<?php

namespace Appylogi\AppyCrud\Schema;

/**
 * Overrides opcionales sobre lo que la introspeccion automatica detecta.
 * Permite al desarrollador ajustar labels, tipos de input, visibilidad, etc.
 * sin tener que describir la tabla completa a mano.
 *
 * Ejemplo:
 *   new TableConfig([
 *       'id'         => ['hidden' => true],
 *       'created_at' => ['hidden' => true, 'readOnly' => true],
 *       'email'      => ['label' => 'Correo', 'inputType' => 'email'],
 *   ]);
 */
class TableConfig
{
    public function __construct(private array $columnOverrides = [])
    {
    }

    public function overridesFor(string $columnName): array
    {
        return $this->columnOverrides[$columnName] ?? [];
    }

    public function applyTo(Column $column): Column
    {
        $overrides = $this->overridesFor($column->name);

        if ($overrides === []) {
            return $column;
        }

        foreach ($overrides as $property => $value) {
            if (property_exists($column, $property)) {
                $column->$property = $value;
            }
        }

        return $column;
    }
}
