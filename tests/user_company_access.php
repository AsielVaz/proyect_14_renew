<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CatalogRepository;

$pdo = db();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $pdo->exec('CREATE TEMPORARY TABLE usuarios (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NULL
    )');
    $pdo->exec('CREATE TEMPORARY TABLE empresas (
        id INT NOT NULL PRIMARY KEY,
        razon VARCHAR(150) NULL,
        rfc VARCHAR(50) NOT NULL DEFAULT ""
    )');
    $pdo->exec('CREATE TEMPORARY TABLE cliente_empresa (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        id_empresa INT NOT NULL,
        usuario_crea INT NULL,
        fecha_crea DATETIME NULL,
        UNIQUE KEY uq_usuario_empresa (id_usuario, id_empresa)
    )');
    $pdo->exec("INSERT INTO empresas (id, razon, rfc) VALUES
        (1, 'Empresa Uno', 'EUNO010101AA1'),
        (2, 'Empresa Dos', 'EDOS010101AA2'),
        (3, 'Empresa Tres', 'ETRE010101AA3')");

    $catalog = [
        'table' => 'usuarios',
        'fields' => ['nombre' => ['type' => 'text', 'label' => 'Nombre', 'required' => true]],
    ];
    $repository = new CatalogRepository($pdo);
    $userId = $repository->saveUserWithCompanies($catalog, ['nombre' => 'Usuario'], null, [], ['1', '2'], 99);

    $relations = $pdo->query("SELECT id_empresa, usuario_crea FROM cliente_empresa WHERE id_usuario = {$userId} ORDER BY id_empresa")->fetchAll();
    $assert(array_column($relations, 'id_empresa') === [1, 2], 'Debe guardar todas las empresas seleccionadas.');
    $assert(array_unique(array_column($relations, 'usuario_crea')) === [99], 'Debe registrar al operador que creó la relación.');

    $companyTwoRelation = (int) $pdo->query(
        "SELECT id FROM cliente_empresa WHERE id_usuario = {$userId} AND id_empresa = 2"
    )->fetchColumn();
    $repository->saveUserWithCompanies($catalog, ['nombre' => 'Usuario actualizado'], $userId, [], ['2', '3'], 100);
    $relations = $pdo->query("SELECT id, id_empresa FROM cliente_empresa WHERE id_usuario = {$userId} ORDER BY id_empresa")->fetchAll();
    $assert(array_column($relations, 'id_empresa') === [2, 3], 'Debe agregar y retirar relaciones según la nueva selección.');
    $assert((int) $relations[0]['id'] === $companyTwoRelation, 'Debe conservar las relaciones que continúan seleccionadas.');

    $options = $repository->userCompanyOptions($userId);
    $selected = array_column(array_filter($options, static fn (array $row): bool => $row['selected'] === 1), 'id');
    $assert($selected === [2, 3], 'La edición debe recuperar las empresas ya relacionadas.');

    try {
        $repository->saveUserWithCompanies($catalog, ['nombre' => 'Cambio inválido'], $userId, [], ['999'], 100);
        throw new RuntimeException('Una empresa inexistente debió rechazarse.');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'ya no existen'), 'Debe explicar la selección inválida.');
    }
    $name = $pdo->query("SELECT nombre FROM usuarios WHERE id = {$userId}")->fetchColumn();
    $assert($name === 'Usuario actualizado', 'Un error de empresas debe revertir también la edición del usuario.');

    $view = file_get_contents(dirname(__DIR__) . '/index.php');
    $script = file_get_contents(dirname(__DIR__) . '/assets/user-editor.js');
    $assert(is_string($view) && str_contains($view, 'Características de facturación')
        && str_contains($view, 'name="company_ids[]"'), 'La edición debe mostrar la pestaña y sus casillas de empresas.');
    $assert(is_string($script) && str_contains($script, "[data-user-tab]")
        && str_contains($script, "[data-user-company-search]"), 'La interfaz debe controlar pestañas y búsqueda de empresas.');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS cliente_empresa');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS empresas');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS usuarios');
}

echo "user_company_access: ok\n";
