<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Statistics;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $checks++;
};

$definition = Statistics::get('datos-al-dia');
$assert(is_array($definition), 'la estadística está registrada');
$assert(($definition['dateFilter'] ?? true) === false, 'el saldo actual no utiliza un rango de fechas');

$result = statistics_repository()->run('datos-al-dia', []);
$assert(($result['type'] ?? '') === 'chart', 'se genera una gráfica de saldos');
$assert(($result['series'][0]['name'] ?? '') === 'Saldo actual', 'la serie identifica el saldo actual');
$assert(count($result['labels'] ?? []) === count($result['series'][0]['data'] ?? []), 'cada banco tiene un saldo');

$expectedAccounts = (int) db()->query("SELECT COUNT(*) FROM bancos WHERE status_banco = 'AC'")->fetchColumn();
$accountSummary = null;
foreach ($result['summary'] ?? [] as $summary) {
    if (($summary['label'] ?? '') === 'Cuentas activas') {
        $accountSummary = (int) $summary['value'];
    }
}
$assert($accountSummary === $expectedAccounts, 'incluye todas las cuentas bancarias activas');

$source = file_get_contents(dirname(__DIR__) . '/src/StatisticsRepository.php');
$assert(is_string($source) && str_contains($source, 'saldos.cuenta = b.id'), 'une captura_edo_cta.cuenta con bancos.id');
$assert(is_string($source) && str_contains($source, 'GROUP BY cuenta'), 'agrupa movimientos antes del join para evitar duplicaciones');

echo "statistics_bank_balances: {$checks} comprobaciones superadas.\n";
