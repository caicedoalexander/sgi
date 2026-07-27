# Vincular "Recibo de Caja" a la legalización de anticipos — Fase 1 · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir vincular facturas `document_type = 'Recibo de Caja'` a la legalización de un anticipo, tratándolas igual que las `Legalización`, y congelar su pipeline al vincularse para evitar doble pago.

**Architecture:** Se amplía a `IN (Legalización, Recibo de Caja)` el conjunto de tipos vinculables en las 5 queries del ciclo de legalización; la selección/escritura del vínculo restringe el RC a `Contabilidad` (forma `OR`); y una nueva `ReciboCajaDocumentTypePolicy` congela el avance del RC mientras tenga `advance_id`. No se toca la promoción al cierre (`LinkedInvoiceLegalizer`) ni la capa de presentación de pipeline (diferido a Fase 2).

**Tech Stack:** PHP 8.4, CakePHP 5.3, PHPUnit (cakephp-fixture-factories), phpcs (CakePHP coding standard).

**Spec:** `docs/superpowers/specs/2026-06-30-vincular-recibo-caja-legalizacion-design.md`

## Global Constraints

- **Constante fuente única:** el conjunto de tipos vinculables vive en `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES`; nunca arrays literales `['Legalización', 'Recibo de Caja']` inline.
- **Slugs persistidos inmutables:** no tocar los valores `'Legalización'` / `'Recibo de Caja'` ni los slugs `'advances'`/`'legalizations'`.
- **Patrón State/Policy:** el freeze se implementa como `DocumentTypePolicy`, no como un `if` suelto en el coordinador.
- **Anti-drift de vista:** no se introducen mapeos estado→pill ni se toca `InvoicePresentation`.
- **API pública preservada:** `getNextStatus` solo gana un parámetro opcional retrocompatible.
- **Estilo:** correr `composer cs-fix` antes de cada commit; la suite debe quedar verde (`composer test`).
- **Commits:** mensajes en formato conventional (`feat:`/`test:`/`refactor:`), sin atribución (deshabilitada globalmente).
- **Estado de freeze:** el RC se congela en `Contabilidad` cuando `advance_id != null` (simétrico con `Legalización`).

---

### Task 1: Freeze del Recibo de Caja vinculado (policy + factory + getNextStatus)

Crea la `ReciboCajaDocumentTypePolicy`, la registra, y hace `getNextStatus` consciente de `advance_id`. Resultado: un RC con `advance_id` en `Contabilidad` no puede avanzar; uno sin `advance_id` avanza normal.

**Files:**
- Create: `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php`
- Create: `tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php`
- Modify: `src/Service/Pipeline/Invoice/DocumentTypePolicyFactory.php`
- Modify: `src/Application.php:310-320`
- Modify: `src/Service/InvoicePipelineService.php:124-165` (getNextStatus + caller `:126`), `:243`
- Modify: `src/Controller/InvoicesController.php:377`
- Modify: `tests/TestCase/Service/Pipeline/Invoice/DocumentTypePolicyFactoryTest.php`
- Modify: `tests/TestCase/Service/InvoicePipelineServiceTest.php` (buildService + nuevos tests)

**Interfaces:**
- Consumes: `DocumentTypePolicy` interface, `InvoicePipelineState::getStatus(): PipelineStatus`, `InvoiceConstants::DOCTYPE_RECIBO_CAJA`, `PipelineStatus::CONTABILIDAD`.
- Produces: `ReciboCajaDocumentTypePolicy` (mapeada en el factory para `DOCTYPE_RECIBO_CAJA`); `InvoicePipelineService::getNextStatus(string $currentStatus, ?string $documentType = null, ?int $advanceId = null): ?string`.

- [ ] **Step 1: Escribir el test unit de la policy (falla)**

Create `tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\InvoicePipelineState;
use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;
use PHPUnit\Framework\TestCase;

final class ReciboCajaDocumentTypePolicyTest extends TestCase
{
    private function stateFor(PipelineStatus $status): InvoicePipelineState
    {
        $state = $this->createMock(InvoicePipelineState::class);
        $state->method('getStatus')->willReturn($status);

        return $state;
    }

    public function testGetDocumentTypeIsReciboCaja(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $this->assertSame(InvoiceConstants::DOCTYPE_RECIBO_CAJA, $policy->getDocumentType());
    }

    public function testBlocksAdvanceWhenLinkedInContabilidad(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertNotNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::CONTABILIDAD), $invoice),
        );
    }

    public function testDoesNotBlockWhenNotLinked(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => null];

        $this->assertNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::CONTABILIDAD), $invoice),
        );
    }

    public function testDoesNotBlockOutsideContabilidad(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $invoice = (object)['advance_id' => 123];

        $this->assertNull(
            $policy->blocksAdvance($this->stateFor(PipelineStatus::TESORERIA), $invoice),
        );
    }

    public function testUsesFullPipelineForView(): void
    {
        $policy = new ReciboCajaDocumentTypePolicy();
        $this->assertSame(InvoiceConstants::PIPELINE_STATUSES, $policy->getPipelineStatusesForView());
    }
}
```

