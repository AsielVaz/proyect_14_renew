<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ComplementEmailService;
use App\PdfDocumentService;

$pdo = new PDO('sqlite::memory:');
$storageRoot = sys_get_temp_dir() . '/s14-complement-email-' . bin2hex(random_bytes(5));
$service = new ComplementEmailService($pdo, new PdfDocumentService($pdo), $storageRoot);
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

$localUuid = '04B8F924-ECD7-4A9E-A899-9265B23036D4';
$localDirectory = $storageRoot . '/123';
assert(mkdir($localDirectory, 0770, true));
$localXml = '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="' . $localUuid . '"/></cfdi:Comprobante>';
assert(file_put_contents($localDirectory . '/XML-complemento-123-' . $localUuid . '.xml', $localXml) !== false);
$systemXmlMethod = $reflection->getMethod('system14Xml');
assert($systemXmlMethod->invoke($service, ['id' => 123, 'uuid' => $localUuid, 'xml_firmado' => '<html>inválido</html>']) === $localXml);
unlink($localDirectory . '/XML-complemento-123-' . $localUuid . '.xml');
rmdir($localDirectory);
rmdir($storageRoot);

echo "11 comprobaciones del correo de complementos superadas.\n";
