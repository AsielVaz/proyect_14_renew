<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

final class CaptureAnalysisService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CompanyPanelRepository $companies,
        private readonly MovementRepository $movements,
        private readonly AutomaticInvoiceService $automaticInvoices,
    ) {
    }

    public function analyze(int $companyId, int $accountId, string $html, int $operatorId): array
    {
        $account = $this->companies->accountForAnalysis($companyId, $accountId, $operatorId);
        $html = trim($html);
        if ($html === '') {
            throw new RuntimeException('Pega primero el contenido de la captura bancaria.');
        }
        if (strlen($html) > 2_000_000) {
            throw new RuntimeException('La captura supera el límite de 2 MB para el análisis.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('El contenido pegado no tiene un formato HTML válido.');
        }

        $xpath = new DOMXPath($document);
        $movements = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }
            $rows = $this->tableRows($xpath, $table);
            if (count($rows) < 2) {
                continue;
            }
            [$headerIndex, $columns] = $this->detectColumns($rows);
            if ($headerIndex === null || !isset($columns['date'])
                || (!isset($columns['amount']) && !isset($columns['credit']) && !isset($columns['debit']))) {
                continue;
            }
            for ($index = $headerIndex + 1; $index < count($rows); $index++) {
                $movement = $this->movementFromRow($rows[$index], $columns);
                if ($movement !== null) {
                    $movements[] = $movement;
                }
                if (count($movements) > 500) {
                    throw new RuntimeException('La captura supera el límite de 500 movimientos por análisis.');
                }
            }
        }
        if ($movements === []) {
            foreach ($xpath->query('//pre') ?: [] as $preformatted) {
                $rows = [];
                foreach (preg_split('/\R/u', trim($preformatted->textContent)) ?: [] as $line) {
                    $cells = array_map('trim', explode("\t", $line));
                    if (count($cells) > 1) $rows[] = $cells;
                }
                if (count($rows) < 2) continue;
                [$headerIndex, $columns] = $this->detectColumns($rows);
                if ($headerIndex === null || !isset($columns['date'])
                    || (!isset($columns['amount']) && !isset($columns['credit']) && !isset($columns['debit']))) continue;
                for ($index = $headerIndex + 1; $index < count($rows); $index++) {
                    $movement = $this->movementFromRow($rows[$index], $columns);
                    if ($movement !== null) $movements[] = $movement;
                }
            }
        }
        if ($movements === []) {
            throw new RuntimeException('No se detectó una tabla bancaria con columnas de fecha e importe, cargo o abono.');
        }

        $duplicate = $this->pdo->prepare('SELECT id FROM captura_edo_cta
            WHERE cuenta = :account AND fecha = :date
            AND ABS(COALESCE(deposito, 0) - :deposit) < 0.005
            AND ABS(COALESCE(retiro, 0) - :withdrawal) < 0.005
            AND ABS(COALESCE(saldo, 0) - :balance) < 0.005 LIMIT 1');
        $summary = [
            'detected' => count($movements), 'new' => 0, 'duplicates' => 0,
            'deposits' => 0, 'withdrawals' => 0, 'new_total' => 0.0,
            'with_key' => 0, 'without_key' => 0,
        ];
        foreach ($movements as &$movement) {
            $duplicate->execute([
                'account' => $accountId, 'date' => $movement['date'], 'deposit' => $movement['deposit'],
                'withdrawal' => $movement['withdrawal'], 'balance' => $movement['balance'],
            ]);
            $movement['duplicate'] = $duplicate->fetchColumn() !== false;
            $summary[$movement['duplicate'] ? 'duplicates' : 'new']++;
            if ($movement['type'] === 'Deposito') {
                $summary['deposits']++;
                if (!$movement['duplicate']) $summary['new_total'] += $movement['amount'];
            } else {
                $summary['withdrawals']++;
                if (!$movement['duplicate']) $summary['new_total'] -= $movement['amount'];
            }
        }
        unset($movement);

        $descriptions = array_map(static fn (array $movement): string => trim(implode(' ', array_filter([
            $movement['description'], $movement['detail'], $movement['reference'],
        ]))), $movements);
        $matches = $this->companies->analyzeShortKeys($companyId, $accountId, $descriptions, $operatorId);
        $commissionMovements = [];
        foreach ($movements as $index => &$movement) {
            $match = $matches[$index] ?? [
                'associated' => false, 'key_id' => null, 'key' => null,
                'client_id' => null, 'client' => null,
            ];
            $movement['short_key'] = $match;
            $summary[$match['associated'] ? 'with_key' : 'without_key']++;
            if (!$movement['duplicate'] && $match['associated']) {
                $commissionMovements[] = [
                    'sourceIndex' => $index,
                    'amount' => $movement['amount'],
                    'type' => $movement['type'],
                    'clientId' => $match['client_id'],
                ];
            }
        }
        unset($movement);

        $bankBalance = self::projectBankBalance((float) ($account['saldo'] ?? 0.0), $movements);
        $currency = strtoupper(trim((string) ($account['moneda'] ?? 'MXN')));
        $bankBalance['currency'] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'MXN';

        return [
            'account' => $account,
            'summary' => $summary,
            'bank_balance' => $bankBalance,
            'movements' => $movements,
            'commission_preview' => $this->movements->previewCaptureAssignments($commissionMovements),
        ];
    }

    public static function projectBankBalance(float $currentBalance, array $movements): array
    {
        $change = 0.0;
        $movementCount = 0;
        foreach ($movements as $movement) {
            if (($movement['duplicate'] ?? false) === true) {
                continue;
            }
            $change += (float) ($movement['deposit'] ?? 0.0);
            $change -= (float) ($movement['withdrawal'] ?? 0.0);
            $movementCount++;
        }

        return [
            'before' => round($currentBalance, 2),
            'after' => round($currentBalance + $change, 2),
            'change' => round($change, 2),
            'movement_count' => $movementCount,
        ];
    }

    public function load(int $companyId, int $accountId, string $html, int $operatorId): array
    {
        $this->companies->accountForAnalysis($companyId, $accountId, $operatorId);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM bancos WHERE id = :id AND razon = :company FOR UPDATE');
            $lock->execute(['id' => $accountId, 'company' => $companyId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('La cuenta bancaria ya no está disponible.');
            }

            $analysis = $this->analyze($companyId, $accountId, $html, $operatorId);
            $newMovements = array_values(array_filter(
                $analysis['movements'],
                static fn (array $movement): bool => !$movement['duplicate']
            ));
            if ($newMovements === []) {
                throw new RuntimeException('Todos los movimientos detectados ya existen. No se cargó información duplicada.');
            }

            $now = new \DateTimeImmutable();
            $captureInsert = $this->pdo->prepare('INSERT INTO captura
                (razon, nombre_corto, captura, fecha, hora)
                VALUES (:company, :account, :capture, :date, :time)');
            $captureInsert->execute([
                'company' => (string) $companyId,
                'account' => (string) $accountId,
                'capture' => $html,
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
            ]);
            $captureId = (int) $this->pdo->lastInsertId();

            $statementInsert = $this->pdo->prepare("INSERT INTO captura_edo_cta
                (id_captura, fecha_operacion, hora_operacion, fecha, referencia, descripcion,
                 deposito, retiro, saldo, descrip_det, referencia_interb, tipo_movimiento,
                 fecha_sistema, asignado, cliente_asignado, facturado, cuenta)
                VALUES
                (:capture, :operation_date, :operation_time, :date, :reference, :description,
                 :deposit, :withdrawal, :balance, :detail, :interbank_reference, :movement_type,
                 :system_date, :assigned, :assigned_client, NULL, :account)");
            $paymentInsert = $this->pdo->prepare("INSERT INTO pagos
                (nombre, monto, factura, empresa, status, fecha_operacion, hora_operacion,
                 fecha_captura, tipo_movimiento, saldo_acreditable, banco, saldo,
                 usuario_registra, id_factura, fecha_banco, detalle, nombre_corto,
                 tipo_captura, descripcion, descripcion_detallada, id_captura,
                 id_captura_edo_cta, usuario_asigna_fac)
                VALUES
                (NULL, :amount, 'Sin Facturar', :company, 'sin asignar', :operation_date, :operation_time,
                 :captured_at, :movement_type, 0, :bank, 0,
                 :operator, 0, :bank_date, :detail, :short_name,
                 'Captura bancaria', :description, :detailed_description, :capture,
                 :statement, 9999)");
            $statementAssigned = $this->pdo->prepare("UPDATE captura_edo_cta
                SET asignado = 'si', cliente_asignado = :client WHERE id = :id");
            $logInsert = $this->pdo->prepare("INSERT INTO bitacora
                (usuario_registra, hora_registra, fecha_registra, accion, monto, empresa, cliente)
                VALUES (:operator, :time, :date, 'Captura', :amount, :company, :client)");

            $assigned = 0;
            $unassigned = 0;
            $commissions = 0;
            $commissionTotal = 0.0;
            $paymentIds = [];
            $automaticInvoiceIds = [];
            foreach ($newMovements as $movement) {
                $match = (array) ($movement['short_key'] ?? []);
                $clientId = !empty($match['associated']) ? (int) ($match['client_id'] ?? 0) : 0;
                $description = trim((string) ($movement['description'] ?? ''));
                $detail = trim((string) ($movement['detail'] ?? ''));
                $reference = trim((string) ($movement['reference'] ?? ''));
                $combinedDetail = trim(implode(' ', array_filter([$description, $detail, $reference])));

                $statementInsert->execute([
                    'capture' => $captureId,
                    'operation_date' => $movement['date'],
                    'operation_time' => $movement['time'] ?: null,
                    'date' => $movement['date'],
                    'reference' => $reference,
                    'description' => $description,
                    'deposit' => $movement['deposit'],
                    'withdrawal' => $movement['withdrawal'],
                    'balance' => $movement['balance'],
                    'detail' => $detail,
                    'interbank_reference' => $reference,
                    'movement_type' => $movement['type'],
                    'system_date' => $now->format('Y-m-d'),
                    'assigned' => $clientId > 0 ? 'si' : 'no',
                    'assigned_client' => $clientId > 0 ? (string) $clientId : null,
                    'account' => $accountId,
                ]);
                $statementId = (int) $this->pdo->lastInsertId();

                $paymentInsert->execute([
                    'amount' => $movement['amount'],
                    'company' => (string) $companyId,
                    'operation_date' => $movement['date'],
                    'operation_time' => $movement['time'] ?: null,
                    'captured_at' => $now->format('Y-m-d'),
                    'movement_type' => $movement['type'],
                    'bank' => (string) $analysis['account']['banco'],
                    'operator' => $operatorId,
                    'bank_date' => $movement['date'],
                    'detail' => $combinedDetail,
                    'short_name' => (string) $analysis['account']['nombre_corto'],
                    'description' => $description,
                    'detailed_description' => $detail,
                    'capture' => $captureId,
                    'statement' => $statementId,
                ]);
                $paymentId = (int) $this->pdo->lastInsertId();
                $paymentIds[] = $paymentId;

                if ($clientId > 0) {
                    $assignment = $this->movements->assignWithinTransaction($paymentId, $clientId, $operatorId);
                    $statementAssigned->execute(['client' => $clientId, 'id' => $statementId]);
                    $assigned++;
                    $commissions += (int) $assignment['commissions'];
                    $commissionTotal += (float) $assignment['commissionTotal'];

                    $automaticInvoiceId = $this->automaticInvoices->createForCapturedMovement(
                        $paymentId,
                        $captureId,
                        $companyId,
                        (int) ($match['key_id'] ?? 0),
                        (float) $movement['amount'],
                        (string) $movement['type'],
                        $operatorId,
                    );
                    if ($automaticInvoiceId !== null) {
                        $automaticInvoiceIds[] = $automaticInvoiceId;
                    }
                } else {
                    $unassigned++;
                }

                $logInsert->execute([
                    'operator' => $operatorId,
                    'time' => $now->format('H:i:s'),
                    'date' => $now->format('Y-m-d'),
                    'amount' => $movement['amount'],
                    'company' => $companyId,
                    'client' => $clientId > 0 ? $clientId : null,
                ]);
            }

            $this->pdo->commit();
            return [
                'captureId' => $captureId,
                'loaded' => count($newMovements),
                'duplicatesSkipped' => (int) $analysis['summary']['duplicates'],
                'assigned' => $assigned,
                'unassigned' => $unassigned,
                'commissions' => $commissions,
                'commissionTotal' => round($commissionTotal, 6),
                'paymentIds' => $paymentIds,
                'automaticInvoices' => count($automaticInvoiceIds),
                'automaticInvoiceIds' => $automaticInvoiceIds,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function manualCatalog(int $operatorId): array
    {
        return $this->companies->manualCaptureCatalog($operatorId);
    }

    public function manualPage(int $page, int $operatorId, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $companyIds = array_map(
            static fn (array $company): int => (int) $company['id'],
            $this->manualCatalog($operatorId)
        );
        if ($companyIds === []) {
            return ['rows' => [], 'page' => 1, 'perPage' => $perPage, 'hasNext' => false];
        }

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $offset = ($page - 1) * $perPage;
        $limit = $perPage + 1;

        // El sistema anterior listaba desde pagos/tipo_captura. Mantener ese punto de
        // entrada permite aprovechar el orden por ID y evita conteos completos costosos.
        $query = $this->pdo->prepare("SELECT c.id, CAST(c.razon AS UNSIGNED) AS company_id,
            c.fecha AS captured_date, c.hora AS captured_time,
            e.razon AS company, b.banco, b.nombre_corto, COALESCE(NULLIF(b.moneda, ''), 'MXN') AS currency,
            ce.id AS statement_id, ce.fecha AS bank_date, ce.tipo_movimiento,
            CASE WHEN ce.tipo_movimiento = 'Retiro' THEN ce.retiro ELSE ce.deposito END AS amount,
            ce.saldo AS balance, COALESCE(NULLIF(ce.descripcion, ''), ce.descrip_det) AS detail,
            p.id AS payment_id, cl.nombre AS client
            FROM pagos p
            INNER JOIN captura c ON c.id = p.id_captura
            INNER JOIN empresas e ON e.id = CAST(c.razon AS UNSIGNED)
            INNER JOIN bancos b ON b.id = CAST(c.nombre_corto AS UNSIGNED)
            INNER JOIN captura_edo_cta ce ON ce.id_captura = c.id
            LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            WHERE p.tipo_captura = 'Manual' AND p.banco <> '99'
              AND p.tipo_movimiento <> 'Abono por comisión'
              AND c.captura LIKE 'Captura Manual%'
              AND CAST(c.razon AS UNSIGNED) IN ({$placeholders})
            ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($companyIds);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'hasNext' => $hasNext,
        ];
    }

    public function createManual(array $input, int $operatorId): array
    {
        $companyId = (int) ($input['company_id'] ?? 0);
        $accountId = (int) ($input['account_id'] ?? 0);
        $movementType = trim((string) ($input['movement_type'] ?? ''));
        if (!in_array($movementType, ['Deposito', 'Retiro'], true)) {
            throw new RuntimeException('Selecciona si la captura es un depósito o un retiro.');
        }
        $amount = self::manualDecimal($input['amount'] ?? null, false, 'El monto');
        $balance = self::manualDecimal($input['balance'] ?? null, true, 'El saldo bancario');
        $bankDate = trim((string) ($input['bank_date'] ?? ''));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $bankDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $bankDate) {
            throw new RuntimeException('La fecha bancaria no es válida.');
        }
        $detail = trim((string) ($input['detail'] ?? ''));
        if ($detail === '') {
            throw new RuntimeException('El detalle del movimiento es obligatorio.');
        }
        if (mb_strlen($detail) > 500) {
            throw new RuntimeException('El detalle no puede superar 500 caracteres.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT id FROM bancos
                WHERE id = :account AND razon = :company AND status_banco = 'AC' FOR UPDATE");
            $lock->execute(['account' => $accountId, 'company' => $companyId]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('La cuenta bancaria no pertenece a la empresa seleccionada.');
            }
            $account = $this->companies->accountForAnalysis($companyId, $accountId, $operatorId);
            $match = $this->companies->analyzeShortKeys($companyId, $accountId, [$detail], $operatorId)[0] ?? [];
            $clientId = !empty($match['associated']) ? (int) ($match['client_id'] ?? 0) : 0;
            $now = new \DateTimeImmutable();
            $captureLabel = 'Captura Manual de ' . $now->format('Y-m-d H:i:s');

            $captureInsert = $this->pdo->prepare('INSERT INTO captura
                (razon, nombre_corto, captura, fecha, hora)
                VALUES (:company, :account, :capture, :date, :time)');
            $captureInsert->execute([
                'company' => (string) $companyId,
                'account' => (string) $accountId,
                'capture' => $captureLabel,
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
            ]);
            $captureId = (int) $this->pdo->lastInsertId();
            $deposit = $movementType === 'Deposito' ? $amount : 0.0;
            $withdrawal = $movementType === 'Retiro' ? $amount : 0.0;

            $statementInsert = $this->pdo->prepare("INSERT INTO captura_edo_cta
                (id_captura, fecha_operacion, fecha, referencia, descripcion, deposito, retiro,
                 saldo, descrip_det, tipo_movimiento, fecha_sistema, asignado, cliente_asignado, cuenta)
                VALUES
                (:capture, :bank_date, :bank_date_copy, 'Captura Manual', :description, :deposit, :withdrawal,
                 :balance, :detail, :movement_type, :system_date, :assigned, :client, :account)");
            $statementInsert->execute([
                'capture' => $captureId,
                'bank_date' => $bankDate,
                'bank_date_copy' => $bankDate,
                'description' => $detail,
                'deposit' => $deposit,
                'withdrawal' => $withdrawal,
                'balance' => $balance,
                'detail' => $detail,
                'movement_type' => $movementType,
                'system_date' => $now->format('Y-m-d'),
                'assigned' => $clientId > 0 ? 'si' : 'no',
                'client' => $clientId > 0 ? (string) $clientId : null,
                'account' => $accountId,
            ]);
            $statementId = (int) $this->pdo->lastInsertId();

            $paymentInsert = $this->pdo->prepare("INSERT INTO pagos
                (nombre, monto, factura, empresa, status, fecha_operacion, fecha_captura,
                 tipo_movimiento, saldo_acreditable, banco, saldo, usuario_registra, id_factura,
                 fecha_banco, detalle, nombre_corto, tipo_captura, descripcion,
                 descripcion_detallada, id_captura, id_captura_edo_cta, usuario_asigna_fac)
                VALUES
                (NULL, :amount, :invoice_status, :company, 'sin asignar', :operation_date, :captured_at,
                 :movement_type, 0, 'Manual', 0, :operator, 0,
                 :bank_date, :detail, :short_name, 'Manual', :description,
                 :detailed_description, :capture, :statement, 9999)");
            $paymentInsert->execute([
                'amount' => $amount,
                'invoice_status' => $movementType === 'Retiro' ? 'NA' : 'Sin Facturar',
                'company' => (string) $companyId,
                'operation_date' => $bankDate,
                'captured_at' => $now->format('Y-m-d'),
                'movement_type' => $movementType,
                'operator' => $operatorId,
                'bank_date' => $bankDate,
                'detail' => mb_strtoupper($detail),
                'short_name' => (string) $account['nombre_corto'],
                'description' => $detail,
                'detailed_description' => $detail,
                'capture' => $captureId,
                'statement' => $statementId,
            ]);
            $paymentId = (int) $this->pdo->lastInsertId();

            $assignment = null;
            $automaticInvoiceId = null;
            if ($clientId > 0) {
                $assignment = $this->movements->assignWithinTransaction($paymentId, $clientId, $operatorId);
                $automaticInvoiceId = $this->automaticInvoices->createForCapturedMovement(
                    $paymentId,
                    $captureId,
                    $companyId,
                    (int) ($match['key_id'] ?? 0),
                    $amount,
                    $movementType,
                    $operatorId,
                );
            }

            $log = $this->pdo->prepare("INSERT INTO bitacora
                (usuario_registra, hora_registra, fecha_registra, accion, monto, empresa, cliente)
                VALUES (:operator, :time, :date, 'Captura Manual', :amount, :company, :client)");
            $log->execute([
                'operator' => $operatorId,
                'time' => $now->format('H:i:s'),
                'date' => $now->format('Y-m-d'),
                'amount' => $amount,
                'company' => $companyId,
                'client' => $clientId > 0 ? $clientId : null,
            ]);

            $this->pdo->commit();
            return [
                'captureId' => $captureId,
                'statementId' => $statementId,
                'paymentId' => $paymentId,
                'assignedClientId' => $clientId,
                'assignedClient' => $clientId > 0 ? (string) ($match['client'] ?? '') : '',
                'commissions' => (int) ($assignment['commissions'] ?? 0),
                'automaticInvoiceId' => $automaticInvoiceId,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteManual(int $captureId, int $operatorId): void
    {
        if ($captureId <= 0) {
            throw new RuntimeException('La captura manual seleccionada no es válida.');
        }
        $this->pdo->beginTransaction();
        try {
            $captureQuery = $this->pdo->prepare("SELECT c.id, CAST(c.razon AS UNSIGNED) AS company_id,
                CAST(c.nombre_corto AS UNSIGNED) AS account_id, c.captura
                FROM captura c WHERE c.id = :id FOR UPDATE");
            $captureQuery->execute(['id' => $captureId]);
            $capture = $captureQuery->fetch();
            if (!$capture) {
                throw new RuntimeException('La captura manual ya no existe.');
            }
            $this->companies->accountForAnalysis(
                (int) $capture['company_id'],
                (int) $capture['account_id'],
                $operatorId
            );
            $manualCheck = $this->pdo->prepare("SELECT COUNT(*) FROM pagos
                WHERE id_captura = :capture AND tipo_captura = 'Manual'");
            $manualCheck->execute(['capture' => $captureId]);
            if (!str_starts_with((string) $capture['captura'], 'Captura Manual')
                && (int) $manualCheck->fetchColumn() === 0) {
                throw new RuntimeException('Solo se pueden eliminar capturas creadas manualmente desde este módulo.');
            }

            $payments = $this->pdo->prepare('SELECT id, id_factura, id_factura_res FROM pagos
                WHERE id_captura = :capture FOR UPDATE');
            $payments->execute(['capture' => $captureId]);
            $paymentRows = $payments->fetchAll();
            foreach ($paymentRows as $payment) {
                $complementId = trim((string) ($payment['id_factura_res'] ?? ''));
                if (preg_match('/^[1-9]\d*$/D', $complementId)) {
                    throw new RuntimeException('La captura tiene un complemento relacionado y no puede eliminarse.');
                }
            }

            $invoices = $this->pdo->prepare("SELECT DISTINCT f.id, f.uuid
                FROM facturas f LEFT JOIN pagos p ON p.id_captura = :payment_capture
                WHERE f.id_captura = :invoice_capture OR f.id_pago = p.id OR f.id = p.id_factura
                FOR UPDATE");
            $invoices->execute(['payment_capture' => $captureId, 'invoice_capture' => $captureId]);
            $invoiceRows = $invoices->fetchAll();
            foreach ($invoiceRows as $invoice) {
                if (trim((string) ($invoice['uuid'] ?? '')) !== '') {
                    throw new RuntimeException('La captura tiene una factura timbrada y no puede eliminarse.');
                }
                $related = $this->pdo->prepare('SELECT COUNT(*) FROM facturar_rel_complementos WHERE id_factura = :id');
                $related->execute(['id' => $invoice['id']]);
                if ((int) $related->fetchColumn() > 0) {
                    throw new RuntimeException('La captura tiene una factura relacionada con un complemento y no puede eliminarse.');
                }
            }
            foreach ($invoiceRows as $invoice) {
                $invoiceId = (int) $invoice['id'];
                $this->pdo->prepare('DELETE FROM detalle_factura WHERE id_factura = :id')->execute(['id' => $invoiceId]);
                if ($this->tableExists('facturas_fav')) {
                    $this->pdo->prepare('DELETE FROM facturas_fav WHERE id_factura = :id')->execute(['id' => $invoiceId]);
                }
                $this->pdo->prepare('DELETE FROM facturas WHERE id = :id')->execute(['id' => $invoiceId]);
            }

            $paymentIds = array_map(static fn (array $payment): int => (int) $payment['id'], $paymentRows);
            if ($paymentIds !== []) {
                $paymentPlaceholders = implode(',', array_fill(0, count($paymentIds), '?'));
                if ($this->tableExists('relacion_factura_pago')) {
                    $this->pdo->prepare("DELETE FROM relacion_factura_pago WHERE id_pago IN ({$paymentPlaceholders})")
                        ->execute($paymentIds);
                }
                if ($this->tableExists('s14_movimiento_comision_links')) {
                    $linkParams = array_merge($paymentIds, $paymentIds);
                    $this->pdo->prepare("DELETE FROM s14_movimiento_comision_links
                        WHERE pago_origen_id IN ({$paymentPlaceholders})
                           OR pago_comision_id IN ({$paymentPlaceholders})")->execute($linkParams);
                }
            }
            $this->pdo->prepare('DELETE FROM pagos WHERE id_captura = :capture')->execute(['capture' => $captureId]);
            $this->pdo->prepare('DELETE FROM captura_edo_cta WHERE id_captura = :capture')->execute(['capture' => $captureId]);
            $this->pdo->prepare('DELETE FROM captura WHERE id = :capture')->execute(['capture' => $captureId]);

            $now = new \DateTimeImmutable();
            $log = $this->pdo->prepare("INSERT INTO bitacora
                (usuario_registra, hora_registra, fecha_registra, accion, monto, empresa)
                VALUES (:operator, :time, :date, 'Eliminar Captura Manual', 0, :company)");
            $log->execute([
                'operator' => $operatorId,
                'time' => $now->format('H:i:s'),
                'date' => $now->format('Y-m-d'),
                'company' => (int) $capture['company_id'],
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function manualDecimal(mixed $value, bool $allowNegative, string $label): float
    {
        $normalized = str_replace([',', '$', ' '], '', trim((string) $value));
        if ($normalized === '' || !is_numeric($normalized)) {
            throw new RuntimeException($label . ' debe ser un número válido.');
        }
        $number = (float) $normalized;
        if (!is_finite($number) || (!$allowNegative && $number <= 0)) {
            throw new RuntimeException($allowNegative
                ? $label . ' debe ser un número válido.'
                : $label . ' debe ser mayor que cero.');
        }
        if (abs($number) > 999999999999.99) {
            throw new RuntimeException($label . ' supera el importe permitido.');
        }
        return round($number, 2);
    }

    private function tableExists(string $table): bool
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = :table');
        $query->execute(['table' => $table]);
        return (int) $query->fetchColumn() > 0;
    }

    private function tableRows(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
            }
            if ($cells !== []) $rows[] = $cells;
        }
        return $rows;
    }

    private function detectColumns(array $rows): array
    {
        $aliases = [
            'date' => ['fecha', 'fecha operacion', 'fecha de operacion', 'fecha movimiento', 'dia'],
            'time' => ['hora', 'hora operacion', 'hora de operacion'],
            'description' => ['descripcion', 'concepto', 'movimiento', 'tipo', 'operacion'],
            'detail' => ['detalle', 'concepto spei', 'spei concepto', 'comentario', 'leyenda'],
            'reference' => ['referencia', 'clave rastreo', 'clave de rastreo', 'spei clave rastreo', 'spei referencia'],
            'origin_name' => ['spei cuenta origen nombre', 'cuenta origen nombre', 'nombre ordenante', 'ordenante'],
            'beneficiary_name' => ['spei beneficiario', 'beneficiario', 'nombre beneficiario'],
            'debit' => ['retiro', 'retiros', 'cargo', 'cargos', 'debito', 'importe cargo'],
            'credit' => ['deposito', 'depositos', 'abono', 'abonos', 'credito', 'importe abono'],
            'amount' => ['importe', 'monto', 'cantidad'],
            'balance' => ['saldo', 'saldo disponible'],
        ];
        $bestIndex = null;
        $bestColumns = [];
        foreach (array_slice($rows, 0, 10, true) as $rowIndex => $row) {
            $columns = [];
            foreach ($row as $columnIndex => $heading) {
                $normalized = $this->normalize($heading);
                foreach ($aliases as $field => $options) {
                    if (isset($columns[$field])) continue;
                    foreach ($options as $option) {
                        if ($normalized === $option || str_starts_with($normalized, $option . ' ')
                            || ($field === 'description' && str_starts_with($normalized, 'descripci'))) {
                            $columns[$field] = $columnIndex;
                            break;
                        }
                    }
                }
            }
            if (count($columns) > count($bestColumns)) {
                $bestIndex = $rowIndex;
                $bestColumns = $columns;
            }
        }
        return [$bestIndex, $bestColumns];
    }

    private function movementFromRow(array $row, array $columns): ?array
    {
        $date = $this->date((string) ($row[$columns['date']] ?? ''));
        if ($date === null) return null;
        $credit = isset($columns['credit']) ? $this->money((string) ($row[$columns['credit']] ?? '')) : 0.0;
        $debit = isset($columns['debit']) ? $this->money((string) ($row[$columns['debit']] ?? '')) : 0.0;
        $amount = isset($columns['amount']) ? $this->money((string) ($row[$columns['amount']] ?? '')) : 0.0;
        if ($credit == 0.0 && $debit == 0.0 && $amount != 0.0) {
            if ($amount < 0) $debit = abs($amount); else $credit = $amount;
        }
        if ($credit == 0.0 && $debit == 0.0) return null;
        $type = $credit != 0.0 ? 'Deposito' : 'Retiro';
        $detailParts = array_values(array_unique(array_filter(array_map('trim', [
            (string) ($row[$columns['detail'] ?? -1] ?? ''),
            (string) ($row[$columns['origin_name'] ?? -1] ?? ''),
            (string) ($row[$columns['beneficiary_name'] ?? -1] ?? ''),
        ]), static fn (string $value): bool => $value !== '')));
        return [
            'date' => $date,
            'time' => trim((string) ($row[$columns['time'] ?? -1] ?? '')),
            'description' => trim((string) ($row[$columns['description'] ?? -1] ?? '')),
            'detail' => implode(' · ', $detailParts),
            'reference' => trim((string) ($row[$columns['reference'] ?? -1] ?? '')),
            'type' => $type, 'amount' => abs($type === 'Deposito' ? $credit : $debit),
            'deposit' => abs($credit), 'withdrawal' => abs($debit),
            'balance' => isset($columns['balance']) ? $this->money((string) ($row[$columns['balance']] ?? '')) : 0.0,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','_'=>' ']);
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d/m/y', '!d-m-y', '!d-M-Y', '!dmY'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format(str_replace('!', '', $format)) === $value) return $date->format('Y-m-d');
        }
        return null;
    }

    private function money(string $value): float
    {
        $value = preg_replace('/[^0-9,.-]/', '', trim($value)) ?? '';
        if ($value === '' || $value === '-') return 0.0;
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $thousand = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousand, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false) {
            $value = preg_match('/,\d{1,2}$/', $value) ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
