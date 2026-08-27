<?php

session_start();
require __DIR__ . '/../../autoload.php'; // autoload sin composer (ver README.md de este directorio)
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Crud\ActionsPosition;
use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$pdo = appycrud_demo_sandbox('basico', function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE categorias (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL)');
    $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Trabajo'), ('Personal'), ('Urgente')");

    $pdo->exec('CREATE TABLE tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo VARCHAR(150) NOT NULL,
        descripcion TEXT,
        categoria_id INTEGER REFERENCES categorias(id),
        prioridad VARCHAR(10) DEFAULT "media",
        completada INTEGER NOT NULL DEFAULT 0,
        creada_en TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $categoriaIds = [1, 2, 1, 3, 2];
    $prioridades = ['baja', 'media', 'alta'];
    $insert = $pdo->prepare(
        'INSERT INTO tareas (titulo, descripcion, categoria_id, prioridad, completada)
         VALUES (:titulo, :descripcion, :categoria_id, :prioridad, :completada)'
    );
    for ($i = 1; $i <= 24; $i++) {
        $insert->execute([
            ':titulo' => 'Tarea de ejemplo ' . $i,
            ':descripcion' => 'Puedes editar, filtrar o borrar esta fila libremente.',
            ':categoria_id' => $categoriaIds[$i % count($categoriaIds)],
            ':prioridad' => $prioridades[$i % count($prioridades)],
            ':completada' => $i % 3 === 0 ? 1 : 0,
        ]);
    }
});

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creada_en' => ['hidden' => true, 'readOnly' => true],
    'titulo' => ['label' => 'Titulo', 'rules' => ['required', 'max:100']],
    'descripcion' => ['label' => 'Descripcion', 'inputType' => 'text'],
    'categoria_id' => ['label' => 'Categoria', 'reference' => [
        'table' => 'categorias', 'column' => 'id', 'label' => 'nombre',
    ]],
    'prioridad' => ['label' => 'Prioridad', 'inputType' => 'dropdown', 'options' => [
        ['value' => 'baja', 'label' => 'Baja'],
        ['value' => 'media', 'label' => 'Media'],
        ['value' => 'alta', 'label' => 'Alta'],
    ]],
    'completada' => ['label' => 'Completada', 'inputType' => 'boolean'],
], perPage: 10, perPageOptions: [10, 24, 50]);

$crud = new AppyCrud($connection, 'tareas', $config, 'es', [
    'deleteMode' => DeleteMode::CONFIRM,
    'filterableFields' => ['titulo', 'categoria_id', 'prioridad', 'completada'],
    'defaultOrderBy' => 'id',
    'defaultOrderDir' => 'DESC',
    // Por defecto la columna de acciones va a la derecha; con LEFT queda
    // primero (a la izquierda de los datos) -- util cuando la tabla tiene
    // muchas columnas y las acciones terminan fuera de la vista sin scrollear.
    'actionsPosition' => ActionsPosition::LEFT,
    // Consulta Packagist como mucho 1 vez cada 24h y muestra un aviso
    // descartable si hay una version mas nueva. Ahora mismo esta demo ya
    // esta en la ultima version publicada, asi que no veras el aviso real
    // aqui — mas abajo hay una vista simulada de como se ve cuando si hay.
    'checkForUpdates' => true,
]);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}

$sparklesIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" /></svg>';
$xIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="M18 6 6 18M6 6l12 12" /></svg>';

// Vista simulada: exactamente el mismo HTML/clases que genera
// TailwindRenderer::renderUpdateBanner() de verdad (no es una imagen ni un
// mockup aparte) — solo con datos de ejemplo, para que se vea sin tener que
// esperar a que salga una version nueva real.
$updatePreview = '<details class="demo-mariadb" style="margin-bottom:26px">'
    . '<summary>¿Cómo se ve el aviso cuando SÍ hay una actualización disponible?</summary>'
    . '<div class="inner">'
    . '<p>Esta demo ya corre la versión más reciente publicada (v' . AppyCrud::VERSION . '), así que <code>checkForUpdates</code> (activado arriba) no tiene nada que avisar ahora mismo. Esta es exactamente la misma pieza de HTML que se renderiza de verdad — con una versión de ejemplo, para que veas cómo se vería:</p>'
    . '<div class="flex items-center justify-between gap-3 mb-1 px-4 py-2.5 rounded-md border border-blue-200 bg-blue-50 text-sm text-blue-800">'
    . '<div class="flex items-center gap-2">' . $sparklesIcon . '<span>Hay una nueva versión de AppyCrud disponible: v9.9.9.</span></div>'
    . '<div class="flex items-center gap-3 flex-shrink-0">'
    . '<a href="https://github.com/appylogi/appycrud/releases" target="_blank" rel="noopener" class="font-medium underline hover:no-underline">Ver cambios</a>'
    . '<span class="text-blue-400" aria-label="Descartar">' . $xIcon . '</span>'
    . '</div></div>'
    . '</div></details>';

appycrud_demo_page(
    'Listado, filtros y paginación',
    'Filtro por columna, búsqueda global, orden por AJAX, paginación configurable y columna de acciones a la izquierda — todo en la tabla, sin recargar la página.',
    <<<'HTML'
<span class="k">$config</span> = <span class="k">new</span> TableConfig([...], perPage: <span class="s">10</span>, perPageOptions: [<span class="s">10, 24, 50</span>]);
<span class="k">$crud</span> = <span class="k">new</span> AppyCrud(<span class="k">$connection</span>, <span class="s">'tareas'</span>, <span class="k">$config</span>, <span class="s">'es'</span>, [
    <span class="s">'filterableFields'</span> =&gt; [<span class="s">'titulo', 'categoria_id', 'prioridad', 'completada'</span>],
    <span class="s">'actionsPosition'</span> =&gt; ActionsPosition::LEFT, <span class="com">// default: RIGHT</span>
]);
HTML,
    $updatePreview . $html,
    (int) $pdo->query('SELECT COUNT(*) FROM tareas')->fetchColumn()
);
