# RBAC en la vista de legalización de anticipos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la vista de legalización de anticipos oculte los controles que el rol no puede operar, filtre la bandeja y el badge por los pasos operables del rol, y redirija a la bandeja tras avanzar de paso.

**Architecture:** El backend ya está protegido: las 16 acciones mutantes de `AdvancesController` pasan por `AdvanceLegalizationActionPolicy` y son rechazadas con `_denyAction()`. El hueco está en la capa de vista, que ramifica solo por `$leg->status`. Se añaden dos métodos al policy (`canOperateCurrentStep`, `getVisibleStatuses`), se precomputan 10 flags rol-aware en un método fábrica del controller, y el template los consume. Las dos capas quedan independientes: los flags deciden qué se dibuja, el policy sigue decidiendo qué se ejecuta.

**Tech Stack:** CakePHP 5.3, PHP 8.4, PHPUnit, CakePHP Test Factories (`tests/Factory/`), `IntegrationTestTrait`.

**Spec:** `docs/superpowers/specs/2026-07-09-rbac-vista-legalizacion-anticipos-design.md`

---

## Contexto que el implementador necesita

**No hay agujero de escritura.** No estás arreglando una vulnerabilidad de escritura; estás arreglando que la UI ofrece botones que el backend rechaza. No elimines ningún gate existente del backend.

**Dos tablas de permisos, independientes:**
- `permissions` (CRUD por módulo) → el módulo aquí se llama `advances`.
- `pipeline_permissions` (rol × pipeline × step) → el pipeline aquí se llama `legalizations`.

Son slugs distintos y **persistidos**. No los unifiques ni los "corrijas".

**El pipeline `legalizations` tiene 7 pasos operables** (`PipelineStepConstants::STEPS_BY_PIPELINE`): `validacion`, `aprobacion`, `revision_firmas`, `contabilidad`, `tesoreria`, `autorizacion_pago`, `verificacion_pago`. El estado terminal `legalizada` **no** es un paso, así que filtrar por pasos operables ya excluye las legalizaciones cerradas.

**El rol Administrador NO bypassa `advances`.** `AuthorizationService::ADMIN_BYPASS_MODULES` cubre solo `users` y `roles`. Si un test siembra un rol sin `pipeline_permissions`, no verá controles — eso es correcto.

**Cómo se corre la suite:**

```bash
vendor/bin/phpunit tests/TestCase/Ruta/DelTest.php --filter=nombreDelTest
```

No uses `composer test`. La suite sale con código 1 incluso en verde por notices preexistentes: fíjate en la línea `OK` / `FAILURES`, no en el exit code. Si ves errores en cascada tras correr varias suites seguidas, vuelve a correr solo el archivo afectado antes de concluir que hay regresión.

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php` | Dimensión rol×paso del módulo | Modificar: +2 métodos |
| `src/ViewModel/AdvanceLegalizationViewModel.php` | Agregado per-request para el template | Modificar: +10 bools |
| `src/Controller/AdvancesController.php` | HTTP, gate CRUD, fábrica del VM, redirecciones | Modificar |
| `templates/element/readonly_banner.php` | Aviso genérico de solo lectura | Crear |
| `templates/Advances/legalization.php` | Vista de trabajo | Modificar: gating |
| `templates/Advances/view.php` | Hub de consulta | Modificar: ocultar enlace |
| `templates/Invoices/add.php` | Alta de comprobante vinculado | Modificar: degradar enlace |
| `src/Controller/InvoicesController.php` | Redirect post-creación de comprobante | Modificar: ramificar destino |
| `src/Service/SidebarCounterService.php` | Badges del sidebar | Modificar: +1 dep, filtro |
| `src/Application.php` | Container DI | Modificar: 1 argumento |

**No se tocan:** `AdvanceLegalizationService`, `templates/element/advance_legalization/_linked_invoices.php`, `templates/element/advance_legalization/_soportes.php`. Los dos elements ya aceptan `$editable` y lo AND-ean con el estado; solo cambia lo que el caller les pasa.

---

## Task 1: Dos métodos nuevos en el policy

**Files:**
- Modify: `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`
- Create: `tests/TestCase/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicyTest.php`

El directorio `tests/TestCase/Service/Pipeline/Advance/Policy/` no existe todavía: créalo.

- [ ] **Step 1: Escribe el test que falla**

Crea `tests/TestCase/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\AdvanceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica los dos métodos que alimentan la capa de vista: el flag del banner
 * de solo lectura y los pasos visibles de la bandeja.
 */
final class AdvanceLegalizationActionPolicyTest extends TestCase
{
    private const ROLE_CONTABILIDAD = 2;

