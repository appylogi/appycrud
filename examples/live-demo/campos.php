<?php

session_start();
require __DIR__ . '/../../autoload.php'; // autoload sin composer (ver README.md de este directorio)
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$pdo = appycrud_demo_sandbox('campos', function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE contenidos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo VARCHAR(150) NOT NULL,
        color VARCHAR(9) DEFAULT "#4f46e5",
        clave_acceso VARCHAR(100),
        aviso TEXT,
        etiquetas VARCHAR(150),
        publicado INTEGER NOT NULL DEFAULT 0,
        fecha_publicacion TEXT
    )');

    $etiquetas = ['nuevo', 'promocion', 'interno', 'urgente'];
    $insert = $pdo->prepare(
        'INSERT INTO contenidos (titulo, color, clave_acceso, aviso, etiquetas, publicado, fecha_publicacion)
         VALUES (:titulo, :color, :clave, :aviso, :etiquetas, :publicado, :fecha)'
    );
    for ($i = 1; $i <= 12; $i++) {
        $insert->execute([
            ':titulo' => 'Contenido ' . $i,
            ':color' => ['#4f46e5', '#0ea5e9', '#16a34a', '#f59e0b'][$i % 4],
            ':clave' => 'clave' . $i,
            ':aviso' => $i % 3 === 0 ? '<p>Aviso <b>importante</b> para el contenido ' . $i . '.</p>' : null,
            ':etiquetas' => $etiquetas[$i % count($etiquetas)],
            ':publicado' => $i % 2,
            ':fecha' => date('Y-m-d', strtotime('-' . $i . ' days')),
        ]);
    }
});

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'titulo' => ['label' => 'Titulo', 'rules' => ['required']],
    'color' => ['label' => 'Color', 'inputType' => 'color'],
    'clave_acceso' => ['label' => 'Clave de acceso', 'inputType' => 'password_toggle'],
    'aviso' => ['label' => 'Aviso', 'inputType' => 'richtext_advanced'],
    'etiquetas' => ['label' => 'Etiquetas', 'inputType' => 'multiselect_searchable', 'options' => [
        ['value' => 'nuevo', 'label' => 'Nuevo'],
        ['value' => 'promocion', 'label' => 'Promocion'],
        ['value' => 'interno', 'label' => 'Interno'],
        ['value' => 'urgente', 'label' => 'Urgente'],
    ]],
    'publicado' => ['label' => 'Publicado', 'inputType' => 'boolean'],
    'fecha_publicacion' => ['label' => 'Fecha', 'inputType' => 'date'],
]);

$crud = new AppyCrud($connection, 'contenidos', $config);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}

appycrud_demo_page(
    'Catálogo de tipos de campo',
    'Color, contraseña con toggle de visibilidad, editor de texto enriquecido, multiselect estilo select2 y fecha — cada uno con el widget correcto, sin escribir HTML a mano.',
    <<<'HTML'
<span class="k">$config</span> = <span class="k">new</span> TableConfig([
    <span class="s">'color'</span>        =&gt; [<span class="s">'inputType' =&gt; 'color'</span>],
    <span class="s">'clave_acceso'</span> =&gt; [<span class="s">'inputType' =&gt; 'password_toggle'</span>],
    <span class="s">'aviso'</span>        =&gt; [<span class="s">'inputType' =&gt; 'richtext_advanced'</span>],
    <span class="s">'etiquetas'</span>    =&gt; [<span class="s">'inputType' =&gt; 'multiselect_searchable', 'options' =&gt; [...]</span>],
]);
HTML,
    $html,
    (int) $pdo->query('SELECT COUNT(*) FROM contenidos')->fetchColumn()
);
