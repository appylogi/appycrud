# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).

## [0.1.24] - 2026-08-27

### Agregado
- `TableConfig(columnOrder: [...])`: fuerza un orden explicito de columnas en listado y formulario (equivalente a `->columns([...])` de GroceryCrud). Sin esto, las columnas siempre se muestran en el orden fisico de la tabla en BD -- que no siempre coincide con el orden logico que tenia la app original, sobre todo en tablas legacy donde una columna se agrego despues del diseño original y quedo al final fisicamente aunque conceptualmente vaya al principio. Las columnas no listadas en `columnOrder` conservan su posicion relativa original y quedan al final (no hace falta listarlas todas). Ver `docs/uso.md`.

## [0.1.23] - 2026-08-27

### Corregido
- "Clonar" (`action=clone`) prellenaba el formulario con los datos del registro original, pero **no** con sus relaciones many-to-many: `handleClone()` llamaba `manyToManyFormData(null)` sin pasarle las selecciones del registro fuente, asi que cada relacion (ej. "Operadores logisticos" de un cliente) quedaba siempre vacia en el formulario de clonacion, perdiendo silenciosamente las asociaciones existentes. Ahora se leen las selecciones del registro original y se usan para prellenar el multiselect del clon.

## [0.1.22] - 2026-08-27

### Corregido
- 0.1.21 solo normalizaba una columna cuando llegaba en el payload como `''` explicito -- una columna `hidden => true` (u otra que el navegador simplemente nunca envia, como un checkbox sin marcar) no llega ni siquiera como `''`, llega **ausente**, y seguia rompiendo el INSERT con `Field '...' doesn't have a default value`. Ahora, en INSERT, una columna numerica/fecha ausente se trata igual que si hubiera llegado vacia. En UPDATE se mantiene el comportamiento anterior (ausente = "no toques este campo", nunca se sobreescribe con `0`), para no pisar datos ya guardados que el formulario de edicion no incluye a proposito.

## [0.1.21] - 2026-08-27

### Corregido
- 0.1.19 evitaba el 500 en columnas numericas/fecha opcionales dejadas en blanco quitandolas del payload para que la BD aplicara su `DEFAULT` -- pero si la columna es `NOT NULL` **sin** `DEFAULT` (comun en tablas legacy con columnas de "detalle" que nunca fueron pensadas como obligatorias), quitarla del payload sigue rompiendo el INSERT (`Field '...' doesn't have a default value`), esta vez porque la columna se omite del todo. Ahora, cuando la columna no tiene `DEFAULT` real y no acepta `NULL`, se manda un valor neutro segun el tipo (`0` para columnas numericas) en vez de omitirla -- el mismo resultado que un formulario clasico (ej. GroceryCrud) siempre produjo, **sin requerir ninguna migracion de esquema**. Las columnas que si tienen `DEFAULT` en la BD siguen usandolo (no se sobreescriben con `0`).

## [0.1.20] - 2026-08-27

### Corregido
- Un error del driver de BD al hacer INSERT/UPDATE (violacion de constraint que la app no valida, tipo de dato incompatible, etc.) no se capturaba y quedaba como excepcion sin manejar: con `display_errors=Off` en produccion, el usuario veia una pantalla en blanco con HTTP 500 sin ningun mensaje. `handleStore()`/`handleUpdate()` ahora capturan cualquier `\Throwable` del INSERT/UPDATE, lo registran con `error_log()` y vuelven a mostrar el formulario con el mensaje real del driver (`"No se pudo guardar el registro: ..."`) — igual que hacia GroceryCrud. Es una herramienta interna de administracion, por eso se opta por el mensaje real del driver en vez de uno generico.
- Aunque el backend ya devolviera el formulario con el error (caso anterior, o una validacion 422), el JS del lado cliente (`appycrudSubmitForm()`) solo mostraba esa respuesta cuando el status era *exactamente* `422` — cualquier otro codigo (como el `500` del punto anterior) caia al `else` y hacia `window.location.reload()`, descartando el mensaje sin dejar rastro. Ahora se muestra el HTML devuelto para cualquier respuesta que no sea 2xx, y un fallo de red real (sin respuesta del servidor) muestra un aviso explicito en vez de fallar en silencio.

