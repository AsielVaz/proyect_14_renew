<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class StatisticsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function clients(): array
    {
        return $this->pdo->query("SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre")->fetchAll();
    }

    public function run(string $key, array $filters): array
    {
        [$from, $to] = $this->dateRange($filters);
        $clientId = max(0, (int) ($filters['client'] ?? 0));

        return match ($key) {
            'ganancias-cliente' => $this->monthlyClient($from, $to, $clientId, true),
            'estadisticas-clientes' => $this->monthlyClient($from, $to, $clientId, false),
            'ganancias-generales' => $this->generalEarnings($from, $to),
            'ganancias-dia' => $this->dailyEarnings($from, $to),
            'uso-stp' => $this->stpUsage($from, $to),
            'facturas' => $this->invoiceActivity($from, $to),
            'cuentas-frecuentes' => $this->frequentAccounts($from, $to, max(1, (int) ($filters['page'] ?? 1))),
            'facturas-cliente' => $this->monthlyInvoices($from, $to, $clientId),
            default => throw new RuntimeException('Estadística desconocida.'),
        } + ['from' => $from, 'to' => $to];
    }

    private function dateRange(array $filters): array
    {
        $today = new DateTimeImmutable('today');
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters['from'] ?? '')) ?: $today->modify('first day of january');
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters['to'] ?? '')) ?: $today;
        if ($from > $to) {
            throw new RuntimeException('La fecha inicial no puede ser posterior a la fecha final.');
        }
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function monthlyClient(string $from, string $to, int $clientId, bool $commissions): array
    {
        $clientId = $this->requireClient($clientId);
        $sql = $commissions
            ? "SELECT DATE_FORMAT(p.fecha_asignacion, '%Y-%m') period, SUM(p.saldo_acreditable) value
               FROM pagos p WHERE p.cliente_origen_comision = :client AND p.nombre = '1'
                 AND p.tipo_movimiento = 'Abono por comisión' AND p.fecha_asignacion BETWEEN :from AND :to
               GROUP BY YEAR(p.fecha_asignacion), MONTH(p.fecha_asignacion) ORDER BY period"
            : "SELECT DATE_FORMAT(p.fecha_asignacion, '%Y-%m') period, SUM(p.monto) value
               FROM pagos p WHERE CAST(p.nombre AS UNSIGNED) = :client AND p.tipo_movimiento = 'Deposito'
                 AND p.fecha_asignacion BETWEEN :from AND :to
               GROUP BY YEAR(p.fecha_asignacion), MONTH(p.fecha_asignacion) ORDER BY period";
        $rows = $this->fetch($sql, ['client' => $clientId, 'from' => $from, 'to' => $to]);
        return $this->singleSeries($rows, $commissions ? 'Aportación' : 'Depósitos', 'money');
    }

    private function generalEarnings(string $from, string $to): array
    {
        $rows = $this->fetch(
            "SELECT COALESCE(c.nombre, 'Sin cliente') label, SUM(p.saldo_acreditable) value
             FROM pagos p LEFT JOIN clientes c ON c.id = p.cliente_origen_comision
             WHERE p.nombre = '1' AND p.tipo_movimiento = 'Abono por comisión'
               AND p.fecha_asignacion BETWEEN :from AND :to
             GROUP BY c.id, c.nombre ORDER BY value DESC",
            ['from' => $from, 'to' => $to]
        );
        $result = $this->singleSeries($rows, 'Ganancias', 'money', 'label');
        $result['summary'] = [
            ['label' => 'Ganancias generales', 'value' => array_sum(array_column($rows, 'value')), 'format' => 'money'],
            ['label' => 'Mayor aportador', 'value' => $rows[0]['label'] ?? 'Sin datos', 'format' => 'text'],
            ['label' => 'Mayor acumulado por cliente', 'value' => (float) ($rows[0]['value'] ?? 0), 'format' => 'money'],
        ];
        return $result;
    }

    private function dailyEarnings(string $from, string $to): array
    {
        $rows = $this->fetch(
            "SELECT COALESCE(NULLIF(p.fecha_banco, ''), DATE_FORMAT(p.fecha_captura, '%Y-%m-%d')) label,
                    SUM(p.saldo_acreditable) value
             FROM pagos p WHERE p.tipo_movimiento = 'Abono por comisión' AND p.fecha_captura BETWEEN :from AND :to
             GROUP BY label ORDER BY STR_TO_DATE(label, '%Y-%m-%d'), label",
            ['from' => $from, 'to' => $to]
        );
        $result = $this->singleSeries($rows, 'Ganancias', 'money', 'label');
        $result['summary'] = [
            ['label' => 'Ganancia del periodo', 'value' => array_sum(array_column($rows, 'value')), 'format' => 'money'],
            ['label' => 'Días con movimientos', 'value' => count($rows), 'format' => 'number'],
            ['label' => 'Promedio diario', 'value' => count($rows) ? array_sum(array_column($rows, 'value')) / count($rows) : 0, 'format' => 'money'],
        ];
        return $result;
    }

    private function stpUsage(string $from, string $to): array
    {
        $out = $this->fetch(
            "SELECT DATE_FORMAT(fecha_creacion, '%x-S%v') period,
                SUM(folio LIKE '%EN%') en_count, SUM(CASE WHEN folio LIKE '%EN%' THEN monto ELSE 0 END) en_amount,
                SUM(folio LIKE '%WK%') wk_count, SUM(CASE WHEN folio LIKE '%WK%' THEN monto ELSE 0 END) wk_amount,
                SUM(folio LIKE '%ZA%') za_count, SUM(CASE WHEN folio LIKE '%ZA%' THEN monto ELSE 0 END) za_amount,
                SUM(folio LIKE '%TO%') tok_count, SUM(CASE WHEN folio LIKE '%TO%' THEN monto ELSE 0 END) tok_amount
             FROM pre_ejecuta_plantilla WHERE fecha_creacion >= :from AND fecha_creacion < DATE_ADD(:to, INTERVAL 1 DAY)
             GROUP BY YEARWEEK(fecha_creacion, 3) ORDER BY YEARWEEK(fecha_creacion, 3)",
            ['from' => $from, 'to' => $to]
        );
        $in = $this->fetch(
            "SELECT DATE_FORMAT(fecha, '%x-S%v') period,
                SUM(cuenta = 117 AND deposito > 0) en_count, SUM(CASE WHEN cuenta = 117 AND deposito > 0 THEN deposito ELSE 0 END) en_amount,
                SUM(cuenta = 114 AND deposito > 0) wk_count, SUM(CASE WHEN cuenta = 114 AND deposito > 0 THEN deposito ELSE 0 END) wk_amount,
                SUM(cuenta = 127 AND deposito > 0) za_count, SUM(CASE WHEN cuenta = 127 AND deposito > 0 THEN deposito ELSE 0 END) za_amount,
                SUM(cuenta = 130 AND deposito > 0) tok_count, SUM(CASE WHEN cuenta = 130 AND deposito > 0 THEN deposito ELSE 0 END) tok_amount
             FROM captura_edo_cta WHERE fecha BETWEEN :from AND :to
             GROUP BY YEARWEEK(fecha, 3) ORDER BY YEARWEEK(fecha, 3)",
            ['from' => $from, 'to' => $to]
        );
        return ['type' => 'multi', 'charts' => [
            $this->stpChart('Salidas de STP · movimientos', $out, '_count', 'number'),
            $this->stpChart('Salidas de STP · montos', $out, '_amount', 'money'),
            $this->stpChart('Entradas de STP · movimientos', $in, '_count', 'number'),
            $this->stpChart('Entradas de STP · montos', $in, '_amount', 'money'),
        ]];
    }

    private function stpChart(string $title, array $rows, string $suffix, string $format): array
    {
        $names = ['en' => 'STP Enclensi', 'wk' => 'STP WK', 'za' => 'STP Zac', 'tok' => 'STP Tokidoki'];
        return ['title' => $title, 'format' => $format, 'labels' => array_column($rows, 'period'), 'series' => array_map(
            fn (string $key, string $name): array => ['name' => $name, 'data' => array_map('floatval', array_column($rows, $key . $suffix))],
            array_keys($names), array_values($names)
        )];
    }

    private function invoiceActivity(string $from, string $to): array
    {
        $rows = $this->fetch(
            "SELECT DATE_FORMAT(fecha, '%x-S%v') period,
                    SUM(usuario_crea IS NOT NULL) manuales, SUM(usuario_crea IS NULL) automaticas
             FROM facturas WHERE fecha BETWEEN :from AND :to
             GROUP BY YEARWEEK(fecha, 3) ORDER BY YEARWEEK(fecha, 3)",
            ['from' => $from, 'to' => $to]
        );
        return ['type' => 'chart', 'format' => 'number', 'labels' => array_column($rows, 'period'), 'series' => [
            ['name' => 'Facturas manuales', 'data' => array_map('floatval', array_column($rows, 'manuales'))],
            ['name' => 'Facturas automáticas', 'data' => array_map('floatval', array_column($rows, 'automaticas'))],
        ], 'summary' => [
            ['label' => 'Facturas manuales', 'value' => array_sum(array_column($rows, 'manuales')), 'format' => 'number'],
            ['label' => 'Facturas automáticas', 'value' => array_sum(array_column($rows, 'automaticas')), 'format' => 'number'],
            ['label' => 'Total emitidas', 'value' => array_sum(array_column($rows, 'manuales')) + array_sum(array_column($rows, 'automaticas')), 'format' => 'number'],
        ]];
    }

    private function frequentAccounts(string $from, string $to, int $page): array
    {
        $pageSize = 100;
        $offset = ($page - 1) * $pageSize;
        $totalStatement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (SELECT 1 FROM pre_ejecuta_plantilla
             WHERE fecha_creacion >= :from AND fecha_creacion < DATE_ADD(:to, INTERVAL 1 DAY)
             GROUP BY clabe, nombre, tarjeta) grouped_accounts"
        );
        $totalStatement->execute(['from' => $from, 'to' => $to]);
        $total = (int) $totalStatement->fetchColumn();
        $rows = $this->fetch(
            "SELECT COALESCE(NULLIF(nombre, ''), 'Sin nombre') nombre, tarjeta, clabe,
                    COUNT(*) repeticiones, SUM(monto) monto
             FROM pre_ejecuta_plantilla WHERE fecha_creacion >= :from AND fecha_creacion < DATE_ADD(:to, INTERVAL 1 DAY)
             GROUP BY clabe, nombre, tarjeta ORDER BY repeticiones DESC, monto DESC
             LIMIT {$pageSize} OFFSET {$offset}",
            ['from' => $from, 'to' => $to]
        );
        return ['type' => 'table', 'rows' => $rows, 'columns' => [
            'nombre' => 'Nombre', 'tarjeta' => 'Tarjeta', 'clabe' => 'CLABE',
            'repeticiones' => 'Número de repeticiones', 'monto' => 'Suma / monto',
        ], 'format' => ['repeticiones' => 'number', 'monto' => 'money'], 'total' => $total, 'page' => $page,
            'hasNext' => $offset + count($rows) < $total,
            'charts' => [
                ['title' => 'Top 5 por repeticiones', 'format' => 'number', 'labels' => array_column(array_slice($rows, 0, 5), 'nombre'), 'series' => [['name' => 'Repeticiones', 'data' => array_map('floatval', array_column(array_slice($rows, 0, 5), 'repeticiones'))]]],
                ['title' => 'Top 5 por monto', 'format' => 'money', 'labels' => array_column(array_slice($rows, 0, 5), 'nombre'), 'series' => [['name' => 'Monto', 'data' => array_map('floatval', array_column(array_slice($rows, 0, 5), 'monto'))]]],
            ]];
    }

    private function monthlyInvoices(string $from, string $to, int $clientId): array
    {
        $clientId = $this->requireClient($clientId);
        $rows = $this->fetch(
            "SELECT DATE_FORMAT(fecha, '%Y-%m') period, COUNT(*) value FROM facturas
             WHERE nombre = :client AND fecha BETWEEN :from AND :to
             GROUP BY YEAR(fecha), MONTH(fecha) ORDER BY period",
            ['client' => $clientId, 'from' => $from, 'to' => $to]
        );
        return $this->singleSeries($rows, 'Facturas', 'number');
    }

    private function singleSeries(array $rows, string $name, string $format, string $labelKey = 'period'): array
    {
        return ['type' => 'chart', 'format' => $format, 'labels' => array_values(array_map('strval', array_column($rows, $labelKey))),
            'series' => [['name' => $name, 'data' => array_map('floatval', array_column($rows, 'value'))]]];
    }

    private function requireClient(int $clientId): int
    {
        if ($clientId <= 0) {
            $clientId = (int) $this->pdo->query("SELECT id FROM clientes WHERE activo = 1 ORDER BY nombre LIMIT 1")->fetchColumn();
        }
        if ($clientId <= 0) {
            throw new RuntimeException('No hay clientes activos para generar esta estadística.');
        }
        return $clientId;
    }

    private function fetch(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
}
