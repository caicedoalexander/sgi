# Diseño — Pilot S1: `InvoicePipelineStatus` enum

**Fecha:** 2026-05-07
**Auditoría base:** [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md) — sugerencias S1 + S3
**Alcance:** pilot acotado a un solo pipeline (facturas). Establece el patrón `src/Constants/Domain/{Module}/` y el uso de enums tipados en el núcleo del state machine.

---

## 1. Contexto y alcance

La auditoría sugería S1 (migrar a enums PHP 8.1+) empezando por `InvoicePipelineStatus` (~196 ocurrencias en 50 archivos). Migrar 196 call-sites en un solo PR es inviable y de alto riesgo de regresión.

Este pilot ejecuta S1 en su forma **incremental y compatible**:

- Crea el enum y lo establece como fuente única.
- `InvoiceConstants::STATUS_*` queda como **alias** delegado al enum.
- Migra firmas únicamente en el **núcleo del state machine** (donde el type safety previene bugs reales).
- Templates, controllers, services periféricos siguen usando strings vía `InvoiceConstants` — sin tocar.

S3 se ejecuta en su forma mínima: se crea `src/Constants/Domain/Invoice/` para alojar el enum. Se descarta la subdivisión completa de `Constants/` (no aporta valor con un archivo por módulo, ya que el enum encapsula label/next/isTerminal en sí mismo).

**Out of scope:**

- Otros 5 pipelines (Novelty, PettyCash, Refund, Advance, PaymentScheduling). Se evalúan **después** del pilot.
- Migrar templates, controllers, ViewModels, payment/approval/history services a enum tipado.
- Cambiar la columna `invoices.pipeline_status` (sigue siendo VARCHAR; el enum es solo capa de código).
- Constantes de `InvoiceConstants` ajenas al pipeline (DOCTYPE_*, APPROVAL_*, DIAN_*, PAYMENT_*, READY_FOR_PAYMENT_*, HOLDER_*, OBSERVATION_*).

---

## 2. Decisiones tomadas

| Pregunta | Decisión | Razón |
|---|---|---|
| ¿Combinable S1 + S3? | Sí, en forma mínima | Crear `Domain/Invoice/` para alojar el enum. No subdividir el resto de `Constants/`. |
| ¿Big bang o pilot? | Pilot (1 pipeline) | Evaluar costo/beneficio antes de gastar en los otros 5. |
| ¿Dónde mantener `label()`? | En el enum | Vocabulario de dominio (logs, emails, audit). Icon queda en `InvoicePresentation`. |
| ¿Backward compat? | Sí, total. `InvoiceConstants::STATUS_*` delega al enum. | Evita migrar 196 call-sites en un PR. |
| ¿Arrays derivados o literales? | Literales | Más simple. Drift mínimo (M3 ya canonicalizó labels hace 1 día). |
| ¿Hasta dónde migrar firmas? | `Service/Pipeline/`, `InvoicePipelineService`, `InvoiceFieldAccessPolicy` | Donde un typo rompe transiciones silenciosamente. |

---

## 3. Diseño detallado

### 3.1 Enum nuevo

**Archivo:** `src/Constants/Domain/Invoice/PipelineStatus.php`

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
    case PAGADA = 'pagada';
    case LEGALIZADA = 'legalizada';

    public function label(): string
    {
        return match ($this) {
            self::APROBACION        => 'Aprobación',
            self::CONTABILIDAD      => 'Contabilidad',
            self::TESORERIA         => 'Tesorería',
            self::AUTORIZACION_PAGO => 'Autorización de pago',
            self::PAGADA            => 'Pagada',
            self::LEGALIZADA        => 'Legalizada',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::APROBACION        => self::CONTABILIDAD,
            self::CONTABILIDAD      => self::TESORERIA,
            self::TESORERIA         => self::AUTORIZACION_PAGO,
            self::AUTORIZACION_PAGO => self::PAGADA,
            self::PAGADA, self::LEGALIZADA => null,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::PAGADA || $this === self::LEGALIZADA;
    }

    /** Estados del flujo normal (excluye LEGALIZADA, terminal alterno). */
    public static function pipelineCases(): array
    {
        return [
            self::APROBACION,
            self::CONTABILIDAD,
            self::TESORERIA,
            self::AUTORIZACION_PAGO,
            self::PAGADA,
        ];
    }

    /** Pipeline visual exclusivo de Legalizaciones (3 pasos). */
    public static function legalizationCases(): array
    {
        return [self::APROBACION, self::CONTABILIDAD, self::LEGALIZADA];
    }
}
```

**Observaciones:**

- `BACKWARD_TRANSITIONS` no se modela en el enum: el rollback `autorizacion_pago → tesoreria` por pago parcial es regla de negocio dependiente de payment state, no transición simétrica. Vive en `InvoicePipelineService`.
- `icon()` no existe en el enum: la presentación está en `src/View/Presentation/InvoicePresentation::STATUS_ICONS` (extracción M1+M2).

### 3.2 `InvoiceConstants` — backward compat

```php
use App\Constants\Domain\Invoice\PipelineStatus;

