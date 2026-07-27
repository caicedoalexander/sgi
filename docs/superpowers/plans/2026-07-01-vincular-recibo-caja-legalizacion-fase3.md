# Vincular "Recibo de Caja" a la legalización — Fase 3 · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Acceso directo para crear una factura ya vinculada a la legalización de un anticipo (nace con `advance_id`, en `aprobacion`, con el pipeline reducido de F2 desde el inicio).

**Architecture:** Un botón "Nueva" en la vista de legalización (solo en `Validación`) lleva a `Invoices::add?advance_id=X`. La validación del contexto y el registro de auditoría viven en `AdvanceLegalizationService` (2 métodos nuevos); `InvoicesController::add()` los orquesta (GET pre-rellena, POST re-valida + persiste + registra + redirige a la legalización). `templates/Invoices/add.php` (legacy) gana un modo vinculado.

**Tech Stack:** PHP 8.4, CakePHP 5.3, PHPUnit, phpcs.

**Spec:** `docs/superpowers/specs/2026-07-01-vincular-recibo-caja-legalizacion-fase3-design.md`

## Global Constraints

- **Re-validación en el boundary autoritativo:** el `advance_id` (mass-assignable) se re-valida en el POST, no solo en el GET/listado — anticipo con legalización en `Validación` + `document_type ∈ InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES`.
- **Constante fuente única:** los tipos permitidos derivan de `ADVANCE_LINKABLE_DOCTYPES` (`[Legalización, Recibo de Caja]`); no literales.
- **Paridad de auditoría:** F3 registra el vínculo en el historial de la legalización (`invoices_linked`), delegado a `AdvanceLegalizationService` (el controller no conoce el `AdvanceLegalizationHistoryService`).
- **Estado inicial:** la factura nace en `STATUS_APROBACION` (como toda factura); el `advance_id` la hace `usesLegalizationView()` desde el inicio (F2).
- **OC = `operation_center_id`** (Centro de Operación), NO `purchase_order`.
- **RBAC:** solo `invoices.can_create` (`#[Permission(action: 'add')]`); el botón se gatea además por `can_create` en el template.
- **Deuda legacy:** `add.php` no se moderniza fuera del soporte de `advance_id`.
- **Slugs persistidos inmutables:** no tocar `'Legalización'`/`'Recibo de Caja'` ni estados.
- **Estilo:** `composer cs-fix` antes de cada commit, solo stageando los archivos de la tarea (revertir deuda preexistente que toque). `config/bootstrap.php` no se toca.
- **Commits:** conventional, SIN atribución (no `Co-Authored-By`).
- **Tests de integración:** la DB de test es una RDS remota; correr con `vendor/bin/phpunit` (no `composer test`, que corta a 300s) y las credenciales de `config/.env`. Si el entorno no tiene DB (`Access denied`), la verificación final la corre el controller.

---

### Task 1: Métodos de servicio — validación del contexto + registro de auditoría

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php` (2 métodos nuevos)
- Modify: `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php`

**Interfaces:**
- Consumes: `AdvanceConstants::STATUS_VALIDACION`; `AdvanceLegalizationHistoryService::recordFieldChange(int, string, ?string, ?string, int)`.
- Produces: `AdvanceLegalizationService::legalizationInValidacion(int $advanceInvoiceId): ?AdvanceLegalization`; `AdvanceLegalizationService::recordDirectLink(AdvanceLegalization $leg, Invoice $invoice, int $userId): void`.

- [ ] **Step 1: Escribir los tests (fallan)**

Modify `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php`. Añadir:

```php
    public function testLegalizationInValidacionReturnsLegWhenInValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        $found = $this->buildService()->legalizationInValidacion((int)$anticipo->id);

        $this->assertNotNull($found);
        $this->assertSame($leg->id, $found->id);
    }

    public function testLegalizationInValidacionNullWhenNotInValidacion(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->assertNull($this->buildService()->legalizationInValidacion((int)$anticipo->id));
    }

    public function testLegalizationInValidacionNullWhenNoLegalization(): void
    {
        $this->assertNull($this->buildService()->legalizationInValidacion(999999));
    }

    public function testRecordDirectLinkWritesLinkHistory(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $rc = InvoiceFactory::new()->reciboDeCaja()->save();
        $user = UserFactory::new()->save();

        $this->buildService()->recordDirectLink($leg, $rc, (int)$user->id);

        $count = $this->fetchTable('AdvanceLegalizationHistories')->find()
            ->where(['legalization_id' => $leg->id, 'field_changed' => 'invoices_linked'])
            ->count();
        $this->assertSame(1, $count);
    }
