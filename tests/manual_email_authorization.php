<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ManualEmailService;
use App\Movements;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $assertions++;
};

foreach (['administrador', 'administrador_b', ' ADMINISTRADOR '] as $type) {
    $assert(ManualEmailService::isAllowed($type), "el perfil {$type} puede utilizar el cotizador");
    $assert(isset(Movements::allowedFor($type)['correos-manuales']), "el perfil {$type} puede ver el módulo");
}

foreach (['capturista', 'supercapturista', 'cliente', 'archivo', 'esclavo', ''] as $type) {
    $assert(!ManualEmailService::isAllowed($type), "el perfil {$type} no puede utilizar el cotizador");
    $assert(!isset(Movements::allowedFor($type)['correos-manuales']), "el perfil {$type} no puede ver el módulo");
}

echo "manual_email_authorization: {$assertions} comprobaciones superadas.\n";
