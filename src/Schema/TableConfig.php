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
 *
 * Ademas de los overrides por columna, acepta ajustes de paginacion propios
 * de ESTA tabla (segundo/tercer argumento) — pensado para cuando distintas
 * tablas de la misma app necesitan un default distinto de "cuantos
 * registros por pagina" (una tabla de catalogo chica puede querer 50 de
 * entrada; un log grande, 10). Si no se pasan (quedan en null), AppyCrud usa
 * la opcion 'perPage'/'perPageOptions' del array de opciones del constructor,
 * y si tampoco esa se definio, el default final es 20 / [10, 20, 50, 100].
 *
 * Ejemplo:
 *   new TableConfig(
 *       columnOverrides: ['id' => ['hidden' => true]],
 *       perPage: 50,
 *       perPageOptions: [25, 50, 100],
 *   );
 */
class TableConfig
{
    public function __construct(
        private array $columnOverrides = [],
        private ?int $perPage = null,
        private ?array $perPageOptions = null,
    ) {
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

    /** null si esta tabla no fijo un perPage propio — AppyCrud cae a la opcion del constructor o al default 20. */
    public function perPage(): ?int
    {
        return $this->perPage;
    }

    /** null si esta tabla no fijo perPageOptions propias — AppyCrud cae a la opcion del constructor o al default [10, 20, 50, 100]. */
    public function perPageOptions(): ?array
    {
        return $this->perPageOptions;
    }
}
