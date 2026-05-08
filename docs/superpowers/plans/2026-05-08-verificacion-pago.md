# Estado intermedio "Verificación de pago" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Insertar un estado `verificacion_pago` (label "Verificación de pago") entre `autorizacion_pago` y `pagada` en los 5 módulos que terminan en Pagada (Invoices, PaymentScheduling, PettyCash, Refund, Novelty/LiquidationDoc), de modo que la autorización del Contador deje el registro pendiente de confirmación por Tesorería antes de cerrar el flujo.

**Architecture:** Cada módulo declara el nuevo estado en su enum `PipelineStatus` y en sus Constants/Trait. Se añade una nueva clase State `VerificacionPagoState` por módulo y se ajustan los Registries y los States adyacentes (`AutorizacionPagoState`, `PagadaState`). Cada `*PaymentService` se desdobla: el método de autorización (Contador) deja el registro en `verificacion_pago` y un nuevo método `confirmPaymentExecuted`/`confirmPayment`/`confirmExecution` (Tesorería) cierra a `pagada` y dispara los eventos de dominio (`InvoicePaidEvent`, etc.). Se añade una ruta y acción de controller por módulo, restringida a `RoleConstants::TESORERIA` y `RoleConstants::ADMIN`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB. Sin tests automatizados (ver CLAUDE.md "Testing Policy") — cada tarea cierra con validación manual mediante `php bin/cake server`.

**Spec:** `docs/superpowers/specs/2026-05-08-verificacion-pago-design.md`

**Convención TDD adaptada:** este proyecto no usa tests automatizados. En lugar de "escribir test que falla → implementar → test pasa", cada tarea cierra con un **paso de validación manual** que ejercita el cambio en el navegador o vía `curl`, replicando un escenario observable. Los commits se hacen al final de cada tarea, después de validar.

**Notas técnicas verificadas en código:**
- Las columnas `pipeline_status`/`status` son `VARCHAR/string`, no `ENUM` — **no se requiere migración de schema** (verificado en `config/Migrations/20260219000007_CreateInvoices.php:101`, `config/Migrations/20260313000002_ModifyEmployeeNoveltiesForPipeline.php:11`).
- `SidebarCounterService` cuenta automáticamente cualquier estado que aparezca en `getVisibleStatuses(roleName)` de cada servicio, así que basta con asegurar que el nuevo State declare `RoleConstants::TESORERIA` en `getRoleVisibility()`.
- `RoleConstants::ADMIN` (no `ADMINISTRADOR`) — verificado en `src/Constants/RoleConstants.php:14`.

---

## Mapeo de archivos

### Crear

- `src/Service/Pipeline/State/VerificacionPagoState.php` (Invoices)
- `src/Service/Pipeline/PaymentScheduling/State/VerificacionPagoState.php`
- `src/Service/Pipeline/PettyCash/State/VerificacionPagoState.php`
- `src/Service/Pipeline/Refund/State/VerificacionPagoState.php`
- `src/Service/Pipeline/Novelty/State/VerificacionPagoState.php`

### Modificar

**Enums (5):**
- `src/Constants/Domain/Invoice/PipelineStatus.php`
- `src/Constants/Domain/PaymentScheduling/PipelineStatus.php`
- `src/Constants/Domain/PettyCash/PipelineStatus.php`
- `src/Constants/Domain/Refund/PipelineStatus.php`
- `src/Constants/Domain/Novelty/PipelineStatus.php`

**Constants (3 + 1 trait):**
- `src/Constants/InvoiceConstants.php`
- `src/Constants/PaymentSchedulingConstants.php`
- `src/Constants/NoveltyConstants.php`
- `src/Constants/Concerns/GroupingPipelineConstantsTrait.php` (cubre PettyCash y Refund)

**Presentation (5):**
- `src/View/Presentation/InvoicePresentation.php`
- `src/View/Presentation/PaymentSchedulingPresentation.php`
- `src/View/Presentation/PettyCashPresentation.php`
- `src/View/Presentation/RefundPresentation.php`
- `src/View/Presentation/NoveltyPresentation.php`

**State classes existentes (10):** `AutorizacionPagoState` y `PagadaState` de cada uno de los 5 módulos.

**State Registries (5):** uno por módulo.

**Servicios (5):**
- `src/Service/InvoicePaymentService.php`
- `src/Service/PaymentSchedulingService.php`
- `src/Service/PettyCashService.php`
- `src/Service/RefundPaymentService.php`
- `src/Service/LiquidationDocPaymentService.php`

**Controllers (5):** uno por módulo (acción `confirmPayment`).

**Routes:** `config/routes.php`.

**Vistas:** `templates/Invoices/view.php`, `templates/PaymentSchedulings/view.php`, `templates/PettyCashRecords/view.php`, `templates/Refunds/view.php`, `templates/NoveltyLiquidationDocs/view.php` (botón "Pasar a Pagada").

---

## Fase 0 — Foundations

### Task 1: Añadir VERIFICACION_PAGO a los 5 enums PipelineStatus

**Files:**
- Modify: `src/Constants/Domain/Invoice/PipelineStatus.php`
- Modify: `src/Constants/Domain/PaymentScheduling/PipelineStatus.php`
- Modify: `src/Constants/Domain/PettyCash/PipelineStatus.php`
- Modify: `src/Constants/Domain/Refund/PipelineStatus.php`
- Modify: `src/Constants/Domain/Novelty/PipelineStatus.php`

- [ ] **Step 1: Modificar `Invoice/PipelineStatus.php`**

Reemplazar el enum completo con esta versión (añade caso, ajusta `next()`, `pipelineCases()`):

```php
<?php
declare(strict_types=1);

namespace App\Constants\Domain\Invoice;

enum PipelineStatus: string
{
    case APROBACION = 'aprobacion';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case VERIFICACION_PAGO = 'verificacion_pago';
    case PAGADA = 'pagada';
    case LEGALIZADA = 'legalizada';

    public function label(): string
    {
        return match ($this) {
            self::APROBACION => 'Aprobación',
            self::CONTABILIDAD => 'Contabilidad',
            self::TESORERIA => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::VERIFICACION_PAGO => 'Verificación de pago',
            self::PAGADA => 'Pagada',
            self::LEGALIZADA => 'Legalizada',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::APROBACION => self::CONTABILIDAD,
            self::CONTABILIDAD => self::TESORERIA,
            self::TESORERIA => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::VERIFICACION_PAGO,
            self::VERIFICACION_PAGO => self::PAGADA,
            self::PAGADA, self::LEGALIZADA => null,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PAGADA || $this === self::LEGALIZADA;
    }

    /**
     * @return list<self>
     */
    public static function pipelineCases(): array
    {
        return [
            self::APROBACION,
            self::CONTABILIDAD,
            self::TESORERIA,
            self::AUTORIZACION_PAGO,
            self::VERIFICACION_PAGO,
            self::PAGADA,
        ];
    }

    /**
     * @return list<self>
     */
    public static function legalizationCases(): array
    {
        return [self::APROBACION, self::CONTABILIDAD, self::LEGALIZADA];
    }
}
```

- [ ] **Step 2: Modificar `PaymentScheduling/PipelineStatus.php`**

Añadir el caso `VERIFICACION_PAGO = 'verificacion_pago'` y ajustar `label()` y `next()` para que `AUTORIZACION_PAGO → VERIFICACION_PAGO → PAGADA`. Si el enum tiene un método `pipelineCases()` o equivalente, incluir el nuevo caso entre `AUTORIZACION_PAGO` y `PAGADA`.

- [ ] **Step 3: Modificar `PettyCash/PipelineStatus.php`**

Mismo cambio.

- [ ] **Step 4: Modificar `Refund/PipelineStatus.php`**

Mismo cambio.

- [ ] **Step 5: Modificar `Novelty/PipelineStatus.php`**

Mismo cambio. Importante: el enum de Novelty ya tiene varios estados anteriores (`REGISTRO`, `RRHH`, `GDP`, etc.). Solo insertar `VERIFICACION_PAGO` entre `AUTORIZACION_PAGO` y `PAGADA` en `next()` y en cualquier listado ordenado.

- [ ] **Step 6: Verificar sintácticamente con autoload**

Run: `php -r "require 'vendor/autoload.php'; var_dump(\\App\\Constants\\Domain\\Invoice\\PipelineStatus::VERIFICACION_PAGO->label());"`
Expected: `string(20) "Verificación de pago"`

Repetir para los otros 4 namespaces.

- [ ] **Step 7: Commit**

```bash
git add src/Constants/Domain/
git commit -m "feat(constants): añadir VERIFICACION_PAGO a los 5 enums PipelineStatus"
```

---

