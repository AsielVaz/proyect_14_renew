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

echo count($cases) . " comprobaciones de presentación de movimientos superadas.\n";
