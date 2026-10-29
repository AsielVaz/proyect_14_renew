<?php

declare(strict_types=1);

use App\CatalogRepository;
use App\ReportRepository;
use App\MovementRepository;
use App\InvoiceRepository;
use App\DashboardRepository;
use App\PaymentComplementRepository;
use App\CompanyPanelRepository;
use App\CaptureAnalysisService;

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
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $configuredPath = (string) (parse_url(env_value('APP_URL'), PHP_URL_PATH) ?: '/');
    $configuredPath = rtrim($configuredPath, '/') . '/';
    $httpsRequest = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $secureCookie = filter_var(env_value('SESSION_SECURE_COOKIE', $httpsRequest ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    session_name(env_value('SESSION_NAME', 'proyect_14_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $configuredPath,
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set(env_value('APP_TIMEZONE', 'America/Mexico_City'));

function current_operator_id(): int
{
    $operatorId = (int) ($_SESSION['auth_user']['id'] ?? 0);
    if ($operatorId <= 0) {
        throw new RuntimeException('La sesión autenticada no está disponible.');
    }
    return $operatorId;
}

function current_user(): array
{
    return is_array($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : [];
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

function legacy_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#^https?://[^/]+/#i', '', $path) ?? $path;
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'sistema/')) {
        $path = substr($path, strlen('sistema/'));
    }
    $base = rtrim(env_value('APP_URL'), '/');
    $parts = parse_url($base);
    $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    $applicationPath = rtrim((string) ($parts['path'] ?? ''), '/');
    $parentPath = $applicationPath === '' ? '' : rtrim(str_replace('\\', '/', dirname($applicationPath)), '/.');
    $segments = array_values(array_filter(explode('/', preg_replace('#/+#', '/', $path) ?? $path), 'strlen'));
    return $origin . ($parentPath !== '' ? '/' . ltrim($parentPath, '/') : '')
        . '/sistema/' . implode('/', array_map('rawurlencode', $segments));
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

function payment_complement_repository(): PaymentComplementRepository
{
    static $repository;
    return $repository ??= new PaymentComplementRepository(db());
}

function company_panel_repository(): CompanyPanelRepository
{
    static $repository;
    return $repository ??= new CompanyPanelRepository(db());
}

function capture_analysis_service(): CaptureAnalysisService
{
    static $service;
    return $service ??= new CaptureAnalysisService(db(), company_panel_repository());
}

function dashboard_repository(): DashboardRepository
{
    static $repository;
    return $repository ??= new DashboardRepository(db());
}

function auth_service(): App\AuthService
{
    static $service;
    return $service ??= new App\AuthService(db());
}

function auth_rate_limiter(): App\AuthRateLimiter
{
    static $limiter;
    return $limiter ??= new App\AuthRateLimiter(
        env_value('APP_KEY'),
        max(3, (int) env_value('AUTH_MAX_ATTEMPTS', '5')),
        max(60, (int) env_value('AUTH_LOCK_SECONDS', '900')),
    );
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

function csp_nonce(): string
{
    static $nonce;
    return $nonce ??= base64_encode(random_bytes(18));
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, private, max-age=0');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; object-src \'none\'; img-src \'self\' data:; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'nonce-' . csp_nonce() . '\'; connect-src \'self\'');
}
