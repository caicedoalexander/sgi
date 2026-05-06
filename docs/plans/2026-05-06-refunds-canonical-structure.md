# Refunds — Migración a estructura canónica (Implementation Plan)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Llevar el módulo Refunds a la base canónica de la auditoría 2026-05-06: extraer Pipeline State pattern (5 estados con `validateAdvance` + `getRegressionLockMessage` por estado), eliminar `Trait/RefundPipelineHelpersTrait`, renombrar `Dto/RefundSyntheticPayment` a `BulkPaymentView` (compartido), crear `RefundAddViewModel` + `RefundEditViewModel`.

**Architecture:** Patrón canónico ya establecido por Plan B (Novelties). `RefundService` queda como coordinador (RBAC + transacciones + locks pesimistas + propagación a hijas + history). `Pipeline/Refund/State/*` con interfaz `getName/getNext/getPrevious/validateAdvance/getRegressionLockMessage` + Registry. ViewModels para `add` y `edit` del controller. `BulkPaymentView` se promueve a Dto compartido (PettyCash queda con TODO para adoptarlo en próxima sesión).

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB. Sin migraciones de BD (refactor 100% PHP).

**Design doc:** `docs/plans/2026-05-06-refunds-canonical-design.md`
**Audit fuente:** `docs/audits/flow-structure-audit-2026-05-06.md` (Plan C, promovido desde Backlog)
**Branch sugerido:** `refactor/refunds-canonical-structure`

**Política del proyecto (CLAUDE.md):** No hay tests automatizados. Cada task termina con **validación manual** específica en lugar de tests. Si la validación falla, no commit hasta resolver.

---

## Task 1: Crear interfaz `RefundPipelineState` y Registry (placeholder)

**Files:**
- Create: `src/Service/Pipeline/Refund/RefundPipelineState.php`
- Create: `src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`

**Step 1: Crear directorio**

```bash
mkdir -p src/Service/Pipeline/Refund/State
```

**Step 2: Crear interfaz**

Archivo `src/Service/Pipeline/Refund/RefundPipelineState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Model\Entity\Refund;

/**
 * Polymorphic representation of one Refund pipeline state.
 *
 * Each State knows its base transitions (next/previous) y dos métodos:
 * - validateAdvance: errores que impiden avanzar al siguiente estado.
 * - getRegressionLockMessage: mensaje de bloqueo si la regresión NO procede,
 *   null si la regresión está permitida desde este estado.
 *
 * Cross-cutting checks (RBAC, transacciones, propagación a hijas, history)
 * son responsabilidad del coordinador (RefundService).
 */
interface RefundPipelineState
{
    /** Canonical name (e.g. 'agrupacion'). */
    public function getName(): string;

    /** Base next state's name; null if terminal. */
    public function getNext(): ?string;

    /** Previous state's name; null if first state or regression intrínsecamente bloqueada. */
    public function getPrevious(): ?string;

    /**
     * Errores que impiden avanzar al siguiente estado.
     * Si el avance no aplica desde este estado (terminal, gestionado por otro flujo, etc.)
     * retornar un error explicativo (no array vacío).
     *
     * @return array<string>
     */
    public function validateAdvance(Refund $record): array;

    /**
     * Mensaje de bloqueo si la regresión NO procede; null si la regresión está
     * permitida. Solo `TesoreriaState` lo implementa con la regla del pago pendiente.
     */
    public function getRegressionLockMessage(Refund $record): ?string;
}
```

**Step 3: Crear Registry (placeholder hasta tener States — Task 2 los crea)**

Archivo `src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund;

use App\Service\Pipeline\Refund\State\AgrupacionState;
use App\Service\Pipeline\Refund\State\AutPagoState;
use App\Service\Pipeline\Refund\State\ContabilidadState;
use App\Service\Pipeline\Refund\State\PagadoState;
use App\Service\Pipeline\Refund\State\TesoreriaState;
use InvalidArgumentException;

/**
 * Resolves `refunds.status` (string) to a concrete State.
 * Sole dependency the coordinator (RefundService) needs to access states.
 */
final class RefundPipelineStateRegistry
{
    /** @var array<string, \App\Service\Pipeline\Refund\RefundPipelineState> */
    private array $states;

    public function __construct(
        ?AgrupacionState $agrupacion = null,
        ?ContabilidadState $contabilidad = null,
        ?TesoreriaState $tesoreria = null,
        ?AutPagoState $autPago = null,
        ?PagadoState $pagado = null,
    ) {
        $list = [
            $agrupacion ?? new AgrupacionState(),
            $contabilidad ?? new ContabilidadState(),
            $tesoreria ?? new TesoreriaState(),
            $autPago ?? new AutPagoState(),
            $pagado ?? new PagadoState(),
        ];

        foreach ($list as $state) {
            $this->states[$state->getName()] = $state;
        }
    }

    public function get(string $name): RefundPipelineState
    {
        if (!isset($this->states[$name])) {
            throw new InvalidArgumentException("Unknown refund pipeline state: {$name}");
        }

        return $this->states[$name];
    }

    /** @return array<string, \App\Service\Pipeline\Refund\RefundPipelineState> */
    public function all(): array
    {
        return $this->states;
    }
}
```

**Step 4: Validación**

Run: `composer cs-check src/Service/Pipeline/Refund/`
Expected: sin errores. Si hay, `composer cs-fix`.

Run: `php -l src/Service/Pipeline/Refund/RefundPipelineState.php`
Run: `php -l src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`
Expected: `No syntax errors detected` para ambos.

**Step 5: NO commit todavía** — el Registry referencia States que no existen. Commit conjunto al final de Task 4.

---

## Task 2: Crear los 5 Estados concretos

**Files:**
- Create: `src/Service/Pipeline/Refund/State/AgrupacionState.php`
- Create: `src/Service/Pipeline/Refund/State/ContabilidadState.php`
- Create: `src/Service/Pipeline/Refund/State/TesoreriaState.php`
- Create: `src/Service/Pipeline/Refund/State/AutPagoState.php`
- Create: `src/Service/Pipeline/Refund/State/PagadoState.php`

