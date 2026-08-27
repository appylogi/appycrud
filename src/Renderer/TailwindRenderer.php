<?php

namespace Appylogi\AppyCrud\Renderer;

use Appylogi\AppyCrud\Crud\ActionsPosition;
use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\FieldType;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Genera el HTML de listado/formulario/vista con clases Tailwind.
 * No depende de ningun framework de JS: crear/editar/ver/clonar/confirmar
 * abren en <dialog> nativos cargados por fetch, y el envio de formularios
 * tambien va por fetch. Todo el JS y CSS de efectos vive en un solo bloque,
 * generado una sola vez por listado.
 */
class TailwindRenderer
{
    public function __construct(private Translator $translator)
    {
    }

    /** Traduce una llave del idioma activo; util para mensajes generados fuera del renderer (ej. errores de CSRF en AppyCrud). */
    public function translate(string $key, array $replace = []): string
    {
        return $this->translator->t($key, $replace);
    }

    /**
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions columna => opciones (para resolver el label de las FK en el listado)
     * @param array<string, mixed> $features ver AppyCrud::$features (export/bulkDelete/filters/search/view/print/clone/...)
     * @param array<string, string> $activeFilters columna => valor de filtro actualmente aplicado
     */
    /** @param array<int, array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool}> $rowActions */
    public function renderList(
        TableSchema $schema,
        array $pagination,
        string $baseUrl,
        string $deleteMode = DeleteMode::CONFIRM,
        array $referenceOptions = [],
        array $features = [],
        array $activeFilters = [],
        string $search = '',
        string $orderBy = '',
        string $orderDir = 'ASC',
        string $csrfToken = '',
        array $rowActions = [],
        ?string $uploadUrlPrefix = null,
        ?array $filterableFields = null,
        array $advancedFilters = [],
        array $perPageOptions = [10, 20, 50, 100],
        ?array $updateInfo = null,
        ?string $title = null,
        ?string $subtitle = null,
    ): string {
        $t = $this->translator;
        $filtersEnabled = $features['filters'] ?? true;
        $searchEnabled = $features['search'] ?? true;

        $createUrl = $this->e($baseUrl) . '?action=create&ajax=1';
        $modal = $this->renderModalShell($csrfToken);
        $updateBanner = $updateInfo !== null ? $this->renderUpdateBanner($updateInfo) : '';
        $searchAndFilters = ($filtersEnabled || $searchEnabled)
            ? $this->renderFilterRow($schema, $baseUrl, $activeFilters, $search, $orderBy, $orderDir, $filtersEnabled, $searchEnabled, $filterableFields, $advancedFilters, $referenceOptions)
            : '';

        $toolbar = ($features['create'] ?? true)
            ? '<button type="button" onclick="appycrudOpenModal(\'' . $createUrl . '\')" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">' . $this->icon('plus') . '<span>' . $this->e($t->t('list.new')) . '</span></button>'
            : '';

        if ($features['export'] ?? true) {
            $toolbar .= $this->renderExportMenu($baseUrl, $activeFilters, $search, $advancedFilters);
        }

        $toolbar .= '<button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('printer') . '<span>' . $this->e($t->t('list.print_list')) . '</span></button>';

        $body = $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken, $rowActions, $uploadUrlPrefix, $advancedFilters, $filterableFields, $perPageOptions);

        $titleText = $title ?? $t->t('list.title', ['table' => $schema->table]);
        $subtitleHtml = $subtitle !== null && $subtitle !== ''
            ? '<p class="text-sm text-gray-500 mt-0.5">' . $this->e($subtitle) . '</p>'
            : '';

