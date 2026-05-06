# Diseño — Migración de Refunds a la estructura canónica

**Fecha:** 2026-05-06
**Plan:** C (Refunds) — promovido desde Backlog del audit `docs/audits/flow-structure-audit-2026-05-06.md`.
**Auditoría origen:** sección 6, fila 🟠 Media (Refunds).
**Sesiones precedentes:** Plan A (PaymentSchedulings) ✅ y Plan B (Novelties) ✅ completados.

---

## 1. Contexto

Refunds (reintegros = sobrantes de anticipo) ya tiene mucho de la estructura canónica:

- ✅ `RefundService`, `RefundDocumentService`, `RefundHistoryService`, `RefundPaymentService`
- ✅ `RefundConstants`
- ❌ Sin ViewModels (controller obeso: 797 líneas)
- ❌ Sin `Pipeline/Refund/State/*` — usa `Trait/RefundPipelineHelpersTrait`
- ⚠️ Tiene `Dto/RefundSyntheticPayment` y `Subscriber/RefundOutcomeSubscriber`, marcados por el audit para evaluación.

Decisiones tomadas en la sesión de brainstorming:

| Decisión | Opción elegida |
|---|---|
| Alcance | A — plan completo (State + Trait + Dto + ViewModels) |
| Interface del State | B — incluye `getRegressionLockMessage` (Refunds tiene lock condicional) |
| Trait | A — eliminar; helpers inlineados |
| Dto | C — renombrar a `BulkPaymentView` y promover a convención compartida; PettyCash en backlog |
| Subscriber | Se queda — patrón sano (`LegalizationInitializer`, `LinkedInvoicesPromoter`, `RefundOutcome`) |
| ViewModels | A — solo Add y Edit |
| Commits | C — 3 commits: (State+Trait) → (Dto rename) → (ViewModels) |

---

## 2. State pattern

### Estructura nueva

```
src/Service/Pipeline/Refund/
├── RefundPipelineState.php           ← interface
├── RefundPipelineStateRegistry.php   ← lookup por nombre
└── State/
    ├── AgrupacionState.php
    ├── ContabilidadState.php
    ├── TesoreriaState.php
    ├── AutPagoState.php
    └── PagadoState.php
```

### Interface (divergente de Novelty)

Refunds **no** tiene modo dual individual/grupo (la interface de Novelty), pero **sí** tiene regresión condicional con lock por estado.

```php
namespace App\Service\Pipeline\Refund;

use App\Model\Entity\Refund;

interface RefundPipelineState
{
    public function getName(): string;
    public function getNext(): ?string;
    public function getPrevious(): ?string;

    /** @return array<string> Errores que impiden avanzar. */
    public function validateAdvance(Refund $record): array;

    /** Mensaje de bloqueo si la regresión NO procede; null si sí. */
    public function getRegressionLockMessage(Refund $record): ?string;
}
```

### Reparto por estado

| State | `validateAdvance` (extraído de `_validateTransition`) | `getRegressionLockMessage` |
|---|---|---|
| `AgrupacionState` | Beneficiario obligatorio (employee/provider según `beneficiary_type`) | null |
| `ContabilidadState` | `accrued` + `ready_for_payment` requeridos | null |
| `TesoreriaState` | (vacío — el avance lo gestiona `RefundPaymentService::registerPayment`) | "No se puede regresar a Contabilidad: existe un pago pendiente registrado…" si `payment_amount` no vacío |
| `AutPagoState` | "La autorización de pago se gestiona desde la sección de pagos." | null (regresión bloqueada por `BACKWARD_TRANSITIONS`) |
| `PagadoState` | "Este registro ya está en su estado final." | null |

### Coordinator (`RefundService`)

Lo que **se queda** en el service tras la migración:

- RBAC (`canOperate` directo a `PipelineAuthorizationService`).
- Transacciones (`Connection::transactional`).
- Locks pesimistas (`epilog('FOR UPDATE')`) y revalidación TOCTOU.
- Snapshots de campos bajo lock antes de propagar a hijas.
- Propagación a `Invoices` hijas (`updateAll` + `recordBulkHistory`).
- Escritura de history y observaciones.
- `getTransitionErrors()`, `canRegress()`, `getPreviousStatus()`, `getRegressionLockMessage()` ahora **delegan al State** vía Registry.

### `RefundConstants`

`TRANSITIONS` y `BACKWARD_TRANSITIONS` se mantienen como mapas estáticos para lookup rápido. Los States son la fuente de verdad de las transiciones; los mapas son una proyección plana usada por código existente que no necesita un State completo.

---

## 3. Eliminación de `RefundPipelineHelpersTrait`

