# Employee History & Observations Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add audit trail (change history) and threaded observations to the Employees module, replicating existing Invoice patterns.

**Architecture:** Two new tables (`employee_histories`, `employee_observations`) with corresponding models, a new `EmployeeHistoryService` for field-by-field change tracking, controller integration for recording changes on edit and adding observations, and view updates showing chat-style observations and a history table. Finally, migrate existing `notes` data and drop the column.

**Tech Stack:** CakePHP 5.3, MariaDB, PHP 8.2+, Bootstrap 5

---

### Task 1: Migration — Create `employee_histories` and `employee_observations` tables

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_CreateEmployeeHistoriesAndObservations.php`

**Step 1: Create the migration file**

```bash
cd C:/Users/sistema/Documents/sgi && bin/cake migrations create CreateEmployeeHistoriesAndObservations
```

**Step 2: Write the migration code**

Replace the generated file content with:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateEmployeeHistoriesAndObservations extends BaseMigration
{
    public function up(): void
    {
        $this->table('employee_histories')
            ->addColumn('employee_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('field_changed', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('old_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('new_value', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();

        $this->table('employee_observations')
            ->addColumn('employee_id', 'integer', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('employee_observations')->drop()->save();
        $this->table('employee_histories')->drop()->save();
    }
}
```

**Step 3: Run the migration**

```bash
bin/cake migrations migrate
```

Expected: Tables `employee_histories` and `employee_observations` created successfully.

**Step 4: Commit**

```bash
git add config/Migrations/*CreateEmployeeHistoriesAndObservations*
git commit -m "feat: add employee_histories and employee_observations tables"
```

---

### Task 2: Models — Entity + Table for EmployeeHistory and EmployeeObservation

**Files:**
- Create: `src/Model/Entity/EmployeeHistory.php`
- Create: `src/Model/Entity/EmployeeObservation.php`
- Create: `src/Model/Table/EmployeeHistoriesTable.php`
- Create: `src/Model/Table/EmployeeObservationsTable.php`

**Step 1: Create EmployeeHistory entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class EmployeeHistory extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'user_id' => true,
        'field_changed' => true,
        'old_value' => true,
        'new_value' => true,
    ];
}
```

**Step 2: Create EmployeeObservation entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class EmployeeObservation extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'user_id' => true,
        'message' => true,
    ];
}
```

**Step 3: Create EmployeeHistoriesTable**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeeHistoriesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employee_histories');
        $this->setDisplayField('field_changed');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
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
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('field_changed')
            ->maxLength('field_changed', 100)
            ->requirePresence('field_changed', 'create')
            ->notEmptyString('field_changed');

        $validator
            ->scalar('old_value')
            ->allowEmptyString('old_value');

        $validator
            ->scalar('new_value')
            ->allowEmptyString('new_value');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
```

**Step 4: Create EmployeeObservationsTable**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmployeeObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('employee_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
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
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'La observación no puede estar vacía.');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
```

**Step 5: Commit**

```bash
git add src/Model/Entity/EmployeeHistory.php src/Model/Entity/EmployeeObservation.php src/Model/Table/EmployeeHistoriesTable.php src/Model/Table/EmployeeObservationsTable.php
git commit -m "feat: add EmployeeHistory and EmployeeObservation models"
```

---

### Task 3: Add hasMany relationships to EmployeesTable

**Files:**
- Modify: `src/Model/Table/EmployeesTable.php` (inside `initialize()`, after the existing `hasMany('EmployeeNovelties')` block at line ~59)

**Step 1: Add the two hasMany associations**

Add after the `EmployeeNovelties` hasMany (line 59):

```php
        $this->hasMany('EmployeeHistories', [
            'foreignKey' => 'employee_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('EmployeeObservations', [
            'foreignKey' => 'employee_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
```

**Step 2: Commit**

```bash
git add src/Model/Table/EmployeesTable.php
git commit -m "feat: add EmployeeHistories and EmployeeObservations associations to EmployeesTable"
```

