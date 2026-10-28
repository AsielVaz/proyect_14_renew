<?php

declare(strict_types=1);

use App\CatalogRepository;
use App\ReportRepository;
use App\MovementRepository;
use App\InvoiceRepository;
use App\DashboardRepository;

const APP_ROOT = __DIR__ . '/..';

function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No se encontró el archivo de configuración .env.');
    }

    $values = parse_ini_file($path, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('No fue posible leer el archivo de configuración .env.');
    }

    foreach ($values as $key => $value) {
        if (!is_string($key) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $value = (string) $value;
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env_value(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false) {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException("Falta la variable {$key} en el archivo .env.");
    }
    return (string) $value;
}

load_env(APP_ROOT . '/.env');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(env_value('SESSION_NAME', 'proyect_14_session'));
    session_start();
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'America/Mexico_City'));

function current_operator_id(): int
{
    // Sustituir por la cookie/sesión autenticada cuando se integre el login.
    return (int) env_value('APP_OPERATOR_ID', '1');
}

function app_url(string $path = ''): string
{
    $base = rtrim(env_value('APP_URL'), '/');
    if ($path === '') {
        return $base;
    }
    if (str_starts_with($path, '?')) {
        return $base . '/' . $path;
    }
    return $base . '/' . ltrim($path, '/');
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require APP_ROOT . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function repository(): CatalogRepository
{
    static $repository;
    return $repository ??= new CatalogRepository(db());
}

function report_repository(): ReportRepository
{
    static $repository;
    return $repository ??= new ReportRepository(db());
}

function movement_repository(): MovementRepository
{
    static $repository;
    return $repository ??= new MovementRepository(db());
}

function invoice_repository(): InvoiceRepository
{
    static $repository;
    return $repository ??= new InvoiceRepository(db());
}

function dashboard_repository(): DashboardRepository
{
    static $repository;
    return $repository ??= new DashboardRepository(db());
}

function dashboard_overview(int $operatorId): array
{
    $ttl = max(0, (int) env_value('DASHBOARD_CACHE_SECONDS', '30'));
    $cacheKey = 'dashboard_overview_' . $operatorId;
    $cached = $_SESSION[$cacheKey] ?? null;
    if ($ttl > 0 && is_array($cached) && (int) ($cached['expires'] ?? 0) >= time() && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }

    $data = dashboard_repository()->overview($operatorId);
    if ($ttl > 0) {
        $_SESSION[$cacheKey] = ['expires' => time() + $ttl, 'data' => $data];
    }
    return $data;
}

function report_value(mixed $value, string $format = 'text'): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return match ($format) {
        'money' => '$' . number_format((float) $value, 2),
        'percent' => number_format((float) $value, 2) . '%',
        default => (string) $value,
    };
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo otra vez.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = compact('type', 'message');
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function redirect(string $url): never
{
    if (!preg_match('#^https?://#i', $url)) {
        $url = app_url($url);
    }
    header('Location: ' . $url);
    exit;
}
