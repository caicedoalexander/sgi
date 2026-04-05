<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\LeaveDocumentService;
use Cake\Http\Response;

class LeaveDocumentTemplatesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private LeaveDocumentService $leaveDocumentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->leaveDocumentService = new LeaveDocumentService();
    }

    public function index()
    {
        $query = $this->LeaveDocumentTemplates->find()
            ->contain(['LeaveTemplateFields'])
            ->order(['LeaveDocumentTemplates.created' => 'DESC']);

        $templates = $this->paginate($query);

        $this->set(compact('templates'));
    }

    public function add()
    {
        $template = $this->LeaveDocumentTemplates->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data['created_by'] = $user->id;

            $data['orientation'] = $data['orientation'] ?? 'P';

            // Handle file upload
            $file = $this->request->getUploadedFile('template_file');
            if ($file && $file->getError() === UPLOAD_ERR_OK) {
                $result = $this->leaveDocumentService->uploadTemplate($file);

                if (isset($result['error'])) {
                    $this->Flash->error($result['error']);
                    $this->set(compact('template'));

                    return;
                }

                $data['file_path'] = $result['file_path'];
                $data['mime_type'] = $result['mime_type'];

                // Use detected dimensions; swap if orientation doesn't match
                $w = $result['page_width'];
                $h = $result['page_height'];
                $isLandscapeFile = $w > $h;
                $wantsLandscape = $data['orientation'] === 'L';

                if ($isLandscapeFile !== $wantsLandscape) {
                    $data['page_width'] = $h;
                    $data['page_height'] = $w;
                } else {
                    $data['page_width'] = $w;
                    $data['page_height'] = $h;
                }
            } else {
                $this->Flash->error('Debe seleccionar un archivo de plantilla.');
                $this->set(compact('template'));

                return;
            }

            unset($data['template_file']);

            $template = $this->LeaveDocumentTemplates->patchEntity($template, $data);
            if ($this->LeaveDocumentTemplates->save($template)) {
                $this->Flash->success('Plantilla creada. Ahora configure los campos.');

                return $this->redirect(['action' => 'edit', $template->id]);
            }
            $this->Flash->error('No se pudo guardar la plantilla.');
        }

        $this->set(compact('template'));
    }

    public function edit($id = null)
    {
        $template = $this->LeaveDocumentTemplates->get($id, contain: ['LeaveTemplateFields']);

        $availableFields = LeaveDocumentService::AVAILABLE_FIELDS;

        $this->set(compact('template', 'availableFields'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $template = $this->LeaveDocumentTemplates->get($id);

        $this->leaveDocumentService->deleteTemplateFile($template->file_path);

        if ($this->LeaveDocumentTemplates->delete($template)) {
            $this->Flash->success('Plantilla eliminada.');
        } else {
            $this->Flash->error('No se pudo eliminar la plantilla.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function saveFields($id = null): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $template = $this->LeaveDocumentTemplates->get($id);

        $fieldsData = json_decode((string)$this->request->getBody(), true);
        if (!is_array($fieldsData)) {
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode(['success' => false, 'error' => 'Datos inválidos.']));
        }

        // Delete existing fields
        $fieldsTable = $this->LeaveDocumentTemplates->LeaveTemplateFields;
        $fieldsTable->deleteAll(['leave_document_template_id' => $template->id]);

        // Create new fields
        $errors = [];
        foreach ($fieldsData as $i => $fieldData) {
            $fieldData['leave_document_template_id'] = $template->id;
            $fieldData['sort_order'] = $i;
            $field = $fieldsTable->newEntity($fieldData);
            if (!$fieldsTable->save($field)) {
                $errors[] = 'Error en campo: ' . ($fieldData['field_key'] ?? $i);
            }
        }

        if (!empty($errors)) {
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode(['success' => false, 'error' => implode(', ', $errors)]));
        }

        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['success' => true, 'count' => count($fieldsData)]));
    }

    public function preview($id = null): ?Response
    {
        $this->autoRender = false;

        $pdfContent = $this->leaveDocumentService->generatePreviewPdf((int)$id);

        return $this->response
            ->withType('application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="preview_plantilla.pdf"')
            ->withStringBody($pdfContent);
    }
}
