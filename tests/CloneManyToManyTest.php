<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Crud\ManyToMany;

/**
 * Fija v0.1.23: "Clonar" debe traer las relaciones many-to-many del
 * registro original preseleccionadas -- antes handleClone() llamaba
 * manyToManyFormData(null) sin las selecciones del registro fuente, y el
 * multiselect del clon quedaba siempre vacio.
 */
final class CloneManyToManyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE clientes (pkid INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT)');
        $this->exec('CREATE TABLE operadores (pkid INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT)');
        $this->exec('CREATE TABLE clientes_operadores (cliente INTEGER, operador INTEGER)');

        $this->exec("INSERT INTO clientes (pkid, nombre) VALUES (1, 'Cliente Original')");
        $this->exec("INSERT INTO operadores (pkid, nombre) VALUES (10, 'TCC'), (20, 'Coordinadora')");
        $this->exec('INSERT INTO clientes_operadores (cliente, operador) VALUES (1, 10), (1, 20)');
    }

    public function test_formulario_de_clonar_trae_preseleccionados_los_operadores_del_original(): void
    {
        $crud = new AppyCrud($this->connection, 'clientes', null, 'es', [
            'csrf' => false,
            'manyToMany' => [
                new ManyToMany(
                    name: 'operadores_por_cliente',
                    pivotTable: 'clientes_operadores',
                    localKey: 'cliente',
                    foreignKey: 'operador',
                    relatedTable: 'operadores',
                    relatedKey: 'pkid',
                    labelColumn: 'nombre',
                ),
            ],
        ]);

        $html = $crud->handle('/clientes', ['action' => 'clone', 'id' => '1'], []);

        // Ambas opciones deben aparecer marcadas como seleccionadas en el
        // formulario de clonacion (select multiple nativo: value="10" selected).
        $this->assertMatchesRegularExpression('/value="10"[^>]*selected/', $html, 'operador 10 (TCC) del cliente original debe venir preseleccionado al clonar');
        $this->assertMatchesRegularExpression('/value="20"[^>]*selected/', $html, 'operador 20 (Coordinadora) del cliente original debe venir preseleccionado al clonar');
    }
}
