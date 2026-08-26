<?php

namespace Appylogi\AppyCrud\Schema;

use Appylogi\AppyCrud\Database\Connection;
use RuntimeException;

/**
 * Autodetecta columnas, tipos y llave primaria de una tabla existente,
 * y opcionalmente aplica un TableConfig con overrides manuales.
 */
class TableIntrospector
{
    public function introspect(Connection $connection, string $table, ?TableConfig $config = null): TableSchema
    {
        $rows = match ($connection->driver()) {
            'mysql' => $this->introspectMysql($connection, $table),
            'pgsql' => $this->introspectPgsql($connection, $table),
            'sqlite' => $this->introspectSqlite($connection, $table),
            default => throw new RuntimeException("AppyCrud: driver '{$connection->driver()}' no soportado para introspeccion automatica."),
        };

        $foreignKeys = match ($connection->driver()) {
            'mysql' => $this->foreignKeysMysql($connection, $table),
            'pgsql' => $this->foreignKeysPgsql($connection, $table),
            'sqlite' => $this->foreignKeysSqlite($connection, $table),
            default => [],
        };

        $uniqueColumns = match ($connection->driver()) {
            'mysql' => $this->uniqueColumnsMysql($connection, $table),
            'pgsql' => $this->uniqueColumnsPgsql($connection, $table),
            'sqlite' => $this->uniqueColumnsSqlite($connection, $table),
            default => [],
        };

        $schema = new TableSchema($table);

        foreach ($rows as $row) {
            $enumOptions = $row['enumOptions'] ?? [];

            $isUnique = !$row['isPrimaryKey'] && in_array($row['name'], $uniqueColumns, true);

            $column = new Column(
                name: $row['name'],
                type: $row['type'],
                nullable: $row['nullable'],
                default: $row['default'],
                isPrimaryKey: $row['isPrimaryKey'],
                isAutoIncrement: $row['isAutoIncrement'],
                maxLength: $row['maxLength'],
                inputType: $enumOptions !== [] ? FieldType::DROPDOWN : null,
                reference: $foreignKeys[$row['name']] ?? null,
                options: $enumOptions,
                unique: $isUnique,
                uniqueInDb: $isUnique,
            );

            if ($config !== null) {
                $column = $config->applyTo($column);
            }

            $schema->addColumn($column);
        }

        return $schema;
    }

    /** @return array<string, array{table: string, column: string}> columna local => referencia */
    private function foreignKeysMysql(Connection $connection, string $table): array
    {
        $sql = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND table_name = :table
                  AND referenced_table_name IS NOT NULL";

        $stmt = $connection->pdo()->prepare($sql);
        $stmt->execute(['table' => $table]);

        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $r) {
            $foreignKeys[$r['COLUMN_NAME']] = [
                'table' => $r['REFERENCED_TABLE_NAME'],
                'column' => $r['REFERENCED_COLUMN_NAME'],
            ];
        }

