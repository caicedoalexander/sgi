# Novelties Pipeline Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the simple 3-state approval system (pendiente/aprobado/rechazado) with a 6-stage pipeline (registro → rrhh → contabilidad → firmas_aprobacion → gdp → tesoreria → pagada), with grouping under Liquidation Documents from Contabilidad onwards.

**Architecture:** Extends the existing `EmployeeNovelties` module with a pipeline service analogous to `InvoicePipelineService`. New tables for liquidation docs, observations, documents, signatures, and massive employees. NoveltyType flags control which stages are skipped. Individual advancement in registro/rrhh; group advancement via `NoveltyLiquidationDocs` from contabilidad onwards.

**Tech Stack:** CakePHP 5.3 · PHP 8.2 · MariaDB · Bootstrap 5 · Existing pipeline_progress element pattern

---

## Task 1: Update NoveltyConstants

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`

**Step 1: Replace constants with pipeline values**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    // Pipeline statuses (ordered)
    public const STATUS_REGISTRO = 'registro';
    public const STATUS_RRHH = 'rrhh';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_FIRMAS_APROBACION = 'firmas_aprobacion';
    public const STATUS_GDP = 'gdp';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADA = 'pagada';
    public const STATUS_RECHAZADA = 'rechazada';

    public const PIPELINE_STATUSES = [
        self::STATUS_REGISTRO,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_FIRMAS_APROBACION,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];

    public const ALL_STATUSES = [
        self::STATUS_REGISTRO,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_FIRMAS_APROBACION,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
        self::STATUS_RECHAZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REGISTRO => 'Registro',
        self::STATUS_RRHH => 'RRHH',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_FIRMAS_APROBACION => 'Firmas y Aprobación',
        self::STATUS_GDP => 'GDP',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_PAGADA => 'Pagada',
        self::STATUS_RECHAZADA => 'Rechazada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_REGISTRO => 'bi-pencil-square',
        self::STATUS_RRHH => 'bi-people',
        self::STATUS_CONTABILIDAD => 'bi-calculator',
        self::STATUS_FIRMAS_APROBACION => 'bi-pen',
        self::STATUS_GDP => 'bi-clipboard-check',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_PAGADA => 'bi-cash-coin',
    ];

    // Linear transitions
    public const TRANSITIONS = [
        self::STATUS_REGISTRO => self::STATUS_RRHH,
        self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_FIRMAS_APROBACION,
        self::STATUS_FIRMAS_APROBACION => self::STATUS_GDP,
        self::STATUS_GDP => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    // Schedule types
    public const SCHEDULE_DAYS = 'days';
    public const SCHEDULE_HOURS = 'hours';
    public const SCHEDULE_TYPES = [self::SCHEDULE_DAYS, self::SCHEDULE_HOURS];
    public const SCHEDULE_LABELS = [
        self::SCHEDULE_DAYS => 'Por días',
        self::SCHEDULE_HOURS => 'Por horas',
    ];

    // Period options (for liquidation docs)
    public const PERIOD_PRIMERA_QUINCENA = 'primera_quincena';
    public const PERIOD_SEGUNDA_QUINCENA = 'segunda_quincena';
    public const PERIOD_CIERRE_NOMINA = 'cierre_nomina';
    public const PERIODS = [
        self::PERIOD_PRIMERA_QUINCENA,
        self::PERIOD_SEGUNDA_QUINCENA,
        self::PERIOD_CIERRE_NOMINA,
    ];
    public const PERIOD_LABELS = [
        self::PERIOD_PRIMERA_QUINCENA => 'Primera Quincena',
        self::PERIOD_SEGUNDA_QUINCENA => 'Segunda Quincena',
        self::PERIOD_CIERRE_NOMINA => 'Cierre de Nómina',
    ];

    // Payment statuses (for liquidation docs)
    public const PAYMENT_PAGADO = 'pagado';
    public const PAYMENT_PENDIENTE = 'pendiente';
    public const PAYMENT_NA = 'na';
    public const PAYMENT_STATUSES = [
        self::PAYMENT_PAGADO,
        self::PAYMENT_PENDIENTE,
        self::PAYMENT_NA,
    ];
    public const PAYMENT_LABELS = [
        self::PAYMENT_PAGADO => 'Pagado',
        self::PAYMENT_PENDIENTE => 'Pendiente',
        self::PAYMENT_NA => 'N/A',
    ];

    // Signer types (for liquidation doc signatures)
    public const SIGNER_CONTADOR = 'contador';
    public const SIGNER_COORDINADOR_ADMIN = 'coordinador_admin';
    public const SIGNER_JEFE_INMEDIATO = 'jefe_inmediato';
    public const SIGNER_TRABAJADOR = 'trabajador';
    public const SIGNER_TYPES = [
        self::SIGNER_CONTADOR,
        self::SIGNER_COORDINADOR_ADMIN,
        self::SIGNER_JEFE_INMEDIATO,
        self::SIGNER_TRABAJADOR,
    ];
    public const SIGNER_LABELS = [
        self::SIGNER_CONTADOR => 'Contador',
        self::SIGNER_COORDINADOR_ADMIN => 'Coordinador Administrativo',
        self::SIGNER_JEFE_INMEDIATO => 'Jefe Inmediato',
        self::SIGNER_TRABAJADOR => 'Trabajador',
    ];

    // Backward compat — old statuses mapping
    public const STATUS_PENDING = self::STATUS_REGISTRO;
    public const STATUS_APPROVED = self::STATUS_PAGADA;
    public const STATUS_REJECTED = self::STATUS_RECHAZADA;
    public const STATUSES = self::ALL_STATUSES;
}
```

**Step 2: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "feat(novelties): update NoveltyConstants with pipeline statuses and liquidation constants"
```

---

## Task 2: Database Migrations — NoveltyTypes Config Columns

**Files:**
- Create: `config/Migrations/20260313000001_AddPipelineFlagsToNoveltyTypes.php`

**Step 1: Create migration**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPipelineFlagsToNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');
        $table
            ->addColumn('requires_rrhh', 'boolean', ['default' => true, 'null' => false, 'after' => 'parent_id'])
            ->addColumn('requires_firmas', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_rrhh'])
            ->addColumn('requires_gdp', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_firmas'])
            ->addColumn('requires_tesoreria', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_gdp'])
            ->addColumn('show_start_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'requires_tesoreria'])
            ->addColumn('show_end_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_start_date'])
            ->addColumn('show_permission_date', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_end_date'])
            ->addColumn('show_schedule_type', 'boolean', ['default' => true, 'null' => false, 'after' => 'show_permission_date'])
            ->addColumn('uses_custom_name', 'boolean', ['default' => false, 'null' => false, 'after' => 'show_schedule_type'])
            ->addColumn('is_massive', 'boolean', ['default' => false, 'null' => false, 'after' => 'uses_custom_name'])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_types');
        $table
            ->removeColumn('requires_rrhh')
            ->removeColumn('requires_firmas')
            ->removeColumn('requires_gdp')
            ->removeColumn('requires_tesoreria')
            ->removeColumn('show_start_date')
            ->removeColumn('show_end_date')
            ->removeColumn('show_permission_date')
            ->removeColumn('show_schedule_type')
            ->removeColumn('uses_custom_name')
            ->removeColumn('is_massive')
            ->update();
    }
}
```

**Step 2: Run migration**

```bash
bin/cake migrations migrate
```
Expected: Migration applied successfully.

**Step 3: Commit**

```bash
git add config/Migrations/20260313000001_AddPipelineFlagsToNoveltyTypes.php
git commit -m "feat(novelties): add pipeline configuration flags to novelty_types"
```

