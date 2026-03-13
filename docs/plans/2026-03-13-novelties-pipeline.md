# Novelties Pipeline — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reemplazar el modelo simple pendiente/aprobado/rechazado de novedades por un pipeline de 6 etapas (registro → rrhh → contabilidad → firmas_aprobacion → gdp → tesoreria → pagada) con agrupación de novedades bajo un Documento de Liquidación a partir de Contabilidad.

**Architecture:** `employee_novelties.pipeline_status` rastrea el estado de cada novedad. A partir de contabilidad, las novedades comparten un `novelty_liquidation_docs` que almacena datos de etapas 3–6. El avance desde contabilidad en adelante es siempre grupal. Un `NoveltyPipelineService` análogo a `InvoicePipelineService` maneja toda la lógica de transiciones, leyendo flags de `novelty_types` para saltar etapas que no aplican.

**Tech Stack:** CakePHP 5.3 · PHP 8.2+ · MariaDB · Bootstrap 5 · Phinx migrations (BaseMigration)

**Design doc:** `docs/plans/2026-03-13-novelties-pipeline-design.md`

---

## Phase 1: Constants & Migrations

---

### Task 1: Actualizar NoveltyConstants

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`

**Step 1: Reemplazar el contenido completo**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    // Pipeline statuses
    public const STATUS_REGISTRO           = 'registro';
    public const STATUS_RRHH               = 'rrhh';
    public const STATUS_CONTABILIDAD       = 'contabilidad';
    public const STATUS_FIRMAS_APROBACION  = 'firmas_aprobacion';
    public const STATUS_GDP                = 'gdp';
    public const STATUS_TESORERIA          = 'tesoreria';
    public const STATUS_PAGADA             = 'pagada';
    public const STATUS_RECHAZADA          = 'rechazada';

    public const PIPELINE_STATUSES = [
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
        self::STATUS_REGISTRO          => 'Registro',
        self::STATUS_RRHH              => 'RRHH',
        self::STATUS_CONTABILIDAD      => 'Contabilidad',
        self::STATUS_FIRMAS_APROBACION => 'Firmas y Aprobación',
        self::STATUS_GDP               => 'GDP',
        self::STATUS_TESORERIA         => 'Tesorería',
        self::STATUS_PAGADA            => 'Pagada',
        self::STATUS_RECHAZADA         => 'Rechazada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_REGISTRO          => 'bi-pencil-square',
        self::STATUS_RRHH              => 'bi-people',
        self::STATUS_CONTABILIDAD      => 'bi-calculator',
        self::STATUS_FIRMAS_APROBACION => 'bi-pen',
        self::STATUS_GDP               => 'bi-clipboard-check',
        self::STATUS_TESORERIA         => 'bi-bank',
        self::STATUS_PAGADA            => 'bi-cash-coin',
        self::STATUS_RECHAZADA         => 'bi-x-circle',
    ];

    // Period options for liquidation docs
    public const PERIOD_PRIMERA_QUINCENA  = 'primera_quincena';
    public const PERIOD_SEGUNDA_QUINCENA  = 'segunda_quincena';
    public const PERIOD_CIERRE_NOMINA     = 'cierre_nomina';

    public const PERIODS = [
        self::PERIOD_PRIMERA_QUINCENA,
        self::PERIOD_SEGUNDA_QUINCENA,
        self::PERIOD_CIERRE_NOMINA,
    ];

    public const PERIOD_LABELS = [
        self::PERIOD_PRIMERA_QUINCENA => '1ra Quincena',
        self::PERIOD_SEGUNDA_QUINCENA => '2da Quincena',
        self::PERIOD_CIERRE_NOMINA    => 'Cierre de Nómina',
    ];

    // Payment statuses (tesoreria)
    public const PAYMENT_PAGADO    = 'pagado';
    public const PAYMENT_PENDIENTE = 'pendiente';
    public const PAYMENT_NA        = 'na';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_PAGADO,
        self::PAYMENT_PENDIENTE,
        self::PAYMENT_NA,
    ];

    public const PAYMENT_LABELS = [
        self::PAYMENT_PAGADO    => 'Pagado',
        self::PAYMENT_PENDIENTE => 'Pendiente',
        self::PAYMENT_NA        => 'N/A',
    ];

    // Signer types for firmas_aprobacion
    public const SIGNER_CONTADOR          = 'contador';
    public const SIGNER_COORDINADOR_ADMIN = 'coordinador_admin';
    public const SIGNER_JEFE_INMEDIATO    = 'jefe_inmediato';
    public const SIGNER_TRABAJADOR        = 'trabajador';

    public const SIGNER_TYPES = [
        self::SIGNER_CONTADOR,
        self::SIGNER_COORDINADOR_ADMIN,
        self::SIGNER_JEFE_INMEDIATO,
        self::SIGNER_TRABAJADOR,
    ];

    public const SIGNER_LABELS = [
        self::SIGNER_CONTADOR          => 'Contador',
        self::SIGNER_COORDINADOR_ADMIN => 'Coordinador Administrativo',
        self::SIGNER_JEFE_INMEDIATO    => 'Jefe Inmediato',
        self::SIGNER_TRABAJADOR        => 'Trabajador',
    ];

    // Schedule types (existing, kept for compatibility)
    public const SCHEDULE_DAYS  = 'days';
    public const SCHEDULE_HOURS = 'hours';
    public const SCHEDULE_TYPES = [self::SCHEDULE_DAYS, self::SCHEDULE_HOURS];

    public const SCHEDULE_LABELS = [
        self::SCHEDULE_DAYS  => 'Por días',
        self::SCHEDULE_HOURS => 'Por horas',
    ];
}
```

**Step 2: Verificar que el archivo compila**

```bash
composer cs-check src/Constants/NoveltyConstants.php
```
Expected: No errors.

**Step 3: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "feat(novelties): expand NoveltyConstants with full pipeline statuses, periods, payments, signers"
```

---

### Task 2: Migración — columnas de configuración en novelty_types

**Files:**
- Create: `config/Migrations/20260313000001_AddConfigColumnsToNoveltyTypes.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddConfigColumnsToNoveltyTypes extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');
        $table
            ->addColumn('requires_rrhh', 'boolean', ['null' => false, 'default' => true, 'after' => 'parent_id'])
            ->addColumn('requires_firmas', 'boolean', ['null' => false, 'default' => true, 'after' => 'requires_rrhh'])
            ->addColumn('requires_gdp', 'boolean', ['null' => false, 'default' => true, 'after' => 'requires_firmas'])
            ->addColumn('requires_tesoreria', 'boolean', ['null' => false, 'default' => true, 'after' => 'requires_gdp'])
            ->addColumn('show_start_date', 'boolean', ['null' => false, 'default' => true, 'after' => 'requires_tesoreria'])
            ->addColumn('show_end_date', 'boolean', ['null' => false, 'default' => true, 'after' => 'show_start_date'])
            ->addColumn('show_permission_date', 'boolean', ['null' => false, 'default' => true, 'after' => 'show_end_date'])
            ->addColumn('show_schedule_type', 'boolean', ['null' => false, 'default' => true, 'after' => 'show_permission_date'])
            ->addColumn('uses_custom_name', 'boolean', ['null' => false, 'default' => false, 'after' => 'show_schedule_type'])
            ->addColumn('is_massive', 'boolean', ['null' => false, 'default' => false, 'after' => 'uses_custom_name'])
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

**Step 2: Aplicar la migración**

```bash
bin/cake migrations migrate
```
Expected: `== 20260313000001 AddConfigColumnsToNoveltyTypes: migrated`

**Step 3: Commit**

```bash
git add config/Migrations/20260313000001_AddConfigColumnsToNoveltyTypes.php
git commit -m "feat(novelties): add pipeline config columns to novelty_types"
```

---

### Task 3: Migración — modificar employee_novelties

