# Payment Authorization Pipeline — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extend the invoice pipeline with a new "Autorización de Pago" state between Tesorería and Pagada, add banking entities catalog, invoice payments sub-table, and Contador role integration.

**Architecture:** New pipeline state `autorizacion_pago` inserted before `pagada`. Contador role authorizes payments (read-only view + boolean flag). Tesorería sees both `tesoreria` and `autorizacion_pago` and performs the final advance to `pagada`. Banking entities is a standalone CRUD catalog. Invoice payments are managed as a hasMany sub-section within the invoice edit form.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Bootstrap 5, Select2, Flatpickr, AutoNumeric.

---

## Task 1: Migration — Create `banking_entities` table

**Files:**
- Create: `config/Migrations/20260407000001_CreateBankingEntities.php`

**Step 1: Generate migration scaffold**

```bash
php bin/cake migrations create CreateBankingEntities
```

**Step 2: Write migration code**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateBankingEntities extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('banking_entities')) {
            $this->table('banking_entities')
                ->addColumn('code', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('active', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['code'], ['unique' => true])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('banking_entities')) {
            $this->table('banking_entities')->drop()->save();
        }
    }
}
```

**Step 3: Run migration**

```bash
php bin/cake migrations migrate
```

Expected: table `banking_entities` created.

**Step 4: Commit**

```bash
git add config/Migrations/*CreateBankingEntities*
git commit -m "feat: create banking_entities table migration"
```

---

## Task 2: Migration — Add payment authorization fields to `invoices`

**Files:**
- Create: `config/Migrations/20260407000002_AddPaymentAuthorizationToInvoices.php`

**Step 1: Generate migration scaffold**

```bash
php bin/cake migrations create AddPaymentAuthorizationToInvoices
```

**Step 2: Write migration code**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentAuthorizationToInvoices extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->addColumn('payment_authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'payment_date',
            ])
            ->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized',
            ])
            ->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_by',
            ])
            ->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->dropForeignKey('payment_authorized_by')
            ->removeColumn('payment_authorized')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->update();
    }
}
```

**Step 3: Run migration**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*AddPaymentAuthorizationToInvoices*
git commit -m "feat: add payment_authorized fields to invoices table"
```

---

## Task 3: Migration — Create `invoice_payments` table

**Files:**
- Create: `config/Migrations/20260407000003_CreateInvoicePayments.php`

**Step 1: Generate migration scaffold**

```bash
php bin/cake migrations create CreateInvoicePayments
```

**Step 2: Write migration code**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('invoice_payments')) {
            $this->table('invoice_payments')
                ->addColumn('invoice_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['invoice_id'])
                ->addForeignKey('invoice_id', 'invoices', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_payments')) {
            $this->table('invoice_payments')->drop()->save();
        }
    }
}
```

**Step 3: Run migration**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*CreateInvoicePayments*
git commit -m "feat: create invoice_payments table migration"
```

---

## Task 4: Migration — Seed Contador role and permissions

**Files:**
- Create: `config/Migrations/20260407000004_AddContadorRoleAndPermissions.php`

**Step 1: Generate migration scaffold**

```bash
php bin/cake migrations create AddContadorRoleAndPermissions
```

**Step 2: Write migration code**

This migration inserts the Contador role if it doesn't exist, and adds permissions for `invoices` (can_view + can_edit) and `banking_entities` for relevant roles.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddContadorRoleAndPermissions extends BaseMigration
{
    public function up(): void
    {
        // Insert Contador role if not exists
        $exists = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        if (!$exists) {
            $this->execute("INSERT INTO roles (name, created, modified) VALUES ('Contador', NOW(), NOW())");
        }

        // Get Contador role_id
        $row = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        $contadorRoleId = $row['id'] ?? $row[0];

        // Contador: can view + edit invoices
        $existsPerm = $this->fetchRow(
            "SELECT id FROM permissions WHERE role_id = $contadorRoleId AND module = 'invoices'"
        );
        if (!$existsPerm) {
            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($contadorRoleId, 'invoices', 1, 0, 1, 0, NOW(), NOW())"
            );
        }

        // Add banking_entities permission for Admin (role_id=1), Tesorería, Contador
        $adminRow = $this->fetchRow("SELECT id FROM roles WHERE name = 'Administrador'");
        $adminId = $adminRow['id'] ?? $adminRow[0] ?? 1;

        $tesoreriaRow = $this->fetchRow("SELECT id FROM roles WHERE name = 'Tesorería'");
        $tesoreriaId = $tesoreriaRow ? ($tesoreriaRow['id'] ?? $tesoreriaRow[0]) : null;

        foreach ([$adminId, $tesoreriaId, $contadorRoleId] as $roleId) {
            if (!$roleId) continue;
            $ep = $this->fetchRow(
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'banking_entities'"
            );
            if (!$ep) {
                $canAll = ($roleId == $adminId) ? 1 : 0;
                $this->execute(
                    "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                     VALUES ($roleId, 'banking_entities', 1, $canAll, $canAll, $canAll, NOW(), NOW())"
                );
            }
        }
    }

    public function down(): void
    {
        $row = $this->fetchRow("SELECT id FROM roles WHERE name = 'Contador'");
        if ($row) {
            $contadorRoleId = $row['id'] ?? $row[0];
            $this->execute("DELETE FROM permissions WHERE role_id = $contadorRoleId");
            $this->execute("DELETE FROM roles WHERE id = $contadorRoleId");
        }
        $this->execute("DELETE FROM permissions WHERE module = 'banking_entities'");
    }
}
```

**Step 3: Run migration**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*AddContadorRoleAndPermissions*
git commit -m "feat: seed Contador role and banking_entities permissions"
```

---

## Task 5: Constants — Add `STATUS_AUTORIZACION_PAGO` and update pipeline

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`

**Step 1: Add new constant and update PIPELINE_STATUSES**

In `src/Constants/InvoiceConstants.php`, add the new status constant after `STATUS_TESORERIA` (line 46) and update `PIPELINE_STATUSES`:

```php
// Pipeline statuses
public const STATUS_APROBACION = 'aprobacion';
public const STATUS_CONTABILIDAD = 'contabilidad';
public const STATUS_TESORERIA = 'tesoreria';
public const STATUS_AUTORIZACION_PAGO = 'autorizacion_pago';
public const STATUS_PAGADA = 'pagada';

public const PIPELINE_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_CONTABILIDAD,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_PAGADA,
];
```

**Step 2: Commit**

```bash
git add src/Constants/InvoiceConstants.php
git commit -m "feat: add STATUS_AUTORIZACION_PAGO to InvoiceConstants"
```

---

## Task 6: Update `InvoicePipelineService` — New state, roles, transitions

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

This is the core change. Update these constants/maps:

**Step 1: Update STATUS_LABELS and STATUS_ICONS**

```php
public const STATUS_LABELS = [
    InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
    InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
    InvoiceConstants::STATUS_PAGADA            => 'Pagada',
];

public const STATUS_ICONS = [
    InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
    InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
    InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
];
```

**Step 2: Update ROLE_VISIBLE_STATUSES**

```php
private const ROLE_VISIBLE_STATUSES = [
    RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
    RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
    RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
    RoleConstants::CONTADOR          => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
    RoleConstants::ADMIN             => InvoiceConstants::PIPELINE_STATUSES,
];
```

**Step 3: Update ALL_FIELDS**

Add the new fields to `ALL_FIELDS`:

```php
private const ALL_FIELDS = [
    'invoice_number', 'issue_date', 'due_date',
    'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
    'detail', 'amount', 'expense_type_id', 'cost_center_id',
    'confirmed_by', 'approver_id', 'area_approval',
    'dian_validation', 'accrued', 'ready_for_payment',
    'payment_status', 'payment_date', 'pipeline_status',
    'payment_authorized',
];
```

**Step 4: Update EDITABLE_FIELDS**

Add Contador and Tesorería entries for the new state:

```php
private const EDITABLE_FIELDS = [
    RoleConstants::REGISTRO_REVISION => [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by',
            'dian_validation',
        ],
    ],
    RoleConstants::CONTABILIDAD => [
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'ready_for_payment',
        ],
    ],
    RoleConstants::TESORERIA => [
        InvoiceConstants::STATUS_TESORERIA => [
            'payment_status', 'payment_date',
        ],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
    ],
    RoleConstants::CONTADOR => [
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
            'payment_authorized',
        ],
    ],
];
```

**Step 5: Update VISIBLE_SECTIONS_BY_ROLE**

```php
private const VISIBLE_SECTIONS_BY_ROLE = [
    RoleConstants::REGISTRO_REVISION => ['general', 'dates', 'classification', 'revision'],
    RoleConstants::CONTABILIDAD      => ['general', 'dates', 'classification', 'accounting'],
    RoleConstants::TESORERIA         => ['general', 'treasury'],
    RoleConstants::CONTADOR          => ['general', 'dates', 'classification', 'revision', 'accounting', 'treasury', 'payment_authorization'],
];
```

**Step 6: Update TRANSITION_REQUIREMENTS**

Change `tesoreria` to accept partial or total payment, and add `autorizacion_pago`:

```php
private const TRANSITION_REQUIREMENTS = [
    InvoiceConstants::STATUS_APROBACION => [
        [
            'field' => 'area_approval',
            'value' => InvoiceConstants::APPROVAL_APPROVED,
            'label' => 'Todos los aprobadores deben haber aprobado',
        ],
        [
            'field' => 'dian_validation',
            'value' => InvoiceConstants::DIAN_APPROVED,
            'label' => 'Validación DIAN debe ser "Aprobada"',
        ],
    ],
    InvoiceConstants::STATUS_CONTABILIDAD => [
        [
            'field' => 'accrued',
            'value' => true,
            'label' => 'La factura debe estar marcada como Causada',
        ],
        [
            'field' => 'accrual_date',
            'not_empty' => true,
            'label' => 'Fecha de Causación es requerida',
        ],
        [
            'field' => 'ready_for_payment',
            'not_empty' => true,
            'label' => 'Campo "Lista para Pago" es requerido',
        ],
    ],
    InvoiceConstants::STATUS_TESORERIA => [
        [
            'field' => 'payment_status',
            'not_empty' => true,
            'label' => 'Estado de Pago es requerido (Pago total o Pago Parcial)',
        ],
        [
            'field' => 'payment_date',
            'not_empty' => true,
            'label' => 'Fecha de Pago es requerida',
        ],
    ],
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
        [
            'field' => 'payment_authorized',
            'value' => true,
            'label' => 'El Contador debe autorizar el pago antes de marcar como Pagada',
        ],
    ],
];
```

**Step 7: Update TRANSITIONS**

```php
public const TRANSITIONS = [
    InvoiceConstants::STATUS_APROBACION        => InvoiceConstants::STATUS_CONTABILIDAD,
    InvoiceConstants::STATUS_CONTABILIDAD       => InvoiceConstants::STATUS_TESORERIA,
    InvoiceConstants::STATUS_TESORERIA          => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
    InvoiceConstants::STATUS_AUTORIZACION_PAGO  => InvoiceConstants::STATUS_PAGADA,
    InvoiceConstants::STATUS_PAGADA             => null,
];
```

**Step 8: Update `getVisibleSections()` for Admin**

In the `getVisibleSections()` method, update the Admin logic to include the new state index:

```php
public function getVisibleSections(string $roleName, string $status): array
{
    if ($roleName !== RoleConstants::ADMIN) {
        return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? ['general'];
    }

    // Admin: show sections up to the current state
    // STATUSES: aprobacion(0), contabilidad(1), tesoreria(2), autorizacion_pago(3), pagada(4)
    $statusIndex = $this->getStatusIndex($status);
    $sections = ['general', 'dates', 'classification', 'revision'];
    if ($statusIndex >= 1) {
        $sections[] = 'accounting';
    }
    if ($statusIndex >= 2) {
        $sections[] = 'treasury';
    }
    if ($statusIndex >= 3) {
        $sections[] = 'payment_authorization';
    }

    return $sections;
}
```

**Step 9: Update `saveAndAdvance()` for auto-set `payment_authorized_by`**

Inside `saveAndAdvance()`, after the line that handles `area_approval` auto-date (around line 300), add:

```php
// Auto-set payment_authorized_by and payment_authorized_date when authorizing
if (array_key_exists('payment_authorized', $filteredData)) {
    if (!empty($filteredData['payment_authorized']) && !$invoice->payment_authorized) {
        $filteredData['payment_authorized_by'] = $userId;
        $filteredData['payment_authorized_date'] = date('Y-m-d');
    } elseif (empty($filteredData['payment_authorized'])) {
        $filteredData['payment_authorized_by'] = null;
        $filteredData['payment_authorized_date'] = null;
    }
}
```

**Step 10: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "feat: extend pipeline with autorizacion_pago state and Contador role"
```

---

## Task 7: Entity & Table — Invoice model updates

**Files:**
- Modify: `src/Model/Entity/Invoice.php`
- Modify: `src/Model/Table/InvoicesTable.php`

**Step 1: Update Invoice entity accessible fields**

In `src/Model/Entity/Invoice.php`, add to `$_accessible`:

```php
'payment_authorized' => true,
'payment_authorized_by' => true,
'payment_authorized_date' => false,
```

**Step 2: Update InvoicesTable associations**

In `src/Model/Table/InvoicesTable.php` `initialize()`, add:

```php
$this->belongsTo('PaymentAuthorizedByUsers', [
    'className' => 'Users',
    'foreignKey' => 'payment_authorized_by',
    'joinType' => 'LEFT',
]);
$this->hasMany('InvoicePayments', [
    'foreignKey' => 'invoice_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

**Step 3: Update InvoicesTable validation**

Add validators for the new fields:

```php
$validator
    ->boolean('payment_authorized');

$validator
    ->integer('payment_authorized_by')
    ->allowEmptyString('payment_authorized_by');

$validator
    ->date('payment_authorized_date')
    ->allowEmptyDate('payment_authorized_date');
```

**Step 4: Update InvoicesTable build rules**

Add:

```php
$rules->add($rules->existsIn('payment_authorized_by', 'PaymentAuthorizedByUsers'), [
    'errorField' => 'payment_authorized_by',
    'allowNullableNulls' => true,
]);
```

**Step 5: Commit**

```bash
git add src/Model/Entity/Invoice.php src/Model/Table/InvoicesTable.php
git commit -m "feat: add payment authorization fields to Invoice entity and table"
```

---

## Task 8: BankingEntities — Entity, Table, Controller, Templates

**Files:**
- Create: `src/Model/Entity/BankingEntity.php`
- Create: `src/Model/Table/BankingEntitiesTable.php`
- Create: `src/Controller/BankingEntitiesController.php`
- Create: `templates/BankingEntities/index.php`
- Create: `templates/BankingEntities/add.php`
- Create: `templates/BankingEntities/edit.php`
- Modify: `src/Controller/AppController.php` (add to `$controllerModuleMap`)
- Modify: `src/Service/AuthorizationService.php` (add to `MODULES`)
- Modify: `templates/layout/default.php` (add sidebar link)

**Step 1: Create Entity**

`src/Model/Entity/BankingEntity.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class BankingEntity extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'name' => true,
        'active' => true,
    ];
}
```

**Step 2: Create Table**

`src/Model/Table/BankingEntitiesTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class BankingEntitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('banking_entities');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('InvoicePayments', [
            'foreignKey' => 'banking_entity_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 20)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->boolean('active');

        return $validator;
    }

    public function findCodeList(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['BankingEntities.active' => true])
            ->order(['BankingEntities.name' => 'ASC'])
            ->formatResults(function ($results) {
                return $results->combine('id', function ($row) {
                    return $row->code . ' - ' . $row->name;
                });
            });
    }
}
```

**Step 3: Create Controller**

`src/Controller/BankingEntitiesController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

class BankingEntitiesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function index()
    {
        $bankingEntities = $this->paginate($this->BankingEntities);

        $this->set(compact('bankingEntities'));
    }

    public function add()
    {
        $bankingEntity = $this->BankingEntities->newEmptyEntity();
        if ($this->request->is('post')) {
            $bankingEntity = $this->BankingEntities->patchEntity($bankingEntity, $this->request->getData());
            if ($this->BankingEntities->save($bankingEntity)) {
                $this->Flash->success(__('La entidad bancaria ha sido guardada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo guardar la entidad bancaria. Intente de nuevo.'));
        }

        $this->set(compact('bankingEntity'));
    }

    public function edit($id = null)
    {
        $bankingEntity = $this->BankingEntities->get($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $bankingEntity = $this->BankingEntities->patchEntity($bankingEntity, $this->request->getData());
            if ($this->BankingEntities->save($bankingEntity)) {
                $this->Flash->success(__('La entidad bancaria ha sido actualizada.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('No se pudo actualizar la entidad bancaria. Intente de nuevo.'));
        }

        $this->set(compact('bankingEntity'));
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $bankingEntity = $this->BankingEntities->get($id);
        if ($this->BankingEntities->delete($bankingEntity)) {
            $this->Flash->success(__('La entidad bancaria ha sido eliminada.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar la entidad bancaria. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
```

**Step 4: Create template — index**

`templates/BankingEntities/index.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\BankingEntity> $bankingEntities
 */
