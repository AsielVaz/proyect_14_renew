<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class PasskeyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByCredentialId(string $credentialId): ?array
    {
        if ($credentialId === '') {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT p.*, u.id AS user_exists
             FROM usuarios_passkeys p
             INNER JOIN usuarios u ON u.id = p.id_usuario
             WHERE p.credential_hash = :credential_hash
             LIMIT 1'
        );
        $statement->bindValue(':credential_hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
        $statement->execute();
        $credential = $statement->fetch();

        if (!is_array($credential) || !hash_equals((string) $credential['credential_id'], $credentialId)) {
            return null;
        }
        return $credential;
    }

    public function credentialsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, id_usuario, credential_id, user_handle, sign_count, transports,
                    backup_eligible, backup_state, nombre, fecha_creacion, ultimo_uso
             FROM usuarios_passkeys
             WHERE id_usuario = :user_id
             ORDER BY fecha_creacion DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function publicCredentialsForUser(int $userId): array
    {
        return array_map(static function (array $credential): array {
            return [
                'id' => (int) $credential['id'],
                'name' => (string) $credential['nombre'],
                'created_at' => (string) $credential['fecha_creacion'],
                'last_used_at' => $credential['ultimo_uso'] !== null ? (string) $credential['ultimo_uso'] : null,
                'backup_eligible' => (bool) $credential['backup_eligible'],
                'backup_state' => (bool) $credential['backup_state'],
                'transports' => self::decodeTransports($credential['transports'] ?? null),
            ];
        }, $this->credentialsForUser($userId));
    }

    public function stableUserHandle(int $userId, string $handleSecret): string
    {
        $statement = $this->pdo->prepare(
            'SELECT user_handle FROM usuarios_passkeys WHERE id_usuario = :user_id ORDER BY id LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $handle = $statement->fetchColumn();
        if (is_string($handle) && $handle !== '') {
            return $handle;
        }
        if ($handleSecret === '') {
            throw new RuntimeException('No está configurado el secreto para generar el identificador WebAuthn.');
        }
        return hash_hmac('sha256', (string) $userId, $handleSecret, true);
    }

    public function insert(int $userId, array $data): int
    {
        $credentialId = (string) ($data['credential_id'] ?? '');
        $publicKey = trim((string) ($data['public_key'] ?? ''));
        $userHandle = (string) ($data['user_handle'] ?? '');
        if ($userId <= 0 || $credentialId === '' || $userHandle === '' || openssl_pkey_get_public($publicKey) === false) {
            throw new RuntimeException('Los datos de la credencial no son válidos.');
        }
        if (strlen($credentialId) > 1023 || strlen($userHandle) > 64) {
            throw new RuntimeException('La credencial excede el tamaño permitido.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($this->findByCredentialId($credentialId) !== null) {
                throw new RuntimeException('Esta llave de acceso ya está registrada.');
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO usuarios_passkeys
                    (id_usuario, credential_id, credential_hash, public_key, user_handle, sign_count,
                     transports, backup_eligible, backup_state, nombre)
                 VALUES
                    (:user_id, :credential_id, :credential_hash, :public_key, :user_handle, :sign_count,
                     :transports, :backup_eligible, :backup_state, :name)'
            );
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
            $statement->bindValue(':credential_hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
            $statement->bindValue(':public_key', $publicKey);
            $statement->bindValue(':user_handle', $userHandle, PDO::PARAM_LOB);
            $statement->bindValue(':sign_count', max(0, (int) ($data['sign_count'] ?? 0)), PDO::PARAM_INT);
            $statement->bindValue(':transports', json_encode(array_values(array_unique($data['transports'] ?? [])), JSON_THROW_ON_ERROR));
            $statement->bindValue(':backup_eligible', !empty($data['backup_eligible']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue(':backup_state', !empty($data['backup_state']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue(':name', mb_substr(trim((string) ($data['name'] ?? 'Mi dispositivo')), 0, 100));
            $statement->execute();
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateUsage(int $id, int $newCounter, bool $backupState, ?int $expectedCounter): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE usuarios_passkeys
             SET sign_count = :sign_count, backup_state = :backup_state, ultimo_uso = NOW()
             WHERE id = :id' . ($expectedCounter !== null ? ' AND sign_count = :expected_counter' : '')
        );
        $params = [
            'sign_count' => max(0, $newCounter),
            'backup_state' => $backupState ? 1 : 0,
            'id' => $id,
        ];
        if ($expectedCounter !== null) {
            $params['expected_counter'] = max(0, $expectedCounter);
        }
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            $check = $this->pdo->prepare('SELECT sign_count FROM usuarios_passkeys WHERE id = :id');
            $check->execute(['id' => $id]);
            $current = $check->fetchColumn();
            $safeZeroCounter = $current !== false && $expectedCounter === 0
                && $newCounter === 0 && (int) $current === 0;
            $safeSyncedCredential = $current !== false && $expectedCounter === null;
            if (!$safeZeroCounter && !$safeSyncedCredential) {
                throw new RuntimeException('La llave fue utilizada simultáneamente. Solicita una nueva comprobación.');
            }
        }
    }

    public function rename(int $credentialId, int $userId, string $name): bool
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') {
            throw new RuntimeException('Escribe un nombre para la llave de acceso.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE usuarios_passkeys SET nombre = :name WHERE id = :id AND id_usuario = :user_id'
        );
        $statement->execute(['name' => $name, 'id' => $credentialId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function delete(int $credentialId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM usuarios_passkeys WHERE id = :id AND id_usuario = :user_id'
        );
        $statement->execute(['id' => $credentialId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM usuarios_passkeys WHERE id_usuario = :user_id');
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    public static function decodeTransports(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
