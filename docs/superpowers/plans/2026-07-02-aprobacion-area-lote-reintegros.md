# Aprobación de área en lote — Reintegros — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir un estado `aprobacion` al pipeline de Reintegros donde las facturas vinculadas (en estado `aprobacion`) se aprueban en lote por aprobadores del grupo (multi-aprobador, un link por aprobador, todo-o-nada), y al aprobarse todos avanzan juntas a `contabilidad`.

**Architecture:** El reintegro gana un estado `aprobacion` entre `agrupacion` y `contabilidad`. Una tabla nueva `refund_approvals` (espejo de `invoice_approvals`) y un servicio `RefundApprovalService` (sobre una base abstracta `GroupApprovalService`) gestionan el multi-aprobador. El aprobador aprueba/rechaza vía el `ExternalApprovalsController` existente (extendido con un tercer path de token). Al aprobar todos → `area_approval=Aprobada` en cada factura; al rechazar → el reintegro regresa a `agrupacion`.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB, PHPUnit + **cakephp-fixture-factories**, Migrations (`BaseMigration`).

**Scope:** Solo Reintegros (incluye la base compartida `GroupApprovalService`, reutilizable por Anticipos en un plan posterior). Anticipos/Legalización = plan aparte.

## Global Constraints

- CakePHP 5.3, PHP `>=8.4`. Migraciones extienden `Migrations\BaseMigration` (NUNCA `AbstractMigration`); usar `hasTable()`; FK con tipos exactos (`signed`).
- Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get('Name')`, nunca `$this->Name`.
- DI: los servicios que consumen otros servicios se registran en `src/Application.php::services()` con `$container->addShared(...)->addArgument(...)`. El patrón `?? new X()` en el constructor **solo** aplica a colaboradores SIN dependencias (p. ej. `RefundHistoryService`); **nunca** para `NotificationService` (tiene 3 dependencias no-nullable — `new NotificationService()` lanza `ArgumentCountError`).
- Servicios retornan `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`; verificar `->success`.
- Métodos privados con guion bajo: `_buildX()`.
- **TESTS = cakephp-fixture-factories.** El proyecto NO usa fixtures clásicos: `tests/Fixture/` no existe. Los tests con BD **no** declaran `$fixtures` (el rollback lo aplica `FactoryTransactionStrategy` global). Sembrar datos con `App\Test\Factory\{Refund,Invoice,User,Role,...}Factory::new([...])->save()` (patrón `withStatus()`, `withRequiredParents()`). Tests puros in-memory extienden `PHPUnit\Framework\TestCase`; tests con BD extienden `Cake\TestSuite\TestCase`.
- **Vocabulario de `status` de aprobación = español** (`InvoiceConstants::APPROVER_STATUS_PENDING='Pendiente'`, `APPROVER_STATUS_APPROVED='Aprobada'`, `APPROVER_STATUS_REJECTED='Rechazada'`, `APPROVER_STATUS_SUPERSEDED='Reemplazada'`). Reutilizar estas constantes; NO inventar valores en inglés.
- Slug de estado de pipeline en español sin acento: `aprobacion`.
- Mapeo estado→pill vive SOLO en `PipelineColorMap` (ya mapea `aprobacion` → `pill-warning-soft`); prohibido literal inline. `RefundPresentation::STATUS_BADGES` deriva de ahí (verificado por `PipelineColorConsistencyTest`).
- Tokens: secreto hex 64 chars (`ApprovalTokenManager::generateSecret()`), hasheado SHA256 en reposo (`token_hash`), TTL `InvoiceConstants::APPROVAL_TOKEN_HOURS`. Consumo con `SELECT … FOR UPDATE`.
- Correr suite: `vendor/bin/phpunit` (timeout 300s). Migraciones: `php bin/cake migrations migrate`. Estilo: `composer cs-check` / `composer cs-fix`.

**Orden de build (dependencias):** tabla+factory → guard → enum+state+registry → filtro → notificación → service+DI → coordinador → RBAC → controller → external → UI → integración.

---

### Task 1: Tabla `refund_approvals` (migración + entity + table + factory)

**Files:**
- Create: `config/Migrations/20260702100000_CreateRefundApprovals.php`
- Create: `src/Model/Entity/RefundApproval.php`
- Create: `src/Model/Table/RefundApprovalsTable.php`
- Create: `tests/Factory/RefundApprovalFactory.php`
- Test: `tests/TestCase/Model/Table/RefundApprovalsTableTest.php`

**Interfaces:**
- Produces: tabla `refund_approvals` (`refund_id`, `user_id`, `token_hash`, `token_expires_at`, `status`, `responded_at`, `observations`, `ip_address`, `user_agent`, `created`, `modified`); Entity `RefundApproval` (`token_hash` hidden); Table `RefundApprovalsTable` (`belongsTo Refunds, Users`); `RefundApprovalFactory`.

- [ ] **Step 1: Escribir la migración**

Create `config/Migrations/20260702100000_CreateRefundApprovals.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateRefundApprovals extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('refund_approvals')) {
            $table = $this->table('refund_approvals');
            $table
                ->addColumn('refund_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('user_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addColumn('token_expires_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'Pendiente', 'null' => false])
                ->addColumn('responded_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('observations', 'text', ['null' => true, 'default' => null])
                ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
                ->addColumn('user_agent', 'text', ['null' => true, 'default' => null])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_refund_approvals_token_hash'])
                ->addIndex(['refund_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('refund_id', 'refunds', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('refund_approvals')) {
            $this->table('refund_approvals')->drop()->save();
        }
    }
}
```

- [ ] **Step 2: Correr la migración**

Run: `php bin/cake migrations migrate`
Expected: crea `refund_approvals` sin error.

- [ ] **Step 3: Escribir Entity y Table**

Create `src/Model/Entity/RefundApproval.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RefundApproval extends Entity
{
    protected array $_accessible = [
        'refund_id' => true,
        'user_id' => true,
        'token_hash' => true,
        'token_expires_at' => true,
        'status' => true,
        'responded_at' => true,
        'observations' => true,
        'ip_address' => true,
        'user_agent' => true,
    ];

    protected array $_hidden = ['token_hash'];
}
```

Create `src/Model/Table/RefundApprovalsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class RefundApprovalsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('refund_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Refunds', ['foreignKey' => 'refund_id', 'joinType' => 'INNER']);
        $this->belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'INNER']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('refund_id')->requirePresence('refund_id', 'create')->notEmptyString('refund_id');
        $validator
            ->integer('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id');
        $validator
            ->scalar('status')->maxLength('status', 20)->notEmptyString('status');
        $validator
            ->scalar('token_hash')->maxLength('token_hash', 64)->allowEmptyString('token_hash');

        return $validator;
    }
}
```

- [ ] **Step 4: Escribir el factory** (patrón de `tests/Factory/RefundFactory.php`)

Create `tests/Factory/RefundApprovalFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de RefundApproval. El caller provee refund_id y user_id (FK NOT NULL).
 */
class RefundApprovalFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'RefundApprovals';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->setField('status', InvoiceConstants::APPROVER_STATUS_APPROVED);
    }
}
```

- [ ] **Step 5: Escribir el test**

Create `tests/TestCase/Model/Table/RefundApprovalsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\RefundApprovalsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundApprovalsTableTest extends TestCase
{
    public function testTableConfiguration(): void
    {
        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        $this->assertInstanceOf(RefundApprovalsTable::class, $table);
        $this->assertSame('refund_approvals', $table->getTable());
        $this->assertTrue($table->hasAssociation('Refunds'));
        $this->assertTrue($table->hasAssociation('Users'));
    }

    public function testTokenHashIsHidden(): void
    {
        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        $entity = $table->newEntity(['refund_id' => 1, 'user_id' => 1, 'status' => 'Pendiente', 'token_hash' => 'abc']);
        $this->assertArrayNotHasKey('token_hash', $entity->toArray());
    }
}
```

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter RefundApprovalsTableTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add config/Migrations/20260702100000_CreateRefundApprovals.php src/Model/Entity/RefundApproval.php src/Model/Table/RefundApprovalsTable.php tests/Factory/RefundApprovalFactory.php tests/TestCase/Model/Table/RefundApprovalsTableTest.php
git commit -m "feat(refunds): tabla refund_approvals (migración, entity, table, factory)"
```

---

### Task 2: `RefundApprovalGuard` (consultas para el State)

**Files:**
- Create: `src/Service/RefundApprovalGuard.php`
- Test: `tests/TestCase/Service/RefundApprovalGuardTest.php`