    public function testCanOperateCurrentStepTrueWhenRoleOperatesTheStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE_CONTABILIDAD),
                PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                AdvanceConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertTrue($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    public function testCanOperateCurrentStepFalseWhenRoleCannotOperateTheStep(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertFalse($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    /**
     * `legalizada` es terminal y no figura en STEPS_BY_PIPELINE. El policy corta
     * antes de consultar la facade — nadie "opera" una legalización cerrada.
     */
    public function testCanOperateCurrentStepFalseWhenLegalizationIsClosed(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->never())->method('canOperate');

        $leg = new AdvanceLegalization(['status' => AdvanceConstants::STATUS_LEGALIZADA]);
        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertFalse($policy->canOperateCurrentStep($leg, self::ROLE_CONTABILIDAD));
    }

    public function testGetVisibleStatusesDelegatesToOperableSteps(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('operableSteps')
            ->with(
                $this->callback(fn(UserContext $u) => $u->roleId === self::ROLE_CONTABILIDAD),
                PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            )
            ->willReturn([AdvanceConstants::STATUS_CONTABILIDAD]);

        $policy = new AdvanceLegalizationActionPolicy($auth);

        $this->assertSame(
            [AdvanceConstants::STATUS_CONTABILIDAD],
            $policy->getVisibleStatuses(self::ROLE_CONTABILIDAD),
        );
    }
}
```

- [ ] **Step 2: Corre el test para verificar que falla**

```bash
vendor/bin/phpunit tests/TestCase/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicyTest.php
```

Esperado: FAIL con `Call to undefined method ...::canOperateCurrentStep()`.

- [ ] **Step 3: Implementa los dos métodos**

En `src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php`, añade estos dos métodos públicos justo antes del `_canOperate()` privado del final:

```php
    /**
     * True cuando el rol puede operar el paso actual de la legalización.
     * Alimenta el banner de solo lectura de `templates/Advances/legalization.php`.
     *
     * La guarda `isLegalized()` es redundante — `legalizada` no figura en
     * `STEPS_BY_PIPELINE`, así que `canOperate` ya devolvería false — pero se
     * conserva explícita por paridad con `RefundActionPolicy::canOperateCurrentStep`.
     */
    public function canOperateCurrentStep(AdvanceLegalization $leg, int $roleId): bool
    {
        if ($leg->isLegalized()) {
            return false;
        }

        return $this->_canOperate($roleId, (string)$leg->status);
    }

    /**
     * Pasos del pipeline `legalizations` que el rol puede operar. Filtra la
     * bandeja `pendingLegalization` y el badge del sidebar.
     *
     * Vive aquí y no en `AdvanceLegalizationService` porque ese service no es un
     * coordinador de pipeline (ver CLAUDE.md) y no inyecta `AuthorizationFacade`.
     *
     * @return array<string>
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
        );
    }
```

Los `use` de `PipelineStepConstants`, `AdvanceLegalization` y `UserContext` ya están en el archivo. No añadas ninguno.

- [ ] **Step 4: Corre el test para verificar que pasa**

```bash
vendor/bin/phpunit tests/TestCase/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicyTest.php
```

Esperado: `OK (4 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicy.php tests/TestCase/Service/Pipeline/Advance/Policy/AdvanceLegalizationActionPolicyTest.php
git commit -m "feat: canOperateCurrentStep y getVisibleStatuses en AdvanceLegalizationActionPolicy"
```

---

## Task 2: 10 flags nuevos en el ViewModel

**Files:**
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php`
- Create: `tests/TestCase/ViewModel/AdvanceLegalizationViewModelFlagsTest.php`

El VM tiene hoy 13 parámetros y 4 bools. Quedará en 23 parámetros — por debajo de los 25 de `RefundEditViewModel`, que es el canon.

- [ ] **Step 1: Escribe el test que falla**

Crea `tests/TestCase/ViewModel/AdvanceLegalizationViewModelFlagsTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\Provider;
use App\ViewModel\AdvanceLegalizationViewModel;
use PHPUnit\Framework\TestCase;

/**
 * Los 10 flags rol-aware nuevos llegan intactos a build(), que es lo que el
 * template destructura. Sin esto, el gating de la vista es letra muerta.
 */
final class AdvanceLegalizationViewModelFlagsTest extends TestCase
{
    private function _viewModel(bool $value): AdvanceLegalizationViewModel
    {
        // El provider es obligatorio: build() lee `$invoice->provider->name` para
        // derivar el beneficiario. Sin él, PHP emite "Attempt to read property on
        // null" — no rompe hoy, pero sí el día que se active failOnWarning.
        $invoice = new Invoice([
            'id' => 1,
            'invoice_number' => 'ANT-1',
            'amount' => 1000.0,
            'provider_id' => 1,
            'provider' => new Provider(['name' => 'Proveedor de prueba', 'document_number' => '900123456']),
        ]);

        return new AdvanceLegalizationViewModel(
            invoice: $invoice,
            leg: new AdvanceLegalization(['status' => AdvanceConstants::STATUS_CONTABILIDAD]),
            roleName: 'Contabilidad',
            linkedInvoices: [],
            bankingEntities: [],
            surplusPayment: null,
            canOperateCurrentStep: $value,
            canLinkInvoices: $value,
            canUploadRelationDocument: $value,
            canMoveToAprobacion: $value,
            canMarkSigned: $value,
            canReturnToAprobacion: $value,
            canMarkExact: $value,
            canRegisterShortage: $value,
            canRegisterSurplus: $value,
            canConfirmShortage: $value,
        );
    }

    public function testBuildExposesAllTenFlagsAsTrue(): void
    {
        $built = $this->_viewModel(true)->build();

        foreach ($this->_flagNames() as $flag) {
            $this->assertTrue($built[$flag], "El flag {$flag} debería ser true");
        }
    }

    public function testFlagsDefaultToFalse(): void
    {
        $built = $this->_viewModel(false)->build();

        foreach ($this->_flagNames() as $flag) {
            $this->assertFalse($built[$flag], "El flag {$flag} debería ser false");
        }
    }

    /**
     * @return array<int, string>
     */
    private function _flagNames(): array
    {
        return [
            'canOperateCurrentStep',
            'canLinkInvoices',
            'canUploadRelationDocument',
            'canMoveToAprobacion',
            'canMarkSigned',
            'canReturnToAprobacion',
            'canMarkExact',
            'canRegisterShortage',
            'canRegisterSurplus',
            'canConfirmShortage',
        ];
    }
}
```

- [ ] **Step 2: Corre el test para verificar que falla**

```bash
vendor/bin/phpunit tests/TestCase/ViewModel/AdvanceLegalizationViewModelFlagsTest.php
```

Esperado: FAIL con `Unknown named parameter $canOperateCurrentStep`.

- [ ] **Step 3: Añade los 10 bools al constructor**

En `src/ViewModel/AdvanceLegalizationViewModel.php`, añade estos 10 parámetros **al final** del constructor, después de `public array $approvers = [],`:

```php
        public bool $canOperateCurrentStep = false,
        public bool $canLinkInvoices = false,
        public bool $canUploadRelationDocument = false,
        public bool $canMoveToAprobacion = false,
        public bool $canMarkSigned = false,
        public bool $canReturnToAprobacion = false,
        public bool $canMarkExact = false,
        public bool $canRegisterShortage = false,
        public bool $canRegisterSurplus = false,
        public bool $canConfirmShortage = false,
```

Añade este bloque al docblock del constructor, después de la línea de `@param array<int,string> $approvers`:

```php
     * @param bool $canOperateCurrentStep Pre-computado vía AdvanceLegalizationActionPolicy. Niega el banner de solo lectura.
     * @param bool $canLinkInvoices Pre-computado. Gatea `editable` de _linked_invoices y el modal de vinculación.
     * @param bool $canUploadRelationDocument Pre-computado. Gatea `editable` de _soportes.
     * @param bool $canMoveToAprobacion Pre-computado. Gatea la card de acción en `validacion`.
     * @param bool $canMarkSigned Pre-computado. Gatea el botón "Marcar como firmado".
     * @param bool $canReturnToAprobacion Pre-computado. Gatea "Devolver a Aprobación" y su modal.
     * @param bool $canMarkExact Pre-computado. Gatea la rama de caso exacto en `contabilidad`.
     * @param bool $canRegisterShortage Pre-computado. Gatea la rama de faltante.
     * @param bool $canRegisterSurplus Pre-computado. Gatea la rama de sobrante.
     * @param bool $canConfirmShortage Pre-computado. Gatea la card de Tesorería, caso faltante.
     *
     * Dos de los 15 predicados del policy no se pasan, y no es un olvido:
     * - `canUnlinkInvoice`: predicado idéntico a `canLinkInvoices` (ambos exigen
     *   `validacion`), y el element usa un único `$editable` para ambos controles.
     * - `canReturnFromAprobacion`: su botón vive en `_approval_panel` y ya está
     *   gateado por `canManageApprovers` (= `canConsolidateApproval`). Ambos
     *   predicados son equivalentes: exigen `status === 'aprobacion'` y
     *   `canOperate($roleId, 'aprobacion')`.
```

- [ ] **Step 4: Expón los 10 flags en `build()`**

En el `return` de `build()`, justo después de `'approvers' => $this->approvers,`, añade:

```php
            'canOperateCurrentStep' => $this->canOperateCurrentStep,
            'canLinkInvoices' => $this->canLinkInvoices,
            'canUploadRelationDocument' => $this->canUploadRelationDocument,
            'canMoveToAprobacion' => $this->canMoveToAprobacion,
            'canMarkSigned' => $this->canMarkSigned,
            'canReturnToAprobacion' => $this->canReturnToAprobacion,
            'canMarkExact' => $this->canMarkExact,
            'canRegisterShortage' => $this->canRegisterShortage,
            'canRegisterSurplus' => $this->canRegisterSurplus,
            'canConfirmShortage' => $this->canConfirmShortage,
```

- [ ] **Step 5: Corre el test para verificar que pasa**

```bash
vendor/bin/phpunit tests/TestCase/ViewModel/AdvanceLegalizationViewModelFlagsTest.php
```

Esperado: `OK (2 tests)`.

- [ ] **Step 6: Commit**

```bash
git add src/ViewModel/AdvanceLegalizationViewModel.php tests/TestCase/ViewModel/AdvanceLegalizationViewModelFlagsTest.php
git commit -m "feat: 10 flags rol-aware en AdvanceLegalizationViewModel"
```

---

## Task 3: Gate `edit` y método fábrica en el controller

**Files:**
- Modify: `src/Controller/AdvancesController.php:335-420` (la action `legalization()`)
- Modify: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`

**Atención:** los 4 tests de `AdvancesLegalizationRenderTest` siembran solo `advances.can_view` y esperan 200. Con `Permission('edit')` pasarán a 403, y sin `pipeline_permissions` los controles desaparecerán en la Task 4. Hay que arreglar el seed **ahora**, en la misma tarea, o dejarás la suite en rojo.

- [ ] **Step 1: Actualiza el seed del test existente**

En `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`, sustituye el método `_seedViewer()` por este par de métodos. Añade `use App\Constants\PipelineStepConstants;` a los `use` del archivo.

```php
    /**
     * Siembra un rol con `advances.can_edit` (la vista de trabajo lo exige, igual
     * que las otras 5 vistas de trabajo del proyecto) y con permiso de pipeline
     * sobre `$step`, para que los controles de ese paso se rendericen.
     */
    private function _seedOperator(string $step): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => $step,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }
```

Ahora cambia las cuatro llamadas, cada una con el paso que su escenario usa:

| Test | Sustituye | Por |
|---|---|---|
| `testOperativeViewRenders` | `$this->_seedViewer()` | `$this->_seedOperator(AdvanceConstants::STATUS_VALIDACION)` |
| `testSurplusAmountInputRendersRawValue` | `$this->_seedViewer()` | `$this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD)` |
| `testContabilidadRendersAccountingFields` | `$this->_seedViewer()` | `$this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD)` |
| `testTesoreriaRendersReadOnlyAccountingCard` | `$this->_seedViewer()` | `$this->_seedOperator(AdvanceConstants::STATUS_TESORERIA)` |

- [ ] **Step 2: Escribe el test del 403**

Añade este test al final de la misma clase:

```php
    /**
     * La vista de trabajo exige `advances.can_edit`, como las otras 5 del
     * proyecto. Un rol de solo consulta recibe 403, no la pantalla de trabajo.
     */
    public function testViewOnlyRoleIsForbidden(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => false,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseCode(403);
    }
```

- [ ] **Step 3: Corre los tests para verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Esperado: `testViewOnlyRoleIsForbidden` FALLA (recibe 200, esperaba 403). Los otros 4 pasan (el seed ya da `can_edit`, y el gate sigue siendo `view`).

- [ ] **Step 4: Cambia el gate y extrae la fábrica**

En `src/Controller/AdvancesController.php`, cambia el attribute de `legalization()`:

```php
    #[Permission(action: 'edit')]
    public function legalization(?int $id = null): ?Response
```

Y sustituye todo el cuerpo desde `$user = $this->_getCurrentUser();` (línea 368) hasta el `$this->set('viewModel', $vm);` final por:

```php
        $this->set('viewModel', $this->_buildLegalizationViewModel(
            $invoice,
            $leg,
            $this->_getCurrentUser(),
        ));

        return null;
    }

    /**
     * Fábrica del ViewModel de la vista de legalización. Espejo de
     * `RefundsController::_buildEditViewModel()`: concentra las 14 llamadas al
     * policy y la carga de datos crudos para mantener la action delgada.
     */
    private function _buildLegalizationViewModel(
        Invoice $invoice,
        AdvanceLegalization $leg,
        object $user,
    ): AdvanceLegalizationViewModel {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $roleId = (int)$user->role_id;
        $isAprobacion = $leg->status === AdvanceConstants::STATUS_APROBACION;

        // Datos crudos: el VM solo deriva, no consulta (audit CR-102).
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                'Invoices.advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->orderBy(['Invoices.issue_date' => 'ASC'])
            ->all();

        $bankingEntities = TableRegistry::getTableLocator()->get('BankingEntities')
            ->find('list')
            ->all()
            ->toArray();

        $surplusPayment = null;
        if ($leg->surplus_payment_id) {
            $surplusPayment = TableRegistry::getTableLocator()->get('InvoicePayments')->get(
                $leg->surplus_payment_id,
                contain: ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            );
        }

        return new AdvanceLegalizationViewModel(
            invoice: $invoice,
            leg: $leg,
            roleName: $user->role->name ?? '',
            linkedInvoices: $linkedInvoices,
            bankingEntities: $bankingEntities,
            surplusPayment: $surplusPayment,
            canRegisterRefund: $this->actionPolicy->canRegisterRefund($leg, $roleId),
            canAuthorizeRefundPayment: $this->actionPolicy->canAuthorizeRefundPayment($leg, $roleId),
            canConfirmRefundPayment: $this->actionPolicy->canConfirmRefundPayment($leg, $roleId),
            approvals: $isAprobacion
                ? $this->approvalService->getCurrentApprovals((int)$leg->id) : [],
            approvalSummary: $isAprobacion
                ? $this->approvalService->getApprovalSummary((int)$leg->id)
                : ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
            canManageApprovers: $this->actionPolicy->canConsolidateApproval($leg, $roleId),
            approvers: $isAprobacion
                ? $this->fetchTable('Users')->find('list', keyField: 'id', valueField: 'full_name')
                    ->where(['active' => true])->toArray()
                : [],
            canOperateCurrentStep: $this->actionPolicy->canOperateCurrentStep($leg, $roleId),
            canLinkInvoices: $this->actionPolicy->canLinkInvoices($leg, $roleId),
            canUploadRelationDocument: $this->actionPolicy->canUploadRelationDocument($leg, $roleId),
            canMoveToAprobacion: $this->actionPolicy->canMoveToAprobacion($leg, $roleId),
            canMarkSigned: $this->actionPolicy->canMarkSigned($leg, $roleId),
            canReturnToAprobacion: $this->actionPolicy->canReturnToAprobacion($leg, $roleId),
            canMarkExact: $this->actionPolicy->canMarkExact($leg, $roleId),
            canRegisterShortage: $this->actionPolicy->canRegisterShortage($leg, $roleId),
            canRegisterSurplus: $this->actionPolicy->canRegisterSurplus($leg, $roleId),
            canConfirmShortage: $this->actionPolicy->canConfirmShortage($leg, $roleId),
        );
    }
```

Añade `use App\Model\Entity\Invoice;` a los `use` del controller. `AdvanceLegalization`, `AdvanceConstants`, `InvoiceConstants`, `TableRegistry` y `AdvanceLegalizationViewModel` ya están importados.

- [ ] **Step 5: Corre los tests para verificar que pasan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Esperado: `OK (5 tests)`.

- [ ] **Step 6: Corre los tests de la vista de consulta, que no debe romperse**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php
```

Esperado: `OK`. `Advances/view` sigue con `Permission('view')`.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/AdvancesController.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "feat: legalization() exige can_edit y construye el VM en un metodo fabrica"
```

---

## Task 4: Banner de solo lectura y gating del template

**Files:**
- Create: `templates/element/readonly_banner.php`
- Modify: `templates/Advances/legalization.php`
- Modify: `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`

- [ ] **Step 1: Escribe los dos tests que fallan**

Añade a `AdvancesLegalizationRenderTest`:

```php
    /**
     * Un rol con can_edit pero sin permiso de pipeline sobre el paso actual ve la
     * vista completa en modo consulta: banner presente, formularios ausentes.
     */
    public function testRoleWithoutPipelinePermissionSeesReadonlyBanner(): void
    {
        // Opera `tesoreria`, pero la legalización está en `contabilidad`.
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(2000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Solo lectura');
        // La aserción que atrapa la regresión real: el formulario no se dibuja.
        $this->assertResponseNotContains('name="accrued"');
        $this->assertResponseNotContains('name="accrual_date"');
    }

    /**
     * Una legalización cerrada muestra su banner de cierre, no el de solo lectura.
     */
    public function testClosedLegalizationDoesNotShowReadonlyBanner(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_LEGALIZADA)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Legalizada');
        $this->assertResponseNotContains('Solo lectura');
    }
```

- [ ] **Step 2: Corre los tests para verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php --filter=ReadonlyBanner
```

Esperado: `testRoleWithoutPipelinePermissionSeesReadonlyBanner` FALLA en `assertResponseContains('Solo lectura')`.

- [ ] **Step 3: Crea el element del banner**

Crea `templates/element/readonly_banner.php`:

```php
<?php
/**
 * Banner genérico de solo lectura para las vistas de trabajo de pipeline.
 * Se muestra cuando el rol puede abrir la vista pero no operar el paso actual.
 *
 * Usa la variante `.banner info` del sistema de diseño (ver docs/design/overlays.md).
 * El espaciado lo aporta el contenedor flex del consumidor, no este element.
 *
 * @var \App\View\AppView $this
 * @var string $stepLabel Etiqueta en español del paso actual (ej. "Contabilidad").
 */
?>
<div class="banner info">
    <div class="banner-icon"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></div>
    <div class="banner-body">
        <div class="banner-title">Solo lectura</div>
        <div class="banner-msg">
            Sin permisos para operar el paso <strong><?= h($stepLabel) ?></strong>.
        </div>
    </div>
</div>
```

Tres detalles que no son cosméticos:
- **`.banner info`, no `.banner` pelado.** En `webroot/css/components.css:2133-2140`,
  el `.banner` sin variante lleva `border-left: var(--primary-color)` (verde, semántica
  de éxito) y su `.banner-icon` **no recibe fondo** — solo lo reciben `warning`,
  `danger` e `info`. El icono saldría desnudo.
- **`bi-info-circle-fill`**, como el otro banner informativo del mismo template (línea 321).
- **Voz impersonal.** `docs/design/reglas-copy.md:28-29` prohíbe "tú"/"usted" en UI.
  Sin `margin` inline: el contenedor `main.d-flex.flex-column.gap-3` ya separa hermanos.

- [ ] **Step 4: Destructura los 10 flags en el template**

En `templates/Advances/legalization.php`, dentro del array de destructuring (líneas 12-45), añade después de `'showAccountingCard' => $showAccountingCard,`:

```php
    'canOperateCurrentStep' => $canOperateCurrentStep,
    'canLinkInvoices' => $canLinkInvoices,
    'canUploadRelationDocument' => $canUploadRelationDocument,
    'canMoveToAprobacion' => $canMoveToAprobacion,
    'canMarkSigned' => $canMarkSigned,
    'canReturnToAprobacion' => $canReturnToAprobacion,
    'canMarkExact' => $canMarkExact,
    'canRegisterShortage' => $canRegisterShortage,
    'canRegisterSurplus' => $canRegisterSurplus,
    'canConfirmShortage' => $canConfirmShortage,
```

- [ ] **Step 5: Deriva el flag de la rama de Contabilidad**

En el bloque `<?php ... ?>` de las líneas 93-97 (donde se calcula `$isLegTerminal`), añade al final, antes del `?>`:

```php
// La card de Contabilidad tiene 3 ramas mutuamente excluyentes según $diff.
// Cada una se gatea con su propio predicado del policy.
$caseFlag = abs($diff) < 0.005
    ? $canMarkExact
    : ($diff > 0.005 ? $canRegisterShortage : $canRegisterSurplus);
```

- [ ] **Step 6: Aplica el gating**

Estos son los siete cambios exactos. Los números de línea son los del archivo **antes** de editarlo; aplícalos de abajo hacia arriba para que no se desplacen.

**a)** Línea 175, en el `element('advance_legalization/_linked_invoices', [...])`:

```php
            'editable' => $canLinkInvoices,
```

**b)** Justo antes de la línea 178 (`<!-- Sección: Acciones del estado -->`), inserta el banner:

```php
        <?php if (!$canOperateCurrentStep && !$isLegTerminal): ?>
        <?= $this->element('readonly_banner', [
            'stepLabel' => $legPipelineLabels[$leg->status] ?? $leg->status,
        ]) ?>
        <?php endif; ?>

```

**c)** Línea 179:

```php
        <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION && $canMoveToAprobacion): ?>