### Task 2: Actualizar Constants y Trait

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`
- Modify: `src/Constants/PaymentSchedulingConstants.php`
- Modify: `src/Constants/NoveltyConstants.php`
- Modify: `src/Constants/Concerns/GroupingPipelineConstantsTrait.php`

- [ ] **Step 1: Modificar `InvoiceConstants.php`**

Buscar la sección de pipeline statuses y añadir la nueva constante. Reemplazar:

```php
public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
public const STATUS_PAGADA = PipelineStatus::PAGADA->value;
```

por:

```php
public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
public const STATUS_PAGADA = PipelineStatus::PAGADA->value;
```

Actualizar `PIPELINE_STATUSES`:

```php
public const PIPELINE_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_CONTABILIDAD,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_VERIFICACION_PAGO,
    self::STATUS_PAGADA,
];
```

Actualizar `ALL_STATUSES` (incluye también `LEGALIZADA`):

```php
public const ALL_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_CONTABILIDAD,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_VERIFICACION_PAGO,
    self::STATUS_PAGADA,
    self::STATUS_LEGALIZADA,
];
```

Actualizar `TRANSITIONS`:

```php
public const TRANSITIONS = [
    self::STATUS_APROBACION => self::STATUS_CONTABILIDAD,
    self::STATUS_CONTABILIDAD => self::STATUS_TESORERIA,
    self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
    self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
    self::STATUS_PAGADA => null,
    self::STATUS_LEGALIZADA => null,
];
```

Actualizar `STATUS_LABELS`:

```php
public const STATUS_LABELS = [
    self::STATUS_APROBACION        => 'Aprobación',
    self::STATUS_CONTABILIDAD      => 'Contabilidad',
    self::STATUS_TESORERIA         => 'Tesorería',
    self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
    self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
    self::STATUS_PAGADA            => 'Pagada',
    self::STATUS_LEGALIZADA        => 'Legalizada',
];
```

`PIPELINE_STATUSES_LEGALIZACION` no se toca (las legalizaciones no pasan por verificación de pago).

- [ ] **Step 2: Modificar `PaymentSchedulingConstants.php`**

Añadir constante, actualizar `PIPELINE_STATUSES`, `STATUS_LABELS`, `TRANSITIONS`. Actualizar también `BACKWARD_TRANSITIONS` para permitir regresar de `verificacion_pago → autorizacion_pago`:

```php
public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
public const STATUS_PAGADA = PipelineStatus::PAGADA->value;

public const PIPELINE_STATUSES = [
    self::STATUS_BORRADOR,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_VERIFICACION_PAGO,
    self::STATUS_PAGADA,
];

public const STATUS_LABELS = [
    self::STATUS_BORRADOR => 'Borrador',
    self::STATUS_TESORERIA => 'Tesorería',
    self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
    self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
    self::STATUS_PAGADA => 'Pagada',
];

public const BACKWARD_TRANSITIONS = [
    self::STATUS_BORRADOR => null,
    self::STATUS_TESORERIA => self::STATUS_BORRADOR,
    self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
    self::STATUS_VERIFICACION_PAGO => self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_PAGADA => null,
];

public const FORWARD_TRANSITIONS = [
    self::STATUS_BORRADOR => self::STATUS_TESORERIA,
    self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
    self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
    self::STATUS_PAGADA => null,
];
```

- [ ] **Step 3: Modificar `NoveltyConstants.php`**

Añadir `STATUS_VERIFICACION_PAGO` en la sección de constantes (después de `STATUS_AUTORIZACION_PAGO`) e insertarlo en `PIPELINE_STATUSES`, `ALL_STATUSES`, `STATUS_LABELS`, `TRANSITIONS`. **No** añadir a `ACTIVE_STATUSES` (la semántica actual de "activa" excluye `AUTORIZACION_PAGO` por ser transitorio; `VERIFICACION_PAGO` también es transitorio y mantenemos coherencia — registrar nota en docblock).

```php
public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
public const STATUS_VERIFICACION_PAGO = PipelineStatus::VERIFICACION_PAGO->value;
public const STATUS_PAGADA = PipelineStatus::PAGADA->value;

public const PIPELINE_STATUSES = [
    self::STATUS_APROBACION,
    self::STATUS_RRHH,
    self::STATUS_CONTABILIDAD,
    self::STATUS_REVISION_FIRMAS,
    self::STATUS_GDP,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_VERIFICACION_PAGO,
    self::STATUS_PAGADA,
];

public const ALL_STATUSES = [
    self::STATUS_REGISTRO,
    self::STATUS_APROBACION,
    self::STATUS_RRHH,
    self::STATUS_CONTABILIDAD,
    self::STATUS_REVISION_FIRMAS,
    self::STATUS_GDP,
    self::STATUS_TESORERIA,
    self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_VERIFICACION_PAGO,
    self::STATUS_PAGADA,
    self::STATUS_RECHAZADA,
];

public const STATUS_LABELS = [
    self::STATUS_REGISTRO => 'Registro',
    self::STATUS_APROBACION => 'Aprobación',
    self::STATUS_RRHH => 'RRHH',
    self::STATUS_CONTABILIDAD => 'Contabilidad',
    self::STATUS_REVISION_FIRMAS => 'Revisión y Firmas de documentos',
    self::STATUS_GDP => 'GDP',
    self::STATUS_TESORERIA => 'Tesorería',
    self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
    self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
    self::STATUS_PAGADA => 'Pagada',
    self::STATUS_RECHAZADA => 'Rechazada',
];

public const TRANSITIONS = [
    self::STATUS_APROBACION => self::STATUS_RRHH,
    self::STATUS_RRHH => self::STATUS_CONTABILIDAD,
    self::STATUS_CONTABILIDAD => self::STATUS_REVISION_FIRMAS,
    self::STATUS_REVISION_FIRMAS => self::STATUS_GDP,
    self::STATUS_GDP => self::STATUS_TESORERIA,
    self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
    self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
    self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
    self::STATUS_PAGADA => null,
];
```

Actualizar el docblock de `ACTIVE_STATUSES` añadiendo `VERIFICACION_PAGO` a la lista de exclusiones explicada.

- [ ] **Step 4: Modificar `GroupingPipelineConstantsTrait.php`**

Reemplazar el trait completo:

```php
<?php
declare(strict_types=1);

namespace App\Constants\Concerns;

use App\Constants\ObservationConstants;

trait GroupingPipelineConstantsTrait
{
    public const STATUS_AGRUPACION = 'agrupacion';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUTORIZACION_PAGO = 'autorizacion_pago';
    public const STATUS_VERIFICACION_PAGO = 'verificacion_pago';
    public const STATUS_PAGADA = 'pagada';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_VERIFICACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_AGRUPACION => 'Agrupación',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_VERIFICACION_PAGO => 'Verificación de pago',
        self::STATUS_PAGADA => 'Pagada',
    ];

    public const TRANSITIONS = [
        self::STATUS_AGRUPACION => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_VERIFICACION_PAGO,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA => null,
    ];

    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA => self::STATUS_CONTABILIDAD,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
        self::STATUS_VERIFICACION_PAGO => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA => null,
    ];

    public const OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL;
    public const OBSERVATION_TYPE_REGRESSION = ObservationConstants::TYPE_REGRESSION;

    public const OBSERVATION_TYPES = ObservationConstants::TYPES;
}
```

Actualizar el docblock superior del trait describiendo el nuevo flujo de 6 estados.

- [ ] **Step 5: Verificar autoload**

Run: `php -r "require 'vendor/autoload.php'; var_dump(\\App\\Constants\\InvoiceConstants::PIPELINE_STATUSES);"`
Expected: array de 6 strings que incluya `'verificacion_pago'` antes de `'pagada'`.

Run: `php -r "require 'vendor/autoload.php'; var_dump(\\App\\Constants\\PettyCashConstants::TRANSITIONS);"`
Expected: array donde `autorizacion_pago` mapea a `verificacion_pago` y `verificacion_pago` mapea a `pagada`.

- [ ] **Step 6: Commit**

```bash
git add src/Constants/InvoiceConstants.php src/Constants/PaymentSchedulingConstants.php src/Constants/NoveltyConstants.php src/Constants/Concerns/GroupingPipelineConstantsTrait.php
git commit -m "feat(constants): añadir STATUS_VERIFICACION_PAGO en 5 módulos"
```

---

### Task 3: Actualizar Presentation (badges + iconos)

**Files:**
- Modify: `src/View/Presentation/InvoicePresentation.php`
- Modify: `src/View/Presentation/PaymentSchedulingPresentation.php`
- Modify: `src/View/Presentation/PettyCashPresentation.php`
- Modify: `src/View/Presentation/RefundPresentation.php`
- Modify: `src/View/Presentation/NoveltyPresentation.php`

- [ ] **Step 1: Modificar `InvoicePresentation.php`**

Reemplazar el archivo completo:

```php
<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;

final class InvoicePresentation
{
    public const STATUS_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'bg-warning text-dark',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        InvoiceConstants::STATUS_TESORERIA         => 'bg-info',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        InvoiceConstants::STATUS_PAGADA            => 'bg-success',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bg-success',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];
}
```

- [ ] **Step 2: Modificar `PaymentSchedulingPresentation.php`**

Añadir entradas para `VERIFICACION_PAGO` con el mismo `'bg-warning text-dark'` y el mismo icono `'bi-hourglass-split'` antes de `PAGADA`.

- [ ] **Step 3: Modificar `PettyCashPresentation.php`**

Mismo patrón: añadir entrada para `STATUS_VERIFICACION_PAGO` (constante heredada del trait via `PettyCashConstants::STATUS_VERIFICACION_PAGO`).

- [ ] **Step 4: Modificar `RefundPresentation.php`**

Mismo patrón con `RefundConstants::STATUS_VERIFICACION_PAGO`.

- [ ] **Step 5: Modificar `NoveltyPresentation.php`**

Mismo patrón con `NoveltyConstants::STATUS_VERIFICACION_PAGO`.

- [ ] **Step 6: Commit**

```bash
git add src/View/Presentation/
git commit -m "feat(presentation): badge e icono para verificacion_pago en 5 módulos"
```

---

## Fase 1 — Invoices

### Task 4: State pattern Invoices

**Files:**
- Create: `src/Service/Pipeline/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/State/PagadaState.php`
- Modify: `src/Service/Pipeline/InvoicePipelineStateRegistry.php`

- [ ] **Step 1: Crear `VerificacionPagoState.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\State;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Constants\RoleConstants;
use App\Service\Pipeline\InvoicePipelineState;