> **Convención:** los `validateAdvance` que NO aplican (terminal, gestionado por otro flujo) retornan un único error explicativo, no array vacío. El `getRegressionLockMessage` retorna null por defecto excepto en `TesoreriaState`. La lógica viene de `RefundService::_validateTransition()` (líneas 347-381) y `RefundService::getRegressionLockMessage()` (líneas 407-419), que se eliminarán en Task 3.

**Step 1: AgrupacionState**

Archivo `src/Service/Pipeline/Refund/State/AgrupacionState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AgrupacionState implements RefundPipelineState
{
    public function getName(): string
    {
        return RefundConstants::STATUS_AGRUPACION;
    }

    public function getNext(): ?string
    {
        return RefundConstants::STATUS_CONTABILIDAD;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(Refund $record): array
    {
        $errors = [];
        $type = $record->beneficiary_type;

        if ($type === RefundConstants::BENEFICIARY_TYPE_EMPLOYEE) {
            if (empty($record->beneficiary_employee_id)) {
                $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
            }
        } elseif ($type === RefundConstants::BENEFICIARY_TYPE_PROVIDER) {
            if (empty($record->beneficiary_provider_id)) {
                $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
            }
        } else {
            $errors[] = 'Debe seleccionar un beneficiario antes de avanzar.';
        }

        return $errors;
    }

    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
```

**Step 2: ContabilidadState**

Archivo `src/Service/Pipeline/Refund/State/ContabilidadState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class ContabilidadState implements RefundPipelineState
{
    public function getName(): string
    {
        return RefundConstants::STATUS_CONTABILIDAD;
    }

    public function getNext(): ?string
    {
        return RefundConstants::STATUS_TESORERIA;
    }

    public function getPrevious(): ?string
    {
        return RefundConstants::STATUS_AGRUPACION;
    }

    public function validateAdvance(Refund $record): array
    {
        $errors = [];

        if (empty($record->accrued)) {
            $errors[] = 'El registro debe estar marcado como Causado.';
        }
        if (empty($record->ready_for_payment)) {
            $errors[] = 'Debe seleccionar "Lista para Pago".';
        }

        return $errors;
    }

    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
```

**Step 3: TesoreriaState**

Archivo `src/Service/Pipeline/Refund/State/TesoreriaState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class TesoreriaState implements RefundPipelineState
{
    public function getName(): string
    {
        return RefundConstants::STATUS_TESORERIA;
    }

    public function getNext(): ?string
    {
        return RefundConstants::STATUS_AUT_PAGO;
    }

    public function getPrevious(): ?string
    {
        return RefundConstants::STATUS_CONTABILIDAD;
    }

    /**
     * El avance tesoreria→aut_pago lo gestiona RefundPaymentService::registerPayment
     * (no pasa por advanceStatus). Si alguien intenta avanzar desde el coordinator,
     * devolvemos un mensaje claro.
     */
    public function validateAdvance(Refund $record): array
    {
        return ['Debe registrar un pago para avanzar desde Tesorería.'];
    }

    /**
     * Bloqueo único de regresión en Refunds: tesoreria→contabilidad cuando ya
     * existe un pago bulk registrado en columnas. Anular o reasignar el pago
     * primero.
     */
    public function getRegressionLockMessage(Refund $record): ?string
    {
        if (!empty($record->payment_amount)) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado.'
                . ' Anule o reasigne el pago primero.';
        }

        return null;
    }
}
```

**Step 4: AutPagoState**

Archivo `src/Service/Pipeline/Refund/State/AutPagoState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class AutPagoState implements RefundPipelineState
{
    public function getName(): string
    {
        return RefundConstants::STATUS_AUT_PAGO;
    }

    public function getNext(): ?string
    {
        return RefundConstants::STATUS_PAGADO;
    }

    /**
     * BACKWARD_TRANSITIONS mapea aut_pago → tesoreria, pero la regresión
     * real está bloqueada de fábrica (canRegress() retorna false porque
     * el avance aut_pago→pagado tampoco pasa por el coordinator). Aquí
     * declaramos previous = tesoreria por consistencia con BACKWARD_TRANSITIONS.
     */
    public function getPrevious(): ?string
    {
        return RefundConstants::STATUS_TESORERIA;
    }

    public function validateAdvance(Refund $record): array
    {
        return ['La autorización de pago se gestiona desde la sección de pagos.'];
    }

    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
```

**Step 5: PagadoState**

Archivo `src/Service/Pipeline/Refund/State/PagadoState.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class PagadoState implements RefundPipelineState
{
    public function getName(): string
    {
        return RefundConstants::STATUS_PAGADO;
    }

    public function getNext(): ?string
    {
        return null;
    }

    public function getPrevious(): ?string
    {
        return null;
    }

    public function validateAdvance(Refund $record): array
    {
        return ['Este registro ya está en su estado final.'];
    }

    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
```

**Step 6: Validación**

Run: `composer cs-check src/Service/Pipeline/Refund/`
Expected: sin errores. Si hay, `composer cs-fix`.

Sanity check de instanciación e integridad de transiciones:

```bash
php -r "
require 'vendor/autoload.php';
require 'config/bootstrap.php';
\$r = new App\\Service\\Pipeline\\Refund\\RefundPipelineStateRegistry();
echo \$r->get('agrupacion')->getNext() . PHP_EOL;
echo \$r->get('aut_pago')->getPrevious() . PHP_EOL;
echo (\$r->get('pagado')->getNext() ?? 'null') . PHP_EOL;
echo (\$r->get('agrupacion')->getPrevious() ?? 'null') . PHP_EOL;
"
```

Expected output:
```
contabilidad
tesoreria
null
null
```

