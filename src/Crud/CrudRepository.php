<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\FieldType;
use Appylogi\AppyCrud\Schema\TableIntrospector;
use Appylogi\AppyCrud\Schema\TableSchema;
use PDOException;
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
        return $this->conditionsToSql($this->baseConditions, 'base');
    }

    /**
     * Traduce un array de Condition a fragmentos SQL + params, con un prefijo
     * de parametro propio para poder combinar varios juegos de condiciones
     * (base + las de una referencia puntual) sin colisionar nombres.
     * @param Condition[] $conditions
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function conditionsToSql(array $conditions, string $paramPrefix): array
    {
        $sqlParts = [];
        $params = [];

        foreach ($conditions as $index => $condition) {
            $quotedColumn = $this->connection->quoteIdentifier($condition->column);
            $prefix = ':' . $paramPrefix . '_' . $index . '_';

            if ($condition->operator === 'IN' || $condition->operator === 'NOT IN') {
                $values = (array) $condition->value;

                if ($values === []) {
                    // IN() vacio no es SQL valido; una condicion sin valores no debe matchear nada.
                    $sqlParts[] = '1 = 0';
                    continue;
                }

                $placeholders = [];
                foreach (array_values($values) as $i => $value) {
                    $key = $prefix . $i;
                    $placeholders[] = $key;
                    $params[$key] = $value;
                }

                $sqlParts[] = "{$quotedColumn} {$condition->operator} (" . implode(', ', $placeholders) . ')';
            } elseif ($condition->operator === 'IS NULL' || $condition->operator === 'IS NOT NULL') {
                $sqlParts[] = "{$quotedColumn} {$condition->operator}";
            } else {
                $key = $prefix . '0';
                $sqlParts[] = "{$quotedColumn} {$condition->operator} {$key}";
                $params[$key] = $condition->value;
            }
        }

        return [$sqlParts, $params];
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = '', string $orderDir = 'ASC', array $filters = [], string $search = '', array $advancedFilters = []): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $table = $this->connection->quoteIdentifier($this->schema->table);

        $orderSql = '';
        $orderParams = [];
        $orderColumn = $orderBy !== '' ? $this->schema->column($orderBy) : null;
        if ($orderColumn !== null) {
            $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

            if ($orderColumn->reference !== null) {
                // Ordenar una FK por su id crudo no dice nada al usuario (ej.
                // "Ciudad Origen" saldria por id, no alfabetico) -- se ordena
                // por el mismo label que ya se muestra en la columna.
                [$orderExpr, $orderParams] = $this->buildReferenceOrderExpression($orderColumn);
                $orderSql = "ORDER BY {$orderExpr} {$dir}";
            } else {
                $orderSql = 'ORDER BY ' . $this->connection->quoteIdentifier($orderBy) . ' ' . $dir;
            }
        }

        [$whereSql, $params] = $this->buildWhereClause($filters, $search, $advancedFilters);

        $sql = "SELECT * FROM {$table} {$whereSql} {$orderSql} LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params + $orderParams as $key => $value) {
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
    private function buildWhereClause(array $filters, string $search = '', array $advancedFilters = []): array
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
                if (FieldType::strategy($column->inputType ?? '') === FieldType::STRATEGY_CHECKBOX) {
                    continue;
                }

                $quotedColumn = $this->connection->quoteIdentifier($column->name);
                $paramKey = ':search_' . $column->name;

                if ($column->reference !== null) {
                    [$fragment, $refParams] = $this->buildReferenceSearchFragment($column, $quotedColumn, $paramKey);
                    $searchConditions[] = $fragment;
                    $params += $refParams;
                    $params[$paramKey] = '%' . $search . '%';
                    continue;
                }

                $searchConditions[] = "{$quotedColumn} LIKE {$paramKey}";
                $params[$paramKey] = '%' . $search . '%';
            }

            if ($searchConditions !== []) {
                $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            }
        }

        [$advancedSql, $advancedParams] = $this->buildAdvancedFilterSql($advancedFilters);
        if ($advancedSql !== null) {
            $conditions[] = $advancedSql;
            $params += $advancedParams;
        }

        $sql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$sql, $params];
    }

    /** Operadores validos del constructor de filtros avanzado -> fragmento SQL. Los que no llevan valor (IS [NOT] NULL) se marcan aparte. */
    private const ADVANCED_FILTER_OPERATORS = [
        'eq' => '=',
        'neq' => '!=',
        'contains' => 'LIKE',
        'not_contains' => 'NOT LIKE',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'is_null' => 'IS NULL',
        'is_not_null' => 'IS NOT NULL',
    ];

    /**
     * Combina las filas del constructor visual (campo + operador + valor),
     * cada una precedida por un conector AND/OR respecto de la anterior, de
     * IZQUIERDA A DERECHA — se agrupan explicitamente con parentesis en ese
     * orden para que el resultado no dependa de la precedencia normal de
     * AND/OR en SQL (donde AND siempre liga mas fuerte que OR).
     * @param array<int, array{field: string, op: string, value: mixed, conn: string}> $rows
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function buildAdvancedFilterSql(array $rows): array
    {
        $acc = null;
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            $field = (string) ($row['field'] ?? '');
            $op = (string) ($row['op'] ?? '');
            $value = $row['value'] ?? '';
            $connector = strtoupper((string) ($row['conn'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';

            $column = $this->schema->column($field);
            $sqlOperator = self::ADVANCED_FILTER_OPERATORS[$op] ?? null;

            if ($column === null || $sqlOperator === null) {
                continue;
            }

            $quotedColumn = $this->connection->quoteIdentifier($field);
            $isNullCheck = $op === 'is_null' || $op === 'is_not_null';

            if (!$isNullCheck && ($value === '' || $value === null)) {
                continue;
            }

            if ($isNullCheck) {
                $fragment = "{$quotedColumn} {$sqlOperator}";
            } else {
                $paramKey = ':advf_' . $i;
                $fragment = "{$quotedColumn} {$sqlOperator} {$paramKey}";
                $params[$paramKey] = $op === 'contains' || $op === 'not_contains' ? '%' . $value . '%' : $value;
            }

            $acc = $acc === null ? $fragment : "({$acc} {$connector} {$fragment})";
            $i++;
        }

        return [$acc, $params];
    }

    /**
     * Exporta las filas (respetando filtros y busqueda activos) a CSV,
     * escribiendo por bloques al stream $output en vez de acumular todo en memoria.
     */
    public function exportCsv($output, array $filters = [], int $chunkSize = 1000, string $search = '', array $advancedFilters = []): void
    {
        $columns = $this->schema->visibleColumns();
        fputcsv($output, array_map(fn (Column $c) => $c->label, $columns));

        foreach ($this->exportRows($filters, $search, $chunkSize, $advancedFilters) as $displayRow) {
            fputcsv($output, array_values($displayRow));
        }
    }

    /**
     * Exporta a una tabla HTML con mimetype de Excel (application/vnd.ms-excel).
     * Excel abre HTML valido guardado con extension .xls sin depender de
     * ninguna libreria externa (PhpSpreadsheet, etc.).
     */
    public function exportXls($output, array $filters = [], int $chunkSize = 1000, string $search = '', array $advancedFilters = []): void
    {
        $columns = $this->schema->visibleColumns();

        fwrite($output, '<html><head><meta charset="UTF-8"></head><body><table border="1">');
        fwrite($output, '<tr>' . implode('', array_map(fn (Column $c) => '<th>' . htmlspecialchars($c->label, ENT_QUOTES) . '</th>', $columns)) . '</tr>');

        foreach ($this->exportRows($filters, $search, $chunkSize, $advancedFilters) as $displayRow) {
            fwrite($output, '<tr>' . implode('', array_map(fn ($v) => '<td>' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td>', $displayRow)) . '</tr>');
        }

        fwrite($output, '</table></body></html>');
    }

    /**
     * Exporta a una tabla en formato Markdown.
     */
    public function exportMarkdown($output, array $filters = [], int $chunkSize = 1000, string $search = '', array $advancedFilters = []): void
    {
        $columns = $this->schema->visibleColumns();
        $escape = fn ($v) => str_replace('|', '\\|', (string) $v);

        fwrite($output, '| ' . implode(' | ', array_map(fn (Column $c) => $escape($c->label), $columns)) . " |\n");
        fwrite($output, '| ' . implode(' | ', array_fill(0, count($columns), '---')) . " |\n");

        foreach ($this->exportRows($filters, $search, $chunkSize, $advancedFilters) as $displayRow) {
            fwrite($output, '| ' . implode(' | ', array_map($escape, $displayRow)) . " |\n");
        }
    }

    /**
     * Itera las filas que aplican (filtros + busqueda), ya resueltas a su
     * valor "para mostrar" (FK -> label), en bloques de $chunkSize sin
     * acumular el resultado completo en memoria.
     * @return iterable<int, array<string, mixed>>
     */
    private function exportRows(array $filters, string $search, int $chunkSize, array $advancedFilters = []): iterable
    {
        $table = $this->connection->quoteIdentifier($this->schema->table);
        [$whereSql, $params] = $this->buildWhereClause($filters, $search, $advancedFilters);
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

            // Completa labels de valores que este bloque necesita y que quedaron
            // fuera del cap de referenceOptions() (tablas de referencia grandes,
            // ej. miles de ciudades) -- sin esto se veria el valor crudo guardado
            // en vez del label en la exportacion.
            foreach ($columns as $column) {
                if ($column->reference === null) {
                    continue;
                }

                $missing = array_filter(
                    array_map(fn ($row) => $row[$column->name] ?? null, $rows),
                    fn ($v) => $v !== null && $v !== '' && !array_key_exists((string) $v, $referenceLabels[$column->name] ?? [])
                );

                if ($missing === []) {
                    continue;
                }

                foreach ($this->referenceOptions($column, mustIncludeValues: $missing) as $option) {
                    $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
                }
            }

            foreach ($rows as $row) {
                $displayRow = [];
                foreach ($columns as $column) {
                    $rawValue = (string) ($row[$column->name] ?? '');

                    if (FieldType::isRichText($column->inputType ?? '')) {
                        // Exportar (CSV/Excel/Markdown) es texto plano; el HTML solo tiene sentido en pantalla (listado/vista).
                        $displayRow[$column->name] = trim(preg_replace('/\s+/', ' ', strip_tags($rawValue)));
                        continue;
                    }

                    $displayRow[$column->name] = ($column->reference !== null || $column->options !== [])
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
                continue;
            }

            // Dropdown/enum estaticos (Column::$options, sin reference a otra tabla)
            // tambien resuelven su label en la exportacion, igual que una relacion.
            foreach ($column->options as $option) {
                $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
            }
        }

        return $referenceLabels;
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

        if (($pk = $this->schema->primaryKey()) !== null) {
            unset($data[$pk->name]);
        }

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
        $this->executeCatchingDuplicate($stmt, $data);

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
        $this->executeCatchingDuplicate($stmt, $data + ['__pk' => $primaryKeyValue] + $baseParams);
    }

    /**
     * Ejecuta el prepared statement; si la BD rechaza el INSERT/UPDATE por
     * una restriccion UNIQUE real (SQLSTATE 23000), lo convierte en
     * DuplicateValueException con el nombre de columna afectada cuando se
     * puede identificar (buscando el nombre de columna en el mensaje del
     * driver -- MySQL y Postgres lo incluyen). Es la red de seguridad final
     * contra condiciones de carrera; ver columnValueExists() para el
     * chequeo previo (mas rapido, mejor mensaje, pero no atomico).
     */
    private function executeCatchingDuplicate(\PDOStatement $stmt, array $params): void
    {
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $column = null;
            foreach ($this->schema->columns() as $c) {
                if ($c->unique && str_contains($e->getMessage(), $c->name)) {
                    $column = $c;
                    break;
                }
            }

            $label = $column?->label ?? 'valor';
            throw new DuplicateValueException(
                $column?->name ?? '',
                "Ya existe un registro con ese {$label}.",
            );
        }
    }

    /**
     * Chequeo previo (best-effort, no atomico) de si ya existe otra fila con
     * ese valor para una columna marcada Column::$unique. $excludeId permite
     * ignorar la propia fila al editar. Respeta las condiciones base
     * (scoping): un valor duplicado fuera del scope del integrador no cuenta.
     */
    public function columnValueExists(string $column, mixed $value, mixed $excludeId = null): bool
    {
        $quotedTable = $this->connection->quoteIdentifier($this->schema->table);
        $quotedColumn = $this->connection->quoteIdentifier($column);
        [$baseSql, $baseParams] = $this->baseConditionsSql();
        $conditions = array_merge(["{$quotedColumn} = :value"], $baseSql);
        $params = ['value' => $value] + $baseParams;

        $pk = $this->schema->primaryKey();
        if ($pk !== null && $excludeId !== null) {
            $conditions[] = $this->connection->quoteIdentifier($pk->name) . ' <> :__excludeId';
            $params['__excludeId'] = $excludeId;
        }

        $whereSql = implode(' AND ', $conditions);
        $stmt = $this->connection->pdo()->prepare("SELECT 1 FROM {$quotedTable} WHERE {$whereSql} LIMIT 1");
        $stmt->execute($params);

        return $stmt->fetch() !== false;
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
     * Si el override de 'reference' trae 'conditions' (Condition[]), solo se
     * listan las filas de la tabla referenciada que las cumplen (ej. "solo
     * categorias activas").
     * @return array<int, array{value: mixed, label: string}>
     */
    /**
     * $mustIncludeValues: valores que estan realmente guardados en la(s) fila(s)
     * que se van a mostrar (listado/vista/edicion) y por lo tanto su label debe
     * resolver siempre, aunque la tabla referenciada tenga mas de $limit filas y
     * el valor guardado no caiga entre las primeras $limit por orden alfabetico
     * (ej. 'BOGOTA' en una tabla de 9000+ ciudades). Sin esto, el <select> del
     * formulario sigue mostrando solo las primeras $limit (por diseño, es un
     * limite de UX/performance), pero el LABEL de un valor ya guardado que quedo
     * fuera de esas $limit se resolvia mal (se veia el valor crudo en vez del
     * nombre) en listado/vista/impresion/exportacion.
     */
    /**
     * Fragmento SQL para que la busqueda global tambien alcance una columna de
     * referencia (FK): sin esto, buscar "Bogota" en un modulo cuyas columnas
     * visibles son todas relaciones (ej. Trayectos: ciudad_origen/destino,
     * tipo_trayecto...) no encontraba nada, porque el valor guardado es el id
     * numerico, no el texto. Resuelve por subquery contra la tabla
     * referenciada, filtrando por el mismo label armado en referenceOptions()
     * y respetando las mismas 'conditions' (ej. solo ciudades activas).
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildReferenceSearchFragment(Column $column, string $quotedColumn, string $paramKey): array
    {
        $refTable = $column->reference['table'];
        $valueColumn = $column->reference['column'];
        $labelColumn = $column->reference['label'] ?? $this->guessLabelColumn($refTable, $valueColumn);
        $conditions = $column->reference['conditions'] ?? [];

        $tableQ = $this->connection->quoteIdentifier($refTable);
        $valueQ = $this->connection->quoteIdentifier($valueColumn);
        $paramPrefix = 'searchref_' . $column->name;
        [$labelExpr, $labelParams] = $this->labelExpression($labelColumn, $paramPrefix . '_lbl');
        [$conditionSql, $conditionParams] = $this->conditionsToSql($conditions, $paramPrefix);

        $whereParts = $conditionSql;
        $whereParts[] = "{$labelExpr} LIKE {$paramKey}";
        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        $fragment = "{$quotedColumn} IN (SELECT {$valueQ} FROM {$tableQ} {$whereSql})";

        return [$fragment, $labelParams + $conditionParams];
    }

    /**
     * Expresion SQL para ordenar una columna de referencia (FK) por su label,
     * no por el id crudo -- sin esto, ordenar "Ciudad Origen" ordenaba por el
     * id numerico de tblciudades (sin sentido para el usuario). Subquery
     * correlacionada (una lectura indexada por PK por fila del resultado),
     * respetando el mismo label/conditions que referenceOptions().
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildReferenceOrderExpression(Column $column): array
    {
        $refTable = $column->reference['table'];
        $valueColumn = $column->reference['column'];
        $labelColumn = $column->reference['label'] ?? $this->guessLabelColumn($refTable, $valueColumn);
        $conditions = $column->reference['conditions'] ?? [];

        $tableQ = $this->connection->quoteIdentifier($refTable);
        $valueQ = $this->connection->quoteIdentifier($valueColumn);
        $paramPrefix = 'orderref_' . $column->name;
        [$labelExpr, $labelParams] = $this->labelExpression($labelColumn, $paramPrefix . '_lbl');
        [$conditionSql, $conditionParams] = $this->conditionsToSql($conditions, $paramPrefix);

        $outerTableQ = $this->connection->quoteIdentifier($this->schema->table);
        $quotedColumn = $this->connection->quoteIdentifier($column->name);

        $whereParts = $conditionSql;
        $whereParts[] = "{$valueQ} = {$outerTableQ}.{$quotedColumn}";
        $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        $expr = "(SELECT {$labelExpr} FROM {$tableQ} {$whereSql})";

        return [$expr, $labelParams + $conditionParams];
    }

    public function referenceOptions(Column $column, int $limit = 500, array $mustIncludeValues = []): array
    {
        if ($column->reference === null) {
            return [];
        }

        $refTable = $column->reference['table'];
        $valueColumn = $column->reference['column'];
        $labelColumn = $column->reference['label'] ?? $this->guessLabelColumn($refTable, $valueColumn);
        $conditions = $column->reference['conditions'] ?? [];

        $tableQ = $this->connection->quoteIdentifier($refTable);
        $valueQ = $this->connection->quoteIdentifier($valueColumn);
        [$labelExpr, $labelParams] = $this->labelExpression($labelColumn, 'refcond_' . $column->name . '_lbl');

        [$conditionSql, $params] = $this->conditionsToSql($conditions, 'refcond_' . $column->name);
        $whereSql = $conditionSql === [] ? '' : 'WHERE ' . implode(' AND ', $conditionSql);

        $sql = "SELECT {$valueQ} AS value, {$labelExpr} AS label FROM {$tableQ} {$whereSql} ORDER BY label LIMIT :limit";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params + $labelParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $mustIncludeValues = array_values(array_unique(array_filter(
            $mustIncludeValues,
            fn ($v) => $v !== null && $v !== ''
        )));
        $known = array_map(fn ($r) => (string) $r['value'], $rows);
        $missing = array_values(array_diff(array_map('strval', $mustIncludeValues), $known));

        if ($missing !== []) {
            $inPlaceholders = [];
            $missingParams = $params;
            foreach ($missing as $i => $value) {
                $ph = ':mustinclude_' . $column->name . '_' . $i;
                $inPlaceholders[] = $ph;
                $missingParams[$ph] = $value;
            }
            $missingWhere = ($conditionSql === [] ? '' : implode(' AND ', $conditionSql) . ' AND ')
                . "{$valueQ} IN (" . implode(', ', $inPlaceholders) . ')';
            $sql2 = "SELECT {$valueQ} AS value, {$labelExpr} AS label FROM {$tableQ} WHERE {$missingWhere}";
            $stmt2 = $this->connection->pdo()->prepare($sql2);
            foreach ($missingParams + $labelParams as $key => $value) {
                $stmt2->bindValue($key, $value);
            }
            $stmt2->execute();
            $rows = array_merge($rows, $stmt2->fetchAll());
        }

        return $rows;
    }

    /**
     * Busca opciones de una referencia por texto libre (label LIKE %q%),
     * para el combobox buscable con backend real (select2-style): a
     * diferencia de referenceOptions(), no depende de precargar/cachear un
     * top-N de la tabla completa -- funciona igual de bien con 50 filas que
     * con 500 mil, porque el filtrado ocurre en el motor de base de datos,
     * no en el navegador.
     * @return array<int, array{value: mixed, label: string}>
     */
    public function searchReferenceOptions(Column $column, string $query, int $limit = 20): array
    {
        if ($column->reference === null) {
            return [];
        }

        $refTable = $column->reference['table'];
        $valueColumn = $column->reference['column'];
        $labelColumn = $column->reference['label'] ?? $this->guessLabelColumn($refTable, $valueColumn);
        $conditions = $column->reference['conditions'] ?? [];

        $tableQ = $this->connection->quoteIdentifier($refTable);
        $valueQ = $this->connection->quoteIdentifier($valueColumn);
        [$labelExpr, $labelParams] = $this->labelExpression($labelColumn, 'refsearch_' . $column->name . '_lbl');

        [$conditionSql, $params] = $this->conditionsToSql($conditions, 'refsearch_' . $column->name);
        $whereSql = $conditionSql === [] ? '' : 'WHERE ' . implode(' AND ', $conditionSql);

        // El termino de busqueda filtra por el alias "label" via HAVING, no
        // repitiendo $labelExpr en un WHERE -- con EMULATE_PREPARES=false
        // (ver Connection), un mismo parametro nombrado no puede aparecer
        // dos veces en la misma consulta, y $labelExpr puede traer sus
        // propios parametros (labels compuestos tipo '{nombre}-{depto}').
        // MySQL permite HAVING sin GROUP BY referenciando un alias del
        // SELECT, pero SQLite no -- exige un GROUP BY previo ("a GROUP BY
        // clause is required before HAVING", detectado escribiendo un test).
        // Agrupar por $valueQ (la PK de la tabla referenciada, ya unica por
        // fila) es un no-op real en ambos motores, asi que se agrega siempre.
        $havingSql = '';
        if (trim($query) !== '') {
            $havingSql = 'HAVING label LIKE :refsearch_term';
            $params[':refsearch_term'] = '%' . $query . '%';
        }

        $sql = "SELECT {$valueQ} AS value, {$labelExpr} AS label FROM {$tableQ} {$whereSql} GROUP BY {$valueQ} {$havingSql} ORDER BY label LIMIT :limit";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params + $labelParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Traduce un nombre de columna simple, o una plantilla tipo GroceryCrud
     * ("{pkid} - {nombre} ({nit})"), a una expresion SQL con sus parametros.
     * Los fragmentos literales de la plantilla viajan parametrizados (no
     * concatenados a mano) para no depender del escapado de comillas del motor.
     * @return array{0: string, 1: array<string, string>}
     */
    private function labelExpression(string $labelColumn, string $paramPrefix): array
    {
        if (!str_contains($labelColumn, '{')) {
            return [$this->connection->quoteIdentifier($labelColumn), []];
        }

        $tokens = preg_split('/(\{[a-zA-Z0-9_]+\})/', $labelColumn, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $parts = [];
        $params = [];
        $i = 0;

        foreach ($tokens as $token) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $token, $m)) {
                $parts[] = $this->connection->quoteIdentifier($m[1]);
            } else {
                $placeholder = ':' . $paramPrefix . '_' . $i++;
                $parts[] = $placeholder;
                $params[$placeholder] = $token;
            }
        }

        $expr = $this->connection->driver() === 'sqlite'
            ? implode(' || ', $parts)
            : 'CONCAT(' . implode(', ', $parts) . ')';

        return [$expr, $params];
    }

    /**
     * Opciones para poblar el multiselect de una relacion muchos-a-muchos
     * (todas las filas de la tabla relacionada, no solo las ya asociadas).
     * @return array<int, array{value: mixed, label: string}>
     */
    /**
     * $relation->labelColumn acepta el mismo formato que reference.label: un
     * nombre de columna simple, o una plantilla tipo GroceryCrud
     * ("{pkid} - {nit} {nombre}") combinando varias columnas.
     */
    public function manyToManyOptions(ManyToMany $relation, int $limit = 500): array
    {
        $labelColumn = $relation->labelColumn ?? $this->guessLabelColumn($relation->relatedTable, $relation->relatedKey);

        $tableQ = $this->connection->quoteIdentifier($relation->relatedTable);
        $keyQ = $this->connection->quoteIdentifier($relation->relatedKey);
        [$labelExpr, $labelParams] = $this->labelExpression($labelColumn, 'm2m_' . $relation->name . '_lbl');
        [$conditionSql, $conditionParams] = $this->conditionsToSql($relation->conditions, 'm2m_' . $relation->name . '_cond');
        $whereSql = $conditionSql === [] ? '' : 'WHERE ' . implode(' AND ', $conditionSql);

        $sql = "SELECT {$keyQ} AS value, {$labelExpr} AS label FROM {$tableQ} {$whereSql} ORDER BY label LIMIT :limit";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($labelParams + $conditionParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Ids ya asociados a $primaryKeyValue en la tabla pivote de la relacion.
     * Antes de tocar el pivote se verifica que $primaryKeyValue este dentro
     * del scoping de baseConditions (mismo criterio que find/update/delete):
     * un id de otro tenant/ambito no debe exponer ni modificar sus filas de
     * pivote, aunque la tabla pivote en si no tenga columnas de scoping.
     * @return string[]
     */
    public function manyToManySelected(ManyToMany $relation, mixed $primaryKeyValue): array
    {
        if (!$this->isInScope($primaryKeyValue)) {
            return [];
        }

        $pivotQ = $this->connection->quoteIdentifier($relation->pivotTable);
        $foreignQ = $this->connection->quoteIdentifier($relation->foreignKey);
        $localQ = $this->connection->quoteIdentifier($relation->localKey);

        $stmt = $this->connection->pdo()->prepare("SELECT {$foreignQ} AS related_id FROM {$pivotQ} WHERE {$localQ} = :id");
        $stmt->execute(['id' => $primaryKeyValue]);

        return array_map(fn ($row) => (string) $row['related_id'], $stmt->fetchAll());
    }

    /**
     * Reemplaza las asociaciones de $primaryKeyValue en la tabla pivote por
     * $selectedValues (borra todas las existentes y vuelve a insertar).
     * Ver nota de scoping en manyToManySelected().
     */
    public function syncManyToMany(ManyToMany $relation, mixed $primaryKeyValue, array $selectedValues): void
    {
        if (!$this->isInScope($primaryKeyValue)) {
            return;
        }

        $pivotQ = $this->connection->quoteIdentifier($relation->pivotTable);
        $foreignQ = $this->connection->quoteIdentifier($relation->foreignKey);
        $localQ = $this->connection->quoteIdentifier($relation->localKey);

        $delete = $this->connection->pdo()->prepare("DELETE FROM {$pivotQ} WHERE {$localQ} = :id");
        $delete->execute(['id' => $primaryKeyValue]);

        if ($selectedValues === []) {
            return;
        }

        $insert = $this->connection->pdo()->prepare("INSERT INTO {$pivotQ} ({$localQ}, {$foreignQ}) VALUES (:id, :related)");
        foreach (array_unique($selectedValues) as $value) {
            $insert->execute(['id' => $primaryKeyValue, 'related' => $value]);
        }
    }

    /**
     * Limpia las asociaciones de $primaryKeyValue antes de eliminar el registro
     * principal (evita filas huerfanas en el pivote).
     * Ver nota de scoping en manyToManySelected().
     */
    public function deleteManyToManyFor(ManyToMany $relation, mixed $primaryKeyValue): void
    {
        if (!$this->isInScope($primaryKeyValue)) {
            return;
        }

        $pivotQ = $this->connection->quoteIdentifier($relation->pivotTable);
        $localQ = $this->connection->quoteIdentifier($relation->localKey);

        $stmt = $this->connection->pdo()->prepare("DELETE FROM {$pivotQ} WHERE {$localQ} = :id");
        $stmt->execute(['id' => $primaryKeyValue]);
    }

    /** true si $primaryKeyValue matchea alguna fila visible bajo baseConditions (o si no hay baseConditions definidas). */
    private function isInScope(mixed $primaryKeyValue): bool
    {
        if ($this->baseConditions === []) {
            return true;
        }

        return $this->find($primaryKeyValue) !== null;
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
