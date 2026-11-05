<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Catalogs.php';

use App\Catalogs;

$expected = [
    'nombre', 'telefono', 'esquema_comision', 'porcentajeCom', 'color', 'texto',
    'archivo', 'activo', 'revision',
    'sub_com1', 'porcentaje1', 'sub_com2', 'porcentaje2', 'sub_com3', 'porcentaje3',
    'correo', 'correoSaldos2', 'correoSaldos3',
    'correo2', 'correoF2', 'correoF3',
    'fact_auto', 'grupo', 'notificar_saldo', 'lidera',
    'razon_social', 'rfc', 'domicilio',
];

$catalog = Catalogs::get('carteras');
if ($catalog === null) {
    throw new RuntimeException('No se encontró el catálogo de carteras.');
}

$actual = array_keys($catalog['fields']);
if ($actual !== $expected) {
    throw new RuntimeException('El formulario de carteras no coincide exactamente con los campos de referencia.');
}

$mappedColumns = array_map(
    static fn (string $name, array $field): string => (string) ($field['column'] ?? $name),
    array_keys($catalog['fields']),
    array_values($catalog['fields'])
);

foreach (['1_subcomision', '2_subcomision', '3_subcomision', 'imagen', 'facturacion_automatica'] as $column) {
    if (!in_array($column, $mappedColumns, true)) {
        throw new RuntimeException('Falta el mapeo de la columna ' . $column . '.');
    }
}

echo count($expected) . " campos del formulario de carteras verificados.\n";