**Step 7: NO commit todavía** — el Registry y los States no se usan aún. Commit conjunto al final de Task 4.

---

## Task 3: Refactorizar `RefundService` para delegar al Registry

**Files:**
- Modify: `src/Service/RefundService.php`

**Step 1: Agregar import del Registry**

En `src/Service/RefundService.php`, dentro del bloque de `use ...;` agregar:

```php
use App\Service\Pipeline\Refund\RefundPipelineStateRegistry;
```

**Step 2: Inyectar el Registry en el constructor**

Reemplazar la firma actual del constructor (líneas 53-67) por:

```php
private GroupedInvoiceService $grouped;
private RefundHistoryService $refundHistory;
private RefundPipelineStateRegistry $stateRegistry;

/**
 * @param \App\Service\Interface\HistoryServiceInterface $historyService History service for child invoices.
 * @param \App\Service\PipelineAuthorizationService|null $pipelineAuth Pipeline authorization service.
 * @param \App\Service\RefundHistoryService|null $refundHistory Refund-specific audit trail.
 * @param \App\Service\Pipeline\Refund\RefundPipelineStateRegistry|null $stateRegistry Pipeline states.
 */
public function __construct(
    HistoryServiceInterface $historyService,
    ?PipelineAuthorizationService $pipelineAuth = null,
    ?RefundHistoryService $refundHistory = null,
    ?RefundPipelineStateRegistry $stateRegistry = null,
) {
    $this->grouped = new GroupedInvoiceService(
        documentType: InvoiceConstants::DOCTYPE_REINTEGRO,
        fkField: 'refund_id',
        recordTableName: 'Refunds',
        fkLabel: 'Reintegro',
        historyService: $historyService,
    );
    $this->pipelineAuth = $pipelineAuth ?? new PipelineAuthorizationService();
    $this->refundHistory = $refundHistory ?? new RefundHistoryService();
    $this->stateRegistry = $stateRegistry ?? new RefundPipelineStateRegistry();
}
```

**Step 3: Eliminar el método privado `_validateTransition()` (líneas 341-381)**

El método entero — incluyendo el docblock `@param ... Validate refund specific transition requirements` — se borra. Su lógica vive ahora repartida en los `validateAdvance()` de cada State.

**Step 4: Reemplazar la llamada a `_validateTransition()` en `advanceStatus()`**

Localizar la llamada actual en `advanceStatus()` (línea 151):

```php
$validationErrors = $this->_validateTransition($currentStatus, $record);
```

Reemplazar por:

```php
$validationErrors = $this->stateRegistry->get($currentStatus)->validateAdvance($record);
```

**Step 5: Reemplazar la llamada a `_validateTransition()` en `getTransitionErrors()`**

En `getTransitionErrors()` (línea 337) cambiar:

```php
return $this->_validateTransition($record->status, $record);
```

Por:

```php
return $this->stateRegistry->get($record->status)->validateAdvance($record);
```

**Step 6: Refactorizar `getRegressionLockMessage()` para delegar al State**

El método actual (líneas 407-419) tiene la regla de negocio embebida. Reemplazar el cuerpo por delegación:

```php
/**
 * Returns a human-readable lock message preventing regression, or null if allowed.
 */
public function getRegressionLockMessage(Refund $record): ?string
{
    return $this->stateRegistry->get($record->status)->getRegressionLockMessage($record);
}
```

**Step 7: Refactorizar `getPreviousStatus()` para usar el State (opcional pero coherente)**

Reemplazar el cuerpo (líneas 387-390):

```php
public function getPreviousStatus(string $currentStatus): ?string
{
    return $this->stateRegistry->get($currentStatus)->getPrevious();
}
```

**Nota:** funcionalmente equivalente al lookup en `RefundConstants::BACKWARD_TRANSITIONS` porque los States retornan los mismos nombres. Mantener `BACKWARD_TRANSITIONS` en Constants — otros archivos pueden seguir consultándolo sin cargar el Registry.

**Step 8: Validación**

Run: `composer cs-check src/Service/RefundService.php`
Expected: sin errores.

Run: `php -l src/Service/RefundService.php`
Expected: `No syntax errors detected`.

Sanity check del coordinator:

```bash
php -r "
require 'vendor/autoload.php';
require 'config/bootstrap.php';
\$service = new App\\Service\\RefundService(new App\\Service\\InvoiceHistoryService());
\$ref = new ReflectionClass(\$service);
echo (\$ref->hasMethod('_validateTransition') ? 'STILL HAS _validateTransition' : 'OK: _validateTransition removed') . PHP_EOL;
"
```

Expected:
```
OK: _validateTransition removed
```

**Step 9: NO commit todavía** — falta eliminar el Trait (Task 4). Commit conjunto al final.

---

## Task 4: Eliminar `RefundPipelineHelpersTrait`

**Files:**
- Modify: `src/Service/RefundService.php`
- Modify: `src/Service/RefundPaymentService.php`
- Delete: `src/Service/Trait/RefundPipelineHelpersTrait.php`

**Step 1: Inlinear helpers en `RefundService`**

En `src/Service/RefundService.php`:

1. Quitar `use App\Service\Trait\RefundPipelineHelpersTrait;` del bloque de imports.
2. Quitar la línea `use RefundPipelineHelpersTrait;` (línea 20, dentro de la clase).
3. Agregar la propiedad explícita (junto a las otras propiedades del coordinator):

```php
private PipelineAuthorizationService $pipelineAuth;
```

(Ya existe la asignación en el constructor, solo necesitamos declarar la propiedad explícita ya que el trait la declaraba.)

4. Agregar al final de la clase, como `private static`, el método ex-trait:

```php
private static function _buildSaveErrorMessage(string $base, array $entityErrors): string
{
    $details = [];
    foreach ($entityErrors as $field => $fieldErrors) {
        foreach ((array)$fieldErrors as $msg) {
            if (is_string($msg) && $msg !== '') {
                $details[] = sprintf('%s: %s', $field, $msg);
            }
        }
    }

    return empty($details) ? $base : ($base . ' ' . implode(', ', $details));
}
```

