<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ComplementEmailService;
use App\PdfDocumentService;

$pdo = new PDO('sqlite::memory:');
$service = new ComplementEmailService($pdo, new PdfDocumentService($pdo));
$reflection = new ReflectionClass($service);

$htmlMethod = $reflection->getMethod('html');
$html = $htmlMethod->invoke($service, [
    'empresa' => 'Empresa de prueba',
    'receptor' => 'Cliente de prueba',
    'uuid' => '9560DCA5-C650-42D3-BEF4-80D91A6DBE92',
    'total_factura' => 1250.5,
    'fecha_pago' => '2026-11-23 10:00:00',
], 'P123');
assert(str_contains($html, 'Complemento de pago P123'));
assert(str_contains($html, 'no-reply') === false);
assert(str_contains($html, 'sistema14.org'));
assert(str_contains($html, '@media(max-width:640px)'));
assert(str_contains($html, '$1,250.50'));

$xmlMethod = $reflection->getMethod('isXml');
$uuid = '9560DCA5-C650-42D3-BEF4-80D91A6DBE92';
assert($xmlMethod->invoke($service, '<cfdi:Comprobante UUID="' . $uuid . '"></cfdi:Comprobante>', $uuid) === true);
assert($xmlMethod->invoke($service, '<html>error</html>', $uuid) === false);
assert($reflection->getConstant('FROM_EMAIL') === 'no-reply@sistema14.org');

echo "8 comprobaciones del correo de complementos superadas.\n";
