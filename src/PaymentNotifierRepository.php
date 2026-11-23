<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class PaymentNotifierRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{phone:string,clients:list<array{id:int,name:string,email:string}>,clientCount:int,names:string,emails:string,representativeId:int,representativeEmail:string}>
     */
    public function recipientGroups(): array
    {
        $rows = $this->pdo->query("SELECT id, nombre, telefono, correo
            FROM clientes
            WHERE activo = 1
              AND telefono IS NOT NULL
              AND TRIM(telefono) <> ''
              AND TRIM(telefono) <> '0'
            ORDER BY nombre ASC, id ASC")->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $phone = self::normalizePhone((string) ($row['telefono'] ?? ''));
            if ($phone === '') {
                continue;
            }
            $groups[$phone] ??= [
                'phone' => $phone,
                'clients' => [],
                'clientCount' => 0,
                'names' => '',
                'emails' => '',
                'representativeId' => 0,
                'representativeEmail' => '',
            ];
            $groups[$phone]['clients'][] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['nombre']),
                'email' => trim((string) ($row['correo'] ?? '')),
            ];
        }

        foreach ($groups as &$group) {
            $group['clientCount'] = count($group['clients']);
            $group['names'] = implode(' · ', array_column($group['clients'], 'name'));
            $emails = array_values(array_unique(array_filter(
                array_column($group['clients'], 'email'),
                static fn (string $email): bool => $email !== ''
            )));
            $group['emails'] = implode(' · ', $emails);
            $group['representativeId'] = (int) ($group['clients'][0]['id'] ?? 0);
            $group['representativeEmail'] = (string) ($group['clients'][0]['email'] ?? '');
            if ($group['representativeEmail'] === '' && $emails !== []) {
                $group['representativeEmail'] = $emails[0];
            }
        }
        unset($group);

        uasort($groups, static fn (array $left, array $right): int =>
            strnatcasecmp($left['names'], $right['names']) ?: strcmp($left['phone'], $right['phone'])
        );
        return array_values($groups);
    }

    /**
     * @param array<int, mixed> $selectedPhones
     * @return array{records:int,clients:int}
     */
    public function queue(string $subject, string $message, array $selectedPhones): array
    {
        $subject = trim($subject);
        $message = trim($message);
        if ($subject === '') {
            throw new RuntimeException('Escribe el asunto del mensaje.');
        }
        if (mb_strlen($subject, 'UTF-8') > 256) {
            throw new RuntimeException('El asunto no puede exceder 256 caracteres.');
        }
        if ($message === '') {
            throw new RuntimeException('Escribe el mensaje que se notificará.');
        }
        if (mb_strlen($message, 'UTF-8') > 20000) {
            throw new RuntimeException('El mensaje no puede exceder 20,000 caracteres.');
        }

        $requested = [];
        foreach ($selectedPhones as $selectedPhone) {
            $phone = self::normalizePhone((string) $selectedPhone);
            if ($phone !== '') {
                $requested[$phone] = true;
            }
        }
        if ($requested === []) {
            throw new RuntimeException('Selecciona al menos un cliente para notificar.');
        }
        if (count($requested) > 500) {
            throw new RuntimeException('No se pueden preparar más de 500 teléfonos en una sola operación.');
        }

        $available = [];
        foreach ($this->recipientGroups() as $group) {
            $available[$group['phone']] = $group;
        }
        $selected = [];
        foreach (array_keys($requested) as $phone) {
            if (!isset($available[$phone])) {
                throw new RuntimeException('Uno de los teléfonos seleccionados ya no pertenece a un cliente activo.');
            }
            $selected[] = $available[$phone];
        }

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO mensajesBanca
                (asunto, mensaje, telefono, correo, notificado, log, fecha_inserta, fecha_notifica, id_cliente)
                VALUES (:subject, :message, :phone, :email, 0, NULL, NOW(), NULL, :client)');
            $clientCount = 0;
            foreach ($selected as $group) {
                $insert->execute([
                    'subject' => $subject,
                    'message' => $message,
                    'phone' => $group['phone'],
                    'email' => mb_substr((string) $group['representativeEmail'], 0, 100, 'UTF-8'),
                    'client' => (int) $group['representativeId'],
                ]);
                $clientCount += (int) $group['clientCount'];
            }
            $this->pdo->commit();
            return ['records' => count($selected), 'clients' => $clientCount];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        if (strlen($digits) < 7 || preg_match('/^0+$/D', $digits)) {
            return '';
        }
        return $digits;
    }
}
