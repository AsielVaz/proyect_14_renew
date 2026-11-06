<?php

declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    return [
        'APP_KEY' => 'session-rotation-test-key',
        'SESSION_IDLE_SECONDS' => '1800',
        'SESSION_ABSOLUTE_SECONDS' => '28800',
    ][$key] ?? $default;
}

$_SERVER['HTTP_USER_AGENT'] = 'Sistema14 session rotation test';
session_id('s14rotation' . bin2hex(random_bytes(8)));
session_start();

require dirname(__DIR__) . '/src/auth.php';

$now = time();
$token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $token;
$_SESSION['auth_user'] = [
    'id' => 1,
    'ua_hash' => auth_user_agent_hash(),
    'last_activity' => $now - 10,
    'authenticated_at' => $now - 1000,
    'rotated_at' => $now - 901,
];

auth_validate_session();

if (!hash_equals($token, (string) ($_SESSION['csrf_token'] ?? ''))) {
    throw new RuntimeException('La rotación de sesión invalidó el formulario abierto.');
}
if ((int) ($_SESSION['auth_user']['rotated_at'] ?? 0) < $now) {
    throw new RuntimeException('El identificador de sesión no se renovó.');
}
if ((int) ($_SESSION['auth_user']['last_activity'] ?? 0) < $now) {
    throw new RuntimeException('La actividad de sesión no se actualizó.');
}

session_destroy();
echo "Rotación de sesión verificada sin invalidar el formulario.\n";
