# Aprobación de área en lote — Anticipos/Legalización — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Insertar un estado `aprobacion` en el pipeline de legalización de anticipos (`validacion → aprobacion → revision_firmas → …`) donde las facturas vinculadas (Legalización y Recibo de Caja, en invoice-`aprobacion`) se aprueban en lote por aprobadores del grupo (multi-aprobador, un link por aprobador, todo-o-nada); al aprobar todos, el operador consolida y las facturas avanzan juntas a invoice-`contabilidad`.

**Architecture:** La legalización (`advance_legalizations`) gana un estado `aprobacion` entre `validacion` y `revision_firmas`. Una tabla nueva `advance_legalization_approvals` (espejo de `refund_approvals`) y un servicio `AdvanceLegalizationApprovalService` (que **reutiliza** la base abstracta `GroupApproval\GroupApprovalService` ya construida en la Fase 1 de Reintegros) gestionan el multi-aprobador. El aprobador aprueba/rechaza vía el `ExternalApprovalsController` existente (extendido con un 4º path de token). El coordinador `AdvanceLegalizationService` es el **OUTLIER** documentado (verbos por outcome, no `advance()/next()`): se añaden dos verbos nuevos — `moveToAprobacion` (validacion→aprobacion) y una repurposición de `moveToRevisionFirmas` (ahora aprobacion→revision_firmas: valida quórum+DIAN, mueve las facturas hijas a invoice-`contabilidad` + `area_approval=Aprobada`, y avanza la legalización). La regla MA-006 ("todas las vinculadas en contabilidad") se **subsume** en la aprobación de grupo: deja de ser precondición de `validacion` y pasa a ser un efecto garantizado del verbo de consolidación.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, MySQL/MariaDB, PHPUnit + **cakephp-fixture-factories**, Migrations (`BaseMigration`).

**Scope:** Solo Anticipos/Legalización. Reutiliza `GroupApprovalService` (ya en `dev`, Fase 1). Reintegros, Caja Menor, Facturas individuales, Novedades y Programación de pagos **no** cambian.

## Global Constraints

- CakePHP 5.3, PHP `>=8.4`. Migraciones extienden `Migrations\BaseMigration` (NUNCA `AbstractMigration`); usar `hasTable()`; FK con tipos exactos (`signed`).
- **FK `user_id` de tablas de aprobación = `ON DELETE RESTRICT`** (lección de Fase 1: se alinea con las FKs de audit del módulo; NO `CASCADE`). FK `advance_legalization_id` = `ON DELETE CASCADE` (fila hija de la legalización).
- Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get('Name')`, nunca `$this->Name`.
- DI: los servicios que consumen otros servicios se registran en `src/Application.php::services()` con `$container->addShared(...)->addArguments([...])`. El patrón `?? new X()` en el constructor **solo** aplica a colaboradores SIN dependencias (p. ej. `AdvanceLegalizationHistoryService`); **nunca** para `NotificationService` (tiene 3 dependencias no-nullable — `new NotificationService()` lanza `ArgumentCountError`).
- Servicios retornan `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`; verificar `->success`. `firstError()` para el primer mensaje.
- Métodos privados con guion bajo: `_buildX()`.
- **TESTS = cakephp-fixture-factories.** El proyecto NO usa fixtures clásicos: `tests/Fixture/` no existe. Los tests con BD **no** declaran `$fixtures`. Sembrar datos con `App\Test\Factory\{AdvanceLegalization,Invoice,User,Role,...}Factory::new([...])->save()`. Tests puros in-memory extienden `PHPUnit\Framework\TestCase`; tests con BD extienden `Cake\TestSuite\TestCase`.
- **Vocabulario de `status` de aprobación = español** (`InvoiceConstants::APPROVER_STATUS_PENDING='Pendiente'`, `APPROVER_STATUS_APPROVED='Aprobada'`, `APPROVER_STATUS_REJECTED='Rechazada'`, `APPROVER_STATUS_SUPERSEDED='Reemplazada'`). Reutilizar estas constantes; NO inventar valores en inglés.
- Slug de estado de pipeline en español sin acento: `aprobacion`. Label visible: `'Aprobación'`.
- Mapeo estado→pill vive SOLO en `PipelineColorMap` (ya mapea `aprobacion` → `pill-warning-soft`/`is-warning`, usado por facturas — **no se toca el mapa**). `AdvancePresentation::STATUS_BADGES` añade la entrada literal correspondiente; `PipelineColorConsistencyTest` la verifica. Prohibido literal inline en el `.php` de la vista.
- Tokens: secreto hex 64 chars (`ApprovalTokenManager::generateSecret()`), hasheado SHA256 en reposo (`token_hash`), TTL `InvoiceConstants::APPROVAL_TOKEN_HOURS` (48h). Consumo con `SELECT … FOR UPDATE` (ya implementado en la base).
- **Split de naming legítimo (NO renombrar):** dir/enum/namespace corto `Advance` (`App\Service\Pipeline\Advance`, `App\Constants\Domain\Advance`); clases/tablas largas `AdvanceLegalization*`. Slug CRUD/permisos `'advances'` ≠ slug pipeline `'legalizations'` (`PipelineStepConstants::PIPELINE_LEGALIZATIONS`). Ambos ejes ya existen; NO tocarlos.
- **Trampa de dos ejes (Anticipos):** el "grupo" es la fila `advance_legalizations` (los aprobadores cuelgan de `advance_legalization_id`); las facturas hijas cuelgan de `invoices.advance_id = leg.advance_invoice_id` (el id del **Invoice** del anticipo), **NO** de `leg.id`. Toda query de hijas usa `advance_id = leg->advance_invoice_id`.
- Correr suite: `vendor/bin/phpunit` (timeout 300s). Migraciones: `php bin/cake.php migrations migrate` (en test: `--connection test`). Estilo: `composer cs-check` / `composer cs-fix`.
- **Atribución OFF:** los commits NO llevan `Co-Authored-By`.
- **DB de test:** credenciales en `config/.env` (DATABASE_TEST_*). Si el sandbox reporta `Access denied`, correr los tests puros (in-memory) y diferir los con-DB al checkpoint del controller.

**Orden de build (dependencias):** tabla+factory → guard → enum+state+registry(+update tests) → filtro+drop-MA-006 → notificación → service+DI → coordinador (verbos) → RBAC → controller+rutas → external → UI → integración.

**Baseline de suite:** verde tras Fase 1 (~843 tests). Registrar el número real al inicio (Task 12) y verificar 0 regresiones al final.

---

### Task 1: Tabla `advance_legalization_approvals` (migración + entity + table + factory)

**Files:**
- Create: `config/Migrations/20260703100000_CreateAdvanceLegalizationApprovals.php`
- Create: `src/Model/Entity/AdvanceLegalizationApproval.php`
- Create: `src/Model/Table/AdvanceLegalizationApprovalsTable.php`
- Create: `tests/Factory/AdvanceLegalizationApprovalFactory.php`
- Test: `tests/TestCase/Model/Table/AdvanceLegalizationApprovalsTableTest.php`

**Interfaces:**
- Produces: tabla `advance_legalization_approvals` (`advance_legalization_id`, `user_id`, `token_hash`, `token_expires_at`, `status`, `responded_at`, `observations`, `ip_address`, `user_agent`, `created`, `modified`); Entity `AdvanceLegalizationApproval` (`token_hash` hidden); Table `AdvanceLegalizationApprovalsTable` (`belongsTo AdvanceLegalizations, Users`); `AdvanceLegalizationApprovalFactory`.

- [ ] **Step 1: Escribir la migración**

Create `config/Migrations/20260703100000_CreateAdvanceLegalizationApprovals.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateAdvanceLegalizationApprovals extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('advance_legalization_approvals')) {
            $table = $this->table('advance_legalization_approvals');
            $table
                ->addColumn('advance_legalization_id', 'integer', ['null' => false, 'signed' => true])
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
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_ala_token_hash'])
                ->addIndex(['advance_legalization_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('advance_legalization_id', 'advance_legalizations', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('advance_legalization_approvals')) {
            $this->table('advance_legalization_approvals')->drop()->save();
        }
    }
}
```

- [ ] **Step 2: Correr la migración**

Run: `php bin/cake.php migrations migrate`
Expected: crea `advance_legalization_approvals` sin error. (En test: `php bin/cake.php migrations migrate --connection test`.)

- [ ] **Step 3: Escribir Entity y Table**

Create `src/Model/Entity/AdvanceLegalizationApproval.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AdvanceLegalizationApproval extends Entity
{
    protected array $_accessible = [
        'advance_legalization_id' => true,
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

Create `src/Model/Table/AdvanceLegalizationApprovalsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class AdvanceLegalizationApprovalsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('advance_legalization_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('AdvanceLegalizations', ['foreignKey' => 'advance_legalization_id', 'joinType' => 'INNER']);
        $this->belongsTo('Users', ['foreignKey' => 'user_id', 'joinType' => 'INNER']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('advance_legalization_id')
            ->requirePresence('advance_legalization_id', 'create')
            ->notEmptyString('advance_legalization_id');
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

- [ ] **Step 4: Escribir el factory** (patrón de `tests/Factory/RefundApprovalFactory.php`)

Create `tests/Factory/AdvanceLegalizationApprovalFactory.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Factory de AdvanceLegalizationApproval. El caller provee
 * advance_legalization_id y user_id (FK NOT NULL).
 */
class AdvanceLegalizationApprovalFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'AdvanceLegalizationApprovals';
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

Create `tests/TestCase/Model/Table/AdvanceLegalizationApprovalsTableTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\AdvanceLegalizationApprovalsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalsTableTest extends TestCase
{
    public function testTableConfiguration(): void
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $this->assertInstanceOf(AdvanceLegalizationApprovalsTable::class, $table);
        $this->assertSame('advance_legalization_approvals', $table->getTable());
        $this->assertTrue($table->hasAssociation('AdvanceLegalizations'));
        $this->assertTrue($table->hasAssociation('Users'));
    }

    public function testTokenHashIsHidden(): void
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $entity = $table->newEntity([
            'advance_legalization_id' => 1,
            'user_id' => 1,
            'status' => 'Pendiente',
            'token_hash' => 'abc',
        ]);
        $this->assertArrayNotHasKey('token_hash', $entity->toArray());
    }
}
```

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationApprovalsTableTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add config/Migrations/20260703100000_CreateAdvanceLegalizationApprovals.php src/Model/Entity/AdvanceLegalizationApproval.php src/Model/Table/AdvanceLegalizationApprovalsTable.php tests/Factory/AdvanceLegalizationApprovalFactory.php tests/TestCase/Model/Table/AdvanceLegalizationApprovalsTableTest.php
git commit -m "feat(advances): tabla advance_legalization_approvals (migración, entity, table, factory)"
```

---

### Task 2: `AdvanceLegalizationApprovalGuard` (quórum + DIAN)

**Files:**
- Create: `src/Service/AdvanceLegalizationApprovalGuard.php`
- Test: `tests/TestCase/Service/AdvanceLegalizationApprovalGuardTest.php`

**Interfaces:**
- Consumes: tabla `advance_legalization_approvals` (Task 1); `InvoiceConstants::{APPROVER_STATUSES_ACTIVE, APPROVER_STATUS_APPROVED, DIAN_APPROVED, ADVANCE_LINKABLE_DOCTYPES}`; factories.
- Produces: `AdvanceLegalizationApprovalGuard::{activeApproverCount(int):int, approvedCount(int):int, allApproved(int $legId):bool, childInvoicesFailingDian(int $advanceInvoiceId):array}`. Clase **NO `final`** (PHPUnit mockea el guard en los tests puros del State).

> **Nota de diseño (dos ejes):** `allApproved`/`activeApproverCount`/`approvedCount` reciben el **id de la legalización** (`advance_legalization_id`, las filas de aprobación). `childInvoicesFailingDian` recibe el **id del Invoice del anticipo** (`advance_invoice_id`, las facturas hijas via `invoices.advance_id`). No confundir.

- [ ] **Step 1: Escribir el test que falla**

Create `tests/TestCase/Service/AdvanceLegalizationApprovalGuardTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AdvanceLegalizationApprovalGuard;
use App\Test\Factory\AdvanceLegalizationApprovalFactory;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\UserFactory;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalGuardTest extends TestCase
{
    public function testAllApprovedTrueWhenEveryActiveIsApproved(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])->approved()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u2->id])->approved()->save();

        $this->assertTrue((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }

    public function testAllApprovedFalseWithPending(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $u1 = UserFactory::new()->save();
        $u2 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])->approved()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u2->id])->save();

        $this->assertFalse((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }

    public function testAllApprovedFalseWithNoApprovers(): void
    {
        $leg = AdvanceLegalizationFactory::new()->save();
        $this->assertFalse((new AdvanceLegalizationApprovalGuard())->allApproved((int)$leg->id));
    }
}
```

> Si `AdvanceLegalizationFactory` requiere un `advance_invoice_id` (FK a un Invoice-anticipo), usar el patrón `->withRequiredParents()` del factory existente o sembrar el Invoice antes. Verificar la firma en `tests/Factory/AdvanceLegalizationFactory.php` antes de correr.

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationApprovalGuardTest`
Expected: FAIL — clase inexistente.

