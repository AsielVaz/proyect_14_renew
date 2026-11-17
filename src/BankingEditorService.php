<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class BankingEditorService
{
    public const CLIENT_ID = 13;
    public const ALLOWED_USER_TYPES = ['administrador', 'administrador_b'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function isAllowed(string $userType): bool
    {
        return in_array(mb_strtolower(trim($userType), 'UTF-8'), self::ALLOWED_USER_TYPES, true);
    }

    public function data(): array
    {
        $companiesQuery = $this->pdo->prepare(
            'SELECT id, clave_corta, razon_social, rfc
             FROM claves_cortas
             WHERE id_cliente = :client
             ORDER BY razon_social, clave_corta, id'
        );
        $companiesQuery->execute(['client' => self::CLIENT_ID]);
        $companies = $companiesQuery->fetchAll();

        $providers = $this->pdo->query(
            "SELECT id, razon, rfc
             FROM empresas
             WHERE TRIM(COALESCE(razon, '')) <> ''
             ORDER BY razon, id"
        )->fetchAll();

        $configurationQuery = $this->pdo->prepare(
            'SELECT p.id, p.id_empresa, p.id_proveedor, p.porcentaje_sugerido,
                    cc.clave_corta, cc.razon_social, cc.rfc AS rfc_empresa,
                    e.razon AS nombre_proveedor, e.rfc AS rfc_proveedor
             FROM proveedores_14 p
             INNER JOIN claves_cortas cc ON cc.id = p.id_empresa AND cc.id_cliente = :client
             LEFT JOIN empresas e ON e.id = p.id_proveedor
             ORDER BY cc.razon_social, p.id_empresa, e.razon, p.id'
        );
        $configurationQuery->execute(['client' => self::CLIENT_ID]);

        $configurations = [];
        foreach ($configurationQuery->fetchAll() as $row) {
            $companyId = (int) $row['id_empresa'];
            if (!isset($configurations[$companyId])) {
                $configurations[$companyId] = [
                    'id_empresa' => $companyId,
                    'clave_corta' => (string) $row['clave_corta'],
                    'razon_social' => (string) $row['razon_social'],
                    'rfc' => (string) $row['rfc_empresa'],
                    'proveedores' => [],
                ];
            }
            $configurations[$companyId]['proveedores'][] = [
                'id_registro' => (int) $row['id'],
                'id_proveedor' => (int) $row['id_proveedor'],
                'razon' => (string) ($row['nombre_proveedor'] ?? 'Proveedor no disponible'),
                'rfc' => (string) ($row['rfc_proveedor'] ?? ''),
                'porcentaje_sugerido' => (float) $row['porcentaje_sugerido'],
            ];
        }

        return [
            'id_cliente' => self::CLIENT_ID,
            'empresas_catalogo' => array_map([$this, 'normalizeCompany'], $companies),
            'proveedores_catalogo' => array_map([$this, 'normalizeProvider'], $providers),
            'configuraciones' => array_values($configurations),
        ];
    }

    public function save(int $companyId, array $providers): array
    {
        $cleanProviders = self::validateProviders($providers);

        $this->pdo->beginTransaction();
        try {
            $companyQuery = $this->pdo->prepare(
                'SELECT id, razon_social FROM claves_cortas
                 WHERE id = :company AND id_cliente = :client FOR UPDATE'
            );
            $companyQuery->execute(['company' => $companyId, 'client' => self::CLIENT_ID]);
            $company = $companyQuery->fetch();
            if (!$company) {
                throw new RuntimeException('La empresa no existe o no pertenece al cliente autorizado.');
            }

            $providerIds = array_column($cleanProviders, 'id_proveedor');
            $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
            $providerQuery = $this->pdo->prepare("SELECT id FROM empresas WHERE id IN ({$placeholders})");
            $providerQuery->execute($providerIds);
            if (count($providerQuery->fetchAll()) !== count($providerIds)) {
                throw new RuntimeException('Uno o más proveedores seleccionados ya no existen.');
            }

            $delete = $this->pdo->prepare('DELETE FROM proveedores_14 WHERE id_empresa = :company');
            $delete->execute(['company' => $companyId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO proveedores_14 (id_empresa, id_proveedor, porcentaje_sugerido)
                 VALUES (:company, :provider, :percentage)'
            );
            foreach ($cleanProviders as $provider) {
                $insert->execute([
                    'company' => $companyId,
                    'provider' => $provider['id_proveedor'],
                    'percentage' => $provider['porcentaje_sugerido'],
                ]);
            }
            $this->pdo->commit();

            return [
                'id_empresa' => $companyId,
                'empresa' => (string) $company['razon_social'],
                'total_porcentaje' => 100,
                'registros_guardados' => count($cleanProviders),
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function validateProviders(array $providers): array
    {
        if ($providers === []) {
            throw new RuntimeException('Debes agregar al menos un proveedor.');
        }

        $clean = [];
        $seen = [];
        $total = 0.0;
        foreach ($providers as $index => $provider) {
            if (!is_array($provider)) {
                throw new RuntimeException('La fila ' . ($index + 1) . ' no tiene un formato válido.');
            }
            $providerId = filter_var($provider['id_proveedor'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($providerId <= 0) {
                throw new RuntimeException('Selecciona un proveedor válido en la fila ' . ($index + 1) . '.');
            }
            if (isset($seen[$providerId])) {
                throw new RuntimeException('Un proveedor no puede aparecer dos veces en la misma empresa.');
            }
            $rawPercentage = $provider['porcentaje_sugerido'] ?? null;
            if ($rawPercentage === null || !is_numeric($rawPercentage)) {
                throw new RuntimeException('El porcentaje de la fila ' . ($index + 1) . ' no es válido.');
            }
            $percentage = round((float) $rawPercentage, 4);
            if (!is_finite($percentage) || $percentage <= 0 || $percentage > 100) {
                throw new RuntimeException('Cada porcentaje debe ser mayor que 0 y menor o igual a 100.');
            }
            $seen[$providerId] = true;
            $total += $percentage;
            $clean[] = ['id_proveedor' => $providerId, 'porcentaje_sugerido' => $percentage];
        }

        $total = round($total, 4);
        if (abs($total - 100.0) > 0.0001) {
            throw new RuntimeException(
                'La suma de los porcentajes debe ser exactamente 100%. Total actual: '
                . rtrim(rtrim(number_format($total, 4, '.', ''), '0'), '.') . '%.'
            );
        }
        return $clean;
    }

    public function exportRows(string $search = ''): array
    {
        $data = $this->data();
        $providers = [];
        foreach ($data['proveedores_catalogo'] as $provider) {
            $providers[(int) $provider['id']] = $provider;
        }
        $needle = mb_strtolower(trim($search), 'UTF-8');
        $rows = [];
        foreach ($data['configuraciones'] as $configuration) {
            $configurationProviders = array_map(
                fn (array $item): array => $providers[(int) $item['id_proveedor']] ?? [
                    'id' => $item['id_proveedor'], 'razon' => $item['razon'], 'rfc' => $item['rfc'],
                ],
                $configuration['proveedores']
            );
            $configurationSearchable = mb_strtolower(implode(' ', [
                $configuration['id_empresa'], $configuration['clave_corta'], $configuration['razon_social'],
                $configuration['rfc'],
                ...array_map(static fn (array $provider): string => implode(' ', [
                    $provider['id'], $provider['razon'], $provider['rfc'],
                ]), $configurationProviders),
            ]), 'UTF-8');
            if ($needle !== '' && !str_contains($configurationSearchable, $needle)) {
                continue;
            }
            foreach ($configuration['proveedores'] as $providerIndex => $providerConfig) {
                $provider = $configurationProviders[$providerIndex];
                $rows[] = [
                    'empresa_id' => (int) $configuration['id_empresa'],
                    'clave_corta' => (string) $configuration['clave_corta'],
                    'empresa' => (string) $configuration['razon_social'],
                    'rfc_empresa' => (string) $configuration['rfc'],
                    'proveedor_id' => (int) $provider['id'],
                    'proveedor' => (string) $provider['razon'],
                    'rfc_proveedor' => (string) $provider['rfc'],
                    'porcentaje' => (float) $providerConfig['porcentaje_sugerido'],
                ];
            }
        }
        return $rows;
    }

    public function pdf(string $search = ''): string
    {
        if (!class_exists(\FPDF::class, true)) {
            throw new RuntimeException('No se encontró el motor PDF local.');
        }
        $rows = $this->exportRows($search);
        $pdf = new FiscalPdf('L', 'mm', 'Letter');
        $pdf->AliasNbPages();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setFooterLabel('Editor del banking · Sistema 14');
        $pdf->AddPage();
        $pdf->SetFillColor(...FiscalPdf::NAVY);
        $pdf->Rect(0, 0, 279.4, 25, 'F');
        $pdf->SetXY(12, 7);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(180, 7, $pdf->encode('Distribución de proveedores'), 0, 1);
        $pdf->SetX(12);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(180, 5, $pdf->encode('Cliente 13 · Generado ' . date('d/m/Y H:i')), 0, 1);
        $pdf->Ln(7);

        $headers = ['ID', 'Clave corta', 'Empresa', 'Proveedor ID', 'Proveedor', 'RFC', '%'];
        $widths = [14, 30, 58, 21, 66, 31, 18];
        $this->pdfHeader($pdf, $headers, $widths);
        if ($rows === []) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(...FiscalPdf::MUTED);
            $pdf->Cell(array_sum($widths), 9, $pdf->encode('No hay configuraciones para el criterio seleccionado.'), 1, 1, 'C');
        }
        foreach ($rows as $row) {
            if ($pdf->GetY() > 190) {
                $pdf->AddPage();
                $this->pdfHeader($pdf, $headers, $widths);
            }
            $values = [
                (string) $row['empresa_id'], $row['clave_corta'], $row['empresa'],
                (string) $row['proveedor_id'], $row['proveedor'], $row['rfc_proveedor'],
                rtrim(rtrim(number_format((float) $row['porcentaje'], 4, '.', ''), '0'), '.') . '%',
            ];
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->SetTextColor(...FiscalPdf::INK);
            foreach ($values as $index => $value) {
                $limit = match ($index) { 2 => 36, 4 => 42, default => 22 };
                $value = mb_strlen((string) $value) > $limit ? mb_substr((string) $value, 0, $limit - 1) . '…' : (string) $value;
                $pdf->Cell($widths[$index], 7, $pdf->encode($value), 1, 0, $index === 6 ? 'R' : 'L');
            }
            $pdf->Ln();
        }
        return $pdf->Output('S');
    }

    private function pdfHeader(FiscalPdf $pdf, array $headers, array $widths): void
    {
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetFillColor(...FiscalPdf::SOFT);
        $pdf->SetTextColor(...FiscalPdf::NAVY);
        foreach ($headers as $index => $header) {
            $pdf->Cell($widths[$index], 7, $pdf->encode($header), 1, 0, 'L', true);
        }
        $pdf->Ln();
    }

    private function normalizeCompany(array $company): array
    {
        return [
            'id' => (int) $company['id'],
            'clave_corta' => (string) $company['clave_corta'],
            'razon_social' => (string) $company['razon_social'],
            'rfc' => (string) ($company['rfc'] ?? ''),
        ];
    }

    private function normalizeProvider(array $provider): array
    {
        return [
            'id' => (int) $provider['id'],
            'razon' => (string) $provider['razon'],
            'rfc' => (string) ($provider['rfc'] ?? ''),
        ];
    }
}
