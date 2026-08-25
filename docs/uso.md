# Manual de uso

## Lo mínimo

```php
$connection = Connection::fromPdo($pdo);
$crud = new AppyCrud($connection, 'clientes');

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) { echo $html; exit; }
// ... envolver $html en tu HTML/layout ...
```

`$isAjax` es importante: varias acciones (abrir el modal de crear/editar, filtrar, ordenar) piden solo el fragmento de HTML por `fetch()`, no la página completa. Si tu página no distingue esto, el modal mostrará una página entera dentro del modal.

Con esto solo, AppyCrud ya:

- Detecta las columnas de `clientes` (tipos, nulabilidad, llave primaria).
- Detecta sus llaves foráneas y las convierte en `<select>`.
- Genera listado, formulario de crear/editar, confirmación de borrado — todo en modal.

## `TableConfig`: overrides por columna

La autodetección cubre el caso general, pero seguro querrás ajustar labels, ocultar columnas técnicas, o agregar validaciones. Todo eso vive en `TableConfig`, un array de overrides por nombre de columna:

```php
use Appylogi\AppyCrud\Schema\TableConfig;

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creado_en' => ['hidden' => true, 'readOnly' => true],
    'email' => ['label' => 'Correo', 'rules' => ['required', 'email']],
    'nombre' => ['label' => 'Nombre completo', 'rules' => ['required', 'max:150']],
    'activo' => ['inputType' => 'checkbox'],
]);

$crud = new AppyCrud($connection, 'clientes', $config);
```

Cualquier propiedad pública de `Column` se puede sobreescribir así (`label`, `hidden`, `readOnly`, `inputType`, `rules`, `reference`, etc.) — el mecanismo es genérico, no una lista cerrada.

