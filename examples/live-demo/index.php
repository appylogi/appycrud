<?php

$ejemplos = [
    [
        'href' => 'basico',
        'titulo' => 'Listado, filtros y paginación',
        'descripcion' => 'Filtro por columna, búsqueda global, orden AJAX, filtro avanzado AND/OR, paginación configurable y columna de acciones a la izquierda o derecha.',
        'config' => "perPage, filterableFields, actionsPosition",
    ],
    [
        'href' => 'relaciones',
        'titulo' => 'Relaciones: FK y muchos a muchos',
        'descripcion' => 'Llave foránea auto-detectada con opciones filtradas y select buscable automático (+8 opciones), más una relación muchos-a-muchos real vía tabla pivote.',
        'config' => "reference + conditions, select buscable, ManyToMany",
    ],
    [
        'href' => 'campos',
        'titulo' => 'Catálogo de tipos de campo',
        'descripcion' => 'Color, contraseña con toggle, editor de texto enriquecido, multiselect estilo select2 y fecha.',
        'config' => "color, password_toggle, richtext_advanced, multiselect_searchable",
    ],
    [
        'href' => 'borrado',
        'titulo' => 'Modos de borrado y scoping',
        'descripcion' => 'Cambia en vivo entre confirmación, borrado directo y borrado lógico (soft delete), más scoping con where fijo.',
        'config' => "deleteMode, softDeleteColumn, where",
    ],
    [
        'href' => 'seguridad',
        'titulo' => 'Seguridad: XSS, CSRF, uploads y validación',
        'descripcion' => 'Prueba pegar un <script> en el editor, subir un archivo .php, o forzar un campo oculto por POST — todo protegido por defecto.',
        'config' => "csrf, insertFields/editFields, uploadDir, rules",
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ejemplos en vivo — AppyCrud</title>
<style>
  :root{--brand:#4f46e5;--dark:#0b0f19}
  *{box-sizing:border-box}
  body{background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;color:#0f172a}
  .demo-bar{
    background:var(--dark);color:#e7e9f3;padding:12px 20px;
    display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;font-size:.88rem;
  }
  .demo-bar a{color:#818cf8;text-decoration:none;font-weight:600}
  .wrap{max-width:1000px;margin:0 auto;padding:40px 20px 60px}
  h1{font-size:1.9rem;letter-spacing:-.02em;margin:0 0 10px}
  .lead{color:#475569;max-width:620px;margin:0 0 36px;font-size:1rem}
  .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
  @media (max-width:720px){.grid{grid-template-columns:1fr}}
  .card{
    background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .card:hover{transform:translateY(-3px);box-shadow:0 12px 30px -10px rgba(15,23,42,.15)}
  .card h2{font-size:1.1rem;margin:0 0 8px}
  .card p{color:#475569;font-size:.9rem;margin:0 0 14px}
  .card code{
    display:block;background:#f1f5f9;color:#4338ca;border-radius:8px;padding:8px 10px;
    font-size:.76rem;font-family:Consolas,Menlo,monospace;margin-bottom:16px;word-break:break-word;
  }
  .card a.btn{
    display:inline-flex;align-items:center;gap:6px;background:var(--brand);color:#fff;
    padding:9px 16px;border-radius:8px;font-weight:600;font-size:.86rem;text-decoration:none;
  }
</style>
</head>
<body>
  <div class="demo-bar">
    <div>Ejemplos en vivo de AppyCrud — cada uno es tu propia caja de arena</div>
    <div><a href="https://appycrud.appylogi.com/demo/" target="_blank" rel="noopener">Ver en appycrud.appylogi.com</a> &nbsp;·&nbsp; <a href="https://github.com/appylogi/appycrud" target="_blank" rel="noopener">GitHub</a></div>
  </div>
  <div class="wrap">
    <h1>Elige qué característica quieres ver en acción</h1>
    <p class="lead">Un solo ejemplo no alcanza para mostrar todo lo que hace AppyCrud, así que aquí tienes varios, cada uno configurado para demostrar un grupo de características distinto — con el fragmento de código real que lo genera.</p>
    <div class="grid">
      <?php foreach ($ejemplos as $e): ?>
      <div class="card">
        <h2><?= htmlspecialchars($e['titulo']) ?></h2>
        <p><?= htmlspecialchars($e['descripcion']) ?></p>
        <code><?= htmlspecialchars($e['config']) ?></code>
        <a class="btn" href="<?= htmlspecialchars($e['href']) ?>">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3l14 9-14 9V3z"/></svg>
          Probar este ejemplo
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</body>
</html>
