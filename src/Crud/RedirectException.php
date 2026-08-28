<?php

namespace Appylogi\AppyCrud\Crud;

use RuntimeException;

/**
 * Señal interna de "hay que redirigir a esta URL" -- AppyCrud::redirect()
 * la lanza en vez de llamar header()+exit() directamente, y
 * AppyCrud::handle() (el unico punto de entrada publico) es quien
 * realmente hace el header()+exit(), atrapandola justo antes de devolver el
 * control al caller.
 *
 * Por que no header()+exit() directo: un exit() en medio de una libreria
 * termina el proceso PHP completo -- imposible de probar en un test (el
 * test runner mismo muere), e imposible de envolver desde afuera (un
 * framework moderno que quiera loguear/inspeccionar la respuesta antes de
 * enviarla nunca recupera el control). Con la excepcion, el comportamiento
 * en produccion es identico (handle() sigue terminando en un header()+exit()
 * real), pero un test puede atraparla y verificar la URL sin que el
 * proceso muera.
 */
class RedirectException extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("AppyCrud: redirect a '{$url}'.");
    }
}
