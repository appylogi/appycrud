<?php

namespace Appylogi\AppyCrud;

use Appylogi\AppyCrud\Crud\ActionsPosition;
use Appylogi\AppyCrud\Crud\Condition;
use Appylogi\AppyCrud\Crud\Csrf;
use Appylogi\AppyCrud\Crud\CrudRepository;
use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Crud\DuplicateValueException;
use Appylogi\AppyCrud\Crud\HookAbortException;
use Appylogi\AppyCrud\Crud\HtmlSanitizer;
use Appylogi\AppyCrud\Crud\ManyToMany;
use Appylogi\AppyCrud\Crud\RowAction;
use Appylogi\AppyCrud\Crud\UpdateChecker;
use Appylogi\AppyCrud\Crud\Validator;
use Appylogi\AppyCrud\Database\Connection;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Renderer\TailwindRenderer;
use Appylogi\AppyCrud\Schema\FieldType;
use Appylogi\AppyCrud\Schema\TableConfig;
use Appylogi\AppyCrud\Schema\TableIntrospector;
use Appylogi\AppyCrud\Schema\TableSchema;
use InvalidArgumentException;

/**
 * Punto de entrada unico: dado una conexion y una tabla, resuelve el
 * esquema, ejecuta la accion pedida por request (list/create/store/edit/update/
 * delete/bulkDelete/view/clone/export/print) y devuelve el HTML ya renderizado.
 *
 * Opciones soportadas ($options):
 *   - 'deleteMode' => DeleteMode::CONFIRM (default) | DIRECT | SOFT
 *   - 'softDeleteColumn' => nombre de columna, obligatorio si deleteMode es SOFT
 *   - 'export' => bool (default true)
 *   - 'bulkDelete' => bool (default true)
 *   - 'filters' => bool (default true)
 *   - 'search' => bool (default true) busqueda global sobre columnas de texto
 *   - 'view' => bool (default true)
 *   - 'print' => bool (default true)
 *   - 'clone' => bool (default true)
 *   - 'cloneExcludeColumns' => string[] columnas a vaciar al clonar (ej. codigos unicos)
 *   - 'cloneSuffixColumn' => columna a la que se le agrega un sufijo al clonar
 *   - 'cloneSuffix' => sufijo a usar (default ' (copia)')
 *   - 'csrf' => bool (default true). Protege store/update/delete/bulkDelete con
 *     un token de sesion. Requiere session_start() antes de instanciar AppyCrud
 *     (lanza RuntimeException si no hay sesion activa); desactivalo con false
 *     si tu aplicacion ya maneja CSRF a otro nivel.
 *   - 'where' => Condition[] condiciones base (scoping), SIEMPRE aplicadas:
 *     listado, exportar, ver, editar y eliminar. Ver Crud\Condition.
 *   - 'insertDefaults' => array<string,mixed> valores forzados en cada insert,
 *     ignorando lo que mande el cliente (ej. ['empresa_id' => $idEmpresaActual]).
 *   - 'defaultOrderBy' / 'defaultOrderDir' => orden usado cuando la URL no trae
 *     orderBy/orderDir explicitos (el usuario puede cambiarlo haciendo clic en
 *     cualquier columna del listado).
 *   - 'insertFields' / 'editFields' => string[]|null (default null = todas las
 *     columnas visibles/editables). Si se indica, solo esas columnas aparecen
 *     en el formulario correspondiente y solo esas se aceptan al guardar
 *     (defensa en profundidad: aunque alguien arme un POST con campos extra,
 *     se descartan).
 *   - 'hooks' => array de callables opcionales:
 *     'beforeInsert' => fn(array $data): array   — puede modificar $data; lanzar
 *         HookAbortException($mensaje) cancela el insert y muestra $mensaje.
 *     'afterInsert'  => fn(string $id, array $data): void
 *     'beforeUpdate' => fn(mixed $id, array $data): array — igual que beforeInsert.
 *     'afterUpdate'  => fn(mixed $id, array $data): void
 *     'beforeDelete' => fn(mixed $id): void — lanzar HookAbortException cancela el borrado.
 *     'afterDelete'  => fn(mixed $id): void
 *   - 'manyToMany' => Crud\ManyToMany[] relaciones muchos-a-muchos via tabla
 *     pivote (ver esa clase). Se renderizan como un multiselect adicional en
 *     crear/editar, se sincronizan despues de guardar el registro principal,
 *     y se limpian antes de eliminarlo (evita filas huerfanas en el pivote).
 *   - 'rowActions' => Crud\RowAction[] acciones custom agregadas al menu de
 *     cada fila (junto a Ver/Editar/Clonar/Eliminar). Ver esa clase.
 *   - 'checkForUpdates' => bool (default false). Si esta activo, el listado
 *     consulta (como mucho una vez cada 24h, con cache en disco) la API
 *     publica de Packagist para ver si hay una version mas nueva de AppyCrud,
 *     y muestra un aviso descartable arriba del listado si la hay. No envia
 *     ningun dato del proyecto — solo pregunta la ultima version publicada.
 *     Cualquier fallo de red se ignora en silencio (nunca bloquea la pagina).
 *     Ver Crud\UpdateChecker.
 *   - 'uploadDir' => ruta absoluta donde se guardan los archivos subidos.
 *     Obligatorio si alguna columna usa inputType 'file'. Debe existir y ser
 *     escribible, y NO deberia ser accesible directamente por HTTP con
 *     ejecucion de scripts habilitada (o al menos deshabilita la ejecucion de
 *     PHP ahi) — AppyCrud ya evita guardar extensiones peligrosas (.php,
 *     .phtml, etc.) pero la carpeta en si es responsabilidad tuya.
 *   - 'uploadUrlPrefix' => URL publica desde la que esos archivos son
 *     descargables (ej. '/uploads'), usada para armar el link en listado/vista.
 *     Si no se indica, solo se muestra el nombre del archivo como texto.
 */
