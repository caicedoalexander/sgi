<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use Cake\ORM\TableRegistry;
use DateTime;

class NoveltyPipelineService
{
    /**
     * Get the next status for a novelty, skipping stages disabled by type flags.
     */
    public function getNextStatus(object $novelty, ?object $noveltyType = null): ?string
    {
        $currentStatus = $novelty->pipeline_status;

        if (
            $currentStatus === NoveltyConstants::STATUS_RECHAZADA
            || $currentStatus === NoveltyConstants::STATUS_PAGADA
        ) {
            return null;
        }

        if (!$noveltyType && !empty($novelty->novelty_type)) {
            $noveltyType = $novelty->novelty_type;
        }
        if (!$noveltyType && !empty($novelty->novelty_type_id)) {
            $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
                ->get($novelty->novelty_type_id);
        }

        $nextStatus = NoveltyConstants::TRANSITIONS[$currentStatus] ?? null;

        // Skip stages that are disabled by type flags
        while ($nextStatus && $noveltyType && $this->shouldSkipStage($nextStatus, $noveltyType)) {
            $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
        }

        return $nextStatus;
    }

    /**
     * Check if a pipeline stage should be skipped based on type flags.
     */
    private function shouldSkipStage(string $status, object $noveltyType): bool
    {
        return match ($status) {
            NoveltyConstants::STATUS_RRHH => !$noveltyType->requires_rrhh,
            NoveltyConstants::STATUS_CONTABILIDAD => !$noveltyType->requires_contabilidad,
            NoveltyConstants::STATUS_FIRMAS_APROBACION => !$noveltyType->requires_firmas,
            NoveltyConstants::STATUS_GDP => !$noveltyType->requires_gdp,
            NoveltyConstants::STATUS_TESORERIA => !$noveltyType->requires_tesoreria,
            NoveltyConstants::STATUS_PAGADA => !$noveltyType->requires_tesoreria,
            default => false,
        };
    }

    /**
     * Get the effective pipeline statuses for a novelty type (excluding skipped stages).
     */
    public function getEffectiveStatuses(?object $noveltyType = null): array
    {
        if (!$noveltyType) {
            return NoveltyConstants::PIPELINE_STATUSES;
        }

        return array_values(array_filter(
            NoveltyConstants::PIPELINE_STATUSES,
            fn(string $status) => !$this->shouldSkipStage($status, $noveltyType),
        ));
    }

