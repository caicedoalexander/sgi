# Pipeline Permissions Implementation Plan

> **Para agentes ejecutores:** este proyecto **NO usa tests automatizados** (ver `CLAUDE.md` "Testing Policy" y memoria del usuario). En lugar de TDD, cada tarea termina con (a) `composer cs-check` y (b) la persona usuaria hará el smoke manual del flujo entre tareas. NO escribir archivos en `tests/`. NO proponer fixtures/PHPUnit.

> **Spec referencia:** `docs/superpowers/specs/2026-05-02-pipeline-permissions-design.md`. Leerlo antes de empezar.

**Goal:** Migrar autorización de transiciones de pipeline + edición de campos por estado desde constantes hardcodeadas (`RoleConstants::*`) a una tabla `pipeline_permissions` configurable desde la UI de Roles.

**Architecture:** Tabla nueva `pipeline_permissions(role_id, pipeline, step, can_operate)`. Servicio nuevo `PipelineAuthorizationService` (espejo de `AuthorizationService`). Admin bypassa. Seed vacío. UI en `Roles/edit.php` con segunda matriz debajo de la actual. Reescritura quirúrgica de `InvoiceFieldAccessPolicy` (cambia firma a `roleId+roleName+status`), guards de `canAdvance`/`canRegress`/`canReject` en `InvoicePipelineService`, `NoveltyPipelineService`, `PaymentSchedulingPipelineService`, `RefundService`, `PettyCashService`, y guards inline en controllers de pagos.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, `migrations/BaseMigration`, league/container DI (`src/Application.php`).

**Decisión clave del scope:** los métodos `getRoleVisibility()` y `getAdvanceRoleVisibility()` de los `Pipeline/State/*` (que solo se usan para **filtrar listados** en index — fuera del alcance del spec) se mantienen como están. Lo que cambia es el **chequeo de autorización para avanzar/regresar/editar**.

**Decisión clave de granularidad:** un único flag `can_operate` por `(role_id, pipeline, step)` cubre tres acciones: (1) avanzar/regresar desde ese paso, (2) editar los campos definidos para ese paso, (3) ver la sección del formulario asociada al paso.

---

## Estructura de archivos

**Nuevos:**
- `config/Migrations/20260502180520_CreatePipelinePermissions.php` — tabla
- `src/Constants/PipelineStepConstants.php` — pares `(pipeline, step)` válidos + labels
- `src/Service/PipelineAuthorizationService.php` — servicio de autorización
- `src/Model/Table/PipelinePermissionsTable.php` — modelo CakePHP
- `src/Model/Entity/PipelinePermission.php` — entidad CakePHP

**Modificados:**
- `src/Application.php` — registrar servicio en DI
- `src/Service/InvoiceFieldAccessPolicy.php` — reescritura: mapas por step (sin rol) + delega a servicio
- `src/Service/InvoiceTransitionValidator.php` — `filterErrorsForRole` acepta `roleId`
- `src/Service/InvoicePipelineService.php` — métodos delegantes aceptan `roleId`; `canAdvance`/`canRegress` usan servicio
- `src/Service/NoveltyPipelineService.php` — `getEditableFields/getVisibleSections/canAdvanceFromStatus` usan servicio
- `src/Service/PaymentSchedulingPipelineService.php` — `canAdvance/canReject/canRegress` usan servicio
- `src/Service/RefundService.php` — `canRegress` usa servicio
- `src/Service/PettyCashService.php` — `canRegress` usa servicio
- `src/Controller/InvoicesController.php` — propaga `roleId` a `getEditableFields/getVisibleSections`
- `src/Controller/AdvancesController.php` — idem (mismo patrón)
- `src/Controller/InvoicePaymentsController.php` — guards inline usan servicio
- `src/Controller/LiquidationDocPaymentsController.php` — guards inline usan servicio
- `src/Controller/NoveltyLiquidationDocsController.php` — guards inline usan servicio
- `src/Controller/RefundsController.php` — guards inline usan servicio
- `src/Controller/PettyCashRecordsController.php` — guards inline usan servicio
- `src/Controller/RolesController.php` — carga/persiste matriz nueva
- `templates/Roles/edit.php` — segunda card con matriz pipeline
- `templates/Roles/view.php` — sección informativa con matriz pipeline

**Sin cambios (pero relevantes):**
- `src/Service/Pipeline/State/*.php` (6 archivos) — `getRoleVisibility/getAdvanceRoleVisibility` siguen igual; solo afectan listado en index, fuera del alcance.
- `src/Constants/RefundConstants.php`, `src/Constants/PettyCashConstants.php` — `REGRESS_ROLE_BY_STATUS` queda en el archivo pero ya no se consulta (se documenta en código que es legacy).

---

## Convenciones de implementación

- **Inyección de dependencias:** constructor con `?ServiceClass $svc = null` y fallback `$this->svc = $svc ?? new ServiceClass()` (patrón vigente en el proyecto).
- **Acceso a tablas dentro de servicios:** `TableRegistry::getTableLocator()->get('NombreTabla')`.
- **Comparación admin:** `$roleName === RoleConstants::ADMIN`.
- **Orden de imports:** alfabético, luego use trait/groups, conforme a CakePHP coding standard.
- **Mensaje de error denegación:** `'No tiene permisos para operar este paso del pipeline.'` (usar literal exactamente igual en todos los controllers para consistencia).
- **Commits:** mensaje en español, formato `type(scope): mensaje`. Después de cada tarea hacer commit antes de pasar a la siguiente.

---

## Tarea 1: Migración + Modelo de tabla

**Files:**
- Create: `config/Migrations/20260502180520_CreatePipelinePermissions.php`
- Create: `src/Model/Table/PipelinePermissionsTable.php`
- Create: `src/Model/Entity/PipelinePermission.php`

- [ ] **Step 1.1: Crear migración**

Contenido de `config/Migrations/20260502180520_CreatePipelinePermissions.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePipelinePermissions extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('pipeline_permissions')) {
            return;
        }

        $this->table('pipeline_permissions')
            ->addColumn('role_id', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('pipeline', 'string', [
                'limit' => 40,
                'null' => false,
            ])
            ->addColumn('step', 'string', [
                'limit' => 40,
                'null' => false,
            ])
            ->addColumn('can_operate', 'boolean', [
                'default' => false,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['role_id', 'pipeline', 'step'], [
                'unique' => true,
                'name' => 'pipeline_permissions_role_pipeline_step_unique',
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pipeline_permissions')) {
            $this->table('pipeline_permissions')->drop()->save();
        }
    }
}
```

- [ ] **Step 1.2: Crear entidad**

Contenido de `src/Model/Entity/PipelinePermission.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PipelinePermission extends Entity
{
    protected array $_accessible = [
        'role_id' => true,
        'pipeline' => true,
        'step' => true,
        'can_operate' => true,
        'created' => true,
        'modified' => true,
        'role' => true,
    ];
}
```

- [ ] **Step 1.3: Crear tabla CakePHP**

Contenido de `src/Model/Table/PipelinePermissionsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class PipelinePermissionsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('pipeline_permissions');
        $this->setDisplayField('step');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Roles', [
            'foreignKey' => 'role_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('role_id')
            ->requirePresence('role_id', 'create')
            ->notEmptyString('role_id');

        $validator
            ->scalar('pipeline')
            ->maxLength('pipeline', 40)
            ->requirePresence('pipeline', 'create')
            ->notEmptyString('pipeline');

        $validator
            ->scalar('step')
            ->maxLength('step', 40)
            ->requirePresence('step', 'create')
            ->notEmptyString('step');

        $validator
            ->boolean('can_operate')
            ->requirePresence('can_operate', 'create')
            ->notEmptyString('can_operate');

        return $validator;
    }
}
```

- [ ] **Step 1.4: Aplicar migración**

Run: `php bin/cake migrations migrate`
Expected: la migración se aplica; tabla `pipeline_permissions` existe en BD.

- [ ] **Step 1.5: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores en los 3 archivos creados.

- [ ] **Step 1.6: Commit**

```bash
git add config/Migrations/20260502180520_CreatePipelinePermissions.php \
        src/Model/Table/PipelinePermissionsTable.php \
        src/Model/Entity/PipelinePermission.php
git commit -m "feat(pipeline-permissions): tabla y modelo pipeline_permissions"
```

---

## Tarea 2: Constantes `PipelineStepConstants`

**Files:**
- Create: `src/Constants/PipelineStepConstants.php`

- [ ] **Step 2.1: Crear constants file**

Contenido completo de `src/Constants/PipelineStepConstants.php`:

```php
<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Catálogo declarativo de los pasos del pipeline configurables vía
 * `pipeline_permissions`. Sirve como única fuente de verdad para:
 *  - validar input del POST en RolesController::edit (defensa contra POST manipulado),
 *  - iterar la matriz de permisos en la UI,
 *  - traducir slugs a etiquetas en español.
 */
final class PipelineStepConstants
{
    public const PIPELINE_INVOICES = 'invoices';
    public const PIPELINE_NOVELTIES = 'novelties';
    public const PIPELINE_PAYMENT_SCHEDULINGS = 'payment_schedulings';
    public const PIPELINE_REFUNDS = 'refunds';
    public const PIPELINE_PETTY_CASH = 'petty_cash';

    public const PIPELINES = [
        self::PIPELINE_INVOICES,
        self::PIPELINE_NOVELTIES,
        self::PIPELINE_PAYMENT_SCHEDULINGS,
        self::PIPELINE_REFUNDS,
        self::PIPELINE_PETTY_CASH,
    ];

    public const PIPELINE_LABELS = [
        self::PIPELINE_INVOICES => 'Facturas',
        self::PIPELINE_NOVELTIES => 'Novedades',
        self::PIPELINE_PAYMENT_SCHEDULINGS => 'Programación de pagos',
        self::PIPELINE_REFUNDS => 'Reintegros',
        self::PIPELINE_PETTY_CASH => 'Caja menor',
    ];

    /**
     * Pasos válidos por pipeline. La lista debe coincidir con los estados que
     * los services de cada dominio usan para autorización (no necesariamente
     * con todos los estados del pipeline — se excluyen estados terminales sin
     * autorización configurable, como 'pagada' en facturas).
     */
    public const STEPS_BY_PIPELINE = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION,
            InvoiceConstants::STATUS_CONTABILIDAD,
            InvoiceConstants::STATUS_TESORERIA,
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION,
            NoveltyConstants::STATUS_RRHH,
            NoveltyConstants::STATUS_CONTABILIDAD,
            NoveltyConstants::STATUS_REVISION_FIRMAS,
            NoveltyConstants::STATUS_GDP,
            NoveltyConstants::STATUS_TESORERIA,
            NoveltyConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION,
            RefundConstants::STATUS_CONTABILIDAD,
            RefundConstants::STATUS_TESORERIA,
            RefundConstants::STATUS_AUT_PAGO,
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION,
            PettyCashConstants::STATUS_CONTABILIDAD,
            PettyCashConstants::STATUS_TESORERIA,
            PettyCashConstants::STATUS_AUT_PAGO,
        ],
    ];

    /**
     * Etiquetas en español para mostrar en la UI de configuración.
     */
    public const STEP_LABELS = [
        self::PIPELINE_INVOICES => [
            InvoiceConstants::STATUS_APROBACION => 'Aprobación',
            InvoiceConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            InvoiceConstants::STATUS_TESORERIA => 'Tesorería',
            InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_NOVELTIES => [
            NoveltyConstants::STATUS_APROBACION => 'Aprobación',
            NoveltyConstants::STATUS_RRHH => 'RRHH',
            NoveltyConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            NoveltyConstants::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
            NoveltyConstants::STATUS_GDP => 'GDP',
            NoveltyConstants::STATUS_TESORERIA => 'Tesorería',
            NoveltyConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_PAYMENT_SCHEDULINGS => [
            PaymentSchedulingConstants::STATUS_BORRADOR => 'Borrador',
            PaymentSchedulingConstants::STATUS_TESORERIA => 'Tesorería',
            PaymentSchedulingConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_REFUNDS => [
            RefundConstants::STATUS_AGRUPACION => 'Agrupación',
            RefundConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            RefundConstants::STATUS_TESORERIA => 'Tesorería',
            RefundConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
        self::PIPELINE_PETTY_CASH => [
            PettyCashConstants::STATUS_AGRUPACION => 'Agrupación',
            PettyCashConstants::STATUS_CONTABILIDAD => 'Contabilidad',
            PettyCashConstants::STATUS_TESORERIA => 'Tesorería',
            PettyCashConstants::STATUS_AUT_PAGO => 'Autorización de pago',
        ],
    ];

    /**
     * @return bool true si el par (pipeline, step) está declarado.
     */
    public static function isValid(string $pipeline, string $step): bool
    {
        return in_array($step, self::STEPS_BY_PIPELINE[$pipeline] ?? [], true);
    }
}
```

- [ ] **Step 2.2: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 2.3: Commit**

```bash
git add src/Constants/PipelineStepConstants.php
git commit -m "feat(pipeline-permissions): constantes PipelineStepConstants"
```

---

## Tarea 3: Servicio `PipelineAuthorizationService`

**Files:**
- Create: `src/Service/PipelineAuthorizationService.php`

- [ ] **Step 3.1: Crear servicio**

Contenido completo de `src/Service/PipelineAuthorizationService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;

/**
 * Resuelve si un rol puede operar (avanzar, regresar, editar campos, ver
 * sección) en un paso específico de un pipeline.
 *
 * Espejo de patrón a `AuthorizationService` para módulos CRUD: misma
 * estructura de cache por request, mismo bypass para Admin.
 */
class PipelineAuthorizationService
{
    /** @var array<int, array<string, array<string, bool>>> cache[role_id][pipeline][step] = bool */
    private array $cache = [];

    /**
     * @return bool true si el rol puede operar el paso del pipeline.
     */
    public function canOperate(int $roleId, string $roleName, string $pipeline, string $step): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        $perms = $this->_loadForRole($roleId);

        return (bool)($perms[$pipeline][$step] ?? false);
    }

    /**
     * @return array<string> Pasos del pipeline donde el rol puede operar.
     */
    public function getOperableSteps(int $roleId, string $roleName, string $pipeline): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [];
        }

        $perms = $this->_loadForRole($roleId);
        $stepsForPipeline = $perms[$pipeline] ?? [];

        return array_values(array_filter(
            PipelineStepConstants::STEPS_BY_PIPELINE[$pipeline] ?? [],
            static fn(string $step): bool => !empty($stepsForPipeline[$step]),
        ));
    }

    /**
     * Devuelve la matriz completa para alimentar la UI de Roles/edit.
     *
     * @return array<string, array<string, bool>> matrix[pipeline][step] = bool
     */
    public function getPermissionsMatrix(int $roleId): array
    {
        $perms = $this->_loadForRole($roleId);
        $matrix = [];

        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            $matrix[$pipeline] = [];
            foreach ($steps as $step) {
                $matrix[$pipeline][$step] = (bool)($perms[$pipeline][$step] ?? false);
            }
        }

        return $matrix;
    }

    /**
     * Persiste la matriz para un rol. Ignora pares (pipeline, step) inválidos.
     *
     * Estrategia: para cada par válido, upsert por (role_id, pipeline, step).
     * No borra filas existentes — solo actualiza el flag `can_operate` (false
     * cuando el checkbox no viene en el POST).
     */
    public function savePermissions(int $roleId, array $data): void
    {
        $table = TableRegistry::getTableLocator()->get('PipelinePermissions');

        foreach (PipelineStepConstants::STEPS_BY_PIPELINE as $pipeline => $steps) {
            $pipelineData = $data[$pipeline] ?? [];

            foreach ($steps as $step) {
                if (!PipelineStepConstants::isValid($pipeline, $step)) {
                    continue;
                }

                $existing = $table->find()
                    ->where([
                        'role_id' => $roleId,
                        'pipeline' => $pipeline,
                        'step' => $step,
                    ])
                    ->first();

                $payload = [
                    'role_id' => $roleId,
                    'pipeline' => $pipeline,
                    'step' => $step,
                    'can_operate' => !empty($pipelineData[$step]),
                ];

                if ($existing) {
                    $existing = $table->patchEntity($existing, $payload);
                    $table->save($existing);
                } else {
                    $entity = $table->newEntity($payload);
                    $table->save($entity);
                }
            }
        }

        unset($this->cache[$roleId]);
    }

    /**
     * @return array<string, array<string, bool>> perms[pipeline][step] = bool
     */
    private function _loadForRole(int $roleId): array
    {
        if (isset($this->cache[$roleId])) {
            return $this->cache[$roleId];
        }

        $rows = TableRegistry::getTableLocator()
            ->get('PipelinePermissions')
            ->find()
            ->where(['role_id' => $roleId])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->pipeline][$row->step] = (bool)$row->can_operate;
        }

        $this->cache[$roleId] = $result;

        return $result;
    }
}
```

- [ ] **Step 3.2: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 3.3: Commit**

```bash
git add src/Service/PipelineAuthorizationService.php
git commit -m "feat(pipeline-permissions): servicio PipelineAuthorizationService"
```

---

## Tarea 4: Registrar servicio en DI container

**Files:**
- Modify: `src/Application.php`

- [ ] **Step 4.1: Leer la sección de bindings actual**

Run: `grep -n "addShared\|InvoiceFieldAccessPolicy\|AuthorizationService" src/Application.php`

Verificar el patrón vigente: `$container->addShared(ClassName::class);` y formas de inyección.

- [ ] **Step 4.2: Añadir registro del servicio**

