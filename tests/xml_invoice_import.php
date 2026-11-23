<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\XmlInvoiceImportService;

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Serie="CW" Folio="474031"
 Fecha="2026-11-23T09:31:31" SubTotal="6000.00" Total="6960.00" Moneda="MXN" MetodoPago="PPD"
 FormaPago="99" TipoDeComprobante="I" LugarExpedicion="44130" NoCertificado="00001000000703478396"
 Certificado="CERTIFICADO" Sello="SELLO">
 <cfdi:Emisor Nombre="FAPA TRANSFERS" RegimenFiscal="601" Rfc="FTR150713AS7"/>
 <cfdi:Receptor Nombre="MOTA-ENGIL MEXICO" Rfc="MME100125RS6" DomicilioFiscalReceptor="11000"
  RegimenFiscalReceptor="601" UsoCFDI="G03"/>
 <cfdi:Conceptos><cfdi:Concepto Cantidad="1" ClaveProdServ="78101805" ClaveUnidad="E48"
  Descripcion="SERVICIO DE RENTA" Importe="6000.00" ValorUnitario="6000.00">
  <cfdi:Impuestos><cfdi:Traslados><cfdi:Traslado Base="6000.00" Importe="960.00" Impuesto="002"
   TasaOCuota="0.160000" TipoFactor="Tasa"/></cfdi:Traslados></cfdi:Impuestos>
 </cfdi:Concepto></cfdi:Conceptos>
 <cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"
  FechaTimbrado="2026-11-23T10:31:33" NoCertificadoSAT="00001000000705928441" SelloCFD="SELLOCFD"
  SelloSAT="SELLOSAT" UUID="9560DCA5-C650-42D3-BEF4-80D91A6DBE92" Version="1.1"/></cfdi:Complemento>
</cfdi:Comprobante>
XML;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon TEXT, rfc TEXT)');
$pdo->exec('CREATE TABLE clientes (id INTEGER PRIMARY KEY, nombre TEXT)');
$pdo->exec('CREATE TABLE claves_cortas (id INTEGER PRIMARY KEY, id_cliente INTEGER, clave_corta TEXT,
    razon_social TEXT, rfc TEXT, cp TEXT, regimen_fiscal INTEGER)');
$pdo->exec('CREATE TABLE metodos_pago (id INTEGER PRIMARY KEY, clave TEXT)');
$pdo->exec('CREATE TABLE formas_pago (id INTEGER PRIMARY KEY, clave INTEGER)');
$pdo->exec('CREATE TABLE catalogo_usos_cfdi (id INTEGER PRIMARY KEY, cod_sat TEXT)');
$pdo->exec('CREATE TABLE catalogo_monedas (id INTEGER PRIMARY KEY, clave_moneda TEXT)');
$pdo->exec('CREATE TABLE facturas (
    id INTEGER PRIMARY KEY AUTOINCREMENT, fecha TEXT, nombre INTEGER, metodo_pago INTEGER, moneda INTEGER,
    forma_pago INTEGER, status_pago TEXT, total_factura REAL, empresa INTEGER, estatus_cert TEXT,
    certificado TEXT, razon INTEGER, tipo_factura TEXT, total_sin_iva REAL, total_iva REAL, token TEXT,
    tipo_comprobante TEXT, uso_cfdi INTEGER, num_certificado TEXT, certificado_fact TEXT, uuid TEXT,
    fecha_timbrado TEXT, hora_timbrado TEXT, sello_cfd TEXT, num_certificado_sat TEXT, sello_sat TEXT,
    complemento TEXT, factura_manual INTEGER, id_clave_corta INTEGER, xml_firmado TEXT, status TEXT,
    folio INTEGER, usuario_crea INTEGER, serie TEXT, retencion_transporte TEXT, xml_total REAL, xml_subtotal REAL
)');
$pdo->exec('CREATE TABLE detalle_factura (
    id INTEGER PRIMARY KEY AUTOINCREMENT, id_factura INTEGER, id_producto INTEGER, cantidad REAL,
    precio_unitario REAL, precio_con_iva REAL, precio_neto REAL, precio_neto_iva REAL, concepto TEXT, iva INTEGER
)');
$pdo->exec("INSERT INTO empresas VALUES (120, 'FAPA TRANSFERS', 'FTR150713AS7')");
$pdo->exec("INSERT INTO clientes VALUES (74, 'MOTA-ENGIL')");
$pdo->exec("INSERT INTO claves_cortas VALUES (894, 74, 'MOTA', 'MOTA-ENGIL MEXICO', 'MME100125RS6', '11000', 601)");
$pdo->exec("INSERT INTO metodos_pago VALUES (2, 'PPD')");
$pdo->exec("INSERT INTO formas_pago VALUES (23, 99)");
$pdo->exec("INSERT INTO catalogo_usos_cfdi VALUES (3, 'G03')");
$pdo->exec("INSERT INTO catalogo_monedas VALUES (1, 'MXN')");

$service = new XmlInvoiceImportService($pdo);
$preview = $service->inspect($xml);
assert($preview['stamp']['uuid'] === '9560DCA5-C650-42D3-BEF4-80D91A6DBE92');
assert(count($preview['companies']) === 1);
assert(count($preview['receivers']) === 1);
assert(count($preview['concepts']) === 1);
assert((float) $preview['total'] === 6960.0);

$invoiceId = $service->save($xml, 120, 894, 1);
$invoice = $pdo->query('SELECT * FROM facturas WHERE id = ' . $invoiceId)->fetch();
assert($invoice['uuid'] === $preview['stamp']['uuid']);
assert((float) $invoice['total_iva'] === 6960.0);
assert((int) $invoice['id_clave_corta'] === 894);
assert((int) $pdo->query('SELECT COUNT(*) FROM detalle_factura WHERE id_factura = ' . $invoiceId)->fetchColumn() === 1);

try {
    $service->save($xml, 120, 894, 1);
    throw new RuntimeException('No se bloqueó el UUID duplicado.');
} catch (RuntimeException $exception) {
    assert(str_contains($exception->getMessage(), 'UUID'));
}

echo "9 comprobaciones de carga XML superadas.\n";
