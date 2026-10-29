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
        $_SESSION['nombre']
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
    $invalid = (int) ($user['id'] ?? 0) <= 0
        || !hash_equals((string) ($user['ua_hash'] ?? ''), auth_user_agent_hash())
        || $now - (int) ($user['last_activity'] ?? 0) > $idleLimit
        || $now - (int) ($user['authenticated_at'] ?? 0) > $absoluteLimit;

    if ($invalid) {
        auth_forget_identity();
        unset($_SESSION['csrf_token']);
        $_SESSION['auth_notice'] = 'Tu sesión terminó por seguridad. Vuelve a ingresar.';
        session_regenerate_id(true);
        return;
    }

    $_SESSION['auth_user']['last_activity'] = $now;
    if ($now - (int) ($user['rotated_at'] ?? 0) > 900) {
        session_regenerate_id(true);
        $_SESSION['auth_user']['rotated_at'] = $now;
        unset($_SESSION['csrf_token']);
    }
}

function auth_complete(array $user): never
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

    $returnTo = (string) ($_SESSION['auth_return_to'] ?? '');
    unset($_SESSION['auth_return_to']);
    redirect(str_starts_with($returnTo, '?') ? $returnTo : './');
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
        // El sistema legado usa la columna `email` como identificador, pero contiene
        // cuentas históricas como "usuario@admin" que no son correos RFC válidos.
        $invalidIdentifier = $email === '' || strlen($email) > 254
            || preg_match('/[\x00-\x1F\x7F]/', $email) === 1;
        if ($invalidIdentifier || $password === '' || strlen($password) > 4096) {
            auth_failure_delay();
            auth_render_login('password', 'El correo o la contraseña no son válidos.', $email);
        }

        $ip = auth_client_ip();
        $limiter = auth_rate_limiter();
        $remaining = $limiter->blockedFor($email, $ip);
        if ($remaining > 0) {
            auth_render_login('password', 'Demasiados intentos. Espera ' . max(1, (int) ceil($remaining / 60)) . ' minutos.', $email);
        }

        $user = auth_service()->findByEmail($email);
        if (!$user || !auth_service()->verifyPassword($user, $password)) {
            $limiter->recordFailure($email, $ip);
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
        auth_complete($user);
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
            auth_forget_identity();
            auth_render_login('password', 'La verificación venció. Ingresa de nuevo.');
        }

        $email = (string) ($pending['email'] ?? '');
        $ip = auth_client_ip();
        $limiter = auth_rate_limiter();
        $remaining = $limiter->blockedFor($email, $ip);
        if ($remaining > 0) {
            auth_forget_identity();
            auth_render_login('password', 'Demasiados intentos. Espera ' . max(1, (int) ceil($remaining / 60)) . ' minutos.');
        }

        $user = auth_service()->findById((int) $pending['user_id']);
        $code = (string) ($_POST['code'] ?? '');
        $window = max(0, min(3, (int) env_value('TOTP_WINDOW', '3')));
        if (!$user || !auth_service()->requiresTwoFactor($user) || !auth_service()->verifyTotp($user, $code, $window)) {
            $limiter->recordFailure($email, $ip);
            auth_failure_delay();
            auth_render_login('2fa', 'El código de verificación no es válido.');
        }

        $limiter->clear($email, $ip);
        auth_complete($user);
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
