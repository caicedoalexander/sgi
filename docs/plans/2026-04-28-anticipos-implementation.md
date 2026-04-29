# Anticipos Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `Anticipos` module covering Phase 1 (registering and paying advances by reusing the Invoice pipeline) and Phase 2 (a separate legalization pipeline that closes the advance with one of three cases: exact, shortage, or surplus).

**Architecture:** The Anticipo IS an `Invoice` row with `document_type='Anticipo'` (auto-approved, normal 5-state pipeline). When that invoice reaches `pagada`, the system auto-creates a row in a new `advance_legalizations` table that drives Phase 2 (`validacion → revision_firmas → contabilidad → [tesoreria] → legalizada`). Legalization-Invoices are `Invoice` rows with `document_type='Legalización'` and a self-FK `advance_id` pointing to the Anticipo Invoice; they run a truncated pipeline (no tesorería/autorización because the beneficiary already paid). Surplus refunds reuse `InvoicePayment` with a new `is_refund` flag, looping through the Anticipo's standard authorization flow.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Bootstrap 5, Flatpickr, AutoNumeric, Select2, PHPUnit 11+. Reference: `docs/plans/2026-04-28-anticipos-design.md`.

**Conventions to follow** (from `CLAUDE.md`, verified during exploration):
- Services return `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`. Check `->success` before reading `->data`.
- Services get tables via `TableRegistry::getTableLocator()->get('TableName')` — never via `$this->TableName`.
- Constructor DI with nullable params and `?? new ServiceClass()` fallback.
- Migrations extend `Migrations\BaseMigration` (not `AbstractMigration`) and wrap create/drop in `$this->hasTable()`.
- Custom routes go BEFORE `$builder->fallbacks()` in `config/routes.php`.
- Pagination: 15 items per page.
- Private methods prefixed with `_`.
- Spanish-language UI throughout.
- Run `composer cs-fix` after each task that touches PHP code, then `composer cs-check` to verify.

---

## Phase 0 — Preflight

### Task 0.1: Verify baseline state

**Files:**
- Read: `src/Constants/InvoiceConstants.php`
- Read: `src/Service/AuthorizationService.php`
- Read: `src/Controller/AppController.php`

- [ ] **Step 1: Confirm `'Anticipo'` and `'Legalización'` already exist in `InvoiceConstants::DOCUMENT_TYPES`**

```bash
grep -n "DOCTYPE_ANTICIPO\|DOCTYPE_LEGALIZACION" src/Constants/InvoiceConstants.php
```
Expected: Both constants present and listed in `DOCUMENT_TYPES` array. If absent, add them — but the current code already has them, so no action expected.

- [ ] **Step 2: Confirm baseline test suite passes**

```bash
composer test
```
Expected: All tests green (only `ExcelMappingServiceTest` exists; should pass).

- [ ] **Step 3: Confirm cs-check is clean**

```bash
composer cs-check
```
Expected: No style errors. Fix anything that fails before starting.

---

## Phase 1 — Schema, Constants, ORM (PR 1)

This phase ships migrations + new entities/tables/constants with **no behavior**. After this PR the DB schema is ready, but no UI or pipeline branching exists yet.

### Task 1.1: Create `AdvanceConstants` class

**Files:**
- Create: `src/Constants/AdvanceConstants.php`

- [ ] **Step 1: Write the constants class**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class AdvanceConstants
{
    // Phase 2 pipeline statuses (advance_legalizations.status)
    public const STATUS_VALIDACION = 'validacion';
    public const STATUS_REVISION_FIRMAS = 'revision_firmas';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_LEGALIZADA = 'legalizada';

    public const PIPELINE_STATUSES = [
        self::STATUS_VALIDACION,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_VALIDACION       => 'Validación',
        self::STATUS_REVISION_FIRMAS  => 'Revisión y Firmas',
        self::STATUS_CONTABILIDAD     => 'Contabilidad',
        self::STATUS_TESORERIA        => 'Tesorería',
        self::STATUS_LEGALIZADA       => 'Legalizada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_VALIDACION       => 'bi-clipboard-check',
        self::STATUS_REVISION_FIRMAS  => 'bi-pen',
        self::STATUS_CONTABILIDAD     => 'bi-calculator',
        self::STATUS_TESORERIA        => 'bi-bank',
        self::STATUS_LEGALIZADA       => 'bi-cash-coin',
    ];

    // Case types resolved by Contabilidad
    public const CASE_EXACTO = 'exacto';
    public const CASE_FALTANTE = 'faltante';
    public const CASE_SOBRANTE = 'sobrante';
    public const CASE_TYPES = [self::CASE_EXACTO, self::CASE_FALTANTE, self::CASE_SOBRANTE];

    // Signature lifecycle (advance_legalization_signatures.signature_status)
    public const SIGNATURE_PENDING = 'pending';
    public const SIGNATURE_SIGNED = 'signed';
    public const SIGNATURE_REJECTED = 'rejected';

    public const SIGNATURE_STATUSES = [
        self::SIGNATURE_PENDING,
        self::SIGNATURE_SIGNED,
        self::SIGNATURE_REJECTED,
    ];

    // Permission module slug (matches AuthorizationService::MODULES key)
    public const MODULE = 'advances';
}
```

- [ ] **Step 2: Verify autoload + style**

```bash
composer cs-check src/Constants/AdvanceConstants.php
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add src/Constants/AdvanceConstants.php
git commit -m "feat(advances): add AdvanceConstants for Phase 2 pipeline"
```

---

### Task 1.2: Migration — `advance_id` FK on `invoices`

**Files:**
- Create: `config/Migrations/<timestamp>_AddAdvanceFieldsToInvoices.php`

- [ ] **Step 1: Generate migration scaffolding**

```bash
php bin/cake migrations create AddAdvanceFieldsToInvoices
```
This creates a file like `config/Migrations/<timestamp>_AddAdvanceFieldsToInvoices.php`. Note the resulting filename for later steps.

- [ ] **Step 2: Replace migration body**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddAdvanceFieldsToInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('advance_id')) {
            $table
                ->addColumn('advance_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'petty_cash_record_id',
                    'signed' => true,
                ])
                ->addIndex(['advance_id'])
                ->addForeignKey('advance_id', 'invoices', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if ($table->hasColumn('advance_id')) {
            $table->dropForeignKey('advance_id')->update();
            $table->removeColumn('advance_id')->update();
        }
    }
}
```

Notes: The FK column type must match `invoices.id`. Existing `invoices.id` is `signed` based on the existing FKs in `CreateInvoicePayments` (which also use `'integer'` default-signed). If migration fails with a type-mismatch error, inspect `invoices.id` in MySQL (`SHOW CREATE TABLE invoices;`) and adjust `signed` accordingly.

- [ ] **Step 3: Run migration and verify**

```bash
php bin/cake migrations migrate
```
Expected: Migration runs without errors.

```bash
php bin/cake migrations status
```
Expected: New migration in status `up`.

- [ ] **Step 4: Roll back and forward to confirm idempotency**

```bash
php bin/cake migrations rollback && php bin/cake migrations migrate
```
Expected: Both succeed.

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/
git commit -m "feat(advances): add advance_id self-FK to invoices"
```

---

### Task 1.3: Migration — `is_refund` on `invoice_payments`

**Files:**
- Create: `config/Migrations/<timestamp>_AddIsRefundToInvoicePayments.php`

- [ ] **Step 1: Generate scaffolding**

```bash
php bin/cake migrations create AddIsRefundToInvoicePayments
```

- [ ] **Step 2: Replace migration body**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIsRefundToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_payments');
        if (!$table->hasColumn('is_refund')) {
            $table
                ->addColumn('is_refund', 'boolean', [
                    'null' => false,
                    'default' => false,
                    'after' => 'rejection_reason',
                ])
                ->addIndex(['is_refund'])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoice_payments');
        if ($table->hasColumn('is_refund')) {
            $table->removeIndexByName('invoice_payments_is_refund')->update();
            $table->removeColumn('is_refund')->update();
        }
    }
}
```

- [ ] **Step 3: Run migration**

```bash
php bin/cake migrations migrate
```
Expected: Successful.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(advances): add is_refund flag to invoice_payments"
```

---

### Task 1.4: Migration — `advance_legalizations` table

**Files:**
- Create: `config/Migrations/<timestamp>_CreateAdvanceLegalizations.php`

- [ ] **Step 1: Generate scaffolding**

```bash
php bin/cake migrations create CreateAdvanceLegalizations
```

- [ ] **Step 2: Replace migration body**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizations extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('advance_legalizations')) {
            return;
        }

        $this->table('advance_legalizations')
            ->addColumn('advance_invoice_id', 'integer', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 30, 'null' => false, 'default' => 'validacion'])
            ->addColumn('case_type', 'string', ['limit' => 20, 'null' => true, 'default' => null])
            ->addColumn('shortage_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true, 'default' => null])
            ->addColumn('surplus_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => true, 'default' => null])
            ->addColumn('shortage_received_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('shortage_receipt_number', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('shortage_receipt_path', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('surplus_payment_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('legalized_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_by', 'integer', ['null' => false])
            ->addColumn('updated_by', 'integer', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['advance_invoice_id'], ['unique' => true])
            ->addIndex(['status'])
            ->addForeignKey('advance_invoice_id', 'invoices', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('surplus_payment_id', 'invoice_payments', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalizations')) {
            $this->table('advance_legalizations')->drop()->save();
        }
    }
}
```

- [ ] **Step 3: Run and verify**

```bash
php bin/cake migrations migrate
```
Expected: Table created.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(advances): create advance_legalizations table"
```

---

### Task 1.5: Migration — `advance_legalization_signatures` table

**Files:**
- Create: `config/Migrations/<timestamp>_CreateAdvanceLegalizationSignatures.php`

- [ ] **Step 1: Generate scaffolding**

```bash
php bin/cake migrations create CreateAdvanceLegalizationSignatures
```

- [ ] **Step 2: Replace migration body**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationSignatures extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('advance_legalization_signatures')) {
            return;
        }

        $this->table('advance_legalization_signatures')
            ->addColumn('legalization_id', 'integer', ['null' => false])
            ->addColumn('signed_by_user_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('signed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('document_path', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('document_name', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('signature_status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending'])
            ->addColumn('rejection_reason', 'text', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['legalization_id'])
            ->addForeignKey('legalization_id', 'advance_legalizations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('signed_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalization_signatures')) {
            $this->table('advance_legalization_signatures')->drop()->save();
        }
    }
}
```

- [ ] **Step 3: Run and verify**

```bash
php bin/cake migrations migrate
```

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(advances): create advance_legalization_signatures table"
```

---

### Task 1.6: Migration — Seed `advances` permissions

**Files:**
- Create: `config/Migrations/<timestamp>_SeedAdvancesPermissions.php`

This migration mirrors the `AddContadorRoleAndPermissions` pattern (raw SQL via `$this->execute`).

- [ ] **Step 1: Generate scaffolding**

```bash
php bin/cake migrations create SeedAdvancesPermissions
```

- [ ] **Step 2: Replace migration body**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedAdvancesPermissions extends BaseMigration
{
    /**
     * Permission matrix from docs/plans/2026-04-28-anticipos-design.md (§ "Matriz de roles × estados").
     *
     * @var array<string, array{view:int, create:int, edit:int, delete:int}>
     */
    private const MATRIX = [
        'Administrador'                              => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
        'Contabilidad'                               => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Tesorería'                                  => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Registro/Revisión'                          => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
        'Contador'                                   => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
        'Coordinador Administrativo y Financiero'    => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
    ];

    public function up(): void
    {
        foreach (self::MATRIX as $roleName => $perms) {
            $row = $this->fetchRow("SELECT id FROM roles WHERE name = '" . addslashes($roleName) . "'");
            if (!$row) {
                continue;
            }
            $roleId = $row['id'] ?? $row[0];

            $existing = $this->fetchRow(
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'advances'"
            );
            if ($existing) {
                continue;
            }

            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($roleId, 'advances', {$perms['view']}, {$perms['create']}, {$perms['edit']}, {$perms['delete']}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'advances'");
    }
}
```