final class VerificacionPagoState implements InvoicePipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function getRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::ADMIN];
    }

    public function getAdvanceRoleVisibility(): array
    {
        return [RoleConstants::TESORERIA, RoleConstants::ADMIN];
    }

    public function validateAdvance(object $invoice): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }

    public function getTransitionRules(): array
    {
        return [
            ['field' => '_payment_executed', 'label' => 'Tesorería debe confirmar que el pago se ejecutó'],
        ];
    }
}
```

- [ ] **Step 2: Modificar `AutorizacionPagoState.php`**

Cambiar `getNextStatus()`:

```php
public function getNextStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 3: Modificar `PagadaState.php`**

Cambiar `getPreviousStatus()`:

```php
public function getPreviousStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 4: Modificar `InvoicePipelineStateRegistry.php`**

Añadir el nuevo State al constructor y al ciclo. Reemplazar el constructor:

```php
public function __construct(
    AprobacionState $aprobacion,
    ContabilidadState $contabilidad,
    TesoreriaState $tesoreria,
    AutorizacionPagoState $autorizacionPago,
    VerificacionPagoState $verificacionPago,
    PagadaState $pagada,
    LegalizadaState $legalizada,
) {
    foreach ([$aprobacion, $contabilidad, $tesoreria, $autorizacionPago, $verificacionPago, $pagada, $legalizada] as $state) {
        $this->states[$state->getStatus()->value] = $state;
    }
}
```

Añadir `use App\Service\Pipeline\State\VerificacionPagoState;` arriba.

- [ ] **Step 5: Levantar servidor y verificar que carga**

Run: `php bin/cake server` (en otra terminal: `curl -s http://localhost:8765/ -o /dev/null -w "%{http_code}\n"`)
Expected: `200` (o `302` redirigiendo a login). Si hay un error fatal, el server lo reporta.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/State/ src/Service/Pipeline/InvoicePipelineStateRegistry.php
git commit -m "feat(invoices): añadir VerificacionPagoState al pipeline"
```

---

### Task 5: Modificar `InvoicePaymentService` para desacoplar autorización de pagada

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`

- [ ] **Step 1: Modificar `authorizePayment()` — cambiar destino de pago total**

Localizar el bloque (alrededor de la línea 149):

```php
$newPipelineStatus = $invoice->payment_status === InvoiceConstants::PAYMENT_FULL
    ? InvoiceConstants::STATUS_PAGADA
    : InvoiceConstants::STATUS_TESORERIA;
```

Reemplazar por:

```php
$newPipelineStatus = $invoice->payment_status === InvoiceConstants::PAYMENT_FULL
    ? InvoiceConstants::STATUS_VERIFICACION_PAGO
    : InvoiceConstants::STATUS_TESORERIA;
```

Localizar el bloque del dispatch de `InvoicePaidEvent` dentro de `authorizePayment` (alrededor de la línea 173):

```php
if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA) {
    $this->events->dispatch(new Event(
        'Invoice.paid',
        null,
        ['payload' => new InvoicePaidEvent($invoice, $authorizedBy)],
    ));
}
```

Eliminar este bloque (el evento se moverá al nuevo método `confirmPaymentExecuted`). El `InvoiceRefundAuthorizedEvent` (más arriba en el mismo método) **se mantiene** sin cambios — el Contador ya dio el OK financiero del reembolso.

- [ ] **Step 2: Añadir nuevo método `confirmPaymentExecuted()` al final de la clase (antes del cierre `}`)**

```php
/**
 * Confirma que el pago efectivamente fue ejecutado por Tesorería.
 * Avanza la factura de verificacion_pago → pagada en una sola transacción,
 * registra historial y dispara InvoicePaidEvent (que activa
 * LegalizationInitializerSubscriber para anticipos).
 */
public function confirmPaymentExecuted(int $invoiceId, int $confirmedBy): ServiceResult
{
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $invoice = $invoicesTable->get($invoiceId);

    if ($invoice->pipeline_status !== InvoiceConstants::STATUS_VERIFICACION_PAGO) {
        return ServiceResult::fail(['La factura no está en verificación de pago.']);
    }

    $connection = $invoicesTable->getConnection();
    $ok = $connection->transactional(function () use ($invoicesTable, $invoice, $invoiceId, $confirmedBy) {
        if (!$this->recalculatePaymentStatus($invoiceId)) {
            return false;
        }

        $refreshed = $invoicesTable->get($invoiceId);
        if ($refreshed->payment_status !== InvoiceConstants::PAYMENT_FULL) {
            return false;
        }

        $previousStatus = $refreshed->pipeline_status;
        $refreshed->pipeline_status = InvoiceConstants::STATUS_PAGADA;
        if (!$invoicesTable->save($refreshed)) {
            return false;
        }

        $this->historyService->recordStatusChange(
            $refreshed->id,
            $previousStatus,
            InvoiceConstants::STATUS_PAGADA,
            $confirmedBy,
        );

        $this->events->dispatch(new Event(
            'Invoice.paid',
            null,
            ['payload' => new InvoicePaidEvent($refreshed, $confirmedBy)],
        ));

        return true;
    });

    if ($ok === false) {
        return ServiceResult::fail(['No se pudo confirmar el pago.']);
    }

    return ServiceResult::ok(['newPipelineStatus' => InvoiceConstants::STATUS_PAGADA]);
}
```

Asegurar que `use App\Service\ServiceResult;` esté importado (debería estarlo ya en archivos del namespace `App\Service`; si no, añadir el import).

- [ ] **Step 3: Verificar que el archivo carga sin errores**

Run: `composer cs-check src/Service/InvoicePaymentService.php`
Expected: sin errores (o solo warnings de estilo aceptables).

Run: `php -r "require 'vendor/autoload.php'; \\$rc = new ReflectionClass('App\\\\Service\\\\InvoicePaymentService'); var_dump(\\$rc->hasMethod('confirmPaymentExecuted'));"`
Expected: `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add src/Service/InvoicePaymentService.php
git commit -m "feat(invoices): desacoplar autorización de marca-pagada — añadir confirmPaymentExecuted"
```

---

### Task 6: Controller, ruta y vista para Invoices

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php` (añadir `confirmPayment` action)
- Modify: `config/routes.php`
- Modify: `templates/Invoices/view.php` (botón "Pasar a Pagada")

- [ ] **Step 1: Añadir acción `confirmPayment` al `InvoicePaymentsController`**

Localizar dónde está la acción `authorizePayment` actual y añadir, justo debajo (sin reemplazar nada):

```php
/**
 * Confirma que el pago de una factura ya se ejecutó. Avanza de
 * verificacion_pago → pagada. Restringido a Tesorería y Admin.
 */
public function confirmPayment(int $invoiceId): \Cake\Http\Response
{
    $this->request->allowMethod(['post']);

    $currentUser = $this->_getCurrentUser();
    $roleName = $currentUser->role->name ?? null;
    if (!in_array($roleName, [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar este pago.');
        return $this->redirect($this->referer());
    }

    $result = $this->paymentService->confirmPaymentExecuted((int)$invoiceId, (int)$currentUser->id);

    if (!$result->success) {
        $this->Flash->error($result->errors[0] ?? 'No se pudo confirmar el pago.');
        return $this->redirect($this->referer());
    }

    $this->Flash->success('Pago confirmado. La factura quedó marcada como pagada.');
    return $this->redirect(['controller' => 'Invoices', 'action' => 'view', $invoiceId]);
}
```

Si el controller ya tiene imports de `RoleConstants` o helpers, puedes simplificar usando esos imports en lugar del FQN.

- [ ] **Step 2: Añadir ruta en `config/routes.php`**

Buscar la ruta existente `/invoices/authorize-payment/{invoiceId}/{paymentId}` (alrededor de la línea 416). Justo después, añadir:

```php
$builder->connect(
    '/invoices/confirm-payment/{invoiceId}',
    ['controller' => 'InvoicePayments', 'action' => 'confirmPayment'],
    ['pass' => ['invoiceId'], 'invoiceId' => '\d+'],
);
```

Asegurar que está antes de `$builder->fallbacks();`.

- [ ] **Step 3: Mapear acción en `AppController::$controllerModuleMap` o equivalente**

Run: `git grep -n "authorizePayment.*=>" src/Controller/AppController.php | head -5`

Localizar el array que mapea acciones del controller `InvoicePayments` a permisos. Añadir `'confirmPayment' => 'can_edit'` (o el patrón que use el proyecto). Si la acción `authorizePayment` ya se mapea ahí, replicar el mismo mapeo.

Si no existe entrada explícita, dejar que herede el comportamiento por defecto (`can_edit` → módulo Invoices). Verificar con un POST de prueba en Step 5.

- [ ] **Step 4: Añadir botón "Pasar a Pagada" en `templates/Invoices/view.php`**

Localizar la zona de la vista donde se muestra el `pipeline_progress` o la sección de pagos. Añadir, condicionalmente, justo antes o después del bloque de pagos:

```php
<?php
use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

$currentRole = $this->getRequest()->getAttribute('identity')->role->name ?? null;
$canConfirm = in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true);
?>
<?php if ($invoice->pipeline_status === InvoiceConstants::STATUS_VERIFICACION_PAGO && $canConfirm): ?>
    <div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem;">
                El pago fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-cash-coin"></i> Pasar a Pagada',
                ['controller' => 'InvoicePayments', 'action' => 'confirmPayment', $invoice->id],
                ['class' => 'sgi-btn-primary', 'escape' => false, 'confirm' => '¿Confirmar que el pago ya se ejecutó?']
            ) ?>
        </div>
    </div>
