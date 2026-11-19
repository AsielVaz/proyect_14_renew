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
            $rows = $this->pendingMovements($now);
            $groups = [];
            foreach ($rows as $row) {
                $groups[(int) $row['client_id']][] = $row;
            }

            $result['clients'] = count($groups);
            $result['movements'] = count($rows);

            foreach ($groups as $clientId => $movements) {
                $first = $movements[0];
                $movementDetails = $this->movementDetails($movements);
                $balance = null;
                $recipients = self::recipientAddresses([
                    $first['email'] ?? null,
                    $first['email_2'] ?? null,
                    $first['email_3'] ?? null,
                ]);
                if ($recipients === []) {
                    ++$result['errors'];
                    $result['notifications'][] = [
                        'client_id' => $clientId,
                        'client_name' => (string) $first['client_name'],
                        'mode' => (string) $first['notification_mode'],
                        'status' => 'skipped_no_recipients',
                        'recipients' => [],
                        'movements' => $movementDetails,
                        'balance' => null,
                        'marked' => 0,
                        'provider_ids' => [],
                        'message' => 'La cartera no tiene correos de saldos válidos.',
                    ];
                    error_log('MovementNotification: la cartera ' . $clientId . ' no tiene correos de saldos válidos.');
                    continue;
                }

                try {
                    $mode = (string) $first['notification_mode'];
                    $balance = $this->currentBalance($clientId);
                    $delivery = ($this->transport)([
                        'subject' => $mode === 'fin_dia'
                            ? 'Resumen de movimientos del día · ' . (string) $first['client_name']
                            : 'Nuevos movimientos · ' . (string) $first['client_name'],
                        'html' => $this->html($first, $movements, $balance, $now),
                        'text' => $this->text($first, $movements, $balance, $now),
                        'recipients' => $recipients,
                    ]);
                    if (!is_array($delivery) || !($delivery['accepted'] ?? false)) {
                        throw new RuntimeException((string) ($delivery['message'] ?? 'El proveedor no aceptó el correo.'));
                    }
                    $marked = $this->markNotified(array_map(
                        static fn (array $movement): int => (int) $movement['id'],
                        $movements
                    ));
                    $result['marked'] += $marked;
                    $result['notifications'][] = [
                        'client_id' => $clientId,
                        'client_name' => (string) $first['client_name'],
                        'mode' => $mode,
                        'status' => 'sent',
                        'recipients' => $recipients,
                        'movements' => $movementDetails,
                        'balance' => $balance,
                        'marked' => $marked,
                        'provider_ids' => array_values(array_map('strval', is_array($delivery['provider_ids'] ?? null) ? $delivery['provider_ids'] : [])),
                        'message' => (string) ($delivery['message'] ?? 'Correo aceptado.'),
                    ];
                } catch (Throwable $exception) {
                    ++$result['errors'];
                    $result['notifications'][] = [
                        'client_id' => $clientId,
                        'client_name' => (string) $first['client_name'],
                        'mode' => (string) $first['notification_mode'],
                        'status' => 'error',
                        'recipients' => $recipients,
                        'movements' => $movementDetails,
                        'balance' => $balance,
                        'marked' => 0,
                        'provider_ids' => [],
                        'message' => $exception->getMessage(),
                    ];
                    error_log('MovementNotification client ' . $clientId . ': ' . $exception->getMessage());
                }
            }
        } finally {
            $this->releaseLock();
        }

        $result['ok'] = $result['errors'] === 0;
        return $result;
    }

    private function currentBalance(int $clientId): float
    {
        $query = $this->pdo->prepare(
            'SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = :client'
        );
        $query->execute(['client' => (string) $clientId]);
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
            'html' => $this->html($movement, [$movement], $balance, $now),
            'text' => $this->text($movement, [$movement], $balance, $now),
            'recipients' => $recipients,
        ]);
        if (!is_array($delivery)) {
            throw new RuntimeException('El proveedor devolvió una respuesta no válida.');
        }
        return $delivery;
    }

    /** @return list<array<string, mixed>> */
    private function pendingMovements(DateTimeImmutable $now): array
    {
        $dailyHour = max(0, min(23, (int) env_value('MOVEMENT_NOTIFICATION_DAILY_HOUR', '18')));
        $dailyDue = (int) $now->format('G') >= $dailyHour ? 1 : 0;
        $query = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.monto, p.saldo_acreditable, p.tipo_movimiento,
                   p.fecha_banco, p.fecha_asignacion, p.fecha_operacion,
                   p.detalle, p.descripcion, p.descripcion_detallada,
                   p.empresa, p.banco, p.nombre_corto,
                   c.id AS client_id, c.nombre AS client_name, c.info_3 AS notification_mode,
                   c.correo AS email, c.correo_2_s AS email_2, c.correo_3_s AS email_3
              FROM clientes c
              STRAIGHT_JOIN pagos p ON p.nombre = CAST(c.id AS CHAR) AND p.notificado = 'no'
             WHERE COALESCE(c.activo, 1) = 1
               AND (
                    LOWER(TRIM(COALESCE(c.info_3, ''))) = 'movimiento'
                    OR (LOWER(TRIM(COALESCE(c.info_3, ''))) = 'fin_dia' AND :daily_due = 1)
               )
             ORDER BY c.id, p.id
            SQL);
        $query->execute(['daily_due' => $dailyDue]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
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
    private function html(array $client, array $movements, float $balance, DateTimeImmutable $now): string
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
            $rows .= '<tr>'
                . '<td style="padding:11px 8px;border-bottom:1px solid #e6e8ec">' . (int) $movement['id'] . '</td>'
                . '<td style="padding:11px 8px;border-bottom:1px solid #e6e8ec">' . $this->escape($date) . '</td>'
                . '<td style="padding:11px 8px;border-bottom:1px solid #e6e8ec;color:' . $color . ';font-weight:700">' . $this->escape($type) . '</td>'
                . '<td style="padding:11px 8px;border-bottom:1px solid #e6e8ec">' . $this->escape($detail ?: 'Sin descripción') . '</td>'
                . '<td align="right" style="padding:11px 8px;border-bottom:1px solid #e6e8ec;font-weight:700;white-space:nowrap">$' . number_format($this->displayAmount($movement), 2) . '</td>'
                . '</tr>';
        }

        return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:Arial,sans-serif;color:#20242c">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="760" cellpadding="0" cellspacing="0" style="max-width:760px;width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="background:#171717;color:#fff;padding:24px 28px;border-left:6px solid #f36b0a">'
            . '<div style="color:#ff9a45;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">Sistema 14</div>'
            . '<div style="font-size:24px;font-weight:700;margin-top:5px">Movimientos de ' . $this->escape((string) $client['client_name']) . '</div>'
            . '<div style="color:#c9c9c9;font-size:13px;margin-top:7px">Generado el ' . $this->escape($now->format('d/m/Y H:i')) . '</div></td></tr>'
            . '<tr><td style="padding:24px 28px"><p style="margin:0 0 18px">Se registraron <strong>' . count($movements) . '</strong> movimiento(s) pendiente(s) de notificar.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#151d2d;border-radius:12px"><tr>'
            . '<td style="padding:16px 18px;color:#c8d0dd;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px">Saldo actual de la cartera<br><span style="font-size:10px;font-weight:400;text-transform:none;letter-spacing:0;color:#929dad">Al momento de enviar este correo</span></td>'
            . '<td align="right" style="padding:16px 18px;color:' . ($balance < 0 ? '#ff8a80' : '#ffffff') . ';font-size:24px;font-weight:700;white-space:nowrap">$' . number_format($balance, 2) . '</td>'
            . '</tr></table>'
            . '<div style="overflow-x:auto"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px">'
            . '<thead><tr style="background:#f7f8fa;color:#636b78"><th align="left" style="padding:10px 8px">ID</th><th align="left" style="padding:10px 8px">Fecha</th><th align="left" style="padding:10px 8px">Tipo</th><th align="left" style="padding:10px 8px">Descripción</th><th align="right" style="padding:10px 8px">Monto</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . '<p style="margin:22px 0 0;color:#727987;font-size:12px">Este correo fue generado automáticamente. No respondas a este mensaje.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $client
     *  @param list<array<string, mixed>> $movements
     */
    private function text(array $client, array $movements, float $balance, DateTimeImmutable $now): string
    {
        $lines = [
            'Movimientos de ' . (string) $client['client_name'],
            'Generado: ' . $now->format('d/m/Y H:i'),
            'Saldo actual de la cartera: $' . number_format($balance, 2),
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