- [ ] **Step 3: Run and verify**

```bash
php bin/cake migrations migrate
```

- [ ] **Step 4: Verify rows in `permissions`**

```bash
php bin/cake console -q << 'EOF'
$rows = \Cake\ORM\TableRegistry::getTableLocator()->get('Permissions')->find()->where(['module' => 'advances'])->all();
foreach ($rows as $r) { echo "$r->role_id\tview={$r->can_view}\tcreate={$r->can_create}\tedit={$r->can_edit}\tdelete={$r->can_delete}\n"; }
EOF
```
Expected: One row per role from the matrix (skip any role missing in the local DB).

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/
git commit -m "feat(advances): seed advances permissions per role"
```

---

### Task 1.7: Entity — `AdvanceLegalization`

**Files:**
- Create: `src/Model/Entity/AdvanceLegalization.php`

- [ ] **Step 1: Write entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AdvanceConstants;
use Cake\ORM\Entity;

class AdvanceLegalization extends Entity
{
    protected array $_accessible = [
        'advance_invoice_id' => true,
        'status' => true,
        'case_type' => true,
        'shortage_amount' => true,
        'surplus_amount' => true,
        'shortage_received_at' => true,
        'shortage_receipt_number' => true,
        'shortage_receipt_path' => true,
        'surplus_payment_id' => true,
        'legalized_at' => true,
        'created_by' => true,
        'updated_by' => true,
        'advance_invoice' => true,
        'linked_invoices' => true,
        'advance_legalization_signatures' => true,
    ];

    public function isLegalized(): bool
    {
        return $this->status === AdvanceConstants::STATUS_LEGALIZADA;
    }

    public function isInValidacion(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }
}
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Model/Entity/AdvanceLegalization.php
```

---

### Task 1.8: Entity — `AdvanceLegalizationSignature`

**Files:**
- Create: `src/Model/Entity/AdvanceLegalizationSignature.php`

- [ ] **Step 1: Write entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AdvanceLegalizationSignature extends Entity
{
    protected array $_accessible = [
        'legalization_id' => true,
        'signed_by_user_id' => true,
        'signed_at' => true,
        'document_path' => true,
        'document_name' => true,
        'signature_status' => true,
        'rejection_reason' => true,
    ];
}
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Model/Entity/AdvanceLegalizationSignature.php
```

---

### Task 1.9: Table — `AdvanceLegalizationsTable`

**Files:**
- Create: `src/Model/Table/AdvanceLegalizationsTable.php`

- [ ] **Step 1: Write table class**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AdvanceConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalizations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceInvoices', [
            'className' => 'Invoices',
            'foreignKey' => 'advance_invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SurplusPayments', [
            'className' => 'InvoicePayments',
            'foreignKey' => 'surplus_payment_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UpdatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'updated_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('AdvanceLegalizationSignatures', [
            'foreignKey' => 'legalization_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('advance_invoice_id')
            ->requirePresence('advance_invoice_id', 'create')
            ->notEmptyString('advance_invoice_id');

        $validator
            ->scalar('status')
            ->inList('status', AdvanceConstants::PIPELINE_STATUSES)
            ->notEmptyString('status');

        $validator
            ->scalar('case_type')
            ->allowEmptyString('case_type')
            ->inList('case_type', AdvanceConstants::CASE_TYPES);

        $validator
            ->decimal('shortage_amount')
            ->allowEmptyString('shortage_amount');

        $validator
            ->decimal('surplus_amount')
            ->allowEmptyString('surplus_amount');

        $validator
            ->dateTime('shortage_received_at')
            ->allowEmptyDateTime('shortage_received_at');

        $validator
            ->scalar('shortage_receipt_number')
            ->maxLength('shortage_receipt_number', 100)
            ->allowEmptyString('shortage_receipt_number');

        $validator
            ->scalar('shortage_receipt_path')
            ->maxLength('shortage_receipt_path', 500)
            ->allowEmptyString('shortage_receipt_path');

        $validator
            ->dateTime('legalized_at')
            ->allowEmptyDateTime('legalized_at');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('advance_invoice_id', 'AdvanceInvoices'), [
            'errorField' => 'advance_invoice_id',
        ]);
        $rules->add($rules->isUnique(['advance_invoice_id'], 'Ya existe una legalización para este anticipo.'), [
            'errorField' => 'advance_invoice_id',
        ]);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Model/Table/AdvanceLegalizationsTable.php
```

---

### Task 1.10: Table — `AdvanceLegalizationSignaturesTable`

**Files:**
- Create: `src/Model/Table/AdvanceLegalizationSignaturesTable.php`

- [ ] **Step 1: Write table class**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\AdvanceConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationSignaturesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalization_signatures');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', [
            'foreignKey' => 'legalization_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SignedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'signed_by_user_id',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('legalization_id')
            ->requirePresence('legalization_id', 'create')
            ->notEmptyString('legalization_id');

        $validator
            ->scalar('document_path')
            ->maxLength('document_path', 500)
            ->requirePresence('document_path', 'create')
            ->notEmptyString('document_path');

        $validator
            ->scalar('signature_status')
            ->inList('signature_status', AdvanceConstants::SIGNATURE_STATUSES);

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('legalization_id', 'AdvanceLegalizations'), [
            'errorField' => 'legalization_id',
        ]);

        return $rules;
    }
}
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Model/Table/AdvanceLegalizationSignaturesTable.php
```

---

### Task 1.11: Wire `advance_id` into Invoice entity + table

**Files:**
- Modify: `src/Model/Entity/Invoice.php`
- Modify: `src/Model/Table/InvoicesTable.php`

- [ ] **Step 1: Add `advance_id` to `Invoice::$_accessible`**

In `src/Model/Entity/Invoice.php`, add (alphabetical order doesn't matter; place after `petty_cash_record_id`):

```php
        'petty_cash_record_id' => true,
        'advance_id' => true,
```

- [ ] **Step 2: Add associations + validation in `InvoicesTable`**

In `src/Model/Table/InvoicesTable.php` `initialize()`, append after the existing `belongsTo('Employees', …)`:

```php
        $this->belongsTo('Advance', [
            'className' => 'Invoices',
            'foreignKey' => 'advance_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasOne('AdvanceLegalization', [
            'className' => 'AdvanceLegalizations',
            'foreignKey' => 'advance_invoice_id',
            'joinType' => 'LEFT',
        ]);
```

In `validationDefault()`, append before `return $validator;`:

```php
        $validator
            ->integer('advance_id')
            ->allowEmptyString('advance_id');
```

In `buildRules()`, append before `return $rules;`:

```php
        $rules->add($rules->existsIn('advance_id', 'Advance'), [
            'errorField' => 'advance_id',
            'allowNullableNulls' => true,
        ]);