- [ ] **Step 3: Escribir el guard**

Create `src/Service/AdvanceLegalizationApprovalGuard.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;

/**
 * Consultas ligeras sobre advance_legalization_approvals (quórum) e invoices
 * hijas (DIAN) para el AprobacionState puro del pipeline de legalización.
 * Espejo de RefundApprovalGuard. NO final: PHPUnit mockea el guard.
 */
class AdvanceLegalizationApprovalGuard
{
    public function activeApproverCount(int $legalizationId): int
    {
        return TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals')->find()
            ->where([
                'advance_legalization_id' => $legalizationId,
                'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
            ])
            ->count();
    }

    public function approvedCount(int $legalizationId): int
    {
        return TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals')->find()
            ->where([
                'advance_legalization_id' => $legalizationId,
                'status' => InvoiceConstants::APPROVER_STATUS_APPROVED,
            ])
            ->count();
    }

    /** True si hay ≥1 aprobador activo y todos están en 'Aprobada'. */
    public function allApproved(int $legalizationId): bool
    {
        $active = $this->activeApproverCount($legalizationId);

        return $active > 0 && $active === $this->approvedCount($legalizationId);
    }

    /**
     * Facturas hijas (Legalización / Recibo de Caja) sin DIAN aprobada.
     * Recibe el id del Invoice del anticipo (advance_invoice_id).
     *
     * @return array<string> Números de factura o #id de las ofensoras.
     */
    public function childInvoicesFailingDian(int $advanceInvoiceId): array
    {
        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                'dian_validation !=' => InvoiceConstants::DIAN_APPROVED,
            ])
            ->all()
            ->map(fn($i) => $i->invoice_number ?: ('#' . $i->id))
            ->toList();
    }
}
```

- [ ] **Step 4: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationApprovalGuardTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Service/AdvanceLegalizationApprovalGuard.php tests/TestCase/Service/AdvanceLegalizationApprovalGuardTest.php
git commit -m "feat(advances): AdvanceLegalizationApprovalGuard (quórum + DIAN)"
```

---

### Task 3: Estado `aprobacion` — enum, constantes, `AprobacionState`, registry (+ actualizar tests existentes)

**Files:**
- Modify: `src/Constants/Domain/Advance/PipelineStatus.php`
- Modify: `src/Constants/AdvanceConstants.php`
- Create: `src/Service/Pipeline/Advance/State/AprobacionState.php`
- Modify: `src/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistry.php`
- Modify (tests existentes): `tests/TestCase/Constants/Domain/Advance/PipelineStatusTest.php`, `tests/TestCase/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistryTest.php`, `tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php`, `tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php`
- Test (nuevos): `tests/TestCase/Service/Pipeline/Advance/State/AprobacionStateTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationApprovalGuard` (Task 2); `AdvanceLegalizationPipelineState` interface.
- Produces: `PipelineStatus::APROBACION` (`'aprobacion'`), en `next()/previous()/label()`; `AdvanceConstants::STATUS_APROBACION`, en `PIPELINE_STATUSES`, las 3 variantes por caso, y `STATUS_LABELS`; `AprobacionState implements AdvanceLegalizationPipelineState` con gate quórum + DIAN (recibe el guard); registrado en `AdvanceLegalizationPipelineStateRegistry` (8 estados).

> **Importante (evita ventana de tests rota):** enum, State y registro van en ESTA tarea juntos, porque `AdvanceLegalizationPipelineStateRegistryTest::testGetResolvesEveryEnumCase` itera `PipelineStatus::cases()` contra el registry. Añadir el case sin registrar el State dejaría ese test en fatal (índice indefinido en `get()`).

- [ ] **Step 1: Escribir/actualizar los tests que fallan**

Create `tests/TestCase/Service/Pipeline/Advance/State/AprobacionStateTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\Pipeline\Advance\State\AprobacionState;
use PHPUnit\Framework\TestCase;

final class AprobacionStateTest extends TestCase
{
    public function testTransitions(): void
    {
        $state = new AprobacionState($this->createStub(AdvanceLegalizationApprovalGuard::class));
        $this->assertSame(PipelineStatus::APROBACION, $state->getStatus());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, $state->getNextStatus());
        $this->assertSame(PipelineStatus::VALIDACION, $state->getPreviousStatus());
    }

    public function testValidateAdvanceBlocksWithoutQuorum(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(false);
        $guard->method('childInvoicesFailingDian')->willReturn([]);

        $errors = (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aprobación', mb_strtolower($errors[0]));
    }

    public function testValidateAdvanceListsDianOffenders(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childInvoicesFailingDian')->willReturn(['F-9']);

        $errors = (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('F-9', $errors[0]);
    }

    public function testValidateAdvancePassesWithQuorumAndDian(): void
    {
        $guard = $this->createMock(AdvanceLegalizationApprovalGuard::class);
        $guard->method('allApproved')->willReturn(true);
        $guard->method('childInvoicesFailingDian')->willReturn([]);

        $this->assertSame([], (new AprobacionState($guard))
            ->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5])));
    }
}
```

Actualizar `tests/TestCase/Constants/Domain/Advance/PipelineStatusTest.php`:
- `testValues` (tras la aserción de `VALIDACION`): añadir `$this->assertSame('aprobacion', PipelineStatus::APROBACION->value);`
- `testLabels` (tras la aserción de `VALIDACION`): añadir `$this->assertSame('Aprobación', PipelineStatus::APROBACION->label());`
- `testNextLinearPath` (L35): reemplazar `$this->assertSame(PipelineStatus::REVISION_FIRMAS, PipelineStatus::VALIDACION->next());` por:
```php
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::VALIDACION->next());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, PipelineStatus::APROBACION->next());
```
- `testPreviousProvidesRegression` (L58): reemplazar `$this->assertSame(PipelineStatus::VALIDACION, PipelineStatus::REVISION_FIRMAS->previous());` por:
```php
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::REVISION_FIRMAS->previous());
        $this->assertSame(PipelineStatus::VALIDACION, PipelineStatus::APROBACION->previous());
```

Actualizar `tests/TestCase/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistryTest.php`:
- Renombrar `testRegistryHasSevenStates` → `testRegistryHasEightStates` y `assertCount(8, $registry->all());`
- `testGetResolvesEveryEnumCase` no cambia (pasa una vez el registry incluya `AprobacionState`).

Actualizar `tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php`:
- `testRevisionFirmas` (L37): reemplazar `$this->assertSame(PipelineStatus::VALIDACION, $s->getPreviousStatus());` por `$this->assertSame(PipelineStatus::APROBACION, $s->getPreviousStatus());`

Actualizar `tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php`:
- `testStatusTransitions` (L26): reemplazar `$this->assertSame(PipelineStatus::REVISION_FIRMAS, $state->getNextStatus());` por `$this->assertSame(PipelineStatus::APROBACION, $state->getNextStatus());`
- (Los tests de contenido `testFlagsFirstInvoiceNotInContabilidad…` / `testPassesWhenInvoicesInContabilidad…` se actualizan en **Task 4** cuando se remueve MA-006. No tocarlos en esta tarea.)

- [ ] **Step 2: Correr los tests — deben fallar**

Run: `vendor/bin/phpunit --filter AprobacionStateTest`
Expected: FAIL (enum sin APROBACION / clase inexistente).

- [ ] **Step 3: Añadir el case al enum**

En `src/Constants/Domain/Advance/PipelineStatus.php`:
- Cases: insertar `case APROBACION = 'aprobacion';` tras `case VALIDACION = 'validacion';`.
- `label()`: insertar `self::APROBACION => 'Aprobación',` tras la línea de `VALIDACION`.
- `next()`: reemplazar `self::VALIDACION => self::REVISION_FIRMAS,` por:
```php
            self::VALIDACION => self::APROBACION,
            self::APROBACION => self::REVISION_FIRMAS,
```
- `previous()`: reemplazar `self::REVISION_FIRMAS => self::VALIDACION,` por:
```php
            self::APROBACION => self::VALIDACION,
            self::REVISION_FIRMAS => self::APROBACION,
