# Auditoría — Estructura de Constantes (`src/Constants/`)

**Fecha:** 2026-05-06
**Alcance:** 14 archivos · 872 líneas · usados en 30+ archivos de `src/` y 18+ templates
**Modo:** Auditoría arquitectónica del directorio (sin diff de rama)
**Verdicto global:** ⚠️ **REQUEST CHANGES** — diseño funcional, pero con duplicación estructural severa y mezcla de capas

## Estado de remediación

| Hallazgo | Estado | Resuelto en |
|---|---|---|
| **C1** Inconsistencia léxica `aut_pago`/`pagado` vs `autorizacion_pago`/`pagada` | ✅ Resuelto | Migración `20260506180000_UnifyCanonicalPipelineStatuses` (2026-05-06) |
| **C2** Duplicación PettyCashConstants ≈ RefundConstants | ✅ Resuelto | Trait `GroupingPipelineConstantsTrait` (2026-05-06) |
| **C3** Esquema repetido en 6 *PipelineConstants sin contrato común | ⚠️ No procede | Re-evaluado 2026-05-06 — ver Notas C3 |
| **M1** Mezcla dominio/presentación en StatusColorConstants | ✅ Resuelto | PR Presentation extraction (2026-05-06) — ver Notas M1+M2 |
| **M2** God-array `PIPELINE_STATUS_BADGES` cross-domain | ✅ Resuelto | PR Presentation extraction (2026-05-06) — ver Notas M1+M2 |
| **M3** Drift `Aut. Pago` vs `Autorización de pago` en STATUS_LABELS | ✅ Resuelto | PR `91f78dd` — canonicalización a forma larga (2026-05-06) |
| **M4** `EmployeeStatusConstants` acopla código a IDs de seed | ✅ Resuelto | Migración `20260506213953_ReplaceEmployeeStatusesTableWithStatusColumn` + PR `1c53032` (2026-05-06) |
| **M5** Inconsistencia estilo `self::*` vs literal en PettyCash/Refund | ✅ Resuelto | Reescritura de constants al resolver C1 |
| **M6** Duplicación `ALL_STATUSES`/`TRANSITIONS` Service vs Constants | ✅ Resuelto | PR `f4fd900` — movidas a `InvoiceConstants` (2026-05-06) |
| Menores **m6** (`OBSERVATION_TYPE_*` repetido) | ✅ Resuelto | PR `f4fd900` — promovido a `ObservationConstants::TYPE_*` (2026-05-06) |
| Menores **m2** (orden de declaración en `InvoiceConstants`) | ✅ Resuelto | Quick wins PR (2026-05-06) — ver `docs/plans/2026-05-06-constants-quick-wins-design.md` |
| Menores **m3** (`DIAN_STATUSES` literal `'Pendiente'`) | ✅ Resuelto | Quick wins PR (2026-05-06) — `DIAN_PENDING` extraído como constante |
| Menores **m4** (`READY_FOR_PAYMENT_OPTIONS` magic strings) | ✅ Resuelto | Quick wins PR (2026-05-06) — 3 constantes simbólicas + referencias en `READY_FOR_PAYMENT_BADGES` y templates |
| Menores **m5** (`NoveltyConstants::ACTIVE_STATUSES` semántica ambigua) | ✅ Resuelto | Quick wins PR (2026-05-06) — PHPDoc explicando exclusiones |
| Menores **m7** (`PipelineStepConstants::isValid()` estático) | 📌 Descartado | Decisión 2026-05-06: dejar donde está; mover sería over-engineering por purismo |
| Menores restantes (m1) y sugerencias (S1–S3) | ⏳ Pendientes | Postergados — ver `docs/plans/2026-05-06-constants-audit-plan-a-design.md` §4 |

### Notas C1 (2026-05-06)