Encontrar la sección donde se registran los servicios (cerca de `InvoiceFieldAccessPolicy::class` o `AuthorizationService::class`). Añadir tres cosas:

1. Use statement al tope: `use App\Service\PipelineAuthorizationService;`
2. Bind shared: `$container->addShared(PipelineAuthorizationService::class);`
3. Si `InvoiceFieldAccessPolicy` se registra como `addShared(InvoiceFieldAccessPolicy::class);` sin argumentos, hay que actualizarlo cuando la Tarea 5 cambie su constructor — por ahora, dejar como está (esa actualización va en la Tarea 5).

Ejemplo de bloque a añadir (ajustar al estilo exacto del archivo):

```php
$container->addShared(PipelineAuthorizationService::class);
```

- [ ] **Step 4.3: Smoke check**

Run: `composer cs-check && php bin/cake server` (este último lo prueba la persona usuaria)
Expected: el contenedor instancia `PipelineAuthorizationService` sin error al cargar cualquier ruta.

- [ ] **Step 4.4: Commit**

```bash
git add src/Application.php
git commit -m "chore(pipeline-permissions): registrar PipelineAuthorizationService en DI"
```

---

## Tarea 5: Reescribir `InvoiceFieldAccessPolicy`

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php` (reescritura completa)
- Modify: `src/Application.php` (actualizar binding con nueva dependencia)

**Cambio de firma público:** `getEditableFields/getVisibleSections/getCollapsibleSections/filterEntityData` ahora reciben `int $roleId, string $roleName, string $status` (antes: `string $roleName, string $status`). Los callers (Tareas 6 y 7) se actualizan a continuación.

- [ ] **Step 5.1: Reescribir el archivo**

Reemplazar el contenido completo de `src/Service/InvoiceFieldAccessPolicy.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RoleConstants;

/**
 * Calcula qué campos puede editar un usuario en una factura y qué secciones
 * del formulario debe ver, dado su rol y el estado actual del pipeline.
 *
 * El mapeo `step → campos editables` y `step → sección visible` es lógica de
 * dominio (vive en código). La autorización (¿este rol puede operar este
 * paso?) se delega a `PipelineAuthorizationService`, que consulta
 * `pipeline_permissions`.
 */
class InvoiceFieldAccessPolicy
{
    private const ALL_FIELDS = [
        'invoice_number', 'issue_date', 'due_date',
        'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
        'detail', 'amount', 'expense_type_id', 'cost_center_id',
        'confirmed_by', 'area_approval',
        'dian_validation', 'accrued', 'accrual_date', 'ready_for_payment',
        'payment_status', 'full_payment_date', 'pipeline_status',
    ];

    /** Campos editables por paso del pipeline (sin acoplamiento a rol). */
    private const FIELDS_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => [
            'invoice_number', 'issue_date', 'due_date',
            'document_type', 'purchase_order', 'provider_id', 'operation_center_id',
            'detail', 'amount', 'expense_type_id', 'cost_center_id',
            'confirmed_by',
            'dian_validation',
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            'accrued', 'accrual_date', 'ready_for_payment',
        ],
        InvoiceConstants::STATUS_TESORERIA => [],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [],
    ];

    /** Sección del formulario asociada a cada paso. */
    private const SECTION_BY_STEP = [
        InvoiceConstants::STATUS_APROBACION => 'revision',
        InvoiceConstants::STATUS_CONTABILIDAD => 'accounting',
        InvoiceConstants::STATUS_TESORERIA => 'treasury',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'payment_authorization',
    ];

    private PipelineAuthorizationService $pipelineAuth;

    public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
    {
        $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    }

    public function getEditableFields(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::ALL_FIELDS;
        }

        $allowedSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        if (!in_array($status, $allowedSteps, true)) {
            return [];
        }

        return self::FIELDS_BY_STEP[$status] ?? [];
    }

    public function getVisibleSections(int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $this->_resolveAdminSections($status);
        }

        $sections = ['ledger'];

        $operableSteps = $this->pipelineAuth->getOperableSteps(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_INVOICES,
        );

        foreach ($operableSteps as $step) {
            if (isset(self::SECTION_BY_STEP[$step])) {
                $sections[] = self::SECTION_BY_STEP[$step];
            }
        }

        return array_values(array_unique($sections));
    }

    public function getCollapsibleSections(int $roleId, string $roleName, string $status): array
    {
        // La política previa no definía secciones colapsables por rol/estado;
        // se mantiene el contrato vacío.
        return [];
    }

    public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $data;
        }

        $allowed = $this->getEditableFields($roleId, $roleName, $status);

        return array_intersect_key($data, array_flip($allowed));
    }

    private function _resolveAdminSections(string $status): array
    {
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

    private function _getStatusIndex(string $status): int
    {
        if ($status === InvoiceConstants::STATUS_LEGALIZADA) {
            return array_search(InvoiceConstants::STATUS_CONTABILIDAD, InvoiceConstants::PIPELINE_STATUSES);
        }

        $index = array_search($status, InvoiceConstants::PIPELINE_STATUSES);

        return $index !== false ? $index : 0;
    }
}
```

- [ ] **Step 5.2: Actualizar registro DI de `InvoiceFieldAccessPolicy`**

En `src/Application.php`, localizar dónde se registra `InvoiceFieldAccessPolicy::class` (líneas 194 y 200/216 según `grep`). Asegurar que la inyección recibe `PipelineAuthorizationService`:

Ejemplo del patrón a aplicar (ajustar al estilo del archivo):

```php
$container->addShared(InvoiceFieldAccessPolicy::class)
    ->addArgument(PipelineAuthorizationService::class);
```

Si el binding original era `$container->addShared(InvoiceFieldAccessPolicy::class);` (sin argumentos), el fallback `?? new PipelineAuthorizationService()` del constructor sigue funcionando — pero perdería el cache compartido. **Inyectar explícitamente**.

- [ ] **Step 5.3: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 5.4: Commit**

```bash
git add src/Service/InvoiceFieldAccessPolicy.php src/Application.php
git commit -m "refactor(pipeline-permissions): InvoiceFieldAccessPolicy delega a PipelineAuthorizationService"
```

---

## Tarea 6: Actualizar `InvoicePipelineService` y `InvoiceTransitionValidator`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/InvoiceTransitionValidator.php`

Cambios:
1. Inyectar `PipelineAuthorizationService` en `InvoicePipelineService`.
2. Cambiar firma de `getEditableFields/getVisibleSections/getCollapsibleSections/filterEntityData/filterAdvanceErrorsForRole` para aceptar `int $roleId`.
3. `canAdvance/canRegress` consultan al servicio en vez de a `getVisibleStatuses($roleName)`.
4. `saveAndAdvance/regress` reciben `int $roleId` (cambia firma pública — Tarea 7 actualiza callers).
5. `InvoiceTransitionValidator::filterErrorsForRole` y su llamada al field policy aceptan `int $roleId`.

- [ ] **Step 6.1: Actualizar `InvoicePipelineService::__construct`**

Añadir el nuevo argumento al final del constructor (`src/Service/InvoicePipelineService.php` ~líneas 25-35):

```php
public function __construct(
    private readonly HistoryServiceInterface $historyService,
    private readonly InvoicePaymentService $paymentService,
    private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    private readonly InvoiceLockPolicy $lockPolicy,
    private readonly InvoiceTransitionValidator $transitionValidator,
    private readonly InvoicePipelineStateRegistry $states,
    private readonly DocumentTypePolicyFactory $docTypePolicies,
    private readonly EventManagerInterface $events,
    private readonly PipelineAuthorizationService $pipelineAuth,
) {
}
```

Añadir use statement al tope del archivo: `use App\Service\PipelineAuthorizationService;`

- [ ] **Step 6.2: Actualizar firmas y delegaciones**

Reemplazar los métodos siguientes en `src/Service/InvoicePipelineService.php`:

```php
public function getEditableFields(int $roleId, string $roleName, string $status): array
{
    return $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
}

public function getVisibleSections(int $roleId, string $roleName, string $status, ?string $documentType = null): array
{
    $sections = $this->fieldPolicy->getVisibleSections($roleId, $roleName, $status);

    return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections);
}

public function getCollapsibleSections(int $roleId, string $roleName, string $status): array
{
    return $this->fieldPolicy->getCollapsibleSections($roleId, $roleName, $status);
}

public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
{
    return $this->fieldPolicy->filterEntityData($data, $roleId, $roleName, $status);
}

public function filterAdvanceErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
{
    return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleId, $roleName, $status);
}
```

- [ ] **Step 6.3: Cambiar `canAdvance` y `canRegress` para usar el servicio**

Reemplazar (líneas ~174-186 y ~219-230):

```php
public function canAdvance(int $roleId, string $roleName, string $currentStatus, ?string $documentType = null): bool
{
    if ($this->getNextStatus($currentStatus, $documentType) === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        \App\Constants\PipelineStepConstants::PIPELINE_INVOICES,
        $currentStatus,
    );
}

public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
{
    if ($this->getPreviousStatus($currentStatus) === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        \App\Constants\PipelineStepConstants::PIPELINE_INVOICES,
        $currentStatus,
    );
}
```