final class InvoiceConstants
{
    // ... (DOCTYPE_*, APPROVAL_*, DIAN_*, etc. — sin cambios)

    // Pipeline statuses → delegados al enum
    public const STATUS_APROBACION        = PipelineStatus::APROBACION->value;
    public const STATUS_CONTABILIDAD      = PipelineStatus::CONTABILIDAD->value;
    public const STATUS_TESORERIA         = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_PAGADA            = PipelineStatus::PAGADA->value;
    public const STATUS_LEGALIZADA        = PipelineStatus::LEGALIZADA->value;

    // Arrays — siguen siendo arrays de strings (API idéntica a hoy)
    public const PIPELINE_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA,
    ];

    public const ALL_STATUSES = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_PAGADA,
        self::STATUS_LEGALIZADA,
    ];

    public const PIPELINE_STATUSES_LEGALIZACION = [
        self::STATUS_APROBACION,
        self::STATUS_CONTABILIDAD,
        self::STATUS_LEGALIZADA,
    ];

    public const TRANSITIONS = [
        self::STATUS_APROBACION        => self::STATUS_CONTABILIDAD,
        self::STATUS_CONTABILIDAD      => self::STATUS_TESORERIA,
        self::STATUS_TESORERIA         => self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_AUTORIZACION_PAGO => self::STATUS_PAGADA,
        self::STATUS_PAGADA            => null,
        self::STATUS_LEGALIZADA        => null,
    ];

    public const STATUS_LABELS = [
        self::STATUS_APROBACION        => 'Aprobación',
        self::STATUS_CONTABILIDAD      => 'Contabilidad',
        self::STATUS_TESORERIA         => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_PAGADA            => 'Pagada',
        self::STATUS_LEGALIZADA        => 'Legalizada',
    ];

    // ... (PAYMENT_*, READY_FOR_PAYMENT_*, etc. — sin cambios)
}
```

**Plan B si `Enum::CASE->value` falla en const:** dejar literales `'aprobacion'`, etc., con comentario `// debe coincidir con PipelineStatus::APROBACION->value`. Drift bajo, M3 ya canonicalizó hace 1 día.

### 3.3 Migración de firmas — núcleo

**Archivos en alcance:**

```
src/Service/InvoicePipelineService.php
src/Service/InvoiceFieldAccessPolicy.php
src/Service/Pipeline/InvoicePipelineState.php
src/Service/Pipeline/InvoicePipelineStateRegistry.php
src/Service/Pipeline/State/AprobacionState.php
src/Service/Pipeline/State/ContabilidadState.php
src/Service/Pipeline/State/TesoreriaState.php
src/Service/Pipeline/State/AutorizacionPagoState.php
src/Service/Pipeline/State/PagadaState.php
src/Service/Pipeline/State/LegalizadaState.php
```

**Patrón de migración por archivo:**

```php
// Antes
public function canTransition(Invoice $invoice, string $toStatus): bool { ... }

// Después
public function canTransition(Invoice $invoice, PipelineStatus $toStatus): bool { ... }
```

**Gateway (frontera string→enum):** los métodos públicos que reciben strings desde HTTP deben normalizar una sola vez:

```php
public function advance(Invoice $invoice, string $toStatus): ServiceResult
{
    $target = PipelineStatus::tryFrom($toStatus);
    if ($target === null) {
        return ServiceResult::fail(['status' => "Estado inválido: {$toStatus}"]);
    }
    return $this->advanceTo($invoice, $target);
}

private function advanceTo(Invoice $invoice, PipelineStatus $target): ServiceResult
{
    // toda la lógica interna ya con enum
}
```

