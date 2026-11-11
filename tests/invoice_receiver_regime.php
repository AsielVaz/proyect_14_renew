<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$source = file_get_contents(dirname(__DIR__) . '/index.php');
if ($source === false) {
    throw new RuntimeException('No fue posible leer el formulario de facturación.');
}

$checks = [
    'id="invoiceReceiverRegime"' => 'el formulario muestra el régimen fiscal del receptor',
    'option.dataset.regime = item.regimen_fiscal' => 'el catálogo conserva el régimen de la clave corta',
    "invoiceReceiver?.addEventListener('change', updateInvoiceReceiverRegime)" => 'la selección actualiza el régimen inmediatamente',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
}

$receiver = null;
foreach (invoice_repository()->receivers(1) as $candidate) {
    if (trim((string) ($candidate['regimen_fiscal'] ?? '')) !== '') {
        $receiver = $candidate;
        break;
    }
}
if ($receiver === null) {
    $receiver = db()->query("SELECT id_cliente FROM claves_cortas WHERE regimen_fiscal IS NOT NULL AND regimen_fiscal <> '' LIMIT 1")->fetch();
    if ($receiver) {
        foreach (invoice_repository()->receivers((int) $receiver['id_cliente']) as $candidate) {
            if (trim((string) ($candidate['regimen_fiscal'] ?? '')) !== '') {
                $receiver = $candidate;
                break;
            }
        }
    }
}
if (!is_array($receiver) || trim((string) ($receiver['regimen_fiscal'] ?? '')) === '') {
    throw new RuntimeException('FALLÓ: el catálogo no devuelve el régimen fiscal almacenado.');
}

echo "invoice_receiver_regime: 4 comprobaciones superadas.\n";
