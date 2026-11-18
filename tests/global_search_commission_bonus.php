<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\GlobalSearchRepository;
use App\Movements;

$pdo = db();
$bonus = $pdo->query(
    "SELECT id, monto
     FROM pagos
     WHERE LOWER(COALESCE(tipo_movimiento, '')) LIKE 'abono por comisi%'
       AND LOWER(COALESCE(detalle, '')) LIKE 'abono de porcentaje por comision%'
       AND banco <> 'STP' AND banco <> '99'
     ORDER BY id DESC
     LIMIT 1"
)->fetch();

if (!is_array($bonus)) {
    throw new RuntimeException('No hay un bono de porcentaje por comisión disponible para la prueba.');
}

$paymentId = (int) $bonus['id'];
$repository = new GlobalSearchRepository($pdo);

$byAlias = $repository->search('bono de porcentaje por comision', 1, 10)['payments'];
if ($byAlias === []) {
    throw new RuntimeException('El buscador no encontró bonos mediante el término alternativo.');
}
if (!str_contains((string) $byAlias[0]['title'], 'Bono de porcentaje por comisión')) {
    throw new RuntimeException('El resultado no identifica claramente el bono de comisión.');
}

$byId = $repository->search((string) $paymentId, 1, 10)['payments'];
if (!in_array($paymentId, array_column($byId, 'id'), true)) {
    throw new RuntimeException('El buscador no encontró el bono por su ID.');
}

$byAmount = $repository->search(number_format((float) $bonus['monto'], 2, '.', ''), 1, 10)['payments'];
$hasBonusByAmount = array_filter(
    $byAmount,
    static fn (array $result): bool => str_contains((string) $result['title'], 'Bono de porcentaje por comisión')
);
if ($hasBonusByAmount === []) {
    throw new RuntimeException('El buscador no encontró el bono por su monto.');
}

$movementResult = movement_repository()->page(
    'totales',
    Movements::get('totales'),
    (string) $paymentId,
    1,
    25
);
if (!in_array($paymentId, array_map('intval', array_column($movementResult['rows'], 'id')), true)) {
    throw new RuntimeException('El enlace del resultado no puede mostrar el bono en el log de movimientos.');
}

echo "OK: los bonos de porcentaje por comisión aparecen en la búsqueda global y en el log de movimientos.\n";
