# Vincular "Recibo de Caja" a la legalización — Fase 2 · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un Recibo de Caja vinculado (`advance_id != null`) se comporte y se vea idéntico a una Legalización: se promueve a `legalizada` al cierre del anticipo y usa el pipeline visual reducido (3 pasos) + oculta secciones de tesorería/pago + banner.

**Architecture:** Un predicado único `Invoice::usesLegalizationView()` centraliza el criterio. La `ReciboCajaDocumentTypePolicy` pasa a ser `advance_id`-aware y delega en `LegalizacionDocumentTypePolicy` para el pipeline visual y las secciones ocultas (Enfoque A); `InvoicePresentation` (index) y los banners (view/edit) consumen el predicado; `LinkedInvoiceLegalizer` amplía la promoción a `IN ADVANCE_LINKABLE_DOCTYPES`.

**Tech Stack:** PHP 8.4, CakePHP 5.3, PHPUnit, phpcs.

**Spec:** `docs/superpowers/specs/2026-07-01-vincular-recibo-caja-legalizacion-fase2-design.md`

## Global Constraints

- **Predicado fuente única:** el criterio "usa vista de legalización" vive en `Invoice::usesLegalizationView()`; `InvoicePresentation` y los banners lo consumen. Dentro de la `ReciboCajaDocumentTypePolicy` se chequea `($invoice->advance_id ?? null) !== null` directamente (consistente con `blocksAdvance` de Fase 1 y testeable con `stdClass`).
- **Firma retrocompatible:** `getPipelineStatusesForView` y `filterVisibleSections` ganan un parámetro `?object $invoice = null`; los `getPipelineStatusesFor`/`getVisibleSections` del service igual. Default `null` → comportamiento previo.
- **Inyección `?? new`:** `ReciboCajaDocumentTypePolicy` inyecta `LegalizacionDocumentTypePolicy` con fallback; `Application.php` la pasa como argumento.
- **Banner guard:** el banner requiere `!empty($invoice->advance_id) && $invoice->usesLegalizationView()` (una Legalización sin vincular NO muestra banner).
- **Anti-drift:** no se tocan mapeos estado→pill (`InvoicePresentation::STATUS_BADGES`); `STATUS_LEGALIZADA => 'pill-primary-soft'` ya existe.
- **Slugs persistidos inmutables:** no tocar `'Legalización'`/`'Recibo de Caja'`/`legalizada`.
- **Estilo:** `composer cs-fix` antes de cada commit; solo stagear los archivos de la tarea (revertir deuda preexistente que toque `cs-fix`). `config/bootstrap.php` (cambio del usuario) no se toca.
- **Commits:** conventional, SIN atribución (no `Co-Authored-By`).

---

### Task 1: Predicado `Invoice::usesLegalizationView()`

**Files:**
- Modify: `src/Model/Entity/Invoice.php` (nuevo método, junto a `isRejected`/`isApproved`/`isPaid`)
- Create: `tests/TestCase/Model/Entity/InvoiceTest.php`

**Interfaces:**
- Produces: `Invoice::usesLegalizationView(): bool` — true si `document_type === 'Legalización'`, o `document_type === 'Recibo de Caja'` y `advance_id !== null`.

- [ ] **Step 1: Escribir el test (falla)**

Create `tests/TestCase/Model/Entity/InvoiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testUsesLegalizationViewForLegalizacion(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION]);
        $this->assertTrue($invoice->usesLegalizationView());
    }

    public function testUsesLegalizationViewForLinkedReciboDeCaja(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 123,
        ]);
        $this->assertTrue($invoice->usesLegalizationView());
    }

    public function testDoesNotUseLegalizationViewForUnlinkedReciboDeCaja(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA]);
        $this->assertFalse($invoice->usesLegalizationView());
    }

    public function testDoesNotUseLegalizationViewForFactura(): void
    {
        $invoice = new Invoice(['document_type' => InvoiceConstants::DOCTYPE_FACTURA, 'advance_id' => 5]);
        $this->assertFalse($invoice->usesLegalizationView());
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter InvoiceTest`
Expected: FAIL — `Call to undefined method App\Model\Entity\Invoice::usesLegalizationView()`.