<?php endif; ?>
```

Si la vista usa otro mecanismo para acceder al rol del usuario, ajustar la captura de `$currentRole` al patrón de la vista.

- [ ] **Step 5: Validación manual de Invoices**

Levantar el servidor:

```bash
php bin/cake server
```

Escenario A (pago total — flujo verificación):

1. Login como Registro/Revisión. Crear nueva factura con monto $100,000, doctype = `Factura`.
2. Avanzar la factura por aprobación, contabilidad, tesorería (registrando pago total = $100,000) hasta que quede en `autorizacion_pago` con un pago pendiente de autorizar.
3. Logout. Login como Contador.
4. Autorizar el pago.
5. Verificar en la vista de la factura: estado = "Verificación de pago", pipeline progress muestra 6 pasos con el 5° activo.
6. Logout. Login como Tesorería.
7. Abrir la factura. Verificar que aparece el bloque con botón "Pasar a Pagada".
8. Click el botón → confirmar el dialog.
9. Verificar: factura en estado `pagada`, último paso del pipeline en verde.
10. Verificar `invoice_histories` muestra dos transiciones: `autorizacion_pago → verificacion_pago` (autor: Contador) y `verificacion_pago → pagada` (autor: Tesorería).

Escenario B (pago parcial — sin cambios):

1. Repetir setup hasta `autorizacion_pago` pero con pago parcial = $50,000 (factura de $100,000).
2. Login como Contador, autorizar el pago.
3. Verificar: factura **regresa a `tesoreria`** (NO a `verificacion_pago`). `payment_status = 'Pago Parcial'`.

Escenario C (anticipo — InvoicePaidEvent dispara legalización):

1. Crear factura con `document_type = 'Anticipo'`, llevar hasta `verificacion_pago`.
2. Como Tesorería, click "Pasar a Pagada".
3. Verificar que se inició el flujo de legalización (la legalización aparece en su listado/módulo correspondiente). Esto valida que `InvoicePaidEvent` se disparó en el momento correcto.

Escenario D (permisos):

1. Login como Contador. Manualmente: `curl -X POST http://localhost:8765/invoices/confirm-payment/{id} -b "cookie..."` (o intentar el botón si visible).
2. Verificar respuesta de error o flash "No tiene permisos para confirmar este pago.".

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InvoicePaymentsController.php config/routes.php templates/Invoices/view.php src/Controller/AppController.php
git commit -m "feat(invoices): acción confirmPayment + ruta + botón en view"
```

---

## Fase 2 — PaymentScheduling

### Task 7: State pattern PaymentScheduling

**Files:**
- Create: `src/Service/Pipeline/PaymentScheduling/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/PaymentScheduling/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/PaymentScheduling/State/PagadaState.php`
- Modify: `src/Service/Pipeline/PaymentScheduling/PaymentSchedulingPipelineStateRegistry.php`

- [ ] **Step 1: Crear `VerificacionPagoState.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PaymentScheduling\State;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use App\Model\Entity\PaymentScheduling;
use App\Service\Pipeline\PaymentScheduling\PaymentSchedulingPipelineState;

final class VerificacionPagoState implements PaymentSchedulingPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvance(PaymentScheduling $scheduling): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}
```

- [ ] **Step 2: Modificar `AutorizacionPagoState.php`**

```php
public function getNextStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 3: Modificar `PagadaState.php`**

Cambiar `getPreviousStatus()`:

```php
public function getPreviousStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 4: Modificar `PaymentSchedulingPipelineStateRegistry.php`**

```php
public function __construct(
    ?BorradorState $borrador = null,
    ?TesoreriaState $tesoreria = null,
    ?AutorizacionPagoState $autorizacionPago = null,
    ?VerificacionPagoState $verificacionPago = null,
    ?PagadaState $pagada = null,
) {
    $list = [
        $borrador ?? new BorradorState(),
        $tesoreria ?? new TesoreriaState(),
        $autorizacionPago ?? new AutorizacionPagoState(),
        $verificacionPago ?? new VerificacionPagoState(),
        $pagada ?? new PagadaState(),
    ];

    foreach ($list as $state) {
        $this->states[$state->getStatus()->value] = $state;
    }
}
```

Añadir `use App\Service\Pipeline\PaymentScheduling\State\VerificacionPagoState;`.

- [ ] **Step 5: Levantar el servidor y verificar carga**

Run: `php bin/cake server`
Curl la home: `curl -s http://localhost:8765/ -o /dev/null -w "%{http_code}\n"`
Expected: `200` o `302`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/PaymentScheduling/
git commit -m "feat(payment-scheduling): añadir VerificacionPagoState al pipeline"
```

---

### Task 8: Modificar `PaymentSchedulingService` para desacoplar autorización de pagada

**Files:**
- Modify: `src/Service/PaymentSchedulingService.php`

- [ ] **Step 1: Modificar `applyPayments()` — cambiar destino de facturas hijas**

Localizar el bloque (alrededor de la línea 273) dentro del foreach por `$appliedInvoiceIds`:

```php
$invoice = $invoicesTable->get($invoiceId);
if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
    $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
    $invoicesTable->save($invoice);
    $advanced[] = $invoiceId;
} else {
    $partial[] = $invoiceId;
}
```

Reemplazar por:

```php
$invoice = $invoicesTable->get($invoiceId);
if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
    $invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
    $invoicesTable->save($invoice);
    $advanced[] = $invoiceId;
} else {
    $partial[] = $invoiceId;
}
```

Buscar más adelante en el mismo método o en el caller dónde el `scheduling` se mueve a `STATUS_PAGADA`. Si se setea explícitamente:

Run: `git grep -n "STATUS_PAGADA\|STATUS_VERIFICACION_PAGO" src/Service/PaymentSchedulingService.php`

Cambiar la asignación del scheduling a `PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO`. Si el scheduling avanza por otro camino (coordinador del pipeline), confirmar manualmente en el escenario de validación.

- [ ] **Step 2: Añadir nuevo método `confirmExecution()` al final de la clase**

```php
/**
 * Confirma que los pagos de la programación efectivamente se ejecutaron.
 * Avanza el scheduling y todas sus facturas hijas de verificacion_pago → pagada,
 * recalcula payment_status, y dispara InvoicePaidEvent por cada hija.
 */
public function confirmExecution(int $schedulingId, int $confirmedBy): \App\Service\ServiceResult
{
    $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

    $scheduling = $schedulingsTable->get($schedulingId);
    if ($scheduling->pipeline_status !== \App\Constants\PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO) {
        return \App\Service\ServiceResult::fail(['La programación no está en verificación de pago.']);
    }

    $connection = $schedulingsTable->getConnection();
    $ok = $connection->transactional(function () use ($schedulingsTable, $invoicesTable, $scheduling, $schedulingId, $confirmedBy) {
        $scheduling->pipeline_status = \App\Constants\PaymentSchedulingConstants::STATUS_PAGADA;
        if (!$schedulingsTable->save($scheduling)) {
            return false;
        }

        $childInvoices = $invoicesTable->find()
            ->where([
                'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
                'id IN' => $invoicesTable->find()
                    ->select(['id'])
                    ->matching('InvoicePayments', function ($q) use ($schedulingId) {
                        return $q->where(['InvoicePayments.payment_scheduling_id' => $schedulingId]);
                    }),
            ])
            ->all();

        foreach ($childInvoices as $invoice) {
            if (!$this->paymentService->recalculatePaymentStatus($invoice->id)) {
                return false;
            }
            $refreshed = $invoicesTable->get($invoice->id);
            $previousStatus = $refreshed->pipeline_status;
            $refreshed->pipeline_status = InvoiceConstants::STATUS_PAGADA;
            if (!$invoicesTable->save($refreshed)) {
                return false;
            }
            $this->historyService->recordStatusChange(
                $refreshed->id,
                $previousStatus,
                InvoiceConstants::STATUS_PAGADA,
                $confirmedBy,
            );
            $this->events->dispatch(new \Cake\Event\Event(
                'Invoice.paid',
                null,
                ['payload' => new \App\Event\InvoicePaidEvent($refreshed, $confirmedBy)],
            ));
        }

        return true;
    });

    if ($ok === false) {
        return \App\Service\ServiceResult::fail(['No se pudo confirmar la programación.']);
    }

    return \App\Service\ServiceResult::ok(['newPipelineStatus' => \App\Constants\PaymentSchedulingConstants::STATUS_PAGADA]);
}
```

**Importante:** verificar que `PaymentSchedulingService` ya tenga inyectados `$paymentService` (`InvoicePaymentService`), `$historyService` (`InvoiceHistoryService`) y `$events` (`EventManagerInterface`). Si falta alguno, añadirlo al constructor con `?? new ServiceClass()` fallback siguiendo el patrón del proyecto. Ver constructor existente:

Run: `git grep -n "__construct" src/Service/PaymentSchedulingService.php | head -3`

Si la inyección está incompleta, añadir las dependencias necesarias (consultar el patrón usado en `InvoicePaymentService`).

- [ ] **Step 3: Verificar carga**

Run: `composer cs-check src/Service/PaymentSchedulingService.php`
Expected: sin errores.

Run: `php -r "require 'vendor/autoload.php'; \\$rc = new ReflectionClass('App\\\\Service\\\\PaymentSchedulingService'); var_dump(\\$rc->hasMethod('confirmExecution'));"`
Expected: `bool(true)`

- [ ] **Step 4: Commit**

```bash
git add src/Service/PaymentSchedulingService.php
git commit -m "feat(payment-scheduling): desacoplar autorización de pagada — añadir confirmExecution"
```

---

### Task 9: Controller, ruta y vista PaymentScheduling

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php` (asumido — confirmar nombre real con `git grep`)
- Modify: `config/routes.php`
- Modify: `templates/PaymentSchedulings/view.php`

