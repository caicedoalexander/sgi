# Diseño — C2 · Trait `GroupingPipelineConstants`

**Fecha:** 2026-05-06
**Auditoría origen:** [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md) — hallazgo **C2**
**Alcance:** B (resolver C2 + dejar puerta abierta a C3 sin migrar los otros 4 pipelines)

---

## Contexto

Tras resolver C1 (unificación léxica `aut_pago` → `autorizacion_pago`, `pagado` → `pagada`), `PettyCashConstants` y `RefundConstants` quedaron como clones byte-a-byte en el ~95% de su contenido. Las únicas divergencias reales son:

| Constante | PettyCash | Refund |
|---|---|---|
| `STATUS_BADGES` | ✅ presente | ❌ ausente (usa `StatusColorConstants::PIPELINE_STATUS_BADGES`) |
| `CODE_PREFIX` | `'CM'` | `'REI'` |
| `BENEFICIARY_TYPE_*` | ❌ | ✅ exclusivo |

El resto (`STATUSES`, `STATUS_LABELS`, `STATUS_ICONS`, `TRANSITIONS`, `BACKWARD_TRANSITIONS`, `OBSERVATION_TYPE_*`) es idéntico.

---

## Decisiones de diseño

### D1 · Mecanismo: trait, no abstract class

Se elige un **trait** sobre una abstract class porque:

- Cero cambios en call sites: las constantes del trait se inyectan en la clase consumidora; `PettyCashConstants::STATUS_TESORERIA` y `RefundConstants::STATUSES` siguen accesibles bajo el mismo nombre.
- Mantiene `final class` en ambas clases.
- Si en C3 evolucionamos hacia un `PipelineDefinition` value object con interface, un trait se descarta limpiamente. Una abstract class sería deuda transicional.
- No hay polimorfismo en juego — herencia sería ceremonia innecesaria.

### D2 · Ubicación: `src/Constants/Concerns/`

Sub-namespace estándar para traits. Mantiene `src/Constants/` plano para los archivos públicos del dominio.

### D3 · Contenido del trait

Todo lo que es idéntico entre los dos archivos. Lo divergente queda en cada clase final.

### D4 · Lo que NO va al trait

- `CODE_PREFIX` (diverge).
- `STATUS_BADGES` (asimetría histórica entre PettyCash y Refund; M2 separado, fuera de scope).
- `BENEFICIARY_TYPE_*` (exclusivo de Refund).

---

## Estructura final

### Trait — `src/Constants/Concerns/GroupingPipelineConstants.php`

```php
<?php
declare(strict_types=1);

namespace App\Constants\Concerns;

/**
 * Constantes comunes de pipelines de "agrupación de pagos" (PettyCash, Refund).
 *
 * Flujo: agrupacion → contabilidad → tesoreria → autorizacion_pago → pagada.
 * `pagada` es terminal; la regresión hacia atrás se permite hasta `tesoreria`
 * (no desde `pagada`, porque la autorización ya materializó pagos en las
 * facturas hijas).
 */
trait GroupingPipelineConstants
{
    public const STATUS_AGRUPACION         = 'agrupacion';
    public const STATUS_CONTABILIDAD       = 'contabilidad';
    public const STATUS_TESORERIA          = 'tesoreria';
    public const STATUS_AUTORIZACION_PAGO  = 'autorizacion_pago';
    public const STATUS_PAGADA             = 'pagada';

    public const STATUSES = [
        self::STATUS_AGRUPACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_AGRUPACION        => 'Agrupación',
        self::STATUS_CONTABILIDAD      => 'Contabilidad',
        self::STATUS_TESORERIA         => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
        self::STATUS_PAGADA            => 'Pagada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_AGRUPACION        => 'bi-collection',
        self::STATUS_CONTABILIDAD      => 'bi-calculator',
        self::STATUS_TESORERIA         => 'bi-bank',
        self::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        self::STATUS_PAGADA            => 'bi-cash-coin',
    ];

    public const TRANSITIONS = [
        self::STATUS_AGRUPACION        => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD      => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA         => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA            => null,
    ];

    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION        => null,
        self::STATUS_CONTABILIDAD      => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA         => self::STATUS_CONTABILIDAD,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_TESORERIA,
        self::STATUS_PAGADA            => null,
    ];

    public const OBSERVATION_TYPE_GENERAL    = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];
}
```

### Clases finales

