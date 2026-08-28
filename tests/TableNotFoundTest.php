<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;

/**
 * Fija v0.1.28: pedir una tabla que no existe en la conexion actual (comun
 * en apps multi-tenant, cada tenant con un subconjunto distinto de tablas)
 * no debe lanzar una excepcion sin manejar -- handle() debe devolver un
 * mensaje amigable.
 */
final class TableNotFoundTest extends TestCase
{
    public function test_tabla_inexistente_no_lanza_excepcion_y_da_mensaje_amigable(): void
    {
        $crud = new AppyCrud($this->connection, 'tabla_que_no_existe', null, 'es', ['csrf' => false]);

        $html = $crud->handle('/algo', [], []);

        $this->assertStringContainsString('no esta configurado', $html);
    }

    public function test_tabla_inexistente_responde_igual_para_cualquier_accion(): void
    {
        $crud = new AppyCrud($this->connection, 'tabla_que_no_existe', null, 'es', ['csrf' => false]);

        // Ni siquiera intentar 'store'/'delete' debe tronar -- el corte pasa
        // antes de tocar $this->schema/$this->repository, que nunca se armaron.
        $html = $crud->handle('/algo', ['action' => 'store'], ['x' => '1']);
        $this->assertStringContainsString('no esta configurado', $html);
    }
}
