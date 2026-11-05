<?php

declare(strict_types=1);

use lbuchs\WebAuthn\WebAuthnException;

function webauthn_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function webauthn_payload(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $payload = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($payload) ? $payload : [];
    }
    return $_POST;
}

function webauthn_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        webauthn_json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
    }
}

function webauthn_verify_csrf_payload(array $payload): void
{
    $sent = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo nuevamente.');
    }
}

function handle_webauthn_endpoint(string $action): never
{
    webauthn_require_post();
    $payload = webauthn_payload();
    $ip = auth_client_ip();
    $loginLimiterIdentity = 'webauthn-login';

    try {
        if (!webauthn_service()->isEnabled()) {
            throw new RuntimeException('El acceso con Passkey no está habilitado.');
        }

        switch ($action) {
            case 'login-options':
                if (webauthn_options_rate_limiter()->blockedFor('webauthn-options', $ip) > 0) {
                    throw new RuntimeException('Se solicitaron demasiadas comprobaciones. Espera un minuto.');
                }
                if (auth_rate_limiter()->blockedFor($loginLimiterIdentity, $ip) > 0) {
                    throw new RuntimeException('Demasiados intentos. Espera unos minutos antes de intentar nuevamente.');
                }
                webauthn_options_rate_limiter()->recordFailure('webauthn-options', $ip);
                $identity = mb_substr(trim((string) ($payload['identity'] ?? '')), 0, 254);
                $user = $identity !== '' ? auth_service()->findByIdentifier($identity) : null;
                if ($identity !== '' && $user === null) {
                    auth_failure_delay();
                    throw new RuntimeException('No hay una llave de acceso disponible para esa cuenta.');
                }
                webauthn_json_response(['ok' => true, 'options' => webauthn_service()->loginOptions($user)]);

            case 'login-verify':
                if (auth_rate_limiter()->blockedFor($loginLimiterIdentity, $ip) > 0) {
                    throw new RuntimeException('Demasiados intentos. Espera unos minutos antes de intentar nuevamente.');
                }
                try {
                    $result = webauthn_service()->verifyLogin($payload);
                } catch (Throwable $exception) {
                    auth_rate_limiter()->recordFailure($loginLimiterIdentity, $ip);
                    throw $exception;
                }
                $user = auth_service()->findById((int) $result['user_id']);
                if (!$user || trim((string) ($user['email'] ?? '')) === '') {
                    throw new RuntimeException('La cuenta asociada ya no puede iniciar sesión.');
                }
                auth_rate_limiter()->clear($loginLimiterIdentity, $ip);
                $redirect = auth_establish_session($user, 'passkey');
                webauthn_json_response(['ok' => true, 'redirect' => $redirect]);

            case 'register-options':
                webauthn_verify_csrf_payload($payload);
                $user = auth_service()->findById(current_operator_id());
                if (!$user) {
                    throw new RuntimeException('La cuenta autenticada ya no está disponible.');
                }
                webauthn_json_response(['ok' => true, 'options' => webauthn_service()->registrationOptions($user)]);

            case 'register-verify':
                webauthn_verify_csrf_payload($payload);
                $user = auth_service()->findById(current_operator_id());
                if (!$user) {
                    throw new RuntimeException('La cuenta autenticada ya no está disponible.');
                }
                $result = webauthn_service()->verifyRegistration($payload, $user);
                webauthn_json_response(['ok' => true, 'data' => $result]);

            case 'rename':
                webauthn_verify_csrf_payload($payload);
                $renamed = passkey_repository()->rename(
                    (int) ($payload['credential_id'] ?? 0),
                    current_operator_id(),
                    (string) ($payload['name'] ?? '')
                );
                if (!$renamed) {
                    throw new RuntimeException('La llave de acceso no existe o no pertenece a tu cuenta.');
                }
                webauthn_json_response(['ok' => true, 'message' => 'Nombre actualizado.']);

            case 'delete':
                webauthn_verify_csrf_payload($payload);
                $userId = current_operator_id();
                $authTime = (int) ($_SESSION['auth_user']['authenticated_at'] ?? 0);
                if (time() - $authTime > (int) webauthn_config()['recent_auth_seconds']) {
                    throw new RuntimeException('Por seguridad, vuelve a iniciar sesión antes de eliminar una llave de acceso.');
                }
                $user = auth_service()->findById($userId);
                if (!$user) {
                    throw new RuntimeException('La cuenta autenticada ya no está disponible.');
                }
                if (passkey_repository()->countForUser($userId) <= 1 && !auth_service()->hasPassword($user)) {
                    throw new RuntimeException('No puedes eliminar tu único método de acceso sin configurar una alternativa.');
                }
                if (!passkey_repository()->delete((int) ($payload['credential_id'] ?? 0), $userId)) {
                    throw new RuntimeException('La llave de acceso no existe o no pertenece a tu cuenta.');
                }
                webauthn_json_response(['ok' => true, 'message' => 'Llave de acceso eliminada.']);

            default:
                webauthn_json_response(['ok' => false, 'message' => 'Operación WebAuthn no disponible.'], 404);
        }
    } catch (PDOException $exception) {
        error_log('WebAuthn database [' . $action . ']: ' . $exception->getMessage());
        webauthn_json_response([
            'ok' => false,
            'message' => 'No fue posible consultar las llaves de acceso en este momento.',
        ], 500);
    } catch (WebAuthnException $exception) {
        error_log('WebAuthn validation [' . $action . ']: ' . $exception->getMessage());
        auth_failure_delay();
        webauthn_json_response([
            'ok' => false,
            'message' => 'No fue posible verificar la llave de acceso. Solicita una nueva comprobación e inténtalo otra vez.',
        ], 422);
    } catch (RuntimeException $exception) {
        webauthn_json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        error_log('WebAuthn [' . $action . ']: ' . $exception->getMessage());
        webauthn_json_response([
            'ok' => false,
            'message' => 'No fue posible completar la operación con la llave de acceso.',
        ], 500);
    }
}
