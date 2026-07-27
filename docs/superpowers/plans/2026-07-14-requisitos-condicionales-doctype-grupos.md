# Requisitos condicionales por doctype + resolución inline en grupos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** DIAN y soporte condicionales por `document_type` (gate individual y de grupo), resolución inline de DIAN/soporte desde la tabla de hijas del padre, y vinculación de facturas en `aprobacion` a Caja Menor con auto-avance.

**Architecture:** Extensión del patrón existente (spec `docs/superpowers/specs/2026-07-14-requisitos-condicionales-doctype-grupos-design.md`). Flags estáticos en `DocumentTypePolicy`; contrato de errores keyed en el pipeline de Invoice; DTO `GroupReadinessReport` compartido por gates de grupo y checklist UI; element compartido con acciones por fila (AJAX); auto-avance transaccional en PettyCash. Sin migraciones de esquema.

**Tech Stack:** CakePHP 5.3 / PHP 8.4, PHPUnit + cakephp-fixture-factories, JS vanilla (fetch + SpiToast).

## Global Constraints

- **Sin migraciones de esquema.** Todo es código + templates.
- Idioma: mensajes de UI/errores en español; slugs de pipeline en español sin acentos (`aprobacion`, `contabilidad`).
- Constantes, nunca strings: `InvoiceConstants::DIAN_APPROVED`, `STATUS_APROBACION`, etc.
- `DIAN_REJECTED = 'Rechazado'` (masculino) es deliberado — no "corregir".
- **Gemelo muerto:** `src/Service/PettyCashService.php` NO se toca (sin referencias vivas; el controller usa `PettyCashPipelineService`). No parchearlo ni borrarlo.
- Servicios retornan `ServiceResult` donde ya lo hacen; DI por constructor con `?? new`; tablas vía `TableRegistry::getTableLocator()->get()`.
- Mapeos estado→pill SOLO en `src/View/Presentation/InvoicePresentation.php` (anti-drift) — cero literales inline en templates.
- Tests: `vendor/bin/phpunit <ruta> --filter <nombre>` (timeout 300s). **La suite completa termina con exit 1 en verde por notices preexistentes (baseline ~843 tests)** — evaluar por el resumen de failures/errors, no por el exit code. Los tests DB usan las factories de `tests/Factory/` y credenciales de `config/.env`.
- Estilo: `composer cs-check` debe pasar al final de cada task (usar `composer cs-fix` para autofix).
- Refinamiento aprobado en plan respecto al spec §3.2 (misma meta, menos código): los flags `requiresDianValidation()`/`requiresSupportDocument()` son **métodos estáticos** de la interfaz (evita el ciclo de construcción guard→factory→AnticipoPolicy→AdvanceLegalizationService→registry→state→guard), y `getTransitionRules()` **se elimina** de la cadena en lugar de propagarle la factura: su único consumidor era la correlación posicional de `filterErrorsForRole()`, que desaparece con el contrato keyed (verificado por grep: no hay otros call sites en src/, templates/ ni tests salvo los que este plan actualiza).

---

### Task 1: Flags por tipo de documento (interfaz + 4 policies + helpers de la factory)

**Files:**
- Modify: `src/Service/Pipeline/Invoice/DocumentTypePolicy.php`
- Modify: `src/Service/Pipeline/Invoice/Policy/StandardDocumentTypePolicy.php`
- Modify: `src/Service/Pipeline/Invoice/Policy/AnticipoDocumentTypePolicy.php`
- Modify: `src/Service/Pipeline/Invoice/Policy/LegalizacionDocumentTypePolicy.php`
- Modify: `src/Service/Pipeline/Invoice/Policy/ReciboCajaDocumentTypePolicy.php`
- Modify: `src/Service/Pipeline/Invoice/DocumentTypePolicyFactory.php`
- Test: `tests/TestCase/Service/Pipeline/Invoice/DocumentTypePolicyFactoryTest.php` (ampliar)

**Interfaces:**
- Produces: `DocumentTypePolicy::requiresDianValidation(): bool` y `::requiresSupportDocument(): bool` (estáticos); `DocumentTypePolicyFactory::requiresDianFor(?string): bool`, `::requiresSupportFor(?string): bool`, `::dianExemptDocumentTypes(): array`, `::supportExemptDocumentTypes(): array` (estáticos). Consumidos por Tasks 3, 5, 10, 11.

- [ ] **Step 1: Tests que fallan** — añadir a `DocumentTypePolicyFactoryTest.php` (respetar el estilo del archivo existente):

```php
public function testRequiresDianForReciboCajaIsFalse(): void
{
    $this->assertFalse(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_RECIBO_CAJA));
    $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_FACTURA));
    $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(InvoiceConstants::DOCTYPE_ANTICIPO));
    $this->assertTrue(DocumentTypePolicyFactory::requiresDianFor(null));
}

public function testRequiresSupportForAllTypesIsTrueToday(): void
{
    $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(InvoiceConstants::DOCTYPE_RECIBO_CAJA));
    $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(InvoiceConstants::DOCTYPE_FACTURA));
    $this->assertTrue(DocumentTypePolicyFactory::requiresSupportFor(null));
}

public function testExemptListsAreDerivedFromPolicies(): void
{
    $this->assertSame([InvoiceConstants::DOCTYPE_RECIBO_CAJA], DocumentTypePolicyFactory::dianExemptDocumentTypes());
    $this->assertSame([], DocumentTypePolicyFactory::supportExemptDocumentTypes());
}

public function testPolicyClassesMirrorsFactoryInstances(): void
{
    // Guard anti-drift BIDIRECCIONAL: para TODO doctype conocido, la clase que
    // resuelve for() debe ser exactamente la que POLICY_CLASSES declara (o
    // Standard si no está declarada). Caza tanto la entrada que falta en
    // POLICY_CLASSES como la que falta en el byType del constructor.
    $factory = new DocumentTypePolicyFactory(
        new StandardDocumentTypePolicy(),
        new AnticipoDocumentTypePolicy($this->createStub(AdvanceLegalizationService::class)),
        new LegalizacionDocumentTypePolicy(),
    );
    foreach (InvoiceConstants::DOCUMENT_TYPES as $doctype) {
        $expected = DocumentTypePolicyFactory::POLICY_CLASSES[$doctype] ?? StandardDocumentTypePolicy::class;
        $this->assertSame($expected, $factory->for($doctype)::class, "Drift POLICY_CLASSES↔byType en '{$doctype}'");
    }
}
```

- [ ] **Step 2: Verificar que fallan** — `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/DocumentTypePolicyFactoryTest.php` → FAIL (método no existe).

- [ ] **Step 3: Implementar.** En la interfaz `DocumentTypePolicy.php`, añadir al final (antes de `}`):

```php
    /** ¿El avance aprobacion→contabilidad exige dian_validation='Aprobada'? Flag de clase (no depende de la instancia). */
    public static function requiresDianValidation(): bool;

    /** ¿El avance aprobacion→contabilidad exige ≥1 documento en invoice_documents? Flag de clase. */
    public static function requiresSupportDocument(): bool;
```

En `StandardDocumentTypePolicy`, `AnticipoDocumentTypePolicy` y `LegalizacionDocumentTypePolicy` añadir:

```php
    public static function requiresDianValidation(): bool
    {
        return true;
    }

    public static function requiresSupportDocument(): bool
    {
        return true;
    }
```

En `ReciboCajaDocumentTypePolicy`:

```php
    public static function requiresDianValidation(): bool
    {
        return false;
    }

    public static function requiresSupportDocument(): bool
    {
        return true;
    }
```

En `DocumentTypePolicyFactory`, añadir (las keys de `POLICY_CLASSES` deben espejear las de `$this->byType`; documentarlo en el docblock):

```php
    /**
     * Clases policy por doctype especial (espejo de $byType). Fuente de las
     * consultas ESTÁTICAS de flags: los guards y states no necesitan construir
     * la factory (AnticipoDocumentTypePolicy requiere AdvanceLegalizationService).
     *
     * @var array<string, class-string<\App\Service\Pipeline\Invoice\DocumentTypePolicy>>
     */
    public const POLICY_CLASSES = [
        InvoiceConstants::DOCTYPE_ANTICIPO     => AnticipoDocumentTypePolicy::class,
        InvoiceConstants::DOCTYPE_LEGALIZACION => LegalizacionDocumentTypePolicy::class,
        InvoiceConstants::DOCTYPE_RECIBO_CAJA  => ReciboCajaDocumentTypePolicy::class,
    ];

    public static function requiresDianFor(?string $documentType): bool
    {
        $class = self::POLICY_CLASSES[$documentType] ?? StandardDocumentTypePolicy::class;

        return $class::requiresDianValidation();
    }

    public static function requiresSupportFor(?string $documentType): bool
    {
        $class = self::POLICY_CLASSES[$documentType] ?? StandardDocumentTypePolicy::class;

        return $class::requiresSupportDocument();
    }

    /** @return list<string> Doctypes exentos de DIAN (derivado de las policies, única fuente). */
    public static function dianExemptDocumentTypes(): array
    {
        return array_values(array_keys(array_filter(
            self::POLICY_CLASSES,
            static fn(string $class): bool => !$class::requiresDianValidation(),
        )));
    }

    /** @return list<string> Doctypes exentos de soporte. */
    public static function supportExemptDocumentTypes(): array
    {
        return array_values(array_keys(array_filter(
            self::POLICY_CLASSES,
            static fn(string $class): bool => !$class::requiresSupportDocument(),
        )));
    }
```

- [ ] **Step 4: Verificar** — mismo comando → PASS. También correr los tests de las 4 policies existentes (`vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/ tests/TestCase/Service/Pipeline/Invoice/Policy/`) → verdes.

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat: flags estaticos requiresDian/requiresSupport por DocumentTypePolicy"`

---

### Task 2: `InvoiceGuard` (IO de documentos) + `InvoiceDocumentFactory`

**Files:**
- Create: `src/Service/Pipeline/Invoice/Guard/InvoiceGuard.php`
- Create: `tests/Factory/InvoiceDocumentFactory.php`
- Test: `tests/TestCase/Service/Pipeline/Invoice/Guard/InvoiceGuardTest.php`

**Interfaces:**
- Produces: `InvoiceGuard::hasAnyDocument(int $invoiceId): bool`; `InvoiceDocumentFactory` (usada por Tasks 5, 10). Semántica deliberadamente laxa (spec §3.2): cualquier documento de cualquier fase satisface.

- [ ] **Step 1: Factory.** Crear `tests/Factory/InvoiceDocumentFactory.php` (mirror del estilo de `InvoiceFactory`; columnas según migración `20260223000005_CreateInvoiceDocuments`: `invoice_id`, `pipeline_status` NOT NULL, `document_type` nullable, `file_path`, `file_name`, `file_size`, `mime_type`, `uploaded_by`):

```php
<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class InvoiceDocumentFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'InvoiceDocuments';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'file_path' => 'storage/invoices/test.pdf',
            'file_name' => 'test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ];
    }
}
```

Si `InvoiceDocuments` exige más columnas NOT NULL al guardar, ajustarlas leyendo la migración citada — no adivinar.

- [ ] **Step 2: Test que falla** (`InvoiceGuardTest.php`, DB-backed con `Cake\TestSuite\TestCase`):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Guard;

use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use Cake\TestSuite\TestCase;

