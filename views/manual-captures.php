<?php

declare(strict_types=1);

$manualCaptureIsForm = $action === 'form';
$manualCaptureRows = $manualCaptureResult['rows'] ?? [];
$manualCapturePage = (int) ($manualCaptureResult['page'] ?? 1);
$manualCaptureHasNext = (bool) ($manualCaptureResult['hasNext'] ?? false);
$captureTotal = (int) ($manualCaptureResult['total'] ?? count($manualCaptureRows));
$capturePages = (int) ($manualCaptureResult['pages'] ?? 0);
$canDeleteCapture = in_array((string) (current_user()['type'] ?? ''), ['administrador', 'administrador_b'], true);
$captureTabs = ['all' => 'Todas las capturas', 'manual' => 'Capturas manuales', 'deleted' => 'Capturas eliminadas'];
$captureBaseQuery = ['movement' => 'capturas-manuales', 'view' => $captureSection, 'q' => $captureSearch ?: null, 'per_page' => $manualCapturePerPage];
?>

<section class="report-intro movement-intro capture-module-intro">
    <div><p><?= e($movement['description']) ?></p><span class="operation-badge">Operación transaccional y auditada</span></div>
    <nav class="capture-tabs" aria-label="Tipos de captura">
        <?php foreach ($captureTabs as $tabKey => $tabLabel): ?><a href="?<?= e(http_build_query(['movement'=>'capturas-manuales','view'=>$tabKey])) ?>" <?= $captureSection === $tabKey ? 'aria-current="page" class="is-active"' : '' ?>><?= e($tabLabel) ?></a><?php endforeach; ?>
    </nav>
</section>

