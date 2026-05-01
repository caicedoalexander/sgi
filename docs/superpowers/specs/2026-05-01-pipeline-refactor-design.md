# Plan 4 — Refactor del Pipeline (C5 + W2 + W9 + W10)

**Plan del roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · **Plan #4**
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
**Fecha:** 2026-05-01
**Tamaño estimado:** 1–2 semanas

---

## Resumen

`InvoicePipelineService` tiene 768 LOC y cohabita cinco responsabilidades: state machine encoded como arrays + match chains, validación de transición + filtrado por rol, lock policy (petty cash y paid scheduling), branches por `document_type` repetidos en 4 servicios, y la orquestación transaccional `saveAndAdvance` con side-effects cross-aggregate. Esto rompe SRP, viola OCP cada vez que se agrega un estado o un doctype, y duplica la verdad de la transición en `Invoice::canAdvanceTo()` (entidad) versus `InvoicePipelineService::canAdvance()` (servicio).

Este plan resuelve los items **C5** (god-service), **W2** (duplicación entity vs servicio), **W9** (state machine procedural) y **W10** (document-type conditionals dispersos) reorganizando el pipeline en cuatro colaboradores con responsabilidad única, manteniendo la API pública del coordinador para no romper callers.

---

## Decisiones de diseño tomadas en brainstorming

1. **Alcance vertical: refactor completo de Invoice (opción A).** Las cuatro extracciones del roadmap se hacen juntas. `NoveltyPipelineService` y `PaymentSchedulingPipelineService` quedan fuera del alcance — comparten el patrón pero son menos complejos y no urgen. Si después de Plan 4 se quiere replicar el refactor allá, se decide en un plan separado.

2. **Alcance horizontal: sólo Invoice (opción A del scope).** No se toca `NoveltyPipelineService` ni `PaymentSchedulingPipelineService` aunque tengan el mismo antipatrón.

3. **DocumentTypePolicy: 3 clases con default `Standard` (opción B).** `StandardDocumentTypePolicy` es el caso por defecto para los 6 doctypes que no tienen comportamiento especial (Factura, Nota Débito, Caja Menor, Tarjeta de Crédito, Reintegro, Recibo). `AnticipoDocumentTypePolicy` y `LegalizacionDocumentTypePolicy` modelan los dos doctypes con reglas propias. El factory siempre devuelve una policy concreta — el coordinador nunca verifica `null`.

4. **State pattern: 6 clases incluyendo `LegalizadaState` (opción A).** `legalizada` es estado terminal exclusivo de Legalización (no se alcanza por avance manual del flujo normal). Se modela como clase propia para mantener el criterio "agregar un estado nuevo toca ≤ 2 archivos" y para evitar ramas `if ($status === 'legalizada')` en futuros consumidores.

5. **`legalizeLinkedInvoices` se queda en el coordinador (opción A).** Es operación cross-aggregate disparada por `AdvanceLegalizationService`. Mover ahora agrega scope sin terminar de romper el ciclo Pipeline ↔ Legalization (Payment ↔ Legalization sigue). Plan 5 (Domain Events) la moverá con la infraestructura adecuada.

6. **`Invoice::canAdvanceTo()` se elimina (opción A).** Verificado: cero callers en el repo. La duplicación con el servicio se resuelve trivialmente borrando el método muerto.

7. **`saveAndAdvance` mantiene retorno `array` (opción B).** W15 (estandarización a `ServiceResult`) queda para Plan 7. Cambiar el shape ahora suma scope y oscurece la métrica de "≤ 300 LOC en el coordinador".

8. **Validación manual a criterio del usuario.** El proyecto no usa tests automatizados (CLAUDE.md). El spec lista los flujos que el refactor toca como referencia, no como checklist obligatorio. El usuario ejercita los flujos en navegador a su criterio.

---

## Alcance

### Lo que entra

