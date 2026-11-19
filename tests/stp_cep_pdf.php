<?php

declare(strict_types=1);

putenv('LEGACY_CEP_ENCRYPTION_KEY=Encr10h-.$=2023SecretoMuajaaja');
putenv('LEGACY_CEP_PDF_URL=https://old.sistema14.com/gen_cep_pdf-directo.php');

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$paymentId = 568622;
$url = legacy_cep_pdf_url($paymentId);
$query = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
$token = (string) ($query['pago'] ?? '');
$decoded = base64_decode($token, true);

if ($decoded === false) {
    throw new RuntimeException('El token del PDF STP no es Base64 válido.');
}

$cipher = 'aes-256-cbc';
$ivLength = openssl_cipher_iv_length($cipher);
$decrypted = openssl_decrypt(
    substr($decoded, $ivLength),
    $cipher,
    'Encr10h-.$=2023SecretoMuajaaja',
    0,
    substr($decoded, 0, $ivLength)
);

if ($decrypted !== (string) $paymentId) {
    throw new RuntimeException('El token no es compatible con desencriptar() del sistema anterior.');
}

$view = file_get_contents($root . '/index.php');
$script = file_get_contents($root . '/assets/stp-documents.js');
if (!is_string($view)
    || !str_contains($view, 'data-stp-cep-pdf')
    || !str_contains($view, "movementKey === 'stp'")) {
    throw new RuntimeException('Movimientos STP no incluye la apertura conjunta de Banxico y PDF.');
}
if (!is_string($script)
    || !str_contains($script, "window.open('about:blank', '_blank')")
    || !str_contains($script, 'pdfWindow.location.replace(pdfUrl)')) {
    throw new RuntimeException('El controlador de documentos STP no abre el PDF directo.');
}

echo "PDF directo de movimientos STP: ok\n";