**Interfaces:**
- Consumes: tabla `refund_approvals` (Task 1); `InvoiceConstants::{APPROVER_STATUSES_ACTIVE, APPROVER_STATUS_APPROVED, DIAN_APPROVED}`; factories.
- Produces: `RefundApprovalGuard::{activeApproverCount(int):int, approvedCount(int):int, allApproved(int):bool, childInvoicesFailingDian(int):array}`.

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Service/RefundApprovalGuardTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\RefundApprovalGuard;
use App\Test\Factory\RefundApprovalFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

class RefundApprovalGuardTest extends TestCase
{
    public function testAllApprovedTrueWhenEveryActiveIsApproved(): void
    {
        $refund = RefundFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u1->id])->approved()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u2->id])->approved()->save();

        $this->assertTrue((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }

    public function testAllApprovedFalseWithPending(): void
    {
        $refund = RefundFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u1->id])->approved()->save();
        RefundApprovalFactory::new(['refund_id' => $refund->id, 'user_id' => $u2->id])->save(); // pendiente

        $this->assertFalse((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }

    public function testAllApprovedFalseWithNoApprovers(): void
    {
        $refund = RefundFactory::new()->save();
        $this->assertFalse((new RefundApprovalGuard())->allApproved((int)$refund->id));
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter RefundApprovalGuardTest`
Expected: FAIL — clase inexistente.

- [ ] **Step 3: Escribir el guard**

Create `src/Service/RefundApprovalGuard.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

/**
 * Consultas ligeras sobre refund_approvals para los States puros del pipeline
 * de reintegros (espejo de AdvanceLegalizationGuard).
 */
final class RefundApprovalGuard
{
    public function activeApproverCount(int $refundId): int
    {
        return TableRegistry::getTableLocator()->get('RefundApprovals')->find()
            ->where(['refund_id' => $refundId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->count();
    }

    public function approvedCount(int $refundId): int
    {
        return TableRegistry::getTableLocator()->get('RefundApprovals')->find()
            ->where(['refund_id' => $refundId, 'status' => InvoiceConstants::APPROVER_STATUS_APPROVED])
            ->count();
    }

    /** True si hay ≥1 aprobador activo y todos están en 'Aprobada'. */
    public function allApproved(int $refundId): bool
    {
        $active = $this->activeApproverCount($refundId);

        return $active > 0 && $active === $this->approvedCount($refundId);
    }

    /** Facturas hijas sin DIAN aprobada (número o #id). */
    public function childInvoicesFailingDian(int $refundId): array
    {
        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where(['refund_id' => $refundId, 'dian_validation !=' => InvoiceConstants::DIAN_APPROVED])
            ->all()
            ->map(fn($i) => $i->invoice_number ?: ('#' . $i->id))
            ->toList();
    }
}
```

- [ ] **Step 4: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter RefundApprovalGuardTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Service/RefundApprovalGuard.php tests/TestCase/Service/RefundApprovalGuardTest.php
git commit -m "feat(refunds): RefundApprovalGuard (consultas de quórum y DIAN)"
```

---

### Task 3: Estado `aprobacion` — enum, constantes, `AprobacionState`, registry (y actualizar tests existentes)

**Files:**
- Modify: `src/Constants/Domain/Refund/PipelineStatus.php`
- Modify: `src/Constants/RefundConstants.php`
- Create: `src/Service/Pipeline/Refund/State/AprobacionState.php`
- Modify: `src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`
- Modify (tests existentes): `tests/TestCase/Service/Pipeline/Refund/State/RefundStatesTest.php`, `tests/TestCase/Service/Pipeline/Refund/RefundPipelineStateRegistryTest.php`
- Test: `tests/TestCase/Constants/Domain/Refund/PipelineStatusTest.php`, `tests/TestCase/Service/Pipeline/Refund/State/AprobacionStateTest.php`

**Interfaces:**
- Consumes: `RefundApprovalGuard` (Task 2); `RefundPipelineState` interface.
- Produces: `PipelineStatus::APROBACION` (`'aprobacion'`), en `next()/previous()/label()`; `RefundConstants::STATUS_APROBACION`, en `STATUSES` y `STATUS_LABELS`; `AprobacionState implements RefundPipelineState` con gate de quórum + DIAN; registrado en `RefundPipelineStateRegistry` (7 estados).

> **Importante (evita ventana de tests rota):** enum, State y registro van en ESTA tarea juntos, porque `RefundPipelineStateRegistryTest::testGetReturnsStateForEveryEnumCase` itera `PipelineStatus::cases()` contra el registry. Añadir el case sin registrar el State dejaría ese test en fatal.

- [ ] **Step 1: Escribir/actualizar los tests que fallan**

Create `tests/TestCase/Constants/Domain/Refund/PipelineStatusTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Refund;

use App\Constants\Domain\Refund\PipelineStatus;
use PHPUnit\Framework\TestCase;

class PipelineStatusTest extends TestCase
{
    public function testAprobacionIsBetweenAgrupacionAndContabilidad(): void
    {
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::AGRUPACION->next());
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::APROBACION->next());
        $this->assertSame(PipelineStatus::AGRUPACION, PipelineStatus::APROBACION->previous());
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::CONTABILIDAD->previous());
        $this->assertSame('Aprobación', PipelineStatus::APROBACION->label());
    }
}
```

Create `tests/TestCase/Service/Pipeline/Refund/State/AprobacionStateTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\State\AprobacionState;
use App\Service\RefundApprovalGuard;
use PHPUnit\Framework\TestCase;

class AprobacionStateTest extends TestCase
{
    public function testTransitions(): void
    {
        $state = new AprobacionState(new RefundApprovalGuard());
        $this->assertSame(PipelineStatus::APROBACION, $state->getStatus());
        $this->assertSame(PipelineStatus::CONTABILIDAD, $state->getNextStatus());
        $this->assertSame(PipelineStatus::AGRUPACION, $state->getPreviousStatus());
    }

    public function testValidateAdvanceBlocksWithoutQuorum(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(false);
        $guard->method('childInvoicesFailingDian')->willReturn([]);

        $errors = (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower($errors[0]));
    }

    public function testValidateAdvancePassesWithQuorumAndDian(): void
    {
        $guard = $this->createMock(RefundApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childInvoicesFailingDian')->willReturn([]);

        $this->assertSame([], (new AprobacionState($guard))->validateAdvance(new Refund(['id' => 1])));
    }
}
```

Actualizar `tests/TestCase/Service/Pipeline/Refund/State/RefundStatesTest.php`:
- `testAgrupacionTransitions` (L34): cambiar la aserción de next a `$this->assertSame(PipelineStatus::APROBACION, $s->getNextStatus());`
- `testContabilidadTransitions` (L43): cambiar la aserción de previous a `$this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());`

Actualizar `tests/TestCase/Service/Pipeline/Refund/RefundPipelineStateRegistryTest.php`:
- `testRegistryHasSixStates` → renombrar a `testRegistryHasSevenStates` y `assertCount(7, ...)`.
- `testGetReturnsStateForEveryEnumCase` y `testKeysMatchEnumValues` no cambian (pasan una vez el registry incluya `AprobacionState`).

- [ ] **Step 2: Correr los tests — deben fallar**

Run: `vendor/bin/phpunit --filter PipelineStatusTest`
Run: `vendor/bin/phpunit --filter AprobacionStateTest`
Expected: FAIL (enum sin APROBACION / clase inexistente).

- [ ] **Step 3: Añadir el case al enum**

En `src/Constants/Domain/Refund/PipelineStatus.php`:
- Cases: insertar `case APROBACION = 'aprobacion';` tras `AGRUPACION`.
- `label()`: insertar `self::APROBACION => 'Aprobación',` tras AGRUPACION.
- `next()`: reemplazar `self::AGRUPACION => self::CONTABILIDAD,` por:
```php
            self::AGRUPACION => self::APROBACION,
            self::APROBACION => self::CONTABILIDAD,
```
- `previous()`: reemplazar `self::CONTABILIDAD => self::AGRUPACION,` por:
```php
            self::APROBACION => self::AGRUPACION,
            self::CONTABILIDAD => self::APROBACION,
```

- [ ] **Step 4: Constante y labels en `RefundConstants`**

En `src/Constants/RefundConstants.php`:
- Tras `STATUS_AGRUPACION`: `public const STATUS_APROBACION = PipelineStatus::APROBACION->value;`
- En `STATUSES` insertar `self::STATUS_APROBACION,` entre AGRUPACION y CONTABILIDAD.
- En `STATUS_LABELS` insertar `self::STATUS_APROBACION => 'Aprobación',` entre AGRUPACION y CONTABILIDAD.

- [ ] **Step 5: Escribir `AprobacionState`**

Create `src/Service/Pipeline/Refund/State/AprobacionState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;
use App\Service\RefundApprovalGuard;

final class AprobacionState implements RefundPipelineState
{
    private RefundApprovalGuard $guard;

    public function __construct(?RefundApprovalGuard $guard = null)
    {
        $this->guard = $guard ?? new RefundApprovalGuard();
    }

    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::APROBACION;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return $this->getStatus()->next();
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return $this->getStatus()->previous();
    }

    public function validateAdvance(Refund $record): array
    {
        $errors = [];
        if (!$this->guard->allApproved((int)$record->id)) {
            $errors[] = 'La aprobación de área del grupo está pendiente: todos los aprobadores deben aprobar.';
        }
        $failingDian = $this->guard->childInvoicesFailingDian((int)$record->id);
        if (!empty($failingDian)) {
            $errors[] = 'Validación DIAN pendiente en: ' . implode(', ', $failingDian);
        }

        return $errors;
    }
}
```

- [ ] **Step 6: Registrar en el registry**

En `src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`:
- Añadir `use App\Service\Pipeline\Refund\State\AprobacionState;`
- Añadir param al constructor: `?AprobacionState $aprobacion = null,` tras `$agrupacion`.
- En `$list` insertar `$aprobacion ?? new AprobacionState(),` tras `$agrupacion ?? new AgrupacionState(),`.

- [ ] **Step 7: Correr los tests afectados — deben pasar**

Run: `vendor/bin/phpunit --filter PipelineStatusTest`
Run: `vendor/bin/phpunit --filter AprobacionStateTest`
Run: `vendor/bin/phpunit --filter RefundStatesTest`
Run: `vendor/bin/phpunit --filter RefundPipelineStateRegistryTest`
Expected: PASS (los cuatro).

- [ ] **Step 8: Commit**

```bash
git add src/Constants/Domain/Refund/PipelineStatus.php src/Constants/RefundConstants.php src/Service/Pipeline/Refund/State/AprobacionState.php src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php tests/TestCase/Constants/Domain/Refund/PipelineStatusTest.php tests/TestCase/Service/Pipeline/Refund/State/AprobacionStateTest.php tests/TestCase/Service/Pipeline/Refund/State/RefundStatesTest.php tests/TestCase/Service/Pipeline/Refund/RefundPipelineStateRegistryTest.php
git commit -m "feat(refunds): estado 'aprobacion' (enum, AprobacionState, registry, gate quórum+DIAN)"
```

---

### Task 4: Vinculación de facturas en estado `aprobacion` (parametrizar `GroupedInvoiceService`)

**Files:**
- Modify: `src/Service/GroupedInvoiceService.php` (constructor, `getAvailableInvoices`, `validateGrouping`)
- Modify: `src/Service/RefundPipelineService.php:53-59`
- Test: `tests/TestCase/Service/GroupedInvoiceServiceTest.php`

**Interfaces:**
- Consumes: `RefundConstants::STATUS_APROBACION`.
- Produces: `GroupedInvoiceService` acepta `string $linkableStatus = InvoiceConstants::STATUS_CONTABILIDAD` y lo aplica en `getAvailableInvoices()` **y** `validateGrouping()`; `RefundPipelineService` lo instancia con `linkableStatus: InvoiceConstants::STATUS_APROBACION`.

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Service/GroupedInvoiceServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\GroupedInvoiceService;
use App\Service\Interface\HistoryServiceInterface;
use App\Test\Factory\InvoiceFactory;
use Cake\TestSuite\TestCase;

class GroupedInvoiceServiceTest extends TestCase
{
    private function _service(string $linkableStatus): GroupedInvoiceService
    {
        return new GroupedInvoiceService(
            documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
            fkField: 'refund_id',
            recordTableName: 'Refunds',
            fkLabel: 'Reintegro',
            historyService: $this->createMock(HistoryServiceInterface::class),
            linkableStatus: $linkableStatus,
        );
    }

    public function testValidateGroupingRejectsWrongStatus(): void
    {
        $inv = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_REINTEGRO])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $errors = $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$inv->id]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower(implode(' ', $errors)));
    }

    public function testValidateGroupingAcceptsMatchingStatus(): void
    {
        $inv = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_REINTEGRO])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->assertSame([], $this->_service(InvoiceConstants::STATUS_APROBACION)->validateGrouping([$inv->id]));
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter GroupedInvoiceServiceTest`
Expected: FAIL — `linkableStatus` no existe (Unknown named parameter).

- [ ] **Step 3: Parametrizar el servicio**

En `src/Service/GroupedInvoiceService.php`, añadir al constructor: `private readonly string $linkableStatus = InvoiceConstants::STATUS_CONTABILIDAD,` (último param).

En `validateGrouping()` (L77-82), reemplazar el chequeo:
```php
            if ($invoice->pipeline_status !== $this->linkableStatus) {
                $errors[] = sprintf(
                    'La factura #%s no está en estado "%s".',
                    $invoice->invoice_number ?? $invoice->id,
                    InvoiceConstants::STATUS_LABELS[$this->linkableStatus] ?? $this->linkableStatus,
                );
            }
