<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\ObservationConstants;
use Cake\Http\Response;

/**
 * Maneja la rama JSON/HTML uniforme de los `addObservation` actions.
 *
 * Consumido por los 6 controllers con chat de observaciones
 * (Invoices, PaymentSchedulings, EmployeeNovelties — vía constants only —,
 * Employees, PettyCashRecords, Refunds). El contrato JSON debe mantenerse
 * sincronizado con `webroot/js/sgi-observation-chat.js`.
 */
trait ObservationControllerTrait
{
    /**
     * Persiste una observación y devuelve la respuesta apropiada (JSON o
     * redirect con Flash) según el tipo de request.
     *
     * @param string $tableAlias Alias de la tabla de observaciones (ej. 'InvoiceObservations').
     * @param string $fkField Nombre de la FK al record padre (ej. 'invoice_id').
     * @param mixed $recordId Identificador del record padre.
     * @param object $user Usuario actual (debe exponer `id` y `full_name`).
     * @param callable():Response $redirectFallback Closure que produce el redirect HTML.
     * @return \Cake\Http\Response
     */
    protected function _handleAddObservation(
        string $tableAlias,
        string $fkField,
        mixed $recordId,
        object $user,
        callable $redirectFallback,
    ): Response {
        $this->request->allowMethod(['post']);
        $message = trim((string)$this->request->getData('message'));

        $observationsTable = $this->fetchTable($tableAlias);
        $observation = $observationsTable->newEntity([
            $fkField => $recordId,
            'user_id' => $user->id,
            'message' => $message,
        ]);

        $saved = $message !== '' && $observationsTable->save($observation);

        if ($this->_isJsonRequest()) {
            if (!$saved) {
                return $this->_jsonResponse([
                    'success' => false,
                    'error' => $message === ''
                        ? ObservationConstants::ERR_EMPTY
                        : ObservationConstants::ERR_SAVE_FAILED,
                ]);
            }

            return $this->_jsonResponse([
                'success' => true,
                'observation' => [
                    'id' => $observation->id,
                    'message' => $observation->message,
                    'user_name' => $user->full_name,
                    'created' => $observation->created->format(ObservationConstants::DATE_FORMAT),
                ],
            ]);
        }

        if ($saved) {
            $this->Flash->success(ObservationConstants::MSG_ADDED);
        } else {
            $this->Flash->error(
                $message === ''
                    ? ObservationConstants::ERR_EMPTY
                    : ObservationConstants::ERR_SAVE_FAILED,
            );
        }

        return $redirectFallback();
    }
}
