<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/**
 * Fija el comportamiento de v0.1.19-0.1.22: una columna NOT NULL sin
 * 'rules'=>['required'] que llega vacia/ausente al guardar NO debe romper el
 * INSERT/UPDATE -- ni con '' explicito (v0.1.19+) ni completamente ausente
 * del payload, ej. un campo 'hidden' que el navegador nunca envia (v0.1.22).
 */
final class NormalizeEmptyFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // SQLite no fuerza NOT NULL sin default salvo que se declare
        // explicitamente sin DEFAULT -- 'equivalente' simula una columna NOT
        // NULL sin default que ademas queda 'hidden' => true (nunca se
        // renderiza, el navegador nunca la envia).
        $this->exec('CREATE TABLE tipos (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            tipo_id INTEGER NOT NULL DEFAULT 2,
            equivalente INTEGER NOT NULL
        )');
    }

    public function test_columna_con_default_en_bd_usa_ese_default_si_llega_vacia(): void
    {
        $config = new TableConfig(columnOverrides: ['nombre' => ['rules' => ['required']]]);
        $crud = new AppyCrud($this->connection, 'tipos', $config, 'es', [
            'csrf' => false,
            'insertFields' => ['nombre', 'tipo_id', 'equivalente'],
        ]);

        // 'equivalente' esta oculta (hidden => true) -- nunca llega en el post,
        // ni como '' ni como 0. Sin el fix de v0.1.22, esto tronaba el INSERT.
        $configOculto = new TableConfig(columnOverrides: [
            'nombre' => ['rules' => ['required']],
            'equivalente' => ['hidden' => true],
        ]);
        $crud = new AppyCrud($this->connection, 'tipos', $configOculto, 'es', [
            'csrf' => false,
            'insertFields' => ['nombre', 'tipo_id', 'equivalente'],
        ]);

        $crud->handle('/tipos', ['action' => 'store'], ['nombre' => 'Prueba', 'tipo_id' => '']);

        $row = $this->connection->pdo()->query('SELECT * FROM tipos')->fetch();
        $this->assertNotFalse($row, 'el INSERT debio completarse sin error');
        $this->assertSame('Prueba', $row['nombre']);
        $this->assertSame(2, (int) $row['tipo_id'], "tipo_id vacio -> debe tomar el DEFAULT real de la BD (2), no 0");
        $this->assertSame(0, (int) $row['equivalente'], "equivalente (NOT NULL sin default, ausente del post) -> valor neutro 0");
    }

    public function test_columna_de_texto_not_null_sin_default_ausente_del_post_no_rompe_el_insert(): void
    {
        // Reproduce en vivo: Noticias.php ('descripcion' mediumtext NOT NULL
        // sin default) rompia igual que los casos numericos ya cubiertos por
        // los tests de arriba, solo que para texto -- el gap se encontro
        // verificando el redirect real contra la BD de un tenant real.
        $this->exec('CREATE TABLE noticias (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            titulo TEXT NOT NULL,
            descripcion TEXT NOT NULL,
            activo INTEGER NOT NULL DEFAULT 1
        )');

        $config = new TableConfig(columnOverrides: ['titulo' => ['rules' => ['required']]]);
        $crud = new AppyCrud($this->connection, 'noticias', $config, 'es', [
            'csrf' => false,
            // 'descripcion' deliberadamente fuera de insertFields (simula un
            // campo oculto/no incluido en el form) -- nunca llega en el post.
            'insertFields' => ['titulo', 'activo'],
        ]);

        $crud->handle('/noticias', ['action' => 'store'], ['titulo' => 'Titulo de prueba']);

        $row = $this->connection->pdo()->query('SELECT * FROM noticias')->fetch();
        $this->assertNotFalse($row, 'el INSERT debio completarse sin error');
        $this->assertSame('Titulo de prueba', $row['titulo']);
        $this->assertSame('', $row['descripcion'], "descripcion (TEXT NOT NULL sin default, ausente del post) -> valor neutro ''");
    }

    public function test_update_no_pisa_con_0_una_columna_ausente_del_editform(): void
    {
        $this->exec("INSERT INTO tipos (nombre, tipo_id, equivalente) VALUES ('Original', 5, 7)");

        // editFields NO incluye 'equivalente' a proposito (ej. columna oculta
        // solo en el form de edicion) -- debe conservar su valor (7), no
        // pisarlo con 0.
        $config = new TableConfig(columnOverrides: ['nombre' => ['rules' => ['required']]]);
        $crud = new AppyCrud($this->connection, 'tipos', $config, 'es', [
            'csrf' => false,
            'editFields' => ['nombre', 'tipo_id'],
        ]);

        $crud->handle('/tipos', ['action' => 'update', 'id' => '1'], ['nombre' => 'Editado', 'tipo_id' => '9']);

        $row = $this->connection->pdo()->query('SELECT * FROM tipos WHERE pkid = 1')->fetch();
        $this->assertSame('Editado', $row['nombre']);
        $this->assertSame(9, (int) $row['tipo_id']);
        $this->assertSame(7, (int) $row['equivalente'], "'equivalente' no estaba en editFields -> update() no debe tocarlo");
    }
}
