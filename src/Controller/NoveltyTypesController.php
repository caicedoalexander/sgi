<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Constants\ContractTypeConstants;
use Cake\ORM\TableRegistry;

class NoveltyTypesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $query = $this->NoveltyTypes->find()
            ->contain([
                'ParentNoveltyTypes',
                'ChildNoveltyTypes',
                'NoveltyTypeContractTemplates.LeaveDocumentTemplates',
            ])
            ->where(['NoveltyTypes.parent_id IS' => null])
            ->orderBy(['NoveltyTypes.name' => 'ASC']);

        $noveltyTypes = $this->paginate($query);
        $this->set(compact('noveltyTypes'));
    }

    #[Permission(action: 'add')]
    public function add()
    {
        $noveltyType = $this->NoveltyTypes->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data = $this->_cleanContractTemplates($data);
            $noveltyType = $this->NoveltyTypes->patchEntity($noveltyType, $data, [
                'associated' => ['NoveltyTypeContractTemplates'],
            ]);
            if ($this->NoveltyTypes->save($noveltyType)) {
                $this->Flash->success(__('El tipo de novedad ha sido guardado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar. Intente de nuevo.'));
        }

        $parentTypes = $this->NoveltyTypes->find('list')
            ->where(['parent_id IS' => null])
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $this->_setFormData();
        $this->set(compact('noveltyType', 'parentTypes'));
    }

    #[Permission(action: 'edit')]
    public function edit($id = null)
    {
        $noveltyType = $this->NoveltyTypes->get($id, contain: ['NoveltyTypeContractTemplates']);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $data = $this->_cleanContractTemplates($data);
            $noveltyType = $this->NoveltyTypes->patchEntity($noveltyType, $data, [
                'associated' => ['NoveltyTypeContractTemplates'],
            ]);
            if ($this->NoveltyTypes->save($noveltyType)) {
                $this->Flash->success(__('El tipo de novedad ha sido actualizado.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar. Intente de nuevo.'));
        }

        $parentTypes = $this->NoveltyTypes->find('list')
            ->where(['parent_id IS' => null, 'id !=' => $id])
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $this->_setFormData();
        $this->set(compact('noveltyType', 'parentTypes'));
    }

    #[Permission(action: 'view')]
    public function getFlags($id = null)
    {
        $this->request->allowMethod(['get']);
        $this->autoRender = false;

        $noveltyType = $this->NoveltyTypes->get($id);

        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode([
                'requires_boss_approval' => (bool)$noveltyType->requires_boss_approval,
                'requires_employee_signature_creation' => (bool)$noveltyType->requires_employee_signature_creation,
                'requires_employee_signature_review' => (bool)$noveltyType->requires_employee_signature_review,
                'show_start_date' => (bool)$noveltyType->show_start_date,
                'show_end_date' => (bool)$noveltyType->show_end_date,
                'show_permission_date' => (bool)$noveltyType->show_permission_date,
                'show_schedule_type' => (bool)$noveltyType->show_schedule_type,
                'uses_custom_name' => (bool)$noveltyType->uses_custom_name,
                'is_massive' => (bool)$noveltyType->is_massive,
            ]));
    }

    #[Permission(action: 'delete')]
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltyType = $this->NoveltyTypes->get($id);

        $count = $this->NoveltyTypes->EmployeeNovelties->find()
            ->where(['novelty_type_id' => $id])
            ->count();

        if ($count > 0) {
            $this->Flash->error(__('No se puede eliminar: hay {0} novedad(es) asociada(s).', $count));

            return $this->redirect(['action' => 'index']);
        }

        if ($this->NoveltyTypes->delete($noveltyType)) {
            $this->Flash->success(__('El tipo de novedad ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    private function _setFormData(): void
    {
        $documentTemplates = TableRegistry::getTableLocator()->get('LeaveDocumentTemplates')
            ->find('list', valueField: 'name')
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $temporaryOrganizations = TableRegistry::getTableLocator()->get('TemporaryOrganizations')
            ->find('list', valueField: 'name')
            ->orderBy(['name' => 'ASC'])
            ->toArray();

        $contractTypes = ContractTypeConstants::LABELS;

        $this->set(compact('documentTemplates', 'temporaryOrganizations', 'contractTypes'));
    }

    private function _cleanContractTemplates(array $data): array
    {
        if (empty($data['novelty_type_contract_templates'])) {
            $data['novelty_type_contract_templates'] = [];

            return $data;
        }

        $cleaned = [];
        foreach ($data['novelty_type_contract_templates'] as $row) {
            if (empty($row['contract_type']) || empty($row['leave_document_template_id'])) {
                continue;
            }
            if ($row['contract_type'] !== ContractTypeConstants::OBRA_LABOR) {
                $row['temporary_organization_id'] = null;
            }
            $cleaned[] = $row;
        }
        $data['novelty_type_contract_templates'] = $cleaned;

        return $data;
    }
}
