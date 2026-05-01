# Pipeline Refactor Implementation Plan (Plan 4 — C5 + W2 + W9 + W10)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganizar `InvoicePipelineService` (768 LOC) en cuatro colaboradores con responsabilidad única — `InvoiceLockPolicy`, `InvoiceTransitionValidator`, State pattern (6 clases), DocumentTypePolicy (3 clases) — manteniendo la API pública del coordinador para no romper callers.

**Architecture:** Extract Class + State pattern + Strategy/Policy. El coordinador queda como delegador delgado (≤ 300 LOC). Cada estado del pipeline es una clase polimórfica registrada en un `Registry`. Los doctypes con comportamiento especial (Anticipo, Legalización) viven en `DocumentTypePolicy` con default `Standard` para los 6 doctypes restantes. Plan 3 (DI Container) ya en sitio: todos los nuevos componentes se registran en `Application::services()`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, `league/container` 4.x (vía `Cake\Core\ContainerInterface`).

**Spec:** [`docs/superpowers/specs/2026-05-01-pipeline-refactor-design.md`](../specs/2026-05-01-pipeline-refactor-design.md)

**Project policy:** Sin tests automatizados (CLAUDE.md). Validación manual a criterio del usuario. Cada tarea termina con un commit y referencia de qué flujos puede probar el usuario manualmente. **NO ejecutar `php bin/cake server` ni `curl` desde el agente** — el usuario hace los smoke checks entre commits.

---

## File Structure

**Crear (15 archivos nuevos):**

```
src/Service/
├── InvoiceLockPolicy.php                            (Task 1)
├── InvoiceTransitionValidator.php                   (Task 2)
└── Pipeline/
    ├── InvoicePipelineState.php                     (Task 3 — interfaz)
    ├── InvoicePipelineStateRegistry.php             (Task 3)
    ├── DocumentTypePolicy.php                       (Task 4 — interfaz)
    ├── DocumentTypePolicyFactory.php                (Task 4)
    ├── State/
    │   ├── AprobacionState.php                      (Task 3)
    │   ├── ContabilidadState.php                    (Task 3)
    │   ├── TesoreriaState.php                       (Task 3)
    │   ├── AutorizacionPagoState.php                (Task 3)
    │   ├── PagadaState.php                          (Task 3)
    │   └── LegalizadaState.php                      (Task 3)
    └── Policy/
        ├── StandardDocumentTypePolicy.php           (Task 4)
        ├── AnticipoDocumentTypePolicy.php           (Task 4)
        └── LegalizacionDocumentTypePolicy.php       (Task 4)
```

**Modificar:**
- `src/Service/InvoicePipelineService.php` — extracciones (Tasks 1, 2) + delegación full (Task 5).
- `src/Service/InvoicePaymentService.php` — usa `DocumentTypePolicy` (Task 6).
- `src/Service/InvoiceFieldAccessPolicy.php` — firma de `getVisibleSections` (Task 7).
- `src/Application.php` — registrar nuevos componentes en DI (en cada task que crea archivos).
- `src/Model/Entity/Invoice.php` — eliminar `canAdvanceTo()` (Task 8).

**Borrar:** ninguno (sólo eliminar un método dentro de Invoice).

---

## Convenciones de los pasos en este plan

Cada tarea termina con un commit. Steps típicos:

1. **Aplicar cambios de código** (Read + Edit/Write)
2. **Verificación estática** (`grep`, `composer cs-check` si toca muchas líneas)
3. **Referencia de validación manual** (qué flujos puede probar el usuario; no se ejecuta desde el agente)
4. **Commit**

Si un commit deja la app rota (no arranca, error 500), **rollback inmediato** (`git reset --hard HEAD~1`) y reintento del task.

**Ningún commit puede dejar la app sin arrancar.** El orden es importante: las tareas 1, 2, 3, 4 son aditivas (crean archivos + registran en DI sin romper nada). Task 5 es el commit más grande (migra el coordinador). Tasks 6, 7, 8 son afinaciones puntuales.

---

## Task 1: Extraer `InvoiceLockPolicy`

**Files:**
- Create: `src/Service/InvoiceLockPolicy.php`
- Modify: `src/Application.php` (registrar en DI)
- Modify: `src/Service/InvoicePipelineService.php:21-27` (constructor) y métodos lock (líneas 232-268, 602-622)

**Por qué primero:** Sin cambio funcional. `InvoiceLockPolicy` encapsula tres lock checks (petty cash, paid scheduling, edit/regression message) que hoy viven como métodos del coordinador. Es la extracción más mecánica del Plan 4.

- [ ] **Step 1: Crear el archivo `src/Service/InvoiceLockPolicy.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\TableRegistry;

/**
 * Policy que encapsula los bloqueos de edición y regresión de una factura
 * derivados de petty cash y de programaciones de pago ya pagadas.
 *
 * Las reglas dependientes del document_type (Anticipo con legalización iniciada)
 * NO viven aquí — las aporta DocumentTypePolicy::getRegressionLockReason().
 */
final class InvoiceLockPolicy
{
    /**
     * Returns true if the invoice is linked to a Petty Cash record.
     */
    public function isLockedByPettyCash(object $invoice): bool
    {
        return !empty($invoice->petty_cash_record_id ?? null);
    }

    /**
     * Returns true if the invoice has any payment linked to a payment
     * scheduling already in "pagada" state.
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        return $paymentsTable->find()
            ->matching('PaymentSchedulings', function ($q) {
                return $q->where([
                    'PaymentSchedulings.pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA,
                ]);
            })
            ->where(['InvoicePayments.invoice_id' => $invoiceId])
            ->count() > 0;
    }

    /**
     * Returns a human-readable reason if the invoice is locked for editing,
     * or null otherwise. Lock priority: petty cash → scheduling.
     */
    public function getEditLockMessage(object $invoice): ?string
    {
        if ($this->isLockedByPettyCash($invoice)) {
            return 'Factura bloqueada: pertenece al registro de Caja Menor.';
        }
        if (!empty($invoice->id) && $this->isLockedByPaidScheduling((int)$invoice->id)) {
            return 'Factura bloqueada: tiene pagos de una programación ya pagada.';
        }

        return null;
    }

    /**
     * Returns a human-readable reason if the invoice cannot be regressed
     * by reglas no-doctype (rejection, petty cash, paid scheduling).
     * El bloqueo por Anticipo con legalización iniciada lo aporta DocumentTypePolicy.
     */
    public function getRegressionLockMessage(object $invoice): ?string
    {
        if (($invoice->area_approval ?? null) === InvoiceConstants::APPROVAL_REJECTED) {
            return "Factura rechazada. Use 'Reiniciar flujo' para reactivarla.";
        }
        if ($this->isLockedByPettyCash($invoice)) {
            return 'Factura bloqueada: pertenece a un registro de Caja Menor.';
        }
        if (!empty($invoice->id) && $this->isLockedByPaidScheduling((int)$invoice->id)) {
            return 'Factura bloqueada: tiene pagos en una programación ya pagada.';
        }

        return null;
    }
}
```

- [ ] **Step 2: Registrar `InvoiceLockPolicy` en `Application::services()`.**

Modificar `src/Application.php`. Localizar la sección `// === Invoice domain (cycle: closure factory in AdvanceLegalization) ===` (alrededor de la línea 161) y agregar el binding inmediatamente después de `$container->addShared(InvoiceFieldAccessPolicy::class);`:

```php
$container->addShared(InvoiceFieldAccessPolicy::class);
$container->addShared(InvoiceLockPolicy::class);  // NEW
```

Y agregar el `use` correspondiente al bloque de imports superior (orden alfabético tras `InvoiceHistoryService`):

```php
use App\Service\InvoiceLockPolicy;
```

- [ ] **Step 3: Inyectar `InvoiceLockPolicy` en `InvoicePipelineService` y reemplazar los métodos lock por delegación.**

Modificar `src/Service/InvoicePipelineService.php`.

3a. Constructor (líneas 21-27): agregar `private readonly InvoiceLockPolicy $lockPolicy`:

```php
public function __construct(
    private readonly HistoryServiceInterface $historyService,
    private readonly InvoicePaymentService $paymentService,
    private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
    private readonly InvoiceLockPolicy $lockPolicy,
) {
}
```

3b. Reemplazar los métodos `isLockedByPaidScheduling`, `isLockedByPettyCash`, `getEditLockMessage`, `getRegressionLockMessage` (líneas 232–268 y 602–622) por delegaciones. Buscar el bloque desde:

```php
    /**
     * Returns true if the invoice has any payment linked to a payment
     * scheduling already in "pagada" state. Used to lock the invoice from
     * further edits (except for Admin).
     */
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
```

Y reemplazar todo hasta el cierre de `getEditLockMessage()` por:

```php
    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        return $this->lockPolicy->isLockedByPaidScheduling($invoiceId);
    }

    public function isLockedByPettyCash(object $invoice): bool
    {
        return $this->lockPolicy->isLockedByPettyCash($invoice);
    }

    public function getEditLockMessage(object $invoice): ?string
    {
        return $this->lockPolicy->getEditLockMessage($invoice);
    }
```

3c. Reemplazar `getRegressionLockMessage` (líneas 602–622). Buscar:

```php
    public function getRegressionLockMessage(object $invoice): ?string
    {
        if (($invoice->area_approval ?? null) === InvoiceConstants::APPROVAL_REJECTED) {
```

Y reemplazar todo el método (hasta el `return null;` y `}` final) por:

