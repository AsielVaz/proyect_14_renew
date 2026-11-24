<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ShortKeyLiveDocumentService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$pdo = db();
try {
    $pdo->exec('CREATE TEMPORARY TABLE claves_cortas (
        id INT PRIMARY KEY, id_cliente INT, clave_corta VARCHAR(100), documentos_vivos INT NULL
    )');
    $pdo->exec('CREATE TEMPORARY TABLE facturas (
        id INT PRIMARY KEY, id_clave_corta INT, metodo_pago INT, uuid VARCHAR(100),
        status VARCHAR(50), total_iva DECIMAL(18,2)
    )');
    $pdo->exec('CREATE TEMPORARY TABLE facturar_rel_complementos (
        id INT PRIMARY KEY AUTO_INCREMENT, id_factura INT, monto DECIMAL(18,2)
    )');
    $pdo->exec("INSERT INTO claves_cortas VALUES
        (910001, 920001, 'CLAVE-A', 99),
        (910002, 920001, 'CLAVE-B', 99),
        (910003, 920002, 'CLAVE-C', 99)");
    $pdo->exec("INSERT INTO facturas VALUES
        (930001, 910001, 2, 'UUID-1', 'Timbrada', 100.00),
        (930002, 910001, 2, 'UUID-2', 'Timbrada', 200.00),
        (930003, 910002, 2, 'UUID-3', 'Timbrada', 300.00),
        (930004, 910002, 1, 'UUID-4', 'Timbrada', 400.00),
        (930005, 910002, 2, 'UUID-5', 'Cancelado', 500.00),
        (930006, 910003, 2, '', 'Pendiente', 600.00)");
    $pdo->exec("INSERT INTO facturar_rel_complementos (id_factura, monto) VALUES
        (930001, 40.00), (930002, 125.00), (930002, 75.00)");

    $result = (new ShortKeyLiveDocumentService($pdo))->run();
    $counts = $pdo->query('SELECT id, documentos_vivos FROM claves_cortas ORDER BY id')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $expect($counts === [910001 => 1, 910002 => 1, 910003 => 0],
        'El conteo debe calcularse por clave corta, no por cliente.');
    $expect(($result['ok'] ?? false) === true
        && ($result['claves_cortas'] ?? 0) === 3
        && ($result['claves_con_documentos'] ?? 0) === 2
        && ($result['documentos_vivos'] ?? 0) === 2,
        'El cron debe devolver un resumen verificable de la actualización.');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS facturar_rel_complementos');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS facturas');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS claves_cortas');
}

echo "short_key_live_documents: ok\n";