- Slugs canónicos: `autorizacion_pago` y `pagada` para todos los pipelines.
- DB: 6 tablas actualizadas + `pipeline_permissions.step` + labels en `petty_cash_histories` / `refund_histories` (`Pagado` → `Pagada`).
- Código: 4 Constants files reescritos, 6 state classes renombradas (`AutPagoState` → `AutorizacionPagoState`, `PagadoState` → `PagadaState`) en Refund/PettyCash/Novelty/PaymentScheduling, 4 registries actualizados, ~25 services/controllers ajustados, 11 templates limpiados.
- API de entidades: `Refund::isPagado/isAutPago` y `PettyCashRecord::isPagado/isAutPago` renombrados a `isPagada/isAutorizacionPago`.
- Aliases redundantes en `StatusColorConstants::PIPELINE_STATUS_BADGES` eliminados.

### Notas C2 (2026-05-06)

- Diseño: [`docs/plans/2026-05-06-c2-grouping-pipeline-trait-design.md`](../plans/2026-05-06-c2-grouping-pipeline-trait-design.md).
- Nuevo trait `App\Constants\Concerns\GroupingPipelineConstantsTrait` con las 13 constantes idénticas (`STATUS_*`, `STATUSES`, `STATUS_LABELS`, `STATUS_ICONS`, `TRANSITIONS`, `BACKWARD_TRANSITIONS`, `OBSERVATION_TYPE_*`, `OBSERVATION_TYPES`).
- `PettyCashConstants` (74 → 19 LoC) y `RefundConstants` (77 → 28 LoC) hacen `use GroupingPipelineConstantsTrait;` y solo conservan lo específico (`CODE_PREFIX`, `STATUS_BADGES` en PettyCash, `BENEFICIARY_TYPE_*` en Refund).
- API pública sin cambios: 209 referencias en 37 archivos siguen resolviendo idéntico (verificado en runtime).
- Decisión: trait sobre abstract class para evitar deuda transicional si C3 evoluciona a `PipelineDefinition` value object.

### Notas C3 (re-evaluación 2026-05-06)

Tras inspeccionar los 6 archivos `*PipelineConstants` se concluye que **C3 no procede como refactor crítico**. El hallazgo del auditor sobreestima la duplicación: lo que comparten es la *forma* (estados + labels + transiciones), no el *contenido*, y la forma diverge por razones legítimas de dominio.

**Evidencia contra el refactor C3:**

| Pipeline | Particularidad de dominio que rompe la "forma común" |
|---|---|
| `InvoiceConstants` | `STATUS_LEGALIZADA` es estado terminal *fuera* de `PIPELINE_STATUSES` — solo aplica a `document_type=Legalización`. Es bifurcación real del flujo. |
| `NoveltyConstants` | 10 estados, `STATUS_RECHAZADA` terminal, `ACTIVE_STATUSES` (subset semántico), `NOVELTY_STATUSES` (sub-pipeline antes del liquidation doc) |
| `PaymentSchedulingConstants` | `REJECTION_TARGET` (estado al que regresa cuando Contador rechaza) — lógica única |
| `AdvanceConstants` | `CASE_TYPES = exacto/faltante/sobrante` — concepto exclusivo de legalizaciones |
| `PettyCash` / `Refund` | Ya unificados vía trait (C2) |

Una `interface PipelineDefinition` común tendría que aceptar todos estos campos como opcionales o casos especiales — es la sobreingeniería que el principio "no flexibility that wasn't requested" prohíbe.

**Argumentos del auditor reevaluados:**

- *"Agregar un campo cuesta 6 archivos"* — engañoso. Cuando agregas `STATUS_DESCRIPTIONS` cada pipeline tiene contenido propio; un `PipelineDefinition` central no elimina el trabajo, solo reubica las 6 entradas. El costo lineal es inherente al dominio, no al diseño.
- *"No hay forma de iterar pipelines"* — `PipelineStepConstants::STEPS_BY_PIPELINE` ya enumera los 6 con `isValid()`. Un `PipelineRegistry` añadiría elegancia pero no resuelve un dolor productivo concreto.
- *"Riesgo de typo al copiar"* — válido pero mitigable con cs-check + revisión de PR. C2 ya resolvió el caso real de copy-paste (PettyCash↔Refund).

