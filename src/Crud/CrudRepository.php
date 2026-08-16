<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableIntrospector;
use Appylogi\AppyCrud\Schema\TableSchema;
use RuntimeException;

/**
 * Operaciones CRUD genericas sobre una tabla, basadas en su TableSchema.
 * Todo el acceso a datos pasa por prepared statements; nunca se concatena
 * valor de usuario dentro del SQL.
 */
class CrudRepository
{
    public function __construct(
        private Connection $connection,
        private TableSchema $schema,
        private ?string $softDeleteColumn = null,
    ) {
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = '', string $orderDir = 'ASC', array $filters = []): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $table = $this->connection->quoteIdentifier($this->schema->table);

        $orderSql = '';
        if ($orderBy !== '' && $this->schema->column($orderBy) !== null) {
            $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $orderSql = 'ORDER BY ' . $this->connection->quoteIdentifier($orderBy) . ' ' . $dir;
        }

        [$whereSql, $params] = $this->buildWhereClause($filters);

        $sql = "SELECT * FROM {$table} {$whereSql} {$orderSql} LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countStmt = $this->connection->pdo()->prepare("SELECT COUNT(*) FROM {$table} {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * Construye WHERE combinando el filtro de borrado logico (si aplica) con
     * los filtros por columna recibidos ($filters: columna => valor).
     * Columnas con reference/checkbox usan igualdad exacta; el resto, LIKE.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($this->softDeleteColumn !== null) {
            $softColumn = $this->connection->quoteIdentifier($this->softDeleteColumn);
            $conditions[] = "({$softColumn} = 0 OR {$softColumn} IS NULL)";
        }

        foreach ($filters as $columnName => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $column = $this->schema->column($columnName);

            if ($column === null) {
                continue;
            }

            $quotedColumn = $this->connection->quoteIdentifier($columnName);
            $paramKey = ':filter_' . $columnName;

            if ($column->reference !== null || $column->inputType === 'checkbox') {
                $conditions[] = "{$quotedColumn} = {$paramKey}";
                $params[$paramKey] = $value;
            } else {
                $conditions[] = "{$quotedColumn} LIKE {$paramKey}";
                $params[$paramKey] = '%' . $value . '%';
            }
        }

        $sql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$sql, $params];
    }

    /**
     * Exporta las filas (respetando los filtros activos) a CSV, escribiendo
     * por bloques al stream $output en vez de acumular todo en memoria.
     */
    public function exportCsv($output, array $filters = [], int $chunkSize = 1000): void
    {
        $table = $this->connection->quoteIdentifier($this->schema->table);
        [$whereSql, $params] = $this->buildWhereClause($filters);
        $columns = $this->schema->visibleColumns();

        $referenceLabels = [];
        foreach ($columns as $column) {
            if ($column->reference !== null) {
                foreach ($this->referenceOptions($column) as $option) {
                    $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
                }
            }
        }

        fputcsv($output, array_map(fn (Column $c) => $c->label, $columns));

        $offset = 0;

        while (true) {
            $sql = "SELECT * FROM {$table} {$whereSql} LIMIT :limit OFFSET :offset";
            $stmt = $this->connection->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $chunkSize, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($output, array_map(function (Column $c) use ($row, $referenceLabels) {
                    $rawValue = (string) ($row[$c->name] ?? '');

                    return $c->reference !== null ? ($referenceLabels[$c->name][$rawValue] ?? $rawValue) : $rawValue;
                }, $columns));
            }

            $offset += $chunkSize;

            if (count($rows) < $chunkSize) {
                break;
            }
        }
    }

    public function bulkDelete(array $primaryKeyValues): void
    {
        foreach ($primaryKeyValues as $value) {
            $this->delete($value);
        }
    }

    /**
     * Copia los datos de un registro para prellenar un formulario de creacion
     * (nunca inserta por si solo). $excludeColumns permite vaciar columnas
     * unicas (codigos, emails) para que el usuario las complete a mano.
     */
    public function cloneData(mixed $primaryKeyValue, array $excludeColumns = [], ?string $suffixColumn = null, string $suffix = ''): ?array
    {
        $row = $this->find($primaryKeyValue);

        if ($row === null) {
            return null;
        }

        $pk = $this->schema->primaryKey();
        if ($pk !== null) {
            unset($row[$pk->name]);
        }

        foreach ($excludeColumns as $columnName) {
            unset($row[$columnName]);
        }

        if ($suffixColumn !== null && isset($row[$suffixColumn])) {
            $row[$suffixColumn] .= $suffix;
        }

        return $row;
    }

    public function find(mixed $primaryKeyValue): ?array
    {
        $pk = $this->requirePrimaryKey();
        $table = $this->connection->quoteIdentifier($this->schema->table);
        $pkColumn = $this->connection->quoteIdentifier($pk->name);

        $stmt = $this->connection->pdo()->prepare("SELECT * FROM {$table} WHERE {$pkColumn} = :pk LIMIT 1");
        $stmt->execute(['pk' => $primaryKeyValue]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insert(array $data): string
    {
        $data = $this->filterToKnownColumns($data);

        if ($data === []) {
            throw new RuntimeException('AppyCrud: no hay columnas validas para insertar.');
        }

        $table = $this->connection->quoteIdentifier($this->schema->table);
        $columns = array_map(fn ($c) => $this->connection->quoteIdentifier($c), array_keys($data));
        $placeholders = array_map(fn ($c) => ':' . $c, array_keys($data));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($data);

        return $this->connection->lastInsertId();
    }

    public function update(mixed $primaryKeyValue, array $data): void
    {
        $pk = $this->requirePrimaryKey();
        $data = $this->filterToKnownColumns($data);
        unset($data[$pk->name]);

        if ($data === []) {
            return;
        }

        $table = $this->connection->quoteIdentifier($this->schema->table);
        $pkColumn = $this->connection->quoteIdentifier($pk->name);
        $sets = array_map(fn ($c) => $this->connection->quoteIdentifier($c) . ' = :' . $c, array_keys($data));

        $sql = sprintf('UPDATE %s SET %s WHERE %s = :__pk', $table, implode(', ', $sets), $pkColumn);

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($data + ['__pk' => $primaryKeyValue]);
    }

    public function delete(mixed $primaryKeyValue): void
    {
        $pk = $this->requirePrimaryKey();
        $table = $this->connection->quoteIdentifier($this->schema->table);
        $pkColumn = $this->connection->quoteIdentifier($pk->name);

        if ($this->softDeleteColumn !== null) {
            $softColumn = $this->connection->quoteIdentifier($this->softDeleteColumn);
            $stmt = $this->connection->pdo()->prepare("UPDATE {$table} SET {$softColumn} = 1 WHERE {$pkColumn} = :pk");
            $stmt->execute(['pk' => $primaryKeyValue]);
            return;
        }

        $stmt = $this->connection->pdo()->prepare("DELETE FROM {$table} WHERE {$pkColumn} = :pk");
        $stmt->execute(['pk' => $primaryKeyValue]);
    }

    /**
     * Opciones para poblar un <select> de una columna con llave foranea.
     * @return array<int, array{value: mixed, label: string}>
     */
    public function referenceOptions(Column $column, int $limit = 500): array
    {
        if ($column->reference === null) {
            return [];
        }

        $refTable = $column->reference['table'];
        $valueColumn = $column->reference['column'];
        $labelColumn = $column->reference['label'] ?? $this->guessLabelColumn($refTable, $valueColumn);

        $tableQ = $this->connection->quoteIdentifier($refTable);
        $valueQ = $this->connection->quoteIdentifier($valueColumn);
        $labelQ = $this->connection->quoteIdentifier($labelColumn);

        $sql = "SELECT {$valueQ} AS value, {$labelQ} AS label FROM {$tableQ} ORDER BY {$labelQ} LIMIT :limit";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Cuando la referencia no indica 'label', se adivina la columna mas
     * legible de la tabla referenciada (nombre, titulo, descripcion, etc.).
     */
    private function guessLabelColumn(string $table, string $fallback): string
    {
        $refSchema = (new TableIntrospector())->introspect($this->connection, $table);

        $preferred = ['nombre', 'titulo', 'name', 'title', 'descripcion', 'label'];
        foreach ($preferred as $candidate) {
            if ($refSchema->column($candidate) !== null) {
                return $candidate;
            }
        }

        foreach ($refSchema->columns() as $candidateColumn) {
            if (!$candidateColumn->isPrimaryKey && $candidateColumn->inputType === 'text') {
                return $candidateColumn->name;
            }
        }

        return $fallback;
    }

    private function filterToKnownColumns(array $data): array
    {
        $known = array_keys($this->schema->columns());

        return array_intersect_key($data, array_flip($known));
    }

    private function requirePrimaryKey(): \Appylogi\AppyCrud\Schema\Column
    {
        $pk = $this->schema->primaryKey();

        if ($pk === null) {
            throw new RuntimeException("AppyCrud: la tabla '{$this->schema->table}' no tiene llave primaria detectada.");
        }

        return $pk;
    }
}
