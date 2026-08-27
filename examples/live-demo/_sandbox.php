<?php

/**
 * Caja de arena por sesion de navegador: cada visitante tiene su propio
 * SQLite temporal por ejemplo, asi puede crear/editar/borrar sin afectar
 * a nadie mas. ?reset=1 borra y vuelve a sembrar datos de ese ejemplo.
 */

function appycrud_demo_sandbox(string $key, callable $seed): PDO
{
    $dir = sys_get_temp_dir() . '/appycrud-demo';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (empty($_SESSION['appycrud_demo_id'])) {
        $_SESSION['appycrud_demo_id'] = bin2hex(random_bytes(8));
    }

    $file = $dir . '/' . $_SESSION['appycrud_demo_id'] . '-' . $key . '.sqlite';

    $reset = isset($_GET['reset']);
    if ($reset && file_exists($file)) {
        unlink($file);
    }

    $isNew = !file_exists($file);
    $pdo = new PDO('sqlite:' . $file);

    if ($isNew) {
        $seed($pdo);
    }

    if ($reset) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    return $pdo;
}