<?php if ($manualCaptureIsForm): ?>
    <section class="panel manual-capture-panel">
        <div class="panel-heading">
            <div><p class="eyebrow">Movimiento bancario individual</p><h2>Nueva captura manual</h2><p class="panel-copy">Registra exactamente lo que aparece en el banco. Si el detalle contiene una clave corta, el movimiento se asignará y calculará automáticamente.</p></div>
            <a class="button" href="?movement=capturas-manuales&amp;view=manual">Volver al historial</a>
        </div>
        <?php if ($manualCaptureCatalog === []): ?>
            <div class="empty">No hay empresas con cuentas bancarias activas disponibles para capturar.</div>
        <?php else: ?>
            <form method="post" class="manual-capture-form" data-manual-capture-form data-confirm="¿Registrar esta captura manual? Verifica el tipo, monto, fecha y saldo bancario." data-confirm-title="Confirmar captura manual">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="manual_capture_create"><input type="hidden" name="return_view" value="manual">
                <fieldset class="manual-capture-section">
                    <legend><span>1</span><strong>Origen bancario</strong></legend>
                    <div class="manual-capture-grid is-origin">
                        <label class="field"><span>Empresa *</span><select name="company_id" required data-manual-company><option value="">Seleccionar empresa…</option><?php foreach ($manualCaptureCatalog as $company): ?><option value="<?= e($company['id']) ?>"><?= e($company['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Cuenta bancaria *</span><select name="account_id" required disabled data-manual-account aria-describedby="manualAccountHelp"><option value="">Primero selecciona una empresa</option></select><small id="manualAccountHelp" data-manual-account-help>Solo se muestran cuentas activas de la empresa elegida.</small></label>
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
                <div class="form-actions manual-capture-actions"><a class="button subtle" href="?movement=capturas-manuales&amp;view=manual">Cancelar</a><button class="button primary" type="submit">Registrar captura manual</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php elseif (is_array($deletedCaptureDetail)): ?>
    <section class="panel capture-deleted-detail">
        <div class="panel-heading"><div><p class="eyebrow">Captura eliminada</p><h2>Archivo #<?= e($deletedCaptureDetail['id']) ?></h2><p class="panel-copy"><?= e($deletedCaptureDetail['company']) ?> · <?= e($deletedCaptureDetail['banco'] . ' · ' . $deletedCaptureDetail['nombre_corto']) ?></p></div><a class="button" href="?movement=capturas-manuales&amp;view=deleted">Volver a eliminadas</a></div>
        <dl class="capture-deleted-meta"><div><dt>Capturada</dt><dd><?= e(display_date(trim((string) ($deletedCaptureDetail['fecha'] . ' ' . $deletedCaptureDetail['hora'])), true)) ?></dd></div><div><dt>Eliminada</dt><dd><?= e(display_date($deletedCaptureDetail['fecha_elimina'], true)) ?></dd></div><div><dt>Eliminó</dt><dd><?= e($deletedCaptureDetail['deleted_by']) ?></dd></div></dl>
        <div><p class="eyebrow">Contenido original archivado</p><p class="panel-copy"><?php if ((int) $deletedCaptureDetail['captura_longitud'] > 50000): ?>Vista limitada a los primeros 50,000 caracteres de <?= number_format((int) $deletedCaptureDetail['captura_longitud']) ?>.<?php else: ?>El contenido se muestra como texto seguro y no ejecuta el HTML almacenado.<?php endif; ?></p></div>
        <pre class="capture-raw"><?= e($deletedCaptureDetail['captura']) ?></pre>
    </section>
<?php else: ?>
    <section class="panel manual-capture-history">
        <div class="panel-heading list-tools capture-list-heading">
            <div><p class="eyebrow">Historial</p><h2><?= e($captureTabs[$captureSection]) ?></h2><p class="panel-copy"><?= number_format($captureTotal) ?> registros encontrados.</p></div>
            <form method="get" class="search capture-list-filters"><input type="hidden" name="movement" value="capturas-manuales"><input type="hidden" name="view" value="<?= e($captureSection) ?>"><label class="field"><span>Buscar</span><input type="search" name="q" value="<?= e($captureSearch) ?>" placeholder="ID, empresa, banco, cuenta o detalle"></label><label class="movement-page-size"><span>Registros</span><select name="per_page" onchange="this.form.submit()"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $manualCapturePerPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label><button class="button" type="submit">Buscar</button></form>
        </div>
        <div class="table-wrap">
            <?php if ($captureSection === 'manual'): ?>
                <table class="movement-table"><thead><tr><th>Captura</th><th>Empresa / cuenta</th><th>Movimiento</th><th>Fecha bancaria</th><th>Monto</th><th>Saldo bancario</th><th>Detalle</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($manualCaptureRows as $row): $isWithdrawal = strcasecmp((string) $row['tipo_movimiento'], 'Retiro') === 0; ?><tr><td><a href="?companies=capture&amp;id=<?= e($row['company_id']) ?>&amp;capture_id=<?= e($row['id']) ?>"><strong>#<?= e($row['id']) ?></strong></a><?php if ((int) ($row['payment_id'] ?? 0) > 0): ?><span class="movement-cell-secondary">Movimiento #<?= e($row['payment_id']) ?></span><?php endif; ?></td><td><span class="movement-cell-main"><?= e($row['company']) ?></span><span class="movement-cell-secondary"><?= e($row['banco'] . ' · ' . $row['nombre_corto']) ?></span></td><td><span class="movement-kind <?= $isWithdrawal ? 'is-withdrawal' : 'is-deposit' ?>"><?= e($row['tipo_movimiento']) ?></span><span class="movement-cell-secondary"><?= trim((string) ($row['client'] ?? '')) !== '' ? 'Asignado a ' . e($row['client']) : 'Sin asignar' ?></span></td><td><?= e(display_date($row['bank_date'])) ?></td><td><strong><?= e(report_value($row['amount'], 'money')) ?></strong></td><td><?= e(report_value($row['balance'], 'money')) ?></td><td><span class="movement-description-text"><?= e($row['detail'] ?: 'Sin detalle') ?></span></td><td><form method="post" data-confirm="¿Eliminar la captura manual #<?= e($row['id']) ?> y sus movimientos asociados?" data-confirm-title="Eliminar captura manual"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="manual_capture_delete"><input type="hidden" name="return_view" value="manual"><input type="hidden" name="capture_id" value="<?= e($row['id']) ?>"><button class="button danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
                <?php if ($manualCaptureRows === []): ?><tr><td colspan="8" class="empty">Aún no hay capturas manuales disponibles.</td></tr><?php endif; ?></tbody></table>
            <?php elseif ($captureSection === 'all'): ?>
                <table class="movement-table"><thead><tr><th>Captura</th><th>Empresa / cuenta</th><th>Fecha</th><th>Movimientos</th><th>Depósitos</th><th>Retiros</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($manualCaptureRows as $row): ?><tr><td><strong>#<?= e($row['id']) ?></strong></td><td><span class="movement-cell-main"><?= e($row['company']) ?></span><span class="movement-cell-secondary"><?= e($row['banco'] . ' · ' . $row['nombre_corto']) ?></span></td><td><?= e(display_date(trim((string) ($row['captured_date'] . ' ' . $row['captured_time'])), true)) ?></td><td><?= number_format((int) $row['movements']) ?></td><td><?= e(report_value($row['deposits'], 'money')) ?></td><td><?= e(report_value($row['withdrawals'], 'money')) ?></td><td><div class="capture-row-actions"><a class="button" href="?companies=capture&amp;id=<?= e($row['company_id']) ?>&amp;capture_id=<?= e($row['id']) ?>">Ver captura</a><?php if ($canDeleteCapture): ?><form method="post" data-confirm="¿Eliminar la captura bancaria #<?= e($row['id']) ?> y sus movimientos? Se archivará una copia del contenido original." data-confirm-title="Eliminar captura bancaria"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="capture_delete"><input type="hidden" name="return_view" value="all"><input type="hidden" name="capture_id" value="<?= e($row['id']) ?>"><button class="button danger" type="submit">Eliminar</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
                <?php if ($manualCaptureRows === []): ?><tr><td colspan="7" class="empty">No hay capturas para los filtros seleccionados.</td></tr><?php endif; ?></tbody></table>
            <?php else: ?>
                <table class="movement-table"><thead><tr><th>Archivo</th><th>Empresa / cuenta</th><th>Fecha de captura</th><th>Fecha de eliminación</th><th>Eliminó</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($manualCaptureRows as $row): ?><tr><td><strong>#<?= e($row['id']) ?></strong></td><td><span class="movement-cell-main"><?= e($row['company']) ?></span><span class="movement-cell-secondary"><?= e($row['banco'] . ' · ' . $row['nombre_corto']) ?></span></td><td><?= e(display_date(trim((string) ($row['captured_date'] . ' ' . $row['captured_time'])), true)) ?></td><td><?= e(display_date($row['deleted_at'], true)) ?></td><td><?= e($row['deleted_by']) ?></td><td><a class="button" href="?<?= e(http_build_query(['movement'=>'capturas-manuales','view'=>'deleted','action'=>'detail','capture_id'=>$row['id']])) ?>">Ver captura</a></td></tr><?php endforeach; ?>
                <?php if ($manualCaptureRows === []): ?><tr><td colspan="6" class="empty">No hay capturas eliminadas para los filtros seleccionados.</td></tr><?php endif; ?></tbody></table>
            <?php endif; ?>
        </div>
        <div class="pagination"><span>Página <?= $manualCapturePage ?><?= $capturePages > 0 ? ' de ' . $capturePages : ($manualCaptureHasNext ? ' · Hay más resultados' : '') ?></span><div><?php if ($manualCapturePage > 1): ?><a class="button" href="?<?= e(http_build_query(array_filter($captureBaseQuery + ['page'=>$manualCapturePage - 1], static fn ($value) => $value !== null && $value !== ''))) ?>">← Anterior</a><?php endif; ?><?php if ($manualCaptureHasNext): ?><a class="button" href="?<?= e(http_build_query(array_filter($captureBaseQuery + ['page'=>$manualCapturePage + 1], static fn ($value) => $value !== null && $value !== ''))) ?>">Siguiente →</a><?php endif; ?></div></div>
    </section>
<?php endif; ?>

<?php if ($manualCaptureIsForm && $manualCaptureCatalog !== []): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const form = document.querySelector('[data-manual-capture-form]');
    if (!form) return;
    const catalog = <?= json_encode($manualCaptureCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const company = form.querySelector('[data-manual-company]');
    const account = form.querySelector('[data-manual-account]');
    const help = form.querySelector('[data-manual-account-help]');
    const type = form.querySelector('[data-manual-type]');
    const amount = form.querySelector('[data-manual-amount]');
    const balance = form.querySelector('[data-manual-balance]');
    const currentOutput = form.querySelector('[data-manual-current]');
    const changeOutput = form.querySelector('[data-manual-change]');
    const reportedOutput = form.querySelector('[data-manual-reported]');
    const changeLabel = form.querySelector('[data-manual-change-label]');
    const money = (value, currency = 'MXN') => new Intl.NumberFormat('es-MX', {style:'currency', currency, maximumFractionDigits:2}).format(Number(value) || 0);
    const updatePreview = () => {
        const option = account.selectedOptions[0];
        const currency = option?.dataset.currency || 'MXN';
        const movementAmount = Math.abs(Number(amount.value) || 0);
        const direction = type.value === 'Retiro' ? -1 : (type.value === 'Deposito' ? 1 : 0);
        currentOutput.textContent = option?.dataset.balance !== undefined ? money(option.dataset.balance, currency) : '—';
        changeOutput.textContent = direction ? `${direction > 0 ? '+' : '−'}${money(movementAmount, currency)}` : '—';
        changeOutput.classList.toggle('is-withdrawal', direction < 0);
        changeOutput.classList.toggle('is-deposit', direction > 0);
        changeLabel.textContent = direction ? (direction > 0 ? 'Depósito agregado' : 'Retiro descontado') : 'Selecciona tipo y monto';
        reportedOutput.textContent = balance.value !== '' ? money(balance.value, currency) : '—';
    };
    const updateAccounts = () => {
        const selectedCompany = catalog.find(item => String(item.id) === company.value);
        const accounts = selectedCompany?.accounts || [];
        account.replaceChildren(new Option(accounts.length ? 'Seleccionar cuenta…' : (company.value ? 'No hay cuentas activas' : 'Primero selecciona una empresa'), ''));
        accounts.forEach(item => {
            const option = new Option(`${item.bank} · ${item.name}`, String(item.id));
            option.dataset.balance = String(item.balance ?? 0);
            option.dataset.currency = item.currency || 'MXN';
            account.add(option);
        });
        account.disabled = accounts.length === 0;
        help.textContent = accounts.length ? `${accounts.length} cuenta${accounts.length === 1 ? '' : 's'} activa${accounts.length === 1 ? '' : 's'} disponible${accounts.length === 1 ? '' : 's'}.` : 'La empresa seleccionada no tiene cuentas activas.';
        updatePreview();
    };
    company.addEventListener('change', updateAccounts);
    account.addEventListener('change', updatePreview);
    type.addEventListener('change', updatePreview);
    amount.addEventListener('input', updatePreview);
    balance.addEventListener('input', updatePreview);
    updateAccounts();
})();
</script>
<?php endif; ?>
