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
            <div><strong>Sistema 14</strong><span>Administración financiera</span></div>
        </header>

        <?php if ($step === '2fa'): ?>
            <p class="eyebrow">Verificación en dos pasos</p>
            <h1 id="loginTitle">Confirma tu identidad</h1>
            <p class="login-copy">Abre tu aplicación de autenticación e ingresa el código vigente.</p>
        <?php else: ?>
            <p class="eyebrow">Acceso seguro</p>
            <h1 id="loginTitle">Bienvenido</h1>
            <p class="login-copy">Ingresa con las mismas credenciales de Sistema 14.</p>
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
                <label class="field">
                    <span>Código de 6 dígitos</span>
                    <input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
                </label>
                <button class="button primary" type="submit">Verificar e ingresar</button>
                <a class="login-back" href="?auth=login">Usar otra cuenta</a>
            </form>
        <?php else: ?>
            <form class="login-form" method="post" action="?auth=login" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="field">
                    <span>Correo o usuario de acceso</span>
                    <input name="email" type="text" inputmode="email" maxlength="254" autocomplete="username" value="<?= e($email) ?>" autofocus required>
                </label>
                <label class="field">
                    <span>Contraseña</span>
                    <input name="password" type="password" maxlength="4096" autocomplete="current-password" required>
                </label>
                <button class="button primary" type="submit">Ingresar</button>
            </form>
        <?php endif; ?>

        <footer class="login-security">Sesión protegida · Los datos de acceso no se modifican al ingresar</footer>
    </section>
</main>
</body>
</html>
