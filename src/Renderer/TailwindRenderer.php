<?php

namespace Appylogi\AppyCrud\Renderer;

use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Genera el HTML de listado/formulario/vista con clases Tailwind.
 * No depende de ningun framework de JS: crear/editar/ver/clonar/confirmar
 * abren en <dialog> nativos cargados por fetch, y el envio de formularios
 * tambien va por fetch. Todo el JS vive en un solo bloque, generado una
 * sola vez por listado.
 */
class TailwindRenderer
{
    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param array<string, array<int, array{value: mixed, label: string}>> $referenceOptions columna => opciones (para resolver el label de las FK en el listado)
     * @param array<string, mixed> $features ver AppyCrud::$features (export/bulkDelete/filters/view/print/clone/...)
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
    ): string {
        $t = $this->translator;
        $columns = $schema->visibleColumns();
        $pk = $schema->primaryKey();

        $bulkDeleteEnabled = ($features['bulkDelete'] ?? true) && $pk !== null;
        $viewEnabled = $features['view'] ?? true;
        $cloneEnabled = $features['clone'] ?? true;
        $exportEnabled = $features['export'] ?? true;
        $filtersEnabled = $features['filters'] ?? true;

        $referenceLabels = [];
        foreach ($referenceOptions as $columnName => $options) {
            foreach ($options as $option) {
                $referenceLabels[$columnName][(string) $option['value']] = (string) $option['label'];
            }
        }

        $headers = $bulkDeleteEnabled
            ? '<th class="px-4 py-2 text-left print:hidden"><input type="checkbox" onclick="appycrudToggleAll(this)" aria-label="' . $this->e($t->t('list.select_all')) . '"></th>'
            : '';

        foreach ($columns as $column) {
            $headers .= '<th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600">' . $this->e($column->label) . '</th>';
        }
        $headers .= '<th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-600 print:hidden">' . $this->e($t->t('list.actions')) . '</th>';

        $bodyRows = '';
        foreach ($pagination['rows'] as $row) {
            $pkValue = $pk !== null ? $row[$pk->name] : '';

            $cells = $bulkDeleteEnabled
                ? '<td class="px-4 py-2 print:hidden"><input type="checkbox" class="appycrud-row-check" value="' . $this->e((string) $pkValue) . '"></td>'
                : '';

            foreach ($columns as $column) {
                $rawValue = (string) ($row[$column->name] ?? '');
                $displayValue = $column->reference !== null
                    ? ($referenceLabels[$column->name][$rawValue] ?? $rawValue)
                    : $rawValue;

                $cells .= '<td class="px-4 py-2 text-sm text-gray-800">' . $this->e($displayValue) . '</td>';
            }

            $editUrl = $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '&ajax=1';
            $viewUrl = $this->e($baseUrl) . '?action=view&id=' . $this->e((string) $pkValue) . '&ajax=1';
            $cloneUrl = $this->e($baseUrl) . '?action=clone&id=' . $this->e((string) $pkValue) . '&ajax=1';

            $deleteSubmit = $deleteMode === DeleteMode::CONFIRM
                ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($t->t('confirm.delete'))) . ');"'
                : '';

            $rowActions = $viewEnabled
                ? '<button type="button" onclick="appycrudOpenModal(\'' . $viewUrl . '\')" class="inline-flex items-center gap-1 text-gray-600 hover:text-gray-900">' . $this->icon('eye') . '<span>' . $this->e($t->t('list.view')) . '</span></button>'
                : '';

            $rowActions .= '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800">' . $this->icon('edit') . '<span>' . $this->e($t->t('list.edit')) . '</span></button>';

            if ($cloneEnabled) {
                $rowActions .= '<button type="button" onclick="appycrudOpenModal(\'' . $cloneUrl . '\')" class="inline-flex items-center gap-1 text-emerald-600 hover:text-emerald-800">' . $this->icon('copy') . '<span>' . $this->e($t->t('list.clone')) . '</span></button>';
            }

            $rowActions .= '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '" class="inline"' . $deleteSubmit . '>'
                . '<button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:text-red-800">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>'
                . '</form>';

            $cells .= '<td class="px-4 py-2 text-right text-sm print:hidden"><span class="inline-flex items-center gap-3">' . $rowActions . '</span></td>';

            $bodyRows .= '<tr class="border-b border-gray-100 hover:bg-gray-50">' . $cells . '</tr>';
        }

        if ($bodyRows === '') {
            $colspan = count($columns) + 1 + ($bulkDeleteEnabled ? 1 : 0);
            $bodyRows = '<tr><td colspan="' . $colspan . '" class="px-4 py-6 text-center text-sm text-gray-500">' . $this->e($t->t('list.empty')) . '</td></tr>';
        }

        $pageInfo = $t->t('list.page_of', ['page' => $pagination['page'], 'lastPage' => $pagination['lastPage']]);
        $createUrl = $this->e($baseUrl) . '?action=create&ajax=1';
        $exportUrl = $this->e($baseUrl) . '?action=export' . $this->filterQueryString($activeFilters);
        $modal = $this->renderModalShell();
        $filterRow = $filtersEnabled ? $this->renderFilterRow($schema, $baseUrl, $activeFilters) : '';

        $toolbar = '<button type="button" onclick="appycrudOpenModal(\'' . $createUrl . '\')" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">' . $this->icon('plus') . '<span>' . $this->e($t->t('list.new')) . '</span></button>';

        if ($exportEnabled) {
            $toolbar .= '<a href="' . $exportUrl . '" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('download') . '<span>' . $this->e($t->t('list.export')) . '</span></a>';
        }

        $toolbar .= '<button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50">' . $this->icon('printer') . '<span>' . $this->e($t->t('list.print_list')) . '</span></button>';

        if ($bulkDeleteEnabled) {
            $bulkMessage = $this->e($this->jsString($t->t('list.bulk_delete_confirm', ['count' => '__COUNT__'])));
            $bulkRequireConfirm = $deleteMode === DeleteMode::CONFIRM ? 'true' : 'false';
            $bulkDeleteUrl = $this->e($baseUrl) . '?action=bulkDelete';
            $toolbar .= '<button type="button" onclick="appycrudBulkDelete(\'' . $bulkDeleteUrl . '\', ' . $bulkMessage . ', ' . $bulkRequireConfirm . ')" class="inline-flex items-center gap-2 bg-white border border-red-300 text-red-700 px-4 py-2 rounded-md text-sm hover:bg-red-50">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.bulk_delete')) . '</span></button>';
        }

        return <<<HTML
        <div class="max-w-6xl mx-auto p-6">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <h1 class="text-xl font-bold text-gray-900">{$this->e($t->t('list.title', ['table' => $schema->table]))}</h1>
                <div class="flex items-center gap-2 print:hidden">{$toolbar}</div>
            </div>
            {$filterRow}
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>{$headers}</tr></thead>
                    <tbody>{$bodyRows}</tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-gray-500 print:hidden">{$this->e($pageInfo)}</p>
        </div>
        {$modal}
        HTML;
    }

    /** @param array<string, string> $activeFilters */
    private function renderFilterRow(TableSchema $schema, string $baseUrl, array $activeFilters): string
    {
        $t = $this->translator;
        $fields = '';

        foreach ($schema->visibleColumns() as $column) {
            $current = $activeFilters[$column->name] ?? '';

            if ($column->reference !== null) {
                $fields .= '<input type="text" name="filter[' . $this->e($column->name) . ']" value="' . $this->e($current) . '" placeholder="' . $this->e($column->label) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">';
            } elseif ($column->inputType === 'checkbox') {
                $fields .= '<select name="filter[' . $this->e($column->name) . ']" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">'
                    . '<option value="">' . $this->e($column->label) . '</option>'
                    . '<option value="1"' . ($current === '1' ? ' selected' : '') . '>Si</option>'
                    . '<option value="0"' . ($current === '0' ? ' selected' : '') . '>No</option>'
                    . '</select>';
            } else {
                $fields .= '<input type="text" name="filter[' . $this->e($column->name) . ']" value="' . $this->e($current) . '" placeholder="' . $this->e($column->label) . '" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">';
            }
        }

        return <<<HTML
        <form method="get" action="{$this->e($baseUrl)}" class="flex flex-wrap items-center gap-2 mb-4 print:hidden">
            {$fields}
            <button type="submit" class="bg-gray-800 text-white px-3 py-1.5 rounded-md text-sm hover:bg-gray-900">{$this->e($t->t('list.filter_apply'))}</button>
            <a href="{$this->e($baseUrl)}" class="text-sm text-gray-500 hover:underline">{$this->e($t->t('list.filter_clear'))}</a>
        </form>
        HTML;
    }

    private function filterQueryString(array $activeFilters): string
    {
        $query = '';
        foreach ($activeFilters as $columnName => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $query .= '&filter%5B' . rawurlencode($columnName) . '%5D=' . rawurlencode((string) $value);
        }

        return $query;
    }

    /**
     * Dialogs nativos (formulario + confirmacion) y JS vanilla, se generan una
     * sola vez por pagina de listado. renderForm()/renderView() asumen que
     * este shell ya esta presente (usan sus funciones globales).
     */
    private function renderModalShell(): string
    {
        $cancelLabel = $this->e($this->translator->t('form.cancel'));
        $deleteLabel = $this->e($this->translator->t('list.delete'));

        return <<<HTML
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
        }

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
    public function renderForm(TableSchema $schema, array $values, string $baseUrl, bool $isEdit, array $referenceOptions = [], array $errors = []): string
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

            $fields .= $this->renderField(
                $column,
                (string) ($values[$column->name] ?? ''),
                $referenceOptions[$column->name] ?? [],
                $errors[$column->name] ?? [],
            );
        }

        return <<<HTML
        <div class="p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($title)}</h1>
            <form method="post" action="{$this->e($baseUrl)}?action={$action}&id={$this->e((string) $pkValue)}" class="space-y-4" onsubmit="return appycrudSubmitForm(event, this)">
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
    private function renderField(Column $column, string $value, array $options = [], array $errorMessages = []): string
    {
        $label = '<label class="block text-sm font-medium text-gray-700 mb-1">' . $this->e($column->label) . '</label>';
        $errorClass = $errorMessages !== [] ? ' border-red-400' : '';

        if ($column->reference !== null) {
            $optionsHtml = '<option value="">&mdash;</option>';
            foreach ($options as $option) {
                $selected = (string) $option['value'] === $value ? ' selected' : '';
                $optionsHtml .= '<option value="' . $this->e((string) $option['value']) . '"' . $selected . '>' . $this->e((string) $option['label']) . '</option>';
            }
            $input = '<select name="' . $this->e($column->name) . '" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white' . $errorClass . '"'
                . (!$column->nullable ? ' required' : '')
                . '>' . $optionsHtml . '</select>';
        } elseif ($column->inputType === 'textarea') {
            $input = '<textarea name="' . $this->e($column->name) . '" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm' . $errorClass . '" rows="4">' . $this->e($value) . '</textarea>';
        } elseif ($column->inputType === 'checkbox') {
            $checked = $value ? 'checked' : '';
            $input = '<input type="checkbox" name="' . $this->e($column->name) . '" ' . $checked . ' class="rounded border-gray-300">';
        } else {
            $input = '<input type="' . $this->e($column->inputType) . '" name="' . $this->e($column->name) . '" value="' . $this->e($value) . '" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm' . $errorClass . '"'
                . ($column->maxLength !== null ? ' maxlength="' . $column->maxLength . '"' : '')
                . (!$column->nullable ? ' required' : '')
                . '>';
        }

        $errorHtml = '';
        foreach ($errorMessages as $message) {
            $errorHtml .= '<p class="mt-1 text-xs text-red-600">' . $this->e($message) . '</p>';
        }

        return '<div>' . $label . $input . $errorHtml . '</div>';
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
