<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

final class LocalComplementStampService
{
    private const CFDI_NS = 'http://www.sat.gob.mx/cfd/4';
    private const PAYMENT_NS = 'http://www.sat.gob.mx/Pagos20';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const SCHEMA_LOCATION = 'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd '
        . 'http://www.sat.gob.mx/Pagos20 http://www.sat.gob.mx/sitio_internet/cfd/Pagos/Pagos20.xsd';
    private const AUTH_PATH = '/api/Tunel/AutenticaUsuario';
    private const STAMP_PATH = '/api/Tunel/TimbrarDocumento';
    private const ALLOWED_TREATMENTS = ['T', 'T0', 'SV', '4'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PdfDocumentService $documents,
        private readonly string $storageRoot,
    ) {
    }

    public function stamp(int $id, int $operatorId): array
    {
        if ($id <= 0 || $operatorId <= 0) throw new RuntimeException('El complemento o el operador no son válidos.');
        $lockName = 's14:complement:' . $id;
        $locked = $this->acquireLock($lockName);
        if (!$locked) throw new RuntimeException('Este complemento ya se está timbrando en otra solicitud.');

        try {
            $data = $this->data($id);
            if (trim((string) ($data['uuid'] ?? '')) !== '') {
                throw new RuntimeException('El complemento ya fue timbrado anteriormente.');
            }
            $this->validateCredentials($data);
            $unsignedXml = $this->buildXml($data, new DateTimeImmutable());
            $directory = $this->documentDirectory($id);
            $this->writeFile($directory . '/XML-sinfirma-complemento-' . $id . '.xml', $unsignedXml);

            $sandbox = $this->sandboxMode();
            [$rfc, $username, $password] = $this->credentials($data, $sandbox);
            $token = $this->authenticate($rfc, $username, $password, $sandbox);
            $signedXml = $this->requestStamp($unsignedXml, $token, $sandbox);
            $fiscal = $this->fiscalData($signedXml);

            $signedPath = $directory . '/XML-complemento-' . $id . '-' . $fiscal['uuid'] . '.xml';
            $pdfPath = $directory . '/complemento-' . $id . '-' . $fiscal['uuid'] . '.pdf';
            $storageWarnings = [];
            try {
                $this->writeFile($signedPath, $signedXml);
            } catch (Throwable $exception) {
                $signedPath = '';
                $storageWarnings[] = 'no se pudo guardar la copia física del XML';
                error_log('Complement signed XML storage error #' . $id . ': ' . $exception->getMessage());
            }

            // El timbre ya existe en el PAC: su XML se confirma en la base antes de
            // generar archivos derivados para impedir un segundo timbrado si falla el PDF.
            $this->pdo->beginTransaction();
            try {
                $current = $this->pdo->prepare('SELECT uuid FROM facturas_complementos WHERE id = :id FOR UPDATE');
                $current->execute(['id' => $id]);
                $currentUuid = $current->fetchColumn();
                if ($currentUuid === false) throw new RuntimeException('El complemento solicitado ya no existe.');
                if (trim((string) $currentUuid) !== '') throw new RuntimeException('El complemento ya fue timbrado por otra solicitud.');

                $update = $this->pdo->prepare('UPDATE facturas_complementos SET
                    num_certificado = :certificate_number, certificado_fact = :certificate,
                    uuid = :uuid, fecha_timbrado = :stamp_date, hora_timbrado = :stamp_time,
                    sello_cfd = :cfd_seal, num_certificado_sat = :sat_certificate,
                    sello_sat = :sat_seal, complemento = :fiscal_complement, xml_firmado = :xml,
                    status = "Timbrada", status_error = NULL
                    WHERE id = :id');
                $update->execute([
                    'certificate_number' => $fiscal['certificate_number'], 'certificate' => $fiscal['certificate'],
                    'uuid' => $fiscal['uuid'], 'stamp_date' => $fiscal['stamp_date'],
                    'stamp_time' => $fiscal['stamp_time'], 'cfd_seal' => $fiscal['cfd_seal'],
                    'sat_certificate' => $fiscal['sat_certificate'], 'sat_seal' => $fiscal['sat_seal'],
                    'fiscal_complement' => $fiscal['complement_xml'], 'xml' => $signedXml, 'id' => $id,
                ]);

                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }

            try {
                $pdf = $this->documents->complement($id);
                $pdfBytes = (string) ($pdf['bytes'] ?? '');
                if (!str_starts_with($pdfBytes, '%PDF-')) throw new RuntimeException('El generador no devolvió un PDF válido.');
                $this->writeFile($pdfPath, $pdfBytes);
            } catch (Throwable $exception) {
                $pdfPath = '';
                $storageWarnings[] = 'no se pudo guardar la copia física del PDF';
                error_log('Complement PDF storage error #' . $id . ': ' . $exception->getMessage());
            }

            $message = 'Complemento #' . $id . ' timbrado localmente. UUID: ' . $fiscal['uuid'] . '.';
            if ($storageWarnings !== []) $message .= ' Aviso: ' . implode(' y ', $storageWarnings) . '.';
            return [
                'id' => $id, 'uuid' => $fiscal['uuid'], 'treatment' => $data['treatment'],
                'xml_path' => $signedPath, 'pdf_path' => $pdfPath,
                'message' => $message,
            ];
        } catch (Throwable $exception) {
            $this->markError($id, $exception->getMessage());
            throw $exception;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function buildUnsignedXml(int $id, ?DateTimeImmutable $issuedAt = null): string
    {
        return $this->buildXml($this->data($id), $issuedAt ?? new DateTimeImmutable());
    }

    public function xmlDocument(int $id): array
    {
        $query = $this->pdo->prepare('SELECT uuid, xml_firmado FROM facturas_complementos WHERE id = :id LIMIT 1');
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        if (!$row || trim((string) ($row['uuid'] ?? '')) === '') {
            throw new RuntimeException('El complemento todavía no tiene un XML timbrado.');
        }
        $xml = trim((string) ($row['xml_firmado'] ?? ''));
        if (!$this->validSignedXml($xml, (string) $row['uuid'])) {
            $path = $this->documentDirectory($id, false) . '/XML-complemento-' . $id . '-' . $row['uuid'] . '.xml';
            $xml = is_file($path) ? (string) file_get_contents($path) : '';
        }
        if (!$this->validSignedXml($xml, (string) $row['uuid'])) {
            throw new RuntimeException('No se encontró el XML timbrado almacenado localmente.');
        }
        return ['bytes' => $xml, 'filename' => 'XML-complemento-' . $id . '-' . $row['uuid'] . '.xml'];
    }

    /** @return array{base:float,vat:float,retention:float,taxable:bool,rate:float} */
    public static function inverseTaxes(float $amount, string $treatment): array
    {
        if ($amount <= 0 || !in_array($treatment, self::ALLOWED_TREATMENTS, true)) {
            throw new RuntimeException('El monto o tratamiento fiscal no es válido.');
        }
        if ($treatment === '4') {
            $base = round($amount / 1.12, 6);
            return ['base' => $base, 'vat' => round($base * 0.16, 6),
                'retention' => round($base * 0.04, 6), 'taxable' => true, 'rate' => 0.16];
        }
        if ($treatment === 'T') {
            $base = round($amount / 1.16, 6);
            return ['base' => $base, 'vat' => round($base * 0.16, 6),
                'retention' => 0.0, 'taxable' => true, 'rate' => 0.16];
        }
        if ($treatment === 'T0') {
            return ['base' => round($amount, 6), 'vat' => 0.0,
                'retention' => 0.0, 'taxable' => true, 'rate' => 0.0];
        }
        return ['base' => round($amount, 6), 'vat' => 0.0,
            'retention' => 0.0, 'taxable' => false, 'rate' => 0.0];
    }

    private function data(int $id): array
    {
        $query = $this->pdo->prepare('SELECT fc.*, fc.cadena AS treatment,
            e.razon AS issuer_name, e.rfc AS issuer_rfc, e.cp AS issuer_postal_code,
            e.regimen AS issuer_regime, e.usuario AS issuer_user, e.password_r AS issuer_password,
            cc.razon_social AS receiver_name, cc.rfc AS receiver_rfc, cc.cp AS receiver_postal_code,
            cc.regimen_fiscal AS receiver_regime, fp.clave AS payment_form_code
            FROM facturas_complementos fc
            INNER JOIN empresas e ON e.id = fc.razon
            INNER JOIN claves_cortas cc ON cc.id = fc.id_clave_corta
            INNER JOIN formas_pago fp ON fp.id = fc.forma_pago
            WHERE fc.id = :id LIMIT 1');
        $query->execute(['id' => $id]);
        $data = $query->fetch();
        if (!$data) throw new RuntimeException('El complemento solicitado no existe o no tiene catálogos fiscales completos.');
        $data['treatment'] = strtoupper(trim((string) ($data['treatment'] ?? '')));
        if (!in_array($data['treatment'], self::ALLOWED_TREATMENTS, true)) {
            throw new RuntimeException('El complemento no tiene un tratamiento fiscal válido desde su creación.');
        }
        $relations = $this->pdo->prepare('SELECT id, uuid_rel, monto, num_pago, saldo, restante, folio, serie
            FROM facturar_rel_complementos WHERE id_complemento = :id ORDER BY id');
        $relations->execute(['id' => $id]);
        $data['relations'] = $relations->fetchAll();
        if ($data['relations'] === []) throw new RuntimeException('El complemento no tiene facturas relacionadas.');
        $relationTotal = round(array_sum(array_map(static fn (array $row): float => (float) $row['monto'], $data['relations'])), 2);
        if (abs($relationTotal - round((float) $data['total_factura'], 2)) > 0.01) {
            throw new RuntimeException('El total del complemento no coincide con la suma de sus facturas relacionadas.');
        }
        return $data;
    }

    private function buildXml(array $data, DateTimeImmutable $issuedAt): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::CFDI_NS, 'cfdi:Comprobante');
        $document->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pago20', self::PAYMENT_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $root->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', self::SCHEMA_LOCATION);
        $this->attributes($root, [
            'Version' => '4.0', 'Fecha' => $issuedAt->format('Y-m-d\TH:i:s'),
            'Serie' => (string) (($data['serie'] ?? '') ?: 'P'), 'Folio' => (string) ($data['folio'] ?? ''),
            'Exportacion' => '01', 'SubTotal' => '0', 'Moneda' => 'XXX', 'Total' => '0',
            'TipoDeComprobante' => 'P', 'LugarExpedicion' => $this->postalCode((string) $data['issuer_postal_code']),
        ]);

        $issuer = $document->createElementNS(self::CFDI_NS, 'cfdi:Emisor');
        $root->appendChild($issuer);
        $this->attributes($issuer, ['Rfc' => $this->rfc((string) $data['issuer_rfc']),
            'Nombre' => trim((string) $data['issuer_name']), 'RegimenFiscal' => $this->regime((string) $data['issuer_regime'])]);
        $receiver = $document->createElementNS(self::CFDI_NS, 'cfdi:Receptor');
        $root->appendChild($receiver);
        $this->attributes($receiver, ['Rfc' => $this->rfc((string) $data['receiver_rfc']),
            'Nombre' => trim((string) $data['receiver_name']), 'DomicilioFiscalReceptor' => $this->postalCode((string) $data['receiver_postal_code']),
            'RegimenFiscalReceptor' => $this->regime((string) $data['receiver_regime']), 'UsoCFDI' => 'CP01']);

        $concepts = $document->createElementNS(self::CFDI_NS, 'cfdi:Conceptos');
        $root->appendChild($concepts);
        $concept = $document->createElementNS(self::CFDI_NS, 'cfdi:Concepto');
        $concepts->appendChild($concept);
        $this->attributes($concept, ['ClaveProdServ' => '84111506', 'Cantidad' => '1', 'ClaveUnidad' => 'ACT',
            'Descripcion' => 'Pago', 'ValorUnitario' => '0', 'Importe' => '0', 'ObjetoImp' => '01']);

        $complement = $document->createElementNS(self::CFDI_NS, 'cfdi:Complemento');
        $root->appendChild($complement);
        $payments = $document->createElementNS(self::PAYMENT_NS, 'pago20:Pagos');
        $complement->appendChild($payments);
        $payments->setAttribute('Version', '2.0');
        $totals = $document->createElementNS(self::PAYMENT_NS, 'pago20:Totales');
        $payments->appendChild($totals);

        $treatment = (string) $data['treatment'];
        $baseTotal = 0.0;
        $vatTotal = 0.0;
        $retentionTotal = 0.0;
        foreach ($data['relations'] as &$relation) {
            $relation['taxes'] = self::inverseTaxes((float) $relation['monto'], $treatment);
            $baseTotal += $relation['taxes']['base'];
            $vatTotal += $relation['taxes']['vat'];
            $retentionTotal += $relation['taxes']['retention'];
        }
        unset($relation);
        if ($treatment === 'T' || $treatment === '4') {
            $totals->setAttribute('TotalTrasladosBaseIVA16', self::money($baseTotal));
            $totals->setAttribute('TotalTrasladosImpuestoIVA16', self::money($vatTotal));
        } elseif ($treatment === 'T0') {
            $totals->setAttribute('TotalTrasladosBaseIVA0', self::money($baseTotal));
            $totals->setAttribute('TotalTrasladosImpuestoIVA0', '0.00');
        }
        if ($treatment === '4') $totals->setAttribute('TotalRetencionesIVA', self::money($retentionTotal));
        $totals->setAttribute('MontoTotalPagos', self::money((float) $data['total_factura']));

        $payment = $document->createElementNS(self::PAYMENT_NS, 'pago20:Pago');
        $payments->appendChild($payment);
        $paymentDate = $this->paymentDate((string) ($data['fecha_pago'] ?? ''));
        $this->attributes($payment, ['FechaPago' => $paymentDate,
            'FormaDePagoP' => str_pad((string) $data['payment_form_code'], 2, '0', STR_PAD_LEFT),
            'MonedaP' => 'MXN', 'TipoCambioP' => '1', 'Monto' => self::money((float) $data['total_factura'])]);

        foreach ($data['relations'] as $relation) {
            $related = $document->createElementNS(self::PAYMENT_NS, 'pago20:DoctoRelacionado');
            $payment->appendChild($related);
            $relatedAttributes = ['IdDocumento' => strtoupper(trim((string) $relation['uuid_rel'])),
                'MonedaDR' => 'MXN', 'EquivalenciaDR' => '1', 'NumParcialidad' => (string) max(1, (int) $relation['num_pago']),
                'ImpSaldoAnt' => self::money((float) $relation['saldo']), 'ImpPagado' => self::money((float) $relation['monto']),
                'ImpSaldoInsoluto' => self::money(max(0, (float) $relation['restante'])),
                'ObjetoImpDR' => $relation['taxes']['taxable'] ? '02' : '01'];
            if (trim((string) ($relation['serie'] ?? '')) !== '') $relatedAttributes['Serie'] = trim((string) $relation['serie']);
            if ((int) ($relation['folio'] ?? 0) > 0) $relatedAttributes['Folio'] = (string) (int) $relation['folio'];
            $this->attributes($related, $relatedAttributes);
            if ($relation['taxes']['taxable']) $this->appendRelatedTaxes($document, $related, $relation['taxes'], $treatment);
        }
        if ($treatment !== 'SV') $this->appendPaymentTaxes($document, $payment, $baseTotal, $vatTotal, $retentionTotal, $treatment);
        return $document->saveXML() ?: throw new RuntimeException('No fue posible construir el XML del complemento.');
    }

    private function appendRelatedTaxes(DOMDocument $document, DOMElement $related, array $taxes, string $treatment): void
    {
        $taxNode = $document->createElementNS(self::PAYMENT_NS, 'pago20:ImpuestosDR');
        $related->appendChild($taxNode);
        if ($treatment === '4') {
            $withholdings = $document->createElementNS(self::PAYMENT_NS, 'pago20:RetencionesDR');
            $taxNode->appendChild($withholdings);
            $withholding = $document->createElementNS(self::PAYMENT_NS, 'pago20:RetencionDR');
            $withholdings->appendChild($withholding);
            $this->attributes($withholding, ['BaseDR' => self::decimal($taxes['base']), 'ImpuestoDR' => '002',
                'TipoFactorDR' => 'Tasa', 'TasaOCuotaDR' => '0.040000', 'ImporteDR' => self::decimal($taxes['retention'])]);
        }
        $transfers = $document->createElementNS(self::PAYMENT_NS, 'pago20:TrasladosDR');
        $taxNode->appendChild($transfers);
        $transfer = $document->createElementNS(self::PAYMENT_NS, 'pago20:TrasladoDR');
        $transfers->appendChild($transfer);
        $this->attributes($transfer, ['BaseDR' => self::decimal($taxes['base']), 'ImpuestoDR' => '002',
            'TipoFactorDR' => 'Tasa', 'TasaOCuotaDR' => $treatment === 'T0' ? '0.000000' : '0.160000',
            'ImporteDR' => self::decimal($taxes['vat'])]);
    }

    private function appendPaymentTaxes(DOMDocument $document, DOMElement $payment, float $base, float $vat, float $retention, string $treatment): void
    {
        $taxNode = $document->createElementNS(self::PAYMENT_NS, 'pago20:ImpuestosP');
        $payment->appendChild($taxNode);
        if ($treatment === '4') {
            $withholdings = $document->createElementNS(self::PAYMENT_NS, 'pago20:RetencionesP');
            $taxNode->appendChild($withholdings);
            $withholding = $document->createElementNS(self::PAYMENT_NS, 'pago20:RetencionP');
            $withholdings->appendChild($withholding);
            $this->attributes($withholding, ['ImpuestoP' => '002', 'ImporteP' => self::decimal($retention)]);
        }
        $transfers = $document->createElementNS(self::PAYMENT_NS, 'pago20:TrasladosP');
        $taxNode->appendChild($transfers);
        $transfer = $document->createElementNS(self::PAYMENT_NS, 'pago20:TrasladoP');
        $transfers->appendChild($transfer);
        $this->attributes($transfer, ['BaseP' => self::decimal($base), 'ImpuestoP' => '002',
            'TipoFactorP' => 'Tasa', 'TasaOCuotaP' => $treatment === 'T0' ? '0.000000' : '0.160000',
            'ImporteP' => self::decimal($vat)]);
    }

    private function authenticate(string $rfc, string $username, string $password, bool $sandbox): string
    {
        $response = $this->postJson($this->apiBase($sandbox) . self::AUTH_PATH,
            ['Rfc' => $rfc, 'Usuario' => $username, 'Password' => $password]);
        $token = trim((string) ($response['Mensaje'] ?? ''));
        if ($token === '' || str_contains($token, '|')) {
            throw new RuntimeException($this->apiError($response, 'Iofacturo no devolvió un token de autenticación.'));
        }
        return $token;
    }

    private function requestStamp(string $xml, string $token, bool $sandbox): string
    {
        $response = $this->postJson($this->apiBase($sandbox) . self::STAMP_PATH,
            ['Comprobante' => $xml, 'Token' => $token]);
        $signed = trim((string) ($response['Mensaje'] ?? ''));
        if (!$this->validSignedXml($signed, (string) ($response['DatoAdicional'] ?? ''))) {
            throw new RuntimeException($this->apiError($response, 'Iofacturo no devolvió un CFDI timbrado válido.'));
        }
        return $signed;
    }

    private function postJson(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('El timbrado local requiere la extensión PHP cURL.');
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Sistema14-LocalComplementStamp/1.0',
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if ($raw === false) throw new RuntimeException('No fue posible conectar con Iofacturo: ' . $error);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Iofacturo devolvió una respuesta HTTP no válida (' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->apiError($decoded, 'Iofacturo rechazó la solicitud HTTP (' . $status . ').'));
        }
        return $decoded;
    }

    private function fiscalData(string $xml): array
    {
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) throw new RuntimeException('El CFDI timbrado no contiene XML válido.');
        $xpath = new DOMXPath($document);
        $root = $document->documentElement;
        $stamp = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Complemento"]/*[local-name()="TimbreFiscalDigital"]')?->item(0);
        $complement = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Complemento"]')?->item(0);
        if (!$root instanceof DOMElement || !$stamp instanceof DOMElement || !$complement instanceof DOMElement) {
            throw new RuntimeException('El CFDI timbrado no contiene Timbre Fiscal Digital.');
        }
        $uuid = strtoupper(trim($stamp->getAttribute('UUID')));
        if (!preg_match('/^[0-9A-F]{8}(?:-[0-9A-F]{4}){3}-[0-9A-F]{12}$/D', $uuid)) {
            throw new RuntimeException('Iofacturo devolvió un UUID fiscal no válido.');
        }
        $timestamp = trim($stamp->getAttribute('FechaTimbrado'));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $timestamp, $parts)) {
            throw new RuntimeException('Iofacturo devolvió una fecha de timbrado no válida.');
        }
        return ['uuid' => $uuid, 'certificate_number' => trim($root->getAttribute('NoCertificado')),
            'certificate' => trim($root->getAttribute('Certificado')), 'stamp_date' => $parts[1], 'stamp_time' => $parts[2],
            'cfd_seal' => trim($stamp->getAttribute('SelloCFD')), 'sat_certificate' => trim($stamp->getAttribute('NoCertificadoSAT')),
            'sat_seal' => trim($stamp->getAttribute('SelloSAT')), 'complement_xml' => $document->saveXML($complement) ?: ''];
    }

    private function validSignedXml(string $xml, string $uuid): bool
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
        if ($xml === '' || $xml[0] !== '<' || stripos($xml, 'Comprobante') === false || stripos($xml, 'TimbreFiscalDigital') === false) return false;
        $uuid = strtoupper(trim($uuid));
        return $uuid === '' || stripos($xml, $uuid) !== false;
    }

    private function attributes(DOMElement $element, array $attributes): void
    {
        foreach ($attributes as $name => $value) $element->setAttribute((string) $name, (string) $value);
    }

    private function credentials(array $data, bool $sandbox): array
    {
        if ($sandbox) return [env_value('IOFACTURO_SANDBOX_RFC', ''), env_value('IOFACTURO_SANDBOX_USER', ''), env_value('IOFACTURO_SANDBOX_PASSWORD', '')];
        return [$this->rfc((string) $data['issuer_rfc']), trim((string) $data['issuer_user']), (string) $data['issuer_password']];
    }

    private function validateCredentials(array $data): void
    {
        [$rfc, $user, $password] = $this->credentials($data, $this->sandboxMode());
        if ($rfc === '' || $user === '' || $password === '') throw new RuntimeException('La empresa emisora no tiene credenciales completas de Iofacturo.');
    }

    private function sandboxMode(): bool
    {
        $mode = strtolower(trim((string) ($this->pdo->query('SELECT facturacion FROM configuracion LIMIT 1')->fetchColumn() ?: '')));
        return in_array($mode, ['test', 'prueba', 'pruebas', 'sandbox', 'desarrollo'], true);
    }

    private function apiBase(bool $sandbox): string
    {
        return $sandbox ? 'https://sandboxtunelapi.iofacturo.mx' : 'https://tunelapi.iofacturo.mx';
    }

    private function apiError(array $response, string $fallback): string
    {
        $message = trim((string) ($response['Mensaje'] ?? $response['message'] ?? ''));
        if (str_contains($message, '|')) $message = trim((string) (explode('|', $message, 2)[1] ?? $message));
        return $message !== '' && $message[0] !== '<' ? mb_substr($message, 0, 500) : $fallback;
    }

    private function paymentDate(string $value): string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}:\d{2}))?/', trim($value), $parts)) {
            throw new RuntimeException('La fecha de pago del complemento no es válida.');
        }
        $time = ($parts[2] ?? '') !== '' && $parts[2] !== '00:00:00' ? $parts[2] : '12:00:00';
        return $parts[1] . 'T' . $time;
    }

    private function regime(string $value): string
    {
        if (!preg_match('/\b(\d{3})\b/', trim($value), $match)) throw new RuntimeException('Falta un régimen fiscal válido para emisor o receptor.');
        return $match[1];
    }

    private function postalCode(string $value): string
    {
        $value = preg_replace('/\D+/', '', $value) ?? '';
        if (!preg_match('/^\d{5}$/D', $value)) throw new RuntimeException('Falta un código postal fiscal válido para emisor o receptor.');
        return $value;
    }

    private function rfc(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    private function documentDirectory(int $id, bool $create = true): string
    {
        $root = rtrim(str_replace('\\', '/', $this->storageRoot), '/');
        $path = $root . '/' . $id;
        if ($create && !is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('No fue posible crear el directorio local para documentos fiscales.');
        }
        return $path;
    }

    private function writeFile(string $path, string $bytes): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible almacenar localmente los documentos del complemento.');
        }
    }

    private function markError(int $id, string $message): void
    {
        try {
            $query = $this->pdo->prepare('UPDATE facturas_complementos SET status = "Error", status_error = :error
                WHERE id = :id AND TRIM(COALESCE(uuid, "")) = ""');
            $query->execute(['error' => mb_substr($message, 0, 100), 'id' => $id]);
        } catch (Throwable $logException) {
            error_log('Complement stamp error persistence: ' . $logException->getMessage());
        }
    }

    private function acquireLock(string $name): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
        $query = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $query->execute(['name' => $name]);
        return (int) $query->fetchColumn() === 1;
    }

    private function releaseLock(string $name): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        try {
            $query = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $query->execute(['name' => $name]);
        } catch (Throwable) {
        }
    }

    private static function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private static function decimal(float $value): string
    {
        return number_format(round($value, 6), 6, '.', '');
    }
}
