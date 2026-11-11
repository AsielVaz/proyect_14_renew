<?php

declare(strict_types=1);

function auth_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (filter_var(env_value('TRUST_PROXY', 'false'), FILTER_VALIDATE_BOOL)) {
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
            $ip = $forwarded;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function auth_user_agent_hash(): string
{
    return hash_hmac('sha256', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), env_value('APP_KEY'));
}

function auth_request_id(): string
{
    static $requestId;
    if (is_string($requestId)) {
        return $requestId;
    }
    $provided = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    return $requestId = preg_match('/^[a-zA-Z0-9._:-]{8,100}$/', $provided)
        ? $provided
        : bin2hex(random_bytes(8));
}

function auth_safe_log_context(array $context): array
{
    $safe = [];
    foreach ($context as $key => $value) {
        $key = mb_substr((string) $key, 0, 80);
        if (preg_match('/password|secret|token|challenge|signature|credential|assertion|cookie|client_data|auth_data/i', $key)) {
            $safe[$key] = '[redactado]';
            continue;
        }
        if (is_array($value)) {
            $safe[$key] = auth_safe_log_context($value);
        } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            $safe[$key] = $value;
        } else {
            $safe[$key] = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '', 0, 1000);
        }
    }
    return $safe;
}

function auth_request_log_context(): array
{
    $sessionId = session_id();
    return [
        'request_id' => auth_request_id(),
        'http_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
        'request_uri' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 1000),
        'host' => mb_substr((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''), 0, 255),
        'origin_header' => mb_substr((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 1000),
        'referer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
        'https' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443',
        'auth_route' => mb_substr((string) ($_GET['auth'] ?? ''), 0, 100),
        'webauthn_route' => mb_substr((string) ($_GET['webauthn'] ?? ''), 0, 100),
        'session_cookie_present' => isset($_COOKIE[session_name()]),
        'session_id_hash' => $sessionId !== ''
            ? substr(hash_hmac('sha256', $sessionId, env_value('APP_KEY')), 0, 24)
            : '',
    ];
}

function auth_log_event(
    string $event,
    string $result,
    string $message,
    array $context = [],
    ?array $user = null,
    string $identifier = '',
    string $method = ''
): void {
    try {
        $user ??= is_array($_SESSION['auth_user'] ?? null) ? $_SESSION['auth_user'] : null;
        $identifier = trim($identifier);
        if ($identifier === '') {
            $identifier = trim((string) ($user['email'] ?? $_SESSION['auth_pending']['email'] ?? ''));
        }
        if ($identifier === '') {
            $identifier = 'sin identificar';
        }
        $identifier = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $identifier) ?? 'sin identificar', 0, 100);
        $event = mb_substr(preg_replace('/[^a-z0-9_.:-]+/i', '_', $event) ?? 'auth_event', 0, 80);
        $method = mb_substr(preg_replace('/[^a-z0-9_.:-]+/i', '_', $method) ?? '', 0, 30);
        $result = in_array($result, ['Correcto', 'Error', 'Bloqueado', 'Sesión vencida'], true) ? $result : 'Error';
        $safeContext = auth_safe_log_context(array_merge(auth_request_log_context(), $context));
        $detailParts = ['Mensaje: ' . mb_substr($message, 0, 2000)];
        foreach ($safeContext as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'sí' : 'no';
            } elseif ($value === null) {
                $value = 'null';
            }
            $detailParts[] = $key . ': ' . (string) $value;
        }
        $payload = json_encode([
            'schema' => 1,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'No disponible'), 0, 1000),
            'event' => $event,
            'method' => $method,
            'message' => mb_substr($message, 0, 2000),
            'detail' => implode(' | ', $detailParts),
            'context' => $safeContext,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $insert = db()->prepare('INSERT INTO logsesiones
            (usuario, agent, ip, hora_i, fecha, hora_f, tipo, activa, tiempo_conexion)
            VALUES (:user, :agent, :ip, :time, :date, NULL, :result, :active, :method)');
        $insert->execute([
            'user' => $identifier,
            'agent' => $payload,
            'ip' => auth_client_ip(),
            'time' => date('H:i:s'),
            'date' => date('Y-m-d'),
            'result' => $result,
            'active' => $result === 'Correcto' ? 'si' : 'no',
            'method' => mb_substr(($method !== '' ? $method . ' · ' : '') . $event, 0, 100),
        ]);
    } catch (Throwable $exception) {
        error_log('No fue posible registrar el evento de autenticación [' . $event . ']: ' . $exception->getMessage());
    }
}

