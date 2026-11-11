<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use ZipArchive;

final class XlsxExportService
{
    public function create(string $sheetName, array $headers, array $rows, array $options = []): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP es necesaria para exportar a Excel.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 's14-xlsx-');
        if ($temporary === false) {
            throw new RuntimeException('No fue posible preparar el archivo de Excel.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporary);
            throw new RuntimeException('No fue posible crear el archivo de Excel.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($headers, $rows, $options));
            if (!$zip->close()) {
                throw new RuntimeException('No fue posible finalizar el archivo de Excel.');
            }
            $bytes = file_get_contents($temporary);
            if ($bytes === false) {
                throw new RuntimeException('No fue posible leer el archivo de Excel generado.');
            }
            return $bytes;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function worksheet(array $headers, array $rows, array $options): string
    {
        $columnCount = count($headers);
        $lastColumn = $this->columnName(max(1, $columnCount));
        $lastRow = count($rows) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        $keys = array_keys($headers);
        $configuredWidths = is_array($options['widths'] ?? null) ? $options['widths'] : [];
        for ($column = 1; $column <= $columnCount; $column++) {
            $key = (string) ($keys[$column - 1] ?? '');
            $width = max(8, min(80, (float) ($configuredWidths[$key] ?? ($column === 1 ? 12 : 22))));
            $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>' . $this->rowXml(1, array_values($headers), true);
        foreach ($rows as $index => $row) {
            $values = [];
            foreach (array_keys($headers) as $key) {
                $values[] = $row[$key] ?? '';
            }
            $xml .= $this->rowXml($index + 2, $values, false, !empty($options['wrap_text']));
        }
        return $xml . '</sheetData><autoFilter ref="A1:' . $lastColumn . $lastRow . '"/></worksheet>';
    }

    private function rowXml(int $rowNumber, array $values, bool $header, bool $wrapText = false): string
    {
        $xml = '<row r="' . $rowNumber . '">';
        foreach ($values as $index => $value) {
            $reference = $this->columnName($index + 1) . $rowNumber;
            if (!$header && (is_int($value) || is_float($value))) {
                $xml .= '<c r="' . $reference . '"><v>' . $this->escape((string) $value) . '</v></c>';
                continue;
            }
            $style = $header ? ' s="1"' : ($wrapText ? ' s="2"' : '');
            $xml .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
                . $this->escape((string) $value) . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[^\P{C}\t\r\n]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $sheetName), 0, 31);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->escape($name !== '' ? $name : 'Datos') . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            . '</styleSheet>';
    }
}
