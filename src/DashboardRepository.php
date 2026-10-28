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
            LEFT JOIN clientes_saldo_script s ON s.id = (
                SELECT MAX(s2.id) FROM clientes_saldo_script s2 WHERE s2.id_cliente = c.id
            )
            LEFT JOIN (
                SELECT id_cliente, MIN(clave_corta) AS clave_corta
                FROM claves_cortas GROUP BY id_cliente
            ) cc ON cc.id_cliente = c.id ';

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $joins . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        $query = $this->pdo->prepare('SELECT c.id, c.nombre, c.color, c.texto, c.imagen,
            COALESCE(NULLIF(c.clave_corta, ""), cc.clave_corta, "") AS clave_corta,
            COALESCE(s.saldoss, 0) AS saldo,
            COALESCE(s.movimientos_pend, 0) AS movimientos_pend,
            COALESCE(s.dinero_sin_fact, 0) AS dinero_sin_fact,
            COALESCE(s.cheques_pend, 0) AS cheques_pend,
            COALESCE(s.cheques_pend_dinero, 0) AS cheques_pend_dinero,
            COALESCE(s.facturas_sin_pago, 0) AS facturas_sin_pago,
            s.ultima_act' . $joins . $where . " ORDER BY c.nombre ASC LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($params);

        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
    }

    public function overview(int $operatorId): array
    {
        $wallets = $this->pdo->prepare('SELECT COUNT(*) AS wallets,
            COALESCE(SUM(COALESCE(s.saldoss, 0)), 0) AS wallet_balance,
            COALESCE(SUM(COALESCE(s.dinero_sin_fact, 0)), 0) AS wallet_pending
            FROM clientes c
            LEFT JOIN clientes_saldo_script s ON s.id = (
                SELECT MAX(s2.id) FROM clientes_saldo_script s2 WHERE s2.id_cliente = c.id
            )
            WHERE c.activo = 1
              AND (c.id <> 1 OR :operator_one = 1)
              AND (c.id NOT IN (146, 189) OR :operator_restricted = 32)');
        $wallets->execute(['operator_one' => $operatorId, 'operator_restricted' => $operatorId]);
        $summary = $wallets->fetch() ?: [];

        $internalBalance = $this->scalar('SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = 13');
        $pendingCount = (int) $this->scalar("SELECT COUNT(id) FROM pagos WHERE status <> 'asignado' AND id <> 212733");
        $pendingParts = $this->pdo->query("SELECT
            COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Deposito' AND b.moneda <> 'Dolares' THEN p.monto ELSE 0 END), 0) AS deposits,
            COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Retiro' AND b.moneda <> 'Dolares' THEN p.monto ELSE 0 END), 0) AS withdrawals,
            COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Deposito' AND b.moneda = 'Dolares' THEN p.monto ELSE 0 END), 0) AS dollar_deposits,
            COALESCE(SUM(CASE WHEN p.tipo_movimiento = 'Retiro' AND b.moneda = 'Dolares' THEN p.monto ELSE 0 END), 0) AS dollar_withdrawals
            FROM pagos p
            LEFT JOIN captura ca ON ca.id = p.id_captura
            LEFT JOIN bancos b ON b.id = ca.nombre_corto
            WHERE p.status <> 'asignado'")->fetch() ?: [];
        $exchangeRate = $this->scalar('SELECT COALESCE(tipo_cambio, 0) FROM tipo_cambio ORDER BY id DESC LIMIT 1');
        $withoutReference = $this->scalar('SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE nombre = 0');
        $withoutAccount = $this->scalar('SELECT COALESCE(SUM(deposito), 0) - COALESCE(SUM(retiro), 0) FROM captura_edo_cta WHERE cuenta = 0');
        $pendingBalance = (float) ($pendingParts['deposits'] ?? 0) - (float) ($pendingParts['withdrawals'] ?? 0)
            + (((float) ($pendingParts['dollar_deposits'] ?? 0) - (float) ($pendingParts['dollar_withdrawals'] ?? 0)) * $exchangeRate)
            + $withoutReference + $withoutAccount;

        $capturedBalance = $this->scalar('SELECT COALESCE(SUM(deposito), 0) - COALESCE(SUM(retiro), 0) FROM captura_edo_cta');
        $creditedBalance = $this->scalar('SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos');

        return [
            'wallets' => (int) ($summary['wallets'] ?? 0),
            'walletBalance' => (float) ($summary['wallet_balance'] ?? 0),
            'walletPending' => (float) ($summary['wallet_pending'] ?? 0),
            'internalBalance' => $internalBalance,
            'pendingCount' => $pendingCount,
            'pendingBalance' => $pendingBalance,
            'balanceDifference' => $capturedBalance - $creditedBalance - $pendingBalance,
        ];
    }

    private function scalar(string $sql): float
    {
        return (float) ($this->pdo->query($sql)->fetchColumn() ?: 0);
    }
}