## [0.1.19] - 2026-08-27

### Corregido
- Guardar (crear o editar) fallaba con un error 500 de base de datos (`Incorrect integer value: ''`) cuando un campo numerico/fecha opcional (no marcado `required`, ver 0.1.18) se dejaba en blanco pero la columna en BD es `NOT NULL`. El formulario mandaba `''` explicitamente en el INSERT/UPDATE; MySQL en modo estricto rechaza eso, y ademas **no** aplica el `DEFAULT` de la columna cuando el valor llega vacio en vez de omitirse del todo. `handleStore()`/`handleUpdate()` ahora quitan del payload cualquier columna numerica/fecha (`int`, `decimal`, `float`, `double`, `date`, `time`, `year`, `bit`) que llego vacia, para que la BD aplique su propio `DEFAULT`/`NULL` en vez de recibir `''`. No afecta a columnas de texto (`varchar`/`text`/`enum`/etc.), que siguen guardando `''` tal cual si el usuario la deja vacia.

## [0.1.18] - 2026-08-27

### Corregido
- **Rompe compatibilidad de comportamiento (no de API):** el atributo HTML `required` (y el asterisco junto al label) de un campo se calculaba a partir de si la columna es `NOT NULL` en la base de datos, sin importar si el desarrollador la marco `'rules' => ['required']` o no. En una tabla legacy con muchas columnas `NOT NULL` que nunca fueron pensadas como obligatorias en el formulario (defaults de `''`/`0` puestos al crear la tabla, no por regla de negocio real), esto forzaba a llenar campos — incluyendo archivos (`inputType: 'file'`) — que la app nunca pidio como obligatorios. Ahora `required` refleja unicamente `column->rules` (lo que el desarrollador configuro explicitamente via `TableConfig`), igual que ya hacia la validacion del lado servidor (`Crud\Validator`) — antes habia una inconsistencia real entre lo que el navegador exigia y lo que el servidor exigia. Si una columna es `NOT NULL` sin default y el desarrollador no la marca `required`, un insert con ese campo vacio ahora puede fallar con un error de base de datos en vez de una validacion — revisar `docs/uso.md#columna-obligatoria-vs-not-null-en-la-bd` para decidir, por columna, si conviene marcarla `required` o ponerle un default en la BD.

## [0.1.17] - 2026-08-27

### Corregido
- Presionar Enter en un filtro por columna (`renderColumnFilterRow`) o en el valor de una fila del filtro avanzado podia disparar el envio nativo del `<form>` (navegacion de pagina completa) en vez de aplicar el filtro por AJAX, en navegadores donde el envio implicito por Enter de un control asociado a distancia (atributo `form="..."`, ya que esos inputs viven en la tabla, no dentro del `<form>` fisico) no respeta de forma confiable el `event.preventDefault()` del `onsubmit`. Se agrega `appycrudFilterKeydown()`, que intercepta Enter directamente en cada input (sin depender del envio nativo) y aplica el filtro de inmediato.

## [0.1.16] - 2026-08-27

### Corregido
- `actionsPosition => LEFT` (0.1.15) descuadraba la tabla cuando `filters` estaba activo: la fila de filtros por columna siempre ponia su celda vacia de "acciones" al final, sin importar la posicion real de esa columna en el encabezado, desalineando cada columna una posicion. `renderColumnFilterRow()` ahora respeta `actionsPosition` igual que el encabezado y las filas de datos.

## [0.1.15] - 2026-08-27

