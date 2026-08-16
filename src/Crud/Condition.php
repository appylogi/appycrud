<?php

namespace Appylogi\AppyCrud\Crud;

/**
 * Condicion WHERE base, aplicada SIEMPRE (ademas de filtros/busqueda del
 * usuario) a listado, exportacion, ver, editar y eliminar de un mismo
 * registro. Pensada para scoping (multi-tenant, "solo mis registros",
 * restringir por estado, etc.) — no es opcional para el usuario final,
 * la define el integrador via AppyCrud::options['where'].
 *
 * Ejemplos:
 *   Condition::where('empresa_id', '=', 5)
 *   Condition::whereIn('estado', [1, 2, 3])
 *   Condition::whereNotIn('tipo', ['borrador'])
 *   Condition::whereNull('eliminado_en')
 */
class Condition
{
    private function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {
    }

    public static function where(string $column, string $operator, mixed $value): self
    {
        return new self($column, strtoupper($operator), $value);
    }

    public static function whereIn(string $column, array $values): self
    {
        return new self($column, 'IN', $values);
    }

    public static function whereNotIn(string $column, array $values): self
    {
        return new self($column, 'NOT IN', $values);
    }

    public static function whereNull(string $column): self
    {
        return new self($column, 'IS NULL', null);
    }

    public static function whereNotNull(string $column): self
    {
        return new self($column, 'IS NOT NULL', null);
    }
}
