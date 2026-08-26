<?php

namespace Appylogi\AppyCrud\Crud;

use RuntimeException;

/**
 * Se lanza cuando la base de datos rechaza un INSERT/UPDATE por violar una
 * restriccion UNIQUE real (SQLSTATE 23000), pese a que el chequeo previo de
 * Column::$unique no encontro nada -- la condicion de carrera clasica:
 * otro proceso inserto el mismo valor entre el SELECT de verificacion y el
 * INSERT/UPDATE de este. Es la red de seguridad final; el chequeo previo
 * (CrudRepository::columnValueExists()) resuelve el caso comun con un
 * mensaje mas rapido, sin depender de que la BD tenga el indice real.
 */
class DuplicateValueException extends RuntimeException
{
    public function __construct(public readonly string $column, string $message)
    {
        parent::__construct($message);
    }
}
