# Filtros canónicos del listado de facturas y badge de vinculación — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unificar los tres criterios divergentes que deciden qué factura aparece en qué listado bajo un único concepto —si la factura tiene un registro padre— y mostrar en la fila a qué registro pertenece.

**Architecture:** El conjunto de claves foráneas de contención se declara una vez en `InvoiceConstants::PARENT_FOREIGN_KEYS`. De ahí salen dos consumidores: un custom finder `InvoicesTable::findWithoutParent()` que usan por igual el controller y `SidebarCounterService` (con lo cual contador y lista no pueden divergir), y la derivación del badge en `InvoicePresentation::forRow()`. La programación de pagos queda deliberadamente fuera de la constante: es una referencia, no una contención, y se resuelve solo en la capa de vista.

**Tech Stack:** PHP 8.4+, CakePHP 5.3, PHPUnit + cakephp-fixture-factories, MySQL/MariaDB.

**Spec:** `docs/superpowers/specs/2026-07-10-filtros-listado-facturas-design.md`

## Global Constraints

- **Ninguna migración.** Todo el cambio es de lectura; no se toca el esquema.
- **Nada de mapas estado→pill inline en templates.** Viven solo en `src/View/Presentation/InvoicePresentation.php` (const). Regla dura de `CLAUDE.md › Mapeo estado→pill/icono — anti-drift`.
- **La fila del índice es un `<a class="row-fact">`**, no una `<table>` ni un `<div>`. El badge es texto, nunca un `<a>` anidado.
- **Servicios obtienen tablas vía** `TableRegistry::getTableLocator()->get('X')`, nunca `$this->X`.
- **Métodos privados con guion bajo:** `_buildInvoiceQuery()`.
- **`'Recibo'` (`DOCTYPE_RECIBO`) vence. `'Recibo de Caja'` (`DOCTYPE_RECIBO_CAJA`) NO.** Dos valores persistidos a una palabra de distancia. No unificar, no "corregir".
- **Suite:** `vendor/bin/phpunit` directo, **nunca** `composer test`. La suite sale con **exit code 1 incluso en verde** por *notices* preexistentes: el criterio de éxito es el conteo de fallos (`Failures: 0`, `Errors: 0`), no el exit code.
- **Estilo:** `composer cs-check` debe pasar antes de cada commit.
- **Branch:** `dev`.

## File Structure

**Se crean (3):**

| Archivo | Responsabilidad |
|---|---|
| `src/View/Presentation/InvoiceLinkBadge.php` | DTO inmutable del badge: código, etiqueta, icono, si es contención, y a dónde apunta. |
| `templates/element/invoice_parent_notice.php` | Aviso único "esta factura pertenece a X", variante informativa o de bloqueo. |
| `webroot/css/components.css` → clase `.pill-ref` | Variante punteada del pill, para el vínculo de referencia (programación). |

**Se modifican (8):**

| Archivo | Cambio |
|---|---|
| `src/Constants/InvoiceConstants.php` | `PARENT_FOREIGN_KEYS`, `DOCTYPES_WITH_DUE_DATE`. |
| `src/Model/Table/InvoicesTable.php` | Custom finder `findWithoutParent()`. |
| `src/Controller/InvoicesController.php` | `_buildInvoiceQuery()` + las 4 acciones + `contain` en `view()`. |
| `src/Service/SidebarCounterService.php` | 3 contadores alineados con sus vistas. |
| `src/View/Presentation/InvoicePresentation.php` | `PARENT_BADGES`, `parentBadge()`, derivación en `forRow()`. |
| `src/View/Presentation/InvoiceRowView.php` | Propiedad `?InvoiceLinkBadge $linkBadge`. |
| `templates/Invoices/index.php` | Render del badge. |
| `templates/Invoices/view.php` | Reemplaza dos bloques de aviso por el element. |

**Tests (3 nuevos, 1 ampliado):**

- `tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php`
- `tests/TestCase/Controller/InvoicesListFiltersTest.php`
- `tests/TestCase/Service/SidebarCounterInvoiceParityTest.php`
- `tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php` (nuevo — clase con DB, separada del `InvoicePresentationTest` puro)

**Nota sobre el orden y el TDD.** El spec pide que los tests que reproducen el bug —los del controller— se escriban antes de tocar el controller, y así se hace: son el Paso 1 de la Task 2. La Task 1 (constantes + finder) va delante porque es una dependencia mecánica de ocho líneas, y anteponerla mantiene todos los commits verdes. Un commit rojo no es TDD, es un repo roto.

**Desviación menor respecto al spec.** El spec habla de diez archivos; este plan toca once. El añadido es la clase CSS `.pill-ref` en `webroot/css/components.css`, necesaria para materializar la decisión #5 del spec (badge punteado para la referencia). No cambia el diseño.

---

### Task 1: Fuente única y custom finder

Declara el concepto "factura con padre" en un solo sitio y lo expone como scope de query.

**Files:**
- Modify: `src/Constants/InvoiceConstants.php` (insertar tras `ADVANCE_LINKABLE_DOCTYPES`, línea 44)
- Modify: `src/Model/Table/InvoicesTable.php` (imports, y método nuevo tras `initialize()`, línea 108)
- Test: `tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php` (crear)

**Interfaces:**
- Consumes: nada.
- Produces:
  - `InvoiceConstants::PARENT_FOREIGN_KEYS` → `list<string>` con `['petty_cash_record_id', 'refund_id', 'advance_id']`
  - `InvoiceConstants::DOCTYPES_WITH_DUE_DATE` → `list<string>`
  - `InvoicesTable::findWithoutParent(SelectQuery $query): SelectQuery` — invocable como `->find('withoutParent')` sobre un query existente o como `$table->find('withoutParent')`.

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * findWithoutParent() excluye las facturas CONTENIDAS en un registro padre
 * (caja menor, reintegro, anticipo), cuyo pipeline_status escribe el padre.
 * NO excluye las agendadas en una programación de pagos: eso es una
 * referencia, no una contención.
 */
