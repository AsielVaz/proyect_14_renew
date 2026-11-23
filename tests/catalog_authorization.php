<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Catalogs;

foreach (['administrador', ' ADMINISTRADOR '] as $type) {
    if (!Catalogs::isAllowed($type)) {
        throw new RuntimeException('El administrador debe poder acceder a Catálogos.');
    }
}

foreach (['administrador_b', 'operador', '', 'administrador b'] as $type) {
    if (Catalogs::isAllowed($type)) {
        throw new RuntimeException('Solo el tipo exacto administrador debe poder acceder a Catálogos.');
    }
}

echo "6 comprobaciones de autorización de Catálogos superadas.\n";
