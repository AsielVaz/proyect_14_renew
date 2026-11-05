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

    public function save(array $catalog, array $input, ?int $id, array $files = []): int
    {
        $values = [];
        foreach ($catalog['fields'] as $name => $field) {
            $column = (string) ($field['column'] ?? $name);
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'file') {
                $file = $files[$name] ?? null;
                $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
                if ($error === UPLOAD_ERR_NO_FILE) {
                    if (($field['required'] ?? false) && $id === null) {
                        throw new RuntimeException('El campo “' . $field['label'] . '” es obligatorio.');
                    }
                    continue;
                }
                $values[$column] = $this->storeUploadedImage($file, $field);
                continue;
            }

            if ($type === 'checkbox') {
                $value = isset($input[$name]) ? (string) ($field['checkedValue'] ?? '1') : (string) ($field['uncheckedValue'] ?? '0');
            } else {
                $value = isset($input[$name]) && is_scalar($input[$name]) ? trim((string) $input[$name]) : '';
            }
            if (($field['required'] ?? false) && $value === '' && !($id && ($field['writeOnly'] ?? false))) {
                throw new RuntimeException('El campo “' . $field['label'] . '” es obligatorio.');
            }
            if ($id && ($field['writeOnly'] ?? false) && $value === '') {
                continue;
            }
            $values[$column] = $value === '' ? null : $value;
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
            $params = [];
            foreach ($values as $name => $value) {
                $parameter = 'value_' . count($params);
                $assignments[] = $this->identifier($name) . ' = :' . $parameter;
                $params[$parameter] = $value;
            }
            $params['record_id'] = $id;
            $query = $this->pdo->prepare("UPDATE {$table} SET " . implode(', ', $assignments) . ' WHERE id = :record_id');
            $query->execute($params);
            return $id;
        }

        $sqlDefaults = [];
        foreach (($catalog['defaults'] ?? []) as $name => $value) {
            if (is_array($value) && isset($value['sql'])) {
                $sqlDefaults[$name] = $value['sql'];
            }
        }
        $columns = array_merge(array_keys($values), array_keys($sqlDefaults));
        $params = [];
        $placeholders = [];
        foreach ($columns as $name) {
            if (isset($sqlDefaults[$name])) {
                $placeholders[] = $sqlDefaults[$name];
                continue;
            }
            $parameter = 'value_' . count($params);
            $placeholders[] = ':' . $parameter;
            $params[$parameter] = $values[$name];
        }
        $query = $this->pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', array_map([$this, 'identifier'], $columns)) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $query->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    private function storeUploadedImage(mixed $file, array $field): string
    {
        if (!is_array($file)) {
            throw new RuntimeException('La imagen recibida no es válida.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No fue posible cargar la imagen del cliente.');
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size <= 0 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('La imagen debe ser un archivo válido de hasta 5 MB.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!is_string($mime) || !isset($extensions[$mime])) {
            throw new RuntimeException('La imagen debe estar en formato JPG, PNG, WebP o GIF.');
        }

        $directory = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($field['uploadDirectory'] ?? 'catalogs')) ?: 'catalogs';
        $relativeDirectory = 'assets/uploads/' . $directory;
        $absoluteDirectory = APP_ROOT . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new RuntimeException('No fue posible preparar la carpeta para imágenes.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($temporaryPath, $absoluteDirectory . '/' . $filename)) {
            throw new RuntimeException('No fue posible guardar la imagen del cliente.');
        }

        return \app_url($relativeDirectory . '/' . $filename);
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
