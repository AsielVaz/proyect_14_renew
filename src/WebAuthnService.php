<?php

declare(strict_types=1);

namespace App;

use lbuchs\WebAuthn\Attestation\AuthenticatorData;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;

final class WebAuthnService
{
    private const SESSION_KEY = 'webauthn_challenges';

    public function __construct(
        private readonly array $config,
        private readonly PasskeyRepository $repository,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function registrationOptions(array $user): array
    {
        $this->assertEnabled();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('La sesión autenticada no está disponible.');
        }

        $credentials = $this->repository->credentialsForUser($userId);
        if (count($credentials) >= $this->config['max_credentials']) {
            throw new RuntimeException('La cuenta alcanzó el máximo de llaves de acceso permitidas.');
        }
        $exclude = array_map(static fn (array $row): string => (string) $row['credential_id'], $credentials);
        $userHandle = $this->repository->stableUserHandle($userId, $this->config['handle_secret']);
        $displayName = trim(implode(' ', array_filter([
            (string) ($user['nombre'] ?? ''),
            (string) ($user['appat'] ?? ''),
            (string) ($user['apmat'] ?? ''),
        ]))) ?: (string) ($user['email'] ?? 'Usuario');

        $webauthn = $this->library();
        $options = $webauthn->getCreateArgs(
            $userHandle,
            (string) ($user['email'] ?? 'usuario-' . $userId),
            $displayName,
            $this->config['timeout'],
            'required',
            $this->config['user_verification'],
            null,
            $exclude,
        );

        $this->storeChallenge('register', $options->publicKey->challenge->getBinaryString(), [
            'user_id' => $userId,
            'user_handle' => $this->base64UrlEncode($userHandle),
        ], $this->config['enrollment_ttl']);
        return $this->normalizeOptions($options);
    }

    public function loginOptions(?array $user = null): array
    {
        $this->assertEnabled();
        $credentialIds = [];
        $userId = null;
        if (is_array($user)) {
            $userId = (int) ($user['id'] ?? 0);
            $credentials = $this->repository->credentialsForUser($userId);
            if ($credentials === []) {
                throw new RuntimeException('No hay llaves de acceso disponibles para esta cuenta.');
            }
            $credentialIds = array_map(static fn (array $row): string => (string) $row['credential_id'], $credentials);
        }

        $webauthn = $this->library();
        $options = $webauthn->getGetArgs(
            $credentialIds,
            $this->config['timeout'],
            true,
            true,
            true,
            true,
            true,
            $this->config['user_verification'],
        );
        $this->storeChallenge('login', $options->publicKey->challenge->getBinaryString(), [
            'user_id' => $userId,
        ]);
        return $this->normalizeOptions($options);
    }

    public function verifyRegistration(array $payload, array $user): array
    {
        $this->assertEnabled();
        $challenge = $this->consumeChallenge('register');
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $userId !== (int) ($challenge['user_id'] ?? 0)) {
            throw new RuntimeException('La ceremonia no corresponde al usuario autenticado.');
        }

        $response = $this->requireArray($payload, 'response');
        $clientData = $this->decodeRequired((string) ($response['clientDataJSON'] ?? ''), 'datos del cliente');
        $attestation = $this->decodeRequired((string) ($response['attestationObject'] ?? ''), 'atestación');
        $this->assertExactOrigin($clientData, 'webauthn.create');

        $webauthn = $this->library();
        $created = $webauthn->processCreate(
            $clientData,
            $attestation,
            (string) $challenge['challenge'],
            $this->config['user_verification'] === 'required',
            true,
            false,
            false,
        );

        $credentialId = $created->credentialId instanceof ByteBuffer
            ? $created->credentialId->getBinaryString() : (string) $created->credentialId;
        $browserCredentialId = $this->decodeRequired((string) ($payload['rawId'] ?? $payload['id'] ?? ''), 'credencial');
        if (!hash_equals($credentialId, $browserCredentialId)) {
            throw new RuntimeException('El identificador de la llave de acceso no coincide con la atestación.');
        }
        $expectedHandle = $this->base64UrlDecode((string) ($challenge['user_handle'] ?? ''));
        if ($this->repository->countForUser($userId) >= $this->config['max_credentials']) {
            throw new RuntimeException('La cuenta alcanzó el máximo de llaves de acceso permitidas.');
        }
        $name = mb_substr(trim((string) ($payload['name'] ?? 'Mi dispositivo')), 0, 100);
        $transports = array_values(array_intersect(
            is_array($response['transports'] ?? null) ? $response['transports'] : [],
            ['usb', 'nfc', 'ble', 'hybrid', 'internal']
        ));

        $id = $this->repository->insert($userId, [
            'credential_id' => $credentialId,
            'public_key' => (string) $created->credentialPublicKey,
            'user_handle' => $expectedHandle,
            'sign_count' => (int) ($created->signatureCounter ?? 0),
            'transports' => $transports,
            'backup_eligible' => (bool) ($created->isBackupEligible ?? false),
            'backup_state' => (bool) ($created->isBackedUp ?? false),
            'name' => $name !== '' ? $name : 'Mi dispositivo',
        ]);

