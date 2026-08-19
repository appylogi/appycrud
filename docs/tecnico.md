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
├── Crud\HtmlSanitizer         Sanitiza HTML de campos 'richtext' antes de guardar (whitelist via DOMDocument)
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

`TailwindRenderer::renderField()` deriva el asterisco visual de obligatorio de la misma condicion que ya generaba el atributo HTML `required` (`!$column->nullable`), asi que nunca pueden desincronizarse — no es un flag separado. Se excluye explicitamente `STRATEGY_CHECKBOX`: un booleano no-nullable con un default (`completada TINYINT NOT NULL DEFAULT 0`, tipico) no necesita que el usuario "lo llene", el checkbox ya tiene un estado valido sin tocarlo.

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

### Editor de texto enriquecido (`richtext` / `STRATEGY_RICHTEXT`)

`TailwindRenderer::renderRichText()` genera un `<div contenteditable>` + una barra de botones que llaman a `appycrudRichTextExec(editorId, command)` (JS en `renderModalShell()`, una sola vez por pagina), que hace `document.execCommand(command, false, null)` sobre el div enfocado. El valor real que viaja en el submit **no** es el `contenteditable` (los navegadores no envian el contenido de un div en un form) sino un `<input type="hidden">` sincronizado en cada evento `input` del div (`appycrudRichTextSync()`).

**Bug real encontrado y corregido durante el desarrollo:** los botones de la barra (`<button type="button" onclick="...">`) le robaban el foco al `contenteditable` en el `mousedown` que precede al `click`, colapsando la seleccion de texto *antes* de que `appycrudRichTextExec()` alcanzara a ejecutar `document.execCommand()` — el resultado era que la negrita/italica/etc. nunca se aplicaba a nada, silenciosamente (execCommand no lanza error si no hay seleccion, solo no hace nada). Se corrigio agregando `onmousedown="event.preventDefault()"` a cada boton, que evita el cambio de foco y preserva la seleccion activa durante el clic. Se verifico end-to-end en un navegador real (Chromium via MCP): sin el fix, `el.innerHTML` quedaba sin cambios tras click en "Negrita" con texto seleccionado; con el fix, `<b>...</b>` se aplica correctamente y se sincroniza al input oculto.

**Sanitizacion (`Crud\HtmlSanitizer::sanitize()`)** corre en `AppyCrud::normalizeRichTextFields()`, en el mismo punto del pipeline que `normalizeMultiselectFields()` (despues de `restrictToFields()`, antes de `Validator::validate()` — para que las reglas evaluen el valor que realmente se va a guardar). Implementacion: `DOMDocument::loadHTML()` envuelto en `<body>` con `<?xml encoding="UTF-8">` (sin ese prefijo, `DOMDocument` asume Latin-1 y corrompe acentos/eñes), luego un recorrido recursivo:

- Etiquetas en la lista blanca (`ALLOWED_TAGS`) sobreviven; sus atributos se descartan todos salvo `href` en `<a>`, validado con `isSafeUrl()` (sin esquema = relativa/segura; con esquema, debe estar en `ALLOWED_SCHEMES` = `http`/`https`/`mailto` — asi se bloquea `javascript:`).
- Etiquetas fuera de la lista blanca pero *no* en `STRIP_ENTIRELY_TAGS` se eliminan pero **se preserva su contenido** (sus hijos se re-parentan al nodo padre antes de quitarlas) — un `<img onerror="...">` desaparece pero el texto que lo rodeaba no se pierde.
- `script`/`style` estan en `STRIP_ENTIRELY_TAGS`: se eliminan **enteros, incluido su texto**. Sin este caso especial, el texto interno de un `<script>alert(1)</script>` sobreviviria como texto plano visible ("alert(1)") tras quitar solo la etiqueta — inofensivo (ya no es codigo ejecutable) pero confuso para el usuario.

No hay ninguna dependencia externa tipo HTMLPurifier — es deliberado, siguiendo la misma filosofia zero-dependencias del resto del proyecto.

