<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Reports;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $assertions++;
};
$definition = Reports::get('conta');
$assert(is_array($definition), 'el reporte Conta14 está registrado');
$assert($definition['label'] === 'Conta14', 'el reporte tiene el nombre solicitado');
$assert(empty($definition['dateFilter']), 'sin filtros muestra todo el historial documentado');

$page = report_repository()->run('conta', $definition, [], 1, 25);
$assert(count($page['rows']) > 0, 'el reporte devuelve pagos documentados');
foreach ($page['rows'] as $row) {
    $hasInvoice = (int) ($row['factura_id'] ?? 0) > 0;
    $hasComplement = (int) ($row['complemento_id'] ?? 0) > 0;
    $assert($hasInvoice || $hasComplement, 'cada fila contiene al menos una referencia documental');
    $assert(in_array($row['documentos'], ['Factura', 'Complemento', 'Factura y complemento'], true), 'clasifica la relación documental');
}

$legacy = report_repository()->run('conta', $definition, ['q' => '564774'], 1, 25);
$legacyRow = null;
foreach ($legacy['rows'] as $row) {
    if ((int) $row['pago_id'] === 564774) {
        $legacyRow = $row;
        break;
    }
}
$assert(is_array($legacyRow), 'encuentra un pago con documento histórico');
$assert((int) $legacyRow['complemento_id'] === 116887, 'conserva la referencia id_factura_res');
$assert(str_starts_with((string) $legacyRow['complemento_estado'], 'Registro histórico'), 'resuelve el documento histórico desde facturas');
$assert((string) $legacyRow['complemento_folio'] !== '', 'muestra el registro documental correspondiente');

$both = report_repository()->run('conta', $definition, ['q' => '494038'], 1, 25);
$bothRow = null;
foreach ($both['rows'] as $row) {
    if ((int) $row['pago_id'] === 494038) {
        $bothRow = $row;
        break;
    }
}
$assert(is_array($bothRow), 'encuentra un pago con ambas referencias');
$assert($bothRow['documentos'] === 'Factura y complemento', 'identifica pagos con factura y complemento');

echo "conta14_report: {$assertions} comprobaciones superadas.\n";
