<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CatalogRepository;
use App\Catalogs;

$pdo = db();
$clientId = (int) $pdo->query('SELECT id FROM clientes ORDER BY id LIMIT 1')->fetchColumn();
if ($clientId <= 0) {
    throw new RuntimeException('No hay clientes disponibles para comprobar info_3.');
}

$field = Catalogs::get('carteras')['fields']['notificacion_movimientos'] ?? null;
if (!is_array($field)) {
    throw new RuntimeException('No se configuró el selector de notificación.');
}

$catalog = [
    'table' => 'clientes',
    'fields' => ['notificacion_movimientos' => $field],
];
$repository = new CatalogRepository($pdo);

$pdo->beginTransaction();
try {
    foreach (['no', 'movimiento', 'fin_dia'] as $value) {
        $repository->save($catalog, ['notificacion_movimientos' => $value], $clientId);
        $query = $pdo->prepare('SELECT info_3 FROM clientes WHERE id = :id');
        $query->execute(['id' => $clientId]);
        if ((string) $query->fetchColumn() !== $value) {
            throw new RuntimeException('No se guardó correctamente el valor ' . $value . ' en info_3.');
        }
    }

    try {
        $repository->save($catalog, ['notificacion_movimientos' => 'valor_invalido'], $clientId);
        throw new RuntimeException('El catálogo permitió un valor de notificación no autorizado.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Selecciona un valor válido')) {
            throw $exception;
        }
    }

    echo "OK: las tres opciones se guardan en clientes.info_3 y los valores inválidos se rechazan.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
