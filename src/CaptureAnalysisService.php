<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PDO;
use RuntimeException;

final class CaptureAnalysisService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CompanyPanelRepository $companies,
    ) {
    }

    public function analyze(int $companyId, int $accountId, string $html, int $operatorId): array
    {
        $account = $this->companies->accountForAnalysis($companyId, $accountId, $operatorId);
        $html = trim($html);
        if ($html === '') {
            throw new RuntimeException('Pega primero el contenido de la captura bancaria.');
        }
        if (strlen($html) > 2_000_000) {
            throw new RuntimeException('La captura supera el límite de 2 MB para el análisis.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('El contenido pegado no tiene un formato HTML válido.');
        }

        $xpath = new DOMXPath($document);
        $movements = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }
            $rows = $this->tableRows($xpath, $table);
            if (count($rows) < 2) {
                continue;
            }
            [$headerIndex, $columns] = $this->detectColumns($rows);
            if ($headerIndex === null || !isset($columns['date'])
                || (!isset($columns['amount']) && !isset($columns['credit']) && !isset($columns['debit']))) {
                continue;
            }
            for ($index = $headerIndex + 1; $index < count($rows); $index++) {
                $movement = $this->movementFromRow($rows[$index], $columns);
                if ($movement !== null) {
                    $movements[] = $movement;
                }
                if (count($movements) > 500) {
                    throw new RuntimeException('La captura supera el límite de 500 movimientos por análisis.');
                }
            }
        }
        if ($movements === []) {
            foreach ($xpath->query('//pre') ?: [] as $preformatted) {
                $rows = [];
                foreach (preg_split('/\R/u', trim($preformatted->textContent)) ?: [] as $line) {
                    $cells = array_map('trim', explode("\t", $line));
                    if (count($cells) > 1) $rows[] = $cells;
                }
                if (count($rows) < 2) continue;
                [$headerIndex, $columns] = $this->detectColumns($rows);
                if ($headerIndex === null || !isset($columns['date'])
                    || (!isset($columns['amount']) && !isset($columns['credit']) && !isset($columns['debit']))) continue;
                for ($index = $headerIndex + 1; $index < count($rows); $index++) {
                    $movement = $this->movementFromRow($rows[$index], $columns);
                    if ($movement !== null) $movements[] = $movement;
                }
            }
        }
        if ($movements === []) {
            throw new RuntimeException('No se detectó una tabla bancaria con columnas de fecha e importe, cargo o abono.');
        }

        $duplicate = $this->pdo->prepare('SELECT id FROM captura_edo_cta
            WHERE cuenta = :account AND fecha = :date
            AND ABS(COALESCE(deposito, 0) - :deposit) < 0.005
            AND ABS(COALESCE(retiro, 0) - :withdrawal) < 0.005
            AND ABS(COALESCE(saldo, 0) - :balance) < 0.005 LIMIT 1');
        $summary = ['detected' => count($movements), 'new' => 0, 'duplicates' => 0, 'deposits' => 0, 'withdrawals' => 0, 'new_total' => 0.0];
        foreach ($movements as &$movement) {
            $duplicate->execute([
                'account' => $accountId, 'date' => $movement['date'], 'deposit' => $movement['deposit'],
                'withdrawal' => $movement['withdrawal'], 'balance' => $movement['balance'],
            ]);
            $movement['duplicate'] = $duplicate->fetchColumn() !== false;
            $summary[$movement['duplicate'] ? 'duplicates' : 'new']++;
            if ($movement['type'] === 'Deposito') {
                $summary['deposits']++;
                if (!$movement['duplicate']) $summary['new_total'] += $movement['amount'];
            } else {
                $summary['withdrawals']++;
                if (!$movement['duplicate']) $summary['new_total'] -= $movement['amount'];
            }
        }
        unset($movement);
        return ['account' => $account, 'summary' => $summary, 'movements' => $movements];
    }

    private function tableRows(DOMXPath $xpath, DOMElement $table): array
    {
        $rows = [];
        foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
            }
            if ($cells !== []) $rows[] = $cells;
        }
        return $rows;
    }

    private function detectColumns(array $rows): array
    {
        $aliases = [
            'date' => ['fecha', 'fecha operacion', 'fecha de operacion', 'fecha movimiento', 'dia'],
            'description' => ['descripcion', 'concepto', 'movimiento', 'tipo', 'operacion'],
            'detail' => ['detalle', 'concepto spei', 'spei concepto', 'comentario', 'leyenda'],
            'reference' => ['referencia', 'clave rastreo', 'clave de rastreo', 'spei clave rastreo', 'spei referencia'],
            'debit' => ['retiro', 'retiros', 'cargo', 'cargos', 'debito', 'importe cargo'],
            'credit' => ['deposito', 'depositos', 'abono', 'abonos', 'credito', 'importe abono'],
            'amount' => ['importe', 'monto', 'cantidad'],
            'balance' => ['saldo', 'saldo disponible'],
        ];
        $bestIndex = null;
        $bestColumns = [];
        foreach (array_slice($rows, 0, 10, true) as $rowIndex => $row) {
            $columns = [];
            foreach ($row as $columnIndex => $heading) {
                $normalized = $this->normalize($heading);
                foreach ($aliases as $field => $options) {
                    if (isset($columns[$field])) continue;
                    foreach ($options as $option) {
                        if ($normalized === $option || str_starts_with($normalized, $option . ' ')
                            || ($field === 'description' && str_starts_with($normalized, 'descripci'))) {
                            $columns[$field] = $columnIndex;
                            break;
                        }
                    }
                }
            }
            if (count($columns) > count($bestColumns)) {
                $bestIndex = $rowIndex;
                $bestColumns = $columns;
            }
        }
        return [$bestIndex, $bestColumns];
    }

    private function movementFromRow(array $row, array $columns): ?array
    {
        $date = $this->date((string) ($row[$columns['date']] ?? ''));
        if ($date === null) return null;
        $credit = isset($columns['credit']) ? $this->money((string) ($row[$columns['credit']] ?? '')) : 0.0;
        $debit = isset($columns['debit']) ? $this->money((string) ($row[$columns['debit']] ?? '')) : 0.0;
        $amount = isset($columns['amount']) ? $this->money((string) ($row[$columns['amount']] ?? '')) : 0.0;
        if ($credit == 0.0 && $debit == 0.0 && $amount != 0.0) {
            if ($amount < 0) $debit = abs($amount); else $credit = $amount;
        }
        if ($credit == 0.0 && $debit == 0.0) return null;
        $type = $credit != 0.0 ? 'Deposito' : 'Retiro';
        return [
            'date' => $date,
            'description' => trim((string) ($row[$columns['description'] ?? -1] ?? '')),
            'detail' => trim((string) ($row[$columns['detail'] ?? -1] ?? '')),
            'reference' => trim((string) ($row[$columns['reference'] ?? -1] ?? '')),
            'type' => $type, 'amount' => abs($type === 'Deposito' ? $credit : $debit),
            'deposit' => abs($credit), 'withdrawal' => abs($debit),
            'balance' => isset($columns['balance']) ? $this->money((string) ($row[$columns['balance']] ?? '')) : 0.0,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','_'=>' ']);
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d/m/y', '!d-m-y', '!d-M-Y', '!dmY'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date && $date->format(str_replace('!', '', $format)) === $value) return $date->format('Y-m-d');
        }
        return null;
    }

    private function money(string $value): float
    {
        $value = preg_replace('/[^0-9,.-]/', '', trim($value)) ?? '';
        if ($value === '' || $value === '-') return 0.0;
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $thousand = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousand, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false) {
            $value = preg_match('/,\d{1,2}$/', $value) ? str_replace(',', '.', $value) : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
