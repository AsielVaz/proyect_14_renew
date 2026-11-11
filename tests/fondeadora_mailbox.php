<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\FondeadoraMailboxService;
use Webklex\PHPIMAP\ClientManager;

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
$assert(class_exists(ClientManager::class), 'Webklex PHP-IMAP está disponible');

$service = new FondeadoraMailboxService(
    db(),
    'imap.example.test',
    993,
    '/imap/ssl/novalidate-cert',
    'INBOX'
);
$configurationMethod = new ReflectionMethod($service, 'webklexConfig');
$configuration = $configurationMethod->invoke($service, 'correo@example.test', 'clave-prueba');
$account = $configuration['accounts']['fondeadora'];
$assert($account['host'] === 'imap.example.test', 'configura el servidor IMAP en Webklex');
$assert($account['port'] === 993, 'configura el puerto IMAP en Webklex');
$assert($account['protocol'] === 'imap', 'usa el protocolo IMAP');
$assert($account['encryption'] === 'ssl', 'traduce la bandera SSL para Webklex');
$assert($account['validate_cert'] === false, 'traduce novalidate-cert para Webklex');
$assert($account['username'] === 'correo@example.test', 'configura el usuario del buzón');
$assert($account['password'] === 'clave-prueba', 'configura la contraseña del buzón');

$serviceSource = file_get_contents(dirname(__DIR__) . '/src/FondeadoraMailboxService.php');
$assert(is_string($serviceSource) && str_contains($serviceSource, 'whereUnseen()'), 'consulta únicamente mensajes no leídos');
$assert(is_string($serviceSource) && str_contains($serviceSource, "setFlag('Seen')"), 'marca como leídos los códigos procesados');
$assert(is_string($serviceSource) && !str_contains($serviceSource, 'imap_open('), 'no depende de la extensión IMAP nativa');

$service = new FondeadoraMailboxService(db());
$rows = $service->latestByCompany();
foreach ($rows as $row) {
    $assert(isset($row['id'], $row['correo_contacto']), 'cada fila identifica empresa y correo');
    $assert(!array_key_exists('contra_contacto', $row), 'la contraseña no sale de la capa de servicio');
}
if ($rows !== []) {
    $singleCompany = $service->latestByCompany((int) $rows[0]['id']);
    $assert(count($singleCompany) === 1, 'puede obtener una empresa para actualizarla de manera independiente');
    $assert((int) $singleCompany[0]['id'] === (int) $rows[0]['id'], 'la actualización individual conserva la empresa solicitada');
}

$snapshot = $service->snapshot();
$assert(count($snapshot['rows']) === count($rows), 'la instantánea contiene los códigos ya guardados');
$assert((int) $snapshot['summary']['companies'] === count($rows), 'la instantánea resume las empresas disponibles');
foreach ($snapshot['rows'] as $row) {
    $assert($row['refresh_status'] === 'pending', 'los datos guardados se distinguen de una actualización terminada');
}

echo "fondeadora_mailbox: {$assertions} comprobaciones superadas; no se abrió ningún buzón ni se modificó la base de datos.\n";
