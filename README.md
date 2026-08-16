# AppyCrud

Generador de CRUD genérico para PHP, sin framework, sin dependencias externas, con estilos Tailwind precompilados (funciona sin internet). Dale una conexión PDO y el nombre de una tabla, y obtienes listado, crear/editar/ver en modal, borrado (con confirmación, directo o lógico), filtros, búsqueda, orden, exportación, clonado e impresión — todo generado automáticamente a partir de la estructura real de tu tabla.

Pensado como alternativa libre y descargable a herramientas como [GroceryCRUD](https://www.grocerycrud.com/): úsalo gratis en tu proyecto, sin licencias ni límites. Si quieres acompañamiento o personalización, [Appylogi](https://appylogi.com) ofrece soporte pago opcional.

## Características

- **Sin dependencias de PHP.** Solo `ext-pdo`. Nada de Composer obligatorio (ver [instalación](docs/instalacion.md)).
- **Multi-motor** vía PDO: MySQL, PostgreSQL, SQLite (y cualquier driver PDO estándar).
- **Autodetección de columnas** (tipos, nulabilidad, llaves foráneas) con overrides opcionales por columna.
- **CRUD en modal**: crear/editar/ver sin recargar la página, con validaciones (`required`, `max`, `min`, `email`, `numeric`).
- **Relaciones**: las llaves foráneas se detectan solas y se renderizan como `<select>`, con su label resuelto en listado, vista y exportación.
- **3 modos de borrado**: preguntar (modal propio, no `confirm()` nativo), directo, o lógico (columna configurable).
- **Borrado masivo**, **clonar** (parametrizable: excluir columnas, agregar sufijo), **ver** de solo lectura, **imprimir** (registro o listado completo).
- **Filtro por columna + búsqueda global + orden**, todo por AJAX con debounce (no recarga la página, sigue siendo consulta server-side — no se pierden resultados en tablas grandes con paginación).
- **Exportar** a CSV, Excel (.xls) y Markdown, respetando los filtros activos.
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
- [docs/uso.md](docs/uso.md) — manual de uso: `TableConfig`, opciones de `AppyCrud`, i18n, personalización.
- [docs/desarrollo.md](docs/desarrollo.md) — solo para quienes contribuyan al proyecto (regenerar el CSS de Tailwind).
- [CHANGELOG.md](CHANGELOG.md) — historial de versiones.

## Licencia

[MIT](LICENSE) — libre para cualquier uso, incluido comercial.
