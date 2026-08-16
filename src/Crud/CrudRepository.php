<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Database\Connection;
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
    ) {
    }

    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = '', string $orderDir = 'ASC'): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $table = $this->connection->quoteIdentifier($this->schema->table);

        $orderSql = '';
        if ($orderBy !== '' && $this->schema->column($orderBy) !== null) {
            $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $orderSql = 'ORDER BY ' . $this->connection->quoteIdentifier($orderBy) . ' ' . $dir;
        }

        $sql = "SELECT * FROM {$table} {$orderSql} LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $total = (int) $this->connection->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => (int) max(1, ceil($total / $perPage)),
        ];
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

        $stmt = $this->connection->pdo()->prepare("DELETE FROM {$table} WHERE {$pkColumn} = :pk");
        $stmt->execute(['pk' => $primaryKeyValue]);
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
