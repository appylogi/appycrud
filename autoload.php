<?php

/**
 * Autoloader minimo para quien descargue AppyCrud como ZIP (sin Composer).
 * Quien use Composer no necesita este archivo: usa vendor/autoload.php.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Appylogi\\AppyCrud\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