- [ ] **Step 3: Añadir el método**

Modify `src/Model/Entity/Invoice.php`, después de `isPaid()` (~`:70`):

```php
    /**
     * True si la factura usa la vista de legalización (pipeline reducido de 3 pasos,
     * secciones de tesorería/pago ocultas): una Legalización, o un Recibo de Caja
     * vinculado a un anticipo. Fuente única del criterio — consumida por
     * InvoicePresentation y los banners de vista/edición.
     */
    public function usesLegalizationView(): bool
    {
        return $this->document_type === InvoiceConstants::DOCTYPE_LEGALIZACION
            || ($this->document_type === InvoiceConstants::DOCTYPE_RECIBO_CAJA
                && $this->advance_id !== null);
    }
```

- [ ] **Step 4: Correr — debe pasar**

Run: `vendor/bin/phpunit --filter InvoiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Model/Entity/Invoice.php tests/TestCase/Model/Entity/InvoiceTest.php
git commit -m "feat: predicado Invoice::usesLegalizationView (Legalización o Recibo de Caja vinculado)"
```

---

### Task 2: Promoción del RC vinculado a `legalizada` + docs

**Files:**
- Modify: `src/Service/Pipeline/Invoice/LinkedInvoiceLegalizer.php:34-38`
- Modify: `src/Constants/InvoiceConstants.php` (comentario de `STATUS_LEGALIZADA`, ~`:83-85`)
- Modify: `CLAUDE.md` (invariante "Invoice Pipeline")
- Modify: `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php` (test de integración)

**Interfaces:**
- Consumes: `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES` (Fase 1); `LinkedInvoiceLegalizer::legalizeFor(int $advanceInvoiceId, int $userId): int`.

- [ ] **Step 1: Escribir el test de integración (falla)**

Modify `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php`. Añadir `use App\Service\InvoiceHistoryService;` y `use App\Service\Pipeline\Invoice\LinkedInvoiceLegalizer;` al bloque de `use`, y el test:

```php
    public function testLegalizeForPromotesLinkedReciboDeCaja(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();

        // Un RC vinculado en Contabilidad y una Legalización vinculada en Contabilidad.
        $rc = InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $leg = InvoiceFactory::new(['advance_id' => $anticipo->id])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        $user = UserFactory::new()->save();

        $count = (new LinkedInvoiceLegalizer(new InvoiceHistoryService()))
            ->legalizeFor((int)$anticipo->id, (int)$user->id);

        $this->assertSame(2, $count);
        $invoices = $this->fetchTable('Invoices');
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $invoices->get($rc->id)->pipeline_status);
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $invoices->get($leg->id)->pipeline_status);
    }
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter testLegalizeForPromotesLinkedReciboDeCaja`
Expected: FAIL — hoy `legalizeFor` filtra `document_type = 'Legalización'`, promueve solo `$leg` → `count = 1` (esperaba 2); el RC sigue en `Contabilidad`.

- [ ] **Step 3: Ampliar el filtro de promoción**

Modify `src/Service/Pipeline/Invoice/LinkedInvoiceLegalizer.php` (`:34-38`):

```php
        $linked = $invoicesTable->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            ])
            ->all();
```

Además, actualizar el docblock de clase (`:11-18`), que dice "todas las facturas tipo Legalización
vinculadas", para reflejar que ahora también promueve Recibo de Caja vinculado (comentario, sin
cambio de comportamiento).

- [ ] **Step 4: Correr — debe pasar**

Run: `vendor/bin/phpunit --filter testLegalizeForPromotesLinkedReciboDeCaja`
Expected: PASS.

- [ ] **Step 5: Actualizar la invariante documentada**

Modify `src/Constants/InvoiceConstants.php` (~`:83-85`), el comentario de `STATUS_LEGALIZADA`:

```php
    // Estado terminal de legalización. Lo alcanzan las Legalización y los Recibo de Caja
    // VINCULADOS a un anticipo (advance_id != null) al legalizarse el anticipo.
    // No participa en PIPELINE_STATUSES (flujo normal). Ver self::ALL_STATUSES.
    public const STATUS_LEGALIZADA = PipelineStatus::LEGALIZADA->value;
```

