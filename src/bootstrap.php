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
use App\AutomaticInvoiceService;
use App\FiscalDocumentService;
use App\StatisticsRepository;
use App\GlobalSearchRepository;
use App\ManualEmailService;
use App\PdfDocumentService;
use App\InvoiceEmailService;
use App\XlsxExportService;

const APP_ROOT = __DIR__ . '/..';

function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('No se encontró el archivo de configuración .env.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('No fue posible leer el archivo de configuración .env.');
    }

    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException('La línea ' . ($index + 1) . ' del archivo .env no tiene un formato válido.');
        }
        $key = trim(substr($line, 0, $separator));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new RuntimeException('La variable de la línea ' . ($index + 1) . ' del archivo .env no es válida.');
        }

        $value = trim(substr($line, $separator + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (strlen($value) < 2 || substr($value, -1) !== $quote) {
                throw new RuntimeException('La variable ' . $key . ' tiene comillas sin cerrar en el archivo .env.');
            }
            $value = substr($value, 1, -1);
            $value = str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $value);
        }

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

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

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
    $configuredPath = env_value('SESSION_COOKIE_PATH', '');
    if ($configuredPath === '') {
        $configuredPath = (string) (parse_url(env_value('APP_URL'), PHP_URL_PATH) ?: '/');
        $configuredPath = rtrim($configuredPath, '/') . '/';
    }
    $httpsRequest = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $secureCookie = filter_var(env_value('SESSION_SECURE_COOKIE', $httpsRequest ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    session_name(env_value('SESSION_NAME', 'proyect_14_session'));
    session_set_cookie_params([
        'lifetime' => max(0, (int) env_value('SESSION_LIFETIME', '0')),
        'path' => $configuredPath,
        'domain' => env_value('SESSION_COOKIE_DOMAIN', ''),
        'secure' => $secureCookie,
        'httponly' => filter_var(env_value('SESSION_COOKIE_HTTPONLY', 'true'), FILTER_VALIDATE_BOOL),
        'samesite' => in_array(env_value('SESSION_COOKIE_SAMESITE', 'Lax'), ['Lax', 'Strict', 'None'], true)
            ? env_value('SESSION_COOKIE_SAMESITE', 'Lax') : 'Lax',
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

function company_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#^https?://[^/]+/#i', '', $path) ?? $path;
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'app/')) {
        $path = substr($path, strlen('app/'));
    }
    if (str_starts_with($path, 'sistema/')) {
        $path = substr($path, strlen('sistema/'));
    }
    $base = rtrim(env_value('COMPANY_ASSET_BASE_URL', 'https://sistema14.com/app'), '/');
    $segments = array_values(array_filter(explode('/', preg_replace('#/+#', '/', $path) ?? $path), 'strlen'));
    return $base . '/' . implode('/', array_map('rawurlencode', $segments));
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

function xlsx_export_service(): XlsxExportService
{
    static $service;
    return $service ??= new XlsxExportService();
}

function company_panel_repository(): CompanyPanelRepository
{
    static $repository;
    return $repository ??= new CompanyPanelRepository(db());
}

function capture_analysis_service(): CaptureAnalysisService
{
    static $service;
    return $service ??= new CaptureAnalysisService(
        db(), company_panel_repository(), movement_repository(), automatic_invoice_service()
    );
}

function automatic_invoice_service(): AutomaticInvoiceService
{
    static $service;
    return $service ??= new AutomaticInvoiceService(db());
}

function fiscal_document_service(): FiscalDocumentService
{
    static $service;
    return $service ??= new FiscalDocumentService(db());
}

function pdf_document_service(): PdfDocumentService
{
    static $service;
    return $service ??= new PdfDocumentService(db());
}

function invoice_email_service(): InvoiceEmailService
{
    static $service;
    return $service ??= new InvoiceEmailService(db(), pdf_document_service());
}

function dashboard_repository(): DashboardRepository
{
    static $repository;
    return $repository ??= new DashboardRepository(db());
}

function statistics_repository(): StatisticsRepository
{
    static $repository;
    return $repository ??= new StatisticsRepository(db());
}

function global_search_repository(): GlobalSearchRepository
{
    static $repository;
    return $repository ??= new GlobalSearchRepository(db());
}

function manual_email_service(): ManualEmailService
{
    static $service;
    return $service ??= new ManualEmailService(APP_ROOT . '/src/manual-email-templates.php');
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

function webauthn_options_rate_limiter(): App\AuthRateLimiter
{
    static $limiter;
    return $limiter ??= new App\AuthRateLimiter(
        env_value('APP_KEY'),
        max(10, (int) env_value('WEBAUTHN_MAX_OPTIONS_PER_MINUTE', '30')),
        60,
    );
}

function webauthn_config(): array
{
    static $config;
    return $config ??= require APP_ROOT . '/config/webauthn.php';
}

function passkey_repository(): App\PasskeyRepository
{
    static $repository;
    return $repository ??= new App\PasskeyRepository(db());
}

function webauthn_service(): App\WebAuthnService
{
    static $service;
    return $service ??= new App\WebAuthnService(webauthn_config(), passkey_repository());
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

function display_date(mixed $value, bool $includeTime = false): string
{
    $raw = trim((string) $value);
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($raw);
    } catch (Throwable) {
        return $raw;
    }
    $showTime = $includeTime
        && preg_match('/(?:T|\s)(\d{2}):(\d{2})/', $raw, $matches) === 1
        && ($matches[1] !== '00' || $matches[2] !== '00');
    $weekdays = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $formatted = sprintf(
        '%s %s de %s del %s',
        $weekdays[(int) $date->format('w')],
        $date->format('d'),
        $months[(int) $date->format('n')],
        $date->format('Y')
    );
    return $showTime ? $formatted . ' · ' . $date->format('H:i') : $formatted;
}

function is_display_date_field(string $field): bool
{
    $field = strtolower(trim($field));
    return $field === 'fecha'
        || str_contains($field, 'fecha_')
        || str_ends_with($field, '_fecha')
        || in_array($field, ['created_at', 'updated_at', 'deleted_at', 'last_used_at', 'ultimo_uso'], true);
}

function display_field_value(string $field, mixed $value, string $format = 'text'): string
{
    if ($format === 'date' || $format === 'datetime' || ($format === 'text' && is_display_date_field($field))) {
        return display_date($value, $format !== 'date');
    }
    return report_value($value, $format);
}

function filter_date_value(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $raw ? $raw : '';
}

function filter_date_range(mixed $from, mixed $to): array
{
    $from = filter_date_value($from);
    $to = filter_date_value($to);
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
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
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store, private, max-age=0');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; object-src \'none\'; img-src \'self\' data: https://sistema14.com; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'nonce-' . csp_nonce() . '\'; connect-src \'self\'');
}
