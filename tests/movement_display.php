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

echo (count($cases) + 2) . " comprobaciones de presentación de movimientos superadas.\n";
