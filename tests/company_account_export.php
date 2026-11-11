<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $assertions++;
};

$default = company_panel_repository()->exportAccountMovements(4, 102, [], 1);
$assert($default['defaulted'] === true, 'sin fechas aplica el periodo automático');
$assert($default['from'] === date('Y-m-01'), 'el periodo automático inicia el primer día del mes');
$assert($default['to'] === date('Y-m-t'), 'el periodo automático termina el último día del mes');

$from = '2026-10-01';
$to = '2026-10-31';
$filtered = company_panel_repository()->exportAccountMovements(4, 102, ['from' => $from, 'to' => $to], 1);
$assert($filtered['defaulted'] === false, 'con fechas respeta el rango solicitado');
$assert((int) $filtered['account']['id'] === 102, 'valida la cuenta contra la empresa');
foreach ($filtered['rows'] as $row) {
    $bankDate = (string) ($row['fecha'] ?? '');
    $systemDate = (string) ($row['fecha_sistema'] ?? '');
    $matchesBank = $bankDate >= $from && $bankDate <= $to;
    $matchesSystem = $systemDate >= $from && $systemDate <= $to;
    $assert($matchesBank || $matchesSystem, 'cada movimiento coincide por fecha bancaria o fecha de sistema');
}

$bytes = xlsx_export_service()->create(
    'Estado de cuenta 102',
    ['id' => 'ID', 'descripcion' => 'Descripción', 'retiro' => 'Retiro'],
    [['id' => 1, 'descripcion' => "Descripción extensa\ncon detalle", 'retiro' => 390.00]],
    ['wrap_text' => true, 'widths' => ['descripcion' => 48]]
);
$path = tempnam(sys_get_temp_dir(), 's14-account-export-');
$assert($path !== false, 'crea el archivo temporal de verificación');
file_put_contents($path, $bytes);
$zip = new ZipArchive();
$assert($zip->open($path) === true, 'el resultado es un XLSX válido');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$styles = (string) $zip->getFromName('xl/styles.xml');
$zip->close();
@unlink($path);
$assert(str_contains($sheet, 'width="48"'), 'aplica el ancho especial de descripción');
$assert(str_contains($sheet, 's="2"'), 'aplica ajuste de texto a las celdas');
$assert(str_contains($styles, 'wrapText="1"'), 'incluye el estilo de texto ajustado');

echo "company_account_export: {$assertions} comprobaciones superadas; no se modificó la base de datos.\n";
