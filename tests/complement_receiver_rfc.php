<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/index.php');
if ($source === false) {
    throw new RuntimeException('No fue posible leer el formulario de complementos.');
}

$checks = [
    "\$item['razon_social'] . ' · ' . (\$item['rfc'] ?: 'RFC no registrado')" =>
        'la razón social precargada desde un pago muestra el RFC',
    'option.textContent = `${item.rfc} · ${item.razon_social} · ${item.clave_corta}${isComplementReceiver' =>
        'el catálogo dinámico de razones sociales conserva el RFC',
    'option.disabled = !hasLiveDocuments;' =>
        'el catálogo dinámico desactiva las razones sociales sin documentos vivos',
    "unavailableReceiverGroup.label = 'Sin documentos vivos para esta empresa · No disponibles';" =>
        'el catálogo dinámico separa visualmente las razones sociales no disponibles',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
}

echo count($checks) . " comprobaciones de RFC en razón social superadas.\n";