function auth_forget_identity(): void
{
    unset(
        $_SESSION['auth_user'],
        $_SESSION['auth_pending'],
        $_SESSION['usuario_id'],
        $_SESSION['email'],
        $_SESSION['usuario'],
        $_SESSION['tipo_usuario'],
        $_SESSION['id_cliente_14'],
        $_SESSION['nombre'],
        $_SESSION['webauthn_challenges']
    );
}

function auth_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function auth_validate_session(): void
{
    $user = $_SESSION['auth_user'] ?? null;
    if (!is_array($user)) {
        return;
    }

    $now = time();
    $idleLimit = max(300, (int) env_value('SESSION_IDLE_SECONDS', '1800'));
    $absoluteLimit = max($idleLimit, (int) env_value('SESSION_ABSOLUTE_SECONDS', '28800'));
    $reasons = [];
    if ((int) ($user['id'] ?? 0) <= 0) $reasons[] = 'identificador de usuario ausente';
    if (!hash_equals((string) ($user['ua_hash'] ?? ''), auth_user_agent_hash())) $reasons[] = 'el navegador cambió durante la sesión';
    if ($now - (int) ($user['last_activity'] ?? 0) > $idleLimit) $reasons[] = 'límite de inactividad excedido';
    if ($now - (int) ($user['authenticated_at'] ?? 0) > $absoluteLimit) $reasons[] = 'duración máxima de sesión excedida';
    $invalid = $reasons !== [];

    if ($invalid) {
        auth_log_event('session_invalidated', 'Sesión vencida', 'La sesión fue invalidada por seguridad.', [
            'reasons' => $reasons,
            'idle_seconds' => $now - (int) ($user['last_activity'] ?? 0),
            'idle_limit_seconds' => $idleLimit,
            'session_age_seconds' => $now - (int) ($user['authenticated_at'] ?? 0),
            'absolute_limit_seconds' => $absoluteLimit,
        ], $user, '', (string) ($user['method'] ?? 'session'));
        auth_forget_identity();
        unset($_SESSION['csrf_token']);
        $_SESSION['auth_notice'] = 'Tu sesión terminó por seguridad. Vuelve a ingresar.';
        session_regenerate_id(true);
        return;
    }

    $_SESSION['auth_user']['last_activity'] = $now;
    if ($now - (int) ($user['rotated_at'] ?? 0) > 900) {
        // Renovar el identificador protege la sesión sin invalidar formularios
        // que el usuario ya tiene abiertos. El token CSRF sólo cambia al
        // autenticar, cerrar o invalidar completamente la sesión.
        session_regenerate_id(true);
        $_SESSION['auth_user']['rotated_at'] = $now;
    }
}

