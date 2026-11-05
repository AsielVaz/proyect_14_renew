<section class="passkey-shell" data-passkey-manager data-csrf-token="<?= e(csrf_token()) ?>" aria-labelledby="passkey-heading">
    <article class="passkey-hero">
        <div class="passkey-hero-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="3"/><path d="M10.5 13 20 3m-3 3 2 2m-5 1 2 2"/></svg>
        </div>
        <div>
            <p class="eyebrow">Acceso resistente al phishing</p>
            <h2 id="passkey-heading">Tus llaves de acceso</h2>
            <p>Usa la seguridad de tu teléfono, computadora o llave física para entrar a Sistema 14.</p>
        </div>
        <?php if (webauthn_service()->isEnabled()): ?>
            <button class="button primary" type="button" data-passkey-register>+ Registrar una Passkey</button>
        <?php else: ?>
            <span class="operation-badge">No disponible</span>
        <?php endif; ?>
    </article>

    <div class="passkey-status" data-passkey-status role="status" aria-live="polite" hidden></div>

    <?php if ($passkeys === []): ?>
        <article class="passkey-empty">
            <strong>Aún no tienes llaves de acceso</strong>
            <p>Registra una para iniciar sesión con biometría, PIN del dispositivo o una llave de seguridad.</p>
        </article>
    <?php else: ?>
        <div class="passkey-grid">
            <?php foreach ($passkeys as $passkey): ?>
                <article class="passkey-card" data-passkey-card="<?= e($passkey['id']) ?>">
                    <div class="passkey-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 17h6M12 7v5m-2-2h4"/></svg></div>
                    <div class="passkey-card-copy">
                        <h3><?= e($passkey['name']) ?></h3>
                        <p>Registrada: <time datetime="<?= e($passkey['created_at']) ?>"><?= e($passkey['created_at']) ?></time></p>
                        <p>Último uso: <?= $passkey['last_used_at'] ? '<time datetime="' . e($passkey['last_used_at']) . '">' . e($passkey['last_used_at']) . '</time>' : 'Aún no utilizada' ?></p>
                        <div class="passkey-tags">
                            <?php if ($passkey['backup_state']): ?><span>Sincronizada</span><?php elseif ($passkey['backup_eligible']): ?><span>Admite respaldo</span><?php endif; ?>
                            <?php foreach ($passkey['transports'] as $transport): ?><span><?= e($transport) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="passkey-card-actions">
                        <button class="button" type="button" data-passkey-rename="<?= e($passkey['id']) ?>" data-current-name="<?= e($passkey['name']) ?>">Renombrar</button>
                        <button class="button danger" type="button" data-passkey-delete="<?= e($passkey['id']) ?>" aria-describedby="passkey-delete-help">Eliminar</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <aside class="passkey-help" id="passkey-delete-help">
        <strong>Antes de eliminar una llave</strong>
        <p>La eliminación exige que hayas iniciado sesión recientemente. Tu contraseña tradicional permanece como método de recuperación.</p>
    </aside>
</section>
