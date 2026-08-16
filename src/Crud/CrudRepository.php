<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\FieldType;
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
    /**
     * @param Condition[] $baseConditions condiciones WHERE aplicadas siempre (scoping)
     * @param array<string, mixed> $insertDefaults valores forzados en cada insert (ignora lo que mande el cliente)
     */
    public function __construct(
        private Connection $connection,
        private TableSchema $schema,
        private ?string $softDeleteColumn = null,
        private array $baseConditions = [],
        private array $insertDefaults = [],
    ) {
    }

    /**
     * SQL + params de las condiciones base (scoping), para combinar con el
     * WHERE de cualquier operacion (listado, find, update, delete).
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function baseConditionsSql(): array
    {
        $sqlParts = [];
        $params = [];

        foreach ($this->baseConditions as $index => $condition) {
            $quotedColumn = $this->connection->quoteIdentifier($condition->column);
            $paramPrefix = ':base_' . $index . '_';

            if ($condition->operator === 'IN' || $condition->operator === 'NOT IN') {
                $values = (array) $condition->value;

                if ($values === []) {
                    // IN() vacio no es SQL valido; una condicion sin valores no debe matchear nada.
                    $sqlParts[] = '1 = 0';
                    continue;
                }

                $placeholders = [];
                foreach (array_values($values) as $i => $value) {
                    $key = $paramPrefix . $i;
                    $placeholders[] = $key;
                    $params[$key] = $value;
                }

                $sqlParts[] = "{$quotedColumn} {$condition->operator} (" . implode(', ', $placeholders) . ')';
            } elseif ($condition->operator === 'IS NULL' || $condition->operator === 'IS NOT NULL') {
                $sqlParts[] = "{$quotedColumn} {$condition->operator}";
            } else {
                $key = $paramPrefix . '0';
                $sqlParts[] = "{$quotedColumn} {$condition->operator} {$key}";
                $params[$key] = $condition->value;
            }
        }

        return [$sqlParts, $params];
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = '', string $orderDir = 'ASC', array $filters = [], string $search = ''): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $table = $this->connection->quoteIdentifier($this->schema->table);

        $orderSql = '';
        if ($orderBy !== '' && $this->schema->column($orderBy) !== null) {
            $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $orderSql = 'ORDER BY ' . $this->connection->quoteIdentifier($orderBy) . ' ' . $dir;
        }

        [$whereSql, $params] = $this->buildWhereClause($filters, $search);

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
     * Construye WHERE combinando el filtro de borrado logico (si aplica), los
     * filtros por columna ($filters: columna => valor, AND entre si) y una
     * busqueda global ($search, OR entre las columnas de texto/numero visibles).
     * Columnas con reference/checkbox usan igualdad exacta en los filtros; el
     * resto, LIKE.
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereClause(array $filters, string $search = ''): array
    {
        [$baseSql, $params] = $this->baseConditionsSql();
        $conditions = $baseSql;

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

            if ($column->reference !== null || FieldType::strategy($column->inputType ?? '') === FieldType::STRATEGY_CHECKBOX) {
                $conditions[] = "{$quotedColumn} = {$paramKey}";
                $params[$paramKey] = $value;
            } else {
                $conditions[] = "{$quotedColumn} LIKE {$paramKey}";
                $params[$paramKey] = '%' . $value . '%';
            }
        }

        if ($search !== '') {
            $searchConditions = [];
            foreach ($this->schema->visibleColumns() as $column) {
                if ($column->reference !== null || FieldType::strategy($column->inputType ?? '') === FieldType::STRATEGY_CHECKBOX) {
                    continue;
                }

                $quotedColumn = $this->connection->quoteIdentifier($column->name);
                $paramKey = ':search_' . $column->name;
                $searchConditions[] = "{$quotedColumn} LIKE {$paramKey}";
                $params[$paramKey] = '%' . $search . '%';
            }

            if ($searchConditions !== []) {
                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            }
        }

        $sql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$sql, $params];
    }

    /**
     * Exporta las filas (respetando filtros y busqueda activos) a CSV,
     * escribiendo por bloques al stream $output en vez de acumular todo en memoria.
     */
    public function exportCsv($output, array $filters = [], int $chunkSize = 1000, string $search = ''): void
    {
        $columns = $this->schema->visibleColumns();
        fputcsv($output, array_map(fn (Column $c) => $c->label, $columns));

        foreach ($this->exportRows($filters, $search, $chunkSize) as $displayRow) {
            fputcsv($output, array_values($displayRow));
        }
    }

    /**
     * Exporta a una tabla HTML con mimetype de Excel (application/vnd.ms-excel).
     * Excel abre HTML valido guardado con extension .xls sin depender de
     * ninguna libreria externa (PhpSpreadsheet, etc.).
     */
    public function exportXls($output, array $filters = [], int $chunkSize = 1000, string $search = ''): void
    {
        $columns = $this->schema->visibleColumns();

        fwrite($output, '<html><head><meta charset="UTF-8"></head><body><table border="1">');
        fwrite($output, '<tr>' . implode('', array_map(fn (Column $c) => '<th>' . htmlspecialchars($c->label, ENT_QUOTES) . '</th>', $columns)) . '</tr>');

        foreach ($this->exportRows($filters, $search, $chunkSize) as $displayRow) {
            fwrite($output, '<tr>' . implode('', array_map(fn ($v) => '<td>' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td>', $displayRow)) . '</tr>');
        }

        fwrite($output, '</table></body></html>');
    }

    /**
     * Exporta a una tabla en formato Markdown.
     */
    public function exportMarkdown($output, array $filters = [], int $chunkSize = 1000, string $search = ''): void
    {
        $columns = $this->schema->visibleColumns();
        $escape = fn ($v) => str_replace('|', '\\|', (string) $v);

        fwrite($output, '| ' . implode(' | ', array_map(fn (Column $c) => $escape($c->label), $columns)) . " |\n");
        fwrite($output, '| ' . implode(' | ', array_fill(0, count($columns), '---')) . " |\n");

        foreach ($this->exportRows($filters, $search, $chunkSize) as $displayRow) {
            fwrite($output, '| ' . implode(' | ', array_map($escape, $displayRow)) . " |\n");
        }
    }

    /**
     * Itera las filas que aplican (filtros + busqueda), ya resueltas a su
     * valor "para mostrar" (FK -> label), en bloques de $chunkSize sin
     * acumular el resultado completo en memoria.
     * @return iterable<int, array<string, mixed>>
     */
    private function exportRows(array $filters, string $search, int $chunkSize): iterable
    {
        $table = $this->connection->quoteIdentifier($this->schema->table);
        [$whereSql, $params] = $this->buildWhereClause($filters, $search);
        $columns = $this->schema->visibleColumns();
        $referenceLabels = $this->buildReferenceLabels($columns);

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
                $displayRow = [];
                foreach ($columns as $column) {
                    $rawValue = (string) ($row[$column->name] ?? '');
                    $displayRow[$column->name] = $column->reference !== null
                        ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                        : $rawValue;
                }
                yield $displayRow;
            }

            $offset += $chunkSize;

            if (count($rows) < $chunkSize) {
                break;
            }
        }
    }

    /** @param Column[] $columns @return array<string, array<string, string>> */
    private function buildReferenceLabels(array $columns): array
    {
        $referenceLabels = [];
        foreach ($columns as $column) {
            if ($column->reference !== null) {
                foreach ($this->referenceOptions($column) as $option) {
                    $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
                }
            }
        }

        return $referenceLabels;
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

    /** find/update/delete aplican las mismas condiciones base que el listado (scoping): un id de otro tenant/ambito simplemente no matchea. */
    public function find(mixed $primaryKeyValue): ?array
    {
        $pk = $this->requirePrimaryKey();
        $table = $this->connection->quoteIdentifier($this->schema->table);
        $pkColumn = $this->connection->quoteIdentifier($pk->name);
        [$baseSql, $baseParams] = $this->baseConditionsSql();
        $whereSql = implode(' AND ', array_merge(["{$pkColumn} = :pk"], $baseSql));

        $stmt = $this->connection->pdo()->prepare("SELECT * FROM {$table} WHERE {$whereSql} LIMIT 1");
        $stmt->execute(['pk' => $primaryKeyValue] + $baseParams);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Los valores en $this->insertDefaults sobreescriben cualquier dato enviado por el cliente para esas columnas (ej. forzar empresa_id del usuario actual). */
    public function insert(array $data): string
    {
        $data = array_merge($this->filterToKnownColumns($data), $this->insertDefaults);

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
        [$baseSql, $baseParams] = $this->baseConditionsSql();
        $whereSql = implode(' AND ', array_merge(["{$pkColumn} = :__pk"], $baseSql));

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $whereSql);

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($data + ['__pk' => $primaryKeyValue] + $baseParams);
    }

    public function delete(mixed $primaryKeyValue): void
    {
        $pk = $this->requirePrimaryKey();
        $table = $this->connection->quoteIdentifier($this->schema->table);
        $pkColumn = $this->connection->quoteIdentifier($pk->name);
        [$baseSql, $baseParams] = $this->baseConditionsSql();
        $whereSql = implode(' AND ', array_merge(["{$pkColumn} = :pk"], $baseSql));
        $params = ['pk' => $primaryKeyValue] + $baseParams;

        if ($this->softDeleteColumn !== null) {
            $softColumn = $this->connection->quoteIdentifier($this->softDeleteColumn);
            $stmt = $this->connection->pdo()->prepare("UPDATE {$table} SET {$softColumn} = 1 WHERE {$whereSql}");
            $stmt->execute($params);
            return;
        }

        $stmt = $this->connection->pdo()->prepare("DELETE FROM {$table} WHERE {$whereSql}");
        $stmt->execute($params);
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
