# Gate de soportes por paso de pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Autorizar la gestión de soportes (subir/borrar/reemplazar) en los módulos de flujo por el paso de pipeline del registro (`canOperateStep`), no por el CRUD del módulo, replicando el patrón de Refunds.

**Architecture:** Cada acción documental deja de usar `#[Permission(...)]` (gate CRUD en `AppController`) y pasa a `#[PipelineAction(pipeline: X)]` dinámica (que salta el gate CRUD) + un método privado `_documentGate()` por controlador que valida estado-terminal→409 y `canOperateStep`→403. Para `NoveltyLiquidationDocs` el gate delega en `NoveltyPipelineService::denialReasonForAdvanceGroup` (autoriza contra el pipeline `liquidation_docs`). El flag de UI del botón "eliminar" se alinea a criterio terminal-only (solo Invoices lo tiene hoy por rol).

**Tech Stack:** CakePHP 5.3, PHP 8.4+, PHPUnit + CakePHP Fixture Factories, PHP CS (CakePHP standard).

## Global Constraints

- Slugs de pipeline (verbatim, `PipelineStepConstants`): `PIPELINE_INVOICES='invoices'`, `PIPELINE_PETTY_CASH='petty_cash'`, `PIPELINE_PAYMENT_SCHEDULINGS='payment_schedulings'`, `PIPELINE_NOVELTIES='novelties'`, `PIPELINE_LIQUIDATION_DOCS='liquidation_docs'`. **Inmutables** — no cambiar tablas ni slugs.
- `#[PipelineAction(pipeline: X)]` **sin** `step` es dinámica: `AppController::_applyAuthAttribute` retorna temprano (salta gate CRUD); el método hace su propio gate inline.
- Servicios/policies se obtienen vía `$container->get(...)` en `initialize()` (patrón existente en todos los controllers). **No** usar `?? new` en controllers.
- No hardcodear strings de estado: usar las constantes `*Constants::STATUS_*`.
- Columna de estado por entidad: `Invoice.pipeline_status`, `PettyCashRecord.status`, `PaymentScheduling.pipeline_status`, `EmployeeNovelty.pipeline_status`, `NoveltyLiquidationDoc.pipeline_status`.
- Helper de estado terminal por entidad: `Invoice::isInFinalState()` (pagada+legalizada), `PettyCashRecord::isPagada()`, `PaymentScheduling::isPagada()`, `EmployeeNovelty::isPaid()`/`isRejected()`, liquidación → enum `NoveltyPipelineStatus::isTerminal()` (pagada+rechazada) vía el service.
- Tests: CakePHP Fixture Factories (`App\Test\Factory\*`) + siembra directa de `permissions`/`pipeline_permissions` con `TableRegistry`. Login: `$this->session(['Auth' => $user])`. CSRF: `$this->enableCsrfToken()`. Baseline verde ~843 tests (`vendor/bin/phpunit`).
- CS: `composer cs-check` debe pasar; usar `composer cs-fix` antes de cada commit.

---

### Task 1: Auditoría previa de permisos (read-only, sin código de producción)

Antes de retirar el gate CRUD, verificar qué roles gestionan soportes hoy vía `can_create`/`can_edit`/`can_delete` **sin** operar ningún paso del pipeline del módulo (perderían la capacidad). Es análisis; produce un reporte en el propio spec/PR.

**Files:**
- Modify (append hallazgos): `docs/superpowers/specs/2026-07-06-rbac-soportes-por-paso-design.md` (sección "Resultado auditoría §8").

- [ ] **Step 1: Ejecutar el audit existente para el baseline**

Run: `php bin/cake permissions_audit`
Expected: exit 0 (o documentar los desajustes que reporte).

- [ ] **Step 2: Consultar roles con CRUD-documental pero sin steps operables**

Ejecutar contra la BD (ajustar el binario mysql al entorno). Para cada pareja (módulo CRUD, pipeline):

```sql
-- invoices / invoices
SELECT p.role_id, p.module, p.can_create, p.can_edit, p.can_delete
FROM permissions p
WHERE p.module = 'invoices'
  AND (p.can_create OR p.can_edit OR p.can_delete)
  AND p.role_id NOT IN (
    SELECT pp.role_id FROM pipeline_permissions pp
    WHERE pp.pipeline = 'invoices' AND pp.can_operate = 1
  );
```

