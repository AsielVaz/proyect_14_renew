<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= e(app_url('/')) ?>">
    <title>Acceso · Sistema 14</title>
    <link rel="stylesheet" href="assets/theme.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-card" aria-labelledby="loginTitle">
        <header class="login-brand">
            <img src="assets/logo-s14.png" alt="Sistema 14">
        </header>

        <?php if ($step === '2fa'): ?>
            <h1 id="loginTitle">Confirma tu identidad</h1>
            <p class="login-copy">Abre tu aplicación de autenticación e ingresa el código vigente.</p>
        <?php else: ?>
            <h1 id="loginTitle">Bienvenido de nuevo</h1>
            <p class="login-copy">Ingresa tus credenciales para continuar.</p>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="login-message info" role="status"><?= e($notice) ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="login-message error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($step === '2fa'): ?>
            <form class="login-form" method="post" action="?auth=2fa" autocomplete="one-time-code">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="field login-field">
                    <span>Código de 6 dígitos</span>
                    <span class="login-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a5 5 0 0 0-5 5v2H5v11h14V10h-2V8a5 5 0 0 0-5-5Zm-3 7V8a3 3 0 0 1 6 0v2"/></svg><input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus required></span>
                </label>
                <button class="button primary" type="submit">Verificar e ingresar</button>
                <a class="login-back" href="?auth=login">Usar otra cuenta</a>
            </form>
        <?php else: ?>
            <form class="login-form" method="post" action="?auth=login" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="field login-field">
                    <span>Correo electrónico</span>
                    <span class="login-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3V6Zm1 1 8 6 8-6"/></svg><input name="email" type="text" inputmode="email" maxlength="254" autocomplete="username" value="<?= e($email) ?>" placeholder="usuario@ejemplo.com" autofocus required></span>
                </label>
                <label class="field login-field">
                    <span>Contraseña</span>
                    <span class="login-input-wrap"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2m-12 0h14v11H5V10Zm7 4v3"/></svg><input name="password" type="password" maxlength="4096" autocomplete="current-password" placeholder="••••••••" required></span>
                </label>
                <button class="button primary login-submit" type="submit">Continuar <span aria-hidden="true">→</span></button>
            </form>
            <?php if (webauthn_service()->isEnabled()): ?>
                <div class="login-passkey">
                    <button class="button passkey-login-button" type="button" data-passkey-login data-identity-field="email">Entrar con mi passkey</button>
                    <p>Usa una passkey registrada sin escribir tu correo. Tu dispositivo puede pedir huella, rostro o PIN.</p>
                    <div class="login-message passkey-message" data-passkey-status role="status" aria-live="polite" hidden></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <footer class="login-security">Copyright © <?= e(date('Y')) ?> Sistema 14. Todos los derechos reservados.</footer>
    </section>
</main>
<script src="assets/ui-motion.js"></script>
<script src="assets/movement-highlights.js"></script>
<?php if (webauthn_service()->isEnabled()): ?><script src="assets/passkeys.js"></script><?php endif; ?>
</body>
</html>