class AppyCrud
{
    /** Version instalada de la libreria — se actualiza a mano en cada release (ver CHANGELOG.md). */
    public const VERSION = '0.1.22';

    private TableSchema $schema;
    private CrudRepository $repository;
    private TailwindRenderer $renderer;
    private string $deleteMode;
    private array $features;
    private bool $csrfEnabled;
    private string $csrfSessionKey;
    private ?array $insertFields;
    private ?array $editFields;
    private string $defaultOrderBy;
    private string $defaultOrderDir;
    private array $hooks;
    /** @var array<string, ManyToMany> keyed por ManyToMany::$name */
    private array $manyToMany;
    /** @var RowAction[] */
    private array $rowActions;
    private bool $checkForUpdates;
    private ?string $uploadDir;
    private ?string $uploadUrlPrefix;
    private bool $deleteFilesOnDelete;
    /** @var string[]|null */
    private ?array $filterableFields;
    private int $perPage;
    /** @var int[] */
    private array $perPageOptions;

    public function __construct(
        private Connection $connection,
        private string $table,
        private ?TableConfig $config = null,
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

        $this->features = [
            'export' => $options['export'] ?? true,
            'create' => $options['create'] ?? true,
            'edit' => $options['edit'] ?? true,
            'delete' => $options['delete'] ?? true,
            'bulkDelete' => $options['bulkDelete'] ?? true,
            'filters' => $options['filters'] ?? true,
            'search' => $options['search'] ?? true,
            'view' => $options['view'] ?? true,
            'print' => $options['print'] ?? true,
            'clone' => $options['clone'] ?? true,
            'cloneExcludeColumns' => $options['cloneExcludeColumns'] ?? [],
            'cloneSuffixColumn' => $options['cloneSuffixColumn'] ?? null,
            'cloneSuffix' => $options['cloneSuffix'] ?? ' (copia)',
            'actionsPosition' => $options['actionsPosition'] ?? ActionsPosition::RIGHT,
        ];

        if (!in_array($this->features['actionsPosition'], [ActionsPosition::LEFT, ActionsPosition::RIGHT], true)) {
            throw new InvalidArgumentException("AppyCrud: 'actionsPosition' debe ser ActionsPosition::LEFT o ActionsPosition::RIGHT.");
        }

        $this->csrfEnabled = $options['csrf'] ?? true;
        $this->csrfSessionKey = 'appycrud_csrf_' . $table;

        $this->insertFields = $options['insertFields'] ?? null;
        $this->editFields = $options['editFields'] ?? null;
        $this->defaultOrderBy = (string) ($options['defaultOrderBy'] ?? '');
        $this->defaultOrderDir = (string) ($options['defaultOrderDir'] ?? 'ASC');
        $this->hooks = $options['hooks'] ?? [];

        $this->manyToMany = [];
        foreach ($options['manyToMany'] ?? [] as $relation) {
            $this->manyToMany[$relation->name] = $relation;
        }

        $this->rowActions = $options['rowActions'] ?? [];
        // Desactivado por defecto: no queremos que ninguna instalacion consulte
        // Packagist sin que el integrador lo pida explicitamente. Ver UpdateChecker.
        $this->checkForUpdates = $options['checkForUpdates'] ?? false;

        $this->uploadDir = $options['uploadDir'] ?? null;
        $this->uploadUrlPrefix = $options['uploadUrlPrefix'] ?? null;
        $this->deleteFilesOnDelete = $options['deleteFilesOnDelete'] ?? true;
        $this->filterableFields = $options['filterableFields'] ?? null;
        // Precedencia: lo que fijo esta tabla en su TableConfig > la opcion
        // 'perPage'/'perPageOptions' del array de opciones del constructor >
        // default final (20 / [10, 20, 50, 100]) si nadie configuro nada.
        $this->perPage = max(1, $config?->perPage() ?? (int) ($options['perPage'] ?? 20));
        $this->perPageOptions = $config?->perPageOptions() ?? ($options['perPageOptions'] ?? [10, 20, 50, 100]);

        $hasFileColumn = false;
        foreach ($this->schema->columns() as $column) {
            if (FieldType::isFile($column->inputType ?? '')) {
                $hasFileColumn = true;
                break;
            }
        }

        if ($hasFileColumn && $this->uploadDir === null) {
            throw new InvalidArgumentException("AppyCrud: hay una columna con inputType 'file' pero falta la opcion 'uploadDir'.");
        }

        /** @var Condition[] $where */
        $where = $options['where'] ?? [];
        $insertDefaults = $options['insertDefaults'] ?? [];

        $this->repository = new CrudRepository($connection, $this->schema, $softDeleteColumn, $where, $insertDefaults);
        $this->renderer = new TailwindRenderer(new Translator($locale));
    }

    private function csrfToken(): string
    {
        return $this->csrfEnabled ? Csrf::token($this->csrfSessionKey) : '';
    }

    private function verifyCsrf(array $post): bool
    {
        return !$this->csrfEnabled || Csrf::verify($this->csrfSessionKey, $post['csrf_token'] ?? null);
    }

