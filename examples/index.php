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
    $pdo->exec('CREATE TABLE tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo TEXT NOT NULL,
        descripcion TEXT,
        completada INTEGER NOT NULL DEFAULT 0,
        creada_en TEXT DEFAULT CURRENT_TIMESTAMP
    )');
}

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creada_en' => ['hidden' => true, 'readOnly' => true],
    'completada' => ['label' => 'Completada', 'inputType' => 'checkbox'],
    'titulo' => ['label' => 'Titulo'],
    'descripcion' => ['label' => 'Descripcion', 'inputType' => 'textarea'],
]);

$locale = $_GET['lang'] ?? 'es';
$crud = new AppyCrud($connection, 'tareas', $config, $locale);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$html = $crud->handle($baseUrl, $_GET, $_POST);

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