---

### Task 4: Create EmployeeHistoryService

**Files:**
- Create: `src/Service/EmployeeHistoryService.php`

**Step 1: Write the service**

Follow the exact same pattern as `src/Service/InvoiceHistoryService.php` but adapted for Employee fields.

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Employee;
use Cake\ORM\TableRegistry;

class EmployeeHistoryService
{
    public const FIELD_LABELS = [
        'document_type'            => 'Tipo de Documento',
        'document_number'          => 'Número de Documento',
        'first_name'               => 'Nombres',
        'last_name1'               => 'Primer Apellido',
        'last_name2'               => 'Segundo Apellido',
        'birth_date'               => 'Fecha de Nacimiento',
        'gender'                   => 'Género',
        'marital_status_id'        => 'Estado Civil',
        'education_level_id'       => 'Nivel Educativo',
        'email'                    => 'Correo Electrónico',
        'phone'                    => 'Teléfono',
        'address'                  => 'Dirección',
        'city'                     => 'Ciudad',
        'employee_status_id'       => 'Estado del Empleado',
        'position_id'              => 'Cargo',
        'supervisor_position_id'   => 'Jefe Inmediato',
        'operation_center_id'      => 'Centro de Operación',
        'cost_center_id'           => 'Centro de Costos',
        'hire_date'                => 'Fecha de Ingreso',
        'termination_date'         => 'Fecha de Retiro',
        'salary'                   => 'Salario',
        'contract_type'            => 'Tipo de Contrato',
        'temporary_organization_id' => 'Organización Temporal',
        'vest_number'              => 'Número de Chaleco',
        'eps'                      => 'EPS',
        'pension_fund'             => 'Fondo de Pensión',
        'arl'                      => 'ARL',
        'severance_fund'           => 'Fondo de Cesantías',
    ];

    public function recordChanges(Employee $original, Employee $modified, int $userId): void
    {
        $fieldsToTrack = array_keys(self::FIELD_LABELS);

        $historiesTable = TableRegistry::getTableLocator()->get('EmployeeHistories');

        foreach ($fieldsToTrack as $field) {
            $oldVal = $original->get($field);
            $newVal = $modified->get($field);

            // Normalize DateTime to string for comparison
            if ($oldVal instanceof \DateTimeInterface) {
                $oldVal = $oldVal->format('Y-m-d');
            }
            if ($newVal instanceof \DateTimeInterface) {
                $newVal = $newVal->format('Y-m-d');
            }

            // Normalize booleans
            if (is_bool($oldVal) || is_bool($newVal)) {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            // Normalize null and empty string
            if ($oldVal === '') {
                $oldVal = null;
            }
            if ($newVal === '') {
                $newVal = null;
            }

            if ($oldVal !== $newVal) {
                $history = $historiesTable->newEntity([
                    'employee_id' => $original->id,
                    'user_id' => $userId,
                    'field_changed' => $field,
                    'old_value' => $oldVal !== null ? (string)$oldVal : null,
                    'new_value' => $newVal !== null ? (string)$newVal : null,
                ]);
                $historiesTable->save($history);
            }
        }
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/EmployeeHistoryService.php
git commit -m "feat: add EmployeeHistoryService for audit trail"
```

---

### Task 5: Integrate history + observations in EmployeesController

**Files:**
- Modify: `src/Controller/EmployeesController.php`

**Step 1: Add import and service property**

At the top of the file, add to the use statements:

```php
use App\Service\EmployeeHistoryService;
```

Add property after `private EmployeeDocumentService $documentService;` (line 22):

```php
    private EmployeeHistoryService $historyService;
```

In `initialize()` (after line 27), add:

```php
        $this->historyService = new EmployeeHistoryService();
```

**Step 2: Modify `edit()` to record changes**

Replace the `edit()` method (lines 136-159) with:

```php
    public function edit($id = null)
    {
        $employee = $this->Employees->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $original = clone $employee;
            $employee = $this->Employees->patchEntity($employee, $this->request->getData());
            if ($this->Employees->save($employee)) {
                $userId = (int)$this->Authentication->getIdentity()->getIdentifier();
                $this->historyService->recordChanges($original, $employee, $userId);

                $warning = $this->documentService->handleProfileImage(
                    $employee,
                    $this->request->getUploadedFile('profile_image_file'),
                );
                if ($warning) {
                    $this->Flash->warning(__($warning));
                }
                $this->Flash->success(__('El empleado ha sido actualizado.'));

                return $this->redirect(['action' => 'view', $employee->id]);
            }
            $this->Flash->error(__('No se pudo actualizar el empleado. Intente de nuevo.'));
        }

        $this->_setFormDropdowns();
        $this->set(compact('employee'));
    }
```

**Step 3: Modify `view()` to contain histories and observations**

In the `view()` method, add to the `contain` array (after the `EmployeeFolders` block, before the closing `]`):

```php
            'EmployeeObservations' => [
                'sort' => ['EmployeeObservations.created' => 'ASC'],
                'Users',
            ],
            'EmployeeHistories' => [
                'sort' => ['EmployeeHistories.created' => 'DESC'],
                'Users',
            ],
```

Also add `fieldLabels` to the view variables. After the `$this->set(compact(...))` line in `view()`, add:

```php
        $this->set('fieldLabels', EmployeeHistoryService::FIELD_LABELS);
```

And add the import at the top if not already:

```php
use App\Service\EmployeeHistoryService;
```

**Step 4: Add `addObservation()` action**

Add this method to `EmployeesController` (after the `delete()` method):

```php
    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $userId = (int)$this->Authentication->getIdentity()->getIdentifier();

