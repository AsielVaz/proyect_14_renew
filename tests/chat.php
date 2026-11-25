<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ChatAccess;
use App\ChatException;
use App\ChatQueries;
use App\ChatRepository;
use App\ChatService;

$pdo = db();
foreach (['s14_chat_conversaciones', 's14_chat_mensajes', 's14_chat_consultas'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $pdo->exec(preg_replace('/,\R\s+CONSTRAINT [^\r\n]+/', '', $ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE usuarios (
    id INT PRIMARY KEY, tipo VARCHAR(30), cliente INT, empresa_asignada INT
)');
$pdo->exec("INSERT INTO usuarios VALUES (42, 'cliente', 7001, 0), (43, 'cliente', 7002, 0), (44, 'supercapturista', 0, 0)");
$pdo->exec('CREATE TEMPORARY TABLE cliente_empresa (id_usuario INT, id_empresa INT)');
$pdo->exec('INSERT INTO cliente_empresa VALUES (42, 23)');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expectError = static function (int $status, callable $action) use ($assert): void {
    try {
        $action();
    } catch (ChatException $error) {
        $assert($error->status === $status, 'Se recibió un estado distinto al esperado.');
        return;
    }
    throw new RuntimeException('Se esperaba un rechazo.');
};

$access = ChatAccess::load($pdo, 42);
$otherAccess = ChatAccess::load($pdo, 43);
$staff = ChatAccess::load($pdo, 44);
$assert(!$access->allows('generar_reporte') && !$staff->allows('generar_reporte'), 'Los reportes exigen un rol administrativo.');
$params = [];
$where = $access->conditions('empresa', 'cliente', $params, false);
$assert(str_contains($where, 'cliente = ') && array_values($params) === [7001],
    'El cliente consulta cualquier empresa dentro de su cartera.');

$repository = new ChatRepository($pdo);
$queries = new ChatQueries($pdo);
$scope = $access->signature();
$creation = str_repeat('a', 32);
$id = $repository->withUserLock(42, static fn (): int => $repository->create(42, $scope, $creation));
$assert($id === $repository->withUserLock(42, static fn (): int => $repository->create(42, $scope, $creation)),
    'Reintentar la creación no duplica la conversación.');
$expectError(404, static fn () => $repository->thread($id, 43, $otherAccess->signature(), 0));
$assert($repository->conversations(43, $otherAccess->signature(), 0)['conversaciones'] === [],
    'El historial debe quedar separado por usuario.');

$attempt = 0;
$fake = static function (array $messages, array $tools) use (&$attempt, $assert): array {
    $attempt++;
    $assert(!str_contains(json_encode($messages, JSON_THROW_ON_ERROR), '123456789012345678'), 'Se filtró una CLABE.');
    $assert(!in_array('generar_reporte', array_column(array_column($tools, 'function'), 'name'), true),
        'Se ofreció una herramienta de reporte fuera del rol.');
    if ($attempt === 1) {
        throw new ChatException('Interrupción de prueba', 502);
    }
    if ($attempt === 2) {
        return ['tool_calls' => [
            ['id' => 'fixture-1', 'type' => 'function', 'function' => [
                'name' => 'generar_reporte', 'arguments' => '{"reporte":"conta"}',
            ]],
        ]];
    }
    if ($attempt === 3) {
        $last = end($messages);
        $assert($last['role'] === 'tool' && str_contains($last['content'], 'permiso'), 'La solicitud prohibida debe rechazarse.');
    }
    return ['content' => 'Respuesta de prueba.'];
};
$service = new ChatService($pdo, $repository, $queries, $fake);
$question = 'Consulta para la prueba con 123456789012345678';
$turn = str_repeat('b', 32);
$expectError(502, static fn () => $service->ask(42, $id, $turn, $question));
$assert(count($repository->thread($id, 42, $scope, 0)['mensajes']) === 1, 'El mensaje debe conservarse si falla el proveedor.');
$result = $service->ask(42, $id, $turn, $question);
$assert(array_column($result['mensajes'], 'rol') === ['usuario', 'asistente'], 'Reintentar no debe duplicar el mensaje.');
$service->ask(42, $id, $turn, $question);
$assert($attempt === 3, 'Reenviar una pregunta ya contestada no llama al proveedor.');
$expectError(409, static fn () => $service->ask(42, $id, $turn, 'Otra pregunta'));
$assert($pdo->query('SELECT estado FROM s14_chat_consultas')->fetchColumn() === 'denegada', 'Se debe auditar la consulta denegada.');

$pdo->exec('CREATE TEMPORARY TABLE clientes (id INT PRIMARY KEY, nombre VARCHAR(40))');
$pdo->exec("INSERT INTO clientes VALUES (7001,'Cliente QA'),(7002,'Otra cartera')");
$pdo->exec('CREATE TEMPORARY TABLE empresas (id INT PRIMARY KEY, razon VARCHAR(40))');
$pdo->exec("INSERT INTO empresas VALUES (23,'Empresa QA'),(132,'Restringida')");
$pdo->exec('CREATE TEMPORARY TABLE claves_cortas (
    id INT PRIMARY KEY, clave_corta VARCHAR(20), razon_social VARCHAR(40), rfc VARCHAR(13)
)');
$pdo->exec("INSERT INTO claves_cortas VALUES (99,'QA','Receptor QA','RFCQA')");
$pdo->exec('CREATE TEMPORARY TABLE facturas (
    id INT PRIMARY KEY, razon INT, nombre INT, serie VARCHAR(10), folio VARCHAR(20), uuid VARCHAR(36), fecha DATE,
    total_iva DECIMAL(10, 2), moneda VARCHAR(10), metodo_pago INT, status VARCHAR(30), status_pago VARCHAR(30), cancelado INT, id_clave_corta INT
)');
$pdo->exec("INSERT INTO facturas VALUES
    (701001,23,7001,'QA','105',NULL,'2026-03-01',120,'MXN',1,'Vigente','Pendiente',0,99),
    (701002,23,7002,'QA','105',NULL,'2026-03-01',920,'MXN',1,'Vigente','Pendiente',0,99),
    (701003,132,7001,'QA','105',NULL,'2026-02-01',820,'MXN',1,'Vigente','Pendiente',0,99)");
$round = 0;
$fixture = static function (array $messages) use (&$round, $assert): array {
    $round++;
    if ($round === 1) {
        return ['tool_calls' => [['id' => 'fixture-2', 'type' => 'function', 'function' => [
            'name' => 'buscar_facturas', 'arguments' => '{"id":701001}',
        ]]]];
    }
    if ($round === 2) {
        $last = end($messages);
        $result = json_decode($last['content'], true, 512, JSON_THROW_ON_ERROR);
        $assert(array_column($result['filas'], 'id') === [701001], 'El SQL devolvió una factura fuera del alcance.');
    } else {
        $assert(str_contains($messages[0]['content'], '"factura_id":701001'), 'El turno siguiente debe recordar la factura.');
    }
    return ['content' => 'Factura de prueba.'];
};
$serviceWithQuery = new ChatService($pdo, $repository, $queries, $fixture);
$serviceWithQuery->ask(42, $id, str_repeat('e', 32), 'Busca QA105.');
$serviceWithQuery->ask(42, $id, str_repeat('f', 32), '¿Y su saldo?');

$pdo->exec("INSERT INTO facturas VALUES
    (701004,23,7001,'QA','106',NULL,'2026-03-01',45,'MXN',1,'Vigente','Pendiente',0,99),
    (701005,23,7001,'QA','107',NULL,'2026-03-01',50,'MXN',1,'Vigente','Pendiente',0,99),
    (701006,23,7002,'QA','108',NULL,'2099-10-01',60,'MXN',1,'Vigente','Pendiente',0,99),
    (701007,23,7001,'QA','109',NULL,'2099-10-01',60,'MXN',1,'Vigente','Pendiente',0,0)");
$direct = new ChatService($pdo, $repository, $queries, static function (): never {
    throw new RuntimeException('La pregunta de listado debía resolverse sin llamar al modelo.');
});
$ask = static function (string $text) use ($direct, $id): string {
    $thread = $direct->ask(42, $id, bin2hex(random_bytes(16)), $text);
    return end($thread['mensajes'])['contenido'];
};
$latest = $ask("1. Cual es la factura mas reciente\n2.");
$assert(str_contains($latest, 'Factura #701005') && str_contains($latest, 'folio QA107')
    && !str_contains($latest, '#701006'), 'La última factura debe buscarse en todo el historial autorizado y desempatar por ID.');
$numbered = $ask('Enumera las últimas 2 facturas');
$assert(str_contains($numbered, '1. Factura #701005') && str_contains($numbered, '2. Factura #701004')
    && str_contains($numbered, 'siguiente página'), 'El listado debe numerar y anunciar su continuación.');
$assert(str_contains($ask('Siguiente página'), '3. Factura #701001'), 'La continuación debe mantener los filtros y la numeración.');
$assert(str_contains($ask('¿Cuántas facturas hay?'), 'Total de facturas en tu alcance: 4.'), 'El total debe contar todas las facturas autorizadas.');
$assert(str_contains($ask('Cuál es la primera factura'), 'Factura #701003'), 'La primera factura puede ser de la empresa 132.');
$filtered = $queries->filters('buscar_facturas', ['desde' => '1999-01-01', 'hasta' => '1999-01-02', 'contar' => true], $access);
$zero = $queries->run('buscar_facturas', $filtered, $access);
$assert($zero['totales']['registros'] === 0 && !isset($zero['mensaje']), 'Cero es un conteo válido.');
$period = App\ChatCollection::request('¿Cuántas facturas hay de este mes?', []);
$assert(isset($period['filtros']['desde'], $period['filtros']['hasta']), 'Se conserva un periodo expresamente solicitado.');
$assert(App\ChatCollection::request('¿Cuántos pagos STP hay?', [])['herramienta'] === 'consultar_stp', 'El conteo STP usa su fuente.');

$pdo->exec('UPDATE cliente_empresa SET id_empresa = 24 WHERE id_usuario = 42');
$assert(ChatAccess::load($pdo, 42)->signature() === $scope
    && count($repository->thread($id, 42, $scope, 0)['mensajes']) > 0,
    'Cambiar las empresas vinculadas no altera el alcance del chat.');
$pdo->exec('UPDATE cliente_empresa SET id_empresa = 23 WHERE id_usuario = 42');
$repository->withUserLock(42, static fn () => $repository->close($id, 42, $scope));
$expectError(409, static fn () => $service->ask(42, $id, str_repeat('d', 32), 'Otra pregunta'));

echo "chat: permisos, historial, auditoría, reintentos, factura más reciente, listado y conteo verificados\n";

