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
    ): string {
        $t = $this->translator;
        $filtersEnabled = $features['filters'] ?? true;
        $searchEnabled = $features['search'] ?? true;

        $createUrl = $this->e($baseUrl) . '?action=create&ajax=1';
        $modal = $this->renderModalShell($csrfToken);
        $searchAndFilters = ($filtersEnabled || $searchEnabled)
            ? $this->renderFilterRow($schema, $baseUrl, $activeFilters, $search, $orderBy, $orderDir, $filtersEnabled, $searchEnabled)
            : '';

        $toolbar = '<button type="button" onclick="appycrudOpenModal(\'' . $createUrl . '\')" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">' . $this->icon('plus') . '<span>' . $this->e($t->t('list.new')) . '</span></button>';

        if ($features['export'] ?? true) {
            $toolbar .= $this->renderExportMenu($baseUrl, $activeFilters, $search);
        }

        $toolbar .= '<button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('printer') . '<span>' . $this->e($t->t('list.print_list')) . '</span></button>';

        $body = $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken);

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
    ): string {
        return $this->renderListInner($schema, $pagination, $baseUrl, $deleteMode, $referenceOptions, $features, $activeFilters, $search, $orderBy, $orderDir, $csrfToken);
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
            $headers .= '<th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600">' . $this->renderSortLink($column, $baseUrl, $activeFilters, $search, $orderBy, $orderDir) . '</th>';
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

                $cells .= '<td class="px-4 py-2 text-sm text-gray-800">' . $this->e($displayValue) . '</td>';
            }

            $cells .= '<td class="px-4 py-2 text-right text-sm print:hidden">' . $this->renderRowActions($baseUrl, $pkValue, $deleteMode, $viewEnabled, $cloneEnabled, $t, $csrfToken) . '</td>';

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
        $bulkRequireConfirm = $deleteMode === DeleteMode::CONFIRM ? 'true' : 'false';
        $bulkDeleteUrl = $this->e($baseUrl) . '?action=bulkDelete';

        return '<div class="flex items-center gap-2">'
            . '<input type="checkbox" onclick="appycrudToggleAll(this)" aria-label="' . $this->e($t->t('list.select_all')) . '">'
            . '<button type="button" id="appycrud-bulk-delete-btn" onclick="appycrudBulkDelete(\'' . $bulkDeleteUrl . '\', ' . $bulkMessage . ', ' . $bulkRequireConfirm . ')" class="hidden items-center gap-1 text-red-600 hover:text-red-800 text-xs font-medium">'
            . $this->icon('trash') . '<span>' . $this->e($t->t('list.bulk_delete')) . '</span><span class="appycrud-bulk-count"></span>'
            . '</button>'
            . '</div>';
    }

    private function renderSortLink(Column $column, string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir): string
    {
        $isActive = $orderBy === $column->name;
        $nextDir = ($isActive && strtoupper($orderDir) === 'ASC') ? 'DESC' : 'ASC';
        $query = $this->buildListQuery($activeFilters, $search, $column->name, $nextDir);
        $arrow = $isActive ? ($nextDir === 'DESC' ? ' ↑' : ' ↓') : '';

        return '<a href="' . $this->e($baseUrl) . '?' . $query . '" class="hover:text-gray-900' . ($isActive ? ' text-gray-900' : '') . '">' . $this->e($column->label) . $arrow . '</a>';
    }

    /** @return string querystring (sin "?") con filtros + busqueda + orden */
    private function buildListQuery(array $activeFilters, string $search, string $orderBy, string $orderDir): string
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

        return http_build_query($params);
    }

    private function renderRowActions(string $baseUrl, mixed $pkValue, string $deleteMode, bool $viewEnabled, bool $cloneEnabled, Translator $t, string $csrfToken = ''): string
    {
        $editUrl = $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $viewUrl = $this->e($baseUrl) . '?action=view&id=' . $this->e((string) $pkValue) . '&ajax=1';
        $cloneUrl = $this->e($baseUrl) . '?action=clone&id=' . $this->e((string) $pkValue) . '&ajax=1';

        $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';

        $deleteSubmit = $deleteMode === DeleteMode::CONFIRM
            ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($t->t('confirm.delete'))) . ');"'
            : '';
        $deleteForm = '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '"' . $deleteSubmit . '>' . $csrfField . '%s</form>';

        $editButton = '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="%s text-blue-600 hover:text-blue-800%s">' . $this->icon('edit') . '<span>' . $this->e($t->t('list.edit')) . '</span></button>';

        $extraCount = ($viewEnabled ? 1 : 0) + ($cloneEnabled ? 1 : 0) + 1; // +1 por Eliminar, siempre presente

        // Con pocas acciones se muestran todas en linea; con varias, las
        // secundarias se agrupan en un menu para no saturar la fila.
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
        $menuItems .= sprintf($deleteForm, '<button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 text-left">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>');

        return '<span class="inline-flex items-center gap-3">'
            . sprintf($editButton, 'inline-flex items-center gap-1', '')
            . '<span class="relative inline-block appycrud-menu-wrap">'
            . '<button type="button" onclick="appycrudToggleMenu(this)" aria-label="' . $this->e($t->t('list.more_actions')) . '" class="text-gray-500 hover:text-gray-800 p-1 rounded hover:bg-gray-100">' . $this->icon('dots') . '</button>'
            . '<div class="hidden appycrud-menu w-40 bg-white border border-gray-200 rounded-md shadow-lg overflow-hidden">' . $menuItems . '</div>'
            . '</span>'
            . '</span>';
    }

    private function renderExportMenu(string $baseUrl, array $activeFilters, string $search): string
    {
        $t = $this->translator;
        $query = $this->buildListQuery($activeFilters, $search, '', '');
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
     * Una sola fila: busqueda global + un input por columna, todo en el mismo
     * <form method="get"> para poder combinarse y para preservar el orden
     * actual (orderBy/orderDir como hidden).
     */
    private function renderFilterRow(TableSchema $schema, string $baseUrl, array $activeFilters, string $search, string $orderBy, string $orderDir, bool $filtersEnabled, bool $searchEnabled): string
    {
        $t = $this->translator;
        $fields = '';

        if ($searchEnabled) {
            $fields .= '<input type="text" name="q" value="' . $this->e($search) . '" placeholder="' . $this->e($t->t('list.search_placeholder')) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm min-w-[10rem]">';
        }

        if ($filtersEnabled) {
            foreach ($schema->visibleColumns() as $column) {
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

        return <<<HTML
        <form method="get" action="{$this->e($baseUrl)}" class="flex flex-wrap items-center gap-2 mb-4 print:hidden" oninput="appycrudScheduleFilter(this)" onchange="appycrudScheduleFilter(this)" onsubmit="return appycrudSubmitFilters(event, this)">
            {$fields}
            {$orderHidden}
            <button type="submit" class="bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm hover:bg-gray-900">{$this->e($t->t('list.filter_apply'))}</button>
            <a href="{$this->e($baseUrl)}" class="text-sm text-gray-500 hover:underline">{$this->e($t->t('list.filter_clear'))}</a>
        </form>
        HTML;
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
        $deleteLabel = $this->e($this->translator->t('list.delete'));
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
                <button type="button" onclick="appycrudAcceptConfirm()" class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">{$deleteLabel}</button>
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
            event.preventDefault();
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

        function appycrudConfirmAction(message, action) {
            appycrudPendingAction = action;
            document.getElementById('appycrud-confirm-message').textContent = message;
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

        function appycrudConfirmSubmit(event, form, message) {
            event.preventDefault();
            appycrudConfirmAction(message, function () {
                fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
                    .then(function () { window.location.reload(); });
            });
            return false;
        }

        function appycrudBulkDelete(url, message, requireConfirm) {
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
                appycrudConfirmAction(message.replace('__COUNT__', ids.length), run);
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
    /** @param string[]|null $fieldsWhitelist si no es null, solo estas columnas aparecen en el formulario */
    public function renderForm(TableSchema $schema, array $values, string $baseUrl, bool $isEdit, array $referenceOptions = [], array $errors = [], string $csrfToken = '', string $generalError = '', ?array $fieldsWhitelist = null): string
    {
        $t = $this->translator;
        $pk = $schema->primaryKey();
        $title = $isEdit ? $t->t('form.edit_title') : $t->t('form.create_title');
        $action = $isEdit ? 'update' : 'store';
        $pkValue = $pk !== null ? ($values[$pk->name] ?? '') : '';

        $fields = '';
        foreach ($schema->visibleColumns() as $column) {
            if ($column->isPrimaryKey || $column->readOnly) {
                continue;
            }

            if ($fieldsWhitelist !== null && !in_array($column->name, $fieldsWhitelist, true)) {
                continue;
            }

            $fields .= $this->renderField(
                $column,
                (string) ($values[$column->name] ?? ''),
                $referenceOptions[$column->name] ?? [],
                $errors[$column->name] ?? [],
            );
        }

        $csrfField = $csrfToken !== '' ? '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">' : '';
        $generalErrorHtml = $generalError !== ''
            ? '<div class="rounded-md bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">' . $this->e($generalError) . '</div>'
            : '';

        return <<<HTML
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($title)}</h1>
            {$generalErrorHtml}
            <form method="post" action="{$this->e($baseUrl)}?action={$action}&id={$this->e((string) $pkValue)}" class="space-y-4" onsubmit="return appycrudSubmitForm(event, this)">
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
    public function renderView(TableSchema $schema, array $values, string $baseUrl, string $id, array $referenceOptions = []): string
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

            $rows .= '<div class="py-2 border-b border-gray-100">'
                . '<dt class="text-xs font-semibold uppercase text-gray-500">' . $this->e($column->label) . '</dt>'
                . '<dd class="text-sm text-gray-900 mt-0.5">' . $this->e($displayValue !== '' ? $displayValue : '—') . '</dd>'
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
