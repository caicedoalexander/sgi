# Hub de consulta del Anticipo con detalle de legalización — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `AdvancesController::view()` deje de redirigir a la vista operativa de legalización y sea un hub de consulta read-only del anticipo (datos + desembolso + soportes + bloque read-only de la legalización con botón "Gestionar legalización →").

**Architecture:** Capa de vista (ViewModel ↔ template). Se centraliza la derivación de dominio del resumen de legalización en un helper `ViewModel/Support/LegalizationSummary` compartido por los dos ViewModels; se extraen 2 parciales de template (`_linked_invoices`, `_soportes`) reusados por la vista operativa y el hub mediante un flag `editable`. Sin cambios de pipeline, migraciones ni RBAC.

**Tech Stack:** CakePHP 5.3, PHP 8.4+, PHPUnit, Bootstrap + Bootstrap Icons, elements PHP compartidos (`payment_section`, `documents_section`/`document_row`, `pipeline_sidebar`).

**Spec:** `docs/superpowers/specs/2026-07-04-vista-anticipo-legalizacion-design.md`

## Global Constraints

- PHP `>=8.4`, CakePHP 5.3. Servicios obtienen tablas vía `TableRegistry::getTableLocator()->get('X')`, nunca `$this->X`.
- **Anti-drift (regla dura):** el mapeo estado→pill/icono vive SOLO en `src/View/Presentation/*Presentation` (const). Prohibido literal inline de mapas estado→pill en `.php`. Dirección de dependencia única: **VM → Presentation**.
- **VM deriva, no consulta:** el controller carga los datos crudos y los inyecta al ViewModel; el VM no accede a `TableRegistry`.
- CSS: prefijo `.spi-`. Canon visual VIEW = `.spi-invoice-view-grid` (grid `340px 1fr`), sidebar `pipeline_sidebar` + `main.spi-invoice-view-right` con `.spi-card`s.
- Slugs de pipeline en español sin acento (`aprobacion`, `contabilidad`, …).
- Tests: `vendor/bin/phpunit` (credenciales de test en `config/.env`). Estilo: `composer cs-check` (auto-fix con `composer cs-fix`).
- Commits frecuentes, formato `<type>: <description>` (feat/refactor/test). Atribución deshabilitada globalmente (sin `Co-Authored-By`).

---

### Task 1: Helper `LegalizationSummary` + delegación en `AdvanceLegalizationViewModel`

Centraliza la derivación de dominio del resumen de legalización (hoy embebida en `AdvanceLegalizationViewModel::build()`). El VM operativo delega en el helper sin cambiar su salida.

**Files:**
- Create: `src/ViewModel/Support/LegalizationSummary.php`
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php:92-174` (método `build()`) y añadir `use`.
- Test: `tests/TestCase/ViewModel/Support/LegalizationSummaryTest.php`

**Interfaces:**
- Produces: `App\ViewModel\Support\LegalizationSummary` con propiedades públicas `float $linkedTotal`, `float $advanceTotal`, `float $diff`, `string $diffBadgeClass`, `int $linkedCount`, `?object $relationDocument`, `array $signatureHistory`, `array{0:string,1:string} $statusBadge`, `list<string> $casePipelineSteps`, `string $caseLabel`, y `const CASE_LABELS`. Constructor: `__construct(AdvanceLegalization $leg, float $advanceTotal, iterable $linkedInvoices)`.

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/ViewModel/Support/LegalizationSummaryTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel\Support;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\ViewModel\Support\LegalizationSummary;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit puro: derivación de totales, diff, badge de estado y caso del resumen de
 * legalización. Sin BD; entidades in-memory.
 */
final class LegalizationSummaryTest extends TestCase
{
    private function leg(array $data = []): AdvanceLegalization
    {
        return new AdvanceLegalization($data + [
            'id' => 1,
            'status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'case_type' => null,
            'advance_legalization_signatures' => [],
        ]);
    }

    public function testTotalsAndExactDiff(): void
    {
        $summary = new LegalizationSummary(
            $this->leg(),
            1000.0,
            [new Entity(['amount' => 600]), new Entity(['amount' => 400])],
        );

        $this->assertSame(1000.0, $summary->linkedTotal);
        $this->assertSame(2, $summary->linkedCount);
        $this->assertSame(0.0, $summary->diff);
        $this->assertSame('pill-primary-soft', $summary->diffBadgeClass);
    }

    public function testShortageDiffIsWarning(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, [new Entity(['amount' => 800])]);

        $this->assertSame(200.0, $summary->diff);
        $this->assertSame('pill-warning-soft', $summary->diffBadgeClass);
    }

    public function testSurplusDiffIsDanger(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, [new Entity(['amount' => 1200])]);

        $this->assertSame(-200.0, $summary->diff);
        $this->assertSame('pill-danger-soft', $summary->diffBadgeClass);
    }

    public function testStatusBadgeAndCaseLabel(): void
    {
        $summary = new LegalizationSummary(
            $this->leg(['status' => AdvanceConstants::STATUS_TESORERIA, 'case_type' => AdvanceConstants::CASE_SOBRANTE]),
            1000.0,
            [],
        );

        $this->assertSame('Tesorería', $summary->statusBadge[0]);
        $this->assertSame('pill-info-soft', $summary->statusBadge[1]);
        $this->assertSame('Sobrante', $summary->caseLabel);
        $this->assertSame(AdvanceConstants::PIPELINE_STATUSES_SOBRANTE, $summary->casePipelineSteps);
        // TESORERIA es el índice 4 en PIPELINE_STATUSES_SOBRANTE.
        $this->assertSame(4, $summary->pipelineIdx);
        $this->assertNotSame('', $summary->pipelineVariant);
    }

    public function testNoSignaturesLeavesRelationDocumentNull(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, []);

        $this->assertNull($summary->relationDocument);
        $this->assertSame([], $summary->signatureHistory);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/ViewModel/Support/LegalizationSummaryTest.php`