final class InvoicesTableWithoutParentTest extends TestCase
{
    /** @return list<int> */
    private function _findIds(): array
    {
        return TableRegistry::getTableLocator()->get('Invoices')
            ->find('withoutParent')
            ->all()
            ->extract('id')
            ->toList();
    }

    public function testExcludesInvoiceContainedInPettyCashRecord(): void
    {
        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id])->save();
        $libre = InvoiceFactory::new()->save();

        $this->assertSame([$libre->id], $this->_findIds());
    }

    public function testExcludesInvoiceContainedInRefund(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])->save();
        $libre = InvoiceFactory::new()->save();

        $this->assertSame([$libre->id], $this->_findIds());
    }

    public function testExcludesInvoiceLinkedToAdvance(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()->save();

        // El anticipo padre no tiene padre: sigue apareciendo.
        $this->assertSame([$anticipo->id], $this->_findIds());
    }

    public function testDoesNotExcludeInvoiceScheduledForPayment(): void
    {
        $scheduling = PaymentSchedulingFactory::new()->save();
        $bank = BankingEntityFactory::new()->save();
        $invoice = InvoiceFactory::new()->save();

        $items = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $items->saveOrFail($items->newEntity([
            'payment_scheduling_id' => $scheduling->id,
            'invoice_id' => $invoice->id,
            'banking_entity_id' => $bank->id,
            'amount' => 1000.0,
        ]));

        $this->assertSame([$invoice->id], $this->_findIds());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php
```

Expected: 4 errores con `Unknown finder method "withoutParent"` (`Cake\ORM\Exception\MissingFinderMethodException`).

- [ ] **Step 3: Add the constants**

En `src/Constants/InvoiceConstants.php`, insertar inmediatamente después del cierre de `ADVANCE_LINKABLE_DOCTYPES` (línea 44):

```php
    /**
     * Claves foráneas de CONTENCIÓN: un registro padre gobierna el pipeline de
     * la factura, escribiendo su pipeline_status con updateAll (PettyCashService,
     * RefundPipelineService, AdvanceLegalizationService). Esas facturas no se
     * operan desde el módulo de Facturas.
     *
     * Fuente única de InvoicesTable::findWithoutParent().
     *
     * La programación de pagos NO va aquí: es una REFERENCIA (solo agenda el
     * pago, no toca el estado) y su vínculo vive en payment_scheduling_items,
     * no en invoices.
     *
     * @var list<string>
     */
    public const PARENT_FOREIGN_KEYS = [
        'petty_cash_record_id',
        'refund_id',
        'advance_id',
    ];

    /**
     * Tipos de documento con fecha de vencimiento real. Los demás copian
     * issue_date en due_date solo para satisfacer el NOT NULL
     * (InvoicesController::add), de modo que sin esta lista blanca aparecerían
     * como "vencidos" al día siguiente de emitirse.
     *
     * OJO: 'Recibo' (DOCTYPE_RECIBO) sí vence. 'Recibo de Caja'
     * (DOCTYPE_RECIBO_CAJA) NO — está excluido a propósito: es un documento de
     * legalización de anticipo y no tiene plazo. Añadirlo aquí reintroduce el
     * bug de "legalizaciones vencidas".
     *
     * @var list<string>
     */
    public const DOCTYPES_WITH_DUE_DATE = [
        self::DOCTYPE_FACTURA,
        self::DOCTYPE_NOTA_DEBITO,
        self::DOCTYPE_TARJETA_CREDITO,
        self::DOCTYPE_RECIBO,
    ];
```

- [ ] **Step 4: Add the finder**

En `src/Model/Table/InvoicesTable.php`, añadir el import tras `use Cake\ORM\RulesChecker;` (línea 12):

```php
use Cake\ORM\Query\SelectQuery;
```

Y el método justo después del cierre de `initialize()` (línea 108):

```php
    /**
     * Excluye las facturas contenidas en un registro padre (caja menor,
     * reintegro, anticipo). Su pipeline_status lo escribe el padre, así que no
     * se operan desde el módulo de Facturas y no deben aparecer en la bandeja.
     *
     * NO excluye las facturas agendadas en una programación de pagos: eso es
     * una referencia (solo agenda el pago), no una contención.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query base.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithoutParent(SelectQuery $query): SelectQuery
    {
        foreach (InvoiceConstants::PARENT_FOREIGN_KEYS as $fk) {
            $query->where(["Invoices.{$fk} IS" => null]);
        }

        return $query;
    }
```

- [ ] **Step 5: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php
```

Expected: `OK (4 tests)` — `Failures: 0`, `Errors: 0`.

- [ ] **Step 6: Check style and commit**

```bash
composer cs-check
git add src/Constants/InvoiceConstants.php src/Model/Table/InvoicesTable.php tests/TestCase/Model/Table/InvoicesTableWithoutParentTest.php
git commit -m "feat: fuente unica del vinculo de contencion y finder findWithoutParent"
```

---

### Task 2: Filtros canónicos en los cuatro listados

Sustituye los tres criterios divergentes por composición sobre el finder. **Los tests de este paso reproducen el bug reportado: escríbelos antes de tocar el controller.**

**Files:**
- Modify: `src/Controller/InvoicesController.php:85-171` (las 4 acciones) y `:586-616` (`_buildInvoiceQuery`)
- Test: `tests/TestCase/Controller/InvoicesListFiltersTest.php` (crear)

**Interfaces:**
- Consumes: `InvoicesTable::findWithoutParent()`, `InvoiceConstants::DOCTYPES_WITH_DUE_DATE` (Task 1).
- Produces:
  - `InvoicesController::_buildInboxQuery(array $conditions, int $userId, int $roleId): SelectQuery` — privado; aplica `visibleStatuses` + `withoutParent`. Lo usan `index()`, `rejected()` y `overdue()`.
  - En `all()`, el query contiene las asociaciones `PettyCashRecords`, `Refunds` y `Advance`. La Task 4 depende de ello para pintar el badge.

- [ ] **Step 1: Write the failing tests**

Crear `tests/TestCase/Controller/InvoicesListFiltersTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RefundFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Los 4 listados de facturas comparten un único criterio: si la factura tiene
 * un registro padre, no se opera desde el módulo de Facturas.
 */
final class InvoicesListFiltersTest extends TestCase
{
    use IntegrationTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear('sidebar');
    }

    /**
     * Usuario con can_view de invoices y capacidad de operar los pasos dados
     * del pipeline de facturas.
     *
     * @param list<string> $operableSteps
     */
    private function _loginWithSteps(array $operableSteps): void
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'invoices',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach ($operableSteps as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        $this->session(['Auth' => UserFactory::new(['role_id' => $role->id])->save()]);
    }

    public function testInboxHidesInvoiceGroupedIntoRefund(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'ZZ-AGRUPADA-REI'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-AGRUPADA-REI');
    }

    public function testInboxHidesGroupedPettyCashInvoiceStillInAprobacion(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $record = PettyCashRecordFactory::new()->save();
        InvoiceFactory::new(['petty_cash_record_id' => $record->id, 'invoice_number' => 'ZZ-AGRUPADA-CM'])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-AGRUPADA-CM');
    }

    public function testInboxShowsUngroupedRefundInvoice(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        InvoiceFactory::new([
            'document_type' => InvoiceConstants::DOCTYPE_REINTEGRO,
            'invoice_number' => 'ZZ-LIBRE-REI',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-LIBRE-REI');
    }

    public function testArchiveShowsGroupedInvoices(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id, 'invoice_number' => 'ZZ-ARCHIVO-REI'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/all');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-ARCHIVO-REI');
    }

    public function testOverdueExcludesDocumentTypesWithoutRealDueDate(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $ayer = date('Y-m-d', strtotime('-1 day'));

        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-FAC'])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-LEG'])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer, 'invoice_number' => 'ZZ-VENCIDA-RC'])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/overdue');

        $this->assertResponseOk();
        $this->assertResponseContains('ZZ-VENCIDA-FAC');
        $this->assertResponseNotContains('ZZ-VENCIDA-LEG');
        $this->assertResponseNotContains('ZZ-VENCIDA-RC');
    }

    public function testRejectedIsScopedToOperableSteps(): void
    {
        // Contabilidad no opera `aprobacion`, que es donde vive toda rechazada.
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        InvoiceFactory::new([
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
            'invoice_number' => 'ZZ-RECHAZADA',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $this->get('/invoices/rejected');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ZZ-RECHAZADA');
    }
}
```

**Por qué códigos literales y no `$invoice->invoice_number`:** la factory genera
`F-1`, `F-2`, … y `assertResponseNotContains('F-1')` fallaría si en la página hay
una `F-12`. Los literales `ZZ-…` no colisionan entre sí ni con nada del layout.

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php
```

Expected: fallan **4** de los 6. `testInboxHidesInvoiceGroupedIntoRefund`, `testInboxHidesGroupedPettyCashInvoiceStillInAprobacion`, `testOverdueExcludesDocumentTypesWithoutRealDueDate` y `testRejectedIsScopedToOperableSteps` fallan con `assertResponseNotContains`/`assertResponseContains`. Los otros dos pasan ya (documentan el comportamiento que **no** debe romperse).

- [ ] **Step 3: Rewrite `_buildInvoiceQuery` and add `_buildInboxQuery`**

En `src/Controller/InvoicesController.php`, reemplazar el cuerpo de `_buildInvoiceQuery()` (líneas 586-616) por:

```php
    private function _buildInvoiceQuery(array $conditions = [], int $userId = 0): SelectQuery
    {
        $query = $this->Invoices->find()
            ->contain([
                'Providers',
                'OperationCenters',
                'ExpenseTypes',
                'CostCenters',
                'RegisteredByUsers',
                // Referencia (no contención): la factura sigue siendo del módulo
                // de Facturas; la programación solo agenda su pago.
                'PaymentSchedulingItems' => [
                    'PaymentSchedulings',
                    'sort' => ['PaymentSchedulingItems.id' => 'DESC'],
                ],
            ])
            // El Anticipo es el registro padre y vive en /advances.
            ->where(['Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO]);

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        if ($userId > 0) {
            $uid = (int)$userId;
            $subquery = "(
                SELECT COUNT(*)
                FROM invoice_observations io
                LEFT JOIN invoice_reads ir
                    ON ir.invoice_id = io.invoice_id AND ir.user_id = {$uid}
                WHERE io.invoice_id = Invoices.id
                  AND io.user_id != {$uid}
                  AND (ir.last_visited_at IS NULL OR io.created > ir.last_visited_at)
            )";

            $query->selectAlso(['unread_observations' => $subquery]);
        }

        $this->filterService->apply($query, $this->request->getQueryParams());

        $query->orderBy(['Invoices.created' => 'DESC']);

        return $query;
    }

    /**
     * Query de bandeja: lo que el rol puede operar y no pertenece a otro módulo.
     * Base común de index(), rejected() y overdue().
     *
     * @param array<string|int, mixed> $conditions Condiciones extra de la vista.
     */
    private function _buildInboxQuery(array $conditions, int $userId, int $roleId): SelectQuery
    {
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);
        $conditions = array_merge(
            $conditions,
            $this->_visibleStatusConditions('Invoices.pipeline_status', $visibleStatuses),
        );

        return $this->_buildInvoiceQuery($conditions, $userId)->find('withoutParent');
    }
```

- [ ] **Step 4: Rewrite the four actions**

Reemplazar `index()` (líneas 85-110):

```php
    #[Permission(action: 'view')]
    public function index()
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleId);

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery([], $userId, $roleId));

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
    }
```

Reemplazar `all()` (líneas 112-129). Contiene los padres porque es la única vista donde pueden ser no nulos:

```php
    #[Permission(action: 'view')]
    public function all()
    {
        $roleName = $this->_getRoleName();
        $userId = (int)$this->_getCurrentUser()->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $query = $this->_buildInvoiceQuery([], $userId)
            ->contain(['PettyCashRecords', 'Refunds', 'Advance']);
        $invoices = $this->paginate($query);
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }
```

Reemplazar `rejected()` (líneas 131-148):

```php
    #[Permission(action: 'view')]
    public function rejected(): void
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery(
            ['Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED],
            $userId,
            $roleId,
        ));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }
```

Reemplazar `overdue()` (líneas 150-171). La cláusula `NOT IN [pagada, legalizada]` se conserva aunque `visibleStatuses` la cubra: afirma que una factura pagada no está vencida, y eso no debe depender de cómo esté poblada `pipeline_permissions`:

```php
    #[Permission(action: 'view')]
    public function overdue(): void
    {
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();
        $roleId = (int)$user->role_id;
        $userId = (int)$user->id;

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $invoices = $this->paginate($this->_buildInboxQuery([
            'Invoices.due_date <' => date('Y-m-d'),
            'Invoices.document_type IN' => InvoiceConstants::DOCTYPES_WITH_DUE_DATE,
            'Invoices.pipeline_status NOT IN' => [
                InvoiceConstants::STATUS_PAGADA,
                InvoiceConstants::STATUS_LEGALIZADA,
            ],
        ], $userId, $roleId));
        $visibleStatuses = [];

        $this->set(compact('invoices', 'visibleStatuses', 'roleName'));
        $this->set('approvalSummaries', $this->_getApprovalSummaries($invoices));
        $this->set($this->_getFilterDropdowns());
        $this->render('index');
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php
```

Expected: `OK (6 tests)`.

- [ ] **Step 6: Run the existing invoice controller suite for regressions**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesControllerTest.php tests/TestCase/Controller/InvoicesDocumentGateTest.php
```

Expected: `Failures: 0`, `Errors: 0`. (El exit code puede ser 1 por *notices*; ignóralo.)

- [ ] **Step 7: Check style and commit**

```bash
composer cs-check
git add src/Controller/InvoicesController.php tests/TestCase/Controller/InvoicesListFiltersTest.php
git commit -m "fix: unificar el criterio de filtrado de los 4 listados de facturas"
```

---

### Task 3: Paridad entre contadores del sidebar y sus listados

Cierra la grieta que el propio código documenta: `getInvoiceStatusCounters()` lleva copiada a mano la condición de caja menor "para que el badge no sobre-cuente respecto a la lista".

**Files:**
- Modify: `src/Service/SidebarCounterService.php:72-79` (contadores), `:130-177` (`getInvoiceStatusCounters`), `:179-191` (`getOverdueInvoicesCount`)
- Test: `tests/TestCase/Service/SidebarCounterInvoiceParityTest.php` (crear)

**Interfaces:**
- Consumes: `InvoicesTable::findWithoutParent()`, `InvoiceConstants::DOCTYPES_WITH_DUE_DATE` (Task 1).
- Produces: `getRejectedInvoicesCount(int $roleId): int` y `getOverdueInvoicesCount(int $roleId): int` — privados; `getOverdueInvoicesCount` **cambia de firma**, ahora recibe el rol.

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Service/SidebarCounterInvoiceParityTest.php`. El patrón de stubs replica `SidebarCounterLegalizationTest`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\Service\NoveltyPipelineService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\RefundPipelineService;
use App\Service\SidebarCounterService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

/**
 * Los contadores del sidebar cuentan exactamente lo que su listado muestra.
 * Comparten findWithoutParent(), así que no pueden divergir.
 */
final class SidebarCounterInvoiceParityTest extends TestCase
{
    private const ROLE_ID = 201;

    public function setUp(): void
    {
        parent::setUp();
        // getCounters() cachea en el config `sidebar`, no en `default`.
        Cache::clear('sidebar');
    }

    /** @param list<string> $visibleStatuses */
    private function _service(array $visibleStatuses): SidebarCounterService
    {
        $invoicePipeline = $this->createStub(InvoicePipelineService::class);
        $invoicePipeline->method('getVisibleStatuses')->willReturn($visibleStatuses);

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn([]);

        return new SidebarCounterService(
            $invoicePipeline,
            $this->createStub(NoveltyPipelineService::class),
            $this->createStub(PettyCashPipelineService::class),
            $this->createStub(RefundPipelineService::class),
            new AdvanceLegalizationActionPolicy($auth),
        );
    }

    public function testStatusCountersIgnoreInvoicesWithParent(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $counters = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD])
            ->getCounters(self::ROLE_ID)['sidebarCounters'];

        $this->assertSame(1, $counters[InvoiceConstants::STATUS_CONTABILIDAD]);
    }

    public function testRejectedCounterIsScopedToRoleAndIgnoresParents(): void
    {
        // Rechazada operable por el rol: cuenta.
        InvoiceFactory::new(['area_approval' => InvoiceConstants::APPROVAL_REJECTED])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // Rechazada agrupada en un reintegro: no cuenta.
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $conPaso = $this->_service([InvoiceConstants::STATUS_APROBACION]);
        $this->assertSame(1, $conPaso->getCounters(self::ROLE_ID)['rejectedInvoicesCount']);

        Cache::clear('sidebar');

        // Un rol que no opera `aprobacion` no ve ninguna rechazada.
        $sinPaso = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD]);
        $this->assertSame(0, $sinPaso->getCounters(self::ROLE_ID)['rejectedInvoicesCount']);
    }

    public function testOverdueCounterExcludesDocTypesWithoutRealDueDate(): void
    {
        $ayer = date('Y-m-d', strtotime('-1 day'));

        InvoiceFactory::new(['due_date' => $ayer])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $service = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD]);

        $this->assertSame(1, $service->getCounters(self::ROLE_ID)['overdueInvoicesCount']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Service/SidebarCounterInvoiceParityTest.php
```

Expected: los 3 fallan. `testStatusCountersIgnoreInvoicesWithParent` da `2` (esperaba `1`); `testRejectedCounterIsScopedToRoleAndIgnoresParents` da `2` y luego `2`; `testOverdueCounterExcludesDocTypesWithoutRealDueDate` da `2`.

- [ ] **Step 3: Replace the copied `OR` in `getInvoiceStatusCounters()`**

En `src/Service/SidebarCounterService.php`, reemplazar el cuerpo del método (líneas 130-177) por:

```php
    private function getInvoiceStatusCounters(int $roleId): array
    {
        $visibleStatuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        $statuses = array_values(array_filter(
            $visibleStatuses,
            static fn($status): bool => $status !== InvoiceConstants::STATUS_LEGALIZADA,
        ));
        if ($statuses === []) {
            return [];
        }

        // Un solo GROUP BY pipeline_status en vez de un COUNT por estado (B1).
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $rows = $invoicesTable->find('withoutParent')
            ->select([
                'pipeline_status' => 'Invoices.pipeline_status',
                'cnt' => $invoicesTable->find()->func()->count('*'),
            ])
            ->where([
                'pipeline_status IN' => $statuses,
                'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
            ])
            ->groupBy('Invoices.pipeline_status')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->pipeline_status] = (int)$row->cnt;
        }

        // Preservar la forma original: una clave por estado visible, con 0 si no
        // hay filas (antes cada COUNT vacío devolvía 0 explícitamente).
        $counters = [];
        foreach ($statuses as $status) {
            $counters[$status] = $counts[$status] ?? 0;
        }

        return $counters;
    }