```

En `getAvailableInvoices()` (L191), reemplazar por: `'Invoices.pipeline_status' => $this->linkableStatus,`

- [ ] **Step 4: Reintegros pasa `aprobacion`**

En `src/Service/RefundPipelineService.php` constructor (L53-59), añadir el argumento final:
```php
            historyService: $historyService,
            linkableStatus: InvoiceConstants::STATUS_APROBACION,
        );
```

- [ ] **Step 5: Correr tests — deben pasar (y PettyCash intacto)**

Run: `vendor/bin/phpunit --filter GroupedInvoiceServiceTest`
Run: `vendor/bin/phpunit --filter PettyCash`
Expected: PASS (PettyCash usa el default `contabilidad`).

- [ ] **Step 6: Commit**

```bash
git add src/Service/GroupedInvoiceService.php src/Service/RefundPipelineService.php tests/TestCase/Service/GroupedInvoiceServiceTest.php
git commit -m "feat(refunds): vincular facturas en estado 'aprobacion' (filtro parametrizado)"
```

---

### Task 5: Email de solicitud de aprobación de grupo

**Files:**
- Modify: `src/Constants/EmailLogConstants.php`
- Modify: `src/Service/NotificationService.php`
- Create: `templates/email/html/refund_approval_request.php`
- Test: `tests/TestCase/Service/NotificationServiceRefundTest.php`

**Interfaces:**
- Produces: `NotificationService::sendRefundApprovalLinkNotification(Refund $refund, string $approvalUrl, int $approverUserId, ?int $createdBy = null): void`; constantes `EVENT_REFUND_APPROVAL_REQUEST`, `ENTITY_REFUND` + su label.

- [ ] **Step 1: Añadir constantes de email log**

Read `src/Constants/EmailLogConstants.php` (bloque `EVENT_LABELS` ~L34-37). Añadir:
```php
    public const EVENT_REFUND_APPROVAL_REQUEST = 'refund_approval_request';
    public const ENTITY_REFUND = 'refund';
```
Y en `EVENT_LABELS` añadir `self::EVENT_REFUND_APPROVAL_REQUEST => 'Solicitud de aprobación (Reintegro)',` (seguir el formato exacto de las entradas vecinas).

- [ ] **Step 2: Escribir el test que falla**

Create `tests/TestCase/Service/NotificationServiceRefundTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationServiceRefundTest extends TestCase
{
    public function testMethodExists(): void
    {
        $this->assertTrue(method_exists(NotificationService::class, 'sendRefundApprovalLinkNotification'));
    }
}
```

- [ ] **Step 3: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter NotificationServiceRefundTest`
Expected: FAIL.

- [ ] **Step 4: Añadir el método** (espejo de `sendApprovalLinkNotification`, L31-87)

En `src/Service/NotificationService.php` añadir `use App\Model\Entity\Refund;` (si falta) y el método:

