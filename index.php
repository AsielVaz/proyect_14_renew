<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/auth.php';

send_security_headers();
handle_authentication();

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
$invoiceModule = in_array($invoiceView, ['list', 'create', 'complements'], true);
$complementView = (string) ($_GET['complement'] ?? '');
$complementModule = in_array($complementView, ['list', 'create', 'detail'], true);
$companyView = (string) ($_GET['companies'] ?? '');
$companyModule = in_array($companyView, ['list', 'detail', 'captures', 'capture', 'movements', 'account', 'analyze'], true);
$action = (string) ($_GET['action'] ?? 'list');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$catalog && !$report && !$movement && !$invoiceModule && !$complementModule && !$companyModule
    && isset($_GET['calculator']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        verify_csrf();
        $calculator = (string) $_GET['calculator'];
        $amount = (float) str_replace([',', '$', ' '], '', (string) ($_POST['amount'] ?? '0'));
        $data = match ($calculator) {
            'balance' => dashboard_repository()->calculateBalance(
                (int) ($_POST['client_id'] ?? 0), (string) ($_POST['mode'] ?? ''), $amount, current_operator_id()
            ),
            'banking' => dashboard_repository()->calculateBanking((int) ($_POST['company_id'] ?? 0), $amount),
            'dispersal' => dashboard_repository()->calculateDispersal(
                $amount, (int) ($_POST['units'] ?? 0), (string) ($_POST['accounts'] ?? '')
            ),
            default => throw new RuntimeException('Calculadora desconocida.'),
        };
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if ($companyView === 'analyze' && in_array((string) ($_GET['data'] ?? ''), ['parse', 'short_keys'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        verify_csrf();
        if (($_GET['data'] ?? '') === 'parse') {
            $analysis = capture_analysis_service()->analyze(
                (int) ($id ?? 0), (int) ($_POST['account_id'] ?? 0),
                (string) ($_POST['capture'] ?? ''), current_operator_id()
            );
            echo json_encode(['ok' => true, 'analysis' => $analysis], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            $descriptions = json_decode((string) ($_POST['descriptions'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($descriptions)) {
                throw new RuntimeException('La lista de movimientos no es válida.');
            }
            $matches = company_panel_repository()->analyzeShortKeys(
                (int) ($id ?? 0), (int) ($_POST['account_id'] ?? 0), $descriptions, current_operator_id()
            );
            echo json_encode(['ok' => true, 'matches' => $matches], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

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

if ($complementModule && isset($_GET['data'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $data = match ((string) $_GET['data']) {
        'receivers' => payment_complement_repository()->receivers((int) ($_GET['client'] ?? 0)),
        'invoice' => payment_complement_repository()->invoiceByUuid((string) ($_GET['uuid'] ?? '')),
        default => throw new RuntimeException('Consulta de complementos desconocida.'),
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
        if ($complementModule) {
            if ($postAction === 'complement_create') {
                $complementId = payment_complement_repository()->create($_POST);
                flash('success', 'Complemento #' . $complementId . ' guardado con sus facturas relacionadas. Quedó pendiente de timbrado.');
                redirect('?complement=detail&id=' . $complementId);
            } elseif ($postAction === 'complement_delete') {
                payment_complement_repository()->deletePending((int) ($_POST['id'] ?? 0));
                flash('success', 'Complemento pendiente eliminado junto con sus relaciones.');
                redirect('?complement=list');
            } elseif ($postAction === 'complement_stamp') {
                $message = fiscal_document_service()->stampComplement(
                    (int) ($_POST['id'] ?? 0), (string) ($_POST['treatment'] ?? '')
                );
                flash('success', $message);
                redirect('?complement=list');
            } elseif ($postAction === 'complement_resend') {
                $message = fiscal_document_service()->resendComplement((int) ($_POST['id'] ?? 0));
                flash('success', $message);
                redirect('?complement=list');
            } else {
                throw new RuntimeException('Acción de complemento no permitida.');
            }
        } elseif ($invoiceModule) {
            if ($postAction === 'invoice_create') {
                $invoiceId = invoice_repository()->create($_POST, current_operator_id());
                flash('success', 'Factura #' . $invoiceId . ' guardada con sus conceptos y relaciones. Quedó pendiente de timbrado.');
                redirect('?invoice=list');
            } elseif ($postAction === 'invoice_stamp') {
                $message = fiscal_document_service()->stampInvoice((int) ($_POST['id'] ?? 0), current_operator_id());
                flash('success', $message);
                redirect('?invoice=list');
            } elseif ($postAction === 'invoice_resend') {
                $message = fiscal_document_service()->resendInvoice((int) ($_POST['id'] ?? 0));
                flash('success', $message);
                redirect('?invoice=list');
            } elseif ($postAction === 'invoice_delete') {
                invoice_repository()->deletePending((int) ($_POST['id'] ?? 0));
                flash('success', 'Factura pendiente eliminada junto con sus conceptos.');
                redirect('?invoice=list');
            } else {
                throw new RuntimeException('Acción de facturación no permitida.');
            }
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
    $target = $complementModule ? '?complement=' . rawurlencode($complementView) : ($invoiceModule ? '?invoice=' . rawurlencode($invoiceView) : ($movement ? '?movement=' . rawurlencode($movementKey) : ($catalog ? '?catalog=' . rawurlencode($key) : './')));
    redirect($target);
}

$flash = take_flash();
$complementPrefill = null;
if ($complementView === 'create' && isset($_GET['invoice_id'])) {
    try {
        $sourceInvoiceId = filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT) ?: 0;
        $complementPrefill = payment_complement_repository()->invoiceById($sourceInvoiceId);
        if (!$complementPrefill || (int) $complementPrefill['metodo_pago'] !== 2) {
            throw new RuntimeException('La factura seleccionada no existe o no usa el método PPD.');
        }
        if (trim((string) $complementPrefill['uuid']) === '') {
            throw new RuntimeException('La factura PPD debe estar timbrada antes de crear su complemento de pago.');
        }
        if ((float) $complementPrefill['saldo'] <= 0) {
            throw new RuntimeException('La factura seleccionada ya no tiene saldo pendiente.');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('?invoice=list');
    }
}
$record = null;
if ($catalog && $action === 'form' && $id) {
    $record = repository()->find($catalog, $id);
    if (!$record) {
        flash('error', 'No se encontró el registro solicitado.');
        redirect('?catalog=' . rawurlencode($key));
    }
}

$companyError = null;
$companyData = null;
$companyResult = null;
$companySummary = null;
$companyCapture = null;
if ($companyModule) {
    try {
        $operatorId = current_operator_id();
        $companyPage = max(1, (int) ($_GET['page'] ?? 1));
        if ($companyView === 'list') {
            $companyResult = company_panel_repository()->companies(trim((string) ($_GET['q'] ?? '')), $companyPage, $operatorId);
        } else {
            $companyId = (int) ($id ?? 0);
            $companyData = company_panel_repository()->company($companyId, $operatorId);
            if ($companyView === 'detail') {
                $companySummary = company_panel_repository()->summary($companyId, $operatorId);
            } elseif ($companyView === 'captures') {
                $companyResult = company_panel_repository()->captures($companyId, $_GET, $companyPage, $operatorId);
            } elseif ($companyView === 'movements') {
                $companyResult = company_panel_repository()->movements($companyId, $_GET, $companyPage, $operatorId);
            } elseif ($companyView === 'capture') {
                $captureId = (int) ($_GET['capture_id'] ?? 0);
                $companyCapture = company_panel_repository()->capture($companyId, $captureId, $operatorId);
                $companyResult = company_panel_repository()->captureMovements($captureId, $companyPage);
            } elseif ($companyView === 'account') {
                $companyResult = company_panel_repository()->accountMovements(
                    $companyId, (int) ($_GET['account_id'] ?? 0), $_GET, $companyPage, $operatorId
                );
            } elseif ($companyView === 'analyze') {
                $companyResult = ['account' => company_panel_repository()->accountMovements(
                    $companyId, (int) ($_GET['account_id'] ?? 0), [], 1, $operatorId, 1
                )['account']];
            }
        }
    } catch (Throwable $exception) {
        $companyError = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= e(app_url('/')) ?>">
    <title><?= e($companyModule ? ($companyData['razon'] ?? 'Empresas') : ($complementModule ? ($complementView === 'create' ? 'Nuevo complemento de pago' : ($complementView === 'detail' ? 'Facturas asociadas al complemento' : 'Complementos de pago')) : ($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : ($invoiceView === 'complements' ? 'Complementos asociados' : 'Facturas')) : ($catalog['label'] ?? $report['label'] ?? $movement['label'] ?? 'Sistema 14')))) ?> · Sistema 14</title>
    <!-- theme.css contiene todos los tokens visuales; app.css define componentes. -->
    <link rel="stylesheet" href="assets/theme.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $companyModule ? 'company-module' : '' ?>">
<aside class="sidebar">
    <a class="brand" href="./"><img src="assets/logo-s14.png" alt="Sistema 14"><strong>Sistema 14</strong></a>
    <nav class="main-nav">
        <a class="dashboard-link <?= !$catalog && !$report && !$movement && !$invoiceModule && !$complementModule && !$companyModule ? 'active' : '' ?>" href="./">
            <span class="nav-icon">⌂</span>
            Panel principal
        </a>
        <a class="dashboard-link <?= $companyModule ? 'active' : '' ?>" href="?companies=list">
            <span class="nav-icon">▥</span>
            Panel de empresas
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
        <details class="catalog-menu" <?= $invoiceModule || $complementModule ? 'open' : '' ?>>
            <summary><span><span class="nav-icon">▧</span> Facturación</span><span class="chevron">⌄</span></summary>
            <div class="catalog-links">
                <a class="<?= $invoiceView === 'list' ? 'active' : '' ?>" href="?invoice=list"><span></span>Facturas</a>
                <a class="<?= $invoiceView === 'create' ? 'active' : '' ?>" href="?invoice=create"><span></span>Crear factura</a>
                <a class="<?= $complementModule ? 'active' : '' ?>" href="?complement=list"><span></span>Complementos de pago</a>
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
    <?php $signedUser = current_user(); ?>
    <div class="session-user">
        <span><?= e($signedUser['name'] ?? '') ?></span>
        <small><?= e($signedUser['type'] ?? '') ?></small>
        <form method="post" action="?auth=logout">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </div>
    <p class="phase">Sistema 14 renovado · PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></p>
</aside>

<main>
    <header class="topbar">
        <div>
            <p class="eyebrow"><?= $companyModule ? 'Tesorería por empresa' : ($invoiceModule || $complementModule ? 'Facturación CFDI' : ($report ? 'Consulta y análisis' : ($movement ? 'Operación' : 'Administración'))) ?></p>
            <h1><?= e($companyModule ? ($companyData['razon'] ?? 'Panel de empresas') : ($complementModule ? ($complementView === 'create' ? 'Nuevo complemento de pago' : ($complementView === 'detail' ? 'Facturas asociadas al complemento' : 'Complementos de pago')) : ($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : ($invoiceView === 'complements' ? 'Complementos asociados' : 'Facturas')) : ($catalog['label'] ?? $report['label'] ?? ($movementAction === 'transfer' && $action === 'form' ? 'Nuevo traspaso' : ($movement['label'] ?? 'Panel principal')))))) ?></h1>
        </div>
        <?php if ($catalog && $action !== 'form'): ?>
            <a class="button primary" href="?catalog=<?= e($key) ?>&amp;action=form">+ Nuevo registro</a>
        <?php elseif ($movementKey === 'traspasos' && $action !== 'form'): ?>
            <a class="button primary" href="?movement=traspasos&amp;action=form">+ Nuevo traspaso</a>
        <?php elseif ($invoiceView === 'list'): ?>
            <a class="button primary" href="?invoice=create">+ Nueva factura</a>
        <?php elseif ($complementView === 'list'): ?>
            <a class="button primary" href="?complement=create">+ Nuevo complemento</a>
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

    <?php if ($companyModule): ?>
        <?php if ($companyError): ?>
            <section class="panel"><div class="empty"><strong>No se pudo abrir el panel.</strong><br><?= e($companyError) ?><br><a href="?companies=list">Volver a empresas</a></div></section>
        <?php elseif ($companyView === 'list'): ?>
            <section class="report-intro"><p>Consulta cada empresa, sus cuentas bancarias, capturas importadas y movimientos conciliados.</p><span class="operation-badge">Vista operativa</span></section>
            <section class="panel company-directory-panel">
                <div class="panel-heading list-tools">
                    <div><p class="eyebrow">Empresas activas</p><h2>Directorio de empresas</h2><p class="panel-copy"><?= count($companyResult['rows'] ?? []) ?> resultados en esta página</p></div>
                    <form method="get" class="search"><input type="hidden" name="companies" value="list"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Razón social, RFC o ID…"><button class="button">Buscar</button></form>
                </div>
                <div class="company-grid">
                    <?php foreach ($companyResult['rows'] ?? [] as $item): ?>
                        <a class="company-card" href="?companies=detail&amp;id=<?= e($item['id']) ?>">
                            <div class="company-card-media">
                                <?php if (trim((string) ($item['logo'] ?? '')) !== ''): ?><img src="<?= e(legacy_asset_url($item['logo'])) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(mb_strtoupper(mb_substr((string) $item['razon'], 0, 2))) ?></span><?php endif; ?>
                            </div>
                            <div class="company-card-body"><small>Empresa #<?= e($item['id']) ?></small><strong><?= e($item['razon']) ?></strong><span><?= e($item['rfc'] ?: 'RFC no registrado') ?></span></div>
                            <div class="company-card-footer"><span><?= e($item['cuentas']) ?> cuentas activas</span><b>Ver panel →</b></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (($companyResult['rows'] ?? []) === []): ?><div class="empty">No se encontraron empresas con ese criterio.</div><?php endif; ?>
                </div>
                <?php $companyListQuery = ['companies' => 'list', 'q' => (string) ($_GET['q'] ?? '')]; ?>
                <div class="pagination"><?php if (($companyResult['page'] ?? 1) > 1): ?><a class="button" href="?<?= e(http_build_query($companyListQuery + ['page' => $companyResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?><span>Página <?= e($companyResult['page'] ?? 1) ?></span><?php if ($companyResult['hasNext'] ?? false): ?><a class="button" href="?<?= e(http_build_query($companyListQuery + ['page' => $companyResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?></div>
            </section>
        <?php elseif ($companyView === 'detail'): ?>
            <section class="company-identity panel">
                <div class="company-identity-logo"><?php if (trim((string) ($companyData['logo'] ?? '')) !== ''): ?><img src="<?= e(legacy_asset_url($companyData['logo'])) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr((string) $companyData['razon'], 0, 2))) ?></span><?php endif; ?></div>
                <div><p class="eyebrow">Empresa #<?= e($companyData['id']) ?></p><h2><?= e($companyData['razon']) ?></h2><p><?= e($companyData['rfc'] ?: 'RFC no registrado') ?> · <?= e(trim(implode(' ', array_filter([$companyData['calle'], $companyData['numero'], $companyData['colonia'], $companyData['cp'], $companyData['estado']]))) ?: 'Domicilio no registrado') ?></p></div>
                <a class="button" href="?companies=list">Todas las empresas</a>
            </section>
            <div class="company-summary-grid">
                <article><span>Capturas</span><strong><?= number_format((int) ($companySummary['capturas'] ?? 0)) ?></strong><a href="?companies=captures&amp;id=<?= e($companyData['id']) ?>">Consultar</a></article>
                <article><span>Movimientos</span><strong><?= number_format((int) ($companySummary['movimientos'] ?? 0)) ?></strong><a href="?companies=movements&amp;id=<?= e($companyData['id']) ?>">Consultar</a></article>
                <article><span>Por facturar</span><strong><?= number_format((int) ($companySummary['pendientes'] ?? 0)) ?></strong><small>Movimientos no retirados</small></article>
                <article><span>Depósitos sin facturar</span><strong><?= e(report_value($companySummary['sin_facturar'] ?? 0, 'money')) ?></strong><small>Importe acumulado</small></article>
            </div>
            <section class="panel">
                <div class="panel-heading"><div><p class="eyebrow">Bancos</p><h2>Cuentas activas</h2><p class="panel-copy">Saldo calculado con los depósitos y retiros importados.</p></div><div class="panel-actions"><a class="button" href="?companies=captures&amp;id=<?= e($companyData['id']) ?>">Capturas</a><a class="button" href="?companies=movements&amp;id=<?= e($companyData['id']) ?>">Movimientos</a></div></div>
                <div class="company-account-grid">
                    <?php foreach ($companyData['accounts'] as $account): ?>
                        <article class="company-account-card" style="--account-color: <?= e(preg_match('/^#?[0-9a-f]{6}$/i', (string) $account['color']) ? '#' . ltrim((string) $account['color'], '#') : '#59657a') ?>">
                            <div><span class="account-dot"></span><small><?= e($account['banco']) ?> · <?= e($account['moneda'] ?: 'MXN') ?></small></div><h3><?= e($account['nombre_corto'] ?: ('Cuenta #' . $account['id'])) ?></h3>
                            <strong><?= e(report_value($account['saldo'], 'money')) ?></strong><p><?= e($account['numero_cuenta'] ?: 'Número no registrado') ?></p>
                            <dl><div><dt>Capturas</dt><dd><?= number_format((int) $account['capturas']) ?></dd></div><div><dt>Última actividad</dt><dd><?= e($account['ultima_actividad'] ?: 'Sin movimientos') ?></dd></div></dl>
                            <div class="company-account-actions"><a href="?companies=account&amp;id=<?= e($companyData['id']) ?>&amp;account_id=<?= e($account['id']) ?>">Estado de cuenta</a><a href="?companies=captures&amp;id=<?= e($companyData['id']) ?>&amp;account=<?= e($account['id']) ?>">Capturas</a><a href="?companies=analyze&amp;id=<?= e($companyData['id']) ?>&amp;account_id=<?= e($account['id']) ?>">Analizar captura</a></div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($companyData['accounts'] === []): ?><div class="empty">Esta empresa no tiene cuentas bancarias activas.</div><?php endif; ?>
                </div>
            </section>
        <?php elseif ($companyView === 'analyze'): ?>
            <section class="report-intro"><p><a href="?companies=detail&amp;id=<?= e($companyData['id']) ?>"><?= e($companyData['razon']) ?></a> · Analizar captura bancaria</p><span class="operation-badge"><?= e(($companyResult['account']['banco'] ?? '') . ' · ' . ($companyResult['account']['nombre_corto'] ?? '')) ?></span></section>
            <section class="panel capture-analyzer-panel">
                <div class="panel-heading"><div><p class="eyebrow">Paso previo a la carga</p><h2>Analizar movimientos</h2><p class="panel-copy">Pega la captura conservando su formato. El análisis no guarda información y verifica cada movimiento contra la tabla de claves cortas.</p></div><a class="button" href="?companies=detail&amp;id=<?= e($companyData['id']) ?>">Volver al panel</a></div>
                <form id="captureAnalyzer" class="capture-analyzer" data-parser-url="?companies=analyze&amp;id=<?= e($companyData['id']) ?>&amp;data=parse" data-company-id="<?= e($companyData['id']) ?>" data-account-id="<?= e($companyResult['account']['id']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="field"><span>Contenido de la captura *</span><div id="captureEditor" class="capture-editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Pega aquí la tabla o captura copiada desde el portal bancario…"></div></label>
                    <div class="capture-analyzer-actions"><button type="button" class="button" id="clearCaptureAnalysis">Limpiar</button><button type="submit" class="button primary" id="analyzeCapture">Analizar antes de cargar</button></div>
                </form>
            </section>
            <section class="panel" id="captureAnalysisResult" hidden>
                <div class="panel-heading"><div><p class="eyebrow">Resultado del análisis</p><h2>Movimientos detectados</h2><p class="panel-copy" id="captureAnalysisMessage"></p></div></div>
                <div class="company-summary-grid capture-analysis-summary" id="captureAnalysisSummary"></div>
                <div class="table-wrap"><table class="movement-table"><thead><tr><th>#</th><th>Fecha</th><th>Descripción / referencia</th><th>Tipo</th><th>Importe</th><th>Duplicado</th><th>Clave corta asociada</th></tr></thead><tbody id="captureAnalysisRows"></tbody></table></div>
                <div class="capture-analysis-note"><strong>Revisión previa</strong><span>“Sin clave asociada” no impide revisar el movimiento, pero indica que no se encontró ninguna clave de <code>claves_cortas</code> dentro de su descripción o referencia.</span></div>
            </section>
        <?php elseif (in_array($companyView, ['captures', 'movements', 'account'], true)): ?>
            <?php
                $isCaptures = $companyView === 'captures';
                $isAccount = $companyView === 'account';
                $sectionLabel = $isCaptures ? 'Capturas bancarias' : ($isAccount ? 'Estado de cuenta' : 'Movimientos de la empresa');
                $baseQuery = ['companies' => $companyView, 'id' => $companyData['id']];
                if ($isAccount) { $baseQuery['account_id'] = (int) ($_GET['account_id'] ?? 0); }
                foreach (['q', 'from', 'to', 'account'] as $filterKey) { if (isset($_GET[$filterKey]) && $_GET[$filterKey] !== '') { $baseQuery[$filterKey] = (string) $_GET[$filterKey]; } }
            ?>
            <section class="report-intro"><p><a href="?companies=detail&amp;id=<?= e($companyData['id']) ?>"><?= e($companyData['razon']) ?></a> · <?= e($sectionLabel) ?></p><span class="operation-badge"><?= $isAccount ? e(($companyResult['account']['banco'] ?? '') . ' · ' . ($companyResult['account']['nombre_corto'] ?? '')) : 'Consulta histórica' ?></span></section>
            <section class="panel">
                <div class="panel-heading list-tools"><div><p class="eyebrow">Tesorería</p><h2><?= e($sectionLabel) ?></h2><p class="panel-copy"><?= count($companyResult['rows'] ?? []) ?> registros en esta página</p></div><a class="button" href="?companies=detail&amp;id=<?= e($companyData['id']) ?>">Volver al panel</a></div>
                <form method="get" class="report-filters company-filters"><input type="hidden" name="companies" value="<?= e($companyView) ?>"><input type="hidden" name="id" value="<?= e($companyData['id']) ?>"><?php if ($isAccount): ?><input type="hidden" name="account_id" value="<?= e($_GET['account_id'] ?? '') ?>"><?php endif; ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="ID, referencia, descripción…"><label>Desde<input type="date" name="from" value="<?= e($_GET['from'] ?? '') ?>"></label><label>Hasta<input type="date" name="to" value="<?= e($_GET['to'] ?? '') ?>"></label><?php if (!$isAccount): ?><label>Cuenta<select name="account"><option value="">Todas</option><?php foreach ($companyData['accounts'] as $account): ?><option value="<?= e($account['id']) ?>" <?= (string) ($_GET['account'] ?? '') === (string) $account['id'] ? 'selected' : '' ?>><?= e($account['banco'] . ' · ' . $account['nombre_corto']) ?></option><?php endforeach; ?></select></label><?php endif; ?><button class="button">Filtrar</button></form>
                <div class="table-wrap"><table class="movement-table"><thead><tr>
                    <?php if ($isCaptures): ?><th>Captura</th><th>Fecha</th><th>Cuenta</th><th>Movimientos</th><th>Depósitos</th><th>Retiros</th><th></th>
                    <?php elseif ($isAccount): ?><th>Movimiento</th><th>Fecha</th><th>Referencia / descripción</th><th>Depósito</th><th>Retiro</th><th>Saldo</th><th>Captura</th>
                    <?php else: ?><th>Movimiento</th><th>Fecha</th><th>Cliente / cuenta</th><th>Tipo / estado</th><th>Monto</th><th>Factura</th><th>Captura</th><?php endif; ?>
                </tr></thead><tbody>
                    <?php foreach ($companyResult['rows'] ?? [] as $row): ?><tr>
                        <?php if ($isCaptures): ?>
                            <td><span class="movement-cell-main">#<?= e($row['id']) ?></span></td><td><?= e(trim((string) ($row['fecha'] . ' ' . $row['hora']))) ?></td><td><span class="movement-cell-main"><?= e($row['nombre_corto'] ?: ('Cuenta #' . $row['cuenta_id'])) ?></span><span class="movement-cell-secondary"><?= e(($row['banco'] ?: 'Banco no identificado') . ' · ' . ($row['moneda'] ?: 'MXN')) ?></span></td><td><?= number_format((int) $row['movimientos']) ?></td><td><?= e(report_value($row['depositos'], 'money')) ?></td><td><?= e(report_value($row['retiros'], 'money')) ?></td><td><a href="?companies=capture&amp;id=<?= e($companyData['id']) ?>&amp;capture_id=<?= e($row['id']) ?>">Ver detalle</a></td>
                        <?php elseif ($isAccount): ?>
                            <td><span class="movement-cell-main">#<?= e($row['id']) ?></span><?php if ($row['pago_id']): ?><span class="movement-cell-secondary">Pago #<?= e($row['pago_id']) ?></span><?php endif; ?></td><td><?= e($row['fecha'] ?: $row['fecha_sistema']) ?></td><td><span class="movement-cell-main"><?= e($row['referencia'] ?: 'Sin referencia') ?></span><span class="movement-cell-secondary"><?= e($row['descripcion'] ?: $row['descrip_det']) ?></span></td><td><?= e(report_value($row['deposito'], 'money')) ?></td><td><?= e(report_value($row['retiro'], 'money')) ?></td><td><?= e(report_value($row['saldo'], 'money')) ?></td><td><?php if ($row['id_captura']): ?><a href="?companies=capture&amp;id=<?= e($companyData['id']) ?>&amp;capture_id=<?= e($row['id_captura']) ?>">#<?= e($row['id_captura']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <?php else: ?>
                            <td><span class="movement-cell-main">#<?= e($row['id']) ?></span></td><td><?= e($row['fecha_banco'] ?: ($row['fecha_operacion'] ?: $row['fecha_captura'])) ?></td><td><span class="movement-cell-main"><?= e($row['cliente']) ?></span><span class="movement-cell-secondary"><?= e($row['cuenta'] ?: 'Sin cuenta') ?></span></td><td><span class="movement-cell-main"><?= e($row['tipo_movimiento'] ?: '—') ?></span><span class="movement-cell-secondary"><?= e($row['status'] ?: 'Sin estado') ?></span></td><td><span class="movement-cell-main"><?= e(report_value($row['monto'], 'money')) ?></span><span class="movement-cell-secondary">Acreditable: <?= e(report_value($row['saldo_acreditable'], 'money')) ?></span></td><td><?= e($row['factura'] ?: '—') ?></td><td><?php if ($row['id_captura']): ?><a href="?companies=capture&amp;id=<?= e($companyData['id']) ?>&amp;capture_id=<?= e($row['id_captura']) ?>">#<?= e($row['id_captura']) ?></a><?php else: ?>—<?php endif; ?></td>
                        <?php endif; ?>
                    </tr><?php endforeach; ?>
                    <?php if (($companyResult['rows'] ?? []) === []): ?><tr><td colspan="7" class="empty">No hay registros para los filtros seleccionados.</td></tr><?php endif; ?>
                </tbody></table></div>
                <div class="pagination"><?php if (($companyResult['page'] ?? 1) > 1): ?><a class="button" href="?<?= e(http_build_query($baseQuery + ['page' => $companyResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?><span>Página <?= e($companyResult['page'] ?? 1) ?></span><?php if ($companyResult['hasNext'] ?? false): ?><a class="button" href="?<?= e(http_build_query($baseQuery + ['page' => $companyResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?></div>
            </section>
        <?php elseif ($companyView === 'capture'): ?>
            <?php $capturePageQuery = ['companies' => 'capture', 'id' => $companyData['id'], 'capture_id' => $companyCapture['id']]; ?>
            <section class="report-intro"><p><a href="?companies=captures&amp;id=<?= e($companyData['id']) ?>">Capturas de <?= e($companyData['razon']) ?></a> · Captura #<?= e($companyCapture['id']) ?></p><span class="operation-badge"><?= e(($companyCapture['banco'] ?: 'Banco') . ' · ' . ($companyCapture['nombre_corto'] ?: ('Cuenta #' . $companyCapture['cuenta_id']))) ?></span></section>
            <div class="company-summary-grid capture-summary"><article><span>Movimientos</span><strong><?= number_format((int) ($companyCapture['summary']['movimientos'] ?? 0)) ?></strong></article><article><span>Depósitos</span><strong><?= e(report_value($companyCapture['summary']['depositos'] ?? 0, 'money')) ?></strong></article><article><span>Retiros</span><strong><?= e(report_value($companyCapture['summary']['retiros'] ?? 0, 'money')) ?></strong></article><article><span>Fecha de captura</span><strong><?= e(trim((string) ($companyCapture['fecha'] . ' ' . $companyCapture['hora']))) ?></strong></article></div>
            <section class="panel"><div class="panel-heading"><div><p class="eyebrow">Contenido importado</p><h2>Archivo bancario original</h2><p class="panel-copy"><?php if ((int) $companyCapture['captura_longitud'] > 50000): ?>Vista limitada a los primeros 50,000 caracteres de <?= number_format((int) $companyCapture['captura_longitud']) ?>.<?php else: ?>Contenido completo almacenado por el sistema legado.<?php endif; ?></p></div><a class="button" href="?companies=captures&amp;id=<?= e($companyData['id']) ?>">Volver</a></div><pre class="capture-raw"><?= e($companyCapture['captura']) ?></pre></section>
            <section class="panel"><div class="panel-heading"><div><p class="eyebrow">Desglose</p><h2>Movimientos de la captura</h2></div></div><div class="table-wrap"><table class="movement-table"><thead><tr><th>Movimiento</th><th>Fecha</th><th>Referencia / descripción</th><th>Depósito</th><th>Retiro</th><th>Saldo</th><th>Tipo</th></tr></thead><tbody><?php foreach ($companyResult['rows'] ?? [] as $row): ?><tr><td><span class="movement-cell-main">#<?= e($row['id']) ?></span><?php if ($row['pago_id']): ?><span class="movement-cell-secondary">Pago #<?= e($row['pago_id']) ?></span><?php endif; ?></td><td><?= e($row['fecha'] ?: $row['fecha_sistema']) ?></td><td><span class="movement-cell-main"><?= e($row['referencia'] ?: 'Sin referencia') ?></span><span class="movement-cell-secondary"><?= e($row['descripcion'] ?: $row['descrip_det']) ?></span></td><td><?= e(report_value($row['deposito'], 'money')) ?></td><td><?= e(report_value($row['retiro'], 'money')) ?></td><td><?= e(report_value($row['saldo'], 'money')) ?></td><td><?= e($row['tipo_movimiento'] ?: '—') ?></td></tr><?php endforeach; ?><?php if (($companyResult['rows'] ?? []) === []): ?><tr><td colspan="7" class="empty">La captura no contiene movimientos desglosados.</td></tr><?php endif; ?></tbody></table></div><div class="pagination"><?php if (($companyResult['page'] ?? 1) > 1): ?><a class="button" href="?<?= e(http_build_query($capturePageQuery + ['page' => $companyResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?><span>Página <?= e($companyResult['page'] ?? 1) ?></span><?php if ($companyResult['hasNext'] ?? false): ?><a class="button" href="?<?= e(http_build_query($capturePageQuery + ['page' => $companyResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?></div></section>
        <?php endif; ?>
    <?php elseif ($complementModule): ?>
        <?php if ($complementView === 'create'): ?>
            <?php $complementData = payment_complement_repository()->formData(); $lockedComplementContext = $complementPrefill !== null; ?>
            <section class="report-intro"><p>Registra el pago y relaciónalo con una o varias facturas PPD. Los saldos se verifican nuevamente al guardar.</p><span class="operation-badge">Guardado transaccional</span></section>
            <form method="post" id="complementForm" data-confirm="¿Guardar este complemento y aplicar los montos a las facturas seleccionadas?" data-confirm-title="Confirmar complemento"<?= $lockedComplementContext ? ' data-prefill-invoice-uuid="' . e($complementPrefill['uuid']) . '"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_create">
                <?php if ($lockedComplementContext): ?><input type="hidden" name="company_id" value="<?= e($complementPrefill['company_id']) ?>"><input type="hidden" name="client_id" value="<?= e($complementPrefill['client_id']) ?>"><input type="hidden" name="receiver_id" value="<?= e($complementPrefill['receiver_id']) ?>"><input type="hidden" name="source_invoice_id" value="<?= e($complementPrefill['id']) ?>"><?php endif; ?>
                <section class="panel invoice-form-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Datos del pago</p><h2>Emisor y receptor</h2></div><a class="button" href="?complement=list">Volver</a></div>
                    <div class="form-grid invoice-header-grid">
                        <label class="field"><span>Empresa emisora *</span><select <?= $lockedComplementContext ? '' : 'name="company_id" ' ?>id="complementCompany" required<?= $lockedComplementContext ? ' disabled aria-disabled="true"' : '' ?>><?php if ($lockedComplementContext): ?><option value="<?= e($complementPrefill['company_id']) ?>" selected><?= e($complementPrefill['empresa']) ?></option><?php else: ?><option value="">Seleccionar…</option><?php foreach ($complementData['companies'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['razon']) ?> · <?= e($item['rfc']) ?></option><?php endforeach; ?><?php endif; ?></select></label>
                        <label class="field"><span>Cliente *</span><select <?= $lockedComplementContext ? '' : 'name="client_id" ' ?>id="complementClient" required<?= $lockedComplementContext ? ' disabled aria-disabled="true"' : '' ?>><?php if ($lockedComplementContext): ?><option value="<?= e($complementPrefill['client_id']) ?>" selected><?= e($complementPrefill['cliente']) ?></option><?php else: ?><option value="">Seleccionar…</option><?php foreach ($complementData['clients'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['nombre']) ?></option><?php endforeach; ?><?php endif; ?></select></label>
                        <label class="field"><span>Razón social / clave corta *</span><select <?= $lockedComplementContext ? '' : 'name="receiver_id" ' ?>id="complementReceiver" required<?= $lockedComplementContext ? ' disabled aria-disabled="true"' : ' disabled' ?>><?php if ($lockedComplementContext): ?><option value="<?= e($complementPrefill['receiver_id']) ?>" selected><?= e($complementPrefill['clave_corta'] . ' · ' . $complementPrefill['receptor']) ?></option><?php else: ?><option value="">Selecciona primero un cliente…</option><?php endif; ?></select></label>
                        <label class="field"><span>Forma en que se recibió el pago *</span><select name="form_id" required><option value="">Seleccionar…</option><?php foreach ($complementData['forms'] as $item): ?><option value="<?= e($item['id']) ?>" <?= (int) $item['clave'] === 3 ? 'selected' : '' ?>><?= e(str_pad((string) $item['clave'], 2, '0', STR_PAD_LEFT)) ?> · <?= e(trim((string) $item['forma_pago'])) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Fecha del pago *</span><input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                        <label class="field"><span>Tratamiento fiscal *</span><select name="complement_type" required><option value="T">Típico · IVA 16%</option><option value="T0">Tasa 0%</option><option value="SV">Sin IVA</option><option value="4">Retención 4%</option></select></label>
                        <label class="field"><span>Movimiento de pago</span><input type="number" name="payment_id" min="1" placeholder="ID opcional"></label>
                    </div>
                </section>
                <section class="panel invoice-concepts-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Documentos relacionados</p><h2>Facturas PPD</h2><p class="panel-copy">Captura el UUID; el sistema consulta el saldo vigente antes de agregarlo.</p></div><button type="button" class="button" id="addComplementInvoice">+ Agregar factura</button></div>
                    <div class="table-wrap"><table class="invoice-lines complement-lines"><thead><tr><th>UUID / factura</th><th>Saldo anterior</th><th>Monto pagado</th><th>Parcialidad</th><th>Saldo restante</th><th></th></tr></thead><tbody id="complementLines"></tbody></table></div>
                    <div class="invoice-summary"><div><span>Facturas</span><strong id="complementCount">0</strong></div><div><span>Total del complemento</span><strong id="complementTotal">$0.00</strong></div><button type="submit" class="button primary">Guardar complemento</button></div>
                </section>
            </form>
        <?php elseif ($complementView === 'detail'): ?>
            <?php $complement = payment_complement_repository()->find((int) ($id ?? 0)); ?>
            <?php if (!$complement): ?>
                <section class="panel"><div class="empty">No se encontró el complemento solicitado. <a href="?complement=list">Volver al listado</a></div></section>
            <?php else: ?>
                <section class="report-intro"><p>Relación fiscal y aplicación del pago a sus facturas PPD.</p><span class="operation-badge"><?= e(trim((string) ($complement['uuid'] ?? '')) !== '' ? 'Timbrado' : 'Pendiente de timbrado') ?></span></section>
                <section class="panel">
                    <div class="panel-heading"><div><p class="eyebrow">Complemento #<?= e($complement['id']) ?></p><h2><?= e(($complement['serie'] ?? 'P') . ($complement['folio'] ?? '')) ?></h2><p class="panel-copy"><?= e($complement['uuid'] ?: 'Aún no tiene UUID fiscal') ?></p></div><div class="panel-actions"><?php if (trim((string) ($complement['uuid'] ?? '')) === ''): ?><button type="button" class="button primary" data-complement-stamp-trigger data-complement-id="<?= e($complement['id']) ?>">Timbrar complemento</button><?php endif; ?><a class="button" href="?complement=list">Volver</a></div></div>
                    <?php if (trim((string) ($complement['uuid'] ?? '')) === ''): ?><form method="post" id="complementStamp<?= e($complement['id']) ?>" class="complement-stamp-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_stamp"><input type="hidden" name="id" value="<?= e($complement['id']) ?>"><input type="hidden" name="treatment" value="<?= e(in_array($complement['cadena'], ['T','T0','SV'], true) ? $complement['cadena'] : 'T') ?>"></form><?php endif; ?>
                    <dl class="complement-detail-grid">
                        <div><dt>Empresa emisora</dt><dd><?= e($complement['empresa'] ?: '—') ?></dd></div><div><dt>Cliente</dt><dd><?= e($complement['cliente'] ?: '—') ?></dd></div>
                        <div><dt>Receptor</dt><dd><?= e($complement['receptor'] ?: '—') ?><small><?= e($complement['rfc'] ?: '') ?></small></dd></div><div><dt>Forma de pago</dt><dd><?= e(trim((string) ($complement['forma_pago'] ?? '—'))) ?></dd></div>
                        <div><dt>Fecha del pago</dt><dd><?= e(substr((string) $complement['fecha_pago'], 0, 10)) ?></dd></div><div><dt>Tratamiento</dt><dd><?= e($complement['cadena'] ?: 'T') ?></dd></div>
                        <div><dt>Movimiento asociado</dt><dd><?= (int) ($complement['id_pago'] ?? 0) > 0 ? '#' . e($complement['id_pago']) : '—' ?></dd></div><div><dt>Total</dt><dd><?= e(report_value($complement['total_factura'], 'money')) ?></dd></div>
                    </dl>
                    <div class="table-wrap"><table class="movement-table"><thead><tr><th>Factura</th><th>UUID</th><th>Saldo anterior</th><th>Pago / parcialidad</th><th>Saldo restante</th></tr></thead><tbody><?php foreach ($complement['relations'] as $relation): ?><tr>
                        <td><span class="movement-cell-main">#<?= e($relation['id_factura']) ?></span><span class="movement-cell-secondary"><?= e(($relation['serie'] ?? '') . ($relation['folio'] ?? '')) ?> · <?= e($relation['empresa'] ?? '') ?></span></td>
                        <td><?= e($relation['uuid_rel']) ?></td><td><?= e(report_value($relation['saldo'], 'money')) ?></td>
                        <td><span class="movement-cell-main"><?= e(report_value($relation['monto'], 'money')) ?></span><span class="movement-cell-secondary">Parcialidad <?= e($relation['num_pago']) ?></span></td><td><?= e(report_value($relation['restante'], 'money')) ?></td>
                    </tr><?php endforeach; ?><?php if ($complement['relations'] === []): ?><tr><td colspan="5" class="empty">Este complemento no tiene facturas relacionadas.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <?php $complementPage = max(1, (int) ($_GET['page'] ?? 1)); $complementSearch = trim((string) ($_GET['q'] ?? '')); $complementResult = payment_complement_repository()->paginate($complementSearch, $complementPage); ?>
            <section class="report-intro"><p>Complementos de recepción de pagos, sus facturas relacionadas y estado fiscal.</p><span class="operation-badge">CFDI tipo P</span></section>
            <section class="panel"><div class="panel-heading list-tools"><div><p class="eyebrow">Facturación</p><h2>Complementos de pago</h2><p class="panel-copy"><?= number_format(count($complementResult['rows'])) ?> resultados en esta página</p></div><form method="get" class="search"><input type="hidden" name="complement" value="list"><input type="search" name="q" value="<?= e($complementSearch) ?>" placeholder="ID, UUID, RFC, cliente…"><button class="button">Buscar</button></form></div>
                <div class="table-wrap"><table class="movement-table invoice-table document-table"><thead><tr><th>ID / folio</th><th>Emisor</th><th>Receptor</th><th>Pago</th><th>Total</th><th>Estado / acciones</th></tr></thead><tbody><?php foreach ($complementResult['rows'] as $row): ?><?php $complementStamped = trim((string) ($row['uuid'] ?? '')) !== ''; ?><tr data-record-row data-record-kind="complement" data-record-id="<?= e($row['id']) ?>">
                    <td><span class="movement-cell-main">#<?= e($row['id']) ?></span><span class="movement-cell-secondary"><?= e(($row['serie'] ?? 'P') . ($row['folio_interno'] ?? $row['folio'] ?? '')) ?> · <?= e(substr((string) ($row['fecha_pago'] ?? ''), 0, 10)) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['empresa'] ?: '—') ?></span><span class="movement-cell-secondary"><?= e($row['facturas_relacionadas']) ?> factura(s)</span></td>
                    <td><span class="movement-cell-main"><?= e($row['cliente'] ?: '—') ?></span><span class="movement-cell-secondary"><?= e($row['rfc'] ?: '') ?> · <?= e($row['receptor'] ?: '') ?></span></td>
                    <td><span class="movement-cell-main"><?= e(trim((string) ($row['forma_pago'] ?? '—'))) ?></span><span class="movement-cell-secondary">Tratamiento <?= e($row['tipo_complemento'] ?: 'T') ?></span></td>
                    <td><?= e(report_value($row['total_factura'], 'money')) ?></td>
                    <td><span class="movement-cell-main"><?= e($complementStamped ? 'Timbrado' : ($row['status'] ?: 'Pendiente')) ?></span><span class="movement-cell-secondary"><?= e($row['uuid'] ?: ($row['status_error'] ?: 'Sin UUID')) ?></span>
                        <div class="record-action-controls">
                            <?php if (!$complementStamped): ?><button type="button" class="button primary compact" data-complement-stamp-trigger data-complement-id="<?= e($row['id']) ?>">Timbrar</button><?php endif; ?>
                            <button type="button" class="record-menu-trigger" data-record-menu-trigger aria-label="Abrir acciones del complemento #<?= e($row['id']) ?>">•••</button>
                            <div class="record-action-source" hidden>
                                <a data-record-action data-action-label="Ver facturas asociadas" data-action-icon="⌕" href="?complement=detail&amp;id=<?= e($row['id']) ?>">Ver facturas asociadas</a>
                                <?php if (!$complementStamped): ?>
                                    <button type="button" data-record-action data-action-label="Timbrar complemento" data-action-icon="✓" data-complement-stamp-trigger data-complement-id="<?= e($row['id']) ?>">Timbrar</button>
                                    <a data-record-action data-action-label="Vista PDF" data-action-icon="▧" href="https://old.sistema14.com/gen_fact_complemento_pdf.php?id=<?= e($row['id']) ?>" target="_blank" rel="noopener">PDF</a>
                                    <form method="post" data-confirm="¿Eliminar este complemento pendiente y todas sus relaciones?" data-confirm-title="Eliminar complemento"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Eliminar pendiente" data-action-icon="×" data-action-danger>Eliminar</button></form>
                                <?php else: ?>
                                    <form method="post" data-confirm="¿Reenviar por correo este complemento timbrado?" data-confirm-title="Reenviar complemento"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_resend"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Reenviar por correo" data-action-icon="↗">Reenviar</button></form>
                                    <button type="button" data-record-action data-action-label="Copiar UUID" data-action-icon="⧉" data-copy-uuid="<?= e($row['uuid']) ?>">Copiar UUID</button>
                                    <a data-record-action data-action-label="Descargar PDF" data-action-icon="▧" href="https://old.sistema14.com/gen_fact_complemento_pdf.php?id=<?= e($row['id']) ?>" target="_blank" rel="noopener">PDF</a>
                                    <a data-record-action data-action-label="Descargar XML" data-action-icon="&lt;/&gt;" href="https://ultra.sistema14.com/api/xml/firmados-comp/XML-complemento-<?= e($row['id']) ?>-<?= e(rawurlencode((string) $row['uuid'])) ?>.xml" target="_blank" rel="noopener">XML</a>
                                <?php endif; ?>
                            </div>
                            <?php if (!$complementStamped): ?><form method="post" id="complementStamp<?= e($row['id']) ?>" class="complement-stamp-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_stamp"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="treatment" value="<?= e(in_array($row['tipo_complemento'], ['T','T0','SV'], true) ? $row['tipo_complemento'] : 'T') ?>"></form><?php endif; ?>
                        </div>
                    </td>
                </tr><?php endforeach; ?><?php if ($complementResult['rows'] === []): ?><tr><td colspan="6" class="empty">No hay complementos para mostrar.</td></tr><?php endif; ?></tbody></table></div>
                <div class="pagination"><span>Página <?= $complementResult['page'] ?><?= $complementResult['hasNext'] ? ' · Hay más resultados' : '' ?></span><div><?php if ($complementResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['complement'=>'list','q'=>$complementSearch,'page'=>$complementResult['page']-1])) ?>">← Anterior</a><?php endif; ?><?php if ($complementResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query(['complement'=>'list','q'=>$complementSearch,'page'=>$complementResult['page']+1])) ?>">Siguiente →</a><?php endif; ?></div></div>
            </section>
        <?php endif; ?>
    <?php elseif ($invoiceModule): ?>
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
        <?php elseif ($invoiceView === 'complements'): ?>
            <?php $invoiceComplements = invoice_repository()->associatedComplements((int) ($id ?? 0)); ?>
            <?php if (!$invoiceComplements): ?>
                <section class="panel"><div class="empty">No se encontró la factura PPD solicitada. <a href="?invoice=list">Volver al listado</a></div></section>
            <?php else: ?>
                <section class="report-intro"><p>Complementos de pago aplicados exclusivamente a esta factura PPD.</p><span class="operation-badge"><?= e($invoiceComplements['complement_count']) ?> complemento(s)</span></section>
                <section class="panel">
                    <div class="panel-heading"><div><p class="eyebrow">Factura #<?= e($invoiceComplements['id']) ?></p><h2><?= e(($invoiceComplements['serie'] ?? '') . ($invoiceComplements['folio'] ?? '')) ?></h2><p class="panel-copy"><?= e($invoiceComplements['uuid'] ?: 'Factura sin UUID') ?></p></div><a class="button" href="?invoice=list">Volver a facturas</a></div>
                    <dl class="complement-detail-grid">
                        <div><dt>Empresa emisora</dt><dd><?= e($invoiceComplements['empresa']) ?></dd></div><div><dt>Cliente</dt><dd><?= e($invoiceComplements['cliente']) ?></dd></div>
                        <div><dt>Clave corta / receptor</dt><dd><?= e($invoiceComplements['clave_corta']) ?><small><?= e($invoiceComplements['receptor']) ?></small></dd></div><div><dt>Complementos asociados</dt><dd><?= e($invoiceComplements['complement_count']) ?></dd></div>
                        <div><dt>Total de la factura</dt><dd><?= e(report_value($invoiceComplements['total_iva'], 'money')) ?></dd></div><div><dt>Total pagado</dt><dd><?= e(report_value($invoiceComplements['paid'], 'money')) ?></dd></div>
                        <div><dt>Saldo restante</dt><dd><?= e(report_value($invoiceComplements['balance'], 'money')) ?></dd></div>
                    </dl>
                    <div class="table-wrap"><table class="movement-table"><thead><tr><th>Complemento</th><th>UUID</th><th>Fecha de pago</th><th>Pago / parcialidad</th><th>Saldo restante</th><th>Estado</th></tr></thead><tbody><?php foreach ($invoiceComplements['complements'] as $relation): ?><tr>
                        <td><a href="?complement=detail&amp;id=<?= e($relation['id_complemento']) ?>">#<?= e($relation['id_complemento']) ?> · <?= e(($relation['serie'] ?? 'P') . ($relation['folio'] ?? '')) ?></a></td><td><?= e($relation['uuid'] ?: 'Sin UUID') ?></td><td><?= e(substr((string) $relation['fecha_pago'], 0, 10)) ?></td>
                        <td><span class="movement-cell-main"><?= e(report_value($relation['monto'], 'money')) ?></span><span class="movement-cell-secondary">Parcialidad <?= e($relation['num_pago']) ?></span></td><td><?= e(report_value($relation['restante'], 'money')) ?></td><td><?= e(trim((string) $relation['uuid']) !== '' ? 'Timbrado' : ($relation['status'] ?: ($relation['status_error'] ?: 'Pendiente'))) ?></td>
                    </tr><?php endforeach; ?><?php if ($invoiceComplements['complements'] === []): ?><tr><td colspan="6" class="empty">Esta factura todavía no tiene complementos asociados.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <?php $invoicePage = max(1, (int) ($_GET['page'] ?? 1)); $invoiceSearch = trim((string) ($_GET['q'] ?? '')); $invoiceResult = invoice_repository()->page($invoiceSearch, $invoicePage); ?>
            <section class="report-intro"><p>Facturas guardadas en la tabla original, con emisor, receptor, relación de pago y estado fiscal.</p></section>
            <section class="panel"><div class="panel-heading list-tools"><div><p class="eyebrow">Facturación</p><h2>Facturas</h2><p class="panel-copy"><?= number_format(count($invoiceResult['rows'])) ?> resultados en esta página</p></div><form method="get" class="search"><input type="hidden" name="invoice" value="list"><input type="search" name="q" value="<?= e($invoiceSearch) ?>" placeholder="Folio, UUID, cliente…"><button class="button">Buscar</button></form></div>
                <div class="table-wrap"><table class="movement-table invoice-table document-table"><thead><tr><th>ID / folio</th><th>Emisor</th><th>Receptor</th><th>Pago</th><th>Total</th><th>Estado fiscal / acciones</th></tr></thead><tbody><?php foreach ($invoiceResult['rows'] as $row): ?><?php $invoiceStamped = trim((string) ($row['uuid'] ?? '')) !== ''; ?><tr data-record-row data-record-kind="invoice" data-record-id="<?= e($row['id']) ?>">
                    <td><span class="movement-cell-main">#<?= e($row['id']) ?></span><span class="movement-cell-secondary"><?= e(($row['serie'] ?? '') . ($row['folio'] ?? '')) ?> · <?= e($row['fecha']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['empresa']) ?></span><span class="movement-cell-secondary"><?= e($row['usuario']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['cliente']) ?></span><span class="movement-cell-secondary"><?= e($row['rfc']) ?> · <?= e($row['receptor']) ?></span></td>
                    <td><span class="movement-cell-main"><?= e($row['metodo_pago']) ?></span><span class="movement-cell-secondary"><?= e($row['forma_pago']) ?> · <?= e($row['status_pago']) ?><?= $row['id_pago'] ? ' · Pago #' . e($row['id_pago']) : '' ?></span></td>
                    <td><?= e(report_value($row['total_iva'], 'money')) ?></td>
                    <td><span class="movement-cell-main"><?= e($row['status'] ?: ($invoiceStamped ? 'Timbrada' : 'Pendiente')) ?></span><span class="movement-cell-secondary"><?= e($row['uuid'] ?: ($row['status_error'] ?: 'Sin UUID')) ?></span>
                        <div class="record-action-controls">
                            <?php if (!$invoiceStamped): ?><button type="button" class="button primary compact" data-proxy-action="invoiceStamp<?= e($row['id']) ?>">Timbrar</button><?php endif; ?>
                            <button type="button" class="record-menu-trigger" data-record-menu-trigger aria-label="Abrir acciones de la factura #<?= e($row['id']) ?>">•••</button>
                            <div class="record-action-source" hidden>
                                <?php if ((int) $row['method_id'] === 2): ?>
                                    <a data-record-action data-action-label="Hacer complemento de pago" data-action-icon="$" href="?complement=create&amp;invoice_id=<?= e($row['id']) ?>" target="_blank" rel="noopener">Hacer complemento de pago</a>
                                    <a data-record-action data-action-label="Ver complementos asociados" data-action-icon="⌕" href="?invoice=complements&amp;id=<?= e($row['id']) ?>">Ver complementos asociados</a>
                                <?php endif; ?>
                                <?php if (!$invoiceStamped): ?>
                                    <form method="post" data-confirm="¿Timbrar fiscalmente la factura #<?= e($row['id']) ?>? Esta operación emitirá el CFDI." data-confirm-title="Timbrar factura"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_stamp"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button id="invoiceStamp<?= e($row['id']) ?>" type="submit" data-record-action data-action-label="Timbrar factura" data-action-icon="✓">Timbrar</button></form>
                                    <a data-record-action data-action-label="Ver PDF previo" data-action-icon="▧" href="https://ultra.sistema14.com/api/genera_factura.php?token=<?= e(rawurlencode((string) $row['token'])) ?>&amp;per=<?= e($row['id']) ?>" target="_blank" rel="noopener">Ver PDF</a>
                                    <a data-record-action data-action-label="Descargar PRE XML" data-action-icon="&lt;/&gt;" href="https://ultra.sistema14.com/api/xml/sinfirma/XML-factura-<?= e($row['id']) ?>.xml" target="_blank" rel="noopener">PRE XML</a>
                                    <form method="post" data-confirm="¿Eliminar esta factura pendiente y todos sus conceptos?" data-confirm-title="Eliminar factura"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Eliminar pendiente" data-action-icon="×" data-action-danger>Eliminar</button></form>
                                <?php else: ?>
                                    <button type="button" data-record-action data-action-label="Copiar UUID" data-action-icon="⧉" data-copy-uuid="<?= e($row['uuid']) ?>">Copiar UUID</button>
                                    <a data-record-action data-action-label="Ver PDF" data-action-icon="▧" href="https://ultra.sistema14.com/api/genera_factura.php?token=<?= e(rawurlencode((string) $row['token'])) ?>&amp;per=<?= e($row['id']) ?>" target="_blank" rel="noopener">Ver PDF</a>
                                    <a data-record-action data-action-label="Descargar XML" data-action-icon="&lt;/&gt;" href="https://ultra.sistema14.com/api/gateWayFactura.php?factura=<?= e($row['id']) ?>" target="_blank" rel="noopener">XML</a>
                                    <form method="post" data-confirm="¿Reenviar por correo esta factura timbrada?" data-confirm-title="Reenviar factura"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_resend"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Reenviar por correo" data-action-icon="↗">Reenviar</button></form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
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
                <template id="movementClientOptions"><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?> (#<?= e($client['id']) ?>)</option><?php endforeach; ?></template>
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
        $dashboardCalculators = dashboard_repository()->calculatorData(current_operator_id());
        ?>
        <section class="dashboard-summary">
            <article><span>Saldos internos</span><strong><?= e(report_value($dashboardOverview['internalBalance'], 'money')) ?></strong><small>Cartera interna #13</small></article>
            <article><span>Movimientos pendientes</span><strong><?= e(report_value($dashboardOverview['pendingBalance'], 'money')) ?></strong><small><?= number_format($dashboardOverview['pendingCount']) ?> movimientos</small></article>
            <article class="<?= abs($dashboardOverview['balanceDifference']) > 0.01 ? 'is-warning' : '' ?>"><span>Diferencia de saldos</span><strong><?= e(report_value($dashboardOverview['balanceDifference'], 'money')) ?></strong><small>Estados de cuenta contra pagos</small></article>
        </section>

        <section class="panel dashboard-calculators-panel">
            <div class="panel-heading calculator-launcher-heading"><div><p class="eyebrow">Herramientas operativas</p><h2>Calculadoras</h2><p class="panel-copy">Abre una herramienta para realizar el cálculo sin abandonar el panel.</p></div><div class="calculator-launcher-actions"><button type="button" class="button" data-calculator-open="balance"><span>$</span> Saldos</button><button type="button" class="button" data-calculator-open="banking"><span>%</span> Banking</button><button type="button" class="button" data-calculator-open="dispersal"><span>⇄</span> Dispersor</button></div></div>
        </section>

        <div class="glass-modal-backdrop dashboard-calculator-modal" data-calculator-modal="balance" hidden>
            <section class="glass-modal calculator-modal-card" role="dialog" aria-modal="true" aria-labelledby="balanceCalculatorTitle">
                <header><span class="calculator-icon">$</span><div><p class="eyebrow">Herramienta operativa</p><h2 id="balanceCalculatorTitle">Calculadora de saldos</h2><p>Calcula cuánto necesitas o cuánto queda según las comisiones de la cartera.</p></div></header>
                <div class="calculator-modal-content">
                    <form data-dashboard-calculator="balance" class="calculator-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label><span>Operación</span><select name="mode" required><option value="necesito">Dinero que necesito</option><option value="queda">Dinero que me queda</option></select></label>
                        <label><span>Cartera</span><select name="client_id" required><option value="">Seleccionar…</option><?php foreach ($dashboardCalculators['clients'] as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?></option><?php endforeach; ?></select></label>
                        <label><span>Monto</span><input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required></label>
                        <button type="submit" class="button primary">Calcular</button>
                    </form>
                    <div class="calculator-result balance-calculator-result" data-calculator-result hidden><span>Resultado</span><strong data-balance-result>$0.00</strong><small data-balance-detail></small></div>
                </div>
                <div class="glass-modal-actions"><button type="button" class="button" data-calculator-close>Cerrar</button></div>
            </section>
        </div>

        <div class="glass-modal-backdrop dashboard-calculator-modal" data-calculator-modal="banking" hidden>
            <section class="glass-modal calculator-modal-card calculator-modal-wide" role="dialog" aria-modal="true" aria-labelledby="bankingCalculatorTitle">
                <header><span class="calculator-icon">%</span><div><p class="eyebrow">Herramienta operativa</p><h2 id="bankingCalculatorTitle">Calculadora de banking</h2><p>Distribuye el monto entre proveedores según los porcentajes configurados.</p></div></header>
                <div class="calculator-modal-content">
                    <form data-dashboard-calculator="banking" class="calculator-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label><span>Empresa</span><select name="company_id" required><option value="">Seleccionar…</option><?php foreach ($dashboardCalculators['companies'] as $company): ?><option value="<?= e($company['id']) ?>"><?= e($company['razon']) ?></option><?php endforeach; ?></select></label>
                        <label><span>Monto</span><input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required></label>
                        <button type="submit" class="button primary">Calcular</button>
                    </form>
                    <div class="calculator-table" data-calculator-result hidden><table><thead><tr><th>Proveedor</th><th>Monto</th><th>% calc.</th><th>% config.</th></tr></thead><tbody data-banking-rows></tbody><tfoot><tr><th>Total</th><th data-banking-total></th><th colspan="2"></th></tr></tfoot></table></div>
                </div>
                <div class="glass-modal-actions"><button type="button" class="button" data-calculator-close>Cerrar</button></div>
            </section>
        </div>

        <div class="glass-modal-backdrop dashboard-calculator-modal" data-calculator-modal="dispersal" hidden>
            <section class="glass-modal calculator-modal-card calculator-modal-wide" role="dialog" aria-modal="true" aria-labelledby="dispersalCalculatorTitle">
                <header><span class="calculator-icon">⇄</span><div><p class="eyebrow">Herramienta operativa</p><h2 id="dispersalCalculatorTitle">Dispersor</h2><p>Divide un monto entre bancas y vuelve a distribuirlo entre cuentas.</p></div></header>
                <div class="calculator-modal-content">
                    <form data-dashboard-calculator="dispersal" class="calculator-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label><span>Cantidad de bancas</span><select name="units" required><option value="">Seleccionar…</option><?php for ($units = 1; $units <= 5; $units++): ?><option value="<?= $units ?>"><?= $units ?> banca<?= $units === 1 ? '' : 's' ?></option><?php endfor; ?></select></label>
                        <label><span>Monto</span><input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required></label>
                        <label><span>Cuentas</span><input type="text" name="accounts" placeholder="Cuenta 1, Cuenta 2…" required></label>
                        <button type="submit" class="button primary">Calcular</button>
                    </form>
                    <div class="calculator-table" data-calculator-result hidden><table><thead><tr><th>Salida / cuenta</th><th>Monto</th></tr></thead><tbody data-dispersal-rows></tbody><tfoot><tr><th>Total</th><th data-dispersal-total></th></tr></tfoot></table></div>
                </div>
                <div class="glass-modal-actions"><button type="button" class="button" data-calculator-close>Cerrar</button></div>
            </section>
        </div>

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

<div class="third-party-loader" id="thirdPartyLoader" role="status" aria-live="polite" aria-busy="true" hidden>
    <div class="third-party-loader-card">
        <span class="third-party-loader-spinner" aria-hidden="true"></span>
        <div><strong id="thirdPartyLoaderTitle">Procesando solicitud</strong><p id="thirdPartyLoaderMessage">Conectando con el servicio externo…</p></div>
    </div>
</div>

<div class="record-context-menu" id="recordContextMenu" role="menu" aria-label="Acciones del registro" hidden></div>

<div class="glass-modal-backdrop" id="complementStampModal" hidden>
    <section class="glass-modal fiscal-treatment-modal" role="dialog" aria-modal="true" aria-labelledby="complementStampTitle">
        <div class="glass-modal-icon">✓</div>
        <div class="glass-modal-copy"><p class="eyebrow">Emisión fiscal</p><h2 id="complementStampTitle">Timbrar complemento</h2><p>Selecciona el tratamiento con el que se emitirá el CFDI de pago.</p></div>
        <div class="fiscal-treatment-options">
            <button type="button" data-stamp-treatment="T"><strong>Típico</strong><span>IVA 16%</span></button>
            <button type="button" data-stamp-treatment="T0"><strong>Tasa 0</strong><span>IVA 0%</span></button>
            <button type="button" data-stamp-treatment="SV"><strong>Sin IVA</strong><span>Operación no gravada</span></button>
            <button type="button" disabled title="El sistema fiscal anterior no implementa este timbrado"><strong>Retención 4%</strong><span>No disponible</span></button>
        </div>
        <div class="glass-modal-actions"><button type="button" class="button" id="complementStampCancel">Cancelar</button></div>
    </section>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const thirdPartyLoader = document.getElementById('thirdPartyLoader');
    const thirdPartyLoaderTitle = document.getElementById('thirdPartyLoaderTitle');
    const thirdPartyLoaderMessage = document.getElementById('thirdPartyLoaderMessage');
    let thirdPartyLoaderTimer = null;
    const hideThirdPartyLoader = () => {
        window.clearTimeout(thirdPartyLoaderTimer);
        thirdPartyLoader.classList.remove('is-visible');
        thirdPartyLoader.hidden = true;
        document.body.classList.remove('has-third-party-loader');
    };
    const showThirdPartyLoader = (title, message, autoHide = 0) => {
        window.clearTimeout(thirdPartyLoaderTimer);
        thirdPartyLoaderTitle.textContent = title;
        thirdPartyLoaderMessage.textContent = message;
        thirdPartyLoader.hidden = false;
        document.body.classList.add('has-third-party-loader');
        window.requestAnimationFrame(() => thirdPartyLoader.classList.add('is-visible'));
        if (autoHide > 0) {
            thirdPartyLoaderTimer = window.setTimeout(() => {
                thirdPartyLoader.classList.remove('is-visible');
                document.body.classList.remove('has-third-party-loader');
                window.setTimeout(() => { thirdPartyLoader.hidden = true; }, 180);
            }, autoHide);
        }
    };
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) hideThirdPartyLoader();
    });

    const thirdPartyOperations = {
        invoice_stamp: ['Timbrando factura', 'Conectando con Ultra Sistema 14. Esto puede tardar unos segundos…'],
        invoice_resend: ['Reenviando factura', 'Solicitando el reenvío al servicio de Ultra Sistema 14…'],
        complement_stamp: ['Timbrando complemento', 'Conectando con Ultra Sistema 14. Esto puede tardar unos segundos…'],
        complement_resend: ['Reenviando complemento', 'Solicitando el reenvío al servicio de Ultra Sistema 14…'],
    };

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = form.querySelector('[name="action"]')?.value || '';
        const operation = thirdPartyOperations[action];
        if (operation) showThirdPartyLoader(operation[0], operation[1]);
    });

    document.querySelectorAll('a[href*="ultra.sistema14.com"], a[href*="old.sistema14.com"]').forEach((link) => {
        link.addEventListener('click', () => {
            showThirdPartyLoader('Abriendo documento', 'Solicitando el archivo a Sistema 14…', 1200);
        });
    });

    document.querySelectorAll('.toast-close').forEach((button) => {
        button.addEventListener('click', () => button.closest('.glass-toast')?.remove());
    });

    document.querySelectorAll('[data-auto-dismiss]').forEach((toast) => {
        window.setTimeout(() => {
            toast.classList.add('is-leaving');
            window.setTimeout(() => toast.remove(), 240);
        }, 5200);
    });

    const recordContextMenu = document.getElementById('recordContextMenu');
    let activeRecordRow = null;
    const closeRecordMenu = () => {
        recordContextMenu.hidden = true;
        recordContextMenu.replaceChildren();
        activeRecordRow?.classList.remove('has-open-menu');
        activeRecordRow = null;
    };
    const openRecordMenu = (row, x, y) => {
        closeRecordMenu();
        const actions = Array.from(row.querySelectorAll('.record-action-source [data-record-action]'));
        if (actions.length === 0) return;
        actions.forEach((original) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'record-context-item';
            if (original.hasAttribute('data-action-danger')) item.classList.add('is-danger');
            const icon = document.createElement('span');
            icon.textContent = original.dataset.actionIcon || '•';
            const label = document.createElement('span');
            label.textContent = original.dataset.actionLabel || original.textContent.trim();
            item.append(icon, label);
            item.addEventListener('click', () => {
                closeRecordMenu();
                original.click();
            });
            recordContextMenu.appendChild(item);
        });
        recordContextMenu.hidden = false;
        row.classList.add('has-open-menu');
        activeRecordRow = row;
        const width = Math.max(220, recordContextMenu.offsetWidth);
        const height = recordContextMenu.offsetHeight;
        recordContextMenu.style.left = `${Math.max(8, Math.min(window.innerWidth - width - 8, x))}px`;
        recordContextMenu.style.top = `${Math.max(8, Math.min(window.innerHeight - height - 8, y))}px`;
        recordContextMenu.querySelector('button')?.focus();
    };
    document.querySelectorAll('[data-record-row]').forEach((row) => {
        row.addEventListener('contextmenu', (event) => {
            if (event.target.closest('a, button, input, select, textarea')) return;
            event.preventDefault();
            openRecordMenu(row, event.clientX, event.clientY);
        });
        row.querySelector('[data-record-menu-trigger]')?.addEventListener('click', (event) => {
            const rect = event.currentTarget.getBoundingClientRect();
            openRecordMenu(row, rect.right - 220, rect.bottom + 6);
        });
    });
    document.addEventListener('pointerdown', (event) => {
        if (!recordContextMenu.hidden && !recordContextMenu.contains(event.target)
            && !event.target.closest('[data-record-menu-trigger]')) closeRecordMenu();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !recordContextMenu.hidden) closeRecordMenu();
    });
    window.addEventListener('resize', closeRecordMenu);
    document.addEventListener('scroll', closeRecordMenu, true);

    document.querySelectorAll('[data-proxy-action]').forEach((button) => {
        button.addEventListener('click', () => document.getElementById(button.dataset.proxyAction)?.click());
    });

    document.querySelectorAll('[data-copy-uuid]').forEach((button) => {
        button.addEventListener('click', async () => {
            const uuid = button.dataset.copyUuid || '';
            if (!uuid) return;
            try {
                let copied = false;
                if (navigator.clipboard?.writeText) {
                    try {
                        await navigator.clipboard.writeText(uuid);
                        copied = true;
                    } catch (error) {
                        copied = false;
                    }
                }
                if (!copied) {
                    const helper = document.createElement('textarea');
                    helper.value = uuid;
                    helper.style.position = 'fixed';
                    helper.style.opacity = '0';
                    document.body.appendChild(helper);
                    helper.select();
                    copied = document.execCommand('copy');
                    helper.remove();
                }
                if (!copied) throw new Error('No se pudo copiar');
                showToast('UUID copiado al portapapeles.', 'success');
            } catch (error) {
                showToast('No fue posible copiar el UUID al portapapeles.');
            }
        });
    });

    const stampModal = document.getElementById('complementStampModal');
    const stampCancel = document.getElementById('complementStampCancel');
    let pendingComplementStamp = null;
    const closeStampModal = () => {
        stampModal.classList.remove('is-visible');
        window.setTimeout(() => { stampModal.hidden = true; }, 160);
        pendingComplementStamp = null;
    };
    document.querySelectorAll('[data-complement-stamp-trigger]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.getElementById(`complementStamp${button.dataset.complementId}`);
            if (!form) return;
            pendingComplementStamp = form;
            stampModal.hidden = false;
            window.requestAnimationFrame(() => stampModal.classList.add('is-visible'));
            stampModal.querySelector(`[data-stamp-treatment="${form.querySelector('[name="treatment"]').value}"]`)?.focus();
        });
    });
    stampModal.querySelectorAll('[data-stamp-treatment]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!pendingComplementStamp) return;
            const form = pendingComplementStamp;
            form.querySelector('[name="treatment"]').value = button.dataset.stampTreatment;
            pendingComplementStamp = null;
            stampModal.classList.remove('is-visible');
            stampModal.hidden = true;
            form.requestSubmit();
        });
    });
    stampCancel.addEventListener('click', closeStampModal);
    stampModal.addEventListener('click', (event) => { if (event.target === stampModal) closeStampModal(); });

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

    const normalizeSearchText = (value) => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    let openSearchSelect = null;
    const closeSearchSelect = () => {
        if (!openSearchSelect) return;
        openSearchSelect.wrapper.classList.remove('is-open');
        openSearchSelect.button.setAttribute('aria-expanded', 'false');
        openSearchSelect.menu.remove();
        openSearchSelect = null;
    };

    document.querySelectorAll('select[data-client-picker]').forEach((select, selectIndex) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'search-select';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'search-select-trigger';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', select.getAttribute('aria-label') || 'Seleccionar cartera');
        const buttonLabel = document.createElement('span');
        const chevron = document.createElement('span');
        chevron.className = 'search-select-chevron';
        chevron.textContent = '⌄';
        button.append(buttonLabel, chevron);
        select.classList.add('search-select-native');
        select.insertAdjacentElement('afterend', wrapper);
        wrapper.append(select, button);

        const options = Array.from(select.options).map((option) => ({
            value: option.value,
            label: option.textContent.trim(),
            disabled: option.disabled,
            search: normalizeSearchText(option.textContent),
        }));
        const refreshLabel = () => {
            const selected = select.options[select.selectedIndex];
            buttonLabel.textContent = selected?.textContent.trim() || 'Seleccionar cartera…';
            button.classList.toggle('has-value', Boolean(select.value));
        };
        refreshLabel();
        select.addEventListener('change', () => {
            button.classList.remove('is-invalid');
            refreshLabel();
        });
        select.addEventListener('invalid', (event) => {
            event.preventDefault();
            button.classList.add('is-invalid');
            button.focus();
        });

        button.addEventListener('click', () => {
            if (openSearchSelect?.select === select) {
                closeSearchSelect();
                return;
            }
            closeSearchSelect();
            const menu = document.createElement('div');
            menu.className = 'search-select-menu';
            menu.setAttribute('role', 'dialog');
            menu.setAttribute('aria-label', 'Buscar y seleccionar cartera');
            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'search-select-input';
            search.placeholder = 'Buscar cartera por nombre o ID…';
            search.setAttribute('aria-label', 'Buscar cartera');
            const list = document.createElement('div');
            list.className = 'search-select-options';
            list.setAttribute('role', 'listbox');
            const status = document.createElement('p');
            status.className = 'search-select-status';

            const renderOptions = () => {
                const term = normalizeSearchText(search.value.trim());
                const filtered = options.filter((option) => option.value !== '' && !option.disabled && option.search.includes(term));
                list.replaceChildren();
                filtered.slice(0, 150).forEach((option) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'search-select-option';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', String(select.value === option.value));
                    item.textContent = option.label;
                    item.addEventListener('click', () => {
                        select.value = option.value;
                        select.dispatchEvent(new Event('change', {bubbles: true}));
                        refreshLabel();
                        closeSearchSelect();
                        button.focus();
                    });
                    list.appendChild(item);
                });
                status.textContent = filtered.length === 0
                    ? 'No se encontraron carteras.'
                    : `${filtered.length} cartera${filtered.length === 1 ? '' : 's'} encontrada${filtered.length === 1 ? '' : 's'}${filtered.length > 150 ? ' · mostrando las primeras 150' : ''}`;
            };
            search.addEventListener('input', renderOptions);
            search.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') { closeSearchSelect(); button.focus(); }
                if (event.key === 'Enter') { event.preventDefault(); list.querySelector('.search-select-option')?.click(); }
                if (event.key === 'ArrowDown') { event.preventDefault(); list.querySelector('.search-select-option')?.focus(); }
            });
            list.addEventListener('keydown', (event) => {
                const items = Array.from(list.querySelectorAll('.search-select-option'));
                const current = items.indexOf(document.activeElement);
                if (event.key === 'ArrowDown') { event.preventDefault(); items[Math.min(items.length - 1, current + 1)]?.focus(); }
                if (event.key === 'ArrowUp') { event.preventDefault(); current <= 0 ? search.focus() : items[current - 1]?.focus(); }
                if (event.key === 'Escape') { closeSearchSelect(); button.focus(); }
            });
            menu.append(search, list, status);
            document.body.appendChild(menu);
            const rect = button.getBoundingClientRect();
            const menuWidth = Math.max(290, Math.min(390, rect.width));
            const spaceBelow = window.innerHeight - rect.bottom;
            menu.style.width = `${menuWidth}px`;
            menu.style.left = `${Math.max(10, Math.min(window.innerWidth - menuWidth - 10, rect.left))}px`;
            if (spaceBelow >= 330 || rect.top < spaceBelow) {
                menu.style.top = `${rect.bottom + 6}px`;
            } else {
                menu.style.bottom = `${window.innerHeight - rect.top + 6}px`;
            }
            wrapper.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            openSearchSelect = {select, wrapper, button, menu, id: selectIndex};
            renderOptions();
            search.focus();
        });
    });

    document.addEventListener('pointerdown', (event) => {
        if (openSearchSelect && !openSearchSelect.menu.contains(event.target) && !openSearchSelect.wrapper.contains(event.target)) closeSearchSelect();
    });
    window.addEventListener('resize', closeSearchSelect);
    document.addEventListener('scroll', (event) => {
        if (openSearchSelect && !openSearchSelect.menu.contains(event.target)) closeSearchSelect();
    }, true);

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

    const complementClient = document.getElementById('complementClient');
    const complementCompany = document.getElementById('complementCompany');
    const complementReceiver = document.getElementById('complementReceiver');
    const complementLines = document.getElementById('complementLines');
    const complementForm = document.getElementById('complementForm');
    const addComplementInvoice = document.getElementById('addComplementInvoice');

    const recalculateComplement = () => {
        if (!complementLines) return;
        const rows = Array.from(complementLines.querySelectorAll('tr[data-complement-line]'));
        const total = rows.reduce((sum, row) => sum + (Number(row.querySelector('[name="amount[]"]').value) || 0), 0);
        document.getElementById('complementCount').textContent = String(rows.filter((row) => row.dataset.validated === 'true').length);
        document.getElementById('complementTotal').textContent = invoiceMoney.format(total);
        rows.forEach((row) => {
            const balance = Number(row.dataset.balance) || 0;
            const amount = Number(row.querySelector('[name="amount[]"]').value) || 0;
            row.querySelector('[data-complement-remaining]').textContent = invoiceMoney.format(Math.max(0, balance - amount));
        });
    };

    const resetComplementLine = (row) => {
        row.dataset.validated = 'false';
        row.dataset.balance = '0';
        row.querySelector('[data-complement-invoice]').textContent = 'UUID pendiente de consulta';
        row.querySelector('[data-complement-balance]').textContent = '—';
        row.querySelector('[data-complement-remaining]').textContent = '—';
        const amount = row.querySelector('[name="amount[]"]');
        amount.value = '';
        amount.disabled = true;
        recalculateComplement();
    };

    const lookupComplementInvoice = async (row) => {
        const uuidInput = row.querySelector('[name="invoice_uuid[]"]');
        const button = row.querySelector('[data-complement-lookup]');
        const uuid = uuidInput.value.trim();
        if (!uuid || !complementClient.value || !complementCompany.value || !complementReceiver.value) {
            showToast('Selecciona emisor, cliente y razón social, y captura el UUID de la factura.');
            return;
        }
        button.disabled = true;
        button.textContent = 'Consultando…';
        try {
            const response = await fetch(`?complement=create&data=invoice&uuid=${encodeURIComponent(uuid)}`, {headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.ok || !result.data) throw new Error('No se encontró una factura con ese UUID.');
            const invoice = result.data;
            if (Number(invoice.metodo_pago) !== 2) throw new Error('La factura encontrada no usa el método PPD.');
            if (String(invoice.client_id) !== complementClient.value || String(invoice.company_id) !== complementCompany.value || String(invoice.receiver_id) !== complementReceiver.value) {
                throw new Error('La factura no corresponde al cliente, emisor y razón social seleccionados.');
            }
            if (Number(invoice.saldo) <= 0) throw new Error('La factura ya no tiene saldo pendiente.');
            const duplicate = Array.from(complementLines.querySelectorAll('[name="invoice_uuid[]"]')).some((item) => item !== uuidInput && item.value.trim().toUpperCase() === String(invoice.uuid).toUpperCase());
            if (duplicate) throw new Error('Esta factura ya fue agregada al complemento.');
            uuidInput.value = invoice.uuid;
            row.dataset.validated = 'true';
            row.dataset.balance = String(invoice.saldo);
            row.querySelector('[data-complement-invoice]').textContent = `#${invoice.id} · ${(invoice.serie || '')}${invoice.folio || ''} · ${invoice.cliente}`;
            row.querySelector('[data-complement-balance]').textContent = invoiceMoney.format(Number(invoice.saldo));
            const amount = row.querySelector('[name="amount[]"]');
            amount.disabled = false;
            amount.max = String(invoice.saldo);
            amount.value = String(invoice.saldo);
            row.querySelector('[name="installment[]"]').value = String(invoice.siguiente_parcialidad || 1);
            recalculateComplement();
        } catch (error) {
            resetComplementLine(row);
            showToast(error instanceof Error ? error.message : 'No fue posible consultar la factura.');
        } finally {
            button.disabled = false;
            button.textContent = 'Consultar';
        }
    };

    const addComplementLine = () => {
        if (!complementLines) return;
        const row = document.createElement('tr');
        row.dataset.complementLine = 'true';
        row.dataset.validated = 'false';
        row.dataset.balance = '0';
        row.innerHTML = '<td><div class="complement-uuid-control"><input type="text" name="invoice_uuid[]" maxlength="50" placeholder="UUID fiscal" required><button type="button" class="button" data-complement-lookup>Consultar</button></div><small data-complement-invoice>UUID pendiente de consulta</small></td><td data-complement-balance>—</td><td><input type="number" name="amount[]" min="0.01" step="0.01" required disabled></td><td><input type="number" name="installment[]" min="1" step="1" value="1" required></td><td data-complement-remaining>—</td><td><button type="button" class="button danger invoice-remove-line" data-complement-remove>×</button></td>';
        const uuid = row.querySelector('[name="invoice_uuid[]"]');
        uuid.addEventListener('input', () => resetComplementLine(row));
        row.querySelector('[data-complement-lookup]').addEventListener('click', () => lookupComplementInvoice(row));
        row.querySelector('[name="amount[]"]').addEventListener('input', recalculateComplement);
        row.querySelector('[data-complement-remove]').addEventListener('click', () => {
            row.remove();
            if (!complementLines.querySelector('tr[data-complement-line]')) addComplementLine();
            recalculateComplement();
        });
        complementLines.appendChild(row);
        recalculateComplement();
    };

    complementClient?.addEventListener('change', async () => {
        if (!complementReceiver) return;
        await loadInvoiceCatalog('receivers', complementClient.value, complementReceiver);
        complementLines?.querySelectorAll('tr[data-complement-line]').forEach(resetComplementLine);
    });
    [complementCompany, complementReceiver].forEach((control) => control?.addEventListener('change', () => complementLines?.querySelectorAll('tr[data-complement-line]').forEach(resetComplementLine)));
    addComplementInvoice?.addEventListener('click', addComplementLine);
    if (complementLines) {
        addComplementLine();
        const prefillUuid = complementForm?.dataset.prefillInvoiceUuid;
        if (prefillUuid) {
            const row = complementLines.querySelector('tr[data-complement-line]');
            const uuid = row.querySelector('[name="invoice_uuid[]"]');
            uuid.value = prefillUuid;
            uuid.readOnly = true;
            row.querySelector('[data-complement-remove]').disabled = true;
            lookupComplementInvoice(row).then(() => {
                if (row.dataset.validated === 'true') {
                    row.querySelector('[data-complement-lookup]').hidden = true;
                }
            });
        }
    }
    complementForm?.addEventListener('submit', (event) => {
        const rows = Array.from(complementLines.querySelectorAll('tr[data-complement-line]'));
        if (rows.length === 0 || rows.some((row) => row.dataset.validated !== 'true')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showToast('Consulta y valida todas las facturas antes de guardar el complemento.');
        }
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

    const appendCalculatorCell = (row, text, tag = 'td', className = '') => {
        const cell = document.createElement(tag);
        cell.textContent = text;
        if (className) cell.className = className;
        row.appendChild(cell);
        return cell;
    };

    let activeCalculatorModal = null;
    const closeCalculatorModal = (modal = activeCalculatorModal) => {
        if (!modal) return;
        modal.classList.remove('is-visible');
        if (activeCalculatorModal === modal) activeCalculatorModal = null;
        document.body.classList.remove('has-calculator-modal');
        window.setTimeout(() => {
            if (!modal.classList.contains('is-visible')) modal.hidden = true;
        }, 180);
    };

    document.querySelectorAll('[data-calculator-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-calculator-modal="${button.dataset.calculatorOpen}"]`);
            if (!modal) return;
            if (activeCalculatorModal && activeCalculatorModal !== modal) closeCalculatorModal(activeCalculatorModal);
            activeCalculatorModal = modal;
            modal.hidden = false;
            document.body.classList.add('has-calculator-modal');
            window.requestAnimationFrame(() => {
                modal.classList.add('is-visible');
                modal.querySelector('select, input, button')?.focus();
            });
        });
    });

    document.querySelectorAll('[data-calculator-modal]').forEach((modal) => {
        modal.querySelectorAll('[data-calculator-close]').forEach((button) => {
            button.addEventListener('click', () => closeCalculatorModal(modal));
        });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeCalculatorModal(modal);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeCalculatorModal) closeCalculatorModal();
    });

    document.querySelectorAll('[data-dashboard-calculator]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const type = form.dataset.dashboardCalculator;
            const button = form.querySelector('button[type="submit"]');
            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Calculando…';
            try {
                const response = await fetch(`?calculator=${encodeURIComponent(type)}`, {
                    method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json'},
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'No fue posible realizar el cálculo.');
                const output = form.parentElement.querySelector('[data-calculator-result]');
                output.hidden = false;
                if (type === 'balance') {
                    output.querySelector('[data-balance-result]').textContent = invoiceMoney.format(Number(result.data.result));
                    output.querySelector('[data-balance-detail]').textContent = `${result.data.scheme} · Comisión total ${Number(result.data.percentage).toFixed(2)}%`;
                } else if (type === 'banking') {
                    const body = output.querySelector('[data-banking-rows]');
                    body.replaceChildren();
                    result.data.rows.forEach((item) => {
                        const row = document.createElement('tr');
                        appendCalculatorCell(row, item.provider);
                        appendCalculatorCell(row, invoiceMoney.format(Number(item.amount)));
                        appendCalculatorCell(row, `${Number(item.calculated_percentage).toFixed(4)}%`);
                        appendCalculatorCell(row, `${Number(item.suggested_percentage).toFixed(2)}%`);
                        body.appendChild(row);
                    });
                    output.querySelector('[data-banking-total]').textContent = invoiceMoney.format(Number(result.data.total));
                } else if (type === 'dispersal') {
                    const body = output.querySelector('[data-dispersal-rows]');
                    body.replaceChildren();
                    result.data.rows.forEach((item) => {
                        const parent = document.createElement('tr');
                        parent.className = 'calculator-parent-row';
                        appendCalculatorCell(parent, `Banca ${item.name}`);
                        appendCalculatorCell(parent, invoiceMoney.format(Number(item.amount)));
                        body.appendChild(parent);
                        item.accounts.forEach((account) => {
                            const child = document.createElement('tr');
                            child.className = 'calculator-child-row';
                            appendCalculatorCell(child, account.name);
                            appendCalculatorCell(child, invoiceMoney.format(Number(account.amount)));
                            body.appendChild(child);
                        });
                    });
                    output.querySelector('[data-dispersal-total]').textContent = invoiceMoney.format(Number(result.data.total));
                }
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'No fue posible realizar el cálculo.');
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        });
    });

    const captureAnalyzer = document.getElementById('captureAnalyzer');
    const captureEditor = document.getElementById('captureEditor');
    const captureResult = document.getElementById('captureAnalysisResult');
    const captureRows = document.getElementById('captureAnalysisRows');
    const captureSummary = document.getElementById('captureAnalysisSummary');
    const captureMessage = document.getElementById('captureAnalysisMessage');
    const analyzeCaptureButton = document.getElementById('analyzeCapture');

    const appendCaptureSummary = (label, value, detail) => {
        const card = document.createElement('article');
        const labelNode = document.createElement('span');
        const valueNode = document.createElement('strong');
        const detailNode = document.createElement('small');
        labelNode.textContent = label;
        valueNode.textContent = String(value);
        detailNode.textContent = detail;
        card.append(labelNode, valueNode, detailNode);
        captureSummary.appendChild(card);
    };

    captureAnalyzer?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const capture = captureEditor.innerHTML.trim();
        if (capture === '' || capture === '<br>') {
            showToast('Pega primero el contenido de la captura bancaria.');
            captureEditor.focus();
            return;
        }
        if (capture.length > 2_000_000) {
            showToast('La captura supera el límite de 2 MB para el análisis.');
            return;
        }

        analyzeCaptureButton.disabled = true;
        analyzeCaptureButton.textContent = 'Analizando…';
        captureResult.hidden = true;
        try {
            const parserPayload = new FormData();
            parserPayload.set('csrf_token', captureAnalyzer.querySelector('[name="csrf_token"]').value);
            parserPayload.set('capture', capture);
            parserPayload.set('account_id', captureAnalyzer.dataset.accountId);
            const parserResponse = await fetch(captureAnalyzer.dataset.parserUrl, {
                method: 'POST', body: parserPayload, headers: {'Accept': 'application/json'}
            });
            const parserResult = await parserResponse.json();
            if (!parserResponse.ok || !parserResult.ok) throw new Error(parserResult.message || 'No se detectaron movimientos válidos.');
            const analysis = parserResult.analysis;

            const movementCount = analysis.movements.length;
            if (movementCount < 1) throw new Error('No se detectaron movimientos en la captura.');
            const descriptions = analysis.movements.map((movement) => [
                movement.description,
                movement.detail,
                movement.reference,
            ].filter(Boolean).join(' '));

            const keyPayload = new FormData();
            keyPayload.set('csrf_token', captureAnalyzer.querySelector('[name="csrf_token"]').value);
            keyPayload.set('account_id', captureAnalyzer.dataset.accountId);
            keyPayload.set('descriptions', JSON.stringify(descriptions));
            const keyResponse = await fetch(`?companies=analyze&id=${encodeURIComponent(captureAnalyzer.dataset.companyId)}&data=short_keys`, {
                method: 'POST', body: keyPayload, headers: {'Accept': 'application/json'}
            });
            const keyResult = await keyResponse.json();
            if (!keyResponse.ok || !keyResult.ok) throw new Error(keyResult.message || 'No fue posible consultar las claves cortas.');

            captureRows.replaceChildren();
            captureSummary.replaceChildren();
            let associated = 0;
            keyResult.matches.forEach((match, index) => {
                if (match.associated) associated += 1;
                const movement = analysis.movements[index];
                const row = document.createElement('tr');
                const cells = [
                    String(index + 1),
                    movement.date || '—',
                    descriptions[index] || 'Sin descripción',
                    movement.type || '—',
                    invoiceMoney.format(Number(movement.amount) || 0),
                    movement.duplicate ? 'Sí' : 'No',
                    match.associated ? `Sí · ${match.key} · ${match.client}` : 'No · Sin clave asociada',
                ];
                cells.forEach((value, cellIndex) => {
                    const cell = document.createElement('td');
                    cell.textContent = value;
                    if (cellIndex === 6) cell.className = match.associated ? 'short-key-found' : 'short-key-missing';
                    row.appendChild(cell);
                });
                captureRows.appendChild(row);
            });

            appendCaptureSummary('Detectados', movementCount, 'Movimientos leídos');
            appendCaptureSummary('Nuevos', Number(analysis.summary.new) || 0, 'No encontrados previamente');
            appendCaptureSummary('Con clave corta', associated, 'Asociación encontrada');
            appendCaptureSummary('Sin clave corta', movementCount - associated, 'Requieren revisión');
            captureMessage.textContent = `${associated} de ${movementCount} movimientos tienen una clave corta asociada en la tabla claves_cortas.`;
            captureResult.hidden = false;
            captureResult.scrollIntoView({behavior: 'smooth', block: 'start'});
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'No fue posible analizar la captura.');
        } finally {
            analyzeCaptureButton.disabled = false;
            analyzeCaptureButton.textContent = 'Analizar antes de cargar';
        }
    });

    document.getElementById('clearCaptureAnalysis')?.addEventListener('click', () => {
        captureEditor.replaceChildren();
        captureRows?.replaceChildren();
        captureSummary?.replaceChildren();
        if (captureResult) captureResult.hidden = true;
        captureEditor.focus();
    });

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
