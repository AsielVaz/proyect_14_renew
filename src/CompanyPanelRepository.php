<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class CompanyPanelRepository
{
    private const RESTRICTED_COMPANY = 132;
    private const RESTRICTED_OPERATORS = [1, 31, 82];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function companies(string $search, int $page, int $operatorId, int $perPage = 24): array
    {
        $page = max(1, $page);
        $limit = $perPage + 1;
        $offset = ($page - 1) * $perPage;
        $where = 'WHERE e.comodin = 0 AND e.id <> 99';
        $params = [];
        if (!in_array($operatorId, self::RESTRICTED_OPERATORS, true)) {
            $where .= ' AND e.id <> ' . self::RESTRICTED_COMPANY;
        }
        if ($search !== '') {
            $where .= ' AND (e.razon LIKE :search OR e.rfc LIKE :search OR CAST(e.id AS CHAR) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $query = $this->pdo->prepare("SELECT e.id, e.razon, e.rfc, e.logo, e.descripcion,
            (SELECT COUNT(*) FROM bancos b WHERE b.razon = e.id AND b.status_banco = 'AC') AS cuentas
            FROM empresas e {$where} ORDER BY e.razon LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'page' => $page, 'hasNext' => $hasNext];
    }

    public function company(int $id, int $operatorId): array
    {
        $this->assertAccess($id, $operatorId);
        $query = $this->pdo->prepare('SELECT id, razon, rfc, calle, numero, colonia, cp, estado,
            logo, regimen, descripcion, correo_conta, correo_pv, correo_ventas
            FROM empresas WHERE id = :id AND comodin = 0 AND id <> 99');
        $query->execute(['id' => $id]);
        $company = $query->fetch();
        if (!$company) {
            throw new RuntimeException('La empresa solicitada no existe o no está disponible.');
        }

        $accounts = $this->pdo->prepare("SELECT b.id, b.banco, b.numero_cuenta,
            b.clabe_interbancaria, b.nombre_corto, b.moneda, b.color, b.texto, b.alerta,
            b.limite_superior, b.limite_inferior,
            COALESCE((SELECT SUM(COALESCE(ce.deposito, 0)) - SUM(COALESCE(ce.retiro, 0))
                FROM captura_edo_cta ce WHERE ce.cuenta = b.id), 0) AS saldo,
            (SELECT COUNT(*) FROM captura c WHERE c.nombre_corto = CAST(b.id AS CHAR)) AS capturas,
            (SELECT MAX(COALESCE(ce2.fecha, ce2.fecha_sistema)) FROM captura_edo_cta ce2 WHERE ce2.cuenta = b.id) AS ultima_actividad
            FROM bancos b WHERE b.razon = :company AND b.status_banco = 'AC' ORDER BY b.banco, b.nombre_corto");
        $accounts->execute(['company' => $id]);
        $company['accounts'] = $accounts->fetchAll();
        return $company;
    }

    public function summary(int $companyId, int $operatorId): array
    {
        $this->assertAccess($companyId, $operatorId);
        $query = $this->pdo->prepare("SELECT
            (SELECT COUNT(*) FROM captura c WHERE c.razon = CAST(:company_captures AS CHAR)) AS capturas,
            (SELECT COUNT(*) FROM pagos p WHERE p.empresa = CAST(:company_movements AS CHAR)) AS movimientos,
            (SELECT COUNT(*) FROM pagos p WHERE p.empresa = CAST(:company_pending AS CHAR)
                AND p.factura = 'Sin Facturar' AND p.tipo_movimiento <> 'Retiro') AS pendientes,
            (SELECT COALESCE(SUM(p.monto), 0) FROM pagos p WHERE p.empresa = CAST(:company_unbilled AS CHAR)
                AND p.factura = 'Sin Facturar' AND p.tipo_movimiento = 'Deposito') AS sin_facturar");
        $query->execute(['company_captures' => $companyId, 'company_movements' => $companyId,
            'company_pending' => $companyId, 'company_unbilled' => $companyId]);
        return $query->fetch() ?: [];
    }

    public function captures(int $companyId, array $filters, int $page, int $operatorId, int $perPage = 30): array
    {
        $this->assertAccess($companyId, $operatorId);
        $page = max(1, $page);
        $limit = $perPage + 1;
        $offset = ($page - 1) * $perPage;
        $where = ' WHERE c.razon = CAST(:company AS CHAR)';
        $params = ['company' => $companyId];
        $accountId = (int) ($filters['account'] ?? 0);
        if ($accountId > 0) {
            $this->assertAccount($companyId, $accountId);
            $where .= ' AND c.nombre_corto = CAST(:account AS CHAR)';
            $params['account'] = $accountId;
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (CAST(c.id AS CHAR) LIKE :search OR b.banco LIKE :search OR b.nombre_corto LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        [$dateWhere, $dateParams] = $this->dateFilter($filters, 'c.fecha');
        $where .= $dateWhere;
        $params += $dateParams;
        $query = $this->pdo->prepare("SELECT c.id, c.fecha, c.hora, c.nombre_corto AS cuenta_id,
            b.banco, b.nombre_corto, b.moneda,
            (SELECT COUNT(*) FROM captura_edo_cta ce WHERE ce.id_captura = c.id) AS movimientos,
            (SELECT COALESCE(SUM(ce.deposito), 0) FROM captura_edo_cta ce WHERE ce.id_captura = c.id) AS depositos,
            (SELECT COALESCE(SUM(ce.retiro), 0) FROM captura_edo_cta ce WHERE ce.id_captura = c.id) AS retiros
            FROM captura c LEFT JOIN bancos b ON b.id = CAST(c.nombre_corto AS UNSIGNED)
            {$where} ORDER BY c.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        return $this->paged($query->fetchAll(), $page, $perPage);
    }

    public function capture(int $companyId, int $captureId, int $operatorId): array
    {
        $this->assertAccess($companyId, $operatorId);
        $query = $this->pdo->prepare('SELECT c.id, c.razon AS company_id, c.nombre_corto AS cuenta_id,
            LEFT(c.captura, 50000) AS captura, CHAR_LENGTH(c.captura) AS captura_longitud,
            c.fecha, c.hora, b.banco, b.nombre_corto, b.moneda
            FROM captura c LEFT JOIN bancos b ON b.id = CAST(c.nombre_corto AS UNSIGNED)
            WHERE c.id = :capture AND c.razon = CAST(:company AS CHAR)');
        $query->execute(['capture' => $captureId, 'company' => $companyId]);
        $capture = $query->fetch();
        if (!$capture) {
            throw new RuntimeException('La captura no pertenece a la empresa seleccionada.');
        }
        $summary = $this->pdo->prepare('SELECT COUNT(*) movimientos,
            COALESCE(SUM(deposito), 0) depositos, COALESCE(SUM(retiro), 0) retiros
            FROM captura_edo_cta WHERE id_captura = :capture');
        $summary->execute(['capture' => $captureId]);
        $capture['summary'] = $summary->fetch();
        return $capture;
    }

    public function captureMovements(int $captureId, int $page, int $perPage = 30): array
    {
        $page = max(1, $page);
        $limit = $perPage + 1;
        $offset = ($page - 1) * $perPage;
        $query = $this->pdo->prepare("SELECT ce.id,
            (SELECT MAX(p.id) FROM pagos p WHERE p.id_captura_edo_cta = ce.id) AS pago_id,
            ce.fecha, ce.fecha_sistema,
            ce.referencia, ce.descripcion, ce.descrip_det, ce.deposito, ce.retiro, ce.saldo,
            ce.tipo_movimiento, ce.asignado, ce.cliente_asignado
            FROM captura_edo_cta ce WHERE ce.id_captura = :capture
            ORDER BY ce.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute(['capture' => $captureId]);
        return $this->paged($query->fetchAll(), $page, $perPage);
    }

    public function movements(int $companyId, array $filters, int $page, int $operatorId, int $perPage = 30): array
    {
        $this->assertAccess($companyId, $operatorId);
        $page = max(1, $page);
        $limit = $perPage + 1;
        $offset = ($page - 1) * $perPage;
        $where = ' WHERE p.empresa = CAST(:company AS CHAR)';
        $params = ['company' => $companyId];
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (CAST(p.id AS CHAR) LIKE :search OR p.descripcion LIKE :search
                OR p.descripcion_detallada LIKE :search OR p.detalle LIKE :search
                OR cl.nombre LIKE :search OR p.factura LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $accountId = (int) ($filters['account'] ?? 0);
        if ($accountId > 0) {
            $this->assertAccount($companyId, $accountId);
            $where .= ' AND (c.nombre_corto = CAST(:account AS CHAR) OR p.nombre_corto = CAST(:account AS CHAR))';
            $params['account'] = $accountId;
        }
        [$dateWhere, $dateParams] = $this->dateFilter($filters, 'COALESCE(NULLIF(p.fecha_banco, ""), p.fecha_operacion, p.fecha_captura)');
        $where .= $dateWhere;
        $params += $dateParams;
        $query = $this->pdo->prepare("SELECT p.id, p.monto, p.factura, p.status, p.tipo_movimiento,
            p.saldo_acreditable, p.saldo, p.fecha_banco, p.fecha_operacion, p.fecha_captura,
            p.descripcion, p.descripcion_detallada, p.detalle, p.id_captura,
            COALESCE(cl.nombre, 'Sin asignar') AS cliente,
            COALESCE(b.nombre_corto, p.banco, p.nombre_corto) AS cuenta
            FROM pagos p LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            LEFT JOIN captura c ON c.id = p.id_captura
            LEFT JOIN bancos b ON b.id = CAST(c.nombre_corto AS UNSIGNED)
            {$where} ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        return $this->paged($query->fetchAll(), $page, $perPage);
    }

    public function accountMovements(int $companyId, int $accountId, array $filters, int $page, int $operatorId, int $perPage = 30): array
    {
        $this->assertAccess($companyId, $operatorId);
        $account = $this->assertAccount($companyId, $accountId);
        $page = max(1, $page);
        $limit = $perPage + 1;
        $offset = ($page - 1) * $perPage;
        $where = ' WHERE ce.cuenta = :account';
        $params = ['account' => $accountId];
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (CAST(ce.id AS CHAR) LIKE :search OR ce.referencia LIKE :search
                OR ce.descripcion LIKE :search OR ce.descrip_det LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        [$dateWhere, $dateParams] = $this->dateFilter($filters, 'COALESCE(ce.fecha, ce.fecha_sistema)');
        $where .= $dateWhere;
        $params += $dateParams;
        $query = $this->pdo->prepare("SELECT ce.id,
            (SELECT MAX(p.id) FROM pagos p WHERE p.id_captura_edo_cta = ce.id) AS pago_id,
            ce.id_captura, ce.fecha,
            ce.fecha_sistema, ce.referencia, ce.descripcion, ce.descrip_det, ce.deposito,
            ce.retiro, ce.saldo, ce.tipo_movimiento, ce.asignado, ce.cliente_asignado
            FROM captura_edo_cta ce {$where} ORDER BY ce.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        $result = $this->paged($query->fetchAll(), $page, $perPage);
        $result['account'] = $account;
        return $result;
    }

    public function analyzeShortKeys(int $companyId, int $accountId, array $descriptions, int $operatorId): array
    {
        $this->assertAccess($companyId, $operatorId);
        $this->assertAccount($companyId, $accountId);
        if (count($descriptions) > 500) {
            throw new RuntimeException('La captura supera el límite de 500 movimientos por análisis.');
        }

        $query = $this->pdo->query("SELECT cc.id, cc.id_cliente, cc.clave_corta,
            COALESCE(NULLIF(c.nombre, ''), CONCAT('Cliente #', cc.id_cliente)) AS cliente
            FROM claves_cortas cc LEFT JOIN clientes c ON c.id = cc.id_cliente
            WHERE TRIM(cc.clave_corta) <> ''
            ORDER BY CHAR_LENGTH(cc.clave_corta) DESC, cc.id ASC");
        $keys = $query->fetchAll();
        $matches = [];
        foreach ($descriptions as $description) {
            $description = mb_strtoupper(mb_substr(trim((string) $description), 0, 5000));
            $match = null;
            foreach ($keys as $key) {
                $shortKey = mb_strtoupper(trim((string) $key['clave_corta']));
                if ($shortKey !== '' && mb_strpos($description, $shortKey) !== false) {
                    $match = [
                        'associated' => true,
                        'key_id' => (int) $key['id'],
                        'key' => (string) $key['clave_corta'],
                        'client_id' => (int) $key['id_cliente'],
                        'client' => (string) $key['cliente'],
                    ];
                    break;
                }
            }
            $matches[] = $match ?? [
                'associated' => false, 'key_id' => null, 'key' => null,
                'client_id' => null, 'client' => null,
            ];
        }
        return $matches;
    }

    public function accountForAnalysis(int $companyId, int $accountId, int $operatorId): array
    {
        $this->assertAccess($companyId, $operatorId);
        return $this->assertAccount($companyId, $accountId);
    }

    private function assertAccess(int $companyId, int $operatorId): void
    {
        if ($companyId <= 0 || ($companyId === self::RESTRICTED_COMPANY
            && !in_array($operatorId, self::RESTRICTED_OPERATORS, true))) {
            throw new RuntimeException('No tienes acceso a la empresa solicitada.');
        }
    }

    private function assertAccount(int $companyId, int $accountId): array
    {
        $query = $this->pdo->prepare("SELECT id, banco, nombre_corto, moneda, numero_cuenta,
            clabe_interbancaria FROM bancos WHERE id = :id AND razon = :company AND status_banco = 'AC'");
        $query->execute(['id' => $accountId, 'company' => $companyId]);
        $account = $query->fetch();
        if (!$account) {
            throw new RuntimeException('La cuenta bancaria no pertenece a la empresa seleccionada.');
        }
        return $account;
    }

    private function dateFilter(array $filters, string $column): array
    {
        $where = '';
        $params = [];
        foreach (['from', 'to'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new RuntimeException('El rango de fechas no es válido.');
            }
            $operator = $key === 'from' ? '>=' : '<=';
            $where .= " AND {$column} {$operator} :date_{$key}";
            $params['date_' . $key] = $value;
        }
        if (isset($params['date_from'], $params['date_to']) && $params['date_from'] > $params['date_to']) {
            throw new RuntimeException('La fecha inicial no puede ser posterior a la fecha final.');
        }
        return [$where, $params];
    }

    private function paged(array $rows, int $page, int $perPage): array
    {
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'page' => $page, 'hasNext' => $hasNext];
    }
}