5. Reemplazar las llamadas a `$this->_canOperate(...)` (líneas 147 y 401 originales) por la llamada directa al servicio inyectado:

```php
// Antes:
if (!$this->_canOperate($roleId, $currentStatus)) {

// Después:
if (!$this->pipelineAuth->canOperate(
    $roleId,
    '',
    PipelineStepConstants::PIPELINE_REFUNDS,
    $currentStatus,
)) {
```

```php
// Antes:
return $this->_canOperate($roleId, $currentStatus);

// Después:
return $this->pipelineAuth->canOperate(
    $roleId,
    '',
    PipelineStepConstants::PIPELINE_REFUNDS,
    $currentStatus,
);
```

6. Reemplazar `self::_today()` (línea 207 original) por `date('Y-m-d')`:

```php
// Antes:
$today = self::_today();

// Después:
$today = date('Y-m-d');
```

7. Agregar import necesario:

```php
use App\Constants\PipelineStepConstants;
```

**Step 2: Inlinear helpers en `RefundPaymentService`**

En `src/Service/RefundPaymentService.php`, idéntico patrón:

1. Quitar `use App\Service\Trait\RefundPipelineHelpersTrait;` (línea 8).
2. Quitar `use RefundPipelineHelpersTrait;` (línea 21).
3. Declarar explícitamente la propiedad junto a las otras del service:

```php
private PipelineAuthorizationService $pipelineAuth;
```

4. Agregar el método `_buildSaveErrorMessage` como `private static` al final de la clase (mismo cuerpo que en `RefundService`).
5. Reemplazar las 3 llamadas a `$this->_canOperate(...)` (líneas 53, 181, 350) por la llamada directa con `$this->pipelineAuth->canOperate(...)`.
6. Reemplazar `self::_today()` (línea 261) por `date('Y-m-d')`.
7. Agregar import: `use App\Constants\PipelineStepConstants;`.

**Step 3: Eliminar el archivo del trait**

```bash
rm src/Service/Trait/RefundPipelineHelpersTrait.php
```

Verificar que el directorio NO se elimina:

```bash
ls src/Service/Trait/
```

Expected: `DocumentUploadTrait.php` y `HistoryNormalizationTrait.php` siguen ahí.

**Step 4: Validar que no quedan referencias al trait**

```bash
grep -rn "RefundPipelineHelpersTrait" src/ templates/
```

Expected: cero coincidencias.

**Step 5: Validación de sintaxis**

Run: `composer cs-check src/Service/RefundService.php src/Service/RefundPaymentService.php`
Expected: sin errores. Si hay, `composer cs-fix`.

Run: `php -l src/Service/RefundService.php`
Run: `php -l src/Service/RefundPaymentService.php`
Expected: `No syntax errors detected` para ambos.

**Step 6: Validación manual end-to-end (CRÍTICO antes del commit)**

Levantar servidor: `php bin/cake server`

1. Login como Registro/Revisión (`testrev` / pass adecuada del entorno) → ir a `/refunds` → crear refund nuevo → asignar beneficiario (employee o provider) → guardar → avanzar.
   Verificar: redirect a index, refund pasa a `contabilidad`. Hijas Invoices pasan a `STATUS_CONTABILIDAD`.

2. Crear otro refund en agrupación → intentar avanzar SIN beneficiario.
   Verificar: warning "Debe seleccionar un beneficiario antes de avanzar."

3. Login Contabilidad → editar refund en contabilidad con `accrued=false` → intentar avanzar.
   Verificar: warning "El registro debe estar marcado como Causado."

4. Marcar `accrued=true` con fecha + `ready_for_payment` → guardar y avanzar.
   Verificar: pasa a tesorería, hijas a `STATUS_TESORERIA`.

5. Login Tesorería → registrar pago en el refund → ya en `aut_pago`, intentar regresar al estado anterior.
   Verificar: regresa a `tesoreria`. Login Contabilidad o Tesorería → en tesoreria con `payment_amount` registrado → intentar regresar a contabilidad.
   Verificar: error "No se puede regresar a Contabilidad: existe un pago pendiente registrado…"

6. Anular el pago (rechazar/eliminar según UI) → regresar tesoreria → contabilidad ahora SÍ procede.
   Verificar: regresa, observación tipo regression registrada en `RefundObservations`, hijas vuelven a `STATUS_CONTABILIDAD`.

Si cualquier paso falla, **no commit** — investigar la causa.

**Step 7: Commit (Commit 1: State pattern + Trait eliminado)**

```bash
git add src/Service/Pipeline/Refund/
git add src/Service/RefundService.php src/Service/RefundPaymentService.php
git rm src/Service/Trait/RefundPipelineHelpersTrait.php
git commit -m "refactor(refunds): extract pipeline state pattern and remove helpers trait

Move per-state validation and regression lock rules from RefundService
to Pipeline/Refund/State/* (5 states). RefundService delegates via
RefundPipelineStateRegistry for advance, regression, and previous-state
lookups.

Drop Trait/RefundPipelineHelpersTrait — its 3 helpers (_canOperate,
_buildSaveErrorMessage, _today) were no-pipeline shared code. Replace
_canOperate with direct PipelineAuthorizationService calls, inline
_buildSaveErrorMessage as private static in each service, and replace
_today with date('Y-m-d').

Audit: docs/audits/flow-structure-audit-2026-05-06.md (Plan C)"
```

---

## Task 5: Renombrar `RefundSyntheticPayment` → `BulkPaymentView` (compartido)

**Files:**
- Delete: `src/Service/Dto/RefundSyntheticPayment.php`
- Create: `src/Service/Dto/BulkPaymentView.php`
- Modify: `src/Service/RefundService.php`
- Modify: `src/Controller/PettyCashRecordsController.php` (solo comentario TODO)