```

- [ ] **Step 2: Correr — deben fallar**

Run: `vendor/bin/phpunit --filter "testLegalizationInValidacion|testRecordDirectLink"`
Expected: FAIL — `Call to undefined method App\Service\AdvanceLegalizationService::legalizationInValidacion()`.

- [ ] **Step 3: Añadir los métodos**

Modify `src/Service/AdvanceLegalizationService.php`. Añadir el `use App\Model\Entity\AdvanceLegalization;` si no está (ya está: la clase lo usa en firmas), y los métodos (junto a `hasLegalization`):

```php
    /**
     * Devuelve la legalización del anticipo si está en Validación (estado en que
     * se puede vincular/crear-vinculado), o null. Usado por la creación directa
     * (Fase 3) para validar el contexto.
     */
    public function legalizationInValidacion(int $advanceInvoiceId): ?AdvanceLegalization
    {
        $leg = TableRegistry::getTableLocator()->get('AdvanceLegalizations')
            ->find()
            ->where(['advance_invoice_id' => $advanceInvoiceId])
            ->first();

        return ($leg !== null && $leg->status === AdvanceConstants::STATUS_VALIDACION) ? $leg : null;
    }

    /**
     * Registra en el historial de la legalización el vínculo de una factura creada
     * directamente vinculada (Fase 3), en paridad con linkInvoices() (invoices_linked).
     */
    public function recordDirectLink(AdvanceLegalization $leg, Invoice $invoice, int $userId): void
    {
        $this->historyService->recordFieldChange(
            $leg->id,
            'invoices_linked',
            null,
            (string)($invoice->invoice_number ?? $invoice->id),
            $userId,
        );
    }
```

- [ ] **Step 4: Correr — deben pasar**

Run: `vendor/bin/phpunit --filter "testLegalizationInValidacion|testRecordDirectLink"`
Expected: PASS.

- [ ] **Step 5: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Service/AdvanceLegalizationService.php \
        tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php
git commit -m "feat: validación de contexto y registro de vínculo directo en AdvanceLegalizationService"
```

---

### Task 2: `InvoicesController::add()` — soporte de `advance_id`

**Files:**
- Modify: `src/Controller/InvoicesController.php:237-272`
- Modify/Create: `tests/TestCase/Controller/InvoicesControllerTest.php`

**Interfaces:**
- Consumes: `AdvanceLegalizationService::legalizationInValidacion(int): ?AdvanceLegalization`, `recordDirectLink(...)` (Task 1); `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES`.
- Produces: la vista `add` recibe `$advance` (el anticipo `Invoice` o `null`) además de `$invoice` y los dropdowns.

- [ ] **Step 1: Escribir el smoke test (falla o pasa según ruta)**

