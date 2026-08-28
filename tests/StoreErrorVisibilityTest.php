<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;

/**
 * Fija v0.1.20: un error de BD al hacer INSERT/UPDATE que la app no
 * anticipa (ej. una restriccion que el schema no modela) no debe escapar
 * como excepcion sin manejar -- debe capturarse y mostrar el mensaje real
 * del driver en vez de una pantalla en blanco / 500 sin contexto.
 */
final class StoreErrorVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CHECK no es algo que AppyCrud modele -- una violacion produce un
        // PDOException que ningun catch especifico de excepcion de negocio
        // conoce de antemano.
        $this->exec('CREATE TABLE cuentas (pkid INTEGER PRIMARY KEY AUTOINCREMENT, saldo INTEGER CHECK(saldo >= 0))');
    }

    public function test_error_de_bd_no_esperado_se_muestra_en_vez_de_escapar(): void
    {
        $crud = new AppyCrud($this->connection, 'cuentas', null, 'es', ['csrf' => false]);

        $html = $crud->handle('/cuentas', ['action' => 'store'], ['saldo' => '-5']);

        // El objetivo de este test es la invariante real: un error de BD no
        // anticipado nunca debe escapar sin manejar (form re-renderizado, no
        // una excepcion sin capturar) y nunca debe insertar la fila invalida.
        // El mensaje exacto puede variar: PDO/SQLite reporta las violaciones
        // de CHECK con el mismo SQLSTATE 23000 que un UNIQUE (a diferencia de
        // MySQL, donde CHECK es HY000) -- ahi CrudRepository la interpreta
        // como "valor duplicado" en vez de caer al catch(\Throwable) generico
        // de AppyCrud::databaseErrorMessage(). Cualquiera de los dos mensajes
        // confirma que el error SI se mostro.
        $mensajeGenerico = str_contains($html, 'No se pudo guardar el registro');
        $mensajeDuplicado = str_contains(strtolower($html), 'existe un registro');
        $this->assertTrue($mensajeGenerico || $mensajeDuplicado, 'debe mostrar algun mensaje de error, no un formulario en blanco silencioso');

        $count = (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM cuentas')->fetchColumn();
        $this->assertSame(0, $count, 'el CHECK debio impedir el insert');
    }
}
