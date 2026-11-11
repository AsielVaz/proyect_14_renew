<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/auth.php';
require dirname(__DIR__) . '/src/webauthn.php';

use App\ReportRepository;
use App\Reports;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $passed++;
    echo "OK: {$message}\n";
};

$pdo = db();
$oldServer = $_SERVER;
$oldPost = $_POST;
$oldSession = $_SESSION;
$identifier = 'audit-test-' . bin2hex(random_bytes(6)) . '@example.test';

$pdo->beginTransaction();
try {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/?webauthn=login-verify';
    $_SERVER['HTTP_HOST'] = 'dev1.sistema14.com';
    $_SERVER['HTTP_ORIGIN'] = 'https://dev1.sistema14.com';
    $_SERVER['HTTP_USER_AGENT'] = 'Sistema14 audit test';

    auth_log_event('test_failure', 'Error', 'Detalle de auditoría de prueba.', [
        'expected_origin' => 'https://dev1.sistema14.com',
        'password' => 'NO-DEBE-GUARDARSE',
    ], null, $identifier, 'passkey');

    $query = $pdo->prepare('SELECT * FROM logsesiones WHERE usuario = :user ORDER BY id DESC LIMIT 1');
    $query->execute(['user' => $identifier]);
    $row = $query->fetch();
    $assert(is_array($row), 'el error de autenticación se guarda en logsesiones');
    $payload = json_decode((string) $row['agent'], true);
    $assert(is_array($payload) && ($payload['event'] ?? '') === 'test_failure', 'el evento queda identificado');
    $assert(($payload['message'] ?? '') === 'Detalle de auditoría de prueba.', 'la explicación queda disponible');
    $assert(($payload['context']['expected_origin'] ?? '') === 'https://dev1.sistema14.com', 'se conserva el contexto diagnóstico');
    $assert(($payload['context']['password'] ?? '') === '[redactado]', 'las contraseñas se redactan');
    $assert(!str_contains((string) $row['agent'], 'NO-DEBE-GUARDARSE'), 'el secreto no aparece en el registro');

    $_SESSION['csrf_token'] = 'token-esperado-no-persistir';
    $_POST = ['csrf_token' => 'token-enviado-no-persistir', 'email' => $identifier];
    try {
        verify_csrf();
        $assert(false, 'un CSRF inválido debe rechazarse');
    } catch (RuntimeException) {
        $assert(true, 'un CSRF inválido se rechaza');
    }
    $query->execute(['user' => $identifier]);
    $csrfRow = $query->fetch();
    $csrfPayload = json_decode((string) $csrfRow['agent'], true);
    $assert(($csrfPayload['event'] ?? '') === 'csrf_invalid', 'el formulario vencido se registra');
    $assert(!str_contains((string) $csrfRow['agent'], 'token-esperado-no-persistir'), 'el token esperado no se guarda');
    $assert(!str_contains((string) $csrfRow['agent'], 'token-enviado-no-persistir'), 'el token enviado no se guarda');

    $clientData = rtrim(strtr(base64_encode(json_encode([
        'type' => 'webauthn.get',
        'challenge' => 'NO-DEBE-GUARDARSE',
        'origin' => 'https://sistema14.com',
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $context = webauthn_client_data_context(['response' => ['clientDataJSON' => $clientData]]);
    $assert(($context['received_origin'] ?? '') === 'https://sistema14.com', 'el origen real de WebAuthn se extrae para diagnóstico');
    $assert(!array_key_exists('challenge', $context), 'el desafío WebAuthn no se incorpora al contexto');

    $definition = Reports::all()['accesos'];
    $report = (new ReportRepository($pdo))->run('accesos', $definition, ['q' => $identifier], 1, 25);
    $assert(($report['rows'][0]['evento'] ?? '') === 'csrf_invalid', 'el reporte de accesos interpreta el evento estructurado');
    $assert(str_contains((string) ($report['rows'][0]['detalle'] ?? ''), 'token de seguridad'), 'el reporte muestra la explicación detallada');

    echo "Pruebas completadas: {$passed}\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SERVER = $oldServer;
    $_POST = $oldPost;
    $_SESSION = $oldSession;
}
