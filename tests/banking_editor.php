<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\BankingEditorService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$assert(BankingEditorService::isAllowed('administrador'), 'El administrador debe acceder al editor.');
$assert(BankingEditorService::isAllowed(' ADMINISTRADOR_B '), 'El administrador B debe acceder al editor.');
$assert(!BankingEditorService::isAllowed('usuario'), 'Un usuario ordinario no debe editar el banking.');

$valid = BankingEditorService::validateProviders([
    ['id_proveedor' => 10, 'porcentaje_sugerido' => 35.5],
    ['id_proveedor' => 11, 'porcentaje_sugerido' => 64.5],
]);
$assert(count($valid) === 2, 'La distribución válida debe conservar sus dos proveedores.');
$assert(abs(array_sum(array_column($valid, 'porcentaje_sugerido')) - 100.0) < 0.0001, 'La distribución válida debe sumar 100%.');

foreach ([
    [['id_proveedor' => 10, 'porcentaje_sugerido' => 99]],
    [
        ['id_proveedor' => 10, 'porcentaje_sugerido' => 50],
        ['id_proveedor' => 10, 'porcentaje_sugerido' => 50],
    ],
] as $invalidProviders) {
    try {
        BankingEditorService::validateProviders($invalidProviders);
        throw new RuntimeException('La distribución inválida fue aceptada.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() !== 'La distribución inválida fue aceptada.', 'La distribución inválida debe rechazarse.');
    }
}

$data = banking_editor_service()->data();
$assert((int) $data['id_cliente'] === BankingEditorService::CLIENT_ID, 'El editor debe estar limitado al cliente 13.');
$assert(is_array($data['empresas_catalogo']), 'El catálogo de razones sociales debe estar disponible.');
$assert(is_array($data['proveedores_catalogo']), 'El catálogo de proveedores debe estar disponible.');
$assert(is_array($data['configuraciones']), 'Las configuraciones deben estar disponibles.');

$rows = banking_editor_service()->exportRows();
$assert(is_array($rows), 'La exportación debe producir una colección de filas.');
if ($rows !== []) {
    $assert(array_keys($rows[0]) === [
        'empresa_id', 'clave_corta', 'empresa', 'rfc_empresa', 'proveedor_id',
        'proveedor', 'rfc_proveedor', 'porcentaje',
    ], 'La exportación debe conservar todas las columnas útiles.');
}

$pdf = banking_editor_service()->pdf();
$assert(str_starts_with($pdf, '%PDF-'), 'La exportación PDF debe generar un documento válido.');

echo "banking_editor: {$checks} comprobaciones superadas; no se modificó la base de datos.\n";
