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
        $queryText = 'SELECT * FROM (' . $source . ') AS report_data';

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $searchColumns = implode(', ', array_map([$this, 'identifier'], $columns));
            $queryText .= " WHERE CONCAT_WS(' ', {$searchColumns}) LIKE :report_search";
            $params['report_search'] = '%' . $search . '%';
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $queryText . ') AS report_count');
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $limit = $export ? 10000 : $perPage;
        $offset = $export ? 0 : ($page - 1) * $perPage;
        $queryText .= ' ORDER BY ' . $sort . " LIMIT {$limit} OFFSET {$offset}";

        $query = $this->pdo->prepare($queryText);
        $query->execute($params);
        $rows = $this->transform($key, $query->fetchAll());

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'exportLimited' => $export && $total > $limit,
        ];
    }

    private function source(string $key, array $filters): array
    {
        $from = $this->validDate((string) ($filters['from'] ?? '')) ?: date('Y-m-01');
        $to = $this->validDate((string) ($filters['to'] ?? '')) ?: date('Y-m-d');

        return match ($key) {
            'accesos' => [
                'SELECT id, usuario, agent AS dispositivo, ip, fecha, hora_i, tiempo_conexion, activa FROM logsesiones',
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
                "SELECT p.id, e.razon AS empresa, cl.nombre AS cliente, p.monto, p.saldo_acreditable,
                        COALESCE(b.nombre_corto, p.banco) AS banco, p.fecha_banco, p.fecha_asignacion,
                        CONCAT_WS(' ', p.detalle, CONCAT(COALESCE(p.descripcion, ''), '{', COALESCE(MAX(ce.referencia), ''), '}'), p.descripcion_detallada) AS detalles,
                        f.uuid, CONCAT_WS(' / ', f.serie, f.folio) AS serie_folio
                 FROM pagos p
                 LEFT JOIN clientes cl ON cl.id = p.nombre
                 LEFT JOIN captura c ON c.id = p.id_captura
                 LEFT JOIN bancos b ON b.id = c.nombre_corto
                 LEFT JOIN captura_edo_cta ce ON ce.id_captura = p.id_captura
                    AND (p.monto = ce.deposito OR p.monto = ce.retiro) AND p.saldo = ce.saldo
                 LEFT JOIN empresas e ON e.id = c.razon
                 INNER JOIN facturas f ON f.id_pago = p.id
                 WHERE p.saldo_acreditable <> 0 AND p.fecha_asignacion BETWEEN :date_from AND :date_to
                 GROUP BY p.id, e.razon, cl.nombre, p.monto, p.saldo_acreditable, b.nombre_corto,
                          p.banco, p.fecha_banco, p.fecha_asignacion, p.detalle, p.descripcion,
                          p.descripcion_detallada, f.uuid, f.serie, f.folio",
                ['date_from' => $from, 'date_to' => $to], '`fecha_asignacion` DESC, `id` DESC',
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
                        COUNT(rfp.id) AS folios_asociados
                 FROM pagos p
                 LEFT JOIN clientes cl ON cl.id = p.cliente_origen_comision
                 INNER JOIN captura c ON c.id = p.id_captura
                 INNER JOIN bancos b ON b.id = c.nombre_corto
                 INNER JOIN captura_edo_cta ce ON ce.id_captura = p.id_captura
                    AND (p.monto = ce.deposito OR p.monto = ce.retiro)
                 INNER JOIN empresas e ON e.id = c.razon
                 INNER JOIN relacion_factura_pago rfp ON rfp.id_pago = p.id
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
            'banking' => [$this->bankingQuery(), [], '`empresa_origen`, `porcentaje_real` DESC'],
            'correos-factura' => [
                'SELECT fc.id, e1.razon AS origen, e2.razon AS destino, fc.tamplate AS peticion,
                        fc.respuesta, fc.fecha_envia, fc.fecha_recibe
                 FROM facturas_correo fc
                 INNER JOIN empresas e1 ON e1.id = fc.empresa_origen
                 INNER JOIN empresas e2 ON e2.id = fc.empresa_destino',
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

    private function bankingQuery(): string
    {
        return "SELECT banking_rows.empresa_origen, banking_rows.proveedor, banking_rows.total,
                       ROUND(banking_rows.porcentaje_real, 2) AS porcentaje_real,
                       banking_rows.porcentaje_sugerido,
                       CONCAT(ROUND(banking_rows.rango_inferior, 2), '% - ', ROUND(banking_rows.rango_superior, 2), '%') AS rango,
                       CASE WHEN banking_rows.porcentaje_real BETWEEN banking_rows.rango_inferior AND banking_rows.rango_superior
                            THEN 'Dentro de rango' ELSE 'Fuera de rango' END AS estado
                FROM (
                    SELECT daily.empresa_origen, daily.proveedor, daily.total, daily.porcentaje_sugerido,
                           daily.total / NULLIF(SUM(daily.total) OVER (PARTITION BY daily.empresa_origen), 0) * 100 AS porcentaje_real,
                           daily.porcentaje_sugerido * 0.70 AS rango_inferior,
                           daily.porcentaje_sugerido * 1.30 AS rango_superior
                    FROM (
                        SELECT origin.razon AS empresa_origen, provider.razon AS proveedor,
                               SUM(f.total_iva) AS total, p14.porcentaje_sugerido
                        FROM claves_cortas cl
                        INNER JOIN empresas origin ON origin.rfc = cl.rfc
                        INNER JOIN facturas f ON f.id_clave_corta = cl.id
                        INNER JOIN empresas provider ON provider.id = f.razon
                        INNER JOIN proveedores_14 p14 ON p14.id_empresa = cl.id AND p14.id_proveedor = provider.id
                        WHERE f.metodo_pago = 1 AND DATE(f.fecha) = CURDATE()
                          AND f.razon <> 132 AND provider.comodin = 0 AND origin.id <> 132
                        GROUP BY origin.razon, provider.razon, p14.porcentaje_sugerido
                    ) daily
                ) banking_rows";
    }

    private function transform(string $key, array $rows): array
    {
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
