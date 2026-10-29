<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombre, appat, apmat, email, password, usuario, tipo, cliente, `2fa`
             FROM usuarios
             WHERE email = :email
             ORDER BY id
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombre, appat, apmat, email, password, usuario, tipo, cliente, `2fa`
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function verifyPassword(array $user, string $plainPassword): bool
    {
        $stored = (string) ($user['password'] ?? '');
        $email = (string) ($user['email'] ?? '');
        if ($stored === '' || $email === '') {
            return false;
        }

        // Compatibilidad exacta con Sistema 14. No se reescribe el hash en la BD.
        $legacy = sha1(md5(sha1($email . $plainPassword)));
        if (hash_equals($stored, $legacy)) {
            return true;
        }

        // Permite hashes modernos si ya existieran, sin migrarlos durante el acceso.
        $info = password_get_info($stored);
        return ($info['algo'] ?? null) !== null && ($info['algo'] ?? null) !== 0
            && password_verify($plainPassword, $stored);
    }

    public function requiresTwoFactor(array $user): bool
    {
        return trim((string) ($user['2fa'] ?? '')) !== '';
    }

    public function verifyTotp(array $user, string $code, int $window = 1): bool
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', (string) ($user['2fa'] ?? '')) ?? '');
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($secret === '' || strlen($code) !== 6) {
            return false;
        }

        $key = $this->decodeBase32($secret);
        if ($key === '') {
            return false;
        }

        $counter = intdiv(time(), 30);
        $window = max(0, min(3, $window));
        for ($offset = -$window; $offset <= $window; $offset++) {
            $binaryCounter = pack('N2', 0, $counter + $offset);
            $hash = hash_hmac('sha1', $binaryCounter, $key, true);
            $position = ord($hash[19]) & 0x0f;
            $value = ((ord($hash[$position]) & 0x7f) << 24)
                | ((ord($hash[$position + 1]) & 0xff) << 16)
                | ((ord($hash[$position + 2]) & 0xff) << 8)
                | (ord($hash[$position + 3]) & 0xff);
            $expected = str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private function decodeBase32(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        for ($index = 0, $length = strlen($bits); $index + 8 <= $length; $index += 8) {
            $decoded .= chr(bindec(substr($bits, $index, 8)));
        }
        return $decoded;
    }
}
