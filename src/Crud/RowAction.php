<?php

namespace Appylogi\AppyCrud\Crud;

/**
 * Accion custom agregada al menu de cada fila del listado (junto a Ver/
 * Editar/Clonar/Eliminar). AppyCrud la despacha como cualquier otra accion:
 * al hacer clic, navega/hace fetch a "?action={name}&id={id}" y ejecuta $handler.
 *
 * Ejemplos:
 *
 *   // Abre en el mismo modal (fetch), como Ver/Editar. $handler debe devolver HTML.
 *   new RowAction('marcar_revisado', 'Marcar revisado', function (mixed $id) use ($repository) {
 *       $repository->update($id, ['revisado' => 1]);
 *       return '<div class="p-6">Listo.</div>';
 *   });
 *
 *   // Link normal (navegacion o descarga). $handler puede hacer header()+exit.
 *   new RowAction('descargar_pdf', 'Descargar PDF', function (mixed $id) { ... }, icon: 'download', openInModal: false);
 *
 *   // Requiere confirmacion y es una escritura (POST), como Eliminar.
 *   new RowAction('archivar', 'Archivar', function (mixed $id) use ($repository) {
 *       $repository->update($id, ['archivada' => 1]);
 *       return ''; // el valor de retorno no importa para method 'post': siempre recarga la pagina
 *   }, icon: 'trash', confirm: 'Archivar este registro?', method: 'post');
 */
class RowAction
{
    /** @param callable(mixed $id, array $get, array $post): string $handler */
    public function __construct(
        public string $name,
        public string $label,
        public $handler,
        public ?string $icon = null,
        public ?string $confirm = null,
        public string $method = 'get',
        public bool $openInModal = true,
    ) {
    }
}
