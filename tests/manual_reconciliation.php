<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\InvoiceRepository;

$pdo = db();
$tables = [
    'empresas' => 'id INT PRIMARY KEY, razon VARCHAR(100)',
    'clientes' => 'id INT PRIMARY KEY, nombre VARCHAR(100), activo INT',
    'captura' => 'id INT PRIMARY KEY, razon INT',
    'captura_edo_cta' => 'id INT PRIMARY KEY, referencia VARCHAR(80)',
    'pagos' => 'id INT PRIMARY KEY, nombre VARCHAR(10), monto DOUBLE, empresa VARCHAR(10),
        banco VARCHAR(20), id_factura_res VARCHAR(20), descripcion_detallada VARCHAR(100),
        descripcion VARCHAR(100), detalle VARCHAR(100), id_captura INT, id_captura_edo_cta INT,
        fecha_asignacion DATE, status VARCHAR(20), tipo_movimiento VARCHAR(20), id_factura INT',
    'facturas' => 'id INT PRIMARY KEY, id_pago INT, nombre INT, total_iva DOUBLE',
    'relacion_factura_pago' => 'id_factura INT, id_pago INT',
    'claves_cortas' => 'id INT PRIMARY KEY, clave_corta VARCHAR(20), id_cliente INT,
        razon_social VARCHAR(100), rfc VARCHAR(15), cp VARCHAR(5), regimen_fiscal INT',
];
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
try {
    foreach ($tables as $name => $schema) $pdo->exec("CREATE TEMPORARY TABLE {$name} ({$schema})");
    $pdo->exec("INSERT INTO empresas VALUES (10,'Emisora de prueba')");
    $pdo->exec("INSERT INTO clientes VALUES (13,'Internos',1), (45,'Otra cartera',1)");
    $pdo->exec("INSERT INTO claves_cortas VALUES
        (71,'FC4UNO',13,'Receptor uno','RFC010101AA1','01000',601),
        (72,'FC4DOS',13,'Receptor dos','RFC010101AA2','01000',601),
        (73,'XOTRO',45,'Otro receptor','RFC010101AA3','01000',601)");
    $pdo->exec("INSERT INTO captura_edo_cta VALUES (109,'Referencia FC4UNO')");
    $insert = $pdo->prepare("INSERT INTO pagos (id,nombre,monto,empresa,banco,detalle,id_captura_edo_cta,
        fecha_asignacion,status,tipo_movimiento,id_factura)
        VALUES (?, ?, ?, '10', 'ASP', ?, ?, ?, 'asignado', ?, ?)");
    $day = '2026-11-26';
    $old = '2026-11-25';
    foreach ([
        [1,'13',100.25,'',0,$day,'Deposito',null],
        [2,'13',200,'',0,$day,'Deposito',null],
        [3,'13',300,'',0,$day,'Deposito',null],
        [4,'13',400,'',0,$day,'Deposito',null],
        [5,'13',500,'',0,$day,'Deposito',null],
        [6,'13',600,'',0,$old,'Deposito',null],
        [7,'13',700,'',0,$day,'Deposito',2],
        [8,'13',800,'',0,$day,'Deposito',null],
        [9,'13',900,'',0,$day,'Deposito',null],
        [11,'13',-100.25,'',109,$day,'Retiro',null],
        [12,'13',100.25,'FC4DOS',0,$old,'Retiro',null],
        [13,'13',200,'FC4UNO',0,$day,'Retiro',null],
        [14,'13',200,'FC4DOS',0,$day,'Retiro',null],
        [15,'45',-900,'',0,$day,'Retiro',null],
    ] as $row) $insert->execute($row);
    $pdo->exec('INSERT INTO facturas VALUES (181,NULL,13,400), (182,5,45,500), (183,NULL,45,123)');
    $pdo->exec('INSERT INTO relacion_factura_pago VALUES (183,8)');
    $repo = new InvoiceRepository($pdo);
    $rows = array_column($repo->manualReconciliation($day), null, 'id');
    $check(array_keys($rows) === [9, 3, 2, 1], 'Debe excluir otro día, retiros, bandera 2 y facturas por vínculo o importe.');
    $check((int) $rows[1]['receiver']['id'] === 71 && $rows[1]['reason'] === '',
        'El retiro del mismo día y monto negativo debe prevalecer sobre el histórico.');
    $check($rows[2]['receiver'] === null && $rows[2]['reason'] !== '', 'Dos receptores posibles deben bloquear la acción.');
    $check($rows[3]['receiver'] === null && $rows[3]['reason'] !== '', 'Se debe listar un depósito que carece de retiro.');
    $check((int) $rows[9]['receiver']['id'] === 73, 'Un retiro en otra cartera con receptor único debe identificarse.');
    $prefill = $repo->manualReconciliationPrefill(1, $day);
    $check($prefill['id'] === 1 && $prefill['client_id'] === 13 && $prefill['receiver_id'] === 71
        && $prefill['withdrawal_id'] === 11 && $prefill['amount'] === 100.25, 'La precarga debe vincular el depósito, no el retiro.');
    $pdo->exec('UPDATE pagos SET id_factura = 184 WHERE id = 1');
    try {
        $repo->manualReconciliationPrefill(1, $day);
        throw new LogicException('Una factura recién vinculada debe impedir otra precarga.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'ya tiene una factura'), 'El rechazo debe identificar la factura existente.');
    }
} finally {
    foreach (array_keys($tables) as $name) $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$name}");
}

echo "manual_reconciliation: ok\n";
