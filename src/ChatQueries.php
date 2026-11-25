<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use PDO;

final class ChatQueries
{
    public const NOT_FOUND = 'No encontré un registro accesible con esos datos';
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 25;
    public const MAX_PAGE = 10000;
    private const PAYMENT_DATE = "COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura)";
    private const PAYMENT_COMPANY = 'COALESCE(NULLIF(c.razon, 0), NULLIF(CAST(p.empresa AS UNSIGNED), 0))';
    private const BANK_CLIENT = "COALESCE(NULLIF(CAST(ce.cliente_asignado AS UNSIGNED), 0),
        (SELECT CASE WHEN MIN(CAST(bp.nombre AS UNSIGNED)) = MAX(CAST(bp.nombre AS UNSIGNED))
            THEN MIN(CAST(bp.nombre AS UNSIGNED)) END FROM pagos bp WHERE bp.id_captura_edo_cta = ce.id))";
    private const BANK_COMPANY = 'COALESCE(NULLIF(bc.razon, 0), NULLIF(b.razon, 0))';
    private const STP_COMPANY = 'COALESCE(NULLIF(sc.razon, 0), NULLIF(b.razon, 0), '
        . 'NULLIF(c.razon, 0), NULLIF(CAST(p.empresa AS UNSIGNED), 0))';
    private const STP_CODE = "UPPER(TRIM(COALESCE(s.estado, '')))";
    private const STP_INCIDENT = "(COALESCE(s.causa_devolucion, '') REGEXP '[^[:space:]]'
        OR COALESCE(s.status_pre, '') REGEXP '[^[:space:]]')";
    public const STP_LABELS = [
        'liquidado' => 'Liquidado',
        'devuelto' => 'Devuelto',
        'cancelado' => 'Cancelado',
        'en_espera' => 'En espera',
        'desconocido' => 'Estado no reconocido',
    ];
    private const COMPLEMENT_REF = "CASE WHEN TRIM(LEADING '0' FROM TRIM(p.id_factura_res)) REGEXP '^[0-9]{1,10}$'
        THEN NULLIF(CAST(TRIM(p.id_factura_res) AS UNSIGNED), 0) END";

    private const RECORD_TOOLS = [
        'factura' => 'detalle_factura',
        'complemento' => 'detalle_complemento',
        'movimiento' => 'detalle_movimiento',
        'banco' => 'consultar_banco',
        'stp' => 'consultar_stp',
    ];

    public function __construct(private readonly PDO $pdo) {}

    private static function definitions(): array
    {
        $common = [
            'id' => ['type' => 'integer', 'description' => 'ID interno exacto del registro'],
            'folio' => ['type' => 'string', 'description' => 'Serie y folio juntos o folio exacto'],
            'cliente' => ['type' => 'string', 'description' => 'Nombre de la cartera o cliente'],
            'empresa' => ['type' => 'string', 'description' => 'Nombre de la empresa'],
            'empresa_id' => ['type' => 'integer', 'description' => 'ID exacto de la empresa en Sistema 14'],
            'desde' => ['type' => 'string', 'description' => 'Fecha inicial YYYY-MM-DD inclusive'],
            'hasta' => ['type' => 'string', 'description' => 'Fecha final YYYY-MM-DD inclusive'],
        ];
        $amount = ['monto' => ['type' => 'number', 'description' => 'Importe exacto con hasta dos decimales. Nunca se interpreta como ID']];
        $listing = [
            'limite' => ['type' => 'integer', 'description' => 'Cantidad de registros por página, entre 1 y 25. Usa 1 para el más reciente'],
            'pagina' => ['type' => 'integer', 'description' => 'Página desde 1. Omite para consultar la primera'],
            'orden' => ['type' => 'string', 'enum' => ['recientes', 'antiguos']],
            'contar' => ['type' => 'boolean', 'description' => 'Cuenta todos los registros autorizados que coinciden con los filtros, sin límite de listado'],
            'totalizar' => ['type' => 'boolean', 'description' => 'Devuelve conteo y sumas del conjunto autorizado completo, por moneda'],
        ];
        $text = ['texto' => ['type' => 'string', 'description' => 'Texto en descripción, detalle o referencia bancaria']];
        $noFolio = array_diff_key($common, ['folio' => true]);
        $id = ['id' => $common['id']];
        $uuid = ['uuid' => ['type' => 'string', 'description' => 'UUID fiscal exacto, distinto del folio y del ID interno']];
        return [
            'buscar_facturas' => ['Lista, cuenta o busca facturas por ID, folio, UUID, importe o filtros. Para la factura más reciente usa limite=1 y orden=recientes sin fechas, salvo que el usuario indique un periodo.', $common + $uuid + $amount + $listing, []],
            'detalle_factura' => ['Factura exacta, conceptos, tratamiento fiscal, complementos, pagos y saldo PPD si es verificable.', $id, ['id']],
            'buscar_complementos' => ['Busca complementos de pago por ID, folio, UUID, importe o filtros.', $common + $uuid + $amount + $listing, []],
            'detalle_complemento' => ['Complemento exacto, facturas, montos aplicados y movimiento asociado.', $id, ['id']],
            'consultar_pagos' => ['Busca depósitos, retiros y comisiones en Movimientos por ID de pago, referencia, cartera o documento autorizado.', [
                'factura_id' => ['type' => 'integer', 'description' => 'ID exacto de la factura previamente identificada'],
                'cliente_id' => ['type' => 'integer', 'description' => 'ID interno exacto de la cartera'],
                'vista' => ['type' => 'string', 'enum' => ['asignados', 'sin-asignar', 'cartera'],
                    'description' => 'En cartera usa fecha de asignación y saldo acreditable'],
                'pendientes_documento' => ['type' => 'boolean', 'description' => 'Depósitos asignados o sin asignar que siguen sin documento relacionado'],
                'tipo' => ['type' => 'string', 'enum' => ['deposito', 'retiro', 'comision']],
            ] + $noFolio + $amount + $listing + $text, []],
            'detalle_movimiento' => ['Detalle de pago o movimiento contable por su ID exacto, con documentos y líneas bancarias autorizadas.', $id, ['id']],
            'consultar_banco' => ['Líneas de estado de cuenta. El ID de línea bancaria es distinto del ID de pago. Busca por referencia, importe o filtros.', [
                'pago_id' => ['type' => 'integer', 'description' => 'ID interno del pago para consultar su línea bancaria relacionada'],
            ] + $noFolio + $amount + $listing + $text, []],
            'consultar_stp' => ['Órdenes STP por fecha de creación, folio, importe, estado e incidencias. Para la última orden usa limite=1.', [
                'estado_stp' => ['type' => 'string', 'enum' => array_keys(self::STP_LABELS)],
                'referencia_stp' => ['type' => 'string', 'description' => 'Número sin etiqueta. Busca coincidencia exacta como ID interno o folio numérico'],
                'resumir_estados' => ['type' => 'boolean', 'description' => 'Cuenta por estado todas las órdenes autorizadas que cumplen los filtros'],
                'solo_problemas' => ['type' => 'boolean', 'description' => 'Incluye devoluciones y órdenes con causa_devolucion o status_pre no vacío'],
            ] + $common + $amount + $listing, []],
            'pendientes_ppd' => ['Facturas vigentes con saldo PPD por empresa emisora y RFC de la clave corta autorizada.', [
                'empresa_id' => ['type' => 'integer', 'description' => 'ID de la empresa emisora'],
                'clave_corta_id' => ['type' => 'integer', 'description' => 'ID de la clave corta'],
                'clave_corta' => ['type' => 'string', 'description' => 'Código exacto de la clave corta cuando no se conoce su ID'],
            ] + $listing, ['empresa_id']],
            'consultar_correos' => ['Consulta los registros de Dev de correo de facturas o correos contextuales. Distingue envío, cola y fecha programada.', [
                'tipo_correo' => ['type' => 'string', 'enum' => ['factura', 'contextual']],
                'factura_id' => ['type' => 'integer', 'description' => 'ID interno de la factura previamente identificada'],
            ] + array_diff_key($noFolio, ['cliente' => true]) + array_diff_key($listing, ['totalizar' => true]), ['tipo_correo']],
            'generar_reporte' => ['Consulta los reportes Conta14, Relación depósitos documentos o Banking (proveedores fuera de rango).', [
                'reporte' => ['type' => 'string', 'enum' => ['conta', 'pagos-saldo', 'banking']],
                'con_diferencia' => ['type' => 'boolean', 'description' => 'Solo para pagos-saldo. Depósitos vinculados con diferencia distinta de cero'],
            ] + $listing + $noFolio, ['reporte']],
        ];
    }

    public static function isTool(string $name): bool
    {
        return isset(self::definitions()[$name]);
    }

    public static function isCollection(string $tool): bool
    {
        return isset(self::definitions()[$tool][1]['pagina']);
    }

    public static function tools(ChatAccess $access): array
    {
        $tools = [];
        foreach (self::definitions() as $name => [$description, $properties, $required]) {
            if ($access->allows($name)) {
                $tools[] = ['type' => 'function', 'function' => [
                    'name' => $name,
                    'description' => $description,
                    'parameters' => [
                        'type' => 'object', 'properties' => $properties, 'required' => $required,
                        'additionalProperties' => false,
                    ],
                ]];
            }
        }
        return $tools;
    }

    public function filters(string $tool, array $input, ChatAccess $access): array
    {
        if (!$access->allows($tool)) {
            throw new ChatException('Tu cuenta no tiene permiso para esta consulta.', 403);
        }
        [, $properties, $required] = self::definitions()[$tool];
        $filters = [];
        foreach ($input as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === null || $value === '') {
                continue;
            }
            $property = $properties[$key] ?? [];
            $valid = match ($property['type'] ?? null) {
                'integer' => is_int($value) && $value > 0 && $value <= 4294967295,
                'number' => (is_int($value) || is_float($value)) && is_finite($value)
                    && abs($value) <= 999999999999 && abs(round($value, 2) - $value) < 0.000001,
                'boolean' => is_bool($value),
                'string' => is_string($value) && mb_strlen($value) <= 120,
                default => false,
            };
            if (!$valid || (isset($property['enum']) && !in_array($value, $property['enum'], true))) {
                throw new ChatException('Los filtros de la consulta no son válidos.');
            }
            $filters[$key] = $key === 'monto' ? number_format($value, 2, '.', '') : $value;
        }
        if (array_diff($required, array_keys($filters)) !== []
            || ($tool === 'pendientes_ppd' && !isset($filters['clave_corta_id']) && !isset($filters['clave_corta']))) {
            throw new ChatException('Falta un identificador requerido para esta consulta.');
        }
        if (($filters['con_diferencia'] ?? false) && ($filters['reporte'] ?? null) !== 'pagos-saldo') {
            throw new ChatException('El filtro de diferencias corresponde a Relación depósitos documentos.');
        }
        if (in_array($tool, ['buscar_facturas', 'buscar_complementos'], true)) {
            $uuidPattern = '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD';
            if (!isset($filters['uuid']) && preg_match($uuidPattern, $filters['folio'] ?? '')) {
                $filters['uuid'] = $filters['folio'];
                unset($filters['folio']);
            }
            if (isset($filters['uuid'])) {
                if (!preg_match($uuidPattern, $filters['uuid'])) {
                    throw new ChatException('El UUID debe tener el formato fiscal de 36 caracteres.');
                }
                $filters['uuid'] = strtoupper($filters['uuid']);
            }
        }
        if (isset($filters['referencia_stp']) && !preg_match('/^[0-9]{1,20}$/D', $filters['referencia_stp'])) {
            throw new ChatException('La referencia numérica STP no es válida.');
        }
        foreach (['desde', 'hasta'] as $key) {
            if (isset($filters[$key])) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
                if (!$date || $date->format('Y-m-d') !== $filters[$key] || (int) $date->format('Y') < 1000 || (int) $date->format('Y') > 9998) {
                    throw new ChatException('Indica las fechas en formato AAAA-MM-DD.');
                }
            }
        }
        if (isset($filters['desde'], $filters['hasta']) && $filters['desde'] > $filters['hasta']) {
            throw new ChatException('La fecha inicial debe ser igual o anterior a la fecha final.');
        }

        if (($filters['limite'] ?? 1) > self::MAX_LIMIT || ($filters['pagina'] ?? 1) > self::MAX_PAGE) {
            throw new ChatException('El listado admite hasta 25 registros por página.');
        }
        if ($tool === 'generar_reporte' && ($filters['totalizar'] ?? false) && isset($filters['desde'], $filters['hasta'])) {
            $start = new DateTimeImmutable($filters['desde']);
            $end = new DateTimeImmutable($filters['hasta']);
            if ($end < $start || $start->diff($end)->days > 366) {
                throw new ChatException('Para totalizar un reporte indica un periodo de hasta un año.');
            }
        }
        return $filters;
    }

    public function run(string $tool, array $filters, ChatAccess $access): array
    {
        if (!$access->allows($tool)) {
            throw new ChatException('Tu cuenta no tiene permiso para esta consulta.', 403);
        }
        $result = match ($tool) {
            'buscar_facturas' => $this->invoices($filters, $access),
            'detalle_factura' => $this->invoiceDetail($filters['id'], $access),
            'buscar_complementos' => $this->complements($filters, $access),
            'detalle_complemento' => $this->complementDetail($filters['id'], $access),
            'consultar_pagos' => $this->payments($filters, $access),
            'detalle_movimiento' => $this->paymentDetail($filters['id'], $access),
            'consultar_banco' => $this->bank($filters, $access),
            'consultar_stp' => $this->stp($filters, $access),
            'pendientes_ppd' => $this->pendingInvoices($filters, $access),
            'consultar_correos' => (new ReportRepository($this->pdo))->runChatMail($filters, $access, self::DEFAULT_LIMIT),
            'generar_reporte' => (new ReportRepository($this->pdo))->runChatReport($filters, $access, self::DEFAULT_LIMIT),
        };
        if ($result['filas'] === [] && !isset($result['totales']) && !isset($result['mensaje'])) {
            $result['mensaje'] = self::NOT_FOUND;
        }
        return $result;
    }

    public function record(string $kind, int $id, ChatAccess $access): array
    {
        $tool = self::RECORD_TOOLS[$kind] ?? null;
        if ($tool === null) {
            throw new ChatException(self::NOT_FOUND, 404);
        }
        $result = $this->run($tool, ['id' => $id], $access);
        if ($result['filas'] === []) {
            throw new ChatException(self::NOT_FOUND, 404);
        }
        return $result;
    }

    public function assertDocument(string $kind, int $id, ChatAccess $access): void
    {
        $params = [];
        $table = match ($kind) {
            'factura' => 'facturas',
            'complemento' => 'facturas_complementos',
            default => throw new ChatException(self::NOT_FOUND, 404),
        };
        $scope = $access->conditions('d.razon', 'd.nombre', $params, false);
        $key = ChatAccess::bind($params, $id);
        $related = '';
        if ($kind === 'complemento') {
            $invoiceScope = $access->conditions('f.razon', 'f.nombre', $params, false);
            $related = " AND NOT EXISTS (SELECT 1 FROM facturar_rel_complementos rel
                LEFT JOIN facturas f ON f.id = rel.id_factura
                WHERE rel.id_complemento = d.id AND (f.id IS NULL OR ({$invoiceScope}) IS NOT TRUE))";
        }
        if ($this->fetch("SELECT d.id FROM {$table} d WHERE d.id = {$key} AND {$scope}{$related}", $params) === []) {
            throw new ChatException(self::NOT_FOUND, 404);
        }
    }

    public function invoiceXml(int $id, ChatAccess $access): array
    {
        $params = [];
        $scope = $access->conditions('f.razon', 'f.nombre', $params, false);
        $key = ChatAccess::bind($params, $id);
        $row = $this->fetch("SELECT f.xml_firmado FROM facturas f WHERE f.id = {$key} AND {$scope}
            AND TRIM(COALESCE(f.uuid, '')) <> ''", $params)[0] ?? [];
        $bytes = trim((string) ($row['xml_firmado'] ?? ''));
        if (!str_starts_with($bytes, '<')) {
            throw new ChatException(self::NOT_FOUND, 404);
        }
        return ['filename' => "Factura-{$id}.xml", 'bytes' => $bytes];
    }

    private function invoices(array $filters, ChatAccess $access, bool $pending = false): array
    {
        $params = [];
        $where = $access->conditions('f.razon', 'f.nombre', $params, false);
        $where .= self::condition($filters, [
            'id' => 'f.id', 'empresa_id' => 'f.razon', 'uuid' => 'UPPER(TRIM(f.uuid))', 'folio' => ["CONCAT_WS('', f.serie, f.folio)", 'CAST(f.folio AS CHAR)', "CONCAT_WS('-', f.serie, f.folio)"],
            'empresa' => 'e.razon', 'cliente' => 'c.nombre', 'fecha' => 'f.fecha', 'monto' => ['f.total_iva'],
        ], $params);
        $useInvoiceCatalogs = !$pending && !isset($filters['id']) && !isset($filters['folio']) && !isset($filters['uuid']);
        $narrow = 'FROM facturas f ' . ($useInvoiceCatalogs
            ? InvoiceRepository::catalogJoins()
            : (isset($filters['cliente']) ? ' LEFT JOIN clientes c ON c.id = f.nombre' : '')
                . (isset($filters['empresa']) ? ' LEFT JOIN empresas e ON e.id = f.razon' : ''));
        $totals = $filters['totalizar'] ?? false;
        if (!$pending && !$totals && ($filters['contar'] ?? false)) {
            return $this->countRows("SELECT f.id {$narrow} WHERE {$where}", $params, $filters);
        }
        if (!$pending && $totals) {
            return $this->rows("SELECT f.total_iva AS total, NULLIF(cm.clave_moneda, '') AS moneda
                {$narrow} LEFT JOIN catalogo_monedas cm ON cm.id = f.moneda WHERE {$where}",
                'fecha DESC, id DESC', $params, $filters, 'factura', ['total']);
        }

        $paginationInSource = !$pending;
        $source = 'facturas f';
        if ($paginationInSource) {
            [$sort, , $size, $offset] = self::window($filters, 'f.fecha DESC, f.id DESC');
            $limit = $size + 1;
            // Primero limita los IDs, después consulta saldos y datos de cada factura de la página.
            $source = "(SELECT f.id {$narrow} WHERE {$where} ORDER BY {$sort} LIMIT {$limit} OFFSET {$offset}) lista
                JOIN facturas f ON f.id = lista.id";
            $where = '1=1';
        } else {
            $where .= ' AND UPPER(TRIM(cc.rfc)) = ' . ChatAccess::bind($params, $filters['rfc'])
                . " AND f.metodo_pago = 2 AND TRIM(COALESCE(f.uuid, '')) <> ''
                    AND COALESCE(f.status, '') NOT IN ('Cancelado', 'CAPDEL')
                    AND COALESCE(f.cancelado, 0) = 0 AND COALESCE(f.desactivado, 0) = 0";
        }
        $linkedScope = $access->conditions('fc.razon', 'fc.nombre', $params, false);

        $sql = "SELECT f.id, CONCAT_WS('', f.serie, f.folio) AS folio, f.uuid, f.fecha,
            e.razon AS empresa, f.razon AS empresa_id, c.nombre AS cliente,
            cc.id AS clave_corta_id, cc.clave_corta, cc.razon_social AS receptor, cc.rfc,
            f.total_iva AS total, NULLIF(cm.clave_moneda, '') AS moneda, mp.clave AS metodo_pago,
            f.status AS estado, f.status_pago AS estado_pago, f.cancelado, f.metodo_pago = 2 AS es_ppd,
            CASE WHEN f.metodo_pago = 2 AND NOT EXISTS (
                SELECT 1 FROM facturar_rel_complementos rel
                LEFT JOIN facturas_complementos fc ON fc.id = rel.id_complemento
                WHERE rel.id_factura = f.id AND (fc.id IS NULL OR ({$linkedScope}) IS NOT TRUE)
            ) THEN COALESCE((SELECT SUM(rel.monto) FROM facturar_rel_complementos rel WHERE rel.id_factura = f.id), 0)
            END AS aplicado_ppd
            FROM {$source}
            LEFT JOIN clientes c ON c.id = f.nombre
            LEFT JOIN empresas e ON e.id = f.razon
            LEFT JOIN claves_cortas cc ON CAST(cc.id AS CHAR) = TRIM(f.id_clave_corta)
            LEFT JOIN metodos_pago mp ON mp.id = f.metodo_pago
            LEFT JOIN catalogo_monedas cm ON cm.id = f.moneda
            WHERE {$where}";
        $sql = "SELECT facturas_autorizadas.*,
            GREATEST(ROUND(total - aplicado_ppd, 2), 0) AS saldo_ppd_registrado FROM ({$sql}) facturas_autorizadas"
            . ($pending ? ' WHERE total - aplicado_ppd > 0.005' : '');
        return $this->rows($sql, 'fecha DESC, id DESC', $params, $filters, 'factura', ['total'],
            $pending ? ['saldo_ppd_registrado'] : [], $paginationInSource);
    }

    private function pendingInvoices(array $filters, ChatAccess $access): array
    {
        $params = [];
        $scope = $access->conditions('e.id', 'cc.id_cliente', $params, false);
        $company = ChatAccess::bind($params, $filters['empresa_id']);
        $column = isset($filters['clave_corta_id']) ? 'cc.id' : 'cc.clave_corta';
        $key = ChatAccess::bind($params, $filters['clave_corta_id'] ?? $filters['clave_corta']);
        $keys = $this->fetch("SELECT DISTINCT UPPER(TRIM(cc.rfc)) AS rfc FROM claves_cortas cc
            JOIN empresas e ON e.id = {$company} WHERE {$column} = {$key} AND {$scope}
            AND TRIM(COALESCE(cc.rfc, '')) <> '' LIMIT 2", $params);
        if (count($keys) !== 1) {
            return ['filas' => [], 'hay_mas' => false, 'mensaje' => $keys === [] ? self::NOT_FOUND
                : 'La clave corta corresponde a más de un RFC. Indica el ID de la clave corta.'];
        }
        return $this->invoices($filters + ['rfc' => $keys[0]['rfc']], $access, true);
    }

    private function complements(array $filters, ChatAccess $access): array
    {
        $params = [];
        $scope = $access->conditions('fc.razon', 'fc.nombre', $params, false);
        $where = $scope . self::condition($filters, [
            'id' => 'fc.id', 'empresa_id' => 'fc.razon', 'uuid' => 'UPPER(TRIM(fc.uuid))', 'folio' => ["CONCAT_WS('', fc.serie, fc.folio)", 'CAST(fc.folio AS CHAR)', "CONCAT_WS('-', fc.serie, fc.folio)"],
            'empresa' => 'e.razon', 'cliente' => 'cl.nombre', 'fecha' => 'fc.fecha_pago', 'monto' => ['fc.total_factura'],
        ], $params);
        return $this->rows("SELECT fc.id, CONCAT_WS('', fc.serie, fc.folio) AS folio, fc.uuid,
            fc.fecha_pago AS fecha, fc.total_factura AS total, fc.status AS estado,
            e.razon AS empresa, cl.nombre AS cliente, NULLIF(cm.clave_moneda, '') AS moneda
            FROM facturas_complementos fc LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN clientes cl ON cl.id = fc.nombre LEFT JOIN catalogo_monedas cm ON cm.id = fc.moneda
            WHERE {$where}", 'fecha DESC, id DESC', $params, $filters, 'complemento', ['total']);
    }

    private function payments(array $filters, ChatAccess $access, ?int $complement = null): array
    {
        $params = [];
        $company = self::PAYMENT_COMPANY;
        $date = ($filters['vista'] ?? null) === 'cartera' ? 'p.fecha_asignacion' : self::PAYMENT_DATE;
        $bankCompany = self::BANK_COMPANY;
        $bankScope = $access->conditions($bankCompany, self::BANK_CLIENT, $params, false);
        $bankScope .= ' AND ' . $access->optionalCompany('b.razon', $params);
        $where = $this->paymentScope($access, $params) . self::condition($filters, [
            'id' => 'p.id', 'empresa_id' => $company, 'empresa' => 'e.razon', 'cliente' => 'cl.nombre', 'cliente_id' => 'CAST(p.nombre AS UNSIGNED)', 'fecha' => $date, 'monto' => ['p.monto'],
            'texto' => ['p.descripcion', 'p.descripcion_detallada', 'p.detalle', 'ce.referencia'],
        ], $params);
        $unassigned = "((p.nombre = 0 OR p.nombre = '' OR p.nombre IS NULL)
            AND p.id <> 212733 AND p.monto NOT IN (7.5, 2.88, 8, 1.28, 1.2, 0.8, 5))";
        $assigned = "(p.status = 'asignado' AND p.tipo_movimiento <> 'Abono por comisión'
            AND p.banco NOT IN ('STP', '99') AND COALESCE(p.id_factura, 0) <> 2)";
        $where .= match ($filters['vista'] ?? null) {
            'sin-asignar' => " AND {$unassigned}",
            'asignados' => " AND {$assigned}",
            'cartera' => ' AND COALESCE(p.saldo_acreditable, 0) <> 0 AND p.fecha_asignacion IS NOT NULL',
            default => '',
        };
        $documentJoin = '';
        if ($filters['pendientes_documento'] ?? false) {
            $documentJoin = "LEFT JOIN (
                    SELECT f.id_pago FROM facturas f WHERE f.id_pago > 0
                    UNION SELECT fc.id_pago FROM facturas_complementos fc WHERE fc.id_pago > 0
                    UNION SELECT rp.id_pago FROM relacion_factura_pago rp
                        LEFT JOIN facturas f ON f.id = rp.id_factura
                        LEFT JOIN facturas_complementos fc ON fc.id = rp.id_factura_com
                        WHERE rp.id_pago > 0 AND (f.id IS NOT NULL OR fc.id IS NOT NULL)
                ) documentos ON documentos.id_pago = p.id";
            $where .= " AND ({$unassigned} OR {$assigned}) AND LOWER(p.tipo_movimiento) IN ('deposito', 'depósito')
                AND COALESCE(p.id_factura, 0) <> 2 AND documentos.id_pago IS NULL
                AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.id = p.id_factura)
                AND NOT EXISTS (SELECT 1 FROM facturas_complementos fc WHERE fc.id = " . self::COMPLEMENT_REF . ')';
        }
        if (isset($filters['tipo'])) {
            $where .= match ($filters['tipo']) {
                'deposito' => " AND LOWER(p.tipo_movimiento) IN ('deposito', 'depósito')",
                'retiro' => " AND LOWER(p.tipo_movimiento) = 'retiro'",
                'comision' => " AND LOWER(p.tipo_movimiento) LIKE 'abono por comisi%'",
            };
        }
        $sources = '';
        if (isset($filters['factura_id'])) {
            $invoice = $filters['factura_id'];
            $related = $this->invoicePaymentSources($invoice, $access, $params);
            $sources = $related['with'];
            $where .= ' AND ' . $related['condition'];
        } elseif ($complement !== null) {
            $key = ChatAccess::bind($params, $complement);
            $scope = $access->conditions('fc.razon', 'fc.nombre', $params, false);
            $sources = "WITH chat_complementos AS (SELECT fc.id, fc.id_pago FROM facturas_complementos fc
                WHERE fc.id = {$key} AND {$scope}) ";
            $where .= ' AND (' . self::complementPaymentFilter() . ')';
        }
        return $this->rows("{$sources}SELECT p.id, {$date} AS fecha, e.razon AS empresa,
            cl.nombre AS cliente, p.monto, COALESCE(NULLIF(pb.moneda, ''), NULLIF(b.moneda, '')) AS moneda,
            p.saldo_acreditable, p.tipo_movimiento, p.status AS estado, p.id_factura = 2 AS sin_documento,
            LEFT(p.descripcion, 350) AS descripcion, LEFT(p.descripcion_detallada, 350) AS descripcion_detallada,
            LEFT(p.detalle, 350) AS detalle, LEFT(ce.referencia, 130) AS referencia,
            p.fecha_banco, p.fecha_asignacion, ce.id AS linea_bancaria_id
            FROM pagos p {$documentJoin}
            LEFT JOIN captura c ON c.id = p.id_captura
            LEFT JOIN bancos pb ON pb.id = CAST(COALESCE(c.nombre_corto, p.nombre_corto) AS UNSIGNED)
            LEFT JOIN (captura_edo_cta ce LEFT JOIN captura bc ON bc.id = ce.id_captura
                LEFT JOIN bancos b ON b.id = ce.cuenta)
                ON ce.id = p.id_captura_edo_cta AND {$bankScope}
            LEFT JOIN empresas e ON e.id = {$company}
            LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            WHERE {$where}", 'fecha DESC, id DESC', $params, $filters, 'movimiento', ['monto', 'saldo_acreditable']);
    }

    private function paymentScope(ChatAccess $access, array &$params): string
    {
        return $access->conditions(self::PAYMENT_COMPANY, 'CAST(p.nombre AS UNSIGNED)', $params, false)
            . ' AND ' . $access->optionalCompany('CAST(p.empresa AS UNSIGNED)', $params);
    }

    private function invoicePaymentSources(int $id, ChatAccess $access, array &$params): array
    {
        $invoiceScope = $access->conditions('f.razon', 'f.nombre', $params, false);
        $invoice = ChatAccess::bind($params, $id);
        $complementScope = $access->conditions('fc.razon', 'fc.nombre', $params, false);
        $source = "WITH chat_factura AS (
            SELECT f.id, f.id_pago FROM facturas f WHERE f.id = {$invoice} AND {$invoiceScope}
        ), chat_complementos AS (
            SELECT DISTINCT fc.id, fc.id_pago FROM chat_factura f
            JOIN facturar_rel_complementos rel ON rel.id_factura = f.id
            JOIN facturas_complementos fc ON fc.id = rel.id_complemento WHERE {$complementScope}
        ) ";
        $condition = 'EXISTS (SELECT 1 FROM chat_factura) AND (
            (p.id_factura <> 2 AND p.id_factura IN (SELECT id FROM chat_factura))
            OR p.id IN (SELECT id_pago FROM chat_factura)
            OR p.id IN (SELECT rp.id_pago FROM relacion_factura_pago rp JOIN chat_factura f ON f.id = rp.id_factura)
            OR ' . self::complementPaymentFilter() . ')';
        return ['with' => $source, 'condition' => $condition];
    }

    private static function complementPaymentFilter(): string
    {
        return 'p.id IN (SELECT id_pago FROM chat_complementos) OR ' . self::COMPLEMENT_REF . ' IN (SELECT id FROM chat_complementos)
            OR p.id IN (SELECT rp.id_pago FROM relacion_factura_pago rp JOIN chat_complementos fc ON fc.id = rp.id_factura_com)';
    }

    private function bank(array $filters, ChatAccess $access): array
    {
        $params = [];
        $company = self::BANK_COMPANY;
        $payment = $this->paymentScope($access, $params);
        $where = $access->conditions($company, self::BANK_CLIENT, $params, false)
            . ' AND ' . $access->optionalCompany('b.razon', $params)
            . self::condition($filters, [
                'id' => 'ce.id', 'empresa_id' => $company, 'empresa' => 'e.razon', 'cliente' => 'cl.nombre', 'fecha' => 'COALESCE(ce.fecha, ce.fecha_operacion, ce.fecha_sistema)',
                'monto' => ['ce.deposito', 'ce.retiro'], 'texto' => ['ce.referencia', 'ce.descripcion', 'ce.descrip_det'],
            ], $params);
        if (isset($filters['pago_id'])) {
            $requested = ChatAccess::bind($params, $filters['pago_id']);
            $where .= " AND ce.id = (SELECT p.id_captura_edo_cta FROM pagos p LEFT JOIN captura c ON c.id = p.id_captura
                WHERE p.id = {$requested} AND " . $this->paymentScope($access, $params) . ')';
        }
        $client = self::BANK_CLIENT;
        $balance = $access->canSeeBankBalance() ? 'ce.saldo' : 'NULL';
        return $this->rows("SELECT ce.id, ce.id_captura AS captura_id, b.id AS cuenta_id, ce.fecha AS fecha_banco, ce.fecha_operacion, ce.fecha_sistema,
            COALESCE(ce.fecha, ce.fecha_operacion, ce.fecha_sistema) AS fecha,
            LEFT(ce.referencia, 130) AS referencia, LEFT(ce.descripcion, 350) AS descripcion,
            LEFT(ce.descrip_det, 350) AS descripcion_detallada, ce.deposito, ce.retiro, {$balance} AS saldo_bancario,
            ce.tipo_movimiento, b.banco, NULLIF(b.moneda, '') AS moneda, cl.nombre AS cliente, e.razon AS empresa,
            (SELECT GROUP_CONCAT(p.id ORDER BY p.id LIMIT 10) FROM pagos p LEFT JOIN captura c ON c.id = p.id_captura
                WHERE p.id_captura_edo_cta = ce.id AND {$payment}) AS pagos_asociados
            FROM captura_edo_cta ce LEFT JOIN captura bc ON bc.id = ce.id_captura
            LEFT JOIN bancos b ON b.id = ce.cuenta LEFT JOIN empresas e ON e.id = {$company}
            LEFT JOIN clientes cl ON cl.id = {$client}
            WHERE {$where}", 'fecha DESC, id DESC', $params, $filters, 'banco', ['deposito', 'retiro']);
    }

    private function stp(array $filters, ChatAccess $access): array
    {
        $params = [];
        $company = self::STP_COMPANY;

        $where = $access->conditions($company, 's.cliente', $params, false);
        foreach (['sc.razon', 'b.razon', 'c.razon', 'CAST(p.empresa AS UNSIGNED)'] as $origin) {
            $where .= ' AND ' . $access->optionalCompany($origin, $params);
        }
        if (isset($filters['folio'])) {
            $filters['folio'] = strtoupper($filters['folio']);
        }
        $where .= self::condition($filters, [
            'id' => 's.id', 'empresa_id' => $company, 'folio' => ['UPPER(TRIM(s.folio))'], 'empresa' => 'e.razon',
            'cliente' => 'cl.nombre', 'fecha' => 's.fecha_creacion', 'monto' => ['s.monto'],
        ], $params);
        if (isset($filters['referencia_stp'])) {
            $value = $filters['referencia_stp'];
            $reference = ChatAccess::bind($params, $value);
            $match = "TRIM(s.folio) = {$reference}";
            $id = (int) $value;
            if ($id > 0 && $id <= 4294967295) {
                $match .= ' OR s.id = ' . ChatAccess::bind($params, $id);
            }
            $where .= " AND ({$match})";
        }
        if (isset($filters['pago_id'])) {
            $where .= ' AND p.id = ' . ChatAccess::bind($params, $filters['pago_id']);
        }
        $code = self::STP_CODE;
        $incident = self::STP_INCIDENT;
        $problem = "({$code} = 'D' OR {$incident})";
        $state = "CASE {$code}
            WHEN 'LQ' THEN 'liquidado' WHEN 'CONFIRMADO' THEN 'liquidado'
            WHEN 'D' THEN 'devuelto' WHEN 'C' THEN 'cancelado' WHEN '' THEN 'en_espera'
            ELSE 'desconocido' END";
        if (isset($filters['estado_stp'])) {
            $where .= " AND ({$state}) = " . ChatAccess::bind($params, $filters['estado_stp']);
        }
        if ($filters['solo_problemas'] ?? false) {
            $where .= " AND {$problem}";
        }

        $source = "FROM pre_ejecuta_plantilla s
            LEFT JOIN pagos p ON p.id = s.id_pago AND CAST(p.nombre AS UNSIGNED) = s.cliente AND p.monto = s.monto
            LEFT JOIN captura c ON c.id = p.id_captura
            LEFT JOIN captura_edo_cta ce ON ce.id = s.id_captura_edo AND ce.referencia = s.folio
            LEFT JOIN captura sc ON sc.id = ce.id_captura
            LEFT JOIN bancos b ON b.id = ce.cuenta
            LEFT JOIN empresas e ON e.id = {$company}
            LEFT JOIN clientes cl ON cl.id = s.cliente
            WHERE {$where}";
        if ($filters['resumir_estados'] ?? false) {
            $groups = $this->fetch("SELECT {$state} AS estado_stp, COUNT(*) AS cantidad,
                SUM({$problem}) AS problemas {$source} GROUP BY estado_stp", $params);
            $states = array_fill_keys(array_keys(self::STP_LABELS), 0);
            foreach ($groups as $group) {
                $states[$group['estado_stp']] = (int) $group['cantidad'];
            }
            return ['filas' => [], 'hay_mas' => false,
                'periodo' => array_intersect_key($filters, ['desde' => true, 'hasta' => true]),
                'totales' => ['registros' => array_sum($states), 'por_estado' => $states,
                    'con_problemas' => (int) array_sum(array_column($groups, 'problemas'))]];
        }

        $payment = $this->paymentScope($access, $params);
        $result = $this->rows("SELECT s.id, s.folio, s.monto, s.fecha_creacion, s.fecha_ejecucion,
            s.status AS codigo_estado, s.estado AS estado_codigo, s.status_banxico, cl.nombre AS cliente, e.razon AS empresa,
            NULLIF(LEFT(TRIM(s.causa_devolucion), 250), '') AS causa_devolucion,
            NULLIF(LEFT(TRIM(s.status_pre), 250), '') AS status_pre,
            {$state} AS estado_stp, ({$code} = 'CONFIRMADO') AS confirmado,
            {$incident} AS posible_incidencia, 'MXN' AS moneda,
            CASE WHEN {$payment} THEN p.id END AS pago_id
            {$source}", 'fecha_creacion DESC, id DESC', $params, $filters, 'stp', ['monto']);
        $result['filas'] = array_map(self::interpretStp(...), $result['filas']);
        return $result;
    }

    private static function interpretStp(array $row): array
    {
        $state = $row['estado_stp'];
        $row['estado'] = self::STP_LABELS[$state];
        $row['liquidada'] = match ($state) {
            'liquidado' => true,
            'devuelto', 'cancelado' => false,
            default => null,
        };
        $row['comprobante_enviado'] = $row['confirmado'] ? true : null;
        $row['posible_incidencia'] = (bool) $row['posible_incidencia'];
        unset($row['confirmado']);
        return $row;
    }

    private function invoiceDetail(int $id, ChatAccess $access): array
    {
        $result = $this->invoices(['id' => $id], $access);
        if ($result['filas'] === []) {
            return $result;
        }
        $params = [];
        $scope = $access->conditions('f.razon', 'f.nombre', $params, false);
        $key = ChatAccess::bind($params, $id);
        $fiscal = $this->fetch("SELECT f.id, f.xml_firmado, f.xml_total, f.xml_subtotal, f.total_iva,
            f.retencion_transporte, f.retencion_iva_tasa, f.uuid FROM facturas f WHERE f.id = {$key} AND {$scope}", $params)[0] ?? null;
        if ($fiscal === null) {
            return ['filas' => [], 'hay_mas' => false];
        }

        $result['filas'][0]['tratamiento_fiscal'] = (new PaymentComplementRepository($this->pdo))->taxTreatmentLabel($fiscal) ?? 'No determinado';
        if (trim((string) $fiscal['uuid']) !== '') {
            $result['filas'][0]['pdf'] = '?document=invoice_pdf&id=' . $id;
            if (trim((string) $fiscal['xml_firmado']) !== '') {
                $result['filas'][0]['xml'] = '?document=invoice_xml&id=' . $id;
            }
        }

        $concepts = $this->fetch("SELECT d.id, LEFT(COALESCE(NULLIF(d.concepto, ''), c.concepto), 350) AS concepto,
            d.cantidad, d.precio_unitario AS precio_unitario_sin_iva, d.precio_con_iva AS precio_unitario_con_iva,
            d.precio_neto AS subtotal, d.iva AS tasa_iva_registrada, d.precio_neto_iva AS total
            FROM detalle_factura d JOIN facturas f ON f.id = d.id_factura
            LEFT JOIN conceptos c ON c.id = d.id_producto AND CAST(c.razon AS UNSIGNED) = f.razon
            WHERE f.id = {$key} AND {$scope} ORDER BY d.id LIMIT 11", $params);
        $result['secciones'] = [
            'conceptos' => ['filas' => array_map(self::safeText(...), array_slice($concepts, 0, self::DEFAULT_LIMIT)), 'hay_mas' => count($concepts) > self::DEFAULT_LIMIT],
            'complementos' => $this->allocations($id, true, $access),
            'movimientos' => $this->payments(['factura_id' => $id], $access),
        ];
        return $result;
    }

    private function complementDetail(int $id, ChatAccess $access): array
    {
        $result = $this->complements(['id' => $id], $access);
        if ($result['filas'] === []) {
            return $result;
        }
        $downloadAllowed = true;
        try {
            $this->assertDocument('complemento', $id, $access);
        } catch (ChatException) {
            $downloadAllowed = false;
        }
        if ($downloadAllowed && trim((string) $result['filas'][0]['uuid']) !== '') {
            $result['filas'][0]['pdf'] = '?document=complement_pdf&id=' . $id;
            $params = [];
            $scope = $access->conditions('fc.razon', 'fc.nombre', $params, false);
            $key = ChatAccess::bind($params, $id);
            if ($this->fetch("SELECT fc.id FROM facturas_complementos fc WHERE fc.id = {$key} AND {$scope}
                AND TRIM(COALESCE(fc.xml_firmado, '')) <> ''", $params) !== []) {
                $result['filas'][0]['xml'] = '?document=complement_xml&id=' . $id;
            }
        }
        $result['secciones'] = [
            'facturas' => $this->allocations($id, false, $access),
            'movimientos' => $this->payments([], $access, $id),
        ];
        return $result;
    }

    private function allocations(int $id, bool $byInvoice, ChatAccess $access): array
    {
        $params = [];
        $invoice = $access->conditions('f.razon', 'f.nombre', $params, false);
        $complement = $access->conditions('fc.razon', 'fc.nombre', $params, false);
        $key = ChatAccess::bind($params, $id);
        $from = $byInvoice ? 'f' : 'fc';
        $record = $byInvoice ? 'fc' : 'f';
        $kind = $byInvoice ? 'complemento' : 'factura';
        return $this->rows("SELECT {$record}.id, CONCAT_WS('', {$record}.serie, {$record}.folio) AS folio,
            {$record}.uuid, {$record}.status AS estado, e.razon AS empresa, cl.nombre AS cliente,
            rel.monto AS monto_aplicado, rel.num_pago AS parcialidad,
            rel.saldo AS saldo_anterior_registrado, rel.restante AS saldo_restante_registrado,
            rel.fecha_pago
            FROM facturar_rel_complementos rel JOIN facturas f ON f.id = rel.id_factura
            JOIN facturas_complementos fc ON fc.id = rel.id_complemento
            LEFT JOIN empresas e ON e.id = {$record}.razon LEFT JOIN clientes cl ON cl.id = {$record}.nombre
            WHERE {$from}.id = {$key} AND {$invoice} AND {$complement}",
            'parcialidad ASC, fecha_pago ASC, id ASC', $params, [], $kind);
    }

    private function paymentDetail(int $id, ChatAccess $access): array
    {
        $result = $this->payments(['id' => $id], $access);
        if ($result['filas'] === []) {
            return $result;
        }
        $result['secciones'] = [
            'facturas' => $this->paymentDocuments($id, true, $access),
            'complementos' => $this->paymentDocuments($id, false, $access),
            'lineas_bancarias' => $this->bank(['pago_id' => $id], $access),
            'ordenes_stp' => $this->stp(['pago_id' => $id], $access),
        ];
        return $result;
    }

    private function paymentDocuments(int $id, bool $invoice, ChatAccess $access): array
    {
        $params = [];
        $kind = $invoice ? 'factura' : 'complemento';
        $alias = $invoice ? 'f' : 'fc';
        $table = $invoice ? 'facturas' : 'facturas_complementos';
        $amount = $invoice ? 'f.total_iva' : 'fc.total_factura';
        $date = $invoice ? 'f.fecha' : 'fc.fecha_pago';
        $complementRef = self::COMPLEMENT_REF;
        $paymentScope = $this->paymentScope($access, $params);
        $key = ChatAccess::bind($params, $id);
        $complementScope = $access->conditions('fc.razon', 'fc.nombre', $params, false);
        $documentScope = $invoice ? $access->conditions('f.razon', 'f.nombre', $params, false) : '1=1';
        $related = $invoice ? 'chat_facturas' : 'chat_complementos';
        $invoiceSources = $invoice ? ', chat_facturas AS (
            SELECT id_factura AS id FROM chat_pago WHERE id_factura <> 2
            UNION SELECT f.id FROM facturas f JOIN chat_pago p ON p.id = f.id_pago
            UNION SELECT rp.id_factura FROM relacion_factura_pago rp JOIN chat_pago p ON p.id = rp.id_pago
            UNION SELECT rel.id_factura FROM facturar_rel_complementos rel JOIN chat_complementos fc ON fc.id = rel.id_complemento
        )' : '';
        return $this->rows("WITH chat_pago AS (
            SELECT p.id, p.id_factura, {$complementRef} AS complemento_id
            FROM pagos p LEFT JOIN captura c ON c.id = p.id_captura WHERE p.id = {$key} AND {$paymentScope}
        ), chat_complementos AS (
            SELECT DISTINCT fc.id FROM facturas_complementos fc WHERE {$complementScope} AND (
                fc.id IN (SELECT complemento_id FROM chat_pago)
                OR fc.id_pago IN (SELECT id FROM chat_pago)
                OR fc.id IN (SELECT rp.id_factura_com FROM relacion_factura_pago rp JOIN chat_pago p ON p.id = rp.id_pago)
            )
        ){$invoiceSources}
            SELECT {$alias}.id, CONCAT_WS('', {$alias}.serie, {$alias}.folio) AS folio,
                {$alias}.uuid, {$alias}.status AS estado, {$amount} AS total, {$date} AS fecha,
                e.razon AS empresa, cl.nombre AS cliente
            FROM {$related} related JOIN {$table} {$alias} ON {$alias}.id = related.id
            LEFT JOIN empresas e ON e.id = {$alias}.razon LEFT JOIN clientes cl ON cl.id = {$alias}.nombre
            WHERE {$documentScope}",
            'fecha DESC, id DESC', $params, [], $kind);
    }

    public static function condition(array $filters, array $columns, array &$params): string
    {
        $conditions = [];
        foreach ($filters as $key => $value) {
            if (in_array($key, ['folio', 'monto', 'texto'], true) && isset($columns[$key])) {
                $matches = [];
                foreach ($columns[$key] as $column) {
                    $matches[] = match ($key) {
                        'folio' => $column . ' = ' . ChatAccess::bind($params, $value),
                        'monto' => 'ROUND(' . $column . ', 2) = CAST(' . ChatAccess::bind($params, $value) . ' AS DECIMAL(15,2))',
                        'texto' => self::contains($column, $value, $params),
                    };
                }
                $conditions[] = '(' . implode(' OR ', $matches) . ')';
            } elseif (in_array($key, ['cliente', 'empresa'], true) && isset($columns[$key])) {
                $conditions[] = self::contains($columns[$key], $value, $params);
            } elseif (in_array($key, ['id', 'empresa_id', 'cliente_id', 'uuid'], true) && isset($columns[$key])) {
                $conditions[] = $columns[$key] . ' = ' . ChatAccess::bind($params, $value);
            } elseif ($key === 'desde' && isset($columns['fecha'])) {
                $conditions[] = $columns['fecha'] . ' >= ' . ChatAccess::bind($params, $value);
            } elseif ($key === 'hasta' && isset($columns['fecha'])) {
                $exclusive = (new DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d');
                $conditions[] = $columns['fecha'] . ' < ' . ChatAccess::bind($params, $exclusive);
            }
        }
        return $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);
    }

    private static function contains(string $column, string $value, array &$params): string
    {
        $value = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        return $column . ' LIKE ' . ChatAccess::bind($params, '%' . $value . '%') . " ESCAPE '!'";
    }

    private static function safeText(array $row): array
    {
        foreach (['descripcion', 'descripcion_detallada', 'detalle', 'concepto', 'referencia', 'status_banxico', 'causa_devolucion', 'status_pre'] as $key) {
            if (is_string($row[$key] ?? null)) {
                $row[$key] = preg_replace([
                    '~https?://\S+~iu',
                    '/\b(?:clabe|cuenta|tarjeta|cvv|token|contrase(?:ñ|n)a|secret[oa]|api[_ -]?key)\s*[:=#]?\s*(?:[A-Za-z0-9_-]{8,}|\d(?:[ -]?\d){7,19})/iu',
                    '/(?<!\d)\d(?:[ -]?\d){12,}(?!\d)/u',
                ], '[dato omitido]', $row[$key]) ?? '[dato omitido]';
            }
        }
        return $row;
    }

    private function fetch(string $sql, array $params): array
    {
        try {
            $query = $this->pdo->prepare('SET STATEMENT max_statement_time=8 FOR ' . $sql);
            $query->execute($params);
            return $query->fetchAll();
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1969) {
                throw new ChatException('La consulta tardó demasiado. Acota el periodo o indica un identificador exacto.');
            }
            throw $error;
        }
    }

    private function countRows(string $sql, array $params, array $filters): array
    {
        $row = $this->fetch("SELECT COUNT(*) AS registros FROM ({$sql}) autorizados", $params)[0];
        return ['filas' => [], 'hay_mas' => false, 'periodo' => array_intersect_key($filters, ['desde' => true, 'hasta' => true]),
            'totales' => ['registros' => (int) $row['registros']]];
    }

    private static function window(array $filters, string $order): array
    {
        $page = $filters['pagina'] ?? 1;
        $size = $filters['limite'] ?? self::DEFAULT_LIMIT;
        if (($filters['orden'] ?? 'recientes') === 'antiguos') {
            $order = str_replace(' DESC', ' ASC', $order);
            $date = explode(' ', $order, 2)[0];
            $order = "{$date} IS NULL ASC, {$order}";
        }
        return [$order, $page, $size, ($page - 1) * $size];
    }

    private function rows(
        string $sql, string $order, array $params, array $filters, string $kind,
        array $amounts = [], array $extraTotals = [], bool $paginationInSource = false
    ): array {
        $period = array_intersect_key($filters, ['desde' => true, 'hasta' => true]);
        if (($filters['contar'] ?? false) && !($filters['totalizar'] ?? false)) {
            return $this->countRows($sql, $params, $filters);
        }
        if ($filters['totalizar'] ?? false) {
            $sums = array_map(static fn (string $name): string => "ROUND(SUM({$name}), 2) AS {$name}", [...$amounts, ...$extraTotals]);
            $group = $kind === 'movimiento' ? 'moneda, tipo_movimiento' : 'moneda';
            $columns = implode(', ', [$group, 'COUNT(*) AS registros', ...$sums]);
            $totals = $this->fetch("SELECT {$columns} FROM ({$sql}) autorizados GROUP BY {$group} ORDER BY {$group}", $params);
            return ['filas' => [], 'hay_mas' => false, 'periodo' => $period, 'totales' => [
                'registros' => array_sum(array_column($totals, 'registros')), 'por_moneda' => $totals,
            ]];
        }

        [$order, $page, $pageSize, $offset] = self::window($filters, $order);
        $limit = $pageSize + 1;
        $window = $paginationInSource ? '' : " LIMIT {$limit} OFFSET {$offset}";
        $rows = $this->fetch("{$sql} ORDER BY {$order}{$window}", $params);
        $data = array_slice($rows, 0, $pageSize);
        foreach ($data as &$row) {
            $row = self::safeText($row);
            $row['enlace'] = '?chat_registro=' . $kind . '&id=' . (int) $row['id'];
        }
        unset($row);
        return ['filas' => $data, 'hay_mas' => count($rows) > $pageSize, 'pagina' => $page, 'limite' => $pageSize,
            'varias_coincidencias' => count($rows) > 1 && (isset($filters['folio']) || isset($filters['uuid']) || isset($filters['monto']) || isset($filters['referencia_stp'])),
            'periodo' => $period];
    }
}

