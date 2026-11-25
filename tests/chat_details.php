<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ChatAccess;
use App\ChatException;
use App\ChatQueries;

$pdo = db();
$fixtures = [
    'usuarios' => 'id INT PRIMARY KEY, tipo VARCHAR(30), cliente INT, empresa_asignada INT',
    'cliente_empresa' => 'id_usuario INT, id_empresa INT',
    'empresas' => 'id INT PRIMARY KEY, razon VARCHAR(50)',
    'clientes' => 'id INT PRIMARY KEY, nombre VARCHAR(50)',
    'claves_cortas' => 'id INT PRIMARY KEY, id_cliente INT, clave_corta VARCHAR(24), razon_social VARCHAR(50), rfc VARCHAR(20)',
    'metodos_pago' => 'id INT PRIMARY KEY, clave VARCHAR(3)',
    'catalogo_monedas' => 'id INT PRIMARY KEY, clave_moneda VARCHAR(5)',
    'facturas' => 'id INT PRIMARY KEY, razon INT, nombre INT, id_clave_corta VARCHAR(20), serie VARCHAR(10), folio INT, uuid VARCHAR(36),
        fecha DATE, total_iva DECIMAL(12,2), moneda INT, metodo_pago INT, status VARCHAR(40), status_pago VARCHAR(40), cancelado INT,
        desactivado INT, id_pago INT, xml_firmado TEXT, xml_total DECIMAL(12,2), xml_subtotal DECIMAL(12,2),
        retencion_transporte VARCHAR(10), retencion_iva_tasa DECIMAL(8,6)',
    'facturas_complementos' => 'id INT PRIMARY KEY, razon INT, nombre INT, serie VARCHAR(10), folio INT, uuid VARCHAR(36),
        fecha_pago DATETIME, total_factura DECIMAL(12,2), moneda INT, status VARCHAR(40), id_pago INT, xml_firmado TEXT',
    'facturar_rel_complementos' => 'id INT PRIMARY KEY, id_factura INT, id_complemento INT, monto DECIMAL(12,2), num_pago INT,
        saldo DECIMAL(12,2), restante DECIMAL(12,2), fecha_pago DATETIME',
    'conceptos' => 'id INT PRIMARY KEY, razon VARCHAR(24), concepto VARCHAR(100), aplica_iva VARCHAR(10)',
    'detalle_factura' => 'id INT PRIMARY KEY, id_factura INT, id_producto INT, cantidad DECIMAL(10,2), concepto TEXT, iva INT,
        precio_unitario DECIMAL(10,2), precio_con_iva DECIMAL(10,2), precio_neto DECIMAL(10,2), precio_neto_iva DECIMAL(10,2)',
    'pagos' => 'id INT PRIMARY KEY, nombre VARCHAR(20), monto DECIMAL(12,2), saldo_acreditable DECIMAL(12,2), status VARCHAR(20),
        id_factura INT, id_factura_res VARCHAR(100), empresa VARCHAR(20), id_captura INT, nombre_corto VARCHAR(20),
        id_captura_edo_cta INT, fecha_banco VARCHAR(20), fecha_operacion DATE, fecha_asignacion DATE, fecha_captura DATE,
        tipo_movimiento VARCHAR(50), descripcion TEXT, descripcion_detallada TEXT, detalle TEXT',
    'captura' => 'id INT PRIMARY KEY, razon INT, nombre_corto INT',
    'bancos' => 'id INT PRIMARY KEY, razon INT, banco VARCHAR(30), moneda VARCHAR(8), numero_cuenta VARCHAR(25), clabe_interbancaria VARCHAR(30)',
    'captura_edo_cta' => 'id INT PRIMARY KEY, id_captura INT, cuenta INT, cliente_asignado VARCHAR(30), referencia VARCHAR(100),
        descripcion TEXT, descrip_det TEXT, deposito DECIMAL(12,2), retiro DECIMAL(12,2), saldo DECIMAL(12,2),
        fecha DATE, fecha_operacion DATE, fecha_sistema DATE, tipo_movimiento VARCHAR(30)',
    'relacion_factura_pago' => 'id INT PRIMARY KEY, id_pago INT, id_factura INT, id_factura_com INT',
    'pre_ejecuta_plantilla' => 'id INT PRIMARY KEY, cliente INT, folio VARCHAR(30), monto DECIMAL(12,2), fecha_creacion DATETIME,
        fecha_ejecucion DATETIME, status VARCHAR(30), estado VARCHAR(30), status_banxico VARCHAR(30), id_pago INT, id_captura_edo INT, causa_devolucion TEXT, status_pre TEXT',
];
foreach ($fixtures as $table => $fields) {
    $pdo->exec("CREATE TEMPORARY TABLE {$table} ({$fields})");
}