```

**d)** Línea 205:

```php
        <?php elseif ($leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS && ($canMarkSigned || $canReturnToAprobacion)): ?>
```

Y dentro de esa card, envuelve cada botón con su flag. El `<div class="d-flex flex-wrap gap-2">` de la línea 213 queda así:

```php
            <div class="d-flex flex-wrap gap-2">
                <?php if ($canMarkSigned): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar como firmado',
                    ['action' => 'markSigned', $leg->advance_invoice_id],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
                <?php endif; ?>
                <?php if ($canReturnToAprobacion): ?>
                <button type="button" class="btn btn-ghost-card spi-fg-warning" data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i>Devolver a Aprobación
                </button>
                <?php endif; ?>
            </div>
```

**e)** Línea 224:

```php
        <?php elseif ($leg->status === AdvanceConstants::STATUS_CONTABILIDAD && $caseFlag): ?>
```

**f)** Línea 282:

```php
        <?php elseif ($leg->status === AdvanceConstants::STATUS_TESORERIA && $leg->case_type === AdvanceConstants::CASE_FALTANTE && $canConfirmShortage): ?>
```

**g)** Línea 431, en el `element('advance_legalization/_soportes', [...])`:

```php
        'editable' => $canUploadRelationDocument,
```

**h)** Línea 463, el modal de vinculación:

```php
<?php if ($leg && $leg->status === AdvanceConstants::STATUS_VALIDACION && $canLinkInvoices): ?>
```

**i)** Línea 487, el modal de regresión:

```php
<?php if ($leg && $leg->status === AdvanceConstants::STATUS_REVISION_FIRMAS && $canReturnToAprobacion): ?>
```

- [ ] **Step 7: Corre toda la suite del render**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
```

