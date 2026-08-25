<?php

/**
 * Ejemplo minimo: crea una tabla "tareas" en SQLite (archivo local) y
 * expone su CRUD completo con AppyCrud, sin ningun framework de por medio.
 *
 * Correrlo con: php -S localhost:8000 -t examples
 * y abrir http://localhost:8000/
 */

session_start(); // AppyCrud usa la sesion para el token CSRF (opciones['csrf'], default true)

require __DIR__ . '/../vendor/autoload.php';

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Crud\Condition;
use Appylogi\AppyCrud\Crud\HookAbortException;
use Appylogi\AppyCrud\Crud\ManyToMany;
use Appylogi\AppyCrud\Crud\RowAction;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Schema\TableConfig;

$dbFile = __DIR__ . '/demo.sqlite';
$isNew = !file_exists($dbFile);

$pdo = new \PDO('sqlite:' . $dbFile);

if ($isNew) {
    // 'activa' demuestra 'conditions' en el override de 'reference' mas abajo:
    // 'Urgente' queda inactiva y no aparece en el select de categorias.
    $pdo->exec('CREATE TABLE categorias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        activa INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec("INSERT INTO categorias (nombre, activa) VALUES ('Trabajo', 1), ('Personal', 1), ('Urgente', 0)");

    // Tabla + pivote para la relacion muchos-a-muchos (ver 'manyToMany' mas abajo).
    // Distinto de 'etiquetas' (columna CSV, multiselect_native de toda la vida):
    // aqui cada colaborador es una fila real en su propia tabla.
    $pdo->exec('CREATE TABLE colaboradores (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL
    )');
    $pdo->exec("INSERT INTO colaboradores (nombre) VALUES ('Ana'), ('Beto'), ('Caro')");
    $pdo->exec('CREATE TABLE tareas_colaboradores (
        tarea_id INTEGER NOT NULL,
        colaborador_id INTEGER NOT NULL
    )');

    // 'titulo' es VARCHAR (campo corto); 'descripcion' es TEXT (la libreria
    // detecta TEXT/LONGTEXT como contenido largo y lo renderiza como textarea).
    // 'archivada' demuestra 'where'/'insertDefaults' mas abajo: nunca se ve en
    // el form (hidden) ni en el listado (scoping), pero existe en la tabla.
    // 'adjunto' demuestra inputType 'file' (ver 'uploadDir' mas abajo).
    $pdo->exec('CREATE TABLE tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo VARCHAR(150) NOT NULL,
        descripcion TEXT,
        categoria_id INTEGER REFERENCES categorias(id),
        prioridad VARCHAR(10) DEFAULT "media",
        etiquetas VARCHAR(100),
        adjunto VARCHAR(255),
        notas TEXT,
        completada INTEGER NOT NULL DEFAULT 0,
        archivada INTEGER NOT NULL DEFAULT 0,
        creada_en TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    // Se cargan 20+ registros variados por defecto (categorias/prioridades/
    // etiquetas/completada mezclados) para poder probar filtro simple, filtro
    // avanzado (AND/OR) y busqueda con datos reales, no con 1-2 filas sueltas.
    $categoriaIds = [1, 2, 1, 2, 1]; // Trabajo, Personal (3 = Urgente, inactiva, no se usa aqui)
    $prioridades = ['baja', 'media', 'alta'];
    $etiquetasPosibles = ['casa', 'oficina', 'viaje'];
    $insertTarea = $pdo->prepare(
        'INSERT INTO tareas (titulo, descripcion, categoria_id, prioridad, etiquetas, completada, notas)
         VALUES (:titulo, :descripcion, :categoria_id, :prioridad, :etiquetas, :completada, :notas)'
    );

    for ($i = 1; $i <= 24; $i++) {
        $insertTarea->execute([
            ':titulo' => 'Tarea de ejemplo ' . $i,
            ':descripcion' => 'Descripcion generada automaticamente para la tarea numero ' . $i . '.',
            ':categoria_id' => $categoriaIds[$i % count($categoriaIds)],
            ':prioridad' => $prioridades[$i % count($prioridades)],
            ':etiquetas' => $etiquetasPosibles[$i % count($etiquetasPosibles)],
            ':completada' => $i % 3 === 0 ? 1 : 0,
            ':notas' => $i % 4 === 0 ? '<p>Nota <b>importante</b> para la tarea ' . $i . '.</p>' : null,
        ]);

        // Asigna 1-2 colaboradores por tarea (rotando), para poder probar el
        // multiselect de muchos-a-muchos tambien con datos de ejemplo reales.
        $tareaId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO tareas_colaboradores (tarea_id, colaborador_id) VALUES ({$tareaId}, " . (($i % 3) + 1) . ')');
    }
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$connection = Connection::fromPdo($pdo);

$config = new TableConfig([
    'id' => ['hidden' => true],
    'creada_en' => ['hidden' => true, 'readOnly' => true],
    'archivada' => ['hidden' => true],
    'completada' => ['label' => 'Completada', 'inputType' => 'boolean'],
    // 'rules' usa el mismo mecanismo de override que cualquier otra propiedad de Column.
    'titulo' => ['label' => 'Titulo', 'rules' => ['required', 'max:100']],
    'descripcion' => ['label' => 'Descripcion', 'inputType' => 'text'],
    // 'conditions' filtra las opciones del select: solo categorias con activa=1.
    'categoria_id' => ['label' => 'Categoria', 'reference' => [
        'table' => 'categorias', 'column' => 'id', 'label' => 'nombre',
        'conditions' => [Condition::where('activa', '=', 1)],
    ]],
    // 'dropdown' con opciones estaticas (no viene de otra tabla, a diferencia de categoria_id).
    'prioridad' => ['label' => 'Prioridad', 'inputType' => 'dropdown', 'options' => [
        ['value' => 'baja', 'label' => 'Baja'],
        ['value' => 'media', 'label' => 'Media'],
        ['value' => 'alta', 'label' => 'Alta'],
    ]],
    // 'multiselect_searchable' se guarda como CSV en una sola columna (ver docs/uso.md),
    // igual que 'multiselect_native' — la diferencia es solo el widget: aqui es un
    // combobox tipo "select2" (buscar + chips removibles), en vez del <select multiple> nativo.
    'etiquetas' => ['label' => 'Etiquetas', 'inputType' => 'multiselect_searchable', 'options' => [
        ['value' => 'casa', 'label' => 'Casa'],
        ['value' => 'oficina', 'label' => 'Oficina'],
        ['value' => 'viaje', 'label' => 'Viaje'],
    ]],
    'adjunto' => ['label' => 'Adjunto', 'inputType' => 'file'],
    // 'richtext_advanced': editor vanilla con barra extendida (encabezados, enlaces,
    // alineacion, deshacer/rehacer) — 'richtext' (sin '_advanced') da la barra minima
    // (negrita/italica/subrayado/listas). Ambos se sanitizan igual al guardar
    // (Crud\HtmlSanitizer: whitelist de etiquetas, sin scripts/atributos peligrosos).
    'notas' => ['label' => 'Notas', 'inputType' => 'richtext_advanced'],
], perPage: 10, perPageOptions: [10, 24, 50]);
// ^ perPage/perPageOptions son propios de ESTA tabla ('tareas'): otra tabla de la
// misma app puede pasar valores distintos en su propio TableConfig. Si no se
// configuran aqui, se usa la opcion 'perPage'/'perPageOptions' del array de
// opciones de AppyCrud (mas abajo); si tampoco esa se define, el default final
// es 20 / [10, 20, 50, 100]. Ver docs/uso.md#paginación-cuántos-registros-mostrar.

$locale = $_GET['lang'] ?? 'es';

// deleteMode acepta: DeleteMode::CONFIRM (default), DeleteMode::DIRECT o DeleteMode::SOFT
// (SOFT requiere 'softDeleteColumn' con el nombre de una columna existente en la tabla).
// export/bulkDelete/filters/view/print/clone son booleanos, todos true por default;
// se muestran aqui solo para dejar explicito que se pueden desactivar individualmente.
//
// insertFields/editFields (no activados aqui para no ocultar campos de la demo):
//   'insertFields' => ['titulo', 'categoria_id'],  // solo estos apareceran al crear
//   'editFields'   => ['titulo', 'completada'],    // solo estos al editar
$options = [
    'deleteMode' => \Appylogi\AppyCrud\Crud\DeleteMode::CONFIRM,
    // Consulta Packagist como mucho 1 vez cada 24h (con cache en disco) y
    // muestra un aviso descartable si hay una version mas nueva publicada.
    // Apagado por defecto en AppyCrud; se activa aqui solo para que se vea
    // en el ejemplo. No envia ningun dato del proyecto.
    'checkForUpdates' => true,
    'export' => true,
    'bulkDelete' => true,
    'filters' => true,
    // Limita las columnas con filtro simple + las disponibles en el constructor
    // avanzado (util en tablas anchas). Sin esta opcion, se muestran todas las visibles.
    'filterableFields' => ['titulo', 'categoria_id', 'prioridad', 'completada'],
    'view' => true,
    'print' => true,
    'clone' => true,
    'cloneSuffixColumn' => 'titulo',
    'cloneSuffix' => ' (copia)',
    'defaultOrderBy' => 'id',
    'defaultOrderDir' => 'DESC',
    // 'where': condicion base SIEMPRE aplicada (listado, exportar, ver, editar, eliminar).
    // Aqui excluye las tareas archivadas de todo, no solo del listado.
    'where' => [
        Condition::where('archivada', '=', 0),
    ],
    // 'insertDefaults': fuerza este valor en cada insert, ignorando lo que mande el cliente.
    'insertDefaults' => [
        'archivada' => 0,
    ],
    // Relacion muchos-a-muchos real (tabla + pivote), a diferencia de 'etiquetas' (CSV).
    'manyToMany' => [
        new ManyToMany(
            name: 'colaboradores',
            pivotTable: 'tareas_colaboradores',
            localKey: 'tarea_id',
            foreignKey: 'colaborador_id',
            relatedTable: 'colaboradores',
        ),
    ],
    // Se ejecutan antes/despues de insert/update/delete. Lanzar HookAbortException
    // cancela la operacion y muestra el mensaje (insert/update: en el mismo modal;
    // delete: simplemente no borra).
    'hooks' => [
        'beforeDelete' => function (mixed $id) use ($pdo) {
            $stmt = $pdo->prepare('SELECT prioridad FROM tareas WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row !== false && $row['prioridad'] === 'alta') {
                throw new HookAbortException('No se pueden eliminar tareas de prioridad alta.');
            }
        },
    ],
    // Carpeta donde se guardan los archivos subidos (columna 'adjunto', inputType 'file').
    // Obligatorio en cuanto una columna use ese tipo. 'uploadUrlPrefix' es opcional:
    // si se indica, el listado/vista muestran un link de descarga en vez de solo el nombre.
    'uploadDir' => $uploadDir,
    'uploadUrlPrefix' => '/appycrud/examples/uploads',
    // Al editar, se puede marcar "Quitar archivo actual" para borrarlo sin eliminar
    // el registro completo. 'deleteFilesOnDelete' (true por default) borra ademas el
    // archivo fisico del disco cuando se elimina el registro entero; ponlo en false
    // si prefieres conservar los archivos aunque se borre la fila.
    'deleteFilesOnDelete' => true,
    // Accion custom agregada al menu de cada fila (ver Crud\RowAction).
    'rowActions' => [
        new RowAction(
            name: 'duplicar_sin_adjunto',
            label: 'Duplicar sin adjunto',
            handler: function (mixed $id, array $get, array $post) use ($pdo) {
                $stmt = $pdo->prepare('SELECT titulo, descripcion, categoria_id, prioridad FROM tareas WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row !== false) {
                    $insert = $pdo->prepare('INSERT INTO tareas (titulo, descripcion, categoria_id, prioridad) VALUES (:titulo, :descripcion, :categoria_id, :prioridad)');
                    $insert->execute($row);
                }

                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            },
            icon: 'copy',
            confirm: 'Duplicar esta tarea sin su adjunto ni colaboradores?',
            method: 'post',
        ),
    ],
];

$crud = new AppyCrud($connection, 'tareas', $config, $locale, $options);

$baseUrl = strtok($_SERVER['REQUEST_URI'], '?');
$isAjax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$html = $crud->handle($baseUrl, $_GET, $_POST, $isAjax, $_FILES);

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