**Modo avanzado (`richtext_advanced` / `FieldType::isRichTextAdvanced()`):** `renderRichText()` recibe un flag `$advanced` (resuelto por `TailwindRenderer::renderField()` a partir del `inputType` crudo de la columna, no de la estrategia — `FieldType::strategy()` colapsa `richtext` y `richtext_advanced` a la misma `STRATEGY_RICHTEXT`, asi que hay que mirar el nombre exacto para decidir la barra). Con el flag activo se agregan: un `<select>` de encabezados (`appycrudRichTextFormatBlock()` → `execCommand('formatBlock', false, '<h1>')` etc.), enlace/quitar-enlace (`appycrudRichTextLink()` usa `window.prompt()` para pedir la URL — sin modal propio, para no sumar mas JS del necesario), alineacion (`justifyLeft`/`justifyCenter`/`justifyRight`) y deshacer/rehacer. Sigue siendo 100% `document.execCommand`, cero dependencias nuevas — lo unico que cambia entre modos es cuantos botones se muestran y que `HtmlSanitizer` permite mas superficie (`h1`-`h3`, `style="text-align"`).

`HtmlSanitizer::ALLOWED_TAGS` incluye `h1`/`h2`/`h3` (necesarios para el `formatBlock` del modo avanzado, pero el sanitizador no distingue de donde vino el HTML — cualquier campo `richtext` simple que por algun motivo llegue con un `<h1>` tambien lo conserva). El manejo de `style` es un caso especial deliberadamente estrecho: solo se acepta en `<p>`/`<div>`, y solo se extrae y **reescribe entero** la propiedad `text-align` (`extractTextAlign()`, regex + whitelist de valores `left`/`center`/`right`/`justify`) — nunca se conserva el atributo `style` original tal cual, para que sea imposible colar otra propiedad CSS (`background`, `position`, etc.) agazapada junto a `text-align` en el mismo atributo.

**Rendering diferenciado por contexto** (`FieldType::isRichText()` en cada punto):

- Listado (`TailwindRenderer::richTextPreview()`) y exportaciones (`CrudRepository::exportRows()`): `strip_tags()` + colapsar espacios — texto plano, porque el HTML formateado no es legible/util en una celda de tabla angosta o en un CSV.
- Vista de solo lectura (`renderView()`) y el propio editor al re-abrir para editar: el HTML sanitizado se inyecta **sin escapar** (a diferencia de todos los demas tipos de campo, que pasan por `$this->e()`) — es la unica forma de que la negrita/listas se vean formateadas en vez de como texto con etiquetas literales. Esto es seguro *solo* porque el valor ya paso por `HtmlSanitizer::sanitize()` al guardarse; nunca se sanitiza en el momento de renderizar (seria tarde para los valores ya guardados sin sanitizar de una version anterior, si los hubiera).

### Multiselect: almacenamiento

`multiselect_native`/`multiselect_searchable` no asumen una tabla de union (relacion muchos-a-muchos); los valores seleccionados se guardan como **texto separado por comas** en una sola columna (`FieldType::isMultiselect()` + `AppyCrud::normalizeMultiselectFields()` hacen el `implode(',', ...)` antes de validar/guardar). Es la opcion mas simple para un CRUD generico de una sola tabla; si tu caso necesita una tabla de union real, no esta cubierto por esta version.

## `CrudRepository`: WHERE unificado

Toda consulta que filtra filas (listado, exportar, busqueda global) pasa por `buildWhereClause()`, que combina en un solo `WHERE ... AND ...`:

1. Las condiciones base (`Condition[]`, scoping — ver `baseConditionsSql()`), **siempre** presentes.
2. El filtro de borrado logico (si `deleteMode` es `SOFT`).
3. Los filtros por columna que mando el usuario (`$filters`, AND entre si).
4. La busqueda global (`$search`, OR entre las columnas de texto).
5. El constructor de filtro avanzado (`$advancedFilters`, ver mas abajo) — se agrega como un solo fragmento adicional, combinado con `AND` respecto a los puntos 1-4.

Las condiciones base (punto 1) tambien se aplican a `find()`, `update()` y `delete()` — no solo al listado — precisamente para que el scoping sea una garantia de seguridad real (un id de otro tenant no matchea el `WHERE`) y no solo un filtro cosmetico de la tabla.

### `Condition`

Objeto inmutable con `column`, `operator`, `value`. Los operadores `IN`/`NOT IN` generan un placeholder por cada valor del array; un `IN` con un array vacio se traduce a `1 = 0` (no matchea nada) en vez de generar SQL invalido (`IN ()`). La conversion a SQL vive en `CrudRepository::conditionsToSql(array $conditions, string $paramPrefix)`, parametrizada por un prefijo — `baseConditionsSql()` la llama con prefijo `'base'` para el scoping, y `referenceOptions()` con `'refcond_' . $column->name` para las condiciones de una FK puntual, de modo que varios juegos de condiciones puedan combinarse en la misma consulta sin colisionar nombres de parametro.

