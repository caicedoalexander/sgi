# Inventario de patrones de permisos por módulo

**Fecha:** 2026-05-12
**Alcance:** 6 módulos de pipeline (Invoice, Novelty, Advance, PettyCash, Refund, PaymentScheduling) + 3 sub-controllers (InvoicePayments, LiquidationDocPayments, NoveltyLiquidationDocs).
**Propósito:** Inventario **read-only** previo a una segunda ola de unificación. Identifica qué patrón sigue cada módulo en 7 dimensiones, sin proponer cambios todavía.
**Continuación de:** `permissions-audit-2026-05-11.md` (verdicto RESUELTO con deudas conocidas).
**Leyenda:** ✅ = patrón canónico (mayoritario o explícitamente preferido por audit previo) · ⚠️ = variante / parcial · ❌ = ausente / no aplica.

---

## Matriz 6 × 7

| Módulo | D1 ActionPolicy | D2 FieldAccessPolicy | D3 denialReason API | D4 Controller dispatch | D5 UserContext | D6 ViewModel flags | D7 Mensaje denegación |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| **Invoice** | ⚠️ helper `_canOperate` privado, sin `canOperateStep`/`canOperateCurrentStep`; estado check inline | ⚠️ Hereda ✅ pero override `getEditableFields` + `getCollapsibleSections` extra; vive en `src/Service/` (no en `Pipeline/Invoice/Policy/`) | ✅ ambos métodos (`Advance` + `Regress`) | ✅ `actionPolicy->canX(...)` (3 calls) | ⚠️ `$user->role_id` ad hoc | ✅ Controller computa, ViewModel recibe `InvoiceEditPermissions` DTO | ⚠️ Solo se consume como boolean (`=== null`); Flash usa `$result->firstError()` string |
| **Advance** | ⚠️ helper `_canOperate` privado; **único** que compone `_canOperate && entity->canX()` (modelo rico) | ❌ **NO existe FieldPolicy** | ❌ **NO usa** `advance`/`regress` genéricos; tiene 13 métodos específicos (`linkInvoices`, `moveToRevisionFirmas`, `markSigned`, …) | ✅ `actionPolicy->canX(...)` (12 calls a 12 métodos específicos) | ⚠️ `$this->_getCurrentUser()->role_id` ad hoc | ⚠️ **Único** ViewModel que **se inyecta ActionPolicy y computa internamente** | ❌ no usa `DenialReason` |
| **Novelty** | ⚠️ `canOperateStep` ✅ + `canOperateCurrentStep` ✅; **única** policy con solo 2 métodos genéricos (no específicos) | ✅ Hereda `PipelineFieldPolicy`, ubicación canónica | ⚠️ Solo `denialReasonForAdvance` (falta `Regress`) | ❌ Controller **no** invoca policy ni authFacade — gating delegado al `NoveltyService` | ⚠️ `$user->role_id` ad hoc | ❌ `EmployeeNoveltyEditViewModel` no expone flags `can*` | ❌ no consume `DenialReason::message()` |
| **PettyCash** | ✅ `canOperateStep` público + 3 métodos específicos (`canRegister/Authorize/Confirm Payment`); estado check inline (`isPagada()`) | ✅ Hereda `PipelineFieldPolicy`, override `filterEntityData` con validación inline | ✅ ambos métodos | ✅ `actionPolicy->canX(...)` (4 calls) | ⚠️ `$user->role_id` ad hoc | ✅ Controller computa, ViewModel recibe flags | ⚠️ Solo consumo boolean (`=== null`); no muestra `->message()` en Flash |
| **Refund** | ✅ `canOperateStep` + `canOperateCurrentStep` públicos + 3 específicos; estado check inline (`isPagada()`) | ✅ Hereda `PipelineFieldPolicy`, override `filterEntityData` | ⚠️ Solo `denialReasonForRegress` (falta `Advance`) | ✅ `actionPolicy->canX(...)` (5 calls) | ⚠️ Mixto: 1 sitio `_userContext()` + 24 sitios `$user->role_id` ad hoc | ✅ Controller computa, ViewModel recibe flags | ⚠️ Solo consumo boolean |
| **PaymentScheduling** | ✅ `canOperateStep` público + 2 específicos (`canReject`, `canConfirmPayment`); estado check inline (`isPagada()` + status check) | ❌ **NO existe FieldPolicy** | ✅ ambos métodos | ✅ `actionPolicy->canX(...)` (4 calls) | ⚠️ `$user->role_id` ad hoc | ✅ Controller computa, ViewModel recibe flags | ✅ **único** que invoca `$advanceDenial->message()` en Flash |

---

## Sub-controllers (deuda anotada en PA-011)

| Sub-controller | D4 Patrón | D5 UserContext |
|---|---|---|
| **InvoicePaymentsController** | ❌ NO usa `actionPolicy`; 6 calls a `authFacade->canOperate(...)` inline | ✅ `_userContext()` helper |
| **LiquidationDocPaymentsController** | ❌ NO usa `actionPolicy`; 4 calls inline | ✅ `_userContext()` helper |
| **NoveltyLiquidationDocsController** | ❌ NO usa `actionPolicy`; 3 calls inline | ⚠️ `_userContext()` + 2 `role_id` directos |