$this->assign('title', 'Entidades Bancarias');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Entidades Bancarias</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['banking_entities']['can_create'])): ?>
        <?= $this->Html->link('<i class="bi bi-plus-lg me-1"></i>Nueva Entidad', ['action' => 'add'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('code', 'Código') ?></th>
                    <th><?= $this->Paginator->sort('name', 'Nombre') ?></th>
                    <th><?= $this->Paginator->sort('active', 'Estado') ?></th>
                    <th><?= $this->Paginator->sort('created', 'Creado') ?></th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bankingEntities as $entity): ?>
                <tr>
                    <td><?= $this->Number->format($entity->id) ?></td>
                    <td><code><?= h($entity->code) ?></code></td>
                    <td><?= h($entity->name) ?></td>
                    <td>
                        <?php if ($entity->active): ?>
                            <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $entity->created?->format('d/m/Y H:i') ?></td>
                    <td class="text-end">
                        <?php if (!empty($userPermissions['banking_entities']['can_edit'])): ?>
                        <?= $this->Html->link('<i class="bi bi-pencil"></i>', ['action' => 'edit', $entity->id], ['class' => 'btn btn-sm btn-outline-warning', 'escape' => false, 'title' => 'Editar']) ?>
                        <?php endif; ?>
                        <?php if (!empty($userPermissions['banking_entities']['can_delete'])): ?>
                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'delete', $entity->id], ['confirm' => '¿Está seguro de eliminar esta entidad bancaria?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>
```

**Step 5: Create template — add**

`templates/BankingEntities/add.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\BankingEntity $bankingEntity
 */
$this->assign('title', 'Nueva Entidad Bancaria');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Entidad Bancaria</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?= $this->Form->create($bankingEntity) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Ej: 001']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Ej: Bancolombia']) ?>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-2">
                    <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'id' => 'active-check']) ?>
                    <label class="form-check-label" for="active-check">Activo</label>
                </div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
