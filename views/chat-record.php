<?php
$recordTitles = [
    'factura' => 'Factura', 'complemento' => 'Complemento de pago',
    'movimiento' => 'Movimiento contable', 'banco' => 'Línea bancaria', 'stp' => 'Orden STP', 'traspaso' => 'Traspaso',
];
$sectionTitles = [
    'conceptos' => 'Conceptos', 'facturas' => 'Facturas relacionadas', 'complementos' => 'Complementos relacionados',
    'movimientos' => 'Movimientos relacionados', 'lineas_bancarias' => 'Líneas bancarias', 'ordenes_stp' => 'Órdenes STP',
];
$title = $recordTitles[$kind] ?? 'Consulta de registro';
$displayFields = static function (array $row, bool $main = false): void {
    foreach ($row as $field => $value) {
        if ($field === 'enlace' && $main) {
            continue;
        }
        if ($value === null && !($field === 'saldo_ppd_registrado' && !empty($row['es_ppd']))) {
            continue;
        }
        $label = match ($field) {
            'id' => 'ID', 'uuid' => 'UUID', 'rfc' => 'RFC', 'pdf' => 'PDF', 'xml' => 'XML',
            'enlace' => 'Registro', 'es_ppd' => 'Pago diferido (PPD)', 'saldo_ppd_registrado' => 'Saldo PPD registrado',
            'origen_id' => 'ID del movimiento de origen', 'destino_id' => 'ID del movimiento de destino',
            'pago_id' => 'ID del pago', 'linea_bancaria_id' => 'ID de línea bancaria',
            'estado_codigo' => 'Código de estado STP', 'posible_incidencia' => 'Posible incidencia',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
        if (in_array($field, ['es_ppd', 'cancelado', 'sin_documento', 'liquidada', 'comprobante_enviado', 'posible_incidencia'], true)) {
            $value = $value ? 'Sí' : 'No';
        }
        ?>
        <dt><?= e($label) ?></dt>
        <?php if (in_array($field, ['enlace', 'pdf', 'xml'], true)): ?>
            <dd><a href="<?= e($value) ?>"><?= e($field === 'enlace' ? 'Ver detalle' : 'Abrir ' . $label) ?></a></dd>
        <?php else: ?>
            <dd><?= e($value ?? 'No disponible para tu alcance') ?></dd>
        <?php endif;
    }
};
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Sistema 14</title>
    <link rel="stylesheet" href="assets/theme.css">
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/chat.css">
</head>
<body>
    <main class="s14-chat-record">
        <a href="./">Volver al sistema</a>
        <h1><?= e($title) ?><?= isset($result['filas'][0]['id']) ? ' #' . e($result['filas'][0]['id']) : '' ?></h1>
        <?php if ($result === null): ?>
            <p><?= http_response_code() === 500 ? 'No se pudo cargar el registro.' : e(App\ChatQueries::NOT_FOUND) ?></p>
        <?php else: ?>
            <section class="panel" aria-label="Datos del registro">
                <dl><?php $displayFields($result['filas'][0], true); ?></dl>
            </section>
            <?php foreach ($result['secciones'] ?? [] as $name => $section): ?>
                <section class="panel">
                    <h2><?= e($sectionTitles[$name]) ?></h2>
                    <?php if ($section['filas'] === []): ?><p>No hay registros relacionados accesibles.</p><?php endif; ?>
                    <?php foreach ($section['filas'] as $related): ?>
                        <dl><?php $displayFields($related); ?></dl>
                    <?php endforeach; ?>
                    <?php if ($section['hay_mas']): ?><p>Se muestran los primeros 10 registros accesibles.</p><?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>