```php
    public function getRegressionLockMessage(object $invoice): ?string
    {
        $lockMsg = $this->lockPolicy->getRegressionLockMessage($invoice);
        if ($lockMsg !== null) {
            return $lockMsg;
        }

        // Bloqueo cross-aggregate por Anticipo con legalización iniciada.
        // Plan 4 lo deja aquí; Task 5 lo moverá a DocumentTypePolicy.
        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
            && !empty($invoice->id)
            && $this->advanceLegalizationService->hasLegalization((int)$invoice->id)
        ) {
            return 'No se puede regresar: la legalización del anticipo ya fue iniciada.';
        }

        return null;
    }
```

- [ ] **Step 4: Actualizar el binding del coordinador en `Application::services()` para inyectarle `InvoiceLockPolicy`.**

Localizar el binding actual de `InvoicePipelineService` (alrededor de la línea 175) y agregar `InvoiceLockPolicy::class` al final del `addArguments` array:

```php
$container->addShared(InvoicePipelineService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
        AdvanceLegalizationService::class,
        InvoiceLockPolicy::class,  // NEW
    ]);
```

- [ ] **Step 5: Verificación estática.**

Ejecutar:

```bash
grep -n "isLockedByPettyCash\|isLockedByPaidScheduling\|getEditLockMessage" src/Service/InvoicePipelineService.php
```

Expected: 4 ocurrencias (las 3 firmas de los métodos delegadores + la llamada interna `$this->lockPolicy->getRegressionLockMessage`). Si aparecen referencias duplicadas o cuerpos largos, revisar.

```bash
composer cs-check
```

Expected: sin errores nuevos en `src/Service/InvoiceLockPolicy.php` ni en `src/Service/InvoicePipelineService.php`.

- [ ] **Step 6: Validación manual (referencia para el usuario).**

Flujos a probar (usuario decide cuáles):
- Abrir una factura asociada a un registro de Caja Menor. Verificar mensaje de bloqueo de edición y bloqueo de regresión.
- Abrir una factura con pagos en una programación pagada. Verificar mensaje de bloqueo.
- Abrir una factura rechazada (`area_approval=Rechazada`). Verificar mensaje de regresión "Use 'Reiniciar flujo'...".
- Abrir un Anticipo con legalización iniciada. Verificar mensaje "No se puede regresar: la legalización del anticipo ya fue iniciada."

- [ ] **Step 7: Commit.**

```bash
git add src/Service/InvoiceLockPolicy.php src/Application.php src/Service/InvoicePipelineService.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): extraer InvoiceLockPolicy del coordinador (C5 parcial)

Mueve los 3 lock checks (petty cash, paid scheduling, edit/regression
message) a un policy dedicado. El coordinador delega; el bloqueo
cross-aggregate de Anticipo con legalización iniciada queda en el
coordinador hasta Task 5 (lo moverá a DocumentTypePolicy).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Extraer `InvoiceTransitionValidator`

**Files:**
- Create: `src/Service/InvoiceTransitionValidator.php`
- Modify: `src/Application.php` (registrar en DI)
- Modify: `src/Service/InvoicePipelineService.php` (constructor + métodos `validateTransitionRequirements`, `getTransitionRules`, `filterAdvanceErrorsForRole`)

**Por qué segundo:** Sin cambio funcional. Igual que Task 1, es extracción mecánica. Importante: el validator todavía NO usa los States ni la DocumentTypePolicy (no existen aún) — internamente sigue trabajando con las constantes que aún viven en el coordinador. La interfaz queda lista para que Task 5 lo conecte a States/Policies.

- [ ] **Step 1: Crear el archivo `src/Service/InvoiceTransitionValidator.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

/**
 * Orquesta la validación de avance del pipeline:
 *  - rejection bloquea todo avance,
 *  - regla doctype-specific (Legalización en contabilidad bloquea con mensaje),
 *  - chequeo de requirements del estado actual.
 *
 * También filtra los errores que un rol puede resolver desde el formulario.
 *
 * Esta clase tiene dos vidas:
 *  - Task 2 (este task): trabaja con las constantes TRANSITION_REQUIREMENTS
 *    inyectadas por el coordinador. No conoce States ni DocumentTypePolicy aún.
 *  - Task 5: pasa a depender de InvoicePipelineStateRegistry y
 *    DocumentTypePolicyFactory; las constantes legacy desaparecen del coordinador.
 */
final class InvoiceTransitionValidator
{
    /** Mapeo requirement-field → campos del form que lo resuelven. */
    private const REQUIREMENT_FIELDS = [
        'area_approval'        => [],
        'dian_validation'      => ['dian_validation'],
        'accrued'              => ['accrued', 'accrual_date'],
        'accrual_date'         => ['accrual_date'],
        'ready_for_payment'    => ['ready_for_payment'],
        '_has_pending_payment' => [],
        '_payment_authorized'  => [],
    ];

    /** Reglas de transición indexadas por estado origen. */
    private const TRANSITION_REQUIREMENTS = [
        InvoiceConstants::STATUS_APROBACION => [
            [
                'field' => 'area_approval',
                'value' => InvoiceConstants::APPROVAL_APPROVED,
                'label' => 'Todos los aprobadores deben haber aprobado',
            ],
            [
                'field' => 'dian_validation',
                'value' => InvoiceConstants::DIAN_APPROVED,
                'label' => 'Validación DIAN debe ser "Aprobada"',
            ],
        ],
        InvoiceConstants::STATUS_CONTABILIDAD => [
            [
                'field' => 'accrued',
                'value' => true,
                'label' => 'La factura debe estar marcada como Causada',
            ],
            [
                'field' => 'accrual_date',
                'not_empty' => true,
                'label' => 'Fecha de Causación es requerida',
            ],
            [
                'field' => 'ready_for_payment',
                'not_empty' => true,
                'label' => 'Campo "Lista para Pago" es requerido',
            ],
        ],
        InvoiceConstants::STATUS_TESORERIA => [
            [
                'field' => '_has_pending_payment',
                'custom' => true,
                'label' => 'Debe registrar al menos un pago para avanzar a autorización',
            ],
        ],
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
            [
                'field' => '_payment_authorized',
                'custom' => true,
                'label' => 'El pago pendiente debe ser autorizado por el Contador',
            ],
        ],
    ];

    public function __construct(
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    ) {
    }

    /**
     * Errores de avance: rejection + doctype block (Legalización en contabilidad)
     * + requirements del estado.
     *
     * @param object $invoice Invoice (puede ser entidad parchada en saveAndAdvance).
     * @param string $fromStatus Estado desde el que se intenta avanzar.
     * @return array<string>
     */
    public function validateAdvance(object $invoice, string $fromStatus): array
    {
        if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION
            && $fromStatus === InvoiceConstants::STATUS_CONTABILIDAD
        ) {
            return ['La legalización avanzará automáticamente cuando el Anticipo padre se legalice.'];
        }

        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            if (!empty($rule['custom'])) {
                if ($rule['field'] === '_has_pending_payment') {
                    if (!$this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                } elseif ($rule['field'] === '_payment_authorized') {
                    if ($this->paymentService->hasPendingAuthorization($invoice->id)) {
                        $errors[] = $rule['label'];
                    }
                }
                continue;
            }

            $field = $rule['field'];
            $value = $invoice->$field ?? null;

            if (isset($rule['value'])) {
                $expected = $rule['value'];
                $actual = is_bool($expected) ? (bool)$value : $value;
                if ($actual !== $expected) {
                    $errors[] = $rule['label'];
                }
            } elseif (!empty($rule['not_empty'])) {
                if ($value === null || $value === '' || $value === false) {
                    $errors[] = $rule['label'];
                }
            }
        }

        return $errors;
    }

    /**
     * Reglas crudas para UI (sin evaluar).
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        $rules = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            $rules[] = ['field' => $rule['field'], 'label' => $rule['label']];
        }

        return $rules;
    }

    /**
     * Filtra los errores que un rol puede resolver desde el formulario.
     *
     * @param array<string> $errors
     * @param array<int, array{field: string, label: string}> $rules
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->fieldPolicy->getEditableFields($roleName, $status);
        $visibleStatuses = $this->getVisibleStatusesForRole($roleName);
        $statusVisible = in_array($status, $visibleStatuses, true);

        $filtered = [];
        foreach ($rules as $i => $rule) {
            if (!isset($errors[$i])) {
                continue;
            }
            $field = $rule['field'];
            $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $errors[$i];
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $errors[$i];
            }
        }

        return $filtered;
    }

    /**
     * Helper local hasta que Task 5 conecte el StateRegistry.
     * Replica el mapeo de ROLE_VISIBLE_STATUSES del coordinador.
     */
    private function getVisibleStatusesForRole(string $roleName): array
    {
        return match ($roleName) {
            RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
            RoleConstants::CONTABILIDAD      => [InvoiceConstants::STATUS_CONTABILIDAD],
            RoleConstants::TESORERIA         => [InvoiceConstants::STATUS_TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            RoleConstants::CONTADOR          => [InvoiceConstants::STATUS_AUTORIZACION_PAGO],
            RoleConstants::ADMIN             => [
                InvoiceConstants::STATUS_APROBACION,
                InvoiceConstants::STATUS_CONTABILIDAD,
                InvoiceConstants::STATUS_TESORERIA,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                InvoiceConstants::STATUS_PAGADA,
                InvoiceConstants::STATUS_LEGALIZADA,
            ],
            default => [],
        };
    }
}
```

- [ ] **Step 2: Registrar `InvoiceTransitionValidator` en `Application::services()`.**

Modificar `src/Application.php`. Después del binding de `InvoiceLockPolicy::class` agregado en Task 1:

```php
$container->addShared(InvoiceLockPolicy::class);
$container->addShared(InvoiceTransitionValidator::class)
    ->addArguments([
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
    ]);