### Constructor de filtro avanzado (`buildAdvancedFilterSql()`)

Recibe `$advancedFilters` (filas `{field, op, value, conn}`, en el orden en que llegaron) y las combina en un solo fragmento SQL con `fold` de izquierda a derecha: `$acc = $acc === null ? $fragment : "({$acc} {$connector} {$fragment})"`. La agrupacion explicita con parentesis es deliberada — sin ella, SQL aplicaria la precedencia normal (`AND` liga mas fuerte que `OR`) sin importar el orden visual en que el usuario agrego las filas, lo cual no coincide con lo que un constructor visual de filas hace esperar.

`AppyCrud::extractAdvancedFilters()` parsea `$get['af_field']`/`af_op`/`af_value`/`af_conn` (arrays paralelos alineados por posicion, mismo patron que `extractManyToManySelections()`) y descarta cualquier fila cuyo campo no este en `allowedFilterFields()` (== `filterableFields` si se definio, si no todas las columnas visibles) — es la misma whitelist que ya limita el filtro simple por columna. `CrudRepository::buildAdvancedFilterSql()` hace una segunda validacion independiente contra `$this->schema->column($field)` y contra la lista fija `ADVANCED_FILTER_OPERATORS`, ignorando silenciosamente cualquier fila con campo/operador invalido — nunca concatena el nombre de columna o el operador crudos en el SQL, solo los usa como clave de un `match`/array fijo.

Los operadores `is_null`/`is_not_null` no llevan valor ni parametro bindeado; el resto usa un placeholder posicional (`:advf_N`) para no colisionar con los de `$filters`/`$search`/las condiciones base, que ya usan sus propios prefijos (ver `Condition`).

`TailwindRenderer` no sabe nada de esta logica: solo genera los inputs `af_field[]`/`af_op[]`/`af_value[]`/`af_conn[]` dentro del mismo `<form>` del filtro simple, en el mismo orden en que estan en el DOM (`appycrudAddFilterRow()`/`appycrudRemoveFilterRow()` solo clonan/quitan bloques completos de 4 inputs desde un `<template>`, nunca reordenan). El envio via `FormData(form)` preserva ese orden, que es lo que permite alinear los 4 arrays por posicion sin depender de indices explicitos en los nombres.

**El panel es un `<dialog id="appycrud-advanced-filter">` (modal nativo), no un `<div>` oculto por CSS** — pero sigue viviendo dentro del mismo `<form>` del filtro simple. Esto funciona porque `<dialog>` no reparenta sus hijos al mostrarse via `showModal()` (solo lo pinta en el "top layer"); los `<input>`/`<select>` dentro siguen siendo descendientes del `<form>` en el arbol del DOM, asi que `FormData(form)` los sigue incluyendo con el dialog abierto **o cerrado**. `appycrudApplyAdvancedFilter()` llama a `appycrudSubmitFilters()` (la misma funcion que usa el filtro simple) y despues cierra el dialog — no hay una ruta de envio separada para el filtro avanzado. Al abrirse por primera vez ya trae una fila vacia (`renderAdvancedFilterPanel()` inyecta una condicion por defecto si `$activeRows` viene vacio) — un modal sin ninguna fila que editar no tenia sentido.

### El filtro simple vive DENTRO de la tabla, no en el `<form>` que lo procesa

`renderColumnFilterRow()` genera una segunda fila de `<thead>`, un input/select por columna filtrable, alineada bajo cada encabezado (columnas fuera de `filterableFields` quedan con una celda vacia, para no romper la alineacion del resto de columnas). Esa fila vive dentro de `#appycrud-list-body` — el fragmento que se reemplaza **entero** por AJAX en cada filtrado/orden/pagina — mientras que `<form id="appycrud-filter-form">` (busqueda global, botones Filtrar/Limpiar/Filtro avanzado, el dialog) vive **fuera** de `#appycrud-list-body`, en `renderFilterRow()`, y por eso sobrevive intacto a esos reemplazos.

Como esos inputs de columna ya no son descendientes del `<form>` en el arbol del DOM, no pueden depender del `oninput`/`onchange` que esta puesto en el propio `<form>` (ese handler solo escucha eventos que burbujean desde sus descendientes reales). Se resuelve con dos piezas:

