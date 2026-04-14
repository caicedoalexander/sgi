# Payment Authorization Expansion — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add `aut_pago` state to liquidation docs, petty cash, and legalizations pipelines; create payment tables and services for each; add unified payment registry view in sidebar.

**Architecture:** Three new payment tables (one per module) with identical structure to `invoice_payments` minus `payment_scheduling_id`. Three new payment services following `InvoicePaymentService` pattern but simplified (no partial payments). A `PaymentRegistryService` unifies all 4 payment tables for a read-only index. Constants, pipeline services, controllers, and templates updated for the new `aut_pago` state.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Phinx migrations via `Migrations\BaseMigration`

**Design doc:** `docs/plans/2026-04-14-payment-authorization-expansion-design.md`

---

### Task 1: Migration — Create 3 payment tables

**Files:**
- Create: `config/Migrations/20260414000001_CreateLiquidationDocPayments.php`
- Create: `config/Migrations/20260414000002_CreatePettyCashPayments.php`
- Create: `config/Migrations/20260414000003_CreateLegalizationPayments.php`

**Step 1: Create `liquidation_doc_payments` migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLiquidationDocPayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('liquidation_doc_payments')) {
            $this->table('liquidation_doc_payments')
                ->addColumn('liquidation_doc_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['liquidation_doc_id'])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', [
                    'delete' => 'CASCADE', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('authorized_by', 'users', 'id', [
                    'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('liquidation_doc_payments')) {
            $this->table('liquidation_doc_payments')->drop()->save();
        }
    }
}
```

**Step 2: Create `petty_cash_payments` migration**

Same structure but FK is `petty_cash_record_id` → `petty_cash_records`.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePettyCashPayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')
                ->addColumn('petty_cash_record_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['petty_cash_record_id'])
                ->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
                    'delete' => 'CASCADE', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('authorized_by', 'users', 'id', [
                    'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')->drop()->save();
        }
    }
}
```

**Step 3: Create `legalization_payments` migration**

Same structure but FK is `legalization_record_id` → `legalization_records`.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationPayments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_payments')) {
            $this->table('legalization_payments')
                ->addColumn('legalization_record_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['legalization_record_id'])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', [
                    'delete' => 'CASCADE', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('authorized_by', 'users', 'id', [
                    'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
                ])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT', 'update' => 'NO_ACTION',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_payments')) {
            $this->table('legalization_payments')->drop()->save();
        }
    }
}
```

**Step 4: Run migrations**

```bash
php bin/cake migrations migrate
```

Expected: 3 tables created successfully.

**Step 5: Commit**

```bash
git add config/Migrations/20260414000001_CreateLiquidationDocPayments.php config/Migrations/20260414000002_CreatePettyCashPayments.php config/Migrations/20260414000003_CreateLegalizationPayments.php
git commit -m "feat: create payment tables for liquidation docs, petty cash, legalizations"
```

---

### Task 2: Models — Entity + Table for 3 payment tables

**Files:**
- Create: `src/Model/Entity/LiquidationDocPayment.php`
- Create: `src/Model/Table/LiquidationDocPaymentsTable.php`
- Create: `src/Model/Entity/PettyCashPayment.php`
- Create: `src/Model/Table/PettyCashPaymentsTable.php`
- Create: `src/Model/Entity/LegalizationPayment.php`
- Create: `src/Model/Table/LegalizationPaymentsTable.php`
- Modify: `src/Model/Table/NoveltyLiquidationDocsTable.php` — add hasMany LiquidationDocPayments
- Modify: `src/Model/Table/PettyCashRecordsTable.php` — add hasMany PettyCashPayments
- Modify: `src/Model/Table/LegalizationRecordsTable.php` — add hasMany LegalizationPayments

**Step 1: Create entities**

All three entities follow the same pattern as `src/Model/Entity/InvoicePayment.php`. Accessible fields: parent FK, `banking_entity_id`, `amount`, `payment_date`, `authorized`, `authorized_by`, `authorized_date`, `created_by`.

**LiquidationDocPayment.php:**
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LiquidationDocPayment extends Entity
{
    protected array $_accessible = [
        'liquidation_doc_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'authorized' => true,
        'authorized_by' => true,
        'authorized_date' => true,
        'created_by' => true,
    ];
}
```

