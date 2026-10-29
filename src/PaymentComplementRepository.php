<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class PaymentComplementRepository
{
    private const ALLOWED_TYPES = ['T', 'T0', 'SV', '4'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function paginate(string $search, int $page, int $perPage = 30): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $limit = $perPage + 1;
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE CAST(fc.id AS CHAR) LIKE :search OR fc.uuid LIKE :search
                OR c.nombre LIKE :search OR e.razon LIKE :search OR cc.rfc LIKE :search
                OR CAST(fc.folio AS CHAR) LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $query = $this->pdo->prepare("SELECT fc.id, fc.serie, fc.folio, fc.total_factura,
            fc.uuid, fc.fecha_timbrado, fc.fecha_pago, fc.status, fc.status_error,
            fc.cadena AS tipo_complemento, c.nombre AS cliente, e.razon AS empresa,
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

    public function formData(): array
    {
        return [
            'companies' => $this->pdo->query('SELECT id, razon, rfc FROM empresas ORDER BY razon')->fetchAll(),
            'clients' => $this->pdo->query('SELECT id, nombre FROM clientes WHERE COALESCE(activo, 1) = 1 ORDER BY nombre')->fetchAll(),
            'forms' => $this->pdo->query('SELECT id, clave, forma_pago FROM formas_pago WHERE clave <> 99 ORDER BY clave')->fetchAll(),
        ];
    }

    public function receivers(int $clientId): array
    {
        $query = $this->pdo->prepare('SELECT id, clave_corta, razon_social, rfc, cp, regimen_fiscal
            FROM claves_cortas WHERE id_cliente = :client ORDER BY razon_social');
        $query->execute(['client' => $clientId]);
        return $query->fetchAll();
    }

    public function invoiceByUuid(string $uuid): ?array
    {
        $uuid = strtoupper(trim($uuid));
        if ($uuid === '') {
            return null;
        }
        $query = $this->pdo->prepare('SELECT f.id, f.uuid, f.serie, f.folio, f.total_iva,
            f.nombre AS client_id, f.razon AS company_id, f.id_clave_corta AS receiver_id,
            f.metodo_pago, c.nombre AS cliente, e.razon AS empresa, cc.razon_social AS receptor,
            COALESCE(SUM(rel.monto), 0) AS pagado, COALESCE(MAX(rel.num_pago), 0) + 1 AS siguiente_parcialidad
            FROM facturas f
            INNER JOIN clientes c ON c.id = f.nombre
            INNER JOIN empresas e ON e.id = f.razon
            INNER JOIN claves_cortas cc ON cc.id = f.id_clave_corta
            LEFT JOIN facturar_rel_complementos rel ON rel.id_factura = f.id
            WHERE UPPER(f.uuid) = :uuid
            GROUP BY f.id, f.uuid, f.serie, f.folio, f.total_iva, f.nombre, f.razon,
                f.id_clave_corta, f.metodo_pago, c.nombre, e.razon, cc.razon_social
            ORDER BY f.id DESC LIMIT 1');
        $query->execute(['uuid' => $uuid]);
        $row = $query->fetch();
        if (!$row) {
            return null;
        }
        $row['saldo'] = max(0, round((float) $row['total_iva'] - (float) $row['pagado'], 2));
        return $row;
    }

    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT fc.*, c.nombre AS cliente, e.razon AS empresa,
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
        $type = (string) ($input['complement_type'] ?? 'T');
        $uuids = (array) ($input['invoice_uuid'] ?? []);
        $amounts = (array) ($input['amount'] ?? []);
        $installments = (array) ($input['installment'] ?? []);

        if ($clientId <= 0 || $receiverId <= 0 || $companyId <= 0 || $formId <= 0) {
            throw new RuntimeException('Selecciona cliente, razón social, empresa emisora y forma de pago.');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('El tipo de complemento no es válido.');
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
            $receiver = $this->pdo->prepare('SELECT id FROM claves_cortas WHERE id = :id AND id_cliente = :client');
            $receiver->execute(['id' => $receiverId, 'client' => $clientId]);
            if (!$receiver->fetchColumn()) {
                throw new RuntimeException('La razón social no pertenece al cliente seleccionado.');
            }
            if ($paymentId > 0) {
                $payment = $this->pdo->prepare('SELECT id FROM pagos WHERE id = :id FOR UPDATE');
                $payment->execute(['id' => $paymentId]);
                if (!$payment->fetchColumn()) {
                    throw new RuntimeException('El movimiento de pago indicado no existe.');
                }
            }

            $invoiceQuery = $this->pdo->prepare('SELECT id, uuid, serie, folio, total_iva, nombre,
                razon, id_clave_corta, metodo_pago FROM facturas WHERE UPPER(uuid) = :uuid
                ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $paidQuery = $this->pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM facturar_rel_complementos WHERE id_factura = :invoice');
            $relations = [];
            $seen = [];
            $total = 0.0;
            foreach ($uuids as $index => $rawUuid) {
                $uuid = strtoupper(trim((string) $rawUuid));
                $amount = round((float) str_replace([',', '$', ' '], '', (string) ($amounts[$index] ?? '0')), 2);
                $installment = (int) ($installments[$index] ?? 0);
                if ($uuid === '' || isset($seen[$uuid])) {
                    throw new RuntimeException('Cada factura debe tener un UUID único y válido.');
                }
                if ($amount <= 0 || $installment <= 0) {
                    throw new RuntimeException('El monto y número de parcialidad deben ser mayores a cero.');
                }
                $invoiceQuery->execute(['uuid' => $uuid]);
                $invoice = $invoiceQuery->fetch();
                if (!$invoice) {
                    throw new RuntimeException('No se encontró la factura con UUID ' . $uuid . '.');
                }
                if ((int) $invoice['metodo_pago'] !== 2) {
                    throw new RuntimeException('La factura ' . $uuid . ' no usa el método PPD.');
                }
                if ((int) $invoice['nombre'] !== $clientId || (int) $invoice['razon'] !== $companyId
                    || (int) $invoice['id_clave_corta'] !== $receiverId) {
                    throw new RuntimeException('La factura ' . $uuid . ' no corresponde al cliente, emisor y razón social seleccionados.');
                }
                $paidQuery->execute(['invoice' => $invoice['id']]);
                $paid = round((float) $paidQuery->fetchColumn(), 2);
                $balance = max(0, round((float) $invoice['total_iva'] - $paid, 2));
                if ($amount > $balance + 0.001) {
                    throw new RuntimeException('El monto para ' . $uuid . ' excede el saldo de $' . number_format($balance, 2) . '.');
                }
                $relations[] = ['invoice' => $invoice, 'amount' => $amount,
                    'installment' => $installment, 'balance' => $balance,
                    'remaining' => round($balance - $amount, 2)];
                $seen[$uuid] = true;
                $total += $amount;
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
            $this->pdo->commit();
            return $complementId;
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
            $query = $this->pdo->prepare('SELECT id, uuid FROM facturas_complementos WHERE id = :id FOR UPDATE');
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
}
