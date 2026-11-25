<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class ReportRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(string $key, array $definition, array $filters, int $page = 1, int $perPage = 25, bool $export = false): array
    {
        [$source, $params, $sort] = $this->source($key, $filters);
        $columns = array_keys($definition['columns']);
        $queryText = 'SELECT report_data.* FROM (' . $source . ') AS report_data';

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $searchColumns = implode(', ', array_map([$this, 'identifier'], $columns));
            $queryText .= " WHERE CONCAT_WS(' ', {$searchColumns}) LIKE :report_search";
            $params['report_search'] = '%' . $search . '%';
        }

        $page = max(1, $page);
        $limit = $export ? 10001 : $perPage + 1;
        $offset = $export ? 0 : ($page - 1) * $perPage;
        $queryText .= ' ORDER BY ' . $sort . " LIMIT {$limit} OFFSET {$offset}";

        $query = $this->pdo->prepare($queryText);
        $query->execute($params);
        $rows = $query->fetchAll();
        $visibleLimit = $export ? 10000 : $perPage;
        $hasNext = count($rows) > $visibleLimit;
        if ($hasNext) {
            array_pop($rows);
        }
        $rows = $this->transform($key, $rows);

        return [
            'rows' => $rows,
            'total' => count($rows),
            'page' => $page,
            'lastPage' => $page + ($hasNext ? 1 : 0),
            'hasNext' => $hasNext,
            'totalExact' => false,
            'exportLimited' => $export && $hasNext,
        ];
    }

    public function runChatReport(array $filters, ChatAccess $access, int $limit): array
    {
        $key = $filters['reporte'];
        if (!$access->allows('generar_reporte') || !in_array($key, ['conta', 'pagos-saldo', 'banking'], true)) {
            throw new ChatException('Tu cuenta no tiene permiso para este reporte.', 403);
        }

        $params = [];
        if ($key === 'banking') {
            return $this->runChatBanking($filters, $access, $limit);
        }
        $conta = $key === 'conta';
        $company = 'COALESCE(NULLIF(c.razon, 0), NULLIF(CAST(p.empresa AS UNSIGNED), 0))';
        $dateColumn = $conta ? "COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura)" : 'p.fecha_banco';
        $where = ' AND ' . $access->conditions($company, 'CAST(p.nombre AS UNSIGNED)', $params, false);
        foreach ($conta ? ['f', 'fc', 'legacy_response'] : ['chat_factura', 'chat_complemento'] as $alias) {
            $where .= " AND ({$alias}.id IS NULL OR ("
                . $access->conditions("{$alias}.razon", "{$alias}.nombre", $params, false) . '))';
        }
        $where .= ChatQueries::condition($filters, [
            'id' => 'p.id', 'empresa_id' => $company, 'empresa' => 'e.razon', 'cliente' => $conta ? 'cl.nombre' : 'chat_cliente.nombre',
            'fecha' => $dateColumn,
        ], $params);
        if ($filters['con_diferencia'] ?? false) {
            $where .= " AND COALESCE(p.id_factura, 0) <> 2 AND LOWER(p.tipo_movimiento) IN ('deposito', 'depósito')";
        }
        [$source, , $sort] = $this->source($key, [], $where);
        if ($filters['con_diferencia'] ?? false) {
            $source = "SELECT * FROM ({$source}) con_saldo WHERE ABS(restante) > 0.005";
        }

        $period = array_intersect_key($filters, ['desde' => true, 'hasta' => true]);
        if (($filters['contar'] ?? false) && !($filters['totalizar'] ?? false)) {
            $query = $this->pdo->prepare("SET STATEMENT max_statement_time=8 FOR
                SELECT COUNT(*) FROM ({$source}) AS chat_reporte");
            $query->execute($params);
            return ['reporte' => $key, 'filas' => [], 'hay_mas' => false, 'periodo' => $period,
                'totales' => ['registros' => (int) $query->fetchColumn()]];
        }
        if ($filters['totalizar'] ?? false) {
            $balance = $conta ? '' : ', SUM(restante) AS suma_restante';
            $query = $this->pdo->prepare("SET STATEMENT max_statement_time=8 FOR SELECT moneda, COUNT(*) AS registros, SUM(monto) AS suma_importes{$balance}
                FROM ({$source}) AS chat_reporte GROUP BY moneda ORDER BY moneda");
            $query->execute($params);
            $rows = $query->fetchAll();
            return ['reporte' => $key, 'filas' => [], 'hay_mas' => false, 'periodo' => $period, 'totales' => [
                'registros' => array_sum(array_column($rows, 'registros')),
                'por_moneda' => $rows,
            ]];
        }

        $columns = $conta
            ? 'pago_id, documentos, empresa, cliente, monto, moneda, fecha_pago, factura_id, factura_folio, factura_estado,
                complemento_id, complemento_folio, complemento_fecha, complemento_estado'
            : 'id, empresa, monto, moneda, fecha_banco, restante, folios_asociados';
        $limit = $filters['limite'] ?? $limit;
        $page = $filters['pagina'] ?? 1;
        $offset = ($page - 1) * $limit;
        $fetchLimit = $limit + 1;
        $date = $conta ? 'fecha_pago' : 'fecha_banco';
        $id = $conta ? 'pago_id' : 'id';
        if (isset($filters['orden'])) {
            $direction = $filters['orden'] === 'antiguos' ? 'ASC' : 'DESC';
            $sort = "{$date} IS NULL ASC, {$date} {$direction}, {$id} {$direction}";
        }
        $query = $this->pdo->prepare("SET STATEMENT max_statement_time=8 FOR SELECT {$columns} FROM ({$source}) AS chat_reporte
            ORDER BY {$sort} LIMIT {$fetchLimit} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        return ['reporte' => $key, 'filas' => array_slice($rows, 0, $limit),
            'hay_mas' => count($rows) > $limit, 'pagina' => $page, 'limite' => $limit, 'periodo' => $period];
    }

    private function runChatBanking(array $filters, ChatAccess $access, int $limit): array
    {
        $from = $filters['desde'] ?? date('Y-m-01');
        $until = $filters['hasta'] ?? date('Y-m-d');
        if ($from > $until) {
            throw new ChatException('La fecha inicial debe ser igual o anterior a la fecha final.');
        }
        $params = ['date_from' => $from, 'date_to' => $until];
        $visibility = $access->conditions('origin.id', 'cl.id_cliente', $params, false) . ' AND '
            . $access->conditions('provider.id', 'f.nombre', $params, false);
        $origin = ChatQueries::condition($filters, ['empresa_id' => 'origin.id', 'empresa' => 'origin.razon'], $params);
        $sql = $this->bankingQuery($visibility, $origin);
        $filters += ['desde' => $from, 'hasta' => $until];
        return ['reporte' => 'banking'] + $this->chatRows($sql, $params, $filters,
            'empresa_origen ASC, porcentaje_real DESC, proveedor ASC', $limit);
    }

    public function runChatMail(array $filters, ChatAccess $access, int $limit): array
    {
        if (!$access->allows('consultar_correos')) {
            throw new ChatException('Tu cuenta no tiene permiso para consultar los registros de correo.', 403);
        }
        $params = [];
        if ($filters['tipo_correo'] === 'contextual') {
            $company = 'l.id_proveedor';
            $client = 'receiver.id_cliente';
            $date = 'l.fecha_entrega';
            $query = "SELECT l.id, 'contextual' AS tipo_correo, f.id AS factura_id, e.razon AS empresa,
                receiver.razon_social AS receptor, l.estado AS codigo_estado,
                CASE l.estado WHEN 0 THEN 'Pendiente de envío'
                    WHEN 1 THEN 'Envío registrado, reenvío pendiente'
                    WHEN 2 THEN 'Flujo de correo registrado como completado'
                    ELSE 'Estado sin interpretar' END AS estado,
                l.fecha_entrega AS fecha_programada, l.fecha_respuesta AS respuesta_programada
                FROM contexto_correo l JOIN empresas e ON e.id = l.id_proveedor
                JOIN claves_cortas receiver ON receiver.id = l.id_empresa
                LEFT JOIN facturas f ON f.id = l.id_factura";
            $where = '1=1';
        } else {
            $company = 'COALESCE(NULLIF(f.razon, 0), l.empresa_origen)';
            $client = 'COALESCE(NULLIF(f.nombre, 0), l.empresa_destino)';
            $date = "COALESCE(CASE WHEN l.fecha_envia >= '1000-01-01' THEN l.fecha_envia END, l.fecha_inserta)";
            $destination = ($filters['destinatario'] ?? false)
                ? ", NULLIF(TRIM(REPLACE(REPLACE(l.correo_destino, CHAR(13), ' '), CHAR(10), ' ')),'') AS destinatario_registrado" : '';
            $query = "SELECT l.id, 'factura' AS tipo_correo, f.id AS factura_id, e.razon AS empresa,
                receiver.nombre AS receptor, l.estado AS codigo_estado,
                CASE l.estado WHEN 1 THEN 'Envío registrado'
                    WHEN 2 THEN 'Aceptado o en cola'
                    WHEN -1 THEN 'Rechazado'
                    WHEN -2 THEN 'Error de envío'
                    ELSE 'Sin envío confirmado' END AS estado,
                CASE WHEN l.fecha_envia >= '1000-01-01' THEN l.fecha_envia END AS fecha_envia,
                CASE WHEN l.fecha_recibe >= '1000-01-01' THEN l.fecha_recibe END AS fecha_recibe,
                l.fecha_inserta{$destination}
                FROM facturas_correo l LEFT JOIN facturas f ON f.id = l.id_factura
                LEFT JOIN empresas e ON e.id = {$company}
                LEFT JOIN clientes receiver ON receiver.id = {$client}";
            $where = 'COALESCE(l.id_factura, 0) >= 0';
        }
        $where .= ' AND ' . $access->conditions($company, $client, $params, false)
            . ' AND (f.id IS NULL OR (' . $access->conditions('f.razon', 'f.nombre', $params, false) . '))';
        if (isset($filters['factura_id'])) {
            $where .= ' AND f.id = ' . ChatAccess::bind($params, $filters['factura_id']);
        }
        $where .= ChatQueries::condition($filters, [
            'id' => 'l.id', 'empresa_id' => $company, 'empresa' => 'e.razon', 'fecha' => $date,
        ], $params);
        $sort = ($filters['orden'] ?? 'recientes') === 'antiguos' ? 'ASC' : 'DESC';
        return $this->chatRows("{$query} WHERE {$where}", $params, $filters, "id {$sort}", $limit)
            + ['detalle_destinatario' => $filters['tipo_correo'] === 'factura' && ($filters['destinatario'] ?? false)];
    }

    private function chatRows(string $sql, array $params, array $filters, string $order, int $limit): array
    {
        $period = array_intersect_key($filters, ['desde' => true, 'hasta' => true]);
        if (($filters['contar'] ?? false) || ($filters['totalizar'] ?? false)) {
            $query = $this->pdo->prepare("SET STATEMENT max_statement_time=8 FOR SELECT COUNT(*) FROM ({$sql}) datos");
            $query->execute($params);
            return ['filas' => [], 'periodo' => $period, 'hay_mas' => false,
                'totales' => ['registros' => (int) $query->fetchColumn()]];
        }
        $page = $filters['pagina'] ?? 1;
        $size = $filters['limite'] ?? $limit;
        $offset = ($page - 1) * $size;
        $count = $size + 1;
        $query = $this->pdo->prepare("SET STATEMENT max_statement_time=8 FOR
            SELECT * FROM ({$sql}) datos ORDER BY {$order} LIMIT {$count} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        return ['filas' => array_slice($rows, 0, $size), 'periodo' => $period, 'hay_mas' => count($rows) > $size,
            'pagina' => $page, 'limite' => $size];
    }

    private function source(string $key, array $filters, string $chatFilter = ''): array
    {
        $from = $this->validDate((string) ($filters['from'] ?? '')) ?: date('Y-m-01');
        $to = $this->validDate((string) ($filters['to'] ?? '')) ?: date('Y-m-d');

        $chatCurrency = $chatFilter === '' ? '' : ", NULLIF(b.moneda, '') AS moneda";
        $chatGroupedCurrency = $chatFilter === '' ? '' : ", MAX(NULLIF(b.moneda, '')) AS moneda";
        $chatJoins = $chatFilter === '' ? '' : '
            LEFT JOIN clientes chat_cliente ON chat_cliente.id = CAST(p.nombre AS UNSIGNED)
            LEFT JOIN facturas chat_factura ON chat_factura.id = rfp.id_factura
            LEFT JOIN facturas_complementos chat_complemento ON chat_complemento.id = rfp.id_factura_com';

        return match ($key) {
            'accesos' => [
                'SELECT id, usuario, tipo AS resultado, tipo AS evento, tiempo_conexion AS metodo,
                        agent AS dispositivo, ip, fecha, hora_i, agent AS detalle, activa
                 FROM logsesiones',
                [], '`id` DESC',
            ],
            'bitacora' => [
                'SELECT b.id, u.nombre AS usuario, b.hora_registra AS hora, b.fecha_registra AS fecha,
                        b.accion, b.monto, e.razon AS empresa
                 FROM bitacora b
                 INNER JOIN usuarios u ON u.id = b.usuario_registra
                 INNER JOIN empresas e ON e.id = b.empresa',
                [], '`id` DESC',
            ],
            'disparador' => [
                "SELECT id, fecha,
                        CASE banca_nueva WHEN 1 THEN 'DELMENT' WHEN 2 THEN 'COMERCIO WK' WHEN 3 THEN 'ENCLENSI' ELSE CONCAT('Banca ', banca_nueva) END AS banca_nueva,
                        CASE banca_original WHEN 1 THEN 'DELMENT' WHEN 2 THEN 'COMERCIO WK' WHEN 3 THEN 'ENCLENSI' ELSE CONCAT('Banca ', banca_original) END AS banca_original,
                        monto, usuario_registro, sistema
                 FROM ejecuciones_disparador",
                [], '`id` DESC',
            ],
            'conta' => [
                "SELECT p.id AS pago_id,
                        CASE
                            WHEN COALESCE(p.id_factura, 0) > 0
                                AND TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                                AND CAST(p.id_factura_res AS UNSIGNED) > 0 THEN 'Factura y complemento'
                            WHEN COALESCE(p.id_factura, 0) > 0 THEN 'Factura'
                            ELSE 'Complemento'
                        END AS documentos,
                        COALESCE(e.razon, 'Empresa no identificada') AS empresa,
                        COALESCE(cl.nombre, 'Sin asignar') AS cliente,
                        COALESCE(b.nombre_corto, p.banco, p.nombre_corto, 'Sin cuenta') AS cuenta,
                        p.tipo_movimiento, p.monto{$chatCurrency},
                        COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_captura) AS fecha_pago,
                        p.fecha_asignacion,
                        CONCAT_WS(' · ', NULLIF(p.detalle, ''), NULLIF(p.descripcion, ''), NULLIF(p.descripcion_detallada, '')) AS detalle_pago,
                        CASE WHEN COALESCE(p.id_factura, 0) > 0 THEN p.id_factura ELSE NULL END AS factura_id,
                        CASE WHEN f.id IS NOT NULL THEN CONCAT_WS(' / ', f.serie, f.folio) ELSE '' END AS factura_folio,
                        COALESCE(f.uuid, '') AS factura_uuid,
                        f.total_iva AS factura_total,
                        CASE
                            WHEN p.id_factura = 2 THEN 'Bandera documental; sin factura asociada'
                            WHEN COALESCE(p.id_factura, 0) <= 0 THEN 'No aplica'
                            WHEN f.id IS NULL THEN 'Registro de factura no encontrado'
                            ELSE COALESCE(NULLIF(f.status, ''), NULLIF(f.status_error, ''), IF(NULLIF(f.uuid, '') IS NOT NULL, 'Timbrada', 'Pendiente'))
                        END AS factura_estado,
                        CASE
                            WHEN TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                                AND CAST(p.id_factura_res AS UNSIGNED) > 0
                            THEN CAST(p.id_factura_res AS UNSIGNED) ELSE NULL
                        END AS complemento_id,
                        CASE
                            WHEN fc.id IS NOT NULL THEN CONCAT_WS(' / ', fc.serie, fc.folio)
                            WHEN legacy_response.id IS NOT NULL THEN CONCAT_WS(' / ', legacy_response.serie, legacy_response.folio)
                            ELSE ''
                        END AS complemento_folio,
                        COALESCE(fc.uuid, legacy_response.uuid, '') AS complemento_uuid,
                        COALESCE(fc.total_factura, legacy_response.total_iva) AS complemento_total,
                        COALESCE(fc.fecha_pago, legacy_response.fecha) AS complemento_fecha,
                        CASE
                            WHEN NOT (TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                                AND CAST(p.id_factura_res AS UNSIGNED) > 0) THEN 'No aplica'
                            WHEN fc.id IS NOT NULL THEN CONCAT('Complemento actual · ',
                                COALESCE(NULLIF(fc.status, ''), NULLIF(fc.status_error, ''), IF(NULLIF(fc.uuid, '') IS NOT NULL, 'Timbrado', 'Pendiente')))
                            WHEN legacy_response.id IS NOT NULL THEN CONCAT('Registro histórico · ',
                                COALESCE(NULLIF(legacy_response.status, ''), NULLIF(legacy_response.status_error, ''), IF(NULLIF(legacy_response.uuid, '') IS NOT NULL, 'Timbrado', 'Pendiente')))
                            ELSE 'Registro de complemento no encontrado'
                        END AS complemento_estado
                 FROM pagos p
                 LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
                 LEFT JOIN captura c ON c.id = p.id_captura
                 LEFT JOIN bancos b ON b.id = CAST(COALESCE(c.nombre_corto, p.nombre_corto) AS UNSIGNED)
                 LEFT JOIN empresas e ON e.id = CAST(COALESCE(c.razon, p.empresa) AS UNSIGNED)
                 LEFT JOIN facturas f ON f.id = p.id_factura AND p.id_factura <> 2
                 LEFT JOIN facturas_complementos fc ON fc.id = CASE
                    WHEN TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                    THEN CAST(p.id_factura_res AS UNSIGNED) ELSE NULL END
                    AND fc.id_pago = p.id
                 LEFT JOIN facturas legacy_response ON legacy_response.id = CASE
                    WHEN TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                    THEN CAST(p.id_factura_res AS UNSIGNED) ELSE NULL END
                 WHERE p.tipo_movimiento = 'Deposito'
                   AND (COALESCE(p.id_factura, 0) > 0
                    OR (TRIM(COALESCE(p.id_factura_res, '')) REGEXP '^[0-9]+$'
                        AND CAST(p.id_factura_res AS UNSIGNED) > 0)){$chatFilter}",
                [], '`pago_id` DESC',
            ],
            'conta-interno' => [
                "SELECT f.id AS factura_id, f.fecha AS fecha_factura, f.uuid,
                        f.total_iva AS importe_factura, p.id AS pago_id, p.monto AS monto_pago,
                        p.fecha_banco AS fecha_pago, e.razon AS empresa, b.nombre_corto AS cuenta,
                        CONCAT_WS(' ', p.detalle, p.descripcion, p.descripcion_detallada) AS detalles
                 FROM facturas f
                 INNER JOIN (
                    SELECT total_iva, MAX(id) AS factura_id
                    FROM facturas
                    WHERE nombre = 13 AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    GROUP BY total_iva
                 ) uf ON uf.factura_id = f.id
                 INNER JOIN (
                    SELECT monto, MAX(id) AS pago_id
                    FROM pagos
                    WHERE nombre = 13
                      AND fecha_asignacion >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 10 DAY)
                      AND tipo_movimiento = 'Deposito'
                    GROUP BY monto
                 ) up ON up.monto = f.total_iva
                 INNER JOIN pagos p ON p.id = up.pago_id
                 LEFT JOIN captura c ON c.id = p.id_captura
                 LEFT JOIN bancos b ON b.id = c.nombre_corto
                 LEFT JOIN empresas e ON e.id = c.razon",
                [], '`importe_factura` DESC, `factura_id` DESC',
            ],
            'pagos-saldo' => [
                "SELECT p.id, e.razon AS empresa, b.nombre_corto AS banco, p.monto,
                        p.tipo_movimiento, p.descripcion, p.descripcion_detallada, p.fecha_banco,
                        SUM(rfp.monto_factura - p.monto) AS restante,
                        COUNT(rfp.id) AS folios_asociados{$chatGroupedCurrency}
                 FROM pagos p
                 LEFT JOIN clientes cl ON cl.id = p.cliente_origen_comision
                 INNER JOIN captura c ON c.id = p.id_captura
                 INNER JOIN bancos b ON b.id = c.nombre_corto
                 INNER JOIN captura_edo_cta ce ON ce.id_captura = p.id_captura
                    AND (p.monto = ce.deposito OR p.monto = ce.retiro)
                 INNER JOIN empresas e ON e.id = c.razon
                 INNER JOIN relacion_factura_pago rfp ON rfp.id_pago = p.id
                 {$chatJoins}
                 WHERE 1=1{$chatFilter}
                 GROUP BY p.id, e.razon, b.nombre_corto, p.monto, p.tipo_movimiento,
                          p.descripcion, p.descripcion_detallada, p.fecha_banco",
                [], '`id` DESC',
            ],
            'timbres' => [
                'SELECT MAX(lt.id) AS id, lt.id_factura, MAX(lt.uuid) AS uuid,
                        MAX(u.nombre) AS usuario_timbro, MAX(lt.fecha) AS fecha_timbrado,
                        COUNT(lt.id_factura) AS conteo
                 FROM log_timbrado lt
                 INNER JOIN usuarios u ON u.id = lt.usuario_timbro
                 GROUP BY lt.id_factura',
                [], '`conteo` DESC, `id` DESC',
            ],
            'facturas-eliminadas' => [$this->invoiceQuery('eliminadas'), [], '`id` DESC'],
            'facturas-captura-eliminada' => [$this->invoiceQuery('captura'), [], '`id` DESC'],
            'facturas-canceladas' => [$this->invoiceQuery('canceladas'), [], '`id` DESC'],
            'banking' => [
                $this->bankingQuery(),
                ['date_from' => $from, 'date_to' => $to],
                '`empresa_origen`, `porcentaje_real` DESC',
            ],
            'correos-factura' => [
                'SELECT fc.id, COALESCE(e1.razon, fc.correo_origen) AS origen,
                        COALESCE(e2.razon, fc.correo_destino) AS destino, fc.tamplate AS peticion,
                        fc.respuesta, fc.fecha_envia, fc.fecha_recibe
                 FROM facturas_correo fc
                 LEFT JOIN empresas e1 ON e1.id = fc.empresa_origen
                 LEFT JOIN empresas e2 ON e2.id = fc.empresa_destino',
                [], '`id` DESC',
            ],
            'correos-contexto' => [
                'SELECT cc.id, e.razon AS empresa_origen, cl.razon_social AS empresa_destino,
                        cc.mensaje, cc.fecha_entrega, cc.fecha_respuesta
                 FROM contexto_correo cc
                 INNER JOIN empresas e ON e.id = cc.id_proveedor
                 INNER JOIN claves_cortas cl ON cl.id = cc.id_empresa',
                [], '`id` DESC',
            ],
            default => throw new RuntimeException('Reporte desconocido.'),
        };
    }

    private function invoiceQuery(string $type): string
    {
        $table = $type === 'captura' ? 'facturas' : 'facturas_eliminadas';
        $rfc = $type === 'captura' ? 'e.rfc' : 'cc.rfc';
        $where = match ($type) {
            'eliminadas' => 'f.razon <> 132',
            'canceladas' => 'f.uuid IS NOT NULL',
            default => "f.status = 'CAPDEL'",
        };
        $userJoin = $type === 'captura'
            ? ''
            : 'LEFT JOIN usuarios u ON u.id = f.usuario_crea LEFT JOIN usuarios u2 ON u2.id = f.usuario_timbra';
        $users = $type === 'captura'
            ? "'' AS usuarios"
            : "CONCAT('CREÓ: ', COALESCE(u.nombre, 'AUTO'), ' · ', 'GESTIONÓ: ', COALESCE(u2.nombre, '')) AS usuarios";
        $claveJoin = $type === 'captura' ? '' : 'INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta';

        return "SELECT f.id, CONCAT_WS('-', f.folio, f.serie) AS folio_interno,
                       mp.metodo_pago, fp.forma_pago, e.razon AS empresa, f.total_iva,
                       c.nombre AS cliente, f.fecha_timbrado, f.uuid, {$rfc} AS rfc, {$users}
                FROM {$table} f
                INNER JOIN clientes c ON c.id = f.nombre
                INNER JOIN empresas e ON e.id = f.razon
                INNER JOIN formas_pago fp ON fp.id = f.forma_pago
                INNER JOIN metodos_pago mp ON mp.id = f.metodo_pago
                {$claveJoin}
                {$userJoin}
                WHERE {$where}
                ORDER BY f.id DESC
                LIMIT 1000";
    }

    private function bankingQuery(string $visibility = '', string $originFilter = ''): string
    {
        $visibleGroup = $visibility === '' ? '' : ", MIN(CASE WHEN {$visibility} THEN 1 ELSE 0 END) AS visible";
        $visiblePartition = $visibility === '' ? '' : ', MIN(daily.visible) OVER (PARTITION BY daily.empresa_origen) AS visible';
        $visibleFilter = $visibility === '' ? '' : ' AND banking_rows.visible = 1';
        return "SELECT banking_rows.empresa_origen, banking_rows.proveedor, banking_rows.total,
                       ROUND(banking_rows.porcentaje_real, 2) AS porcentaje_real,
                       banking_rows.porcentaje_sugerido,
                       CONCAT(ROUND(banking_rows.rango_inferior, 2), '% - ', ROUND(banking_rows.rango_superior, 2), '%') AS rango,
                       'Fuera de rango' AS estado
                FROM (
                    SELECT daily.empresa_origen, daily.proveedor, daily.total, daily.porcentaje_sugerido,
                           daily.total / NULLIF(SUM(daily.total) OVER (PARTITION BY daily.empresa_origen), 0) * 100 AS porcentaje_real,
                           daily.porcentaje_sugerido * 0.70 AS rango_inferior,
                           daily.porcentaje_sugerido * 1.30 AS rango_superior{$visiblePartition}
                    FROM (
                        SELECT origin.razon AS empresa_origen, provider.razon AS proveedor,
                               SUM(f.total_iva) AS total, p14.porcentaje_sugerido{$visibleGroup}
                        FROM claves_cortas cl
                        INNER JOIN empresas origin ON origin.rfc = cl.rfc
                        INNER JOIN facturas f ON f.id_clave_corta = cl.id
                        INNER JOIN empresas provider ON provider.id = f.razon
                        INNER JOIN proveedores_14 p14 ON p14.id_empresa = cl.id AND p14.id_proveedor = provider.id
                        WHERE f.metodo_pago = 1 AND DATE(f.fecha) BETWEEN :date_from AND :date_to
                          AND f.razon <> 132 AND provider.comodin = 0 AND origin.id <> 132{$originFilter}
                        GROUP BY origin.razon, provider.razon, p14.porcentaje_sugerido
                    ) daily
                ) banking_rows
                WHERE (banking_rows.porcentaje_real BETWEEN banking_rows.rango_inferior AND banking_rows.rango_superior) IS NOT TRUE{$visibleFilter}";
    }

    private function transform(string $key, array $rows): array
    {
        if ($key === 'accesos') {
            foreach ($rows as &$row) {
                $payload = json_decode((string) ($row['dispositivo'] ?? ''), true);
                if (is_array($payload) && (int) ($payload['schema'] ?? 0) === 1) {
                    $row['dispositivo'] = (string) ($payload['user_agent'] ?? 'No disponible');
                    $row['evento'] = (string) ($payload['event'] ?? 'auth_event');
                    $row['metodo'] = (string) ($payload['method'] ?? $row['metodo'] ?? '');
                    $row['detalle'] = (string) ($payload['detail'] ?? $payload['message'] ?? '');
                } else {
                    $row['evento'] = (string) (($row['resultado'] ?? '') === 'Correcto' ? 'login_success' : 'legacy_event');
                    $row['detalle'] = 'Registro histórico; no contiene contexto técnico adicional.';
                }
            }
            unset($row);
        }

        if ($key === 'correos-contexto') {
            foreach ($rows as &$row) {
                [$message, $response] = array_pad(explode('RESPUESTA', (string) $row['mensaje'], 2), 2, '');
                $row['mensaje'] = trim(str_replace(['*', ':'], '', $message));
                $row['respuesta'] = trim(str_replace(['*', ':'], '', $response));
            }
            unset($row);
        }

        if ($key === 'correos-factura') {
            foreach ($rows as &$row) {
                $date = strtotime((string) $row['fecha_envia']) ?: time();
                $replace = ['%' => '', '"respuesta"' => '', '$$' => '', 'AAAA' => date('Y', $date), 'MM' => date('m', $date), 'DD' => date('d', $date)];
                $row['peticion'] = strtr((string) $row['peticion'], $replace);
                $row['respuesta'] = strtr((string) $row['respuesta'], $replace);
            }
            unset($row);
        }

        return $rows;
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Columna de reporte no permitida.');
        }
        return '`' . $name . '`';
    }

    private function validDate(string $date): ?string
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $value && $value->format('Y-m-d') === $date ? $date : null;
    }
}