**PettyCashPayment.php:**
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PettyCashPayment extends Entity
{
    protected array $_accessible = [
        'petty_cash_record_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'authorized' => true,
        'authorized_by' => true,
        'authorized_date' => true,
        'created_by' => true,
    ];
}
```

**LegalizationPayment.php:**
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LegalizationPayment extends Entity
{
    protected array $_accessible = [
        'legalization_record_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
        'payment_date' => true,
        'authorized' => true,
        'authorized_by' => true,
        'authorized_date' => true,
        'created_by' => true,
    ];
}
```

**Step 2: Create Table classes**

All three follow `InvoicePaymentsTable` pattern. Associations: belongsTo parent, BankingEntities, CreatedByUsers, AuthorizedByUsers. Validation: require parent FK, banking_entity_id, amount (decimal), payment_date (date), created_by (integer).

**LiquidationDocPaymentsTable.php:**
```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LiquidationDocPaymentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('liquidation_doc_payments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
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
        $this->belongsTo('AuthorizedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'authorized_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('liquidation_doc_id')->requirePresence('liquidation_doc_id', 'create')->notEmptyString('liquidation_doc_id');
        $validator
            ->integer('banking_entity_id')->requirePresence('banking_entity_id', 'create')->notEmptyString('banking_entity_id');
        $validator
            ->decimal('amount')->requirePresence('amount', 'create')->notEmptyString('amount');
        $validator
            ->date('payment_date')->requirePresence('payment_date', 'create')->notEmptyDate('payment_date');
        $validator
            ->integer('created_by')->requirePresence('created_by', 'create')->notEmptyString('created_by');
        $validator
            ->boolean('authorized');
        $validator
            ->integer('authorized_by')->allowEmptyString('authorized_by');
        $validator
            ->date('authorized_date')->allowEmptyDate('authorized_date');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), ['errorField' => 'liquidation_doc_id']);
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

**PettyCashPaymentsTable.php** — same pattern, FK `petty_cash_record_id` → `PettyCashRecords`.

**LegalizationPaymentsTable.php** — same pattern, FK `legalization_record_id` → `LegalizationRecords`.

**Step 3: Add hasMany to parent Table classes**

In `NoveltyLiquidationDocsTable::initialize()` add:
```php
$this->hasMany('LiquidationDocPayments', [
    'foreignKey' => 'liquidation_doc_id',
    'dependent' => true,
]);
```

In `PettyCashRecordsTable::initialize()` add:
```php
$this->hasMany('PettyCashPayments', [
    'foreignKey' => 'petty_cash_record_id',
    'dependent' => true,
]);
```

In `LegalizationRecordsTable::initialize()` add:
```php
$this->hasMany('LegalizationPayments', [
    'foreignKey' => 'legalization_record_id',
    'dependent' => true,
]);
```

**Step 4: Commit**

```bash
git add src/Model/Entity/LiquidationDocPayment.php src/Model/Entity/PettyCashPayment.php src/Model/Entity/LegalizationPayment.php src/Model/Table/LiquidationDocPaymentsTable.php src/Model/Table/PettyCashPaymentsTable.php src/Model/Table/LegalizationPaymentsTable.php src/Model/Table/NoveltyLiquidationDocsTable.php src/Model/Table/PettyCashRecordsTable.php src/Model/Table/LegalizationRecordsTable.php
git commit -m "feat: add entity/table models for 3 new payment tables"
```

---

### Task 3: Update Constants — Add `aut_pago` to all 3 modules

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`
- Modify: `src/Constants/PettyCashConstants.php`
- Modify: `src/Constants/LegalizationConstants.php`

**Step 1: Update NoveltyConstants**

Add `STATUS_AUT_PAGO = 'aut_pago'` constant. Insert into `PIPELINE_STATUSES` between `tesoreria` and `pagada`. Add to `STATUS_LABELS` (`'aut_pago' => 'Aut. Pago'`), `STATUS_ICONS` (`'aut_pago' => 'bi-shield-check'`), `ALL_STATUSES`. Update `TRANSITIONS`: `tesoreria → aut_pago`, add `aut_pago → pagada`.

Changes to `PIPELINE_STATUSES`:
```php
public const PIPELINE_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_RRHH,
    self::STATUS_CONTABILIDAD,
    self::STATUS_REVISION_FIRMAS,
    self::STATUS_GDP,
    self::STATUS_TESORERIA,
    self::STATUS_AUT_PAGO,
    self::STATUS_PAGADA,
];
```

