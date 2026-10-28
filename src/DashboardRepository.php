<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function wallets(string $search, int $page, int $operatorId, int $perPage = 24): array
    {
        $permissions = 'c.activo = 1
            AND (c.id <> 1 OR :operator_one = 1)
            AND (c.id NOT IN (146, 189) OR :operator_restricted = 32)';
        $params = ['operator_one' => $operatorId, 'operator_restricted' => $operatorId];
        $where = 'WHERE ' . $permissions;
        if ($search !== '') {
            $where .= " AND CONCAT_WS(' ', c.id, c.nombre, c.clave_corta, cc.clave_corta) LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        $joins = ' FROM clientes c
            LEFT JOIN (
                SELECT balances.* FROM clientes_saldo_script balances
                INNER JOIN (
                    SELECT id_cliente, MAX(id) AS latest_id
                    FROM clientes_saldo_script GROUP BY id_cliente
                ) latest ON latest.latest_id = balances.id
            ) s ON s.id_cliente = c.id
            LEFT JOIN (
                SELECT id_cliente, MIN(clave_corta) AS clave_corta
                FROM claves_cortas GROUP BY id_cliente
            ) cc ON cc.id_cliente = c.id ';

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $query = $this->pdo->prepare('SELECT c.id, c.nombre, c.color, c.texto, c.imagen,
            COALESCE(NULLIF(c.clave_corta, ""), cc.clave_corta, "") AS clave_corta,
            COALESCE(s.saldoss, 0) AS saldo,
            COALESCE(s.movimientos_pend, 0) AS movimientos_pend,
            COALESCE(s.dinero_sin_fact, 0) AS dinero_sin_fact,
            COALESCE(s.cheques_pend, 0) AS cheques_pend,
            COALESCE(s.cheques_pend_dinero, 0) AS cheques_pend_dinero,
            COALESCE(s.facturas_sin_pago, 0) AS facturas_sin_pago,
            s.ultima_act, COUNT(*) OVER() AS __total' . $joins . $where . " ORDER BY c.nombre ASC LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        if ($rows === [] && $page > 1) {
            return $this->wallets($search, 1, $operatorId, $perPage);
        }
        $total = (int) ($rows[0]['__total'] ?? 0);
        foreach ($rows as &$row) {
            unset($row['__total']);
        }
        unset($row);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    public function overview(int $operatorId): array
    {
        $query = $this->pdo->prepare("SELECT wallet_summary.*, payment_summary.*, pending_summary.*, capture_summary.*, exchange_summary.exchange_rate
            FROM (
                SELECT COUNT(*) AS wallets,
                    COALESCE(SUM(COALESCE(s.saldoss, 0)), 0) AS wallet_balance,
                    COALESCE(SUM(COALESCE(s.dinero_sin_fact, 0)), 0) AS wallet_pending
                FROM clientes c
                LEFT JOIN (
                    SELECT balances.* FROM clientes_saldo_script balances
                    INNER JOIN (
                        SELECT id_cliente, MAX(id) AS latest_id
                        FROM clientes_saldo_script GROUP BY id_cliente
                    ) latest ON latest.latest_id = balances.id
                ) s ON s.id_cliente = c.id
                WHERE c.activo = 1
                  AND (c.id <> 1 OR :operator_one = 1)
                  AND (c.id NOT IN (146, 189) OR :operator_restricted = 32)
            ) wallet_summary
            CROSS JOIN (
                SELECT
                    COALESCE(SUM(CASE WHEN nombre = 13 THEN saldo_acreditable ELSE 0 END), 0) AS internal_balance,
                    COALESCE(SUM(CASE WHEN nombre = 0 THEN monto ELSE 0 END), 0) AS without_reference,
                    COALESCE(SUM(saldo_acreditable), 0) AS credited_balance
                FROM pagos
            ) payment_summary
            CROSS JOIN (
                SELECT COUNT(CASE WHEN p.id <> 212733 THEN 1 END) AS pending_count,
                    COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Deposito' AND b.moneda <> 'Dolares' THEN p.monto ELSE 0 END), 0) AS deposits,
                    COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Retiro' AND b.moneda <> 'Dolares' THEN p.monto ELSE 0 END), 0) AS withdrawals,
                    COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Deposito' AND b.moneda = 'Dolares' THEN p.monto ELSE 0 END), 0) AS dollar_deposits,
                    COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Retiro' AND b.moneda = 'Dolares' THEN p.monto ELSE 0 END), 0) AS dollar_withdrawals
                FROM pagos p
                LEFT JOIN captura ca ON ca.id = p.id_captura
                LEFT JOIN bancos b ON b.id = ca.nombre_corto
                WHERE p.status <> 'asignado'
            ) pending_summary
            CROSS JOIN (
                SELECT COALESCE(SUM(deposito), 0) - COALESCE(SUM(retiro), 0) AS captured_balance,
                    COALESCE(SUM(CASE WHEN cuenta = 0 THEN deposito ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN cuenta = 0 THEN retiro ELSE 0 END), 0) AS without_account
                FROM captura_edo_cta
            ) capture_summary
            CROSS JOIN (
                SELECT COALESCE((SELECT tipo_cambio FROM tipo_cambio ORDER BY id DESC LIMIT 1), 0) AS exchange_rate
            ) exchange_summary");
        $query->execute(['operator_one' => $operatorId, 'operator_restricted' => $operatorId]);
        $summary = $query->fetch() ?: [];

        $pendingBalance = (float) ($summary['deposits'] ?? 0) - (float) ($summary['withdrawals'] ?? 0)
            + (((float) ($summary['dollar_deposits'] ?? 0) - (float) ($summary['dollar_withdrawals'] ?? 0)) * (float) ($summary['exchange_rate'] ?? 0))
            + (float) ($summary['without_reference'] ?? 0) + (float) ($summary['without_account'] ?? 0);

        return [
            'wallets' => (int) ($summary['wallets'] ?? 0),
            'walletBalance' => (float) ($summary['wallet_balance'] ?? 0),
            'walletPending' => (float) ($summary['wallet_pending'] ?? 0),
            'internalBalance' => (float) ($summary['internal_balance'] ?? 0),
            'pendingCount' => (int) ($summary['pending_count'] ?? 0),
            'pendingBalance' => $pendingBalance,
            'balanceDifference' => (float) ($summary['captured_balance'] ?? 0) - (float) ($summary['credited_balance'] ?? 0) - $pendingBalance,
        ];
    }
}