**Lo que sí permanece como deuda real (no parte de C3):**

- **M3** (drift `Aut. Pago` vs `Autorización de pago` entre `*Constants::STATUS_LABELS` y `PipelineStepConstants::STEP_LABELS`) — bug presente, atender por separado.
- **M6** (`InvoicePipelineService::ALL_STATUSES`/`TRANSITIONS` deberían vivir en `InvoiceConstants`) — duplicación accidental real, atender por separado.

**Cuándo reabrir C3:**

Si llega un séptimo pipeline con shape muy similar a uno existente, o si los 6 actuales convergen orgánicamente al mismo shape (hoy divergen). Hasta entonces, la estructura actual refleja diferencias reales de dominio y debe mantenerse.

### Notas M1+M2 (2026-05-06)

Diseño completo en [`docs/plans/2026-05-06-m1-m2-presentation-extraction-design.md`](../plans/2026-05-06-m1-m2-presentation-extraction-design.md). Refactor en un único PR.

- Nueva carpeta `src/View/Presentation/` con 7 clases finales: `InvoicePresentation`, `NoveltyPresentation`, `AdvancePresentation`, `PaymentSchedulingPresentation`, `PettyCashPresentation`, `RefundPresentation`, `SharedPresentation`. `Constants/` queda como vocabulario de dominio puro.
- `StatusColorConstants` eliminado. El god-array `PIPELINE_STATUS_BADGES` se descompone: cada pipeline tiene su `STATUS_BADGES` con los estados que le aplican. Los 4 controllers + 11 templates importan solo el mapa del pipeline que renderizan.
- `STATUS_ICONS` migrados desde `AdvanceConstants`, `NoveltyConstants`, `PaymentSchedulingConstants`, `GroupingPipelineConstantsTrait` y `InvoicePipelineService`. Cada uno vive ahora en su `*Presentation`. Aceptamos duplicación de 5 entradas idénticas entre `PettyCashPresentation` y `RefundPresentation` (YAGNI: divergencia futura plausible).
- `PettyCashConstants::STATUS_BADGES` (que existía localmente con valores divergentes del god-array) absorbido en `PettyCashPresentation::STATUS_BADGES` con sus colores reales (`bg-info text-dark` para agrupación, `bg-warning text-dark` para tesorería).
- `NoveltyConstants::CALENDAR_COLORS` movido a `NoveltyPresentation::CALENDAR_COLORS` (paleta hex del calendario de novedades).
- `ObservationConstants::DATE_FORMAT` movido a `SharedPresentation::DATE_FORMAT` (formato global de UI). `ObservationConstants` queda con tipos de observación + mensajes de error.
- `templates/ExternalApprovals/review.php` ahora resuelve `$badgeMap` desde `$tokenRecord->entity_type` (`'invoices'` → `InvoicePresentation`, `'employee_novelties'` → `NoveltyPresentation`).
- `templates/PettyCashRecords/view.php:211` muestra el `pipeline_status` de la **factura** asociada — usa `InvoicePresentation::STATUS_BADGES`, no el badge de la propia caja menor.
- Sin migración de BD; sin cambio de comportamiento; sin lógica nueva.

### Notas M3, M4, M6, m6 (2026-05-06)

Plan de ejecución completo en [`docs/plans/2026-05-06-constants-audit-plan-a-design.md`](../plans/2026-05-06-constants-audit-plan-a-design.md). Tres PRs en `main`:

