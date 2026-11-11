<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\FondeadoraMailboxService;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException('FALLÓ: ' . $message);
    }
    $assertions++;
};

$assert(
    FondeadoraMailboxService::classifySubject('Autentica tu inicio de sesión') === 'correo_ingreso',
    'identifica el correo de inicio de sesión'
);
$assert(
    FondeadoraMailboxService::classifySubject('Valida la creación de una nueva cuenta destino en tu cuenta') === 'correo_alta',
    'identifica el correo de alta de cuenta'
);
$assert(FondeadoraMailboxService::classifySubject('Otro mensaje') === null, 'ignora asuntos ajenos');
$assert(
    FondeadoraMailboxService::extractOtpCode('<h2>123456</h2>', '') === '123456',
    'extrae el código desde HTML'
);
$assert(
    FondeadoraMailboxService::extractOtpCode('', 'Tu código de seguridad es 654321') === '654321',
    'extrae el código desde texto'
);
$assert(
    FondeadoraMailboxService::extractOtpCode('', 'Mensaje sin código') === null,
    'no inventa códigos ausentes'
);

$rows = (new FondeadoraMailboxService(db()))->latestByCompany();
foreach ($rows as $row) {
    $assert(isset($row['id'], $row['correo_contacto']), 'cada fila identifica empresa y correo');
    $assert(!array_key_exists('contra_contacto', $row), 'la contraseña no sale de la capa de servicio');
}

echo "fondeadora_mailbox: {$assertions} comprobaciones superadas; no se abrió IMAP ni se modificó la base de datos.\n";