    public function schema(): TableSchema
    {
        return $this->schema;
    }

    /**
     * Despacha la accion segun $_GET['action'] y devuelve el HTML resultante.
     * $baseUrl es la URL del propio script (para construir los enlaces).
     * $isAjax indica si la peticion vino por fetch (header X-Requested-With) en
     * vez de una navegacion normal del navegador; para la accion por defecto
     * ('list') decide si se devuelve la pagina completa o solo el fragmento de
     * tabla+paginacion (usado por el filtrado/busqueda instantaneos).
     * Algunas acciones (delete, bulkDelete, store/update validos, export, print)
     * terminan el request ellas mismas (redirect o salida directa).
     */
    public function handle(string $baseUrl, array $get, array $post, bool $isAjax = false, array $files = []): string
    {
        $action = $get['action'] ?? 'list';

        if (in_array($action, ['create', 'store'], true) && !($this->features['create'] ?? true)) {
            $this->redirect($baseUrl);
        }

        if (in_array($action, ['edit', 'update'], true) && !($this->features['edit'] ?? true)) {
            $this->redirect($baseUrl);
        }

        foreach ($this->rowActions as $rowAction) {
            if ($rowAction->name === $action) {
                if ($rowAction->method === 'post' && !$this->verifyCsrf($post)) {
                    $this->redirect($baseUrl);
                }

                return ($rowAction->handler)($get['id'] ?? null, $get, $post);
            }
        }

        return match ($action) {
            'create' => $this->renderer->renderForm($this->schema, [], $baseUrl, false, $this->referenceOptions(), [], $this->csrfToken(), '', $this->insertFields, $this->manyToManyFormData(null)),
            'edit' => $this->handleEdit($get, $baseUrl),
            'view' => $this->handleView($get, $baseUrl),
            'clone' => $this->handleClone($get, $baseUrl),
            'store' => $this->handleStore($post, $baseUrl, $files),
            'update' => $this->handleUpdate($get['id'] ?? '', $post, $baseUrl, $files),
            'delete' => $this->handleDelete($get['id'] ?? '', $baseUrl, $post),
            'bulkDelete' => $this->handleBulkDelete($post, $baseUrl),
            'export' => $this->handleExport($get),
            'print' => $this->handlePrint($get['id'] ?? ''),
            'reference_search' => $this->handleReferenceSearch($get),
            default => $isAjax ? $this->renderListBody($get, $baseUrl) : $this->renderList($get, $baseUrl),
        };
    }

    /**
     * $rows: fila(s) que se van a mostrar con estas opciones (form de edicion,
     * fila de vista/impresion, o todas las filas de la pagina actual del
     * listado) -- garantiza que el label del valor ya guardado en esas filas
     * resuelva aunque la tabla referenciada sea mas grande que el limite del
     * <select> (500 por defecto). Sin filas (ej. formulario de creacion en
     * blanco) solo se listan las primeras $limit opciones, como siempre.
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array{value: mixed, label: string}>>
     */
    private function referenceOptions(array $rows = []): array
    {
        $options = [];

        foreach ($this->schema->columns() as $column) {
            if ($column->reference !== null) {
                $mustInclude = array_map(fn ($row) => $row[$column->name] ?? null, $rows);
                $options[$column->name] = $this->repository->referenceOptions($column, mustIncludeValues: $mustInclude);
            }
        }

        return $options;
    }

    /**
     * Datos para renderizar el multiselect de cada relacion muchos-a-muchos
     * en el formulario. $selectedOverride permite preservar lo que el
     * usuario ya habia marcado al re-renderizar tras un error de
     * validacion/CSRF/hook (en vez de perderlo o ir a buscarlo a la BD).
     * @param array<string, string[]>|null $selectedOverride nombre de relacion => ids seleccionados
     * @return array<int, array{name: string, label: string, options: array<int, array{value: mixed, label: string}>, selected: string[], inputType: string}>
     */
    private function manyToManyFormData(mixed $id, ?array $selectedOverride = null): array
    {
        $result = [];

        foreach ($this->manyToMany as $name => $relation) {
            $selected = $selectedOverride[$name]
                ?? ($id !== null ? $this->repository->manyToManySelected($relation, $id) : []);

            $result[] = [
                'name' => $name,
                'label' => $relation->label,
                'options' => $this->repository->manyToManyOptions($relation),
                'selected' => $selected,
                'inputType' => $relation->inputType,
            ];
        }

        return $result;
    }

    /** @return array<int, array{label: string, values: string[]}> */
    private function manyToManyViewData(mixed $id): array
    {
        $result = [];

        foreach ($this->manyToMany as $relation) {
            $options = $this->repository->manyToManyOptions($relation);
            $labelsById = [];
            foreach ($options as $option) {
                $labelsById[(string) $option['value']] = (string) $option['label'];
            }

            $selectedIds = $id !== null ? $this->repository->manyToManySelected($relation, $id) : [];
            $result[] = [
                'label' => $relation->label,
                'values' => array_map(fn ($v) => $labelsById[$v] ?? $v, $selectedIds),
            ];
        }

        return $result;
    }

    /** @return array<string, string[]> nombre de relacion => ids seleccionados, tal como llegaron en el POST */
    private function extractManyToManySelections(array $post): array
    {
        $selections = [];

        foreach ($this->manyToMany as $name => $relation) {
            $selections[$name] = array_map('strval', (array) ($post['m2m_' . $name] ?? []));
        }

        return $selections;
    }

