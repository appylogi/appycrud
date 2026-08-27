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
 *
 * Tambien acepta un titulo/subtitulo propios para el listado (equivalente al
 * setSubject($title, $subtitle) de GroceryCrud) -- si no se pasan, AppyCrud
 * usa el texto por defecto "Listado de :table" segun el idioma activo.
 *
 * Ejemplo:
 *   new TableConfig(title: 'Bancos', subtitle: 'Listado de Bancos');
 *
 * Por defecto las columnas se muestran en el orden fisico de la tabla en
 * BD (el que devuelve la introspeccion) -- no siempre coincide con el
 * orden logico que tenia la app original (ej. GroceryCrud con
 * ->columns([...])). `columnOrder` fuerza un orden explicito: las
 * columnas listadas van primero en ese orden, cualquier columna de la
 * tabla que no se mencione conserva su posicion relativa original y
 * queda al final (no hace falta listarlas todas).
 *
 * Ejemplo:
 *   new TableConfig(columnOrder: ['cliente', 'tarifa', 'fecha_inicial']);
 */
class TableConfig
{
    public function __construct(
        private array $columnOverrides = [],
        private ?int $perPage = null,
        private ?array $perPageOptions = null,
        private ?string $title = null,
        private ?string $subtitle = null,
        private ?array $columnOrder = null,
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
            if (!property_exists($column, $property)) {
                continue;
            }

            try {
                $column->$property = $value;
            } catch (\TypeError $e) {
                $type = get_debug_type($value);
                throw new \InvalidArgumentException(
                    "AppyCrud: el override '{$property}' de la columna '{$column->name}' recibio un valor de tipo "
                    . "'{$type}' que Column::\${$property} no acepta. Revisa docs/uso.md#tableconfig-overrides-por-columna.",
                    previous: $e
                );
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

    /** null si esta tabla no fijo un titulo propio — AppyCrud cae al default "Listado de :table". */
    public function title(): ?string
    {
        return $this->title;
    }

    /** null si esta tabla no fijo un subtitulo — no se muestra nada debajo del titulo. */
    public function subtitle(): ?string
    {
        return $this->subtitle;
    }

    /** null si esta tabla no fijo un orden propio — AppyCrud usa el orden fisico de la BD. */
    public function columnOrder(): ?array
    {
        return $this->columnOrder;
    }
}