Expected: FAIL — `Class "App\ViewModel\Support\LegalizationSummary" not found`.

- [ ] **Step 3: Create the helper**

Create `src/ViewModel/Support/LegalizationSummary.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel\Support;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\PipelineColorMap;

/**
 * Derivación de dominio del resumen de una legalización de anticipo, compartida
 * por AdvanceLegalizationViewModel (vista operativa) y AdvanceViewViewModel (hub
 * de consulta read-only). Fuente única de linkedTotal/diff/diffBadgeClass/split
 * de firmas/badge de estado para evitar drift entre ambos ViewModels.
 *
 * `linkedInvoices` debe ser re-iterable (array o ResultSet buffered de ->all());
 * se recorre una vez aquí para totales y de nuevo en el template.
 */
final readonly class LegalizationSummary
{
    public const CASE_LABELS = [
        AdvanceConstants::CASE_EXACTO => 'Exacto',
        AdvanceConstants::CASE_FALTANTE => 'Faltante',
        AdvanceConstants::CASE_SOBRANTE => 'Sobrante',
    ];

    public float $linkedTotal;
    public float $diff;
    public string $diffBadgeClass;
    public int $linkedCount;
    /** @var \App\Model\Entity\AdvanceLegalizationSignature|null */
    public ?object $relationDocument;
    /** @var array<int,\App\Model\Entity\AdvanceLegalizationSignature> */
    public array $signatureHistory;
    /** @var array{0:string,1:string} */
    public array $statusBadge;
    /** @var list<string> */
    public array $casePipelineSteps;
    public string $caseLabel;
    public int $pipelineIdx;
    public string $pipelineVariant;

    public function __construct(
        public AdvanceLegalization $leg,
        public float $advanceTotal,
        public iterable $linkedInvoices,
    ) {
        $linkedTotal = 0.0;
        $count = 0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
            $count++;
        }
        $this->linkedTotal = $linkedTotal;
        $this->linkedCount = $count;
        $this->diff = $advanceTotal - $linkedTotal;
        $this->diffBadgeClass = abs($this->diff) < 0.005
            ? 'pill-primary-soft'
            : ($this->diff > 0 ? 'pill-warning-soft' : 'pill-danger-soft');

        $relationDocument = null;
        $signatureHistory = [];
        if ($leg->advance_legalization_signatures) {
            $sigs = $leg->advance_legalization_signatures;
            usort($sigs, fn($a, $b) => $b->id <=> $a->id);
            foreach ($sigs as $sig) {
                if ($relationDocument === null && ($sig->isPending() || $sig->isSigned())) {
                    $relationDocument = $sig;
                } else {
                    $signatureHistory[] = $sig;
                }
            }
        }
        $this->relationDocument = $relationDocument;
        $this->signatureHistory = $signatureHistory;

        $this->statusBadge = [
            AdvanceConstants::STATUS_LABELS[$leg->status] ?? 'Desconocido',
            AdvancePresentation::STATUS_BADGES[$leg->status] ?? 'pill-muted',
        ];
        $this->casePipelineSteps = AdvanceConstants::PIPELINE_STATUSES_BY_CASE[$leg->case_type ?? '']
            ?? AdvanceConstants::PIPELINE_STATUSES_EXACTO;
        $this->caseLabel = $leg->case_type
            ? (self::CASE_LABELS[$leg->case_type] ?? $leg->case_type)
            : '';
        $idx = array_search($leg->status, $this->casePipelineSteps, true);
        $this->pipelineIdx = $idx === false ? -1 : (int)$idx;
        $this->pipelineVariant = PipelineColorMap::variant($leg->status);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/ViewModel/Support/LegalizationSummaryTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Refactor `AdvanceLegalizationViewModel::build()` to delegate**

In `src/ViewModel/AdvanceLegalizationViewModel.php`, add the import after the existing `use` block (line 10):

```php
use App\ViewModel\Support\LegalizationSummary;
```

Replace the entire `build()` method (lines 92-174) with:

```php
    public function build(): array
    {
        $summary = new LegalizationSummary(
            $this->leg,
            (float)$this->invoice->amount,
            $this->linkedInvoices,
        );

        // ── Derivaciones de presentación (antes inline en el template) ──
        $legPipelineLabels = AdvanceConstants::STATUS_LABELS;

        $beneficiary = $this->invoice->provider->name ?? ($this->invoice->employee->full_name ?? '—');
        $beneficiaryDoc = $this->invoice->provider->document_number
            ?? ($this->invoice->employee->document_number ?? null);
        $beneficiaryDocType = $this->invoice->provider_id
            ? ($this->invoice->provider->document_type ?? '')
            : ($this->invoice->employee_id ? ($this->invoice->employee->document_type ?? '') : '');
        $beneficiaryKind = $this->invoice->provider_id
            ? 'Proveedor'
            : ($this->invoice->employee_id ? 'Empleado' : '—');

        return [
            'invoice' => $this->invoice,
            'leg' => $this->leg,
            'linkedInvoices' => $this->linkedInvoices,
            'linkedTotal' => $summary->linkedTotal,
            'advanceTotal' => $summary->advanceTotal,
            'diff' => $summary->diff,
            'relationDocument' => $summary->relationDocument,
            'signatureHistory' => $summary->signatureHistory,
            'bankingEntities' => $this->bankingEntities,
            'surplusPayment' => $this->surplusPayment,
            'roleName' => $this->roleName,
            'canRegisterRefund' => $this->canRegisterRefund,
            'canAuthorizeRefundPayment' => $this->canAuthorizeRefundPayment,
            'canConfirmRefundPayment' => $this->canConfirmRefundPayment,
            'approvals' => $this->approvals,
            'approvalSummary' => $this->approvalSummary,
            'canManageApprovers' => $this->canManageApprovers,
            'isAprobacion' => $this->isAprobacion,
            'approvers' => $this->approvers,
            // Derivaciones de presentación.
            'pageTitle' => $this->pageTitle,
            'legPipelineLabels' => $legPipelineLabels,
            'beneficiary' => $beneficiary,
            'beneficiaryDoc' => $beneficiaryDoc,
            'beneficiaryDocType' => $beneficiaryDocType,
            'beneficiaryKind' => $beneficiaryKind,
            'ps' => $this->currentStatusBadge,
            'linkedCount' => $summary->linkedCount,
            'diffBadgeClass' => $summary->diffBadgeClass,
            'caseLabels' => LegalizationSummary::CASE_LABELS,
        ];
    }
