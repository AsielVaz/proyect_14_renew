<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use App\Catalogs;
use App\Movements;
use App\Reports;

$catalogs = Catalogs::all();
$movements = Movements::all();
$reports = Reports::all();
$key = (string) ($_GET['catalog'] ?? '');
$catalog = $key !== '' ? Catalogs::get($key) : null;
$reportKey = (string) ($_GET['report'] ?? '');
$report = $reportKey !== '' ? Reports::get($reportKey) : null;
$movementKey = (string) ($_GET['movement'] ?? '');
$movement = $movementKey !== '' ? Movements::get($movementKey) : null;
$movementAction = $movement['action'] ?? null;
$invoiceView = (string) ($_GET['invoice'] ?? '');
$invoiceModule = in_array($invoiceView, ['list', 'create'], true);
$action = (string) ($_GET['action'] ?? 'list');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;

if ($invoiceModule && isset($_GET['data'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $data = match ((string) $_GET['data']) {
        'receivers' => invoice_repository()->receivers((int) ($_GET['client'] ?? 0)),
        'concepts' => invoice_repository()->concepts((int) ($_GET['company'] ?? 0)),
        default => throw new RuntimeException('Catálogo de facturación desconocido.'),
    };
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($report && ($_GET['export'] ?? '') === 'csv') {
    $exportResult = report_repository()->run($reportKey, $report, $_GET, 1, 25, true);
    $fileName = 'reporte-' . $reportKey . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, array_map(static fn (mixed $column): string => is_array($column) ? $column['label'] : $column, $report['columns']), ',', '"', '');
    foreach ($exportResult['rows'] as $row) {
        fputcsv($output, array_map(static fn (string $column): mixed => $row[$column] ?? '', array_keys($report['columns'])), ',', '"', '');
    }
    fclose($output);
    exit;
}

$expectsJson = false;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $postAction = (string) ($_POST['action'] ?? '');
        if ($invoiceModule) {
            if ($postAction !== 'invoice_create') {
                throw new RuntimeException('Acción de facturación no permitida.');
            }
            $invoiceId = invoice_repository()->create($_POST, current_operator_id());
            flash('success', 'Factura #' . $invoiceId . ' guardada con sus conceptos y relaciones. Quedó pendiente de timbrado.');
            redirect('?invoice=list');
        } elseif ($movement) {
            $paymentId = (int) ($_POST['payment_id'] ?? 0);
            $operatorId = current_operator_id();
            if ($postAction === 'movement_assignment_preview') {
                $expectsJson = true;
                $preview = movement_repository()->previewAssignments((array) ($_POST['assignments'] ?? []));
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => true, 'preview' => $preview], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                exit;
            } elseif ($postAction === 'movement_assign_batch') {
                $result = movement_repository()->assignBatch((array) ($_POST['assignments'] ?? []), $operatorId);
                flash('success', $result['movements'] . ' movimientos asignados. Se generaron ' . $result['commissions'] . ' movimientos de comisión.');
            } elseif ($postAction === 'movement_assign') {
                $result = movement_repository()->assign($paymentId, (int) ($_POST['client_id'] ?? 0), $operatorId);
                flash('success', 'Movimiento asignado. Se generaron ' . $result['commissions'] . ' movimientos de comisión.');
            } elseif ($postAction === 'movement_unassign') {
                $result = movement_repository()->unassign($paymentId, $operatorId);
                flash('success', 'Movimiento desasignado. Se eliminaron ' . $result['removedCommissions'] . ' movimientos de comisión.');
            } elseif ($postAction === 'movement_reassign') {
                $result = movement_repository()->reassign($paymentId, (int) ($_POST['client_id'] ?? 0), $operatorId);
                flash('success', 'Movimiento reasignado y comisiones recalculadas.');
            } elseif ($postAction === 'transfer_create') {
                $amount = (float) str_replace([',', '$', ' '], '', (string) ($_POST['amount'] ?? '0'));
                $result = movement_repository()->createTransfer(
                    (int) ($_POST['origin_id'] ?? 0), (int) ($_POST['destination_id'] ?? 0),
                    $amount, (string) ($_POST['detail'] ?? ''), $operatorId
                );
                flash('success', 'Traspaso creado: entrada #' . $result['entryId'] . ' y salida #' . $result['exitId'] . '.');
            } elseif ($postAction === 'transfer_delete') {
                movement_repository()->deleteTransfer($paymentId, $operatorId);
                flash('success', 'Se eliminaron la entrada y la salida del traspaso.');
            } else {
                throw new RuntimeException('Acción de movimiento no permitida.');
            }
            redirect('?movement=' . rawurlencode($movementKey));
        } elseif ($catalog) {
            if ($postAction === 'save') {
                $savedId = repository()->save($catalog, $_POST, filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null);
                flash('success', 'Registro guardado correctamente (ID ' . $savedId . ').');
            } elseif ($postAction === 'delete') {
                repository()->delete($catalog, (int) ($_POST['id'] ?? 0));
                flash('success', 'Registro eliminado correctamente.');
            } else {
                throw new RuntimeException('Acción no permitida.');
            }
            redirect('?catalog=' . rawurlencode($key));
        } else {
            throw new RuntimeException('Módulo desconocido.');
        }
    }
} catch (Throwable $exception) {
    if ($expectsJson) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    flash('error', $exception->getMessage());
    $target = $invoiceModule ? '?invoice=' . rawurlencode($invoiceView) : ($movement ? '?movement=' . rawurlencode($movementKey) : ($catalog ? '?catalog=' . rawurlencode($key) : './'));
    redirect($target);
}

