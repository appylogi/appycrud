<?php

namespace Appylogi\AppyCrud\Schema;

/**
 * Catalogo de tipos de campo aceptados en Column::$inputType (via override
 * de TableConfig). Varios nombres son alias intencionales del mismo widget
 * (ej. 'date' y 'native_date' se renderizan igual: <input type="date">,
 * sin JS de terceros) para que se pueda usar la nomenclatura que ya conoces
 * de otras herramientas.
 *
 * strategy() traduce cualquiera de estos nombres (o uno no reconocido) a
 * una de las pocas estrategias de render que realmente implementa
 * TailwindRenderer::renderField().
 */
class FieldType
{
    public const BOOLEAN = 'boolean';
    public const COLOR = 'color';
    public const DATE = 'date';
    public const DATETIME = 'datetime';
    public const DROPDOWN = 'dropdown';
    public const DROPDOWN_SEARCH = 'dropdown_search';
    public const EMAIL = 'email';
    public const ENUM = 'enum';
    public const ENUM_SEARCHABLE = 'enum_searchable';
    public const FLOAT = 'float';
    public const HIDDEN = 'hidden';
    public const INT = 'int';
    public const INVISIBLE = 'invisible';
    public const MULTISELECT_NATIVE = 'multiselect_native';
    public const MULTISELECT_SEARCHABLE = 'multiselect_searchable';
    public const NATIVE_DATE = 'native_date';
    public const NATIVE_DATETIME = 'native_datetime';
    public const NATIVE_TIME = 'native_time';
    public const NUMERIC = 'numeric';
    public const PASSWORD = 'password';
    public const PASSWORD_TOGGLE = 'password_toggle';
    public const RELATIONAL_NATIVE = 'relational_native';
    public const STRING = 'string';
    public const TEXT = 'text';
    public const TIMESTAMP = 'timestamp';
    public const FILE = 'file';
    public const RICHTEXT = 'richtext';

    /** Estrategias de render que implementa TailwindRenderer::renderField(). */
    public const STRATEGY_TEXT = 'text_input';
    public const STRATEGY_TEXTAREA = 'textarea';
    public const STRATEGY_CHECKBOX = 'checkbox';
    public const STRATEGY_INT = 'number_int';
    public const STRATEGY_FLOAT = 'number_float';
    public const STRATEGY_DATE = 'date';
    public const STRATEGY_DATETIME = 'datetime-local';
    public const STRATEGY_TIME = 'time';
    public const STRATEGY_EMAIL = 'email';
    public const STRATEGY_COLOR = 'color';
    public const STRATEGY_PASSWORD = 'password';
    public const STRATEGY_PASSWORD_TOGGLE = 'password_toggle';
    public const STRATEGY_HIDDEN = 'hidden';
    public const STRATEGY_INVISIBLE = 'invisible';
    public const STRATEGY_SELECT = 'select';
    public const STRATEGY_SELECT_SEARCHABLE = 'select_searchable';
    public const STRATEGY_MULTISELECT = 'multiselect';
    public const STRATEGY_MULTISELECT_SEARCHABLE = 'multiselect_searchable';
    public const STRATEGY_FILE = 'file';
    public const STRATEGY_RICHTEXT = 'richtext';

    private const MAP = [
        self::BOOLEAN => self::STRATEGY_CHECKBOX,
        'checkbox' => self::STRATEGY_CHECKBOX,
        self::COLOR => self::STRATEGY_COLOR,
        self::DATE => self::STRATEGY_DATE,
        self::NATIVE_DATE => self::STRATEGY_DATE,
        self::DATETIME => self::STRATEGY_DATETIME,
        self::NATIVE_DATETIME => self::STRATEGY_DATETIME,
        self::TIMESTAMP => self::STRATEGY_DATETIME,
        'datetime-local' => self::STRATEGY_DATETIME,
        self::NATIVE_TIME => self::STRATEGY_TIME,
        'time' => self::STRATEGY_TIME,
        self::DROPDOWN => self::STRATEGY_SELECT,
        self::ENUM => self::STRATEGY_SELECT,
        self::RELATIONAL_NATIVE => self::STRATEGY_SELECT,
        self::DROPDOWN_SEARCH => self::STRATEGY_SELECT_SEARCHABLE,
        self::ENUM_SEARCHABLE => self::STRATEGY_SELECT_SEARCHABLE,
        self::EMAIL => self::STRATEGY_EMAIL,
        self::FLOAT => self::STRATEGY_FLOAT,
        self::NUMERIC => self::STRATEGY_FLOAT,
        'number' => self::STRATEGY_FLOAT,
        self::INT => self::STRATEGY_INT,
        self::HIDDEN => self::STRATEGY_HIDDEN,
        self::INVISIBLE => self::STRATEGY_INVISIBLE,
        self::MULTISELECT_NATIVE => self::STRATEGY_MULTISELECT,
        self::MULTISELECT_SEARCHABLE => self::STRATEGY_MULTISELECT_SEARCHABLE,
        self::PASSWORD => self::STRATEGY_PASSWORD,
        self::PASSWORD_TOGGLE => self::STRATEGY_PASSWORD_TOGGLE,
        self::STRING => self::STRATEGY_TEXT,
        self::TEXT => self::STRATEGY_TEXTAREA,
        'textarea' => self::STRATEGY_TEXTAREA,
        self::FILE => self::STRATEGY_FILE,
        self::RICHTEXT => self::STRATEGY_RICHTEXT,
    ];

    public static function strategy(string $inputType): string
    {
        return self::MAP[$inputType] ?? self::STRATEGY_TEXT;
    }

    public static function isMultiselect(string $inputType): bool
    {
        $strategy = self::strategy($inputType);

        return $strategy === self::STRATEGY_MULTISELECT || $strategy === self::STRATEGY_MULTISELECT_SEARCHABLE;
    }

    public static function isFile(string $inputType): bool
    {
        return self::strategy($inputType) === self::STRATEGY_FILE;
    }

    public static function isRichText(string $inputType): bool
    {
        return self::strategy($inputType) === self::STRATEGY_RICHTEXT;
    }
}
