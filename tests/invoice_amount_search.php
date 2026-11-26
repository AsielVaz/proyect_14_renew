<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\InvoiceRepository;

$pdo = db();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $pdo->exec('CREATE TEMPORARY TABLE clientes (id INT PRIMARY KEY, nombre VARCHAR(100), correo VARCHAR(100))');
    $pdo->exec('CREATE TEMPORARY TABLE empresas (id INT PRIMARY KEY, razon VARCHAR(150))');
    $pdo->exec('CREATE TEMPORARY TABLE claves_cortas (id INT PRIMARY KEY, rfc VARCHAR(50), razon_social VARCHAR(150))');
    $pdo->exec('CREATE TEMPORARY TABLE formas_pago (id INT PRIMARY KEY, forma_pago VARCHAR(100))');
    $pdo->exec('CREATE TEMPORARY TABLE metodos_pago (id INT PRIMARY KEY, metodo_pago VARCHAR(100))');
    $pdo->exec('CREATE TEMPORARY TABLE usuarios (id INT PRIMARY KEY, nombre VARCHAR(100))');
    $pdo->exec('CREATE TEMPORARY TABLE facturar_rel_complementos (id_factura INT, monto DECIMAL(18,2))');
    $pdo->exec('CREATE TEMPORARY TABLE facturas (
        id INT PRIMARY KEY, fecha DATETIME, serie VARCHAR(20), folio VARCHAR(50),
        total_iva DECIMAL(18,2), uuid VARCHAR(100), token VARCHAR(100), status VARCHAR(50),
        status_error TEXT, status_pago VARCHAR(50), id_pago INT, metodo_pago INT,
        forma_pago INT, nombre INT, razon INT, id_clave_corta INT, usuario_crea INT, sello_sat TEXT NULL
    )');

    $pdo->exec("INSERT INTO clientes VALUES (1, 'Cliente de prueba', 'cliente@example.test')");
    $pdo->exec("INSERT INTO empresas VALUES (1, 'Empresa de prueba')");
    $pdo->exec("INSERT INTO claves_cortas VALUES (1, 'RFC010101AA1', 'Receptor de prueba')");
    $pdo->exec("INSERT INTO formas_pago VALUES (1, 'Transferencia')");
    $pdo->exec("INSERT INTO metodos_pago VALUES (1, 'PUE')");
    $pdo->exec("INSERT INTO usuarios VALUES (1, 'Operador')");
    $pdo->exec("INSERT INTO facturas VALUES
        (700001, '2026-11-20 10:00:00', 'A', 'F-100', 456321.34, 'UUID-PRUEBA', '', '', '', 'Pagado', 0, 1, 1, 1, 1, 1, 1, 'SELLO')");

    $repository = new InvoiceRepository($pdo);
    foreach (['456321.34', '456,321.34', '$456,321.34'] as $search) {
        $rows = $repository->page($search, 1)['rows'];
        $assert(count($rows) === 1 && (int) $rows[0]['id'] === 700001,
            "El monto {$search} debe encontrar la factura por total_iva.");
    }
    $assert(count($repository->page('Cliente de prueba', 1)['rows']) === 1,
        'La búsqueda textual existente debe conservarse.');

    $pdo->exec("INSERT INTO facturas VALUES
        (700002, '2024-01-02 10:00:00', 'A', 'F-101', 100.00, NULL, '', '', '', '', 0, 1, 1, 1, 1, 1, 1, NULL),
        (700003, '2024-01-02 10:00:00', 'A', 'F-102', 100.00, NULL, '', '', '', '', 0, 1, 1, 1, 1, 1, 1, '')");
    $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'id'));
    $assert($ids($repository->page('', 1, pendingStampOnly: true)['rows']) === [700002],
        'Pendientes debe filtrar únicamente por sello SAT NULL.');
    $assert($repository->page('', 1, dateFrom: '2024-02-01', pendingStampOnly: true)['rows'] === [],
        'El filtro de pendientes debe respetar el rango de fechas.');
    $assert($ids($repository->exportRows('', pendingStampOnly: true)) === [700002],
        'El filtro de pendientes debe conservarse al exportar, incluso fuera de los últimos diez días.');
} finally {
    foreach (['facturas', 'facturar_rel_complementos', 'usuarios', 'metodos_pago', 'formas_pago', 'claves_cortas', 'empresas', 'clientes'] as $table) {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
    }
}

echo "invoice_amount_search: ok\n";
