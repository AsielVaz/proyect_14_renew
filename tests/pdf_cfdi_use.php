<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\PdfDocumentService;

$service = (new ReflectionClass(PdfDocumentService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(PdfDocumentService::class, 'cfdiUse');

$cases = [
    [['uso_clave' => 'G03', 'uso_cfdi_descripcion' => "G03 \tGastos en general"], 'G03 - Gastos en general'],
    [['uso_clave' => 'CP01', 'uso_cfdi_descripcion' => 'Pagos'], 'CP01 - Pagos'],
    [['uso_clave' => '', 'uso_cfdi_descripcion' => 'Sin efectos fiscales'], 'Sin efectos fiscales'],
    [['uso_clave' => '', 'uso_cfdi_descripcion' => ''], '-'],
];

foreach ($cases as [$input, $expected]) {
    $actual = $method->invoke($service, $input);
    if ($actual !== $expected) {
        throw new RuntimeException("Uso CFDI incorrecto. Esperado: {$expected}; obtenido: {$actual}");
    }
}

$source = file_get_contents(dirname(__DIR__) . '/src/PdfDocumentService.php');
if (!is_string($source)
    || !str_contains($source, 'LEFT JOIN catalogo_usos_cfdi uc ON uc.id = fc.uso_cfdi')
    || !str_contains($source, '$this->complementMetaBar($pdf, $complement)')) {
    throw new RuntimeException('El PDF del complemento debe consultar e imprimir el Uso de CFDI.');
}

echo "pdf_cfdi_use: ok\n";
