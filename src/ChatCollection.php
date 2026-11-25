<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

final class ChatCollection
{
    private const RESOURCES = [
        'buscar_facturas' => ['facturas?', 'Factura', 'facturas'],
        'buscar_complementos' => ['complementos?(?: de pago)?', 'Complemento', 'complementos'],
        'consultar_stp' => ['(?:pagos?|orden(?:es)?|transaccion(?:es)?|transferencias?) (?:de )?stp', 'Orden STP', 'órdenes STP'],
        'consultar_traspasos' => ['traspasos?(?: entre carteras)?', 'Traspaso', 'traspasos'],
        'consultar_banco' => ['(?:lineas? bancarias?|movimientos? bancarios?)', 'Línea bancaria', 'líneas bancarias'],
        'consultar_pagos' => ['(?:pagos?|movimientos?(?: contables?)?)', 'Movimiento contable', 'movimientos contables'],
    ];

    public static function request(
        string $text, array $previous, ?string $lastTool = null, ?array $pendingStp = null, ?int $recentInvoice = null, array $reference = []
    ): ?array
    {
        $text = preg_replace('/^\h*\d+[.)]\h*/m', '', $text) ?? $text;
        $walletBalance = self::walletBalanceRequest($text);
        if ($walletBalance !== null) {
            return $walletBalance;
        }
        $text = strtr(mb_strtolower($text), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            '¿' => '', '?' => '', '¡' => '', '!' => '',
        ]);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text, " .\t\n\r");
        if (isset($pendingStp['herramienta']) && preg_match('/^(?:si[, ]+)?(?:el pago (?:de |por )?|la transferencia (?:de |por )?)?stp$/', $text)) {
            return $pendingStp;
        }
        if ($lastTool === 'consultar_catalogos' && ($previous['herramienta'] ?? null) === $lastTool
            && preg_match('/^(?:y )?(?:cuant[oa]s? (?:hay|son|existen)(?: en total)?|cuent[aa](?:las|los))$/', $text)) {
            $filters = array_diff_key($previous['filtros'], ['pagina'=>true,'limite'=>true]);
            return self::make($lastTool, ['contar'=>true] + $filters, 'catalogo');
        }
        if (isset($previous['herramienta'])) {
            $page = null;
            if (in_array($text, ['siguiente pagina', 'muestra la siguiente pagina', 'pagina siguiente', 'siguiente',
                'muestra mas', 'ver mas', 'y las anteriores', 'y los anteriores', 'y la anterior', 'y el anterior'], true)) {
                $page = $previous['pagina'] + 1;
            } elseif (preg_match('/^pagina ([1-9][0-9]{0,4})$/', $text, $pageNumber)) {
                $page = (int) $pageNumber[1];
            }
            if ($page !== null) {
                $filters = $previous['filtros'];
                $filters['limite'] = $previous['limite'];
                $filters['pagina'] = $page;
                return self::make($previous['herramienta'], $filters, 'pagina');
            }
        }

        $catalog = ChatCatalogs::request($text, $reference);
        if ($catalog !== null) {
            return $catalog;
        }

        $period = [];
        if (preg_match('/ (de hoy|de ayer|de esta semana|de este mes|del mes pasado)$/', $text, $suffix)) {
            $period = self::period($suffix[1]);
            $text = substr($text, 0, -strlen($suffix[0]));
        }

        $company = [];
        if (preg_match('/ (?:de|en|para) (?:la )?empresa #?([1-9][0-9]{0,9})$/', $text, $companyMatch)
            && (int) $companyMatch[1] <= 4294967295) {
            $company = ['empresa_id' => (int) $companyMatch[1]];
            $text = substr($text, 0, -strlen($companyMatch[0]));
        }

        $sat = self::invoiceSatRequest($text, $period + $company, $lastTool, $recentInvoice);
        if ($sat !== null) {
            return $sat;
        }

        if (preg_match('/^(?:que|cuales) depositos (?:siguen|estan|quedaron) pendientes (de conciliar|de documentar|sin documento)$/', $text, $pending)) {
            $mode = $pending[1] === 'de conciliar' ? 'pendientes_conciliar' : 'pendientes_deposito';
            return self::make('consultar_pagos', ['pendientes_documento' => true] + $period + $company, $mode);
        }
        if (preg_match('/^(?:que|cuales) proveedores (?:quedaron|estan|salieron) fuera de rango(?: en banking)?$/', $text)) {
            return self::make('generar_reporte', ['reporte' => 'banking'] + $period + $company, 'lista');
        }
        if (preg_match('/^(?:ya )?se envio el correo de la factura (?:#|(?:con )?id )([1-9][0-9]{0,9})$/', $text, $mail)
            && (int) $mail[1] <= 4294967295) {
            return self::make('consultar_correos', [
                'tipo_correo' => 'factura', 'factura_id' => (int) $mail[1], 'limite' => 1,
            ] + $period + $company, 'correo_factura');
        }

        $transfer = self::RESOURCES['consultar_traspasos'][0];
        if (preg_match("~^(?:(?:consulta(?:r)?|muestra|busca|ver) )?(?:el )?{$transfer} (?:#|(?:con )?id )([1-9][0-9]{0,9})$~", $text, $movement)
            && (int) $movement[1] <= 4294967295) {
            return self::make('consultar_traspasos', ['id' => (int) $movement[1], 'limite' => 1] + $period + $company, 'uno');
        }
        if (preg_match("~^(?:de donde a donde fue|entre que carteras fue) el ultimo {$transfer}$~", $text)) {
            return self::make('consultar_traspasos', ['limite' => 1] + $period + $company, 'uno');
        }
        if (preg_match("~^(?:(?:que|cuales) {$transfer} (?:hubo|hay|se hicieron)|{$transfer})$~", $text)) {
            return self::make('consultar_traspasos', $period + $company, 'lista');
        }

        $mentionsStp = preg_match('/\bstp\b/', $text);
        if ($mentionsStp) {
            $text = preg_replace('/ con (?:(?:su|el) )?estado,? (?:(?:su|el) )?comprobante (?:e|y) (?:(?:sus|las) )?incidencias$/', '', $text) ?? $text;
        }
        $aboutRecentStp = ($lastTool ?? ($previous['herramienta'] ?? null)) === 'consultar_stp'
            && !preg_match('/\b(?:facturas?|complementos?|reportes?|traspasos?|bancari[oa]s?)\b/', $text);
        $lookup = self::stpLookupRequest($text, $aboutRecentStp, $pendingStp);
        if ($lookup !== null) {
            if (isset($lookup['filtros'])) {
                $lookup['filtros'] = $company + $lookup['filtros'];
            } else {
                $lookup['consulta']['filtros'] = $company + ($pendingStp['filtros'] ?? []);
            }
            return $lookup;
        }
        $latestStp = self::latestStpRequest($text, $period + $company, $aboutRecentStp);
        if ($latestStp !== null) {
            return $latestStp;
        }
        if ($mentionsStp || $aboutRecentStp) {
            $request = self::stpRequest($text, $period);
            if ($request !== null) {
                $request['filtros'] += $company;
                return $request;
            }
        }

        $article = '(?:la |el |las |los |un |una )?';
        $show = '(?:(?:cual es|cual fue|cuales son|dime|dame|muestra|muestrame|busca|consulta|ver) )?';
        $list = '(?:lista|listame|listar|enumera|enumerame|enumerar|muestra|muestrame|dame|consulta|ver)';
        foreach (self::RESOURCES as $tool => [$noun]) {
            if (preg_match("~^{$show}{$article}(?:(?:ultim[oa]|primer[oa]) {$noun}|{$noun} mas (?:reciente|antigu[oa]))$~", $text)) {
                $order = preg_match('~(?:mas antigu[oa]|primer[oa])~', $text) ? 'antiguos' : 'recientes';
                return self::make($tool, ['limite' => 1, 'orden' => $order] + $period + $company, 'uno');
            }
            if (preg_match("~^(?:(?:cuant[oa]s) {$noun}(?: hay| tengo| tenemos| existen| se han registrado)?|"
                . "(?:cuenta|cuentame|contar|numero de|total de) {$article}{$noun})(?: en total)?$~", $text)) {
                return self::make($tool, ['contar' => true] + $period + $company, 'conteo');
            }
            $pattern = "~^{$list} (?:tod[oa]s )?{$article}(?:(ultim[oa]s|primer[oa]s) )?(?:(\d{1,6}) )?{$noun}(?: (?:mas )?(recientes|antigu[oa]s))?$~";
            if (preg_match($pattern, $text, $matches)) {
                $order = str_starts_with($matches[1] ?? '', 'primer') || str_starts_with($matches[3] ?? '', 'antigu')
                    ? 'antiguos' : 'recientes';
                $quantity = (int) (($matches[2] ?? '') ?: ChatQueries::DEFAULT_LIMIT);
                return self::make($tool, ['limite' => min(ChatQueries::MAX_LIMIT, max(1, $quantity)), 'orden' => $order] + $period + $company, 'lista');
            }
        }
        return null;
    }

    private static function invoiceSatRequest(string $text, array $filters, ?string $lastTool, ?int $recentInvoice): ?array
    {
        $text = preg_replace('/\s+en (?:el )?sat$/', '', $text) ?? $text;
        $text = preg_replace('/^(?:verifica|revisa|confirma|valida|consulta) si /', '', $text) ?? $text;
        $state = '(?:vigentes?|activ[oa]s?|cancelad[oa]s?)';
        $current = '(?:aun |todavia )?(?:sigue[n]?|esta[n]?|es|son) (?:aun |todavia )?';
        if ($recentInvoice !== null && in_array($lastTool, ['buscar_facturas', 'detalle_factura'], true)
            && preg_match("~^(?:y )?{$current}{$state}$~", $text)) {
            return self::make('consultar_estatus_sat', ['referencias' => 'id:' . $recentInvoice], 'estatus_sat');
        }
        $subject = '(?:la |las |una )?(?:(ultima[s]?(?: \d{1,3})?) )?facturas?';
        $status = '(?:estatus|estado|vigencia)(?: fiscal)?(?: (?:en (?:el )?)?sat)?';
        $prefix = "(?:(?:cual es|cuales son|dime|dame|consulta|consultar|muestra|verifica|revisa|valida) (?:el )?)?(?:{$status}(?: de)?|(?:que|cual) {$status} tiene[n]?)";
        $patterns = [
            "~^{$prefix} {$subject}(?:[: ]+(.+))?$~",
            "~^{$current}{$state} {$subject}(?:[: ]+(.+))?$~",
            "~^{$subject}(?:[: ]+(.+?))? {$current}{$state}$~",
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $match)) {
                continue;
            }
            $tail = preg_replace('/^con /', '', trim($match[2] ?? ''));
            if (in_array($tail, ['mas reciente', 'mas recientes'], true)) {
                $filters += ['limite' => $tail === 'mas reciente' ? 1 : 5, 'orden' => 'recientes'];
            } elseif ($tail !== '') {
                if (ChatQueries::satReferences($tail) === null) {
                    return null;
                }
                $filters['referencias'] = $tail;
            } elseif (!empty($match[1])) {
                $parts = explode(' ', $match[1]);
                $filters += ['limite' => isset($parts[1]) ? (int) $parts[1] : ($parts[0] === 'ultima' ? 1 : 5), 'orden' => 'recientes'];
            }
            return self::make('consultar_estatus_sat', $filters, 'estatus_sat');
        }
        return null;
    }

    private static function walletBalanceRequest(string $text): ?array
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/^[\s¿¡]+|[\s.?!]+$/u', '', $text) ?? $text;
        $balance = '(?:cu[aá]n(?:to|do)(?:\s+saldo)?\s+(?:tiene|hay en)|(?:cu[aá]l es|dime|dame|consulta|muestra|calcula) el saldo(?: total)?(?: de)?|saldo(?: total)?(?: de)?)';
        if (preg_match("~^{$balance} (?:el |del )?grupo\s+(.+)$~iu", $text, $group)) {
            $name = preg_replace('/^(?:de\s+|#\s*|id\s+#?)/iu', '', $group[1]);
            $filters = [];
            if (preg_match('/^(?:([1-9][0-9]{0,9})|(.+?)\s*\(\s*([1-9][0-9]{0,9})\s*\))$/u', $name, $match)) {
                $id = (int) ($match[1] ?: $match[3]);
                if ($id <= 4294967295) {
                    $filters['grupo_id'] = $id;
                    $name = $match[2] ?? '';
                }
            }
            if ($name !== '') {
                $filters['grupo_nombre'] = preg_replace('/^[\s"\'«]+|[\s"\'»]+$/u', '', $name);
            }
            return self::make('consultar_saldos_carteras', $filters, 'saldo_carteras');
        }

        $sum = "(?:suma(?:me|r)?|totaliza|calcula|{$balance})(?: el saldo de)?";
        if (preg_match("~^{$sum} (?:todas )?(?:las )?carteras activas(?: ((?:del|en el|visibles en el) panel(?: principal)?))?$~iu", $text, $active)) {
            return self::make('consultar_saldos_carteras', ['todas_activas' => true, 'panel' => !empty($active[1])], 'saldo_carteras');
        }
        if (preg_match("~^{$sum} (?:todas )?(?:las )?car(?:teras|teas) (?:que (?:tengan|tienen|contengan|contienen)|con) (.+?)(?: en (?:su|el) nombre)?$~iu", $text, $wallet)) {
            return self::make('consultar_saldos_carteras', ['cartera_nombre' => preg_replace('/^[\s"\'«]+|[\s"\'»]+$/u', '', $wallet[1])], 'saldo_carteras');
        }
        return null;
    }

    private static function stpLookupRequest(string $text, bool $aboutRecentStp, ?array $pendingStp): ?array
    {
        $identifier = '(?=[a-z0-9-]*\d)[a-z0-9][a-z0-9-]{0,39}';
        $subject = '(?:orden|transaccion|transferencia|pago) (?:de )?stp';
        $verb = '(?:consultar?|buscar?|ver|muestra)';
        $selector = '(folio|id(?: interno)?)';
        $lookup = "~^(?:{$verb} )?(?:(?:una |la |el )?{$subject}(?: (?:por|con) (?:el )?{$selector})?|"
            . "{$selector} (?:de )?stp)(?:[:# ]+({$identifier}))?$~";
        if (preg_match($lookup, $text, $match)) {
            $criterion = ($match[1] ?? '') ?: ($match[2] ?? '');
            $value = $match[3] ?? '';
            return $value === '' ? self::stpIdentifierPrompt($criterion) : self::stpIdentifier($value, $criterion);
        }

        $waiting = ($pendingStp['modo'] ?? null) === 'esperar_identificador_stp';
        if (($aboutRecentStp || $waiting) && preg_match("~^(?:{$selector}[:# ]+)?#?({$identifier})$~", $text, $match)) {
            $criterion = $match[1] ?: ($waiting ? $pendingStp['criterio'] : '');
            $request = self::stpIdentifier($match[2], $criterion, $match[1] !== '');
            if ($waiting && isset($request['filtros'])) {
                $request['filtros'] += $pendingStp['filtros'] ?? [];
            }
            return $request;
        }
        return null;
    }

    private static function stpIdentifierPrompt(string $criterion): array
    {
        return ['modo' => 'pedir_identificador_stp', 'consulta' => [
            'modo' => 'esperar_identificador_stp', 'criterio' => $criterion,
        ]];
    }

    private static function stpIdentifier(string $value, string $criterion, bool $explicit = true): array
    {
        if (str_starts_with($criterion, 'id')) {
            if (!ctype_digit($value) || (int) $value <= 0 || (int) $value > 4294967295) {
                return self::stpIdentifierPrompt('id');
            }
            $filters = ['id' => (int) $value];
        } elseif (ctype_digit($value) && !($explicit && $criterion === 'folio') && strlen($value) <= 20) {
            $filters = ['referencia_stp' => $value];
        } else {
            $filters = ['folio' => strtoupper($value)];
        }
        return self::make('consultar_stp', $filters, 'buscar_identificador_stp');
    }

    private static function latestStpRequest(string $text, array $period, bool $aboutRecentStp): ?array
    {
        $stp = self::RESOURCES['consultar_stp'][0];
        $subject = "(?:{$stp}|pago|orden|transaccion|transferencia)";
        $latest = "(?:(?:la |el )?ultim[oa] {$subject}|(?:la |el )?{$subject} mas reciente)";
        $outcome = '(?:salio bien|fue exitos[oa]|se completo correctamente|se realizo sin problemas)';
        $completed = 'se (?:concreto|liquido|completo|realizo|ejecuto|hizo)';
        $problem = '(?:algun |alguna )?(?:problemas?|errores?|incidencias?|fallas?)';

        $mode = match (true) {
            (bool) preg_match("~^(?:y )?(?:(?:hubo|hay|se registro|se reporto) {$problem} (?:con|en) {$latest}|{$latest} (?:tuvo|presento|dio) {$problem})$~", $text)
                => 'incidencia_ultima_stp',
            (bool) preg_match("~^(?:y )?(?:(?:ya )?{$outcome} {$latest}|{$latest} (?:ya )?{$outcome}|todo bien con {$latest})$~", $text)
                => 'resultado_stp',
            (bool) preg_match("~^(?:y )?(?:(?:ya )?{$completed} {$latest}|{$latest} (?:ya )?{$completed}|(?:(?:cual es|cual fue|dime|muestra) )?(?:el )?estado d(?:e |el ){$latest})$~", $text)
                => 'estado_stp',
            default => null,
        };
        if ($mode === null) {
            return null;
        }
        $request = self::make('consultar_stp', ['limite' => 1, 'orden' => 'recientes'] + $period, $mode);
        return $aboutRecentStp || preg_match('/\bstp\b/', $text)
            ? $request
            : ['modo' => 'aclarar_stp', 'consulta' => $request];
    }

    private static function stpRequest(string $text, array $period): ?array
    {
        if (preg_match('/\b(?:desde|hasta|entre|empresa|cliente|cartera|folio|monto|importe|horas?|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b|\d{4}-\d{2}-\d{2}/', $text)) {
            return null;
        }
        $mentionsProblems = preg_match('/\b(?:errores?|incidencias?|fallas?|fallos?|problemas?|fallaron)\b/', $text);
        $isList = preg_match('/^(?:lista|listame|listar|enumera|enumerame|enumerar|muestra|muestrame|dame)\b/', $text);
        $asksAboutProblems = $mentionsProblems && ($isList || preg_match(
            '/\b(?:hubo|hay|reportaron|detectaron|ocurrieron|habido|tuvimos|cuant[oa]s|cuenta|recient(?:emente|es?))\b/', $text
        ));
        if ($asksAboutProblems) {
            $filters = self::stpPeriod($text, $period ?: self::recentDays(7)) + ['solo_problemas' => true];
            if ($isList) {
                if (preg_match('/\b([1-9][0-9]{0,5}) (?:errores|incidencias|fallas|fallos|problemas)\b/', $text, $quantity)) {
                    $filters['limite'] = min(ChatQueries::MAX_LIMIT, (int) $quantity[1]);
                }
                return self::make('consultar_stp', $filters + ['orden' => 'recientes'], 'incidencias_lista');
            }
            return self::make('consultar_stp', $filters + ['contar' => true], 'incidencias_stp');
        }
        if (!preg_match('/^(?:y )?(?:dime )?(?:cuant[oa]s|cuenta|contar|resumen|desglose)\b|(?:por estado|resumen)\b/', $text)) {
            return null;
        }

        $patterns = [
            'liquidado' => '/\bliquid(?:aron|ad[oa]s?|o)\b/',
            'devuelto' => '/\b(?:devolvieron|devuelt[oa]s?|devoluciones)\b/',
            'cancelado' => '/\bcancel(?:aron|ad[oa]s?|aciones)\b/',
            'en_espera' => '/\b(?:en espera|pendientes)\b/',
        ];
        $states = [];
        foreach ($patterns as $state => $pattern) {
            if (preg_match($pattern, $text)) {
                $states[] = $state;
            }
        }
        if ($states === [] && !preg_match('/\b(?:resumen|desglose|por estado|cada estado)\b/', $text)) {
            return null;
        }
        return self::make('consultar_stp', self::stpPeriod($text, $period) + ['resumir_estados' => true], 'resumen_stp')
            + ['estados' => $states];
    }

    private static function stpPeriod(string $text, array $fallback): array
    {
        if (preg_match('/\bultimos ([1-9][0-9]{0,3}) dias\b/', $text, $match)) {
            return self::recentDays((int) $match[1]);
        }
        if (preg_match('/\b(hoy|ayer|esta semana|este mes|mes pasado)\b/', $text, $match)) {
            $label = $match[1] === 'mes pasado' ? 'del mes pasado' : 'de ' . $match[1];
            return self::period($label);
        }
        if ($fallback === [] && preg_match('/\brecient(?:emente|es?)\b/', $text)) {
            return self::recentDays(7);
        }
        return $fallback;
    }

    private static function recentDays(int $days): array
    {
        $today = new DateTimeImmutable('today');
        return ['desde' => $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), 'hasta' => $today->format('Y-m-d')];
    }

    private static function period(string $label): array
    {
        $today = new DateTimeImmutable('today');
        [$start, $end] = match ($label) {
            'de hoy' => [$today, $today],
            'de ayer' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'de esta semana' => [$today->modify('monday this week'), $today],
            'de este mes' => [$today->modify('first day of this month'), $today],
            'del mes pasado' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        };
        return ['desde' => $start->format('Y-m-d'), 'hasta' => $end->format('Y-m-d')];
    }

    private static function make(string $tool, array $filters, string $mode): array
    {
        return ['herramienta' => $tool, 'filtros' => $filters, 'modo' => $mode];
    }

    public static function reply(array $request, array $result): string
    {
        if ($request['modo'] === 'pendientes_conciliar') {
            return self::reconciliationReply($request, $result);
        }
        if (isset($result['error'])) {
            return $result['error'];
        }
        $tool = $request['herramienta'];
        if ($tool === 'consultar_catalogos') {
            return ChatCatalogs::reply($request, $result);
        }
        if ($tool === 'consultar_estatus_sat') {
            return self::invoiceSatReply($result);
        }
        if ($tool === 'consultar_saldos_carteras') {
            return self::walletBalanceReply($request['filtros'], $result);
        }
        if ($tool === 'consultar_traspasos') {
            return self::transferReply($request, $result);
        }
        if (($result['reporte'] ?? null) === 'banking') {
            return self::bankingReply($result);
        }
        if ($tool === 'consultar_correos') {
            return self::mailReply($result);
        }
        [, $single, $plural] = self::RESOURCES[$tool] ?? ['', 'Registro', 'registros del reporte'];
        if ($request['modo'] === 'resumen_stp') {
            return self::stpSummary($request, $result);
        }
        if ($request['modo'] === 'incidencias_stp') {
            $count = $result['totales']['registros'];
            $period = self::periodLabel($result['periodo']);
            return $count === 0
                ? 'No encontré devoluciones ni avisos de incidencias en órdenes STP ' . $period . '.'
                : 'Sí. Órdenes STP con devoluciones o avisos de incidencias ' . $period . ': '
                    . number_format($count, 0, '.', ',') . '.';
        }
        if ($request['modo'] === 'conteo') {
            return 'Total de ' . $plural . ' en tu alcance: ' . number_format($result['totales']['registros'], 0, '.', ',') . '.';
        }
        if ($result['filas'] === []) {
            if ($request['modo'] === 'pendientes_deposito') {
                return 'No encontré depósitos pendientes de documentar en tu alcance.';
            }
            if ($request['modo'] === 'incidencias_lista') {
                return 'No encontré devoluciones ni avisos de incidencias en órdenes STP ' . self::periodLabel($result['periodo']) . '.';
            }
            return $request['modo'] === 'pagina' ? 'No hay más ' . $plural . ' en ese listado.' : ChatQueries::NOT_FOUND;
        }

        if (in_array($request['modo'], ['estado_stp', 'resultado_stp', 'incidencia_ultima_stp'], true)) {
            return self::stpReply($result['filas'][0], $request['modo']);
        }

        $page = $result['pagina'];
        $position = ($page - 1) * $result['limite'];
        $ancient = ($request['filtros']['orden'] ?? '') === 'antiguos';
        $lookup = $request['modo'] === 'buscar_identificador_stp';
        $one = $request['modo'] === 'uno' || ($lookup && count($result['filas']) === 1 && !$result['hay_mas']);
        $order = $ancient ? 'por fecha, de antiguo a reciente' : 'por fecha, de reciente a antiguo';
        $lines = $one
            ? [$single . ($ancient ? ' con la fecha más antigua' : ' con la fecha más reciente') . ':']
            : ['Listado de ' . $plural . ' (' . $order . ', página ' . $page . '):'];
        if ($request['modo'] === 'incidencias_lista') {
            $lines = ['Órdenes STP con devoluciones o avisos de incidencias ' . self::periodLabel($result['periodo'])
                . ' (página ' . $page . '):'];
        }
        if ($request['modo'] === 'pendientes_deposito') {
            $lines = ['Depósitos pendientes de documentar en Movimientos:'];
        }
        if ($lookup) {
            $lines = $one ? ['Orden STP encontrada:']
                : ['Encontré varias órdenes STP. Indica el ID interno de la orden que quieres consultar.'];
        }
        foreach ($result['filas'] as $row) {
            $prefix = $one ? '' : (++$position) . '. ';
            $line = self::line($single, $row);
            if ($tool === 'consultar_stp') {
                $line .= ' · Comprobante: ' . ($row['comprobante_enviado'] === true ? 'enviado' : 'envío sin confirmar');
                $line .= ' · Incidencias: ' . ($row['posible_incidencia'] ? 'posible incidencia' : 'sin avisos registrados');
            }
            $lines[] = $prefix . $line;
            if ($tool === 'consultar_stp' && $row['posible_incidencia']) {
                array_push($lines, ...self::stpNotes($row));
            }
            if (isset($row['enlace'])) {
                $lines[] = $row['enlace'];
            }
        }
        if (!$one && $result['hay_mas']) {
            $lines[] = 'Escribe «siguiente página» para ver más.';
        }
        return implode("\n", $lines);
    }

    private static function reconciliationReply(array $request, array $result): string
    {
        $request['modo'] = 'pendientes_deposito';
        $sections = [self::reply($request, $result)];
        $report = $result['diferencias'] ?? null;
        if ($report === null) {
            return $sections[0];
        }
        if (isset($report['error'])) {
            $sections[] = 'Relación depósitos documentos · ' . $report['error'];
        } elseif ($report['filas'] === []) {
            $sections[] = 'Relación depósitos documentos no muestra depósitos vinculados con diferencia en tu alcance.';
        } else {
            $lines = ['Depósitos vinculados con diferencia en Relación depósitos documentos:'];
            foreach ($report['filas'] as $row) {
                $lines[] = self::line('Movimiento', $row) . ' · diferencia registrada '
                    . number_format((float) $row['restante'], 2, '.', ',')
                    . (empty($row['moneda']) ? '' : ' ' . $row['moneda']);
            }
            if ($report['hay_mas']) {
                $lines[] = 'Se muestran los primeros resultados del reporte.';
            }
            $sections[] = implode("\n", $lines);
        }
        return implode("\n\n", $sections);
    }

    private static function bankingReply(array $result): string
    {
        if ($result['filas'] === []) {
            return 'El reporte Banking no muestra proveedores fuera de rango ' . self::periodLabel($result['periodo']) . '.';
        }
        $lines = ['Proveedores fuera de rango en Banking ' . self::periodLabel($result['periodo']) . ':'];
        foreach ($result['filas'] as $row) {
            $actual = $row['porcentaje_real'] === null ? 'sin porcentaje calculado' : $row['porcentaje_real'] . '% facturado';
            $lines[] = $row['empresa_origen'] . ' · ' . $row['proveedor'] . ' · ' . $actual
                . ' · sugerido ' . $row['porcentaje_sugerido'] . '% · rango ' . $row['rango'];
        }
        if ($result['hay_mas']) {
            $lines[] = 'Escribe «siguiente página» para ver más.';
        }
        return implode("\n", $lines);
    }

    private static function mailReply(array $result): string
    {
        if ($result['filas'] === []) {
            return 'No encontré registros de correo accesibles con esos datos.';
        }
        $lines = [];
        foreach ($result['filas'] as $row) {
            $document = isset($row['factura_id']) ? ' · factura #' . $row['factura_id'] : '';
            $line = 'Registro de correo #' . $row['id'] . $document . ' · ' . $row['estado'];
            if (!empty($row['fecha_envia'])) {
                $line .= ' · fecha registrada del intento ' . $row['fecha_envia'];
            }
            if (!empty($row['fecha_programada'])) {
                $line .= ' · fecha programada ' . $row['fecha_programada'];
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function stpSummary(array $request, array $result): string
    {
        $labels = [
            'liquidado' => 'Liquidadas', 'devuelto' => 'Devueltas', 'cancelado' => 'Canceladas',
            'en_espera' => 'En espera', 'desconocido' => 'Estado no reconocido',
        ];
        $totals = $result['totales'];
        $states = $request['estados'];
        $lines = ['Estado actual de las órdenes STP registradas ' . self::periodLabel($result['periodo']) . ':'];
        foreach ($labels as $state => $label) {
            if (($states === [] && ($state !== 'desconocido' || $totals['por_estado'][$state] > 0))
                || in_array($state, $states, true)) {
                $lines[] = $label . ': ' . number_format($totals['por_estado'][$state], 0, '.', ',');
            }
        }
        if ($states === []) {
            $lines[] = 'Total: ' . number_format($totals['registros'], 0, '.', ',');
        }
        return implode("\n", $lines);
    }

    private static function invoiceSatReply(array $result): string
    {
        $lines = [];
        foreach ($result['filas'] as $row) {
            $label = 'Factura · ID #' . $row['id'];
            if (!empty($row['folio'])) {
                $label .= ' · folio ' . $row['folio'];
            }
            if (!empty($row['uuid'])) {
                $label .= ' · UUID ' . $row['uuid'];
            }
            $lines[] = $label . ' · SAT: ' . $row['estado_sat'] . (!empty($row['detalle_sat']) ? '. ' . $row['detalle_sat'] : '.');
            $lines[] = $row['enlace'];
        }
        foreach ($result['referencias_sin_resolver'] as $item) {
            if ($item['coincidencias'] === []) {
                $lines[] = $item['referencia'] . ': no encontré una factura accesible.';
                continue;
            }
            $ids = array_map(static fn (array $row): string => '#' . $row['id'], $item['coincidencias']);
            $lines[] = $item['referencia'] . ': tiene varias coincidencias (por ejemplo, IDs ' . implode(' y ', $ids)
                . '). Indica el ID o UUID de la factura.';
        }
        if ($lines === []) {
            return $result['mensaje'] ?? ChatQueries::NOT_FOUND;
        }
        if ($result['hay_mas'] && $result['limite'] > 1) {
            $lines[] = 'Escribe «siguiente página» para verificar más facturas.';
        }
        return implode("\n", $lines);
    }

    private static function walletBalanceReply(array $filters, array $result): string
    {
        if (isset($result['resumen_carteras']) || (($filters['pagina'] ?? 1) > 1 && isset($result['pagina']) && $result['filas'] === [])) {
            return ChatCatalogs::reply(
                ['filtros' => ['estado' => 'activo'], 'modo' => ($filters['pagina'] ?? 1) > 1 ? 'pagina' : 'catalogo'],
                ['catalogo' => 'carteras'] + $result
            );
        }
        if (isset($result['grupos'])) {
            $groups = array_map(static fn (array $group): string => $group['nombre'] . ' (#' . $group['id'] . ')', $result['grupos']);
            return 'Encontré varios grupos: ' . implode(', ', $groups) . '. Escribe «Saldo del grupo #ID» con el número que corresponda.';
        }
        if (!isset($result['totales'])) {
            return $result['mensaje'] ?? ChatQueries::NOT_FOUND;
        }

        $totals = $result['totales'];
        $count = $totals['registros'];
        $wallets = number_format($count) . ($count === 1 ? ' cartera activa' : ' carteras activas');
        $selection = isset($result['grupo'])
            ? 'Grupo ' . $result['grupo']['nombre'] . ' (#' . $result['grupo']['id'] . ')'
            : (isset($filters['cartera_nombre']) ? 'Carteras con «' . $filters['cartera_nombre'] . '» en el nombre'
                : 'Carteras activas' . (!empty($filters['panel']) ? ' visibles en el panel' : ''));
        return $selection . ' · Saldo acreditable total de ' . $wallets . ' en tu alcance: '
            . number_format($totals['saldo_acreditable'], 2, '.', ',') . ' ' . $totals['moneda'] . '.';
    }

    private static function transferReply(array $request, array $result): string
    {
        if (isset($result['totales'])) {
            $count = $result['totales']['registros'];
            $unpaired = $result['totales']['sin_contrapartida'];
            $reply = 'Traspasos con origen y destino verificados: ' . number_format($count) . '.';
            if ($unpaired > 0) {
                $reply .= ' Movimientos sin contrapartida verificable en tu alcance: ' . number_format($unpaired) . '.';
            }
            return $reply;
        }
        if ($result['filas'] === []) {
            return $request['modo'] === 'pagina' ? 'No hay más traspasos en ese listado.' : 'No encontré traspasos en tu alcance.';
        }

        $one = $request['modo'] === 'uno';
        $position = ($result['pagina'] - 1) * $result['limite'];
        $lines = [];
        foreach ($result['filas'] as $row) {
            $heading = !empty($row['origen_id']) && !empty($row['destino_id']) ? 'Traspaso' : 'Movimiento de traspaso';
            $lines[] = ($one ? '' : (++$position) . '. ') . $heading . (!empty($row['fecha']) ? ' · ' . $row['fecha'] : '');
            foreach (['origen' => 'Origen', 'destino' => 'Destino'] as $side => $label) {
                if (empty($row[$side . '_id'])) {
                    $lines[] = $label . ': sin contrapartida verificable en tu alcance.';
                    continue;
                }
                $id = (int) $row[$side . '_id'];
                $wallet = 'cartera #' . (int) $row['cartera_' . $side . '_id'];
                if (!empty($row[$side])) {
                    $wallet = trim(preg_replace('/\s+/u', ' ', $row[$side]) ?? $row[$side]) . ' (' . $wallet . ')';
                }
                $balance = (float) $row['saldo_' . $side];
                $lines[] = $label . ': ' . $wallet . ' · movimiento #' . $id . ' · saldo acreditable '
                    . ($balance > 0 ? '+' : '') . number_format($balance, 2, '.', ',') . ' ' . $row['moneda'];
                $lines[] = '?chat_registro=movimiento&id=' . $id;
            }
            $lines[] = '';
        }
        if (!$one && $result['hay_mas']) {
            $lines[] = 'Escribe «siguiente página» para ver más.';
        }
        return trim(implode("\n", $lines));
    }

    private static function periodLabel(array $period): string
    {
        if (isset($period['desde'], $period['hasta'])) {
            return $period['desde'] === $period['hasta'] ? 'el ' . $period['desde']
                : 'del ' . $period['desde'] . ' al ' . $period['hasta'];
        }
        return 'en tu historial autorizado';
    }

    private static function stpNotes(array $row): array
    {
        $notes = [];
        foreach (['causa_devolucion' => 'Causa de devolución', 'status_pre' => 'Estado previo'] as $key => $label) {
            if (trim((string) ($row[$key] ?? '')) !== '') {
                $notes[] = $label . ': ' . $row[$key];
            }
        }
        return $notes;
    }

    private static function stpReply(array $row, string $mode): string
    {
        $asksAboutOutcome = $mode === 'resultado_stp';
        $summary = match ($row['estado']) {
            'Liquidado' => ($asksAboutOutcome && $row['posible_incidencia'] ? '' : 'Sí. ')
                . 'La última transacción STP se liquidó.'
                . ($row['comprobante_enviado'] === true ? ' Se envió el comprobante.' : '')
                . ($asksAboutOutcome && !$row['posible_incidencia'] ? ' No hay avisos de incidencias registrados.' : ''),
            'Devuelto' => 'No. La última transacción STP fue devuelta.',
            'Cancelado' => 'No. La última transacción STP fue cancelada.',
            'En espera' => 'Aún no. La última transacción STP está en espera.',
            default => 'No pude confirmar si se concretó la última transacción STP. El estado registrado no se reconoce.',
        };
        if ($mode === 'incidencia_ultima_stp') {
            $summary = match (true) {
                $row['estado'] === 'Devuelto' => 'Sí. La última transferencia STP fue devuelta.',
                $row['posible_incidencia'] => 'Sí. La última transferencia STP tiene un aviso de posible incidencia.',
                $row['estado'] === 'Cancelado' => 'La última transferencia STP fue cancelada. No hay avisos de incidencias registrados.',
                default => 'No encontré devoluciones ni avisos de incidencias en la última transferencia STP.',
            };
        }
        $lines = [$summary, self::line('Orden STP', $row)];
        if ($row['posible_incidencia']) {
            if ($mode !== 'incidencia_ultima_stp' || $row['estado'] === 'Devuelto') {
                $lines[] = 'Hay una posible incidencia registrada.';
            }
            array_push($lines, ...self::stpNotes($row));
        }
        if (isset($row['enlace'])) {
            $lines[] = $row['enlace'];
        }
        return implode("\n", $lines);
    }

    private static function line(string $label, array $row): string
    {
        $parts = [$label . ' #' . ($row['id'] ?? $row['pago_id'])];
        if (!empty($row['folio'])) {
            $parts[] = 'folio ' . $row['folio'];
        }
        $date = $row['fecha'] ?? $row['fecha_creacion'] ?? $row['fecha_pago'] ?? $row['fecha_banco'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $date) && !str_starts_with($date, '0000')) {
            $parts[] = substr($date, 0, 10);
        }
        foreach (['empresa', 'cliente', 'estado'] as $field) {
            if (!empty($row[$field])) {
                $parts[] = $row[$field];
            }
        }
        $amount = $row['total'] ?? $row['monto'] ?? null;
        $currency = trim((string) ($row['moneda'] ?? ''));
        if (is_numeric($amount)) {
            $parts[] = number_format((float) $amount, 2, '.', ',') . ($currency === '' ? '' : ' ' . $currency);
        }
        foreach (['deposito' => 'depósito', 'retiro' => 'retiro'] as $field => $caption) {
            if (isset($row[$field]) && (float) $row[$field] != 0.0) {
                $parts[] = $caption . ' ' . number_format((float) $row[$field], 2, '.', ',')
                    . ($currency === '' ? '' : ' ' . $currency);
            }
        }
        return implode(' · ', $parts);
    }
}