- Extraer `InvoiceLockPolicy` con los 3 lock checks (`isLockedByPettyCash`, `isLockedByPaidScheduling`, `getEditLockMessage`, `getRegressionLockMessage` para reglas no-doctype).
- Extraer `InvoiceTransitionValidator` con orquestación de validación de avance (rejection + doctype block + state validation) y filtrado de errores por rol.
- Crear interfaz `InvoicePipelineState` y 6 clases concretas (`AprobacionState`, `ContabilidadState`, `TesoreriaState`, `AutorizacionPagoState`, `PagadaState`, `LegalizadaState`).
- Crear `InvoicePipelineStateRegistry` que mapea `pipeline_status` → instancia.
- Crear interfaz `DocumentTypePolicy` y 3 clases (`StandardDocumentTypePolicy`, `AnticipoDocumentTypePolicy`, `LegalizacionDocumentTypePolicy`) más `DocumentTypePolicyFactory`.
- Refactorizar `InvoicePipelineService` para delegar todo a los 4 nuevos colaboradores, manteniendo la API pública.
- Migrar `InvoicePaymentService` a usar `DocumentTypePolicy` para `is_refund` validation y auto-init de legalización.
- Cambiar firma de `InvoiceFieldAccessPolicy::getVisibleSections` (eliminar `?string $documentType`); el filtrado por doctype lo hace el coordinador vía `DocumentTypePolicy::filterVisibleSections`.
- Eliminar `Invoice::canAdvanceTo()` y su PHPDoc (W2).
- Registrar todos los componentes nuevos en `Application::services()`.

### Lo que NO entra

- Refactor de `NoveltyPipelineService` y `PaymentSchedulingPipelineService` (mismo patrón, fuera de scope).
- Romper el ciclo Pipeline ↔ Payment ↔ Legalization (Plan 5: C6 — Domain Events).
- Migrar `saveAndAdvance` y `regress` a `ServiceResult` (Plan 7: W15).
- Migrar `Cake\Log\Log::*` a `StructuredLogger` inyectado (Plan 7: W1).
- Mover `EDITABLE_FIELDS` y `VISIBLE_SECTIONS_BY_ROLE` desde `InvoiceFieldAccessPolicy` a los States (no necesario para cumplir las metas del plan).
- Mover `legalizeLinkedInvoices` a un servicio separado (Plan 5).
- Cambiar `STATUS_LABELS`, `STATUS_ICONS`, `STATUSES`, `ALL_STATUSES`, `TRANSITIONS` (constantes públicas que consumen controllers y templates) — se mantienen en `InvoicePipelineService` sin tocar.

---

## Arquitectura

```
InvoicePipelineService (coordinador, ≤ 300 LOC)
  │
  ├─→ InvoicePipelineStateRegistry  ──→  6 × InvoicePipelineState   (W9)
  ├─→ DocumentTypePolicyFactory     ──→  3 × DocumentTypePolicy     (W10)
  ├─→ InvoiceLockPolicy                                              (C5)
  ├─→ InvoiceTransitionValidator                                     (C5)
  │
  ├─→ InvoiceFieldAccessPolicy   (sin cambios estructurales)
  ├─→ InvoiceHistoryService      (sin cambios)
  ├─→ InvoicePaymentService      (recibe nueva dep DocumentTypePolicyFactory)
  └─→ AdvanceLegalizationService (sin cambios)
```

### Árbol de archivos resultante

```
src/Service/
├── InvoicePipelineService.php                       (refactorizado ≤ 300 LOC)
├── InvoiceLockPolicy.php                            (NUEVO)
├── InvoiceTransitionValidator.php                   (NUEVO)
├── InvoiceFieldAccessPolicy.php                     (firma de getVisibleSections cambia)
├── InvoicePaymentService.php                        (recibe DocumentTypePolicyFactory)
└── Pipeline/
    ├── InvoicePipelineState.php                     (NUEVO interfaz)
    ├── InvoicePipelineStateRegistry.php             (NUEVO)
    ├── DocumentTypePolicy.php                       (NUEVO interfaz)
    ├── DocumentTypePolicyFactory.php                (NUEVO factory)
    ├── State/
    │   ├── AprobacionState.php                      (NUEVO)
    │   ├── ContabilidadState.php                    (NUEVO)
    │   ├── TesoreriaState.php                       (NUEVO)
    │   ├── AutorizacionPagoState.php                (NUEVO)
    │   ├── PagadaState.php                          (NUEVO)
    │   └── LegalizadaState.php                      (NUEVO)
    └── Policy/
        ├── StandardDocumentTypePolicy.php           (NUEVO, default)
        ├── AnticipoDocumentTypePolicy.php           (NUEVO)
        └── LegalizacionDocumentTypePolicy.php       (NUEVO)

src/Model/Entity/
└── Invoice.php  (canAdvanceTo() eliminado, W2)
```

**Total nuevo:** 15 archivos (2 policies extraídas + interfaz/registry/factory de pipeline + 6 estados + 3 doctype policies). **Eliminado:** un método en `Invoice` (W2).

---

## Componentes nuevos

### `InvoicePipelineState` (interfaz)