```

- [ ] **Step 4: Constante, arrays y labels en `AdvanceConstants`**

En `src/Constants/AdvanceConstants.php`:
- Tras `STATUS_VALIDACION`: `public const STATUS_APROBACION = PipelineStatus::APROBACION->value;`
- En `PIPELINE_STATUSES` insertar `self::STATUS_APROBACION,` entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`.
- En `PIPELINE_STATUSES_EXACTO` y `PIPELINE_STATUSES_FALTANTE` insertar `self::STATUS_APROBACION,` entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`. (`PIPELINE_STATUSES_SOBRANTE` = `PIPELINE_STATUSES`, ya cubierta.)
- En `STATUS_LABELS` insertar `self::STATUS_APROBACION => 'Aprobación',` entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`.

- [ ] **Step 5: Escribir `AprobacionState`**

Create `src/Service/Pipeline/Advance/State/AprobacionState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Model\Entity\AdvanceLegalization;
use App\Service\AdvanceLegalizationApprovalGuard;
use App\Service\Pipeline\Advance\AdvanceLegalizationPipelineState;

/**
 * Estado `aprobacion` de la legalización: puerta de la aprobación de área en
 * lote. El avance a `revision_firmas` exige quórum (todos los aprobadores del
 * grupo en 'Aprobada') y DIAN aprobada en cada factura hija. El movimiento de
 * las hijas a invoice-contabilidad lo hace el verbo del coordinador
 * (AdvanceLegalizationService::moveToRevisionFirmas), no este State puro.
 */
final class AprobacionState implements AdvanceLegalizationPipelineState
{
    private AdvanceLegalizationApprovalGuard $guard;

    public function __construct(?AdvanceLegalizationApprovalGuard $guard = null)
    {
        $this->guard = $guard ?? new AdvanceLegalizationApprovalGuard();
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

    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        if (!$this->guard->allApproved((int)$leg->id)) {
            $errors[] = 'La aprobación de área del grupo está pendiente: todos los aprobadores deben aprobar.';
        }
        $failingDian = $this->guard->childInvoicesFailingDian((int)$leg->advance_invoice_id);
        if (!empty($failingDian)) {
            $errors[] = 'Validación DIAN pendiente en: ' . implode(', ', $failingDian);
        }

        return $errors;
    }
}
```

- [ ] **Step 6: Registrar en el registry**

En `src/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistry.php`:
- Añadir `use App\Service\Pipeline\Advance\State\AprobacionState;`
- Añadir param al constructor: `?AprobacionState $aprobacion = null,` tras `?ValidacionState $validacion = null,`.
- En `$list` insertar `$aprobacion ?? new AprobacionState(),` tras `$validacion ?? new ValidacionState(),`.

- [ ] **Step 7: Correr los tests afectados — deben pasar**

Run: `vendor/bin/phpunit --filter AprobacionStateTest`
Run: `vendor/bin/phpunit --filter "Advance"`
Expected: PASS. (`PipelineStatusTest`, `AdvanceLegalizationPipelineStateRegistryTest`, `AdvanceStatesTest`, `ValidacionStateTest::testStatusTransitions` verdes; los tests de contenido de MA-006 de `ValidacionStateTest` siguen verdes porque aún no se remueve MA-006 — se actualizan en Task 4.)

Además, **correr la suite completa** tras esta tarea (esta es la primera que muta `AdvanceConstants::PIPELINE_STATUSES` + enum + labels, con impacto potencial cross-módulo en cualquier consumidor de esos arrays):
Run: `vendor/bin/phpunit`
Expected: 0 failures / 0 errors vs. baseline. Si algún test de otro módulo asume el conteo/orden viejo de `PIPELINE_STATUSES`/labels de anticipos, corregirlo aquí (append/insert-aware), no en Task 12.

- [ ] **Step 8: Commit**

```bash
git add src/Constants/Domain/Advance/PipelineStatus.php src/Constants/AdvanceConstants.php src/Service/Pipeline/Advance/State/AprobacionState.php src/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistry.php tests/TestCase/Constants/Domain/Advance/PipelineStatusTest.php tests/TestCase/Service/Pipeline/Advance/AdvanceLegalizationPipelineStateRegistryTest.php tests/TestCase/Service/Pipeline/Advance/State/AdvanceStatesTest.php tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php tests/TestCase/Service/Pipeline/Advance/State/AprobacionStateTest.php
git commit -m "feat(advances): estado 'aprobacion' (enum, AprobacionState, registry, gate quórum+DIAN)"
```

---

### Task 4: Vincular en `aprobacion` + subsumir MA-006 (filtro doble + ValidacionState)

**Files:**
- Modify: `src/Controller/AdvancesController.php` (candidate query `:417-426`)
- Modify: `src/Service/AdvanceLegalizationService.php` (`linkInvoices` `:138-147`)
- Modify: `src/Service/Pipeline/Advance/State/ValidacionState.php` (remover el bloque MA-006)
- Modify: `tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php` (actualizar tests MA-006)
- Test (nuevo, con DB): `tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php`

**Interfaces:**
- Consumes: `InvoiceConstants::{STATUS_APROBACION, DOCTYPE_LEGALIZACION, DOCTYPE_RECIBO_CAJA}`.
- Produces: candidatos y enforcement de `linkInvoices` exigen ambos tipos (`Legalización` + `Recibo de Caja`) en invoice-`aprobacion` y sin vincular. `ValidacionState::validateAdvance` deja de exigir MA-006 (solo ≥1 vinculada + PDF de relación).

> **Trampa (§4.5 del spec):** hay que cambiar **los dos extremos**. Si solo se cambia el controller, `linkInvoices` hace no-op silencioso (su `updateAll` con la condición vieja no matchea nada). Si solo se cambia `linkInvoices`, los candidatos mostrados no coinciden con lo que se puede vincular.

> **Decisión de paridad — supersesión de aprobadores individuales de las hijas (§5, M2):** el spec §5 dice que al vincular una factura al grupo, sus `invoice_approvals` individuales activos deberían marcarse `Reemplazada` y su "enviar enlaces" individual quedar desactivado. En Fase 1 (Reintegros) esto **no** se implementó a nivel de hija (el `regressStatus` de Refunds solo hace `supersedeAll` de las aprobaciones **de grupo**, no de las individuales de cada factura). **Decisión para este plan:** mantener **paridad exacta con Fase 1** — NO superseder los `invoice_approvals` individuales de las hijas al vincular. Impacto real bajo (Legalización/RC rara vez llevan aprobadores individuales). Si el usuario quiere cerrar este hueco, debe hacerse en **ambos** módulos (Reintegros y Anticipos) en un cambio de paridad aparte, no aquí. Registrado en "Notas de ejecución".

- [ ] **Step 1: Escribir el test que falla** (con DB)

Create `tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationLinkFilterTest extends TestCase
{
    private function _service(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            EventManager::instance(),
            new \App\Service\AdvanceLegalizationHistoryService(),
            new \App\Service\AdvanceLegalizationDocumentService(),
        );
    }

    public function testLinksLegalizacionInvoiceInAprobacion(): void
    {
        // Anticipo (Invoice) pagado + su legalización en validacion.
        $anticipo = \App\Test\Factory\InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = \App\Test\Factory\AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        // status es non-accessible: setear directo.
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        $legalizacion = \App\Test\Factory\InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$legalizacion->id], (int)$anticipo->registered_by ?: 1);
        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['linked']);

        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($legalizacion->id);
        $this->assertSame((int)$anticipo->id, (int)$reloaded->advance_id);
    }

    public function testDoesNotLinkLegalizacionInvoiceInContabilidad(): void
    {
        $anticipo = \App\Test\Factory\InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
        ])->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = \App\Test\Factory\AdvanceLegalizationFactory::new([
            'advance_invoice_id' => $anticipo->id,
        ])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        $legalizacion = \App\Test\Factory\InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
        ])->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $result = $this->_service()->linkInvoices($leg, [(int)$legalizacion->id], 1);
        // No-op: la factura en contabilidad ya no es vinculable.
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['linked']);
    }
}
```

> Ajustar el constructor de `_service()` a la firma real de `AdvanceLegalizationService::__construct` (ver Task 7 / archivo actual): `(EventManagerInterface $events, AdvanceLegalizationHistoryService $historyService, AdvanceLegalizationDocumentService $documentService, ?AdvanceLegalizationPipelineStateRegistry $stateRegistry = null)`. Simplificar el helper si el `DocumentService` no requiere args.

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationLinkFilterTest`
Expected: FAIL — `testLinksLegalizacionInvoiceInAprobacion` falla (0 linked) porque la condición actual sólo vincula `Legalización` en cualquier estado pero el enforcement aún no exige `aprobacion` de forma consistente / `testDoesNotLink…` puede pasar por la razón equivocada. (El objetivo es dejar ambos en verde con la semántica nueva.)

- [ ] **Step 3: Cambiar el enforcement en `linkInvoices`**

En `src/Service/AdvanceLegalizationService.php`, dentro de `linkInvoices` (`:135-148`), reemplazar el `updateAll` de condición:

```php
                $count = $invoices->updateAll(
                    ['advance_id' => $leg->advance_invoice_id],
                    [
                        'id IN' => $invoiceIds,
                        'advance_id IS' => null,
                        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
                        'document_type IN' => [
                            InvoiceConstants::DOCTYPE_LEGALIZACION,
                            InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                        ],
                    ],
                );
```

- [ ] **Step 4: Cambiar el query de candidatos en el controller**

En `src/Controller/AdvancesController.php`, `linkCandidates`, reemplazar `$conditions` (`:417-426`):

```php
        $conditions = [
            'Invoices.advance_id IS' => null,
            'Invoices.pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'Invoices.document_type IN' => [
                InvoiceConstants::DOCTYPE_LEGALIZACION,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            ],
        ];
```

- [ ] **Step 5: Remover MA-006 de `ValidacionState`**

En `src/Service/Pipeline/Advance/State/ValidacionState.php`, en `validateAdvance`, **eliminar** el bloque del comentario `// MA-006 …` y su `foreach` (`:48-57`). El método queda:

```php
    public function validateAdvance(AdvanceLegalization $leg): array
    {
        $errors = [];
        $linked = $this->guard->linkedLegalizationInvoices((int)$leg->advance_invoice_id);

        if (count($linked) === 0) {
            $errors[] = 'Vincule al menos una factura antes de avanzar.';
        }

        if (!$this->guard->hasPendingRelationDocument((int)$leg->id)) {
            $errors[] = 'Debe adjuntar la relación de facturas (PDF).';
        }

        return $errors;
    }
```

