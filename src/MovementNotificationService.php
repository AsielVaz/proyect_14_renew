<?php

declare(strict_types=1);

namespace App;

use Closure;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class MovementNotificationService
{
    private const LOCK_NAME = 's14_movement_notification_cron';
    private const MANDRILL_URL = 'https://mandrillapp.com/api/1.0/messages/send.json';

    private readonly Closure $transport;

    public function __construct(private readonly PDO $pdo, ?callable $transport = null)
    {
        $this->transport = $transport !== null
            ? Closure::fromCallable($transport)
            : Closure::fromCallable([$this, 'sendWithMandrill']);
    }

    /**
     * @return array{ok: bool, busy: bool, executed_at: string, clients: int, movements: int, marked: int, errors: int, notifications: list<array<string, mixed>>}
     */
    public function run(DateTimeImmutable $now): array
    {
        $result = [
            'ok' => true,
            'busy' => false,
            'executed_at' => $now->format(DATE_ATOM),
            'clients' => 0,
            'movements' => 0,
            'marked' => 0,
            'errors' => 0,
            'notifications' => [],
        ];
        if (!$this->acquireLock()) {
            $result['busy'] = true;
            return $result;
        }

        try {
            $clientIds = [];
            $this->processGroups($this->pendingImmediateMovements(), 'immediate', $now, $result, $clientIds);
            if (self::modeIsDue('fin_dia', $now, (int) env_value('MOVEMENT_NOTIFICATION_DAILY_HOUR', '18'))) {
                $this->ensureDailyNotificationTable();
                $this->processGroups($this->dailyMovements($now), 'daily_summary', $now, $result, $clientIds);
            }
            $result['clients'] = count($clientIds);
        } finally {
            $this->releaseLock();
        }

        $result['ok'] = $result['errors'] === 0;
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $result
     * @param array<int, true> $clientIds
     */
    private function processGroups(array $rows, string $notificationType, DateTimeImmutable $now, array &$result, array &$clientIds): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['client_id']][] = $row;
        }
        $result['movements'] += count($rows);

        foreach ($groups as $clientId => $movements) {
            $first = $movements[0];
            $movementDetails = $this->movementDetails($movements);
            $balance = null;
            $closingBalance = null;
            $dailyClaimed = false;
            $emailAccepted = false;
            $recipients = self::recipientAddresses([
                $first['email'] ?? null,
                $first['email_2'] ?? null,
                $first['email_3'] ?? null,
            ]);
            $clientIds[$clientId] = true;
            if ($recipients === []) {
                ++$result['errors'];
                $result['notifications'][] = $this->notificationResult(
                    $clientId, $first, $notificationType, 'skipped_no_recipients', [],
                    $movementDetails, null, 0, [], 'La cartera no tiene correos de saldos válidos.'
                );
                error_log('MovementNotification: la cartera ' . $clientId . ' no tiene correos de saldos válidos.');
                continue;
            }

            if ($notificationType === 'daily_summary') {
                $dailyClaimed = $this->claimDailySummary($clientId, $now->format('Y-m-d'));
                if (!$dailyClaimed) continue;
            }

            try {
                $closingBalance = $this->currentBalance($clientId);
                $balance = $notificationType === 'daily_summary'
                    ? $this->previousDayBalance($clientId, $now)
                    : $closingBalance;
                $delivery = ($this->transport)([
                    'subject' => $notificationType === 'daily_summary'
                        ? 'Resumen de movimientos del día · ' . (string) $first['client_name']
                        : 'Nuevos movimientos · ' . (string) $first['client_name'],
                    'html' => $this->html($first, $movements, $balance, $now, $notificationType, $closingBalance),
                    'text' => $this->text($first, $movements, $balance, $now, $notificationType, $closingBalance),
                    'recipients' => $recipients,
                ]);
                if (!is_array($delivery) || !($delivery['accepted'] ?? false)) {
                    throw new RuntimeException((string) ($delivery['message'] ?? 'El proveedor no aceptó el correo.'));
                }
                $emailAccepted = true;
                $providerIds = array_values(array_map('strval',
                    is_array($delivery['provider_ids'] ?? null) ? $delivery['provider_ids'] : []));
                if ($notificationType === 'daily_summary') {
                    $this->finishDailySummary($clientId, $now->format('Y-m-d'), count($movements), $balance, $recipients, $providerIds);
                }
                $marked = $this->markNotified(array_map(
                    static fn (array $movement): int => (int) $movement['id'],
                    $movements
                ));
                $result['marked'] += $marked;
                $result['notifications'][] = $this->notificationResult(
                    $clientId, $first, $notificationType, 'sent', $recipients,
                    $movementDetails, $balance, $marked, $providerIds,
                    (string) ($delivery['message'] ?? 'Correo aceptado.'), $closingBalance
                );
            } catch (Throwable $exception) {
                if ($dailyClaimed && !$emailAccepted) {
                    $this->releaseDailySummary($clientId, $now->format('Y-m-d'));
                }
                ++$result['errors'];
                $result['notifications'][] = $this->notificationResult(
                    $clientId, $first, $notificationType, 'error', $recipients,
                    $movementDetails, $balance, 0, [], $exception->getMessage(), $closingBalance
                );
                error_log('MovementNotification client ' . $clientId . ' [' . $notificationType . ']: ' . $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function notificationResult(
        int $clientId,
        array $client,
        string $notificationType,
        string $status,
        array $recipients,
        array $movements,
        ?float $balance,
        int $marked,
        array $providerIds,
        string $message,
        ?float $closingBalance = null,
    ): array {
        return [
            'client_id' => $clientId,
            'client_name' => (string) $client['client_name'],
            'mode' => (string) $client['notification_mode'],
            'notification_type' => $notificationType,
            'status' => $status,
            'recipients' => $recipients,
            'movements' => $movements,
            'balance' => $balance,
            'closing_balance' => $closingBalance,
            'marked' => $marked,
            'provider_ids' => $providerIds,
            'message' => $message,
        ];
    }

    private function currentBalance(int $clientId): float
    {
        $query = $this->pdo->prepare(
            'SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = :client'
        );
        $query->execute(['client' => (string) $clientId]);
        return round((float) $query->fetchColumn(), 2);
    }

    private function previousDayBalance(int $clientId, DateTimeImmutable $now): float
    {
        $query = $this->pdo->prepare(
            'SELECT COALESCE(SUM(saldo_acreditable), 0)
               FROM pagos
              WHERE nombre = :client AND fecha_asignacion < :summary_date'
        );
        $query->execute([
            'client' => (string) $clientId,
            'summary_date' => $now->format('Y-m-d'),
        ]);
        return round((float) $query->fetchColumn(), 2);
    }

    /**
     * @param list<array<string, mixed>> $movements
     * @return list<array{id: int, type: string, amount: float, date: string}>
     */
    private function movementDetails(array $movements): array
    {
        return array_map(function (array $movement): array {
            $date = trim((string) ($movement['fecha_banco'] ?? ''))
                ?: trim((string) ($movement['fecha_asignacion'] ?? ''))
                ?: trim((string) ($movement['fecha_operacion'] ?? ''));
            return [
                'id' => (int) $movement['id'],
                'type' => trim((string) ($movement['tipo_movimiento'] ?? 'Movimiento')) ?: 'Movimiento',
                'amount' => $this->displayAmount($movement),
                'date' => $date,
            ];
        }, $movements);
    }

    public static function modeIsDue(string $mode, DateTimeImmutable $now, int $dailyHour = 18): bool
    {
        return $mode === 'movimiento'
            || ($mode === 'fin_dia' && (int) $now->format('G') >= max(0, min(23, $dailyHour)));
    }

    /** @param array<int, mixed> $addresses
     *  @return list<string>
     */
    public static function recipientAddresses(array $addresses): array
    {
        $valid = [];
        foreach ($addresses as $address) {
            $address = mb_strtolower(trim((string) $address));
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $valid[$address] = $address;
            }
        }
        return array_values($valid);
    }

    /** @return array<string, mixed> */
    public function sendExample(int $paymentId, string $recipient, DateTimeImmutable $now): array
    {
        $recipients = self::recipientAddresses([$recipient]);
        if ($paymentId <= 0 || $recipients === []) {
            throw new RuntimeException('El movimiento o destinatario de prueba no es válido.');
        }
        $query = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.monto, p.saldo_acreditable, p.tipo_movimiento,
                   p.fecha_banco, p.fecha_asignacion, p.fecha_operacion,
                   p.detalle, p.descripcion, p.descripcion_detallada,
                   p.empresa, p.banco, p.nombre_corto,
                   c.id AS client_id, c.nombre AS client_name, c.info_3 AS notification_mode
              FROM pagos p
              INNER JOIN clientes c ON p.nombre = CAST(c.id AS CHAR)
             WHERE p.id = :id
             LIMIT 1
            SQL);
        $query->execute(['id' => $paymentId]);
        $movement = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($movement)) {
            throw new RuntimeException('No se encontró el movimiento solicitado para la prueba.');
        }
        $balance = $this->currentBalance((int) $movement['client_id']);
        $delivery = ($this->transport)([
            'subject' => '[PRUEBA] Notificación de movimiento · ' . (string) $movement['client_name'],
            'html' => $this->html($movement, [$movement], $balance, $now, 'immediate'),
            'text' => $this->text($movement, [$movement], $balance, $now, 'immediate'),
            'recipients' => $recipients,
        ]);
        if (!is_array($delivery)) {
            throw new RuntimeException('El proveedor devolvió una respuesta no válida.');
        }
        return $delivery;
    }

    /** @return list<array<string, mixed>> */
    private function pendingImmediateMovements(): array
    {
        $query = $this->pdo->query(<<<'SQL'
            SELECT p.id, p.monto, p.saldo_acreditable, p.tipo_movimiento,
                   p.fecha_banco, p.fecha_asignacion, p.fecha_operacion,
                   p.detalle, p.descripcion, p.descripcion_detallada,
                   p.empresa, p.banco, p.nombre_corto,
                   c.id AS client_id, c.nombre AS client_name, c.info_3 AS notification_mode,
                   c.correo AS email, c.correo_2_s AS email_2, c.correo_3_s AS email_3
              FROM clientes c
              STRAIGHT_JOIN pagos p ON p.nombre = CAST(c.id AS CHAR) AND p.notificado = 'no'
             WHERE COALESCE(c.activo, 1) = 1
               AND LOWER(TRIM(COALESCE(c.info_3, ''))) = 'movimiento'
             ORDER BY c.id, p.id
            SQL);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function dailyMovements(DateTimeImmutable $now): array
    {
        $query = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.monto, p.saldo_acreditable, p.tipo_movimiento,
                   p.fecha_banco, p.fecha_asignacion, p.fecha_operacion,
                   p.detalle, p.descripcion, p.descripcion_detallada,
                   p.empresa, p.banco, p.nombre_corto,
                   c.id AS client_id, c.nombre AS client_name, c.info_3 AS notification_mode,
                   c.correo AS email, c.correo_2_s AS email_2, c.correo_3_s AS email_3
              FROM clientes c
              STRAIGHT_JOIN pagos p ON p.nombre = CAST(c.id AS CHAR) AND p.fecha_asignacion = :summary_date
             WHERE COALESCE(c.activo, 1) = 1
               AND LOWER(TRIM(COALESCE(c.info_3, ''))) IN ('movimiento', 'fin_dia')
               AND NOT EXISTS (
                    SELECT 1 FROM s14_cartera_notificacion_diaria d
                     WHERE d.cliente_id = c.id AND d.fecha_resumen = :existing_date
               )
             ORDER BY c.id, p.id
            SQL);
        $date = $now->format('Y-m-d');
        $query->execute(['summary_date' => $date, 'existing_date' => $date]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureDailyNotificationTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s14_cartera_notificacion_diaria (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT NOT NULL,
                fecha_resumen DATE NOT NULL,
                estado VARCHAR(20) NOT NULL DEFAULT 'procesando',
                movimientos INT NOT NULL DEFAULT 0,
                saldo DECIMAL(18,2) NULL,
                destinatarios LONGTEXT NULL,
                provider_ids LONGTEXT NULL,
                creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                enviado_at DATETIME NULL,
                UNIQUE KEY uq_cliente_fecha (cliente_id, fecha_resumen),
                KEY idx_fecha_estado (fecha_resumen, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    private function claimDailySummary(int $clientId, string $date): bool
    {
        $query = $this->pdo->prepare(
            "INSERT IGNORE INTO s14_cartera_notificacion_diaria
                (cliente_id, fecha_resumen, estado) VALUES (:client, :summary_date, 'procesando')"
        );
        $query->execute(['client' => $clientId, 'summary_date' => $date]);
        return $query->rowCount() === 1;
    }

    private function finishDailySummary(
        int $clientId,
        string $date,
        int $movementCount,
        float $balance,
        array $recipients,
        array $providerIds,
    ): void {
        $query = $this->pdo->prepare(<<<'SQL'
            UPDATE s14_cartera_notificacion_diaria
               SET estado = 'enviado', movimientos = :movements, saldo = :balance,
                   destinatarios = :recipients, provider_ids = :provider_ids, enviado_at = NOW()
             WHERE cliente_id = :client AND fecha_resumen = :summary_date AND estado = 'procesando'
            SQL);
        $query->execute([
            'movements' => $movementCount,
            'balance' => $balance,
            'recipients' => json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'provider_ids' => json_encode($providerIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'client' => $clientId,
            'summary_date' => $date,
        ]);
        if ($query->rowCount() !== 1) {
            throw new RuntimeException('No fue posible confirmar el registro del resumen diario.');
        }
    }

    private function releaseDailySummary(int $clientId, string $date): void
    {
        $query = $this->pdo->prepare(
            "DELETE FROM s14_cartera_notificacion_diaria
              WHERE cliente_id = :client AND fecha_resumen = :summary_date AND estado = 'procesando'"
        );
        $query->execute(['client' => $clientId, 'summary_date' => $date]);
    }

    /** @param list<int> $ids */
    private function markNotified(array $ids): int
    {
        $marked = 0;
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $query = $this->pdo->prepare(
                "UPDATE pagos SET notificado = 'Si' WHERE id IN ({$placeholders}) AND notificado = 'no'"
            );
            $query->execute($chunk);
            $marked += $query->rowCount();
        }
        return $marked;
    }

    private function acquireLock(): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $query->execute(['name' => self::LOCK_NAME]);
        return (int) $query->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $query = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $query->execute(['name' => self::LOCK_NAME]);
        } catch (Throwable $exception) {
            error_log('MovementNotification lock release: ' . $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $message
     *  @return array{accepted: bool, message: string, provider_ids: list<string>}
     */
    private function sendWithMandrill(array $message): array
    {
        $key = trim(env_value('MANDRILL_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('Falta configurar MANDRILL_API_KEY.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('El envío requiere la extensión PHP cURL.');
        }

        $to = array_map(static fn (string $email): array => ['email' => $email, 'type' => 'to'], $message['recipients']);
        $payload = json_encode([
            'key' => $key,
            'message' => [
                'from_email' => env_value('MOVEMENT_NOTIFICATION_FROM_EMAIL', 'no-reply@sistema14.org'),
                'from_name' => env_value('MOVEMENT_NOTIFICATION_FROM_NAME', 'Sistema 14'),
                'subject' => $message['subject'],
                'html' => $message['html'],
                'text' => $message['text'],
                'to' => $to,
                'preserve_recipients' => false,
                'important' => false,
                'track_opens' => true,
            ],
            'async' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $handle = curl_init(self::MANDRILL_URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Sistema14-MovementNotifier/1.0',
        ]);
        $raw = curl_exec($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($handle);
        if ($raw === false) {
            throw new RuntimeException('No fue posible contactar al proveedor de correo: ' . $networkError);
        }
        $decoded = json_decode((string) $raw, true);
        if ($http < 200 || $http >= 300 || !is_array($decoded) || ($decoded['status'] ?? '') === 'error') {
            throw new RuntimeException(trim((string) ($decoded['message'] ?? '')) ?: 'El proveedor devolvió una respuesta no válida.');
        }

        $accepted = 0;
        $providerIds = [];
        foreach ($decoded as $recipient) {
            if (is_array($recipient) && in_array(strtolower((string) ($recipient['status'] ?? '')), ['sent', 'queued', 'scheduled'], true)) {
                ++$accepted;
                if (trim((string) ($recipient['_id'] ?? '')) !== '') {
                    $providerIds[] = (string) $recipient['_id'];
                }
            }
        }
        return [
            'accepted' => $accepted > 0,
            'message' => $accepted > 0 ? 'Correo aceptado.' : 'Ningún destinatario fue aceptado por el proveedor.',
            'provider_ids' => $providerIds,
        ];
    }

    /** @param array<string, mixed> $client
     *  @param list<array<string, mixed>> $movements
     */
    private function html(
        array $client,
        array $movements,
        float $balance,
        DateTimeImmutable $now,
        string $notificationType,
        ?float $closingBalance = null,
    ): string
    {
        $rows = '';
        foreach ($movements as $movement) {
            $type = trim((string) ($movement['tipo_movimiento'] ?? 'Movimiento')) ?: 'Movimiento';
            $color = strcasecmp($type, 'Retiro') === 0 ? '#c62828'
                : (strcasecmp($type, 'Deposito') === 0 ? '#168247' : '#b65308');
            $detail = trim((string) ($movement['detalle'] ?? ''));
            $description = trim((string) ($movement['descripcion'] ?? ''));
            if ($description !== '' && strcasecmp($description, $detail) !== 0) {
                $detail .= ($detail !== '' ? ' · ' : '') . $description;
            }
            $date = trim((string) ($movement['fecha_banco'] ?? ''))
                ?: trim((string) ($movement['fecha_asignacion'] ?? ''))
                ?: trim((string) ($movement['fecha_operacion'] ?? ''));
            $rows .= '<tr class="movement-row">'
                . '<td class="movement-cell" style="padding:11px 8px;border-bottom:1px solid #e6e8ec"><span class="mobile-label">ID</span>' . (int) $movement['id'] . '</td>'
                . '<td class="movement-cell" style="padding:11px 8px;border-bottom:1px solid #e6e8ec"><span class="mobile-label">Fecha</span>' . $this->escape($date) . '</td>'
                . '<td class="movement-cell" style="padding:11px 8px;border-bottom:1px solid #e6e8ec;color:' . $color . ';font-weight:700"><span class="mobile-label">Tipo</span>' . $this->escape($type) . '</td>'
                . '<td class="movement-cell movement-description" style="padding:11px 8px;border-bottom:1px solid #e6e8ec;overflow-wrap:anywhere"><span class="mobile-label">Descripción</span>' . $this->escape($detail ?: 'Sin descripción') . '</td>'
                . '<td class="movement-cell movement-amount" align="right" style="padding:11px 8px;border-bottom:1px solid #e6e8ec;font-weight:700;white-space:nowrap"><span class="mobile-label">Monto</span>$' . number_format($this->displayAmount($movement), 2) . '</td>'
                . '</tr>';
        }

        $daily = $notificationType === 'daily_summary';
        $heading = $daily ? 'Resumen diario de ' : 'Movimientos de ';
        $balanceLabel = $daily ? 'Saldo del día anterior' : 'Saldo actual de la cartera';
        $balanceCaption = $daily
            ? 'Movimientos asignados antes del ' . $now->format('d/m/Y')
            : 'Al momento de enviar este correo';
        $closingBalance ??= $balance;
        $balanceRows = '<tr>'
            . '<td class="balance-label" style="padding:16px 18px;color:#c8d0dd;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px">' . $balanceLabel . '<br><span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:#929dad">' . $balanceCaption . '</span></td>'
            . '<td class="balance-value" align="right" style="padding:16px 18px;color:' . ($balance < 0 ? '#ff8a80' : '#ffffff') . ';font-size:24px;font-weight:700;white-space:nowrap">$' . number_format($balance, 2) . '</td>'
            . '</tr>';
        if ($daily) {
            $balanceRows .= '<tr style="border-top:1px solid #2d3749">'
                . '<td class="balance-label" style="padding:16px 18px;color:#c8d0dd;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;border-top:1px solid #2d3749">Saldo de cierre del día<br><span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:#929dad">Saldo actual de la cartera al enviar este correo</span></td>'
                . '<td class="balance-value" align="right" style="padding:16px 18px;color:' . ($closingBalance < 0 ? '#ff8a80' : '#ffffff') . ';font-size:24px;font-weight:700;white-space:nowrap;border-top:1px solid #2d3749">$' . number_format($closingBalance, 2) . '</td>'
                . '</tr>';
        }
        $intro = $daily
            ? 'Este resumen incluye <strong>' . count($movements) . '</strong> movimiento(s) asignado(s) durante el día, aunque ya hayan sido notificados individualmente.'
            : 'Se registraron <strong>' . count($movements) . '</strong> movimiento(s) pendiente(s) de notificar.';

        return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<style>'
            . '.mobile-label{display:none;font-size:11px;line-height:1.2;color:#727987;font-weight:700;text-transform:uppercase;letter-spacing:.45px}'
            . '@media only screen and (max-width:620px){'
            . 'body{margin:0!important}.email-outer{padding:0!important}.email-shell{width:100%!important;max-width:100%!important;border-radius:0!important}'
            . '.email-header{padding:22px 18px!important}.email-title{font-size:22px!important;line-height:1.22!important;overflow-wrap:anywhere}'
            . '.email-content{padding:20px 14px!important}.email-intro{font-size:16px!important;line-height:1.45!important}'
            . '.balance-label,.balance-value{display:block!important;width:100%!important;box-sizing:border-box!important;text-align:left!important}'
            . '.balance-label{padding:16px 18px 4px!important}.balance-value{padding:4px 18px 16px!important;font-size:26px!important;line-height:1.15!important;white-space:normal!important;overflow-wrap:anywhere}'
            . '.movement-table,.movement-table tbody,.movement-row,.movement-cell{display:block!important;width:100%!important;box-sizing:border-box!important}'
            . '.movement-head{display:none!important}.movement-row{margin:0 0 12px!important;border:1px solid #e3e6eb!important;border-radius:10px!important;overflow:hidden!important}'
            . '.movement-cell{padding:10px 12px!important;border-bottom:1px solid #eceef2!important;text-align:left!important;white-space:normal!important;overflow-wrap:anywhere!important}'
            . '.movement-cell:last-child{border-bottom:0!important}.mobile-label{display:block!important;margin:0 0 4px!important}'
            . '.movement-amount{font-size:17px!important;white-space:nowrap!important}.email-footer{line-height:1.45!important}'
            . '}'
            . '</style></head><body style="margin:0;background:#f3f5f8;font-family:Arial,sans-serif;color:#20242c;-webkit-text-size-adjust:100%;text-size-adjust:100%">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td class="email-outer" align="center" style="padding:28px 12px">'
            . '<table class="email-shell" role="presentation" width="760" cellpadding="0" cellspacing="0" style="max-width:760px;width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td class="email-header" style="background:#171717;color:#fff;padding:24px 28px;border-left:6px solid #f36b0a">'
            . '<div style="color:#ff9a45;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">Sistema 14</div>'
            . '<div class="email-title" style="font-size:24px;line-height:1.25;font-weight:700;margin-top:5px">' . $heading . $this->escape((string) $client['client_name']) . '</div>'
            . '<div style="color:#c9c9c9;font-size:13px;margin-top:7px">Generado el ' . $this->escape($now->format('d/m/Y H:i')) . '</div></td></tr>'
            . '<tr><td class="email-content" style="padding:24px 28px"><p class="email-intro" style="margin:0 0 18px">' . $intro . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#151d2d;border-radius:12px">'
            . $balanceRows
            . '</table>'
            . '<table class="movement-table" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;table-layout:fixed">'
            . '<thead class="movement-head"><tr style="background:#f7f8fa;color:#636b78"><th width="10%" align="left" style="padding:10px 8px">ID</th><th width="18%" align="left" style="padding:10px 8px">Fecha</th><th width="15%" align="left" style="padding:10px 8px">Tipo</th><th width="37%" align="left" style="padding:10px 8px">Descripción</th><th width="20%" align="right" style="padding:10px 8px">Monto</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<p class="email-footer" style="margin:22px 0 0;color:#727987;font-size:12px">Este correo fue generado automáticamente. No respondas a este mensaje.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $client
     *  @param list<array<string, mixed>> $movements
     */
    private function text(
        array $client,
        array $movements,
        float $balance,
        DateTimeImmutable $now,
        string $notificationType,
        ?float $closingBalance = null,
    ): string
    {
        $daily = $notificationType === 'daily_summary';
        $closingBalance ??= $balance;
        $lines = [
            ($daily ? 'Resumen diario de ' : 'Movimientos de ')
                . (string) $client['client_name'],
            'Generado: ' . $now->format('d/m/Y H:i'),
            ($daily ? 'Saldo del día anterior: $' : 'Saldo actual de la cartera: $')
                . number_format($balance, 2),
            ...($daily ? ['Saldo de cierre del día: $' . number_format($closingBalance, 2)] : []),
            ($daily
                ? 'Movimientos asignados durante el día: ' . count($movements)
                : 'Movimientos pendientes notificados: ' . count($movements)),
            '',
        ];
        foreach ($movements as $movement) {
            $detail = trim((string) ($movement['detalle'] ?? $movement['descripcion'] ?? ''));
            $lines[] = sprintf('#%d | %s | %s | $%s | %s', (int) $movement['id'],
                (string) ($movement['fecha_banco'] ?: $movement['fecha_asignacion'] ?: $movement['fecha_operacion']),
                (string) ($movement['tipo_movimiento'] ?? 'Movimiento'),
                number_format($this->displayAmount($movement), 2), $detail);
        }
        return implode("\n", $lines);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $movement */
    private function displayAmount(array $movement): float
    {
        $type = mb_strtolower(trim((string) ($movement['tipo_movimiento'] ?? '')));
        if ($type === 'abono por comisión' || $type === 'abono por comision') {
            return (float) ($movement['saldo_acreditable'] ?? $movement['monto'] ?? 0);
        }
        return (float) ($movement['monto'] ?? 0);
    }
}
