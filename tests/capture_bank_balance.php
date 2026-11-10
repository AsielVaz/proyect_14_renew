<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/CaptureAnalysisService.php';

use App\CaptureAnalysisService;

$movements = [
    ['deposit' => 100.0, 'withdrawal' => 0.0],
    ['deposit' => 0.0, 'withdrawal' => 25.0],
    ['deposit' => 100.0, 'withdrawal' => 0.0],
];
$summary = CaptureAnalysisService::projectBankBalance(1000.0, $movements);
if ($summary['before'] !== 1000.0 || $summary['after'] !== 1175.0
    || $summary['change'] !== 175.0 || $summary['movement_count'] !== 3) {
    throw new RuntimeException('No se proyectó correctamente el saldo incluyendo movimientos idénticos.');
}

echo "capture_bank_balance: ok\n";