Añadir use statement al tope: `use App\Constants\PipelineStepConstants;` y reemplazar los `\App\Constants\PipelineStepConstants::` por `PipelineStepConstants::` en el código de arriba (queda más limpio).

- [ ] **Step 6.4: Actualizar `saveAndAdvance` para aceptar `roleId`**

Cambiar la firma y propagar (línea ~237 en `src/Service/InvoicePipelineService.php`):

```php
public function saveAndAdvance(
    Invoice $invoice,
    array $data,
    int $roleId,
    string $roleName,
    int $userId,
    ?string $baseUrl = null,
): ServiceResult {
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

    $currentStatus = $invoice->pipeline_status;
    $filteredData = $this->filterEntityData($data, $roleId, $roleName, $currentStatus);

    // ... (resto del método sin cambios excepto líneas siguientes)

    $canAdvance = $this->canAdvance($roleId, $roleName, $currentStatus, $invoice->document_type ?? null);
    // ... resto idéntico
}
```

(Mantener todo lo demás del método tal cual.)

- [ ] **Step 6.5: Actualizar `advance` y `regress`**

Cambiar las firmas (líneas ~348 y ~387):

```php
public function advance(Invoice $invoice, int $roleId, string $roleName, int $userId): ServiceResult
{
    $currentStatus = $invoice->pipeline_status;

    if (!$this->canAdvance($roleId, $roleName, $currentStatus, $invoice->document_type ?? null)) {
        return ServiceResult::fail(['No tiene permisos para avanzar esta factura.']);
    }
    // ... resto idéntico
}

public function regress(
    Invoice $invoice,
    int $roleId,
    string $roleName,
    int $userId,
    string $reason,
): ServiceResult {
    $reason = trim($reason);
    $currentStatus = $invoice->pipeline_status;

    if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
        // ... resto idéntico
    }
    // ... resto idéntico
}
```

- [ ] **Step 6.6: Actualizar `InvoiceTransitionValidator`**

Cambiar la firma de `filterErrorsForRole` y la llamada interna a `getEditableFields` (`src/Service/InvoiceTransitionValidator.php` ~líneas 74-104):

```php
public function filterErrorsForRole(array $errors, array $rules, int $roleId, string $roleName, string $status): array
{
    if ($roleName === RoleConstants::ADMIN) {
        return array_values($errors);
    }

    $editable = $this->fieldPolicy->getEditableFields($roleId, $roleName, $status);
    $statusVisible = in_array($roleName, $this->states->get($status)->getRoleVisibility(), true);

    $filtered = [];
    foreach ($rules as $i => $rule) {
        if (!isset($errors[$i])) {
            continue;
        }
        $field = $rule['field'];
        $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];

        if ($responsible === []) {
            if ($statusVisible) {
                $filtered[] = $errors[$i];
            }
            continue;
        }

        if (array_intersect($responsible, $editable)) {
            $filtered[] = $errors[$i];
        }
    }

    return $filtered;
}
```

- [ ] **Step 6.7: Actualizar el binding DI de `InvoicePipelineService`**

En `src/Application.php`, asegurar que el constructor recibe `PipelineAuthorizationService::class` como nuevo argumento (al final). Localizar el binding de `InvoicePipelineService` y añadirlo.

- [ ] **Step 6.8: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores. Aún quedarán callers rotos (controllers); se arreglan en la Tarea 7.

- [ ] **Step 6.9: NO hacer commit todavía**

Este cambio rompe a los controllers. Continuar a la Tarea 7 antes de commitear o dejar el commit hasta el final de la Tarea 7.

---

## Tarea 7: Actualizar callers en controllers de facturas

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Modify: `src/Controller/AdvancesController.php` (si existe y usa los mismos métodos)
- Modify: `src/Controller/InvoicePaymentsController.php`
- Modify: `src/Controller/LiquidationDocPaymentsController.php`

- [ ] **Step 7.1: Localizar todos los call sites**

Run:
```bash
grep -rn "pipeline->getEditableFields\|pipeline->getVisibleSections\|pipeline->filterEntityData\|pipeline->filterAdvanceErrorsForRole\|pipeline->canAdvance\|pipeline->canRegress\|pipeline->saveAndAdvance\|pipeline->advance\|pipeline->regress" src/Controller templates/
```

Anotar cada llamada para ajustar la firma agregando `(int)$user->role_id` antes del primer argumento existente.

- [ ] **Step 7.2: Actualizar `InvoicesController`**

En `src/Controller/InvoicesController.php` líneas 264-266, reemplazar:

```php
$editableFields = $this->pipeline->getEditableFields($roleName, $currentStatus);

$visibleSections = $this->pipeline->getVisibleSections($roleName, $currentStatus, $invoice->document_type);
```

por:

```php
$roleId = (int)$user->role_id;
$editableFields = $this->pipeline->getEditableFields($roleId, $roleName, $currentStatus);

$visibleSections = $this->pipeline->getVisibleSections($roleId, $roleName, $currentStatus, $invoice->document_type);
```

(Asumir que `$user = $this->_getCurrentUser();` ya existe en el contexto. Si no, añadirlo justo antes.)

Buscar también las llamadas a `saveAndAdvance/advance/regress` en este controller y propagar `$roleId`.

- [ ] **Step 7.3: Actualizar `InvoicePaymentsController`**

En `src/Controller/InvoicePaymentsController.php`:

- Añadir helper `_getRoleId()`:

```php
private function _getRoleId(): int
{
    return (int)$this->_getCurrentUser()->role_id;
}
```

- Líneas ~39-48: reemplazar el guard de `addPayment` por una llamada a `PipelineAuthorizationService`:

```php
$roleName = $this->_getRoleName();
$roleId = $this->_getRoleId();
$pipelineAuth = $this->getContainer()->get(\App\Service\PipelineAuthorizationService::class);

if (
    $roleName !== RoleConstants::ADMIN
    && !(
        $pipelineAuth->canOperate(
            $roleId,
            $roleName,
            \App\Constants\PipelineStepConstants::PIPELINE_INVOICES,
            $currentStatus,
        )
        && $currentStatus === InvoiceConstants::STATUS_TESORERIA
    )
) {
    $this->Flash->error('No tiene permisos para registrar pagos en este estado.');

    return $this->_redirectForInvoice($invoice, 'edit', $invoiceId);
}
```

(Importar al tope: `use App\Constants\PipelineStepConstants;` y `use App\Service\PipelineAuthorizationService;`. Mejor aún: inyectar el servicio en `initialize()` y guardarlo en una propiedad para no usar `getContainer()->get(...)` en cada acción.)

- Aplicar el mismo patrón en líneas ~80, ~113, ~139, ~171-172. Cada bloque `if ($roleName !== X && $roleName !== ADMIN)` se reemplaza por `if (!$pipelineAuth->canOperate($roleId, $roleName, 'invoices', <step esperado>))`.

  - editPayment (línea ~80): step esperado `STATUS_TESORERIA`
  - authorizePayment (línea ~113): step esperado `STATUS_AUTORIZACION_PAGO`
  - rejectPayment (línea ~139): step esperado `STATUS_AUTORIZACION_PAGO`
  - segundo bloque addPayment (línea ~171): step esperado `STATUS_TESORERIA`

Mantener la condición `$currentStatus === <esperado>` cuando aplica (para no permitir registrar pago en otra fase aunque tenga el permiso).

**Refactor recomendado:** extraer un helper privado `_assertCanOperateInvoice($currentStatus, $expectedStep): bool` para no duplicar la condición compuesta. Pero si compromete la legibilidad, dejarlo inline.

- [ ] **Step 7.4: Actualizar `LiquidationDocPaymentsController`**

En `src/Controller/LiquidationDocPaymentsController.php` líneas ~50, ~83, ~112: aplicar el mismo patrón usando pipeline `PIPELINE_NOVELTIES` y los pasos correspondientes:

- registerPayment (línea ~50): step `NoveltyConstants::STATUS_TESORERIA`
- authorizePayment (línea ~83): step `NoveltyConstants::STATUS_AUT_PAGO`
- rejectPayment (línea ~112): step `NoveltyConstants::STATUS_AUT_PAGO`

- [ ] **Step 7.5: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 7.6: Smoke check usuario**

Pedirle a la persona usuaria: levantar `php bin/cake server` y abrir una factura como admin (cualquier estado). Confirmar que carga sin error 500. La validación funcional con roles no-admin se hace al final del plan tras configurar `pipeline_permissions`.

- [ ] **Step 7.7: Commit (Tareas 6 y 7 juntas)**