1. **`form="appycrud-filter-form"`** en cada input/select de columna — el atributo HTML estandar que asocia un control con un `<form>` por id, sin importar su posicion en el arbol. `new FormData(form)` los sigue recolectando igual que si fueran hijos directos.
2. **`oninput`/`onchange` explicito en cada input de columna** (en vez de heredarlo del `<form>` ancestro, que ya no lo es): `appycrudScheduleFilter(document.getElementById('appycrud-filter-form'))`, la misma funcion de debounce que usa el filtro simple original.

Al reemplazarse `#appycrud-list-body`, los inputs de columna se regeneran desde cero con el valor de `$activeFilters` ya reflejado (no hay estado de JS que preservar) — la unica perdida real es el foco/cursor si el usuario seguia escribiendo justo cuando el debounce dispara, que por diseño ocurre 500ms despues de la ultima tecla (ver mas abajo), o sea con el usuario ya detenido.

### Bug real: el filtro de una columna FK no traia resultados

`renderColumnFilterRow()` (filtro simple por columna) y `renderAdvancedFilterRow()` (filtro avanzado) inicialmente renderizaban **siempre** un `<input type="text">` para el valor a filtrar, salvo el caso especial de `STRATEGY_CHECKBOX`. El problema: `CrudRepository::buildWhereClause()` filtra una columna con `reference !== null` por **igualdad exacta contra el id** (`{columna} = :valor`), no por `LIKE` contra texto — es la misma condicion que ya usaba el filtro simple *antes* de que los filtros se movieran dentro de la tabla, solo que entonces nadie habia notado que el input seguia siendo de texto libre. Si el usuario escribia el nombre visible ("Trabajo") en vez del id numerico (`1`), la comparacion `categoria_id = 'Trabajo'` nunca matcheaba nada.

**Fix:** cuando `$column->reference !== null`, tanto el filtro simple como el avanzado renderizan un `<select>` poblado con las mismas opciones que ya se usan en el formulario (`referenceOptions`, threadeado hasta `renderColumnFilterRow()`/`renderAdvancedFilterPanel()`/`renderAdvancedFilterRow()`) — el usuario elige por *label*, pero el `value` que viaja es el id real. En el filtro avanzado, como el campo se puede cambiar dinamicamente sin recargar (el `<select name="af_field[]">` tiene su propio `onchange`), la logica de "input vs select" tambien vive en JS: `appycrudUpdateFilterValueControl()` lee un catalogo `{columna: [{value, label}, ...]}` embebido como JSON en `data-reference-catalog` sobre `#appycrud-advanced-filter-rows`, y reconstruye el control (`<select>` si el campo elegido esta en el catalogo, `<input>` si no) cada vez que cambia el campo o el operador.

### `insertDefaults`

Se aplican en `CrudRepository::insert()` con `array_merge($data, $this->insertDefaults)` — es decir, **despues** de filtrar por columnas conocidas, y sobreescribiendo cualquier valor que haya mandado el cliente para esas columnas. Es el complemento de seguridad de `where`: sin esto, un `where` que restringe por `empresa_id` no impide que alguien inserte un registro con un `empresa_id` distinto al suyo.

## `ManyToMany`: relaciones via tabla pivote

A diferencia de todo lo demas en `CrudRepository` (que opera sobre `$this->schema`, la tabla principal), los metodos `manyToManyOptions()`, `manyToManySelected()`, `syncManyToMany()` y `deleteManyToManyFor()` reciben la `ManyToMany` como parametro explicito — son operaciones sobre *otra* tabla (la pivote) que no forma parte del `TableSchema` de la tabla principal.

`syncManyToMany()` siempre borra todas las asociaciones existentes de ese id y vuelve a insertar las seleccionadas (no hace un diff) — mas simple de razonar y suficientemente rapido para el numero de filas tipico de un pivote (decenas, no millones). `AppyCrud` extrae la seleccion del POST (`m2m_{name}[]`) **antes** de aplicar `insertFields`/`editFields` o `restrictToFields()`, porque esos mecanismos filtran por nombres de columna real del schema — un campo `m2m_*` nunca es una columna real, asi que quedaria descartado si se dejara pasar por ese filtro.

El orden de operaciones importa: en `handleDelete`, `deleteManyToManyFor()` se llama **antes** de `repository->delete()` (limpia el pivote primero, evitando depender de `ON DELETE CASCADE` a nivel de base de datos, que no todos los motores/tablas tienen configurado). En `handleStore`/`handleUpdate`, `syncManyToMany()` se llama **despues** del insert/update exitoso (necesita el id del registro principal, que en el caso de insert recien se conoce tras `repository->insert()`).