- [ ] **Step 2: Correr el test — debe fallar**

Run: `vendor/bin/phpunit --filter ReciboCajaDocumentTypePolicyTest`
Expected: FAIL — `Class "App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy" not found`.

- [ ] **Step 3: Crear la policy**

Create `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Policy;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicy;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

/**
 * Reglas del Recibo de Caja:
 *  - cuando está vinculado a una legalización (advance_id != null) queda parqueado
 *    en `contabilidad` (no avanza manualmente): es justificación de un gasto ya
 *    cubierto por el anticipo, no se paga por su cuenta. Espejo del freeze de
 *    Legalización, pero condicionado a advance_id (un RC sin vincular usa el
 *    pipeline normal de 6 pasos).
 */
final class ReciboCajaDocumentTypePolicy implements DocumentTypePolicy
{
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_RECIBO_CAJA;
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        if (($invoice->advance_id ?? null) !== null
            && $state->getStatus() === PipelineStatus::CONTABILIDAD) {
            return 'Este Recibo de Caja está vinculado a una legalización; avanzará junto con ella.';
        }

        return null;
    }

    public function getPipelineStatusesForView(): array
    {
        return InvoiceConstants::PIPELINE_STATUSES;
    }

    public function filterVisibleSections(array $sections): array
    {
        return $sections;
    }

    public function triggersAutoLegalization(PipelineStatus $newStatus): bool
    {
        return false;
    }

    public function getRegressionLockReason(object $invoice): ?string
    {
        return null;
    }

    public function allowsRefundPayments(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Correr el test — debe pasar**

Run: `vendor/bin/phpunit --filter ReciboCajaDocumentTypePolicyTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Registrar la policy en el factory (4.º param nullable con fallback)**

Modify `src/Service/Pipeline/Invoice/DocumentTypePolicyFactory.php` — añadir el `use`, el parámetro del constructor y la entrada del mapa. **El 4.º parámetro es nullable con fallback `?? new`** (convención de DI de SPI): así los call-sites existentes que construyen el factory con 3 argumentos (5 en total, ver Step 13) **no se rompen** — reciben la policy por el fallback.

```php
use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;
```
```php
    public function __construct(
        private readonly StandardDocumentTypePolicy $standard,
        AnticipoDocumentTypePolicy $anticipo,
        LegalizacionDocumentTypePolicy $legalizacion,
        ?ReciboCajaDocumentTypePolicy $reciboCaja = null,
    ) {
        $this->byType = [
            InvoiceConstants::DOCTYPE_ANTICIPO     => $anticipo,
            InvoiceConstants::DOCTYPE_LEGALIZACION => $legalizacion,
            InvoiceConstants::DOCTYPE_RECIBO_CAJA  => $reciboCaja ?? new ReciboCajaDocumentTypePolicy(),
        ];
    }
```

- [ ] **Step 6: Registrar la policy en el contenedor DI**

Modify `src/Application.php` (bloque de policies, `:310-320`):

```php
        // === Document type policies ===
        $container->addShared(StandardDocumentTypePolicy::class);
        $container->addShared(AnticipoDocumentTypePolicy::class)
            ->addArgument(AdvanceLegalizationService::class);
        $container->addShared(LegalizacionDocumentTypePolicy::class);
        $container->addShared(ReciboCajaDocumentTypePolicy::class);
        $container->addShared(DocumentTypePolicyFactory::class)
            ->addArguments([
                StandardDocumentTypePolicy::class,
                AnticipoDocumentTypePolicy::class,
                LegalizacionDocumentTypePolicy::class,
                ReciboCajaDocumentTypePolicy::class,
            ]);
```

Añadir el `use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;` al inicio de `Application.php` (junto a los demás `use` de policies).

- [ ] **Step 7: Actualizar el test del factory (RC ya no cae a Standard)**

