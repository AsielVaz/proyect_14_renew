<?php

declare(strict_types=1);

namespace App;

final class Catalogs
{
    public static function all(): array
    {
        $yesNo = ['1' => 'Sí', '0' => 'No'];
        $active = ['1' => 'Activo', '0' => 'Inactivo'];

        return [
            'empresas' => [
                'label' => 'Empresas', 'table' => 'empresas', 'title' => 'razon',
                'list' => ['id', 'razon', 'rfc', 'estado', 'cp', 'correo_conta'],
                'fields' => [
                    'razon' => self::text('Razón social', true, 150),
                    'rfc' => self::text('RFC', true, 50),
                    'calle' => self::text('Calle', true, 150),
                    'numero' => self::text('Número', true, 50),
                    'colonia' => self::text('Colonia', true, 70),
                    'cp' => self::text('Código postal', true, 70),
                    'estado' => self::text('Estado', true, 70),
                    'regimen' => self::text('Régimen fiscal', false, 200),
                    'descripcion' => self::textarea('Descripción'),
                    'correo_conta' => self::email('Correo Conta'),
                    'correo_pv' => self::email('Correo PV'),
                    'correo_ventas' => self::email('Correo ventas'),
                ],
                'defaults' => ['logo' => '', 'comodin' => 0],
            ],
            'bancos' => [
                'label' => 'Bancos', 'table' => 'bancos', 'title' => 'nombre_corto',
                'list' => ['id', 'razon', 'banco', 'numero_cuenta', 'clabe_interbancaria', 'moneda', 'status_banco'],
                'fields' => [
                    'razon' => self::relation('Empresa', 'empresas', 'id', 'razon', true),
                    'banco' => self::text('Banco', true, 40),
                    'numero_cuenta' => self::text('Número de cuenta', true, 50),
                    'clabe_interbancaria' => self::text('CLABE interbancaria', true, 100),
                    'nombre_corto' => self::text('Nombre corto', true, 100),
                    'moneda' => self::select('Moneda', ['MXN' => 'MXN', 'USD' => 'USD'], false),
                    'color' => self::color('Color'),
                    'texto' => self::color('Color de texto'),
                    'fecha_inicial' => self::date('Fecha inicial'),
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
                'fields' => [
                    'nombre' => self::text('Nombre', false, 150),
                    'razon_social' => self::text('Razón social', false, 250),
                    'rfc' => self::text('RFC', false, 50),
                    'telefono' => self::text('Teléfono', false, 15),
                    'correo' => self::email('Correo principal'),
                    'correo2' => self::email('Correo 2'),
                    'correo3' => self::email('Correo 3'),
                    'tipo_cliente' => self::text('Tipo de cliente', false, 50),
                    'domicilio' => self::textarea('Domicilio'),
                    'clave_corta' => self::text('Clave corta', false, 10),
                    'empresa' => self::relation('Empresa', 'empresas', 'id', 'razon'),
                    'grupo' => self::relation('Grupo', 'grupo', 'id', 'nombre'),
                    'facturacion_automatica' => self::select('Facturación automática', ['si' => 'Sí', 'no' => 'No']),
                    'comision' => self::number('Comisión', false, '0.01'),
                    'notificar_saldo' => self::select('Notificar saldo', $yesNo),
                    'activo' => self::select('Estado', $active),
                ],
            ],
            'grupos' => [
                'label' => 'Grupos de clientes', 'table' => 'grupo', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'color', 'cliente_padre', 'cuenta_salida'],
                'fields' => [
                    'nombre' => self::text('Nombre', true, 128),
                    'color' => self::color('Color', true),
                    'cliente_padre' => self::relation('Cliente padre', 'clientes', 'id', 'nombre'),
                    'cuenta_salida' => self::relation('Cuenta de salida', 'bancos', 'id', 'nombre_corto'),
                ],
                'defaults' => ['usuario_inserta' => 0, 'ultimo_asigno' => 0],
            ],
            'conceptos' => [
                'label' => 'Conceptos facturables', 'table' => 'conceptos', 'title' => 'concepto',
                'list' => ['id', 'concepto', 'clave_sat', 'clave_unidad_medida', 'razon', 'precio_min', 'precio_max'],
                'fields' => [
                    'concepto' => self::text('Concepto', true, 300),
                    'clave_sat' => self::text('Clave SAT', true, 100),
                    'clave_producto' => self::text('Clave de producto', true, 100),
                    'clave_unidad_medida' => self::relation('Unidad de medida', 'catalogo_unidad_medida', 'clave_unidad_medida', 'nombre_unidad_medida', true),
                    'razon' => self::relation('Empresa', 'empresas', 'id', 'razon', true),
                    'precio_min' => self::number('Precio mínimo', true, '0.01'),
                    'precio_max' => self::number('Precio máximo', true, '0.01'),
                    'unidades_max' => self::number('Unidades máximas'),
                    'facturable_auto' => self::select('Facturable automáticamente', ['si' => 'Sí', 'no' => 'No']),
                    'aplica_iva' => self::select('Aplica IVA', ['si' => 'Sí', 'no' => 'No'], true),
                    'objeto_impuesto' => self::select('Objeto de impuesto', ['1' => 'Sí', '0' => 'No']),
                    'impuesto' => self::number('IVA (%)'),
                ],
            ],
            'claves' => [
                'label' => 'Claves cortas', 'table' => 'claves_cortas', 'title' => 'clave_corta',
                'list' => ['id', 'clave_corta', 'razon_social', 'rfc', 'id_cliente', 'cp'],
                'fields' => [
                    'id_cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre', true),
                    'clave_corta' => self::text('Clave corta', true, 10),
                    'razon_social' => self::text('Razón social', true, 200),
                    'rfc' => self::text('RFC', true, 100),
                    'domicilio' => self::textarea('Domicilio', true),
                    'facturacion_automatica' => self::select('Facturación automática', ['si' => 'Sí', 'no' => 'No'], true),
                    'cp' => self::text('Código postal', false, 6),
                    'regimen_fiscal' => self::number('Régimen fiscal'),
                ],
                'defaults' => ['usuario_crea' => 0],
            ],
            'unidades' => [
                'label' => 'Unidades de medida', 'table' => 'catalogo_unidad_medida', 'title' => 'nombre_unidad_medida',
                'list' => ['id', 'clave_unidad_medida', 'nombre_unidad_medida'],
                'fields' => [
                    'clave_unidad_medida' => self::text('Clave', true, 20),
                    'nombre_unidad_medida' => self::text('Unidad de medida', true, 100),
                ],
            ],
            'formas' => [
                'label' => 'Formas de pago', 'table' => 'formas_pago', 'title' => 'forma_pago',
                'list' => ['id', 'clave', 'forma_pago'],
                'fields' => [
                    'clave' => self::number('Clave SAT', true),
                    'forma_pago' => self::text('Forma de pago', true, 100),
                ],
            ],
            'usuarios' => [
                'label' => 'Usuarios', 'table' => 'usuarios', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'appat', 'apmat', 'email', 'usuario', 'tipo'],
                'fields' => [
                    'nombre' => self::text('Nombre', false, 100),
                    'appat' => self::text('Apellido paterno', false, 100),
                    'apmat' => self::text('Apellido materno', false, 100),
                    'email' => self::email('Correo'),
                    'fecha_nac' => self::date('Fecha de nacimiento'),
                    'usuario' => self::text('Usuario'),
                    'tipo' => self::text('Tipo', false, 30),
                    'cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre'),
                    'empresa_asignada' => self::relation('Empresa asignada', 'empresas', 'id', 'razon'),
                ],
                'defaults' => ['imagen' => ''],
            ],
            'fondeadoras' => [
                'label' => 'Fondeadoras', 'table' => 'fondeadora_ejec', 'title' => 'correo',
                'list' => ['id', 'correo', 'id_empresa', 'minuto_ejecucion'],
                'fields' => [
                    'correo' => self::email('Correo', true),
                    'pass_coreo' => self::password('Contraseña de correo', true),
                    'id_empresa' => self::relation('Cuenta bancaria', 'bancos', 'id', 'nombre_corto', true),
                    'minuto_ejecucion' => self::number('Minuto de ejecución', true, '1', 0, 59),
                ],
            ],
            'cuentas-especiales' => [
                'label' => 'Cuentas especiales', 'table' => 'cuentas_esp', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'cuenta', 'id_cliente', 'banca'],
                'fields' => [
                    'id_cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre'),
                    'cuenta' => self::text('Cuenta', false, 100),
                    'nombre' => self::text('Nombre', false, 100),
                    'banca' => self::relation('Banco', 'bancos', 'id', 'nombre_corto'),
                ],
            ],
            'contactos' => [
                'label' => 'Contactos de banca', 'table' => 'cuentas_clientes_frecuentes', 'title' => 'nombre',
                'list' => ['id', 'nombre', 'alias', 'cliente', 'numero', 'clabe_inter', 'correo', 'telefono'],
                'fields' => [
                    'cliente' => self::relation('Cliente', 'clientes', 'id', 'nombre'),
                    'numero' => self::text('Número de cuenta', false, 20),
                    'nombre' => self::text('Nombre', false, 100),
                    'alias' => self::text('Alias', false, 50),
                    'clabe_inter' => self::text('CLABE', false, 25),
                    'correo' => self::email('Correo', true, 500),
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
    private static function password(string $label, bool $required = false): array { return compact('label', 'required') + ['type' => 'password', 'writeOnly' => true]; }
    private static function number(string $label, bool $required = false, string $step = '1', ?int $min = null, ?int $max = null): array { return compact('label', 'required', 'step', 'min', 'max') + ['type' => 'number']; }
    private static function select(string $label, array $options, bool $required = false): array { return compact('label', 'options', 'required') + ['type' => 'select']; }
    private static function relation(string $label, string $table, string $value, string $display, bool $required = false): array { return compact('label', 'required') + ['type' => 'relation', 'relation' => compact('table', 'value', 'display')]; }
}