- **PR `f4fd900`** — `refactor(constants): centralize observation types and pipeline status arrays`
  - **m6**: agregadas `ObservationConstants::TYPE_GENERAL`, `TYPE_REGRESSION`, `TYPES`. Las 4 declaraciones locales (`InvoiceConstants`, `PaymentSchedulingConstants`, `GroupingPipelineConstantsTrait`) se redefinen como referencias a `ObservationConstants` para preservar API en ~25 call-sites de Tables/Services/templates.
  - **M6**: `ALL_STATUSES` y `TRANSITIONS` movidas de `InvoicePipelineService` a `InvoiceConstants` (incluyen `STATUS_LEGALIZADA`, terminal fuera de `PIPELINE_STATUSES`). `InvoicesTable.php:214` actualizado a `InvoiceConstants::ALL_STATUSES`; import obsoleto de `InvoicePipelineService` removido. `STATUS_ICONS` permanece en el service (M1, fuera de alcance).
  - Alcance verificado: solo `InvoicePipelineService` tenía estas constantes — `NoveltyPipelineService` y `PaymentSchedulingPipelineService` no existen como archivos independientes.

- **PR `91f78dd`** — `refactor(constants): canonicalize 'Autorización de pago' label across pipelines`
  - **M3**: `'Aut. Pago'` reemplazado por `'Autorización de pago'` en los 5 `*Constants` con `STATUS_LABELS` (Invoice, Novelty, PaymentScheduling, Advance, `GroupingPipelineConstantsTrait`) y en 7 templates con badges inline (Advances, Invoices, PaymentSchedulings views/edits).
  - Ahora `*Constants::STATUS_LABELS` y `PipelineStepConstants::STEP_LABELS` coinciden. Slug `'autorizacion_pago'` sin cambios; pre-grep confirmó que ninguna query filtra por el string del label.
  - 3 comentarios internos en services (`RefundPaymentService:38`, `PettyCashService:470`, `AutorizacionPagoState:29`) no se tocaron por principio de cambios quirúrgicos — no afectan UX.

- **PR `1c53032`** — `refactor(employees): replace employee_statuses table with status enum column`
  - **M4**: gate de auditoría previa pasó (tabla sin metadatos, 2 filas estables, FKs entrantes solo la propia, 238 empleados todos con `Activo`). Se procedió con la opción radical (drop tabla + columna string).
  - `EmployeeStatusConstants` cambia de PKs (1, 2) a slugs (`'activo'`, `'retirado'`); incorpora `STATUSES` y `STATUS_LABELS`.
  - Migración `20260506213953`: agrega `employees.status VARCHAR(20) NOT NULL DEFAULT 'activo'`, backfill desde `employee_status_id`, drop FK + columna, drop tabla `employee_statuses`, limpieza de 8 filas en `permissions WHERE module='employee_statuses'`. `down()` reversa exacta.
  - Cambios de código: `Employee` entity (`$_accessible`), `EmployeesTable` (sin `belongsTo('EmployeeStatuses')`, validación `inList`, ExcelExportable simplificado), `EmployeesController`, `InvoicesController`, `EmployeeStatisticsService`, `EmployeeFilterService`, `EmployeeHistoryService`, `AppController` (`controllerModuleMap`), `AuthorizationService` (`MODULES`), templates de `Employees/`, sidebar (`templates/layout/default.php`).
  - Borrados: `EmployeeStatusesController`, `EmployeeStatusesTable`, `EmployeeStatus` entity, `templates/EmployeeStatuses/` (4 archivos).
  - Nota Excel: import/export del campo de estado ahora usa el slug (`'activo'`/`'retirado'`) en lugar del label. Sheets antiguas con `'Activo'`/`'Retirado'` fallarán validación `inList`.

---

## Resumen ejecutivo

La capa de constantes funciona como single-source-of-truth para strings de dominio, pero **escala mal con shotgun surgery**: agregar un estado al pipeline obliga a editar entre 3 y 6 archivos. Cinco archivos (`InvoiceConstants`, `NoveltyConstants`, `RefundConstants`, `PettyCashConstants`, `AdvanceConstants`, `PaymentSchedulingConstants`) repiten el **mismo esqueleto** (`STATUSES`, `STATUS_LABELS`, `STATUS_ICONS`, `TRANSITIONS`, `BACKWARD_TRANSITIONS`, `CODE_PREFIX`, `OBSERVATION_TYPE_*`) sin abstracción común. Además mezclan capa de presentación (clases Bootstrap, iconos, colores) con dominio.

