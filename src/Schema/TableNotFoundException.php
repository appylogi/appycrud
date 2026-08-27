<?php

namespace Appylogi\AppyCrud\Schema;

use RuntimeException;

/**
 * La tabla pedida no existe (o no tiene columnas visibles) en la conexion
 * actual. Comun en apps multi-tenant donde cada tenant tiene un subconjunto
 * distinto de tablas (ej. solo las integraciones de operador que ese
 * cliente realmente usa) -- AppyCrud::handle() la captura y muestra un
 * mensaje amigable en vez de dejarla escapar como un 500 sin contexto.
 */
class TableNotFoundException extends RuntimeException
{
}
