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

    public function page(string $search, int $page, int $perPage = 25): array
    {
        $where = 'WHERE f.razon <> 132';
        $params = [];
        if ($search !== '') {
            $where .= " AND CONCAT_WS(' ', f.id, f.folio, f.serie, f.uuid, c.nombre, e.razon, cc.rfc, cc.razon_social) LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        $joins = ' FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN empresas e ON e.id = f.razon
            INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            LEFT JOIN formas_pago fp ON fp.id = f.forma_pago
            LEFT JOIN metodos_pago mp ON mp.id = f.metodo_pago
            LEFT JOIN usuarios u ON u.id = f.usuario_crea ';
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $joins . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $query = $this->pdo->prepare('SELECT f.id, f.fecha, f.serie, f.folio, f.total_iva, f.uuid,
            f.status, f.status_error, f.status_pago, f.id_pago, c.nombre AS cliente,
            e.razon AS empresa, cc.razon_social AS receptor, cc.rfc,
            fp.forma_pago, mp.metodo_pago, COALESCE(u.nombre, "AUTO") AS usuario' .
            $joins . $where . " ORDER BY f.id DESC LIMIT {$perPage} OFFSET {$offset}");
        $query->execute($params);
        return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
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

    public function receivers(int $clientId): array
    {
        $query = $this->pdo->prepare('SELECT id, clave_corta, razon_social, rfc, cp, regimen_fiscal FROM claves_cortas WHERE id_cliente = :client ORDER BY razon_social');
        $query->execute(['client' => $clientId]);
        return $query->fetchAll();
    }

    public function concepts(int $companyId): array
    {
        $query = $this->pdo->prepare('SELECT id, concepto, clave_sat, clave_unidad_medida, precio_min, precio_max, unidades_max, aplica_iva, impuesto FROM conceptos WHERE razon = :company ORDER BY concepto');
        $query->execute(['company' => $companyId]);
        return $query->fetchAll();
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
            $minimumPrice = (float) ($concept['precio_min'] ?? 0);
            $maximumPrice = (float) ($concept['precio_max'] ?? 0);
            if ($minimumPrice > 0 && $grossUnit < $minimumPrice) {
                throw new RuntimeException('El precio de ' . $concept['concepto'] . ' es menor al mínimo permitido.');
            }
            if ($maximumPrice > 0 && $grossUnit > $maximumPrice) {
                throw new RuntimeException('El precio de ' . $concept['concepto'] . ' excede el máximo permitido.');
            }
            if ((float) $concept['unidades_max'] > 0 && $quantity > (float) $concept['unidades_max']) {
                throw new RuntimeException('La cantidad excede el máximo del concepto ' . $concept['concepto'] . '.');
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
            $details[] = compact('concept', 'quantity', 'grossUnit', 'tax', 'netUnit', 'net', 'gross', 'description');
        }

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
                'total' => round($total, 2), 'token' => $token, 'voucher' => $voucherType,
                'use_cfdi' => $useId, 'receiver' => $receiverId, 'folio' => $folio,
                'operator' => $operatorId, 'series' => $series, 'retention' => $retention,
                'xml_total' => round($total, 2), 'xml_subtotal' => round($subtotal, 2)]);
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
                $payment = $this->pdo->prepare('SELECT id, id_factura FROM pagos WHERE id = :id FOR UPDATE');
                $payment->execute(['id' => $paymentId]);
                $row = $payment->fetch();
                if (!$row) {
                    throw new RuntimeException('El pago asociado no existe.');
                }
                if ((int) ($row['id_factura'] ?? 0) > 0) {
                    throw new RuntimeException('El pago seleccionado ya está relacionado con otra factura.');
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
}