```php
namespace App\Service\Pipeline;

interface InvoicePipelineState
{
    public function getName(): string;
    public function getNext(): ?string;
    public function getPrevious(): ?string;
    public function getRoleVisibility(): array;
    public function getAdvanceRoleVisibility(): array;
    public function validateAdvance(\App\Model\Entity\Invoice $invoice): array;
    public function getTransitionRules(): array;
}
```

**Responsabilidad:** representar un estado del pipeline. Cada estado conoce su transición natural, qué roles lo ven, y qué requisitos verifica para avanzar. No conoce el doctype (eso es del policy) ni los locks (eso es del lock policy).

**Las 6 clases concretas:**

| State | Deps | `getNext` | `getPrevious` | `validateAdvance` |
|-------|------|-----------|---------------|-------------------|
| `AprobacionState` | — | `'contabilidad'` | `null` | `area_approval=Aprobada`, `dian_validation=Aprobada` |
| `ContabilidadState` | — | `'tesoreria'` | `'aprobacion'` | `accrued=true`, `accrual_date` no vacío, `ready_for_payment` no vacío |
| `TesoreriaState` | `InvoicePaymentService` | `'autorizacion_pago'` | `'contabilidad'` | `paymentService->hasPendingAuthorization($invoice->id)` |
| `AutorizacionPagoState` | `InvoicePaymentService` | `'pagada'` | `'tesoreria'` | `!paymentService->hasPendingAuthorization($invoice->id)` |
| `PagadaState` | — | `null` | `'autorizacion_pago'` | `[]` |
| `LegalizadaState` | — | `null` | `null` | `[]` |

`getRoleVisibility` y `getAdvanceRoleVisibility` retornan los arrays que hoy viven en `ROLE_VISIBLE_STATUSES` y `ADVANCE_VISIBLE_STATUSES`, distribuidos por estado.

### `InvoicePipelineStateRegistry`

```php
final class InvoicePipelineStateRegistry
{
    /** @var array<string, InvoicePipelineState> */
    private array $states;

    public function __construct(
        AprobacionState $aprobacion,
        ContabilidadState $contabilidad,
        TesoreriaState $tesoreria,
        AutorizacionPagoState $autorizacionPago,
        PagadaState $pagada,
        LegalizadaState $legalizada,
    ) {
        $this->states = [
            $aprobacion->getName()       => $aprobacion,
            $contabilidad->getName()     => $contabilidad,
            $tesoreria->getName()        => $tesoreria,
            $autorizacionPago->getName() => $autorizacionPago,
            $pagada->getName()           => $pagada,
            $legalizada->getName()       => $legalizada,
        ];
    }

    public function get(string $name): InvoicePipelineState
    {
        return $this->states[$name] ?? throw new \InvalidArgumentException("Unknown pipeline state: {$name}");
    }

    /** @return array<string, InvoicePipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}
```

**Responsabilidad:** resolver `pipeline_status` → instancia de State. Es la única dependencia del coordinador para acceder a los estados (1 dep en lugar de 6).

### `DocumentTypePolicy` (interfaz)

```php
namespace App\Service\Pipeline;

interface DocumentTypePolicy
{
    public function getDocumentType(): string;
    public function blocksAdvance(InvoicePipelineState $state, \App\Model\Entity\Invoice $invoice): ?string;
    public function getPipelineStatusesForView(): array;
    public function filterVisibleSections(array $sections): array;
    public function triggersAutoLegalization(string $newStatus): bool;
    public function getRegressionLockReason(\App\Model\Entity\Invoice $invoice): ?string;
    public function allowsRefundPayments(): bool;
}
```

**Responsabilidad:** encapsular las reglas que diferencian a un doctype del flujo normal. Cualquier rama `if ($documentType === DOCTYPE_*)` que hoy vive en pipeline / payment / field-access policy se sustituye por una llamada a la policy.

**Las 3 clases concretas (resumen tabular):**

| Método | `Standard` | `Anticipo` | `Legalización` |
|--------|------------|------------|----------------|
| `getDocumentType` | `'*'` (sentinel) | `InvoiceConstants::DOCTYPE_ANTICIPO` | `InvoiceConstants::DOCTYPE_LEGALIZACION` |
| `blocksAdvance` | `null` | `null` | en `contabilidad` retorna `"La legalización avanzará automáticamente cuando el Anticipo padre se legalice."` |
| `getPipelineStatusesForView` | `InvoiceConstants::PIPELINE_STATUSES` | igual que Standard | `InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION` |
| `filterVisibleSections` | retorna `$sections` sin cambios | quita `'revision'` | quita `'treasury'` y `'payment_authorization'` |
| `triggersAutoLegalization` | `false` | `$newStatus === STATUS_PAGADA` | `false` |
| `getRegressionLockReason` | `null` | `"No se puede regresar: la legalización del anticipo ya fue iniciada."` si `$advLeg->hasLegalization($invoice->id)`; en otro caso `null` | `null` |
| `allowsRefundPayments` | `false` | `true` | `false` |

