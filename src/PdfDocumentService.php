<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class PdfDocumentService
{
    private const PAGE_WIDTH = 216.0;
    private const LEFT = 14.0;
    private const CONTENT = 188.0;

    public function __construct(private readonly PDO $pdo)
    {
        if (!class_exists(\FPDF::class, true)) {
            $configuredPath = trim((string) (getenv('FPDF_PATH') ?: ''));
            $alternatePath = dirname((string) (realpath(APP_ROOT) ?: APP_ROOT))
                . '/sistema/documentos/api/FPDF/fpdf.php';
            $candidates = array_filter([
                $configuredPath,
                APP_ROOT . '/vendor/setasign/fpdf/fpdf.php',
                $alternatePath,
            ]);
            foreach ($candidates as $library) {
                if (is_file($library) && is_readable($library)) {
                    require_once $library;
                    if (class_exists(\FPDF::class, false)) {
                        break;
                    }
                }
            }
        }
        if (!class_exists(\FPDF::class, false)) {
            throw new RuntimeException(
                'No se encontró el motor PDF. Ejecuta composer install --no-dev --optimize-autoloader.'
            );
        }

        if (!class_exists(\BaconQrCode\Encoder\Encoder::class, true)) {
            $configuredQrAutoload = trim((string) (getenv('PDF_QR_AUTOLOAD') ?: ''));
            foreach (array_filter([
                $configuredQrAutoload,
                'C:/laragon/www/sistema14/optimizado/runtime/vendor/autoload.php',
            ]) as $qrAutoload) {
                if (is_file($qrAutoload) && is_readable($qrAutoload)) {
                    require_once $qrAutoload;
                    break;
                }
            }
        }
    }

    public function invoice(int $id): array
    {
        $invoice = $this->invoiceData($id);
        $details = $this->invoiceDetails($id);
        $pdf = $this->document();
        $this->coverHeader($pdf, 'FACTURA', $invoice);
        $this->fiscalIdentity($pdf, $invoice, 'CFDI de ingreso');
        $this->parties($pdf, $invoice);
        $this->invoiceMetaBar($pdf, $invoice);
        $this->invoiceTable($pdf, $details);
        $this->invoiceTotals($pdf, $invoice, $details);
        $this->compactFiscalBlock($pdf, $invoice, 'Factura');
        $name = $this->filename('factura-' . $this->folio($invoice) . '.pdf');
        return ['bytes' => $pdf->Output('S'), 'filename' => $name];
    }

    public function complement(int $id): array
    {
        $complement = $this->complementData($id);
        $relations = $this->complementRelations($id);
        $pdf = $this->document();
        $this->coverHeader($pdf, 'COMPLEMENTO DE PAGO', $complement);
        $this->fiscalIdentity($pdf, $complement, 'CFDI de recepción de pagos');
        $this->parties($pdf, $complement);
        $this->paymentSummary($pdf, $complement, $relations);
        $this->relationsTable($pdf, $relations);
        $this->compactFiscalBlock($pdf, $complement, 'Complemento de pago');
        $name = $this->filename('complemento-' . $this->folio($complement) . '.pdf');
        return ['bytes' => $pdf->Output('S'), 'filename' => $name];
    }

    public function quotation(array $data): array
    {
        $concepts = is_array($data['concepts'] ?? null) ? $data['concepts'] : [];
        if ($concepts === []) {
            throw new RuntimeException('Agrega al menos un concepto para generar el PDF de cotización.');
        }
        $company = $this->quotationCompany(
            (string) ($data['from_email'] ?? ''),
            (string) ($data['from_name'] ?? 'Empresa emisora')
        );
        $reference = 'COT-' . strtoupper(substr(hash('sha256', json_encode([
            $data['from_email'] ?? '',
            $data['recipients'] ?? [],
            $data['subject'] ?? '',
            $concepts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 10));
        $pdf = $this->document('Cotización comercial - Sistema 14');
        $pdf->setFooterLabel('Cotización comercial · Documento informativo no fiscal');
        $this->quotationHeader($pdf, $company, $reference);
        $this->quotationParties($pdf, $company, $data);
        $this->quotationMetaBar($pdf, $data);
        $this->quotationTable($pdf, $concepts);
        $this->quotationTotals($pdf, $data);
        $this->quotationNotes($pdf, (string) ($data['notes'] ?? ''));
        return [
            'bytes' => $pdf->Output('S'),
            'filename' => $this->filename(strtolower($reference) . '.pdf'),
            'reference' => $reference,
        ];
    }

    private function document(string $title = 'Documento fiscal - Sistema 14'): FiscalPdf
    {
        $pdf = new FiscalPdf('P', 'mm', 'Letter');
        $pdf->SetMargins(self::LEFT, 12, self::LEFT);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AliasNbPages();
        $pdf->SetTitle($pdf->encode($title));
        $pdf->SetAuthor($pdf->encode('Sistema 14'));
        $pdf->AddPage();
        return $pdf;
    }

    private function quotationHeader(FiscalPdf $pdf, array $company, string $reference): void
    {
        $logo = $this->logoPath($company['logo'] ?? null);
        if ($logo !== null) {
            try {
                [$width, $height] = getimagesize($logo['path']) ?: [0, 0];
                if ($width > 0 && $height > 0) {
                    $ratio = min(68.0 / $width, 38.0 / $height);
                    $pdf->Image($logo['path'], 10, 3, $width * $ratio, $height * $ratio);
                }
            } finally {
                if ($logo['temporary']) @unlink($logo['path']);
            }
        } else {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(...FiscalPdf::NAVY);
            $pdf->SetXY(15, 13);
            $pdf->Cell(55, 10, 'SISTEMA 14', 0, 0, 'L');
        }
        $pdf->SetTextColor(...FiscalPdf::NAVY);
        $companyName = (string) ($company['empresa'] ?? 'EMPRESA EMISORA');
        $pdf->SetFont('Arial', 'B', mb_strlen($companyName) > 28 ? 11 : 14);
        $pdf->SetXY(78, 7);
        $pdf->Cell(124, 7, $pdf->encode($companyName), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY(78, 15);
        $pdf->Cell(124, 4, $pdf->encode('RFC ' . (($company['empresa_rfc'] ?? '') ?: 'No especificado')), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY(78, 20);
        $pdf->Cell(124, 4, $pdf->encode($this->truncate($pdf, (string) (($company['empresa_regimen'] ?? '') ?: 'Datos fiscales no requeridos'), 124)), 0, 0, 'R');

        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->Rect(132, 28, 70, 15, 'F');
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(135, 30);
        $pdf->Cell(64, 5, $pdf->encode('COTIZACIÓN'), 0, 0, 'C');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(205, 213, 226);
        $pdf->SetXY(135, 36);
        $pdf->Cell(64, 4, $reference, 0, 0, 'C');

        $pdf->SetFillColor(...FiscalPdf::ORANGE);
        $pdf->Rect(0, 48, self::PAGE_WIDTH, 7, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->SetXY(self::LEFT, 49.5);
        $pdf->Cell(self::CONTENT, 4, $pdf->encode('PROPUESTA COMERCIAL · DOCUMENTO NO FISCAL'), 0, 0, 'L');
        $pdf->SetY(59);
    }

    private function quotationParties(FiscalPdf $pdf, array $company, array $data): void
    {
        $gap = 6.0;
        $width = (self::CONTENT - $gap) / 2;
        $this->partyCard($pdf, self::LEFT, 59, $width, 'EMISOR', [
            (string) ($company['empresa'] ?? 'Empresa emisora'),
            (string) ($data['from_email'] ?? ''),
            'RFC  ' . (($company['empresa_rfc'] ?? '') ?: 'No especificado'),
        ]);
        $recipients = is_array($data['recipients'] ?? null) ? $data['recipients'] : [];
        $this->partyCard($pdf, self::LEFT + $width + $gap, 59, $width, 'DESTINATARIO', [
            $recipients !== [] ? implode(', ', array_map('strval', $recipients)) : 'No especificado',
            (string) (($data['subject'] ?? '') ?: 'Cotización comercial'),
            'Datos fiscales pendientes de confirmación',
        ]);
        $pdf->SetY(94);
    }

    private function quotationMetaBar(FiscalPdf $pdf, array $data): void
    {
        $items = [
            ['FECHA', date('d/m/Y')],
            ['MONEDA', (string) (($data['currency'] ?? '') ?: 'MXN')],
            ['DOCUMENTO', 'Cotización'],
            ['VALIDEZ', 'No especificada'],
        ];
        $widths = [44, 38, 48, 58];
        $x = self::LEFT;
        foreach ($items as $index => [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 5.5);
            $pdf->SetTextColor(...FiscalPdf::MUTED);
            $pdf->SetXY($x + 1, 96);
            $pdf->Cell($widths[$index] - 2, 3, $pdf->encode($label), 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor(...FiscalPdf::INK);
            $pdf->SetXY($x + 1, 100);
            $pdf->Cell($widths[$index] - 2, 4, $pdf->encode($value), 0, 0, 'L');
            $x += $widths[$index];
        }
        $pdf->SetY(108);
    }

    private function quotationTable(FiscalPdf $pdf, array $concepts): void
    {
        $widths = [18, 82, 30, 26, 32];
        $headers = ['Cant.', 'Descripción', 'P. unitario', 'IVA', 'Total'];
        $this->tableHeader($pdf, $headers, $widths);
        foreach ($concepts as $concept) {
            $description = $this->singleLine((string) ($concept['description'] ?? 'Concepto'));
            $lines = max(1, (int) ceil($pdf->GetStringWidth($pdf->encode($description)) / ($widths[1] - 5)));
            $height = max(9.0, min(15.0, $lines * 3.0 + 3));
            if ($pdf->GetY() + $height > 205) {
                $pdf->AddPage();
                $this->continuationHeader($pdf, 'COTIZACIÓN', 'Conceptos - continuación');
                $this->tableHeader($pdf, $headers, $widths);
            }
            $this->tableRow($pdf, [
                (string) ($concept['quantity'] ?? '1'),
                $description,
                $this->money((float) ($concept['unit_price'] ?? 0)),
                $this->money((float) ($concept['tax_amount'] ?? 0)),
                $this->money((float) ($concept['total'] ?? 0)),
            ], $widths, $height, 2.7);
        }
    }

    private function quotationTotals(FiscalPdf $pdf, array $data): void
    {
        if ($pdf->GetY() > 215) {
            $pdf->AddPage();
            $this->continuationHeader($pdf, 'COTIZACIÓN', 'Resumen');
        }
        $subtotal = (float) ($data['subtotal'] ?? 0);
        $tax = (float) ($data['tax'] ?? 0);
        $total = (float) ($data['total'] ?? 0);
        $y = $pdf->GetY() + 5;
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->Line(self::LEFT, $y, 202, $y);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY(self::LEFT + 1, $y + 5);
        $pdf->Cell(92, 4, 'TOTAL ESTIMADO CON LETRA', 0, 0, 'L');
        $pdf->SetFont('Arial', 'BI', 7.5);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY(self::LEFT + 1, $y + 10);
        $pdf->MultiCell(92, 4, $pdf->encode($this->amountWords($total, (string) ($data['currency'] ?? 'MXN'))), 0, 'L');
        $this->totalsCard($pdf, 126, $y + 4, 76, [
            ['Subtotal', $subtotal],
            ['IVA', $tax],
            ['TOTAL', $total],
        ]);
        $pdf->SetY($y + 44);
    }

    private function quotationNotes(FiscalPdf $pdf, string $notes): void
    {
        $notes = trim(preg_replace('/\s+/u', ' ', $notes) ?? '');
        if ($notes === '') return;
        if ($pdf->GetY() > 235) {
            $pdf->AddPage();
            $this->continuationHeader($pdf, 'COTIZACIÓN', 'Notas');
        }
        $this->sectionLabel($pdf, 'Notas de la cotización');
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->roundRect(self::LEFT, $pdf->GetY(), self::CONTENT, 24, 2.5, 'FD');
        $pdf->SetXY(self::LEFT + 4, $pdf->GetY() + 4);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->MultiCell(self::CONTENT - 8, 3.5, $pdf->encode($this->limit($notes, 1000)), 0, 'L');
    }

    private function coverHeader(FiscalPdf $pdf, string $type, array $row): void
    {
        $logo = $this->logoPath($row['logo'] ?? null);
        if ($logo !== null) {
            $logoPath = $logo['path'];
            try {
                [$width, $height] = getimagesize($logoPath) ?: [0, 0];
                if ($width > 0 && $height > 0) {
                    // El encabezado reserva toda la columna izquierda para el logo.
                    // Algunos logotipos incluyen margen blanco interno, por eso
                    // se escala hasta el límite seguro sin invadir los datos del emisor.
                    $maxWidth = 68.0;
                    $maxHeight = 42.0;
                    $ratio = min($maxWidth / $width, $maxHeight / $height);
                    $drawWidth = $width * $ratio;
                    $drawHeight = $height * $ratio;
                    $pdf->Image($logoPath, 8, 1 + ($maxHeight - $drawHeight) / 2, $drawWidth, $drawHeight);
                }
            } finally {
                if ($logo['temporary']) {
                    @unlink($logoPath);
                }
            }
        } else {
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->SetTextColor(...FiscalPdf::NAVY);
            $pdf->SetXY(15, 13);
            $pdf->Cell(45, 10, 'SISTEMA 14', 0, 0, 'L');
        }

        $pdf->SetTextColor(...FiscalPdf::NAVY);
        $companyName = (string) ($row['empresa'] ?? 'EMISOR');
        $pdf->SetFont('Arial', 'B', mb_strlen($companyName) > 24 ? 12 : 14);
        $pdf->SetXY(83, 7);
        $pdf->Cell(119, 7, $pdf->encode($companyName), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY(83, 13);
        $pdf->Cell(119, 4, $pdf->encode((string) ($row['empresa_rfc'] ?? '-')), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY(83, 18);
        $pdf->Cell(119, 4, $pdf->encode($this->truncate($pdf, (string) ($row['empresa_regimen'] ?? '-'), 119)), 0, 0, 'R');

        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->Rect(139, 25, 63, 16, 'F');
        $pdf->SetFont('Arial', 'B', 10.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(142, 27);
        $pdf->Cell(57, 6, $pdf->encode($type . '  ' . $this->folio($row)), 0, 0, 'C');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(205, 213, 226);
        $pdf->SetXY(142, 34);
        $pdf->Cell(57, 4, 'CFDI v4.0  |  ' . $this->status($row), 0, 0, 'C');
    }

    private function fiscalIdentity(FiscalPdf $pdf, array $row, string $subtitle): void
    {
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->Rect(0, 45, self::PAGE_WIDTH, 10, 'F');
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $pdf->SetTextColor(161, 175, 197);
        $pdf->SetFont('Arial', '', 5.5);
        $pdf->SetXY(14, 46);
        $pdf->Cell(96, 3, 'FOLIO FISCAL', 0, 0, 'L');
        $pdf->SetXY(112, 46);
        $pdf->Cell(90, 3, $pdf->encode('CERT. SAT: ' . (($row['num_certificado_sat'] ?? '') ?: '-')), 0, 0, 'R');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 6.2);
        $pdf->SetXY(14, 49.5);
        $pdf->Cell(96, 3, $pdf->encode($uuid !== '' ? strtoupper($uuid) : 'PENDIENTE DE TIMBRADO'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 5.8);
        $pdf->SetXY(112, 49.5);
        $pdf->Cell(90, 3, $pdf->encode($this->stampDate($row)), 0, 0, 'R');
        $pdf->SetY(58);
    }

    private function parties(FiscalPdf $pdf, array $row): void
    {
        $top = 58.0;
        $gap = 6.0;
        $width = (self::CONTENT - $gap) / 2;
        $this->partyCard($pdf, self::LEFT, $top, $width, 'EMISOR', [
            (string) ($row['empresa'] ?? 'Emisor no registrado'),
            'RFC  ' . ($row['empresa_rfc'] ?? '-'),
            'Régimen  ' . ($row['empresa_regimen'] ?? '-'),
        ]);
        $receiverAddress = $this->singleLine((string) ($row['receptor_domicilio'] ?? ''));
        $this->partyCard($pdf, self::LEFT + $width + $gap, $top, $width, 'RECEPTOR', [
            (string) ($row['receptor'] ?? 'Receptor no registrado'),
            'RFC  ' . ($row['receptor_rfc'] ?? '-'),
            'CP  ' . ($row['receptor_cp'] ?? '-') . '  |  Régimen ' . ($row['receptor_regimen'] ?? '-'),
            $receiverAddress,
        ]);
        $pdf->SetY(93);
    }

    private function partyCard(FiscalPdf $pdf, float $x, float $y, float $width, string $title, array $lines): void
    {
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $width, 31, 'FD');
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(...FiscalPdf::ORANGE);
        $pdf->SetXY($x + 5, $y + 4);
        $pdf->Cell($width - 10, 4, $title, 0, 0, 'L');
        $pdf->SetTextColor(...FiscalPdf::INK);
        $currentY = $y + 9;
        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            $pdf->SetFont('Arial', $index === 0 ? 'B' : '', $index === 0 ? 9 : 7.4);
            $pdf->SetXY($x + 5, $currentY);
            $pdf->Cell($width - 10, 4, $pdf->encode($this->truncate($pdf, $line, $width - 10)), 0, 0, 'L');
            $currentY += $index === 0 ? 5 : 4.2;
            if ($currentY > $y + 28) break;
        }
    }

    private function invoiceMetaBar(FiscalPdf $pdf, array $invoice): void
    {
        $items = [
            ['FORMA DE PAGO', $this->satCode($invoice['forma_clave'] ?? null)],
            ['MÉTODO DE PAGO', (string) (($invoice['metodo_clave'] ?? '') ?: '-')],
            ['MONEDA', (string) (($invoice['moneda_clave'] ?? '') ?: 'MXN')],
            ['RÉGIMEN FISCAL', (string) (($invoice['empresa_regimen'] ?? '') ?: '-')],
        ];
        $widths = [48, 48, 38, 54];
        $x = self::LEFT;
        foreach ($items as $index => [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 5.5);
            $pdf->SetTextColor(...FiscalPdf::MUTED);
            $pdf->SetXY($x + 1, 96);
            $pdf->Cell($widths[$index] - 2, 3, $pdf->encode($label), 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor(...FiscalPdf::INK);
            $pdf->SetXY($x + 1, 100);
            $pdf->Cell($widths[$index] - 2, 4, $pdf->encode($this->truncate($pdf, $value, $widths[$index] - 2)), 0, 0, 'L');
            $x += $widths[$index];
        }
        $pdf->SetY(107);
    }

    private function invoiceTable(FiscalPdf $pdf, array $details): void
    {
        $widths = [14, 23, 22, 78, 24, 27];
        $headers = ['Cant.', 'Unidad', 'Clave SAT', 'Descripción', 'P. unitario', 'Importe'];
        $this->tableHeader($pdf, $headers, $widths);
        foreach ($details as $detail) {
            $description = $this->singleLine((string) ($detail['concepto'] ?? 'Concepto'));
            $lines = max(1, (int) ceil($pdf->GetStringWidth($pdf->encode($description)) / ($widths[3] - 5)));
            $height = max(8.0, min(12.0, $lines * 3.0 + 3));
            if ($pdf->GetY() + $height > 190) {
                $pdf->AddPage();
                $this->continuationHeader($pdf, 'FACTURA', 'Conceptos - continuación');
                $this->tableHeader($pdf, $headers, $widths);
            }
            $values = [
                $this->quantity((float) ($detail['cantidad'] ?? 0)),
                (string) ($detail['clave_unidad_medida'] ?? '-'),
                (string) (($detail['clave_sat'] ?? '') ?: ($detail['clave_producto'] ?? '-')),
                $description,
                $this->money((float) ($detail['precio_unitario'] ?? 0)),
                $this->money((float) ($detail['precio_neto_iva'] ?? $detail['precio_con_iva'] ?? 0)),
            ];
            $this->tableRow($pdf, $values, $widths, $height, 2.7);
        }
        if ($details === []) {
            $this->emptyRow($pdf, 'La factura no contiene conceptos registrados.');
        }
    }

    private function invoiceTotals(FiscalPdf $pdf, array $invoice, array $details): void
    {
        $subtotal = (float) ($invoice['xml_subtotal'] ?? 0);
        if ($subtotal <= 0) {
            $subtotal = array_sum(array_map(static fn (array $item): float => (float) ($item['precio_neto'] ?? 0), $details));
        }
        $hasRetention = strcasecmp(trim((string) ($invoice['retencion_transporte'] ?? 'No')), 'Si') === 0;
        $transferredTax = array_sum(array_map(static fn (array $item): float => max(
            0,
            (float) ($item['precio_neto_iva'] ?? 0) - (float) ($item['precio_neto'] ?? 0)
        ), $details));
        $retainedTax = $hasRetention ? array_sum(array_map(static fn (array $item): float =>
            (int) ($item['iva'] ?? 0) > 0 ? round((float) ($item['precio_neto'] ?? 0) * 0.04, 6) : 0.0,
            $details
        )) : 0.0;
        $retainedTax = round($retainedTax, 2);
        $total = $hasRetention && (float) ($invoice['total_iva'] ?? 0) > 0
            ? (float) $invoice['total_iva']
            : (float) ($invoice['xml_total'] ?? $invoice['total_iva'] ?? 0);
        if ($pdf->GetY() > 205) {
            $pdf->AddPage();
            $this->continuationHeader($pdf, 'FACTURA', 'Resumen del comprobante');
        }
        $y = $pdf->GetY() + 5;
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->Line(self::LEFT, $y, 202, $y);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY(self::LEFT + 1, $y + 5);
        $pdf->Cell(92, 4, 'TOTAL CON LETRA', 0, 0, 'L');
        $pdf->SetFont('Arial', 'BI', 7.5);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY(self::LEFT + 1, $y + 10);
        $pdf->MultiCell(92, 4, $pdf->encode($this->amountWords($total, (string) ($invoice['moneda_clave'] ?? 'MXN'))), 0, 'L');
        $this->totalsCard($pdf, 126, $y + 4, 76, [
            ['Subtotal', $subtotal],
            ['IVA trasladado', $transferredTax],
            ...($hasRetention ? [['Retención 4%', -$retainedTax]] : []),
            ['TOTAL', $total],
        ]);
        $pdf->SetY($y + 43);
    }

    private function paymentSummary(FiscalPdf $pdf, array $row, array $relations): void
    {
        $this->sectionLabel($pdf, 'Información del pago');
        $total = array_sum(array_map(static fn (array $relation): float => (float) ($relation['monto'] ?? 0), $relations));
        if ($total <= 0) $total = (float) ($row['total_factura'] ?? 0);
        $y = $pdf->GetY() + 2;
        $this->infoStrip($pdf, self::LEFT, $y, 116, [
            ['Fecha del pago', $this->date((string) ($row['fecha_pago'] ?? ''))],
            ['Forma de pago', $this->satCode($row['forma_clave'] ?? null) . ' - ' . trim((string) ($row['forma_pago'] ?? ''))],
            ['Moneda', 'MXN'],
            ['Número de operación', (string) (($row['num_operacion'] ?? '') ?: '-')],
            ['Cuenta beneficiaria', (string) (($row['cta_beneficiario'] ?? '') ?: '-')],
        ]);
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->roundRect(136, $y, 66, 39, 3, 'FD');
        $pdf->SetTextColor(196, 205, 220);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY(142, $y + 8);
        $pdf->Cell(54, 4, 'MONTO RECIBIDO', 0, 0, 'R');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetXY(142, $y + 16);
        $pdf->Cell(54, 9, $this->money($total), 0, 0, 'R');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(196, 205, 220);
        $pdf->SetXY(142, $y + 28);
        $pdf->Cell(54, 4, 'MXN', 0, 0, 'R');
        $pdf->SetY($y + 47);
    }

    private function relationsTable(FiscalPdf $pdf, array $relations): void
    {
        $this->sectionLabel($pdf, 'Documentos relacionados');
        $widths = [58, 25, 20, 29, 28, 28];
        $headers = ['UUID relacionado', 'Serie / folio', 'Parcialidad', 'Saldo anterior', 'Pago', 'Saldo insoluto'];
        $this->tableHeader($pdf, $headers, $widths);
        foreach ($relations as $relation) {
            if ($pdf->GetY() + 11 > 235) {
                $pdf->AddPage();
                $this->continuationHeader($pdf, 'COMPLEMENTO DE PAGO', 'Documentos - continuación');
                $this->tableHeader($pdf, $headers, $widths);
            }
            $this->tableRow($pdf, [
                strtoupper((string) ($relation['uuid_rel'] ?? '-')),
                trim((string) ($relation['serie'] ?? '') . ' ' . (string) ($relation['folio'] ?? '')),
                (string) ($relation['num_pago'] ?? '-'),
                $this->money((float) ($relation['saldo'] ?? 0)),
                $this->money((float) ($relation['monto'] ?? 0)),
                $this->money((float) ($relation['restante'] ?? 0)),
            ], $widths, 11, 2.3);
        }
        if ($relations === []) {
            $this->emptyRow($pdf, 'El complemento no contiene documentos relacionados.');
        }
    }

    private function compactFiscalBlock(FiscalPdf $pdf, array $row, string $type): void
    {
        if ($pdf->GetY() + 54 > 265) {
            $pdf->AddPage();
            $this->continuationHeader($pdf, mb_strtoupper($type), 'Certificación fiscal');
        }
        $top = $pdf->GetY() + 2;
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->Line(self::LEFT, $top, 202, $top);
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $qrPayload = $uuid !== '' ? $this->satVerificationUrl($row) : '';
        $this->drawQr($pdf, self::LEFT + 1, $top + 5, 25, $qrPayload);

        $textX = 45.0;
        $textWidth = 157.0;
        $pdf->SetFont('Arial', 'B', 5.8);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY($textX, $top + 4);
        $pdf->Cell($textWidth, 3, 'Sello Digital del CFDI:', 0, 0, 'L');
        $pdf->SetFont('Courier', '', 4.3);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY($textX, $top + 8);
        $pdf->MultiCell($textWidth, 2.1, $pdf->encode($this->technicalWrap((string) (($row['sello_cfd'] ?? '') ?: 'Pendiente de timbrado'), 350)), 0, 'L');

        $pdf->SetFont('Arial', 'B', 5.8);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY($textX, $top + 17);
        $pdf->Cell($textWidth, 3, 'Sello del SAT:', 0, 0, 'L');
        $pdf->SetFont('Courier', '', 4.3);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY($textX, $top + 21);
        $pdf->MultiCell($textWidth, 2.1, $pdf->encode($this->technicalWrap((string) (($row['sello_sat'] ?? '') ?: 'Pendiente de timbrado'), 350)), 0, 'L');

        $pdf->SetFont('Arial', 'B', 5.8);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY(self::LEFT + 1, $top + 32);
        $pdf->Cell(self::CONTENT - 2, 3, $pdf->encode('Cadena original del complemento de certificación digital del SAT:'), 0, 0, 'L');
        $technical = (string) (($row['cadena'] ?? '') ?: ($row['certificado_fact'] ?? 'Información disponible después del timbrado.'));
        $pdf->SetFont('Courier', '', 4.2);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY(self::LEFT + 1, $top + 36);
        $pdf->MultiCell(self::CONTENT - 2, 2, $pdf->encode($this->technicalWrap($technical, 520)), 0, 'L');

        $pdf->SetFont('Arial', 'B', 5.5);
        $pdf->SetTextColor(...FiscalPdf::NAVY);
        $pdf->SetXY(self::LEFT + 1, $top + 45);
        $pdf->Cell(45, 3, $pdf->encode('No. Certificado SAT:'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 5.5);
        $pdf->Cell(49, 3, $pdf->encode((string) (($row['num_certificado_sat'] ?? '') ?: '-')), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 5.5);
        $pdf->Cell(51, 3, $pdf->encode('Fecha y hora de certificación:'), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 5.5);
        $pdf->Cell(41, 3, $pdf->encode($this->stampDate($row)), 0, 0, 'R');

        $pdf->SetFillColor(24, 113, 67);
        $pdf->Rect(self::LEFT, $top + 50, 62, 5, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 5.5);
        $pdf->SetXY(self::LEFT, $top + 50.5);
        $pdf->Cell(62, 3.5, $uuid !== '' ? 'Generada a partir de XML oficial timbrado' : 'Vista previa - pendiente de timbrado', 0, 0, 'C');
        $pdf->SetY($top + 57);
    }

    private function drawQr(FiscalPdf $pdf, float $x, float $y, float $size, string $payload): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $size, $size, 'F');
        if ($payload === '' || !class_exists(\BaconQrCode\Encoder\Encoder::class)) {
            $pdf->SetDrawColor(...FiscalPdf::BORDER);
            $pdf->Rect($x, $y, $size, $size, 'D');
            $pdf->SetTextColor(...FiscalPdf::MUTED);
            $pdf->SetFont('Arial', 'B', 5.5);
            $pdf->SetXY($x, $y + 10);
            $pdf->Cell($size, 4, 'QR SAT', 0, 0, 'C');
            return;
        }
        try {
            $qr = \BaconQrCode\Encoder\Encoder::encode(
                $payload,
                \BaconQrCode\Common\ErrorCorrectionLevel::M(),
                'UTF-8'
            );
            $matrix = $qr->getMatrix();
            $quiet = 2;
            $modules = $matrix->getWidth() + $quiet * 2;
            $module = $size / $modules;
            $pdf->SetFillColor(0, 0, 0);
            for ($row = 0; $row < $matrix->getHeight(); $row++) {
                for ($column = 0; $column < $matrix->getWidth(); $column++) {
                    if ($matrix->get($column, $row) === 1) {
                        $pdf->Rect($x + ($column + $quiet) * $module, $y + ($row + $quiet) * $module, $module + 0.02, $module + 0.02, 'F');
                    }
                }
            }
        } catch (\Throwable) {
            $pdf->SetDrawColor(...FiscalPdf::BORDER);
            $pdf->Rect($x, $y, $size, $size, 'D');
        }
    }

    private function satVerificationUrl(array $row): string
    {
        $seal = trim((string) ($row['sello_cfd'] ?? ''));
        $hasRetention = strcasecmp(trim((string) ($row['retencion_transporte'] ?? 'No')), 'Si') === 0;
        $total = $hasRetention && (float) ($row['total_iva'] ?? 0) > 0
            ? (float) $row['total_iva']
            : (float) ($row['xml_total'] ?? $row['total_iva'] ?? $row['total_factura'] ?? 0);
        return 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?' . http_build_query([
            'id' => strtoupper(trim((string) ($row['uuid'] ?? ''))),
            're' => trim((string) ($row['empresa_rfc'] ?? '')),
            'rr' => trim((string) ($row['receptor_rfc'] ?? '')),
            'tt' => number_format($total, 6, '.', ''),
            'fe' => substr($seal, -8),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function technicalWrap(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/u', '', trim($value)) ?? '';
        $value = mb_substr($value, 0, $limit);
        return trim(chunk_split($value, 92, ' '));
    }

    private function fiscalPage(FiscalPdf $pdf, array $row, string $type): void
    {
        $pdf->AddPage();
        $this->continuationHeader($pdf, strtoupper($type), 'Información fiscal y certificación');
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $this->sectionLabel($pdf, 'Identificación del comprobante');
        $this->twoColumnDetails($pdf, [
            ['UUID', $uuid !== '' ? strtoupper($uuid) : 'Pendiente de timbrado'],
            ['Certificado del emisor', (string) (($row['num_certificado'] ?? '') ?: '-')],
            ['Certificado SAT', (string) (($row['num_certificado_sat'] ?? '') ?: '-')],
            ['Fecha de certificación', $this->stampDate($row)],
        ]);
        $this->sectionLabel($pdf, 'Sello digital del CFDI');
        $this->technicalBox($pdf, (string) (($row['sello_cfd'] ?? '') ?: 'El documento todavía no cuenta con sello digital.'));
        $this->sectionLabel($pdf, 'Sello digital del SAT');
        $this->technicalBox($pdf, (string) (($row['sello_sat'] ?? '') ?: 'El documento todavía no cuenta con sello SAT.'));
        $this->sectionLabel($pdf, 'Cadena original / certificado');
        $technical = trim((string) (($row['cadena'] ?? '') ?: ($row['certificado_fact'] ?? '')));
        $this->technicalBox($pdf, $technical !== '' ? $technical : 'Información disponible después del timbrado.', 33);
        $pdf->SetFont('Arial', 'I', 7.5);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->MultiCell(self::CONTENT, 4, $pdf->encode('Este documento fue generado localmente por Sistema 14 a partir de la información fiscal almacenada. No sustituye al archivo XML firmado.'), 0, 'L');
    }

    private function continuationHeader(FiscalPdf $pdf, string $type, string $subtitle): void
    {
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->Rect(0, 0, self::PAGE_WIDTH, 28, 'F');
        $pdf->SetFillColor(...FiscalPdf::ORANGE);
        $pdf->Rect(0, 0, 5, 28, 'F');
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(self::LEFT, 8);
        $pdf->Cell(80, 6, $pdf->encode($type), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(196, 205, 220);
        $pdf->Cell(108, 6, $pdf->encode($subtitle), 0, 0, 'R');
        $pdf->SetY(36);
    }

    private function sectionLabel(FiscalPdf $pdf, string $label): void
    {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(...FiscalPdf::ORANGE);
        $pdf->Cell(self::CONTENT, 6, $pdf->encode(mb_strtoupper($label)), 0, 1, 'L');
    }

    private function labelValue(FiscalPdf $pdf, float $x, float $y, float $width, string $label, string $value): void
    {
        $pdf->SetFont('Arial', 'B', 6.5);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 4, $pdf->encode(mb_strtoupper($label)), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetTextColor(...FiscalPdf::INK);
        $pdf->SetXY($x, $y + 6);
        $pdf->Cell($width, 5, $pdf->encode($this->truncate($pdf, $value, $width)), 0, 0, 'L');
    }

    private function tableHeader(FiscalPdf $pdf, array $headers, array $widths): void
    {
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 6.5);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 9, $pdf->encode($header), 0, 0, $index >= count($headers) - 2 ? 'R' : 'L', true);
        }
        $pdf->Ln();
    }

    private function tableRow(FiscalPdf $pdf, array $values, array $widths, float $height, float $lineHeight): void
    {
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->Rect($x, $y, array_sum($widths), $height, 'FD');
        foreach ($values as $index => $value) {
            $width = $widths[$index];
            $pdf->SetXY($x + 2, $y + 2);
            $pdf->SetTextColor(...FiscalPdf::INK);
            $pdf->SetFont('Arial', $index === count($values) - 1 ? 'B' : '', 6.7);
            if ($index > 0 && $index < count($values) - 2 && $width >= 70) {
                $pdf->MultiCell($width - 4, $lineHeight, $pdf->encode((string) $value), 0, 'L');
            } else {
                $align = $index >= count($values) - 2 ? 'R' : 'L';
                $pdf->Cell($width - 4, $height - 4, $pdf->encode($this->truncate($pdf, (string) $value, $width - 4)), 0, 0, $align);
            }
            $x += $width;
        }
        $pdf->SetXY(self::LEFT, $y + $height + 1);
    }

    private function emptyRow(FiscalPdf $pdf, string $message): void
    {
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(self::CONTENT, 12, $pdf->encode($message), 0, 1, 'C', true);
    }

    private function infoStrip(FiscalPdf $pdf, float $x, float $y, float $width, array $items): void
    {
        $height = max(34, count($items) * 7 + 5);
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->roundRect($x, $y, $width, $height, 3, 'FD');
        $currentY = $y + 5;
        foreach ($items as [$label, $value]) {
            $pdf->SetFont('Arial', 'B', 6.5);
            $pdf->SetTextColor(...FiscalPdf::MUTED);
            $pdf->SetXY($x + 5, $currentY);
            $pdf->Cell(30, 4, $pdf->encode(mb_strtoupper($label)), 0, 0, 'L');
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(...FiscalPdf::INK);
            $pdf->SetXY($x + 36, $currentY);
            $pdf->Cell($width - 41, 4, $pdf->encode($this->truncate($pdf, (string) $value, $width - 41)), 0, 0, 'L');
            $currentY += 7;
        }
    }

    private function totalsCard(FiscalPdf $pdf, float $x, float $y, float $width, array $items): void
    {
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->roundRect($x, $y, $width, 36, 3, 'F');
        $currentY = $y + 6;
        foreach ($items as $index => [$label, $value]) {
            $last = $index === count($items) - 1;
            $pdf->SetFont('Arial', $last ? 'B' : '', $last ? 9 : 7);
            $pdf->SetTextColor($last ? 255 : 196, $last ? 255 : 205, $last ? 255 : 220);
            $pdf->SetXY($x + 6, $currentY);
            $pdf->Cell(30, 5, $pdf->encode($label), 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', $last ? 11 : 8);
            $pdf->Cell($width - 42, 5, $this->money((float) $value), 0, 0, 'R');
            $currentY += $last ? 10 : 8;
        }
    }

    private function twoColumnDetails(FiscalPdf $pdf, array $items): void
    {
        $gap = 6.0;
        $width = (self::CONTENT - $gap) / 2;
        $startY = $pdf->GetY();
        foreach ($items as $index => [$label, $value]) {
            $column = $index % 2;
            $row = intdiv($index, 2);
            $x = self::LEFT + $column * ($width + $gap);
            $y = $startY + $row * 22;
            $pdf->SetFillColor(...FiscalPdf::SOFT);
            $pdf->SetDrawColor(...FiscalPdf::BORDER);
            $pdf->roundRect($x, $y, $width, 17, 2.5, 'FD');
            $this->labelValue($pdf, $x + 4, $y + 3, $width - 8, $label, (string) $value);
        }
        $pdf->SetY($startY + 44);
    }

    private function technicalBox(FiscalPdf $pdf, string $text, float $height = 27): void
    {
        $x = self::LEFT;
        $y = $pdf->GetY();
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetDrawColor(...FiscalPdf::BORDER);
        $pdf->roundRect($x, $y, self::CONTENT, $height, 2.5, 'FD');
        $pdf->SetXY($x + 4, $y + 4);
        $pdf->SetFont('Courier', '', 6.2);
        $pdf->SetTextColor(...FiscalPdf::MUTED);
        $maximum = $height >= 33 ? 900 : 660;
        $pdf->MultiCell(self::CONTENT - 8, 3.1, $pdf->encode($this->limit($text, $maximum)), 0, 'L');
        $pdf->SetY($y + $height + 5);
    }

    private function quotationCompany(string $email, string $fallbackName): array
    {
        $query = $this->pdo->prepare("SELECT razon AS empresa, rfc AS empresa_rfc,
                regimen AS empresa_regimen, logo
            FROM empresas
            WHERE LOWER(TRIM(correo_conta)) = LOWER(TRIM(:accounting))
               OR LOWER(TRIM(correo_pv)) = LOWER(TRIM(:service))
               OR LOWER(TRIM(correo_ventas)) = LOWER(TRIM(:sales))
               OR LOWER(TRIM(correo_contacto)) = LOWER(TRIM(:contact))
            ORDER BY id
            LIMIT 1");
        $query->execute([
            'accounting' => $email,
            'service' => $email,
            'sales' => $email,
            'contact' => $email,
        ]);
        $company = $query->fetch();
        return is_array($company) ? $company : [
            'empresa' => $fallbackName !== '' ? $fallbackName : 'Empresa emisora',
            'empresa_rfc' => '',
            'empresa_regimen' => '',
            'logo' => null,
        ];
    }

    private function invoiceData(int $id): array
    {
        $query = $this->pdo->prepare("SELECT f.*, e.razon AS empresa, e.rfc AS empresa_rfc,
            e.regimen AS empresa_regimen, e.logo, cc.razon_social AS receptor,
            cc.rfc AS receptor_rfc, cc.domicilio AS receptor_domicilio, cc.cp AS receptor_cp,
            cc.regimen_fiscal AS receptor_regimen, fp.clave AS forma_clave,
            fp.forma_pago, mp.clave AS metodo_clave, mp.metodo_pago,
            cm.clave_moneda AS moneda_clave, uc.cod_sat AS uso_clave, uc.uso_cfdi,
            COALESCE((SELECT ff.folio FROM facturas_folios ff WHERE ff.id_factura = f.id ORDER BY ff.id DESC LIMIT 1), f.folio) AS folio_interno
            FROM facturas f
            LEFT JOIN empresas e ON e.id = f.razon
            LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
            LEFT JOIN formas_pago fp ON fp.id = CAST(f.forma_pago AS UNSIGNED)
            LEFT JOIN metodos_pago mp ON mp.id = f.metodo_pago
            LEFT JOIN catalogo_monedas cm ON cm.id = f.moneda
            LEFT JOIN catalogo_usos_cfdi uc ON uc.id = f.uso_cfdi
            WHERE f.id = :id LIMIT 1");
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        if (!$row) throw new RuntimeException('La factura solicitada no existe.');
        return $row;
    }

    private function invoiceDetails(int $id): array
    {
        $query = $this->pdo->prepare('SELECT df.*, c.clave_sat, c.clave_producto, c.clave_unidad_medida
            FROM detalle_factura df LEFT JOIN conceptos c ON c.id = df.id_producto
            WHERE df.id_factura = :id ORDER BY df.id');
        $query->execute(['id' => $id]);
        return $query->fetchAll();
    }

    private function complementData(int $id): array
    {
        $query = $this->pdo->prepare("SELECT fc.*, e.razon AS empresa, e.rfc AS empresa_rfc,
            e.regimen AS empresa_regimen, e.logo, cc.razon_social AS receptor,
            cc.rfc AS receptor_rfc, cc.domicilio AS receptor_domicilio, cc.cp AS receptor_cp,
            cc.regimen_fiscal AS receptor_regimen, fp.clave AS forma_clave, fp.forma_pago,
            COALESCE((SELECT cf.folio FROM complementos_folio cf WHERE cf.id_factura = fc.id ORDER BY cf.id DESC LIMIT 1), fc.folio) AS folio_interno
            FROM facturas_complementos fc
            LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN claves_cortas cc ON cc.id = CAST(fc.id_clave_corta AS UNSIGNED)
            LEFT JOIN formas_pago fp ON fp.id = CAST(fc.forma_pago AS UNSIGNED)
            WHERE fc.id = :id LIMIT 1");
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        if (!$row) throw new RuntimeException('El complemento solicitado no existe.');
        return $row;
    }

    private function complementRelations(int $id): array
    {
        $query = $this->pdo->prepare('SELECT rel.*, f.total_iva AS factura_total
            FROM facturar_rel_complementos rel
            LEFT JOIN facturas f ON f.id = rel.id_factura
            WHERE rel.id_complemento = :id ORDER BY rel.id');
        $query->execute(['id' => $id]);
        return $query->fetchAll();
    }

    /** @return array{path:string, temporary:bool}|null */
    private function logoPath(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        $remote = $this->downloadLogo(company_asset_url($value));
        if ($remote !== null) {
            return ['path' => $remote, 'temporary' => true];
        }

        $path = preg_replace('#^https?://[^/]+/#i', '', $value) ?? $value;
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $workspace = dirname((string) (realpath(APP_ROOT) ?: APP_ROOT));
        foreach ([$workspace . '/sistema/' . $path, $workspace . '/sistema/img/' . basename($path)] as $candidate) {
            if (is_file($candidate) && in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) {
                return ['path' => $candidate, 'temporary' => false];
            }
        }
        return null;
    }

    private function downloadLogo(string $url): ?string
    {
        if ($url === '' || !str_starts_with($url, 'https://') || !function_exists('curl_init')) {
            return null;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Sistema14-PDF/1.0',
        ]);
        $bytes = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if (!is_string($bytes) || $status < 200 || $status >= 300 || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }

        $image = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
        $extension = match ($image[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            default => null,
        };
        if ($extension === null) {
            return null;
        }

        $base = tempnam(sys_get_temp_dir(), 's14-logo-');
        if ($base === false) {
            return null;
        }
        $path = $base . '.' . $extension;
        if (!@rename($base, $path) || file_put_contents($path, $bytes) !== strlen($bytes)) {
            @unlink($base);
            @unlink($path);
            return null;
        }
        return $path;
    }

    private function status(array $row): string
    {
        return trim((string) ($row['uuid'] ?? '')) !== '' ? 'TIMBRADO' : 'PREVIO';
    }

    private function folio(array $row): string
    {
        $series = trim((string) ($row['serie'] ?? ''));
        $folio = trim((string) (($row['folio_interno'] ?? '') ?: ($row['folio'] ?? '') ?: ($row['id'] ?? '')));
        return ($series !== '' ? $series . '-' : '') . $folio;
    }

    private function stampDate(array $row): string
    {
        $date = trim((string) ($row['fecha_timbrado'] ?? ''));
        $time = trim((string) ($row['hora_timbrado'] ?? ''));
        return $date !== '' && $date !== '0000-00-00' ? trim($date . ' ' . $time) : 'Pendiente de timbrado';
    }

    private function satCode(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '--' : str_pad($value, 2, '0', STR_PAD_LEFT);
    }

    private function money(float $value): string
    {
        return '$' . number_format($value, 2, '.', ',');
    }

    private function amountWords(float $amount, string $currency): string
    {
        $whole = (int) floor(abs($amount));
        $cents = (int) round((abs($amount) - $whole) * 100);
        if ($cents === 100) {
            $whole++;
            $cents = 0;
        }
        $words = mb_strtoupper($this->integerWords($whole));
        if (str_ends_with($words, 'UNO')) {
            $words = mb_substr($words, 0, -3) . 'UN';
        }
        $unit = strtoupper($currency) === 'MXN' ? ($whole === 1 ? 'PESO' : 'PESOS') : strtoupper($currency);
        return trim($words . ' ' . $unit . ' ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100 M.N.');
    }

    private function integerWords(int $number): string
    {
        $units = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
            'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
            'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
        if ($number < 30) return $units[$number];
        if ($number < 100) {
            $tens = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
            $rest = $number % 10;
            return $tens[intdiv($number, 10)] . ($rest ? ' y ' . $this->integerWords($rest) : '');
        }
        if ($number === 100) return 'cien';
        if ($number < 1000) {
            $hundreds = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
                6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];
            $rest = $number % 100;
            return $hundreds[intdiv($number, 100)] . ($rest ? ' ' . $this->integerWords($rest) : '');
        }
        if ($number < 1_000_000) {
            $thousands = intdiv($number, 1000);
            $rest = $number % 1000;
            $prefix = $thousands === 1 ? 'mil' : $this->integerWords($thousands) . ' mil';
            return $prefix . ($rest ? ' ' . $this->integerWords($rest) : '');
        }
        if ($number < 1_000_000_000) {
            $millions = intdiv($number, 1_000_000);
            $rest = $number % 1_000_000;
            $prefix = $millions === 1 ? 'un millón' : $this->integerWords($millions) . ' millones';
            return $prefix . ($rest ? ' ' . $this->integerWords($rest) : '');
        }
        $billions = intdiv($number, 1_000_000_000);
        $rest = $number % 1_000_000_000;
        $prefix = $billions === 1 ? 'mil millones' : $this->integerWords($billions) . ' mil millones';
        return $prefix . ($rest ? ' ' . $this->integerWords($rest) : '');
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    private function date(string $value): string
    {
        $value = trim($value);
        return $value === '' || str_starts_with($value, '0000-00-00') ? '-' : substr($value, 0, 10);
    }

    private function singleLine(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function truncate(FiscalPdf $pdf, string $text, float $width): string
    {
        $text = $this->singleLine($text);
        if ($pdf->GetStringWidth($pdf->encode($text)) <= $width) return $text;
        while (mb_strlen($text) > 1 && $pdf->GetStringWidth($pdf->encode($text . '...')) > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '...';
    }

    private function limit(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
    }

    private function filename(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'documento-fiscal.pdf';
    }
}