**Estado actual:** `src/Service/Trait/RefundPipelineHelpersTrait.php`, 64 líneas, 3 helpers usados por `RefundService` y `RefundPaymentService`.

### Plan de remoción

| Helper | Reemplazo |
|---|---|
| `_canOperate(roleId, step)` | Llamada directa: `$this->pipelineAuth->canOperate($roleId, '', PipelineStepConstants::PIPELINE_REFUNDS, $step)`. Ambos services ya tienen el `PipelineAuthorizationService` inyectado. |
| `_buildSaveErrorMessage(base, errors)` | Mover como método `private static` a cada service (~12 líneas duplicadas). YAGNI — no creamos clase utility. |
| `_today()` | Inline `date('Y-m-d')`. |

### Acciones concretas

1. Borrar `src/Service/Trait/RefundPipelineHelpersTrait.php`.
2. Quitar `use RefundPipelineHelpersTrait` de `RefundService` y `RefundPaymentService`.
3. Quitar el `use App\Service\Trait\RefundPipelineHelpersTrait;` de los dos services.
4. Verificar que el directorio `src/Service/Trait/` queda con `DocumentUploadTrait` y `HistoryNormalizationTrait` — no se elimina el directorio.

**Cero cambios de comportamiento.** Solo redistribución del código.

---

## 4. `BulkPaymentView` — DTO compartido

### Renombrado

`src/Service/Dto/RefundSyntheticPayment.php` → `src/Service/Dto/BulkPaymentView.php`.

**Forma idéntica** (la firma del actual ya es correcta):

```php
namespace App\Service\Dto;

use Cake\ORM\Entity;
use DateTimeInterface;

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
    ) {}
}
```

### Docblock

> Vista uniforme del pago bulk de un dominio que guarda **un único pago como columnas en su tabla principal** (Refunds, PettyCashRecords). Materializa esas columnas en la forma que espera el element compartido `templates/element/payment_section.php`. Garantiza tipado estático: cualquier mismatch falla en IDE en lugar de runtime.

### Cambios de consumidores

1. `RefundService::buildSyntheticPayments()` retorna `array<int, BulkPaymentView>`. `new RefundSyntheticPayment(...)` → `new BulkPaymentView(...)`. `use` actualizado.
2. `RefundsController` (línea 441): el tipo del array que viaja a la vista cambia. La vista no requiere cambios — accede por las mismas propiedades públicas.
3. `templates/Refunds/edit.php` (línea 410): cero cambios.
4. **PettyCash queda intacto** con su `_buildSyntheticPayments()` privado retornando `(object)[...]`. Se le agrega comentario en el método: `// TODO: migrar a BulkPaymentView (Service/Dto/) en próxima sesión.`

---

## 5. ViewModels

### `RefundAddViewModel`

```php
namespace App\ViewModel;

use App\Model\Entity\Refund;

final readonly class RefundAddViewModel
{
    public function __construct(
        public Refund $record,
        public array $employees,         // [id => "First Last1 Last2"]
        public array $providers,         // [id => name]
        public array $operationCenters,  // findCodeList()
    ) {}
}
```

### `RefundEditViewModel`

Absorbe las 18 variables actuales que `edit()` setea, agrupadas por intención:

```php
namespace App\ViewModel;

use App\Model\Entity\Refund;
use App\Service\Dto\BulkPaymentView;

final readonly class RefundEditViewModel
{
    public function __construct(
        public Refund $record,
        public string $currentStatus,
        // Listas de selects
        public array $employees,
        public array $providers,
        public array $operationCenters,
        public array $bankingEntities,
        // Agrupación
        public iterable $availableInvoices,
        public array $groupFilters,
        // Avance
        public ?string $nextStatus,
        public array $advanceErrors,
        // Regresión
        public bool $canRegress,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        // Pagos
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        /** @var array<int, BulkPaymentView> */
        public array $syntheticPayments,
        // Contexto
        public string $roleName,
        public array $pipelineLabels,
    ) {}
}
```

### Cambios en `RefundsController`

- **`add()`:** prep inline de 4 listas → `$this->set('viewModel', new RefundAddViewModel(...))`. Pasa de ~14 líneas a ~6.
- **`edit()` rama GET (líneas 412-468, 57 líneas):** se sustituye por `_buildEditViewModel(Refund $record, User $user): RefundEditViewModel` privado, mismo patrón que `EmployeeNoveltiesController::_buildEditViewModel`. El método privado vive en el controller (no es scope creep — usa `$this->fetchTable()` y `$this->refundService`).
- **`_loadBeneficiaryLists()`** queda como helper privado del controller — ambas VMs lo consumen.
- **Templates `add.php` y `edit.php`:** cambian de `<?= $employees ?>` a `<?= $viewModel->employees ?>`. Reescritura mecánica, sin cambios de markup.

