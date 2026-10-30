<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

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

    public function calculatorData(int $operatorId): array
    {
        $clients = $this->pdo->prepare('SELECT id, nombre FROM clientes
            WHERE activo = 1
              AND (id <> 1 OR :operator_one = 1)
              AND (id NOT IN (146, 189) OR :operator_restricted = 32)
            ORDER BY nombre');
        $clients->execute(['operator_one' => $operatorId, 'operator_restricted' => $operatorId]);
        return [
            'clients' => $clients->fetchAll(),
            'companies' => $this->pdo->query('SELECT id, razon, rfc FROM empresas ORDER BY razon')->fetchAll(),
        ];
    }

    public function calculateBalance(int $clientId, string $mode, float $amount, int $operatorId): array
    {
        if (!in_array($mode, ['necesito', 'queda'], true) || $amount <= 0) {
            throw new RuntimeException('Selecciona el modo e ingresa un monto mayor a cero.');
        }
        $query = $this->pdo->prepare('SELECT esquema_comision, comision,
            porc_1_subcomision, porc_2_subcomision, porc_3_subcomision, porc_4_subcomision
            FROM clientes WHERE id = :id AND activo = 1
              AND (id <> 1 OR :operator_one = 1)
              AND (id NOT IN (146, 189) OR :operator_restricted = 32)');
        $query->execute(['id' => $clientId, 'operator_one' => $operatorId, 'operator_restricted' => $operatorId]);
        $client = $query->fetch();
        if (!$client) {
            throw new RuntimeException('La cartera seleccionada no está disponible.');
        }
        $percentage = (float) $client['comision']
            + (float) $client['porc_1_subcomision'] + (float) $client['porc_2_subcomision']
            + (float) $client['porc_3_subcomision'] + (float) $client['porc_4_subcomision'];
        $scheme = trim((string) $client['esquema_comision']);
        $result = 0.0;
        if ($scheme === 'Total') {
            $multiplier = 1 - ($percentage / 100);
            if ($multiplier <= 0) throw new RuntimeException('La comisión configurada no permite realizar el cálculo.');
            $result = $mode === 'queda' ? $amount * $multiplier : $amount / $multiplier;
        } elseif ($scheme === 'Total Invertido') {
            $multiplier = 1 + ($percentage / 100);
            $result = $mode === 'queda' ? $amount / $multiplier : $amount * $multiplier;
        } elseif ($scheme === 'Subtotal') {
            $tax = (float) ($this->pdo->query('SELECT iva FROM configuracion ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
            $taxMultiplier = 1 + ($tax / 100);
            if ($mode === 'queda') {
                $result = $amount - (($amount / $taxMultiplier) * ($percentage / 100));
            } else {
                $divisor = 1 - (($percentage / 100) / $taxMultiplier);
                if ($divisor <= 0) throw new RuntimeException('La comisión configurada no permite realizar el cálculo.');
                $result = $amount / $divisor;
            }
        } else {
            throw new RuntimeException('La cartera no tiene un esquema de comisión compatible.');
        }
        return ['result' => round($result, 2), 'percentage' => $percentage, 'scheme' => $scheme];
    }

    public function calculateBanking(int $companyId, float $amount): array
    {
        if ($companyId <= 0 || $amount <= 0) {
            throw new RuntimeException('Selecciona una empresa e ingresa un monto mayor a cero.');
        }
        $company = $this->pdo->prepare('SELECT rfc FROM empresas WHERE id = :id');
        $company->execute(['id' => $companyId]);
        $rfc = trim((string) $company->fetchColumn());
        if ($rfc === '') throw new RuntimeException('La empresa seleccionada no tiene RFC.');
        $query = $this->pdo->prepare('SELECT cc.razon_social AS empresa, e.razon AS proveedor,
            p.porcentaje_sugerido AS porcentaje
            FROM proveedores_14 p
            INNER JOIN claves_cortas cc ON cc.id = p.id_empresa
            INNER JOIN empresas e ON e.id = p.id_proveedor
            WHERE cc.rfc = :rfc ORDER BY p.porcentaje_sugerido ASC');
        $query->execute(['rfc' => $rfc]);
        $providers = $query->fetchAll();
        if ($providers === []) throw new RuntimeException('No hay proveedores configurados para esta empresa.');

        $onePercent = $amount * 0.01;
        $rows = [];
        $allocated = 0.0;
        $allocatedPercentage = 0.0;
        foreach ($providers as $index => $provider) {
            $suggested = (float) $provider['porcentaje'];
            if ($index === count($providers) - 1) {
                $calculated = 100 - $allocatedPercentage;
                $providerAmount = $amount - $allocated;
            } else {
                $variation = random_int(1, 5) * ($suggested * 0.01);
                $calculated = $suggested + (random_int(0, 1) === 0 ? -$variation : $variation);
                $providerAmount = $onePercent * $calculated;
                if ($providerAmount >= 100 && $providerAmount < 1000) $providerAmount += 1;
                elseif ($providerAmount >= 1000 && $providerAmount < 10000) $providerAmount += random_int(1, 100);
                elseif ($providerAmount >= 10000 && $providerAmount < 100000) $providerAmount += random_int(1, 1000);
                elseif ($providerAmount >= 100000 && $providerAmount < 1000000) $providerAmount += random_int(1, 10000);
                $providerAmount += random_int(0, 99) / 100;
                $calculated = $onePercent > 0 ? ($providerAmount / $onePercent) : 0;
                $allocated += $providerAmount;
                $allocatedPercentage += $calculated;
            }
            $rows[] = ['provider' => (string) $provider['proveedor'], 'company' => (string) $provider['empresa'],
                'amount' => round($providerAmount, 2), 'calculated_percentage' => round($calculated, 4),
                'suggested_percentage' => $suggested];
        }
        return ['rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    public function calculateDispersal(float $amount, int $units, string $rawAccounts): array
    {
        $accounts = array_values(array_filter(array_map('trim', explode(',', $rawAccounts)), static fn (string $item): bool => $item !== ''));
        if ($amount <= 0 || $units < 1 || $units > 5 || $accounts === []) {
            throw new RuntimeException('Indica monto, de 1 a 5 bancas y al menos una cuenta separada por comas.');
        }
        $amounts = $this->randomDistribution($amount, $units, 0.05);
        $base = $amount / $units;
        $rows = [];
        foreach ($amounts as $index => $distributed) {
            $subAmounts = $this->randomDistribution($distributed, count($accounts), 0.40);
            $rows[] = [
                'name' => chr(65 + $index),
                'amount' => round($distributed, 2),
                'percentage' => round(($distributed / $base - 1) * 100, 2),
                'accounts' => array_map(static fn (string $name, float $value): array =>
                    ['name' => $name, 'amount' => round($value, 2)], $accounts, $subAmounts),
            ];
        }
        return ['rows' => $rows, 'total' => round(array_sum($amounts), 2)];
    }

    private function randomDistribution(float $amount, int $units, float $variationRatio): array
    {
        $base = $amount / $units;
        $variationCents = max(0, (int) round($base * $variationRatio * 100));
        $amounts = [];
        $sum = 0.0;
        for ($index = 0; $index < $units; $index++) {
            $value = $index === $units - 1
                ? $amount - $sum
                : round($base + random_int(-$variationCents, $variationCents) / 100, 2);
            $amounts[] = $value;
            $sum += $value;
        }
        $amounts[0] = round($amounts[0] + ($amount - array_sum($amounts)), 2);
        return $amounts;
    }
}
