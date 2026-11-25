<?php

declare(strict_types=1);

namespace App;

use Closure;
use DOMDocument;
use DOMXPath;

final class SatCfdiStatus
{
    public const MAX_BATCH = 10;
    private const URL = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';
    private const SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const SERVICE = 'http://tempuri.org/';
    private const ACUSE = 'http://schemas.datacontract.org/2004/07/Sat.Cfdi.Negocio.ConsultaCfdi.Servicio';
    public const UUID = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}';

    public function __construct(private readonly ?Closure $transport = null) {}

    public function check(array $invoices): array
    {
        $requests = [];
        $results = [];
        foreach ($invoices as $id => $invoice) {
            $expression = self::expression($invoice);
            if ($expression === null) {
                $results[$id] = ['estado_sat' => 'Sin verificar', 'detalle_sat' => 'Faltan datos fiscales válidos para la consulta.'];
                continue;
            }
            $escaped = htmlspecialchars($expression, ENT_XML1, 'UTF-8');
            $requests[$id] = '<?xml version="1.0" encoding="utf-8"?>'
                . '<s:Envelope xmlns:s="' . self::SOAP . '"><s:Body><Consulta xmlns="' . self::SERVICE . '">'
                . '<expresionImpresa>' . $escaped . '</expresionImpresa></Consulta></s:Body></s:Envelope>';
        }
        if ($requests !== []) {
            $responses = $this->transport ? ($this->transport)($requests) : $this->post($requests);
            foreach ($requests as $id => $_) {
                $results[$id] = self::status($responses[$id] ?? '');
            }
        }
        return $results;
    }

    private static function expression(array $invoice): ?string
    {
        $uuid = strtoupper(trim((string) ($invoice['uuid'] ?? '')));
        $issuer = (string) ($invoice['empresa_rfc'] ?? '');
        $receiver = (string) ($invoice['receptor_rfc'] ?? '');
        $total = (string) ($invoice['total_sat'] ?? '');
        $seal = (string) ($invoice['sello_cfd'] ?? '');
        $xml = trim((string) ($invoice['xml_firmado'] ?? ''));
        if ($xml !== '') {
            $doc = self::loadXml($xml);
            $root = $doc?->documentElement;
            if ($root === null || $root->localName !== 'Comprobante'
                || !in_array($root->namespaceURI, ['http://www.sat.gob.mx/cfd/3', 'http://www.sat.gob.mx/cfd/4'], true)) {
                return null;
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('cfdi', $root->namespaceURI);
            $xpath->registerNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
            $stamp = strtoupper((string) $xpath->evaluate('string(/cfdi:Comprobante/cfdi:Complemento/tfd:TimbreFiscalDigital/@UUID)'));
            if ($stamp === '' || ($uuid !== '' && $stamp !== $uuid)) {
                return null;
            }
            $uuid = $stamp;
            $issuer = (string) $xpath->evaluate('string(/cfdi:Comprobante/cfdi:Emisor/@Rfc | /cfdi:Comprobante/cfdi:Emisor/@rfc)');
            $receiver = (string) $xpath->evaluate('string(/cfdi:Comprobante/cfdi:Receptor/@Rfc | /cfdi:Comprobante/cfdi:Receptor/@rfc)');
            $total = $root->getAttribute('Total') ?: $root->getAttribute('total');
            $seal = $root->getAttribute('Sello') ?: $root->getAttribute('sello');
        }
        $issuer = mb_strtoupper(trim($issuer));
        $receiver = mb_strtoupper(trim($receiver));
        $total = trim($total);
        $seal = preg_replace('/\s+/', '', $seal) ?? '';
        $rfc = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/uD';
        if (!preg_match('/^' . self::UUID . '$/iD', $uuid) || !preg_match($rfc, $issuer) || !preg_match($rfc, $receiver)
            || !preg_match('/^\d{1,18}(?:\.\d{1,6})?$/D', $total)
            || !preg_match('~^[A-Za-z0-9+/]{6,}={0,2}$~D', $seal) || strlen($seal) < 8) {
            return null;
        }
        return '?' . http_build_query(['id' => $uuid, 're' => $issuer, 'rr' => $receiver,
            'tt' => $total, 'fe' => substr($seal, -8)], '', '&', PHP_QUERY_RFC3986);
    }

    private static function loadXml(string $xml): ?DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $document = new DOMDocument();
        return @$document->loadXML($xml, LIBXML_NONET) ? $document : null;
    }

    private static function status(string $xml): array
    {
        $document = self::loadXml($xml);
        if ($document !== null) {
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', self::SOAP);
            $xpath->registerNamespace('t', self::SERVICE);
            $xpath->registerNamespace('a', self::ACUSE);
            $path = '/s:Envelope/s:Body/t:ConsultaResponse/t:ConsultaResult/a:';
            $code = trim((string) $xpath->evaluate('string(' . $path . 'CodigoEstatus)'));
            $state = mb_strtolower(trim((string) $xpath->evaluate('string(' . $path . 'Estado)')));
            if (preg_match('/^N\s*601\b/i', $code)) {
                return ['estado_sat' => 'Sin verificar', 'detalle_sat' => 'El SAT rechazó los datos de verificación (N 601).'];
            }
            if (preg_match('/^N\s*602\b/i', $code) || $state === 'no encontrado') {
                return ['estado_sat' => 'No encontrado', 'detalle_sat' => 'El SAT no localizó el CFDI con los datos enviados.'];
            }
            if (in_array($state, ['vigente', 'cancelado'], true)) {
                return ['estado_sat' => ucfirst($state)];
            }
        }
        return ['estado_sat' => 'Sin verificar', 'detalle_sat' => 'El SAT no devolvió un estado verificable.'];
    }

    private function post(array $requests): array
    {
        $batch = curl_multi_init();
        $handles = [];
        foreach ($requests as $id => $body) {
            $handle = curl_init(self::URL);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "' . self::SERVICE . 'IConsultaCFDIService/Consulta"'],
            ]);
            curl_multi_add_handle($batch, $handle);
            $handles[$id] = $handle;
        }
        do {
            $code = curl_multi_exec($batch, $running);
            if ($running && $code === CURLM_OK && curl_multi_select($batch, 0.2) === -1) {
                usleep(10000);
            }
        } while ($running && $code === CURLM_OK);
        $responses = [];
        foreach ($handles as $id => $handle) {
            if ((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200) {
                $responses[$id] = (string) curl_multi_getcontent($handle);
            }
            curl_multi_remove_handle($batch, $handle);
        }
        curl_multi_close($batch);
        return $responses;
    }
}

