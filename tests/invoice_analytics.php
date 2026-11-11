<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $assertions++;
};
$invoiceByFolio = static function (int $folio): array {
    $result = invoice_repository()->page((string) $folio, 1, 100);
    foreach ($result['rows'] as $row) {
        if ((int) ($row['folio'] ?? 0) === $folio) {
            return $row;
        }
    }
    return [];
};

$invalid = $invoiceByFolio(144135);
$assert($invalid !== [], 'encuentra la factura analizada con inconsistencias');
$assert((int) $invalid['id'] === 47376, 'relaciona facturas_analitica por folio y no por ID interno');
$assert($invalid['analysis_result'] === 'con_inconsistencias', 'recupera el resultado negativo');
$errors = json_decode((string) $invalid['analysis_errors'], true);
$assert(is_array($errors) && isset($errors[0]['problema']), 'recupera el problema específico para el tooltip');
$assert(trim((string) $invalid['analysis_summary']) !== '', 'recupera el resumen de la inconsistencia');

$valid = $invoiceByFolio(144148);
$assert($valid !== [], 'encuentra la factura analizada sin inconsistencias');
$assert($valid['analysis_result'] === 'sin_inconsistencias', 'recupera el resultado positivo');
$assert((int) $valid['analysis_id'] === 4570, 'utiliza el registro de análisis esperado');

echo "invoice_analytics: {$assertions} comprobaciones superadas.\n";
