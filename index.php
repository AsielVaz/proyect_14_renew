<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/auth.php';

send_security_headers();
handle_authentication();

use App\Catalogs;
use App\ManualEmailService;
use App\Movements;
use App\Reports;
use App\Statistics;

if (isset($_GET['global_search']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
    if (mb_strlen($query) < 2) {
        echo json_encode(['ok' => true, 'query' => $query, 'results' => [
            'payments' => [], 'invoices' => [], 'captures' => [],
        ], 'total' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    try {
        $results = global_search_repository()->search($query, current_operator_id());
        $total = array_sum(array_map('count', $results));
        echo json_encode(['ok' => true, 'query' => $query, 'results' => $results, 'total' => $total],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible completar la búsqueda. Inténtalo nuevamente.'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['wallet_balance']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $clientId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$clientId) {
            throw new RuntimeException('La cartera seleccionada no es válida.');
        }
        $data = dashboard_repository()->walletAccreditableBalance((int) $clientId, current_operator_id());
        echo json_encode(['ok' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('Wallet balance refresh: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible actualizar el saldo. Inténtalo nuevamente.'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['document']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        current_operator_id();
        $documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$documentId) {
            throw new RuntimeException('El documento solicitado no es válido.');
        }
        $document = match ((string) $_GET['document']) {
            'invoice_pdf' => pdf_document_service()->invoice((int) $documentId),
            'complement_pdf' => pdf_document_service()->complement((int) $documentId),
            default => throw new RuntimeException('Tipo de documento no disponible.'),
        };
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $document['filename'] . '"');
        header('Content-Length: ' . strlen($document['bytes']));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $document['bytes'];
    } catch (RuntimeException $exception) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('PDF fiscal local: ' . $exception->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'No fue posible generar el documento PDF.';
    }
    exit;
}

if (isset($_GET['invoice_email']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        verify_csrf();
        $result = invoice_email_service()->send(
            (int) ($_POST['invoice_id'] ?? 0),
            (string) ($_POST['email'] ?? ''),
            current_operator_id()
        );
        $accepted = in_array((string) ($result['status'] ?? ''), ['sent', 'queued', 'scheduled'], true);
        if (!$accepted) http_response_code(422);
        echo json_encode(['ok' => $accepted, 'result' => $result, 'message' => $result['message'] ?? ''],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('Invoice email: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible confirmar el envío. Inténtalo nuevamente.'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$catalogs = Catalogs::all();
$movements = Movements::allowedFor(current_operator_id());
$reports = Reports::all();
$statistics = Statistics::all();
$key = (string) ($_GET['catalog'] ?? '');
$catalog = $key !== '' ? Catalogs::get($key) : null;
$reportKey = (string) ($_GET['report'] ?? '');
$report = $reportKey !== '' ? Reports::get($reportKey) : null;
$movementKey = (string) ($_GET['movement'] ?? '');
$movement = $movementKey !== '' ? ($movements[$movementKey] ?? null) : null;
$knownMovement = $movementKey !== '' ? Movements::get($movementKey) : null;
if ($knownMovement && !$movement) {
    flash('error', 'No tienes permiso para abrir ese módulo.');
    redirect('./');
}
$movementAction = $movement['action'] ?? null;
$invoiceView = (string) ($_GET['invoice'] ?? '');
$invoiceModule = in_array($invoiceView, ['list', 'create', 'complements'], true);
$complementView = (string) ($_GET['complement'] ?? '');
$complementModule = in_array($complementView, ['list', 'create', 'detail'], true);
$companyView = (string) ($_GET['companies'] ?? '');
$companyModule = in_array($companyView, ['list', 'detail', 'captures', 'capture', 'movements', 'account', 'analyze'], true);
$statisticsKey = (string) ($_GET['statistics'] ?? '');
$statistic = $statisticsKey !== '' ? Statistics::get($statisticsKey) : null;
$statisticsModule = $statistic !== null;
$dashboardModule = !$catalog && !$report && !$movement && !$invoiceModule && !$complementModule && !$companyModule && !$statisticsModule;
$action = (string) ($_GET['action'] ?? 'list');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if ($movementKey === 'correos-manuales' && isset($_GET['mail_action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        verify_csrf();
        if (!ManualEmailService::isAllowed(current_operator_id())) {
            throw new RuntimeException('No tienes permiso para utilizar este módulo.');
        }
        $result = match ((string) $_GET['mail_action']) {
            'generate' => manual_email_service()->generate($_POST),
            'review' => manual_email_service()->review($_POST, $_FILES),
            'send' => manual_email_service()->send($_POST, $_FILES),
            default => throw new RuntimeException('Acción de correo no permitida.'),
        };
        echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('ManualEmail: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible completar la operación. Inténtalo nuevamente.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

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

if ($companyView === 'analyze' && in_array((string) ($_GET['data'] ?? ''), ['parse', 'short_keys', 'load'], true)
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
        } elseif (($_GET['data'] ?? '') === 'load') {
            $result = capture_analysis_service()->load(
                (int) ($id ?? 0), (int) ($_POST['account_id'] ?? 0),
                (string) ($_POST['capture'] ?? ''), current_operator_id()
            );
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $data = match ((string) $_GET['data']) {
            'receivers' => payment_complement_repository()->receivers((int) ($_GET['client'] ?? 0)),
            'invoice' => payment_complement_repository()->invoiceByUuid((string) ($_GET['uuid'] ?? '')),
            'pending_invoices' => payment_complement_repository()->pendingInvoicesForPayment(
                (int) ($_GET['payment_id'] ?? 0),
                (int) ($_GET['receiver_id'] ?? 0)
            ),
            default => throw new RuntimeException('Consulta de complementos desconocida.'),
        };
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (RuntimeException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('Complement data: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'No fue posible consultar la información de complementos.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
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
                $invoiceId = (int) ($_POST['id'] ?? 0);
                $operatorId = current_operator_id();
                ignore_user_abort(true);
                @set_time_limit(360);
                $message = fiscal_document_service()->stampInvoice($invoiceId, $operatorId);
                try {
                    $emailResult = invoice_email_service()->sendToInvoiceContact($invoiceId, $operatorId, true, true);
                    $message .= ' ' . ($emailResult['status'] === 'skipped'
                        ? $emailResult['message']
                        : 'Se prepararon el PDF y el XML para el correo del cliente. ' . $emailResult['message']);
                } catch (Throwable $emailException) {
                    error_log('Invoice automatic email #' . $invoiceId . ': ' . $emailException->getMessage());
                    $message .= ' La factura quedó timbrada, pero el correo automático no pudo completarse: ' . $emailException->getMessage();
                }
                flash('success', $message);
                redirect('?invoice=list');
            } elseif ($postAction === 'invoice_resend') {
                $invoiceId = (int) ($_POST['id'] ?? 0);
                ignore_user_abort(true);
                @set_time_limit(180);
                $result = invoice_email_service()->sendToInvoiceContact($invoiceId, current_operator_id(), true, true);
                if (($result['status'] ?? '') === 'skipped') {
                    throw new RuntimeException((string) $result['message']);
                }
                flash('success', 'Se prepararon el PDF moderno y el XML de Sistema 14 para el correo del cliente. ' . ($result['message'] ?? ''));
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
$complementPaymentPrefill = null;
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
} elseif ($complementView === 'create' && isset($_GET['payment_id'])) {
    try {
        $sourcePaymentId = filter_var($_GET['payment_id'], FILTER_VALIDATE_INT) ?: 0;
        $complementPaymentPrefill = payment_complement_repository()->paymentPrefill($sourcePaymentId);
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect('?movement=asignados');
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
            $companyResult = company_panel_repository()->companies(
                trim((string) ($_GET['q'] ?? '')),
                $companyPage,
                $operatorId,
                (int) ($_GET['per_page'] ?? 6),
                (string) ($_GET['sort'] ?? 'id_asc')
            );
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
$statisticsError = null;
$statisticsResult = null;
$statisticsClients = [];
if ($statisticsModule) {
    try {
        $statisticsClients = ($statistic['clientFilter'] ?? false) ? statistics_repository()->clients() : [];
        $statisticsResult = statistics_repository()->run($statisticsKey, $_GET);
    } catch (Throwable $exception) {
        $statisticsError = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= e(app_url('/')) ?>">
    <title><?= e($statistic['label'] ?? ($companyModule ? ($companyData['razon'] ?? 'Empresas') : ($complementModule ? ($complementView === 'create' ? 'Nuevo complemento de pago' : ($complementView === 'detail' ? 'Facturas asociadas al complemento' : 'Complementos de pago')) : ($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : ($invoiceView === 'complements' ? 'Complementos asociados' : 'Facturas')) : ($catalog['label'] ?? $report['label'] ?? $movement['label'] ?? 'Sistema 14'))))) ?> · Sistema 14</title>
    <!-- theme.css contiene todos los tokens visuales; app.css define componentes. -->
    <link rel="stylesheet" href="assets/theme.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $companyModule ? 'company-module' : '' ?><?= $companyView === 'list' ? ' company-directory-module' : '' ?><?= $companyView === 'detail' ? ' company-detail-module' : '' ?>">
<a class="skip-link" href="#main-content">Saltar al contenido</a>
<aside class="sidebar" id="s14-sidebar">
    <div class="sidebar-brand-row">
        <a class="brand" href="./" aria-label="Ir al panel principal de Sistema 14"><img src="assets/logo-s14.png" alt="Sistema 14"></a>
        <button class="mobile-menu-toggle" type="button" aria-controls="s14-sidebar" aria-expanded="false" aria-label="Abrir menú principal">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
    </div>
    <nav class="main-nav">
        <a class="dashboard-link <?= $dashboardModule ? 'active' : '' ?>" href="./">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5M5.5 10v10h13V10M9.5 20v-6h5v6"/></svg></span>
            Panel principal
        </a>
        <a class="dashboard-link <?= $companyModule ? 'active' : '' ?>" href="?companies=list">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4h14v17M3 21h18M8 8h2m4 0h2m-8 4h2m4 0h2m-8 4h2m4 0h2"/></svg></span>
            Panel de empresas
        </a>
        <details class="catalog-menu" <?= $catalog ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5 12 2l8 3.5-8 3.5-8-3.5Zm0 6L12 15l8-3.5M4 17.5 12 21l8-3.5"/></svg></span> Catálogos</span>
                <span class="chevron"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></span>
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
                <span><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14m0 0-3-3m3 3-3 3M20 16H6m0 0 3 3m-3-3 3-3"/></svg></span> Movimientos</span>
                <span class="chevron"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></span>
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
            <summary><span><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h10l3 3v15H6V3Zm10 0v4h4M9 11h6m-6 4h6m-6 4h4"/></svg></span> Facturación</span><span class="chevron"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></span></summary>
            <div class="catalog-links">
                <a class="<?= $invoiceView === 'list' ? 'active' : '' ?>" href="?invoice=list"><span></span>Facturas</a>
                <a class="<?= $invoiceView === 'create' ? 'active' : '' ?>" href="?invoice=create"><span></span>Crear factura</a>
                <a class="<?= $complementModule ? 'active' : '' ?>" href="?complement=list"><span></span>Complementos de pago</a>
            </div>
        </details>
        <details class="catalog-menu report-menu" <?= $report ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6V3Zm3 5h6m-6 4h6m-6 4h4"/></svg></span> Reportes</span>
                <span class="chevron"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></span>
            </summary>
            <div class="catalog-links">
                <?php foreach ($reports as $navKey => $item): ?>
                    <a class="<?= $reportKey === $navKey ? 'active' : '' ?>" href="?report=<?= e($navKey) ?>">
                        <span></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
        <details class="catalog-menu statistics-menu" <?= $statisticsModule ? 'open' : '' ?>>
            <summary>
                <span><span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20v-7h4v7m2 0V9h4v11m2 0V4h4v16M3 20h19"/></svg></span> Estadísticas</span>
                <span class="chevron"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></span>
            </summary>
            <div class="catalog-links">
                <?php foreach ($statistics as $navKey => $item): ?>
                    <a class="<?= $statisticsKey === $navKey ? 'active' : '' ?>" href="?statistics=<?= e($navKey) ?>">
                        <span></span><?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
    </nav>
    <?php $signedUser = current_user(); ?>
    <div class="session-user">
        <span class="session-avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0"/></svg></span>
        <div class="session-copy"><span><?= e($signedUser['name'] ?? '') ?></span><small><?= e($signedUser['type'] ?? '') ?></small></div>
        <form method="post" action="?auth=logout">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H5v14h5m4-3 4-4-4-4m4 4H9"/></svg>Cerrar sesión</button>
        </form>
    </div>
    <p class="phase">Sistema 14 renovado · PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?></p>
</aside>

<main id="main-content">
    <header class="topbar">
        <div class="topbar-copy">
            <p class="eyebrow"><?= $statisticsModule ? 'Análisis operativo' : ($companyModule ? 'Tesorería por empresa' : ($invoiceModule || $complementModule ? 'Facturación CFDI' : ($report ? 'Consulta y análisis' : ($movement ? 'Operación' : 'Administración')))) ?></p>
            <h1><?= e($statistic['label'] ?? ($companyModule ? ($companyData['razon'] ?? 'Panel de empresas') : ($complementModule ? ($complementView === 'create' ? 'Nuevo complemento de pago' : ($complementView === 'detail' ? 'Facturas asociadas al complemento' : 'Complementos de pago')) : ($invoiceModule ? ($invoiceView === 'create' ? 'Crear factura' : ($invoiceView === 'complements' ? 'Complementos asociados' : 'Facturas')) : ($catalog['label'] ?? $report['label'] ?? ($movementAction === 'transfer' && $action === 'form' ? 'Nuevo traspaso' : ($movement['label'] ?? 'Panel principal'))))))) ?></h1>
            <?php if ($dashboardModule): ?><p class="topbar-description">Visión general de saldos, movimientos y carteras de clientes del sistema.</p><?php endif; ?>
        </div>
        <div class="topbar-actions">
            <button type="button" class="global-search-trigger" data-global-search-open aria-haspopup="dialog" aria-controls="global-search-dialog">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg>
                <span>Buscar</span><kbd>Ctrl K</kbd>
            </button>
            <?php if ($catalog && $action !== 'form'): ?>
                <a class="button primary" href="?catalog=<?= e($key) ?>&amp;action=form">+ Nuevo registro</a>
            <?php elseif ($movementKey === 'traspasos' && $action !== 'form'): ?>
                <a class="button primary" href="?movement=traspasos&amp;action=form">+ Nuevo traspaso</a>
            <?php elseif ($invoiceView === 'list'): ?>
                <a class="button primary" href="?invoice=create">+ Nueva factura</a>
            <?php elseif ($complementView === 'list'): ?>
                <a class="button primary" href="?complement=create">+ Nuevo complemento</a>
            <?php elseif ($dashboardModule): ?>
                <div class="dashboard-calculator-shortcuts" aria-label="Calculadoras">
                    <button type="button" class="calculator-shortcut" data-calculator-open="balance" aria-label="Abrir calculadora de saldos" title="Calculadora de saldos">$</button>
                    <button type="button" class="calculator-shortcut" data-calculator-open="banking" aria-label="Abrir calculadora de banking" title="Calculadora de banking">%</button>
                    <button type="button" class="calculator-shortcut" data-calculator-open="dispersal" aria-label="Abrir dispersor" title="Dispersor"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14m0 0-3-3m3 3-3 3M20 16H6m0 0 3 3m-3-3 3-3"/></svg></button>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="global-search-backdrop" data-global-search-backdrop hidden>
        <section class="global-search-dialog" id="global-search-dialog" role="dialog" aria-modal="true" aria-labelledby="global-search-title" aria-describedby="global-search-help">
            <header class="global-search-header">
                <span class="global-search-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg></span>
                <div><h2 id="global-search-title">Búsqueda general</h2><p id="global-search-help">Busca pagos, facturas y capturas de estados de cuenta.</p></div>
                <button type="button" class="global-search-close" data-global-search-close aria-label="Cerrar búsqueda"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </header>
            <form class="global-search-form" data-global-search-form role="search">
                <label class="sr-only" for="global-search-input">Buscar por ID, descripción, monto, folio, UUID o referencia</label>
                <span class="global-search-input-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg></span>
                <input id="global-search-input" type="search" name="q" minlength="2" maxlength="120" autocomplete="off" spellcheck="false" placeholder="ID, descripción, monto, folio, UUID o referencia…" aria-describedby="global-search-status">
                <kbd>Esc</kbd>
            </form>
            <div class="global-search-status" id="global-search-status" data-global-search-status role="status" aria-live="polite">Escribe al menos 2 caracteres para buscar.</div>
            <div class="global-search-results" data-global-search-results></div>
        </section>
    </div>

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
            <?php
            $companyStats = $companyResult['stats'] ?? [];
            $companyTotal = (int) ($companyResult['total'] ?? 0);
            $companyPerPage = (int) ($companyResult['perPage'] ?? 6);
            $companyCurrentPage = (int) ($companyResult['page'] ?? 1);
            $companyLastPage = (int) ($companyResult['lastPage'] ?? 1);
            $companyStart = $companyTotal > 0 ? (($companyCurrentPage - 1) * $companyPerPage) + 1 : 0;
            $companyEnd = min($companyCurrentPage * $companyPerPage, $companyTotal);
            $companyListQuery = [
                'companies' => 'list',
                'q' => (string) ($_GET['q'] ?? ''),
                'sort' => (string) ($companyResult['sort'] ?? 'id_asc'),
                'per_page' => $companyPerPage,
            ];
            $pageWindowStart = max(1, $companyCurrentPage - 2);
            $pageWindowEnd = min($companyLastPage, $companyCurrentPage + 2);
            ?>
            <section class="company-directory-shell" aria-labelledby="company-directory-title">
                <header class="company-directory-hero">
                    <div class="company-directory-heading">
                        <span class="company-directory-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 21V4.8a1.8 1.8 0 0 1 1.3-1.7l7-2.1A1.3 1.3 0 0 1 15 2.2V21M3 21h18M8 7h2m-2 4h2m-2 4h2m7-7h2v13m-2-9h2m-2 4h2"/></svg></span>
                        <div><p>Empresas activas</p><h1 id="company-directory-title">Directorio de empresas</h1><span>Consulta cada empresa, sus cuentas bancarias, capturas importadas y movimientos conciliados.</span></div>
                    </div>
                    <span class="company-operational-status"><i aria-hidden="true"></i>Vista operativa</span>
                </header>

                <div class="company-kpi-grid" aria-label="Resumen del directorio">
                    <article class="company-kpi is-featured"><span class="company-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 21V5l10-3v19M3 21h18M8 8h2m-2 4h2m-2 4h2m7-8h4v13m-3-9h1m-1 4h1"/></svg></span><div><strong><?= number_format((int) ($companyStats['empresas'] ?? 0)) ?></strong><span>Empresas registradas</span></div></article>
                    <article class="company-kpi"><span class="company-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5M5 10h14M6 10v7m4-7v7m4-7v7m4-7v7M4 20h16M3 17h18"/></svg></span><div><strong><?= number_format((int) ($companyStats['cuentas'] ?? 0)) ?></strong><span>Cuentas bancarias activas</span></div></article>
                    <article class="company-kpi"><span class="company-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h15m0 0-3-3m3 3-3 3M20 17H5m0 0 3 3m-3-3 3-3"/></svg></span><div><strong><?= number_format((int) ($companyStats['movimientos'] ?? 0)) ?></strong><span>Movimientos conciliados</span></div></article>
                    <article class="company-kpi"><span class="company-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg></span><div><strong><?= number_format((int) ($companyStats['capturas'] ?? 0)) ?></strong><span>Capturas importadas</span></div></article>
                </div>

                <form method="get" class="company-toolbar" role="search">
                    <input type="hidden" name="companies" value="list">
                    <input type="hidden" name="per_page" value="<?= $companyPerPage ?>">
                    <div class="company-search-field"><label class="sr-only" for="companyDirectorySearch">Buscar empresa</label><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg><input id="companyDirectorySearch" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Razón social, RFC o ID de empresa..." autocomplete="off"><button type="submit">Buscar</button></div>
                    <label class="company-select-field"><span class="sr-only">Estado</span><select aria-label="Estado de las empresas"><option>Estado: Activas</option></select></label>
                    <label class="company-select-field"><span class="sr-only">Ordenar empresas</span><select name="sort" data-company-auto-submit><option value="id_asc" <?= ($companyResult['sort'] ?? '') === 'id_asc' ? 'selected' : '' ?>>Orden: ID (asc)</option><option value="id_desc" <?= ($companyResult['sort'] ?? '') === 'id_desc' ? 'selected' : '' ?>>Orden: ID (desc)</option><option value="name_asc" <?= ($companyResult['sort'] ?? '') === 'name_asc' ? 'selected' : '' ?>>Nombre A–Z</option><option value="name_desc" <?= ($companyResult['sort'] ?? '') === 'name_desc' ? 'selected' : '' ?>>Nombre Z–A</option><option value="accounts_desc" <?= ($companyResult['sort'] ?? '') === 'accounts_desc' ? 'selected' : '' ?>>Más cuentas</option></select></label>
                    <div class="company-view-switch" role="group" aria-label="Vista del directorio"><button type="button" data-company-view="grid" aria-pressed="true" aria-label="Vista de tarjetas"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg></button><button type="button" data-company-view="list" aria-pressed="false" aria-label="Vista de lista"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6h1M4 12h1M4 18h1"/></svg></button></div>
                </form>

                <div class="company-results-meta"><strong><?= number_format($companyTotal) ?> resultados</strong><span>Mostrando <?= number_format($companyStart) ?>–<?= number_format($companyEnd) ?> de <?= number_format($companyTotal) ?></span></div>

                <?php if (($companyResult['rows'] ?? []) !== []): ?>
                    <div class="company-grid" data-company-view-panel="grid">
                        <?php foreach ($companyResult['rows'] as $item): ?>
                            <?php $companyInitials = mb_strtoupper(mb_substr(trim((string) $item['razon']), 0, 2)); ?>
                            <article class="company-card">
                                <div class="company-card-head">
                                    <div class="company-card-media">
                                        <?php if (trim((string) ($item['logo'] ?? '')) !== ''): ?><img src="<?= e(legacy_asset_url($item['logo'])) ?>" alt="Logo de <?= e($item['razon']) ?>" loading="lazy" data-company-logo><span data-company-logo-fallback hidden><?= e($companyInitials) ?></span><?php else: ?><span><?= e($companyInitials) ?></span><?php endif; ?>
                                    </div>
                                    <div class="company-card-identity"><span class="company-id-badge">#<?= e($item['id']) ?></span><h2 title="<?= e($item['razon']) ?>"><?= e($item['razon']) ?></h2><p><?= e($item['rfc'] ?: 'RFC no registrado') ?></p></div>
                                    <span class="company-status"><i aria-hidden="true"></i>Activa</span>
                                </div>
                                <dl class="company-card-metrics">
                                    <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 9 9-5 9 5M5 10h14M6 10v7m4-7v7m4-7v7m4-7v7M4 20h16"/></svg><strong><?= number_format((int) $item['cuentas']) ?></strong></dt><dd><?= (int) $item['cuentas'] === 1 ? 'Cuenta activa' : 'Cuentas activas' ?></dd></div>
                                    <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg><strong><?= number_format((int) $item['capturas']) ?></strong></dt><dd>Capturas</dd></div>
                                    <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h15m0 0-3-3m3 3-3 3M20 17H5m0 0 3 3m-3-3 3-3"/></svg><strong><?= number_format((int) $item['movimientos']) ?></strong></dt><dd>Movimientos</dd></div>
                                </dl>
                                <footer class="company-card-footer"><a class="company-primary-action" href="?companies=detail&amp;id=<?= e($item['id']) ?>">Ver panel <span aria-hidden="true">→</span></a></footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="company-list-wrap" data-company-view-panel="list" hidden>
                        <table class="company-list-table"><thead><tr><th>Empresa</th><th>RFC</th><th>ID</th><th>Cuentas</th><th>Capturas</th><th>Movimientos</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
                        <?php foreach ($companyResult['rows'] as $item): ?><tr><td><strong><?= e($item['razon']) ?></strong></td><td><?= e($item['rfc'] ?: 'No registrado') ?></td><td>#<?= e($item['id']) ?></td><td><?= number_format((int) $item['cuentas']) ?></td><td><?= number_format((int) $item['capturas']) ?></td><td><?= number_format((int) $item['movimientos']) ?></td><td><span class="company-status"><i aria-hidden="true"></i>Activa</span></td><td><a href="?companies=detail&amp;id=<?= e($item['id']) ?>">Ver panel <span aria-hidden="true">→</span></a></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </div>
                <?php else: ?>
                    <div class="company-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg></span><strong>No encontramos empresas</strong><p>Prueba con otra razón social, RFC o ID.</p><a href="?companies=list">Limpiar búsqueda</a></div>
                <?php endif; ?>

                <footer class="company-pagination">
                    <form method="get"><input type="hidden" name="companies" value="list"><input type="hidden" name="q" value="<?= e($_GET['q'] ?? '') ?>"><input type="hidden" name="sort" value="<?= e($companyResult['sort'] ?? 'id_asc') ?>"><label>Resultados por página:<select name="per_page" data-company-auto-submit><option value="6" <?= $companyPerPage === 6 ? 'selected' : '' ?>>6</option><option value="12" <?= $companyPerPage === 12 ? 'selected' : '' ?>>12</option><option value="24" <?= $companyPerPage === 24 ? 'selected' : '' ?>>24</option></select></label></form>
                    <nav aria-label="Paginación de empresas">
                        <a class="company-page-link company-page-arrow<?= $companyCurrentPage <= 1 ? ' is-disabled' : '' ?>" href="?<?= e(http_build_query($companyListQuery + ['page' => max(1, $companyCurrentPage - 1)])) ?>" aria-label="Página anterior"<?= $companyCurrentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>‹</a>
                        <?php for ($companyPageNumber = $pageWindowStart; $companyPageNumber <= $pageWindowEnd; $companyPageNumber++): ?><a class="company-page-link<?= $companyPageNumber === $companyCurrentPage ? ' is-active' : '' ?>" href="?<?= e(http_build_query($companyListQuery + ['page' => $companyPageNumber])) ?>"<?= $companyPageNumber === $companyCurrentPage ? ' aria-current="page"' : '' ?>><?= $companyPageNumber ?></a><?php endfor; ?>
                        <a class="company-page-link company-page-arrow<?= $companyCurrentPage >= $companyLastPage ? ' is-disabled' : '' ?>" href="?<?= e(http_build_query($companyListQuery + ['page' => min($companyLastPage, $companyCurrentPage + 1)])) ?>" aria-label="Página siguiente"<?= $companyCurrentPage >= $companyLastPage ? ' aria-disabled="true" tabindex="-1"' : '' ?>>›</a>
                    </nav>
                </footer>
            </section>
        <?php elseif ($companyView === 'detail'): ?>
            <?php
            $companyInitials = mb_strtoupper(mb_substr(trim((string) $companyData['razon']), 0, 2));
            $companyAddress = trim(implode(' ', array_filter([
                $companyData['calle'], $companyData['numero'], $companyData['colonia'],
                $companyData['cp'], $companyData['estado'],
            ])));
            ?>
            <div class="company-detail-shell">
                <header class="company-detail-hero">
                    <div class="company-detail-identity">
                        <div class="company-identity-logo">
                            <?php if (trim((string) ($companyData['logo'] ?? '')) !== ''): ?><img src="<?= e(legacy_asset_url($companyData['logo'])) ?>" alt="Logo de <?= e($companyData['razon']) ?>" width="100" height="100" fetchpriority="high" data-company-logo><span data-company-logo-fallback hidden><?= e($companyInitials) ?></span><?php else: ?><span><?= e($companyInitials) ?></span><?php endif; ?>
                        </div>
                        <div class="company-detail-divider" aria-hidden="true"></div>
                        <div class="company-detail-copy"><p>Empresa #<?= e($companyData['id']) ?></p><h1><?= e($companyData['razon']) ?></h1><span><?= e($companyData['rfc'] ?: 'RFC no registrado') ?> · <?= e($companyAddress ?: 'Domicilio no registrado') ?></span></div>
                    </div>
                    <a class="company-all-link" href="?companies=list"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>Todas las empresas</a>
                </header>

                <section class="company-detail-kpis" aria-label="Resumen de la empresa">
                    <article class="is-featured"><span class="company-detail-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg></span><div><span>Capturas</span><strong><?= number_format((int) ($companySummary['capturas'] ?? 0)) ?></strong><a href="?companies=captures&amp;id=<?= e($companyData['id']) ?>">Consultar <b aria-hidden="true">→</b></a></div></article>
                    <article><span class="company-detail-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h15m0 0-3-3m3 3-3 3M20 17H5m0 0 3 3m-3-3 3-3"/></svg></span><div><span>Movimientos</span><strong><?= number_format((int) ($companySummary['movimientos'] ?? 0)) ?></strong><a href="?companies=movements&amp;id=<?= e($companyData['id']) ?>">Consultar <b aria-hidden="true">→</b></a></div></article>
                    <article><span class="company-detail-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/><path d="m10 18 2 2 4-4"/></svg></span><div><span>Por facturar</span><strong><?= number_format((int) ($companySummary['pendientes'] ?? 0)) ?></strong><small>Movimientos no retirados</small></div></article>
                    <article><span class="company-detail-kpi-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v5c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 11v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/></svg></span><div><span>Depósitos sin facturar</span><strong><?= e(report_value($companySummary['sin_facturar'] ?? 0, 'money')) ?></strong><small>Importe acumulado</small></div></article>
                </section>

                <section class="company-bank-panel" aria-labelledby="company-bank-title">
                    <header class="company-bank-heading">
                        <div class="company-bank-title"><span class="company-bank-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 9 9-5 9 5M5 10h14M6 10v7m4-7v7m4-7v7m4-7v7M4 20h16M3 17h18"/></svg></span><div><p>Bancos</p><h2 id="company-bank-title">Cuentas activas</h2><span>Saldo calculado con los depósitos y retiros importados.</span></div></div>
                        <nav class="company-bank-nav" aria-label="Consultas de la empresa"><a class="is-primary" href="?companies=captures&amp;id=<?= e($companyData['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg>Capturas</a><a href="?companies=movements&amp;id=<?= e($companyData['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h15m0 0-3-3m3 3-3 3M20 17H5m0 0 3 3m-3-3 3-3"/></svg>Movimientos</a></nav>
                    </header>
                    <div class="company-account-grid">
                        <?php foreach ($companyData['accounts'] as $account): ?>
                            <?php
                            $accountColor = preg_match('/^#?[0-9a-f]{6}$/i', (string) $account['color'])
                                ? '#' . ltrim((string) $account['color'], '#')
                                : '#f26a0a';
                            $accountIdentity = mb_strtoupper(trim((string) $account['banco'] . ' ' . (string) $account['nombre_corto']));
                            $accountLogo = str_contains($accountIdentity, 'ASP') ? legacy_asset_url('img/asp.jpg') : '';
                            $accountFallbackSource = trim((string) ($account['banco'] ?: $account['nombre_corto'] ?: 'Banco'));
                            $accountInitials = mb_strtoupper(mb_substr($accountFallbackSource, 0, 3));
                            ?>
                            <article class="company-account-card" style="--account-color: <?= e($accountColor) ?>">
                                <div class="company-account-main">
                                    <div class="company-bank-logo"><?php if ($accountLogo !== ''): ?><img src="<?= e($accountLogo) ?>" alt="Logo de <?= e($account['banco']) ?>" width="200" height="200" loading="lazy" data-company-logo><span data-company-logo-fallback hidden><?= e($accountInitials) ?></span><?php else: ?><span><?= e($accountInitials) ?></span><?php endif; ?></div>
                                    <div class="company-account-copy"><span class="company-account-bank"><i aria-hidden="true"></i><?= e($account['banco'] ?: 'Banco no registrado') ?> · <?= e($account['moneda'] ?: 'MXN') ?></span><h3><?= e($account['nombre_corto'] ?: ('Cuenta #' . $account['id'])) ?></h3><strong><?= e(report_value($account['saldo'], 'money')) ?></strong><p><?= e($account['numero_cuenta'] ?: 'Número no registrado') ?></p></div>
                                </div>
                                <dl class="company-account-metrics"><div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4-8 4-8-4 8-4Zm-8 9 8 4 8-4M4 17l8 4 8-4"/></svg>Capturas</dt><dd><?= number_format((int) $account['capturas']) ?></dd></div><div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4m8-4v4M4 10h16M8 14h2m4 0h2m-8 3h2"/></svg>Última actividad</dt><dd><?= e($account['ultima_actividad'] ?: 'Sin movimientos') ?></dd></div></dl>
                                <footer class="company-account-actions"><a class="is-primary" href="?companies=account&amp;id=<?= e($companyData['id']) ?>&amp;account_id=<?= e($account['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6V3Zm3 5h6m-6 4h6m-6 4h4"/></svg>Estado de cuenta</a><a href="?companies=captures&amp;id=<?= e($companyData['id']) ?>&amp;account=<?= e($account['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 18 5-5 3 3 2-2 4 4"/></svg>Capturas</a><a href="?companies=analyze&amp;id=<?= e($companyData['id']) ?>&amp;account_id=<?= e($account['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h16M7 15l4-4 3 2 5-6"/></svg>Analizar captura</a></footer>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($companyData['accounts'] === []): ?><div class="company-bank-empty"><strong>Sin cuentas activas</strong><span>Esta empresa no tiene cuentas bancarias activas para consultar.</span></div><?php endif; ?>
                    </div>
                </section>
            </div>
        <?php elseif ($companyView === 'analyze'): ?>
            <section class="report-intro capture-analysis-breadcrumb"><p><a href="?companies=detail&amp;id=<?= e($companyData['id']) ?>"><?= e($companyData['razon']) ?></a> · Analizar captura bancaria</p><span class="operation-badge"><?= e(($companyResult['account']['banco'] ?? '') . ' · ' . ($companyResult['account']['nombre_corto'] ?? '')) ?></span></section>
            <section class="panel capture-analyzer-panel">
                <div class="panel-heading capture-analyzer-heading"><span class="capture-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg></span><div><p class="eyebrow">Paso previo a la carga</p><h2>Analizar movimientos</h2><p class="panel-copy">Pega la captura conservando su formato. Se validarán duplicados, claves cortas y comisiones antes de guardar.</p></div><a class="button capture-back-button" href="?companies=detail&amp;id=<?= e($companyData['id']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6"/></svg>Volver al panel</a></div>
                <form id="captureAnalyzer" class="capture-analyzer" data-parser-url="?companies=analyze&amp;id=<?= e($companyData['id']) ?>&amp;data=parse" data-loader-url="?companies=analyze&amp;id=<?= e($companyData['id']) ?>&amp;data=load" data-company-id="<?= e($companyData['id']) ?>" data-account-id="<?= e($companyResult['account']['id']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label class="field capture-input-field"><span>Contenido de la captura *</span><div id="captureEditor" class="capture-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-describedby="captureEditorHelp" data-placeholder="Pega aquí la tabla o captura copiada desde el portal bancario…"></div><small id="captureEditorHelp">Puedes pegar una tabla HTML o contenido tabulado. Máximo 2 MB y 500 movimientos.</small></label>
                    <div class="capture-analyzer-actions"><button type="button" class="button" id="clearCaptureAnalysis"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 14h8l1-14M10 11v6m4-6v6"/></svg>Limpiar</button><button type="submit" class="button primary" id="analyzeCapture"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 11 7-11 7V5Z"/></svg><span>Analizar antes de cargar</span></button></div>
                </form>
            </section>
            <section class="panel capture-analysis-result" id="captureAnalysisResult" aria-live="polite" hidden>
                <div class="panel-heading capture-result-heading"><span class="capture-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 20V10h3v10H5Zm6 0V4h3v16h-3Zm6 0v-7h3v7h-3Z"/></svg></span><div><p class="eyebrow">Resultado del análisis</p><h2>Movimientos detectados</h2><p class="panel-copy" id="captureAnalysisMessage"></p></div></div>
                <div class="company-summary-grid capture-analysis-summary" id="captureAnalysisSummary"></div>
                <div class="table-wrap capture-results-table"><table class="movement-table"><thead><tr><th>#</th><th>Fecha</th><th>Descripción / referencia</th><th>Tipo</th><th>Importe</th><th>Duplicado</th><th>Clave corta asociada</th></tr></thead><tbody id="captureAnalysisRows"></tbody></table></div>
                <section class="capture-commission-summary" id="captureCommissionSummary" hidden>
                    <div class="capture-commission-heading"><div><p class="eyebrow">Proyección automática</p><h3>Resumen de comisiones</h3><span>Se generará únicamente para movimientos nuevos con clave corta identificada.</span></div><strong id="captureCommissionTotal">$0.00</strong></div>
                    <div class="capture-commission-kpis"><div><span>Movimientos asignables</span><strong id="captureCommissionMovements">0</strong></div><div><span>Registros de comisión</span><strong id="captureCommissionCount">0</strong></div></div>
                    <div class="table-wrap"><table class="capture-commission-table"><thead><tr><th>Cartera receptora</th><th>Comisión estimada</th></tr></thead><tbody id="captureCommissionRows"></tbody></table></div>
                </section>
                <div class="capture-analysis-note"><span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/></svg></span><strong>Revisión previa</strong><span>“Sin clave asociada” no impide cargar el movimiento: se registrará como <b>sin asignar</b>. Los duplicados serán omitidos.</span></div>
                <div class="capture-load-actions"><div><strong id="captureLoadTitle">Revisa el resultado antes de cargar</strong><span id="captureLoadStatus">La carga volverá a validar todos los datos en el servidor.</span></div><button type="button" class="button primary" id="loadCaptureMovements" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 20h14"/></svg><span>Cargar movimientos al sistema</span></button></div>
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
            <?php
            $complementData = payment_complement_repository()->formData();
            $invoiceComplementContext = $complementPrefill !== null;
            $paymentComplementContext = $complementPaymentPrefill !== null;
            $lockedCompanyClient = $invoiceComplementContext || $paymentComplementContext;
            $complementHeaderPrefill = $complementPrefill ?? $complementPaymentPrefill;
            $paymentPrefillReceivers = $paymentComplementContext
                ? payment_complement_repository()->receivers((int) $complementPaymentPrefill['client_id'])
                : [];
            $paymentAutoReceiverId = count($paymentPrefillReceivers) === 1
                ? (int) $paymentPrefillReceivers[0]['id']
                : 0;
            ?>
            <section class="report-intro"><p>Registra el pago y relaciónalo con una o varias facturas PPD. Los saldos se verifican nuevamente al guardar.</p><span class="operation-badge">Guardado transaccional</span></section>
            <form method="post" id="complementForm" data-confirm="¿Guardar este complemento y aplicar los montos a las facturas seleccionadas?" data-confirm-title="Confirmar complemento"<?= $invoiceComplementContext ? ' data-prefill-invoice-uuid="' . e($complementPrefill['uuid']) . '"' : '' ?><?= $paymentComplementContext ? ' data-prefill-payment-maximum="' . e($complementPaymentPrefill['maximum']) . '"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_create">
                <?php if ($lockedCompanyClient): ?><input type="hidden" name="company_id" value="<?= e($complementHeaderPrefill['company_id']) ?>"><input type="hidden" name="client_id" value="<?= e($complementHeaderPrefill['client_id']) ?>"><?php endif; ?>
                <?php if ($invoiceComplementContext): ?><input type="hidden" name="receiver_id" value="<?= e($complementPrefill['receiver_id']) ?>"><input type="hidden" name="source_invoice_id" value="<?= e($complementPrefill['id']) ?>"><?php endif; ?>
                <?php if ($paymentComplementContext): ?><input type="hidden" name="payment_id" value="<?= e($complementPaymentPrefill['id']) ?>"><?php endif; ?>
                <section class="panel invoice-form-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Datos del pago</p><h2>Emisor y receptor</h2></div><a class="button" href="?complement=list">Volver</a></div>
                    <div class="form-grid invoice-header-grid">
                        <label class="field"><span>Empresa emisora *</span><select <?= $lockedCompanyClient ? '' : 'name="company_id" ' ?>id="complementCompany" required<?= $lockedCompanyClient ? ' disabled aria-disabled="true"' : '' ?>><?php if ($lockedCompanyClient): ?><option value="<?= e($complementHeaderPrefill['company_id']) ?>" selected><?= e($complementHeaderPrefill['empresa']) ?></option><?php else: ?><option value="">Seleccionar…</option><?php foreach ($complementData['companies'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['razon']) ?> · <?= e($item['rfc']) ?></option><?php endforeach; ?><?php endif; ?></select></label>
                        <label class="field"><span>Cliente *</span><select <?= $lockedCompanyClient ? '' : 'name="client_id" ' ?>id="complementClient" required<?= $lockedCompanyClient ? ' disabled aria-disabled="true"' : '' ?>><?php if ($lockedCompanyClient): ?><option value="<?= e($complementHeaderPrefill['client_id']) ?>" selected><?= e($complementHeaderPrefill['cliente']) ?></option><?php else: ?><option value="">Seleccionar…</option><?php foreach ($complementData['clients'] as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['nombre']) ?></option><?php endforeach; ?><?php endif; ?></select></label>
                        <label class="field"><span>Razón social / clave corta *</span><select <?= $invoiceComplementContext ? '' : 'name="receiver_id" ' ?>id="complementReceiver" required<?= $invoiceComplementContext ? ' disabled aria-disabled="true"' : (!$paymentComplementContext ? ' disabled' : '') ?>><?php if ($invoiceComplementContext): ?><option value="<?= e($complementPrefill['receiver_id']) ?>" selected><?= e($complementPrefill['clave_corta'] . ' · ' . $complementPrefill['receptor']) ?></option><?php elseif ($paymentComplementContext): ?><option value="">Seleccionar…</option><?php foreach ($paymentPrefillReceivers as $item): ?><option value="<?= e($item['id']) ?>" <?= (int) $item['id'] === $paymentAutoReceiverId ? 'selected' : '' ?>><?= e(($item['clave_corta'] ?: 'Sin clave') . ' · ' . $item['razon_social']) ?></option><?php endforeach; ?><?php else: ?><option value="">Selecciona primero un cliente…</option><?php endif; ?></select></label>
                        <label class="field"><span>Forma en que se recibió el pago *</span><select name="form_id" required><option value="">Seleccionar…</option><?php foreach ($complementData['forms'] as $item): ?><option value="<?= e($item['id']) ?>" <?= (int) $item['clave'] === 3 ? 'selected' : '' ?>><?= e(str_pad((string) $item['clave'], 2, '0', STR_PAD_LEFT)) ?> · <?= e(trim((string) $item['forma_pago'])) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Fecha del pago *</span><input type="date" name="payment_date" value="<?= e($paymentComplementContext ? $complementPaymentPrefill['payment_date'] : date('Y-m-d')) ?>" required></label>
                        <label class="field"><span>Tratamiento fiscal *</span><select name="complement_type" required><option value="T">Típico · IVA 16%</option><option value="T0">Tasa 0%</option><option value="SV">Sin IVA</option><option value="4">Retención 4%</option></select></label>
                        <?php if ($paymentComplementContext): ?>
                            <label class="field"><span>Movimiento de pago</span><input type="text" value="#<?= e($complementPaymentPrefill['id']) ?>" readonly aria-readonly="true"></label>
                            <label class="field"><span>Monto máximo disponible</span><input type="text" value="<?= e(report_value($complementPaymentPrefill['maximum'], 'money')) ?>" readonly aria-readonly="true" data-complement-payment-maximum-value></label>
                        <?php else: ?>
                            <label class="field"><span>Movimiento de pago</span><input type="number" name="payment_id" min="1" placeholder="ID opcional"></label>
                        <?php endif; ?>
                    </div>
                    <?php if ($paymentComplementContext): ?><div class="complement-payment-context" role="note"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5V4Zm3 4h8M8 12h8m-8 4h5"/></svg></span><div><strong>Movimiento #<?= e($complementPaymentPrefill['id']) ?> precargado</strong><p>Empresa y cliente bloqueados para conservar la relación. Disponible para aplicar: <b data-complement-payment-remaining><?= e(report_value($complementPaymentPrefill['maximum'], 'money')) ?></b>.</p></div></div><?php endif; ?>
                </section>
                <section class="panel invoice-concepts-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Documentos relacionados</p><h2>Facturas PPD</h2><p class="panel-copy">Captura el UUID o consulta las facturas con saldo pendiente para este pago.</p></div><div class="panel-actions"><?php if ($paymentComplementContext): ?><button type="button" class="button complement-pending-trigger" id="viewPendingInvoices" aria-expanded="false" aria-controls="pendingInvoicesPanel"<?= $paymentAutoReceiverId <= 0 ? ' disabled' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6"/></svg><span>Ver facturas pendientes</span></button><?php endif; ?><button type="button" class="button" id="addComplementInvoice">+ Agregar factura</button></div></div>
                    <?php if ($paymentComplementContext): ?>
                        <section class="pending-invoices-panel" id="pendingInvoicesPanel" aria-labelledby="pendingInvoicesTitle" tabindex="-1" hidden>
                            <header><div><p class="eyebrow">Saldo por aplicar</p><h3 id="pendingInvoicesTitle">Facturas pendientes</h3><span id="pendingInvoicesSummary">Selecciona una razón social y consulta sus facturas.</span></div><button type="button" class="pending-invoices-close" id="closePendingInvoices" aria-label="Cerrar facturas pendientes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
                            <div class="pending-invoices-status" id="pendingInvoicesStatus" role="status" aria-live="polite">Preparado para consultar.</div>
                            <div class="table-wrap"><table class="pending-invoices-table"><thead><tr><th>Factura</th><th>Fecha</th><th>Total</th><th>Pagado</th><th>Saldo pendiente</th><th><span class="sr-only">Acción</span></th></tr></thead><tbody id="pendingInvoicesBody"></tbody></table></div>
                        </section>
                    <?php endif; ?>
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
                                    <a data-record-action data-action-label="Vista PDF" data-action-icon="▧" href="?document=complement_pdf&amp;id=<?= e($row['id']) ?>" target="_blank" rel="noopener">PDF</a>
                                    <form method="post" data-confirm="¿Eliminar este complemento pendiente y todas sus relaciones?" data-confirm-title="Eliminar complemento"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Eliminar pendiente" data-action-icon="×" data-action-danger>Eliminar</button></form>
                                <?php else: ?>
                                    <form method="post" data-confirm="¿Reenviar por correo este complemento timbrado?" data-confirm-title="Reenviar complemento"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="complement_resend"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Reenviar por correo" data-action-icon="↗">Reenviar</button></form>
                                    <button type="button" data-record-action data-action-label="Copiar UUID" data-action-icon="⧉" data-copy-uuid="<?= e($row['uuid']) ?>">Copiar UUID</button>
                                    <a data-record-action data-action-label="Descargar PDF" data-action-icon="▧" href="?document=complement_pdf&amp;id=<?= e($row['id']) ?>" target="_blank" rel="noopener">PDF</a>
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
                                    <a data-record-action data-action-label="Ver PDF previo" data-action-icon="▧" href="?document=invoice_pdf&amp;id=<?= e($row['id']) ?>" target="_blank" rel="noopener">Ver PDF</a>
                                    <a data-record-action data-action-label="Descargar PRE XML" data-action-icon="&lt;/&gt;" href="https://ultra.sistema14.com/api/xml/sinfirma/XML-factura-<?= e($row['id']) ?>.xml" target="_blank" rel="noopener">PRE XML</a>
                                    <form method="post" data-confirm="¿Eliminar esta factura pendiente y todos sus conceptos?" data-confirm-title="Eliminar factura"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_delete"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Eliminar pendiente" data-action-icon="×" data-action-danger>Eliminar</button></form>
                                <?php else: ?>
                                    <button type="button" data-record-action data-action-label="Copiar UUID" data-action-icon="⧉" data-copy-uuid="<?= e($row['uuid']) ?>">Copiar UUID</button>
                                    <a data-record-action data-action-label="Ver PDF" data-action-icon="▧" href="?document=invoice_pdf&amp;id=<?= e($row['id']) ?>" target="_blank" rel="noopener">Ver PDF</a>
                                    <a data-record-action data-action-label="Descargar XML" data-action-icon="&lt;/&gt;" href="https://ultra.sistema14.com/api/gateWayFactura.php?factura=<?= e($row['id']) ?>" target="_blank" rel="noopener">XML</a>
                                    <form method="post" data-confirm="¿Reenviar al correo principal del cliente el PDF moderno y el XML timbrado?" data-confirm-title="Reenviar factura"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="invoice_resend"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><button type="submit" data-record-action data-action-label="Reenviar por correo" data-action-icon="↗">Reenviar</button></form>
                                <?php endif; ?>
                                <button type="button" data-record-action data-action-label="Enviar a correo" data-action-icon="mail" data-invoice-email-trigger data-invoice-id="<?= e($row['id']) ?>" data-invoice-folio="<?= e(trim((string) (($row['serie'] ?? '') . ($row['folio'] ?? ''))) ?: ('#' . $row['id'])) ?>" data-invoice-email="<?= e($row['cliente_correo'] ?? '') ?>">Enviar a correo</button>
                            </div>
                        </div>
                    </td>
                </tr><?php endforeach; ?><?php if ($invoiceResult['rows'] === []): ?><tr><td colspan="6" class="empty">No hay facturas para mostrar.</td></tr><?php endif; ?></tbody></table></div>
                <div class="pagination"><span>Página <?= $invoiceResult['page'] ?><?= $invoiceResult['hasNext'] ? ' · Hay más resultados' : '' ?></span><div><?php if ($invoiceResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['invoice'=>'list','q'=>$invoiceSearch,'page'=>$invoiceResult['page']-1])) ?>">← Anterior</a><?php endif; ?><?php if ($invoiceResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query(['invoice'=>'list','q'=>$invoiceSearch,'page'=>$invoiceResult['page']+1])) ?>">Siguiente →</a><?php endif; ?></div></div>
            </section>
        <?php endif; ?>
    <?php elseif ($movement): ?>
        <?php if ($movementKey === 'correos-manuales'): ?>
            <?php $manualEmailCompanies = manual_email_service()->companies(); ?>
            <?php require __DIR__ . '/views/manual-email.php'; ?>
        <?php else: ?>
        <?php
        $movementPage = max(1, (int) ($_GET['page'] ?? 1));
        $movementSearch = trim((string) ($_GET['q'] ?? ''));
        $movementClientId = filter_input(INPUT_GET, 'client', FILTER_VALIDATE_INT) ?: null;
        $movementClient = $movementClientId ? movement_repository()->client($movementClientId) : null;
        $isTransferForm = $movementAction === 'transfer' && $action === 'form';
        $hasMovementContextActions = in_array($movementKey, ['asignados', 'cartera'], true);
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
                <form method="post" class="transfer-form" data-transfer-form data-confirm="¿Registrar este traspaso entre carteras?" data-confirm-title="Confirmar traspaso">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="transfer_create">
                    <div class="transfer-route" aria-label="Dirección del traspaso">
                        <label class="transfer-account-card transfer-origin-card">
                            <span class="transfer-card-heading">
                                <span class="transfer-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                <span><small>Paso 1 · Origen</small><strong>Sale dinero de</strong></span>
                            </span>
                            <select name="origin_id" required data-transfer-origin aria-describedby="transferOriginHelp"><option value="">Seleccionar cartera origen…</option><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?> (#<?= e($client['id']) ?>)</option><?php endforeach; ?></select>
                            <small id="transferOriginHelp" class="transfer-card-help">Se registrará un retiro por el monto indicado.</small>
                        </label>

                        <div class="transfer-direction" aria-hidden="true">
                            <span>ENVÍA</span>
                            <svg viewBox="0 0 112 34" fill="none"><path d="M2 17h101" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><path d="m92 5 12 12-12 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>

                        <label class="transfer-account-card transfer-destination-card">
                            <span class="transfer-card-heading">
                                <span class="transfer-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                <span><small>Paso 2 · Destino</small><strong>Recibe dinero en</strong></span>
                            </span>
                            <select name="destination_id" required data-transfer-destination aria-describedby="transferDestinationHelp"><option value="">Seleccionar cartera destino…</option><?php foreach ($movementClients as $client): ?><option value="<?= e($client['id']) ?>"><?= e($client['nombre']) ?> (#<?= e($client['id']) ?>)</option><?php endforeach; ?></select>
                            <small id="transferDestinationHelp" class="transfer-card-help">Se registrará un depósito por el mismo monto.</small>
                        </label>
                    </div>

                    <div class="transfer-route-summary" data-transfer-route-summary role="status" aria-live="polite">
                        <span class="transfer-summary-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 12h16M14 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        <div><small>Ruta del traspaso</small><strong data-transfer-summary>Selecciona la cartera que envía y la cartera que recibe.</strong></div>
                    </div>

                    <div class="transfer-details-grid">
                        <label class="field"><span>Monto que se transfiere *</span><input type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="$0.00" required data-transfer-amount></label>
                        <label class="field"><span>Motivo o detalle *</span><input type="text" name="detail" maxlength="500" placeholder="Ej. Ajuste entre carteras" required></label>
                    </div>
                    <p class="transfer-validation" data-transfer-validation role="alert" hidden></p>
                    <div class="form-actions transfer-actions"><button class="button primary" type="submit">Confirmar traspaso</button></div>
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
                <table class="movement-table<?= $movementAction || $hasMovementContextActions ? ' has-actions' : '' ?>">
                    <thead><tr><?php foreach ($movement['columns'] as $column): ?><th><?= e(is_array($column) ? $column['label'] : $column) ?></th><?php endforeach; ?><?php if ($movementAction || $hasMovementContextActions): ?><th><?= $movementAction === 'assign' ? 'Cartera destino' : 'Acción' ?></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($movementResult['rows'] as $row): ?>
                        <?php $canCreateMovementComplement = $hasMovementContextActions
                            && (string) ($row['status'] ?? '') === 'asignado'
                            && (float) ($row['saldo_acreditable'] ?? 0) > 0.005
                            && (int) ($row['client_id'] ?? 0) > 0
                            && (int) ($row['company_id'] ?? 0) > 0; ?>
                        <tr<?= $canCreateMovementComplement ? ' data-record-row' : '' ?>>
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
                            <?php if ($movementAction || $hasMovementContextActions): ?>
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
                                        <div class="movement-record-actions">
                                            <form method="post" class="inline-operation single-action" data-confirm="¿Desasignar este movimiento y eliminar todas sus comisiones relacionadas?" data-confirm-title="Confirmar desasignación">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="movement_unassign"><input type="hidden" name="payment_id" value="<?= e($row['id']) ?>">
                                                <button class="button danger" type="submit">Desasignar</button>
                                            </form>
                                            <?php if ($canCreateMovementComplement): ?>
                                                <button type="button" class="record-menu-trigger" data-record-menu-trigger aria-label="Abrir acciones del movimiento #<?= e($row['id']) ?>">•••</button>
                                                <div class="record-action-source" hidden><a data-record-action data-action-label="Hacer complemento" data-action-icon="receipt" href="?complement=create&amp;payment_id=<?= e($row['id']) ?>">Hacer complemento</a></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($movementAction === 'transfer'): ?>
                                        <form method="post" class="inline-operation single-action" data-confirm="¿Eliminar de forma permanente la entrada y la salida de este traspaso?" data-confirm-title="Eliminar traspaso">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="transfer_delete"><input type="hidden" name="payment_id" value="<?= e($row['id']) ?>">
                                            <button class="button danger" type="submit">Eliminar par</button>
                                        </form>
                                    <?php elseif ($canCreateMovementComplement): ?>
                                        <button type="button" class="record-menu-trigger" data-record-menu-trigger aria-label="Abrir acciones del movimiento #<?= e($row['id']) ?>">•••</button>
                                        <div class="record-action-source" hidden><a data-record-action data-action-label="Hacer complemento" data-action-icon="receipt" href="?complement=create&amp;payment_id=<?= e($row['id']) ?>">Hacer complemento</a></div>
                                    <?php else: ?>
                                        <span aria-hidden="true">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($movementResult['rows'] === []): ?><tr><td colspan="<?= count($movement['columns']) + ($movementAction || $hasMovementContextActions ? 1 : 0) ?>" class="empty">No hay movimientos para mostrar.</td></tr><?php endif; ?>
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
        <?php endif; ?>
    <?php elseif ($statisticsModule): ?>
        <?php
        $statFrom = (string) ($statisticsResult['from'] ?? ($_GET['from'] ?? date('Y-01-01')));
        $statTo = (string) ($statisticsResult['to'] ?? ($_GET['to'] ?? date('Y-m-d')));
        $selectedStatClient = max(0, (int) ($_GET['client'] ?? 0));
        if ($selectedStatClient <= 0 && $statisticsClients !== []) {
            $selectedStatClient = (int) $statisticsClients[0]['id'];
        }
        $formatStatisticValue = static function (mixed $value, string $format): string {
            return match ($format) {
                'money' => '$' . number_format((float) $value, 2),
                'number' => number_format((float) $value, (float) $value === floor((float) $value) ? 0 : 2),
                default => (string) $value,
            };
        };
        $renderStatisticChart = static function (array $chart, string $id = ''): void {
            $payload = json_encode([
                'labels' => array_values($chart['labels'] ?? []),
                'series' => array_values($chart['series'] ?? []),
                'format' => $chart['format'] ?? 'number',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>
            <div class="statistics-chart"<?= $id !== '' ? ' id="' . e($id) . '"' : '' ?>>
                <div class="statistics-chart-plot" data-statistics-chart="<?= e($payload ?: '{}') ?>" role="img" aria-label="Gráfica estadística"></div>
            </div>
            <?php
        };
        ?>
        <section class="report-intro statistics-intro">
            <p><?= e($statistic['description']) ?></p>
            <span class="operation-badge">Datos al <?= e(date('d/m/Y')) ?></span>
        </section>
        <section class="panel statistics-filter-panel">
            <form method="get" class="statistics-filters">
                <input type="hidden" name="statistics" value="<?= e($statisticsKey) ?>">
                <label><span>Desde</span><input type="date" name="from" value="<?= e($statFrom) ?>" required></label>
                <label><span>Hasta</span><input type="date" name="to" value="<?= e($statTo) ?>" required></label>
                <?php if ($statistic['clientFilter'] ?? false): ?>
                    <label class="statistics-client-filter"><span>Cliente</span><select name="client" required>
                        <?php foreach ($statisticsClients as $client): ?>
                            <option value="<?= e($client['id']) ?>" <?= $selectedStatClient === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <button class="button primary" type="submit">Actualizar estadísticas</button>
            </form>
        </section>

        <?php if ($statisticsError !== null): ?>
            <section class="panel"><div class="empty"><strong>No se pudo generar la estadística.</strong><br><?= e($statisticsError) ?></div></section>
        <?php elseif ($statisticsResult !== null): ?>
            <?php if (!empty($statisticsResult['summary'])): ?>
                <section class="statistics-summary">
                    <?php foreach ($statisticsResult['summary'] as $summary): ?>
                        <article class="stat-card"><span><?= e($summary['label']) ?></span><strong><?= e($formatStatisticValue($summary['value'], $summary['format'])) ?></strong></article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (($statisticsResult['type'] ?? '') === 'multi'): ?>
                <div class="statistics-grid">
                    <?php foreach ($statisticsResult['charts'] as $chartIndex => $chart): ?>
                        <section class="panel statistics-chart-panel"><div class="panel-heading"><div><p class="eyebrow">Comparativo semanal</p><h2><?= e($chart['title']) ?></h2></div></div><?php $renderStatisticChart($chart, 'statisticsChart' . $chartIndex); ?></section>
                    <?php endforeach; ?>
                </div>
            <?php elseif (($statisticsResult['type'] ?? '') === 'table'): ?>
                <div class="statistics-grid statistics-grid-two">
                    <?php foreach ($statisticsResult['charts'] as $chartIndex => $chart): ?>
                        <section class="panel statistics-chart-panel"><div class="panel-heading"><h2><?= e($chart['title']) ?></h2></div><?php $renderStatisticChart($chart, 'statisticsChart' . $chartIndex); ?></section>
                    <?php endforeach; ?>
                </div>
                <section class="panel report-panel statistics-table-panel">
                    <div class="panel-heading"><div><p class="eyebrow">Detalle agrupado</p><h2><?= number_format($statisticsResult['total']) ?> cuentas</h2><p class="panel-copy">100 resultados por página</p></div></div>
                    <div class="table-wrap"><table class="report-table"><thead><tr>
                        <?php foreach ($statisticsResult['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                    </tr></thead><tbody>
                        <?php foreach ($statisticsResult['rows'] as $row): ?><tr>
                            <?php foreach ($statisticsResult['columns'] as $column => $label): ?><td><?= e($formatStatisticValue($row[$column] ?? '', $statisticsResult['format'][$column] ?? 'text')) ?></td><?php endforeach; ?>
                        </tr><?php endforeach; ?>
                        <?php if ($statisticsResult['rows'] === []): ?><tr><td colspan="<?= count($statisticsResult['columns']) ?>" class="empty">No hay cuentas STP en el periodo seleccionado.</td></tr><?php endif; ?>
                    </tbody></table></div>
                    <div class="pagination">
                        <span>Página <?= e($statisticsResult['page']) ?> de <?= e(max(1, (int) ceil($statisticsResult['total'] / 100))) ?></span>
                        <div>
                            <?php if ($statisticsResult['page'] > 1): ?><a class="button" href="?<?= e(http_build_query(['statistics' => $statisticsKey, 'from' => $statFrom, 'to' => $statTo, 'page' => $statisticsResult['page'] - 1])) ?>">← Anterior</a><?php endif; ?>
                            <?php if ($statisticsResult['hasNext']): ?><a class="button" href="?<?= e(http_build_query(['statistics' => $statisticsKey, 'from' => $statFrom, 'to' => $statTo, 'page' => $statisticsResult['page'] + 1])) ?>">Siguiente →</a><?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section class="panel statistics-chart-panel statistics-chart-main">
                    <div class="panel-heading"><div><p class="eyebrow">Periodo <?= e($statFrom) ?> a <?= e($statTo) ?></p><h2><?= e($statistic['label']) ?></h2></div></div>
                    <?php $renderStatisticChart($statisticsResult, 'statisticsChartMain'); ?>
                </section>
            <?php endif; ?>
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
        $walletRows = $dashboardWallets['rows'];
        $walletCounts = [
            'all' => count($walletRows),
            'available' => count(array_filter($walletRows, static fn (array $wallet): bool => (float) $wallet['saldo'] >= -0.005)),
            'movements' => count(array_filter($walletRows, static fn (array $wallet): bool => (int) $wallet['movimientos_pend'] > 0)),
            'balance' => count(array_filter($walletRows, static fn (array $wallet): bool => abs((float) $wallet['saldo']) > 0.005)),
        ];
        ?>
        <section class="dashboard-summary">
            <article class="summary-card summary-card-primary">
                <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v5c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 11v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/></svg></span>
                <div><span>Saldos internos</span><strong><?= e(report_value($dashboardOverview['internalBalance'], 'money')) ?></strong><small>Cartera interna #13</small></div>
            </article>
            <article class="summary-card">
                <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14m0 0-3-3m3 3-3 3M20 16H6m0 0 3 3m-3-3 3-3"/></svg></span>
                <div><span>Movimientos pendientes</span><strong><?= e(report_value($dashboardOverview['pendingBalance'], 'money')) ?></strong><small><?= number_format($dashboardOverview['pendingCount']) ?> movimientos</small></div>
            </article>
            <article class="summary-card <?= abs($dashboardOverview['balanceDifference']) > 0.01 ? 'is-warning' : '' ?>">
                <span class="summary-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M6 6h12M8 6 4 14h8L8 6Zm8 0-4 8h8l-4-8ZM4 14c.7 2 2 3 4 3s3.3-1 4-3m0 0c.7 2 2 3 4 3s3.3-1 4-3"/></svg></span>
                <div><span>Diferencia de saldos</span><strong><?= e(report_value($dashboardOverview['balanceDifference'], 'money')) ?></strong><small>Estados de cuenta contra pagos</small></div>
                <?php if (abs($dashboardOverview['balanceDifference']) <= 0.01): ?><span class="summary-status" aria-label="Saldos conciliados"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 12 3 3 7-7"/></svg></span><?php endif; ?>
            </article>
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
            <div class="panel-heading wallet-heading">
                <span class="wallet-heading-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v13H4V7Zm4 0V4h8v3M8 12h8"/></svg></span>
                <div><p class="eyebrow">Panel anterior renovado</p><h2>Carteras de clientes</h2><p class="panel-copy">Saldos, movimientos, cheques y facturas pendientes con la información calculada por el sistema.</p></div>
            </div>
            <div class="wallet-toolbar">
                <div class="wallet-filters" role="group" aria-label="Filtrar carteras de esta página">
                    <button type="button" class="wallet-filter is-active" data-wallet-filter="all" aria-pressed="true">Todas <span><?= $walletCounts['all'] ?></span></button>
                    <button type="button" class="wallet-filter" data-wallet-filter="available" aria-pressed="false">Disponibles <span><?= $walletCounts['available'] ?></span></button>
                    <button type="button" class="wallet-filter" data-wallet-filter="movements" aria-pressed="false">Con movimientos <span><?= $walletCounts['movements'] ?></span></button>
                    <button type="button" class="wallet-filter" data-wallet-filter="balance" aria-pressed="false">Con saldo <span><?= $walletCounts['balance'] ?></span></button>
                </div>
                <form method="get" class="search wallet-search" role="search">
                    <label class="sr-only" for="wallet-search">Buscar cartera o clave</label>
                    <span class="search-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg></span>
                    <input id="wallet-search" type="search" name="q" value="<?= e($dashboardSearch) ?>" placeholder="Buscar cartera o clave…" autocomplete="off">
                    <button class="button">Buscar</button>
                </form>
            </div>
            <div class="wallet-grid">
                <?php foreach ($walletRows as $wallet): ?>
                    <?php $walletColor = preg_match('/^[0-9a-fA-F]{6}$/', ltrim((string) $wallet['color'], '#')) ? '#' . ltrim((string) $wallet['color'], '#') : 'var(--color-primary)'; ?>
                    <article class="wallet-card <?= (float) $wallet['saldo'] < -0.005 ? 'is-negative' : '' ?>" style="--wallet-accent: <?= e($walletColor) ?>" data-wallet-card data-wallet-id="<?= e($wallet['id']) ?>" data-wallet-available="<?= (float) $wallet['saldo'] >= -0.005 ? 'true' : 'false' ?>" data-wallet-movements="<?= (int) $wallet['movimientos_pend'] > 0 ? 'true' : 'false' ?>" data-wallet-balance="<?= abs((float) $wallet['saldo']) > 0.005 ? 'true' : 'false' ?>">
                        <button type="button" class="wallet-balance-refresh" data-wallet-balance-refresh aria-label="Actualizar saldo de <?= e($wallet['nombre']) ?>" title="Actualizar saldo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5M4 18v-5h5M18.5 9A7 7 0 0 0 6.2 6.5L4 11m16 2-2.2 4.5A7 7 0 0 1 5.5 15"/></svg></button>
                        <span class="sr-only" data-wallet-balance-status role="status" aria-live="polite"></span>
                        <header><div class="wallet-identity"><span class="wallet-avatar"><?= e(mb_strtoupper(mb_substr((string) $wallet['nombre'], 0, 1))) ?></span><div><h3><?= e($wallet['nombre']) ?></h3><small><?= e($wallet['clave_corta'] ?: 'Sin clave corta') ?> · #<?= e($wallet['id']) ?></small></div></div><span class="wallet-state"><i aria-hidden="true"></i><span data-wallet-state-label><?= (float) $wallet['saldo'] < -0.005 ? 'Saldo negativo' : 'Disponible' ?></span></span></header>
                        <div class="wallet-balance"><span>Saldo acreditable</span><strong data-wallet-balance-value><?= e(report_value($wallet['saldo'], 'money')) ?></strong></div>
                        <dl>
                            <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14m0 0-3-3m3 3-3 3M20 16H6m0 0 3 3m-3-3 3-3"/></svg>Mov. pendientes</dt><dd><?= number_format((int) $wallet['movimientos_pend']) ?> · <?= e(report_value($wallet['dinero_sin_fact'], 'money')) ?></dd></div>
                            <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h10l3 3v15H6V3Zm10 0v4h4M9 11h6m-6 4h6"/></svg>Cheques pendientes</dt><dd><?= number_format((int) $wallet['cheques_pend']) ?> · <?= e(report_value($wallet['cheques_pend_dinero'], 'money')) ?></dd></div>
                            <div><dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h10l3 3v15H6V3Zm10 0v4h4M9 11h6m-6 4h4"/></svg>Facturas sin pago</dt><dd><?= number_format((int) $wallet['facturas_sin_pago']) ?></dd></div>
                        </dl>
                        <footer><small><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v15H5V5Zm3-2v4m8-4v4M5 9h14"/></svg><span data-wallet-updated>Actualizado <?= e($wallet['ultima_act'] ?: 'sin fecha') ?></span></small><a href="?movement=cartera&amp;client=<?= e($wallet['id']) ?>">Ver movimientos <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12m0 0-4-4m4 4-4 4"/></svg></a></footer>
                    </article>
                <?php endforeach; ?>
                <?php if ($dashboardWallets['rows'] === []): ?><p class="empty wallet-empty">No se encontraron carteras.</p><?php endif; ?>
                <p class="empty wallet-filter-empty" hidden>No hay carteras que coincidan con este filtro en la página actual.</p>
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

<div class="glass-modal-backdrop invoice-email-modal" id="invoiceEmailModal" hidden>
    <section class="glass-modal invoice-email-dialog" role="dialog" aria-modal="true" aria-labelledby="invoiceEmailTitle" aria-describedby="invoiceEmailDescription">
        <div class="glass-modal-icon invoice-email-icon" aria-hidden="true">@</div>
        <div class="glass-modal-copy">
            <p class="eyebrow">Mailchimp Transactional</p>
            <h2 id="invoiceEmailTitle">Enviar factura por correo</h2>
            <p id="invoiceEmailDescription">Se adjuntará el PDF generado por Sistema 14 y el XML cuando esté disponible.</p>
        </div>
        <form id="invoiceEmailForm" class="invoice-email-form" data-endpoint="?invoice_email=1">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="invoice_id" id="invoiceEmailInvoiceId" value="">
            <div class="invoice-email-document"><span>Factura seleccionada</span><strong id="invoiceEmailFolio">—</strong></div>
            <label class="field"><span>Correo destinatario *</span><input type="email" name="email" id="invoiceEmailRecipient" maxlength="100" autocomplete="email" placeholder="cliente@empresa.com" required></label>
            <div class="invoice-email-sender"><span>Remitente</span><strong>no-reply@sistema14.org</strong></div>
            <div class="invoice-email-result" id="invoiceEmailResult" role="status" aria-live="polite" hidden></div>
            <div class="glass-modal-actions">
                <button type="button" class="button" id="invoiceEmailCancel">Cancelar</button>
                <button type="submit" class="button primary" id="invoiceEmailSend"><span>Enviar factura</span></button>
            </div>
        </form>
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
        document.body.classList.remove('has-third-party-loader');
        window.setTimeout(() => {
            if (!thirdPartyLoader.classList.contains('is-visible')) thirdPartyLoader.hidden = true;
        }, 220);
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
                window.setTimeout(() => { thirdPartyLoader.hidden = true; }, 220);
            }, autoHide);
        }
    };
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) hideThirdPartyLoader();
    });

    const thirdPartyOperations = {
        invoice_stamp: ['Timbrando factura', 'Conectando con Ultra Sistema 14. Esto puede tardar unos segundos…'],
        invoice_resend: ['Reenviando factura', 'Obteniendo el PDF y el XML para enviarlos al correo del cliente…'],
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

    const dismissToast = (toast) => {
        if (!(toast instanceof HTMLElement) || toast.classList.contains('is-leaving')) return;
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 240);
    };
    document.querySelectorAll('.toast-close').forEach((button) => {
        button.addEventListener('click', () => dismissToast(button.closest('.glass-toast')));
    });

    document.querySelectorAll('[data-auto-dismiss]').forEach((toast) => {
        window.setTimeout(() => {
            dismissToast(toast);
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
            item.setAttribute('role', 'menuitem');
            if (original.hasAttribute('data-action-danger')) item.classList.add('is-danger');
            const icon = document.createElement('span');
            if (original.dataset.actionIcon === 'receipt') {
                icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6"/></svg>';
            } else if (original.dataset.actionIcon === 'mail') {
                icon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3V6Zm1 1 8 6 8-6"/></svg>';
            } else {
                icon.textContent = original.dataset.actionIcon || '•';
            }
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

    const invoiceEmailModal = document.getElementById('invoiceEmailModal');
    const invoiceEmailForm = document.getElementById('invoiceEmailForm');
    const invoiceEmailRecipient = document.getElementById('invoiceEmailRecipient');
    const invoiceEmailInvoiceId = document.getElementById('invoiceEmailInvoiceId');
    const invoiceEmailFolio = document.getElementById('invoiceEmailFolio');
    const invoiceEmailResult = document.getElementById('invoiceEmailResult');
    const invoiceEmailSend = document.getElementById('invoiceEmailSend');
    const invoiceEmailCancel = document.getElementById('invoiceEmailCancel');
    let invoiceEmailSending = false;

    const closeInvoiceEmailModal = () => {
        if (invoiceEmailSending) return;
        invoiceEmailModal.classList.remove('is-visible');
        document.body.classList.remove('has-invoice-email-modal');
        window.setTimeout(() => {
            if (!invoiceEmailModal.classList.contains('is-visible')) invoiceEmailModal.hidden = true;
        }, 220);
    };

    const openInvoiceEmailModal = (button) => {
        invoiceEmailForm.reset();
        invoiceEmailInvoiceId.value = button.dataset.invoiceId || '';
        invoiceEmailFolio.textContent = button.dataset.invoiceFolio || `#${button.dataset.invoiceId || ''}`;
        invoiceEmailRecipient.value = button.dataset.invoiceEmail || '';
        invoiceEmailResult.hidden = true;
        invoiceEmailResult.className = 'invoice-email-result';
        invoiceEmailResult.textContent = '';
        invoiceEmailSend.disabled = false;
        invoiceEmailSend.querySelector('span').textContent = 'Enviar factura';
        invoiceEmailCancel.disabled = false;
        invoiceEmailCancel.textContent = 'Cancelar';
        invoiceEmailSending = false;
        invoiceEmailModal.hidden = false;
        document.body.classList.add('has-invoice-email-modal');
        window.requestAnimationFrame(() => invoiceEmailModal.classList.add('is-visible'));
        window.setTimeout(() => invoiceEmailRecipient.focus(), 80);
    };

    document.querySelectorAll('[data-invoice-email-trigger]').forEach((button) => {
        button.addEventListener('click', () => openInvoiceEmailModal(button));
    });

    invoiceEmailCancel?.addEventListener('click', closeInvoiceEmailModal);
    invoiceEmailModal?.addEventListener('click', (event) => {
        if (event.target === invoiceEmailModal) closeInvoiceEmailModal();
    });

    invoiceEmailForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (invoiceEmailSending || !invoiceEmailForm.reportValidity()) return;
        invoiceEmailSending = true;
        invoiceEmailSend.disabled = true;
        invoiceEmailCancel.disabled = true;
        invoiceEmailSend.querySelector('span').textContent = 'Esperando respuesta…';
        invoiceEmailResult.hidden = false;
        invoiceEmailResult.className = 'invoice-email-result is-waiting';
        invoiceEmailResult.textContent = 'Generando el PDF y esperando la respuesta de Mailchimp Transactional…';
        try {
            const response = await fetch(invoiceEmailForm.dataset.endpoint, {
                method: 'POST',
                body: new FormData(invoiceEmailForm),
                headers: {'Accept': 'application/json'},
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || data.result?.message || 'Mailchimp no aceptó el correo.');
            const status = String(data.result?.status || 'sent');
            invoiceEmailResult.className = `invoice-email-result is-success is-${status}`;
            invoiceEmailResult.textContent = data.result?.message || 'Correo aceptado correctamente.';
            invoiceEmailSend.querySelector('span').textContent = status === 'sent' ? 'Enviado correctamente' : 'Aceptado por Mailchimp';
            invoiceEmailCancel.textContent = 'Cerrar';
            invoiceEmailCancel.disabled = false;
            showToast(invoiceEmailResult.textContent, 'success');
        } catch (error) {
            invoiceEmailResult.className = 'invoice-email-result is-error';
            invoiceEmailResult.textContent = error instanceof Error ? error.message : 'No fue posible confirmar el envío.';
            invoiceEmailSend.disabled = false;
            invoiceEmailSend.querySelector('span').textContent = 'Intentar nuevamente';
            invoiceEmailCancel.disabled = false;
            showToast(invoiceEmailResult.textContent, 'error');
        } finally {
            invoiceEmailSending = false;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && invoiceEmailModal && !invoiceEmailModal.hidden) closeInvoiceEmailModal();
    });

    const stampModal = document.getElementById('complementStampModal');
    const stampCancel = document.getElementById('complementStampCancel');
    let pendingComplementStamp = null;
    const closeStampModal = () => {
        stampModal.classList.remove('is-visible');
        window.setTimeout(() => { stampModal.hidden = true; }, 220);
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
    const viewPendingInvoices = document.getElementById('viewPendingInvoices');
    const pendingInvoicesPanel = document.getElementById('pendingInvoicesPanel');
    const pendingInvoicesBody = document.getElementById('pendingInvoicesBody');
    const pendingInvoicesStatus = document.getElementById('pendingInvoicesStatus');
    const pendingInvoicesSummary = document.getElementById('pendingInvoicesSummary');
    const closePendingInvoices = document.getElementById('closePendingInvoices');
    let complementPaymentMaximum = Number(complementForm?.dataset.prefillPaymentMaximum || 0);
    const complementPaymentRemaining = document.querySelector('[data-complement-payment-remaining]');
    const complementPaymentMaximumValue = document.querySelector('[data-complement-payment-maximum-value]');

    const complementTotalAmount = (excludedRow = null) => {
        if (!complementLines) return 0;
        return Array.from(complementLines.querySelectorAll('tr[data-complement-line]'))
            .filter((row) => row !== excludedRow)
            .reduce((sum, row) => sum + (Number(row.querySelector('[name="amount[]"]').value) || 0), 0);
    };

    const syncPendingInvoiceButtons = () => {
        if (!pendingInvoicesBody || !complementLines) return;
        const selected = new Set(Array.from(complementLines.querySelectorAll('tr[data-complement-line]'))
            .filter((row) => row.dataset.validated === 'true')
            .map((row) => row.querySelector('[name="invoice_uuid[]"]').value.trim().toUpperCase()).filter(Boolean));
        const noCapacity = complementPaymentMaximum > 0
            && complementTotalAmount() >= complementPaymentMaximum - 0.001;
        pendingInvoicesBody.querySelectorAll('[data-pending-invoice-add]').forEach((button) => {
            const added = selected.has((button.dataset.pendingInvoiceUuid || '').toUpperCase());
            const applying = button.dataset.applying === 'true';
            button.disabled = added || applying || noCapacity;
            button.textContent = added ? 'Agregada' : (applying ? 'Agregando…' : 'Aplicar');
            button.title = noCapacity && !added ? 'El saldo del movimiento ya fue aplicado' : '';
        });
    };

    const recalculateComplement = () => {
        if (!complementLines) return;
        const rows = Array.from(complementLines.querySelectorAll('tr[data-complement-line]'));
        const total = complementTotalAmount();
        document.getElementById('complementCount').textContent = String(rows.filter((row) => row.dataset.validated === 'true').length);
        document.getElementById('complementTotal').textContent = invoiceMoney.format(total);
        if (complementPaymentRemaining && complementPaymentMaximum > 0) {
            const remaining = Math.max(0, complementPaymentMaximum - total);
            complementPaymentRemaining.textContent = invoiceMoney.format(remaining);
            complementPaymentRemaining.classList.toggle('is-exceeded', total > complementPaymentMaximum + 0.001);
        }
        rows.forEach((row) => {
            const balance = Number(row.dataset.balance) || 0;
            const amount = Number(row.querySelector('[name="amount[]"]').value) || 0;
            row.querySelector('[data-complement-remaining]').textContent = invoiceMoney.format(Math.max(0, balance - amount));
        });
        syncPendingInvoiceButtons();
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
            return false;
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
            const movementCapacity = complementPaymentMaximum > 0
                ? Math.max(0, complementPaymentMaximum - complementTotalAmount(row))
                : Number(invoice.saldo);
            const maximumForLine = Math.min(Number(invoice.saldo), movementCapacity);
            if (maximumForLine <= 0) throw new Error('El monto máximo del movimiento ya fue aplicado en otras facturas.');
            amount.max = String(maximumForLine);
            amount.value = String(maximumForLine);
            row.querySelector('[name="installment[]"]').value = String(invoice.siguiente_parcialidad || 1);
            recalculateComplement();
            return true;
        } catch (error) {
            resetComplementLine(row);
            showToast(error instanceof Error ? error.message : 'No fue posible consultar la factura.');
            return false;
        } finally {
            button.disabled = false;
            button.textContent = 'Consultar';
        }
    };

    const addComplementLine = () => {
        if (!complementLines) return null;
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
        return row;
    };

    const renderPendingInvoices = (invoices) => {
        if (!pendingInvoicesBody || !pendingInvoicesSummary) return;
        pendingInvoicesBody.replaceChildren();
        pendingInvoicesSummary.textContent = invoices.length === 1
            ? '1 factura con saldo pendiente.'
            : `${invoices.length} facturas con saldo pendiente.`;
        if (invoices.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.className = 'pending-invoices-empty';
            cell.textContent = 'No hay facturas PPD timbradas con saldo pendiente para esta razón social.';
            row.appendChild(cell);
            pendingInvoicesBody.appendChild(row);
            return;
        }

        invoices.forEach((invoice) => {
            const row = document.createElement('tr');
            const invoiceCell = document.createElement('td');
            const title = document.createElement('strong');
            title.textContent = `#${invoice.id} · ${(invoice.serie || '')}${invoice.folio || ''}`;
            const uuid = document.createElement('small');
            uuid.textContent = invoice.uuid;
            invoiceCell.append(title, uuid);
            row.appendChild(invoiceCell);
            [
                [invoice.fecha_timbrado || 'Sin fecha', ''],
                [invoiceMoney.format(Number(invoice.total_iva)), 'pending-invoice-money'],
                [invoiceMoney.format(Number(invoice.pagado)), 'pending-invoice-money'],
                [invoiceMoney.format(Number(invoice.saldo)), 'pending-invoice-money'],
            ].forEach(([value, className]) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                cell.className = className;
                row.appendChild(cell);
            });

            const actionCell = document.createElement('td');
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'button compact';
            apply.dataset.pendingInvoiceAdd = 'true';
            apply.dataset.pendingInvoiceUuid = invoice.uuid;
            apply.textContent = 'Aplicar';
            apply.addEventListener('click', async () => {
                const normalizedUuid = String(invoice.uuid).trim().toUpperCase();
                const existing = Array.from(complementLines.querySelectorAll('[name="invoice_uuid[]"]'))
                    .find((input) => input.value.trim().toUpperCase() === normalizedUuid);
                if (existing) {
                    existing.focus();
                    existing.closest('tr')?.scrollIntoView({block: 'center'});
                    showToast('Esta factura ya está agregada al complemento.', 'success');
                    syncPendingInvoiceButtons();
                    return;
                }

                let targetRow = Array.from(complementLines.querySelectorAll('tr[data-complement-line]'))
                    .find((item) => item.dataset.validated !== 'true' && !item.querySelector('[name="invoice_uuid[]"]').value.trim());
                targetRow ||= addComplementLine();
                if (!targetRow) return;
                const uuidInput = targetRow.querySelector('[name="invoice_uuid[]"]');
                uuidInput.value = invoice.uuid;
                apply.dataset.applying = 'true';
                syncPendingInvoiceButtons();
                const added = await lookupComplementInvoice(targetRow);
                delete apply.dataset.applying;
                if (!added) uuidInput.value = '';
                syncPendingInvoiceButtons();
                if (added) {
                    targetRow.scrollIntoView({block: 'center'});
                    targetRow.querySelector('[name="amount[]"]')?.focus();
                }
            });
            actionCell.appendChild(apply);
            row.appendChild(actionCell);
            pendingInvoicesBody.appendChild(row);
        });
        syncPendingInvoiceButtons();
    };

    const hidePendingInvoices = (restoreFocus = false) => {
        if (!pendingInvoicesPanel || !viewPendingInvoices) return;
        pendingInvoicesPanel.hidden = true;
        viewPendingInvoices.setAttribute('aria-expanded', 'false');
        if (restoreFocus) viewPendingInvoices.focus();
    };

    const loadPendingInvoices = async () => {
        const paymentId = complementForm?.querySelector('[name="payment_id"]')?.value || '';
        const receiverId = complementReceiver?.value || '';
        if (!paymentId || !receiverId || !pendingInvoicesPanel || !pendingInvoicesBody) {
            showToast('Selecciona una razón social para consultar sus facturas pendientes.');
            return;
        }

        pendingInvoicesPanel.hidden = false;
        viewPendingInvoices.setAttribute('aria-expanded', 'true');
        viewPendingInvoices.disabled = true;
        viewPendingInvoices.setAttribute('aria-busy', 'true');
        viewPendingInvoices.querySelector('span').textContent = 'Consultando…';
        pendingInvoicesStatus.textContent = 'Consultando facturas pendientes…';
        pendingInvoicesBody.replaceChildren();
        try {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('complement', 'create');
            url.searchParams.set('data', 'pending_invoices');
            url.searchParams.set('payment_id', paymentId);
            url.searchParams.set('receiver_id', receiverId);
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) throw new Error(result?.message || 'No fue posible consultar las facturas pendientes.');
            complementPaymentMaximum = Number(result.data.maximum) || complementPaymentMaximum;
            if (complementPaymentMaximumValue) complementPaymentMaximumValue.value = invoiceMoney.format(complementPaymentMaximum);
            renderPendingInvoices(Array.isArray(result.data.invoices) ? result.data.invoices : []);
            recalculateComplement();
            pendingInvoicesStatus.textContent = 'Consulta actualizada.';
            pendingInvoicesPanel.focus({preventScroll: true});
        } catch (error) {
            pendingInvoicesStatus.textContent = error instanceof Error ? error.message : 'No fue posible consultar las facturas pendientes.';
            renderPendingInvoices([]);
        } finally {
            viewPendingInvoices.disabled = !complementReceiver.value;
            viewPendingInvoices.removeAttribute('aria-busy');
            viewPendingInvoices.querySelector('span').textContent = 'Ver facturas pendientes';
        }
    };

    viewPendingInvoices?.addEventListener('click', loadPendingInvoices);
    closePendingInvoices?.addEventListener('click', () => hidePendingInvoices(true));
    pendingInvoicesPanel?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hidePendingInvoices(true);
    });

    complementClient?.addEventListener('change', async () => {
        if (!complementReceiver) return;
        await loadInvoiceCatalog('receivers', complementClient.value, complementReceiver);
        complementLines?.querySelectorAll('tr[data-complement-line]').forEach(resetComplementLine);
    });
    [complementCompany, complementReceiver].forEach((control) => control?.addEventListener('change', () => {
        complementLines?.querySelectorAll('tr[data-complement-line]').forEach(resetComplementLine);
        if (control === complementReceiver && viewPendingInvoices) {
            viewPendingInvoices.disabled = !complementReceiver.value;
            hidePendingInvoices();
            pendingInvoicesBody?.replaceChildren();
        }
    }));
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
            return;
        }
        if (complementPaymentMaximum > 0 && complementTotalAmount() > complementPaymentMaximum + 0.001) {
            event.preventDefault();
            event.stopImmediatePropagation();
            showToast(`El total excede el máximo disponible de ${invoiceMoney.format(complementPaymentMaximum)}.`);
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
        const dismiss = () => {
            if (toast.classList.contains('is-leaving')) return;
            toast.classList.add('is-leaving');
            window.setTimeout(() => toast.remove(), 240);
        };
        close.addEventListener('click', dismiss);
        toast.append(icon, copy, close);
        stack.appendChild(toast);
        window.setTimeout(dismiss, 6500);
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
        }, 220);
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
    const captureCommissionSummary = document.getElementById('captureCommissionSummary');
    const captureCommissionTotal = document.getElementById('captureCommissionTotal');
    const captureCommissionMovements = document.getElementById('captureCommissionMovements');
    const captureCommissionCount = document.getElementById('captureCommissionCount');
    const captureCommissionRows = document.getElementById('captureCommissionRows');
    const loadCaptureButton = document.getElementById('loadCaptureMovements');
    const captureLoadTitle = document.getElementById('captureLoadTitle');
    const captureLoadStatus = document.getElementById('captureLoadStatus');
    let analyzedCapture = '';
    let currentCaptureAnalysis = null;

    const captureSummaryIcons = {
        detected: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6V3Zm9 0v5h5M9 12h7m-7 4h7"/></svg>',
        new: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h15m0 0-3-3m3 3-3 3M20 17H5m0 0 3 3m-3-3 3-3"/></svg>',
        key: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 2 2m-5 1 2 2"/></svg>',
        missing: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4 3 20h18L12 4Zm0 6v4m0 3h.01"/></svg>',
    };

    const appendCaptureSummary = (kind, label, value, detail) => {
        const card = document.createElement('article');
        const icon = document.createElement('span');
        const copy = document.createElement('div');
        const labelNode = document.createElement('span');
        const valueNode = document.createElement('strong');
        const detailNode = document.createElement('small');
        card.className = `capture-summary-card is-${kind}`;
        icon.className = 'capture-summary-icon';
        icon.innerHTML = captureSummaryIcons[kind] || captureSummaryIcons.detected;
        labelNode.textContent = label;
        valueNode.textContent = String(value);
        detailNode.textContent = detail;
        copy.append(labelNode, valueNode, detailNode);
        card.append(icon, copy);
        captureSummary.appendChild(card);
    };

    const resetCaptureResult = (message = 'La carga volverá a validar todos los datos en el servidor.') => {
        analyzedCapture = '';
        currentCaptureAnalysis = null;
        captureRows?.replaceChildren();
        captureSummary?.replaceChildren();
        captureCommissionRows?.replaceChildren();
        if (captureResult) captureResult.hidden = true;
        if (captureCommissionSummary) captureCommissionSummary.hidden = true;
        if (loadCaptureButton) {
            loadCaptureButton.disabled = true;
            loadCaptureButton.querySelector('span').textContent = 'Cargar movimientos al sistema';
        }
        if (captureLoadTitle) captureLoadTitle.textContent = 'Revisa el resultado antes de cargar';
        if (captureLoadStatus) captureLoadStatus.textContent = message;
    };

    const renderCaptureCommissions = (preview) => {
        captureCommissionRows.replaceChildren();
        const movementCount = Number(preview?.movementCount) || 0;
        if (movementCount < 1) {
            captureCommissionSummary.hidden = true;
            return;
        }
        captureCommissionSummary.hidden = false;
        captureCommissionTotal.textContent = invoiceMoney.format(Number(preview.commissionTotal) || 0);
        captureCommissionMovements.textContent = String(movementCount);
        captureCommissionCount.textContent = String(Number(preview.commissionCount) || 0);
        const distributed = Array.isArray(preview.distributed) ? preview.distributed : [];
        if (distributed.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 2;
            cell.className = 'empty';
            cell.textContent = 'Las claves fueron identificadas, pero sus carteras no tienen una comisión configurada.';
            row.appendChild(cell);
            captureCommissionRows.appendChild(row);
            return;
        }
        distributed.forEach((recipient) => {
            const row = document.createElement('tr');
            const name = document.createElement('td');
            const amount = document.createElement('td');
            name.textContent = recipient.recipientName || `Cartera #${recipient.recipientId}`;
            amount.textContent = invoiceMoney.format(Number(recipient.amount) || 0);
            row.append(name, amount);
            captureCommissionRows.appendChild(row);
        });
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

        const analyzeLabel = analyzeCaptureButton.querySelector('span');
        analyzeCaptureButton.disabled = true;
        analyzeLabel.textContent = 'Analizando…';
        resetCaptureResult('Analizando duplicados, claves cortas y comisiones…');
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

            captureRows.replaceChildren();
            captureSummary.replaceChildren();
            analysis.movements.forEach((movement, index) => {
                const match = movement.short_key || {associated: false};
                const description = [movement.description, movement.detail, movement.reference].filter(Boolean).join(' ');
                const row = document.createElement('tr');
                const cells = [
                    String(index + 1),
                    [movement.date, movement.time].filter(Boolean).join(' · ') || '—',
                    description || 'Sin descripción',
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

            const newCount = Number(analysis.summary.new) || 0;
            const associated = Number(analysis.summary.with_key) || 0;
            const withoutKey = Number(analysis.summary.without_key) || 0;
            const duplicateCount = Number(analysis.summary.duplicates) || 0;
            appendCaptureSummary('detected', 'Detectados', movementCount, 'Movimientos leídos');
            appendCaptureSummary('new', 'Nuevos', newCount, 'No encontrados previamente');
            appendCaptureSummary('key', 'Con clave corta', associated, 'Asociación encontrada');
            appendCaptureSummary('missing', 'Sin clave corta', withoutKey, 'Se cargarán sin asignar');
            captureMessage.textContent = `${associated} de ${movementCount} movimientos tienen una clave corta asociada en la tabla claves_cortas.`;
            renderCaptureCommissions(analysis.commission_preview || {});
            analyzedCapture = capture;
            currentCaptureAnalysis = analysis;
            loadCaptureButton.disabled = newCount < 1;
            loadCaptureButton.querySelector('span').textContent = newCount === 1
                ? 'Cargar 1 movimiento al sistema'
                : `Cargar ${newCount} movimientos al sistema`;
            captureLoadTitle.textContent = newCount > 0 ? 'Análisis listo para cargar' : 'No hay movimientos nuevos';
            captureLoadStatus.textContent = newCount > 0
                ? `${associated} con clave corta; ${withoutKey} sin clave; ${duplicateCount} duplicados serán omitidos.`
                : 'Todos los movimientos detectados ya existen en el sistema.';
            captureResult.hidden = false;
            captureResult.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start'
            });
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'No fue posible analizar la captura.');
        } finally {
            analyzeCaptureButton.disabled = false;
            analyzeLabel.textContent = 'Analizar antes de cargar';
        }
    });

    loadCaptureButton?.addEventListener('click', async () => {
        const capture = captureEditor.innerHTML.trim();
        if (!currentCaptureAnalysis || capture !== analyzedCapture) {
            resetCaptureResult('El contenido cambió. Analiza nuevamente antes de cargar.');
            showToast('Analiza nuevamente la captura antes de cargarla.');
            return;
        }
        const label = loadCaptureButton.querySelector('span');
        const originalLabel = label.textContent;
        loadCaptureButton.disabled = true;
        label.textContent = 'Cargando…';
        captureLoadTitle.textContent = 'Guardando movimientos';
        captureLoadStatus.textContent = 'No cierres esta ventana mientras se aplican saldos y comisiones.';
        try {
            const payload = new FormData();
            payload.set('csrf_token', captureAnalyzer.querySelector('[name="csrf_token"]').value);
            payload.set('capture', capture);
            payload.set('account_id', captureAnalyzer.dataset.accountId);
            const response = await fetch(captureAnalyzer.dataset.loaderUrl, {
                method: 'POST', body: payload, headers: {'Accept': 'application/json'}
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'No fue posible cargar los movimientos.');
            const result = data.result;
            captureLoadTitle.textContent = 'Carga completada';
            captureLoadStatus.textContent = `${result.loaded} movimientos cargados: ${result.assigned} asignados y ${result.unassigned} sin asignar. ${result.commissions} comisiones por ${invoiceMoney.format(Number(result.commissionTotal) || 0)}.`;
            label.textContent = 'Carga completada';
            showToast('Los movimientos se cargaron correctamente.');
            window.setTimeout(() => {
                window.location.assign(`?companies=capture&id=${encodeURIComponent(captureAnalyzer.dataset.companyId)}&capture_id=${encodeURIComponent(result.captureId)}`);
            }, 900);
        } catch (error) {
            captureLoadTitle.textContent = 'No se completó la carga';
            captureLoadStatus.textContent = error instanceof Error ? error.message : 'No fue posible cargar los movimientos.';
            label.textContent = originalLabel;
            loadCaptureButton.disabled = false;
            showToast(captureLoadStatus.textContent);
        }
    });

    captureEditor?.addEventListener('input', () => {
        if (!currentCaptureAnalysis) return;
        resetCaptureResult('El contenido cambió. Analiza nuevamente antes de cargar.');
    });

    document.getElementById('clearCaptureAnalysis')?.addEventListener('click', () => {
        captureEditor.replaceChildren();
        resetCaptureResult();
        captureEditor.focus();
    });

    const openModal = () => {
        backdrop.hidden = false;
        window.requestAnimationFrame(() => backdrop.classList.add('is-visible'));
        accept.focus();
    };

    const closeModal = () => {
        backdrop.classList.remove('is-visible');
        window.setTimeout(() => { backdrop.hidden = true; }, 220);
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
<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const sidebar = document.getElementById('s14-sidebar');
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    if (sidebar && menuToggle) {
        const setMenuState = (open) => {
            sidebar.classList.toggle('is-open', open);
            menuToggle.setAttribute('aria-expanded', String(open));
            menuToggle.setAttribute('aria-label', open ? 'Cerrar menú principal' : 'Abrir menú principal');
            document.body.classList.toggle('has-mobile-menu', open);
        };
        menuToggle.addEventListener('click', () => setMenuState(!sidebar.classList.contains('is-open')));
        sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setMenuState(false)));
        window.addEventListener('resize', () => {
            if (window.innerWidth > 850) setMenuState(false);
        }, {passive: true});
    }

    const filterButtons = Array.from(document.querySelectorAll('[data-wallet-filter]'));
    const walletCards = Array.from(document.querySelectorAll('.wallet-card[data-wallet-available]'));
    const filterEmpty = document.querySelector('.wallet-filter-empty');
    if (filterButtons.length && walletCards.length) {
        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const filter = button.dataset.walletFilter;
                let visibleCount = 0;
                filterButtons.forEach((item) => {
                    const active = item === button;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-pressed', String(active));
                });
                walletCards.forEach((card) => {
                    const visible = filter === 'all' || card.dataset[`wallet${filter[0].toUpperCase()}${filter.slice(1)}`] === 'true';
                    card.hidden = !visible;
                    if (visible) visibleCount += 1;
                });
                if (filterEmpty) filterEmpty.hidden = visibleCount !== 0;
            });
        });
    }
})();
</script>
<script src="assets/ui-motion.js"></script>
<script src="assets/wallet-balance.js"></script>
<script src="assets/global-search.js"></script>
<script src="assets/company-directory.js"></script>
<script src="assets/searchable-select.js"></script>
<script src="assets/manual-email.js"></script>
<script src="assets/transfer.js"></script>
<script src="<?= e(legacy_asset_url('assets/js/apexcharts.js')) ?>"></script>
<script src="assets/statistics.js"></script>
</body>
</html>