Changes to `TRANSITIONS`:
```php
public const TRANSITIONS = [
    self::STATUS_APROBACION => self::STATUS_RRHH,
    self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
    self::STATUS_CONTABILIDAD => self::STATUS_REVISION_FIRMAS,
    self::STATUS_REVISION_FIRMAS => self::STATUS_GDP,
    self::STATUS_GDP => self::STATUS_TESORERIA,
    self::STATUS_TESORERIA => self::STATUS_AUT_PAGO,
    self::STATUS_AUT_PAGO => self::STATUS_PAGADA,
    self::STATUS_PAGADA => null,
];
```

**Step 2: Update PettyCashConstants**

Add `STATUS_AUT_PAGO = 'aut_pago'`. Insert into `STATUSES` between `tesoreria` and `pagado`. Add to `STATUS_LABELS`, `STATUS_ICONS`, `TRANSITIONS`.

```php
public const STATUSES = [
    self::STATUS_AGRUPACION,
    self::STATUS_CONTABILIDAD,
    self::STATUS_TESORERIA,
    self::STATUS_AUT_PAGO,
    self::STATUS_PAGADO,
];

public const TRANSITIONS = [
    'agrupacion' => 'contabilidad',
    'contabilidad' => 'tesoreria',
    'tesoreria' => 'aut_pago',
    'aut_pago' => 'pagado',
    'pagado' => null,
];
```

**Step 3: Update LegalizationConstants** — identical changes as PettyCashConstants.

**Step 4: Commit**

```bash
git add src/Constants/NoveltyConstants.php src/Constants/PettyCashConstants.php src/Constants/LegalizationConstants.php
git commit -m "feat: add aut_pago status to novelty, petty cash, legalization constants"
```

---

### Task 4: Payment Services — LiquidationDocPaymentService

**Files:**
- Create: `src/Service/LiquidationDocPaymentService.php`

**Step 1: Create the service**

Pattern follows `InvoicePaymentService` but simplified — no partial payment logic. Methods:

- `registerPayment(int $docId, array $paymentData, int $createdBy): ServiceResult` — Creates payment record, advances doc + all child novelties to `aut_pago`. Validates amount matches doc total.
- `authorizePayment(int $paymentId, int $authorizedBy): array` — Sets `authorized=true`, advances doc + novelties to `pagada`.
- `rejectPayment(int $paymentId, int $rejectedBy): ServiceResult` — Deletes payment, returns doc + novelties to `tesoreria`.

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;