- [ ] **Step 1: Identificar controller real**

Run: `git grep -l "PaymentScheduling" src/Controller/`
Localizar el controller responsable de la acción "view" para schedulings.

- [ ] **Step 2: Añadir acción `confirmPayment` al controller**

Patrón idéntico al de Invoices, ajustando el redirect al view del scheduling:

```php
public function confirmPayment(int $id): \Cake\Http\Response
{
    $this->request->allowMethod(['post']);

    $currentUser = $this->_getCurrentUser();
    $roleName = $currentUser->role->name ?? null;
    if (!in_array($roleName, [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar este pago.');
        return $this->redirect($this->referer());
    }

    $result = $this->schedulingService->confirmExecution((int)$id, (int)$currentUser->id);

    if (!$result->success) {
        $this->Flash->error($result->errors[0] ?? 'No se pudo confirmar la programación.');
        return $this->redirect($this->referer());
    }

    $this->Flash->success('Programación confirmada. Las facturas quedaron como pagadas.');
    return $this->redirect(['action' => 'view', $id]);
}
```

Reemplazar `$this->schedulingService` por el nombre real de la propiedad inyectada en el controller.

- [ ] **Step 3: Añadir ruta en `config/routes.php`**

Localizar el bloque cercano a las rutas de payment-schedulings (alrededor de las líneas que ya tienen rutas de scheduling). Añadir antes de fallbacks:

```php
$builder->connect(
    '/payment-schedulings/confirm-payment/{id}',
    ['controller' => 'PaymentSchedulings', 'action' => 'confirmPayment'],
    ['pass' => ['id'], 'id' => '\d+'],
);
```

- [ ] **Step 4: Añadir botón en `templates/PaymentSchedulings/view.php`**

```php
<?php
use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;

$currentRole = $this->getRequest()->getAttribute('identity')->role->name ?? null;
$canConfirm = in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true);
?>
<?php if ($paymentScheduling->pipeline_status === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO && $canConfirm): ?>
    <div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem;">
                Los pagos fueron autorizados por el Contador. Confirme cuando el dinero haya salido del banco.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-cash-coin"></i> Pasar a Pagada',
                ['action' => 'confirmPayment', $paymentScheduling->id],
                ['class' => 'sgi-btn-primary', 'escape' => false, 'confirm' => '¿Confirmar que los pagos ya se ejecutaron?']
            ) ?>
        </div>
    </div>
<?php endif; ?>
```

Ajustar el nombre de la variable (`$paymentScheduling` o el que use la vista actual).

- [ ] **Step 5: Validación manual PaymentScheduling**

Run: `php bin/cake server`

Escenario:

1. Login como Tesorería. Crear nueva programación de pago con 2-3 facturas en estado `tesoreria`. Avanzar a `autorizacion_pago`.
2. Login como Contador. Autorizar la programación.
3. Verificar: scheduling y todas sus facturas hijas en `verificacion_pago`. **Ningún `InvoicePaidEvent` disparado todavía** (verificar que ningún proceso post-pago se ejecutó — p. ej., si alguna factura era anticipo, la legalización no se inició).
4. Login como Tesorería. Abrir la vista del scheduling. Verificar botón "Pasar a Pagada".
5. Click → confirmar.
6. Verificar: scheduling en `pagada`, todas las facturas hijas en `pagada`, históricos registrados, y si había anticipos, las legalizaciones aparecen ahora.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/ config/routes.php templates/PaymentSchedulings/
git commit -m "feat(payment-scheduling): acción confirmPayment + ruta + botón en view"
```

---

## Fase 3 — PettyCash

### Task 10: State pattern PettyCash

**Files:**
- Create: `src/Service/Pipeline/PettyCash/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/PettyCash/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/PettyCash/State/PagadaState.php`
- Modify: `src/Service/Pipeline/PettyCash/PettyCashPipelineStateRegistry.php`

- [ ] **Step 1: Crear `VerificacionPagoState.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\State;

use App\Constants\Domain\PettyCash\PipelineStatus;
use App\Model\Entity\PettyCashRecord;
use App\Service\Pipeline\PettyCash\PettyCashPipelineState;

final class VerificacionPagoState implements PettyCashPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvance(PettyCashRecord $record): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}
```

- [ ] **Step 2: Modificar `AutorizacionPagoState.php`**

```php
public function getNextStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 3: Modificar `PagadaState.php`**

```php
public function getPreviousStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 4: Modificar `PettyCashPipelineStateRegistry.php`**

Run: `cat src/Service/Pipeline/PettyCash/PettyCashPipelineStateRegistry.php`

Añadir `VerificacionPagoState` al constructor y al ciclo de inicialización, siguiendo el mismo patrón que ya tiene el archivo (similar a PaymentSchedulingPipelineStateRegistry).

- [ ] **Step 5: Verificar carga**

Run: `php bin/cake server` y `curl -s http://localhost:8765/ -o /dev/null -w "%{http_code}\n"`
Expected: `200` o `302`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/PettyCash/
git commit -m "feat(petty-cash): añadir VerificacionPagoState al pipeline"
```

---

### Task 11: Modificar `PettyCashService` para desacoplar autorización de pagada

**Files:**
- Modify: `src/Service/PettyCashService.php`

- [ ] **Step 1: Modificar `authorizePayment()` — cambiar destino**

Localizar el bloque (alrededor de la línea 628):

```php
$invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
$invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
$invoice->full_payment_date = $record->payment_date;
```

Reemplazar la primera línea por:

```php
$invoice->pipeline_status = InvoiceConstants::STATUS_VERIFICACION_PAGO;
$invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
$invoice->full_payment_date = $record->payment_date;
```

Localizar también el bloque (alrededor de la línea 638):

```php
$record->status = PettyCashConstants::STATUS_PAGADA;
$record->payment_status = InvoiceConstants::PAYMENT_FULL;
$record->payment_authorized_by = $authorizedBy;
$record->payment_authorized_date = date('Y-m-d');
```

Cambiar la primera línea por:

```php
$record->status = PettyCashConstants::STATUS_VERIFICACION_PAGO;
```

(las demás líneas se mantienen).

Si hay un dispatch de `InvoicePaidEvent` dentro del transactional callback, removerlo (se moverá a `confirmPayment`). Si no hay dispatch en `authorizePayment` actual, no tocar.

Run: `git grep -n "InvoicePaidEvent\|Invoice.paid" src/Service/PettyCashService.php` para verificar.

- [ ] **Step 2: Añadir nuevo método `confirmPayment()` al final de la clase**

```php
/**
 * Confirma que el pago del record de caja menor se ejecutó. Avanza el record
 * y todas sus facturas hijas de verificacion_pago → pagada en una transacción,
 * registra historial y dispara InvoicePaidEvent por cada hija.
 */