Repetir cambiando `module`/`pipeline` por: `petty_cash`/`petty_cash`, `payment_schedulings`/`payment_schedulings`, `employee_novelties`/`novelties`, `novelty_liquidation_docs`/`liquidation_docs`.

> Nota: el módulo CRUD de liquidación es `novelty_liquidation_docs` y el de novedades individuales es `employee_novelties` (ver `PipelineStepConstants::MODULE_BY_PIPELINE`).

- [ ] **Step 3: Documentar el resultado**

En el spec, bajo una nueva sección "Resultado auditoría §8", listar por módulo los `role_id` encontrados (o "ninguno"). Si aparece algún rol operativo real que dependa de la vía CRUD, **detener** y consultar con negocio antes de continuar.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-07-06-rbac-soportes-por-paso-design.md
git commit -m "docs: auditoria permissions vs pipeline_permissions para gate de soportes"
```

---

### Task 2: Exponer `InvoiceActionPolicy::canOperateStep`

`InvoiceActionPolicy` solo tiene `_canOperate` privado. Se expone un método público uniforme con las otras policies, consumido por el `_documentGate` de Invoices (Task 3).

**Files:**
- Modify: `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php`
- Test: `tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceActionPolicyTest.php` (crear si no existe)

**Interfaces:**
- Produces: `InvoiceActionPolicy::canOperateStep(int $roleId, string $step): bool`

- [ ] **Step 1: Write the failing test**

Crear `tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceActionPolicyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;
use App\ValueObject\UserContext;
use Cake\TestSuite\TestCase;

class InvoiceActionPolicyTest extends TestCase
{
    public function testCanOperateStepDelegatesToFacadeWithInvoicesPipeline(): void
    {
        $facade = $this->createMock(AuthorizationFacade::class);
        $facade->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn(UserContext $c) => $c->roleId === 7),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_CONTABILIDAD,
            )
            ->willReturn(true);

        $policy = new InvoiceActionPolicy($facade);

        $this->assertTrue($policy->canOperateStep(7, InvoiceConstants::STATUS_CONTABILIDAD));
    }
}
```

> Verificá el nombre de la propiedad pública de `UserContext` (se usa `$c->roleId`). Si el VO expone el rol con otro nombre, ajustá el `callback`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceActionPolicyTest.php`
Expected: FAIL con "Call to undefined method ...::canOperateStep()".

- [ ] **Step 3: Add the public method**

En `src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php`, añadir el método público justo antes de `_canOperate` (línea 106) y hacer que `_canOperate` delegue en él para no duplicar:

```php
    /**
     * @param int $roleId Role ID.
     * @param string $step Pipeline step.
     * @return bool
     */
    public function canOperateStep(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $step,
        );
    }

    /**
     * @param int $roleId Role ID.
     * @param string $step Pipeline step.
     * @return bool
     */
    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->canOperateStep($roleId, $step);
    }
```