$pdo->exec("INSERT INTO usuarios VALUES (42,'cliente',101,0),(43,'esclavo',102,0),(44,'administrador',0,24),(45,'capturista',0,0),(46,'administrador',0,0),(47,'administrador_b',0,24),(82,'administrador_b',0,132),(1,'administrador',0,24)");
$pdo->exec('INSERT INTO cliente_empresa VALUES (42,23),(45,24),(46,24)');
$pdo->exec("INSERT INTO empresas VALUES (23,'Emisora QA'),(24,'Otra emisora'),(132,'Restringida')");
$pdo->exec("INSERT INTO clientes VALUES (101,'Cartera A'),(102,'Cartera B')");
$pdo->exec("INSERT INTO claves_cortas VALUES (1,101,'QA','Receptor A',' rfcqa123 '),(2,102,'QB','Receptor B','RFCQA123'),(3,101,'QC','Receptor C','RFCQA321')");
$pdo->exec("INSERT INTO metodos_pago VALUES (1,'PUE'),(2,'PPD')");
$pdo->exec("INSERT INTO catalogo_monedas VALUES (1,'MXN')");
$pdo->exec("INSERT INTO facturas
    (id,razon,nombre,id_clave_corta,serie,folio,uuid,fecha,total_iva,moneda,metodo_pago,status,status_pago,cancelado,desactivado,id_pago) VALUES
    (2,23,101,'1','Z',2,NULL,'2026-05-01',600,1,1,'Vigente','Pagado',0,0,NULL),
    (10,23,101,'1','A',105,'QA-10','2026-05-01',200,1,2,'Vigente','Pendiente',0,0,32),
    (11,23,101,'3','A',105,'QA-11','2026-05-02',300,1,1,'Vigente','Pagado',0,0,NULL),
    (12,23,102,'2','A',106,'QA-12','2026-05-01',500,1,2,'Vigente','Pendiente',0,0,33),
    (13,23,101,'1','A',107,'QA-13','2026-05-02',800,1,2,'Vigente','Pendiente',0,0,NULL),
    (14,23,101,'1','A',108,'QA-14','2026-05-02',600,1,2,'Cancelado','Pendiente',1,0,NULL),
    (15,23,101,'1','A',109,'QA-15','2026-05-03',700,1,2,'Vigente','Pagado',0,0,NULL),
    (16,132,101,'1','A',110,'QA-16','2026-05-03',700,1,2,'Vigente','Pendiente',0,0,NULL),
    (17,24,101,'1','A',111,'QA-17','2026-05-03',700,1,2,'Vigente','Pendiente',0,0,NULL)");
$statement = $pdo->prepare('UPDATE facturas SET xml_firmado = ? WHERE id = 10');
$statement->execute(['<Comprobante><Concepto><Traslado Impuesto="002" TasaOCuota="0.160000"/></Concepto></Comprobante>']);
$pdo->exec("INSERT INTO facturas_complementos VALUES
    (21,23,101,'P',21,'QA-21','2026-05-02',60,1,'Timbrado',32,'<Comprobante/>'),
    (22,23,101,'P',22,'QA-22','2026-05-03',30,1,'Timbrado',NULL,'<Comprobante/>'),
    (23,23,102,'P',23,'QA-23','2026-05-04',40,1,'Timbrado',NULL,'<Comprobante/>'),
    (24,23,101,'P',24,'QA-24','2026-05-04',700,1,'Timbrado',NULL,'<Comprobante/>')");
$pdo->exec("INSERT INTO facturar_rel_complementos VALUES
    (1,10,21,20,1,200,180,'2026-05-02'),(2,12,21,40,1,500,460,'2026-05-02'),
    (3,10,22,30,2,180,150,'2026-05-03'),(4,13,23,40,1,800,760,'2026-05-04'),
    (5,15,24,700,1,700,0,'2026-05-04')");
$pdo->exec("INSERT INTO conceptos VALUES (1,'23','Servicio QA','Si')");
$pdo->exec("INSERT INTO detalle_factura VALUES (1,10,1,2,'Servicio QA',16,100,116,200,232)");
$pdo->exec("INSERT INTO bancos VALUES (41,23,'Banco QA','MXN','123456789012','123456789012345678')");
$pdo->exec('INSERT INTO captura VALUES (40,23,41)');
$pdo->exec("INSERT INTO captura_edo_cta VALUES
    (51,40,41,'101','REF-QA','Línea QA','Detalle QA',20,0,1234,'2026-05-02','2026-05-01','2026-05-03','Deposito'),
    (52,40,41,'102','REF-OCULTA','Línea oculta','Otro',10,0,7000,'2026-05-02','2026-05-02','2026-05-04','Deposito')");
$pdo->exec("INSERT INTO pagos VALUES
    (31,'101',456321.34,450000,'asignado',2,NULL,'23',NULL,NULL,NULL,'2026-05-02','2026-05-01','2026-05-03','2026-05-04','Deposito','Sin captura','CLABE 123456789012345678','Detalle QA'),
    (32,'101',20,18,'asignado',10,' 000000000000021 ','23',40,'41',51,'2026-05-02','2026-05-01','2026-05-03','2026-05-04','Retiro','Pago ligado','Detalle autorizado','Movimiento QA'),
    (33,'102',10,10,'asignado',12,'23x','23',40,'41',52,'2026-05-02','2026-05-01','2026-05-03','2026-05-04','Deposito','Otro cliente','Oculto','Otro'),
    (34,'101',456321.34,1500,'asignado',NULL,NULL,'23',40,'41',NULL,'2026-05-03','2026-05-01','2026-05-03','2026-05-04','Abono por comisión','Comisión QA','Comisión','Detalle'),
    (456321,'101',1,1,'asignado',NULL,NULL,'23',40,'41',NULL,'2026-05-03','2026-05-01','2026-05-03','2026-05-04','Deposito','ID QA','ID','Detalle')");
$pdo->exec('INSERT INTO relacion_factura_pago VALUES (1,32,10,21),(2,32,10,21)');
$pdo->exec("INSERT INTO pre_ejecuta_plantilla VALUES
    (61,101,'REF-QA',20,'2026-05-01 10:00','2026-05-02 10:00','1','LQ','Liquidado',32,51,NULL,NULL)");

$queries = new ChatQueries($pdo);
$client = ChatAccess::load($pdo, 42);
$staff = ChatAccess::load($pdo, 44);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $tool, array $args, ChatAccess $access) use ($queries): array {
    return $queries->run($tool, $queries->filters($tool, $args, $access), $access);
};
$ids = static fn (array $result): array => array_map('intval', array_column($result['filas'], 'id'));

$check($run('detalle_factura', ['id' => 12], $client)['mensaje'] === ChatQueries::NOT_FOUND, 'Factura de otra cartera.');
$check($run('detalle_factura', ['id' => 16], $client)['mensaje'] === ChatQueries::NOT_FOUND, 'Empresa 132 restringida para clientes.');
$check($run('detalle_movimiento', ['id' => 33], $client)['filas'] === [], 'Movimiento de otra cartera.');
$check($run('consultar_banco', ['id' => 52], $client)['filas'] === [], 'Línea de otra cartera.');
$check($run('detalle_factura', ['id' => 10], ChatAccess::load($pdo, 45))['filas'] === [], 'Emisor fuera de la asignación.');
$noCapture = $run('detalle_movimiento', ['id' => 31], $client);
$check($ids($noCapture) === [31] && $noCapture['filas'][0]['linea_bancaria_id'] === null, 'Debe aparecer el movimiento sin captura.');
$check((bool) $noCapture['filas'][0]['sin_documento'] && $noCapture['secciones']['facturas']['filas'] === [], 'La bandera 2 no es una factura.');
$check(!str_contains($noCapture['filas'][0]['descripcion_detallada'], '123456789012345678'), 'Se filtró un número de cuenta.');
$check($run('consultar_pagos', ['factura_id' => 2], $client)['filas'] === [], 'No debe asociarse el pago por la bandera 2.');
$check($ids($run('consultar_pagos', ['monto' => 456321.34], $client)) === [34,31], 'El importe decimal no es un ID.');
$check($ids($run('consultar_pagos', ['texto' => 'REF-QA'], $client)) === [32], 'Se puede buscar por referencia bancaria.');
$check($ids($run('consultar_pagos', ['monto' => 456321.34, 'tipo' => 'comision'], $client)) === [34], 'El tipo comisión debe ser distinguible.');
$ambiguous = $run('buscar_facturas', ['folio' => 'A-105'], $client);
$check($ids($ambiguous) === [11,10] && $ambiguous['varias_coincidencias'], 'Los folios ambiguos conservan sus candidatos.');

$invoice = $run('detalle_factura', ['id' => 10], $client);
$check($invoice['filas'][0]['tratamiento_fiscal'] === 'Típico · IVA 16%', 'El tratamiento debe provenir del CFDI timbrado.');
$check((float) $invoice['filas'][0]['aplicado_ppd'] === 50.0 && (float) $invoice['filas'][0]['saldo_ppd_registrado'] === 150.0, 'Saldo con varios complementos.');
$check($ids($invoice['secciones']['complementos']) === [21,22], 'Deben mostrarse varios complementos.');
$check((float) $invoice['secciones']['complementos']['filas'][0]['monto_aplicado'] === 20.0, 'Usa el importe aplicado a la factura.');
$check($ids($invoice['secciones']['movimientos']) === [32], 'Un pago encontrado por varios vínculos aparece una vez.');
$check((float) $invoice['secciones']['conceptos']['filas'][0]['precio_unitario_con_iva'] === 116.0, 'Los conceptos conservan precios guardados.');
$check($run('detalle_factura', ['id' => 13], $client)['filas'][0]['saldo_ppd_registrado'] === null, 'No se calcula un saldo con relaciones ocultas.');
$pending = ['empresa_id' => 23, 'clave_corta_id' => 1];
$check($ids($run('pendientes_ppd', $pending, $client)) === [10], 'PPD por RFC para la cartera propia.');
$staffPending = $ids($run('pendientes_ppd', $pending, $staff));
sort($staffPending);
$check($staffPending === [10,12,13], 'PPD por RFC puede incluir dos carteras autorizadas del mismo emisor.');

$complement = $run('detalle_complemento', ['id' => 21], $client);
$check($ids($complement['secciones']['facturas']) === [10] && !isset($complement['filas'][0]['pdf']), 'Oculta factura relacionada y evita descargar el complemento completo.');
try {
    $queries->assertDocument('complemento', 21, $client);
    throw new RuntimeException('El acceso directo a la descarga debía denegarse.');
} catch (ChatException $expected) {
    $check($expected->status === 404, 'La descarga denegada debe manejarse igual que un registro inexistente.');
}
$queries->assertDocument('complemento', 21, $staff);
$check($queries->invoiceXml(10, $client)['filename'] === 'Factura-10.xml', 'Descarga protegida del XML de la factura.');
$bank = $run('consultar_banco', ['pago_id' => 32], $client);
$check($ids($bank) === [51] && $bank['filas'][0]['pagos_asociados'] === '32', 'Distingue línea bancaria y pago.');
$check($bank['filas'][0]['saldo_bancario'] === null && $bank['filas'][0]['fecha_sistema'] === '2026-05-03', 'Protege saldo de cuenta y distingue fechas.');
$stp = $run('consultar_stp', ['folio' => 'REF-QA'], $client);
$check($ids($stp) === [61] && (int) $stp['filas'][0]['pago_id'] === 32 && $stp['filas'][0]['status_banxico'] === 'Liquidado', 'Distingue orden STP y pago.');
foreach (['s14_chat_conversaciones', 's14_chat_mensajes', 's14_chat_consultas'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $pdo->exec(preg_replace('/,\R\s+CONSTRAINT [^\r\n]+/', '', $ddl));
}
$chatRepository = new App\ChatRepository($pdo);
$chat = new App\ChatService($pdo, $chatRepository, $queries, static function (): never {
    throw new RuntimeException('La pregunta sobre la última transacción no debe depender del modelo.');
});
$conversation = $chatRepository->create(42, $client->signature(), bin2hex(random_bytes(16)));
$pdo->exec("INSERT INTO pre_ejecuta_plantilla VALUES
    (62,102,'AJENA',10,'2026-05-04 10:00',NULL,'1','CONFIRMADO','Liquidado',33,NULL,NULL,NULL)");
$update = $pdo->prepare('UPDATE pre_ejecuta_plantilla SET estado = ?, causa_devolucion = ?, status_pre = ? WHERE id = 61');
foreach ([
    ['LQ', null, null, 'Sí. La última transacción STP se liquidó.', false, false],
    [' CONFIRMADO ', null, 'Revisar confirmación', 'Se envió el comprobante.', true, true],
    [null, null, ' ', 'Aún no. La última transacción STP está en espera.', false, false],
    ['D', 'Cuenta de destino inválida', null, 'No. La última transacción STP fue devuelta.', true, false],
    ['C', null, null, 'No. La última transacción STP fue cancelada.', false, false],
    ['?', null, null, 'El estado registrado no se reconoce.', false, false],
] as [$code, $cause, $preStatus, $line, $alert, $receipt]) {
    $update->execute([$code, $cause, $preStatus]);
    $thread = $chat->ask(42, $conversation, bin2hex(random_bytes(16)), "1. se concreto la ultima transaccion de stp?\n2.");
    $answer = end($thread['mensajes'])['contenido'];
    $check(str_contains($answer, $line) && str_contains($answer, 'REF-QA') && !str_contains($answer, 'AJENA'),
        'Debe interpretar el campo estado de la última orden STP autorizada.');
    $check(str_contains($answer, 'Hay una posible incidencia registrada.') === $alert, 'La incidencia depende de causa_devolucion y status_pre.');
    $check(str_contains($answer, 'Se envió el comprobante.') === $receipt, 'El comprobante se informa cuando el estado es CONFIRMADO.');
    $result = $chat->ask(42, $conversation, bin2hex(random_bytes(16)), '1. La ultima transaccion de STP salio bien ?');
    $outcome = end($result['mensajes'])['contenido'];
    $check(str_contains($outcome, 'REF-QA') && !str_contains($outcome, 'AJENA'), 'Salió bien consulta la última orden autorizada.');
    $check(str_contains($outcome, 'Hay una posible incidencia registrada.') === $alert, 'Salió bien avisa si hubo posibles incidencias.');
    if (trim((string) $code) === 'CONFIRMADO' && $alert) {
        $check(str_starts_with($outcome, 'La última transacción STP se liquidó.') && str_contains($outcome, 'Se envió el comprobante.'),
            'Una liquidación con avisos no debe presentarse como un éxito sin incidencias.');
    } else {
        $check(str_contains($outcome, $line), 'Salió bien interpreta el estado de la orden.');
    }
    $result = $chat->ask(42, $conversation, bin2hex(random_bytes(16)), '¿se hizo el último pago?');
    $short = end($result['mensajes'])['contenido'];
    $check(str_contains($short, $line) && str_contains($short, 'REF-QA'), 'El último pago usa el contexto STP.');
    $result = $chat->ask(42, $conversation, bin2hex(random_bytes(16)), '¿hubo algún problema con la última transferencia?');
    $problem = end($result['mensajes'])['contenido'];
    $expectedProblem = match (trim((string) $code)) {
        'D' => 'Sí. La última transferencia STP fue devuelta.',
        'C' => 'La última transferencia STP fue cancelada.',
        default => $alert ? 'Sí. La última transferencia STP tiene un aviso' : 'No encontré devoluciones ni avisos',
    };
    $check(str_starts_with($problem, $expectedProblem) && str_contains($problem, 'REF-QA'),
        'La pregunta por problemas revisa la última orden, sin saltar a una orden anterior.');
}
$check(App\ChatCollection::request('¿Cuál es el estado de la última transferencia STP?', [])['modo'] === 'estado_stp',
    'Las preguntas directas por el estado STP usan la consulta de la última orden.');

$separateChat = $chatRepository->create(42, $client->signature(), bin2hex(random_bytes(16)));
$before = (int) $pdo->query('SELECT COUNT(*) FROM s14_chat_consultas')->fetchColumn();
$thread = $chat->ask(42, $separateChat, bin2hex(random_bytes(16)), '¿se hizo el último pago?');
$check(str_contains(end($thread['mensajes'])['contenido'], '¿Te refieres a una operación STP')
    && (int) $pdo->query('SELECT COUNT(*) FROM s14_chat_consultas')->fetchColumn() === $before,
    'En una conversación nueva el pago ambiguo se aclara antes de consultar.');
$thread = $chat->ask(42, $separateChat, bin2hex(random_bytes(16)), 'Sí, STP');
$check(str_contains(end($thread['mensajes'])['contenido'], 'REF-QA')
    && (int) $pdo->query('SELECT COUNT(*) FROM s14_chat_consultas')->fetchColumn() === $before + 1,
    'Confirmar STP retoma la intención de la pregunta original.');

$pdo->exec("UPDATE pre_ejecuta_plantilla SET fecha_creacion = '2000-01-01' WHERE id IN (61,62)");
$today = new DateTimeImmutable('today');
$insertStp = $pdo->prepare('INSERT INTO pre_ejecuta_plantilla
    (id,cliente,folio,monto,fecha_creacion,estado,id_pago,causa_devolucion,status_pre) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([
    [71, 'LQ', 0, 8, 101, 32, null, null],
    [72, 'D', 0, 9, 101, 32, null, null],
    [73, 'C', 0, 10, 101, 32, null, null],
    [74, null, 0, 11, 101, 32, null, ' '],
    [75, 'CONFIRMADO', 0, 12, 101, 32, null, 'Revisar confirmación'],
    [76, 'C', 1, 12, 101, 32, 'Aviso de prueba de ayer', null],
    [77, 'D', 8, 12, 101, 32, 'Fuera del periodo reciente', null],
    [78, 'D', 0, 13, 102, 33, 'Otra cartera', null],
] as [$id, $state, $days, $hour, $owner, $payment, $cause, $pre]) {
    $date = $today->modify("-{$days} days")->setTime($hour, 0)->format('Y-m-d H:i:s');
    $insertStp->execute([$id, $owner, "QA-STP-{$id}", $owner === 101 ? 20 : 10, $date, $state, $payment, $cause, $pre]);
}
$stpAsk = static function (string $prompt) use ($chat, $conversation): string {
    $thread = $chat->ask(42, $conversation, bin2hex(random_bytes(16)), $prompt);
    return end($thread['mensajes'])['contenido'];
};
$listStp = $stpAsk('Enumera las últimas 5 transacciones de STP con su estado, comprobante e incidencias');
$check(preg_match_all('/^[1-5]\. Orden STP #(\d+)/m', $listStp, $listed) === 5
    && $listed[1] === ['75','74','73','72','71'], 'Lista realmente las últimas cinco órdenes autorizadas.');
$check(substr_count($listStp, 'Comprobante:') === 5 && substr_count($listStp, 'Incidencias:') === 5
    && substr_count($listStp, 'Comprobante: enviado') === 1
    && str_contains($listStp, 'Estado previo: Revisar confirmación'), 'Cada orden muestra comprobante e incidencias y el motivo cuando existe.');

$todayStates = $stpAsk('¿Cuántas se liquidaron, se devolvieron, se cancelaron o siguen en espera hoy?');
foreach (['Liquidadas: 2', 'Devueltas: 1', 'Canceladas: 1', 'En espera: 1', $today->format('Y-m-d')] as $expected) {
    $check(str_contains($todayStates, $expected), 'Resumen de hoy respeta estados, fecha y permisos.');
}
$check(str_contains($stpAsk('¿Cuántas transacciones de STP se liquidaron hoy?'), 'Liquidadas: 2'), 'Cuenta un solo estado.');
$check(str_contains($stpAsk('¿Hubo errores recientemente?'), 'avisos de incidencias del ' . $today->modify('-6 days')->format('Y-m-d'))
    && str_contains($stpAsk('¿Hubo errores en STP recientemente?'), ': 3.'), 'Recientemente cubre siete días y usa la conversación STP.');
$problemList = $stpAsk('Muestra los errores de STP hoy');
$check(str_contains($problemList, '#72') && str_contains($problemList, '#75') && !str_contains($problemList, '#73')
    && !str_contains($problemList, '#76') && !str_contains($problemList, '#78'), 'Lista devoluciones y avisos del periodo, con alcance aplicado.');
$zero = $run('consultar_stp', ['resumir_estados' => true, 'desde' => '1999-01-01', 'hasta' => '1999-01-01'], $client);
$check($zero['totales']['registros'] === 0 && $zero['totales']['por_estado']['liquidado'] === 0, 'Todas las categorías tienen conteo cero cuando no hay órdenes.');
$check(App\ChatCollection::request('¿Hubo errores en las facturas hoy?', [], 'consultar_stp') === null,
    'El contexto STP no convierte una consulta de facturas en una consulta de STP.');

$insertStp->execute([119312, 101, '10HUM119312MQ', 20, '1999-01-01 09:00:00', 'CONFIRMADO', 32, null, null]);
$lookupChat = $chatRepository->create(42, $client->signature(), bin2hex(random_bytes(16)));
$lookupAsk = static function (string $prompt, int $user, int $thread) use ($chat): string {
    $result = $chat->ask($user, $thread, bin2hex(random_bytes(16)), $prompt);
    return end($result['mensajes'])['contenido'];
};
$prompt = $lookupAsk('Consultar una orden STP por folio', 42, $lookupChat);
$check(str_contains($prompt, 'Compárteme el folio STP'), 'La sugerencia inicia una búsqueda y conserva el contexto STP.');
foreach (['10HUM119312MQ', ' 10hum119312mq ', '119312', 'Consulta una orden STP con folio 10HUM119312MQ'] as $prompt) {
    $found = $lookupAsk($prompt, 42, $lookupChat);
    $check(str_contains($found, 'Orden STP encontrada') && str_contains($found, '#119312')
        && str_contains($found, 'Comprobante: enviado'), 'El folio y el ID interno encuentran la misma orden autorizada sin filtro de fecha.');
}

$insertStp->execute([119313, 101, '119312', 20, '1999-01-01 09:00:00', 'LQ', 32, null, null]);
$ambiguousStp = $lookupAsk('119312', 42, $lookupChat);
$check(str_contains($ambiguousStp, 'varias órdenes') && str_contains($ambiguousStp, '#119312')
    && str_contains($ambiguousStp, '#119313'), 'Un folio numérico que también sea otro ID interno se desambigua.');
$check(str_contains($lookupAsk('ID interno 119312', 42, $lookupChat), '#119312'), 'La etiqueta ID selecciona el ID interno.');
$check(str_contains($lookupAsk('folio 119312', 42, $lookupChat), '#119313'), 'La etiqueta folio selecciona el folio numérico.');

$other = ChatAccess::load($pdo, 43);
$otherChat = $chatRepository->create(43, $other->signature(), bin2hex(random_bytes(16)));
$lookupAsk('Consultar una orden STP por folio', 43, $otherChat);
foreach (['10HUM119312MQ', '119312'] as $prompt) {
    $check($lookupAsk($prompt, 43, $otherChat) === ChatQueries::NOT_FOUND, 'El folio y el ID no revelan órdenes de otra cartera.');
}
$lookupAsk('Consultar una orden STP por folio', 42, $lookupChat);
$check($lookupAsk('NOEXISTE99999', 42, $lookupChat) === ChatQueries::NOT_FOUND
    && str_contains($lookupAsk('10HUM119312MQ', 42, $lookupChat), '#119312'), 'Tras una búsqueda vacía se conserva el contexto STP.');

$pdo->exec("INSERT INTO pagos (id,nombre,monto,empresa,fecha_banco,tipo_movimiento)
    VALUES (98,'101',20,'132','2026-05-05','Retiro')");
$insertStp->execute([119314, 101, 'QA-EMPRESA132', 20, '2026-05-05 09:00:00', 'LQ', 98, null, null]);

$previousAdminScope = hash('sha256', json_encode(['administrador', 44, null, [24], false], JSON_THROW_ON_ERROR));
$previousAllCompanies = hash('sha256', json_encode(['administrador', 44, null, [], false], JSON_THROW_ON_ERROR));
$check($staff->previousSignatures() === [$previousAllCompanies, $previousAdminScope]
    && $staff->signature() !== $previousAdminScope,
    'El administrador conserva compatibilidad con el alcance anterior.');
foreach ([44, 46] as $operator) {
    $admin = ChatAccess::load($pdo, $operator);
    $check($ids($run('consultar_stp', ['id' => 119312], $admin)) === [119312]
        && $ids($run('detalle_factura', ['id' => 12], $admin)) === [12]
        && $ids($run('detalle_complemento', ['id' => 23], $admin)) === [23]
        && $ids($run('detalle_movimiento', ['id' => 33], $admin)) === [33]
        && $ids($run('consultar_banco', ['id' => 52], $admin)) === [52]
        && $admin->allows('generar_reporte'), 'Administración consulta datos de otras empresas y carteras.');
}
$limitedAdmin = ChatAccess::load($pdo, 47);
$check($limitedAdmin->previousSignatures() === [] && $run('consultar_stp', ['id' => 119312], $limitedAdmin)['filas'] === [],
    'Administración delegada conserva el alcance de su empresa.');
$companyFilters = ['empresa_id' => 132];
$check($ids($run('buscar_facturas', $companyFilters, $staff)) === [16]
    && $ids($run('consultar_stp', $companyFilters, $staff)) === [119314],
    'Un administrador fuera de la lista antigua puede consultar la empresa 132 por ID.');
$queries->assertDocument('factura', 16, $staff);
$check($ids($run('detalle_factura', ['id' => 16], ChatAccess::load($pdo, 1))) === [16],
    'Un administrador autorizado anteriormente conserva el acceso.');
$delegated132 = ChatAccess::load($pdo, 82);
$check($delegated132->previousSignatures() === []
    && $run('buscar_facturas', $companyFilters, $delegated132)['filas'] === []
    && $run('consultar_stp', $companyFilters, $delegated132)['filas'] === [],
    'La empresa 132 se restringe para otros roles incluso con asignación y excepción anterior.');
try {
    $queries->assertDocument('factura', 16, $delegated132);
    throw new RuntimeException('El documento de empresa 132 no debe abrirse con otro rol.');
} catch (ChatException $error) {
    $check($error->status === 404, 'El documento de empresa 132 respeta la restricción.');
}
$delegatedOldScope = hash('sha256', json_encode(['administrador_b', 82, null, [132], true], JSON_THROW_ON_ERROR));
$delegatedOldThread = $chatRepository->create(82, $delegatedOldScope, bin2hex(random_bytes(16)));
$check($chatRepository->conversations(82, $delegated132->signature(), 0, $delegated132->previousSignatures())['conversaciones'] === [],
    'Un alcance histórico más amplio se mantiene fuera del historial accesible del rol restringido.');

$oldThread = $chatRepository->create(44, $previousAdminScope, bin2hex(random_bytes(16)));
$oldConversation = $chatRepository->conversation($oldThread, 44, $previousAdminScope);
$oldContext = json_decode($oldConversation['contexto_json'], true, 512, JSON_THROW_ON_ERROR);
$chatRepository->startTurn($oldThread, 'Consultar una orden STP por folio', bin2hex(random_bytes(16)), $oldConversation, $oldContext);
$oldContext['herramienta_reciente'] = 'consultar_stp';
$chatRepository->finishTurn($oldThread, 'Compárteme el folio STP o el ID interno de la orden.', $oldContext);
$oldBroadThread = $chatRepository->create(44, $previousAllCompanies, bin2hex(random_bytes(16)));
$visible = $chatRepository->conversations(44, $staff->signature(), 0, $staff->previousSignatures());
$check(count(array_intersect([$oldThread, $oldBroadThread], array_column($visible['conversaciones'], 'id'))) === 2, 'La conversación anterior sigue visible.');
$answer = $lookupAsk('10HUM119312MQ', 44, $oldThread);
$check(str_contains($answer, '#119312'), 'Se retoma la conversación anterior con el alcance ampliado.');
$companyAnswer = $lookupAsk('Enumera las últimas 5 facturas de la empresa 132', 44, $oldThread);
$check(str_contains($companyAnswer, 'Factura #16') && !str_contains($companyAnswer, 'Factura #17'),
    'El chat interpreta el número como ID de empresa y conserva el filtro.');
$stpCompanyAnswer = $lookupAsk('Enumera las últimas 5 transacciones de STP de la empresa 132', 44, $oldThread);
$check(str_contains($stpCompanyAnswer, 'Orden STP #119314') && !str_contains($stpCompanyAnswer, 'Orden STP #119312'),
    'La lista STP usa el filtro de empresa con permisos de administrador.');
$migrated = $chatRepository->conversation($oldThread, 44, $staff->signature());
$check(json_decode($migrated['contexto_json'], true, 512, JSON_THROW_ON_ERROR)['alcance'] === $staff->signature(),
    'La conversación registra el alcance ampliado cuando se vuelve a usar.');
$pdo->exec("UPDATE usuarios SET tipo='administrador_b' WHERE id=44");
$lowerAccess = ChatAccess::load($pdo, 44);
try {
    $chatRepository->conversation($oldThread, 44, $lowerAccess->signature(), $lowerAccess->previousSignatures());
    throw new RuntimeException('La conversación con alcance de administrador pleno no debe quedar expuesta.');
} catch (ChatException $error) {
    $check($error->status === 404, 'Una reducción de permisos protege el historial ampliado.');
}
$pdo->exec("UPDATE usuarios SET tipo='administrador' WHERE id=44");

$totals = $run('consultar_pagos', ['monto' => 456321.34, 'totalizar' => true], $client);
$check($totals['totales']['registros'] === 2 && count($totals['totales']['por_moneda']) === 2, 'El total usa el conjunto filtrado y mantiene los tipos.');
$extra = $pdo->prepare("INSERT INTO pagos (id,nombre,monto,empresa,nombre_corto,fecha_banco,tipo_movimiento,descripcion)
    VALUES (?, '101', 10, '23', '41', '2026-05-02', 'Deposito', 'Serie de totales')");
for ($index = 0; $index < 12; $index++) {
    $extra->execute([1000 + $index]);
}
$sample = $run('consultar_pagos', ['texto' => 'Serie de totales'], $client);
$aggregate = $run('consultar_pagos', ['texto' => 'Serie de totales', 'totalizar' => true], $client);
$check(count($sample['filas']) === 10 && $sample['hay_mas'] && $aggregate['totales']['registros'] === 12
    && (float) $aggregate['totales']['por_moneda'][0]['monto'] === 120.0, 'Los totales no se calculan sobre la muestra.');

$tax = new App\PaymentComplementRepository($pdo);
$xml = static fn (string $taxes): array => ['uuid' => 'QA', 'xml_firmado' => "<Comprobante>{$taxes}</Comprobante>"];
$check($tax->taxTreatmentLabel($xml('<Traslado Impuesto="002" TasaOCuota="0.000000"/>')) === 'Tasa 0%', 'Tasa cero en XML.');
$check($tax->taxTreatmentLabel($xml('<Traslado Impuesto="002" TipoFactor="Exento"/>')) === 'Sin IVA', 'Exento en XML.');
$check($tax->taxTreatmentLabel($xml('<Retencion Impuesto="002" Base="100" Importe="4" TasaOCuota="0.040000"/>')) === 'Retención 4%',
    'Retención del 4% en XML.');
$check($tax->taxTreatmentLabel(['id' => 2]) === null, 'Datos fiscales insuficientes no se convierten en Sin IVA.');

$pdo->exec("ALTER TABLE pagos ADD COLUMN banco VARCHAR(15) NOT NULL DEFAULT 'Banco QA'");
$pdo->exec("INSERT INTO pagos
    (id,nombre,monto,saldo_acreditable,empresa,status,id_factura,id_factura_res,tipo_movimiento,fecha_banco,fecha_asignacion)
    VALUES (990,'101',88,0,'23','asignado',2,NULL,'Deposito','2026-01-02','2026-01-03'),
    (991,'0',90,0,'23',NULL,NULL,NULL,'Deposito','2026-01-02',NULL),
    (992,'101',91,0,'23','asignado',NULL,NULL,'Deposito','2026-01-02','2026-01-03'),
    (993,'101',92,70,'23','asignado',NULL,NULL,'Deposito','2026-01-02','2026-01-04'),
    (994,'101',93,0,'23','asignado',NULL,'000000022','Deposito','2026-01-02','2026-01-03'),
    (995,'101',94,0,'23','asignado',NULL,NULL,'Retiro','2026-01-02','2026-01-03')");
$pdo->exec('INSERT INTO relacion_factura_pago VALUES (3,992,10,NULL)');
$pendingArgs = ['pendientes_documento'=>true,'desde'=>'2026-01-02','hasta'=>'2026-01-02'];
$check($ids($run('consultar_pagos', $pendingArgs, $staff)) === [993,991],
    'Conciliación lista depósitos sin documento y excluye la bandera 2, los retiros y las relaciones existentes.');
$check($ids($run('consultar_pagos', $pendingArgs + ['vista'=>'sin-asignar'], $staff)) === [991], 'Vista Sin asignar.');
$check($ids($run('consultar_pagos', $pendingArgs + ['vista'=>'asignados'], $staff)) === [993], 'Vista Asignados.');
$check(str_contains($lookupAsk('¿Qué depósitos siguen pendientes de conciliar?', 42, $lookupChat), 'Depósitos pendientes de documentar en Movimientos:'),
    'La pregunta dirige al módulo de Movimientos.');
$wallet = ['vista'=>'cartera','cliente_id'=>101,'desde'=>'2026-01-04','hasta'=>'2026-01-04'];
$check($ids($run('consultar_pagos', $wallet, $staff)) === [993]
    && (float) $run('consultar_pagos', $wallet + ['totalizar'=>true], $staff)['totales']['por_moneda'][0]['saldo_acreditable'] === 70.0,
    'Cartera usa la fecha de asignación y totaliza el importe acreditado.');

$pdo->exec("CREATE TEMPORARY TABLE facturas_correo (id INT, id_factura INT, empresa_origen INT, empresa_destino INT,
    estado INT, fecha_envia DATETIME, fecha_recibe DATETIME, fecha_inserta DATETIME)");
$pdo->exec("INSERT INTO facturas_correo VALUES
    (801,10,23,101,1,'2026-01-04 10:00:00',NULL,'2026-01-04 09:58:00'),
    (802,10,23,101,2,'2026-01-04 11:00:00',NULL,'2026-01-04 10:58:00'),
    (803,-10,23,101,1,'2026-01-04 12:00:00',NULL,'2026-01-04 11:58:00')");
$pdo->exec("CREATE TEMPORARY TABLE contexto_correo (id INT,id_factura INT,id_proveedor INT,id_empresa INT,
    estado INT,fecha_entrega DATETIME,fecha_respuesta DATETIME)");
$pdo->exec("INSERT INTO contexto_correo VALUES (901,10,23,1,0,'2026-01-04 10:00:00','2026-01-05 10:00:00')");
$log = $run('consultar_correos', ['tipo_correo'=>'factura','factura_id'=>10], $staff);
$check($ids($log) === [802,801] && $log['filas'][0]['estado'] === 'Aceptado o en cola'
    && $log['filas'][1]['estado'] === 'Envío registrado', 'Un complemento no se confunde con la factura y se distingue cola de envío.');
$contextMail = $run('consultar_correos', ['tipo_correo'=>'contextual','factura_id'=>10], $staff);
$check($ids($contextMail) === [901] && $contextMail['filas'][0]['estado'] === 'Pendiente de envío'
    && isset($contextMail['filas'][0]['fecha_programada']), 'Una fecha programada del correo contextual no prueba un envío.');
$check($run('consultar_correos', ['tipo_correo'=>'factura','factura_id'=>10], $limitedAdmin)['filas'] === [],
    'El log de correo respeta el alcance de empresa.');
$check(str_contains($lookupAsk('¿Se envió el correo de la factura #10?', 44, $oldThread), 'Aceptado o en cola'),
    'La pregunta por correo usa el registro más reciente de Dev.');

$pdo->exec("ALTER TABLE empresas ADD COLUMN rfc VARCHAR(30), ADD COLUMN comodin INT DEFAULT 0");
$pdo->exec("UPDATE empresas SET rfc='BANKING-QA' WHERE id=24");
$pdo->exec("INSERT INTO empresas (id,razon,rfc) VALUES (26,'Proveedor QA','PROV-QA')");
$pdo->exec("INSERT INTO claves_cortas VALUES (20,101,'BANK','Receptor Banking','BANKING-QA')");
$pdo->exec("CREATE TEMPORARY TABLE proveedores_14 (id_empresa INT,id_proveedor INT,porcentaje_sugerido DECIMAL(8,2))");
$pdo->exec("INSERT INTO proveedores_14 VALUES (20,23,50),(20,26,50)");
$pdo->exec("INSERT INTO facturas
    (id,razon,nombre,id_clave_corta,serie,folio,fecha,total_iva,moneda,metodo_pago,status)
    VALUES (90,23,101,'20','B',1,'2026-01-04',75,1,1,'Vigente'),
           (91,26,101,'20','B',2,'2026-01-04',25,1,1,'Vigente')");
$bankingArgs = ['reporte'=>'banking','empresa_id'=>24,'desde'=>'2026-01-04','hasta'=>'2026-01-04'];
$banking = $run('generar_reporte', $bankingArgs, $staff);
$check(array_map('floatval', array_column($banking['filas'], 'porcentaje_real')) === [75.0,25.0],
    'Banking devuelve las desviaciones calculadas por el reporte original.');
$check($run('generar_reporte', $bankingArgs, $limitedAdmin)['filas'] === [],
    'Una empresa de origen autorizada no revela cifras de proveedores fuera del alcance.');

$pdo->exec("UPDATE facturas SET uuid='a1234567-abcd-1234-ab12-123456789abc' WHERE id=10");
$check($ids($run('buscar_facturas', ['uuid'=>'A1234567-ABCD-1234-AB12-123456789ABC'], $staff)) === [10]
    && $ids($run('buscar_facturas', ['folio'=>'2'], $staff)) === [2,91]
    && $ids($run('buscar_facturas', ['id'=>2], $staff)) === [2],
    'UUID, folio e ID interno tienen filtros distintos.');

$pdo->exec('ALTER TABLE pagos ADD COLUMN origen_traspaso INT, ADD COLUMN destino_traspaso INT');
$pdo->exec('CREATE TEMPORARY TABLE s14_traspaso_links (pago_entrada_id INT PRIMARY KEY, pago_salida_id INT NOT NULL UNIQUE)');
$pdo->exec("INSERT INTO pagos (id,nombre,monto,saldo_acreditable,empresa,banco,fecha_asignacion,origen_traspaso,destino_traspaso,detalle)
    VALUES (2100,'102',10,10,'99','99','2026-05-05',101,102,'Traspaso adyacente'),
           (2101,'101',-10,-10,'99','99','2026-05-05',101,102,'Traspaso adyacente'),
           (2110,'101',-30,-30,'23','99','2026-05-05',101,102,'Traspaso intercalado'),
           (2111,'102',30,30,'23','23','2026-05-05',101,102,'Traspaso intercalado'),
           (2112,'102',30,30,'23','99','2026-05-05',101,102,'Traspaso intercalado'),
           (2120,'102',50,50,'99','99','2026-05-06',101,102,'Traspaso vinculado'),
           (2180,'101',-50,-50,'99','99','2026-05-06',101,102,'Traspaso vinculado'),
           (2130,'101',-60,-60,'99','99','2026-05-04',101,102,'Coincidencia ambigua'),
           (2131,'102',60,60,'99','99','2026-05-04',101,102,'Coincidencia ambigua'),
           (2132,'101',-60,-60,'99','99','2026-05-04',101,102,'Coincidencia ambigua')");
$pdo->exec('INSERT INTO s14_traspaso_links VALUES (2120,2180)');
foreach ([[2101, 2100, 10.0], [2110, 2112, 30.0], [2180, 2120, 50.0]] as [$origin, $destination, $amount]) {
    foreach ([$origin, $destination] as $movementId) {
        $transfer = $run('consultar_traspasos', ['id' => $movementId], $staff)['filas'][0];
        $check((int) $transfer['origen_id'] === $origin && (int) $transfer['destino_id'] === $destination
            && (float) $transfer['saldo_origen'] === -$amount && (float) $transfer['saldo_destino'] === $amount,
            'Ambas referencias resuelven a la misma pareja, por proximidad o vínculo explícito.');
    }
}
$totals = $run('consultar_traspasos', ['contar' => true], $staff)['totales'];
$check($totals['registros'] === 3 && $totals['sin_contrapartida'] === 3 && $totals['monto'] === 90.0,
    'Cada par cuenta una vez y los movimientos ambiguos quedan sin pareja.');
$scopedTransfer = $run('consultar_traspasos', ['id' => 2110], $client)['filas'][0];
$check($scopedTransfer['destino_id'] === null && $scopedTransfer['destino'] === null
    && $run('consultar_traspasos', ['id' => 2112], $client)['filas'] === [],
    'El chat protege la contrapartida fuera de la cartera autorizada.');
$transferAnswer = $lookupAsk('¿De dónde a dónde fue el último traspaso?', 44, $oldThread);
$check(str_contains($transferAnswer, 'Origen: Cartera A (cartera #101) · movimiento #2180 · saldo acreditable -50.00 MXN')
    && str_contains($transferAnswer, 'Destino: Cartera B (cartera #102) · movimiento #2120 · saldo acreditable +50.00 MXN'),
    'El chat muestra las dos carteras, los IDs y los signos.');
$scopedAnswer = $lookupAsk('Consulta el traspaso #2110', 42, $lookupChat);
$check(str_contains($scopedAnswer, 'Destino: sin contrapartida verificable en tu alcance.') && !str_contains($scopedAnswer, '2112'),
    'La respuesta por ID omite los datos de una cartera fuera del alcance.');

$pdo->exec('ALTER TABLE clientes ADD COLUMN activo INT NOT NULL DEFAULT 1, ADD COLUMN grupo INT, ADD COLUMN empresa INT');
$pdo->exec('CREATE TEMPORARY TABLE grupo (id INT PRIMARY KEY, nombre VARCHAR(100))');
$pdo->exec("INSERT INTO grupo VALUES (2,'Calle Ley'),(3,'Calle Norte'),(4,'Calle Ley 2024')");
$pdo->exec("UPDATE clientes SET grupo=2, nombre=CONCAT('JiMMy ',nombre) WHERE id IN (101,102)");
$pdo->exec("INSERT INTO clientes (id,nombre,activo,grupo,empresa)
    VALUES (146,'Jimmy restringido',1,2,23),(222,'Jimmy inactivo',0,2,23),
           (223,'Jimmy saldo cero',1,2,23),(224,'Cartera Norte',1,3,23),(225,'Calle Ley futura',1,4,23),
           (226,'Jimmy empresa reservada',1,2,132)");
$pdo->exec("INSERT INTO pagos (id,nombre,saldo_acreditable,empresa)
    VALUES (2200,'146',800000,'23'), (2201,'222',700000,'23'), (2202,'224',500000,'23'),
           (2203,'101',91000,'132'), (2204,'226',92000,'132')");
$walletTool = 'consultar_saldos_carteras';
$walletGroup = $run($walletTool, ['grupo_id'=>2,'grupo_nombre'=>'calle ley'], $staff)['totales'];
$walletName = $run($walletTool, ['cartera_nombre'=>'JIMMY'], $staff)['totales'];
$check($walletGroup === $walletName && $walletGroup['registros'] === 4,
    'Incluye las mismas carteras activas que coinciden por grupo y nombre, incluso si no tienen movimientos.');
$groupCandidates = $run($walletTool, ['grupo_nombre'=>'Calle'], $staff);
$check(count($groupCandidates['grupos']) === 3 && !isset($groupCandidates['totales'])
    && $run($walletTool, ['grupo_nombre'=>'Calle Ley'], $staff)['grupo']['id'] === 2,
    'Una coincidencia exacta prevalece y un nombre parcial ambiguo solo devuelve candidatos.');
$check($run($walletTool, ['cartera_nombre'=>'JYMMY'], $staff)['filas'] === []
    && !isset($run($walletTool, ['cartera_nombre'=>'%'], $staff)['totales'])
    && !isset($run($walletTool, ['grupo_id'=>2,'grupo_nombre'=>'Calle Norte'], $staff)['totales']),
    'Busca texto literal y valida que el nombre corresponda al ID del grupo.');
$walletCompany = $run($walletTool, ['grupo_id'=>2], $client)['totales'];
$walletUser = $run($walletTool, ['cartera_nombre'=>'JIMMY'], $client)['totales'];
$check($walletCompany === $walletUser && $walletCompany['registros'] === 1
    && $walletCompany['saldo_acreditable'] === (float) $pdo->query(
        "SELECT SUM(saldo_acreditable) FROM pagos WHERE nombre='101' AND empresa='23'"
    )->fetchColumn(), 'La suma de un cliente excluye otras carteras y otras empresas.');
$check(str_contains($lookupAsk('cuando saldo tiene el grupo de calle ley (2)', 44, $oldThread), 'Grupo Calle Ley (#2)')
    && str_contains($lookupAsk('Saldo del grupo Calle Ley 2024', 44, $oldThread), 'Grupo Calle Ley 2024 (#4)')
    && str_contains($lookupAsk('suma todas las carteas que tengan JYMMY en su nombre', 44, $oldThread), '«JYMMY»')
    && str_contains($lookupAsk('Suma todas las carteras que tengan «JIMMY» en su nombre', 44, $oldThread), '4 carteras activas'),
    'El chat resuelve las frases del usuario, conserva el texto literal y reconoce nombres con números.');

$pdo->exec('ALTER TABLE facturas ADD COLUMN sello_cfd TEXT');
$pdo->exec("UPDATE empresas SET rfc='DUBE800923E41' WHERE id=23");
$pdo->exec("UPDATE claves_cortas SET rfc='MOAF580320PJ1' WHERE id=3");
$pdo->exec("UPDATE facturas SET uuid='856CAF56-E0B9-4A96-B0E8-7C7C2EC5C534', sello_cfd='rH8/bw==',
    xml_total=100.25, xml_firmado=NULL WHERE id IN (11,16)");
$stamp = '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Total="999.250000" Sello="aBcD+/==">'
    . '<cfdi:Emisor Rfc="DUBE800923E41"/><cfdi:Receptor Rfc="MOAF580320PJ1"/>'
    . '<cfdi:Complemento><tfd:TimbreFiscalDigital UUID="a1234567-abcd-1234-ab12-123456789abc"/></cfdi:Complemento></cfdi:Comprobante>';
$pdo->prepare('UPDATE facturas SET xml_firmado=? WHERE id=10')->execute([$stamp]);
$soap = static fn (string $code, string $state): string => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
    . '<ConsultaResponse xmlns="http://tempuri.org/"><ConsultaResult xmlns:a="http://schemas.datacontract.org/2004/07/Sat.Cfdi.Negocio.ConsultaCfdi.Servicio">'
    . '<a:CodigoEstatus>' . $code . '</a:CodigoEstatus><a:Estado>' . $state . '</a:Estado></ConsultaResult></ConsultaResponse></s:Body></s:Envelope>';
$sent = [];
$statusReplies = [
    10 => $soap('S - Comprobante obtenido satisfactoriamente.', 'Vigente'),
    11 => $soap('S - Comprobante obtenido satisfactoriamente.', 'Cancelado'),
];
$satQueries = new ChatQueries($pdo, new App\SatCfdiStatus(static function (array $requests) use (&$sent, &$statusReplies): array {
    $sent = $requests;
    return array_intersect_key($statusReplies, $requests);
}));
$satChat = new App\ChatService($pdo, $chatRepository, $satQueries, static function (): never {
    throw new RuntimeException('La pregunta de estatus debe resolverse con el SAT.');
});
$satAsk = static function (string $text) use ($satChat, $lookupChat): string {
    $turn = $satChat->ask(42, $lookupChat, bin2hex(random_bytes(16)), $text);
    return end($turn['mensajes'])['contenido'];
};
$result = $satAsk('¿Cuál es el estatus de las facturas #10, #11 y #16?');
$check(array_keys($sent) === [10,11] && str_contains($sent[10], '&amp;tt=999.250000&amp;fe=aBcD%2B%2F%3D%3D')
    && str_contains($sent[11], '&amp;tt=100.25&amp;fe=rH8%2Fbw%3D%3D'),
    'SOAP usa el XML timbrado o los datos fiscales guardados y solo envía facturas autorizadas.');
$check(str_contains($result, 'SAT: Vigente') && str_contains($result, 'SAT: Cancelado') && str_contains($result, 'ID #16: no encontré'),
    'El chat muestra un estado por CFDI y limita los datos fuera de alcance.');
$sent = [];
$check(str_contains($satAsk('¿Factura folio A105 está activa?'), 'varias coincidencias') && $sent === [],
    'Un folio ambiguo se resuelve antes de consultar al SAT.');
$statusReplies[11] = $soap('N - 602: Comprobante no encontrado.', 'No Encontrado');
$check(str_contains($satAsk('¿Está activa la factura UUID 856CAF56-E0B9-4A96-B0E8-7C7C2EC5C534?'), 'SAT: No encontrado'),
    'No encontrado se distingue de Cancelado.');
$statusReplies[11] = '<html>Servicio no disponible</html>';
$check(str_contains($satAsk('Estado fiscal de la factura #11'), 'SAT: Sin verificar'),
    'La falla del servicio conserva el estado como sin verificar.');
$sent = [];
$check(str_contains($satAsk('¿Está vigente la factura #13?'), 'Faltan datos fiscales') && $sent === [],
    'Un CFDI con datos incompletos queda sin verificar sin enviar una petición.');

echo "chat_details: permisos, cruces fiscales y bancarios, totales y descargas verificados\n";

