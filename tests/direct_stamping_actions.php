<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/index.php');
if ($source === false) {
    throw new RuntimeException('No fue posible leer index.php.');
}

$checks = [
    'columna de timbrado visible' => substr_count($source, '<th>Timbrar</th>') === 2,
    'acción directa para facturas' => str_contains($source, 'class="direct-stamp-form"'),
    'acción directa para complementos' => str_contains($source, 'aria-label="Timbrar complemento #'),
    'acción contextual de facturas conservada' => str_contains($source, 'data-action-label="Timbrar factura"'),
    'acción contextual de complementos conservada' => str_contains($source, 'data-action-label="Timbrar complemento"'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Falló la comprobación: ' . $label);
    }
}

echo count($checks) . " comprobaciones de timbrado directo superadas.\n";
