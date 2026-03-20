# Legalizations Module Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the Legalizations module (replica of Petty Cash) that groups invoices of type "Legalización" through a 4-state pipeline, plus make the `code` field optional/manual in both Petty Cash and Legalizations.

**Architecture:** Mirror the Petty Cash module structure — separate controller, service, models, constants, and views — filtering by `document_type = 'Legalización'` instead of `'Caja menor'`. Also modify Petty Cash to remove auto-generated codes and make `code` optional.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MariaDB, Bootstrap 5, Select2, Flatpickr

---

### Task 1: Constants — LegalizationConstants

**Files:**
- Create: `src/Constants/LegalizationConstants.php`

**Step 1: Create the constants file**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class LegalizationConstants
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADO = 'pagado';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADO,
    ];

    public const STATUS_LABELS = [
        'agrupacion' => 'Agrupación',
        'contabilidad' => 'Contabilidad',
        'tesoreria' => 'Tesorería',
        'pagado' => 'Pagado',
    ];

    public const STATUS_ICONS = [
        'agrupacion' => 'bi-collection',
        'contabilidad' => 'bi-calculator',
        'tesoreria' => 'bi-bank',
        'pagado' => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        'agrupacion' => 'contabilidad',
        'contabilidad' => 'tesoreria',
        'tesoreria' => 'pagado',
        'pagado' => null,
    ];
}
```

**Step 2: Commit**

```bash
git add src/Constants/LegalizationConstants.php
git commit -m "feat: add LegalizationConstants for legalizations module"
```

---

### Task 2: Database Migrations

**Files:**
- Create: `config/Migrations/20260320000001_CreateLegalizationRecords.php`
- Create: `config/Migrations/20260320000002_AddLegalizationRecordIdToInvoices.php`
- Create: `config/Migrations/20260320000003_CreateLegalizationDocuments.php`
- Create: `config/Migrations/20260320000004_CreateLegalizationObservations.php`
- Create: `config/Migrations/20260320000005_AddLegalizationPermissions.php`
- Create: `config/Migrations/20260320000006_MakePettyCashCodeOptional.php`

**Step 1: Create migration for legalization_records table**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationRecords extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_records')) {
            $table = $this->table('legalization_records');
            $table
                ->addColumn('code', 'string', ['limit' => 30, 'null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'agrupacion'])
                ->addColumn('total_amount', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
                ->addColumn('accrued', 'boolean', ['default' => false])
                ->addColumn('accrual_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('ready_for_payment', 'string', ['limit' => 50, 'null' => true, 'default' => null])
                ->addColumn('payment_status', 'string', ['limit' => 30, 'null' => true, 'default' => null])
                ->addColumn('payment_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('notes', 'text', ['null' => true, 'default' => null])
                ->addColumn('created_by', 'integer', ['signed' => true])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_records')) {
            $this->table('legalization_records')->drop()->save();
        }
    }
}
```

**Step 2: Create migration to add legalization_record_id to invoices**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddLegalizationRecordIdToInvoices extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoices');
        if (!$table->hasColumn('legalization_record_id')) {
            $table
                ->addColumn('legalization_record_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                    'after' => 'petty_cash_record_id',
                ])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoices');
        if ($table->hasForeignKey('legalization_record_id')) {
            $table->dropForeignKey('legalization_record_id');
        }
        if ($table->hasColumn('legalization_record_id')) {
            $table->removeColumn('legalization_record_id')->update();
        }
    }
}
```

**Step 3: Create migration for legalization_documents**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationDocuments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_documents')) {
            $table = $this->table('legalization_documents');
            $table
                ->addColumn('legalization_record_id', 'integer', ['signed' => true])
                ->addColumn('document_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
                ->addColumn('file_path', 'string', ['limit' => 255])
                ->addColumn('file_name', 'string', ['limit' => 255])
                ->addColumn('file_size', 'integer', ['null' => true, 'default' => null])
                ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
                ->addColumn('uploaded_by', 'integer', ['null' => true, 'default' => null, 'signed' => true])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_documents')) {
            $this->table('legalization_documents')->drop()->save();
        }
    }
}
```

