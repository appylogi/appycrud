<?php

namespace Appylogi\AppyCrud\Renderer;

use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Genera el HTML de listado/formulario con clases Tailwind.
 * No depende de ningun framework de JS: crear/editar/confirmar abren en
 * <dialog> nativos cargados por fetch, y el envio de formularios tambien
 * va por fetch. Todo el JS vive en un solo bloque, generado una vez por listado.
 */
class TailwindRenderer
{
    public function __construct(private Translator $translator)
    {
    }

    public function renderList(TableSchema $schema, array $pagination, string $baseUrl, string $deleteMode = DeleteMode::CONFIRM): string
    {
        $t = $this->translator;
        $columns = $schema->visibleColumns();
        $pk = $schema->primaryKey();

        $headers = '';
        foreach ($columns as $column) {
            $headers .= '<th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600">' . $this->e($column->label) . '</th>';
        }
        $headers .= '<th class="px-4 py-2 text-right text-xs font-semibold uppercase text-gray-600">' . $this->e($t->t('list.actions')) . '</th>';

        $bodyRows = '';
        foreach ($pagination['rows'] as $row) {
            $cells = '';
            foreach ($columns as $column) {
                $cells .= '<td class="px-4 py-2 text-sm text-gray-800">' . $this->e((string) ($row[$column->name] ?? '')) . '</td>';
            }

            $pkValue = $pk !== null ? $row[$pk->name] : '';
            $editUrl = $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '&ajax=1';

            $deleteSubmit = $deleteMode === DeleteMode::CONFIRM
                ? ' onsubmit="return appycrudConfirmSubmit(event, this, ' . $this->e($this->jsString($t->t('confirm.delete'))) . ');"'
                : '';

            $cells .= '<td class="px-4 py-2 text-right text-sm">'
                . '<span class="inline-flex items-center gap-3">'
                . '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800">' . $this->icon('edit') . '<span>' . $this->e($t->t('list.edit')) . '</span></button>'
                . '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '" class="inline"' . $deleteSubmit . '>'
                . '<button type="submit" class="inline-flex items-center gap-1 text-red-600 hover:text-red-800">' . $this->icon('trash') . '<span>' . $this->e($t->t('list.delete')) . '</span></button>'
                . '</form>'
                . '</span>'
                . '</td>';

            $bodyRows .= '<tr class="border-b border-gray-100 hover:bg-gray-50">' . $cells . '</tr>';
        }

        if ($bodyRows === '') {
            $colspan = count($columns) + 1;
            $bodyRows = '<tr><td colspan="' . $colspan . '" class="px-4 py-6 text-center text-sm text-gray-500">' . $this->e($t->t('list.empty')) . '</td></tr>';
        }

        $pageInfo = $t->t('list.page_of', ['page' => $pagination['page'], 'lastPage' => $pagination['lastPage']]);
        $createUrl = $this->e($baseUrl) . '?action=create&ajax=1';
        $modal = $this->renderModalShell();

        return <<<HTML
        <div class="max-w-5xl mx-auto p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-900">{$this->e($t->t('list.title', ['table' => $schema->table]))}</h1>
                <button type="button" onclick="appycrudOpenModal('{$createUrl}')" class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">{$this->icon('plus')}<span>{$this->e($t->t('list.new'))}</span></button>
            </div>
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>{$headers}</tr></thead>
                    <tbody>{$bodyRows}</tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-gray-500">{$this->e($pageInfo)}</p>
        </div>
        {$modal}
        HTML;
    }

    /**
     * Dialogs nativos (formulario + confirmacion) y JS vanilla, se generan una
     * sola vez por pagina de listado. renderForm() asume que este shell ya
     * esta presente (usa sus funciones globales).
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
            fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
                .then(function () { window.location.reload(); });
            return false;
        }

        var appycrudPendingForm = null;

        function appycrudConfirmSubmit(event, form, message) {
            event.preventDefault();
            appycrudPendingForm = form;
            document.getElementById('appycrud-confirm-message').textContent = message;
            document.getElementById('appycrud-confirm-dialog').showModal();
            return false;
        }

        function appycrudCancelConfirm() {
            appycrudPendingForm = null;
            document.getElementById('appycrud-confirm-dialog').close();
        }

        function appycrudAcceptConfirm() {
            if (!appycrudPendingForm) {
                return;
            }

            var form = appycrudPendingForm;
            appycrudPendingForm = null;
            document.getElementById('appycrud-confirm-dialog').close();

            fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
                .then(function () { window.location.reload(); });
        }
        </script>
        HTML;
    }

    public function renderForm(TableSchema $schema, array $values, string $baseUrl, bool $isEdit): string
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

            $fields .= $this->renderField($column, (string) ($values[$column->name] ?? ''));
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

    private function renderField(Column $column, string $value): string
    {
        $label = '<label class="block text-sm font-medium text-gray-700 mb-1">' . $this->e($column->label) . '</label>';

        if ($column->inputType === 'textarea') {
            $input = '<textarea name="' . $this->e($column->name) . '" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" rows="4">' . $this->e($value) . '</textarea>';
        } elseif ($column->inputType === 'checkbox') {
            $checked = $value ? 'checked' : '';
            $input = '<input type="checkbox" name="' . $this->e($column->name) . '" ' . $checked . ' class="rounded border-gray-300">';
        } else {
            $input = '<input type="' . $this->e($column->inputType) . '" name="' . $this->e($column->name) . '" value="' . $this->e($value) . '" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"'
                . ($column->maxLength !== null ? ' maxlength="' . $column->maxLength . '"' : '')
                . (!$column->nullable ? ' required' : '')
                . '>';
        }

        return '<div>' . $label . $input . '</div>';
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
