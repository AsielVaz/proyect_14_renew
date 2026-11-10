<?php

declare(strict_types=1);

$manualCaptureIsForm = $action === 'form';
$manualCaptureRows = $manualCaptureResult['rows'] ?? [];
$manualCapturePage = (int) ($manualCaptureResult['page'] ?? 1);
$manualCaptureHasNext = (bool) ($manualCaptureResult['hasNext'] ?? false);
?>

<section class="report-intro movement-intro">
    <p><?= e($movement['description']) ?></p>
    <span class="operation-badge">Operación transaccional y auditada</span>
</section>

<?php if ($manualCaptureIsForm): ?>
    <section class="panel manual-capture-panel">
        <div class="panel-heading">
            <div><p class="eyebrow">Movimiento bancario individual</p><h2>Nueva captura manual</h2><p class="panel-copy">Registra exactamente lo que aparece en el banco. Si el detalle contiene una clave corta, el movimiento se asignará y calculará automáticamente.</p></div>
            <a class="button" href="?movement=capturas-manuales">Volver al historial</a>
        </div>
        <?php if ($manualCaptureCatalog === []): ?>
            <div class="empty">No hay empresas con cuentas bancarias activas disponibles para capturar.</div>
        <?php else: ?>
            <form method="post" class="manual-capture-form" data-manual-capture-form data-confirm="¿Registrar esta captura manual? Verifica el tipo, monto, fecha y saldo bancario." data-confirm-title="Confirmar captura manual">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="manual_capture_create">

                <fieldset class="manual-capture-section">
                    <legend><span>1</span><strong>Origen bancario</strong></legend>
                    <div class="manual-capture-grid is-origin">
                        <label class="field"><span>Empresa *</span><select name="company_id" required data-manual-company><option value="">Seleccionar empresa…</option><?php foreach ($manualCaptureCatalog as $company): ?><option value="<?= e($company['id']) ?>"><?= e($company['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Cuenta bancaria *</span><select name="account_id" required disabled data-manual-account aria-describedby="manualAccountHelp"><option value="">Primero selecciona una empresa</option><?php foreach ($manualCaptureCatalog as $company): ?><?php foreach ($company['accounts'] as $account): ?><option value="<?= e($account['id']) ?>" data-company-id="<?= e($company['id']) ?>" data-balance="<?= e($account['balance']) ?>" data-currency="<?= e($account['currency']) ?>" disabled hidden><?= e($account['bank'] . ' · ' . $account['name']) ?></option><?php endforeach; ?><?php endforeach; ?></select><small id="manualAccountHelp">Solo se muestran cuentas activas de la empresa elegida.</small></label>
                    </div>
                </fieldset>

                <fieldset class="manual-capture-section">
                    <legend><span>2</span><strong>Datos del movimiento</strong></legend>
                    <div class="manual-capture-grid">
                        <label class="field"><span>Tipo de movimiento *</span><select name="movement_type" required data-manual-type><option value="">Seleccionar…</option><option value="Deposito">Depósito</option><option value="Retiro">Retiro</option></select></label>
                        <label class="field"><span>Monto *</span><input type="number" name="amount" min="0.01" max="999999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" required data-manual-amount></label>
                        <label class="field"><span>Fecha del banco *</span><input type="date" name="bank_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                        <label class="field"><span>Saldo mostrado por el banco *</span><input type="number" name="balance" step="0.01" inputmode="decimal" placeholder="0.00" required data-manual-balance><small>Puede ser positivo, cero o negativo.</small></label>
                    </div>
                    <label class="field manual-capture-detail"><span>Detalle o concepto bancario *</span><textarea name="detail" rows="3" maxlength="500" placeholder="Copia el concepto completo; aquí se buscará la clave corta del cliente." required></textarea><small>Se conserva el texto original y se usa para detectar la cartera, comisiones y facturación automática.</small></label>
                </fieldset>

                <section class="manual-capture-preview" aria-labelledby="manualCapturePreviewTitle" aria-live="polite">
                    <div><p class="eyebrow">Vista previa</p><h3 id="manualCapturePreviewTitle">Efecto en la cuenta bancaria</h3></div>
                    <div class="manual-capture-preview-grid">
                        <article><span>Saldo registrado</span><strong data-manual-current>—</strong><small>Antes de esta captura</small></article>
                        <article><span>Efecto del movimiento</span><strong data-manual-change>—</strong><small data-manual-change-label>Selecciona tipo y monto</small></article>
                        <article class="is-reported"><span>Saldo capturado</span><strong data-manual-reported>—</strong><small>El que aparece en el banco</small></article>
                    </div>
                </section>

                <div class="form-actions manual-capture-actions"><a class="button subtle" href="?movement=capturas-manuales">Cancelar</a><button class="button primary" type="submit" data-manual-submit>Registrar captura manual</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel manual-capture-history">
        <div class="panel-heading list-tools">
            <div><p class="eyebrow">Historial</p><h2>Capturas manuales</h2><p class="panel-copy"><?= number_format(count($manualCaptureRows)) ?> registros en esta página.</p></div>
            <form method="get" class="search manual-capture-page-size"><input type="hidden" name="movement" value="capturas-manuales"><label class="movement-page-size"><span>Registros</span><select name="per_page" onchange="this.form.submit()"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $manualCapturePerPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label></form>
        </div>
        <div class="table-wrap">
            <table class="movement-table">
                <thead><tr><th>Captura</th><th>Empresa / cuenta</th><th>Movimiento</th><th>Fecha bancaria</th><th>Monto</th><th>Saldo bancario</th><th>Detalle</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($manualCaptureRows as $row): ?>
                    <?php $isWithdrawal = strcasecmp((string) $row['tipo_movimiento'], 'Retiro') === 0; ?>
                    <tr>
                        <td><a href="?companies=capture&amp;id=<?= e($row['company_id']) ?>&amp;capture_id=<?= e($row['id']) ?>"><strong>#<?= e($row['id']) ?></strong></a><?php if ((int) ($row['payment_id'] ?? 0) > 0): ?><span class="movement-cell-secondary">Movimiento #<?= e($row['payment_id']) ?></span><?php endif; ?></td>
                        <td><span class="movement-cell-main"><?= e($row['company']) ?></span><span class="movement-cell-secondary"><?= e($row['banco'] . ' · ' . $row['nombre_corto']) ?></span></td>
                        <td><span class="movement-kind <?= $isWithdrawal ? 'is-withdrawal' : 'is-deposit' ?>"><?= e($row['tipo_movimiento']) ?></span><?php if (trim((string) ($row['client'] ?? '')) !== ''): ?><span class="movement-cell-secondary">Asignado a <?= e($row['client']) ?></span><?php else: ?><span class="movement-cell-secondary">Sin asignar</span><?php endif; ?></td>
                        <td><?= e(display_date($row['bank_date'])) ?></td>
                        <td><strong><?= e(report_value($row['amount'], 'money')) ?></strong></td>
                        <td><?= e(report_value($row['balance'], 'money')) ?></td>
                        <td><span class="movement-description-text"><?= e($row['detail'] ?: 'Sin detalle') ?></span></td>
                        <td><form method="post" data-confirm="¿Eliminar la captura manual #<?= e($row['id']) ?> y sus movimientos asociados?" data-confirm-title="Eliminar captura manual"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="manual_capture_delete"><input type="hidden" name="capture_id" value="<?= e($row['id']) ?>"><button class="button danger" type="submit">Eliminar</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($manualCaptureRows === []): ?><tr><td colspan="8" class="empty">Aún no hay capturas manuales disponibles.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination"><span>Página <?= $manualCapturePage ?><?= $manualCaptureHasNext ? ' · Hay más resultados' : '' ?></span><div><?php if ($manualCapturePage > 1): ?><a class="button" href="?<?= e(http_build_query(['movement'=>'capturas-manuales','per_page'=>$manualCapturePerPage,'page'=>$manualCapturePage - 1])) ?>">← Anterior</a><?php endif; ?><?php if ($manualCaptureHasNext): ?><a class="button" href="?<?= e(http_build_query(['movement'=>'capturas-manuales','per_page'=>$manualCapturePerPage,'page'=>$manualCapturePage + 1])) ?>">Siguiente →</a><?php endif; ?></div></div>
    </section>
<?php endif; ?>

<?php if ($manualCaptureIsForm && $manualCaptureCatalog !== []): ?>
<script>
(() => {
    const form = document.querySelector('[data-manual-capture-form]');
    if (!form) return;
    const company = form.querySelector('[data-manual-company]');
    const account = form.querySelector('[data-manual-account]');
    const type = form.querySelector('[data-manual-type]');
    const amount = form.querySelector('[data-manual-amount]');
    const balance = form.querySelector('[data-manual-balance]');
    const currentOutput = form.querySelector('[data-manual-current]');
    const changeOutput = form.querySelector('[data-manual-change]');
    const reportedOutput = form.querySelector('[data-manual-reported]');
    const changeLabel = form.querySelector('[data-manual-change-label]');
    const money = (value, currency = 'MXN') => new Intl.NumberFormat('es-MX', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value) || 0);

    const updatePreview = () => {
        const option = account.options[account.selectedIndex];
        const currency = option?.dataset.currency || 'MXN';
        const current = Number(option?.dataset.balance || 0);
        const captured = Number(balance.value);
        const movementAmount = Math.abs(Number(amount.value) || 0);
        const direction = type.value === 'Retiro' ? -1 : (type.value === 'Deposito' ? 1 : 0);
        currentOutput.textContent = option?.dataset.balance !== undefined ? money(current, currency) : '—';
        changeOutput.textContent = direction ? `${direction > 0 ? '+' : '−'}${money(movementAmount, currency)}` : '—';
        changeOutput.classList.toggle('is-withdrawal', direction < 0);
        changeOutput.classList.toggle('is-deposit', direction > 0);
        changeLabel.textContent = direction ? (direction > 0 ? 'Depósito agregado' : 'Retiro descontado') : 'Selecciona tipo y monto';
        reportedOutput.textContent = balance.value !== '' ? money(captured, currency) : '—';
    };
    const updateAccounts = () => {
        const companyId = company.value;
        let first = null;
        Array.from(account.options).forEach((option, index) => {
            if (index === 0) return;
            const visible = option.dataset.companyId === companyId;
            option.disabled = !visible;
            option.hidden = !visible;
            if (visible && !first) first = option;
        });
        account.disabled = !first;
        account.options[0].textContent = first ? 'Seleccionar cuenta…' : 'No hay cuentas activas';
        account.value = first ? first.value : '';
        updatePreview();
    };
    company.addEventListener('change', updateAccounts);
    account.addEventListener('change', updatePreview);
    type.addEventListener('change', updatePreview);
    amount.addEventListener('input', updatePreview);
    balance.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>
<?php endif; ?>
