<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/** Fija v0.1.29: un archivo de imagen ya guardado muestra una miniatura clickeable (lightbox), no solo el nombre. */
final class ImagePreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE clientes (pkid INTEGER PRIMARY KEY AUTOINCREMENT, logo TEXT)');
        $this->exec("INSERT INTO clientes (pkid, logo) VALUES (1, 'logo-cliente.png')");
        $this->exec("INSERT INTO clientes (pkid, logo) VALUES (2, 'contrato.pdf')");
    }

    private function crud(?string $uploadUrlPrefix = '/uploads'): AppyCrud
    {
        $config = new TableConfig(columnOverrides: ['logo' => ['inputType' => 'file']]);

        return new AppyCrud($this->connection, 'clientes', $config, 'es', [
            'csrf' => false,
            'uploadDir' => sys_get_temp_dir(),
            'uploadUrlPrefix' => $uploadUrlPrefix,
        ]);
    }

    public function test_imagen_guardada_muestra_miniatura_clickeable_en_el_formulario_de_edicion(): void
    {
        $html = $this->crud()->handle('/clientes', ['action' => 'edit', 'id' => '1'], []);

        $this->assertStringContainsString('appycrudPreviewImage', $html);
        $this->assertStringContainsString('/uploads/logo-cliente.png', $html);
        $this->assertMatchesRegularExpression('/<img[^>]+src="[^"]*logo-cliente\.png"/', $html);
    }

    public function test_archivo_no_imagen_no_muestra_miniatura(): void
    {
        $html = $this->crud()->handle('/clientes', ['action' => 'edit', 'id' => '2'], []);

        $this->assertStringNotContainsString('appycrudPreviewImage', $html);
        $this->assertStringContainsString('contrato.pdf', $html);
    }

    public function test_sin_uploadurlprefix_no_intenta_armar_miniatura(): void
    {
        $html = $this->crud(uploadUrlPrefix: null)->handle('/clientes', ['action' => 'edit', 'id' => '1'], []);

        $this->assertStringNotContainsString('appycrudPreviewImage', $html);
        $this->assertStringContainsString('logo-cliente.png', $html, 'el nombre del archivo sigue mostrandose como texto informativo');
    }
}