Esperado: `OK (7 tests)`. Si `testContabilidadRendersAccountingFields` falla, revisa que su `_seedOperator()` reciba `STATUS_CONTABILIDAD`.

- [ ] **Step 8: Verifica el estilo de código**

```bash
composer cs-check
```

Esperado: sin errores en los archivos tocados.

- [ ] **Step 9: Commit**

```bash
git add templates/element/readonly_banner.php templates/Advances/legalization.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "feat: banner de solo lectura y gating rol-aware en la vista de legalizacion"
```

---

## Task 5: Redirección híbrida tras las transiciones

**Files:**
- Modify: `src/Controller/AdvancesController.php`
- Create: `tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php`

Regla: **avanzar o regresar → la bandeja. Cerrar → `view`. Fallar → quedarse.**

- [ ] **Step 1: Escribe los tests que fallan**

Crea `tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * El destino tras cada transición: avanzar/regresar devuelve a la bandeja,
 * cerrar lleva al hub de consulta, fallar deja al usuario donde estaba.
 */
class AdvancesLegalizationRedirectTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // assertFlashMessage() lee de _requestSession, que solo recibe el flash
        // re-inyectado si retain está activo (ver EmployeesDocumentUploadTest:20).
        $this->enableRetainFlashMessages();
    }

    private function _seedOperator(string $step): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
            'role_id' => $role->id,
            'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            'step' => $step,
            'can_operate' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    /**
     * Caso exacto: cierra la legalización → hub de consulta del anticipo.
     */
    public function testMarkExactRedirectsToView(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_CONTABILIDAD);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/mark-exact/' . $anticipo->id, [
            'expected_status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'accrued' => '1',
            'accrual_date' => '2026-07-09',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertRedirect('/advances/view/' . $anticipo->id);
    }

    /**
     * Mueve de paso: vuelve a la bandeja, que es donde el rol elige el siguiente.
     */
    public function testMoveToAprobacionRedirectsToPendingLegalization(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion.pdf',
            'file_name' => 'relacion.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/move-to-aprobacion/' . $anticipo->id);

        $this->assertRedirect('/advances/pending-legalization');
    }

    /**
     * Un rol sin permiso de pipeline sobre `contabilidad` no puede cerrar la
     * legalización: el gate `_denyAction()` lo rechaza y el estado no cambia.
     * Verifica que la Task 4 (ocultar controles) no sustituyó al gate del POST.
     */
    public function testMarkExactDeniedForRoleWithoutPermission(): void
    {
        // Opera `tesoreria`, no `contabilidad`.
        $user = $this->_seedOperator(AdvanceConstants::STATUS_TESORERIA);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withAmount(1000000.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/mark-exact/' . $anticipo->id, [
            'expected_status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'accrued' => '1',
            'accrual_date' => '2026-07-09',
            'ready_for_payment' => InvoiceConstants::READY_FOR_PAYMENT_SI,
        ]);

        $this->assertRedirect('/advances/legalization/' . $anticipo->id);
        $this->assertFlashMessage('No tienes permiso para esta acción en el estado actual.');

        $reloaded = TableRegistry::getTableLocator()->get('AdvanceLegalizations')->get($leg->id);
        $this->assertSame(AdvanceConstants::STATUS_CONTABILIDAD, $reloaded->status);
    }

    /**
     * Si la transición falla, el usuario NO se va a la bandeja: se queda en la
     * vista para leer el flash de error sin perder el contexto.
     */
    public function testFailedTransitionStaysOnLegalizationView(): void
    {
        $user = $this->_seedOperator(AdvanceConstants::STATUS_VALIDACION);

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        // Sin facturas vinculadas ni relación adjunta → moveToAprobacion falla.
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post('/advances/move-to-aprobacion/' . $anticipo->id);

        $this->assertRedirect('/advances/legalization/' . $anticipo->id);
    }
}
```

