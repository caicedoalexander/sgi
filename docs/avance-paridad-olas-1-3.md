# Avance de ejecución — Paridad de módulos de flujo (Olas 1-3)

> **Fecha:** 2026-05-29 · **Estado:** ejecutado en `main`.
>
> Documento **compañero** de [`auditoria-estructural-fresca-2026-05-29.md`](auditoria-estructural-fresca-2026-05-29.md) (la auditoría base "as-audited", que se conserva intacta como retrato del estado **pre-refactor**). Aquí se registra el **delta de implementación** de las Olas 1-3 del roadmap de esa auditoría: qué se ejecutó, con qué commits, y los 4 cuadros comparativos actualizados al estado **post-refactor**.
>
> Para el mapa estructural original, el patrón canónico, la clasificación A/B/C y la priorización, ver el informe base.

---

## Resumen por ola

| Ola | Foco | Ítems ejecutados | Commits | Resultado |
|---|---|---|---|---|
| **1** | Seguridad y atomicidad | A1, A3, A4 | `69f4b88` (A1), `7695031` (A3), `8781d00` (A4) | ✅ Suite verde · `php -l` limpio · 0 violaciones cs nuevas · comportamiento preservado. **C1 queda como decisión pendiente** (requiere migración de esquema) |
| **2** | Convergencia de la máquina de estados | A8, A2, A5 (+A6) | `2a0fe27` (A8), `121bdd3` (A2), `14ee4f6` (A5+A6) | ✅ Suite verde · comportamiento preservado. **A6 resultó no-op de código** (ver nota) |
| **3** | Organización y fixes | A10, A11, A12, A13, A7 | `048da87` (A10/A11/A12/A13), `6789ad3` (A7) | ✅ **215/215 PHPUnit verde** · `php -l` limpio · 0 cs nuevas. **A9 diferido**; **C2 decisión pendiente** |

**Matices de ejecución a registrar:**

- **A6 fue no-op de código.** Los métodos del enum reportados como "muertos" tienen *callers de test*, y las Constants **no pueden** delegar al enum por el límite de PHP sobre expresiones constantes (`const X = Enum::CASE->value` es válido, pero derivar arrays/labels en tiempo de compilación no lo es). Se cerró documentalmente junto a A5 (`14ee4f6`); el enum sigue siendo fuente única efectiva del **grafo de transición** (los States ya delegan), no de los labels/listas de las Constants.
- **A13 sin cambio observable.** El atributo `#[PipelineAction(step)]` ya gateaba la acción **antes** de entrar al cuerpo del método, por lo que la verificación inline `canOperateStep` era estrictamente redundante; su eliminación no altera comportamiento (defensa en profundidad → defensa única declarativa).
- **A9 diferido** (no ejecutado): el VM de Advance (`AdvanceLegalizationViewModel`) sigue sin implementar `EditViewModelInterface` y `legalization()` aún entrega con `$this->set($vm->build())` (`AdvancesController.php:340`). Es un rewrite de un template de ~765 líneas: **alto riesgo, cero cambio de comportamiento** → no entró en estas olas.

---

## Cuadros actualizados (post-refactor)

Leyenda: ✅ presente/canónico · ⚠️ presente pero divergente · ❌ ausente · 🔵 ausencia legítima de dominio. La columna **Δ** señala el ítem de remediación que cambió la celda respecto a la versión as-audited (sección 1 del informe base).

