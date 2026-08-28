<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/**
 * Fija el bug real reportado en Trayectos (appylogi): el buscador global (?q=)
 * ignoraba por completo las columnas de referencia (FK) -- si todas las
 * columnas visibles de una tabla son relaciones (ciudad_origen/destino,
 * tipo_trayecto...), la busqueda nunca encontraba nada porque el valor
 * guardado es el id numerico, no el texto que el usuario escribe.
 */
final class ReferenceSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE ciudades (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT,
            departamento TEXT,
            activo INTEGER NOT NULL DEFAULT 1
        )');
        $this->exec("INSERT INTO ciudades (pkid, nombre, departamento, activo) VALUES
            (1, 'Bogota', 'Cundinamarca', 1),
            (2, 'Pasto', 'Narino', 1),
            (3, 'Medellin', 'Antioquia', 1)");

        $this->exec('CREATE TABLE trayectos (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            ciudad_origen INTEGER,
            ciudad_destino INTEGER,
            dias_entregas INTEGER
        )');
        $this->exec('INSERT INTO trayectos (ciudad_origen, ciudad_destino, dias_entregas) VALUES (1, 2, 3)');
        $this->exec('INSERT INTO trayectos (ciudad_origen, ciudad_destino, dias_entregas) VALUES (2, 3, 2)');
    }

    private function crud(): AppyCrud
    {
        $config = new TableConfig(columnOverrides: [
            'ciudad_origen' => ['label' => 'Ciudad Origen', 'reference' => [
                'table' => 'ciudades', 'column' => 'pkid', 'label' => 'nombre',
            ]],
            'ciudad_destino' => ['label' => 'Ciudad Destino', 'reference' => [
                'table' => 'ciudades', 'column' => 'pkid', 'label' => 'nombre',
            ]],
        ]);

        return new AppyCrud($this->connection, 'trayectos', $config, 'es', ['csrf' => false]);
    }

    public function test_busca_por_ciudad_origen_via_columna_de_referencia(): void
    {
        $html = $this->crud()->handle('/trayectos', ['q' => 'Bogota'], []);

        // Los catalogos completos (dropdowns de filtro, filtro avanzado)
        // listan todas las ciudades siempre -- lo que importa es cuantas
        // FILAS trae el listado, no si "Medellin" aparece en algun lado del HTML.
        $this->assertStringContainsString('(1 registro)', $html, 'solo la fila Bogota->Pasto debe matchear "Bogota"');
        $this->assertStringContainsString('>Pasto</td>', $html);
    }

    public function test_busca_por_ciudad_destino_via_columna_de_referencia(): void
    {
        $html = $this->crud()->handle('/trayectos', ['q' => 'Medellin'], []);

        $this->assertStringContainsString('(1 registro)', $html, 'solo la fila Pasto->Medellin debe matchear "Medellin"');
        $this->assertStringContainsString('>Pasto</td>', $html);
    }

    public function test_busqueda_sin_coincidencia_no_rompe_y_no_devuelve_filas(): void
    {
        $html = $this->crud()->handle('/trayectos', ['q' => 'Cali'], []);

        $this->assertStringContainsString('(0 registros)', $html);
    }
}
