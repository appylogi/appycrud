<?php

namespace Appylogi\AppyCrud\Schema;

/**
 * Representa una columna de tabla, ya sea autodetectada o forzada por override.
 */
class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = true,
        public mixed $default = null,
        public bool $isPrimaryKey = false,
        public bool $isAutoIncrement = false,
        public ?int $maxLength = null,
        public string $label = '',
        public bool $hidden = false,
        public bool $readOnly = false,
        public ?string $inputType = null,
        /** @var array{table: string, column: string, label?: string}|null */
        public ?array $reference = null,
        /** @var string[] reglas de validacion tipo 'required', 'max:100', 'email', etc. */
        public array $rules = [],
    ) {
        if ($this->label === '') {
            $this->label = ucwords(str_replace(['_', '-'], ' ', $name));
        }

        if ($this->inputType === null) {
            $this->inputType = $this->guessInputType();
        }
    }

    private function guessInputType(): string
    {
        $type = strtolower($this->type);

        return match (true) {
            str_contains($type, 'int') => 'number',
            str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 'number',
            str_contains($type, 'bool') => 'checkbox',
            str_contains($type, 'text') => 'textarea',
            str_contains($type, 'date') && str_contains($type, 'time') => 'datetime-local',
            $type === 'date' => 'date',
            str_contains($type, 'time') => 'time',
            str_contains($type, 'email') => 'email',
            default => 'text',
        };
    }
}
