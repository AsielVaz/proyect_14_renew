<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\PaymentComplementRepository;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE claves_cortas (
    id INTEGER PRIMARY KEY,
    id_cliente INTEGER NOT NULL,
    clave_corta TEXT,
    razon_social TEXT,
    rfc TEXT,
    cp TEXT,
    regimen_fiscal TEXT,
    documentos_vivos INTEGER NULL
)');
$pdo->exec("INSERT INTO claves_cortas VALUES
    (11, 1, 'VIVA', 'Razón con documentos', 'RFC010101AAA', '01000', '601', 5),
    (12, 1, 'CERO', 'Razón sin documentos', 'RFC010101AAB', '01000', '601', 0),
    (21, 2, 'NULL', 'Razón sin cálculo', 'RFC010101AAC', '01000', '601', NULL)");

$repository = new PaymentComplementRepository($pdo);
$method = (new ReflectionClass($repository))->getMethod('pendingInvoiceCount');
assert($method->invoke($repository, 1) === 5);
assert($method->invoke($repository, 2) === 0);
assert($method->invoke($repository, 3) === 0);
assert($method->invoke($repository, 999) === 0);

$receivers = $repository->receivers(1);
assert(count($receivers) === 2);
$receiversById = [];
foreach ($receivers as $receiver) $receiversById[(int) $receiver['id']] = $receiver;
assert(($receiversById[11]['has_live_documents'] ?? false) === true);
assert(($receiversById[12]['has_live_documents'] ?? true) === false);

$receiverValidation = (new ReflectionClass($repository))->getMethod('assertReceiverHasLiveDocuments');
$receiverValidation->invoke($repository, 1, 11);
$blocked = false;
try {
    $receiverValidation->invoke($repository, 1, 12);
} catch (RuntimeException $exception) {
    $blocked = str_contains($exception->getMessage(), 'Sin documentos');
}
assert($blocked);

$movementSource = file_get_contents(dirname(__DIR__) . '/src/MovementRepository.php');
$indexSource = file_get_contents(dirname(__DIR__) . '/index.php');
assert(is_string($movementSource) && str_contains($movementSource, 'client_balance.facturas_vivas AS pending_invoice_count'));
assert(is_string($movementSource) && str_contains($movementSource, 'SUM(COALESCE(documentos_vivos, 0)) AS facturas_vivas'));
assert(is_string($movementSource) && !str_contains($movementSource, 'FROM clientes_saldo_script balances'));
assert(is_string($indexSource) && str_contains($indexSource, '$movementClientHasPendingInvoices'));
assert(is_string($indexSource) && str_contains($indexSource, 'Hacer complemento (Sin documentos)'));
assert(is_string($indexSource) && str_contains($indexSource, 'data-action-disabled'));
assert(is_string($indexSource) && str_contains($indexSource, 'Sin documentos vivos'));

echo "15 comprobaciones de disponibilidad documental para complementos superadas.\n";