```

- [ ] **Step 3: cs-check**

```bash
composer cs-check src/Model/Entity/Invoice.php src/Model/Table/InvoicesTable.php
```

- [ ] **Step 4: Commit Phase 1 ORM batch**

```bash
git add src/Constants/AdvanceConstants.php src/Model/
git commit -m "feat(advances): add Advance ORM entities, tables, and Invoice associations"
```

---

### Task 1.12: Quick smoke test for ORM wiring

**Files:**
- None (CLI smoke test)

- [ ] **Step 1: Insert and read back a legalization row via console**

```bash
php bin/cake console -q << 'EOF'
$invoicesTable = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
$legTable = \Cake\ORM\TableRegistry::getTableLocator()->get('AdvanceLegalizations');
$adminId = 1; // assume admin id
$invoice = $invoicesTable->newEntity([
    'document_type' => 'Anticipo',
    'issue_date' => date('Y-m-d'),
    'detail' => 'TEST advance smoke',
    'amount' => 100000,
    'operation_center_id' => 1,
    'expense_type_id' => 1,
    'pipeline_status' => 'pagada',
    'area_approval' => 'Aprobada',
    'registered_by' => $adminId,
]);
if (!$invoicesTable->save($invoice)) { print_r($invoice->getErrors()); exit(1); }
$leg = $legTable->newEntity([
    'advance_invoice_id' => $invoice->id,
    'status' => 'validacion',
    'created_by' => $adminId,
]);
$ok = $legTable->save($leg);
echo $ok ? "leg-id={$leg->id}\n" : "save failed: " . print_r($leg->getErrors(), true) . "\n";
$legTable->deleteAll(['id' => $leg->id]);
$invoicesTable->deleteAll(['id' => $invoice->id]);
EOF
```
Expected: Output `leg-id=N` for some integer N. If validation fails, recheck Task 1.7–1.11.

---

## Phase 2 — Anticipo Lifecycle (Phase 1 of design) (PR 2)

### Task 2.1: Auto-approve Anticipo invoices on creation

**Files:**
- Modify: `src/Model/Table/InvoicesTable.php`

The design (decision #9) says Anticipo skips area approval. Implement via a `beforeSave` hook that runs on new Anticipo entities.

- [ ] **Step 1: Add `beforeSave` to `InvoicesTable`**

Append near the bottom of the class:

```php
    public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity, \ArrayObject $options): void
    {
        if ($entity->isNew() && ($entity->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO) {
            $entity->area_approval = \App\Constants\InvoiceConstants::APPROVAL_APPROVED;
            $entity->approver_id = null;
            if (empty($entity->dian_validation)) {
                $entity->dian_validation = \App\Constants\InvoiceConstants::DIAN_APPROVED;
            }
        }
    }
```

Note: `area_approval_date` is set elsewhere (`InvoicePipelineService::saveAndAdvance`), but new Anticipos persist via `add()` action which doesn't touch the pipeline service. Set the date here too so the audit trail is complete:

```php
        if ($entity->isNew() && ($entity->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO) {
            $entity->area_approval = \App\Constants\InvoiceConstants::APPROVAL_APPROVED;
            $entity->approver_id = null;
            $entity->area_approval_date = date('Y-m-d');
            if (empty($entity->dian_validation)) {
                $entity->dian_validation = \App\Constants\InvoiceConstants::DIAN_APPROVED;
            }
        }
```

But `area_approval_date` is **not in `_accessible`** (`Invoice.php` line 31 has it as `false`). Set it via the entity property directly — entity setters bypass `_accessible`. The line above does that correctly.

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Model/Table/InvoicesTable.php
```

---

### Task 2.2: Hook auto-creation of legalization when Anticipo reaches `pagada`

**Files:**
- Create: `src/Service/AdvanceLegalizationService.php` (initial skeleton — only `initialize()` for now)
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/InvoicePaymentService.php`

There are TWO paths an Invoice can take to reach `pagada`:
1. `InvoicePipelineService::saveAndAdvance` (and `advance`) — flows through Contador autorizando vía edit form.
2. `InvoicePaymentService::authorizePayment` — Contador autoriza un pago en el listado, lo cual también puede llevar la factura a `pagada`.

Both must trigger `AdvanceLegalizationService::initialize()` when the new status is `pagada` AND `document_type === 'Anticipo'`.

- [ ] **Step 1: Create `AdvanceLegalizationService` skeleton with `initialize()`**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use Cake\ORM\TableRegistry;

class AdvanceLegalizationService
{
    /**
     * Idempotently create the advance_legalizations row for a paid Anticipo.
     */
    public function initialize(Invoice $advance, int $userId): ServiceResult
    {
        if (($advance->document_type ?? null) !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            return ServiceResult::fail('Solo los Anticipos pueden iniciar legalización.');
        }
        if (($advance->pipeline_status ?? null) !== InvoiceConstants::STATUS_PAGADA) {
            return ServiceResult::fail('El anticipo debe estar Pagada para iniciar legalización.');
        }

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $existing = $table->find()->where(['advance_invoice_id' => $advance->id])->first();
        if ($existing) {
            return ServiceResult::ok($existing);
        }

        $entity = $table->newEntity([
            'advance_invoice_id' => $advance->id,
            'status' => AdvanceConstants::STATUS_VALIDACION,
            'created_by' => $userId,
        ]);

        if (!$table->save($entity)) {
            return ServiceResult::fail('No se pudo crear la legalización: ' . json_encode($entity->getErrors()));
        }

        return ServiceResult::ok($entity);
    }
}
```

- [ ] **Step 2: Inject the service into `InvoicePipelineService`**

In the constructor:

```php
    private AdvanceLegalizationService $advanceLegalizationService;

    public function __construct(
        ?HistoryServiceInterface $historyService = null,
        ?InvoicePaymentService $paymentService = null,
        ?InvoiceFieldAccessPolicy $fieldPolicy = null,
        ?AdvanceLegalizationService $advanceLegalizationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
        $this->fieldPolicy = $fieldPolicy ?? new InvoiceFieldAccessPolicy();
        $this->advanceLegalizationService = $advanceLegalizationService ?? new AdvanceLegalizationService();
    }
```

- [ ] **Step 3: Hook into `saveAndAdvance` — fire when new status is `pagada` for Anticipo**

In the transactional callback, after the existing `$this->paymentService->recalculatePaymentStatus(...)` block (line 411 area), add a SECOND post-save hook that runs when the final status (after possible auto-regression) is `pagada`:

```php
                if ($currentStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                    $this->paymentService->recalculatePaymentStatus($invoice->id);
                    $refreshed = $invoicesTable->get($invoice->id);

                    if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
                        $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                        $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
                        $invoicesTable->save($invoice);
                        $this->historyService->recordStatusChange(
                            $invoice->id,
                            InvoiceConstants::STATUS_PAGADA,
                            InvoiceConstants::STATUS_TESORERIA,
                            $userId,
                        );
                    }
                }

                // Anticipo → Legalización auto-init (idempotent).
                if (
                    $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
                    && ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
                ) {
                    $this->advanceLegalizationService->initialize($invoice, $userId);
                }
```

- [ ] **Step 4: Hook into `InvoicePaymentService::authorizePayment` after the invoice is saved**

In `src/Service/InvoicePaymentService.php`, modify `authorizePayment` to inject the service and call `initialize()` after the invoice transitions to `pagada`:

```php
    private AdvanceLegalizationService $advanceLegalizationService;

    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?AdvanceLegalizationService $advanceLegalizationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->advanceLegalizationService = $advanceLegalizationService ?? new AdvanceLegalizationService();
    }
```

After `$invoicesTable->save($invoice);` near line 129, add:

```php
        if (
            $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
            && ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
        ) {
            $this->advanceLegalizationService->initialize($invoice, $authorizedBy);
        }
```

- [ ] **Step 5: cs-check**

```bash
composer cs-check src/Service/
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php src/Service/InvoicePipelineService.php src/Service/InvoicePaymentService.php src/Model/Table/InvoicesTable.php
git commit -m "feat(advances): auto-approve Anticipo and auto-init legalization on pagada"
```

---

### Task 2.3: Hide `revision` section for Anticipos in field access policy

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

The `revision` section is the area-approval block. Anticipos must hide it.

- [ ] **Step 1: Add a method that filters sections by document type**

Modify `getVisibleSections()` to accept optional document type:

```php
    public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
    {
        $sections = $this->_resolveVisibleSections($roleName, $status);

        if ($documentType === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO) {
            $sections = array_values(array_filter($sections, static fn($s) => $s !== 'revision'));
        }

        return $sections;
    }

    private function _resolveVisibleSections(string $roleName, string $status): array
    {
        if ($roleName !== \App\Constants\RoleConstants::ADMIN) {
            return self::VISIBLE_SECTIONS_BY_ROLE[$roleName] ?? ['general'];
        }

        $statusIndex = $this->_getStatusIndex($status);
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

- [ ] **Step 2: Update `InvoicePipelineService::getVisibleSections` to pass document type**

```php
    public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
    {
        return $this->fieldPolicy->getVisibleSections($roleName, $status, $documentType);
    }
```

- [ ] **Step 3: Update `InvoicesController::edit` to pass document type**

In `src/Controller/InvoicesController.php` `edit()`:

```php
        $visibleSections = $this->pipeline->getVisibleSections($roleName, $currentStatus, $invoice->document_type);
```

- [ ] **Step 4: cs-check**

```bash
composer cs-check src/Service/InvoiceFieldAccessPolicy.php src/Service/InvoicePipelineService.php src/Controller/InvoicesController.php
```

---

### Task 2.4: Truncated pipeline for Legalización invoices

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

Legalización invoices skip `tesoreria` and `autorizacion_pago` (the beneficiary already paid them). The pipeline must transition `contabilidad → pagada` directly for Legalización docs. Field policy should also strip `treasury` and `payment_authorization` sections.

- [ ] **Step 1: Add helper for next status that respects document type**

In `InvoicePipelineService`, replace `getNextStatus()` with a doc-aware version:

```php
    public function getNextStatus(string $currentStatus, ?string $documentType = null): ?string
    {
        if (
            $documentType === InvoiceConstants::DOCTYPE_LEGALIZACION
            && $currentStatus === InvoiceConstants::STATUS_CONTABILIDAD
        ) {
            return InvoiceConstants::STATUS_PAGADA;
        }

        return self::TRANSITIONS[$currentStatus] ?? null;
    }
```

- [ ] **Step 2: Update internal callers to pass document type**

Three callers in `InvoicePipelineService` invoke `getNextStatus`:

(a) `saveAndAdvance` — replace `$this->getNextStatus($currentStatus)` with `$this->getNextStatus($currentStatus, $invoice->document_type)`.

(b) `advance` — replace `$this->getNextStatus($currentStatus)` with `$this->getNextStatus($currentStatus, $invoice->document_type)`.

(c) `canAdvance` (around line 320) — keep as-is since the transition existence is what we check; it already returns true for any non-null next from the constant map.

- [ ] **Step 3: Skip `_has_pending_payment` requirement for Legalización**

In `validateTransitionRequirements`, add a special case before the foreach:

```php
        // Legalizaciones skip treasury/auth-payment requirements: jump from contabilidad to pagada directly.
        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION
            && $fromStatus === InvoiceConstants::STATUS_CONTABILIDAD
        ) {
            $errors = [];
            foreach (self::TRANSITION_REQUIREMENTS[InvoiceConstants::STATUS_CONTABILIDAD] ?? [] as $rule) {
                $field = $rule['field'];
                $value = $invoice->$field ?? null;
                if (isset($rule['value']) && $value !== $rule['value']) {
                    $errors[] = $rule['label'];
                } elseif (!empty($rule['not_empty']) && ($value === null || $value === '' || $value === false)) {
                    $errors[] = $rule['label'];
                }
            }

            return $errors;
        }
```

Place it near the top after the `isRejected` check.

- [ ] **Step 4: Hide `treasury` / `payment_authorization` sections for Legalización**

In `InvoiceFieldAccessPolicy::getVisibleSections`, extend the filter:

```php
        if ($documentType === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO) {
            $sections = array_values(array_filter($sections, static fn($s) => $s !== 'revision'));
        }

        if ($documentType === \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION) {
            $sections = array_values(array_filter(
                $sections,
                static fn($s) => !in_array($s, ['treasury', 'payment_authorization'], true),
            ));
        }
```

- [ ] **Step 5: cs-check**

```bash
composer cs-check src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/InvoicePipelineService.php src/Service/InvoiceFieldAccessPolicy.php src/Controller/InvoicesController.php
git commit -m "feat(advances): truncated pipeline + section filtering for Anticipo/Legalización"
```

---

### Task 2.5: Register `advances` module in AppController + AuthorizationService

**Files:**
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`

- [ ] **Step 1: Add `Advances` to `controllerModuleMap`**

In `src/Controller/AppController.php`, append to `$controllerModuleMap`:

```php
        'Advances' => 'advances',
```

- [ ] **Step 2: Add `advances` to `AuthorizationService::MODULES`**

```php
        'advances' => 'Anticipos',
```

Place it after `'invoices' => 'Facturas',`.

- [ ] **Step 3: Add Phase 2 actions to `_actionToPermission`**

In `_actionToPermission()`, extend the `'edit'` arm with the Phase 2 endpoints (added in Phase 3+ but mapped now):

Existing edit branch:
```php
            'edit', 'advanceStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload' => 'edit',
```

Append these tokens to the same arm: `'linkInvoices', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund'`.

- [ ] **Step 4: cs-check**

```bash
composer cs-check src/Controller/AppController.php src/Service/AuthorizationService.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php
git commit -m "feat(advances): register advances module and Phase 2 action permissions"
```

---

### Task 2.6: AdvancesController — index/add/view (Phase 1 only)

**Files:**
- Create: `src/Controller/AdvancesController.php`

Phase 1 endpoints: `index`, `add`, `view`. The `edit` action delegates to `InvoicesController::edit` because the Anticipo IS an Invoice — so `edit` simply redirects there for now. Phase 2 endpoints (linkInvoices, etc.) are added in Phase 3.

- [ ] **Step 1: Write controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

class AdvancesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('Invoices');
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    public function index(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $query = $invoicesTable->find()
            ->where(['Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->order(['Invoices.created' => 'DESC']);

        $advances = $this->paginate($query);

        $this->set(compact('advances'));
    }

    public function add(): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['document_type'] = InvoiceConstants::DOCTYPE_ANTICIPO;
            $data['registered_by'] = $user->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');

            // beneficiary required: provider_id OR employee_id
            if (empty($data['provider_id']) && empty($data['employee_id'])) {
                $this->Flash->error('Debe seleccionar un proveedor o un empleado como beneficiario.');
            } else {
                $invoice = $invoicesTable->patchEntity($invoice, $data);
                if ($invoicesTable->save($invoice)) {
                    $this->Flash->success('Anticipo creado.');

                    return $this->redirect(['action' => 'view', $invoice->id]);
                }
                $this->Flash->error('No se pudo guardar el anticipo.');
            }
        }

        $this->set(compact('invoice'));
        $this->set($this->_dropdowns());
    }

    public function view(?int $id = null): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'RegisteredByUsers',
            'InvoiceObservations' => ['Users'],
            'InvoiceDocuments' => ['UploadedByUsers'],
            'InvoicePayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            'AdvanceLegalization' => ['AdvanceLegalizationSignatures' => ['SignedByUsers']],
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        // Linked Legalización-Invoices
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->order(['issue_date' => 'ASC'])
            ->all();

        $linkedTotal = 0.0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
        }

        $this->set(compact('invoice', 'linkedInvoices', 'linkedTotal'));
    }

    public function edit(?int $id = null)
    {
        // The Anticipo is an Invoice; edit lives in InvoicesController.
        return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $id]);
    }

    private function _dropdowns(): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        return [
            'providers' => $invoicesTable->Providers->find('list')->order(['Providers.name' => 'ASC'])->all(),
            'operationCenters' => $invoicesTable->OperationCenters->find('codeList')->all(),
            'expenseTypes' => $invoicesTable->ExpenseTypes->find('list', limit: 200)->all(),
            'costCenters' => $invoicesTable->CostCenters->find('codeList')->all(),
            'employees' => $this->fetchTable('Employees')
                ->find('list', limit: 500)
                ->order(['Employees.first_name' => 'ASC'])
                ->all(),
        ];
    }
}
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Controller/AdvancesController.php
```

---

### Task 2.7: Templates — `index`, `add`, `view` (Phase 1)

**Files:**
- Create: `templates/Advances/index.php`
- Create: `templates/Advances/add.php`
- Create: `templates/Advances/view.php`
- Create: `templates/element/advance_legalization_progress.php`

These are Spanish-language Bootstrap 5 views matching the conventions in `templates/Invoices/`. Auto-init JS (Flatpickr `.flatpickr-date`, AutoNumeric `.currency-input`, Select2 `.select2`) is already wired via `webroot/js/sgi-common.js`.

- [ ] **Step 1: Write `templates/Advances/index.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 */
$this->assign('title', 'Anticipos');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h3 mb-0">Anticipos</h2>
        <?= $this->Html->link(
            '<i class="bi bi-plus-circle me-1"></i> Nuevo Anticipo',
            ['action' => 'add'],
            ['class' => 'btn sgi-btn-primary', 'escape' => false],
        ) ?>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Beneficiario</th>
                        <th>Centro Op.</th>
                        <th>Detalle</th>
                        <th class="text-end">Monto</th>
                        <th>Estado pago</th>
                        <th>Estado legalización</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($advances as $a): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>">
                        <td><?= h($a->id) ?></td>
                        <td><?= h($a->provider->name ?? ($a->employee->full_name ?? '—')) ?></td>
                        <td><?= h($a->operation_center->name ?? '—') ?></td>
                        <td><?= h($a->detail) ?></td>
                        <td class="text-end">$<?= number_format((float)$a->amount, 0, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= h($a->pipeline_status) ?></span></td>
                        <td>
                            <?php if ($a->advance_legalization): ?>
                                <span class="badge bg-info text-dark">
                                    <?= h(\App\Constants\AdvanceConstants::STATUS_LABELS[$a->advance_legalization->status] ?? $a->advance_legalization->status) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $a->id], ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= $this->element('pagination') ?>
