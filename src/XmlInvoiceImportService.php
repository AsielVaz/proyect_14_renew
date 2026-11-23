<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

final class XmlInvoiceImportService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function inspect(string $xml): array
    {
        $invoice = $this->parse($xml);
        $companyQuery = $this->pdo->prepare(
            'SELECT id, razon, rfc FROM empresas WHERE UPPER(REPLACE(TRIM(rfc), " ", "")) = :rfc ORDER BY id'
        );
        $companyQuery->execute(['rfc' => $invoice['issuer']['rfc']]);

        $receiverQuery = $this->pdo->prepare('SELECT cc.id, cc.id_cliente, cc.clave_corta,
                cc.razon_social, cc.rfc, cc.cp, cc.regimen_fiscal, c.nombre AS cliente
            FROM claves_cortas cc
            INNER JOIN clientes c ON c.id = cc.id_cliente
            WHERE UPPER(REPLACE(TRIM(cc.rfc), " ", "")) = :rfc
            ORDER BY c.nombre, cc.razon_social, cc.id');
        $receiverQuery->execute(['rfc' => $invoice['receiver']['rfc']]);

        $invoice['companies'] = $companyQuery->fetchAll();
        $invoice['receivers'] = $receiverQuery->fetchAll();
        $invoice['catalogs'] = $this->resolveCatalogs($invoice);
        $invoice['already_imported'] = $this->uuidExists($invoice['stamp']['uuid']);
        return $invoice;
    }

    public function parse(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new RuntimeException('El archivo XML está vacío.');
        }
        if (strlen($xml) > 5 * 1024 * 1024) {
            throw new RuntimeException('El XML excede el límite permitido de 5 MB.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('El XML contiene declaraciones externas no permitidas.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            $detail = $errors !== [] ? trim((string) $errors[0]->message) : '';
            throw new RuntimeException('El archivo no contiene XML válido.' . ($detail !== '' ? ' ' . $detail : ''));
        }

        $xpath = new DOMXPath($document);
        $root = $document->documentElement;
        if (strcasecmp($root->localName, 'Comprobante') !== 0) {
            throw new RuntimeException('El XML no corresponde a un comprobante CFDI.');
        }
        $issuer = $this->firstElement($xpath, '/*[local-name()="Comprobante"]/*[local-name()="Emisor"]');
        $receiver = $this->firstElement($xpath, '/*[local-name()="Comprobante"]/*[local-name()="Receptor"]');
        $stamp = $this->firstElement($xpath, '/*[local-name()="Comprobante"]/*[local-name()="Complemento"]/*[local-name()="TimbreFiscalDigital"]');
        if (!$issuer || !$receiver || !$stamp) {
            throw new RuntimeException('El CFDI debe incluir emisor, receptor y Timbre Fiscal Digital.');
        }

        $issuerRfc = $this->rfc($issuer->getAttribute('Rfc'));
        $receiverRfc = $this->rfc($receiver->getAttribute('Rfc'));
        $uuid = strtoupper(trim($stamp->getAttribute('UUID')));
        if ($issuerRfc === '' || $receiverRfc === '' || $uuid === '') {
            throw new RuntimeException('El CFDI no contiene RFC de emisor, RFC de receptor o UUID.');
        }
        if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/D', $uuid)) {
            throw new RuntimeException('El UUID del Timbre Fiscal Digital no tiene un formato válido.');
        }

        $concepts = [];
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Conceptos"]/*[local-name()="Concepto"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) continue;
            $quantity = $this->number($node->getAttribute('Cantidad'));
            $net = $this->number($node->getAttribute('Importe'));
            $unit = $this->number($node->getAttribute('ValorUnitario'));
            $transferred = 0.0;
            $withheld = 0.0;
            $taxRate = 0.0;
            foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]', $node) ?: [] as $tax) {
                if (!$tax instanceof DOMElement) continue;
                $transferred += $this->number($tax->getAttribute('Importe'));
                if ($tax->getAttribute('Impuesto') === '002') {
                    $taxRate = $this->number($tax->getAttribute('TasaOCuota')) * 100;
                }
            }
            foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]', $node) ?: [] as $tax) {
                if ($tax instanceof DOMElement) $withheld += $this->number($tax->getAttribute('Importe'));
            }
            $concepts[] = [
                'product_key' => trim($node->getAttribute('ClaveProdServ')),
                'unit_key' => trim($node->getAttribute('ClaveUnidad')),
                'unit_name' => trim($node->getAttribute('Unidad')),
                'description' => trim($node->getAttribute('Descripcion')),
                'quantity' => $quantity,
                'unit_value' => $unit,
                'net' => $net,
                'transferred_tax' => round($transferred, 6),
                'withheld_tax' => round($withheld, 6),
                'tax_rate' => round($taxRate, 6),
                'gross' => round($net + $transferred, 6),
            ];
        }
        if ($concepts === []) {
            throw new RuntimeException('El CFDI no contiene conceptos para guardar.');
        }

        $issuedAt = trim($root->getAttribute('Fecha'));
        $stampedAt = trim($stamp->getAttribute('FechaTimbrado'));
        return [
            'version' => trim($root->getAttribute('Version')),
            'series' => trim($root->getAttribute('Serie')),
            'folio' => trim($root->getAttribute('Folio')),
            'issued_at' => $issuedAt,
            'subtotal' => $this->number($root->getAttribute('SubTotal')),
            'total' => $this->number($root->getAttribute('Total')),
            'currency' => strtoupper(trim($root->getAttribute('Moneda'))),
            'payment_method' => strtoupper(trim($root->getAttribute('MetodoPago'))),
            'payment_form' => str_pad(trim($root->getAttribute('FormaPago')), 2, '0', STR_PAD_LEFT),
            'voucher_type' => strtoupper(trim($root->getAttribute('TipoDeComprobante'))),
            'export_code' => trim($root->getAttribute('Exportacion')),
            'place_of_issue' => trim($root->getAttribute('LugarExpedicion')),
            'certificate_number' => trim($root->getAttribute('NoCertificado')),
            'certificate' => trim($root->getAttribute('Certificado')),
            'seal' => trim($root->getAttribute('Sello')),
            'issuer' => [
                'rfc' => $issuerRfc,
                'name' => trim($issuer->getAttribute('Nombre')),
                'tax_regime' => trim($issuer->getAttribute('RegimenFiscal')),
            ],
            'receiver' => [
                'rfc' => $receiverRfc,
                'name' => trim($receiver->getAttribute('Nombre')),
                'postal_code' => trim($receiver->getAttribute('DomicilioFiscalReceptor')),
                'tax_regime' => trim($receiver->getAttribute('RegimenFiscalReceptor')),
                'cfdi_use' => strtoupper(trim($receiver->getAttribute('UsoCFDI'))),
            ],
            'stamp' => [
                'uuid' => $uuid,
                'stamped_at' => $stampedAt,
                'sat_certificate' => trim($stamp->getAttribute('NoCertificadoSAT')),
                'provider_rfc' => trim($stamp->getAttribute('RfcProvCertif')),
                'cfd_seal' => trim($stamp->getAttribute('SelloCFD')),
                'sat_seal' => trim($stamp->getAttribute('SelloSAT')),
                'xml' => $document->saveXML($stamp) ?: '',
            ],
            'concepts' => $concepts,
            'xml' => $xml,
        ];
    }

    public function save(string $xml, int $companyId, int $receiverId, int $operatorId): int
    {
        if ($operatorId <= 0) throw new RuntimeException('La sesión autenticada no es válida.');
        $data = $this->inspect($xml);
        if ($data['already_imported']) throw new RuntimeException('Este UUID ya está registrado en facturas.');

        $company = $this->findById($data['companies'], $companyId);
        $receiver = $this->findById($data['receivers'], $receiverId);
        if (!$company) throw new RuntimeException('Selecciona una empresa emisora que coincida con el RFC del XML.');
        if (!$receiver) throw new RuntimeException('Selecciona una cartera receptora que coincida con el RFC del XML.');
        foreach (['method_id' => 'método de pago', 'form_id' => 'forma de pago', 'use_id' => 'uso de CFDI', 'currency_id' => 'moneda'] as $key => $label) {
            if ((int) ($data['catalogs'][$key] ?? 0) <= 0) {
                throw new RuntimeException('No existe en el sistema el catálogo para ' . $label . ' del XML.');
            }
        }

        $issued = $this->dateParts($data['issued_at']);
        $stamped = $this->dateParts($data['stamp']['stamped_at']);
        $folio = ctype_digit($data['folio']) ? (int) $data['folio'] : null;
        $series = mb_substr($data['series'], 0, 3);
        $withheld = array_sum(array_column($data['concepts'], 'withheld_tax'));
        $retention = $withheld > 0 ? 'Si' : 'No';
        $statusPayment = $data['payment_method'] === 'PPD' ? 'No Pagado' : 'Pagado';

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            if ($this->uuidExists($data['stamp']['uuid'], true)) {
                throw new RuntimeException('Este UUID ya está registrado en facturas.');
            }
            $insert = $this->pdo->prepare('INSERT INTO facturas
                (fecha, nombre, metodo_pago, moneda, forma_pago, status_pago, total_factura, empresa,
                 estatus_cert, certificado, razon, tipo_factura, total_sin_iva, total_iva, token,
                 tipo_comprobante, uso_cfdi, num_certificado, certificado_fact, uuid, fecha_timbrado,
                 hora_timbrado, sello_cfd, num_certificado_sat, sello_sat, complemento, factura_manual,
                 id_clave_corta, xml_firmado, status, folio, usuario_crea, serie, retencion_transporte,
                 xml_total, xml_subtotal)
                VALUES
                (:date, :client, :method, :currency, :form, :payment_status, :total_factura, :company_empresa,
                 "Timbrada", :certificate_main, :company_reason, :invoice_type, :subtotal, :total_iva, :token,
                 :voucher_type, :cfdi_use, :certificate_number, :certificate_fact, :uuid, :stamp_date,
                 :stamp_time, :cfd_seal, :sat_certificate, :sat_seal, :complement, 1,
                 :receiver, :xml, "Timbrada", :folio, :operator, :series, :retention,
                 :xml_total, :xml_subtotal)');
            $insert->execute([
                'date' => $issued['date'], 'client' => (int) $receiver['id_cliente'],
                'method' => $data['catalogs']['method_id'], 'currency' => $data['catalogs']['currency_id'],
                'form' => $data['catalogs']['form_id'], 'payment_status' => $statusPayment,
                'total_factura' => $data['total'], 'total_iva' => $data['total'],
                'company_empresa' => (int) $company['id'], 'company_reason' => (int) $company['id'],
                'certificate_main' => $data['certificate'], 'certificate_fact' => $data['certificate'],
                'invoice_type' => $data['voucher_type'], 'voucher_type' => $data['voucher_type'],
                'subtotal' => $data['subtotal'], 'token' => bin2hex(random_bytes(20)),
                'cfdi_use' => $data['catalogs']['use_id'], 'certificate_number' => $data['certificate_number'],
                'uuid' => $data['stamp']['uuid'], 'stamp_date' => $stamped['date'],
                'stamp_time' => $stamped['time'], 'cfd_seal' => $data['stamp']['cfd_seal'],
                'sat_certificate' => $data['stamp']['sat_certificate'], 'sat_seal' => $data['stamp']['sat_seal'],
                'complement' => $data['stamp']['xml'], 'receiver' => (int) $receiver['id'],
                'xml' => $xml, 'folio' => $folio, 'operator' => $operatorId, 'series' => $series,
                'retention' => $retention, 'xml_total' => $data['total'], 'xml_subtotal' => $data['subtotal'],
            ]);
            $invoiceId = (int) $this->pdo->lastInsertId();
            $detail = $this->pdo->prepare('INSERT INTO detalle_factura
                (id_factura, id_producto, cantidad, precio_unitario, precio_con_iva,
                 precio_neto, precio_neto_iva, concepto, iva)
                VALUES (:invoice, NULL, :quantity, :unit_net, :unit_gross, :net, :gross, :description, :tax)');
            foreach ($data['concepts'] as $concept) {
                $quantity = max(0.000001, (float) $concept['quantity']);
                $detail->execute([
                    'invoice' => $invoiceId, 'quantity' => $quantity,
                    'unit_net' => (float) $concept['unit_value'],
                    'unit_gross' => round((float) $concept['gross'] / $quantity, 6),
                    'net' => (float) $concept['net'], 'gross' => (float) $concept['gross'],
                    'description' => (string) $concept['description'],
                    'tax' => (int) round((float) $concept['tax_rate']),
                ]);
            }
            if ($ownsTransaction) $this->pdo->commit();
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function resolveCatalogs(array $invoice): array
    {
        return [
            'method_id' => $this->catalogId('metodos_pago', 'clave', $invoice['payment_method']),
            'form_id' => $this->catalogId('formas_pago', 'clave', ltrim($invoice['payment_form'], '0') ?: '0'),
            'use_id' => $this->catalogId('catalogo_usos_cfdi', 'cod_sat', $invoice['receiver']['cfdi_use']),
            'currency_id' => $this->catalogId('catalogo_monedas', 'clave_moneda', $invoice['currency']),
        ];
    }

    private function catalogId(string $table, string $column, string $value): int
    {
        $allowed = [
            'metodos_pago.clave', 'formas_pago.clave',
            'catalogo_usos_cfdi.cod_sat', 'catalogo_monedas.clave_moneda',
        ];
        if (!in_array($table . '.' . $column, $allowed, true)) return 0;
        $query = $this->pdo->prepare("SELECT id FROM {$table} WHERE UPPER(TRIM(CAST({$column} AS CHAR))) = UPPER(:value) LIMIT 1");
        $query->execute(['value' => $value]);
        return (int) ($query->fetchColumn() ?: 0);
    }

    private function uuidExists(string $uuid, bool $lock = false): bool
    {
        $supportsRowLocks = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $query = $this->pdo->prepare('SELECT id FROM facturas WHERE UPPER(TRIM(uuid)) = :uuid LIMIT 1'
            . ($lock && $supportsRowLocks ? ' FOR UPDATE' : ''));
        $query->execute(['uuid' => strtoupper(trim($uuid))]);
        return (bool) $query->fetchColumn();
    }

    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) if ((int) ($row['id'] ?? 0) === $id) return $row;
        return null;
    }

    private function firstElement(DOMXPath $xpath, string $expression): ?DOMElement
    {
        $node = $xpath->query($expression)?->item(0);
        return $node instanceof DOMElement ? $node : null;
    }

    private function number(string $value): float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) return 0.0;
        return (float) $value;
    }

    private function rfc(string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
    }

    private function dateParts(string $value): array
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T|\s)(\d{2}:\d{2}:\d{2})/', $value, $matches)) {
            throw new RuntimeException('El CFDI no contiene una fecha válida.');
        }
        return ['date' => $matches[1], 'time' => $matches[2]];
    }
}
