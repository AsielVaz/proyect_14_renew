<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\PaymentNotifierRepository;

$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE clientes (
    id INT NOT NULL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) NULL,
    correo VARCHAR(100) NULL,
    activo INT NOT NULL
)');
$pdo->exec('CREATE TEMPORARY TABLE mensajesBanca (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    asunto VARCHAR(256) NULL,
    mensaje TEXT NULL,
    telefono VARCHAR(30) NULL,
    correo VARCHAR(100) NULL,
    notificado INT NULL DEFAULT 0,
    log TEXT NULL,
    fecha_inserta DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_notifica DATETIME NULL,
    id_cliente INT NULL
)');
$pdo->exec("INSERT INTO clientes (id, nombre, telefono, correo, activo) VALUES
    (10, 'Alfa', '+52 33 2513 2734', 'alfa@example.com', 1),
    (11, 'Beta', '3325132734', 'beta@example.com', 1),
    (12, 'Inactivo', '3311111111', 'inactivo@example.com', 0),
    (13, 'Sin teléfono', '0', 'cero@example.com', 1),
    (14, 'Gamma', '3333333333', 'gamma@example.com', 1)");

$repository = new PaymentNotifierRepository($pdo);
$groups = $repository->recipientGroups();
if (count($groups) !== 2) {
    throw new RuntimeException('Solo deben mostrarse los teléfonos válidos de clientes activos.');
}
$group = array_values(array_filter($groups, static fn (array $item): bool => $item['phone'] === '3325132734'))[0] ?? null;
if (!$group || (int) $group['clientCount'] !== 2 || (string) $group['names'] !== 'Alfa · Beta') {
    throw new RuntimeException('Los clientes con el mismo teléfono no fueron agrupados.');
}

$result = $repository->queue('Aviso', 'Mensaje de prueba', ['+52 33 2513 2734']);
if (($result['records'] ?? 0) !== 1 || ($result['clients'] ?? 0) !== 2) {
    throw new RuntimeException('La cola no reportó correctamente el teléfono único y sus clientes agrupados.');
}
$queued = $pdo->query('SELECT * FROM mensajesBanca')->fetch();
if (!$queued || $queued['asunto'] !== 'Aviso' || $queued['mensaje'] !== 'Mensaje de prueba'
    || $queued['telefono'] !== '3325132734' || (int) $queued['notificado'] !== 0
    || $queued['fecha_notifica'] !== null || (int) $queued['id_cliente'] !== 10) {
    throw new RuntimeException('El registro pendiente no se guardó correctamente en mensajesBanca.');
}

try {
    $repository->queue('Aviso', 'Mensaje', ['3399999999']);
    throw new RuntimeException('Se aceptó un teléfono que no pertenece a los destinatarios disponibles.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'ya no pertenece')) {
        throw $exception;
    }
}

echo "5 comprobaciones del Notificador superadas.\n";
