<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/**
 * Fija el bug reportado en Trayectos (appylogi): el filtro por columna de una
 * FK (ciudad_origen/ciudad_destino) era un <select> con solo las primeras 500
 * opciones (referenceOptions(), orden alfabetico) -- en un catalogo grande
 * (tblciudades, ~9300 filas reales) la mayoria de las ciudades nunca
 * aparecian en el desplegable, sin importar cuantas filas por pagina se
 * pidieran mostrar (eso no tiene relacion con el tope real). Ahora usa el
 * mismo combobox buscable via AJAX (busca en la BD) que ya existia para el
 * formulario de creacion/edicion.
 */
final class ReferenceFilterAjaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE ciudades (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT,
            activo INTEGER NOT NULL DEFAULT 1
        )');
        $this->exec('CREATE TABLE trayectos (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            ciudad_origen INTEGER,
            dias_entregas INTEGER
        )');
        $this->exec("INSERT INTO ciudades (pkid, nombre, activo) VALUES (1, 'Bogota', 1)");
        $this->exec('INSERT INTO trayectos (ciudad_origen, dias_entregas) VALUES (1, 3)');
    }

    private function crud(): AppyCrud
    {
        $config = new TableConfig(columnOverrides: [
            'ciudad_origen' => ['label' => 'Ciudad Origen', 'reference' => [
                'table' => 'ciudades', 'column' => 'pkid', 'label' => 'nombre',
            ]],
        ]);

        return new AppyCrud($this->connection, 'trayectos', $config, 'es', ['csrf' => false]);
    }

    public function test_el_filtro_de_una_columna_de_referencia_usa_el_combobox_buscable_via_ajax(): void
    {
        $html = $this->crud()->handle('/trayectos', [], []);

        $this->assertStringContainsString('appycrud-ref-search', $html, 'el filtro de ciudad_origen debe usar el combobox buscable, no un <select> con tope fijo');
        $this->assertStringContainsString('action=reference_search&amp;column=ciudad_origen', $html);
    }

    public function test_el_valor_activo_del_filtro_resuelve_su_label_en_el_combobox(): void
    {
        $html = $this->crud()->handle('/trayectos', ['filter' => ['ciudad_origen' => '1']], []);

        // El input visible debe mostrar el nombre, no el id crudo.
        $this->assertMatchesRegularExpression('/appycrud-ref-search-input[^>]*value="Bogota"/', $html);
        $this->assertStringContainsString('name="filter[ciudad_origen]" value="1"', $html);
    }
}