        return ['id' => $id, 'message' => 'La llave de acceso quedó registrada.'];
    }

    public function verifyLogin(array $payload): array
    {
        $this->assertEnabled();
        $challenge = $this->consumeChallenge('login');
        $response = $this->requireArray($payload, 'response');
        $credentialId = $this->decodeRequired((string) ($payload['rawId'] ?? $payload['id'] ?? ''), 'credencial');
        $credential = $this->repository->findByCredentialId($credentialId);
        if ($credential === null) {
            throw new RuntimeException('La llave de acceso no está registrada en este sistema.');
        }
        $restrictedUserId = (int) ($challenge['user_id'] ?? 0);
        if ($restrictedUserId > 0 && $restrictedUserId !== (int) $credential['id_usuario']) {
            throw new RuntimeException('La llave de acceso no corresponde a la cuenta seleccionada.');
        }

        $userHandleEncoded = (string) ($response['userHandle'] ?? '');
        if ($userHandleEncoded !== '') {
            $userHandle = $this->base64UrlDecode($userHandleEncoded);
            if (!hash_equals((string) $credential['user_handle'], $userHandle)) {
                throw new RuntimeException('La llave de acceso no corresponde a su usuario.');
            }
        }

        $clientData = $this->decodeRequired((string) ($response['clientDataJSON'] ?? ''), 'datos del cliente');
        $authenticatorData = $this->decodeRequired((string) ($response['authenticatorData'] ?? ''), 'datos del autenticador');
        $signature = $this->decodeRequired((string) ($response['signature'] ?? ''), 'firma');
        $this->assertExactOrigin($clientData, 'webauthn.get');

        $webauthn = $this->library();
        $previousCounter = (bool) $credential['backup_eligible'] ? null : (int) $credential['sign_count'];
        $verified = $webauthn->processGet(
            $clientData,
            $authenticatorData,
            $signature,
            (string) $credential['public_key'],
            (string) $challenge['challenge'],
            $previousCounter,
            $this->config['user_verification'] === 'required',
            true,
        );
        if (!$verified) {
            throw new RuntimeException('No fue posible validar la firma de la llave de acceso.');
        }

        $authenticator = new AuthenticatorData($authenticatorData);
        $newCounter = $webauthn->getSignatureCounter() ?? (int) $credential['sign_count'];
        $this->repository->updateUsage(
            (int) $credential['id'],
            $newCounter,
            $authenticator->getIsBackup(),
            $previousCounter,
        );

        return [
            'user_id' => (int) $credential['id_usuario'],
            'credential_id' => (int) $credential['id'],
        ];
    }

    private function library(): WebAuthn
    {
        return new WebAuthn(
            $this->config['rp_name'],
            $this->config['rp_id'],
            ['none'],
            true,
        );
    }

    private function storeChallenge(string $operation, string $challenge, array $context, ?int $ttl = null): void
    {
        $_SESSION[self::SESSION_KEY][$operation] = $context + [
            'challenge' => $challenge,
            'expires_at' => time() + ($ttl ?? $this->config['challenge_ttl']),
            'ua_hash' => \auth_user_agent_hash(),
        ];
    }

    private function consumeChallenge(string $operation): array
    {
        $stored = $_SESSION[self::SESSION_KEY][$operation] ?? null;
        unset($_SESSION[self::SESSION_KEY][$operation]);
        if (!is_array($stored)
            || !is_string($stored['challenge'] ?? null)
            || (int) ($stored['expires_at'] ?? 0) < time()
            || !hash_equals((string) ($stored['ua_hash'] ?? ''), \auth_user_agent_hash())) {
            throw new RuntimeException('La solicitud de la llave de acceso venció. Inténtalo nuevamente.');
        }
        return $stored;
    }

    private function assertExactOrigin(string $clientDataJson, string $expectedType): void
    {
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)
            || !hash_equals($expectedType, (string) ($clientData['type'] ?? ''))
            || !hash_equals($this->config['origin'], (string) ($clientData['origin'] ?? ''))
            || ($clientData['crossOrigin'] ?? false) === true) {
            throw new RuntimeException('El origen de la solicitud WebAuthn no es válido.');
        }
    }

    private function normalizeOptions(object $options): array
    {
        $encoded = json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('No fue posible preparar la solicitud WebAuthn.');
        }
        return $decoded;
    }

    private function requireArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('La respuesta de la llave de acceso está incompleta.');
        }
        return $value;
    }

    private function decodeRequired(string $value, string $label): string
    {
        if ($value === '') {
            throw new RuntimeException('Faltan ' . $label . ' de la llave de acceso.');
        }
        return $this->base64UrlDecode($value);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new RuntimeException('La respuesta WebAuthn contiene datos binarios no válidos.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('La respuesta WebAuthn contiene datos binarios no válidos.');
        }
        return $decoded;
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('El acceso con Passkey no está habilitado.');
        }
    }
}