**Step 4: Create migration for legalization_observations**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateLegalizationObservations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('legalization_observations')) {
            $table = $this->table('legalization_observations');
            $table
                ->addColumn('legalization_record_id', 'integer', ['signed' => true])
                ->addColumn('user_id', 'integer', ['signed' => true])
                ->addColumn('message', 'text')
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('legalization_record_id', 'legalization_records', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('legalization_observations')) {
            $this->table('legalization_observations')->drop()->save();
        }
    }
}
```

**Step 5: Create migration for legalization permissions**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddLegalizationPermissions extends BaseMigration
{
    public function up(): void
    {
        $rolesTable = $this->table('roles');
        $rows = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($rows as $row) {
            $isAdmin = $row['name'] === 'Administrador';
            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
                 VALUES (%d, 'legalizations', %d, %d, %d, %d)",
                $row['id'],
                1,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'legalizations'");
    }
}
```

**Step 6: Create migration to make petty_cash_records.code optional**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MakePettyCashCodeOptional extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('petty_cash_records');
        $table->changeColumn('code', 'string', ['limit' => 30, 'null' => true, 'default' => null])
              ->update();
    }

    public function down(): void
    {
        $table = $this->table('petty_cash_records');
        $table->changeColumn('code', 'string', ['limit' => 30, 'null' => false])
              ->update();
    }
}
```

**Step 7: Run migrations**

```bash
bin/cake migrations migrate
```

**Step 8: Commit**

```bash
git add config/Migrations/
git commit -m "feat: add database migrations for legalizations module and make petty cash code optional"
```

---

### Task 3: Entities

**Files:**
- Create: `src/Model/Entity/LegalizationRecord.php`
- Create: `src/Model/Entity/LegalizationDocument.php`
- Create: `src/Model/Entity/LegalizationObservation.php`

**Step 1: Create LegalizationRecord entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\LegalizationConstants;
use Cake\ORM\Entity;

class LegalizationRecord extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'status' => true,
        'total_amount' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'notes' => true,
        'created_by' => true,
    ];

    public function isAgrupacion(): bool
    {
        return ($this->status ?? '') === LegalizationConstants::STATUS_AGRUPACION;
    }

    public function isContabilidad(): bool
    {
        return ($this->status ?? '') === LegalizationConstants::STATUS_CONTABILIDAD;
    }

    public function isTesoreria(): bool
    {
        return ($this->status ?? '') === LegalizationConstants::STATUS_TESORERIA;
    }

    public function isPagado(): bool
    {
        return ($this->status ?? '') === LegalizationConstants::STATUS_PAGADO;
    }
}
```

**Step 2: Create LegalizationDocument entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LegalizationDocument extends Entity
{
    protected array $_accessible = [
        'legalization_record_id' => true,
        'document_type' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
    ];
}
```

**Step 3: Create LegalizationObservation entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class LegalizationObservation extends Entity
{
    protected array $_accessible = [
        'legalization_record_id' => true,
        'user_id' => true,
        'message' => true,
    ];
}
```

**Step 4: Commit**

```bash
git add src/Model/Entity/LegalizationRecord.php src/Model/Entity/LegalizationDocument.php src/Model/Entity/LegalizationObservation.php
git commit -m "feat: add Legalization entities"
```

---

### Task 4: Table Models

**Files:**
- Create: `src/Model/Table/LegalizationRecordsTable.php`
- Create: `src/Model/Table/LegalizationDocumentsTable.php`
- Create: `src/Model/Table/LegalizationObservationsTable.php`

**Step 1: Create LegalizationRecordsTable**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\LegalizationConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LegalizationRecordsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('legalization_records');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('Invoices', [
            'foreignKey' => 'legalization_record_id',
        ]);
        $this->hasMany('LegalizationDocuments', [
            'foreignKey' => 'legalization_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('LegalizationObservations', [
            'foreignKey' => 'legalization_record_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 30)
            ->allowEmptyString('code');

        $validator
            ->scalar('status')
            ->inList('status', LegalizationConstants::STATUSES);

        $validator
            ->decimal('total_amount');

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

**Step 2: Create LegalizationDocumentsTable**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LegalizationDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('legalization_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('LegalizationRecords', [
            'foreignKey' => 'legalization_record_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 255)
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->notEmptyString('file_name');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('legalization_record_id', 'LegalizationRecords'), ['errorField' => 'legalization_record_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by', 'allowNullableNulls' => true]);

        return $rules;
    }
}
```

**Step 3: Create LegalizationObservationsTable**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class LegalizationObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('legalization_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('LegalizationRecords', [
            'foreignKey' => 'legalization_record_id',
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
            ->integer('legalization_record_id')
            ->requirePresence('legalization_record_id', 'create')
            ->notEmptyString('legalization_record_id');

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
        $rules->add($rules->existsIn('legalization_record_id', 'LegalizationRecords'), ['errorField' => 'legalization_record_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
```