- [ ] **Step 6: Actualizar `ValidacionStateTest` (MA-006 removida)**

En `tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php`:
- **Eliminar** `testFlagsFirstInvoiceNotInContabilidadAndBreaks` (la regla MA-006 ya no vive aquí; se prueba en `AprobacionStateTest` (DIAN) y en Task 7 (movimiento)).
- `testPassesWhenInvoicesInContabilidadAndDocumentPresent`: renombrar a `testPassesWhenInvoicesLinkedAndDocumentPresent` y quitar la dependencia del estado `contabilidad` — la factura sembrada puede estar en cualquier estado (usar `InvoiceConstants::STATUS_APROBACION` para reflejar el flujo nuevo). Las aserciones de `linkedLegalizationInvoices`/`hasPendingRelationDocument` no cambian; sigue esperando `assertSame([], $errors)`.

- [ ] **Step 7: Correr tests — deben pasar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationLinkFilterTest`
Run: `vendor/bin/phpunit --filter ValidacionStateTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/AdvancesController.php src/Service/AdvanceLegalizationService.php src/Service/Pipeline/Advance/State/ValidacionState.php tests/TestCase/Service/Pipeline/Advance/State/ValidacionStateTest.php tests/TestCase/Service/AdvanceLegalizationLinkFilterTest.php
git commit -m "feat(advances): vincular en 'aprobacion' + subsumir MA-006 en la aprobación de grupo"
```

---

### Task 5: Email de solicitud de aprobación de grupo (Anticipo)

**Files:**
- Modify: `src/Constants/EmailLogConstants.php`
- Modify: `src/Service/NotificationService.php`
- Create: `templates/email/html/advance_approval_request.php`
- Test: `tests/TestCase/Service/NotificationServiceAdvanceTest.php`

**Interfaces:**
- Produces: `NotificationService::sendAdvanceLegalizationApprovalLinkNotification(AdvanceLegalization $leg, string $approvalUrl, int $approverUserId, ?int $createdBy = null): void`; constantes `EVENT_ADVANCE_APPROVAL_REQUEST`, `ENTITY_ADVANCE_LEGALIZATION` + su label.

- [ ] **Step 1: Añadir constantes de email log**

Read `src/Constants/EmailLogConstants.php` (bloque `EVENT_*` / `ENTITY_*` / `EVENT_LABELS`). Añadir:
```php
    public const EVENT_ADVANCE_APPROVAL_REQUEST = 'advance_approval_request';
    public const ENTITY_ADVANCE_LEGALIZATION = 'advance_legalization';
```
Y en `EVENT_LABELS` añadir `self::EVENT_ADVANCE_APPROVAL_REQUEST => 'Solicitud de aprobación (Anticipo)',` (seguir el formato exacto de las entradas vecinas).

- [ ] **Step 2: Escribir el test que falla**

Create `tests/TestCase/Service/NotificationServiceAdvanceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationServiceAdvanceTest extends TestCase
{
    public function testMethodExists(): void
    {
        $this->assertTrue(method_exists(NotificationService::class, 'sendAdvanceLegalizationApprovalLinkNotification'));
    }
}
```

- [ ] **Step 3: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter NotificationServiceAdvanceTest`
Expected: FAIL.

- [ ] **Step 4: Añadir el método** (espejo de `sendRefundApprovalLinkNotification`)

En `src/Service/NotificationService.php` añadir `use App\Model\Entity\AdvanceLegalization;` (si falta) y el método. El anticipo (Invoice) se carga por `advance_invoice_id` para las variables de display:

```php
    /**
     * Envía el link de aprobación de un grupo (Legalización de Anticipo) a un aprobador.
     * Espejo de sendRefundApprovalLinkNotification a nivel de grupo.
     */
    public function sendAdvanceLegalizationApprovalLinkNotification(
        AdvanceLegalization $leg,
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

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees']);
        $code = $anticipo->invoice_number ?: ('#' . $anticipo->id);
        $beneficiary = $anticipo->provider->name ?? ($anticipo->employee->full_name ?? '—');
        $subject = "SPI-COPCSA - Solicitud de Aprobación: Legalización de Anticipo {$code}";

        foreach ($recipients as $recipient) {
            if (empty($recipient->email)) {
                throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
            }

            $viewVars = [
                'advanceCode' => $code,
                'beneficiaryName' => $beneficiary,
                'amount' => $anticipo->amount,
                'approvalUrl' => $approvalUrl,
                'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
            ];

            $this->deliverWithLog(
                eventType: EmailLogConstants::EVENT_ADVANCE_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_ADVANCE_LEGALIZATION,
                entityId: (int)$leg->id,
                to: $recipient->email,
                subject: $subject,
                template: 'advance_approval_request',
                viewVars: $viewVars,
                layout: 'default',
                createdBy: $createdBy,
            );

            $this->logger->info('approval_link_sent', [
                'recipient' => $recipient->email,
                'advance_legalization_id' => $leg->id,
                'context' => 'advance_legalization',
            ]);
        }
    }
```

> Verificar que `NotificationService` ya importa `Cake\ORM\TableRegistry`; si no, añadir el `use`.

- [ ] **Step 5: Escribir la plantilla de correo**

Read `templates/email/html/refund_approval_request.php` (creada en Fase 1) y crear `templates/email/html/advance_approval_request.php` adaptándola: variables `$advanceCode`, `$beneficiaryName`, `$amount`, `$approvalUrl`, `$recipientName`; texto "Reintegro" → "Legalización de Anticipo"; botón a `<?= h($approvalUrl) ?>`; conservar la mención de validez 48h.

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter NotificationServiceAdvanceTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Constants/EmailLogConstants.php src/Service/NotificationService.php templates/email/html/advance_approval_request.php tests/TestCase/Service/NotificationServiceAdvanceTest.php
git commit -m "feat(advances): notificación de solicitud de aprobación de grupo"
```

---

### Task 6: `AdvanceLegalizationApprovalService` + registro DI

**Files:**
- Create: `src/Service/AdvanceLegalizationApprovalService.php`
- Modify: `src/Application.php` (registro DI)
- Test: `tests/TestCase/Service/AdvanceLegalizationApprovalServiceTest.php`

**Interfaces:**
- Consumes: base `App\Service\GroupApproval\GroupApprovalService` (ya en `dev`); `advance_legalization_approvals`; `InvoiceConstants::{APPROVAL_APPROVED}`; `AdvanceConstants::{STATUS_APROBACION, STATUS_VALIDACION}`; `NotificationService::sendAdvanceLegalizationApprovalLinkNotification`; `AdvanceLegalizationHistoryService`; `ApprovalConstants::ACTION_*` (vía la base).
- Produces: `AdvanceLegalizationApprovalService extends GroupApprovalService` — implementa los abstractos `tableName()='AdvanceLegalizationApprovals'`, `fkField()='advance_legalization_id'`, `notifyApprover(...)`, `onAllApproved(int $legId, int $approverUserId)` (setea `area_approval=Aprobada` en las facturas hijas), `onRejected(int $legId, int $approverUserId, ?string $obs)` (regresa el leg `aprobacion→validacion` + audit). Registrado en DI con `NotificationService` + `AdvanceLegalizationHistoryService`.

> **Efecto de dominio (dos ejes):** `onAllApproved($legId, …)` recibe el id de la **legalización**; carga el leg para obtener `advance_invoice_id` y actualiza las facturas hijas por `advance_id`. El **movimiento a invoice-contabilidad** NO ocurre aquí: lo hace el verbo de consolidación (Task 7) cuando el operador avanza manualmente (§5 del spec: avance manual). `onAllApproved` sólo marca `area_approval` (paridad con Reintegros).

- [ ] **Step 1: Escribir el test que falla** (con DB)

Create `tests/TestCase/Service/AdvanceLegalizationApprovalServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\ApprovalConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationApprovalServiceTest extends TestCase
{
    private function _service(): AdvanceLegalizationApprovalService
    {
        return new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
    }

    private function _legInAprobacion(): object
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        return $leg;
    }

    public function testAllApprovedSetsAreaApprovalOnChildren(): void
    {
        $leg = $this->_legInAprobacion();
        $child = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $leg->advance_invoice_id,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $u1 = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($leg, [$u1->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);
        $svc->processResponse($secret, ApprovalConstants::ACTION_APPROVE, null, '127.0.0.1', 'phpunit');

        $this->assertTrue($svc->areAllApproved((int)$leg->id));
        $reloaded = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloaded->area_approval);
    }

    public function testRejectRegressesLegToValidacion(): void
    {
        $leg = $this->_legInAprobacion();
        $u1 = UserFactory::new()->save();
        $svc = $this->_service();
        $svc->assignApprovers($leg, [$u1->id], 'https://x', (int)$u1->id);

        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);
        $svc->processResponse($secret, ApprovalConstants::ACTION_REJECT, 'faltan soportes', '127.0.0.1', 'phpunit');

        $reloaded = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $reloaded->status);
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationApprovalServiceTest`
Expected: FAIL — clase inexistente.

- [ ] **Step 3: Escribir `AdvanceLegalizationApprovalService`**

Create `src/Service/AdvanceLegalizationApprovalService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\GroupApproval\GroupApprovalService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Aprobación de área en lote para Legalización de Anticipos
 * (tabla advance_legalization_approvals). Reutiliza la mecánica multi-aprobador
 * de GroupApprovalService; sólo aporta los efectos de dominio.
 */
final class AdvanceLegalizationApprovalService extends GroupApprovalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ?AdvanceLegalizationHistoryService $legHistory = null,
    ) {
        parent::__construct();
    }

    protected function tableName(): string
    {
        return 'AdvanceLegalizationApprovals';
    }

    protected function fkField(): string
    {
        return 'advance_legalization_id';
    }

    protected function notifyApprover(object $entity, string $url, int $userId, int $createdBy): void
    {
        $this->notificationService->sendAdvanceLegalizationApprovalLinkNotification($entity, $url, $userId, $createdBy);
    }

    /**
     * Todos aprobaron: marca area_approval=Aprobada en las facturas hijas
     * (por advance_id = advance_invoice_id del leg). El movimiento a
     * invoice-contabilidad lo hace el verbo de consolidación (avance manual).
     */
    protected function onAllApproved(int $entityId, int $approverUserId): void
    {
        $leg = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($entityId);
        TableRegistry::getTableLocator()->get('Invoices')->updateAll(
            ['area_approval' => InvoiceConstants::APPROVAL_APPROVED, 'area_approval_date' => new DateTime()],
            [
                'advance_id' => $leg->advance_invoice_id,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
            ],
        );
    }

    /**
     * Un aprobador rechazó: regresa el leg aprobacion→validacion y audita.
     * Los links pendientes ya los invalida la base (processResponse).
     */
    protected function onRejected(int $entityId, int $approverUserId, ?string $observations): void
    {
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg = $legTable->get($entityId);
        if ($leg->status !== AdvanceConstants::STATUS_APROBACION) {
            return;
        }
        $from = $leg->status;
        $leg->status = AdvanceConstants::STATUS_VALIDACION;
        $legTable->saveOrFail($leg);

        ($this->legHistory ?? new AdvanceLegalizationHistoryService())
            ->recordStatusChange($entityId, $from, AdvanceConstants::STATUS_VALIDACION, $approverUserId);
    }
}
```

- [ ] **Step 4: Registrar en DI**

En `src/Application.php`, en el bloque `services()` (tras el registro de `RefundApprovalService`, ~L408), añadir:
```php
        $container->addShared(AdvanceLegalizationApprovalService::class)
            ->addArguments([
                NotificationService::class,
                AdvanceLegalizationHistoryService::class,
            ]);
