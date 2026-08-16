<?php

namespace Appylogi\AppyCrud\Schema;

/**
 * Descripcion completa de una tabla: nombre, columnas y llave primaria.
 * Es el resultado de la introspeccion, ya con los overrides aplicados.
 */
class TableSchema
{
    /** @var Column[] */
    private array $columns = [];

    public function __construct(public string $table)
    {
    }

    public function addColumn(Column $column): void
    {
        $this->columns[$column->name] = $column;
    }

    /** @return Column[] */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return Column[] columnas visibles en listado/formulario (sin las marcadas hidden) */
    public function visibleColumns(): array
    {
        return array_filter($this->columns, fn (Column $c) => !$c->hidden);
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function primaryKey(): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->isPrimaryKey) {
                return $column;
            }
        }

        return null;
    }
}