**Reducción estimada del controller:** 797 → ~700 líneas. `edit()` baja de ~170 a ~60 líneas.

---

## 6. Plan de commits

Tres commits, cada uno deja la app funcional.

| # | Alcance | Archivos clave |
|---|---|---|
| 1 | State pattern + eliminación del Trait | `src/Service/Pipeline/Refund/*` (nuevo), `src/Service/RefundService.php`, `src/Service/RefundPaymentService.php`, eliminar `src/Service/Trait/RefundPipelineHelpersTrait.php` |
| 2 | Rename `RefundSyntheticPayment` → `BulkPaymentView` | Eliminar `src/Service/Dto/RefundSyntheticPayment.php`, crear `src/Service/Dto/BulkPaymentView.php`, actualizar `RefundService`, comentar TODO en `PettyCashRecordsController::_buildSyntheticPayments` |
| 3 | ViewModels (Add y Edit) | `src/ViewModel/RefundAddViewModel.php` (nuevo), `src/ViewModel/RefundEditViewModel.php` (nuevo), `src/Controller/RefundsController.php` (refactor `add` y `edit`), `templates/Refunds/add.php`, `templates/Refunds/edit.php` |

---

## 7. Validación manual

Sin tests automatizados (CLAUDE.md, sección Testing Policy). Los pasos se ejecutan tras cada commit, con la app corriendo en `php bin/cake server`.

### Tras commit 1 (State + Trait)

1. Login Registro/Revisión → crear refund nuevo → asignar beneficiario → avanzar.
   - Verificar: avanza a `contabilidad`. Hijas pasan a `STATUS_CONTABILIDAD`.
2. Intentar avanzar SIN beneficiario.
   - Verificar: error "Debe seleccionar un beneficiario antes de avanzar." (mensaje de `AgrupacionState::validateAdvance`).
3. Login Contabilidad → con `accrued=false` → avanzar.
   - Verificar: error "El registro debe estar marcado como Causado." (mensaje de `ContabilidadState`).
4. Marcar accrued + ready_for_payment → avanzar a tesorería.
5. Login Tesorería → registrar pago → en `aut_pago` regresar a tesorería.
   - Verificar: NO permite regresar a contabilidad mientras `payment_amount` no esté vacío. Mensaje de `TesoreriaState::getRegressionLockMessage`.
6. Anular el pago → regresar de tesorería a contabilidad.
   - Verificar: regresa, observación tipo regression registrada en `RefundObservations`, hijas vuelven a `STATUS_CONTABILIDAD`.
7. `grep -rn "RefundPipelineHelpersTrait"` → cero coincidencias.

### Tras commit 2 (BulkPaymentView)

1. Edit refund con pago registrado → ver sección de pago.
   - Verificar: renderiza idéntico (banking entity, monto, fecha, status), sin errores PHP.
2. Edit refund SIN pago → sección vacía.
3. `grep -rn "RefundSyntheticPayment"` → cero coincidencias.

### Tras commit 3 (ViewModels)

1. `add()` → cargar formulario, crear refund, comprobar redirect a `edit`.
2. `edit()` → cargar formulario, todos los selects pueblan, filtros de agrupación funcionan, errores de avance se muestran cuando faltan datos, botón de regresión visible cuando aplica.
3. Flujo end-to-end: crear → avanzar → pagar → autorizar → pagado.

---

## 8. Actualizaciones al audit

**Sección 6 (plan de migración):** Refunds pasa de "Backlog" a "Completado".

**Sección 8 (estado de los planes):** agregar fila

| Plan | Flujo | Estado | Fecha cierre |
|---|---|---|---|
| Plan C | Refunds | 🟢 Completado | 2026-05-06 |

**Sección 9 (cambios al audit):** agregar tres entradas el 2026-05-06.

- Activación Plan C (Refunds): se promueve desde Backlog. Justificación: continuación natural tras Plan A/B; los hallazgos de Refunds (DTO mal generalizado en PettyCash) salieron a la luz solo al rediseñarlo.
- Desviación Plan C: el `Trait/RefundPipelineHelpersTrait` se elimina por completo (audit pidió "reemplazar por State/*"; en realidad el trait también tenía RBAC + helpers no-pipeline que se inlinearon en cada service).
- Hallazgo Plan C: `Dto/RefundSyntheticPayment` se renombra a `BulkPaymentView` y se promueve a convención compartida del proyecto. **PettyCash queda con `_buildSyntheticPayments` legacy `(object)[...]`** pendiente de adoptar el Dto. No es parte de este plan; se anota como deuda para su próxima sesión.