**Step 1: Crear `BulkPaymentView.php`**

Archivo `src/Service/Dto/BulkPaymentView.php`:

```php
<?php
declare(strict_types=1);

namespace App\Service\Dto;

use Cake\ORM\Entity;
use DateTimeInterface;

/**
 * Vista uniforme del pago bulk de un dominio que guarda **un único pago como
 * columnas en su tabla principal** (Refunds, PettyCashRecords). Materializa
 * esas columnas en la forma que espera el element compartido
 * `templates/element/payment_section.php`, garantizando tipado estático:
 * cualquier mismatch falla en IDE en lugar de runtime.
 *
 * Convención del proyecto — ver auditoría 2026-05-06 sección 9.
 *
 * @param int $id ID del registro propietario.
 * @param \Cake\ORM\Entity|null $banking_entity Entidad bancaria asociada.
 * @param float|int|null $amount Monto del pago.
 * @param \DateTimeInterface|null $payment_date Fecha del pago.
 * @param string $status Estado del pago (pendiente/autorizado).
 * @param bool $authorized True si el pago fue autorizado.
 * @param \Cake\ORM\Entity|null $authorized_by_user Usuario que autorizó.
 * @param \DateTimeInterface|null $authorized_date Fecha de autorización.
 * @param \Cake\ORM\Entity|null $created_by_user Usuario que registró.
 * @param string|null $rejection_reason Motivo de rechazo si aplica.
 */
final readonly class BulkPaymentView
{
    public function __construct(
        public int $id,
        public ?Entity $banking_entity,
        public float|int|null $amount,
        public ?DateTimeInterface $payment_date,
        public string $status,
        public bool $authorized,
        public ?Entity $authorized_by_user,
        public ?DateTimeInterface $authorized_date,
        public ?Entity $created_by_user,
        public ?string $rejection_reason,
    ) {
    }
}
```

**Step 2: Eliminar el archivo viejo**

```bash
git rm src/Service/Dto/RefundSyntheticPayment.php
```

**Step 3: Actualizar `RefundService.php`**

En `src/Service/RefundService.php`:

1. Cambiar el import:

```php
// Antes:
use App\Service\Dto\RefundSyntheticPayment;

// Después:
use App\Service\Dto\BulkPaymentView;
```

2. Cambiar el `@return` del docblock de `buildSyntheticPayments()` (línea ~275):

```php
/**
 * Construye una representación uniforme del pago bulk del registro para
 * que la vista pueda reusar el element compartido `payment_section`.
 *
 * Reintegro guarda un único pago como columnas en la tabla `refunds` (no
 * tiene tabla de pagos propia); este método materializa esas columnas en
 * la forma que espera el element.
 *
 * @return array<int, \App\Service\Dto\BulkPaymentView> 0 o 1 elementos.
 */
public function buildSyntheticPayments(Refund $record): array
```

3. Cambiar el `new RefundSyntheticPayment(...)` (línea ~286) por `new BulkPaymentView(...)`. La firma es idéntica — solo cambia el nombre de la clase.

**Step 4: Anotar TODO en `PettyCashRecordsController`**

En `src/Controller/PettyCashRecordsController.php`, encontrar el método `_buildSyntheticPayments` (línea 357) y añadir comentario JUSTO ENCIMA del docblock existente:

```php
// TODO: migrar a App\Service\Dto\BulkPaymentView (Service/Dto/) en próxima sesión.
//       El cast (object)[...] perdió tipado estático cuando Refunds adoptó BulkPaymentView.
//       Ver auditoría 2026-05-06 sección 9.
/**
 * Adapt the bulk-payment columns of a Caja Menor record into the shape
 * expected by the shared `payment_section` element (which iterates over
 * a list of payment-like objects). Returns an empty array when no payment
 * has been registered yet.
 *
 * @return array<int, object>
 */
private function _buildSyntheticPayments(PettyCashRecord $record): array
```

**Step 5: Validar que no quedan referencias**

```bash
grep -rn "RefundSyntheticPayment" src/ templates/
```

Expected: cero coincidencias.

```bash
grep -rn "BulkPaymentView" src/
```

Expected: 1 archivo (`src/Service/Dto/BulkPaymentView.php`) y 2-3 menciones en `RefundService.php` (import, docblock, instanciación).

**Step 6: Validación de sintaxis**

Run: `composer cs-check src/Service/Dto/BulkPaymentView.php src/Service/RefundService.php src/Controller/PettyCashRecordsController.php`
Expected: sin errores.

Run: `php -l src/Service/Dto/BulkPaymentView.php`
Expected: `No syntax errors detected`.

**Step 7: Validación manual**

Levantar servidor: `php bin/cake server`

1. Login Tesorería → ir a refund con pago registrado → editar → ver sección "Pagos".
   Verificar: la sección renderiza con banking entity, monto, fecha, status. Cero errores PHP en pantalla.

2. Login Tesorería → ir a refund SIN pago aún registrado → editar.
   Verificar: la sección de pagos se muestra vacía (o con CTA "Registrar pago"), sin errores.

3. Verificar PettyCash NO se rompió: ir a `/petty-cash-records`, abrir uno con pago, comprobar que la sección de pago sigue rendereando idéntica.

**Step 8: Commit (Commit 2: BulkPaymentView)**

```bash
git add src/Service/Dto/BulkPaymentView.php
git rm src/Service/Dto/RefundSyntheticPayment.php
git add src/Service/RefundService.php
git add src/Controller/PettyCashRecordsController.php
git commit -m "refactor(refunds): rename RefundSyntheticPayment to BulkPaymentView

Promote the typed view-model adapter to a shared Dto so other domains
storing one bulk payment as columns (PettyCashRecords) can adopt it.
Refunds now uses BulkPaymentView; PettyCash stays with its (object)[...]
legacy cast and a TODO pointing to the shared Dto.

Audit: docs/audits/flow-structure-audit-2026-05-06.md (section 9 hallazgo)"
```

