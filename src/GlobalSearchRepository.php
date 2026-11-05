<?php

declare(strict_types=1);

namespace App;

use PDO;

final class GlobalSearchRepository
{
    private const RESTRICTED_COMPANY = 132;
    private const RESTRICTED_OPERATORS = [1, 31, 82];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(string $term, int $operatorId, int $limit = 6): array
    {
        $term = mb_substr(trim(preg_replace('/\s+/u', ' ', $term) ?? $term), 0, 120);
        $limit = max(1, min(10, $limit));
        if (mb_strlen($term) < 2) {
            return ['payments' => [], 'invoices' => [], 'complements' => [], 'captures' => []];
        }

        return [
            'payments' => $this->payments($term, $operatorId, $limit),
            'invoices' => $this->invoices($term, $operatorId, $limit),
            'complements' => $this->complements($term, $operatorId, $limit),
            'captures' => $this->captures($term, $operatorId, $limit),
        ];
    }

    private function payments(string $term, int $operatorId, int $limit): array
    {
        $access = $this->hasRestrictedAccess($operatorId) ? '' : ' AND COALESCE(e.id, 0) <> ' . self::RESTRICTED_COMPANY;
        $numeric = self::numericValue($term);
        if ($numeric !== null && self::isDecimalAmount($term)) {
            $condition = 'p.monto = :numeric_amount';
            $params = ['numeric_amount' => $numeric, 'exact' => $term];
        } elseif ($numeric !== null) {
            $condition = '(p.id = :numeric_id OR p.monto = :numeric_amount)';
            $params = ['numeric_id' => (int) $numeric, 'numeric_amount' => $numeric, 'exact' => $term];
        } else {
            $condition = "CONCAT_WS(' ', CAST(p.id AS CHAR), CAST(p.monto AS CHAR), p.descripcion,
                p.descripcion_detallada, p.detalle, p.banco, p.factura,
                p.tipo_movimiento, cl.nombre, e.razon) LIKE :search";
            $params = ['search' => '%' . $term . '%', 'exact' => $term];
        }
        $query = $this->pdo->prepare("SELECT p.id, p.monto, p.tipo_movimiento, p.descripcion,
            p.descripcion_detallada, p.detalle, p.factura, p.status,
            COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura) AS fecha,
            COALESCE(cl.nombre, 'Sin asignar') AS cliente,
            COALESCE(e.razon, p.empresa, 'Sin empresa') AS empresa
            FROM pagos p
            LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            LEFT JOIN empresas e ON e.id = CAST(p.empresa AS UNSIGNED)
            WHERE {$condition} AND LOWER(COALESCE(p.tipo_movimiento, '')) NOT LIKE 'abono por comisi%'{$access}
            ORDER BY (CAST(p.id AS CHAR) = :exact) DESC, p.id DESC LIMIT {$limit}");
        $query->execute($params);

