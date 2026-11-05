<?php

declare(strict_types=1);

$enabled = filter_var(env_value('WEBAUTHN_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
$origin = rtrim(env_value('WEBAUTHN_ORIGIN', ''), '/');
$rpId = strtolower(trim(env_value('WEBAUTHN_RP_ID', '')));
$rpName = trim(env_value('WEBAUTHN_RP_NAME', 'Sistema 14'));
$verification = strtolower(env_value('WEBAUTHN_USER_VERIFICATION', 'required'));
$handleSecret = env_value('WEBAUTHN_HANDLE_SECRET', '');

if ($enabled) {
    $parts = parse_url($origin);
    $originHost = strtolower((string) ($parts['host'] ?? ''));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $hasUnexpectedParts = isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)
        || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']);
    $validRpRelation = $originHost === $rpId || str_ends_with($originHost, '.' . $rpId);
    $appParts = parse_url(env_value('APP_URL'));
    $appOrigin = strtolower((string) ($appParts['scheme'] ?? '')) . '://' . strtolower((string) ($appParts['host'] ?? ''));
    if (isset($appParts['port'])) {
        $appOrigin .= ':' . (int) $appParts['port'];
    }

    if ($origin === '' || $originHost === '' || $rpId === '' || str_contains($origin, '*') || str_contains($rpId, '*')) {
        throw new RuntimeException('La configuración WebAuthn requiere un origen y RP ID explícitos, sin comodines.');
    }
    if (($scheme !== 'https' && $originHost !== 'localhost') || $hasUnexpectedParts) {
        throw new RuntimeException('WEBAUTHN_ORIGIN debe ser un origen HTTPS exacto, sin ruta, consulta ni fragmento.');
    }
    if (!$validRpRelation || filter_var($rpId, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('WEBAUTHN_RP_ID no es compatible con el host configurado.');
    }
    if (!hash_equals(strtolower($origin), $appOrigin)) {
        throw new RuntimeException('WEBAUTHN_ORIGIN debe coincidir con el origen de APP_URL.');
    }
    if ($originHost !== 'localhost'
        && !filter_var(env_value('SESSION_SECURE_COOKIE', 'false'), FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException('WebAuthn en producción requiere SESSION_SECURE_COOKIE=true.');
    }
    if (!in_array($verification, ['required', 'preferred', 'discouraged'], true)) {
        throw new RuntimeException('WEBAUTHN_USER_VERIFICATION no tiene un valor válido.');
    }
    if (strlen($handleSecret) < 32) {
        throw new RuntimeException('WEBAUTHN_HANDLE_SECRET debe contener al menos 32 caracteres.');
    }
    if (!class_exists(lbuchs\WebAuthn\WebAuthn::class)) {
        throw new RuntimeException('La dependencia WebAuthn no está instalada. Ejecuta composer install.');
    }
}

return [
    'enabled' => $enabled,
    'rp_id' => $rpId,
    'rp_name' => $rpName !== '' ? $rpName : 'Sistema 14',
    'origin' => $origin,
    'handle_secret' => $handleSecret,
    'timeout' => max(15, min(300, (int) env_value('WEBAUTHN_TIMEOUT', '60000') / 1000)),
    'user_verification' => $verification,
    'challenge_ttl' => max(60, min(900, (int) env_value('WEBAUTHN_CHALLENGE_TTL', '120'))),
    'enrollment_ttl' => max(60, min(900, (int) env_value('WEBAUTHN_ENROLLMENT_TTL', '300'))),
    'max_credentials' => max(1, min(100, (int) env_value('WEBAUTHN_MAX_CREDENTIALS', '20'))),
    'recent_auth_seconds' => max(60, min(3600, (int) env_value('WEBAUTHN_RECENT_AUTH_SECONDS', '600'))),
];
