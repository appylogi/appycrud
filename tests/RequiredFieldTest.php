<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\AppyCrud;
use Appylogi\AppyCrud\Schema\TableConfig;

/**
 * Fija el comportamiento de la v0.1.18: 'required' (atributo HTML y
 * asterisco) refleja SOLO lo que el desarrollador marca en 'rules', nunca
 * la nulabilidad real de la columna en la BD. Antes de ese fix se derivaba
 * de NOT NULL, forzando a llenar columnas legacy que nunca fueron pensadas
 * como obligatorias.
 */
final class RequiredFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE clientes (
            pkid INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            telefono TEXT NOT NULL
        )');
    }

    public function test_not_null_column_sin_rules_no_queda_required(): void
    {
        $config = new TableConfig(columnOverrides: [
            'nombre' => ['rules' => ['required']],
            // 'telefono' es NOT NULL en la BD pero NO se marca required aqui.
        ]);
        $crud = new AppyCrud($this->connection, 'clientes', $config, 'es', ['csrf' => false]);

        $html = $crud->handle('/clientes', ['action' => 'create'], []);

        $this->assertMatchesRegularExpression('/name="nombre"[^>]*\brequired\b/', $html, "nombre tiene 'rules'=>['required'] -> debe llevar el atributo required");
        $this->assertDoesNotMatchRegularExpression('/name="telefono"[^>]*\brequired\b/', $html, "telefono es NOT NULL en BD pero SIN 'rules' -> NO debe llevar required");
    }

    public function test_validator_del_lado_servidor_es_consistente_con_el_html(): void
    {
        $config = new TableConfig(columnOverrides: [
            'nombre' => ['rules' => ['required']],
        ]);
        $crud = new AppyCrud($this->connection, 'clientes', $config, 'es', ['csrf' => false]);

        // 'telefono' vacio no deberia bloquear el guardado (no es 'required'),
        // solo 'nombre' vacio deberia.
        $html = $crud->handle('/clientes', ['action' => 'store'], ['nombre' => '', 'telefono' => '']);

        $this->assertStringContainsString('obligatorio', $html);

        $count = (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
        $this->assertSame(0, $count, 'no debio insertar nada: nombre (si required) quedo vacio');
    }
}