```

Agregar el `use` (orden alfabético):

```php
use App\Service\InvoiceTransitionValidator;
```

- [ ] **Step 3: Inyectar `InvoiceTransitionValidator` en `InvoicePipelineService` y delegar.**

Modificar `src/Service/InvoicePipelineService.php`.

3a. Constructor: agregar la dep:

```php
public function __construct(
    private readonly HistoryServiceInterface $historyService,
    private readonly InvoicePaymentService $paymentService,
    private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
    private readonly InvoiceLockPolicy $lockPolicy,
    private readonly InvoiceTransitionValidator $transitionValidator,
) {
}
```

3b. Reemplazar el cuerpo de `validateTransitionRequirements()` (líneas 274-326) por:

```php
    public function validateTransitionRequirements(object $invoice, string $fromStatus): array
    {
        return $this->transitionValidator->validateAdvance($invoice, $fromStatus);
    }
```

3c. Reemplazar el cuerpo de `getTransitionRules()` (líneas 333-341) por:

```php
    public function getTransitionRules(string $fromStatus): array
    {
        return $this->transitionValidator->getTransitionRules($fromStatus);
    }
```

3d. Reemplazar el cuerpo de `filterAdvanceErrorsForRole()` (líneas 352-383) por:

```php
    public function filterAdvanceErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleName, $status);
    }
```

3e. Eliminar las constantes `TRANSITION_REQUIREMENTS` (líneas 92-136) y `REQUIREMENT_FIELDS` (líneas 142-150) del coordinador. **Estas constantes ya no se referencian dentro del coordinador después de los cambios anteriores.**

- [ ] **Step 4: Actualizar el binding del coordinador en `Application::services()`.**

```php
$container->addShared(InvoicePipelineService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
        AdvanceLegalizationService::class,
        InvoiceLockPolicy::class,
        InvoiceTransitionValidator::class,  // NEW
    ]);
```

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "TRANSITION_REQUIREMENTS\|REQUIREMENT_FIELDS" src/Service/InvoicePipelineService.php
```

Expected: cero ocurrencias (constantes ya migradas al validator).

```bash
grep -n "TRANSITION_REQUIREMENTS\|REQUIREMENT_FIELDS" src/Service/InvoiceTransitionValidator.php
```

Expected: 2 ocurrencias (las dos `private const`).

```bash
composer cs-check
```

Expected: sin errores nuevos en los archivos tocados.

- [ ] **Step 6: Validación manual (referencia para el usuario).**

Flujos a probar:
- Avanzar una factura desde aprobación: requerir `area_approval=Aprobada` y `dian_validation=Aprobada`. Verificar mensajes de error si falta uno.
- Avanzar desde contabilidad: requerir `accrued`, `accrual_date`, `ready_for_payment`. Verificar mensajes.
- Intentar avanzar una Legalización desde contabilidad: debe mostrar "La legalización avanzará automáticamente cuando el Anticipo padre se legalice."
- Como rol de Contabilidad, intentar avanzar una factura con Tesorería pendiente: el filtrado por rol debe ocultarte el error de Tesorería que tú no puedes resolver.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/InvoiceTransitionValidator.php src/Application.php src/Service/InvoicePipelineService.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): extraer InvoiceTransitionValidator del coordinador (C5 parcial)

Mueve la validación de avance, las reglas de transición y el filtrado
por rol a un servicio dedicado. El coordinador delega. Las constantes
TRANSITION_REQUIREMENTS y REQUIREMENT_FIELDS dejan de existir en el
coordinador. El validator aún no conoce States ni DocumentTypePolicy
(Task 5 lo conectará).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Crear interfaz `InvoicePipelineState`, los 6 estados y el `Registry`

**Files:**
- Create: `src/Service/Pipeline/InvoicePipelineState.php`
- Create: `src/Service/Pipeline/InvoicePipelineStateRegistry.php`
- Create: `src/Service/Pipeline/State/AprobacionState.php`
- Create: `src/Service/Pipeline/State/ContabilidadState.php`
- Create: `src/Service/Pipeline/State/TesoreriaState.php`
- Create: `src/Service/Pipeline/State/AutorizacionPagoState.php`
- Create: `src/Service/Pipeline/State/PagadaState.php`
- Create: `src/Service/Pipeline/State/LegalizadaState.php`
- Modify: `src/Application.php` (registrar todos en DI)

**Por qué tercero:** Aún sin cambio funcional. Los States se crean y registran pero **nadie los consume todavía**. Si la app sigue arrancando después de este commit, el grafo DI quedó bien armado. Task 5 los conectará al coordinador.

- [ ] **Step 1: Crear `src/Service/Pipeline/InvoicePipelineState.php` (interfaz).**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

/**
 * Polymorphic representation of one pipeline state.
 *
 * Cada State conoce su transición natural, qué roles lo ven y qué requisitos
 * verifica para avanzar. NO conoce el doctype (eso es de DocumentTypePolicy)
 * ni los locks (eso es de InvoiceLockPolicy).
 */
interface InvoicePipelineState
{
    /** Nombre canónico del estado (e.g. 'aprobacion'). */
    public function getName(): string;

    /** Estado siguiente "natural"; null si terminal. La DocumentTypePolicy puede bloquearlo. */
    public function getNext(): ?string;

    /** Estado anterior; null si es el primero. */
    public function getPrevious(): ?string;

    /**
     * Roles (RoleConstants::*) que ven este estado en el index principal.
     *
     * @return array<string>
     */
    public function getRoleVisibility(): array;

    /**
     * Roles que ven este estado en "Mis Anticipos".
     *
     * @return array<string>
     */
    public function getAdvanceRoleVisibility(): array;

    /**
     * Errores de requirement de este estado para avanzar al siguiente.
     * No incluye rejection ni doctype block — el coordinador los compone.
     *
     * @return array<string>
     */
    public function validateAdvance(object $invoice): array;

    /**
     * Reglas crudas (campo + etiqueta) para UI. No evalúa contra el invoice.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(): array;
}
```

- [ ] **Step 2: Crear `src/Service/Pipeline/State/AprobacionState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class AprobacionState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_APROBACION;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::REGISTRO_REVISION, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::REGISTRO_REVISION,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        $errors = [];
        if (($invoice->area_approval ?? null) !== InvoiceConstants::APPROVAL_APPROVED) {
            $errors[] = 'Todos los aprobadores deben haber aprobado';
        }
        if (($invoice->dian_validation ?? null) !== InvoiceConstants::DIAN_APPROVED) {
            $errors[] = 'Validación DIAN debe ser "Aprobada"';
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

- [ ] **Step 3: Crear `src/Service/Pipeline/State/ContabilidadState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class ContabilidadState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_CONTABILIDAD;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return InvoiceConstants::STATUS_APROBACION;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::CONTABILIDAD, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::CONTABILIDAD,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        $errors = [];
        if (!(bool)($invoice->accrued ?? false)) {
            $errors[] = 'La factura debe estar marcada como Causada';
        }
        $accrualDate = $invoice->accrual_date ?? null;
        if ($accrualDate === null || $accrualDate === '' || $accrualDate === false) {
            $errors[] = 'Fecha de Causación es requerida';
        }
        $readyForPayment = $invoice->ready_for_payment ?? null;
        if ($readyForPayment === null || $readyForPayment === '' || $readyForPayment === false) {
            $errors[] = 'Campo "Lista para Pago" es requerido';
        }

        return $errors;
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => 'accrued',           'label' => 'La factura debe estar marcada como Causada'],
            ['field' => 'accrual_date',      'label' => 'Fecha de Causación es requerida'],
            ['field' => 'ready_for_payment', 'label' => 'Campo "Lista para Pago" es requerido'],
        ];
    }
}
```

- [ ] **Step 4: Crear `src/Service/Pipeline/State/TesoreriaState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\InvoicePipelineState;

final class TesoreriaState implements InvoicePipelineState
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    public function getName(): string
    {
        return InvoiceConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getPrevious(): ?string
    {
        return InvoiceConstants::STATUS_CONTABILIDAD;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::TESORERIA,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        if (!$this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['Debe registrar al menos un pago para avanzar a autorización'];
        }

        return [];
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_has_pending_payment', 'label' => 'Debe registrar al menos un pago para avanzar a autorización'],
        ];
    }
}
```

- [ ] **Step 5: Crear `src/Service/Pipeline/State/AutorizacionPagoState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\InvoicePipelineState;

final class AutorizacionPagoState implements InvoicePipelineState
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
    }

    public function getName(): string
    {
        return InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getNext(): ?string
    {
        return InvoiceConstants::STATUS_PAGADA;
    }

    public function getPrevious(): ?string
    {
        return InvoiceConstants::STATUS_TESORERIA;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::CONTADOR, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::TESORERIA,
            RoleConstants::CONTADOR,
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        if ($this->paymentService->hasPendingAuthorization((int)$invoice->id)) {
            return ['El pago pendiente debe ser autorizado por el Contador'];
        }

        return [];
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_payment_authorized', 'label' => 'El pago pendiente debe ser autorizado por el Contador'],
        ];
    }
}
```

- [ ] **Step 6: Crear `src/Service/Pipeline/State/PagadaState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class PagadaState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_PAGADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        return [];
    }

    public function getTransitionRules(): array
    {
        return [];
    }
}
```

- [ ] **Step 7: Crear `src/Service/Pipeline/State/LegalizadaState.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class LegalizadaState implements InvoicePipelineState
{
    public function getName(): string
    {
        return InvoiceConstants::STATUS_LEGALIZADA;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [
            RoleConstants::AUXILIAR_PERSONAL,
            RoleConstants::ASISTENTE_PERSONAL,
            RoleConstants::COORDINADOR_ADMIN,
            RoleConstants::ADMIN,
        ];
    }

    public function validateAdvance(object $invoice): array
    {
        return [];
    }

    public function getTransitionRules(): array
    {
        return [];
    }
}
```

- [ ] **Step 8: Crear `src/Service/Pipeline/InvoicePipelineStateRegistry.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Service\Pipeline\State\AprobacionState;
use App\Service\Pipeline\State\AutorizacionPagoState;
use App\Service\Pipeline\State\ContabilidadState;
use App\Service\Pipeline\State\LegalizadaState;
use App\Service\Pipeline\State\PagadaState;
use App\Service\Pipeline\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resuelve `pipeline_status` (string) → instancia concreta de InvoicePipelineState.
 * Es la única dependencia que necesita el coordinador para acceder a los estados.
 */
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
        foreach ([$aprobacion, $contabilidad, $tesoreria, $autorizacionPago, $pagada, $legalizada] as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): InvoicePipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, InvoicePipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}