---

## Task 6: Crear `RefundAddViewModel` y refactor de `add()`

**Files:**
- Create: `src/ViewModel/RefundAddViewModel.php`
- Modify: `src/Controller/RefundsController.php` (líneas 228-271)
- Modify: `templates/Refunds/add.php`

**Step 1: Crear `RefundAddViewModel.php`**

Archivo `src/ViewModel/RefundAddViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Refund;

/**
 * Datos pre-calculados que el template `templates/Refunds/add.php` necesita.
 * Construido por `RefundsController::add()` y pasado como `$viewModel`.
 *
 * @param \App\Model\Entity\Refund $record Entidad nueva (vacía).
 * @param array<int, string> $employees Lista [id => "Nombre Apellido1 Apellido2"] ordenada por nombre.
 * @param array<int, string> $providers Lista [id => name] ordenada por nombre.
 * @param iterable $operationCenters Centros de operación (find('codeList')).
 */
final readonly class RefundAddViewModel
{
    public function __construct(
        public Refund $record,
        public array $employees,
        public array $providers,
        public iterable $operationCenters,
    ) {
    }
}
```

**Step 2: Refactorizar `RefundsController::add()`**

Reemplazar el final del método `add()` en `src/Controller/RefundsController.php` (líneas 268-270):

```php
// Antes:
[$employees, $providers] = $this->_loadBeneficiaryLists();
$operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
$this->set(compact('record', 'employees', 'providers', 'operationCenters'));
```

Por:

```php
[$employees, $providers] = $this->_loadBeneficiaryLists();
$this->set('viewModel', new RefundAddViewModel(
    record: $record,
    employees: $employees,
    providers: $providers,
    operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
));
```

Agregar al tope del controller el import:

```php
use App\ViewModel\RefundAddViewModel;
```

**Step 3: Actualizar `templates/Refunds/add.php`**

Sustituir las referencias a `$record`, `$employees`, `$providers`, `$operationCenters` por `$viewModel->record`, `$viewModel->employees`, `$viewModel->providers`, `$viewModel->operationCenters`.

Búsqueda y reemplazo (orden importa — más largos primero para evitar dobles):

| Antes | Después |
|---|---|
| `$operationCenters` | `$viewModel->operationCenters` |
| `$employees` | `$viewModel->employees` |
| `$providers` | `$viewModel->providers` |
| `$record` | `$viewModel->record` |

**Cuidado:** revisar manualmente el template tras el reemplazo automatizado — alguna referencia puede ser parte de un nombre de campo o atributo HTML (`data-providers="..."` no debe cambiar).

**Step 4: Validación de sintaxis**

Run: `composer cs-check src/ViewModel/RefundAddViewModel.php src/Controller/RefundsController.php`
Expected: sin errores.

Run: `php -l src/ViewModel/RefundAddViewModel.php`
Run: `php -l templates/Refunds/add.php`
Expected: `No syntax errors detected` para ambos.

**Step 5: Validación manual**

Levantar servidor: `php bin/cake server`

1. Login Registro/Revisión → ir a `/refunds/add`.
   Verificar: el formulario carga sin errores PHP. Selects de operation center, employee, provider populan correctamente.

2. Llenar el formulario con beneficiary_type=employee + un empleado válido + un operation center → submit.
   Verificar: redirect a `/refunds/edit/{id}`. El refund quedó creado con `status=agrupacion`.

3. Repetir con beneficiary_type=provider.
   Verificar: misma flujo OK con provider.

**Step 6: NO commit todavía** — Task 7 hace `edit`, commit conjunto al final.

---

## Task 7: Crear `RefundEditViewModel` y refactor de `edit()`

**Files:**
- Create: `src/ViewModel/RefundEditViewModel.php`
- Modify: `src/Controller/RefundsController.php` (líneas 412-468 + agregar `_buildEditViewModel`)
- Modify: `templates/Refunds/edit.php`

**Step 1: Crear `RefundEditViewModel.php`**

Archivo `src/ViewModel/RefundEditViewModel.php`:

```php
<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Refund;
use App\Service\Dto\BulkPaymentView;

/**
 * Datos pre-calculados que el template `templates/Refunds/edit.php` necesita.
 * Construido por `RefundsController::_buildEditViewModel()` y pasado como `$viewModel`.
 *
 * @param \App\Model\Entity\Refund $record Refund cargado con todos sus contains.
 * @param string $currentStatus Estado actual del refund.
 * @param array<int, string> $employees Lista [id => nombre completo].
 * @param array<int, string> $providers Lista [id => name].
 * @param iterable $operationCenters Centros de operación (find('codeList')).
 * @param array<int, string> $bankingEntities Lista [id => name].
 * @param iterable $availableInvoices Facturas elegibles para agrupar.
 * @param array $groupFilters Filtros aplicados al listado de facturas disponibles.
 * @param string|null $nextStatus Próximo estado del pipeline si aplica.
 * @param array<string> $advanceErrors Errores que impiden avanzar (calculados con el State).
 * @param bool $canRegress True si el rol puede regresar el registro.
 * @param string|null $previousStatus Estado anterior si la regresión está disponible.
 * @param string|null $regressLockMessage Mensaje de bloqueo de regresión, null si está permitida.
 * @param bool $canRegisterPayment True si el rol puede registrar pagos en este registro.
 * @param bool $canAuthorizePayment True si el rol puede autorizar pagos.
 * @param array<int, \App\Service\Dto\BulkPaymentView> $syntheticPayments Vista del pago bulk (0 o 1 items).
 * @param string $roleName Nombre del rol del usuario actual.
 * @param array<string, string> $pipelineLabels Mapa estado → label.
 */
final readonly class RefundEditViewModel
{
    public function __construct(
        public Refund $record,
        public string $currentStatus,
        public array $employees,
        public array $providers,
        public iterable $operationCenters,
        public array $bankingEntities,
        public iterable $availableInvoices,
        public array $groupFilters,
        public ?string $nextStatus,
        public array $advanceErrors,
        public bool $canRegress,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public array $syntheticPayments,
        public string $roleName,
        public array $pipelineLabels,
    ) {
    }
}
```

