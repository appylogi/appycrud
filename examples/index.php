<?php

/**
 * Ejemplo minimo: crea una tabla "tareas" en SQLite (archivo local) y
 * expone su CRUD completo con AppyCrud, sin ningun framework de por medio.
 *
 * Correrlo con: php -S localhost:8000 -t examples
 * y abrir http://localhost:8000/
 */

require __DIR__ . '/../vendor/autoload.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$dbFile = __DIR__ . '/demo.sqlite';
$isNew = !file_exists($dbFile);

$pdo = new \PDO('sqlite:' . $dbFile);

if ($isNew) {
    $pdo->exec('CREATE TABLE categorias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL
    )');
    $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Trabajo'), ('Personal'), ('Urgente')");

    // 'titulo' es VARCHAR (campo corto); 'descripcion' es TEXT (la libreria
    // detecta TEXT/LONGTEXT como contenido largo y lo renderiza como textarea).
    $pdo->exec('CREATE TABLE tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo VARCHAR(150) NOT NULL,
        descripcion TEXT,
        categoria_id INTEGER REFERENCES categorias(id),
        completada INTEGER NOT NULL DEFAULT 0,
        creada_en TEXT DEFAULT CURRENT_TIMESTAMP
    )');
}

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creada_en' => ['hidden' => true, 'readOnly' => true],
    'completada' => ['label' => 'Completada', 'inputType' => 'checkbox'],
    // 'rules' usa el mismo mecanismo de override que cualquier otra propiedad de Column.
    'titulo' => ['label' => 'Titulo', 'rules' => ['required', 'max:100']],
    'descripcion' => ['label' => 'Descripcion', 'inputType' => 'textarea'],
    // 'categoria_id' ya se detecta como FK real hacia categorias(id); solo se ajusta el label.
    'categoria_id' => ['label' => 'Categoria'],
]);

$locale = $_GET['lang'] ?? 'es';

// deleteMode acepta: DeleteMode::CONFIRM (default), DeleteMode::DIRECT o DeleteMode::SOFT
// (SOFT requiere 'softDeleteColumn' con el nombre de una columna existente en la tabla).
// export/bulkDelete/filters/view/print/clone son booleanos, todos true por default;
// se muestran aqui solo para dejar explicito que se pueden desactivar individualmente.
$options = [
    'deleteMode' => \Appylogi\AppyCrud\Crud\DeleteMode::CONFIRM,
    'export' => true,
    'bulkDelete' => true,
    'filters' => true,
    'view' => true,
    'print' => true,
    'clone' => true,
    'cloneSuffixColumn' => 'titulo',
    'cloneSuffix' => ' (copia)',
];

$crud = new AppyCrud($connection, 'tareas', $config, $locale, $options);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$html = $crud->handle($baseUrl, $_GET, $_POST);

$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($isAjax) {
    echo $html;
    exit;
}

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
    <meta charset="UTF-8">
    <title>AppyCrud - Demo</title>
    <link rel="stylesheet" href="../assets/css/appycrud.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="bg-white border-b border-gray-200 px-6 py-3 flex justify-between items-center">
        <span class="font-semibold text-gray-700">AppyCrud demo</span>
        <div class="text-sm space-x-3">
            <a href="?lang=es" class="text-blue-600 hover:underline">ES</a>
            <a href="?lang=en" class="text-blue-600 hover:underline">EN</a>
        </div>
    </div>
    <?= $html ?>
</body>
</html>