**Files:**
- Create: `config/Migrations/20260313000002_UpdateEmployeeNoveltiesForPipeline.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class UpdateEmployeeNoveltiesForPipeline extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employee_novelties');

        // Add new columns
        $table
            ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false, 'default' => 'registro', 'after' => 'observations'])
            ->addColumn('passes_payroll', 'boolean', ['null' => true, 'default' => null, 'after' => 'pipeline_status'])
            ->addColumn('rrhh_by', 'integer', ['null' => true, 'default' => null, 'after' => 'passes_payroll'])
            ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null, 'after' => 'rrhh_by'])
            ->addColumn('custom_name', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'liquidation_doc_id'])
            ->update();

        // Make employee_id nullable for massive novelties
        $this->table('employee_novelties')
            ->changeColumn('employee_id', 'integer', ['null' => true, 'default' => null])
            ->update();

        // Migrate existing status values to pipeline_status
        $this->execute("UPDATE employee_novelties SET pipeline_status = CASE
            WHEN status = 'rechazado' THEN 'rechazada'
            WHEN status = 'aprobado' THEN 'pagada'
            ELSE 'registro'
        END");

        // Add FK for rrhh_by and liquidation_doc_id (after table is created in task 4)
        // rrhh_by FK added here since users table exists
        $this->table('employee_novelties')
            ->addForeignKey('rrhh_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('employee_novelties')
            ->dropForeignKey('rrhh_by')
            ->update();

        $table = $this->table('employee_novelties');
        $table
            ->removeColumn('pipeline_status')
            ->removeColumn('passes_payroll')
            ->removeColumn('rrhh_by')
            ->removeColumn('liquidation_doc_id')
            ->removeColumn('custom_name')
            ->update();

        $this->table('employee_novelties')
            ->changeColumn('employee_id', 'integer', ['null' => false])
            ->update();
    }
}
```

**Step 2: Aplicar**

```bash
bin/cake migrations migrate
```
Expected: `== 20260313000002 UpdateEmployeeNoveltiesForPipeline: migrated`

**Step 3: Commit**

```bash
git add config/Migrations/20260313000002_UpdateEmployeeNoveltiesForPipeline.php
git commit -m "feat(novelties): add pipeline_status, rrhh fields, custom_name and liquidation FK to employee_novelties"
```

---

### Task 4: Migración — crear novelty_liquidation_docs

**Files:**
- Create: `config/Migrations/20260313000003_CreateNoveltyLiquidationDocs.php`

