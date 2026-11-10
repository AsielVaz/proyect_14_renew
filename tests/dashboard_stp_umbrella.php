<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$expected = db()->query('SELECT saldo FROM stp_saldos_umbrella ORDER BY id DESC LIMIT 1')->fetchColumn();
$overview = dashboard_repository()->overview(1);

if (!array_key_exists('stpUmbrellaBalance', $overview)
    || abs((float) $overview['stpUmbrellaBalance'] - (float) $expected) > 0.001) {
    throw new RuntimeException('El inicio no está tomando el último saldo de stp_saldos_umbrella.');
}
if (!array_key_exists('stpUmbrellaUpdatedAt', $overview)) {
    throw new RuntimeException('El inicio no expone la fecha de actualización del saldo STP Umbrella.');
}

echo "dashboard_stp_umbrella: ok\n";