```php
    /**
     * Envía el link de aprobación de un grupo (Reintegro) a un aprobador.
     * Espejo de sendApprovalLinkNotification a nivel de grupo.
     */
    public function sendRefundApprovalLinkNotification(
        Refund $refund,
        string $approvalUrl,
        int $approverUserId,
        ?int $createdBy = null,
    ): void {
        $smtpConfig = $this->settings->getGroup('smtp');
        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            throw new Exception('SMTP no configurado. Configure el correo en Ajustes del Sistema.');
        }

        $recipients = $this->getApproverRecipient($approverUserId);
        if (empty($recipients)) {
            throw new Exception('El aprobador asignado no tiene un usuario activo o no tiene correo.');
        }

        $code = $refund->code ?: ('#' . $refund->id);
        $subject = "SPI-COPCSA - Solicitud de Aprobación: Reintegro {$code}";

        foreach ($recipients as $recipient) {
            if (empty($recipient->email)) {
                throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
            }

            $viewVars = [
                'refundCode' => $code,
                'beneficiaryName' => $refund->getBeneficiaryName() ?: '—',
                'amount' => $refund->total_amount,
                'approvalUrl' => $approvalUrl,
                'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
            ];

            $this->deliverWithLog(
                eventType: EmailLogConstants::EVENT_REFUND_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_REFUND,
                entityId: (int)$refund->id,
                to: $recipient->email,
                subject: $subject,
                template: 'refund_approval_request',
                viewVars: $viewVars,
                layout: 'default',
                createdBy: $createdBy,
            );

            $this->logger->info('approval_link_sent', [
                'recipient' => $recipient->email,
                'refund_id' => $refund->id,
                'context' => 'refund',
            ]);
        }
    }
```

- [ ] **Step 5: Escribir la plantilla de correo**

Read `templates/email/html/invoice_approval_request.php` y crear `templates/email/html/refund_approval_request.php` adaptándola: variables `$refundCode`, `$beneficiaryName`, `$amount`, `$approvalUrl`, `$recipientName`; texto "Factura" → "Reintegro"; botón a `<?= h($approvalUrl) ?>`; conservar la mención de validez 48h.

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter NotificationServiceRefundTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Constants/EmailLogConstants.php src/Service/NotificationService.php templates/email/html/refund_approval_request.php tests/TestCase/Service/NotificationServiceRefundTest.php
git commit -m "feat(refunds): notificación de solicitud de aprobación de grupo"
```

---

### Task 6: `GroupApprovalService` base + `RefundApprovalService` + registro DI

**Files:**
- Create: `src/Service/GroupApproval/GroupApprovalService.php`
- Create: `src/Service/RefundApprovalService.php`
- Modify: `src/Application.php` (registro DI)
- Test: `tests/TestCase/Service/RefundApprovalServiceTest.php`

**Interfaces:**
- Consumes: `refund_approvals`; `InvoiceConstants::APPROVER_STATUS_*`, `APPROVER_STATUSES_ACTIVE`, `APPROVAL_TOKEN_HOURS`, `APPROVAL_APPROVED`; `ApprovalTokenManager::{generateSecret,hashSecret}`, `ApprovalUrlBuilder::approveUrl`; `NotificationService::sendRefundApprovalLinkNotification`; `RefundConstants::{STATUS_APROBACION,STATUS_AGRUPACION,OBSERVATION_TYPE_REGRESSION}`; `RefundHistoryService`; `ApprovalConstants::ACTION_*`.
- Produces:
  - `GroupApprovalService` (abstract): `assignApprovers`, `sendApprovalLinks`, `modifyApprovers`, `getCurrentApprovals`, `getApprovalSummary`, `validateToken`, `processResponse`, `areAllApproved`, `hasAnyActiveApprovals`, `hasPendingApprovals`, `applyFreshToken`, `supersedeAll(int):void`. Abstract: `tableName()`, `fkField()`, `notifyApprover(object,string,int,int)`, `onAllApproved(int,int)`, `onRejected(int,int,?string)`.
  - `RefundApprovalService extends GroupApprovalService` (registrado en DI con `NotificationService`).

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Service/RefundApprovalServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\ApprovalConstants;
use App\Constants\RefundConstants;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundApprovalServiceTest extends TestCase
{
    private function _service(): RefundApprovalService
    {
        return new RefundApprovalService($this->createMock(NotificationService::class));
    }

    public function testAssignThenAllApproved(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id, $u2->id], 'https://x', (int)$u1->id);
        $this->assertFalse($svc->areAllApproved((int)$refund->id));

        $table = TableRegistry::getTableLocator()->get('RefundApprovals');
        foreach ($table->find()->where(['refund_id' => $refund->id])->all() as $a) {
            $secret = $svc->applyFreshToken($a);
            $table->saveOrFail($a);
            $svc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        }
        $this->assertTrue($svc->areAllApproved((int)$refund->id));
    }

    public function testRejectRegressesRefundToAgrupacion(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $u1 = UserFactory::new()->save();
        $svc = $this->_service();

        $svc->assignApprovers($refund, [$u1->id], 'https://x', (int)$u1->id);
        $a = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        TableRegistry::getTableLocator()->get('RefundApprovals')->saveOrFail($a);

        $svc->processResponse($secret, ApprovalConstants::ACTION_REJECT, 'faltan soportes', '127.0.0.1', 'phpunit');

        $reloaded = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame('agrupacion', $reloaded->status);
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter RefundApprovalServiceTest`
Expected: FAIL — clases inexistentes.

- [ ] **Step 3: Escribir la base `GroupApprovalService`**

Create `src/Service/GroupApproval/GroupApprovalService.php` (contenido íntegro):

