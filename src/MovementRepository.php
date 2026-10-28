<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class MovementRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->ensureSupportTables();
    }

    public function page(string $view, array $definition, string $search, int $page, int $perPage = 25, ?int $clientId = null): array
    {
        $filterByClient = $clientId !== null && $clientId > 0 && in_array($view, ['asignados', 'reasignar', 'totales', 'cartera'], true);
        [$source, $sort] = $this->source($view, $filterByClient);
        $columns = array_keys($definition['columns']);
        $searchableColumns = $columns;
        foreach ($definition['columns'] as $column) {
            if (!is_array($column)) {
                continue;
            }
            foreach ((array) ($column['secondary'] ?? []) as $key => $value) {
                $searchableColumns[] = is_int($key) ? (string) $value : (string) $key;
            }
        }
        $searchableColumns = array_values(array_unique($searchableColumns));
        $queryText = 'SELECT * FROM (' . $source . ') movement_data';
        $params = [];
        $conditions = [];
        if ($filterByClient) {
            $conditions[] = 'client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        if ($search !== '') {
            $searchColumns = implode(', ', array_map([$this, 'identifier'], $searchableColumns));
            $conditions[] = "CONCAT_WS(' ', {$searchColumns}) LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        if ($conditions !== []) {
            $queryText .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $queryText . ') movement_count');
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $query = $this->pdo->prepare($queryText . " ORDER BY {$sort} LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($params);

        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    public function clients(): array
    {
        return $this->pdo->query("SELECT id, nombre FROM clientes WHERE COALESCE(activo, 1) = 1 ORDER BY nombre")->fetchAll();
    }

    public function client(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT id, nombre FROM clientes WHERE id = :id');
        $query->execute(['id' => $id]);
        $client = $query->fetch();
        return $client ?: null;
    }

    public function assign(int $paymentId, int $clientId, int $operatorId): array
    {
        return $this->transaction(function () use ($paymentId, $clientId, $operatorId): array {
            return $this->assignInternal($paymentId, $clientId, $operatorId);
        });
    }

    public function previewAssignments(array $assignments): array
    {
        $assignments = $this->normalizeAssignments($assignments);
        $iva = (float) ($this->pdo->query('SELECT iva FROM configuracion ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 16);
        $paymentQuery = $this->pdo->prepare('SELECT * FROM pagos WHERE id = :id');
        $clientQuery = $this->pdo->prepare(
            'SELECT id, nombre, empresa, comision, esquema_comision,
                    1_subcomision AS sub_1, 2_subcomision AS sub_2, 3_subcomision AS sub_3, 4_subcomision AS sub_4,
                    porc_1_subcomision AS pct_1, porc_2_subcomision AS pct_2,
                    porc_3_subcomision AS pct_3, porc_4_subcomision AS pct_4
             FROM clientes WHERE id = :id'
        );

        $rows = [];
        $recipientIds = [1];
        foreach ($assignments as $paymentId => $clientId) {
            $paymentQuery->execute(['id' => $paymentId]);
            $payment = $paymentQuery->fetch();
            if (!$payment) {
                throw new RuntimeException("El movimiento #{$paymentId} no existe.");
            }
            $this->assertAssignable($payment);

            $clientQuery->execute(['id' => $clientId]);
            $client = $clientQuery->fetch();
            if (!$client) {
                throw new RuntimeException("La cartera seleccionada para el movimiento #{$paymentId} no existe.");
            }

            $calculation = $this->calculate((float) $payment['monto'], (string) $payment['tipo_movimiento'], $client, $iva);
            $commissions = [];
            foreach ($calculation['subcommissions'] as $commission) {
                $recipientIds[] = $commission['clientId'];
                $commissions[] = [
                    'recipientId' => $commission['clientId'], 'type' => 'Subcomisión',
                    'rate' => $commission['rate'], 'amount' => $commission['amount'],
                ];
            }
            if ($calculation['houseCommission'] > 0) {
                $commissions[] = [
                    'recipientId' => 1, 'type' => 'Comisión principal',
                    'rate' => (float) ($client['comision'] ?? 0), 'amount' => $calculation['houseCommission'],
                ];
            }

            $recipientIds[] = $clientId;
            $rows[] = [
                'paymentId' => $paymentId,
                'amount' => (float) $payment['monto'],
                'movementType' => (string) $payment['tipo_movimiento'],
                'clientId' => $clientId,
                'clientName' => (string) $client['nombre'],
                'scheme' => $calculation['scheme'],
                'mainCredit' => $calculation['mainCredit'],
                'commissions' => $commissions,
                'commissionTotal' => round(array_sum(array_column($commissions, 'amount')), 6),
            ];
        }

        $names = [];
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
        if ($recipientIds !== []) {
            $query = $this->pdo->prepare('SELECT id, nombre FROM clientes WHERE id IN (' . implode(',', array_fill(0, count($recipientIds), '?')) . ')');
            $query->execute($recipientIds);
            foreach ($query->fetchAll() as $recipient) {
                $names[(int) $recipient['id']] = (string) $recipient['nombre'];
            }
        }

        $distributed = [];
        foreach ($rows as &$row) {
            foreach ($row['commissions'] as &$commission) {
                $recipientId = (int) $commission['recipientId'];
                $commission['recipientName'] = $names[$recipientId] ?? "Cartera #{$recipientId}";
                if (!isset($distributed[$recipientId])) {
                    $distributed[$recipientId] = ['recipientId' => $recipientId, 'recipientName' => $commission['recipientName'], 'amount' => 0.0];
                }
                $distributed[$recipientId]['amount'] += (float) $commission['amount'];
            }
            unset($commission);
        }
        unset($row);
        foreach ($distributed as &$recipient) {
            $recipient['amount'] = round($recipient['amount'], 6);
        }
        unset($recipient);

        return [
            'movementCount' => count($rows),
            'commissionCount' => array_sum(array_map(static fn (array $row): int => count($row['commissions']), $rows)),
            'commissionTotal' => round(array_sum(array_column($rows, 'commissionTotal')), 6),
            'rows' => $rows,
            'distributed' => array_values($distributed),
        ];
    }

    public function assignBatch(array $assignments, int $operatorId): array
    {
        $assignments = $this->normalizeAssignments($assignments);

        return $this->transaction(function () use ($assignments, $operatorId): array {
            $this->assertOperator($operatorId);
            $results = [];
            foreach ($assignments as $paymentId => $clientId) {
                $results[] = $this->assignInternal($paymentId, $clientId, $operatorId);
            }

            return [
                'movements' => count($results),
                'commissions' => array_sum(array_column($results, 'commissions')),
            ];
        });
    }

    public function unassign(int $paymentId, int $operatorId): array
    {
        return $this->transaction(function () use ($paymentId, $operatorId): array {
            return $this->unassignInternal($paymentId, $operatorId);
        });
    }

    public function reassign(int $paymentId, int $clientId, int $operatorId): array
    {
        return $this->transaction(function () use ($paymentId, $clientId, $operatorId): array {
            $removed = $this->unassignInternal($paymentId, $operatorId);
            $assigned = $this->assignInternal($paymentId, $clientId, $operatorId);
            $assigned['removedCommissions'] = $removed['removedCommissions'];
            return $assigned;
        });
    }

    public function createTransfer(int $originId, int $destinationId, float $amount, string $detail, int $operatorId): array
    {
        if ($originId <= 0 || $destinationId <= 0 || $originId === $destinationId) {
            throw new RuntimeException('Selecciona dos carteras diferentes.');
        }
        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('El monto del traspaso debe ser mayor que cero.');
        }
        $detail = trim($detail);
        if ($detail === '') {
            throw new RuntimeException('El detalle del traspaso es obligatorio.');
        }

        return $this->transaction(function () use ($originId, $destinationId, $amount, $detail, $operatorId): array {
            $this->lockClients([$originId, $destinationId]);
            $this->assertOperator($operatorId);
            $date = date('Y-m-d');
            $originBalance = $this->balance($originId) - $amount;
            $destinationBalance = $this->balance($destinationId) + $amount;

            $insert = $this->pdo->prepare(
                "INSERT INTO pagos
                (nombre, monto, factura, empresa, status, fecha_asignacion, fecha_captura,
                 tipo_movimiento, saldo_acreditable, banco, saldo, usuario_registra, fecha_banco,
                 detalle, nombre_corto, tipo_captura, descripcion, origen_traspaso,
                 destino_traspaso, cliente_origen_comision, usuario_asigna_fac)
                VALUES
                (:client, :amount, 'NA', '99', 'asignado', :assigned_at, :captured_at,
                 :movement_type, :credit, '99', :balance, :operator, :bank_date,
                 :detail, '99', 'Manual', 'Traspaso', :origin, :destination,
                 :commission_origin, :operator_assign)"
            );

            $insert->execute([
                'client' => $destinationId, 'amount' => $amount, 'assigned_at' => $date,
                'captured_at' => $date, 'movement_type' => 'Deposito', 'credit' => $amount,
                'balance' => $destinationBalance, 'operator' => $operatorId, 'bank_date' => $date,
                'detail' => $detail, 'origin' => $originId, 'destination' => $destinationId,
                'commission_origin' => $originId, 'operator_assign' => $operatorId,
            ]);
            $entryId = (int) $this->pdo->lastInsertId();

            $insert->execute([
                'client' => $originId, 'amount' => -$amount, 'assigned_at' => $date,
                'captured_at' => $date, 'movement_type' => 'Retiro', 'credit' => -$amount,
                'balance' => $originBalance, 'operator' => $operatorId, 'bank_date' => $date,
                'detail' => $detail, 'origin' => $originId, 'destination' => $destinationId,
                'commission_origin' => $destinationId, 'operator_assign' => $operatorId,
            ]);
            $exitId = (int) $this->pdo->lastInsertId();

            $link = $this->pdo->prepare('INSERT INTO s14_traspaso_links (pago_entrada_id, pago_salida_id) VALUES (:entry, :exit)');
            $link->execute(['entry' => $entryId, 'exit' => $exitId]);
            $this->audit('transfer_create', $operatorId, $entryId, ['exitId' => $exitId, 'amount' => $amount, 'origin' => $originId, 'destination' => $destinationId]);

            return ['entryId' => $entryId, 'exitId' => $exitId];
        });
    }

    public function deleteTransfer(int $entryId, int $operatorId): array
    {
        return $this->transaction(function () use ($entryId, $operatorId): array {
            $this->assertOperator($operatorId);
            $entry = $this->paymentForUpdate($entryId);
            if (($entry['descripcion'] ?? '') !== 'Traspaso' || ($entry['tipo_movimiento'] ?? '') !== 'Deposito') {
                throw new RuntimeException('El movimiento seleccionado no es la entrada de un traspaso.');
            }

            $link = $this->pdo->prepare('SELECT pago_salida_id FROM s14_traspaso_links WHERE pago_entrada_id = :id FOR UPDATE');
            $link->execute(['id' => $entryId]);
            $exitId = (int) ($link->fetchColumn() ?: 0);
            if ($exitId === 0) {
                $legacy = $this->pdo->prepare(
                    "SELECT id FROM pagos
                     WHERE descripcion = 'Traspaso' AND tipo_movimiento = 'Retiro'
                       AND origen_traspaso <=> :origin AND destino_traspaso <=> :destination
                       AND fecha_asignacion <=> :assigned_at AND detalle <=> :detail
                       AND ABS(monto + :amount) < 0.00001
                     ORDER BY ABS(id - :entry_id) LIMIT 1 FOR UPDATE"
                );
                $legacy->execute([
                    'origin' => $entry['origen_traspaso'], 'destination' => $entry['destino_traspaso'],
                    'assigned_at' => $entry['fecha_asignacion'], 'detail' => $entry['detalle'],
                    'amount' => abs((float) $entry['monto']), 'entry_id' => $entryId,
                ]);
                $exitId = (int) ($legacy->fetchColumn() ?: 0);
            }
            if ($exitId === 0) {
                throw new RuntimeException('No se encontró la salida compensatoria; no se eliminó ningún movimiento.');
            }

            $delete = $this->pdo->prepare('DELETE FROM pagos WHERE id IN (:entry, :exit)');
            $delete->execute(['entry' => $entryId, 'exit' => $exitId]);
            $this->pdo->prepare('DELETE FROM s14_traspaso_links WHERE pago_entrada_id = :id')->execute(['id' => $entryId]);
            $this->audit('transfer_delete', $operatorId, $entryId, ['exitId' => $exitId, 'entry' => $entry]);

            return ['entryId' => $entryId, 'exitId' => $exitId];
        });
    }

    private function assignInternal(int $paymentId, int $clientId, int $operatorId): array
    {
        $payment = $this->paymentForUpdate($paymentId);
        $this->assertOperator($operatorId);
        $this->assertAssignable($payment);

        $clientQuery = $this->pdo->prepare(
            'SELECT id, nombre, empresa, comision, esquema_comision,
                    1_subcomision AS sub_1, 2_subcomision AS sub_2, 3_subcomision AS sub_3, 4_subcomision AS sub_4,
                    porc_1_subcomision AS pct_1, porc_2_subcomision AS pct_2,
                    porc_3_subcomision AS pct_3, porc_4_subcomision AS pct_4
             FROM clientes WHERE id = :id FOR UPDATE'
        );
        $clientQuery->execute(['id' => $clientId]);
        $client = $clientQuery->fetch();
        if (!$client) {
            throw new RuntimeException('La cartera seleccionada no existe.');
        }

        $iva = (float) ($this->pdo->query('SELECT iva FROM configuracion ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 16);
        $calculation = $this->calculate((float) $payment['monto'], (string) $payment['tipo_movimiento'], $client, $iva);
        $recipientIds = [$clientId, 1];
        foreach ($calculation['subcommissions'] as $commission) {
            $recipientIds[] = $commission['clientId'];
        }
        $this->lockClients($recipientIds);

        $date = date('Y-m-d');
        $newBalance = $this->balance($clientId) + $calculation['mainCredit'];
        $update = $this->pdo->prepare(
            "UPDATE pagos SET status = 'asignado', fecha_asignacion = :date,
             saldo_acreditable = :credit, saldo = :balance, nombre = :client,
             usuario_asigna_fac = :operator WHERE id = :id"
        );
        $update->execute([
            'date' => $date, 'credit' => $calculation['mainCredit'], 'balance' => $newBalance,
            'client' => $clientId, 'operator' => $operatorId, 'id' => $paymentId,
        ]);

        $commissionIds = [];
        foreach ($calculation['subcommissions'] as $commission) {
            $commissionIds[] = $this->insertCommission($payment, $clientId, $commission['clientId'], $commission['amount'], $operatorId, $date);
        }
        if ($calculation['houseCommission'] > 0) {
            $commissionIds[] = $this->insertCommission($payment, $clientId, 1, $calculation['houseCommission'], $operatorId, $date);
        }

        if ($commissionIds !== []) {
            $link = $this->pdo->prepare('INSERT INTO s14_movimiento_comision_links (pago_origen_id, pago_comision_id) VALUES (:origin, :commission)');
            foreach ($commissionIds as $commissionId) {
                $link->execute(['origin' => $paymentId, 'commission' => $commissionId]);
            }
        }
        $this->audit('assign', $operatorId, $paymentId, ['clientId' => $clientId, 'calculation' => $calculation, 'commissionIds' => $commissionIds]);

        return ['paymentId' => $paymentId, 'clientId' => $clientId, 'commissions' => count($commissionIds), 'credit' => $calculation['mainCredit']];
    }

    private function normalizeAssignments(array $assignments): array
    {
        $normalized = [];
        foreach ($assignments as $paymentId => $clientId) {
            $paymentId = (int) $paymentId;
            $clientId = (int) $clientId;
            if ($paymentId > 0 && $clientId > 0) {
                $normalized[$paymentId] = $clientId;
            }
        }
        if ($normalized === []) {
            throw new RuntimeException('Selecciona una cartera para al menos un movimiento antes de continuar.');
        }
        if (count($normalized) > 100) {
            throw new RuntimeException('No se pueden asignar más de 100 movimientos en una sola operación.');
        }
        return $normalized;
    }

    private function assertAssignable(array $payment): void
    {
        if (!in_array($payment['nombre'], [null, '', '0', 0], true)) {
            throw new RuntimeException('El movimiento #' . (int) $payment['id'] . ' ya está asignado. Actualiza el listado e inténtalo nuevamente.');
        }
        if (($payment['descripcion'] ?? '') === 'Traspaso' || str_starts_with((string) $payment['tipo_movimiento'], 'Abono por comisi')) {
            throw new RuntimeException('El movimiento #' . (int) $payment['id'] . ' no admite asignación manual.');
        }
    }

    private function unassignInternal(int $paymentId, int $operatorId): array
    {
        $payment = $this->paymentForUpdate($paymentId);
        $this->assertOperator($operatorId);
        if (in_array($payment['nombre'], [null, '', '0', 0], true)) {
            throw new RuntimeException('El movimiento ya está sin asignar.');
        }
        if (($payment['descripcion'] ?? '') === 'Traspaso' || str_starts_with((string) $payment['tipo_movimiento'], 'Abono por comisi')) {
            throw new RuntimeException('Este movimiento debe revertirse desde su flujo de origen.');
        }

        $linked = $this->pdo->prepare('SELECT pago_comision_id FROM s14_movimiento_comision_links WHERE pago_origen_id = :id FOR UPDATE');
        $linked->execute(['id' => $paymentId]);
        $commissionIds = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));

        if ($commissionIds === []) {
            $legacy = $this->pdo->prepare(
                "SELECT id FROM pagos
                 WHERE monto = :amount AND fecha_asignacion <=> :assigned_at
                   AND tipo_movimiento LIKE 'Abono por comisi%'
                   AND id_captura <=> :capture_id
                   AND cliente_origen_comision <=> :origin_client
                   AND detalle = 'Abono de porcentaje por comision'
                 FOR UPDATE"
            );
            $legacy->execute([
                'amount' => $payment['monto'], 'assigned_at' => $payment['fecha_asignacion'],
                'capture_id' => $payment['id_captura'], 'origin_client' => $payment['nombre'],
            ]);
            $commissionIds = array_map('intval', $legacy->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($commissionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($commissionIds), '?'));
            $delete = $this->pdo->prepare("DELETE FROM pagos WHERE id IN ({$placeholders}) AND tipo_movimiento LIKE 'Abono por comisi%'");
            $delete->execute($commissionIds);
        }
        $this->pdo->prepare('DELETE FROM s14_movimiento_comision_links WHERE pago_origen_id = :id')->execute(['id' => $paymentId]);

        $update = $this->pdo->prepare(
            "UPDATE pagos SET nombre = NULL, status = 'sin asignar', fecha_asignacion = :date,
             saldo_acreditable = 0, saldo = 0, notificado = 'no', notificado_whats = 0
             WHERE id = :id"
        );
        $update->execute(['date' => date('Y-m-d'), 'id' => $paymentId]);
        $this->audit('unassign', $operatorId, $paymentId, ['previous' => $payment, 'removedCommissionIds' => $commissionIds]);

        return ['paymentId' => $paymentId, 'removedCommissions' => count($commissionIds)];
    }

    private function calculate(float $amount, string $movementType, array $client, float $iva): array
    {
        if ($movementType !== 'Deposito') {
            return ['mainCredit' => -abs($amount), 'houseCommission' => 0.0, 'subcommissions' => [], 'scheme' => 'Retiro'];
        }

        $commissionRate = (float) ($client['comision'] ?? 0);
        $subs = [];
        $subRateTotal = 0.0;
        for ($i = 1; $i <= 4; $i++) {
            $recipient = (int) ($client['sub_' . $i] ?? 0);
            $rate = (float) ($client['pct_' . $i] ?? 0);
            if ($recipient > 0) {
                $subs[] = ['clientId' => $recipient, 'rate' => $rate, 'amount' => 0.0];
                $subRateTotal += $rate;
            }
        }

        $scheme = trim((string) ($client['esquema_comision'] ?? ''));
        $house = 0.0;
        if ($scheme === 'Total Invertido') {
            $totalRate = $commissionRate + $subRateTotal;
            if ($totalRate <= 0) {
                return ['mainCredit' => $amount, 'houseCommission' => 0.0, 'subcommissions' => [], 'scheme' => $scheme];
            }
            $main = $amount / (1 + ($totalRate / 100));
            $commissionPool = $amount - $main;
            $house = $commissionPool * ($commissionRate / $totalRate);
            foreach ($subs as &$sub) {
                $sub['amount'] = $commissionPool * ($sub['rate'] / $totalRate);
            }
            unset($sub);
        } elseif ($scheme === 'Subtotal') {
            $subtotal = $amount / (1 + ($iva / 100));
            $house = $subtotal * ($commissionRate / 100);
            foreach ($subs as &$sub) {
                $sub['amount'] = $subtotal * ($sub['rate'] / 100);
            }
            unset($sub);
            $main = $amount - $house - array_sum(array_column($subs, 'amount'));
        } else {
            $house = $amount * ($commissionRate / 100);
            foreach ($subs as &$sub) {
                $sub['amount'] = $amount * ($sub['rate'] / 100);
            }
            unset($sub);
            $main = $amount - $house - array_sum(array_column($subs, 'amount'));
            $scheme = $scheme === '' ? 'Total' : $scheme;
        }

        return [
            'mainCredit' => round($main, 6),
            'houseCommission' => round($house, 6),
            'subcommissions' => array_map(static function (array $sub): array {
                $sub['amount'] = round((float) $sub['amount'], 6);
                return $sub;
            }, $subs),
            'scheme' => $scheme,
        ];
    }

    private function insertCommission(array $source, int $originClient, int $recipientId, float $credit, int $operatorId, string $date): int
    {
        $balance = $this->balance($recipientId) + $credit;
        $query = $this->pdo->prepare(
            "INSERT INTO pagos
             (nombre, monto, factura, empresa, status, fecha_asignacion, fecha_captura,
              tipo_movimiento, saldo_acreditable, banco, saldo, usuario_registra, id_factura,
              fecha_banco, detalle, cliente_origen_comision, id_captura, usuario_asigna_fac)
             VALUES
             (:recipient, :amount, 'NA', :company, 'asignado', :assigned_at, :captured_at,
              'Abono por comisión', :credit, :bank, :balance, :operator, 0,
              :bank_date, 'Abono de porcentaje por comision', :origin_client, :capture_id, :operator_assign)"
        );
        $query->execute([
            'recipient' => $recipientId, 'amount' => $source['monto'], 'company' => $source['empresa'],
            'assigned_at' => $date, 'captured_at' => $date, 'credit' => $credit,
            'bank' => $source['banco'], 'balance' => $balance, 'operator' => $operatorId,
            'bank_date' => $date, 'origin_client' => $originClient, 'capture_id' => $source['id_captura'],
            'operator_assign' => $operatorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function source(string $view, bool $filterByClient = false): array
    {
        $base = "SELECT p.id, p.nombre AS client_id, COALESCE(e.razon, p.empresa) AS empresa, cl.nombre AS cliente,
                        COALESCE(b.nombre_corto, p.nombre_corto) AS cuenta, p.banco, p.monto,
                        COALESCE(p.saldo_acreditable, 0) AS saldo_acreditable, p.tipo_movimiento,
                        CONCAT_WS(' ', p.detalle, p.descripcion, p.descripcion_detallada) AS detalles,
                        COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura) AS fecha_banco,
                        p.fecha_asignacion, u.nombre AS asigno
                 FROM pagos p
                 LEFT JOIN captura c ON c.id = p.id_captura
                 LEFT JOIN bancos b ON b.id = c.nombre_corto
                 LEFT JOIN empresas e ON e.id = COALESCE(c.razon, p.empresa)
                 LEFT JOIN clientes cl ON cl.id = p.nombre
                 LEFT JOIN usuarios u ON u.id = p.usuario_asigna_fac";

        return match ($view) {
            'sin-asignar' => [
                $base . " WHERE (p.nombre = 0 OR p.nombre = '' OR p.nombre IS NULL)
                          AND p.id <> 212733 AND p.monto NOT IN (7.5, 2.88, 8, 1.28, 1.2, 0.8, 5)",
                '`id` DESC',
            ],
            'asignados', 'reasignar' => [
                $base . " WHERE p.status = 'asignado' AND p.tipo_movimiento <> 'Abono por comisión'
                          AND p.banco <> 'STP' AND p.banco <> '99'" . ($filterByClient ? '' : ' ORDER BY p.id DESC LIMIT 500'),
                '`id` DESC',
            ],
            'totales' => [
                "SELECT p.id, p.nombre AS client_id, COALESCE(e.razon, p.empresa) AS empresa, cl.nombre AS cliente, p.monto,
                        p.tipo_movimiento, p.banco, p.fecha_banco, p.fecha_asignacion,
                        c.fecha AS fecha_captura, c.hora AS hora_captura, u.nombre AS asigno
                 FROM pagos p
                 LEFT JOIN empresas e ON e.id = p.empresa
                 LEFT JOIN clientes cl ON cl.id = p.nombre
                 LEFT JOIN usuarios u ON u.id = p.usuario_asigna_fac
                 LEFT JOIN captura c ON c.id = p.id_captura
                 WHERE p.tipo_movimiento <> 'Abono por comisión' AND p.banco <> 'STP' AND p.banco <> '99'
                 " . ($filterByClient ? '' : 'ORDER BY p.id DESC LIMIT 500'),
                '`id` DESC',
            ],
            'cartera' => [
                "SELECT p.id, p.nombre AS client_id, COALESCE(e.razon, p.empresa) AS empresa,
                        COALESCE(b.nombre_corto, p.nombre_corto) AS cuenta, p.monto,
                        COALESCE(p.saldo_acreditable, 0) AS saldo_acreditable, p.tipo_movimiento,
                        CONCAT_WS(' ', p.detalle, p.descripcion, p.descripcion_detallada) AS detalles,
                        COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura) AS fecha_banco,
                        p.fecha_asignacion, origin.nombre AS origen_comision
                 FROM pagos p
                 LEFT JOIN captura c ON c.id = p.id_captura
                 LEFT JOIN bancos b ON b.id = c.nombre_corto
                 LEFT JOIN empresas e ON e.id = COALESCE(c.razon, p.empresa)
                 LEFT JOIN clientes origin ON origin.id = p.cliente_origen_comision
                 WHERE COALESCE(p.saldo_acreditable, 0) <> 0
                   AND p.fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 32 MONTH)
                   AND p.fecha_asignacion <= CURDATE()" . ($filterByClient ? '' : ' ORDER BY p.id DESC LIMIT 500'),
                '`fecha_asignacion` DESC, `id` DESC',
            ],
            'traspasos' => [
                "SELECT p.id, co.nombre AS origen, cd.nombre AS destino, ABS(p.monto) AS monto,
                        p.fecha_asignacion AS fecha, p.detalle
                 FROM pagos p
                 LEFT JOIN clientes co ON co.id = p.origen_traspaso
                 LEFT JOIN clientes cd ON cd.id = p.destino_traspaso
                 WHERE p.tipo_captura = 'Manual' AND p.descripcion = 'Traspaso'
                   AND p.tipo_movimiento = 'Deposito'
                   AND p.fecha_asignacion >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                 ORDER BY p.id DESC LIMIT 7000",
                '`id` DESC',
            ],
            'stp' => [
                "SELECT p.id, c.nombre AS cliente, p.nombre,
                        CONCAT(COALESCE(p.tarjeta, ''), COALESCE(p.clabe, '')) AS cuenta,
                        p.folio, p.monto, p.fecha_creacion, p.status, p.estado,
                        CONCAT_WS(' ', p.status_pre, p.causa_devolucion) AS error,
                        IF(p.status_notificado = 1, 'Sí', 'No') AS notificado,
                        COALESCE(NULLIF(p.cep, ''), 'No disponible') AS cep
                 FROM pre_ejecuta_plantilla p LEFT JOIN clientes c ON c.id = p.cliente
                 ORDER BY p.id DESC LIMIT 500",
                '`id` DESC',
            ],
            default => throw new RuntimeException('Vista de movimientos desconocida.'),
        };
    }

    private function ensureSupportTables(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS s14_movimiento_comision_links (
                pago_origen_id INT NOT NULL,
                pago_comision_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (pago_origen_id, pago_comision_id),
                KEY idx_comision (pago_comision_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS s14_traspaso_links (
                pago_entrada_id INT NOT NULL PRIMARY KEY,
                pago_salida_id INT NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS s14_movimiento_auditoria (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                accion VARCHAR(40) NOT NULL,
                usuario_id INT NOT NULL,
                pago_id INT NULL,
                datos LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pago (pago_id), KEY idx_fecha (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function paymentForUpdate(int $id): array
    {
        $query = $this->pdo->prepare('SELECT * FROM pagos WHERE id = :id FOR UPDATE');
        $query->execute(['id' => $id]);
        $payment = $query->fetch();
        if (!$payment) {
            throw new RuntimeException('El movimiento no existe.');
        }
        return $payment;
    }

    private function assertOperator(int $id): void
    {
        $query = $this->pdo->prepare('SELECT id FROM usuarios WHERE id = :id');
        $query->execute(['id' => $id]);
        if (!$query->fetchColumn()) {
            throw new RuntimeException('Selecciona un operador válido para registrar la acción.');
        }
    }

    private function lockClients(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if ($ids === []) {
            return;
        }
        $query = $this->pdo->prepare('SELECT id FROM clientes WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
        $query->execute($ids);
        $query->fetchAll();
    }

    private function balance(int $clientId): float
    {
        $query = $this->pdo->prepare('SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = :client');
        $query->execute(['client' => $clientId]);
        return (float) $query->fetchColumn();
    }

    private function audit(string $action, int $operatorId, ?int $paymentId, array $data): void
    {
        $query = $this->pdo->prepare('INSERT INTO s14_movimiento_auditoria (accion, usuario_id, pago_id, datos) VALUES (:action, :operator, :payment, :data)');
        $query->execute([
            'action' => $action, 'operator' => $operatorId, 'payment' => $paymentId,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Columna de movimiento no permitida.');
        }
        return '`' . $name . '`';
    }
}