class LiquidationDocPaymentService
{
    public function registerPayment(int $docId, array $paymentData, int $createdBy): ServiceResult
    {
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $doc = $docsTable->get($docId);

        if ($doc->pipeline_status !== NoveltyConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        // Check no existing pending payment
        $existing = $paymentsTable->find()
            ->where(['liquidation_doc_id' => $docId, 'authorized' => false])
            ->first();
        if ($existing) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $payment = $paymentsTable->newEntity([
            'liquidation_doc_id' => $docId,
            'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'payment_date' => $paymentData['payment_date'] ?? null,
            'created_by' => $createdBy,
        ]);

        if (!$paymentsTable->save($payment)) {
            $errors = [];
            foreach ($payment->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
        }

        // Advance doc and novelties to aut_pago
        $doc->pipeline_status = NoveltyConstants::STATUS_AUT_PAGO;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_AUT_PAGO],
            ['liquidation_doc_id' => $docId],
        );

        return ServiceResult::ok('Pago registrado. Documento avanzado a Autorización de Pago.');
    }

    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false];
        }

        $doc = $docsTable->get($payment->liquidation_doc_id);
        $doc->pipeline_status = NoveltyConstants::STATUS_PAGADA;
        $doc->payment_status = NoveltyConstants::PAYMENT_PAGADO;
        $doc->payment_date = $payment->payment_date;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_PAGADA],
            ['liquidation_doc_id' => $payment->liquidation_doc_id],
        );

        return ['success' => true, 'newPipelineStatus' => NoveltyConstants::STATUS_PAGADA];
    }

    public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('LiquidationDocPayments');
        $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $payment = $paymentsTable->get($paymentId);

        if ($payment->authorized) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $docId = $payment->liquidation_doc_id;

        if (!$paymentsTable->delete($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $doc = $docsTable->get($docId);
        $doc->pipeline_status = NoveltyConstants::STATUS_TESORERIA;
        $docsTable->save($doc);

        $noveltiesTable->updateAll(
            ['pipeline_status' => NoveltyConstants::STATUS_TESORERIA],
            ['liquidation_doc_id' => $docId],
        );

        return ServiceResult::ok('Pago rechazado. Documento devuelto a Tesorería.');
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/LiquidationDocPaymentService.php
git commit -m "feat: add LiquidationDocPaymentService"
```

---

### Task 5: Payment Services — PettyCashPaymentService + LegalizationPaymentService

**Files:**
- Create: `src/Service/PettyCashPaymentService.php`
- Create: `src/Service/LegalizationPaymentService.php`

**Step 1: Create PettyCashPaymentService**

Same pattern as `LiquidationDocPaymentService` but operates on `petty_cash_payments` / `petty_cash_records`. Key differences:
- FK field: `petty_cash_record_id`
- Status constants: `PettyCashConstants::STATUS_TESORERIA`, `STATUS_AUT_PAGO`, `STATUS_PAGADO`
- On authorize: also updates child invoices to `InvoiceConstants::STATUS_PAGADA` with `payment_status = PAYMENT_FULL` and `payment_date` (same logic currently in `PettyCashService::advanceStatus()` for the `pagado` transition)
- On reject: returns record to `tesoreria`, does NOT touch child invoice statuses (they stay in `tesoreria`)

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;

class PettyCashPaymentService
{
    public function registerPayment(int $recordId, array $paymentData, int $createdBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        $existing = $paymentsTable->find()
            ->where(['petty_cash_record_id' => $recordId, 'authorized' => false])
            ->first();
        if ($existing) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $payment = $paymentsTable->newEntity([
            'petty_cash_record_id' => $recordId,
            'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'payment_date' => $paymentData['payment_date'] ?? null,
            'created_by' => $createdBy,
        ]);

        if (!$paymentsTable->save($payment)) {
            $errors = [];
            foreach ($payment->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
        }

        $record->status = PettyCashConstants::STATUS_AUT_PAGO;
        $recordsTable->save($record);

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }

    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false];
        }

        $record = $recordsTable->get($payment->petty_cash_record_id);
        $record->status = PettyCashConstants::STATUS_PAGADO;
        $record->payment_status = InvoiceConstants::PAYMENT_FULL;
        $record->payment_date = $payment->payment_date;
        $recordsTable->save($record);

        // Update child invoices to pagada
        $invoicesTable->updateAll(
            [
                'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
                'payment_status' => InvoiceConstants::PAYMENT_FULL,
                'payment_date' => $payment->payment_date,
            ],
            ['petty_cash_record_id' => $record->id],
        );

        return ['success' => true, 'newPipelineStatus' => PettyCashConstants::STATUS_PAGADO];
    }

    public function rejectPayment(int $paymentId, int $rejectedBy): ServiceResult
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');

        $payment = $paymentsTable->get($paymentId);

        if ($payment->authorized) {
            return ServiceResult::fail('No se puede rechazar un pago ya autorizado.');
        }

        $recordId = $payment->petty_cash_record_id;

        if (!$paymentsTable->delete($payment)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        $record = $recordsTable->get($recordId);
        $record->status = PettyCashConstants::STATUS_TESORERIA;
        $recordsTable->save($record);

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }
}
```

**Step 2: Create LegalizationPaymentService** — same pattern as PettyCashPaymentService but FK `legalization_record_id`, table `LegalizationRecords`, constants `LegalizationConstants`, child invoices FK `legalization_record_id`.

**Step 3: Commit**

```bash
git add src/Service/PettyCashPaymentService.php src/Service/LegalizationPaymentService.php
git commit -m "feat: add PettyCashPaymentService and LegalizationPaymentService"
```

---

### Task 6: Update Pipeline Services

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php`
- Modify: `src/Service/PettyCashService.php`
- Modify: `src/Service/LegalizationService.php`

**Step 1: Update NoveltyPipelineService**

1. In `ROLE_VISIBLE_STATUSES`: add `NoveltyConstants::STATUS_AUT_PAGO` to Contador's visible list and Tesoreria's visible list.
2. In `SECTIONS_BY_STATUS`: add entry for `aut_pago` (same sections as `tesoreria`).
3. In `validateGroupTransition()`: remove `STATUS_TESORERIA` case (payment registration replaces it). Add `STATUS_AUT_PAGO` case — no validation needed (authorize/reject handled by payment service).
4. In `advanceGroup()`: block advancing from `tesoreria` (must use payment registration instead). Block advancing from `aut_pago` (must use authorize/reject).

Changes to `ROLE_VISIBLE_STATUSES`:
```php
RoleConstants::CONTADOR => [
    NoveltyConstants::STATUS_REVISION_FIRMAS,
    NoveltyConstants::STATUS_AUT_PAGO,
    NoveltyConstants::STATUS_PAGADA,
],
RoleConstants::TESORERIA => [
    NoveltyConstants::STATUS_TESORERIA,
    NoveltyConstants::STATUS_AUT_PAGO,
    NoveltyConstants::STATUS_PAGADA,
],
```

Changes to `VISIBLE_SECTIONS_BY_ROLE` — add `aut_pago` visibility for Contador:
```php
RoleConstants::CONTADOR => ['informacion', 'fechas', 'firmas'],
```
(Contador already has this — no change needed since sections are role-based, not status-based for non-Admin.)

Changes to `SECTIONS_BY_STATUS` — add:
```php
NoveltyConstants::STATUS_AUT_PAGO => [
    'informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'contabilidad', 'firmas',
],
```

In `validateGroupTransition()` — update `STATUS_TESORERIA` case: remove payment_status/payment_date validation (now handled by payment service registration form). The tesoreria → aut_pago transition happens via registerPayment, not advanceGroup.

In `advanceGroup()` — add guard: if `$liquidationDoc->pipeline_status === NoveltyConstants::STATUS_TESORERIA`, return error "Debe registrar un pago para avanzar desde Tesorería." Same for `STATUS_AUT_PAGO`: "La autorización de pago se gestiona desde la sección de pagos."

**Step 2: Update PettyCashService**

In `advanceStatus()`: when `$nextStatus === PettyCashConstants::STATUS_AUT_PAGO`, block: "Debe registrar un pago para avanzar desde Tesorería." When `$currentStatus === PettyCashConstants::STATUS_AUT_PAGO`, block: "La autorización de pago se gestiona desde la sección de pagos."

Remove the `$nextStatus === PettyCashConstants::STATUS_PAGADO` branch from `advanceStatus()` that sets invoice pipeline_status/payment_status/payment_date — this is now handled by `PettyCashPaymentService::authorizePayment()`.

In `_validateTransition()`: remove `STATUS_TESORERIA` case (no longer validates payment fields here — payment service handles it).

**Step 3: Update LegalizationService** — identical changes as PettyCashService.

**Step 4: Commit**

```bash
git add src/Service/NoveltyPipelineService.php src/Service/PettyCashService.php src/Service/LegalizationService.php
git commit -m "feat: update pipeline services to block generic advance for aut_pago transitions"
```

---

### Task 7: Payment Controllers for 3 modules

**Files:**
- Create: `src/Controller/LiquidationDocPaymentsController.php`
- Create: `src/Controller/PettyCashPaymentsController.php`
- Create: `src/Controller/LegalizationPaymentsController.php`

**Step 1: Create LiquidationDocPaymentsController**

Follow `InvoicePaymentsController` pattern. Actions: `addPayment($docId)`, `authorizePayment($docId, $paymentId)`, `rejectPayment($docId, $paymentId)`. Permissions: addPayment requires Tesorería, authorize/reject requires Contador. Redirects back to `NoveltyLiquidationDocs::edit`.

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\RoleConstants;
use App\Service\LiquidationDocPaymentService;

class LiquidationDocPaymentsController extends AppController
{
    private LiquidationDocPaymentService $paymentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->paymentService = new LiquidationDocPaymentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    public function addPayment($docId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::TESORERIA && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('No tiene permisos para registrar pagos.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->registerPayment(
            (int)$docId,
            $this->request->getData(),
            (int)$this->_getCurrentUser()->id,
        );

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->data);
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }

    public function authorizePayment($docId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede autorizar pagos.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->authorizePayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result['success']) {
            $this->Flash->success('Pago autorizado. Documento marcado como Pagado.');
        } else {
            $this->Flash->error('No se pudo autorizar el pago.');
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }

    public function rejectPayment($docId = null, $paymentId = null)
    {
        $this->request->allowMethod(['post']);
        $roleName = $this->_getRoleName();

        if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
            $this->Flash->error('Solo el Contador puede rechazar pagos.');

            return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
        }

        $result = $this->paymentService->rejectPayment((int)$paymentId, (int)$this->_getCurrentUser()->id);

        if ($result->success) {
            $this->Flash->success($result->data);
        } else {
            $this->Flash->error($result->data);
        }

        return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', $docId]);
    }
}
```

**Step 2: Create PettyCashPaymentsController** — same pattern, redirects to `PettyCashRecords::edit`.

**Step 3: Create LegalizationPaymentsController** — same pattern, redirects to `LegalizationRecords::edit`.

**Step 4: Register in AppController**

In `$controllerModuleMap` add:
```php
'LiquidationDocPayments' => 'novelty_liquidation_docs',
'PettyCashPayments' => 'petty_cash',
'LegalizationPayments' => 'legalizations',
```

In `_actionToPermission()` — `addPayment` maps to `'add'`, `authorizePayment` and `rejectPayment` map to `'edit'` (already listed).

**Step 5: Commit**

```bash
git add src/Controller/LiquidationDocPaymentsController.php src/Controller/PettyCashPaymentsController.php src/Controller/LegalizationPaymentsController.php src/Controller/AppController.php
git commit -m "feat: add payment controllers for liquidation docs, petty cash, legalizations"
```

---

### Task 8: Update Edit Templates — Liquidation Docs

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` — pass `bankingEntities` + payments to view

**Step 1: Update controller `edit()` action**

Add to the `contain` array: `'LiquidationDocPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers']`.

Pass `$bankingEntities` to view:
```php
$bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();
```

Also set `$roleName` and pass a flag for whether current user is Contador in aut_pago:
```php
$roleName = $this->_getUserRoleName($user);
$isContadorAutPago = ($roleName === RoleConstants::CONTADOR || $roleName === RoleConstants::ADMIN)
    && $doc->pipeline_status === NoveltyConstants::STATUS_AUT_PAGO;
$isTesoreriaEdit = ($roleName === RoleConstants::TESORERIA || $roleName === RoleConstants::ADMIN)
    && $doc->pipeline_status === NoveltyConstants::STATUS_TESORERIA;
```

**Step 2: Update edit template**

Replace the `STATUS_TESORERIA` sticky action form (lines ~308-329) with a payment registration form similar to invoices edit:
- Show "Registrar Pago" collapsible form with banking entity (select2), amount (currency-input), payment date (flatpickr-date)
- POST to `LiquidationDocPayments::addPayment`
- Show payments table with authorize/reject buttons for Contador when in `aut_pago`

Replace the `STATUS_TESORERIA` block in sticky actions with the payment form (only if `$isTesoreriaEdit`).

Add a payments section visible in `tesoreria`, `aut_pago`, and `pagada` states showing the payments table.

For `aut_pago` — no generic advance button. Instead show payments table with authorize/reject buttons.

**Step 3: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat: add payment registration UI to liquidation docs edit"
```

---

### Task 9: Update Edit Templates — Petty Cash

**Files:**
- Modify: `templates/PettyCashRecords/edit.php`
- Modify: `src/Controller/PettyCashRecordsController.php`

**Step 1: Update controller `edit()` action**

Add to contain: `'PettyCashPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers']`.
Pass `$bankingEntities`, `$isContadorAutPago`, `$isTesoreriaEdit` flags.

**Step 2: Update edit template**

Replace the treasury section's payment_status/payment_date fields with:
- In `tesoreria`: payment registration form (banking entity, amount, date) that POSTs to `PettyCashPayments::addPayment`
- In `aut_pago`: payments table with authorize/reject buttons for Contador
- In `pagado`: read-only payments table

The existing advance button in tesoreria should be removed (advance happens via payment registration).

**Step 3: Commit**

```bash
git add templates/PettyCashRecords/edit.php src/Controller/PettyCashRecordsController.php
git commit -m "feat: add payment registration UI to petty cash edit"
```

---

### Task 10: Update Edit Templates — Legalizations

**Files:**
- Modify: `templates/LegalizationRecords/edit.php`
- Modify: `src/Controller/LegalizationRecordsController.php`

Same changes as Task 9 but for legalizations. FK `legalization_record_id`, controller `LegalizationPayments`.

**Step 1: Update controller + template** (same pattern as Task 9)

**Step 2: Commit**

```bash
git add templates/LegalizationRecords/edit.php src/Controller/LegalizationRecordsController.php
git commit -m "feat: add payment registration UI to legalizations edit"
```

---

### Task 11: Update View Templates

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/view.php` — add payments section (read-only)
- Modify: `src/Controller/NoveltyLiquidationDocsController.php` (`view()` action) — contain payments

**Step 1:** In controller `view()`, add `'LiquidationDocPayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers']` to contain.

**Step 2:** In view template, add a read-only payments table section showing banking entity, amount, date, authorized status.

**Step 3: Commit**

```bash
git add templates/NoveltyLiquidationDocs/view.php src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat: show payments in liquidation doc view"
```

---

### Task 12: PaymentRegistryService + Controller + Template

**Files:**
- Create: `src/Service/PaymentRegistryService.php`
- Create: `src/Controller/PaymentRegistryController.php`
- Create: `templates/PaymentRegistry/index.php`

**Step 1: Create PaymentRegistryService**

Queries all 4 payment tables, normalizes results into a common format, returns combined array. Supports filters (type, authorized, date range, banking entity).

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class PaymentRegistryService
{
    public function getAll(array $filters = []): array
    {
        $results = [];

        $results = array_merge($results, $this->_queryInvoicePayments($filters));
        $results = array_merge($results, $this->_queryLiquidationDocPayments($filters));
        $results = array_merge($results, $this->_queryPettyCashPayments($filters));
        $results = array_merge($results, $this->_queryLegalizationPayments($filters));

        // Sort by created DESC
        usort($results, fn($a, $b) => strtotime($b['created']) - strtotime($a['created']));

        return $results;
    }

    private function _queryInvoicePayments(array $filters): array
    {
        if (!empty($filters['type']) && $filters['type'] !== 'invoice') {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('InvoicePayments');
        $query = $table->find()
            ->contain(['Invoices', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'])
            ->order(['InvoicePayments.created' => 'DESC']);

        $this->_applyCommonFilters($query, $filters, 'InvoicePayments');

        return array_map(fn($p) => [
            'type' => 'invoice',
            'type_label' => 'Factura',
            'reference' => $p->invoice->invoice_number ?? "FAC-{$p->invoice_id}",
            'banking_entity' => $p->banking_entity->name ?? '—',
            'amount' => (float)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d'),
            'authorized' => (bool)$p->authorized,
            'authorized_by' => $p->authorized_by_user->full_name ?? $p->authorized_by_user->username ?? null,
            'authorized_date' => $p->authorized_date?->format('Y-m-d'),
            'created_by' => $p->created_by_user->full_name ?? $p->created_by_user->username ?? '—',
            'created' => $p->created?->format('Y-m-d H:i:s') ?? '',
        ], $query->all()->toArray());
    }

    // _queryLiquidationDocPayments, _queryPettyCashPayments, _queryLegalizationPayments
    // follow the same pattern with their respective tables and references.

    private function _applyCommonFilters($query, array $filters, string $alias): void
    {
        if (isset($filters['authorized'])) {
            $query->where(["$alias.authorized" => $filters['authorized'] === 'yes']);
        }
        if (!empty($filters['banking_entity_id'])) {
            $query->where(["$alias.banking_entity_id" => $filters['banking_entity_id']]);
        }
        if (!empty($filters['date_from'])) {
            $query->where(["$alias.payment_date >=" => $filters['date_from']]);
        }
        if (!empty($filters['date_to'])) {
            $query->where(["$alias.payment_date <=" => $filters['date_to']]);
        }
    }
}
```