---

## Task 3: Database Migrations — Modify employee_novelties + Create new tables

**Files:**
- Create: `config/Migrations/20260313000002_ModifyEmployeeNoveltiesForPipeline.php`
- Create: `config/Migrations/20260313000003_CreateNoveltyLiquidationDocs.php`
- Create: `config/Migrations/20260313000004_CreateNoveltyLiquidationSignatures.php`
- Create: `config/Migrations/20260313000005_CreateNoveltyMassiveEmployees.php`
- Create: `config/Migrations/20260313000006_CreateNoveltyObservations.php`
- Create: `config/Migrations/20260313000007_CreateNoveltyDocuments.php`

**Step 1: Migration — Modify employee_novelties**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ModifyEmployeeNoveltiesForPipeline extends BaseMigration
{
    public function up(): void
    {
        // Rename status → pipeline_status and widen
        $this->execute("ALTER TABLE employee_novelties CHANGE COLUMN `status` `pipeline_status` VARCHAR(30) NOT NULL DEFAULT 'registro'");

        // Migrate old values
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'registro' WHERE pipeline_status = 'pendiente'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'pagada' WHERE pipeline_status = 'aprobado'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'rechazada' WHERE pipeline_status = 'rechazado'");

        // Make employee_id nullable (for massive novelties)
        $this->execute("ALTER TABLE employee_novelties MODIFY COLUMN employee_id INTEGER NULL DEFAULT NULL");

        // Add new columns
        $table = $this->table('employee_novelties');
        $table
            ->addColumn('passes_payroll', 'boolean', ['null' => true, 'default' => null, 'after' => 'observations'])
            ->addColumn('rrhh_by', 'integer', ['null' => true, 'default' => null, 'after' => 'passes_payroll'])
            ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null, 'after' => 'rrhh_by'])
            ->addColumn('custom_name', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'liquidation_doc_id'])
            ->addForeignKey('rrhh_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        // Note: liquidation_doc_id FK added after creating novelty_liquidation_docs table
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');

        // Remove FK first if exists
        try {
            $table->dropForeignKey('rrhh_by')->update();
        } catch (\Exception $e) {
            // FK may not exist
        }

        $table
            ->removeColumn('passes_payroll')
            ->removeColumn('rrhh_by')
            ->removeColumn('liquidation_doc_id')
            ->removeColumn('custom_name')
            ->update();

        $this->execute("ALTER TABLE employee_novelties MODIFY COLUMN employee_id INTEGER NOT NULL");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'pendiente' WHERE pipeline_status = 'registro'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'aprobado' WHERE pipeline_status = 'pagada'");
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'rechazado' WHERE pipeline_status = 'rechazada'");
        $this->execute("ALTER TABLE employee_novelties CHANGE COLUMN `pipeline_status` `status` VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
    }
}
```

**Step 2: Migration — Create novelty_liquidation_docs**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyLiquidationDocs extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_liquidation_docs')) {
            $this->table('novelty_liquidation_docs')
                ->addColumn('liquidation_number', 'string', ['limit' => 50, 'null' => false])
                ->addColumn('period', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false, 'default' => 'contabilidad'])
                ->addColumn('document_date', 'date', ['null' => false])
                ->addColumn('performed_by', 'integer', ['null' => false])
                ->addColumn('passes_for_payment', 'boolean', ['null' => true, 'default' => null])
                ->addColumn('payment_status', 'string', ['limit' => 20, 'null' => true, 'default' => null])
                ->addColumn('payment_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['liquidation_number'], ['unique' => true])
                ->addForeignKey('performed_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }

        // Now add FK from employee_novelties → novelty_liquidation_docs
        $this->table('employee_novelties')
            ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        try {
            $this->table('employee_novelties')->dropForeignKey('liquidation_doc_id')->update();
        } catch (\Exception $e) {
            // FK may not exist
        }
        if ($this->hasTable('novelty_liquidation_docs')) {
            $this->table('novelty_liquidation_docs')->drop()->save();
        }
    }
}
```

**Step 3: Migration — Create novelty_liquidation_signatures**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyLiquidationSignatures extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_liquidation_signatures')) {
            $this->table('novelty_liquidation_signatures')
                ->addColumn('liquidation_doc_id', 'integer', ['null' => false])
                ->addColumn('signer_type', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('signature_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('signed_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('signed_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_liquidation_signatures')) {
            $this->table('novelty_liquidation_signatures')->drop()->save();
        }
    }
}
```

**Step 4: Migration — Create novelty_massive_employees**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyMassiveEmployees extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_massive_employees')) {
            $this->table('novelty_massive_employees')
                ->addColumn('novelty_id', 'integer', ['null' => false])
                ->addColumn('employee_id', 'integer', ['null' => false])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_massive_employees')) {
            $this->table('novelty_massive_employees')->drop()->save();
        }
    }
}
```

**Step 5: Migration — Create novelty_observations**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyObservations extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_observations')) {
            $this->table('novelty_observations')
                ->addColumn('novelty_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('message', 'text', ['null' => false])
                ->addColumn('is_read', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_observations')) {
            $this->table('novelty_observations')->drop()->save();
        }
    }
}
```

**Step 6: Migration — Create novelty_documents**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyDocuments extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('novelty_documents')) {
            $this->table('novelty_documents')
                ->addColumn('novelty_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_size', 'integer', ['null' => false])
                ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('uploaded_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('novelty_documents')) {
            $this->table('novelty_documents')->drop()->save();
        }
    }
}
```

**Step 7: Run all migrations**

```bash
bin/cake migrations migrate
```
Expected: All 7 migrations applied successfully.

**Step 8: Commit**

```bash
git add config/Migrations/20260313000002_ModifyEmployeeNoveltiesForPipeline.php \
        config/Migrations/20260313000003_CreateNoveltyLiquidationDocs.php \
        config/Migrations/20260313000004_CreateNoveltyLiquidationSignatures.php \
        config/Migrations/20260313000005_CreateNoveltyMassiveEmployees.php \
        config/Migrations/20260313000006_CreateNoveltyObservations.php \
        config/Migrations/20260313000007_CreateNoveltyDocuments.php
git commit -m "feat(novelties): create pipeline database schema (liquidation docs, signatures, observations, documents, massive employees)"
```

---

## Task 4: Models — Entities and Tables for New Tables

**Files:**
- Modify: `src/Model/Entity/EmployeeNovelty.php`
- Modify: `src/Model/Entity/NoveltyType.php`
- Modify: `src/Model/Table/EmployeeNoveltiesTable.php`
- Modify: `src/Model/Table/NoveltyTypesTable.php`
- Create: `src/Model/Entity/NoveltyLiquidationDoc.php`
- Create: `src/Model/Table/NoveltyLiquidationDocsTable.php`
- Create: `src/Model/Entity/NoveltyLiquidationSignature.php`
- Create: `src/Model/Table/NoveltyLiquidationSignaturesTable.php`
- Create: `src/Model/Entity/NoveltyMassiveEmployee.php`
- Create: `src/Model/Table/NoveltyMassiveEmployeesTable.php`
- Create: `src/Model/Entity/NoveltyObservation.php`
- Create: `src/Model/Table/NoveltyObservationsTable.php`
- Create: `src/Model/Entity/NoveltyDocument.php`
- Create: `src/Model/Table/NoveltyDocumentsTable.php`

**Step 1: Update EmployeeNovelty entity**

