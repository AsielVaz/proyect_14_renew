<?php

declare(strict_types=1);

namespace App;

final class Statistics
{
    public static function all(): array
    {
        return [
            'ganancias-cliente' => ['label' => 'Ganancias por cliente', 'description' => 'Comisiones generadas por un cliente, agrupadas por mes.', 'clientFilter' => true],
            'estadisticas-clientes' => ['label' => 'Estadísticas para clientes', 'description' => 'Depósitos recibidos por cliente, agrupados por mes.', 'clientFilter' => true],
            'ganancias-generales' => ['label' => 'Ganancias generales', 'description' => 'Aportación acumulada de cada cliente en el periodo seleccionado.'],
            'ganancias-dia' => ['label' => 'Ganancias generales por día', 'description' => 'Comisiones acreditables agrupadas por fecha bancaria.'],
            'uso-stp' => ['label' => 'Uso de STP', 'description' => 'Entradas y salidas STP por semana, tanto en movimientos como en monto.'],
            'facturas' => ['label' => 'Facturas', 'description' => 'Comparativo semanal de facturas manuales y automáticas.'],
            'cuentas-frecuentes' => ['label' => 'Cuentas frecuentes (STP)', 'description' => 'Beneficiarios STP agrupados por cuenta, repeticiones y monto enviado.'],
            'facturas-cliente' => ['label' => 'Cantidad facturada por cliente', 'description' => 'Cantidad de facturas emitidas a un cliente, agrupada por mes.', 'clientFilter' => true],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
