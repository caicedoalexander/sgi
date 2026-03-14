<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\LeaveDocumentService;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;

class EmployeeNoveltiesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;
    private NoveltyDocumentService $documentService;
    private NoveltyObservationService $observationService;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->pipelineService = new NoveltyPipelineService();
        $this->documentService = new NoveltyDocumentService();
        $this->observationService = new NoveltyObservationService();
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->order(['EmployeeNovelties.created' => 'DESC']);

        if ($roleName !== 'Administrador') {
            $subordinateIds = $this->_getSubordinateEmployeeIds($user);
            if (!empty($subordinateIds)) {
                $query->where(['EmployeeNovelties.employee_id IN' => $subordinateIds]);
            } else {
                $query->where(['1 = 0']);
            }
        }

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
            ->order(['name' => 'ASC'])
            ->toArray();

        $this->set(compact('novelties', 'statusFilter', 'typeFilter', 'noveltyTypes'));
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null|void
     */
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
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Mark observations as read
        $this->observationService->markAsRead($user->id, noveltyId: $novelty->id);

        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses($novelty->novelty_type);
        $nextStatus = $this->pipelineService->getNextStatus($novelty);
        $transitionErrors = $this->pipelineService->validateTransition($novelty, $novelty->pipeline_status);
        $canAdvance = !$novelty->isRejected() && !$novelty->isPaid() && !$novelty->isGrouped() && $nextStatus !== null;

        $documentsByStatus = $this->documentService->getDocumentsByStatus($novelty->id);

        // PDF template check
        $service = new LeaveDocumentService();
        $employee = $novelty->employee;
        $template = $employee ? $service->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        ) : null;
        $hasActiveTemplate = $template && $template->is_active;

        // Existing liquidation docs for assignment dropdown
        $liquidationDocs = [];
        if ($novelty->pipeline_status === NoveltyConstants::STATUS_CONTABILIDAD && !$novelty->isGrouped()) {
            $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
            $liquidationDocs = $liquidationDocsTable->find('list', [
                'keyField' => 'id',
                'valueField' => 'liquidation_number',
            ])->where(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->toArray();
        }

        $this->set(compact(
            'novelty',
            'effectiveStatuses',
            'nextStatus',
            'transitionErrors',
            'canAdvance',
            'documentsByStatus',
            'hasActiveTemplate',
            'liquidationDocs',
        ));
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function exportPdf(?string $id = null): ?Response
    {
        $this->autoRender = false;

        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
        ]);

        $service = new LeaveDocumentService();
        $employee = $novelty->employee;
        $template = $service->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null,
        );

        if (!$template || !$template->is_active) {
            $this->Flash->error('No hay plantilla de documento configurada para el tipo de contrato de este empleado.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $pdfContent = $service->generatePdf((int)$id, (int)$template->id);

        return $this->response
            ->withType('application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="novedad_' . $id . '.pdf"')
            ->withStringBody($pdfContent);
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    public function add()
    {
        $novelty = $this->EmployeeNovelties->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = NoveltyConstants::STATUS_REGISTRO;
            $data['filing_date'] = Date::now()->format('Y-m-d');

            // Handle massive novelties
            $massiveEmployeeIds = [];
            if (!empty($data['massive_employee_ids'])) {
                $massiveEmployeeIds = $data['massive_employee_ids'];
                unset($data['massive_employee_ids']);
                $data['employee_id'] = null;
            }

            $novelty = $this->EmployeeNovelties->patchEntity($novelty, $data);
            if ($this->EmployeeNovelties->save($novelty)) {
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
                $signatureService = new NoveltySignatureService();
                $signaturePath = null;

                $signatureFile = $this->request->getUploadedFile('signature_file');
                if ($signatureFile && $signatureFile->getError() === UPLOAD_ERR_OK) {
                    $signaturePath = $signatureService->saveFromUpload(
                        $novelty->id,
                        $signatureFile,
                        $user->id,
                        'employee',
                    );
                }

                if (!$signaturePath) {
                    $signatureBase64 = $this->request->getData('signature_base64');
                    if (!empty($signatureBase64)) {
                        $signaturePath = $signatureService->saveFromBase64(
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

        $preselectedEmployee = $this->request->getQuery('employee_id');

        $this->set(compact('novelty', 'employees', 'noveltyTypes', 'preselectedEmployee'));
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function advance(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Save editable fields for current stage before advancing
        $data = $this->request->getData();
        if (!empty($data)) {
            $novelty = $this->EmployeeNovelties->patchEntity($novelty, $data);
            $this->EmployeeNovelties->save($novelty);
        }

        $result = $this->pipelineService->advance($novelty, $user->id);

        if ($result['success']) {
            $this->Flash->success('Novedad avanzada a: ' . NoveltyConstants::STATUS_LABELS[$result['nextStatus']]);
        } else {
            $this->Flash->error($result['error']);
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function reject(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $observations = $this->request->getData('observations');
        $result = $this->pipelineService->reject($novelty, $user->id, $observations);

        if ($result['success']) {
            $this->Flash->success('Novedad rechazada.');
        } else {
            $this->Flash->error($result['error']);
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
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
            $novelty->liquidation_doc_id = $doc->id;
            $novelty->pipeline_status = NoveltyConstants::STATUS_CONTABILIDAD;
            if ($this->EmployeeNovelties->save($novelty)) {
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

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function addObservation(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $message = $this->request->getData('message');

        $result = $this->observationService->addToNovelty($id, $user->id, $message);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Observación agregada.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function uploadDocument(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('document');

        if (!$file) {
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->documentService->uploadForNovelty($novelty->id, $novelty->pipeline_status, $file, $user->id);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $noveltyId Novelty ID.
     * @param string|null $documentId Document ID.
     * @return \Cake\Http\Response|null
     */
    public function deleteDocument(?string $noveltyId = null, ?string $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $novelty = $this->EmployeeNovelties->get($noveltyId);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $novelty->pipeline_status)) {
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['action' => 'view', $noveltyId]);
        }

        if ($this->documentService->deleteDocument($documentId)) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $noveltyId]);
    }

    /**
     * @return array
     */
    private function _getNoveltyTypesGrouped(): array
    {
        $types = $this->EmployeeNovelties->NoveltyTypes->find()
            ->contain(['ChildNoveltyTypes' => ['sort' => ['ChildNoveltyTypes.name' => 'ASC']]])
            ->where(['NoveltyTypes.parent_id IS' => null])
            ->order(['NoveltyTypes.name' => 'ASC'])
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

    /**
     * @param object $user Current user.
     * @return array
     */
    private function _getSubordinateEmployeeIds(object $user): array
    {
        $employeesTable = TableRegistry::getTableLocator()->get('Employees');

        $userEmployee = $employeesTable->find()
            ->where(['email' => $user->email, 'active' => true])
            ->first();

        if (!$userEmployee || !$userEmployee->position_id) {
            return [];
        }

        $subordinates = $employeesTable->find()
            ->where(['supervisor_position_id' => $userEmployee->position_id])
            ->select(['id'])
            ->all();

        return array_map(fn($e) => $e->id, $subordinates->toArray());
    }
}