- [ ] **Step 2: Corre los tests para verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php
```

Esperado: `testMarkExactRedirectsToView` y `testMoveToAprobacionRedirectsToPendingLegalization` FALLAN (redirigen a `/advances/legalization/{id}`). `testFailedTransitionStaysOnLegalizationView` y `testMarkExactDeniedForRoleWithoutPermission` ya pasan: verifican el gate `_denyAction()` preexistente, que este plan **no** toca. Que sigan verdes al final de la Task 5 es justamente el punto.

- [ ] **Step 3: Añade el helper de redirección**

En `src/Controller/AdvancesController.php`, junto a `_denyAction()` y `_redirectMissing()`, añade:

```php
    /**
     * Destino tras una transición exitosa. El service muta `$leg->status` in-place
     * sobre la instancia recibida (`AdvanceLegalizationService::_setStatus()`, y
     * `registerRefundPayment()` que asigna directo), así que la entidad ya refleja
     * el estado nuevo cuando se llama a este helper.
     *
     * Cerrar la legalización lleva al hub de consulta (patrón de
     * `RefundsController::confirmPayment`); mover de paso devuelve a la bandeja.
     *
     * SOLO se invoca en el camino de éxito. En fallo, cada acción conserva su
     * redirect a `legalization/{id}` para que el usuario lea el flash sin perder
     * el contexto: una transición fallida deja `$leg->status` sin cambiar, y este
     * helper lo mandaría a la bandeja por error.
     */
    private function _redirectAfterTransition(AdvanceLegalization $leg, int $advanceId): Response
    {
        return $leg->isLegalized()
            ? $this->redirect(['action' => 'view', $advanceId])
            : $this->redirect(['action' => 'pendingLegalization']);
    }
```

- [ ] **Step 4: Aplica el helper a las 11 acciones que mueven o cierran el paso**

En cada una, sustituye el bloque final `if ($result->success) { ... } else { ... } return $this->redirect(['action' => 'legalization', $id]);` por el patrón de abajo, conservando el mensaje de éxito y de error que cada acción ya tiene.

Patrón (ejemplo con `moveToAprobacion`):

```php
        if (!$result->success) {
            $this->Flash->error($result->firstError() ?? 'Error al avanzar.');

            return $this->redirect(['action' => 'legalization', $id]);
        }

        $this->Flash->success('Legalización enviada a Aprobación de área.');

        return $this->_redirectAfterTransition($leg, (int)$id);
```

Las 11 acciones y su mensaje de éxito actual (no lo cambies):

| Acción | Mensaje de éxito | Destino en éxito |
|---|---|---|
| `moveToAprobacion` | `Legalización enviada a Aprobación de área.` | bandeja |
| `moveToRevision` | `Aprobación consolidada. Legalización enviada a Revisión y Firmas.` | bandeja |
| `returnFromAprobacion` | `Legalización regresada a Validación. Los enlaces de aprobación fueron invalidados.` | bandeja |
| `markSigned` | `Documento marcado como firmado.` | bandeja |
| `returnToAprobacion` | `Legalización devuelta a Aprobación.` | bandeja |
| `registerShortage` | `Faltante registrado. La legalización pasó a Tesorería.` | bandeja |
| `registerSurplus` | `Sobrante registrado. La legalización pasó a Tesorería.` | bandeja |
| `registerRefund` | `Reintegro registrado. Pendiente de autorización por el Contador.` | bandeja |
| `markExact` | `Anticipo legalizado (caso exacto).` | `view` |
| `confirmShortage` | `Consignación confirmada. Anticipo legalizado.` | `view` |
| `confirmRefundPayment` | `Reintegro confirmado. La legalización quedó cerrada.` | `view` |

En `returnFromAprobacion`, la llamada `$this->approvalService->supersedeAll((int)$leg->id);` va **antes** del `Flash->success`, dentro del camino de éxito. No la muevas al camino de fallo.

En `confirmShortage`, el bloque JSON de éxito se mantiene tal cual por ahora — lo tocarás en la Task 8. Solo cambia el camino no-JSON.

**No toques** `linkInvoices`, `unlinkInvoice`, `uploadRelationDocument`, `sendApprovalLinks`, `modifyApprovers` ni `linkCandidates`: no mueven el paso.

- [ ] **Step 5: Corre los tests para verificar que pasan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php
```

Esperado: `OK (4 tests)`.

- [ ] **Step 6: Corre los tests de integración del ciclo de vida, que no deben romperse**

```bash
vendor/bin/phpunit tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php tests/TestCase/Service/Integration/AdvanceLegalizationTransitionsTest.php
```

