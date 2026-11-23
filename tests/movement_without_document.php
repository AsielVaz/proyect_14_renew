<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\MovementRepository;

$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE usuarios (
    id INT NOT NULL PRIMARY KEY,
    tipo VARCHAR(40) NOT NULL
)');
$pdo->exec('CREATE TEMPORARY TABLE pagos (
    id INT NOT NULL PRIMARY KEY,
    nombre VARCHAR(30) NULL,
    status VARCHAR(40) NULL,
    id_factura INT NULL,
    id_factura_res VARCHAR(30) NULL,
    fecha_asig_factura DATETIME NULL,
    usuario_asigna_fac INT NULL
)');
$pdo->exec('CREATE TEMPORARY TABLE s14_movimiento_auditoria (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    accion VARCHAR(40) NOT NULL,
    usuario_id INT NOT NULL,
    pago_id INT NULL,
    datos LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec("INSERT INTO usuarios (id, tipo) VALUES (1, 'administrador'), (2, 'operador')");
$pdo->exec('ALTER TABLE pagos ADD tipo_movimiento VARCHAR(40) NULL');
$pdo->exec("INSERT INTO pagos (id, nombre, status, id_factura, id_factura_res, tipo_movimiento)
    VALUES (101, '74', 'asignado', 0, NULL, 'Deposito'),
           (102, '74', 'asignado', 0, NULL, 'Deposito'),
           (103, NULL, 'sin asignar', 0, NULL, 'Deposito')");

$repository = new MovementRepository($pdo);
$result = $repository->markWithoutDocument(101, 1);
if (($result['paymentId'] ?? 0) !== 101) {
    throw new RuntimeException('La acción no devolvió el movimiento marcado.');
}
$marked = $pdo->query('SELECT id_factura, usuario_asigna_fac FROM pagos WHERE id = 101')->fetch();
if ((int) ($marked['id_factura'] ?? 0) !== 2 || (int) ($marked['usuario_asigna_fac'] ?? 0) !== 1) {
    throw new RuntimeException('El movimiento no quedó marcado con id_factura = 2 y el administrador responsable.');
}
$audit = $pdo->query("SELECT accion FROM s14_movimiento_auditoria WHERE pago_id = 101")->fetchColumn();
if ($audit !== 'without_document') {
    throw new RuntimeException('La marca sin documento no quedó auditada.');
}

try {
    $repository->markWithoutDocument(102, 2);
    throw new RuntimeException('Un usuario no administrador pudo marcar un movimiento.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'Solo un administrador')) {
        throw $exception;
    }
}
if ((int) $pdo->query('SELECT COALESCE(id_factura, 0) FROM pagos WHERE id = 102')->fetchColumn() !== 0) {
    throw new RuntimeException('Se modificó un movimiento después de rechazar a un usuario sin permiso.');
}

try {
    $repository->markWithoutDocument(103, 1);
    throw new RuntimeException('La acción se aplicó incorrectamente en Movimientos sin asignar.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'continúan asignados')) {
        throw $exception;
    }
}

echo "6 comprobaciones de movimiento sin documento superadas.\n";
