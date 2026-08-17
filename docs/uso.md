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

### Reglas de validación disponibles

| Regla | Efecto |
|---|---|
| `required` | El campo no puede llegar vacío. |
| `max:N` | Máximo N caracteres. |
| `min:N` | Mínimo N caracteres. |
| `email` | Debe ser un correo válido (`filter_var`). |
| `numeric` | Debe ser numérico. |

Si la validación falla, AppyCrud responde con HTTP 422 y re-renderiza el formulario con los errores — sin perder los datos ya escritos ni el modal abierto.

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
| `multiselect_searchable` | checkboxes + filtro de texto | Mismo almacenamiento (CSV) que `multiselect_native`. |

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
- Un `<input type="file">` nunca se puede prellenar por seguridad del navegador. Si al editar no seleccionas un archivo nuevo, AppyCrud **conserva el archivo ya guardado** — no lo borra ni lo deja vacío.
- El formulario agrega automáticamente `enctype="multipart/form-data"` en cuanto detecta al menos un campo `file`.

**Importante — `uploadDir` no debería ser accesible directamente por HTTP con ejecución de scripts habilitada.** AppyCrud ya neutraliza extensiones peligrosas, pero esa es una defensa adicional, no un reemplazo de una configuración de servidor correcta (por ejemplo, un `.htaccess` con `php_flag engine off` en esa carpeta, o guardarla fuera del docroot y servirla mediante un script intermedio).

**Esta versión no borra el archivo físico cuando se elimina el registro** (evita pérdida de datos si el archivo se reutiliza en otro lado); si quieres ese comportamiento, bórralo tú mismo desde un hook `afterDelete` (ver [Hooks](#hooks-antesdespués-de-las-acciones)).

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
| `filters` | `true` | Fila de filtros por columna. |
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
- **CSRF**: activado por default (`'csrf' => true`). Genera un token por sesión (`$_SESSION`) y lo exige en `store`/`update`/`delete`/`bulkDelete`; los formularios y botones de borrado ya lo incluyen automáticamente, no hay que hacer nada extra. **Requiere `session_start()`** antes de instanciar `AppyCrud` — si no hay sesión activa, lanza `RuntimeException` con un mensaje explicando qué falta. Si tu aplicación ya resuelve CSRF a otro nivel (un framework con su propio middleware, por ejemplo) y no quieres que se dupliquen tokens, desactívalo con `'csrf' => false`.

## Integrarlo con tu propio router

`AppyCrud::handle()` despacha por `$_GET['action']`: `list` (default), `create`, `store`, `edit`, `update`, `delete`, `bulkDelete`, `view`, `clone`, `export`, `print`. Si usas un router propio en vez de un archivo `.php` por tabla, basta con enrutar todas esas acciones al mismo controlador y pasarle `$_GET`/`$_POST` tal cual — AppyCrud no depende de la URL en sí, solo de esos parámetros.