En `CLAUDE.md`, sección "Invoice Pipeline", donde dice que `legalizada` es *"exclusivo de `document_type = 'Legalización'`"*, ajustar a *"exclusivo de las facturas que usan la vista de legalización: `Legalización` y `Recibo de Caja` vinculado (`advance_id != null`)"*.

- [ ] **Step 6: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Service/Pipeline/Invoice/LinkedInvoiceLegalizer.php \
        src/Constants/InvoiceConstants.php CLAUDE.md \
        tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php
git commit -m "feat: promover Recibo de Caja vinculado a legalizada al cierre del anticipo"
```

---

### Task 3: Policy `advance_id`-aware (pipeline visual + secciones)

**Files:**
- Modify: `src/Service/Pipeline/Invoice/DocumentTypePolicy.php` (interfaz: 2 firmas + typo docblock `:28`)
- Modify: `src/Service/Pipeline/Invoice/Policy/StandardDocumentTypePolicy.php` (firmas)
- Modify: `src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php` (firmas)
- Modify: `src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php` (firmas)
- Modify: `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php` (constructor + delegación)
- Modify: `src/Service/InvoicePipelineService.php:55-70`
- Modify: `src/Controller/InvoicesController.php` (`:215`, `:431`, `:436`)
- Modify: `src/Application.php:316`
- Modify: `tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php`

**Interfaces:**
- Consumes: `LegalizacionDocumentTypePolicy` (existente); `InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION`.
- Produces: `DocumentTypePolicy::getPipelineStatusesForView(?object $invoice = null): array`; `filterVisibleSections(array $sections, ?object $invoice = null): array`; `InvoicePipelineService::getPipelineStatusesFor(?string $documentType = null, ?object $invoice = null)`; `getVisibleSections(int, string, ?string, ?object)`.

- [ ] **Step 1: Escribir los tests de la policy RC (fallan)**

Modify `tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php`. El `testUsesFullPipelineForView` existente sigue válido (invoice `null` → Standard). Añadir:

```php
    public function testUsesLegalizationPipelineWhenLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION,
            $policy->getPipelineStatusesForView($invoice),
        );
    }

    public function testUsesFullPipelineWhenUnlinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];

        $this->assertSame(
            InvoiceConstants::PIPELINE_STATUSES,
            $policy->getPipelineStatusesForView($invoice),
        );
    }

    public function testHidesTreasurySectionsWhenLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertSame(
            ['ledger', 'accounting'],
            $policy->filterVisibleSections(['ledger', 'accounting', 'treasury', 'payment_authorization'], $invoice),
        );
    }

    public function testKeepsAllSectionsWhenUnlinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];
        $input = ['ledger', 'treasury', 'payment_authorization'];

        $this->assertSame($input, $policy->filterVisibleSections($input, $invoice));
    }
```

- [ ] **Step 2: Correr — deben fallar**

Run: `vendor/bin/phpunit --filter ReciboCajaDocumentTypePolicyTest`
Expected: FAIL — `getPipelineStatusesForView`/`filterVisibleSections` aún no aceptan el argumento invoice ni delegan.

- [ ] **Step 3: Ampliar la firma de la interfaz + typo**

Modify `src/Service/Pipeline/Invoice/DocumentTypePolicy.php`:

```php
    /**
     * Estados visuales del pipeline (Standard/Anticipo: 6; Legalización: 3).
     * Un doctype puede depender del invoice (p. ej. Recibo de Caja vinculado).
     *
     * @return array<string>
     */
    public function getPipelineStatusesForView(?object $invoice = null): array;
```
```php
    /**
     * Filtra secciones visibles que no aplican a este doctype. Puede depender del
     * invoice (p. ej. Recibo de Caja vinculado oculta tesorería/pago).
     *
     * @param array<string> $sections
     * @return array<string>
     */
    public function filterVisibleSections(array $sections, ?object $invoice = null): array;
