<?php

declare(strict_types=1);

namespace App;

final class Catalogs
{
    public const ALLOWED_USER_TYPES = ['administrador'];

    public static function isAllowed(string $userType): bool
    {
        return in_array(
            mb_strtolower(trim($userType), 'UTF-8'),
            self::ALLOWED_USER_TYPES,
            true
        );
    }

    public static function all(): array
    {
        return [
            'empresas' => [
                'label' => 'Empresas', 'table' => 'empresas', 'title' => 'razon',
                'list' => ['id', 'razon', 'rfc', 'estado', 'cp', 'correo_conta'],
                'sections' => [
                    'Identificación fiscal' => 'Razón social y régimen con los que opera la empresa.',
                    'Domicilio' => 'Dirección fiscal registrada para la empresa.',
                    'Información y correos' => 'Descripción y buzones utilizados por cada área.',
                ],
                'fields' => [
                    'razon' => self::text('Razón social', true, 150) + ['section' => 'Identificación fiscal'],
                    'rfc' => self::text('RFC', true, 50),
                    'regimen' => self::text('Régimen fiscal', false, 200),
                    'calle' => self::text('Calle', true, 150) + ['section' => 'Domicilio'],
                    'numero' => self::text('Número', true, 50),
                    'colonia' => self::text('Colonia', true, 70),
                    'cp' => self::text('Código postal', true, 70),
                    'estado' => self::text('Estado', true, 70),
                    'descripcion' => self::textarea('Descripción') + ['section' => 'Información y correos'],
                    'correo_conta' => self::email('Correo Conta'),
                    'correo_pv' => self::email('Correo PV'),
                    'correo_ventas' => self::email('Correo ventas'),
                ],
                'defaults' => ['logo' => '', 'comodin' => 0],
            ],
            'bancos' => [
                'label' => 'Bancos', 'table' => 'bancos', 'title' => 'nombre_corto',
                'list' => ['id', 'razon', 'banco', 'numero_cuenta', 'clabe_interbancaria', 'moneda', 'status_banco'],
                'sections' => [
                    'Cuenta bancaria' => 'Identificación de la empresa, el banco y la cuenta.',
                    'Presentación' => 'Colores utilizados para reconocer la cuenta en el sistema.',
                    'Saldo y operación' => 'Saldo inicial, estado operativo, alertas y límites.',
                ],
                'fields' => [
                    'razon' => self::relation('Empresa', 'empresas', 'id', 'razon', true) + ['section' => 'Cuenta bancaria'],
                    'banco' => self::text('Banco', true, 40),
                    'numero_cuenta' => self::text('Número de cuenta', true, 50),
                    'clabe_interbancaria' => self::text('CLABE interbancaria', true, 100),
                    'nombre_corto' => self::text('Nombre corto', true, 100),
                    'moneda' => self::select('Moneda', ['MXN' => 'MXN', 'USD' => 'USD'], false),
                    'color' => self::color('Color') + ['section' => 'Presentación'],
                    'texto' => self::color('Color de texto'),
                    'fecha_inicial' => self::date('Fecha inicial') + ['section' => 'Saldo y operación'],
                    'saldo_inicial' => self::number('Saldo inicial', false, '0.01'),
                    'alerta' => self::number('Alerta'),
                    'status_banco' => self::select('Estado', ['AC' => 'Activo', 'IN' => 'Inactivo']),
                    'limite_superior' => self::number('Límite superior', false, '0.01'),
                    'limite_inferior' => self::number('Límite inferior', false, '0.01'),
                ],
            ],
            'carteras' => [
                'label' => 'Carteras / clientes', 'table' => 'clientes', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'razon_social', 'rfc', 'correo', 'telefono', 'activo'],
                'sections' => [
                    'Datos generales' => 'Identidad, estado y apariencia de la cartera dentro del sistema.',
                    'Comisiones adicionales' => 'Distribuye comisiones adicionales entre otras carteras.',
                    'Correos de saldos' => 'Destinatarios de los avisos y resúmenes de movimientos y saldos.',
                    'Correos de facturación' => 'Direcciones que recibirán facturas y documentos fiscales.',
                    'Automatización y notificaciones' => 'Agrupación, facturación automática y reglas de notificación.',
                    'Datos fiscales' => 'Información utilizada para identificar fiscalmente a la cartera.',
                ],
                'fields' => [
                    'nombre' => self::text('Nombre', false, 150) + ['section' => 'Datos generales'],
                    'telefono' => self::text('Teléfono', false, 15) + ['inputMode' => 'tel'],
                    'esquema_comision' => self::select('Esquema de comisión', [
                        'Total' => 'Total',
                        'Total Invertido' => 'Total Invertido',
                        'Subtotal' => 'Subtotal',
                    ]),
                    'porcentajeCom' => self::number('Comisión', false, '0.01') + ['column' => 'comision'],
                    'color' => self::select('Color', [
                        'FFEB84' => 'Amarillo',
                        '74C7DA' => 'Azul',
                        '000080' => 'Azul Marino',
                        'C683EA' => 'Morado',
                        'FF9D35' => 'Naranja',
                        'FF5C5C' => 'Rojo',
                        '7ADA74' => 'Verde',
                        'A53636' => 'Vino',
                    ]),
                    'texto' => self::select('Color de texto', [
                        'fff' => 'Blanco',
                        'adadad' => 'Gris',
                        '000' => 'Negro',
                    ]),
                    'archivo' => self::file('Imagen del cliente', 'image/jpeg,image/png,image/webp,image/gif') + ['column' => 'imagen', 'uploadDirectory' => 'clients'],
                    'activo' => self::checkbox('Activo'),
                    'revision' => self::checkbox('En revisión'),

                    'sub_com1' => self::relation('Comisión adicional (1)', 'clientes', 'id', 'nombre') + ['column' => '1_subcomision', 'section' => 'Comisiones adicionales', 'showId' => false],
                    'porcentaje1' => self::number('Porcentaje de comisión (1)', false, '0.01') + ['column' => 'porc_1_subcomision'],
                    'sub_com2' => self::relation('Comisión adicional (2)', 'clientes', 'id', 'nombre') + ['column' => '2_subcomision', 'showId' => false],
                    'porcentaje2' => self::number('Porcentaje de comisión (2)', false, '0.01') + ['column' => 'porc_2_subcomision'],
                    'sub_com3' => self::relation('Comisión adicional (3)', 'clientes', 'id', 'nombre') + ['column' => '3_subcomision', 'showId' => false],
                    'porcentaje3' => self::number('Porcentaje de comisión (3)', false, '0.01') + ['column' => 'porc_3_subcomision'],

                    'correo' => self::email('Correo de saldos (1)', false, 100) + ['section' => 'Correos de saldos'],
                    'correoSaldos2' => self::email('Correo de saldos (2)', false, 100) + ['column' => 'correo_2_s'],
                    'correoSaldos3' => self::email('Correo de saldos (3)', false, 100) + ['column' => 'correo_3_s'],

                    'correo2' => self::email('Correo de facturas (1)', false, 100) + ['section' => 'Correos de facturación'],
                    'correoF2' => self::email('Correo de facturas (2)', false, 100) + ['column' => 'correo2_2_f'],
                    'correoF3' => self::email('Correo de facturas (3)', false, 100) + ['column' => 'correo2_3_f'],

                    'fact_auto' => self::select('Facturación automática', ['No' => 'No', 'Si' => 'Sí']) + ['column' => 'facturacion_automatica', 'section' => 'Automatización y notificaciones', 'caseInsensitiveOptions' => true],
                    'grupo' => self::relation('Grupo de cliente', 'grupo', 'id', 'nombre') + ['showId' => false],
                    'notificar_saldo' => self::checkbox('Notificar saldos'),
                    'notificacion_movimientos' => self::select('Notificación de movimientos', [
                        'no' => 'Sin notificar',
                        'movimiento' => 'Notificar por movimiento',
                        'fin_dia' => 'Notificar al final del día',
                    ], true) + ['column' => 'info_3'],
                    'lidera' => self::checkbox('Lidera grupo'),

                    'razon_social' => self::text('Razón social', false, 250) + ['section' => 'Datos fiscales'],
                    'rfc' => self::text('RFC', false, 50),
                    'domicilio' => self::textarea('Domicilio'),
                ],
                'defaults' => ['info_3' => 'no'],
            ],
            'grupos' => [
                'label' => 'Grupos de clientes', 'table' => 'grupo', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'color', 'cliente_padre', 'cuenta_salida'],
                'sections' => [
                    'Datos del grupo' => 'Nombre y color con los que se identifica el grupo.',
                    'Asignación' => 'Cartera líder y cuenta bancaria de salida.',
                ],
                'fields' => [
                    'nombre' => self::text('Nombre', true, 128) + ['section' => 'Datos del grupo'],
                    'color' => self::color('Color', true),
                    'cliente_padre' => self::relation('Cliente padre', 'clientes', 'id', 'nombre') + ['section' => 'Asignación'],
                    'cuenta_salida' => self::relation('Cuenta de salida', 'bancos', 'id', 'nombre_corto'),
                ],
                'defaults' => ['usuario_inserta' => 0, 'ultimo_asigno' => 0],
            ],
            'conceptos' => [
                'label' => 'Conceptos facturables', 'table' => 'conceptos', 'title' => 'concepto',
                'list' => ['id', 'concepto', 'clave_sat', 'clave_unidad_medida', 'razon', 'precio_min', 'precio_max'],
                'sections' => [
                    'Identificación SAT' => 'Descripción y claves fiscales del concepto.',
                    'Empresa y límites' => 'Empresa emisora y límites operativos del concepto.',
                    'Impuestos y automatización' => 'Tratamiento de IVA y uso en facturación automática.',
                ],
                'fields' => [
                    'concepto' => self::text('Concepto', true, 300) + ['section' => 'Identificación SAT'],
                    'clave_sat' => self::text('Clave SAT', true, 100),
                    'clave_producto' => self::text('Clave de producto', true, 100),
                    'clave_unidad_medida' => self::relation('Unidad de medida', 'catalogo_unidad_medida', 'clave_unidad_medida', 'nombre_unidad_medida', true),
                    'razon' => self::relation('Empresa', 'empresas', 'id', 'razon', true) + ['section' => 'Empresa y límites'],
                    'precio_min' => self::number('Precio mínimo', true, '0.01'),
                    'precio_max' => self::number('Precio máximo', true, '0.01'),
                    'unidades_max' => self::number('Unidades máximas'),
                    'facturable_auto' => self::select('Facturable automáticamente', ['si' => 'Sí', 'no' => 'No']) + ['section' => 'Impuestos y automatización'],
                    'aplica_iva' => self::select('Aplica IVA', ['si' => 'Sí', 'no' => 'No'], true),
                    'objeto_impuesto' => self::select('Objeto de impuesto', ['1' => 'Sí', '0' => 'No']),
                    'impuesto' => self::number('IVA (%)'),
                ],
            ],
            'claves' => [
                'label' => 'Claves cortas', 'table' => 'claves_cortas', 'title' => 'clave_corta',
                'list' => ['id', 'clave_corta', 'razon_social', 'rfc', 'id_cliente', 'cp'],
                'sections' => [
                    'Identificación' => 'Cliente y razón social asociados con la clave corta.',
                    'Datos fiscales' => 'Domicilio, código postal y régimen del receptor.',
                    'Automatización' => 'Comportamiento de facturación automática para esta clave.',
                ],
                'fields' => [
                    'id_cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre', true) + ['section' => 'Identificación'],
                    'clave_corta' => self::text('Clave corta', true, 10),
                    'razon_social' => self::text('Razón social', true, 200),
                    'rfc' => self::text('RFC', true, 100),
                    'domicilio' => self::textarea('Domicilio', true) + ['section' => 'Datos fiscales'],
                    'cp' => self::text('Código postal', false, 6),
                    'regimen_fiscal' => self::number('Régimen fiscal'),
                    'facturacion_automatica' => self::select('Facturación automática', ['si' => 'Sí', 'no' => 'No'], true) + ['section' => 'Automatización'],
                ],
                'defaults' => ['usuario_crea' => 0],
            ],
            'unidades' => [
                'label' => 'Unidades de medida', 'table' => 'catalogo_unidad_medida', 'title' => 'nombre_unidad_medida',
                'list' => ['id', 'clave_unidad_medida', 'nombre_unidad_medida'],
                'sections' => [
                    'Unidad SAT' => 'Clave y nombre utilizados para identificar la unidad de medida.',
                ],
                'fields' => [
                    'clave_unidad_medida' => self::text('Clave', true, 20) + ['section' => 'Unidad SAT'],
                    'nombre_unidad_medida' => self::text('Unidad de medida', true, 100),
                ],
            ],
            'formas' => [
                'label' => 'Formas de pago', 'table' => 'formas_pago', 'title' => 'forma_pago',
                'list' => ['id', 'clave', 'forma_pago'],
                'sections' => [
                    'Forma de pago SAT' => 'Clave y descripción oficial de la forma de pago.',
                ],
                'fields' => [
                    'clave' => self::number('Clave SAT', true) + ['section' => 'Forma de pago SAT'],
                    'forma_pago' => self::text('Forma de pago', true, 100),
                ],
            ],
            'usuarios' => [
                'label' => 'Usuarios', 'table' => 'usuarios', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'appat', 'apmat', 'email', 'usuario', 'tipo'],
                'sections' => [
                    'Identidad y contacto' => 'Datos personales y correo del usuario.',
                    'Acceso y asignación' => 'Identificador de acceso y relaciones operativas del usuario.',
                ],
                'fields' => [
                    'nombre' => self::text('Nombre', false, 100) + ['section' => 'Identidad y contacto'],
                    'appat' => self::text('Apellido paterno', false, 100),
                    'apmat' => self::text('Apellido materno', false, 100),
                    'email' => self::email('Correo'),
                    'fecha_nac' => self::date('Fecha de nacimiento'),
                    'usuario' => self::text('Usuario') + ['section' => 'Acceso y asignación'],
                    'tipo' => self::text('Tipo', false, 30),
                    'cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre'),
                    'empresa_asignada' => self::relation('Empresa asignada', 'empresas', 'id', 'razon'),
                ],
                'defaults' => ['imagen' => ''],
            ],
            'fondeadoras' => [
                'label' => 'Fondeadoras', 'table' => 'fondeadora_ejec', 'title' => 'correo',
                'list' => ['id', 'correo', 'id_empresa', 'minuto_ejecucion'],
                'sections' => [
                    'Cuenta de correo' => 'Credenciales utilizadas para consultar el buzón.',
                    'Ejecución' => 'Cuenta bancaria vinculada y minuto programado.',
                ],
                'fields' => [
                    'correo' => self::email('Correo', true) + ['section' => 'Cuenta de correo'],
                    'pass_coreo' => self::password('Contraseña de correo', true),
                    'id_empresa' => self::relation('Cuenta bancaria', 'bancos', 'id', 'nombre_corto', true) + ['section' => 'Ejecución'],
                    'minuto_ejecucion' => self::number('Minuto de ejecución', true, '1', 0, 59),
                ],
            ],
            'cuentas-especiales' => [
                'label' => 'Cuentas especiales', 'table' => 'cuentas_esp', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'cuenta', 'id_cliente', 'banca'],
                'sections' => [
                    'Identificación' => 'Cliente, nombre y número de la cuenta especial.',
                    'Banco asociado' => 'Cuenta bancaria relacionada con este registro.',
                ],
                'fields' => [
                    'id_cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre') + ['section' => 'Identificación'],
                    'cuenta' => self::text('Cuenta', false, 100),
                    'nombre' => self::text('Nombre', false, 100),
                    'banca' => self::relation('Banco', 'bancos', 'id', 'nombre_corto') + ['section' => 'Banco asociado'],
                ],
            ],
            'contactos' => [
                'label' => 'Contactos de banca', 'table' => 'cuentas_clientes_frecuentes', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'alias', 'cliente', 'numero', 'clabe_inter', 'correo', 'telefono'],
                'sections' => [
                    'Beneficiario' => 'Cliente, nombre y alias del contacto frecuente.',
                    'Datos bancarios' => 'Número de cuenta y CLABE del beneficiario.',
                    'Contacto y operación' => 'Medios de contacto y concepto predeterminado.',
                ],
                'fields' => [
                    'cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre') + ['section' => 'Beneficiario'],
                    'nombre' => self::text('Nombre', false, 100),
                    'alias' => self::text('Alias', false, 50),
                    'numero' => self::text('Número de cuenta', false, 20) + ['section' => 'Datos bancarios'],
                    'clabe_inter' => self::text('CLABE', false, 25),
                    'correo' => self::email('Correo', true, 500) + ['section' => 'Contacto y operación'],
                    'telefono' => self::text('Teléfono', true, 100),
                    'concepto_pre' => self::text('Concepto predeterminado', false, 100),
                ],
                'defaults' => ['fecha' => ['sql' => 'CURDATE()'], 'hora' => ['sql' => 'CURTIME()'], 'ip' => '', 'datos' => ''],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    private static function text(string $label, bool $required = false, ?int $maxlength = null): array { return compact('label', 'required', 'maxlength') + ['type' => 'text']; }
    private static function email(string $label, bool $required = false, ?int $maxlength = null): array { return compact('label', 'required', 'maxlength') + ['type' => 'email']; }
    private static function textarea(string $label, bool $required = false): array { return compact('label', 'required') + ['type' => 'textarea']; }
    private static function date(string $label, bool $required = false): array { return compact('label', 'required') + ['type' => 'date']; }
    private static function color(string $label, bool $required = false): array { return compact('label', 'required') + ['type' => 'color']; }
    private static function checkbox(string $label): array { return compact('label') + ['type' => 'checkbox', 'required' => false]; }
    private static function file(string $label, string $accept = ''): array { return compact('label', 'accept') + ['type' => 'file', 'required' => false]; }
    private static function password(string $label, bool $required = false): array { return compact('label', 'required') + ['type' => 'password', 'writeOnly' => true]; }
    private static function number(string $label, bool $required = false, string $step = '1', ?int $min = null, ?int $max = null): array { return compact('label', 'required', 'step', 'min', 'max') + ['type' => 'number']; }
    private static function select(string $label, array $options, bool $required = false): array { return compact('label', 'options', 'required') + ['type' => 'select']; }
    private static function relation(string $label, string $table, string $value, string $display, bool $required = false): array { return compact('label', 'required') + ['type' => 'relation', 'relation' => compact('table', 'value', 'display')]; }
}