**Step 2: Agregar método privado `_buildEditViewModel()` al controller**

En `src/Controller/RefundsController.php`, agregar después de `_loadBeneficiaryLists()` (después de la línea 297):

```php
private function _buildEditViewModel(Refund $record, object $user): RefundEditViewModel
{
    $nextStatus = RefundConstants::TRANSITIONS[$record->status] ?? null;
    $advanceErrors = $nextStatus
        ? $this->refundService->getTransitionErrors($record)
        : [];

    $groupFilters = $this->request->getQueryParams();
    [$employees, $providers] = $this->_loadBeneficiaryLists();
    $roleName = $this->_getUserRoleName($user);
    $roleId = (int)$user->role_id;

    return new RefundEditViewModel(
        record: $record,
        currentStatus: $record->status,
        employees: $employees,
        providers: $providers,
        operationCenters: $this->fetchTable('OperationCenters')->find('codeList')->all(),
        bankingEntities: $this->fetchTable('BankingEntities')->find('list')->toArray(),
        availableInvoices: $this->refundService->getAvailableInvoices($groupFilters)->all(),
        groupFilters: $groupFilters,
        nextStatus: $nextStatus,
        advanceErrors: $advanceErrors,
        canRegress: $this->refundService->canRegress($roleId, $record->status),
        previousStatus: $this->refundService->getPreviousStatus($record->status),
        regressLockMessage: $this->refundService->getRegressionLockMessage($record),
        canRegisterPayment: $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_TESORERIA,
        ),
        canAuthorizePayment: $this->pipelineAuth->canOperate(
            $roleId,
            $roleName,
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_AUT_PAGO,
        ),
        syntheticPayments: $this->refundService->buildSyntheticPayments($record),
        roleName: $roleName,
        pipelineLabels: RefundConstants::STATUS_LABELS,
    );
}
```

Agregar import al tope del controller:

```php
use App\ViewModel\RefundEditViewModel;
```

**Step 3: Reemplazar el bloque GET de `edit()` por la llamada al builder**

En `src/Controller/RefundsController.php`, sustituir las líneas 412-468 (todo el bloque desde `// Compute advance errors for the view` hasta el `$this->set(compact(...))`) por:

```php
$user = $this->_getCurrentUser();
$this->set('viewModel', $this->_buildEditViewModel($record, $user));
```

**Importante:** la rama POST (líneas 317-410) NO se toca — solo la rama GET.

**Step 4: Actualizar `templates/Refunds/edit.php`**

Sustituir las referencias a las 18 variables sueltas por `$viewModel->...`:

| Antes | Después |
|---|---|
| `$record` | `$viewModel->record` |
| `$availableInvoices` | `$viewModel->availableInvoices` |
| `$operationCenters` | `$viewModel->operationCenters` |
| `$employees` | `$viewModel->employees` |
| `$providers` | `$viewModel->providers` |
| `$groupFilters` | `$viewModel->groupFilters` |
| `$nextStatus` | `$viewModel->nextStatus` |
| `$advanceErrors` | `$viewModel->advanceErrors` |
| `$roleName` | `$viewModel->roleName` |
| `$bankingEntities` | `$viewModel->bankingEntities` |
| `$canRegisterPayment` | `$viewModel->canRegisterPayment` |
| `$canAuthorizePayment` | `$viewModel->canAuthorizePayment` |
| `$syntheticPayments` | `$viewModel->syntheticPayments` |
| `$canRegress` | `$viewModel->canRegress` |
| `$previousStatus` | `$viewModel->previousStatus` |
| `$regressLockMessage` | `$viewModel->regressLockMessage` |
| `$pipelineLabels` | `$viewModel->pipelineLabels` |
| `$currentStatus` | `$viewModel->currentStatus` |

Aplicar reemplazos del más largo al más corto para no dañar nombres parciales (`$availableInvoices` antes que `$invoices`, `$canRegisterPayment` antes que `$canRegister`, etc.).

**Cuidado:** los elements (`$this->element(...)`) que reciben parámetros explícitos seguramente reciben `$record` por valor — esos pasan a recibir `$viewModel->record`.

Tras el reemplazo, leer el template completo para confirmar:
- No queda ninguna variable suelta (`$record`, `$employees`, etc.) sin prefijo.
- Los `data-*` atributos HTML (que son strings) no se rompieron.

**Step 5: Validación de sintaxis**

Run: `composer cs-check src/ViewModel/RefundEditViewModel.php src/Controller/RefundsController.php`
Expected: sin errores.

Run: `php -l src/ViewModel/RefundEditViewModel.php`
Run: `php -l templates/Refunds/edit.php`
Expected: `No syntax errors detected` para ambos.

**Step 6: Validación manual end-to-end (CRÍTICO)**

Levantar servidor: `php bin/cake server`

1. **Add flow** (cobertura Task 6 + 7): login Registro/Revisión → `/refunds/add` → crear refund → redirect a edit.
   Verificar: edit carga sin errores PHP. Todos los selects pueblan. La sección de agrupación muestra facturas disponibles. Los filtros del query funcionan (filtrar por operation center, etc.).

2. **Avance con datos faltantes:** en agrupación sin beneficiario → ver mensaje de error preview.
   Verificar: el botón de avance aparece pero al hacer click muestra "Debe seleccionar un beneficiario antes de avanzar." (ahora viene del State).

3. **Avance OK:** asignar beneficiario, agrupar al menos una factura → avanzar a contabilidad → editar como Contabilidad → marcar accrued + ready_for_payment → avanzar a tesorería.
   Verificar: cada estado muestra el subset correcto de campos editables; el progreso del pipeline se ve OK; las hijas avanzan en cascada.

