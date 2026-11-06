<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class AutomaticInvoiceService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createForCapturedMovement(
        int $paymentId,
        int $captureId,
        int $companyId,
        int $shortKeyId,
        float $amount,
        string $movementType,
        int $operatorId,
    ): ?int {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('La facturación automática requiere una transacción activa.');
        }
        if (strcasecmp(trim($movementType), 'Deposito') !== 0) {
            return null;
        }
        $amount = round(abs($amount), 2);
        if ($paymentId <= 0 || $captureId <= 0 || $companyId <= 0 || $shortKeyId <= 0 || $operatorId <= 0 || $amount <= 0) {
            return null;
        }

        $keyQuery = $this->pdo->prepare('SELECT id, id_cliente, facturacion_automatica
            FROM claves_cortas WHERE id = :id FOR UPDATE');
        $keyQuery->execute(['id' => $shortKeyId]);
        $key = $keyQuery->fetch();
        if (!$key || !$this->enabled($key['facturacion_automatica'] ?? null)) {
            return null;
        }
        $clientId = (int) ($key['id_cliente'] ?? 0);
        if ($clientId <= 0) {
            throw new RuntimeException('La clave corta automática no tiene una cartera válida asociada.');
        }

        $paymentQuery = $this->pdo->prepare('SELECT id, id_factura, id_factura_res
            FROM pagos WHERE id = :id FOR UPDATE');
        $paymentQuery->execute(['id' => $paymentId]);
        $payment = $paymentQuery->fetch();
        if (!$payment) {
            throw new RuntimeException('El movimiento para la factura automática ya no existe.');
        }
        $linkedInvoice = (int) ($payment['id_factura'] ?? 0);
        if ($linkedInvoice > 0) {
            return $linkedInvoice;
        }
        if ($this->positiveInteger($payment['id_factura_res'] ?? null) > 0) {
            throw new RuntimeException('El movimiento ya está relacionado con un complemento y no puede facturarse automáticamente.');
        }

        $conceptQuery = $this->pdo->prepare("SELECT id, concepto, precio_min, precio_max,
                unidades_max, aplica_iva, impuesto
            FROM conceptos
            WHERE CAST(razon AS UNSIGNED) = :company
              AND LOWER(TRIM(CAST(facturable_auto AS CHAR))) IN ('si', 'sí', '1', 'true', 'yes')
              AND precio_min > 0 AND precio_max > 0 AND unidades_max > 0");
        $conceptQuery->execute(['company' => $companyId]);
        $concepts = $conceptQuery->fetchAll();
        if ($concepts === []) {
            throw new RuntimeException('La empresa no tiene conceptos habilitados para facturación automática.');
        }

        $details = $this->buildDetails($amount, $concepts);
        $subtotal = round(array_sum(array_column($details, 'net')), 6);
        $token = bin2hex(random_bytes(20));
        $series = chr(random_int(65, 90)) . chr(random_int(65, 90));
        $folio = random_int(100000, 900000);

        $invoiceInsert = $this->pdo->prepare('INSERT INTO facturas
            (nombre, metodo_pago, moneda, forma_pago, status_pago, razon,
             total_sin_iva, total_iva, token, tipo_comprobante, uso_cfdi,
             id_clave_corta, id_captura, id_pago, folio, usuario_crea, serie,
             xml_total, xml_subtotal, factura_manual, status)
            VALUES
            (:client, 1, 1, 3, "Pagado", :company,
             :subtotal, :total, :token, "I", 3,
             :short_key, :capture, :payment, :folio, :operator, :series,
             :xml_total, :xml_subtotal, 0, "Pendiente")');
        $invoiceInsert->execute([
            'client' => $clientId,
            'company' => $companyId,
            'subtotal' => $subtotal,
            'total' => $amount,
            'token' => $token,
            'short_key' => $shortKeyId,
            'capture' => $captureId,
            'payment' => $paymentId,
            'folio' => $folio,
            'operator' => $operatorId,
            'series' => $series,
            'xml_total' => $amount,
            'xml_subtotal' => $subtotal,
        ]);
        $invoiceId = (int) $this->pdo->lastInsertId();

        $detailInsert = $this->pdo->prepare('INSERT INTO detalle_factura
            (id_factura, id_producto, cantidad, precio_unitario, precio_con_iva,
             precio_neto, precio_neto_iva, concepto, iva)
            VALUES
            (:invoice, :product, :quantity, :unit_net, :unit_gross,
             :net, :gross, :description, :tax)');
        foreach ($details as $detail) {
            $detailInsert->execute([
                'invoice' => $invoiceId,
                'product' => $detail['product'],
                'quantity' => $detail['quantity'],
                'unit_net' => $detail['unitNet'],
                'unit_gross' => $detail['unitGross'],
                'net' => $detail['net'],
                'gross' => $detail['gross'],
                'description' => $detail['description'],
                'tax' => $detail['tax'],
            ]);
        }

        $this->pdo->prepare("UPDATE pagos
            SET id_factura = :invoice, factura = 'Facturado'
            WHERE id = :payment AND COALESCE(id_factura, 0) = 0")
            ->execute(['invoice' => $invoiceId, 'payment' => $paymentId]);

        return $invoiceId;
    }

    private function buildDetails(float $amount, array $concepts): array
    {
        shuffle($concepts);
        $planned = [];
        $accumulated = 0.0;
        foreach ($concepts as $concept) {
            $remaining = round($amount - $accumulated, 6);
            if ($remaining <= 0.000001) {
                break;
            }
            $minimum = max(0.000001, (float) $concept['precio_min']);
            $maximum = max($minimum, (float) $concept['precio_max']);
            $unitLimit = max(1, (int) $concept['unidades_max']);
            $maximumQuantity = (int) floor($remaining / $minimum);
            $quantity = min($maximumQuantity, $unitLimit);

            if ($maximum > $remaining) {
                $planned[] = ['concept' => $concept, 'quantity' => 1, 'gross' => $remaining];
                $accumulated += $remaining;
                break;
            }
            if ($quantity > 0) {
                $gross = round($quantity * $minimum, 6);
                $planned[] = ['concept' => $concept, 'quantity' => $quantity, 'gross' => $gross];
                $accumulated += $gross;
            }
        }
        if ($planned === []) {
            throw new RuntimeException('No fue posible construir partidas para la factura automática.');
        }
        if ($accumulated < $amount) {
            $last = array_key_last($planned);
            $planned[$last]['gross'] = round((float) $planned[$last]['gross'] + ($amount - $accumulated), 6);
        }

        $details = [];
        $grossAccumulated = 0.0;
        $lastIndex = array_key_last($planned);
        foreach ($planned as $index => $item) {
            $concept = $item['concept'];
            $quantity = max(1, (int) $item['quantity']);
            $gross = $index === $lastIndex
                ? round($amount - $grossAccumulated, 6)
                : round((float) $item['gross'], 6);
            $grossAccumulated += $gross;
            $tax = $this->enabled($concept['aplica_iva'] ?? null)
                ? max(0, min(100, (int) ($concept['impuesto'] ?? 16)))
                : 0;
            $net = round($tax > 0 ? $gross / (1 + ($tax / 100)) : $gross, 6);
            $details[] = [
                'product' => (int) $concept['id'],
                'quantity' => $quantity,
                'unitNet' => round($net / $quantity, 6),
                'unitGross' => round($gross / $quantity, 6),
                'net' => $net,
                'gross' => $gross,
                'description' => trim((string) $concept['concepto']),
                'tax' => $tax,
            ];
        }
        return $details;
    }

    private function enabled(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['si', 'sí', '1', 'true', 'yes'], true);
    }

    private function positiveInteger(mixed $value): int
    {
        $value = trim((string) $value);
        return preg_match('/^[1-9]\d*$/', $value) === 1 ? (int) $value : 0;
    }
}