Modify `tests/TestCase/Service/Pipeline/Invoice/DocumentTypePolicyFactoryTest.php`. El `setUp` **no se toca**: construye el factory con 3 argumentos y el fallback `?? new` del Step 5 crea la `ReciboCajaDocumentTypePolicy` internamente. Solo:
- Añadir `use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;`.
- Eliminar `InvoiceConstants::DOCTYPE_RECIBO_CAJA,` de la lista `$standardDoctypes` en `testEachDoctypeWithoutSpecialPolicyFallsBackToStandard` (ya no cae a Standard).
- Añadir el test:
```php
    public function testReciboCajaReturnsReciboCajaPolicy(): void
    {
        $policy = $this->factory->for(InvoiceConstants::DOCTYPE_RECIBO_CAJA);
        $this->assertInstanceOf(ReciboCajaDocumentTypePolicy::class, $policy);
    }
```

- [ ] **Step 8: Escribir los tests del freeze en getNextStatus (fallan)**

Modify `tests/TestCase/Service/InvoicePipelineServiceTest.php`. El `buildService()` **no se toca**: construye el factory con 3 argumentos y el fallback `?? new` (Step 5) provee la `ReciboCajaDocumentTypePolicy`. No hace falta `use` extra (no se instancia la policy en el test). Solo añadir, junto a los demás tests de `getNextStatus`:
```php
    public function testGetNextStatusBlockedForReciboCajaVinculadoEnContabilidad(): void
    {
        $service = $this->buildService();

        // RC vinculado (advance_id != null) en contabilidad: congelado.
        $this->assertNull(
            $service->getNextStatus(
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                123,
            ),
        );
    }

    public function testGetNextStatusAllowsReciboCajaSinVincular(): void
    {
        $service = $this->buildService();

        // RC sin vincular: avanza por el pipeline normal.
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $service->getNextStatus(
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                null,
            ),
        );
    }
```

- [ ] **Step 9: Correr los tests nuevos — deben fallar**

Run: `vendor/bin/phpunit --filter testGetNextStatusBlockedForReciboCajaVinculadoEnContabilidad`
Expected: FAIL — `getNextStatus` aún no acepta el 3.º argumento ni consulta `advance_id` (devuelve `tesoreria`, no `null`).

- [ ] **Step 10: Hacer getNextStatus consciente de advance_id**

Modify `src/Service/InvoicePipelineService.php`:

`getNextStatus` (`:147`):
```php
    public function getNextStatus(string $currentStatus, ?string $documentType = null, ?int $advanceId = null): ?string
    {
        $currentEnum = PipelineStatus::tryFrom($currentStatus);
        if ($currentEnum === null) {
            return null;
        }

        $state = $this->states->get($currentEnum);
        $policy = $this->docTypePolicies->for($documentType);

        // Cuando la policy bloquea el avance del estado, el next efectivo es null.
        // El stub lleva advance_id para que el freeze del Recibo de Caja vinculado
        // (ReciboCajaDocumentTypePolicy::blocksAdvance) pueda evaluarlo.
        $stub = (object)[
            'document_type' => $documentType,
            'pipeline_status' => $currentStatus,
            'advance_id' => $advanceId,
        ];
        if ($policy->blocksAdvance($state, $stub) !== null) {
            return null;
        }

        return $state->getNextStatus()?->value;
    }
```

Caller en `denialReasonForAdvance` (`:126`):
```php
        if ($this->getNextStatus($invoice->pipeline_status, $invoice->document_type, $invoice->advance_id) === null) {
            return DenialReason::TERMINAL_STATE;
        }
```

Caller en `saveAndAdvance` (`:243`):
```php
                $advanceNextStatus = $this->getNextStatus($currentStatus, $invoice->document_type, $invoice->advance_id);
```

- [ ] **Step 11: Actualizar el 3.º caller (controller)**

Modify `src/Controller/InvoicesController.php:377`:
```php
                $nextStatus = $this->pipeline->getNextStatus($currentStatus, $invoice->document_type, $invoice->advance_id);
```

- [ ] **Step 12: Verificar que ningún call-site del factory quedó roto**

El 4.º parámetro es nullable con fallback (Step 5), así que los call-sites de 3 argumentos siguen válidos. Confirmarlo corriendo TODAS las suites que construyen el factory (las 5 conocidas):