Además de los overrides por columna, `TableConfig` acepta ajustes de paginación propios de esa tabla (`perPage`, `perPageOptions`, segundo y tercer argumento) — ver [Paginación](#paginación-cuántos-registros-mostrar).

### Reglas de validación disponibles

| Regla | Efecto |
|---|---|
| `required` | El campo no puede llegar vacío. |
| `max:N` | Máximo N caracteres. |
| `min:N` | Mínimo N caracteres. |
| `email` | Debe ser un correo válido (`filter_var`). |
| `numeric` | Debe ser numérico. |

Si la validación falla, AppyCrud responde con HTTP 422 y re-renderiza el formulario con los errores — sin perder los datos ya escritos ni el modal abierto.

**Indicador visual de campo obligatorio:** cualquier columna no-nullable (`NOT NULL` en la base de datos, o forzado con `'nullable' => false` en el override) muestra un asterisco rojo (`*`) junto a su label en el formulario, además del atributo HTML `required`. No aplica a checkboxes (`boolean`): un booleano no-nullable casi siempre trae un default y no tiene el mismo sentido de "hay que llenarlo" que el resto de los tipos. Si el formulario tiene al menos un campo obligatorio, aparece una leyenda ("* obligatorio") arriba de los campos.

## Tipos de campo

`inputType` (override de `TableConfig`, ver arriba) acepta cualquiera de estos nombres. Varios son alias intencionales del mismo widget — por ejemplo `date` y `native_date` se ven exactamente igual — porque no hay un `<input>` nativo distinto para "date" vs "native date" sin JavaScript de terceros, y este proyecto no depende de ninguno.

| Tipo | Se renderiza como | Notas |
|---|---|---|
| `string` | `<input type="text">` | |
| `text` | `<textarea>` | Igual que el heurístico automático para columnas `TEXT`/`LONGTEXT`. |
| `boolean` | checkbox | |
| `int` | `<input type="number" step="1">` | |
| `float`, `numeric` | `<input type="number" step="any">` | |
| `email` | `<input type="email">` | |
| `password` | `<input type="password">` | |
| `password_toggle` | password + botón de ojo (mostrar/ocultar) | Vanilla JS, sin dependencias. |
| `color` | `<input type="color">` | |
| `date`, `native_date` | `<input type="date">` | |
| `datetime`, `native_datetime`, `timestamp` | `<input type="datetime-local">` | |
| `native_time` | `<input type="time">` | |
| `hidden` | `<input type="hidden">` | Se envía con el formulario pero no es visible ni editable. |
| `invisible` | (nada) | No aparece en el formulario en absoluto — ni siquiera como campo oculto. |
| `dropdown`, `enum` | `<select>` | Necesita `options` (ver abajo) salvo que sea una FK. |
| `dropdown_search`, `enum_searchable` | combobox buscable | `<input list>` + `<datalist>` nativo del navegador; guarda el *value* real en un campo oculto sincronizado, no el texto visible. |
| `relational_native` | `<select>` poblado desde otra tabla | Es lo que ya se genera automáticamente para una FK; este nombre es explícito por si prefieres declararlo así. |
| `multiselect_native` | `<select multiple>` | Se guarda como texto separado por comas en una sola columna (no arma una tabla de unión). |
| `multiselect_searchable` | combobox "select2" (buscar + chips) | Mismo almacenamiento (CSV) que `multiselect_native`; ver [más abajo](#multiselect-con-muchas-opciones). |
| `richtext` | editor de texto enriquecido (simple) | `<div contenteditable>` + barra minima (negrita/itálica/subrayado/listas), vanilla JS. Ver [Editor de texto enriquecido](#editor-de-texto-enriquecido-richtext). |
| `richtext_advanced` | editor de texto enriquecido (avanzado) | Igual que `richtext`, con barra extendida: encabezados (H1-H3), enlaces, alineación, deshacer/rehacer. Sigue siendo vanilla JS (`document.execCommand`), sin dependencias nuevas. |

### Opciones estáticas (`dropdown`/`enum`/`multiselect`)

Cuando el campo no es una llave foránea, dale las opciones a mano:

```php
'prioridad' => [
    'inputType' => 'dropdown',
    'options' => [
        ['value' => 'baja', 'label' => 'Baja'],
        ['value' => 'media', 'label' => 'Media'],
        ['value' => 'alta', 'label' => 'Alta'],
    ],
],
```

Si la columna es un `ENUM(...)` de MySQL, AppyCrud detecta los valores solo y los usa como opciones automáticamente — no hace falta declarar `options` a mano (aunque puedes sobreescribirlas si quieres otros labels).

### Multiselect con muchas opciones

`multiselect_native` (`<select multiple>`) tiene una altura fija — no crece sin límite aunque la lista de opciones sea larga; con muchas opciones, hace scroll interno. Debajo aparece un contador ("N seleccionado(s)") que se actualiza en vivo, para no tener que scrollear la lista completa solo para saber cuántos quedaron marcados.

`multiselect_searchable` es un combobox al estilo **select2**: los valores ya elegidos se muestran como **chips removibles** dentro de la misma caja; escribir abre un desplegable con las opciones que faltan por elegir (las ya seleccionadas no se repiten en la lista, así que no hay que buscar entre lo que ya marcaste). Hacer clic en una opción la agrega como chip; la "×" de cada chip la quita y la devuelve al desplegable. Todo vanilla JS — sin ninguna librería de terceros (no es una integración de la librería select2 real, es un widget propio con el mismo patrón de interacción).

### Cargar archivos (`file`)

```php
use Appylogi\AppyCrud\AppyCrud;

$config = new TableConfig([
    'adjunto' => ['inputType' => 'file'],
]);

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'uploadDir' => __DIR__ . '/uploads',        // obligatorio si hay algun campo 'file'
    'uploadUrlPrefix' => '/mi-sitio/uploads',    // opcional: habilita el link de descarga en listado/vista
]);

// $_FILES se pasa explicito, igual que $_GET/$_POST:
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax, $_FILES);
```

Cómo funciona:

- El archivo se guarda con un **nombre aleatorio** (no el original) dentro de `uploadDir`, y ese nombre es lo que se guarda en la columna. Esto evita colisiones entre archivos con el mismo nombre y evita que alguien adivine o controle el nombre del archivo en el servidor.
- Las extensiones potencialmente ejecutables (`.php`, `.phtml`, `.cgi`, `.py`, `.sh`, `.asp`, `.jsp`, etc.) se descartan y se reemplazan por `.bin`, sin importar qué extensión traiga el archivo original — una defensa extra por si `uploadDir` terminara siendo accesible por HTTP con ejecución de scripts habilitada.
- Un `<input type="file">` nunca se puede prellenar por seguridad del navegador. Si al editar no seleccionas un archivo nuevo, AppyCrud **conserva el archivo ya guardado** — no lo borra ni lo deja vacío. Si subes uno nuevo, el archivo anterior se borra del disco automáticamente (evita huérfanos).
- El formulario agrega automáticamente `enctype="multipart/form-data"` en cuanto detecta al menos un campo `file`.

**Importante — `uploadDir` no debería ser accesible directamente por HTTP con ejecución de scripts habilitada.** AppyCrud ya neutraliza extensiones peligrosas, pero esa es una defensa adicional, no un reemplazo de una configuración de servidor correcta (por ejemplo, un `.htaccess` con `php_flag engine off` en esa carpeta, o guardarla fuera del docroot y servirla mediante un script intermedio).

#### Quitar el archivo adjunto

Al editar un registro que ya tiene un archivo, el formulario muestra una casilla **"Quitar archivo actual"** junto al nombre del archivo. Si la marcas (y no seleccionas un archivo nuevo), AppyCrud borra el archivo del disco y deja la columna en `NULL` — sin necesidad de eliminar el registro completo.

Además, por default, **al eliminar el registro completo también se borra su archivo físico del disco** (columnas `file`). Si prefieres conservar los archivos aunque se borre la fila (por ejemplo porque se reutilizan en otro lado), desactívalo con:

```php
'deleteFilesOnDelete' => false,
```

### Editor de texto enriquecido (`richtext`)

```php
$config = new TableConfig([
    'notas' => ['inputType' => 'richtext'],           // barra minima
    'contenido' => ['inputType' => 'richtext_advanced'], // barra extendida
]);
```

No requiere ninguna opción adicional (a diferencia de `file`, que necesita `uploadDir`). Cómo funciona:

- El editor es un `<div contenteditable>` con una barra de herramientas. `richtext` (barra mínima): **negrita, itálica, subrayado, lista con viñetas, lista numerada**. `richtext_advanced` (barra extendida) agrega: **encabezados (H1-H3), insertar/quitar enlace, alinear izquierda/centro/derecha, deshacer/rehacer**. Todo con `document.execCommand()` — vanilla JS, sin ningún editor de terceros (TinyMCE, Quill, etc.) ni CDN, en ambos modos. `document.execCommand` está marcado como obsoleto en el estándar pero sigue implementado y soportado en todos los navegadores modernos (Chrome, Firefox, Safari, Edge); si algún día se retira de los navegadores, este es el único punto del código que habría que tocar.
- **El HTML se sanitiza siempre al guardar**, sin importar el modo (`Crud\HtmlSanitizer`, basado únicamente en `DOMDocument` — sin dependencias externas). Solo sobreviven las etiquetas `p`, `div`, `br`, `b`, `strong`, `i`, `em`, `u`, `ul`, `ol`, `li`, `a`, `h1`, `h2`, `h3`; cualquier otra etiqueta se elimina (conservando su texto), y `<script>`/`<style>` se descartan por completo (ni siquiera queda su texto). En `<a>` solo sobrevive `href`, y solo si su esquema es `http`, `https`, `mailto` o es una ruta relativa — un `href="javascript:..."` se elimina. En `<p>`/`<div>` sobrevive un `style` **solo** si contiene `text-align` con un valor válido (`left`/`center`/`right`/`justify`) — se reescribe entero para no dejar colar ninguna otra propiedad CSS.
- En el **listado**, se muestra una vista previa en texto plano (sin etiquetas, truncada) — el HTML completo compitiendo por espacio en una tabla de varias columnas no es legible. En la **vista de solo lectura** y al **editar**, se muestra el HTML ya formateado (negrita, listas, encabezados, alineación, etc. se ven como tales, no como texto con corchetes).
- En las **exportaciones** (CSV/Excel/Markdown) también se exporta como texto plano (sin etiquetas), por la misma razón que en el listado.

**Por qué importa la sanitización:** el valor de un campo `richtext`/`richtext_advanced` se renderiza como HTML real (no escapado) en la vista — es lo que permite que la negrita/las listas/los encabezados se vean formateados. Sin sanitizar, esto sería una vía directa de XSS almacenado (cualquiera que edite el registro podría inyectar `<script>` o atributos `on*`). La sanitización corre **siempre** al guardar (crear y editar), sin importar si el HTML vino del editor de AppyCrud o de otro lado (ej. una integración que llene el campo por su cuenta).

## Relaciones (llaves foráneas)

Si tu base de datos tiene una `FOREIGN KEY` real (MySQL, PostgreSQL o SQLite con `REFERENCES`), AppyCrud la detecta sola y renderiza un `<select>` poblado con esa tabla. La columna que se muestra como texto se adivina (`nombre`, `titulo`, `name`, `title`, `descripcion`...); si tu tabla usa otro nombre, indícalo:

```php
'categoria_id' => ['reference' => ['table' => 'categorias', 'column' => 'id', 'label' => 'nombre_categoria']],
```

Este mismo override sirve para bases de datos legacy que no tienen la `FOREIGN KEY` declarada como constraint real (muy común en sistemas antiguos) — declaras la relación a mano y AppyCrud la trata igual que una detectada automáticamente (select poblado, label resuelto en listado/vista/exportación).

### Filtrar las opciones del select (`conditions`)

Si solo quieres ofrecer un subconjunto de la tabla referenciada (ej. "solo categorías activas"), agrega `conditions` con el mismo tipo `Condition` que se usa para [scoping](#restringir-por-where-scoping):

```php
use Appylogi\AppyCrud\Crud\Condition;

'categoria_id' => ['reference' => [
    'table' => 'categorias', 'column' => 'id', 'label' => 'nombre',
    'conditions' => [Condition::where('activa', '=', 1)],
]],
```

Esto afecta solo las opciones que se muestran en el `<select>` — no cambia lo que se ve en el listado si una categoría deja de estar activa después de asignada a una tarea existente (el label ya guardado se sigue resolviendo igual).

## Muchos a muchos

Para relaciones muchos-a-muchos vía tabla pivote (ej. una tarea puede tener varios colaboradores, y un colaborador puede estar en varias tareas), usa `Crud\ManyToMany` en la opción `manyToMany`:

```php
use Appylogi\AppyCrud\Crud\ManyToMany;

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'manyToMany' => [
        new ManyToMany(
            name: 'colaboradores',           // nombre logico, se usa como m2m_colaboradores en el form
            pivotTable: 'tareas_colaboradores',
            localKey: 'tarea_id',              // FK en el pivote hacia tareas.id
            foreignKey: 'colaborador_id',       // FK en el pivote hacia colaboradores.id
            relatedTable: 'colaboradores',
        ),
    ],
]);
```

Con esto, el formulario de crear/editar muestra un multiselect adicional ("Colaboradores") poblado desde la tabla `colaboradores`. AppyCrud sincroniza la tabla pivote automáticamente: borra las asociaciones existentes e inserta las nuevas, después de guardar el registro principal — y también las limpia antes de eliminar el registro (evita filas huérfanas en el pivote). En la vista de solo lectura (`view`) se muestran los nombres ya resueltos, separados por coma.

**Diferencia con `multiselect_native`/`multiselect_searchable` como tipo de campo (ver [Tipos de campo](#tipos-de-campo)):** esos guardan la selección como texto separado por comas en una sola columna de la propia tabla (`tareas.etiquetas`), sin tabla de unión. `manyToMany` sí usa una tabla pivote real — úsalo cuando la relación merece existir como entidad propia consultable desde otro lado (ej. reportes por colaborador), no solo como una lista de valores dentro de un registro.

**Limitaciones de esta versión:** las relaciones `manyToMany` no aparecen como columna en el listado ni en la exportación (evita tener que resolver un `GROUP_CONCAT`/`STRING_AGG` distinto por motor); tampoco se copian al usar "Clonar" (el registro clonado empieza sin asociaciones).

### ¿Y si tengo varias relaciones muchos-a-muchos en la misma tabla?

Simplemente agrega más de una a la lista — cada `ManyToMany` es independiente:

```php
'manyToMany' => [
    new ManyToMany(name: 'etiquetas', pivotTable: 'tareas_etiquetas', localKey: 'tarea_id', foreignKey: 'etiqueta_id', relatedTable: 'etiquetas'),
    new ManyToMany(name: 'colaboradores', pivotTable: 'tareas_colaboradores', localKey: 'tarea_id', foreignKey: 'colaborador_id', relatedTable: 'colaboradores'),
],
```

Cada una aparece como su propio multiselect en el formulario (`m2m_etiquetas[]`, `m2m_colaboradores[]`) y se sincroniza por separado. No hay límite en cuántas puedes declarar.

### ¿Necesito que la `FOREIGN KEY` exista de verdad en la base de datos?

No. Ni para `manyToMany` ni para el override de `reference` (ver [Relaciones](#relaciones-llaves-foráneas)) — AppyCrud arma las consultas (`SELECT`/`INSERT`/`DELETE`) con los nombres de tabla y columna que tú le des, sin verificar en ningún momento que exista un constraint `FOREIGN KEY` real. Esto es intencional: mucho código PHP legacy (y no tan legacy) tiene la relación implícita en el nombre de las columnas pero nunca declaró el constraint en el motor. Mientras la tabla y las columnas existan, funciona igual — con o sin la `FOREIGN KEY` declarada.

## Acciones custom por fila

Además de Ver/Editar/Clonar/Eliminar, puedes agregar tus propias acciones al menú de cada fila con `Crud\RowAction`:

```php
use Appylogi\AppyCrud\Crud\RowAction;

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'rowActions' => [
        // Abre en el mismo modal (fetch): el handler debe devolver HTML.
        new RowAction('marcar_revisado', 'Marcar revisado', function (mixed $id) use ($repository) {
            $repository->update($id, ['revisado' => 1]);
            return '<div class="p-6">Listo.</div>';
        }, icon: 'edit'),

        // Link normal (navegacion o descarga); el handler puede hacer header()+exit.
        new RowAction('descargar_pdf', 'Descargar PDF', function (mixed $id) {
            // generar y enviar el PDF...
        }, icon: 'download', openInModal: false),

        // Escritura (POST) con confirmacion, igual que Eliminar.
        new RowAction('archivar', 'Archivar', function (mixed $id) use ($repository) {
            $repository->update($id, ['archivada' => 1]);
        }, icon: 'trash', confirm: '¿Archivar este registro?', method: 'post'),
    ],
]);
```

Parámetros de `RowAction`: `name` (se vuelve `?action={name}` internamente), `label`, `handler`, `icon` (opcional, nombre de un ícono del catálogo interno: `edit`, `eye`, `copy`, `trash`, `download`, `printer`, `dots`, o `null` para ninguno), `confirm` (mensaje opcional; si se define, pide confirmación antes de ejecutar), `method` (`'get'` por defecto o `'post'` para escrituras), `openInModal` (por defecto `true` para acciones GET — el resultado del handler se muestra dentro del mismo modal; ponlo en `false` para que sea un link normal, útil para descargas o redirecciones).

El `handler` recibe `(mixed $id, array $get, array $post)` y para acciones GET con `openInModal: true` debe devolver el HTML a mostrar; para el resto, el valor de retorno no se usa (la página se recarga después).

## Hooks antes/después de las acciones

Para ejecutar código propio alrededor de `insert`/`update`/`delete` — auditoría, notificaciones, calcular un campo, bloquear un borrado bajo cierta condición — usa la opción `hooks`:

```php
use Appylogi\AppyCrud\Crud\HookAbortException;

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'hooks' => [
        'beforeInsert' => function (array $data): array {
            $data['slug'] = strtolower(str_replace(' ', '-', $data['titulo']));
            return $data; // el array que retornes es lo que realmente se inserta
        },
        'afterInsert' => function (string $id, array $data): void {
            // ej. enviar una notificacion, escribir un log...
        },
        'beforeUpdate' => function (mixed $id, array $data): array {
            return $data;
        },
        'afterUpdate' => function (mixed $id, array $data): void {},
        'beforeDelete' => function (mixed $id): void {
            if (/* alguna condicion */ false) {
                throw new HookAbortException('Este registro no se puede eliminar.');
            }
        },
        'afterDelete' => function (mixed $id): void {},
    ],
]);
```

Reglas del contrato:

- `beforeInsert`/`beforeUpdate` **deben retornar un array** (el que efectivamente se guarda) — si no modificas nada, retorna `$data` tal cual.
- Lanzar `HookAbortException('mensaje')` desde cualquier hook `before*` cancela la operación. Para `beforeInsert`/`beforeUpdate`, el mensaje se muestra dentro del mismo modal sin perder lo ya escrito. Para `beforeDelete`, simplemente no se borra nada (no hay una forma estándar de mostrar un error en el flujo de borrado, ya que puede dispararse desde el borrado masivo).
- Los hooks `after*` no pueden cancelar nada — ya se guardó/eliminó; son solo para efectos secundarios.
- En `bulkDelete`, `beforeDelete`/`afterDelete` se ejecutan **una vez por cada registro** seleccionado, no una vez por el lote completo.

## Opciones de `AppyCrud`

El cuarto argumento es el idioma (`'es'`/`'en'`, ver [i18n](#i18n)); el quinto es un array de opciones:

```php
$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'deleteMode' => DeleteMode::SOFT,
    'softDeleteColumn' => 'eliminado',
    'clone' => true,
    'cloneExcludeColumns' => ['codigo'],
    'cloneSuffixColumn' => 'titulo',
    'cloneSuffix' => ' (copia)',
    'export' => true,
    'bulkDelete' => true,
    'filters' => true,
    'search' => true,
    'view' => true,
    'print' => true,
    'csrf' => true,
]);
```

| Opción | Default | Descripción |
|---|---|---|
| `deleteMode` | `DeleteMode::CONFIRM` | `CONFIRM` pregunta con un modal propio; `DIRECT` borra sin preguntar; `SOFT` no borra, actualiza `softDeleteColumn`. |
| `softDeleteColumn` | `null` | Obligatorio si `deleteMode` es `SOFT`. Debe ser una columna existente. |
| `export` | `true` | Menú de exportar (CSV / Excel / Markdown). |
| `bulkDelete` | `true` | Checkboxes + borrado masivo (respeta `deleteMode`). |
| `filters` | `true` | Fila de filtros por columna + constructor avanzado AND/OR. |
| `filterableFields` | `null` | Restringe qué columnas tienen filtro simple y aparecen en el constructor avanzado; `null` = todas las visibles. Ver [Filtros](#filtros-elegir-columnas-y-constructor-avanzado-andor). |
| `perPage` | `20` | Registros por página al abrir el listado. Ver [Paginación](#paginación-cuántos-registros-mostrar). |
| `perPageOptions` | `[10, 20, 50, 100]` | Opciones del selector "Por página"; también es la whitelist que valida `?perPage=` en la URL. |
| `search` | `true` | Búsqueda global sobre las columnas de texto. |
| `view` | `true` | Acción "Ver" (solo lectura). |
| `print` | `true` | Imprimir un registro o el listado completo. |
| `clone` | `true` | Acción "Clonar" (prellena el formulario de crear). |
| `cloneExcludeColumns` | `[]` | Columnas a vaciar al clonar (típicamente códigos/emails únicos). |
| `cloneSuffixColumn` | `null` | Columna a la que se le agrega `cloneSuffix` al clonar (ej. el título). |
| `cloneSuffix` | `' (copia)'` | Sufijo usado con `cloneSuffixColumn`. |
| `csrf` | `true` | Protección CSRF (ver [Seguridad](#seguridad)). Requiere `session_start()` antes de instanciar `AppyCrud`. |
| `where` | `[]` | Condiciones base (scoping), ver [Restringir por WHERE](#restringir-por-where-scoping). |
| `insertDefaults` | `[]` | Valores forzados en cada insert, ver la misma sección. |
| `defaultOrderBy` / `defaultOrderDir` | `''` / `'ASC'` | Orden inicial cuando la URL no trae `orderBy`/`orderDir` (el usuario puede cambiarlo con clic en cualquier columna). |
| `insertFields` / `editFields` | `null` | Restringe qué columnas aparecen y se aceptan al crear/editar, ver [Campos permitidos](#campos-permitidos-al-insertar-y-editar). |
| `hooks` | `[]` | Callables antes/después de insert/update/delete, ver [Hooks](#hooks-antesdespués-de-las-acciones). |
| `manyToMany` | `[]` | `ManyToMany[]`, ver [Muchos a muchos](#muchos-a-muchos). |
| `rowActions` | `[]` | `RowAction[]`, ver [Acciones custom por fila](#acciones-custom-por-fila). |
| `uploadDir` | `null` | Ruta absoluta para archivos subidos; obligatorio si hay algún campo `file`. |
| `uploadUrlPrefix` | `null` | URL pública para el link de descarga en listado/vista; sin esto, solo se muestra el nombre del archivo. |
| `deleteFilesOnDelete` | `true` | Borra del disco los archivos (columnas `file`) del registro cuando se elimina la fila completa. |
| `checkForUpdates` | `false` | Aviso descartable de nueva versión disponible, ver [Aviso de nueva versión](#aviso-de-nueva-versión-checkforupdates). |

Filtro, búsqueda y orden funcionan por AJAX (sin recargar la página) pero siguen siendo consultas al servidor — no se pierden resultados en tablas con miles de filas y paginación, a diferencia de un filtro puramente en JavaScript sobre lo ya cargado en pantalla.

## Ordenar por cualquier columna

Cada encabezado del listado es un enlace que ordena por esa columna (clic de nuevo invierte a descendente, con una flecha indicando el sentido activo). Esto ya funciona out-of-the-box para **cualquier** columna real de la tabla, visible o no — no hay lista blanca que mantener.

Para fijar un orden inicial (antes de que el usuario haga clic en algo), usa `defaultOrderBy`/`defaultOrderDir`:

```php
$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'defaultOrderBy' => 'creado_en',
    'defaultOrderDir' => 'DESC',
]);
```

## Filtros: elegir columnas y constructor avanzado (AND/OR)

Por default, el filtro simple (una fila con un input **dentro de la propia tabla**, justo debajo de cada encabezado de columna) muestra **todas las columnas visibles**. En tablas anchas eso satura la pantalla; `filterableFields` limita cuáles aparecen — las columnas fuera de la lista quedan con una celda vacía (para no romper la alineación) — y también limita cuáles están disponibles en el constructor avanzado (ver abajo):

```php
$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'filterableFields' => ['titulo', 'categoria_id', 'prioridad'],
]);
```

Sin esta opción (`null`, el default), se muestran todas las columnas visibles, igual que antes.

**Columnas de llave foránea (FK):** el filtro de una columna FK (ej. `categoria_id`) se renderiza como un `<select>` con las opciones reales de la tabla referenciada (mismas opciones que en el formulario), no como un input de texto — filtrar una FK compara por igualdad contra el **id**, no contra el nombre visible, así que un input de texto contra el label nunca matchearía nada. Lo mismo aplica dentro del constructor avanzado: si eliges un campo FK, el control de "valor" cambia automáticamente a un `<select>` con esas opciones.

### Constructor de filtro avanzado (AND/OR)

Junto al filtro simple hay un botón **"Filtro avanzado"** que abre un **modal** (`<dialog>` nativo) con filas dinámicas: cada fila es `campo` + `operador` + `valor`, y (salvo la primera) un selector **Y/O** que la conecta con la fila anterior. Las filas se combinan **de izquierda a derecha** — por ejemplo, con 3 filas A, B, C conectadas por Y y O respectivamente, el resultado es `(A Y B) O C`, no la precedencia habitual de SQL (donde `Y` siempre liga más fuerte que `O`). Esto es intencional: en un constructor visual el usuario espera que el orden en que agrega las condiciones sea el orden en que se combinan.

Operadores disponibles: es igual a, es distinto de, contiene, no contiene, mayor que, mayor o igual que, menor que, menor o igual que, es vacío, no es vacío.

No requiere ninguna opción adicional — se activa junto con `filters` (`true` por default). Las filas se pueden agregar/quitar dinámicamente (JS vanilla, sin librerías); al hacer clic en "Filtrar" dentro del modal, se aplican los filtros (combinados con el filtro simple y la búsqueda global, todo en un mismo request AJAX sin recargar la página) y el modal se cierra automáticamente. El estado del filtro avanzado también viaja en los links de ordenar por columna y exportar, para no perderlo al navegar.

### Filtrado en vivo (debounce)

Tanto la búsqueda global como el filtro simple por columna consultan automáticamente mientras escribes: esperan **medio segundo (500ms) sin nueva tecla** antes de disparar la consulta (AJAX, sin recargar la página) — así no se dispara una consulta por cada tecla, pero tampoco hace falta hacer clic en "Filtrar" para ver el resultado. El botón "Filtrar" queda disponible para forzar la consulta al instante (por ejemplo, tras pegar un valor) y "Limpiar" quita todos los filtros activos de un clic.

Mientras esa consulta AJAX está en curso, aparece un indicador de carga (spinner + "Cargando...") superpuesto sobre la tabla — así en tablas grandes, donde la respuesta puede tardar, no se ve un espacio en blanco mientras se espera. El spinner solo se muestra si la respuesta tarda más de 200ms (en tablas chicas, donde la respuesta es casi instantánea, ni se llega a ver — evita el parpadeo).

## Paginación: cuántos registros mostrar

Se puede definir en dos lugares, con esta precedencia (el primero que se defina gana):

**1. Por tabla, en `TableConfig`** — útil cuando distintas tablas de la misma app necesitan un default distinto (un catálogo chico puede querer 50 de entrada; un log grande, 10):

```php
$config = new TableConfig(
    columnOverrides: ['id' => ['hidden' => true]],
    perPage: 10,
    perPageOptions: [10, 24, 50],
);
```

**2. Global, en las opciones de `AppyCrud`** — se usa si la tabla no definió lo anterior:

```php
$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'perPage' => 20,                      // default al abrir el listado
    'perPageOptions' => [10, 20, 50, 100], // opciones del selector "Por página"
]);
```

**Si ninguno de los dos se configura**, el default final es `perPage = 20` con `perPageOptions = [10, 20, 50, 100]`.

El listado incluye un selector **"Por página"** (con las opciones resueltas de `perPageOptions`) y navegación **Anterior/Siguiente**, junto al texto "Página X de Y". Cambiar el valor del selector recarga la página conservando los filtros/búsqueda/orden activos y vuelve a la página 1.

`perPage` en la querystring (`?perPage=50`) **solo se acepta si el valor está en las `perPageOptions` resueltas** — cualquier otro valor se ignora y se usa el `perPage` default. Esto es deliberado: sin esta validación, cualquiera podría pedir `?perPage=999999` y forzar una consulta que trae la tabla completa de un solo golpe.

**Para verlo funcionando:** `examples/index.php` (la tabla `tareas`) ya lo configura así, en el `TableConfig` que arma el ejemplo:

```php
$config = new TableConfig([
    // ...overrides de columnas...
], perPage: 10, perPageOptions: [10, 24, 50]);
```

Como el ejemplo siembra 24 registros por defecto, con `perPage: 10` el listado abre mostrando **3 páginas** — así el selector "Por página" y los botones Anterior/Siguiente son visibles de inmediato sin tener que crear registros a mano. Corre el ejemplo (`php -S localhost:8000 -t examples`) y abre `http://localhost:8000/` para verlo: el selector muestra `10 / 24 / 50` (no el default global `10/20/50/100`) precisamente porque viene del `TableConfig` de esa tabla, no de las opciones de `AppyCrud`.

## Restringir por WHERE (scoping)

Cuando necesitas que un mismo CRUD solo muestre (y solo permita editar/eliminar) los registros que le corresponden a algo — multi-tenant por empresa, "solo mis propios registros", excluir un estado — usa la opción `where`. A diferencia de los filtros del usuario (`filters`/`search`, opcionales y visibles en el listado), estas condiciones son **fijas** y las define el integrador, no el usuario final:

```php
use Appylogi\AppyCrud\Crud\Condition;

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'where' => [
        Condition::where('empresa_id', '=', $idEmpresaActual),
        Condition::whereIn('estado', [1, 2, 3]),
    ],
    // Sin esto, alguien podria insertar un registro con otro empresa_id
    // a mano (manipulando el form) y quedaria invisible para todos.
    'insertDefaults' => [
        'empresa_id' => $idEmpresaActual,
    ],
]);
```

`Condition` tiene tambien `whereNotIn()`, `whereNull()` y `whereNotNull()`. Estas condiciones se aplican **siempre** — no solo al listado: también a exportar, ver, editar y eliminar un registro puntual. Si alguien intenta abrir `?action=edit&id=999` de un registro fuera de su `empresa_id`, `find()` simplemente no lo encuentra (mismo efecto que si no existiera), y `update()`/`delete()` con ese id no afectan ninguna fila.

## Campos permitidos al insertar y editar

Por default, el formulario de crear muestra todas las columnas visibles (menos la llave primaria y las de solo lectura), y lo mismo el de editar. Para restringir cuáles aparecen — y cuáles se aceptan realmente al guardar, aunque alguien arme un POST con campos extra — usa `insertFields`/`editFields`:

```php
$crud = new AppyCrud($connection, 'usuarios', $config, 'es', [
    'insertFields' => ['nombre', 'email', 'rol'],      // el resto ni aparece al crear
    'editFields' => ['nombre', 'email'],                // 'rol' no se puede cambiar despues de creado
]);
```

Esto es una restricción real, no solo visual: aunque el HTML del formulario nunca tenga un campo `rol` en el editar, y aunque alguien arme el POST a mano incluyéndolo, `AppyCrud` lo descarta antes de validar y guardar.

## i18n

`lang/es.php` y `lang/en.php` ya vienen incluidos. Agregar un idioma nuevo es copiar uno de esos archivos a `lang/{codigo}.php` con las mismas llaves traducidas, y pasar `'{codigo}'` como cuarto argumento de `AppyCrud`. No hay que tocar código.

## Seguridad

- **SQL**: todo el acceso a datos usa *prepared statements*; los valores de usuario nunca se concatenan en el SQL.
- **XSS**: toda salida a HTML pasa por `htmlspecialchars`.
- **CSRF**: activado por default (`'csrf' => true`). Genera un token por sesión (`$_SESSION`) y lo exige en `store`/`update`/`delete`/`bulkDelete`, y también en cualquier [`RowAction`](#acciones-custom-por-fila) con `method: 'post'`; los formularios y botones de borrado ya lo incluyen automáticamente, no hay que hacer nada extra. **Requiere `session_start()`** antes de instanciar `AppyCrud` — si no hay sesión activa, lanza `RuntimeException` con un mensaje explicando qué falta. Si tu aplicación ya resuelve CSRF a otro nivel (un framework con su propio middleware, por ejemplo) y no quieres que se dupliquen tokens, desactívalo con `'csrf' => false`.

## Aviso de nueva versión (`checkForUpdates`)

Desactivado por defecto — activarlo es opcional y explícito:

```php
$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'checkForUpdates' => true,
]);
```

Con esto activado, el listado consulta (como mucho una vez cada 24 horas, con cache en disco vía `sys_get_temp_dir()`) la API pública de Packagist para saber cuál es la última versión publicada de `appylogi/appycrud`. Si hay una más nueva que la instalada, aparece un aviso descartable arriba del listado con un link a las notas del release; al descartarlo (guardado en `localStorage` del navegador, por versión) no vuelve a aparecer hasta que salga una versión más nueva todavía.

Puntos importantes:

- **No envía ningún dato del proyecto** — ni URL, ni configuración, ni nada identificable. Solo pregunta "¿cuál es la última versión de este paquete?", la misma pregunta que ya le hace `composer require`/`composer update` a Packagist.
- **Nunca bloquea ni rompe la página**: cualquier fallo de red, timeout, o `allow_url_fopen` desactivado sin `curl` disponible simplemente hace que no se muestre el aviso — no hay excepciones que se propaguen hasta el usuario final.
- Por qué está apagado por defecto: una librería que se posiciona como "sin sorpresas, sin dependencias, sin llamadas a casa" no debería hacer ninguna petición de red sin que el integrador lo pida explícitamente, ni siquiera una tan inocua como esta.

**El aviso solo informa — nunca actualiza nada por su cuenta.** Al ver el banner, actualizar depende de cómo instalaste AppyCrud:

- **Con Composer**: `composer update appylogi/appycrud`.
- **Sin Composer (ZIP)**: descarga el ZIP de la [versión más reciente](https://github.com/appylogi/appycrud/releases) y reemplaza los archivos de `appycrud/` en tu proyecto (revisa el `CHANGELOG.md` del release por si hay algún cambio que requiera ajustar tu configuración).

Que la actualización sea siempre una acción manual y explícita es deliberado: una librería que se auto-actualizara sola, sin que el integrador lo apruebe, sería exactamente el tipo de sorpresa que este proyecto busca evitar.

## Integrarlo con tu propio router

`AppyCrud::handle()` despacha por `$_GET['action']`: `list` (default), `create`, `store`, `edit`, `update`, `delete`, `bulkDelete`, `view`, `clone`, `export`, `print`. Si usas un router propio en vez de un archivo `.php` por tabla, basta con enrutar todas esas acciones al mismo controlador y pasarle `$_GET`/`$_POST` tal cual — AppyCrud no depende de la URL en sí, solo de esos parámetros.
