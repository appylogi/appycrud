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
├── Crud\Condition             Condiciones WHERE base (scoping)
├── Crud\Validator             Reglas de validacion (Column::$rules)
├── Crud\Csrf                  Token CSRF por sesion
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

Objeto inmutable con `column`, `operator`, `value`. Los operadores `IN`/`NOT IN` generan un placeholder por cada valor del array (`:base_0_0`, `:base_0_1`, ...); un `IN` con un array vacio se traduce a `1 = 0` (no matchea nada) en vez de generar SQL invalido (`IN ()`).

### `insertDefaults`

Se aplican en `CrudRepository::insert()` con `array_merge($data, $this->insertDefaults)` — es decir, **despues** de filtrar por columnas conocidas, y sobreescribiendo cualquier valor que haya mandado el cliente para esas columnas. Es el complemento de seguridad de `where`: sin esto, un `where` que restringe por `empresa_id` no impide que alguien inserte un registro con un `empresa_id` distinto al suyo.

## Exportacion por chunks

`CrudRepository::exportRows()` es un generador (`yield`) que pagina internamente con `LIMIT`/`OFFSET` en bloques de `$chunkSize` (1000 por defecto), en vez de traer todas las filas de una sola consulta. `exportCsv()`/`exportXls()`/`exportMarkdown()` consumen ese mismo generador — agregar un formato nuevo de exportacion es escribir un metodo que itere `exportRows()` y formatee cada fila, no reimplementar la paginacion.

## Render de listado: pagina completa vs. fragmento

`TailwindRenderer::renderList()` (pagina completa: titulo, toolbar, formulario de filtros) y `renderListBody()` (solo tabla + paginacion, usado por el fetch de filtrado/busqueda AJAX) comparten toda su logica de armado de filas en el metodo privado `renderListInner()`. `AppyCrud::handle()` decide cual devolver segun `$isAjax` en la accion por defecto (`list`).