Run: `grep -rn "new DocumentTypePolicyFactory(" src tests`
Esperado: 5 call-sites — `Application.php` (4 args, explícito), `InvoicePipelineServiceTest`, `DocumentTypePolicyFactoryTest`, `InvoicePaymentServiceTest`, `PaymentServiceIntegrationTrait`, `InvoiceTransitionValidatorTest` (estos con 3 args + fallback). Ninguno requiere edición.

- [ ] **Step 13: Correr la suite afectada (incluye los consumidores del factory)**

Run: `vendor/bin/phpunit --filter "ReciboCajaDocumentTypePolicyTest|InvoicePipelineServiceTest|DocumentTypePolicyFactoryTest|InvoicePaymentServiceTest|InvoiceTransitionValidatorTest"`
Expected: PASS (incluye los 2 tests nuevos de getNextStatus, el del factory, y confirma que los 3 consumidores no listados originalmente siguen verdes por el fallback).

- [ ] **Step 14: Estilo + commit**

Run: `composer cs-fix`
Then:
```bash
git add src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php \
        src/Service/Pipeline/Invoice/DocumentTypePolicyFactory.php \
        src/Application.php src/Service/InvoicePipelineService.php \
        src/Controller/InvoicesController.php \
        tests/TestCase/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicyTest.php \
        tests/TestCase/Service/Pipeline/Invoice/DocumentTypePolicyFactoryTest.php \
        tests/TestCase/Service/InvoicePipelineServiceTest.php
git commit -m "feat: congelar avance del Recibo de Caja vinculado a legalización"
```

---

### Task 2: Constante + vinculación estado-restringida (candidatos y escritura)

Amplía el conjunto de tipos vinculables y restringe el RC a `Contabilidad` tanto al ofrecer candidatos como al escribir el vínculo. Resultado: un RC en Contabilidad se ofrece y se vincula; uno fuera de Contabilidad no se ofrece ni se vincula (aunque su id se inyecte en el POST).

**Files:**
- Modify: `src/Constants/InvoiceConstants.php` (nueva constante, tras `:31` — cierre de `DOCUMENT_TYPES`)
- Modify: `src/Controller/AdvancesController.php:414-417` (`linkCandidates`)
- Modify: `src/Service/AdvanceLegalizationService.php:104-111` (`linkInvoices` `updateAll`)
- Modify: `tests/Factory/InvoiceFactory.php` (helper `reciboDeCaja()`)
- Modify: `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php` (tests de vinculación)

**Interfaces:**
- Consumes: `InvoiceConstants::DOCTYPE_LEGALIZACION`, `DOCTYPE_RECIBO_CAJA`, `STATUS_CONTABILIDAD`; `AdvanceLegalizationService::linkInvoices(AdvanceLegalization $leg, array $invoiceIds, int $userId): ServiceResult`.
- Produces: `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES` (array<string>); `InvoiceFactory::reciboDeCaja(): static`.

- [ ] **Step 1: Añadir la constante**

Modify `src/Constants/InvoiceConstants.php`, justo después de `DOCUMENT_TYPES` (tras `:31`):

```php
    /**
     * Tipos de documento vinculables a la legalización de un anticipo.
     * Fuente única — consumida por las queries del ciclo de vinculación y por
     * AdvanceLegalizationService/Guard. Ver spec 2026-06-30 (Fase 1).
     */
    public const ADVANCE_LINKABLE_DOCTYPES = [
        self::DOCTYPE_LEGALIZACION,
        self::DOCTYPE_RECIBO_CAJA,
    ];
```

- [ ] **Step 2: Añadir el helper de factory para Recibo de Caja**

Modify `tests/Factory/InvoiceFactory.php`, junto a `legalizacion()`:

```php
    public function reciboDeCaja(): static
    {
        return $this->setField('document_type', InvoiceConstants::DOCTYPE_RECIBO_CAJA);
    }
```

- [ ] **Step 3: Escribir el test de escritura del vínculo (falla)**

Modify `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php`. Usar el arnés REAL del archivo: `buildService()` (no `$this->service`), `UserFactory::new()->save()`, `$this->fetchTable('Invoices')` y el armado anticipo+legalización de los tests vecinos (`:142-167`). `InvoiceFactory::new()` (no `::make()`) auto-crea los parents NOT NULL.