---

## Inventario

| Archivo | LoC | Dominio |
|---|---|---|
| `EmployeeStatusConstants.php` | 10 | Estados de empleado (IDs de BD) |
| `ProviderConstants.php` | 17 | Tipos de documento |
| `ObservationConstants.php` | 18 | Mensajes del chat de observaciones |
| `ContractTypeConstants.php` | 19 | Tipos de contrato |
| `RoleConstants.php` | 22 | Nombres de roles |
| `StatusColorConstants.php` | 36 | Badges Bootstrap por estado |
| `EmailLogConstants.php` | 43 | Estados/eventos de email |
| `AdvanceConstants.php` | 65 | Pipeline de legalizaciones |
| `PaymentSchedulingConstants.php` | 66 | Pipeline de programación de pagos |
| `PettyCashConstants.php` | 74 | Pipeline de caja menor |
| `RefundConstants.php` | 77 | Pipeline de reintegros |
| `InvoiceConstants.php` | 121 | Pipeline de facturas |
| `PipelineStepConstants.php` | 131 | Catálogo de pasos para `pipeline_permissions` |
| `NoveltyConstants.php` | 173 | Pipeline de novedades |
| **Total** | **872** | |

---

## 🔴 Críticos

### C1. Inconsistencia léxica de un mismo concepto: `autorizacion_pago` vs `aut_pago`

La etapa "autorización de pago" del pipeline tiene **dos códigos string distintos** según el módulo:

| Módulo | Constante | Valor |
|---|---|---|
| `InvoiceConstants` | `STATUS_AUTORIZACION_PAGO` | `'autorizacion_pago'` |
| `AdvanceConstants` | `STATUS_AUTORIZACION_PAGO` | `'autorizacion_pago'` |
| `NoveltyConstants` | `STATUS_AUT_PAGO` | `'aut_pago'` |
| `PettyCashConstants` | `STATUS_AUT_PAGO` | `'aut_pago'` |
| `RefundConstants` | `STATUS_AUT_PAGO` | `'aut_pago'` |
| `PaymentSchedulingConstants` | `STATUS_AUT_PAGO` | `'aut_pago'` |

Lo mismo pasa con **`pagada` (femenino) vs `pagado` (masculino)**: mitad de los módulos usa uno, mitad el otro.

**Consecuencia:** `StatusColorConstants::PIPELINE_STATUS_BADGES` tiene que mapear AMBOS:
```php
'autorizacion_pago' => 'bg-info',
'aut_pago'         => 'bg-info',   // alias del mismo concepto
'pagada'           => 'bg-success',
'pagado'           => 'bg-success', // alias del mismo concepto
```
Esto es una **fuga de inconsistencia que ya está costando código**. Cualquier query analítica cross-pipeline (dashboard, reportes) necesita conocer ambos.

**Recomendación:** unificar a un único valor (`autorizacion_pago` y `pagada` son los más descriptivos), con migración de datos. Si por riesgo no se puede migrar, **documentarlo explícitamente** en cabeza de cada archivo y crear `PipelineLexicon::canonicalize($status)`.

---

### C2. Duplicación estructural masiva entre `PettyCashConstants` y `RefundConstants`

Comparados línea por línea, son **clones casi idénticos**:

| Constante | PettyCash | Refund |
|---|---|---|
| Estados (5) | `agrupacion`, `contabilidad`, `tesoreria`, `aut_pago`, `pagado` | idénticos |
| `STATUS_LABELS` | idéntico | idéntico |
| `STATUS_ICONS` | idéntico | idéntico |
| `TRANSITIONS` | idéntico | idéntico |
| `BACKWARD_TRANSITIONS` | idéntico | idéntico |
| `OBSERVATION_TYPE_*` | idéntico | idéntico |
| `CODE_PREFIX` | `'CM'` | `'REI'` |

Solo `CODE_PREFIX` y los `BENEFICIARY_TYPE_*` (exclusivos de Refund) divergen. **Esto es DRY violado al 95%.**

