<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class ComplementEmailService
{
    private const FROM_EMAIL = 'no-reply@sistema14.org';
    private const FROM_NAME = 'Sistema 14 - Facturación';
    private const API_URL = 'https://mandrillapp.com/api/1.4/messages/send';
    private const XML_BASE_URL = 'https://ultra.sistema14.com/api/xml/firmados-comp/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PdfDocumentService $documents,
    ) {
    }

    public function sendToComplementContact(
        int $complementId,
        int $operatorId,
        bool $allowRapidDuplicate = false,
        bool $requireStampedDocument = true,
    ): array {
        $complement = $requireStampedDocument
            ? $this->waitForStampedComplement($complementId)
            : $this->complement($complementId);
        $recipient = trim((string) ($complement['client_email'] ?? ''));
        if ($recipient === '') {
            return [
                'status' => 'skipped',
                'message' => 'El cliente no tiene un correo de facturación capturado en correo2; no se realizó el envío.',
                'complementId' => $complementId,
            ];
        }
        return $this->send($complementId, $recipient, $operatorId, $allowRapidDuplicate, $requireStampedDocument, true);
    }

    public function send(
        int $complementId,
        string $recipient,
        int $operatorId,
        bool $allowRapidDuplicate = false,
        bool $requireStampedDocument = true,
        bool $includeInvoiceCopies = false,
    ): array {
        if ($complementId <= 0 || $operatorId <= 0) {
            throw new RuntimeException('El complemento o el operador no son válidos.');
        }
        $recipient = mb_strtolower(trim($recipient));
        if (strlen($recipient) > 100 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Captura un correo electrónico válido.');
        }

        $complement = $requireStampedDocument
            ? $this->waitForStampedComplement($complementId)
            : $this->complement($complementId);
        $uuid = trim((string) ($complement['uuid'] ?? ''));
        if ($uuid === '') {
            throw new RuntimeException('El complemento debe estar timbrado antes de enviarlo por correo.');
        }
        if (!$allowRapidDuplicate) $this->preventRapidDuplicate($complementId, $recipient);

        $pdf = $this->documents->complement($complementId);
        $pdfBytes = (string) ($pdf['bytes'] ?? '');
        if (strlen($pdfBytes) < 5 || !str_starts_with($pdfBytes, '%PDF-')) {
            throw new RuntimeException('No fue posible generar un PDF válido; el correo no se envió para evitar adjuntos incompletos.');
        }
        $xml = $this->system14Xml($complement);
        if ($xml === null) {
            throw new RuntimeException('No fue posible obtener el XML timbrado; el correo no se envió para evitar adjuntos incompletos.');
        }

        $folio = trim((string) ($complement['serie'] ?? 'P') . (string) ($complement['folio_internal'] ?? $complement['folio'] ?? ''));
        if ($folio === '') $folio = '#' . $complementId;
        $subject = 'Complemento de pago ' . $folio . ' - ' . (string) ($complement['empresa'] ?? 'Sistema 14');
        $attachments = [
            [
                'type' => 'application/pdf',
                'name' => (string) $pdf['filename'],
                'content' => base64_encode($pdfBytes),
            ],
            [
                'type' => 'application/xml',
                'name' => 'XML-complemento-' . $complementId . '-' . $this->safeName($uuid) . '.xml',
                'content' => base64_encode($xml),
            ],
        ];
        $message = [
            'from_email' => self::FROM_EMAIL,
            'from_name' => self::FROM_NAME,
            'to' => $this->recipients($recipient, $complement, $includeInvoiceCopies),
            'subject' => $subject,
            'html' => $this->html($complement, $folio),
            'text' => $this->text($complement, $folio),
            'attachments' => $attachments,
            'important' => true,
            'track_opens' => true,
            'track_clicks' => false,
            'auto_text' => true,
        ];

        $auditId = $this->beginAudit($complement, $recipient, $subject);
        try {
            $response = $this->sendMandrill($message);
            $accepted = in_array($response['status'], ['sent', 'queued', 'scheduled'], true);
            $attachmentAudit = array_map(static fn (array $attachment): array => [
                'name' => (string) ($attachment['name'] ?? ''),
                'type' => (string) ($attachment['type'] ?? ''),
                'bytes' => (int) floor(strlen((string) ($attachment['content'] ?? '')) * 3 / 4),
            ], $attachments);
            $this->finishAudit($auditId, $accepted ? ($response['status'] === 'sent' ? 1 : 2) : -1,
                $response + ['document_type' => 'complement', 'attachments' => $attachmentAudit]);
            if ($accepted) {
                $this->pdo->prepare("UPDATE facturas_complementos SET enviado = 'si' WHERE id = :id")
                    ->execute(['id' => $complementId]);
            }
            return $response + [
                'complementId' => $complementId,
                'recipient' => $recipient,
                'from' => self::FROM_EMAIL,
                'attachmentCount' => 2,
            ];
        } catch (Throwable $exception) {
            $this->finishAudit($auditId, -2, [
                'status' => 'error', 'document_type' => 'complement', 'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function complement(int $id): array
    {
        $query = $this->pdo->prepare('SELECT fc.id, fc.serie, fc.folio, fc.uuid, fc.total_factura,
            fc.xml_firmado, fc.razon AS company_id, fc.fecha_pago,
            COALESCE(cc.id_cliente, CAST(fc.nombre AS UNSIGNED)) AS client_id,
            e.razon AS empresa, e.rfc AS empresa_rfc,
            c.nombre AS cliente, NULLIF(TRIM(c.correo2), "") AS client_email,
            c.correo2_2_f AS client_email_copy_1, c.correo2_3_f AS client_email_copy_2,
            cc.razon_social AS receptor, cc.rfc AS receptor_rfc,
            COALESCE((SELECT cf.folio FROM complementos_folio cf
                WHERE cf.id_factura = fc.id ORDER BY cf.id DESC LIMIT 1), fc.folio) AS folio_internal
            FROM facturas_complementos fc
            LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN claves_cortas cc ON cc.id = CAST(fc.id_clave_corta AS UNSIGNED)
            LEFT JOIN clientes c ON c.id = COALESCE(cc.id_cliente, CAST(fc.nombre AS UNSIGNED))
            WHERE fc.id = :id LIMIT 1');
        $query->execute(['id' => $id]);
        $complement = $query->fetch();
        if (!$complement) throw new RuntimeException('El complemento solicitado no existe.');
        return $complement;
    }

    private function waitForStampedComplement(int $id): array
    {
        $complement = $this->complement($id);
        for ($attempt = 0; $attempt < 8 && trim((string) ($complement['uuid'] ?? '')) === ''; $attempt++) {
            usleep(350000);
            $complement = $this->complement($id);
        }
        return $complement;
    }

    private function recipients(string $primary, array $complement, bool $includeCopies): array
    {
        $recipients = [['email' => $primary, 'type' => 'to']];
        if (!$includeCopies) return $recipients;
        $used = [mb_strtolower($primary) => true];
        foreach (['client_email_copy_1', 'client_email_copy_2'] as $column) {
            $email = mb_strtolower(trim((string) ($complement[$column] ?? '')));
            if ($email === '' || isset($used[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $recipients[] = ['email' => $email, 'type' => 'bcc'];
            $used[$email] = true;
        }
        return $recipients;
    }

    private function system14Xml(array $complement): ?string
    {
        $id = (int) ($complement['id'] ?? 0);
        $uuid = trim((string) ($complement['uuid'] ?? ''));
        if ($id <= 0 || $uuid === '') return null;
        $stored = (string) ($complement['xml_firmado'] ?? '');
        if ($this->isXml($stored, $uuid)) return $stored;
        $url = self::XML_BASE_URL . 'XML-complemento-' . rawurlencode((string) $id) . '-' . rawurlencode($uuid) . '.xml';
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 60,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml;q=0.9, */*;q=0.2'],
                CURLOPT_USERAGENT => 'Sistema14-ComplementMailer/1.0',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            if (is_string($body) && $status >= 200 && $status < 300 && $this->isXml($body, $uuid)) return $body;
            if ($error !== '') error_log('ComplementEmail XML download: ' . $error);
        }
        return $this->isXml($stored, $uuid) ? $stored : null;
    }

    private function isXml(string $content, string $uuid): bool
    {
        $content = ltrim($content, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($content === '' || $content[0] !== '<') return false;
        $isCfdi = stripos($content, '<cfdi:Comprobante') !== false || stripos($content, '<Comprobante') !== false;
        return $isCfdi && stripos($content, $uuid) !== false;
    }

    private function preventRapidDuplicate(int $complementId, string $recipient): void
    {
        $query = $this->pdo->prepare('SELECT id FROM facturas_correo
            WHERE id_factura = :document AND LOWER(correo_destino) = :recipient
              AND tamplate LIKE "Complemento de pago %"
              AND fecha_inserta >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
            ORDER BY id DESC LIMIT 1');
        $query->execute(['document' => -$complementId, 'recipient' => $recipient]);
        if ($query->fetchColumn()) {
            throw new RuntimeException('Ya se intentó enviar este complemento al mismo correo. Espera 30 segundos antes de repetir.');
        }
    }

    private function beginAudit(array $complement, string $recipient, string $subject): int
    {
        $query = $this->pdo->prepare('INSERT INTO facturas_correo
            (correo_origen, correo_destino, tamplate, empresa_origen, empresa_destino,
             fecha_inserta, estado, monto, respuesta, id_factura)
            VALUES (:origin, :destination, :template, :origin_company, :destination_company,
             NOW(), 0, :amount, :response, :document)');
        $query->execute([
            'origin' => self::FROM_EMAIL, 'destination' => $recipient,
            'template' => mb_substr($subject, 0, 1024),
            'origin_company' => (int) ($complement['company_id'] ?? 0) ?: null,
            'destination_company' => (int) ($complement['client_id'] ?? 0) ?: null,
            'amount' => (float) ($complement['total_factura'] ?? 0),
            'response' => 'Solicitud de complemento enviada a Mailchimp Transactional.',
            // Se usa el ID negativo para distinguir el complemento sin cambiar el esquema histórico.
            'document' => -(int) $complement['id'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function finishAudit(int $auditId, int $state, array $response): void
    {
        if ($auditId <= 0) return;
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = $this->pdo->prepare('UPDATE facturas_correo
            SET estado = :state, fecha_envia = NOW(), respuesta = :response WHERE id = :id');
        $query->execute([
            'state' => $state, 'response' => mb_substr($encoded ?: 'Respuesta no disponible.', 0, 1024), 'id' => $auditId,
        ]);
    }

    private function sendMandrill(array $message): array
    {
        $key = trim(env_value('MANDRILL_API_KEY', ''));
        if ($key === '') throw new RuntimeException('Falta configurar MANDRILL_API_KEY para enviar correos.');
        if (!function_exists('curl_init')) throw new RuntimeException('El envío requiere la extensión PHP cURL.');
        $payload = json_encode(['key' => $key, 'message' => $message, 'async' => false],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = curl_init(self::API_URL);
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 75,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Sistema14-ComplementMailer/1.0',
        ]);
        $raw = curl_exec($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($handle);
        if ($raw === false) {
            error_log('ComplementEmail Mandrill network error: ' . $networkError);
            throw new RuntimeException('No se pudo confirmar el envío con Mailchimp. Inténtalo nuevamente.');
        }
        $decoded = json_decode((string) $raw, true);
        if ($http < 200 || $http >= 300 || !is_array($decoded)) {
            $messageText = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            throw new RuntimeException($messageText !== '' ? $messageText : 'Mailchimp devolvió una respuesta no válida.');
        }
        if (($decoded['status'] ?? '') === 'error') {
            throw new RuntimeException(trim((string) ($decoded['message'] ?? '')) ?: 'Mailchimp rechazó la solicitud.');
        }
        $recipient = is_array($decoded[0] ?? null) ? $decoded[0] : null;
        if (!$recipient || !isset($recipient['status'])) throw new RuntimeException('Mailchimp no devolvió el estado del destinatario.');
        $status = strtolower((string) $recipient['status']);
        $reason = trim((string) ($recipient['reject_reason'] ?? $recipient['queued_reason'] ?? ''));
        $messageText = match ($status) {
            'sent' => 'Complemento enviado correctamente por Mailchimp.',
            'queued' => 'Mailchimp aceptó el complemento y lo dejó en cola para entrega.',
            'scheduled' => 'Mailchimp aceptó el complemento y programó su entrega.',
            'rejected' => 'Mailchimp rechazó el correo' . ($reason !== '' ? ': ' . $reason : '.'),
            'invalid' => 'Mailchimp indicó que el destinatario no es válido.',
            default => 'Mailchimp respondió con estado: ' . $status . '.',
        };
        return ['status' => $status, 'message' => $messageText,
            'messageId' => (string) ($recipient['_id'] ?? ''), 'reason' => $reason];
    }

    private function html(array $complement, string $folio): string
    {
        $company = $this->escape((string) ($complement['empresa'] ?? 'Sistema 14'));
        $receiver = $this->escape((string) (($complement['receptor'] ?? '') ?: ($complement['cliente'] ?? 'Cliente')));
        $uuid = $this->escape(strtoupper((string) ($complement['uuid'] ?? '')));
        $paymentDate = trim((string) ($complement['fecha_pago'] ?? ''));
        $paymentDate = $paymentDate !== '' ? date('d/m/Y', strtotime($paymentDate) ?: time()) : '—';
        return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>@media(max-width:640px){.email-pad{padding:18px!important}.email-title{font-size:23px!important}.email-data td{display:block!important;width:auto!important;border-left:0!important;border-top:1px solid #e7e2de}.email-data td:first-child{border-top:0}}</style></head>'
            . '<body style="margin:0;background:#f5f2ef;font-family:Arial,Helvetica,sans-serif;color:#242326">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" class="email-pad" style="padding:30px 18px">'
            . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#fff;border:1px solid #e2d8d1;border-radius:18px;overflow:hidden">'
            . '<tr><td style="padding:26px 30px;background:#171515;border-left:6px solid #f56600;color:#fff"><div style="font-size:11px;font-weight:800;letter-spacing:1.5px;color:#ff9a55">SISTEMA 14</div>'
            . '<div class="email-title" style="margin-top:7px;font-size:27px;font-weight:800">Complemento de pago ' . $this->escape($folio) . '</div><div style="margin-top:7px;color:#c9c2be;font-size:13px">CFDI timbrado · Documentos adjuntos</div></td></tr>'
            . '<tr><td class="email-pad" style="padding:30px"><p style="margin:0 0 16px;font-size:16px">Hola,</p>'
            . '<p style="margin:0 0 22px;line-height:1.65;color:#514d4a">Adjuntamos el complemento de pago emitido por <strong style="color:#242326">' . $company . '</strong> para <strong style="color:#242326">' . $receiver . '</strong>.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="email-data" style="background:#faf8f6;border:1px solid #e7e2de;border-radius:12px;overflow:hidden"><tr>'
            . '<td width="50%" style="padding:16px 18px"><small style="display:block;color:#8a7f78;font-size:10px;font-weight:bold;letter-spacing:1px">MONTO DEL PAGO</small><strong style="display:block;margin-top:5px;font-size:21px;color:#e85f00">$' . number_format((float) ($complement['total_factura'] ?? 0), 2) . '</strong></td>'
            . '<td width="50%" style="padding:16px 18px;border-left:1px solid #e7e2de"><small style="display:block;color:#8a7f78;font-size:10px;font-weight:bold;letter-spacing:1px">FECHA DE PAGO</small><strong style="display:block;margin-top:7px">' . $this->escape($paymentDate) . '</strong></td></tr></table>'
            . '<p style="margin:20px 0 0;padding:13px 15px;background:#fff7f1;border-left:3px solid #f56600;color:#615a55;font-size:12px;line-height:1.55"><strong>UUID:</strong><br>' . $uuid . '</p>'
            . '<p style="margin:24px 0 0;color:#8a817c;font-size:12px;line-height:1.5">Se adjuntan el PDF generado por Sistema 14 y el XML timbrado. Este mensaje fue enviado automáticamente desde sistema14.org.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private function text(array $complement, string $folio): string
    {
        return implode("\n", [
            'Complemento de pago ' . $folio,
            'Emisor: ' . (string) ($complement['empresa'] ?? 'Sistema 14'),
            'Receptor: ' . (string) (($complement['receptor'] ?? '') ?: ($complement['cliente'] ?? 'Cliente')),
            'Monto: $' . number_format((float) ($complement['total_factura'] ?? 0), 2),
            'UUID: ' . strtoupper((string) ($complement['uuid'] ?? '')),
            '', 'Se adjuntan el PDF y el XML timbrado del complemento de pago.',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeName(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'documento';
    }
}
