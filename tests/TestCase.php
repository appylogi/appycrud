<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base para los tests de AppyCrud: cada test arranca con su propia conexion
 * SQLite en memoria (rapida, sin depender de un servidor MySQL externo) y la
 * destruye al terminar. Usar exec() para el DDL/DML de fixture de cada test.
 */
abstract class TestCase extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Connection::create('sqlite::memory:');
        // Un guardar/actualizar/borrar exitoso normalmente termina en un
        // redirect() real (header()+exit()) -- un exit() real mataria el
        // proceso del test runner. Ver AppyCrud::$exitOnRedirect.
        AppyCrud::$exitOnRedirect = false;
    }

    protected function tearDown(): void
    {
        AppyCrud::$exitOnRedirect = true;
        parent::tearDown();
    }

    protected function exec(string $sql): void
    {
        $this->connection->pdo()->exec($sql);
    }

    /** GET/POST simulados: nunca se usan cookies/superglobales reales, se pasan directo a handle(). */
    protected function get(array $overrides = []): array
    {
        return $overrides;
    }
}