final class InvoiceGuardTest extends TestCase
{
    public function testHasAnyDocumentFalseWithoutDocs(): void
    {
        $invoice = InvoiceFactory::new()->save();
        $this->assertFalse((new InvoiceGuard())->hasAnyDocument((int)$invoice->id));
    }

    public function testHasAnyDocumentTrueWithAnyDoc(): void
    {
        $invoice = InvoiceFactory::new()->save();
        InvoiceDocumentFactory::new(['invoice_id' => $invoice->id])->save();
        $this->assertTrue((new InvoiceGuard())->hasAnyDocument((int)$invoice->id));
    }
}
```

- [ ] **Step 3: Verificar que falla** — `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/Guard/InvoiceGuardTest.php` → FAIL (clase no existe).

- [ ] **Step 4: Implementar** `src/Service/Pipeline/Invoice/Guard/InvoiceGuard.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\Guard;

use Cake\ORM\TableRegistry;

/**
 * IO que los States puros del pipeline de facturas necesitan sobre documentos.
 * Espejo del patrón RefundApprovalGuard. NO final: PHPUnit mockea el guard.
 */
class InvoiceGuard
{
    /**
     * ≥1 fila en invoice_documents, sin importar fase ni tipo (Decisión 2 del
     * spec: la disciplina fina la pone el revisor humano).
     */
    public function hasAnyDocument(int $invoiceId): bool
    {
        return TableRegistry::getTableLocator()->get('InvoiceDocuments')
            ->exists(['invoice_id' => $invoiceId]);
    }
}
```

- [ ] **Step 5: Verificar** → PASS. **Step 6: Commit** — `git commit -m "feat: InvoiceGuard.hasAnyDocument + InvoiceDocumentFactory"`

---

### Task 3: Contrato keyed + gate condicional en los States de Invoice

**Files:**
- Modify: `src/Service/Pipeline/Invoice/InvoicePipelineState.php`
- Modify: `src/Service/Pipeline/Invoice/State/AprobacionState.php`
- Modify: `src/Service/Pipeline/Invoice/State/ContabilidadState.php`
- Modify: `src/Service/Pipeline/Invoice/State/TesoreriaState.php`
- Modify: `src/Service/Pipeline/Invoice/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/Invoice/State/VerificacionPagoState.php`
- Test: `tests/TestCase/Service/Pipeline/Invoice/State/InvoiceStatesTest.php` (actualizar)

**Interfaces:**
- Consumes: `DocumentTypePolicyFactory::requiresDianFor/requiresSupportFor` (Task 1), `InvoiceGuard` (Task 2).
- Produces: `validateAdvance(object $invoice): array<string,string>` keyed por requisito. Keys usadas: `area_approval`, `dian_validation`, `support_document`, `accrued`, `accrual_date`, `ready_for_payment`, `_has_pending_payment`, `_payment_authorized`, `_payment_executed`. `AprobacionState::__construct(?InvoiceGuard $guard = null)`.

**Nota:** en esta task `getTransitionRules()` aún existe y NO se toca (se elimina en Task 4). **Tasks 3 y 4 son ATÓMICAS: un solo commit al final de Task 4** — commitear T3 sola dejaría `filterErrorsForRole` posicional correlacionando contra errores keyed (los warnings de avance desaparecerían de la UI en ese estado intermedio).

**Ojo con `InvoiceStatesTest.php`:** es `PHPUnit\Framework\TestCase` puro (sin DB) y hoy instancia `new AprobacionState()` sin args en varios puntos. TODAS las instancias existentes de `AprobacionState` en ese archivo deben pasar a `new AprobacionState($guardStub)` con `hasAnyDocument → true` — el guard default usa `TableRegistry` y tocaría la BD en un test unitario.

- [ ] **Step 1: Tests.** En `InvoiceStatesTest.php`, actualizar/añadir los casos de `validateAdvance` para reflejar el contrato keyed y el gate condicional. Casos nuevos mínimos (adaptar helpers existentes del archivo; `AprobacionState` recibe un stub de `InvoiceGuard`):

```php
public function testAprobacionKeysErrorsByRequirement(): void
{
    $guard = $this->createStub(InvoiceGuard::class);
    $guard->method('hasAnyDocument')->willReturn(false);
    $state = new AprobacionState($guard);

    $invoice = new Invoice([
        'id' => 1,
        'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        'dian_validation' => InvoiceConstants::DIAN_PENDING,
    ]);

    $errors = $state->validateAdvance($invoice);
    $this->assertArrayHasKey('dian_validation', $errors);
    $this->assertArrayHasKey('support_document', $errors);
    $this->assertArrayNotHasKey('area_approval', $errors);
}

public function testAprobacionReciboCajaSkipsDianButNotSupport(): void
{
    $guard = $this->createStub(InvoiceGuard::class);
    $guard->method('hasAnyDocument')->willReturn(false);
    $state = new AprobacionState($guard);

    $invoice = new Invoice([
        'id' => 1,
        'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
        'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        'dian_validation' => InvoiceConstants::DIAN_PENDING,
    ]);

    $errors = $state->validateAdvance($invoice);
    $this->assertArrayNotHasKey('dian_validation', $errors);
    $this->assertArrayHasKey('support_document', $errors);
}

public function testAprobacionPassesWithDianAndDocument(): void
{
    $guard = $this->createStub(InvoiceGuard::class);
    $guard->method('hasAnyDocument')->willReturn(true);
    $state = new AprobacionState($guard);

    $invoice = new Invoice([
        'id' => 1,
        'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        'dian_validation' => InvoiceConstants::DIAN_APPROVED,
    ]);

    $this->assertSame([], $state->validateAdvance($invoice));
}
```

Además, actualizar los asserts existentes del archivo que comparan listas posicionales de `validateAdvance` (Contabilidad/Tesoreria/etc.) a los nuevos arrays keyed (mismos mensajes, ahora con key).

- [ ] **Step 2: Verificar que fallan** — `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/State/InvoiceStatesTest.php` → FAIL.

- [ ] **Step 3: Implementar.**

`InvoicePipelineState.php` — actualizar el docblock de `validateAdvance`:

```php
    /**
     * Errores de requirement de este estado para avanzar al siguiente,
     * KEYED por requisito (key de InvoiceTransitionValidator::REQUIREMENT_FIELDS).
     * No incluye rejection ni doctype block — el coordinador los compone.
     *
     * @return array<string, string>
     */
    public function validateAdvance(object $invoice): array;
```

`AprobacionState.php` — reemplazar la clase completa por:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Service\Pipeline\Invoice\InvoicePipelineState;

final class AprobacionState implements InvoicePipelineState
{
    private InvoiceGuard $guard;

    public function __construct(?InvoiceGuard $guard = null)
    {
        $this->guard = $guard ?? new InvoiceGuard();
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

    public function validateAdvance(object $invoice): array
    {
        $documentType = $invoice->document_type ?? null;

        $errors = [];
        if (($invoice->area_approval ?? null) !== InvoiceConstants::APPROVAL_APPROVED) {
            $errors['area_approval'] = 'Todos los aprobadores deben haber aprobado';
        }
        if (
            DocumentTypePolicyFactory::requiresDianFor($documentType)
            && ($invoice->dian_validation ?? null) !== InvoiceConstants::DIAN_APPROVED
        ) {
            $errors['dian_validation'] = 'Validación DIAN debe ser "Aprobada"';
        }
        if (
            DocumentTypePolicyFactory::requiresSupportFor($documentType)
            && !empty($invoice->id)
            && !$this->guard->hasAnyDocument((int)$invoice->id)
        ) {
            $errors['support_document'] = 'Debe cargar al menos un soporte de la factura';
        }

        return $errors;
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => 'area_approval',   'label' => 'Todos los aprobadores deben haber aprobado'],
            ['field' => 'dian_validation', 'label' => 'Validación DIAN debe ser "Aprobada"'],
        ];
    }
}
```

Los otros 4 states: **misma lógica condicional que hoy, solo cambia la forma del array** (key por requisito). `ContabilidadState::validateAdvance` → `$errors['accrued'] = ...`, `$errors['accrual_date'] = ...`, `$errors['ready_for_payment'] = ...` (cada uno dentro de su `if` actual). `TesoreriaState` → dentro del `if (!$this->paymentService->hasPendingAuthorization(...))` existente, `return ['_has_pending_payment' => 'Debe registrar al menos un pago para avanzar a autorización'];`. `AutorizacionPagoState` → dentro de su `if` existente, `return ['_payment_authorized' => 'El pago pendiente debe ser autorizado por el Contador'];`. `VerificacionPagoState` → `return ['_payment_executed' => 'La confirmación de pago se gestiona desde la sección de pagos.'];` (incondicional, como hoy). `PagadaState`/`LegalizadaState` ya devuelven `[]`.

- [ ] **Step 4: Verificar** — StatesTest PASS (`InvoiceTransitionValidatorTest` fallará hasta Task 4 — esperado). **NO commitear aquí:** continuar directo a Task 4 y commitear ambas juntas (ver nota de atomicidad arriba).

---

### Task 4: Validator keyed + pass-through + eliminación de `getTransitionRules`

**Files:**
- Modify: `src/Service/Pipeline/Invoice/Policy/InvoiceTransitionValidator.php`
- Modify: `src/Service/InvoicePipelineService.php:111-119`
- Modify: `src/Controller/InvoicesController.php:393-399` y `:429-430`
- Modify: `src/Service/Pipeline/Invoice/InvoicePipelineState.php` + los 7 states (quitar `getTransitionRules()`)
- Test: `tests/TestCase/Service/Pipeline/Invoice/Policy/InvoiceTransitionValidatorTest.php` (actualizar)
- Test: `tests/TestCase/Service/Pipeline/Invoice/State/InvoiceStatesTest.php` (quitar los asserts de `getTransitionRules` — líneas ~48, 160, 171, 181)

**Interfaces:**
- Produces: `InvoiceTransitionValidator::filterErrorsForRole(array $errors, int $roleId, string $status): array` (sin `$rules`); `validateAdvance()` early-returns keyed (`_rejected`, `_invalid_status`, `_doctype_block`); `InvoicePipelineService::filterAdvanceErrorsForRole(array $errors, int $roleId, string $status)`. `getTransitionRules` deja de existir en toda la cadena.

- [ ] **Step 1: Tests.** Reescribir en `InvoiceTransitionValidatorTest.php` (mantener `buildValidator()`, pero `new AprobacionState($this->createStub(InvoiceGuard::class))` con `hasAnyDocument→true`):
  - `testValidateAdvanceBlocksRejectedInvoice` → `assertSame(['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'], $errors)`.
  - `testValidateAdvanceRejectsInvalidFromStatus` → `assertArrayHasKey('_invalid_status', $errors)`.
  - `testValidateAdvanceBlockedByLegalizacionPolicyInContabilidad` → `assertArrayHasKey('_doctype_block', $errors)`.
  - `filterErrorsForRole`: quitar el parámetro `$rules` de las llamadas; los errores de entrada pasan keyed (`['area_approval' => 'Falta la aprobación del área.']`, `['dian_validation' => ...]`); mismos asserts de visibilidad.
  - Nuevos:

