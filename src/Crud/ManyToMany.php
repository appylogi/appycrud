<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Schema\FieldType;

/**
 * Define una relacion muchos-a-muchos via tabla pivote (union), renderizada
 * como un campo multiselect en el formulario. No es una columna real de la
 * tabla principal: AppyCrud sincroniza la tabla pivote por separado, despues
 * de guardar el registro principal.
 *
 * Ejemplo: tareas <-> etiquetas via tareas_etiquetas(tarea_id, etiqueta_id)
 *
 *   new ManyToMany(
 *       name: 'etiquetas',
 *       pivotTable: 'tareas_etiquetas',
 *       localKey: 'tarea_id',
 *       foreignKey: 'etiqueta_id',
 *       relatedTable: 'etiquetas',
 *   )
 */
class ManyToMany
{
    /** @param Condition[] $conditions */
    public function __construct(
        public string $name,
        public string $pivotTable,
        public string $localKey,
        public string $foreignKey,
        public string $relatedTable,
        public string $relatedKey = 'id',
        public ?string $labelColumn = null,
        public string $label = '',
        public string $inputType = FieldType::MULTISELECT_NATIVE,
        public array $conditions = [],
    ) {
        if ($this->label === '') {
            $this->label = ucwords(str_replace(['_', '-'], ' ', $name));
        }
    }
}
