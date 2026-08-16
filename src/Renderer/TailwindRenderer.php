<?php

namespace Appylogi\AppyCrud\Renderer;

use Appylogi\AppyCrud\Lang\Translator;
use Appylogi\AppyCrud\Schema\Column;
use Appylogi\AppyCrud\Schema\TableSchema;

/**
 * Genera el HTML de listado/formulario con clases Tailwind.
 * No depende de ningun framework de JS: el unico script es el confirm()
 * de eliminar, en vanilla JS.
 */
class TailwindRenderer
{
    public function __construct(private Translator $translator)
    {
    }

    public function renderList(TableSchema $schema, array $pagination, string $baseUrl): string
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
            $cells .= '<td class="px-4 py-2 text-right text-sm space-x-2">'
                . '<a href="' . $this->e($baseUrl) . '?action=edit&id=' . $this->e((string) $pkValue) . '" class="text-blue-600 hover:underline">' . $this->e($t->t('list.edit')) . '</a>'
                . '<form method="post" action="' . $this->e($baseUrl) . '?action=delete&id=' . $this->e((string) $pkValue) . '" class="inline" onsubmit="return confirm(' . $this->jsString($t->t('confirm.delete')) . ');">'
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

        return <<<HTML
        <div class="max-w-5xl mx-auto p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-900">{$this->e($t->t('list.title', ['table' => $schema->table]))}</h1>
                <a href="{$this->e($baseUrl)}?action=create" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">{$this->e($t->t('list.new'))}</a>
            </div>
            <div class="overflow-x-auto bg-white rounded-lg shadow">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>{$headers}</tr></thead>
                    <tbody>{$bodyRows}</tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-gray-500">{$this->e($pageInfo)}</p>
        </div>
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
        <div class="max-w-2xl mx-auto p-6">
            <h1 class="text-xl font-bold text-gray-900 mb-4">{$this->e($title)}</h1>
            <form method="post" action="{$this->e($baseUrl)}?action={$action}&id={$this->e((string) $pkValue)}" class="bg-white rounded-lg shadow p-6 space-y-4">
                {$fields}
                <div class="flex justify-end gap-3 pt-2">
                    <a href="{$this->e($baseUrl)}" class="px-4 py-2 text-sm text-gray-600 hover:underline">{$this->e($t->t('form.cancel'))}</a>
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