```
Y añadir el `use App\Service\AdvanceLegalizationApprovalService;` en la cabecera (junto a los otros `use App\Service\...`). Verificar que `AdvanceLegalizationHistoryService` ya esté registrado (`addShared`) o registrarlo si falta (no tiene dependencias).

- [ ] **Step 5: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationApprovalServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Service/AdvanceLegalizationApprovalService.php src/Application.php tests/TestCase/Service/AdvanceLegalizationApprovalServiceTest.php
git commit -m "feat(advances): AdvanceLegalizationApprovalService (motor de grupo + DI)"
```

---

### Task 7: Verbos del coordinador OUTLIER (`moveToAprobacion` + repurposición de `moveToRevisionFirmas`)

**Files:**
- Modify: `src/Model/Entity/AdvanceLegalization.php` (predicados nuevos)
- Modify: `src/Service/AdvanceLegalizationService.php` (verbos)
- Modify: `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` (métodos nuevos)
- Modify (tests existentes): `tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php` (si conduce validacion→revision_firmas directo)
- Test (nuevos, con DB): `tests/TestCase/Service/AdvanceLegalizationConsolidationTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationApprovalGuard` (Task 2, vía `AprobacionState`); `AdvanceConstants::{STATUS_APROBACION, STATUS_REVISION_FIRMAS, STATUS_CONTABILIDAD}`; `InvoiceConstants::{APPROVAL_APPROVED, STATUS_APROBACION, STATUS_CONTABILIDAD, ADVANCE_LINKABLE_DOCTYPES}`.
- Produces:
  - Entity: `AdvanceLegalization::canMoveToAprobacion(): bool` (status===validacion), `canConsolidateApproval(): bool` (status===aprobacion), `canReturnFromAprobacion(): bool` (status===aprobacion).
  - Service: `moveToAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult` (validacion→aprobacion); `returnToValidacionFromAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult` (aprobacion→validacion, para editar el grupo); `moveToRevisionFirmas(AdvanceLegalization $leg, int $userId): ServiceResult` **repurposado** (aprobacion→revision_firmas: gate quórum+DIAN vía `AprobacionState`, mueve hijas a invoice-contabilidad + area_approval, avanza el leg).
  - Policy: `canMoveToAprobacion(leg, roleId)`, `canConsolidateApproval(leg, roleId)`, `canReturnFromAprobacion(leg, roleId)`.

> **Cambio de flujo (⚠️):** hoy `moveToRevisionFirmas` gatea `canMoveToRevision()` (status===validacion) y va validacion→revision_firmas. En el flujo nuevo, `validacion→revision_firmas` **directo ya no existe** (pasa por `aprobacion`). Los tests de integración que conducen ese salto directo deben insertar el paso `moveToAprobacion` + aprobación de grupo antes de `moveToRevisionFirmas`, o mockear el guard para saltar el quórum.

- [ ] **Step 1: Escribir el test que falla** (con DB)

Create `tests/TestCase/Service/AdvanceLegalizationConsolidationTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\AdvanceLegalizationService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationApprovalFactory;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class AdvanceLegalizationConsolidationTest extends TestCase
{
    private function _coordinator(): AdvanceLegalizationService
    {
        return new AdvanceLegalizationService(
            EventManager::instance(),
            new \App\Service\AdvanceLegalizationHistoryService(),
            new \App\Service\AdvanceLegalizationDocumentService(),
        );
    }

    public function testConsolidateMovesChildrenToContabilidadAndAdvancesLeg(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        // Hija en invoice-aprobacion, DIAN aprobada.
        $child = InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => $anticipo->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        // Quórum: un aprobador ya en Aprobada.
        $u1 = UserFactory::new()->save();
        AdvanceLegalizationApprovalFactory::new(['advance_legalization_id' => $leg->id, 'user_id' => $u1->id])
            ->approved()->save();

        $result = $this->_coordinator()->moveToRevisionFirmas($leg, (int)$u1->id);
        $this->assertTrue($result->success, $result->firstError() ?? '');

        $reloadedLeg = $legTable->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_REVISION_FIRMAS, $reloadedLeg->status);

        $reloadedChild = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $reloadedChild->pipeline_status);
        $this->assertSame(InvoiceConstants::APPROVAL_APPROVED, $reloadedChild->area_approval);
    }

    public function testConsolidateBlockedWithoutQuorum(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        // Sin aprobadores → sin quórum.
        $result = $this->_coordinator()->moveToRevisionFirmas($leg, 1);
        $this->assertFalse($result->success);

        $this->assertSame(AdvanceConstants::STATUS_APROBACION, $legTable->get($leg->id)->status);
    }

    public function testReturnFromAprobacionGoesBackToValidacion(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $result = $this->_coordinator()->returnToValidacionFromAprobacion($leg, 1);
        $this->assertTrue($result->success, $result->firstError() ?? '');
        $this->assertSame(AdvanceConstants::STATUS_VALIDACION, $legTable->get($leg->id)->status);
    }
}
```

> Confirmar la firma real de `AdvanceLegalizationService::__construct` antes de correr: `(EventManagerInterface $events, AdvanceLegalizationHistoryService $historyService, AdvanceLegalizationDocumentService $documentService, ?AdvanceLegalizationPipelineStateRegistry $stateRegistry = null)`. Si `AdvanceLegalizationDocumentService` requiere args en su constructor, obtenerlo del container en el test en vez de `new`.

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationConsolidationTest`
Expected: FAIL — `moveToRevisionFirmas` aún gatea validacion, no mueve hijas.

- [ ] **Step 3: Añadir predicados en la entidad**

En `src/Model/Entity/AdvanceLegalization.php`, junto a `canMoveToRevision()`, añadir. Nota: el estado del que se ENTRA a aprobación es `validacion`; el que consolida es `aprobacion`:

```php
    /** @return bool true cuando el estado permite enviar el grupo a aprobación de área. */
    public function canMoveToAprobacion(): bool
    {
        return $this->status === AdvanceConstants::STATUS_VALIDACION;
    }

    /** @return bool true cuando el estado permite consolidar la aprobación de área. */
    public function canConsolidateApproval(): bool
    {
        return $this->status === AdvanceConstants::STATUS_APROBACION;
    }

    /** @return bool true cuando el estado permite regresar el grupo a Validación para editarlo. */
    public function canReturnFromAprobacion(): bool
    {
        return $this->status === AdvanceConstants::STATUS_APROBACION;
    }
```

- [ ] **Step 4: Añadir `moveToAprobacion`, `returnToValidacionFromAprobacion` y repurposar `moveToRevisionFirmas`**

En `src/Service/AdvanceLegalizationService.php`:

**(a)** Añadir el verbo `moveToAprobacion` (nuevo salto validacion→aprobacion; reusa la validación de `ValidacionState`):

```php
    /**
     * Advance validacion → aprobacion. Requiere ≥1 factura vinculada y el PDF de
     * relación (ValidacionState, MA-006 ya subsumida). Arma el grupo para la
     * aprobación de área en lote.
     */
    public function moveToAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canMoveToAprobacion()) {
            return ServiceResult::fail('La legalización no está en Validación.');
        }
        $statusEnum = AdvancePipelineStatus::tryFrom((string)$leg->status);
        if ($statusEnum === null) {
            return ServiceResult::fail("Estado inválido: {$leg->status}");
        }
        $errors = $this->stateRegistry->get($statusEnum)->validateAdvance($leg);
        if (!empty($errors)) {
            return ServiceResult::fail($errors[0]);
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_APROBACION, $userId);
    }
```

**(a-bis)** Añadir el verbo de regreso `aprobacion → validacion` (para editar el grupo; §5). NO invalida los links aquí — eso lo hace el controller vía `approvalService->supersedeAll` (mirror de `RefundsController::regressStatus`, que llama `supersedeAll` tras `regress` cuando venía de `aprobacion`; el coordinador OUTLIER no conoce el approval service):

```php
    /**
     * Regresa aprobacion → validacion para editar el grupo (vincular/desvincular).
     * El controller invalida las aprobaciones activas (supersedeAll) tras esta llamada.
     */
    public function returnToValidacionFromAprobacion(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canReturnFromAprobacion()) {
            return ServiceResult::fail('La legalización no está en Aprobación.');
        }

        return $this->_setStatus($leg, AdvanceConstants::STATUS_VALIDACION, $userId);
    }
