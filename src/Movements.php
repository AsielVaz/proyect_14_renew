<?php

declare(strict_types=1);

namespace App;

final class Movements
{
    public static function all(): array
    {
        return [
            'sin-asignar' => [
                'label' => 'Movimientos sin asignar',
                'description' => 'Asigna depósitos o retiros a una cartera y calcula sus comisiones.',
                'action' => 'assign',
                'columns' => self::baseColumns(false),
            ],
            'asignados' => [
                'label' => 'Movimientos asignados',
                'description' => 'Movimientos con cartera asignada. Al desasignar se revierten sus comisiones.',
                'action' => 'unassign',
                'columns' => self::baseColumns(true),
            ],
            'reasignar' => [
                'label' => 'Reasignación de movimientos',
                'description' => 'Revierte la asignación actual y vuelve a calcularla para otra cartera.',
                'action' => 'reassign',
                'columns' => self::baseColumns(true),
            ],
            'totales' => [
                'label' => 'Ingreso de pagos',
                'description' => 'Consulta general de movimientos capturados.',
                'columns' => [
                    'id' => 'ID',
                    'cliente' => ['label' => 'Cartera / empresa', 'secondary' => ['empresa' => 'Empresa']],
                    'monto' => ['label' => 'Monto', 'format' => 'money'],
                    'tipo_movimiento' => ['label' => 'Movimiento', 'secondary' => ['banco' => 'Banco']],
                    'fecha_banco' => ['label' => 'Fechas', 'secondary' => ['fecha_asignacion' => 'Asignado', 'fecha_captura' => 'Capturado']],
                    'asigno' => 'Asignó',
                ],
            ],
            'cartera' => [
                'label' => 'Movimientos por cartera',
                'description' => 'Consulta histórica de movimientos acreditables por cartera. Es la vista equivalente al detalle del panel anterior.',
                'columns' => [
                    'id' => 'ID',
                    'empresa' => ['label' => 'Origen', 'secondary' => ['cuenta' => 'Cuenta']],
                    'monto' => ['label' => 'Importes', 'format' => 'money', 'secondary' => ['saldo_acreditable' => 'Acreditado'], 'secondaryFormat' => ['saldo_acreditable' => 'money']],
                    'tipo_movimiento' => ['label' => 'Movimiento', 'secondary' => ['detalles' => 'Detalle']],
                    'fecha_asignacion' => ['label' => 'Fechas', 'secondary' => ['fecha_banco' => 'Banco']],
                    'origen_comision' => 'Origen comisión',
                ],
            ],
            'traspasos' => [
                'label' => 'Traspasos entre carteras',
                'description' => 'Entrada manual de una salida y una entrada compensadas.',
                'action' => 'transfer',
                'columns' => [
                    'id' => 'ID',
                    'origen' => ['label' => 'Ruta', 'secondary' => ['destino' => 'Destino']],
                    'monto' => ['label' => 'Monto', 'format' => 'money'],
                    'fecha' => 'Fecha', 'detalle' => 'Detalle',
                ],
            ],
            'stp' => [
                'label' => 'Movimientos STP',
                'description' => 'Órdenes registradas en la plantilla de ejecución STP.',
                'columns' => [
                    'id' => 'ID',
                    'cliente' => ['label' => 'Beneficiario', 'secondary' => ['nombre' => 'Nombre']],
                    'cuenta' => ['label' => 'Cuenta', 'secondary' => ['folio' => 'Folio']],
                    'monto' => ['label' => 'Monto', 'format' => 'money'],
                    'status' => ['label' => 'Estatus', 'secondary' => ['estado' => 'Estado', 'error' => 'Detalle']],
                    'fecha_creacion' => 'Fecha',
                    'cep' => ['label' => 'Comprobante', 'secondary' => ['notificado' => 'Enviado']],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    private static function baseColumns(bool $assigned): array
    {
        $columns = ['id' => 'ID'];
        if ($assigned) {
            $columns['cliente'] = ['label' => 'Cartera', 'secondary' => ['asigno' => 'Asignó']];
        }
        $columns += [
            'empresa' => ['label' => 'Origen', 'secondary' => ['cuenta' => 'Cuenta', 'banco' => 'Banco']],
            'monto' => $assigned
                ? ['label' => 'Importe', 'format' => 'money', 'secondary' => ['saldo_acreditable' => 'Acreditable'], 'secondaryFormat' => ['saldo_acreditable' => 'money']]
                : ['label' => 'Monto', 'format' => 'money'],
            'tipo_movimiento' => ['label' => 'Movimiento', 'secondary' => ['detalles' => 'Detalle']],
            'fecha_banco' => $assigned
                ? ['label' => 'Fechas', 'secondary' => ['fecha_asignacion' => 'Asignado']]
                : 'Fecha',
        ];
        return $columns;
    }
}