```php
<?php
declare(strict_types=1);

namespace App\Service\GroupApproval;

use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\Approval\ApprovalTokenManager;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\ServiceResult;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Base multi-aprobador a nivel de grupo (reintegro / legalización de anticipo).
 * Espejo genérico de InvoiceApprovalService: asigna aprobadores, emite tokens,
 * detecta quórum "todos aprueban" y consume el token con FOR UPDATE. El efecto
 * de dominio lo aportan las subclases (onAllApproved / onRejected).
 */
abstract class GroupApprovalService
{
    protected Table $approvalsTable;

    public function __construct()
    {
        $this->approvalsTable = TableRegistry::getTableLocator()->get($this->tableName());
    }

    abstract protected function tableName(): string;

    abstract protected function fkField(): string;

    abstract protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void;

    abstract protected function onAllApproved(int $entityId, int $approverUserId): void;

    abstract protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void;

    public function applyFreshToken(object $approval): string
    {
        $secret = ApprovalTokenManager::generateSecret();
        $approval->token_hash = ApprovalTokenManager::hashSecret($secret);
        $approval->token_expires_at = new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours');

        return $secret;
    }

    public function getCurrentApprovals(int $entityId): array
    {
        return $this->approvalsTable->find()
            ->where([$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->contain(['Users' => ['Roles']])
            ->orderBy([$this->approvalsTable->getAlias() . '.created' => 'ASC'])
            ->all()
            ->toArray();
    }

    public function getApprovalSummary(int $entityId): array
    {
        $total = 0;
        $approved = 0;
        $rejected = 0;
        $pending = 0;
        foreach ($this->getCurrentApprovals($entityId) as $r) {
            $total++;
            match ($r->status) {
                InvoiceConstants::APPROVER_STATUS_APPROVED => $approved++,
                InvoiceConstants::APPROVER_STATUS_REJECTED => $rejected++,
                default => $pending++,
            };
        }

        return compact('total', 'approved', 'rejected', 'pending');
    }

    public function validateToken(string $token): ?object
    {
        return $this->approvalsTable->find()
            ->where([
                'token_hash' => ApprovalTokenManager::hashSecret($token),
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])
            ->contain(['Users'])
            ->first();
    }

    public function areAllApproved(int $entityId): bool
    {
        $rows = $this->getCurrentApprovals($entityId);
        if (empty($rows)) {
            return false;
        }
        foreach ($rows as $r) {
            if ($r->status !== InvoiceConstants::APPROVER_STATUS_APPROVED) {
                return false;
            }
        }

        return true;
    }

    public function hasPendingApprovals(int $entityId): bool
    {
        return $this->approvalsTable->find()
            ->where([
                $this->fkField() => $entityId,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                'token_expires_at >' => new DateTime(),
            ])->count() > 0;
    }

    public function hasAnyActiveApprovals(int $entityId): bool
    {
        return $this->approvalsTable->find()
            ->where([$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE])
            ->count() > 0;
    }

    /** Marca Reemplazada todas las aprobaciones activas del grupo (edición/regresión). */
    public function supersedeAll(int $entityId): void
    {
        $this->approvalsTable->updateAll(
            ['status' => InvoiceConstants::APPROVER_STATUS_SUPERSEDED, 'token_hash' => null, 'token_expires_at' => null],
            [$this->fkField() => $entityId, 'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE],
        );
    }

    public function assignApprovers(object $entity, array $userIds, string $baseUrl, int $createdBy): ServiceResult
    {
        if (empty($userIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador']);
        }
        $pending = [];
        $expiresAt = new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours');
        foreach ($userIds as $userId) {
            $secret = ApprovalTokenManager::generateSecret();
            $approval = $this->approvalsTable->newEntity([
                $this->fkField() => $entity->id,
                'user_id' => (int)$userId,
                'token_hash' => ApprovalTokenManager::hashSecret($secret),
                'token_expires_at' => $expiresAt,
                'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            ]);
            if (!$this->approvalsTable->save($approval)) {
                return ServiceResult::fail(["Error al asignar aprobador ID {$userId}"]);
            }
            $pending[] = ['userId' => (int)$userId, 'url' => ApprovalUrlBuilder::approveUrl($baseUrl, $secret)];
        }

        $errors = [];
        foreach ($pending as $item) {
            try {
                $this->notifyApprover($entity, $item['url'], $item['userId'], $createdBy);
            } catch (Exception $e) {
                $errors[] = sprintf('Aprobador asignado, pero el correo a usuario ID %d falló: %s', $item['userId'], $e->getMessage());
            }
        }

        return empty($errors) ? ServiceResult::ok(['assigned' => count($pending)]) : ServiceResult::fail($errors);
    }

    public function sendApprovalLinks(object $entity, array $userIds, string $baseUrl, int $createdBy): ServiceResult
    {
        if ($this->hasAnyActiveApprovals((int)$entity->id)) {
            return ServiceResult::fail(['Ya existen aprobaciones; use Modificar aprobadores.']);
        }

        return $this->assignApprovers($entity, $userIds, $baseUrl, $createdBy);
    }

    public function modifyApprovers(object $entity, array $userIds, string $reason, string $baseUrl, int $userId): ServiceResult
    {
        if (trim($reason) === '') {
            return ServiceResult::fail(['El motivo es obligatorio.']);
        }
        if (empty($userIds)) {
            return ServiceResult::fail(['Debe seleccionar al menos un aprobador.']);
        }
        $this->supersedeAll((int)$entity->id);

        return $this->assignApprovers($entity, $userIds, $baseUrl, $userId);
    }

    public function processResponse(string $token, string $action, ?string $observations, ?string $ip, ?string $userAgent): ServiceResult
    {
        $connection = $this->approvalsTable->getConnection();

        return $connection->transactional(function () use ($token, $action, $observations, $ip, $userAgent) {
            $approval = $this->approvalsTable->find()
                ->where([
                    'token_hash' => ApprovalTokenManager::hashSecret($token),
                    'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
                    'token_expires_at >' => new DateTime(),
                ])
                ->epilog('FOR UPDATE')
                ->first();

            if (!$approval) {
                return ServiceResult::fail(['Token inválido o expirado']);
            }

            $approval->status = $action === ApprovalConstants::ACTION_APPROVE
                ? InvoiceConstants::APPROVER_STATUS_APPROVED
                : InvoiceConstants::APPROVER_STATUS_REJECTED;
            $approval->responded_at = new DateTime();
            $approval->observations = $observations;
            $approval->ip_address = $ip;
            $approval->user_agent = $userAgent;
            $approval->token_hash = null;
            if (!$this->approvalsTable->save($approval)) {
                return ServiceResult::fail(['Error al guardar respuesta']);
            }

            $entityId = (int)$approval->{$this->fkField()};

            if ($action === ApprovalConstants::ACTION_REJECT) {
                $this->approvalsTable->updateAll(
                    ['token_hash' => null, 'token_expires_at' => null],
                    [$this->fkField() => $entityId, 'status' => InvoiceConstants::APPROVER_STATUS_PENDING, 'id !=' => $approval->id],
                );
                $this->onRejected($entityId, (int)$approval->user_id, $observations);

                return ServiceResult::ok(['allApproved' => false, 'rejected' => true, 'entity_id' => $entityId]);
            }

            $allApproved = $this->areAllApproved($entityId);
            if ($allApproved) {
                $this->onAllApproved($entityId, (int)$approval->user_id);
            }

            return ServiceResult::ok(['allApproved' => $allApproved, 'rejected' => false, 'entity_id' => $entityId]);
        });
    }
}
```

- [ ] **Step 4: Escribir `RefundApprovalService`** (NotificationService requerido, sin `?? new`)

Create `src/Service/RefundApprovalService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\GroupApproval\GroupApprovalService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Aprobación de área en lote para Reintegros (tabla refund_approvals).
 */
final class RefundApprovalService extends GroupApprovalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ?RefundHistoryService $refundHistory = null,
    ) {
        parent::__construct();
    }

    protected function tableName(): string
    {
        return 'RefundApprovals';
    }

    protected function fkField(): string
    {
        return 'refund_id';
    }

    protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void
    {
        $this->notificationService->sendRefundApprovalLinkNotification($entity, $url, $userId, $createdBy);
    }

    protected function onAllApproved(int $entityId, int $approverUserId): void
    {
        TableRegistry::getTableLocator()->get('Invoices')->updateAll(
            ['area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'area_approval_date' => new DateTime()],
            ['refund_id' => $entityId],
        );
    }

    protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void
    {
        $refunds = TableRegistry::getTableLocator()->get('Refunds');
        $refund = $refunds->get($entityId);
        if ($refund->status !== RefundConstants::STATUS_APROBACION) {
            return;
        }
        $from = $refund->status;
        $refund->status = RefundConstants::STATUS_AGRUPACION;
        $refunds->save($refund);

        $reason = trim((string)$observations) !== '' ? $observations : 'Rechazado por el aprobador de área.';

        $observationsTable = TableRegistry::getTableLocator()->get('RefundObservations');
        $observationsTable->save($observationsTable->newEntity([
            'refund_id' => $entityId,
            'user_id' => $approverUserId,
            'type' => RefundConstants::OBSERVATION_TYPE_REGRESSION,
            'message' => $reason,
            'metadata' => ['from_status' => $from, 'to_status' => RefundConstants::STATUS_AGRUPACION],
        ]));

        ($this->refundHistory ?? new RefundHistoryService())
            ->recordStatusChange($entityId, $from, RefundConstants::STATUS_AGRUPACION, $approverUserId);
    }
}
```

- [ ] **Step 5: Registrar en el contenedor DI**

En `src/Application.php`, junto al bloque de Refund (tras `RefundPipelineService`, ~L402), añadir:
```php
        $container->addShared(RefundApprovalService::class)
            ->addArguments([
                NotificationService::class,
                RefundHistoryService::class,
            ]);
```
Y `use App\Service\RefundApprovalService;` en el bloque de imports (junto a `RefundPipelineService`).

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter RefundApprovalServiceTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Service/GroupApproval/GroupApprovalService.php src/Service/RefundApprovalService.php src/Application.php tests/TestCase/Service/RefundApprovalServiceTest.php
git commit -m "feat(refunds): GroupApprovalService base + RefundApprovalService + DI"
```

---

### Task 7: Propagación de facturas hijas al regresar por `aprobacion`

**Files:**
- Modify: `src/Service/RefundPipelineService.php` (`regress` `$childPipelineMap` L601-604)
- Test: `tests/TestCase/Service/Integration/RefundAprobacionFlowTest.php`

**Interfaces:**
- Consumes: `RefundConstants::STATUS_APROBACION`, `InvoiceConstants::STATUS_APROBACION`.
- Produces: al regresar `contabilidad → aprobacion` las hijas vuelven a invoice-`aprobacion`. (El avance `aprobacion → contabilidad` ya lo cubre el branch `nextStatus === STATUS_CONTABILIDAD` en `advance()` `$updateData`; `agrupacion → aprobacion` intencionalmente NO propaga.)

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Service/Integration/RefundAprobacionFlowTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\RefundPipelineService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundAprobacionFlowTest extends TestCase
{
    private function buildService(bool $canOperate = true): RefundPipelineService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn($canOperate);
        $auth->method('operableSteps')->willReturn([]);

        return new RefundPipelineService(new InvoiceHistoryService(), $auth);
    }

    public function testRegressFromContabilidadReturnsChildrenToAprobacion(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_CONTABILIDAD)->save();
        $invoice = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $result = $this->buildService()->regress($refund, $user->role_id, $user->id, 'motivo suficiente de regresion');
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $reloadedInvoice = TableRegistry::getTableLocator()->get('Invoices')->get($invoice->id);
        $this->assertSame(RefundConstants::STATUS_APROBACION, $reloadedRefund->status);
        $this->assertSame(InvoiceConstants::STATUS_APROBACION, $reloadedInvoice->pipeline_status);
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter RefundAprobacionFlowTest`
Expected: FAIL — el refund regresa a `agrupacion` (mapa viejo) y la hija no cambia a `aprobacion`.