**Step 4: Commit**

```bash
git add src/Model/Table/LegalizationRecordsTable.php src/Model/Table/LegalizationDocumentsTable.php src/Model/Table/LegalizationObservationsTable.php
git commit -m "feat: add Legalization table models"
```

---

### Task 5: Services

**Files:**
- Create: `src/Service/LegalizationService.php`
- Create: `src/Service/LegalizationDocumentService.php`

**Step 1: Create LegalizationService**

Replica of `PettyCashService` with these changes:
- No `generateCode()` method
- `document_type` filter = `'Legalización'`
- FK field = `legalization_record_id`
- Uses `LegalizationConstants`

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\LegalizationConstants;
use App\Model\Entity\LegalizationRecord;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

class LegalizationService
{
    public function validateGrouping(array $invoiceIds): array
    {
        $errors = [];
        if (empty($invoiceIds)) {
            return ['Debe seleccionar al menos una factura.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['Invoices.id IN' => $invoiceIds])
            ->all();

        $foundIds = [];
        foreach ($invoices as $invoice) {
            $foundIds[] = $invoice->id;

            if ($invoice->document_type !== 'Legalización') {
                $errors[] = sprintf(
                    'La factura #%s no es de tipo "Legalización".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if ($invoice->pipeline_status !== 'aprobacion') {
                $errors[] = sprintf(
                    'La factura #%s no está en estado "aprobación".',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
            if (!empty($invoice->legalization_record_id)) {
                $errors[] = sprintf(
                    'La factura #%s ya pertenece a otro registro de Legalización.',
                    $invoice->invoice_number ?? $invoice->id,
                );
            }
        }

        $missingIds = array_diff($invoiceIds, $foundIds);
        foreach ($missingIds as $missingId) {
            $errors[] = sprintf('La factura con ID %d no fue encontrada.', $missingId);
        }

        return $errors;
    }

    public function addInvoices(LegalizationRecord $record, array $invoiceIds): array
    {
        $errors = $this->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        foreach ($invoiceIds as $invoiceId) {
            $invoicesTable->updateAll(
                ['legalization_record_id' => $record->id],
                ['id' => $invoiceId],
            );
        }

        $this->calculateAndSaveTotal($record);

        return [];
    }

    public function removeInvoice(LegalizationRecord $record, int $invoiceId): bool
    {
        if (!$record->isAgrupacion()) {
            return false;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicesTable->updateAll(
            ['legalization_record_id' => null],
            ['id' => $invoiceId, 'legalization_record_id' => $record->id],
        );

        $this->calculateAndSaveTotal($record);

        return true;
    }

    public function calculateAndSaveTotal(LegalizationRecord $record): void
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $result = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->select(['total' => $invoicesTable->find()->func()->sum('amount')])
            ->first();

        $table = TableRegistry::getTableLocator()->get('LegalizationRecords');
        $record->total_amount = (float)($result->total ?? 0);
        $table->save($record);
    }

    public function advanceStatus(LegalizationRecord $record, int $userId): array
    {
        $currentStatus = $record->status;
        $nextStatus = LegalizationConstants::TRANSITIONS[$currentStatus] ?? null;

        if ($nextStatus === null) {
            return ['success' => false, 'error' => 'Este registro ya está en su estado final.'];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['success' => false, 'error' => 'El registro debe tener al menos una factura agrupada.'];
        }

        $validationErrors = $this->validateLegalizationTransition($currentStatus, $invoices, $record);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'No se puede avanzar. ' . implode('. ', $validationErrors),
            ];
        }

        $connection = $invoicesTable->getConnection();

        return $connection->transactional(function () use ($record, $nextStatus, $currentStatus, $invoicesTable) {
            $today = date('Y-m-d');
            $updateData = [];

            if ($nextStatus === LegalizationConstants::STATUS_CONTABILIDAD) {
                $updateData = [
                    'pipeline_status' => 'contabilidad',
                ];
            } elseif ($nextStatus === LegalizationConstants::STATUS_TESORERIA) {
                $updateData = [
                    'pipeline_status' => 'tesoreria',
                    'accrued' => (bool)$record->accrued,
                    'accrual_date' => $record->accrual_date ?? $today,
                    'ready_for_payment' => $record->ready_for_payment,
                ];
            } elseif ($nextStatus === LegalizationConstants::STATUS_PAGADO) {
                $updateData = [
                    'pipeline_status' => 'pagada',
                    'payment_status' => $record->payment_status ?? InvoiceConstants::PAYMENT_FULL,
                    'payment_date' => $record->payment_date ?? $today,
                ];
            }

            if (!empty($updateData)) {
                $invoicesTable->updateAll(
                    $updateData,
                    ['legalization_record_id' => $record->id],
                );
            }

            $table = TableRegistry::getTableLocator()->get('LegalizationRecords');
            $record->status = $nextStatus;
            $table->save($record);

            return [
                'success' => true,
                'nextStatus' => $nextStatus,
            ];
        });
    }

    public function getTransitionErrors(LegalizationRecord $record): array
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoices = $invoicesTable->find()
            ->where(['legalization_record_id' => $record->id])
            ->all()
            ->toArray();

        if (empty($invoices)) {
            return ['El registro debe tener al menos una factura agrupada.'];
        }

        return $this->validateLegalizationTransition($record->status, $invoices, $record);
    }

    private function validateLegalizationTransition(string $fromStatus, array $invoices, LegalizationRecord $record): array
    {
        $errors = [];

        switch ($fromStatus) {
            case LegalizationConstants::STATUS_AGRUPACION:
                break;

            case LegalizationConstants::STATUS_CONTABILIDAD:
                if (empty($record->accrued)) {
                    $errors[] = 'El registro debe estar marcado como Causado.';
                }
                if (empty($record->ready_for_payment)) {
                    $errors[] = 'Debe seleccionar "Lista para Pago".';
                }
                break;

            case LegalizationConstants::STATUS_TESORERIA:
                if (empty($record->payment_status)) {
                    $errors[] = 'Debe seleccionar un Estado de Pago.';
                }
                if (empty($record->payment_date)) {
                    $errors[] = 'Debe ingresar una Fecha de Pago.';
                }
                break;
        }

        return $errors;
    }

    public function getAvailableInvoices(array $filters = []): SelectQuery
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $query = $invoicesTable->find()
            ->contain(['Providers', 'OperationCenters'])
            ->where([
                'Invoices.document_type' => 'Legalización',
                'Invoices.pipeline_status' => 'aprobacion',
                'Invoices.legalization_record_id IS' => null,
            ])
            ->order(['Invoices.issue_date' => 'ASC']);

        if (!empty($filters['date_from'])) {
            $query->where(['Invoices.issue_date >=' => $filters['date_from']]);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Invoices.issue_date <=' => $filters['date_to']]);
        }
        if (!empty($filters['operation_center_id'])) {
            $query->where(['Invoices.operation_center_id' => $filters['operation_center_id']]);
        }

        return $query;
    }

    public function canDelete(LegalizationRecord $record): bool
    {
        return $record->isAgrupacion();
    }
}
```

**Step 2: Create LegalizationDocumentService**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class LegalizationDocumentService
{
    private const MAX_DOC_SIZE = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_DOC_MIMES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function uploadDocument(
        int $recordId,
        UploadedFile $file,
        ?int $uploadedBy,
        ?string $documentType = null,
    ): object|string {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }

        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 10MB.';
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_DOC_MIMES)) {
            return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'legalizations' . DS . $recordId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid('leg_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get('LegalizationDocuments');
        $document = $documentsTable->newEntity([
            'legalization_record_id' => $recordId,
            'document_type' => $documentType,
            'file_path' => 'uploads/legalizations/' . $recordId . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]);

        if (!$documentsTable->save($document)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return 'No se pudo guardar el documento.';
        }

        return $document;
    }

    public function deleteDocument(int $documentId): bool
    {
        $documentsTable = TableRegistry::getTableLocator()->get('LegalizationDocuments');
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }
}
```

**Step 3: Commit**

```bash
git add src/Service/LegalizationService.php src/Service/LegalizationDocumentService.php
git commit -m "feat: add LegalizationService and LegalizationDocumentService"
```

---

### Task 6: Controller

**Files:**
- Create: `src/Controller/LegalizationRecordsController.php`

**Step 1: Create LegalizationRecordsController**

Replica of `PettyCashRecordsController` with:
- Uses `LegalizationService` / `LegalizationDocumentService`
- Uses `LegalizationConstants`
- References `legalizations` module for permissions
- No `generateCode()` call — accepts `code` from form data
- All FK references use `legalization_record_id`

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\LegalizationConstants;
use App\Service\LegalizationDocumentService;
use App\Service\LegalizationService;

class LegalizationRecordsController extends AppController
{
    private LegalizationService $legalizationService;
    private LegalizationDocumentService $documentService;