        return $foreignKeys;
    }

    /** @return array<string, array{table: string, column: string}> */
    private function foreignKeysPgsql(Connection $connection, string $table): array
    {
        $sql = "SELECT kcu.column_name, ccu.table_name AS referenced_table_name, ccu.column_name AS referenced_column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_name = kcu.table_name
                JOIN information_schema.constraint_column_usage ccu
                    ON tc.constraint_name = ccu.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = :table";

        $stmt = $connection->pdo()->prepare($sql);
        $stmt->execute(['table' => $table]);

        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $r) {
            $foreignKeys[$r['column_name']] = [
                'table' => $r['referenced_table_name'],
                'column' => $r['referenced_column_name'],
            ];
        }

        return $foreignKeys;
    }

    /** @return array<string, array{table: string, column: string}> */
    private function foreignKeysSqlite(Connection $connection, string $table): array
    {
        $quoted = $connection->quoteIdentifier($table);
        $stmt = $connection->pdo()->query("PRAGMA foreign_key_list({$quoted})");

        $foreignKeys = [];
        foreach ($stmt->fetchAll() as $r) {
            $foreignKeys[$r['from']] = [
                'table' => $r['table'],
                'column' => $r['to'],
            ];
        }

        return $foreignKeys;
    }

    /**
     * Solo detecta indices UNIQUE de una sola columna (no compuestos): un
     * unique compuesto (ej. UNIQUE(cliente_id, codigo)) no se puede validar
     * de forma significativa columna por columna, asi que se ignora --
     * seguira funcionando como restriccion real en la BD, solo no aparece
     * reflejado como Column::$unique.
     * @return string[] nombres de columna con UNIQUE de una sola columna
     */
    private function uniqueColumnsMysql(Connection $connection, string $table): array
    {
        $sql = 'SHOW INDEX FROM ' . $connection->quoteIdentifier($table) . " WHERE Non_unique = 0 AND Key_name != 'PRIMARY'";
        $stmt = $connection->pdo()->query($sql);

        return $this->singleColumnIndexNames($stmt->fetchAll(), 'Key_name', 'Column_name');
    }

    /** @return string[] */
    private function uniqueColumnsPgsql(Connection $connection, string $table): array
    {
        $sql = "SELECT tc.constraint_name, kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'UNIQUE' AND tc.table_name = :table AND tc.table_schema = current_schema()";

        $stmt = $connection->pdo()->prepare($sql);
        $stmt->execute(['table' => $table]);

        return $this->singleColumnIndexNames($stmt->fetchAll(), 'constraint_name', 'column_name');
    }

    /** @return string[] */
    private function uniqueColumnsSqlite(Connection $connection, string $table): array
    {
        $quoted = $connection->quoteIdentifier($table);
        $indexes = $connection->pdo()->query("PRAGMA index_list({$quoted})")->fetchAll();

        $columns = [];
        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            $quotedIndex = $connection->quoteIdentifier($index['name']);
            $info = $connection->pdo()->query("PRAGMA index_info({$quotedIndex})")->fetchAll();

            if (count($info) === 1) {
                $columns[] = $info[0]['name'];
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Agrupa filas indice=>columna por nombre de indice/constraint y se
     * queda solo con los que tienen exactamente una columna (unique simple,
     * no compuesto).
     * @param array<int, array<string, mixed>> $rows
     * @return string[]
     */
    private function singleColumnIndexNames(array $rows, string $indexKey, string $columnKey): array
    {
        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[$row[$indexKey]][] = $row[$columnKey];
        }

        $columns = [];
        foreach ($byIndex as $indexColumns) {
            if (count($indexColumns) === 1) {
                $columns[] = $indexColumns[0];
            }
        }

        return array_values(array_unique($columns));
    }

    private function introspectMysql(Connection $connection, string $table): array
    {
        $sql = "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = :table
                ORDER BY ORDINAL_POSITION";

        $stmt = $connection->pdo()->prepare($sql);
        $stmt->execute(['table' => $table]);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'name' => $r['COLUMN_NAME'],
                'type' => $r['DATA_TYPE'],
                'nullable' => $r['IS_NULLABLE'] === 'YES',
                'default' => $r['COLUMN_DEFAULT'],
                'isPrimaryKey' => $r['COLUMN_KEY'] === 'PRI',
                'isAutoIncrement' => str_contains($r['EXTRA'], 'auto_increment'),
                'maxLength' => $r['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $r['CHARACTER_MAXIMUM_LENGTH'] : null,
                'enumOptions' => $r['DATA_TYPE'] === 'enum' ? $this->parseMysqlEnumOptions($r['COLUMN_TYPE']) : [],
            ];
        }

        if ($rows === []) {
            throw new RuntimeException("AppyCrud: la tabla '{$table}' no existe o no tiene columnas visibles.");
        }

        return $rows;
    }

    /**
     * MySQL expone ENUM('activo','inactivo') tal cual en COLUMN_TYPE; se
     * parsean los valores para poblar un <select> automaticamente, sin que
     * el integrador tenga que declararlos a mano.
     * @return array<int, array{value: string, label: string}>
     */
    private function parseMysqlEnumOptions(string $columnType): array
    {
        if (preg_match('/^enum\((.+)\)$/i', $columnType, $matches) !== 1) {
            return [];
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $valueMatches);

        $options = [];
        foreach ($valueMatches[1] as $rawValue) {
            $value = str_replace(["\\'", '\\\\'], ["'", '\\'], $rawValue);
            $options[] = ['value' => $value, 'label' => $value];
        }

        return $options;
    }

    private function introspectPgsql(Connection $connection, string $table): array
    {
        // table_schema = current_schema() evita mezclar columnas de una tabla
        // con el mismo nombre en otro schema (ej. public.usuarios vs auditoria.usuarios).
        $sql = "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default, c.character_maximum_length,
                       CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END AS is_primary_key
                FROM information_schema.columns c
                LEFT JOIN (
                    SELECT kcu.column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                        ON tc.constraint_name = kcu.constraint_name AND tc.table_name = kcu.table_name AND tc.table_schema = kcu.table_schema
                    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = :table1 AND tc.table_schema = current_schema()
                ) pk ON pk.column_name = c.column_name
                WHERE c.table_name = :table2 AND c.table_schema = current_schema()
                ORDER BY c.ordinal_position";

        $stmt = $connection->pdo()->prepare($sql);
        $stmt->execute(['table1' => $table, 'table2' => $table]);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $isPrimaryKey = (bool) $r['is_primary_key'];
            $rows[] = [
                'name' => $r['column_name'],
                'type' => $r['data_type'],
                'nullable' => $r['is_nullable'] === 'YES',
                'default' => $r['column_default'],
                'isPrimaryKey' => $isPrimaryKey,
                'isAutoIncrement' => $isPrimaryKey && is_string($r['column_default']) && str_contains($r['column_default'], 'nextval'),
                'maxLength' => $r['character_maximum_length'] !== null ? (int) $r['character_maximum_length'] : null,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException("AppyCrud: la tabla '{$table}' no existe o no tiene columnas visibles.");
        }

        return $rows;
    }

    private function introspectSqlite(Connection $connection, string $table): array
    {
        $quoted = $connection->quoteIdentifier($table);
        $stmt = $connection->pdo()->query("PRAGMA table_info({$quoted})");
        $columns = $stmt->fetchAll();

        if ($columns === []) {
            throw new RuntimeException("AppyCrud: la tabla '{$table}' no existe o no tiene columnas visibles.");
        }

        $rows = [];
        foreach ($columns as $c) {
            $rows[] = [
                'name' => $c['name'],
                'type' => $c['type'],
                'nullable' => (int) $c['notnull'] === 0,
                'default' => $c['dflt_value'],
                'isPrimaryKey' => (int) $c['pk'] > 0,
                'isAutoIncrement' => (int) $c['pk'] > 0 && strtolower($c['type']) === 'integer',
                'maxLength' => null,
            ];
        }

        return $rows;
    }
}
