<?php

namespace Appylogi\AppyCrud;

use Appylogi\AppyCrud\Crud\CrudRepository;
use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Crud\Validator;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Renderer\TailwindRenderer;
use Appylogi\AppyCrud\Schema\TableConfig;
use Appylogi\AppyCrud\Schema\TableIntrospector;
use Appylogi\AppyCrud\Schema\TableSchema;
use InvalidArgumentException;

/**
 * Punto de entrada unico: dado una conexion y una tabla, resuelve el
 * esquema, ejecuta la accion pedida por request (list/create/store/edit/update/delete)
 * y devuelve el HTML ya renderizado.
 *
 * Opciones soportadas ($options):
 *   - 'deleteMode' => DeleteMode::CONFIRM (default) | DIRECT | SOFT
 *   - 'softDeleteColumn' => nombre de columna, obligatorio si deleteMode es SOFT
 */
class AppyCrud
{
    private TableSchema $schema;
    private CrudRepository $repository;
    private TailwindRenderer $renderer;
    private string $deleteMode;

    public function __construct(
        private Connection $connection,
        private string $table,
        ?TableConfig $config = null,
        string $locale = 'es',
        array $options = [],
    ) {
        $this->schema = (new TableIntrospector())->introspect($connection, $table, $config);

        $this->deleteMode = $options['deleteMode'] ?? DeleteMode::CONFIRM;
        $softDeleteColumn = $options['softDeleteColumn'] ?? null;

        if ($this->deleteMode === DeleteMode::SOFT) {
            if ($softDeleteColumn === null) {
                throw new InvalidArgumentException("AppyCrud: deleteMode SOFT requiere la opcion 'softDeleteColumn'.");
            }

            if ($this->schema->column($softDeleteColumn) === null) {
                throw new InvalidArgumentException("AppyCrud: la columna de borrado logico '{$softDeleteColumn}' no existe en la tabla '{$table}'.");
            }
        } else {
            $softDeleteColumn = null;
        }

        $this->repository = new CrudRepository($connection, $this->schema, $softDeleteColumn);
        $this->renderer = new TailwindRenderer(new Translator($locale));
    }

    public function schema(): TableSchema
    {
        return $this->schema;
    }

    /**
     * Despacha la accion segun $_GET['action'] y devuelve el HTML resultante.
     * $baseUrl es la URL del propio script (para construir los enlaces).
     */
    public function handle(string $baseUrl, array $get, array $post): string
    {
        $action = $get['action'] ?? 'list';

        return match ($action) {
            'create' => $this->renderer->renderForm($this->schema, [], $baseUrl, false, $this->referenceOptions()),
            'edit' => $this->renderer->renderForm($this->schema, $this->repository->find($get['id'] ?? '') ?? [], $baseUrl, true, $this->referenceOptions()),
            'store' => $this->handleStore($post, $baseUrl),
            'update' => $this->handleUpdate($get['id'] ?? '', $post, $baseUrl),
            'delete' => $this->handleDelete($get['id'] ?? '', $baseUrl),
            default => $this->renderList($get, $baseUrl),
        };
    }

    /** @return array<string, array<int, array{value: mixed, label: string}>> */
    private function referenceOptions(): array
    {
        $options = [];

        foreach ($this->schema->columns() as $column) {
            if ($column->reference !== null) {
                $options[$column->name] = $this->repository->referenceOptions($column);
            }
        }

        return $options;
    }

    private function renderList(array $get, string $baseUrl): string
    {
        $page = max(1, (int) ($get['page'] ?? 1));
        $pagination = $this->repository->paginate($page);

        return $this->renderer->renderList($this->schema, $pagination, $baseUrl, $this->deleteMode, $this->referenceOptions());
    }

    private function handleStore(array $post, string $baseUrl): string
    {
        $errors = Validator::validate($this->schema, $post);

        if ($errors !== []) {
            http_response_code(422);
            return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions(), $errors);
        }

        $this->repository->insert($post);
        $this->redirect($baseUrl);
    }

    private function handleUpdate(mixed $id, array $post, string $baseUrl): string
    {
        $errors = Validator::validate($this->schema, $post);

        if ($errors !== []) {
            http_response_code(422);
            $pk = $this->schema->primaryKey();
            $values = $pk !== null ? $post + [$pk->name => $id] : $post;
            return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions(), $errors);
        }

        $this->repository->update($id, $post);
        $this->redirect($baseUrl);
    }

    private function handleDelete(mixed $id, string $baseUrl): string
    {
        $this->repository->delete($id);
        $this->redirect($baseUrl);
    }

    private function redirect(string $baseUrl): never
    {
        header('Location: ' . $baseUrl);
        exit;
    }
}