public function confirmPayment(int $recordId, int $confirmedBy): \App\Service\ServiceResult
{
    $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

    $record = $recordsTable->get($recordId);
    if ($record->status !== \App\Constants\PettyCashConstants::STATUS_VERIFICACION_PAGO) {
        return \App\Service\ServiceResult::fail(['El record no está en verificación de pago.']);
    }

    $connection = $recordsTable->getConnection();
    $ok = $connection->transactional(function () use ($recordsTable, $invoicesTable, $record, $confirmedBy) {
        $previousStatus = $record->status;
        $record->status = \App\Constants\PettyCashConstants::STATUS_PAGADA;
        if (!$recordsTable->save($record)) {
            return false;
        }

        $this->history->recordStatusChange(
            $record->id,
            $previousStatus,
            $record->status,
            $confirmedBy,
        );

        $childInvoices = $invoicesTable->find()
            ->where([
                'petty_cash_record_id' => $record->id,
                'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
            ])
            ->all();

        foreach ($childInvoices as $invoice) {
            $invoicePreviousStatus = $invoice->pipeline_status;
            $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
            if (!$invoicesTable->save($invoice)) {
                return false;
            }
            $this->invoiceHistory->recordStatusChange(
                $invoice->id,
                $invoicePreviousStatus,
                InvoiceConstants::STATUS_PAGADA,
                $confirmedBy,
            );
            $this->events->dispatch(new \Cake\Event\Event(
                'Invoice.paid',
                null,
                ['payload' => new \App\Event\InvoicePaidEvent($invoice, $confirmedBy)],
            ));
        }

        return true;
    });

    if ($ok === false) {
        return \App\Service\ServiceResult::fail(['No se pudo confirmar el pago.']);
    }

    return \App\Service\ServiceResult::ok(['newStatus' => \App\Constants\PettyCashConstants::STATUS_PAGADA]);
}
```

**Verificar dependencias del constructor.** Si `$this->invoiceHistory` o `$this->events` no están inyectados, añadirlos al constructor con la convención del proyecto (nullable + fallback `?? new ...`). El nombre exacto de la propiedad de history se verifica con:

Run: `git grep -n "private.*history\|history " src/Service/PettyCashService.php | head -10`

Ajustar nombres en el código según lo que ya esté inyectado.

- [ ] **Step 3: Verificar carga**

Run: `composer cs-check src/Service/PettyCashService.php`
Expected: sin errores.

- [ ] **Step 4: Commit**

```bash
git add src/Service/PettyCashService.php
git commit -m "feat(petty-cash): desacoplar autorización de pagada — añadir confirmPayment"
```

---

### Task 12: Controller, ruta y vista PettyCash

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `config/routes.php`
- Modify: `templates/PettyCashRecords/view.php`

- [ ] **Step 1: Añadir acción `confirmPayment` al `PettyCashRecordsController`**

Localizar la acción `authorizePayment` existente (línea 443 según grep previo). Añadir debajo:

```php
public function confirmPayment(int $id): \Cake\Http\Response
{
    $this->request->allowMethod(['post']);

    $currentUser = $this->_getCurrentUser();
    $roleName = $currentUser->role->name ?? null;
    if (!in_array($roleName, [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar este pago.');
        return $this->redirect($this->referer());
    }

    $result = $this->pettyCashService->confirmPayment((int)$id, (int)$currentUser->id);

    if (!$result->success) {
        $this->Flash->error($result->errors[0] ?? 'No se pudo confirmar el pago.');
        return $this->redirect($this->referer());
    }

    $this->Flash->success('Pago confirmado. El record y sus facturas quedaron como pagados.');
    return $this->redirect(['action' => 'view', $id]);
}
```

- [ ] **Step 2: Añadir ruta**

Localizar `/petty-cash-records/authorize-payment/{id}` (línea 350 según grep previo). Añadir después:

```php
$builder->connect(
    '/petty-cash-records/confirm-payment/{id}',
    ['controller' => 'PettyCashRecords', 'action' => 'confirmPayment'],
    ['pass' => ['id'], 'id' => '\d+'],
);
```

- [ ] **Step 3: Añadir botón en `templates/PettyCashRecords/view.php`**

```php
<?php
use App\Constants\PettyCashConstants;
use App\Constants\RoleConstants;

$currentRole = $this->getRequest()->getAttribute('identity')->role->name ?? null;
$canConfirm = in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true);
?>
<?php if ($pettyCashRecord->status === PettyCashConstants::STATUS_VERIFICACION_PAGO && $canConfirm): ?>
    <div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem;">
                El pago fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-cash-coin"></i> Pasar a Pagada',
                ['action' => 'confirmPayment', $pettyCashRecord->id],
                ['class' => 'sgi-btn-primary', 'escape' => false, 'confirm' => '¿Confirmar que el pago ya se ejecutó?']
            ) ?>
        </div>
    </div>
<?php endif; ?>
```

Verificar que la variable de la vista sea `$pettyCashRecord` (`git grep -n "pettyCashRecord\|petty_cash_record" templates/PettyCashRecords/view.php | head -5`).

- [ ] **Step 4: Validación manual PettyCash**

Run: `php bin/cake server`

Escenario:

1. Crear un record de caja menor con varias facturas hijas, avanzarlo a `autorizacion_pago` (Contabilidad → Tesorería registra pago).
2. Login como Contador. Autorizar el pago.
3. Verificar: record y todas las facturas hijas en `verificacion_pago`.
4. Login como Tesorería. Abrir la vista del record. Verificar botón.
5. Click → confirmar.
6. Verificar: record y facturas en `pagada`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php config/routes.php templates/PettyCashRecords/
git commit -m "feat(petty-cash): acción confirmPayment + ruta + botón en view"
```

---

## Fase 4 — Refund

### Task 13: State pattern Refund

**Files:**
- Create: `src/Service/Pipeline/Refund/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/Refund/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/Refund/State/PagadaState.php`
- Modify: `src/Service/Pipeline/Refund/RefundPipelineStateRegistry.php`

- [ ] **Step 1: Crear `VerificacionPagoState.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Refund\State;

use App\Constants\Domain\Refund\PipelineStatus;
use App\Model\Entity\Refund;
use App\Service\Pipeline\Refund\RefundPipelineState;

final class VerificacionPagoState implements RefundPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvance(Refund $record): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }

    public function getRegressionLockMessage(Refund $record): ?string
    {
        return null;
    }
}
```

- [ ] **Step 2: Modificar `AutorizacionPagoState.php` — cambiar `getNextStatus()` y comentario**

```php
public function getNextStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 3: Modificar `PagadaState.php`**

```php
public function getPreviousStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 4: Modificar `RefundPipelineStateRegistry.php`**

Añadir `VerificacionPagoState` al constructor y al ciclo, similar a los otros registries.

- [ ] **Step 5: Verificar carga**

Run: `php bin/cake server` y `curl -s http://localhost:8765/ -o /dev/null -w "%{http_code}\n"`
Expected: `200` o `302`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/Refund/
git commit -m "feat(refund): añadir VerificacionPagoState al pipeline"
```

---

### Task 14: Modificar `RefundPaymentService` para desacoplar autorización de pagada

**Files:**
- Modify: `src/Service/RefundPaymentService.php`

- [ ] **Step 1: Modificar el método de autorización — cambiar destino**

Localizar el bloque (alrededor de la línea 301):

```php
$invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
$invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
$invoice->full_payment_date = $record->payment_date;
```

Cambiar la primera línea por `STATUS_VERIFICACION_PAGO`.

Localizar el bloque (alrededor de la línea 318):

```php
$record->status = RefundConstants::STATUS_PAGADA;
```

Cambiar a `RefundConstants::STATUS_VERIFICACION_PAGO`.

Verificar que ningún `InvoicePaidEvent` se dispare en el método de autorización (debería estar fuera o no existir):

Run: `git grep -n "InvoicePaidEvent\|Invoice.paid" src/Service/RefundPaymentService.php`

Si hay dispatch, removerlo (se moverá a `confirmPayment`).

- [ ] **Step 2: Añadir `confirmPayment()` al final de la clase**

Patrón similar al de `PettyCashService::confirmPayment`:

```php
public function confirmPayment(int $refundId, int $confirmedBy): \App\Service\ServiceResult
{
    $refundsTable = TableRegistry::getTableLocator()->get('Refunds');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');

    $record = $refundsTable->get($refundId);
    if ($record->status !== \App\Constants\RefundConstants::STATUS_VERIFICACION_PAGO) {
        return \App\Service\ServiceResult::fail(['El reintegro no está en verificación de pago.']);
    }

    $connection = $refundsTable->getConnection();
    $ok = $connection->transactional(function () use ($refundsTable, $invoicesTable, $record, $confirmedBy) {
        $previousStatus = $record->status;
        $record->status = \App\Constants\RefundConstants::STATUS_PAGADA;
        if (!$refundsTable->save($record)) {
            return false;
        }

        $this->history->recordStatusChange(
            $record->id,
            $previousStatus,
            $record->status,
            $confirmedBy,
        );

        $childInvoices = $invoicesTable->find()
            ->where([
                'refund_id' => $record->id,
                'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
            ])
            ->all();

        foreach ($childInvoices as $invoice) {
            $invoicePreviousStatus = $invoice->pipeline_status;
            $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
            if (!$invoicesTable->save($invoice)) {
                return false;
            }
            $this->invoiceHistory->recordStatusChange(
                $invoice->id,
                $invoicePreviousStatus,
                InvoiceConstants::STATUS_PAGADA,
                $confirmedBy,
            );
            $this->events->dispatch(new \Cake\Event\Event(
                'Invoice.paid',
                null,
                ['payload' => new \App\Event\InvoicePaidEvent($invoice, $confirmedBy)],
            ));
        }

        return true;
    });

    if ($ok === false) {
        return \App\Service\ServiceResult::fail(['No se pudo confirmar el reintegro.']);
    }

    return \App\Service\ServiceResult::ok(['newStatus' => \App\Constants\RefundConstants::STATUS_PAGADA]);
}
```

Verificar nombres reales de propiedades del constructor (`$this->history`, `$this->invoiceHistory`, `$this->events`):

Run: `git grep -n "private.*readonly" src/Service/RefundPaymentService.php | head -10`

Ajustar nombres según lo inyectado. Si falta algún servicio, añadirlo al constructor.

- [ ] **Step 3: Verificar carga**

Run: `composer cs-check src/Service/RefundPaymentService.php`
Expected: sin errores.

- [ ] **Step 4: Commit**

```bash
git add src/Service/RefundPaymentService.php
git commit -m "feat(refund): desacoplar autorización de pagada — añadir confirmPayment"
```

---

### Task 15: Controller, ruta y vista Refund

**Files:**
- Modify: `src/Controller/RefundsController.php`
- Modify: `config/routes.php`
- Modify: `templates/Refunds/view.php`

- [ ] **Step 1: Añadir acción `confirmPayment` al `RefundsController`**

Patrón idéntico a PettyCash:

```php
public function confirmPayment(int $id): \Cake\Http\Response
{
    $this->request->allowMethod(['post']);

    $currentUser = $this->_getCurrentUser();
    $roleName = $currentUser->role->name ?? null;
    if (!in_array($roleName, [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar este pago.');
        return $this->redirect($this->referer());
    }

    $result = $this->paymentService->confirmPayment((int)$id, (int)$currentUser->id);

    if (!$result->success) {
        $this->Flash->error($result->errors[0] ?? 'No se pudo confirmar el reintegro.');
        return $this->redirect($this->referer());
    }

    $this->Flash->success('Pago confirmado. El reintegro y sus facturas quedaron como pagados.');
    return $this->redirect(['action' => 'view', $id]);
}
```

- [ ] **Step 2: Añadir ruta**

Localizar `/refunds/authorize-payment/{id}` (línea 387 según grep previo) y añadir después:

```php
$builder->connect(
    '/refunds/confirm-payment/{id}',
    ['controller' => 'Refunds', 'action' => 'confirmPayment'],
    ['pass' => ['id'], 'id' => '\d+'],
);
```

- [ ] **Step 3: Añadir botón en `templates/Refunds/view.php`**

```php
<?php
use App\Constants\RefundConstants;
use App\Constants\RoleConstants;

$currentRole = $this->getRequest()->getAttribute('identity')->role->name ?? null;
$canConfirm = in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true);
?>
<?php if ($refund->status === RefundConstants::STATUS_VERIFICACION_PAGO && $canConfirm): ?>
    <div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem;">
                El pago fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-cash-coin"></i> Pasar a Pagada',
                ['action' => 'confirmPayment', $refund->id],
                ['class' => 'sgi-btn-primary', 'escape' => false, 'confirm' => '¿Confirmar que el pago ya se ejecutó?']
            ) ?>
        </div>
    </div>