```bash
git add src/Service/InvoicePipelineService.php \
        src/Service/InvoiceTransitionValidator.php \
        src/Service/InvoiceFieldAccessPolicy.php \
        src/Controller/InvoicesController.php \
        src/Controller/InvoicePaymentsController.php \
        src/Controller/LiquidationDocPaymentsController.php \
        src/Application.php
git commit -m "refactor(pipeline-permissions): facturas delegan autorización a PipelineAuthorizationService"
```

(Si `AdvancesController` también tenía llamadas, incluirlo.)

---

## Tarea 8: Actualizar `NoveltyPipelineService`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php`

Cambios:
1. Inyectar `PipelineAuthorizationService`.
2. Borrar las constantes `EDITABLE_FIELDS` y `VISIBLE_SECTIONS_BY_ROLE`. Reemplazar por mapas `step → campos` y `step → sección` (sin rol).
3. `getEditableFields/getVisibleSections/canAdvanceFromStatus` cambian firma a `(int $roleId, string $roleName, string $status)` y consultan al servicio.
4. NO tocar `ROLE_VISIBLE_STATUSES` ni `LIQUIDATION_VISIBLE_STATUSES` — son para filtrar listados (fuera del alcance).

- [ ] **Step 8.1: Reescribir las constantes y firmas**

En `src/Service/NoveltyPipelineService.php`:

a) Borrar `EDITABLE_FIELDS` (líneas ~75-87) y reemplazar por:

```php
/** Campos editables por paso del pipeline (sin acoplamiento a rol). */
private const FIELDS_BY_STEP = [
    NoveltyConstants::STATUS_APROBACION => ['approver_id'],
    NoveltyConstants::STATUS_RRHH => ['passes_payroll'],
    NoveltyConstants::STATUS_CONTABILIDAD => ['liquidation_doc_id'],
];
```

b) Borrar `VISIBLE_SECTIONS_BY_ROLE` (líneas ~89-101). Reemplazar por mapeo derivado de los pasos operables:

```php
/** Sección del formulario asociada a cada paso. */
private const SECTIONS_BY_STEP = [
    NoveltyConstants::STATUS_APROBACION => ['informacion', 'fechas', 'motivo', 'aprobacion', 'firmas'],
    NoveltyConstants::STATUS_RRHH => ['informacion', 'fechas', 'motivo', 'aprobacion', 'rrhh', 'firmas'],
    NoveltyConstants::STATUS_CONTABILIDAD => ['informacion', 'fechas', 'contabilidad'],
    NoveltyConstants::STATUS_REVISION_FIRMAS => ['informacion', 'fechas', 'firmas'],
    NoveltyConstants::STATUS_GDP => ['informacion', 'fechas', 'firmas'],
    NoveltyConstants::STATUS_TESORERIA => ['informacion'],
    NoveltyConstants::STATUS_AUT_PAGO => ['informacion'],
];
```

> Estas listas reproducen el mapeo previo según el rol que era dueño del estado. Cuando el admin marque `can_operate` para un rol en varios pasos, las secciones se acumulan (unión).

c) Añadir constructor con DI:

```php
private PipelineAuthorizationService $pipelineAuth;

public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
{
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
}
```

d) Añadir use statements al tope:

```php
use App\Constants\PipelineStepConstants;
use App\Service\PipelineAuthorizationService;
```

- [ ] **Step 8.2: Reescribir `getEditableFields/getVisibleSections/canAdvanceFromStatus/filterEntityData`**

Reemplazar los métodos (líneas ~586-625):

```php
public function getEditableFields(int $roleId, string $roleName, string $status): array
{
    if ($roleName === RoleConstants::ADMIN) {
        return self::ALL_FIELDS;
    }

    if (!$this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_NOVELTIES,
        $status,
    )) {
        return [];
    }

    return self::FIELDS_BY_STEP[$status] ?? [];
}

public function getVisibleSections(int $roleId, string $roleName, string $status): array
{
    if ($roleName === RoleConstants::ADMIN) {
        return self::SECTIONS_BY_STATUS[$status] ?? self::ALL_SECTIONS;
    }

    $operableSteps = $this->pipelineAuth->getOperableSteps(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_NOVELTIES,
    );

    $sections = [];
    foreach ($operableSteps as $step) {
        $sections = array_merge($sections, self::SECTIONS_BY_STEP[$step] ?? []);
    }

    return array_values(array_unique($sections));
}

public function canAdvanceFromStatus(int $roleId, string $roleName, string $status): bool
{
    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_NOVELTIES,
        $status,
    );
}

public function filterEntityData(array $data, int $roleId, string $roleName, string $status): array
{
    $allowed = $this->getEditableFields($roleId, $roleName, $status);

    return array_intersect_key($data, array_flip($allowed));
}
```

- [ ] **Step 8.3: Localizar callers de `canAdvanceFromStatus/getEditableFields/getVisibleSections/filterEntityData` en novelty**

Run:
```bash
grep -rn "canAdvanceFromStatus\|noveltyPipeline->getEditableFields\|noveltyPipeline->getVisibleSections\|noveltyPipeline->filterEntityData\|NoveltyPipeline.*getEditableFields" src/Controller templates/
```

Actualizar cada caller propagando `$roleId` (típicamente `(int)$user->role_id`).

- [ ] **Step 8.4: Actualizar binding DI**

En `src/Application.php`, si `NoveltyPipelineService` se registra, añadir `PipelineAuthorizationService::class` como argumento. Si no se registra explícitamente, el fallback `?? new ...` cubre.

- [ ] **Step 8.5: Smoke check estilo + arranque**

Run: `composer cs-check`. Persona usuaria: arrancar server y abrir `/employee-novelties` como admin.
Expected: carga sin 500.

- [ ] **Step 8.6: Commit**

```bash
git add src/Service/NoveltyPipelineService.php src/Application.php
# + cualquier controller/template tocado
git commit -m "refactor(pipeline-permissions): NoveltyPipelineService usa PipelineAuthorizationService"
```

---

## Tarea 9: Actualizar `PaymentSchedulingPipelineService`

**Files:**
- Modify: `src/Service/PaymentSchedulingPipelineService.php`

Cambios:
1. Inyectar `PipelineAuthorizationService`.
2. `canAdvance/canReject/canRegress` cambian firma a `(int $roleId, string $roleName, string $currentStatus)` y consultan al servicio.
3. NO tocar `ROLE_VISIBLE_STATUSES` (filtrado de listado, fuera de alcance).

- [ ] **Step 9.1: Añadir DI**

En `src/Service/PaymentSchedulingPipelineService.php`, añadir:

```php
use App\Constants\PipelineStepConstants;
use App\Service\PipelineAuthorizationService;
```

Y constructor:

```php
private PipelineAuthorizationService $pipelineAuth;

public function __construct(?PipelineAuthorizationService $pipelineAuth = null)
{
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
}
```

- [ ] **Step 9.2: Reescribir `canAdvance` (líneas 57-82)**

```php
public function canAdvance(int $roleId, string $roleName, string $currentStatus): bool
{
    if (self::TRANSITIONS[$currentStatus] === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
        $currentStatus,
    );
}
```

- [ ] **Step 9.3: Reescribir `canReject` (líneas 84-92)**

```php
public function canReject(int $roleId, string $roleName, string $currentStatus): bool
{
    // Reject solo aplica al paso aut_pago.
    if ($currentStatus !== PaymentSchedulingConstants::STATUS_AUT_PAGO) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
        $currentStatus,
    );
}
```

- [ ] **Step 9.4: Reescribir `canRegress` (líneas 127-152)**

```php
public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
{
    if ($this->getPreviousStatus($currentStatus) === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
        $currentStatus,
    );
}
```

- [ ] **Step 9.5: Actualizar `regress` (línea ~167)**

```php
public function regress(
    PaymentScheduling $scheduling,
    int $roleId,
    string $roleName,
    int $userId,
    string $reason,
): ServiceResult {
    $reason = trim($reason);
    $currentStatus = $scheduling->pipeline_status;

    if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
        // ... resto idéntico
    }
    // ... resto idéntico
}
```

- [ ] **Step 9.6: Localizar callers**

Run:
```bash
grep -rn "schedulingPipeline->canAdvance\|schedulingPipeline->canReject\|schedulingPipeline->canRegress\|schedulingPipeline->regress\|PaymentSchedulingPipelineService.*canAdvance" src/Controller templates/
```

Actualizar cada caller propagando `$roleId`.

- [ ] **Step 9.7: Smoke check estilo + arranque**

Run: `composer cs-check`. Persona usuaria: abrir `/payment-schedulings` como admin.
Expected: carga sin 500.

- [ ] **Step 9.8: Commit**

```bash
git add src/Service/PaymentSchedulingPipelineService.php
# + controllers/templates afectados
git commit -m "refactor(pipeline-permissions): PaymentSchedulingPipelineService usa PipelineAuthorizationService"
```

---

## Tarea 10: Actualizar `RefundService`

**Files:**
- Modify: `src/Service/RefundService.php`