4. **Pago bulk:** login Tesorería → registrar pago → ver sección de pago renderizada con `BulkPaymentView`.
   Verificar: muestra banking entity, monto, fecha, status pendiente.

5. **Regresión con lock:** desde tesoreria con pago registrado → intentar regresar a contabilidad.
   Verificar: bloqueado con el mensaje del `TesoreriaState::getRegressionLockMessage`.

6. **Regresión sin lock:** anular el pago → regresar tesoreria → contabilidad.
   Verificar: regresa, observación tipo regression queda guardada.

7. **Autorización + final:** login Contador (rol que autoriza pagos en aut_pago) → autorizar pago → estado pasa a `pagado`.
   Verificar: render del estado pagado, no hay botón de regresión, links correctos.

Si cualquier paso falla, **no commit** — investigar.

**Step 7: Commit (Commit 3: ViewModels)**

```bash
git add src/ViewModel/RefundAddViewModel.php
git add src/ViewModel/RefundEditViewModel.php
git add src/Controller/RefundsController.php
git add templates/Refunds/add.php templates/Refunds/edit.php
git commit -m "refactor(refunds): introduce RefundAddViewModel and RefundEditViewModel

Move the per-action data prep out of the controller's add() and edit()
GET branch into typed final readonly view-model classes. The 18 vars
currently set via compact() in edit() are now properties on
RefundEditViewModel; add() drops to a single set('viewModel', ...) call.

Patrón canónico (auditoría 2026-05-06 sección 5).
Audit: docs/audits/flow-structure-audit-2026-05-06.md (Plan C)"
```

---

## Task 8: Actualizar la auditoría

**Files:**
- Modify: `docs/audits/flow-structure-audit-2026-05-06.md`

**Step 1: Marcar Refunds como Completado en sección 6**

Cambiar la fila de Refunds (línea 99 aprox.) de:

```markdown
| 🟠 Media | **Refunds** | Reemplazar `Trait/RefundPipelineHelpersTrait` por `Pipeline/Refund/State/*`. Crear Add/Edit ViewModels. Evaluar si `Dto/RefundSyntheticPayment` y `Subscriber/RefundOutcomeSubscriber` se generalizan o se quedan como caso particular justificado. | Backlog (no entra en opción 2) |
```

A:

```markdown
| 🟠 Media | **Refunds** | Reemplazar `Trait/RefundPipelineHelpersTrait` por `Pipeline/Refund/State/*`. Crear Add/Edit ViewModels. Evaluar si `Dto/RefundSyntheticPayment` y `Subscriber/RefundOutcomeSubscriber` se generalizan o se quedan como caso particular justificado. | **Completado — Plan C** |
```

**Step 2: Agregar fila a sección 8 (Estado de los planes)**

Agregar bajo la fila de Plan B:

```markdown
| Plan C | Refunds | 🟢 Completado | 2026-05-06 |
```

**Step 3: Agregar entradas a sección 9 (Cambios a esta auditoría)**

Bajo la entrada existente (`- **2026-05-06** — Creación inicial. Adopción de opción 2.`) agregar:

```markdown
- **2026-05-06** — Activación Plan C (Refunds). Se promueve desde Backlog. Justificación: continuación natural tras Plan A/B completados; los hallazgos de Refunds (DTO mal generalizado en PettyCash) salieron a la luz solo al rediseñarlo.
- **2026-05-06** — Desviación Plan C: el `Trait/RefundPipelineHelpersTrait` se elimina por completo (audit pidió "reemplazar por State/*"; en realidad el trait también tenía RBAC + helpers no-pipeline que se inlinearon en cada service).
- **2026-05-06** — Hallazgo Plan C: `Dto/RefundSyntheticPayment` se renombra a `BulkPaymentView` y se promueve a convención compartida del proyecto. **PettyCash queda con `_buildSyntheticPayments` legacy `(object)[...]`** pendiente de adoptar el Dto. No es parte de este plan; se anota como deuda para su próxima sesión.
- **2026-05-06** — Plan C también deja constancia de que `Subscriber/RefundOutcomeSubscriber` se conserva como patrón sano del proyecto (3 Subscribers ya: `LegalizationInitializer`, `LinkedInvoicesPromoter`, `RefundOutcome` — convención, no excepción).
```

**Step 4: Validación**

Leer el archivo del audit y revisar:
- La tabla de sección 6 mantiene el formato.
- La tabla de sección 8 mantiene el formato.
- Las nuevas entradas de sección 9 están en orden cronológico/lógico.

**Step 5: Commit (Commit 4: Audit update)**

```bash
git add docs/audits/flow-structure-audit-2026-05-06.md
git commit -m "docs(audits): mark Plan C (Refunds) as completed

Update sections 6, 8, and 9: Refunds promoted from Backlog and finished.
Document trait removal as a deviation from the literal audit ask, and
the BulkPaymentView promotion as a finding (PettyCash backlog)."
```

---

## Resumen de commits

1. `refactor(refunds): extract pipeline state pattern and remove helpers trait` (Tasks 1-4)
2. `refactor(refunds): rename RefundSyntheticPayment to BulkPaymentView` (Task 5)
3. `refactor(refunds): introduce RefundAddViewModel and RefundEditViewModel` (Tasks 6-7)
4. `docs(audits): mark Plan C (Refunds) as completed` (Task 8)

---

## Cierre

Tras los 4 commits, ejecutar como sanity final:

```bash
composer cs-check
grep -rn "RefundPipelineHelpersTrait\|RefundSyntheticPayment" src/ templates/
```

Expected:
- `cs-check` sin errores.
- `grep` cero coincidencias (los nombres viejos están totalmente erradicados).

Validación manual final del flujo completo end-to-end (un refund desde creación hasta `pagado`) tras los 4 commits — replica los pasos 1-7 de Task 7, Step 6.

Si todo OK: branch listo para merge a `main`.
