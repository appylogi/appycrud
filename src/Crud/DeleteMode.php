<?php

namespace Appylogi\AppyCrud\Crud;

/**
 * Los 3 modos de borrado soportados por AppyCrud.
 */
final class DeleteMode
{
    /** Pide confirmacion (JS confirm) y borra fisicamente el registro. */
    public const CONFIRM = 'confirm';

    /** Borra fisicamente el registro sin preguntar. */
    public const DIRECT = 'direct';

    /** No borra: actualiza una columna indicada (borrado logico). */
    public const SOFT = 'soft';
}