Complete the 3 remaining private methods following `_queryInvoicePayments` pattern.

**Step 2: Create PaymentRegistryController**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PaymentRegistryService;

class PaymentRegistryController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentRegistryService $registryService;

    public function initialize(): void
    {
        parent::initialize();
        $this->registryService = new PaymentRegistryService();
    }

    public function index()
    {
        $filters = [
            'type' => $this->request->getQuery('type'),
            'authorized' => $this->request->getQuery('authorized'),
            'banking_entity_id' => $this->request->getQuery('banking_entity_id'),
            'date_from' => $this->request->getQuery('date_from'),
            'date_to' => $this->request->getQuery('date_to'),
        ];

        $allPayments = $this->registryService->getAll($filters);

        // Manual pagination
        $page = (int)($this->request->getQuery('page') ?? 1);
        $limit = 15;
        $total = count($allPayments);
        $payments = array_slice($allPayments, ($page - 1) * $limit, $limit);

        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();

        $this->set(compact('payments', 'filters', 'bankingEntities', 'page', 'limit', 'total'));
    }
}
```

**Step 3: Create template**

`templates/PaymentRegistry/index.php` — standard SGI index page with filters row and table. Columns: Tipo, Referencia, Entidad Bancaria, Monto, Fecha Pago, Estado (Autorizado/Pendiente badge), Autorizado por, Registrado por, Fecha registro. Manual pagination links.

Follow the same design patterns from existing index templates (border-based, Inter font, no shadows).

**Step 4: Commit**

```bash
git add src/Service/PaymentRegistryService.php src/Controller/PaymentRegistryController.php templates/PaymentRegistry/index.php
git commit -m "feat: add unified payment registry with service, controller, and template"
```

---

### Task 13: Permissions + Sidebar + AppController

**Files:**
- Create: `config/Migrations/20260414000004_AddPaymentRegistryPermissions.php`
- Modify: `src/Controller/AppController.php` — add to `$controllerModuleMap`
- Modify: `src/Service/AuthorizationService.php` — add to `MODULES`
- Modify: `templates/layout/default.php` — add sidebar item

**Step 1: Create migration**

Seeds `payment_registry` module into permissions table for Admin (role_id=1), Tesorería (role_id=3), and Contador (role_id=7, look up actual ID) with `can_view=true`.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentRegistryPermissions extends BaseMigration
{
    public function up(): void
    {
        // Find Contador role ID
        $rows = $this->fetchAll("SELECT id FROM roles WHERE name = 'Contador'");
        $contadorId = $rows[0]['id'] ?? null;

        $permissions = [
            ['role_id' => 1, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
            ['role_id' => 3, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false],
        ];

        if ($contadorId) {
            $permissions[] = ['role_id' => $contadorId, 'module' => 'payment_registry', 'can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
        }

        $table = $this->table('permissions');
        foreach ($permissions as $perm) {
            $table->insert($perm);
        }
        $table->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'payment_registry'");
    }
}
```