### Scoping (`baseConditions`) en el pivote

La tabla pivote (y la tabla relacionada) no tienen columnas de `baseConditions` (ej. `empresa_id`) — el scoping no se puede aplicar a su `WHERE` directamente como en `find()`/`update()`/`delete()`. En cambio, `manyToManySelected()`, `syncManyToMany()` y `deleteManyToManyFor()` verifican primero (via `isInScope()`, que llama a `find($primaryKeyValue)`) que el id de la tabla principal recibido este dentro del scope; si no lo esta, la operacion no toca el pivote (retorna `[]` o no hace nada). Sin esto, un id de otro tenant/ambito —aunque `find`/`update`/`delete` ya lo rechacen sobre la tabla principal— seguia pudiendo leer, sincronizar o borrar filas de pivote ajenas, porque esos tres metodos usaban el id tal cual, sin pasar por `baseConditionsSql()`. `manyToManyOptions()` no necesita este chequeo: no recibe un id de la tabla principal, solo lista la tabla relacionada completa (el catalogo de opciones disponibles, igual para cualquier registro).

## Hooks

`AppyCrud::hook(string $name): ?callable` es la unica puerta de entrada a `$this->hooks`. El contrato (`beforeInsert`/`beforeUpdate` retornan `array`; `beforeDelete` no retorna nada; cualquiera puede lanzar `HookAbortException`) se decidio deliberadamente via **excepcion para cancelar** en vez de un valor de retorno especial (`false`, `null`, etc.) — evita la ambiguedad de "¿que significa que el hook no retorne nada?" y es el patron mas idiomatico en PHP para "esta operacion no puede continuar". `afterInsert`/`afterUpdate`/`afterDelete` no participan de ese contrato: ya no hay nada que cancelar, son solo para efectos secundarios.

En `handleBulkDelete`, `beforeDelete`/`afterDelete` se invocan **por cada id** del lote (no una vez por el lote completo) — cada registro se evalua y borra de forma independiente, así que un id bloqueado por el hook no impide que los demas se borren.

## Exportacion por chunks

`CrudRepository::exportRows()` es un generador (`yield`) que pagina internamente con `LIMIT`/`OFFSET` en bloques de `$chunkSize` (1000 por defecto), en vez de traer todas las filas de una sola consulta. `exportCsv()`/`exportXls()`/`exportMarkdown()` consumen ese mismo generador — agregar un formato nuevo de exportacion es escribir un metodo que itere `exportRows()` y formatee cada fila, no reimplementar la paginacion.

## Render de listado: pagina completa vs. fragmento

`TailwindRenderer::renderList()` (pagina completa: titulo, toolbar, formulario de filtros) y `renderListBody()` (solo tabla + paginacion, usado por el fetch de filtrado/busqueda AJAX) comparten toda su logica de armado de filas en el metodo privado `renderListInner()`. `AppyCrud::handle()` decide cual devolver segun `$isAjax` en la accion por defecto (`list`).

### Paginacion: `perPage` y navegacion Anterior/Siguiente

Antes de esta version **no existia ninguna forma de navegar mas alla de la pagina 1** — `CrudRepository::paginate()` ya soportaba `$perPage`/`$page` desde el principio, pero `AppyCrud` siempre llamaba con `20` fijo y `TailwindRenderer` nunca generaba un link `?page=N`. Con datasets de mas de 20 filas, cualquier registro despues de la fila 20 era literalmente inalcanzable desde la UI.

`AppyCrud::resolvePerPage($get)` valida `$get['perPage']` contra `$this->perPageOptions` (whitelist) antes de usarlo — un valor fuera de la lista se ignora y cae al default (`$this->perPage`). Esto es deliberado: sin la validacion, `?perPage=999999` forzaria traer la tabla completa de un solo golpe, sin paginar.

**Precedencia de `perPage`/`perPageOptions`** (resuelta una sola vez en el constructor de `AppyCrud`): `$config?->perPage() ?? (int) ($options['perPage'] ?? 20)`, y analogamente para `perPageOptions`. `TableConfig::perPage()`/`perPageOptions()` devuelven `null` si esa tabla no los fijo explicitamente (son propiedades opcionales del constructor, no parte del array generico de `columnOverrides` — a diferencia de los overrides de columna, esto es paginacion a nivel de tabla completa, no tiene sentido modelarlo como propiedad de una `Column`). El operador `?->` cubre el caso `$config === null` (tabla sin `TableConfig` en absoluto). Este orden fue una decision explicita del integrador: permite que una app con varias tablas de tamaños muy distintos (un catalogo chico, un log grande) defina el default de cada una junto a sus demas overrides, sin tener que repetir `perPage` en cada llamada a `new AppyCrud(...)`.