Cambios:
1. Inyectar `PipelineAuthorizationService`.
2. `canRegress` cambia firma a `(int $roleId, string $roleName, string $currentStatus)` y consulta al servicio en vez de `RefundConstants::REGRESS_ROLE_BY_STATUS`.
3. NO tocar `ROLE_VISIBLE_STATUSES`.
4. La constante `RefundConstants::REGRESS_ROLE_BY_STATUS` queda en el archivo pero se vuelve no consultada — añadir comentario advirtiendo que es legacy y migrada a `pipeline_permissions`.

- [ ] **Step 10.1: Inyectar DI**

En `src/Service/RefundService.php` añadir use statements:

```php
use App\Constants\PipelineStepConstants;
```

(El servicio ya tiene un constructor que inicializa `$grouped`. Cambiarlo a:)

```php
private GroupedInvoiceService $grouped;
private PipelineAuthorizationService $pipelineAuth;

public function __construct(
    HistoryServiceInterface $historyService,
    ?PipelineAuthorizationService $pipelineAuth = null,
) {
    $this->grouped = new GroupedInvoiceService(
        documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
        fkField: 'refund_id',
        recordTableName: 'Refunds',
        fkLabel: 'Reintegro',
        historyService: $historyService,
    );
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
}
```

- [ ] **Step 10.2: Reescribir `canRegress` (líneas 452-465)**

```php
public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
{
    if ($this->getPreviousStatus($currentStatus) === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_REFUNDS,
        $currentStatus,
    );
}
```

- [ ] **Step 10.3: Cambiar firma de `regress` (línea ~490)**

```php
public function regress(
    Refund $record,
    int $roleId,
    string $roleName,
    int $userId,
    string $reason,
): array {
    $reason = trim($reason);
    $currentStatus = $record->status;

    if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
        // ... resto idéntico
    }
    // ... resto idéntico
}
```

- [ ] **Step 10.4: Marcar `RefundConstants::REGRESS_ROLE_BY_STATUS` como legacy**

En `src/Constants/RefundConstants.php` líneas 54-58, añadir comentario:

```php
/**
 * @deprecated Migrado a pipeline_permissions a partir del plan
 *   2026-05-02-pipeline-permissions. Conservado solo por referencia
 *   histórica; no se consulta desde el código.
 */
public const REGRESS_ROLE_BY_STATUS = [
    self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
    self::STATUS_TESORERIA => [RoleConstants::TESORERIA],
    self::STATUS_AUT_PAGO => [RoleConstants::CONTADOR],
];
```

- [ ] **Step 10.5: Localizar callers**

Run:
```bash
grep -rn "refundService->canRegress\|refundService->regress\|RefundService.*regress" src/Controller templates/
```

Actualizar callers propagando `$roleId`.

- [ ] **Step 10.6: Smoke check estilo + arranque**

Run: `composer cs-check`. Persona usuaria: abrir `/refunds` como admin.
Expected: carga sin 500.

- [ ] **Step 10.7: Commit**

```bash
git add src/Service/RefundService.php src/Constants/RefundConstants.php
# + controllers/templates afectados
git commit -m "refactor(pipeline-permissions): RefundService.canRegress usa PipelineAuthorizationService"
```

---

## Tarea 11: Actualizar `PettyCashService`

**Files:**
- Modify: `src/Service/PettyCashService.php`

Patrón **idéntico** a Tarea 10 pero para Petty Cash. Pipeline: `PIPELINE_PETTY_CASH`.

- [ ] **Step 11.1: Inyectar DI**

Añadir use statements:

```php
use App\Constants\PipelineStepConstants;
```

Constructor:

```php
private GroupedInvoiceService $grouped;
private PipelineAuthorizationService $pipelineAuth;

public function __construct(
    HistoryServiceInterface $historyService,
    ?PipelineAuthorizationService $pipelineAuth = null,
) {
    $this->grouped = new GroupedInvoiceService(
        documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
        fkField: 'petty_cash_record_id',
        recordTableName: 'PettyCashRecords',
        fkLabel: 'Caja Menor',
        historyService: $historyService,
    );
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
}
```

- [ ] **Step 11.2: Reescribir `canRegress` (líneas 440-453)**

```php
public function canRegress(int $roleId, string $roleName, string $currentStatus): bool
{
    if ($this->getPreviousStatus($currentStatus) === null) {
        return false;
    }

    if ($roleName === RoleConstants::ADMIN) {
        return true;
    }

    return $this->pipelineAuth->canOperate(
        $roleId,
        $roleName,
        PipelineStepConstants::PIPELINE_PETTY_CASH,
        $currentStatus,
    );
}
```

- [ ] **Step 11.3: Cambiar firma de `regress` (línea ~478)**

```php
public function regress(
    PettyCashRecord $record,
    int $roleId,
    string $roleName,
    int $userId,
    string $reason,
): array {
    $reason = trim($reason);
    $currentStatus = $record->status;

    if (!$this->canRegress($roleId, $roleName, $currentStatus)) {
        // ... resto idéntico
    }
    // ... resto idéntico
}
```

- [ ] **Step 11.4: Marcar `PettyCashConstants::REGRESS_ROLE_BY_STATUS` como legacy**

En `src/Constants/PettyCashConstants.php` líneas 56-62 añadir el mismo comentario que en Tarea 10 Step 10.4.

- [ ] **Step 11.5: Localizar callers**

Run:
```bash
grep -rn "pettyCashService->canRegress\|pettyCashService->regress\|PettyCashService.*regress" src/Controller templates/
```

Actualizar callers.

- [ ] **Step 11.6: Smoke check + commit**

Run: `composer cs-check`.

```bash
git add src/Service/PettyCashService.php src/Constants/PettyCashConstants.php
# + controllers/templates afectados
git commit -m "refactor(pipeline-permissions): PettyCashService.canRegress usa PipelineAuthorizationService"
```

---

## Tarea 12: Actualizar guards en controllers de Refunds, PettyCash, NoveltyLiquidationDocs

**Files:**
- Modify: `src/Controller/RefundsController.php`
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

- [ ] **Step 12.1: Refunds — guards inline**

En `src/Controller/RefundsController.php` líneas ~313-319:

Cambiar:

```php
if (!in_array($roleName, [
    RoleConstants::TESORERIA, RoleConstants::ADMIN,
], true)) { ... }

if (!in_array($roleName, [
    RoleConstants::CONTADOR, RoleConstants::ADMIN,
], true)) { ... }
```

Por (asumiendo que ya está importado `PipelineAuthorizationService` y disponible vía `$this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);` en `initialize()`):

```php
$roleId = (int)$this->_getCurrentUser()->role_id;

// Para acción que requiere step Tesorería:
if (!$this->pipelineAuth->canOperate(
    $roleId,
    $roleName,
    PipelineStepConstants::PIPELINE_REFUNDS,
    RefundConstants::STATUS_TESORERIA,
)) {
    $this->Flash->error('No tiene permisos para operar este paso del pipeline.');
    return $this->redirect(['action' => 'view', $id]);
}

// Para acción que requiere step Aut. Pago:
if (!$this->pipelineAuth->canOperate(
    $roleId,
    $roleName,
    PipelineStepConstants::PIPELINE_REFUNDS,
    RefundConstants::STATUS_AUT_PAGO,
)) {
    $this->Flash->error('No tiene permisos para operar este paso del pipeline.');
    return $this->redirect(['action' => 'view', $id]);
}
```

Añadir use statements al tope:

```php
use App\Constants\PipelineStepConstants;
use App\Service\PipelineAuthorizationService;
```

E inyectar el servicio en `initialize()`:

```php
$this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
```

(con la propiedad `private PipelineAuthorizationService $pipelineAuth;` declarada).

- [ ] **Step 12.2: PettyCashRecords — guards inline**

Patrón idéntico al Step 12.1 pero usando `PipelineStepConstants::PIPELINE_PETTY_CASH` y `PettyCashConstants::STATUS_TESORERIA / STATUS_AUT_PAGO`. Líneas ~280-287 en `src/Controller/PettyCashRecordsController.php`.

- [ ] **Step 12.3: NoveltyLiquidationDocs — guards inline**

En `src/Controller/NoveltyLiquidationDocsController.php` líneas ~203-205:

Cambiar:

```php
$isTesoreriaEdit = ($roleName === RoleConstants::TESORERIA || $roleName === RoleConstants::ADMIN)
    && $currentStatus === NoveltyConstants::STATUS_TESORERIA;
$isContadorAutPago = ($roleName === RoleConstants::CONTADOR || $roleName === RoleConstants::ADMIN)
    && $currentStatus === NoveltyConstants::STATUS_AUT_PAGO;
```

Por:

