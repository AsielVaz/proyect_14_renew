<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path = tempnam(sys_get_temp_dir(), 's14-env-test-');
if ($path === false) {
    throw new RuntimeException('No fue posible crear el archivo temporal de prueba.');
}

try {
    file_put_contents($path, <<<'ENV'
# Comentario con paréntesis (opcional)
S14_TEST_PLAIN=value(with-parentheses)#and-symbols
S14_TEST_DOUBLE="Sistema 14 (pruebas)"
S14_TEST_SINGLE='secret(value)'
S14_TEST_EMPTY=
ENV);
    load_env($path);

    $expected = [
        'S14_TEST_PLAIN' => 'value(with-parentheses)#and-symbols',
        'S14_TEST_DOUBLE' => 'Sistema 14 (pruebas)',
        'S14_TEST_SINGLE' => 'secret(value)',
        'S14_TEST_EMPTY' => '',
    ];
    foreach ($expected as $key => $value) {
        if (getenv($key) !== $value) {
            throw new RuntimeException('La prueba del lector .env falló para ' . $key . '.');
        }
    }
    echo "4 comprobaciones del lector .env superadas.\n";
} finally {
    @unlink($path);
}
