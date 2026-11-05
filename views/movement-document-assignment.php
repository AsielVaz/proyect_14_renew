<?php

declare(strict_types=1);

$assignmentPayment = $movementDocumentAssignment['payment'];
$assignmentInvoices = $movementDocumentAssignment['invoices'];
$assignmentComplements = $movementDocumentAssignment['complements'];
$assignmentDetail = \App\Movements::uniqueDetailText(
    $assignmentPayment['detalle'] ?? null,
    $assignmentPayment['descripcion'] ?? null,
    null
);
$documentReturnFields = [
    'return_page' => $movementPage,
    'return_q' => $movementSearch,
    'return_client' => $movementClient['id'] ?? '',
    'return_movement_type' => $movementTypeFilter,
    'return_per_page' => $movementPerPageValue,
];
?>

<section class="panel document-assignment-panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">Asociación manual</p>
            <h2>Asignar documento al movimiento #<?= e($assignmentPayment['id']) ?></h2>
            <p class="panel-copy">Solo se muestran documentos de <?= e($assignmentPayment['company']) ?> con una diferencia máxima de $10.00.</p>
        </div>
        <a class="button" href="<?= e($movementReturnUrl) ?>">Volver a movimientos</a>
    </div>

    <div class="document-assignment-summary" aria-label="Resumen del movimiento">
        <article><span>Monto del movimiento</span><strong><?= e(report_value($assignmentPayment['amount'], 'money')) ?></strong></article>
        <article><span>Empresa</span><strong><?= e($assignmentPayment['company']) ?></strong></article>
        <article><span>Cartera</span><strong><?= e($assignmentPayment['client'] ?: 'Sin cartera identificada') ?></strong></article>
        <article><span>Rango admitido</span><strong><?= e(report_value($movementDocumentAssignment['minimum'], 'money')) ?> a <?= e(report_value($movementDocumentAssignment['maximum'], 'money')) ?></strong></article>
    </div>
    <?php if ($assignmentDetail !== ''): ?><p class="document-assignment-detail"><strong>Detalle:</strong> <?= e($assignmentDetail) ?></p><?php endif; ?>
</section>