**Step 1: Crear la migración**

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
                ->addColumn('period', 'string', ['limit' => 30, 'null' => true, 'default' => null])
                ->addColumn('document_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('performed_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('passes_for_payment', 'boolean', ['null' => true, 'default' => null])
                ->addColumn('payment_status', 'string', ['limit' => 20, 'null' => true, 'default' => null])
                ->addColumn('payment_date', 'date', ['null' => true, 'default' => null])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['liquidation_number'], ['unique' => true])
                ->addForeignKey('performed_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION'])
                ->create();
        }

        // Now add FK from employee_novelties to novelty_liquidation_docs
        $this->table('employee_novelties')
            ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
    }

    public function down(): void
    {
        $this->table('employee_novelties')
            ->dropForeignKey('liquidation_doc_id')
            ->update();

        if ($this->hasTable('novelty_liquidation_docs')) {
            $this->table('novelty_liquidation_docs')->drop()->save();
        }
    }
}
```

**Step 2: Aplicar**

```bash
bin/cake migrations migrate
```
Expected: `== 20260313000003 CreateNoveltyLiquidationDocs: migrated`

**Step 3: Commit**

```bash
git add config/Migrations/20260313000003_CreateNoveltyLiquidationDocs.php
git commit -m "feat(novelties): create novelty_liquidation_docs table and FK from employee_novelties"
```

---

### Task 5: Migraciones — tablas auxiliares (massive_employees, signatures, observations, documents)

**Files:**
- Create: `config/Migrations/20260313000004_CreateNoveltyAuxiliaryTables.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateNoveltyAuxiliaryTables extends BaseMigration
{
    public function up(): void
    {
        // novelty_massive_employees
        if (!$this->hasTable('novelty_massive_employees')) {
            $this->table('novelty_massive_employees')
                ->addColumn('novelty_id', 'integer', ['null' => false])
                ->addColumn('employee_id', 'integer', ['null' => false])
                ->addForeignKey('novelty_id', 'employee_novelties', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }

        // novelty_liquidation_signatures
        if (!$this->hasTable('novelty_liquidation_signatures')) {
            $this->table('novelty_liquidation_signatures')
                ->addColumn('liquidation_doc_id', 'integer', ['null' => false])
                ->addColumn('signer_type', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('signature_path', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addColumn('signed_by', 'integer', ['null' => true, 'default' => null])
                ->addColumn('approved_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addForeignKey('liquidation_doc_id', 'novelty_liquidation_docs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('signed_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
                ->create();
        }

        // novelty_observations
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

        // novelty_documents
        if (!$this->hasTable('novelty_documents')) {
            $this->table('novelty_documents')
                ->addColumn('novelty_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('liquidation_doc_id', 'integer', ['null' => true, 'default' => null])
                ->addColumn('pipeline_status', 'string', ['limit' => 30, 'null' => false])
                ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('file_size', 'integer', ['null' => true, 'default' => null])
                ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
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
        foreach (['novelty_documents', 'novelty_observations', 'novelty_liquidation_signatures', 'novelty_massive_employees'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }
}
```

**Step 2: Aplicar**

```bash
bin/cake migrations migrate
```
Expected: `== 20260313000004 CreateNoveltyAuxiliaryTables: migrated`

**Step 3: Commit**

```bash
git add config/Migrations/20260313000004_CreateNoveltyAuxiliaryTables.php
git commit -m "feat(novelties): create auxiliary tables: massive_employees, signatures, observations, documents"
```

---

### Task 6: Migración — permisos para novelty_liquidation_docs

**Files:**
- Create: `config/Migrations/20260313000005_AddNoveltyLiquidationPermissions.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddNoveltyLiquidationPermissions extends BaseMigration
{
    public function up(): void
    {
        $rolesTable = $this->table('roles');
        $roles = $this->fetchAll('SELECT id, name FROM roles');
        $roleMap = array_column($roles, 'id', 'name');

        $permissions = [];
        foreach ($roles as $role) {
            $isAdmin = $role['name'] === 'Administrador';
            $permissions[] = [
                'role_id'    => $role['id'],
                'module'     => 'novelty_liquidation_docs',
                'can_view'   => 1,
                'can_create' => $isAdmin ? 1 : 0,
                'can_edit'   => 1,
                'can_delete' => $isAdmin ? 1 : 0,
            ];
        }

        $this->table('permissions')->insert($permissions)->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'novelty_liquidation_docs'");
    }
}
```

**Step 2: Aplicar**

```bash
bin/cake migrations migrate
```
Expected: `== 20260313000005 AddNoveltyLiquidationPermissions: migrated`

**Step 3: Commit**

```bash
git add config/Migrations/20260313000005_AddNoveltyLiquidationPermissions.php
git commit -m "feat(novelties): add permissions for novelty_liquidation_docs module"
```

---

## Phase 2: Models

---

### Task 7: Actualizar NoveltyType entity + NoveltyTypesTable

**Files:**
- Modify: `src/Model/Entity/NoveltyType.php`
- Modify: `src/Model/Table/NoveltyTypesTable.php`

**Step 1: Actualizar entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyType extends Entity
{
    protected array $_accessible = [
        'name'                => true,
        'parent_id'           => true,
        'requires_rrhh'       => true,
        'requires_firmas'     => true,
        'requires_gdp'        => true,
        'requires_tesoreria'  => true,
        'show_start_date'     => true,
        'show_end_date'       => true,
        'show_permission_date'=> true,
        'show_schedule_type'  => true,
        'uses_custom_name'    => true,
        'is_massive'          => true,
        'novelty_type_contract_templates' => true,
    ];
}
```

**Step 2: Actualizar NoveltyTypesTable — agregar validaciones para los nuevos campos**

En `validationDefault()` agregar después de la validación de `parent_id`:

```php
foreach ([
    'requires_rrhh', 'requires_firmas', 'requires_gdp', 'requires_tesoreria',
    'show_start_date', 'show_end_date', 'show_permission_date', 'show_schedule_type',
    'uses_custom_name', 'is_massive',
] as $boolField) {
    $validator->boolean($boolField)->notEmptyString($boolField);
}
```

**Step 3: Verificar estilo**

```bash
composer cs-check src/Model/Entity/NoveltyType.php src/Model/Table/NoveltyTypesTable.php
```

**Step 4: Commit**

```bash
git add src/Model/Entity/NoveltyType.php src/Model/Table/NoveltyTypesTable.php
git commit -m "feat(novelties): update NoveltyType entity and table with pipeline config fields"
```

---

### Task 8: Actualizar EmployeeNovelty entity + EmployeeNoveltiesTable

**Files:**
- Modify: `src/Model/Entity/EmployeeNovelty.php`
- Modify: `src/Model/Table/EmployeeNoveltiesTable.php`

**Step 1: Actualizar entity — agregar campos nuevos a `$_accessible` y helpers de estado**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\ORM\Entity;

class EmployeeNovelty extends Entity
{
    protected array $_accessible = [
        'employee_id'         => true,
        'novelty_type_id'     => true,
        'filing_date'         => true,
        'permission_date'     => true,
        'schedule_type'       => true,
        'start_date'          => true,
        'end_date'            => true,
        'start_time'          => true,
        'end_time'            => true,
        'is_paid'             => true,
        'reason'              => true,
        'pipeline_status'     => true,
        'passes_payroll'      => true,
        'rrhh_by'             => true,
        'liquidation_doc_id'  => true,
        'custom_name'         => true,
        'registered_by'       => true,
        'employee_signature'  => true,
        'coordinator_signature' => true,
        'observations'        => true,
    ];

    public function isRechazada(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
    }

    public function isPagada(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_PAGADA;
    }

    public function isGrouped(): bool
    {
        return !empty($this->liquidation_doc_id);
    }

    public function isAtStage(string $status): bool
    {
        return $this->pipeline_status === $status;
    }
}
```

**Step 2: Actualizar EmployeeNoveltiesTable**

- Agregar asociación `belongsTo('RrhhByUsers', ['className' => 'Users', 'foreignKey' => 'rrhh_by', 'joinType' => 'LEFT'])`
- Agregar asociación `belongsTo('NoveltyLiquidationDocs', ['foreignKey' => 'liquidation_doc_id', 'joinType' => 'LEFT'])`
- Agregar asociación `hasMany('NoveltyMassiveEmployees', ['foreignKey' => 'novelty_id', 'dependent' => true])`
- Agregar asociación `hasMany('NoveltyObservations', ['foreignKey' => 'novelty_id', 'dependent' => true])`
- Agregar asociación `hasMany('NoveltyDocuments', ['foreignKey' => 'novelty_id', 'dependent' => true])`
- En `validationDefault()`: cambiar `'status'` por `'pipeline_status'` con `inList('pipeline_status', NoveltyConstants::PIPELINE_STATUSES)`
- Hacer `employee_id` opcional: cambiar `requirePresence` por `allowEmptyString`
- Hacer `permission_date`, `schedule_type` opcionales (allowEmpty)
- Agregar `passes_payroll` como `boolean()->allowEmptyString()`
- Agregar `custom_name` como `scalar()->allowEmptyString()`

**Step 3: Verificar**

```bash
composer cs-check src/Model/Entity/EmployeeNovelty.php src/Model/Table/EmployeeNoveltiesTable.php
```

**Step 4: Commit**

```bash
git add src/Model/Entity/EmployeeNovelty.php src/Model/Table/EmployeeNoveltiesTable.php
git commit -m "feat(novelties): update EmployeeNovelty entity and table for pipeline"
```

---

### Task 9: Crear modelos de tablas nuevas

**Files:**
- Create: `src/Model/Entity/NoveltyLiquidationDoc.php`
- Create: `src/Model/Table/NoveltyLiquidationDocsTable.php`
- Create: `src/Model/Entity/NoveltyLiquidationSignature.php`
- Create: `src/Model/Table/NoveltyLiquidationSignaturesTable.php`
- Create: `src/Model/Entity/NoveltyObservation.php`
- Create: `src/Model/Table/NoveltyObservationsTable.php`
- Create: `src/Model/Entity/NoveltyDocument.php`
- Create: `src/Model/Table/NoveltyDocumentsTable.php`
- Create: `src/Model/Entity/NoveltyMassiveEmployee.php`
- Create: `src/Model/Table/NoveltyMassiveEmployeesTable.php`

**Step 1: NoveltyLiquidationDoc entity**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\ORM\Entity;

class NoveltyLiquidationDoc extends Entity
{
    protected array $_accessible = [
        'liquidation_number'  => true,
        'period'              => true,
        'document_date'       => true,
        'performed_by'        => true,
        'passes_for_payment'  => true,
        'payment_status'      => true,
        'payment_date'        => true,
        'created_by'          => true,
    ];

    public function isPagada(): bool
    {
        // A doc is considered paid when all its novelties are pagada
        return $this->payment_status === NoveltyConstants::PAYMENT_PAGADO;
    }
}
```

**Step 2: NoveltyLiquidationDocsTable**

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
            'className'  => 'Users',
            'foreignKey' => 'performed_by',
            'joinType'   => 'LEFT',
        ]);
        $this->belongsTo('CreatedByUsers', [
            'className'  => 'Users',
            'foreignKey' => 'created_by',
            'joinType'   => 'INNER',
        ]);
        $this->hasMany('EmployeeNovelties', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent'  => false,
        ]);
        $this->hasMany('NoveltyLiquidationSignatures', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent'  => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('NoveltyObservations', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent'  => true,
        ]);
        $this->hasMany('NoveltyDocuments', [
            'foreignKey' => 'liquidation_doc_id',
            'dependent'  => true,
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
            ->allowEmptyString('period');

        $validator
            ->date('document_date')
            ->allowEmptyDate('document_date');

        $validator
            ->integer('performed_by')
            ->allowEmptyString('performed_by');

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
        $rules->add($rules->isUnique(['liquidation_number']), ['errorField' => 'liquidation_number']);
        $rules->add($rules->existsIn('performed_by', 'PerformedByUsers'), [
            'errorField' => 'performed_by',
            'allowNullableNulls' => true,
        ]);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }
}
```

**Step 3: Entidades y tablas restantes (crear cada una)**

`NoveltyLiquidationSignature` entity — `$_accessible`: liquidation_doc_id, signer_type, signature_path, signed_by, approved_at

`NoveltyLiquidationSignaturesTable` — belongsTo LiquidationDoc, belongsTo SignedByUsers (Users). Validation: signer_type inList(NoveltyConstants::SIGNER_TYPES).

`NoveltyObservation` entity — `$_accessible`: novelty_id, liquidation_doc_id, user_id, message, is_read

`NoveltyObservationsTable` — belongsTo EmployeeNovelties, belongsTo NoveltyLiquidationDocs, belongsTo Users. Validation: message notEmpty.

`NoveltyDocument` entity — `$_accessible`: novelty_id, liquidation_doc_id, pipeline_status, file_path, file_name, file_size, mime_type, uploaded_by

`NoveltyDocumentsTable` — belongsTo EmployeeNovelties, belongsTo NoveltyLiquidationDocs, belongsTo UploadedByUsers. Validation: pipeline_status inList, file_path/file_name notEmpty.

`NoveltyMassiveEmployee` entity — `$_accessible`: novelty_id, employee_id

`NoveltyMassiveEmployeesTable` — belongsTo EmployeeNovelties, belongsTo Employees.

**Step 4: Verificar estilo**

```bash
composer cs-check src/Model/
```

**Step 5: Commit**

```bash
git add src/Model/
git commit -m "feat(novelties): create all new entity and table classes for pipeline"
```

---

## Phase 3: Services

---

### Task 10: Crear NoveltyPipelineService

**Files:**
- Create: `src/Service/NoveltyPipelineService.php`
- Create: `tests/TestCase/Service/NoveltyPipelineServiceTest.php`

**Step 1: Escribir el test primero**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\NoveltyConstants;
use App\Service\NoveltyPipelineService;
use Cake\TestSuite\TestCase;

class NoveltyPipelineServiceTest extends TestCase
{
    private NoveltyPipelineService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new NoveltyPipelineService();
    }

    // Test: tipo que requiere todas las etapas avanza paso a paso
    public function testGetNextStatusFullPipeline(): void
    {
        $type = $this->_makeType(true, true, true, true);
        $this->assertSame(NoveltyConstants::STATUS_RRHH, $this->service->getNextStatus(NoveltyConstants::STATUS_REGISTRO, $type));
        $this->assertSame(NoveltyConstants::STATUS_CONTABILIDAD, $this->service->getNextStatus(NoveltyConstants::STATUS_RRHH, $type));
        $this->assertSame(NoveltyConstants::STATUS_FIRMAS_APROBACION, $this->service->getNextStatus(NoveltyConstants::STATUS_CONTABILIDAD, $type));
        $this->assertSame(NoveltyConstants::STATUS_GDP, $this->service->getNextStatus(NoveltyConstants::STATUS_FIRMAS_APROBACION, $type));
        $this->assertSame(NoveltyConstants::STATUS_TESORERIA, $this->service->getNextStatus(NoveltyConstants::STATUS_GDP, $type));
        $this->assertSame(NoveltyConstants::STATUS_PAGADA, $this->service->getNextStatus(NoveltyConstants::STATUS_TESORERIA, $type));
        $this->assertNull($this->service->getNextStatus(NoveltyConstants::STATUS_PAGADA, $type));
    }

    // Test: tipo que no requiere rrhh ni firmas ni gdp
    public function testGetNextStatusSkipsOptionalStages(): void
    {
        $type = $this->_makeType(false, false, false, true); // solo tesoreria
        $this->assertSame(NoveltyConstants::STATUS_CONTABILIDAD, $this->service->getNextStatus(NoveltyConstants::STATUS_REGISTRO, $type));
        $this->assertSame(NoveltyConstants::STATUS_TESORERIA, $this->service->getNextStatus(NoveltyConstants::STATUS_CONTABILIDAD, $type));
        $this->assertSame(NoveltyConstants::STATUS_PAGADA, $this->service->getNextStatus(NoveltyConstants::STATUS_TESORERIA, $type));
    }

    // Test: novedad agrupada no puede avanzar individualmente
    public function testCannotAdvanceIndividuallyWhenGrouped(): void
    {
        $novelty = new \stdClass();
        $novelty->liquidation_doc_id = 5;
        $this->assertFalse($this->service->canAdvanceIndividually($novelty));
    }

    public function testCanAdvanceIndividuallyWhenNotGrouped(): void
    {
        $novelty = new \stdClass();
        $novelty->liquidation_doc_id = null;
        $this->assertTrue($this->service->canAdvanceIndividually($novelty));
    }

    private function _makeType(bool $rrhh, bool $firmas, bool $gdp, bool $tesoreria): object
    {
        $type = new \stdClass();
        $type->requires_rrhh      = $rrhh;
        $type->requires_firmas    = $firmas;
        $type->requires_gdp       = $gdp;
        $type->requires_tesoreria = $tesoreria;
        return $type;
    }
}
```

**Step 2: Correr el test — debe fallar**

```bash
composer test -- tests/TestCase/Service/NoveltyPipelineServiceTest.php
```
Expected: FAIL — class not found.

**Step 3: Implementar NoveltyPipelineService**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use Cake\ORM\TableRegistry;

class NoveltyPipelineService
{
    // Ordered pipeline stages (excluding rechazada)
    public const ORDERED_STATUSES = [
        NoveltyConstants::STATUS_REGISTRO,
        NoveltyConstants::STATUS_RRHH,
        NoveltyConstants::STATUS_CONTABILIDAD,
        NoveltyConstants::STATUS_FIRMAS_APROBACION,
        NoveltyConstants::STATUS_GDP,
        NoveltyConstants::STATUS_TESORERIA,
        NoveltyConstants::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = NoveltyConstants::STATUS_LABELS;
    public const STATUS_ICONS  = NoveltyConstants::STATUS_ICONS;

    /**
     * Get next pipeline status for a novelty type, skipping disabled stages.
     */
    public function getNextStatus(string $currentStatus, object $noveltyType): ?string
    {
        $ordered = $this->_buildOrderedPipeline($noveltyType);
        $idx = array_search($currentStatus, $ordered);
        if ($idx === false) {
            return null;
        }
        return $ordered[$idx + 1] ?? null;
    }

    /**
     * Build ordered pipeline for a novelty type (only enabled stages).
     */
    private function _buildOrderedPipeline(object $noveltyType): array
    {
        $pipeline = [NoveltyConstants::STATUS_REGISTRO];

        if ($noveltyType->requires_rrhh ?? true) {
            $pipeline[] = NoveltyConstants::STATUS_RRHH;
        }

        $pipeline[] = NoveltyConstants::STATUS_CONTABILIDAD;

        if ($noveltyType->requires_firmas ?? true) {
            $pipeline[] = NoveltyConstants::STATUS_FIRMAS_APROBACION;
        }

        if ($noveltyType->requires_gdp ?? true) {
            $pipeline[] = NoveltyConstants::STATUS_GDP;
        }

        if ($noveltyType->requires_tesoreria ?? true) {
            $pipeline[] = NoveltyConstants::STATUS_TESORERIA;
        }

        $pipeline[] = NoveltyConstants::STATUS_PAGADA;

        return $pipeline;
    }

    /**
     * Returns true if the novelty can be advanced individually (not grouped).
     */
    public function canAdvanceIndividually(object $novelty): bool
    {
        return empty($novelty->liquidation_doc_id);
    }

    /**
     * Validate transition requirements before advancing a novelty.
     * Returns array of error messages (empty = OK).
     */
    public function validateTransition(object $novelty, string $fromStatus): array
    {
        $errors = [];

        switch ($fromStatus) {
            case NoveltyConstants::STATUS_RRHH:
                if ($novelty->passes_payroll === null) {
                    $errors[] = 'Debe indicar si pasa a nómina antes de avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_CONTABILIDAD:
                if (empty($novelty->liquidation_doc_id)) {
                    $errors[] = 'Debe asignar un Documento de Liquidación antes de avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_GDP:
                if ($novelty->passes_for_payment === null) {
                    $errors[] = 'Debe indicar si pasa para pago antes de avanzar.';
                }
                break;

            case NoveltyConstants::STATUS_TESORERIA:
                if (empty($novelty->payment_status)) {
                    $errors[] = 'Debe indicar el estado de pago.';
                }
                if ($novelty->payment_status === NoveltyConstants::PAYMENT_PAGADO && empty($novelty->payment_date)) {
                    $errors[] = 'La fecha de pago es requerida cuando el estado es Pagado.';
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate group transition (liquidation doc level).
     */
    public function validateGroupTransition(object $liquidationDoc, string $fromStatus): array
    {
        $errors = [];

        switch ($fromStatus) {
            case NoveltyConstants::STATUS_CONTABILIDAD:
                if (empty($liquidationDoc->period)) {
                    $errors[] = 'El período es requerido.';
                }
                if (empty($liquidationDoc->document_date)) {
                    $errors[] = 'La fecha del documento es requerida.';
                }
                if (empty($liquidationDoc->performed_by)) {
                    $errors[] = 'Debe indicar quién realizó el documento.';
                }
                break;

            case NoveltyConstants::STATUS_FIRMAS_APROBACION:
                // Check that all required signatures are present
                // (caller must pass signatures count or the doc with signatures loaded)
                break;

            case NoveltyConstants::STATUS_GDP:
                if ($liquidationDoc->passes_for_payment === null) {
                    $errors[] = 'Debe indicar si pasa para pago.';
                }
                break;

            case NoveltyConstants::STATUS_TESORERIA:
                if (empty($liquidationDoc->payment_status)) {
                    $errors[] = 'El estado de pago es requerido.';
                }
                if ($liquidationDoc->payment_status === NoveltyConstants::PAYMENT_PAGADO && empty($liquidationDoc->payment_date)) {
                    $errors[] = 'La fecha de pago es requerida cuando el estado es Pagado.';
                }
                break;
        }

        return $errors;
    }

    /**
     * Advance a group (liquidation doc) and all its member novelties atomically.
     * Returns array of errors or empty array on success.
     */
    public function advanceGroup(object $liquidationDoc, object $user): array
    {
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');

        $currentStatus = $this->_getCurrentGroupStatus($liquidationDoc);
        $errors = $this->validateGroupTransition($liquidationDoc, $currentStatus);

        if (!empty($errors)) {
            return $errors;
        }

        // Load all member novelties with their types
        $novelties = $noveltiesTable->find()
            ->contain(['NoveltyTypes'])
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->all();

        $connection = $noveltiesTable->getConnection();

        try {
            $connection->begin();

            foreach ($novelties as $novelty) {
                $nextStatus = $this->getNextStatus($novelty->pipeline_status, $novelty->novelty_type);
                if ($nextStatus !== null) {
                    $novelty->pipeline_status = $nextStatus;
                    if (!$noveltiesTable->save($novelty)) {
                        $connection->rollback();
                        return ['Error al avanzar la novedad #' . $novelty->id];
                    }
                }
            }

            $connection->commit();

            return [];
        } catch (\Exception $e) {
            $connection->rollback();
            return ['Error inesperado al avanzar el grupo: ' . $e->getMessage()];
        }
    }

    /**
     * Get current group status (derived from member novelties — uses the first one).
     */
    private function _getCurrentGroupStatus(object $liquidationDoc): string
    {
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $first = $noveltiesTable->find()
            ->where(['liquidation_doc_id' => $liquidationDoc->id])
            ->select(['pipeline_status'])
            ->first();

        return $first ? $first->pipeline_status : NoveltyConstants::STATUS_CONTABILIDAD;
    }

    /**
     * Assign novelty to a liquidation doc, creating it if the number doesn't exist.
     * Changes novelty pipeline_status to contabilidad.
     * Returns the liquidation doc or array of errors.
     */
    public function assignToLiquidationDoc(object $novelty, string $liquidationNumber, array $data, object $user): object|array
    {
        $liquidationDocsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

        // Find or create liquidation doc
        $liquidationDoc = $liquidationDocsTable->find()
            ->where(['liquidation_number' => $liquidationNumber])
            ->first();

        if (!$liquidationDoc) {
            $liquidationDoc = $liquidationDocsTable->newEntity(array_merge($data, [
                'liquidation_number' => $liquidationNumber,
                'created_by'         => $user->id,
            ]));
            if (!$liquidationDocsTable->save($liquidationDoc)) {
                return ['No se pudo crear el Documento de Liquidación.'];
            }
        }

        // Link novelty
        $novelty->liquidation_doc_id = $liquidationDoc->id;
        $novelty->pipeline_status    = NoveltyConstants::STATUS_CONTABILIDAD;

        if (!$noveltiesTable->save($novelty)) {
            return ['No se pudo vincular la novedad al documento de liquidación.'];
        }

        return $liquidationDoc;
    }

    /**
     * Get visible fields for a novelty type at a given stage.
     */
    public function getVisibleFields(object $noveltyType, string $pipelineStatus): array
    {
        $base = [
            'show_start_date'      => $noveltyType->show_start_date ?? true,
            'show_end_date'        => $noveltyType->show_end_date ?? true,
            'show_permission_date' => $noveltyType->show_permission_date ?? true,
            'show_schedule_type'   => $noveltyType->show_schedule_type ?? true,
            'uses_custom_name'     => $noveltyType->uses_custom_name ?? false,
            'is_massive'           => $noveltyType->is_massive ?? false,
        ];

        return $base;
    }

    /**
     * Get the effective pipeline (ordered statuses) for a novelty type.
     */
    public function getPipelineForType(object $noveltyType): array
    {
        return $this->_buildOrderedPipeline($noveltyType);
    }
}
```

**Step 4: Correr los tests — deben pasar**

```bash
composer test -- tests/TestCase/Service/NoveltyPipelineServiceTest.php
```
Expected: All tests PASS.

**Step 5: Commit**

```bash
git add src/Service/NoveltyPipelineService.php tests/TestCase/Service/NoveltyPipelineServiceTest.php
git commit -m "feat(novelties): implement NoveltyPipelineService with tests"
```

---

### Task 11: Crear NoveltyDocumentService

**Files:**
- Create: `src/Service/NoveltyDocumentService.php`

**Step 1: Implementar el servicio (patrón de InvoiceDocumentService)**

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

    public function uploadForNovelty(int $noveltyId, string $pipelineStatus, UploadedFile $file, ?int $uploadedBy): object|string
    {
        return $this->_upload('novelty_id', $noveltyId, 'novelties', $noveltyId, $pipelineStatus, $file, $uploadedBy);
    }

    public function uploadForGroup(int $liquidationDocId, string $pipelineStatus, UploadedFile $file, ?int $uploadedBy): object|string
    {
        return $this->_upload('liquidation_doc_id', $liquidationDocId, 'novelty-liquidations', $liquidationDocId, $pipelineStatus, $file, $uploadedBy);
    }

    private function _upload(string $fkField, int $fkValue, string $subDir, int $entityId, string $pipelineStatus, UploadedFile $file, ?int $uploadedBy): object|string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'No se recibió ningún archivo válido.';
        }
        if ($file->getSize() > self::MAX_DOC_SIZE) {
            return 'El archivo excede el tamaño máximo de 10MB.';
        }
        if (!in_array($file->getClientMediaType(), self::ALLOWED_DOC_MIMES)) {
            return 'Tipo de archivo no permitido. Use PDF, imágenes, Word o Excel.';
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . $subDir . DS . $entityId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $uniqueName = uniqid('nov_') . '.' . $ext;
        $filePath = $uploadDir . DS . $uniqueName;
        $file->moveTo($filePath);

        $table = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $doc = $table->newEntity([
            $fkField          => $fkValue,
            'pipeline_status' => $pipelineStatus,
            'file_path'       => 'uploads/' . $subDir . '/' . $entityId . '/' . $uniqueName,
            'file_name'       => $file->getClientFilename(),
            'file_size'       => $file->getSize(),
            'mime_type'       => $file->getClientMediaType(),
            'uploaded_by'     => $uploadedBy,
        ]);

        if (!$table->save($doc)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return 'No se pudo guardar el documento.';
        }

        return $doc;
    }

    public function deleteDocument(int $documentId): bool
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyDocuments');
        $doc = $table->get($documentId);
        $filePath = WWW_ROOT . $doc->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        return $table->delete($doc);
    }

    public function canDeleteDocument(object $document, string $currentPipelineStatus): bool
    {
        return $document->pipeline_status === $currentPipelineStatus;
    }

    public function getDocumentsByStatus(int $noveltyId): array
    {
        $docs = TableRegistry::getTableLocator()->get('NoveltyDocuments')->find()
            ->where(['novelty_id' => $noveltyId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($docs as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }
        return $grouped;
    }

    public function getGroupDocumentsByStatus(int $liquidationDocId): array
    {
        $docs = TableRegistry::getTableLocator()->get('NoveltyDocuments')->find()
            ->where(['liquidation_doc_id' => $liquidationDocId])
            ->contain(['UploadedByUsers'])
            ->order(['NoveltyDocuments.created' => 'DESC'])
            ->all();

        $grouped = [];
        foreach ($docs as $doc) {
            $grouped[$doc->pipeline_status][] = $doc;
        }
        return $grouped;
    }
}
```

**Step 2: Verificar estilo**

```bash
composer cs-check src/Service/NoveltyDocumentService.php
```

**Step 3: Commit**

```bash
git add src/Service/NoveltyDocumentService.php
git commit -m "feat(novelties): implement NoveltyDocumentService"
```

---

### Task 12: Crear NoveltyObservationService

**Files:**
- Create: `src/Service/NoveltyObservationService.php`

**Step 1: Implementar**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class NoveltyObservationService
{
    public function addToNovelty(int $noveltyId, int $userId, string $message): object|false
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $obs = $table->newEntity([
            'novelty_id' => $noveltyId,
            'user_id'    => $userId,
            'message'    => $message,
            'is_read'    => false,
        ]);
        return $table->save($obs) ? $obs : false;
    }

    public function addToGroup(int $liquidationDocId, int $userId, string $message): object|false
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $obs = $table->newEntity([
            'liquidation_doc_id' => $liquidationDocId,
            'user_id'            => $userId,
            'message'            => $message,
            'is_read'            => false,
        ]);
        return $table->save($obs) ? $obs : false;
    }

    public function markNoveltyObservationsRead(int $noveltyId, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $table->updateAll(
            ['is_read' => true],
            ['novelty_id' => $noveltyId, 'user_id !=' => $userId, 'is_read' => false]
        );
    }

    public function markGroupObservationsRead(int $liquidationDocId, int $userId): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $table->updateAll(
            ['is_read' => true],
            ['liquidation_doc_id' => $liquidationDocId, 'user_id !=' => $userId, 'is_read' => false]
        );
    }

    public function getUnreadCountForNovelty(int $noveltyId, int $userId): int
    {
        return TableRegistry::getTableLocator()->get('NoveltyObservations')->find()
            ->where(['novelty_id' => $noveltyId, 'user_id !=' => $userId, 'is_read' => false])
            ->count();
    }

    public function getUnreadCountForGroup(int $liquidationDocId, int $userId): int
    {
        return TableRegistry::getTableLocator()->get('NoveltyObservations')->find()
            ->where(['liquidation_doc_id' => $liquidationDocId, 'user_id !=' => $userId, 'is_read' => false])
            ->count();
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyObservationService.php
git commit -m "feat(novelties): implement NoveltyObservationService"
```

---

## Phase 4: Controllers

---

### Task 13: Actualizar EmployeeNoveltiesController

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Reescribir el controlador con el nuevo pipeline**

El controlador debe:
- Inyectar `NoveltyPipelineService`, `NoveltyDocumentService`, `NoveltyObservationService` en `initialize()`
- `index()`: filtrar por `pipeline_status` y `novelty_type_id`, mostrar badge de estado
- `add()`: manejar `is_massive` (guardar en `novelty_massive_employees`), `uses_custom_name` (usar `custom_name` en vez de `employee_id`), iniciar `pipeline_status = 'registro'`
- `view($id)`: cargar novedad con todas las asociaciones, marcar observaciones como leídas, pasar docs agrupados por estado
- `advance($id)`: verificar que no esté agrupada (`canAdvanceIndividually`), validar transición, avanzar estado
- `reject($id)`: marcar como `rechazada`
- `addObservation($id)`: agregar observación a novedad individual
- `uploadDocument($id)`: subir soporte para etapa actual (etapas registro/rrhh)
- `deleteDocument($documentId)`: borrar soporte

Estructura básica:

```php
public function initialize(): void
{
    parent::initialize();
    $this->pipelineService     = new NoveltyPipelineService();
    $this->documentService     = new NoveltyDocumentService();
    $this->observationService  = new NoveltyObservationService();
}

public function advance($id = null)
{
    $this->request->allowMethod(['post']);
    $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
    $user    = $this->Authentication->getIdentity()->getOriginalData();

    if (!$this->pipelineService->canAdvanceIndividually($novelty)) {
        $this->Flash->error('Esta novedad está agrupada. Avanzar desde el Documento de Liquidación.');
        return $this->redirect(['action' => 'view', $id]);
    }

    $errors = $this->pipelineService->validateTransition($novelty, $novelty->pipeline_status);
    if (!empty($errors)) {
        foreach ($errors as $error) {
            $this->Flash->error($error);
        }
        return $this->redirect(['action' => 'view', $id]);
    }

    $nextStatus = $this->pipelineService->getNextStatus($novelty->pipeline_status, $novelty->novelty_type);
    if ($nextStatus === null) {
        $this->Flash->warning('Esta novedad ya se encuentra en el estado final.');
        return $this->redirect(['action' => 'view', $id]);
    }

    $novelty->pipeline_status = $nextStatus;
    if ($this->EmployeeNovelties->save($novelty)) {
        $this->Flash->success('Novedad avanzada a: ' . NoveltyConstants::STATUS_LABELS[$nextStatus]);
    } else {
        $this->Flash->error('No se pudo avanzar la novedad.');
    }

    return $this->redirect(['action' => 'view', $id]);
}
```

**Step 2: Asegurarse que el `add()` maneja masivo**

```php
// Dentro de add() al guardar:
if ($novelty->novelty_type->is_massive) {
    $employeeIds = $this->request->getData('employee_ids', []);
    foreach ($employeeIds as $empId) {
        $massiveEntry = $this->EmployeeNovelties->NoveltyMassiveEmployees->newEntity([
            'novelty_id'  => $novelty->id,
            'employee_id' => $empId,
        ]);
        $this->EmployeeNovelties->NoveltyMassiveEmployees->save($massiveEntry);
    }
}
```

**Step 3: Agregar action mappings en AppController**

En `_actionToPermission()` agregar: `'advance', 'addObservation', 'uploadDocument'` → `'edit'`, `'deleteDocument'` → `'delete'`.

También agregar en `$controllerModuleMap`:
```php
'NoveltyLiquidationDocs' => 'novelty_liquidation_docs',
```

**Step 4: Verificar estilo**

```bash
composer cs-check src/Controller/EmployeeNoveltiesController.php src/Controller/AppController.php
```

**Step 5: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php src/Controller/AppController.php
git commit -m "feat(novelties): update EmployeeNoveltiesController with pipeline advance, docs, observations"
```

---

### Task 14: Crear NoveltyLiquidationDocsController

**Files:**
- Create: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: Implementar el controlador**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\NoveltyConstants;
use App\Service\NoveltyDocumentService;
use App\Service\NoveltyObservationService;
use App\Service\NoveltyPipelineService;

class NoveltyLiquidationDocsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private NoveltyPipelineService $pipelineService;
    private NoveltyDocumentService $documentService;
    private NoveltyObservationService $observationService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipelineService    = new NoveltyPipelineService();
        $this->documentService    = new NoveltyDocumentService();
        $this->observationService = new NoveltyObservationService();
    }

    public function index()
    {
        $query = $this->NoveltyLiquidationDocs->find()
            ->contain(['CreatedByUsers', 'PerformedByUsers'])
            ->withCount(['EmployeeNovelties'])
            ->order(['NoveltyLiquidationDocs.created' => 'DESC']);

        $statusFilter = $this->request->getQuery('status');
        if ($statusFilter) {
            // Filter by current group status = first member novelty pipeline_status
            $noveltyIds = $this->NoveltyLiquidationDocs->EmployeeNovelties->find()
                ->where(['pipeline_status' => $statusFilter])
                ->select(['liquidation_doc_id'])
                ->distinct(['liquidation_doc_id'])
                ->all()
                ->map(fn($n) => $n->liquidation_doc_id)
                ->toArray();
            $query->where(['NoveltyLiquidationDocs.id IN' => $noveltyIds ?: [0]]);
        }

        $docs = $this->paginate($query);
        $statusLabels = NoveltyConstants::STATUS_LABELS;

        $this->set(compact('docs', 'statusFilter', 'statusLabels'));
    }

    public function view($id = null)
    {
        $doc = $this->NoveltyLiquidationDocs->get($id, contain: [
            'PerformedByUsers',
            'CreatedByUsers',
            'EmployeeNovelties' => ['Employees', 'NoveltyTypes'],
            'NoveltyLiquidationSignatures',
            'NoveltyObservations' => ['Users'],
        ]);

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $this->observationService->markGroupObservationsRead($id, $user->id);

        $documentsByStatus = $this->documentService->getGroupDocumentsByStatus($id);
        $accountingUsers   = $this->NoveltyLiquidationDocs->PerformedByUsers->find('list', [
            'keyField'   => 'id',
            'valueField' => 'username',
        ])->all();

        // Current group status = derived from first member novelty
        $currentStatus = $doc->employee_novelties[0]->pipeline_status ?? NoveltyConstants::STATUS_CONTABILIDAD;
        $canAdvance    = !in_array($currentStatus, [NoveltyConstants::STATUS_PAGADA, NoveltyConstants::STATUS_RECHAZADA]);

        $this->set(compact('doc', 'documentsByStatus', 'accountingUsers', 'currentStatus', 'canAdvance'));
    }

    public function advanceGroup($id = null)
    {
        $this->request->allowMethod(['post']);
        $doc  = $this->NoveltyLiquidationDocs->get($id, contain: ['EmployeeNovelties' => ['NoveltyTypes']]);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        // Save any submitted group-level fields first
        $data = $this->request->getData();
        if (!empty($data)) {
            $this->NoveltyLiquidationDocs->patchEntity($doc, $data);
            $this->NoveltyLiquidationDocs->save($doc);
        }

        $errors = $this->pipelineService->advanceGroup($doc, $user);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->Flash->error($error);
            }
        } else {
            $this->Flash->success('Grupo avanzado exitosamente.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function addSignature($id = null)
    {
        $this->request->allowMethod(['post']);
        $doc  = $this->NoveltyLiquidationDocs->get($id);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $signerType = $this->request->getData('signer_type');
        $base64     = $this->request->getData('signature_base64');

        if (!in_array($signerType, NoveltyConstants::SIGNER_TYPES)) {
            $this->Flash->error('Tipo de firmante inválido.');
            return $this->redirect(['action' => 'view', $id]);
        }

        $signaturesTable = $this->NoveltyLiquidationDocs->NoveltyLiquidationSignatures;

        // Upsert: find existing or create new
        $existing = $signaturesTable->find()
            ->where(['liquidation_doc_id' => $id, 'signer_type' => $signerType])
            ->first();

        $signature = $existing ?? $signaturesTable->newEmptyEntity();

        if (!empty($base64)) {
            $uploadDir = WWW_ROOT . 'uploads' . DS . 'novelty-signatures' . DS . $id;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $imgData  = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));
            $fileName = uniqid($signerType . '_') . '.png';
            file_put_contents($uploadDir . DS . $fileName, $imgData);

            $signature = $signaturesTable->patchEntity($signature, [
                'liquidation_doc_id' => $id,
                'signer_type'        => $signerType,
                'signature_path'     => 'uploads/novelty-signatures/' . $id . '/' . $fileName,
                'signed_by'          => $user->id,
                'approved_at'        => date('Y-m-d H:i:s'),
            ]);
            $signaturesTable->save($signature);
        }

        $this->Flash->success('Firma registrada.');
        return $this->redirect(['action' => 'view', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user    = $this->Authentication->getIdentity()->getOriginalData();
        $message = $this->request->getData('message');

        if (!empty($message)) {
            $this->observationService->addToGroup((int)$id, $user->id, $message);
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function uploadDocument($id = null)
    {
        $this->request->allowMethod(['post']);
        $doc    = $this->NoveltyLiquidationDocs->get($id, contain: ['EmployeeNovelties']);
        $user   = $this->Authentication->getIdentity()->getOriginalData();
        $status = $doc->employee_novelties[0]->pipeline_status ?? NoveltyConstants::STATUS_CONTABILIDAD;
        $file   = $this->request->getUploadedFile('document_file');

        if ($file) {
            $result = $this->documentService->uploadForGroup((int)$id, $status, $file, $user->id);
            if (is_string($result)) {
                $this->Flash->error($result);
            } else {
                $this->Flash->success('Documento subido.');
            }
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    public function deleteDocument($documentId = null)
    {
        $this->request->allowMethod(['post']);
        $docRecord = TableRegistry::getTableLocator()->get('NoveltyDocuments')->get($documentId);
        $liquidationId = $docRecord->liquidation_doc_id;

        if ($this->documentService->deleteDocument((int)$documentId)) {
            $this->Flash->success('Documento eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el documento.');
        }

        return $this->redirect(['action' => 'view', $liquidationId]);
    }
}
```

**Step 2: Agregar `use Cake\ORM\TableRegistry;` al inicio del archivo si se usa.**

**Step 3: Verificar estilo**

```bash
composer cs-check src/Controller/NoveltyLiquidationDocsController.php
```

**Step 4: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(novelties): create NoveltyLiquidationDocsController"
```

---

## Phase 5: Views

---

### Task 15: Actualizar NoveltyTypes/edit — sección de configuración de pipeline

**Files:**
- Modify: `templates/NoveltyTypes/edit.php`

**Step 1: Agregar sección de configuración al final del formulario, antes del botón submit**

```php
<hr class="my-4">
<div class="mb-3">
    <span class="sgi-section-label">Configuración del Pipeline</span>
</div>
<div class="row g-3">
    <div class="col-md-6">
        <div class="form-label mb-2">Etapas requeridas</div>
        <?= $this->Form->control('requires_rrhh', ['type' => 'checkbox', 'label' => 'Requiere etapa RRHH', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('requires_firmas', ['type' => 'checkbox', 'label' => 'Requiere Firmas y Aprobación', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('requires_gdp', ['type' => 'checkbox', 'label' => 'Requiere etapa GDP', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('requires_tesoreria', ['type' => 'checkbox', 'label' => 'Requiere etapa Tesorería', 'class' => 'form-check-input']) ?>
    </div>
    <div class="col-md-6">
        <div class="form-label mb-2">Campos del formulario</div>
        <?= $this->Form->control('show_start_date', ['type' => 'checkbox', 'label' => 'Mostrar fecha de inicio', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('show_end_date', ['type' => 'checkbox', 'label' => 'Mostrar fecha de fin', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('show_permission_date', ['type' => 'checkbox', 'label' => 'Mostrar fecha de permiso', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('show_schedule_type', ['type' => 'checkbox', 'label' => 'Mostrar tipo de horario', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('uses_custom_name', ['type' => 'checkbox', 'label' => 'Usar campo de nombre libre (en vez de selección de empleado)', 'class' => 'form-check-input']) ?>
        <?= $this->Form->control('is_massive', ['type' => 'checkbox', 'label' => 'Permite múltiples empleados (masivo)', 'class' => 'form-check-input']) ?>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add templates/NoveltyTypes/edit.php
git commit -m "feat(novelties): add pipeline config section to NoveltyType edit form"
```

---

### Task 16: Actualizar EmployeeNovelties/add

**Files:**
- Modify: `templates/EmployeeNovelties/add.php`

**Step 1: Reescribir el campo de empleado para soportar masivo y custom_name**

El campo de empleado debe ser condicional. Agregar un data attribute al select de tipo para que JS reactive los campos:

```php
<!-- Campo de empleado — se muestra/oculta según el tipo seleccionado -->
<div id="employee-field" class="col-md-6">
    <label class="form-label">Empleado</label>
    <?= $this->Form->control('employee_id', [
        'label' => false,
        'class' => 'form-control select2',
        'empty' => '-- Seleccionar empleado --',
        'options' => $employees,
    ]) ?>
</div>

<div id="custom-name-field" class="col-md-6" style="display:none">
    <label class="form-label">Nombre / Descripción</label>
    <?= $this->Form->control('custom_name', ['label' => false, 'class' => 'form-control', 'placeholder' => 'Ej: Bonificación especial equipo ventas']) ?>
</div>

<div id="massive-employees-field" class="col-md-12" style="display:none">
    <label class="form-label">Empleados (selección múltiple)</label>
    <?= $this->Form->control('employee_ids[]', [
        'label'    => false,
        'type'     => 'select',
        'multiple' => true,
        'class'    => 'form-control select2',
        'options'  => $employees,
    ]) ?>
</div>
```

**Step 2: Agregar el JS de tipo que reactive los campos**

Al final del add.php:

```html
<script>
document.addEventListener('DOMContentLoaded', function () {
    const noveltyTypeData = <?= json_encode($noveltyTypeConfig) ?>;

    function updateFieldVisibility(typeId) {
        const config = noveltyTypeData[typeId] || {};
        document.getElementById('employee-field').style.display =
            (!config.uses_custom_name && !config.is_massive) ? '' : 'none';
        document.getElementById('custom-name-field').style.display =
            config.uses_custom_name ? '' : 'none';
        document.getElementById('massive-employees-field').style.display =
            config.is_massive ? '' : 'none';

        // Conditional date/schedule fields
        const fieldMap = {
            'start-date-field':      config.show_start_date,
            'end-date-field':        config.show_end_date,
            'permission-date-field': config.show_permission_date,
            'schedule-type-field':   config.show_schedule_type,
        };
        for (const [id, show] of Object.entries(fieldMap)) {
            const el = document.getElementById(id);
            if (el) el.style.display = show ? '' : 'none';
        }
    }

    const typeSelect = document.querySelector('[name="novelty_type_id"]');
    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            updateFieldVisibility(this.value);
        });
        updateFieldVisibility(typeSelect.value);
    }
});
</script>
```

**Step 3: En el EmployeeNoveltiesController::add() agregar `$noveltyTypeConfig`**

```php
// Cargar configuración de tipos para JS
$allTypes = $this->EmployeeNovelties->NoveltyTypes->find()->all();
$noveltyTypeConfig = [];
foreach ($allTypes as $t) {
    $noveltyTypeConfig[$t->id] = [
        'uses_custom_name'     => (bool)$t->uses_custom_name,
        'is_massive'           => (bool)$t->is_massive,
        'show_start_date'      => (bool)$t->show_start_date,
        'show_end_date'        => (bool)$t->show_end_date,
        'show_permission_date' => (bool)$t->show_permission_date,
        'show_schedule_type'   => (bool)$t->show_schedule_type,
    ];
}
$this->set(compact('noveltyTypeConfig'));
```

**Step 4: Commit**

```bash
git add templates/EmployeeNovelties/add.php src/Controller/EmployeeNoveltiesController.php
git commit -m "feat(novelties): update add form with conditional fields for custom_name and massive types"
```

---

### Task 17: Actualizar EmployeeNovelties/index y view

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`
- Modify: `templates/EmployeeNovelties/view.php`

**Step 1: index.php — actualizar badge de estado**

Cambiar la columna `status` por `pipeline_status` con badge de color. Agregar filtro por `pipeline_status`:

```php
<?php
$statusColors = [
    'registro'           => 'secondary',
    'rrhh'               => 'info',
    'contabilidad'       => 'primary',
    'firmas_aprobacion'  => 'warning',
    'gdp'                => 'dark',
    'tesoreria'          => 'success',
    'pagada'             => 'success',
    'rechazada'          => 'danger',
];
// En la columna de estado:
$color = $statusColors[$novelty->pipeline_status] ?? 'secondary';
$label = \App\Constants\NoveltyConstants::STATUS_LABELS[$novelty->pipeline_status] ?? $novelty->pipeline_status;
echo "<span class=\"badge bg-{$color}\">{$label}</span>";
```

**Step 2: view.php — agregar barra de progreso, botón avanzar, sección de soportes y observaciones**

La vista debe incluir:
- Barra de progreso usando `$pipeline` (array de etapas del tipo) y `$currentStatus`
- Sección de campos editables según etapa actual
- Si está agrupada: mensaje con link al doc de liquidación en vez de botón avanzar
- Sección de soportes (collapsible por etapa, upload form)
- Chat de observaciones (igual al de facturas)
- Botón Avanzar / Rechazar (solo si no está agrupada y el rol lo permite)

En el controlador `view()`, pasar:
```php
$pipeline = $this->pipelineService->getPipelineForType($novelty->novelty_type);
$documentsByStatus = $this->documentService->getDocumentsByStatus($id);
$observations = $this->EmployeeNovelties->NoveltyObservations->find()
    ->contain(['Users'])
    ->where(['novelty_id' => $id])
    ->order(['created' => 'ASC'])
    ->all();
$this->set(compact('novelty', 'pipeline', 'documentsByStatus', 'observations'));
```

**Step 3: Commit**

```bash
git add templates/EmployeeNovelties/index.php templates/EmployeeNovelties/view.php
git commit -m "feat(novelties): update novelty index and view for pipeline UI"
```

---

### Task 18: Crear vistas de NoveltyLiquidationDocs

**Files:**
- Create: `templates/NoveltyLiquidationDocs/index.php`
- Create: `templates/NoveltyLiquidationDocs/view.php`

**Step 1: index.php**

Tabla con columnas: Número de liquidación, Período, Estado actual (badge), Nº de novedades, Fecha, acciones.

Filtro por estado (igual a otros índices del sistema).

**Step 2: view.php**

Secciones:
1. Encabezado: número, período, fecha doc, realizado por
2. Barra de progreso de pipeline (etapas contabilidad → pagada)
3. Lista de novedades miembro (tabla compacta con link a cada una)
4. Sección campos del grupo por etapa (colapsable):
   - Contabilidad: período, fecha, realizado por (form inline)
   - Firmas y Aprobación: canvas por firmante + badge "Firmado" / "Pendiente"
   - GDP: pasa para pago (radio Si/No)
   - Tesorería: estado de pago, fecha de pago
5. Botón "Avanzar grupo" → POST a `advanceGroup`
6. Soportes por estado
7. Chat de observaciones del grupo

**Step 3: Commit**

```bash
git add templates/NoveltyLiquidationDocs/
git commit -m "feat(novelties): create NoveltyLiquidationDocs index and view templates"
```

---

## Phase 6: Wiring

---

### Task 19: Rutas, AuthorizationService, sidebar

**Files:**
- Modify: `config/routes.php`
- Modify: `src/Service/AuthorizationService.php`

**Step 1: Agregar rutas en routes.php (antes de `$builder->fallbacks()`)**

```php
$routes->connect('/novelty-liquidation-docs/advance-group/{id}', [
    'controller' => 'NoveltyLiquidationDocs',
    'action'     => 'advanceGroup',
], ['id' => '\d+', 'pass' => ['id']]);

$routes->connect('/novelty-liquidation-docs/add-signature/{id}', [
    'controller' => 'NoveltyLiquidationDocs',
    'action'     => 'addSignature',
], ['id' => '\d+', 'pass' => ['id']]);

$routes->connect('/novelty-liquidation-docs/add-observation/{id}', [
    'controller' => 'NoveltyLiquidationDocs',
    'action'     => 'addObservation',
], ['id' => '\d+', 'pass' => ['id']]);

$routes->connect('/employee-novelties/advance/{id}', [
    'controller' => 'EmployeeNovelties',
    'action'     => 'advance',
], ['id' => '\d+', 'pass' => ['id']]);

$routes->connect('/employee-novelties/add-observation/{id}', [
    'controller' => 'EmployeeNovelties',
    'action'     => 'addObservation',
], ['id' => '\d+', 'pass' => ['id']]);
```

**Step 2: Agregar módulo en AuthorizationService::MODULES**

```php
'novelty_liquidation_docs' => 'Documentos de Liquidación',
```

**Step 3: Agregar acciones en AppController::_actionToPermission()**

```php
'advanceGroup', 'addSignature', 'addObservation', 'advance', 'uploadDocument' => 'edit',
```

**Step 4: Commit**

```bash
git add config/routes.php src/Service/AuthorizationService.php src/Controller/AppController.php
git commit -m "feat(novelties): wire up routes, permissions and module map for pipeline"
```

---

### Task 20: Smoke test y verificación final

**Step 1: Correr todos los tests**

```bash
composer test
```
Expected: All tests pass (no regressions).

**Step 2: Verificar estilo completo**

```bash
composer cs-check src/
```
Expected: No errors.

**Step 3: Verificar migraciones aplicadas**

```bash
bin/cake migrations status
```
Expected: All migrations `up`.

**Step 4: Smoke test manual**

- Crear una novedad de tipo simple → verificar que inicia en `registro`
- Avanzar a RRHH → completar campos RRHH → avanzar a Contabilidad
- Asignar número de liquidación → verificar que queda agrupada
- Desde NoveltyLiquidationDocs → avanzar el grupo a Firmas → GDP → Tesorería → Pagada
- Intentar avanzar individualmente una novedad agrupada → debe mostrar error
- Crear novedad de tipo masivo → verificar multi-select de empleados
- Crear novedad con `uses_custom_name` → verificar campo de texto libre
- Verificar que tipos con etapas desactivadas las saltan correctamente

**Step 5: Commit final**

```bash
git commit --allow-empty -m "feat(novelties): complete pipeline implementation - smoke tested"
```