**Recomendación:** extraer un `AbstractGroupingPipelineConstants` o reemplazar por un `PipelineDefinition` value object configurable. Como mínimo: una `trait` con la matriz común.

---

### C3. Repetición del mismo esquema en 6 archivos sin contrato común

Los 6 *PipelineConstants files siguen el mismo patrón shape-by-convention pero **sin interface ni clase abstracta** que lo formalice. Esto significa:
- Cualquier nuevo módulo se construye por copy-paste (alto riesgo de typo o de olvidar `BACKWARD_TRANSITIONS`).
- No hay forma estática de iterar "dame todos los pipelines" — `PipelineStepConstants` enumera 6 a mano.
- Si se agrega un campo (p. ej. `STATUS_DESCRIPTIONS`), hay que tocar 6 archivos.

**Recomendación:** definir
```php
interface PipelineDefinition {
    public function statuses(): array;
    public function labels(): array;
    public function icons(): array;
    public function transitions(): array;
    public function backwardTransitions(): array;
    public function codePrefix(): string;
}
```
y un registro central `PipelineRegistry`. `PipelineStepConstants` ya apunta hacia esta dirección — completarlo.

---

## 🟠 Mayores

### M1. `StatusColorConstants` mezcla dominio con presentación

`PIPELINE_STATUS_BADGES` (clases Bootstrap como `'bg-warning text-dark'`) y `READY_FOR_PAYMENT_BADGES` son **configuración de UI**, no constantes de dominio. Lo mismo aplica a:
- `*Constants::STATUS_ICONS` (`bi-bank`, `bi-shield-check`...)
- `NoveltyConstants::CALENDAR_COLORS`: paleta hex
- `ObservationConstants::DATE_FORMAT = 'd/m/Y H:i'` (formato de presentación)

**Consecuencia:** un cambio de tema visual o de framework CSS impactaría el "directorio de dominio". Mezcla violación de separación de capas.

**Recomendación:** mover a `src/View/Configuration/` o un `StatusPresentation` service. Mantener en `Constants/` solo lo que define el **lenguaje del dominio**.

---

### M2. `StatusColorConstants::PIPELINE_STATUS_BADGES` es un god-array cross-domain

La estructura es un único dict plano con 15 entradas que mezcla estados de 6 dominios distintos:
```php
'aprobacion'       => ...   // invoice + novelty
'tesoreria'        => ...   // 5 dominios
'borrador'         => ...   // payment_scheduling
'rrhh', 'gdp', 'revision_firmas' => ... // novelty exclusivo
```
Cualquier nuevo estado en cualquier módulo obliga a tocar este archivo. Acoplamiento eferente máximo.

**Recomendación:** un mapa por pipeline: `StatusColorConstants::badgesFor('invoices')[$status]`.

---

### M3. `STATUS_LABELS` duplicado en dos lugares para los mismos estados

Para cada pipeline, las labels viven simultáneamente en:
1. `XxxConstants::STATUS_LABELS` (e.g. `PettyCashConstants::STATUS_LABELS['tesoreria'] => 'Tesorería'`)
2. `PipelineStepConstants::STEP_LABELS['petty_cash']['tesoreria'] => 'Tesorería'`

Hay drift latente: en `PipelineStepConstants` el label del paso `aut_pago` es **`'Autorización de pago'`**, mientras que en `*Constants::STATUS_LABELS` es **`'Aut. Pago'`** (forma corta). Mismo valor, dos rótulos. Bug de UX latente.

**Recomendación:** consolidar `STEP_LABELS` referenciando `*Constants::STATUS_LABELS` o, mejor, decidir la fuente única y eliminar la otra.

---

### M4. `EmployeeStatusConstants` acopla código a IDs de seed

```php
public const ACTIVO = 1;
public const RETIRADO = 2;
```
Esto **hardcodea PKs de la BD**. Si el seed se ejecuta en otro orden, o se truncan tablas y se re-siembran con auto_increment alto, todo se rompe sin error visible. Es un *code smell* clásico (Magic Number disfrazado).

