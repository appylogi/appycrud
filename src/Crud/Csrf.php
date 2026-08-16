<?php

namespace Appylogi\AppyCrud\Crud;

use RuntimeException;

/**
 * Token CSRF minimo basado en $_SESSION. Requiere que la aplicacion
 * anfitriona haya llamado session_start() antes de usar AppyCrud (no lo
 * hace por su cuenta: es agnostico de framework y no asume como se maneja
 * la sesion).
 */
class Csrf
{
    public static function token(string $sessionKey): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException(
                "AppyCrud: la proteccion CSRF requiere una sesion activa. " .
                "Llama a session_start() antes de usar AppyCrud, o desactivala con la opcion 'csrf' => false."
            );
        }

        if (empty($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$sessionKey];
    }

    public static function verify(string $sessionKey, ?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $token === null || $token === '') {
            return false;
        }

        $expected = $_SESSION[$sessionKey] ?? null;

        return $expected !== null && hash_equals($expected, $token);
    }
}