```

- [ ] **Step 9: Registrar todos los States y el Registry en `Application::services()`.**

Modificar `src/Application.php`. Agregar imports al bloque `use` superior:

```php
use App\Service\Pipeline\InvoicePipelineStateRegistry;
use App\Service\Pipeline\State\AprobacionState;
use App\Service\Pipeline\State\AutorizacionPagoState;
use App\Service\Pipeline\State\ContabilidadState;
use App\Service\Pipeline\State\LegalizadaState;
use App\Service\Pipeline\State\PagadaState;
use App\Service\Pipeline\State\TesoreriaState;
```

Agregar bloque de bindings al final de la sección "Invoice domain" (después del binding de `InvoiceTransitionValidator` o `InvoicePipelineService`, antes de `// === Strategies ===`):

```php
// === Pipeline states (Plan 4 W9) — registrados pero aún no consumidos ===
$container->addShared(AprobacionState::class);
$container->addShared(ContabilidadState::class);
$container->addShared(TesoreriaState::class)
    ->addArgument(InvoicePaymentService::class);
$container->addShared(AutorizacionPagoState::class)
    ->addArgument(InvoicePaymentService::class);
$container->addShared(PagadaState::class);
$container->addShared(LegalizadaState::class);
$container->addShared(InvoicePipelineStateRegistry::class)
    ->addArguments([
        AprobacionState::class,
        ContabilidadState::class,
        TesoreriaState::class,
        AutorizacionPagoState::class,
        PagadaState::class,
        LegalizadaState::class,
    ]);
```

- [ ] **Step 10: Verificación estática.**

```bash
ls src/Service/Pipeline/State/
```

Expected: 6 archivos `.php` (Aprobacion, Autorizacion, Contabilidad, Legalizada, Pagada, Tesoreria).

```bash
grep -rn "implements InvoicePipelineState" src/Service/Pipeline/State/ | wc -l
```

Expected: `6`.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 11: Validación manual (referencia para el usuario).**

Como ningún State se consume todavía, no hay regresión funcional posible. La única validación útil aquí es **arrancar la app** y verificar que no haya error de DI:

- Cargar la home / login. Si hay un error tipo "InvocationException" del container, falta alguna dep en `Application::services()`.

- [ ] **Step 12: Commit.**

```bash
git add src/Service/Pipeline/ src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): crear interfaz InvoicePipelineState + 6 estados + registry (W9)

Agrega la jerarquía polimórfica de estados (AprobacionState,
ContabilidadState, TesoreriaState, AutorizacionPagoState, PagadaState,
LegalizadaState) y el Registry que mapea pipeline_status -> instancia.
Todo registrado en DI pero aún no consumido por el coordinador (Task 5
hace la conexión).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Crear interfaz `DocumentTypePolicy`, las 3 policies y el `Factory`

**Files:**
- Create: `src/Service/Pipeline/DocumentTypePolicy.php`
- Create: `src/Service/Pipeline/DocumentTypePolicyFactory.php`
- Create: `src/Service/Pipeline/Policy/StandardDocumentTypePolicy.php`
- Create: `src/Service/Pipeline/Policy/AnticipoDocumentTypePolicy.php`
- Create: `src/Service/Pipeline/Policy/LegalizacionDocumentTypePolicy.php`
- Modify: `src/Application.php` (registrar todos en DI)

**Por qué cuarto:** Aún sin cambio funcional. Igual que Task 3: se crea el grafo de policies y se registra en DI, pero **nadie las consume todavía**. Tasks 5, 6, 7 las conectarán.

- [ ] **Step 1: Crear `src/Service/Pipeline/DocumentTypePolicy.php` (interfaz).**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

/**
 * Encapsula las reglas que diferencian a un document_type del flujo normal.
 *
 * Cualquier rama `if ($documentType === DOCTYPE_*)` que hoy vive en pipeline,
 * payment o field-access policy se sustituye por una llamada a la policy
 * correspondiente. La factory siempre devuelve una policy concreta — los
 * doctypes sin reglas especiales caen en StandardDocumentTypePolicy.
 */
interface DocumentTypePolicy
{
    /** Sentinel '*' para Standard; valor de InvoiceConstants::DOCTYPE_* para los demás. */
    public function getDocumentType(): string;

    /**
     * Mensaje si el doctype bloquea avance desde el estado actual; null = no bloquea.
     * Usado por Legalización en `contabilidad`.
     */
    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string;

    /**
     * Estados visuales del pipeline (Standard/Anticipo: 5; Legalización: 3).
     *
     * @return array<string>
     */
    public function getPipelineStatusesForView(): array;

    /**
     * Filtra secciones visibles que no aplican a este doctype.
     *
     * @param array<string> $sections
     * @return array<string>
     */
    public function filterVisibleSections(array $sections): array;

    /** ¿Avanzar a $newStatus dispara auto-init de la legalización? Sólo Anticipo cuando newStatus = pagada. */
    public function triggersAutoLegalization(string $newStatus): bool;

    /** Mensaje si el doctype bloquea regresión por su propio estado; null = no. */
    public function getRegressionLockReason(object $invoice): ?string;

    /** ¿Permite is_refund=true en sus pagos? Sólo Anticipo. */
    public function allowsRefundPayments(): bool;
}
```

- [ ] **Step 2: Crear `src/Service/Pipeline/Policy/StandardDocumentTypePolicy.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\DocumentTypePolicy;
use App\Service\Pipeline\InvoicePipelineState;

/**
 * Policy por defecto para los doctypes sin comportamiento especial:
 * Factura, Nota Débito, Caja Menor, Tarjeta de Crédito, Reintegro, Recibo.
 */
final class StandardDocumentTypePolicy implements DocumentTypePolicy
{
    public function getDocumentType(): string
    {
        return '*';
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
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

    public function triggersAutoLegalization(string $newStatus): bool
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

- [ ] **Step 3: Crear `src/Service/Pipeline/Policy/AnticipoDocumentTypePolicy.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\InvoiceConstants;
use App\Service\AdvanceLegalizationService;
use App\Service\Pipeline\DocumentTypePolicy;
use App\Service\Pipeline\InvoicePipelineState;

/**
 * Reglas específicas de los Anticipos:
 *  - al pagar (transition a `pagada`) se dispara auto-init de legalización,
 *  - una vez iniciada la legalización, la regresión queda bloqueada,
 *  - permite is_refund=true en sus pagos (devoluciones de Anticipo).
 */
final class AnticipoDocumentTypePolicy implements DocumentTypePolicy
{
    public function __construct(
        private readonly AdvanceLegalizationService $advanceLegalizationService,
    ) {
    }

    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_ANTICIPO;
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        return null;
    }

    public function getPipelineStatusesForView(): array
    {
        return InvoiceConstants::PIPELINE_STATUSES;
    }

    public function filterVisibleSections(array $sections): array
    {
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => $s !== 'revision',
        ));
    }

    public function triggersAutoLegalization(string $newStatus): bool
    {
        return $newStatus === InvoiceConstants::STATUS_PAGADA;
    }

    public function getRegressionLockReason(object $invoice): ?string
    {
        if (
            !empty($invoice->id)
            && $this->advanceLegalizationService->hasLegalization((int)$invoice->id)
        ) {
            return 'No se puede regresar: la legalización del anticipo ya fue iniciada.';
        }

        return null;
    }

    public function allowsRefundPayments(): bool
    {
        return true;
    }
}
```

- [ ] **Step 4: Crear `src/Service/Pipeline/Policy/LegalizacionDocumentTypePolicy.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Policy;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\DocumentTypePolicy;
use App\Service\Pipeline\InvoicePipelineState;

/**
 * Reglas específicas de las Legalizaciones:
 *  - pipeline visual corto: aprobacion → contabilidad → legalizada,
 *  - en `contabilidad` no se avanza manualmente (lo dispara legalizeLinkedInvoices),
 *  - secciones de tesorería y autorización de pago no aplican.
 */
final class LegalizacionDocumentTypePolicy implements DocumentTypePolicy
{
    public function getDocumentType(): string
    {
        return InvoiceConstants::DOCTYPE_LEGALIZACION;
    }

