<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\PaymentComplementRepository;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE conceptos (id INTEGER PRIMARY KEY, aplica_iva TEXT)');
$pdo->exec('CREATE TABLE detalle_factura (id_factura INTEGER, id_producto INTEGER, iva REAL)');
$pdo->exec("INSERT INTO conceptos VALUES (1, 'si'), (2, 'no')");
$pdo->exec('INSERT INTO detalle_factura VALUES (101, 1, 0), (102, 2, 0), (103, 1, 16)');

$repository = new PaymentComplementRepository($pdo);
$method = (new ReflectionClass($repository))->getMethod('invoiceTaxTreatment');
$xml = static fn (string $taxes, string $object = '02'): string => '<?xml version="1.0" encoding="UTF-8"?>'
    . '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
    . '<cfdi:Conceptos><cfdi:Concepto ObjetoImp="' . $object . '"><cfdi:Impuestos>' . $taxes
    . '</cfdi:Impuestos></cfdi:Concepto></cfdi:Conceptos></cfdi:Comprobante>';
$transfer = static fn (string $rate): string => '<cfdi:Traslados><cfdi:Traslado Impuesto="002" TipoFactor="Tasa" TasaOCuota="'
    . $rate . '" Base="100.00" Importe="0.00"/></cfdi:Traslados>';

assert($method->invoke($repository, ['xml_firmado' => $xml($transfer('0.160000'))]) === 'T');
assert($method->invoke($repository, ['xml_firmado' => $xml($transfer('0.000000'))]) === 'T0');
assert($method->invoke($repository, ['xml_firmado' => $xml('', '01')]) === 'SV');
assert($method->invoke($repository, ['xml_firmado' => $xml($transfer('0.160000')
    . '<cfdi:Retenciones><cfdi:Retencion Impuesto="002" Importe="4.00"/></cfdi:Retenciones>')]) === '4');
assert($method->invoke($repository, ['retencion_transporte' => 'Si']) === '4');
assert($method->invoke($repository, [
    'retencion_transporte' => 'Si',
    'xml_firmado' => $xml($transfer('0.160000')),
]) === 'T');
assert($method->invoke($repository, ['id' => 101]) === 'T0');
assert($method->invoke($repository, ['id' => 102]) === 'SV');
assert($method->invoke($repository, ['id' => 103]) === 'T');

$interface = file_get_contents(dirname(__DIR__) . '/index.php');
assert(is_string($interface) && str_contains($interface, '<th>Saldo restante</th><th>Tratamiento fiscal</th>'));
assert(!str_contains($interface, 'id="complementTreatment"'));
assert(!str_contains($interface, 'id="viewPendingInvoices"') && !str_contains($interface, 'id="addComplementInvoice"'));
assert(is_string($interface) && str_contains($interface, 'row.dataset.taxTreatment = taxTreatment'));
assert(is_string($interface) && str_contains($interface, 'Crea complementos separados'));
assert(is_string($interface) && !str_contains($interface, 'complementClient.value = targetClientId'));
assert(is_string($interface) && !str_contains($interface, 'complementReceiver.value = targetReceiverId'));

echo "16 comprobaciones de herencia fiscal en complementos superadas.\n";