        return array_map(static function (array $row): array {
            $description = self::firstText($row['descripcion'], $row['descripcion_detallada'], $row['detalle'], $row['factura']);
            $rawMovementType = trim((string) ($row['tipo_movimiento'] ?? ''));
            $normalizedMovementType = mb_strtolower($rawMovementType, 'UTF-8');
            $movementType = str_starts_with($normalizedMovementType, 'abono')
                ? 'Abono'
                : ($normalizedMovementType === 'deposito' ? 'Depósito' : ($rawMovementType ?: 'Sin tipo'));
            return [
                'id' => (int) $row['id'],
                'title' => 'Pago #' . $row['id'] . ' · ' . $movementType,
                'description' => $description !== '' ? $description : ($row['tipo_movimiento'] ?: 'Movimiento sin descripción'),
                'meta' => 'Tipo: ' . $movementType . ' · ' . trim((string) $row['cliente']) . ' · ' . trim((string) $row['empresa']),
                'value' => self::money($row['monto']),
                'date' => (string) ($row['fecha'] ?? ''),
                'url' => '?movement=totales&q=' . rawurlencode((string) $row['id']),
            ];
        }, $query->fetchAll());
    }

    private function invoices(string $term, int $operatorId, int $limit): array
    {
        $access = $this->hasRestrictedAccess($operatorId) ? '' : ' AND f.razon <> ' . self::RESTRICTED_COMPANY;
        $numeric = self::numericValue($term);
        if ($numeric !== null && self::isDecimalAmount($term)) {
            $condition = 'f.total_iva = :numeric_amount';
            $params = ['numeric_amount' => $numeric, 'exact' => $term];
        } elseif ($numeric !== null) {
            $condition = '(f.id = :numeric_id OR f.total_iva = :numeric_amount OR f.folio = :numeric_folio)';
            $params = ['numeric_id' => (int) $numeric, 'numeric_amount' => $numeric, 'numeric_folio' => $term, 'exact' => $term];
        } else {
            $condition = "CONCAT_WS(' ', CAST(f.id AS CHAR), f.serie, f.folio, f.uuid,
                CAST(f.total_iva AS CHAR), c.nombre, e.razon, cc.razon_social, cc.rfc) LIKE :search";
            $params = ['search' => '%' . $term . '%', 'exact' => $term];
        }
        $query = $this->pdo->prepare("SELECT f.id, f.serie, f.folio, f.uuid, f.total_iva,
            f.fecha, f.status, f.metodo_pago, c.nombre AS cliente, e.razon AS empresa,
            cc.razon_social AS receptor, cc.rfc
            FROM facturas f
            LEFT JOIN clientes c ON c.id = f.nombre
            LEFT JOIN empresas e ON e.id = f.razon
            LEFT JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            WHERE {$condition}{$access}
            ORDER BY (CAST(f.id AS CHAR) = :exact) DESC, f.id DESC LIMIT {$limit}");
        $query->execute($params);

        $rows = $query->fetchAll();
        $ppdInvoiceIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($rows, static fn (array $row): bool => (int) $row['metodo_pago'] === 2)
        );
        $complementsByInvoice = $this->invoiceComplements($ppdInvoiceIds);

        return array_map(static function (array $row) use ($complementsByInvoice): array {
            $folio = trim((string) ($row['serie'] ?? '') . (string) ($row['folio'] ?? ''));
            $invoiceId = (int) $row['id'];
            $isPpd = (int) $row['metodo_pago'] === 2;
            return [
                'id' => $invoiceId,
                'title' => 'Factura #' . $row['id'] . ($folio !== '' ? ' · ' . $folio : ''),
                'description' => self::firstText($row['receptor'], $row['rfc'], $row['uuid']),
                'meta' => trim((string) ($row['cliente'] ?: 'Sin cliente')) . ' · '
                    . trim((string) ($row['empresa'] ?: 'Sin empresa')) . ($isPpd ? ' · PPD' : ''),
                'value' => self::money($row['total_iva']),
                'date' => (string) ($row['fecha'] ?? ''),
                'url' => $isPpd
                    ? '?invoice=complements&id=' . rawurlencode((string) $invoiceId)
                    : '?invoice=list&q=' . rawurlencode((string) $invoiceId),
                'isPpd' => $isPpd,
                'complements' => $complementsByInvoice[$invoiceId] ?? [],
            ];
        }, $rows);
    }

    /**
     * @param list<int> $invoiceIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function invoiceComplements(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_filter($invoiceIds, static fn (int $id): bool => $id > 0)));
        if ($invoiceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $query = $this->pdo->prepare("SELECT rel.id_factura, rel.id_complemento, rel.monto,
                rel.num_pago, rel.fecha_pago, fc.serie, fc.folio, fc.uuid, fc.status, fc.status_error
            FROM facturar_rel_complementos rel
            INNER JOIN facturas_complementos fc ON fc.id = rel.id_complemento
            WHERE rel.id_factura IN ({$placeholders})
            ORDER BY rel.id_factura, rel.num_pago ASC, rel.id ASC");
        $query->execute($invoiceIds);

        $result = [];
        foreach ($query->fetchAll() as $row) {
            $invoiceId = (int) $row['id_factura'];
            $folio = trim((string) ($row['serie'] ?? '') . (string) ($row['folio'] ?? ''));
            $uuid = trim((string) ($row['uuid'] ?? ''));
            $status = $uuid !== ''
                ? 'Timbrado'
                : self::firstText($row['status'], $row['status_error'], 'Pendiente');
            $result[$invoiceId][] = [
                'id' => (int) $row['id_complemento'],
                'title' => 'Complemento #' . $row['id_complemento'] . ($folio !== '' ? ' · ' . $folio : ''),
                'description' => $uuid !== '' ? $uuid : 'Sin UUID',
                'meta' => 'Parcialidad ' . ((int) ($row['num_pago'] ?? 0) ?: '—') . ' · ' . $status,
                'value' => self::money($row['monto']),
                'date' => (string) ($row['fecha_pago'] ?? ''),
                'url' => '?complement=detail&id=' . rawurlencode((string) $row['id_complemento']),
            ];
        }

        return $result;
    }

    private function captures(string $term, int $operatorId, int $limit): array
    {
        $access = $this->hasRestrictedAccess($operatorId) ? '' : ' AND e.id <> ' . self::RESTRICTED_COMPANY;
        $numeric = self::numericValue($term);
        if ($numeric !== null && self::isDecimalAmount($term)) {
            $condition = '(ce.deposito = :numeric_deposit OR ce.retiro = :numeric_withdrawal OR ce.saldo = :numeric_balance)';
            $params = ['numeric_deposit' => $numeric, 'numeric_withdrawal' => $numeric,
                'numeric_balance' => $numeric, 'exact' => $term, 'exact_capture' => $term];
        } elseif ($numeric !== null) {
            $condition = '(ce.id = :numeric_id OR ce.id_captura = :numeric_capture OR ce.deposito = :numeric_deposit
                OR ce.retiro = :numeric_withdrawal OR ce.saldo = :numeric_balance OR ce.referencia = :numeric_reference)';
            $params = ['numeric_id' => (int) $numeric, 'numeric_capture' => (int) $numeric,
                'numeric_deposit' => $numeric, 'numeric_withdrawal' => $numeric, 'numeric_balance' => $numeric,
                'numeric_reference' => $term, 'exact' => $term, 'exact_capture' => $term];
        } else {
            $condition = "CONCAT_WS(' ', CAST(ce.id AS CHAR), CAST(ce.id_captura AS CHAR),
                ce.referencia, ce.descripcion, ce.descrip_det, CAST(ce.deposito AS CHAR),
                CAST(ce.retiro AS CHAR), CAST(ce.saldo AS CHAR), e.razon, b.nombre_corto, b.banco) LIKE :search";
            $params = ['search' => '%' . $term . '%', 'exact' => $term, 'exact_capture' => $term];
        }
        $query = $this->pdo->prepare("SELECT ce.id, ce.id_captura, ce.referencia, ce.descripcion,
            ce.descrip_det, ce.deposito, ce.retiro, ce.saldo,
            COALESCE(ce.fecha, ce.fecha_sistema) AS fecha,
            c.razon AS company_id, COALESCE(e.razon, 'Sin empresa') AS empresa,
            COALESCE(b.nombre_corto, b.banco, CONCAT('Cuenta #', c.nombre_corto)) AS cuenta
            FROM captura_edo_cta ce
            INNER JOIN captura c ON c.id = ce.id_captura
            LEFT JOIN empresas e ON e.id = CAST(c.razon AS UNSIGNED)
            LEFT JOIN bancos b ON b.id = CAST(c.nombre_corto AS UNSIGNED)
            WHERE {$condition}{$access}
            ORDER BY (CAST(ce.id AS CHAR) = :exact OR CAST(ce.id_captura AS CHAR) = :exact_capture) DESC,
                ce.id DESC LIMIT {$limit}");
        $query->execute($params);

        return array_map(static function (array $row): array {
            $amount = (float) $row['deposito'] !== 0.0 ? $row['deposito'] : $row['retiro'];
            $movement = (float) $row['deposito'] !== 0.0 ? 'Depósito' : ((float) $row['retiro'] !== 0.0 ? 'Retiro' : 'Saldo');
            return [
                'id' => (int) $row['id'],
                'title' => 'Captura #' . $row['id_captura'] . ' · Movimiento #' . $row['id'],
                'description' => self::firstText($row['referencia'], $row['descripcion'], $row['descrip_det']),
                'meta' => trim((string) $row['empresa']) . ' · ' . trim((string) $row['cuenta']) . ' · ' . $movement,
                'value' => self::money($amount),
                'secondaryValue' => 'Saldo ' . self::money($row['saldo']),
                'date' => (string) ($row['fecha'] ?? ''),
                'url' => '?' . http_build_query([
                    'companies' => 'capture',
                    'id' => (int) $row['company_id'],
                    'capture_id' => (int) $row['id_captura'],
                ]),
            ];
        }, $query->fetchAll());
    }

    private function complements(string $term, int $operatorId, int $limit): array
    {
        $access = $this->hasRestrictedAccess($operatorId) ? '' : ' AND fc.razon <> ' . self::RESTRICTED_COMPANY;
        $numeric = self::numericValue($term);
        if ($numeric !== null && self::isDecimalAmount($term)) {
            $condition = 'fc.total_factura = :numeric_amount';
            $params = ['numeric_amount' => $numeric, 'exact' => $term];
        } elseif ($numeric !== null) {
            $condition = '(fc.id = :numeric_id OR fc.total_factura = :numeric_amount OR fc.folio = :numeric_folio)';
            $params = ['numeric_id' => (int) $numeric, 'numeric_amount' => $numeric,
                'numeric_folio' => $term, 'exact' => $term];
        } else {
            $condition = "CONCAT_WS(' ', CAST(fc.id AS CHAR), fc.serie, fc.folio, fc.uuid,
                CAST(fc.total_factura AS CHAR), c.nombre, e.razon, cc.razon_social, cc.rfc,
                fc.status, fc.status_error) LIKE :search";
            $params = ['search' => '%' . $term . '%', 'exact' => $term];
        }

        $query = $this->pdo->prepare("SELECT fc.id, fc.serie, fc.folio, fc.uuid, fc.total_factura,
                fc.fecha_pago, fc.fecha_timbrado, fc.status, fc.status_error,
                c.nombre AS cliente, e.razon AS empresa, cc.razon_social AS receptor, cc.rfc
            FROM facturas_complementos fc
            LEFT JOIN clientes c ON c.id = fc.nombre
            LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN claves_cortas cc ON cc.id = fc.id_clave_corta
            WHERE {$condition}{$access}
            ORDER BY (CAST(fc.id AS CHAR) = :exact) DESC, fc.id DESC LIMIT {$limit}");
        $query->execute($params);

        return array_map(static function (array $row): array {
            $folio = trim((string) ($row['serie'] ?? '') . (string) ($row['folio'] ?? ''));
            $status = trim((string) (($row['uuid'] ?? '') !== ''
                ? 'Timbrado'
                : (($row['status'] ?? '') ?: (($row['status_error'] ?? '') ?: 'Pendiente'))));
            return [
                'id' => (int) $row['id'],
                'title' => 'Complemento #' . $row['id'] . ($folio !== '' ? ' · ' . $folio : ''),
                'description' => self::firstText($row['receptor'], $row['rfc'], $row['uuid']),
                'meta' => trim((string) ($row['cliente'] ?: 'Sin cliente')) . ' · '
                    . trim((string) ($row['empresa'] ?: 'Sin empresa')) . ' · ' . $status,
                'value' => self::money($row['total_factura']),
                'date' => (string) (($row['fecha_pago'] ?? '') ?: ($row['fecha_timbrado'] ?? '')),
                'url' => '?complement=detail&id=' . rawurlencode((string) $row['id']),
            ];
        }, $query->fetchAll());
    }

    private function hasRestrictedAccess(int $operatorId): bool
    {
        return in_array($operatorId, self::RESTRICTED_OPERATORS, true);
    }

    private static function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
            }
        }
        return '';
    }

    private static function money(mixed $value): string
    {
        return '$' . number_format((float) $value, 2);
    }

    private static function numericValue(string $term): ?float
    {
        $normalized = str_replace([',', '$', ' '], '', $term);
        return $normalized !== '' && is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function isDecimalAmount(string $term): bool
    {
        $normalized = str_replace([',', '$', ' '], '', $term);
        return preg_match('/^[+-]?\d+\.\d+$/', $normalized) === 1;
    }
}
