<?php

namespace Appylogi\AppyCrud\Tests;

use Appylogi\AppyCrud\Crud\CrudRepository;
use Appylogi\AppyCrud\Schema\TableConfig;
use Appylogi\AppyCrud\Schema\TableIntrospector;

/**
 * Fija un bug real de `CrudRepository::searchReferenceOptions()` (backend del
 * combobox buscable via AJAX, compartido por el formulario y -- desde esta
 * misma sesion -- el filtro de columna del listado): escribir un termino de
 * busqueda no vacio rompia con "a GROUP BY clause is required before HAVING"
 * en SQLite -- el HAVING sin GROUP BY, valido en MySQL (donde esta ruta ya
 * se usaba en produccion sin problema), no lo es en SQLite. Nunca se habia
 * probado esta ruta con un termino de busqueda no vacio.
 *
 * Se prueba `CrudRepository` directo (no `AppyCrud::handle()`) porque
 * `handleReferenceSearch()` termina en un `exit()` real sin la proteccion de
 * `AppyCrud::$exitOnRedirect` (esa solo cubre `redirect()`) -- llamarlo
 * desde un test mataria el proceso de PHPUnit igual que el bug ya corregido
 * de `redirect()`. Corregir eso queda fuera del alcance de este fix puntual.
 */
final class ReferenceSearchAjaxEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->exec('CREATE TABLE ciudades (pkid INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, departamento TEXT, activo INTEGER NOT NULL DEFAULT 1)');
        $this->exec("INSERT INTO ciudades (pkid, nombre, departamento, activo) VALUES
            (1, 'Bogota', 'Cundinamarca', 1),
            (2, 'Pasto', 'Narino', 1)");
        $this->exec('CREATE TABLE trayectos (pkid INTEGER PRIMARY KEY AUTOINCREMENT, ciudad_origen INTEGER)');
    }

    /** @return array{0: CrudRepository, 1: \Appylogi\AppyCrud\Schema\Column} */
    private function repositoryAndColumn(): array
    {
        $config = new TableConfig(columnOverrides: [
            'ciudad_origen' => ['label' => 'Ciudad Origen', 'reference' => [
                'table' => 'ciudades', 'column' => 'pkid', 'label' => '{nombre}-{departamento}',
            ]],
        ]);
        $schema = (new TableIntrospector())->introspect($this->connection, 'trayectos', $config);

        return [new CrudRepository($this->connection, $schema), $schema->column('ciudad_origen')];
    }

    public function test_buscar_con_termino_no_vacio_no_rompe_y_filtra_por_label(): void
    {
        [$repository, $column] = $this->repositoryAndColumn();

        $options = $repository->searchReferenceOptions($column, 'Pasto');

        $this->assertCount(1, $options);
        $this->assertSame('Pasto-Narino', $options[0]['label']);
    }

    public function test_buscar_sin_termino_lista_todo(): void
    {
        [$repository, $column] = $this->repositoryAndColumn();

        $options = $repository->searchReferenceOptions($column, '');

        $this->assertCount(2, $options);
    }
}
