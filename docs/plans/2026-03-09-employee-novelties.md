# Employee Novelties Module — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the separate employee_leaves, leave_types, and employee_incidents modules with a unified employee_novelties + novelty_types system that manages all employee events (permissions, licenses, disabilities, vacations, etc.) from a single flexible structure.

**Architecture:** Create new DB tables (novelty_types with self-referencing parent_id, employee_novelties with approval workflow, novelty_type_contract_templates as bridge to document templates). Build new controllers/models/templates mirroring the existing patterns. Adapt LeaveDocumentService and LeaveSignatureService to work with the new tables. Remove all legacy code.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MariaDB, Bootstrap 5, Flatpickr, Select2

---

### Task 1: Create NoveltyConstants

**Files:**
- Create: `src/Constants/NoveltyConstants.php`

**Step 1: Create the constants file**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    public const STATUS_PENDING = 'pendiente';
    public const STATUS_APPROVED = 'aprobado';
    public const STATUS_REJECTED = 'rechazado';
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const SCHEDULE_DAYS = 'days';
    public const SCHEDULE_HOURS = 'hours';
    public const SCHEDULE_TYPES = [self::SCHEDULE_DAYS, self::SCHEDULE_HOURS];

    public const SCHEDULE_LABELS = [
        self::SCHEDULE_DAYS => 'Por días',
        self::SCHEDULE_HOURS => 'Por horas',
    ];
}
```

**Step 2: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "feat: add NoveltyConstants for employee novelties module"
```

---

### Task 2: Create Migration — novelty_types table

**Files:**
- Create: `config/Migrations/20260309000001_CreateNoveltyTypes.php`

**Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_types')) {
            $this->table('novelty_types')
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('parent_id', 'integer', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('parent_id', 'novelty_types', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_types')) {
            $this->table('novelty_types')->drop()->save();
        }
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260309000001_CreateNoveltyTypes.php
git commit -m "feat: add novelty_types table migration"
```

---

### Task 3: Create Migration — employee_novelties table

**Files:**
- Create: `config/Migrations/20260309000002_CreateEmployeeNovelties.php`

**Step 1: Create the migration**

Important: Check the `employees` and `users` tables to determine if their `id` columns are signed or unsigned, and match the FK columns accordingly. The existing `employee_leaves` table uses unsigned IDs for `employee_id` based on the employees table. The `users` table uses unsigned IDs.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEmployeeNovelties extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('employee_novelties')) {
            $this->table('employee_novelties')
                ->addColumn('employee_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('novelty_type_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('filing_date', 'date', ['null' => false])
                ->addColumn('permission_date', 'date', ['null' => false])
                ->addColumn('schedule_type', 'string', ['limit' => 10, 'null' => false])
                ->addColumn('start_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('end_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('start_time', 'time', ['null' => true, 'default' => null])
                ->addColumn('end_time', 'time', ['null' => true, 'default' => null])
                ->addColumn('is_paid', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('reason', 'text', ['null' => false])
                ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pendiente'])
                ->addColumn('approved_by', 'integer', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('registered_by', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('employee_signature', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('coordinator_signature', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('novelty_type_id', 'novelty_types', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->addForeignKey('approved_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->addForeignKey('registered_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('employee_novelties')) {
            $this->table('employee_novelties')->drop()->save();
        }
    }
}
```

**IMPORTANT:** Before running this migration, verify the signedness of `employees.id`, `users.id`, and `novelty_types.id` columns. If any of them are signed (not unsigned), adjust the corresponding FK columns to match. Run this SQL to check:

```sql
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('employees', 'users', 'novelty_types')
  AND COLUMN_NAME = 'id';
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260309000002_CreateEmployeeNovelties.php
git commit -m "feat: add employee_novelties table migration"
```

---

### Task 4: Create Migration — novelty_type_contract_templates table

**Files:**
- Create: `config/Migrations/20260309000003_CreateNoveltyTypeContractTemplates.php`

**Step 1: Create the migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyTypeContractTemplates extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_type_contract_templates')) {
            $this->table('novelty_type_contract_templates')
                ->addColumn('novelty_type_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('contract_type', 'string', ['limit' => 50, 'null' => false])
                ->addColumn('temporary_organization_id', 'integer', ['null' => true, 'default' => null, 'signed' => false])
                ->addColumn('leave_document_template_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['novelty_type_id', 'contract_type', 'temporary_organization_id'], ['unique' => true])
                ->addForeignKey('novelty_type_id', 'novelty_types', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('temporary_organization_id', 'temporary_organizations', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->addForeignKey('leave_document_template_id', 'leave_document_templates', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_type_contract_templates')) {
            $this->table('novelty_type_contract_templates')->drop()->save();
        }
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```

**Step 3: Commit**

```bash
git add config/Migrations/20260309000003_CreateNoveltyTypeContractTemplates.php
git commit -m "feat: add novelty_type_contract_templates table migration"
```

---

### Task 5: Create NoveltyType Model (Entity + Table)

**Files:**
- Create: `src/Model/Entity/NoveltyType.php`
- Create: `src/Model/Table/NoveltyTypesTable.php`

**Step 1: Create Entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyType extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'parent_id' => true,
        'novelty_type_contract_templates' => true,
    ];
}
```

**Step 2: Create Table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyTypesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('ParentNoveltyTypes', [
            'className' => 'NoveltyTypes',
            'foreignKey' => 'parent_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('ChildNoveltyTypes', [
            'className' => 'NoveltyTypes',
            'foreignKey' => 'parent_id',
            'dependent' => false,
        ]);
        $this->hasMany('NoveltyTypeContractTemplates', [
            'foreignKey' => 'novelty_type_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'novelty_type_id',
            'dependent' => false,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->integer('parent_id')
            ->allowEmptyString('parent_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('parent_id', 'ParentNoveltyTypes'), [
            'errorField' => 'parent_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
```

**Step 3: Commit**

```bash
git add src/Model/Entity/NoveltyType.php src/Model/Table/NoveltyTypesTable.php
git commit -m "feat: add NoveltyType entity and table"
```

---

### Task 6: Create EmployeeNovelty Model (Entity + Table)

**Files:**
- Create: `src/Model/Entity/EmployeeNovelty.php`
- Create: `src/Model/Table/EmployeeNoveltiesTable.php`

**Step 1: Create Entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\ORM\Entity;

class EmployeeNovelty extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'novelty_type_id' => true,
        'filing_date' => true,
        'permission_date' => true,
        'schedule_type' => true,
        'start_date' => true,
        'end_date' => true,
        'start_time' => true,
        'end_time' => true,
        'is_paid' => true,
        'reason' => true,
        'status' => true,
        'approved_by' => true,
        'approved_at' => true,
        'registered_by' => true,
        'employee_signature' => true,
        'coordinator_signature' => true,
        'observations' => true,
    ];

    public function isPending(): bool
    {
        return $this->status === NoveltyConstants::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === NoveltyConstants::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === NoveltyConstants::STATUS_REJECTED;
    }
}
```

**Step 2: Create Table**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeeNoveltiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employee_novelties');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('NoveltyTypes', [
            'foreignKey' => 'novelty_type_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('ApprovedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'approved_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('RegisteredByUsers', [
            'className' => 'Users',
            'foreignKey' => 'registered_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('employee_id')
            ->requirePresence('employee_id', 'create')
            ->notEmptyString('employee_id');

        $validator
            ->integer('novelty_type_id')
            ->requirePresence('novelty_type_id', 'create')
            ->notEmptyString('novelty_type_id');

        $validator
            ->date('filing_date')
            ->requirePresence('filing_date', 'create')
            ->notEmptyDate('filing_date');

        $validator
            ->date('permission_date')
            ->requirePresence('permission_date', 'create')
            ->notEmptyDate('permission_date');

        $validator
            ->scalar('schedule_type')
            ->inList('schedule_type', NoveltyConstants::SCHEDULE_TYPES)
            ->requirePresence('schedule_type', 'create')
            ->notEmptyString('schedule_type');

        $validator
            ->date('start_date')
            ->allowEmptyDate('start_date');

        $validator
            ->date('end_date')
            ->allowEmptyDate('end_date');

        $validator
            ->time('start_time')
            ->allowEmptyTime('start_time');

        $validator
            ->time('end_time')
            ->allowEmptyTime('end_time');

        $validator
            ->boolean('is_paid')
            ->notEmptyString('is_paid');

        $validator
            ->scalar('reason')
            ->requirePresence('reason', 'create')
            ->notEmptyString('reason');

        $validator
            ->scalar('status')
            ->inList('status', NoveltyConstants::STATUSES);

        $validator
            ->scalar('observations')
            ->allowEmptyString('observations');

        return $validator;
    }

    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        $scheduleType = $data['schedule_type'] ?? null;

        if ($scheduleType === NoveltyConstants::SCHEDULE_HOURS) {
            $data['start_date'] = null;
            $data['end_date'] = null;
        } elseif ($scheduleType === NoveltyConstants::SCHEDULE_DAYS) {
            $data['start_time'] = null;
            $data['end_time'] = null;
        }
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);
        $rules->add($rules->existsIn('novelty_type_id', 'NoveltyTypes'), ['errorField' => 'novelty_type_id']);
        $rules->add($rules->existsIn('approved_by', 'ApprovedByUsers'), [
            'errorField' => 'approved_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('registered_by', 'RegisteredByUsers'), ['errorField' => 'registered_by']);

        // If schedule_type='hours', require start_time and end_time
        $rules->add(function ($entity) {
            if ($entity->schedule_type !== NoveltyConstants::SCHEDULE_HOURS) {
                return true;
            }

            return !empty($entity->start_time) && !empty($entity->end_time);
        }, 'hoursRequired', [
            'errorField' => 'start_time',
            'message' => 'Hora de salida y entrada son requeridas para horario por horas.',
        ]);

        // start_time < end_time
        $rules->add(function ($entity) {
            if ($entity->schedule_type !== NoveltyConstants::SCHEDULE_HOURS || empty($entity->start_time) || empty($entity->end_time)) {
                return true;
            }

            return (string)$entity->start_time < (string)$entity->end_time;
        }, 'startBeforeEnd', [
            'errorField' => 'start_time',
            'message' => 'La hora de salida debe ser anterior a la hora de entrada.',
        ]);

        // If schedule_type='days', require start_date and end_date
        $rules->add(function ($entity) {
            if ($entity->schedule_type !== NoveltyConstants::SCHEDULE_DAYS) {
                return true;
            }

            return !empty($entity->start_date) && !empty($entity->end_date);
        }, 'daysRequired', [
            'errorField' => 'start_date',
            'message' => 'Fecha inicio y fecha fin son requeridas para horario por días.',
        ]);

        return $rules;
    }
}
```

**Step 3: Commit**

```bash
git add src/Model/Entity/EmployeeNovelty.php src/Model/Table/EmployeeNoveltiesTable.php
git commit -m "feat: add EmployeeNovelty entity and table with validation"
```

---

### Task 7: Create NoveltyTypeContractTemplate Model (Entity + Table)

**Files:**
- Create: `src/Model/Entity/NoveltyTypeContractTemplate.php`
- Create: `src/Model/Table/NoveltyTypeContractTemplatesTable.php`

**Step 1: Create Entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyTypeContractTemplate extends Entity
{
    protected array $_accessible = [
        'novelty_type_id' => true,
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

class NoveltyTypeContractTemplatesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_type_contract_templates');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('NoveltyTypes', [
            'foreignKey' => 'novelty_type_id',
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
            ->integer('novelty_type_id')
            ->allowEmptyString('novelty_type_id');

        $validator
            ->scalar('contract_type')
            ->inList('contract_type', ContractTypeConstants::ALL, 'Tipo de contrato inválido.')
            ->notEmptyString('contract_type');

        $validator
            ->integer('leave_document_template_id')
            ->notEmptyString('leave_document_template_id');

        $validator
            ->integer('temporary_organization_id')
            ->allowEmptyString('temporary_organization_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('novelty_type_id', 'NoveltyTypes'), ['errorField' => 'novelty_type_id']);
        $rules->add($rules->existsIn('leave_document_template_id', 'LeaveDocumentTemplates'), ['errorField' => 'leave_document_template_id']);
        $rules->add($rules->existsIn('temporary_organization_id', 'TemporaryOrganizations'), [
            'errorField' => 'temporary_organization_id',
            'allowNullableNulls' => true,
        ]);

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
git add src/Model/Entity/NoveltyTypeContractTemplate.php src/Model/Table/NoveltyTypeContractTemplatesTable.php
git commit -m "feat: add NoveltyTypeContractTemplate entity and table"
```

---

### Task 8: Create NoveltyTypesController

**Files:**
- Create: `src/Controller/NoveltyTypesController.php`

**Step 1: Create controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\ContractTypeConstants;
use Cake\ORM\TableRegistry;

class NoveltyTypesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $query = $this->NoveltyTypes->find()
            ->contain([
                'ParentNoveltyTypes',
                'ChildNoveltyTypes',
                'NoveltyTypeContractTemplates.LeaveDocumentTemplates',
            ])
            ->where(['NoveltyTypes.parent_id IS' => null])
            ->order(['NoveltyTypes.name' => 'ASC']);

        $noveltyTypes = $this->paginate($query);
        $this->set(compact('noveltyTypes'));
    }

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
            ->order(['name' => 'ASC'])
            ->toArray();

        $this->_setFormData();
        $this->set(compact('noveltyType', 'parentTypes'));
    }

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
            ->order(['name' => 'ASC'])
            ->toArray();

        $this->_setFormData();
        $this->set(compact('noveltyType', 'parentTypes'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $noveltyType = $this->NoveltyTypes->get($id);

        // Check if there are novelties using this type
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
            ->order(['name' => 'ASC'])
            ->toArray();

        $temporaryOrganizations = TableRegistry::getTableLocator()->get('TemporaryOrganizations')
            ->find('list', valueField: 'name')
            ->order(['name' => 'ASC'])
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
```

**Step 2: Commit**

```bash
git add src/Controller/NoveltyTypesController.php
git commit -m "feat: add NoveltyTypesController with CRUD and contract templates"
```

---

### Task 9: Create NoveltyTypes Templates (index, add, edit)

**Files:**
- Create: `templates/NoveltyTypes/index.php`
- Create: `templates/NoveltyTypes/add.php`
- Create: `templates/NoveltyTypes/edit.php`

**Step 1: Create index.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\NoveltyType> $noveltyTypes
 */
$this->assign('title', 'Tipos de Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Tipos de Novedad</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['novelty_types']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Tipo',
            ['action' => 'add'],
            ['class' => 'btn btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Subtipos</th>
                    <th>Plantilla</th>
                    <th style="width:160px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($noveltyTypes as $noveltyType): ?>
                <tr>
                    <td><strong><?= h($noveltyType->name) ?></strong></td>
                    <td>
                        <?php if (!empty($noveltyType->child_novelty_types)): ?>
                            <?php foreach ($noveltyType->child_novelty_types as $child): ?>
                                <span class="badge bg-light text-dark border me-1"><?= h($child->name) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($noveltyType->novelty_type_contract_templates)): ?>
                            <span class="text-muted">
                                <i class="bi bi-file-earmark-pdf me-1"></i><?= count($noveltyType->novelty_type_contract_templates) ?> asignación(es)
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (!empty($userPermissions['novelty_types']['can_create'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-plus-circle"></i>',
                                ['action' => 'add', '?' => ['parent_id' => $noveltyType->id]],
                                ['class' => 'btn btn-sm btn-outline-success', 'escape' => false, 'title' => 'Agregar subtipo']
                            ) ?>
                            <?php endif; ?>
                            <?php if (!empty($userPermissions['novelty_types']['can_edit'])): ?>
                            <?= $this->Html->link(
                                '<i class="bi bi-pencil"></i>',
                                ['action' => 'edit', $noveltyType->id],
                                ['class' => 'btn btn-sm btn-outline-dark', 'escape' => false]
                            ) ?>
                            <?php endif; ?>
                            <?php if (!empty($userPermissions['novelty_types']['can_delete'])): ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-trash"></i>',
                                ['action' => 'delete', $noveltyType->id],
                                ['class' => 'btn btn-sm btn-outline-danger', 'escape' => false,
                                 'confirm' => '¿Eliminar este tipo de novedad?']
                            ) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
```

**Step 2: Create add.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyType $noveltyType
 * @var array $parentTypes
 * @var array $documentTemplates
 * @var array $temporaryOrganizations
 * @var array $contractTypes
 */
$preselectedParent = $this->request->getQuery('parent_id');
$this->assign('title', $preselectedParent ? 'Nuevo Subtipo de Novedad' : 'Nuevo Tipo de Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title"><?= $this->fetch('title') ?></span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($noveltyType) ?>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Ej: Licencia de Maternidad']) ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tipo Padre (opcional)</label>
                <?= $this->Form->control('parent_id', [
                    'label' => false,
                    'options' => $parentTypes,
                    'empty' => '— Ninguno (tipo principal) —',
                    'class' => 'form-select',
                    'value' => $preselectedParent,
                ]) ?>
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
    var obraLaborValue = <?= json_encode(\App\Constants\ContractTypeConstants::OBRA_LABOR) ?>;
    var rowIndex = 0;
    var tbody = document.getElementById('contract-templates-body');

    function buildOptions(obj, emptyLabel) {
        var html = '<option value="">' + emptyLabel + '</option>';
        for (var k in obj) {
            if (obj.hasOwnProperty(k)) {
                html += '<option value="' + k + '">' + obj[k] + '</option>';
            }
        }
        return html;
    }

    function addRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var prefix = 'novelty_type_contract_templates[' + rowIndex + ']';
        var hiddenHtml = '';

        if (data.id) {
            hiddenHtml = '<input type="hidden" name="' + prefix + '[id]" value="' + data.id + '">';
        }

        tr.innerHTML = hiddenHtml
            + '<td><select name="' + prefix + '[contract_type]" class="form-select form-select-sm ct-contract-type">'
            + buildOptions(contractTypes, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[temporary_organization_id]" class="form-select form-select-sm ct-org-select" disabled>'
            + buildOptions(temporaryOrgs, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[leave_document_template_id]" class="form-select form-select-sm">'
            + buildOptions(documentTemplates, '-- Seleccione --') + '</select></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row"><i class="bi bi-trash"></i></button></td>';

        tbody.appendChild(tr);

        var ctSelect = tr.querySelector('.ct-contract-type');
        var orgSelect = tr.querySelector('.ct-org-select');

        function toggleOrg() {
            var isObraLabor = ctSelect.value === obraLaborValue;
            orgSelect.disabled = !isObraLabor;
            if (!isObraLabor) orgSelect.value = '';
        }

        ctSelect.addEventListener('change', toggleOrg);

        if (data.contract_type) ctSelect.value = data.contract_type;
        if (data.leave_document_template_id) {
            tr.querySelector('[name$="[leave_document_template_id]"]').value = data.leave_document_template_id;
        }

        toggleOrg();

        if (data.temporary_organization_id) {
            orgSelect.disabled = false;
            orgSelect.value = data.temporary_organization_id;
        }

        tr.querySelector('.ct-remove-row').addEventListener('click', function() { tr.remove(); });
        rowIndex++;
    }

    document.getElementById('add-contract-template-row').addEventListener('click', function() { addRow(); });
})();
<?php $this->Html->scriptEnd(); ?>
```

**Step 3: Create edit.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyType $noveltyType
 * @var array $parentTypes
 * @var array $documentTemplates
 * @var array $temporaryOrganizations
 * @var array $contractTypes
 */
$this->assign('title', 'Editar Tipo de Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Tipo de Novedad</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($noveltyType) ?>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Tipo Padre (opcional)</label>
                <?= $this->Form->control('parent_id', [
                    'label' => false,
                    'options' => $parentTypes,
                    'empty' => '— Ninguno (tipo principal) —',
                    'class' => 'form-select',
                ]) ?>
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
if (!empty($noveltyType->novelty_type_contract_templates)) {
    foreach ($noveltyType->novelty_type_contract_templates as $ct) {
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
    var obraLaborValue = <?= json_encode(\App\Constants\ContractTypeConstants::OBRA_LABOR) ?>;
    var rowIndex = 0;
    var tbody = document.getElementById('contract-templates-body');

    function buildOptions(obj, emptyLabel) {
        var html = '<option value="">' + emptyLabel + '</option>';
        for (var k in obj) {
            if (obj.hasOwnProperty(k)) {
                html += '<option value="' + k + '">' + obj[k] + '</option>';
            }
        }
        return html;
    }

    function addRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var prefix = 'novelty_type_contract_templates[' + rowIndex + ']';
        var hiddenHtml = '';

        if (data.id) {
            hiddenHtml = '<input type="hidden" name="' + prefix + '[id]" value="' + data.id + '">';
        }

        tr.innerHTML = hiddenHtml
            + '<td><select name="' + prefix + '[contract_type]" class="form-select form-select-sm ct-contract-type">'
            + buildOptions(contractTypes, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[temporary_organization_id]" class="form-select form-select-sm ct-org-select" disabled>'
            + buildOptions(temporaryOrgs, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[leave_document_template_id]" class="form-select form-select-sm">'
            + buildOptions(documentTemplates, '-- Seleccione --') + '</select></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row"><i class="bi bi-trash"></i></button></td>';

        tbody.appendChild(tr);

        var ctSelect = tr.querySelector('.ct-contract-type');
        var orgSelect = tr.querySelector('.ct-org-select');

        function toggleOrg() {
            var isObraLabor = ctSelect.value === obraLaborValue;
            orgSelect.disabled = !isObraLabor;
            if (!isObraLabor) orgSelect.value = '';
        }

        ctSelect.addEventListener('change', toggleOrg);

        if (data.contract_type) ctSelect.value = data.contract_type;
        if (data.leave_document_template_id) {
            tr.querySelector('[name$="[leave_document_template_id]"]').value = data.leave_document_template_id;
        }

        toggleOrg();

        if (data.temporary_organization_id) {
            orgSelect.disabled = false;
            orgSelect.value = data.temporary_organization_id;
        }

        tr.querySelector('.ct-remove-row').addEventListener('click', function() { tr.remove(); });
        rowIndex++;
    }

    existingRows.forEach(function(row) { addRow(row); });

    document.getElementById('add-contract-template-row').addEventListener('click', function() { addRow(); });
})();
<?php $this->Html->scriptEnd(); ?>
```

**Step 4: Commit**

```bash
git add templates/NoveltyTypes/
git commit -m "feat: add NoveltyTypes templates (index, add, edit)"
```

---

### Task 10: Create EmployeeNoveltiesController

**Files:**
- Create: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Create controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\LeaveDocumentService;
use App\Service\NoveltySignatureService;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use DateTime;

class EmployeeNoveltiesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $roleName = $this->_getUserRoleName($user);

        $query = $this->EmployeeNovelties->find()
            ->contain(['Employees', 'NoveltyTypes', 'RegisteredByUsers'])
            ->order(['EmployeeNovelties.created' => 'DESC']);

        // Non-admin users see only novelties for employees they supervise
        if ($roleName !== 'Administrador') {
            $subordinateIds = $this->_getSubordinateEmployeeIds($user);
            if (!empty($subordinateIds)) {
                $query->where(['EmployeeNovelties.employee_id IN' => $subordinateIds]);
            } else {
                $query->where(['1 = 0']);
            }
        }

        // Optional filters
        $statusFilter = $this->request->getQuery('status');
        if ($statusFilter) {
            $query->where(['EmployeeNovelties.status' => $statusFilter]);
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

    public function view($id = null)
    {
        $novelty = $this->EmployeeNovelties->get($id, contain: [
            'Employees',
            'NoveltyTypes',
            'ApprovedByUsers',
            'RegisteredByUsers',
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $canApprove = $this->_canApproveNovelty($user, $novelty);

        $service = new LeaveDocumentService();
        $employee = $novelty->employee;
        $template = $service->resolveTemplate(
            (int)$novelty->novelty_type_id,
            $employee->contract_type ?? null,
            $employee->temporary_organization_id ?? null
        );
        $hasActiveTemplate = $template && $template->is_active;

        $this->set(compact('novelty', 'canApprove', 'hasActiveTemplate'));
    }

    public function exportPdf($id = null): ?Response
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
            $employee->temporary_organization_id ?? null
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

    public function add()
    {
        $novelty = $this->EmployeeNovelties->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->Authentication->getIdentity()->getOriginalData();
            $data = $this->request->getData();
            $data['registered_by'] = $user->id;
            $data['status'] = NoveltyConstants::STATUS_PENDING;
            $data['filing_date'] = Date::now()->format('Y-m-d');

            // Handle is_paid logic based on novelty type
            // (No automatic override — user decides; future enhancement possible)

            $novelty = $this->EmployeeNovelties->patchEntity($novelty, $data);
            if ($this->EmployeeNovelties->save($novelty)) {
                // Process employee signature
                $signatureService = new NoveltySignatureService();
                $signaturePath = null;

                // Try file upload first
                $signatureFile = $this->request->getUploadedFile('signature_file');
                if ($signatureFile && $signatureFile->getError() === UPLOAD_ERR_OK) {
                    $signaturePath = $signatureService->saveFromUpload($novelty->id, $signatureFile, $user->id, 'employee');
                }

                // Fall back to base64 canvas
                if (!$signaturePath) {
                    $signatureBase64 = $this->request->getData('signature_base64');
                    if (!empty($signatureBase64)) {
                        $signaturePath = $signatureService->saveFromBase64($novelty->id, $signatureBase64, $user->id, 'employee');
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

    public function approve($id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveNovelty($user, $novelty)) {
            $this->Flash->error('No tiene permisos para aprobar esta novedad.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $novelty->status = NoveltyConstants::STATUS_APPROVED;
        $novelty->approved_by = $user->id;
        $novelty->approved_at = new DateTime();

        // Process coordinator signature if provided
        $signatureService = new NoveltySignatureService();
        $coordSignatureFile = $this->request->getUploadedFile('coordinator_signature_file');
        if ($coordSignatureFile && $coordSignatureFile->getError() === UPLOAD_ERR_OK) {
            $coordPath = $signatureService->saveFromUpload($novelty->id, $coordSignatureFile, $user->id, 'coordinator');
            if ($coordPath) {
                $novelty->coordinator_signature = $coordPath;
            }
        }
        $coordBase64 = $this->request->getData('coordinator_signature_base64');
        if (!$novelty->coordinator_signature && !empty($coordBase64)) {
            $coordPath = $signatureService->saveFromBase64($novelty->id, $coordBase64, $user->id, 'coordinator');
            if ($coordPath) {
                $novelty->coordinator_signature = $coordPath;
            }
        }

        if ($this->EmployeeNovelties->save($novelty)) {
            $this->Flash->success('Novedad aprobada.');
        } else {
            $this->Flash->error('No se pudo aprobar la novedad.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['Employees']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if (!$this->_canApproveNovelty($user, $novelty)) {
            $this->Flash->error('No tiene permisos para rechazar esta novedad.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $novelty->status = NoveltyConstants::STATUS_REJECTED;
        $novelty->approved_by = $user->id;
        $novelty->approved_at = new DateTime();

        $observations = $this->request->getData('observations');
        if ($observations) {
            $novelty->observations = $observations;
        }

        if ($this->EmployeeNovelties->save($novelty)) {
            $this->Flash->success('Novedad rechazada.');
        } else {
            $this->Flash->error('No se pudo rechazar la novedad.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Get novelty types grouped by parent for the dropdown.
     * Returns array like: ['Licencia' => ['1' => 'Licencia de Luto', ...], 'Permiso' => ...]
     * or flat list if no subtypes exist.
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

    private function _canApproveNovelty(object $user, object $novelty): bool
    {
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === 'Administrador') {
            return true;
        }

        if ($novelty->status !== NoveltyConstants::STATUS_PENDING) {
            return false;
        }

        $employee = $novelty->employee;
        if (!$employee || !$employee->supervisor_position_id) {
            return false;
        }

        $employeesTable = TableRegistry::getTableLocator()->get('Employees');
        $supervisorEmployee = $employeesTable->find()
            ->where([
                'position_id' => $employee->supervisor_position_id,
                'active' => true,
            ])
            ->first();

        if (!$supervisorEmployee) {
            return false;
        }

        return $supervisorEmployee->email === $user->email;
    }

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
```

**Step 2: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat: add EmployeeNoveltiesController with CRUD and approval workflow"
```

---

### Task 11: Create NoveltySignatureService

**Files:**
- Create: `src/Service/NoveltySignatureService.php`

**Step 1: Create service**

Adapted from `LeaveSignatureService` — uses `uploads/novelties/{noveltyId}/` path and supports both employee and coordinator signature types.

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Laminas\Diactoros\UploadedFile;

class NoveltySignatureService
{
    private const MAX_SIZE = 2 * 1024 * 1024; // 2MB
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    public function saveFromUpload(int $noveltyId, UploadedFile $file, int $userId, string $type = 'employee'): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        $mime = $file->getClientMediaType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return null;
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return null;
        }

        $dir = $this->ensureDir($noveltyId);
        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $fileName = "{$type}_signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        $file->moveTo($filePath);

        return "uploads/novelties/{$noveltyId}/{$fileName}";
    }

    public function saveFromBase64(int $noveltyId, string $base64Data, int $userId, string $type = 'employee'): ?string
    {
        if (!preg_match('/^data:image\/(png|jpeg);base64,/', $base64Data, $matches)) {
            return null;
        }

        $ext = $matches[1] === 'jpeg' ? 'jpg' : 'png';
        $data = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64Data));

        if ($data === false || strlen($data) > self::MAX_SIZE) {
            return null;
        }

        $dir = $this->ensureDir($noveltyId);
        $fileName = "{$type}_signature_{$userId}_" . time() . ".{$ext}";
        $filePath = $dir . DS . $fileName;

        file_put_contents($filePath, $data);

        return "uploads/novelties/{$noveltyId}/{$fileName}";
    }

    private function ensureDir(int $noveltyId): string
    {
        $dir = WWW_ROOT . 'uploads' . DS . 'novelties' . DS . $noveltyId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltySignatureService.php
git commit -m "feat: add NoveltySignatureService for employee and coordinator signatures"
```

---

### Task 12: Create EmployeeNovelties Templates (index, add, view)

**Files:**
- Create: `templates/EmployeeNovelties/index.php`
- Create: `templates/EmployeeNovelties/add.php`
- Create: `templates/EmployeeNovelties/view.php`

**Step 1: Create index.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmployeeNovelty> $novelties
 * @var string|null $statusFilter
 * @var string|null $typeFilter
 * @var array $noveltyTypes
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Novedades de Empleados');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Novedades de Empleados</span>
    <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-plus-lg me-1"></i>Nueva Novedad',
        ['action' => 'add'],
        ['class' => 'btn btn-primary', 'escape' => false]
    ) ?>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card card-primary mb-3">
    <div class="card-body py-2 px-3">
        <form method="get" class="d-flex gap-3 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm" style="max-width:160px;" onchange="this.form.submit()">
                <option value="">Estado: Todos</option>
                <?php foreach (NoveltyConstants::STATUSES as $s): ?>
                <option value="<?= $s ?>" <?= ($statusFilter ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="novelty_type_id" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <?php foreach ($noveltyTypes as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($typeFilter ?? '') == $id ? 'selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($statusFilter || $typeFilter): ?>
            <a href="<?= $this->Url->build(['action' => 'index']) ?>" class="btn btn-sm btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tipo de Novedad</th>
                    <th>Fecha Permiso</th>
                    <th>Horario</th>
                    <th>Remunerado</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($novelties as $novelty): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $novelty->id]) ?>">
                    <td><?= h($novelty->employee->full_name ?? '—') ?></td>
                    <td><?= h($novelty->novelty_type->name ?? '—') ?></td>
                    <td><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></td>
                    <td><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></td>
                    <td>
                        <?php if ($novelty->is_paid): ?>
                            <span class="badge bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusBadges[$novelty->status] ?? 'bg-secondary' ?>"><?= ucfirst(h($novelty->status)) ?></span></td>
                    <td style="font-size:.8125rem;color:#888"><?= h($novelty->registered_by_user->full_name ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('pagination') ?>
</div>
```

**Step 2: Create add.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var array $employees
 * @var array $noveltyTypes
 * @var string|null $preselectedEmployee
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Nueva Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Novedad</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create($novelty, ['type' => 'file']) ?>
        <input type="hidden" name="filing_date" value="<?= date('Y-m-d') ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Empleado</label>
                <?= $this->Form->control('employee_id', [
                    'label' => false,
                    'options' => $employees,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select select2-enable',
                    'value' => $preselectedEmployee,
                ]) ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tipo de Novedad</label>
                <?= $this->Form->control('novelty_type_id', [
                    'label' => false,
                    'options' => $noveltyTypes,
                    'empty' => '-- Seleccione --',
                    'class' => 'form-select',
                ]) ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Fecha del Permiso</label>
                <input type="text" name="permission_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->permission_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Horario</label>
                <select name="schedule_type" id="schedule-type-select" class="form-select">
                    <option value="">-- Seleccione --</option>
                    <option value="<?= NoveltyConstants::SCHEDULE_DAYS ?>" <?= ($novelty->schedule_type ?? '') === NoveltyConstants::SCHEDULE_DAYS ? 'selected' : '' ?>>Por días</option>
                    <option value="<?= NoveltyConstants::SCHEDULE_HOURS ?>" <?= ($novelty->schedule_type ?? '') === NoveltyConstants::SCHEDULE_HOURS ? 'selected' : '' ?>>Por horas</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-4">
                    <input type="hidden" name="is_paid" value="0">
                    <input type="checkbox" name="is_paid" value="1" class="form-check-input"
                           id="paid-check" <?= !empty($novelty->is_paid) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="paid-check">Remunerado</label>
                </div>
            </div>

            <!-- Days fields -->
            <div class="col-md-4" id="start-date-group" style="display:none;">
                <label class="form-label">Fecha Inicio</label>
                <input type="text" name="start_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->start_date?->format('Y-m-d') ?? '') ?>">
            </div>
            <div class="col-md-4" id="end-date-group" style="display:none;">
                <label class="form-label">Fecha Fin</label>
                <input type="text" name="end_date" class="form-control flatpickr-date"
                       value="<?= h($novelty->end_date?->format('Y-m-d') ?? '') ?>">
            </div>

            <!-- Hours fields -->
            <div class="col-md-4" id="start-time-group" style="display:none;">
                <label class="form-label">Hora Salida</label>
                <input type="time" name="start_time" class="form-control"
                       value="<?= h($novelty->start_time ?? '') ?>">
            </div>
            <div class="col-md-4" id="end-time-group" style="display:none;">
                <label class="form-label">Hora Entrada</label>
                <input type="time" name="end_time" class="form-control"
                       value="<?= h($novelty->end_time ?? '') ?>">
            </div>

            <div class="col-12">
                <label class="form-label">Motivo</label>
                <?= $this->Form->control('reason', [
                    'label' => false,
                    'type' => 'textarea',
                    'rows' => 3,
                    'class' => 'form-control',
                ]) ?>
            </div>

            <!-- Firma del Funcionario -->
            <div class="col-12">
                <label class="form-label">Firma del Funcionario</label>
                <div class="d-flex gap-3 align-items-start">
                    <div>
                        <input type="file" name="signature_file" id="signature-file" class="form-control form-control-sm"
                               accept="image/png,image/jpeg" style="max-width:300px;">
                        <div class="form-text">O dibuje su firma abajo</div>
                    </div>
                </div>
                <div class="mt-2" style="border:1px solid var(--border-color);display:inline-block;">
                    <canvas id="signature-canvas" width="400" height="150" style="cursor:crosshair;display:block;"></canvas>
                </div>
                <input type="hidden" name="signature_base64" id="signature-base64">
                <div class="mt-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="signature-clear">
                        <i class="bi bi-eraser me-1"></i>Limpiar Firma
                    </button>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary" id="btn-submit">
                <i class="bi bi-save me-1"></i>Registrar Novedad
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<script>
(function() {
    // Toggle fields based on schedule type
    var scheduleSelect = document.getElementById('schedule-type-select');
    var startDateGroup = document.getElementById('start-date-group');
    var endDateGroup = document.getElementById('end-date-group');
    var startTimeGroup = document.getElementById('start-time-group');
    var endTimeGroup = document.getElementById('end-time-group');

    function toggleScheduleFields() {
        var val = scheduleSelect.value;
        startDateGroup.style.display = val === 'days' ? '' : 'none';
        endDateGroup.style.display = val === 'days' ? '' : 'none';
        startTimeGroup.style.display = val === 'hours' ? '' : 'none';
        endTimeGroup.style.display = val === 'hours' ? '' : 'none';
    }
    scheduleSelect.addEventListener('change', toggleScheduleFields);
    toggleScheduleFields();

    // Signature canvas
    var canvas = document.getElementById('signature-canvas');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasDrawn = false;

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var clientX, clientY;
        if (e.touches && e.touches.length > 0) {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    canvas.addEventListener('mousedown', function(e) { drawing = true; var pos = getPos(e); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); });
    canvas.addEventListener('mousemove', function(e) { if (!drawing) return; hasDrawn = true; var pos = getPos(e); ctx.lineTo(pos.x, pos.y); ctx.stroke(); });
    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });

    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var pos = getPos(e); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); });
    canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; hasDrawn = true; var pos = getPos(e); ctx.lineTo(pos.x, pos.y); ctx.stroke(); });
    canvas.addEventListener('touchend', function() { drawing = false; });

    document.getElementById('signature-clear').addEventListener('click', function() {
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
        document.getElementById('signature-base64').value = '';
    });

    document.getElementById('btn-submit').closest('form').addEventListener('submit', function() {
        if (hasDrawn) {
            document.getElementById('signature-base64').value = canvas.toDataURL('image/png');
        }
    });
})();
</script>
```

**Step 3: Create view.php**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmployeeNovelty $novelty
 * @var bool $canApprove
 * @var bool $hasActiveTemplate
 */
use App\Constants\NoveltyConstants;

$this->assign('title', 'Detalle de Novedad');

$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle de Novedad</span>
    <div class="d-flex gap-2">
        <?php if (!empty($hasActiveTemplate)): ?>
        <?= $this->Html->link(
            '<i class="bi bi-file-earmark-pdf me-1"></i>Exportar PDF',
            ['action' => 'exportPdf', $novelty->id],
            ['class' => 'btn btn-outline-danger btn-sm', 'escape' => false, 'target' => '_blank']
        ) ?>
        <?php endif; ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;">
                    <?= h($novelty->employee->full_name ?? '') ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    <?= h($novelty->novelty_type->name ?? '') ?>
                </div>
            </div>
        </div>
        <span class="badge <?= $statusBadges[$novelty->status] ?? 'bg-secondary' ?>">
            <?= ucfirst(h($novelty->status)) ?>
        </span>
    </div>

    <div class="row g-0" style="border-top:1px solid var(--border-color);">
        <div class="col-md-6" style="border-right:1px solid var(--border-color);">
            <div class="sgi-section-title">Información de la Novedad</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($novelty->employee->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo</span>
                <span class="sgi-data-value"><?= h($novelty->novelty_type->name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha del Permiso</span>
                <span class="sgi-data-value"><?= $novelty->permission_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Horario</span>
                <span class="sgi-data-value"><?= $scheduleLabels[$novelty->schedule_type] ?? h($novelty->schedule_type) ?></span>
            </div>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_DAYS): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Inicio</span>
                <span class="sgi-data-value"><?= $novelty->start_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Fin</span>
                <span class="sgi-data-value"><?= $novelty->end_date?->format('d/m/Y') ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($novelty->schedule_type === NoveltyConstants::SCHEDULE_HOURS): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Hora Salida</span>
                <span class="sgi-data-value"><?= h($novelty->start_time) ?: '—' ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Hora Entrada</span>
                <span class="sgi-data-value"><?= h($novelty->end_time) ?: '—' ?></span>
            </div>
            <?php endif; ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Remunerado</span>
                <span class="sgi-data-value">
                    <?php if ($novelty->is_paid): ?>
                        <span class="badge bg-success">Sí</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">No</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($novelty->reason): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Motivo</span>
                <span class="sgi-data-value"><?= nl2br(h($novelty->reason)) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <div class="sgi-section-title">Gestión</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Registrado por</span>
                <span class="sgi-data-value"><?= h($novelty->registered_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Aprobado por</span>
                <span class="sgi-data-value"><?= h($novelty->approved_by_user->full_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Aprobación</span>
                <span class="sgi-data-value"><?= $novelty->approved_at ? $novelty->approved_at->format('d/m/Y H:i') : '—' ?></span>
            </div>
            <?php if ($novelty->filing_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Diligenciamiento</span>
                <span class="sgi-data-value"><?= $novelty->filing_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Firmas -->
    <?php if ($novelty->employee_signature): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Firma del Funcionario</div>
        <div style="padding:.25rem 1.25rem .875rem;">
            <img src="<?= $this->Url->build('/' . $novelty->employee_signature) ?>" alt="Firma Funcionario"
                 style="max-width:400px;max-height:150px;border:1px solid var(--border-color);">
        </div>
    </div>
    <?php endif; ?>

    <?php if ($novelty->coordinator_signature): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Firma del Coordinador</div>
        <div style="padding:.25rem 1.25rem .875rem;">
            <img src="<?= $this->Url->build('/' . $novelty->coordinator_signature) ?>" alt="Firma Coordinador"
                 style="max-width:400px;max-height:150px;border:1px solid var(--border-color);">
        </div>
    </div>
    <?php endif; ?>

    <?php if ($novelty->observations): ?>
    <div style="border-top:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.25rem 1.25rem .875rem;font-size:.875rem;color:#555;line-height:1.65;">
            <?= nl2br(h($novelty->observations)) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canApprove): ?>
    <div style="border-top:1px solid var(--border-color);padding:1.25rem;">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                <i class="bi bi-check-lg me-1"></i>Aprobar
            </button>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                <i class="bi bi-x-lg me-1"></i>Rechazar
            </button>
        </div>
    </div>

    <!-- Approve modal (with coordinator signature) -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <?= $this->Form->create(null, ['url' => ['action' => 'approve', $novelty->id], 'type' => 'file']) ?>
                <div class="modal-header">
                    <h5 class="modal-title">Aprobar Novedad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Aprobar esta novedad para <strong><?= h($novelty->employee->full_name ?? '') ?></strong>?</p>

                    <label class="form-label">Firma del Coordinador (opcional)</label>
                    <div>
                        <input type="file" name="coordinator_signature_file" class="form-control form-control-sm mb-2"
                               accept="image/png,image/jpeg" style="max-width:300px;">
                        <div class="form-text">O dibuje la firma abajo</div>
                    </div>
                    <div class="mt-2" style="border:1px solid var(--border-color);display:inline-block;">
                        <canvas id="coord-signature-canvas" width="400" height="150" style="cursor:crosshair;display:block;"></canvas>
                    </div>
                    <input type="hidden" name="coordinator_signature_base64" id="coord-signature-base64">
                    <div class="mt-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="coord-signature-clear">
                            <i class="bi bi-eraser me-1"></i>Limpiar
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btn-approve">Aprobar</button>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>

    <!-- Reject modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <?= $this->Form->create(null, ['url' => ['action' => 'reject', $novelty->id]]) ?>
                <div class="modal-header">
                    <h5 class="modal-title">Rechazar Novedad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Motivo del rechazo</label>
                    <textarea name="observations" class="form-control" rows="3"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Rechazar</button>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var canvas = document.getElementById('coord-signature-canvas');
        var ctx = canvas.getContext('2d');
        var drawing = false;
        var hasDrawn = false;

        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';

        function getPos(e) {
            var rect = canvas.getBoundingClientRect();
            var cx = e.touches ? e.touches[0].clientX : e.clientX;
            var cy = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: cx - rect.left, y: cy - rect.top };
        }

        canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('mousemove', function(e) { if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
        canvas.addEventListener('touchmove', function(e) { e.preventDefault(); if (!drawing) return; hasDrawn = true; var p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); });
        canvas.addEventListener('touchend', function() { drawing = false; });

        document.getElementById('coord-signature-clear').addEventListener('click', function() {
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); hasDrawn = false;
            document.getElementById('coord-signature-base64').value = '';
        });

        document.getElementById('btn-approve').closest('form').addEventListener('submit', function() {
            if (hasDrawn) { document.getElementById('coord-signature-base64').value = canvas.toDataURL('image/png'); }
        });
    })();
    </script>
    <?php endif; ?>
</div>
```

**Step 4: Commit**

```bash
git add templates/EmployeeNovelties/
git commit -m "feat: add EmployeeNovelties templates (index, add, view)"
```

---

### Task 13: Update Routes, AppController, and AuthorizationService

**Files:**
- Modify: `config/routes.php`
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`

**Step 1: Update routes.php**

Remove the old leave/incident routes and add novelty routes. In `config/routes.php`, replace the employee leave and incident route blocks with:

```php
// Employee novelties approve/reject/export
$builder->connect(
    '/employee-novelties/approve/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'approve'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/employee-novelties/reject/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'reject'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/employee-novelties/export-pdf/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'exportPdf'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

Remove the old routes:
- `/employee-leaves/approve/{id}`
- `/employee-leaves/reject/{id}`
- `/employee-incidents` (index)
- `/employees/{employeeId}/incidents/add`
- `/employee-incidents/deactivate/{id}`
- `/employee-leaves/export-pdf/{id}`

**Step 2: Update AppController::$controllerModuleMap**

Replace the old entries:

```php
// Remove these:
'EmployeeLeaves' => 'employee_leaves',
'LeaveTypes' => 'leave_types',
'EmployeeIncidents' => 'employee_incidents',

// Add these:
'EmployeeNovelties' => 'employee_novelties',
'NoveltyTypes' => 'novelty_types',
```

Keep `'LeaveDocumentTemplates' => 'novelty_types'` (was `'leave_types'`, now points to `'novelty_types'`).

**Step 3: Update AuthorizationService::MODULES**

Replace the old entries:

```php
// Remove these:
'employee_leaves' => 'Permisos de Empleados',
'leave_types' => 'Tipos de Permiso',
'employee_incidents' => 'Novedades',

// Add these:
'employee_novelties' => 'Novedades de Empleados',
'novelty_types' => 'Tipos de Novedad',
```

**Step 4: Commit**

```bash
git add config/routes.php src/Controller/AppController.php src/Service/AuthorizationService.php
git commit -m "feat: update routes, permissions, and module mappings for novelties"
```

---

### Task 14: Update LeaveDocumentService for Novelties

**Files:**
- Modify: `src/Service/LeaveDocumentService.php`

**Step 1: Update resolveTemplate()**

Change `resolveTemplate()` to look in `novelty_type_contract_templates` instead of `leave_type_contract_templates`:

```php
public function resolveTemplate(int $noveltyTypeId, ?string $contractType, ?int $temporaryOrgId): ?object
{
    $table = TableRegistry::getTableLocator()->get('NoveltyTypeContractTemplates');

    if ($contractType === ContractTypeConstants::OBRA_LABOR && $temporaryOrgId) {
        $match = $table->find()
            ->contain(['LeaveDocumentTemplates'])
            ->where([
                'NoveltyTypeContractTemplates.novelty_type_id' => $noveltyTypeId,
                'NoveltyTypeContractTemplates.contract_type' => $contractType,
                'NoveltyTypeContractTemplates.temporary_organization_id' => $temporaryOrgId,
            ])
            ->first();

        if ($match && $match->leave_document_template) {
            return $match->leave_document_template;
        }
    }

    if ($contractType) {
        $match = $table->find()
            ->contain(['LeaveDocumentTemplates'])
            ->where([
                'NoveltyTypeContractTemplates.novelty_type_id' => $noveltyTypeId,
                'NoveltyTypeContractTemplates.contract_type' => $contractType,
                'NoveltyTypeContractTemplates.temporary_organization_id IS' => null,
            ])
            ->first();

        if ($match && $match->leave_document_template) {
            return $match->leave_document_template;
        }
    }

    return null;
}
```

**Step 2: Update generatePdf()**

Change to load from `EmployeeNovelties` instead of `EmployeeLeaves`:

```php
public function generatePdf(int $noveltyId, int $templateId): string
{
    $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
    $novelty = $noveltiesTable->get($noveltyId, contain: [
        'Employees.Positions',
        'Employees.OperationCenters',
        'NoveltyTypes',
        'ApprovedByUsers',
        'RegisteredByUsers',
    ]);

    // ... rest of PDF generation stays the same but uses $novelty
}
```

**Step 3: Update AVAILABLE_FIELDS**

Update the field keys to match the new structure (e.g., `novelty_type.name`, `permission_date`, `is_paid`, `registered_by_user.full_name`, `employee_signature`, `coordinator_signature`).

**Step 4: Update resolveCheckField()**

Change `paid_yes`/`paid_no` to use `is_paid`:

```php
case 'paid_yes':
    return !empty($leave->is_paid) ? $mark : '';
case 'paid_no':
    return empty($leave->is_paid) ? $mark : '';
```

**Step 5: Commit**

```bash
git add src/Service/LeaveDocumentService.php
git commit -m "feat: adapt LeaveDocumentService to work with employee_novelties"
```

---

### Task 15: Update Sidebar and Employee View

**Files:**
- Modify: `templates/layout/default.php`
- Modify: `templates/Employees/view.php`
- Modify: `src/Controller/EmployeesController.php`

**Step 1: Update sidebar in default.php**

In the RRHH section, replace:
```php
$canView('employee_leaves') ? 'employee_leaves' : null,
$canView('leave_types') ? 'leave_types_templates' : null,
```
with:
```php
$canView('employee_novelties') ? 'employee_novelties' : null,
$canView('novelty_types') ? 'novelty_types_templates' : null,
```

Replace the sidebar links:
- "Permisos" (`EmployeeLeaves`) → "Novedades" (`EmployeeNovelties`) with icon `bi-journal-text`

In the Catálogos section, replace:
```php
$canView('leave_types') ? 'leave_types' : null,
```
with:
```php
$canView('novelty_types') ? 'novelty_types' : null,
```

Replace the catalog link:
- "Tipos de Permiso" (`LeaveTypes`) → "Tipos de Novedad" (`NoveltyTypes`)

Update the document templates link condition from `$canView('leave_types')` to `$canView('novelty_types')`.

**Step 2: Update EmployeesController::view()**

Replace `EmployeeIncidents` and `ActiveIncident` contains with `EmployeeNovelties`:

```php
$employee = $this->Employees->get($id, contain: [
    // ... keep existing contains
    'EmployeeNovelties' => [
        'sort' => ['EmployeeNovelties.created' => 'DESC'],
        'NoveltyTypes',
        'RegisteredByUsers',
    ],
    // Remove: 'ActiveIncident', 'EmployeeIncidents' => [...]
]);
```

**Step 3: Update EmployeesTable associations**

Add association to EmployeeNovelties, remove EmployeeIncidents and ActiveIncident:

```php
// Remove:
$this->hasMany('EmployeeIncidents', ...);
$this->hasOne('ActiveIncident', ...);

// Add:
$this->hasMany('EmployeeNovelties', [
    'foreignKey' => 'employee_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

**Step 4: Update Employees/view.php**

Replace the Novedades section (currently showing incidents) with a section showing employee novelties from the new table. Replace the modal that creates incidents with a link to `/employee-novelties/add?employee_id={id}`.

The Novedades section should show:
- Table with columns: Tipo, Fecha Permiso, Horario, Remunerado, Estado, Registrado por
- Button "Nueva Novedad" linking to `['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]]`
- Clickable rows linking to the novelty view

**Step 5: Update Employees/index.php**

Remove the `ActiveIncident` badge display if present (it uses incident_type which no longer exists).

**Step 6: Commit**

```bash
git add templates/layout/default.php templates/Employees/view.php src/Controller/EmployeesController.php src/Model/Table/EmployeesTable.php templates/Employees/index.php
git commit -m "feat: integrate novelties into sidebar, employee view, and employees table"
```

---

### Task 16: Remove Legacy Code

**Files:**
- Delete: `src/Controller/EmployeeLeavesController.php`
- Delete: `src/Controller/LeaveTypesController.php`
- Delete: `src/Controller/EmployeeIncidentsController.php`
- Delete: `src/Model/Entity/EmployeeLeave.php`
- Delete: `src/Model/Entity/LeaveType.php`
- Delete: `src/Model/Entity/EmployeeIncident.php`
- Delete: `src/Model/Entity/LeaveTypeContractTemplate.php`
- Delete: `src/Model/Table/EmployeeLeavesTable.php`
- Delete: `src/Model/Table/LeaveTypesTable.php`
- Delete: `src/Model/Table/EmployeeIncidentsTable.php`
- Delete: `src/Model/Table/LeaveTypeContractTemplatesTable.php`
- Delete: `templates/EmployeeLeaves/` (entire folder)
- Delete: `templates/LeaveTypes/` (entire folder)
- Delete: `templates/EmployeeIncidents/` (entire folder)

**Step 1: Remove files**

```bash
rm src/Controller/EmployeeLeavesController.php
rm src/Controller/LeaveTypesController.php
rm src/Controller/EmployeeIncidentsController.php
rm src/Model/Entity/EmployeeLeave.php
rm src/Model/Entity/LeaveType.php
rm src/Model/Entity/EmployeeIncident.php
rm src/Model/Entity/LeaveTypeContractTemplate.php
rm src/Model/Table/EmployeeLeavesTable.php
rm src/Model/Table/LeaveTypesTable.php
rm src/Model/Table/EmployeeIncidentsTable.php
rm src/Model/Table/LeaveTypeContractTemplatesTable.php
rm -rf templates/EmployeeLeaves/
rm -rf templates/LeaveTypes/
rm -rf templates/EmployeeIncidents/
```

**Step 2: Commit**

```bash
git add -A
git commit -m "refactor: remove legacy employee_leaves, leave_types, and employee_incidents code"
```

---

### Task 17: Create Migration to Drop Legacy Tables

**Files:**
- Create: `config/Migrations/20260309000004_DropLegacyLeaveTables.php`

**Step 1: Create migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropLegacyLeaveTables extends BaseMigration
{
    public function up(): void
    {
        // Drop in correct FK order
        if ($this->hasTable('leave_type_contract_templates')) {
            $this->table('leave_type_contract_templates')->drop()->save();
        }
        if ($this->hasTable('employee_leaves')) {
            $this->table('employee_leaves')->drop()->save();
        }
        if ($this->hasTable('leave_types')) {
            $this->table('leave_types')->drop()->save();
        }
        if ($this->hasTable('employee_incidents')) {
            $this->table('employee_incidents')->drop()->save();
        }
    }

    public function down(): void
    {
        // No rollback — tables would need to be recreated from original migrations
    }
}
```

**IMPORTANT:** Only run this migration AFTER confirming the new system works correctly and any needed data has been migrated. Consider doing this as the very last step after testing.

**Step 2: Commit**

```bash
git add config/Migrations/20260309000004_DropLegacyLeaveTables.php
git commit -m "feat: add migration to drop legacy leave and incident tables"
```

---

### Task 18: Update Permissions in Database

This is a manual/seed step. After deployment, run these SQL statements to update the permissions table:

```sql
-- Rename module references in permissions table
UPDATE permissions SET module = 'employee_novelties' WHERE module = 'employee_leaves';
UPDATE permissions SET module = 'novelty_types' WHERE module = 'leave_types';
DELETE FROM permissions WHERE module = 'employee_incidents';
```

This can also be done via a migration or seed script.

---

### Task 19: Smoke Test and Verification

**Step 1: Start the server**

```bash
bin/cake server
```

**Step 2: Verify these URLs work:**

- `http://localhost:8765/novelty-types` — Should show types list
- `http://localhost:8765/novelty-types/add` — Should show add form with parent type select and contract templates
- `http://localhost:8765/employee-novelties` — Should show novelties list
- `http://localhost:8765/employee-novelties/add` — Should show add form with employee, type, schedule toggle, signature
- `http://localhost:8765/employees/view/{id}` — Should show novelties section instead of incidents

**Step 3: Test functional flow:**

1. Create a novelty type (e.g., "Licencia") in `/novelty-types/add`
2. Create a subtype (e.g., "Licencia de Maternidad") via the "+" button
3. Create a novelty for an employee in `/employee-novelties/add`
4. Verify the novelty appears in the employee view
5. View the novelty detail and approve/reject it
6. Verify signature upload/draw works
7. Verify schedule type toggle (days vs hours fields)

**Step 4: Run code style check**

```bash
composer cs-check
```

**Step 5: Fix any issues and commit**

```bash
composer cs-fix
git add -A
git commit -m "fix: code style corrections"
```