**Step 2: Update AppController `$controllerModuleMap`**

Add:
```php
'PaymentRegistry' => 'payment_registry',
```

**Step 3: Update AuthorizationService `MODULES`**

Add:
```php
'payment_registry' => 'Registro de Pagos',
```

**Step 4: Update sidebar**

In `templates/layout/default.php`, add a new sidebar item under the Facturación submenu (after Programación, before closing `</ul>`):

```php
<?php if ($canView('payment_registry')): ?>
<li class="nav-item">
    <?= $this->Html->link(
        '<i class="bi bi-cash-stack me-2"></i>Registro de Pagos',
        ['controller' => 'PaymentRegistry', 'action' => 'index'],
        ['class' => $navLink('PaymentRegistry') . ' d-flex align-items-center', 'escape' => false]
    ) ?>
</li>
<?php endif; ?>
```

**Step 5: Run migration + commit**

```bash
php bin/cake migrations migrate
git add config/Migrations/20260414000004_AddPaymentRegistryPermissions.php src/Controller/AppController.php src/Service/AuthorizationService.php templates/layout/default.php
git commit -m "feat: add payment registry permissions, sidebar item, and module registration"
```

---

### Task 14: Pipeline Progress Elements

**Files:**
- Modify: `templates/element/petty_cash_progress.php` — already reads from `PettyCashConstants::STATUSES` which will include `aut_pago` after Task 3. **No changes needed** — the element auto-renders all statuses.
- Modify: `templates/element/legalization_progress.php` — same, **no changes needed**.
- The liquidation docs use the generic `pipeline_progress.php` element with `$effectiveStatuses` from `getEffectiveStatuses()` which reads from `PIPELINE_STATUSES`. **No changes needed** after Task 3.

