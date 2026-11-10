<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$repository = movement_repository();
$companies = $repository->companiesForFilter();
if ($companies === []) {
    throw new RuntimeException('El filtro no encontró empresas disponibles.');
}

$excludedCompany = (int) db()->query("SELECT CAST(COALESCE(c.razon, p.empresa) AS UNSIGNED)
    FROM pagos p
    LEFT JOIN captura c ON c.id = p.id_captura
    WHERE p.status = 'asignado'
      AND p.tipo_movimiento <> 'Abono por comisión'
      AND p.banco <> 'STP' AND p.banco <> '99'
      AND CAST(COALESCE(c.razon, p.empresa) AS UNSIGNED) > 0
    ORDER BY p.id DESC LIMIT 1")->fetchColumn();
if ($excludedCompany <= 0) {
    throw new RuntimeException('No hay una empresa con movimientos asignados para comprobar el filtro.');
}

$result = $repository->page(
    'asignados', App\Movements::get('asignados'), '', 1, 25,
    null, [], '', '', '', 'assigned', [$excludedCompany]
);
foreach ($result['rows'] as $row) {
    if ((int) ($row['company_id'] ?? 0) === $excludedCompany) {
        throw new RuntimeException('La empresa desmarcada todavía aparece en movimientos asignados.');
    }
}

echo "movement_company_filter: ok\n";