**`InvoiceFieldAccessPolicy`:** sus métodos `getEditableFields(string $role, string $status)` y `getVisibleSections(string $role, string $status)` migran a `PipelineStatus $status`. Los callers (controllers, templates) seguirán pasando strings — adaptación en el gateway:

```php
public function getEditableFields(string $role, string $status): array
{
    $statusEnum = PipelineStatus::tryFrom($status);
    if ($statusEnum === null) {
        return [];  // estado inválido = sin campos editables (fail-safe)
    }
    return $this->resolveFields($role, $statusEnum);
}
```

### 3.4 Lo que NO se toca

| Archivo/capa | Razón |
|---|---|
| `templates/Invoices/*.php` | Acceden a `$invoice->pipeline_status` como string. Sin valor migrar. |
| `templates/element/pipeline_progress.php` | Idem. |
| `src/Controller/InvoicesController.php` | Recibe strings de request; pasa al service que normaliza. |
| `src/Controller/InvoicePaymentsController.php` | Idem. |
| `src/Controller/ExternalApprovalsController.php` | Idem. |
| `src/Model/Entity/Invoice.php` | `$pipeline_status` es columna VARCHAR. |
| `src/Model/Table/InvoicesTable.php` | Validación con `inList(InvoiceConstants::ALL_STATUSES)` sigue funcionando. |
| `src/ViewModel/InvoiceAddViewModel.php`, `AdvanceAddViewModel.php` | Strings. |
| `InvoiceApprovalService`, `InvoicePaymentService`, `InvoiceHistoryService`, `GroupedInvoiceService`, `RefundService`, `RefundPaymentService`, `PettyCashService`, `PaymentSchedulingService`, `PaymentSchedulingImportService`, `AdvanceLegalizationService`, `SidebarCounterService`, `Dashboard/InvoiceStatisticsService` | Periféricos. Siguen leyendo `InvoiceConstants::STATUS_*` (strings). |
| `Service/Pipeline/Policy/*` | Strings. Son políticas de tipo de documento, no de estado. |
| `Service/Pipeline/LinkedInvoiceLegalizer` | String. |
| `PipelineStepConstants` | Independiente del enum. Sigue como está. |

---

## 4. Validación manual

No hay tests automatizados (ver `CLAUDE.md` "Testing Policy"). Validación 100% manual.

### 4.1 Pre-commit

1. `composer cs-check` limpio.
2. **Grep de sanidad antes de commit:**
   - En archivos migrados: `=== 'aprobacion'`, `=== 'contabilidad'`, etc. (comparaciones por string deben haberse reemplazado por `=== PipelineStatus::APROBACION`).
   - En archivos NO migrados: `InvoiceConstants::STATUS_*` debe seguir resolviendo idéntico (no hubo cambio de valor).

### 4.2 Smoke test funcional

`php bin/cake server` — recorrer:

**Pipeline normal de facturas (todas las transiciones):**

1. Login Registro/Revisión → crear factura → estado inicial `aprobacion`. ✓
2. Aprobar externamente (link) → avanza a `contabilidad`. ✓
3. Login Contabilidad → completar campos contables → avanza a `tesoreria`. ✓
4. Login Tesorería → registrar pago vía `InvoicePaymentService` → avanza a `autorizacion_pago`. ✓
5. Login Contador → autorizar todos los pagos → avanza a `pagada`. ✓
6. Verificar pagada redirige a `view` para no-admins. ✓

**Regresiones por pago parcial:**

7. Crear factura, llevar a `autorizacion_pago`, registrar pago parcial → debe regresar a `tesoreria`. ✓

**Pipeline de Legalización:**

8. Crear factura `document_type = Legalización` → recorrer hasta `legalizada`. ✓ (terminal alterno, no pasa por tesoreria/autorizacion_pago/pagada).

**Rechazo:**

9. Marcar `area_approval = 'Rechazada'` → no se puede avanzar. ✓
10. Login Registro/Revisión → `resetFlow` → factura vuelve a `aprobacion`. ✓

**Field access policy (M crítico):**