```

- [ ] **Step 4: Scope the two danger counters to the role**

Reemplazar el bloque `rejectedInvoicesCount` / `overdueInvoicesCount` en `_buildCounters()` (líneas 72-79) por:

```php
            'rejectedInvoicesCount' => $this->getRejectedInvoicesCount($roleId),
            'overdueInvoicesCount' => $this->getOverdueInvoicesCount($roleId),
```

Y reemplazar `getOverdueInvoicesCount()` (líneas 179-191) por los dos métodos:

```php
    /**
     * Espejo exacto de InvoicesController::rejected(): pasos operables del rol,
     * sin facturas con padre. Si diverge, el badge miente sobre su lista.
     */
    private function getRejectedInvoicesCount(int $roleId): int
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.area_approval' => InvoiceConstants::APPROVAL_REJECTED,
                'Invoices.pipeline_status IN' => $statuses,
            ])
            ->count();
    }

    /**
     * Espejo exacto de InvoicesController::overdue(): pasos operables del rol,
     * sin facturas con padre, solo tipos de documento con vencimiento real.
     */
    private function getOverdueInvoicesCount(int $roleId): int
    {
        $statuses = $this->invoicePipeline->getVisibleStatuses($roleId);
        if ($statuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find('withoutParent')
            ->where([
                'Invoices.document_type IN' => InvoiceConstants::DOCTYPES_WITH_DUE_DATE,
                'Invoices.due_date <' => date('Y-m-d'),
                'Invoices.pipeline_status IN' => $statuses,
                'Invoices.pipeline_status NOT IN' => [
                    InvoiceConstants::STATUS_PAGADA,
                    InvoiceConstants::STATUS_LEGALIZADA,
                ],
            ])
            ->count();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/TestCase/Service/SidebarCounterInvoiceParityTest.php tests/TestCase/Service/SidebarCounterLegalizationTest.php
```

Expected: `OK (5 tests)`.

- [ ] **Step 6: Check style and commit**

```bash
composer cs-check
git add src/Service/SidebarCounterService.php tests/TestCase/Service/SidebarCounterInvoiceParityTest.php
git commit -m "fix: alinear los contadores del sidebar con los listados que anuncian"
```

---

### Task 4: Derivación del badge en la capa de presentación

El badge sale del mismo concepto que decide si la factura entra a la bandeja, no de un cálculo aparte.

**Files:**
- Create: `src/View/Presentation/InvoiceLinkBadge.php`
- Modify: `src/View/Presentation/InvoicePresentation.php` (const nueva tras `APPROVER_STATUS_MAP` línea 49; `forRow()` líneas 56-99)
- Modify: `src/View/Presentation/InvoiceRowView.php` (una propiedad)
- Test: `tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php` (crear)

**Interfaces:**
- Consumes: `InvoiceConstants::PARENT_FOREIGN_KEYS` (Task 1); las asociaciones que `all()` contiene (Task 2).
- Produces:
  - `InvoiceLinkBadge` con propiedades públicas `string $code`, `string $label`, `string $icon`, `bool $isContainment`, `string $controller`, `int $parentId`.
  - `InvoicePresentation::parentBadge(Invoice $invoice): ?InvoiceLinkBadge` — **público**, lo consume también `templates/Invoices/view.php` en la Task 6.
  - `InvoiceRowView::$linkBadge` de tipo `?InvoiceLinkBadge`.

**Por qué un archivo de test NUEVO y no ampliar `InvoicePresentationTest`:** ese
archivo extiende `PHPUnit\Framework\TestCase` puro (sin estrategia transaccional)
y su docblock se declara "100% puro". Los tests del badge tocan la base de datos
(factories con `->save()`), así que **deben** extender `Cake\TestSuite\TestCase`,
que envuelve cada test en una transacción con rollback. Mezclarlos en el archivo
puro haría COMMIT permanente en `sgi_test` y rompería la suite en la segunda
corrida por colisión de `invoice_number` (regla `isUnique`). Es la trampa
documentada en la memoria "Test DB back-to-back contamination".

- [ ] **Step 1: Write the failing tests**

Crear `tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Test\Factory\BankingEntityFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\RefundFactory;
use App\View\Presentation\InvoicePresentation;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * Derivación del badge de vinculación en forRow(). Toca la base de datos (las
 * asociaciones de padre se cargan con contain), por eso extiende
 * Cake\TestSuite\TestCase — no el InvoicePresentationTest puro.
 */
final class InvoicePresentationBadgeTest extends TestCase
{
    public function testContainedInvoiceGetsSolidBadgeAndLosesItsPipeline(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['Refunds'])->where(['refund_id' => $refund->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        $this->assertSame($refund->code, $row->linkBadge->code);
        $this->assertSame('Reintegro', $row->linkBadge->label);
        $this->assertTrue($row->linkBadge->isContainment);
        // El padre gobierna el pipeline: la factura no dibuja el suyo.
        $this->assertSame(-1, $row->stageIdx);
        $this->assertSame('pill-muted', $row->pillClass);
    }

    public function testScheduledInvoiceGetsDashedBadgeAndKeepsItsPipeline(): void
    {
        $scheduling = PaymentSchedulingFactory::new()->save();
        $bank = BankingEntityFactory::new()->save();
        $saved = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $items = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $items->saveOrFail($items->newEntity([
            'payment_scheduling_id' => $scheduling->id,
            'invoice_id' => $saved->id,
            'banking_entity_id' => $bank->id,
            'amount' => 1000.0,
        ]));

        $invoice = TableRegistry::getTableLocator()->get('Invoices')
            ->find()->contain(['PaymentSchedulingItems' => ['PaymentSchedulings']])
            ->where(['Invoices.id' => $saved->id])->firstOrFail();

        $row = InvoicePresentation::forRow($invoice);

        $this->assertNotNull($row->linkBadge);
        $this->assertSame($scheduling->code, $row->linkBadge->code);
        $this->assertFalse($row->linkBadge->isContainment);
        // La programación solo agenda el pago: la factura conserva su pipeline.
        $this->assertGreaterThanOrEqual(0, $row->stageIdx);
    }

    public function testUnlinkedInvoiceHasNoBadge(): void
    {
        $saved = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();
        $invoice = TableRegistry::getTableLocator()->get('Invoices')->get($saved->id);

        $this->assertNull(InvoicePresentation::forRow($invoice)->linkBadge);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php
```

Expected: los 3 fallan. `forRow()` aún no calcula ni pasa `linkBadge`, así que `$row->linkBadge` es una propiedad indefinida (warning + `null`) y los `assertNotNull` / `assertSame` fallan.

- [ ] **Step 3: Create the value object**

Crear `src/View/Presentation/InvoiceLinkBadge.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * Badge de vinculación de una factura con otro módulo.
 *
 * `isContainment = true`  → el registro padre gobierna el pipeline de la factura
 *                           (caja menor, reintegro, anticipo). El badge REEMPLAZA
 *                           la pipeline-mini de la fila.
 * `isContainment = false` → referencia: solo agenda el pago (programación). El
 *                           badge ACOMPAÑA a la pipeline-mini.
 */
final readonly class InvoiceLinkBadge
{
    public function __construct(
        public string $code,
        public string $label,
        public string $icon,
        public bool   $isContainment,
        public string $controller,
        public int    $parentId,
        public string $pillClass,
    ) {
    }
}
```

`pillClass` lo fija la factoría en `InvoicePresentation` (`pill-muted` para
contención, `pill-ref` para referencia). Vive en Presentation, no como ternario en
el template: así ningún mapa clase→pill queda inline, en línea con la regla
anti-drift.

- [ ] **Step 4: Add the map and the derivation**

En `src/View/Presentation/InvoicePresentation.php`, añadir la constante tras `APPROVER_STATUS_MAP` (línea 49):

```php
    /**
     * Mapa de clave foránea de contención → presentación del badge. Espejo de
     * InvoiceConstants::PARENT_FOREIGN_KEYS. El prefijo del código ya identifica
     * el tipo (CM-, RE-, ANT-), así que el badge muestra el código pelado.
     */
    public const PARENT_BADGES = [
        'petty_cash_record_id' => [
            'label' => 'Caja menor',
            'association' => 'petty_cash_record',
            'code_field' => 'code',
            'controller' => 'PettyCashRecords',
            'icon' => 'bi-link-45deg',
        ],
        'refund_id' => [
            'label' => 'Reintegro',
            'association' => 'refund',
            'code_field' => 'code',
            'controller' => 'Refunds',
            'icon' => 'bi-link-45deg',
        ],
        'advance_id' => [
            'label' => 'Anticipo',
            'association' => 'advance',
            'code_field' => 'invoice_number',
            'controller' => 'Advances',
            'icon' => 'bi-link-45deg',
        ],
    ];
```

Añadir estos dos métodos al final de la clase, antes del cierre:

```php
    /**
     * Badge del registro padre que gobierna el pipeline de la factura, si lo hay.
     *
     * Devuelve null cuando la asociación no viene contenida en el query: la
     * bandeja no la contiene porque allí la FK siempre es NULL. Un badge que
     * falta en una vista que sí debería mostrarlo lo caza el test de all().
     */
    public static function parentBadge(Invoice $invoice): ?InvoiceLinkBadge
    {
        foreach (InvoiceConstants::PARENT_FOREIGN_KEYS as $fk) {
            if (empty($invoice->{$fk})) {
                continue;
            }

            $cfg = self::PARENT_BADGES[$fk];
            if (!$invoice->hasValue($cfg['association'])) {
                return null;
            }

            return new InvoiceLinkBadge(
                code: (string)$invoice->{$cfg['association']}->{$cfg['code_field']},
                label: $cfg['label'],
                icon: $cfg['icon'],
                isContainment: true,
                controller: $cfg['controller'],
                parentId: (int)$invoice->{$fk},
                pillClass: 'pill-muted',
            );
        }

        return null;
    }

    /**
     * Badge de la programación de pagos más reciente que agenda esta factura.
     * Referencia, no contención: el estado de la factura no lo toca nadie.
     *
     * Nada en la base impide que una factura esté en dos programaciones
     * (payment_scheduling_items.invoice_id no es único). Se muestra la más
     * reciente; el contain la ordena por id DESC.
     */
    private static function schedulingBadge(Invoice $invoice): ?InvoiceLinkBadge
    {
        if (!$invoice->hasValue('payment_scheduling_items')) {
            return null;
        }

        $item = $invoice->payment_scheduling_items[0] ?? null;
        if ($item === null || !$item->hasValue('payment_scheduling')) {
            return null;
        }

        return new InvoiceLinkBadge(
            code: (string)$item->payment_scheduling->code,
            label: 'Programación',
            icon: 'bi-calendar-event',
            isContainment: false,
            controller: 'PaymentSchedulings',
            parentId: (int)$item->payment_scheduling->id,
            pillClass: 'pill-ref',
        );
    }
```

Y modificar `forRow()`. Tras la línea que calcula `$stageIdx` (línea 77), insertar:

```php
        $linkBadge = self::parentBadge($invoice) ?? self::schedulingBadge($invoice);
```

Después de la línea que calcula `$pillClass` (líneas 80-82), insertar:

```php
        // El padre gobierna el pipeline: la factura ni lo dibuja ni lo colorea.
        if ($linkBadge?->isContainment) {
            $stageIdx  = -1;
            $pillClass = 'pill-muted';
        }
```

Y añadir el argumento al constructor del DTO, tras `pillClass: $pillClass,`:

```php
            linkBadge: $linkBadge,
```

- [ ] **Step 5: Add the property to the DTO**

En `src/View/Presentation/InvoiceRowView.php`, añadir tras `public string $pillClass,`:

```php
        public ?InvoiceLinkBadge $linkBadge = null,
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php tests/TestCase/View/Presentation/InvoicePresentationTest.php tests/TestCase/View/Presentation/PipelineColorConsistencyTest.php
```

Expected: `Failures: 0`, `Errors: 0`. El `InvoicePresentationTest` puro sigue verde (sus casos no pasan FKs de padre → `linkBadge` es `null`) y `PipelineColorConsistencyTest` también.

- [ ] **Step 7: Check style and commit**

```bash
composer cs-check
git add src/View/Presentation/InvoiceLinkBadge.php src/View/Presentation/InvoicePresentation.php src/View/Presentation/InvoiceRowView.php tests/TestCase/View/Presentation/InvoicePresentationBadgeTest.php
git commit -m "feat: derivar el badge de vinculacion en InvoicePresentation"
```

---

### Task 5: Render del badge en la fila del listado

**Files:**
- Modify: `webroot/css/components.css` (clase nueva, junto a `.pill-muted` en la línea 872)
- Modify: `templates/Invoices/index.php:319-358` (columna «Estado · Pipeline»)
- Test: `tests/TestCase/Controller/InvoicesListFiltersTest.php` (añadir un método)

**Interfaces:**
- Consumes: `InvoiceRowView::$linkBadge` (Task 4); el `contain` de padres en `all()` (Task 2).
- Produces: nada que consuman tareas posteriores.

- [ ] **Step 1: Write the failing test**

Añadir a `tests/TestCase/Controller/InvoicesListFiltersTest.php`. Este test es el que caza que alguien quite el `contain` de `all()`:

```php
    public function testArchiveRendersParentBadgeWithParentCode(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_APROBACION]);

        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/all');

        $this->assertResponseOk();
        $this->assertResponseContains((string)$refund->code);
        $this->assertResponseContains('bi-link-45deg');
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php --filter testArchiveRendersParentBadgeWithParentCode
```

Expected: FAIL — el código del reintegro no aparece en la respuesta.

- [ ] **Step 3: Add the dashed pill variant**

En `webroot/css/components.css`, inmediatamente después de la regla `.pill-muted` (línea 872), añadir. El archivo escribe las variantes de pill en una línea; respeta ese estilo:

```css
/* Referencia (programación): acompaña al pipeline en vez de reemplazarlo. */
.pill-ref            { background-color: transparent; color: var(--text-muted); border: 1px dashed var(--border-color); }
```

- [ ] **Step 4: Render the badge**

En `templates/Invoices/index.php`, dentro del `<div style="display:flex;flex-wrap:wrap;gap:4px;">` de la columna 6 (línea 328), insertar el badge como **primer hijo**, antes del `<?php if ($row->isRejected): ?>` de la línea 329:

```php
                    <?php if ($row->linkBadge !== null): ?>
                        <span class="pill <?= h($row->linkBadge->pillClass) ?> pill-sm"
                              title="<?= h($row->linkBadge->label) ?> <?= h($row->linkBadge->code) ?>">
                            <i class="bi <?= h($row->linkBadge->icon) ?>" style="font-size:9px;" aria-hidden="true"></i>
                            <?= h($row->linkBadge->code) ?>
                        </span>
                    <?php endif; ?>
```

No se toca la condición `$row->stageIdx >= 0` de la línea 321: `forRow()` ya devuelve `-1` para las facturas con padre, de modo que la `pipeline-mini` desaparece sola.

- [ ] **Step 5: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php
```

Expected: `OK (7 tests)`.

- [ ] **Step 6: Check style and commit**

```bash
composer cs-check
git add webroot/css/components.css templates/Invoices/index.php tests/TestCase/Controller/InvoicesListFiltersTest.php
git commit -m "feat: badge de vinculacion en la fila del listado de facturas"
```

---

### Task 6: Aviso unificado de registro padre en la vista de la factura

Hoy conviven tres avisos con estilos y reglas distintas, y Reintegro no tiene ninguno. Un element los unifica.

**Files:**
- Create: `templates/element/invoice_parent_notice.php`
- Modify: `templates/Invoices/view.php:58-80` (reemplaza dos bloques)
- Modify: `src/Controller/InvoicesController.php:178-209` (`view()`: `contain` de `Refunds` y `Advance`)
- Test: `tests/TestCase/Controller/InvoicesListFiltersTest.php` (añadir un método)

**Interfaces:**
- Consumes: `InvoicePresentation::parentBadge()` (Task 4).
- Produces: element `invoice_parent_notice` con dos variables: `InvoiceLinkBadge $badge` y `bool $locked`.

- [ ] **Step 1: Write the failing test**

Añadir a `tests/TestCase/Controller/InvoicesListFiltersTest.php`:

```php
    public function testInvoiceViewShowsParentNoticeForRefund(): void
    {
        $this->_loginWithSteps([InvoiceConstants::STATUS_CONTABILIDAD]);

        $refund = RefundFactory::new()->save();
        $agrupada = InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $this->get('/invoices/view/' . $agrupada->id);

        $this->assertResponseOk();
        $this->assertResponseContains((string)$refund->code);
        $this->assertResponseContains('/refunds/view/' . $refund->id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php --filter testInvoiceViewShowsParentNoticeForRefund
```

Expected: FAIL — ni el código del reintegro ni el enlace aparecen.

- [ ] **Step 3: Contain the two missing parents in `view()`**

En `src/Controller/InvoicesController.php`, dentro del `contain` de `view()` (línea 178), añadir tras `'PettyCashRecords',`:

```php
            'Refunds',
            'Advance',
```

- [ ] **Step 4: Create the element**

Crear `templates/element/invoice_parent_notice.php`:

```php
<?php
/**
 * Aviso de que la factura pertenece a un registro padre, que es quien gobierna
 * su pipeline. Reemplaza los avisos sueltos de legalización y de caja menor.
 *
 * @var \App\View\AppView $this
 * @var \App\View\Presentation\InvoiceLinkBadge $badge Registro padre.
 * @var bool $locked Si además la factura está bloqueada para edición.
 */
$class = $locked ? 'alert-warning' : 'alert-info';
$icon = $locked ? 'bi-lock-fill' : 'bi-link-45deg';
?>
<div class="alert <?= $class ?> d-flex align-items-center gap-2 mb-4">
    <i class="bi <?= h($icon) ?> fs-5" aria-hidden="true"></i>
    <div>
        <?php if ($locked): ?>Factura bloqueada: pertenece<?php else: ?>Esta factura pertenece<?php endif; ?>
        al registro de <strong><?= h($badge->label) ?></strong>
        <strong><?= $this->Html->link(
            h($badge->code),
            ['controller' => $badge->controller, 'action' => 'view', $badge->parentId],
            ['class' => 'alert-link'],
        ) ?></strong>. Los cambios se gestionan desde allí.
    </div>
</div>
```

- [ ] **Step 5: Replace the two blocks in `view.php`**

En `templates/Invoices/view.php`, reemplazar íntegramente las líneas 58-80 (el bloque `isLinkedLegalization` **y** el bloque `showPettyCashLock`) por:

```php
<?php $parentBadge = \App\View\Presentation\InvoicePresentation::parentBadge($invoice); ?>
<?php if ($parentBadge !== null): ?>
    <?= $this->element('invoice_parent_notice', [
        'badge' => $parentBadge,
        'locked' => !empty($showPettyCashLock),
    ]) ?>
<?php endif; ?>
```

El bloque `showSchedulingLock` (líneas 82-89) **no se toca**: la programación no es un padre, y su alerta habla de pagos aplicados, no de pertenencia.

- [ ] **Step 6: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/TestCase/Controller/InvoicesListFiltersTest.php
```

Expected: `OK (8 tests)`.

- [ ] **Step 7: Run the full invoice suite for regressions**

```bash
vendor/bin/phpunit tests/TestCase/Controller/ tests/TestCase/View/ tests/TestCase/Service/SidebarCounterInvoiceParityTest.php
```

Expected: `Failures: 0`, `Errors: 0`. La cobertura real del reemplazo de `view.php` es el nuevo `testInvoiceViewShowsParentNoticeForRefund` (Task 6 Step 1). `InvoiceViewViewModelTest` sigue verde porque este plan **no** elimina la propiedad `isLinkedLegalization` del ViewModel, solo deja de consumirla en el template.

- [ ] **Step 8: Check style and commit**

```bash
composer cs-check
git add templates/element/invoice_parent_notice.php templates/Invoices/view.php src/Controller/InvoicesController.php tests/TestCase/Controller/InvoicesListFiltersTest.php
git commit -m "feat: aviso unificado de registro padre en la vista de factura"
```

---

### Task 7: Verificación final

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Run the full suite**

```bash
vendor/bin/phpunit
```

Expected: `Failures: 0`, `Errors: 0`. El exit code será 1 por *notices* preexistentes — es lo normal en este repo, no es una regresión. Compara el conteo de tests con el baseline previo al trabajo.

- [ ] **Step 2: Style check across the whole diff**

```bash
composer cs-check
```

Expected: sin violaciones.

- [ ] **Step 3: Verify permissions/pipeline consistency**

```bash
php bin/cake permissions_audit
```

Expected: exit 0. Este plan no toca permisos, pero el comando es la red de seguridad de la invariante "operar implica ver".

- [ ] **Step 4: Manual smoke test**

Levanta el servidor (`php bin/cake server`) y comprueba a ojo:

1. `/invoices/all` — una factura de reintegro agrupada muestra su badge con el código `RE-…`, **sin** `pipeline-mini`, con el pill de estado en gris.
2. `/invoices` — esa misma factura **no** aparece.
3. `/invoices` — una factura normal agendada en una programación muestra el badge punteado `PG-…` **y conserva** su `pipeline-mini`.
4. `/invoices/overdue` — no aparece ninguna legalización ni recibo de caja.
5. `/invoices/view/<id>` de la factura agrupada — muestra el aviso con enlace al reintegro.
6. El badge de "Mis Facturas" en el sidebar coincide con el número de filas del listado. (Si no coincide, espera cinco minutos o limpia la caché: es la deuda conocida de invalidación, no una regresión de este trabajo.)

---

## Deuda registrada (no se implementa aquí)

Ambas están documentadas en la sección «Fuera de alcance» del spec:

**No existe `isLockedByRefund`.** Una factura de reintegro agrupada puede editarse hoy entrando por URL a `/invoices/edit`, aunque su pipeline lo gobierne `RefundPipelineService`. Ocultarla de la bandeja no cierra esa puerta. Merece su propio arreglo con su propio test.

**La caché del sidebar no se invalida.** Los contadores viven cinco minutos en el config de caché `sidebar` y nadie los limpia al agrupar una factura. Este trabajo lo hace más visible, porque ahora agrupar sí cambia el conteo.
