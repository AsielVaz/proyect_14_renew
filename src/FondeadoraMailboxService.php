<?php

declare(strict_types=1);

namespace App;

use DateTimeInterface;
use PDO;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

final class FondeadoraMailboxService
{
    private const EXPECTED_SENDER = 'info@negocios.fondeadora.com';
    private const SUBJECT_LOGIN = 'Autentica tu inicio de';
    private const SUBJECT_BENEFICIARY = 'Valida la creación de una nueva cuenta destino en tu cuenta';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $server = 'mail.10h.io',
        private readonly int $port = 993,
        private readonly string $flags = '/imap/ssl',
        private readonly string $folder = 'INBOX',
    ) {
    }

    public function refreshAll(): array
    {
        $companies = $this->configuredCompanies();
        $results = [];
        $totals = [
            'companies' => count($companies),
            'connected' => 0,
            'unread' => 0,
            'inserted' => 0,
            'duplicates' => 0,
            'omitted' => 0,
            'errors' => 0,
        ];

        if (!class_exists(ClientManager::class)) {
            foreach ($companies as $company) {
                $results[(int) $company['id']] = [
                    'status' => 'error',
                    'message' => 'La biblioteca Webklex PHP-IMAP no está instalada.',
                    'unread' => 0,
                    'inserted' => 0,
                    'duplicates' => 0,
                ];
                $totals['errors']++;
            }
            return $this->response($results, $totals, 'No fue posible revisar los buzones porque falta Webklex PHP-IMAP.');
        }

        foreach ($companies as $company) {
            $companyResult = $this->refreshCompany($company);
            $results[(int) $company['id']] = $companyResult;
            if ($companyResult['status'] === 'ok') {
                $totals['connected']++;
            } else {
                $totals['errors']++;
            }
            foreach (['unread', 'inserted', 'duplicates', 'omitted'] as $counter) {
                $totals[$counter] += (int) ($companyResult[$counter] ?? 0);
            }
        }

        $message = $companies === []
            ? 'No hay empresas con correo de contacto y contraseña configurados.'
            : 'Se revisaron todos los buzones configurados.';
        return $this->response($results, $totals, $message);
    }

    public function latestByCompany(?int $companyId = null): array
    {
        $sql =
            "SELECT e.id, e.razon, e.rfc, e.correo_contacto,
                    latest.codigo AS last_code, latest.tipo_correo AS last_type,
                    latest.fecha_correo AS last_date, latest.fecha_ingresa AS stored_at
             FROM empresas e
             LEFT JOIN codigos_fondeadora latest ON latest.id = (
                 SELECT code.id
                 FROM codigos_fondeadora code
                 WHERE LOWER(TRIM(code.correo_recibe)) = LOWER(TRIM(e.correo_contacto))
                 ORDER BY code.fecha_correo DESC, code.id DESC
                 LIMIT 1
             )
             WHERE e.correo_contacto IS NOT NULL
               AND TRIM(e.correo_contacto) <> ''
               AND e.contra_contacto IS NOT NULL
               AND TRIM(e.contra_contacto) <> ''
               AND (:company_id IS NULL OR e.id = :company_filter)
             ORDER BY e.razon ASC, e.id ASC";
        $query = $this->pdo->prepare($sql);
        $query->execute([
            'company_id' => $companyId,
            'company_filter' => $companyId,
        ]);
        return $query->fetchAll();
    }

    public function refreshOne(int $companyId): array
    {
        $company = $this->configuredCompany($companyId);
        if ($company === null) {
            throw new \RuntimeException('La empresa no tiene un buzón de Fondeadora configurado.');
        }
        $result = !class_exists(ClientManager::class)
            ? [
                'status' => 'error',
                'message' => 'La biblioteca Webklex PHP-IMAP no está instalada.',
                'unread' => 0,
                'inserted' => 0,
                'duplicates' => 0,
                'omitted' => 0,
            ]
            : $this->refreshCompany($company);
        $totals = [
            'companies' => 1,
            'connected' => $result['status'] === 'ok' ? 1 : 0,
            'unread' => (int) ($result['unread'] ?? 0),
            'inserted' => (int) ($result['inserted'] ?? 0),
            'duplicates' => (int) ($result['duplicates'] ?? 0),
            'omitted' => (int) ($result['omitted'] ?? 0),
            'errors' => $result['status'] === 'ok' ? 0 : 1,
        ];
        return $this->response(
            [$companyId => $result],
            $totals,
            (string) $result['message'],
            $this->latestByCompany($companyId)
        );
    }

    public function snapshot(): array
    {
        $rows = $this->latestByCompany();
        $totals = [
            'companies' => count($rows),
            'connected' => 0,
            'unread' => 0,
            'inserted' => 0,
            'duplicates' => 0,
            'omitted' => 0,
            'errors' => 0,
        ];
        return $this->response(
            [],
            $totals,
            'Mostrando los últimos códigos guardados. Usa Consultar en la empresa que quieras actualizar.',
            $rows
        );
    }

    public static function classifySubject(string $subject): ?string
    {
        if (stripos($subject, self::SUBJECT_LOGIN) !== false) {
            return 'correo_ingreso';
        }
        if (stripos($subject, self::SUBJECT_BENEFICIARY) !== false) {
            return 'correo_alta';
        }
        return null;
    }

    public static function extractOtpCode(string $html, string $text): ?string
    {
        if ($html !== '' && preg_match('/<h[1-6]\b[^>]*>\s*([0-9]{6})\s*<\/h[1-6]>/is', $html, $match)) {
            return $match[1];
        }
        $plain = $text . "\n" . html_entity_decode(strip_tags(preg_replace(
            '/<(?:br\b[^>]*|\/p\s*|\/div\s*|\/tr\s*|\/li\s*|\/h[1-6]\s*)>/i',
            "\n",
            $html
        ) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/(?:c[oó]digo|code)[^0-9]{0,120}([0-9]{6})/iu', $plain, $match)) {
            return $match[1];
        }
        return preg_match('/(?<![0-9])([0-9]{6})(?![0-9])/', $plain, $match) ? $match[1] : null;
    }

    private function configuredCompanies(): array
    {
        return $this->pdo->query(
            "SELECT id, razon, rfc, LOWER(TRIM(correo_contacto)) AS correo_contacto, contra_contacto
             FROM empresas
             WHERE correo_contacto IS NOT NULL AND TRIM(correo_contacto) <> ''
               AND contra_contacto IS NOT NULL AND TRIM(contra_contacto) <> ''
             ORDER BY razon ASC, id ASC"
        )->fetchAll();
    }

    private function configuredCompany(int $companyId): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT id, razon, rfc, LOWER(TRIM(correo_contacto)) AS correo_contacto, contra_contacto
             FROM empresas
             WHERE id = :id
               AND correo_contacto IS NOT NULL AND TRIM(correo_contacto) <> ''
               AND contra_contacto IS NOT NULL AND TRIM(contra_contacto) <> ''
             LIMIT 1"
        );
        $query->execute(['id' => $companyId]);
        $company = $query->fetch();
        return is_array($company) ? $company : null;
    }

    private function refreshCompany(array $company): array
    {
        $email = (string) $company['correo_contacto'];
        $result = ['status' => 'ok', 'message' => 'Buzón revisado.', 'unread' => 0, 'inserted' => 0, 'duplicates' => 0, 'omitted' => 0];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array_merge($result, ['status' => 'error', 'message' => 'El correo de contacto no tiene un formato válido.']);
        }

        $client = null;
        try {
            $manager = new ClientManager($this->webklexConfig($email, (string) $company['contra_contacto']));
            $client = $manager->account('fondeadora');
            $client->connect();
            $folder = $client->getFolder($this->folder);
            if ($folder === null) {
                throw new \RuntimeException('No se encontró la carpeta IMAP configurada.');
            }
            $messages = $folder->messages()->whereUnseen()->get();
            $result['unread'] = $messages->count();
            foreach ($messages as $message) {
                if (!$message instanceof Message || !$this->processMessage($message, $email, $result)) {
                    $result['omitted']++;
                }
            }
            $result['message'] = $result['inserted'] > 0
                ? $result['inserted'] . ' código(s) nuevo(s) guardado(s).'
                : 'Buzón revisado sin códigos nuevos.';
        } catch (Throwable $exception) {
            $result['status'] = 'error';
            $result['message'] = 'No se pudo revisar el buzón: ' . $this->cleanImapError($exception->getMessage());
        } finally {
            if ($client !== null) {
                try {
                    $client->disconnect();
                } catch (Throwable) {
                    // La lectura ya terminó; un error al cerrar no debe ocultar el resultado.
                }
            }
        }

        return $result;
    }

    private function webklexConfig(string $email, string $password): array
    {
        $flags = strtolower($this->flags);
        $encryption = str_contains($flags, '/ssl')
            ? 'ssl'
            : (str_contains($flags, '/tls') ? 'tls' : false);
        return [
            'default' => 'fondeadora',
            'accounts' => [
                'fondeadora' => [
                    'host' => $this->server,
                    'port' => $this->port,
                    'protocol' => 'imap',
                    'encryption' => $encryption,
                    'validate_cert' => !str_contains($flags, '/novalidate-cert'),
                    'username' => $email,
                    'password' => $password,
                    'authentication' => null,
                    'timeout' => 12,
                ],
            ],
            'options' => [
                'fetch' => \Webklex\PHPIMAP\IMAP::FT_PEEK,
                'sequence' => \Webklex\PHPIMAP\IMAP::ST_UID,
                'fetch_body' => true,
                'fetch_flags' => true,
                'soft_fail' => false,
                'message_key' => 'uid',
                'fetch_order' => 'asc',
            ],
        ];
    }

    private function processMessage(Message $message, string $email, array &$result): bool
    {
        $subject = trim((string) $message->getSubject());
        $type = self::classifySubject($subject);
        if ($type === null || !$this->isExpectedSender($message)) {
            return false;
        }

        $html = $message->getHTMLBody();
        $text = $message->getTextBody();
        $code = self::extractOtpCode($html, $text);
        if ($code === null) {
            return false;
        }
        $date = $this->emailDate($message);
        if ($this->codeExists($code, $date, $email)) {
            $result['duplicates']++;
            $message->setFlag('Seen');
            return true;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO codigos_fondeadora (codigo, fecha_ingresa, fecha_correo, correo_recibe, tipo_correo)
             VALUES (:code, :stored, :received, :email, :type)'
        );
        $insert->execute([
            'code' => $code,
            'stored' => date('Y-m-d H:i:s'),
            'received' => $date,
            'email' => $email,
            'type' => $type,
        ]);
        $result['inserted']++;
        $message->setFlag('Seen');
        return true;
    }

    private function codeExists(string $code, string $date, string $email): bool
    {
        $query = $this->pdo->prepare(
            'SELECT 1 FROM codigos_fondeadora
             WHERE codigo = :code AND fecha_correo = :received AND correo_recibe = :email LIMIT 1'
        );
        $query->execute(['code' => $code, 'received' => $date, 'email' => $email]);
        return $query->fetchColumn() !== false;
    }

    private function isExpectedSender(Message $message): bool
    {
        $from = $message->getFrom();
        if (!is_object($from) || !method_exists($from, 'toArray')) {
            return false;
        }
        foreach ($from->toArray() as $address) {
            $sender = is_object($address) && isset($address->mail)
                ? strtolower(trim((string) $address->mail))
                : strtolower(trim((string) $address));
            if ($sender === self::EXPECTED_SENDER) {
                return true;
            }
        }
        return false;
    }

    private function emailDate(Message $message): string
    {
        $date = $message->getDate();
        $value = is_object($date) && method_exists($date, 'first') ? $date->first() : $date;
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $timestamp = strtotime((string) $value);
        return date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    private function cleanImapError(string $error): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? 'Error de conexión IMAP.';
        return mb_substr(trim(preg_replace('/\s+/', ' ', $clean) ?? $clean), 0, 300);
    }

    private function response(array $results, array $totals, string $message, ?array $rows = null): array
    {
        $rows ??= $this->latestByCompany();
        foreach ($rows as &$row) {
            $status = $results[(int) $row['id']] ?? null;
            $row['refresh_status'] = $status['status'] ?? 'pending';
            $row['refresh_message'] = $status['message'] ?? 'Código guardado. Presiona Consultar para actualizarlo.';
            $row['unread'] = (int) ($status['unread'] ?? 0);
            $row['inserted'] = (int) ($status['inserted'] ?? 0);
            $row['duplicates'] = (int) ($status['duplicates'] ?? 0);
        }
        unset($row);
        return ['message' => $message, 'summary' => $totals, 'rows' => $rows];
    }
}