<?php endif; ?>
```

- [ ] **Step 4: Validación manual Refund**

Run: `php bin/cake server`

1. Crear reintegro con varias facturas hijas, llevar a `autorizacion_pago`.
2. Contador autoriza → reintegro y facturas hijas en `verificacion_pago`.
3. Tesorería: ver el reintegro, click "Pasar a Pagada" → ambos en `pagada`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/RefundsController.php config/routes.php templates/Refunds/
git commit -m "feat(refund): acción confirmPayment + ruta + botón en view"
```

---

## Fase 5 — Novelty (LiquidationDoc)

### Task 16: State pattern Novelty

**Files:**
- Create: `src/Service/Pipeline/Novelty/State/VerificacionPagoState.php`
- Modify: `src/Service/Pipeline/Novelty/State/AutorizacionPagoState.php`
- Modify: `src/Service/Pipeline/Novelty/State/PagadaState.php`
- Modify: `src/Service/Pipeline/Novelty/NoveltyPipelineStateRegistry.php`

- [ ] **Step 1: Crear `VerificacionPagoState.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Novelty\State;

use App\Constants\Domain\Novelty\PipelineStatus;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\Service\Pipeline\Novelty\NoveltyPipelineState;

final class VerificacionPagoState implements NoveltyPipelineState
{
    public function getStatus(): PipelineStatus
    {
        return PipelineStatus::VERIFICACION_PAGO;
    }

    public function getNextStatus(): ?PipelineStatus
    {
        return PipelineStatus::PAGADA;
    }

    public function getPreviousStatus(): ?PipelineStatus
    {
        return PipelineStatus::AUTORIZACION_PAGO;
    }

    public function validateAdvanceIndividual(EmployeeNovelty $novelty): array
    {
        return ['La confirmación de pago se gestiona desde el documento de liquidación.'];
    }

    public function validateAdvanceGroup(NoveltyLiquidationDoc $doc): array
    {
        return ['La confirmación de pago se gestiona desde la sección de pagos.'];
    }
}
```

- [ ] **Step 2: Modificar `AutorizacionPagoState.php`**

```php
public function getNextStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 3: Modificar `PagadaState.php`**

```php
public function getPreviousStatus(): ?PipelineStatus
{
    return PipelineStatus::VERIFICACION_PAGO;
}
```

- [ ] **Step 4: Modificar `NoveltyPipelineStateRegistry.php`**

Añadir `VerificacionPagoState` al constructor y al ciclo de inicialización.

- [ ] **Step 5: Verificar carga**

Run: `php bin/cake server` y `curl -s http://localhost:8765/ -o /dev/null -w "%{http_code}\n"`

- [ ] **Step 6: Commit**

```bash
git add src/Service/Pipeline/Novelty/
git commit -m "feat(novelty): añadir VerificacionPagoState al pipeline"
```

---

### Task 17: Modificar `LiquidationDocPaymentService`

**Files:**
- Modify: `src/Service/LiquidationDocPaymentService.php`

- [ ] **Step 1: Modificar `authorizePayment()`**

Localizar el bloque (alrededor de la línea 96-105):

```php
$doc = $docsTable->get($payment->liquidation_doc_id);
$doc->pipeline_status = NoveltyConstants::STATUS_PAGADA;
$doc->payment_status = NoveltyConstants::PAYMENT_PAGADO;
$doc->payment_date = $payment->payment_date;
$docsTable->save($doc);

$noveltiesTable->updateAll(
    ['pipeline_status' => NoveltyConstants::STATUS_PAGADA],
    ['liquidation_doc_id' => $payment->liquidation_doc_id],
);
```

Reemplazar por:

```php
$doc = $docsTable->get($payment->liquidation_doc_id);
$doc->pipeline_status = NoveltyConstants::STATUS_VERIFICACION_PAGO;
$doc->payment_status = NoveltyConstants::PAYMENT_PAGADO;
$doc->payment_date = $payment->payment_date;
$docsTable->save($doc);

$noveltiesTable->updateAll(
    ['pipeline_status' => NoveltyConstants::STATUS_VERIFICACION_PAGO],
    ['liquidation_doc_id' => $payment->liquidation_doc_id],
);
```

Y el return:

```php
return ['success' => true, 'newPipelineStatus' => NoveltyConstants::STATUS_VERIFICACION_PAGO];
```

- [ ] **Step 2: Añadir nuevo método `confirmPayment()` al final de la clase**

```php
/**
 * Confirma que el pago de un documento de liquidación se ejecutó. Avanza
 * doc y sus novedades hijas de verificacion_pago → pagada.
 */
public function confirmPayment(int $docId, int $confirmedBy): \App\Service\ServiceResult
{
    $docsTable = TableRegistry::getTableLocator()->get('NoveltyLiquidationDocs');
    $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');

    $doc = $docsTable->get($docId);
    if ($doc->pipeline_status !== \App\Constants\NoveltyConstants::STATUS_VERIFICACION_PAGO) {
        return \App\Service\ServiceResult::fail(['El documento no está en verificación de pago.']);
    }

    $connection = $docsTable->getConnection();
    $ok = $connection->transactional(function () use ($docsTable, $noveltiesTable, $doc) {
        $doc->pipeline_status = \App\Constants\NoveltyConstants::STATUS_PAGADA;
        if (!$docsTable->save($doc)) {
            return false;
        }

        $noveltiesTable->updateAll(
            ['pipeline_status' => \App\Constants\NoveltyConstants::STATUS_PAGADA],
            [
                'liquidation_doc_id' => $doc->id,
                'pipeline_status' => \App\Constants\NoveltyConstants::STATUS_VERIFICACION_PAGO,
            ],
        );

        return true;
    });

    if ($ok === false) {
        return \App\Service\ServiceResult::fail(['No se pudo confirmar el pago del documento.']);
    }

    return \App\Service\ServiceResult::ok(['newPipelineStatus' => \App\Constants\NoveltyConstants::STATUS_PAGADA]);
}
```

- [ ] **Step 3: Verificar carga**

Run: `composer cs-check src/Service/LiquidationDocPaymentService.php`

- [ ] **Step 4: Commit**

```bash
git add src/Service/LiquidationDocPaymentService.php
git commit -m "feat(novelty): desacoplar autorización de pagada — añadir confirmPayment"
```

---

### Task 18: Controller, ruta y vista Novelty

**Files:**
- Modify: `src/Controller/LiquidationDocPaymentsController.php`
- Modify: `config/routes.php`
- Modify: `templates/NoveltyLiquidationDocs/view.php`

- [ ] **Step 1: Añadir acción `confirmPayment` al `LiquidationDocPaymentsController`**