### Tabla A — Coordinador y API de transición (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| Clase coordinador | `InvoicePipelineService` ✅ | `NoveltyPipelineService` ✅ | `AdvanceLegalizationService` ⚠️ (no es coord. de pipeline) | `PettyCashPipelineService` ✅ | `RefundPipelineService` ✅ | `PaymentSchedulingPipelineService` ✅ | — |
| `saveAndAdvance` | ✅ `:211` | ✅ `:127` | ❌ | ✅ `:175` | ✅ **`:144`** | 🔵 (no edita header) | **A2** |
| `advance` (puro) | ❌ (plegado en edit) | ❌ | ❌ | ✅ `:293` | ✅ `:181` | ✅ `:132` | — |
| `regress` | ✅ `:318` | ❌ (tiene `reject` terminal) 🔵 | ❌ (verbos por outcome) 🔵 | ✅ `:800` | ✅ `:426` | ✅ `:208` | — |
| `reject` | ❌ (vía `area_approval`) | ✅ `:288` terminal 🔵 | ❌ | ❌ | ❌ | ✅ **en el coordinador** `:330` (transaccional) | **A3** |
| `validateTransitionRequirements` firma | `(inv, fromStatus, overrides)` `:104` | `(nov, fromStatus)` `:322` | ❌ | `(record)` `:411` ⚠️ | `(record)` `:361` ⚠️ | `(sched, fromStatus)` `:106` | — |
| `getNextStatus` firma | `(string, ?docType)` `:145` | `(object, ?type)` 🔵 | ❌ (hardcode `_setStatus`) | `(string)` | `(string)` | `(string)` | — |
| Resolución de avance | State `:145` | State + skips por tipo 🔵 | hardcode literal `_setStatus(…)` ⚠️ | State (→ enum) | State (→ enum) | enum `next()` | **A5** (States ahora delegan al enum; ya no hay doble fuente) |
| Retorna `ServiceResult` | ✅ | ✅ (`assignToExistingLiquidationDoc` ✅; `assignToLiquidationDoc` aún array) | ✅ | ✅ | ✅ | ✅ | **A4** (rama existing_doc_id) |
| Coordinador con IO/transacción inline | ⚠️ sí | ⚠️ sí | ⚠️ sí (fat) | ⚠️ sí (fat) | ⚠️ sí | ⚠️ sí | — |

### Tabla B — Suite de Pipeline (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| Nº States | 7 | 9 | 7 | 6 | 6 | 5 | — |
| Pureza de States | ✅ (inyecta `InvoicePaymentService`) | ✅ (inyecta `NoveltyLiquidationGuard`) | ✅ **(inyecta `AdvanceLegalizationGuard`)** | ✅ | ✅ | ✅ **(inyecta `PaymentSchedulingGuard`)** | **A8** |
| Registry resuelve enum→State | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Constants delegan al enum | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Enum tiene `previous()` | ✅ **`:51`** | ✅ | ✅ | ✅ | ✅ | ✅ | **A5** (Invoice ya no es el outlier) |
| State delega al enum (`next()/previous()`) | ✅ | ✅ | ✅ (ya no vestigial: delega) | ✅ | ✅ | ✅ | **A5** |
| `FieldAccessPolicy` | ✅ `Pipeline/Invoice/Policy/` | ✅ | 🔵 ausente (edita vía Invoices) | ✅ **(gate por-paso, sin `unset`)** | ✅ **(gate por-paso, sin `unset`)** | 🔵 ausente (no edita header) | **A1** |
| `LockPolicy` | ✅ **`Pipeline/Invoice/Policy/`** (aún hace IO → C4) | ❌ (sin regress) 🔵 | ❌ 🔵 | ✅ **`Pipeline/PettyCash/Policy/`** | ✅ **`Pipeline/Refund/Policy/`** | ⚠️ stub `→null` en coord. | **A7** |
| `TransitionValidator` dedicado | ✅ **`Pipeline/Invoice/Policy/`** | ❌ (en States) | ❌ | ❌ (en States) | ✅ **`Pipeline/Refund/Policy/`** | ❌ (en States) | **A7** |
| `ActionPolicy` | ✅ 7 métodos | ✅ | ✅ 13 métodos | ✅ | ✅ | ✅ 3 métodos | — |
| `DocumentTypePolicy` | ✅ exclusivo (factory + 3 impl) | 🔵 | 🔵 | 🔵 | 🔵 | 🔵 | — |

