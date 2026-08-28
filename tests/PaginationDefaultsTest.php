<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;

/** Fija v0.1.26/0.1.27: default de 50 por pagina, opciones hasta 500, y el total de registros visible. */
final class PaginationDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE items (pkid INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT)');
        for ($i = 1; $i <= 3; $i++) {
            $this->exec("INSERT INTO items (nombre) VALUES ('Item $i')");
        }
    }

    public function test_default_es_50_por_pagina_y_ofrece_hasta_500(): void
    {
        $crud = new AppyCrud($this->connection, 'items', null, 'es', ['csrf' => false]);
        $html = $crud->handle('/items', [], []);

        $this->assertMatchesRegularExpression('/<option value="50"[^>]*selected/', $html, 'el default seleccionado debe ser 50');
        $this->assertStringContainsString('value="200"', $html);
        $this->assertStringContainsString('value="500"', $html);
    }

    public function test_muestra_el_total_de_registros(): void
    {
        $crud = new AppyCrud($this->connection, 'items', null, 'es', ['csrf' => false]);
        $html = $crud->handle('/items', [], []);

        $this->assertStringContainsString('3', $html);
        $this->assertMatchesRegularExpression('/\(3\s*registros\)/', $html, 'el total real (3) debe aparecer junto a "registros"');
    }

    public function test_tabla_propia_puede_sobreescribir_el_default(): void
    {
        $config = new \Appylogi\AppyCrud\Schema\TableConfig(perPage: 10, perPageOptions: [10, 25]);
        $crud = new AppyCrud($this->connection, 'items', $config, 'es', ['csrf' => false]);
        $html = $crud->handle('/items', [], []);

        $this->assertMatchesRegularExpression('/<option value="10"[^>]*selected/', $html);
        $this->assertStringNotContainsString('value="500"', $html, 'una tabla con perPageOptions propio no debe heredar el default global');
    }
}