**Dependencias inyectadas:**
- `AnticipoDocumentTypePolicy` recibe `AdvanceLegalizationService` (para `getRegressionLockReason`).
- `Standard` y `Legalizacion`: sin dependencias.

### `DocumentTypePolicyFactory`

```php
final class DocumentTypePolicyFactory
{
    /** @var array<string, DocumentTypePolicy> */
    private array $byType;

    public function __construct(
        private readonly StandardDocumentTypePolicy $standard,
        AnticipoDocumentTypePolicy $anticipo,
        LegalizacionDocumentTypePolicy $legalizacion,
    ) {
        $this->byType = [
            \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO     => $anticipo,
            \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION => $legalizacion,
        ];
    }

    public function for(?string $documentType): DocumentTypePolicy
    {
        return $this->byType[$documentType] ?? $this->standard;
    }
}
```

**Responsabilidad:** mapear `document_type` → policy concreta. Siempre devuelve algo (cae a `Standard`); el coordinador y `InvoicePaymentService` nunca verifican `null`.

### `InvoiceLockPolicy`

```php
namespace App\Service;

final class InvoiceLockPolicy
{
    public function isLockedByPettyCash(object $invoice): bool;
    public function isLockedByPaidScheduling(int $invoiceId): bool;

    /** Mensaje si está bloqueada para edición; null si no.
     *  Considera petty cash y scheduling pagada. NO considera doctype ni rejection. */
    public function getEditLockMessage(object $invoice): ?string;

    /** Mensaje si está bloqueada para regresión; null si no.
     *  Considera rejection, petty cash y scheduling pagada.
     *  El bloqueo por Anticipo con legalización iniciada lo aporta DocumentTypePolicy. */
    public function getRegressionLockMessage(object $invoice): ?string;
}
```

**Sin dependencias inyectadas.** Usa `TableRegistry::getTableLocator()->get('InvoicePayments')` como hoy hace `InvoicePipelineService::isLockedByPaidScheduling()`. Es policy puro de queries de bloqueo + composición de mensajes.

### `InvoiceTransitionValidator`

```php
namespace App\Service;

use App\Service\Pipeline\InvoicePipelineStateRegistry;
use App\Service\Pipeline\DocumentTypePolicyFactory;

final class InvoiceTransitionValidator
{
    public function __construct(
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $policies,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    ) {}

    /** Errores de avance: rejection + doctype block + state.validateAdvance.
     *  Si $fromStatus es null, se usa $invoice->pipeline_status. */
    public function validateAdvance(\App\Model\Entity\Invoice $invoice, ?string $fromStatus = null): array;

    /** Reglas crudas para UI (delega al State actual). */
    public function getTransitionRules(string $fromStatus): array;

    /** Filtra errores que el rol puede resolver desde el formulario.
     *  Mantiene la lógica actual de filterAdvanceErrorsForRole. */
    public function filterErrorsForRole(array $errors, array $rules, string $roleName, string $status): array;

    /** Mapeo requirement-field → campos del form que lo resuelven.
     *  Constante interna; se queda aquí porque los nombres son únicos globalmente
     *  y sólo filterErrorsForRole la consume. */
    private const REQUIREMENT_FIELDS = [
        'area_approval'        => [],
        'dian_validation'      => ['dian_validation'],
        'accrued'              => ['accrued', 'accrual_date'],
        'accrual_date'         => ['accrual_date'],
        'ready_for_payment'    => ['ready_for_payment'],
        '_has_pending_payment' => [],
        '_payment_authorized'  => [],
    ];
}
```

**Responsabilidad:** orquestar la validación de transición usando state + doctype policy + rejection check, y exponer el filtrado por rol que mantiene el comportamiento actual.

---

## Cambios en componentes existentes

### `InvoicePipelineService` (refactorizado)

**Constructor:** 4 deps → 8 deps.

```php
public function __construct(
    private readonly HistoryServiceInterface $historyService,
    private readonly InvoicePaymentService $paymentService,
    private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
    private readonly Pipeline\InvoicePipelineStateRegistry $states,
    private readonly Pipeline\DocumentTypePolicyFactory $docTypePolicies,
    private readonly InvoiceLockPolicy $lockPolicy,
    private readonly InvoiceTransitionValidator $transitionValidator,
) {}
```

