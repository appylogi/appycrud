# AppyCrud

Generador de CRUD genérico para PHP, sin framework, sin dependencias externas, con estilos Tailwind precompilados (funciona sin internet). Dale una conexión PDO y el nombre de una tabla, y obtienes listado, crear/editar/ver en modal, borrado (con confirmación, directo o lógico), filtros, búsqueda, orden, exportación, clonado e impresión — todo generado automáticamente a partir de la estructura real de tu tabla.

Código abierto bajo licencia MIT: úsalo gratis en cualquier proyecto, incluido uso comercial, sin licencias ni límites. Si quieres acompañamiento o personalización, [Appylogi](https://appylogi.com) ofrece soporte pago opcional.

🌐 **Sitio y ejemplos en vivo:** [appycrud.appylogi.com](https://appycrud.appylogi.com) — la sección [/demo](https://appycrud.appylogi.com/demo) tiene varios ejemplos ejecutándose de verdad (cada uno en su propia caja de arena, puedes crear/editar/borrar libremente), uno por grupo de características: listado y filtros, relaciones, catálogo de tipos de campo, y modos de borrado.

## Características

- **Sin dependencias de PHP.** Solo `ext-pdo`. Nada de Composer obligatorio (ver [instalación](docs/instalacion.md)).
- **Multi-motor** vía PDO: MySQL, PostgreSQL, SQLite (y cualquier driver PDO estándar).
- **Autodetección de columnas** (tipos, nulabilidad, llaves foráneas) con overrides opcionales por columna.
- **CRUD en modal**: crear/editar/ver sin recargar la página, con validaciones (`required`, `max`, `min`, `email`, `numeric`).
- **Relaciones**: las llaves foráneas se detectan solas y se renderizan como `<select>`, con su label resuelto en listado, vista y exportación.
- **3 modos de borrado**: preguntar (modal propio, no `confirm()` nativo), directo, o lógico (columna configurable).
- **Borrado masivo**, **clonar** (parametrizable: excluir columnas, agregar sufijo), **ver** de solo lectura, **imprimir** (registro o listado completo).
- **Filtro por columna + búsqueda global + orden**, todo por AJAX con debounce (no recarga la página, sigue siendo consulta server-side — no se pierden resultados en tablas grandes con paginación). **Constructor de filtro avanzado** (filas dinámicas combinadas con AND/OR) y `filterableFields` para limitar qué columnas se pueden filtrar en tablas anchas.
- **Exportar** a CSV, Excel (.xls) y Markdown, respetando los filtros activos.
- **26 tipos de campo** (`boolean`, `dropdown_search`, `multiselect_native`, `password_toggle`, `relational_native`, `richtext`/`richtext_advanced`, etc.) — ver [catálogo completo](docs/uso.md#tipos-de-campo).
- **Paginación configurable**: cuántos registros mostrar por página (`perPage`), con selector en el listado y navegación Anterior/Siguiente.
- **Scoping** vía `where`/`whereIn` (multi-tenant, "solo mis registros") aplicado a listado, exportar, ver, editar y eliminar — no solo cosmético en el listado.
- **Control de campos**: qué columnas se pueden insertar y cuáles editar, por separado.
- **Muchos a muchos** vía tabla pivote (`ManyToMany`), sincronizada automáticamente al guardar.
- **Hooks** antes/después de insert/update/delete, con posibilidad de cancelar la operación.
- **Acciones custom por fila** (además de Ver/Editar/Clonar/Eliminar), parametrizables.
- **Carga de archivos**, con nombre aleatorio seguro, conservación del archivo existente al editar sin re-subir, casilla para quitarlo, y borrado físico automático al eliminar el registro.
- **i18n** (español/inglés incluido, agregar un idioma es un archivo nuevo en `lang/`).
- **Un solo sistema de diseño** (Tailwind, precompilado) para mantener todo consistente; la arquitectura permite agregar otros temas más adelante.

## Instalación rápida

```bash
composer require appylogi/appycrud
```

¿Sin Composer? Descarga el ZIP y usa `require 'appycrud/autoload.php';`. Ver [docs/instalacion.md](docs/instalacion.md) para el detalle completo (requisitos de PHP, ambos métodos, verificación).

## Uso básico

```php
<?php

session_start(); // AppyCrud lo necesita para el token CSRF (activado por default)

require __DIR__ . '/vendor/autoload.php'; // o 'autoload.php' si no usas Composer

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;

$pdo = new PDO('mysql:host=localhost;dbname=mi_bd', 'usuario', 'clave');
$connection = Connection::fromPdo($pdo);

$crud = new AppyCrud($connection, 'clientes');

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><link rel="stylesheet" href="vendor/appylogi/appycrud/assets/css/appycrud.css"></head>
<body><?= $html ?></body>
</html>
```

Eso ya te da un CRUD completo sobre la tabla `clientes`. Ver [docs/uso.md](docs/uso.md) para el detalle de cada opción (`TableConfig`, `deleteMode`, `cloneExcludeColumns`, reglas de validación, cómo agregar un idioma, etc.) y [examples/index.php](examples/index.php) para un ejemplo funcional completo con relaciones.

## Documentación

- [docs/instalacion.md](docs/instalacion.md) — requisitos, Composer vs. ZIP, verificación.
- [docs/uso.md](docs/uso.md) — manual de uso: `TableConfig`, tipos de campo, scoping, opciones de `AppyCrud`, i18n, personalización.
- [docs/tecnico.md](docs/tecnico.md) — manual técnico: arquitectura interna, cómo agregar un tipo de campo nuevo.
- [docs/desarrollo.md](docs/desarrollo.md) — solo para quienes contribuyan al proyecto (regenerar el CSS de Tailwind).
- [CHANGELOG.md](CHANGELOG.md) — historial de versiones.

## Licencia

[MIT](LICENSE) — libre para cualquier uso, incluido comercial.
