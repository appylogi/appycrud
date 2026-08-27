# Demo en vivo

Esta es la misma demo que corre en [appycrud.appylogi.com/demo](https://appycrud.appylogi.com/demo/),
incluida en el repo para que cualquiera que lo clone la tenga localmente sin
depender del sitio público. Son 5 páginas, cada una enfocada en un grupo de
características, con el fragmento de código real que las genera:

- **basico.php** — listado, filtros, búsqueda, orden AJAX, paginación y `actionsPosition`.
- **relaciones.php** — llave foránea con `conditions`, select buscable automático (+8 opciones) y `ManyToMany`.
- **campos.php** — catálogo de tipos de campo (color, password_toggle, richtext, multiselect_searchable, fecha).
- **borrado.php** — `deleteMode` (confirm/direct/soft) en vivo, más `where` para scoping.
- **seguridad.php** — XSS, CSRF, `insertFields`/`editFields`, `uploadDir`, `rules`.

## Cómo correrla

No necesita Composer ni base de datos: usa el autoload propio del repo
(`autoload.php` en la raíz) y SQLite en un archivo temporal por sesión de
navegador (cada quien tiene su propia caja de arena, se resetea con el link
"Reiniciar datos" de cada página).

Desde la **raíz del repo** (no desde esta carpeta — las rutas a `assets/` y
`autoload.php` son relativas a la raíz):

```bash
php -S localhost:8000
```

Y abre `http://localhost:8000/examples/live-demo/`.

## Si editas estas páginas

Si cambias algo aquí y quieres que se refleje también en
appycrud.appylogi.com/demo, ese es un despliegue aparte (vive en otro
servidor, con su propia copia vendorizada de la librería) — hay que
sincronizarlo a mano.