**Constantes que se mantienen en el coordinador (consumidas por templates/controllers):**
- `STATUS_LABELS`, `STATUS_ICONS`, `STATUSES`, `ALL_STATUSES`, `TRANSITIONS` (legacy reads de `InvoiceConstants::PIPELINE_STATUSES`).

**Constantes que se eliminan del coordinador:**
- `ROLE_VISIBLE_STATUSES`, `ADVANCE_VISIBLE_STATUSES` → distribuidas en `*State::getRoleVisibility()` y `*State::getAdvanceRoleVisibility()`.
- `BACKWARD_TRANSITIONS` → cubierto por `*State::getPrevious()`.
- `TRANSITION_REQUIREMENTS` → distribuido en `*State::validateAdvance()` y `*State::getTransitionRules()`.
- `REQUIREMENT_FIELDS` → constante privada de `InvoiceTransitionValidator`.

**API pública preservada:** todos los métodos públicos actuales se mantienen con la misma firma. Cada uno se vuelve delegación de 1-3 líneas.

**Mapeo método → delegación:**

| Método del coordinador | Delegación |
|------------------------|------------|
| `getVisibleStatuses(roleName)` | recorre `states->all()` y devuelve los que incluyen `$roleName` en `getRoleVisibility()` |
| `getVisibleAdvanceStatuses(roleName)` | igual con `getAdvanceRoleVisibility()` |
| `getPipelineStatusesFor(?docType)` | `docTypePolicies->for($docType)->getPipelineStatusesForView()` |
| `getEditableFields(role, status)` | `fieldPolicy->getEditableFields(...)` (sin cambios) |
| `getVisibleSections(role, status, ?docType)` | `docTypePolicies->for($docType)->filterVisibleSections($fieldPolicy->getVisibleSections($role, $status))` |
| `getCollapsibleSections(role, status)` | `fieldPolicy->getCollapsibleSections(...)` (sin cambios) |
| `isRejected(invoice)` | inline (≤ 3 líneas) |
| `isLockedByPettyCash(invoice)` | `lockPolicy->isLockedByPettyCash(...)` |
| `isLockedByPaidScheduling(id)` | `lockPolicy->isLockedByPaidScheduling(...)` |
| `getEditLockMessage(invoice)` | `lockPolicy->getEditLockMessage(...)` |
| `getRegressionLockMessage(invoice)` | `lockPolicy->getRegressionLockMessage($invoice) ?? docTypePolicies->for($invoice->document_type)->getRegressionLockReason($invoice)` |
| `validateTransitionRequirements(invoice, fromStatus)` | `transitionValidator->validateAdvance($invoice, $fromStatus)` (el validator resuelve el `*State` desde `$fromStatus`, no desde `$invoice->pipeline_status`, para preservar el comportamiento actual donde `saveAndAdvance` pasa un `testEntity` parchado con `$currentStatus` original) |
| `getTransitionRules(fromStatus)` | `transitionValidator->getTransitionRules(...)` |
| `filterAdvanceErrorsForRole(...)` | `transitionValidator->filterErrorsForRole(...)` |
| `canAdvance(roleName, currentStatus, ?docType)` | `getNextStatus(currentStatus, docType) !== null && (admin OR role-visible)` |
| `getNextStatus(currentStatus, ?docType)` | `state = states->get($currentStatus); policy = docTypePolicies->for($docType); return policy->blocksAdvance($state, $invoice) ? null : $state->getNext()` *(ver nota *firma)* |
| `filterEntityData(data, role, status)` | `fieldPolicy->filterEntityData(...)` |
| `getStatusIndex(status)` | inline (computado contra `STATUSES`) |
| `getPreviousStatus(currentStatus)` | `states->get($currentStatus)->getPrevious()` |
| `canRegress(roleName, currentStatus)` | igual a hoy con `getPreviousStatus` |
| `saveAndAdvance(...)` | orquesta el flujo transaccional usando states + policies; ver siguiente subsección |
| `advance(invoice, role, userId)` | igual a hoy pero validación delega al `transitionValidator` |
| `regress(invoice, role, userId, reason)` | igual a hoy con lock combinado |
| `legalizeLinkedInvoices(advanceId, userId)` | sin cambios (Plan 5 lo moverá) |

