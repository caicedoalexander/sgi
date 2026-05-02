# Plan 8 — Módulo Reintegros (Refunds) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear el módulo `Refunds` (Reintegros): agrupa facturas con `document_type='Reintegro'` siguiendo el mismo flujo que Caja Menor, con un beneficiario polimórfico (employee/provider) en el padre.

**Architecture:** Clon estructural de `PettyCash` reutilizando `GroupedInvoiceService`. Pipeline de 4 estados (`agrupacion → contabilidad → tesoreria → aut_pago → pagado`). Ningún módulo existente cambia su comportamiento; solo se añaden asociaciones `belongsTo Refunds` en `Invoices`/`InvoicePayments`. La diferencia funcional con Caja Menor es la validación de beneficiario antes de pasar a `contabilidad` y la edición read-only del beneficiario fuera de `agrupacion`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MariaDB/MySQL, `Migrations\BaseMigration`.

**Política del proyecto (recordatorio):** este proyecto NO usa tests automatizados (ver `CLAUDE.md` § Testing Policy y memoria `feedback_no_tests.md`). Los smoke checks con `php bin/cake server` los ejecuta el usuario manualmente al final del plan, no se corren desde aquí. La revisión de calidad (`composer cs-fix` + `composer cs-check`) corre **una sola vez** al final del plan, no por tarea.

**Spec:** `docs/superpowers/specs/2026-05-02-refunds-design.md`

**Estrategia de clonación:** PettyCash es un módulo grande pero altamente repetitivo. Para los archivos que son clones 1:1 estructurales (`RefundService`, `RefundsController`, templates, element progress) este plan especifica el archivo fuente y la **lista exacta de reemplazos** a aplicar tras copiar. Esto es más confiable que pegar 600+ líneas de código duplicado que sería difícil de auditar línea a línea.

---

## File Structure

### Nuevos

- `config/Migrations/<timestamp>_CreateRefunds.php` — tabla `refunds`, tabla `refund_observations`, columna `refund_id` en `invoices` e `invoice_payments`. Idempotente con `hasTable()`/`hasColumn()`.
- `config/Migrations/<timestamp>_SeedRefundsPermissions.php` — siembra `permissions` para módulo `refunds`.
- `src/Constants/RefundConstants.php` — constantes paralelas a `PettyCashConstants` con `CODE_PREFIX = 'REI'` y constantes de beneficiario.
- `src/Model/Entity/Refund.php`
- `src/Model/Table/RefundsTable.php` — asociaciones, validación, regla de beneficiario (`buildRules`), `beforeSave` para generar `code`.
- `src/Model/Entity/RefundObservation.php`
- `src/Model/Table/RefundObservationsTable.php`
- `src/Service/RefundService.php` — clon de `PettyCashService` con la diff descrita en Task 7.
- `src/Controller/RefundsController.php` — clon de `PettyCashRecordsController` con la diff descrita en Task 9.
- `templates/Refunds/index.php`
- `templates/Refunds/add.php`
- `templates/Refunds/edit.php`
- `templates/Refunds/view.php`
- `templates/element/refund_progress.php` — clon visual de `petty_cash_progress.php`.

### Modificados

- `src/Model/Table/InvoicesTable.php` — agregar `belongsTo('Refunds', ['foreignKey' => 'refund_id'])`.
- `src/Model/Table/InvoicePaymentsTable.php` — agregar `belongsTo('Refunds', ['foreignKey' => 'refund_id'])`.
- `src/Service/AuthorizationService.php` — agregar `'refunds' => 'Reintegros'` a la constante `MODULES`.
- `src/Controller/AppController.php` — agregar `'Refunds' => 'refunds'` a `$controllerModuleMap`.
- `src/Service/SidebarCounterService.php` — método `getRefundsPendingCount` y wiring en el counter agregado.
- `templates/layout/default.php` — nav-link `Reintegros` debajo del de `Caja Menor`.
- `config/routes.php` — scope `/refunds` con rutas POST custom antes de `$builder->fallbacks()`.
- `src/Application.php` — registrar `RefundService` en el container DI.

### NO modificados

- `src/Constants/InvoiceConstants.php` — `DOCTYPE_REINTEGRO` ya existe.
- `src/Service/GroupedInvoiceService.php` — ya soporta este caso por parametrización.
- `src/Service/InvoicePipelineService.php`, `InvoicePaymentService`, `InvoiceFieldAccessPolicy` — el flujo de las facturas hijas no cambia.
- `src/Service/PaymentRegistryService.php` — `'reintegro_doc'` sigue como estaba.

---

## Task 1: Migración — crear tablas `refunds`, `refund_observations` y columnas `refund_id`

**Files:**
- Create: `config/Migrations/<timestamp>_CreateRefunds.php` (timestamp lo genera `bin/cake`)

- [ ] **Step 1: Generar el archivo de migración**

```bash
php bin/cake migrations create CreateRefunds
```

Esto crea un archivo en `config/Migrations/<timestamp>_CreateRefunds.php` con stub vacío.

- [ ] **Step 2: Reemplazar el contenido del archivo**

