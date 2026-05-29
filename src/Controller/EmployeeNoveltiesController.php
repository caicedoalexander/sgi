<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Attribute\PipelineAction;
use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Controller\Trait\ObservationControllerTrait;
use App\Model\Entity\EmployeeNovelty;
use App\Service\ApprovalTokenService;
use App\Service\EmailLogService;
use App\Service\LeaveDocumentService;
use App\Service\NotificationService;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use App\View\Presentation\NoveltyPresentation;
use App\ViewModel\EmployeeNoveltyAddViewModel;
use App\ViewModel\EmployeeNoveltyEditViewModel;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Exception;

class EmployeeNoveltiesController extends AppController
{
    use ObservationControllerTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;

    private NoveltyDocumentService $documentService;

    private NoveltyObservationService $observationService;

    private NoveltyHistoryService $historyService;

    private LeaveDocumentService $leaveDocumentService;

    private NoveltySignatureService $signatureService;

    private ApprovalTokenService $tokenService;

    private NotificationService $notificationService;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->pipelineService = $container->get(NoveltyPipelineService::class);
        $this->documentService = $container->get(NoveltyDocumentService::class);
        $this->observationService = $container->get(NoveltyObservationService::class);
        $this->historyService = $container->get(NoveltyHistoryService::class);
        $this->leaveDocumentService = $container->get(LeaveDocumentService::class);
        $this->signatureService = $container->get(NoveltySignatureService::class);
        $this->tokenService = $container->get(ApprovalTokenService::class);
        $this->notificationService = $container->get(NotificationService::class);
    }

    /**
     * Index — "Mis Novedades" filtered by role's visible statuses.
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleId = (int)$user->role_id;
        $visibleStatuses = $this->pipelineService->getVisibleStatuses($roleId);

        $conditions = $this->_visibleStatusConditions('EmployeeNovelties.pipeline_status', $visibleStatuses);
        // Exclude rejected from "Mis Novedades"
        $conditions['EmployeeNovelties.pipeline_status !='] = NoveltyConstants::STATUS_RECHAZADA;

        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->where($conditions)
            ->where(function ($exp) {
                // In contabilidad, only show novelties not yet linked to a liquidation doc
                return $exp->or([
                    'EmployeeNovelties.pipeline_status !=' => NoveltyConstants::STATUS_CONTABILIDAD,
                    'EmployeeNovelties.liquidation_doc_id IS' => null,
                ]);
            })
            ->orderBy(['EmployeeNovelties.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.pipeline_status' => $statusFilter]);
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        [$upcomingNovelties, $typeDistribution] = $this->_buildSideRailData(clone $query);

        $this->set(compact(
            'novelties',
            'statusFilter',
            'typeFilter',
            'noveltyTypes',
            'visibleStatuses',
            'upcomingNovelties',
            'typeDistribution',
        ));
    }

    /**
     * Build the side-rail data for the index template: upcoming novelties
     * and per-type distribution over the visible set.
     *
     * @param \Cake\ORM\Query\SelectQuery $visibleQuery Query already filtered to the visible novelties.
     * @return array{0: array<\App\Model\Entity\EmployeeNovelty>, 1: array<string, int>}
     */
    private function _buildSideRailData($visibleQuery): array
    {
        $today = date('Y-m-d');

        // Próximas ~6 novedades por fecha de inicio desde hoy en adelante.
        $upcoming = (clone $visibleQuery)
            ->contain(['Employees', 'NoveltyTypes'])
            ->where(function ($exp) use ($today) {
                return $exp->or([
                    'EmployeeNovelties.start_date >=' => $today,
                    'EmployeeNovelties.permission_date >=' => $today,
                ]);
            })
            ->orderBy([
                'EmployeeNovelties.start_date' => 'ASC',
                'EmployeeNovelties.permission_date' => 'ASC',
            ], true)
            ->limit(6)
            ->all()
            ->toArray();

        // Distribución por tipo sobre el conjunto visible completo.
        $distQuery = (clone $visibleQuery);
        $distRows = $distQuery
            ->select([
                'name' => 'NoveltyTypes.name',
                'count' => $distQuery->func()->count('EmployeeNovelties.id'),
            ])
            ->contain([])
            ->innerJoinWith('NoveltyTypes')
            ->groupBy(['NoveltyTypes.name'])
            ->orderBy(['count' => 'DESC'], true)
            ->disableHydration()
            ->all();

        $distribution = [];
        foreach ($distRows as $row) {
            $distribution[(string)($row['name'] ?? 'Sin tipo')] = (int)$row['count'];
        }

        return [$upcoming, $distribution];
    }

    /**
     * All — "Todas las Novedades" (read-only, all statuses).
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function all()
    {
        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->orderBy(['EmployeeNovelties.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.pipeline_status' => $statusFilter]);
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $employees = $this->EmployeeNovelties->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->orderBy(['first_name' => 'ASC', 'last_name1' => 'ASC'])->toArray();

        $visibleStatuses = [];

        [$upcomingNovelties, $typeDistribution] = $this->_buildSideRailData(clone $query);

        $this->set(compact(
            'novelties',
            'statusFilter',
            'typeFilter',
            'noveltyTypes',
            'employees',
            'visibleStatuses',
            'upcomingNovelties',
            'typeDistribution',
        ));
        $this->render('index');
    }

    /**
     * Rejected — "Novedades Rechazadas".
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function rejected()
    {
        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->where(['EmployeeNovelties.pipeline_status' => NoveltyConstants::STATUS_RECHAZADA])
            ->orderBy(['EmployeeNovelties.created' => 'DESC']);

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $query->where(['EmployeeNovelties.novelty_type_id' => $typeFilter]);
        }

        $novelties = $this->paginate($query);

        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $statusFilter = null;
        $visibleStatuses = [];

        [$upcomingNovelties, $typeDistribution] = $this->_buildSideRailData(clone $query);

        $this->set(compact(
            'novelties',
            'statusFilter',
            'typeFilter',
            'noveltyTypes',
            'visibleStatuses',
            'upcomingNovelties',
            'typeDistribution',
        ));
        $this->render('index');
    }

    /**
     * Active — Calendar view of currently active novelties.
     *
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function active()
    {
        $noveltyTypes = $this->EmployeeNovelties->NoveltyTypes->find('list', [
            'keyField' => 'id',
            'valueField' => 'name',
        ])->toArray();

        $employees = $this->EmployeeNovelties->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->orderBy(['first_name' => 'ASC', 'last_name1' => 'ASC'])->toArray();

        $this->set(compact('noveltyTypes', 'employees'));
    }

    /**
     * ActiveEvents — JSON endpoint for FullCalendar events.
     *
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'view')]
    public function activeEvents(): Response
    {
        $this->request->allowMethod(['get']);

        $start = $this->request->getQuery('start');
        $end = $this->request->getQuery('end');

        if (!$start || !$end) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode([]));
        }

        $today = date('Y-m-d');

        $conditions = [
            'EmployeeNovelties.pipeline_status IN' => NoveltyConstants::ACTIVE_STATUSES,
        ];

        // "Vigente" means today falls within the novelty's date range
        $conditions[] = function ($exp) use ($today) {
            return $exp->or([
                // days: today is between start_date and end_date
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                    'EmployeeNovelties.start_date <=' => $today,
                    'EmployeeNovelties.end_date >=' => $today,
                ]),
                // hours: permission_date is today
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                    'EmployeeNovelties.permission_date' => $today,
                ]),
            ]);
        };

        // Optional filters
        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $conditions['EmployeeNovelties.novelty_type_id'] = $typeFilter;
        }

        $employeeFilter = $this->request->getQuery('employee_id');
        if ($employeeFilter) {
            $conditions['EmployeeNovelties.employee_id'] = $employeeFilter;
        }

        $novelties = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes'])
            ->where($conditions)
            ->all();

        $colors = NoveltyPresentation::CALENDAR_COLORS;
        $colorCount = count($colors);

        $events = [];
        foreach ($novelties as $novelty) {
            $employeeName = $novelty->employee ? $novelty->employee->full_name : ($novelty->custom_name ?? 'Sin empleado');
            $typeName = $novelty->novelty_type ? $novelty->novelty_type->name : 'Sin tipo';
            $color = $colors[($novelty->novelty_type_id - 1) % $colorCount];

            if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS) {
                $eventStart = $novelty->start_date->format('Y-m-d');
                // FullCalendar end is exclusive, so add 1 day
                $eventEnd = $novelty->end_date->modify('+1 day')->format('Y-m-d');
            } else {
                $eventStart = $novelty->permission_date->format('Y-m-d');
                $eventEnd = $eventStart;
            }

            $events[] = [
                'id' => $novelty->id,
                'title' => $employeeName . ' - ' . $typeName,
                'start' => $eventStart,
                'end' => $eventEnd,
                'color' => $color,
                'url' => Router::url(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]),
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode($events));
    }

    /**
     * AllEvents — JSON endpoint for FullCalendar showing all novelties in a date range.
     *
     * @return \Cake\Http\Response
     */
    #[Permission(action: 'view')]
    public function allEvents(): Response
    {
        $this->request->allowMethod(['get']);

        $start = $this->request->getQuery('start');
        $end = $this->request->getQuery('end');

        if (!$start || !$end) {
            return $this->response->withType('application/json')
                ->withStringBody(json_encode([]));
        }

        $conditions = [];

        // Date range overlap with calendar visible range
        $conditions[] = function ($exp) use ($start, $end) {
            return $exp->or([
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_DAYS,
                    'EmployeeNovelties.start_date <=' => $end,
                    'EmployeeNovelties.end_date >=' => $start,
                ]),
                $exp->and([
                    'EmployeeNovelties.schedule_type' => NoveltyConstants::SCHEDULE_HOURS,
                    'EmployeeNovelties.permission_date >=' => $start,
                    'EmployeeNovelties.permission_date <=' => $end,
                ]),
            ]);
        };

        // Optional filters
        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $conditions['EmployeeNovelties.pipeline_status'] = $statusFilter;
        }

        $typeFilter = $this->request->getQuery('novelty_type_id');
        if ($typeFilter) {
            $conditions['EmployeeNovelties.novelty_type_id'] = $typeFilter;
        }

        $employeeFilter = $this->request->getQuery('employee_id');
        if ($employeeFilter) {
            $conditions['EmployeeNovelties.employee_id'] = $employeeFilter;
        }

        $novelties = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes'])
            ->where($conditions)
            ->all();

        $colors = NoveltyPresentation::CALENDAR_COLORS;
        $colorCount = count($colors);
        $statusLabels = NoveltyConstants::STATUS_LABELS;

        $events = [];
        foreach ($novelties as $novelty) {
            $employeeName = $novelty->employee ? $novelty->employee->full_name : ($novelty->custom_name ?? 'Sin empleado');
            $typeName = $novelty->novelty_type ? $novelty->novelty_type->name : 'Sin tipo';
            $color = $colors[($novelty->novelty_type_id - 1) % $colorCount];

            if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS) {
                $eventStart = $novelty->start_date->format('Y-m-d');
                $eventEnd = $novelty->end_date->modify('+1 day')->format('Y-m-d');
            } else {
                $eventStart = $novelty->permission_date->format('Y-m-d');
                $eventEnd = $eventStart;
            }

            $events[] = [
                'id' => $novelty->id,
                'title' => $employeeName . ' - ' . $typeName,
                'start' => $eventStart,
                'end' => $eventEnd,
                'color' => $color,
                'url' => Router::url(['controller' => 'EmployeeNovelties', 'action' => 'view', $novelty->id]),
                'extendedProps' => [
                    'status' => $statusLabels[$novelty->pipeline_status] ?? $novelty->pipeline_status,
                ],
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode($events));
    }

    /**
     * Edit action — main working view (like invoices/edit).
     *
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
            'ApprovedByUsers',
            'RegisteredByUsers',
            'RrhhByUsers',
            'NoveltyLiquidationDocs',
            'NoveltyObservations' => [
                'Users',
                'sort' => ['NoveltyObservations.created' => 'ASC'],
            ],
            'NoveltyDocuments' => [
                'UploadedByUsers',
                'sort' => ['NoveltyDocuments.created' => 'DESC'],
            ],
            'NoveltyMassiveEmployees' => ['Employees'],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        $this->observationService->markAsRead($user->id, noveltyId: $novelty->id);

        $vm = $this->_buildEditViewModel($novelty, (int)$user->role_id, $roleName);
        $this->set('viewModel', $vm);
    }

    /**
     * Build the view model for the edit action.
     */
    private function _buildEditViewModel(
        EmployeeNovelty $novelty,
        int $roleId,
        string $roleName,
    ): EmployeeNoveltyEditViewModel {
        $editableFields = $this->pipelineService->getEditableFields($roleId, $novelty->pipeline_status);
        $visibleSections = $this->pipelineService->getVisibleSections($roleId, $novelty->pipeline_status);
        if (empty($visibleSections)) {
            // Defensive fallback: rol sin pasos operables ve las secciones canónicas (read-only).
            $visibleSections = ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas'];
        }
        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($novelty->novelty_type);
        $noveltyStatuses = $this->pipelineService->getNoveltyStatuses($novelty->novelty_type);
        $nextStatus = $this->pipelineService->getNextStatus($novelty);
        $transitionErrors = $this->pipelineService->validateTransitionRequirements($novelty, $novelty->pipeline_status);
        // isGrouped no es estado de pipeline (vive en liquidation_doc_id) — se compone aparte.
        $canAdvance = !$novelty->isGrouped()
            && $this->pipelineService->denialReasonForAdvance($novelty, $roleId) === null;

        $isApprovalRejected = $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION
            && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED;

        $approversList = [];
        if ($novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION) {
            $approvers = TableRegistry::getTableLocator()->get('Approvers')
                ->find()
                ->contain(['Users'])
                ->where(['Approvers.active' => true])
                ->all();
            foreach ($approvers as $approver) {
                if ($approver->user) {
                    $approversList[$approver->user->id] = $approver->user->full_name;
                }
            }
        }

        $documentsByStatus = $this->documentService->getDocumentsByStatus($novelty->id);

        $liquidationDocs = [];
        if (
            $novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD
            && !$novelty->isGrouped()
        ) {
            $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
            $liquidationDocs = $liquidationDocsTable->find('list', [
                'keyField' => 'id',
                'valueField' => 'liquidation_number',
            ])->where(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->toArray();
        }

        $emailLogService = $this->getContainer()->get(EmailLogService::class);
        $emailLogs = $emailLogService->forEntity('employee_novelty', (int)$novelty->id);

        return new EmployeeNoveltyEditViewModel(
            novelty: $novelty,
            roleName: $roleName,
            editableFields: $editableFields,
            visibleSections: $visibleSections,
            effectiveStatuses: $effectiveStatuses,
            noveltyStatuses: $noveltyStatuses,
            nextStatus: $nextStatus,
            transitionErrors: $transitionErrors,
            canAdvance: $canAdvance,
            isApprovalRejected: $isApprovalRejected,
            approversList: $approversList,
            documentsByStatus: $documentsByStatus,
            liquidationDocs: $liquidationDocs,
            emailLogs: $emailLogs,
        );
    }

    /**
     * View action — read-only detail with PDF export and history.
     *
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
            'ApprovedByUsers',
            'RegisteredByUsers',
            'RrhhByUsers',
            'NoveltyLiquidationDocs',
            'NoveltyObservations' => [
                'Users',
                'sort' => ['NoveltyObservations.created' => 'ASC'],
            ],
            'NoveltyDocuments' => [
                'UploadedByUsers',
                'sort' => ['NoveltyDocuments.created' => 'DESC'],
            ],
            'NoveltyMassiveEmployees' => ['Employees'],
            'NoveltyHistories' => [
                'Users',
                'sort' => ['NoveltyHistories.created' => 'DESC'],
            ],
        ]);

        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($novelty->novelty_type);
        $noveltyStatuses = $this->pipelineService->getNoveltyStatuses($novelty->novelty_type);

        $documentsByStatus = $this->documentService->getDocumentsByStatus($novelty->id);

        // PDF template check
        $employee = $novelty->employee;
        $template = $employee ? $this->leaveDocumentService->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        ) : null;
        $hasActiveTemplate = $template && $template->is_active;

        $fieldLabels = NoveltyHistoryService::FIELD_LABELS;

        $this->set(compact(
            'novelty',
            'effectiveStatuses',
            'noveltyStatuses',
            'documentsByStatus',
            'hasActiveTemplate',
            'fieldLabels',
        ));
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'view')]
    public function exportPdf(?string $id = null): ?Response
    {
        $this->autoRender = false;

        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
        ]);

        $employee = $novelty->employee;
        $template = $this->leaveDocumentService->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        );

        if (!$template || !$template->is_active) {
            $this->Flash->error('No hay plantilla de documento configurada para el tipo de contrato de este empleado.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $pdfContent = $this->leaveDocumentService->generatePdf((int)$id, (int)$template->id);

        return $this->response
            ->withType('application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="novedad_' . $id . '.pdf"')
            ->withStringBody($pdfContent);
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $novelty = $this->EmployeeNovelties->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['filing_date'] = Date::now()->format('Y-m-d');

            // Determine initial status based on type's requires_boss_approval
            $initialStatus = NoveltyConstants::STATUS_RRHH;
            $noveltyType = null;
            if (!empty($data['novelty_type_id'])) {
                $noveltyType = $this->EmployeeNovelties->NoveltyTypes->get($data['novelty_type_id']);
                if ($noveltyType->requires_boss_approval) {
                    $initialStatus = NoveltyConstants::STATUS_APROBACION;
                }
            }
            $data['pipeline_status'] = $initialStatus;

            // Normalize empty strings to null for optional fields
            if (isset($data['custom_name']) && $data['custom_name'] === '') {
                $data['custom_name'] = null;
            }

            // Handle massive novelties
            $massiveEmployeeIds = [];
            if (!empty($data['massive_employee_ids'])) {
                $massiveEmployeeIds = $data['massive_employee_ids'];
                unset($data['massive_employee_ids']);
                $data['employee_id'] = null;
            }

            $novelty = $this->EmployeeNovelties->patchEntity($novelty, $data);
            if ($this->EmployeeNovelties->save($novelty)) {
                $this->historyService->recordStatusChange(
                    (int)$novelty->id,
                    '',
                    (string)$novelty->pipeline_status,
                    $user->id,
                );

                // Save massive employees
                if (!empty($massiveEmployeeIds)) {
                    $massiveTable = TableRegistry::getTableLocator()->get('NoveltyMassiveEmployees');
                    foreach ($massiveEmployeeIds as $empId) {
                        $massiveEntry = $massiveTable->newEntity([
                            'novelty_id' => $novelty->id,
                            'employee_id' => (int)$empId,
                        ]);
                        $massiveTable->save($massiveEntry);
                    }
                }

                // Handle employee signature
                $signaturePath = null;

                $signatureFile = $this->request->getUploadedFile('signature_file');
                if ($signatureFile && $signatureFile->getError() === UPLOAD_ERR_OK) {
                    $signaturePath = $this->signatureService->saveFromUpload(
                        $novelty->id,
                        $signatureFile,
                        $user->id,
                        'employee',
                    );
                }

                if (!$signaturePath) {
                    $signatureBase64 = $this->request->getData('signature_base64');
                    if (!empty($signatureBase64)) {
                        $signaturePath = $this->signatureService->saveFromBase64(
                            $novelty->id,
                            $signatureBase64,
                            $user->id,
                            'employee',
                        );
                    }
                }

                if ($signaturePath) {
                    $novelty->employee_signature = $signaturePath;
                    $this->EmployeeNovelties->save($novelty);
                }

                // Generate approval token if type requires boss approval
                if ($noveltyType && $noveltyType->requires_boss_approval && !empty($novelty->approver_id)) {
                    $token = $this->tokenService->generateToken('employee_novelties', $novelty->id, $user->id);
                    $baseUrl = $this->request->scheme() . '://' . $this->request->host();
                    $approvalUrl = $baseUrl . '/approve/' . $token;

                    // Reload novelty with associations for email
                    $noveltyForEmail = $this->EmployeeNovelties->get($novelty->id, contain: ['Employees', 'NoveltyTypes']);

                    // Send notification email to approver
                    $approversTable = TableRegistry::getTableLocator()->get('Users');
                    $approver = $approversTable->get($novelty->approver_id);
                    if ($approver && !empty($approver->email)) {
                        try {
                            $this->notificationService->sendNoveltyApprovalEmail(
                                $approver,
                                $noveltyForEmail,
                                $approvalUrl,
                                (int)$user->id,
                            );
                        } catch (Exception $e) {
                            $this->Flash->warning(__(
                                'Novedad creada, pero el correo de aprobación falló: {0}. Puede reintentar desde la página de la novedad.',
                                $e->getMessage(),
                            ));
                        }
                    }
                }

                $this->Flash->success(__('La novedad ha sido registrada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo registrar la novedad. Intente de nuevo.'));
        }

        $employees = $this->EmployeeNovelties->Employees->find('list', [
            'keyField' => 'id',
            'valueField' => 'full_name',
        ])->all();

        $noveltyTypes = $this->_getNoveltyTypesGrouped();

        $preselectedEmployeeRaw = $this->request->getQuery('employee_id');
        $preselectedEmployee = $preselectedEmployeeRaw !== null ? (int)$preselectedEmployeeRaw : null;

        $approvers = TableRegistry::getTableLocator()->get('Approvers')
            ->find()
            ->contain(['Users'])
            ->where(['Approvers.active' => true])
            ->all();

        $approversList = [];
        foreach ($approvers as $approver) {
            if ($approver->user) {
                $approversList[$approver->user->id] = $approver->user->full_name;
            }
        }

        $vm = new EmployeeNoveltyAddViewModel(
            novelty: $novelty,
            employees: $employees,
            noveltyTypes: $noveltyTypes,
            approversList: $approversList,
            preselectedEmployee: $preselectedEmployee,
        );
        $this->set('viewModel', $vm);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function advance(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);

        if (!$this->_ensureExpectedStatus($novelty->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->Authentication->getIdentity()->getOriginalData();

        // El guardado de campos del paso (filtrado por canOperate), el avance y su
        // registro en el historial se hacen dentro de la transacción de
        // NoveltyPipelineService::saveAndAdvance().
        $result = $this->pipelineService->saveAndAdvance(
            $novelty,
            $this->request->getData(),
            (int)$user->role_id,
            (int)$user->id,
        );

        if ($result->success) {
            if ((bool)($result->data['advanced'] ?? false)) {
                $nextStatus = $result->data['nextStatus'] ?? null;
                $this->Flash->success(
                    'Novedad avanzada a: ' . (NoveltyConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus),
                );
            } else {
                $this->Flash->success('La novedad ha sido actualizada.');
                foreach ($result->data['advanceErrors'] ?? [] as $err) {
                    $this->Flash->warning($err);
                }
            }
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo avanzar la novedad.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]
    public function reject(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $originalStatus = $novelty->pipeline_status;

        $observations = $this->request->getData('observations');
        $result = $this->pipelineService->reject($novelty, (int)$user->role_id, (int)$user->id, $observations);

        if ($result->success) {
            $this->historyService->recordStatusChange(
                (int)$novelty->id,
                $originalStatus,
                NoveltyConstants::STATUS_RECHAZADA,
                $user->id,
            );
            $this->Flash->success('Novedad rechazada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'No se pudo rechazar la novedad.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * Resend approval token for a rejected novelty.
     *
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function resendApproval(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees', 'NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if ($novelty->pipeline_status !== NoveltyConstants::STATUS_APROBACION) {
            $this->Flash->error('Solo se puede reenviar aprobación para novedades en estado de aprobación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        // Allow updating approver from the form
        $newApproverId = $this->request->getData('approver_id');
        if (!empty($newApproverId) && (int)$newApproverId !== (int)$novelty->approver_id) {
            $previousApproverId = $novelty->approver_id;
            $novelty->approver_id = (int)$newApproverId;
            $this->EmployeeNovelties->save($novelty);
            $this->historyService->recordFieldChange(
                (int)$novelty->id,
                'approver_id',
                $previousApproverId !== null ? (string)$previousApproverId : null,
                (string)$newApproverId,
                (int)$user->id,
            );
            $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees', 'NoveltyTypes']);
        }

        if (empty($novelty->approver_id)) {
            $this->Flash->error('Debe asignar un aprobador antes de enviar.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        // Clear rejection
        $previousAreaApproval = $novelty->area_approval;
        $novelty->area_approval = null;
        $this->EmployeeNovelties->save($novelty);
        if ($previousAreaApproval !== null) {
            $this->historyService->recordFieldChange(
                (int)$novelty->id,
                'area_approval',
                (string)$previousAreaApproval,
                null,
                (int)$user->id,
            );
        }

        // Generate new token
        $token = $this->tokenService->generateToken('employee_novelties', $novelty->id, $user->id);
        $baseUrl = $this->request->scheme() . '://' . $this->request->host();
        $approvalUrl = $baseUrl . '/approve/' . $token;

        // Send notification email
        $approversTable = TableRegistry::getTableLocator()->get('Users');
        $approver = $approversTable->get($novelty->approver_id);
        if ($approver && !empty($approver->email)) {
            try {
                $this->notificationService->sendNoveltyApprovalEmail(
                    $approver,
                    $novelty,
                    $approvalUrl,
                    (int)$user->id,
                );
                $this->Flash->success('Enlace de aprobación reenviado al aprobador (válido por 48h).');
            } catch (Exception $e) {
                $this->Flash->error(__(
                    'No se pudo reenviar el correo de aprobación: {0}. Puede reintentar desde la página de la novedad.',
                    $e->getMessage(),
                ));
            }
        } else {
            $this->Flash->warning('El aprobador asignado no tiene correo electrónico configurado.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function assignLiquidation(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $liquidationNumber = $this->request->getData('liquidation_number');
        $existingDocId = $this->request->getData('existing_doc_id');

        if ($existingDocId) {
            // Assign to existing doc
            $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
            $doc = $liquidationDocsTable->get($existingDocId);
            $previousDocId = $novelty->liquidation_doc_id;
            $previousStatus = (string)$novelty->pipeline_status;
            $novelty->liquidation_doc_id = $doc->id;
            $novelty->pipeline_status = NoveltyConstants::STATUS_CONTABILIDAD;
            if ($this->EmployeeNovelties->save($novelty)) {
                $this->historyService->recordFieldChange(
                    (int)$novelty->id,
                    'liquidation_doc_id',
                    $previousDocId !== null ? (string)$previousDocId : null,
                    (string)$doc->id,
                    (int)$user->id,
                );
                if ($previousStatus !== NoveltyConstants::STATUS_CONTABILIDAD) {
                    $this->historyService->recordStatusChange(
                        (int)$novelty->id,
                        $previousStatus,
                        NoveltyConstants::STATUS_CONTABILIDAD,
                        (int)$user->id,
                    );
                }
                $this->Flash->success('Novedad asignada al documento de liquidación: ' . $doc->liquidation_number);
            } else {
                $this->Flash->error('No se pudo asignar la novedad.');
            }
        } elseif ($liquidationNumber) {
            $data = $this->request->getData();
            $result = $this->pipelineService->assignToLiquidationDoc($novelty, $liquidationNumber, $data, $user->id);

            if (is_array($result)) {
                $this->Flash->error(implode(' ', $result));
            } else {
                $this->Flash->success('Novedad asignada al documento de liquidación: ' . $result->liquidation_number);
            }
        } else {
            $this->Flash->error('Debe indicar un número de liquidación o seleccionar un documento existente.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function addObservation(?string $id = null): Response
    {
        return $this->_handleAddObservation(
            'NoveltyObservations',
            'novelty_id',
            $id,
            $this->Authentication->getIdentity()->getOriginalData(),
            fn() => $this->redirect(['action' => 'edit', $id]),
        );
    }

    /**
     * @return array
     */
    private function _getNoveltyTypesGrouped(): array
    {
        $types = $this->EmployeeNovelties->NoveltyTypes->find()
            ->contain(['ChildNoveltyTypes' => ['sort' => ['ChildNoveltyTypes.name' => 'ASC']]])
            ->where(['NoveltyTypes.parent_id IS' => null])
            ->orderBy(['NoveltyTypes.name' => 'ASC'])
            ->all();

        $grouped = [];
        foreach ($types as $type) {
            if (!empty($type->child_novelty_types)) {
                $children = [];
                foreach ($type->child_novelty_types as $child) {
                    $children[$child->id] = $child->name;
                }
                $grouped[$type->name] = $children;
            } else {
                $grouped[$type->id] = $type->name;
            }
        }

        return $grouped;
    }
}