**Verify:** After Task 3, check that all 3 progress elements render the new `aut_pago` step correctly by starting the dev server and viewing a record in each module.

**Step 1: Commit** (if any minor fixes needed)

---

### Task 15: Update SidebarCounterService (if needed)

**Files:**
- Check: `src/Service/SidebarCounterService.php`

**Step 1:** Review if `SidebarCounterService` counts pending items per status. If it does, add counters for `aut_pago` status for liquidation docs. The sidebar for liquidation docs already shows per-status counters (contabilidad, tesoreria, revision_firmas, gdp) — add `aut_pago` counter.

**Step 2:** Update sidebar in `default.php` to show `aut_pago` counter for liquidation docs.

**Step 3: Commit**

```bash
git add src/Service/SidebarCounterService.php templates/layout/default.php
git commit -m "feat: add aut_pago counters to sidebar"
```

---

### Task 16: Final Verification

**Step 1:** Start dev server: `php bin/cake server`

**Step 2:** Test each module end-to-end:
- Liquidation doc: advance through pipeline to tesoreria → register payment → verify aut_pago → authorize as Contador → verify pagada
- Petty cash: same flow
- Legalization: same flow
- Test rejection flow: register payment → reject as Contador → verify returns to tesoreria
- Verify payment registry index shows all payments with filters

**Step 3:** Run code style check: `composer cs-check`

**Step 4:** Fix any style issues: `composer cs-fix`

**Step 5: Final commit if any fixes**

```bash
git add -A
git commit -m "fix: code style corrections"
```
