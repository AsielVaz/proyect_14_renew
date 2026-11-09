<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$cases = [
    [['2026-11-09', false], 'Lunes 09 de Noviembre del 2026'],
    [['2026-11-05', false], 'Jueves 05 de Noviembre del 2026'],
    [['2026-11-05 15:40:09', true], 'Jueves 05 de Noviembre del 2026 · 15:40'],
    [['2026-11-05 00:00:00', true], 'Jueves 05 de Noviembre del 2026'],
    [['0000-00-00', false], '—'],
    [['', true], '—'],
];

foreach ($cases as [[$value, $includeTime], $expected]) {
    $actual = display_date($value, $includeTime);
    if ($actual !== $expected) {
        throw new RuntimeException("Se esperaba '{$expected}' y se obtuvo '{$actual}'.");
    }
}

echo count($cases) . " comprobaciones de formato de fecha superadas.\n";