```php
public function testFilterErrorsDoesNotMisattributeWhenOneRequirementPasses(): void
{
    // area pasa, dian falla: el error DIAN debe seguir gobernado por dian_validation
    // (con el contrato posicional viejo se atribuía a area_approval).
    $errors = ['dian_validation' => 'Falta la validación DIAN.'];

    $authNotEditable = $this->createMock(AuthorizationFacade::class);
    $authNotEditable->method('canOperate')->willReturn(false);
    $hidden = $this->buildValidator($authNotEditable)
        ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
    $this->assertSame([], $hidden);
}

public function testReservedKeysAlwaysPassTheFilter(): void
{
    $errors = ['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'];
    $auth = $this->createMock(AuthorizationFacade::class);
    $auth->method('canOperate')->willReturn(false);

    $shown = $this->buildValidator($auth)
        ->filterErrorsForRole($errors, 3, InvoiceConstants::STATUS_APROBACION);
    $this->assertSame(['La factura fue rechazada. El flujo ha terminado.'], $shown);
}
```

  - Borrar `testGetTransitionRulesReturnsEmptyForInvalidStatus`.

- [ ] **Step 2: Verificar que fallan** → FAIL.

- [ ] **Step 3: Implementar `InvoiceTransitionValidator.php`:**
  - `REQUIREMENT_FIELDS`: añadir `'support_document' => [],` (responsable vacío ⇒ gobernado por `canOperate` del status — el error se resuelve subiendo documento, no tecleando).
  - Nueva const: `private const ALWAYS_VISIBLE_KEYS = ['_rejected', '_doctype_block', '_invalid_status'];`
  - En `validateAdvance()`: los 3 early-returns pasan a keyed — `return ['_rejected' => 'La factura fue rechazada. El flujo ha terminado.'];`, `return ['_invalid_status' => "Estado de origen inválido: {$fromStatus}"];`, `return ['_doctype_block' => $blockMsg];`. Actualizar el docblock `@return array<string, string>`.
  - Borrar `getTransitionRules()`.
  - Reemplazar `filterErrorsForRole` por:

```php
    /**
     * @param array<string, string> $errors Errores keyed por requisito.
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, int $roleId, string $status): array
    {
        $editable = $this->fieldPolicy->getEditableFields($roleId, $status);
        $statusVisible = $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            $status,
        );

        $filtered = [];
        foreach ($errors as $key => $message) {
            if (in_array($key, self::ALWAYS_VISIBLE_KEYS, true)) {
                $filtered[] = $message;
                continue;
            }
            $responsible = self::REQUIREMENT_FIELDS[$key] ?? [$key];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $message;
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $message;
            }
        }

        return $filtered;
    }
```

- [ ] **Step 4: Propagar.**
  - `InvoicePipelineService.php`: borrar `getTransitionRules()` (líneas 111-114); `filterAdvanceErrorsForRole(array $errors, int $roleId, string $status)` delega sin `$rules`.
  - `InvoicesController.php` líneas 393-399: borrar `$rules = ...;` y llamar `$this->pipeline->filterAdvanceErrorsForRole($advanceErrors, $roleId, $currentStatus)`. Ídem líneas 429-430.
  - Interfaz `InvoicePipelineState.php`: borrar la firma `getTransitionRules()` y su docblock; borrar el método en los 7 states (`AprobacionState`, `ContabilidadState`, `TesoreriaState`, `AutorizacionPagoState`, `VerificacionPagoState`, `PagadaState`, `LegalizadaState`).
  - `grep -rn "getTransitionRules" src/ tests/ templates/` → debe devolver 0 resultados.

- [ ] **Step 5: Verificar** — `vendor/bin/phpunit tests/TestCase/Service/Pipeline/Invoice/ tests/TestCase/Controller/InvoicesControllerTest.php` → verde. **Step 6: Commit (cubre Tasks 3+4)** — `git commit -m "feat: gate DIAN/soporte condicional por doctype + contrato keyed de errores del pipeline de facturas"`

---

### Task 5: `GroupReadinessReport` + `GroupReadinessQuery`

**Files:**
- Create: `src/Service/Dto/GroupReadinessReport.php`
- Create: `src/Service/GroupReadinessQuery.php`
- Test: `tests/TestCase/Service/GroupReadinessQueryTest.php`

**Interfaces:**
- Consumes: `DocumentTypePolicyFactory::dianExemptDocumentTypes()/supportExemptDocumentTypes()` (Task 1).
- Produces: `GroupReadinessReport` (readonly: `array $dianPending`, `array $supportMissing`, ambos `array<int,string>` id⇒número; `isBlocked(): bool`; `toMessages(): array<string>`); `GroupReadinessQuery::report(array $conditions, bool $includeDian = true): GroupReadinessReport`. Consumido por Tasks 6, 7, 10, 12, 13.

- [ ] **Step 1: Test que falla** (DB-backed):

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\GroupReadinessQuery;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use Cake\TestSuite\TestCase;

final class GroupReadinessQueryTest extends TestCase
{
    public function testReportFlagsDianAndSupport(): void
    {
        $refund = RefundFactory::new()->save();
        // Sin DIAN y sin soporte → aparece en ambos.
        $bad = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-BAD',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // DIAN ok y con soporte → limpia.
        $good = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-GOOD',
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        InvoiceDocumentFactory::new(['invoice_id' => $good->id])->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id]);

        $this->assertSame([$bad->id => 'F-BAD'], $report->dianPending);
        $this->assertSame([$bad->id => 'F-BAD'], $report->supportMissing);
        $this->assertTrue($report->isBlocked());
        $this->assertCount(2, $report->toMessages());
    }

    public function testDianExemptDoctypeIsIgnoredForDianButNotSupport(): void
    {
        $refund = RefundFactory::new()->save();
        $rc = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'RC-1',
        ])->reciboDeCaja()->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id]);

        $this->assertSame([], $report->dianPending);
        $this->assertSame([$rc->id => 'RC-1'], $report->supportMissing);
    }

    public function testIncludeDianFalseSkipsDianEntirely(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
        ])->save();

        $report = GroupReadinessQuery::report(['refund_id' => $refund->id], includeDian: false);
        $this->assertSame([], $report->dianPending);
    }
}
```

(El caso "lista de exentos vacía no rompe el NOT IN" queda cubierto implícitamente: `supportExemptDocumentTypes()` es `[]` hoy y la query de soporte corre en los 3 tests.)

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar.**

`src/Service/Dto/GroupReadinessReport.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * Reporte estructurado de requisitos pendientes de las facturas hijas de un
 * registro padre (Reintegro / Caja Menor / Anticipo). Misma fuente para el
 * gate de avance del padre y el checklist de la vista (cero drift).
 */
final readonly class GroupReadinessReport
{
    /**
     * @param array<int, string> $dianPending id ⇒ invoice_number
     * @param array<int, string> $supportMissing id ⇒ invoice_number
     */
    public function __construct(
        public array $dianPending = [],
        public array $supportMissing = [],
    ) {
    }

    public function isBlocked(): bool
    {
        return $this->dianPending !== [] || $this->supportMissing !== [];
    }

    /** @return array<string> Mensajes ES para errores de transición / flash. */
    public function toMessages(): array
    {
        $messages = [];
        if ($this->dianPending !== []) {
            $messages[] = 'Validación DIAN pendiente en: ' . implode(', ', $this->dianPending);
        }
        if ($this->supportMissing !== []) {
            $messages[] = 'Soporte pendiente en: ' . implode(', ', $this->supportMissing);
        }

        return $messages;
    }
}
```

`src/Service/GroupReadinessQuery.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Dto\GroupReadinessReport;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

/**
 * Queries compartidas de los guards de grupo (Refund/PettyCash/Advance).
 * Las exenciones por doctype salen de DocumentTypePolicyFactory (única fuente);
 * con lista de exentos vacía se OMITE la cláusula NOT IN (CakePHP lanza
 * excepción con IN/NOT IN sobre array vacío).
 */
final class GroupReadinessQuery
{
    /**
     * @param array<string, mixed> $conditions Condiciones que seleccionan las hijas (p.ej. ['refund_id' => $id]).
     */
    public static function report(array $conditions, bool $includeDian = true): GroupReadinessReport
    {
        $dianPending = [];
        if ($includeDian) {
            $dianConditions = $conditions + ['dian_validation !=' => InvoiceConstants::DIAN_APPROVED];
            $dianExempt = DocumentTypePolicyFactory::dianExemptDocumentTypes();
            if ($dianExempt !== []) {
                $dianConditions['document_type NOT IN'] = $dianExempt;
            }
            $dianPending = self::_numbersById(self::_invoices()->find()->where($dianConditions));
        }

        $supportConditions = $conditions;
        $supportExempt = DocumentTypePolicyFactory::supportExemptDocumentTypes();
        if ($supportExempt !== []) {
            $supportConditions['document_type NOT IN'] = $supportExempt;
        }
        $supportQuery = self::_invoices()->find()
            ->where($supportConditions)
            ->where(function ($exp) {
                $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
                $sub = $docs->find()
                    ->select(['InvoiceDocuments.id'])
                    ->where(['InvoiceDocuments.invoice_id = Invoices.id']);

                return $exp->notExists($sub);
            });
        $supportMissing = self::_numbersById($supportQuery);

        return new GroupReadinessReport($dianPending, $supportMissing);
    }

    private static function _invoices(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('Invoices');
    }

    /** @return array<int, string> */
    private static function _numbersById(SelectQuery $query): array
    {
        $result = [];
        foreach ($query->select(['id', 'invoice_number'])->all() as $invoice) {
            $result[(int)$invoice->id] = $invoice->invoice_number ?: '#' . $invoice->id;
        }

        return $result;
    }
}
```

- [ ] **Step 4: PASS.** — [ ] **Step 5: Commit** — `git commit -m "feat: GroupReadinessReport + GroupReadinessQuery compartida"`

---

### Task 6: Guards de Refund/Advance → `childRequirements` (y muere `childInvoicesFailingDian`)

**Files:**
- Modify: `src/Service/RefundApprovalGuard.php`
- Modify: `src/Service/AdvanceLegalizationApprovalGuard.php`
- Modify: `src/Service/Pipeline/Refund/State/AprobacionState.php:35-47`
- Modify: `src/Service/Pipeline/Advance/State/AprobacionState.php:42-54`
- Test: `tests/TestCase/Service/RefundApprovalGuardTest.php`, `tests/TestCase/Service/AdvanceLegalizationApprovalGuardTest.php`, `tests/TestCase/Service/Pipeline/Refund/State/AprobacionStateTest.php`, `tests/TestCase/Service/Pipeline/Advance/State/AprobacionStateTest.php`
- Test (regresión — el nuevo gate de soporte los rompe si no se siembran documentos): `tests/TestCase/Service/Integration/RefundGroupApprovalFlowTest.php`, `tests/TestCase/Service/Integration/AdvanceGroupApprovalFlowTest.php`, `tests/TestCase/Service/Integration/RefundPipelineServiceTest.php`

**Interfaces:**
- Produces: `RefundApprovalGuard::childRequirements(int $refundId): GroupReadinessReport`; `AdvanceLegalizationApprovalGuard::childRequirements(int $advanceInvoiceId): GroupReadinessReport` (mantiene el filtro `document_type IN ADVANCE_LINKABLE_DOCTYPES`). `childInvoicesFailingDian()` eliminado en ambos.

- [ ] **Step 1: Tests.** En `RefundApprovalGuardTest.php` reemplazar `testChildInvoicesFailingDian` por:

```php
    public function testChildRequirementsReportsDianAndSupport(): void
    {
        $refund = RefundFactory::new()->save();
        $ok = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_APPROVED,
            'invoice_number' => 'F-OK',
        ])->save();
        InvoiceDocumentFactory::new(['invoice_id' => $ok->id])->save();
        $pending = InvoiceFactory::new([
            'refund_id' => $refund->id,
            'dian_validation' => InvoiceConstants::DIAN_PENDING,
            'invoice_number' => 'F-PEND',
        ])->save();

        $report = (new RefundApprovalGuard())->childRequirements((int)$refund->id);

        $this->assertSame([$pending->id => 'F-PEND'], $report->dianPending);
        $this->assertSame([$pending->id => 'F-PEND'], $report->supportMissing);
    }