```php
    public function testLinkInvoicesAcceptsReciboDeCajaInContabilidad(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();
        $rc = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $result = $this->buildService()->linkInvoices($leg, [$rc->id], $user->id);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['linked']);
        $this->assertSame($anticipo->id, $this->fetchTable('Invoices')->get($rc->id)->advance_id);
    }

    public function testLinkInvoicesRejectsReciboDeCajaOutsideContabilidad(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();
        $user = UserFactory::new()->save();
        // RC fuera de Contabilidad: su id se inyecta directo (form rancio / 2.ª pestaña).
        $rc = InvoiceFactory::new()->reciboDeCaja()
            ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

        $result = $this->buildService()->linkInvoices($leg, [$rc->id], $user->id);

        // No hace match en el updateAll → ok con 0 vinculadas, advance_id intacto.
        $this->assertSame(0, $result->data['linked'] ?? null);
        $this->assertNull($this->fetchTable('Invoices')->get($rc->id)->advance_id);
    }
```

- [ ] **Step 4: Correr — `accepts` debe fallar (RED)**

Run: `vendor/bin/phpunit --filter "testLinkInvoicesAcceptsReciboDeCajaInContabilidad|testLinkInvoicesRejectsReciboDeCajaOutsideContabilidad"`
Expected:
- `testLinkInvoicesAcceptsReciboDeCajaInContabilidad`: **FAIL** — hoy `linkInvoices` filtra `document_type = 'Legalización'`; el RC no hace match → `linked = 0` (esperaba `1`), `advance_id` nulo.
- `testLinkInvoicesRejectsReciboDeCajaOutsideContabilidad`: PASS desde ya (hoy ningún RC se vincula). Es un **guard de seguridad** que debe SEGUIR pasando tras el fix; fallaría si el Step 5 omitiera la restricción `pipeline_status = Contabilidad` (precisamente el bug de doble pago que cierra el spec §4.4).

- [ ] **Step 5: Restringir la escritura del vínculo (linkInvoices)**

Modify `src/Service/AdvanceLegalizationService.php` (`:104-111`), reemplazar la condición `document_type` del `updateAll` por el `OR` estado-restringido:

```php
                $count = $invoices->updateAll(
                    ['advance_id' => $leg->advance_invoice_id],
                    [
                        'id IN' => $invoiceIds,
                        'advance_id IS' => null,
                        'OR' => [
                            ['document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION],
                            [
                                'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                            ],
                        ],
                    ],
                );
```

(El bloque posterior que recolecta `$linkedNumbers` filtra por `advance_id` y no requiere cambios.)

- [ ] **Step 6: Correr — deben pasar**

Run: `vendor/bin/phpunit --filter "testLinkInvoicesAcceptsReciboDeCajaInContabilidad|testLinkInvoicesRejectsReciboDeCajaOutsideContabilidad"`
Expected: PASS.

- [ ] **Step 7: Restringir el listado de candidatos (linkCandidates)**

Modify `src/Controller/AdvancesController.php` (`:414-417`), reemplazar la condición `document_type` por el `OR`:

```php
        $conditions = [
            'Invoices.advance_id IS' => null,
            'OR' => [
                ['Invoices.document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION],
                [
                    'Invoices.document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
                    'Invoices.pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
                ],
            ],
        ];
```

(Los filtros opcionales `operation_center_id`/`date_from`/`date_to`/`provider_id` que siguen se mantienen idénticos; se AND-ean con el `OR`.)

- [ ] **Step 8: Correr la suite de Advances + lifecycle**

Run: `vendor/bin/phpunit --filter "AdvanceLegalizationLifecycleTest|AdvancesController"`
Expected: PASS.

- [ ] **Step 9: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Constants/InvoiceConstants.php src/Controller/AdvancesController.php \
        src/Service/AdvanceLegalizationService.php \
        tests/Factory/InvoiceFactory.php \
        tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php
