<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/** Fija v0.1.24: TableConfig(columnOrder) reordena columnas ignorando el orden fisico de la tabla. */
final class ColumnOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Orden fisico intencionalmente "incorrecto": tarifa antes que cliente
        // (el mismo patron que causo el bug real en Acuerdosconsolidados).
        $this->exec('CREATE TABLE acuerdos (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            tarifa TEXT,
            cliente TEXT,
            fecha_inicial TEXT
        )');
    }

    public function test_sin_columnorder_respeta_el_orden_fisico_de_la_tabla(): void
    {
        $crud = new AppyCrud($this->connection, 'acuerdos', null, 'es', ['csrf' => false]);
        $html = $crud->handle('/acuerdos', [], []);

        $posTarifa = strpos($html, 'Tarifa');
        $posCliente = strpos($html, 'Cliente');
        $this->assertNotFalse($posTarifa);
        $this->assertNotFalse($posCliente);
        $this->assertLessThan($posCliente, $posTarifa, 'sin columnOrder, tarifa (columna fisica #2) debe salir antes que cliente (#3)');
    }

    public function test_columnorder_fuerza_cliente_antes_que_tarifa(): void
    {
        $config = new TableConfig(columnOrder: ['cliente', 'tarifa', 'fecha_inicial']);
        $crud = new AppyCrud($this->connection, 'acuerdos', $config, 'es', ['csrf' => false]);
        $html = $crud->handle('/acuerdos', [], []);

        $posTarifa = strpos($html, 'Tarifa');
        $posCliente = strpos($html, 'Cliente');
        $this->assertLessThan($posTarifa, $posCliente, 'con columnOrder, cliente debe salir antes que tarifa, invirtiendo el orden fisico');
    }

    public function test_columna_no_listada_en_columnorder_conserva_su_posicion_relativa_al_final(): void
    {
        // Solo se menciona 'cliente' -- 'tarifa' y 'fecha_inicial' deben seguir
        // apareciendo, en su orden relativo original, despues de 'cliente'.
        $config = new TableConfig(columnOrder: ['cliente']);
        $crud = new AppyCrud($this->connection, 'acuerdos', $config, 'es', ['csrf' => false]);
        $html = $crud->handle('/acuerdos', [], []);

        $posCliente = strpos($html, 'Cliente');
        $posTarifa = strpos($html, 'Tarifa');
        $posFecha = strpos($html, 'Fecha Inicial');
        $this->assertLessThan($posTarifa, $posCliente);
        $this->assertLessThan($posFecha, $posTarifa, 'tarifa y fecha_inicial conservan su orden relativo original entre si');
    }
}
