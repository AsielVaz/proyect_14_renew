<?php

declare(strict_types=1);

$notifierClientTotal = array_sum(array_map(
    static fn (array $group): int => (int) $group['clientCount'],
    $paymentNotifierRecipients
));
?>

<form method="post" class="payment-notifier" data-payment-notifier data-confirm="¿Guardar el mensaje para todos los teléfonos seleccionados? Quedará pendiente de notificación." data-confirm-title="Preparar notificaciones">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="payment_notifier_queue">

    <section class="panel notifier-compose-panel">
        <div class="panel-heading">
            <div><p class="eyebrow">Nuevo mensaje</p><h2>Redactar notificación</h2><p class="panel-copy">El mensaje se guardará en la cola con estado pendiente.</p></div>
            <span class="operation-badge">notificado = 0</span>
        </div>
        <div class="notifier-compose-fields">
            <label class="field"><span>Asunto *</span><input type="text" name="subject" maxlength="256" placeholder="Asunto de la notificación" required data-notifier-subject></label>
            <label class="field"><span>Mensaje *</span><textarea name="message" rows="10" maxlength="20000" placeholder="Escribe el mensaje que recibirán los clientes…" required data-notifier-message></textarea><small><span data-notifier-message-count>0</span> de 20,000 caracteres</small></label>
        </div>
        <div class="notifier-submit-summary" aria-live="polite">
            <span><strong data-notifier-selected-phones>0</strong> teléfonos seleccionados</span>
            <span><strong data-notifier-selected-clients>0</strong> clientes incluidos</span>
        </div>
        <button class="button primary notifier-save" type="submit" data-notifier-submit disabled>Guardar notificaciones</button>
    </section>

    <section class="panel notifier-recipient-panel">
        <div class="panel-heading">
            <div><p class="eyebrow">Destinatarios</p><h2>Seleccionar clientes</h2><p class="panel-copy"><?= number_format(count($paymentNotifierRecipients)) ?> teléfonos únicos · <?= number_format($notifierClientTotal) ?> clientes activos</p></div>
        </div>
        <div class="notifier-recipient-tools">
            <label class="notifier-search"><span class="sr-only">Buscar cliente, teléfono o correo</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg><input type="search" placeholder="Buscar cliente, teléfono o correo…" autocomplete="off" data-notifier-search></label>
            <div><button class="button" type="button" data-notifier-select-all>Seleccionar todos</button><button class="button subtle" type="button" data-notifier-clear-all>Deseleccionar todos</button></div>
        </div>
        <p class="notifier-visible-count" data-notifier-visible-count><?= number_format(count($paymentNotifierRecipients)) ?> teléfonos visibles</p>
        <div class="notifier-recipient-list" data-notifier-list>
            <?php foreach ($paymentNotifierRecipients as $group): ?>
                <?php $searchText = mb_strtolower(trim($group['names'] . ' ' . $group['phone'] . ' ' . $group['emails']), 'UTF-8'); ?>
                <label class="notifier-recipient" data-notifier-recipient data-notifier-search-text="<?= e($searchText) ?>">
                    <input type="checkbox" name="recipient_phones[]" value="<?= e($group['phone']) ?>" data-notifier-checkbox data-client-count="<?= e($group['clientCount']) ?>">
                    <span class="notifier-check" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="m3 8 3 3 7-7"/></svg></span>
                    <span class="notifier-recipient-copy">
                        <span class="notifier-recipient-heading"><strong><?= e($group['names']) ?></strong><?php if ((int) $group['clientCount'] > 1): ?><b><?= e($group['clientCount']) ?> clientes agrupados</b><?php endif; ?></span>
                        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h3l1.5 5-2 1.5a14 14 0 0 0 5 5l1.5-2 5 1.5v3c0 2-1 4-4 4C9 21 3 15 3 7c0-3 2-4 4-4Z"/></svg><?= e($group['phone']) ?></span>
                        <?php if ($group['emails'] !== ''): ?><small><?= e($group['emails']) ?></small><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
            <?php if ($paymentNotifierRecipients === []): ?><p class="empty">No hay clientes activos con un número telefónico válido.</p><?php endif; ?>
            <p class="empty notifier-search-empty" data-notifier-empty hidden>No hay destinatarios que coincidan con la búsqueda.</p>
        </div>
    </section>
</form>
