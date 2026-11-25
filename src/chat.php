<?php

declare(strict_types=1);

use App\ChatAccess;
use App\ChatException;
use App\ChatQueries;
use App\ChatRepository;
use App\ChatReviewWebhook;
use App\ChatService;
use App\DeepSeekChatClient;

function handle_chat_endpoint(string $route): never
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    try {
        if (current_user() === []) {
            throw new ChatException('Tu sesión terminó. Vuelve a iniciar sesión.', 401);
        }
        $user = current_operator_id();
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $expectedMethod = match ($route) {
            'list', 'thread' => 'GET',
            'new', 'send', 'close', 'review' => 'POST',
            default => throw new ChatException('Operación de chat no disponible.', 404),
        };
        if ($method !== $expectedMethod) {
            header('Allow: ' . $expectedMethod);
            throw new ChatException('Método no permitido.', 405);
        }
        if ($method === 'POST') {
            try {
                verify_csrf();
            } catch (RuntimeException $error) {
                throw new ChatException('Tu sesión cambió. Recarga la página para continuar.', 403);
            }
        }
        session_write_close();

        $pdo = db();
        $access = ChatAccess::load($pdo, $user);
        $scope = $access->signature();
        $previousScopes = $access->previousSignatures();
        $repository = new ChatRepository($pdo);
        $data = match ($route) {
            'list' => $repository->conversations($user, $scope, chat_number($_GET['offset'] ?? 0, 0), $previousScopes) + [
                'reportes' => $access->allows('generar_reporte'),
            ],
            'thread' => $repository->thread(chat_number($_GET['id'] ?? null), $user, $scope, chat_number($_GET['antes'] ?? 0, 0), $previousScopes),
            'new' => ['id' => $repository->withUserLock($user, static fn (): int => $repository->create(
                $user, $scope, chat_request_id($_POST['solicitud'] ?? null), $previousScopes
            ))],
            'close' => $repository->withUserLock($user, static function () use ($repository, $user, $scope, $previousScopes): array {
                $id = chat_number($_POST['id'] ?? null);
                $repository->close($id, $user, $scope, $previousScopes);
                return $repository->thread($id, $user, $scope, 0, $previousScopes);
            }),
            'review' => $repository->withUserLock($user, static function () use ($repository, $user, $scope, $previousScopes): array {
                $id = chat_number($_POST['id'] ?? null);
                $repository->markForReview($id, $user, $scope, (new ChatReviewWebhook())->send(...), $previousScopes);
                return ['revision' => 1];
            }),
            'send' => (new ChatService($pdo, $repository, new ChatQueries($pdo), (new DeepSeekChatClient())->complete(...)))
                ->ask($user, chat_number($_POST['id'] ?? null),
                    chat_request_id($_POST['solicitud'] ?? null),
                    is_string($_POST['mensaje'] ?? null) ? $_POST['mensaje'] : ''),
        };
        echo ChatRepository::json(['ok' => true] + $data);
    } catch (ChatException $error) {
        http_response_code($error->status);
        echo ChatRepository::json(['ok' => false, 'mensaje' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('Chat API ' . $error::class . ': ' . $error->getCode());
        http_response_code(500);
        echo ChatRepository::json(['ok' => false, 'mensaje' => 'No fue posible acceder al chat. Intenta de nuevo.']);
    }
    exit;
}

function chat_number(mixed $value, int $minimum = 1): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if ($number === false) {
        throw new ChatException('La solicitud de chat no es válida.');
    }
    return $number;
}


function chat_request_id(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
        throw new ChatException('La solicitud de chat no es válida.');
    }
    return $value;
}

function handle_chat_record(): never
{
    header('Cache-Control: no-store, private, max-age=0');
    $kind = is_string($_GET['chat_registro'] ?? null) ? $_GET['chat_registro'] : '';
    $result = null;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            header('Allow: GET');
            throw new ChatException('Método no permitido.', 405);
        }
        $id = chat_number($_GET['id'] ?? null);
        $pdo = db();
        $access = ChatAccess::load($pdo, current_operator_id());
        $result = (new ChatQueries($pdo))->record($kind, $id, $access);
    } catch (ChatException $error) {
        http_response_code($error->status === 405 ? 405 : 404);
    } catch (Throwable $error) {
        error_log('Detalle del chat: ' . $error->getCode());
        http_response_code(500);
    }
    require dirname(__DIR__) . '/views/chat-record.php';
    exit;
}
