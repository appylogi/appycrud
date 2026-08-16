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