    /** @return array<string, string> 'remove_{columna}' => '1' por cada columna 'file' cuya casilla de quitar vino marcada */
    private function extractRemoveFileFlags(array $post): array
    {
        $flags = [];

        foreach ($this->schema->columns() as $column) {
            if (FieldType::isFile($column->inputType ?? '') && !empty($post['remove_' . $column->name])) {
                $flags['remove_' . $column->name] = '1';
            }
        }

        return $flags;
    }

    private function syncManyToMany(mixed $id, array $selections): void
    {
        foreach ($this->manyToMany as $name => $relation) {
            $this->repository->syncManyToMany($relation, $id, $selections[$name] ?? []);
        }
    }

    private function renderList(array $get, string $baseUrl): string
    {
        [$pagination, $filters, $search, $orderBy, $orderDir, $advancedFilters] = $this->paginateFromRequest($get);

        return $this->renderer->renderList($this->schema, $pagination, $baseUrl, $this->deleteMode, $this->referenceOptions($pagination['rows']), $this->features, $filters, $search, $orderBy, $orderDir, $this->csrfToken(), $this->rowActionsForRender(), $this->uploadUrlPrefix, $this->filterableFields, $advancedFilters, $this->perPageOptions, $this->availableUpdate(), $this->config?->title(), $this->config?->subtitle());
    }

    private function handleEdit(array $get, string $baseUrl): string
    {
        $row = $this->repository->find($get['id'] ?? '') ?? [];

        return $this->renderer->renderForm($this->schema, $row, $baseUrl, true, $this->referenceOptions([$row]), [], $this->csrfToken(), '', $this->editFields, $this->manyToManyFormData($get['id'] ?? null));
    }

    private function handleView(array $get, string $baseUrl): string
    {
        $row = $this->repository->find($get['id'] ?? '') ?? [];

        return $this->renderer->renderView($this->schema, $row, $baseUrl, (string) ($get['id'] ?? ''), $this->referenceOptions([$row]), $this->manyToManyViewData($get['id'] ?? null), $this->uploadUrlPrefix);
    }

    private function handleClone(array $get, string $baseUrl): string
    {
        $row = $this->repository->cloneData($get['id'] ?? '', $this->features['cloneExcludeColumns'], $this->features['cloneSuffixColumn'], $this->features['cloneSuffix']) ?? [];

        return $this->renderer->renderForm($this->schema, $row, $baseUrl, false, $this->referenceOptions([$row]), [], $this->csrfToken(), '', $this->insertFields, $this->manyToManyFormData(null));
    }

    /** Solo se consulta si 'checkForUpdates' esta activo; nunca lanza excepciones. */
    private function availableUpdate(): ?array
    {
        return $this->checkForUpdates ? UpdateChecker::check(self::VERSION) : null;
    }

    /** Fragmento solo de tabla+paginacion, usado por el filtrado/busqueda por AJAX (sin recargar la pagina). */
    private function renderListBody(array $get, string $baseUrl): string
    {
        [$pagination, $filters, $search, $orderBy, $orderDir, $advancedFilters] = $this->paginateFromRequest($get);

        return $this->renderer->renderListBody($this->schema, $pagination, $baseUrl, $this->deleteMode, $this->referenceOptions($pagination['rows']), $this->features, $filters, $search, $orderBy, $orderDir, $this->csrfToken(), $this->rowActionsForRender(), $this->uploadUrlPrefix, $advancedFilters, $this->filterableFields, $this->perPageOptions);
    }

    /** @return array<int, array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool}> */
    private function rowActionsForRender(): array
    {
        return array_map(fn (RowAction $a) => [
            'name' => $a->name,
            'label' => $a->label,
            'icon' => $a->icon,
            'confirm' => $a->confirm,
            'method' => $a->method,
            'openInModal' => $a->openInModal,
        ], $this->rowActions);
    }

    /** @return array{0: array, 1: array<string,string>, 2: string, 3: string, 4: string, 5: array<int, array{field: string, op: string, value: mixed, conn: string}>} */
    private function paginateFromRequest(array $get): array
    {
        $page = max(1, (int) ($get['page'] ?? 1));
        $filters = $this->features['filters'] ? $this->restrictToFields($get['filter'] ?? [], $this->allowedFilterFields()) : [];
        $search = $this->features['search'] ? trim((string) ($get['q'] ?? '')) : '';
        $orderBy = (string) ($get['orderBy'] ?? $this->defaultOrderBy);
        $orderDir = (string) ($get['orderDir'] ?? $this->defaultOrderDir);
        $advancedFilters = $this->features['filters'] ? $this->extractAdvancedFilters($get) : [];
        $perPage = $this->resolvePerPage($get);

        $pagination = $this->repository->paginate($page, $perPage, $orderBy, $orderDir, $filters, $search, $advancedFilters);

        return [$pagination, $filters, $search, $orderBy, $orderDir, $advancedFilters];
    }

    /**
     * El valor de $get['perPage'] solo se acepta si esta en $perPageOptions
     * (whitelist) — de lo contrario cualquiera podria pedir ?perPage=999999
     * y forzar una consulta que trae la tabla completa de un golpe.
     */
    private function resolvePerPage(array $get): int
    {
        $requested = (int) ($get['perPage'] ?? 0);

        return in_array($requested, $this->perPageOptions, true) ? $requested : $this->perPage;
    }

