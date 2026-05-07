<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\Domain\Novelty\PipelineStatus as NoveltyPipelineStatus;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Service\Pipeline\Novelty\NoveltyPipelineStateRegistry;
use Cake\ORM\TableRegistry;
use DateTime;

class NoveltyService
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
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
        ],
        RoleConstants::COORDINADOR_ADMIN => [NoveltyConstants::STATUS_REVISION_FIRMAS],
        RoleConstants::TESORERIA => [
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
        ],
        RoleConstants::ADMIN => NoveltyConstants::PIPELINE_STATUSES,
    ];

    // Pipeline statuses applicable to liquidation documents (no 'registro', 'aprobacion', 'rrhh').
    private const LIQUIDATION_ACTIVE_STATUSES = [
        NoveltyConstants::STATUS_CONTABILIDAD,
        NoveltyConstants::STATUS_REVISION_FIRMAS,
        NoveltyConstants::STATUS_GDP,
        NoveltyConstants::STATUS_TESORERIA,
        NoveltyConstants::STATUS_AUTORIZACION_PAGO,
    ];

    // Which liquidation-doc statuses each role sees in "Mis D. de Liquidación".
    private const LIQUIDATION_VISIBLE_STATUSES = [
        RoleConstants::CONTABILIDAD => [NoveltyConstants::STATUS_CONTABILIDAD],
        RoleConstants::TESORERIA => [
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUTORIZACION_PAGO,
        ],
        RoleConstants::CONTADOR => [NoveltyConstants::STATUS_AUTORIZACION_PAGO],
        RoleConstants::REGISTRO_REVISION => [
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
        ],
        RoleConstants::AUXILIAR_PERSONAL => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::ASISTENTE_PERSONAL => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::COORDINADOR_ADMIN => self::LIQUIDATION_ACTIVE_STATUSES,
        RoleConstants::ADMIN => self::LIQUIDATION_ACTIVE_STATUSES,
    ];

    /**
     * Campos editables por paso del pipeline (sin acoplamiento a rol).
     */
    private const FIELDS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['approver_id'],
        NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
    ];

    /**
     * Secciones del formulario asociadas a cada paso (unión cuando el rol opera varios).
     */
    private const SECTIONS_BY_STEP = [
        NoveltyConstants::STATUS_APROBACION => ['informacion', 'fechas', 'motivo', 'aprobacion', 'firmas'],
        NoveltyConstants::STATUS_RRHH => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
        NoveltyConstants::STATUS_CONTABILIDAD => ['informacion', 'fechas', 'contabilidad'],
        NoveltyConstants::STATUS_REVISION_FIRMAS => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_GDP => ['informacion', 'fechas', 'firmas'],
        NoveltyConstants::STATUS_TESORERIA => ['informacion'],
        NoveltyConstants::STATUS_AUTORIZACION_PAGO => ['informacion'],
    ];

    private PipelineAuthorizationService $pipelineAuth;
    private NoveltyPipelineStateRegistry $stateRegistry;

    /**
     * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth Pipeline auth service.
     * @param \App\Service\Pipeline\Novelty\NoveltyPipelineStateRegistry|null $stateRegistry State registry.
     */
    public function __construct(
        ?PipelineAuthorizationService $pipelineAuth = null,
        ?NoveltyPipelineStateRegistry $stateRegistry = null,
    ) {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
        $this->stateRegistry = $stateRegistry ?? new NoveltyPipelineStateRegistry();
    }

    /**
     * Resolve next status applying conditional skips for the novelty type.
     */
    private function resolveNextStatus(object $novelty, ?object $type): ?string
    {
        $currentEnum = NoveltyPipelineStatus::tryFrom((string)$novelty->pipeline_status);
        if ($currentEnum === null || $currentEnum->isTerminal()) {
            return null;
        }

        $nextEnum = $this->stateRegistry->get($currentEnum)->getNextStatus();

        if ($nextEnum === NoveltyPipelineStatus::APROBACION && $type && !$type->requires_boss_approval) {
            $nextEnum = $this->stateRegistry->get($nextEnum)->getNextStatus();
        }
        if ($nextEnum === NoveltyPipelineStatus::GDP && $type && !$type->requires_employee_signature_review) {
            $nextEnum = $this->stateRegistry->get($nextEnum)->getNextStatus();
        }

        return $nextEnum?->value;
    }

    /**
     * Get the next status for a novelty.
     * Only skips aprobacion if the type doesn't require boss approval.
     */
    public function getNextStatus(object $novelty, ?object $noveltyType = null): ?string
    {
        if (!$noveltyType && !empty($novelty->novelty_type)) {
            $noveltyType = $novelty->novelty_type;
        }
        if (!$noveltyType && !empty($novelty->novelty_type_id)) {
            $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
                ->get($novelty->novelty_type_id);
        }

        return $this->resolveNextStatus($novelty, $noveltyType);
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
     * @return \App\Service\ServiceResult on success: data = ['nextStatus' => string]
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
     * @return \App\Service\ServiceResult on success: data = ['nextStatus' => string]
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

        $fromEnum = NoveltyPipelineStatus::tryFrom($fromStatus);
        if ($fromEnum === null) {
            return ["Estado inválido: {$fromStatus}"];
        }

        return $this->stateRegistry->get($fromEnum)->validateAdvanceIndividual($novelty);
    }

    /**
     * Validate transition requirements for a liquidation document group.
     */
    public function validateGroupTransition(object $liquidationDoc): array
    {
        $statusEnum = NoveltyPipelineStatus::tryFrom((string)$liquidationDoc->pipeline_status);
        if ($statusEnum === null) {
            return ["Estado inválido: {$liquidationDoc->pipeline_status}"];
        }

        return $this->stateRegistry->get($statusEnum)->validateAdvanceGroup($liquidationDoc);
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
    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if (
            !$this->pipelineAuth->canOperate(
                $roleId,
                $roleName,
                PipelineStepConstants::PIPELINE_NOVELTIES,
                $status,
            )
        ) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }

    /**
     * Get visible sections for a role in a given status.
     */
    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
        );

        $sections = [];
        foreach ($operableSteps as $step) {
            $sections = array_merge($sections, self::SECTIONS_BY_STEP[$step] ?? []);
        }

        return array_values(array_unique($sections));
    }

    /**
     * Check if a role can advance from a given status.
     */
    public function canAdvanceFromStatus(int $roleId, string $roleName, string $status): bool
    {
        return $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            $status,
        );
    }

    /**
     * Filter entity data to only allowed fields for the role/status.
     */
    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        $allowed = $this->getEditableFields($roleId, $roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }
}