**Recomendación:** usar el campo `code` o `slug` del catálogo como en `OperationCenters` y `CostCenters`. Si la tabla `employee_statuses` no tiene slug, agregar una columna `code` y consultar por ahí.

---

### M5. Inconsistencia de estilo dentro del mismo archivo

En `PettyCashConstants` y `RefundConstants`, la mitad usa referencias `self::STATUS_*` y la otra mitad strings literales:
```php
public const STATUS_LABELS = [
    'agrupacion' => 'Agrupación',          // ← literal
    'contabilidad' => 'Contabilidad',
    ...
];
public const STATUSES = [
    self::STATUS_AGRUPACION,                // ← self::
    self::STATUS_CONTABILIDAD,
    ...
];
public const TRANSITIONS = [
    'agrupacion' => 'contabilidad',         // ← literal otra vez
    ...
];
public const BACKWARD_TRANSITIONS = [
    self::STATUS_AGRUPACION => null,        // ← self::
    ...
];
```
**Si se renombra `STATUS_AGRUPACION`, los literales no se actualizan**: refactor risk concreto. Normalizar a `self::*` en todos lados.

---

### M6. `InvoicePipelineService::ALL_STATUSES` y `TRANSITIONS` re-declarados sobre `InvoiceConstants`

`InvoicePipelineService.php:38-65` redefine constantes que ya existen en `InvoiceConstants`:
```php
public const STATUSES = InvoiceConstants::PIPELINE_STATUSES;  // OK, alias
public const ALL_STATUSES = [ /* 6 estados */ ];              // ← se podría declarar en InvoiceConstants
public const TRANSITIONS = [ /* idéntico al patrón */ ];      // ← idem
```
La constante `ALL_STATUSES` (que incluye `STATUS_LEGALIZADA`, no en `PIPELINE_STATUSES`) es semánticamente parte del **modelo de dominio de facturas**, no de un service. `InvoicesTable.php:214` la usa para validación: bien podría leer `InvoiceConstants::ALL_STATUSES`.

**Recomendación:** mover `ALL_STATUSES` y `TRANSITIONS` a `InvoiceConstants`.

---

## 🟡 Menores

### m1. Mezcla de idiomas inglés/español en valores de un mismo dominio
- `EmailLogConstants`: status values en inglés (`'pending'`, `'sent'`, `'failed'`) — labels en español.
- `AdvanceConstants::SIGNATURE_PENDING = 'pending'` (inglés).
- Resto del proyecto: `'Pendiente'`, `'tesoreria'` (español sin acentos para slugs).

Convivencia funcional pero inconsistente. Decidir convención y documentarla.

### m2. `InvoiceConstants::DOCUMENT_TYPES` referencia constantes declaradas más abajo
Líneas 9-19: `self::DOCTYPE_FACTURA` se usa **antes** de declararse en líneas 79-87. PHP lo permite pero visualmente desorganizado.

### m3. `InvoiceConstants::DIAN_STATUSES` mezcla const y literal
```php
public const DIAN_STATUSES = ['Pendiente', self::DIAN_APPROVED, self::DIAN_REJECTED];
```
`'Pendiente'` debería tener su propia constante `DIAN_PENDING`.

### m4. `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS` — magic strings
```php
public const READY_FOR_PAYMENT_OPTIONS = ['Si', 'Pago PSE', 'Pago prioritario'];
```
Sin constantes simbólicas. `StatusColorConstants::READY_FOR_PAYMENT_BADGES` repite estos strings como claves: si se cambia uno, se rompe en silencio el otro.

### m5. `NoveltyConstants::ACTIVE_STATUSES` — semántica ambigua
Excluye `STATUS_AUT_PAGO`. ¿Bug u opción de diseño? Sin PHPDoc explicando por qué.