```

**Step 6: Create template — edit**

`templates/BankingEntities/edit.php`:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\BankingEntity $bankingEntity
 */
$this->assign('title', 'Editar Entidad Bancaria');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Entidad Bancaria</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?= $this->Form->create($bankingEntity) ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <?= $this->Form->control('code', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Nombre</label>
                <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-4">
                <div class="form-check mt-2">
                    <?= $this->Form->checkbox('active', ['class' => 'form-check-input', 'id' => 'active-check']) ?>
                    <label class="form-check-label" for="active-check">Activo</label>
                </div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Actualizar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
```

**Step 7: Add to `$controllerModuleMap` in `AppController.php`**

In `src/Controller/AppController.php`, add inside `$controllerModuleMap` (after the `CostCenters` entry):

```php
'BankingEntities' => 'banking_entities',
```

**Step 8: Add to `AuthorizationService::MODULES`**

In `src/Service/AuthorizationService.php`, add inside `MODULES`:

```php
'banking_entities' => 'Entidades Bancarias',
```

**Step 9: Add sidebar link in `default.php`**

In `templates/layout/default.php`, after the Providers nav-item in the Catálogos section (around line 329), add:

```php
<?php if ($canView('banking_entities')): ?>
<li class="nav-item">
    <?= $this->Html->link(
        '<i class="bi bi-bank me-2"></i>Entidades Bancarias',
        ['controller' => 'BankingEntities', 'action' => 'index'],
        ['class' => $navLink('BankingEntities'), 'escape' => false]
    ) ?>
</li>
<?php endif; ?>
```

