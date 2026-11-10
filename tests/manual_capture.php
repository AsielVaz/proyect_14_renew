<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CaptureAnalysisService;
use App\Movements;

$definition = Movements::get('capturas-manuales');
if (($definition['action'] ?? '') !== 'manual_capture') {
    throw new RuntimeException('El módulo de capturas manuales no está registrado en Movimientos.');
}

$decimalCases = [
    ['$1,234.56', false, 1234.56],
    ['200000', false, 200000.0],
    ['-18.40', true, -18.4],
    ['0', true, 0.0],
];
foreach ($decimalCases as [$value, $allowNegative, $expected]) {
    $actual = CaptureAnalysisService::manualDecimal($value, $allowNegative, 'Importe');
    if ($actual !== $expected) {
        throw new RuntimeException('No se normalizó correctamente un importe de captura manual.');
    }
}

$invalid = false;
try {
    CaptureAnalysisService::manualDecimal('0', false, 'Monto');
} catch (RuntimeException) {
    $invalid = true;
}
if (!$invalid) {
    throw new RuntimeException('La captura manual aceptó un monto igual a cero.');
}

$catalog = capture_analysis_service()->manualCatalog(1);
if (!is_array($catalog)) {
    throw new RuntimeException('El catálogo de empresas para captura manual no es válido.');
}
foreach ($catalog as $company) {
    if ((int) ($company['id'] ?? 0) <= 0 || trim((string) ($company['name'] ?? '')) === '') {
        throw new RuntimeException('El catálogo de captura manual contiene una empresa inválida.');
    }
    foreach ($company['accounts'] ?? [] as $account) {
        if ((int) ($account['id'] ?? 0) <= 0 || !array_key_exists('balance', $account)) {
            throw new RuntimeException('El catálogo de captura manual contiene una cuenta inválida.');
        }
    }
}

$page = capture_analysis_service()->manualPage(1, 1, 25);
foreach (['rows', 'page', 'perPage', 'total', 'pages', 'hasNext'] as $key) {
    if (!array_key_exists($key, $page)) {
        throw new RuntimeException('El historial de capturas manuales no devolvió la estructura esperada.');
    }
}

foreach (['all', 'deleted'] as $section) {
    $history = capture_analysis_service()->capturePage($section, 1, 1, 25, '');
    foreach (['rows', 'page', 'perPage', 'total', 'pages', 'hasNext'] as $key) {
        if (!array_key_exists($key, $history)) {
            throw new RuntimeException('El historial de capturas no devolvió la estructura esperada para ' . $section . '.');
        }
    }
}

$view = file_get_contents(dirname(__DIR__) . '/views/manual-captures.php');
if ($view === false || !str_contains($view, 'account.replaceChildren')
    || !str_contains($view, 'nonce="<?= e(csp_nonce()) ?>"')
    || str_contains($view, 'data-company-id=')) {
    throw new RuntimeException('El selector de cuentas no reconstruye las opciones al cambiar de empresa.');
}

echo "manual_capture: ok\n";