(Reemplaza el cuerpo actual de `_canOperate` líneas 111-118.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceActionPolicyTest.php`
Expected: PASS.

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Service/Pipeline/Invoice/Policy/InvoiceActionPolicy.php tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceActionPolicyTest.php
git commit -m "feat: expone InvoiceActionPolicy::canOperateStep para el gate de soportes"
```

---

### Task 3: Invoices — gate por paso en `uploadDocument`/`deleteDocument` + flag terminal-only

**Files:**
- Modify: `src/Controller/InvoicesController.php` (atributos + `_documentGate`/`_documentGateError`; flag JSON línea ~700; construcción de `InvoiceEditPermissions` línea ~446)
- Test: `tests/TestCase/Controller/InvoicesDocumentGateTest.php` (crear)

**Interfaces:**
- Consumes: `InvoiceActionPolicy::canOperateStep(int, string): bool` (Task 2); `$this->actionPolicy` ya inyectada.

- [ ] **Step 1: Write the failing tests**

Crear `tests/TestCase/Controller/InvoicesDocumentGateTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class InvoicesDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_INVOICES,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        // Rol con el step operable pero SIN permisos CRUD del módulo.
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        // Sin archivo: el gate pasa y falla después por archivo faltante
        // (Invoices responde 200 + success:false, NO 403). Prueba que el gate CRUD ya no aplica.
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_TESORERIA); // opera OTRO step
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadConflictWhenInvoiceInFinalState(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'Invoices', 'action' => 'uploadDocument', $invoice->id]);

        $this->assertResponseCode(409);
    }

    public function testDeleteForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, InvoiceConstants::STATUS_TESORERIA); // opera OTRO step
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $invoice = InvoiceFactory::new(['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        // documentId inexistente: el gate (403) corre ANTES de buscar el documento.
        $this->post(['controller' => 'Invoices', 'action' => 'deleteDocument', $invoice->id, 999999]);

        $this->assertResponseCode(403);
    }
}
```

> **Cobertura de borrado (M1) en Tasks 4-7:** añadí en cada archivo de test un
> `testDeleteForbiddenForRoleNotOperatingStep` análogo al de arriba — mismo rol que opera
> otro step, POST a la acción de borrado del módulo (`deleteDocument`; en NoveltyDocuments es
> `delete`) con un `documentId` inexistente (`999999`), esperando **403**. Es válido porque el
> `_documentGate` corre antes de buscar el documento. En NoveltyLiquidationDocs, sembrá el
> permiso en `PIPELINE_LIQUIDATION_DOCS` para el caso positivo y en el step equivocado para el 403.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/InvoicesDocumentGateTest.php`
Expected: FAIL — hoy `uploadDocument` usa `#[Permission(action:'add')]`, así que un rol sin `can_create` recibe 403 en los tres casos (el de 200 y el de 409 fallan).

- [ ] **Step 3: Cambiar atributos y añadir el gate**

En `src/Controller/InvoicesController.php`:

(a) Cambiar el atributo de `uploadDocument` (línea 660) de `#[Permission(action: 'add')]` a:

```php
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
```

(b) Cambiar el atributo de `deleteDocument` (línea 729) de `#[Permission(action: 'delete')]` a:

```php
    #[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_INVOICES)]
```

(c) Insertar al principio del cuerpo de `uploadDocument` (justo después de `$invoice = $this->Invoices->get($invoiceId);`, línea 664):

```php
        $gate = $this->_documentGate($invoice, 'subir');
        if ($gate !== null) {
            return $gate;
        }
```

(d) Insertar al principio del cuerpo de `deleteDocument` (justo después de `$invoice = $this->Invoices->get($invoiceId);`, línea 733):

```php
        $gate = $this->_documentGate($invoice, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }
```

(e) Cambiar el flag JSON en `uploadDocument` (línea 700) de:

```php
            $canDelete = $this->_checkPermission('invoices', 'delete')
                && $this->documentService->canDeleteDocument($result, $invoice->pipeline_status);
```

a:

```php
            $canDelete = !$invoice->isInFinalState()
                && $this->documentService->canDeleteDocument($result, $invoice->pipeline_status);
```

(f) Añadir los dos métodos privados (por ejemplo, tras `deleteDocument`, ~línea 787):

```php
    /**
     * Gate compartido de soportes: bloquea si la factura está en estado terminal
     * (409) o si el rol no puede operar el paso actual del pipeline (403).
     */
    private function _documentGate(Invoice $invoice, string $blockedActionLabel): ?Response
    {
        if ($invoice->isInFinalState()) {
            return $this->_documentGateError(
                $invoice,
                sprintf('No se puede %s un soporte de una factura en estado final.', $blockedActionLabel),
                409,
            );
        }

        $roleId = (int)$this->_getCurrentUser()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$invoice->pipeline_status)) {
            return $this->_documentGateError(
                $invoice,
                'No tiene permisos para gestionar soportes en este paso.',
                403,
            );
        }

        return null;
    }

    private function _documentGateError(Invoice $invoice, string $message, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->_redirectForInvoice($invoice, 'edit', $invoice->id);
    }
```

Añadir `use Cake\Http\Response;` si no está en los imports.

(g) Alinear el flag de UI del ViewModel: en la construcción de `InvoiceEditPermissions` (línea ~446) cambiar:

```php
            canDeleteDocuments: $this->_checkPermission('invoices', 'delete'),
```

a:

```php
            canDeleteDocuments: !$invoice->isInFinalState(),
```

> Verificá el nombre de la variable de la factura en ese método (probablemente `$invoice`). El desempaque en `InvoiceEditViewModel.php:109` no cambia.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/InvoicesDocumentGateTest.php`
Expected: PASS (400, 403, 409).

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Controller/InvoicesController.php tests/TestCase/Controller/InvoicesDocumentGateTest.php
git commit -m "feat: gate de soportes por paso en Invoices (upload/delete)"
```

---

### Task 4: PettyCashRecords — gate por paso en `uploadDocument`/`deleteDocument`

El flag de UI ya es terminal-only (`!$record->isPagada()` en plantilla y JSON): **no se toca la vista**.

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`
- Test: `tests/TestCase/Controller/PettyCashRecordsDocumentGateTest.php` (crear)

- [ ] **Step 1: Write the failing tests**

Crear `tests/TestCase/Controller/PettyCashRecordsDocumentGateTest.php` (misma estructura que Task 3; diferencias: pipeline `PIPELINE_PETTY_CASH`, entidad usa columna `status`, factory `PettyCashRecordFactory`, constantes `PettyCashConstants`):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\PettyCashConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\PettyCashRecordFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class PettyCashRecordsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_PETTY_CASH,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(200); // sin archivo → success:false + mensaje (este módulo responde 200 en JSON de error de archivo)
        $this->assertResponseContains('archivo');
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadConflictWhenRecordPagada(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PettyCashConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PettyCashRecordFactory::new(['status' => PettyCashConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PettyCashRecords', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(409);
    }
}
```

> Nota sobre el 200 del primer test: el `uploadDocument` de PettyCash responde el error de archivo faltante con `_jsonResponse([...])` sin status (default 200). Lo relevante es que **no** sea 403 y contenga "archivo" (prueba que el gate pasó). Si preferís homogeneidad, en el Step 3 podés añadir el status 400 a ese `_jsonResponse`; si lo hacés, cambiá el assert a 400.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashRecordsDocumentGateTest.php`
Expected: FAIL (hoy 403 por `#[Permission(action:'add')]`).

- [ ] **Step 3: Cambiar atributos y añadir el gate**

En `src/Controller/PettyCashRecordsController.php`:

(a) `uploadDocument` (línea 640): cambiar `#[Permission(action: 'add')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH)]`.

(b) `deleteDocument` (línea 711): cambiar `#[Permission(action: 'delete')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PETTY_CASH)]`.

(c) En `uploadDocument`, tras `$record = $this->PettyCashRecords->get($id);` insertar:

```php
        $gate = $this->_documentGate($record, 'subir');
        if ($gate !== null) {
            return $gate;
        }
```

(d) En `deleteDocument`, reemplazar el bloque actual de bloqueo por-pagada (líneas 717-724) por el gate. Es decir, tras `$record = $this->PettyCashRecords->get($recordId);` quitar el `if ($record->isPagada()) {...}` existente e insertar:

```php
        $gate = $this->_documentGate($record, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }
```

(e) Añadir los métodos privados (tras `deleteDocument`):

```php
    /**
     * Gate compartido de soportes: 409 si el registro está pagado, 403 si el rol
     * no puede operar el paso actual del pipeline de caja menor.
     */
    private function _documentGate(PettyCashRecord $record, string $blockedActionLabel): ?Response
    {
        if ($record->isPagada()) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de un registro pagado.', $blockedActionLabel),
                (int)$record->id,
                409,
            );
        }

        $roleId = (int)$this->_getCurrentUser()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$record->status)) {
            return $this->_documentGateError(
                'No tiene permisos para gestionar soportes en este paso.',
                (int)$record->id,
                403,
            );
        }

        return null;
    }

    private function _documentGateError(string $message, int $recordId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->redirect(['action' => 'edit', $recordId]);
    }
```

Añadir `use Cake\Http\Response;` a los imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PettyCashRecordsDocumentGateTest.php`
Expected: PASS.

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Controller/PettyCashRecordsController.php tests/TestCase/Controller/PettyCashRecordsDocumentGateTest.php
git commit -m "feat: gate de soportes por paso en PettyCashRecords (upload/delete)"
```

---

### Task 5: PaymentSchedulings — gate por paso en `uploadDocument`/`deleteDocument`

Flag de UI ya terminal-only (`!$viewModel->isPagada`): **no se toca la vista**.

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`
- Test: `tests/TestCase/Controller/PaymentSchedulingsDocumentGateTest.php` (crear)

- [ ] **Step 1: Write the failing tests**

Crear el test (estructura idéntica a Task 4; diferencias: pipeline `PIPELINE_PAYMENT_SCHEDULINGS`, columna `pipeline_status`, factory `PaymentSchedulingFactory`, constantes `PaymentSchedulingConstants`, estado operable `STATUS_TESORERIA`):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\PaymentSchedulingFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class PaymentSchedulingsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PaymentSchedulingFactory::new(['pipeline_status' => PaymentSchedulingConstants::STATUS_TESORERIA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PaymentSchedulings', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadConflictWhenPagada(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PaymentSchedulingConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PaymentSchedulingFactory::new(['pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PaymentSchedulings', 'action' => 'uploadDocument', $record->id]);

        $this->assertResponseCode(409);
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PaymentSchedulingConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $record = PaymentSchedulingFactory::new(['pipeline_status' => PaymentSchedulingConstants::STATUS_TESORERIA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'PaymentSchedulings', 'action' => 'uploadDocument', $record->id]);

        // El gate pasa (rol opera el step) y falla después por archivo faltante
        // (200 + success:false), NUNCA 403. Prueba que el gate CRUD ya no aplica.
        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PaymentSchedulingsDocumentGateTest.php`
Expected: FAIL (hoy 403 por CRUD).

- [ ] **Step 3: Cambiar atributos y añadir el gate**

En `src/Controller/PaymentSchedulingsController.php`:

(a) `uploadDocument` (línea 470): `#[Permission(action: 'add')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]`.

(b) `deleteDocument` (línea 528): `#[Permission(action: 'delete')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS)]`.

(c) En `uploadDocument`, tras `$record = $this->PaymentSchedulings->get($id);` insertar:

```php
        $gate = $this->_documentGate($record, 'subir');
        if ($gate !== null) {
            return $gate;
        }
```

(d) En `deleteDocument`, reemplazar el bloque de bloqueo por-pagada existente (el `if ($record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA) {...}`) por, tras `$record = $this->PaymentSchedulings->get($id);`:

```php
        $gate = $this->_documentGate($record, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }
```

(e) Añadir los métodos privados (tras `deleteDocument`):

```php
    /**
     * Gate compartido de soportes: 409 si la programación está pagada, 403 si el
     * rol no puede operar el paso actual del pipeline de programación de pagos.
     */
    private function _documentGate(PaymentScheduling $record, string $blockedActionLabel): ?Response
    {
        if ($record->isPagada()) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de una programación pagada.', $blockedActionLabel),
                (int)$record->id,
                409,
            );
        }

        $roleId = (int)$this->_getCurrentUser()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$record->pipeline_status)) {
            return $this->_documentGateError(
                'No tiene permisos para gestionar soportes en este paso.',
                (int)$record->id,
                403,
            );
        }

        return null;
    }

    private function _documentGateError(string $message, int $recordId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->redirect(['action' => 'edit', $recordId]);
    }
```

Añadir `use Cake\Http\Response;` a los imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/PaymentSchedulingsDocumentGateTest.php`
Expected: PASS.

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Controller/PaymentSchedulingsController.php tests/TestCase/Controller/PaymentSchedulingsDocumentGateTest.php
git commit -m "feat: gate de soportes por paso en PaymentSchedulings (upload/delete)"
```

---

### Task 6: NoveltyDocuments — gate por paso en `upload`/`delete` (pipeline `novelties`)

Este controller **no** tiene `actionPolicy` ni `_getCurrentUser`. El flag de UI ya es terminal-only (`showUploadSection` en `EmployeeNoveltyEditViewModel`): **no se toca la vista**.

**Files:**
- Modify: `src/Controller/NoveltyDocumentsController.php`
- Test: `tests/TestCase/Controller/NoveltyDocumentsDocumentGateTest.php` (crear)

- [ ] **Step 1: Write the failing tests**

Crear el test (pipeline `PIPELINE_NOVELTIES`, entidad `EmployeeNovelty` columna `pipeline_status`, factory `EmployeeNoveltyFactory`, constantes `NoveltyConstants`, rutas `/novelty-documents/upload`):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\EmployeeNoveltyFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class NoveltyDocumentsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => PipelineStepConstants::PIPELINE_NOVELTIES,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, NoveltyConstants::STATUS_TESORERIA);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $novelty = EmployeeNoveltyFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadAllowedForRoleOperatingStepWithoutCrud(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $novelty = EmployeeNoveltyFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyDocuments', 'action' => 'upload', $novelty->id]);

        // El gate pasa (rol opera el step) y falla después por archivo faltante
        // (200 + success:false), NUNCA 403. Prueba que el gate CRUD ya no aplica.
        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }
}
```

> Verificá que `NoveltyConstants::STATUS_CONTABILIDAD` y `STATUS_TESORERIA` sean pasos válidos del pipeline `novelties`. Si el pipeline de novedades individuales salta a estados distintos, usá dos estados no terminales cualesquiera del pipeline.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/NoveltyDocumentsDocumentGateTest.php`
Expected: FAIL (hoy `#[Permission(action:'edit')]` → 403 sin `can_edit`).

