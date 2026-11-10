<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/CaptureAnalysisService.php';

use App\CaptureAnalysisService;

$movements = [
    ['deposit' => 100.0, 'withdrawal' => 0.0, 'duplicate' => false],
    ['deposit' => 0.0, 'withdrawal' => 25.0, 'duplicate' => false],
    ['deposit' => 500.0, 'withdrawal' => 0.0, 'duplicate' => true],
];
$summary = CaptureAnalysisService::projectBankBalance(1000.0, $movements);
if ($summary['before'] !== 1000.0 || $summary['after'] !== 1075.0
    || $summary['change'] !== 75.0 || $summary['movement_count'] !== 2) {
    throw new RuntimeException('No se proyectó correctamente el saldo con depósitos, retiros y duplicados.');
}

echo "capture_bank_balance: ok\n";