`TailwindRenderer::renderPaginationNav()` genera Anterior/Siguiente como `<a href>` normales (navegacion completa, **no** AJAX) — mismo patron que `renderSortLink()`/`renderExportMenu()`: consistente con como ya se comportaba el orden por columna, en vez de sumar una ruta de fetch mas. El selector "Por pagina" es un `<select onchange="...">` con JS minimo (`URLSearchParams` sobre `location.search`, sin depender de otro `<form>`) que fija `perPage` y borra `page` (vuelve a la pagina 1) antes de navegar.

### Indicador de carga en el filtrado AJAX

`renderList()` envuelve `#appycrud-list-body` en un `<div class="relative">` con un hermano permanente `#appycrud-list-loading` (oculto por default, `position: absolute; inset: 0`) — deliberadamente **fuera** de `#appycrud-list-body`, para que sobreviva al `innerHTML` swap de `appycrudApplyFilters()` (si estuviera dentro, se borraria y recrearia en cada respuesta, perdiendo cualquier estado intermedio).

`appycrudApplyFilters()` programa un `setTimeout` de 200ms que revela el overlay, y lo cancela + oculta en el `.finally()` del `fetch()` (corre tanto si la respuesta llega bien como si falla, a diferencia de `.then()`). El retraso de 200ms es intencional: en tablas chicas la respuesta llega casi al instante y el spinner ni se percibe (evita el parpadeo de "flash of loading state"); en tablas grandes, donde de verdad se nota la espera, para entonces el usuario ya lo ve. No hay `AbortController` para peticiones superpuestas (ej. el usuario sigue escribiendo mientras la anterior request aun no resuelve) — se acepta la carrera porque el resultado final visible es el de la ultima respuesta en llegar, que en la practica casi siempre coincide con la ultima request enviada (mismo comportamiento que ya tenia el filtrado antes de este indicador, no es una regresion nueva).

### Bug real: `renderListBody()` no propagaba `$advancedFilters`

Antes de esta version, `AppyCrud::renderListBody()` desestructuraba solo 5 de los 6 valores que devuelve `paginateFromRequest()`, descartando el filtro avanzado activo. El efecto: los links de "ordenar por columna" generados **durante un refresco AJAX** (con un filtro avanzado ya aplicado) perdian ese filtro en su querystring — al hacer clic para ordenar, el filtro avanzado se perdia silenciosamente. Se corrigio capturando los 6 valores y pasando `$advancedFilters` a `renderer->renderListBody()`.

## `RowAction`: acciones custom por fila

`AppyCrud::handle()` revisa `$this->rowActions` **antes** de entrar al `match()` de acciones internas: si `$_GET['action']` coincide con el `name` de alguna `RowAction`, se ejecuta su `handler` y ese resultado se retorna directo — el `match()` interno ni se evalua. Esto significa que un `name` de `RowAction` puede, en teoria, pisar una accion interna (`view`, `edit`, etc.) si se usa el mismo nombre; no hay validacion contra eso, es responsabilidad del integrador elegir nombres que no choquen.

`TailwindRenderer::renderCustomRowAction()` arma tres variantes de HTML segun `method`/`openInModal`/`confirm`:

- `method: 'post'` → un `<form>` con el token CSRF como campo oculto (igual que Eliminar). Si `confirm` esta definido, el submit se intercepta con `appycrudConfirmSubmit()` (el mismo mecanismo que usa el borrado individual).

### Bug real: `method: 'post'` renderizaba el token CSRF pero `handle()` nunca lo verificaba