```

- [ ] **Step 6: Run the ViewModel tests to verify no behavior change**

Run: `vendor/bin/phpunit tests/TestCase/ViewModel/ tests/TestCase/ViewModel/Support/`
Expected: PASS. Nota: `AdvanceLegalizationViewModelApprovalTest` cubre el **constructor** del VM (no `build()`), y sigue verde porque el constructor no cambió. La equivalencia de las claves de `build()` está garantizada estructuralmente (mismas claves, ahora tomadas de `$summary`) y se comprueba en el render manual de la vista operativa (Tasks 3-4 Step 5); la derivación numérica en sí la cubre `LegalizationSummaryTest`.

- [ ] **Step 7: Style check**

Run: `composer cs-check`
Expected: no errors on the new/modified files (run `composer cs-fix` if needed).

- [ ] **Step 8: Commit**

```bash
git add src/ViewModel/Support/LegalizationSummary.php src/ViewModel/AdvanceLegalizationViewModel.php tests/TestCase/ViewModel/Support/LegalizationSummaryTest.php
git commit -m "refactor: extrae LegalizationSummary compartido y delega en AdvanceLegalizationViewModel"
```

---

### Task 2: Ampliar `AdvanceViewViewModel` con el resumen read-only de la legalización

El hub necesita, además de los datos del anticipo (ya derivados), el resumen de la legalización cuando existe.

**Files:**
- Modify: `src/ViewModel/AdvanceViewViewModel.php` (constructor + nuevas propiedades + `use`)
- Test: `tests/TestCase/ViewModel/AdvanceViewViewModelTest.php` (añadir casos)

**Interfaces:**
- Consumes: `App\ViewModel\Support\LegalizationSummary` (Task 1).
- Produces: `AdvanceViewViewModel` constructor pasa a `__construct(Invoice $record, ?AdvanceLegalization $legalization = null, iterable $linkedInvoices = [])`; nuevas propiedades públicas `bool $hasLegalization`, `?LegalizationSummary $legalizationSummary`, `array $documentRows` (params para `document_row`) y `int $totalDocs`. Las propiedades existentes (`record`, `pageTitle`, `currentStatusBadge`, `isTerminal`, `beneficiary`, etc.) se conservan. (`AdvanceViewViewModel` ya importa `InvoiceConstants` e `InvoicePresentation` — no hay que añadirlos.)

- [ ] **Step 1: Write the failing test**

Append these methods to `tests/TestCase/ViewModel/AdvanceViewViewModelTest.php` (add `use App\Constants\AdvanceConstants;`, `use App\Model\Entity\AdvanceLegalization;` and `use App\ViewModel\Support\LegalizationSummary;` to the imports):

```php
    public function testNoLegalizationByDefault(): void
    {
        $vm = new AdvanceViewViewModel($this->advance(['amount' => 1000]));

        $this->assertFalse($vm->hasLegalization);
        $this->assertNull($vm->legalizationSummary);
        $this->assertSame(0, $vm->totalDocs);
        $this->assertSame([], $vm->documentRows);
    }

    public function testLegalizationSummaryDerivedWhenPresent(): void
    {
        $leg = new AdvanceLegalization([
            'id' => 1,
            'status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'case_type' => null,
            'advance_legalization_signatures' => [],
        ]);
        $vm = new AdvanceViewViewModel(
            $this->advance(['amount' => 1000]),
            $leg,
            [new \Cake\ORM\Entity(['amount' => 700])],
        );

        $this->assertTrue($vm->hasLegalization);
        $this->assertInstanceOf(LegalizationSummary::class, $vm->legalizationSummary);
        $this->assertSame(700.0, $vm->legalizationSummary->linkedTotal);
        $this->assertSame(300.0, $vm->legalizationSummary->diff);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/ViewModel/AdvanceViewViewModelTest.php`
Expected: FAIL — too few constructor arguments handling / `Undefined property ...::$hasLegalization` (the 3-arg constructor call errors, or the property doesn't exist).

- [ ] **Step 3: Extend the ViewModel**

In `src/ViewModel/AdvanceViewViewModel.php`, add to the imports (after line 7):

```php
use App\Model\Entity\AdvanceLegalization;
use App\ViewModel\Support\LegalizationSummary;
```

Add these property declarations alongside the existing ones (after `public array $pipelineSteps;`, ~line 34):

```php
    public bool $hasLegalization;
    public ?LegalizationSummary $legalizationSummary;
    /** @var list<array{doc:mixed,canDelete:bool,showBadge:bool,badgeColors:array<string,string>,statusLabels:array<string,string>}> */
    public array $documentRows;
    public int $totalDocs;
```

Change the constructor signature (line 36) from:

```php
    public function __construct(public Invoice $record)
    {
```

to:

```php
    public function __construct(
        public Invoice $record,
        public ?AdvanceLegalization $legalization = null,
        public iterable $linkedInvoices = [],
    ) {
```

At the end of the constructor body (after `$this->registryLines = $registry;`, before the closing brace at line 65), add:

```php
        $this->hasLegalization = $this->legalization !== null;
        $this->legalizationSummary = $this->legalization !== null
            ? new LegalizationSummary($this->legalization, (float)$record->amount, $this->linkedInvoices)
            : null;

        $docRows = [];
        foreach ($record->invoice_documents ?? [] as $doc) {
            $docRows[] = [
                'doc' => $doc,
                'canDelete' => false,
                'showBadge' => true,
                'badgeColors' => InvoicePresentation::STATUS_BADGES,
                'statusLabels' => InvoiceConstants::STATUS_LABELS,
            ];
        }
        $this->documentRows = $docRows;
        $this->totalDocs = count($docRows);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/TestCase/ViewModel/AdvanceViewViewModelTest.php`
Expected: PASS (all existing tests + the 2 new ones).

- [ ] **Step 5: Style check**

Run: `composer cs-check`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/ViewModel/AdvanceViewViewModel.php tests/TestCase/ViewModel/AdvanceViewViewModelTest.php
git commit -m "feat: AdvanceViewViewModel expone resumen read-only de la legalizacion"
```

---

### Task 3: Extraer el parcial `_linked_invoices` y refactorizar la vista operativa

Extrae la card "Facturas vinculadas" de `legalization.php` a un element reusable con flag `editable`. La vista operativa lo consume con `editable=true` (comportamiento idéntico).

**Files:**
- Create: `templates/element/advance_legalization/_linked_invoices.php`
- Modify: `templates/Advances/legalization.php:169-252` (reemplazar el bloque por una llamada al element)

**Interfaces:**
- Produces: `element('advance_legalization/_linked_invoices', [...])` con params: `AdvanceLegalization $leg`, `Invoice $invoice`, `iterable $linkedInvoices`, `float $linkedTotal`, `int $linkedCount`, `bool $editable`. Con `editable=false` NO renderiza los botones "Nueva"/"Vincular" ni el postLink de desvincular.

- [ ] **Step 1: Create the element**

Create `templates/element/advance_legalization/_linked_invoices.php`. Copy the markup currently at `templates/Advances/legalization.php:169-252` (the `<?php $liGrid = ...` line through the closing `</div>` of the `.spi-card`), wrapped with the header below. Apply exactly these transformations:

1. Header/docblock + defaults + `use` (the element needs its own imports):

```php
<?php
/**
 * Card "Facturas vinculadas" de una legalización de anticipo. Compartida por la
 * vista operativa (Advances/legalization.php, editable=true) y el hub de consulta
 * (Advances/view.php, editable=false). Con editable=false oculta los controles de
 * mutación (Nueva/Vincular/Desvincular).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\Invoice $invoice
 * @var iterable $linkedInvoices
 * @var float $linkedTotal
 * @var int $linkedCount
 * @var bool $editable
 */
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoiceBeneficiary;
use App\View\Presentation\InvoicePresentation;

$editable = $editable ?? true;
$liGrid = 'display:grid;grid-template-columns:1.1fr 1.8fr 0.9fr 1fr 1.2fr 32px;gap:12px;align-items:center;';
?>
```

2. In the copied markup, change the header actions condition (was `<?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>` around the "Nueva"/"Vincular" buttons) to:

```php
<?php if ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
```

3. In the copied markup, change the per-row unlink condition (the inner `<?php if ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>` that guards the `postLink` unlink button) to:

```php
<?php if ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
```

4. Remove the now-duplicated `<?php $liGrid = '...'; ?>` line from inside the copied body (it is defined in the header above). Keep everything else byte-for-byte.

- [ ] **Step 2: Refactor `legalization.php` to consume the element**

In `templates/Advances/legalization.php`, replace lines 169-252 (from `<?php $liGrid = ...` through the closing `</div>` that ends the "Facturas vinculadas" `.spi-card`) with:

```php
        <!-- Sección: Facturas vinculadas -->
        <?= $this->element('advance_legalization/_linked_invoices', [
            'leg' => $leg,
            'invoice' => $invoice,
            'linkedInvoices' => $linkedInvoices,
            'linkedTotal' => $linkedTotal,
            'linkedCount' => $linkedCount,
            'editable' => true,
        ]) ?>
```

- [ ] **Step 3: Lint both files**

Run: `php -l templates/element/advance_legalization/_linked_invoices.php && php -l templates/Advances/legalization.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Style check**

Run: `composer cs-check`
Expected: no errors.

- [ ] **Step 5: Manual render check of the operative view**

Start the server (`php bin/cake server`), log in, and open an advance in Fase 2 (`/advances/legalization/{id}`). Verify the "Facturas vinculadas" card renders identically to before (list, total, and — in estado `validacion` — the "Nueva"/"Vincular" buttons and per-row unlink icon).

- [ ] **Step 6: Commit**

```bash
git add templates/element/advance_legalization/_linked_invoices.php templates/Advances/legalization.php
git commit -m "refactor: extrae _linked_invoices reusable con flag editable"
```

---

### Task 4: Extraer el parcial `_soportes` y refactorizar la vista operativa

Extrae la card "Soportes" (relación de facturas + comprobante de consignación + historial de firmas) de `legalization.php` a un element reusable con flag `editable`.

**Files:**
- Create: `templates/element/advance_legalization/_soportes.php`
- Modify: `templates/Advances/legalization.php:464-631` (reemplazar el bloque por una llamada al element)

**Interfaces:**
- Produces: `element('advance_legalization/_soportes', [...])` con params: `AdvanceLegalization $leg`, `?object $relationDocument`, `array $signatureHistory`, `bool $editable`. Con `editable=false` NO renderiza los formularios de subir/reemplazar la relación de facturas.

- [ ] **Step 1: Create the element**

Create `templates/element/advance_legalization/_soportes.php`. Copy the markup currently at `templates/Advances/legalization.php:464-631` (the `<!-- Soportes -->` card, from `<div class="spi-card d-flex flex-column">` through its closing `</div>`), wrapped with the header below. Apply exactly these transformations:

1. Header/docblock + defaults + `use`:

```php
<?php
/**
 * Card "Soportes" de una legalización de anticipo: relación de facturas,
 * comprobante de consignación (caso faltante) e historial de firmas. Compartida
 * por la vista operativa (editable=true) y el hub de consulta (editable=false).
 * Con editable=false oculta los formularios de subir/reemplazar la relación.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\AdvanceLegalizationSignature|null $relationDocument
 * @var array $signatureHistory
 * @var bool $editable
 */
use App\Constants\AdvanceConstants;

$editable = $editable ?? true;
?>
```

2. In the copied markup, change the "reemplazar" form condition (was `<?php if (in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>`) to:

```php
<?php if ($editable && in_array($leg->status, [AdvanceConstants::STATUS_VALIDACION, AdvanceConstants::STATUS_REVISION_FIRMAS], true)): ?>
```

3. In the copied markup, the "Sin documento adjunto" branch that shows the upload form is `<?php elseif ($leg->status === AdvanceConstants::STATUS_VALIDACION): ?>`. Split its behavior by `editable`: when NOT editable, fall through to the plain "Sin documento" branch. Concretely, change that `elseif` condition to:

```php
<?php elseif ($editable && $leg->status === AdvanceConstants::STATUS_VALIDACION): ?>
```

(The existing final `<?php else: ?>` "Sin documento" branch then covers the read-only no-document case.)

Keep all other markup byte-for-byte.

- [ ] **Step 2: Refactor `legalization.php` to consume the element**

In `templates/Advances/legalization.php`, replace lines 464-631 (the entire `<!-- Soportes -->` `.spi-card`) with:

```php
    <!-- Soportes -->
    <?= $this->element('advance_legalization/_soportes', [
        'leg' => $leg,
        'relationDocument' => $relationDocument,
        'signatureHistory' => $signatureHistory,
        'editable' => true,
    ]) ?>
```

- [ ] **Step 3: Lint both files**

Run: `php -l templates/element/advance_legalization/_soportes.php && php -l templates/Advances/legalization.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Style check**

Run: `composer cs-check`
Expected: no errors.

- [ ] **Step 5: Add an integration smoke test for the operative view render**

Ambos parciales ya están extraídos y consumidos por `legalization.php` (editable=true). Añade una guardia automática de que la vista operativa renderiza sin errores tras la extracción (los tests de Advances existentes solo ejercitan POST → 302/405; ninguno hace GET a `legalization`). Create `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Smoke test de render de la vista operativa de legalización tras extraer los
 * parciales _linked_invoices y _soportes: GET /advances/legalization/{id}
 * responde 200 (no 500 por un error en un element). Requiere advances.can_view;
 * el anticipo lleva provider (InvoiceFactory no lo asocia por defecto).
 */
class AdvancesLegalizationRenderTest extends TestCase
{
    use IntegrationTestTrait;

    public function testOperativeViewRenders(): void
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
        ]));
        $user = UserFactory::new(['role_id' => $role->id])->save();

        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $signatures = $this->fetchTable('AdvanceLegalizationSignatures');
        $signatures->saveOrFail($signatures->newEntity([
            'legalization_id' => $leg->id,
            'file_path' => 'uploads/relacion-facturas.pdf',
            'file_name' => 'relacion-facturas.pdf',
            'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
        ]));

        $this->session(['Auth' => $user]);
        $this->get('/advances/legalization/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Facturas vinculadas');
        $this->assertResponseContains('Soportes');
    }
}
```

- [ ] **Step 6: Run the smoke test**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php`
Expected: PASS.

- [ ] **Step 7: Manual render check of the operative view**

Open an advance in Fase 2 (`/advances/legalization/{id}`) across several estados (validacion, revision_firmas, faltante con comprobante). Verify the "Soportes" card renders identically: the relación de facturas row, its Subir/Reemplazar controls when editable, the comprobante de consignación (caso faltante) and the signature history.

- [ ] **Step 8: Commit**

```bash
git add templates/element/advance_legalization/_soportes.php templates/Advances/legalization.php tests/TestCase/Controller/AdvancesLegalizationRenderTest.php
git commit -m "refactor: extrae _soportes reusable con flag editable"
```

---

### Task 5: `AdvancesController::view()` — quitar la redirección y cargar datos de legalización

Elimina el guard que redirige a `legalization()` y amplía la carga para inyectar la legalización + facturas vinculadas al ViewModel.

**Files:**
- Modify: `src/Controller/AdvancesController.php:273-303` (método `view()`)
- Test: `tests/TestCase/Controller/AdvancesViewTest.php` (crear)

**Interfaces:**
- Consumes: `AdvanceViewViewModel(Invoice, ?AdvanceLegalization, iterable)` (Task 2).

- [ ] **Step 1: Write the failing test**

Create `tests/TestCase/Controller/AdvancesViewTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use App\Test\Factory\RoleFactory;
use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * view() ya NO redirige a legalization() cuando el anticipo está en Fase 2; el
 * hub de consulta se renderiza en cualquier estado. Requiere permiso
 * advances.can_view (sembrado directo en `permissions`, patrón de
 * RefundsControllerGroupSupersessionTest). El anticipo se crea con un provider
 * porque InvoiceFactory no asocia provider/employee por defecto y el render
 * del hub accede a un beneficiario.
 */
class AdvancesViewTest extends TestCase
{
    use IntegrationTestTrait;

    private function userWithAdvancesView(): object
    {
        $role = RoleFactory::new()->save();
        $permissions = TableRegistry::getTableLocator()->get('Permissions');
        $permissions->saveOrFail($permissions->newEntity([
            'role_id' => $role->id,
            'module' => 'advances',
            'can_view' => true,
        ]));

        return UserFactory::new(['role_id' => $role->id])->save();
    }

    private function anticipo(string $status): object
    {
        $provider = ProviderFactory::new()->save();

        return InvoiceFactory::new(['provider_id' => $provider->id])
            ->anticipo()->withStatus($status)->save();
    }

    public function testViewDoesNotRedirectWhenLegalizationExists(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_PAGADA);
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertNoRedirect();
    }

    public function testNonAdvanceRedirectsToIndex(): void
    {
        $invoice = InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $invoice->id);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/advances');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php`
Expected: `testViewDoesNotRedirectWhenLegalizationExists` FAILS (`assertNoRedirect` — response is a 302 to `/advances/legalization/{id}`). `testNonAdvanceRedirectsToIndex` already PASSES (the non-Anticipo guard is untouched).

- [ ] **Step 3: Modify `view()`**

In `src/Controller/AdvancesController.php`, replace the body of `view()` (lines 273-303) with:

```php
    #[Permission(action: 'view')]
    public function view(?int $id = null): ?Response
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($id, contain: [
            'Providers',
            'Employees',
            'OperationCenters',
            'ExpenseTypes',
            'CostCenters',
            'RegisteredByUsers',
            'InvoiceDocuments' => ['UploadedByUsers'],
            'InvoicePayments' => ['BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers'],
            'AdvanceLegalization' => ['AdvanceLegalizationSignatures' => ['SignedByUsers']],
        ]);

        if ($invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
            $this->Flash->error('Esta factura no es un Anticipo.');

            return $this->redirect(['action' => 'index']);
        }

        $leg = $invoice->advance_legalization ?? null;
        $linkedInvoices = [];
        if ($leg) {
            $linkedInvoices = $invoicesTable->find()
                ->where([
                    'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                    'Invoices.advance_id' => $invoice->id,
                ])
                ->contain(['Providers', 'Employees'])
                ->orderBy(['Invoices.issue_date' => 'ASC'])
                ->all();
        }

        $this->set('viewModel', new AdvanceViewViewModel($invoice, $leg, $linkedInvoices));

        return null;
    }
```

(Note: the `if ($invoice->advance_legalization) { return $this->redirect(...); }` guard is deleted.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php`
Expected: PASS.

Note: `view.php` at this point still shows the old "iniciará al llegar a Pagada" banner even when a legalization exists — that is completed in Task 6. The test only asserts no-redirect + 200, both of which now hold.

- [ ] **Step 5: Style check**

Run: `composer cs-check`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/AdvancesController.php tests/TestCase/Controller/AdvancesViewTest.php
git commit -m "feat: view() de Anticipo deja de redirigir y carga la legalizacion"
```

---

### Task 6: Hub `templates/Advances/view.php` — desembolso + soportes + bloque de legalización

Completa el hub: añade la card de desembolso, la de soportes del anticipo, y el bloque read-only de la legalización (con los 2 parciales), ramificando por `hasLegalization`.

**Files:**
- Modify: `templates/Advances/view.php` (columna derecha)
- Test: `tests/TestCase/Controller/AdvancesViewTest.php` (añadir un caso de contenido)

**Interfaces:**
- Consumes: `$viewModel->hasLegalization`, `$viewModel->legalizationSummary` (Task 2); `element('payment_section')`, `element('documents_section')`; `element('advance_legalization/_linked_invoices')` (Task 3), `element('advance_legalization/_soportes')` (Task 4).

- [ ] **Step 1: Write the failing content test**

Append to `tests/TestCase/Controller/AdvancesViewTest.php`:

```php
    public function testViewRendersLegalizationBlockWithManageButton(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_PAGADA);
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_CONTABILIDAD)->save();

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('Gestionar legalización');
        $this->assertResponseContains('/advances/legalization/' . $anticipo->id);
    }

    public function testViewShowsBannerWhenNoLegalization(): void
    {
        $anticipo = $this->anticipo(InvoiceConstants::STATUS_APROBACION);

        $this->session(['Auth' => $this->userWithAdvancesView()]);
        $this->get('/advances/view/' . $anticipo->id);

        $this->assertResponseOk();
        $this->assertResponseContains('La legalización iniciará automáticamente');
        $this->assertResponseNotContains('Gestionar legalización');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php`
Expected: FAIL — `testViewRendersLegalizationBlockWithManageButton` fails (`Gestionar legalización` not present; template still shows the banner).

- [ ] **Step 3: Add the disbursement + documents cards**

In `templates/Advances/view.php`, add these two cards inside `<main class="spi-invoice-view-right">`, immediately after the "Beneficiario + Detalle" `.spi-card` (i.e. after line 125, before the banner at line 127). No new `use` imports are needed (la derivación de filas vive en el ViewModel — Task 2).

```php
        <!-- Desembolso al beneficiario -->
        <?php if (!empty($invoice->invoice_payments)): ?>
        <div class="spi-card">
            <?= $this->element('payment_section', [
                'payments' => $invoice->invoice_payments,
                'bankingEntities' => [],
                'addPaymentUrl' => ['action' => 'view', $invoice->id],
                'paymentStatus' => null,
                'totalAmount' => (float)$invoice->amount,
                'mode' => 'view',
                'canRegisterPayment' => false,
                'canAuthorize' => false,
                'canDelete' => false,
                'sectionTitle' => 'Desembolso al beneficiario',
                'sectionIcon' => 'bi-cash-stack',
            ]) ?>
        </div>
        <?php endif; ?>

        <!-- Soportes del anticipo -->
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $viewModel->documentRows]],
            'totalDocs'     => $viewModel->totalDocs,
            'canUpload'     => false,
            'uploadModalId' => null,
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>
```

(`documentRows`/`totalDocs` se derivan en `AdvanceViewViewModel` — Task 2 — igual que en los 5 `view.php` hermanos, p. ej. `Refunds/view.php:163-169`.)

- [ ] **Step 4: Replace the banner with the legalization branch**

In `templates/Advances/view.php`, replace the banner block (lines 127-132, the `<div class="banner info">…</div>`) with:

```php
        <!-- Legalización -->
        <?php if ($viewModel->hasLegalization):
            $sum = $viewModel->legalizationSummary;
            $leg = $viewModel->legalization;
        ?>
        <!-- Legalización: resumen (card propia; los detalles van en cards hermanas para evitar card-en-card) -->
        <div class="spi-card" style="position:relative;">
            <div class="accent-strip accent-green"></div>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-bottom:12px;">
                <span class="spi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i>Legalización
                    <span class="pill <?= h($sum->statusBadge[1]) ?>"><?= h($sum->statusBadge[0]) ?></span>
                </span>
                <?= $this->Html->link(
                    'Gestionar legalización<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>',
                    ['action' => 'legalization', $invoice->id],
                    ['class' => 'btn btn-primary btn-sm', 'escape' => false]
                ) ?>
            </div>

            <?php if ($sum->pipelineIdx >= 0): ?>
            <div class="pipeline-mini <?= h($sum->pipelineVariant) ?>" aria-hidden="true" style="margin-bottom:16px;max-width:100%;">
                <?php for ($s = 0; $s < count($sum->casePipelineSteps); $s++): ?>
                    <div class="<?= $s <= $sum->pipelineIdx ? 'on' : '' ?>"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;">
                <div>
                    <div class="spi-label">Anticipo</div>
                    <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);margin-top:2px;">
                        $ <?= number_format($sum->advanceTotal, 0, ',', '.') ?>
                    </div>
                </div>
                <div>
                    <div class="spi-label">Vinculado</div>
                    <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--text-default);margin-top:2px;">
                        $ <?= number_format($sum->linkedTotal, 0, ',', '.') ?>
                    </div>
                </div>
                <div>
                    <div class="spi-label">Diferencia</div>
                    <div style="margin-top:4px;">
                        <span class="pill <?= $sum->diffBadgeClass ?>" style="font-family:var(--font-mono);">
                            $ <?= number_format($sum->diff, 0, ',', '.') ?>
                        </span>
                        <?php if ($sum->caseLabel !== ''): ?>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:6px;">· <?= h($sum->caseLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legalización: facturas vinculadas (read-only) -->
        <?= $this->element('advance_legalization/_linked_invoices', [
            'leg' => $leg,
            'invoice' => $invoice,
            'linkedInvoices' => $viewModel->linkedInvoices,
            'linkedTotal' => $sum->linkedTotal,
            'linkedCount' => $sum->linkedCount,
            'editable' => false,
        ]) ?>

        <!-- Legalización: soportes (read-only) -->
        <?= $this->element('advance_legalization/_soportes', [
            'leg' => $leg,
            'relationDocument' => $sum->relationDocument,
            'signatureHistory' => $sum->signatureHistory,
            'editable' => false,
        ]) ?>
        <?php else: ?>
        <div class="banner info">
            <div class="banner-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></div>
            <div class="banner-body">
                <div class="banner-msg">La legalización iniciará automáticamente cuando este anticipo llegue al estado <strong>Pagada</strong>.</div>
            </div>
        </div>
        <?php endif; ?>
```

- [ ] **Step 5: Lint + run the content tests**

Run: `php -l templates/Advances/view.php && vendor/bin/phpunit tests/TestCase/Controller/AdvancesViewTest.php`
Expected: `No syntax errors detected`; all 4 tests PASS.

- [ ] **Step 6: Style check**

Run: `composer cs-check`
Expected: no errors.

- [ ] **Step 7: Manual render check of the hub**

Open `/advances/view/{id}` for: (a) an advance in Fase 2 with linked invoices and a relación de facturas — verify datos + desembolso + soportes + the read-only legalization block (totals, linked list without unlink icons, soportes without upload controls, and the "Gestionar legalización →" button leading to the operative view); (b) an advance not yet paid — verify the banner shows and there is no legalization block; (c) confirm the breadcrumb from the operative view ("Ver Anticipo") now lands on the hub instead of looping.

- [ ] **Step 8: Commit**

```bash
git add templates/Advances/view.php tests/TestCase/Controller/AdvancesViewTest.php
git commit -m "feat: hub de consulta de Anticipo con desembolso, soportes y bloque de legalizacion"
```

---

### Task 7: Verificación de regresión completa

- [ ] **Step 1: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: sin regresiones respecto al baseline vigente (los nuevos tests pasan; los existentes de `AdvanceLegalizationViewModelApprovalTest`, `AdvancePresentationTest`, etc. siguen verdes).

- [ ] **Step 2: Full style check**

Run: `composer cs-check`
Expected: limpio.

- [ ] **Step 3: Commit (si cs-fix cambió algo)**

```bash
git add -A
git commit -m "chore: cs-fix tras hub de consulta de Anticipo"
```

(Si no hubo cambios, omitir.)

---

## Self-Review

**Spec coverage:**
- §4.1 (quitar redirect + cargar datos, no cargar surplusPayment) → Task 5. ✓
- §4.2 card 1 (datos) → ya existe (sin cambio). Card 2 (desembolso, payment_section mode view, addPaymentUrl inocuo) → Task 6 Step 3. Card 3 (soportes vía documents_section/document_row con `documentRows`/`totalDocs` derivados en el VM — Task 2, badges desde Presentation) → Task 6 Step 3. Card 4 (resumen con estado + **mini-pipeline del caso** + totales/diff/caso, en card propia; _linked_invoices y _soportes como cards hermanas read-only; botón "Gestionar legalización") → Task 6 Step 4. Banner condicional → Task 6 Step 4. ✓
- §4.3 (extraer _linked_invoices y _soportes con flag editable; refactor legalization.php; payment_section ya compartido) → Tasks 3, 4. ✓
- §4.4 (helper LegalizationSummary; AdvanceViewViewModel ampliado; AdvanceLegalizationViewModel delega mínimo; anti-drift) → Tasks 1, 2. ✓
- §4.5 (RBAC sin cambios; view read-only bajo Permission view) → Task 5 conserva `#[Permission(action: 'view')]`; el hub no expone mutación. ✓
- §6 criterios de aceptación → cubiertos por los tests de Tasks 5-6 y las verificaciones manuales. ✓
- §7 testing → controller no-redirige (Task 5) + banner sin leg (Task 6) + **no-Anticipo redirige** (Task 5, `testNonAdvanceRedirectsToIndex`); VM unit (`LegalizationSummaryTest` Task 1 + `AdvanceViewViewModelTest` Task 2); **regresión de la vista operativa** cubierta por el smoke test `AdvancesLegalizationRenderTest` (Task 4 Step 5) además del check manual (Tasks 3-4). ✓

**Placeholder scan:** sin TBD/TODO. El markup grande de los parciales (Tasks 3-4) se instruye por copia verbatim de rangos exactos de `legalization.php` + transformaciones enumeradas con los snippets precisos a cambiar (no es un placeholder: el código fuente existe en el repo y las modificaciones están dadas literalmente).

**Type consistency:** `LegalizationSummary` (Task 1) expone `linkedTotal/diff/diffBadgeClass/linkedCount/relationDocument/signatureHistory/statusBadge/casePipelineSteps/caseLabel` + `const CASE_LABELS`; consumido idénticamente por `AdvanceLegalizationViewModel::build()` (Task 1), `AdvanceViewViewModel` (Task 2) y `view.php` (Task 6, vía `$viewModel->legalizationSummary`). `AdvanceViewViewModel` constructor `(Invoice, ?AdvanceLegalization, iterable)` usado por el controller (Task 5) y los tests (Task 2). Params de los elements `_linked_invoices`/`_soportes` idénticos entre creación (Tasks 3-4) y consumo en `view.php` (Task 6) y `legalization.php` (Tasks 3-4). ✓
