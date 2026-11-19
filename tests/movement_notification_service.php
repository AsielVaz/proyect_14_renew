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
    $pdo->exec("INSERT INTO clientes (id, nombre, correo, correo_2_s, info_3, activo)
        VALUES (900001, 'Cartera de prueba', 'uno@example.com', 'dos@example.com', 'movimiento', 1)");
    $pdo->exec("INSERT INTO pagos (id, nombre, monto, saldo_acreditable, factura, empresa, status, tipo_movimiento, notificado)
        VALUES (900001, '900001', 1250.50, 1250.50, '', '', 'asignado', 'Deposito', 'no')");

    $messages = [];
    $service = new MovementNotificationService($pdo, static function (array $message) use (&$messages): array {
        $messages[] = $message;
        return ['accepted' => true, 'message' => 'Aceptado en la prueba.'];
    });
    $result = $service->run($at1759);

    $expect($result['clients'] === 1 && $result['movements'] === 1 && $result['marked'] === 1,
        'El cron debe enviar y marcar el movimiento pendiente.');
    $expect(($result['notifications'][0]['status'] ?? '') === 'sent'
        && ($result['notifications'][0]['recipients'] ?? []) === ['uno@example.com', 'dos@example.com']
        && ($result['notifications'][0]['movements'][0]['id'] ?? 0) === 900001
        && ($result['notifications'][0]['movements'][0]['amount'] ?? 0) === 1250.50
        && ($result['notifications'][0]['balance'] ?? 0) === 1250.50,
        'El JSON debe detallar la cartera, saldo, destinatarios y movimientos notificados.');
    $expect(count($messages) === 1 && $messages[0]['recipients'] === ['uno@example.com', 'dos@example.com'],
        'El cron debe usar los correos de saldos de la cartera.');
    $expect(str_contains($messages[0]['html'], 'Saldo actual de la cartera')
        && str_contains($messages[0]['html'], '$1,250.50')
        && str_contains($messages[0]['text'], 'Saldo actual de la cartera: $1,250.50'),
        'El correo debe mostrar el saldo de la cartera calculado al momento del envío.');
    $expect($pdo->query('SELECT notificado FROM pagos WHERE id = 900001')->fetchColumn() === 'Si',
        'El pago sólo debe quedar marcado después del envío aceptado.');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS pagos');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS clientes');
}

echo "movement_notification_service: ok\n";
