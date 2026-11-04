<?php

declare(strict_types=1);

namespace App;

final class ModernFiscalPdf extends \FPDF
{
    public const NAVY = [19, 27, 45];
    public const ORANGE = [242, 106, 10];
    public const INK = [22, 28, 40];
    public const MUTED = [101, 113, 133];
    public const BORDER = [222, 227, 235];
    public const SOFT = [247, 248, 251];

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(...self::BORDER);
        $this->Line(14, $this->GetY(), 202, $this->GetY());
        $this->SetY(-9);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(...self::MUTED);
        $this->Cell(120, 4, $this->encode('Representación impresa de un CFDI'), 0, 0, 'L');
        $this->Cell(68, 4, $this->encode('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
    }

    public function roundRect(float $x, float $y, float $width, float $height, float $radius = 3, string $style = ''): void
    {
        $k = $this->k;
        $hp = $this->h;
        $operation = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };
        $arc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $radius) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F l', ($x + $width - $radius) * $k, ($hp - $y) * $k));
        $this->arc($x + $width - $radius + $radius * $arc, $y, $x + $width, $y + $radius - $radius * $arc, $x + $width, $y + $radius);
        $this->_out(sprintf('%.2F %.2F l', ($x + $width) * $k, ($hp - ($y + $height - $radius)) * $k));
        $this->arc($x + $width, $y + $height - $radius + $radius * $arc, $x + $width - $radius + $radius * $arc, $y + $height, $x + $width - $radius, $y + $height);
        $this->_out(sprintf('%.2F %.2F l', ($x + $radius) * $k, ($hp - ($y + $height)) * $k));
        $this->arc($x + $radius - $radius * $arc, $y + $height, $x, $y + $height - $radius + $radius * $arc, $x, $y + $height - $radius);
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - ($y + $radius)) * $k));
        $this->arc($x, $y + $radius - $radius * $arc, $x + $radius - $radius * $arc, $y, $x + $radius, $y);
        $this->_out($operation);
    }

    public function encode(string $value): string
    {
        $encoded = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        return $encoded === false ? $value : $encoded;
    }

    private function arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $height = $this->h;
        $scale = $this->k;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $scale, ($height - $y1) * $scale,
            $x2 * $scale, ($height - $y2) * $scale,
            $x3 * $scale, ($height - $y3) * $scale
        ));
    }
}
