<?php

declare(strict_types=1);

require __DIR__ . '/../src/XlsxExportService.php';

use App\XlsxExportService;

function assert_xlsx(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$bytes = (new XlsxExportService())->create(
    'Facturas / prueba',
    ['id' => 'ID', 'cliente' => 'Cliente', 'total' => 'Total'],
    [['id' => 7, 'cliente' => 'Compañía & Asociados', 'total' => 1250.50]]
);
$path = tempnam(sys_get_temp_dir(), 's14-xlsx-test-');
assert_xlsx($path !== false, 'No fue posible crear el archivo temporal de prueba.');
file_put_contents($path, $bytes);

$zip = new ZipArchive();
assert_xlsx($zip->open($path) === true, 'El resultado no es un contenedor XLSX válido.');
foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $entry) {
    assert_xlsx($zip->locateName($entry) !== false, 'Falta la parte ' . $entry . ' del XLSX.');
}
$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
$workbook = $zip->getFromName('xl/workbook.xml');
$zip->close();
@unlink($path);

assert_xlsx(is_string($sheet) && simplexml_load_string($sheet) !== false, 'La hoja no contiene XML válido.');
assert_xlsx(str_contains($sheet, 'Compañía &amp; Asociados'), 'El contenido de texto no fue escapado correctamente.');
assert_xlsx(str_contains($sheet, '<v>1250.5</v>'), 'El importe no fue exportado como número.');
assert_xlsx(is_string($workbook) && str_contains($workbook, 'Facturas   prueba'), 'El nombre de la hoja no fue normalizado.');

echo "4 comprobaciones de exportación XLSX superadas.\n";