Create `tests/TestCase/Controller/InvoicesControllerTest.php` (patrón del proyecto — smoke de auth, sin sesión autenticada):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class InvoicesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAddRequiresAuthentication(): void
    {
        $this->get('/invoices/add');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testAddWithAdvanceIdRequiresAuthentication(): void
    {
        $this->get('/invoices/add?advance_id=1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
```

> El comportamiento real (validación del contexto + registro) está cubierto por los tests de servicio de Task 1; el proyecto no testea POSTs de controller autenticados (todos los `*ControllerTest` son smoke de auth).
>
> **Deuda de test conocida (límite de harness):** el wiring del POST re-validado, el GET vinculado, el
> redirect y el caso "sin `advance_id`" (regresión) — que enumera el spec §8 — **no** quedan con test
> automatizado a nivel controller, porque el repo no tiene infraestructura de POST autenticado. El
> boundary de seguridad (validación) sí está testeado en el servicio (Task 1). Registrar esto para el
> review final como deuda sobre un boundary de seguridad.

- [ ] **Step 2: Correr — debe pasar (smoke)**

Run: `vendor/bin/phpunit --filter InvoicesControllerTest`
Expected: PASS (2 tests; el `AuthenticationMiddleware` redirige a `/login` antes del controller).

- [ ] **Step 3: Implementar el GET vinculado**

Modify `src/Controller/InvoicesController.php`, el bloque GET de `add()` (`:269-271`). Añadir `use App\Service\AdvanceLegalizationService;` arriba si no está. Reemplazar:

```php
        $advanceId = (int)$this->request->getQuery('advance_id');
        $advance = null;
        $entity = $this->Invoices->newEmptyEntity();
        if ($advanceId > 0
            && $this->getContainer()->get(AdvanceLegalizationService::class)
                ->legalizationInValidacion($advanceId) !== null
        ) {
            $advance = $this->Invoices->get($advanceId);
            $entity = $this->Invoices->patchEntity($entity, [
                'advance_id' => $advanceId,
                'operation_center_id' => $advance->operation_center_id,
            ]);
        }
        $vm = new InvoiceAddViewModel($entity);
        $this->set('invoice', $vm->invoice);
        $this->set('advance', $advance);
        $this->set($this->_getFormDropdowns());
```

- [ ] **Step 4: Implementar el POST vinculado**

Modify `src/Controller/InvoicesController.php`, dentro de `if ($this->request->is('post'))` de `add()`. Tras armar `$data` (registered_by / pipeline_status / registration_date / due_date) y ANTES de construir el `$vm`, añadir la re-validación; y en la rama de `save()` exitoso, añadir el registro + redirect. El bloque completo del POST queda:

```php
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $data['registered_by'] = (int)$this->_getCurrentUser()->id;
            $data['pipeline_status'] = InvoiceConstants::STATUS_APROBACION;
            $data['registration_date'] = date('Y-m-d');
            if (empty($data['due_date']) && !empty($data['issue_date'])) {
                $data['due_date'] = $data['issue_date'];
            }

            // F3: creación vinculada — re-validar el advance_id del cliente.
            $advanceId = (int)($data['advance_id'] ?? 0);
            $leg = null;
            if ($advanceId > 0) {
                $service = $this->getContainer()->get(AdvanceLegalizationService::class);
                $leg = $service->legalizationInValidacion($advanceId);
                $linkable = in_array(
                    $data['document_type'] ?? '',
                    InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                    true,
                );
                if ($leg === null || !$linkable) {
                    $this->Flash->error(__('No se puede crear un comprobante vinculado a esta legalización.'));
                    $vm = new InvoiceAddViewModel($this->Invoices->patchEntity($this->Invoices->newEmptyEntity(), $data));
                    $this->set('invoice', $vm->invoice);
                    $this->set('advance', $leg !== null ? $this->Invoices->get($advanceId) : null);
                    $this->set($this->_getFormDropdowns());

                    return;
                }
            }

            $vm = new InvoiceAddViewModel($this->Invoices->patchEntity($this->Invoices->newEmptyEntity(), $data));

            if ($this->Invoices->save($vm->invoice)) {
                $this->historyService->recordStatusChange(
                    (int)$vm->invoice->id,
                    '',
                    (string)$vm->invoice->pipeline_status,
                    (int)$this->_getCurrentUser()->id,
                );
                if ($leg !== null) {
                    $this->getContainer()->get(AdvanceLegalizationService::class)
                        ->recordDirectLink($leg, $vm->invoice, (int)$this->_getCurrentUser()->id);
                    $this->Flash->success(__('El comprobante ha sido creado y vinculado.'));

                    return $this->redirect(['controller' => 'Advances', 'action' => 'legalization', $advanceId]);
                }
                $this->Flash->success(__('La factura ha sido guardada.'));

                return $this->_redirectForInvoice($vm->invoice, 'index');
            }
            $this->Flash->error(__('No se pudo guardar la factura. Intente de nuevo.'));
            $this->set('invoice', $vm->invoice);
            $this->set('advance', $advanceId > 0 ? $this->Invoices->get($advanceId) : null);
            $this->set($this->_getFormDropdowns());

            return;
        }
```

- [ ] **Step 5: Correr la suite del controller + servicio**

Run: `vendor/bin/phpunit --filter "InvoicesControllerTest|AdvanceLegalizationLifecycleTest"`
Expected: PASS (smoke del controller + los métodos de servicio de Task 1). Si el entorno no tiene DB, el smoke del controller igual corre (redirect pre-DB); los de servicio quedan para la verificación final.

- [ ] **Step 6: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Controller/InvoicesController.php tests/TestCase/Controller/InvoicesControllerTest.php
git commit -m "feat: InvoicesController::add acepta advance_id (creación vinculada re-validada)"
```

---

### Task 3: `templates/Invoices/add.php` — modo vinculado

**Files:**
- Modify: `templates/Invoices/add.php` (banner + hidden + OC + selector limitado)

**Interfaces:**
- Consumes: `$advance` (anticipo `Invoice` o `null`) y `$invoice` (entity, con `advance_id`/`operation_center_id` pre-parcheados en GET) que setea `add()` (Task 2); `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES`.

- [ ] **Step 1: Banner + hidden `advance_id`**

Modify `templates/Invoices/add.php`. Al inicio del form (después de `Form->create`, antes de la primera fila de campos), añadir el banner condicional y el hidden:

```php
<?php if (!empty($advance)): ?>
    <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-link-45deg" aria-hidden="true"></i>
        <span>Comprobante para el
            <?= $this->Html->link('Anticipo #' . h($advance->invoice_number ?: $advance->id),
                ['controller' => 'Advances', 'action' => 'legalization', $advance->id]) ?>.
        </span>
    </div>
    <?= $this->Form->control('advance_id', ['type' => 'hidden']) ?>
<?php endif; ?>
```

(El `advance_id` del entity ya viene parcheado del GET, así que el hidden lo emite con su valor.)

- [ ] **Step 2: Selector de `document_type` limitado**

Modify `templates/Invoices/add.php` (`:51-56`). Reemplazar el `options => $documentTypes` para que, en modo vinculado, se limite a los 2 tipos:

```php
                <?= $this->Form->control('document_type', [
                    'class'   => 'form-select',
                    'label'   => ['text' => 'Tipo de Documento', 'class' => 'input-label'],
                    'options' => !empty($advance)
                        ? array_intersect_key($documentTypes, array_flip(InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES))
                        : $documentTypes,
                    'empty'   => '-- Seleccione --',
                ]) ?>
```

> Nota (verificado): `$documentTypes` se define **en el propio `add.php:10`** como
> `array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES)` — claves =
> valores de `document_type` (`'Legalización' => 'Legalización'`, etc.). Por eso
> `array_intersect_key($documentTypes, array_flip(ADVANCE_LINKABLE_DOCTYPES))` deja exactamente los 2
> tipos. `use App\Constants\InvoiceConstants;` **ya está** en el template (`:6`), no hay que añadirlo.

- [ ] **Step 3: OC pre-seleccionado (ya cubierto por el entity)**

El campo `operation_center_id` (`:137-142`) toma su valor del `$invoice` (entity), que en GET vinculado ya viene parcheado con el `operation_center_id` del anticipo. **No requiere cambio de template** — queda pre-seleccionado y editable. (Confirmar en el render que el `<select>` marca la opción del anticipo.)

**El JS de visibilidad (`add.php:240-262`) tampoco requiere cambio:** key-ea por el *valor* de
`document_type` (no por la lista de opciones), así que en modo vinculado oculta/muestra los mismos
campos que hoy para `Legalización`/`Recibo de Caja`. §4.5 "ajuste del JS" queda satisfecho sin
tocarlo — solo verificar que el toggle sigue funcionando con el selector limitado.

- [ ] **Step 4: Verificar render (sin fatal) + estilo**

Run: `composer cs-fix`
Verificar (manual o con un test de render si existe) que `add.php` renderiza sin error en ambos modos (con y sin `$advance`). El `$advance` no seteado → `!empty($advance)` es `false` → modo normal intacto.

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/add.php
git commit -m "feat: modo vinculado en el form de creación de facturas (banner, tipo limitado, OC)"
```

---

### Task 4: Botón "Nueva" en la vista de legalización

**Files:**
- Modify: `templates/Advances/legalization.php:167-175`

**Interfaces:**
- Consumes: `$leg->advance_invoice_id`, `$userPermissions` (si disponible en la vista), `AdvanceConstants::STATUS_VALIDACION`.

- [ ] **Step 1: Añadir el botón**

Modify `templates/Advances/legalization.php` (`:167-175`), el header de la card "Facturas vinculadas". Envolver el botón "Vincular" existente y el nuevo "Nueva" en un `d-flex gap-2`, ambos bajo el guard `status === VALIDACION`; "Nueva" además gateado por `can_create`:

```php
            <div class="d-flex align-items-center justify-content-between" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>Facturas vinculadas
                </span>
                <?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
                <div class="d-flex gap-2">
                    <?php if (!empty($userPermissions['invoices']['can_create'])): ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-file-earmark-plus" aria-hidden="true"></i>Nueva',
                        ['controller' => 'Invoices', 'action' => 'add', '?' => ['advance_id' => $leg->advance_invoice_id]],
                        ['class' => 'btn btn-default btn-sm', 'escape' => false],
                    ) ?>
                    <?php endif; ?>
                    <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#advanceLinkModal">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>Vincular
                    </button>
                </div>
                <?php endif; ?>
            </div>
```

> Verificar que `$userPermissions` llega a `legalization.php` (variable de vista global del proyecto). Si NO está disponible, quitar el `if (!empty($userPermissions...))` y dejar el botón solo bajo el guard `status` (como "Vincular"); el deny lo hace el server vía `#[Permission(action:'add')]` en `add()`.

- [ ] **Step 2: Verificar render + estilo**

Run: `composer cs-fix`
Verificar que `legalization.php` renderiza sin error y que el botón "Nueva" solo aparece en `Validación` (y con `can_create` si aplica).

- [ ] **Step 3: Commit**

```bash
git add templates/Advances/legalization.php
git commit -m "feat: botón Nueva para crear comprobante vinculado desde la legalización"
```

---

### Task 5: Verificación final

- [ ] **Step 1: Suite completa (con DB)**

Run (con credenciales de `config/.env`): `vendor/bin/phpunit`
Expected: verde (baseline 802 + los nuevos de F3), 0 failures/0 errors. El exit≠0 por notices/deprecations preexistentes + `apc.enable_cli` es esperado. Confirma que los tests de integración de Task 1 (servicio) pasan con DB real y que el smoke del controller sigue verde.

- [ ] **Step 2: Estilo global**

Run: `composer cs-check`
Expected: sin violaciones NUEVAS en los archivos tocados (deuda preexistente permanece).

---

## Self-Review

**Spec coverage:**
- §4.1 Botón "Nueva" (guard status + can_create + wrapper) → Task 4. ✓
- §4.2 GET vinculado → Task 2 Step 3. ✓
- §4.3 POST (re-validar + persistir + registrar + redirect) → Task 2 Step 4. ✓
- §4.4 Validación del contexto (`legalizationInValidacion`) → Task 1. ✓
- §4.5 add.php (hidden, banner, OC, selector limitado) → Task 3. ✓
- §4.6 Wiring VM→template (`$advance` como var extra) → Task 2 Steps 3-4 + Task 3. ✓
- §4.7 Registro de auditoría (`recordDirectLink`) → Task 1 + Task 2 Step 4. ✓
- §6 RBAC (`can_create`) → Task 2 (`#[Permission]` existente) + Task 4 (guard del botón). ✓
- §8 Testing (servicio + smoke controller) → Tasks 1-2. ✓
- Fuera de alcance (modernizar add.php, tocar F1/F2) → no se tocan. ✓

**Placeholder scan:** sin TBD/TODO; todos los pasos llevan código completo. Dos puntos delegados al implementador, ambos con instrucción de verificación: la forma exacta de `$documentTypes` (`value=>label`) en Task 3 Step 2, y la disponibilidad de `$userPermissions` en `legalization.php` en Task 4 Step 1 (con fallback explícito).

**Type consistency:** `legalizationInValidacion(int): ?AdvanceLegalization` y `recordDirectLink(AdvanceLegalization, Invoice, int): void` definidas en Task 1 y consumidas idénticas en Task 2 Steps 3-4; `$advance` (anticipo o null) producido por Task 2 y consumido por Task 3; `ADVANCE_LINKABLE_DOCTYPES` usado en Task 2 (validación) y Task 3 (selector).