    public function blocksAdvance(InvoicePipelineState $state, object $invoice): ?string
    {
        if ($state->getName() === InvoiceConstants::STATUS_CONTABILIDAD) {
            return 'La legalización avanzará automáticamente cuando el Anticipo padre se legalice.';
        }

        return null;
    }

    public function getPipelineStatusesForView(): array
    {
        return InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION;
    }

    public function filterVisibleSections(array $sections): array
    {
        return array_values(array_filter(
            $sections,
            static fn(string $s): bool => !in_array($s, ['treasury', 'payment_authorization'], true),
        ));
    }

    public function triggersAutoLegalization(string $newStatus): bool
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

- [ ] **Step 5: Crear `src/Service/Pipeline/DocumentTypePolicyFactory.php`.**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Policy\StandardDocumentTypePolicy;

/**
 * Mapea document_type → DocumentTypePolicy concreta.
 * Siempre devuelve algo (cae a StandardDocumentTypePolicy); los consumidores
 * nunca verifican null.
 */
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
            InvoiceConstants::DOCTYPE_ANTICIPO     => $anticipo,
            InvoiceConstants::DOCTYPE_LEGALIZACION => $legalizacion,
        ];
    }

    public function for(?string $documentType): DocumentTypePolicy
    {
        return $this->byType[$documentType] ?? $this->standard;
    }
}
```

- [ ] **Step 6: Registrar todos en `Application::services()`.**

Modificar `src/Application.php`. Agregar imports:

```php
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Policy\StandardDocumentTypePolicy;
```

Agregar bloque de bindings inmediatamente después del bloque de States (Task 3 step 9):

```php
// === Document type policies (Plan 4 W10) — registrados pero aún no consumidos ===
$container->addShared(StandardDocumentTypePolicy::class);
$container->addShared(AnticipoDocumentTypePolicy::class)
    ->addArgument(AdvanceLegalizationService::class);
$container->addShared(LegalizacionDocumentTypePolicy::class);
$container->addShared(DocumentTypePolicyFactory::class)
    ->addArguments([
        StandardDocumentTypePolicy::class,
        AnticipoDocumentTypePolicy::class,
        LegalizacionDocumentTypePolicy::class,
    ]);
```

- [ ] **Step 7: Verificación estática.**

```bash
ls src/Service/Pipeline/Policy/
```

Expected: 3 archivos `.php` (Anticipo, Legalizacion, Standard).

```bash
grep -rn "implements DocumentTypePolicy" src/Service/Pipeline/Policy/ | wc -l
```

Expected: `3`.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 8: Validación manual (referencia para el usuario).**

Igual que Task 3: las policies aún no se consumen, así que la única regresión posible es DI mal armado. Verificar que la app arranca:

- Cargar home / login. Si hay `InvocationException`, falta una dep en el factory.

- [ ] **Step 9: Commit.**

```bash
git add src/Service/Pipeline/DocumentTypePolicy.php src/Service/Pipeline/DocumentTypePolicyFactory.php src/Service/Pipeline/Policy/ src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): crear interfaz DocumentTypePolicy + 3 policies + factory (W10)

Agrega la jerarquía polimórfica de doctype policies (Standard como
default, Anticipo, Legalización) y el factory que las mapea desde
document_type. Todo registrado en DI pero aún no consumido (Task 5
conecta el coordinador, Task 6 InvoicePaymentService).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Migrar `InvoicePipelineService` a usar States + Policies (full delegación)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (reescritura del coordinador)
- Modify: `src/Service/InvoiceTransitionValidator.php` (conectar al StateRegistry y DocumentTypePolicyFactory)
- Modify: `src/Application.php` (actualizar bindings del coordinador y del validator)

**Por qué quinto:** Es el commit más grande del plan. Aquí el coordinador deja de tener constantes legacy (`ROLE_VISIBLE_STATUSES`, `ADVANCE_VISIBLE_STATUSES`, `BACKWARD_TRANSITIONS`) y la lógica especial de doctype (`getNextStatus` con DOCTYPE_LEGALIZACION, auto-init en `saveAndAdvance`, regression lock cross-aggregate). Todo eso pasa a delegación pura. **Plan partido en sub-pasos** para mantener la app arrancable después de cada bloque.

**Importante:** este task no debe partirse en commits separados — los cambios al coordinador y al validator son interdependientes. Si algo se rompe a mitad de implementación, hacer rollback completo y reintentar.

- [ ] **Step 1: Reescribir `InvoiceTransitionValidator` para que use `InvoicePipelineStateRegistry` y `DocumentTypePolicyFactory`.**

Reemplazar **el archivo completo** `src/Service/InvoiceTransitionValidator.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\InvoicePipelineStateRegistry;

/**
 * Orquesta la validación de avance del pipeline:
 *  - rejection bloquea todo avance,
 *  - DocumentTypePolicy puede bloquear con un mensaje (Legalización en contabilidad),
 *  - el State del estado actual valida sus requirements.
 *
 * También filtra los errores que un rol puede resolver desde el formulario.
 */
final class InvoiceTransitionValidator
{
    /** Mapeo requirement-field → campos del form que lo resuelven. */
    private const REQUIREMENT_FIELDS = [
        'area_approval'        => [],
        'dian_validation'      => ['dian_validation'],
        'accrued'              => ['accrued', 'accrual_date'],
        'accrual_date'         => ['accrual_date'],
        'ready_for_payment'    => ['ready_for_payment'],
        '_has_pending_payment' => [],
        '_payment_authorized'  => [],
    ];

    public function __construct(
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $policies,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
    ) {
    }

    /**
     * Errores de avance: rejection + doctype block + state validation.
     *
     * @return array<string>
     */
    public function validateAdvance(object $invoice, string $fromStatus): array
    {
        if (($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED) {
            return ['La factura fue rechazada. El flujo ha terminado.'];
        }

        $state = $this->states->get($fromStatus);
        $policy = $this->policies->for($invoice->document_type ?? null);

        $blockMsg = $policy->blocksAdvance($state, $invoice);
        if ($blockMsg !== null) {
            return [$blockMsg];
        }

        return $state->validateAdvance($invoice);
    }

    /**
     * @return array<int, array{field: string, label: string}>
     */
    public function getTransitionRules(string $fromStatus): array
    {
        return $this->states->get($fromStatus)->getTransitionRules();
    }

    /**
     * @param array<string> $errors
     * @param array<int, array{field: string, label: string}> $rules
     * @return array<string>
     */
    public function filterErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        if ($roleName === RoleConstants::ADMIN) {
            return array_values($errors);
        }

        $editable = $this->fieldPolicy->getEditableFields($roleName, $status);
        $statusVisible = in_array($roleName, $this->states->get($status)->getRoleVisibility(), true);

        $filtered = [];
        foreach ($rules as $i => $rule) {
            if (!isset($errors[$i])) {
                continue;
            }
            $field = $rule['field'];
            $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];

            if ($responsible === []) {
                if ($statusVisible) {
                    $filtered[] = $errors[$i];
                }
                continue;
            }

            if (array_intersect($responsible, $editable)) {
                $filtered[] = $errors[$i];
            }
        }

        return $filtered;
    }
}
```

- [ ] **Step 2: Actualizar binding de `InvoiceTransitionValidator` en `Application::services()`.**

Modificar `src/Application.php`. Cambiar el binding actual:

```php
$container->addShared(InvoiceTransitionValidator::class)
    ->addArguments([
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
    ]);
```

por:

```php
$container->addShared(InvoiceTransitionValidator::class)
    ->addArguments([
        InvoicePipelineStateRegistry::class,
        DocumentTypePolicyFactory::class,
        InvoiceFieldAccessPolicy::class,
    ]);
```

**Importante:** este binding ahora depende de `InvoicePipelineStateRegistry` y `DocumentTypePolicyFactory`. Asegurarse que estos dos bindings ya estén declarados ANTES (en Task 3 step 9 y Task 4 step 6). Si las secciones quedaron en otro orden, mover el bloque del validator después.

- [ ] **Step 3: Reescribir `InvoicePipelineService` por completo.**

Reemplazar **el archivo completo** `src/Service/InvoicePipelineService.php` por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Invoice;
use App\Service\Interface\HistoryServiceInterface;
use App\Service\Pipeline\DocumentTypePolicyFactory;
use App\Service\Pipeline\InvoicePipelineStateRegistry;
use Cake\ORM\TableRegistry;

/**
 * Coordinador delgado del pipeline de facturas.
 * Delega a States, DocumentTypePolicy, LockPolicy y TransitionValidator.
 *
 * API pública preservada para no romper callers (controllers, strategies, templates).
 */
class InvoicePipelineService
{
    public function __construct(
        private readonly HistoryServiceInterface $historyService,
        private readonly InvoicePaymentService $paymentService,
        private readonly InvoiceFieldAccessPolicy $fieldPolicy,
        private readonly AdvanceLegalizationService $advanceLegalizationService,
        private readonly InvoiceLockPolicy $lockPolicy,
        private readonly InvoiceTransitionValidator $transitionValidator,
        private readonly InvoicePipelineStateRegistry $states,
        private readonly DocumentTypePolicyFactory $docTypePolicies,
    ) {
    }

    public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;

