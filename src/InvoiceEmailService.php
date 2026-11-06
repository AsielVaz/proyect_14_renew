<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class InvoiceEmailService
{
    private const FROM_EMAIL = 'no-reply@sistema14.org';
    private const FROM_NAME = 'Sistema 14 - Facturación';
    private const API_URL = 'https://mandrillapp.com/api/1.4/messages/send';
    private const SYSTEM14_XML_URL = 'https://ultra.sistema14.com/api/gateWayFactura.php?factura=';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PdfDocumentService $documents,
    ) {
    }

    public function sendToInvoiceContact(
        int $invoiceId,
        int $operatorId,
        bool $allowRapidDuplicate = false,
        bool $requireStampedDocument = false,
    ): array {
        $invoice = $requireStampedDocument
            ? $this->waitForStampedInvoice($invoiceId)
            : $this->invoice($invoiceId);
        $recipient = trim((string) ($invoice['client_email'] ?? ''));
        if ($recipient === '') {
            return [
                'status' => 'skipped',
                'message' => 'El cliente no tiene un correo de facturación capturado en correo2; no se realizó el envío.',
                'invoiceId' => $invoiceId,
            ];
        }
        return $this->send($invoiceId, $recipient, $operatorId, $allowRapidDuplicate, $requireStampedDocument, true);
    }

    public function send(
        int $invoiceId,
        string $recipient,
        int $operatorId,
        bool $allowRapidDuplicate = false,
        bool $requireStampedDocument = false,
        bool $includeInvoiceCopies = false,
    ): array
    {
        if ($invoiceId <= 0 || $operatorId <= 0) {
            throw new RuntimeException('La factura o el operador no son válidos.');
        }
        $recipient = mb_strtolower(trim($recipient));
        if (strlen($recipient) > 100 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Captura un correo electrónico válido.');
        }
        $invoice = $requireStampedDocument
            ? $this->waitForStampedInvoice($invoiceId)
            : $this->invoice($invoiceId);
        $stamped = trim((string) ($invoice['uuid'] ?? '')) !== '';
        if ($requireStampedDocument && !$stamped) {
            throw new RuntimeException('La factura se timbró, pero el UUID todavía no está disponible para preparar el correo.');
        }
        if (!$allowRapidDuplicate) $this->preventRapidDuplicate($invoiceId, $recipient);
        $pdf = $this->documents->invoice($invoiceId);
        $pdfBytes = (string) ($pdf['bytes'] ?? '');
        if (strlen($pdfBytes) < 5 || !str_starts_with($pdfBytes, '%PDF-')) {
            throw new RuntimeException('No fue posible generar un PDF válido; el correo no se envió para evitar adjuntos incompletos.');
        }
        $folio = trim((string) ($invoice['serie'] ?? '') . (string) ($invoice['folio'] ?? ''));
        if ($folio === '') $folio = '#' . $invoiceId;
        $subject = 'Factura ' . $folio . ' - ' . (string) ($invoice['empresa'] ?? 'Sistema 14');
        $attachments = [[
            'type' => 'application/pdf',
            'name' => (string) $pdf['filename'],
            'content' => base64_encode($pdfBytes),
        ]];
        $xml = $stamped ? $this->system14Xml($invoice) : null;
        if ($xml !== null) {
            $attachments[] = [
                'type' => 'application/xml',
                'name' => 'XML-factura-' . $invoiceId . '-' . $this->safeName((string) $invoice['uuid']) . '.xml',
                'content' => base64_encode($xml),
            ];
        } elseif ($stamped || $requireStampedDocument) {
            throw new RuntimeException('No fue posible obtener el XML timbrado desde Sistema 14; el correo no se envió para evitar adjuntos incompletos.');
        }

        $message = [
            'from_email' => self::FROM_EMAIL,
            'from_name' => self::FROM_NAME,
            'to' => $this->recipients($recipient, $invoice, $includeInvoiceCopies),
            'subject' => $subject,
            'html' => $this->html($invoice, $folio),
            'text' => $this->text($invoice, $folio),
            'attachments' => $attachments,
            'important' => true,
            'track_opens' => true,
            'track_clicks' => false,
            'auto_text' => true,
        ];

        $auditId = $this->beginAudit($invoice, $recipient, $subject);
        try {
            $response = $this->sendMandrill($message);
            $accepted = in_array($response['status'], ['sent', 'queued', 'scheduled'], true);
            $attachmentAudit = array_map(static fn (array $attachment): array => [
                'name' => (string) ($attachment['name'] ?? ''),
                'type' => (string) ($attachment['type'] ?? ''),
                'bytes' => (int) floor(strlen((string) ($attachment['content'] ?? '')) * 3 / 4),
            ], $attachments);
            $this->finishAudit(
                $auditId,
                $accepted ? ($response['status'] === 'sent' ? 1 : 2) : -1,
                $response + ['attachments' => $attachmentAudit]
            );
            if ($accepted) {
                $this->pdo->prepare("UPDATE facturas SET enviado = 'si' WHERE id = :id")->execute(['id' => $invoiceId]);
            }
            return $response + [
                'invoiceId' => $invoiceId,
                'recipient' => $recipient,
                'from' => self::FROM_EMAIL,
                'attachmentCount' => count($attachments),
            ];
        } catch (Throwable $exception) {
            $this->finishAudit($auditId, -2, ['status' => 'error', 'message' => $exception->getMessage()]);
            throw $exception;
        }
    }

    private function invoice(int $id): array
    {
        $query = $this->pdo->prepare('SELECT f.id, f.serie, f.folio, f.uuid, f.total_iva,
            f.xml_firmado, f.razon AS company_id,
            COALESCE(cc.id_cliente, CAST(f.nombre AS UNSIGNED)) AS client_id,
            e.razon AS empresa, e.rfc AS empresa_rfc,
            c.nombre AS cliente,
            NULLIF(TRIM(c.correo2), "") AS client_email,
            c.correo2_2_f AS client_email_copy_1, c.correo2_3_f AS client_email_copy_2,
            cc.razon_social AS receptor, cc.rfc AS receptor_rfc
            FROM facturas f
            LEFT JOIN empresas e ON e.id = f.razon
            LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
            LEFT JOIN clientes c ON c.id = COALESCE(cc.id_cliente, CAST(f.nombre AS UNSIGNED))
            WHERE f.id = :id LIMIT 1');
        $query->execute(['id' => $id]);
        $invoice = $query->fetch();
        if (!$invoice) throw new RuntimeException('La factura solicitada no existe.');
        return $invoice;
    }

    private function recipients(string $primary, array $invoice, bool $includeCopies): array
    {
        $recipients = [['email' => $primary, 'type' => 'to']];
        if (!$includeCopies) {
            return $recipients;
        }
        $used = [mb_strtolower($primary) => true];
        foreach (['client_email_copy_1', 'client_email_copy_2'] as $column) {
            $email = mb_strtolower(trim((string) ($invoice[$column] ?? '')));
            if ($email === '' || isset($used[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $recipients[] = ['email' => $email, 'type' => 'bcc'];
            $used[$email] = true;
        }
        return $recipients;
    }

    private function waitForStampedInvoice(int $id): array
    {
        $invoice = $this->invoice($id);
        for ($attempt = 0; $attempt < 5 && trim((string) ($invoice['uuid'] ?? '')) === ''; $attempt++) {
            usleep(300000);
            $invoice = $this->invoice($id);
        }
        return $invoice;
    }

    private function system14Xml(array $invoice): ?string
    {
        $invoiceId = (int) ($invoice['id'] ?? 0);
        $uuid = trim((string) ($invoice['uuid'] ?? ''));
        if ($invoiceId <= 0) return null;
        if (function_exists('curl_init')) {
            $handle = curl_init(self::SYSTEM14_XML_URL . rawurlencode((string) $invoiceId));
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml;q=0.9, */*;q=0.2'],
                CURLOPT_USERAGENT => 'Sistema14-InvoiceMailer/1.0',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            if (is_string($body) && $status >= 200 && $status < 300 && $this->isXml($body, $uuid)) {
                return $body;
            }
            if ($error !== '') error_log('InvoiceEmail XML download: ' . $error);
        }
        $stored = (string) ($invoice['xml_firmado'] ?? '');
        return $this->isXml($stored, $uuid) ? $stored : null;
    }

    private function isXml(string $content, string $uuid = ''): bool
    {
        $content = ltrim($content, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($content === '' || $content[0] !== '<') return false;
        $isCfdi = stripos($content, '<cfdi:Comprobante') !== false
            || stripos($content, '<Comprobante') !== false;
        return $isCfdi && ($uuid === '' || stripos($content, $uuid) !== false);
    }

    private function preventRapidDuplicate(int $invoiceId, string $recipient): void
    {
        $query = $this->pdo->prepare('SELECT id FROM facturas_correo
            WHERE id_factura = :invoice AND LOWER(correo_destino) = :recipient
              AND fecha_inserta >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
            ORDER BY id DESC LIMIT 1');
        $query->execute(['invoice' => $invoiceId, 'recipient' => $recipient]);
        if ($query->fetchColumn()) {
            throw new RuntimeException('Ya se intentó enviar esta factura al mismo correo. Espera 30 segundos antes de repetir.');
        }
    }

    private function beginAudit(array $invoice, string $recipient, string $subject): int
    {
        $query = $this->pdo->prepare('INSERT INTO facturas_correo
            (correo_origen, correo_destino, tamplate, empresa_origen, empresa_destino,
             fecha_inserta, estado, monto, respuesta, id_factura)
            VALUES (:origin, :destination, :template, :origin_company, :destination_company,
             NOW(), 0, :amount, :response, :invoice)');
        $query->execute([
            'origin' => self::FROM_EMAIL,
            'destination' => $recipient,
            'template' => mb_substr($subject, 0, 1024),
            'origin_company' => (int) ($invoice['company_id'] ?? 0) ?: null,
            'destination_company' => (int) ($invoice['client_id'] ?? 0) ?: null,
            'amount' => (float) ($invoice['total_iva'] ?? 0),
            'response' => 'Solicitud enviada a Mailchimp Transactional.',
            'invoice' => (int) $invoice['id'],
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
            'state' => $state,
            'response' => mb_substr($encoded ?: 'Respuesta no disponible.', 0, 1024),
            'id' => $auditId,
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Sistema14-InvoiceMailer/1.0',
        ]);
        $raw = curl_exec($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($handle);
        if ($raw === false) {
            error_log('InvoiceEmail Mandrill network error: ' . $networkError);
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
        if (!$recipient || !isset($recipient['status'])) {
            throw new RuntimeException('Mailchimp no devolvió el estado del destinatario.');
        }
        $status = strtolower((string) $recipient['status']);
        $reason = trim((string) ($recipient['reject_reason'] ?? $recipient['queued_reason'] ?? ''));
        $messageText = match ($status) {
            'sent' => 'Correo enviado correctamente por Mailchimp.',
            'queued' => 'Mailchimp aceptó el correo y lo dejó en cola para entrega.',
            'scheduled' => 'Mailchimp aceptó el correo y programó su entrega.',
            'rejected' => 'Mailchimp rechazó el correo' . ($reason !== '' ? ': ' . $reason : '.'),
            'invalid' => 'Mailchimp indicó que el destinatario no es válido.',
            default => 'Mailchimp respondió con estado: ' . $status . '.',
        };
        return [
            'status' => $status,
            'message' => $messageText,
            'messageId' => (string) ($recipient['_id'] ?? ''),
            'reason' => $reason,
        ];
    }

    private function html(array $invoice, string $folio): string
    {
        $company = $this->escape((string) ($invoice['empresa'] ?? 'Sistema 14'));
        $receiver = $this->escape((string) (($invoice['receptor'] ?? '') ?: ($invoice['cliente'] ?? 'Cliente')));
        $uuid = trim((string) ($invoice['uuid'] ?? ''));
        $status = $uuid !== '' ? 'CFDI timbrado' : 'Vista previa pendiente de timbrado';
        return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:Arial,sans-serif;color:#172033">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px">'
            . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#fff;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:#151d2d;color:#fff;padding:24px 30px;border-left:6px solid #f26a0a">'
            . '<div style="font-size:12px;color:#f7a160;text-transform:uppercase;font-weight:bold">Sistema 14</div>'
            . '<div style="font-size:24px;font-weight:bold;margin-top:5px">Factura ' . $this->escape($folio) . '</div></td></tr>'
            . '<tr><td style="padding:28px 30px"><p style="margin:0 0 16px">Hola,</p>'
            . '<p style="margin:0 0 18px;line-height:1.6">Adjuntamos la factura emitida por <strong>' . $company . '</strong> para <strong>' . $receiver . '</strong>.</p>'
            . '<table role="presentation" width="100%" style="background:#f7f8fb;border:1px solid #e1e6ee;border-radius:10px" cellpadding="12"><tr>'
            . '<td><small style="color:#68758b">TOTAL</small><br><strong style="font-size:18px">$' . number_format((float) ($invoice['total_iva'] ?? 0), 2) . '</strong></td>'
            . '<td><small style="color:#68758b">ESTADO</small><br><strong>' . $status . '</strong></td></tr></table>'
            . ($uuid !== '' ? '<p style="font-size:12px;color:#68758b;margin:18px 0 0"><strong>UUID:</strong> ' . $this->escape(strtoupper($uuid)) . '</p>' : '')
            . '<p style="margin:24px 0 0;color:#68758b;font-size:12px">Este mensaje fue enviado automáticamente desde sistema14.org.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private function text(array $invoice, string $folio): string
    {
        $lines = [
            'Factura ' . $folio,
            'Emisor: ' . (string) ($invoice['empresa'] ?? 'Sistema 14'),
            'Receptor: ' . (string) (($invoice['receptor'] ?? '') ?: ($invoice['cliente'] ?? 'Cliente')),
            'Total: $' . number_format((float) ($invoice['total_iva'] ?? 0), 2),
        ];
        if (trim((string) ($invoice['uuid'] ?? '')) !== '') $lines[] = 'UUID: ' . strtoupper((string) $invoice['uuid']);
        $lines[] = '';
        $lines[] = 'Se adjunta el documento PDF generado por Sistema 14.';
        return implode("\n", $lines);
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
