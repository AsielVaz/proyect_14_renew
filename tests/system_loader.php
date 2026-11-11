<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$css = file_get_contents($root . '/assets/app.css');
if ($index === false || $css === false) {
    throw new RuntimeException('No fue posible leer los archivos del loader.');
}

$checks = [
    [$index, 'id="systemLoader"', 'existe el loader global'],
    [$index, 'class="system-loader-emblem"', 'incluye el emblema visual de Sistema 14'],
    [$index, "params.has('invoice')", 'personaliza la espera de facturación'],
    [$index, "params.has('movement')", 'personaliza la espera de movimientos'],
    [$index, "params.has('report')", 'personaliza la espera de reportes'],
    [$index, "{delay: 180}", 'evita parpadeos en cargas rápidas'],
    [$index, "window.addEventListener('pageshow', hideSystemLoader)", 'retira el loader al restaurar una página'],
    [$css, '.system-loader-progress', 'incluye progreso visual temático'],
    [$css, '@media (prefers-reduced-motion: reduce)', 'respeta movimiento reducido'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
}

echo 'system_loader: ' . count($checks) . " comprobaciones superadas.\n";
