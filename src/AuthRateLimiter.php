<?php

declare(strict_types=1);

namespace App;

final class AuthRateLimiter
{
    private readonly string $directory;

    public function __construct(
        private readonly string $applicationKey,
        private readonly int $maximumAttempts = 5,
        private readonly int $periodSeconds = 900,
    ) {
        $this->directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 's14-auth-rate';
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
    }

    public function blockedFor(string $identity, string $ip): int
    {
        $attempts = $this->read($this->path($identity, $ip));
        if (count($attempts) < $this->maximumAttempts) {
            return 0;
        }
        return max(0, $this->periodSeconds - (time() - min($attempts)));
    }

    public function recordFailure(string $identity, string $ip): void
    {
        $path = $this->path($identity, $ip);
        $attempts = $this->read($path);
        $attempts[] = time();
        $this->write($path, $attempts);
    }

    public function clear(string $identity, string $ip): void
    {
        $path = $this->path($identity, $ip);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $identity, string $ip): string
    {
        $digest = hash_hmac('sha256', mb_strtolower(trim($identity)) . '|' . $ip, $this->applicationKey);
        return $this->directory . DIRECTORY_SEPARATOR . $digest . '.json';
    }

    private function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        $threshold = time() - $this->periodSeconds;
        return array_values(array_filter(
            is_array($decoded) ? $decoded : [],
            static fn (mixed $value): bool => is_int($value) && $value >= $threshold
        ));
    }

    private function write(string $path, array $attempts): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        @file_put_contents($path, json_encode($attempts, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