        return <<<HTML
        <div class="max-w-6xl mx-auto p-6 appycrud-fade-in">
            {$updateBanner}
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">{$this->e($titleText)}</h1>
                    {$subtitleHtml}
                </div>
                <div class="flex items-center gap-2 print:hidden">{$toolbar}</div>
            </div>
            {$searchAndFilters}
            <div class="relative">
                <div id="appycrud-list-body">{$body}</div>
                <div id="appycrud-list-loading" class="hidden absolute inset-0 bg-white/70 flex items-center justify-center rounded-lg">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"></circle>
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                        </svg>
                        <span>{$this->e($t->t('list.loading'))}</span>
                    </div>
                </div>
            </div>
        </div>
        {$modal}
        HTML;
    }

    /**
     * Fragmento solo de tabla+paginacion (sin titulo/toolbar/formulario de
     * filtros), pensado para reemplazar #appycrud-list-body por fetch cuando
     * el usuario filtra/busca/ordena, sin recargar toda la pagina.
     */
    /** @param array<int, array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool}> $rowActions */
    public function renderListBody(
        TableSchema $schema,
        array $pagination,
        string $baseUrl,
        string $deleteMode = DeleteMode::CONFIRM,
        array $referenceOptions = [],
        array $features = [],
        array $activeFilters = [],
        string $search = '',
        string $orderBy = '',
        string $orderDir = 'ASC',
        string $csrfToken = '',
        array $rowActions = [],
        ?string $uploadUrlPrefix = null,
        array $advancedFilters = [],
        ?array $filterableFields = null,
        array $perPageOptions = [10, 20, 50, 100],
    ): string {
        return $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken, $rowActions, $uploadUrlPrefix, $advancedFilters, $filterableFields, $perPageOptions);
    }

    private function renderListInner(
        TableSchema $schema,
        array $pagination,
        string $baseUrl,
        string $deleteMode,
        array $referenceOptions,
        array $features,
        array $activeFilters,
        string $search,
        string $orderBy,
        string $orderDir,
        string $csrfToken,
        array $rowActions = [],
        ?string $uploadUrlPrefix = null,
        array $advancedFilters = [],
        ?array $filterableFields = null,
        array $perPageOptions = [10, 20, 50, 100],
    ): string {
        $t = $this->translator;
        $columns = $schema->visibleColumns();
        $pk = $schema->primaryKey();

        $deleteEnabled = $features['delete'] ?? true;
        $editEnabled = $features['edit'] ?? true;
        $bulkDeleteEnabled = ($features['bulkDelete'] ?? true) && $deleteEnabled && $pk !== null;
        $viewEnabled = $features['view'] ?? true;
        $cloneEnabled = $features['clone'] ?? true;

        $referenceLabels = [];
        foreach ($referenceOptions as $columnName => $options) {
            foreach ($options as $option) {
                $referenceLabels[$columnName][(string) $option['value']] = (string) $option['label'];
            }
        }
        // Los dropdown/enum estaticos (Column::$options, sin reference a otra tabla)
        // tambien resuelven su label, igual que una relacion.
        foreach ($schema->columns() as $column) {
            if ($column->reference !== null || $column->options === []) {
                continue;
            }

            foreach ($column->options as $option) {
                $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
            }
        }

        $bulkHeaderCell = $bulkDeleteEnabled
            ? '<th class="px-4 py-2 text-left print:hidden">' . $this->renderBulkDeleteControl($schema, $baseUrl, $deleteMode, $activeFilters, $search) . '</th>'
            : '';

        // A la derecha por defecto (ActionsPosition::RIGHT); ActionsPosition::LEFT
        // la ubica justo despues de la casilla de "seleccionar todos" (si el
        // borrado masivo esta activo) o como primera columna de la tabla.
        $actionsOnLeft = ($features['actionsPosition'] ?? ActionsPosition::RIGHT) === ActionsPosition::LEFT;
        $actionsAlign = $actionsOnLeft ? 'text-left' : 'text-right';
        $actionsHeaderCell = '<th class="px-4 py-2 ' . $actionsAlign . ' text-xs font-semibold uppercase text-gray-600 print:hidden">' . $this->e($t->t('list.actions')) . '</th>';

        $dataHeaders = '';
        foreach ($columns as $column) {
            $dataHeaders .= '<th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600">' . $this->renderSortLink($column, $baseUrl, $activeFilters, $search, $orderBy, $orderDir, $advancedFilters) . '</th>';
        }

        $headers = $actionsOnLeft
            ? $bulkHeaderCell . $actionsHeaderCell . $dataHeaders
            : $bulkHeaderCell . $dataHeaders . $actionsHeaderCell;

        $filterHeaderRow = ($features['filters'] ?? true)
            ? $this->renderColumnFilterRow($columns, $activeFilters, $bulkDeleteEnabled, $filterableFields, $referenceOptions, $actionsOnLeft)
            : '';

        $bodyRows = '';
        foreach ($pagination['rows'] as $row) {
            $pkValue = $pk !== null ? $row[$pk->name] : '';

            $bulkCell = $bulkDeleteEnabled
                ? '<td class="px-4 py-2 print:hidden"><input type="checkbox" class="appycrud-row-check" onchange="appycrudUpdateBulkUI()" value="' . $this->e((string) $pkValue) . '"></td>'
                : '';

            $dataCells = '';
            foreach ($columns as $column) {
                $rawValue = (string) ($row[$column->name] ?? '');
                $displayValue = ($column->reference !== null || $column->options !== [])
                    ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                    : $rawValue;

                $cellContent = match (true) {
                    FieldType::isFile($column->inputType ?? '') => $this->renderFileCell($rawValue, $uploadUrlPrefix),
                    FieldType::isRichText($column->inputType ?? '') => $this->e($this->richTextPreview($displayValue)),
                    default => $this->e($displayValue),
                };

                $dataCells .= '<td class="px-4 py-2 text-sm text-gray-800">' . $cellContent . '</td>';
            }

            $actionsCell = '<td class="px-4 py-2 ' . $actionsAlign . ' text-sm print:hidden">' . $this->renderRowActions($baseUrl, $pkValue, $deleteMode, $viewEnabled, $cloneEnabled, $t, $csrfToken, $rowActions, $deleteEnabled, $editEnabled) . '</td>';

            $cells = $actionsOnLeft
                ? $bulkCell . $actionsCell . $dataCells
                : $bulkCell . $dataCells . $actionsCell;

            $bodyRows .= '<tr class="border-b border-gray-100 hover:bg-gray-50">' . $cells . '</tr>';
        }

        if ($bodyRows === '') {
            $colspan = count($columns) + 1 + ($bulkDeleteEnabled ? 1 : 0);
            $bodyRows = '<tr><td colspan="' . $colspan . '" class="px-4 py-6 text-center text-sm text-gray-500">' . $this->e($t->t('list.empty')) . '</td></tr>';
        }

        $paginationNav = $this->renderPaginationNav($baseUrl, $activeFilters, $search, $orderBy, $orderDir, $advancedFilters, $pagination, $perPageOptions);

        return <<<HTML
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr>{$headers}</tr>{$filterHeaderRow}</thead>
                <tbody>{$bodyRows}</tbody>
            </table>
        </div>
        {$paginationNav}
        HTML;
    }

    /**
     * Anterior/Siguiente + numero de pagina + selector de "registros por
     * pagina", todo por navegacion normal (como el orden por columna), no
     * AJAX — consistente con renderSortLink()/renderExportMenu(). El
     * selector usa JS minimo (URLSearchParams) para no depender de otro
     * <form> ni duplicar la logica de buildListQuery() del lado del cliente.
     * @param int[] $perPageOptions
     */
    private function renderPaginationNav(string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir, array $advancedFilters, array $pagination, array $perPageOptions): string
    {
        $t = $this->translator;
        $page = (int) $pagination['page'];
        $lastPage = (int) $pagination['lastPage'];
        $perPage = (int) $pagination['perPage'];

        $prevUrl = $this->e($baseUrl) . '?' . $this->buildListQuery($activeFilters, $search, $orderBy, $orderDir, $advancedFilters, max(1, $page - 1), $perPage);
        $nextUrl = $this->e($baseUrl) . '?' . $this->buildListQuery($activeFilters, $search, $orderBy, $orderDir, $advancedFilters, min($lastPage, $page + 1), $perPage);

        $prevDisabled = $page <= 1;
        $nextDisabled = $page >= $lastPage;
        $navClass = 'inline-flex items-center px-2 py-1 rounded-md border text-sm';

        $prevLink = $prevDisabled
            ? '<span class="' . $navClass . ' border-gray-200 text-gray-300 cursor-not-allowed">' . $this->e($t->t('list.prev_page')) . '</span>'
            : '<a href="' . $prevUrl . '" class="' . $navClass . ' border-gray-300 text-gray-700 hover:bg-gray-50">' . $this->e($t->t('list.prev_page')) . '</a>';

        $nextLink = $nextDisabled
            ? '<span class="' . $navClass . ' border-gray-200 text-gray-300 cursor-not-allowed">' . $this->e($t->t('list.next_page')) . '</span>'
            : '<a href="' . $nextUrl . '" class="' . $navClass . ' border-gray-300 text-gray-700 hover:bg-gray-50">' . $this->e($t->t('list.next_page')) . '</a>';

        $perPageSelect = '';
        if ($perPageOptions !== []) {
            $options = '';
            foreach ($perPageOptions as $option) {
                $options .= '<option value="' . $option . '"' . ($option === $perPage ? ' selected' : '') . '>' . $option . '</option>';
            }
            $perPageSelect = '<label class="flex items-center gap-1.5 text-sm text-gray-500">'
                . $this->e($t->t('list.per_page'))
                . '<select onchange="var p=new URLSearchParams(location.search);p.set(\'perPage\',this.value);p.delete(\'page\');location.search=p.toString();" class="border border-gray-300 rounded-md px-2 py-1 text-sm bg-white">' . $options . '</select>'
                . '</label>';
        }

        $pageInfo = $t->t('list.page_of', ['page' => $page, 'lastPage' => $lastPage]);

        return '<div class="mt-3 flex items-center justify-between flex-wrap gap-3 print:hidden">'
            . '<p class="text-sm text-gray-500">' . $this->e($pageInfo) . '</p>'
            . '<div class="flex items-center gap-3">' . $perPageSelect . '<div class="flex items-center gap-2">' . $prevLink . $nextLink . '</div></div>'
            . '</div>';
    }

    /**
     * Segunda fila del <thead>, un input/select de filtro alineado bajo cada
     * columna filtrable (columnas fuera de $filterableFields quedan con una
     * celda vacia, para mantener la alineacion con el resto de la tabla).
     * Estos inputs NO son descendientes de <form id="appycrud-filter-form">
     * (esa tabla se reemplaza entera por AJAX en cada filtrado/orden, fuera
     * del <form> que vive en renderFilterRow()) — se asocian via el
     * atributo form="appycrud-filter-form", que el navegador respeta para
     * FormData() sin importar la posicion en el DOM.
     * @param Column[] $columns
     * @param array<string, string> $activeFilters
     * @param string[]|null $filterableFields
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions
     */
    private function renderColumnFilterRow(array $columns, array $activeFilters, bool $bulkDeleteEnabled, ?array $filterableFields, array $referenceOptions = [], bool $actionsOnLeft = false): string
    {
        $bulkCell = $bulkDeleteEnabled ? '<th class="px-4 py-1.5"></th>' : '';
        $actionsPlaceholder = '<th class="px-4 py-1.5 print:hidden"></th>';
        $dataCells = '';

        foreach ($columns as $column) {
            $filterable = $filterableFields === null || in_array($column->name, $filterableFields, true);

            if (!$filterable) {
                $dataCells .= '<th class="px-4 py-1.5"></th>';
                continue;
            }

            $current = $activeFilters[$column->name] ?? '';
            $onEvent = 'appycrudScheduleFilter(document.getElementById(\'appycrud-filter-form\'))';

            if ($column->reference !== null) {
                // FK: el filtro compara por IGUALDAD contra el id real (ver
                // CrudRepository::buildWhereClause), no por texto — un <input>
                // de texto contra el nombre visible nunca matchea nada. El
                // <select> deja elegir por label pero envia el id.
                $input = '<select form="appycrud-filter-form" name="filter[' . $this->e($column->name) . ']" onchange="' . $onEvent . '" class="w-full border border-gray-300 rounded-md px-2 py-1 text-xs bg-white">'
                    . '<option value="">-</option>';
                foreach ($referenceOptions[$column->name] ?? [] as $option) {
                    $optionValue = (string) $option['value'];
                    $input .= '<option value="' . $this->e($optionValue) . '"' . ($current === $optionValue ? ' selected' : '') . '>' . $this->e((string) $option['label']) . '</option>';
                }
                $input .= '</select>';
            } elseif (FieldType::strategy($column->inputType ?? '') === FieldType::STRATEGY_CHECKBOX) {
                $input = '<select form="appycrud-filter-form" name="filter[' . $this->e($column->name) . ']" onchange="' . $onEvent . '" class="w-full border border-gray-300 rounded-md px-2 py-1 text-xs bg-white">'
                    . '<option value="">-</option>'
                    . '<option value="1"' . ($current === '1' ? ' selected' : '') . '>Si</option>'
                    . '<option value="0"' . ($current === '0' ? ' selected' : '') . '>No</option>'
                    . '</select>';
            } else {
                $input = '<input type="text" form="appycrud-filter-form" name="filter[' . $this->e($column->name) . ']" value="' . $this->e($current) . '" oninput="' . $onEvent . '" placeholder="' . $this->e($column->label) . '" class="w-full border border-gray-300 rounded-md px-2 py-1 text-xs">';
            }

            $dataCells .= '<th class="px-4 py-1.5 font-normal">' . $input . '</th>';
        }

        $cells = $actionsOnLeft
            ? $bulkCell . $actionsPlaceholder . $dataCells
            : $bulkCell . $dataCells . $actionsPlaceholder;

        return '<tr class="bg-gray-50 border-t border-gray-100">' . $cells . '</tr>';
    }

    /**
     * Checkbox "seleccionar todos" + boton de borrado masivo, oculto hasta
     * que haya alguna fila seleccionada (lo muestra/oculta appycrudUpdateBulkUI).
     */
    private function renderBulkDeleteControl(TableSchema $schema, string $baseUrl, string $deleteMode, array $activeFilters, string $search): string
    {
        $t = $this->translator;
        $bulkMessage = $this->e($this->jsString($t->t('list.bulk_delete_confirm', ['count' => '__COUNT__'])));
        $bulkDeleteLabel = $this->e($this->jsString($t->t('list.delete')));
        $bulkRequireConfirm = $deleteMode === DeleteMode::CONFIRM ? 'true' : 'false';
        $bulkDeleteUrl = $this->e($baseUrl) . '?action=bulkDelete';

        return '<div class="flex items-center gap-2">'
            . '<input type="checkbox" onclick="appycrudToggleAll(this)" aria-label="' . $this->e($t->t('list.select_all')) . '">'
            . '<button type="button" id="appycrud-bulk-delete-btn" onclick="appycrudBulkDelete(\'' . $bulkDeleteUrl . '\', ' . $bulkMessage . ', ' . $bulkRequireConfirm . ', ' . $bulkDeleteLabel . ')" class="hidden items-center gap-1 text-red-600 hover:text-red-800 text-xs font-medium">'
            . $this->icon('trash') . '<span>' . $this->e($t->t('list.bulk_delete')) . '</span><span class="appycrud-bulk-count"></span>'
            . '</button>'
            . '</div>';
    }

    private function renderSortLink(Column $column, string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir, array $advancedFilters = []): string
    {
        $isActive = $orderBy === $column->name;
        $nextDir = ($isActive && strtoupper($orderDir) === 'ASC') ? 'DESC' : 'ASC';
        $query = $this->buildListQuery($activeFilters, $search, $column->name, $nextDir, $advancedFilters);
        $arrow = $isActive ? ($nextDir === 'DESC' ? ' ↑' : ' ↓') : '';

        return '<a href="' . $this->e($baseUrl) . '?' . $query . '" class="hover:text-gray-900' . ($isActive ? ' text-gray-900' : '') . '">' . $this->e($column->label) . $arrow . '</a>';
    }

    /** Si hay uploadUrlPrefix, el nombre del archivo se muestra como link de descarga; si no, solo como texto. */
    private function renderFileCell(string $filename, ?string $uploadUrlPrefix): string
    {
        if ($filename === '') {
            return '';
        }

        if ($uploadUrlPrefix === null) {
            return $this->e($filename);
        }

        $url = $this->e(rtrim($uploadUrlPrefix, '/') . '/' . $filename);

        return '<a href="' . $url . '" target="_blank" class="text-blue-600 hover:underline">' . $this->e($filename) . '</a>';
    }

    /** Vista previa en texto plano (sin etiquetas) para la celda del listado — el HTML completo se reserva para la vista/edicion, donde no compite por espacio con otras columnas. */
    private function richTextPreview(string $html, int $maxLength = 80): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) . '…' : $text;
    }

    /** @return string querystring (sin "?") con filtros + busqueda + orden */
    /** @param array<int, array{field: string, op: string, value: mixed, conn: string}> $advancedFilters */
    private function buildListQuery(array $activeFilters, string $search, string $orderBy, string $orderDir, array $advancedFilters = [], int $page = 1, ?int $perPage = null): string
    {
        $params = [];

        foreach ($activeFilters as $columnName => $value) {
            if ($value !== '' && $value !== null) {
                $params['filter'][$columnName] = $value;
            }
        }

        if ($search !== '') {
            $params['q'] = $search;
        }

        if ($orderBy !== '') {
            $params['orderBy'] = $orderBy;
            $params['orderDir'] = $orderDir;
        }

        foreach ($advancedFilters as $row) {
            $params['af_field'][] = $row['field'];
            $params['af_op'][] = $row['op'];
            $params['af_value'][] = $row['value'];
            $params['af_conn'][] = $row['conn'];
        }

        if ($page > 1) {
            $params['page'] = $page;
        }

        if ($perPage !== null) {
            $params['perPage'] = $perPage;
        }

        return http_build_query($params);
    }

    /** @param array<int, array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool}> $rowActions */
    private function renderRowActions(string $baseUrl, mixed $pkValue, string $deleteMode, bool $viewEnabled, bool $cloneEnabled, Translator $t, string $csrfToken = '', array $rowActions = [], bool $deleteEnabled = true, bool $editEnabled = true): string
    {
        $editUrl = $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $viewUrl = $this->e($baseUrl) . '?action=view&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $cloneUrl = $this->e($baseUrl) . '?action=clone&id=' . $this->e((string) $pkValue) . '&ajax=1';

        $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';

        $deleteSubmit = $deleteMode === DeleteMode::CONFIRM
            ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($t->t('confirm.delete'))) . ', ' . $this->e($this->jsString($t->t('list.delete'))) . ');"'
            : '';
        $deleteForm = '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '"' . $deleteSubmit . '>' . $csrfField . '%s</form>';

        $editButton = '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="%s px-2.5 py-1 rounded-md border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 hover:border-blue-300%s">' . $this->icon('edit') . '<span>' . $this->e($t->t('list.edit')) . '</span></button>';
        $viewButton = '<button type="button" onclick="appycrudOpenModal(\'' . $viewUrl . '\')" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-gray-200 bg-gray-50 text-gray-700 hover:bg-gray-100 hover:border-gray-300">' . $this->icon('eye') . '<span>' . $this->e($t->t('list.view')) . '</span></button>';

        // Con pocas acciones se muestran todas en linea; con varias, las
        // secundarias se agrupan en un menu para no saturar la fila.
        $extraCount = ($viewEnabled ? 1 : 0) + ($cloneEnabled ? 1 : 0) + ($deleteEnabled ? 1 : 0) + count($rowActions);

        if ($extraCount <= 1) {
            $inline = $editEnabled ? sprintf($editButton, 'inline-flex items-center gap-1', '') : '';
            if ($deleteEnabled) {
                $inline .= sprintf($deleteForm, '<button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:text-red-800">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>');
            } elseif ($viewEnabled && !$editEnabled) {
                // Sin editar ni eliminar, "Ver" es la unica accion disponible -- mostrarla
                // inline en vez de perderla (viewEnabled ya cuenta en $extraCount).
                $inline .= $viewButton;
            }

            return $inline === '' ? '' : '<span class="inline-flex items-center gap-3">' . $inline . '</span>';
        }

        $menuItems = '';
        if ($viewEnabled) {
            $menuItems .= '<button type="button" onclick="appycrudOpenModal(\'' . $viewUrl . '\')" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 text-left">' . $this->icon('eye') . '<span>' . $this->e($t->t('list.view')) . '</span></button>';
        }
        if ($cloneEnabled) {
            $menuItems .= '<button type="button" onclick="appycrudOpenModal(\'' . $cloneUrl . '\')" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-emerald-700 hover:bg-emerald-50 text-left">' . $this->icon('copy') . '<span>' . $this->e($t->t('list.clone')) . '</span></button>';
        }
        foreach ($rowActions as $action) {
            $menuItems .= $this->renderCustomRowAction($action, $pkValue, $baseUrl, $csrfToken);
        }
        if ($deleteEnabled) {
            $menuItems .= sprintf($deleteForm, '<button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 text-left">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>');
        }

        return '<span class="inline-flex items-center gap-3">'
            . ($editEnabled ? sprintf($editButton, 'inline-flex items-center gap-1', '') : '')
            . '<span class="relative inline-block appycrud-menu-wrap">'
            . '<button type="button" onclick="appycrudToggleMenu(this)" aria-label="' . $this->e($t->t('list.more_actions')) . '" class="text-gray-500 hover:text-gray-800 p-1 rounded hover:bg-gray-100">' . $this->icon('dots') . '</button>'
            . '<div class="hidden appycrud-menu w-40 bg-white border border-gray-200 rounded-md shadow-lg overflow-hidden">' . $menuItems . '</div>'
            . '</span>'
            . '</span>';
    }

    /** @param array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool} $action */
    private function renderCustomRowAction(array $action, mixed $pkValue, string $baseUrl, string $csrfToken): string
    {
        $url = $this->e($baseUrl) . '?action=' . $this->e($action['name']) . '&id=' . $this->e((string) $pkValue);
        $iconHtml = $action['icon'] !== null ? $this->icon($action['icon']) : '';
        $labelHtml = '<span>' . $this->e($action['label']) . '</span>';
        $itemClass = 'w-full flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 text-left';

        if ($action['method'] === 'post') {
            $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';
            $onsubmit = $action['confirm'] !== null
                ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($action['confirm'])) . ', ' . $this->e($this->jsString($action['label'])) . ');"'
                : '';

            return '<form method="post" action="' . $url . '"' . $onsubmit . '>' . $csrfField . '<button type="submit" class="' . $itemClass . '">' . $iconHtml . $labelHtml . '</button></form>';
        }

        if ($action['openInModal']) {
            return '<button type="button" onclick="appycrudOpenModal(\'' . $url . '&ajax=1\')" class="' . $itemClass . '">' . $iconHtml . $labelHtml . '</button>';
        }

        if ($action['confirm'] !== null) {
            $onclick = 'appycrudConfirmAction(' . $this->jsString($action['confirm']) . ', function () { window.location.href = ' . $this->jsString($url) . '; }, ' . $this->jsString($action['label']) . '); return false;';

            return '<a href="' . $url . '" onclick="' . $this->e($onclick) . '" class="' . $itemClass . '">' . $iconHtml . $labelHtml . '</a>';
        }

        return '<a href="' . $url . '" class="' . $itemClass . '">' . $iconHtml . $labelHtml . '</a>';
    }

    /**
     * Aviso descartable de version nueva disponible. $updateInfo viene de
     * Crud\UpdateChecker::check(): ['version' => '0.1.3', 'url' => '...'].
     * El descarte se recuerda en localStorage por version (data-appycrud-update-version),
     * asi que si sale una version mas nueva todavia, el aviso vuelve a aparecer.
     */
    private function renderUpdateBanner(array $updateInfo): string
    {
        $t = $this->translator;
        $version = $this->e($updateInfo['version']);
        $url = $this->e($updateInfo['url']);
        $message = $this->e($t->t('update.banner', ['version' => $updateInfo['version']]));
        $viewLabel = $this->e($t->t('update.view_changes'));
        $dismissLabel = $this->e($t->t('update.dismiss'));

        return <<<HTML
        <div data-appycrud-update-version="{$version}" class="hidden items-center justify-between gap-3 mb-4 px-4 py-2.5 rounded-md border border-blue-200 bg-blue-50 text-sm text-blue-800" id="appycrud-update-banner">
            <div class="flex items-center gap-2">{$this->icon('sparkles')}<span>{$message}</span></div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <a href="{$url}" target="_blank" rel="noopener" class="font-medium underline hover:no-underline">{$viewLabel}</a>
                <button type="button" onclick="appycrudDismissUpdateBanner('{$version}')" class="text-blue-400 hover:text-blue-700" aria-label="{$dismissLabel}">{$this->icon('x')}</button>
            </div>
        </div>
        <script>
        (function () {
            var el = document.getElementById('appycrud-update-banner');
            if (!el) return;
            var version = el.getAttribute('data-appycrud-update-version');
            try {
                if (localStorage.getItem('appycrud_update_dismissed') === version) return;
            } catch (e) {}
            el.classList.remove('hidden');
            el.classList.add('flex');
        })();
        </script>
        HTML;
    }

    private function renderExportMenu(string $baseUrl, array $activeFilters, string $search, array $advancedFilters = []): string
    {
        $t = $this->translator;
        $query = $this->buildListQuery($activeFilters, $search, '', '', $advancedFilters);
        $sep = $query === '' ? '?' : '?' . $query . '&';

        $formats = [
            'csv' => $t->t('list.export_csv'),
            'xls' => $t->t('list.export_xls'),
            'md' => $t->t('list.export_md'),
        ];

        $items = '';
        foreach ($formats as $format => $label) {
            $url = $this->e($baseUrl) . $sep . 'action=export&format=' . $format;
            $items .= '<a href="' . $url . '" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">' . $this->e($label) . '</a>';
        }

        return '<span class="relative inline-block appycrud-menu-wrap">'
            . '<button type="button" onclick="appycrudToggleMenu(this)" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('download') . '<span>' . $this->e($t->t('list.export')) . '</span></button>'
            . '<div class="hidden appycrud-menu w-40 bg-white border border-gray-200 rounded-md shadow-lg overflow-hidden">' . $items . '</div>'
            . '</span>';
    }

    /**
     * Una sola fila: busqueda global + un input por columna (limitado a
     * $filterableFields si se define, para no saturar tablas anchas), mas un
     * boton para abrir el constructor de filtro avanzado (AND/OR). Todo en
     * el mismo <form method="get"> para poder combinarse y preservar el
     * orden actual (orderBy/orderDir como hidden).
     * @param string[]|null $filterableFields columnas permitidas en filtro simple y avanzado; null = todas las visibles
     * @param array<int, array{field: string, op: string, value: mixed, conn: string}> $advancedFilters filas activas, para re-pintar el panel
     */
    /**
     * Solo busqueda global + botones (Filtrar/Limpiar/Filtro avanzado) + el
     * modal del filtro avanzado. Los filtros POR COLUMNA ya no viven aqui —
     * se renderizan dentro de la propia tabla (ver renderColumnFilterRow()),
     * asociados a ESTE <form> via el atributo form="appycrud-filter-form"
     * (la tabla se reemplaza entera por AJAX en cada filtrado/orden, asi que
     * sus inputs no pueden ser descendientes reales de este <form>, que vive
     * fuera de #appycrud-list-body y por eso sobrevive a esos reemplazos).
     */
    private function renderFilterRow(TableSchema $schema, string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir, bool $filtersEnabled, bool $searchEnabled, ?array $filterableFields = null, array $advancedFilters = [], array $referenceOptions = []): string
    {
        $t = $this->translator;

        $filterableColumns = array_values(array_filter(
            $schema->visibleColumns(),
            fn (Column $c) => $filterableFields === null || in_array($c->name, $filterableFields, true),
        ));

        $searchField = $searchEnabled
            ? '<input type="text" name="q" value="' . $this->e($search) . '" placeholder="' . $this->e($t->t('list.search_placeholder')) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm min-w-[10rem]">'
            : '';

        $orderHidden = $orderBy !== ''
            ? '<input type="hidden" name="orderBy" value="' . $this->e($orderBy) . '"><input type="hidden" name="orderDir" value="' . $this->e($orderDir) . '">'
            : '';

        $advancedPanel = $filtersEnabled ? $this->renderAdvancedFilterPanel($filterableColumns, $advancedFilters, $referenceOptions) : '';
        $advancedToggle = $filtersEnabled
            ? '<button type="button" onclick="appycrudOpenAdvancedFilter()" class="inline-flex items-center gap-1.5 text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-1.5 hover:bg-gray-50">' . $this->icon('sliders') . '<span>' . $this->e($t->t('list.advanced_filter')) . '</span></button>'
            : '';

        return <<<HTML
        <form id="appycrud-filter-form" method="get" action="{$this->e($baseUrl)}" class="mb-4 print:hidden" oninput="appycrudScheduleFilter(this)" onchange="appycrudScheduleFilter(this)" onsubmit="return appycrudSubmitFilters(event, this)">
            <div class="flex flex-wrap items-center gap-2">
                {$searchField}
                {$orderHidden}
                <button type="submit" class="inline-flex items-center gap-1.5 bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm hover:bg-gray-900">{$this->icon('search')}<span>{$this->e($t->t('list.filter_apply'))}</span></button>
                <a href="{$this->e($baseUrl)}" class="inline-flex items-center gap-1.5 bg-red-50 text-red-700 border border-red-200 px-3 py-1.5 rounded-md text-sm hover:bg-red-100">{$this->icon('x-circle')}<span>{$this->e($t->t('list.filter_clear'))}</span></a>
                {$advancedToggle}
            </div>
            {$advancedPanel}
        </form>
        HTML;
    }

    /**
     * Panel oculto por default con filas dinamicas campo+operador+valor,
     * cada una (salvo la primera) precedida por un selector AND/OR. Se
     * combinan de izquierda a derecha (ver CrudRepository::buildAdvancedFilterSql()).
     * Las filas viven en el mismo <form> que el filtro simple: al aplicar,
     * appycrudApplyFilters() junta TODO el form (FormData) en la querystring.
     * @param Column[] $filterableColumns
     * @param array<int, array{field: string, op: string, value: mixed, conn: string}> $activeRows
     */
    private const ADVANCED_FILTER_OPERATOR_LABELS = [
        'eq' => 'list.op_eq', 'neq' => 'list.op_neq',
        'contains' => 'list.op_contains', 'not_contains' => 'list.op_not_contains',
        'gt' => 'list.op_gt', 'gte' => 'list.op_gte', 'lt' => 'list.op_lt', 'lte' => 'list.op_lte',
        'is_null' => 'list.op_is_null', 'is_not_null' => 'list.op_is_not_null',
    ];

    /**
     * @param Column[] $filterableColumns
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions
     */
    private function renderAdvancedFilterPanel(array $filterableColumns, array $activeRows, array $referenceOptions = []): string
    {
        $t = $this->translator;

        // Catalogo campo (FK) -> opciones, para que appycrudUpdateFilterValueControl()
        // sepa cuando debe mostrar un <select> en vez de un <input> de texto —
        // mismo motivo que en renderColumnFilterRow(): filtrar una FK compara
        // por igualdad contra el id, no contra el texto visible.
        $referenceCatalog = [];
        foreach ($filterableColumns as $column) {
            if ($column->reference !== null) {
                $referenceCatalog[$column->name] = array_map(
                    fn ($o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']],
                    $referenceOptions[$column->name] ?? [],
                );
            }
        }
        $referenceCatalogJson = $this->e(json_encode($referenceCatalog, JSON_UNESCAPED_UNICODE) ?: '{}');

        // Plantilla para filas agregadas por JS (sin valores activos, sin conector visible);
        // va en un <template> para que appycrudAddFilterRow() la clone sin depender de un fetch.
        $templateRow = $this->renderAdvancedFilterRow($filterableColumns, ['field' => '', 'op' => 'contains', 'value' => '', 'conn' => 'AND'], false, $referenceOptions);

        // Si no hay filas activas (primera vez que se abre), se arranca con una
        // fila vacia visible en vez de un panel en blanco sin nada que editar.
        $initialRows = $activeRows === [] ? [['field' => '', 'op' => 'contains', 'value' => '', 'conn' => 'AND']] : $activeRows;

        $rowsHtml = '';
        foreach ($initialRows as $index => $row) {
            $rowsHtml .= $this->renderAdvancedFilterRow($filterableColumns, $row, $index > 0, $referenceOptions);
        }

        $addLabel = $this->e($t->t('list.advanced_filter_add_row'));
        $applyLabel = $this->e($t->t('list.filter_apply'));
        $titleLabel = $this->e($t->t('list.advanced_filter'));

        return <<<HTML
        <dialog id="appycrud-advanced-filter" class="rounded-lg shadow-xl p-0 w-full max-w-3xl !max-h-[85vh] !overflow-y-auto backdrop:bg-black/50">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-gray-900">{$titleLabel}</h2>
                    <button type="button" onclick="appycrudCloseAdvancedFilter()" class="text-gray-400 hover:text-gray-700">{$this->icon('x')}</button>
                </div>
                <template id="appycrud-af-row-template">{$templateRow}</template>
                <div id="appycrud-advanced-filter-rows" class="space-y-3" data-reference-catalog="{$referenceCatalogJson}">{$rowsHtml}</div>
                <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-gray-100">
                    <button type="button" onclick="appycrudAddFilterRow()" class="inline-flex items-center gap-1.5 bg-blue-600 text-white px-3 py-1.5 rounded-md text-sm font-medium hover:bg-blue-700">{$this->icon('plus')}<span>{$addLabel}</span></button>
                    <button type="button" onclick="appycrudApplyAdvancedFilter()" class="inline-flex items-center gap-1.5 bg-gray-800 text-white px-4 py-2 rounded-md text-sm hover:bg-gray-900">{$this->icon('search')}<span>{$applyLabel}</span></button>
                </div>
            </div>
        </dialog>
        HTML;
    }

    /**
     * @param Column[] $filterableColumns
     * @param array{field: string, op: string, value: mixed, conn: string} $row
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions
     */
    private function renderAdvancedFilterRow(array $filterableColumns, array $row, bool $showConnector, array $referenceOptions = []): string
    {
        $t = $this->translator;

        $connSelect = '<select name="af_conn[]" class="border border-gray-300 rounded-md px-2 py-2 text-sm bg-white w-20 shrink-0' . ($showConnector ? '' : ' invisible') . '">'
            . '<option value="AND"' . ($row['conn'] === 'AND' ? ' selected' : '') . '>' . $this->e($t->t('list.conn_and')) . '</option>'
            . '<option value="OR"' . ($row['conn'] === 'OR' ? ' selected' : '') . '>' . $this->e($t->t('list.conn_or')) . '</option>'
            . '</select>';

        $fieldColumn = null;
        $fieldSelect = '<select name="af_field[]" class="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white flex-1 min-w-[9rem]" onchange="appycrudUpdateFilterValueControl(this.closest(\'.appycrud-af-row\'))"><option value="">--</option>';
        foreach ($filterableColumns as $column) {
            $selected = $column->name === $row['field'] ? ' selected' : '';
            if ($selected !== '') {
                $fieldColumn = $column;
            }
            $fieldSelect .= '<option value="' . $this->e($column->name) . '"' . $selected . '>' . $this->e($column->label) . '</option>';
        }
        $fieldSelect .= '</select>';

        $opSelect = '<select name="af_op[]" class="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white flex-1 min-w-[10rem]" onchange="appycrudUpdateFilterValueControl(this.closest(\'.appycrud-af-row\'))">';
        foreach (self::ADVANCED_FILTER_OPERATOR_LABELS as $value => $labelKey) {
            $selected = $value === $row['op'] ? ' selected' : '';
            $opSelect .= '<option value="' . $value . '"' . $selected . '>' . $this->e($t->t($labelKey)) . '</option>';
        }
        $opSelect .= '</select>';

        $valueHidden = in_array($row['op'], ['is_null', 'is_not_null'], true) ? ' style="display:none"' : '';
        $valueControl = ($fieldColumn !== null && $fieldColumn->reference !== null)
            ? $this->renderAdvancedFilterValueSelect((string) $row['value'], $referenceOptions[$fieldColumn->name] ?? [], $valueHidden)
            : '<input type="text" name="af_value[]" value="' . $this->e((string) $row['value']) . '" class="border border-gray-300 rounded-md px-3 py-2 text-sm flex-1 min-w-[9rem]"' . $valueHidden . '>';

        return '<div class="flex flex-wrap items-center gap-2 appycrud-af-row">'
            . $connSelect . $fieldSelect . $opSelect . $valueControl
            . '<button type="button" onclick="appycrudRemoveFilterRow(this)" class="shrink-0 text-gray-400 hover:text-red-600 p-1" title="' . $this->e($t->t('list.advanced_filter_remove_row')) . '">' . $this->icon('x') . '</button>'
            . '</div>';
    }

    /** @param array<int, array{value: mixed, label: string}> $options */
    private function renderAdvancedFilterValueSelect(string $currentValue, array $options, string $hiddenAttr): string
    {
        $html = '<select name="af_value[]" class="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white flex-1 min-w-[9rem]"' . $hiddenAttr . '><option value="">--</option>';
        foreach ($options as $option) {
            $optionValue = (string) $option['value'];
            $html .= '<option value="' . $this->e($optionValue) . '"' . ($currentValue === $optionValue ? ' selected' : '') . '>' . $this->e((string) $option['label']) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Dialogs nativos (formulario + confirmacion), estilos de efectos y JS
     * vanilla; se generan una sola vez por pagina de listado. renderForm()/
     * renderView() asumen que este shell ya esta presente (usan sus funciones
     * globales).
     */
    private function renderModalShell(string $csrfToken = ''): string
    {
        $cancelLabel = $this->e($this->translator->t('form.cancel'));
        $defaultAcceptLabel = $this->e($this->translator->t('confirm.accept'));
        $csrfTokenJs = $this->jsString($csrfToken);
        $refSearchNoResultsJs = $this->jsString($this->translator->t('list.search_no_results'));

        return <<<HTML
        <style>
        @keyframes appycrud-fade-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .appycrud-fade-in { animation: appycrud-fade-in .25s ease-out; }
        dialog[open] { animation: appycrud-pop .18s ease-out; }
        dialog[open]::backdrop { animation: appycrud-backdrop-fade .18s ease-out; }
        @keyframes appycrud-pop { from { opacity: 0; transform: scale(.95) translateY(-8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        @keyframes appycrud-backdrop-fade { from { opacity: 0; } to { opacity: 1; } }
        </style>
        <dialog id="appycrud-dialog" class="rounded-lg shadow-xl p-0 w-full max-w-2xl !max-h-[85vh] !overflow-y-auto backdrop:bg-black/50">
            <div id="appycrud-dialog-content"></div>
        </dialog>
        <dialog id="appycrud-confirm-dialog" class="rounded-lg shadow-xl p-6 w-full max-w-sm backdrop:bg-black/50">
            <p id="appycrud-confirm-message" class="text-sm text-gray-700 mb-5"></p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="appycrudCancelConfirm()" class="px-4 py-2 text-sm text-gray-600 hover:underline">{$cancelLabel}</button>
                <button type="button" id="appycrud-confirm-accept-btn" data-default-label="{$defaultAcceptLabel}" onclick="appycrudAcceptConfirm()" class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">{$defaultAcceptLabel}</button>
            </div>
        </dialog>
        <script>
        var appycrudCsrfToken = {$csrfTokenJs};

        function appycrudOpenModal(url) {
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    document.getElementById('appycrud-dialog-content').innerHTML = html;
                    document.getElementById('appycrud-dialog').showModal();
                });
        }
        function appycrudCloseModal() {
            document.getElementById('appycrud-dialog').close();
        }
        function appycrudDismissUpdateBanner(version) {
            try { localStorage.setItem('appycrud_update_dismissed', version); } catch (e) {}
            var el = document.getElementById('appycrud-update-banner');
            if (el) el.remove();
        }

        function appycrudTogglePassword(button) {
            var input = button.previousElementSibling;
            input.type = input.type === 'password' ? 'text' : 'password';
        }

        function appycrudRichTextExec(editorId, command) {
            document.getElementById(editorId).focus();
            document.execCommand(command, false, null);
            appycrudRichTextSync(editorId);
        }

        function appycrudRichTextSync(editorId) {
            var editor = document.getElementById(editorId);
            document.getElementById(editorId + '-input').value = editor.innerHTML;
        }

        function appycrudRichTextFormatBlock(editorId, tag) {
            document.getElementById(editorId).focus();
            document.execCommand('formatBlock', false, tag ? '<' + tag + '>' : '<p>');
            appycrudRichTextSync(editorId);
        }

        function appycrudRichTextLink(editorId) {
            var editor = document.getElementById(editorId);
            editor.focus();
            var url = window.prompt('URL:', 'https://');
            if (url) { document.execCommand('createLink', false, url); }
            appycrudRichTextSync(editorId);
        }

        function appycrudSyncDatalist(input) {
            var hidden = input.nextElementSibling;
            var list = document.getElementById(input.getAttribute('list'));
            var match = null;
            list.querySelectorAll('option').forEach(function (opt) {
                if (opt.value === input.value) { match = opt.getAttribute('data-value'); }
            });
            hidden.value = match !== null ? match : '';
        }

        function appycrudUpdateMultiselectCount(select) {
            var caption = document.querySelector('[data-multiselect-count="' + select.id + '"]');
            if (caption) { caption.textContent = select.selectedOptions.length + caption.textContent.replace(/^\d+/, ''); }
        }

        // --- Combobox de seleccion multiple "select2" (multiselect_searchable) ---

        function appycrudSelect2FocusInput(box) {
            box.querySelector('.appycrud-select2-input').focus();
        }

        function appycrudSelect2Open(input) {
            var dropdown = input.closest('.appycrud-select2').querySelector('.appycrud-select2-dropdown');
            document.querySelectorAll('.appycrud-select2-dropdown').forEach(function (d) {
                if (d !== dropdown) { d.classList.add('hidden'); }
            });
            dropdown.classList.remove('hidden');
        }

        var appycrudSelect2CloseIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3"><path d="M18 6 6 18M6 6l12 12" /></svg>';

        function appycrudSelect2Filter(input) {
            appycrudSelect2Open(input);
            var term = input.value.toLowerCase();
            var dropdown = input.closest('.appycrud-select2').querySelector('.appycrud-select2-dropdown');
            dropdown.querySelectorAll('.appycrud-select2-option').forEach(function (opt) {
                var alreadySelected = opt.dataset.selected === '1';
                var matches = !alreadySelected && opt.dataset.label.toLowerCase().indexOf(term) !== -1;
                opt.classList.toggle('hidden', !matches);
            });
        }

        function appycrudSelect2Select(optionButton) {
            var wrap = optionButton.closest('.appycrud-select2');
            var name = wrap.dataset.name;
            var value = optionButton.dataset.value;
            var label = optionButton.dataset.label;

            var chip = document.createElement('span');
            chip.className = 'appycrud-select2-chip inline-flex items-center gap-1 bg-blue-100 text-blue-800 text-xs rounded px-2 py-1';
            chip.dataset.value = value;
            chip.innerHTML = '<span></span><input type="hidden"><button type="button" class="text-blue-600 hover:text-blue-900" onclick="appycrudSelect2Remove(this)">' + appycrudSelect2CloseIconSvg + '</button>';
            chip.querySelector('span').textContent = label;
            var hidden = chip.querySelector('input');
            hidden.name = name + '[]';
            hidden.value = value;

            wrap.querySelector('.appycrud-select2-chips').appendChild(chip);
            optionButton.classList.add('hidden');
            optionButton.dataset.selected = '1';

            var input = wrap.querySelector('.appycrud-select2-input');
            input.value = '';
            input.focus();
        }

        function appycrudSelect2Remove(removeButton) {
            var chip = removeButton.closest('.appycrud-select2-chip');
            var wrap = chip.closest('.appycrud-select2');
            var value = chip.dataset.value;

            wrap.querySelectorAll('.appycrud-select2-option').forEach(function (opt) {
                if (opt.dataset.value === value) {
                    opt.classList.remove('hidden');
                    opt.dataset.selected = '0';
                }
            });

            chip.remove();
        }

        // --- Combobox buscable con backend real (referencias grandes) ---

        var appycrudRefSearchNoResults = {$refSearchNoResultsJs};
        var appycrudRefSearchTimer = null;

        function appycrudRefSearchInput(input) {
            var wrap = input.closest('.appycrud-ref-search');
            var dropdown = wrap.querySelector('.appycrud-ref-search-dropdown');
            var term = input.value;

            if (term === '') {
                wrap.querySelector('input[type="hidden"]').value = '';
            }

            document.querySelectorAll('.appycrud-ref-search-dropdown').forEach(function (d) {
                if (d !== dropdown) { d.classList.add('hidden'); }
            });

            clearTimeout(appycrudRefSearchTimer);
            appycrudRefSearchTimer = setTimeout(function () {
                fetch(wrap.dataset.url + encodeURIComponent(term))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        dropdown.innerHTML = '';
                        var options = data.options || [];
                        if (options.length === 0) {
                            dropdown.innerHTML = '<div class="px-3 py-1.5 text-sm text-gray-400">' + appycrudRefSearchNoResults + '</div>';
                        }
                        options.forEach(function (opt) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'appycrud-ref-search-option w-full text-left px-3 py-1.5 text-sm hover:bg-blue-50';
                            btn.textContent = opt.label;
                            btn.dataset.value = opt.value;
                            btn.dataset.label = opt.label;
                            btn.onclick = function () { appycrudRefSearchSelect(btn); };
                            dropdown.appendChild(btn);
                        });
                        dropdown.classList.remove('hidden');
                    });
            }, 250);
        }

        function appycrudRefSearchSelect(optionButton) {
            var wrap = optionButton.closest('.appycrud-ref-search');
            var input = wrap.querySelector('.appycrud-ref-search-input');
            var hidden = wrap.querySelector('input[type="hidden"]');
            input.value = optionButton.dataset.label;
            hidden.value = optionButton.dataset.value;
            wrap.querySelector('.appycrud-ref-search-dropdown').classList.add('hidden');
        }

        function appycrudSubmitForm(event, form) {
            event.preventDefault();
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (response) {
                    if (response.status === 422) {
                        return response.text().then(function (html) {
                            document.getElementById('appycrud-dialog-content').innerHTML = html;
                        });
                    }
                    window.location.reload();
                });
            return false;
        }

        function appycrudToggleAll(checkbox) {
            document.querySelectorAll('.appycrud-row-check').forEach(function (c) {
                c.checked = checkbox.checked;
            });
            appycrudUpdateBulkUI();
        }

        var appycrudFilterTimer = null;

        function appycrudScheduleFilter(form) {
            clearTimeout(appycrudFilterTimer);
            appycrudFilterTimer = setTimeout(function () { appycrudApplyFilters(form); }, 500);
        }

        function appycrudSubmitFilters(event, form) {
            if (event) { event.preventDefault(); }
            clearTimeout(appycrudFilterTimer);
            appycrudApplyFilters(form);
            return false;
        }

        function appycrudApplyFilters(form) {
            var params = new URLSearchParams(new FormData(form));
            var query = params.toString();
            var url = form.getAttribute('action') + (query ? '?' + query : '');

            history.replaceState(null, '', url);

            var loading = document.getElementById('appycrud-list-loading');
            // Pequeno retraso antes de mostrar el spinner: en tablas chicas la
            // respuesta llega en unos ms y el spinner solo parpadearia sin
            // aportar nada; en tablas grandes, donde de verdad se nota la
            // espera, el usuario ya lo ve para entonces.
            var showTimer = setTimeout(function () { if (loading) { loading.classList.remove('hidden'); } }, 200);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    document.getElementById('appycrud-list-body').innerHTML = html;
                })
                .finally(function () {
                    clearTimeout(showTimer);
                    if (loading) { loading.classList.add('hidden'); }
                });
        }

        function appycrudOpenAdvancedFilter() {
            document.getElementById('appycrud-advanced-filter').showModal();
        }

        function appycrudCloseAdvancedFilter() {
            document.getElementById('appycrud-advanced-filter').close();
        }

        function appycrudApplyAdvancedFilter() {
            var dialog = document.getElementById('appycrud-advanced-filter');
            appycrudSubmitFilters(null, dialog.closest('form'));
            dialog.close();
        }

        function appycrudAddFilterRow() {
            var template = document.getElementById('appycrud-af-row-template');
            var rows = document.getElementById('appycrud-advanced-filter-rows');
            var clone = template.content.cloneNode(true);
            if (rows.children.length > 0) {
                clone.querySelector('select[name="af_conn[]"]').classList.remove('invisible');
            }
            rows.appendChild(clone);
        }

        function appycrudRemoveFilterRow(button) {
            var row = button.closest('.appycrud-af-row');
            var rows = document.getElementById('appycrud-advanced-filter-rows');
            row.remove();
            var first = rows.querySelector('.appycrud-af-row');
            if (first) {
                var conn = first.querySelector('select[name="af_conn[]"]');
                if (conn) { conn.classList.add('invisible'); }
            }
        }

        // Reconstruye el control de valor de una fila del filtro avanzado segun
        // el campo y operador elegidos: <select> con las opciones reales si el
        // campo es una llave foranea (filtrar una FK compara por igualdad
        // contra el id, no contra el texto visible — ver renderColumnFilterRow()
        // para el mismo caso en el filtro simple), <input> de texto en cualquier
        // otro caso, y oculto del todo para los operadores "es/no es vacio".
        function appycrudUpdateFilterValueControl(row) {
            var fieldSelect = row.querySelector('select[name="af_field[]"]');
            var opSelect = row.querySelector('select[name="af_op[]"]');
            var current = row.querySelector('[name="af_value[]"]');
            var catalog = JSON.parse(document.getElementById('appycrud-advanced-filter-rows').dataset.referenceCatalog || '{}');
            var options = catalog[fieldSelect.value];
            var hide = opSelect.value === 'is_null' || opSelect.value === 'is_not_null';
            var currentValue = current.value;

            var replacement;
            if (options) {
                replacement = document.createElement('select');
                replacement.className = 'border border-gray-300 rounded-md px-3 py-2 text-sm bg-white flex-1 min-w-[9rem]';
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '--';
                replacement.appendChild(blank);
                options.forEach(function (opt) {
                    var el = document.createElement('option');
                    el.value = opt.value;
                    el.textContent = opt.label;
                    if (opt.value === currentValue) { el.selected = true; }
                    replacement.appendChild(el);
                });
            } else if (current.tagName === 'SELECT') {
                replacement = document.createElement('input');
                replacement.type = 'text';
                replacement.className = 'border border-gray-300 rounded-md px-3 py-2 text-sm flex-1 min-w-[9rem]';
                replacement.value = currentValue;
            } else {
                replacement = current;
            }

            replacement.name = 'af_value[]';
            replacement.style.display = hide ? 'none' : '';

            if (replacement !== current) {
                current.replaceWith(replacement);
            }
        }

        function appycrudUpdateBulkUI() {
            var btn = document.getElementById('appycrud-bulk-delete-btn');
            if (!btn) { return; }
            var checked = document.querySelectorAll('.appycrud-row-check:checked').length;
            if (checked > 0) {
                btn.classList.remove('hidden');
                btn.classList.add('flex');
                btn.querySelector('.appycrud-bulk-count').textContent = ' (' + checked + ')';
            } else {
                btn.classList.add('hidden');
                btn.classList.remove('flex');
            }
        }

        function appycrudToggleMenu(button) {
            var menu = button.nextElementSibling;
            document.querySelectorAll('.appycrud-menu').forEach(function (m) {
                if (m !== menu) { m.classList.add('hidden'); }
            });

            var opening = menu.classList.contains('hidden');
            menu.classList.toggle('hidden');

            if (opening) {
                // position: fixed (calculado desde el boton) para que el menu
                // no quede recortado por el overflow-x-auto de la tabla.
                var rect = button.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.top = (rect.bottom + 4) + 'px';
                menu.style.left = 'auto';
                menu.style.right = (window.innerWidth - rect.right) + 'px';
                menu.style.zIndex = '9999';
            }
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.appycrud-menu-wrap')) {
                document.querySelectorAll('.appycrud-menu').forEach(function (m) { m.classList.add('hidden'); });
            }
            if (!e.target.closest('.appycrud-select2')) {
                document.querySelectorAll('.appycrud-select2-dropdown').forEach(function (d) { d.classList.add('hidden'); });
            }
            if (!e.target.closest('.appycrud-ref-search')) {
                document.querySelectorAll('.appycrud-ref-search-dropdown').forEach(function (d) { d.classList.add('hidden'); });
            }
        });

        var appycrudPendingAction = null;

        function appycrudConfirmAction(message, action, label) {
            appycrudPendingAction = action;
            document.getElementById('appycrud-confirm-message').textContent = message;
            var btn = document.getElementById('appycrud-confirm-accept-btn');
            btn.textContent = label || btn.getAttribute('data-default-label');
            document.getElementById('appycrud-confirm-dialog').showModal();
        }

        function appycrudCancelConfirm() {
            appycrudPendingAction = null;
            document.getElementById('appycrud-confirm-dialog').close();
        }

        function appycrudAcceptConfirm() {
            if (!appycrudPendingAction) {
                return;
            }

            var action = appycrudPendingAction;
            appycrudPendingAction = null;
            document.getElementById('appycrud-confirm-dialog').close();
            action();
        }

        function appycrudConfirmSubmit(event, form, message, label) {
            event.preventDefault();
            appycrudConfirmAction(message, function () {
                fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
                    .then(function () { window.location.reload(); });
            }, label);
            return false;
        }

        function appycrudBulkDelete(url, message, requireConfirm, label) {
            var ids = Array.prototype.map.call(document.querySelectorAll('.appycrud-row-check:checked'), function (c) { return c.value; });

            if (ids.length === 0) {
                return;
            }

            var run = function () {
                var body = new FormData();
                ids.forEach(function (id) { body.append('ids[]', id); });
                if (appycrudCsrfToken) { body.append('csrf_token', appycrudCsrfToken); }
                fetch(url, { method: 'POST', body: body })
                    .then(function () { window.location.reload(); });
            };

            if (requireConfirm) {
                appycrudConfirmAction(message.replace('__COUNT__', ids.length), run, label);
            } else {
                run();
            }
        }
        </script>
        HTML;
    }

    /**
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions columna => opciones del select
     * @param array<string, string[]> $errors columna => mensajes de error
     */
    /**
     * @param string[]|null $fieldsWhitelist si no es null, solo estas columnas aparecen en el formulario
     * @param array<int, array{name: string, label: string, options: array<int, array{value: mixed, label: string}>, selected: string[], inputType: string}> $manyToMany
     *   relaciones muchos-a-muchos, renderizadas como multiselect adicional (ver Crud\ManyToMany)
     */
    public function renderForm(TableSchema $schema, array $values, string $baseUrl, bool $isEdit, array $referenceOptions = [], array $errors = [], string $csrfToken = '', string $generalError = '', ?array $fieldsWhitelist = null, array $manyToMany = []): string
    {
        $t = $this->translator;
        $pk = $schema->primaryKey();
        $title = $isEdit ? $t->t('form.edit_title') : $t->t('form.create_title');
        $action = $isEdit ? 'update' : 'store';
        $pkValue = $pk !== null ? ($values[$pk->name] ?? '') : '';

        $fields = '';
        $hasFileField = false;
        $hasRequiredField = false;
        foreach ($schema->visibleColumns() as $column) {
            if ($column->isPrimaryKey || $column->readOnly) {
                continue;
            }

            if ($fieldsWhitelist !== null && !in_array($column->name, $fieldsWhitelist, true)) {
                continue;
            }

            if (FieldType::isFile($column->inputType ?? '')) {
                $hasFileField = true;
            }

            if (!$column->nullable && FieldType::strategy($column->inputType ?? '') !== FieldType::STRATEGY_CHECKBOX) {
                $hasRequiredField = true;
            }

            $fields .= $this->renderField(
                $column,
                (string) ($values[$column->name] ?? ''),
                $referenceOptions[$column->name] ?? [],
                $errors[$column->name] ?? [],
                $baseUrl,
            );
        }

        foreach ($manyToMany as $relation) {
            $fields .= $this->renderManyToManyField($relation);
        }

        $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';
        $generalErrorHtml = $generalError !== ''
            ? '<div class="rounded-md bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">' . $this->e($generalError) . '</div>'
            : '';
        $enctype = $hasFileField ? ' enctype="multipart/form-data"' : '';
        $requiredLegend = $hasRequiredField
            ? '<p class="text-xs text-gray-500 mb-3"><span class="text-red-500">*</span> ' . $this->e($t->t('form.required_mark')) . '</p>'
            : '';

        return <<<HTML
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($title)}</h1>
            {$generalErrorHtml}
            {$requiredLegend}
            <form method="post" action="{$this->e($baseUrl)}?action={$action}&id={$this->e((string) $pkValue)}" class="space-y-4"{$enctype} onsubmit="return appycrudSubmitForm(event, this)">
                {$csrfField}
                {$fields}
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="appycrudCloseModal()" class="px-4 py-2 text-sm text-gray-600 hover:underline">{$this->e($t->t('form.cancel'))}</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">{$this->e($t->t('form.save'))}</button>
                </div>
            </form>
        </div>
        HTML;
    }

    /**
     * Vista de solo lectura de un registro, con boton para imprimirlo en
     * una pestana nueva (renderPrintDocument via ?action=print).
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions
     */
    /**
     * @param array<int, array{label: string, values: string[]}> $manyToMany relaciones muchos-a-muchos ya resueltas a sus labels
     */
    public function renderView(TableSchema $schema, array $values, string $baseUrl, string $id, array $referenceOptions = [], array $manyToMany = [], ?string $uploadUrlPrefix = null): string
    {
        $t = $this->translator;

        $referenceLabels = [];
        foreach ($referenceOptions as $columnName => $options) {
            foreach ($options as $option) {
                $referenceLabels[$columnName][(string) $option['value']] = (string) $option['label'];
            }
        }
        // Los dropdown/enum estaticos (Column::$options, sin reference a otra tabla)
        // tambien resuelven su label, igual que una relacion.
        foreach ($schema->columns() as $column) {
            if ($column->reference !== null || $column->options === []) {
                continue;
            }

            foreach ($column->options as $option) {
                $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
            }
        }

        $rows = '';
        foreach ($schema->visibleColumns() as $column) {
            $rawValue = (string) ($values[$column->name] ?? '');
            $displayValue = ($column->reference !== null || $column->options !== [])
                ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                : $rawValue;

            $ddContent = match (true) {
                FieldType::isFile($column->inputType ?? '') => $rawValue !== '' ? $this->renderFileCell($rawValue, $uploadUrlPrefix) : '—',
                // El HTML ya fue sanitizado al guardar (ver AppyCrud::normalizeRichTextFields()/HtmlSanitizer)
                // — se renderiza tal cual, sin escapar, para que negrita/listas/etc se vean formateadas.
                FieldType::isRichText($column->inputType ?? '') => $displayValue !== '' ? $displayValue : '—',
                default => $this->e($displayValue !== '' ? $displayValue : '—'),
            };

            $rows .= '<div class="py-2 border-b border-gray-100">'
                . '<dt class="text-xs font-semibold uppercase text-gray-500">' . $this->e($column->label) . '</dt>'
                . '<dd class="text-sm text-gray-900 mt-0.5">' . $ddContent . '</dd>'
                . '</div>';
        }

        foreach ($manyToMany as $relation) {
            $displayValue = $relation['values'] === [] ? '—' : implode(', ', $relation['values']);
            $rows .= '<div class="py-2 border-b border-gray-100">'
                . '<dt class="text-xs font-semibold uppercase text-gray-500">' . $this->e($relation['label']) . '</dt>'
                . '<dd class="text-sm text-gray-900 mt-0.5">' . $this->e($displayValue) . '</dd>'
                . '</div>';
        }

        $printUrl = $this->e($baseUrl) . '?action=print&id=' . $this->e($id);

        return <<<HTML
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($t->t('view.title'))}</h1>
            <dl>{$rows}</dl>
            <div class="flex justify-end gap-3 pt-4">
                <a href="{$printUrl}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">{$this->icon('printer')}<span>{$this->e($t->t('view.print'))}</span></a>
                <button type="button" onclick="appycrudCloseModal()" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">{$this->e($t->t('view.close'))}</button>
            </div>
        </div>
        HTML;
    }

    /**
     * Documento HTML standalone (sin depender del CSS del resto de la app)
     * pensado para abrirse en una pestana nueva e imprimirse automaticamente.
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions
     */
    public function renderPrintDocument(TableSchema $schema, array $values, array $referenceOptions = []): string
    {
        $referenceLabels = [];
        foreach ($referenceOptions as $columnName => $options) {
            foreach ($options as $option) {
                $referenceLabels[$columnName][(string) $option['value']] = (string) $option['label'];
            }
        }
        // Los dropdown/enum estaticos (Column::$options, sin reference a otra tabla)
        // tambien resuelven su label, igual que una relacion.
        foreach ($schema->columns() as $column) {
            if ($column->reference !== null || $column->options === []) {
                continue;
            }

            foreach ($column->options as $option) {
                $referenceLabels[$column->name][(string) $option['value']] = (string) $option['label'];
            }
        }

        $rows = '';
        foreach ($schema->visibleColumns() as $column) {
            $rawValue = (string) ($values[$column->name] ?? '');
            $displayValue = ($column->reference !== null || $column->options !== [])
                ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                : $rawValue;

            $rows .= '<tr><th>' . $this->e($column->label) . '</th><td>' . $this->e($displayValue) . '</td></tr>';
        }

        $title = $this->e($schema->table);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
        <meta charset="UTF-8">
        <title>{$title}</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 2rem; color: #111; }
            table { width: 100%; border-collapse: collapse; }
            th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #ddd; }
            th { width: 220px; color: #555; font-weight: 600; }
        </style>
        <script>window.onload = function () { window.print(); };</script>
        </head>
        <body>
            <h1>{$title}</h1>
            <table>{$rows}</table>
        </body>
        </html>
        HTML;
    }

    /**
     * @param array<int, array{value: mixed, label: string}> $options
     * @param string[] $errorMessages
     */
    /**
     * @param array<int, array{value: mixed, label: string}> $options opciones para reference/dropdown/enum/multiselect
     *   (si $column->reference !== null vienen de la tabla referenciada; si no, de $column->options)
     * @param string[] $errorMessages
     */
    /**
     * @param array{name: string, label: string, options: array<int, array{value: mixed, label: string}>, selected: string[], inputType: string} $relation
     */
    private function renderManyToManyField(array $relation): string
    {
        $name = 'm2m_' . $this->e($relation['name']);
        $label = '<label class="block text-sm font-medium text-gray-700 mb-1">' . $this->e($relation['label']) . '</label>';
        $selectedCsv = implode(',', $relation['selected']);
        $class = 'w-full border border-gray-300 rounded-md px-3 py-2 text-sm';

        $input = FieldType::strategy($relation['inputType']) === FieldType::STRATEGY_MULTISELECT_SEARCHABLE
            ? $this->renderMultiselectSearchable($name, $selectedCsv, $relation['options'])
            : $this->renderMultiselect($name, $selectedCsv, $relation['options'], $class);

        return '<div>' . $label . $input . '</div>';
    }

    /**
     * A partir de cuantas opciones un <select> comun se vuelve incomodo de
     * usar con el mouse/scroll y conviene auto-promoverlo a buscable — sin
     * que el desarrollador tenga que acordarse de poner inputType =>
     * 'dropdown_search' en cada columna con muchas opciones (referencias a
     * ciudades, por ejemplo, facilmente pasan de cientos de filas).
     */
    private const AUTO_SEARCHABLE_OPTION_THRESHOLD = 8;

    private function renderField(Column $column, string $value, array $options = [], array $errorMessages = [], string $baseUrl = ''): string
    {
        $strategy = FieldType::strategy($column->inputType ?? '');

        // Una llave foranea siempre debe verse como select/combobox, sin importar
        // que inputType haya adivinado la introspeccion sobre la columna cruda
        // (ej. 'number' para un INTEGER) — salvo que el override pida explicitamente
        // otro widget de la misma familia (dropdown_search, multiselect...).
        $selectFamily = [FieldType::STRATEGY_SELECT, FieldType::STRATEGY_SELECT_SEARCHABLE, FieldType::STRATEGY_MULTISELECT, FieldType::STRATEGY_MULTISELECT_SEARCHABLE];
        if ($column->reference !== null && !in_array($strategy, $selectFamily, true)) {
            $strategy = FieldType::STRATEGY_SELECT;
        }

        if ($strategy === FieldType::STRATEGY_INVISIBLE) {
            return '';
        }

        $name = $this->e($column->name);
        $baseClass = 'w-full border border-gray-300 rounded-md px-3 py-2 text-sm';
        $errorClass = $errorMessages !== [] ? ' border-red-400' : '';
        $required = !$column->nullable ? ' required' : '';
        $optionSource = $column->reference !== null ? $options : $column->options;

        // auto-promover a buscable: cubre tanto dropdown/enum como
        // referencias sin inputType explicito (el caso mas comun -- p.ej.
        // ciudad_destino con cientos de filas). Si el desarrollador ya pidio
        // 'dropdown_search' a proposito, $strategy ya viene en
        // STRATEGY_SELECT_SEARCHABLE y esta condicion no aplica.
        if ($strategy === FieldType::STRATEGY_SELECT && count($optionSource) > self::AUTO_SEARCHABLE_OPTION_THRESHOLD) {
            $strategy = FieldType::STRATEGY_SELECT_SEARCHABLE;
        }

        if ($strategy === FieldType::STRATEGY_HIDDEN) {
            return '<input type="hidden" name="' . $name . '" value="' . $this->e($value) . '">';
        }

        // El checkbox no lleva marca de obligatorio: no-nullable en un boolean
        // (0/1) no tiene el mismo sentido de "hay que llenarlo" que en el resto
        // de los tipos — casi siempre viene con un default y da igual dejarlo
        // sin tocar.
        $requiredMark = ($required !== '' && $strategy !== FieldType::STRATEGY_CHECKBOX)
            ? '<span class="text-red-500 ml-0.5" title="' . $this->e($this->translator->t('form.required_mark')) . '">*</span>'
            : '';
        $label = '<label class="block text-sm font-medium text-gray-700 mb-1">' . $this->e($column->label) . $requiredMark . '</label>';

        $input = match ($strategy) {
            FieldType::STRATEGY_TEXTAREA => '<textarea name="' . $name . '" class="' . $baseClass . $errorClass . '" rows="4"' . $required . '>' . $this->e($value) . '</textarea>',
            FieldType::STRATEGY_CHECKBOX => '<input type="checkbox" name="' . $name . '" value="1"' . ($value ? ' checked' : '') . ' class="rounded border-gray-300">',
            FieldType::STRATEGY_INT => $this->renderTextLikeInput('number', $name, $value, $baseClass . $errorClass, $required, $column->maxLength, ['step' => '1']),
            FieldType::STRATEGY_FLOAT => $this->renderTextLikeInput('number', $name, $value, $baseClass . $errorClass, $required, $column->maxLength, ['step' => 'any']),
            FieldType::STRATEGY_DATE => $this->renderTextLikeInput('date', $name, $value, $baseClass . $errorClass, $required, null),
            FieldType::STRATEGY_DATETIME => $this->renderTextLikeInput('datetime-local', $name, $value, $baseClass . $errorClass, $required, null),
            FieldType::STRATEGY_TIME => $this->renderTextLikeInput('time', $name, $value, $baseClass . $errorClass, $required, null),
            FieldType::STRATEGY_EMAIL => $this->renderTextLikeInput('email', $name, $value, $baseClass . $errorClass, $required, $column->maxLength),
            FieldType::STRATEGY_COLOR => '<input type="color" name="' . $name . '" value="' . $this->e($value !== '' ? $value : '#000000') . '" class="h-10 w-16 border border-gray-300 rounded-md">',
            FieldType::STRATEGY_PASSWORD => $this->renderTextLikeInput('password', $name, '', $baseClass . $errorClass, $required, $column->maxLength),
            FieldType::STRATEGY_PASSWORD_TOGGLE => $this->renderPasswordToggle($name, $baseClass . $errorClass, $required, $column->maxLength),
            FieldType::STRATEGY_SELECT => $this->renderSelect($name, $value, $optionSource, $required, $baseClass . $errorClass),
            FieldType::STRATEGY_SELECT_SEARCHABLE => $column->reference !== null
                ? $this->renderSearchableSelectAjax($column, $name, $value, $optionSource, $required, $baseClass . $errorClass, $baseUrl)
                : $this->renderSearchableSelect($column, $name, $value, $optionSource, $required, $baseClass . $errorClass),
            FieldType::STRATEGY_MULTISELECT => $this->renderMultiselect($name, $value, $optionSource, $baseClass . $errorClass),
            FieldType::STRATEGY_MULTISELECT_SEARCHABLE => $this->renderMultiselectSearchable($name, $value, $optionSource),
            FieldType::STRATEGY_FILE => $this->renderFileInput($name, $value, $baseClass . $errorClass, $required !== '' && $value === ''),
            FieldType::STRATEGY_RICHTEXT => $this->renderRichText($name, $value, $errorClass, FieldType::isRichTextAdvanced($column->inputType ?? '')),
            default => $this->renderTextLikeInput('text', $name, $value, $baseClass . $errorClass, $required, $column->maxLength),
        };

        $errorHtml = '';
        foreach ($errorMessages as $message) {
            $errorHtml .= '<p class="mt-1 text-xs text-red-600">' . $this->e($message) . '</p>';
        }

        return '<div>' . $label . $input . $errorHtml . '</div>';
    }

    /** @param array<string,string> $extraAttrs */
    private function renderTextLikeInput(string $type, string $name, string $value, string $class, string $required, ?int $maxLength, array $extraAttrs = []): string
    {
        $attrs = '';
        foreach ($extraAttrs as $attrName => $attrValue) {
            $attrs .= ' ' . $attrName . '="' . $this->e($attrValue) . '"';
        }

        return '<input type="' . $type . '" name="' . $name . '" value="' . $this->e($value) . '" class="' . $class . '"'
            . ($maxLength !== null ? ' maxlength="' . $maxLength . '"' : '')
            . $required . $attrs . '>';
    }

    /**
     * $value trae el nombre del archivo ya guardado (si lo hay, en edicion).
     * Un <input type="file"> nunca se puede prellenar por seguridad del
     * navegador, asi que se muestra como texto informativo aparte; si no se
     * selecciona uno nuevo al guardar, AppyCrud conserva el archivo actual
     * (ver AppyCrud::processFileUploads()).
     */
    private function renderFileInput(string $name, string $value, string $class, bool $required): string
    {
        $input = '<input type="file" name="' . $name . '" class="' . $class . '"' . ($required ? ' required' : '') . '>';

        if ($value === '') {
            return $input;
        }

        $currentLabel = $this->e($this->translator->t('form.current_file', ['file' => $value]));
        $removeLabel = $this->e($this->translator->t('form.remove_file'));
        $removeName = 'remove_' . $name;

        return $input . '<p class="mt-1 text-xs text-gray-500">' . $currentLabel . '</p>'
            . '<label class="mt-1 flex items-center gap-1.5 text-xs text-gray-600">'
            . '<input type="checkbox" name="' . $removeName . '" value="1" class="rounded border-gray-300">'
            . $removeLabel . '</label>';
    }

    /**
     * Editor de texto enriquecido vanilla: un <div contenteditable> con una
     * barra de herramientas (negrita/italica/subrayado/listas — y en modo
     * "avanzado" tambien encabezados/enlaces/alineacion/deshacer-rehacer)
     * via document.execCommand, y un <input type="hidden"> que es lo que
     * realmente viaja en el submit — se sincroniza en cada 'input' del div.
     * $value ya viene sanitizado (ver Crud\HtmlSanitizer::sanitize(), aplicado
     * al guardar), por eso se inyecta tal cual como HTML del contenteditable,
     * no escapado como el resto de los campos de texto.
     *
     * "Avanzado" NO agrega ninguna dependencia externa: sigue siendo
     * document.execCommand puro. Lo unico que cambia es cuantos botones se
     * muestran y que Crud\HtmlSanitizer permite mas etiquetas/atributos
     * (h1-h3, y style="text-align" en p/div) para que el HTML de esos
     * comandos sobreviva la sanitizacion al guardar.
     */
    private function renderRichText(string $name, string $value, string $errorClass, bool $advanced): string
    {
        $editorId = 'appycrud-rt-' . $name;
        $t = $this->translator;

        $execButton = function (string $command, string $icon, string $label) use ($editorId): string {
            return '<button type="button" onmousedown="event.preventDefault()" onclick="appycrudRichTextExec(\'' . $editorId . '\', \'' . $command . '\')" title="' . $this->e($label) . '" class="px-2 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">' . $icon . '</button>';
        };

        $toolbar = $execButton('bold', 'B', $t->t('richtext.bold'))
            . $execButton('italic', 'I', $t->t('richtext.italic'))
            . $execButton('underline', 'U', $t->t('richtext.underline'))
            . $execButton('insertUnorderedList', '&bull; –', $t->t('richtext.bullet_list'))
            . $execButton('insertOrderedList', '1.', $t->t('richtext.numbered_list'));

        if ($advanced) {
            $toolbar .= '<select onmousedown="event.preventDefault()" onchange="appycrudRichTextFormatBlock(\'' . $editorId . '\', this.value); this.value=\'\'" title="' . $this->e($t->t('richtext.heading')) . '" class="px-1 py-1 text-sm border border-gray-300 rounded bg-white">'
                . '<option value="">' . $this->e($t->t('richtext.paragraph')) . '</option>'
                . '<option value="h1">' . $this->e($t->t('richtext.heading')) . ' 1</option>'
                . '<option value="h2">' . $this->e($t->t('richtext.heading')) . ' 2</option>'
                . '<option value="h3">' . $this->e($t->t('richtext.heading')) . ' 3</option>'
                . '</select>'
                . '<button type="button" onmousedown="event.preventDefault()" onclick="appycrudRichTextLink(\'' . $editorId . '\')" title="' . $this->e($t->t('richtext.link')) . '" class="px-2 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">' . $this->icon('link') . '</button>'
                . $execButton('unlink', $this->icon('unlink'), $t->t('richtext.unlink'))
                . $execButton('justifyLeft', $this->icon('align-left'), $t->t('richtext.align_left'))
                . $execButton('justifyCenter', $this->icon('align-center'), $t->t('richtext.align_center'))
                . $execButton('justifyRight', $this->icon('align-right'), $t->t('richtext.align_right'))
                . $execButton('undo', $this->icon('undo'), $t->t('richtext.undo'))
                . $execButton('redo', $this->icon('redo'), $t->t('richtext.redo'));
        }

        return '<div class="flex items-center gap-1 mb-1 flex-wrap">' . $toolbar . '</div>'
            . '<div id="' . $editorId . '" contenteditable="true" oninput="appycrudRichTextSync(\'' . $editorId . '\')" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm min-h-[8rem] max-h-96 overflow-y-auto appycrud-richtext' . $errorClass . '">' . $value . '</div>'
            . '<input type="hidden" name="' . $name . '" id="' . $editorId . '-input" value="' . $this->e($value) . '">';
    }

    private function renderPasswordToggle(string $name, string $class, string $required, ?int $maxLength): string
    {
        return '<div class="relative">'
            . '<input type="password" name="' . $name . '" value="" class="' . $class . ' pr-10"'
            . ($maxLength !== null ? ' maxlength="' . $maxLength . '"' : '')
            . $required . '>'
            . '<button type="button" onclick="appycrudTogglePassword(this)" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">' . $this->icon('eye') . '</button>'
            . '</div>';
    }

    /** @param array<int, array{value: mixed, label: string}> $options */
    private function renderSelect(string $name, string $value, array $options, string $required, string $class): string
    {
        $optionsHtml = '<option value="">&mdash;</option>';
        foreach ($options as $option) {
            $selected = (string) $option['value'] === $value ? ' selected' : '';
            $optionsHtml .= '<option value="' . $this->e((string) $option['value']) . '"' . $selected . '>' . $this->e((string) $option['label']) . '</option>';
        }

        return '<select name="' . $name . '" class="' . $class . ' bg-white"' . $required . '>' . $optionsHtml . '</select>';
    }

    /**
     * Combobox buscable sin JS de terceros: <input list> + <datalist> (nativo
     * del navegador) para filtrar, con un input oculto que guarda el value
     * real (no el label) sincronizado por appycrudSyncDatalist().
     * @param array<int, array{value: mixed, label: string}> $options
     */
    private function renderSearchableSelect(Column $column, string $name, string $value, array $options, string $required, string $class): string
    {
        $listId = 'dl_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column->name);
        $currentLabel = '';

        $datalistOptions = '';
        foreach ($options as $option) {
            $optionValue = (string) $option['value'];
            $optionLabel = (string) $option['label'];

            if ($optionValue === $value) {
                $currentLabel = $optionLabel;
            }

            $datalistOptions .= '<option data-value="' . $this->e($optionValue) . '" value="' . $this->e($optionLabel) . '">';
        }

        return '<input type="text" list="' . $listId . '" value="' . $this->e($currentLabel) . '" class="' . $class . '" oninput="appycrudSyncDatalist(this)" autocomplete="off">'
            . '<input type="hidden" name="' . $name . '" value="' . $this->e($value) . '"' . $required . '>'
            . '<datalist id="' . $listId . '">' . $datalistOptions . '</datalist>';
    }

    /** @param array<int, array{value: mixed, label: string}> $options */
    private function renderMultiselect(string $name, string $value, array $options, string $class): string
    {
        $selected = $value !== '' ? explode(',', $value) : [];

        $optionsHtml = '';
        foreach ($options as $option) {
            $optionValue = (string) $option['value'];
            $isSelected = in_array($optionValue, $selected, true) ? ' selected' : '';
            $optionsHtml .= '<option value="' . $this->e($optionValue) . '"' . $isSelected . '>' . $this->e((string) $option['label']) . '</option>';
        }

        // Altura fija (no crece con la cantidad de opciones, por muchas que
        // sean) — con muchas opciones, el <select> hace scroll interno en vez
        // de estirar el modal completo fuera de la pantalla. El contador de
        // seleccionados (appycrudUpdateMultiselectCount) ayuda a ver de un
        // vistazo cuantas quedaron marcadas sin tener que scrollear la lista.
        $selectId = 'ms_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        return '<select id="' . $selectId . '" name="' . $name . '[]" multiple onchange="appycrudUpdateMultiselectCount(this)" class="' . $class . ' bg-white h-40">' . $optionsHtml . '</select>'
            . '<p class="mt-1 text-xs text-gray-500" data-multiselect-count="' . $selectId . '">' . count($selected) . ' ' . $this->e($this->translator->t('form.multiselect_selected_count')) . '</p>';
    }

    /**
     * Combobox buscable con backend real (a diferencia de renderSearchableSelect,
     * que precarga un top-N de opciones en un <datalist>): cada tecla dispara
     * una busqueda en el servidor (AppyCrud::handleReferenceSearch, filtrado
     * en SQL) con debounce, asi que encuentra cualquier fila de la tabla
     * referenciada sin importar que tan grande sea o en que posicion
     * alfabetica caiga el valor buscado. Vanilla JS, sin dependencias.
     * @param array<int, array{value: mixed, label: string}> $options opciones ya conocidas (usadas solo para resolver el label inicial)
     */
    private function renderSearchableSelectAjax(Column $column, string $name, string $value, array $options, string $required, string $class, string $baseUrl): string
    {
        $currentLabel = '';
        foreach ($options as $option) {
            if ((string) $option['value'] === $value) {
                $currentLabel = (string) $option['label'];
                break;
            }
        }

        $searchUrl = rtrim($baseUrl, '/') . '?action=reference_search&column=' . rawurlencode($column->name) . '&q=';
        $placeholder = $this->e($this->translator->t('list.search_placeholder'));

        return '<div class="appycrud-ref-search relative" data-url="' . $this->e($searchUrl) . '">'
            . '<input type="text" class="appycrud-ref-search-input ' . $class . '" placeholder="' . $placeholder . '" value="' . $this->e($currentLabel) . '" autocomplete="off"'
            . ' oninput="appycrudRefSearchInput(this)" onfocus="appycrudRefSearchInput(this)">'
            . '<input type="hidden" name="' . $name . '" value="' . $this->e($value) . '"' . $required . '>'
            . '<div class="appycrud-ref-search-dropdown hidden absolute z-10 mt-1 w-full max-h-48 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg"></div>'
            . '</div>';
    }

    /**
     * Checkboxes con un filtro de texto arriba (vanilla JS), para cuando el
     * multiselect nativo tiene demasiadas opciones para desplazarse comodo.
     * @param array<int, array{value: mixed, label: string}> $options
     */
    /**
     * Combobox de seleccion multiple al estilo "select2": los valores ya
     * elegidos se muestran como chips removibles dentro de la misma caja de
     * busqueda; escribir filtra un dropdown con las opciones que faltan por
     * elegir (las ya seleccionadas no se repiten en la lista). Cada chip
     * lleva su propio <input type="hidden"> — es lo que realmente viaja en
     * el submit, el combobox visible no tiene "name" propio. Vanilla JS
     * (funciones appycrudSelect2*), sin ninguna libreria de terceros.
     * @param array<int, array{value: mixed, label: string}> $options
     */
    private function renderMultiselectSearchable(string $name, string $value, array $options): string
    {
        $selected = $value !== '' ? explode(',', $value) : [];
        $labelsByValue = [];
        foreach ($options as $option) {
            $labelsByValue[(string) $option['value']] = (string) $option['label'];
        }

        $chips = '';
        foreach ($selected as $selectedValue) {
            if (isset($labelsByValue[$selectedValue])) {
                $chips .= $this->renderSelect2Chip($name, $selectedValue, $labelsByValue[$selectedValue]);
            }
        }

        $dropdownOptions = '';
        foreach ($options as $option) {
            $optionValue = (string) $option['value'];
            $isSelected = in_array($optionValue, $selected, true);
            $dropdownOptions .= '<button type="button" data-value="' . $this->e($optionValue) . '" data-label="' . $this->e((string) $option['label']) . '" data-selected="' . ($isSelected ? '1' : '0') . '" onclick="appycrudSelect2Select(this)" class="appycrud-select2-option w-full text-left px-3 py-1.5 text-sm hover:bg-blue-50' . ($isSelected ? ' hidden' : '') . '">' . $this->e((string) $option['label']) . '</button>';
        }

        $placeholder = $this->e($this->translator->t('list.search_placeholder'));

        return <<<HTML
        <div class="appycrud-select2 relative" data-name="{$this->e($name)}">
            <div class="flex flex-wrap items-center gap-1 border border-gray-300 rounded-md p-1.5 min-h-[2.5rem] focus-within:ring-2 focus-within:ring-blue-200" onclick="appycrudSelect2FocusInput(this)">
                <div class="appycrud-select2-chips flex flex-wrap gap-1">{$chips}</div>
                <input type="text" class="appycrud-select2-input flex-1 min-w-[6rem] text-sm outline-none border-0 p-0.5" placeholder="{$placeholder}" oninput="appycrudSelect2Filter(this)" onfocus="appycrudSelect2Open(this)">
            </div>
            <div class="appycrud-select2-dropdown hidden absolute z-10 mt-1 w-full max-h-48 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg">{$dropdownOptions}</div>
        </div>
        HTML;
    }

    private function renderSelect2Chip(string $name, string $value, string $label): string
    {
        return '<span class="appycrud-select2-chip inline-flex items-center gap-1 bg-blue-100 text-blue-800 text-xs rounded px-2 py-1" data-value="' . $this->e($value) . '">'
            . '<span>' . $this->e($label) . '</span>'
            . '<input type="hidden" name="' . $name . '[]" value="' . $this->e($value) . '">'
            . '<button type="button" onclick="appycrudSelect2Remove(this)" class="text-blue-600 hover:text-blue-900">' . $this->icon('x') . '</button>'
            . '</span>';
    }

    /**
     * Iconos SVG inline (trazo simple, sin dependencias externas ni CDN).
     */
    private function icon(string $name): string
    {
        $paths = [
            'plus' => '<path d="M12 5v14M5 12h14" />',
            'edit' => '<path d="M4 20h4l10.5-10.5a2 2 0 0 0-4-4L4 16v4Z" /><path d="M13 6l4 4" />',
            'trash' => '<path d="M4 7h16" /><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" /><path d="M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12" /><path d="M10 11v6" /><path d="M14 11v6" />',
            'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" />',
            'copy' => '<rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" />',
            'download' => '<path d="M12 3v12" /><path d="M7 10l5 5 5-5" /><path d="M5 21h14" />',
            'printer' => '<path d="M6 9V3h12v6" /><rect x="4" y="9" width="16" height="8" rx="1" /><path d="M6 17v4h12v-4" />',
            'dots' => '<circle cx="5" cy="12" r="1.5" /><circle cx="12" cy="12" r="1.5" /><circle cx="19" cy="12" r="1.5" />',
            'search' => '<circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" />',
            'x-circle' => '<circle cx="12" cy="12" r="9" /><path d="M9.5 9.5l5 5M14.5 9.5l-5 5" />',
            'sliders' => '<path d="M4 6h6M14 6h6M4 12h10M18 12h2M4 18h6M14 18h6" /><circle cx="12" cy="6" r="2" /><circle cx="16" cy="12" r="2" /><circle cx="12" cy="18" r="2" />',
            'x' => '<path d="M18 6 6 18M6 6l12 12" />',
            'sparkles' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" />',
            'link' => '<path d="M9 15l6-6" /><path d="M11 6l1-1a4 4 0 1 1 6 6l-1 1" /><path d="M13 18l-1 1a4 4 0 1 1-6-6l1-1" />',
            'unlink' => '<path d="M9 15l6-6" /><path d="M11 6l1-1a4 4 0 1 1 6 6l-1 1" /><path d="M13 18l-1 1a4 4 0 1 1-6-6l1-1" /><path d="M4 4l16 16" />',
            'align-left' => '<path d="M4 6h16M4 12h10M4 18h14" />',
            'align-center' => '<path d="M4 6h16M7 12h10M5 18h14" />',
            'align-right' => '<path d="M4 6h16M10 12h10M6 18h14" />',
            'undo' => '<path d="M9 14 4 9l5-5" /><path d="M4 9h11a5 5 0 0 1 0 10h-1" />',
            'redo' => '<path d="M15 14l5-5-5-5" /><path d="M20 9H9a5 5 0 0 0 0 10h1" />',
        ];

        $path = $paths[$name] ?? '';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4">' . $path . '</svg>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
