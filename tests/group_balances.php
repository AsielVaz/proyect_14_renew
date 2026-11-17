<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\GroupRepository;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $checks++;
};

$repository = new GroupRepository(db());
$groups = $repository->groups(1);
$expectedGroups = (int) db()->query('SELECT COUNT(*) FROM grupo')->fetchColumn();
$assert(count($groups) === $expectedGroups, 'lista todos los grupos configurados');

$group = null;
foreach ($groups as $candidate) {
    if ((int) $candidate['carteras'] > 0) {
        $group = $candidate;
        break;
    }
}
$assert(is_array($group), 'existe al menos un grupo con carteras activas');

$detail = $repository->group((int) $group['id'], 1);
$assert((int) $detail['id'] === (int) $group['id'], 'abre el grupo solicitado');
$assert(count($detail['wallets']) === (int) $group['carteras'], 'el detalle incluye las mismas carteras del resumen');

$walletIds = array_map(static fn (array $wallet): int => (int) $wallet['id'], $detail['wallets']);
$assert(count($walletIds) === count(array_unique($walletIds)), 'cada cartera aparece una sola vez');
foreach ($detail['wallets'] as $wallet) {
    $clientGroup = (int) db()->query('SELECT grupo FROM clientes WHERE id = ' . (int) $wallet['id'])->fetchColumn();
    $assert($clientGroup === (int) $group['id'], 'cada cartera pertenece al mismo id de grupo');
}

$walletBalance = array_sum(array_map(static fn (array $wallet): float => (float) $wallet['saldo'], $detail['wallets']));
$assert(abs($walletBalance - (float) $detail['saldo']) < 0.01, 'el saldo del grupo es la suma de sus carteras');
$assert(abs((float) $group['saldo'] - (float) $detail['saldo']) < 0.01, 'el listado y el detalle muestran el mismo saldo');

$source = file_get_contents(dirname(__DIR__) . '/src/GroupRepository.php');
$index = file_get_contents(dirname(__DIR__) . '/index.php');
$assert(is_string($source) && str_contains($source, 'c.grupo = g.id'), 'relaciona clientes.grupo con grupo.id');
$assert(is_string($source) && str_contains($source, 'GROUP BY CAST(nombre AS UNSIGNED)'), 'agrupa pagos por cartera antes de sumarlos');
$assert(is_string($index) && str_contains($index, '?groups=list'), 'el módulo está disponible en la navegación');

echo "group_balances: {$checks} comprobaciones superadas.\n";