```

En `AdvanceLegalizationApprovalGuardTest.php`, adaptar el test equivalente existente de `childInvoicesFailingDian` a `childRequirements` (mismos fixtures; asserts sobre `->dianPending`) y añadir el caso RC exento: hija `->reciboDeCaja()` con `advance_id` seteado y DIAN pendiente → NO aparece en `dianPending`, SÍ en `supportMissing`.

En los dos `AprobacionStateTest`: los mocks del guard cambian `->method('childInvoicesFailingDian')->willReturn([])` por `->method('childRequirements')->willReturn(new GroupReadinessReport())`, y añadir un caso donde `childRequirements` devuelve `new GroupReadinessReport(dianPending: [1 => 'F-1'])` → `validateAdvance` contiene un error con 'DIAN'.

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar.**

`RefundApprovalGuard.php` — reemplazar `childInvoicesFailingDian()` por:

```php
    /** Requisitos pendientes (DIAN + soporte) de las facturas hijas. */
    public function childRequirements(int $refundId): GroupReadinessReport
    {
        return GroupReadinessQuery::report(['refund_id' => $refundId]);
    }
```

(+ imports `App\Service\Dto\GroupReadinessReport`, `App\Service\GroupReadinessQuery`.)

`AdvanceLegalizationApprovalGuard.php` — ídem con el filtro existente:

```php
    /**
     * Requisitos pendientes (DIAN + soporte) de las hijas vinculadas al anticipo.
     * Recibe el id del Invoice del anticipo (advance_invoice_id).
     */
    public function childRequirements(int $advanceInvoiceId): GroupReadinessReport
    {
        return GroupReadinessQuery::report([
            'advance_id' => $advanceInvoiceId,
            'document_type IN' => InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES,
        ]);
    }
```

`Refund/State/AprobacionState::validateAdvance` — reemplazar el bloque DIAN por:

```php
        foreach ($this->guard->childRequirements((int)$record->id)->toMessages() as $msg) {
            $errors[] = $msg;
        }
```

`Advance/State/AprobacionState::validateAdvance` — ídem con `(int)$leg->advance_invoice_id`.

`grep -rn "childInvoicesFailingDian" src/ tests/` → 0 resultados.

- [ ] **Step 3b: Adaptar los tests de integración existentes al nuevo gate de soporte.** En `RefundGroupApprovalFlowTest.php` y `AdvanceGroupApprovalFlowTest.php`, cada factura hija que el flujo espera que avance debe tener `InvoiceDocumentFactory::new(['invoice_id' => $child->id])->save()` en su arrange (los asserts `assertSame([], ...validateTransitionRequirements(...))` y los `advance()` fallan sin ello). En `RefundPipelineServiceTest.php` (Integration), ídem donde el avance del refund debe pasar. Aprovechar para añadir a `RefundGroupApprovalFlowTest` el caso de cobertura del spec §5: hija SIN documento → avance bloqueado con mensaje que contiene 'Soporte pendiente'; se sube documento (factory) → avance pasa. Y a `AdvanceGroupApprovalFlowTest`: hija `->reciboDeCaja()` con DIAN pendiente pero con documento → NO bloquea (exención DIAN en flujo real).

- [ ] **Step 4: Verificar** — `vendor/bin/phpunit tests/TestCase/Service/RefundApprovalGuardTest.php tests/TestCase/Service/AdvanceLegalizationApprovalGuardTest.php tests/TestCase/Service/Pipeline/Refund/ tests/TestCase/Service/Pipeline/Advance/ tests/TestCase/Service/Integration/RefundGroupApprovalFlowTest.php tests/TestCase/Service/Integration/AdvanceGroupApprovalFlowTest.php tests/TestCase/Service/Integration/RefundPipelineServiceTest.php` → verde.
- [ ] **Step 5: Commit** — `git commit -m "feat: gates de grupo Refund/Advance via GroupReadinessReport (DIAN policy-aware + soporte)"`

---

### Task 7: `PettyCashGuard` + gate de soporte en `AgrupacionState`

**Files:**
- Create: `src/Service/Pipeline/PettyCash/Guard/PettyCashGuard.php`
- Modify: `src/Service/Pipeline/PettyCash/State/AgrupacionState.php`
- Test: `tests/TestCase/Service/Pipeline/PettyCash/Guard/PettyCashGuardTest.php` (nuevo), `tests/TestCase/Service/Pipeline/PettyCash/State/PettyCashStatesTest.php` (actualizar)
- Test (regresión): `tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php` — `testAdvanceMovesRecordFromAgrupacionToContabilidad` (líneas ~58-72) vincula una hija sin documento; sembrar `InvoiceDocumentFactory` en su arrange y actualizar el docblock del test ("Solo exige ≥1 factura agrupada" queda obsoleto)

**Interfaces:**
- Produces: `PettyCashGuard::childRequirements(int $recordId): GroupReadinessReport` (con `includeDian: false` — las hijas de caja menor saltan Aprobación por diseño, el DIAN no les aplica). `AgrupacionState::__construct(?PettyCashGuard $guard = null)`.

- [ ] **Step 1: Tests.** `PettyCashGuardTest.php`: record + hija sin documento → `supportMissing` la contiene y `dianPending === []` aunque su DIAN esté pendiente (usar `PettyCashRecordFactory` + `InvoiceFactory::new(['petty_cash_record_id' => $record->id, 'dian_validation' => InvoiceConstants::DIAN_PENDING])`). En `PettyCashStatesTest.php`: `AgrupacionState` con guard mockeado — report vacío → `[]`; report con `supportMissing` → error que contiene 'Soporte pendiente'.

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar.**

`src/Service/Pipeline/PettyCash/Guard/PettyCashGuard.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Guard;

use App\Service\Dto\GroupReadinessReport;
use App\Service\GroupReadinessQuery;

/**
 * IO del AgrupacionState puro de caja menor. Solo soporte: las hijas de caja
 * menor saltan el paso Aprobación por diseño (el vínculo certifica la
 * aprobación), así que el DIAN no les aplica. NO final: PHPUnit mockea el guard.
 */
class PettyCashGuard
{
    public function childRequirements(int $recordId): GroupReadinessReport
    {
        return GroupReadinessQuery::report(
            ['petty_cash_record_id' => $recordId],
            includeDian: false,
        );
    }
}
```

`AgrupacionState.php`:

```php
final class AgrupacionState implements PettyCashPipelineState
{
    private PettyCashGuard $guard;

    public function __construct(?PettyCashGuard $guard = null)
    {
        $this->guard = $guard ?? new PettyCashGuard();
    }
    // getStatus/getNextStatus/getPreviousStatus sin cambios

    public function validateAdvance(PettyCashRecord $record): array
    {
        return $this->guard->childRequirements((int)$record->id)->toMessages();
    }
}
```

(+ import `App\Service\Pipeline\PettyCash\Guard\PettyCashGuard`. Verificar que `PettyCashPipelineStateRegistry` construye `new AgrupacionState()` sin args — el param opcional lo mantiene compatible.)

- [ ] **Step 4: PASS** — `vendor/bin/phpunit tests/TestCase/Service/Pipeline/PettyCash/ tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php` (incluye la regresión adaptada). — [ ] **Step 5: Commit** — `git commit -m "feat: gate de soporte de hijas en PettyCash agrupacion via PettyCashGuard"`

---

### Task 8: `GroupedInvoiceService` multi-estado (`linkableStatuses`)

**Files:**
- Modify: `src/Service/GroupedInvoiceService.php`
- Modify: `src/Service/PettyCashPipelineService.php:49-55` (pasar ambos estados)
- Test: `tests/TestCase/Service/GroupedInvoiceServiceTest.php` (existe — ampliar; el cambio de mensaje de estado no rompe sus asserts actuales)

**Interfaces:**
- Produces: constructor acepta `string|array $linkableStatus` (normaliza a `list<string> $linkableStatuses`); `validateGrouping()` acepta cualquiera de los estados con mensaje multi-estado; `getAvailableInvoices()` usa `pipeline_status IN`. Refund (`RefundPipelineService.php:60`) NO cambia (string sigue funcionando).

- [ ] **Step 1: Test que falla** (nuevo o ampliado; DB-backed):

```php
public function testValidateGroupingAcceptsAnyLinkableStatus(): void
{
    $record = PettyCashRecordFactory::new()->save();
    $svc = new GroupedInvoiceService(
        documentType: InvoiceConstants::DOCTYPE_CAJA_MENOR,
        fkField: 'petty_cash_record_id',
        recordTableName: 'PettyCashRecords',
        fkLabel: 'Caja Menor',
        historyService: new InvoiceHistoryService(),
        linkableStatus: [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD],
    );

    $enAprobacion = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
        ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
    $enTesoreria = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
        ->withStatus(InvoiceConstants::STATUS_TESORERIA)->save();

    $this->assertSame([], $svc->validateGrouping([$enAprobacion->id]));
    $errors = $svc->validateGrouping([$enTesoreria->id]);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('estado vinculable', $errors[0]);
}
```

(Import `App\Service\InvoiceHistoryService` — es el history service que ya usan los llamadores reales para las hijas.)

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar** en `GroupedInvoiceService.php`:

```php
    /** @var list<string> */
    private readonly array $linkableStatuses;

    public function __construct(
        private readonly string $documentType,
        private readonly string $fkField,
        private readonly string $recordTableName,
        private readonly string $fkLabel,
        private readonly HistoryServiceInterface $historyService,
        string|array $linkableStatus = InvoiceConstants::STATUS_CONTABILIDAD,
    ) {
        $this->linkableStatuses = array_values((array)$linkableStatus);
    }
```

En `validateGrouping()` (línea 79) reemplazar el check de estado por:

```php
            if (!in_array($invoice->pipeline_status, $this->linkableStatuses, true)) {
                $labels = array_map(
                    static fn(string $s): string => InvoiceConstants::STATUS_LABELS[$s] ?? $s,
                    $this->linkableStatuses,
                );
                $errors[] = sprintf(
                    'La factura #%s no está en un estado vinculable (%s).',
                    $invoice->invoice_number ?? $invoice->id,
                    implode(' o ', $labels),
                );
            }