### Agregado
- Opción `actionsPosition` (`Crud\ActionsPosition::RIGHT`, default, o `::LEFT`): ubica la columna de acciones (editar/ver/eliminar/clonar/custom) a la izquierda o derecha de la tabla, en encabezado y filas. Ver [docs/uso.md](docs/uso.md#columna-de-acciones-izquierda-o-derecha) y `examples/index.php`.

## [0.1.14] - 2026-08-27

### Corregido
- El combobox buscable de una referencia (`dropdown_search`/auto-promovido desde 0.1.13) solo filtraba entre un top-500 de opciones precargadas en un `<datalist>` — con una tabla referenciada grande (miles de filas), buscar un valor que cayera fuera de ese top-500 alfabetico (ej. "Medellin" entre miles de ciudades) no encontraba nada. Ahora, cuando el campo es una referencia (llave foranea), el combobox busca en tiempo real contra la base de datos (`AppyCrud::handleReferenceSearch`, nuevo endpoint `action=reference_search`, con debounce de 250ms) en vez de filtrar en el navegador — funciona sin importar el tamano de la tabla referenciada. Los `dropdown`/`enum` con `options` estaticas (no referencia) siguen usando el `<datalist>` de siempre, que no tiene ese problema.

## [0.1.13] - 2026-08-27

### Agregado
- Los `<select>` (referencias, dropdown, enum) con mas de 8 opciones ahora se vuelven buscables automaticamente (`renderSearchableSelect`, ya existia pero antes solo se activaba con `inputType => 'dropdown_search'` explicito) — sin JS de terceros, `<input list>` + `<datalist>` nativo del navegador. No requiere configuracion por columna. Ver [docs/uso.md](docs/uso.md#selects-buscables).

## [0.1.12] - 2026-08-26

### Agregado
- `ManyToMany::$conditions` (Condition[]) filtra las opciones del multiselect a un subconjunto de la tabla relacionada (ej. "solo operadores activos"), igual que `reference.conditions`.

## [0.1.11] - 2026-08-26

### Agregado
- `ManyToMany::$labelColumn` acepta el mismo formato que `reference.label`: una plantilla combinando varias columnas (`'{pkid} - {nit} {nombre}'`), no solo el nombre de una columna. Ver [docs/uso.md](docs/uso.md#muchos-a-muchos).

## [0.1.10] - 2026-08-26

### Corregido
- El label de una relacion (`reference`) guardado en una fila no resolvia en listado/vista/edicion/impresion/exportacion si la tabla referenciada tenia mas de 500 filas y el valor guardado no caia entre las primeras 500 por orden alfabetico de label (ej. una ciudad como "BOGOTA" en una tabla de miles de ciudades) — se veia el valor crudo guardado (el id) en vez del nombre. `referenceOptions()` ahora siempre incluye el label de los valores realmente presentes en la(s) fila(s) que se estan mostrando, ademas del top-500 usado para poblar el `<select>`.

## [0.1.9] - 2026-08-26

### Corregido
- Los dropdown/enum estaticos (`Column::$options`, sin `reference` a otra tabla) mostraban el valor crudo guardado en listado, vista de detalle, impresion y exportacion (CSV/Excel/Markdown) en vez de su label — solo el formulario de edicion resolvia el label correctamente. Ahora se resuelve igual que una relacion en todos esos lugares.

## [0.1.8] - 2026-08-26

### Agregado
- `reference.label` acepta una plantilla con varias columnas (`'{pkid} - {nombre} ({nit})'`), equivalente al `{campo}` de GroceryCrud — antes solo aceptaba el nombre de una sola columna. Se resuelve con `CONCAT` (MySQL/PostgreSQL) o `||` (SQLite); los fragmentos literales viajan parametrizados. Ver [docs/uso.md](docs/uso.md#label-compuesto-varias-columnas).

## [0.1.7] - 2026-08-26

### Agregado
- Nuevas opciones `create` y `edit` (default `true`) en `AppyCrud`, con el mismo comportamiento que `delete`: ocultan el boton/accion correspondiente en la UI y ademas rechazan `action=create`/`store` o `action=edit`/`update` en el servidor. Equivalentes a `unsetAdd()`/`unsetEdit()` en GroceryCrud — utiles para vistas de solo-lectura segun el rol del usuario. Ver [docs/uso.md](docs/uso.md#opciones-de-appycrud).

### Corregido
- El boton "Ver" no aparecia cuando era la unica accion disponible en la fila (`view` activo pero `edit`/`delete`/`clone` desactivados y sin row actions custom) -- se perdia silenciosamente en vez de mostrarse en linea junto al resto de acciones.

## [0.1.6] - 2026-08-26

### Agregado
- Nueva opción `delete` (default `true`) en `AppyCrud`: si se pone en `false`, oculta el botón/acción Eliminar (individual y masivo) en la UI y además rechaza `action=delete`/`action=bulkDelete` en el servidor — no es solo cosmético. Equivalente a `unsetDelete()` en GroceryCrud, para tablas donde borrar no debe ser posible. Ver [docs/uso.md](docs/uso.md#opciones-de-appycrud).

## [0.1.5] - 2026-08-26

### Agregado
- `TableConfig` acepta `title`/`subtitle` para personalizar el encabezado del listado (equivalente a `setSubject()` en GroceryCrud), en vez del texto por defecto `"Listado de :table"`. Ver [docs/uso.md](docs/uso.md#título-y-subtítulo-del-listado-title-subtitle).

## [0.1.4] - 2026-08-26

### Agregado
- Nueva propiedad `Column::$unique`: valida antes de guardar que no exista otra fila con el mismo valor (ignorando la propia fila al editar). Se autodetecta desde un índice `UNIQUE` de una sola columna en la base de datos (MySQL, Postgres y SQLite), igual que ya pasa con llaves foráneas y `ENUM` — o se puede forzar a mano vía `TableConfig` (`'unique' => true`). Si la columna tiene un `UNIQUE` real en la base de datos, además se captura el error que la propia base de datos lanza ante una condición de carrera (dos guardados casi simultáneos) y se convierte en el mismo mensaje de validación en vez de un error crudo — ver `Crud\DuplicateValueException` y [docs/uso.md](docs/uso.md#valores-únicos-unique).

## [0.1.3] - 2026-08-19

### Agregado
- Nueva opción `checkForUpdates` (default `false`) en `AppyCrud`: si se activa, el listado consulta como mucho una vez cada 24h (cache en disco) la API pública de Packagist y muestra un aviso descartable arriba del listado cuando hay una versión más nueva publicada, con link a las notas del release. No envía ningún dato del proyecto, y cualquier fallo de red se ignora en silencio sin bloquear la página. Ver `Crud\UpdateChecker` y [docs/uso.md](docs/uso.md#aviso-de-nueva-versión-checkforupdates).

## [0.1.2] - 2026-08-19

### Corregido
- `perPage: 0` (en las opciones del constructor o en `TableConfig`) causaba division por cero en la paginacion (`ceil($total / 0)`), un warning en PHP 8 y un `LIMIT 0` sin sentido. Ahora se fija un minimo de 1.
- La introspeccion de PostgreSQL no filtraba por `table_schema`: en una base con la misma tabla en dos schemas (ej. `public.usuarios` y `auditoria.usuarios`) podia mezclar columnas de ambas. Ahora se filtra por `current_schema()`, igual que la introspeccion de MySQL ya filtraba por `DATABASE()`.
- `TableConfig::applyTo()` lanzaba un `TypeError` crudo y poco claro si un override tenia el tipo equivocado (ej. `['label' => null]` en vez de un string). Ahora lanza `InvalidArgumentException` con el nombre de la columna, la propiedad y el tipo recibido.

## [0.1.1] - 2026-08-19

### Corregido
- **CSRF**: los `RowAction` con `method: 'post'` se ejecutaban sin verificar el token CSRF ni el método HTTP. El formulario renderizado sí incluye el campo oculto con el token (dando una falsa sensación de protección), pero `AppyCrud::handle()` nunca lo validaba antes de invocar el handler — cualquier acción de este tipo que modifique datos (ej. "archivar") podía dispararse con una simple petición GET forjada desde otra página (CSRF real) mientras la víctima tuviera sesión activa. Ahora se exige `verifyCsrf($post)` antes de ejecutar el handler de cualquier `RowAction` con `method: 'post'`; si el token no es válido, la petición se ignora (igual que en `delete`/`bulkDelete`). Las acciones con `method: 'get'` (el default) no cambian de comportamiento.
- `CrudRepository::insert()` no excluía la llave primaria de los datos a insertar, a diferencia de `update()` que sí lo hace explícitamente. Si `insertFields` incluía el nombre de la PK (o un POST manual la forzaba), se generaba un `INSERT` con un valor de autoincremento explícito, inconsistente con `update()` y potencialmente conflictivo según el motor. Ahora `insert()` también excluye la PK de los datos, igual que `update()`.
- Se eliminó `CrudRepository::bulkDelete()`, un método público sin uso interno que solo borraba filas sin ejecutar los hooks `beforeDelete`/`afterDelete`, sin limpiar archivos subidos y sin limpiar las tablas pivote de relaciones muchos-a-muchos — a diferencia de `AppyCrud::handleBulkDelete()` (la ruta real usada por la librería), que sí hace todo eso por registro. Este método no puede implementarse correctamente a nivel de `CrudRepository` porque no tiene acceso a esa configuración (vive en `AppyCrud`), así que se retira en vez de dejarlo con un comportamiento engañoso. El borrado masivo sigue funcionando igual vía `AppyCrud` (acción `bulkDelete`); esto solo afecta a quien llamaba `CrudRepository::bulkDelete()` directamente.

## [0.1.0] - 2026-08-19

Primera versión etiquetada.

### Corregido
- Scoping (`where`/`whereIn`, etc.) no se aplicaba a las operaciones de relaciones muchos-a-muchos (`manyToManySelected`, `syncManyToMany`, `deleteManyToManyFor`): un id fuera del scope del integrador podía leer/sincronizar/borrar filas de la tabla pivote de otro tenant o ámbito, aunque `find`/`update`/`delete` sobre la tabla principal ya lo rechazaban correctamente. Ahora las tres operaciones verifican primero que el id este dentro de `baseConditions` (via `find()`) y no hacen nada si no lo esta.

### Agregado
- Núcleo: conexión PDO agnóstica de motor (MySQL/PostgreSQL/SQLite), introspección híbrida de tablas (autodetección + overrides vía `TableConfig`), CRUD básico con prepared statements.
- Interfaz: crear/editar/ver en `<dialog>` nativo cargado por fetch, sin recargar la página; Tailwind precompilado (sin CDN, funciona offline).
- Borrado: 3 modos (`CONFIRM`, `DIRECT`, `SOFT`) con modal de confirmación propio; borrado masivo con checkboxes.
- Relaciones: detección automática de llaves foráneas, renderizadas como `<select>`, con su label resuelto en listado/vista/exportación.
- Validaciones por columna (`required`, `max`, `min`, `email`, `numeric`) vía el mismo mecanismo de override.
- Filtro por columna, búsqueda global y orden por columna, todo por AJAX con debounce (sin recargar la página, consulta server-side).
- Exportar a CSV, Excel (.xls) y Markdown, respetando filtros/búsqueda activos.
- Clonar (parametrizable: excluir columnas, agregar sufijo), ver de solo lectura, imprimir (registro individual o listado completo).
- Menú de acciones agrupado por fila cuando hay varias acciones activas.
- Animaciones de entrada en modales y listado.
- i18n español/inglés incluido, extensible con un archivo nuevo en `lang/`.
- Autoloader manual (`autoload.php`) para instalación sin Composer.
- Protección CSRF activada por default (token por sesión, exigido en store/update/delete/bulkDelete); desactivable con `'csrf' => false`.
- Orden por defecto configurable (`defaultOrderBy`/`defaultOrderDir`); el orden por columna (clic en encabezado, asc/desc) ya funcionaba para cualquier columna real.
- Scoping vía `where`/`whereIn`/`whereNotIn`/`whereNull`/`whereNotNull` (clase `Condition`), aplicado a listado, exportar, ver, editar y eliminar (no solo al listado) — pensado para multi-tenant o "solo mis registros". Complementado con `insertDefaults` para forzar valores en cada insert.
- `insertFields`/`editFields`: restringen qué columnas aparecen y se aceptan al crear/editar, con enforcement real server-side (no solo ocultar el campo en el HTML).
- Catálogo de 24 tipos de campo (`FieldType`): boolean, color, date/native_date, datetime/native_datetime/timestamp, native_time, dropdown, dropdown_search, enum, enum_searchable, email, float/numeric, hidden, int, invisible, multiselect_native, multiselect_searchable, password, password_toggle, relational_native, string, text. Varios son alias intencionales del mismo widget. Autodetección de columnas `ENUM` de MySQL (parsea los valores reales). `dropdown_search`/`enum_searchable` usan `<datalist>` nativo (sin JS de terceros); `multiselect_*` se guardan como CSV en una sola columna.
- Relaciones muchos-a-muchos vía tabla pivote (`Crud\ManyToMany`, opción `manyToMany`): multiselect adicional en crear/editar, sincronizado automáticamente tras guardar y limpiado antes de eliminar (sin filas huérfanas). Labels resueltos en la vista de solo lectura.
- Hooks antes/después de insert/update/delete (opción `hooks`): `beforeInsert`/`beforeUpdate` pueden modificar los datos a guardar; cualquier hook `before*` puede lanzar `HookAbortException` para cancelar la operación con un mensaje.
- Las opciones de un `<select>` de llave foránea ahora se pueden filtrar con `conditions` (mismo tipo `Condition` que el scoping) — ej. "solo categorías activas".
- Ni `manyToMany` ni el override de `reference` requieren que exista una `FOREIGN KEY` real en la base de datos (confirmado con pruebas explícitas en MariaDB); se puede declarar más de una relación `manyToMany` sobre la misma tabla sin límite.
- Acciones custom agregadas al menú de cada fila (`Crud\RowAction`, opción `rowActions`), parametrizables: abrir en el mismo modal, como link normal, o como escritura POST con confirmación.
- Carga de archivos (`FieldType::FILE`): nombre de archivo aleatorio (nunca el original), extensiones potencialmente ejecutables neutralizadas a `.bin`, conserva el archivo existente si no se sube uno nuevo al editar. Requiere `uploadDir`; `uploadUrlPrefix` opcional habilita el link de descarga en listado/vista.
- Fix: el diálogo de confirmación reutilizado (borrar fila, borrado masivo, `RowAction` con `confirm`) mostraba siempre "Eliminar" en el botón de aceptar sin importar la acción; ahora el label es dinámico por confirmación.
- Quitar el archivo adjunto: al editar, una casilla "Quitar archivo actual" borra el archivo del disco y limpia la columna sin eliminar el registro. Subir un archivo de reemplazo también borra el anterior (evita huérfanos). Nueva opción `deleteFilesOnDelete` (default `true`): borra automáticamente los archivos físicos de un registro cuando se elimina la fila completa (individual o en borrado masivo).
- Filtros: nueva opción `filterableFields` para limitar qué columnas tienen filtro simple y aparecen en el constructor avanzado (útil en tablas anchas). Nuevo constructor de filtro avanzado con filas dinámicas (campo + operador + valor), combinadas de izquierda a derecha con un selector Y/O entre cada una; 10 operadores disponibles (igual, distinto, contiene, no contiene, mayor/menor [o igual], es/no es vacío). Se integra con el filtrado AJAX existente, y su estado se conserva en los links de ordenar por columna y exportar.
- Nuevo tipo de campo `richtext` (25º en el catálogo): editor de texto enriquecido vanilla (`<div contenteditable>` + negrita/itálica/subrayado/listas, sin librerías de terceros ni CDN). El HTML se sanitiza siempre al guardar (`Crud\HtmlSanitizer`, whitelist de etiquetas vía `DOMDocument`, sin dependencias externas) antes de renderizarse sin escapar en la vista/edición; en el listado y las exportaciones se muestra como texto plano.
- El filtro avanzado ahora se abre en un modal (`<dialog>`) en vez de un panel inline. Íconos en los botones "Filtrar" (lupa) y "Limpiar" (ahora también más visible, con fondo rojo claro); ícono en "Filtro avanzado". El debounce del filtrado en vivo (búsqueda global + filtro por columna) pasó de 350ms a 500ms.
- Fix: el modal de filtro avanzado se abre ahora con una fila de condición ya visible (antes arrancaba vacío) y con más espaciado (filas más altas, ancho del modal mayor); también se corrigió que `max-w-3xl` no estaba en el CSS precompilado (Tailwind JIT solo incluye clases detectadas al momento de compilar) — regenerado con `npx tailwindcss@3`.
- El filtro simple por columna ahora vive **dentro de la propia tabla** (una fila bajo cada encabezado), no en un formulario aparte arriba del listado. Los inputs se asocian al `<form>` que los procesa vía el atributo `form=""` (siguen dentro de `#appycrud-list-body`, que se reemplaza entero por AJAX en cada filtrado/orden/página).
- Botón "Agregar condición" del filtro avanzado, ahora con estilo lleno (azul), más llamativo que el link de texto anterior.
- El ejemplo (`examples/index.php`) ahora siembra 24 registros variados por defecto (categorías/prioridades/etiquetas/notas/colaboradores mezclados), en vez de 1, para poder probar filtro simple, filtro avanzado (AND/OR) y búsqueda con datos reales.
- Fix: el filtro (simple y avanzado) de una columna llave foránea (ej. `categoria_id`) nunca traía resultados — comparaba el nombre visible escrito en un input de texto contra el id numérico real. Ahora se renderiza como `<select>` con las opciones reales de la tabla referenciada, igual que en el formulario.
- Multiselect (`multiselect_native`/`multiselect_searchable`): altura fija (no crece con la cantidad de opciones) + contador "N seleccionado(s)" en vivo. El modal de crear/editar/ver ahora tiene `max-height` real con scroll interno (antes el navegador ganaba la pelea de cascada sobre la utilidad de Tailwind sin `!important`, y el modal se salía de la pantalla con formularios largos).
- Nuevo tipo de campo `richtext_advanced` (26º en el catálogo): mismo editor vanilla que `richtext`, con barra extendida (encabezados H1-H3, insertar/quitar enlace, alinear izquierda/centro/derecha, deshacer/rehacer), sin dependencias nuevas — sigue siendo `document.execCommand`. `Crud\HtmlSanitizer` ahora permite `h1`/`h2`/`h3` y `style="text-align"` (reescrito entero, whitelist de valores) para que ese HTML sobreviva la sanitización.
- Nueva opción `perPage` (default `20`) + `perPageOptions` (default `[10, 20, 50, 100]`): selector "Por página" y navegación Anterior/Siguiente en el listado. Antes no existía ninguna forma de ver registros más allá de la página 1.
- Indicador visual (asterisco rojo) junto al label de cualquier campo obligatorio (no-nullable) en el formulario, más una leyenda si aplica a al menos un campo. No aplica a checkboxes.
- Fix: `AppyCrud::renderListBody()` no capturaba el filtro avanzado activo — los links de "ordenar por columna" generados durante un refresco AJAX lo perdían silenciosamente.
- Indicador de carga (spinner + "Cargando...") sobre la tabla mientras el filtrado/búsqueda/orden AJAX está en curso — antes el listado quedaba en blanco un instante en tablas grandes. Solo se muestra si la respuesta tarda más de 200ms, para no parpadear en tablas chicas.
- `perPage`/`perPageOptions` ahora también se pueden definir por tabla en `TableConfig` (segundo/tercer argumento del constructor), no solo en las opciones globales de `AppyCrud` — útil cuando distintas tablas de la misma app necesitan un default distinto. Precedencia: `TableConfig` > opción de `AppyCrud` > default final (`20` / `[10, 20, 50, 100]`).
- `multiselect_searchable` ahora es un combobox al estilo **select2** (buscar + seleccionar múltiples con chips removibles), en vez de una lista de checkboxes con un filtro de texto arriba. Vanilla JS, sin ninguna librería de terceros. `multiselect_native` no cambia (sigue siendo el `<select multiple>` nativo).
- Botón "Editar" del listado, ahora con fondo/borde propio (antes era solo texto azul plano, poco visible entre el resto de acciones de la fila).
