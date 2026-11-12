<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/PdfDocumentService.php';

use App\PdfDocumentService;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

$service = (new ReflectionClass(PdfDocumentService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(PdfDocumentService::class, 'satVerificationUrl');

$url = $method->invoke($service, [
    'uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
    'empresa_rfc' => 'AAA010101AAA',
    'receptor_rfc' => 'XAXX010101000',
    'xml_total' => 1234.5,
    'sello_cfd' => 'SELLO-ABC12345',
]);

$expected = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?'
    . 'id=A1B2C3D4-E5F6-7890-ABCD-EF1234567890'
    . '&re=AAA010101AAA'
    . '&rr=XAXX010101000'
    . '&tt=1234.500000'
    . '&fe=ABC12345';

$checks = [
    'usa el portal oficial de verificación del SAT' => $url === $expected,
    'incluye el UUID' => str_contains($url, 'id=A1B2C3D4-E5F6-7890-ABCD-EF1234567890'),
    'incluye los RFC emisor y receptor' => str_contains($url, 're=AAA010101AAA&rr=XAXX010101000'),
    'incluye el total y los últimos ocho caracteres del sello' => str_contains($url, 'tt=1234.500000&fe=ABC12345'),
];

$qr = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8');
$checks['la URL puede codificarse en un QR real'] = $qr->getMatrix()->getWidth() > 0;

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('Falló la comprobación: ' . $label);
    }
}

echo count($checks) . " comprobaciones del QR de factura superadas.\n";