Esperado: `OK`. Estos tests llaman al service directamente, no al controller: no deberían verse afectados. Si fallan, has tocado el service por error.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/AdvancesController.php tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php
git commit -m "feat: redireccion hibrida tras las transiciones de legalizacion"
```

---

## Task 6: Bandeja filtrada por los pasos operables del rol

**Files:**
- Modify: `src/Controller/AdvancesController.php:198-233` (`pendingLegalization()`)
- Create: `tests/TestCase/Controller/AdvancesPendingLegalizationTest.php`

- [ ] **Step 1: Escribe los tests que fallan**

Crea `tests/TestCase/Controller/AdvancesPendingLegalizationTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\User;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * La bandeja "Pendientes de Legalización" solo lista las legalizaciones cuyo
 * paso actual el rol puede operar, como las bandejas de los otros 5 módulos.
 */
class AdvancesPendingLegalizationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @param array<int, string> $steps Pasos operables. Vacío = ningún paso.
     */
    private function _seedRole(array $steps): User
    {
        $role = RoleFactory::new()->save();

        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => true,
        ]));

        $pipelinePermissions = TableRegistry::getTableLocator()->get('PipelinePermissions');
        foreach ($steps as $step) {
            $pipelinePermissions->saveOrFail($pipelinePermissions->newEntity([
                'role_id' => $role->id,
                'pipeline' => PipelineStepConstants::PIPELINE_LEGALIZATIONS,
                'step' => $step,
                'can_operate' => true,
            ]));
        }

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function _seedLegalization(string $status, string $invoiceNumber): void
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new([
            'provider_id' => $provider->id,
            'invoice_number' => $invoiceNumber,
        ])->anticipo()->withStatus(InvoiceConstants::STATUS_PAGADA)->save();

        AdvanceLegalizationFactory::new()->forAdvance($anticipo)->withStatus($status)->save();
    }

    public function testListsOnlyLegalizationsOnOperableSteps(): void
    {
        $user = $this->_seedRole([AdvanceConstants::STATUS_CONTABILIDAD]);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-CONTA');
        $this->_seedLegalization(AdvanceConstants::STATUS_TESORERIA, 'ANT-TESO');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseContains('ANT-CONTA');
        $this->assertResponseNotContains('ANT-TESO');
    }

    public function testRoleWithoutOperableStepsSeesEmptyList(): void
    {
        $user = $this->_seedRole([]);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD, 'ANT-CONTA');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ANT-CONTA');
    }

    /**
     * `legalizada` no figura en STEPS_BY_PIPELINE, así que el filtro por pasos
     * operables ya excluye las cerradas aunque el rol opere todos los pasos.
     */
    public function testClosedLegalizationsNeverAppear(): void
    {
        $user = $this->_seedRole(PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS]);
        $this->_seedLegalization(AdvanceConstants::STATUS_LEGALIZADA, 'ANT-CERRADA');

        $this->session(['Auth' => $user]);
        $this->get('/advances/pending-legalization');

        $this->assertResponseOk();
        $this->assertResponseNotContains('ANT-CERRADA');
    }
}
```

- [ ] **Step 2: Corre los tests para verificar que fallan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesPendingLegalizationTest.php
```

Esperado: `testListsOnlyLegalizationsOnOperableSteps` y `testRoleWithoutOperableStepsSeesEmptyList` FALLAN. `testClosedLegalizationsNeverAppear` ya pasa (el `!= legalizada` actual).

- [ ] **Step 3: Filtra la query**

En `src/Controller/AdvancesController.php`, dentro de `pendingLegalization()`, sustituye el `innerJoinWith` por:

```php
        $visibleStatuses = $this->actionPolicy->getVisibleStatuses(
            (int)$this->_getCurrentUser()->role_id,
        );

        // `_visibleStatusConditions` devuelve `1 = 0` cuando el rol no opera
        // ningún paso, así que la bandeja sale vacía sin caso especial.
        $stepConditions = $this->_visibleStatusConditions(
            'AdvanceLegalization.status',
            $visibleStatuses,
        );

        $query = $invoicesTable->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', function ($q) use ($stepConditions) {
                // El `!= legalizada` es redundante — `legalizada` no es un step —
                // pero se conserva como defensa en profundidad.
                return $q->where($stepConditions)->where([
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            })
            ->contain([
                'Providers',
                'Employees',
                'OperationCenters',
                'AdvanceLegalization',
            ])
            ->orderBy(['Invoices.created' => 'DESC']);
```

Deja intactos el filtro de búsqueda, el `paginate()` y el `$this->render('index')` que vienen después. La variable `$visibleStatuses` que se pasa a la vista sigue siendo `[]` — esa se usa para los chips del pipeline de facturas, no para la legalización:

```php
        $advances = $this->paginate($query);
        $visibleStatuses = [];
        $this->set(compact('advances', 'visibleStatuses'));
        $this->render('index');
```