```

- [ ] **Step 4: Actualizar las 3 policies que ignoran el invoice**

En `StandardDocumentTypePolicy.php`, `AnticipoDocumentTypePolicy.php` y `LegalizacionDocumentTypePolicy.php`, cambiar SOLO las firmas (el cuerpo no cambia):

```php
    public function getPipelineStatusesForView(?object $invoice = null): array
```
```php
    public function filterVisibleSections(array $sections, ?object $invoice = null): array
```

(Los cuerpos NO cambian: `Standard` devuelve `PIPELINE_STATUSES` / `$sections`; `Anticipo`
`PIPELINE_STATUSES` / filtra `revision`; `Legalizacion` `PIPELINE_STATUSES_LEGALIZACION` / filtra
`treasury`+`payment_authorization`.)

- [ ] **Step 5: Constructor + delegación en `ReciboCajaDocumentTypePolicy`**

Modify `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php`:

```php
    private readonly LegalizacionDocumentTypePolicy $legalizacion;

    public function __construct(?LegalizacionDocumentTypePolicy $legalizacion = null)
    {
        $this->legalizacion = $legalizacion ?? new LegalizacionDocumentTypePolicy();
    }
```
```php
    public function getPipelineStatusesForView(?object $invoice = null): array
    {
        if (($invoice->advance_id ?? null) !== null) {
            return $this->legalizacion->getPipelineStatusesForView($invoice);
        }

        return InvoiceConstants::PIPELINE_STATUSES;
    }

    public function filterVisibleSections(array $sections, ?object $invoice = null): array
    {
        if (($invoice->advance_id ?? null) !== null) {
            return $this->legalizacion->filterVisibleSections($sections, $invoice);
        }

        return $sections;
    }
```

- [ ] **Step 6: Propagar el invoice en el service**

Modify `src/Service/InvoicePipelineService.php` (`:55-70`):

```php
    public function getPipelineStatusesFor(?string $documentType = null, ?object $invoice = null): array
    {
        return $this->docTypePolicies->for($documentType)->getPipelineStatusesForView($invoice);
    }
```
```php
    public function getVisibleSections(int $roleId, string $status, ?string $documentType = null, ?object $invoice = null): array
    {
        $sections = $this->fieldPolicy->getVisibleSections($roleId, $status);

        return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections, $invoice);
    }
```

- [ ] **Step 7: Pasar el invoice en los 3 call-sites**

Modify `src/Controller/InvoicesController.php`:
- `:215` (dentro de `view()`):
```php
        $pipelineStatuses = $this->pipeline->getPipelineStatusesFor($invoice->document_type, $invoice);
```
- `:431` (dentro de `_buildEditViewModel`):
```php
            visibleSections: $this->pipeline->getVisibleSections($roleId, $currentStatus, $invoice->document_type, $invoice),
```
- `:436`:
```php
            pipelineStatuses: $this->pipeline->getPipelineStatusesFor($invoice->document_type, $invoice),
```

- [ ] **Step 8: Inyectar la policy de Legalización en el DI**

Modify `src/Application.php` (`:316`):

```php
        $container->addShared(ReciboCajaDocumentTypePolicy::class)
            ->addArgument(LegalizacionDocumentTypePolicy::class);
```

- [ ] **Step 9: Correr los tests de policies + pipeline + factory**

Run: `vendor/bin/phpunit --filter "ReciboCajaDocumentTypePolicyTest|StandardDocumentTypePolicyTest|LegalizacionDocumentTypePolicyTest|AnticipoDocumentTypePolicyTest|InvoicePipelineServiceTest|DocumentTypePolicyFactoryTest"`
Expected: PASS — los 4 tests nuevos de la policy RC pasan; los tests de las otras policies (que llaman sin el argumento invoice) siguen verdes por el default `null`.

- [ ] **Step 10: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Service/Pipeline/Invoice/DocumentTypePolicy.php \
        src/Service/Pipeline/Invoice/Policy/StandardDocumentTypePolicy.php \
        src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php \
        src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php \
        src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php \
        src/Service/InvoicePipelineService.php src/Controller/InvoicesController.php \
        src/Application.php \
        tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php
git commit -m "feat: pipeline visual reducido y secciones ocultas para Recibo de Caja vinculado"
```

---

### Task 4: Index — `InvoicePresentation` usa el predicado