Replace `src/Model/Entity/EmployeeNovelty.php` with:

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
        'pipeline_status' => true,
        'approved_by' => true,
        'approved_at' => true,
        'registered_by' => true,
        'employee_signature' => true,
        'coordinator_signature' => true,
        'observations' => true,
        'passes_payroll' => true,
        'rrhh_by' => true,
        'liquidation_doc_id' => true,
        'custom_name' => true,
    ];

    public function isRejected(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
    }

    public function isPaid(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_PAGADA;
    }

    public function isGrouped(): bool
    {
        return $this->liquidation_doc_id !== null;
    }
}
```

**Step 2: Update NoveltyType entity**

Replace `src/Model/Entity/NoveltyType.php` with:

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
        'requires_rrhh' => true,
        'requires_firmas' => true,
        'requires_gdp' => true,
        'requires_tesoreria' => true,
        'show_start_date' => true,
        'show_end_date' => true,
        'show_permission_date' => true,
        'show_schedule_type' => true,
        'uses_custom_name' => true,
        'is_massive' => true,
        'novelty_type_contract_templates' => true,
    ];
}
```

**Step 3: Update EmployeeNoveltiesTable — add new associations, update validation**

In `src/Model/Table/EmployeeNoveltiesTable.php`, update `initialize()` to add:

```php
// Existing associations stay. Add these new ones:
$this->belongsTo('NoveltyLiquidationDocs', [
    'foreignKey' => 'liquidation_doc_id',
    'joinType' => 'LEFT',
]);
$this->belongsTo('RrhhByUsers', [
    'className' => 'Users',
    'foreignKey' => 'rrhh_by',
    'joinType' => 'LEFT',
]);
$this->hasMany('NoveltyMassiveEmployees', [
    'foreignKey' => 'novelty_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
$this->hasMany('NoveltyObservations', [
    'foreignKey' => 'novelty_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
$this->hasMany('NoveltyDocuments', [
    'foreignKey' => 'novelty_id',
    'dependent' => true,
    'cascadeCallbacks' => true,
]);
```

Update `validationDefault()`:
- Change `'status'` validator to `'pipeline_status'` with `inList(NoveltyConstants::ALL_STATUSES)`
- Make `employee_id` not required on create (allow null for massive)
- Make `permission_date` allowEmptyDate (conditional per type flags)
- Make `schedule_type` allowEmptyString (conditional per type flags)
- Make `reason` allowEmptyString

Update `Employees` belongsTo to `'joinType' => 'LEFT'` (nullable for massive).

**Step 4: Update NoveltyTypesTable — add validation for new flags**

In `src/Model/Table/NoveltyTypesTable.php`, add boolean validation for each new flag in `validationDefault()`:

```php
$validator->boolean('requires_rrhh');
$validator->boolean('requires_firmas');
$validator->boolean('requires_gdp');
$validator->boolean('requires_tesoreria');
$validator->boolean('show_start_date');
$validator->boolean('show_end_date');
$validator->boolean('show_permission_date');
$validator->boolean('show_schedule_type');
$validator->boolean('uses_custom_name');
$validator->boolean('is_massive');
```

**Step 5: Create NoveltyLiquidationDoc entity**

File: `src/Model/Entity/NoveltyLiquidationDoc.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyLiquidationDoc extends Entity
{
    protected array $_accessible = [
        'liquidation_number' => true,
        'period' => true,
        'pipeline_status' => true,
        'document_date' => true,
        'performed_by' => true,
        'passes_for_payment' => true,
        'payment_status' => true,
        'payment_date' => true,
        'created_by' => true,
    ];
}
```

**Step 6: Create NoveltyLiquidationDocsTable**