</div>
```

- [ ] **Step 2: Write `templates/Advances/add.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var \Cake\Collection\CollectionInterface $providers
 * @var \Cake\Collection\CollectionInterface $employees
 * @var \Cake\Collection\CollectionInterface $operationCenters
 * @var \Cake\Collection\CollectionInterface $expenseTypes
 * @var \Cake\Collection\CollectionInterface $costCenters
 */
$this->assign('title', 'Nuevo Anticipo');
?>
<div class="container py-4">
    <h2 class="h3 mb-3">Nuevo Anticipo</h2>
    <?= $this->Form->create($invoice) ?>
    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Proveedor (beneficiario)</label>
                    <?= $this->Form->select('provider_id', $providers, ['class' => 'form-select select2', 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Empleado (beneficiario)</label>
                    <?= $this->Form->select('employee_id', $employees, ['class' => 'form-select select2', 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Centro de Operación *</label>
                    <?= $this->Form->select('operation_center_id', $operationCenters, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipo de Gasto *</label>
                    <?= $this->Form->select('expense_type_id', $expenseTypes, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Centro de Costos</label>
                    <?= $this->Form->select('cost_center_id', $costCenters, ['class' => 'form-select select2', 'empty' => '— Sin asignar —']) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha de Emisión *</label>
                    <?= $this->Form->control('issue_date', ['label' => false, 'class' => 'form-control flatpickr-date', 'required' => true, 'type' => 'text']) ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monto *</label>
                    <?= $this->Form->control('amount', ['label' => false, 'class' => 'form-control currency-input', 'required' => true, 'type' => 'text']) ?>
                </div>
                <div class="col-12">
                    <label class="form-label">Concepto / Detalle *</label>
                    <?= $this->Form->control('detail', ['label' => false, 'class' => 'form-control', 'required' => true, 'type' => 'textarea', 'rows' => 3]) ?>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-link']) ?>
            <button type="submit" class="btn sgi-btn-primary">Guardar Anticipo</button>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>
```

- [ ] **Step 3: Write `templates/element/advance_legalization_progress.php`**

```php
<?php
/**
 * Phase 2 progress strip — mirrors element/pipeline_progress.php.
 *
 * @var \App\View\AppView $this
 * @var string $currentStatus
 */
$statuses = \App\Constants\AdvanceConstants::PIPELINE_STATUSES;
$labels = \App\Constants\AdvanceConstants::STATUS_LABELS;
$icons = \App\Constants\AdvanceConstants::STATUS_ICONS;
$currentIndex = array_search($currentStatus, $statuses, true);
if ($currentIndex === false) { $currentIndex = 0; }
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($statuses as $i => $s): ?>
        <?php $isDone = $i < $currentIndex; $isCurrent = $i === $currentIndex; ?>
        <div class="d-flex align-items-center">
            <span class="badge <?= $isCurrent ? 'bg-primary' : ($isDone ? 'bg-success' : 'bg-light text-muted') ?>">
                <i class="bi <?= h($icons[$s] ?? 'bi-circle') ?> me-1"></i>
                <?= h($labels[$s] ?? $s) ?>
            </span>
            <?php if ($i < count($statuses) - 1): ?>
                <i class="bi bi-arrow-right mx-2 text-muted"></i>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
```

- [ ] **Step 4: Write `templates/Advances/view.php` (Phase 1 baseline — Phase 2 actions stubbed for later tasks)**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var float $linkedTotal
 */
$this->assign('title', 'Anticipo #' . $invoice->id);
$leg = $invoice->advance_legalization ?? null;
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h3 mb-0">Anticipo #<?= h($invoice->id) ?></h2>
            <small class="text-muted"><?= h($invoice->detail) ?></small>
        </div>
        <?= $this->Html->link('Editar', ['controller' => 'Invoices', 'action' => 'edit', $invoice->id], ['class' => 'btn btn-outline-secondary']) ?>
    </div>

    <?= $this->element('pipeline_progress', [
        'currentStatus' => $invoice->pipeline_status,
        'isRejected' => false,
    ]) ?>

    <?php if ($leg): ?>
        <h5 class="mt-4">Legalización</h5>
        <?= $this->element('advance_legalization_progress', ['currentStatus' => $leg->status]) ?>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Datos del Anticipo</div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Beneficiario</dt>
                <dd class="col-sm-9"><?= h($invoice->provider->name ?? ($invoice->employee->full_name ?? '—')) ?></dd>
                <dt class="col-sm-3">Monto</dt>
                <dd class="col-sm-9">$<?= number_format((float)$invoice->amount, 0, ',', '.') ?></dd>
                <dt class="col-sm-3">Centro de Operación</dt>
                <dd class="col-sm-9"><?= h($invoice->operation_center->name ?? '—') ?></dd>
                <dt class="col-sm-3">Estado pipeline</dt>
                <dd class="col-sm-9"><?= h($invoice->pipeline_status) ?></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Facturas vinculadas</span>
            <?php if ($leg && $leg->status === \App\Constants\AdvanceConstants::STATUS_VALIDACION): ?>
                <button type="button" class="btn btn-sm sgi-btn-primary" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                    <i class="bi bi-link-45deg"></i> Agregar facturas
                </button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>#</th><th>Beneficiario</th><th>Fecha</th><th class="text-end">Monto</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($linkedInvoices as $li): ?>
                    <tr>
                        <td><?= h($li->invoice_number ?: $li->id) ?></td>
                        <td><?= h($li->provider->name ?? ($li->employee->full_name ?? '—')) ?></td>
                        <td><?= $li->issue_date ? $li->issue_date->format('d/m/Y') : '—' ?></td>
                        <td class="text-end">$<?= number_format((float)$li->amount, 0, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= h($li->pipeline_status) ?></span></td>
                        <td><?= $this->Html->link('<i class="bi bi-eye"></i>', ['controller' => 'Invoices', 'action' => 'view', $li->id], ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light"><td colspan="3" class="text-end fw-bold">Total vinculado</td><td class="text-end fw-bold">$<?= number_format($linkedTotal, 0, ',', '.') ?></td><td colspan="2"></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Commit**

```bash
git add templates/Advances/ templates/element/advance_legalization_progress.php src/Controller/AdvancesController.php
git commit -m "feat(advances): scaffold Anticipo controller + index/add/view templates"
```

---

### Task 2.8: Sidebar link + counter

**Files:**
- Modify: `src/Service/SidebarCounterService.php`
- Modify: `templates/layout/default.php`

- [ ] **Step 1: Add advance counter to `SidebarCounterService::getCounters`**

In the try block return array:

```php
                'advancesPendingLegalizationCount' => $this->getCount(
                    'AdvanceLegalizations',
                    ['status !=' => \App\Constants\AdvanceConstants::STATUS_LEGALIZADA],
                ),
```

Mirror in the catch fallback:

```php
                'advancesPendingLegalizationCount' => 0,
```

- [ ] **Step 2: Add nav-link in `templates/layout/default.php` under the Financiero section**

Inside the `Financiero` `<ul class="sidebar-submenu">` (the outer one — the same level as `Mis Facturas` and `Caja Menor`), insert before `Programación`:

```php
                            <?php if ($canView('advances')): ?>
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-cash-coin me-2"></i>Anticipos' .
                                    (!empty($advancesPendingLegalizationCount) ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $advancesPendingLegalizationCount . '</span>' : ''),
                                    ['controller' => 'Advances', 'action' => 'index'],
                                    ['class' => $navLink('Advances') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
                            <?php endif; ?>
```

Also at the top of the layout add the variable default near `$pettyCashCount = $pettyCashCount ?? 0;`:

```php
$advancesPendingLegalizationCount = $advancesPendingLegalizationCount ?? 0;
```

- [ ] **Step 3: cs-check**

```bash
composer cs-check src/Service/SidebarCounterService.php
```

- [ ] **Step 4: Manually verify** (start the server and log in as Admin)

```bash
php bin/cake server
```

Browse to `http://localhost:8765/advances`. Expected: empty Anticipos index loads; sidebar shows "Anticipos" link.

- [ ] **Step 5: Commit**

```bash
git add src/Service/SidebarCounterService.php templates/layout/default.php
git commit -m "feat(advances): sidebar link + pending-legalization counter"
```

---

### Task 2.9: Banner on Legalización-Invoice views

**Files:**
- Modify: `templates/Invoices/view.php`
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Add a banner partial near the top of `templates/Invoices/view.php`**

Add inside the main container, before existing content:

```php
<?php if (($invoice->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($invoice->advance_id)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1"></i>
            Esta factura es una <strong>Legalización</strong> vinculada al
            <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 2: Mirror the same block in `templates/Invoices/edit.php`** (immediately after the form's opening container).

- [ ] **Step 3: Commit**

```bash
git add templates/Invoices/view.php templates/Invoices/edit.php
git commit -m "feat(advances): show 'Vinculada al Anticipo' banner on Legalización invoices"
```

---

### Task 2.10: Smoke-test Phase 1 happy path

**Files:**
- None (manual test)

- [ ] **Step 1: Use the running server to walk through the full Anticipo lifecycle**

Manually:
1. Log in as Admin, go to `/advances`, click "Nuevo Anticipo".
2. Fill the form (any valid Operation Center + Expense Type, set a Provider OR Employee, monto >0). Submit.
3. Open the created invoice via "Editar". Verify `area_approval='Aprobada'` is preset and the `revision` section is hidden.
4. Walk it through the pipeline as Contabilidad → Tesorería → Contador (or as Admin), authorizing the payment.
5. After the invoice reaches `pagada`, browse to `/advances/view/{id}`. The legalization progress strip should render at status `validacion`.
6. Open the DB and verify `advance_legalizations` has a row pointing to that invoice with `status='validacion'`.

```bash
php bin/cake console -q -c "echo \\Cake\\ORM\\TableRegistry::getTableLocator()->get('AdvanceLegalizations')->find()->count() . PHP_EOL;"
```
Expected: `>= 1`.

- [ ] **Step 2: Commit any final fixes**

If issues are found, fix them, run `composer cs-fix`, and commit.

---

## Phase 3 — Legalización Validación / Revisión Firmas / Caso Exacto (PR 3)

### Task 3.1: Extend `AdvanceLegalizationService` with validation-stage methods

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php`

These methods drive the `validacion → revision_firmas → contabilidad → legalizada (exacto)` happy path.

- [ ] **Step 1: Append new methods to the service**

```php
    use \App\Service\Trait\DocumentUploadTrait;

    /**
     * Bulk-link Legalización invoices to this advance.
     *
     * @param int[] $invoiceIds
     */
    public function linkInvoices(\App\Model\Entity\AdvanceLegalization $leg, array $invoiceIds, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('Solo se pueden vincular facturas en estado Validación.');
        }
        if (empty($invoiceIds)) {
            return ServiceResult::fail('Seleccione al menos una factura.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        $count = $invoices->updateAll(
            ['advance_id' => $leg->advance_invoice_id],
            [
                'id IN' => $invoiceIds,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'advance_id IS' => null,
            ],
        );

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok(['linked' => (int)$count]);
    }

    public function unlinkInvoice(\App\Model\Entity\AdvanceLegalization $leg, int $invoiceId, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('Solo se pueden desvincular facturas en estado Validación.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $count = $invoices->updateAll(
            ['advance_id' => null],
            [
                'id' => $invoiceId,
                'advance_id' => $leg->advance_invoice_id,
            ],
        );

        if ($count === 0) {
            return ServiceResult::fail('La factura no estaba vinculada a este anticipo.');
        }

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok(['unlinked' => 1]);
    }

    /**
     * Save the relation-of-invoices document; supersedes any pending signature row.
     */
    public function attachRelationDocument(\App\Model\Entity\AdvanceLegalization $leg, \Laminas\Diactoros\UploadedFile $file, int $userId): ServiceResult
    {
        if (!in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)) {
            return ServiceResult::fail('Solo se puede subir el documento en Validación o Revisión y Firmas.');
        }

        $result = $this->uploadAndSave(
            $file,
            'AdvanceLegalizationSignatures',
            'advances/' . $leg->id,
            'leg_',
            [
                'legalization_id' => $leg->id,
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ],
        );

        if (is_string($result)) {
            return ServiceResult::fail($result);
        }

        // Mark prior pending docs as superseded by deleting them — keep history simple.
        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $sigTable->deleteAll([
            'legalization_id' => $leg->id,
            'id !=' => $result->id,
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);

        $this->_touchUpdatedBy($leg, $userId);

        return ServiceResult::ok($result);
    }

    /**
     * Advance from validacion → revision_firmas. Requires ≥1 linked invoice, a relation
     * document, and that every linked invoice is at least in `contabilidad`.
     */
    public function moveToRevisionFirmas(\App\Model\Entity\AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_VALIDACION) {
            return ServiceResult::fail('La legalización no está en Validación.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $linked = $invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return ServiceResult::fail('Vincule al menos una factura antes de avanzar.');
        }

        $allowedStatuses = [
            InvoiceConstants::STATUS_CONTABILIDAD,
            InvoiceConstants::STATUS_PAGADA,
        ];
        foreach ($linked as $li) {
            if (!in_array($li->pipeline_status, $allowedStatuses, true)) {
                return ServiceResult::fail(
                    'Todas las facturas vinculadas deben estar al menos en Contabilidad. '
                    . 'Falta: factura ' . ($li->invoice_number ?: ('#' . $li->id))
                );
            }
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $hasDoc = $sigTable->exists([
            'legalization_id' => $leg->id,
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]);
        if (!$hasDoc) {
            return ServiceResult::fail('Debe adjuntar la relación de facturas (PDF).');
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_REVISION_FIRMAS, $userId);
    }

    public function markSigned(\App\Model\Entity\AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_REVISION_FIRMAS) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $pending = $sigTable->find()
            ->where(['legalization_id' => $leg->id, 'signature_status' => AdvanceConstants::SIGNATURE_PENDING])
            ->order(['id' => 'DESC'])
            ->first();

        if (!$pending) {
            return ServiceResult::fail('No hay documento pendiente para firmar.');
        }

        $pending->signed_by_user_id = $userId;
        $pending->signed_at = date('Y-m-d H:i:s');
        $pending->signature_status = AdvanceConstants::SIGNATURE_SIGNED;
        $sigTable->save($pending);

        return $this->_setStatus($leg, AdvanceConstants::STATUS_CONTABILIDAD, $userId);
    }

    public function returnToValidacion(\App\Model\Entity\AdvanceLegalization $leg, string $reason, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_REVISION_FIRMAS) {
            return ServiceResult::fail('La legalización no está en Revisión y Firmas.');
        }
        if (trim($reason) === '') {
            return ServiceResult::fail('Indique el motivo de la devolución.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $pending = $sigTable->find()
            ->where(['legalization_id' => $leg->id, 'signature_status' => AdvanceConstants::SIGNATURE_PENDING])
            ->order(['id' => 'DESC'])
            ->first();
        if ($pending) {
            $pending->signature_status = AdvanceConstants::SIGNATURE_REJECTED;
            $pending->rejection_reason = $reason;
            $sigTable->save($pending);
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_VALIDACION, $userId);
    }

    /**
     * Sum of amounts of linked Legalización invoices.
     */
    public function getLinkedTotal(\App\Model\Entity\AdvanceLegalization $leg): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        return (float)$invoices->find()
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            ])
            ->all()
            ->sumOf('amount');
    }

    /**
     * Difference: advance.amount - sum(linked.amount).
     * - >0 means shortage (anticipo > facturas vinculadas; sobró dinero al beneficiario).
     * - <0 means surplus (facturas > anticipo; la empresa debe reintegrar).
     * NOTE: this matches the design's caso definition — verify with stakeholders if
     * the sign convention differs in your domain. Design § "contabilidad" describes
     * "diff calculado como ayuda visual"; we follow positive-shortage / negative-surplus.
     *
     * Reread: design says shortage = el beneficiario consigna lo no gastado.
     * That happens when sum(linked) < advance.amount → diff = advance - linked > 0.
     * Surplus = la empresa reintegra al beneficiario, sum(linked) > advance.amount → diff < 0.
     */
    public function getDifference(\App\Model\Entity\AdvanceLegalization $leg): float
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoices->get($leg->advance_invoice_id);

        return (float)$advance->amount - $this->getLinkedTotal($leg);
    }

    public function markExact(\App\Model\Entity\AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }

        if (abs($this->getDifference($leg)) > 0.005) {
            return ServiceResult::fail('La diferencia no es cero. Use Faltante o Sobrante.');
        }

        $leg->case_type = AdvanceConstants::CASE_EXACTO;
        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }

    private function _setStatus(\App\Model\Entity\AdvanceLegalization $leg, string $newStatus, int $userId): ServiceResult
    {
        $leg->status = $newStatus;
        $leg->updated_by = $userId;
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        if (!$table->save($leg)) {
            return ServiceResult::fail('No se pudo guardar la legalización: ' . json_encode($leg->getErrors()));
        }

        return ServiceResult::ok($leg);
    }

    private function _touchUpdatedBy(\App\Model\Entity\AdvanceLegalization $leg, int $userId): void
    {
        $leg->updated_by = $userId;
        TableRegistry::getTableLocator()->get('AdvanceLegalizations')->save($leg);
    }
```

Notes on imports — at the top of the file ensure these `use` statements:

```php
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
```

The `use \App\Service\Trait\DocumentUploadTrait;` line inside the class body is a trait use; remove the leading backslash if you also imported it at file level.

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Service/AdvanceLegalizationService.php
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php
git commit -m "feat(advances): legalization service - validacion, revision_firmas, exacto"
```

---

### Task 3.2: AdvancesController endpoints for Phase 2 (validacion + exacto)

**Files:**
- Modify: `src/Controller/AdvancesController.php`

- [ ] **Step 1: Inject the service into the controller**

```php
    private \App\Service\AdvanceLegalizationService $legalizationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('Invoices');
        $this->legalizationService = new \App\Service\AdvanceLegalizationService();
    }
```

- [ ] **Step 2: Add helper to load a legalization for an action**

```php
    private function _loadLegalization(int $advanceInvoiceId): \App\Model\Entity\AdvanceLegalization
    {
        return TableRegistry::getTableLocator()
            ->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->firstOrFail();
    }
```

- [ ] **Step 3: Add `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument`, `markSigned`, `returnToValidacion`, `markExact` actions**

```php
    public function linkInvoices(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $userId = (int)$this->_getCurrentUser()->id;

        $invoiceIds = (array)$this->request->getData('invoice_ids', []);
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds)));

        $result = $this->legalizationService->linkInvoices($leg, $invoiceIds, $userId);
        if ($result->success) {
            $this->Flash->success(($result->data['linked'] ?? 0) . ' factura(s) vinculada(s).');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al vincular.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function unlinkInvoice(?int $id = null, ?int $invoiceId = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $result = $this->legalizationService->unlinkInvoice($leg, (int)$invoiceId, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al desvincular.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function uploadRelationDocument(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $file = $this->request->getUploadedFile('relation_document');
        if (!$file) {
            $this->Flash->error('Adjunte un archivo PDF de relación de facturas.');

            return $this->redirect(['action' => 'view', $id]);
        }
        $result = $this->legalizationService->attachRelationDocument($leg, $file, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Documento adjuntado.')
            : $this->Flash->error($result->firstError() ?? 'Error al adjuntar.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function moveToRevision(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $result = $this->legalizationService->moveToRevisionFirmas($leg, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Legalización enviada a Revisión y Firmas.')
            : $this->Flash->error($result->firstError() ?? 'Error al avanzar.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function markSigned(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $result = $this->legalizationService->markSigned($leg, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Documento marcado como firmado.')
            : $this->Flash->error($result->firstError() ?? 'Error al firmar.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function returnToValidacion(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $reason = (string)$this->request->getData('reason', '');
        $result = $this->legalizationService->returnToValidacion($leg, $reason, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Legalización devuelta a Validación.')
            : $this->Flash->error($result->firstError() ?? 'Error al devolver.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function markExact(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $result = $this->legalizationService->markExact($leg, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Anticipo legalizado (caso exacto).')
            : $this->Flash->error($result->firstError() ?? 'Error al legalizar.');

        return $this->redirect(['action' => 'view', $id]);
    }
```

- [ ] **Step 4: Update `_actionToPermission` mapping**

In `src/Controller/AppController.php` `_actionToPermission()`, ensure the edit branch covers `'moveToRevision'` and `'returnToValidacion'`:

Append to the edit-branch tokens added in Task 2.5: `'moveToRevision', 'returnToValidacion'`.

- [ ] **Step 5: cs-check**

```bash
composer cs-check src/Controller/
```

---

### Task 3.3: Routes for Phase 2 actions

**Files:**
- Modify: `config/routes.php`

- [ ] **Step 1: Add routes BEFORE `$builder->fallbacks()`**

In the main `$routes->scope('/', …)` block, just before `$builder->fallbacks();`, add:

```php
        // Advances (Anticipos)
        $builder->connect(
            '/advances/link-invoices/{id}',
            ['controller' => 'Advances', 'action' => 'linkInvoices'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/unlink-invoice/{id}/{invoiceId}',
            ['controller' => 'Advances', 'action' => 'unlinkInvoice'],
            ['id' => '\d+', 'invoiceId' => '\d+', 'pass' => ['id', 'invoiceId']],
        );
        $builder->connect(
            '/advances/upload-relation-document/{id}',
            ['controller' => 'Advances', 'action' => 'uploadRelationDocument'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/move-to-revision/{id}',
            ['controller' => 'Advances', 'action' => 'moveToRevision'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/mark-signed/{id}',
            ['controller' => 'Advances', 'action' => 'markSigned'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/return-to-validacion/{id}',
            ['controller' => 'Advances', 'action' => 'returnToValidacion'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/mark-exact/{id}',
            ['controller' => 'Advances', 'action' => 'markExact'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 2: Verify routes load**

```bash
php bin/cake routes | grep advances
```
Expected: All 7 new routes listed.

---

### Task 3.4: Modal element + view-template wiring for Phase 2 (validacion/exacto)

**Files:**
- Create: `templates/element/advance_link_modal.php`
- Modify: `templates/Advances/view.php`

- [ ] **Step 1: Write the modal element**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 */
$invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
$candidates = $invoices->find()
    ->where([
        'document_type' => \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION,
        'advance_id IS' => null,
    ])
    ->contain(['Providers', 'Employees'])
    ->order(['issue_date' => 'DESC'])
    ->limit(200)
    ->all();
?>
<div class="modal fade" id="advanceLinkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <?= $this->Form->create(null, ['url' => ['controller' => 'Advances', 'action' => 'linkInvoices', $leg->id]]) ?>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vincular facturas-Legalización</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Solo se muestran facturas con tipo "Legalización" sin anticipo asignado.</p>
                <div class="table-responsive" style="max-height: 50vh;">
                    <table class="table table-sm table-hover">
                        <thead><tr><th></th><th>#</th><th>Beneficiario</th><th>Fecha</th><th class="text-end">Monto</th></tr></thead>
                        <tbody>
                        <?php foreach ($candidates as $c): ?>
                            <tr>
                                <td><?= $this->Form->checkbox('invoice_ids[]', ['value' => $c->id, 'hiddenField' => false]) ?></td>
                                <td><?= h($c->invoice_number ?: $c->id) ?></td>
                                <td><?= h($c->provider->name ?? ($c->employee->full_name ?? '—')) ?></td>
                                <td><?= $c->issue_date ? $c->issue_date->format('d/m/Y') : '—' ?></td>
                                <td class="text-end">$<?= number_format((float)$c->amount, 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn sgi-btn-primary">Vincular seleccionadas</button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
```

- [ ] **Step 2: Render the modal + add Phase-2 action buttons in `templates/Advances/view.php`**

At the bottom of the existing view template, before the closing container `</div>`:

```php
<?php if ($leg): ?>
    <?= $this->element('advance_link_modal', ['leg' => $leg]) ?>

    <?php if ($leg->status === \App\Constants\AdvanceConstants::STATUS_VALIDACION): ?>
        <div class="card mt-3">
            <div class="card-header">Validación</div>
            <div class="card-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'uploadRelationDocument', $leg->id], 'type' => 'file']) ?>
                    <div class="mb-2">
                        <label class="form-label">Relación de facturas (PDF)</label>
                        <?= $this->Form->control('relation_document', ['type' => 'file', 'class' => 'form-control', 'label' => false, 'required' => true]) ?>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">Adjuntar</button>
                <?= $this->Form->end() ?>
                <hr>
                <?= $this->Form->postLink(
                    'Pasar a Revisión y Firmas',
                    ['action' => 'moveToRevision', $leg->id],
                    ['class' => 'btn sgi-btn-primary', 'confirm' => '¿Pasar a Revisión y Firmas?'],
                ) ?>
            </div>
        </div>
    <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_REVISION_FIRMAS): ?>
        <div class="card mt-3">
            <div class="card-header">Revisión y Firmas</div>
            <div class="card-body">
                <?= $this->Form->postLink('Marcar como firmado', ['action' => 'markSigned', $leg->id], ['class' => 'btn btn-success me-2']) ?>
                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#advReturnModal">Devolver a Validación</button>
            </div>
        </div>
        <div class="modal fade" id="advReturnModal" tabindex="-1"><div class="modal-dialog">
            <?= $this->Form->create(null, ['url' => ['action' => 'returnToValidacion', $leg->id]]) ?>
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Devolver a Validación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <label class="form-label">Motivo *</label>
                    <?= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'required' => true, 'label' => false]) ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning">Devolver</button></div>
            </div>
            <?= $this->Form->end() ?>
        </div></div>
    <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_CONTABILIDAD): ?>
        <?php
        $diff = (new \App\Service\AdvanceLegalizationService())->getDifference($leg);
        $linkedTotal = (new \App\Service\AdvanceLegalizationService())->getLinkedTotal($leg);
        $advanceTotal = (float)$invoice->amount;
        ?>
        <div class="card mt-3">
            <div class="card-header">Contabilidad — cierre</div>
            <div class="card-body">
                <dl class="row">
                    <dt class="col-sm-3">Total Anticipo</dt><dd class="col-sm-9">$<?= number_format($advanceTotal, 0, ',', '.') ?></dd>
                    <dt class="col-sm-3">Total facturas vinculadas</dt><dd class="col-sm-9">$<?= number_format($linkedTotal, 0, ',', '.') ?></dd>
                    <dt class="col-sm-3">Diferencia</dt><dd class="col-sm-9">
                        <span class="badge bg-<?= abs($diff) < 0.005 ? 'success' : ($diff > 0 ? 'warning text-dark' : 'danger') ?>">
                            $<?= number_format($diff, 0, ',', '.') ?>
                        </span>
                    </dd>
                </dl>
                <?php if (abs($diff) < 0.005): ?>
                    <?= $this->Form->postLink('Marcar legalizada (caso exacto)', ['action' => 'markExact', $leg->id], ['class' => 'btn btn-success']) ?>
                <?php else: ?>
                    <p class="text-muted">Use Faltante o Sobrante (Phase 4 / Phase 5).</p>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_LEGALIZADA): ?>
        <div class="alert alert-success mt-3">
            <i class="bi bi-check-circle me-1"></i> Legalizada el <?= h($leg->legalized_at) ?> (caso <?= h($leg->case_type) ?>).
        </div>
    <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 3: Commit**

```bash
git add src/Controller/AdvancesController.php config/routes.php templates/element/advance_link_modal.php templates/Advances/view.php
git commit -m "feat(advances): Phase 2 actions for validacion/revision_firmas/exacto"
```

---

### Task 3.5: Tests for `AdvanceLegalizationService` (caso exacto path)

**Files:**
- Create: `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`

These integration tests use the live test DB (CakePHP tests by default reuse the test datasource configured in `app_local.php` / `phpunit.xml.dist`).

- [ ] **Step 1: Write a failing test for `initialize()`**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationServiceTest extends TestCase
{
    private AdvanceLegalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdvanceLegalizationService();
    }

    public function testInitializeRequiresAnticipoDocumentType(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'unit',
            'amount' => 1,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);

        $result = $this->service->initialize($invoice, 1);
        $this->assertFalse($result->success);
    }
}
```

- [ ] **Step 2: Run the test — expect it to PASS** (the `if document_type !== Anticipo → fail` branch already exists)

```bash
composer test -- --filter=AdvanceLegalizationServiceTest
```
Expected: Green.

- [ ] **Step 3: Add a happy-path test that walks an Anticipo to legalizada via the exacto path**

Append:

```php
    public function testHappyPathExacto(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        // 1. Anticipo @ pagada
        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'Anticipo unit test',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);
        $this->assertTrue((bool)$invoices->save($advance), json_encode($advance->getErrors()));

        $init = $this->service->initialize($advance, 1);
        $this->assertTrue($init->success, $init->firstError() ?? '');

        $leg = $legTable->find()->where(['advance_invoice_id' => $advance->id])->firstOrFail();
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $leg->status);

        // 2. Linked Legalización invoice with same total
        $legInv = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'detail' => 'Legalization invoice',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $this->assertTrue((bool)$invoices->save($legInv), json_encode($legInv->getErrors()));

        $linked = $this->service->linkInvoices($leg, [$legInv->id], 1);
        $this->assertTrue($linked->success, $linked->firstError() ?? '');
        $this->assertEqualsWithDelta(0.0, $this->service->getDifference($leg), 0.01);

        // 3. Skip the relation-doc check by injecting a signature row directly
        TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->save(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->newEntity([
                'legalization_id' => $leg->id,
                'document_path' => 'uploads/test.pdf',
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ]),
        );

        $r1 = $this->service->moveToRevisionFirmas($leg, 1);
        $this->assertTrue($r1->success, $r1->firstError() ?? '');

        $r2 = $this->service->markSigned($leg, 1);
        $this->assertTrue($r2->success, $r2->firstError() ?? '');

        $r3 = $this->service->markExact($leg, 1);
        $this->assertTrue($r3->success, $r3->firstError() ?? '');

        $reloaded = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $reloaded->status);
        $this->assertSame(AdvanceConstants::CASE_EXACTO, $reloaded->case_type);

        // Cleanup
        $legTable->deleteAll(['id' => $leg->id]);
        $invoices->deleteAll(['id IN' => [$advance->id, $legInv->id]]);
    }
```

- [ ] **Step 4: Run tests**

```bash
composer test -- --filter=AdvanceLegalizationServiceTest
```
Expected: Both tests pass. If `operation_center_id`/`expense_type_id` 1 don't exist in the test DB, replace with valid IDs from `operation_centers`/`expense_types`.

- [ ] **Step 5: Commit**

```bash
git add tests/TestCase/Service/AdvanceLegalizationServiceTest.php
git commit -m "test(advances): legalization service happy path - caso exacto"
```

---

## Phase 4 — Caso Faltante (PR 4)

### Task 4.1: Service methods `registerShortage` + `confirmShortageReceipt`

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php`

- [ ] **Step 1: Append methods**

```php
    public function registerShortage(\App\Model\Entity\AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del faltante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_FALTANTE;
        $leg->shortage_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId);
    }

    /**
     * Tesorería confirms the beneficiary's deposit. Payload:
     *   - receipt_number (string)
     *   - received_at (Y-m-d)
     *   - receipt_file (UploadedFile, optional)
     */
    public function confirmShortageReceipt(\App\Model\Entity\AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_TESORERIA || $leg->case_type !== AdvanceConstants::CASE_FALTANTE) {
            return ServiceResult::fail('La legalización no está esperando consignación de faltante.');
        }
        $number = trim((string)($data['receipt_number'] ?? ''));
        if ($number === '') {
            return ServiceResult::fail('El número de comprobante es obligatorio.');
        }

        $leg->shortage_receipt_number = $number;
        $leg->shortage_received_at = !empty($data['received_at']) ? date('Y-m-d H:i:s', strtotime($data['received_at'])) : date('Y-m-d H:i:s');

        // Optional file
        if (!empty($data['receipt_file']) && $data['receipt_file'] instanceof \Laminas\Diactoros\UploadedFile) {
            /** @var \Laminas\Diactoros\UploadedFile $file */
            $file = $data['receipt_file'];
            $uploadDir = WWW_ROOT . 'uploads/advances/' . $leg->id;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = pathinfo($file->getClientFilename() ?? '', PATHINFO_EXTENSION) ?: 'pdf';
            $name = uniqid('shortage_') . '.' . $ext;
            $file->moveTo($uploadDir . DS . $name);
            $leg->shortage_receipt_path = 'uploads/advances/' . $leg->id . '/' . $name;
        }

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }
```

- [ ] **Step 2: cs-check**

```bash
composer cs-check src/Service/AdvanceLegalizationService.php
```

---

### Task 4.2: Controller endpoints + routes for shortage

**Files:**
- Modify: `src/Controller/AdvancesController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Add controller actions**

```php
    public function registerShortage(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $amount = (float)str_replace([',', '.'], ['.', ''], (string)$this->request->getData('shortage_amount'));
        $result = $this->legalizationService->registerShortage($leg, $amount, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Faltante registrado. La legalización pasó a Tesorería.')
            : $this->Flash->error($result->firstError() ?? 'Error al registrar faltante.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function confirmShortage(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $data = $this->request->getData();
        $data['receipt_file'] = $this->request->getUploadedFile('receipt_file');
        $result = $this->legalizationService->confirmShortageReceipt($leg, $data, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Consignación confirmada. Anticipo legalizado.')
            : $this->Flash->error($result->firstError() ?? 'Error al confirmar consignación.');

        return $this->redirect(['action' => 'view', $id]);
    }
```

- [ ] **Step 2: Add routes (before `$builder->fallbacks()`)**

```php
        $builder->connect(
            '/advances/register-shortage/{id}',
            ['controller' => 'Advances', 'action' => 'registerShortage'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/confirm-shortage/{id}',
            ['controller' => 'Advances', 'action' => 'confirmShortage'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 3: cs-check**

```bash
composer cs-check src/Controller/AdvancesController.php
```

---

### Task 4.3: View — Contabilidad shortage button + Tesorería confirm form

**Files:**
- Modify: `templates/Advances/view.php`

- [ ] **Step 1: In the `STATUS_CONTABILIDAD` block, add the "Registrar faltante" form**

Inside the `<?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_CONTABILIDAD): ?>` branch in the existing view template, add — alongside the Marcar-legalizada button — a separate form rendered when `$diff > 0`:

```php
                <?php if ($diff > 0.005): ?>
                    <hr>
                    <?= $this->Form->create(null, ['url' => ['action' => 'registerShortage', $leg->id]]) ?>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Monto del faltante</label>
                                <input type="text" name="shortage_amount" class="form-control currency-input"
                                       value="<?= number_format($diff, 0, ',', '.') ?>" required>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-warning">Registrar faltante</button>
                            </div>
                        </div>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
```

- [ ] **Step 2: Add a `STATUS_TESORERIA` branch (only for case_type=faltante)**

Append to the existing `if/elseif` chain:

```php
    <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_TESORERIA && $leg->case_type === \App\Constants\AdvanceConstants::CASE_FALTANTE): ?>
        <div class="card mt-3">
            <div class="card-header">Tesorería — confirmar consignación del faltante</div>
            <div class="card-body">
                <?= $this->Form->create(null, ['url' => ['action' => 'confirmShortage', $leg->id], 'type' => 'file']) ?>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">N.º comprobante *</label>
                            <input type="text" name="receipt_number" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fecha</label>
                            <input type="text" name="received_at" class="form-control flatpickr-date">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Soporte (PDF/imagen)</label>
                            <input type="file" name="receipt_file" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3 text-end">
                        <button type="submit" class="btn btn-success">Confirmar consignación</button>
                    </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php src/Controller/AdvancesController.php config/routes.php templates/Advances/view.php
git commit -m "feat(advances): caso faltante - register + confirm shortage receipt"
```

---

### Task 4.4: Test — caso faltante happy path

**Files:**
- Modify: `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`

- [ ] **Step 1: Add a failing test**

```php
    public function testHappyPathFaltante(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'Anticipo faltante',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
        ]);
        $invoices->save($advance);

        $this->service->initialize($advance, 1);
        $leg = $legTable->find()->where(['advance_invoice_id' => $advance->id])->firstOrFail();

        $legInv = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'detail' => 'Partial', 'amount' => 600,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $invoices->save($legInv);
        $this->service->linkInvoices($leg, [$legInv->id], 1);

        TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->save(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->newEntity([
                'legalization_id' => $leg->id, 'document_path' => 'x.pdf',
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ]),
        );

        $this->service->moveToRevisionFirmas($leg, 1);
        $this->service->markSigned($leg, 1);

        $shortage = $this->service->registerShortage($leg, 400.0, 1);
        $this->assertTrue($shortage->success);
        $this->assertSame(AdvanceConstants::STATUS_TESORERIA, $legTable->get($leg->id)->status);
        $this->assertEqualsWithDelta(400.0, (float)$legTable->get($leg->id)->shortage_amount, 0.01);

        $confirm = $this->service->confirmShortageReceipt($leg, ['receipt_number' => 'CON-001'], 1);
        $this->assertTrue($confirm->success);

        $reloaded = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $reloaded->status);
        $this->assertSame(AdvanceConstants::CASE_FALTANTE, $reloaded->case_type);

        $legTable->deleteAll(['id' => $leg->id]);
        $invoices->deleteAll(['id IN' => [$advance->id, $legInv->id]]);
    }
```

- [ ] **Step 2: Run tests**

```bash
composer test -- --filter=AdvanceLegalizationServiceTest
```
Expected: Three passing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/TestCase/Service/AdvanceLegalizationServiceTest.php
git commit -m "test(advances): caso faltante happy path"
```

---

## Phase 5 — Caso Sobrante (PR 5)

### Task 5.1: Service `registerSurplus` + `registerRefundPayment` + close-on-authorize hook

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php`
- Modify: `src/Service/InvoicePaymentService.php`

- [ ] **Step 1: Append `registerSurplus` and `registerRefundPayment` methods**

```php
    public function registerSurplus(\App\Model\Entity\AdvanceLegalization $leg, float $amount, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_CONTABILIDAD) {
            return ServiceResult::fail('La legalización no está en Contabilidad.');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('El monto del sobrante debe ser mayor a cero.');
        }

        $leg->case_type = AdvanceConstants::CASE_SOBRANTE;
        $leg->surplus_amount = $amount;

        return $this->_setStatus($leg, AdvanceConstants::STATUS_TESORERIA, $userId);
    }

    /**
     * Crea un InvoicePayment con is_refund=true sobre el Invoice del Anticipo,
     * y deja la legalización en Tesorería esperando autorización.
     */
    public function registerRefundPayment(\App\Model\Entity\AdvanceLegalization $leg, array $data, int $userId): ServiceResult
    {
        if ($leg->status !== AdvanceConstants::STATUS_TESORERIA || $leg->case_type !== AdvanceConstants::CASE_SOBRANTE) {
            return ServiceResult::fail('La legalización no está esperando reintegro.');
        }
        if (!empty($leg->surplus_payment_id)) {
            return ServiceResult::fail('Ya existe un pago de reintegro registrado.');
        }
        if ($leg->surplus_amount === null) {
            return ServiceResult::fail('Monto del sobrante no definido.');
        }

        $payments = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoices = TableRegistry::getTableLocator()->get('Invoices');

        $connection = $payments->getConnection();

        return $connection->transactional(function () use ($leg, $data, $userId, $payments, $invoices) {
            $payment = $payments->newEntity([
                'invoice_id' => $leg->advance_invoice_id,
                'banking_entity_id' => $data['banking_entity_id'] ?? null,
                'amount' => (float)$leg->surplus_amount,
                'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
                'is_refund' => true,
                'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
                'authorized' => false,
                'created_by' => $userId,
            ]);

            if (!$payments->save($payment)) {
                return ServiceResult::fail('No se pudo crear el reintegro: ' . json_encode($payment->getErrors()));
            }

            // Move the underlying Anticipo Invoice back to autorizacion_pago for the Contador.
            $advance = $invoices->get($leg->advance_invoice_id);
            $advance->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
            $invoices->save($advance);

            $leg->surplus_payment_id = $payment->id;
            $leg->updated_by = $userId;
            TableRegistry::getTableLocator()->get('AdvanceLegalizations')->save($leg);

            return ServiceResult::ok($payment);
        });
    }

    /**
     * Called from InvoicePaymentService::authorizePayment when a refund payment is authorized.
     */
    public function closeOnRefundAuthorized(int $paymentId, int $userId): ServiceResult
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->find()->where(['surplus_payment_id' => $paymentId])->first();
        if (!$leg) {
            return ServiceResult::fail('No hay legalización vinculada al pago.');
        }
        if ($leg->status === AdvanceConstants::STATUS_LEGALIZADA) {
            return ServiceResult::ok($leg);
        }

        $leg->legalized_at = date('Y-m-d H:i:s');

        return $this->_setStatus($leg, AdvanceConstants::STATUS_LEGALIZADA, $userId);
    }
```

- [ ] **Step 2: Validate `is_refund` + close legalization in `InvoicePaymentService`**

In `src/Service/InvoicePaymentService.php` `registerPayment()`, **add a guard** at the top:

```php
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($invoiceId);

        if (!empty($paymentData['is_refund']) && $invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            return ServiceResult::fail('is_refund solo es válido en pagos de Anticipos.');
        }
```

In `authorizePayment()`, after the `$this->historyService->recordStatusChange(...)` call (around line 137), add:

```php
        if ((bool)($payment->is_refund ?? false)) {
            $this->advanceLegalizationService->closeOnRefundAuthorized($payment->id, $authorizedBy);
        }
```

- [ ] **Step 3: cs-check**

```bash
composer cs-check src/Service/AdvanceLegalizationService.php src/Service/InvoicePaymentService.php
```

---

### Task 5.2: Controller endpoints + routes for surplus

**Files:**
- Modify: `src/Controller/AdvancesController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Add controller actions**

```php
    public function registerSurplus(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $amount = (float)str_replace([',', '.'], ['.', ''], (string)$this->request->getData('surplus_amount'));
        $result = $this->legalizationService->registerSurplus($leg, $amount, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Sobrante registrado. La legalización pasó a Tesorería.')
            : $this->Flash->error($result->firstError() ?? 'Error al registrar sobrante.');

        return $this->redirect(['action' => 'view', $id]);
    }

    public function registerRefund(?int $id = null): \Cake\Http\Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        $data = $this->request->getData();
        $result = $this->legalizationService->registerRefundPayment($leg, $data, (int)$this->_getCurrentUser()->id);
        $result->success
            ? $this->Flash->success('Reintegro registrado. Pendiente de autorización por el Contador.')
            : $this->Flash->error($result->firstError() ?? 'Error al registrar reintegro.');

        return $this->redirect(['action' => 'view', $id]);
    }
```

- [ ] **Step 2: Add routes**

```php
        $builder->connect(
            '/advances/register-surplus/{id}',
            ['controller' => 'Advances', 'action' => 'registerSurplus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/register-refund/{id}',
            ['controller' => 'Advances', 'action' => 'registerRefund'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

---

### Task 5.3: View — surplus button + refund form

**Files:**
- Modify: `templates/Advances/view.php`

- [ ] **Step 1: Inside the `STATUS_CONTABILIDAD` branch, add a surplus form when `$diff < 0`**

```php
                <?php if ($diff < -0.005): ?>
                    <hr>
                    <?= $this->Form->create(null, ['url' => ['action' => 'registerSurplus', $leg->id]]) ?>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Monto del sobrante</label>
                                <input type="text" name="surplus_amount" class="form-control currency-input"
                                       value="<?= number_format(abs($diff), 0, ',', '.') ?>" required>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-danger">Registrar sobrante (reintegro a empleado)</button>
                            </div>
                        </div>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
```

- [ ] **Step 2: Add a `STATUS_TESORERIA` branch for case_type=sobrante**

```php
    <?php elseif ($leg->status === \App\Constants\AdvanceConstants::STATUS_TESORERIA && $leg->case_type === \App\Constants\AdvanceConstants::CASE_SOBRANTE): ?>
        <div class="card mt-3">
            <div class="card-header">Tesorería — registrar reintegro al beneficiario</div>
            <div class="card-body">
                <?php if ($leg->surplus_payment_id): ?>
                    <div class="alert alert-info mb-0">
                        Reintegro #<?= h($leg->surplus_payment_id) ?> registrado. Esperando autorización por el Contador en
                        <?= $this->Html->link('Aut. Pago', ['controller' => 'Invoices', 'action' => 'edit', $leg->advance_invoice_id]) ?>.
                    </div>
                <?php else: ?>
                    <?php
                    $bankingEntities = \Cake\ORM\TableRegistry::getTableLocator()->get('BankingEntities')->find('list')->all();
                    ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'registerRefund', $leg->id]]) ?>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label">Entidad bancaria *</label>
                                <?= $this->Form->select('banking_entity_id', $bankingEntities, ['class' => 'form-select select2', 'required' => true, 'empty' => '— Seleccione —']) ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fecha *</label>
                                <input type="text" name="payment_date" class="form-control flatpickr-date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Monto</label>
                                <input type="text" class="form-control" value="$<?= number_format((float)$leg->surplus_amount, 0, ',', '.') ?>" disabled>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="submit" class="btn btn-danger">Registrar reintegro</button>
                        </div>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
        </div>
```

- [ ] **Step 3: Commit**

```bash
git add src/Service/ src/Controller/AdvancesController.php config/routes.php templates/Advances/view.php
git commit -m "feat(advances): caso sobrante - register + refund payment + close on authorize"
```

---

### Task 5.4: Test — caso sobrante happy path

**Files:**
- Modify: `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`

- [ ] **Step 1: Add a test that walks the surplus flow including authorization closing it**

```php
    public function testHappyPathSobrante(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $payments = TableRegistry::getTableLocator()->get('InvoicePayments');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'Anticipo sobrante',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
        ]);
        $invoices->save($advance);

        $this->service->initialize($advance, 1);
        $leg = $legTable->find()->where(['advance_invoice_id' => $advance->id])->firstOrFail();

        $legInv = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'detail' => 'Over budget', 'amount' => 1500,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $invoices->save($legInv);
        $this->service->linkInvoices($leg, [$legInv->id], 1);

        TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->save(
            TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures')->newEntity([
                'legalization_id' => $leg->id, 'document_path' => 'x.pdf',
                'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
            ]),
        );
        $this->service->moveToRevisionFirmas($leg, 1);
        $this->service->markSigned($leg, 1);

        $surplus = $this->service->registerSurplus($leg, 500.0, 1);
        $this->assertTrue($surplus->success, $surplus->firstError() ?? '');

        $bankingEntityId = TableRegistry::getTableLocator()->get('BankingEntities')->find()->firstOrFail()->id;
        $refund = $this->service->registerRefundPayment(
            $legTable->get($leg->id),
            ['banking_entity_id' => $bankingEntityId, 'payment_date' => date('Y-m-d')],
            1,
        );
        $this->assertTrue($refund->success, $refund->firstError() ?? '');
        $payment = $refund->data;
        $this->assertTrue((bool)$payment->is_refund);

        // Authorize as Contador → should close the legalization.
        $paymentService = new \App\Service\InvoicePaymentService();
        $paymentService->authorizePayment($payment->id, 1);

        $reloaded = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_LEGALIZADA, $reloaded->status);
        $this->assertSame(AdvanceConstants::CASE_SOBRANTE, $reloaded->case_type);

        $payments->deleteAll(['invoice_id' => $advance->id]);
        $legTable->deleteAll(['id' => $leg->id]);
        $invoices->deleteAll(['id IN' => [$advance->id, $legInv->id]]);
    }
```

- [ ] **Step 2: Run tests**

```bash
composer test -- --filter=AdvanceLegalizationServiceTest
```
Expected: Four tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/TestCase/Service/AdvanceLegalizationServiceTest.php
git commit -m "test(advances): caso sobrante happy path including refund authorization close"
```

---

## Phase 6 — Polish, Docs, Final Verification (PR 6)

### Task 6.1: Failure-path tests

**Files:**
- Modify: `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`

- [ ] **Step 1: Add tests for the design's "Tests críticos" failure cases**

Append:

```php
    public function testCannotAdvanceToRevisionWithoutLinkedInvoices(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'edge', 'amount' => 1, 'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
        ]);
        $invoices->save($advance);
        $this->service->initialize($advance, 1);
        $leg = $legTable->find()->where(['advance_invoice_id' => $advance->id])->firstOrFail();

        $r = $this->service->moveToRevisionFirmas($leg, 1);
        $this->assertFalse($r->success);

        $legTable->deleteAll(['id' => $leg->id]);
        $invoices->deleteAll(['id' => $advance->id]);
    }

    public function testCannotLinkLegalizationAlreadyLinkedElsewhere(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $a1 = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO, 'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'A1', 'amount' => 1, 'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
        ]);
        $a2 = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO, 'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'A2', 'amount' => 1, 'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
        ]);
        $invoices->save($a1); $invoices->save($a2);

        $this->service->initialize($a1, 1);
        $this->service->initialize($a2, 1);
        $leg1 = $legTable->find()->where(['advance_invoice_id' => $a1->id])->firstOrFail();
        $leg2 = $legTable->find()->where(['advance_invoice_id' => $a2->id])->firstOrFail();

        $li = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'detail' => 'shared', 'amount' => 100, 'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $invoices->save($li);

        $r1 = $this->service->linkInvoices($leg1, [$li->id], 1);
        $this->assertSame(1, $r1->data['linked'] ?? 0);

        $r2 = $this->service->linkInvoices($leg2, [$li->id], 1);
        $this->assertSame(0, $r2->data['linked'] ?? 0); // Filter on advance_id IS NULL prevents the relink

        $invoices->deleteAll(['id' => $li->id]);
        $legTable->deleteAll(['id IN' => [$leg1->id, $leg2->id]]);
        $invoices->deleteAll(['id IN' => [$a1->id, $a2->id]]);
    }

    public function testCannotRefundOnNonAnticipo(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA, 'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'normal', 'amount' => 100, 'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1, 'expense_type_id' => 1, 'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ]);
        $invoices->save($invoice);

        $bankingEntityId = TableRegistry::getTableLocator()->get('BankingEntities')->find()->firstOrFail()->id;
        $service = new \App\Service\InvoicePaymentService();
        $r = $service->registerPayment($invoice->id, [
            'banking_entity_id' => $bankingEntityId,
            'amount' => 50,
            'payment_date' => date('Y-m-d'),
            'is_refund' => true,
        ], 1);
        $this->assertFalse($r->success);

        $invoices->deleteAll(['id' => $invoice->id]);
    }
```

- [ ] **Step 2: Run tests**

```bash
composer test
```
Expected: All tests pass.

---

### Task 6.2: Cleanup, cs-fix and final commit

**Files:**
- All

- [ ] **Step 1: Run cs-fix across the entire diff**

```bash
composer cs-fix
```

- [ ] **Step 2: Verify cs-check + tests pass**

```bash
composer check
```
Expected: Tests pass and cs-check is clean.

- [ ] **Step 3: Manually walk all 3 cases through the UI** (server running)

For each case (exact, faltante, sobrante), perform a full end-to-end walkthrough as described in Task 2.10 plus the Phase 2 actions:
1. Create Anticipo, advance through pipeline, get to `pagada`.
2. Create one or more Legalización-Invoices (via `Invoices` controller), set them to at least `contabilidad`.
3. From `/advances/view/<id>`: link them, upload PDF, advance to `revision_firmas`, mark signed, then exact/shortage/surplus.
4. For shortage: confirm receipt as Tesorería. For surplus: register refund, then authorize the refund payment as Contador (in the Invoice's `autorizacion_pago` view).
5. Verify each path lands at `legalizada` with the correct `case_type`.

If anything misbehaves: fix → cs-fix → tests → commit.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore(advances): cs-fix and final cleanup"
```

---

## Self-Review Notes

**Coverage check** (against `docs/plans/2026-04-28-anticipos-design.md`):
- Decisions §1–§11 → Tasks 2.1, 1.4, 1.7, 2.4, 3.1, 3.1 (markExact), 5.1 (registerRefundPayment), 4.1 (confirmShortageReceipt), 2.1 (auto-approval), 2.2 (auto-init), 3.1 (moveToRevisionFirmas guard).
- Schema changes → Tasks 1.2, 1.3, 1.4, 1.5.
- Phase 1 pipeline differences → Tasks 2.1, 2.3, 2.4.
- Phase 2 statuses (validacion → revision_firmas → contabilidad → tesoreria/legalizada) → Tasks 3.1, 4.1, 5.1.
- UX vinculación + banner → Tasks 3.4, 2.9.
- Roles × estados matrix → Task 1.6 (seed) + Task 2.5 (module registration). Per-action role enforcement is delegated to existing `AppController::_enforcePermission` machinery.
- Servicios y archivos § archivos NUEVOS → Tasks 1.1, 1.7–1.10, 2.6, 2.7, 3.4, 1.2–1.6.
- Servicios y archivos § archivos a MODIFICAR → Tasks 0.1 (constants verified), 2.2, 2.3+2.4, 5.1, 2.8, 2.5, 2.5, 2.8 (sidebar), 3.3+4.2+5.2 (routes), 2.9 (banner), 1.11.
- Migraciones → Tasks 1.2–1.6.
- Plan de implementación PRs → Phases 1–6.
- Tests críticos → Tasks 3.5, 4.4, 5.4, 6.1.
- Fuera de alcance items are explicitly excluded.

**Out-of-scope items not implemented** (matches design § "Fuera de alcance"):
- Reapertura de legalizaciones cerradas.
- Movimiento atómico entre anticipos.
- Aprobación de área configurable para Anticipos.
- Reportes consolidados.
- Notificaciones automáticas de estado.
