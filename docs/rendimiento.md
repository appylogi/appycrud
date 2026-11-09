# Rendimiento y paginación rápida
Disponible desde AppyCrud 0.1.38. Estas opciones son optativas: los módulos existentes conservan su paginación exacta, búsqueda y orden alfabético de referencias.

```php
$crud = new AppyCrud($connection, 'registros', $config, 'es', [
    'paginationMode' => 'simple',
    'defaultOrderBy' => 'id',
    'defaultOrderDir' => 'DESC',
    'sortableFields' => ['id', 'categoria_id'],
    'referenceOrderByValue' => true,
    'autoFilter' => false,
    'exportMaxRows' => 10000,
]);
```

## Configurar una tabla grande
`paginationMode` acepta `exact` (predeterminado) y `simple`. El modo simple pide una fila extra para decidir si hay otra página, sin `COUNT(*)`; devuelve total/lastPage nulos y muestra las filas de la página, sin inventar un total.

Con un orden por la llave primaria se usan cursores firmados ligados a la sesión y a los filtros/orden/tamaño. Siguiente y Anterior usan comparación de PK y no OFFSET. Un cursor modificado o de otro contexto reinicia la navegación. Mantén sesiones y CSRF habilitados. El cursor expresa una posición, nunca autorización: las condiciones `where` y borrado lógico siguen aplicándose. Con otros órdenes, la paginación simple utiliza OFFSET y puede hacerse más lenta en páginas profundas. No es una instantánea inmutable ante escrituras simultáneas.

`sortableFields` restringe encabezados y parámetros de orden a una lista de columnas. Elige campos indexados. `referenceOrderByValue=true` ordena por el código local de una relación y mantiene su etiqueta visible; cambia deliberadamente la semántica del orden alfabético habitual. Se añade la PK como desempate. Si no lo activas, se conserva el orden por etiqueta.

`autoFilter=false` conserva los campos y filtros avanzados, pero aplica el listado al pulsar Filtrar o Enter. La búsqueda remota de opciones de referencia sigue funcionando. Puedes mantener la búsqueda global o deshabilitarla con `search=false` en tablas masivas donde necesites filtros concretos.

`exportMaxRows` es nulo por defecto (comportamiento anterior). Si configuras un entero positivo, exige filtros para exportar y hace un sondeo limitado a máximo+1 PK antes de enviar cabeceras. Si faltan filtros o el resultado excede el límite devuelve 422 con un aviso, sin descargar HTML presentado como CSV. CSV/XLS/Markdown válidos conservan su formato. No es una cola de exportación: usa filtros selectivos y un trabajo diferido si necesitas millones de filas.

## Mejoras generales
Se reutilizan opciones de referencias idénticas dentro de cada repositorio/conexión/petición. La clave incluye la definición de referencia, condiciones y límite. Se siguen resolviendo los valores seleccionados fuera de las primeras opciones y se invalida la caché local al insertar/editar/eliminar. No es una caché global entre usuarios o tenants.

Los filtros AJAX cancelan el GET anterior cuando hay AbortController y descartan respuestas atrasadas por secuencia, incluso si el navegador no puede abortar. Los errores conservan los filtros y el listado con un aviso accesible. Cancelar fetch no garantiza cancelar SQL en el servidor.

Las acciones directas `export`, `print` y `clone` también respetan sus opciones de permiso. Las reglas de escritura, hooks, M2M, condiciones de alcance y CSRF se conservan.

## Ejemplos y pruebas
[Demo rápida](https://appycrud.appylogi.com/demo/rendimiento): 600 filas ficticias iniciales por sesión, 25 por página y límite de exportación de 100 para demostrar la advertencia. Los demás ejemplos conservan totales y filtros habituales. El CSS de la plantilla usa versión por modificación del archivo.

`composer test`: suite PHPUnit existente más 55 comprobaciones CLI en SQLite aislado (cursores ASC/DESC, filtros, caché de referencias, etiquetas fuera de límite, CSV, permisos, CSRF, CRUD, hooks y M2M). `composer test:ajax`: 14 comprobaciones de concurrencia con Node, sin red ni acciones de negocio. Node no es una dependencia de ejecución de AppyCrud. Las pruebas de SQLite no certifican por sí solas tiempos ni planes SQL de otros motores; valida índices y consultas en tu base.
