# Regenerar el CSS de Tailwind

AppyCrud distribuye su CSS **ya compilado** en `assets/css/appycrud.css`.
Los usuarios finales no necesitan Node, npm ni conexión a internet para usarlo
(a diferencia del CDN de Tailwind, que falla sin internet).

Solo los mantenedores necesitan Node si agregan clases nuevas en el HTML
generado por `src/Renderer/` y quieren regenerar el CSS:

```bash
npx tailwindcss@3 -c tailwind.config.js -i ./assets/css/appycrud.src.css -o ./assets/css/appycrud.css --minify
```

`tailwind.config.js` escanea `src/**/*.php` y `examples/**/*.php` para incluir
solo las clases realmente usadas.

# Pruebas automatizadas

```bash
composer install
composer test
```

Corre contra SQLite en memoria (sin depender de un servidor MySQL externo ni de
ninguna configuracion previa) — ver `tests/TestCase.php`. Antes de publicar un
cambio que toque `src/`, corré la suite completa; si agregás un comportamiento
nuevo (o corregís un bug), sumale un test que lo fije, siguiendo el patron de
los que ya existen (uno por fix, con el numero de version donde se corrigio en
el docblock de la clase).