function auth_establish_session(array $user, string $method): string
{
    session_regenerate_id(true);
    $now = time();
    $fullName = trim(implode(' ', array_filter([
        trim((string) ($user['nombre'] ?? '')),
        trim((string) ($user['appat'] ?? '')),
        trim((string) ($user['apmat'] ?? '')),
    ])));
    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'name' => $fullName !== '' ? $fullName : (string) $user['email'],
        'type' => (string) ($user['tipo'] ?? ''),
        'client_id' => (int) ($user['cliente'] ?? 0),
        'method' => $method,
        'authenticated_at' => $now,
        'last_activity' => $now,
        'rotated_at' => $now,
        'ua_hash' => auth_user_agent_hash(),
    ];

    // Alias de sesión conservados para el código migrado; no implican escrituras en BD.
    $_SESSION['usuario_id'] = (int) $user['id'];
    $_SESSION['email'] = (string) $user['email'];
    $_SESSION['usuario'] = (string) ($user['usuario'] ?? '');
    $_SESSION['tipo_usuario'] = (string) ($user['tipo'] ?? '');
    $_SESSION['id_cliente_14'] = (int) ($user['cliente'] ?? 0);
    $_SESSION['nombre'] = (string) ($user['nombre'] ?? '');
    unset($_SESSION['auth_pending'], $_SESSION['csrf_token']);

    auth_log_event('login_success', 'Correcto', 'Inicio de sesión completado correctamente.', [
        'user_id' => (int) $user['id'],
        'user_type' => (string) ($user['tipo'] ?? ''),
        'two_factor_used' => $method === 'totp',
    ], $user, (string) $user['email'], $method);

    $returnTo = (string) ($_SESSION['auth_return_to'] ?? '');
    unset($_SESSION['auth_return_to']);
    return str_starts_with($returnTo, '?') ? app_url($returnTo) : app_url('/');
}

function auth_complete(array $user, string $method = 'password'): never
{
    redirect(auth_establish_session($user, $method));
}

function auth_render_login(string $step = 'password', ?string $error = null, string $email = ''): never
{
    http_response_code($error === null ? 200 : 401);
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    $notice = (string) ($_SESSION['auth_notice'] ?? '');
    unset($_SESSION['auth_notice']);
    require APP_ROOT . '/login.php';
    exit;
}

function auth_failure_delay(): void
{
    usleep(random_int(250_000, 450_000));
}