    public function initialize(): void
    {
        parent::initialize();
        $this->legalizationService = new LegalizationService();
        $this->documentService = new LegalizationDocumentService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    public function index()
    {
        $query = $this->LegalizationRecords->find()
            ->contain(['CreatedByUsers', 'Invoices'])
            ->order(['LegalizationRecords.created' => 'DESC']);

        $params = $this->request->getQueryParams();

        if (!empty($params['code'])) {
            $query->where(['LegalizationRecords.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['LegalizationRecords.status' => $params['status']]);
        }
        if (!empty($params['date_from'])) {
            $query->where(['LegalizationRecords.created >=' => $params['date_from']]);
        }
        if (!empty($params['date_to'])) {
            $query->where(['LegalizationRecords.created <=' => $params['date_to'] . ' 23:59:59']);
        }

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $records = $this->paginate($query);
        $this->set(compact('records'));
    }

    public function view($id = null)
    {
        $record = $this->LegalizationRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers'],
            'LegalizationDocuments' => [
                'UploadedByUsers',
                'sort' => ['LegalizationDocuments.created' => 'DESC'],
            ],
            'LegalizationObservations' => [
                'Users',
                'sort' => ['LegalizationObservations.created' => 'ASC'],
            ],
        ]);

        $this->set(compact('record'));
    }

    public function add()
    {
        $record = $this->LegalizationRecords->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();

            $invoiceIds = array_map('intval', array_filter((array)($data['invoice_ids'] ?? [])));

            $record = $this->LegalizationRecords->patchEntity($record, [
                'code' => !empty($data['code']) ? $data['code'] : null,
                'status' => LegalizationConstants::STATUS_AGRUPACION,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($this->LegalizationRecords->save($record)) {
                if (!empty($invoiceIds)) {
                    $errors = $this->legalizationService->addInvoices($record, $invoiceIds);
                    foreach ($errors as $err) {
                        $this->Flash->warning($err);
                    }
                }

                $this->Flash->success('Registro de Legalización creado exitosamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }

            $this->Flash->error('No se pudo crear el registro. Intente de nuevo.');
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->legalizationService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'groupFilters'));
    }

    public function edit($id = null)
    {
        $record = $this->LegalizationRecords->get($id, contain: [
            'CreatedByUsers',
            'Invoices' => ['Providers', 'OperationCenters'],
            'LegalizationDocuments' => [
                'UploadedByUsers',
                'sort' => ['LegalizationDocuments.created' => 'DESC'],
            ],
            'LegalizationObservations' => [
                'Users',
                'sort' => ['LegalizationObservations.created' => 'ASC'],
            ],
        ]);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $data = $this->request->getData();
            $patchData = [];

            // Code: editable in all non-final states
            if (!$record->isPagado()) {
                $patchData['code'] = !empty($data['code']) ? $data['code'] : null;
            }

            // Notes: editable in agrupacion and contabilidad
            if ($record->isAgrupacion() || $record->isContabilidad()) {
                $patchData['notes'] = $data['notes'] ?? $record->notes;
            }

            // Accounting fields: editable in contabilidad
            if ($record->isContabilidad()) {
                $patchData['accrued'] = !empty($data['accrued']);
                if (!empty($data['accrued']) && empty($record->accrual_date)) {
                    $patchData['accrual_date'] = date('Y-m-d');
                } elseif (empty($data['accrued'])) {
                    $patchData['accrual_date'] = null;
                }
                $patchData['ready_for_payment'] = $data['ready_for_payment'] ?? null;
            }

            // Treasury fields: editable in tesoreria
            if ($record->isTesoreria()) {
                $patchData['payment_status'] = $data['payment_status'] ?? null;
                $patchData['payment_date'] = !empty($data['payment_date']) ? $data['payment_date'] : null;
            }

            if (!empty($patchData)) {
                $record = $this->LegalizationRecords->patchEntity($record, $patchData);
                $this->LegalizationRecords->save($record);
            }

            // Add invoices (only in agrupacion)
            if ($record->isAgrupacion() && !empty($data['invoice_ids'])) {
                $invoiceIds = array_map('intval', array_filter((array)$data['invoice_ids']));
                $errors = $this->legalizationService->addInvoices($record, $invoiceIds);
                foreach ($errors as $err) {
                    $this->Flash->warning($err);
                }
            }

            // Try to advance automatically
            $user = $this->_getCurrentUser();
            $canAdvance = !$record->isPagado() && (LegalizationConstants::TRANSITIONS[$record->status] ?? null) !== null;
            if ($canAdvance) {
                $result = $this->legalizationService->advanceStatus($record, $user->id);
                if ($result['success']) {
                    $nextLabel = LegalizationConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
                    $this->Flash->success(sprintf('Registro guardado y avanzado a: %s', $nextLabel));
                } else {
                    $this->Flash->success('Registro actualizado.');
                    $this->Flash->warning($result['error']);
                }
            } else {
                $this->Flash->success('Registro actualizado.');
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = LegalizationConstants::TRANSITIONS[$record->status] ?? null;
        $advanceErrors = [];
        if ($nextStatus) {
            $advanceErrors = $this->legalizationService->getTransitionErrors($record);
        }

        $groupFilters = $this->request->getQueryParams();
        $availableInvoices = $this->legalizationService->getAvailableInvoices($groupFilters)->all();
        $operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
        $canDeleteDocuments = $this->_checkPermission('legalizations', 'delete');

        $this->set(compact('record', 'availableInvoices', 'operationCenters', 'canDeleteDocuments', 'groupFilters', 'nextStatus', 'advanceErrors'));
    }

    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->LegalizationRecords->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->legalizationService->advanceStatus($record, $user->id);

        if ($result['success']) {
            $nextLabel = LegalizationConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));
        } else {
            $this->Flash->error($result['error']);
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->LegalizationRecords->get($id);

        if (!$this->legalizationService->canDelete($record)) {
            $this->Flash->error('Solo se pueden eliminar registros en estado Agrupación.');

            return $this->redirect(['action' => 'index']);
        }

        $invoicesTable = $this->fetchTable('Invoices');
        $invoicesTable->updateAll(
            ['legalization_record_id' => null],
            ['legalization_record_id' => $record->id],
        );

        if ($this->LegalizationRecords->delete($record)) {
            $this->Flash->success('Registro de Legalización eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el registro.');
        }

        return $this->redirect(['action' => 'index']);
    }

    public function removeInvoice($recordId = null, $invoiceId = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->LegalizationRecords->get($recordId);

        if ($this->legalizationService->removeInvoice($record, (int)$invoiceId)) {
            $this->Flash->success('Factura removida del registro.');
        } else {
            $this->Flash->error('No se puede remover facturas de un registro que no esté en Agrupación.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }

    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $this->LegalizationRecords->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file) {
            $this->Flash->error('No se recibió ningún archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $identity = $this->Authentication->getIdentity();
        $result = $this->documentService->uploadDocument(
            (int)$id,
            $file,
            $identity ? (int)$identity->getIdentifier() : null,
            $this->request->getData('document_type'),
        );

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('El soporte ha sido subido.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('LegalizationObservations');
        $observation = $observationsTable->newEntity([
            'legalization_record_id' => $id,
            'user_id' => $user->id,
            'message' => $this->request->getData('message'),
        ]);

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function deleteDocument($recordId = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $this->LegalizationRecords->get($recordId);

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success('El soporte ha sido eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $recordId]);
    }
}
```

**Step 2: Commit**

```bash
git add src/Controller/LegalizationRecordsController.php
git commit -m "feat: add LegalizationRecordsController"
```

---

### Task 7: Views — Templates

**Files:**
- Create: `templates/LegalizationRecords/index.php`
- Create: `templates/LegalizationRecords/add.php`
- Create: `templates/LegalizationRecords/edit.php`
- Create: `templates/LegalizationRecords/view.php`
- Create: `templates/element/legalization_progress.php`

**Step 1: Create all 5 template files**

These are copies of the Petty Cash templates with the following search-and-replace changes:

| Petty Cash | Legalization |
|------------|-------------|
| `Caja Menor` | `Legalizaciones` |
| `Caja menor` | `Legalización` (document type) |
| `Registro de Caja Menor` | `Registro de Legalización` |
| `PettyCashConstants` | `LegalizationConstants` |
| `petty_cash_progress` | `legalization_progress` |
| `petty_cash_documents` | `legalization_documents` |
| `petty_cash_observations` | `legalization_observations` |
| `PettyCashRecords` | `LegalizationRecords` |
| `petty_cash` (permission module) | `legalizations` |
| `uploadPcDocModal` | `uploadLegDocModal` |
| `$this->pettyCashService->generateCode()` → removed, uses `$data['code']` from form |
| `bi-wallet2` icon | `bi-file-earmark-check` icon |

**For index.php**: Copy from `templates/PettyCashRecords/index.php`, change title to "Legalizaciones", change "Caja Menor" → "Legalizaciones", `petty_cash` → `legalizations`, `PettyCashConstants` → `LegalizationConstants`, `PettyCashRecords` → `LegalizationRecords`.

**For add.php**: Copy from `templates/PettyCashRecords/add.php`, change title, labels, and add a `code` input field (optional text input). Remove "El código se generará automáticamente" subtitle. Change `'Caja menor'` references to `'Legalización'`.

**For edit.php**: Copy from `templates/PettyCashRecords/edit.php`. Add editable `code` input field in the ledger section (when not pagado). Change all petty cash references to legalization. Change permission module from `petty_cash` to `legalizations`. Change modal ID from `uploadPcDocModal` to `uploadLegDocModal`.

**For view.php**: Copy from `templates/PettyCashRecords/view.php`. Change title, labels, permission module reference.

**For legalization_progress.php**: Copy from `templates/element/petty_cash_progress.php`. Change `PettyCashConstants` to `LegalizationConstants`.

> **Note to implementer:** Copy each Petty Cash template file, then do the replacements listed above. The templates are extensive (100-650 lines each), so copy-and-adapt is the correct approach.

**Step 2: Commit**

```bash
git add templates/LegalizationRecords/ templates/element/legalization_progress.php
git commit -m "feat: add Legalization views and progress element"
```

---

### Task 8: Routes, Module Registration, and Sidebar

**Files:**
- Modify: `config/routes.php` (after line 255)
- Modify: `src/Controller/AppController.php` (lines 48, 167-180)
- Modify: `src/Service/AuthorizationService.php` (line 35-37)
- Modify: `templates/layout/default.php` (after line 143)

**Step 1: Add routes in `config/routes.php` after the Petty Cash routes (line 255)**

Add after the `// Employee observations` comment:

```php
        // Legalization Records (Legalizaciones)
        $builder->connect(
            '/legalization-records/advance-status/{id}',
            ['controller' => 'LegalizationRecords', 'action' => 'advanceStatus'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/legalization-records/upload-document/{id}',
            ['controller' => 'LegalizationRecords', 'action' => 'uploadDocument'],
            ['id' => '\d+', 'pass' => ['id']]
        );
        $builder->connect(
            '/legalization-records/delete-document/{recordId}/{documentId}',
            ['controller' => 'LegalizationRecords', 'action' => 'deleteDocument'],
            ['recordId' => '\d+', 'documentId' => '\d+', 'pass' => ['recordId', 'documentId']]
        );
        $builder->connect(
            '/legalization-records/remove-invoice/{recordId}/{invoiceId}',
            ['controller' => 'LegalizationRecords', 'action' => 'removeInvoice'],
            ['recordId' => '\d+', 'invoiceId' => '\d+', 'pass' => ['recordId', 'invoiceId']]
        );
        $builder->connect(
            '/legalization-records/add-observation/{id}',
            ['controller' => 'LegalizationRecords', 'action' => 'addObservation'],
            ['id' => '\d+', 'pass' => ['id']]
        );
```

**Step 2: Add module mapping in `AppController.php`**

Add to `$controllerModuleMap` array (after `'PettyCashRecords' => 'petty_cash'`):
```php
'LegalizationRecords' => 'legalizations',
```

**Step 3: Add sidebar counter in `AppController.php`**

After the petty cash counter block (after line 170), add:
```php
            $legalizationTable = TableRegistry::getTableLocator()->get('LegalizationRecords');
            $this->set('legalizationCount', $legalizationTable->find()
                ->where(['status !=' => 'pagado'])
                ->count());
```

And in the catch block, add:
```php
            $this->set('legalizationCount', 0);
```

**Step 4: Add module to `AuthorizationService::MODULES`**

After `'petty_cash' => 'Caja Menor',`:
```php
'legalizations' => 'Legalizaciones',
```

**Step 5: Add sidebar entry in `templates/layout/default.php`**

After the Caja Menor sidebar `<?php endif; ?>` (line 144), add:
```php
                            <?php if ($canView('legalizations')): ?>
                            <li class="nav-item">
                                <?= $this->Html->link(
                                    '<i class="bi bi-file-earmark-check me-2"></i>Legalizaciones' .
                                    ($legalizationCount > 0 ? ' <span class="badge bg-warning text-dark sidebar-badge ms-auto">' . $legalizationCount . '</span>' : ''),
                                    ['controller' => 'LegalizationRecords', 'action' => 'index'],
                                    ['class' => $navLink('LegalizationRecords') . ' d-flex align-items-center', 'escape' => false]
                                ) ?>
                            </li>
                            <?php endif; ?>
```

Also add variable initialization at the top of `default.php` near `$pettyCashCount`:
```php
$legalizationCount = $legalizationCount ?? 0;
```

**Step 6: Commit**

```bash
git add config/routes.php src/Controller/AppController.php src/Service/AuthorizationService.php templates/layout/default.php
git commit -m "feat: register legalizations module in routes, permissions, sidebar"
```

---

### Task 9: Petty Cash — Remove Auto-Generated Code

**Files:**
- Modify: `src/Service/PettyCashService.php` (remove `generateCode()`)
- Modify: `src/Controller/PettyCashRecordsController.php` (line 83: use `$data['code']` instead of `generateCode()`)
- Modify: `src/Model/Table/PettyCashRecordsTable.php` (line 48: change `notEmptyString` to `allowEmptyString`, remove unique rule on line 71)
- Modify: `templates/PettyCashRecords/add.php` (add code input field, remove auto-generate subtitle)
- Modify: `templates/PettyCashRecords/edit.php` (make code editable input)

**Step 1: Remove `generateCode()` from PettyCashService**

Delete the entire `generateCode()` method (lines 14-31 of `PettyCashService.php`).

**Step 2: Update PettyCashRecordsController::add()**

Change line 83 from:
```php
'code' => $this->pettyCashService->generateCode(),
```
to:
```php
'code' => !empty($data['code']) ? $data['code'] : null,
```

**Step 3: Update PettyCashRecordsTable validation**

Change line 48 from:
```php
->notEmptyString('code');
```
to:
```php
->allowEmptyString('code');
```

Remove the unique rule on line 71:
```php
$rules->add($rules->isUnique(['code']), ['errorField' => 'code']);
```

**Step 4: Update PettyCashRecordsController::edit()**

In the POST handler, after the notes section (around line 134), add code editing:
```php
// Code: editable in all non-final states
if (!$record->isPagado()) {
    $patchData['code'] = !empty($data['code']) ? $data['code'] : null;
}
```

**Step 5: Update `templates/PettyCashRecords/add.php`**

Change line 68 subtitle from `"El código se generará automáticamente"` to `"El código es opcional"`.

Add code input field before the Notes textarea (before line 73):
```php
<div class="mb-4">
    <label class="form-label">Código <small class="text-muted">(opcional)</small></label>
    <input type="text" name="code" class="form-control" maxlength="30" value="<?= h($record->code ?? '') ?>" placeholder="Ej. CM-2026-0001">
</div>
```

**Step 6: Update `templates/PettyCashRecords/edit.php`**

In the ledger section (around line 157-159), change the code display from static text to an editable input when not pagado:
```php
<div class="sgi-ledger-item">
    <div class="sgi-ledger-label">Código</div>
    <?php if (!$record->isPagado()): ?>
    <div class="sgi-ledger-value"><input type="text" name="code" form="<?= $this->Form->getFormProtector() ? '' : '' ?>" class="form-control form-control-sm" style="font-family:monospace;max-width:200px;" maxlength="30" value="<?= h($record->code ?? '') ?>" placeholder="Opcional"></div>
    <?php else: ?>
    <div class="sgi-ledger-value" style="font-family:monospace;"><?= h($record->code ?? '—') ?></div>
    <?php endif; ?>
</div>
```

> **Note:** The code input must be inside the main `<form>` element. Since the ledger section is already inside the form (which starts at line 182), the input is automatically part of the form. Simply replace the static display with the input.

**Step 7: Commit**

```bash
git add src/Service/PettyCashService.php src/Controller/PettyCashRecordsController.php src/Model/Table/PettyCashRecordsTable.php templates/PettyCashRecords/add.php templates/PettyCashRecords/edit.php
git commit -m "feat: make petty cash code optional and manually editable"
```

---

### Task 10: Final Verification

**Step 1: Run code style check**

```bash
composer cs-check
```

Fix any issues with `composer cs-fix`.

**Step 2: Verify migrations are applied**

```bash
bin/cake migrations status
```

All migrations should show as "up".

**Step 3: Start dev server and verify manually**

```bash
bin/cake server
```

Test these flows:
- [ ] Navigate to Legalizaciones in sidebar
- [ ] Create a new legalization record (with optional code, select invoices type "Legalización")
- [ ] Edit the record: add/remove invoices, add observations, upload documents
- [ ] Advance through states: agrupación → contabilidad → tesorería → pagado
- [ ] Verify Caja Menor still works with optional code field
- [ ] Verify permissions work correctly for non-admin roles

**Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete legalizations module implementation"
```
