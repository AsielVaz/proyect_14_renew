<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\LocalComplementStampService;
use App\PdfDocumentService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('CREATE TABLE empresas (
    id INTEGER PRIMARY KEY, razon TEXT, rfc TEXT, cp TEXT, regimen TEXT, usuario TEXT, password_r TEXT
)');
$pdo->exec('CREATE TABLE claves_cortas (
    id INTEGER PRIMARY KEY, razon_social TEXT, rfc TEXT, cp TEXT, regimen_fiscal TEXT
)');
$pdo->exec('CREATE TABLE formas_pago (id INTEGER PRIMARY KEY, clave TEXT)');
$pdo->exec('CREATE TABLE facturas_complementos (
    id INTEGER PRIMARY KEY, razon INTEGER, id_clave_corta INTEGER, forma_pago INTEGER,
    serie TEXT, folio INTEGER, fecha_pago TEXT, total_factura NUMERIC, cadena TEXT, uuid TEXT
)');
$pdo->exec('CREATE TABLE facturar_rel_complementos (
    id INTEGER PRIMARY KEY, id_complemento INTEGER, uuid_rel TEXT, monto NUMERIC,
    num_pago INTEGER, saldo NUMERIC, restante NUMERIC, folio INTEGER, serie TEXT
)');

$pdo->exec("INSERT INTO empresas VALUES
    (1, 'FAPA TRANSFERS', 'FTR150713AS7', '44130', '626', 'api-user', 'api-password')");
$pdo->exec("INSERT INTO claves_cortas VALUES
    (2, 'MIVE CONSTRUCCIONES', 'MCO981119D77', '44920', '601')");
$pdo->exec("INSERT INTO formas_pago VALUES (3, '03')");
$pdo->exec("INSERT INTO facturas_complementos VALUES
    (10, 1, 2, 3, 'P', 422, '2026-08-18 16:00:00', 37996.80, '4', '')");
$pdo->exec("INSERT INTO facturar_rel_complementos VALUES
    (1, 10, '49C3707A-D9E2-43AF-B2C9-91E3EBAC234D', 37996.80, 1,
     146545.28, 108548.48, 842206, 'UI')");

$service = new LocalComplementStampService($pdo, new PdfDocumentService($pdo), sys_get_temp_dir() . '/s14-complement-test');
$xml = $service->buildUnsignedXml(10, new DateTimeImmutable('2026-10-08 16:01:14'));
$document = new DOMDocument();
assert($document->loadXML($xml, LIBXML_NONET));
$xpath = new DOMXPath($document);

$node = static function (string $name) use ($xpath): DOMElement {
    $result = $xpath->query('//*[local-name()="' . $name . '"]')?->item(0);
    assert($result instanceof DOMElement, 'Falta el nodo ' . $name);
    return $result;
};

$totals = $node('Totales');
$payment = $node('Pago');
$related = $node('DoctoRelacionado');
$transfer = $node('TrasladoDR');
$withholding = $node('RetencionDR');

assert($totals->getAttribute('MontoTotalPagos') === '37996.80');
assert($totals->getAttribute('TotalTrasladosBaseIVA16') === '33925.71');
assert($totals->getAttribute('TotalTrasladosImpuestoIVA16') === '5428.11');
assert($totals->getAttribute('TotalRetencionesIVA') === '1357.03');
assert($payment->getAttribute('Monto') === '37996.80');
assert($payment->getAttribute('FechaPago') === '2026-08-18T16:00:00');
assert($related->getAttribute('ImpPagado') === '37996.80');
assert($related->getAttribute('ObjetoImpDR') === '02');
assert($transfer->getAttribute('BaseDR') === '33925.714286');
assert($transfer->getAttribute('ImporteDR') === '5428.114286');
assert($withholding->getAttribute('BaseDR') === '33925.714286');
assert($withholding->getAttribute('ImporteDR') === '1357.028571');
assert(abs(33925.714286 + 5428.114286 - 1357.028571 - 37996.80) < 0.00001);

$typical = LocalComplementStampService::inverseTaxes(116.00, 'T');
assert($typical['base'] === 100.0 && $typical['vat'] === 16.0 && $typical['retention'] === 0.0);
$zero = LocalComplementStampService::inverseTaxes(100.00, 'T0');
assert($zero['base'] === 100.0 && $zero['vat'] === 0.0 && $zero['taxable'] === true);
$withoutVat = LocalComplementStampService::inverseTaxes(100.00, 'SV');
assert($withoutVat['base'] === 100.0 && $withoutVat['taxable'] === false);

echo "19 comprobaciones del timbrado local de complementos superadas.\n";
