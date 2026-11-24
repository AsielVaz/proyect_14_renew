<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class ShortKeyLiveDocumentService
{
    private const LOCK_NAME = 's14_short_key_live_documents_cron';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, int|float|bool|string> */
    public function run(): array
    {
        $startedAt = microtime(true);
        if (!$this->acquireLock()) {
            throw new RuntimeException('Ya hay una actualización de documentos vivos en proceso.');
        }

        try {
            $query = $this->pdo->prepare(<<<'SQL'
                UPDATE claves_cortas cc
                LEFT JOIN (
                    SELECT f.id_clave_corta, COUNT(*) AS documentos_vivos
                    FROM facturas f
                    LEFT JOIN (
                        SELECT id_factura, SUM(monto) AS total_pagado
                        FROM facturar_rel_complementos
                        GROUP BY id_factura
                    ) pagos_factura ON pagos_factura.id_factura = f.id
                    WHERE f.metodo_pago = 2
                      AND COALESCE(f.uuid, '') <> ''
                      AND COALESCE(f.status, '') <> 'Cancelado'
                      AND f.id_clave_corta IS NOT NULL
                      AND f.id_clave_corta > 0
                      AND f.total_iva - COALESCE(pagos_factura.total_pagado, 0) > 0.005
                    GROUP BY f.id_clave_corta
                ) vivos ON vivos.id_clave_corta = cc.id
                SET cc.documentos_vivos = COALESCE(vivos.documentos_vivos, 0)
                SQL);
            $query->execute();
            $changed = $query->rowCount();

            $summary = $this->pdo->query(<<<'SQL'
                SELECT COUNT(*) AS claves_cortas,
                       COALESCE(SUM(CASE WHEN documentos_vivos > 0 THEN 1 ELSE 0 END), 0) AS claves_con_documentos,
                       COALESCE(SUM(documentos_vivos), 0) AS documentos_vivos
                FROM claves_cortas
                SQL)->fetch(PDO::FETCH_ASSOC);

            return [
                'ok' => true,
                'message' => 'Documentos vivos actualizados por clave corta.',
                'claves_cortas' => (int) ($summary['claves_cortas'] ?? 0),
                'claves_con_documentos' => (int) ($summary['claves_con_documentos'] ?? 0),
                'documentos_vivos' => (int) ($summary['documentos_vivos'] ?? 0),
                'registros_modificados' => $changed,
                'duracion_segundos' => round(microtime(true) - $startedAt, 3),
            ];
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $query->execute(['name' => self::LOCK_NAME]);
        return (int) $query->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $query = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $query->execute(['name' => self::LOCK_NAME]);
        } catch (Throwable $exception) {
            error_log('Short-key live documents lock release: ' . $exception->getMessage());
        }
    }
}
