# Manual técnico

Arquitectura interna de AppyCrud, pensado para quien vaya a leer el código, corregir un bug o agregar un tipo de campo nuevo. Si solo vas a *usar* la librería, ver [docs/uso.md](uso.md).

## Mapa de clases

```
AppyCrud                    Punto de entrada; despacha por accion ($_GET['action'])
├── Schema\TableIntrospector   Autodetecta columnas/FKs/ENUM por driver (mysql/pgsql/sqlite)
├── Schema\TableConfig         Aplica overrides sobre las Column autodetectadas
├── Schema\Column              Una columna: tipo, nullable, reference, options, rules, inputType...
├── Schema\FieldType           Catalogo de tipos de campo -> estrategia de render
├── Crud\CrudRepository        SELECT/INSERT/UPDATE/DELETE, todo con prepared statements
├── Crud\Condition             Condiciones WHERE (scoping, y filtro de opciones de una FK)
├── Crud\ManyToMany            Definicion de una relacion muchos-a-muchos (tabla pivote)
├── Crud\Validator             Reglas de validacion (Column::$rules)
├── Crud\Csrf                  Token CSRF por sesion
├── Crud\HookAbortException    Cancela un hook 'before*' con un mensaje
├── Crud\DeleteMode            Constantes CONFIRM/DIRECT/SOFT
├── Renderer\TailwindRenderer  Todo el HTML/CSS/JS (listado, formulario, vista, impresion)
└── Lang\Translator            i18n basado en arrays PHP (lang/*.php)
```

Ninguna de estas clases conoce `$_SERVER`/`$_SESSION` directamente salvo `Csrf` (necesita `$_SESSION` para el token) — `AppyCrud::handle()` recibe `$get`/`$post`/`$isAjax` como parametros explicitos, no los lee de las superglobales el mismo. Quien integra la libreria decide como llega esa data (`$_GET`/`$_POST` directo, o adaptado desde otro framework).

## `Column`: autodeteccion vs. override

`TableIntrospector::introspect()` construye un `Column` por columna real de la tabla (tipo, nullable, default, si es PK/auto-increment, largo maximo, y si es FK). `Column::guessInputType()` adivina un `inputType` razonable a partir del tipo de dato SQL (`int`→`number`, `text`→`textarea`, etc.) **solo si no se paso uno explicito** — por eso ENUM de MySQL puede fijar `inputType: FieldType::DROPDOWN` desde el introspector sin que el heuristico lo pise despues.

`TableConfig::applyTo(Column $column)` itera el array de overrides para esa columna y, por cada llave, hace `property_exists($column, $llave)` + asignacion directa. Esto es deliberadamente generico: **cualquier propiedad publica de `Column` es "override-able"** sin tener que mantener una lista cerrada de opciones soportadas. Al agregar una propiedad nueva a `Column`, ya es automaticamente configurable via `TableConfig` sin tocar `TableConfig`.

## `FieldType`: catalogo de tipos de campo