```php
// src/Constants/PettyCashConstants.php
final class PettyCashConstants
{
    use GroupingPipelineConstants;

    public const CODE_PREFIX = 'CM';

    public const STATUS_BADGES = [
        self::STATUS_AGRUPACION        => 'bg-info text-dark',
        self::STATUS_CONTABILIDAD      => 'bg-primary',
        self::STATUS_TESORERIA         => 'bg-warning text-dark',
        self::STATUS_AUTORIZACION_PAGO => 'bg-info',
        self::STATUS_PAGADA            => 'bg-success',
    ];
}
```

```php
// src/Constants/RefundConstants.php
final class RefundConstants
{
    use GroupingPipelineConstants;

    public const CODE_PREFIX = 'REI';

    public const BENEFICIARY_TYPE_EMPLOYEE = 'employee';
    public const BENEFICIARY_TYPE_PROVIDER = 'provider';

    public const BENEFICIARY_TYPES = [
        self::BENEFICIARY_TYPE_EMPLOYEE,
        self::BENEFICIARY_TYPE_PROVIDER,
    ];

    public const BENEFICIARY_TYPES_LABELS = [
        self::BENEFICIARY_TYPE_EMPLOYEE => 'Empleado',
        self::BENEFICIARY_TYPE_PROVIDER => 'Proveedor',
    ];
}
```

---

## API pública (sin cambios)

Cualquier referencia existente del tipo:

```php
PettyCashConstants::STATUS_TESORERIA
PettyCashConstants::STATUSES
PettyCashConstants::STATUS_LABELS
PettyCashConstants::OBSERVATION_TYPE_GENERAL
RefundConstants::TRANSITIONS
RefundConstants::BACKWARD_TRANSITIONS
RefundConstants::BENEFICIARY_TYPE_EMPLOYEE
```

sigue resolviendo idéntico. Las constantes del trait se "inyectan" en la clase consumidora en tiempo de compilación (PHP 8.2+ ya lo soporta y el proyecto corre PHP 8.2+).

---

## Métricas

| Archivo | Antes | Después |
|---|---:|---:|
| `PettyCashConstants.php` | 74 | ~18 |
| `RefundConstants.php` | 77 | ~28 |
| `Concerns/GroupingPipelineConstants.php` | — | ~50 |
| **Total** | **151** | **~96** |

---

## Plan de implementación

1. Crear `src/Constants/Concerns/GroupingPipelineConstants.php`.
2. Reescribir `PettyCashConstants` con `use GroupingPipelineConstants;` y solo sus extras (`CODE_PREFIX`, `STATUS_BADGES`).
3. Reescribir `RefundConstants` con `use GroupingPipelineConstants;` y solo sus extras (`CODE_PREFIX`, `BENEFICIARY_TYPE_*`).
4. `composer cs-check` para validar PSR-12.
5. Verificar referencias en código vía `grep` — todas deben seguir resolviendo.
6. Actualizar tabla de estado en `docs/audits/constants-structure-audit-2026-05-06.md`: marcar C2 como ✅ Resuelto.

---

## Validación manual

Siguiendo la Testing Policy del proyecto (sin tests automatizados), validar tras el merge:

| Ruta | Qué verificar |
|---|---|
| `/petty-cash-records` (index) | Tabla carga; badges de estado (`STATUS_BADGES`) correctos; filtro por estado |
| `/petty-cash-records/view/{id}` | Pipeline progress; status label; observaciones tipo `general`/`regression` |
| `/petty-cash-records/edit/{id}` | Avance de cada transición forward (agrupacion→contabilidad→tesoreria→autorizacion_pago→pagada); regresión via `BACKWARD_TRANSITIONS` |
| `/refunds` (index) | Tabla con badges (vía `StatusColorConstants::PIPELINE_STATUS_BADGES`); códigos `REI-…` |
| `/refunds/view/{id}` | Beneficiary type label (Empleado/Proveedor) |
| `/refunds/edit/{id}` | Mismo flujo de transiciones que petty cash |
| Crear nuevo `Refund` y nuevo `PettyCashRecord` | `CODE_PREFIX` correcto en cada uno (`REI-…` vs `CM-…`) |

---

## Riesgo y rollback

- **Riesgo:** muy bajo. Cambio puramente sintáctico — los valores de cada constante son byte-idénticos antes y después.
- **Rollback:** revertir el commit. Sin cambios de schema, sin cambios de API HTTP, sin migración de datos.

---

## Fuera de scope (deuda separada)

- **M1 / M2** — separación dominio vs presentación; god-array `PIPELINE_STATUS_BADGES`.
- **C3** — abstracción común para los otros 4 pipelines (Invoice, Novelty, PaymentScheduling, Advance).
- **m6** — el trait elimina la duplicación de `OBSERVATION_TYPE_*` entre PettyCash y Refund. Los duplicados restantes en Invoice/PaymentScheduling se atacan por separado.
