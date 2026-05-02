<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\EmployeeNovelty;
use Cake\ORM\TableRegistry;
use DateTime;

class NoveltyPipelineService
{
    // Which statuses each role can see/work with in "Mis Novedades"
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::AUXILIAR_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::ASISTENTE_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::CONTABILIDAD => [NoveltyConstants::STATUS_CONTABILIDAD],
        RoleConstants::CONTADOR => [
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_AUT_PAGO,
        ],
        RoleConstants::COORDINADOR_ADMIN => [NoveltyConstants::STATUS_REVISION_FIRMAS],
        RoleConstants::TESORERIA => [
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUT_PAGO,
        ],
        RoleConstants::ADMIN => NoveltyConstants::PIPELINE_STATUSES,
    ];

    // Pipeline statuses applicable to liquidation documents (no 'registro', 'aprobacion', 'rrhh').
    private const LIQUIDATION_ACTIVE_STATUSES = [
        NoveltyConstants::STATUS_CONTABILIDAD,
        NoveltyConstants::STATUS_REVISION_FIRMAS,
        NoveltyConstants::STATUS_GDP,
        NoveltyConstants::STATUS_TESORERIA,
        NoveltyConstants::STATUS_AUT_PAGO,
    ];

    // Which liquidation-doc statuses each role sees in "Mis D. de Liquidación".
    private const LIQUIDATION_VISIBLE_STATUSES = [
        RoleConstants::CONTABILIDAD => [NoveltyConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA => [
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUT_PAGO,
        ],
        RoleConstants::CONTADOR => [NoveltyConstants::STATUS_AUT_PAGO],
        RoleConstants::REGISTRO_REVISION => [
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::AUXILIAR_PERSONAL => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::ASISTENTE_PERSONAL => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::COORDINADOR_ADMIN => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::ADMIN => self::LIQUIDATION_ACTIVE_STATUSES,
    ];

    // All novelty fields (for Admin)
    private const ALL_FIELDS = [
        'approver_id', 'area_approval', 'passes_payroll',
        'rrhh_by', 'liquidation_doc_id',
    ];

    // Fields editable by role in each status
    private const EDITABLE_FIELDS = [
        RoleConstants::AUXILIAR_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION => ['approver_id'],
            NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        ],
        RoleConstants::ASISTENTE_PERSONAL => [
            NoveltyConstants::STATUS_APROBACION => ['approver_id'],
            NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        ],
        RoleConstants::CONTABILIDAD => [
            NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
        ],
    ];

    // Sections visible per role (non-Admin roles have fixed sections)
    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::AUXILIAR_PERSONAL => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas',
        ],
        RoleConstants::ASISTENTE_PERSONAL => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas',
        ],
        RoleConstants::CONTABILIDAD => ['informacion', 'fechas', 'contabilidad'],
        RoleConstants::CONTADOR => ['informacion', 'fechas', 'firmas'],
        RoleConstants::COORDINADOR_ADMIN => ['informacion', 'fechas', 'firmas'],
        RoleConstants::TESORERIA => ['informacion'],
    ];

    // All sections in pipeline order (for Admin)
    private const ALL_SECTIONS = [
        'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
    ];

    // Map pipeline statuses to which sections are visible up to that point (for Admin)
    private const SECTIONS_BY_STATUS = [
        NoveltyConstants::STATUS_APROBACION => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'firmas',
        ],
        NoveltyConstants::STATUS_RRHH => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas',
        ],
        NoveltyConstants::STATUS_CONTABILIDAD => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
        NoveltyConstants::STATUS_REVISION_FIRMAS => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
        NoveltyConstants::STATUS_GDP => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
        NoveltyConstants::STATUS_TESORERIA => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
        NoveltyConstants::STATUS_AUT_PAGO => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
        NoveltyConstants::STATUS_PAGADA => [
            'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
        ],
    ];

    /**
     * Get the next status for a novelty.
     * Only skips aprobacion if the type doesn't require boss approval.
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

        // Skip aprobacion if type doesn't require boss approval
        if ($nextStatus === NoveltyConstants::STATUS_APROBACION && $noveltyType && !$noveltyType->requires_boss_approval) {
            $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
        }

        // Skip GDP if type doesn't require employee signature review
        if ($nextStatus === NoveltyConstants::STATUS_GDP && $noveltyType && !$noveltyType->requires_employee_signature_review) {
            $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
        }

        return $nextStatus;
    }

    /**
     * Get the effective pipeline statuses for a novelty type (full pipeline).
     */
    public function getEffectiveStatuses(?object $noveltyType = null): array
    {
        $statuses = NoveltyConstants::PIPELINE_STATUSES;

        if ($noveltyType && !$noveltyType->requires_boss_approval) {
            $statuses = array_filter($statuses, fn(string $s) => $s !== NoveltyConstants::STATUS_APROBACION);
        }
        if ($noveltyType && !$noveltyType->requires_employee_signature_review) {
            $statuses = array_filter($statuses, fn(string $s) => $s !== NoveltyConstants::STATUS_GDP);
        }

        return array_values($statuses);
    }

    /**
     * Get pipeline statuses for the individual novelty phase
     * (before liquidation doc takes over).
     */
    public function getNoveltyStatuses(?object $noveltyType = null): array
    {
        if (!$noveltyType || $noveltyType->requires_boss_approval) {
            return NoveltyConstants::NOVELTY_STATUSES;
        }

        return array_values(array_filter(
            NoveltyConstants::NOVELTY_STATUSES,
            fn(string $status) => $status !== NoveltyConstants::STATUS_APROBACION,
        ));
    }

    /**
     * Advance a single novelty individually.
     * Blocked if novelty has a liquidation_doc_id.
     *
     * @return ServiceResult on success: data = ['nextStatus' => string]
     */
    public function advance(EmployeeNovelty $novelty, int $userId): ServiceResult
    {
        if ($novelty->isGrouped()) {
            return ServiceResult::fail([
                'Esta novedad pertenece a un documento de liquidación. Debe avanzar desde el documento grupal.',
            ]);
        }

        if ($novelty->isRejected()) {
            return ServiceResult::fail(['La novedad fue rechazada. El flujo ha terminado.']);
        }

        $errors = $this->validateTransition($novelty, $novelty->pipeline_status);
        if (!empty($errors)) {
            return ServiceResult::fail($errors);
        }

        $nextStatus = $this->getNextStatus($novelty);
        if (!$nextStatus) {
            return ServiceResult::fail(['Esta novedad ya está en el estado final.']);
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $result = $noveltiesTable->getConnection()->transactional(function () use ($noveltiesTable, $novelty, $nextStatus) {
            $novelty->pipeline_status = $nextStatus;

            return $noveltiesTable->save($novelty);
        });

        if (!$result) {
            return ServiceResult::fail(['No se pudo avanzar el estado.']);
        }

        return ServiceResult::ok(['nextStatus' => $nextStatus]);
    }

    /**
     * Advance all novelties in a liquidation document group.
     *
     * @return ServiceResult on success: data = ['nextStatus' => string]
     */
    public function advanceGroup(object $liquidationDoc, int $userId): ServiceResult
    {
        $errors = $this->validateGroupTransition($liquidationDoc);
        if (!empty($errors)) {
            return ServiceResult::fail($errors);
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');

        $members = $noveltiesTable->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->all();

        $firstMember = $members->first();
        if (!$firstMember) {
            return ServiceResult::fail(['No hay novedades en este documento de liquidación.']);
        }

        $nextGroupStatus = $this->getNextStatus($firstMember, $firstMember->novelty_type);
        if (!$nextGroupStatus) {
            return ServiceResult::fail(['El documento ya está en el estado final.']);
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
            return ServiceResult::fail(['No se pudo avanzar el grupo.']);
        }

        return ServiceResult::ok(['nextStatus' => $nextGroupStatus]);
    }

    /**
     * Reject a novelty (from any stage).
     */
    public function reject(EmployeeNovelty $novelty, int $userId, ?string $observations = null): ServiceResult
    {
        if ($novelty->isRejected()) {
            return ServiceResult::fail(['La novedad ya está rechazada.']);
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty->pipeline_status = NoveltyConstants::STATUS_RECHAZADA;
        $novelty->approved_by = $userId;
        $novelty->approved_at = new DateTime();

        if ($observations) {
            $novelty->observations = $observations;
        }

        if (!$noveltiesTable->save($novelty)) {
            return ServiceResult::fail(['No se pudo rechazar la novedad.']);
        }

        return ServiceResult::ok();
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
            case NoveltyConstants::STATUS_APROBACION:
                if (empty($novelty->approver_id)) {
                    $errors[] = 'Debe asignar un aprobador.';
                }
                if (!empty($novelty->area_approval) && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED) {
                    $errors[] = 'La novedad fue rechazada por el aprobador. Edite y reenvíe para aprobación.';
                }
                break;

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
            case NoveltyConstants::STATUS_CONTABILIDAD:
                $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
                $hasLiqDoc = $documentsTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'document_type' => NoveltyConstants::DOC_TYPE_LIQUIDATION,
                    ])
                    ->count();

                if ($hasLiqDoc === 0) {
                    $errors[] = 'Debe subir el documento de liquidación antes de avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_REVISION_FIRMAS:
                $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

                // Only validate non-worker signatures in revision_firmas
                $totalSlots = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
                    ])
                    ->count();

                $signedCount = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signer_type !=' => NoveltyConstants::SIGNER_TRABAJADOR,
                        'signature_path IS NOT' => null,
                    ])
                    ->count();

                if ($signedCount < $totalSlots) {
                    $errors[] = 'Todas las firmas requeridas (Contador y Coordinador) deben estar presentes para avanzar.';
                }

                // If GDP will be skipped, validate passes_for_payment here
                $firstMember = $this->_getFirstGroupMember($liquidationDoc);
                if ($firstMember && $firstMember->novelty_type && !$firstMember->novelty_type->requires_employee_signature_review) {
                    if ($liquidationDoc->passes_for_payment === null) {
                        $errors[] = 'Debe indicar si "Pasa para Pago".';
                    }
                }
                break;

            case NoveltyConstants::STATUS_GDP:
                if ($liquidationDoc->passes_for_payment === null) {
                    $errors[] = 'Debe indicar si "Pasa para Pago".';
                }

                // Validate worker signature
                $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
                $workerSlot = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signer_type' => NoveltyConstants::SIGNER_TRABAJADOR,
                    ])
                    ->first();

                if ($workerSlot && empty($workerSlot->signature_path)) {
                    $errors[] = 'La firma del trabajador es requerida para avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_TESORERIA:
                $errors[] = 'Debe registrar un pago para avanzar desde Tesorería.';
                break;

            case NoveltyConstants::STATUS_AUT_PAGO:
                $errors[] = 'La autorización de pago se gestiona desde la sección de pagos.';
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
     * Creates 2 or 3 signature slots based on type's requires_employee_signature_review.
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

            // Determine which signature slots to create
            $signerTypes = [
                NoveltyConstants::SIGNER_CONTADOR,
                NoveltyConstants::SIGNER_COORDINADOR_ADMIN,
            ];

            // Check if any novelty type requires employee signature in review
            $noveltyType = null;
            if (!empty($novelty->novelty_type)) {
                $noveltyType = $novelty->novelty_type;
            } elseif (!empty($novelty->novelty_type_id)) {
                $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
                    ->get($novelty->novelty_type_id);
            }

            if ($noveltyType && $noveltyType->requires_employee_signature_review) {
                $signerTypes[] = NoveltyConstants::SIGNER_TRABAJADOR;
            }

            $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
            foreach ($signerTypes as $signerType) {
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

        if ($pipelineStatus === NoveltyConstants::STATUS_APROBACION) {
            $fields[] = 'approver_id';
        }

        return $fields;
    }

    /**
     * Get the statuses visible to a role in "Mis Novedades".
     */
    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * Get the liquidation-doc statuses visible to a role in "Mis D. de Liquidación".
     */
    public function getVisibleLiquidationStatuses(string $roleName): array
    {
        return self::LIQUIDATION_VISIBLE_STATUSES[$roleName] ?? [];
    }

    /**
     * Get editable fields for a role in a given status.
     */
    public function getEditableFields(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        return self::EDITABLE_FIELDS[$roleName][$status] ?? [];
    }

    /**
     * Get visible sections for a role in a given status.
     */
    public function getVisibleSections(string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::SECTIONS_BY_STATUS[$status] ?? self::ALL_SECTIONS;
        }

        return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? [];
    }

    /**
     * Check if a role can advance from a given status.
     */
    public function canAdvanceFromStatus(string $roleName, string $status): bool
    {
        $visible = self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];

        return in_array($status, $visible, true);
    }

    /**
     * Filter entity data to only allowed fields for the role/status.
     */
    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        $allowed = $this->getEditableFields($roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * Get the first novelty member of a liquidation document group with its type.
     */
    private function _getFirstGroupMember(object $liquidationDoc): ?object
    {
        return TableRegistry::getTableLocator()->get('EmployeeNovelties')
            ->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->first();
    }
}