File: `src/Model/Table/NoveltyLiquidationDocsTable.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyLiquidationDocsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_liquidation_docs');
        $this->setDisplayField('liquidation_number');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PerformedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'performed_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => false,
        ]);
        $this->hasMany('NoveltyLiquidationSignatures', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyObservations', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyDocuments', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('liquidation_number')
            ->maxLength('liquidation_number', 50)
            ->requirePresence('liquidation_number', 'create')
            ->notEmptyString('liquidation_number');

        $validator
            ->scalar('period')
            ->inList('period', NoveltyConstants::PERIODS)
            ->requirePresence('period', 'create')
            ->notEmptyString('period');

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', NoveltyConstants::ALL_STATUSES);

        $validator
            ->date('document_date')
            ->requirePresence('document_date', 'create')
            ->notEmptyDate('document_date');

        $validator
            ->integer('performed_by')
            ->requirePresence('performed_by', 'create')
            ->notEmptyString('performed_by');

        $validator
            ->boolean('passes_for_payment')
            ->allowEmptyString('passes_for_payment');

        $validator
            ->scalar('payment_status')
            ->inList('payment_status', NoveltyConstants::PAYMENT_STATUSES)
            ->allowEmptyString('payment_status');

        $validator
            ->date('payment_date')
            ->allowEmptyDate('payment_date');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['liquidation_number']), [
            'errorField' => 'liquidation_number',
            'message' => 'Este número de liquidación ya existe.',
        ]);
        $rules->add($rules->existsIn('performed_by', 'PerformedByUsers'), ['errorField' => 'performed_by']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

**Step 7: Create remaining entities and tables**

File: `src/Model/Entity/NoveltyLiquidationSignature.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyLiquidationSignature extends Entity
{
    protected array $_accessible = [
        'liquidation_doc_id' => true,
        'signer_type' => true,
        'signature_path' => true,
        'signed_by' => true,
        'approved_at' => true,
    ];
}
```

File: `src/Model/Table/NoveltyLiquidationSignaturesTable.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\NoveltyConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyLiquidationSignaturesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_liquidation_signatures');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('SignedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'signed_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('liquidation_doc_id')
            ->requirePresence('liquidation_doc_id', 'create')
            ->notEmptyString('liquidation_doc_id');

        $validator
            ->scalar('signer_type')
            ->inList('signer_type', NoveltyConstants::SIGNER_TYPES)
            ->requirePresence('signer_type', 'create')
            ->notEmptyString('signer_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), ['errorField' => 'liquidation_doc_id']);
        $rules->add($rules->existsIn('signed_by', 'SignedByUsers'), [
            'errorField' => 'signed_by',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
```

File: `src/Model/Entity/NoveltyMassiveEmployee.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyMassiveEmployee extends Entity
{
    protected array $_accessible = [
        'novelty_id' => true,
        'employee_id' => true,
    ];
}
```

File: `src/Model/Table/NoveltyMassiveEmployeesTable.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyMassiveEmployeesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_massive_employees');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('EmployeeNovelties', [
            'foreignKey' => 'novelty_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Employees', [
            'foreignKey' => 'employee_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->integer('novelty_id')->requirePresence('novelty_id', 'create')->notEmptyString('novelty_id');
        $validator->integer('employee_id')->requirePresence('employee_id', 'create')->notEmptyString('employee_id');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), ['errorField' => 'novelty_id']);
        $rules->add($rules->existsIn('employee_id', 'Employees'), ['errorField' => 'employee_id']);

        return $rules;
    }
}
```

File: `src/Model/Entity/NoveltyObservation.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyObservation extends Entity
{
    protected array $_accessible = [
        'novelty_id' => true,
        'liquidation_doc_id' => true,
        'user_id' => true,
        'message' => true,
        'is_read' => true,
    ];
}
```

File: `src/Model/Table/NoveltyObservationsTable.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('EmployeeNovelties', [
            'foreignKey' => 'novelty_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
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
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), [
            'errorField' => 'novelty_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), [
            'errorField' => 'liquidation_doc_id',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
```

File: `src/Model/Entity/NoveltyDocument.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyDocument extends Entity
{
    protected array $_accessible = [
        'novelty_id' => true,
        'liquidation_doc_id' => true,
        'pipeline_status' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
    ];
}
```

File: `src/Model/Table/NoveltyDocumentsTable.php`

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class NoveltyDocumentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('novelty_documents');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('EmployeeNovelties', [
            'foreignKey' => 'novelty_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('NoveltyLiquidationDocs', [
            'foreignKey' => 'liquidation_doc_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->scalar('pipeline_status')->requirePresence('pipeline_status', 'create')->notEmptyString('pipeline_status');
        $validator->scalar('file_path')->requirePresence('file_path', 'create')->notEmptyString('file_path');
        $validator->scalar('file_name')->requirePresence('file_name', 'create')->notEmptyString('file_name');
        $validator->integer('file_size')->requirePresence('file_size', 'create');
        $validator->scalar('mime_type')->requirePresence('mime_type', 'create')->notEmptyString('mime_type');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('novelty_id', 'EmployeeNovelties'), [
            'errorField' => 'novelty_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('liquidation_doc_id', 'NoveltyLiquidationDocs'), [
            'errorField' => 'liquidation_doc_id',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), [
            'errorField' => 'uploaded_by',
            'allowNullableNulls' => true,
        ]);

        return $rules;
    }
}
```

**Step 8: Commit**

```bash
git add src/Model/Entity/EmployeeNovelty.php \
        src/Model/Entity/NoveltyType.php \
        src/Model/Entity/NoveltyLiquidationDoc.php \
        src/Model/Entity/NoveltyLiquidationSignature.php \
        src/Model/Entity/NoveltyMassiveEmployee.php \
        src/Model/Entity/NoveltyObservation.php \
        src/Model/Entity/NoveltyDocument.php \
        src/Model/Table/EmployeeNoveltiesTable.php \
        src/Model/Table/NoveltyTypesTable.php \
        src/Model/Table/NoveltyLiquidationDocsTable.php \
        src/Model/Table/NoveltyLiquidationSignaturesTable.php \
        src/Model/Table/NoveltyMassiveEmployeesTable.php \
        src/Model/Table/NoveltyObservationsTable.php \
        src/Model/Table/NoveltyDocumentsTable.php
git commit -m "feat(novelties): add entities and tables for pipeline models"
```

---

## Task 5: NoveltyPipelineService

**Files:**
- Create: `src/Service/NoveltyPipelineService.php`

**Step 1: Create the service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\EmployeeNovelty;
use Cake\ORM\TableRegistry;

class NoveltyPipelineService
{
    /**
     * Get the next status for a novelty, skipping stages disabled by type flags.
     */
    public function getNextStatus(object $novelty, ?object $noveltyType = null): ?string
    {
        $currentStatus = $novelty->pipeline_status;

        if ($currentStatus === NoveltyConstants::STATUS_RECHAZADA || $currentStatus === NoveltyConstants::STATUS_PAGADA) {
            return null;
        }

        if (!$noveltyType && !empty($novelty->novelty_type)) {
            $noveltyType = $novelty->novelty_type;
        }
        if (!$noveltyType && !empty($novelty->novelty_type_id)) {
            $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
                ->get($novelty->novelty_type_id);
        }

        $nextStatus = NoveltyConstants::TRANSITIONS[$currentStatus] ?? null;

        // Skip stages that are disabled by type flags
        while ($nextStatus && $noveltyType && $this->shouldSkipStage($nextStatus, $noveltyType)) {
            $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
        }

        return $nextStatus;
    }

    /**
     * Check if a pipeline stage should be skipped based on type flags.
     */
    private function shouldSkipStage(string $status, object $noveltyType): bool
    {
        return match ($status) {
            NoveltyConstants::STATUS_RRHH => !$noveltyType->requires_rrhh,
            NoveltyConstants::STATUS_FIRMAS_APROBACION => !$noveltyType->requires_firmas,
            NoveltyConstants::STATUS_GDP => !$noveltyType->requires_gdp,
            NoveltyConstants::STATUS_TESORERIA => !$noveltyType->requires_tesoreria,
            default => false,
        };
    }

    /**
     * Get the effective pipeline statuses for a novelty type (excluding skipped stages).
     */
    public function getEffectiveStatuses(?object $noveltyType = null): array
    {
        if (!$noveltyType) {
            return NoveltyConstants::PIPELINE_STATUSES;
        }

        return array_values(array_filter(
            NoveltyConstants::PIPELINE_STATUSES,
            fn(string $status) => !$this->shouldSkipStage($status, $noveltyType),
        ));
    }

    /**
     * Advance a single novelty individually.
     * Blocked if novelty has a liquidation_doc_id.
     */
    public function advance(EmployeeNovelty $novelty, int $userId): array
    {
        if ($novelty->isGrouped()) {
            return ['success' => false, 'error' => 'Esta novedad pertenece a un documento de liquidación. Debe avanzar desde el documento grupal.'];
        }

        if ($novelty->isRejected()) {
            return ['success' => false, 'error' => 'La novedad fue rechazada. El flujo ha terminado.'];
        }

        $errors = $this->validateTransition($novelty, $novelty->pipeline_status);
        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors)];
        }

        $nextStatus = $this->getNextStatus($novelty);
        if (!$nextStatus) {
            return ['success' => false, 'error' => 'Esta novedad ya está en el estado final.'];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty->pipeline_status = $nextStatus;

        if (!$noveltiesTable->save($novelty)) {
            return ['success' => false, 'error' => 'No se pudo avanzar el estado.'];
        }

        return ['success' => true, 'error' => null, 'nextStatus' => $nextStatus];
    }

    /**
     * Advance all novelties in a liquidation document group.
     */
    public function advanceGroup(object $liquidationDoc, int $userId): array
    {
        $errors = $this->validateGroupTransition($liquidationDoc);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');

        $members = $noveltiesTable->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->all();

        $currentGroupStatus = $liquidationDoc->pipeline_status;

        // Calculate next status using first member's type (all should advance to same group status)
        $firstMember = $members->first();
        if (!$firstMember) {
            return ['success' => false, 'errors' => ['No hay novedades en este documento de liquidación.']];
        }

        $nextGroupStatus = $this->getNextStatus($firstMember, $firstMember->novelty_type);
        if (!$nextGroupStatus) {
            return ['success' => false, 'errors' => ['El documento ya está en el estado final.']];
        }

        $saved = $noveltiesTable->getConnection()->transactional(
            function () use ($noveltiesTable, $liquidationDocsTable, $members, $liquidationDoc, $nextGroupStatus) {
                foreach ($members as $member) {
                    $member->pipeline_status = $nextGroupStatus;
                    if (!$noveltiesTable->save($member)) {
                        return false;
                    }
                }

                $liquidationDoc->pipeline_status = $nextGroupStatus;
                if (!$liquidationDocsTable->save($liquidationDoc)) {
                    return false;
                }

                return true;
            },
        );

        if (!$saved) {
            return ['success' => false, 'errors' => ['No se pudo avanzar el grupo.']];
        }

        return ['success' => true, 'errors' => [], 'nextStatus' => $nextGroupStatus];
    }

    /**
     * Reject a novelty (from any stage).
     */
    public function reject(EmployeeNovelty $novelty, int $userId, ?string $observations = null): array
    {
        if ($novelty->isRejected()) {
            return ['success' => false, 'error' => 'La novedad ya está rechazada.'];
        }

        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty->pipeline_status = NoveltyConstants::STATUS_RECHAZADA;
        $novelty->approved_by = $userId;
        $novelty->approved_at = new \DateTime();

        if ($observations) {
            $novelty->observations = $observations;
        }

        if (!$noveltiesTable->save($novelty)) {
            return ['success' => false, 'error' => 'No se pudo rechazar la novedad.'];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Validate transition requirements for a single novelty.
     */
    public function validateTransition(object $novelty, string $fromStatus): array
    {
        if ($novelty->isRejected()) {
            return ['La novedad fue rechazada. El flujo ha terminado.'];
        }

        $errors = [];

        switch ($fromStatus) {
            case NoveltyConstants::STATUS_RRHH:
                if ($novelty->passes_payroll === null) {
                    $errors[] = 'Debe indicar si "Pasa a Nómina".';
                }
                break;

            case NoveltyConstants::STATUS_CONTABILIDAD:
                if (empty($novelty->liquidation_doc_id)) {
                    $errors[] = 'La novedad debe estar asignada a un documento de liquidación.';
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate transition requirements for a liquidation document group.
     */
    public function validateGroupTransition(object $liquidationDoc): array
    {
        $errors = [];
        $currentStatus = $liquidationDoc->pipeline_status;

        switch ($currentStatus) {
            case NoveltyConstants::STATUS_FIRMAS_APROBACION:
                $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
                $signedCount = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signature_path IS NOT' => null,
                    ])
                    ->count();

                if ($signedCount < count(NoveltyConstants::SIGNER_TYPES)) {
                    $errors[] = 'Todas las firmas requeridas deben estar presentes para avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_GDP:
                if ($liquidationDoc->passes_for_payment === null) {
                    $errors[] = 'Debe indicar si "Pasa para Pago".';
                }
                break;

            case NoveltyConstants::STATUS_TESORERIA:
                if (empty($liquidationDoc->payment_status)) {
                    $errors[] = 'Estado de pago es requerido.';
                }
                if ($liquidationDoc->payment_status === NoveltyConstants::PAYMENT_PAGADO && empty($liquidationDoc->payment_date)) {
                    $errors[] = 'Fecha de pago es requerida cuando el estado es "Pagado".';
                }
                break;
        }

        return $errors;
    }

    /**
     * Check if a novelty can advance individually (not grouped).
     */
    public function canAdvanceIndividually(object $novelty): bool
    {
        return !$novelty->isGrouped();
    }

    /**
     * Assign a novelty to a liquidation document.
     * Creates the document if it doesn't exist yet.
     */
    public function assignToLiquidationDoc(
        EmployeeNovelty $novelty,
        string $liquidationNumber,
        array $data,
        int $userId,
    ): object|array {
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        $doc = $liquidationDocsTable->find()
            ->where(['liquidation_number' => $liquidationNumber])
            ->first();

        if (!$doc) {
            $doc = $liquidationDocsTable->newEntity([
                'liquidation_number' => $liquidationNumber,
                'period' => $data['period'] ?? NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
                'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
                'document_date' => $data['document_date'] ?? date('Y-m-d'),
                'performed_by' => $userId,
                'created_by' => $userId,
            ]);

            if (!$liquidationDocsTable->save($doc)) {
                return ['No se pudo crear el documento de liquidación.'];
            }

            // Create signature slots
            $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
            foreach (NoveltyConstants::SIGNER_TYPES as $signerType) {
                $sig = $signaturesTable->newEntity([
                    'liquidation_doc_id' => $doc->id,
                    'signer_type' => $signerType,
                ]);
                $signaturesTable->save($sig);
            }
        }

        $novelty->liquidation_doc_id = $doc->id;
        $novelty->pipeline_status = NoveltyConstants::STATUS_CONTABILIDAD;
        if (!$noveltiesTable->save($novelty)) {
            return ['No se pudo asignar la novedad al documento de liquidación.'];
        }

        return $doc;
    }

    /**
     * Get visible fields for a novelty type in a given pipeline stage.
     */
    public function getVisibleFields(object $noveltyType, string $pipelineStatus): array
    {
        $fields = ['novelty_type_id', 'filing_date', 'reason', 'is_paid'];

        if ($noveltyType->show_permission_date) {
            $fields[] = 'permission_date';
        }
        if ($noveltyType->show_schedule_type) {
            $fields[] = 'schedule_type';
        }
        if ($noveltyType->show_start_date) {
            $fields[] = 'start_date';
        }
        if ($noveltyType->show_end_date) {
            $fields[] = 'end_date';
        }
        if ($noveltyType->uses_custom_name) {
            $fields[] = 'custom_name';
        } else {
            $fields[] = 'employee_id';
        }

        if (in_array($pipelineStatus, [NoveltyConstants::STATUS_RRHH, NoveltyConstants::STATUS_CONTABILIDAD])) {
            $fields[] = 'passes_payroll';
        }

        return $fields;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "feat(novelties): create NoveltyPipelineService with pipeline logic, group advancement, and type-based stage skipping"
```

---

## Task 6: NoveltyDocumentService

**Files:**
- Create: `src/Service/NoveltyDocumentService.php`

**Step 1: Create service (clone of InvoiceDocumentService)**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

class NoveltyDocumentService
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

    public function uploadForNovelty(
        int $noveltyId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        return $this->upload($file, $pipelineStatus, $uploadedBy, 'novelties/' . $noveltyId, [
            'novelty_id' => $noveltyId,
        ]);
    }

    public function uploadForGroup(
        int $liquidationDocId,
        string $pipelineStatus,
        UploadedFile $file,
        ?int $uploadedBy,
    ): object|string {
        return $this->upload($file, $pipelineStatus, $uploadedBy, 'novelty_liquidations/' . $liquidationDocId, [
            'liquidation_doc_id' => $liquidationDocId,
        ]);
    }

    private function upload(
        UploadedFile $file,
        string $pipelineStatus,
        ?int $uploadedBy,
        string $subDir,
        array $extraFields,
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

        $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid('nov_') . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $document = $documentsTable->newEntity(array_merge($extraFields, [
            'pipeline_status' => $pipelineStatus,
            'file_path' => 'uploads/' . $subDir . '/' . $uniqueName,
            'file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
            'uploaded_by' => $uploadedBy,
        ]));

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
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        $filePath = WWW_ROOT . $document->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $documentsTable->delete($document);
    }

    public function canDeleteDocument(object $document, string $currentPipelineStatus): bool
    {
        return $document->pipeline_status === $currentPipelineStatus;
    }

    public function getDocumentsByStatus(int $noveltyId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $documents = $documentsTable->find()
            ->where(['novelty_id' => $noveltyId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }

    public function getGroupDocumentsByStatus(int $liquidationDocId): array
    {
        $documentsTable = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $documents = $documentsTable->find()
            ->where(['liquidation_doc_id' => $liquidationDocId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }

        return $grouped;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyDocumentService.php
git commit -m "feat(novelties): create NoveltyDocumentService for novelty and group document uploads"
```

---

## Task 7: NoveltyObservationService

**Files:**
- Create: `src/Service/NoveltyObservationService.php`

**Step 1: Create service**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class NoveltyObservationService
{
    public function addToNovelty(int $noveltyId, int $userId, string $message): object|string
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $observation = $table->newEntity([
            'novelty_id' => $noveltyId,
            'user_id' => $userId,
            'message' => $message,
        ]);

        if (!$table->save($observation)) {
            return 'No se pudo guardar la observación.';
        }

        return $observation;
    }

    public function addToGroup(int $liquidationDocId, int $userId, string $message): object|string
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $observation = $table->newEntity([
            'liquidation_doc_id' => $liquidationDocId,
            'user_id' => $userId,
            'message' => $message,
        ]);

        if (!$table->save($observation)) {
            return 'No se pudo guardar la observación.';
        }

        return $observation;
    }

    public function markAsRead(int $userId, ?int $noveltyId = null, ?int $liquidationDocId = null): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $conditions = ['user_id !=' => $userId, 'is_read' => false];

        if ($noveltyId) {
            $conditions['novelty_id'] = $noveltyId;
        } elseif ($liquidationDocId) {
            $conditions['liquidation_doc_id'] = $liquidationDocId;
        } else {
            return;
        }

        $table->updateAll(['is_read' => true], $conditions);
    }

    public function getUnreadCount(int $userId, ?int $noveltyId = null, ?int $liquidationDocId = null): int
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $conditions = ['user_id !=' => $userId, 'is_read' => false];

        if ($noveltyId) {
            $conditions['novelty_id'] = $noveltyId;
        } elseif ($liquidationDocId) {
            $conditions['liquidation_doc_id'] = $liquidationDocId;
        } else {
            return 0;
        }

        return $table->find()->where($conditions)->count();
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyObservationService.php
git commit -m "feat(novelties): create NoveltyObservationService for novelty and group observations"
```

---

## Task 8: Update EmployeeNoveltiesController

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Rewrite controller with pipeline support**

The controller needs these changes:
- Inject `NoveltyPipelineService`, `NoveltyDocumentService`, `NoveltyObservationService`
- Update `index()` to filter by `pipeline_status` instead of `status`
- Update `view()` to show pipeline progress, observations, documents
- Update `add()` to set `pipeline_status = 'registro'` instead of `status = 'pendiente'`, handle massive/custom_name
- Replace `approve()` with `advance()` using pipeline service
- Keep `reject()` but use pipeline service
- Add `addObservation()`, `uploadDocument()`, `deleteDocument()` actions
- Add `edit()` for RRHH stage editing (passes_payroll, etc.)

Key changes for `index()`:
```php
$statusFilter = $this->request->getQuery('pipeline_status');
if ($statusFilter) {
    $query->where(['EmployeeNovelties.pipeline_status' => $statusFilter]);
}
```

Key changes for `add()`:
```php
$data['pipeline_status'] = NoveltyConstants::STATUS_REGISTRO;
// Handle massive: save multiple employees in novelty_massive_employees
// Handle custom_name: store text instead of employee_id
```

New `advance()` action:
```php
public function advance($id = null)
{
    $this->request->allowMethod(['post']);
    $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
    $user = $this->Authentication->getIdentity()->getOriginalData();

    $result = $this->pipelineService->advance($novelty, $user->id);

    if ($result['success']) {
        $this->Flash->success('Novedad avanzada a: ' . NoveltyConstants::STATUS_LABELS[$result['nextStatus']]);
    } else {
        $this->Flash->error($result['error']);
    }

    return $this->redirect(['action' => 'view', $id]);
}
```

**Step 2: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat(novelties): update EmployeeNoveltiesController with pipeline support, observations, documents"
```

---

## Task 9: Create NoveltyLiquidationDocsController

**Files:**
- Create: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: Create controller**

Actions: `index`, `view`, `advanceGroup`, `addSignature`, `uploadDocument`, `deleteDocument`, `addObservation`

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;
use App\Service\NoveltySignatureService;

class NoveltyLiquidationDocsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;
    private NoveltyDocumentService $documentService;
    private NoveltyObservationService $observationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipelineService = new NoveltyPipelineService();
        $this->documentService = new NoveltyDocumentService();
        $this->observationService = new NoveltyObservationService();
    }

    public function index()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['PerformedByUsers', 'EmployeeNovelties'])
            ->order(['NoveltyLiquidationDocs.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('pipeline_status');
        if ($statusFilter) {
            $query->where(['NoveltyLiquidationDocs.pipeline_status' => $statusFilter]);
        }

        $liquidationDocs = $this->paginate($query);
        $this->set(compact('liquidationDocs', 'statusFilter'));
    }

    public function view($id = null)
    {
        $doc = $this->NoveltyLiquidationDocs->get($id, contain: [
            'PerformedByUsers',
            'CreatedByUsers',
            'EmployeeNovelties' => ['Employees', 'NoveltyTypes'],
            'NoveltyLiquidationSignatures' => ['SignedByUsers'],
            'NoveltyObservations' => [
                'Users',
                'sort' => ['NoveltyObservations.created' => 'ASC'],
            ],
            'NoveltyDocuments' => [
                'UploadedByUsers',
                'sort' => ['NoveltyDocuments.created' => 'DESC'],
            ],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $this->observationService->markAsRead($user->id, liquidationDocId: $doc->id);

        $groupErrors = $this->pipelineService->validateGroupTransition($doc);
        $effectiveStatuses = $this->pipelineService->getEffectiveStatuses();

        $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($doc->id);

        $this->set(compact('doc', 'groupErrors', 'effectiveStatuses', 'documentsByStatus'));
    }

    public function advanceGroup($id = null)
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Save editable fields for current stage before advancing
        $data = $this->request->getData();
        if (!empty($data)) {
            $doc = $this->NoveltyLiquidationDocs->patchEntity($doc, $data);
            $this->NoveltyLiquidationDocs->save($doc);
        }

        $result = $this->pipelineService->advanceGroup($doc, $user->id);

        if ($result['success']) {
            $this->Flash->success('Documento de liquidación avanzado a: ' . NoveltyConstants::STATUS_LABELS[$result['nextStatus']]);
        } else {
            foreach ($result['errors'] as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function addSignature($id = null)
    {
        $this->request->allowMethod(['post']);

        $signerType = $this->request->getData('signer_type');
        $signatureService = new NoveltySignatureService();
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $signaturesTable = $this->fetchTable('NoveltyLiquidationSignatures');
        $signature = $signaturesTable->find()
            ->where(['liquidation_doc_id' => $id, 'signer_type' => $signerType])
            ->first();

        if (!$signature) {
            $this->Flash->error('Firma no encontrada.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $signatureBase64 = $this->request->getData('signature_base64');
        if (!empty($signatureBase64)) {
            $path = $signatureService->saveFromBase64(
                $id,
                $signatureBase64,
                $user->id,
                'liquidation_' . $signerType,
            );
            if ($path) {
                $signature->signature_path = $path;
                $signature->signed_by = $user->id;
                $signature->approved_at = new \DateTime();
                $signaturesTable->save($signature);
                $this->Flash->success('Firma registrada.');
            }
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $doc = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $file = $this->request->getUploadedFile('document');

        if (!$file) {
            $this->Flash->error('No se seleccionó ningún archivo.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $result = $this->documentService->uploadForGroup($doc->id, $doc->pipeline_status, $file, $user->id);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Documento subido exitosamente.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function deleteDocument($id = null, $documentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $doc = $this->NoveltyLiquidationDocs->get($id);

        $documentsTable = $this->fetchTable('NoveltyDocuments');
        $document = $documentsTable->get($documentId);

        if (!$this->documentService->canDeleteDocument($document, $doc->pipeline_status)) {
            $this->Flash->error('Solo puede eliminar documentos de la etapa actual.');

            return $this->redirect(['action' => 'view', $id]);
        }

        if ($this->documentService->deleteDocument($documentId)) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->Authentication->getIdentity()->getOriginalData();
        $message = $this->request->getData('message');

        $result = $this->observationService->addToGroup($id, $user->id, $message);

        if (is_string($result)) {
            $this->Flash->error($result);
        } else {
            $this->Flash->success('Observación agregada.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }
}
```

**Step 2: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(novelties): create NoveltyLiquidationDocsController for group management"
```

---

## Task 10: Update Routes, AppController, AuthorizationService

**Files:**
- Modify: `config/routes.php`
- Modify: `src/Controller/AppController.php`
- Modify: `src/Service/AuthorizationService.php`

**Step 1: Add routes** (before `$builder->fallbacks()`)

```php
// Employee novelties pipeline
$builder->connect(
    '/employee-novelties/advance/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'advance'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/employee-novelties/add-observation/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'addObservation'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/employee-novelties/upload-document/{id}',
    ['controller' => 'EmployeeNovelties', 'action' => 'uploadDocument'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/employee-novelties/delete-document/{noveltyId}/{documentId}',
    ['controller' => 'EmployeeNovelties', 'action' => 'deleteDocument'],
    ['noveltyId' => '\d+', 'documentId' => '\d+', 'pass' => ['noveltyId', 'documentId']]
);

// Novelty Liquidation Docs
$builder->connect(
    '/novelty-liquidation-docs/advance-group/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'advanceGroup'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/novelty-liquidation-docs/add-signature/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addSignature'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/novelty-liquidation-docs/upload-document/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument'],
    ['id' => '\d+', 'pass' => ['id']]
);
$builder->connect(
    '/novelty-liquidation-docs/delete-document/{id}/{documentId}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'deleteDocument'],
    ['id' => '\d+', 'documentId' => '\d+', 'pass' => ['id', 'documentId']]
);
$builder->connect(
    '/novelty-liquidation-docs/add-observation/{id}',
    ['controller' => 'NoveltyLiquidationDocs', 'action' => 'addObservation'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

**Step 2: Update AppController**

In `$controllerModuleMap` add:
```php
'NoveltyLiquidationDocs' => 'novelty_liquidation_docs',
```

In `_setSidebarCounters()`, update the novelties counter:
```php
$noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
$this->set('noveltiesCount', $noveltiesTable->find()
    ->where(['pipeline_status NOT IN' => ['pagada', 'rechazada']])
    ->count());
```

**Step 3: Update AuthorizationService**

Add to `MODULES`:
```php
'novelty_liquidation_docs' => 'Documentos de Liquidación',
```

**Step 4: Create permissions migration**

File: `config/Migrations/20260313000008_AddNoveltyLiquidationDocsPermissions.php`

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddNoveltyLiquidationDocsPermissions extends BaseMigration
{
    public function up(): void
    {
        // Get all role IDs
        $roles = $this->fetchAll('SELECT id FROM roles');
        foreach ($roles as $role) {
            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) VALUES (%d, 'novelty_liquidation_docs', 1, 1, 1, 0)",
                $role['id']
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'novelty_liquidation_docs'");
    }
}
```

**Step 5: Run migration and commit**

```bash
bin/cake migrations migrate
git add config/routes.php \
        src/Controller/AppController.php \
        src/Service/AuthorizationService.php \
        config/Migrations/20260313000008_AddNoveltyLiquidationDocsPermissions.php
git commit -m "feat(novelties): add routes, permissions, and sidebar counters for pipeline"
```

---

## Task 11: Update NoveltyTypes Edit View — Pipeline Config Toggles

**Files:**
- Modify: `templates/NoveltyTypes/edit.php`
- Modify: `templates/NoveltyTypes/add.php`

**Step 1: Add pipeline configuration section**

Add a card section after existing fields with toggle switches for:
- `requires_rrhh` — "Requiere etapa RRHH"
- `requires_firmas` — "Requiere Firmas y Aprobación"
- `requires_gdp` — "Requiere etapa GDP"
- `requires_tesoreria` — "Requiere etapa Tesorería"
- `show_start_date` — "Mostrar Fecha Inicio"
- `show_end_date` — "Mostrar Fecha Fin"
- `show_permission_date` — "Mostrar Fecha de Permiso"
- `show_schedule_type` — "Mostrar Tipo de Horario"
- `uses_custom_name` — "Usa Nombre Libre (en vez de select de empleado)"
- `is_massive` — "Novedad Masiva (multi-selección de empleados)"

Use Bootstrap `form-check form-switch` pattern for each toggle.

**Step 2: Commit**

```bash
git add templates/NoveltyTypes/edit.php templates/NoveltyTypes/add.php
git commit -m "feat(novelties): add pipeline configuration toggles to NoveltyTypes edit/add views"
```

---

## Task 12: EmployeeNovelties Templates — Index, View, Add

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`
- Modify: `templates/EmployeeNovelties/view.php`
- Modify: `templates/EmployeeNovelties/add.php`

**Step 1: Update index.php**

- Replace `status` filter with `pipeline_status` dropdown using `NoveltyConstants::STATUS_LABELS`
- Show pipeline status as colored badge (use `STATUS_LABELS` for display)
- Show `custom_name` or employee name depending on type
- Add column for unread observation count badge

**Step 2: Update view.php**

- Add pipeline progress bar using `pipeline_progress.php` element with novelty-specific statuses/labels/icons
- Show RRHH fields (passes_payroll) when in RRHH stage
- Show "Asignar a Documento de Liquidación" form when in contabilidad stage (if not yet grouped)
- If grouped: show link to liquidation doc, hide individual advance button
- Add documents section grouped by pipeline stage
- Add observations chat section
- Show advance/reject buttons based on current stage and role

**Step 3: Update add.php**

- Load novelty type flags via AJAX or data attributes to show/hide fields dynamically
- If `is_massive`: show multi-select Select2 for employees instead of single select
- If `uses_custom_name`: show text input instead of employee select
- Show/hide date fields based on type flags (`show_start_date`, `show_end_date`, etc.)
- Show/hide schedule_type and permission_date based on type flags

**Step 4: Commit**

```bash
git add templates/EmployeeNovelties/index.php \
        templates/EmployeeNovelties/view.php \
        templates/EmployeeNovelties/add.php
git commit -m "feat(novelties): update EmployeeNovelties templates with pipeline UI, observations, documents"
```

---

## Task 13: NoveltyLiquidationDocs Templates — Index, View

**Files:**
- Create: `templates/NoveltyLiquidationDocs/index.php`
- Create: `templates/NoveltyLiquidationDocs/view.php`

**Step 1: Create index.php**

- Table with columns: Número de Liquidación, Período (label from PERIOD_LABELS), Estado (badge), Novedades (count), Fecha
- Filter dropdown by `pipeline_status`
- Clickable rows with `data-href`
- Pagination element

**Step 2: Create view.php**

- Header card with: liquidation_number, period label, document_date, performed_by user name
- Pipeline progress bar (starting from contabilidad stage)
- Compact list of member novelties with links to each
- Signatures section (visible in `firmas_aprobacion` stage):
  - 4 signer slots with canvas and completion badge
  - Form to add each signature via base64
- GDP section (visible in `gdp` stage): passes_for_payment toggle
- Treasury section (visible in `tesoreria` stage): payment_status select, payment_date
- Documents section grouped by pipeline stage
- Observations chat
- "Avanzar Grupo" button

**Step 3: Commit**

```bash
git add templates/NoveltyLiquidationDocs/index.php \
        templates/NoveltyLiquidationDocs/view.php
git commit -m "feat(novelties): create NoveltyLiquidationDocs templates (index, view with signatures and group actions)"
```

---

## Task 14: Update Sidebar in Layout

**Files:**
- Modify: `templates/layout/default.php`

**Step 1: Add sidebar entry for Liquidation Docs**

Add a "Documentos de Liquidación" link in the sidebar under the existing "Novedades" section:

```php
<a class="nav-link" href="<?= $this->Url->build(['controller' => 'NoveltyLiquidationDocs', 'action' => 'index']) ?>">
    <i class="bi bi-file-earmark-text"></i> Documentos de Liquidación
</a>
```

**Step 2: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat(novelties): add Liquidation Documents link to sidebar"
```

---

## Task 15: Pipeline Progress Element — Support Dynamic Statuses

**Files:**
- Modify: `templates/element/pipeline_progress.php`

**Step 1: Make element generic**

The existing element already accepts `$pipelineStatuses`, `$pipelineLabels`, `$currentStatus`, `$isRejected` as variables. It should work without changes since the novelty views will pass novelty-specific statuses/labels. However, the `$statusIcons` array is hardcoded for invoices.

Update the element to accept an optional `$statusIcons` variable:
```php
$statusIcons = $statusIcons ?? [
    'aprobacion'    => 'bi-check-circle',
    'contabilidad'  => 'bi-calculator',
    'tesoreria'     => 'bi-bank',
    'pagada'        => 'bi-cash-coin',
];
```

This makes it backwards compatible with invoices while allowing novelties to pass their own icons.

**Step 2: Commit**

```bash
git add templates/element/pipeline_progress.php
git commit -m "feat: make pipeline_progress element accept custom status icons"
```

---

## Task 16: Add Novelty Type Flags Endpoint (AJAX)

**Files:**
- Modify: `src/Controller/NoveltyTypesController.php`
- Modify: `config/routes.php`

**Step 1: Add getFlags action**

```php
public function getFlags($id = null)
{
    $this->request->allowMethod(['get']);
    $this->autoRender = false;

    $noveltyType = $this->NoveltyTypes->get($id);

    return $this->response
        ->withType('application/json')
        ->withStringBody(json_encode([
            'requires_rrhh' => $noveltyType->requires_rrhh,
            'requires_firmas' => $noveltyType->requires_firmas,
            'requires_gdp' => $noveltyType->requires_gdp,
            'requires_tesoreria' => $noveltyType->requires_tesoreria,
            'show_start_date' => $noveltyType->show_start_date,
            'show_end_date' => $noveltyType->show_end_date,
            'show_permission_date' => $noveltyType->show_permission_date,
            'show_schedule_type' => $noveltyType->show_schedule_type,
            'uses_custom_name' => $noveltyType->uses_custom_name,
            'is_massive' => $noveltyType->is_massive,
        ]));
}
```

**Step 2: Add route**

```php
$builder->connect(
    '/novelty-types/get-flags/{id}',
    ['controller' => 'NoveltyTypes', 'action' => 'getFlags'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

**Step 3: Commit**

```bash
git add src/Controller/NoveltyTypesController.php config/routes.php
git commit -m "feat(novelties): add AJAX endpoint for novelty type flags"
```

---

## Task 17: Frontend JS for Dynamic Add Form

**Files:**
- Create: `webroot/js/novelty-add.js`

**Step 1: Create JS for dynamic form behavior**

When novelty_type_id select changes:
1. Fetch flags from `/novelty-types/get-flags/{id}`
2. Show/hide fields based on flags:
   - `show_permission_date` → toggle permission_date field
   - `show_schedule_type` → toggle schedule_type field
   - `show_start_date` → toggle start_date field
   - `show_end_date` → toggle end_date field
   - `uses_custom_name` → toggle between employee select and text input
   - `is_massive` → toggle between single select and multi-select Select2

**Step 2: Include in add.php template**

```php
<?= $this->Html->script('novelty-add') ?>
```

**Step 3: Commit**

```bash
git add webroot/js/novelty-add.js
git commit -m "feat(novelties): add JS for dynamic novelty add form based on type flags"
```

---

## Task 18: Final Integration & Verification

**Step 1: Run code style check**

```bash
composer cs-check
```
Fix any issues found.

**Step 2: Run tests**

```bash
composer test
```
Fix any failures.

**Step 3: Verify migrations**

```bash
bin/cake migrations status
```
All migrations should be "up".

**Step 4: Manual smoke test**

1. Visit `/novelty-types` — verify pipeline config toggles appear in edit form
2. Visit `/employee-novelties` — verify pipeline_status filter and badges work
3. Create a new novelty — verify dynamic form shows/hides fields
4. View a novelty — verify pipeline progress bar shows
5. Advance a novelty from registro → rrhh → contabilidad
6. In contabilidad, assign to a liquidation doc
7. Visit `/novelty-liquidation-docs` — verify index shows the doc
8. View liquidation doc — verify member list, signatures, advance group

**Step 5: Final commit**

```bash
git add -A
git commit -m "feat(novelties): complete pipeline implementation with all views and services"
```

---

## Summary of All Files

### New Files (18)
| File | Purpose |
|------|---------|
| `config/Migrations/20260313000001_*` through `20260313000008_*` | 8 migrations |
| `src/Service/NoveltyPipelineService.php` | Pipeline orchestration |
| `src/Service/NoveltyDocumentService.php` | File uploads for novelties & groups |
| `src/Service/NoveltyObservationService.php` | Observation chat |
| `src/Controller/NoveltyLiquidationDocsController.php` | Group management |
| `src/Model/Entity/NoveltyLiquidationDoc.php` | Entity |
| `src/Model/Table/NoveltyLiquidationDocsTable.php` | Table |
| `src/Model/Entity/NoveltyLiquidationSignature.php` | Entity |
| `src/Model/Table/NoveltyLiquidationSignaturesTable.php` | Table |
| `src/Model/Entity/NoveltyMassiveEmployee.php` | Entity |
| `src/Model/Table/NoveltyMassiveEmployeesTable.php` | Table |
| `src/Model/Entity/NoveltyObservation.php` | Entity |
| `src/Model/Table/NoveltyObservationsTable.php` | Table |
| `src/Model/Entity/NoveltyDocument.php` | Entity |
| `src/Model/Table/NoveltyDocumentsTable.php` | Table |
| `templates/NoveltyLiquidationDocs/index.php` | Group list view |
| `templates/NoveltyLiquidationDocs/view.php` | Group detail view |
| `webroot/js/novelty-add.js` | Dynamic form JS |

### Modified Files (12)
| File | Change |
|------|--------|
| `src/Constants/NoveltyConstants.php` | Pipeline statuses, periods, payments, signers |
| `src/Model/Entity/EmployeeNovelty.php` | New fields, domain helpers |
| `src/Model/Entity/NoveltyType.php` | Pipeline config flags |
| `src/Model/Table/EmployeeNoveltiesTable.php` | New associations, validation updates |
| `src/Model/Table/NoveltyTypesTable.php` | Boolean flag validation |
| `src/Controller/EmployeeNoveltiesController.php` | Pipeline actions |
| `src/Controller/NoveltyTypesController.php` | getFlags AJAX endpoint |
| `src/Controller/AppController.php` | Module map, sidebar counter |
| `src/Service/AuthorizationService.php` | New module |
| `config/routes.php` | Pipeline routes |
| `templates/element/pipeline_progress.php` | Custom icons support |
| `templates/layout/default.php` | Sidebar link |
| `templates/EmployeeNovelties/index.php` | Pipeline status filter/badges |
| `templates/EmployeeNovelties/view.php` | Pipeline UI, docs, observations |
| `templates/EmployeeNovelties/add.php` | Dynamic form with type flags |
| `templates/NoveltyTypes/edit.php` | Pipeline config toggles |
| `templates/NoveltyTypes/add.php` | Pipeline config toggles |