**Nota de firma:** `getNextStatus` actual recibe `?string $documentType`. Para resolver `blocksAdvance` se necesita el `$invoice` completo (porque `LegalizacionDocumentTypePolicy::blocksAdvance` verifica el state y el invoice). Como hoy los callers le pasan `$invoice->document_type`, mantenemos la firma y reconstruimos un proxy de invoice mínimo o aceptamos un overload. **Decisión:** dejar la firma actual `(string $currentStatus, ?string $documentType)` por compat; internamente, cuando la policy necesita el invoice (sólo Legalización lo usa hoy y sólo verifica el state name), se puede pasar `null` o un stub. La validación rica con `Invoice` la hace `validateTransitionRequirements`/`saveAndAdvance` directamente.

**`saveAndAdvance` después del refactor (esqueleto):**

```php
public function saveAndAdvance(
    Invoice $invoice,
    array $data,
    string $roleName,
    int $userId,
    ?string $baseUrl = null,
): array {
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $currentStatus = $invoice->pipeline_status;
    $filteredData  = $this->fieldPolicy->filterEntityData($data, $roleName, $currentStatus);

    // Auto-set area_approval_date (lógica actual conservada)
    // ...

    $canAdvance  = $this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null);
    $isRejected  = $this->lockPolicy->getRegressionLockMessage($invoice) !== null
        ? false  // si está bloqueada NO se evalúa rejection per se
        : $this->isRejected($invoice);

    // Lo mismo que hoy: testEntity con datos parchados, validar requirements,
    // computar advanceNextStatus y guardar transaccionalmente.
    // La diferencia: la validación pasa por $this->transitionValidator->validateAdvance(...)
    // y el auto-init de legalización pasa por
    //   $this->docTypePolicies->for($invoice->document_type)->triggersAutoLegalization($newStatus)
    // en lugar del if hardcoded por DOCTYPE_ANTICIPO.

    // ...
}
```

**Métrica:** con todas las constantes y validadores fuera, las firmas delegadas, y `legalizeLinkedInvoices` + `saveAndAdvance` + `regress` + `advance` como los métodos "gordos" restantes, el coordinador queda en **≤ 300 LOC**. Si la métrica no se cumpliera tras el refactor, la separación de responsabilidades (no la cifra) es el criterio que decide aceptar o no el resultado: no se infla el spec para alcanzar el número si el código ya está claramente delegado.

### `InvoicePaymentService`

**Constructor:** recibe nuevo argumento `DocumentTypePolicyFactory`.

```php
public function __construct(
    private readonly InvoiceHistoryService $historyService,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
    private readonly Pipeline\DocumentTypePolicyFactory $docTypePolicies, // NEW
) {}
```

**Cambios internos:**
- `registerPayment`: sustituye `if (!empty($paymentData['is_refund']) && $invoice->document_type !== DOCTYPE_ANTICIPO)` por `if (!empty($paymentData['is_refund']) && !$this->docTypePolicies->for($invoice->document_type)->allowsRefundPayments())`.
- `authorizePayment`: sustituye `if ($invoice->pipeline_status === STATUS_PAGADA && $invoice->document_type === DOCTYPE_ANTICIPO)` por `if ($this->docTypePolicies->for($invoice->document_type)->triggersAutoLegalization($invoice->pipeline_status))`.

API pública sin cambios.

### `InvoiceFieldAccessPolicy`

**Cambio de firma:** `getVisibleSections` pierde `?string $documentType`.

Antes:
```php
public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
```

Después:
```php
public function getVisibleSections(string $roleName, string $status): array
```

El filtrado por doctype (los dos `if` que quitaban `revision`/`treasury`/`payment_authorization`) sale a `DocumentTypePolicy::filterVisibleSections`. El coordinador es el orquestador.

**Caller único de `getVisibleSections` con doctype hoy:** `InvoicePipelineService::getVisibleSections`. El cambio se contiene en el coordinador. No hay otros consumidores.

### `Invoice` (entidad)

**Eliminar:** método `canAdvanceTo()` y su PHPDoc (líneas 64–85).

Verificado: `grep -rn "canAdvanceTo" --include="*.php" src/ templates/` retorna sólo la definición. Cero callers.

---

## Registro DI en `Application::services()`

Sección "Invoice domain" se reemplaza por:

```php
// === Pipeline states (W9) ===
$container->addShared(\App\Service\Pipeline\State\AprobacionState::class);
$container->addShared(\App\Service\Pipeline\State\ContabilidadState::class);
$container->addShared(\App\Service\Pipeline\State\TesoreriaState::class)
    ->addArgument(InvoicePaymentService::class);
$container->addShared(\App\Service\Pipeline\State\AutorizacionPagoState::class)
    ->addArgument(InvoicePaymentService::class);
$container->addShared(\App\Service\Pipeline\State\PagadaState::class);
$container->addShared(\App\Service\Pipeline\State\LegalizadaState::class);
$container->addShared(\App\Service\Pipeline\InvoicePipelineStateRegistry::class)
    ->addArguments([
        \App\Service\Pipeline\State\AprobacionState::class,
        \App\Service\Pipeline\State\ContabilidadState::class,
        \App\Service\Pipeline\State\TesoreriaState::class,
        \App\Service\Pipeline\State\AutorizacionPagoState::class,
        \App\Service\Pipeline\State\PagadaState::class,
        \App\Service\Pipeline\State\LegalizadaState::class,
    ]);

// === Document type policies (W10) ===
$container->addShared(\App\Service\Pipeline\Policy\StandardDocumentTypePolicy::class);
$container->addShared(\App\Service\Pipeline\Policy\AnticipoDocumentTypePolicy::class)
    ->addArgument(AdvanceLegalizationService::class);
$container->addShared(\App\Service\Pipeline\Policy\LegalizacionDocumentTypePolicy::class);
$container->addShared(\App\Service\Pipeline\DocumentTypePolicyFactory::class)
    ->addArguments([
        \App\Service\Pipeline\Policy\StandardDocumentTypePolicy::class,
        \App\Service\Pipeline\Policy\AnticipoDocumentTypePolicy::class,
        \App\Service\Pipeline\Policy\LegalizacionDocumentTypePolicy::class,
    ]);

// === Pipeline sub-policies (C5) ===
$container->addShared(InvoiceLockPolicy::class);
$container->addShared(InvoiceTransitionValidator::class)
    ->addArguments([
        \App\Service\Pipeline\InvoicePipelineStateRegistry::class,
        \App\Service\Pipeline\DocumentTypePolicyFactory::class,
        InvoiceFieldAccessPolicy::class,
    ]);

// === Invoice domain (refactored) ===
$container->addShared(InvoiceHistoryService::class);
$container->addShared(InvoiceFieldAccessPolicy::class);
$container->addShared(InvoiceFilterService::class);
$container->addShared(InvoiceDocumentService::class);
$container->addShared(InvoicePaymentService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        AdvanceLegalizationService::class,
        \App\Service\Pipeline\DocumentTypePolicyFactory::class, // NEW
    ]);
$container->addShared(AdvanceLegalizationService::class)
    ->addArgument(new LiteralArgument(
        fn() => $container->get(InvoicePipelineService::class),
    ));
$container->addShared(InvoicePipelineService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
        AdvanceLegalizationService::class,
        \App\Service\Pipeline\InvoicePipelineStateRegistry::class,
        \App\Service\Pipeline\DocumentTypePolicyFactory::class,
        InvoiceLockPolicy::class,
        InvoiceTransitionValidator::class,
    ]);
$container->addShared(InvoiceApprovalService::class)
    ->addArgument(NotificationService::class);
```

**Nota:** el `LiteralArgument` con closure factory para `AdvanceLegalizationService` (que rompe el ciclo Pipeline ↔ Legalization) se mantiene como hoy. Plan 5 lo elimina cuando los Domain Events sustituyan la llamada cruzada.

---

## Migración de callers

| Caller | Cambio |
|--------|--------|
| `InvoicesController` | **Cero.** API pública del coordinador preservada. |
| `AdvancesController` | Cero. |
| `ExternalApprovalsController` | Cero. |
| `PaymentSchedulingsController` | Cero. |
| `InvoiceApprovalStrategy::apply` | Cero (usa `pipeline->saveAndAdvance` con misma firma). |
| `SidebarCounterService` | Cero. |
| `templates/element/pipeline_progress.php` | Cero (consume `STATUS_LABELS`, `STATUS_ICONS`). |
| `templates/Invoices/view.php`, `edit.php`, `index.php` | Cero. |
| `InvoiceFieldAccessPolicy::getVisibleSections` (firma) | Único caller actual con doctype: el coordinador. Cambio contenido. |
| `InvoicePaymentService::registerPayment` | Reescrito para usar policy. |
| `InvoicePaymentService::authorizePayment` | Reescrito para usar policy. |

---

## Plan de migración (estructura sugerida de commits)

Para facilitar revisión cada commit debe compilar y dejar la app funcional. El orden propuesto minimiza la ventana en la que el coordinador queda mitad-refactorizado:

1. **Extraer `InvoiceLockPolicy`.** Mover los 3 lock checks. El coordinador delega. Sin cambio funcional.
2. **Extraer `InvoiceTransitionValidator`.** Mover validación + filtrado por rol. El coordinador delega. Sin cambio funcional. (En este punto los States todavía no existen, así que el validator usa internamente las constantes que aún están en el coordinador. Se eliminarán en el paso 5.)
3. **Crear interfaz `InvoicePipelineState` + `Registry` + las 6 clases concretas.** Aún no se conectan al coordinador. Se registran en DI. (Compila pero no se usan.)
4. **Crear interfaz `DocumentTypePolicy` + 3 policies + factory.** Igual: registrados pero aún no consumidos.
5. **Migrar coordinador a usar States + Policies + Registry + Validator (modo full delegación).** Aquí se eliminan las constantes legacy del coordinador y `validateTransitionRequirements` migra de constantes hardcoded a llamadas a `*State::validateAdvance`. Es el commit más grande y donde la métrica ≤ 300 LOC se valida.
6. **Migrar `InvoicePaymentService` a usar `DocumentTypePolicy`.** `registerPayment` (`is_refund` check) y `authorizePayment` (auto-init).
7. **Cambiar firma de `InvoiceFieldAccessPolicy::getVisibleSections`.** El coordinador hace la composición con `DocumentTypePolicy::filterVisibleSections`.
8. **Eliminar `Invoice::canAdvanceTo()`** (W2).

Cada commit debería verificarse manualmente con un smoke flow del usuario antes de pasar al siguiente.

---

## Validación manual

El proyecto no usa tests automatizados (CLAUDE.md). El refactor no cambia comportamiento — sólo dónde vive cada decisión. La validación es regresión-driven y queda a criterio del usuario, ejercitando los flujos en navegador.

**Flujos que el refactor toca y conviene revisar:**

- Pipeline normal de Factura: aprobación → contabilidad → tesorería (registrar pago) → autorización pago → pagada. Cada rol debe ver los campos editables y secciones correctas en cada estado.
- Anticipo: pago total → pasa a `pagada` → `AdvanceLegalizationService::initialize` se ejecuta automáticamente (auto-init via `DocumentTypePolicy::triggersAutoLegalization`).
- Legalización: queda en `contabilidad` con mensaje "avanzará automáticamente" cuando se intenta avanzar manualmente; salta a `legalizada` cuando el Anticipo padre se legaliza (vía `legalizeLinkedInvoices`).
- Locks: factura asociada a Caja Menor o a una programación pagada bloquea edición y regresión.
- Rechazo: `area_approval = Rechazada` bloquea avance. `Reiniciar flujo` desde Registro/Revisión vuelve a habilitar.
- Pago parcial tras autorización: vuelve a `tesoreria` automáticamente.
- Refund: `is_refund=true` sólo permitido en pagos de Anticipo.

---

## Riesgos

- **Comportamiento debe preservarse exactamente.** El refactor no cambia la state machine ni las reglas de validación — sólo dónde viven. Validación manual del usuario es la red de seguridad. Cualquier divergencia observada significa bug en la migración.
- **DI fail-fast:** si falta una dep en `Application::services()`, el container falla en boot, no en runtime. Plan 3 lo dejó listo.
- **Plan 5 va a tocar varios de estos archivos.** Las llamadas a `$advLeg->initialize()` dentro de `*State` o coordinador serán sustituidas por publicación de eventos. Asumimos que la inversión paga: el código será más limpio y Plan 5 será más simple porque las decisiones ya estarán por estado/policy.
- **El Pipeline actual lazy-init Pipeline ↔ Legalization** sigue como hoy. No se rompe el ciclo en este plan.

---

## Out of scope (recordatorio)

| Item | Plan |
|------|------|
| Refactor de `NoveltyPipelineService` y `PaymentSchedulingPipelineService` | Posible Plan 8 si se decide replicar |
| Romper ciclo Pipeline ↔ Payment ↔ Legalization | Plan 5 (Domain Events) |
| Migrar `saveAndAdvance` a `ServiceResult` | Plan 7 (W15) |
| Migrar `Cake\Log\Log::*` a `StructuredLogger` inyectado | Plan 7 (W1) |
| Mover `EDITABLE_FIELDS` desde `InvoiceFieldAccessPolicy` a los States | Fuera de scope (no necesario para metas) |
| Mover `legalizeLinkedInvoices` fuera del coordinador | Plan 5 |

---

## Referencias

- Auditoría origen: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md), items C5, W2, W9, W10, conflict 1
- Roadmap: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md), Plan #4
- Plan 3 (DI Container, dependencia recomendada): [`docs/superpowers/specs/2026-05-01-di-container-design.md`](./2026-05-01-di-container-design.md)
- Convenciones del proyecto: `CLAUDE.md` (raíz)
- Arquitectura actual: `ARCHITECTURE.md` (raíz)