```php
public function confirmPayment(int $docId): \Cake\Http\Response
{
    $this->request->allowMethod(['post']);

    $currentUser = $this->_getCurrentUser();
    $roleName = $currentUser->role->name ?? null;
    if (!in_array($roleName, [\App\Constants\RoleConstants::TESORERIA, \App\Constants\RoleConstants::ADMIN], true)) {
        $this->Flash->error('No tiene permisos para confirmar este pago.');
        return $this->redirect($this->referer());
    }

    $result = $this->paymentService->confirmPayment((int)$docId, (int)$currentUser->id);

    if (!$result->success) {
        $this->Flash->error($result->errors[0] ?? 'No se pudo confirmar el pago.');
        return $this->redirect($this->referer());
    }

    $this->Flash->success('Pago confirmado. El documento y sus novedades quedaron como pagados.');
    return $this->redirect(['controller' => 'NoveltyLiquidationDocs', 'action' => 'view', $docId]);
}
```

- [ ] **Step 2: Añadir ruta en `config/routes.php`**

Localizar las rutas de liquidation doc payments (buscar `liquidation-doc-payments` o `LiquidationDocPayments`).

Run: `git grep -n "liquidation\|LiquidationDoc" config/routes.php | head -10`

Añadir antes de fallbacks:

```php
$builder->connect(
    '/liquidation-doc-payments/confirm-payment/{docId}',
    ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment'],
    ['pass' => ['docId'], 'docId' => '\d+'],
);
```

Ajustar el path si el módulo usa otra convención (p. ej. `/employee-novelties/liquidation-docs/...`).

- [ ] **Step 3: Añadir botón en `templates/NoveltyLiquidationDocs/view.php`**

```php
<?php
use App\Constants\NoveltyConstants;
use App\Constants\RoleConstants;

$currentRole = $this->getRequest()->getAttribute('identity')->role->name ?? null;
$canConfirm = in_array($currentRole, [RoleConstants::TESORERIA, RoleConstants::ADMIN], true);
?>
<?php if ($noveltyLiquidationDoc->pipeline_status === NoveltyConstants::STATUS_VERIFICACION_PAGO && $canConfirm): ?>
    <div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
        <div class="card-body">
            <p class="mb-2" style="font-size:.9rem;">
                El pago fue autorizado por el Contador. Confirme cuando el dinero haya salido del banco.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-cash-coin"></i> Pasar a Pagada',
                ['controller' => 'LiquidationDocPayments', 'action' => 'confirmPayment', $noveltyLiquidationDoc->id],
                ['class' => 'sgi-btn-primary', 'escape' => false, 'confirm' => '¿Confirmar que el pago ya se ejecutó?']
            ) ?>
        </div>
    </div>
<?php endif; ?>
```

Confirmar nombre real de la variable de la vista. Run: `git grep -n "noveltyLiquidationDoc\|liquidationDoc" templates/NoveltyLiquidationDocs/view.php | head -5`.

- [ ] **Step 4: Validación manual Novelty**

Run: `php bin/cake server`

1. Crear novedades, liquidarlas en un doc de liquidación, avanzar hasta `autorizacion_pago`.
2. Contador autoriza → doc y novedades en `verificacion_pago`.
3. Tesorería abre el doc, click "Pasar a Pagada" → todos en `pagada`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/LiquidationDocPaymentsController.php config/routes.php templates/NoveltyLiquidationDocs/
git commit -m "feat(novelty): acción confirmPayment + ruta + botón en view"
```

---

## Fase 6 — Cierre

### Task 19: Validación cruzada (sidebar, históricos, regresiones)

**Files:** ninguno (solo verificación; commits si se descubren faltantes).

- [ ] **Step 1: Verificar sidebar counters de Tesorería**

Login como Tesorería. Crear al menos un registro en `verificacion_pago` en cada uno de los 5 módulos. En la home (sidebar):

- Verificar que el contador de "Facturas" incluye registros en `verificacion_pago`.
- Verificar que el contador de "Caja Menor" incluye records en `verificacion_pago`.
- Verificar contadores de "Reintegros", "Programaciones" y "Liquidaciones de novedad" (o etiquetas que use el sidebar).

Si algún contador NO incluye `verificacion_pago`, revisar el método `getVisibleStatuses(roleName)` del servicio correspondiente. Asegurar que el nuevo `VerificacionPagoState` declare `RoleConstants::TESORERIA` en `getRoleVisibility()`. Si la lista de estados visibles para Tesorería se construye fuera del State (p. ej. en `NoveltyService::getVisibleLiquidationStatuses` o similar), añadir `STATUS_VERIFICACION_PAGO` allí también.

Run: `git grep -n "getVisibleLiquidationStatuses\|getVisibleStatuses" src/Service/`

- [ ] **Step 2: Verificar históricos**

Para cada módulo, abrir un registro recién pasado a `pagada`. Revisar la pestaña/sección de historial:

- Debe mostrar 2 transiciones nuevas: `autorizacion_pago → verificacion_pago` (autor: Contador) y `verificacion_pago → pagada` (autor: Tesorería).
- Si alguna falta, revisar el `confirmPayment*` del servicio correspondiente y asegurar que llame a `historyService->recordStatusChange()`.

- [ ] **Step 3: Verificar regresiones en módulos agrupados**

En PaymentScheduling, PettyCash, Refund: con un registro en `verificacion_pago`, intentar regresarlo a `autorizacion_pago` desde la UI (botón de regresión existente con motivo).

Expected: la regresión funciona, registra observación de regresión, y el registro vuelve a `autorizacion_pago`.

Si falla, revisar `BACKWARD_TRANSITIONS` del Constants (`PaymentSchedulingConstants` o `GroupingPipelineConstantsTrait`) y verificar que `verificacion_pago → autorizacion_pago` esté declarado.

- [ ] **Step 4: Verificar que no se puede regresar desde `pagada`**

Intentar regresar un registro recién pasado a `pagada` → debe fallar con mensaje de "estado terminal" o similar.

- [ ] **Step 5: Verificar registros históricos no afectados**

Buscar 1-2 registros que ya estaban en `pagada` antes del despliegue (anteriores a la migración):

- En la vista, el pipeline progress debe mostrar 6 pasos con el último en `pagada` (verde).
- En index, el badge debe seguir siendo "Pagada".
- No deben aparecer en colas de "verificación pendiente".

- [ ] **Step 6: Verificar pipeline_progress.php muestra 6 pasos**

Abrir vistas de cada módulo en distintos estados (`tesoreria`, `autorizacion_pago`, `verificacion_pago`, `pagada`). Confirmar visualmente que el stepper renderiza 6 pasos con el icono correcto en `verificacion_pago` (`bi-hourglass-split`).

Si algún element de progress (`templates/element/petty_cash_progress.php`, `templates/element/refund_progress.php`) no recibe los estados actualizados, revisar el controller que pasa la variable `$pipelineStatuses` o `$pipelineLabels` y asegurar que use las constantes actualizadas.

- [ ] **Step 7: Si se descubren bugs, hacer commits aislados**

Cada fix de validación cruzada va en su propio commit con prefijo `fix(<modulo>):` describiendo el síntoma observado.

- [ ] **Step 8: Commit final del plan completo**

Si todo pasó sin nuevos commits, hacer un commit vacío de cierre (opcional):

```bash
git commit --allow-empty -m "chore: cierre del feature verificacion_pago — validación cruzada OK"
```

---

## Self-review — gaps detectados y corregidos

- **Cobertura del spec:** todas las secciones del spec están mapeadas a tareas (enums → Task 1; constants → Task 2; presentation → Task 3; states + services + controllers + vistas por módulo → Tasks 4-18; sidebar/históricos/regresiones → Task 19). El no-objetivo "Advances queda fuera" se respeta porque ningún task lo toca.
- **Migración de schema:** verificada como NO necesaria porque las columnas son `VARCHAR/string` (verificado en `config/Migrations/20260219000007_CreateInvoices.php:101` y `20260313000002_ModifyEmployeeNoveltiesForPipeline.php:11`). Spec menciona "verificar antes" — esa verificación ya quedó documentada en el header del plan.
- **Eventos diferidos:** cada `confirmPayment*` dispara `InvoicePaidEvent`; el evento ya NO se dispara en `authorizePayment*`. Cubre el "punto crítico" del spec.
- **Pago parcial Invoices:** la modificación de `InvoicePaymentService::authorizePayment()` mantiene `STATUS_TESORERIA` para parciales (Task 5, Step 1), respetando "sin cambios" del spec.
- **Granularidad agrupados:** todos los `confirmPayment*` de módulos agrupados (Scheduling, PettyCash, Refund, Novelty) operan a nivel del record padre y propagan a hijas en una transacción.
- **Permisos:** todos los controllers verifican `RoleConstants::TESORERIA` y `RoleConstants::ADMIN` (no `ADMINISTRADOR`).
- **No placeholders:** todos los steps incluyen el código completo o el comando exacto. Donde el plan indica "verificar nombre real con git grep" es porque el caller del implementador podría no saber el nombre exacto de una propiedad/variable; en cada caso se da el comando exacto a ejecutar y el patrón a buscar.
- **Validación manual:** cada módulo tiene su escenario de validación inmediatamente después de la implementación, no al final.