```

**(b)** Reemplazar el cuerpo de `moveToRevisionFirmas` (`:252-270`) para que gatee `aprobacion`, valide quórum+DIAN vía `AprobacionState`, mueva las hijas y avance:

```php
    /**
     * Consolidate aprobacion → revision_firmas. Gate: quórum de aprobadores +
     * DIAN por hija (AprobacionState). Efecto: mueve cada factura hija de
     * invoice-aprobacion a invoice-contabilidad + area_approval=Aprobada
     * (propagación leg→facturas; MA-006 garantizada por construcción).
     */
    public function moveToRevisionFirmas(AdvanceLegalization $leg, int $userId): ServiceResult
    {
        if (!$leg->canConsolidateApproval()) {
            return ServiceResult::fail('La legalización no está en Aprobación.');
        }

        $statusEnum = AdvancePipelineStatus::tryFrom((string)$leg->status);
        if ($statusEnum === null) {
            return ServiceResult::fail("Estado inválido: {$leg->status}");
        }
        $errors = $this->stateRegistry->get($statusEnum)->validateAdvance($leg);
        if (!empty($errors)) {
            return ServiceResult::fail($errors[0]);
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $legTable->getConnection()->transactional(
            function () use ($leg, $userId, $invoices, &$result): bool {
                // Propagación leg→facturas: mueve las hijas en invoice-aprobacion a
                // invoice-contabilidad + area_approval (MA-006 como efecto).
                $invoices->updateAll(
                    [
                        'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                        'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
                        'area_approval_date' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'advance_id' => $leg->advance_invoice_id,
                        'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
                    ],
                );

                $inner = $this->_setStatus($leg, AdvanceConstants::STATUS_REVISION_FIRMAS, $userId);
                if (!$inner->success) {
                    $result = $inner;

                    return false;
                }
                $result = $inner;

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }
```

> Verificar que el `use App\Constants\InvoiceConstants;` ya está en `AdvanceLegalizationService` (sí lo está). `AdvancePipelineStatus` es el alias ya importado (`use App\Constants\Domain\Advance\PipelineStatus as AdvancePipelineStatus;`).

- [ ] **Step 5: Añadir métodos en el ActionPolicy**

En `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`, añadir (junto a `canMoveToRevision`):

```php
    public function canMoveToAprobacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMoveToAprobacion();
    }

    public function canConsolidateApproval(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConsolidateApproval();
    }

    public function canReturnFromAprobacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canReturnFromAprobacion();
    }
```

> `canMoveToRevision` queda como método legacy (aún referenciado por el controller hasta Task 9); no borrarlo en esta tarea.

- [ ] **Step 6: Actualizar tests de integración que saltan validacion→revision_firmas directo**

Read `tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php` (y `…LifecycleTest.php`). Donde conduzcan `moveToRevisionFirmas` desde `validacion` directamente, insertar antes: `moveToAprobacion($leg, $uid)` + sembrar quórum (`AdvanceLegalizationApprovalFactory::…->approved()->save()`) o mockear el guard, de modo que `moveToRevisionFirmas` corra ya en `aprobacion`. Ajustar aserciones de estado intermedio (ahora pasa por `aprobacion`).

- [ ] **Step 7: Correr tests — deben pasar**

Run: `vendor/bin/phpunit --filter AdvanceLegalizationConsolidationTest`
Run: `vendor/bin/phpunit --filter "AdvanceLegalization"`
Expected: PASS (incluyendo los de integración actualizados).

- [ ] **Step 8: Commit**

```bash
git add src/Model/Entity/AdvanceLegalization.php src/Service/AdvanceLegalizationService.php src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php tests/TestCase/Service/AdvanceLegalizationConsolidationTest.php
git commit -m "feat(advances): verbos moveToAprobacion + consolidación aprobacion→revision_firmas"
```

---

### Task 8: RBAC — declarar step + seed `pipeline_permissions` (espejo de `validacion`)

**Files:**
- Modify: `src/Constants/PipelineStepConstants.php` (`STEPS_BY_PIPELINE` + `STEP_LABELS`)
- Create: `config/Migrations/20260703110000_SeedAdvanceAprobacionPermission.php`
- Modify (test existente): `tests/TestCase/Constants/PipelineStepConstantsTest.php` (append-only)

**Interfaces:**
- Produces: `PipelineStepConstants::STEPS_BY_PIPELINE[legalizations]` y `STEP_LABELS[legalizations]` incluyen `aprobacion`; migración que otorga el step `aprobacion` del pipeline `legalizations` a los roles que hoy operan `validacion`.

- [ ] **Step 1: Declarar el step en `PipelineStepConstants`**

En `src/Constants/PipelineStepConstants.php`:
- En `STEPS_BY_PIPELINE[self::PIPELINE_LEGALIZATIONS]` (`:102-109`) insertar `AdvanceConstants::STATUS_APROBACION,` entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`.
- En `STEP_LABELS[self::PIPELINE_LEGALIZATIONS]` (`:169-176`) insertar entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`:
```php
            AdvanceConstants::STATUS_APROBACION => AdvanceConstants::STATUS_LABELS[AdvanceConstants::STATUS_APROBACION],
```

- [ ] **Step 2: Actualizar el test append-only**

Read `tests/TestCase/Constants/PipelineStepConstantsTest.php`. Si asserta el conteo/contenido de `STEPS_BY_PIPELINE[legalizations]`, actualizar para incluir `aprobacion` (6→7 steps para legalizations). Mantener el estilo append-only del test.

- [ ] **Step 3: Escribir la migración de seed** (idempotente, espejo de `SeedRefundAprobacionPermission`)

Create `config/Migrations/20260703110000_SeedAdvanceAprobacionPermission.php`:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Otorga el step 'aprobacion' del pipeline 'legalizations' a los mismos roles
 * que hoy operan 'validacion', para que el nuevo estado tenga operadores desde
 * el deploy (gestionar aprobadores + consolidar).
 */
class SeedAdvanceAprobacionPermission extends BaseMigration
{
    public function up(): void
    {
        $rows = $this->fetchAll(
            "SELECT role_id FROM pipeline_permissions
             WHERE pipeline = 'legalizations' AND step = 'validacion' AND can_operate = 1"
        );
        foreach ($rows as $row) {
            $roleId = (int)$row['role_id'];
            $exists = $this->fetchRow(
                "SELECT id FROM pipeline_permissions
                 WHERE pipeline = 'legalizations' AND step = 'aprobacion' AND role_id = {$roleId}"
            );
            if (!$exists) {
                $this->execute(
                    "INSERT INTO pipeline_permissions (role_id, pipeline, step, can_operate, created, modified)
                     VALUES ({$roleId}, 'legalizations', 'aprobacion', 1, NOW(), NOW())"
                );
            }
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM pipeline_permissions WHERE pipeline = 'legalizations' AND step = 'aprobacion'");
    }
}
```

- [ ] **Step 4: Correr la migración + audit**

Run: `php bin/cake.php migrations migrate` (y `--connection test`)
Run: `php bin/cake.php permissions_audit`
Expected: migración OK; `permissions_audit` exit 0 (la invariante "operar implica ver" se mantiene — el módulo mapeado `advances` ya tiene `can_view` para esos roles).

- [ ] **Step 5: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter PipelineStepConstantsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Constants/PipelineStepConstants.php config/Migrations/20260703110000_SeedAdvanceAprobacionPermission.php tests/TestCase/Constants/PipelineStepConstantsTest.php
git commit -m "feat(advances): RBAC del step 'aprobacion' (declaración + seed espejo de validacion)"
```

---

### Task 9: Acciones de grupo en el controller + rutas + invalidación de links

**Files:**
- Modify: `src/Controller/AdvancesController.php` (DI `approvalService`, `_getBaseUrl`, acciones `sendApprovalLinks`/`modifyApprovers`/`moveToAprobacion`/`returnFromAprobacion`, repoint de `moveToRevision`)
- Modify: `config/routes.php` (nuevas rutas `/advances/...`)
- Test (con DB autenticado): `tests/TestCase/Controller/AdvancesGroupApprovalTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationApprovalService` (Task 6); `AdvanceLegalizationActionPolicy::{canMoveToAprobacion, canConsolidateApproval, canReturnFromAprobacion}` (Task 7); `ApprovalUrlBuilder::baseFromRequest`.
- Produces: endpoints POST `sendApprovalLinks`, `modifyApprovers`, `moveToAprobacion` (validacion→aprobacion), `returnFromAprobacion` (aprobacion→validacion + `supersedeAll`), y `moveToRevision` repointado a la consolidación (aprobacion→revision_firmas).

- [ ] **Step 1: Escribir el test que falla** (auth-guard, con DB)

Create `tests/TestCase/Controller/AdvancesGroupApprovalTest.php` (patrón `ExternalApprovalsGroupTest` / `RefundsControllerGroupSupersessionTest` de Fase 1):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class AdvancesGroupApprovalTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSendApprovalLinksRequiresPost(): void
    {
        $this->get('/advances/send-approval-links/1');
        $this->assertResponseCode(405); // allowMethod(['post'])
    }

    public function testModifyApproversRequiresPost(): void
    {
        $this->get('/advances/modify-approvers/1');
        $this->assertResponseCode(405);
    }

    public function testMoveToAprobacionRequiresPost(): void
    {
        $this->get('/advances/move-to-aprobacion/1');
        $this->assertResponseCode(405);
    }

    public function testReturnFromAprobacionRequiresPost(): void
    {
        $this->get('/advances/return-from-aprobacion/1');
        $this->assertResponseCode(405);
    }
}
```

> Cobertura de comportamiento completo (asignar → aprobar → consolidar) va en Task 12 (integración). Estos guards verifican wiring de rutas/métodos. Si el harness permite POST autenticado (Fase 1 lo confirmó: `$this->session(['Auth' => $userEntity])`), añadir un caso autenticado que asigne aprobadores y verifique filas en `advance_legalization_approvals`.

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter AdvancesGroupApprovalTest`
Expected: FAIL — rutas inexistentes (404) en vez de 405.

- [ ] **Step 3: DI + `_getBaseUrl` en el controller**

En `src/Controller/AdvancesController.php`:
- Añadir `use App\Service\AdvanceLegalizationApprovalService;` y `use App\Service\Approval\ApprovalUrlBuilder;`.
- Añadir propiedad `private AdvanceLegalizationApprovalService $approvalService;`.
- En `initialize()`: `$this->approvalService = $this->getContainer()->get(AdvanceLegalizationApprovalService::class);`
- Añadir el helper (espejo de `RefundsController::_getBaseUrl`):
```php
    private function _getBaseUrl(): string
    {
        return ApprovalUrlBuilder::baseFromRequest($this->request);
    }
```

- [ ] **Step 4: Acción `moveToAprobacion` + repoint de `moveToRevision`**

En `src/Controller/AdvancesController.php`:

**(a)** Añadir la acción `moveToAprobacion` (botón de `validacion`):
```php
    /**
     * Move legalization from validacion → aprobacion (arma el grupo) (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function moveToAprobacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canMoveToAprobacion($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->moveToAprobacion($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Legalización enviada a Aprobación de área.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }
```

**(b)** Repoint de `moveToRevision` (ahora el botón "Avanzar" de `aprobacion` = consolidación). Cambiar el gate a `canConsolidateApproval` y el flash:
```php
        if (!$this->actionPolicy->canConsolidateApproval($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->moveToRevisionFirmas($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->Flash->success('Aprobación consolidada. Legalización enviada a Revisión y Firmas.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');
        }
```

- [ ] **Step 5: Acciones `sendApprovalLinks` + `modifyApprovers`** (espejo de `RefundsController`)

Read `src/Controller/RefundsController.php` (acciones `sendApprovalLinks`/`modifyApprovers`, ~L510-560) y portarlas a `AdvancesController` adaptando: cargar el leg con `_loadLegalization`, gate `actionPolicy->canConsolidateApproval($leg, $roleId)` (el step `aprobacion` gobierna gestionar aprobadores), leer `approver_ids` del POST, llamar `$this->approvalService->sendApprovalLinks($leg, $userIds, $this->_getBaseUrl(), $userId)` / `modifyApprovers(..., $reason, ...)`, flash con `$result`. Redirigir a `['action' => 'legalization', $id]`.

- [ ] **Step 6: Acción de regreso `aprobacion → validacion` + invalidación de links (A1 / §5)**

El coordinador OUTLIER **no** tiene un `regress` genérico (a diferencia de `RefundsController::regressStatus`), así que el camino de operador para editar el grupo (§5) es una acción dedicada. Añadir a `AdvancesController` (espejo de `RefundsController::regressStatus:492-495`, que llama `supersedeAll` tras regresar desde `aprobacion`):

```php
    /**
     * Regresa la legalización de Aprobación a Validación para editar el grupo,
     * invalidando las aprobaciones activas (POST).
     */
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LEGALIZATIONS)]
    public function returnFromAprobacion(?int $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $leg = $this->_loadLegalization((int)$id);
        if (!$leg) {
            return $this->_redirectMissing();
        }
        if (!$this->actionPolicy->canReturnFromAprobacion($leg, (int)$this->_getCurrentUser()->role_id)) {
            return $this->_denyAction((int)$id);
        }
        $result = $this->legalizationService->returnToValidacionFromAprobacion($leg, (int)$this->_getCurrentUser()->id);
        if ($result->success) {
            $this->approvalService->supersedeAll((int)$leg->id); // invalida aprobaciones activas
            $this->Flash->success('Legalización regresada a Validación. Los enlaces de aprobación fueron invalidados.');
        } else {
            $this->Flash->error($result->firstError() ?? 'Error al regresar.');
        }

        return $this->redirect(['action' => 'legalization', $id]);
    }