        $observationsTable = $this->fetchTable('EmployeeObservations');
        $observation = $observationsTable->newEntity([
            'employee_id' => $id,
            'user_id' => $userId,
            'message' => $this->request->getData('message'),
        ]);

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }
```

**Step 5: Commit**

```bash
git add src/Controller/EmployeesController.php
git commit -m "feat: integrate history recording and observations in EmployeesController"
```

---

### Task 6: Add route for addObservation

**Files:**
- Modify: `config/routes.php`

**Step 1: Add the route**

Add before `$builder->fallbacks();` (line 257), after the employees import/export routes block:

```php
        // Employee observations
        $builder->connect(
            '/employees/add-observation/{id}',
            ['controller' => 'Employees', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
        );
```

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat: add route for employee observations"
```

---

### Task 7: Update templates/Employees/view.php — Observations chat + History table

**Files:**
- Modify: `templates/Employees/view.php`

**Step 1: Replace the "Observaciones" section (lines 252-258)**

Replace this block:

```php
    <!-- Observaciones -->
    <?php if ($employee->notes): ?>
    <div style="border-top:1px solid var(--border-color);padding:1rem 1.25rem">
        <div class="sgi-section-title" style="padding:0 0 .5rem">Observaciones</div>
        <p class="mb-0" style="font-size:.8125rem;color:#555;line-height:1.6"><?= nl2br(h($employee->notes)) ?></p>
    </div>
    <?php endif; ?>
```

With:

```php
    <!-- Observaciones (chat) -->
    <div style="border-top:1px solid var(--border-color)">
        <div class="sgi-section-title">Observaciones</div>
        <div style="max-height:400px;overflow-y:auto;padding:.5rem 1.25rem .875rem;">
            <?php if (empty($employee->employee_observations)): ?>
            <div class="text-center text-muted py-3" style="font-size:.8rem">
                <i class="bi bi-chat-square-dots d-block mb-1" style="font-size:1.5rem;color:#ddd"></i>
                Sin observaciones aún
            </div>
            <?php else: ?>
            <?php foreach ($employee->employee_observations as $obs): ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:var(--primary-color);color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
        <div style="border-top:1px solid var(--border-color);padding:.75rem 1.25rem;">
            <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $employee->id]]) ?>
            <div class="d-flex gap-2 align-items-end">
                <textarea name="message" class="form-control" rows="1"
                          style="font-size:.82rem;resize:none;"
                          placeholder="Escriba una observación..." required></textarea>
                <button type="submit" class="btn btn-primary flex-shrink-0"
                        style="padding:.5rem .75rem;" title="Enviar">
                    <i class="bi bi-send" style="font-size:.85rem;"></i>
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>
    </div>
```

**Step 2: Add the History table after the closing `</div>` of the employee card (line 260) and before the Novedades card**

Add this block between the employee card closing `</div>` and the `<!-- Novedades -->` comment:

```php
<!-- Historial de Cambios -->
<?php if (!empty($employee->employee_histories)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history"></i>
            Historial de Cambios
        </span>
    </div>
    <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employee->employee_histories as $history): ?>
                <tr>
                    <td style="font-size:.8rem;white-space:nowrap;"><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td style="font-size:.8rem;"><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td style="font-size:.8rem;"><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td style="font-size:.8rem;" class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td style="font-size:.8rem;" class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add templates/Employees/view.php
git commit -m "feat: add observations chat and change history to employee view"
```

---

### Task 8: Migration — Migrate notes data and drop notes column

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_MigrateEmployeeNotesAndDropColumn.php`
- Modify: `src/Model/Entity/Employee.php` (remove `'notes' => true` from `$_accessible`)

**Step 1: Create the migration**

```bash
bin/cake migrations create MigrateEmployeeNotesAndDropColumn
```

**Step 2: Write the migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigrateEmployeeNotesAndDropColumn extends BaseMigration
{
    public function up(): void
    {
        // Migrate existing notes to employee_observations
        $employees = $this->fetchAll(
            "SELECT id, notes, created FROM employees WHERE notes IS NOT NULL AND TRIM(notes) != ''"
        );

        foreach ($employees as $emp) {
            $this->execute(sprintf(
                "INSERT INTO employee_observations (employee_id, user_id, message, created) VALUES (%d, 1, %s, %s)",
                $emp['id'],
                $this->getAdapter()->getConnection()->quote($emp['notes']),
                $emp['created'] ? $this->getAdapter()->getConnection()->quote($emp['created']) : 'NOW()'
            ));
        }

        // Drop the notes column
        $this->table('employees')
            ->removeColumn('notes')
            ->update();
    }

    public function down(): void
    {
        $this->table('employees')
            ->addColumn('notes', 'text', ['null' => true, 'default' => null, 'after' => 'severance_fund'])
            ->update();
    }
}
```

**Step 3: Remove `notes` from Employee entity**

In `src/Model/Entity/Employee.php`, remove the line `'notes' => true,` from the `$_accessible` array.

**Step 4: Run the migration**

```bash
bin/cake migrations migrate
```

**Step 5: Commit**

```bash
git add config/Migrations/*MigrateEmployeeNotesAndDropColumn* src/Model/Entity/Employee.php
git commit -m "feat: migrate employee notes to observations and drop notes column"
```

---

### Task 9: Verify everything works end-to-end

**Step 1: Start dev server**

```bash
bin/cake server
```

**Step 2: Manual verification checklist**

- [ ] Visit `/employees/view/{id}` — observations section shows (with form to add)
- [ ] Add an observation — appears in chat with user name and timestamp
- [ ] Edit an employee (change salary, position, etc.) — save succeeds
- [ ] Visit `/employees/view/{id}` — history table shows the changes with correct field labels
- [ ] If employee had `notes` before, verify it appears as first observation (user=Admin)
- [ ] Check that observations form is hidden if user doesn't have edit permission

**Step 3: Run code style check**

```bash
composer cs-check src/Service/EmployeeHistoryService.php src/Model/Entity/EmployeeHistory.php src/Model/Entity/EmployeeObservation.php src/Model/Table/EmployeeHistoriesTable.php src/Model/Table/EmployeeObservationsTable.php src/Controller/EmployeesController.php
```

Fix any issues with `composer cs-fix`.