    /**
     * Advance a single novelty individually.
     * Blocked if novelty has a liquidation_doc_id.
     */
    public function advance(EmployeeNovelty $novelty, int $userId): array
    {
        if ($novelty->isGrouped()) {
            return [
                'success' => false,
                'error' => 'Esta novedad pertenece a un documento de liquidación. Debe avanzar desde el documento grupal.',
            ];
        }

        if ($novelty->isRejected()) {
            return ['success' => false, 'error' => 'La novedad fue rechazada. El flujo ha terminado.'];
        }

        $errors = $this->validateTransition($novelty, $novelty->pipeline_status);
        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors)];
        }

        $nextStatus = $this->getNextStatus($novelty);
        if (!$nextStatus) {
            return ['success' => false, 'error' => 'Esta novedad ya está en el estado final.'];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty->pipeline_status = $nextStatus;

        if (!$noveltiesTable->save($novelty)) {
            return ['success' => false, 'error' => 'No se pudo avanzar el estado.'];
        }

        return ['success' => true, 'error' => null, 'nextStatus' => $nextStatus];
    }

    /**
     * Advance all novelties in a liquidation document group.
     */
    public function advanceGroup(object $liquidationDoc, int $userId): array
    {
        $errors = $this->validateGroupTransition($liquidationDoc);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');

        $members = $noveltiesTable->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->all();

        // Calculate next status using first member's type (all should advance to same group status)
        $firstMember = $members->first();
        if (!$firstMember) {
            return ['success' => false, 'errors' => ['No hay novedades en este documento de liquidación.']];
        }

        $nextGroupStatus = $this->getNextStatus($firstMember, $firstMember->novelty_type);
        if (!$nextGroupStatus) {
            return ['success' => false, 'errors' => ['El documento ya está en el estado final.']];
        }

        $saved = $noveltiesTable->getConnection()->transactional(
            function () use ($noveltiesTable, $liquidationDocsTable, $members, $liquidationDoc, $nextGroupStatus) {
                foreach ($members as $member) {
                    $member->pipeline_status = $nextGroupStatus;
                    if (!$noveltiesTable->save($member)) {
                        return false;
                    }
                }

                $liquidationDoc->pipeline_status = $nextGroupStatus;
                if (!$liquidationDocsTable->save($liquidationDoc)) {
                    return false;
                }

                return true;
            },
        );

        if (!$saved) {
            return ['success' => false, 'errors' => ['No se pudo avanzar el grupo.']];
        }

        return ['success' => true, 'errors' => [], 'nextStatus' => $nextGroupStatus];
    }

    /**
     * Reject a novelty (from any stage).
     */
    public function reject(EmployeeNovelty $novelty, int $userId, ?string $observations = null): array
    {
        if ($novelty->isRejected()) {
            return ['success' => false, 'error' => 'La novedad ya está rechazada.'];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty->pipeline_status = NoveltyConstants::STATUS_RECHAZADA;
        $novelty->approved_by = $userId;
        $novelty->approved_at = new DateTime();

        if ($observations) {
            $novelty->observations = $observations;
        }

        if (!$noveltiesTable->save($novelty)) {
            return ['success' => false, 'error' => 'No se pudo rechazar la novedad.'];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Validate transition requirements for a single novelty.
     */
    public function validateTransition(object $novelty, string $fromStatus): array
    {
        if ($novelty->isRejected()) {
            return ['La novedad fue rechazada. El flujo ha terminado.'];
        }

        $errors = [];

        switch ($fromStatus) {
            case NoveltyConstants::STATUS_RRHH:
                if ($novelty->passes_payroll === null) {
                    $errors[] = 'Debe indicar si "Pasa a Nómina".';
                }
                break;

            case NoveltyConstants::STATUS_CONTABILIDAD:
                if (empty($novelty->liquidation_doc_id)) {
                    $errors[] = 'La novedad debe estar asignada a un documento de liquidación.';
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate transition requirements for a liquidation document group.
     */
    public function validateGroupTransition(object $liquidationDoc): array
    {
        $errors = [];
        $currentStatus = $liquidationDoc->pipeline_status;

        switch ($currentStatus) {
            case NoveltyConstants::STATUS_FIRMAS_APROBACION:
                $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
                $signedCount = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signature_path IS NOT' => null,
                    ])
                    ->count();

                if ($signedCount < count(NoveltyConstants::SIGNER_TYPES)) {
                    $errors[] = 'Todas las firmas requeridas deben estar presentes para avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_GDP:
                if ($liquidationDoc->passes_for_payment === null) {
                    $errors[] = 'Debe indicar si "Pasa para Pago".';
                }
                break;

            case NoveltyConstants::STATUS_TESORERIA:
                if (empty($liquidationDoc->payment_status)) {
                    $errors[] = 'Estado de pago es requerido.';
                }
                if (
                    $liquidationDoc->payment_status === NoveltyConstants::PAYMENT_PAGADO
                    && empty($liquidationDoc->payment_date)
                ) {
                    $errors[] = 'Fecha de pago es requerida cuando el estado es "Pagado".';
                }
                break;
        }

        return $errors;
    }

    /**
     * Check if a novelty can advance individually (not grouped).
     */
    public function canAdvanceIndividually(object $novelty): bool
    {
        return !$novelty->isGrouped();
    }

    /**
     * Assign a novelty to a liquidation document.
     * Creates the document if it doesn't exist yet.
     */
    public function assignToLiquidationDoc(
        EmployeeNovelty $novelty,
        string $liquidationNumber,
        array $data,
        int $userId,
    ): object|array {
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $doc = $liquidationDocsTable->find()
            ->where(['liquidation_number' => $liquidationNumber])
            ->first();

        if (!$doc) {
            $doc = $liquidationDocsTable->newEntity([
                'liquidation_number' => $liquidationNumber,
                'period' => $data['period'] ?? NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
                'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
                'document_date' => $data['document_date'] ?? date('Y-m-d'),
                'performed_by' => $userId,
                'created_by' => $userId,
            ]);

            if (!$liquidationDocsTable->save($doc)) {
                return ['No se pudo crear el documento de liquidación.'];
            }

            // Create signature slots
            $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
            foreach (NoveltyConstants::SIGNER_TYPES as $signerType) {
                $sig = $signaturesTable->newEntity([
                    'liquidation_doc_id' => $doc->id,
                    'signer_type' => $signerType,
                ]);
                $signaturesTable->save($sig);
            }
        }

        $novelty->liquidation_doc_id = $doc->id;
        $novelty->pipeline_status = NoveltyConstants::STATUS_CONTABILIDAD;
        if (!$noveltiesTable->save($novelty)) {
            return ['No se pudo asignar la novedad al documento de liquidación.'];
        }

        return $doc;
    }

    /**
     * Get visible fields for a novelty type in a given pipeline stage.
     */
    public function getVisibleFields(object $noveltyType, string $pipelineStatus): array
    {
        $fields = ['novelty_type_id', 'filing_date', 'reason', 'is_paid'];

        if ($noveltyType->show_permission_date) {
            $fields[] = 'permission_date';
        }
        if ($noveltyType->show_schedule_type) {
            $fields[] = 'schedule_type';
        }
        if ($noveltyType->show_start_date) {
            $fields[] = 'start_date';
        }
        if ($noveltyType->show_end_date) {
            $fields[] = 'end_date';
        }
        if ($noveltyType->uses_custom_name) {
            $fields[] = 'custom_name';
        } else {
            $fields[] = 'employee_id';
        }

        if (in_array($pipelineStatus, [NoveltyConstants::STATUS_RRHH, NoveltyConstants::STATUS_CONTABILIDAD])) {
            $fields[] = 'passes_payroll';
        }

        return $fields;
    }
}
