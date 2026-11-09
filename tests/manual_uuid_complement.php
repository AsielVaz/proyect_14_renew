<?php

declare(strict_types=1);

use App\PaymentComplementRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

$previousUser = $_SESSION['auth_user'] ?? null;
$_SESSION['auth_user'] = ['id' => 987654];

$reflection = new ReflectionClass(PaymentComplementRepository::class);
$repository = $reflection->newInstanceWithoutConstructor();
$createToken = $reflection->getMethod('manualLookupToken');
$validateToken = $reflection->getMethod('validManualLookupToken');

$lookupInvoice = ['id' => 321, 'uuid' => 'ABC-123', 'company_id' => 17];
$storedInvoice = ['id' => 321, 'uuid' => 'ABC-123', 'razon' => 17];
$token = $createToken->invoke($repository, $lookupInvoice, 17);

$checks = [
    'token válido para la misma factura y empresa' => $validateToken->invoke($repository, $token, $storedInvoice, 17) === true,
    'otra empresa se rechaza' => $validateToken->invoke($repository, $token, $storedInvoice, 18) === false,
    'firma modificada se rechaza' => $validateToken->invoke($repository, $token . 'x', $storedInvoice, 17) === false,
];

$differentInvoice = $storedInvoice;
$differentInvoice['id'] = 999;
$checks['ID de factura distinto se rechaza'] = $validateToken->invoke($repository, $token, $differentInvoice, 17) === false;

$_SESSION['auth_user'] = ['id' => 987655];
$checks['otro operador se rechaza'] = $validateToken->invoke($repository, $token, $storedInvoice, 17) === false;

$interface = file_get_contents(dirname(__DIR__) . '/index.php');
$checks['la excepción se activa con el evento pegar'] = is_string($interface)
    && str_contains($interface, "uuid.addEventListener('paste'");
$checks['la consulta manual envía empresa y modo'] = is_string($interface)
    && str_contains($interface, "lookupUrl.searchParams.set('lookup_mode', 'manual')")
    && str_contains($interface, "lookupUrl.searchParams.set('company_id', complementCompany.value)");

if ($previousUser === null) {
    unset($_SESSION['auth_user']);
} else {
    $_SESSION['auth_user'] = $previousUser;
}

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Falló la comprobación: ' . $label);
    }
}

echo count($checks) . " comprobaciones de UUID manual superadas.\n";