Estos 3 controllers no fueron alcanzados por el refactor PA-011 (lo nota el cierre del audit anterior).

---

## Análisis por dimensión

### D1 — Shape del `*ActionPolicy`

**Sub-patrones encontrados:**

| Sub-patrón | Módulos | Observación |
|---|---|---|
| **A.** Helper público `canOperateStep(int $roleId, string $step)` | PettyCash, Refund, Novelty, PaymentScheduling | 4/6 |
| **B.** Helper privado `_canOperate(int $roleId, string $step)` | Invoice, Advance | 2/6 |
| **C.** Método público `canOperateCurrentStep(Entity, int $roleId)` | Refund, Novelty | 2/6 |
| **D.** Composición `_canOperate(...) && $entity->canX()` (delegación de estado a entidad) | Advance | 1/6 |
| **E.** Estado check inline (`if isPagada/isRejected/pipeline_status !== X`) | Invoice, PettyCash, Refund, PaymentScheduling | 4/6 |
| **F.** Solo métodos genéricos sin específicos | Novelty | 1/6 |

**Lectura:** Hay tensión entre **sub-patrón D** (Advance, "estado vive en la entidad") y **sub-patrón E** (los otros 4, "estado check inline en la policy"). El audit anterior consideraba A+D como modelo canónico; en la práctica solo Advance lo siguió. Refund/PettyCash/PaymentScheduling adoptaron E (más simple, menos refactor de entities).

### D2 — `*FieldAccessPolicy`

**Sub-patrones:**

| Sub-patrón | Módulos | Observación |
|---|---|---|
| **A.** Hereda `PipelineFieldPolicy` con shape canónico | Invoice, Novelty, PettyCash, Refund | 4/6 |
| **B.** No tiene FieldPolicy | Advance, PaymentScheduling | 2/6 |
| **C.** Override `filterEntityData` con validación inline (accrual_date, branches) | PettyCash, Refund | 2/4 que sí tienen |
| **D.** Ubicación canónica `src/Service/Pipeline/{Module}/Policy/` | Novelty, PettyCash, Refund | 3/4 |
| **E.** Ubicación legacy `src/Service/` | Invoice | 1/4 (alone) |
| **F.** Override `getEditableFields` + `getCollapsibleSections` extra | Invoice | 1/4 (alone) |

**Lectura:** El audit PA-008 alcanzó solo 4 de 6 módulos. Advance no fue tocado porque tiene un modelo distinto (no hay "edit form" con secciones por step — cada acción de Advance es una transición específica con su propio template parcial). PaymentScheduling no fue tocado **probablemente por omisión** — sí tiene formulario edit con secciones, candidato natural.

### D3 — API Service `denialReasonFor*`

**Sub-patrones:**

| Sub-patrón | Módulos | Observación |
|---|---|---|
| **A.** Ambos métodos (`Advance` + `Regress`) | Invoice, PettyCash, PaymentScheduling | 3/6 |
| **B.** Solo `Advance` | Novelty | 1/6 |
| **C.** Solo `Regress` | Refund | 1/6 |
| **D.** Ninguno (modelo de transiciones específicas) | Advance | 1/6 |

**Lectura:** Refund + Novelty **están parciales**. Advance es excepción legítima (su modelo es N transiciones específicas, no advance/regress binario).

### D4 — Patrón de invocación en Controller

**Sub-patrones:**

| Sub-patrón | Controllers | Observación |
|---|---|---|
| **A.** `$this->actionPolicy->canX(...)` con policy inyectada | Invoice, Advance, PettyCash, Refund, PaymentScheduling | 5/6 |
| **B.** Sin chequeo en controller — delegado al Service | Novelty | 1/6 |
| **C.** `$this->authFacade->canOperate(...)` inline | InvoicePayments, LiquidationDocPayments, NoveltyLiquidationDocs | 3 sub-controllers |

**Lectura:** Patrón A es claramente canónico para controllers principales. Novelty (B) y los 3 sub-controllers (C) son outliers conocidos.

### D5 — Construcción de `UserContext` / extracción de `$roleId`

**Sub-patrones:**

| Sub-patrón | Controllers | Observación |
|---|---|---|
| **A.** `$user->role_id` o `$this->_getCurrentUser()->role_id` ad hoc | Invoice, Novelty, Advance, PettyCash, PaymentScheduling | 5/6 |
| **B.** `_userContext()` helper (porque llama directo al Facade) | InvoicePayments, LiquidationDocPayments, NoveltyLiquidationDocs | 3 sub-controllers |
| **C.** Mixto (helper en 1 sitio + ad hoc en otros) | Refund | 1/6 |

**Lectura:** No hay divergencia "real" en D5 — el helper `_userContext()` solo es útil cuando se llama al Facade directamente. Donde se usa `actionPolicy`, propagar `int $roleId` es lo natural (el VO se construye dentro de la policy).

