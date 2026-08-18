# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).

## [0.1.0] - 2026-08-16

Primera versión.

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