```

> **Nota M1 (eje de id):** `_loadLegalization($id)` resuelve la legalización por `advance_invoice_id` (el id del **Invoice** del anticipo), NO por `leg->id`. Por eso `{id}` en TODAS las rutas/forms de esta feature (`send-approval-links`, `modify-approvers`, `move-to-aprobacion`, `move-to-revision`, `return-from-aprobacion`) es `$leg->advance_invoice_id`, consistente con los botones existentes de `legalization.php`. `supersedeAll`, en cambio, recibe `$leg->id` (la FK de las filas de aprobación).

- [ ] **Step 7: Rutas**

En `config/routes.php`, en el bloque de rutas `/advances/...` (tras `:561` `move-to-revision`), añadir — **estilo exacto de las vecinas** (3er argumento array con `pass`, verificado `:531-601`):
```php
        $builder->connect(
            '/advances/move-to-aprobacion/{id}',
            ['controller' => 'Advances', 'action' => 'moveToAprobacion'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/return-from-aprobacion/{id}',
            ['controller' => 'Advances', 'action' => 'returnFromAprobacion'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/send-approval-links/{id}',
            ['controller' => 'Advances', 'action' => 'sendApprovalLinks'],
            ['id' => '\d+', 'pass' => ['id']],
        );
        $builder->connect(
            '/advances/modify-approvers/{id}',
            ['controller' => 'Advances', 'action' => 'modifyApprovers'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 8: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter AdvancesGroupApprovalTest`
Expected: PASS (405 en GET a los 3 endpoints).

- [ ] **Step 9: Commit**

```bash
git add src/Controller/AdvancesController.php config/routes.php tests/TestCase/Controller/AdvancesGroupApprovalTest.php
git commit -m "feat(advances): acciones de grupo (enviar/modificar aprobadores, mover a aprobación, consolidar) + rutas"
```

---

### Task 10: Aprobación externa del grupo (4º path en `ExternalApprovalsController`)

**Files:**
- Modify: `src/Controller/ExternalApprovalsController.php` (4º path)
- Create: `templates/ExternalApprovals/review_group_advance.php` (o generalizar `review_group.php`)
- Test (con DB autenticado): `tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationApprovalService::{validateToken, processResponse}` (Task 6).
- Produces: `review($token)` y `process($token)` reconocen el token de grupo de legalización de anticipo (tras invoice y refund, antes del genérico), verifican identidad del aprobador, renderizan/procesan.

- [ ] **Step 1: Escribir el test que falla** (auth-guard, con DB)

Create `tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php` (patrón `ExternalApprovalsGroupTest` de Fase 1): sembrar leg en `aprobacion` + una aprobación con token fresco (`applyFreshToken` + save); `review` con el usuario aprobador logueado renderiza `review_group_advance`; con otro usuario → `expired`/unauthorized; `process` con `action=approve` marca la fila `Aprobada`.

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationApprovalService;
use App\Service\NotificationService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class ExternalApprovalsAdvanceGroupTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAssignedApproverSeesReviewGroupAdvance(): void
    {
        $anticipo = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_ANTICIPO])
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new(['advance_invoice_id' => $anticipo->id])->save();
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
        $leg->status = AdvanceConstants::STATUS_APROBACION;
        $legTable->saveOrFail($leg);

        $approver = UserFactory::new()->save();
        $svc = new AdvanceLegalizationApprovalService($this->createMock(NotificationService::class));
        $svc->assignApprovers($leg, [$approver->id], 'https://x', (int)$approver->id);
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizationApprovals');
        $a = $table->find()->where(['advance_legalization_id' => $leg->id])->firstOrFail();
        $secret = $svc->applyFreshToken($a);
        $table->saveOrFail($a);

        $this->session(['Auth' => TableRegistry::getTableLocator()->get('Users')->get($approver->id)]);
        $this->get('/approve/' . $secret);
        $this->assertResponseOk();
        $this->assertResponseContains('Legalización'); // review_group_advance render
    }
}
```

> Ajustar la ruta externa (`/approve/{token}`) a la real (ver `config/routes.php` para `ExternalApprovals::review`). Ajustar `session(['Auth' => …])` al mecanismo confirmado en Fase 1.

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter ExternalApprovalsAdvanceGroupTest`
Expected: FAIL — sin path de leg, cae al genérico y no renderiza `review_group_advance`.

- [ ] **Step 3: DI + 4º path en `review`**

En `src/Controller/ExternalApprovalsController.php`:
- Añadir `use App\Service\AdvanceLegalizationApprovalService;`, propiedad `private AdvanceLegalizationApprovalService $advanceApprovalService;`, e inicializarla en `initialize()` (`$container->get(AdvanceLegalizationApprovalService::class)`).
- En `review`, tras el bloque "Refund group token path" (`:79`) y antes del "Generic single-entity token path" (`:81`), añadir:
```php
        // Advance legalization group token path (advance_legalization_approvals table)
        $advApproval = $this->advanceApprovalService->validateToken($token);
        if ($advApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($advApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización para aprobar esta legalización.');
                $this->set('unauthorized', true);

                return $this->render('expired');
            }
            $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');
            $leg = $legTable->get($advApproval->advance_legalization_id);
            $invoices = TableRegistry::getTableLocator()->get('Invoices');
            $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees']);
            $linkedInvoices = $invoices->find()
                ->where([
                    'advance_id' => $leg->advance_invoice_id,
                    'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                ])
                ->contain(['Providers'])
                ->all();
            $this->set(compact('token', 'leg', 'anticipo', 'linkedInvoices', 'currentUser'));

            return $this->render('review_group_advance');
        }
```

- [ ] **Step 4: 4º path en `process`**

En `process`, tras el bloque "Refund group token path" (`:199`) y antes del genérico (`:201`), añadir:
```php
        // Advance legalization group token path
        $advApproval = $this->advanceApprovalService->validateToken($token);
        if ($advApproval) {
            $currentUser = $this->Authentication->getIdentity()->getOriginalData();
            if ($advApproval->user_id !== $currentUser->id) {
                $this->Flash->error('No tiene autorización.');
                $this->set('expired', true);

                return $this->render('expired');
            }
            $result = $this->advanceApprovalService->processResponse(
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

- [ ] **Step 5: Plantilla externa**

Read `templates/ExternalApprovals/review_group.php` (Fase 1) y crear `templates/ExternalApprovals/review_group_advance.php` adaptando los textos a "Legalización de Anticipo" y las variables (`$leg`, `$anticipo`, `$linkedInvoices`). Mostrar todas las facturas del grupo + botones Aprobar/Rechazar (todo-o-nada) + observaciones. Montos en COP con 0 decimales (lección Fase 1). Escapar toda salida con `h()`.

- [ ] **Step 6: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter ExternalApprovalsAdvanceGroupTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Controller/ExternalApprovalsController.php templates/ExternalApprovals/review_group_advance.php tests/TestCase/Controller/ExternalApprovalsAdvanceGroupTest.php
git commit -m "feat(advances): aprobación externa del grupo (4º path + review_group_advance)"
```

---

### Task 11: UI — badge anti-drift, ViewModel y panel de aprobación

**Files:**
- Modify: `src/View/Presentation/AdvancePresentation.php` (`STATUS_BADGES`)
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php` (datos del panel)
- Modify: `templates/Advances/legalization.php` (panel gateado a `aprobacion` + botón "Enviar a aprobación" en `validacion`)
- Modify (tests existentes): `tests/TestCase/View/Presentation/AdvancePresentationTest.php` (append-only)
- Test: `tests/TestCase/ViewModel/AdvanceLegalizationViewModelApprovalTest.php`

**Interfaces:**
- Consumes: `AdvanceConstants::STATUS_APROBACION`; `AdvanceLegalizationApprovalService::getCurrentApprovals/getApprovalSummary` (para el panel — el controller los inyecta al VM).
- Produces: `AdvancePresentation::STATUS_BADGES[STATUS_APROBACION]`; `AdvanceLegalizationViewModel` expone `approvals`/`approvalSummary`/`isAprobacion` para el panel; template con panel de aprobadores.

- [ ] **Step 1: Badge anti-drift**

En `src/View/Presentation/AdvancePresentation.php`, en `STATUS_BADGES`, insertar entre `STATUS_VALIDACION` y `STATUS_REVISION_FIRMAS`:
```php
        AdvanceConstants::STATUS_APROBACION         => 'pill-warning-soft',
```
> El valor DEBE coincidir con `PipelineColorMap` para `aprobacion` (`pill-warning-soft`, ya usado por facturas/reintegros). `PipelineColorConsistencyTest` lo verifica — no inventar otro pill.

- [ ] **Step 2: Actualizar `AdvancePresentationTest` (append-only)**

Read `tests/TestCase/View/Presentation/AdvancePresentationTest.php`. Si asserta el conteo o presencia de badges por estado, añadir la aserción de `STATUS_APROBACION => 'pill-warning-soft'`. Mantener append-only.

- [ ] **Step 3: Exponer datos del panel en el ViewModel**

En `src/ViewModel/AdvanceLegalizationViewModel.php`:
- Añadir parámetros al constructor (al FINAL, para no romper llamadas posicionales; mirror del patrón de Fase 1):
```php
        public array $approvals = [],
        public array $approvalSummary = ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
        public bool $canManageApprovers = false,
```
- Derivar `isAprobacion` en el constructor: `$this->isAprobacion = ((string)$leg->status === AdvanceConstants::STATUS_APROBACION);` (declarar la propiedad pública `public bool $isAprobacion;`).
- En `build()`, añadir al array de retorno: `'approvals' => $this->approvals, 'approvalSummary' => $this->approvalSummary, 'canManageApprovers' => $this->canManageApprovers, 'isAprobacion' => $this->isAprobacion,`.

En `src/Controller/AdvancesController.php::legalization`, poblar los nuevos args al construir el VM:
```php
            approvals: $leg->status === AdvanceConstants::STATUS_APROBACION
                ? $this->approvalService->getCurrentApprovals((int)$leg->id) : [],
            approvalSummary: $leg->status === AdvanceConstants::STATUS_APROBACION
                ? $this->approvalService->getApprovalSummary((int)$leg->id)
                : ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
            canManageApprovers: $this->actionPolicy->canConsolidateApproval($leg, $roleId),
```

- [ ] **Step 4: Test del ViewModel**

Create `tests/TestCase/ViewModel/AdvanceLegalizationViewModelApprovalTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\ViewModel\AdvanceLegalizationViewModel;
use PHPUnit\Framework\TestCase;

class AdvanceLegalizationViewModelApprovalTest extends TestCase
{
    public function testIsAprobacionAndBadgeInAprobacion(): void
    {
        $invoice = new Invoice(['id' => 1, 'invoice_number' => 'ANT-1', 'amount' => 100.0]);
        $leg = new AdvanceLegalization(['id' => 9, 'advance_invoice_id' => 1]);
        $leg->status = AdvanceConstants::STATUS_APROBACION;

        $vm = new AdvanceLegalizationViewModel(
            invoice: $invoice,
            leg: $leg,
            roleName: 'Contabilidad',
            linkedInvoices: [],
            bankingEntities: [],
            surplusPayment: null,
        );

        $this->assertTrue($vm->isAprobacion);
        $this->assertSame('Aprobación', $vm->currentStatusBadge[0]);
        $this->assertSame('pill-warning-soft', $vm->currentStatusBadge[1]);
    }
}
```

- [ ] **Step 5: Panel + botón en el template**

En `templates/Advances/legalization.php`:
- En la sección de acciones de `validacion`, cambiar el botón que hoy dispara `move-to-revision` para que apunte a `/advances/move-to-aprobacion/{id}` con label "Enviar a aprobación de área".
- Añadir, gateado a `$viewModel->isAprobacion` (o `$isAprobacion`), un panel de aprobación **espejo del de Reintegros**. Read `templates/Refunds/edit.php` (panel de `aprobacion`, gateado a `aprobacion`) y portarlo: asignar aprobadores (`select2-enable`, POST a `/advances/send-approval-links/{id}`), tabla de estado por aprobador (Pendiente/Aprobada/Rechazada desde `$approvals`), botón "Modificar aprobadores" (motivo, POST a `/advances/modify-approvers/{id}`), botón "Avanzar" (consolidar) a `/advances/move-to-revision/{id}` habilitado cuando `approvalSummary.pending === 0 && approvalSummary.total > 0`, y botón "Regresar a Validación" (editar el grupo) POST a `/advances/return-from-aprobacion/{id}`. Mantener CSRF (`$this->Form`), chips y el canon visual (`element('pipeline_sidebar')` layout ya presente).
- **Nota M1 (eje de id) — crítica:** en TODOS estos forms, `{id}` = `$invoice->id` (= `$leg->advance_invoice_id`, el id del Invoice del anticipo), NO `$leg->id`. Es el mismo eje que ya usan los botones existentes de `legalization.php` (p. ej. `move-to-revision`). Usar `$leg->id` produciría un redirect silencioso "Legalización no encontrada" (`_loadLegalization` resuelve por `advance_invoice_id`).

- [ ] **Step 6: Correr tests — deben pasar**

Run: `vendor/bin/phpunit --filter AdvancePresentationTest`
Run: `vendor/bin/phpunit --filter AdvanceLegalizationViewModelApprovalTest`
Run: `vendor/bin/phpunit --filter PipelineColorConsistencyTest`
Expected: PASS (los tres).

- [ ] **Step 7: Commit**

```bash
git add src/View/Presentation/AdvancePresentation.php src/ViewModel/AdvanceLegalizationViewModel.php src/Controller/AdvancesController.php templates/Advances/legalization.php tests/TestCase/View/Presentation/AdvancePresentationTest.php tests/TestCase/ViewModel/AdvanceLegalizationViewModelApprovalTest.php
git commit -m "feat(advances): UI del estado 'aprobacion' (badge, ViewModel, panel de aprobadores)"
```

---

### Task 12: Integración end-to-end + verificación RC↔Legalización + regresión de suite

**Files:**
- Test: `tests/TestCase/Service/Integration/AdvanceGroupApprovalFlowTest.php`
- Test: `tests/TestCase/Service/Integration/AdvanceReciboCajaFreezeTest.php`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: cobertura e2e del flujo de aprobación de grupo de anticipos + verificación de la interacción con RC↔Legalización.

- [ ] **Step 1: Registrar baseline de suite**

Run: `vendor/bin/phpunit`
Anotar el conteo verde actual (esperado ~843 + los tests añadidos hasta Task 11). Este es el baseline contra el que se mide "0 regresiones".

- [ ] **Step 2: Escribir el test e2e del flujo de grupo**

Create `tests/TestCase/Service/Integration/AdvanceGroupApprovalFlowTest.php`: flujo completo con DB real:
1. Anticipo pagado + leg en `validacion`.
2. Vincular una `Legalización` (invoice-`aprobacion`, DIAN aprobada) + adjuntar relación (o sembrar la firma pendiente).
3. `moveToAprobacion` → leg en `aprobacion`.
4. `assignApprovers` (2 aprobadores) → `areAllApproved`=false; `moveToRevisionFirmas` **bloqueado** (sin quórum).
5. Ambos aprueban vía `processResponse` → `areAllApproved`=true; hijas con `area_approval=Aprobada`.
6. `moveToRevisionFirmas` → leg en `revision_firmas`; hijas en invoice-`contabilidad`.
7. Caso alterno: un aprobador **rechaza** → leg vuelve a `validacion`, links invalidados (`hasPendingApprovals`=false).
8. Caso alterno: DIAN faltante en una hija → `moveToRevisionFirmas` bloqueado con mensaje que lista la ofensora.

Usar los factories y el patrón de aserción de `RefundGroupApprovalFlowTest` (Fase 1) como referencia estructural.

- [ ] **Step 3: Escribir la verificación RC↔Legalización**

Create `tests/TestCase/Service/Integration/AdvanceReciboCajaFreezeTest.php`: vincular un `Recibo de Caja` (invoice-`aprobacion`, DIAN aprobada) al anticipo, correr la aprobación de grupo hasta `moveToRevisionFirmas`, y verificar:
- El RC vinculado queda **exactamente** en invoice-`contabilidad` con `area_approval=Aprobada` (el estado terminal-de-congelado que espera `ReciboCajaDocumentTypePolicy::blocksAdvance`).
- El RC **no** avanza solo tras la aprobación de grupo (su `pipeline_status` permanece `contabilidad`, no salta).
- (Verificación documentada en §6.3 del spec: la aprobación de grupo lleva el RC de `aprobacion` a `contabilidad`, el mismo estado de congelado que hoy; F1–F3 de RC↔Legalización siguen coherentes.)

- [ ] **Step 4: Correr los tests nuevos — deben pasar**

Run: `vendor/bin/phpunit --filter AdvanceGroupApprovalFlowTest`
Run: `vendor/bin/phpunit --filter AdvanceReciboCajaFreezeTest`
Expected: PASS

- [ ] **Step 5: cs-check + suite completa (regresión)**

Run: `composer cs-check` (si hay findings estilísticos nuevos: `composer cs-fix` y revisar)
Run: `vendor/bin/phpunit`
Expected: suite verde, 0 failures / 0 errors, sin regresiones vs. el baseline del Step 1 (solo el warning benigno `apc.enable_cli` + notices/deprecations preexistentes). Anotar el conteo final (baseline + tests de la feature).

- [ ] **Step 6: Commit**

```bash
git add tests/TestCase/Service/Integration/AdvanceGroupApprovalFlowTest.php tests/TestCase/Service/Integration/AdvanceReciboCajaFreezeTest.php
git commit -m "test(advances): integración e2e de aprobación de grupo + verificación RC↔Legalización"
```

---

## Notas de ejecución

- **Branch:** crear `feat/aprobacion-lote-anticipos` desde `dev` antes de la Task 1 (mirror de Fase 1). Merge a `dev` al final tras review whole-branch.
- **DB de test:** si el sandbox del implementer reporta `Access denied`, correr los tests puros y diferir los con-DB (Tasks 2, 4, 6, 7, 9, 10, 12) al checkpoint del controller (sesión principal), que sí alcanza la DB.
- **`bin/cake` es wrapper shell** → invocar `php bin/cake.php ...`; migraciones sobre test con `--connection test`.
- **Deuda heredada conocida (no bloqueante, de Fase 1):** race de quórum stale en `GroupApprovalService::areAllApproved` (lectura de siblings sin lock). Se hereda idéntica en Anticipos. Fuera de alcance (tocaría el motor compartido); documentar, no arreglar aquí.
- **Paridad (M2) — aprobadores individuales de las hijas (§5):** este plan NO supersede los `invoice_approvals` individuales de las facturas hijas al vincularlas al grupo, en paridad exacta con Fase 1 (Reintegros tampoco lo hace). Si se decide cerrarlo, hacerlo en ambos módulos en un cambio aparte. Ver la "Decisión de paridad" en Task 4.
- **A1 resuelto:** el camino de operador `aprobacion → validacion` (editar el grupo, §5) se implementa como acción dedicada `returnFromAprobacion` (Task 7 verbo + Task 9 acción/ruta + `supersedeAll` + Task 11 botón), porque el coordinador OUTLIER no tiene `regress` genérico como Refunds.
