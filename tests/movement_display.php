<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$cases = [
    [['DEPOSITO', 'DEPOSITO', null], 'DEPOSITO'],
    [['  Depósito  ', 'depósito', 'DEPÓSITO'], 'Depósito'],
    [['Transferencia', 'Pago de cliente', 'Pago de cliente'], 'Transferencia Pago de cliente'],
    [[null, '', 'Detalle único'], 'Detalle único'],
];

foreach ($cases as [$values, $expected]) {
    $actual = App\Movements::uniqueDetailText(...$values);
    if ($actual !== $expected) {
        throw new RuntimeException("Se esperaba '{$expected}' y se obtuvo '{$actual}'.");
    }
}

$assignedAmount = App\Movements::get('asignados')['columns']['monto'] ?? [];
if (isset($assignedAmount['secondary']['saldo_acreditable'])) {
    throw new RuntimeException('Movimientos asignados no debe mostrar el saldo acreditable.');
}

$reassignmentAmount = App\Movements::get('reasignar')['columns']['monto'] ?? [];
if (!isset($reassignmentAmount['secondary']['saldo_acreditable'])) {
    throw new RuntimeException('Reasignación debe conservar el saldo acreditable.');
}

$invoiceReferences = [
    [null, 0],
    [0, 0],
    [2, 0],
    ['2', 0],
    [3, 3],
];
foreach ($invoiceReferences as [$value, $expected]) {
    $actual = App\Movements::invoiceDocumentId($value);
    if ($actual !== $expected) {
        throw new RuntimeException("La referencia de factura no se normalizó correctamente.");
    }
}

$repositoryReflection = new ReflectionClass(App\MovementRepository::class);
$repository = $repositoryReflection->newInstanceWithoutConstructor();
$sourceMethod = $repositoryReflection->getMethod('source');
[$withDocumentSource] = $sourceMethod->invoke($repository, 'asignados', true, ['document'], '');
[$withoutDocumentSource] = $sourceMethod->invoke($repository, 'asignados', true, ['none'], '');
[$unassignedSource] = $sourceMethod->invoke($repository, 'sin-asignar', true, [], '');

if (!str_contains($withDocumentSource, 'COALESCE(p.id_factura, 0) > 0')
    || str_contains($withDocumentSource, 'COALESCE(p.id_factura, 0) <> 2')) {
    throw new RuntimeException('La bandera id_factura = 2 debe incluirse en Con documento.');
}
if (!str_contains($withoutDocumentSource, 'COALESCE(p.id_factura, 0) <= 0')
    || str_contains($withoutDocumentSource, 'COALESCE(p.id_factura, 0) = 2')) {
    throw new RuntimeException('La bandera id_factura = 2 debe excluirse de Sin documento.');
}
foreach ([$withDocumentSource, $unassignedSource] as $movementSource) {
    foreach (['p.descripcion AS __descripcion', 'p.detalle AS __detalle',
        'p.descripcion_detallada AS __descripcion_detallada'] as $searchField) {
        if (!str_contains($movementSource, $searchField)) {
            throw new RuntimeException('El buscador de movimientos debe incluir descripción y detalle.');
        }
    }
}

if (!str_contains($unassignedSource, 'LEFT JOIN captura_edo_cta cec ON cec.id = p.id_captura_edo_cta')
    || !str_contains($unassignedSource, 'cec.referencia AS referencia')) {
    throw new RuntimeException('Movimientos sin asignar debe recuperar la referencia mediante LEFT JOIN al estado de cuenta.');
}
if (!array_key_exists('referencia', App\Movements::get('sin-asignar')['columns'] ?? [])) {
    throw new RuntimeException('Movimientos sin asignar debe mostrar la referencia bancaria.');
}
if (array_key_exists('referencia', App\Movements::get('asignados')['columns'] ?? [])) {
    throw new RuntimeException('La nueva columna de referencia debe limitarse a movimientos sin asignar.');
}

echo (count($cases) + 7 + count($invoiceReferences)) . " comprobaciones de presentación de movimientos superadas.\n";