function handle_authentication(): void
{
    auth_validate_session();
    $authRoute = (string) ($_GET['auth'] ?? '');
    $webauthnRoute = (string) ($_GET['webauthn'] ?? '');

    if (is_array($_SESSION['auth_user'] ?? null)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authRoute === 'logout') {
            verify_csrf();
            auth_destroy_session();
            redirect('?auth=login');
        }
        if ($authRoute !== '') {
            redirect('./');
        }
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $authRoute === '') {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $_SESSION['auth_return_to'] = $query !== '' ? '?' . $query : './';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authRoute === 'login') {
        try {
            verify_csrf();
        } catch (Throwable) {
            auth_render_login('password', 'La sesión del formulario venció. Recarga e inténtalo de nuevo.');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        // La columna `email` funciona como identificador, pero también puede contener
        // cuentas históricas como "usuario@admin" que no son correos RFC válidos.
        $invalidIdentifier = $email === '' || strlen($email) > 254
            || preg_match('/[\x00-\x1F\x7F]/', $email) === 1;
        if ($invalidIdentifier || $password === '' || strlen($password) > 4096) {
            auth_log_event('credentials_rejected', 'Error', 'El formulario de acceso contiene credenciales con formato no válido.', [
                'identifier_present' => $email !== '',
                'identifier_length' => strlen($email),
                'identifier_contains_control_characters' => preg_match('/[\x00-\x1F\x7F]/', $email) === 1,
                'password_present' => $password !== '',
                'password_length' => strlen($password),
            ], null, $email, 'password');
            auth_failure_delay();
            auth_render_login('password', 'El correo o la contraseña no son válidos.', $email);
        }

        $ip = auth_client_ip();
        $limiter = auth_rate_limiter();
        $remaining = $limiter->blockedFor($email, $ip);
        if ($remaining > 0) {
            auth_log_event('login_rate_limited', 'Bloqueado', 'El inicio de sesión fue bloqueado temporalmente por exceso de intentos.', [
                'remaining_seconds' => $remaining,
            ], null, $email, 'password');
            auth_render_login('password', 'Demasiados intentos. Espera ' . max(1, (int) ceil($remaining / 60)) . ' minutos.', $email);
        }

        $user = auth_service()->findByEmail($email);
        if (!$user || !auth_service()->verifyPassword($user, $password)) {
            $limiter->recordFailure($email, $ip);
            auth_log_event('credentials_invalid', 'Error', 'El identificador no existe o la contraseña no coincide.', [
                'account_was_found' => is_array($user),
                'rate_limit_failure_recorded' => true,
            ], null, $email, 'password');
            auth_failure_delay();
            auth_render_login('password', 'El correo o la contraseña no son válidos.', $email);
        }

        if (auth_service()->requiresTwoFactor($user)) {
            session_regenerate_id(true);
            $_SESSION['auth_pending'] = [
                'user_id' => (int) $user['id'],
                'email' => (string) $user['email'],
                'expires_at' => time() + 300,
                'ua_hash' => auth_user_agent_hash(),
            ];
            unset($_SESSION['csrf_token']);
            redirect('?auth=2fa');
        }

        $limiter->clear($email, $ip);
        auth_complete($user, 'password');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authRoute === '2fa') {
        try {
            verify_csrf();
        } catch (Throwable) {
            auth_render_login('2fa', 'La sesión del formulario venció. Inicia de nuevo.');
        }

        $pending = $_SESSION['auth_pending'] ?? null;
        if (!is_array($pending)
            || (int) ($pending['expires_at'] ?? 0) < time()
            || !hash_equals((string) ($pending['ua_hash'] ?? ''), auth_user_agent_hash())) {
            auth_log_event('two_factor_expired', 'Sesión vencida', 'La verificación en dos pasos ya no es válida.', [
                'pending_session_present' => is_array($pending),
                'expired' => is_array($pending) && (int) ($pending['expires_at'] ?? 0) < time(),
                'browser_matches' => is_array($pending) && hash_equals((string) ($pending['ua_hash'] ?? ''), auth_user_agent_hash()),
            ], null, (string) ($pending['email'] ?? ''), 'totp');
            auth_forget_identity();
            auth_render_login('password', 'La verificación venció. Ingresa de nuevo.');
        }

        $email = (string) ($pending['email'] ?? '');
        $ip = auth_client_ip();
        $limiter = auth_rate_limiter();
        $remaining = $limiter->blockedFor($email, $ip);
        if ($remaining > 0) {
            auth_log_event('two_factor_rate_limited', 'Bloqueado', 'La verificación en dos pasos fue bloqueada temporalmente por exceso de intentos.', [
                'remaining_seconds' => $remaining,
            ], null, $email, 'totp');
            auth_forget_identity();
            auth_render_login('password', 'Demasiados intentos. Espera ' . max(1, (int) ceil($remaining / 60)) . ' minutos.');
        }

        $user = auth_service()->findById((int) $pending['user_id']);
        $code = (string) ($_POST['code'] ?? '');
        $window = max(0, min(3, (int) env_value('TOTP_WINDOW', '3')));
        if (!$user || !auth_service()->requiresTwoFactor($user) || !auth_service()->verifyTotp($user, $code, $window)) {
            $limiter->recordFailure($email, $ip);
            auth_log_event('two_factor_invalid', 'Error', 'El código de verificación en dos pasos no fue válido.', [
                'account_was_found' => is_array($user),
                'two_factor_was_configured' => is_array($user) && auth_service()->requiresTwoFactor($user),
                'code_was_present' => $code !== '',
                'code_length' => strlen($code),
                'code_was_numeric' => ctype_digit($code),
            ], $user ?: null, $email, 'totp');
            auth_failure_delay();
            auth_render_login('2fa', 'El código de verificación no es válido.');
        }

        $limiter->clear($email, $ip);
        auth_complete($user, 'totp');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array($webauthnRoute, [
            'login-options', 'login-verify', 'register-options', 'register-verify', 'rename', 'delete',
        ], true)) {
        return;
    }

    if ($authRoute === 'login') {
        unset($_SESSION['auth_pending']);
    }
    $pending = $_SESSION['auth_pending'] ?? null;
    if ($authRoute === '2fa' && is_array($pending) && (int) ($pending['expires_at'] ?? 0) >= time()) {
        auth_render_login('2fa');
    }
    auth_render_login('password');
}