    /** @return string[] nombres de columna permitidos en el filtro simple y en el constructor avanzado */
    private function allowedFilterFields(): array
    {
        if ($this->filterableFields !== null) {
            return $this->filterableFields;
        }

        return array_map(fn ($c) => $c->name, $this->schema->visibleColumns());
    }

    /**
     * Parsea las filas del constructor de filtro avanzado desde el GET
     * (arrays paralelos af_field[]/af_op[]/af_value[]/af_conn[], alineados
     * por posicion — cada fila del panel envia sus 4 inputs en ese orden).
     * Descarta filas cuyo campo no este en allowedFilterFields() (defensa
     * extra ademas de la que ya hace CrudRepository con Column::name real).
     * @return array<int, array{field: string, op: string, value: mixed, conn: string}>
     */
    private function extractAdvancedFilters(array $get): array
    {
        $fields = (array) ($get['af_field'] ?? []);
        $ops = (array) ($get['af_op'] ?? []);
        $values = (array) ($get['af_value'] ?? []);
        $conns = (array) ($get['af_conn'] ?? []);
        $allowed = $this->allowedFilterFields();

        $rows = [];
        foreach ($fields as $i => $field) {
            $field = (string) $field;

            if ($field === '' || !in_array($field, $allowed, true)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'op' => (string) ($ops[$i] ?? ''),
                'value' => $values[$i] ?? '',
                'conn' => (string) ($conns[$i] ?? 'AND'),
            ];
        }

        return $rows;
    }

    private function handleStore(array $post, string $baseUrl, array $files = []): string
    {
        $m2mSelections = $this->extractManyToManySelections($post);

        if (!$this->verifyCsrf($post)) {
            http_response_code(422);
            return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions([$post]), [], $this->csrfToken(), $this->csrfErrorMessage(), $this->insertFields, $this->manyToManyFormData(null, $m2mSelections));
        }

        $post = $this->normalizeRichTextFields($this->normalizeMultiselectFields($this->restrictToFields($post, $this->insertFields)));
        $post = $this->processFileUploads($files, $post, null);
        $errors = Validator::validate($this->schema, $post);
        $errors = array_merge_recursive($errors, $this->checkUniqueColumns($post, null));