### m6. `*Constants::OBSERVATION_TYPE_GENERAL = 'general'` repetido 5 veces idéntico
Vive en Invoice, PettyCash, Refund, PaymentScheduling. Mismo string, mismo nombre. Candidato directo a `ObservationConstants::TYPE_GENERAL` / `TYPE_REGRESSION`.

### m7. `PipelineStepConstants::isValid()` — método estático en clase de constantes
Estilísticamente, `Constants` debería ser **datos puros**. Un método de validación cabe mejor en un `PipelineStepValidator` o en `PipelineRegistry`.

---

## 🟢 Sugerencias / refactor de fondo

### S1. Migrar a enums PHP 8.1+
Todas estas const-strings se beneficiarían de `enum: string` con `cases()`, exhaustividad en `match()` y validación gratuita por type hint:
```php
enum InvoicePipelineStatus: string {
    case APROBACION = 'aprobacion';
    case CONTABILIDAD = 'contabilidad';
    case TESORERIA = 'tesoreria';
    case AUTORIZACION_PAGO = 'autorizacion_pago';
    case PAGADA = 'pagada';
    case LEGALIZADA = 'legalizada';

    public function label(): string { /* match */ }
    public function icon(): string { /* match */ }
    public function next(): ?self { /* match */ }
}
```
**Veredicto pragmático:** vale la pena para `InvoicePipelineStatus` (~279 referencias), evaluar caso a caso para los demás.

### S2. Extraer un `PipelineDefinition` value object
Para colapsar las 6 *PipelineConstants files duplicadas. Ver C3.

### S3. Separar `Constants/` por subdirectorios
```
src/Constants/
├── Domain/         # estados, transiciones, slugs
│   ├── Invoice/
│   ├── Novelty/
│   └── ...
└── Presentation/   # iconos, badges, colores  ← StatusColorConstants, *_ICONS, CALENDAR_COLORS
```

---

## Tabla resumen

| Categoría | Severidad | Ítems |
|---|---|---|
| Inconsistencia de dominio (`aut_pago`/`pagado`) | 🔴 | 1 |
| Duplicación masiva (PettyCash≈Refund; 6 PipelineConstants gemelas) | 🔴 | 2 |
| Mezcla de capas (UI en dominio) | 🟠 | 1 |
| God-array cross-domain | 🟠 | 1 |
| Labels duplicados con drift (`Aut. Pago` vs `Autorización de pago`) | 🟠 | 1 |
| Acoplamiento a IDs de seed | 🟠 | 1 |
| Refactor risk por inconsistencia de estilo | 🟠 | 1 |
| Constantes de service que deberían estar en Constants | 🟠 | 1 |
| Inconsistencia léxica menor | 🟡 | 7 |
| Sugerencias arquitectónicas | 🟢 | 3 |

---

## Plan de mejora sugerido (priorizado)

1. **Quick wins** (1–2h): unificar estilo `self::*` en `PettyCashConstants` y `RefundConstants` (M5); promover `ObservationConstants::TYPE_*` y eliminar las copias (m6).
2. **Refactor focal** (½ día): mover `ALL_STATUSES`/`TRANSITIONS` de `InvoicePipelineService` a `InvoiceConstants` (M6); separar `Presentation/` de `Domain/` (S3).
3. **Eliminación de duplicación estructural** (1–2 días): introducir `AbstractGroupingPipelineConstants` o `PipelineDefinition` para fusionar PettyCash/Refund (C2/C3); consolidar `STEP_LABELS` con `STATUS_LABELS` (M3).
4. **Decisión estratégica** (proyecto): unificar `aut_pago`↔`autorizacion_pago` y `pagado`↔`pagada` con migración de datos (C1) — bloqueante si se quiere extender el dominio sin más deuda.
5. **Largo plazo** (opcional): migración incremental a enums PHP 8.1+ (S1), empezando por `InvoicePipelineStatus`.

**Verdicto final:** ❌ **REQUEST CHANGES** — la base es sólida (uso consistente de constantes, no hay strings sueltos en services), pero la duplicación estructural y la inconsistencia léxica entre módulos van a multiplicar el costo de cada nueva feature.
