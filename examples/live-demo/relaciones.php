<?php

session_start();
require __DIR__ . '/../../autoload.php'; // autoload sin composer (ver README.md de este directorio)
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Crud\Condition;
use Appylogi\AppyCrud\Crud\ManyToMany;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$pdo = appycrud_demo_sandbox('relaciones', function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE categorias (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, activa INTEGER NOT NULL DEFAULT 1)');
    // mas de 8 categorias activas: pasa el umbral que auto-promueve el select
    // de "categoria" a buscable via AJAX (en vez de un <select> comun).
    $pdo->exec("INSERT INTO categorias (nombre, activa) VALUES
        ('Web', 1), ('Movil', 1), ('Backend', 1), ('Infraestructura', 1),
        ('Diseño', 1), ('Datos', 1), ('QA', 1), ('Soporte', 1), ('Seguridad', 1),
        ('Descontinuada', 0)");

    $pdo->exec('CREATE TABLE colaboradores (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL)');
    $pdo->exec("INSERT INTO colaboradores (nombre) VALUES ('Ana'), ('Beto'), ('Caro'), ('Dario')");

    $pdo->exec('CREATE TABLE proyectos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre VARCHAR(150) NOT NULL,
        categoria_id INTEGER REFERENCES categorias(id),
        presupuesto REAL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE proyectos_colaboradores (proyecto_id INTEGER NOT NULL, colaborador_id INTEGER NOT NULL)');

    $insert = $pdo->prepare('INSERT INTO proyectos (nombre, categoria_id, presupuesto) VALUES (:nombre, :categoria_id, :presupuesto)');
    for ($i = 1; $i <= 10; $i++) {
        $insert->execute([
            ':nombre' => 'Proyecto ' . $i,
            ':categoria_id' => ($i % 2) + 1,
            ':presupuesto' => $i * 1250,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO proyectos_colaboradores (proyecto_id, colaborador_id) VALUES ({$id}, " . (($i % 4) + 1) . ')');
    }
});

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'nombre' => ['label' => 'Nombre', 'rules' => ['required']],
    'categoria_id' => ['label' => 'Categoria', 'reference' => [
        'table' => 'categorias', 'column' => 'id', 'label' => 'nombre',
        'conditions' => [Condition::where('activa', '=', 1)],
    ]],
    'presupuesto' => ['label' => 'Presupuesto', 'inputType' => 'float'],
]);

$crud = new AppyCrud($connection, 'proyectos', $config, 'es', [
    'manyToMany' => [
        new ManyToMany(
            name: 'colaboradores',
            pivotTable: 'proyectos_colaboradores',
            localKey: 'proyecto_id',
            foreignKey: 'colaborador_id',
            relatedTable: 'colaboradores',
        ),
    ],
]);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax);

if ($isAjax) {
    echo $html;
    exit;
}

appycrud_demo_page(
    'Relaciones: FK y muchos a muchos',
    'La llave foránea "categoría" se detecta sola, solo muestra categorías activas, y como hay más de 8 opciones el select se auto-promueve a uno buscable (con búsqueda en el propio servidor, no precargado). "Colaboradores" es una relación muchos-a-muchos real vía tabla pivote, sincronizada automáticamente al guardar.',
    <<<'HTML'
<span class="k">$crud</span> = <span class="k">new</span> AppyCrud(<span class="k">$connection</span>, <span class="s">'proyectos'</span>, <span class="k">$config</span>, <span class="s">'es'</span>, [
    <span class="s">'manyToMany'</span> =&gt; [<span class="k">new</span> ManyToMany(
        name: <span class="s">'colaboradores'</span>, pivotTable: <span class="s">'proyectos_colaboradores'</span>,
        localKey: <span class="s">'proyecto_id'</span>, foreignKey: <span class="s">'colaborador_id'</span>, relatedTable: <span class="s">'colaboradores'</span>,
    )],
]);
// 'categoria_id' filtra sus opciones con Condition::where('activa', '=', 1)
// y se vuelve buscable solo porque tiene mas de 8 opciones -- automatico,
// sin ninguna opcion extra que activarlo.
HTML,
    $html,
    (int) $pdo->query('SELECT COUNT(*) FROM proyectos')->fetchColumn()
);
