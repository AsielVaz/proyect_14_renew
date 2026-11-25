<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class PaymentComplementRepository
{
    private const ALLOWED_TYPES = ['T', 'T0', 'SV', '4'];
    private const TREATMENT_LABELS = [
        'T' => 'Típico · IVA 16%',
        'T0' => 'Tasa 0%',
        'SV' => 'Sin IVA',
        '4' => 'Retención 4%',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function paginate(
        string $search,
        int $page,
        int $perPage = 30,
        string $dateFrom = '',
        string $dateTo = ''
    ): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage + 1;
        $where = ' WHERE 1 = 1';
        $params = [];
        if ($search !== '') {
            $textCondition = "CONCAT_WS(' ', CAST(fc.id AS CHAR), fc.uuid, c.nombre,
                e.razon, cc.rfc, CAST(fc.folio AS CHAR), CAST(fc.total_factura AS CHAR)) LIKE :search";
            $amount = self::numericSearchValue($search);
            $where .= $amount !== null
                ? " AND (fc.total_factura = :search_amount OR {$textCondition})"
                : " AND {$textCondition}";
            $params['search'] = '%' . $search . '%';
            if ($amount !== null) {
                $params['search_amount'] = $amount;
            }
        }
        if ($dateFrom !== '') {
            $where .= ' AND fc.fecha_pago >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where .= ' AND fc.fecha_pago < :date_to';
            $params['date_to'] = (new \DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        $query = $this->pdo->prepare("SELECT fc.id, fc.serie, fc.folio, fc.total_factura,
            fc.uuid, fc.fecha_timbrado, fc.fecha_pago, fc.status, fc.status_error,
            fc.cadena AS tipo_complemento, c.nombre AS cliente,
            NULLIF(TRIM(c.correo2), '') AS cliente_correo, e.razon AS empresa,
            cc.razon_social AS receptor, cc.rfc, fp.forma_pago,
            COALESCE(cf.folio, fc.folio) AS folio_interno,
            (SELECT COUNT(*) FROM facturar_rel_complementos rel WHERE rel.id_complemento = fc.id) AS facturas_relacionadas
            FROM facturas_complementos fc
            LEFT JOIN clientes c ON c.id = fc.nombre
            LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN claves_cortas cc ON cc.id = fc.id_clave_corta
            LEFT JOIN formas_pago fp ON fp.id = fc.forma_pago
            LEFT JOIN complementos_folio cf ON cf.id = (
                SELECT MAX(cf2.id) FROM complementos_folio cf2 WHERE cf2.id_factura = fc.id
            ){$where} ORDER BY fc.id DESC LIMIT {$limit} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'page' => $page, 'hasNext' => $hasNext];
    }

    public function exportRows(string $search, string $dateFrom = '', string $dateTo = ''): array
    {
        return $this->paginate($search, 1, 10000, $dateFrom, $dateTo)['rows'];
    }

    public function formData(): array
    {
        return [
            'companies' => $this->pdo->query('SELECT id, razon, rfc FROM empresas ORDER BY razon')->fetchAll(),
            'clients' => $this->pdo->query('SELECT id, nombre FROM clientes WHERE COALESCE(activo, 1) = 1 ORDER BY nombre')->fetchAll(),
            'forms' => $this->pdo->query('SELECT id, clave, forma_pago FROM formas_pago WHERE clave <> 99 ORDER BY clave')->fetchAll(),
        ];
    }

    public function receivers(int $clientId, int $companyId = 0): array
    {
        if ($companyId > 0) {
            $query = $this->pdo->prepare(<<<'SQL'
                SELECT cc.id, cc.clave_corta, cc.razon_social, cc.rfc, cc.cp, cc.regimen_fiscal,
                       COALESCE(vivos.documentos_vivos, 0) AS documentos_vivos,
                       COALESCE(cc.documentos_vivos, 0) AS documentos_vivos_totales
                FROM claves_cortas cc
                LEFT JOIN (
                    SELECT UPPER(TRIM(invoice_cc.rfc)) AS receiver_rfc, COUNT(*) AS documentos_vivos
                    FROM facturas f
                    INNER JOIN claves_cortas invoice_cc
                        ON CAST(invoice_cc.id AS CHAR) = TRIM(f.id_clave_corta)
                    LEFT JOIN (
                        SELECT id_factura, SUM(monto) AS total_pagado
                        FROM facturar_rel_complementos GROUP BY id_factura
                    ) pagos_factura ON pagos_factura.id_factura = f.id
                    WHERE f.razon = :company
                      AND f.metodo_pago = 2
                      AND COALESCE(f.uuid, '') <> ''
                      AND COALESCE(f.status, '') <> 'Cancelado'
                      AND TRIM(COALESCE(f.id_clave_corta, '')) <> ''
                      AND TRIM(COALESCE(invoice_cc.rfc, '')) <> ''
                      AND f.total_iva - COALESCE(pagos_factura.total_pagado, 0) > 0.005
                    GROUP BY UPPER(TRIM(invoice_cc.rfc))
                ) vivos ON vivos.receiver_rfc = UPPER(TRIM(cc.rfc))
                WHERE cc.id_cliente = :client
                ORDER BY cc.razon_social
                SQL);
            $query->execute(['client' => $clientId, 'company' => $companyId]);
        } else {
            $query = $this->pdo->prepare('SELECT id, clave_corta, razon_social, rfc, cp, regimen_fiscal,
                    COALESCE(documentos_vivos, 0) AS documentos_vivos,
                    COALESCE(documentos_vivos, 0) AS documentos_vivos_totales
                FROM claves_cortas WHERE id_cliente = :client ORDER BY razon_social');
            $query->execute(['client' => $clientId]);
        }
        $receivers = $query->fetchAll();
        foreach ($receivers as &$receiver) {
            $receiver['documentos_vivos'] = max(0, (int) ($receiver['documentos_vivos'] ?? 0));
            $receiver['documentos_vivos_totales'] = max(0, (int) ($receiver['documentos_vivos_totales'] ?? 0));
            $receiver['has_live_documents'] = $receiver['documentos_vivos'] > 0;
            $receiver['documents_scope'] = $companyId > 0 ? 'company_rfc' : 'all_companies';
        }
        unset($receiver);
        return $receivers;
    }

    public function invoiceByUuid(string $uuid, int $manualCompanyId = 0): ?array
    {
        $uuid = strtoupper(trim($uuid));
        if ($uuid === '') {
            return null;
        }
        $invoice = $this->findInvoice('UPPER(f.uuid) = :value', $uuid);
        if (!$invoice || $manualCompanyId <= 0) {
            return $invoice;
        }
        if ((int) $invoice['company_id'] !== $manualCompanyId) {
            throw new RuntimeException('La factura pegada no pertenece a la empresa emisora seleccionada.');
        }
        $invoice['manual_lookup_token'] = $this->manualLookupToken($invoice, $manualCompanyId);
        return $invoice;
    }

    public function invoiceById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return $this->findInvoice('f.id = :value', $id);
    }

    public function paymentPrefill(int $paymentId): array
    {
        if ($paymentId <= 0) {
            throw new RuntimeException('El movimiento seleccionado no es válido.');
        }

        $query = $this->pdo->prepare("SELECT p.id, p.tipo_movimiento, p.id_factura, p.id_factura_res,
                ABS(p.monto) AS movement_amount,
                CAST(p.nombre AS UNSIGNED) AS client_id,
                CAST(COALESCE(ca.razon, p.empresa) AS UNSIGNED) AS company_id,
                cl.nombre AS cliente, e.razon AS empresa,
                COALESCE((SELECT SUM(fc.total_factura)
                    FROM facturas_complementos fc
                    WHERE fc.id_pago = p.id AND COALESCE(fc.status, '') <> 'Cancelado'), 0) AS utilizado,
                DATE(COALESCE(NULLIF(p.fecha_banco, ''), p.fecha_operacion, p.fecha_asignacion, p.fecha_captura)) AS payment_date
            FROM pagos p
            LEFT JOIN captura ca ON ca.id = p.id_captura
            LEFT JOIN clientes cl ON cl.id = CAST(p.nombre AS UNSIGNED)
            LEFT JOIN empresas e ON e.id = CAST(COALESCE(ca.razon, p.empresa) AS UNSIGNED)
            WHERE p.id = :id
              AND p.status = 'asignado'
              AND p.tipo_movimiento <> 'Abono por comisión'
              AND p.banco <> 'STP'
              AND p.banco <> '99'
            LIMIT 1");
        $query->execute(['id' => $paymentId]);
        $payment = $query->fetch();
        if (!$payment) {
            throw new RuntimeException('El movimiento ya no está asignado o no está disponible para complementos.');
        }
        if (strcasecmp(trim((string) ($payment['tipo_movimiento'] ?? '')), 'Retiro') === 0) {
            throw new RuntimeException('Los retiros no se pueden relacionar con complementos de pago.');
        }
        if ((int) ($payment['id_factura'] ?? 0) > 0) {
            throw new RuntimeException('El movimiento ya está relacionado con una factura.');
        }
        if (self::positiveIntegerReference($payment['id_factura_res'] ?? null) > 0) {
            throw new RuntimeException('El movimiento ya está relacionado con un complemento de pago.');
        }

        $clientId = (int) $payment['client_id'];
        $companyId = (int) $payment['company_id'];
        $maximum = round((float) $payment['movement_amount'] - (float) $payment['utilizado'], 2);
        if ($clientId <= 0 || $companyId <= 0 || trim((string) $payment['cliente']) === '' || trim((string) $payment['empresa']) === '') {
            throw new RuntimeException('El movimiento no tiene empresa y cliente válidos para crear el complemento.');
        }
        if ($maximum <= 0) {
            throw new RuntimeException('El monto del movimiento ya fue aplicado por completo en otros complementos.');
        }
        return [
            'id' => (int) $payment['id'],
            'client_id' => $clientId,
            'company_id' => $companyId,
            'cliente' => (string) $payment['cliente'],
            'empresa' => (string) $payment['empresa'],
            'maximum' => $maximum,
            'movement_amount' => round((float) $payment['movement_amount'], 2),
            'payment_date' => (string) ($payment['payment_date'] ?: date('Y-m-d')),
        ];
    }

    public function pendingInvoicesForPayment(int $paymentId, int $companyId, int $receiverId): array
    {
        if ($companyId <= 0) {
            throw new RuntimeException('Selecciona una empresa emisora para consultar sus facturas pendientes.');
        }
        if ($receiverId <= 0) {
            throw new RuntimeException('Selecciona una clave corta para consultar sus facturas pendientes.');
        }

        $payment = $this->paymentPrefill($paymentId);
        $this->assertReference('empresas', $companyId, 'empresa emisora');
        $receiverRfc = $this->receiverRfcForClient((int) $payment['client_id'], $receiverId);
        $movementAmount = (float) $payment['movement_amount'];

        $query = $this->pdo->prepare("SELECT f.id, f.uuid, f.serie, f.folio, f.total_iva,
                f.xml_firmado, f.retencion_transporte, f.xml_total, f.xml_subtotal,
                f.nombre AS client_id, f.id_clave_corta AS receiver_id,
                c.nombre AS cliente, cc.razon_social AS receptor, cc.rfc,
                DATE(f.fecha_timbrado) AS fecha_timbrado,
                COALESCE(rel.pagado, 0) AS pagado,
                GREATEST(ROUND(f.total_iva - COALESCE(rel.pagado, 0), 2), 0) AS saldo,
                COALESCE(rel.ultima_parcialidad, 0) + 1 AS siguiente_parcialidad
            FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN claves_cortas cc ON CAST(cc.id AS CHAR) = TRIM(f.id_clave_corta)
            LEFT JOIN (
                SELECT id_factura, SUM(monto) AS pagado, MAX(num_pago) AS ultima_parcialidad
                FROM facturar_rel_complementos
                GROUP BY id_factura
            ) rel ON rel.id_factura = f.id
            WHERE f.razon = :company
              AND UPPER(TRIM(cc.rfc)) = :receiver_rfc
              AND f.metodo_pago = 2
              AND COALESCE(f.uuid, '') <> ''
              AND COALESCE(f.status, '') <> 'Cancelado'
              AND (f.total_iva - COALESCE(rel.pagado, 0) > 0.005
                   OR ABS(f.total_iva - :movement_amount) < 0.005)
            ORDER BY f.fecha_timbrado ASC, f.id ASC");
        $query->execute([
            'company' => $companyId,
            'receiver_rfc' => $receiverRfc,
            'movement_amount' => $movementAmount,
        ]);
        $invoices = $query->fetchAll();
        foreach ($invoices as &$invoice) {
            $invoice['id'] = (int) $invoice['id'];
            $invoice['total_iva'] = round((float) $invoice['total_iva'], 2);
            $invoice['pagado'] = round((float) $invoice['pagado'], 2);
            $invoice['saldo'] = round((float) $invoice['saldo'], 2);
            $invoice['siguiente_parcialidad'] = (int) $invoice['siguiente_parcialidad'];
            $invoice['client_id'] = (int) $invoice['client_id'];
            $invoice['receiver_id'] = (int) $invoice['receiver_id'];
            $invoice['matches_movement_amount'] = abs((float) $invoice['total_iva'] - $movementAmount) < 0.005;
            $this->addTaxTreatment($invoice);
        }
        unset($invoice);

        return [
            'invoices' => $invoices,
            'maximum' => (float) $payment['maximum'],
        ];
    }

    private function findInvoice(string $condition, string|int $value): ?array
    {
        $query = $this->pdo->prepare('SELECT f.id, f.uuid, f.serie, f.folio, f.total_iva,
            f.xml_firmado, f.retencion_transporte, f.xml_total, f.xml_subtotal,
            f.nombre AS client_id, f.razon AS company_id, f.id_clave_corta AS receiver_id,
            f.metodo_pago, c.nombre AS cliente, e.razon AS empresa, cc.clave_corta,
            cc.razon_social AS receptor,
            COALESCE(SUM(rel.monto), 0) AS pagado, COALESCE(MAX(rel.num_pago), 0) + 1 AS siguiente_parcialidad
            FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN empresas e ON e.id = f.razon
            INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            LEFT JOIN facturar_rel_complementos rel ON rel.id_factura = f.id
            WHERE ' . $condition . '
            GROUP BY f.id, f.uuid, f.serie, f.folio, f.total_iva, f.nombre, f.razon,
                f.id_clave_corta, f.metodo_pago, c.nombre, e.razon, cc.clave_corta, cc.razon_social
            ORDER BY f.id DESC LIMIT 1');
        $query->execute(['value' => $value]);
        $row = $query->fetch();
        if (!$row) {
            return null;
        }
        $row['saldo'] = max(0, round((float) $row['total_iva'] - (float) $row['pagado'], 2));
        $this->addTaxTreatment($row);
        return $row;
    }

    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT fc.*, c.nombre AS cliente,
            NULLIF(TRIM(c.correo2), "") AS cliente_correo, e.razon AS empresa,
            cc.razon_social AS receptor, cc.rfc, fp.forma_pago
            FROM facturas_complementos fc
            LEFT JOIN clientes c ON c.id = fc.nombre
            LEFT JOIN empresas e ON e.id = fc.razon
            LEFT JOIN claves_cortas cc ON cc.id = fc.id_clave_corta
            LEFT JOIN formas_pago fp ON fp.id = fc.forma_pago
            WHERE fc.id = :id');
        $query->execute(['id' => $id]);
        $complement = $query->fetch();
        if (!$complement) {
            return null;
        }
        $relations = $this->pdo->prepare('SELECT rel.*, f.total_iva, c.nombre AS cliente,
            e.razon AS empresa FROM facturar_rel_complementos rel
            LEFT JOIN facturas f ON f.id = rel.id_factura
            LEFT JOIN clientes c ON c.id = f.nombre
            LEFT JOIN empresas e ON e.id = f.razon
            WHERE rel.id_complemento = :id ORDER BY rel.id');
        $relations->execute(['id' => $id]);
        $complement['relations'] = $relations->fetchAll();
        return $complement;
    }

    public function create(array $input): int
    {
        $clientId = (int) ($input['client_id'] ?? 0);
        $receiverId = (int) ($input['receiver_id'] ?? 0);
        $companyId = (int) ($input['company_id'] ?? 0);
        $formId = (int) ($input['form_id'] ?? 0);
        $paymentId = (int) ($input['payment_id'] ?? 0);
        $paymentDate = trim((string) ($input['payment_date'] ?? ''));
        $type = '';
        $uuids = (array) ($input['invoice_uuid'] ?? []);
        $amounts = (array) ($input['amount'] ?? []);
        $sourceInvoiceId = (int) ($input['source_invoice_id'] ?? 0);

        if ($clientId <= 0 || $receiverId <= 0 || $companyId <= 0 || $formId <= 0) {
            throw new RuntimeException('Selecciona cliente, razón social, empresa emisora y forma de pago.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if (!$date || $date->format('Y-m-d') !== $paymentDate) {
            throw new RuntimeException('La fecha de pago no es válida.');
        }
        if ($uuids === []) {
            throw new RuntimeException('Agrega al menos una factura PPD al complemento.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertReference('clientes', $clientId, 'cliente');
            $this->assertReference('empresas', $companyId, 'empresa emisora');
            $this->assertReference('formas_pago', $formId, 'forma de pago');
            $this->receiverRfcForClient($clientId, $receiverId);
            $paymentMaximum = null;
            if ($paymentId > 0) {
                $payment = $this->pdo->prepare("SELECT p.id, p.tipo_movimiento, p.id_factura, p.id_factura_res,
                        CAST(p.nombre AS UNSIGNED) AS client_id,
                        CAST(COALESCE(ca.razon, p.empresa) AS UNSIGNED) AS company_id,
                        ABS(p.monto) AS movement_amount
                    FROM pagos p
                    LEFT JOIN captura ca ON ca.id = p.id_captura
                    WHERE p.id = :id AND p.status = 'asignado'
                      AND p.tipo_movimiento <> 'Abono por comisión'
                      AND p.banco <> 'STP' AND p.banco <> '99'
                    FOR UPDATE");
                $payment->execute(['id' => $paymentId]);
                $paymentRow = $payment->fetch();
                if (!$paymentRow) {
                    throw new RuntimeException('El movimiento de pago ya no está asignado o no está disponible.');
                }
                if (strcasecmp(trim((string) ($paymentRow['tipo_movimiento'] ?? '')), 'Retiro') === 0) {
                    throw new RuntimeException('Los retiros no se pueden relacionar con complementos de pago.');
                }
                if ((int) ($paymentRow['id_factura'] ?? 0) > 0) {
                    throw new RuntimeException('El movimiento ya está relacionado con una factura.');
                }
                if (self::positiveIntegerReference($paymentRow['id_factura_res'] ?? null) > 0) {
                    throw new RuntimeException('El movimiento ya está relacionado con un complemento de pago.');
                }
                $used = $this->pdo->prepare("SELECT COALESCE(SUM(total_factura), 0)
                    FROM facturas_complementos
                    WHERE id_pago = :payment AND COALESCE(status, '') <> 'Cancelado'");
                $used->execute(['payment' => $paymentId]);
                $paymentMaximum = round((float) $paymentRow['movement_amount'] - (float) $used->fetchColumn(), 2);
                if ($paymentMaximum <= 0) {
                    throw new RuntimeException('El monto del movimiento ya fue aplicado por completo en otros complementos.');
                }
            }

            $invoiceQuery = $this->pdo->prepare('SELECT id, uuid, serie, folio, total_iva, nombre,
                razon, id_clave_corta, metodo_pago, xml_firmado, retencion_transporte,
                xml_total, xml_subtotal FROM facturas WHERE UPPER(uuid) = :uuid
                ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paidQuery = $this->pdo->prepare('SELECT COALESCE(SUM(monto), 0) AS paid,
                GREATEST(COALESCE(MAX(num_pago), 0), 0) + 1 AS next_installment
                FROM facturar_rel_complementos WHERE id_factura = :invoice FOR UPDATE');
            $relations = [];
            $seen = [];
            $sourceInvoiceIncluded = $sourceInvoiceId <= 0;
            $total = 0.0;
            $inheritedType = null;
            foreach ($uuids as $index => $rawUuid) {
                $uuid = strtoupper(trim((string) $rawUuid));
                $amount = round((float) str_replace([',', '$', ' '], '', (string) ($amounts[$index] ?? '0')), 2);
                if ($uuid === '' || isset($seen[$uuid])) {
                    throw new RuntimeException('Cada factura debe tener un UUID único y válido.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('El monto debe ser mayor a cero.');
                }
                $invoiceQuery->execute(['uuid' => $uuid]);
                $invoice = $invoiceQuery->fetch();
                if (!$invoice) {
                    throw new RuntimeException('No se encontró la factura con UUID ' . $uuid . '.');
                }
                if ((int) $invoice['metodo_pago'] !== 2) {
                    throw new RuntimeException('La factura ' . $uuid . ' no usa el método PPD.');
                }
                if ((int) $invoice['razon'] !== $companyId) {
                    throw new RuntimeException('La factura ' . $uuid . ' no corresponde a la empresa emisora seleccionada.');
                }
                $invoiceType = $this->invoiceTaxTreatment($invoice);
                if ($inheritedType !== null && $invoiceType !== $inheritedType) {
                    throw new RuntimeException('Las facturas relacionadas tienen tratamientos fiscales distintos ('
                        . self::TREATMENT_LABELS[$inheritedType] . ' y '
                        . self::TREATMENT_LABELS[$invoiceType] . '). Crea complementos separados.');
                }
                $inheritedType ??= $invoiceType;
                $paidQuery->execute(['invoice' => $invoice['id']]);
                $paymentHistory = $paidQuery->fetch(PDO::FETCH_ASSOC);
                $paid = round((float) $paymentHistory['paid'], 2);
                $installment = (int) $paymentHistory['next_installment'];
                $balance = max(0, round((float) $invoice['total_iva'] - $paid, 2));
                if ($amount > $balance + 0.001) {
                    throw new RuntimeException('El monto para ' . $uuid . ' excede el saldo de $' . number_format($balance, 2) . '.');
                }
                $relations[] = ['invoice' => $invoice, 'amount' => $amount,
                    'installment' => $installment, 'balance' => $balance,
                    'remaining' => round($balance - $amount, 2)];
                if ((int) $invoice['id'] === $sourceInvoiceId) {
                    $sourceInvoiceIncluded = true;
                }
                $seen[$uuid] = true;
                $total += $amount;
            }
            if ($inheritedType === null) {
                throw new RuntimeException('No fue posible determinar el tratamiento fiscal de las facturas relacionadas.');
            }
            $type = $inheritedType;
            if (!$sourceInvoiceIncluded) {
                throw new RuntimeException('La factura PPD que originó el complemento debe permanecer relacionada.');
            }
            if ($paymentMaximum !== null && $total > $paymentMaximum + 0.001) {
                throw new RuntimeException('El total del complemento excede el máximo disponible de $' . number_format($paymentMaximum, 2) . '.');
            }

            $token = bin2hex(random_bytes(20));
            $folio = random_int(1, 999999);
            $insert = $this->pdo->prepare('INSERT INTO facturas_complementos
                (nombre, metodo_pago, moneda, forma_pago, status_pago, total_factura, razon,
                 tipo_factura, uso_cfdi, token, id_clave_corta, folio, serie, id_pago,
                 fecha_pago, cadena, factura_manual, status)
                VALUES (:client, 1, 1, :form, "", :total, :company, "P", 3, :token,
                 :receiver, :folio, "P", :payment, :payment_date, :type, 1, "Pendiente")');
            $insert->execute(['client' => $clientId, 'form' => $formId, 'total' => round($total, 2),
                'company' => $companyId, 'token' => $token, 'receiver' => $receiverId,
                'folio' => $folio, 'payment' => $paymentId, 'payment_date' => $paymentDate . ' 00:00:00',
                'type' => $type]);
            $complementId = (int) $this->pdo->lastInsertId();
            $relationInsert = $this->pdo->prepare('INSERT INTO facturar_rel_complementos
                (id_factura, id_complemento, monto, num_pago, saldo, restante, uuid_rel, folio, serie, fecha_pago)
                VALUES (:invoice, :complement, :amount, :installment, :balance, :remaining,
                 :uuid, :folio, :series, :payment_date)');
            foreach ($relations as $relation) {
                $invoice = $relation['invoice'];
                $relationInsert->execute(['invoice' => $invoice['id'], 'complement' => $complementId,
                    'amount' => $relation['amount'], 'installment' => $relation['installment'],
                    'balance' => $relation['balance'], 'remaining' => $relation['remaining'],
                    'uuid' => $invoice['uuid'], 'folio' => (int) ($invoice['folio'] ?? 0),
                    'series' => (string) ($invoice['serie'] ?? ''),
                    'payment_date' => $paymentDate . ' 00:00:00']);
            }
            if ($paymentId > 0) {
                $this->pdo->prepare('UPDATE pagos SET id_factura_res = :complement WHERE id = :payment')
                    ->execute(['complement' => $complementId, 'payment' => $paymentId]);
            }
            $this->pdo->commit();
            return $complementId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function pendingInvoiceCount(int $clientId): int
    {
        if ($clientId <= 0) return 0;
        $query = $this->pdo->prepare('SELECT COALESCE(SUM(documentos_vivos), 0)
            FROM claves_cortas WHERE id_cliente = :client');
        $query->execute(['client' => $clientId]);
        $value = $query->fetchColumn();
        return $value === false || $value === null ? 0 : max(0, (int) $value);
    }

    public function taxTreatmentLabel(array $invoice): ?string
    {
        $treatment = $this->invoiceTaxTreatment($invoice, true);
        return $treatment === null ? null : self::TREATMENT_LABELS[$treatment];
    }

    /** @param array<string, mixed> $invoice */
    private function addTaxTreatment(array &$invoice): void
    {
        $treatment = $this->invoiceTaxTreatment($invoice);
        $invoice['tax_treatment'] = $treatment;
        $invoice['tax_treatment_label'] = self::TREATMENT_LABELS[$treatment];
        unset($invoice['xml_firmado']);
    }

    /** @param array<string, mixed> $invoice */
    private function invoiceTaxTreatment(array $invoice, bool $strict = false): ?string
    {
        $xml = trim((string) ($invoice['xml_firmado'] ?? ''));
        if ($strict && trim((string) ($invoice['uuid'] ?? '')) === '') {
            $xml = '';
        }
        if ($xml !== '') {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded && (!$strict || $document->documentElement?->localName === 'Comprobante')) {
                $xpath = new \DOMXPath($document);
                $retentions = $xpath->query('//*[local-name()="Retencion" and @Impuesto="002"]');
                if ($retentions !== false && $retentions->length > 0) {
                    if (!$strict) {
                        return '4';
                    }
                    foreach ($retentions as $retention) {
                        if (!$retention instanceof \DOMElement) {
                            continue;
                        }
                        $base = (float) $retention->getAttribute('Base');
                        $rate = $retention->getAttribute('TasaOCuota');
                        if (($rate !== '' && abs((float) $rate - 0.04) < 0.000001)
                            || ($rate === '' && $base > 0 && abs((float) $retention->getAttribute('Importe') - $base * 0.04) <= 0.005)) {
                            return '4';
                        }
                    }
                    if (strcasecmp(trim((string) ($invoice['retencion_transporte'] ?? '')), 'Si') === 0
                        || abs((float) ($invoice['retencion_iva_tasa'] ?? 0) - 0.04) < 0.000001) {
                        return '4';
                    }
                    return null;
                }
                $transfers = $xpath->query('//*[local-name()="Traslado" and @Impuesto="002"]');
                $zeroRate = false;
                $otherRate = false;
                if ($transfers !== false) {
                    foreach ($transfers as $transfer) {
                        if (!$transfer instanceof \DOMElement) continue;
                        $rate = trim($transfer->getAttribute('TasaOCuota'));
                        if ($rate !== '' && ($strict ? abs((float) $rate - 0.16) < 0.000001 : (float) $rate >= 0.159999)) return 'T';
                        if ($strict && $rate !== '' && (float) $rate > 0) $otherRate = true;
                        if ($rate !== '' && abs((float) $rate) < 0.000001) $zeroRate = true;
                    }
                }
                return $otherRate ? null : ($zeroRate ? 'T0' : 'SV');
            }
        }

        if (strcasecmp(trim((string) ($invoice['retencion_transporte'] ?? '')), 'Si') === 0
            || ($strict && abs((float) ($invoice['retencion_iva_tasa'] ?? 0) - 0.04) < 0.000001)) {
            return '4';
        }


        $invoiceId = (int) ($invoice['id'] ?? 0);
        if ($invoiceId > 0) {
            $query = $this->pdo->prepare('SELECT d.iva, c.aplica_iva
                FROM detalle_factura d LEFT JOIN conceptos c ON c.id = d.id_producto
                WHERE d.id_factura = :invoice');
            $query->execute(['invoice' => $invoiceId]);
            $taxableZero = false;
            $exempt = false;
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                $vat = $detail['iva'] ?? null;
                if ($strict && $vat === null) {
                    continue;
                }
                if ($strict ? (float) $vat === 16.0 : (float) $vat >= 15.999) return 'T';
                if ((float) $vat === 0.0 && strcasecmp(trim((string) ($detail['aplica_iva'] ?? '')), 'no') === 0) {
                    $exempt = true;
                }
                if ((float) ($detail['iva'] ?? 0) === 0.0
                    && strcasecmp(trim((string) ($detail['aplica_iva'] ?? '')), 'no') !== 0
                    && trim((string) ($detail['aplica_iva'] ?? '')) !== '') {
                    $taxableZero = true;
                }
            }
            if ($taxableZero) return 'T0';
            if ($strict && $exempt) return 'SV';
        }

        $total = (float) ($invoice['xml_total'] ?? $invoice['total_iva'] ?? 0);
        $subtotal = (float) ($invoice['xml_subtotal'] ?? 0);
        if ($strict) {
            return $subtotal > 0 && abs($total - $subtotal * 1.16) <= 0.02 ? 'T' : null;
        }
        return $subtotal > 0 && $total > $subtotal + 0.005 ? 'T' : 'SV';
    }

    private function receiverRfcForClient(int $clientId, int $receiverId): string
    {
        $query = $this->pdo->prepare('SELECT rfc FROM claves_cortas
            WHERE id = :id AND id_cliente = :client LIMIT 1');
        $query->execute(['id' => $receiverId, 'client' => $clientId]);
        $rfc = $query->fetchColumn();
        if ($rfc === false) {
            throw new RuntimeException('La razón social no pertenece al cliente seleccionado.');
        }
        $rfc = strtoupper(trim((string) $rfc));
        if ($rfc === '') {
            throw new RuntimeException('La clave corta seleccionada no tiene un RFC válido para buscar sus facturas.');
        }
        return $rfc;
    }

    public function deletePending(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id, uuid, id_pago FROM facturas_complementos WHERE id = :id FOR UPDATE');
            $query->execute(['id' => $id]);
            $row = $query->fetch();
            if (!$row) {
                throw new RuntimeException('El complemento solicitado no existe.');
            }
            if (trim((string) ($row['uuid'] ?? '')) !== '') {
                throw new RuntimeException('Un complemento timbrado no puede eliminarse; debe seguir el proceso fiscal de cancelación.');
            }
            $this->pdo->prepare('DELETE FROM facturar_rel_complementos WHERE id_complemento = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM complementos_folio WHERE id_factura = :id')->execute(['id' => $id]);
            $this->pdo->prepare('DELETE FROM facturas_complementos WHERE id = :id')->execute(['id' => $id]);
            $paymentId = (int) ($row['id_pago'] ?? 0);
            if ($paymentId > 0) {
                $replacement = $this->pdo->prepare("SELECT id FROM facturas_complementos
                    WHERE id_pago = :payment AND COALESCE(status, '') <> 'Cancelado'
                    ORDER BY id DESC LIMIT 1");
                $replacement->execute(['payment' => $paymentId]);
                $replacementId = (int) ($replacement->fetchColumn() ?: 0);
                $this->pdo->prepare('UPDATE pagos SET id_factura_res = :complement WHERE id = :payment')
                    ->execute([
                        'complement' => $replacementId > 0 ? (string) $replacementId : null,
                        'payment' => $paymentId,
                    ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function assertReference(string $table, int $id, string $label): void
    {
        $allowed = ['clientes', 'empresas', 'formas_pago'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Referencia no permitida.');
        }
        $query = $this->pdo->prepare("SELECT id FROM {$table} WHERE id = :id");
        $query->execute(['id' => $id]);
        if (!$query->fetchColumn()) {
            throw new RuntimeException('Selecciona un valor válido para ' . $label . '.');
        }
    }

    private function manualLookupToken(array $invoice, int $companyId): string
    {
        $payload = json_encode([
            'invoice' => (int) ($invoice['id'] ?? 0),
            'uuid' => strtoupper(trim((string) ($invoice['uuid'] ?? ''))),
            'company' => $companyId,
            'operator' => \current_operator_id(),
            'expires' => time() + 600,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, \env_value('APP_KEY'));
        return $encoded . '.' . $signature;
    }

    private function validManualLookupToken(string $token, array $invoice, int $companyId): bool
    {
        $parts = explode('.', trim($token), 2);
        if (count($parts) !== 2 || !hash_equals(
            hash_hmac('sha256', $parts[0], \env_value('APP_KEY')),
            $parts[1]
        )) {
            return false;
        }
        $padding = (4 - strlen($parts[0]) % 4) % 4;
        $decoded = base64_decode(strtr($parts[0] . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        try {
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        return is_array($payload)
            && (int) ($payload['invoice'] ?? 0) === (int) ($invoice['id'] ?? 0)
            && hash_equals((string) ($payload['uuid'] ?? ''), strtoupper(trim((string) ($invoice['uuid'] ?? ''))))
            && (int) ($payload['company'] ?? 0) === $companyId
            && (int) ($payload['company'] ?? 0) === (int) ($invoice['razon'] ?? $invoice['company_id'] ?? 0)
            && (int) ($payload['operator'] ?? 0) === \current_operator_id()
            && (int) ($payload['expires'] ?? 0) >= time();
    }

    private static function positiveIntegerReference(mixed $value): int
    {
        $value = trim((string) $value);
        return preg_match('/^[0-9]+$/D', $value) && (int) $value > 0 ? (int) $value : 0;
    }

    private static function numericSearchValue(string $value): ?float
    {
        $normalized = str_replace([',', '$', ' '], '', trim($value));
        return $normalized !== '' && is_numeric($normalized) ? (float) $normalized : null;
    }
}