$flash = take_flash();
$record = null;
if ($catalog && $action === 'form' && $id) {
    $record = repository()->find($catalog, $id);
    if (!$record) {
        flash('error', 'No se encontró el registro solicitado.');
        redirect('?catalog=' . rawurlencode($key));
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= e(app_url('/')) ?>">
    <title><?= e($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : 'Facturas') : ($catalog['label'] ?? $report['label'] ?? $movement['label'] ?? 'Sistema 14')) ?> · Sistema 14</title>
    <!-- theme.css contiene todos los tokens visuales; app.css define componentes. -->
    <link rel="stylesheet" href="assets/theme.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="./"><img src="assets/logo-s14.png" alt="Sistema 14"><strong>Sistema 14</strong></a>
    <nav class="main-nav">
        <a class="dashboard-link <?= !$catalog && !$report && !$movement && !$invoiceModule ? 'active' : '' ?>" href="./">
            <span class="nav-icon">⌂</span>
            Panel principal
        </a>
        <details class="catalog-menu" <?= $catalog ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon">▦</span> Catálogos</span>
                <span class="chevron">⌄</span>
            </summary>
            <div class="catalog-links">
                <?php foreach ($catalogs as $navKey => $item): ?>
                    <a class="<?= $key === $navKey ? 'active' : '' ?>" href="?catalog=<?= e($navKey) ?>">
                        <span></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
        <details class="catalog-menu movement-menu" <?= $movement ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon">⇄</span> Movimientos</span>
                <span class="chevron">⌄</span>
            </summary>
            <div class="catalog-links">
                <?php foreach ($movements as $navKey => $item): ?>
                    <a class="<?= $movementKey === $navKey ? 'active' : '' ?>" href="?movement=<?= e($navKey) ?>">
                        <span></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
        <details class="catalog-menu" <?= $invoiceModule ? 'open' : '' ?>>
            <summary><span><span class="nav-icon">▧</span> Facturación</span><span class="chevron">⌄</span></summary>
            <div class="catalog-links">
                <a class="<?= $invoiceView === 'list' ? 'active' : '' ?>" href="?invoice=list"><span></span>Facturas</a>
                <a class="<?= $invoiceView === 'create' ? 'active' : '' ?>" href="?invoice=create"><span></span>Crear factura</a>
            </div>
        </details>
        <details class="catalog-menu report-menu" <?= $report ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon">▤</span> Reportes</span>
                <span class="chevron">⌄</span>
            </summary>
            <div class="catalog-links">
                <?php foreach ($reports as $navKey => $item): ?>
                    <a class="<?= $reportKey === $navKey ? 'active' : '' ?>" href="?report=<?= e($navKey) ?>">
                        <span></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
    </nav>
    <p class="phase">Sistema 14 renovado · PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?><br>Acceso sin login</p>
</aside>

<main>
    <header class="topbar">
        <div>
            <p class="eyebrow"><?= $invoiceModule ? 'Facturación CFDI' : ($report ? 'Consulta y análisis' : ($movement ? 'Operación' : 'Administración')) ?></p>
            <h1><?= e($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : 'Facturas') : ($catalog['label'] ?? $report['label'] ?? ($movementAction === 'transfer' && $action === 'form' ? 'Nuevo traspaso' : ($movement['label'] ?? 'Panel principal')))) ?></h1>
        </div>
        <?php if ($catalog && $action !== 'form'): ?>
            <a class="button primary" href="?catalog=<?= e($key) ?>&amp;action=form">+ Nuevo registro</a>
        <?php elseif ($movementKey === 'traspasos' && $action !== 'form'): ?>
            <a class="button primary" href="?movement=traspasos&amp;action=form">+ Nuevo traspaso</a>
        <?php elseif ($invoiceView === 'list'): ?>
            <a class="button primary" href="?invoice=create">+ Nueva factura</a>
        <?php endif; ?>
    </header>

    <?php if ($flash): ?>
        <div class="toast-stack" aria-live="polite">
            <div class="glass-toast <?= e($flash['type']) ?>" data-auto-dismiss>
                <span class="toast-icon"><?= $flash['type'] === 'success' ? '✓' : '!' ?></span>
                <div><strong><?= $flash['type'] === 'success' ? 'Operación completada' : 'No se pudo completar' ?></strong><p><?= e($flash['message']) ?></p></div>
                <button type="button" class="toast-close" aria-label="Cerrar notificación">×</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($invoiceModule): ?>
        <?php if ($invoiceView === 'create'): ?>
            <?php $invoiceData = invoice_repository()->formData(); ?>
            <section class="report-intro"><p>Genera la factura usando los mismos catálogos de empresas, clientes, razones sociales, conceptos y claves fiscales del sistema anterior.</p><span class="operation-badge">Guardado transaccional</span></section>
            <form method="post" id="invoiceForm" data-confirm="¿Guardar esta factura y todos sus conceptos?" data-confirm-title="Confirmar factura">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_create">
                <section class="panel invoice-form-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Datos fiscales</p><h2>Emisor y receptor</h2></div><a class="button" href="?invoice=list">Volver</a></div>
                    <div class="form-grid invoice-header-grid">
                        <label class="field"><span>Empresa emisora *</span><select name="company_id" id="invoiceCompany" required><option value="">Seleccionar…</option><?php foreach ($invoiceData['companies'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['razon']) ?> · <?= e($item['rfc']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Cliente *</span><select name="client_id" id="invoiceClient" required><option value="">Seleccionar…</option><?php foreach ($invoiceData['clients'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['nombre']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Razón social *</span><select name="receiver_id" id="invoiceReceiver" required disabled><option value="">Selecciona primero un cliente…</option></select></label>
                        <label class="field"><span>Método de pago *</span><select name="method_id" required><?php foreach ($invoiceData['methods'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['clave']) ?> · <?= e($item['metodo_pago']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Forma de pago *</span><select name="form_id" required><option value="">Seleccionar…</option><?php foreach ($invoiceData['forms'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e(str_pad((string) $item['clave'], 2, '0', STR_PAD_LEFT)) ?> · <?= e($item['forma_pago']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Uso CFDI *</span><select name="use_id" required><option value="">Seleccionar…</option><?php foreach ($invoiceData['uses'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['cod_sat']) ?> · <?= e($item['uso_cfdi']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Moneda *</span><select name="currency_id" required><?php foreach ($invoiceData['currencies'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['clave_moneda']) ?> · <?= e($item['moneda']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Tipo de comprobante *</span><select name="voucher_type" required><option value="I">Ingreso</option><option value="E">Egreso</option><option value="T">Traslado</option><option value="N">Nómina</option><option value="P">Pago</option></select></label>
                        <label class="field"><span>Estatus de pago *</span><select name="status_payment"><option value="Pagado">Pagado</option><option value="No Pagado">No pagado</option></select></label>
                        <label class="field"><span>Retención 4%</span><select name="retention"><option value="No">No</option><option value="Si">Sí</option></select></label>
                        <label class="field"><span>Pago asociado</span><input type="number" name="payment_id" min="1" placeholder="ID opcional"></label>
                    </div>
                </section>
                <section class="panel invoice-concepts-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Partidas</p><h2>Conceptos de factura</h2></div><div class="invoice-add-concept"><select id="invoiceConcept" disabled><option value="">Selecciona primero una empresa…</option></select><button type="button" class="button" id="addInvoiceConcept">+ Agregar</button></div></div>
                    <div class="table-wrap"><table class="invoice-lines"><thead><tr><th>Concepto</th><th>Cantidad</th><th>P/U con IVA</th><th>IVA</th><th>Total</th><th></th></tr></thead><tbody id="invoiceLines"><tr class="invoice-empty"><td colspan="6" class="empty">Agrega al menos un concepto.</td></tr></tbody></table></div>
                    <div class="invoice-summary"><div><span>Subtotal</span><strong id="invoiceSubtotal">$0.00</strong></div><div><span>IVA</span><strong id="invoiceTax">$0.00</strong></div><div><span>Total</span><strong id="invoiceTotal">$0.00</strong></div><button type="submit" class="button primary">Guardar factura</button></div>
                </section>
            </form>
        <?php else: ?>
            <?php $invoicePage = max(1, (int) ($_GET['page'] ?? 1)); $invoiceSearch = trim((string) ($_GET['q'] ?? '')); $invoiceResult = invoice_repository()->page($invoiceSearch, $invoicePage); ?>
            <section class="report-intro"><p>Facturas guardadas en la tabla original, con emisor, receptor, relación de pago y estado fiscal.</p></section>
            <section class="panel"><div class="panel-heading list-tools"><div><p class="eyebrow">Facturación</p><h2>Facturas</h2><p class="panel-copy"><?= number_format(count($invoiceResult['rows'])) ?> resultados en esta página</p></div><form method="get" class="search"><input type="hidden" name="invoice" value="list"><input type="search" name="q" value="<?= e($invoiceSearch) ?>" placeholder="Folio, UUID, cliente…"><button class="button">Buscar</button></form></div>
                <div class="table-wrap"><table class="movement-table invoice-table"><thead><tr><th>ID / folio</th><th>Emisor</th><th>Receptor</th><th>Pago</th><th>Total</th><th>Estado fiscal</th></tr></thead><tbody><?php foreach ($invoiceResult['rows'] as $row): ?><tr>
                    <td><span class="movement-cell-main">#<?= e($row['id']) ?></span><span class="movement-cell-secondary"><?= e(($row['serie'] ?? '') . ($row['folio'] ?? '')) ?> · <?= e($row['fecha']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['empresa']) ?></span><span class="movement-cell-secondary"><?= e($row['usuario']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['cliente']) ?></span><span class="movement-cell-secondary"><?= e($row['rfc']) ?> · <?= e($row['receptor']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['metodo_pago']) ?></span><span class="movement-cell-secondary"><?= e($row['forma_pago']) ?> · <?= e($row['status_pago']) ?><?= $row['id_pago'] ? ' · Pago #' . e($row['id_pago']) : '' ?></span></td>
                    <td><?= e(report_value($row['total_iva'], 'money')) ?></td>
                    <td><span class="movement-cell-main"><?= e($row['status'] ?: (($row['uuid'] ?? '') ? 'Timbrada' : 'Pendiente')) ?></span><span class="movement-cell-secondary"><?= e($row['uuid'] ?: ($row['status_error'] ?: 'Sin UUID')) ?></span></td>
                </tr><?php endforeach; ?><?php if ($invoiceResult['rows'] === []): ?><tr><td colspan="6" class="empty">No hay facturas para mostrar.</td></tr><?php endif; ?></tbody></table></div>
                <div class="pagination"><span>Página <?= $invoiceResult['page'] ?><?= $invoiceResult['hasNext'] ? ' · Hay más resultados' : '' ?></span><div><?php if ($invoiceResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['invoice'=>'list','q'=>$invoiceSearch,'page'=>$invoiceResult['page']-1])) ?>">← Anterior</a><?php endif; ?><?php if ($invoiceResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query(['invoice'=>'list','q'=>$invoiceSearch,'page'=>$invoiceResult['page']+1])) ?>">Siguiente →</a><?php endif; ?></div></div>
            </section>
        <?php endif; ?>
    <?php elseif ($movement): ?>
        <?php
        $movementPage = max(1, (int) ($_GET['page'] ?? 1));
        $movementSearch = trim((string) ($_GET['q'] ?? ''));
        $movementClientId = filter_input(INPUT_GET, 'client', FILTER_VALIDATE_INT) ?: null;
        $movementClient = $movementClientId ? movement_repository()->client($movementClientId) : null;
        $isTransferForm = $movementAction === 'transfer' && $action === 'form';
        $movementResult = $isTransferForm ? null : movement_repository()->page($movementKey, $movement, $movementSearch, $movementPage, 25, $movementClient ? $movementClientId : null);
        $movementClients = in_array($movementAction, ['assign', 'reassign', 'transfer'], true) ? movement_repository()->clients() : [];
        ?>
        <section class="report-intro movement-intro">
            <p><?= e($movementClient ? 'Movimientos de la cartera ' . $movementClient['nombre'] . ' (#' . $movementClient['id'] . ').' : $movement['description']) ?></p>
            <?php if ($movementClient): ?><a class="button" href="?movement=<?= e($movementKey) ?>">Quitar filtro de cartera</a><?php endif; ?>
            <?php if (in_array($movementAction, ['assign', 'reassign', 'unassign', 'transfer'], true)): ?>
                <span class="operation-badge">Operación transaccional y auditada</span>
            <?php endif; ?>
        </section>

        <?php if ($isTransferForm): ?>
            <section class="panel transfer-form-panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">Nuevo movimiento</p><h2>Registrar traspaso</h2></div>
                    <a class="button" href="?movement=traspasos">Volver al listado</a>
                </div>
                <form method="post" class="form-grid" data-confirm="¿Registrar este traspaso entre carteras?" data-confirm-title="Confirmar traspaso">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="transfer_create">
                    <label class="field"><span>Cartera origen *</span><select name="origin_id" required><option value="">Seleccionar…</option><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?> (#<?= e($client['id']) ?>)</option><?php endforeach; ?></select></label>
                    <label class="field"><span>Cartera destino *</span><select name="destination_id" required><option value="">Seleccionar…</option><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?> (#<?= e($client['id']) ?>)</option><?php endforeach; ?></select></label>
                    <label class="field"><span>Monto *</span><input type="number" name="amount" min="0.01" step="0.01" required></label>
                    <label class="field wide"><span>Detalle *</span><input type="text" name="detail" maxlength="500" required></label>
                    <div class="form-actions wide"><button class="button primary" type="submit">Registrar entrada y salida</button></div>
                </form>
            </section>
        <?php else: ?>
        <section class="panel movement-panel">
            <?php if ($movementAction === 'assign'): ?>
                <form method="post" id="assignmentBatchForm" data-assignment-batch>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="movement_assign_batch">
                </form>
            <?php endif; ?>
            <div class="panel-heading list-tools">
                <div><p class="eyebrow">Movimientos</p><h2><?= number_format(count($movementResult['rows'])) ?> movimientos</h2><p class="panel-copy">Resultados de la página actual</p></div>
                <form method="get" class="search">
                    <input type="hidden" name="movement" value="<?= e($movementKey) ?>">
                    <?php if ($movementClient): ?><input type="hidden" name="client" value="<?= e($movementClient['id']) ?>"><?php endif; ?>
                    <input type="search" name="q" value="<?= e($movementSearch) ?>" placeholder="Buscar movimientos…">
                    <button class="button" type="submit">Buscar</button>
                </form>
            </div>
            <?php if (in_array($movementAction, ['assign', 'reassign'], true)): ?>
                <template id="movementClientOptions"><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?></option><?php endforeach; ?></template>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="movement-table<?= $movementAction ? ' has-actions' : '' ?>">
                    <thead><tr><?php foreach ($movement['columns'] as $column): ?><th><?= e(is_array($column) ? $column['label'] : $column) ?></th><?php endforeach; ?><?php if ($movementAction): ?><th><?= $movementAction === 'assign' ? 'Cartera destino' : 'Acción' ?></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($movementResult['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($movement['columns'] as $columnKey => $column): ?>
                                <?php
                                $columnConfig = is_array($column) ? $column : [];
                                $format = $columnConfig['format'] ?? 'text';
                                $secondaryColumns = (array) ($columnConfig['secondary'] ?? []);
                                $secondaryFormats = (array) ($columnConfig['secondaryFormat'] ?? []);
                                $titleParts = [report_value($row[$columnKey] ?? null, $format)];
                                foreach ($secondaryColumns as $secondaryKey => $secondaryLabel) {
                                    if (is_int($secondaryKey)) {
                                        $secondaryKey = (string) $secondaryLabel;
                                        $secondaryLabel = '';
                                    }
                                    if (($row[$secondaryKey] ?? null) !== null && ($row[$secondaryKey] ?? '') !== '') {
                                        $secondaryValue = report_value($row[$secondaryKey], $secondaryFormats[$secondaryKey] ?? 'text');
                                        $titleParts[] = ($secondaryLabel !== '' ? $secondaryLabel . ': ' : '') . $secondaryValue;
                                    }
                                }
                                ?>
                                <td title="<?= e(implode(' · ', $titleParts)) ?>">
                                    <span class="movement-cell-main">
                                    <?php if ($columnKey === 'cep' && filter_var($row[$columnKey] ?? '', FILTER_VALIDATE_URL)): ?>
                                        <a href="<?= e($row[$columnKey]) ?>" target="_blank" rel="noopener">Abrir comprobante</a>
                                    <?php else: ?>
                                        <?= e(report_value($row[$columnKey] ?? null, $format)) ?>
                                    <?php endif; ?>
                                    </span>
                                    <?php foreach ($secondaryColumns as $secondaryKey => $secondaryLabel): ?>
                                        <?php
                                        if (is_int($secondaryKey)) {
                                            $secondaryKey = (string) $secondaryLabel;
                                            $secondaryLabel = '';
                                        }
                                        $secondaryRaw = $row[$secondaryKey] ?? null;
                                        if ($secondaryRaw === null || $secondaryRaw === '') {
                                            continue;
                                        }
                                        ?>
                                        <span class="movement-cell-secondary">
                                            <?php if ($secondaryLabel !== ''): ?><b><?= e($secondaryLabel) ?>:</b><?php endif; ?>
                                            <?= e(report_value($secondaryRaw, $secondaryFormats[$secondaryKey] ?? 'text')) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if ($movementAction): ?>
                                <td class="movement-action">
                                    <?php if ($movementAction === 'assign'): ?>
                                        <select name="assignments[<?= e($row['id']) ?>]" form="assignmentBatchForm" aria-label="Cartera para movimiento <?= e($row['id']) ?>" data-client-picker>
                                            <option value="">Seleccionar cartera…</option>
                                        </select>
                                    <?php elseif ($movementAction === 'reassign'): ?>
                                        <form method="post" class="inline-operation" data-confirm="¿Confirmar esta reasignación y su cálculo de comisiones?" data-confirm-title="Confirmar reasignación">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="movement_reassign">
                                            <input type="hidden" name="payment_id" value="<?= e($row['id']) ?>">
                                            <select name="client_id" aria-label="Cartera" required data-client-picker><option value="">Cartera…</option></select>
                                            <button class="button primary" type="submit">Reasignar</button>
                                        </form>
                                    <?php elseif ($movementAction === 'unassign'): ?>
                                        <form method="post" class="inline-operation single-action" data-confirm="¿Desasignar este movimiento y eliminar todas sus comisiones relacionadas?" data-confirm-title="Confirmar desasignación">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="movement_unassign"><input type="hidden" name="payment_id" value="<?= e($row['id']) ?>">
                                            <button class="button danger" type="submit">Desasignar</button>
                                        </form>
                                    <?php elseif ($movementAction === 'transfer'): ?>
                                        <form method="post" class="inline-operation single-action" data-confirm="¿Eliminar de forma permanente la entrada y la salida de este traspaso?" data-confirm-title="Eliminar traspaso">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="transfer_delete"><input type="hidden" name="payment_id" value="<?= e($row['id']) ?>">
                                            <button class="button danger" type="submit">Eliminar par</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($movementResult['rows'] === []): ?><tr><td colspan="<?= count($movement['columns']) + ($movementAction ? 1 : 0) ?>" class="empty">No hay movimientos para mostrar.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($movementAction === 'assign' && $movementResult['rows'] !== []): ?>
                <div class="batch-assignment-bar">
                    <div><strong>Asignación por lote</strong><span>Selecciona una o más carteras. Los movimientos sin selección permanecerán sin asignar.</span></div>
                    <button class="button primary" type="submit" form="assignmentBatchForm">Guardar asignaciones seleccionadas</button>
                </div>
            <?php endif; ?>
            <div class="pagination">
                <span>Página <?= $movementResult['page'] ?><?= $movementResult['hasNext'] ? ' · Hay más resultados' : '' ?></span>
                <div>
                    <?php if ($movementResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['movement' => $movementKey, 'client' => $movementClient['id'] ?? null, 'q' => $movementSearch, 'page' => $movementResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?>
                    <?php if ($movementResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query(['movement' => $movementKey, 'client' => $movementClient['id'] ?? null, 'q' => $movementSearch, 'page' => $movementResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php elseif ($report): ?>
        <?php
        $reportPage = max(1, (int) ($_GET['page'] ?? 1));
        $reportResult = report_repository()->run($reportKey, $report, $_GET, $reportPage);
        $queryValues = ['report' => $reportKey, 'q' => (string) ($_GET['q'] ?? '')];
        if ($report['dateFilter'] ?? false) {
            $queryValues['from'] = (string) ($_GET['from'] ?? date('Y-m-01'));
            $queryValues['to'] = (string) ($_GET['to'] ?? date('Y-m-d'));
        }
        ?>
        <section class="report-intro">
            <p><?= e($report['description']) ?></p>
            <a class="button" href="?<?= e(http_build_query($queryValues + ['export' => 'csv'])) ?>">Exportar CSV</a>
        </section>
        <section class="panel report-panel">
            <div class="panel-heading list-tools">
                <div><p class="eyebrow">Resultados</p><h2><?= number_format(count($reportResult['rows'])) ?> registros</h2><p class="panel-copy">Resultados de la página actual</p></div>
                <form method="get" class="report-filters">
                    <input type="hidden" name="report" value="<?= e($reportKey) ?>">
                    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar en el reporte…">
                    <?php if ($report['dateFilter'] ?? false): ?>
                        <label><span>Desde</span><input type="date" name="from" value="<?= e($queryValues['from']) ?>"></label>
                        <label><span>Hasta</span><input type="date" name="to" value="<?= e($queryValues['to']) ?>"></label>
                    <?php endif; ?>
                    <button class="button primary" type="submit">Aplicar filtros</button>
                </form>
            </div>
            <div class="table-wrap">
                <table class="report-table">
                    <thead><tr><?php foreach ($report['columns'] as $column): ?><th><?= e(is_array($column) ? $column['label'] : $column) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($reportResult['rows'] as $row): ?>
                        <tr class="<?= ($row['estado'] ?? '') === 'Fuera de rango' ? 'row-warning' : '' ?>">
                            <?php foreach ($report['columns'] as $columnKey => $column): ?>
                                <?php $format = is_array($column) ? ($column['format'] ?? 'text') : 'text'; ?>
                                <td><?= e(report_value($row[$columnKey] ?? null, $format)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($reportResult['rows'] === []): ?><tr><td colspan="<?= count($report['columns']) ?>" class="empty">No hay resultados para los filtros seleccionados.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <span>Página <?= $reportResult['page'] ?><?= $reportResult['hasNext'] ? ' · Hay más resultados' : '' ?></span>
                <div>
                    <?php if ($reportResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query($queryValues + ['page' => $reportResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?>
                    <?php if ($reportResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query($queryValues + ['page' => $reportResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?>
                </div>
            </div>
        </section>
    <?php elseif (!$catalog): ?>
        <?php
        $dashboardSearch = trim((string) ($_GET['q'] ?? ''));
        $dashboardPage = max(1, (int) ($_GET['page'] ?? 1));
        $dashboardOverview = dashboard_overview(current_operator_id());
        $dashboardWallets = dashboard_repository()->wallets($dashboardSearch, $dashboardPage, current_operator_id());
        ?>
        <section class="dashboard-summary">
            <article><span>Carteras visibles</span><strong><?= number_format($dashboardOverview['wallets']) ?></strong><small>Clientes activos</small></article>
            <article><span>Saldo de carteras</span><strong><?= e(report_value($dashboardOverview['walletBalance'], 'money')) ?></strong><small>Saldo calculado más reciente</small></article>
            <article><span>Saldos internos</span><strong><?= e(report_value($dashboardOverview['internalBalance'], 'money')) ?></strong><small>Cartera interna #13</small></article>
            <article><span>Movimientos pendientes</span><strong><?= e(report_value($dashboardOverview['pendingBalance'], 'money')) ?></strong><small><?= number_format($dashboardOverview['pendingCount']) ?> movimientos</small></article>
            <article class="<?= abs($dashboardOverview['balanceDifference']) > 0.01 ? 'is-warning' : '' ?>"><span>Diferencia de saldos</span><strong><?= e(report_value($dashboardOverview['balanceDifference'], 'money')) ?></strong><small>Estados de cuenta contra pagos</small></article>
        </section>

        <section class="panel wallet-panel">
            <div class="panel-heading list-tools">
                <div><p class="eyebrow">Panel anterior renovado</p><h2>Carteras de clientes</h2><p class="panel-copy">Saldos, movimientos, cheques y facturas pendientes con la información calculada por el sistema.</p></div>
                <form method="get" class="search"><input type="search" name="q" value="<?= e($dashboardSearch) ?>" placeholder="Buscar cartera o clave…"><button class="button">Buscar</button></form>
            </div>
            <div class="wallet-grid">
                <?php foreach ($dashboardWallets['rows'] as $wallet): ?>
                    <?php $walletColor = preg_match('/^[0-9a-fA-F]{6}$/', ltrim((string) $wallet['color'], '#')) ? '#' . ltrim((string) $wallet['color'], '#') : 'var(--color-primary)'; ?>
                    <article class="wallet-card <?= (float) $wallet['saldo'] < -0.005 ? 'is-negative' : '' ?>" style="--wallet-accent: <?= e($walletColor) ?>">
                        <header><div class="wallet-identity"><span class="wallet-avatar"><?= e(mb_strtoupper(mb_substr((string) $wallet['nombre'], 0, 1))) ?></span><div><h3><?= e($wallet['nombre']) ?></h3><small><?= e($wallet['clave_corta'] ?: 'Sin clave corta') ?> · #<?= e($wallet['id']) ?></small></div></div><span class="wallet-state"><?= (float) $wallet['saldo'] < -0.005 ? 'Saldo negativo' : 'Disponible' ?></span></header>
                        <div class="wallet-balance"><span>Saldo actual</span><strong><?= e(report_value($wallet['saldo'], 'money')) ?></strong></div>
                        <dl>
                            <div><dt>Mov. pendientes</dt><dd><?= number_format((int) $wallet['movimientos_pend']) ?> · <?= e(report_value($wallet['dinero_sin_fact'], 'money')) ?></dd></div>
                            <div><dt>Cheques pendientes</dt><dd><?= number_format((int) $wallet['cheques_pend']) ?> · <?= e(report_value($wallet['cheques_pend_dinero'], 'money')) ?></dd></div>
                            <div><dt>Facturas sin pago</dt><dd><?= number_format((int) $wallet['facturas_sin_pago']) ?></dd></div>
                        </dl>
                        <footer><small>Actualizado <?= e($wallet['ultima_act'] ?: 'sin fecha') ?></small><a href="?movement=cartera&client=<?= e($wallet['id']) ?>">Ver movimientos →</a></footer>
                    </article>
                <?php endforeach; ?>
                <?php if ($dashboardWallets['rows'] === []): ?><p class="empty wallet-empty">No se encontraron carteras.</p><?php endif; ?>
            </div>
            <div class="pagination"><span>Página <?= $dashboardWallets['page'] ?> de <?= $dashboardWallets['lastPage'] ?> · <?= number_format($dashboardWallets['total']) ?> carteras</span><div><?php if ($dashboardWallets['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['q'=>$dashboardSearch,'page'=>$dashboardWallets['page']-1])) ?>">← Anterior</a><?php endif; ?><?php if ($dashboardWallets['page'] < $dashboardWallets['lastPage']): ?><a class="button" href="?<?= e(http_build_query(['q'=>$dashboardSearch,'page'=>$dashboardWallets['page']+1])) ?>">Siguiente →</a><?php endif; ?></div></div>
        </section>
    <?php elseif ($action === 'form'): ?>
        <section class="panel form-panel">
            <div class="panel-heading">
                <div><p class="eyebrow"><?= $record ? 'Edición' : 'Alta' ?></p><h2><?= $record ? e($record[$catalog['title']] ?? ('Registro ' . $id)) : 'Nuevo registro' ?></h2></div>
                <a class="button" href="?catalog=<?= e($key) ?>">Cancelar</a>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e($record['id'] ?? '') ?>">
                <?php foreach ($catalog['fields'] as $name => $field): ?>
                    <?php
                    $value = ($field['writeOnly'] ?? false) ? '' : ($record[$name] ?? '');
                    $required = ($field['required'] ?? false) && !($record && ($field['writeOnly'] ?? false));
                    $wide = ($field['type'] ?? '') === 'textarea';
                    ?>
                    <label class="field <?= $wide ? 'wide' : '' ?>">
                        <span><?= e($field['label']) ?><?= $required ? ' *' : '' ?></span>
                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea name="<?= e($name) ?>" rows="4" <?= $required ? 'required' : '' ?>><?= e($value) ?></textarea>
                        <?php elseif ($field['type'] === 'select'): ?>
                            <select name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                                <option value="">Seleccionar…</option>
                                <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                    <option value="<?= e($optionValue) ?>" <?= (string) $value === (string) $optionValue ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($field['type'] === 'relation'): ?>
                            <select name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                                <option value="">Seleccionar…</option>
                                <?php foreach (repository()->options($field['relation']) as $option): ?>
                                    <option value="<?= e($option['option_value']) ?>" <?= (string) $value === (string) $option['option_value'] ? 'selected' : '' ?>><?= e($option['option_label']) ?> (#<?= e($option['option_value']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="<?= e($field['type']) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
                                <?= $required ? 'required' : '' ?>
                                <?= isset($field['maxlength']) && $field['maxlength'] ? 'maxlength="' . e($field['maxlength']) . '"' : '' ?>
                                <?= isset($field['step']) ? 'step="' . e($field['step']) . '"' : '' ?>
                                <?= isset($field['min']) && $field['min'] !== null ? 'min="' . e($field['min']) . '"' : '' ?>
                                <?= isset($field['max']) && $field['max'] !== null ? 'max="' . e($field['max']) . '"' : '' ?>
                                <?= ($field['writeOnly'] ?? false) && $record ? 'placeholder="Dejar vacío para conservar"' : '' ?>>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <div class="form-actions wide"><a class="button" href="?catalog=<?= e($key) ?>">Cancelar</a><button class="button primary" type="submit">Guardar registro</button></div>
            </form>
        </section>
    <?php else: ?>
        <?php
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = repository()->page($catalog, $search, $page);
        ?>
        <section class="panel">
            <div class="panel-heading list-tools">
                <div><p class="eyebrow">Registros</p><h2><?= number_format(count($result['rows'])) ?> resultados</h2><p class="panel-copy">Resultados de la página actual</p></div>
                <form method="get" class="search">
                    <input type="hidden" name="catalog" value="<?= e($key) ?>">
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar en este catálogo…">
                    <button class="button" type="submit">Buscar</button>
                </form>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><?php foreach ($catalog['list'] as $column): ?><th><?= e(ucwords(str_replace('_', ' ', $column))) ?></th><?php endforeach; ?><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($catalog['list'] as $column): ?><td><?= e($row[$column] ?? '') ?></td><?php endforeach; ?>
                            <td class="actions">
                                <a href="?catalog=<?= e($key) ?>&amp;action=form&amp;id=<?= e($row['id']) ?>">Editar</a>
                                <form method="post" data-confirm="¿Eliminar definitivamente este registro?" data-confirm-title="Eliminar registro">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <button type="submit">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($result['rows'] === []): ?><tr><td colspan="<?= count($catalog['list']) + 1 ?>" class="empty">No hay resultados para mostrar.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <span>Página <?= $result['page'] ?><?= $result['hasNext'] ? ' · Hay más resultados' : '' ?></span>
                <div>
                    <?php if ($result['page'] > 1): ?><a class="button" href="?catalog=<?= e($key) ?>&amp;q=<?= e(rawurlencode($search)) ?>&amp;page=<?= $result['page'] - 1 ?>">← Anterior</a><?php endif; ?>
                    <?php if ($result['hasNext']): ?><a class="button" href="?catalog=<?= e($key) ?>&amp;q=<?= e(rawurlencode($search)) ?>&amp;page=<?= $result['page'] + 1 ?>">Siguiente →</a><?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<div class="glass-modal-backdrop" id="confirmationModal" hidden>
    <section class="glass-modal" role="dialog" aria-modal="true" aria-labelledby="confirmationTitle" aria-describedby="confirmationMessage">
        <div class="glass-modal-icon">?</div>
        <div class="glass-modal-copy">
            <p class="eyebrow">Confirmación requerida</p>
            <h2 id="confirmationTitle">Confirmar operación</h2>
            <p id="confirmationMessage"></p>
        </div>
        <div class="commission-preview" id="confirmationDetails" hidden></div>
        <div class="glass-modal-actions">
            <button type="button" class="button" id="confirmationCancel">Cancelar</button>
            <button type="button" class="button primary" id="confirmationAccept">Confirmar</button>
        </div>
    </section>
</div>

<script>
(() => {
    document.querySelectorAll('.toast-close').forEach((button) => {
        button.addEventListener('click', () => button.closest('.glass-toast')?.remove());
    });

    document.querySelectorAll('[data-auto-dismiss]').forEach((toast) => {
        window.setTimeout(() => {
            toast.classList.add('is-leaving');
            window.setTimeout(() => toast.remove(), 240);
        }, 5200);
    });

    const invoiceCompany = document.getElementById('invoiceCompany');
    const invoiceClient = document.getElementById('invoiceClient');
    const invoiceReceiver = document.getElementById('invoiceReceiver');
    const invoiceConcept = document.getElementById('invoiceConcept');
    const invoiceLines = document.getElementById('invoiceLines');
    const addInvoiceConcept = document.getElementById('addInvoiceConcept');
    const invoiceMoney = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'});

    const movementClientOptions = document.getElementById('movementClientOptions');
    if (movementClientOptions) {
        document.querySelectorAll('select[data-client-picker]').forEach((select) => {
            select.appendChild(movementClientOptions.content.cloneNode(true));
        });
    }

    const recalculateInvoice = () => {
        if (!invoiceLines) return;
        let subtotal = 0;
        let taxTotal = 0;
        invoiceLines.querySelectorAll('tr[data-invoice-line]').forEach((row) => {
            const quantity = Number(row.querySelector('[name="quantity[]"]').value) || 0;
            const grossUnit = Number(row.querySelector('[name="unit_price[]"]').value) || 0;
            const taxRate = Number(row.querySelector('[name="tax_rate[]"]').value) || 0;
            const gross = quantity * grossUnit;
            const net = taxRate > 0 ? gross / (1 + taxRate / 100) : gross;
            subtotal += net;
            taxTotal += gross - net;
            row.querySelector('[data-line-total]').textContent = invoiceMoney.format(gross);
        });
        document.getElementById('invoiceSubtotal').textContent = invoiceMoney.format(subtotal);
        document.getElementById('invoiceTax').textContent = invoiceMoney.format(taxTotal);
        document.getElementById('invoiceTotal').textContent = invoiceMoney.format(subtotal + taxTotal);
    };

    const loadInvoiceCatalog = async (type, id, select) => {
        select.disabled = true;
        select.innerHTML = '<option value="">Cargando…</option>';
        if (!id) {
            select.innerHTML = `<option value="">Selecciona primero ${type === 'receivers' ? 'un cliente' : 'una empresa'}…</option>`;
            return;
        }
        try {
            const parameter = type === 'receivers' ? 'client' : 'company';
            const response = await fetch(`?invoice=create&data=${type}&${parameter}=${encodeURIComponent(id)}`, {headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error('No fue posible cargar el catálogo.');
            select.innerHTML = '<option value="">Seleccionar…</option>';
            result.data.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.id;
                if (type === 'receivers') {
                    option.textContent = `${item.rfc} · ${item.razon_social} · ${item.clave_corta}`;
                } else {
                    option.textContent = `${item.clave_sat} · ${item.concepto}`;
                    option.dataset.description = item.concepto;
                    option.dataset.code = item.clave_sat;
                    option.dataset.unit = item.clave_unidad_medida;
                    option.dataset.price = item.precio_min;
                    option.dataset.maxPrice = item.precio_max;
                    option.dataset.maxUnits = item.unidades_max;
                    option.dataset.tax = item.impuesto;
                    option.dataset.applies = item.aplica_iva;
                }
                select.appendChild(option);
            });
            select.disabled = result.data.length === 0;
            if (result.data.length === 0) select.options[0].textContent = 'No hay opciones disponibles';
        } catch (error) {
            select.innerHTML = '<option value="">Error al cargar</option>';
            showToast(error instanceof Error ? error.message : 'No fue posible cargar el catálogo.');
        }
    };

    invoiceClient?.addEventListener('change', () => loadInvoiceCatalog('receivers', invoiceClient.value, invoiceReceiver));
    invoiceCompany?.addEventListener('change', () => {
        loadInvoiceCatalog('concepts', invoiceCompany.value, invoiceConcept);
        invoiceLines.querySelectorAll('tr[data-invoice-line]').forEach((row) => row.remove());
        if (!invoiceLines.querySelector('.invoice-empty')) {
            const empty = document.createElement('tr');
            empty.className = 'invoice-empty';
            empty.innerHTML = '<td colspan="6" class="empty">Agrega al menos un concepto.</td>';
            invoiceLines.appendChild(empty);
        }
        recalculateInvoice();
    });

    addInvoiceConcept?.addEventListener('click', () => {
        const option = invoiceConcept.options[invoiceConcept.selectedIndex];
        if (!option || !option.value) {
            showToast('Selecciona un concepto disponible para la empresa.', 'error');
            return;
        }
        invoiceLines.querySelector('.invoice-empty')?.remove();
        const row = document.createElement('tr');
        row.dataset.invoiceLine = 'true';

        const conceptCell = document.createElement('td');
        const conceptId = document.createElement('input');
        conceptId.type = 'hidden'; conceptId.name = 'concept_id[]'; conceptId.value = option.value;
        const conceptName = document.createElement('strong');
        conceptName.textContent = `${option.dataset.code} · ${option.dataset.unit}`;
        const description = document.createElement('textarea');
        description.name = 'description[]'; description.rows = 2; description.required = true; description.value = option.dataset.description || '';
        conceptCell.append(conceptId, conceptName, description);

        const quantityCell = document.createElement('td');
        const quantity = document.createElement('input');
        quantity.type = 'number'; quantity.name = 'quantity[]'; quantity.min = '0.000001'; quantity.step = '0.000001'; quantity.value = '1'; quantity.required = true;
        if (Number(option.dataset.maxUnits) > 0) quantity.max = option.dataset.maxUnits;
        quantityCell.appendChild(quantity);

        const priceCell = document.createElement('td');
        const price = document.createElement('input');
        price.type = 'number'; price.name = 'unit_price[]'; price.min = '0'; price.step = '0.000001'; price.value = option.dataset.price || '0'; price.required = true;
        if (Number(option.dataset.price) > 0) price.min = option.dataset.price;
        if (Number(option.dataset.maxPrice) > 0) price.max = option.dataset.maxPrice;
        priceCell.appendChild(price);

        const taxCell = document.createElement('td');
        const tax = document.createElement('select');
        tax.name = 'tax_rate[]';
        [['16', '16%'], ['0', '0%']].forEach(([value, label]) => { const item = document.createElement('option'); item.value = value; item.textContent = label; tax.appendChild(item); });
        tax.value = String(option.dataset.applies).toLowerCase() === 'no' ? '0' : (option.dataset.tax || '16');
        taxCell.appendChild(tax);

        const totalCell = document.createElement('td');
        const total = document.createElement('strong'); total.dataset.lineTotal = 'true'; totalCell.appendChild(total);
        const actionCell = document.createElement('td');
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'button danger invoice-remove-line'; remove.textContent = '×';
        remove.addEventListener('click', () => { row.remove(); if (!invoiceLines.querySelector('tr[data-invoice-line]')) { invoiceLines.innerHTML = '<tr class="invoice-empty"><td colspan="6" class="empty">Agrega al menos un concepto.</td></tr>'; } recalculateInvoice(); });
        actionCell.appendChild(remove);
        row.append(conceptCell, quantityCell, priceCell, taxCell, totalCell, actionCell);
        invoiceLines.appendChild(row);
        [quantity, price, tax].forEach((control) => control.addEventListener('input', recalculateInvoice));
        recalculateInvoice();
    });

    const backdrop = document.getElementById('confirmationModal');
    const title = document.getElementById('confirmationTitle');
    const message = document.getElementById('confirmationMessage');
    const accept = document.getElementById('confirmationAccept');
    const cancel = document.getElementById('confirmationCancel');
    const details = document.getElementById('confirmationDetails');
    const modal = backdrop.querySelector('.glass-modal');
    let pendingForm = null;

    const money = new Intl.NumberFormat('es-MX', {
        style: 'currency', currency: 'MXN', minimumFractionDigits: 2, maximumFractionDigits: 6
    });

    const showToast = (text, type = 'error') => {
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        const toast = document.createElement('div');
        toast.className = `glass-toast ${type}`;
        const icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.textContent = '!';
        const copy = document.createElement('div');
        const heading = document.createElement('strong');
        heading.textContent = type === 'success' ? 'Operación completada' : 'No se pudo completar la operación';
        const paragraph = document.createElement('p');
        paragraph.textContent = text;
        copy.append(heading, paragraph);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Cerrar notificación');
        close.textContent = '×';
        close.addEventListener('click', () => toast.remove());
        toast.append(icon, copy, close);
        stack.appendChild(toast);
        window.setTimeout(() => toast.remove(), 6500);
    };

    const openModal = () => {
        backdrop.hidden = false;
        window.requestAnimationFrame(() => backdrop.classList.add('is-visible'));
        accept.focus();
    };

    const closeModal = () => {
        backdrop.classList.remove('is-visible');
        window.setTimeout(() => { backdrop.hidden = true; }, 160);
    };

    const clearDetails = () => {
        details.replaceChildren();
        details.hidden = true;
        modal.classList.remove('has-commission-preview');
        accept.textContent = 'Confirmar';
    };

    const renderCommissionPreview = (preview) => {
        details.replaceChildren();
        details.hidden = false;
        modal.classList.add('has-commission-preview');

        const totals = document.createElement('div');
        totals.className = 'commission-preview-totals';
        [
            ['Movimientos', preview.movementCount],
            ['Registros de comisión', preview.commissionCount],
            ['Comisión total', money.format(preview.commissionTotal)]
        ].forEach(([label, value]) => {
            const item = document.createElement('div');
            const small = document.createElement('span');
            const strong = document.createElement('strong');
            small.textContent = label;
            strong.textContent = value;
            item.append(small, strong);
            totals.appendChild(item);
        });
        details.appendChild(totals);

        preview.rows.forEach((row) => {
            const card = document.createElement('article');
            card.className = 'commission-preview-card';
            const heading = document.createElement('div');
            heading.className = 'commission-preview-heading';
            const name = document.createElement('strong');
            name.textContent = `Movimiento #${row.paymentId} → ${row.clientName}`;
            const amount = document.createElement('span');
            amount.textContent = `${row.movementType} · ${money.format(row.amount)}`;
            heading.append(name, amount);
            card.appendChild(heading);

            const credit = document.createElement('p');
            credit.textContent = `Acreditable a la cartera: ${money.format(row.mainCredit)} · Esquema: ${row.scheme}`;
            card.appendChild(credit);

            const list = document.createElement('ul');
            if (row.commissions.length === 0) {
                const item = document.createElement('li');
                item.textContent = 'Este movimiento no genera comisiones.';
                list.appendChild(item);
            } else {
                row.commissions.forEach((commission) => {
                    const item = document.createElement('li');
                    const label = document.createElement('span');
                    const value = document.createElement('strong');
                    label.textContent = `${commission.type} · ${commission.recipientName} (${commission.rate}%)`;
                    value.textContent = money.format(commission.amount);
                    item.append(label, value);
                    list.appendChild(item);
                });
            }
            card.appendChild(list);
            details.appendChild(card);
        });

        if (preview.distributed.length > 0) {
            const heading = document.createElement('h3');
            heading.textContent = 'Total repartido por cartera';
            const list = document.createElement('ul');
            list.className = 'commission-distribution';
            preview.distributed.forEach((recipient) => {
                const item = document.createElement('li');
                const label = document.createElement('span');
                const value = document.createElement('strong');
                label.textContent = recipient.recipientName;
                value.textContent = money.format(recipient.amount);
                item.append(label, value);
                list.appendChild(item);
            });
            details.append(heading, list);
        }
    };

    const renderInvoicePreview = (form) => {
        details.replaceChildren();
        details.hidden = false;
        modal.classList.add('has-commission-preview');

        const totals = document.createElement('div');
        totals.className = 'commission-preview-totals';
        const rows = Array.from(form.querySelectorAll('tr[data-invoice-line]'));
        let subtotal = 0;
        let taxTotal = 0;
        let total = 0;
        rows.forEach((row) => {
            const quantity = Number(row.querySelector('[name="quantity[]"]').value) || 0;
            const unitPrice = Number(row.querySelector('[name="unit_price[]"]').value) || 0;
            const taxRate = Number(row.querySelector('[name="tax_rate[]"]').value) || 0;
            const gross = quantity * unitPrice;
            const net = taxRate > 0 ? gross / (1 + taxRate / 100) : gross;
            subtotal += net;
            taxTotal += gross - net;
            total += gross;
        });
        [['Conceptos', rows.length], ['Subtotal', invoiceMoney.format(subtotal)], ['IVA', invoiceMoney.format(taxTotal)], ['Total', invoiceMoney.format(total)]].forEach(([label, value]) => {
            const item = document.createElement('div');
            const small = document.createElement('span');
            const strong = document.createElement('strong');
            small.textContent = label;
            strong.textContent = value;
            item.append(small, strong);
            totals.appendChild(item);
        });
        details.appendChild(totals);

        rows.forEach((row) => {
            const quantity = Number(row.querySelector('[name="quantity[]"]').value) || 0;
            const unitPrice = Number(row.querySelector('[name="unit_price[]"]').value) || 0;
            const taxRate = Number(row.querySelector('[name="tax_rate[]"]').value) || 0;
            const card = document.createElement('article');
            card.className = 'commission-preview-card';
            const heading = document.createElement('div');
            heading.className = 'commission-preview-heading';
            const name = document.createElement('strong');
            name.textContent = row.querySelector('[name="description[]"]').value;
            const amount = document.createElement('span');
            amount.textContent = invoiceMoney.format(quantity * unitPrice);
            heading.append(name, amount);
            const copy = document.createElement('p');
            copy.textContent = `${quantity} × ${invoiceMoney.format(unitPrice)} · IVA ${taxRate}%`;
            card.append(heading, copy);
            details.appendChild(card);
        });
    };

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            pendingForm = form;
            clearDetails();
            title.textContent = form.dataset.confirmTitle || 'Confirmar operación';
            message.textContent = form.dataset.confirm || '¿Deseas continuar?';
            if (form.id === 'invoiceForm') {
                renderInvoicePreview(form);
                accept.textContent = 'Aceptar y guardar';
            }
            openModal();
        });
    });

    document.querySelectorAll('form[data-assignment-batch]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            const submitter = event.submitter;
            if (submitter) {
                submitter.disabled = true;
                submitter.textContent = 'Calculando comisiones…';
            }
            try {
                const payload = new FormData(form);
                payload.set('action', 'movement_assignment_preview');
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST', body: payload, headers: {'Accept': 'application/json'}
                });
                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'No fue posible calcular las comisiones.');
                }
                pendingForm = form;
                title.textContent = 'Revisar asignación por lote';
                message.textContent = 'Verifica el reparto. Al aceptar se asignarán únicamente los movimientos seleccionados; los demás permanecerán sin cambios.';
                renderCommissionPreview(result.preview);
                accept.textContent = 'Aceptar y asignar';
                openModal();
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'No fue posible calcular las comisiones.');
            } finally {
                if (submitter) {
                    submitter.disabled = false;
                    submitter.textContent = 'Guardar asignaciones seleccionadas';
                }
            }
        });
    });

    cancel.addEventListener('click', () => {
        pendingForm = null;
        closeModal();
        clearDetails();
    });

    accept.addEventListener('click', () => {
        if (!pendingForm) return;
        const form = pendingForm;
        pendingForm = null;
        closeModal();
        form.dataset.confirmed = 'true';
        form.requestSubmit();
    });

    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
            pendingForm = null;
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !backdrop.hidden) {
            pendingForm = null;
            closeModal();
        }
    });
})();
</script>
</body>
</html>
