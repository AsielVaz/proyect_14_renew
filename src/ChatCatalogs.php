<?php

declare(strict_types=1);

namespace App;

final class ChatCatalogs
{
    private const NOTIFICATIONS = "CASE LOWER(TRIM(c.info_3))
        WHEN 'movimiento' THEN 'Aviso por movimiento y resumen de fin de día'
        WHEN 'fin_dia' THEN 'Al final del día (solo resumen)'
        WHEN 'no' THEN 'Sin notificar' END";

    public const NAMES = [
        'carteras' => 'carteras', 'claves' => 'claves cortas', 'grupos' => 'grupos', 'empresas' => 'empresas',
        'bancos' => 'cuentas bancarias', 'conceptos' => 'conceptos facturables', 'unidades' => 'unidades de medida',
        'formas' => 'formas de pago', 'usuarios' => 'usuarios', 'fondeadoras' => 'fondeadoras',
        'cuentas-especiales' => 'cuentas especiales', 'contactos' => 'contactos de banca',
    ];


    public static function request(string $text, array $reference): ?array
    {
        $text = preg_replace('/^((?:que|cuales son|muestra|dime|lista) )claves (?=(?:tiene|de|asociadas a)\b)/', '$1claves cortas ', $text) ?? $text;
        $phone = self::phoneRequest($text, $reference);
        if ($phone !== null) {
            return $phone;
        }
        $config = self::configurationRequest($text, $reference);
        if ($config !== null) {
            return $config;
        }
        $article = '(?:la |las |el |los )?';
        $wallet = '(?:carteras?|clientes?)';
        $patterns = [
            "~^(?:(?:cual es|dime|dame|muestra|consulta|que) )?(?:(?:el|un) )?(?:estado|estatus|reporte|saldo(?: acreditable)?)(?: (?:tiene|tienen|de|del))? {$article}{$wallet} (.+)$~",
            "~^cuanto saldo (?:tiene|tienen) {$article}{$wallet} (.+)$~",
            "~^(?:esta|estan|sigue|siguen) (?:activ[oa]s?|inactiv[oa]s?) {$article}{$wallet} (.+)$~",
            "~^{$article}{$wallet} (.+?) (?:esta|estan|sigue|siguen) (?:activ[oa]s?|inactiv[oa]s?)$~",
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)
                && !preg_match('/^(?:(?:activas?|inactivas?)$|activas? |inactivas? |visibles |que |con |del? |en )/', $match[1])) {
                return self::request('cartera ' . $match[1], $reference);
            }
        }
        if (preg_match("~^(?:(?:cual es|dime|dame|muestra|consulta) )?(?:el )?(?:estado|estatus|reporte|saldo) de (?:esta|esa) cartera$~", $text)) {
            return isset($reference['cartera_id'])
                ? self::request('cartera #' . (int) $reference['cartera_id'], $reference)
                : ['modo' => 'aclarar_catalogo', 'mensaje' => 'Indica el nombre o ID de la cartera.'];
        }
        if (preg_match('/\b(?:saldo|saldos|ppd|pendientes de pago|depositos|retiros|movimientos|facturas|pagos)\b/', $text)) {
            return null;
        }
        $aliases = [
            'carteras'=>'(?:carteras?|clientes?)', 'claves'=>'claves? cortas?', 'grupos'=>'grupos?(?: de clientes| de carteras)?',
            'empresas'=>'empresas?(?: emisoras?)?', 'bancos'=>'(?:cuentas? bancarias?|bancos?)',
            'conceptos'=>'conceptos?(?: facturables?)?', 'unidades'=>'unidad(?:es)?(?: de medida)?',
            'formas'=>'formas? de pago', 'usuarios'=>'usuarios?', 'fondeadoras'=>'fondeadoras?',
            'cuentas-especiales'=>'cuentas? especiales?', 'contactos'=>'(?:contactos? (?:de banca|bancarios?)|beneficiarios?)',
        ];
        $intent = '(?:cuant[oa]s?|que(?: es| significa)?|cual(?:es)?(?: es| son)?|lista|listame|enumera|muestra|muestrame|dime|dame|busca|consulta|cuenta|total de|datos (?:fiscales )?de)';
        $prefix = "(?:({$intent}) )?(?:(?:todas? |todos? )?(?:la |el |los |las |un |una )?)?(?:(\d{1,3}) )?";
        foreach ($aliases as $catalog => $alias) {
            if (!preg_match("~^{$prefix}({$alias})(?: (.+))?$~", $text, $match)) {
                continue;
            }
            $tail = trim($match[4] ?? '');
            $filters = ['catalogo' => $catalog];
            if (preg_match('/^(?:cuant[oa]|cuenta$|total de$)/', $match[1])) {
                $filters['contar'] = true;
            }
            if (!empty($match[2])) {
                $filters['limite'] = min(ChatQueries::MAX_LIMIT, max(1, (int) $match[2]));
            }
            if (in_array($catalog, ['carteras', 'bancos'], true)
                && preg_match('/^(?:estan )?(activ[oa]s?|inactiv[oa]s?)\b *(.*)$/', $tail, $status)) {
                $filters['estado'] = str_starts_with($status[1], 'in') ? 'inactivo' : 'activo';
                $tail = $status[2];
            }
            if ($catalog === 'carteras' && preg_match('/(?:^| )(?:(?:que )?aparecen |visibles )?(?:en|del) (?:el )?panel(?: principal)?$/', $tail, $panel)) {
                $filters['panel'] = true;
                $tail = substr($tail, 0, -strlen($panel[0]));
            }
            if ($catalog === 'bancos' && preg_match('/(?:^| )(?:en |moneda )?(mxn|usd)(?= |$)/', $tail, $currency)) {
                $filters['moneda'] = strtoupper($currency[1]);
                $tail = trim(str_replace($currency[0], '', $tail));
            }
            if ($catalog === 'carteras' && preg_match('/^lider(?:es)?\b *(.*)$/', $tail, $leader)) {
                $filters['lider'] = true;
                $tail = $leader[1];
            }
            $tail = preg_replace('/^(?:hay|existen|tenemos|estan registrad[oa]s)(?: |$)/', '', $tail) ?? $tail;
            $tail = preg_replace('/^(?:pertenecen (?:al|a)|tiene[n]?|corresponde a|de|del|en|para|asociadas? a|con) /', '', $tail) ?? $tail;
            $tail = preg_replace('/^(?:la|el|las|los) /', '', $tail) ?? $tail;
            if (preg_match('/^(esta|este|esa|ese) (cartera|empresa|grupo)$/', $tail, $relative)) {
                $key = $relative[2] . '_id';
                if (!isset($reference[$key])) {
                    return ['modo' => 'aclarar_catalogo', 'mensaje' => 'Indica el nombre o ID de ' . $relative[1] . ' ' . $relative[2] . '.'];
                }
                $filters[$key] = (int) $reference[$key];
            } elseif (preg_match('/^(cartera|cliente|empresa|grupo) (.+)$/', $tail, $relation)) {
                $key = $relation[1] === 'cliente' ? 'cartera' : $relation[1];
                $value = trim($relation[2], "\"' ");
                if (preg_match('/^(?:id )?#?([1-9][0-9]{0,9})$/', $value, $relationId)
                    && (int) $relationId[1] <= 4294967295) {
                    $filters[$key . '_id'] = (int) $relationId[1];
                } elseif (preg_match('/^(.+?)\s*\(#?([1-9][0-9]{0,9})\)$/', $value, $namedId)
                    && (int) $namedId[2] <= 4294967295) {
                    $filters[$key] = $namedId[1];
                    $filters[$key . '_id'] = (int) $namedId[2];
                } else {
                    $filters[$key] = $value;
                }
            } elseif ($tail !== '' && $tail !== 'en total') {
                if (preg_match('/^(?:id |#)([1-9][0-9]{0,9})$/', $tail, $id)) {
                    $filters['id'] = (int) $id[1];
                } elseif (preg_match('/^rfc\s*[: ]\s*(.+)$/', $tail, $rfc)) {
                    $filters['rfc'] = $rfc[1];
                } elseif (preg_match('/^(?:codigo|clave(?: sat)?)[ :]+(.+)$/', $tail, $code)) {
                    $filters['codigo'] = $code[1];
                } elseif (in_array($catalog, ['unidades','formas','claves','conceptos'], true)
                    && preg_match('/^[a-z\d-]+$/', $tail)
                    && (preg_match('/\d/', $tail) || ($catalog === 'claves' && $match[3] === 'clave corta'))) {
                    $filters['codigo'] = $tail;
                } elseif (in_array($catalog, ['carteras','empresas','grupos','bancos','usuarios','fondeadoras','cuentas-especiales','contactos'], true)
                    && preg_match('/^[1-9][0-9]{0,9}$/', $tail)) {
                    $filters['id'] = (int) $tail;
                } else {
                    $tail = preg_replace('/^(?:nombre|razon social|alias) /', '', $tail) ?? $tail;
                    $tail = preg_replace('/ en (?:su|el) nombre$/', '', $tail) ?? $tail;
                    $filters['nombre'] = trim($tail, "\"' ");
                }
            }
            return ['herramienta' => 'consultar_catalogos', 'filtros' => $filters, 'modo' => 'catalogo'];
        }
        if (preg_match('/^(?:cual es|dime|muestra) la cartera lider (?:del|de el) grupo (.+)$/', $text, $leader)) {
            return self::request('carteras lideres del grupo ' . $leader[1], $reference);
        }
        if (preg_match('/^(?:como|que) notificaciones (?:tiene|usa) (?:la )?cartera (.+)$/', $text, $wallet)) {
            return self::request('cartera ' . $wallet[1], $reference);
        }
        return null;
    }

    private static function configurationRequest(string $text, array $reference): ?array
    {
        $topics = [
            ['carteras','correos_saldos','a que correos se (?:mandan|envian) (?:los )?(?:movimientos y saldos|saldos y movimientos|movimientos|saldos)|correos? de (?:movimientos y saldos|saldos)'],
            ['carteras','correo_facturacion','correo (?:principal )?de facturacion|correo para (?:facturas|complementos)'],
            ['carteras','copias_facturacion','quien recibe copia de las facturas|copias?(?: ocultas?)? de (?:las )?facturas'],
            ['carteras','correo_principal','correo(?: principal)?'],
            ['carteras','notificaciones','como se notifica|como se notifican|notificaciones'],
            ['carteras','comisiones_adicionales','comisiones? adicionales?'],
            ['carteras','comision','que comision tiene configurada|que comision tiene|comision(?: configurada)?'],
            ['carteras','lidera','esta marcada para liderar|marcada para liderar'],
            ['grupos','cartera_padre','cartera padre'],
            ['claves','documentos_vivos','cuantos documentos vivos tiene|documentos vivos(?: de)?'],
            ['claves','facturacion_automatica','facturacion automatica|factura automaticamente'],
            ['carteras','facturacion_automatica','facturacion automatica|factura automaticamente'],
            ['carteras','razon_social','razon social'],
            ['conceptos','iva','iva(?: configurado)?|facturacion automatica'],
            ['bancos','saldo_inicial','saldo inicial'],
            ['usuarios','empresas_permitidas','empresas permitidas|empresas vinculadas'],
        ];
        if (preg_match('/^(.+?) (factura automaticamente|esta marcada para liderar)$/', $text, $reverse)) {
            $text = $reverse[2] . ' de ' . $reverse[1];
        }
        $nouns = ['carteras'=>'(?:cartera|cliente)', 'grupos'=>'grupo', 'claves'=>'clave(?: corta)?',
            'conceptos'=>'concepto(?: facturable)?', 'usuarios'=>'usuario', 'bancos'=>'(?:cuenta bancaria|banco)'];
        $keys = ['carteras'=>'cartera_id', 'grupos'=>'grupo_id', 'claves'=>'clave_corta_id',
            'conceptos'=>'concepto_id', 'usuarios'=>'usuario_id', 'bancos'=>'banco_id'];
        foreach ($topics as [$catalog, $field, $topic]) {
            $noun = $nouns[$catalog];
            $prefix = '(?:(?:solo|solamente) )?(?:(?:cual es|cuales son|dime|dame|consulta|muestra) )?(?:(?:solo|solamente) )?(?:el |la |los |las |una |un )?';
            if (!preg_match("~^{$prefix}(?:{$topic})(?: (?:de|del|para|a|en|tiene)?)?(?: (?:la |el )?({$noun})(?: (.+))?| ((?:esta|este|esa|ese) {$noun}))?$~", $text, $match)) {
                continue;
            }
            $value = trim($match[2] ?? '');
            if ($value === '') {
                $id = $reference[$keys[$catalog]] ?? null;
                if ($id === null) {
                    // Un tema compartido requiere que la pregunta identifique su catálogo.
                    if (empty($match[3]) && in_array($field, ['facturacion_automatica','iva','saldo_inicial'], true)) {
                        continue;
                    }
                    return ['modo' => 'aclarar_catalogo', 'mensaje' => 'Indica el nombre, clave o ID del registro que quieres consultar.'];
                }
                $filters = ['catalogo' => $catalog, 'id' => (int) $id];
            } else {
                $canonical = ['carteras'=>'cartera','grupos'=>'grupo','claves'=>'clave corta','conceptos'=>'concepto','usuarios'=>'usuario','bancos'=>'cuenta bancaria'];
                $parsed = self::request($canonical[$catalog] . ' ' . $value, $reference);
                $filters = $parsed['filtros'] ?? [];
            }
            if (array_intersect_key($filters, ['id'=>true,'nombre'=>true,'codigo'=>true,'rfc'=>true]) !== []) {
                return ['herramienta'=>'consultar_catalogos','modo'=>'catalogo',
                    'filtros'=>['configuracion'=>$field] + $filters];
            }
        }
        return null;
    }

    private static function configurationFields(string $catalog, string $field): string
    {
        $wallet = match ($field) {
            'correo_principal' => 'c.correo AS correo_principal_saldos',
            'correos_saldos' => 'c.correo AS correo_principal_saldos, c.correo_2_s AS correo_saldos_2, c.correo_3_s AS correo_saldos_3',
            'correo_facturacion' => 'c.correo2 AS correo_principal_facturacion',
            'copias_facturacion' => 'c.correo2_2_f AS copia_oculta_1, c.correo2_3_f AS copia_oculta_2',
            'notificaciones' => self::NOTIFICATIONS . ' AS notificaciones',
            'comision' => 'c.comision AS comision, c.esquema_comision AS esquema_comision',
            'comisiones_adicionales' => implode(', ', array_map(static function (int $i): string {
                return "(SELECT CONCAT('#',sub.id,' ',sub.nombre) FROM clientes sub WHERE sub.id=c.`{$i}_subcomision`
                ) AS cartera_comision_{$i}, c.porc_{$i}_subcomision AS porcentaje_comision_{$i}";
            }, [1,2,3])),
            'lidera' => "CASE c.lidera WHEN 1 THEN 'Sí' WHEN 0 THEN 'No' END AS marcada_para_liderar",
            'facturacion_automatica' => 'c.facturacion_automatica AS facturacion_automatica_cartera',
            'razon_social' => 'c.razon_social',
            default => null,
        };
        if ($catalog === 'carteras' && $wallet !== null) {
            return "c.nombre, IF(c.activo=1,'Activa','Inactiva') AS estado, {$wallet}";
        }
        return match ([$catalog, $field]) {
            ['grupos','cartera_padre'] => "t.nombre, CASE WHEN c.id IS NOT NULL THEN CONCAT('#',c.id,' ',c.nombre) END AS cartera_padre",
            ['claves','documentos_vivos'] => 't.razon_social AS nombre, t.clave_corta, c.nombre AS cartera, t.documentos_vivos',
            ['claves','facturacion_automatica'] => 't.razon_social AS nombre, t.clave_corta, c.nombre AS cartera,
                t.facturacion_automatica AS facturacion_automatica_clave, c.facturacion_automatica AS facturacion_automatica_cartera',
            ['conceptos','iva'] => 't.concepto AS nombre, e.razon AS empresa, t.aplica_iva,
                t.impuesto AS porcentaje_iva, t.objeto_impuesto, t.facturable_auto AS facturacion_automatica',
            ['bancos','saldo_inicial'] => 't.nombre_corto AS nombre, e.razon AS empresa, t.saldo_inicial, t.moneda',
            ['usuarios','empresas_permitidas'] => "TRIM(CONCAT_WS(' ',t.nombre,t.appat,t.apmat)) AS nombre,
                c.nombre AS cartera, COALESCE((SELECT GROUP_CONCAT(DISTINCT CONCAT_WS(' ',CONCAT('#',ce.id_empresa),ex.razon)
                    ORDER BY ex.razon SEPARATOR ', ') FROM cliente_empresa ce LEFT JOIN empresas ex ON ex.id=ce.id_empresa
                    WHERE ce.id_usuario=t.id AND ce.id_empresa>0),
                    IF(t.empresa_asignada>0,CONCAT_WS(' ',CONCAT('#',t.empresa_asignada),e.razon),NULL)) AS empresas_permitidas",
            default => throw new ChatException('Esa configuración no corresponde al catálogo solicitado.'),
        };
    }

    private static function phoneRequest(string $text, array $reference): ?array
    {
        $ask = '(?:(?:cual es|que|dime|dame|muestra|muestrame|consulta|busca|me puedes dar) )?(?:el )?telefono';
        $relation = '(?: (?:asignado|registrado))? (?:de|del|a|en|tiene)? ?';
        if (preg_match("~^{$ask}{$relation}(?:esta|esa) cartera$~", $text)) {
            $selection = isset($reference['cartera_id'])
                ? ['filtros' => ['catalogo' => 'carteras', 'id' => (int) $reference['cartera_id']], 'modo' => 'catalogo', 'herramienta' => 'consultar_catalogos']
                : null;
        } elseif (preg_match("~^{$ask}{$relation}(?:la |el )?(?:cartera|cliente) (.+)$~", $text, $match)
            || preg_match('~^(?:la |el )?(?:cartera|cliente) (.+?) (?:que telefono tiene|tiene telefono)$~', $text, $match)) {
            $selection = self::request('cartera ' . $match[1], $reference);
        } else {
            return null;
        }
        if ($selection === null || array_intersect_key($selection['filtros'] ?? [], ['id'=>true,'nombre'=>true,'rfc'=>true]) === []) {
            return ['modo' => 'aclarar_catalogo', 'mensaje' => 'Indica el nombre o ID de la cartera cuyo teléfono quieres consultar.'];
        }
        $selection['filtros']['telefono'] = true;
        return $selection;
    }

    public static function reply(array $request, array $result): string
    {
        $type = self::NAMES[$result['catalogo']];
        $summary = $result['resumen_carteras'] ?? null;
        $configuration = $result['configuracion'] ?? null;
        $filters = $request['filtros'];
        $showUnset = $configuration !== null || array_intersect_key($filters, ['id'=>true,'codigo'=>true,'rfc'=>true]) !== [];
        if (isset($filters['estado'])) {
            $type .= match ($filters['estado']) {
                'activo' => ' activas',
                'inactivo' => ' inactivas',
            };
        }
        if (!empty($filters['panel'])) {
            $type .= ' visibles en el panel principal';
        }
        if ($summary === null && isset($result['totales'])) {
            return 'Total de ' . $type . ' en tu alcance: ' . number_format($result['totales']['registros']) . '.';
        }
        if ($result['filas'] === []) {
            return $request['modo'] === 'pagina' ? 'No hay más ' . $type . ' en ese listado.' : ChatQueries::NOT_FOUND;
        }
        if ($configuration === 'correo_principal') {
            if ($result['pagina'] === 1 && count($result['filas']) === 1 && !$result['hay_mas']) {
                return trim((string) ($result['filas'][0]['correo_principal_saldos'] ?? '')) ?: 'Sin configurar';
            }
            $choices = array_map(static fn (array $row): string => $row['nombre'] . ' (#' . $row['id'] . ')', $result['filas']);
            return "Encontré varias carteras. Indica el ID de la que quieres consultar.\n" . implode("\n", $choices);
        }
        if (!empty($result['detalle_telefono'])) {
            $lines = [];
            foreach ($result['filas'] as $row) {
                $lines[] = $row['nombre'] . ' (#' . (int) $row['id'] . ') · ' . $row['estado'];
                $lines[] = 'Teléfono registrado · ' . ($row['telefono'] ?? 'Sin configurar');
            }
            if ($result['hay_mas']) {
                $lines[] = 'Escribe «siguiente página» para ver más coincidencias.';
            }
            return implode("\n", $lines);
        }
        $labels = ['rfc'=>'RFC','cp'=>'CP','clave_sat'=>'Clave SAT','lider'=>'Líder','lider_id'=>'ID líder',
            'regimen_fiscal'=>'Régimen fiscal','aplica_iva'=>'Aplica IVA','porcentaje_iva'=>'IVA (%)',
            'facturacion_automatica'=>'Facturación automática','cartera_id'=>'ID cartera','empresa_id'=>'ID empresa',
            'grupo_id'=>'ID grupo','banco_id'=>'ID cuenta bancaria','clabe'=>'CLABE','codigo'=>'Clave',
            'minuto_ejecucion'=>'Minuto programado','saldo_acreditable'=>'Saldo acreditable',
            'razon_social'=>'Razón social','comision'=>'Comisión (%)','esquema_comision'=>'Esquema de comisión',
            'facturacion_automatica_clave'=>'Facturación automática de la clave',
            'facturacion_automatica_cartera'=>'Facturación automática de la cartera','marcada_para_liderar'=>'Marcada para liderar'];
        $position = ($result['pagina'] - 1) * $result['limite'];
        $lines = [ucfirst($type) . ' · Página ' . $result['pagina']];
        foreach ($result['filas'] as $row) {
            $parts = [(trim((string) ($row['nombre'] ?? '')) ?: 'Sin nombre') . ' (#' . (int) $row['id'] . ')'];
            foreach (array_diff_key($row, ['id'=>true,'nombre'=>true]) as $key => $value) {
                $value = is_string($value) ? trim($value) : $value;
                if ($value === null || $value === '') {
                    $value = $showUnset ? 'Sin configurar' : null;
                }
                if ($value !== null) {
                    $formatted = $key === 'saldo_acreditable' && $summary !== null ? self::amount($value, $summary) : $value;
                    $parts[] = ($labels[$key] ?? ucfirst(str_replace('_', ' ', $key))) . ' ' . $formatted;
                }
            }
            $lines[] = ++$position . '. ' . implode(' · ', $parts);
        }
        if ($configuration === 'correo_facturacion') {
            $lines[] = 'Configuración del contacto para facturas y complementos. Si el correo está sin configurar, ese flujo omite el envío.';
        } elseif ($configuration === 'documentos_vivos') {
            $lines[] = 'Conteo precalculado del cron para la clave corta.';
        } elseif ($configuration === 'saldo_inicial') {
            $lines[] = 'Saldo de arranque configurado. Para el saldo actual, consulta el estado de cuenta.';
        } elseif ($configuration === 'empresas_permitidas') {
            $lines[] = 'Asignaciones registradas en el catálogo. El chat permite consultar todas las empresas dentro de los módulos y carteras de cada rol.';
        } elseif ($configuration !== null) {
            array_unshift($lines, 'Configuración registrada en Sistema 14');
        }
        if ($result['hay_mas']) {
            $lines[] = 'Escribe «siguiente página» para ver más.';
        }
        if ($summary !== null && $summary['registros'] > 1) {
            $lines[] = '';
            if ($summary['activas'] > 0 && $summary['activas'] < $summary['registros']) {
                $lines[] = 'Saldo conjunto de carteras activas (' . number_format($summary['activas']) . ') · '
                    . self::amount($summary['saldo_activas'], $summary);
            }
            $wallets = number_format($summary['registros']) . ' carteras'
                . ($summary['activas'] === $summary['registros'] ? ' activas' : '');
            $lines[] = 'Saldo conjunto de todas las coincidencias (' . $wallets . ') · '
                . self::amount($summary['saldo_acreditable'], $summary);
        }
        return implode("\n", $lines);
    }

    private static function amount(string|int|float $value, array $summary): string
    {
        return number_format((float) $value, 2, '.', ',') . ' ' . $summary['moneda'];
    }

    public static function properties(): array
    {
        return [
            'catalogo' => ['type' => 'string', 'enum' => array_keys(self::NAMES)],
            'id' => ['type' => 'integer', 'description' => 'ID interno del registro, distinto de la clave o código SAT'],
            'nombre' => ['type' => 'string', 'description' => 'Texto en el nombre, razón social o alias según el catálogo'],
            'codigo' => ['type' => 'string', 'description' => 'Clave exacta del catálogo: clave corta, clave SAT de concepto, unidad o forma de pago'],
            'rfc' => ['type' => 'string', 'description' => 'RFC exacto'],
            'cartera_id' => ['type' => 'integer', 'description' => 'ID de la cartera relacionada'],
            'cartera' => ['type' => 'string', 'description' => 'Nombre de la cartera relacionada'],
            'empresa_id' => ['type' => 'integer', 'description' => 'ID de la empresa relacionada'],
            'empresa' => ['type' => 'string', 'description' => 'Nombre de la empresa relacionada'],
            'grupo_id' => ['type' => 'integer', 'description' => 'ID del grupo al que pertenece la cartera'],
            'grupo' => ['type' => 'string', 'description' => 'Nombre del grupo al que pertenece la cartera'],
            'moneda' => ['type' => 'string', 'description' => 'Código de moneda de la cuenta bancaria'],
            'estado' => ['type' => 'string', 'enum' => ['activo', 'inactivo']],
            'panel' => ['type' => 'boolean', 'description' => 'Solo carteras visibles en el panel principal'],
            'configuracion' => ['type' => 'string', 'enum' => ['correo_principal','correos_saldos','correo_facturacion',
                'copias_facturacion','notificaciones','comision','comisiones_adicionales','lidera','cartera_padre','razon_social',
                'facturacion_automatica','documentos_vivos','iva','saldo_inicial','empresas_permitidas']],
            'telefono' => ['type' => 'boolean', 'description' => 'Devuelve el teléfono de la cartera solo cuando se solicita expresamente. Requiere ID, nombre o RFC.'],
            'lider' => ['type' => 'boolean', 'description' => 'Solo carteras marcadas como líderes o designadas como cartera padre del grupo'],
        ];
    }

    public static function query(array $filters, ChatAccess $access, array &$params): string
    {
        $catalog = $filters['catalogo'];
        $account = self::masked('b.numero_cuenta');
        $clabe = self::masked('b.clabe_interbancaria');
        $wallet = 'LEFT JOIN clientes c ON c.id = ';
        $bank = 'LEFT JOIN bancos b ON b.id = ';
        $company = 'LEFT JOIN empresas e ON e.id = ';
        $walletFilters = ['cartera_id' => 'c.id', 'cartera' => 'c.nombre'];
        $paymentCode = "CASE WHEN CHAR_LENGTH(CAST(t.clave AS CHAR))=1 THEN CONCAT('0',t.clave) ELSE CAST(t.clave AS CHAR) END";
        $companyFilters = ['empresa_id' => 'e.id', 'empresa' => 'e.razon'];
        [$fields, $joins, $columns] = match ($catalog) {
            'carteras' => [
                "c.nombre, c.rfc, IF(c.activo=1,'Activa','Inactiva') AS estado,
                 g.id AS grupo_id, g.nombre AS grupo, " . self::NOTIFICATIONS . " AS notificaciones,
                 IF(c.lidera=1,'Sí','No') AS marcada_para_liderar, e.id AS empresa_id, e.razon AS empresa",
                "LEFT JOIN grupo g ON g.id=c.grupo {$company}c.empresa",
                ['nombre'=>'c.nombre','rfc'=>'c.rfc','estado'=>'IF(c.activo=1,1,0)','grupo_id'=>'g.id','grupo'=>'g.nombre'] + $companyFilters,
            ],
            'claves' => [
                't.clave_corta, t.razon_social AS nombre, t.rfc, c.id AS cartera_id, c.nombre AS cartera,
                 t.regimen_fiscal, t.cp, t.facturacion_automatica, t.documentos_vivos',
                $wallet . 't.id_cliente',
                ['nombre'=>'t.razon_social','codigo'=>'t.clave_corta','rfc'=>'t.rfc'] + $walletFilters,
            ],
            'grupos' => [
                't.nombre, c.id AS cartera_padre_id, c.nombre AS cartera_padre, b.nombre_corto AS cuenta_salida,
                 (SELECT COUNT(*) FROM clientes miembro WHERE miembro.grupo=t.id) AS carteras,
                 (SELECT COUNT(*) FROM clientes miembro WHERE miembro.grupo=t.id AND miembro.activo=1) AS carteras_activas',
                $wallet . 't.cliente_padre ' . $bank . 't.cuenta_salida',
                ['nombre'=>'t.nombre'],
            ],
            'empresas' => [
                't.razon AS nombre, t.rfc, t.regimen AS regimen_fiscal, t.cp',
                '', ['nombre'=>'t.razon','rfc'=>'t.rfc'],
            ],
            'bancos' => [
                "t.nombre_corto AS nombre, t.banco, t.moneda, e.id AS empresa_id, e.razon AS empresa,
                 CASE t.status_banco WHEN 'AC' THEN 'Activa' WHEN 'IN' THEN 'Inactiva' ELSE 'Sin definir' END AS estado,
                 " . self::masked('t.numero_cuenta') . ' AS cuenta, ' . self::masked('t.clabe_interbancaria') . ' AS clabe',
                $company . 't.razon',
                ['nombre'=>"CONCAT_WS(' ',t.nombre_corto,t.banco)",'moneda'=>'t.moneda','estado'=>"CASE t.status_banco WHEN 'AC' THEN 1 WHEN 'IN' THEN 0 END"] + $companyFilters,
            ],
            'conceptos' => [
                't.concepto AS nombre, t.clave_sat, t.clave_unidad_medida, u.unidad,
                 t.aplica_iva, t.impuesto AS porcentaje_iva, t.objeto_impuesto, t.facturable_auto AS facturacion_automatica,
                 e.id AS empresa_id, e.razon AS empresa',
                $company . 't.razon LEFT JOIN (SELECT clave_unidad_medida, MIN(nombre_unidad_medida) AS unidad
                    FROM catalogo_unidad_medida GROUP BY clave_unidad_medida) u ON u.clave_unidad_medida=t.clave_unidad_medida',
                ['nombre'=>'t.concepto','codigo'=>'t.clave_sat'] + $companyFilters,
            ],
            'unidades' => [
                't.clave_unidad_medida AS codigo, t.nombre_unidad_medida AS nombre',
                '', ['codigo'=>'t.clave_unidad_medida','nombre'=>'t.nombre_unidad_medida'],
            ],
            'formas' => [
                "{$paymentCode} AS codigo, t.forma_pago AS nombre",
                '', ['codigo'=>$paymentCode,'nombre'=>'t.forma_pago'],
            ],
            'usuarios' => [
                "TRIM(CONCAT_WS(' ',t.nombre,t.appat,t.apmat)) AS nombre, t.tipo AS rol, c.id AS cartera_id, c.nombre AS cartera,
                 e.id AS empresa_id, e.razon AS empresa,
                 (SELECT GROUP_CONCAT(DISTINCT CONCAT('#',ex.id,' ',ex.razon) ORDER BY ex.razon SEPARATOR ', ')
                    FROM cliente_empresa ce JOIN empresas ex ON ex.id=ce.id_empresa WHERE ce.id_usuario=t.id) AS empresas_vinculadas",
                $wallet . 't.cliente ' . $company . 't.empresa_asignada',
                ['nombre'=>"CONCAT_WS(' ',t.nombre,t.appat,t.apmat,t.usuario)"] + $walletFilters + $companyFilters,
            ],
            'fondeadoras' => [
                "b.nombre_corto AS nombre, b.id AS banco_id, {$account} AS cuenta, {$clabe} AS clabe,
                 e.id AS empresa_id, e.razon AS empresa, t.minuto_ejecucion",
                $bank . 't.id_empresa ' . $company . 'b.razon',
                ['nombre'=>'b.nombre_corto'] + $companyFilters,
            ],
            'cuentas-especiales' => [
                't.nombre, c.id AS cartera_id, c.nombre AS cartera, b.nombre_corto AS banco, '
                    . self::masked('t.cuenta') . ' AS cuenta, e.id AS empresa_id, e.razon AS empresa',
                $wallet . 't.id_cliente ' . $bank . 't.banca ' . $company . 'b.razon',
                ['nombre'=>'t.nombre'] + $walletFilters + $companyFilters,
            ],
            'contactos' => [
                't.nombre, t.alias, c.id AS cartera_id, c.nombre AS cartera, '
                    . self::masked('t.numero') . ' AS cuenta, ' . self::masked('t.clabe_inter') . ' AS clabe',
                $wallet . 't.cliente',
                ['nombre'=>"CONCAT_WS(' ',t.nombre,t.alias)"] + $walletFilters,
            ],
        };

        if (isset($filters['configuracion'])) {
            if (($filters['contar'] ?? false) || array_intersect_key($filters, ['id'=>true,'nombre'=>true,'codigo'=>true,'rfc'=>true]) === []) {
                throw new ChatException('Identifica el registro cuya configuración quieres consultar.');
            }
            $fields = self::configurationFields($catalog, $filters['configuracion']);
        }
        if ($filters['telefono'] ?? false) {
            self::requireWallets($catalog, 'El teléfono de cartera solo está disponible en el catálogo de carteras.');
            if (($filters['contar'] ?? false) || array_intersect_key($filters, ['id'=>true,'nombre'=>true,'rfc'=>true]) === []) {
                throw new ChatException('Indica el nombre, ID o RFC de la cartera cuyo teléfono quieres consultar.');
            }
            $fields = "c.nombre, IF(c.activo=1,'Activa','Inactiva') AS estado,
                NULLIF(TRIM(REPLACE(REPLACE(c.telefono, CHAR(13), ' '), CHAR(10), ' ')), '') AS telefono";
        }
        $alias = $catalog === 'carteras' ? 'c' : 't';
        $columns += ['id' => "{$alias}.id"];
        $where = [];
        if ($filters['panel'] ?? false) {
            self::requireWallets($catalog, 'El filtro del panel solo corresponde a carteras.');
            $where[] = $access->walletConditions($params);
        }
        if ($filters['lider'] ?? false) {
            self::requireWallets($catalog, 'El filtro de cartera líder solo corresponde a carteras.');
            $where[] = '(c.lidera=1 OR g.cliente_padre=c.id)';
        }
        foreach (array_intersect_key($filters, self::properties()) as $key => $value) {
            if (in_array($key, ['catalogo','panel','lider','telefono','configuracion'], true)) {
                continue;
            }
            $column = $columns[$key] ?? throw new ChatException('Ese filtro no corresponde a ' . self::NAMES[$catalog] . '.');
            $condition = match ($key) {
                'nombre', 'cartera', 'empresa', 'grupo' => ChatQueries::contains("LOWER({$column})", mb_strtolower($value), $params),
                'estado' => $column . ' = ' . ChatAccess::bind($params, (int) ($value === 'activo')),
                'codigo', 'rfc', 'moneda' => "UPPER(TRIM({$column})) = "
                    . ChatAccess::bind($params, $catalog === 'formas' && $key === 'codigo' && ctype_digit($value)
                        ? str_pad($value, 2, '0', STR_PAD_LEFT) : mb_strtoupper($value)),
                default => $column . ' = ' . ChatAccess::bind($params, $value),
            };
            if ($catalog === 'usuarios' && in_array($key, ['empresa', 'empresa_id'], true)) {
                $linked = $key === 'empresa'
                    ? ChatQueries::contains('LOWER(ex.razon)', mb_strtolower($value), $params)
                    : 'ex.id = ' . ChatAccess::bind($params, $value);
                $condition = "({$condition} OR EXISTS (SELECT 1 FROM cliente_empresa ce
                    JOIN empresas ex ON ex.id=ce.id_empresa WHERE ce.id_usuario=t.id AND {$linked}))";
            }
            $where[] = $condition;
        }
        $table = Catalogs::get($catalog)['table'];
        return "SELECT {$alias}.id, {$fields} FROM {$table} {$alias} {$joins} WHERE "
            . ($where === [] ? '1=1' : implode(' AND ', $where));
    }

    private static function masked(string $column): string
    {
        $value = "REGEXP_REPLACE(COALESCE({$column},''), '[^0-9]', '')";
        return "CASE WHEN LENGTH({$value}) > 4 THEN CONCAT('•••• ', RIGHT({$value},4))
            WHEN LENGTH({$value}) > 0 THEN '••••' END";
    }

    private static function requireWallets(string $catalog, string $message): void
    {
        if ($catalog !== 'carteras') {
            throw new ChatException($message);
        }
    }
}

