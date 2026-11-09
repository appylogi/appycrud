<?php
session_start();
$libraryRoot = is_file(__DIR__ . '/appycrud/autoload.php') ? __DIR__ . '/appycrud' : dirname(__DIR__, 2);
require $libraryRoot . '/autoload.php';
require __DIR__ . '/_sandbox.php';
require __DIR__ . '/_layout.php';
use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;
use Appylogi\AppyCrud\Crud\ActionsPosition;
$pdo = appycrud_demo_sandbox('rendimiento', function (PDO $pdo) {
    $pdo->exec('CREATE TABLE categorias (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
    $pdo->exec("INSERT INTO categorias VALUES (1,'Operación'),(2,'Comercial'),(3,'Administración')");
    $pdo->exec('CREATE TABLE registros (id INTEGER PRIMARY KEY AUTOINCREMENT, titulo TEXT NOT NULL, categoria_id INTEGER REFERENCES categorias(id), activo INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE INDEX idx_registros_categoria ON registros(categoria_id,id)');
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('INSERT INTO registros(titulo,categoria_id,activo) VALUES(?,?,?)');
    for($i=1;$i<=600;$i++)$stmt->execute(['Registro de ejemplo '.str_pad((string)$i,3,'0',STR_PAD_LEFT),($i%3)+1,$i%5===0?0:1]);
    $pdo->commit();
});
$config = new TableConfig(columnOverrides: [
    'id' => ['label'=>'ID'],
    'titulo' => ['label'=>'Título','rules'=>['required','max:150']],
    'categoria_id' => ['label'=>'Categoría','reference'=>['table'=>'categorias','column'=>'id','label'=>'nombre']],
    'activo' => ['label'=>'Activo','inputType'=>'boolean'],
], perPage:25, perPageOptions:[10,25,50,100]);
$options = [
    'paginationMode'=>'simple',
    'defaultOrderBy'=>'id',
    'defaultOrderDir'=>'DESC',
    'sortableFields'=>['id','categoria_id'],
    'referenceOrderByValue'=>true,
    'autoFilter'=>false,
    'search'=>false,
    'exportMaxRows'=>100,
    'actionsPosition'=>ActionsPosition::LEFT,
];
$crud = new AppyCrud(Connection::fromPdo($pdo),'registros',$config,'es',$options);
$baseUrl=strtok($_SERVER['REQUEST_URI'],'?');
$isAjax=isset($_GET['ajax'])||($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest';
$html=$crud->handle($baseUrl,$_GET,$_POST,$isAjax);
if($isAjax){echo $html;exit;}
$snippet=htmlspecialchars("new AppyCrud(\$connection, 'registros', \$config, 'es', ".var_export($options,true).");",ENT_QUOTES,'UTF-8');
$notice='<p class="mb-4 text-sm text-gray-600">Esta caja de arena se inicia con 600 registros de ejemplo por sesión. Pulsa Filtrar para aplicar criterios. El listado no calcula el total; Siguiente y Anterior usan cursores al ordenar por ID. Las categorías se ordenan por código y conservan su nombre. Para exportar, filtra hasta 100 registros: por ejemplo Título contiene 59. El resto de ejemplos mantiene la paginación habitual.</p>';
appycrud_demo_page('Paginación rápida y filtros controlados','Modo opcional para tablas grandes: sin COUNT en el listado, navegación por cursor, filtros manuales y exportaciones acotadas.',$snippet,$notice.$html);
