<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\AutomaticInvoiceService;

$companyId = (int) db()->query("SELECT CAST(razon AS UNSIGNED)
    FROM conceptos
    WHERE LOWER(TRIM(CAST(facturable_auto AS CHAR))) IN ('si', 'sí', '1', 'true', 'yes')
      AND precio_min > 0 AND precio_max > 0 AND unidades_max > 0
    LIMIT 1")->fetchColumn();
if ($companyId <= 0) {
    throw new RuntimeException('No hay empresas con conceptos automáticos para ejecutar la comprobación.');
}

$query = db()->prepare("SELECT id, concepto, precio_min, precio_max, unidades_max, aplica_iva, impuesto
    FROM conceptos
    WHERE CAST(razon AS UNSIGNED) = :company
      AND LOWER(TRIM(CAST(facturable_auto AS CHAR))) IN ('si', 'sí', '1', 'true', 'yes')
      AND precio_min > 0 AND precio_max > 0 AND unidades_max > 0");
$query->execute(['company' => $companyId]);
$concepts = $query->fetchAll();
if ($concepts === []) {
    throw new RuntimeException('No hay conceptos automáticos para ejecutar la comprobación.');
}

$service = new AutomaticInvoiceService(db());
$builder = new ReflectionMethod($service, 'buildDetails');
foreach ([50.00, 200000.00, 335708.14] as $amount) {
    $details = $builder->invoke($service, $amount, $concepts);
    if ($details === []) {
        throw new RuntimeException('La factura automática no generó conceptos.');
    }
    $gross = round(array_sum(array_column($details, 'gross')), 2);
    if (abs($gross - $amount) > 0.001) {
        throw new RuntimeException("El total automático {$gross} no coincide con {$amount}.");
    }
    foreach ($details as $detail) {
        if ((int) $detail['product'] <= 0 || (float) $detail['quantity'] <= 0 || (float) $detail['gross'] <= 0) {
            throw new RuntimeException('Se generó una partida automática inválida.');
        }
    }
}

echo "Planeación de facturas automáticas verificada sin modificar la base de datos.\n";
