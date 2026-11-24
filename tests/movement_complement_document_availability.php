<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\PaymentComplementRepository;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE clientes_saldo_script (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    id_cliente INTEGER NOT NULL,
    facturas_vivas INTEGER NULL
)');
$pdo->exec('INSERT INTO clientes_saldo_script (id_cliente, facturas_vivas) VALUES
    (1, 5), (1, 2), (2, 0), (3, NULL)');

$repository = new PaymentComplementRepository($pdo);
$method = (new ReflectionClass($repository))->getMethod('pendingInvoiceCount');
assert($method->invoke($repository, 1) === 2);
assert($method->invoke($repository, 2) === 0);
assert($method->invoke($repository, 3) === 0);
assert($method->invoke($repository, 999) === 0);

$movementSource = file_get_contents(dirname(__DIR__) . '/src/MovementRepository.php');
$indexSource = file_get_contents(dirname(__DIR__) . '/index.php');
assert(is_string($movementSource) && str_contains($movementSource, 'client_balance.facturas_vivas AS pending_invoice_count'));
assert(is_string($movementSource) && str_contains($movementSource, 'MAX(id) AS latest_id'));
assert(is_string($indexSource) && str_contains($indexSource, '$movementClientHasPendingInvoices'));
assert(is_string($indexSource) && str_contains($indexSource, 'Hacer complemento (Sin documentos)'));
assert(is_string($indexSource) && str_contains($indexSource, 'data-action-disabled'));

echo "9 comprobaciones de disponibilidad documental para complementos superadas.\n";
