<?php

namespace Appylogi\AppyCrud\Crud;

/**
 * Lado de la tabla donde se muestra la columna de acciones (editar, ver,
 * eliminar, clonar, acciones custom por fila). Por defecto va a la derecha
 * (LeftToRight, como la mayoria de tablas de datos); LEFT existe para
 * integraciones donde ya es la convencion visual establecida (ej. appylogi).
 */
final class ActionsPosition
{
    public const RIGHT = 'right';
    public const LEFT = 'left';
}