    public const ALL_STATUSES = [
        InvoiceConstants::STATUS_APROBACION,
        InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
        InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
        InvoiceConstants::STATUS_PAGADA            => 'Pagada',
        InvoiceConstants::STATUS_LEGALIZADA        => 'Legalizada',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION        => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_CONTABILIDAD      => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_TESORERIA         => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => InvoiceConstants::STATUS_PAGADA,
        InvoiceConstants::STATUS_PAGADA            => null,
        InvoiceConstants::STATUS_LEGALIZADA        => null,
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        $result = [];
        foreach ($this->states->all() as $name => $state) {
            if (in_array($roleName, $state->getRoleVisibility(), true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function getVisibleAdvanceStatuses(string $roleName): array
    {
        $result = [];
        foreach ($this->states->all() as $name => $state) {
            if ($name === InvoiceConstants::STATUS_PAGADA || $name === InvoiceConstants::STATUS_LEGALIZADA) {
                // ADVANCE_ACTIVE_STATUSES excluye terminales para "Mis Anticipos"
                continue;
            }
            if (in_array($roleName, $state->getAdvanceRoleVisibility(), true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public function getPipelineStatusesFor(?string $documentType = null): array
    {
        return $this->docTypePolicies->for($documentType)->getPipelineStatusesForView();
    }

    public function getEditableFields(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getEditableFields($roleName, $status);
    }

    public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
    {
        $sections = $this->fieldPolicy->getVisibleSections($roleName, $status);

        return $this->docTypePolicies->for($documentType)->filterVisibleSections($sections);
    }

    public function getCollapsibleSections(string $roleName, string $status): array
    {
        return $this->fieldPolicy->getCollapsibleSections($roleName, $status);
    }

    public function isRejected(object $invoice): bool
    {
        if ($invoice instanceof Invoice) {
            return $invoice->isRejected();
        }

        return ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
    }

    public function isLockedByPaidScheduling(int $invoiceId): bool
    {
        return $this->lockPolicy->isLockedByPaidScheduling($invoiceId);
    }

    public function isLockedByPettyCash(object $invoice): bool
    {
        return $this->lockPolicy->isLockedByPettyCash($invoice);
    }

    public function getEditLockMessage(object $invoice): ?string
    {
        return $this->lockPolicy->getEditLockMessage($invoice);
    }

    public function getRegressionLockMessage(object $invoice): ?string
    {
        $lockMsg = $this->lockPolicy->getRegressionLockMessage($invoice);
        if ($lockMsg !== null) {
            return $lockMsg;
        }

        return $this->docTypePolicies->for($invoice->document_type ?? null)->getRegressionLockReason($invoice);
    }

    public function validateTransitionRequirements(object $invoice, string $fromStatus): array
    {
        return $this->transitionValidator->validateAdvance($invoice, $fromStatus);
    }

    public function getTransitionRules(string $fromStatus): array
    {
        return $this->transitionValidator->getTransitionRules($fromStatus);
    }

    public function filterAdvanceErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
    {
        return $this->transitionValidator->filterErrorsForRole($errors, $rules, $roleName, $status);
    }

    public function canAdvance(string $roleName, string $currentStatus, ?string $documentType = null): bool
    {
        if ($this->getNextStatus($currentStatus, $documentType) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return in_array($currentStatus, $this->getVisibleStatuses($roleName), true);
    }

    public function getNextStatus(string $currentStatus, ?string $documentType = null): ?string
    {
        $state = $this->states->get($currentStatus);
        $policy = $this->docTypePolicies->for($documentType);

        // Cuando la policy bloquea el avance del estado, el next efectivo es null.
        // Pasamos un stdClass con document_type para mantener compat con la firma de blocksAdvance.
        $stub = (object)['document_type' => $documentType, 'pipeline_status' => $currentStatus];
        if ($policy->blocksAdvance($state, $stub) !== null) {
            return null;
        }

        return $state->getNext();
    }

    public function filterEntityData(array $data, string $roleName, string $status): array
    {
        return $this->fieldPolicy->filterEntityData($data, $roleName, $status);
    }

    public function getStatusIndex(string $status): int
    {
        $index = array_search($status, self::STATUSES);

        return $index !== false ? $index : 0;
    }

    public function getPreviousStatus(string $currentStatus): ?string
    {
        return $this->states->get($currentStatus)->getPrevious();
    }

    public function canRegress(string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return in_array($currentStatus, $this->getVisibleStatuses($roleName), true);
    }

    /**
     * Save invoice fields, optionally advance the pipeline, and record history.
     *
     * Returns:
     *   - 'saved'          => bool
     *   - 'advanced'       => bool
     *   - 'nextStatus'     => ?string
     *   - 'advanceErrors'  => string[]
     */
    public function saveAndAdvance(
        Invoice $invoice,
        array $data,
        string $roleName,
        int $userId,
        ?string $baseUrl = null,
    ): array {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $currentStatus = $invoice->pipeline_status;
        $filteredData = $this->filterEntityData($data, $roleName, $currentStatus);

        // Auto-set area_approval_date when area_approval changes to Aprobada or Rechazada
        if (array_key_exists('area_approval', $filteredData)) {
            $newApproval = $filteredData['area_approval'] ?? '';
            $oldApproval = $invoice->area_approval ?? '';
            if (
                $newApproval !== $oldApproval
                && in_array($newApproval, [InvoiceConstants::APPROVAL_APPROVED, InvoiceConstants::APPROVAL_REJECTED])
            ) {
                $invoice->area_approval_date = date('Y-m-d');
            }
        }

        $canAdvance = $this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null);
        $isRejected = $this->isRejected($invoice);

        $advanceNextStatus = null;
        $postAdvanceErrors = [];
        if ($canAdvance && !$isRejected) {
            $testEntity = $invoicesTable->patchEntity(clone $invoice, $filteredData);
            $postAdvanceErrors = $this->validateTransitionRequirements($testEntity, $currentStatus);
            if (empty($postAdvanceErrors)) {
                $advanceNextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
            }
        }

        $original = clone $invoice;

        $saved = $invoicesTable->getConnection()->transactional(
            function () use ($invoicesTable, &$invoice, $filteredData, $advanceNextStatus, $currentStatus, $userId, $original) {
                $invoice = $invoicesTable->patchEntity($invoice, $filteredData);

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordChanges($original, $invoice, $userId);

                if ($advanceNextStatus) {
                    $invoice->pipeline_status = $advanceNextStatus;
                    if (!$invoicesTable->save($invoice)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $invoice->id,
                        $currentStatus,
                        $advanceNextStatus,
                        $userId,
                    );

                    // After advancing from autorizacion_pago: regress to tesoreria if pago parcial
                    if ($currentStatus === InvoiceConstants::STATUS_AUTORIZACION_PAGO) {
                        $this->paymentService->recalculatePaymentStatus($invoice->id);
                        $refreshed = $invoicesTable->get($invoice->id);

                        if ($refreshed->payment_status === InvoiceConstants::PAYMENT_PARTIAL) {
                            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
                            $advanceNextStatus = InvoiceConstants::STATUS_TESORERIA;
                            $invoicesTable->save($invoice);
                            $this->historyService->recordStatusChange(
                                $invoice->id,
                                InvoiceConstants::STATUS_PAGADA,
                                InvoiceConstants::STATUS_TESORERIA,
                                $userId,
                            );
                        }
                    }

                    // Auto-init de legalización cuando la doctype policy lo dispara (Anticipo → pagada).
                    if ($this->docTypePolicies->for($invoice->document_type ?? null)->triggersAutoLegalization($invoice->pipeline_status)) {
                        $this->advanceLegalizationService->initialize($invoice, $userId);
                    }
                }

                return true;
            },
        );

        return [
            'saved' => (bool)$saved,
            'advanced' => (bool)$advanceNextStatus && (bool)$saved,
            'nextStatus' => $advanceNextStatus,
            'advanceErrors' => $postAdvanceErrors,
        ];
    }

    /**
     * Standalone advance (without field edits). Used by the legacy advanceStatus route.
     *
     * @return array{success: bool, error: ?string, nextStatus: ?string}
     */
    public function advance(Invoice $invoice, string $roleName, int $userId): array
    {
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null)) {
            return ['success' => false, 'error' => 'No tiene permisos para avanzar esta factura.', 'nextStatus' => null];
        }

        if ($this->isRejected($invoice)) {
            return ['success' => false, 'error' => 'La factura fue rechazada. El flujo ha terminado.', 'nextStatus' => null];
        }

        $errors = $this->validateTransitionRequirements($invoice, $currentStatus);
        if (!empty($errors)) {
            return ['success' => false, 'error' => implode(' ', $errors), 'nextStatus' => null];
        }

        $nextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
        if (!$nextStatus) {
            return ['success' => false, 'error' => 'Esta factura ya está en el estado final.', 'nextStatus' => null];
        }

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice->pipeline_status = $nextStatus;

        if (!$invoicesTable->save($invoice)) {
            return ['success' => false, 'error' => 'No se pudo avanzar el estado.', 'nextStatus' => null];
        }

        $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $userId);

        return [
            'success' => true,
            'error' => null,
            'nextStatus' => $nextStatus,
        ];
    }

    /**
     * Regress the invoice to its previous pipeline status (cold regression).
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        Invoice $invoice,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canRegress($roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta factura ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta factura.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($invoice);
        if ($lock !== null) {
            return ['success' => false, 'error' => $lock, 'previousStatus' => null];
        }

        if (mb_strlen($reason) < 10) {
            return [
                'success' => false,
                'error' => 'El motivo es obligatorio (mínimo 10 caracteres).',
                'previousStatus' => null,
            ];
        }
        if (mb_strlen($reason) > 500) {
            return [
                'success' => false,
                'error' => 'El motivo no puede superar 500 caracteres.',
                'previousStatus' => null,
            ];
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $invoicesTable,
                $observationsTable,
                $invoice,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
            ): bool {
                $invoice->pipeline_status = $previousStatus;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordStatusChange(
                    $invoice->id,
                    $currentStatus,
                    $previousStatus,
                    $userId,
                );

                $observation = $observationsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'user_id' => $userId,
                    'type' => InvoiceConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                return (bool)$observationsTable->save($observation);
            },
        );

        if (!$ok) {
            return [
                'success' => false,
                'error' => 'No se pudo regresar la factura. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }

    /**
     * Promueve a `legalizada` todas las facturas tipo Legalización vinculadas al
     * Anticipo dado que estén actualmente en `contabilidad`. Disparado por
     * AdvanceLegalizationService cuando el Anticipo padre llega a STATUS_LEGALIZADA.
     *
     * Plan 5 (Domain Events) moverá este método a un servicio dedicado.
     *
     * @return int Cantidad de facturas promovidas.
     */
    public function legalizeLinkedInvoices(int $advanceInvoiceId, int $userId): int
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

        $linked = $invoicesTable->find()
            ->where([
                'advance_id' => $advanceInvoiceId,
                'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,
                'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            ])
            ->all();

        if ($linked->isEmpty()) {
            return 0;
        }

        $count = 0;
        $invoicesTable->getConnection()->transactional(
            function () use ($linked, $userId, &$count, $invoicesTable): bool {
                foreach ($linked as $inv) {
                    $from = $inv->pipeline_status;
                    $inv->pipeline_status = InvoiceConstants::STATUS_LEGALIZADA;
                    if (!$invoicesTable->save($inv)) {
                        return false;
                    }
                    $this->historyService->recordStatusChange(
                        $inv->id,
                        $from,
                        InvoiceConstants::STATUS_LEGALIZADA,
                        $userId,
                    );
                    $count++;
                }

                return true;
            },
        );

        return $count;
    }
}
```

- [ ] **Step 4: Actualizar binding del coordinador en `Application::services()`.**

```php
$container->addShared(InvoicePipelineService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        InvoicePaymentService::class,
        InvoiceFieldAccessPolicy::class,
        AdvanceLegalizationService::class,
        InvoiceLockPolicy::class,
        InvoiceTransitionValidator::class,
        InvoicePipelineStateRegistry::class,
        DocumentTypePolicyFactory::class,
    ]);
```

- [ ] **Step 5: Verificación estática.**

```bash
wc -l src/Service/InvoicePipelineService.php
```

Expected: ≤ 350 líneas (meta del spec ≤ 300, con margen para PHPDoc y `legalizeLinkedInvoices`).

```bash
grep -n "TRANSITION_REQUIREMENTS\|REQUIREMENT_FIELDS\|ROLE_VISIBLE_STATUSES\|ADVANCE_VISIBLE_STATUSES\|ADVANCE_ACTIVE_STATUSES\|BACKWARD_TRANSITIONS" src/Service/InvoicePipelineService.php
```

Expected: cero ocurrencias (todas las constantes legacy migraron).

```bash
grep -n "DOCTYPE_LEGALIZACION\|DOCTYPE_ANTICIPO" src/Service/InvoicePipelineService.php
```

Expected: **una sola ocurrencia** — la línea `'document_type' => InvoiceConstants::DOCTYPE_LEGALIZACION,` dentro de la query de `legalizeLinkedInvoices()`. Esa referencia es legítima (el método existe específicamente para promover Legalizaciones; no es un branch de behavior). Si aparecen otras ocurrencias (en `getNextStatus`, `validateTransitionRequirements`, `saveAndAdvance` o `getRegressionLockMessage`), es bug del refactor — esos branches deben estar todos delegados a `DocumentTypePolicy`.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 6: Validación manual (referencia para el usuario — esta es la validación más crítica del plan).**

Flujos a probar exhaustivamente, dado que aquí cambia el cableado del coordinador:

**Pipeline normal de Factura:**
- Como Registro/Revisión: ver lista de facturas en `aprobacion`, abrir una, marcarla `area_approval=Aprobada` y `dian_validation=Aprobada`, avanzar. Verificar mensaje "Próximo paso: Contabilidad".
- Como Contabilidad: marcar `accrued=true`, fecha de causación, `ready_for_payment`. Avanzar. Verificar "Próximo paso: Tesorería".
- Como Tesorería: registrar un pago. Verificar que la factura avanza a `autorizacion_pago` automáticamente.
- Como Contador: autorizar el pago. Verificar que la factura avanza a `pagada`.

**Anticipo:**
- Crear Anticipo, llevarlo hasta `pagada`. Verificar que se inicializa la legalización (debe aparecer una factura tipo Legalización vinculada).
- Intentar regresar el Anticipo: debe bloquearse con "No se puede regresar: la legalización del anticipo ya fue iniciada."

**Legalización:**
- Abrir una factura Legalización en `contabilidad`. Intentar avanzar: debe mostrar "La legalización avanzará automáticamente cuando el Anticipo padre se legalice."
- Llevar el Anticipo padre a `legalizada` (registrar y autorizar la legalización). Verificar que la factura Legalización vinculada salta a `legalizada`.

**Bloqueos:**
- Factura asociada a Caja Menor: edición y regresión bloqueadas con mensaje correspondiente.
- Factura con pago en programación pagada: igual.

**Rechazo:**
- Marcar factura como `area_approval=Rechazada`. Intentar avanzar: debe bloquearse con "La factura fue rechazada. El flujo ha terminado."
- Como Registro/Revisión, ejecutar "Reiniciar flujo" en factura rechazada. Verificar que vuelve a `aprobacion` con `area_approval=Pendiente`.

**Pago parcial:**
- Como Contador, autorizar un pago parcial (menor al total de la factura). Verificar que la factura **regresa** a `tesoreria` automáticamente para registrar más pagos.

**Filtrado de errores por rol:**
- Como Contabilidad, intentar avanzar una factura que también requiere acción de Tesorería. Sólo deben mostrarse los errores que Contabilidad puede resolver.

Cualquier divergencia con el comportamiento previo es bug del refactor. Investigar antes de hacer commit.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/InvoicePipelineService.php src/Service/InvoiceTransitionValidator.php src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): migrar InvoicePipelineService a States + Policies (W9 + W10 parcial)

El coordinador queda como delegador delgado. Constantes legacy
ROLE_VISIBLE_STATUSES, ADVANCE_VISIBLE_STATUSES, BACKWARD_TRANSITIONS
eliminadas; visibilidad y transiciones ahora viven en cada *State.
Branches por DOCTYPE_LEGALIZACION y DOCTYPE_ANTICIPO en getNextStatus,
saveAndAdvance (auto-init) y getRegressionLockMessage delegados a
DocumentTypePolicy. InvoiceTransitionValidator pasa a depender del
StateRegistry y la PolicyFactory.

API publica del coordinador preservada: cero cambios en controllers,
strategy o templates.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Migrar `InvoicePaymentService` a usar `DocumentTypePolicy`

**Files:**
- Modify: `src/Service/InvoicePaymentService.php` (constructor + `registerPayment` + `authorizePayment`)
- Modify: `src/Application.php` (binding del payment service)

**Por qué sexto:** Cierra W10 en `InvoicePaymentService`. Después de Task 5, el coordinador y el field-access policy ya no tienen branches por doctype. Aquí se elimina el último consumidor.

- [ ] **Step 1: Inyectar `DocumentTypePolicyFactory` en `InvoicePaymentService`.**

Modificar `src/Service/InvoicePaymentService.php`. Cambiar el constructor (líneas 16-20):

```php
public function __construct(
    private readonly InvoiceHistoryService $historyService,
    private readonly AdvanceLegalizationService $advanceLegalizationService,
    private readonly \App\Service\Pipeline\DocumentTypePolicyFactory $docTypePolicies,
) {
}
```

Y agregar el `use` correspondiente al bloque de imports superior:

```php
use App\Service\Pipeline\DocumentTypePolicyFactory;
```

(Si se prefiere quitar el FQN del constructor, importar y usar `DocumentTypePolicyFactory` directamente.)

- [ ] **Step 2: Reemplazar el branch por doctype en `registerPayment`.**

Localizar en `src/Service/InvoicePaymentService.php:185`:

```php
if (!empty($paymentData['is_refund']) && $invoice->document_type !== InvoiceConstants::DOCTYPE_ANTICIPO) {
    return ServiceResult::fail('is_refund solo es válido en pagos de Anticipos.');
}
```

Reemplazar por:

```php
if (!empty($paymentData['is_refund']) && !$this->docTypePolicies->for($invoice->document_type)->allowsRefundPayments()) {
    return ServiceResult::fail('is_refund solo es válido en pagos de Anticipos.');
}
```

- [ ] **Step 3: Reemplazar el branch por doctype en `authorizePayment`.**

Localizar en `src/Service/InvoicePaymentService.php:151-156`:

```php
if (
    $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
    && ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
) {
    $this->advanceLegalizationService->initialize($invoice, $authorizedBy);
}
```

Reemplazar por:

```php
if ($this->docTypePolicies->for($invoice->document_type ?? null)->triggersAutoLegalization($invoice->pipeline_status)) {
    $this->advanceLegalizationService->initialize($invoice, $authorizedBy);
}
```

- [ ] **Step 4: Actualizar binding de `InvoicePaymentService` en `Application::services()`.**

```php
$container->addShared(InvoicePaymentService::class)
    ->addArguments([
        InvoiceHistoryService::class,
        AdvanceLegalizationService::class,
        DocumentTypePolicyFactory::class,
    ]);
```

- [ ] **Step 5: Verificación estática.**

```bash
grep -n "DOCTYPE_ANTICIPO\|DOCTYPE_LEGALIZACION" src/Service/InvoicePaymentService.php
```

Expected: cero ocurrencias.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 6: Validación manual (referencia para el usuario).**

- Registrar un pago con `is_refund=true` en una factura **no-Anticipo** (ej. Factura normal): debe rechazarse con "is_refund solo es válido en pagos de Anticipos."
- Registrar un pago con `is_refund=true` en un Anticipo: debe permitirse.
- Autorizar el pago final (pago total) de un Anticipo: debe inicializarse la legalización automáticamente (factura Legalización vinculada aparece en `aprobacion`/`contabilidad`).
- Autorizar el pago final de una factura no-Anticipo: NO debe disparar legalización.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/InvoicePaymentService.php src/Application.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): InvoicePaymentService usa DocumentTypePolicy (W10 cierre)

registerPayment delega is_refund check a DocumentTypePolicy::allowsRefundPayments.
authorizePayment delega auto-init de legalización a DocumentTypePolicy::triggersAutoLegalization.
Cero branches por document_type en este servicio.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Cambiar firma de `InvoiceFieldAccessPolicy::getVisibleSections`

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php` (eliminar parámetro `$documentType`)
- Modify: `src/Service/InvoicePipelineService.php` (caller único — ya actualizado en Task 5)

**Por qué séptimo:** Cierra W10 en `InvoiceFieldAccessPolicy`. Tras Task 5, el coordinador ya hace la composición `policy->filterVisibleSections(fieldPolicy->getVisibleSections())` — pero el `fieldPolicy::getVisibleSections` todavía acepta y usa internamente `$documentType` (los dos `if` que filtran secciones). Aquí se elimina ese código duplicado.

- [ ] **Step 1: Eliminar el parámetro `$documentType` y los dos branches del método.**

Modificar `src/Service/InvoiceFieldAccessPolicy.php:62-78`:

Buscar:

```php
public function getVisibleSections(string $roleName, string $status, ?string $documentType = null): array
{
    $sections = $this->_resolveVisibleSections($roleName, $status);

    if ($documentType === InvoiceConstants::DOCTYPE_ANTICIPO) {
        $sections = array_values(array_filter($sections, static fn($s) => $s !== 'revision'));
    }

    if ($documentType === InvoiceConstants::DOCTYPE_LEGALIZACION) {
        $sections = array_values(array_filter(
            $sections,
            static fn($s) => !in_array($s, ['treasury', 'payment_authorization'], true),
        ));
    }

    return $sections;
}
```

Reemplazar por:

```php
public function getVisibleSections(string $roleName, string $status): array
{
    return $this->_resolveVisibleSections($roleName, $status);
}
```

- [ ] **Step 2: Verificación estática.**

```bash
grep -rn "fieldPolicy->getVisibleSections\|fieldAccessPolicy->getVisibleSections" --include="*.php" src/
```

Expected: las llamadas que queden no deben pasar el tercer argumento. La única llamada actual es `InvoicePipelineService::getVisibleSections` (Task 5 ya la actualizó).

```bash
grep -rn "InvoiceFieldAccessPolicy.*getVisibleSections\|getVisibleSections.*documentType" --include="*.php" src/
```

Expected: cero ocurrencias con `documentType` en la llamada.

```bash
grep -n "DOCTYPE_ANTICIPO\|DOCTYPE_LEGALIZACION" src/Service/InvoiceFieldAccessPolicy.php
```

Expected: cero ocurrencias.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 3: Validación manual (referencia para el usuario).**

- Abrir un Anticipo en cualquier estado: la sección "Revisión" debe estar oculta.
- Abrir una Legalización: las secciones "Tesorería" y "Autorización de Pago" deben estar ocultas.
- Abrir una factura normal: todas las secciones que correspondan al rol/estado deben verse.

- [ ] **Step 4: Commit.**

```bash
git add src/Service/InvoiceFieldAccessPolicy.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): InvoiceFieldAccessPolicy::getVisibleSections sin doctype (W10 cierre)

Elimina el parametro \$documentType y los dos branches de filtrado por
doctype. El filtrado lo hace ahora DocumentTypePolicy::filterVisibleSections
desde el coordinador (Task 5). InvoiceFieldAccessPolicy queda enfocado
solo en role/status.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Eliminar `Invoice::canAdvanceTo()` (W2)

**Files:**
- Modify: `src/Model/Entity/Invoice.php` (borrar método y PHPDoc)

**Por qué octavo:** Cierra W2. El método es código muerto (cero callers verificados durante brainstorming). Lo dejamos para el final por simplicidad — su eliminación no depende de nada y es trivial.

- [ ] **Step 1: Eliminar el método `canAdvanceTo()` de `Invoice`.**

Modificar `src/Model/Entity/Invoice.php`. Buscar (líneas 64-85):

```php
    /**
     * Check if the invoice can advance to the given status.
     *
     * @param string $nextStatus Target pipeline status.
     * @return bool
     */
    public function canAdvanceTo(string $nextStatus): bool
    {
        if ($this->isRejected()) {
            return false;
        }

        $statuses = InvoiceConstants::PIPELINE_STATUSES;
        $currentIndex = array_search($this->pipeline_status, $statuses);
        $nextIndex = array_search($nextStatus, $statuses);

        if ($currentIndex === false || $nextIndex === false) {
            return false;
        }

        return $nextIndex === $currentIndex + 1;
    }

```

Eliminar todo el bloque (incluyendo PHPDoc y la línea en blanco posterior). El método siguiente (`isOverdue`) queda inmediatamente después de `isPaid()`.

- [ ] **Step 2: Verificación estática.**

```bash
grep -rn "canAdvanceTo" --include="*.php" src/ templates/
```

Expected: cero ocurrencias.

```bash
composer cs-check
```

Expected: sin errores nuevos.

- [ ] **Step 3: Validación manual (referencia para el usuario).**

Como `canAdvanceTo` no tenía callers, no hay flujo que probar. La única validación útil es que la app sigue arrancando:

- Cargar `/invoices` y abrir cualquier factura. Si carga sin error, OK.

- [ ] **Step 4: Commit.**

```bash
git add src/Model/Entity/Invoice.php
git commit -m "$(cat <<'EOF'
refactor(plan-4): eliminar Invoice::canAdvanceTo() (W2)

Metodo muerto (cero callers verificados). La logica de transicion
es responsabilidad unica del coordinador y los States. Cierra W2.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Cierre del Plan 4

Tras los 8 commits, actualizar el roadmap:

- [ ] **Step 1: Marcar Plan 4 como completado en el roadmap.**

Modificar `docs/audits/architecture-audit-roadmap.md`:

1. En la tabla "Resumen ejecutivo": cambiar la fila del Plan 4 de `⬜ Pendiente` a `🟢 Completado`.
2. En la "Tabla de estado": rellenar las columnas Spec, Plan, PR (si aplica) y Cerrado con `2026-05-01` (o la fecha real de cierre).

- [ ] **Step 2: Commit del cierre.**

```bash
git add docs/audits/architecture-audit-roadmap.md
git commit -m "$(cat <<'EOF'
chore(plan-4): cierre del Plan 4 (refactor del pipeline)

C5, W2, W9 y W10 resueltos. InvoicePipelineService delegador delgado;
6 estados polimorficos; 3 doctype policies con default Standard;
InvoiceLockPolicy y InvoiceTransitionValidator extraidos.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-review (post-implementación)

Tras los 9 commits, verificar la salud arquitectónica del refactor:

- [ ] **LOC del coordinador.** `wc -l src/Service/InvoicePipelineService.php` → meta ≤ 300; ≤ 350 aceptable si la separación está bien.
- [ ] **OCP "agregar un estado".** Para agregar un estado nuevo, sólo deberían tocarse 2 archivos nuevos (la clase concreta + 1 línea en `Application::services()`) y 1 línea en el `Registry`. Verificar mentalmente con un caso hipotético.
- [ ] **OCP "agregar un doctype".** Para agregar un doctype con comportamiento especial: 1 archivo nuevo (la policy) + 2 líneas en el factory (mapa) + 1 línea en `Application::services()`.
- [ ] **Cero branches `DOCTYPE_*`** fuera de las policies y de `AdvanceLegalizationService` (que es legítimo). Verificar con `grep -rn "DOCTYPE_" --include="*.php" src/Service/ | grep -v "Pipeline/Policy/\|AdvanceLegalizationService\|InvoiceConstants"`.
- [ ] **API pública del coordinador preservada.** Ningún caller de `InvoicePipelineService` (controllers, strategy, templates, otros servicios) debió tocarse en Tasks 5–8.

---

## Out-of-scope (recordatorio)

| Item | Plan |
|------|------|
| Refactor de `NoveltyPipelineService` y `PaymentSchedulingPipelineService` | Posible Plan 8 |
| Romper ciclo Pipeline ↔ Payment ↔ Legalization | Plan 5 (Domain Events) |
| Migrar `saveAndAdvance` y `regress` a `ServiceResult` | Plan 7 (W15) |
| Migrar `Cake\Log\Log::*` a `StructuredLogger` inyectado | Plan 7 (W1) |
| Mover `EDITABLE_FIELDS` de `InvoiceFieldAccessPolicy` a States | Fuera de scope |
| Mover `legalizeLinkedInvoices` fuera del coordinador | Plan 5 |