<section class="panel document-candidate-panel">
    <div class="panel-heading">
        <div><p class="eyebrow">Documentos compatibles</p><h2>Facturas</h2><p class="panel-copy"><?= number_format(count($assignmentInvoices)) ?> coincidencias por empresa y monto.</p></div>
    </div>
    <div class="table-wrap">
        <table class="document-candidate-table">
            <thead><tr><th>Factura</th><th>Fecha</th><th>Cliente / receptor</th><th>Total</th><th>Diferencia</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach ($assignmentInvoices as $document): ?>
                <?php
                $documentDate = (string) (($document['fecha_timbrado'] ?? '') ?: ($document['fecha'] ?? ''));
                $difference = round((float) $document['amount'] - (float) $assignmentPayment['amount'], 2);
                $status = trim((string) ($document['status'] ?? ''));
                if ($status === '') $status = trim((string) ($document['uuid'] ?? '')) !== '' ? 'Timbrada' : ((string) ($document['status_error'] ?? '') ?: 'Pendiente');
                ?>
                <tr>
                    <td><span class="movement-cell-main">#<?= e($document['id']) ?> · <?= e(($document['serie'] ?? '') . ($document['folio'] ?? '')) ?></span><span class="movement-cell-secondary document-uuid"><?= e($document['uuid'] ?: 'Sin UUID') ?></span></td>
                    <td><time datetime="<?= e($documentDate) ?>"><?= e(display_date($documentDate)) ?></time></td>
                    <td><span class="movement-cell-main"><?= e($document['client'] ?: 'Sin cliente') ?></span><span class="movement-cell-secondary"><?= e($document['receiver'] ?: 'Sin receptor') ?></span></td>
                    <td><?= e(report_value($document['amount'], 'money')) ?></td>
                    <td><span class="document-difference<?= abs($difference) < 0.005 ? ' is-exact' : '' ?>"><?= $difference > 0 ? '+' : '' ?><?= e(report_value($difference, 'money')) ?></span></td>
                    <td><?= e($status) ?></td>
                    <td class="document-candidate-actions">
                        <a class="button subtle" href="?document=invoice_pdf&amp;id=<?= e($document['id']) ?>" target="_blank" rel="noopener">Ver</a>
                        <form method="post" data-confirm="¿Relacionar esta factura con el movimiento #<?= e($assignmentPayment['id']) ?>?" data-confirm-title="Confirmar relación">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="movement_assign_document">
                            <input type="hidden" name="payment_id" value="<?= e($assignmentPayment['id']) ?>">
                            <input type="hidden" name="document_type" value="invoice">
                            <input type="hidden" name="document_id" value="<?= e($document['id']) ?>">
                            <?php foreach ($documentReturnFields as $returnName => $returnValue): ?><input type="hidden" name="<?= e($returnName) ?>" value="<?= e($returnValue) ?>"><?php endforeach; ?>
                            <?php foreach ($movementDocumentFilters as $returnFilter): ?><input type="hidden" name="return_doc_filter[]" value="<?= e($returnFilter) ?>"><?php endforeach; ?>
                            <button class="button primary" type="submit">Relacionar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($assignmentInvoices === []): ?><tr><td colspan="7" class="empty">No se encontraron facturas de esta empresa dentro del rango de monto permitido.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel document-candidate-panel">
    <div class="panel-heading">
        <div><p class="eyebrow">Documentos compatibles</p><h2>Complementos de pago</h2><p class="panel-copy"><?= number_format(count($assignmentComplements)) ?> coincidencias por empresa y monto.</p></div>
    </div>
    <div class="table-wrap">
        <table class="document-candidate-table">
            <thead><tr><th>Complemento</th><th>Fecha de pago</th><th>Cliente / receptor</th><th>Total</th><th>Diferencia</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>
            <?php foreach ($assignmentComplements as $document): ?>
                <?php
                $documentDate = (string) (($document['payment_date'] ?? '') ?: ($document['fecha'] ?? ''));
                $difference = round((float) $document['amount'] - (float) $assignmentPayment['amount'], 2);
                $status = trim((string) ($document['status'] ?? ''));
                if ($status === '') $status = trim((string) ($document['uuid'] ?? '')) !== '' ? 'Timbrado' : ((string) ($document['status_error'] ?? '') ?: 'Pendiente');
                ?>
                <tr>
                    <td><span class="movement-cell-main">#<?= e($document['id']) ?> · <?= e(($document['serie'] ?? 'P') . ($document['folio'] ?? '')) ?></span><span class="movement-cell-secondary document-uuid"><?= e($document['uuid'] ?: 'Sin UUID') ?></span></td>
                    <td><time datetime="<?= e($documentDate) ?>"><?= e(display_date($documentDate)) ?></time></td>
                    <td><span class="movement-cell-main"><?= e($document['client'] ?: 'Sin cliente') ?></span><span class="movement-cell-secondary"><?= e($document['receiver'] ?: 'Sin receptor') ?></span></td>
                    <td><?= e(report_value($document['amount'], 'money')) ?></td>
                    <td><span class="document-difference<?= abs($difference) < 0.005 ? ' is-exact' : '' ?>"><?= $difference > 0 ? '+' : '' ?><?= e(report_value($difference, 'money')) ?></span></td>
                    <td><?= e($status) ?></td>
                    <td class="document-candidate-actions">
                        <a class="button subtle" href="?complement=detail&amp;id=<?= e($document['id']) ?>">Ver</a>
                        <form method="post" data-confirm="¿Relacionar este complemento con el movimiento #<?= e($assignmentPayment['id']) ?>?" data-confirm-title="Confirmar relación">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="movement_assign_document">
                            <input type="hidden" name="payment_id" value="<?= e($assignmentPayment['id']) ?>">
                            <input type="hidden" name="document_type" value="complement">
                            <input type="hidden" name="document_id" value="<?= e($document['id']) ?>">
                            <?php foreach ($documentReturnFields as $returnName => $returnValue): ?><input type="hidden" name="<?= e($returnName) ?>" value="<?= e($returnValue) ?>"><?php endforeach; ?>
                            <?php foreach ($movementDocumentFilters as $returnFilter): ?><input type="hidden" name="return_doc_filter[]" value="<?= e($returnFilter) ?>"><?php endforeach; ?>
                            <button class="button primary" type="submit">Relacionar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($assignmentComplements === []): ?><tr><td colspan="7" class="empty">No se encontraron complementos de esta empresa dentro del rango de monto permitido.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
