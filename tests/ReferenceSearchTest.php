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
        $this->exec('INSERT INTO trayectos (ciudad_origen, ciudad_destino, dias_entregas) VALUES (3, 1, 1)');
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
        // Matchea la fila 1 (origen=Bogota) y la fila 3 (destino=Bogota).
        $this->assertStringContainsString('(2 registros)', $html, 'las filas con Bogota como origen o destino deben matchear');
        $this->assertStringContainsString('>Pasto</td>', $html);
    }

    public function test_busca_por_ciudad_destino_via_columna_de_referencia(): void
    {
        $html = $this->crud()->handle('/trayectos', ['q' => 'Medellin'], []);

        // Matchea la fila 2 (destino=Medellin) y la fila 3 (origen=Medellin).
        $this->assertStringContainsString('(2 registros)', $html, 'las filas con Medellin como origen o destino deben matchear');
        $this->assertStringContainsString('>Pasto</td>', $html);
    }

    public function test_busqueda_sin_coincidencia_no_rompe_y_no_devuelve_filas(): void
    {
        $html = $this->crud()->handle('/trayectos', ['q' => 'Cali'], []);

        $this->assertStringContainsString('(0 registros)', $html);
    }

    public function test_ordenar_por_columna_de_referencia_ordena_por_el_label_no_por_el_id(): void
    {
        // pkid 1: ciudad_origen=1 (Bogota) -- pkid 2: ciudad_origen=2 (Pasto)
        // -- pkid 3: ciudad_origen=3 (Medellin). Por id ascendente saldria
        // pkid 1,2,3 (Bogota, Pasto, Medellin); alfabeticamente por nombre
        // (lo esperado) es pkid 1,3,2 (Bogota, Medellin, Pasto). Se ubica
        // cada fila por el link de "editar" de su pkid (unico en el HTML),
        // no por el texto de la ciudad (que se repite como origen/destino
        // entre varias filas y no sirve como marcador de posicion).
        $html = $this->crud()->handle('/trayectos', ['orderBy' => 'ciudad_origen', 'orderDir' => 'ASC'], []);

        $posFila1 = strpos($html, 'action=edit&id=1&ajax=1');
        $posFila3 = strpos($html, 'action=edit&id=3&ajax=1');
        $posFila2 = strpos($html, 'action=edit&id=2&ajax=1');

        $this->assertNotFalse($posFila1);
        $this->assertNotFalse($posFila3);
        $this->assertNotFalse($posFila2);
        $this->assertLessThan($posFila3, $posFila1, 'fila con origen Bogota debe salir antes que la de origen Medellin (alfabetico, no por id)');
        $this->assertLessThan($posFila2, $posFila3, 'fila con origen Medellin debe salir antes que la de origen Pasto (alfabetico, no por id)');
    }
}