**Files:**
- Modify: `src/View/Presentation/InvoicePresentation.php:72`
- Modify: `tests/TestCase/View/Presentation/InvoicePresentationTest.php`

**Interfaces:**
- Consumes: `Invoice::usesLegalizationView()` (Task 1).

- [ ] **Step 1: Escribir el test (falla)**

Modify `tests/TestCase/View/Presentation/InvoicePresentationTest.php`, junto a `testForRowLegalizationUsesShortPipeline`:

```php
    public function testForRowLinkedReciboDeCajaUsesShortPipeline(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 77,
        ]));

        $this->assertTrue($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION, $row->pipelineSteps);
    }

    public function testForRowUnlinkedReciboDeCajaUsesFullPipeline(): void
    {
        $row = InvoicePresentation::forRow($this->invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
        ]));

        $this->assertFalse($row->isLegalization);
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $row->pipelineSteps);
    }
```

- [ ] **Step 2: Correr — deben fallar**

Run: `vendor/bin/phpunit --filter "testForRowLinkedReciboDeCajaUsesShortPipeline|testForRowUnlinkedReciboDeCajaUsesFullPipeline"`
Expected: FAIL en el primero — hoy `isLegalization = document_type === 'Legalización'`, un RC vinculado da `false` y 6 pasos.

- [ ] **Step 3: Usar el predicado**

Modify `src/View/Presentation/InvoicePresentation.php` (`:72`):

```php
        $isLegalization = $invoice->usesLegalizationView();
```

(Las líneas `:73-77` que eligen `$steps` y calculan `$stageIdx` no cambian.)

- [ ] **Step 4: Correr — deben pasar**

Run: `vendor/bin/phpunit --filter "InvoicePresentationTest"`
Expected: PASS (incluye los 2 nuevos y los existentes de Legalización/Factura).

- [ ] **Step 5: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/View/Presentation/InvoicePresentation.php \
        tests/TestCase/View/Presentation/InvoicePresentationTest.php
git commit -m "feat: mini-pipeline reducido para Recibo de Caja vinculado en el listado"
```

---

### Task 5: Banners — ViewModel + templates (tipo real + guard)

**Files:**
- Modify: `src/ViewModel/InvoiceViewViewModel.php:135`
- Modify: `templates/Invoices/view.php:58-66`
- Modify: `templates/Invoices/edit.php:229-238`
- Modify: `tests/TestCase/ViewModel/InvoiceViewViewModelTest.php`

**Interfaces:**
- Consumes: `Invoice::usesLegalizationView()` (Task 1); `InvoiceViewViewModel::isLinkedLegalization` (bool).

- [ ] **Step 1: Escribir el test (falla)**

Modify `tests/TestCase/ViewModel/InvoiceViewViewModelTest.php`. El archivo define `vm(Invoice $invoice, ...)` (`:22-25`) e importa `App\Model\Entity\Invoice` (`:7`); construir el invoice con `new Invoice([...])` inline, como el test análogo `testIsLinkedLegalization` (`:107-114`). **No existe un helper `$this->invoice(...)`.** Añadir:

```php
    public function testIsLinkedLegalizationForLinkedReciboDeCaja(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'advance_id' => 88,
        ]);
        $this->assertTrue($this->vm($invoice)->isLinkedLegalization);
    }

    public function testIsNotLinkedLegalizationForUnlinkedLegalizacion(): void
    {
        $invoice = new Invoice([
            'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
            'advance_id' => null,
        ]);
        $this->assertFalse($this->vm($invoice)->isLinkedLegalization);
    }
```

- [ ] **Step 2: Correr — `testIsLinkedLegalizationForLinkedReciboDeCaja` debe fallar**

Run: `vendor/bin/phpunit --filter "testIsLinkedLegalizationForLinkedReciboDeCaja|testIsNotLinkedLegalizationForUnlinkedLegalizacion"`
Expected: FAIL en el primero — hoy `isLinkedLegalization = document_type === 'Legalización' && advance_id`, un RC vinculado da `false`. El segundo pasa desde ya (guard `advance_id` correcto).

- [ ] **Step 3: Ampliar el flag en el ViewModel**

Modify `src/ViewModel/InvoiceViewViewModel.php` (`:135-136`):

```php
        $this->isLinkedLegalization = !empty($invoice->advance_id)
            && $invoice->usesLegalizationView();