11. Abrir `edit` con cada rol en cada estado del pipeline:
    - Admin: ve todo. ✓
    - Contabilidad: ve `general+dates+classification+accounting` en `aprobacion`+`contabilidad`. ✓
    - Tesorería: ve `general+treasury` en `tesoreria`. ✓
    - Contador: ve `payment_authorization` en `autorizacion_pago`. ✓
    - Registro/Revisión: ve `general+dates+classification+revision` en `aprobacion`. ✓

**Validación de borde:**

12. Forzar `?status=invalid` en URL de avance → `ServiceResult::fail(['status' => ...])`. ✓ (gateway captura).

### 4.3 Consultas DB

```sql
SELECT DISTINCT pipeline_status FROM invoices;
-- Debe retornar solo: aprobacion, contabilidad, tesoreria, autorizacion_pago, pagada, legalizada
```

---

## 5. Riesgos y mitigaciones

| Riesgo | Probabilidad | Mitigación |
|---|---|---|
| `Enum::CASE->value` no compila en const de clase (PHP <8.1.x edge cases) | Baja | Plan B: literales con comentario referenciando enum. |
| `tryFrom()` retorna null en lugar correcto del flujo | Baja | Gateway en métodos públicos del service; fail-safe en `InvoiceFieldAccessPolicy`. |
| Algún state class compara con `=== 'aprobacion'` en lugar de usar el enum | Media | Grep `=== '` y `== '` en archivos migrados antes de commit. |
| Drift entre `STATUS_LABELS` (array literal) y `PipelineStatus::label()` (match) | Baja | M3 ya canonicalizó. Cualquier cambio futuro requiere tocar ambos. Comentario explícito en `STATUS_LABELS`. |
| Algún service periférico hace `instanceof PipelineStatus` por error | Muy baja | Out of scope; siguen recibiendo strings. |
| `PipelineStepConstants` desincronizado | Baja | Independiente; M3 ya unificó labels. |

---

## 6. Entregable

**Archivos creados:**

- `src/Constants/Domain/Invoice/PipelineStatus.php`

**Archivos modificados:**

- `src/Constants/InvoiceConstants.php`
- `src/Service/InvoicePipelineService.php`
- `src/Service/InvoiceFieldAccessPolicy.php`
- `src/Service/Pipeline/InvoicePipelineState.php`
- `src/Service/Pipeline/InvoicePipelineStateRegistry.php`
- `src/Service/Pipeline/State/AprobacionState.php`
- `src/Service/Pipeline/State/ContabilidadState.php`
- `src/Service/Pipeline/State/TesoreriaState.php`
- `src/Service/Pipeline/State/AutorizacionPagoState.php`
- `src/Service/Pipeline/State/PagadaState.php`
- `src/Service/Pipeline/State/LegalizadaState.php`

**Total:** 1 archivo nuevo + 11 modificados. ~10 archivos con cambios sustantivos.

**Commit:** `refactor(invoices): introduce InvoicePipelineStatus enum for pipeline state machine`

**Branch:** `refactor/invoice-pipeline-status-enum` desde `main`.

---

## 7. Criterio de éxito y decisión post-pilot

El pilot se considera exitoso si:

1. ✅ Todos los smoke tests funcionales pasan.
2. ✅ `composer cs-check` limpio.
3. ✅ Diff total < 400 líneas (excluyendo el nuevo enum).
4. ✅ Cero regresión en field access policy (visualmente idéntico a hoy).
5. ✅ Gateway maneja correctamente status inválido.

**Tras merge, decisión sobre los otros 5 pipelines:**

| Resultado del pilot | Acción |
|---|---|
| Bajo costo, alto valor (claridad en state machine) | Replicar en Novelty + PaymentScheduling (los más complejos). |
| Costo alto, valor difuso | Descartar S1 para los otros pipelines; cerrar auditoría. |
| Bugs encontrados durante migración | Reabrir auditoría de pipeline state machines en general. |

**Cuándo NO replicar:** si tras el pilot los call-sites siguen pasando strings y el enum se siente como wrapper estético, no replicar. El valor de S1 está en el type safety del núcleo, no en el directorio.

---

## 8. Estrategia de ramas

```
main
 └─ refactor/invoice-pipeline-status-enum   ← 1 PR
```

Sin worktree (el PR es acotado y no compite con otro trabajo).

Tras merge: actualizar [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md) marcando S1 (parcial, pilot Invoice) y S3 (parcial, `Domain/Invoice/`) como ✅ Resuelto con referencia a este diseño y al PR.