git commit -m "feat: vincular Recibo de Caja en Contabilidad a la legalización"
```

---

### Task 3: El Recibo de Caja vinculado cuenta (validación, total y lista)

Amplía a `IN` las 3 queries que leen facturas ya vinculadas. Resultado: el RC vinculado entra en la validación "todas en Contabilidad", suma al total/diferencia y aparece en la lista de vinculadas.

**Files:**
- Modify: `src/Service/AdvanceLegalizationGuard.php:26-36` (`linkedLegalizationInvoices`)
- Modify: `src/Service/AdvanceLegalizationService.php:346-357` (`getLinkedTotal`)
- Modify: `src/Controller/AdvancesController.php:335-342` (`legalization`, query `$linkedInvoices`)
- Modify: `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php` (tests de guard + total)

**Interfaces:**
- Consumes: `InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES` (de Task 2); `AdvanceLegalizationGuard::linkedLegalizationInvoices(int $advanceInvoiceId): array`; `AdvanceLegalizationService::getLinkedTotal(AdvanceLegalization $leg): float`.
- Produces: (sin nuevas firmas — solo amplía la semántica de las 3 lecturas a "Legalización + Recibo de Caja").

- [ ] **Step 1: Escribir los tests de guard + total (fallan)**

Modify `tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php`. Añadir `use App\Service\AdvanceLegalizationGuard;` al bloque de `use`. Test con el arnés real (pre-vincular el RC vía `InvoiceFactory::new(['advance_id' => ...])`, como hace `testGetLinkedTotalAndDifference` en `:231-234`; aserción de floats con `assertEqualsWithDelta` como en `:238`):

```php
    public function testGuardAndTotalIncludeLinkedReciboDeCaja(): void
    {
        $anticipo = InvoiceFactory::new()->anticipo()
            ->withAmount(1000.0)->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        $leg = AdvanceLegalizationFactory::new()->forAdvance($anticipo)
            ->withStatus(AdvanceConstants::STATUS_VALIDACION)->save();

        // RC ya vinculado (advance_id = anticipo) en Contabilidad.
        InvoiceFactory::new(['advance_id' => $anticipo->id])->reciboDeCaja()
            ->withAmount(500.0)->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        // Guard: lo cuenta como factura vinculada.
        $linked = (new AdvanceLegalizationGuard())
            ->linkedLegalizationInvoices((int)$anticipo->id);
        $this->assertCount(1, $linked);

        // Total: su monto suma.
        $this->assertEqualsWithDelta(500.0, $this->buildService()->getLinkedTotal($leg), 0.001);
    }
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter testGuardAndTotalIncludeLinkedReciboDeCaja`
Expected: FAIL — guard y total aún filtran solo `Legalización` (`assertCount(1)` falla con 0; `getLinkedTotal` da `0.0`).

- [ ] **Step 3: Ampliar el guard a IN**

Modify `src/Service/AdvanceLegalizationGuard.php` (`:30-33`):
```php
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
            ])
```

- [ ] **Step 4: Ampliar getLinkedTotal a IN**

Modify `src/Service/AdvanceLegalizationService.php` (`:351-354`):
```php
            ->where([
                'advance_id' => $leg->advance_invoice_id,
                'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
            ])
```

- [ ] **Step 5: Ampliar la lista de vinculadas de la vista a IN**

Modify `src/Controller/AdvancesController.php` (`:335-342`):
```php
        $linkedInvoices = $invoicesTable->find()
            ->where([
                'Invoices.document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
                'Invoices.advance_id' => $invoice->id,
            ])
            ->contain(['Providers', 'Employees'])
            ->orderBy(['Invoices.issue_date' => 'ASC'])
            ->all();
