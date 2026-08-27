<?php

session_start();
require __DIR__ . '/../../autoload.php'; // autoload sin composer (ver README.md de este directorio)
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Crud\Condition;
use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$pdo = appycrud_demo_sandbox('borrado', function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE notas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        texto VARCHAR(200) NOT NULL,
        importante INTEGER NOT NULL DEFAULT 0,
        archivada INTEGER NOT NULL DEFAULT 0
    )');
    $insert = $pdo->prepare('INSERT INTO notas (texto, importante) VALUES (:texto, :importante)');
    for ($i = 1; $i <= 8; $i++) {
        $insert->execute([':texto' => 'Nota de ejemplo ' . $i, ':importante' => $i % 3 === 0 ? 1 : 0]);
    }
});

$modo = $_GET['modo'] ?? 'confirm';
$deleteMode = match ($modo) {
    'direct' => DeleteMode::DIRECT,
    'soft' => DeleteMode::SOFT,
    default => DeleteMode::CONFIRM,
};

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'texto' => ['label' => 'Texto', 'rules' => ['required', 'max:200']],
    'importante' => ['label' => 'Importante', 'inputType' => 'boolean'],
    'archivada' => ['hidden' => $modo !== 'soft'],
]);

$options = [
    'deleteMode' => $deleteMode,
];
if ($modo === 'soft') {
    $options['softDeleteColumn'] = 'archivada';
} else {
    $options['where'] = [Condition::where('archivada', '=', 0)];
}

$crud = new AppyCrud($connection, 'notas', $config, 'es', $options);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}

$modos = ['confirm' => 'Confirmación', 'direct' => 'Directo', 'soft' => 'Lógico (soft)'];
$tabs = '<div style="display:flex;gap:10px;margin-bottom:20px">';
foreach ($modos as $key => $label) {
    $active = $key === $modo;
    $style = $active
        ? 'background:#4f46e5;color:#fff;border-color:#4f46e5'
        : 'background:#fff;color:#334155;border-color:#cbd5e1';
    $tabs .= '<a href="?modo=' . $key . '" style="text-decoration:none;padding:8px 16px;border-radius:8px;border:1px solid #cbd5e1;font-size:.85rem;font-weight:600;' . $style . '">' . $label . '</a>';
}
$tabs .= '</div>';

$deleteModeConst = $modo === 'direct' ? 'DIRECT' : ($modo === 'soft' ? 'SOFT' : 'CONFIRM');
$snippetExtra = $modo === 'soft'
    ? '<span class="k">$options</span>[<span class="s">\'softDeleteColumn\'</span>] = <span class="s">\'archivada\'</span>;'
    : '<span class="k">$options</span>[<span class="s">\'where\'</span>] = [Condition::where(<span class="s">\'archivada\', \'=\', 0</span>)];';
$snippet = '<span class="k">$options</span>[<span class="s">\'deleteMode\'</span>] = DeleteMode::<span class="k">' . $deleteModeConst . '</span>;' . "\n" . $snippetExtra;

// En soft se ven tambien las archivadas (no hay 'where' excluyendolas); en
// confirm/direct el 'where' las oculta, asi que contamos igual que el listado.
$visibleCountSql = $modo === 'soft' ? 'SELECT COUNT(*) FROM notas' : 'SELECT COUNT(*) FROM notas WHERE archivada = 0';

appycrud_demo_page(
    'Modos de borrado y scoping',
    'Cambia entre los 3 modos con las pestañas: confirmación con modal propio, borrado directo, o lógico (nunca se borra la fila, solo se marca "archivada"). En confirm/direct, un <code>where</code> fijo excluye las notas archivadas de todo (listado, exportar, editar).',
    $snippet,
    $tabs . $html,
    (int) $pdo->query($visibleCountSql)->fetchColumn()
);