```php
$roleId = (int)$this->_getCurrentUser()->role_id;
$isTesoreriaEdit = $this->pipelineAuth->canOperate(
    $roleId,
    $roleName,
    PipelineStepConstants::PIPELINE_NOVELTIES,
    NoveltyConstants::STATUS_TESORERIA,
) && $currentStatus === NoveltyConstants::STATUS_TESORERIA;

$isContadorAutPago = $this->pipelineAuth->canOperate(
    $roleId,
    $roleName,
    PipelineStepConstants::PIPELINE_NOVELTIES,
    NoveltyConstants::STATUS_AUT_PAGO,
) && $currentStatus === NoveltyConstants::STATUS_AUT_PAGO;
```

Añadir imports e inyección como en Step 12.1.

- [ ] **Step 12.4: Smoke check estilo + arranque**

Run: `composer cs-check`. Persona usuaria: abrir `/refunds`, `/petty-cash-records`, `/novelty-liquidation-docs` como admin.
Expected: las tres páginas cargan sin 500.

- [ ] **Step 12.5: Commit**

```bash
git add src/Controller/RefundsController.php \
        src/Controller/PettyCashRecordsController.php \
        src/Controller/NoveltyLiquidationDocsController.php
git commit -m "refactor(pipeline-permissions): controllers de refunds/petty/novelty-liq usan PipelineAuthorizationService"
```

---

## Tarea 13: UI — Controller `RolesController` carga y persiste matriz

**Files:**
- Modify: `src/Controller/RolesController.php`

- [ ] **Step 13.1: Inyectar el servicio**

En `src/Controller/RolesController.php` añadir al tope:

```php
use App\Service\PipelineAuthorizationService;
use App\Constants\PipelineStepConstants;
```

Añadir propiedad e inicialización:

```php
private PipelineAuthorizationService $pipelineAuth;

public function initialize(): void
{
    parent::initialize();
    $this->pipelineAuth = $this->getContainer()->get(PipelineAuthorizationService::class);
}
```

- [ ] **Step 13.2: Modificar `add()` para soportar matriz vacía en GET y guardarla en POST**

Reemplazar el método `add()` (líneas 26-48):

```php
public function add()
{
    $role = $this->Roles->newEmptyEntity();
    if ($this->request->is('post')) {
        $data = $this->request->getData();
        $role = $this->Roles->patchEntity($role, $data);
        if ($this->Roles->save($role)) {
            $connection = $this->Roles->getConnection();
            $connection->transactional(function () use ($role, $data): void {
                if (!empty($data['permissions'])) {
                    $this->authService->savePermissionsForRole($role->id, $data['permissions']);
                }
                if (!empty($data['pipeline_permissions'])) {
                    $this->pipelineAuth->savePermissions($role->id, $data['pipeline_permissions']);
                }
            });
            $this->Flash->success('El rol ha sido guardado.');

            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->error('No se pudo guardar el rol. Intente de nuevo.');
    }

    $modules = AuthorizationService::MODULES;
    $permissionsMatrix = [];
    $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix(0);
    $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
    $stepLabels = PipelineStepConstants::STEP_LABELS;

    $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
}
```

- [ ] **Step 13.3: Modificar `edit()` para cargar y persistir matriz pipeline**

Reemplazar `edit()` (líneas 50-70):

```php
public function edit($id = null)
{
    $role = $this->Roles->get($id, contain: ['Permissions']);
    if ($this->request->is(['patch', 'post', 'put'])) {
        $data = $this->request->getData();
        $role = $this->Roles->patchEntity($role, $data);
        if ($this->Roles->save($role)) {
            $connection = $this->Roles->getConnection();
            $connection->transactional(function () use ($role, $data): void {
                $this->authService->savePermissionsForRole($role->id, $data['permissions'] ?? []);
                $this->pipelineAuth->savePermissions($role->id, $data['pipeline_permissions'] ?? []);
            });
            $this->Flash->success('El rol ha sido actualizado.');

            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->error('No se pudo actualizar el rol. Intente de nuevo.');
    }

    $modules = AuthorizationService::MODULES;
    $permissionsMatrix = $this->authService->getPermissionsForRoleAsMatrix((int)$id);
    $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix((int)$id);
    $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
    $stepLabels = PipelineStepConstants::STEP_LABELS;

    $this->set(compact('role', 'modules', 'permissionsMatrix', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
}
```

- [ ] **Step 13.4: Modificar `view()` para mostrar la matriz pipeline read-only**

Reemplazar `view()` (líneas 19-24):

```php
public function view($id = null)
{
    $role = $this->Roles->get($id, contain: ['Users', 'Permissions']);
    $pipelineMatrix = $this->pipelineAuth->getPermissionsMatrix((int)$id);
    $pipelineLabels = PipelineStepConstants::PIPELINE_LABELS;
    $stepLabels = PipelineStepConstants::STEP_LABELS;

    $this->set(compact('role', 'pipelineMatrix', 'pipelineLabels', 'stepLabels'));
}
```

- [ ] **Step 13.5: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 13.6: NO commitear todavía**

Continuar a la Tarea 14 para que la UI funcione.

---

## Tarea 14: UI — Templates Roles/edit y Roles/view

**Files:**
- Modify: `templates/Roles/edit.php`
- Modify: `templates/Roles/view.php`

- [ ] **Step 14.1: Editar `templates/Roles/edit.php` — añadir segunda card**

Reemplazar el contenido completo de `templates/Roles/edit.php` por:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array $modules
 * @var array $permissionsMatrix
 * @var array<string, array<string, bool>> $pipelineMatrix
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 */
$this->assign('title', 'Editar Rol: ' . $role->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Rol: <?= h($role->name) ?></span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<?= $this->Form->create($role) ?>

<div class="card card-primary">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre', 'class' => 'form-label']]) ?>
            </div>
            <div class="col-md-6">
                <?= $this->Form->control('description', ['class' => 'form-control', 'label' => ['text' => 'Descripción', 'class' => 'form-label']]) ?>
            </div>
        </div>

        <hr>
        <h6 class="text-muted mb-3"><i class="bi bi-shield-check me-1"></i>Permisos por Módulo</h6>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:35%">Módulo</th>
                        <th class="text-center">Ver</th>
                        <th class="text-center">Crear</th>
                        <th class="text-center">Editar</th>
                        <th class="text-center">Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $module => $label): ?>
                    <?php $perm = $permissionsMatrix[$module] ?? []; ?>
                    <tr>
                        <td class="fw-semibold"><?= h($label) ?></td>
                        <?php foreach (['can_view' => 'Ver', 'can_create' => 'Crear', 'can_edit' => 'Editar', 'can_delete' => 'Eliminar'] as $key => $permLabel): ?>
                        <td class="text-center">
                            <input type="checkbox"
                                   class="form-check-input"
                                   name="permissions[<?= $module ?>][<?= $key ?>]"
                                   value="1"
                                   <?= !empty($perm[$key]) ? 'checked' : '' ?>>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card card-primary mt-3">
    <div class="card-body">
        <h6 class="text-muted mb-3"><i class="bi bi-diagram-3 me-1"></i>Permisos de Pipeline</h6>
        <p class="text-muted small mb-3">
            Cada checkbox autoriza al rol a operar el paso indicado: avanzar/regresar la pieza,
            editar los campos definidos para ese paso y ver la sección correspondiente del formulario.
        </p>

        <?php foreach ($pipelineLabels as $pipeline => $pipelineLabel): ?>
            <div class="mb-4">
                <div class="fw-semibold mb-2"><?= h($pipelineLabel) ?></div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:70%">Paso</th>
                                <th class="text-center">Puede operar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stepLabels[$pipeline] ?? [] as $step => $stepLabel): ?>
                            <tr>
                                <td><?= h($stepLabel) ?></td>
                                <td class="text-center">
                                    <input type="checkbox"
                                           class="form-check-input"
                                           name="pipeline_permissions[<?= h($pipeline) ?>][<?= h($step) ?>]"
                                           value="1"
                                           <?= !empty($pipelineMatrix[$pipeline][$step]) ? 'checked' : '' ?>>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-save me-1"></i>Actualizar</button>
    </div>
</div>

