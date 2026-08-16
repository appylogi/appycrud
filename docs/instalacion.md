# Instalación

## Requisitos

- **PHP 8.1 o superior** (usa `match` y tipos `never`, no funciona en 7.x/8.0).
- Extensión `PDO` habilitada, más el driver del motor que uses (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`...).
- Una base de datos MySQL, PostgreSQL o SQLite (cualquier motor con driver PDO estándar debería funcionar; estos tres son los probados).

No hay más dependencias de PHP. El CSS ya viene compilado — no necesitas Node ni Tailwind CLI para *usar* AppyCrud (sí para modificar sus estilos, ver [docs/desarrollo.md](desarrollo.md)).

## Opción 1: Composer (recomendada)

```bash
composer require appylogi/appycrud
```

Esto instala la librería en `vendor/appylogi/appycrud/` y registra el autoload PSR-4. En tu código:

```php
require __DIR__ . '/vendor/autoload.php';

use Appylogi\AppyCrud\AppyCrud;
```

El CSS precompilado queda en `vendor/appylogi/appycrud/assets/css/appycrud.css` — enlázalo desde tu HTML (copialo a tu carpeta pública si tu servidor no expone `vendor/`).

## Opción 2: Descarga manual (sin Composer)

1. Descarga el ZIP del repositorio (o clónalo) y colócalo donde lo vayas a usar, por ejemplo `mi-proyecto/appycrud/`.
2. En tu código, en vez de `vendor/autoload.php`, incluye el autoloader propio:

```php
require __DIR__ . '/appycrud/autoload.php';

use Appylogi\AppyCrud\AppyCrud;
```

3. Enlaza el CSS precompilado desde `appycrud/assets/css/appycrud.css`.

No necesitas `composer install` para esta opción — el autoloader manual (`autoload.php`) no depende de Composer.

## Verificar que quedó instalado

Con cualquiera de las dos opciones, corre esto para confirmar que las clases cargan:

```php
<?php
require __DIR__ . '/vendor/autoload.php'; // o 'appycrud/autoload.php'

use Appylogi\AppyCrud\AppyCrud;

var_dump(class_exists(AppyCrud::class)); // debe imprimir bool(true)
```

## Siguiente paso

Ver [docs/uso.md](uso.md) para crear tu primer CRUD, o correr el ejemplo incluido:

```bash
composer install   # solo si vas a correr el ejemplo con Composer
php -S localhost:8000 -t examples
```

Y abre `http://localhost:8000/`.