> **Nota A7:** los 5 artefactos movidos (`InvoiceLockPolicy`, `InvoiceTransitionValidator`, `RefundLockPolicy`, `RefundTransitionValidator`, `PettyCashLockPolicy`) ahora viven bajo `src/Service/Pipeline/{Invoice,Refund,PettyCash}/Policy/` con su namespace renombrado; **`src/Service/` raíz ya no contiene ningún `*LockPolicy`** (verificado por Glob). Solo cambió ubicación/namespace + wiring; sin cambio funcional. El IO de `InvoiceLockPolicy` (C4) **no** se tocó — sigue siendo decisión abierta.

### Tabla C — Servicios auxiliares (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| `implements HistoryServiceInterface` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | — (C5, Ola 4 — no ejecutado) |
| `use HistoryNormalizationTrait` | ✅ | ✅ | ❌ 🔵 | ✅ | ✅ | ❌ 🔵 | — |
| `recordChanges()` + fuente de campos | ✅ array local ⚠️ | ✅ itera `FIELD_LABELS` ⚠️ | 🔵 (audita explícito) | ✅ const `FIELDS_TO_TRACK` | ✅ const `FIELDS_TO_TRACK` | 🔵 | — |
| `DocumentUploadTrait` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `PaymentService` dedicado | ✅ `InvoicePaymentService` | ✅ `LiquidationDocPaymentService` | 🔵 (reusa `InvoicePaymentService`) | ❌ inline en coord. ⚠️ | ✅ `RefundPaymentService` | ❌ inline en coord. | — (C2 sin ejecutar) |
| `authorizePayment` retorna `ServiceResult` | ⚠️ array (`InvoicePayment`, propio) | ✅ **`:100`** | n/a | ✅ | ✅ | ✅ | **A11** (Novelty/`LiquidationDocPaymentService`) |
| Modelo de pago | tabla multi-fila + `idempotency_key` + `editPayment` | tabla multi-fila | invoice_payments vía eventos | columnas del record | columnas del record | materializa a hijas | — |
| Modelo de rechazo de pago | persiste `status`+motivo | ⚠️ **BORRA** `:166` | conserva | persiste motivo | persiste motivo | n/a (rechaza la programación) | — (**C1** decisión pendiente: requiere migración de `liquidation_doc_payments`) |

### Tabla D — Permisos y datos a vistas (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| CRUD vía `#[Permission]`+`$controllerModuleMap` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Enforcement por-paso (pagos) `#[PipelineAction(step)]` | ✅ 403 declarativo | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| **Escritura de campos gateada por `canOperate`** | ✅ (base rol-aware) | ✅ | 🔵 | ✅ **(gate por-paso)** | ✅ **(gate por-paso)** | 🔵 | **A1** |
| Redundancia `canOperateStep` inline en `confirmPayment` | n/a | n/a | n/a | ✅ **removida** | ✅ **removida** | ✅ **removida** | **A13** |
| `ViewModel` en edit/add (`EditViewModelInterface`) | ✅ | ✅ | ⚠️ **no implementa interfaz**, `set($vm->build())` `:340` | ✅ | ✅ | ✅ | — (**A9 diferido**) |
| `set('actionPolicy')` muerto | n/a | n/a | ✅ **removido** | n/a | n/a | n/a | **A10** |
| `$this->set()` crudo en index/view | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **(bug `$roleName` resuelto: `index()` usa solo `$roleId`)** | **A10** |
| Literal del estado de doc de liquidación | n/a | ✅ **`NoveltyConstants::DOC_STATUS_LIQUIDACION`** (`= 'd. liquidacion'`, valor idéntico) | n/a | n/a | n/a | n/a | **A12** |
| Slug módulo ≠ slug pipeline | invoices=invoices | ⚠️ employee_novelties/… ≠ novelties/… | ⚠️ advances ≠ legalizations | petty_cash | refunds | payment_schedulings | — (C3 no tocado: persistido) |

---

## Estado de divergencias

Leyenda: ✅ ejecutado · ⏸ diferido · ◻ decisión pendiente.