- [ ] **Step 4: Corre los tests para verificar que pasan**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesPendingLegalizationTest.php
```

Esperado: `OK (3 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/AdvancesController.php tests/TestCase/Controller/AdvancesPendingLegalizationTest.php
git commit -m "feat: bandeja de legalizaciones filtrada por los pasos operables del rol"
```

---

## Task 7: Badge del sidebar filtrado por rol

**Files:**
- Modify: `src/Service/SidebarCounterService.php`
- Modify: `src/Application.php:458-463`
- Create: `tests/TestCase/Service/SidebarCounterLegalizationTest.php`

`getCounters(int $roleId)` ya cachea con la clave `sidebar_counters_{$roleId}`, así que un contador que dependa del rol es cache-safe. No toques el cacheo.

- [ ] **Step 1: Escribe el test que falla**

Crea `tests/TestCase/Service/SidebarCounterLegalizationTest.php`:

Ningún test del proyecto resuelve servicios desde el container: el patrón establecido es instanciar a mano con un stub de `AuthorizationFacade` (ver `RefundAprobacionFlowTest::buildService()`). Los cuatro pipeline services se stubean porque este test solo mira un contador; sus stubs devuelven `[]` para `getVisibleStatuses()` por el tipo de retorno declarado.

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\Service\NoveltyPipelineService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\RefundPipelineService;
use App\Service\SidebarCounterService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

/**
 * El badge "Pendientes de Legalización" cuenta solo lo que el rol puede operar,
 * igual que sus 5 contadores hermanos.
 */
class SidebarCounterLegalizationTest extends TestCase
{
    /** Ids arbitrarios: solo sirven como clave de caché, el stub ignora el rol. */
    private const ROLE_CONTABILIDAD = 101;
    private const ROLE_TESORERIA = 102;
    private const ROLE_SIN_PASOS = 103;

    public function setUp(): void
    {
        parent::setUp();
        // `getCounters()` cachea en el config `sidebar` (SidebarCounterService:57),
        // NO en `default`. Limpiar `default` aquí sería un no-op silencioso.
        Cache::clear('sidebar');
    }

    /**
     * @param array<int, string> $operableSteps Pasos que el rol puede operar.
     */
    private function _service(array $operableSteps): SidebarCounterService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn($operableSteps);

        return new SidebarCounterService(
            $this->createStub(InvoicePipelineService::class),
            $this->createStub(NoveltyPipelineService::class),
            $this->createStub(PettyCashPipelineService::class),
            $this->createStub(RefundPipelineService::class),
            new AdvanceLegalizationActionPolicy($auth),
        );
    }

    private function _seedLegalization(string $status): void
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)->withStatus($status)->save();
    }

    public function testCountsOnlyLegalizationsOnOperableSteps(): void
    {
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);
        $this->_seedLegalization(AdvanceConstants::STATUS_TESORERIA);

        $contabilidad = $this->_service([AdvanceConstants::STATUS_CONTABILIDAD]);
        $this->assertSame(
            2,
            $contabilidad->getCounters(self::ROLE_CONTABILIDAD)['advancesPendingLegalizationCount'],
        );

        $tesoreria = $this->_service([AdvanceConstants::STATUS_TESORERIA]);
        $this->assertSame(
            1,
            $tesoreria->getCounters(self::ROLE_TESORERIA)['advancesPendingLegalizationCount'],
        );
    }

    public function testRoleWithoutOperableStepsCountsZero(): void
    {
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);

        $sinPasos = $this->_service([]);
        $this->assertSame(
            0,
            $sinPasos->getCounters(self::ROLE_SIN_PASOS)['advancesPendingLegalizationCount'],
        );
    }
}
```

Si `getCounters()` resulta frágil porque otro contador explota con los stubs, no cambies el diseño: reduce el test a construir el service y llamar solo al contador, marcando `getAdvancesPendingLegalizationCount` como `public` **no** es opción — usa reflexión o cubre el filtro con el test de la bandeja (Task 6), que ya lo ejercita end-to-end.

- [ ] **Step 2: Corre el test para verificar que falla**

```bash
vendor/bin/phpunit tests/TestCase/Service/SidebarCounterLegalizationTest.php
```

Esperado: FAIL — ambos roles cuentan 3.

- [ ] **Step 3: Inyecta el policy en el servicio**

En `src/Service/SidebarCounterService.php`, añade el `use`:

```php
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
```

Y el quinto parámetro del constructor, con su línea de docblock:

```php
     * @param \App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy $legalizationPolicy Pasos operables del pipeline `legalizations`.
     */
    public function __construct(
        private readonly InvoicePipelineService $invoicePipeline,
        private readonly NoveltyPipelineService $noveltyPipeline,
        private readonly PettyCashPipelineService $pettyCashService,
        private readonly RefundPipelineService $refundService,
        private readonly AdvanceLegalizationActionPolicy $legalizationPolicy,
    ) {
        $this->logger = new StructuredLogger('Sidebar');
    }
```

- [ ] **Step 4: Filtra el contador**

Sustituye `getAdvancesPendingLegalizationCount()` por:

```php
    /**
     * Cuenta los anticipos pendientes de legalización cuyo paso actual el rol
     * puede operar. Espejo de `AdvancesController::pendingLegalization()`.
     *
     * Ojo: `getAdvancesMineCount()` usa `invoicePipeline` porque el Anticipo vive
     * en el pipeline de **facturas**. La legalización es otro pipeline y necesita
     * otra fuente — de ahí `legalizationPolicy`.
     */
    private function getAdvancesPendingLegalizationCount(int $roleId): int
    {
        $visibleStatuses = $this->legalizationPolicy->getVisibleStatuses($roleId);
        if ($visibleStatuses === []) {
            return 0;
        }

        return TableRegistry::getTableLocator()->get('Invoices')->find()
            ->where([
                'Invoices.document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
                'Invoices.pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            ])
            ->innerJoinWith('AdvanceLegalization', function ($q) use ($visibleStatuses) {
                return $q->where([
                    'AdvanceLegalization.status IN' => $visibleStatuses,
                    'AdvanceLegalization.status !=' => AdvanceConstants::STATUS_LEGALIZADA,
                ]);
            })
            ->count();
    }
```

Y en el array de contadores (línea 99, dentro del closure que `getCounters()` memoiza), pasa el rol:

```php
            'advancesPendingLegalizationCount' => $this->getAdvancesPendingLegalizationCount($roleId),
```

- [ ] **Step 5: Registra la dependencia en el container**

En `src/Application.php`, línea 458:

```php
        $container->addShared(SidebarCounterService::class)
            ->addArguments([
                InvoicePipelineService::class,
                NoveltyPipelineService::class,
                PettyCashPipelineService::class,
                RefundPipelineService::class,
                AdvanceLegalizationActionPolicy::class,
            ]);
```

`AdvanceLegalizationActionPolicy` ya está importado y registrado (línea 267). No añadas el `use`.

- [ ] **Step 6: Corre el test y la suite del sidebar**

```bash
vendor/bin/phpunit tests/TestCase/Service/SidebarCounterLegalizationTest.php
```

Esperado: `OK (2 tests)`.

- [ ] **Step 7: Commit**

```bash
git add src/Service/SidebarCounterService.php src/Application.php tests/TestCase/Service/SidebarCounterLegalizationTest.php
git commit -m "feat: badge de legalizaciones pendientes filtrado por rol"
```

---

## Task 8: Caminos a la vista y redirect del JS

**Files:**
- Modify: `templates/Advances/view.php:168-172`
- Modify: `templates/Invoices/add.php:35-46`
- Modify: `src/Controller/InvoicesController.php:288` (redirect huérfano)
- Modify: `src/Controller/AdvancesController.php` (`confirmShortage`, respuesta JSON)
- Modify: `templates/Advances/legalization.php` (JS, línea 546)
- Modify: `tests/TestCase/Controller/AdvancesViewTest.php`
- Modify: `tests/TestCase/Controller/InvoicesControllerTest.php`

Con `Permission('edit')` en `legalization()`, un rol de solo consulta que llegue por estos caminos recibe un 403. **Hay tres caminos, no dos.** Dos son enlaces y se ocultan; el tercero es un redirect y no se puede ocultar: hay que ramificar su destino.

El redirect lo detectó la revisión de calidad de la Task 3, después de escribir este plan: yo había buscado enlaces solo en `templates/`, no en `src/`. Es el peor de los tres, porque el usuario acaba de guardar con éxito y aterriza en un 403.

- [ ] **Step 1: Escribe el test que falla**

Añade a `tests/TestCase/Controller/AdvancesViewTest.php` (importa `RoleFactory`, `UserFactory` y `TableRegistry` si no lo están):

```php
    /**
     * El hub de consulta no ofrece el botón hacia la vista de trabajo a quien no
     * tiene `advances.can_edit`: ese enlace terminaría en un 403.
     */
    public function testManageLegalizationButtonHiddenWithoutEditPermission(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
            'can_edit' => false,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $this->session(['Auth' => $user]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseNotContains('Gestionar legalización');
    }
```

- [ ] **Step 2: Corre el test para verificar que falla**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php --filter=testManageLegalizationButtonHidden
```

Esperado: FAIL — el botón está presente.

- [ ] **Step 3: Oculta el botón en el hub de consulta**

En `templates/Advances/view.php`, envuelve el `Html->link` de la línea 168:

```php
                <?php if (!empty($userPermissions['advances']['can_edit'])): ?>
                <?= $this->Html->link(
                    'Gestionar legalización<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>',
                    ['action' => 'legalization', $invoice->id],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
                <?php endif; ?>
```

- [ ] **Step 4: Degrada el enlace embebido de `Invoices/add.php`**

El enlace vive dentro de una oración: ocultarlo dejaría "Comprobante para el .". Sustituye las líneas 35-46 por:

```php
    <?php if (!empty($advance)) : ?>
        <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>
            <span>Comprobante para el
                <?php $advanceLabel = 'Anticipo #' . ($advance->invoice_number ?: $advance->id); ?>
                <?php if (!empty($userPermissions['advances']['can_edit'])): ?>
                <?= $this->Html->link(
                    h($advanceLabel),
                    ['controller' => 'Advances', 'action' => 'legalization', $advance->id],
                ) ?>.
                <?php else: ?>
                <strong><?= h($advanceLabel) ?></strong>.
                <?php endif; ?>
            </span>
        </div>
        <?= $this->Form->control('advance_id', ['type' => 'hidden']) ?>
    <?php endif; ?>
```

- [ ] **Step 4b: Ramifica el redirect huérfano de `InvoicesController`**

`InvoicesController::add()` redirige a `Advances::legalization` tras crear un
comprobante vinculado (línea 288). No es un enlace: no se puede ocultar. Un
usuario con `invoices.can_create` pero sin `advances.can_edit` guardaría con
éxito y caería en un 403.

Primero el test. En `tests/TestCase/Controller/InvoicesControllerTest.php`, con un
rol que tenga `invoices.can_create` pero **no** `advances.can_edit`, haz POST a
`/invoices/add` con `advance_id` apuntando a un anticipo pagado con legalización
abierta, y asserta:

```php
$this->assertRedirect('/advances/view/' . $anticipo->id);
```

Reutiliza el fixture de los tests que ya existen en ese archivo para crear una
factura válida. Si no hay ninguno que cree un comprobante vinculado, mira
`AdvanceReciboCajaFreezeTest` para el shape de los datos.

Luego el cambio, en `src/Controller/InvoicesController.php:288`:

```php
                    // El destino depende del permiso: `legalization` exige
                    // `advances.can_edit`. Sin él, el usuario iría a un 403 justo
                    // después de guardar con éxito.
                    return $this->redirect([
                        'controller' => 'Advances',
                        'action' => $this->_checkPermission('advances', 'edit')
                            ? 'legalization'
                            : 'view',
                        $advanceId,
                    ]);
```

`AppController::_checkPermission()` es `protected` y ya se usa así en
`AssetsController:80`, `ConsumablesController:47` y `PettyCashRecordsController:345`.
No añadas ningún `use`.

Verifica que el camino feliz sigue intacto: un rol **con** `advances.can_edit` debe
seguir aterrizando en `/advances/legalization/{id}`. Escribe también ese test.

- [ ] **Step 5: Devuelve el destino en la respuesta JSON de `confirmShortage`**

`confirmShortage` cierra la legalización y debe llevar a `view`, pero responde JSON y el JS recarga la página. En `src/Controller/AdvancesController.php`, dentro de `confirmShortage`, el bloque JSON de éxito pasa a:

```php
        if ($this->_isJsonRequest()) {
            if ($result->success) {
                return $this->_jsonResponse([
                    'success' => true,
                    'redirect' => Router::url(['action' => 'view', $id]),
                ]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => $result->firstError() ?? 'Error al confirmar consignación.']);
        }
```

Añade `use Cake\Routing\Router;` a los `use` del controller.

- [ ] **Step 6: Haz que el JS respete el destino**

En `templates/Advances/legalization.php`, línea 546 (dentro del handler de `shortageForm`):

```js
                if (data.success) {
                    if (data.redirect) { window.location = data.redirect; }
                    else { window.location.reload(); }
                } else {
```

**No toques** el handler de `rel-doc-trigger` (línea 587): `uploadRelationDocument` no mueve el paso y debe seguir recargando. El campo `redirect` es opcional, así que si algún día ese endpoint lo devuelve, el patrón ya funcionaría.

- [ ] **Step 7: Corre los tests**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php
```

Esperado: `OK`.

- [ ] **Step 8: Verifica el estilo**

```bash
composer cs-check
```

Esperado: sin errores.

- [ ] **Step 9: Commit**

```bash
git add templates/Advances/view.php templates/Invoices/add.php templates/Advances/legalization.php src/Controller/AdvancesController.php src/Controller/InvoicesController.php tests/TestCase/Controller/AdvancesViewTest.php tests/TestCase/Controller/InvoicesControllerTest.php
git commit -m "feat: cerrar los 3 caminos a la vista de trabajo sin can_edit y redirigir tras cerrar por AJAX"
```

---

## Task 9: Verificación final

**Files:** ninguno. Esta tarea solo verifica.

- [ ] **Step 1: Corre toda la suite del módulo**

```bash
vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php tests/TestCase/Controller/AdvancesLegalizationRedirectTest.php tests/TestCase/Controller/AdvancesPendingLegalizationTest.php tests/TestCase/Controller/AdvancesViewTest.php tests/TestCase/Controller/AdvancesGroupApprovalTest.php
```

Esperado: `OK`. Si aparecen errores en cascada, vuelve a correr cada archivo por separado antes de concluir que hay regresión.

- [ ] **Step 2: Corre los tests de servicio del módulo**

```bash
vendor/bin/phpunit tests/TestCase/Service/Pipeline/Advance/ tests/TestCase/Service/Integration/
```

Esperado: `OK`. Ninguno de los 8 tests que instancian `new AdvanceLegalizationService(...)` debería haberse tocado.

- [ ] **Step 3: Corre la suite completa**

```bash
vendor/bin/phpunit
```

Esperado: la línea de resumen debe mostrar el mismo número de fallos que el baseline (0 failures; los notices preexistentes no cuentan). Ignora el exit code 1.

- [ ] **Step 4: Estilo de código**

```bash
composer cs-check
```

Esperado: sin errores. Si los hay, `composer cs-fix`.

- [ ] **Step 5: Verifica la matriz de permisos contra la base de datos real**

**Este paso no es opcional.** El cambio de `Permission('view')` a `Permission('edit')` introduce un acoplamiento nuevo, "operar implica editar", que ningún comando enforcea. `bin/cake permissions_audit` **no** lo detecta: compara `can_operate` contra `can_view`, no contra `can_edit`.

```sql
SELECT r.name, pp.step, p.can_edit
FROM pipeline_permissions pp
JOIN roles r ON r.id = pp.role_id
LEFT JOIN permissions p ON p.role_id = r.id AND p.module = 'advances'
WHERE pp.pipeline = 'legalizations' AND pp.can_operate = 1;
```

Toda fila con `can_edit` falso o nulo es un rol que **pierde el acceso** a la vista de legalización. Presta atención especial al rol Administrador: no bypassa `advances`. Se arregla desde `/roles/edit`, no con código. Reporta al usuario los roles afectados antes de desplegar.

- [ ] **Step 6: Corre el audit de permisos**

```bash
php bin/cake permissions_audit
```

Esperado: exit 0. Verifica la invariante "operar implica ver", que este cambio no altera. Si sale 1, hay un desajuste preexistente que conviene reportar.

---

## Cobertura del spec

| Sección del spec | Task |
|---|---|
| Policy: `canOperateCurrentStep`, `getVisibleStatuses` | 1 |
| ViewModel: 10 bools sueltos | 2 |
| Controller: `Permission('edit')` + `_buildLegalizationViewModel` | 3 |
| Element `readonly_banner` + gating del template | 4 |
| Redirección híbrida + camino de fallo | 5 |
| Testing: `POST markExact` sin permiso → denegación, estado sin cambios | 5 |
| Bandeja `pendingLegalization` filtrada | 6 |
| `SidebarCounterService` + DI en `Application.php` | 7 |
| Enlaces (`Advances/view.php`, `Invoices/add.php`) | 8 |
| Redirect huérfano (`InvoicesController:288`) | 8 |
| Respuesta AJAX con `redirect` | 8 |
| Verificación de la matriz de permisos | 9 |