Sobreescribir el contenido completo del archivo recién creado con:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefunds extends BaseMigration
{
    public function up(): void
    {
        // refunds (parent record)
        if (!$this->hasTable('refunds')) {
            $table = $this->table('refunds');

            $table->addColumn('code', 'string', [
                'limit' => 30,
                'null' => false,
            ]);
            $table->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'agrupacion',
            ]);
            $table->addColumn('total_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => false,
                'default' => 0,
            ]);

            // Beneficiary (polymorphic: employee XOR provider)
            $table->addColumn('beneficiary_type', 'string', [
                'limit' => 20,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('beneficiary_employee_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('beneficiary_provider_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);

            // Accounting fields
            $table->addColumn('accrued', 'boolean', [
                'null' => false,
                'default' => false,
            ]);
            $table->addColumn('accrual_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('ready_for_payment', 'string', [
                'limit' => 40,
                'null' => true,
                'default' => null,
            ]);

            // Payment fields
            $table->addColumn('banking_entity_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_created_by', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $table->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_status', 'string', [
                'limit' => 40,
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('payment_rejection_reason', 'text', [
                'null' => true,
                'default' => null,
            ]);

            // Audit
            $table->addColumn('created_by', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $table->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ]);
            $table->addColumn('modified', 'datetime', [
                'null' => true,
                'default' => null,
            ]);

            $table->addIndex(['code'], ['unique' => true]);
            $table->addIndex(['status']);
            $table->addIndex(['beneficiary_employee_id']);
            $table->addIndex(['beneficiary_provider_id']);

            $table->addForeignKey('beneficiary_employee_id', 'employees', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('beneficiary_provider_id', 'providers', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('payment_created_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);
            $table->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);

            $table->create();
        }

        // refund_observations
        if (!$this->hasTable('refund_observations')) {
            $obs = $this->table('refund_observations');

            $obs->addColumn('refund_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('user_id', 'integer', [
                'null' => false,
                'signed' => true,
            ]);
            $obs->addColumn('type', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'general',
            ]);
            $obs->addColumn('message', 'text', [
                'null' => false,
            ]);
            $obs->addColumn('metadata', 'json', [
                'null' => true,
                'default' => null,
            ]);
            $obs->addColumn('created', 'datetime', [
                'null' => true,
                'default' => null,
            ]);

            $obs->addForeignKey('refund_id', 'refunds', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ]);
            $obs->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ]);

            $obs->create();
        }

        // invoices.refund_id
        if ($this->hasTable('invoices') && !$this->hasColumn('invoices', 'refund_id')) {
            $invoices = $this->table('invoices');
            $invoices->addColumn('refund_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $invoices->addForeignKey('refund_id', 'refunds', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ]);
            $invoices->update();
        }

        // invoice_payments.refund_id
        if ($this->hasTable('invoice_payments') && !$this->hasColumn('invoice_payments', 'refund_id')) {
            $payments = $this->table('invoice_payments');
            $payments->addColumn('refund_id', 'integer', [
                'null' => true,
                'default' => null,
                'signed' => true,
            ]);
            $payments->addForeignKey('refund_id', 'refunds', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
            ]);
            $payments->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('invoice_payments') && $this->hasColumn('invoice_payments', 'refund_id')) {
            $payments = $this->table('invoice_payments');
            $payments->dropForeignKey('refund_id');
            $payments->removeColumn('refund_id');
            $payments->update();
        }

        if ($this->hasTable('invoices') && $this->hasColumn('invoices', 'refund_id')) {
            $invoices = $this->table('invoices');
            $invoices->dropForeignKey('refund_id');
            $invoices->removeColumn('refund_id');
            $invoices->update();
        }

        if ($this->hasTable('refund_observations')) {
            $this->table('refund_observations')->drop()->save();
        }

        if ($this->hasTable('refunds')) {
            $this->table('refunds')->drop()->save();
        }
    }
}
```

> Nota: `addColumn('metadata', 'json', ...)` requiere MariaDB 10.2+/MySQL 5.7+ (igual que ya se usa en `petty_cash_observations`/`invoice_observations` tras los planes anteriores). Si la migración falla por tipo `json`, el proyecto ya tiene precedente con la migración `AddTypeAndMetadataToPettyCashObservations` (2026-04-30) — usar el mismo enfoque.

- [ ] **Step 3: Correr la migración**

```bash
php bin/cake migrations migrate
```

Esperado: `CreateRefunds` aparece en la lista con timestamp y status `migrated`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(plan-8): migración refunds + refund_observations + columnas refund_id"
```

---

## Task 2: Migración — sembrar permisos del módulo `refunds`

**Files:**
- Create: `config/Migrations/<timestamp>_SeedRefundsPermissions.php`

- [ ] **Step 1: Generar el archivo**

```bash
php bin/cake migrations create SeedRefundsPermissions
```

- [ ] **Step 2: Reemplazar el contenido**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedRefundsPermissions extends BaseMigration
{
    public function up(): void
    {
        $existing = $this->fetchAll("SELECT role_id FROM permissions WHERE module = 'refunds'");
        if (!empty($existing)) {
            // Idempotente: si ya hay filas (rerun parcial) no duplicar.
            return;
        }

        $roles = $this->fetchAll('SELECT id, name FROM roles');

        foreach ($roles as $role) {
            $isAdmin = $role['name'] === 'Administrador';

            $this->execute(sprintf(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete) "
                . "VALUES (%d, 'refunds', %d, %d, %d, %d)",
                $role['id'],
                1,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
                $isAdmin ? 1 : 0,
            ));
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'refunds'");
    }
}
```

- [ ] **Step 3: Correr la migración**

```bash
php bin/cake migrations migrate
```

Esperado: `SeedRefundsPermissions` migrado.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(plan-8): seed permisos del módulo refunds"
```

---

## Task 3: Constantes `RefundConstants`

**Files:**
- Create: `src/Constants/RefundConstants.php`

