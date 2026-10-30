<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class FiscalDocumentService
{
    private const API_BASE = 'https://ultra.sistema14.com/api';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function stampInvoice(int $id, int $operatorId): string
    {
        $this->assertInvoice($id, false);
        $response = $this->post('/apiFactura.php', [
            'accion' => 'facturar', 'id' => $id, 'usuario' => $operatorId,
            'nota' => 'Timbrado desde Sistema 14 renovado',
        ]);
        $this->assertRemoteSuccess($response, 'No fue posible timbrar la factura.');
        return (string) ($response['mensaje'] ?? $response['message'] ?? 'La factura fue enviada a timbrado.');
    }

    public function resendInvoice(int $id): string
    {
        $this->assertInvoice($id, true);
        $response = $this->post('/apiFactura.php', ['accion' => 'reenviar', 'id' => $id]);
        $this->assertRemoteSuccess($response, 'No fue posible reenviar la factura.');
        return (string) ($response['message'] ?? $response['mensaje'] ?? 'La factura fue reenviada.');
    }

    public function stampComplement(int $id, string $treatment): string
    {
        $this->assertComplement($id, false);
        $action = match ($treatment) {
            'T' => 'timbrar',
            'T0' => 'timbrarTcero',
            'SV' => 'timbrarSinIva',
            default => throw new RuntimeException('Selecciona un tratamiento fiscal disponible para timbrar.'),
        };
        $response = $this->post('/apiComplementos.php', ['accion' => $action, 'id' => $id]);
        $this->assertRemoteSuccess($response, 'No fue posible timbrar el complemento.');
        return (string) ($response['message'] ?? $response['mensaje'] ?? 'El complemento fue enviado a timbrado.');
    }

    public function resendComplement(int $id): string
    {
        $this->assertComplement($id, true);
        $response = $this->post('/apiComplementos.php', ['accion' => 'reenviar', 'id' => $id]);
        $this->assertRemoteSuccess($response, 'No fue posible reenviar el complemento.');
        return (string) ($response['message'] ?? $response['mensaje'] ?? 'El complemento fue reenviado.');
    }

    private function assertInvoice(int $id, bool $mustBeStamped): void
    {
        $query = $this->pdo->prepare('SELECT uuid FROM facturas WHERE id = :id');
        $query->execute(['id' => $id]);
        $uuid = $query->fetchColumn();
        if ($uuid === false) throw new RuntimeException('La factura solicitada no existe.');
        $stamped = trim((string) $uuid) !== '';
        if ($mustBeStamped && !$stamped) throw new RuntimeException('La factura todavía no está timbrada.');
        if (!$mustBeStamped && $stamped) throw new RuntimeException('La factura ya fue timbrada anteriormente.');
    }

    private function assertComplement(int $id, bool $mustBeStamped): void
    {
        $query = $this->pdo->prepare('SELECT uuid FROM facturas_complementos WHERE id = :id');
        $query->execute(['id' => $id]);
        $uuid = $query->fetchColumn();
        if ($uuid === false) throw new RuntimeException('El complemento solicitado no existe.');
        $stamped = trim((string) $uuid) !== '';
        if ($mustBeStamped && !$stamped) throw new RuntimeException('El complemento todavía no está timbrado.');
        if (!$mustBeStamped && $stamped) throw new RuntimeException('El complemento ya fue timbrado anteriormente.');
    }

    private function post(string $path, array $fields): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL es necesaria para comunicarse con el servicio fiscal.');
        }
        $handle = curl_init(self::API_BASE . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Sistema14-Renovado/1.0',
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('No fue posible conectar con el servicio fiscal: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $body = trim((string) $body);
        $data = $this->extractRemoteResult($body);
        if (($status < 200 || $status >= 300) && !($data['useful_result'] ?? false)) {
            throw new RuntimeException('El servicio fiscal devolvió una respuesta no válida.');
        }
        if (!is_array($data)) {
            throw new RuntimeException('El servicio fiscal devolvió una respuesta no válida.');
        }
        return $data;
    }

    private function extractRemoteResult(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $this->normalizeRemoteResult($decoded);
        }

        // Algunas respuestas del sistema legado mezclan cálculos, URLs, SQL y hasta
        // errores PHP después de un JSON válido. Se extraen objetos JSON completos
        // sin depender de que estén al inicio o al final de la respuesta.
        $failure = null;
        if (preg_match_all('/\{(?:[^{}"]|"(?:\\\\.|[^"\\\\])*")*\}/s', $body, $matches)) {
            foreach ($matches[0] as $candidate) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded) && $this->hasRemoteSignal($decoded)) {
                    $normalized = $this->normalizeRemoteResult($decoded);
                    if (($normalized['status'] ?? '') !== 'error') {
                        return $normalized;
                    }
                    $failure = $normalized;
                }
            }
        }

        // El timbrado puede haber terminado y actualizado la factura antes de que
        // falle una tarea secundaria, como la generación del PDF. Un UUID fiscal
        // explícito es evidencia suficiente para no reportar un falso negativo.
        if (preg_match('/\buuid\s*=\s*["\']([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})["\']/i', $body, $match)
            || preg_match('/"DatoAdicional"\s*:\s*"([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})"/i', $body, $match)) {
            return [
                'status' => 'success',
                'uuid' => strtoupper($match[1]),
                'useful_result' => true,
                'message' => 'El documento fue timbrado correctamente. UUID: ' . strtoupper($match[1]),
            ];
        }
        return $failure;
    }

    private function hasRemoteSignal(array $response): bool
    {
        return array_key_exists('Respuesta', $response)
            || array_key_exists('respuesta', $response)
            || array_key_exists('status', $response)
            || array_key_exists('estatus', $response)
            || array_key_exists('uuid', $response)
            || array_key_exists('DatoAdicional', $response)
            || array_key_exists('datoAdicional', $response);
    }

    private function normalizeRemoteResult(array $response): array
    {
        $answer = $response['Respuesta'] ?? $response['respuesta'] ?? null;
        $additional = trim((string) ($response['DatoAdicional'] ?? $response['datoAdicional'] ?? ''));
        $uuid = preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $additional)
            ? strtoupper($additional)
            : trim((string) ($response['uuid'] ?? ''));
        if ($answer === true || $answer === 1 || $answer === '1' || strtolower((string) $answer) === 'true' || $uuid !== '') {
            $response['status'] = 'success';
            $response['useful_result'] = true;
            if ($uuid !== '') {
                $response['uuid'] = $uuid;
            }
        } elseif ($answer === false || $answer === 0 || $answer === '0' || strtolower((string) $answer) === 'false') {
            $response['status'] = 'error';
        }
        return $response;
    }

    private function assertRemoteSuccess(array $response, string $fallback): void
    {
        $status = strtolower((string) ($response['status'] ?? $response['estatus'] ?? 'success'));
        if (in_array($status, ['error', 'failed', 'failure'], true)) {
            throw new RuntimeException(trim((string) ($response['message'] ?? $response['mensaje'] ?? $response['Mensaje'] ?? '')) ?: $fallback);
        }
    }
}
