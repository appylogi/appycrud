<?php

session_start();
require __DIR__ . '/../../autoload.php'; // autoload sin composer (ver README.md de este directorio)
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$uploadDir = sys_get_temp_dir() . '/appycrud-demo/uploads-seguridad';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$pdo = appycrud_demo_sandbox('seguridad', function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE reportes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo VARCHAR(150) NOT NULL,
        correo VARCHAR(150) NOT NULL,
        notas TEXT,
        adjunto VARCHAR(255),
        interno INTEGER NOT NULL DEFAULT 0,
        creado_en TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec("INSERT INTO reportes (titulo, correo, notas) VALUES
        ('Reporte de ejemplo 1', 'ana@ejemplo.com', '<p>Prueba a editar este campo y pegar <b>&lt;script&gt;alert(1)&lt;/script&gt;</b> — al guardar, se elimina.</p>'),
        ('Reporte de ejemplo 2', 'beto@ejemplo.com', '<p>Prueba tambien subir un archivo .php como adjunto: se guarda como .bin.</p>')");
});

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creado_en' => ['hidden' => true, 'readOnly' => true],
    'interno' => ['hidden' => true],
    'titulo' => ['label' => 'Titulo', 'rules' => ['required', 'max:100']],
    'correo' => ['label' => 'Correo', 'inputType' => 'email', 'rules' => ['required', 'email']],
    'notas' => ['label' => 'Notas (editor enriquecido)', 'inputType' => 'richtext_advanced'],
    'adjunto' => ['label' => 'Adjunto (prueba con un .php o .exe)', 'inputType' => 'file'],
]);

$crud = new AppyCrud($connection, 'reportes', $config, 'es', [
    // csrf ya viene en true por defecto — se deja explicito para que se note en el snippet.
    'csrf' => true,
    'insertFields' => ['titulo', 'correo', 'notas', 'adjunto'],
    'editFields' => ['titulo', 'correo', 'notas', 'adjunto'],
    'uploadDir' => $uploadDir,
]);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}

$checklist = '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 22px;margin-bottom:24px">'
    . '<h2 style="font-size:1rem;margin:0 0 12px">Cosas para probar en este ejemplo</h2>'
    . '<ul style="margin:0;padding-left:20px;color:#334155;font-size:.9rem;line-height:1.8">'
    . '<li><b>XSS en el editor:</b> edita "Notas", pega <code>&lt;script&gt;alert(1)&lt;/script&gt;</code> y guarda — al volver a abrirlo, el script ya no existe.</li>'
    . '<li><b>Subida de archivos:</b> en "Adjunto", sube un archivo <code>.php</code> o <code>.exe</code> — se guarda con nombre aleatorio y extensión <code>.bin</code>, nunca ejecutable.</li>'
    . '<li><b>Validación real:</b> intenta guardar con el campo "Correo" vacío o con un texto que no sea un email — el servidor lo rechaza aunque manipules el HTML del formulario.</li>'
    . '<li><b>CSRF:</b> mira el código fuente del formulario (clic derecho &rarr; "Ver código fuente") — hay un campo oculto con un token distinto en cada carga de página.</li>'
    . '<li><b>Campos ocultos protegidos:</b> "interno" no aparece en el formulario porque no está en <code>insertFields</code>/<code>editFields</code> — y aunque lo agregues a mano al POST desde las herramientas de desarrollador, el servidor lo descarta.</li>'
    . '</ul></div>';

appycrud_demo_page(
    'Seguridad: XSS, CSRF, uploads y validación',
    'Todo esto viene activado por defecto en cualquier tabla, sin configuración extra.',
    <<<'HTML'
<span class="k">$crud</span> = <span class="k">new</span> AppyCrud(<span class="k">$connection</span>, <span class="s">'reportes'</span>, <span class="k">$config</span>, <span class="s">'es'</span>, [
    <span class="s">'csrf'</span> =&gt; <span class="k">true</span>,                                  <span class="com">// default</span>
    <span class="s">'insertFields'</span> =&gt; [<span class="s">'titulo', 'correo', 'notas', 'adjunto'</span>],
    <span class="s">'editFields'</span>   =&gt; [<span class="s">'titulo', 'correo', 'notas', 'adjunto'</span>],
    <span class="s">'uploadDir'</span> =&gt; <span class="k">$uploadDir</span>,
]);
<span class="com">// 'correo' => ['inputType' => 'email', 'rules' => ['required', 'email']]
// 'notas'  => ['inputType' => 'richtext_advanced'] -- se sanitiza al guardar</span>
HTML,
    $checklist . $html,
    (int) $pdo->query('SELECT COUNT(*) FROM reportes')->fetchColumn()
);