El punto anterior (el `<form>` con el token oculto "igual que Eliminar") solo describia la mitad de la historia: `TailwindRenderer` si pinta el token, pero `AppyCrud::handle()` ejecutaba el handler de la `RowAction` (linea 220-224, antes del fix) sin llamar a `verifyCsrf($post)` en ningun punto, y sin exigir que la peticion fuera realmente un POST. Cualquier `RowAction` con `method: 'post'` que mutara datos (el propio ejemplo del docblock de `RowAction`, "archivar") podia dispararse con una peticion GET forjada desde otra pagina (`<img src="...?action=archivar&id=5">`) mientras la victima tuviera sesion activa — un CSRF real, con el agravante de que el HTML generado sugeria que ya estaba protegido. El fix agrega, justo antes de invocar el handler: si `$rowAction->method === 'post'` y `!$this->verifyCsrf($post)`, se hace `$this->redirect($baseUrl)` sin ejecutar nada (mismo patron de fallo silencioso que usan `handleDelete`/`handleBulkDelete`). Las acciones con `method: 'get'` (el default) no cambian: nunca pretendieron tener proteccion CSRF, son para lectura/navegacion.
- `method: 'get'`, `openInModal: true` (default) → un boton que hace `appycrudOpenModal()` (fetch + mostrar el HTML devuelto dentro del dialog), igual que Ver/Editar/Clonar.
- `method: 'get'`, `openInModal: false` → un `<a href>` normal; si tiene `confirm`, el click se intercepta con `appycrudConfirmAction()` en vez de navegar directo.

### El boton de aceptar del dialog de confirmacion es dinamico

El mismo `<dialog id="appycrud-confirm-dialog">` se reutiliza para *todas* las confirmaciones (borrar una fila, borrado masivo, o una `RowAction` con `confirm`). Como cada una necesita un texto distinto en el boton de aceptar ("Eliminar" vs. el label de la `RowAction`, ej. "Archivar"), el boton no tiene un label fijo en el HTML — `appycrudConfirmAction(message, action, label)` le asigna `label` (o el generico `data-default-label` = `confirm.accept` si no se pasa uno) cada vez que se abre. Si agregas un nuevo punto de confirmacion, asegurate de pasar el `label` correcto a `appycrudConfirmAction()`/`appycrudConfirmSubmit()`/`appycrudBulkDelete()`, o el boton mostrara el texto de la ultima confirmacion que se disparo.

### Bug real: el modal de crear/editar se salia de la pantalla con listas largas (multiselect)

Con una columna `multiselect_native`/`multiselect_searchable` con muchas opciones, o simplemente un formulario con varios campos, el contenido de `#appycrud-dialog` podia superar el alto de la ventana. La intuicion natural es que un `<dialog>` deberia recortarse solo (los navegadores le aplican un `max-height` por default), pero ese `max-height` de la hoja de estilos del navegador (UA stylesheet) **gana por origen de cascada** sobre una clase de utilidad de Tailwind (`max-h-[85vh]`) sin `!important`, sin importar la especificidad — un `getComputedStyle` mostraba el `max-height` del navegador (`calc(100% - Npx)`, el valor exacto varia por navegador) en vez del `85vh` esperado. Se corrigio con el prefijo `!` de Tailwind (`!max-h-[85vh] !overflow-y-auto`), que emite `max-height:85vh!important` — verificado con `getComputedStyle` en un navegador real (Chromium via MCP): sin el `!`, `maxHeight` devolvia el valor del navegador; con el, `612px` en un viewport de `720px` (85% exacto). El mismo fix se aplico al dialog del filtro avanzado (`#appycrud-advanced-filter`) por la misma razon — puede acumular muchas filas de condiciones.

Ademas de esto, `renderMultiselect()` (el `<select multiple>` nativo de `multiselect_native`) tiene una altura fija (`h-40`) que no depende de cuantas opciones haya — la lista larga hace scroll *dentro* de esa altura fija, nunca estira el `<select>` mas alla de lo declarado. Un contador de seleccionados (`appycrudUpdateMultiselectCount()`, disparado en cada `change`) compensa que la lista siga siendo larga: no hace falta scrollearla entera para saber cuantas quedaron marcadas.

### `multiselect_searchable`: combobox "select2" vanilla

`renderMultiselectSearchable()` ya no es una lista de checkboxes con un filtro de texto arriba (esa fue la primera version) — es un combobox al estilo select2: los valores elegidos se muestran como **chips** dentro de la misma caja de busqueda, y el desplegable solo lista las opciones que *faltan* por elegir. Piezas:

- **Render inicial (PHP):** cada opcion del desplegable trae `data-value`, `data-label` y `data-selected` (`'1'`/`'0'`) — los ya seleccionados nacen con la clase `hidden` y `data-selected="1"`, y ademas se renderiza su chip correspondiente (con su propio `<input type="hidden" name="{campo}[]">`, que es lo que realmente viaja en el submit; el combobox visible no tiene `name` propio).
- **Seleccionar una opcion (`appycrudSelect2Select()`):** construye el chip por completo en JS (incluido el SVG del boton "×", embebido como string `appycrudSelect2CloseIconSvg` en vez de depender de una plantilla en el DOM — mas simple que mantener un `<template>` solo para un icono), lo agrega a `.appycrud-select2-chips`, oculta la opcion correspondiente en el desplegable (`classList.add('hidden')` + `data-selected = '1'`), limpia el input de busqueda y le devuelve el foco (para poder seguir agregando sin un clic extra).
- **Quitar un chip (`appycrudSelect2Remove()`):** hace lo inverso — quita el chip del DOM y vuelve a mostrar su opcion en el desplegable (`data-selected = '0'`).
- **Filtrar (`appycrudSelect2Filter()`):** oculta/muestra opciones por texto, pero **nunca** muestra una opcion con `data-selected === '1'` sin importar el texto — evita que una opcion ya elegida aparezca duplicada en la lista mientras se busca.
- **Cierre del desplegable:** el listener global de `click` en `document` (el mismo que ya cerraba los menus `.appycrud-menu-wrap`) se extendio para tambien cerrar cualquier `.appycrud-select2-dropdown` abierto si el clic cayo fuera de su `.appycrud-select2` contenedor.

No hay ninguna dependencia de una libreria "select2" real — es un widget propio que imita el patron de interaccion (buscar + chips), consistente con la filosofia zero-dependencias del resto del proyecto.

## Carga de archivos

`FieldType::FILE` (`STRATEGY_FILE`) es el unico tipo de campo cuyo dato **no viaja por `$_POST`** sino por `$_FILES`, asi que `AppyCrud::handle()` recibe un parametro `$files` aparte (default `[]`, para no romper compatibilidad con quien no lo use) que el integrador debe llenar con `$_FILES` explicitamente — igual que `$get`/`$post`, nunca se lee la superglobal directamente.

`AppyCrud::processFileUploads()` corre **despues** de `restrictToFields()` pero **antes** de `Validator::validate()`, para que una regla `required` sobre un campo `file` vea el nombre de archivo ya resuelto (nuevo upload, o el existente conservado) en vez de nada. La logica de "conservar el archivo existente si no se sube uno nuevo" depende de `$existingRow` (`null` en creacion, la fila actual en edicion via `repository->find($id)` **antes** de tocar los datos) — sin esto, editar sin re-subir borraria la referencia al archivo.

Extensiones peligrosas (`DANGEROUS_UPLOAD_EXTENSIONS`) se reemplazan por `.bin` **siempre**, incluso si el archivo original las trae — es una lista negra fija, no configurable, porque la superficie de riesgo (ejecutar codigo si `uploadDir` termina siendo servido por HTTP) no depende del caso de uso de cada integrador.

### Quitar/reemplazar el archivo y borrado fisico al eliminar

La casilla `remove_{columna}` (renderizada por `TailwindRenderer::renderFileInput()` solo cuando ya hay un archivo guardado) sigue el mismo patron que `extractManyToManySelections()`: `AppyCrud::handleUpdate()` la extrae de `$post` **antes** de `restrictToFields()` (via `extractRemoveFileFlags()`) y la vuelve a mezclar en los datos que llegan a `processFileUploads()` — de lo contrario `restrictToFields()` la descartaria en cuanto `editFields` no incluya explicitamente ese nombre sintetico.

Dentro de `processFileUploads()`, si `remove_{columna}` vino marcada y no se subio un archivo nuevo, se llama a `deleteUploadedFile()` (borra el archivo fisico, `@unlink` best-effort) y **se asigna `null` explicito** a la columna en `$data` — nunca un `unset()`. Esto importa porque `CrudRepository::update()` arma el `UPDATE` solo con las claves presentes en `$data`: un `unset()` simplemente omite la columna del `SET` (deja el valor viejo intacto en la BD), mientras que asignar `null` la incluye con `= NULL`. El mismo cuidado aplica cuando se sube un archivo de reemplazo: antes de sobreescribir `$data[$column->name]` con el nuevo nombre, se borra el archivo anterior (`$existingRow[$column->name]`) si es distinto, para no dejar huerfanos.

`deleteFilesOnDelete` (default `true`) se resuelve en `AppyCrud::deleteUploadedFilesFor($id)`, llamado en `handleDelete()`/`handleBulkDelete()` **antes** de `deleteManyToManyFor()` y de `repository->delete()` — necesita leer la fila con `repository->find($id)` mientras todavia existe, igual que la limpieza de M2M.