**Step 10: Commit**

```bash
git add src/Model/Entity/BankingEntity.php src/Model/Table/BankingEntitiesTable.php src/Controller/BankingEntitiesController.php templates/BankingEntities/ src/Controller/AppController.php src/Service/AuthorizationService.php templates/layout/default.php
git commit -m "feat: add BankingEntities CRUD module with permissions and sidebar"
```

---

## Task 9: InvoicePayments — Entity, Table

**Files:**
- Create: `src/Model/Entity/InvoicePayment.php`
- Create: `src/Model/Table/InvoicePaymentsTable.php`

**Step 1: Create Entity**

`src/Model/Entity/InvoicePayment.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoicePayment extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'created_by' => true,
    ];
}
```

**Step 2: Create Table**

`src/Model/Table/InvoicePaymentsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class InvoicePaymentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('invoice_payments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Invoices', [
            'foreignKey' => 'invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('banking_entity_id')
            ->requirePresence('banking_entity_id', 'create')
            ->notEmptyString('banking_entity_id');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        $validator
            ->date('payment_date')
            ->requirePresence('payment_date', 'create')
            ->notEmptyDate('payment_date');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('invoice_id', 'Invoices'), ['errorField' => 'invoice_id']);
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

**Step 3: Commit**

```bash
git add src/Model/Entity/InvoicePayment.php src/Model/Table/InvoicePaymentsTable.php
git commit -m "feat: add InvoicePayment entity and table"
```

---

## Task 10: InvoicesController — Load payments data and handle payment CRUD

**Files:**
- Modify: `src/Controller/InvoicesController.php`

**Step 1: Update `_getFormDropdowns()` to include banking entities**

Add to the return array:

```php
'bankingEntities' => $this->fetchTable('BankingEntities')->find('codeList')->all(),
```

**Step 2: Update `edit()` to contain InvoicePayments**

In the `edit()` method, update the `$this->Invoices->get()` call to include `InvoicePayments`:

```php
$invoice = $this->Invoices->get($id, contain: [
    'Providers',
    'Employees',
    'OperationCenters',
    'PettyCashRecords',
    'InvoiceObservations' => [
        'Users',
        'sort' => ['InvoiceObservations.created' => 'ASC'],
    ],
    'InvoiceDocuments' => [
        'UploadedByUsers',
        'sort' => ['InvoiceDocuments.created' => 'DESC'],
    ],
    'InvoicePayments' => [
        'BankingEntities',
        'CreatedByUsers',
        'sort' => ['InvoicePayments.payment_date' => 'ASC'],
    ],
    'PaymentAuthorizedByUsers',
]);
```

Also set the payments total for the template:

```php
$paymentsTotal = array_sum(array_map(
    fn($p) => (float)$p->amount,
    $invoice->invoice_payments ?? [],
));
```

Add `$paymentsTotal` to `compact()` in the `set()` call.

**Step 3: Update `view()` to contain InvoicePayments**

In the `view()` method, add to the `contain` array:

```php
'InvoicePayments' => [
    'BankingEntities',
    'CreatedByUsers',
    'sort' => ['InvoicePayments.payment_date' => 'ASC'],
],
'PaymentAuthorizedByUsers',
```

**Step 4: Add `addPayment()` action**

```php
public function addPayment($invoiceId = null)
{
    $this->request->allowMethod(['post']);
    $invoice = $this->Invoices->get($invoiceId);

    $roleName = $this->_getRoleName();
    $currentStatus = $invoice->pipeline_status;

    // Only Tesorería (or Admin) can add payments in tesoreria state
    if ($roleName !== RoleConstants::ADMIN && (
        $roleName !== RoleConstants::TESORERIA ||
        $currentStatus !== InvoiceConstants::STATUS_TESORERIA
    )) {
        $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $paymentsTable = $this->fetchTable('InvoicePayments');
    $payment = $paymentsTable->newEntity([
        'invoice_id' => $invoiceId,
        'banking_entity_id' => $this->request->getData('banking_entity_id'),
        'amount' => $this->request->getData('amount'),
        'payment_date' => $this->request->getData('payment_date'),
        'created_by' => $this->_getCurrentUser()->id,
    ]);

    if ($paymentsTable->save($payment)) {
        $this->Flash->success('Pago registrado correctamente.');
    } else {
        $this->Flash->error('No se pudo registrar el pago. Verifique los datos.');
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

**Step 5: Add `deletePayment()` action**

```php
public function deletePayment($invoiceId = null, $paymentId = null)
{
    $this->request->allowMethod(['post', 'delete']);
    $invoice = $this->Invoices->get($invoiceId);

    $roleName = $this->_getRoleName();
    $currentStatus = $invoice->pipeline_status;

    if ($roleName !== RoleConstants::ADMIN && (
        $roleName !== RoleConstants::TESORERIA ||
        $currentStatus !== InvoiceConstants::STATUS_TESORERIA
    )) {
        $this->Flash->error('No tiene permisos para eliminar pagos en este estado.');

        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $paymentsTable = $this->fetchTable('InvoicePayments');
    $payment = $paymentsTable->get($paymentId);

    if ($paymentsTable->delete($payment)) {
        $this->Flash->success('Pago eliminado.');
    } else {
        $this->Flash->error('No se pudo eliminar el pago.');
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

**Step 6: Add `use` statements at top of controller**

Add at the top of `InvoicesController.php`:

```php
use App\Constants\RoleConstants;
```

**Step 7: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "feat: add invoice payments CRUD and banking entities to form dropdowns"
```

---

## Task 11: Routes — Add payment routes

**Files:**
- Modify: `config/routes.php`

**Step 1: Add custom routes before fallbacks**

```php
$builder->connect(
    '/invoices/add-payment/{invoiceId}',
    ['controller' => 'Invoices', 'action' => 'addPayment'],
    ['pass' => ['invoiceId']],
);
$builder->connect(
    '/invoices/delete-payment/{invoiceId}/{paymentId}',
    ['controller' => 'Invoices', 'action' => 'deletePayment'],
    ['pass' => ['invoiceId', 'paymentId']],
);
```

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat: add routes for invoice payment add/delete"
```

---

## Task 12: Template — Treasury section with payments sub-table

**Files:**
- Modify: `templates/Invoices/edit.php`

**Step 1: Update `$pipelineBadgeMap` to include new status**

Around line 52, update:

```php
$pipelineBadgeMap = [
    'aprobacion'        => ['Aprobación',    'bg-info text-dark'],
    'contabilidad'      => ['Contabilidad',  'bg-primary'],
    'tesoreria'         => ['Tesorería',     'bg-warning text-dark'],
    'autorizacion_pago' => ['Aut. Pago',     'bg-info'],
    'pagada'            => ['Pagada',        'bg-success'],
];
```

**Step 2: Update `$sectionFieldMap` to include new section**

Around line 64, update:

```php
$sectionFieldMap = [
    'general'               => ['invoice_number', 'document_type', 'purchase_order', 'provider_id'],
    'dates'                 => ['issue_date', 'due_date'],
    'classification'        => ['operation_center_id', 'expense_type_id', 'cost_center_id', 'amount', 'detail'],
    'revision'              => ['approver_id', 'dian_validation'],
    'accounting'            => ['accrued', 'ready_for_payment'],
    'treasury'              => ['payment_status', 'payment_date'],
    'payment_authorization' => ['payment_authorized'],
];
```

**Step 3: Update `$statusLabels` and `$badgeColors` for documents section**

Around line 145, update:

```php
$statusLabels = [
    'aprobacion' => 'Aprobación',
    'contabilidad' => 'Contabilidad',
    'tesoreria' => 'Tesorería',
    'autorizacion_pago' => 'Aut. Pago',
    'pagada' => 'Pagada',
];
$badgeColors = [
    'aprobacion' => 'bg-info text-dark',
    'contabilidad' => 'bg-primary',
    'tesoreria' => 'bg-warning text-dark',
    'autorizacion_pago' => 'bg-info',
    'pagada' => 'bg-success',
];
```

**Step 4: Add payments sub-table inside the treasury section**

After the existing treasury `</div><!-- row g-3 -->` (around line 700), add a payments sub-section:

```php
<!-- Sub-sección: Pagos registrados -->
<div class="mt-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-uppercase fw-semibold" style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-credit-card me-1"></i>Pagos Registrados
        </span>
        <?php if ($canEdit('payment_status')): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#add-payment-form">
            <i class="bi bi-plus-lg me-1"></i>Agregar Pago
        </button>
        <?php endif; ?>
    </div>

    <?php if ($canEdit('payment_status')): ?>
    <div class="collapse mb-3" id="add-payment-form">
        <div class="card card-body" style="border-top:2px solid var(--primary-color);">
            <?= $this->Form->create(null, ['url' => ['action' => 'addPayment', $invoice->id]]) ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Entidad Bancaria</label>
                    <select name="banking_entity_id" class="form-select select2-enable" required>
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($bankingEntities as $beId => $beName): ?>
                            <option value="<?= $beId ?>"><?= h($beName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monto (COP)</label>
                    <input type="text" name="amount" class="form-control currency-input" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha de Pago</label>
                    <input type="text" name="payment_date" class="form-control flatpickr-date" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Registrar</button>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($invoice->invoice_payments)): ?>
    <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Entidad Bancaria</th>
                    <th>Monto</th>
                    <th>Fecha</th>
                    <th>Registrado por</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoice->invoice_payments as $payment): ?>
                <tr>
                    <td><?= h($payment->banking_entity->name ?? '—') ?></td>
                    <td>$ <?= number_format((float)$payment->amount, 0, ',', '.') ?></td>
                    <td><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></td>
                    <td><?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?></td>
                    <td class="text-end">
                        <?php if ($canEdit('payment_status')): ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i>',
                            ['action' => 'deletePayment', $invoice->id, $payment->id],
                            ['confirm' => '¿Eliminar este pago?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false]
                        ) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th>Total Pagado</th>
                    <th colspan="4">$ <?= number_format($paymentsTotal ?? 0, 0, ',', '.') ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="text-muted text-center py-3" style="font-size:.85rem;border:1px dashed var(--border-color);">
        <i class="bi bi-credit-card me-1"></i>No hay pagos registrados
    </div>
    <?php endif; ?>
</div>
```

**Step 5: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "feat: add payments sub-table to invoice treasury section"
```

---

## Task 13: Template — Payment Authorization section

**Files:**
- Modify: `templates/Invoices/edit.php`

**Step 1: Add new section block after the treasury section block**

After the `<?php endif; ?>` that closes the `treasury` section (around line 702), add:

```php
<?php if ($sectionName === 'payment_authorization' && in_array('payment_authorization', $visibleSections)): ?>
<!-- ── Sección: Autorización de Pago ── -->
<div class="mb-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-shield-check me-1"></i>Autorización de Pago
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label d-block">Autorizada para Pago</label>
            <?php if ($canEdit('payment_authorized')): ?>
            <div class="form-check">
                <?= $this->Form->checkbox('payment_authorized', [
                    'class' => 'form-check-input',
                    'id' => 'payment-authorized-check',
                ]) ?>
                <label class="form-check-label" for="payment-authorized-check">Autorizar pago</label>
            </div>
            <?php else: ?>
            <div class="py-1">
                <?php if ($invoice->payment_authorized): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizada</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Pendiente</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($invoice->payment_authorized_by): ?>
        <div class="col-md-4">
            <label class="form-label">Autorizada por</label>
            <input type="text" class="form-control" disabled
                   value="<?= h($invoice->payment_authorized_by_user->full_name ?? $invoice->payment_authorized_by_user->username ?? '—') ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Fecha de Autorización</label>
            <input type="text" class="form-control" disabled
                   value="<?= h($invoice->payment_authorized_date?->format('d/m/Y') ?? '') ?>">
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
```

**Step 2: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "feat: add payment authorization section to invoice edit form"
```

---

## Task 14: Template — Update view.php for payment authorization and payments

**Files:**
- Modify: `templates/Invoices/view.php`

**Step 1: Add payment authorization info to the view**

Find the treasury/payment section in `view.php` and after it, add a section showing:
- Payment authorized badge (yes/no)
- Authorized by user
- Authorization date
- Table of `invoice_payments` with bank, amount, date, registered by

**Step 2: Update the export method**

In `InvoicesController::export()`, add `payment_authorized` info to the export array:

```php
'Autorizada Pago'     => $invoice->payment_authorized ? 'Sí' : 'No',
```

**Step 3: Commit**

```bash
git add templates/Invoices/view.php src/Controller/InvoicesController.php
git commit -m "feat: show payment authorization and payments in invoice view and export"
```

---

## Task 15: Update `InvoiceHistoryService` — Track new fields

**Files:**
- Modify: `src/Service/InvoiceHistoryService.php`

**Step 1: Add field labels for new fields**

In the `FIELD_LABELS` constant, add:

```php
'payment_authorized' => 'Autorizada para Pago',
'payment_authorized_by' => 'Autorizada por',
'payment_authorized_date' => 'Fecha Autorización Pago',
```

**Step 2: Commit**

```bash
git add src/Service/InvoiceHistoryService.php
git commit -m "feat: track payment authorization fields in invoice history"
```

---

## Task 16: Update pipeline progress element for 5 states

**Files:**
- Modify: `templates/element/pipeline_progress.php`

**Step 1: Verify dynamic rendering**

The pipeline_progress element already renders dynamically based on `$pipelineStatuses` array. With 5 states, the circles may need slightly smaller sizing. Check if the element handles 5 steps gracefully.

If needed, reduce circle size from `48px` to `40px` when `$totalSteps >= 5`:

```php
$circleSize = $totalSteps >= 5 ? '40px' : '48px';
$fontSize = $totalSteps >= 5 ? '0.95rem' : '1.1rem';
```

Then use `$circleSize` and `$fontSize` in the inline styles.

**Step 2: Commit**

```bash
git add templates/element/pipeline_progress.php
git commit -m "fix: adjust pipeline progress for 5-step display"
```

---

## Task 17: Smoke test — Full pipeline flow

**Step 1: Start dev server**

```bash
php bin/cake server
```

**Step 2: Verify manually**

1. Login as Admin → Create a new invoice
2. Login as Registro/Revisión → Set approvals and DIAN → verify advance to Contabilidad
3. Login as Contabilidad → Mark as accrued → verify advance to Tesorería
4. Login as Tesorería → Set payment_status, payment_date → Add a payment with bank → verify advance to Aut. Pago
5. Login as Contador → See all data in read-only ledger → Mark `payment_authorized` → save (does NOT advance)
6. Login as Tesorería → See invoice in Aut. Pago → Advance to Pagada
7. Verify pipeline_progress shows 5 steps correctly
8. Verify BankingEntities CRUD works (add/edit/delete)
9. Verify invoice_payments show in view

**Step 3: Final commit**

```bash
git add -A
git commit -m "feat: complete payment authorization pipeline with Contador role"
```

---

## Summary of all files changed/created

### New files (10):
- `config/Migrations/20260407000001_CreateBankingEntities.php`
- `config/Migrations/20260407000002_AddPaymentAuthorizationToInvoices.php`
- `config/Migrations/20260407000003_CreateInvoicePayments.php`
- `config/Migrations/20260407000004_AddContadorRoleAndPermissions.php`
- `src/Model/Entity/BankingEntity.php`
- `src/Model/Table/BankingEntitiesTable.php`
- `src/Model/Entity/InvoicePayment.php`
- `src/Model/Table/InvoicePaymentsTable.php`
- `src/Controller/BankingEntitiesController.php`
- `templates/BankingEntities/index.php`, `add.php`, `edit.php`

### Modified files (10):
- `src/Constants/InvoiceConstants.php`
- `src/Service/InvoicePipelineService.php`
- `src/Service/InvoiceHistoryService.php`
- `src/Service/AuthorizationService.php`
- `src/Model/Entity/Invoice.php`
- `src/Model/Table/InvoicesTable.php`
- `src/Controller/InvoicesController.php`
- `src/Controller/AppController.php`
- `config/routes.php`
- `templates/Invoices/edit.php`
- `templates/Invoices/view.php`
- `templates/element/pipeline_progress.php`
- `templates/layout/default.php`
