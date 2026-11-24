<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CaptureAnalysisService;

$service = (new ReflectionClass(CaptureAnalysisService::class))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass($service);
$detectColumns = $reflection->getMethod('detectColumns');
$movementFromRow = $reflection->getMethod('movementFromRow');

$rows = [
    ['Fecha', 'Hora', 'Tipo Operación', 'Beneficiario/Ordenante', 'Banco', 'Cuenta',
        'Cuenta Ordenante', 'Clave Rastreo', 'Concepto', 'Referencia', 'Cargo', 'Abono', 'Saldo'],
    ['24/11/2026', '09:22:05', 'Transferencia enviada', 'CARLA MARIA CHACON COLLIA',
        'BBVA MEXICO', "'012790015575227038", "'659444366000000010", '', 'AST1ALR3Z',
        '261124', '23,733.60', '0.00', '6,327,096.58'],
];

[$headerIndex, $columns] = $detectColumns->invoke($service, $rows);
assert($headerIndex === 0);
assert(($columns['description'] ?? null) === 2);
assert(($columns['concept'] ?? null) === 8);

$movement = $movementFromRow->invoke($service, $rows[1], $columns);
assert(is_array($movement));
assert($movement['description'] === 'Transferencia enviada · AST1ALR3Z');
assert(str_contains($movement['description'], 'AST1ALR3Z'));
assert($movement['type'] === 'Retiro');
assert($movement['amount'] === 23733.60);
assert($movement['date'] === '2026-11-24');

echo "9 comprobaciones de captura ASP superadas.\n";