```

- [ ] **Step 4: Correr — deben pasar**

Run: `vendor/bin/phpunit --filter "InvoiceViewViewModelTest"`
Expected: PASS.

- [ ] **Step 5: Banner de la vista (tipo real)**

Modify `templates/Invoices/view.php` (`:58-66`), el contenido del `if ($viewModel->isLinkedLegalization)`:

```php
<?php if ($viewModel->isLinkedLegalization): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
            Esta factura (<strong><?= h($invoice->document_type) ?></strong>) está vinculada al
            <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 6: Banner de la edición (guard + tipo real)**

Modify `templates/Invoices/edit.php` (`:229-238`):

```php
<?php /* ── Alerta: factura vinculada a un anticipo ───────── */ ?>
<?php if (!empty($invoice->advance_id) && $invoice->usesLegalizationView()): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
        Esta factura (<strong><?= h($invoice->document_type) ?></strong>) está vinculada al
        <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
    </div>
</div>
<?php endif; ?>
```

- [ ] **Step 7: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/ViewModel/InvoiceViewViewModel.php templates/Invoices/view.php \
        templates/Invoices/edit.php \
        tests/TestCase/ViewModel/InvoiceViewViewModelTest.php
git commit -m "feat: banner de vinculación al anticipo para Recibo de Caja (tipo real)"
```

---

### Task 6: Verificación final de la suite

- [ ] **Step 1: Suite completa**

Run: `composer test`
Expected: verde (789 tests de baseline + los nuevos de Fase 2), 0 failures/0 errors. El exit≠0 por notices/deprecations preexistentes + el warning `apc.enable_cli` es esperado. Si aparecen fallos en cascada por contaminación de BD entre suites, re-correr limpio antes de concluir.

- [ ] **Step 2: Estilo global**

Run: `composer cs-check`
Expected: sin violaciones NUEVAS en los archivos tocados (la deuda preexistente del repo permanece; verificar por diff contra el commit base si hay duda).

---

## Self-Review

**Spec coverage:**
- §4.1 Predicado `usesLegalizationView` → Task 1. ✓
- §4.2 Promoción (`LinkedInvoiceLegalizer` IN) → Task 2 Step 3. ✓
- §4.3 Policy `advance_id`-aware (interfaz + 4 policies + service + 3 call-sites + DI + typo) → Task 3. ✓
- §4.4 Index (`InvoicePresentation`) → Task 4. ✓
- §4.5 Banners (ViewModel + view/edit, tipo real + guard) → Task 5. ✓
- §4.6 Docs (invariante `legalizada`) → Task 2 Step 5. ✓
- §8 Testing (predicado, promoción, policy, index, banners, retrocompat) → Tasks 1–5. ✓
- Fuera de alcance (Fase 3, ajuste de datos, `linkCandidates` test) → no se tocan. ✓

**Placeholder scan:** sin TBD/TODO; todos los pasos de código llevan el código completo. Los tests
usan el arnés real verificado del repo: `InvoicePresentationTest` sí tiene helper `invoice(array)`;
`InvoiceViewViewModelTest` NO — sus tests construyen `new Invoice([...])` y usan `$this->vm(...)`
(Task 5 Step 1 lo hace así); los tests de policy usan `(object)['advance_id'=>...]`; la integración
de Task 2 usa `InvoiceFactory::new()`/`fetchTable`/`UserFactory` como los tests vecinos.

**Type consistency:** `usesLegalizationView(): bool` definido en Task 1 y consumido en Tasks 4/5; `getPipelineStatusesForView(?object $invoice = null)` y `filterVisibleSections(array, ?object $invoice = null)` con la misma firma en la interfaz (Task 3 Step 3), las 4 implementaciones (Steps 4-5) y el service (Step 6); los 3 call-sites (Step 7) pasan `$invoice` de forma uniforme; `ReciboCajaDocumentTypePolicy` construida con arg opcional en Application (Step 8) y sin arg en su test (default fallback).
