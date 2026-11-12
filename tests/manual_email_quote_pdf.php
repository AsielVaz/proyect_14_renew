<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ManualEmailService;

function s14_test_quote_template(string $content): string
{
    return '<html><body>' . $content . '</body></html>';
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException('FALLÓ: ' . $message);
    $assertions++;
};

$service = new ManualEmailService(__DIR__ . '/missing-template.php', pdf_document_service());
$companies = new ReflectionProperty($service, 'companies');
$companies->setValue($service, [
    'test' => [
        'name' => 'Empresa de prueba',
        'email' => 'cotizador@example.test',
        'template' => 's14_test_quote_template',
    ],
]);
$input = [
    'company' => 'test',
    'to' => 'cliente@example.test',
    'bcc' => '',
    'subject' => 'Cotización de prueba',
    'html' => '<html><body>Cotización de prueba</body></html>',
    'message' => 'Gracias por considerar nuestra propuesta.',
    'additional' => 'Condiciones sujetas a confirmación.',
    'include_quote_pdf' => '1',
    'concepts' => json_encode([[
        'description' => 'Servicio de prueba',
        'quantity' => '2',
        'price' => '100.00',
        'tax' => true,
    ]], JSON_THROW_ON_ERROR),
];

$draftMethod = new ReflectionMethod($service, 'draft');
$hashMethod = new ReflectionMethod($service, 'draftHash');
$firstDraft = $draftMethod->invoke($service, $input, []);
$secondDraft = $draftMethod->invoke($service, $input, []);

$assert(count($firstDraft['attachments']) === 1, 'agrega exactamente el PDF generado');
$attachment = $firstDraft['attachments'][0];
$assert($attachment['type'] === 'application/pdf', 'declara el tipo PDF');
$assert(str_starts_with(base64_decode($attachment['content'], true) ?: '', '%PDF-'), 'adjunta bytes de un PDF válido');
$assert(str_starts_with($attachment['name'], 'cot-') && str_ends_with($attachment['name'], '.pdf'), 'genera un nombre seguro de cotización');
$assert($attachment['generated'] === 'quotation', 'identifica el adjunto generado');
$assert(
    $hashMethod->invoke($service, $firstDraft) === $hashMethod->invoke($service, $secondDraft),
    'la regeneración del PDF no invalida la revisión del mismo contenido'
);

$withoutPdf = $input;
unset($withoutPdf['include_quote_pdf']);
$plainDraft = $draftMethod->invoke($service, $withoutPdf, []);
$assert($plainDraft['attachments'] === [], 'no genera el PDF cuando la casilla no está seleccionada');

$withoutConcepts = $input;
$withoutConcepts['concepts'] = '[]';
try {
    $draftMethod->invoke($service, $withoutConcepts, []);
    $assert(false, 'debe rechazar un PDF sin conceptos');
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $assert(str_contains($exception->getMessage(), 'al menos un concepto'), 'explica que el PDF necesita conceptos');
}

$view = file_get_contents(dirname(__DIR__) . '/views/manual-email.php');
$assert(is_string($view) && str_contains($view, 'name="include_quote_pdf"'), 'el formulario incluye la casilla de PDF');

echo "manual_email_quote_pdf: {$assertions} comprobaciones superadas.\n";
