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

    /**
     * Reordena las columnas segun $order (lista de nombres, ej. lo que
     * TableConfig::columnOrder() trae desde el override del desarrollador).
     * Las columnas nombradas en $order van primero, en ese orden; cualquier
     * columna de la tabla que NO este en $order conserva su posicion
     * relativa original y queda al final -- asi no hay que listar todas
     * las columnas de la tabla para reordenar solo unas pocas. Nombres en
     * $order que no correspondan a ninguna columna real se ignoran.
     */
    public function reorder(array $order): void
    {
        $ordered = [];

        foreach ($order as $name) {
            if (isset($this->columns[$name])) {
                $ordered[$name] = $this->columns[$name];
            }
        }

        foreach ($this->columns as $name => $column) {
            if (!isset($ordered[$name])) {
                $ordered[$name] = $column;
            }
        }

        $this->columns = $ordered;
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
