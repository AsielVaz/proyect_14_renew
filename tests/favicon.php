<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expected = [
    'assets/favicon-32.png' => [32, 32],
    'assets/favicon-192.png' => [192, 192],
    'assets/favicon-512.png' => [512, 512],
    'assets/apple-touch-icon.png' => [180, 180],
];

foreach ($expected as $relative => $dimensions) {
    $path = $root . '/' . $relative;
    $actual = is_file($path) ? getimagesize($path) : false;
    if (!is_array($actual) || [$actual[0], $actual[1]] !== $dimensions) {
        throw new RuntimeException("El recurso {$relative} no existe o no tiene las dimensiones esperadas.");
    }
}

if (!is_file($root . '/assets/favicon.ico') || filesize($root . '/assets/favicon.ico') < 1000) {
    throw new RuntimeException('El favicon ICO no se generó correctamente.');
}

foreach (['index.php', 'login.php'] as $view) {
    $source = file_get_contents($root . '/' . $view);
    if (!is_string($source)
        || !str_contains($source, 'assets/favicon.ico?v=20261119')
        || !str_contains($source, 'assets/favicon-32.png?v=20261119')
        || !str_contains($source, 'assets/apple-touch-icon.png?v=20261119')) {
        throw new RuntimeException("La vista {$view} no incluye todos los iconos del sitio.");
    }
}

echo "favicon: ok\n";
