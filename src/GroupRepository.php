<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class GroupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function groups(int $operatorId): array
    {
        $visibility = DashboardRepository::ACTIVE_WALLET_SCOPE;
        $query = $this->pdo->prepare("SELECT g.id, g.nombre, g.color, g.cliente_padre,
                g.cuenta_salida, padre.nombre AS cliente_padre_nombre,
                COUNT(c.id) AS carteras,
                COALESCE(SUM(saldos.saldo), 0) AS saldo,
                GROUP_CONCAT(c.nombre ORDER BY c.nombre SEPARATOR '||') AS integrantes
            FROM grupo g
            LEFT JOIN clientes padre ON padre.id = g.cliente_padre
            LEFT JOIN clientes c ON c.grupo = g.id
                AND {$visibility}
            LEFT JOIN (
                SELECT CAST(nombre AS UNSIGNED) AS cliente_id,
                    SUM(COALESCE(saldo_acreditable, 0)) AS saldo
                FROM pagos
                WHERE CAST(nombre AS UNSIGNED) > 0
                GROUP BY CAST(nombre AS UNSIGNED)
            ) saldos ON saldos.cliente_id = c.id
            GROUP BY g.id, g.nombre, g.color, g.cliente_padre, g.cuenta_salida, padre.nombre
            ORDER BY g.nombre");
        $query->execute(['operator_one' => $operatorId, 'operator_restricted' => $operatorId]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row = $this->normalizeGroup($row);
            $row['integrantes'] = trim((string) ($row['integrantes'] ?? '')) === ''
                ? []
                : explode('||', (string) $row['integrantes']);
        }
        unset($row);
        return $rows;
    }

    public function group(int $groupId, int $operatorId): array
    {
        if ($groupId <= 0) {
            throw new RuntimeException('El grupo solicitado no es válido.');
        }

        $groupStatement = $this->pdo->prepare("SELECT g.id, g.nombre, g.color, g.cliente_padre,
                g.cuenta_salida, padre.nombre AS cliente_padre_nombre
            FROM grupo g
            LEFT JOIN clientes padre ON padre.id = g.cliente_padre
            WHERE g.id = :id
            LIMIT 1");
        $groupStatement->execute(['id' => $groupId]);
        $group = $groupStatement->fetch();
        if (!$group) {
            throw new RuntimeException('El grupo solicitado no existe.');
        }

        $visibility = DashboardRepository::ACTIVE_WALLET_SCOPE;
        $walletStatement = $this->pdo->prepare("SELECT c.id, c.nombre, c.clave_corta, c.color, c.texto,
                c.imagen, c.lidera,
                COALESCE(saldos.saldo, 0) AS saldo,
                COALESCE(script.movimientos_pend, 0) AS movimientos_pend,
                COALESCE(script.dinero_sin_fact, 0) AS dinero_sin_fact,
                COALESCE(script.cheques_pend, 0) AS cheques_pend,
                COALESCE(script.cheques_pend_dinero, 0) AS cheques_pend_dinero,
                COALESCE(script.facturas_sin_pago, 0) AS facturas_sin_pago,
                script.ultima_act
            FROM clientes c
            LEFT JOIN (
                SELECT CAST(nombre AS UNSIGNED) AS cliente_id,
                    SUM(COALESCE(saldo_acreditable, 0)) AS saldo
                FROM pagos
                WHERE CAST(nombre AS UNSIGNED) > 0
                GROUP BY CAST(nombre AS UNSIGNED)
            ) saldos ON saldos.cliente_id = c.id
            LEFT JOIN (
                SELECT balances.*
                FROM clientes_saldo_script balances
                INNER JOIN (
                    SELECT id_cliente, MAX(id) AS latest_id
                    FROM clientes_saldo_script
                    GROUP BY id_cliente
                ) latest ON latest.latest_id = balances.id
            ) script ON script.id_cliente = c.id
            WHERE c.grupo = :group_id
                AND {$visibility}
            ORDER BY c.lidera DESC, c.nombre");
        $walletStatement->execute([
            'group_id' => $groupId,
            'operator_one' => $operatorId,
            'operator_restricted' => $operatorId,
        ]);
        $wallets = $walletStatement->fetchAll();

        $group = $this->normalizeGroup($group);
        $group['wallets'] = $wallets;
        $group['carteras'] = count($wallets);
        $group['saldo'] = array_sum(array_map(static fn (array $wallet): float => (float) $wallet['saldo'], $wallets));
        $group['movimientos_pend'] = array_sum(array_map(static fn (array $wallet): int => (int) $wallet['movimientos_pend'], $wallets));
        $group['dinero_sin_fact'] = array_sum(array_map(static fn (array $wallet): float => (float) $wallet['dinero_sin_fact'], $wallets));
        return $group;
    }

    private function normalizeGroup(array $group): array
    {
        $group['id'] = (int) $group['id'];
        $group['carteras'] = (int) ($group['carteras'] ?? 0);
        $group['saldo'] = (float) ($group['saldo'] ?? 0);
        $group['color'] = $this->color((string) ($group['color'] ?? ''));
        $group['cuenta_salida_nombre'] = match ((int) ($group['cuenta_salida'] ?? 0)) {
            1 => 'DELMENT',
            2 => 'COMERCIO WK',
            3 => 'ENCLENSI',
            default => 'Sin configurar',
        };
        return $group;
    }

    private function color(string $color): string
    {
        $color = ltrim(trim($color), '#');
        return preg_match('/^[0-9a-fA-F]{6}$/', $color) ? '#' . strtolower($color) : '#ff6a00';
    }
}
