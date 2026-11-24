<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CatalogRepository;
use App\Catalogs;

$shortKeys = Catalogs::get('claves');
assert(is_array($shortKeys));
$field = $shortKeys['fields']['facturacion_automatica'] ?? null;
assert(is_array($field));
assert(($field['options'] ?? []) === ['Si' => 'Sí', 'No' => 'No']);
assert(($field['caseInsensitiveOptions'] ?? false) === true);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE prueba (id INTEGER PRIMARY KEY AUTOINCREMENT, facturacion_automatica TEXT NOT NULL)');
$repository = new CatalogRepository($pdo);
$catalog = [
    'table' => 'prueba',
    'fields' => [
        'facturacion_automatica' => [
            'label' => 'Facturación automática',
            'type' => 'select',
            'required' => true,
            'options' => ['Si' => 'Sí', 'No' => 'No'],
            'caseInsensitiveOptions' => true,
        ],
    ],
];

$yesId = $repository->save($catalog, ['facturacion_automatica' => 'si'], null);
$noId = $repository->save($catalog, ['facturacion_automatica' => 'NO'], null);
$query = $pdo->prepare('SELECT facturacion_automatica FROM prueba WHERE id = :id');
$query->execute(['id' => $yesId]);
assert($query->fetchColumn() === 'Si');
$query->execute(['id' => $noId]);
assert($query->fetchColumn() === 'No');

echo "6 comprobaciones de valores Si/No para claves cortas superadas.\n";
