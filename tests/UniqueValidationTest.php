<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/**
 * Fija el caso 'nit' de Clientes: una columna sin UNIQUE real en la BD puede
 * forzarse a validar unicidad a nivel de app via TableConfig
 * columnOverrides['col']['unique'] = true.
 */
final class UniqueValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Sin ninguna restriccion UNIQUE real -- simula el indice "unica" de
        // tblclientes.nit, que es un indice normal, no un constraint.
        $this->exec('CREATE TABLE clientes (pkid INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, nit TEXT)');
        $this->exec("INSERT INTO clientes (nombre, nit) VALUES ('Existente', '900123456')");
    }

    public function test_sin_forzar_unique_permite_duplicados(): void
    {
        $crud = new AppyCrud($this->connection, 'clientes', null, 'es', ['csrf' => false]);
        $crud->handle('/clientes', ['action' => 'store'], ['nombre' => 'Duplicado', 'nit' => '900123456']);

        $count = (int) $this->connection->pdo()->query("SELECT COUNT(*) FROM clientes WHERE nit = '900123456'")->fetchColumn();
        $this->assertSame(2, $count, 'sin unique forzado, un nit repetido se inserta igual (comportamiento base, sin constraint real en BD)');
    }

    public function test_unique_forzado_bloquea_un_nit_duplicado(): void
    {
        $config = new TableConfig(columnOverrides: ['nit' => ['unique' => true]]);
        $crud = new AppyCrud($this->connection, 'clientes', $config, 'es', ['csrf' => false]);

        $html = $crud->handle('/clientes', ['action' => 'store'], ['nombre' => 'Duplicado', 'nit' => '900123456']);

        $count = (int) $this->connection->pdo()->query("SELECT COUNT(*) FROM clientes WHERE nit = '900123456'")->fetchColumn();
        $this->assertSame(1, $count, "con 'unique'=>true, un nit repetido NO debe insertarse");
        $this->assertStringContainsString('existe', strtolower($html), 'debe mostrar un mensaje de duplicado');
    }

    public function test_unique_forzado_no_bloquea_editar_el_mismo_registro_con_su_propio_nit(): void
    {
        $config = new TableConfig(columnOverrides: ['nit' => ['unique' => true]]);
        $crud = new AppyCrud($this->connection, 'clientes', $config, 'es', ['csrf' => false]);

        // Editar el pkid=1 reenviando su MISMO nit no debe fallar por "duplicado".
        $crud->handle('/clientes', ['action' => 'update', 'id' => '1'], ['nombre' => 'Existente Editado', 'nit' => '900123456']);

        $row = $this->connection->pdo()->query('SELECT nombre FROM clientes WHERE pkid = 1')->fetch();
        $this->assertSame('Existente Editado', $row['nombre'], 'el update debio aplicarse -- no debe autobloquearse contra su propio nit');
    }
}