- [ ] **Step 1: Crear el archivo**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class RefundConstants
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUT_PAGO = 'aut_pago';
    public const STATUS_PAGADO = 'pagado';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUT_PAGO,
        self::STATUS_PAGADO,
    ];

    public const STATUS_LABELS = [
        'agrupacion' => 'Agrupación',
        'contabilidad' => 'Contabilidad',
        'tesoreria' => 'Tesorería',
        'aut_pago' => 'Aut. Pago',
        'pagado' => 'Pagado',
    ];

    public const STATUS_ICONS = [
        'agrupacion' => 'bi-collection',
        'contabilidad' => 'bi-calculator',
        'tesoreria' => 'bi-bank',
        'aut_pago' => 'bi-shield-check',
        'pagado' => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        'agrupacion' => 'contabilidad',
        'contabilidad' => 'tesoreria',
        'tesoreria' => 'aut_pago',
        'aut_pago' => 'pagado',
        'pagado' => null,
    ];

    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA => self::STATUS_CONTABILIDAD,
        self::STATUS_AUT_PAGO => self::STATUS_TESORERIA,
        self::STATUS_PAGADO => null,
    ];

    public const REGRESS_ROLE_BY_STATUS = [
        self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
        self::STATUS_TESORERIA => [RoleConstants::TESORERIA],
        self::STATUS_AUT_PAGO => [RoleConstants::CONTADOR],
    ];

    public const OBSERVATION_TYPE_GENERAL = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];

    public const CODE_PREFIX = 'REI';

    // Beneficiary types (alineado con InvoiceConstants::HOLDER_TYPE_*, sin manual)
    public const BENEFICIARY_TYPE_EMPLOYEE = 'employee';
    public const BENEFICIARY_TYPE_PROVIDER = 'provider';

    public const BENEFICIARY_TYPES = [
        self::BENEFICIARY_TYPE_EMPLOYEE,
        self::BENEFICIARY_TYPE_PROVIDER,
    ];

    public const BENEFICIARY_TYPES_LABELS = [
        self::BENEFICIARY_TYPE_EMPLOYEE => 'Empleado',
        self::BENEFICIARY_TYPE_PROVIDER => 'Proveedor',
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Constants/RefundConstants.php
git commit -m "feat(plan-8): constantes RefundConstants"
```

---

## Task 4: Entity `Refund` y Table `RefundsTable`

**Files:**
- Create: `src/Model/Entity/Refund.php`
- Create: `src/Model/Table/RefundsTable.php`

- [ ] **Step 1: Crear `src/Model/Entity/Refund.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\RefundConstants;
use Cake\ORM\Entity;

class Refund extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'status' => true,
        'total_amount' => true,
        'beneficiary_type' => true,
        'beneficiary_employee_id' => true,
        'beneficiary_provider_id' => true,
        'accrued' => true,
        'accrual_date' => true,
        'ready_for_payment' => true,
        'banking_entity_id' => true,
        'payment_amount' => true,
        'payment_date' => true,
        'payment_created_by' => true,
        'payment_authorized_by' => true,
        'payment_authorized_date' => true,
        'payment_status' => true,
        'payment_rejection_reason' => true,
        'created_by' => true,
        'created' => true,
        'modified' => true,
        'beneficiary_employee' => true,
        'beneficiary_provider' => true,
        'banking_entity' => true,
        'created_by_user' => true,
        'invoices' => true,
        'refund_observations' => true,
    ];

    public function isAgrupacion(): bool
    {
        return $this->status === RefundConstants::STATUS_AGRUPACION;
    }

    public function isPagado(): bool
    {
        return $this->status === RefundConstants::STATUS_PAGADO;
    }

    public function getBeneficiaryName(): ?string
    {
        if ($this->beneficiary_type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
            $emp = $this->beneficiary_employee ?? null;
            if ($emp === null) {
                return null;
            }

            return trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? ''));
        }

        if ($this->beneficiary_type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
            $prov = $this->beneficiary_provider ?? null;

            return $prov->name ?? null;
        }

        return null;
    }
}
```

- [ ] **Step 2: Crear `src/Model/Table/RefundsTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RefundConstants;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refunds');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BeneficiaryEmployees', [
            'className' => 'Employees',
            'foreignKey' => 'beneficiary_employee_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('BeneficiaryProviders', [
            'className' => 'Providers',
            'foreignKey' => 'beneficiary_provider_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentCreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_created_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentAuthorizedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_authorized_by',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('Invoices', [
            'foreignKey' => 'refund_id',
        ]);
        $this->hasMany('RefundObservations', [
            'foreignKey' => 'refund_id',
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
            ->inList('status', RefundConstants::STATUSES);

        $validator->decimal('total_amount');

        $validator
            ->scalar('beneficiary_type')
            ->inList('beneficiary_type', RefundConstants::BENEFICIARY_TYPES, 'Tipo de beneficiario inválido.')
            ->allowEmptyString('beneficiary_type');

        $validator->integer('beneficiary_employee_id')->allowEmptyString('beneficiary_employee_id');
        $validator->integer('beneficiary_provider_id')->allowEmptyString('beneficiary_provider_id');

        $validator->boolean('accrued');
        $validator->date('accrual_date')->allowEmptyDate('accrual_date');
        $validator->scalar('ready_for_payment')->allowEmptyString('ready_for_payment');

        $validator->integer('banking_entity_id')->allowEmptyString('banking_entity_id');
        $validator->decimal('payment_amount')->allowEmptyString('payment_amount');
        $validator->date('payment_date')->allowEmptyDate('payment_date');
        $validator->integer('payment_created_by')->allowEmptyString('payment_created_by');
        $validator->integer('payment_authorized_by')->allowEmptyString('payment_authorized_by');
        $validator->date('payment_authorized_date')->allowEmptyDate('payment_authorized_date');
        $validator->scalar('payment_status')->allowEmptyString('payment_status');
        $validator->scalar('payment_rejection_reason')->allowEmptyString('payment_rejection_reason');

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        // Beneficiary XOR rule: if type is set, exactly the matching FK must be set.
        $rules->add(function ($entity) {
            $type = $entity->beneficiary_type;
            if ($type === null || $type === '') {
                return true; // No beneficiary set yet (allowed in agrupacion).
            }
            if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
                return !empty($entity->beneficiary_employee_id) && empty($entity->beneficiary_provider_id);
            }
            if ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
                return !empty($entity->beneficiary_provider_id) && empty($entity->beneficiary_employee_id);
            }

            return false;
        }, 'beneficiaryConsistency', [
            'errorField' => 'beneficiary_type',
            'message' => 'El beneficiario seleccionado no coincide con el tipo.',
        ]);

        return $rules;
    }

    /**
     * Generate `code` (REI-YYYY-NNNN) on create when missing.
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$entity->isNew() || !empty($entity->code)) {
            return;
        }

        $year = (int)date('Y');
        $prefix = RefundConstants::CODE_PREFIX . '-' . $year . '-';

        $last = $this->find()
            ->select(['code'])
            ->where(['code LIKE' => $prefix . '%'])
            ->order(['code' => 'DESC'])
            ->first();

        $next = 1;
        if ($last !== null && preg_match('/-(\d+)$/', (string)$last->code, $m)) {
            $next = (int)$m[1] + 1;
        }

        $entity->code = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/Refund.php src/Model/Table/RefundsTable.php
git commit -m "feat(plan-8): entity Refund + RefundsTable con regla XOR de beneficiario"
```

---

## Task 5: Entity `RefundObservation` y Table `RefundObservationsTable`

**Files:**
- Create: `src/Model/Entity/RefundObservation.php`
- Create: `src/Model/Table/RefundObservationsTable.php`

- [ ] **Step 1: Crear `src/Model/Entity/RefundObservation.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RefundObservation extends Entity
{
    protected array $_accessible = [
        'refund_id' => true,
        'user_id' => true,
        'type' => true,
        'message' => true,
        'metadata' => true,
        'created' => true,
        'refund' => true,
        'user' => true,
    ];
}
```

- [ ] **Step 2: Crear `src/Model/Table/RefundObservationsTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RefundConstants;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refund_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);

        $this->belongsTo('Refunds', [
            'foreignKey' => 'refund_id',
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
            ->integer('refund_id')
            ->requirePresence('refund_id', 'create')
            ->notEmptyString('refund_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('type')
            ->inList('type', RefundConstants::OBSERVATION_TYPES)
            ->notEmptyString('type');

        $validator
            ->scalar('message')
            ->maxLength('message', 5000)
            ->requirePresence('message', 'create')
            ->notEmptyString('message');

        $validator->allowEmptyArray('metadata');

        return $validator;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Model/Entity/RefundObservation.php src/Model/Table/RefundObservationsTable.php
git commit -m "feat(plan-8): entity RefundObservation + table"
```

---

## Task 6: Asociaciones `belongsTo Refunds` en `InvoicesTable` e `InvoicePaymentsTable`

**Files:**
- Modify: `src/Model/Table/InvoicesTable.php`
- Modify: `src/Model/Table/InvoicePaymentsTable.php`

- [ ] **Step 1: Añadir asociación en `InvoicesTable`**

Abrir `src/Model/Table/InvoicesTable.php`. Localizar el método `initialize()` y la asociación existente `$this->belongsTo('PettyCashRecords', ...)`. Inmediatamente **después** de esa asociación, agregar:

```php
$this->belongsTo('Refunds', [
    'foreignKey' => 'refund_id',
    'joinType' => 'LEFT',
]);
```

- [ ] **Step 2: Añadir asociación en `InvoicePaymentsTable`**

Abrir `src/Model/Table/InvoicePaymentsTable.php`. Localizar `initialize()` y la asociación `$this->belongsTo('PettyCashRecords', ...)` (existe — agregada en migraciones previas). Inmediatamente **después** de ella agregar:

```php
$this->belongsTo('Refunds', [
    'foreignKey' => 'refund_id',
    'joinType' => 'LEFT',
]);
```

> Si `InvoicePaymentsTable` no tiene `belongsTo PettyCashRecords` (sólo asocia por columna), insertar el bloque al final del bloque de `belongsTo` existentes en `initialize()`.

- [ ] **Step 3: Commit**

```bash
git add src/Model/Table/InvoicesTable.php src/Model/Table/InvoicePaymentsTable.php
git commit -m "feat(plan-8): InvoicesTable + InvoicePaymentsTable belongsTo Refunds"
```

---

## Task 7: Servicio `RefundService`

`RefundService` es un clon estructural de `PettyCashService` con cambios mínimos. La estrategia es: copiar el archivo y aplicar los reemplazos descritos. Esto preserva fielmente la lógica de transiciones, regresión, registro/autorización/rechazo de pago, y bloqueos — toda la cual ya está validada en producción para PettyCash.

**Files:**
- Create: `src/Service/RefundService.php`

- [ ] **Step 1: Copiar el archivo fuente como base**

```bash
cp src/Service/PettyCashService.php src/Service/RefundService.php
```

- [ ] **Step 2: Aplicar los siguientes reemplazos globales en `src/Service/RefundService.php`**

> Hacer cada reemplazo **exactamente como aparece** (case-sensitive). Después de los reemplazos globales se aplican dos cambios localizados.

| Buscar | Reemplazar por |
|---|---|
| `PettyCashService` | `RefundService` |
| `PettyCashConstants` | `RefundConstants` |
| `PettyCashRecord` | `Refund` |
| `PettyCashRecords` | `Refunds` |
| `PettyCashObservations` | `RefundObservations` |
| `petty_cash_record_id` | `refund_id` |
| `petty_cash_observations` | `refund_observations` |
| `'Caja Menor'` | `'Reintegro'` |
| `DOCTYPE_CAJA_MENOR` | `DOCTYPE_REINTEGRO` |

Verificar tras los reemplazos:
- El `use` de `App\Constants\PettyCashConstants` quedó como `App\Constants\RefundConstants`. Si quedó duplicado o desordenado, mover al bloque `use` ordenado alfabéticamente.
- El `use App\Model\Entity\PettyCashRecord;` quedó como `App\Model\Entity\Refund;`.
- En el constructor, el bloque del `GroupedInvoiceService` quedó así (revisar):

```php
$this->grouped = new GroupedInvoiceService(
    documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
    fkField: 'refund_id',
    recordTableName: 'Refunds',
    fkLabel: 'Reintegro',
    historyService: $historyService,
);
```

- [ ] **Step 3: Localizar `_validateTransition` y añadir la regla del beneficiario**

Buscar el método privado `_validateTransition` y, en el `case PettyCashConstants::STATUS_AGRUPACION:` (que tras los reemplazos es `case RefundConstants::STATUS_AGRUPACION:`), reemplazar la línea `break;` por:

```php
$type = $record->beneficiary_type;
if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
    if (empty($record->beneficiary_employee_id)) {
        $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
    }
} elseif ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
    if (empty($record->beneficiary_provider_id)) {
        $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
    }
} else {
    $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
}
break;
```

- [ ] **Step 4: Localizar `authorizePayment` y añadir `refund_id` al payload de `invoice_payments`**

Buscar el bloque dentro de `authorizePayment` que crea cada `$invoicePayment = $invoicePaymentsTable->newEntity([...])`. Tras los reemplazos del Step 2 ya tendrá `'refund_id' => $record->id` (porque vino de `'petty_cash_record_id' => $record->id`). **Verificar** que la línea quedó así:

```php
'refund_id' => $record->id,
```

Si no, agregarla manualmente dentro del array `newEntity([...])` (antes de `'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED`).

- [ ] **Step 5: Verificar el header del archivo**

El doc-comment de la clase debería describir el servicio. Reemplazar cualquier referencia residual a "petty cash" / "caja menor" en doc-comments por "refund" / "reintegro".

- [ ] **Step 6: Commit**

```bash
git add src/Service/RefundService.php
git commit -m "feat(plan-8): RefundService (clon de PettyCashService + validación beneficiario)"
```

---

## Task 8: Wiring de DI, módulo de permisos y `controllerModuleMap`

**Files:**
- Modify: `src/Application.php`
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Registrar `RefundService` en el container DI**

Abrir `src/Application.php`. Localizar el método `services()` (donde se registran los servicios al container). Buscar la línea que registra `PettyCashService` (algo como `$container->add(PettyCashService::class)->...`). Inmediatamente después agregar:

```php
$container->add(RefundService::class)
    ->addArgument(InvoiceHistoryService::class);
```

> Si `PettyCashService` se registra con argumentos distintos (p. ej. otra interfaz), espejar exactamente la misma forma para `RefundService`. Ambos servicios tienen el mismo constructor (toman un `HistoryServiceInterface`).

Asegurarse de que el `use` esté:

```php
use App\Service\RefundService;
```

(en orden alfabético dentro del bloque de `use`).

- [ ] **Step 2: Agregar `refunds` a `AuthorizationService::MODULES`**

Abrir `src/Service/AuthorizationService.php`. Localizar la constante `MODULES` y agregar la entrada `'refunds' => 'Reintegros'`. Conservar el orden de las demás entradas; insertar de forma alfabética por slug o, si el orden actual es por dominio, ubicar `'refunds'` justo después de `'petty_cash'`. Ejemplo:

```php
'petty_cash' => 'Caja Menor',
'refunds' => 'Reintegros',
```

- [ ] **Step 3: Agregar `Refunds` al `controllerModuleMap` de `AppController`**

Abrir `src/Controller/AppController.php`. Localizar el array protegido `$controllerModuleMap`. Inmediatamente después de la entrada `'PettyCashRecords' => 'petty_cash',` agregar:

```php
'Refunds' => 'refunds',
```

- [ ] **Step 4: Commit**

```bash
git add src/Application.php src/Service/AuthorizationService.php src/Controller/AppController.php
git commit -m "feat(plan-8): DI + AuthorizationService::MODULES + controllerModuleMap para refunds"
```

---

## Task 9: Controller `RefundsController`

`RefundsController` es un clon estructural de `PettyCashRecordsController`. Aplicamos la misma estrategia que en Task 7.

**Files:**
- Create: `src/Controller/RefundsController.php`

- [ ] **Step 1: Copiar el archivo fuente como base**

```bash
cp src/Controller/PettyCashRecordsController.php src/Controller/RefundsController.php
```

- [ ] **Step 2: Aplicar los siguientes reemplazos globales**

| Buscar | Reemplazar por |
|---|---|
| `PettyCashRecordsController` | `RefundsController` |
| `PettyCashRecord` | `Refund` |
| `PettyCashRecords` | `Refunds` |
| `PettyCashObservations` | `RefundObservations` |
| `PettyCashService` | `RefundService` |
| `PettyCashConstants` | `RefundConstants` |
| `pettyCashService` | `refundService` |
| `petty_cash_record_id` | `refund_id` |
| `petty_cash_observations` | `refund_observations` |
| `'Caja menor'` | `'Reintegro'` |
| `'Caja Menor'` | `'Reintegro'` |
| `DOCTYPE_CAJA_MENOR` | `DOCTYPE_REINTEGRO` |

Verificar el `use` block: cualquier residuo de `App\Service\PettyCashService` o `App\Constants\PettyCashConstants` debe haber sido reemplazado por las clases de Refunds.

- [ ] **Step 3: Permitir setear el beneficiario en `add` y `edit`**

Buscar la acción `add()`. Tras `$record = $this->Refunds->newEmptyEntity();` (originalmente con otro nombre — usar el patch existente), localizar el bloque `$this->Refunds->patchEntity($record, $this->request->getData())` o equivalente. Asegurar que los campos `beneficiary_type`, `beneficiary_employee_id`, `beneficiary_provider_id` están en `$_accessible` (ya sí, en Task 4) y que `add` no los excluye con `accessibleFields:`.

En `edit()`, dentro del manejo de POST, **bloquear cambios de beneficiario fuera de `agrupacion`**. Justo antes de `$this->Refunds->patchEntity($record, $this->request->getData(), [...])`, agregar:

```php
if ($record->status !== RefundConstants::STATUS_AGRUPACION) {
    $data = $this->request->getData();
    unset($data['beneficiary_type'], $data['beneficiary_employee_id'], $data['beneficiary_provider_id']);
    $this->request = $this->request->withParsedBody($data);
}
```

> Si la firma del `patchEntity` ya recibe explícitamente los campos por whitelist, alternativamente quitar esos tres del array `accessibleFields:`. El plan escoge el filtro por `withParsedBody` porque es local al `if` y no afecta a `add()`.

- [ ] **Step 4: Verificar `index` filtra por estados visibles del rol**

La acción `index()` heredada del clon ya llama a `$this->refundService->getVisibleStatuses($roleName)` (porque `pettyCashService->getVisibleStatuses` existe). No requiere cambios.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/RefundsController.php
git commit -m "feat(plan-8): RefundsController (clon de PettyCashRecordsController + bloqueo edit beneficiario)"
```

---

## Task 10: Rutas

**Files:**
- Modify: `config/routes.php`

- [ ] **Step 1: Localizar el bloque de rutas de PettyCash**

Abrir `config/routes.php`. Buscar el scope o bloque que define las rutas custom de `PettyCashRecords` (`addInvoices`, `removeInvoice`, `advanceStatus`, `regressStatus`, `registerPayment`, `authorizePayment`, `rejectPayment`, etc.). Tomarlo como referencia de estilo.

- [ ] **Step 2: Insertar el bloque de rutas para Refunds**

Inmediatamente **después** del bloque de PettyCash, **antes** de `$builder->fallbacks();`, agregar:

```php
// Refunds (Reintegros) — rutas custom (POST) para acciones del pipeline
$builder->connect(
    '/refunds/{id}/advance',
    ['controller' => 'Refunds', 'action' => 'advance'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/regress',
    ['controller' => 'Refunds', 'action' => 'regress'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/add-invoices',
    ['controller' => 'Refunds', 'action' => 'addInvoices'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/remove-invoice/{invoiceId}',
    ['controller' => 'Refunds', 'action' => 'removeInvoice'],
    ['id' => '\d+', 'invoiceId' => '\d+', 'pass' => ['id', 'invoiceId'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/register-payment',
    ['controller' => 'Refunds', 'action' => 'registerPayment'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/authorize-payment',
    ['controller' => 'Refunds', 'action' => 'authorizePayment'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/reject-payment',
    ['controller' => 'Refunds', 'action' => 'rejectPayment'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
$builder->connect(
    '/refunds/{id}/add-observation',
    ['controller' => 'Refunds', 'action' => 'addObservation'],
    ['id' => '\d+', 'pass' => ['id'], '_method' => 'POST'],
);
```

> Si el bloque actual de PettyCash usa `$builder->scope(...)` con sintaxis distinta (closure), espejar **exactamente** el mismo estilo para Refunds. Lo importante es que las rutas queden antes de `$builder->fallbacks()` y que las acciones del controller cubiertas coincidan con las del controller clonado. Si el controller clonado define alguna acción adicional (p. ej. `uploadDocument`, `deleteDocument`) y el módulo Refunds no requiere documentos en este plan, **eliminar esas acciones del controller** (Task 9, paso adicional) o **dejarlas inactivas sin ruta**. Por simplicidad de este plan: si el clon trajo `uploadDocument`/`deleteDocument`, **borrarlas del controller** ya que Refunds no maneja documentos del padre (la spec § 8 no incluye esa sección).

- [ ] **Step 3: Si el clon del controller trajo acciones de documentos, eliminarlas**

Abrir `src/Controller/RefundsController.php`. Si están presentes `uploadDocument()` y `deleteDocument()` (heredados del clon de PettyCash), eliminar ambas acciones completas. También eliminar cualquier `use App\Service\PettyCashDocumentService` residual o cualquier referencia a `RefundDocumentService` que no se haya creado.

> Documentos del padre (no de las facturas hijas) están fuera del alcance de este plan. Las facturas hijas siguen teniendo sus propios `InvoiceDocuments`, sin cambios.

- [ ] **Step 4: Commit**

```bash
git add config/routes.php src/Controller/RefundsController.php
git commit -m "feat(plan-8): rutas /refunds y limpieza de acciones de documentos del controller"
```

---

## Task 11: Element `refund_progress.php` y template `index.php`

**Files:**
- Create: `templates/element/refund_progress.php`
- Create: `templates/Refunds/index.php`

- [ ] **Step 1: Crear `templates/element/refund_progress.php`**

```bash
cp templates/element/petty_cash_progress.php templates/element/refund_progress.php
```

Aplicar reemplazos en `templates/element/refund_progress.php`:

| Buscar | Reemplazar por |
|---|---|
| `PettyCashConstants` | `RefundConstants` |
| `Caja Menor` (en cualquier label visible) | `Reintegro` |

Verificar el `<?php use ...` del header — debe quedar `use App\Constants\RefundConstants;` (no `PettyCashConstants`).

- [ ] **Step 2: Crear `templates/Refunds/index.php`**

```bash
cp templates/PettyCashRecords/index.php templates/Refunds/index.php
```

Aplicar reemplazos en `templates/Refunds/index.php`:

| Buscar | Reemplazar por |
|---|---|
| `PettyCashRecord` | `Refund` |
| `PettyCashRecords` | `Refunds` |
| `PettyCashConstants` | `RefundConstants` |
| `Caja Menor` | `Reintegro` |
| `Cajas Menores` | `Reintegros` |
| `petty-cash` (en URLs hardcoded) | `refunds` |
| `pettyCash` (en variables de view) | `refund` |

Después de los reemplazos, **agregar columna de beneficiario** en la tabla:

Localizar el `<thead>` del listado. En la fila de headers, añadir después del header del código una nueva celda:

```html
<th>Beneficiario</th>
```

Localizar el `<tbody>` (foreach que renderiza cada fila). Añadir, en la posición correspondiente al header nuevo, una celda que llame al helper del entity:

```php
<td><?= h($record->getBeneficiaryName() ?? '—') ?></td>
```

> Si `index.php` original muestra `notes` u otro campo que no aplica a Refunds (Refunds no tiene `notes`), eliminar esa columna (header + celda) tras los reemplazos.

- [ ] **Step 3: Commit**

```bash
git add templates/element/refund_progress.php templates/Refunds/index.php
git commit -m "feat(plan-8): element refund_progress + template Refunds/index"
```

---

## Task 12: Templates `add.php`, `edit.php`, `view.php`

**Files:**
- Create: `templates/Refunds/add.php`
- Create: `templates/Refunds/edit.php`
- Create: `templates/Refunds/view.php`

- [ ] **Step 1: Copiar los tres archivos**

```bash
cp templates/PettyCashRecords/add.php templates/Refunds/add.php
cp templates/PettyCashRecords/edit.php templates/Refunds/edit.php
cp templates/PettyCashRecords/view.php templates/Refunds/view.php
```

- [ ] **Step 2: Aplicar los reemplazos globales en los tres archivos**

| Buscar | Reemplazar por |
|---|---|
| `PettyCashRecord` | `Refund` |
| `PettyCashRecords` | `Refunds` |
| `PettyCashConstants` | `RefundConstants` |
| `Caja Menor` | `Reintegro` |
| `petty-cash` | `refunds` |
| `petty_cash_record_id` | `refund_id` |
| `pettyCashService` | `refundService` |
| `petty_cash_progress` | `refund_progress` |
| `pettyCash` (variables view) | `refund` |

- [ ] **Step 3: Eliminar secciones que no aplican a Refunds**

En los tres templates:
- Eliminar bloques relacionados con `notes` del padre (Refunds no tiene `notes`).
- Eliminar bloques relacionados con `petty_cash_documents` / "Documentos del registro" (Refunds no maneja documentos del padre — solo las facturas hijas tienen sus propios `invoice_documents` y se editan desde el módulo Invoices).
- Eliminar referencias a `uploadDocument` / `deleteDocument`.

- [ ] **Step 4: Añadir sección de beneficiario en `add.php`**

`add.php` debería ser un formulario corto: solo el beneficiario. Tras los reemplazos, **reemplazar todo el bloque del `Form->create(...)` y `Form->end()`** por:

```php
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card sgi-card">
                <div class="card-header">
                    <h4 class="mb-0">Nuevo Reintegro</h4>
                </div>
                <div class="card-body">
                    <?= $this->Form->create($record) ?>

                    <div class="mb-3">
                        <label class="form-label">Tipo de beneficiario <span class="text-danger">*</span></label>
                        <div>
                            <div class="form-check form-check-inline">
                                <?= $this->Form->radio(
                                    'beneficiary_type',
                                    [['value' => 'employee', 'text' => 'Empleado']],
                                    ['hiddenField' => false, 'class' => 'form-check-input', 'data-target' => 'employee']
                                ) ?>
                            </div>
                            <div class="form-check form-check-inline">
                                <?= $this->Form->radio(
                                    'beneficiary_type',
                                    [['value' => 'provider', 'text' => 'Proveedor']],
                                    ['hiddenField' => false, 'class' => 'form-check-input', 'data-target' => 'provider']
                                ) ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 sgi-beneficiary-employee" style="display:none;">
                        <?= $this->Form->control('beneficiary_employee_id', [
                            'label' => 'Empleado',
                            'options' => $employees ?? [],
                            'empty' => 'Seleccione un empleado',
                            'class' => 'form-select select2',
                        ]) ?>
                    </div>

                    <div class="mb-3 sgi-beneficiary-provider" style="display:none;">
                        <?= $this->Form->control('beneficiary_provider_id', [
                            'label' => 'Proveedor',
                            'options' => $providers ?? [],
                            'empty' => 'Seleccione un proveedor',
                            'class' => 'form-select select2',
                        ]) ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
                        <?= $this->Form->button('Crear', ['class' => 'sgi-btn-primary btn']) ?>
                    </div>

                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('input[name="beneficiary_type"]');
    var empBlock = document.querySelector('.sgi-beneficiary-employee');
    var provBlock = document.querySelector('.sgi-beneficiary-provider');
    var empSelect = empBlock.querySelector('select');
    var provSelect = provBlock.querySelector('select');

    function sync() {
        var checked = document.querySelector('input[name="beneficiary_type"]:checked');
        var val = checked ? checked.value : null;
        empBlock.style.display = val === 'employee' ? '' : 'none';
        provBlock.style.display = val === 'provider' ? '' : 'none';
        if (val !== 'employee') { empSelect.value = ''; }
        if (val !== 'provider') { provSelect.value = ''; }
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
});
</script>
```

> El controller debe pasar `$employees` y `$providers` como `$this->set('employees', $employeesTable->find('list')->all()->toArray())` (ajustar para Refunds en `add()`). Si el clon ya las pasa con otro nombre (p. ej. `$employeesList`), espejarlo.

- [ ] **Step 5: Añadir/preservar sección de beneficiario en `edit.php` (read-only fuera de `agrupacion`)**

En `edit.php`, agregar un bloque de **información del beneficiario** al inicio del formulario, antes de la sección de facturas agrupadas:

```php
<div class="card sgi-card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Beneficiario</h5>
    </div>
    <div class="card-body">
        <?php if ($record->status === RefundConstants::STATUS_AGRUPACION) : ?>
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Tipo</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <?= $this->Form->radio('beneficiary_type', [['value' => 'employee', 'text' => 'Empleado']], ['hiddenField' => false, 'class' => 'form-check-input']) ?>
                        </div>
                        <div class="form-check form-check-inline">
                            <?= $this->Form->radio('beneficiary_type', [['value' => 'provider', 'text' => 'Proveedor']], ['hiddenField' => false, 'class' => 'form-check-input']) ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-12 mb-3 sgi-beneficiary-employee" <?= $record->beneficiary_type === 'employee' ? '' : 'style="display:none;"' ?>>
                    <?= $this->Form->control('beneficiary_employee_id', [
                        'label' => 'Empleado',
                        'options' => $employees ?? [],
                        'empty' => 'Seleccione un empleado',
                        'class' => 'form-select select2',
                    ]) ?>
                </div>
                <div class="col-md-12 mb-3 sgi-beneficiary-provider" <?= $record->beneficiary_type === 'provider' ? '' : 'style="display:none;"' ?>>
                    <?= $this->Form->control('beneficiary_provider_id', [
                        'label' => 'Proveedor',
                        'options' => $providers ?? [],
                        'empty' => 'Seleccione un proveedor',
                        'class' => 'form-select select2',
                    ]) ?>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var radios = document.querySelectorAll('input[name="beneficiary_type"]');
                var empBlock = document.querySelector('.sgi-beneficiary-employee');
                var provBlock = document.querySelector('.sgi-beneficiary-provider');
                function sync() {
                    var checked = document.querySelector('input[name="beneficiary_type"]:checked');
                    var val = checked ? checked.value : null;
                    if (empBlock) empBlock.style.display = val === 'employee' ? '' : 'none';
                    if (provBlock) provBlock.style.display = val === 'provider' ? '' : 'none';
                }
                radios.forEach(function (r) { r.addEventListener('change', sync); });
            });
            </script>
        <?php else : ?>
            <dl class="row mb-0">
                <dt class="col-sm-3">Tipo</dt>
                <dd class="col-sm-9"><?= h(RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? ucfirst((string)$record->beneficiary_type)) ?></dd>
                <dt class="col-sm-3">Beneficiario</dt>
                <dd class="col-sm-9"><?= h($record->getBeneficiaryName() ?? '—') ?></dd>
            </dl>
        <?php endif; ?>
    </div>
</div>
```

Asegurarse de tener `use App\Constants\RefundConstants;` al tope del template `edit.php`. La constante `BENEFICIARY_TYPES_LABELS` ya quedó definida en Task 3.

- [ ] **Step 6: Mostrar beneficiario en `view.php`**

En `view.php`, agregar (después del bloque de progress y antes de la lista de facturas agrupadas):

```php
<div class="card sgi-card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Beneficiario</h5>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Tipo</dt>
            <dd class="col-sm-9"><?= h(RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? '—') ?></dd>
            <dt class="col-sm-3">Beneficiario</dt>
            <dd class="col-sm-9"><?= h($record->getBeneficiaryName() ?? '—') ?></dd>
        </dl>
    </div>
</div>
```

Asegurarse de `use App\Constants\RefundConstants;`.

- [ ] **Step 7: Pasar `$employees` y `$providers` desde el controller a `add` y `edit`**

Abrir `src/Controller/RefundsController.php`. En la acción `add()`, antes del `$this->set(...)` final, agregar:

```php
$employeesTable = $this->fetchTable('Employees');
$providersTable = $this->fetchTable('Providers');
$employees = $employeesTable->find('list', keyField: 'id', valueField: function ($e) {
    return trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? ''));
})->order(['first_name' => 'ASC'])->toArray();
$providers = $providersTable->find('list', keyField: 'id', valueField: 'name')
    ->order(['name' => 'ASC'])->toArray();
$this->set(compact('employees', 'providers'));
```

> Si `Employees` no tiene columnas `first_name`/`last_name` (ajustar según el modelo real — el proyecto puede usar `full_name` o similar), espejar el patrón usado en otros templates donde se selecciona un empleado (p. ej. en Anticipos / EmployeeNovelties).

Repetir el mismo bloque al inicio de `edit()` (antes del render).

- [ ] **Step 8: Commit**

```bash
git add templates/Refunds/ src/Controller/RefundsController.php
git commit -m "feat(plan-8): templates Refunds (add/edit/view) con beneficiario"
```

---

## Task 13: Sidebar nav-link y `SidebarCounterService::getRefundsPendingCount`

**Files:**
- Modify: `src/Service/SidebarCounterService.php`
- Modify: `templates/layout/default.php`

- [ ] **Step 1: Añadir el contador en `SidebarCounterService`**

Abrir `src/Service/SidebarCounterService.php`. Localizar el método que construye los counters (`_buildCounters` o equivalente) y la entrada para `petty_cash`. Añadir, inmediatamente después, una entrada análoga para `refunds`. Por ejemplo, si la lógica actual es:

```php
$counters['petty_cash'] = $this->_pettyCashPending($visibleStatuses);
```

Añadir:

```php
$counters['refunds'] = $this->_refundsPending($visibleStatuses);
```

Y, al final de la clase (después del método `_pettyCashPending` o similar), agregar:

```php
private function _refundsPending(array $visibleStatuses): int
{
    if (empty($visibleStatuses)) {
        return 0;
    }

    $refunds = TableRegistry::getTableLocator()->get('Refunds');

    return (int)$refunds->find()
        ->where(['Refunds.status IN' => $visibleStatuses])
        ->count();
}
```

> Si la implementación actual de `SidebarCounterService` resuelve los visible-statuses por rol vía `RefundService::getVisibleStatuses($role)`, entonces el helper debe llamar a `$this->refundService->getVisibleStatuses($role)`. **Inspeccionar `_pettyCashPending` y replicar exactamente la misma forma** — el método debe espejar la firma y dependencias.
>
> Si `SidebarCounterService` recibe `PettyCashService` por DI, agregar `RefundService` también (modificar el constructor + registrar en `Application.php` si aplica).

- [ ] **Step 2: Añadir nav-link en `templates/layout/default.php`**

Localizar el bloque del nav-link de PettyCash (alrededor de la línea 163, busque `if ($canView('petty_cash'))`). Inmediatamente **después** del bloque de cierre `endif;` de PettyCash, agregar un bloque paralelo para Refunds:

```php
<?php if ($canView('refunds')) : ?>
    <?php $refundsActive = $currentController === 'Refunds'; ?>
    <li class="nav-item">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-counterclockwise me-2"></i><span>Reintegros</span>'
            . ($counters['refunds'] ?? 0 > 0
                ? ' <span class="badge bg-warning text-dark ms-auto">' . (int)($counters['refunds'] ?? 0) . '</span>'
                : ''),
            ['controller' => 'Refunds', 'action' => 'index'],
            ['class' => $navLink('Refunds', 'index') . ' d-flex align-items-center', 'escape' => false],
        ) ?>
    </li>
<?php endif; ?>
```

> El estilo exacto del badge (clases CSS, posición) debe espejar el del nav-link de Caja Menor — copiar literalmente y solo cambiar el texto, el ícono, la clave del counter y la ruta.

- [ ] **Step 3: Commit**

```bash
git add src/Service/SidebarCounterService.php templates/layout/default.php
git commit -m "feat(plan-8): sidebar nav-link + counter de Reintegros"
```

---

## Task 14: Cierre — `cs-fix`, `cs-check`, validación manual

**Files:**
- Modify: ninguno (solo correcciones automáticas de estilo).

- [ ] **Step 1: Correr `cs-fix` sobre todo el código nuevo y modificado**

```bash
composer cs-fix
```

Verificar la salida: si `cs-fix` cambió archivos, son normalmente espacios y orden de imports.

- [ ] **Step 2: Verificar `cs-check`**

```bash
composer cs-check
```

Esperado: sin errores. Si los hay, arreglarlos manualmente y volver a correr `cs-check`.

- [ ] **Step 3: Commit del cierre**

```bash
git add -u
git commit -m "chore(plan-8): cierre del Plan 8 (módulo Reintegros)" --allow-empty
```

> Si `cs-fix` no cambió nada, `--allow-empty` deja un commit marcador del cierre del plan (espejando el estilo de los planes 6 y 7).

- [ ] **Step 4: Validación manual final (la ejecuta el usuario)**

> Los pasos abajo los corre el usuario manualmente. **No se ejecutan desde aquí.** Levantar el servidor con `php bin/cake server` y recorrer:

**1. Migración limpia**
- `ls config/Migrations/ | grep Refund` ⇒ aparecen `<timestamp>_CreateRefunds.php` y `<timestamp>_SeedRefundsPermissions.php`.
- En la base de datos: `SHOW TABLES LIKE '%refund%'` ⇒ `refunds`, `refund_observations`. `DESCRIBE invoices` ⇒ columna `refund_id`. `DESCRIBE invoice_payments` ⇒ columna `refund_id`. `SELECT * FROM permissions WHERE module = 'refunds'` ⇒ una fila por rol, `Administrador` con todos los flags en 1, los demás solo `can_view=1`.

**2. Crear factura individual con `document_type=Reintegro`**
- Como Registro/Revisión: `/invoices/add` → `document_type=Reintegro`, completar campos mínimos. Llevarla por el flujo normal hasta `pipeline_status=contabilidad`.

**3. Crear Reintegro con beneficiario empleado**
- Como Registro/Revisión: `/refunds/add` → seleccionar tipo `Empleado`, escoger empleado, guardar.
- Verificar redirección a `/refunds/{id}/edit`. El beneficiario aparece en el header.
- Agregar la factura del paso 2 (selector de facturas disponibles) → aparece en la tabla, `total_amount` se recalcula.
- Click "Quitar" en la factura → desaparece, `total_amount` vuelve a 0. Agregarla de nuevo.
- Quitar el beneficiario (cambiar a otro tipo sin seleccionar) → guardar, intentar avanzar → debe fallar con mensaje "Debe seleccionar un beneficiario antes de avanzar.".
- Re-seleccionar beneficiario, avanzar → `status=contabilidad`. Verificar que la factura hija pasó a `pipeline_status=contabilidad`.

**4. Contabilidad**
- Login como Contabilidad. Ver el reintegro en su listado.
- Marcar `accrued`, `accrual_date`, `ready_for_payment`. Avanzar → `status=tesoreria`. Hijas ⇒ `pipeline_status=tesoreria`.
- Verificar que el beneficiario aparece read-only (sin radios/select).

**5. Tesorería — registrar pago**
- Login como Tesorería. Abrir el reintegro.
- Registrar pago con entidad bancaria, monto, fecha → `status=aut_pago`. Hijas no cambian.
- Intentar regresar a `contabilidad` → bloqueo con mensaje "existe un pago pendiente".

**6. Contador — rechazar y luego autorizar**
- Login como Contador. Ver el reintegro en `aut_pago`.
- Rechazar con motivo (≥10 caracteres) → vuelve a `tesoreria`, `payment_rejection_reason` poblado, los campos de `banking_entity_id`/`payment_amount`/`payment_date` quedan vacíos.
- Login como Tesorería de nuevo. Re-registrar pago.
- Login como Contador. Autorizar → `status=pagado`. Verificar:
  - `SELECT * FROM invoice_payments WHERE refund_id = <id>` ⇒ una fila por hija, `status=authorized`, `authorized=1`, `authorized_by` poblado, `refund_id` poblado.
  - Hijas en estado `pagada`, `payment_status='Pago total'`.

**7. Regresión con motivo**
- Crear un nuevo Reintegro y avanzar hasta `contabilidad`. Como Contabilidad, regresar con motivo (≥10 caracteres) → vuelve a `agrupacion`. Hijas vuelven a `pipeline_status=contabilidad` (o lo que aplique al `childPipelineMap`). Verificar `refund_observations` → fila `type=regression`, `metadata={"from_status":"contabilidad","to_status":"agrupacion"}`.

**8. Repetir flujo principal con beneficiario `provider`**
- Mismos pasos 3–6 pero con tipo `Proveedor`, escogiendo un proveedor.

**9. Permisos por rol**
- Loguear como Contador → `/refunds` muestra solo registros en `aut_pago`. No ve `agrupacion`/`contabilidad`.
- Loguear como Contabilidad → solo `contabilidad`.
- Loguear como Registro/Revisión → solo `agrupacion`.
- Loguear como Administrador → ve todos.

**10. Sidebar counter**
- Login como Tesorería con un reintegro en `tesoreria` y otro en `aut_pago` → badge muestra `2`.
- Marcar uno como pagado → badge baja a `1` (esperar TTL del cache de sidebar si está activo, ver `config/app.php` → engine `sidebar` con TTL 30s).

**11. Borrado**
- Crear reintegro vacío en `agrupacion` → `delete` funciona.
- Crear otro y avanzarlo a `contabilidad` → `delete` debe fallar.

**12. Style**
- `composer cs-check` ⇒ pasa.

---

## Self-Review (post-plan)

Verificación interna del plan vs spec (`docs/superpowers/specs/2026-05-02-refunds-design.md`):

- ✅ **§ 3 Arquitectura — Archivos nuevos:** Tasks 1, 2 (migraciones), 3 (constants), 4–5 (modelos), 7 (service), 9 (controller), 11–12 (templates).
- ✅ **§ 3 Arquitectura — Archivos modificados:** Tasks 6 (asociaciones Tables), 8 (DI + Auth + AppController), 13 (Sidebar + nav-link). Routes (Task 10).
- ✅ **§ 4.1 Tabla `refunds` (todas las columnas e índices, FKs):** Task 1.
- ✅ **§ 4.2 Tabla `refund_observations`:** Task 1.
- ✅ **§ 4.3 `refund_id` en `invoices` e `invoice_payments`:** Task 1.
- ✅ **§ 4.4 Asociaciones de `RefundsTable`:** Task 4. **Asociaciones en `InvoicesTable`/`InvoicePaymentsTable`:** Task 6.
- ✅ **§ 4.1 Validación XOR del beneficiario (`buildRules`):** Task 4 step 2.
- ✅ **§ 5 Constantes (incluyendo BENEFICIARY_TYPES + LABELS):** Task 3.
- ✅ **§ 6 Service (todos los métodos públicos + reglas de transición + materialización de pagos + regresión):** Task 7 (clon de PettyCashService) + diff explícita para validación de beneficiario y `refund_id` en invoice_payments.
- ✅ **§ 6 Edición del beneficiario solo en `agrupacion`:** Task 9 step 3 (controller block) + Task 12 step 5 (template branch).
- ✅ **§ 6 Borrado solo en `agrupacion`:** heredado del clon (`canDelete()` retorna `$record->isAgrupacion()`).
- ✅ **§ 7 Controller + acciones:** Task 9 (clon).
- ✅ **§ 7 Rutas:** Task 10. Acciones de documentos eliminadas porque la spec § 8 no las incluye.
- ✅ **§ 7 Permisos (slug + MODULES + controllerModuleMap):** Task 8. Seed: Task 2.
- ✅ **§ 7 Sidebar (nav-link + counter):** Task 13.
- ✅ **§ 8 Templates (index/add/edit/view + element progress):** Tasks 11 (index + element), 12 (add/edit/view) con beneficiario polimórfico.
- ✅ **§ 9 Validación manual (12 puntos):** Task 14 step 4.
- ✅ **§ 10 Riesgo "conflicto semántico is_refund":** documentado en spec; el código nuevo usa `refund_id` (FK al padre) que es semánticamente distinto. No se requiere acción adicional en el plan.
- ✅ **§ 10 Numeración `code` REI-YYYY-NNNN:** Task 4 (`beforeSave` en `RefundsTable`).
- ✅ **§ 10 Migración idempotente:** Task 1 usa `hasTable()`/`hasColumn()`.

**Sin placeholders TBD/TODO.** Las únicas instrucciones del estilo "espejar el patrón existente" se acompañan de la referencia exacta al archivo y método fuente, y todos los reemplazos globales son listas explícitas.

**Tipos consistentes.**
- `RefundService` usa `Refund` (entity) en todas las firmas — Task 7 lo asegura vía reemplazo global `PettyCashRecord → Refund`.
- `refund_id` es la única forma de la FK — Task 1 (migración), Task 4 (table associations), Task 6 (Invoices/InvoicePayments associations), Task 7 (service).
- `RefundConstants::BENEFICIARY_TYPE_*` se usa en model, service, controller y templates — Task 3 lo define, Task 4 lo valida, Task 12 lo renderiza.

**Convenciones del proyecto aplicadas:**
- Pagination 15 (heredado del clon).
- `.sgi-*` CSS classes (heredado + nuevos `.sgi-beneficiary-*`).
- `TableRegistry::getTableLocator()->get(...)` en services (heredado del clon).
- `ServiceResult::ok/fail` (heredado).
- Métodos privados con `_` prefix (heredado).
- Constructor con DI nullable + fallback (heredado).
- Migraciones con `BaseMigration` no `AbstractMigration` (Tasks 1, 2).

**No-tests policy:** ninguna tarea introduce archivos en `tests/`, fixtures, ni pasos de PHPUnit. La validación end-to-end del Task 14 step 4 reemplaza la sección "Testing" del template estándar de plans.
