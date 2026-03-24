# Refactorización del Módulo de Novedades — Plan de Implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactorizar el pipeline de novedades para unificar etapas, agregar aprobación externa del jefe inmediato, y separar firmas del empleado con flags por tipo de novedad.

**Architecture:** Se modifica el pipeline de 6 a 7 etapas (Aprobación condicional → RRHH → Contabilidad → Revisión y Firmas → GDP → Tesorería → Pagada). Se eliminan los 5 flags de etapas configurables por tipo, reemplazándolos por 3 flags (aprobación jefe, firma empleado en creación, firma empleado en revisión). Se reutiliza `ApprovalTokenService` y `ExternalApprovalsController` existentes para la aprobación externa de novedades.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, Phinx migrations (BaseMigration)

**Design doc:** `docs/plans/2026-03-24-novelties-refactor-design.md`

---

### Task 1: Migración — Modificar tabla `novelty_types`

**Files:**
- Create: `config/Migrations/20260324000001_RefactorNoveltyTypesFlags.php`

**Step 1: Crear la migración**

```bash
php bin/cake migrations create RefactorNoveltyTypesFlags
```

**Step 2: Implementar la migración**

Editar el archivo generado en `config/Migrations/`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RefactorNoveltyTypesFlags extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('novelty_types');

        // Add new columns
        $table->addColumn('requires_boss_approval', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'parent_id',
        ]);
        $table->addColumn('requires_employee_signature_creation', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'requires_boss_approval',
        ]);
        $table->addColumn('requires_employee_signature_review', 'boolean', [
            'default' => false,
            'null' => false,
            'after' => 'requires_employee_signature_creation',
        ]);

        // Remove old columns
        $table->removeColumn('requires_rrhh');
        $table->removeColumn('requires_contabilidad');
        $table->removeColumn('requires_firmas');
        $table->removeColumn('requires_gdp');
        $table->removeColumn('requires_tesoreria');

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('novelty_types');

        $table->addColumn('requires_rrhh', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_contabilidad', 'boolean', ['default' => false, 'null' => false]);
        $table->addColumn('requires_firmas', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_gdp', 'boolean', ['default' => true, 'null' => false]);
        $table->addColumn('requires_tesoreria', 'boolean', ['default' => true, 'null' => false]);

        $table->removeColumn('requires_boss_approval');
        $table->removeColumn('requires_employee_signature_creation');
        $table->removeColumn('requires_employee_signature_review');

        $table->update();
    }
}
```

**Step 3: Ejecutar la migración**

```bash
php bin/cake migrations migrate
```

Expected: Migration successful, table updated.

**Step 4: Commit**

```bash
git add config/Migrations/*RefactorNoveltyTypesFlags*
git commit -m "migration: replace stage flags with approval/signature flags in novelty_types"
```

---

### Task 2: Migración — Modificar tabla `employee_novelties`

**Files:**
- Create: `config/Migrations/20260324000002_RefactorEmployeeNovelties.php`

**Step 1: Crear la migración**

```bash
php bin/cake migrations create RefactorEmployeeNovelties
```

**Step 2: Implementar la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class RefactorEmployeeNovelties extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('employee_novelties');

        // Add approver_id column
        $table->addColumn('approver_id', 'integer', [
            'null' => true,
            'default' => null,
            'signed' => true,
            'after' => 'approved_at',
        ]);
        $table->addForeignKey('approver_id', 'users', 'id', [
            'delete' => 'SET_NULL',
            'update' => 'NO_ACTION',
        ]);

        // Add area_approval column for rejection tracking
        $table->addColumn('area_approval', 'string', [
            'limit' => 20,
            'null' => true,
            'default' => null,
            'after' => 'approver_id',
        ]);

        // Remove coordinator_signature
        $table->removeColumn('coordinator_signature');

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('employee_novelties');
        $table->dropForeignKey('approver_id');
        $table->removeColumn('approver_id');
        $table->removeColumn('area_approval');
        $table->addColumn('coordinator_signature', 'string', ['limit' => 255, 'null' => true]);
        $table->update();
    }
}
```

**Step 3: Ejecutar la migración**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*RefactorEmployeeNovelties*
git commit -m "migration: add approver_id and area_approval, remove coordinator_signature from employee_novelties"
```

---

### Task 3: Migración — Limpiar `novelty_liquidation_signatures`

**Files:**
- Create: `config/Migrations/20260324000003_CleanupLiquidationSignatures.php`

**Step 1: Crear la migración**

```bash
php bin/cake migrations create CleanupLiquidationSignatures
```

**Step 2: Implementar la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CleanupLiquidationSignatures extends BaseMigration
{
    public function up(): void
    {
        // Remove all jefe_inmediato signature records
        $this->execute("DELETE FROM novelty_liquidation_signatures WHERE signer_type = 'jefe_inmediato'");
    }

    public function down(): void
    {
        // Cannot restore deleted records — no-op
    }
}
```

**Step 3: Ejecutar la migración**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*CleanupLiquidationSignatures*
git commit -m "migration: remove jefe_inmediato signature records from liquidation signatures"
```

---

### Task 4: Actualizar `NoveltyConstants`

**Files:**
- Modify: `src/Constants/NoveltyConstants.php`

**Step 1: Reemplazar el contenido completo de NoveltyConstants**

Cambios clave:
- Agregar `STATUS_APROBACION = 'aprobacion'`
- Renombrar `STATUS_FIRMAS_APROBACION` → `STATUS_REVISION_FIRMAS` (valor `'revision_firmas'`)
- Actualizar `PIPELINE_STATUSES` con las 7 etapas en nuevo orden
- Actualizar `TRANSITIONS` con `aprobacion → rrhh` al inicio
- Actualizar `STATUS_LABELS`, `STATUS_ICONS`
- Eliminar `SIGNER_JEFE_INMEDIATO` de `SIGNER_TYPES`
- Mantener backward compat constants actualizados

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class NoveltyConstants
{
    // Pipeline statuses (ordered)
    public const STATUS_REGISTRO = 'registro';
    public const STATUS_APROBACION = 'aprobacion';
    public const STATUS_RRHH = 'rrhh';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_REVISION_FIRMAS = 'revision_firmas';
    public const STATUS_GDP = 'gdp';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_PAGADA = 'pagada';
    public const STATUS_RECHAZADA = 'rechazada';

    // Backward compat for renamed status
    public const STATUS_FIRMAS_APROBACION = self::STATUS_REVISION_FIRMAS;

    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
    ];

    public const ALL_STATUSES = [
        self::STATUS_REGISTRO,
        self::STATUS_APROBACION,
        self::STATUS_RRHH,
        self::STATUS_CONTABILIDAD,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_GDP,
        self::STATUS_TESORERIA,
        self::STATUS_PAGADA,
        self::STATUS_RECHAZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_REGISTRO => 'Registro',
        self::STATUS_APROBACION => 'Aprobación',
        self::STATUS_RRHH => 'RRHH',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_REVISION_FIRMAS => 'Revisión y Firmas de documentos',
        self::STATUS_GDP => 'GDP',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_PAGADA => 'Pagada',
        self::STATUS_RECHAZADA => 'Rechazada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_REGISTRO => 'bi-pencil-square',
        self::STATUS_APROBACION => 'bi-person-check',
        self::STATUS_RRHH => 'bi-people',
        self::STATUS_CONTABILIDAD => 'bi-calculator',
        self::STATUS_REVISION_FIRMAS => 'bi-pen',
        self::STATUS_GDP => 'bi-clipboard-check',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_PAGADA => 'bi-cash-coin',
    ];

    // Linear transitions
    public const TRANSITIONS = [
        self::STATUS_APROBACION => self::STATUS_RRHH,
        self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_REVISION_FIRMAS,
        self::STATUS_REVISION_FIRMAS => self::STATUS_GDP,
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

    // Signer types (for liquidation doc signatures) — jefe_inmediato removed
    public const SIGNER_CONTADOR = 'contador';
    public const SIGNER_COORDINADOR_ADMIN = 'coordinador_admin';
    public const SIGNER_TRABAJADOR = 'trabajador';
    public const SIGNER_TYPES = [
        self::SIGNER_CONTADOR,
        self::SIGNER_COORDINADOR_ADMIN,
        self::SIGNER_TRABAJADOR,
    ];
    public const SIGNER_LABELS = [
        self::SIGNER_CONTADOR => 'Contador',
        self::SIGNER_COORDINADOR_ADMIN => 'Coordinador Administrativo',
        self::SIGNER_TRABAJADOR => 'Trabajador',
    ];

    // Approval values (for area_approval field)
    public const APPROVAL_PENDING = 'Pendiente';
    public const APPROVAL_APPROVED = 'Aprobada';
    public const APPROVAL_REJECTED = 'Rechazada';

    // Backward compat
    public const STATUS_PENDING = self::STATUS_REGISTRO;
    public const STATUS_APPROVED = self::STATUS_PAGADA;
    public const STATUS_REJECTED = self::STATUS_RECHAZADA;
    public const STATUSES = self::ALL_STATUSES;
}
```

**Step 2: Commit**

```bash
git add src/Constants/NoveltyConstants.php
git commit -m "refactor: update NoveltyConstants with new pipeline, approval status, and reduced signer types"
```

---

### Task 5: Actualizar modelos — Entity y Table de `NoveltyType`

**Files:**
- Modify: `src/Model/Entity/NoveltyType.php`
- Modify: `src/Model/Table/NoveltyTypesTable.php`

**Step 1: Actualizar NoveltyType entity**

Reemplazar los 5 flags de etapas por los 3 nuevos en `$_accessible`:

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
        'requires_boss_approval' => true,
        'requires_employee_signature_creation' => true,
        'requires_employee_signature_review' => true,
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

**Step 2: Actualizar NoveltyTypesTable validation**

En `src/Model/Table/NoveltyTypesTable.php`, reemplazar las validaciones de los 5 flags por los 3 nuevos (líneas 61-65):

Replace:
```php
        $validator->boolean('requires_rrhh');
        $validator->boolean('requires_contabilidad');
        $validator->boolean('requires_firmas');
        $validator->boolean('requires_gdp');
        $validator->boolean('requires_tesoreria');
```

With:
```php
        $validator->boolean('requires_boss_approval');
        $validator->boolean('requires_employee_signature_creation');
        $validator->boolean('requires_employee_signature_review');
```

**Step 3: Commit**

```bash
git add src/Model/Entity/NoveltyType.php src/Model/Table/NoveltyTypesTable.php
git commit -m "refactor: update NoveltyType entity and table with new flag fields"
```

---

### Task 6: Actualizar modelo `EmployeeNovelty`

**Files:**
- Modify: `src/Model/Entity/EmployeeNovelty.php`
- Modify: `src/Model/Table/EmployeeNoveltiesTable.php`

**Step 1: Actualizar EmployeeNovelty entity**

In `src/Model/Entity/EmployeeNovelty.php`:

Remove `'coordinator_signature' => true,` from `$_accessible`.

Add these to `$_accessible`:
```php
        'approver_id' => true,
        'area_approval' => true,
```

Add helper method:
```php
    public function isApprovalRejected(): bool
    {
        return $this->area_approval === \App\Constants\NoveltyConstants::APPROVAL_REJECTED;
    }
```

**Step 2: Actualizar EmployeeNoveltiesTable**

Add the `Approvers` association in `initialize()` of `src/Model/Table/EmployeeNoveltiesTable.php`.

Find where other belongsTo associations are defined (like `ApprovedByUsers`) and add:

```php
        $this->belongsTo('Approvers', [
            'className' => 'Users',
            'foreignKey' => 'approver_id',
            'joinType' => 'LEFT',
        ]);
```

Also add the build rule for `approver_id`:

```php
        $rules->add($rules->existsIn('approver_id', 'Approvers'), [
            'errorField' => 'approver_id',
            'allowNullableNulls' => true,
        ]);
```

**Step 3: Commit**

```bash
git add src/Model/Entity/EmployeeNovelty.php src/Model/Table/EmployeeNoveltiesTable.php
git commit -m "refactor: update EmployeeNovelty entity/table with approver_id and area_approval"
```

---

### Task 7: Actualizar `NoveltyLiquidationSignaturesTable`

**Files:**
- Modify: `src/Model/Table/NoveltyLiquidationSignaturesTable.php`

**Step 1: Update validation**

No code changes needed — the `inList('signer_type', NoveltyConstants::SIGNER_TYPES)` validation at line 41 already references the constant, which was updated in Task 4 to only include 3 types. Verify this is correct by reading the file.

**Step 2: Commit (if changes needed)**

This task may be a no-op since the validation references the constant directly.

---

### Task 8: Reescribir `NoveltyPipelineService`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php`

**Step 1: Reemplazar el servicio completo**

Key changes:
- Remove `shouldSkipStage()` — no more stage skipping (except aprobacion)
- Simplify `getNextStatus()` — only skip `aprobacion` if type doesn't require it
- Simplify `getEffectiveStatuses()` — return 7 or 6 statuses based on `requires_boss_approval`
- Update `validateTransition()` — add `aprobacion` case requiring `approver_id` and not rejected
- Update `validateGroupTransition()` — use `STATUS_REVISION_FIRMAS` instead of `STATUS_FIRMAS_APROBACION`, validate based on actual signature slots count (not hardcoded 4)
- Update `assignToLiquidationDoc()` — create 2 or 3 signature slots based on type's `requires_employee_signature_review`
- Simplify `getVisibleFields()` — remove stage flag dependencies

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use Cake\ORM\TableRegistry;
use DateTime;

class NoveltyPipelineService
{
    /**
     * Get the next status for a novelty.
     * Only skips aprobacion if the type doesn't require boss approval.
     */
    public function getNextStatus(object $novelty, ?object $noveltyType = null): ?string
    {
        $currentStatus = $novelty->pipeline_status;

        if (
            $currentStatus === NoveltyConstants::STATUS_RECHAZADA
            || $currentStatus === NoveltyConstants::STATUS_PAGADA
        ) {
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

        // Skip aprobacion if type doesn't require boss approval
        if ($nextStatus === NoveltyConstants::STATUS_APROBACION && $noveltyType && !$noveltyType->requires_boss_approval) {
            $nextStatus = NoveltyConstants::TRANSITIONS[$nextStatus] ?? null;
        }

        return $nextStatus;
    }

    /**
     * Get the effective pipeline statuses for a novelty type.
     */
    public function getEffectiveStatuses(?object $noveltyType = null): array
    {
        if (!$noveltyType || $noveltyType->requires_boss_approval) {
            return NoveltyConstants::PIPELINE_STATUSES;
        }

        // Exclude aprobacion
        return array_values(array_filter(
            NoveltyConstants::PIPELINE_STATUSES,
            fn(string $status) => $status !== NoveltyConstants::STATUS_APROBACION,
        ));
    }

    /**
     * Advance a single novelty individually.
     * Blocked if novelty has a liquidation_doc_id.
     */
    public function advance(EmployeeNovelty $novelty, int $userId): array
    {
        if ($novelty->isGrouped()) {
            return [
                'success' => false,
                'error' => 'Esta novedad pertenece a un documento de liquidación. Debe avanzar desde el documento grupal.',
            ];
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
        $novelty->approved_at = new DateTime();

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
            case NoveltyConstants::STATUS_APROBACION:
                if (empty($novelty->approver_id)) {
                    $errors[] = 'Debe asignar un aprobador.';
                }
                if (!empty($novelty->area_approval) && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED) {
                    $errors[] = 'La novedad fue rechazada por el aprobador. Edite y reenvíe para aprobación.';
                }
                break;

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
            case NoveltyConstants::STATUS_REVISION_FIRMAS:
                $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');

                $totalSlots = $signaturesTable->find()
                    ->where(['liquidation_doc_id' => $liquidationDoc->id])
                    ->count();

                $signedCount = $signaturesTable->find()
                    ->where([
                        'liquidation_doc_id' => $liquidationDoc->id,
                        'signature_path IS NOT' => null,
                    ])
                    ->count();

                if ($signedCount < $totalSlots) {
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
                if (
                    $liquidationDoc->payment_status === NoveltyConstants::PAYMENT_PAGADO
                    && empty($liquidationDoc->payment_date)
                ) {
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
     * Creates 2 or 3 signature slots based on type's requires_employee_signature_review.
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

            // Determine which signature slots to create
            $signerTypes = [
                NoveltyConstants::SIGNER_CONTADOR,
                NoveltyConstants::SIGNER_COORDINADOR_ADMIN,
            ];

            // Check if any novelty type requires employee signature in review
            $noveltyType = null;
            if (!empty($novelty->novelty_type)) {
                $noveltyType = $novelty->novelty_type;
            } elseif (!empty($novelty->novelty_type_id)) {
                $noveltyType = TableRegistry::getTableLocator()->get('NoveltyTypes')
                    ->get($novelty->novelty_type_id);
            }

            if ($noveltyType && $noveltyType->requires_employee_signature_review) {
                $signerTypes[] = NoveltyConstants::SIGNER_TRABAJADOR;
            }

            $signaturesTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationSignatures');
            foreach ($signerTypes as $signerType) {
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

        if ($pipelineStatus === NoveltyConstants::STATUS_APROBACION) {
            $fields[] = 'approver_id';
        }

        return $fields;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/NoveltyPipelineService.php
git commit -m "refactor: rewrite NoveltyPipelineService for unified pipeline with conditional approval stage"
```

---

### Task 9: Actualizar `ApprovalTokenService` para novedades

**Files:**
- Modify: `src/Service/ApprovalTokenService.php`

**Step 1: Reescribir `applyNoveltyAction`**

Replace the `applyNoveltyAction` method (lines 173-187) with the correct implementation:

```php
    private function applyNoveltyAction(int $noveltyId, string $action): bool
    {
        $table = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $novelty = $table->get($noveltyId);

        if ($action === 'approve') {
            $novelty->pipeline_status = NoveltyConstants::STATUS_RRHH;
            $novelty->area_approval = NoveltyConstants::APPROVAL_APPROVED;
            $novelty->approved_by = $novelty->approver_id;
            $novelty->approved_at = new DateTime();
        } elseif ($action === 'reject') {
            $novelty->area_approval = NoveltyConstants::APPROVAL_REJECTED;
            // Stay in aprobacion status — RRHH can edit and resend
        }

        return (bool)$table->save($novelty);
    }
```

**Step 2: Update `getEntity` to contain Approvers**

In the `getEntity` method (line 207), update the contain for employee_novelties:

```php
            } elseif ($entityType === 'employee_novelties') {
                $contain = ['Employees', 'NoveltyTypes', 'Approvers'];
            }
```

**Step 3: Commit**

```bash
git add src/Service/ApprovalTokenService.php
git commit -m "fix: update ApprovalTokenService novelty action to use pipeline_status and area_approval"
```

---

### Task 10: Actualizar `ExternalApprovalsController` para novedades

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php`

**Step 1: Add novelty approver validation in `review()`**

After the invoice approver check (line 56-59), add the novelty check:

```php
        if ($tokenRecord->entity_type === 'employee_novelties' && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad. Solo el aprobador asignado puede hacerlo.');
            $this->set('unauthorized', true);

            return $this->render('expired');
        }
```

**Step 2: Add novelty approver validation in `process()`**

After the invoice check (line 83-88), add:

```php
        if ($tokenRecord->entity_type === 'employee_novelties' && $entity && $entity->approver_id !== $currentUser->id) {
            $this->Flash->error('No tiene autorización para aprobar esta novedad.');
            $this->set('expired', true);

            return $this->render('expired');
        }
```

**Step 3: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php
git commit -m "feat: add novelty approver validation in ExternalApprovalsController"
```

---

### Task 11: Actualizar vista `ExternalApprovals/review.php` para novedades

**Files:**
- Modify: `templates/ExternalApprovals/review.php`

**Step 1: Add novelty display section**

After the `elseif ($entityType === 'employee_leaves'):` block (before `<?php endif; ?>`), add a new section for employee_novelties:

```php
        <?php elseif ($entityType === 'employee_novelties'): ?>
            <div class="sgi-section-title">Novedad</div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Empleado</span>
                <span class="sgi-data-value"><?= h($entity->employee->full_name ?? $entity->custom_name ?? '—') ?></span>
            </div>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo de Novedad</span>
                <span class="sgi-data-value"><?= h($entity->novelty_type->name ?? '—') ?></span>
            </div>
            <?php if (!empty($entity->reason)): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Motivo</span>
                <span class="sgi-data-value"><?= h($entity->reason) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($entity->start_date || $entity->end_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fechas</span>
                <span class="sgi-data-value">
                    <?= $entity->start_date ? (is_string($entity->start_date) ? $entity->start_date : $entity->start_date->format('d/m/Y')) : '' ?>
                    <?php if ($entity->end_date): ?>
                     — <?= is_string($entity->end_date) ? $entity->end_date : $entity->end_date->format('d/m/Y') ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Actual</span>
                <span class="sgi-data-value"><?= h(\App\Constants\NoveltyConstants::STATUS_LABELS[$entity->pipeline_status] ?? $entity->pipeline_status) ?></span>
            </div>
```

**Step 2: Commit**

```bash
git add templates/ExternalApprovals/review.php
git commit -m "feat: add novelty details display in external approval review page"
```

---

### Task 12: Actualizar `NoveltyTypesController`

**Files:**
- Modify: `src/Controller/NoveltyTypesController.php`

**Step 1: Update `getFlags()` method**

Replace the `getFlags` method (lines 82-104) to return the 3 new flags:

```php
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
```

**Step 2: Commit**

```bash
git add src/Controller/NoveltyTypesController.php
git commit -m "refactor: update NoveltyTypesController getFlags with new approval/signature flags"
```

---

### Task 13: Actualizar templates de `NoveltyTypes` (add/edit)

**Files:**
- Modify: `templates/NoveltyTypes/add.php`
- Modify: `templates/NoveltyTypes/edit.php`

**Step 1: Replace the pipeline checkboxes in add.php**

Replace lines 46-69 (the "Etapas requeridas" column with 5 checkboxes) with:

```php
                <div class="col-md-6">
                    <p class="text-muted small mb-2">Configuración de aprobación y firmas</p>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_boss_approval', ['class' => 'form-check-input', 'id' => 'requires-boss-approval']) ?>
                        <label class="form-check-label" for="requires-boss-approval">Requiere aprobación del jefe inmediato</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_employee_signature_creation', ['class' => 'form-check-input', 'id' => 'requires-sig-creation']) ?>
                        <label class="form-check-label" for="requires-sig-creation">Requiere firma del empleado al crear</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_employee_signature_review', ['class' => 'form-check-input', 'id' => 'requires-sig-review']) ?>
                        <label class="form-check-label" for="requires-sig-review">Requiere firma del empleado en revisión de documentos</label>
                    </div>
                </div>
```

**Step 2: Apply same changes to edit.php**

The edit.php template has the same structure. Apply the identical replacement to the pipeline checkboxes section.

**Step 3: Commit**

```bash
git add templates/NoveltyTypes/add.php templates/NoveltyTypes/edit.php
git commit -m "refactor: replace stage checkboxes with approval/signature checkboxes in NoveltyTypes templates"
```

---

### Task 14: Actualizar `EmployeeNoveltiesController` — `add()` con aprobación

**Files:**
- Modify: `src/Controller/EmployeeNoveltiesController.php`

**Step 1: Import ApprovalTokenService**

Add to imports at top of file:
```php
use App\Service\ApprovalTokenService;
```

Add to class properties:
```php
    private ApprovalTokenService $tokenService;
```

In `initialize()`, add:
```php
        $this->tokenService = new ApprovalTokenService();
```

**Step 2: Update `add()` method**

Replace the initial status determination logic (lines 250-257) with:

```php
            // Determine initial status based on type's requires_boss_approval
            $initialStatus = NoveltyConstants::STATUS_RRHH;
            $noveltyType = null;
            if (!empty($data['novelty_type_id'])) {
                $noveltyType = $this->EmployeeNovelties->NoveltyTypes->get($data['novelty_type_id']);
                if ($noveltyType->requires_boss_approval) {
                    $initialStatus = NoveltyConstants::STATUS_APROBACION;
                }
            }
            $data['pipeline_status'] = $initialStatus;
```

After the novelty is saved successfully (after the signature handling, before Flash success, around line 316), add approval token generation:

```php
                // Generate approval token if type requires boss approval
                if ($noveltyType && $noveltyType->requires_boss_approval && !empty($novelty->approver_id)) {
                    $token = $this->tokenService->generateToken('employee_novelties', $novelty->id, $user->id);
                    $baseUrl = $this->request->scheme() . '://' . $this->request->host();
                    $approvalUrl = $baseUrl . '/approve/' . $token;

                    // Send notification email to approver
                    $approversTable = TableRegistry::getTableLocator()->get('Users');
                    $approver = $approversTable->get($novelty->approver_id);
                    if ($approver && !empty($approver->email)) {
                        $notificationService = new \App\Service\NotificationService();
                        $notificationService->sendNoveltyApprovalEmail($approver, $novelty, $approvalUrl);
                    }
                }
```

**Step 3: Pass approvers list to the view in `add()`**

Before `$this->set(compact(...))`, add:

```php
        $approvers = TableRegistry::getTableLocator()->get('Approvers')
            ->find()
            ->contain(['Users'])
            ->where(['Approvers.active' => true])
            ->all();

        $approversList = [];
        foreach ($approvers as $approver) {
            if ($approver->user) {
                $approversList[$approver->user->id] = $approver->user->full_name;
            }
        }
```

Update the `$this->set(compact(...))` to include `approversList`:

```php
        $this->set(compact('novelty', 'employees', 'noveltyTypes', 'preselectedEmployee', 'approversList'));
```

**Step 4: Update `edit()` to handle approval resubmission**

Add a new action method `resendApproval()`:

```php
    /**
     * Resend approval token for a rejected novelty.
     *
     * @param string|null $id Novelty ID.
     * @return \Cake\Http\Response|null
     */
    public function resendApproval(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);
        $user = $this->Authentication->getIdentity()->getOriginalData();

        if ($novelty->pipeline_status !== NoveltyConstants::STATUS_APROBACION) {
            $this->Flash->error('Solo se puede reenviar aprobación para novedades en estado de aprobación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        if (empty($novelty->approver_id)) {
            $this->Flash->error('Debe asignar un aprobador antes de reenviar.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        // Clear rejection
        $novelty->area_approval = null;
        $this->EmployeeNovelties->save($novelty);

        // Generate new token
        $token = $this->tokenService->generateToken('employee_novelties', $novelty->id, $user->id);
        $baseUrl = $this->request->scheme() . '://' . $this->request->host();
        $approvalUrl = $baseUrl . '/approve/' . $token;

        // Send notification email
        $approversTable = TableRegistry::getTableLocator()->get('Users');
        $approver = $approversTable->get($novelty->approver_id);
        if ($approver && !empty($approver->email)) {
            $notificationService = new \App\Service\NotificationService();
            $notificationService->sendNoveltyApprovalEmail($approver, $novelty, $approvalUrl);
        }

        $this->Flash->success('Enlace de aprobación reenviado al aprobador (válido por 48h).');

        return $this->redirect(['action' => 'edit', $id]);
    }
```

**Step 5: In `edit()`, pass approval state to view**

After existing `$canAdvance` calculation (line 118-121), add:

```php
        $isApprovalRejected = $novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION
            && $novelty->area_approval === NoveltyConstants::APPROVAL_REJECTED;
```

Add it to the `$this->set(compact(...))` call.

Also load approvers list for the edit view:
```php
        $approversList = [];
        if ($novelty->pipeline_status === NoveltyConstants::STATUS_APROBACION) {
            $approvers = TableRegistry::getTableLocator()->get('Approvers')
                ->find()
                ->contain(['Users'])
                ->where(['Approvers.active' => true])
                ->all();
            foreach ($approvers as $approver) {
                if ($approver->user) {
                    $approversList[$approver->user->id] = $approver->user->full_name;
                }
            }
        }
```

Add `approversList` to the compact.

**Step 6: Commit**

```bash
git add src/Controller/EmployeeNoveltiesController.php
git commit -m "feat: add approval token generation and resend functionality to EmployeeNoveltiesController"
```

---

### Task 15: Agregar ruta para `resendApproval`

**Files:**
- Modify: `config/routes.php`

**Step 1: Add route**

After the existing employee novelties routes (around line 157), add:

```php
        $builder->connect(
            '/employee-novelties/resend-approval/{id}',
            ['controller' => 'EmployeeNovelties', 'action' => 'resendApproval'],
            ['id' => '\d+', 'pass' => ['id']]
        );
```

**Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat: add resend-approval route for employee novelties"
```

---

### Task 16: Actualizar `NotificationService` para emails de novedades

**Files:**
- Modify: `src/Service/NotificationService.php`

**Step 1: Add the `sendNoveltyApprovalEmail` method**

Read the existing NotificationService to understand the email sending pattern, then add:

```php
    public function sendNoveltyApprovalEmail(object $approver, object $novelty, string $approvalUrl): void
    {
        // Follow the same pattern as sendApprovalEmail for invoices
        // Send email to $approver->email with the $approvalUrl link
        // Subject: "Solicitud de Aprobación de Novedad"
        // Body: basic info about the novelty + link to approve/reject
    }
```

> **Note:** The exact implementation depends on the existing email pattern in NotificationService. Read the file and follow the same Mailer/template approach.

**Step 2: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "feat: add novelty approval email notification"
```

---

### Task 17: Actualizar `NoveltyLiquidationDocsController` — eliminar firma jefe y use_existing_path

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

**Step 1: In `edit()`, remove `existingWorkerSignatures` collection**

Remove lines 137-145 (the loop that collects existing worker signatures):

```php
        // Remove this block entirely:
        $existingWorkerSignatures = [];
        foreach ($doc->employee_novelties as $novelty) {
            if (!empty($novelty->employee_signature)) {
                $existingWorkerSignatures[] = [
                    'path' => $novelty->employee_signature,
                    'name' => $novelty->custom_name ?: ($novelty->employee->full_name ?? '—'),
                ];
            }
        }
```

Also remove `'existingWorkerSignatures'` from the `compact()` call.

**Step 2: In `addSignature()`, remove `use_existing_path` logic**

Replace the method body (lines 193-237) to remove the `use_existing_path` branch:

```php
    public function addSignature(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $signerType = $this->request->getData('signer_type');
        $user = $this->Authentication->getIdentity()->getOriginalData();

        $signaturesTable = $this->fetchTable('NoveltyLiquidationSignatures');
        $signature = $signaturesTable->find()
            ->where(['liquidation_doc_id' => $id, 'signer_type' => $signerType])
            ->first();

        if (!$signature) {
            $this->Flash->error('Firma no encontrada.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $signatureBase64 = $this->request->getData('signature_base64');

        if (!empty($signatureBase64)) {
            $path = $this->signatureService->saveFromBase64(
                (int)$id,
                $signatureBase64,
                $user->id,
                'liquidation_' . $signerType,
            );
            if ($path) {
                $signature->signature_path = $path;
                $signature->signed_by = $user->id;
                $signature->approved_at = new DateTime();
                $signaturesTable->save($signature);
                $this->Flash->success('Firma registrada.');
            }
        } else {
            $this->Flash->error('No se proporcionó una firma.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

**Step 3: Commit**

```bash
git add src/Controller/NoveltyLiquidationDocsController.php
git commit -m "refactor: remove use_existing_path and jefe_inmediato from NoveltyLiquidationDocsController"
```

---

### Task 18: Actualizar templates de `EmployeeNovelties/add.php`

**Files:**
- Modify: `templates/EmployeeNovelties/add.php`

**Step 1: Read the current template**

Read the file to understand the current structure.

**Step 2: Add approver select field**

Add a select for the approver, hidden by default, shown dynamically via JS when the selected novelty type has `requires_boss_approval = true`:

```html
<!-- Approver select (shown when type requires boss approval) -->
<div class="col-md-6" id="approver-field" style="display:none;">
    <label class="form-label">Aprobador (Jefe Inmediato)</label>
    <?= $this->Form->control('approver_id', [
        'label' => false,
        'options' => $approversList ?? [],
        'empty' => '— Seleccione aprobador —',
        'class' => 'form-select select2',
    ]) ?>
</div>
```

**Step 3: Update JS to show/hide approver and signature fields dynamically**

In the existing JS that calls `getFlags`, add logic to:
- Show `#approver-field` when `requires_boss_approval` is true
- Show `#signature-field` when `requires_employee_signature_creation` is true

```javascript
// On novelty type change, fetch flags
var typeSelect = document.getElementById('novelty-type-id');
if (typeSelect) {
    typeSelect.addEventListener('change', function() {
        var typeId = this.value;
        if (!typeId) return;

        fetch('/novelty-types/get-flags/' + typeId)
            .then(r => r.json())
            .then(flags => {
                // Show/hide approver field
                var approverField = document.getElementById('approver-field');
                if (approverField) {
                    approverField.style.display = flags.requires_boss_approval ? '' : 'none';
                }

                // Show/hide employee signature field
                var sigField = document.getElementById('signature-field');
                if (sigField) {
                    sigField.style.display = flags.requires_employee_signature_creation ? '' : 'none';
                }
            });
    });
}
```

**Step 4: Commit**

```bash
git add templates/EmployeeNovelties/add.php
git commit -m "feat: add dynamic approver select and conditional signature field in novelty add form"
```

---

### Task 19: Actualizar template `EmployeeNovelties/edit.php`

**Files:**
- Modify: `templates/EmployeeNovelties/edit.php`

**Step 1: Read the current template**

Read the file to understand the current structure.

**Step 2: Add approval rejection info and resend button**

When `$isApprovalRejected` is true, show:
- Alert indicating rejection
- Button to resend approval

```html
<?php if (!empty($isApprovalRejected)): ?>
<div class="alert alert-danger d-flex align-items-center gap-2">
    <i class="bi bi-x-circle"></i>
    <span>Esta novedad fue <strong>rechazada</strong> por el aprobador. Edite los datos necesarios y reenvíe para aprobación.</span>
</div>
<?= $this->Form->postLink(
    '<i class="bi bi-send me-1"></i>Reenviar para Aprobación',
    ['action' => 'resendApproval', $novelty->id],
    ['class' => 'btn btn-warning', 'escape' => false, 'confirm' => '¿Reenviar la solicitud de aprobación?']
) ?>
<?php endif; ?>
```

**Step 3: Add approver display/edit when in aprobacion status**

If status is `aprobacion`, show the approver info or a select to change it:

```html
<?php if ($novelty->pipeline_status === \App\Constants\NoveltyConstants::STATUS_APROBACION): ?>
<div class="mb-3">
    <label class="form-label">Aprobador</label>
    <?= $this->Form->control('approver_id', [
        'label' => false,
        'options' => $approversList ?? [],
        'empty' => '— Seleccione —',
        'class' => 'form-select select2',
        'value' => $novelty->approver_id,
    ]) ?>
</div>
<?php endif; ?>
```

**Step 4: Commit**

```bash
git add templates/EmployeeNovelties/edit.php
git commit -m "feat: add approval rejection info and resend button in novelty edit template"
```

---

### Task 20: Actualizar templates de `NoveltyLiquidationDocs/edit.php`

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/edit.php`

**Step 1: Read the current template**

Read the file to understand the signature widgets section.

**Step 2: Remove jefe_inmediato signature widget**

Find and remove any section that renders a signature widget for `jefe_inmediato`.

**Step 3: Remove "use existing signature" option**

Find and remove the `use_existing_path` radio/select option from the worker signature widget.

**Step 4: Make worker signature conditional**

Only show the `trabajador` signature widget if there's a signature slot for it (check `$doc->novelty_liquidation_signatures`):

```php
<?php foreach ($doc->novelty_liquidation_signatures as $sig): ?>
    <!-- Render signature widget for each signer_type present -->
    <!-- jefe_inmediato slots no longer exist, so no widget will render for it -->
<?php endforeach; ?>
```

**Step 5: Commit**

```bash
git add templates/NoveltyLiquidationDocs/edit.php
git commit -m "refactor: remove jefe_inmediato and use_existing_path from liquidation doc edit template"
```

---

### Task 21: Actualizar `NoveltyLiquidationDocs/view.php`

**Files:**
- Modify: `templates/NoveltyLiquidationDocs/view.php`

**Step 1: Read and update**

Remove any references to `jefe_inmediato` in the signatures display section. The view renders from `$doc->novelty_liquidation_signatures`, so if old data is cleaned up by migration, this should work automatically. However, update any hardcoded signer type labels or sections.

**Step 2: Commit**

```bash
git add templates/NoveltyLiquidationDocs/view.php
git commit -m "refactor: update liquidation doc view to reflect reduced signer types"
```

---

### Task 22: Actualizar `EmployeeNovelties/index.php` y `view.php`

**Files:**
- Modify: `templates/EmployeeNovelties/index.php`
- Modify: `templates/EmployeeNovelties/view.php`

**Step 1: Update status labels**

The templates reference `NoveltyConstants::STATUS_LABELS` which was already updated. Verify that the pipeline progress element works correctly with the renamed status.

**Step 2: Check pipeline_progress element**

Read `templates/element/pipeline_progress.php` and verify it works with the new status values. If it references `firmas_aprobacion` directly, update it.

**Step 3: Commit**

```bash
git add templates/EmployeeNovelties/index.php templates/EmployeeNovelties/view.php templates/element/pipeline_progress.php
git commit -m "refactor: update status references in index, view and pipeline progress element"
```

---

### Task 23: Migración de datos — actualizar `pipeline_status` existentes

**Files:**
- Create: `config/Migrations/20260324000004_MigratePipelineStatusValues.php`

**Step 1: Crear migración de datos**

```bash
php bin/cake migrations create MigratePipelineStatusValues
```

**Step 2: Implementar**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class MigratePipelineStatusValues extends BaseMigration
{
    public function up(): void
    {
        // Rename existing firmas_aprobacion status to revision_firmas
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
        $this->execute("UPDATE novelty_liquidation_docs SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
        $this->execute("UPDATE novelty_documents SET pipeline_status = 'revision_firmas' WHERE pipeline_status = 'firmas_aprobacion'");
    }

    public function down(): void
    {
        $this->execute("UPDATE employee_novelties SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
        $this->execute("UPDATE novelty_liquidation_docs SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
        $this->execute("UPDATE novelty_documents SET pipeline_status = 'firmas_aprobacion' WHERE pipeline_status = 'revision_firmas'");
    }
}
```

**Step 3: Ejecutar**

```bash
php bin/cake migrations migrate
```

**Step 4: Commit**

```bash
git add config/Migrations/*MigratePipelineStatusValues*
git commit -m "migration: rename firmas_aprobacion to revision_firmas in existing data"
```

---

### Task 24: Verificación final

**Step 1: Verificar que el servidor arranca sin errores**

```bash
php bin/cake server
```

Expected: Server starts on localhost:8765 without errors.

**Step 2: Verificar las migraciones**

```bash
php bin/cake migrations status
```

Expected: All migrations show as "up".

**Step 3: Verificar code style**

```bash
composer cs-check
```

Fix any issues with `composer cs-fix` if needed.

**Step 4: Test manual de flujo completo**

1. Crear un tipo de novedad con `requires_boss_approval = true`
2. Crear una novedad de ese tipo → debe mostrar select de aprobadores
3. Verificar que la novedad inicia en estado `aprobacion`
4. Verificar que se genera el token y se puede acceder via `/approve/{token}`
5. Probar aprobar → debe avanzar a `rrhh`
6. Probar rechazar → debe quedarse en `aprobacion` marcada como rechazada
7. Probar reenviar → debe generar nuevo token

**Step 5: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup and verification of novelties refactoring"
```
