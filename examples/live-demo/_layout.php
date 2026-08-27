<?php

function appycrud_demo_page(string $title, string $description, string $snippet, string $bodyHtml, ?int $rowCount = null): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Demo AppyCrud</title>
<link rel="stylesheet" href="../../assets/css/appycrud.css">
<style>
  :root{--brand:#4f46e5;--dark:#0b0f19}
  body{background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;color:#0f172a}
  .demo-bar{
    background:var(--dark);color:#e7e9f3;padding:12px 20px;
    display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
    font-size:.88rem;
  }
  .demo-bar a{color:#818cf8;text-decoration:none;font-weight:600}
  .demo-bar .tag{background:#34d399;color:#04231a;font-weight:700;font-size:.7rem;padding:3px 9px;border-radius:999px;margin-right:10px}
  .demo-wrap{max-width:1100px;margin:0 auto;padding:24px 16px 56px}
  .demo-head{margin-bottom:18px}
  .demo-head h1{font-size:1.4rem;margin:0 0 6px;letter-spacing:-.01em}
  .demo-head p{color:#475569;margin:0;font-size:.94rem}
  .demo-snippet{
    background:#0d1220;color:#c3e88d;border-radius:10px;padding:16px 18px;
    font-family:Consolas,Menlo,monospace;font-size:.8rem;line-height:1.6;
    overflow-x:auto;margin:16px 0 26px;border:1px solid #232a3d;white-space:pre;
  }
  .demo-snippet .k{color:#c792ea}
  .demo-snippet .s{color:#89ddff}
  .demo-mariadb{
    margin:-10px 0 26px;border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;overflow:hidden;
  }
  .demo-mariadb summary{
    cursor:pointer;padding:12px 16px;font-size:.85rem;font-weight:600;color:#334155;
    list-style:none;display:flex;align-items:center;gap:8px;
  }
  .demo-mariadb summary::-webkit-details-marker{display:none}
  .demo-mariadb summary::before{content:"▸";color:#4f46e5;transition:transform .15s}
  .demo-mariadb[open] summary::before{transform:rotate(90deg)}
  .demo-mariadb .inner{padding:0 16px 16px}
  .demo-mariadb .inner p{margin:0 0 10px;color:#475569;font-size:.85rem}
  .demo-mariadb pre{
    background:#0d1220;color:#c3e88d;border-radius:8px;padding:14px 16px;
    font-family:Consolas,Menlo,monospace;font-size:.8rem;line-height:1.6;margin:0;overflow-x:auto;white-space:pre;
  }
  .demo-mariadb pre .k{color:#c792ea}
  .demo-mariadb pre .s{color:#89ddff}
  .demo-mariadb pre .com{color:#546e7a}
  .demo-empty{
    display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:space-between;
    margin:0 0 22px;padding:18px 20px;border-radius:12px;
    background:#fffbeb;border:1px solid #fde68a;
  }
  .demo-empty .msg{display:flex;align-items:center;gap:10px;color:#92400e;font-size:.92rem}
  .demo-empty .msg svg{flex-shrink:0}
  .demo-empty .msg strong{display:block;font-size:.98rem;margin-bottom:2px;color:#78350f}
  .demo-empty a.btn-reset{
    flex-shrink:0;display:inline-flex;align-items:center;gap:8px;
    background:#4f46e5;color:#fff;text-decoration:none;font-weight:600;font-size:.88rem;
    padding:10px 18px;border-radius:8px;
  }
  .demo-empty a.btn-reset:hover{background:#4338ca}
</style>
</head>
<body>
  <div class="demo-bar">
    <div><span class="tag">DEMO</span>Caja de arena: crea, edita o borra libremente — nadie mas ve tus cambios.</div>
    <div><a href="?reset=1">Reiniciar datos</a> &nbsp;·&nbsp; <a href="./">&larr; Todos los ejemplos</a> &nbsp;·&nbsp; <a href="https://github.com/appylogi/appycrud" target="_blank" rel="noopener">GitHub</a></div>
  </div>
  <div class="demo-wrap">
    <div class="demo-head">
      <h1><?= htmlspecialchars($title) ?></h1>
      <p><?= $description ?></p>
    </div>
    <div class="demo-snippet"><?= $snippet ?></div>
    <details class="demo-mariadb">
      <summary>¿Y con MariaDB/MySQL en producción, en vez del SQLite de esta demo?</summary>
      <div class="inner">
        <p>Esta demo usa SQLite en un archivo temporal para no necesitar una base de datos real. En tu proyecto, con MariaDB o MySQL, solo cambia cómo abres la conexión — el resto del código (<code>TableConfig</code>, <code>AppyCrud</code>, <code>handle()</code>) es exactamente igual.</p>
        <pre><span class="k">$pdo</span> <span class="k">=</span> <span class="k">new</span> PDO(
    <span class="s">'mysql:host=localhost;port=3306;dbname=mi_bd;charset=utf8mb4'</span>,
    <span class="s">'usuario'</span>,
    <span class="s">'clave'</span>
);
<span class="k">$connection</span> <span class="k">=</span> Connection::fromPdo(<span class="k">$pdo</span>);
<span class="com">// MariaDB usa el mismo driver PDO 'mysql' que MySQL — no hay driver 'mariadb' separado.
// Con Laravel: Connection::fromPdo(DB::connection()->getPdo());
// Con CodeIgniter 4: Connection::fromPdo(\Config\Database::connect()->getConnection());</span></pre>
      </div>
    </details>
    <?php if ($rowCount === 0): ?>
    <div class="demo-empty">
      <div class="msg">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M12 9v4M12 17h.01M10.29 3.86l-8.18 14.14A1.5 1.5 0 0 0 3.5 20.5h17a1.5 1.5 0 0 0 1.39-2.5L13.71 3.86a1.5 1.5 0 0 0-2.42 0Z"/></svg>
        <div><strong>Tu caja de arena esta vacia</strong>Borraste (o alguien mas en esta sesion borro) todos los registros de ejemplo. Esto es normal en una demo editable — no es un error.</div>
      </div>
      <a href="?reset=1" class="btn-reset">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 1 3 6.7"/><path d="M3 16v-4h4"/></svg>
        Reiniciar datos de ejemplo
      </a>
    </div>
    <?php endif; ?>
    <?= $bodyHtml ?>
  </div>
</body>
</html>
    <?php
}
