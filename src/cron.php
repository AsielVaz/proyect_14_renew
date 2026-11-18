<?php

declare(strict_types=1);

use App\MovementNotificationService;

function handle_cron_endpoint(string $endpoint): never
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    if ($endpoint !== 'notificar-movimientos') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Cron no disponible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $configuredToken = trim(env_value('MOVEMENT_NOTIFICATION_CRON_TOKEN', ''));
    $providedToken = trim((string) ($_GET['token'] ?? ''));
    if ($configuredToken === '' || strlen($configuredToken) < 32 || str_starts_with($configuredToken, 'replace_with_')) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'El cron no está configurado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Token no válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    try {
        $service = new MovementNotificationService(db());
        $result = $service->run(new DateTimeImmutable('now'));
        if (!$result['ok']) {
            http_response_code(500);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Movement notification cron: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible completar el cron. Revisa el log del servidor.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