- [ ] **Step 3: Ampliar `$childPipelineMap` en `regress`** (L601-604)

Reemplazar por:
```php
        $childPipelineMap = [
            RefundConstants::STATUS_APROBACION => InvoiceConstants::STATUS_APROBACION,
            RefundConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_CONTABILIDAD,
            RefundConstants::STATUS_TESORERIA => InvoiceConstants::STATUS_TESORERIA,
        ];
```

- [ ] **Step 4: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter RefundAprobacionFlowTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Service/RefundPipelineService.php tests/TestCase/Service/Integration/RefundAprobacionFlowTest.php
git commit -m "feat(refunds): propaga estado de hijas al regresar contabilidad→aprobacion"
```

---

### Task 8: RBAC — declarar y sembrar el step `aprobacion`

**Files:**
- Modify: `src/Constants/PipelineStepConstants.php` (`STEPS_BY_PIPELINE[refunds]`, `STEP_LABELS[refunds]`)
- Create: `config/Migrations/20260702110000_SeedRefundAprobacionPermission.php`
- Test: `tests/TestCase/Constants/PipelineStepConstantsTest.php`

**Interfaces:**
- Produces: `PipelineStepConstants::isValid('refunds','aprobacion') === true`; filas en `pipeline_permissions` para el step nuevo replicando los roles que ya operan `agrupacion`.

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Constants/PipelineStepConstantsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants;

use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use PHPUnit\Framework\TestCase;

class PipelineStepConstantsTest extends TestCase
{
    public function testAprobacionIsAValidRefundStep(): void
    {
        $this->assertTrue(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_APROBACION,
        ));
        $this->assertArrayHasKey(
            RefundConstants::STATUS_APROBACION,
            PipelineStepConstants::STEP_LABELS[PipelineStepConstants::PIPELINE_REFUNDS],
        );
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter PipelineStepConstantsTest`
Expected: FAIL.

- [ ] **Step 3: Declarar el step**

En `src/Constants/PipelineStepConstants.php`:
- `STEPS_BY_PIPELINE[self::PIPELINE_REFUNDS]`: insertar `RefundConstants::STATUS_APROBACION,` tras `STATUS_AGRUPACION`.
- `STEP_LABELS[self::PIPELINE_REFUNDS]`: insertar `RefundConstants::STATUS_APROBACION => RefundConstants::STATUS_LABELS[RefundConstants::STATUS_APROBACION],` tras AGRUPACION.

- [ ] **Step 4: Migración de seed**

Read primero la migración de creación de `pipeline_permissions` para confirmar columnas. Create `config/Migrations/20260702110000_SeedRefundAprobacionPermission.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Otorga el step 'aprobacion' del pipeline 'refunds' a los mismos roles que hoy
 * operan 'agrupacion', para que el nuevo estado tenga operadores desde el deploy.
 */
class SeedRefundAprobacionPermission extends BaseMigration
{
    public function up(): void
    {
        $rows = $this->fetchAll(
            "SELECT role_id FROM pipeline_permissions
             WHERE pipeline = 'refunds' AND step = 'agrupacion' AND can_operate = 1"
        );
        foreach ($rows as $row) {
            $roleId = (int)$row['role_id'];
            $exists = $this->fetchRow(
                "SELECT id FROM pipeline_permissions
                 WHERE pipeline = 'refunds' AND step = 'aprobacion' AND role_id = {$roleId}"
            );
            if (!$exists) {
                $this->execute(
                    "INSERT INTO pipeline_permissions (role_id, pipeline, step, can_operate, created, modified)
                     VALUES ({$roleId}, 'refunds', 'aprobacion', 1, NOW(), NOW())"
                );
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pipeline_permissions WHERE pipeline = 'refunds' AND step = 'aprobacion'");
    }
}
```
Ajustar nombres de columnas si la tabla difiere (p. ej. sin `created/modified`).

- [ ] **Step 5: Migrar + test + auditoría**

Run: `php bin/cake migrations migrate`
Run: `vendor/bin/phpunit --filter PipelineStepConstantsTest`
Run: `php bin/cake permissions_audit`
Expected: migración OK; test PASS; auditoría exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Constants/PipelineStepConstants.php config/Migrations/20260702110000_SeedRefundAprobacionPermission.php tests/TestCase/Constants/PipelineStepConstantsTest.php
git commit -m "feat(refunds): declara y siembra el step de pipeline 'aprobacion'"
```

---

### Task 9: Acciones del controller (grupo) + rutas + `_getBaseUrl` + invalidación de links (spec §5)

**Files:**
- Modify: `src/Controller/RefundsController.php` (initialize, `_getBaseUrl`, `sendApprovalLinks`, `modifyApprovers`, `regressStatus`, `linkInvoices`)
- Modify: `config/routes.php` (2 rutas)
- Test: `tests/TestCase/Controller/RefundsControllerApprovalTest.php`

**Interfaces:**
- Consumes: `RefundApprovalService::{sendApprovalLinks,modifyApprovers,supersedeAll}` (Task 6); `ApprovalUrlBuilder::baseFromRequest`.
- Produces: acciones `sendApprovalLinks($id)`, `modifyApprovers($id)` (POST); rutas; invalidación de links del grupo al regresar desde `aprobacion` (M1); supersede de aprobaciones individuales de facturas al vincular (M2).

- [ ] **Step 1: Escribir el test que falla** (integración autenticada con factories)

Create `tests/TestCase/Controller/RefundsControllerApprovalTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\RefundConstants;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class RefundsControllerApprovalTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSendApprovalLinksCreatesApprovals(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $operator = UserFactory::new()->save();
        $approver = UserFactory::new()->save();

        // Autenticar como operador (replicar el mecanismo de sesión de otros tests de integración).
        $this->session(['Auth' => $operator]);
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();

        $this->post('/refunds/send-approval-links/' . $refund->id, ['approver_ids' => [$approver->id]]);

        $this->assertRedirect(); // redirige a edit
        $count = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->count();
        $this->assertSame(1, $count);
    }
}
```
Nota: ajustar `session(['Auth' => ...])`/`enableCsrfToken` al patrón real de los tests de integración existentes del proyecto (buscar otro `IntegrationTestTrait` en `tests/TestCase/Controller/`); el envío de correo lo hace `NotificationService` — si SMTP no está configurado en test, el `assign` persiste igual y el flash reporta el fallo de correo, pero las filas existen (assert principal).

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter RefundsControllerApprovalTest`
Expected: FAIL (ruta/acción inexistente).

- [ ] **Step 3: Inyectar servicio + `_getBaseUrl`**

En `src/Controller/RefundsController.php`:
- `use App\Service\RefundApprovalService;` y `use App\Service\Approval\ApprovalUrlBuilder;`
- Propiedad `private RefundApprovalService $approvalService;`
- En `initialize()`: `$this->approvalService = $container->get(RefundApprovalService::class);`
- Añadir el helper (idéntico a `InvoicesController::_getBaseUrl`, no es heredable):
```php
    private function _getBaseUrl(): string
    {
        return ApprovalUrlBuilder::baseFromRequest($this->request);
    }
```

- [ ] **Step 4: Añadir `sendApprovalLinks` y `modifyApprovers`**

```php
    #[Permission(action: 'edit')]
    public function sendApprovalLinks($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $user = $this->_getCurrentUser();

        if ($record->status !== RefundConstants::STATUS_APROBACION) {
            $this->Flash->error('Solo se envían enlaces en el estado Aprobación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $result = $this->approvalService->sendApprovalLinks(
            $record,
            (array)$this->request->getData('approver_ids'),
            $this->_getBaseUrl(),
            (int)$user->id,
        );

        if ($result->success) {
            $this->Flash->success('Enlaces de aprobación enviados.');
        } else {
            foreach ($result->errors as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    #[Permission(action: 'edit')]
    public function modifyApprovers($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->Refunds->get($id);
        $user = $this->_getCurrentUser();

        $result = $this->approvalService->modifyApprovers(
            $record,
            (array)$this->request->getData('approver_ids'),
            trim((string)$this->request->getData('reason')),
            $this->_getBaseUrl(),
            (int)$user->id,
        );

        if ($result->success) {
            $this->Flash->success('Aprobadores actualizados. Se enviaron los nuevos enlaces.');
        } else {
            foreach ($result->errors as $error) {
                $this->Flash->error($error);
            }
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 5: M1 — invalidar links del grupo al regresar desde `aprobacion`**

En `regressStatus()` (L449-480), capturar el estado ANTES de regresar y, si era `aprobacion` y el regress tuvo éxito, invalidar. Insertar tras `$record = $this->Refunds->get($id);`:
```php
        $statusBeforeRegress = $record->status;
