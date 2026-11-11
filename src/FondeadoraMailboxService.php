<?php

declare(strict_types=1);

namespace App;

use PDO;

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

        if (!function_exists('imap_open')) {
            foreach ($companies as $company) {
                $results[(int) $company['id']] = [
                    'status' => 'error',
                    'message' => 'La extensión IMAP de PHP no está instalada o habilitada.',
                    'unread' => 0,
                    'inserted' => 0,
                    'duplicates' => 0,
                ];
                $totals['errors']++;
            }
            return $this->response($results, $totals, 'No fue posible revisar los buzones porque PHP no tiene habilitada la extensión IMAP.');
        }

        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 8);
            @imap_timeout(IMAP_READTIMEOUT, 12);
            @imap_timeout(IMAP_WRITETIMEOUT, 12);
            @imap_timeout(IMAP_CLOSETIMEOUT, 5);
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

    public function latestByCompany(): array
    {
        $query = $this->pdo->query(
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
             ORDER BY e.razon ASC, e.id ASC"
        );
        return $query->fetchAll();
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

    private function refreshCompany(array $company): array
    {
        $email = (string) $company['correo_contacto'];
        $result = ['status' => 'ok', 'message' => 'Buzón revisado.', 'unread' => 0, 'inserted' => 0, 'duplicates' => 0, 'omitted' => 0];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array_merge($result, ['status' => 'error', 'message' => 'El correo de contacto no tiene un formato válido.']);
        }

        $mailbox = sprintf('{%s:%d%s}%s', $this->server, $this->port, $this->flags, $this->folder);
        imap_errors();
        $imap = @imap_open($mailbox, $email, (string) $company['contra_contacto'], 0, 1);
        if ($imap === false) {
            $error = $this->cleanImapError((string) (imap_last_error() ?: 'La cuenta rechazó la conexión.'));
            imap_errors();
            return array_merge($result, ['status' => 'error', 'message' => 'No se pudo abrir el buzón: ' . $error]);
        }

        try {
            $uids = @imap_search($imap, 'UNSEEN', SE_UID);
            $uids = is_array($uids) ? $uids : [];
            $result['unread'] = count($uids);
            foreach ($uids as $uid) {
                if (!$this->processMessage($imap, (int) $uid, $email, $result)) {
                    $result['omitted']++;
                }
            }
            $result['message'] = $result['inserted'] > 0
                ? $result['inserted'] . ' código(s) nuevo(s) guardado(s).'
                : 'Buzón revisado sin códigos nuevos.';
        } finally {
            @imap_close($imap);
            imap_errors();
        }

        return $result;
    }

    private function processMessage(mixed $imap, int $uid, string $email, array &$result): bool
    {
        $overviews = @imap_fetch_overview($imap, (string) $uid, FT_UID);
        if (!is_array($overviews) || !isset($overviews[0])) {
            return false;
        }
        $overview = $overviews[0];
        $subject = $this->decodeHeader((string) ($overview->subject ?? ''));
        $type = self::classifySubject($subject);
        if ($type === null || !$this->isExpectedSender($imap, $uid, $overview)) {
            return false;
        }

        [$html, $text] = $this->messageBodies($imap, $uid);
        $code = self::extractOtpCode($html, $text);
        if ($code === null) {
            return false;
        }
        $date = $this->emailDate((string) ($overview->date ?? ''));
        if ($this->codeExists($code, $date, $email)) {
            $result['duplicates']++;
            @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
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
        @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
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

    private function isExpectedSender(mixed $imap, int $uid, object $overview): bool
    {
        $from = strtolower($this->decodeHeader((string) ($overview->from ?? '')));
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $matches)
            && in_array(self::EXPECTED_SENDER, array_map('strtolower', $matches[0]), true)) {
            return true;
        }
        $messageNumber = @imap_msgno($imap, $uid);
        $header = $messageNumber ? @imap_headerinfo($imap, $messageNumber) : false;
        foreach ((array) ($header->from ?? []) as $address) {
            $sender = strtolower((string) ($address->mailbox ?? '') . '@' . (string) ($address->host ?? ''));
            if ($sender === self::EXPECTED_SENDER) {
                return true;
            }
        }
        return false;
    }

    private function messageBodies(mixed $imap, int $uid): array
    {
        $html = [];
        $text = [];
        $structure = @imap_fetchstructure($imap, $uid, FT_UID);
        if ($structure !== false) {
            $this->collectBodies($imap, $uid, $structure, '', $html, $text);
        }
        if ($html === [] && $text === []) {
            $body = @imap_body($imap, $uid, FT_UID | FT_PEEK);
            if (is_string($body)) {
                $text[] = $body;
            }
        }
        return [implode("\n", $html), implode("\n", $text)];
    }

    private function collectBodies(mixed $imap, int $uid, object $part, string $number, array &$html, array &$text): void
    {
        if (strcasecmp((string) ($part->disposition ?? ''), 'ATTACHMENT') === 0) {
            return;
        }
        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $child) {
                $childNumber = $number === '' ? (string) ($index + 1) : $number . '.' . ($index + 1);
                $this->collectBodies($imap, $uid, $child, $childNumber, $html, $text);
            }
            return;
        }
        $body = @imap_fetchbody($imap, $uid, $number === '' ? '1' : $number, FT_UID | FT_PEEK);
        if (!is_string($body)) {
            return;
        }
        $body = match ((int) ($part->encoding ?? 0)) {
            3 => base64_decode($body, true) ?: $body,
            4 => quoted_printable_decode($body),
            default => $body,
        };
        $charset = '';
        foreach (array_merge((array) ($part->parameters ?? []), (array) ($part->dparameters ?? [])) as $parameter) {
            if (strcasecmp((string) ($parameter->attribute ?? ''), 'CHARSET') === 0) {
                $charset = (string) ($parameter->value ?? '');
                break;
            }
        }
        $body = $this->toUtf8($body, $charset);
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ((int) ($part->type ?? -1) === 0 && $subtype === 'HTML') {
            $html[] = $body;
        } elseif ((int) ($part->type ?? -1) === 0 && $subtype === 'PLAIN') {
            $text[] = $body;
        }
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '' || !function_exists('imap_mime_header_decode')) {
            return $value;
        }
        $decoded = @imap_mime_header_decode($value);
        if (!is_array($decoded)) {
            return $value;
        }
        $result = '';
        foreach ($decoded as $part) {
            $result .= $this->toUtf8((string) ($part->text ?? ''), (string) ($part->charset ?? ''));
        }
        return $result;
    }

    private function toUtf8(string $value, string $charset): string
    {
        if ($value === '' || $charset === '' || in_array(strtoupper($charset), ['DEFAULT', 'UTF-8', 'US-ASCII'], true)) {
            return $value;
        }
        $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($value, 'UTF-8', $charset) : false;
        return is_string($converted) ? $converted : $value;
    }

    private function emailDate(string $value): string
    {
        $timestamp = strtotime($value);
        return date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    private function cleanImapError(string $error): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? 'Error de conexión IMAP.';
        return mb_substr(trim(preg_replace('/\s+/', ' ', $clean) ?? $clean), 0, 300);
    }

    private function response(array $results, array $totals, string $message): array
    {
        $rows = $this->latestByCompany();
        foreach ($rows as &$row) {
            $status = $results[(int) $row['id']] ?? null;
            $row['refresh_status'] = $status['status'] ?? 'pending';
            $row['refresh_message'] = $status['message'] ?? 'Sin actualización en esta solicitud.';
            $row['unread'] = (int) ($status['unread'] ?? 0);
            $row['inserted'] = (int) ($status['inserted'] ?? 0);
            $row['duplicates'] = (int) ($status['duplicates'] ?? 0);
        }
        unset($row);
        return ['message' => $message, 'summary' => $totals, 'rows' => $rows];
    }
}