        if ($errors !== []) {
            http_response_code(422);
            return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions([$post]), $errors, $this->csrfToken(), '', $this->insertFields, $this->manyToManyFormData(null, $m2mSelections));
        }

        $post = $this->normalizeEmptyNonStringFields($post, true);

        if (($beforeInsert = $this->hook('beforeInsert')) !== null) {
            try {
                $post = $beforeInsert($post);
            } catch (HookAbortException $e) {
                http_response_code(422);
                return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions([$post]), [], $this->csrfToken(), $e->getMessage(), $this->insertFields, $this->manyToManyFormData(null, $m2mSelections));
            }
        }

        try {
            $id = $this->repository->insert($post);
        } catch (DuplicateValueException $e) {
            // Condicion de carrera: el chequeo previo (checkUniqueColumns) no encontro
            // nada, pero otro proceso inserto el mismo valor justo antes de este INSERT.
            http_response_code(422);
            $errors = $e->column !== '' ? [$e->column => [$e->getMessage()]] : [];
            return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions([$post]), $errors, $this->csrfToken(), $e->column === '' ? $e->getMessage() : '', $this->insertFields, $this->manyToManyFormData(null, $m2mSelections));
        } catch (\Throwable $e) {
            http_response_code(500);
            return $this->renderer->renderForm($this->schema, $post, $baseUrl, false, $this->referenceOptions([$post]), [], $this->csrfToken(), $this->databaseErrorMessage($e), $this->insertFields, $this->manyToManyFormData(null, $m2mSelections));
        }
        $this->syncManyToMany($id, $m2mSelections);

        if (($afterInsert = $this->hook('afterInsert')) !== null) {
            $afterInsert($id, $post);
        }

        $this->redirect($baseUrl);
    }

    private function handleUpdate(mixed $id, array $post, string $baseUrl, array $files = []): string
    {
        $pk = $this->schema->primaryKey();
        $values = $pk !== null ? $post + [$pk->name => $id] : $post;
        $m2mSelections = $this->extractManyToManySelections($post);

        if (!$this->verifyCsrf($post)) {
            http_response_code(422);
            return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions([$values]), [], $this->csrfToken(), $this->csrfErrorMessage(), $this->editFields, $this->manyToManyFormData($id, $m2mSelections));
        }

        $existingRow = $this->repository->find($id);
        $removeFileFlags = $this->extractRemoveFileFlags($post);
        $post = $this->normalizeRichTextFields($this->normalizeMultiselectFields($this->restrictToFields($post, $this->editFields)));
        $post = $this->processFileUploads($files, $post + $removeFileFlags, $existingRow);
        $values = $pk !== null ? $post + [$pk->name => $id] : $post;
        $errors = Validator::validate($this->schema, $post);
        $errors = array_merge_recursive($errors, $this->checkUniqueColumns($post, $id));

        if ($errors !== []) {
            http_response_code(422);
            return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions([$values]), $errors, $this->csrfToken(), '', $this->editFields, $this->manyToManyFormData($id, $m2mSelections));
        }

        $post = $this->normalizeEmptyNonStringFields($post, false);

        if (($beforeUpdate = $this->hook('beforeUpdate')) !== null) {
            try {
                $post = $beforeUpdate($id, $post);
                $values = $pk !== null ? $post + [$pk->name => $id] : $post;
            } catch (HookAbortException $e) {
                http_response_code(422);
                return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions([$values]), [], $this->csrfToken(), $e->getMessage(), $this->editFields, $this->manyToManyFormData($id, $m2mSelections));
            }
        }

        try {
            $this->repository->update($id, $post);
        } catch (DuplicateValueException $e) {
            http_response_code(422);
            $errors = $e->column !== '' ? [$e->column => [$e->getMessage()]] : [];
            return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions([$values]), $errors, $this->csrfToken(), $e->column === '' ? $e->getMessage() : '', $this->editFields, $this->manyToManyFormData($id, $m2mSelections));
        } catch (\Throwable $e) {
            http_response_code(500);
            return $this->renderer->renderForm($this->schema, $values, $baseUrl, true, $this->referenceOptions([$values]), [], $this->csrfToken(), $this->databaseErrorMessage($e), $this->editFields, $this->manyToManyFormData($id, $m2mSelections));
        }
        $this->syncManyToMany($id, $m2mSelections);

        if (($afterUpdate = $this->hook('afterUpdate')) !== null) {
            $afterUpdate($id, $post);
        }

        $this->redirect($baseUrl);
    }

    /**
     * Un INSERT/UPDATE puede fallar por razones que la validacion de la app
     * no puede anticipar (restriccion de BD que el schema no modela, columna
     * NOT NULL sin default, tipo de dato incompatible, etc.). Sin este catch,
     * la excepcion se escapaba sin manejar -- con display_errors=Off (como
     * suele estar en produccion) el usuario solo veia una pantalla en blanco
     * con HTTP 500, sin ninguna pista de que fallo ni por que (a diferencia
     * de GroceryCrud, que mostraba el error real). Esta es una herramienta
     * interna de administracion, no una app publica, asi que se opta por
     * mostrar el mensaje real del driver de BD (ayuda a diagnosticar en el
     * momento) en vez de un mensaje generico -- siempre se registra tambien
     * via error_log() para quedar en el log del servidor.
     */
    private function databaseErrorMessage(\Throwable $e): string
    {
        error_log('AppyCrud: error al guardar en ' . $this->schema->table . ': ' . $e->getMessage());

        return 'No se pudo guardar el registro: ' . $e->getMessage();
    }

    private function handleDelete(mixed $id, string $baseUrl, array $post = []): string
    {
        if (($this->features['delete'] ?? true) && $this->verifyCsrf($post) && $this->runBeforeDelete($id)) {
            $this->deleteUploadedFilesFor($id);
            $this->deleteManyToManyFor($id);
            $this->repository->delete($id);
            $this->runAfterDelete($id);
        }

        $this->redirect($baseUrl);
    }

    private function handleBulkDelete(array $post, string $baseUrl): string
    {
        $ids = $post['ids'] ?? [];

        if (($this->features['delete'] ?? true) && $this->verifyCsrf($post) && is_array($ids) && $ids !== []) {
            foreach ($ids as $id) {
                if ($this->runBeforeDelete($id)) {
                    $this->deleteUploadedFilesFor($id);
                    $this->deleteManyToManyFor($id);
                    $this->repository->delete($id);
                    $this->runAfterDelete($id);
                }
            }
        }

        $this->redirect($baseUrl);
    }

    /** Limpia las filas de las tablas pivote antes de borrar el registro principal (evita huerfanos). */
    private function deleteManyToManyFor(mixed $id): void
    {
        foreach ($this->manyToMany as $relation) {
            $this->repository->deleteManyToManyFor($relation, $id);
        }
    }

    /** true si se puede continuar con el borrado; false si un hook 'beforeDelete' lo cancelo (HookAbortException). */
    private function runBeforeDelete(mixed $id): bool
    {
        $beforeDelete = $this->hook('beforeDelete');

        if ($beforeDelete === null) {
            return true;
        }

        try {
            $beforeDelete($id);
            return true;
        } catch (HookAbortException) {
            return false;
        }
    }

    private function runAfterDelete(mixed $id): void
    {
        $afterDelete = $this->hook('afterDelete');

        if ($afterDelete !== null) {
            $afterDelete($id);
        }
    }

    private function csrfErrorMessage(): string
    {
        return $this->renderer->translate('form.csrf_error');
    }

    /**
     * Chequeo previo (best-effort, no atomico) de las columnas marcadas
     * Column::$unique. $excludeId es null en insert (nada que excluir) o el
     * id de la fila en edicion (para no chocar contra si misma). No
     * reemplaza la proteccion real: ver DuplicateValueException, la red de
     * seguridad que atrapa el error real de la BD si dos guardados chocan
     * entre este chequeo y el INSERT/UPDATE.
     * @return array<string, string[]>
     */
    private function checkUniqueColumns(array $post, mixed $excludeId): array
    {
        $errors = [];

        foreach ($this->schema->columns() as $column) {
            if (!$column->unique) {
                continue;
            }

            $value = $post[$column->name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if ($this->repository->columnValueExists($column->name, $value, $excludeId)) {
                $errors[$column->name][] = "Ya existe un registro con ese {$column->label}.";
            }
        }

        return $errors;
    }

    private function hook(string $name): ?callable
    {
        return $this->hooks[$name] ?? null;
    }

    /** @param string[]|null $fields */
    private function restrictToFields(array $data, ?array $fields): array
    {
        return $fields === null ? $data : array_intersect_key($data, array_flip($fields));
    }

    /** Los campos multiselect_native/multiselect_searchable llegan como array (name[]); se guardan como CSV en una sola columna. */
    private function normalizeMultiselectFields(array $data): array
    {
        foreach ($this->schema->columns() as $column) {
            if (isset($data[$column->name]) && is_array($data[$column->name]) && FieldType::isMultiselect($column->inputType ?? '')) {
                $data[$column->name] = implode(',', $data[$column->name]);
            }
        }

        return $data;
    }

    /**
     * Un select/reference/numero/fecha que el usuario deja en blanco llega
     * como '' (string vacio), aunque la columna en BD sea numerica o de
     * fecha. MySQL en modo estricto rechaza eso ("Incorrect integer value")
     * incluso cuando la columna tiene un DEFAULT -- porque un valor '' SI
     * se envia explicitamente en el INSERT/UPDATE, MySQL no aplica el
     * DEFAULT (solo lo aplica cuando la columna se omite del todo). Esto
     * rompia el guardado de formularios que dejan opcional un campo NOT
     * NULL sin marcarlo 'required' (ver docs/uso.md, seccion "Columna
     * obligatoria vs. NOT NULL en la BD").
     *
     * Que se hace con esa columna depende de lo que ya sabe el schema
     * introspectado (nunca se toca la BD, ni se le pide al desarrollador
     * que le agregue un DEFAULT):
     *   - Si la columna SI tiene un DEFAULT real en la BD -> se quita de
     *     $data para que la BD lo aplique (ese es el valor correcto,
     *     definido por quien diseño la tabla).
     *   - Si la columna acepta NULL -> se manda NULL explicito.
     *   - Si es NOT NULL y no tiene DEFAULT (el caso mas comun en este
     *     tipo de tablas legacy: columnas numericas "de detalle" que
     *     nunca se pensaron realmente obligatorias) -> se manda un valor
     *     neutro segun el tipo (0 para numericos) en vez de omitir la
     *     columna, para que el INSERT no truene. Es el mismo resultado
     *     que un formulario clasico (ej. GroceryCrud) siempre produjo
     *     para estas columnas, sin requerir ninguna migracion de BD.
     *
     * $isInsert distingue dos situaciones muy distintas para una columna
     * que no llego en $data (ni siquiera como '' -- por ejemplo una
     * columna 'hidden' => true, que el navegador nunca envia porque el
     * campo no se renderiza):
     *   - En INSERT, "no llego" significa "nunca se le va a dar un valor
     *     real" -> aplica la misma normalizacion que si hubiera llegado
     *     vacia (si no, el INSERT omite la columna del todo y truena
     *     igual que con '').
     *   - En UPDATE, "no llego" casi siempre significa "este campo no es
     *     parte de este formulario de edicion" (ej. editFields la excluye
     *     a proposito) -> NO se toca, para no pisar con 0 un valor real
     *     ya guardado que el usuario no tenia intencion de tocar.
     */
    private const NUMERIC_DB_TYPES = ['int', 'decimal', 'float', 'double', 'bit'];
    private const DATE_DB_TYPES = ['date', 'time', 'year'];

    private function normalizeEmptyNonStringFields(array $data, bool $isInsert): array
    {
        foreach ($this->schema->columns() as $column) {
            $present = array_key_exists($column->name, $data);

            if ($present && $data[$column->name] !== '') {
                continue;
            }

            if (!$present && !$isInsert) {
                continue;
            }

            if (!$present && ($column->isPrimaryKey || $column->isAutoIncrement || FieldType::isFile($column->inputType ?? ''))) {
                continue;
            }

            $type = strtolower($column->type);
            $isNumeric = self::typeMatches($type, self::NUMERIC_DB_TYPES);
            $isDate = self::typeMatches($type, self::DATE_DB_TYPES);

            if (!$isNumeric && !$isDate) {
                continue;
            }

            if ($column->default !== null) {
                unset($data[$column->name]);
            } elseif ($column->nullable) {
                $data[$column->name] = null;
            } elseif ($isNumeric) {
                $data[$column->name] = 0;
            } else {
                // Fecha/hora NOT NULL sin default y sin valor -- no hay un
                // "cero" seguro y universal para fechas (varia por motor/
                // configuracion), asi que se deja el comportamiento previo:
                // se omite y se deja que la BD decida (puede seguir
                // fallando en este caso especifico, no se ha visto en la
                // practica todavia).
                unset($data[$column->name]);
            }
        }

        return $data;
    }

    private static function typeMatches(string $type, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitiza los campos 'richtext' antes de guardar (ver HtmlSanitizer) —
     * nunca se persiste el HTML crudo que mando el navegador. Corre antes de
     * Validator::validate() para que 'required'/'max' evaluen el valor ya
     * sanitizado (el que realmente se va a guardar).
     */
    private function normalizeRichTextFields(array $data): array
    {
        foreach ($this->schema->columns() as $column) {
            if (isset($data[$column->name]) && FieldType::isRichText($column->inputType ?? '')) {
                $data[$column->name] = HtmlSanitizer::sanitize((string) $data[$column->name]);
            }
        }

        return $data;
    }

    /** Extensiones que nunca se conservan, aunque el archivo original las traiga (riesgo de ejecutar codigo si uploadDir es accesible por HTTP). */
    private const DANGEROUS_UPLOAD_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'asp', 'aspx', 'jsp', 'htaccess', 'config'];

    /**
     * Procesa las columnas 'file': si se subio un archivo nuevo, lo guarda con
     * un nombre aleatorio (evita colisiones y extensiones peligrosas) y pone
     * ese nombre en $data. Si no se subio uno nuevo, conserva el valor ya
     * existente en $existingRow (editar sin re-subir no borra el archivo) —
     * en creacion ($existingRow === null), simplemente no se setea la columna.
     * Si vino marcada la casilla 'remove_{columna}' (y no se subio un
     * reemplazo), borra el archivo fisico del disco y limpia la columna.
     */
    private function processFileUploads(array $files, array $data, ?array $existingRow): array
    {
        foreach ($this->schema->columns() as $column) {
            if (!FieldType::isFile($column->inputType ?? '')) {
                continue;
            }

            $file = $files[$column->name] ?? null;
            $hasNewFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $removeRequested = !empty($data['remove_' . $column->name]);
            unset($data['remove_' . $column->name]);

            if (!$hasNewFile) {
                if ($removeRequested && $existingRow !== null && isset($existingRow[$column->name])) {
                    $this->deleteUploadedFile($existingRow[$column->name]);
                    $data[$column->name] = null;
                    continue;
                }

                if ($existingRow !== null && isset($existingRow[$column->name])) {
                    $data[$column->name] = $existingRow[$column->name];
                } else {
                    unset($data[$column->name]);
                }
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK || $this->uploadDir === null) {
                unset($data[$column->name]);
                continue;
            }

            $extension = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($file['name'], PATHINFO_EXTENSION)));
            if ($extension === '' || in_array($extension, self::DANGEROUS_UPLOAD_EXTENSIONS, true)) {
                $extension = 'bin';
            }

            $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR . $safeName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                if ($existingRow !== null && isset($existingRow[$column->name]) && $existingRow[$column->name] !== $safeName) {
                    $this->deleteUploadedFile($existingRow[$column->name]);
                }
                $data[$column->name] = $safeName;
            } else {
                unset($data[$column->name]);
            }
        }

        return $data;
    }

    /** Borra un archivo previamente subido (best-effort: si ya no existe o falla, no interrumpe el flujo). */
    private function deleteUploadedFile(?string $fileName): void
    {
        if ($fileName === null || $fileName === '' || $this->uploadDir === null) {
            return;
        }

        $path = rtrim($this->uploadDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Borra del disco los archivos de las columnas 'file' del registro, si 'deleteFilesOnDelete' esta activo. */
    private function deleteUploadedFilesFor(mixed $id): void
    {
        if (!$this->deleteFilesOnDelete || $this->uploadDir === null) {
            return;
        }

        $row = $this->repository->find($id);

        if ($row === null) {
            return;
        }

        foreach ($this->schema->columns() as $column) {
            if (FieldType::isFile($column->inputType ?? '') && !empty($row[$column->name])) {
                $this->deleteUploadedFile($row[$column->name]);
            }
        }
    }

    private function handleExport(array $get): never
    {
        $filters = $this->features['filters'] ? $this->restrictToFields($get['filter'] ?? [], $this->allowedFilterFields()) : [];
        $search = $this->features['search'] ? trim((string) ($get['q'] ?? '')) : '';
        $advancedFilters = $this->features['filters'] ? $this->extractAdvancedFilters($get) : [];
        $format = $get['format'] ?? 'csv';

        [$mime, $extension] = match ($format) {
            'xls' => ['application/vnd.ms-excel', 'xls'],
            'md' => ['text/markdown; charset=UTF-8', 'md'],
            default => ['text/csv; charset=UTF-8', 'csv'],
        };

        header("Content-Type: {$mime}");
        header('Content-Disposition: attachment; filename="' . $this->table . '.' . $extension . '"');

        $output = fopen('php://output', 'w');

        match ($format) {
            'xls' => $this->repository->exportXls($output, $filters, 1000, $search, $advancedFilters),
            'md' => $this->repository->exportMarkdown($output, $filters, 1000, $search, $advancedFilters),
            default => (function () use ($output, $filters, $search, $advancedFilters) {
                fwrite($output, "\xEF\xBB\xBF");
                $this->repository->exportCsv($output, $filters, 1000, $search, $advancedFilters);
            })(),
        };

        fclose($output);
        exit;
    }

    private function handlePrint(mixed $id): never
    {
        $row = $this->repository->find($id) ?? [];
        echo $this->renderer->renderPrintDocument($this->schema, $row, $this->referenceOptions([$row]));
        exit;
    }

    /**
     * Backend del combobox buscable "select2" para columnas de referencia
     * (renderSearchableSelectAjax): filtra en la base de datos, no en el
     * navegador -- por eso funciona sin importar cuantas filas tenga la
     * tabla referenciada. Solo acepta columnas que realmente sean una
     * referencia declarada en este schema (whitelist), nunca un nombre de
     * columna/tabla arbitrario que venga del cliente.
     */
    private function handleReferenceSearch(array $get): never
    {
        header('Content-Type: application/json');

        $columnName = $get['column'] ?? '';
        $query = $get['q'] ?? '';
        $column = null;
        foreach ($this->schema->columns() as $candidate) {
            if ($candidate->name === $columnName && $candidate->reference !== null) {
                $column = $candidate;
                break;
            }
        }

        if ($column === null) {
            http_response_code(404);
            echo json_encode(['options' => []]);
            exit;
        }

        $options = $this->repository->searchReferenceOptions($column, (string) $query);
        echo json_encode(['options' => $options]);
        exit;
    }

    private function redirect(string $baseUrl): never
    {
        header('Location: ' . $baseUrl);
        exit;
    }
}
