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
