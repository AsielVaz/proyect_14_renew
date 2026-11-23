<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\MovementRepository;
use App\Movements;

$pdo = db();
$repository = new MovementRepository($pdo);

$pdo->exec('CREATE TEMPORARY TABLE pagos (
    id INT NOT NULL PRIMARY KEY, nombre VARCHAR(30), status VARCHAR(40),
    id_factura INT, id_factura_res VARCHAR(30), empresa INT, banco VARCHAR(40),
    monto DECIMAL(18,2), saldo_acreditable DECIMAL(18,2), tipo_movimiento VARCHAR(40),
    detalle TEXT, descripcion TEXT, descripcion_detallada TEXT,
    fecha_banco DATE, fecha_operacion DATE, fecha_captura DATE, fecha_asignacion DATETIME,
    usuario_asigna_fac INT, id_captura INT, nombre_corto VARCHAR(80)
)');
$pdo->exec('CREATE TEMPORARY TABLE captura (id INT PRIMARY KEY, razon INT, nombre_corto INT)');
$pdo->exec('CREATE TEMPORARY TABLE bancos (id INT PRIMARY KEY, nombre_corto VARCHAR(80))');
$pdo->exec('CREATE TEMPORARY TABLE empresas (id INT PRIMARY KEY, razon VARCHAR(180))');
$pdo->exec('CREATE TEMPORARY TABLE clientes (id INT PRIMARY KEY, nombre VARCHAR(180))');
$pdo->exec('CREATE TEMPORARY TABLE usuarios (id INT PRIMARY KEY, nombre VARCHAR(180))');

$pdo->exec("INSERT INTO empresas VALUES (4, 'Empresa de prueba')");
$pdo->exec("INSERT INTO clientes VALUES (74, 'Cartera de prueba')");
$pdo->exec("INSERT INTO usuarios VALUES (1, 'Administrador')");
$pdo->exec("INSERT INTO pagos
    (id, nombre, status, id_factura, empresa, banco, monto, saldo_acreditable,
     tipo_movimiento, fecha_asignacion, usuario_asigna_fac)
    VALUES
    (501, '74', 'asignado', 0, 4, 'BBVA', 100, 100, 'Deposito', '2026-11-10 09:30:00', 1),
    (502, '74', 'asignado', 0, 4, 'BBVA', 200, 200, 'Deposito', '2026-11-20 18:45:00', 1)");

$result = $repository->page(
    'asignados',
    Movements::get('asignados'),
    '',
    1,
    25,
    null,
    [],
    '',
    '2026-11-15',
    '2026-11-30',
    'assigned'
);

if ((int) ($result['total'] ?? 0) !== 1 || (int) ($result['rows'][0]['id'] ?? 0) !== 502) {
    throw new RuntimeException('El rango no filtró Movimientos asignados por fecha de asignación.');
}

echo "1 comprobación del rango de fecha de asignación superada.\n";