```

En `getAvailableInvoices()` (línea 194): `'Invoices.pipeline_status IN' => $this->linkableStatuses,`.

En `PettyCashPipelineService` constructor: añadir `linkableStatus: [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_CONTABILIDAD],` al `new GroupedInvoiceService(...)`.

- [ ] **Step 4: PASS** + correr `vendor/bin/phpunit tests/TestCase/Service/ --filter "Refund|PettyCash|Grouped"` para regresión. — [ ] **Step 5: Commit** — `git commit -m "feat: GroupedInvoiceService acepta multiples estados vinculables; caja menor vincula desde aprobacion"`

---

### Task 9: Auto-avance transaccional al vincular a Caja Menor

**Files:**
- Modify: `src/Service/PettyCashPipelineService.php:88-91` (método `addInvoices`) y `:214` (caller en `saveAndAdvance`)
- Modify: caller(s) en `src/Controller/PettyCashRecordsController.php` — localizar con `grep -n "addInvoices" src/Controller/PettyCashRecordsController.php`
- Modify: `templates/element/link_invoices_modal.php` (nota por-fila para candidatas que se auto-avanzarán) + su include en el edit de PettyCash — localizar con `grep -rn "link_invoices_modal" templates/PettyCashRecords/`
- Test: `tests/TestCase/Service/Integration/PettyCashPipelineServiceTest.php` (**ampliar el existente** — NO crear un archivo nuevo en otra ruta)

**Interfaces:**
- Produces: `PettyCashPipelineService::addInvoices(PettyCashRecord $record, array $invoiceIds, int $userId): array` (firma nueva con `$userId`). Vincular + auto-avance + historial en UNA transacción. Desvincular NO regresa (sin cambios en `removeInvoice`).

- [ ] **Step 1: Test que falla:**

```php
public function testAddInvoicesAutoAdvancesChildrenInAprobacion(): void
{
    $record = PettyCashRecordFactory::new()->save(); // status agrupacion por defecto — verificar factory
    $svc = $this->buildService(); // helper que construya PettyCashPipelineService con sus deps reales/mocks mínimos
    $user = UserFactory::new()->save();

    $child = InvoiceFactory::new(['document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR])
        ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

    $errors = $svc->addInvoices($record, [(int)$child->id], (int)$user->id);

    $this->assertSame([], $errors);
    $fresh = TableRegistry::getTableLocator()->get('Invoices')->get($child->id);
    $this->assertSame($record->id, $fresh->petty_cash_record_id);
    $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $fresh->pipeline_status);

    // Auditoría del cambio de estado en el historial de la hija.
    $histories = TableRegistry::getTableLocator()->get('InvoiceHistories')->find()
        ->where(['invoice_id' => $child->id])->all();
    $this->assertNotEmpty($histories->toList());
}

public function testAddInvoicesLeavesContabilidadChildrenUntouched(): void
{
    // hija ya en contabilidad → se vincula sin cambiar de estado
}
```

(Para `buildService()`: espejo de cómo el controller construye `PettyCashPipelineService` — revisar el constructor del controller con grep; si usa el container, instanciar a mano con `new InvoiceHistoryService()`, el `AuthorizationFacade` real y `new PettyCashFieldAccessPolicy(...)` según firmas reales. Verificar el nombre de la tabla de historial de facturas con `Glob src/Model/Table/InvoiceHistor*` antes de assert.)

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar.** Reemplazar `addInvoices` en `PettyCashPipelineService`:

```php
    /**
     * Vincula facturas al registro. Las hijas que estén en `aprobacion` se
     * auto-avanzan a `contabilidad` en la MISMA transacción (patrón
     * "PO-backed": el vínculo a Caja Menor certifica la aprobación; ver spec
     * 2026-07-14 §3.5). Desvincular NO regresa.
     *
     * @return array Errores (vacío = éxito).
     */
    public function addInvoices(PettyCashRecord $record, array $invoiceIds, int $userId): array
    {
        $errors = $this->grouped->validateGrouping($invoiceIds);
        if (!empty($errors)) {
            return $errors;
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $toPromote = $invoicesTable->find()
            ->select(['id'])
            ->where([
                'id IN' => $invoiceIds,
                'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            ])
            ->all()
            ->extract('id')
            ->toList();

        $linkErrors = [];
        $ok = $invoicesTable->getConnection()->transactional(
            function () use ($record, $invoiceIds, $toPromote, $invoicesTable, $userId, &$linkErrors): bool {
                $linkErrors = $this->grouped->addInvoices($record, $invoiceIds);
                if (!empty($linkErrors)) {
                    return false;
                }

                if (!empty($toPromote)) {
                    $invoicesTable->updateAll(
                        ['pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD],
                        ['id IN' => $toPromote],
                    );
                    foreach ($toPromote as $invoiceId) {
                        $this->grouped->getHistoryService()->recordStatusChange(
                            (int)$invoiceId,
                            InvoiceConstants::STATUS_APROBACION,
                            InvoiceConstants::STATUS_CONTABILIDAD,
                            $userId,
                        );
                    }
                    $this->history->recordFieldChange(
                        (int)$record->id,
                        'invoices_auto_advanced',
                        null,
                        sprintf(
                            'Avance automático a Contabilidad por vinculación a Caja Menor %s (%d facturas)',
                            (string)$record->code,
                            count($toPromote),
                        ),
                        $userId,
                    );
                }

                return true;
            },
        );

        if (!$ok) {
            return !empty($linkErrors) ? $linkErrors : ['No se pudo vincular las facturas.'];
        }

        return [];
    }
```

Actualizar TODOS los callers de `addInvoices` de este service (grep) pasando `(int)$userId` — en `saveAndAdvance` (línea 214) ya hay `$userId`; en `PettyCashRecordsController` usar `(int)$this->_getCurrentUser()->id` (o `$user->id` local). NO tocar `PettyCashService.php` (gemelo muerto).

En `templates/element/link_invoices_modal.php`: nuevo param opcional `$autoAdvanceStatuses` (default `[]`, los demás módulos no cambian). En la celda del `# Factura` de cada candidata (línea ~111), añadir después del número:

```php
<?php if (in_array($c->pipeline_status, $autoAdvanceStatuses ?? [], true)): ?>
<span class="d-block spi-fg-faint" style="font-size:10.5px;font-weight:400;">
    <i class="bi bi-arrow-right-short" aria-hidden="true"></i>Se avanzará a Contabilidad
</span>
<?php endif; ?>
```

Y en el include del edit de PettyCash, añadir los params:

```php
'helpText' => 'Las facturas en Aprobación se avanzarán automáticamente a Contabilidad al vincularse.',
'autoAdvanceStatuses' => [InvoiceConstants::STATUS_APROBACION],
```

- [ ] **Step 4: PASS** + regresión `vendor/bin/phpunit tests/TestCase/ --filter PettyCash`. — [ ] **Step 5: Commit** — `git commit -m "feat: auto-avance transaccional de hijas en aprobacion al vincular a caja menor"`

---

### Task 10: Endpoint `updateDianInline` (POST AJAX)

**Files:**
- Modify: `src/Controller/InvoicesController.php` (nueva action + helper `_readinessForParent`)
- Test: `tests/TestCase/Controller/InvoicesUpdateDianInlineTest.php`

**Interfaces:**
- Consumes: `DocumentTypePolicyFactory::requiresDianFor` (T1), guards `childRequirements` (T6/T7), `InvoiceFieldAccessPolicy`, `PipelineStepConstants::PIPELINE_INVOICES`.
- Produces: `POST /invoices/update-dian-inline/{id}` con body `dian_validation`, `parent_field`, `parent_id`. JSON: `{success, dian_validation, readiness: {dian_pending, support_missing, blocked}}`. Códigos: 404 pertenencia, 409 estado stale, 422 valor/doctype, 403 RBAC.

- [ ] **Step 1: Tests que fallan** (mirror del estilo de `InvoicesControllerTest.php`: factories + `$this->session(['Auth' => $user])` + `enableCsrfToken()`; para el happy path sembrar `permissions` module invoices can_view+can_edit Y `pipeline_permissions` role/`invoices`/`aprobacion`/can_operate=true — patrón de seeding en `tests/TestCase/Controller/AdvanceRefundPaymentTest.php:71-79`):

```php
public function testHappyPathUpdatesDianAndReturnsReadiness(): void
{
    $user = $this->userWithInvoiceAprobacionOperator(); // helper local con los 2 seeds
    $refund = RefundFactory::new()->save();
    $invoice = InvoiceFactory::new([
        'refund_id' => $refund->id,
        'dian_validation' => InvoiceConstants::DIAN_PENDING,
    ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

    $this->session(['Auth' => $user]);
    $this->enableCsrfToken();
    $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']]);
    $this->post('/invoices/update-dian-inline/' . $invoice->id, [
        'dian_validation' => InvoiceConstants::DIAN_APPROVED,
        'parent_field' => 'refund_id',
        'parent_id' => $refund->id,
    ]);

    $this->assertResponseOk();
    $fresh = TableRegistry::getTableLocator()->get('Invoices')->get($invoice->id);
    $this->assertSame(InvoiceConstants::DIAN_APPROVED, $fresh->dian_validation);
    $body = json_decode((string)$this->_response->getBody(), true);
    $this->assertTrue($body['success']);
    $this->assertSame(0, $body['readiness']['dian_pending']);
}

public function testRejectsInvoiceNotBelongingToParent(): void // otro refund_id → 404, dian intacto
public function testRejectsInvoiceOutsideAprobacion(): void    // status contabilidad → 409
public function testRejectsRoleWithoutPipelinePermission(): void // sin pipeline_permissions → 403
public function testRejectsDianExemptDoctype(): void            // ->reciboDeCaja() → 422
```

- [ ] **Step 2: FAIL** (`vendor/bin/phpunit tests/TestCase/Controller/InvoicesUpdateDianInlineTest.php`).

- [ ] **Step 3: Implementar** en `InvoicesController` (junto a `uploadDocument`; imports nuevos: `App\Service\Dto\GroupReadinessReport`, `App\Service\GroupReadinessQuery` no — usar guards: `App\Service\RefundApprovalGuard`, `App\Service\AdvanceLegalizationApprovalGuard`, `App\Service\Pipeline\PettyCash\Guard\PettyCashGuard`, `App\Service\Pipeline\Invoice\DocumentTypePolicyFactory`, `App\ValueObject\UserContext` si falta):

```php
    /**
     * Edición inline de dian_validation desde la tabla de hijas de un registro
     * padre (spec 2026-07-14 §3.4). Mismo gate que la edición directa:
     * can_edit del módulo + canOperate del paso + FieldAccessPolicy.
     */
    #[Permission(action: 'edit')]
    public function updateDianInline($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        // 1. Anti-IDOR: la factura debe pertenecer al padre indicado.
        $parentField = (string)$this->request->getData('parent_field');
        $parentId = (int)$this->request->getData('parent_id');
        if (
            $parentId <= 0
            || !in_array($parentField, InvoiceConstants::PARENT_FOREIGN_KEYS, true)
            || (int)($invoice->{$parentField} ?? 0) !== $parentId
        ) {
            return $this->_jsonResponse(['success' => false, 'error' => 'La factura no pertenece al registro indicado.'], 404);
        }

        // 2. Solo en aprobacion (tabla stale → error explícito, nunca escritura silenciosa).
        if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
            return $this->_jsonResponse(['success' => false, 'error' => 'La factura ya no está en Aprobación. Refresque la página.'], 409);
        }

        $newValue = (string)$this->request->getData('dian_validation');
        if (!in_array($newValue, InvoiceConstants::DIAN_STATUSES, true)) {
            return $this->_jsonResponse(['success' => false, 'error' => 'Valor de validación DIAN inválido.'], 422);
        }
        if (!DocumentTypePolicyFactory::requiresDianFor($invoice->document_type)) {
            return $this->_jsonResponse(['success' => false, 'error' => 'Este tipo de documento no requiere validación DIAN.'], 422);
        }

        // 3. RBAC de pipeline + campo editable para el rol.
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $editable = $this->pipeline->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION);
        $canOperate = $this->authFacade->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        );
        if (!$canOperate || !in_array('dian_validation', $editable, true)) {
            return $this->_jsonResponse(['success' => false, 'error' => 'No tiene permisos para validar DIAN.'], 403);
        }

        // 4. Persistencia + auditoría (dian_validation está en FIELDS_TO_TRACK).
        $old = $invoice->dian_validation;
        $invoice->dian_validation = $newValue;
        if (!$this->Invoices->save($invoice)) {
            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo guardar el cambio.'], 500);
        }
        $this->historyService->recordFieldChange(
            (int)$invoice->id,
            'dian_validation',
            $old,
            $newValue,
            (int)$this->_getCurrentUser()->id,
        );

        $readiness = $this->_readinessForParent($parentField, $parentId);

        return $this->_jsonResponse([
            'success' => true,
            'dian_validation' => $newValue,
            'readiness' => $readiness === null ? null : [
                'dian_pending' => count($readiness->dianPending),
                'support_missing' => count($readiness->supportMissing),
                'blocked' => $readiness->isBlocked(),
            ],
        ]);
    }

    private function _readinessForParent(string $parentField, int $parentId): ?GroupReadinessReport
    {
        return match ($parentField) {
            'refund_id' => (new RefundApprovalGuard())->childRequirements($parentId),
            'petty_cash_record_id' => (new PettyCashGuard())->childRequirements($parentId),
            'advance_id' => (new AdvanceLegalizationApprovalGuard())->childRequirements($parentId),
            default => null,
        };
    }
```

(Verificar que `historyService` y `pipeline` son propiedades existentes del controller — lo son, se usan en `uploadDocument`/`edit`. La ruta la cubre `$builder->fallbacks()` con DashedRoute: `/invoices/update-dian-inline/{id}`.)

- [ ] **Step 4: PASS.** — [ ] **Step 5: Commit** — `git commit -m "feat: endpoint updateDianInline con anti-IDOR, 409 stale y RBAC completo"`

---

### Task 11: Presentation — `SUPPORT_BADGES`, `GroupedInvoiceRowView`, `forGroupedRow()`

**Files:**
- Create: `src/View/Presentation/GroupedInvoiceRowView.php`
- Modify: `src/View/Presentation/InvoicePresentation.php`
- Test: `tests/TestCase/View/Presentation/InvoicePresentationGroupedRowTest.php`

**Interfaces:**
- Produces: `InvoicePresentation::SUPPORT_BADGES` (const `['ok' => 'pill-primary-soft', 'missing' => 'pill-warning-soft', 'na' => 'pill-muted']`); `InvoicePresentation::forGroupedRow(Invoice $invoice, bool $canResolveDian): GroupedInvoiceRowView`. DTO consumido por el element (T12/T13/T14).

- [ ] **Step 1: Test que falla** (unit puro, sin DB — `PHPUnit\Framework\TestCase`):

```php
public function testForGroupedRowSelectModeWhenEditableAndRequired(): void
{
    $invoice = new Invoice([
        'id' => 7, 'invoice_number' => 'F-7', 'amount' => 100,
        'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
        'dian_validation' => InvoiceConstants::DIAN_PENDING,
        'invoice_documents' => [],
    ]);

    $row = InvoicePresentation::forGroupedRow($invoice, canResolveDian: true);

    $this->assertSame('select', $row->dianMode);
    $this->assertFalse($row->supportOk);
    $this->assertSame(0, $row->docsCount);
}

public function testForGroupedRowNaModeForExemptDoctype(): void
{
    $invoice = new Invoice([
        'id' => 8, 'invoice_number' => 'RC-8', 'amount' => 100,
        'document_type' => InvoiceConstants::DOCTYPE_RECIBO_CAJA,
        'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
    ]);

    $this->assertSame('na', InvoicePresentation::forGroupedRow($invoice, true)->dianMode);
}

public function testForGroupedRowPillModeOutsideAprobacionOrWithoutPermission(): void
{
    // status contabilidad + canResolveDian=true → 'pill'; aprobacion + false → 'pill'
}
```

- [ ] **Step 2: FAIL.** — [ ] **Step 3: Implementar.**

`GroupedInvoiceRowView.php`:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

/** DTO de fila para element/grouped_invoices_table (facturas hijas de un padre). */
final readonly class GroupedInvoiceRowView
{
    public function __construct(
        public int $id,
        public string $number,
        public string $providerName,
        public float $amount,
        public ?string $issueDate,
        public string $statusLabel,
        public string $statusPill,
        public string $dianMode, // 'na' | 'select' | 'pill'
        public string $dianValue,
        public string $dianPill,
        public bool $supportRequired,
        public int $docsCount,
        public bool $supportOk,
        public string $childStatus,
    ) {
    }
}
```

En `InvoicePresentation.php` añadir la const y el factory:

```php
    /** Badge del estado de soporte en la tabla de hijas. */
    public const SUPPORT_BADGES = [
        'ok'      => 'pill-primary-soft',
        'missing' => 'pill-warning-soft',
        'na'      => 'pill-muted',
    ];

    /**
     * DTO de fila para la tabla de facturas hijas dentro de la vista del padre.
     * Requiere que el caller haya contenido Providers e InvoiceDocuments.
     */
    public static function forGroupedRow(Invoice $invoice, bool $canResolveDian): GroupedInvoiceRowView
    {
        $status = $invoice->pipeline_status ?? '';
        $documentType = $invoice->document_type ?? null;
        $requiresDian = DocumentTypePolicyFactory::requiresDianFor($documentType);
        $dianValue = $invoice->dian_validation ?? InvoiceConstants::DIAN_PENDING;

        $dianMode = 'pill';
        if (!$requiresDian) {
            $dianMode = 'na';
        } elseif ($canResolveDian && $status === InvoiceConstants::STATUS_APROBACION) {
            $dianMode = 'select';
        }

        $docsCount = count($invoice->invoice_documents ?? []);
        $supportRequired = DocumentTypePolicyFactory::requiresSupportFor($documentType);

        return new GroupedInvoiceRowView(
            id: (int)$invoice->id,
            number: (string)($invoice->invoice_number ?: '#' . $invoice->id),
            providerName: $invoice->hasValue('provider') ? (string)$invoice->provider->name : '—',
            amount: (float)$invoice->amount,
            issueDate: $invoice->issue_date?->format('d/m/Y'),
            statusLabel: InvoiceConstants::STATUS_LABELS[$status] ?? $status,
            statusPill: self::STATUS_BADGES[$status] ?? 'pill-muted',
            dianMode: $dianMode,
            dianValue: $dianValue,
            dianPill: self::DIAN_BADGES[$dianValue] ?? 'pill-muted',
            supportRequired: $supportRequired,
            docsCount: $docsCount,
            supportOk: !$supportRequired || $docsCount > 0,
            childStatus: $status,
        );
    }
```

(+ import `App\Service\Pipeline\Invoice\DocumentTypePolicyFactory`.)

- [ ] **Step 4: PASS.** — [ ] **Step 5: Commit** — `git commit -m "feat: forGroupedRow + SUPPORT_BADGES en InvoicePresentation"`

---

### Task 12: Element compartido + JS + integración en Reintegro

**Files:**
- Create: `webroot/js/spi-grouped-invoices.js`
- Create: `templates/element/grouped_invoices_table.php`
- Modify: `src/ViewModel/RefundViewViewModel.php`
- Modify: `src/Controller/RefundsController.php:215-234` (action `view`)
- Modify: `templates/Refunds/view.php:118-160`
- Test: render smoke vía `tests/TestCase/Controller/` (nuevo `RefundsViewGroupedTableTest.php`)

**Interfaces:**
- Consumes: `GroupedInvoiceRowView`/`forGroupedRow` (T11), `GroupReadinessReport` (T5), guard (T6), endpoint (T10), `element('upload_doc_modal')`, `SpiToast`.
- Produces: `element('grouped_invoices_table', [...])` con params: `rows` (list<GroupedInvoiceRowView>), `readiness` (?GroupReadinessReport), `parentField` (string), `parentId` (int), `canUploadSupport` (bool), `uploadModalId` (?string), `title` (string, default 'Facturas Agrupadas'). El VM de Refund expone `groupedRows`, `readiness`, `canResolveDian`, `canUploadSupport`.

- [ ] **Step 1: JS** — crear `webroot/js/spi-grouped-invoices.js`:

```js
/**
 * SpiGroupedInvoices — acciones inline en la tabla de facturas hijas de un
 * registro padre (Reintegro / Caja Menor / Anticipo). Spec 2026-07-14 §3.4.
 *
 * - Select DIAN por fila → POST /invoices/update-dian-inline/{id} (JSON),
 *   toast + actualización del checklist en sitio.
 * - Botón de soporte por fila → abre el upload_doc_modal compartido apuntando
 *   el form al uploadDocument de esa hija; tras subir OK recarga la página.
 */
(function (global) {
    'use strict';

    function toast(msg, variant) {
        if (global.SpiToast) { global.SpiToast.show(msg, variant || 'danger'); return; }
        global.alert(msg);
    }

    function updateChecklist(root, readiness) {
        if (!readiness) return;
        var box = root.querySelector('[data-grouped-checklist]');
        if (!box) return;
        var dian = box.querySelector('[data-slot="dian-pending"]');
        var support = box.querySelector('[data-slot="support-missing"]');
        if (dian) dian.textContent = readiness.dian_pending + ' con DIAN pendiente';
        if (support) support.textContent = readiness.support_missing + ' sin soporte';
        box.style.display = readiness.blocked ? '' : 'none';
    }

    function init(opts) {
        var root = document.querySelector(opts.rootSelector);
        if (!root) return;
        var csrfToken = opts.csrfToken || '';

        root.addEventListener('change', function (e) {
            var select = e.target.closest('.grouped-dian-select');
            if (!select) return;

            var body = new FormData();
            body.append('dian_validation', select.value);
            body.append('parent_field', root.dataset.parentField);
            body.append('parent_id', root.dataset.parentId);
            select.disabled = true;

            fetch('/invoices/update-dian-inline/' + select.dataset.invoiceId, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                },
                body: body
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (res.ok && res.data.success) {
                    toast('Validación DIAN actualizada.', 'success');
                    updateChecklist(root, res.data.readiness);
                    var row = select.closest('tr');
                    if (row && res.data.readiness) {
                        var warn = row.querySelector('[data-slot="row-warn"]');
                        if (warn && select.value === select.dataset.approvedValue) warn.style.display = 'none';
                    }
                } else {
                    toast(res.data.error || 'No se pudo actualizar.', 'danger');
                    select.value = select.dataset.currentValue;
                }
            })
            .catch(function () {
                toast('Error de conexión. Intente nuevamente.', 'danger');
                select.value = select.dataset.currentValue;
            })
            .finally(function () {
                select.disabled = false;
                select.dataset.currentValue = select.value;
            });
        });

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('.grouped-upload-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();

            var form = opts.uploadFormSelector ? document.querySelector(opts.uploadFormSelector) : null;
            var modalEl = opts.uploadModalSelector ? document.querySelector(opts.uploadModalSelector) : null;
            if (!form || !modalEl || !global.bootstrap) return;
            form.dataset.url = btn.dataset.uploadUrl;
            global.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });

        var form = opts.uploadFormSelector ? document.querySelector(opts.uploadFormSelector) : null;
        if (form && !form.dataset.groupedBound) {
            form.dataset.groupedBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fileInput = form.querySelector('input[type="file"]');
                if (!fileInput || !fileInput.files.length) return;

                fetch(form.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: new FormData(form)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        global.location.reload();
                    } else {
                        toast(data.error || 'Error al subir el archivo.', 'danger');
                    }
                })
                .catch(function () { toast('Error de conexión. Intente nuevamente.', 'danger'); });
            });
        }
    }

    global.SpiGroupedInvoices = { init: init };
})(window);
```

- [ ] **Step 2: Element** — crear `templates/element/grouped_invoices_table.php`:

```php
<?php
/**
 * Card "Facturas Agrupadas" de la vista de un registro padre, con acciones
 * inline de DIAN y soporte por fila (spec 2026-07-14 §3.4). Los mapeos
 * visuales salen de InvoicePresentation (anti-drift, cero literales aquí).
 *
 * @var \App\View\AppView $this
 * @var list<\App\View\Presentation\GroupedInvoiceRowView> $rows
 * @var \App\Service\Dto\GroupReadinessReport|null $readiness
 * @var string $parentField  FK de contención (refund_id | petty_cash_record_id | advance_id)
 * @var int $parentId
 * @var bool $canUploadSupport
 * @var string|null $uploadModalId  id del upload_doc_modal incluido por la página
 * @var string $title
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$title = $title ?? 'Facturas Agrupadas';
$readiness = $readiness ?? null;
$canUploadSupport = $canUploadSupport ?? false;
$uploadModalId = $uploadModalId ?? null;
$rootId = 'grouped-invoices-' . h($parentField);
$blockedIds = $readiness === null
    ? []
    : array_unique(array_merge(array_keys($readiness->dianPending), array_keys($readiness->supportMissing)));
?>
<div class="spi-card" id="<?= $rootId ?>" data-parent-field="<?= h($parentField) ?>" data-parent-id="<?= (int)$parentId ?>">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom:14px;">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <?= h($title) ?>
            <span class="spi-folder-count"><?= count($rows) ?></span>
        </span>
    </div>

    <?php if ($readiness !== null): ?>
    <div class="d-flex align-items-center gap-2" data-grouped-checklist
         style="margin-bottom:12px;padding:10px 14px;background:var(--bg-subtle);border:1px solid var(--rule);font-size:12px;<?= $readiness->isBlocked() ? '' : 'display:none;' ?>">
        <i class="bi bi-exclamation-triangle" aria-hidden="true" style="color:var(--secondary-color, #CD6A15);"></i>
        <span data-slot="dian-pending"><?= count($readiness->dianPending) ?> con DIAN pendiente</span>
        <span aria-hidden="true">·</span>
        <span data-slot="support-missing"><?= count($readiness->supportMissing) ?> sin soporte</span>
    </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="es-icon es-icon-neutral"><i class="bi bi-inbox" aria-hidden="true"></i></div>
            <div class="es-title">No hay facturas agrupadas</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Proveedor</th>
                        <th style="text-align:right;">Monto</th>
                        <th>Fecha Emisión</th>
                        <th>Estado</th>
                        <th>DIAN</th>
                        <th>Soporte</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $row->id]) ?>">
                        <td class="mono" style="font-weight:600;">
                            <?php if (in_array($row->id, $blockedIds, true)): ?>
                            <i class="bi bi-exclamation-triangle" data-slot="row-warn" aria-hidden="true"
                               style="color:var(--secondary-color, #CD6A15);margin-right:4px;" title="Requisitos pendientes"></i>
                            <?php endif; ?>
                            <?= h($row->number) ?>
                        </td>
                        <td><?= h($row->providerName) ?></td>
                        <td class="mono" style="text-align:right;">$ <?= number_format($row->amount, 0, ',', '.') ?></td>
                        <td class="mono"><?= h($row->issueDate ?? '—') ?></td>
                        <td><span class="pill pill-sm <?= h($row->statusPill) ?>"><?= h($row->statusLabel) ?></span></td>
                        <td onclick="event.stopPropagation();">
                            <?php if ($row->dianMode === 'na'): ?>
                                <span class="spi-fg-faint" style="font-size:12px;">No aplica</span>
                            <?php elseif ($row->dianMode === 'select'): ?>
                                <select class="form-select form-select-sm grouped-dian-select" style="max-width:130px;"
                                        data-invoice-id="<?= $row->id ?>"
                                        data-current-value="<?= h($row->dianValue) ?>"
                                        data-approved-value="<?= h(InvoiceConstants::DIAN_APPROVED) ?>">
                                    <?php foreach (InvoiceConstants::DIAN_STATUSES as $opt): ?>
                                    <option value="<?= h($opt) ?>" <?= $opt === $row->dianValue ? 'selected' : '' ?>><?= h($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="pill pill-sm <?= h($row->dianPill) ?>"><?= h($row->dianValue) ?></span>
                            <?php endif; ?>
                        </td>
                        <td onclick="event.stopPropagation();">
                            <?php
                            $supportKind = !$row->supportRequired ? 'na' : ($row->supportOk ? 'ok' : 'missing');
                            $supportPill = InvoicePresentation::SUPPORT_BADGES[$supportKind];
                            ?>
                            <span class="d-inline-flex align-items-center gap-1">
                                <?php // El badge enlaza a la vista de la factura (spec §3.4: ver/borrar documentos se hace allá). ?>
                                <a href="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'view', $row->id]) ?>"
                                   style="text-decoration:none;" title="Ver documentos de la factura">
                                <?php if ($supportKind === 'na'): ?>
                                    <span class="pill pill-sm <?= h($supportPill) ?>">N/A</span>
                                <?php elseif ($supportKind === 'ok'): ?>
                                    <span class="pill pill-sm <?= h($supportPill) ?>"><i class="bi bi-check2" aria-hidden="true"></i> <?= $row->docsCount ?></span>
                                <?php else: ?>
                                    <span class="pill pill-sm <?= h($supportPill) ?>">Falta</span>
                                <?php endif; ?>
                                </a>
                                <?php if ($canUploadSupport && $uploadModalId !== null): ?>
                                <button type="button" class="btn btn-icon btn-sm grouped-upload-btn" title="Subir soporte"
                                        data-upload-url="<?= $this->Url->build(['controller' => 'Invoices', 'action' => 'uploadDocument', $row->id]) ?>">
                                    <i class="bi bi-upload" aria-hidden="true"></i>
                                </button>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?= $this->Html->script('spi-grouped-invoices', ['block' => true]) ?>
<?php $this->Html->scriptBlock(sprintf(
    "document.addEventListener('DOMContentLoaded',function(){SpiGroupedInvoices.init({rootSelector:'#%s',csrfToken:%s,uploadFormSelector:%s,uploadModalSelector:%s});});",
    $rootId,
    json_encode((string)$this->request->getAttribute('csrfToken')),
    json_encode($uploadModalId !== null ? '#grouped-upload-form' : null),
    json_encode($uploadModalId !== null ? '#' . $uploadModalId : null),
), ['block' => true]); ?>
```

- [ ] **Step 3: VM.** `RefundViewViewModel.php` — constructor pasa a:

```php
    /** @var list<\App\View\Presentation\GroupedInvoiceRowView> */
    public array $groupedRows;

    public function __construct(
        public Refund $record,
        public ?GroupReadinessReport $readiness = null,
        bool $canResolveDian = false,
        public bool $canUploadSupport = false,
    ) {
```

y al final del constructor:

```php
        $rows = [];
        foreach ($record->invoices ?? [] as $inv) {
            $rows[] = InvoicePresentation::forGroupedRow($inv, $canResolveDian);
        }
        $this->groupedRows = $rows;
```

(+ imports `App\Service\Dto\GroupReadinessReport`, `App\View\Presentation\InvoicePresentation`.)

- [ ] **Step 4: Controller.** `RefundsController::view()`: contain `'Invoices' => ['Providers', 'InvoiceDocuments']`; luego:

```php
        $roleId = (int)$this->_getCurrentUser()->role_id;
        $context = new UserContext($roleId);
        $canOperateAprobacion = $this->authFacade->canOperate(
            $context,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        );
        $canEditInvoices = $this->_checkPermission('invoices', 'edit');
        $fieldPolicy = new InvoiceFieldAccessPolicy($this->authFacade);
        $canResolveDian = $canOperateAprobacion && $canEditInvoices
            && in_array('dian_validation', $fieldPolicy->getEditableFields($roleId, InvoiceConstants::STATUS_APROBACION), true);

        $this->set('viewModel', new RefundViewViewModel(
            $record,
            (new RefundApprovalGuard())->childRequirements((int)$record->id),
            $canResolveDian,
            canUploadSupport: $canOperateAprobacion && $canEditInvoices,
        ));
```

(+ imports necesarios. `canUploadSupport` usa `canOperate(aprobacion)` como proxy del gate real de `uploadDocument` — las hijas de un reintegro viven en `aprobacion`; el server-side sigue siendo la autoridad.)

- [ ] **Step 5: Template.** En `templates/Refunds/view.php` reemplazar el bloque completo `<!-- Facturas agrupadas -->` (líneas 118-160, el `<div class="card">` entero) por:

```php
        <?= $this->element('grouped_invoices_table', [
            'rows' => $viewModel->groupedRows,
            'readiness' => $viewModel->readiness,
            'parentField' => 'refund_id',
            'parentId' => (int)$record->id,
            'canUploadSupport' => $viewModel->canUploadSupport,
            'uploadModalId' => $viewModel->canUploadSupport ? 'groupedUploadModal' : null,
        ]) ?>
```

y al final del archivo (fuera del grid), si `$viewModel->canUploadSupport`:

```php
<?php if ($viewModel->canUploadSupport): ?>
<?= $this->element('upload_doc_modal', [
    'modalId' => 'groupedUploadModal',
    'uploadUrl' => '', // la fija SpiGroupedInvoices por fila
    'formId' => 'grouped-upload-form',
    'showDocumentType' => true,
]) ?>
<?php endif; ?>
```

Quitar imports que queden sin uso en el template (`InvoicePresentation`/`InvoiceConstants` solo si ya no se usan en otras partes del archivo — verificar antes de borrar).

- [ ] **Step 6: Smoke test** `RefundsViewGroupedTableTest.php`: usuario con can_view refunds → `GET /refunds/view/{id}` de un refund con 1 hija → 200 y el body contiene `grouped-invoices-refund_id`. (Seeding de permissions igual que otros tests de controller.)

- [ ] **Step 7: Verificar** — `php -l` de los 2 templates nuevos/modificados, test smoke verde, y regresión `vendor/bin/phpunit tests/TestCase/Controller/ --filter Refund`. **Step 8: Commit** — `git commit -m "feat: tabla de hijas con DIAN/soporte inline y checklist en la vista de Reintegro"`

---

### Task 13: Integración en Caja Menor (solo soporte)

**Files:**
- Modify: `src/ViewModel/PettyCashViewViewModel.php`
- Modify: `src/Controller/PettyCashRecordsController.php:173-192` (action `view`)
- Modify: `templates/PettyCashRecords/view.php:278-325`

**Interfaces:**
- Consumes: element/JS (T12), `PettyCashGuard` (T7), `forGroupedRow` (T11).

- [ ] **Step 1: VM** — mismo patrón que Task 12 Step 3 sobre `PettyCashViewViewModel` (`?GroupReadinessReport $readiness = null`, `bool $canUploadSupport = false`, `groupedRows` con `canResolveDian: false` — las hijas de caja menor están fuera de `aprobacion`, el DIAN sale como pill/N-A informativo).

- [ ] **Step 2: Controller** — en `view()`: contain `'Invoices' => ['Providers', 'InvoiceDocuments']`; readiness = `(new PettyCashGuard())->childRequirements((int)$record->id)`; `canUploadSupport = $this->authFacade->canOperate($context, PipelineStepConstants::PIPELINE_INVOICES, InvoiceConstants::STATUS_CONTABILIDAD) && $this->_checkPermission('invoices', 'edit')` (hijas de caja menor viven en `contabilidad`).

- [ ] **Step 3: Template** — reemplazar el bloque `<!-- Facturas agrupadas -->` (líneas 278-325) por el mismo include del element con `'parentField' => 'petty_cash_record_id'` y el modal al final (ídem Task 12 Step 5). El checklist de PettyCash solo mostrará soporte (su `dianPending` siempre es `[]` por `includeDian: false`) — el markup del element ya lo tolera (muestra "0 con DIAN pendiente" solo cuando está bloqueado por soporte; aceptable).

- [ ] **Step 4: Test** — crear `tests/TestCase/Controller/PettyCashViewGroupedTableTest.php`, espejo exacto del smoke de Task 12 Step 6: usuario con can_view de `petty_cash` → `GET /petty-cash-records/view/{id}` de un record con 1 hija → 200 y el body contiene `grouped-invoices-petty_cash_record_id`.
- [ ] **Step 5: Verificar** — `php -l` del template; test nuevo verde; regresión `vendor/bin/phpunit tests/TestCase/ --filter PettyCash`. **Step 6: Commit** — `git commit -m "feat: tabla de hijas con soporte inline y checklist en la vista de Caja Menor"`

---

### Task 14: Anticipo — celdas DIAN/Soporte en `_linked_invoices`

**Files:**
- Modify: `templates/element/advance_legalization/_linked_invoices.php`
- Modify: los 2 include-sites: `templates/Advances/legalization.php:188` y `templates/Advances/view.php:213`
- Modify: `src/ViewModel/AdvanceLegalizationViewModel.php` + el controller que lo construye (localizar con `grep -n "AdvanceLegalizationViewModel(" src/Controller/`)

**Interfaces:**
- Consumes: `forGroupedRow` (T11), `AdvanceLegalizationApprovalGuard::childRequirements` (T6), endpoint (T10), JS `spi-grouped-invoices.js` (T12).
- Produces: `_linked_invoices.php` acepta params opcionales `readiness` (?GroupReadinessReport, default null), `canResolveDian` (bool, default false), `canUploadSupport` (bool, default false), `uploadModalId` (?string, default null). El element bespoke NO se fusiona con `grouped_invoices_table` (canon CLAUDE.md).

- [ ] **Step 1: Contains.** En `src/Controller/AdvancesController.php` hay exactamente 2 queries de `$linkedInvoices`: la del hub `view()` (líneas 376-381) y la de `_buildLegalizationViewModel()` (líneas 454-459). En AMBAS, cambiar `->contain(['Providers', 'Employees'])` por `->contain(['Providers', 'Employees', 'InvoiceDocuments'])` (docsCount del badge).

- [ ] **Step 2: VM + controller (vista operativa).** `AdvanceLegalizationViewModel` (constructor con named params + método `build()` que arma las vars del template):
  - Nuevos params al final del constructor: `public ?GroupReadinessReport $childReadiness = null, public bool $canResolveDianChildren = false, public bool $canUploadChildSupport = false` (+ import del DTO).
  - En `build()`, añadir al array retornado: `'childReadiness' => $this->childReadiness, 'canResolveDianChildren' => $this->canResolveDianChildren, 'canUploadChildSupport' => $this->canUploadChildSupport,`.
  - En `AdvancesController::_buildLegalizationViewModel()` (:476-507), computar antes del `return` — patrón idéntico a Task 12 Step 4 (`$canOperateAprobacion` sobre `PIPELINE_INVOICES`/`STATUS_APROBACION` de invoices, `$this->_checkPermission('invoices', 'edit')`, `InvoiceFieldAccessPolicy::getEditableFields` contiene `dian_validation`) — y pasar:

```php
            childReadiness: (new AdvanceLegalizationApprovalGuard())->childRequirements((int)$invoice->id),
            canResolveDianChildren: $canResolveDian,
            canUploadChildSupport: $canOperateAprobacion && $canEditInvoices,
```

  (Las hijas del anticipo tienen `advance_id = $invoice->id` — ver la query de la línea 457 — y viven en `aprobacion` hasta `moveToRevisionFirmas`.)

- [ ] **Step 3: Element `_linked_invoices.php`.** Al header de params añadir:

```php
$readiness = $readiness ?? null;
$canResolveDian = $canResolveDian ?? false;
$canUploadSupport = $canUploadSupport ?? false;
$uploadModalId = $uploadModalId ?? null;
$blockedIds = $readiness === null
    ? []
    : array_unique(array_merge(array_keys($readiness->dianPending), array_keys($readiness->supportMissing)));
```

Ampliar `$liGrid` a `grid-template-columns:1.1fr 1.8fr 0.9fr 1fr 1.2fr 1fr 0.9fr 32px;`; añadir headers `<span>DIAN</span><span>Soporte</span>` tras `<span>Estado</span>`. Al `.spi-card` raíz: `id="grouped-invoices-advance_id" data-parent-field="advance_id" data-parent-id="<?= (int)$leg->advance_invoice_id ?>"`. Por fila, computar `$rowView = InvoicePresentation::forGroupedRow($li, $canResolveDian);` al inicio del foreach, marcar la celda `# Factura` con el icono `data-slot="row-warn"` si `in_array($li->id, $blockedIds, true)` (mismo markup de Task 12), e insertar ANTES del span del botón desvincular las dos celdas DIAN/Soporte con **exactamente el mismo markup interno** del element de Task 12 (select `.grouped-dian-select` con `data-invoice-id`/`data-current-value`/`data-approved-value` / pill / "No aplica"; link+pill de soporte + botón `.grouped-upload-btn` con `data-upload-url`), cada una como `<span onclick="event.stopPropagation();">` (el element usa grid de spans, no `<td>`). Al final del element, el mismo bloque script include + init de Task 12 con `rootSelector: '#grouped-invoices-advance_id'` y `uploadModalId` (json_encode con null-guard idéntico).

- [ ] **Step 4: Include-sites.**
  - `templates/Advances/legalization.php:188-195`: añadir al array del element `'readiness' => $childReadiness, 'canResolveDian' => $canResolveDianChildren, 'canUploadSupport' => $canUploadChildSupport, 'uploadModalId' => $canUploadChildSupport ? 'advanceGroupedUploadModal' : null,` y, al final del template, el include de `upload_doc_modal` (modalId `advanceGroupedUploadModal`, formId `grouped-upload-form`, `showDocumentType => true`) gateado por `$canUploadChildSupport` — mismo bloque de Task 12 Step 5.
  - `templates/Advances/view.php:213`: NO añadir params nuevos (defaults → celdas en modo lectura: pill DIAN o "No aplica", badge de soporte con conteo, sin select ni botón).

- [ ] **Step 5: Test.** En `tests/TestCase/Controller/AdvancesLegalizationRenderTest.php` añadir un assert al render de la vista operativa: `$this->assertResponseContains('data-parent-field="advance_id"');` y, con una hija Recibo de Caja en el fixture, `$this->assertResponseContains('No aplica');`. Ajustar asserts existentes si el markup del grid los rompe.

- [ ] **Step 6: Verificar** — `php -l` de los 3 templates; `vendor/bin/phpunit tests/TestCase/Controller/AdvancesLegalizationRenderTest.php` → verde. **Step 7: Commit** — `git commit -m "feat: DIAN/soporte inline en facturas vinculadas de la legalizacion de anticipo"`

---

### Task 15: Verificación final

**Files:** — (solo verificación y ajustes menores)

- [ ] **Step 1:** `composer cs-check` → sin errores (usar `composer cs-fix` si hace falta).
- [ ] **Step 2:** Suite completa `vendor/bin/phpunit` (timeout 600s). Recordar: exit 1 con solo notices es el baseline verde — comparar failures/errors contra 0. Si hay cascadas raras, re-correr la suite limpia antes de concluir regresión (contaminación back-to-back conocida).
- [ ] **Step 3:** `grep -rn "getTransitionRules\|childInvoicesFailingDian" src/ tests/ templates/` → 0 resultados.
- [ ] **Step 4:** Smoke manual con `php bin/cake server`: (a) Recibo de Caja suelto en `aprobacion` con soporte avanza sin DIAN; (b) Factura suelta sin soporte NO avanza; (c) vista de un Reintegro muestra checklist y el select DIAN guarda con toast; (d) vincular factura en `aprobacion` a Caja Menor la deja en `contabilidad` con historial.
- [ ] **Step 5:** Actualizar CLAUDE.md si el resumen de servicios quedó desactualizado por este cambio (p. ej. mencionar `Guard/` de Invoice/PettyCash). Commit final: `git commit -m "chore: verificacion final requisitos condicionales por doctype"`

---

## Self-review del plan (hecho, + revisión spi-plan-reviewer incorporada)

- **Cobertura spec→tasks:** §3.1→T1; §3.2→T2,T3,T4 (contrato keyed + pass-through: T4; T3+T4 commit atómico); §3.3→T5,T6,T7; §3.4→T10,T11,T12,T13,T14; §3.5→T8,T9; §4 casos borde→tests de T5 (RC exento), T6 Step 3b (flujo real bloqueado/desbloqueado por soporte + RC exento en Anticipo), T9, T10 (409 stale, anti-IDOR); §5→tests por task; §6 fuera de alcance respetado.
- **Desviaciones documentadas respecto al spec:** flags estáticos (con test guard anti-drift `POLICY_CLASSES`↔`byType` en T1) y eliminación de `getTransitionRules`; ambas conservan la semántica aprobada (validadas por spi-plan-reviewer).
- **Regresiones de tests de integración existentes contempladas:** `RefundGroupApprovalFlowTest`/`AdvanceGroupApprovalFlowTest`/`RefundPipelineServiceTest` (T6 Step 3b) y `Integration/PettyCashPipelineServiceTest` (T7/T9) se adaptan sembrando `InvoiceDocumentFactory` en la misma task que introduce el gate — no se difieren a la suite completa de T15.
- **Consistencia de tipos verificada:** `childRequirements` devuelve `GroupReadinessReport` en los 3 guards; `forGroupedRow(Invoice, bool)` en T11 = usado en T12/T13/T14; `addInvoices(record, ids, int $userId)` en T9 = callers actualizados en la misma task (incluidos `PettyCashRecordsController.php:221,576`).
- **Proxy documentado:** `canUploadSupport` en las vistas del padre es más estricto que el gate real de `uploadDocument` (`_documentGate` solo exige canOperate del paso); oculta el botón a algún rol que el server aceptaría — deliberado, el server sigue siendo la autoridad.
