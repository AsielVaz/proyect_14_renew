<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function page(array $catalog, string $search, int $page, int $perPage = 25): array
    {
        $table = $this->identifier($catalog['table']);
        $columns = array_values(array_unique(array_merge(['id'], $catalog['list'])));
        $select = implode(', ', array_map([$this, 'identifier'], $columns));
        $searchable = array_values(array_filter($catalog['list'], static fn (string $field): bool => $field !== 'id'));
        $where = '';
        $params = [];
        if ($search !== '' && $searchable !== []) {
            $parts = [];
            foreach ($searchable as $i => $column) {
                $parts[] = 'CAST(' . $this->identifier($column) . ' AS CHAR) LIKE :q' . $i;
                $params['q' . $i] = '%' . $search . '%';
            }
            $where = ' WHERE ' . implode(' OR ', $parts);
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $fetchLimit = $perPage + 1;

        $query = $this->pdo->prepare("SELECT {$select} FROM {$table}{$where} ORDER BY id DESC LIMIT {$fetchLimit} OFFSET {$offset}");
        $query->execute($params);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }

        return [
            'rows' => $rows, 'total' => count($rows), 'page' => $page,
            'lastPage' => $page + ($hasNext ? 1 : 0), 'hasNext' => $hasNext, 'totalExact' => false,
        ];
    }

    public function find(array $catalog, int $id): ?array
    {
        $table = $this->identifier($catalog['table']);
        $query = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $query->execute(['id' => $id]);
        return $query->fetch() ?: null;
    }

    public function save(array $catalog, array $input, ?int $id): int
    {
        $values = [];
        foreach ($catalog['fields'] as $name => $field) {
            $value = isset($input[$name]) && is_scalar($input[$name]) ? trim((string) $input[$name]) : '';
            if (($field['required'] ?? false) && $value === '' && !($id && ($field['writeOnly'] ?? false))) {
                throw new RuntimeException('El campo “' . $field['label'] . '” es obligatorio.');
            }
            if ($id && ($field['writeOnly'] ?? false) && $value === '') {
                continue;
            }
            $values[$name] = $value === '' ? null : $value;
        }

        if ($id === null) {
            foreach (($catalog['defaults'] ?? []) as $name => $value) {
                if (!is_array($value)) {
                    $values[$name] = $value;
                }
            }
        }

        if ($values === []) {
            throw new RuntimeException('No hay datos para guardar.');
        }

        $table = $this->identifier($catalog['table']);
        if ($id !== null) {
            $assignments = [];
            foreach (array_keys($values) as $name) {
                $assignments[] = $this->identifier($name) . ' = :' . $name;
            }
            $values['id'] = $id;
            $query = $this->pdo->prepare("UPDATE {$table} SET " . implode(', ', $assignments) . ' WHERE id = :id');
            $query->execute($values);
            return $id;
        }

        $sqlDefaults = [];
        foreach (($catalog['defaults'] ?? []) as $name => $value) {
            if (is_array($value) && isset($value['sql'])) {
                $sqlDefaults[$name] = $value['sql'];
            }
        }
        $columns = array_merge(array_keys($values), array_keys($sqlDefaults));
        $placeholders = array_map(static fn (string $name): string => isset($sqlDefaults[$name]) ? $sqlDefaults[$name] : ':' . $name, $columns);
        $query = $this->pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', array_map([$this, 'identifier'], $columns)) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $query->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(array $catalog, int $id): void
    {
        $table = $this->identifier($catalog['table']);
        $query = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $query->execute(['id' => $id]);
        if ($query->rowCount() === 0) {
            throw new RuntimeException('El registro ya no existe.');
        }
    }

    public function options(array $relation): array
    {
        $table = $this->identifier($relation['table']);
        $value = $this->identifier($relation['value']);
        $display = $this->identifier($relation['display']);
        $query = $this->pdo->query("SELECT {$value} AS option_value, {$display} AS option_label FROM {$table} ORDER BY {$display} LIMIT 5000");
        return $query->fetchAll();
    }

    public function count(array $catalog): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $this->identifier($catalog['table']))->fetchColumn();
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Identificador de base de datos no permitido.');
        }
        return '`' . $name . '`';
    }
}