- [ ] **Step 3: Inyectar la policy y añadir el gate**

En `src/Controller/NoveltyDocumentsController.php`:

(a) Ajustar imports: **eliminar** `use App\Attribute\Permission;` (queda huérfano — ninguna acción del controller usa ya `#[Permission]`, así que `cs-check` lo marcaría) y **añadir**:

```php
use App\Attribute\PipelineAction;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Service\Pipeline\Novelty\Policy\NoveltyActionPolicy;
use Cake\Http\Response;
```

(b) **Reemplazar** el bloque existente de propiedad + `initialize()` (líneas 17-23) por el siguiente (agrega la propiedad `$actionPolicy` y su resolución; conserva `$documentService`). No re-declarar `$documentService` en otra parte:

```php
    private NoveltyDocumentService $documentService;

    private NoveltyActionPolicy $actionPolicy;

    public function initialize(): void
    {
        parent::initialize();
        $container = $this->getContainer();
        $this->documentService = $container->get(NoveltyDocumentService::class);
        $this->actionPolicy = $container->get(NoveltyActionPolicy::class);
    }
```

(c) `upload` (línea 25): `#[Permission(action: 'edit')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]`.

(d) `delete` (línea 77): `#[Permission(action: 'delete')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_NOVELTIES)]`.

(e) En `upload`, tras `$novelty = $noveltiesTable->get($noveltyId);` insertar:

```php
        $gate = $this->_documentGate($novelty, 'subir');
        if ($gate !== null) {
            return $gate;
        }
```

(f) En `delete`, tras `$novelty = $noveltiesTable->get($noveltyId);` insertar:

```php
        $gate = $this->_documentGate($novelty, 'eliminar');
        if ($gate !== null) {
            return $gate;
        }
```

(g) Añadir los métodos privados (antes de `_noveltyDocumentLabels`):

```php
    /**
     * Gate compartido de soportes: 409 si la novedad está pagada o rechazada, 403
     * si el rol no puede operar el paso actual del pipeline de novedades.
     */
    private function _documentGate(EmployeeNovelty $novelty, string $blockedActionLabel): ?Response
    {
        if ($novelty->isPaid() || $novelty->isRejected()) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de una novedad cerrada.', $blockedActionLabel),
                (int)$novelty->id,
                409,
            );
        }

        $roleId = (int)$this->Authentication->getIdentity()->getOriginalData()->role_id;
        if (!$this->actionPolicy->canOperateStep($roleId, (string)$novelty->pipeline_status)) {
            return $this->_documentGateError(
                'No tiene permisos para gestionar soportes en este paso.',
                (int)$novelty->id,
                403,
            );
        }

        return null;
    }

    private function _documentGateError(string $message, int $noveltyId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->redirect(['controller' => 'EmployeeNovelties', 'action' => 'edit', $noveltyId]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/NoveltyDocumentsDocumentGateTest.php`
