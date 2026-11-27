<?php
$requestedDay = $_GET['day'] ?? '';
$parsedDay = is_string($requestedDay) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $requestedDay)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDay)
    : false;
$reconciliationDay = $parsedDay && $parsedDay->format('Y-m-d') === $requestedDay
    ? $requestedDay
    : date('Y-m-d');
$pendingDeposits = invoice_repository()->manualReconciliation($reconciliationDay);
?>
<section class="panel">
    <div class="panel-heading list-tools">
        <div>
            <h2>Depósitos internos sin factura</h2>
            <p class="panel-copy"><?= e(number_format(count($pendingDeposits))) ?> pendientes · <?= e(display_date($reconciliationDay)) ?></p>
        </div>
        <form method="get" class="report-filters document-filters">
            <input type="hidden" name="movement" value="consiliador-manual">
            <label><span>Fecha de asignación</span><input type="date" name="day" value="<?= e($reconciliationDay) ?>" required></label>
            <button type="submit" class="button">Consultar día</button>
        </form>
    </div>
    <div class="table-wrap">
        <table class="movement-table">
            <thead><tr><th>Depósito</th><th>Empresa emisora</th><th>Monto</th><th>Receptor identificado</th><th>Acción</th></tr></thead>
            <tbody>
                <?php foreach ($pendingDeposits as $deposit): ?>
                    <?php $receiver = $deposit['receiver']; ?>
                    <tr>
                        <td><span class="movement-cell-main">#<?= e($deposit['id']) ?></span><?php if (trim((string) $deposit['detalle']) !== ''): ?><small class="movement-cell-secondary"><?= e($deposit['detalle']) ?></small><?php endif; ?></td>
                        <td><?= e($deposit['emisor'] ?: 'Sin empresa') ?></td>
                        <td><?= e(report_value($deposit['monto'], 'money')) ?></td>
                        <td>
                            <?php if ($receiver): ?>
                                <span class="movement-cell-main"><?= e($receiver['razon_social']) ?></span>
                                <small class="movement-cell-secondary"><?= e($receiver['clave_corta']) ?> · <?= e($receiver['rfc']) ?> · Retiro #<?= e($receiver['withdrawal_id']) ?></small>
                            <?php else: ?>
                                <span class="movement-cell-secondary">Por identificar</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($deposit['reason'] === ''): ?>
                                <a class="button compact" href="?<?= e(http_build_query(['invoice' => 'create', 'payment_id' => $deposit['id'], 'reconciliation_day' => $reconciliationDay])) ?>">Crear factura genérica</a>
                            <?php else: ?>
                                <button type="button" class="button compact" disabled>Crear factura genérica</button>
                                <small class="movement-cell-secondary"><?= e($deposit['reason']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pendingDeposits === []): ?><tr><td colspan="5" class="empty">No hay depósitos internos sin factura para este día.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
