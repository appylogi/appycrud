<?php

namespace Appylogi\AppyCrud\Renderer;

use Appylogi\AppyCrud\Crud\DeleteMode;
use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Genera el HTML de listado/formulario con clases Tailwind.
 * No depende de ningun framework de JS: crear/editar abren en un <dialog>
 * nativo cargado por fetch, y el envio del formulario tambien va por fetch.
 * Todo el JS vive en un solo bloque, generado una vez por listado.
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
            $deleteConfirm = $deleteMode === DeleteMode::CONFIRM
                ? ' onsubmit="return confirm(' . $this->jsString($t->t('confirm.delete')) . ');"'
                : '';

            $cells .= '<td class="px-4 py-2 text-right text-sm space-x-2">'
                . '<button type="button" onclick="appycrudOpenModal(\'' . $editUrl . '\')" class="text-blue-600 hover:underline">' . $this->e($t->t('list.edit')) . '</button>'
                . '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '" class="inline"' . $deleteConfirm . '>'
                . '<button type="submit" class="text-red-600 hover:underline">' . $this->e($t->t('list.delete')) . '</button>'
                . '</form>'
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
                <button type="button" onclick="appycrudOpenModal('{$createUrl}')" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">{$this->e($t->t('list.new'))}</button>
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
     * Dialog nativo + JS vanilla, se genera una sola vez por pagina de listado.
     * renderForm() asume que este shell ya esta presente (usa sus funciones globales).
     */
    private function renderModalShell(): string
    {
        return <<<'HTML'
        <dialog id="appycrud-dialog" class="rounded-lg shadow-xl p-0 w-full max-w-2xl backdrop:bg-black/50">
            <div id="appycrud-dialog-content"></div>
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

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
