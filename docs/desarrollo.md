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