<?= $this->Form->end() ?>
```

> El submit lo dejamos al final del segundo card pero el `Form::create` envuelve ambos.

- [ ] **Step 14.2: Editar `templates/Roles/view.php` — añadir sección informativa**

Reemplazar el contenido completo por:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Role $role
 * @var array<string, array<string, bool>> $pipelineMatrix
 * @var array<string, string> $pipelineLabels
 * @var array<string, array<string, string>> $stepLabels
 */
$this->assign('title', 'Rol: ' . $role->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle del Rol</span>
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
</div>

<div class="card card-primary mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= $this->Number->format($role->id) ?></dd>
            <dt class="col-sm-3">Nombre</dt>
            <dd class="col-sm-9"><?= h($role->name) ?></dd>
            <dt class="col-sm-3">Descripción</dt>
            <dd class="col-sm-9"><?= h($role->description) ?: '<span class="text-muted">—</span>' ?></dd>
            <dt class="col-sm-3">Creado</dt>
            <dd class="col-sm-9"><?= $role->created?->format('d/m/Y H:i') ?></dd>
            <dt class="col-sm-3">Modificado</dt>
            <dd class="col-sm-9"><?= $role->modified?->format('d/m/Y H:i') ?></dd>
        </dl>
    </div>
    <div class="card-footer">
        <?php if (!empty($userPermissions['roles']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $role->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['roles']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $role->id], ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-primary mb-4">
    <div class="card-body">
        <h6 class="text-muted mb-3"><i class="bi bi-diagram-3 me-1"></i>Permisos de Pipeline</h6>
        <?php foreach ($pipelineLabels as $pipeline => $pipelineLabel): ?>
            <div class="mb-3">
                <div class="fw-semibold mb-2"><?= h($pipelineLabel) ?></div>
                <ul class="list-unstyled small mb-0">
                <?php foreach ($stepLabels[$pipeline] ?? [] as $step => $stepLabel): ?>
                    <?php $allowed = !empty($pipelineMatrix[$pipeline][$step]); ?>
                    <li>
                        <i class="bi <?= $allowed ? 'bi-check-circle text-success' : 'bi-x-circle text-muted' ?> me-1"></i>
                        <?= h($stepLabel) ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($role->users)): ?>
<div class="card card-primary">
    <div class="card-header"><h5 class="mb-0">Usuarios con este rol</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Usuario</th>
                    <th>Nombre Completo</th>
                    <th>Email</th>
                    <th>Activo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($role->users as $user): ?>
                <tr>
                    <td><?= $this->Html->link(h($user->username), ['controller' => 'Users', 'action' => 'view', $user->id]) ?></td>
                    <td><?= h($user->full_name) ?></td>
                    <td><?= h($user->email) ?></td>
                    <td><?= $user->active ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 14.3: Smoke check estilo**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 14.4: Smoke check funcional UI**

Persona usuaria: abrir `/roles/edit/<id>` (cualquier rol no-admin) como admin. Confirmar:
- Se ve la card "Permisos por Módulo" (matriz vieja).
- Debajo aparece la card "Permisos de Pipeline" con 5 grupos (Facturas, Novedades, Programación de pagos, Reintegros, Caja menor).
- Cada grupo lista sus pasos con checkbox.
- Al guardar, recargar y verificar que el estado de los checkboxes persistió.
- Abrir `/roles/view/<id>` y verificar que se ve la sección "Permisos de Pipeline" con íconos de check/x.

- [ ] **Step 14.5: Commit (Tareas 13 y 14 juntas)**

```bash
git add src/Controller/RolesController.php templates/Roles/edit.php templates/Roles/view.php
git commit -m "feat(pipeline-permissions): UI de Roles con matriz pipeline_permissions"
```

---

## Tarea 15: Configuración inicial post-migración + validación funcional

> Esta tarea **no toca código**. Es la guía de configuración manual + recorrido de validación end-to-end. La persona usuaria la ejecuta tras los commits anteriores.

**Files:** ninguno (operación + validación manual).

- [ ] **Step 15.1: Aplicar migración (si no se aplicó ya en Tarea 1)**

Run: `php bin/cake migrations status` para confirmar `CreatePipelinePermissions` aplicada.

Si falta: `php bin/cake migrations migrate`.

- [ ] **Step 15.2: Configurar matriz inicial vía UI**

Login como admin → ir a `/roles/index` → entrar a `edit` para cada rol no-admin y marcar la siguiente matriz (reproduce el comportamiento previo al cambio):

**Rol "Registro/Revisión":**
- Facturas: ☑ Aprobación
- Reintegros: ☑ Agrupación

**Rol "Contabilidad":**
- Facturas: ☑ Contabilidad
- Novedades: ☑ Contabilidad
- Reintegros: ☑ Contabilidad
- Caja menor: ☑ Contabilidad

**Rol "Tesorería":**
- Facturas: ☑ Tesorería, ☑ Autorización de pago
- Novedades: ☑ Tesorería, ☑ Autorización de pago
- Programación de pagos: ☑ Borrador, ☑ Tesorería
- Reintegros: ☑ Tesorería, ☑ Autorización de pago
- Caja menor: ☑ Tesorería, ☑ Autorización de pago

**Rol "Contador":**
- Facturas: ☑ Autorización de pago
- Novedades: ☑ Revisión y Firmas, ☑ Autorización de pago
- Programación de pagos: ☑ Autorización de pago
- Reintegros: ☑ Autorización de pago
- Caja menor: ☑ Autorización de pago

**Rol "Coordinador Administrativo y Financiero":**
- Novedades: ☑ Revisión y Firmas

**Rol "Auxiliar de Personal" y "Asistente de Personal":**
- Novedades: ☑ Aprobación, ☑ RRHH, ☑ Revisión y Firmas, ☑ GDP

> Si tras la validación funcional algún flujo queda bloqueado para un rol, ajustar agregando el permiso correspondiente. Esta matriz reproduce los mapeos hardcodeados originales pero **se documenta como recomendación, no como contrato**: el admin puede ampliar/restringir.

- [ ] **Step 15.3: Validación funcional — Facturas**

Con un usuario de cada rol, ejecutar el flujo completo:

1. **Registro/Revisión** crea factura → carga DIAN → todos los aprobadores aprueban → `Avanzar` debe pasar a Contabilidad.
2. **Contabilidad** abre la factura → marca Causada + fecha + Lista para Pago → `Avanzar` debe pasar a Tesorería.
3. **Tesorería** abre la factura → registra un pago pendiente → factura debe avanzar a Autorización de Pago.
4. **Contador** abre la factura → autoriza el pago → factura debe pasar a Pagada.
5. **Cualquier rol no-admin sin el permiso correspondiente** intenta avanzar → debe ver error "No tiene permisos para avanzar esta factura."

- [ ] **Step 15.4: Validación funcional — Novedades**

Ciclo análogo: Aprobación → RRHH → Contabilidad → Revisión y Firmas → GDP → Tesorería → Aut. Pago → Pagada.

- [ ] **Step 15.5: Validación funcional — Programación de pagos**

Borrador → Tesorería → Aut. Pago → Pagada (Tesorería avanza los dos primeros, Contador autoriza el último).

- [ ] **Step 15.6: Validación funcional — Reintegros y Caja menor**

Agrupación → Contabilidad → Tesorería → Aut. Pago → Pagado para cada uno.

- [ ] **Step 15.7: Validación específica de campos editables y secciones visibles**

Como **Tesorería**, abrir una factura en estado `tesoreria`. Confirmar:
- Sección visible: ledger + treasury (no accounting ni payment_authorization).
- Campos editables: ninguno editable directo (la operación es vía botones de pago).

Como **Contabilidad**, abrir una factura en estado `contabilidad`:
- Sección visible: ledger + accounting.
- Campos editables: solo `accrued`, `accrual_date`, `ready_for_payment`.

Como **admin**: abrir una factura en estado `pagada`:
- Todas las secciones visibles.
- Todos los campos editables.

- [ ] **Step 15.8: Validación de reconfiguración en caliente**

Como admin: en `/roles/edit/<id-tesoreria>` desmarcar `Tesorería → Tesorería`. Guardar. Login como Tesorería. Abrir una factura en `tesoreria`. Intentar `Registrar Pago` → debe ver "No tiene permisos para registrar pagos en este estado.". Volver a marcar el permiso, refrescar como Tesorería: la operación vuelve a funcionar.

- [ ] **Step 15.9: Si todo OK, no hay commit en esta tarea**

Esta tarea es validación operativa, no toca código. Si encontró bugs, abrir tarea correctiva e iterar.

- [ ] **Step 15.10: Si encontró comportamientos divergentes esperables**

Documentar en `docs/superpowers/specs/2026-05-02-pipeline-permissions-design.md` (sección "Notas de despliegue") los cambios de comportamiento detectados respecto al estado anterior (ej.: "antes Tesorería podía hacer X, ahora si admin no marca el permiso no puede"). Commit del spec actualizado.

---

## Notas finales

- **Sin tests:** este plan no incluye archivos en `tests/`. Toda la validación es manual.
- **Rollback:** revert del PR + `php bin/cake migrations rollback`. La tabla `pipeline_permissions` se elimina sin pérdida de datos de negocio.
- **Quality review al final:** según preferencia del usuario (memoria), el code-review/refactor de calidad ocurre tras la última tarea, no entre tareas. El revisor debería: (a) chequear que no quedaron usos de `RoleConstants::*` para autorización en los archivos en alcance; (b) verificar que ningún caller pasa `roleName` sin `roleId` a las firmas cambiadas; (c) confirmar que `PipelineAuthorizationService::cache` se invalida tras `savePermissions`.
- **Riesgo principal:** un permiso olvidado bloquea silenciosamente un flujo. La matriz de la Tarea 15 Step 15.2 mitiga esto. Si un flujo se bloquea, reconfigurar desde `/roles/edit`.
