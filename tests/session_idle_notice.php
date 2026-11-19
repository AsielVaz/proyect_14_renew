<?php

declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    return [
        'APP_KEY' => 'session-idle-notice-test-key',
        'SESSION_IDLE_SECONDS' => '1800',
        'SESSION_ABSOLUTE_SECONDS' => '28800',
        'TRUST_PROXY' => 'false',
    ][$key] ?? $default;
}

final class IdleNoticeStatement
{
    public function execute(array $parameters = []): bool
    {
        return true;
    }
}

final class IdleNoticeDatabase
{
    public function prepare(string $query): IdleNoticeStatement
    {
        return new IdleNoticeStatement();
    }
}

function db(): IdleNoticeDatabase
{
    static $database;
    return $database ??= new IdleNoticeDatabase();
}

$_SERVER['HTTP_USER_AGENT'] = 'Sistema14 idle notice test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
session_id('s14idle' . bin2hex(random_bytes(8)));
session_start();

require dirname(__DIR__) . '/src/auth.php';

$now = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['auth_user'] = [
    'id' => 1,
    'email' => 'prueba@example.com',
    'method' => 'password',
    'ua_hash' => auth_user_agent_hash(),
    'last_activity' => $now - 1900,
    'authenticated_at' => $now - 2000,
    'rotated_at' => $now - 1900,
];

auth_validate_session();

$notice = (string) ($_SESSION['auth_notice'] ?? '');
if ($notice !== 'Tu sesión terminó después de 31 minutos de inactividad. Vuelve a ingresar.') {
    throw new RuntimeException('El aviso no muestra correctamente los minutos de inactividad: ' . $notice);
}
if (isset($_SESSION['auth_user']) || isset($_SESSION['csrf_token'])) {
    throw new RuntimeException('La sesión vencida debe eliminar la identidad y el token CSRF.');
}

// Si PHP ya eliminó la sesión antes de la siguiente navegación, la marca firmada
// debe conservar el instante necesario para mostrar el mismo aviso en el login.
unset($_SESSION['auth_notice']);
$_COOKIE[auth_idle_marker_name()] = auth_idle_marker_value($now - 1900);
$markerNotice = auth_idle_notice_from_marker($now);
if ($markerNotice !== 'Tu sesión terminó después de 31 minutos de inactividad. Vuelve a ingresar.') {
    throw new RuntimeException('La marca de actividad no recuperó el aviso tras perderse la sesión: ' . $markerNotice);
}

$parts = explode('.', (string) $_COOKIE[auth_idle_marker_name()], 2);
$_COOKIE[auth_idle_marker_name()] = ((int) $parts[0] - 600) . '.' . ($parts[1] ?? '');
if (auth_idle_notice_from_marker($now) !== '') {
    throw new RuntimeException('Una marca de actividad alterada no debe aceptarse.');
}

session_destroy();
echo "session_idle_notice: ok\n";
