<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function page(
        string $search,
        int $page,
        int $perPage = 25,
        string $dateFrom = '',
        string $dateTo = ''
    ): array
    {
        $where = 'WHERE f.razon <> 132';
        $params = [];
        if ($search !== '') {
            $where .= " AND CONCAT_WS(' ', f.id, f.folio, f.serie, f.uuid, c.nombre, e.razon, cc.rfc, cc.razon_social) LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        if ($dateFrom !== '') {
            $where .= ' AND f.fecha >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where .= ' AND f.fecha < :date_to';
            $params['date_to'] = (new \DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $joins = ' FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN empresas e ON e.id = f.razon
            INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            LEFT JOIN formas_pago fp ON fp.id = f.forma_pago
            LEFT JOIN metodos_pago mp ON mp.id = f.metodo_pago
            LEFT JOIN (
                SELECT id_factura, SUM(monto) AS total_pagado
                FROM facturar_rel_complementos
                GROUP BY id_factura
            ) pagos_factura ON pagos_factura.id_factura = f.id
            LEFT JOIN usuarios u ON u.id = f.usuario_crea ';
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $fetchLimit = $perPage + 1;
        $query = $this->pdo->prepare('SELECT f.id, f.fecha, f.serie, f.folio, f.total_iva, f.uuid, f.token,
            f.status, f.status_error, f.status_pago, f.id_pago, f.metodo_pago AS method_id,
            f.nombre AS client_id, f.razon AS company_id, f.id_clave_corta AS receiver_id,
            GREATEST(0, f.total_iva - COALESCE(pagos_factura.total_pagado, 0)) AS saldo_pendiente,
            c.nombre AS cliente, c.correo AS cliente_correo,
            e.razon AS empresa, cc.razon_social AS receptor, cc.rfc,
            fp.forma_pago, mp.metodo_pago, COALESCE(u.nombre, "AUTO") AS usuario' .
            $joins . $where . " ORDER BY f.id DESC LIMIT {$fetchLimit} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }
        $rows = $this->attachAnalytics($rows);
        return [
            'rows' => $rows, 'total' => count($rows), 'page' => $page,
            'lastPage' => $page + ($hasNext ? 1 : 0), 'hasNext' => $hasNext, 'totalExact' => false,
        ];
    }

    public function exportRows(string $search, string $dateFrom = '', string $dateTo = ''): array
    {
        if ($search === '' && $dateFrom === '' && $dateTo === '') {
            $today = new \DateTimeImmutable('today');
            $dateFrom = $today->modify('-9 days')->format('Y-m-d');
            $dateTo = $today->format('Y-m-d');
        }

        return $this->page($search, 1, 10000, $dateFrom, $dateTo)['rows'];
    }

    private function attachAnalytics(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $folios = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['folio'] ?? 0),
            $rows
        ))));
        $analyticsByFolio = [];
        foreach (array_chunk($folios, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $query = $this->pdo->prepare("SELECT analysis.id AS analysis_id, analysis.id_factura,
                    analysis.resultado_general AS analysis_result,
                    analysis.resumen AS analysis_summary,
                    analysis.conceptos_error AS analysis_errors
                FROM facturas_analitica analysis
                INNER JOIN (
                    SELECT id_factura, MAX(id) AS latest_id
                    FROM facturas_analitica
                    WHERE id_factura IN ({$placeholders})
                    GROUP BY id_factura
                ) latest_analysis ON latest_analysis.latest_id = analysis.id");
            $query->execute($chunk);
            foreach ($query->fetchAll() as $analysis) {
                $analyticsByFolio[(int) $analysis['id_factura']] = $analysis;
            }
        }

        foreach ($rows as &$row) {
            $analysis = $analyticsByFolio[(int) ($row['folio'] ?? 0)] ?? [];
            $row['analysis_id'] = $analysis['analysis_id'] ?? null;
            $row['analysis_result'] = $analysis['analysis_result'] ?? null;
            $row['analysis_summary'] = $analysis['analysis_summary'] ?? null;
            $row['analysis_errors'] = $analysis['analysis_errors'] ?? null;
        }
        unset($row);
        return $rows;
    }

    public function formData(): array
    {
        return [
            'companies' => $this->pdo->query('SELECT id, razon, rfc FROM empresas ORDER BY razon')->fetchAll(),
            'clients' => $this->pdo->query('SELECT id, nombre FROM clientes WHERE COALESCE(activo, 1) = 1 ORDER BY nombre')->fetchAll(),
            'methods' => $this->pdo->query('SELECT id, clave, metodo_pago FROM metodos_pago ORDER BY id')->fetchAll(),
            'forms' => $this->pdo->query('SELECT id, clave, forma_pago FROM formas_pago ORDER BY clave')->fetchAll(),
            'uses' => $this->pdo->query('SELECT id, cod_sat, uso_cfdi FROM catalogo_usos_cfdi ORDER BY cod_sat')->fetchAll(),
            'currencies' => $this->pdo->query('SELECT id, clave_moneda, moneda FROM catalogo_monedas ORDER BY id')->fetchAll(),
        ];
    }

    public function paymentPrefill(int $paymentId): array
    {
        if ($paymentId <= 0) {
            throw new RuntimeException('El movimiento seleccionado no es válido.');
        }
        $query = $this->pdo->prepare("SELECT p.id, p.monto, p.tipo_movimiento, p.id_factura, p.id_factura_res,
                CAST(p.nombre AS UNSIGNED) AS client_id,
                CAST(COALESCE(ca.razon, p.empresa) AS UNSIGNED) AS company_id,
                cl.nombre AS cliente, e.razon AS empresa
            FROM pagos p
            LEFT JOIN captura ca ON ca.id = p.id_captura
            LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            LEFT JOIN empresas e ON e.id = CAST(COALESCE(ca.razon, p.empresa) AS UNSIGNED)
            WHERE p.id = :id AND p.status = 'asignado'
              AND p.tipo_movimiento <> 'Abono por comisión'
              AND p.banco <> 'STP' AND p.banco <> '99'
            LIMIT 1");
        $query->execute(['id' => $paymentId]);
        $payment = $query->fetch();
        if (!$payment) {
            throw new RuntimeException('El movimiento ya no está asignado o no está disponible para facturación.');
        }
        if (strcasecmp(trim((string) ($payment['tipo_movimiento'] ?? '')), 'Retiro') === 0) {
            throw new RuntimeException('Los retiros no se pueden relacionar con facturas.');
        }
        if ((int) ($payment['id_factura'] ?? 0) > 0) {
            throw new RuntimeException('El movimiento ya está relacionado con una factura.');
        }
        if (self::positiveIntegerReference($payment['id_factura_res'] ?? null) > 0) {
            throw new RuntimeException('El movimiento ya está relacionado con un complemento de pago.');
        }
        if ((int) $payment['client_id'] <= 0 || (int) $payment['company_id'] <= 0) {
            throw new RuntimeException('El movimiento no tiene empresa y cliente válidos para crear la factura.');
        }

        $receivers = $this->receivers((int) $payment['client_id']);
        return [
            'id' => (int) $payment['id'],
            'amount' => round(abs((float) $payment['monto']), 2),
            'client_id' => (int) $payment['client_id'],
            'company_id' => (int) $payment['company_id'],
            'receiver_id' => count($receivers) === 1 ? (int) $receivers[0]['id'] : 0,
            'cliente' => (string) ($payment['cliente'] ?? ''),
            'empresa' => (string) ($payment['empresa'] ?? ''),
        ];
    }

    public function receivers(int $clientId): array
    {
        $query = $this->pdo->prepare('SELECT id, clave_corta, razon_social, rfc, cp, regimen_fiscal FROM claves_cortas WHERE id_cliente = :client ORDER BY razon_social');
        $query->execute(['client' => $clientId]);
        return $query->fetchAll();
    }

    public function concepts(int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT id, concepto, clave_sat, clave_unidad_medida, aplica_iva, impuesto FROM conceptos WHERE razon = :company ORDER BY concepto');
        $query->execute(['company' => $companyId]);
        return $query->fetchAll();
    }

    public function associatedComplements(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $invoiceQuery = $this->pdo->prepare('SELECT f.id, f.fecha, f.serie, f.folio, f.uuid,
            f.total_iva, f.metodo_pago, c.nombre AS cliente, e.razon AS empresa,
            cc.clave_corta, cc.razon_social AS receptor, cc.rfc
            FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN empresas e ON e.id = f.razon
            INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            WHERE f.id = :id LIMIT 1');
        $invoiceQuery->execute(['id' => $invoiceId]);
        $invoice = $invoiceQuery->fetch();
        if (!$invoice || (int) $invoice['metodo_pago'] !== 2) {
            return null;
        }

        $relationsQuery = $this->pdo->prepare('SELECT rel.id, rel.id_complemento, rel.monto,
            rel.num_pago, rel.saldo, rel.restante, rel.fecha_pago,
            fc.serie, fc.folio, fc.uuid, fc.status, fc.status_error
            FROM facturar_rel_complementos rel
            INNER JOIN facturas_complementos fc ON fc.id = rel.id_complemento
            WHERE rel.id_factura = :invoice
            ORDER BY rel.num_pago ASC, rel.id ASC');
        $relationsQuery->execute(['invoice' => $invoiceId]);
        $relations = $relationsQuery->fetchAll();
        $paid = array_reduce($relations, static fn (float $total, array $relation): float =>
            $total + (float) $relation['monto'], 0.0);
        $invoice['paid'] = round($paid, 2);
        $invoice['balance'] = max(0, round((float) $invoice['total_iva'] - $paid, 2));
        $invoice['complement_count'] = count(array_unique(array_map(
            static fn (array $relation): int => (int) $relation['id_complemento'], $relations
        )));
        $invoice['complements'] = $relations;
        return $invoice;
    }

    public function create(array $input, int $operatorId): int
    {
        $companyId = (int) ($input['company_id'] ?? 0);
        $clientId = (int) ($input['client_id'] ?? 0);
        $receiverId = (int) ($input['receiver_id'] ?? 0);
        $methodId = (int) ($input['method_id'] ?? 0);
        $formId = (int) ($input['form_id'] ?? 0);
        $useId = (int) ($input['use_id'] ?? 0);
        $currencyId = (int) ($input['currency_id'] ?? 0);
        $paymentId = (int) ($input['payment_id'] ?? 0);
        $statusPayment = (string) ($input['status_payment'] ?? 'Pagado');
        $voucherType = (string) ($input['voucher_type'] ?? 'I');
        $retention = (string) ($input['retention'] ?? 'No');
        if (!in_array($statusPayment, ['Pagado', 'No Pagado'], true) || !in_array($voucherType, ['I', 'E', 'T', 'N', 'P'], true) || !in_array($retention, ['No', 'Si'], true)) {
            throw new RuntimeException('La configuración fiscal contiene valores no permitidos.');
        }
        foreach (['empresa' => [$companyId, 'empresas'], 'cliente' => [$clientId, 'clientes'], 'razón social' => [$receiverId, 'claves_cortas'], 'método' => [$methodId, 'metodos_pago'], 'forma' => [$formId, 'formas_pago'], 'uso CFDI' => [$useId, 'catalogo_usos_cfdi'], 'moneda' => [$currencyId, 'catalogo_monedas']] as $label => [$id, $table]) {
            if ($id <= 0 || !$this->exists($table, $id)) {
                throw new RuntimeException('Selecciona un valor válido para ' . $label . '.');
            }
        }
        $receiver = $this->pdo->prepare('SELECT id FROM claves_cortas WHERE id = :id AND id_cliente = :client');
        $receiver->execute(['id' => $receiverId, 'client' => $clientId]);
        if (!$receiver->fetchColumn()) {
            throw new RuntimeException('La razón social no pertenece al cliente seleccionado.');
        }

        $conceptIds = (array) ($input['concept_id'] ?? []);
        $quantities = (array) ($input['quantity'] ?? []);
        $descriptions = (array) ($input['description'] ?? []);
        $prices = (array) ($input['unit_price'] ?? []);
        $taxRates = (array) ($input['tax_rate'] ?? []);
        if ($conceptIds === []) {
            throw new RuntimeException('Agrega al menos un concepto a la factura.');
        }
        $conceptQuery = $this->pdo->prepare('SELECT * FROM conceptos WHERE id = :id AND razon = :company');
        $details = [];
        $total = 0.0;
        $subtotal = 0.0;
        $retainedTax = 0.0;
        foreach ($conceptIds as $index => $rawId) {
            $conceptQuery->execute(['id' => (int) $rawId, 'company' => $companyId]);
            $concept = $conceptQuery->fetch();
            if (!$concept) {
                throw new RuntimeException('Uno de los conceptos no pertenece a la empresa emisora.');
            }
            $quantity = (float) ($quantities[$index] ?? 0);
            $grossUnit = (float) ($prices[$index] ?? 0);
            $tax = (int) ($taxRates[$index] ?? $concept['impuesto'] ?? 16);
            if ($quantity <= 0 || $grossUnit < 0 || !in_array($tax, [0, 16], true)) {
                throw new RuntimeException('Revisa cantidad, precio e IVA de los conceptos.');
            }
            $description = trim((string) ($descriptions[$index] ?? $concept['concepto']));
            if ($description === '') {
                throw new RuntimeException('La descripción del concepto es obligatoria.');
            }
            $appliesTax = $tax > 0 && strtolower((string) $concept['aplica_iva']) !== 'no';
            $netUnit = $appliesTax ? $grossUnit / (1 + ($tax / 100)) : $grossUnit;
            $net = round($netUnit * $quantity, 2);
            $gross = round($grossUnit * $quantity, 2);
            $subtotal += $net;
            $total += $gross;
            if ($retention === 'Si' && $appliesTax) {
                $retainedTax += round($net * 0.04, 6);
            }
            $details[] = compact('concept', 'quantity', 'grossUnit', 'tax', 'netUnit', 'net', 'gross', 'description');
        }
        $retainedTax = round($retainedTax, 2);
        $payableTotal = round($total - $retainedTax, 2);

        $this->pdo->beginTransaction();
        try {
            $token = bin2hex(random_bytes(20));
            $series = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $folio = random_int(100000, 900000);
            $insert = $this->pdo->prepare('INSERT INTO facturas
                (nombre, metodo_pago, moneda, forma_pago, status_pago, razon, total_iva, token,
                 tipo_comprobante, uso_cfdi, id_clave_corta, id_captura, folio, usuario_crea,
                 serie, retencion_transporte, xml_total, xml_subtotal, factura_manual, status)
                VALUES (:client, :method, :currency, :form, :payment_status, :company, :total, :token,
                 :voucher, :use_cfdi, :receiver, 0, :folio, :operator, :series, :retention,
                 :xml_total, :xml_subtotal, 1, "Pendiente")');
            $insert->execute(['client' => $clientId, 'method' => $methodId, 'currency' => $currencyId,
                'form' => $formId, 'payment_status' => $statusPayment, 'company' => $companyId,
                'total' => $payableTotal, 'token' => $token, 'voucher' => $voucherType,
                'use_cfdi' => $useId, 'receiver' => $receiverId, 'folio' => $folio,
                'operator' => $operatorId, 'series' => $series, 'retention' => $retention,
                'xml_total' => $payableTotal, 'xml_subtotal' => round($subtotal, 2)]);
            $invoiceId = (int) $this->pdo->lastInsertId();
            $detailInsert = $this->pdo->prepare('INSERT INTO detalle_factura
                (id_factura, id_producto, cantidad, precio_unitario, precio_con_iva, precio_neto, precio_neto_iva, concepto, iva)
                VALUES (:invoice, :product, :quantity, :unit_net, :unit_gross, :net, :gross, :description, :tax)');
            foreach ($details as $detail) {
                $detailInsert->execute(['invoice' => $invoiceId, 'product' => $detail['concept']['id'],
                    'quantity' => $detail['quantity'], 'unit_net' => round($detail['netUnit'], 6),
                    'unit_gross' => $detail['grossUnit'], 'net' => $detail['net'], 'gross' => $detail['gross'],
                    'description' => $detail['description'], 'tax' => $detail['tax']]);
            }
            if ($paymentId > 0) {
                $payment = $this->pdo->prepare('SELECT id, tipo_movimiento, id_factura, id_factura_res FROM pagos WHERE id = :id FOR UPDATE');
                $payment->execute(['id' => $paymentId]);
                $row = $payment->fetch();
                if (!$row) {
                    throw new RuntimeException('El pago asociado no existe.');
                }
                if (strcasecmp(trim((string) ($row['tipo_movimiento'] ?? '')), 'Retiro') === 0) {
                    throw new RuntimeException('Los retiros no se pueden relacionar con facturas.');
                }
                if ((int) ($row['id_factura'] ?? 0) > 0) {
                    throw new RuntimeException('El pago seleccionado ya está relacionado con otra factura.');
                }
                if (self::positiveIntegerReference($row['id_factura_res'] ?? null) > 0) {
                    throw new RuntimeException('El pago seleccionado ya está relacionado con un complemento de pago.');
                }
                $this->pdo->prepare('UPDATE pagos SET id_factura = :invoice WHERE id = :payment')->execute(['invoice' => $invoiceId, 'payment' => $paymentId]);
                $this->pdo->prepare('UPDATE facturas SET id_pago = :payment WHERE id = :invoice')->execute(['payment' => $paymentId, 'invoice' => $invoiceId]);
            }
            $this->pdo->commit();
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deletePending(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id, uuid FROM facturas WHERE id = :id FOR UPDATE');
            $query->execute(['id' => $id]);
            $invoice = $query->fetch();
            if (!$invoice) throw new RuntimeException('La factura solicitada no existe.');
            if (trim((string) ($invoice['uuid'] ?? '')) !== '') {
                throw new RuntimeException('Una factura timbrada no puede eliminarse desde este módulo.');
            }
            $relations = $this->pdo->prepare('SELECT COUNT(*) FROM facturar_rel_complementos WHERE id_factura = :id');
            $relations->execute(['id' => $id]);
            if ((int) $relations->fetchColumn() > 0) {
                throw new RuntimeException('La factura está relacionada con un complemento de pago y no puede eliminarse.');
            }
            $this->pdo->prepare('UPDATE pagos SET id_factura = NULL WHERE id_factura = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM detalle_factura WHERE id_factura = :id')->execute(['id' => $id]);
            $favoriteTable = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'facturas_fav'")->fetchColumn();
            if ((int) $favoriteTable > 0) {
                $this->pdo->prepare('DELETE FROM facturas_fav WHERE id_factura = :id')->execute(['id' => $id]);
            }
            $this->pdo->prepare('DELETE FROM facturas WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function exists(string $table, int $id): bool
    {
        $allowed = ['empresas', 'clientes', 'claves_cortas', 'metodos_pago', 'formas_pago', 'catalogo_usos_cfdi', 'catalogo_monedas'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }
        $query = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = :id");
        $query->execute(['id' => $id]);
        return (bool) $query->fetchColumn();
    }

    private static function positiveIntegerReference(mixed $value): int
    {
        $value = trim((string) $value);
        return preg_match('/^[0-9]+$/D', $value) && (int) $value > 0 ? (int) $value : 0;
    }
}
