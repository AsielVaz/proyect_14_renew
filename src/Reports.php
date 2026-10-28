<?php

declare(strict_types=1);

namespace App;

final class Reports
{
    public static function all(): array
    {
        return [
            'accesos' => [
                'label' => 'Acceso al sistema',
                'description' => 'Sesiones registradas, dispositivo, dirección IP y tiempo de conexión.',
                'columns' => [
                    'usuario' => 'Usuario', 'dispositivo' => 'Dispositivo', 'ip' => 'Dirección IP',
                    'fecha' => 'Fecha', 'hora_i' => 'Hora de inicio', 'tiempo_conexion' => 'Tiempo de conexión',
                    'activa' => 'Sesión activa',
                ],
            ],
            'bitacora' => [
                'label' => 'Bitácora general',
                'description' => 'Actividad registrada por usuario y empresa.',
                'columns' => [
                    'usuario' => 'Usuario', 'hora' => 'Hora', 'fecha' => 'Fecha', 'accion' => 'Acción',
                    'monto' => ['label' => 'Monto', 'format' => 'money'], 'empresa' => 'Empresa',
                ],
            ],
            'disparador' => [
                'label' => 'Ejecuciones del disparador',
                'description' => 'Movimientos automáticos entre bancas.',
                'columns' => [
                    'id' => 'ID', 'fecha' => 'Fecha', 'banca_nueva' => 'Banca nueva',
                    'banca_original' => 'Banca original', 'monto' => ['label' => 'Monto', 'format' => 'money'],
                    'usuario_registro' => 'Usuario', 'sistema' => 'Sistema',
                ],
            ],
            'conta' => [
                'label' => 'Conta 14',
                'description' => 'Pagos acreditables con su factura y folio fiscal asociados.',
                'dateFilter' => true,
                'columns' => [
                    'id' => 'ID', 'empresa' => 'Empresa', 'cliente' => 'Cliente',
                    'monto' => ['label' => 'Cantidad', 'format' => 'money'],
                    'saldo_acreditable' => ['label' => 'Saldo acreditable', 'format' => 'money'],
                    'banco' => 'Banco', 'fecha_banco' => 'Fecha banco', 'fecha_asignacion' => 'Fecha asignación',
                    'detalles' => 'Detalles', 'uuid' => 'Folio fiscal asociado', 'serie_folio' => 'Serie / folio',
                ],
            ],
            'conta-interno' => [
                'label' => 'Conta 14 (interno)',
                'description' => 'Facturas internas emparejadas con pagos por importe exacto.',
                'columns' => [
                    'factura_id' => 'Factura', 'fecha_factura' => 'Fecha factura', 'uuid' => 'Folio fiscal',
                    'importe_factura' => ['label' => 'Importe factura', 'format' => 'money'],
                    'pago_id' => 'Pago', 'monto_pago' => ['label' => 'Monto pago', 'format' => 'money'],
                    'fecha_pago' => 'Fecha pago', 'empresa' => 'Empresa', 'cuenta' => 'Cuenta',
                    'detalles' => 'Detalles del pago',
                ],
            ],
            'pagos-saldo' => [
                'label' => 'Pagos con cuenta pendiente',
                'description' => 'Pagos relacionados con facturas y su importe restante.',
                'columns' => [
                    'id' => 'ID', 'empresa' => 'Empresa', 'banco' => 'Banco',
                    'monto' => ['label' => 'Monto', 'format' => 'money'], 'tipo_movimiento' => 'Tipo de movimiento',
                    'descripcion' => 'Descripción', 'descripcion_detallada' => 'Descripción detallada',
                    'fecha_banco' => 'Fecha de banco', 'restante' => ['label' => 'Restante', 'format' => 'money'],
                    'folios_asociados' => 'Folios asociados',
                ],
            ],
            'timbres' => [
                'label' => 'Timbres',
                'description' => 'Intentos de timbrado agrupados por factura.',
                'columns' => [
                    'id' => 'ID', 'id_factura' => 'ID factura', 'uuid' => 'Folio fiscal timbrado',
                    'usuario_timbro' => 'Usuario timbró', 'fecha_timbrado' => 'Hora de timbrado',
                    'conteo' => 'Cantidad de timbres',
                ],
            ],
            'facturas-eliminadas' => [
                'label' => 'Facturas eliminadas',
                'description' => 'Últimas 1,000 facturas trasladadas al histórico de eliminadas.',
                'columns' => self::invoiceColumns(true),
            ],
            'facturas-captura-eliminada' => [
                'label' => 'Facturas eliminadas por captura',
                'description' => 'Facturas marcadas con el estado CAPDEL.',
                'columns' => self::invoiceColumns(false),
            ],
            'facturas-canceladas' => [
                'label' => 'Facturas canceladas',
                'description' => 'Facturas eliminadas que conservan folio fiscal.',
                'columns' => self::invoiceColumns(true),
            ],
            'banking' => [
                'label' => 'Banking',
                'description' => 'Distribución diaria real contra el porcentaje sugerido por proveedor.',
                'columns' => [
                    'empresa_origen' => 'Empresa', 'proveedor' => 'Proveedor',
                    'total' => ['label' => 'Total facturado', 'format' => 'money'],
                    'porcentaje_real' => ['label' => 'Porcentaje real', 'format' => 'percent'],
                    'porcentaje_sugerido' => ['label' => 'Porcentaje sugerido', 'format' => 'percent'],
                    'rango' => 'Rango aceptable', 'estado' => 'Estado',
                ],
            ],
            'correos-factura' => [
                'label' => 'Correos de facturas',
                'description' => 'Peticiones y respuestas del procesamiento de facturas por correo.',
                'columns' => [
                    'origen' => 'Correo de origen', 'destino' => 'Correo destino',
                    'peticion' => 'Mensaje de petición', 'respuesta' => 'Mensaje respuesta',
                    'fecha_envia' => 'Hora de petición', 'fecha_recibe' => 'Hora de respuesta',
                ],
            ],
            'correos-contexto' => [
                'label' => 'Correos contextuales',
                'description' => 'Mensajes contextuales separados en petición y respuesta.',
                'columns' => [
                    'empresa_origen' => 'Empresa origen', 'empresa_destino' => 'Empresa destino',
                    'mensaje' => 'Mensaje', 'respuesta' => 'Respuesta',
                    'fecha_entrega' => 'Hora de petición', 'fecha_respuesta' => 'Hora de respuesta',
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    private static function invoiceColumns(bool $users): array
    {
        $columns = [
            'id' => 'ID factura', 'folio_interno' => 'Folio interno', 'metodo_pago' => 'Método de pago',
            'forma_pago' => 'Forma de pago', 'empresa' => 'Empresa que factura',
            'total_iva' => ['label' => 'Total con IVA', 'format' => 'money'], 'cliente' => 'Cliente',
            'fecha_timbrado' => 'Fecha de timbrado', 'uuid' => 'Folio fiscal', 'rfc' => 'RFC',
        ];
        if ($users) {
            $columns['usuarios'] = 'Usuarios';
        }
        return $columns;
    }
}