```
Y dentro del bloque `if ($result->success) {` (antes del `return`):
```php
            if ($statusBeforeRegress === RefundConstants::STATUS_APROBACION) {
                $this->approvalService->supersedeAll((int)$id);
            }
```

- [ ] **Step 6: M2 — supersede aprobaciones individuales de las facturas al vincular**

En `linkInvoices()` (L654-684), dentro del `if (empty($errors)) {` (tras `_recordInvoicesLinked`):
```php
            TableRegistry::getTableLocator()->get('InvoiceApprovals')->updateAll(
                ['status' => \App\Constants\InvoiceConstants::APPROVER_STATUS_SUPERSEDED, 'token_hash' => null, 'token_expires_at' => null],
                ['invoice_id IN' => $invoiceIds, 'status IN' => \App\Constants\InvoiceConstants::APPROVER_STATUSES_ACTIVE],
            );
```
(Defensivo: una factura vinculada al grupo deja de usar el flujo individual.)

- [ ] **Step 7: Añadir rutas** en `config/routes.php` (junto al bloque de invoice approval, L440-450):

```php
        $builder->connect(
            '/refunds/send-approval-links/{id}',
            ['controller' => 'Refunds', 'action' => 'sendApprovalLinks'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/refunds/modify-approvers/{id}',
            ['controller' => 'Refunds', 'action' => 'modifyApprovers'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 8: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter RefundsControllerApprovalTest`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/Controller/RefundsController.php config/routes.php tests/TestCase/Controller/RefundsControllerApprovalTest.php
git commit -m "feat(refunds): acciones de aprobación de grupo + rutas + invalidación de links"
```

---

### Task 10: Aprobación externa del grupo (extender `ExternalApprovalsController`)

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php` (initialize + path de refund en `review` y `process`)
- Create: `templates/ExternalApprovals/review_group.php`
- Test: `tests/TestCase/Controller/ExternalApprovalsGroupTest.php`

**Interfaces:**
- Consumes: `RefundApprovalService::{validateToken,processResponse}`; `Refund` con `Invoices`.
- Produces: `/approve/{token}` de un token de grupo renderiza `review_group`; `/approve/{token}/process` aprueba/rechaza el grupo.

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Controller/ExternalApprovalsGroupTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\RefundConstants;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class ExternalApprovalsGroupTest extends TestCase
{
    use IntegrationTestTrait;

    public function testGroupTokenRendersGroupReview(): void
    {
        $refund = RefundFactory::new()->withStatus(RefundConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();

        $svc = new RefundApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($refund, [$approver->id], 'https://x', (int)$approver->id);
        $a = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        TableRegistry::getTableLocator()->get('RefundApprovals')->saveOrFail($a);

        $this->session(['Auth' => $approver]);
        $this->get('/approve/' . $secret);

        $this->assertResponseOk();
        $this->assertResponseContains('Reintegro');
    }
}
```
Nota: alinear `session(['Auth' => ...])` al mecanismo real de `Authentication` (ver otros tests que golpean `/approve/`).

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter ExternalApprovalsGroupTest`
Expected: FAIL (token de grupo no reconocido → expired).

- [ ] **Step 3: Extender el controller**

En `src/Controller/ExternalApprovalsController.php`:
- Inyectar en `initialize()`: `$this->refundApprovalService = $container->get(RefundApprovalService::class);` (propiedad + `use App\Service\RefundApprovalService;` + `use Cake\ORM\TableRegistry;`).
- En `review()`, tras el bloque multi-aprobador de factura y ANTES del path genérico:
```php
        $groupApproval = $this->refundApprovalService->validateToken($token);
        if ($groupApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($groupApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar este reintegro.');
                $this->set('unauthorized', true);

                return $this->render('expired');
            }
            $refund = TableRegistry::getTableLocator()->get('Refunds')->get(
                $groupApproval->refund_id,
                contain: ['Invoices' => ['Providers'], 'BeneficiaryEmployees', 'BeneficiaryProviders'],
            );
            $this->set(compact('token', 'refund', 'currentUser'));

            return $this->render('review_group');
        }
```
- En `process()`, tras el bloque multi-aprobador de factura y ANTES del path genérico:
```php
        $groupApproval = $this->refundApprovalService->validateToken($token);
        if ($groupApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($groupApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización.');
                $this->set('expired', true);

                return $this->render('expired');
            }
            $result = $this->refundApprovalService->processResponse(
                $token,
                $action,
                $this->request->getData('observations'),
                $this->request->clientIp(),
                $this->request->getHeaderLine('User-Agent'),
            );
            if (!$result->success) {
                $this->Flash->error($result->firstError() ?? 'Error al procesar respuesta');

                return $this->redirect(['action' => 'review', $token]);
            }
            $success = true;
            $this->set(compact('success', 'action'));

            return $this->render('confirmed');
        }
```

- [ ] **Step 4: Escribir `review_group`**

Read `templates/ExternalApprovals/review.php` y crear `templates/ExternalApprovals/review_group.php` (layout `external`): encabezado "Aprobando como {full_name}", datos del reintegro (código, beneficiario, total), tabla de `$refund->invoices` (número, proveedor, monto), y el formulario:
```php
    <div>
        <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>
        <div class="mb-3">
            <label class="input-label">Observaciones (opcional)</label>
            <textarea name="observations" class="form-control" rows="3"></textarea>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve" class="btn btn-primary">
                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar grupo
            </button>
            <button type="submit" name="action" value="reject" class="btn btn-danger">
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar grupo
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
```

- [ ] **Step 5: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter ExternalApprovalsGroupTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php templates/ExternalApprovals/review_group.php tests/TestCase/Controller/ExternalApprovalsGroupTest.php
git commit -m "feat(refunds): aprobación externa del grupo (review/process de reintegro)"
```

---

### Task 11: UI — Presentation (badge), ViewModel y panel de aprobación

**Files:**
- Modify: `src/View/Presentation/RefundPresentation.php` (STATUS_BADGES)
- Modify: `src/ViewModel/RefundEditViewModel.php`
- Modify: `src/Controller/RefundsController.php:_buildEditViewModel`
- Modify: `templates/Refunds/edit.php`
- Create: `templates/element/refund_edit/_approval_panel.php`
- Modify (existente): `tests/TestCase/View/Presentation/RefundPresentationTest.php`

**Interfaces:**
- Consumes: `PipelineColorMap`; `RefundApprovalService::{getCurrentApprovals,getApprovalSummary,hasAnyActiveApprovals}`.
- Produces: `RefundPresentation::STATUS_BADGES['aprobacion'] = 'pill-warning-soft'`; ViewModel expone `currentApprovals`, `approvalSummary`, `approvers`, `canSendLinks`, `hasPendingApprovals`.

- [ ] **Step 1: Añadir el test al archivo EXISTENTE**

`tests/TestCase/View/Presentation/RefundPresentationTest.php` YA EXISTE (5 tests). **Añadir** (no sobrescribir) este método a la clase:

```php
    public function testAprobacionBadgeMatchesColorMap(): void
    {
        $this->assertArrayHasKey(
            \App\Constants\RefundConstants::STATUS_APROBACION,
            \App\View\Presentation\RefundPresentation::STATUS_BADGES,
        );
        $this->assertSame(
            \App\View\Presentation\PipelineColorMap::pill(\App\Constants\RefundConstants::STATUS_APROBACION),
            \App\View\Presentation\RefundPresentation::STATUS_BADGES[\App\Constants\RefundConstants::STATUS_APROBACION],
        );
    }
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter RefundPresentationTest`
Expected: FAIL en el nuevo método (los 5 previos siguen verdes).

- [ ] **Step 3: Añadir el badge**

En `src/View/Presentation/RefundPresentation.php` `STATUS_BADGES` (L17-24), insertar tras AGRUPACION:
```php
        RefundConstants::STATUS_APROBACION        => 'pill-warning-soft',
```

- [ ] **Step 4: Correr — debe pasar**

Run: `vendor/bin/phpunit --filter RefundPresentationTest`
Expected: PASS (los 6).

- [ ] **Step 5: Extender el ViewModel**

En `src/ViewModel/RefundEditViewModel.php`, añadir al FINAL de la lista de params del constructor (para no romper llamadas):
```php
        public array $currentApprovals = [],
        public array $approvalSummary = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
        public array $approvers = [],
        public bool $canSendLinks = false,
        public bool $hasPendingApprovals = false,
```

- [ ] **Step 6: Poblar en `_buildEditViewModel`** (RefundsController L381-416)

Antes del `return`:
```php
        $isAprobacion = $record->status === RefundConstants::STATUS_APROBACION;
        $currentApprovals = $isAprobacion ? $this->approvalService->getCurrentApprovals((int)$record->id) : [];
        $approvalSummary = $this->approvalService->getApprovalSummary((int)$record->id);
        $hasActive = $this->approvalService->hasAnyActiveApprovals((int)$record->id);
        $approvers = $this->fetchTable('Users')->find('list', keyField: 'id', valueField: 'full_name')
            ->where(['active' => true])->toArray();
```
Y pasar al ViewModel (argumentos con nombre):
```php
            currentApprovals: $currentApprovals,
            approvalSummary: $approvalSummary,
            approvers: $approvers,
            canSendLinks: $isAprobacion && !$hasActive,
            hasPendingApprovals: $approvalSummary['pending'] > 0,
```
Verificar `full_name`/`active` en `Users` (ajustar si el valueField real difiere).

- [ ] **Step 7: Panel + enganche en el edit**

Create `templates/element/refund_edit/_approval_panel.php` adaptado del panel de facturas (`templates/Invoices/edit.php` L627-720): chips de aprobadores por estado (`$viewModel->currentApprovals`), `<select name="approver_ids[]" multiple form="sendApprovalLinksForm">` con `$viewModel->approvers`, botón "Enviar links de aprobación" (submit del form oculto) cuando `$viewModel->canSendLinks`, y "Modificar aprobadores" (modal, postea a `modifyApprovers`) cuando ya hay activos.

En `templates/Refunds/edit.php`:
- Añadir a `$sections` (L168-177) una entrada `['key' => 'approval', 'editable' => true]` cuando `$record->status === RefundConstants::STATUS_APROBACION`.
- Renderizar el form oculto `sendApprovalLinksForm` (patrón de facturas L203-211) cuando `$viewModel->canSendLinks`, con `url => ['action' => 'sendApprovalLinks', $record->id]`.
- En el `foreach` de secciones, para `$section['key'] === 'approval'`: `<?= $this->element('refund_edit/_approval_panel', ['viewModel' => $viewModel, 'record' => $record]) ?>`.

- [ ] **Step 8: Smoke + cs-check**

Run: `composer cs-check` (o `composer cs-fix`).
(Render manual opcional vía `php bin/cake server`; la cobertura funcional está en Task 12.)

- [ ] **Step 9: Commit**

```bash
git add src/View/Presentation/RefundPresentation.php src/ViewModel/RefundEditViewModel.php src/Controller/RefundsController.php templates/Refunds/edit.php templates/element/refund_edit/_approval_panel.php tests/TestCase/View/Presentation/RefundPresentationTest.php
git commit -m "feat(refunds): UI del panel de aprobación de grupo (estado 'aprobacion')"
```

---

### Task 12: Test de integración end-to-end + regresión de suite

**Files:**
- Create: `tests/TestCase/Service/Integration/RefundGroupApprovalFlowTest.php`

- [ ] **Step 1: Escribir el flujo completo con factories**

Create `tests/TestCase/Service/Integration/RefundGroupApprovalFlowTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Authorization\AuthorizationFacade;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Constants\RefundConstants;
use App\Service\InvoiceHistoryService;
use App\Service\NotificationService;
use App\Service\RefundApprovalService;
use App\Service\RefundPipelineService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RefundGroupApprovalFlowTest extends TestCase
{
    public function testApproveThenAdvanceMovesChildrenToContabilidad(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $approver = UserFactory::new()->save();
        $operator = UserFactory::new()->save();

        // Aprobación de grupo.
        $approvalSvc = new RefundApprovalService($this->createMock(NotificationService::class));
        $approvalSvc->assignApprovers($refund, [$approver->id], 'https://x', (int)$operator->id);
        $a = TableRegistry::getTableLocator()->get('RefundApprovals')
            ->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $secret = $approvalSvc->applyFreshToken($a);
        TableRegistry::getTableLocator()->get('RefundApprovals')->saveOrFail($a);
        $approvalSvc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');
        $this->assertTrue($approvalSvc->areAllApproved((int)$refund->id));

        // Sin errores de avance (quórum + DIAN OK).
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $auth->method('operableSteps')->willReturn([]);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $this->assertSame([], $pipeline->validateTransitionRequirements($refund));

        // Avance real → contabilidad; hijas propagadas.
        $result = $pipeline->advance($refund, $operator->role_id, $operator->id);
        $this->assertTrue($result->success, implode(' ', (array)$result->errors));

        $reloadedRefund = TableRegistry::getTableLocator()->get('Refunds')->get($refund->id);
        $this->assertSame(RefundConstants::STATUS_CONTABILIDAD, $reloadedRefund->status);
        $child = TableRegistry::getTableLocator()->get('Invoices')->find()->where(['refund_id' => $refund->id])->firstOrFail();
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $child->pipeline_status);
    }

    public function testAdvanceBlockedWithoutQuorum(): void
    {
        $refund = RefundFactory::new(['accrued' => true, 'ready_for_payment' => true])
            ->withStatus(RefundConstants::STATUS_APROBACION)->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'dian_validation' => InvoiceConstants::DIAN_APPROVED])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);
        $pipeline = new RefundPipelineService(new InvoiceHistoryService(), $auth);

        $errors = $pipeline->validateTransitionRequirements($refund);
        $this->assertNotEmpty($errors); // sin aprobadores → gate de quórum bloquea
    }
}
```

- [ ] **Step 2: Correr el test end-to-end**

Run: `vendor/bin/phpunit --filter RefundGroupApprovalFlowTest`
Expected: PASS (ajustar hasta verde).

- [ ] **Step 3: Suite completa (regresión)**

Run: `vendor/bin/phpunit`
Expected: sin regresiones vs baseline (808). Re-correr limpio si hay contaminación entre suites.

- [ ] **Step 4: cs-check**

Run: `composer cs-check`
Expected: limpio.

- [ ] **Step 5: Commit**

```bash
git add tests/TestCase/Service/Integration/RefundGroupApprovalFlowTest.php
git commit -m "test(refunds): flujo end-to-end de aprobación de área en lote"
```

---

## Self-Review (cobertura spec → plan)

| Requisito del spec | Tarea(s) |
|---|---|
| §4.1 enum + `AprobacionState` + registry + step | T3, T8 |
| §4.2 tabla `refund_approvals`, `status` español | T1 |
| §4.3 base + `RefundApprovalService` + DI | T6 |
| §4.3a gate no-bypasseable | T3 (en `AprobacionState::validateAdvance`, invocado siempre por `advance()` vía `RefundTransitionValidator` — desviación consciente vs `denialReasonForAdvance` del spec §4.3a; equivalente y no bypasseable) |
| §4.3b efecto "todos aprueban" + avance manual + propagación hijas | T6, T7 |
| §4.3c gate DIAN por-hija | T2 (`childInvoicesFailingDian`), T3 |
| §4.3d rechazo → regresa `agrupacion` + observación + historial (M6) | T6 |
| §4.4 página externa del grupo | T5, T10 |
| §4.5 filtro doble `GroupedInvoiceService` (PettyCash intacto) | T4 |
| §4.6 STATUSES/labels, Presentation, ViewModel, panel | T3, T11 |
| §4.7 seed `pipeline_permissions` | T8 |
| §5 avance manual | T9 |
| §5 invalidar links al regresar (M1) / supersede individuales al vincular (M2) | T9 |
| §7 criterios de aceptación | T12 + tests por tarea |
| §8 testing con factories | todas |

**Fuera de este plan (van al plan de Anticipos):** legalización de anticipos, `AdvanceLegalizationApprovalService`, reubicación MA-006, interacción RC↔Legalización.

**Riesgos a vigilar en ejecución:** (1) patrón exacto de autenticación en tests de integración (T9/T10) — replicar de tests existentes; (2) columnas reales de `pipeline_permissions` (T8) y `EmailLogConstants::EVENT_LABELS` (T5); (3) `Users.full_name`/`active` como campos (T11); (4) `InvoiceFactory` acepta `dian_validation` como override (T12).
