<?php

namespace Appylogi\AppyCrud\Database;

use PDO;
use PDOException;

/**
 * Envuelve PDO para soportar cualquier motor (mysql, pgsql, sqlite, sqlsrv...)
 * a partir de un DSN estandar, sin acoplar el resto de la libreria a un driver.
 */
class Connection
{
    private PDO $pdo;
    private string $driver;

    private function __construct()
    {
    }

    public static function create(string $dsn, ?string $user = null, ?string $password = null, array $options = []): self
    {
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $password, $options + $defaultOptions);
        } catch (PDOException $e) {
            throw new PDOException('AppyCrud no pudo conectar a la base de datos: ' . $e->getMessage(), (int) $e->getCode());
        }

        return self::fromPdo($pdo);
    }

    public static function fromPdo(PDO $pdo): self
    {
        $instance = new self();
        $instance->pdo = $pdo;
        $instance->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            'pgsql', 'sqlite' => '"' . str_replace('"', '""', $identifier) . '"',
            'sqlsrv' => '[' . str_replace(']', ']]', $identifier) . ']',
            default => $identifier,
        };
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }
}