| # | Divergencia | Estado | Commit / nota |
|---|---|---|---|
| **A1** | PettyCash/Refund gatean por-paso la escritura de campos (`getEditableFields`→patch vacío; sin `unset($roleId)`) | ✅ ejecutado | `69f4b88` — `PettyCashFieldAccessPolicy.php`, `RefundFieldAccessPolicy.php` |
| **A2** | `RefundPipelineService::saveAndAdvance` | ✅ ejecutado | `121bdd3` — `RefundPipelineService.php:144` |
| **A3** | PaymentScheduling `reject()` → coordinador transaccional; controller delega | ✅ ejecutado | `7695031` — servicio `:330`, controller `:263` |
| **A4** | Novelty `assignLiquidation` rama `existing_doc_id` → coordinador (`assignToExistingLiquidationDoc` + `_attachNoveltyToDoc`, transaccional); controller delega | ✅ ejecutado | `8781d00` — servicio `:467`, controller `:1002` |
| **A5** | Enum como fuente única del grafo: States delegan a `getStatus()->next()/previous()`; `previous()` añadido al enum de Invoice | ✅ ejecutado | `14ee4f6` — `Invoice/PipelineStatus.php:51`, States (p.ej. `Refund/State/AgrupacionState.php`) |
| **A6** | Métodos/representaciones duplicadas del enum | ✅ ejecutado (**no-op de código**) | `14ee4f6` — métodos del enum tienen callers de test; Constants no pueden delegar (límite const-expression PHP). Cerrado documentalmente |
| **A7** | Lock/TransitionValidator → `Pipeline/{Modulo}/Policy/`; raíz limpia | ✅ ejecutado | `6789ad3` — 5 clases movidas; raíz sin `*LockPolicy` (Glob) |
| **A8** | IO de `ValidacionState`/`BorradorState` → Guards inyectados (`AdvanceLegalizationGuard`/`PaymentSchedulingGuard`, `?? new`) | ✅ ejecutado | `2a0fe27` — `Advance/State/ValidacionState.php`, `PaymentScheduling/State/BorradorState.php` |
| **A9** | Advance VM `EditViewModelInterface` + `set('viewModel')` | ⏸ diferido | rewrite de template ~765 líneas; alto riesgo / cero cambio de comportamiento |
| **A10** | `set('actionPolicy')` muerto (Advance) + bug `$roleName` indefinido (PaymentScheduling index) | ✅ ejecutado | `048da87` |
| **A11** | `LiquidationDocPaymentService::authorizePayment` → `ServiceResult`; caller ajustado | ✅ ejecutado | `048da87` — servicio `:100`, caller `LiquidationDocPaymentsController.php:106` |
| **A12** | Constante `NoveltyConstants::DOC_STATUS_LIQUIDACION = 'd. liquidacion'` (valor idéntico); `NoveltyDocumentService` la usa | ✅ ejecutado | `048da87` — `NoveltyConstants.php:140`, `NoveltyDocumentService.php:178,215` |
| **A13** | Redundancia `canOperateStep` inline en `confirmPayment` (PettyCash/Refund/PaymentScheduling) | ✅ ejecutado (**sin cambio observable**) | `048da87` — el atributo `#[PipelineAction(step)]` ya gateaba antes del cuerpo |
| **C1** | Rechazo de pago de Novelty BORRA vs persistir motivo | ◻ decisión pendiente | requiere migración de esquema en `liquidation_doc_payments` (sin columnas `status`/`rejection_reason`) + cambio de comportamiento; no es refactor |
| **C2** | Extraer `PettyCashPaymentService` espejando Refund | ◻ decisión pendiente | reorganización de bajo riesgo; no entró en Olas 1-3 |

---

## Pendiente para olas posteriores

- **A9** (⏸) — alineación del VM de Advance (rewrite de template, alto riesgo).
- **C1 / C2** (◻) — decisiones: migración del rechazo de pago de Novelty; extracción de `PettyCashPaymentService`.
- **Ola 4 — higiene de bajo retorno:** C5 (interfaz/trait de History), C6 (auditoría en servicios), C7 (DI explícita de States), C4 (IO de `InvoiceLockPolicy` → Guard).
- **NO tocar sin migración explícita:** B8 (trampa de spelling `DIAN_REJECTED='Rechazado'` persistido), slug `advances`/nombres de tabla, y todas las divergencias **B** (esenciales de dominio).
