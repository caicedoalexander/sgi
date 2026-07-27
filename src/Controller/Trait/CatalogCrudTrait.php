<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use Cake\Datasource\EntityInterface;
use Cake\Http\Response;
use Cake\ORM\Table;

/**
 * CRUD de catálogo con soporte de modal AJAX.
 *
 * Las acciones add()/edit() de los catálogos delegan el guardado a
 * {@see self::_catalogSave()}, que maneja tres caminos:
 *  - POST/PUT OK:    flash de éxito + (AJAX → JSON {success:true}; página → redirect index).
 *  - POST/PUT falla: AJAX → status 422 y se renderiza solo el form (errores inline en el modal);
 *                    página → flash de error y re-render normal.
 *  - GET AJAX:       layout 'ajax' para devolver solo el fragmento del form (se inyecta en el modal).
 *
 * El JS (webroot/js/spi-catalog-modal.js) distingue éxito (JSON) de error (HTML)
 * por content-type; en éxito recarga el index (el flash de sesión muestra el toast).
 */
trait CatalogCrudTrait
{
    /**
     * @param \Cake\ORM\Table $table Tabla del catálogo.
     * @param \Cake\Datasource\EntityInterface $entity Entidad nueva (add) o cargada (edit).
     * @param string $successMessage Mensaje flash de éxito.
     * @param string $errorMessage Mensaje flash de error (solo render de página).
     * @return \Cake\Http\Response|null Response para cortar la acción, o null para que renderice.
     */
    protected function _catalogSave(
        Table $table,
        EntityInterface $entity,
        string $successMessage,
        string $errorMessage,
    ): ?Response {
        $isAjax = $this->request->is('ajax');

        if ($this->request->is(['post', 'patch', 'put'])) {
            $table->patchEntity($entity, $this->request->getData());
            if ($table->save($entity)) {
                $this->Flash->success($successMessage);
                if ($isAjax) {
                    return $this->response
                        ->withType('application/json')
                        ->withStringBody((string)json_encode(['success' => true]));
                }

                return $this->redirect(['action' => 'index']);
            }

            if ($isAjax) {
                $this->response = $this->response->withStatus(422);
            } else {
                $this->Flash->error($errorMessage);
            }
        }

        if ($isAjax) {
            $this->viewBuilder()->setLayout('ajax');
        }

        return null;
    }
}