`Column::$inputType` acepta cualquiera de los nombres listados en `FieldType` (`boolean`, `color`, `date`, `dropdown`, `multiselect_native`, etc. — ver la tabla completa en [docs/uso.md](uso.md#tipos-de-campo)). Varios nombres son **alias intencionales** del mismo widget (`date` y `native_date` se renderizan igual) para no forzar una nomenclatura unica.

`FieldType::strategy(string $inputType): string` traduce cualquiera de esos nombres (o uno desconocido, con fallback a texto plano) a una de las ~15 **estrategias de render** que `TailwindRenderer::renderField()` realmente implementa (`STRATEGY_TEXT`, `STRATEGY_SELECT`, `STRATEGY_MULTISELECT`, etc.). Esta indireccion es la que permite tener 24 nombres de cara al usuario con solo ~15 implementaciones de render.

### Agregar un tipo de campo nuevo

1. Agregar la constante en `FieldType` (el nombre que usara el integrador en `inputType`).
2. Si es un widget realmente nuevo (no alias de uno existente), agregar tambien una constante `STRATEGY_*` y su entrada en el `match()` de `TailwindRenderer::renderField()`.
3. Si es solo un alias de un widget existente (ej. otro nombre para "select"), basta con mapearlo a la `STRATEGY_*` ya existente en `FieldType::MAP`.
4. Si el tipo necesita JS (como `password_toggle` o los `datalist`/multiselect buscables), el JS vive **una sola vez** en `TailwindRenderer::renderModalShell()` (se genera una vez por pagina de listado, no por campo).

### Origen de las opciones (`dropdown`/`enum`/`multiselect`)

`renderField()` decide de donde vienen las opciones de un `<select>`/checkboxes asi: si `$column->reference !== null` (llave foranea, real o forzada via override), las opciones vienen de la tabla referenciada (`CrudRepository::referenceOptions()`); si no, vienen de `$column->options` (definidas a mano en el override, o autodetectadas para `ENUM` de MySQL).

### Multiselect: almacenamiento

`multiselect_native`/`multiselect_searchable` no asumen una tabla de union (relacion muchos-a-muchos); los valores seleccionados se guardan como **texto separado por comas** en una sola columna (`FieldType::isMultiselect()` + `AppyCrud::normalizeMultiselectFields()` hacen el `implode(',', ...)` antes de validar/guardar). Es la opcion mas simple para un CRUD generico de una sola tabla; si tu caso necesita una tabla de union real, no esta cubierto por esta version.

## `CrudRepository`: WHERE unificado

Toda consulta que filtra filas (listado, exportar, busqueda global) pasa por `buildWhereClause()`, que combina en un solo `WHERE ... AND ...`:

1. Las condiciones base (`Condition[]`, scoping — ver `baseConditionsSql()`), **siempre** presentes.
2. El filtro de borrado logico (si `deleteMode` es `SOFT`).
3. Los filtros por columna que mando el usuario (`$filters`, AND entre si).
4. La busqueda global (`$search`, OR entre las columnas de texto).

Las condiciones base (punto 1) tambien se aplican a `find()`, `update()` y `delete()` — no solo al listado — precisamente para que el scoping sea una garantia de seguridad real (un id de otro tenant no matchea el `WHERE`) y no solo un filtro cosmetico de la tabla.

### `Condition`

Objeto inmutable con `column`, `operator`, `value`. Los operadores `IN`/`NOT IN` generan un placeholder por cada valor del array; un `IN` con un array vacio se traduce a `1 = 0` (no matchea nada) en vez de generar SQL invalido (`IN ()`). La conversion a SQL vive en `CrudRepository::conditionsToSql(array $conditions, string $paramPrefix)`, parametrizada por un prefijo — `baseConditionsSql()` la llama con prefijo `'base'` para el scoping, y `referenceOptions()` con `'refcond_' . $column->name` para las condiciones de una FK puntual, de modo que varios juegos de condiciones puedan combinarse en la misma consulta sin colisionar nombres de parametro.

### `insertDefaults`

Se aplican en `CrudRepository::insert()` con `array_merge($data, $this->insertDefaults)` — es decir, **despues** de filtrar por columnas conocidas, y sobreescribiendo cualquier valor que haya mandado el cliente para esas columnas. Es el complemento de seguridad de `where`: sin esto, un `where` que restringe por `empresa_id` no impide que alguien inserte un registro con un `empresa_id` distinto al suyo.

## `ManyToMany`: relaciones via tabla pivote

A diferencia de todo lo demas en `CrudRepository` (que opera sobre `$this->schema`, la tabla principal), los metodos `manyToManyOptions()`, `manyToManySelected()`, `syncManyToMany()` y `deleteManyToManyFor()` reciben la `ManyToMany` como parametro explicito — son operaciones sobre *otra* tabla (la pivote) que no forma parte del `TableSchema` de la tabla principal.

`syncManyToMany()` siempre borra todas las asociaciones existentes de ese id y vuelve a insertar las seleccionadas (no hace un diff) — mas simple de razonar y suficientemente rapido para el numero de filas tipico de un pivote (decenas, no millones). `AppyCrud` extrae la seleccion del POST (`m2m_{name}[]`) **antes** de aplicar `insertFields`/`editFields` o `restrictToFields()`, porque esos mecanismos filtran por nombres de columna real del schema — un campo `m2m_*` nunca es una columna real, asi que quedaria descartado si se dejara pasar por ese filtro.

El orden de operaciones importa: en `handleDelete`, `deleteManyToManyFor()` se llama **antes** de `repository->delete()` (limpia el pivote primero, evitando depender de `ON DELETE CASCADE` a nivel de base de datos, que no todos los motores/tablas tienen configurado). En `handleStore`/`handleUpdate`, `syncManyToMany()` se llama **despues** del insert/update exitoso (necesita el id del registro principal, que en el caso de insert recien se conoce tras `repository->insert()`).

## Hooks

`AppyCrud::hook(string $name): ?callable` es la unica puerta de entrada a `$this->hooks`. El contrato (`beforeInsert`/`beforeUpdate` retornan `array`; `beforeDelete` no retorna nada; cualquiera puede lanzar `HookAbortException`) se decidio deliberadamente via **excepcion para cancelar** en vez de un valor de retorno especial (`false`, `null`, etc.) — evita la ambiguedad de "¿que significa que el hook no retorne nada?" y es el patron mas idiomatico en PHP para "esta operacion no puede continuar". `afterInsert`/`afterUpdate`/`afterDelete` no participan de ese contrato: ya no hay nada que cancelar, son solo para efectos secundarios.

En `handleBulkDelete`, `beforeDelete`/`afterDelete` se invocan **por cada id** del lote (no una vez por el lote completo) — cada registro se evalua y borra de forma independiente, así que un id bloqueado por el hook no impide que los demas se borren.

## Exportacion por chunks

`CrudRepository::exportRows()` es un generador (`yield`) que pagina internamente con `LIMIT`/`OFFSET` en bloques de `$chunkSize` (1000 por defecto), en vez de traer todas las filas de una sola consulta. `exportCsv()`/`exportXls()`/`exportMarkdown()` consumen ese mismo generador — agregar un formato nuevo de exportacion es escribir un metodo que itere `exportRows()` y formatee cada fila, no reimplementar la paginacion.

## Render de listado: pagina completa vs. fragmento

`TailwindRenderer::renderList()` (pagina completa: titulo, toolbar, formulario de filtros) y `renderListBody()` (solo tabla + paginacion, usado por el fetch de filtrado/busqueda AJAX) comparten toda su logica de armado de filas en el metodo privado `renderListInner()`. `AppyCrud::handle()` decide cual devolver segun `$isAjax` en la accion por defecto (`list`).
