<?php

declare(strict_types=1);

$pdfSource = file_get_contents(dirname(__DIR__) . '/src/PdfDocumentService.php');
$emailSource = file_get_contents(dirname(__DIR__) . '/src/InvoiceEmailService.php');
$indexSource = file_get_contents(dirname(__DIR__) . '/index.php');

if ($pdfSource === false || $emailSource === false || $indexSource === false) {
    throw new RuntimeException('No fue posible leer los archivos del flujo de PDF de facturas.');
}

$checks = [
    'el PDF usa directamente facturas.folio' => str_contains($pdfSource, 'f.folio AS folio_interno'),
    'el PDF ya no consulta facturas_folios' => !str_contains($pdfSource, 'facturas_folios'),
    'el correo genera el PDF con el servicio compartido' => str_contains($emailSource, '$this->documents->invoice($invoiceId)'),
    'la vista y descarga usan el servicio compartido' => str_contains($indexSource, "'invoice_pdf' => pdf_document_service()->invoice((int) \$documentId)"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Falló la comprobación: ' . $label);
    }
}

echo count($checks) . " comprobaciones del folio del PDF superadas.\n";
