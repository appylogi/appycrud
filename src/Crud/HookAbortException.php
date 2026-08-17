<?php

namespace Appylogi\AppyCrud\Crud;

use RuntimeException;

/**
 * Lanzala desde un hook 'beforeInsert'/'beforeUpdate'/'beforeDelete' para
 * cancelar la operacion. El mensaje se muestra al usuario (en el mismo
 * modal, sin perder los datos ya escritos, para insert/update; para delete
 * simplemente no se borra nada y la pagina se recarga igual).
 */
class HookAbortException extends RuntimeException
{
}
