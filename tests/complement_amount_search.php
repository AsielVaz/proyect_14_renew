<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$complement = db()->query(
    'SELECT id, total_factura
     FROM facturas_complementos
     WHERE total_factura > 0
     ORDER BY id DESC
     LIMIT 1'
)->fetch();

if (!is_array($complement)) {
    throw new RuntimeException('No hay complementos con monto para probar el buscador.');
}

$id = (int) $complement['id'];
$amount = (float) $complement['total_factura'];
$plainSearch = number_format($amount, 2, '.', '');
$formattedSearch = '$' . number_format($amount, 2, '.', ',');

foreach ([$plainSearch, $formattedSearch] as $search) {
    $result = payment_complement_repository()->paginate($search, 1, 30);
    $ids = array_map('intval', array_column($result['rows'], 'id'));
    if (!in_array($id, $ids, true)) {
        throw new RuntimeException('El buscador no encontró el complemento por total_factura usando: ' . $search);
    }
}

$exportIds = array_map(
    'intval',
    array_column(payment_complement_repository()->exportRows($formattedSearch), 'id')
);
if (!in_array($id, $exportIds, true)) {
    throw new RuntimeException('La exportación no respetó la búsqueda por total_factura.');
}

echo "OK: complementos encontrados por total_factura con monto simple y formateado.\n";