Expected: PASS.

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Controller/NoveltyDocumentsController.php tests/TestCase/Controller/NoveltyDocumentsDocumentGateTest.php
git commit -m "feat: gate de soportes por paso en NoveltyDocuments (upload/delete)"
```

---

### Task 7: NoveltyLiquidationDocs — gate por paso en las 4 acciones (pipeline `liquidation_docs`)

Autoriza contra `liquidation_docs` delegando en `NoveltyPipelineService::denialReasonForAdvanceGroup`. Flag de UI ya terminal-only (`showUploadSection`): **no se toca la vista**. Se preserva el check `allowedStatuses` de `updateLiquidationDocument`.

**Files:**
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`
- Test: `tests/TestCase/Controller/NoveltyLiquidationDocsDocumentGateTest.php` (crear)

- [ ] **Step 1: Write the failing tests**

Crear el test. Casos clave: (a) rol que opera step en `liquidation_docs` pasa el gate; (b) rol que NO opera → 403; (c) terminal (pagada) → 409; (d) **anti-regresión del bloqueante**: un rol con step en `novelties` pero no en `liquidation_docs` es denegado (prueba que autoriza contra el pipeline correcto):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\NoveltyConstants;
use App\Constants\PipelineStepConstants;
use App\Test\Factory\NoveltyLiquidationDocFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class NoveltyLiquidationDocsDocumentGateTest extends TestCase
{
    use IntegrationTestTrait;

    private function seedPipelinePermission(int $roleId, string $pipeline, string $step): void
    {
        $t = TableRegistry::getTableLocator()->get('PipelinePermissions');
        $t->saveOrFail($t->newEntity([
            'role_id' => $roleId,
            'pipeline' => $pipeline,
            'step' => $step,
            'can_operate' => true,
        ]));
    }

    public function testUploadForbiddenForRoleNotOperatingStep(): void
    {
        $role = RoleFactory::new()->save();
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadForbiddenWhenRoleOperatesNoveltiesButNotLiquidation(): void
    {
        // Bloqueante: sembrar en el pipeline EQUIVOCADO (novelties) NO debe autorizar.
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_NOVELTIES, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(403);
    }

    public function testUploadAllowedForRoleOperatingLiquidationStep(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        // El gate pasa (rol opera el step) y falla después por archivo faltante
        // (200 + success:false), NUNCA 403. Prueba que el gate CRUD ya no aplica.
        $this->assertResponseCode(200);
        $this->assertResponseContains('archivo');
    }

    public function testUploadConflictWhenTerminal(): void
    {
        $role = RoleFactory::new()->save();
        $this->seedPipelinePermission($role->id, PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS, NoveltyConstants::STATUS_CONTABILIDAD);
        $user = UserFactory::new(['role_id' => $role->id])->save();
        $doc = NoveltyLiquidationDocFactory::new(['pipeline_status' => NoveltyConstants::STATUS_PAGADA])->save();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->session(['Auth' => $user]);
        $this->enableCsrfToken();
        $this->post(['controller' => 'NoveltyLiquidationDocs', 'action' => 'uploadDocument', $doc->id]);

        $this->assertResponseCode(409);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/TestCase/Controller/NoveltyLiquidationDocsDocumentGateTest.php`
Expected: FAIL (hoy `#[Permission(action:'add')]`; el caso "allowed liquidation step" y el "409" fallan).

- [ ] **Step 3: Cambiar atributos y añadir el gate delegando en el service**

En `src/Controller/NoveltyLiquidationDocsController.php`:

(a) Añadir imports:

```php
use App\Attribute\PipelineAction;
use App\Constants\Domain\Pipeline\DenialReason;
use App\Constants\PipelineStepConstants;
```

(`Cake\Http\Response` ya está importado.)

(b) Cambiar los 4 atributos:
- `uploadDocument` (línea 359): `#[Permission(action: 'add')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS)]`.
- `uploadLiquidationDocument` (línea 420): `#[Permission(action: 'add')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS)]`.
- `updateLiquidationDocument` (línea 466): `#[Permission(action: 'edit')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS)]`.
- `deleteDocument` (línea 531): `#[Permission(action: 'delete')]` → `#[PipelineAction(pipeline: PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS)]`.

(c) Insertar el gate al inicio de cada una de las 4 acciones, tras `$doc = $this->NoveltyLiquidationDocs->get($id);`:

```php
        $gate = $this->_documentGate($doc, 'subir'); // 'eliminar' en deleteDocument; 'actualizar' en updateLiquidationDocument
        if ($gate !== null) {
            return $gate;
        }
```

> En `updateLiquidationDocument`, el gate va **antes** del check `allowedStatuses` existente (que se conserva).

(d) Añadir los métodos privados (antes de `_liquidationDocumentLabels`):

```php
    /**
     * Gate compartido de soportes para documentos de liquidación. Delega en el
     * coordinador, que autoriza contra el pipeline `liquidation_docs` (NO
     * `novelties`): terminal → 409, sin permiso del paso → 403.
     */
    private function _documentGate(NoveltyLiquidationDoc $doc, string $blockedActionLabel): ?Response
    {
        $roleId = (int)$this->Authentication->getIdentity()->getOriginalData()->role_id;
        $denial = $this->pipelineService->denialReasonForAdvanceGroup($doc, $roleId);
        if ($denial === null) {
            return null;
        }

        if ($denial === DenialReason::TERMINAL_STATE) {
            return $this->_documentGateError(
                sprintf('No se puede %s un soporte de un documento de liquidación cerrado.', $blockedActionLabel),
                (int)$doc->id,
                409,
            );
        }

        return $this->_documentGateError(
            'No tiene permisos para gestionar soportes en este paso.',
            (int)$doc->id,
            403,
        );
    }

    private function _documentGateError(string $message, int $docId, int $statusCode): Response
    {
        if ($this->_isJsonRequest()) {
            return $this->_jsonResponse(['success' => false, 'error' => $message], $statusCode);
        }

        $this->Flash->error($message);

        return $this->redirect(['action' => 'edit', $docId]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/Controller/NoveltyLiquidationDocsDocumentGateTest.php`
Expected: PASS (403, 403-pipeline-equivocado, no-403, 409).

- [ ] **Step 5: cs-fix + Commit**

```bash
composer cs-fix
git add src/Controller/NoveltyLiquidationDocsController.php tests/TestCase/Controller/NoveltyLiquidationDocsDocumentGateTest.php
git commit -m "feat: gate de soportes por paso en NoveltyLiquidationDocs (4 acciones, liquidation_docs)"
```

---

### Task 8: Verificación integral

**Files:** ninguno nuevo (verificación).

- [ ] **Step 1: cs-check**

Run: `composer cs-check`
Expected: sin errores.

- [ ] **Step 2: Suite completa**

Run: `vendor/bin/phpunit`
Expected: verde (baseline ~843 + los nuevos tests). Si aparecen fallos de contaminación entre suites, re-correr limpio antes de concluir regresión.

- [ ] **Step 3: Auditoría de permisos**

Run: `php bin/cake permissions_audit`
Expected: exit 0 (el cambio no toca `can_view`; el invariante "operar implica ver" se mantiene).

- [ ] **Step 4: Verificación manual mínima (opcional pero recomendada)**

Con un rol que opera un paso intermedio pero sin CRUD del módulo: subir y borrar un soporte en Invoices y en un documento de liquidación; confirmar que funciona y que en estado terminal el botón no aparece.

- [ ] **Step 5: Commit de cierre (si hubo ajustes de cs)**

```bash
git add -A
git commit -m "chore: cierre gate de soportes por paso (cs + verificacion)"
```

---

## Self-Review (autor)

**Cobertura del spec:**
- §3 (12 acciones / 5 controllers): Tasks 3-7 cubren Invoices (2), PettyCash (2), PaymentSchedulings (2), NoveltyDocuments (2), NoveltyLiquidationDocs (4) = 12. ✓
- §4 (`_documentGate` réplica Refunds): Tasks 3-7. ✓
- §5 (pipeline correcto; liquidación → `liquidation_docs`): Task 7 (delega en `denialReasonForAdvanceGroup`); Task 2 (Invoice policy). ✓
- §6 (botón terminal-only por ViewModel): Task 3 (único outlier real, Invoices línea 446 + JSON 700); el reviewer confirmó que los otros 5 ya son terminal-only. ✓
- §7 (409/403, formatos JSON/redirect): `_documentGate`/`_documentGateError` en cada task. ✓
- §8 (auditoría previa): Task 1. ✓
- §9 (tests: fix/403/409/regresión/liquidation): Tasks 3-7 — upload (fix/403/409) + borrado (403) por módulo; anti-regresión de pipeline `liquidation_docs` vs `novelties` en Task 7. ✓
- §10-11 (permissions_audit, cs): Task 8. ✓

**Placeholder scan:** sin TBD/TODO; cada paso de código trae el código. Las notas "verificá X" apuntan a nombres a confirmar en runtime (propiedad `UserContext->roleId`, estados válidos de novelties), no a lógica faltante. ✓

**Consistencia de tipos:** `canOperateStep(int,string):bool` producido en Task 2, consumido en Task 3. `_documentGate($record,string):?Response` uniforme. `_documentGateError` alineado al orden de Refunds `(string $message, int $id, int $statusCode):Response` en PettyCash/PaymentSchedulings/NoveltyDocuments/NoveltyLiquidationDocs; Invoices es la única excepción `(Invoice $invoice, string $message, int $statusCode)` porque necesita el objeto para `_redirectForInvoice` (anticipos→Advances). ✓