### D6 — Exposición de flags al ViewModel

**Sub-patrones:**

| Sub-patrón | Módulos | Observación |
|---|---|---|
| **A.** Controller computa flags → ViewModel recibe bools (`InvoiceEditPermissions` DTO o params directos) | Invoice, PettyCash, Refund, PaymentScheduling + NoveltyLiquidationDoc | 4/6 + 1 sub |
| **B.** ViewModel se inyecta ActionPolicy y computa internamente | Advance | 1/6 |
| **C.** ViewModel no expone flags `can*` | Novelty (EmployeeNovelty) | 1/6 |

**Lectura:** Advance (B) es deuda legacy del primer audit (cuando aún no existía el patrón A consolidado). Novelty (C) confirma su outlier status — la decisión "no exponer flags al template" puede ser válida si los templates no necesitan gating, pero conviene confirmar.

### D7 — Mensaje de denegación

**Sub-patrones:**

| Sub-patrón | Módulos | Observación |
|---|---|---|
| **A.** Consume `$denial->message()` en Flash | PaymentScheduling | 1/3 que tienen `denialReason` |
| **B.** Solo consume `denialReason === null` como boolean; Flash usa otra fuente (`$result->firstError()`, hardcoded) | Invoice, PettyCash, Refund | 3/3 que tienen `denialReason` |
| **C.** No usa `DenialReason` | Advance, Novelty | 2/6 |

**Lectura:** El enum `DenialReason::message()` se introdujo en PA-005 pero solo 1 controller lo consume tal cual. El resto cae a `firstError()` del `ServiceResult`, lo que en la práctica vuelve a un mensaje hardcoded en el service. Decidir si la fuente canónica para Flash es `DenialReason::message()` o `ServiceResult::firstError()` es una pregunta pendiente.

---

## Resumen de divergencias por severidad

### 🔴 Divergencias estructurales (cambian el modelo mental)

1. **D1.D vs D1.E** — Advance compone estado con `entity->canX()`, los otros 4 con check inline. Decidir cuál es el canónico afecta a las 5 policies del lado E.
2. **D2.B** — Advance y PaymentScheduling **no tienen FieldPolicy**. PaymentScheduling parece omisión; Advance parece decisión legítima.
3. **D4.B Novelty** — Controller no chequea, todo va en Service. Outlier completo.
4. **D6.B Advance** — ViewModel recibe ActionPolicy en vez de bools precomputados.

### 🟠 Divergencias parciales (mismo modelo, faltan piezas)

5. **D3.B/C** — Novelty solo `Advance`, Refund solo `Regress`. Completar simétrico.
6. **D7.B** — 3/3 controllers no consumen `DenialReason::message()` aunque podrían.
7. **D4.C** — 3 sub-controllers (deuda explícita anotada en PA-011).
8. **D1.A vs D1.B** — `_canOperate` privado (Invoice, Advance) vs `canOperateStep` público (los otros 4). Cosmético.
9. **D1.C** — `canOperateCurrentStep` existe en 2/6 (Refund, Novelty); útil pero no propagado.

### 🟡 Divergencias cosméticas

10. **D2.E/F** — Invoice FieldPolicy en ubicación legacy + métodos extra (`getCollapsibleSections` vacío que nadie usa).
11. **D6.C** — `EmployeeNoveltyEditViewModel` sin flags `can*`. Verificar si el template los necesita.

---

## Patrones candidatos a "canónico" (sin decisión todavía)

Para discutir en el paso 3 del proceso acordado:

| Dimensión | Candidato A (mayoría) | Candidato B (audit-preferred) | Notas |
|---|---|---|---|
| D1 estado | Inline check en policy (4/6) | Delegar a `entity->canX()` (1/6 Advance) | B es más limpio pero requiere predicates ricos en cada entity |
| D1 helper | `canOperateStep` público (4/6) | `_canOperate` privado (2/6) | A más reusable, B más encapsulado |
| D2 existencia | Tener FieldPolicy (4/6) | Aplicar solo donde hay formulario edit con secciones (Advance excepción) | A si PaymentScheduling tiene form edit con secciones |
| D3 denialReason | Ambos métodos (3/6) | — | A es el patrón completo |
| D4 dispatch | `actionPolicy->canX` (5/6) | — | A es claramente canónico |
| D6 flags | Controller computa → ViewModel recibe bools (4/6 + 1 sub) | ViewModel se inyecta policy (1/6) | A es más testeable y desacopla template |
| D7 message | `$denial->message()` (1/3) | `$result->firstError()` (3/3) | Sin consenso — requiere decisión explícita |

---

## Próximo paso

Revisar este inventario y acordar:
1. Qué patrones son canónicos por dimensión (decisión sobre los 7 casos de la última tabla).
2. Cuáles divergencias migrar y cuáles dejar (Advance y Novelty tienen rasgos legítimos de outlier).
3. Qué dimensiones agrupar en una sola PR vs separar.

Sin tocar código todavía.
