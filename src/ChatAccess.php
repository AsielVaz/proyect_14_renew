<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ChatAccess
{
    private const STAFF = ['administrador', 'administrador_b', 'supercapturista', 'capturista', 'archivo'];
    private const CAPTURE_ROLES = ['supercapturista', 'capturista'];
    private const REPORT_ROLES = ['administrador', 'administrador_b'];

    private function __construct(
        private readonly string $role,
        private readonly int $operator,
        private readonly ?int $client,
        private readonly array $previousCompanies,
        private readonly bool $previousRestrictedCompanyAllowed,
    ) {}

    public static function load(PDO $pdo, int $operator): self
    {
        $query = $pdo->prepare('SELECT tipo, cliente, empresa_asignada FROM usuarios WHERE id = ?');
        $query->execute([$operator]);
        $user = $query->fetch();
        $role = mb_strtolower(trim((string) ($user['tipo'] ?? '')));
        $client = in_array($role, self::STAFF, true) ? null : (int) ($user['cliente'] ?? 0);
        if ($client !== null && ($client <= 0 || !in_array($role, ['cliente', 'esclavo'], true))) {
            throw new ChatException('Tu cuenta no tiene acceso al chat de consultas.', 403);
        }

        $query = $pdo->prepare('SELECT DISTINCT id_empresa FROM cliente_empresa WHERE id_usuario = ? AND id_empresa > 0');
        $query->execute([$operator]);
        $companies = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        $assigned = (int) ($user['empresa_asignada'] ?? 0);
        if ($companies === [] && $assigned > 0) {
            $companies[] = $assigned;
        }
        sort($companies, SORT_NUMERIC);

        $isAdministrator = $role === 'administrador';
        return new self(
            $role, $operator, $client, $companies,
            $isAdministrator && CompanyPanelRepository::canAccessCompany(CompanyPanelRepository::RESTRICTED_COMPANY, $operator)
        );
    }

    public function signature(): string
    {
        return $this->signatureForScope([], true);
    }

    public function previousSignatures(): array
    {
        if ($this->role !== 'administrador') {
            // Solo se retoma el alcance anterior que tenía los mismos permisos de módulos.
            return [$this->signatureForScope($this->previousCompanies, false)];
        }
        // Los dos alcances anteriores del administrador son subconjuntos del actual.
        $previous = $this->signatureForScope([], $this->previousRestrictedCompanyAllowed);
        $assigned = $this->signatureForScope($this->previousCompanies, $this->previousRestrictedCompanyAllowed);
        return array_values(array_diff(array_unique([$previous, $assigned]), [$this->signature()]));
    }

    private function signatureForScope(array $companies, bool $restrictedCompanyAllowed): string
    {
        $scope = [$this->role, $this->operator, $this->client, $companies, $restrictedCompanyAllowed];
        if (in_array($this->role, self::CAPTURE_ROLES, true)) {
            // Los hilos del alcance anterior pueden contener reportes o saldos de catálogo.
            $scope[] = 'facturas_movimientos';
        }
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function canSeeBankBalance(): bool
    {
        return $this->client === null;
    }

    public function allows(string $tool): bool
    {
        $movements = Movements::allowedFor($this->role);
        return match ($tool) {
            'buscar_facturas', 'detalle_factura', 'consultar_estatus_sat', 'buscar_complementos', 'detalle_complemento', 'pendientes_ppd' => true,
            'consultar_pagos', 'detalle_movimiento', 'consultar_banco' => isset($movements['totales']),
            'consultar_stp' => isset($movements['stp']),
            'consultar_saldos_carteras' => !in_array($this->role, self::CAPTURE_ROLES, true),
            'consultar_catalogos' => Catalogs::isAllowed($this->role),
            'consultar_traspasos' => isset($movements['traspasos']),
            'generar_reporte', 'consultar_correos' => in_array($this->role, self::REPORT_ROLES, true),
            default => false,
        };
    }

    public function walletConditions(array &$params, bool $panel = true): string
    {
        $scope = 'c.activo = 1';
        if ($panel) {
            $params['operator_one'] = $this->operator;
            $params['operator_restricted'] = $this->operator;
            $scope = DashboardRepository::ACTIVE_WALLET_SCOPE;
        }
        $conditions = [$scope];
        if ($this->client !== null) {
            $conditions[] = 'c.id = ' . self::bind($params, $this->client);
        } elseif ($this->role !== 'administrador') {
            $conditions[] = '(c.empresa > 0 OR p.id IS NOT NULL)';
        }
        return implode(' AND ', $conditions);
    }

    /** The expressions are fixed column references provided by the PHP repositories. */
    public function conditions(string $company, string $client, array &$params, bool $allowUnknownCompany = true): string
    {
        $conditions = !$allowUnknownCompany && $this->client !== null ? [$company . ' > 0'] : [];
        if ($this->client !== null) {
            $conditions[] = $client . ' = ' . self::bind($params, $this->client);
        }
        return $conditions === [] ? '1=1' : implode(' AND ', $conditions);
    }

    public static function bind(array &$params, string|int $value): string
    {
        $key = 'chat_' . count($params);
        $params[$key] = $value;
        return ':' . $key;
    }
}

