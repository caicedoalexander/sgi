# Leave Type Contract Templates - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow each LeaveType to have different PDF document templates based on employee contract type (FIJO, INDEFINIDO, OBRA O LABOR DETERMINADA) and, for OBRA O LABOR DETERMINADA, by temporary organization.

**Architecture:** New pivot table `leave_type_contract_templates` links LeaveType + contract_type + optional TemporaryOrganization to a LeaveDocumentTemplate. The existing direct FK `leave_types.leave_document_template_id` is removed. Contract type values are renamed to uppercase and "Temporal" becomes "OBRA O LABOR DETERMINADA".

**Tech Stack:** CakePHP 5.3, MariaDB, Bootstrap 5, vanilla JS for inline table

**Design doc:** `docs/plans/2026-03-06-leave-type-contract-templates-design.md`

---

### Task 1: Create ContractTypeConstants

**Files:**
- Create: `src/Constants/ContractTypeConstants.php`

**Step 1: Create the constants file**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class ContractTypeConstants
{
    public const FIJO = 'FIJO';
    public const INDEFINIDO = 'INDEFINIDO';
    public const OBRA_LABOR = 'OBRA O LABOR DETERMINADA';

    public const ALL = [self::FIJO, self::INDEFINIDO, self::OBRA_LABOR];

    public const LABELS = [
        self::FIJO => 'FIJO',
        self::INDEFINIDO => 'INDEFINIDO',
        self::OBRA_LABOR => 'OBRA O LABOR DETERMINADA',
    ];
}
```

**Step 2: Commit**

```bash
git add src/Constants/ContractTypeConstants.php
git commit -m "feat: add ContractTypeConstants with FIJO, INDEFINIDO, OBRA O LABOR DETERMINADA"
```

---

### Task 2: Migration - Update contract_type values in employees

**Files:**
- Create: `config/Migrations/20260306100001_UpdateContractTypeValues.php`

**Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class UpdateContractTypeValues extends BaseMigration
{
    public function up(): void
    {
        $this->execute("UPDATE employees SET contract_type = 'FIJO' WHERE contract_type = 'Fijo'");
        $this->execute("UPDATE employees SET contract_type = 'INDEFINIDO' WHERE contract_type = 'Indefinido'");
        $this->execute("UPDATE employees SET contract_type = 'OBRA O LABOR DETERMINADA' WHERE contract_type = 'Temporal'");

        // Widen column to fit longer value
        $this->execute("ALTER TABLE employees MODIFY contract_type VARCHAR(50) DEFAULT NULL");
    }

    public function down(): void
    {
        $this->execute("UPDATE employees SET contract_type = 'Fijo' WHERE contract_type = 'FIJO'");
        $this->execute("UPDATE employees SET contract_type = 'Indefinido' WHERE contract_type = 'INDEFINIDO'");
        $this->execute("UPDATE employees SET contract_type = 'Temporal' WHERE contract_type = 'OBRA O LABOR DETERMINADA'");

        $this->execute("ALTER TABLE employees MODIFY contract_type VARCHAR(20) DEFAULT NULL");
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260306100001_UpdateContractTypeValues.php
git commit -m "feat: migrate contract_type values to uppercase and rename Temporal to OBRA O LABOR DETERMINADA"
```

---

### Task 3: Migration - Create leave_type_contract_templates table

**Files:**
- Create: `config/Migrations/20260306100002_CreateLeaveTypeContractTemplates.php`

**Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLeaveTypeContractTemplates extends BaseMigration
{
    public function up(): void
    {
        $this->table('leave_type_contract_templates')
            ->addColumn('leave_type_id', 'integer', ['signed' => true, 'null' => false])
            ->addColumn('contract_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('temporary_organization_id', 'integer', ['signed' => true, 'null' => true, 'default' => null])
            ->addColumn('leave_document_template_id', 'integer', ['signed' => true, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['leave_type_id', 'contract_type', 'temporary_organization_id'], ['unique' => true, 'name' => 'uq_ltype_contract_org'])
            ->addForeignKey('leave_type_id', 'leave_types', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('temporary_organization_id', 'temporary_organizations', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('leave_type_contract_templates')->drop()->save();
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260306100002_CreateLeaveTypeContractTemplates.php
git commit -m "feat: create leave_type_contract_templates table"
```

---

### Task 4: Migration - Remove leave_document_template_id from leave_types

**Files:**
- Create: `config/Migrations/20260306100003_RemoveTemplateFromLeaveTypes.php`

**Step 1: Create the migration**

NOTE: Before dropping the column, migrate existing data into the new table. Since we don't know which contract_type the old template was for, we skip auto-migration — the user will reassign manually via the new UI.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RemoveTemplateFromLeaveTypes extends BaseMigration
{
    public function up(): void
    {
        $this->table('leave_types')
            ->dropForeignKey('leave_document_template_id')
            ->save();

        $this->table('leave_types')
            ->removeColumn('leave_document_template_id')
            ->update();
    }

    public function down(): void
    {
        $this->table('leave_types')
            ->addColumn('leave_document_template_id', 'integer', [
                'signed' => true,
                'null' => true,
                'default' => null,
                'after' => 'paid',
            ])
            ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260306100003_RemoveTemplateFromLeaveTypes.php
git commit -m "feat: remove leave_document_template_id from leave_types table"
```

---

### Task 5: Create LeaveTypeContractTemplate model (Entity + Table)

**Files:**
- Create: `src/Model/Entity/LeaveTypeContractTemplate.php`
- Create: `src/Model/Table/LeaveTypeContractTemplatesTable.php`

**Step 1: Create Entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LeaveTypeContractTemplate extends Entity
{
    protected array $_accessible = [
        'leave_type_id' => true,
        'contract_type' => true,
        'temporary_organization_id' => true,
        'leave_document_template_id' => true,
    ];
}
```

**Step 2: Create Table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\ContractTypeConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LeaveTypeContractTemplatesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('leave_type_contract_templates');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('LeaveTypes', [
            'foreignKey' => 'leave_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('TemporaryOrganizations', [
            'foreignKey' => 'temporary_organization_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('LeaveDocumentTemplates', [
            'foreignKey' => 'leave_document_template_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('leave_type_id')
            ->requirePresence('leave_type_id', 'create')
            ->notEmptyString('leave_type_id');

        $validator
            ->scalar('contract_type')
            ->inList('contract_type', ContractTypeConstants::ALL, 'Tipo de contrato inválido.')
            ->requirePresence('contract_type', 'create')
            ->notEmptyString('contract_type');

        $validator
            ->integer('leave_document_template_id')
            ->requirePresence('leave_document_template_id', 'create')
            ->notEmptyString('leave_document_template_id');

        $validator
            ->integer('temporary_organization_id')
            ->allowEmptyString('temporary_organization_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('leave_type_id', 'LeaveTypes'), ['errorField' => 'leave_type_id']);
        $rules->add($rules->existsIn('leave_document_template_id', 'LeaveDocumentTemplates'), ['errorField' => 'leave_document_template_id']);
        $rules->add($rules->existsIn('temporary_organization_id', 'TemporaryOrganizations'), [
            'errorField' => 'temporary_organization_id',
            'allowNullableNulls' => true,
        ]);

        // temporary_organization_id must be null when contract_type is not OBRA O LABOR DETERMINADA
        $rules->add(function ($entity) {
            if ($entity->contract_type !== ContractTypeConstants::OBRA_LABOR && !empty($entity->temporary_organization_id)) {
                return false;
            }
            return true;
        }, 'orgOnlyForObraLabor', [
            'errorField' => 'temporary_organization_id',
            'message' => 'La organización temporal solo aplica para OBRA O LABOR DETERMINADA.',
        ]);

        return $rules;
    }
}
```

**Step 3: Commit**

```bash
git add src/Model/Entity/LeaveTypeContractTemplate.php src/Model/Table/LeaveTypeContractTemplatesTable.php
git commit -m "feat: add LeaveTypeContractTemplate entity and table"
```

---

### Task 6: Update LeaveType model (remove old association, add new)

**Files:**
- Modify: `src/Model/Table/LeaveTypesTable.php`
- Modify: `src/Model/Entity/LeaveType.php`

**Step 1: Update LeaveTypesTable**

In `LeaveTypesTable::initialize()`:
- Remove the `belongsTo('LeaveDocumentTemplates')` block (lines 22-25)
- Add `hasMany('LeaveTypeContractTemplates')`:

```php
$this->hasMany('LeaveTypeContractTemplates', [
    'foreignKey' => 'leave_type_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

In `buildRules()`:
- Remove the `existsIn('leave_document_template_id', 'LeaveDocumentTemplates')` rule (lines 30-33)

**Step 2: Update LeaveType entity**

Remove `'leave_document_template_id' => true` from `$_accessible`. Add `'leave_type_contract_templates' => true`.

Final `$_accessible`:
```php
protected array $_accessible = [
    'code' => true,
    'name' => true,
    'paid' => true,
    'leave_type_contract_templates' => true,
];
```

**Step 3: Commit**

```bash
git add src/Model/Table/LeaveTypesTable.php src/Model/Entity/LeaveType.php
git commit -m "feat: update LeaveType model to use hasMany LeaveTypeContractTemplates"
```

---

### Task 7: Update EmployeesTable to use ContractTypeConstants

**Files:**
- Modify: `src/Model/Table/EmployeesTable.php` (lines 119-122 and 146-155)

**Step 1: Update validation (line 121)**

Change:
```php
->inList('contract_type', ['Fijo', 'Indefinido', 'Temporal'], 'Tipo de contrato inválido.')
```
To:
```php
->inList('contract_type', ContractTypeConstants::ALL, 'Tipo de contrato inválido.')
```

Add import at top:
```php
use App\Constants\ContractTypeConstants;
```

**Step 2: Update buildRules (line 147)**

Change:
```php
if ($entity->contract_type === 'Temporal' && empty($entity->temporary_organization_id)) {
```
To:
```php
if ($entity->contract_type === ContractTypeConstants::OBRA_LABOR && empty($entity->temporary_organization_id)) {
```

Update the error message (line 154):
```php
'message' => 'Debe seleccionar una organización temporal cuando el tipo de contrato es OBRA O LABOR DETERMINADA.',
```

**Step 3: Commit**

```bash
git add src/Model/Table/EmployeesTable.php
git commit -m "feat: use ContractTypeConstants in EmployeesTable validation"
```

---

### Task 8: Update Employee templates (add, edit, view)

**Files:**
- Modify: `templates/Employees/add.php` (lines 119, 138)
- Modify: `templates/Employees/edit.php` (lines 119, 124, 138)
- Modify: `templates/Employees/view.php` (line referencing contract_type)

**Step 1: Update add.php**

Line 119 — change options:
```php
'options' => ['FIJO' => 'FIJO', 'INDEFINIDO' => 'INDEFINIDO', 'OBRA O LABOR DETERMINADA' => 'OBRA O LABOR DETERMINADA'],
```

Line 138 — change JS condition:
```js
orgWrapper.style.display = contractType.value === 'OBRA O LABOR DETERMINADA' ? '' : 'none';
```

**Step 2: Update edit.php**

Line 119 — same options change as add.php.

Line 124 — change style condition:
```php
style="<?= ($employee->contract_type ?? '') !== 'OBRA O LABOR DETERMINADA' ? 'display:none' : '' ?>"
```

Line 138 — same JS condition change.

**Step 3: Update view.php**

No code change needed — it already uses `h($employee->contract_type)` which will display the new value.

**Step 4: Commit**

```bash
git add templates/Employees/add.php templates/Employees/edit.php
git commit -m "feat: update Employee forms to use new contract type values"
```

---

### Task 9: Update LeaveTypesController to handle inline table

**Files:**
- Modify: `src/Controller/LeaveTypesController.php`

**Step 1: Rewrite the controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\ContractTypeConstants;
use App\Controller\Trait\ExcelCatalogTrait;
use Cake\ORM\TableRegistry;

class LeaveTypesController extends AppController
{
    use ExcelCatalogTrait;

    public function index()
    {
        $query = $this->LeaveTypes->find()
            ->contain(['LeaveTypeContractTemplates.LeaveDocumentTemplates']);
        $leaveTypes = $this->paginate($query);
        $this->set(compact('leaveTypes'));
    }

    public function add()
    {
        $leaveType = $this->LeaveTypes->newEmptyEntity();
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data = $this->_cleanContractTemplates($data);
            $leaveType = $this->LeaveTypes->patchEntity($leaveType, $data, [
                'associated' => ['LeaveTypeContractTemplates'],
            ]);
            if ($this->LeaveTypes->save($leaveType)) {
                $this->Flash->success(__('El tipo de permiso ha sido guardado.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar. Intente de nuevo.'));
        }
        $this->_setFormData();
        $this->set(compact('leaveType'));
    }

    public function edit($id = null)
    {
        $leaveType = $this->LeaveTypes->get($id, contain: ['LeaveTypeContractTemplates']);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $data = $this->_cleanContractTemplates($data);
            $leaveType = $this->LeaveTypes->patchEntity($leaveType, $data, [
                'associated' => ['LeaveTypeContractTemplates'],
            ]);
            if ($this->LeaveTypes->save($leaveType)) {
                $this->Flash->success(__('El tipo de permiso ha sido actualizado.'));
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar. Intente de nuevo.'));
        }
        $this->_setFormData();
        $this->set(compact('leaveType'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $leaveType = $this->LeaveTypes->get($id);
        if ($this->LeaveTypes->delete($leaveType)) {
            $this->Flash->success(__('El tipo de permiso ha sido eliminado.'));
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
            ->order(['name' => 'ASC'])
            ->toArray();

        $temporaryOrganizations = TableRegistry::getTableLocator()->get('TemporaryOrganizations')
            ->find('list', valueField: 'name')
            ->order(['name' => 'ASC'])
            ->toArray();

        $contractTypes = ContractTypeConstants::LABELS;

        $this->set(compact('documentTemplates', 'temporaryOrganizations', 'contractTypes'));
    }

    /**
     * Clean contract templates data: remove empty rows, null out temporary_organization_id
     * when contract_type is not OBRA O LABOR DETERMINADA.
     */
    private function _cleanContractTemplates(array $data): array
    {
        if (empty($data['leave_type_contract_templates'])) {
            $data['leave_type_contract_templates'] = [];
            return $data;
        }

        $cleaned = [];
        foreach ($data['leave_type_contract_templates'] as $row) {
            if (empty($row['contract_type']) || empty($row['leave_document_template_id'])) {
                continue;
            }
            if ($row['contract_type'] !== ContractTypeConstants::OBRA_LABOR) {
                $row['temporary_organization_id'] = null;
            }
            $cleaned[] = $row;
        }
        $data['leave_type_contract_templates'] = $cleaned;

        return $data;
    }
}
```

**Step 2: Commit**

```bash
git add src/Controller/LeaveTypesController.php
git commit -m "feat: update LeaveTypesController to manage contract template assignments"
```

---

### Task 10: Update LeaveTypes add.php template with inline table

**Files:**
- Modify: `templates/LeaveTypes/add.php`

**Step 1: Rewrite add.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LeaveType $leaveType
 * @var array $documentTemplates
 * @var array $temporaryOrganizations
 * @var array $contractTypes
 */
$this->assign('title', 'Nuevo Tipo de Permiso');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nuevo Tipo de Permiso</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($leaveType) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control', 'placeholder' => 'VAC']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Vacaciones']) ?>
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <?= $this->Form->checkbox('paid', ['class' => 'form-check-input', 'id' => 'paid']) ?>
                    <label class="form-check-label" for="paid">Remunerado por defecto</label>
                    <div class="form-text">Si se activa, las solicitudes de permiso de este tipo se marcarán como remuneradas automáticamente.</div>
                </div>
            </div>
        </div>

        <!-- Contract Template Assignments -->
        <div class="mt-4 pt-3" style="border-top:1px solid var(--border-color);">
            <label class="sgi-section-label">Asignación de plantillas por tipo de contrato</label>
            <table class="table table-sm align-middle mb-2" id="contract-templates-table">
                <thead>
                    <tr>
                        <th>Tipo de Contrato</th>
                        <th>Organización Temporal</th>
                        <th>Plantilla</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="contract-templates-body">
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm" id="add-contract-template-row">
                <i class="bi bi-plus-lg me-1"></i>Agregar asignación
            </button>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php $this->Html->scriptStart(['block' => true]); ?>
(function() {
    var contractTypes = <?= json_encode($contractTypes) ?>;
    var documentTemplates = <?= json_encode($documentTemplates) ?>;
    var temporaryOrgs = <?= json_encode($temporaryOrganizations) ?>;
    var rowIndex = 0;
    var tbody = document.getElementById('contract-templates-body');

    function buildOptions(obj, emptyLabel) {
        var html = '<option value="">' + emptyLabel + '</option>';
        for (var k in obj) {
            html += '<option value="' + k + '">' + obj[k] + '</option>';
        }
        return html;
    }

    function addRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var prefix = 'leave_type_contract_templates[' + rowIndex + ']';

        if (data.id) {
            tr.innerHTML += '<input type="hidden" name="' + prefix + '[id]" value="' + data.id + '">';
        }

        var contractSelect = '<select name="' + prefix + '[contract_type]" class="form-select form-select-sm ct-contract-type">'
            + buildOptions(contractTypes, '-- Seleccione --') + '</select>';

        var orgSelect = '<select name="' + prefix + '[temporary_organization_id]" class="form-select form-select-sm ct-org-select" disabled>'
            + buildOptions(temporaryOrgs, '-- Seleccione --') + '</select>';

        var tplSelect = '<select name="' + prefix + '[leave_document_template_id]" class="form-select form-select-sm">'
            + buildOptions(documentTemplates, '-- Seleccione --') + '</select>';

        tr.innerHTML += '<td>' + contractSelect + '</td>';
        tr.innerHTML += '<td>' + orgSelect + '</td>';
        tr.innerHTML += '<td>' + tplSelect + '</td>';
        tr.innerHTML += '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row"><i class="bi bi-trash"></i></button></td>';

        tbody.appendChild(tr);

        // Set values if editing
        if (data.contract_type) {
            tr.querySelector('[name$="[contract_type]"]').value = data.contract_type;
        }
        if (data.leave_document_template_id) {
            tr.querySelector('[name$="[leave_document_template_id]"]').value = data.leave_document_template_id;
        }

        var orgSelectEl = tr.querySelector('.ct-org-select');
        var ctSelectEl = tr.querySelector('.ct-contract-type');

        function toggleOrg() {
            var isObraLabor = ctSelectEl.value === '<?= \App\Constants\ContractTypeConstants::OBRA_LABOR ?>';
            orgSelectEl.disabled = !isObraLabor;
            if (!isObraLabor) orgSelectEl.value = '';
        }

        ctSelectEl.addEventListener('change', toggleOrg);
        toggleOrg();

        if (data.temporary_organization_id) {
            orgSelectEl.disabled = false;
            orgSelectEl.value = data.temporary_organization_id;
        }

        tr.querySelector('.ct-remove-row').addEventListener('click', function() {
            tr.remove();
        });

        rowIndex++;
    }

    document.getElementById('add-contract-template-row').addEventListener('click', function() {
        addRow();
    });
})();
<?php $this->Html->scriptEnd(); ?>
```

**Step 2: Commit**

```bash
git add templates/LeaveTypes/add.php
git commit -m "feat: add inline contract template table to LeaveTypes add form"
```

---

### Task 11: Update LeaveTypes edit.php template with inline table

**Files:**
- Modify: `templates/LeaveTypes/edit.php`

**Step 1: Rewrite edit.php**

Same structure as add.php but with existing data pre-loaded. The key difference is the JS at the bottom loads existing rows:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LeaveType $leaveType
 * @var array $documentTemplates
 * @var array $temporaryOrganizations
 * @var array $contractTypes
 */
$this->assign('title', 'Editar Tipo de Permiso');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Tipo de Permiso</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($leaveType) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <?= $this->Form->checkbox('paid', [
                        'class' => 'form-check-input',
                        'id' => 'paid',
                        'checked' => !empty($leaveType->paid),
                    ]) ?>
                    <label class="form-check-label" for="paid">Remunerado por defecto</label>
                    <div class="form-text">Si se activa, las solicitudes de permiso de este tipo se marcarán como remuneradas automáticamente.</div>
                </div>
            </div>
        </div>

        <!-- Contract Template Assignments -->
        <div class="mt-4 pt-3" style="border-top:1px solid var(--border-color);">
            <label class="sgi-section-label">Asignación de plantillas por tipo de contrato</label>
            <table class="table table-sm align-middle mb-2" id="contract-templates-table">
                <thead>
                    <tr>
                        <th>Tipo de Contrato</th>
                        <th>Organización Temporal</th>
                        <th>Plantilla</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="contract-templates-body">
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm" id="add-contract-template-row">
                <i class="bi bi-plus-lg me-1"></i>Agregar asignación
            </button>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

<?php
$existingRows = [];
if (!empty($leaveType->leave_type_contract_templates)) {
    foreach ($leaveType->leave_type_contract_templates as $ct) {
        $existingRows[] = [
            'id' => $ct->id,
            'contract_type' => $ct->contract_type,
            'temporary_organization_id' => $ct->temporary_organization_id,
            'leave_document_template_id' => $ct->leave_document_template_id,
        ];
    }
}
?>

<?php $this->Html->scriptStart(['block' => true]); ?>
(function() {
    var contractTypes = <?= json_encode($contractTypes) ?>;
    var documentTemplates = <?= json_encode($documentTemplates) ?>;
    var temporaryOrgs = <?= json_encode($temporaryOrganizations) ?>;
    var existingRows = <?= json_encode($existingRows) ?>;
    var rowIndex = 0;
    var tbody = document.getElementById('contract-templates-body');

    function buildOptions(obj, emptyLabel) {
        var html = '<option value="">' + emptyLabel + '</option>';
        for (var k in obj) {
            html += '<option value="' + k + '">' + obj[k] + '</option>';
        }
        return html;
    }

    function addRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var prefix = 'leave_type_contract_templates[' + rowIndex + ']';

        if (data.id) {
            tr.innerHTML += '<input type="hidden" name="' + prefix + '[id]" value="' + data.id + '">';
        }

        var contractSelect = '<select name="' + prefix + '[contract_type]" class="form-select form-select-sm ct-contract-type">'
            + buildOptions(contractTypes, '-- Seleccione --') + '</select>';

        var orgSelect = '<select name="' + prefix + '[temporary_organization_id]" class="form-select form-select-sm ct-org-select" disabled>'
            + buildOptions(temporaryOrgs, '-- Seleccione --') + '</select>';

        var tplSelect = '<select name="' + prefix + '[leave_document_template_id]" class="form-select form-select-sm">'
            + buildOptions(documentTemplates, '-- Seleccione --') + '</select>';

        tr.innerHTML += '<td>' + contractSelect + '</td>';
        tr.innerHTML += '<td>' + orgSelect + '</td>';
        tr.innerHTML += '<td>' + tplSelect + '</td>';
        tr.innerHTML += '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row"><i class="bi bi-trash"></i></button></td>';

        tbody.appendChild(tr);

        if (data.contract_type) {
            tr.querySelector('[name$="[contract_type]"]').value = data.contract_type;
        }
        if (data.leave_document_template_id) {
            tr.querySelector('[name$="[leave_document_template_id]"]').value = data.leave_document_template_id;
        }

        var orgSelectEl = tr.querySelector('.ct-org-select');
        var ctSelectEl = tr.querySelector('.ct-contract-type');

        function toggleOrg() {
            var isObraLabor = ctSelectEl.value === '<?= \App\Constants\ContractTypeConstants::OBRA_LABOR ?>';
            orgSelectEl.disabled = !isObraLabor;
            if (!isObraLabor) orgSelectEl.value = '';
        }

        ctSelectEl.addEventListener('change', toggleOrg);
        toggleOrg();

        if (data.temporary_organization_id) {
            orgSelectEl.disabled = false;
            orgSelectEl.value = data.temporary_organization_id;
        }

        tr.querySelector('.ct-remove-row').addEventListener('click', function() {
            tr.remove();
        });

        rowIndex++;
    }

    // Load existing rows
    existingRows.forEach(function(row) {
        addRow(row);
    });

    document.getElementById('add-contract-template-row').addEventListener('click', function() {
        addRow();
    });
})();
<?php $this->Html->scriptEnd(); ?>
```

**Step 2: Commit**

```bash
git add templates/LeaveTypes/edit.php
git commit -m "feat: add inline contract template table to LeaveTypes edit form"
```

---

### Task 12: Update LeaveTypes index.php to show contract template info

**Files:**
- Modify: `templates/LeaveTypes/index.php`

**Step 1: Update the template column (lines 47-53)**

Replace the single template cell with a count of assigned templates:

```php
<td>
    <?php if (!empty($leaveType->leave_type_contract_templates)): ?>
        <span class="text-muted">
            <i class="bi bi-file-earmark-pdf me-1"></i><?= count($leaveType->leave_type_contract_templates) ?> asignación(es)
        </span>
    <?php else: ?>
        <span class="text-muted">—</span>
    <?php endif; ?>
</td>
```

**Step 2: Commit**

```bash
git add templates/LeaveTypes/index.php
git commit -m "feat: show contract template assignment count in LeaveTypes index"
```

---

### Task 13: Update LeaveDocumentService with resolveTemplate()

**Files:**
- Modify: `src/Service/LeaveDocumentService.php`

**Step 1: Add resolveTemplate method**

Add this method to `LeaveDocumentService`:

```php
public function resolveTemplate(int $leaveTypeId, ?string $contractType, ?int $temporaryOrgId): ?object
{
    $table = TableRegistry::getTableLocator()->get('LeaveTypeContractTemplates');

    // Try exact match (with org for OBRA O LABOR DETERMINADA)
    if ($contractType === \App\Constants\ContractTypeConstants::OBRA_LABOR && $temporaryOrgId) {
        $match = $table->find()
            ->contain(['LeaveDocumentTemplates'])
            ->where([
                'LeaveTypeContractTemplates.leave_type_id' => $leaveTypeId,
                'LeaveTypeContractTemplates.contract_type' => $contractType,
                'LeaveTypeContractTemplates.temporary_organization_id' => $temporaryOrgId,
            ])
            ->first();

        if ($match && $match->leave_document_template) {
            return $match->leave_document_template;
        }
    }

    // Try match by contract type only (org IS NULL)
    if ($contractType) {
        $match = $table->find()
            ->contain(['LeaveDocumentTemplates'])
            ->where([
                'LeaveTypeContractTemplates.leave_type_id' => $leaveTypeId,
                'LeaveTypeContractTemplates.contract_type' => $contractType,
                'LeaveTypeContractTemplates.temporary_organization_id IS' => null,
            ])
            ->first();

        if ($match && $match->leave_document_template) {
            return $match->leave_document_template;
        }
    }

    return null;
}
```

**Step 2: Update generatePdf to accept template directly**

Change `generatePdf` signature from `(int $leaveId, int $templateId)` — keep it as-is since the controller will resolve and pass the templateId. No change needed here.

**Step 3: Commit**

```bash
git add src/Service/LeaveDocumentService.php
git commit -m "feat: add resolveTemplate method to LeaveDocumentService"
```

---

### Task 14: Update EmployeeLeavesController to use resolveTemplate

**Files:**
- Modify: `src/Controller/EmployeeLeavesController.php`

**Step 1: Update view() method (lines 47-63)**

Replace the template detection logic:

```php
public function view($id = null)
{
    $employeeLeave = $this->EmployeeLeaves->get($id, contain: [
        'Employees',
        'LeaveTypes',
        'ApprovedByUsers',
        'RequestedByUsers',
    ]);

    $user = $this->Authentication->getIdentity()->getOriginalData();
    $canApprove = $this->_canApproveLeave($user, $employeeLeave);

    $service = new LeaveDocumentService();
    $employee = $employeeLeave->employee;
    $template = $service->resolveTemplate(
        (int)$employeeLeave->leave_type_id,
        $employee->contract_type ?? null,
        $employee->temporary_organization_id ?? null
    );
    $hasActiveTemplate = $template && $template->is_active;

    $this->set(compact('employeeLeave', 'canApprove', 'hasActiveTemplate'));
}
```

**Step 2: Update exportPdf() method (lines 66-89)**

```php
public function exportPdf($id = null): ?Response
{
    $this->autoRender = false;

    $leave = $this->EmployeeLeaves->get($id, contain: [
        'Employees',
        'LeaveTypes',
    ]);

    $service = new LeaveDocumentService();
    $employee = $leave->employee;
    $template = $service->resolveTemplate(
        (int)$leave->leave_type_id,
        $employee->contract_type ?? null,
        $employee->temporary_organization_id ?? null
    );

    if (!$template || !$template->is_active) {
        $this->Flash->error('No hay plantilla de documento configurada para el tipo de contrato de este empleado.');
        return $this->redirect(['action' => 'view', $id]);
    }

    $pdfContent = $service->generatePdf((int)$id, (int)$template->id);

    return $this->response
        ->withType('application/pdf')
        ->withHeader('Content-Disposition', 'inline; filename="permiso_' . $id . '.pdf"')
        ->withStringBody($pdfContent);
}
```

**Step 3: Commit**

```bash
git add src/Controller/EmployeeLeavesController.php
git commit -m "feat: use resolveTemplate for PDF export based on employee contract type"
```

---

### Task 15: Verify and test manually

**Step 1: Start dev server**

```bash
bin/cake server
```

**Step 2: Manual verification checklist**

1. Go to Employees > Edit any employee. Verify contract type dropdown shows FIJO, INDEFINIDO, OBRA O LABOR DETERMINADA. Verify the org temporal field shows/hides correctly when selecting OBRA O LABOR DETERMINADA.
2. Go to Leave Types > Add. Verify the inline table appears. Add a row, select FIJO, verify org dropdown is disabled. Select OBRA O LABOR DETERMINADA, verify org dropdown enables. Select a template and save.
3. Go to Leave Types > Edit the type you just created. Verify existing rows load correctly. Add/remove rows. Save.
4. Go to Leave Types > Index. Verify the template column shows assignment count.
5. Create an Employee Leave for an employee with a configured contract type. Go to view, verify "Exportar PDF" button appears. Click it, verify PDF generates.
6. Try exporting PDF for an employee whose contract type has no template assigned. Verify flash error message.

**Step 3: Final commit**

```bash
git add -A
git commit -m "feat: complete leave type contract template assignments feature"
```
