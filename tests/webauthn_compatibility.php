<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/auth.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $passed++;
    echo "OK: {$message}\n";
};

$config = webauthn_config();
$assert($config['enabled'] === true, 'WebAuthn está habilitado');
$assert(in_array($config['user_verification'], ['required', 'preferred', 'discouraged'], true), 'verificación de usuario válida');
$assert($config['challenge_ttl'] === 120, 'TTL de autenticación configurado en 120 segundos');
$assert($config['enrollment_ttl'] === 300, 'TTL de registro configurado en 300 segundos');
$assert($config['max_credentials'] === 20, 'límite configurado en 20 credenciales');
$assert(class_exists(lbuchs\WebAuthn\WebAuthn::class), 'biblioteca cargada por Composer');

$columns = db()->query('SHOW COLUMNS FROM usuarios_passkeys')->fetchAll();
$columnNames = array_column($columns, 'Field');
foreach (['id_usuario', 'credential_id', 'credential_hash', 'public_key', 'user_handle', 'sign_count'] as $column) {
    $assert(in_array($column, $columnNames, true), "columna {$column} disponible");
}

$rows = db()->query(
    'SELECT id, id_usuario, credential_id, credential_hash, public_key, user_handle
     FROM usuarios_passkeys ORDER BY id'
)->fetchAll();
$assert($rows !== [], 'hay credenciales existentes para validar compatibilidad');

$handles = [];
foreach ($rows as $row) {
    $assert(hash_equals((string) $row['credential_hash'], hash('sha256', (string) $row['credential_id'], true)), 'hash binario coincide con credential_id #' . $row['id']);
    $assert(openssl_pkey_get_public((string) $row['public_key']) !== false, 'clave PEM válida #' . $row['id']);
    $userId = (int) $row['id_usuario'];
    $handles[$userId] ??= (string) $row['user_handle'];
    $assert(hash_equals($handles[$userId], (string) $row['user_handle']), 'user_handle estable para usuario ' . $userId);
    $found = passkey_repository()->findByCredentialId((string) $row['credential_id']);
    $assert(is_array($found) && (int) $found['id'] === (int) $row['id'], 'adaptador recupera credencial #' . $row['id']);
}

$assert(passkey_repository()->findByCredentialId(random_bytes(32)) === null, 'credencial inexistente se rechaza');
$assert(hash_equals(
    (string) $rows[0]['user_handle'],
    passkey_repository()->stableUserHandle((int) $rows[0]['id_usuario'], $config['handle_secret'])
), 'un usuario existente conserva literalmente su user_handle almacenado');
$userWithoutPasskey = db()->query(
    'SELECT u.id FROM usuarios u
     LEFT JOIN usuarios_passkeys p ON p.id_usuario = u.id
     WHERE p.id IS NULL ORDER BY u.id LIMIT 1'
)->fetchColumn();
if ($userWithoutPasskey !== false) {
    $generatedHandle = passkey_repository()->stableUserHandle((int) $userWithoutPasskey, $config['handle_secret']);
    $assert(strlen($generatedHandle) === 32, 'un usuario nuevo recibe un user_handle binario de 32 bytes');
    $assert(hash_equals(
        $generatedHandle,
        passkey_repository()->stableUserHandle((int) $userWithoutPasskey, $config['handle_secret'])
    ), 'el user_handle nuevo es determinista con el secreto configurado');
}

$firstUser = auth_service()->findById((int) $rows[0]['id_usuario']);
$assert(is_array($firstUser), 'usuario de prueba existe');
$registration = webauthn_service()->registrationOptions($firstUser);
$assert(isset($registration['publicKey']['challenge'], $registration['publicKey']['user']['id']), 'opciones de registro completas');
$login = webauthn_service()->loginOptions($firstUser);
$assert(isset($login['publicKey']['challenge'], $login['publicKey']['rpId']), 'opciones de autenticación completas');
$assert(count($login['publicKey']['allowCredentials'] ?? []) >= 1, 'fallback por usuario limita las credenciales permitidas');

$base64url = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$firstCredential = (string) $rows[0]['credential_id'];
$clientData = static fn (string $challenge, string $origin): string => json_encode([
    'type' => 'webauthn.get',
    'challenge' => $challenge,
    'origin' => $origin,
    'crossOrigin' => false,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$assertion = static function (string $credentialId, string $challenge, string $origin) use ($base64url, $clientData): array {
    return [
        'id' => $base64url($credentialId),
        'rawId' => $base64url($credentialId),
        'response' => [
            'clientDataJSON' => $base64url($clientData($challenge, $origin)),
            'authenticatorData' => $base64url("\0"),
            'signature' => $base64url(random_bytes(64)),
            'userHandle' => '',
        ],
    ];
};

$badOriginPayload = $assertion($firstCredential, $login['publicKey']['challenge'], 'https://malicious.example');
try {
    webauthn_service()->verifyLogin($badOriginPayload);
    $assert(false, 'un origen distinto se rechaza');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'origen'), 'un origen distinto se rechaza');
}
try {
    webauthn_service()->verifyLogin($badOriginPayload);
    $assert(false, 'un desafío consumido no puede reutilizarse');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'venció'), 'un desafío consumido no puede reutilizarse');
}

$expired = webauthn_service()->loginOptions($firstUser);
$_SESSION['webauthn_challenges']['login']['expires_at'] = time() - 1;
try {
    webauthn_service()->verifyLogin($assertion($firstCredential, $expired['publicKey']['challenge'], $config['origin']));
    $assert(false, 'un desafío caducado se rechaza');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'venció'), 'un desafío caducado se rechaza');
}

$unknown = webauthn_service()->loginOptions();
try {
    webauthn_service()->verifyLogin($assertion(random_bytes(32), $unknown['publicKey']['challenge'], $config['origin']));
    $assert(false, 'una credencial desconocida se rechaza');
} catch (RuntimeException $exception) {
    $assert(str_contains($exception->getMessage(), 'no está registrada'), 'una credencial desconocida se rechaza');
}

$invalidSignature = webauthn_service()->loginOptions($firstUser);
$authenticatorData = hash('sha256', $config['rp_id'], true) . chr(0x05) . pack('N', (int) $rows[0]['id'] + 1000);
$invalidPayload = $assertion($firstCredential, $invalidSignature['publicKey']['challenge'], $config['origin']);
$invalidPayload['response']['authenticatorData'] = $base64url($authenticatorData);
try {
    webauthn_service()->verifyLogin($invalidPayload);
    $assert(false, 'una firma inválida se rechaza');
} catch (lbuchs\WebAuthn\WebAuthnException) {
    $assert(true, 'una firma inválida se rechaza');
}

$assert(passkey_repository()->rename((int) $rows[0]['id'], -1, 'No autorizado') === false, 'no se puede renombrar una credencial ajena');
$assert(passkey_repository()->delete((int) $rows[0]['id'], -1) === false, 'no se puede eliminar una credencial ajena');

echo "\n{$passed} comprobaciones superadas. No se modificó ninguna credencial.\n";
