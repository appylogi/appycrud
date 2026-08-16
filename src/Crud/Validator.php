<?php

namespace Appylogi\AppyCrud\Crud;

use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Valida datos de entrada contra las reglas declaradas en cada Column
 * (Column::$rules), asignadas via TableConfig::applyTo(['rules' => [...]]).
 * Reglas soportadas: required, max:N, min:N, email, numeric.
 */
class Validator
{
    /** @return array<string, string[]> columna => mensajes de error */
    public static function validate(TableSchema $schema, array $data): array
    {
        $errors = [];

        foreach ($schema->columns() as $column) {
            if ($column->rules === []) {
                continue;
            }

            $value = $data[$column->name] ?? null;

            foreach ($column->rules as $rule) {
                $message = self::checkRule($column, $rule, $value);

                if ($message !== null) {
                    $errors[$column->name][] = $message;
                }
            }
        }

        return $errors;
    }

    private static function checkRule(Column $column, string $rule, mixed $value): ?string
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
        $isEmpty = $value === null || $value === '';

        return match ($name) {
            'required' => $isEmpty ? "{$column->label} es obligatorio." : null,
            'max' => (!$isEmpty && mb_strlen((string) $value) > (int) $param) ? "{$column->label} no puede superar {$param} caracteres." : null,
            'min' => (!$isEmpty && mb_strlen((string) $value) < (int) $param) ? "{$column->label} debe tener al menos {$param} caracteres." : null,
            'email' => (!$isEmpty && filter_var($value, FILTER_VALIDATE_EMAIL) === false) ? "{$column->label} debe ser un correo valido." : null,
            'numeric' => (!$isEmpty && !is_numeric($value)) ? "{$column->label} debe ser numerico." : null,
            default => null,
        };
    }
}