```

- [ ] **Step 6: Correr — debe pasar**

Run: `vendor/bin/phpunit --filter testGuardAndTotalIncludeLinkedReciboDeCaja`
Expected: PASS.

- [ ] **Step 7: Aclarar el comentario MA-006 (deriva de semántica)**

`getLinkedTotal`, `linkedLegalizationInvoices` y la query de la vista ahora abarcan también Recibo de Caja. Aclarar el comentario `MA-006` en `src/Service/Pipeline/Advance/State/ValidacionState.php:48-49`, que hoy solo nombra la promoción de Legalización:

```php
        // MA-006 — toda factura vinculada debe estar en CONTABILIDAD. Las
        // Legalización las promueve LinkedInvoiceLegalizer al cierre; los Recibo de
        // Caja vinculados quedan congelados en CONTABILIDAD (Fase 1: no se promueven).
        foreach ($linked as $li) {
```

(Sin cambio de comportamiento — solo el comentario.)

- [ ] **Step 8: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/Service/AdvanceLegalizationGuard.php src/Service/AdvanceLegalizationService.php \
        src/Controller/AdvancesController.php \
        src/Service/Pipeline/Advance/State/ValidacionState.php \
        tests/TestCase/Service/Integration/AdvanceLegalizationLifecycleTest.php
git commit -m "feat: contar Recibo de Caja vinculado en validación y total de legalización"
```

---

### Task 4: Display del beneficiario (RC manual/empleado) + copy del modal

Un Recibo de Caja puede no tener proveedor (titular `employee` o `manual`). Centraliza la resolución del beneficiario en un helper y lo aplica en la lista de vinculadas y el modal de candidatos. Actualiza el copy del modal.

**Files:**
- Create: `src/View/Presentation/InvoiceBeneficiary.php`
- Create: `tests/TestCase/View/Presentation/InvoiceBeneficiaryTest.php`
- Modify: `templates/Advances/legalization.php:201-203`
- Modify: `templates/element/link_invoices_modal.php:109`
- Modify: `templates/Advances/link_candidates.php:25`

**Interfaces:**
- Consumes: `InvoiceConstants::DOCTYPE_RECIBO_CAJA`; campos de `Invoice`: `document_type`, `equivalent_holder_type`, `manual_document_number`, asociaciones `employee`/`provider`.
- Produces: `InvoiceBeneficiary::label(object $invoice): string`.

- [ ] **Step 1: Escribir el test del helper (falla)**

Create `tests/TestCase/View/Presentation/InvoiceBeneficiaryTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Employee;
use App\Model\Entity\Invoice;
use App\Model\Entity\Provider;
use App\View\Presentation\InvoiceBeneficiary;
use PHPUnit\Framework\TestCase;

final class InvoiceBeneficiaryTest extends TestCase
{
    private function invoice(array $fields): Invoice
    {
        // guard=false: los micro-tests setean campos/asociaciones sin lidiar con accessibility.
        return new Invoice($fields, ['guard' => false]);
    }

    public function testProviderNameForRegularInvoice(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'provider' => new Provider(['name' => 'ACME'], ['guard' => false]),
        ]);

        $this->assertSame('ACME', InvoiceBeneficiary::label($invoice));
    }

    public function testEmployeeFallbackForRegularInvoiceWithoutProvider(): void
    {
        // No-RC sin proveedor pero con empleado: conserva el fallback del element
        // genérico compartido (Refunds/PettyCash). full_name es virtual → first_name/last_name1.
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'employee' => new Employee(['first_name' => 'Ana', 'last_name1' => 'Pérez'], ['guard' => false]),
        ]);

        $this->assertSame('Ana Pérez', InvoiceBeneficiary::label($invoice));
    }

    public function testManualHolderForReciboDeCaja(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'equivalent_holder_type' => InvoiceConstants::HOLDER_TYPE_MANUAL,
            'manual_document_number' => 'CC-123',
        ]);

        $this->assertSame('CC-123', InvoiceBeneficiary::label($invoice));
    }

    public function testEmployeeHolderForReciboDeCaja(): void
    {
        $invoice = $this->invoice([
            'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
            'equivalent_holder_type' => InvoiceConstants::HOLDER_TYPE_EMPLOYEE,
            'employee' => new Employee(['first_name' => 'Ana', 'last_name1' => 'Pérez'], ['guard' => false]),
        ]);

        $this->assertSame('Ana Pérez', InvoiceBeneficiary::label($invoice));
    }

    public function testDashWhenNothingResolves(): void
    {
        $invoice = $this->invoice(['document_type' => InvoiceConstants::DOCTYPE_FACTURA]);
        $this->assertSame('—', InvoiceBeneficiary::label($invoice));
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `vendor/bin/phpunit --filter InvoiceBeneficiaryTest`
Expected: FAIL — `Class "App\View\Presentation\InvoiceBeneficiary" not found`.

- [ ] **Step 3: Crear el helper**

Create `src/View/Presentation/InvoiceBeneficiary.php` (extrae el patrón de `InvoiceViewViewModel:93-100`; NO toca `InvoicePresentation`):

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;

/**
 * Resuelve la etiqueta del beneficiario de una factura para listados.
 * Un Recibo de Caja puede no tener proveedor: su titular puede ser un empleado
 * (equivalent_holder_type='employee') o manual (manual_document_number).
 * Espejo de la lógica de InvoiceViewViewModel:93-100 para no duplicarla en templates.
 * La rama no-RC conserva el fallback provider→employee→'—' del element genérico
 * compartido (link_invoices_modal, usado por Refunds/PettyCash): no regresiona su display.
 */
final class InvoiceBeneficiary
{
    public static function label(object $invoice): string
    {
        $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;

        if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === InvoiceConstants::HOLDER_TYPE_EMPLOYEE) {
            return $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
        }
        if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === InvoiceConstants::HOLDER_TYPE_MANUAL) {
            return $invoice->manual_document_number ?? '—';
        }
        if ($invoice->hasValue('provider')) {
            return $invoice->provider->name;
        }

        return $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
    }
}
```

- [ ] **Step 4: Correr — debe pasar**

Run: `vendor/bin/phpunit --filter InvoiceBeneficiaryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Usar el helper en la lista de vinculadas**

Modify `templates/Advances/legalization.php`. Añadir al bloque de `use` del inicio del template `use App\View\Presentation\InvoiceBeneficiary;` (junto a `InvoicePresentation`), y reemplazar `:201-203`:

```php
                    <span style="font-size:12px;color:var(--text-default);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= h(InvoiceBeneficiary::label($li)) ?>
                    </span>
```

- [ ] **Step 6: Usar el helper en el modal de candidatos**

Modify `templates/element/link_invoices_modal.php`. Añadir `use App\View\Presentation\InvoiceBeneficiary;` al bloque PHP inicial (tras la docblock, antes de los defaults), y reemplazar `:109`:

```php
                                <td><?= h(InvoiceBeneficiary::label($c)) ?></td>
```

> El element es genérico (Refunds/PettyCash). El cambio es retrocompatible: para un candidato que no es Recibo de Caja, `label()` devuelve `provider->name ?? '—'`, igual que antes.

- [ ] **Step 7: Actualizar el copy del modal**

Modify `templates/Advances/link_candidates.php:25`:
```php
    'helpText' => 'Facturas tipo "Legalización", o "Recibo de Caja" en Contabilidad, sin anticipo asignado.',
```

- [ ] **Step 8: Verificar render del template (sin fatal)**

Run: `vendor/bin/phpunit --filter "AdvanceLegalizationLifecycleTest|InvoiceBeneficiaryTest"`
Expected: PASS. (Si el repo tiene un test de render de `Advances/legalization`/`linkCandidates`, correrlo también.)

- [ ] **Step 9: Estilo + commit**

Run: `composer cs-fix`
```bash
git add src/View/Presentation/InvoiceBeneficiary.php \
        tests/TestCase/View/Presentation/InvoiceBeneficiaryTest.php \
        templates/Advances/legalization.php templates/element/link_invoices_modal.php \
        templates/Advances/link_candidates.php
git commit -m "feat: mostrar beneficiario de Recibo de Caja en vinculación de legalización"
```

---

### Task 5: Verificación final de la suite

- [ ] **Step 1: Correr la suite completa**

Run: `composer test`
Expected: verde, sin regresiones (baseline previa + los tests nuevos de Tasks 1–4). Si aparecen fallos en cascada, re-correr limpio antes de concluir (contaminación conocida entre suites consecutivas).

- [ ] **Step 2: Estilo global**

Run: `composer cs-check`
Expected: sin violaciones.

---

## Self-Review

**Spec coverage:**
- §4.1 Constante → Task 2 Step 1. ✓
- §4.2 puntos 3–5 (`IN` plano) → Task 3. ✓
- §4.2 puntos 1–2 / §4.4 (`OR` estado-restringido en candidatos **y** escritura) → Task 2 Steps 5, 7. ✓ (fix del doble pago en `linkInvoices`).
- §4.3 Freeze (policy + factory + DI + getNextStatus) → Task 1. ✓
- §4.5 Display beneficiario → Task 4 Steps 3, 5, 6. ✓
- §4.6 Copy → Task 4 Step 7. ✓
- §8 nota `MA-006` / deriva de nombres → Task 3 Step 7. ✓
- §9 Testing (candidatos, vinculación, freeze, validación/total, display) → cubierto en Tasks 1–4. ✓
- Fuera de alcance (LinkedInvoiceLegalizer, presentación de pipeline, advance_link_modal) → no se tocan. ✓

**Placeholder scan:** sin TBD/TODO; todos los pasos de código llevan el código completo y los tests de integración usan el arnés real verificado del repo (`buildService()`, `UserFactory::new()->save()`, `fetchTable()`, `InvoiceFactory::new()->reciboDeCaja()`, `AdvanceLegalizationFactory::new()->forAdvance()->withStatus()`). El factory usa el 4.º parámetro nullable con fallback `?? new`, por lo que ningún call-site existente (5 en total) requiere edición.

**Type consistency:** `getNextStatus(string, ?string, ?int)` usado igual en los 3 callers (Task 1 Steps 10–11); `ADVANCE_LINKABLE_DOCTYPES` definido en Task 2 y consumido en Task 3; `InvoiceBeneficiary::label(object): string` definido y usado con la misma firma; `ReciboCajaDocumentTypePolicy` registrada con el mismo orden de argumentos en factory (Step 5), DI (Step 6) y los dos tests (Steps 7, 8).
