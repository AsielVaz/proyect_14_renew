<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class ChatRepository
{
    private const HISTORY_SIZE = 40;

    public function __construct(private readonly PDO $pdo) {}

    public function conversations(int $user, string $scope, int $offset, array $previousScopes = []): array
    {
        $query = $this->pdo->prepare("SELECT id, titulo, actualizado_en, cerrado_en
            FROM s14_chat_conversaciones
            WHERE usuario_id = ? AND JSON_VALUE(contexto_json, '$.alcance') IN (?, ?, ?)
            ORDER BY actualizado_en DESC, id DESC LIMIT 21 OFFSET {$offset}");
        $query->execute([$user, ...self::scopes($scope, $previousScopes)]);
        $rows = $query->fetchAll();
        return ['conversaciones' => array_slice($rows, 0, 20), 'hay_mas' => count($rows) > 20];
    }

    public function create(int $user, string $scope, string $request, array $previousScopes = []): int
    {
        $existing = $this->pdo->prepare("SELECT id FROM s14_chat_conversaciones WHERE usuario_id = ?
            AND JSON_VALUE(contexto_json, '$.alcance') IN (?, ?, ?) AND JSON_VALUE(contexto_json, '$.creacion') = ? LIMIT 1");
        $existing->execute([$user, ...self::scopes($scope, $previousScopes), $request]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $query = $this->pdo->prepare('INSERT INTO s14_chat_conversaciones (usuario_id, contexto_json) VALUES (?, ?)');
        $query->execute([$user, self::json(['alcance' => $scope, 'creacion' => $request])]);
        return (int) $this->pdo->lastInsertId();
    }

    public function conversation(int $id, int $user, string $scope, array $previousScopes = []): array
    {
        $query = $this->pdo->prepare("SELECT id, titulo, resumen, contexto_json, cerrado_en, revision
            FROM s14_chat_conversaciones
            WHERE id = ? AND usuario_id = ? AND JSON_VALUE(contexto_json, '$.alcance') IN (?, ?, ?)");
        $query->execute([$id, $user, ...self::scopes($scope, $previousScopes)]);
        $conversation = $query->fetch();
        if (!$conversation) {
            throw new ChatException('La conversación ya no está disponible para tu cuenta.', 404);
        }
        return $conversation;
    }

    public function history(int $id, int $before = 0, int $limit = self::HISTORY_SIZE): array
    {
        $query = $this->pdo->prepare('SELECT id, rol, contenido, creado_en FROM s14_chat_mensajes
            WHERE conversacion_id = ?' . ($before > 0 ? ' AND id < ?' : '') . " ORDER BY id DESC LIMIT {$limit}");
        $query->execute($before > 0 ? [$id, $before] : [$id]);
        return array_reverse($query->fetchAll());
    }

    public function thread(int $id, int $user, string $scope, int $before, array $previousScopes = []): array
    {
        $conversation = $this->conversation($id, $user, $scope, $previousScopes);
        $messages = $this->history($id, $before, self::HISTORY_SIZE + 1);
        return [
            'conversacion' => array_intersect_key($conversation, array_flip(['id', 'titulo', 'cerrado_en', 'revision'])),
            'mensajes' => array_slice($messages, -self::HISTORY_SIZE),
            'hay_mas' => count($messages) > self::HISTORY_SIZE,
        ];
    }

    public function startTurn(int $id, string $text, string $request, array $conversation, array &$context): int
    {
        return $this->transaction(function () use ($id, $text, $request, $conversation, &$context): int {
            $message = $this->insert($id, 'usuario', $text);
            $context['turno'] = ['solicitud' => $request, 'mensaje_id' => $message,
                'huella' => hash('sha256', $text), 'completo' => false];
            $query = $this->pdo->prepare('UPDATE s14_chat_conversaciones
                SET titulo = ?, contexto_json = ?, actualizado_en = CURRENT_TIMESTAMP(6) WHERE id = ?');
            $query->execute([$conversation['titulo'] ?: mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 70),
                self::json($context), $id]);
            return $message;
        });
    }

    public function finishTurn(int $id, string $answer, array $context): void
    {
        $this->transaction(function () use ($id, $answer, $context): void {
            $this->insert($id, 'asistente', $answer);
            $context['turno']['completo'] = true;
            $reference = $context['referencia'] ?? [];
            $summary = $reference === [] ? null : implode(', ', array_map(
                static fn (string $key, mixed $value): string => $key . '=' . $value,
                array_keys($reference), $reference
            ));
            $query = $this->pdo->prepare('UPDATE s14_chat_conversaciones
                SET contexto_json = ?, resumen = ?, actualizado_en = CURRENT_TIMESTAMP(6) WHERE id = ?');
            $query->execute([self::json($context), $summary, $id]);
        });
    }

    public function close(int $id, int $user, string $scope, array $previousScopes = []): void
    {
        $this->conversation($id, $user, $scope, $previousScopes);
        $query = $this->pdo->prepare('UPDATE s14_chat_conversaciones
            SET cerrado_en = CURRENT_TIMESTAMP(6) WHERE id = ? AND cerrado_en IS NULL');
        $query->execute([$id]);
    }

    public function markForReview(int $id, int $user, string $scope, array $previousScopes = []): void
    {
        // Conserva la fecha de actividad administrada por ON UPDATE.
        $query = $this->pdo->prepare("UPDATE s14_chat_conversaciones
            SET revision = 1, actualizado_en = actualizado_en
            WHERE id = ? AND usuario_id = ? AND JSON_VALUE(contexto_json, '$.alcance') IN (?, ?, ?)
                AND COALESCE(revision, 0) <> 1");
        $query->execute([$id, $user, ...self::scopes($scope, $previousScopes)]);
        if ($query->rowCount() === 0) {
            $this->conversation($id, $user, $scope, $previousScopes);
        }
    }

    public function audit(int $message, string $tool, array $filters, string $status, ?int $count): void
    {
        $query = $this->pdo->prepare('INSERT INTO s14_chat_consultas
            (mensaje_id, herramienta, filtros_json, estado, cantidad_resultados) VALUES (?, ?, ?, ?, ?)');
        // Los nombres y folios pueden contener datos sensibles escritos en lenguaje libre.
        $safeFilters = array_intersect_key($filters, array_flip(['id', 'factura_id', 'pago_id', 'empresa_id', 'clave_corta_id', 'desde', 'hasta', 'reporte', 'tipo', 'tipo_correo', 'vista', 'cliente_id', 'pendientes_documento', 'con_diferencia', 'estado_stp', 'resumir_estados', 'solo_problemas', 'contar', 'totalizar', 'limite', 'pagina', 'orden']));
        $query->execute([$message, $tool, self::json((object) $safeFilters), $status, $count]);
    }

    public function withUserLock(int $user, callable $work): mixed
    {
        $key = 's14_chat_' . $user;
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$key]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new ChatException('Hay una consulta en curso. Espera su respuesta para continuar.', 409);
        }
        try {
            return $work();
        } finally {
            $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$key]);
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }

    private static function scopes(string $scope, array $previousScopes): array
    {
        return [$scope, $previousScopes[0] ?? $scope, $previousScopes[1] ?? $scope];
    }

    private function insert(int $id, string $role, string $message): int
    {
        $query = $this->pdo->prepare('INSERT INTO s14_chat_mensajes (conversacion_id, rol, contenido) VALUES (?, ?, ?)');
        $query->execute([$id, $role, $message]);
        return (int) $this->pdo->lastInsertId();
    }

    private function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }
}

