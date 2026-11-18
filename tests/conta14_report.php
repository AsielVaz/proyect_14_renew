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
    $assert((string) ($row['tipo_movimiento'] ?? '') === 'Deposito', 'cada fila corresponde a un depósito');
    $assert($hasInvoice || $hasComplement, 'cada fila contiene al menos una referencia documental');
    $assert(in_array($row['documentos'], ['Factura', 'Complemento', 'Factura y complemento'], true), 'clasifica la relación documental');
}

$withdrawal = report_repository()->run('conta', $definition, ['q' => '564774'], 1, 25);
$withdrawalIds = array_map('intval', array_column($withdrawal['rows'], 'pago_id'));
$assert(!in_array(564774, $withdrawalIds, true), 'excluye retiros aunque tengan un documento histórico relacionado');

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
