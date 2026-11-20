<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\MovementNotificationService;

$at1759 = new DateTimeImmutable('2026-11-18 17:59:00');
$at1800 = new DateTimeImmutable('2026-11-18 18:00:00');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$expect(MovementNotificationService::modeIsDue('movimiento', $at1759), 'El modo por movimiento debe ejecutarse de inmediato.');
$expect(!MovementNotificationService::modeIsDue('fin_dia', $at1759), 'El resumen diario no debe ejecutarse antes de las 18:00.');
$expect(MovementNotificationService::modeIsDue('fin_dia', $at1800), 'El resumen diario debe ejecutarse desde las 18:00.');
$expect(!MovementNotificationService::modeIsDue('no', $at1800), 'El modo desactivado no debe ejecutarse.');
$expect(MovementNotificationService::recipientAddresses([
    ' CLIENTE@EXAMPLE.COM ', 'cliente@example.com', 'invalido', '', null,
]) === ['cliente@example.com'], 'Los destinatarios deben validarse y eliminar duplicados.');

$pdo = db();
try {
    $pdo->exec('CREATE TEMPORARY TABLE clientes (
        id INT PRIMARY KEY, nombre VARCHAR(150), correo VARCHAR(100), correo_2_s VARCHAR(100),
        correo_3_s VARCHAR(100), info_3 VARCHAR(50), activo INT
    )');
    $pdo->exec('CREATE TEMPORARY TABLE pagos (
        id INT PRIMARY KEY, nombre VARCHAR(100), monto DOUBLE, saldo_acreditable DOUBLE,
        tipo_movimiento VARCHAR(100), fecha_banco VARCHAR(30), fecha_asignacion DATE,
        fecha_operacion DATE, detalle LONGTEXT, descripcion LONGTEXT, descripcion_detallada LONGTEXT,
        empresa VARCHAR(100), banco VARCHAR(50), nombre_corto VARCHAR(100), factura VARCHAR(100),
        status VARCHAR(50), notificado VARCHAR(2), INDEX pagosIndex (nombre)
    )');
    $pdo->exec('CREATE TEMPORARY TABLE s14_cartera_notificacion_diaria (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL, fecha_resumen DATE NOT NULL, estado VARCHAR(20) NOT NULL,
        movimientos INT NOT NULL DEFAULT 0, saldo DECIMAL(18,2) NULL,
        destinatarios LONGTEXT NULL, provider_ids LONGTEXT NULL,
        creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, enviado_at DATETIME NULL,
        UNIQUE KEY uq_cliente_fecha (cliente_id, fecha_resumen)
    )');
    $pdo->exec("INSERT INTO clientes (id, nombre, correo, correo_2_s, info_3, activo)
        VALUES (900001, 'Cartera de prueba', 'uno@example.com', 'dos@example.com', 'movimiento', 1)");
    $pdo->exec("INSERT INTO clientes (id, nombre, correo, info_3, activo)
        VALUES (900002, 'Cartera fin de día', 'tres@example.com', 'fin_dia', 1)");
    $pdo->exec("INSERT INTO pagos (id, nombre, monto, saldo_acreditable, fecha_asignacion, factura, empresa, status, tipo_movimiento, notificado)
        VALUES (900001, '900001', 1250.50, 1250.50, '2026-11-18', '', '', 'asignado', 'Deposito', 'no')");
    $pdo->exec("INSERT INTO pagos (id, nombre, monto, saldo_acreditable, fecha_asignacion, factura, empresa, status, tipo_movimiento, notificado)
        VALUES (900002, '900002', 500.00, 500.00, '2026-11-18', '', '', 'asignado', 'Retiro', 'no')");
    $pdo->exec("INSERT INTO pagos (id, nombre, monto, saldo_acreditable, fecha_asignacion, factura, empresa, status, tipo_movimiento, notificado)
        VALUES (900003, '900001', 300.00, 300.00, '2026-11-17', '', '', 'asignado', 'Deposito', 'Si')");

    $messages = [];
    $service = new MovementNotificationService($pdo, static function (array $message) use (&$messages): array {
        $messages[] = $message;
        return ['accepted' => true, 'message' => 'Aceptado en la prueba.'];
    });
    $result = $service->run($at1759);

    $expect($result['clients'] === 1 && $result['movements'] === 1 && $result['marked'] === 1,
        'El cron debe enviar y marcar el movimiento pendiente.');
    $expect(($result['notifications'][0]['status'] ?? '') === 'sent'
        && ($result['notifications'][0]['notification_type'] ?? '') === 'immediate'
        && ($result['notifications'][0]['recipients'] ?? []) === ['uno@example.com', 'dos@example.com']
        && ($result['notifications'][0]['movements'][0]['id'] ?? 0) === 900001
        && ($result['notifications'][0]['movements'][0]['amount'] ?? 0) === 1250.50
        && ($result['notifications'][0]['balance'] ?? 0) === 1550.50,
        'El JSON debe detallar la cartera, saldo, destinatarios y movimientos notificados.');
    $expect(count($messages) === 1 && $messages[0]['recipients'] === ['uno@example.com', 'dos@example.com'],
        'El cron debe usar los correos de saldos de la cartera.');
    $expect(str_contains($messages[0]['html'], 'Saldo actual de la cartera')
        && str_contains($messages[0]['html'], '$1,550.50')
        && str_contains($messages[0]['text'], 'Saldo actual de la cartera: $1,550.50'),
        'El correo debe mostrar el saldo de la cartera calculado al momento del envío.');
    $expect(str_contains($messages[0]['html'], 'name="viewport"')
        && str_contains($messages[0]['html'], '@media only screen and (max-width:620px)')
        && str_contains($messages[0]['html'], 'class="movement-row"')
        && str_contains($messages[0]['html'], '<span class="mobile-label">Monto</span>'),
        'El correo debe convertir los movimientos en tarjetas legibles en pantallas móviles.');
    $expect($pdo->query('SELECT notificado FROM pagos WHERE id = 900001')->fetchColumn() === 'Si',
        'El pago sólo debe quedar marcado después del envío aceptado.');

    $dailyResult = $service->run($at1800);
    $expect($dailyResult['clients'] === 2 && $dailyResult['movements'] === 2 && $dailyResult['marked'] === 1,
        'A las 18:00 debe resumir todas las carteras activadas y marcar la pendiente de fin de día.');
    $expect(count($dailyResult['notifications']) === 2
        && ($dailyResult['notifications'][0]['notification_type'] ?? '') === 'daily_summary'
        && ($dailyResult['notifications'][0]['movements'][0]['id'] ?? 0) === 900001
        && ($dailyResult['notifications'][0]['balance'] ?? null) === 300.0
        && ($dailyResult['notifications'][1]['notification_type'] ?? '') === 'daily_summary'
        && ($dailyResult['notifications'][1]['movements'][0]['id'] ?? 0) === 900002,
        'El resumen debe incluir movimientos ya notificados por movimiento y los configurados sólo al final del día.');
    $expect(count($messages) === 3
        && str_contains($messages[1]['html'], 'Resumen diario de')
        && str_contains($messages[1]['html'], 'Saldo del día anterior')
        && str_contains($messages[1]['html'], '$300.00')
        && str_contains($messages[1]['text'], 'Saldo del día anterior: $300.00')
        && str_contains($messages[1]['text'], 'Movimientos asignados durante el día: 1'),
        'El correo diario debe identificarse como resumen de todos los movimientos asignados.');
    $expect($pdo->query("SELECT COUNT(*) FROM s14_cartera_notificacion_diaria WHERE estado = 'enviado'")->fetchColumn() === 2,
        'Debe quedar un control diario enviado por cada cartera.');
    $expect($pdo->query('SELECT notificado FROM pagos WHERE id = 900002')->fetchColumn() === 'Si',
        'El movimiento configurado sólo al final del día debe marcarse después del resumen.');

    $repeatResult = $service->run(new DateTimeImmutable('2026-11-18 18:01:00'));
    $expect($repeatResult['notifications'] === [] && count($messages) === 3,
        'El cron no debe repetir el resumen diario en ejecuciones posteriores del mismo día.');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS s14_cartera_notificacion_diaria');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS pagos');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS clientes');
}

echo "movement_notification_service: ok\n";
