<?php

namespace Appylogi\AppyCrud\Renderer;

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
    ): string {
        $t = $this->translator;
        $filtersEnabled = $features['filters'] ?? true;
        $searchEnabled = $features['search'] ?? true;

        $createUrl = $this->e($baseUrl) . '?action=create&ajax=1';
        $modal = $this->renderModalShell($csrfToken);
        $searchAndFilters = ($filtersEnabled || $searchEnabled)
            ? $this->renderFilterRow($schema, $baseUrl, $activeFilters, $search, $orderBy, $orderDir, $filtersEnabled, $searchEnabled, $filterableFields, $advancedFilters)
            : '';

        $toolbar = '<button type="button" onclick="appycrudOpenModal(\'' . $createUrl . '\')" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">' . $this->icon('plus') . '<span>' . $this->e($t->t('list.new')) . '</span></button>';

        if ($features['export'] ?? true) {
            $toolbar .= $this->renderExportMenu($baseUrl, $activeFilters, $search, $advancedFilters);
        }

        $toolbar .= '<button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('printer') . '<span>' . $this->e($t->t('list.print_list')) . '</span></button>';

        $body = $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken, $rowActions, $uploadUrlPrefix, $advancedFilters);

        return <<<HTML
        <div class="max-w-6xl mx-auto p-6 appycrud-fade-in">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <h1 class="text-xl font-bold text-gray-900">{$this->e($t->t('list.title', ['table' => $schema->table]))}</h1>
                <div class="flex items-center gap-2 print:hidden">{$toolbar}</div>
            </div>
            {$searchAndFilters}
            <div id="appycrud-list-body">{$body}</div>
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
    ): string {
        return $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken, $rowActions, $uploadUrlPrefix, $advancedFilters);
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
    ): string {
        $t = $this->translator;
        $columns = $schema->visibleColumns();
        $pk = $schema->primaryKey();

        $bulkDeleteEnabled = ($features['bulkDelete'] ?? true) && $pk !== null;
        $viewEnabled = $features['view'] ?? true;
        $cloneEnabled = $features['clone'] ?? true;

        $referenceLabels = [];
        foreach ($referenceOptions as $columnName => $options) {
            foreach ($options as $option) {
                $referenceLabels[$columnName][(string) $option['value']] = (string) $option['label'];
            }
        }

        $bulkHeaderCell = $bulkDeleteEnabled
            ? '<th class="px-4 py-2 text-left print:hidden">' . $this->renderBulkDeleteControl($schema, $baseUrl, $deleteMode, $activeFilters, $search) . '</th>'
            : '';

        $headers = $bulkHeaderCell;
        foreach ($columns as $column) {
            $headers .= '<th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600">' . $this->renderSortLink($column, $baseUrl, $activeFilters, $search, $orderBy, $orderDir, $advancedFilters) . '</th>';
        }
        $headers .= '<th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-600 print:hidden">' . $this->e($t->t('list.actions')) . '</th>';

        $bodyRows = '';
        foreach ($pagination['rows'] as $row) {
            $pkValue = $pk !== null ? $row[$pk->name] : '';

            $cells = $bulkDeleteEnabled
                ? '<td class="px-4 py-2 print:hidden"><input type="checkbox" class="appycrud-row-check" onchange="appycrudUpdateBulkUI()" value="' . $this->e((string) $pkValue) . '"></td>'
                : '';

            foreach ($columns as $column) {
                $rawValue = (string) ($row[$column->name] ?? '');
                $displayValue = $column->reference !== null
                    ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                    : $rawValue;

                $cellContent = match (true) {
                    FieldType::isFile($column->inputType ?? '') => $this->renderFileCell($rawValue, $uploadUrlPrefix),
                    FieldType::isRichText($column->inputType ?? '') => $this->e($this->richTextPreview($displayValue)),
                    default => $this->e($displayValue),
                };

                $cells .= '<td class="px-4 py-2 text-sm text-gray-800">' . $cellContent . '</td>';
            }

            $cells .= '<td class="px-4 py-2 text-right text-sm print:hidden">' . $this->renderRowActions($baseUrl, $pkValue, $deleteMode, $viewEnabled, $cloneEnabled, $t, $csrfToken, $rowActions) . '</td>';

            $bodyRows .= '<tr class="border-b border-gray-100 hover:bg-gray-50">' . $cells . '</tr>';
        }

        if ($bodyRows === '') {
            $colspan = count($columns) + 1 + ($bulkDeleteEnabled ? 1 : 0);
            $bodyRows = '<tr><td colspan="' . $colspan . '" class="px-4 py-6 text-center text-sm text-gray-500">' . $this->e($t->t('list.empty')) . '</td></tr>';
        }

        $pageInfo = $t->t('list.page_of', ['page' => $pagination['page'], 'lastPage' => $pagination['lastPage']]);

        return <<<HTML
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr>{$headers}</tr></thead>
                <tbody>{$bodyRows}</tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-gray-500 print:hidden">{$this->e($pageInfo)}</p>
        HTML;
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
    private function buildListQuery(array $activeFilters, string $search, string $orderBy, string $orderDir, array $advancedFilters = []): string
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

        return http_build_query($params);
    }

    /** @param array<int, array{name: string, label: string, icon: ?string, confirm: ?string, method: string, openInModal: bool}> $rowActions */
    private function renderRowActions(string $baseUrl, mixed $pkValue, string $deleteMode, bool $viewEnabled, bool $cloneEnabled, Translator $t, string $csrfToken = '', array $rowActions = []): string
    {
        $editUrl = $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $viewUrl = $this->e($baseUrl) . '?action=view&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $cloneUrl = $this->e($baseUrl) . '?action=clone&id=' . $this->e((string) $pkValue) . '&ajax=1';

        $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';

        $deleteSubmit = $deleteMode === DeleteMode::CONFIRM
            ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($t->t('confirm.delete'))) . ', ' . $this->e($this->jsString($t->t('list.delete'))) . ');"'
            : '';
        $deleteForm = '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '"' . $deleteSubmit . '>' . $csrfField . '%s</form>';

        $editButton = '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="%s text-blue-600 hover:text-blue-800%s">' . $this->icon('edit') . '<span>' . $this->e($t->t('list.edit')) . '</span></button>';

        // Con pocas acciones se muestran todas en linea; con varias, las
        // secundarias se agrupan en un menu para no saturar la fila.
        $extraCount = ($viewEnabled ? 1 : 0) + ($cloneEnabled ? 1 : 0) + 1 + count($rowActions); // +1 por Eliminar, siempre presente

        if ($extraCount <= 1) {
            $inline = sprintf($editButton, 'inline-flex items-center gap-1', '');
            $inline .= sprintf($deleteForm, '<button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:text-red-800">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>');

            return '<span class="inline-flex items-center gap-3">' . $inline . '</span>';
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
        $menuItems .= sprintf($deleteForm, '<button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 text-left">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>');

        return '<span class="inline-flex items-center gap-3">'
            . sprintf($editButton, 'inline-flex items-center gap-1', '')
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
    private function renderFilterRow(TableSchema $schema, string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir, bool $filtersEnabled, bool $searchEnabled, ?array $filterableFields = null, array $advancedFilters = []): string
    {
        $t = $this->translator;
        $fields = '';

        $filterableColumns = array_values(array_filter(
            $schema->visibleColumns(),
            fn (Column $c) => $filterableFields === null || in_array($c->name, $filterableFields, true),
        ));

        if ($searchEnabled) {
            $fields .= '<input type="text" name="q" value="' . $this->e($search) . '" placeholder="' . $this->e($t->t('list.search_placeholder')) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm min-w-[10rem]">';
        }

        if ($filtersEnabled) {
            foreach ($filterableColumns as $column) {
                $current = $activeFilters[$column->name] ?? '';

                if (FieldType::strategy($column->inputType ?? '') === FieldType::STRATEGY_CHECKBOX) {
                    $fields .= '<select name="filter[' . $this->e($column->name) . ']" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">'
                        . '<option value="">' . $this->e($column->label) . '</option>'
                        . '<option value="1"' . ($current === '1' ? ' selected' : '') . '>Si</option>'
                        . '<option value="0"' . ($current === '0' ? ' selected' : '') . '>No</option>'
                        . '</select>';
                } else {
                    $fields .= '<input type="text" name="filter[' . $this->e($column->name) . ']" value="' . $this->e($current) . '" placeholder="' . $this->e($column->label) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">';
                }
            }
        }

        $orderHidden = $orderBy !== ''
            ? '<input type="hidden" name="orderBy" value="' . $this->e($orderBy) . '"><input type="hidden" name="orderDir" value="' . $this->e($orderDir) . '">'
            : '';

        $advancedPanel = $filtersEnabled ? $this->renderAdvancedFilterPanel($filterableColumns, $advancedFilters) : '';
        $advancedToggle = $filtersEnabled
            ? '<button type="button" onclick="appycrudToggleAdvancedFilter()" class="text-sm text-gray-600 hover:underline">' . $this->e($t->t('list.advanced_filter')) . '</button>'
            : '';

        return <<<HTML
        <form method="get" action="{$this->e($baseUrl)}" class="mb-4 print:hidden" oninput="appycrudScheduleFilter(this)" onchange="appycrudScheduleFilter(this)" onsubmit="return appycrudSubmitFilters(event, this)">
            <div class="flex flex-wrap items-center gap-2">
                {$fields}
                {$orderHidden}
                <button type="submit" class="bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm hover:bg-gray-900">{$this->e($t->t('list.filter_apply'))}</button>
                <a href="{$this->e($baseUrl)}" class="text-sm text-gray-500 hover:underline">{$this->e($t->t('list.filter_clear'))}</a>
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

    /** @param Column[] $filterableColumns */
    private function renderAdvancedFilterPanel(array $filterableColumns, array $activeRows): string
    {
        $t = $this->translator;

        // Plantilla para filas agregadas por JS (sin valores activos, sin conector visible);
        // va en un <template> para que appycrudAddFilterRow() la clone sin depender de un fetch.
        $templateRow = $this->renderAdvancedFilterRow($filterableColumns, ['field' => '', 'op' => 'contains', 'value' => '', 'conn' => 'AND'], false);

        $rowsHtml = '';
        foreach ($activeRows as $index => $row) {
            $rowsHtml .= $this->renderAdvancedFilterRow($filterableColumns, $row, $index > 0);
        }

        $addLabel = $this->e($t->t('list.advanced_filter_add_row'));
        $applyLabel = $this->e($t->t('list.filter_apply'));

        return <<<HTML
        <div id="appycrud-advanced-filter" class="hidden mt-2 p-3 bg-gray-50 border border-gray-200 rounded-md">
            <template id="appycrud-af-row-template">{$templateRow}</template>
            <div id="appycrud-advanced-filter-rows">{$rowsHtml}</div>
            <div class="flex items-center gap-2 mt-2">
                <button type="button" onclick="appycrudAddFilterRow()" class="text-sm text-blue-600 hover:underline">+ {$addLabel}</button>
                <button type="button" onclick="appycrudSubmitFilters(null, document.getElementById('appycrud-advanced-filter').closest('form'))" class="bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm hover:bg-gray-900">{$applyLabel}</button>
            </div>
        </div>
        HTML;
    }

    /**
     * @param Column[] $filterableColumns
     * @param array{field: string, op: string, value: mixed, conn: string} $row
     */
    private function renderAdvancedFilterRow(array $filterableColumns, array $row, bool $showConnector): string
    {
        $t = $this->translator;

        $connSelect = '<select name="af_conn[]" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm bg-white' . ($showConnector ? '' : ' invisible') . '">'
            . '<option value="AND"' . ($row['conn'] === 'AND' ? ' selected' : '') . '>' . $this->e($t->t('list.conn_and')) . '</option>'
            . '<option value="OR"' . ($row['conn'] === 'OR' ? ' selected' : '') . '>' . $this->e($t->t('list.conn_or')) . '</option>'
            . '</select>';

        $fieldSelect = '<select name="af_field[]" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm bg-white"><option value="">--</option>';
        foreach ($filterableColumns as $column) {
            $selected = $column->name === $row['field'] ? ' selected' : '';
            $fieldSelect .= '<option value="' . $this->e($column->name) . '"' . $selected . '>' . $this->e($column->label) . '</option>';
        }
        $fieldSelect .= '</select>';

        $opSelect = '<select name="af_op[]" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm bg-white" onchange="appycrudToggleFilterValue(this)">';
        foreach (self::ADVANCED_FILTER_OPERATOR_LABELS as $value => $labelKey) {
            $selected = $value === $row['op'] ? ' selected' : '';
            $opSelect .= '<option value="' . $value . '"' . $selected . '>' . $this->e($t->t($labelKey)) . '</option>';
        }
        $opSelect .= '</select>';

        $valueHidden = in_array($row['op'], ['is_null', 'is_not_null'], true) ? ' style="display:none"' : '';

        return '<div class="flex items-center gap-2 mb-2 appycrud-af-row">'
            . $connSelect . $fieldSelect . $opSelect
            . '<input type="text" name="af_value[]" value="' . $this->e((string) $row['value']) . '" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm"' . $valueHidden . '>'
            . '<button type="button" onclick="appycrudRemoveFilterRow(this)" class="text-gray-400 hover:text-red-600">&times;</button>'
            . '</div>';
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

        return <<<HTML
        <style>
        @keyframes appycrud-fade-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .appycrud-fade-in { animation: appycrud-fade-in .25s ease-out; }
        dialog[open] { animation: appycrud-pop .18s ease-out; }
        dialog[open]::backdrop { animation: appycrud-backdrop-fade .18s ease-out; }
        @keyframes appycrud-pop { from { opacity: 0; transform: scale(.95) translateY(-8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        @keyframes appycrud-backdrop-fade { from { opacity: 0; } to { opacity: 1; } }
        </style>
        <dialog id="appycrud-dialog" class="rounded-lg shadow-xl p-0 w-full max-w-2xl backdrop:bg-black/50">
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

        function appycrudSyncDatalist(input) {
            var hidden = input.nextElementSibling;
            var list = document.getElementById(input.getAttribute('list'));
            var match = null;
            list.querySelectorAll('option').forEach(function (opt) {
                if (opt.value === input.value) { match = opt.getAttribute('data-value'); }
            });
            hidden.value = match !== null ? match : '';
        }

        function appycrudFilterMultiselect(input) {
            var term = input.value.toLowerCase();
            input.closest('[data-appycrud-multiselect]').querySelectorAll('.appycrud-ms-option').forEach(function (opt) {
                opt.style.display = opt.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
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
            appycrudFilterTimer = setTimeout(function () { appycrudApplyFilters(form); }, 350);
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

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    document.getElementById('appycrud-list-body').innerHTML = html;
                });
        }

        function appycrudToggleAdvancedFilter() {
            document.getElementById('appycrud-advanced-filter').classList.toggle('hidden');
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

        function appycrudToggleFilterValue(select) {
            var input = select.closest('.appycrud-af-row').querySelector('input[name="af_value[]"]');
            var hide = select.value === 'is_null' || select.value === 'is_not_null';
            input.style.display = hide ? 'none' : '';
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

            $fields .= $this->renderField(
                $column,
                (string) ($values[$column->name] ?? ''),
                $referenceOptions[$column->name] ?? [],
                $errors[$column->name] ?? [],
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

        return <<<HTML
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($title)}</h1>
            {$generalErrorHtml}
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

        $rows = '';
        foreach ($schema->visibleColumns() as $column) {
            $rawValue = (string) ($values[$column->name] ?? '');
            $displayValue = $column->reference !== null
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

        $rows = '';
        foreach ($schema->visibleColumns() as $column) {
            $rawValue = (string) ($values[$column->name] ?? '');
            $displayValue = $column->reference !== null
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

    private function renderField(Column $column, string $value, array $options = [], array $errorMessages = []): string
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

        if ($strategy === FieldType::STRATEGY_HIDDEN) {
            return '<input type="hidden" name="' . $name . '" value="' . $this->e($value) . '">';
        }

        $label = '<label class="block text-sm font-medium text-gray-700 mb-1">' . $this->e($column->label) . '</label>';

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
            FieldType::STRATEGY_SELECT_SEARCHABLE => $this->renderSearchableSelect($column, $name, $value, $optionSource, $required, $baseClass . $errorClass),
            FieldType::STRATEGY_MULTISELECT => $this->renderMultiselect($name, $value, $optionSource, $baseClass . $errorClass),
            FieldType::STRATEGY_MULTISELECT_SEARCHABLE => $this->renderMultiselectSearchable($name, $value, $optionSource),
            FieldType::STRATEGY_FILE => $this->renderFileInput($name, $value, $baseClass . $errorClass, $required !== '' && $value === ''),
            FieldType::STRATEGY_RICHTEXT => $this->renderRichText($name, $value, $errorClass),
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
     * barra de herramientas minima (negrita/italica/subrayado/listas) via
     * document.execCommand, y un <input type="hidden"> que es lo que
     * realmente viaja en el submit — se sincroniza en cada 'input' del div.
     * $value ya viene sanitizado (ver Crud\HtmlSanitizer::sanitize(), aplicado
     * al guardar), por eso se inyecta tal cual como HTML del contenteditable,
     * no escapado como el resto de los campos de texto.
     */
    private function renderRichText(string $name, string $value, string $errorClass): string
    {
        $editorId = 'appycrud-rt-' . $name;
        $t = $this->translator;

        $buttons = [
            ['bold', 'B', $t->t('richtext.bold')],
            ['italic', 'I', $t->t('richtext.italic')],
            ['underline', 'U', $t->t('richtext.underline')],
            ['insertUnorderedList', '&bull; –', $t->t('richtext.bullet_list')],
            ['insertOrderedList', '1.', $t->t('richtext.numbered_list')],
        ];

        $toolbar = '';
        foreach ($buttons as [$command, $icon, $label]) {
            $toolbar .= '<button type="button" onmousedown="event.preventDefault()" onclick="appycrudRichTextExec(\'' . $editorId . '\', \'' . $command . '\')" title="' . $this->e($label) . '" class="px-2 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">' . $icon . '</button>';
        }

        return '<div class="flex items-center gap-1 mb-1">' . $toolbar . '</div>'
            . '<div id="' . $editorId . '" contenteditable="true" oninput="appycrudRichTextSync(\'' . $editorId . '\')" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm min-h-[8rem]' . $errorClass . '">' . $value . '</div>'
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

        return '<select name="' . $name . '[]" multiple class="' . $class . ' bg-white h-32">' . $optionsHtml . '</select>';
    }

    /**
     * Checkboxes con un filtro de texto arriba (vanilla JS), para cuando el
     * multiselect nativo tiene demasiadas opciones para desplazarse comodo.
     * @param array<int, array{value: mixed, label: string}> $options
     */
    private function renderMultiselectSearchable(string $name, string $value, array $options): string
    {
        $selected = $value !== '' ? explode(',', $value) : [];

        $checkboxes = '';
        foreach ($options as $option) {
            $optionValue = (string) $option['value'];
            $isChecked = in_array($optionValue, $selected, true) ? ' checked' : '';
            $checkboxes .= '<label class="appycrud-ms-option flex items-center gap-2 text-sm py-0.5">'
                . '<input type="checkbox" name="' . $name . '[]" value="' . $this->e($optionValue) . '"' . $isChecked . '>'
                . '<span>' . $this->e((string) $option['label']) . '</span>'
                . '</label>';
        }

        return '<div data-appycrud-multiselect class="border border-gray-300 rounded-md p-2">'
            . '<input type="text" placeholder="' . $this->e($this->translator->t('list.search_placeholder')) . '" class="w-full mb-2 text-xs border-b border-gray-200 pb-1 focus:outline-none" oninput="appycrudFilterMultiselect(this)">'
            . '<div class="max-h-32 overflow-y-auto">' . $checkboxes . '</div>'
            . '</div>';
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
