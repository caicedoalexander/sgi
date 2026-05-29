# Auditoría de paridad — Módulos de flujo (ojos frescos sobre código vivo)

> **Fecha:** 2026-05-29 · **Alcance:** análisis, NO implementación (ningún código modificado).
> **Módulos auditados:** Invoice, Novelty, Advance, PettyCash, Refund, PaymentScheduling.
> **Excluye:** templates.
>
> **Metodología:** workflow multi-agente (14 agentes: 6 inventarios de módulo + 1 de infraestructura + 6 ejes transversales + 1 adversarial), más lectura directa de los 6 enums, las 6 APIs de coordinador y los paths críticos. Los agentes de inventario trabajaron **sin** el doc previo (`docs/auditoria-paridad-modulos-flujo.md`) para no contaminar los ojos frescos; el doc previo solo se usó en la fase adversarial, para retar sus "excepciones legítimas". Todo está citado con `archivo:línea`.
>
> **Relación con `docs/auditoria-paridad-modulos-flujo.md`:** aquel documenta el roadmap de remediación (Olas 0-5) ya ejecutado. Este es una re-auditoría independiente del **estado vivo post-remediación**, con postura de no asumir intocable ninguna decisión previa.

---

## 0. Resumen ejecutivo y veredicto fresco

La migración entregó una **columna vertebral real y sólida**: los 6 módulos tienen enum `Domain/{Modulo}/PipelineStatus`, Constants que delegan (`STATUS_X = PipelineStatus::X->value`), patrón State+Registry, ActionPolicy, History/Document service, y —el hallazgo estructural más fuerte— **una cola de pago idéntica**: los 6 terminan con `… → autorizacion_pago → verificacion_pago → {pagada|legalizada}`. La autorización es la pieza más madura: `AppController::_enforcePermission` es *fail-loud* (acción sin atributo → `LogicException` 500, `AppController.php:165-172`) y hay enforcement **por-paso real** (no solo CRUD) en los 6.

El veredicto diverge del doc previo en tres puntos:

1. **Invoice es el canon de RIQUEZA, no de LIMPIEZA.** Tratarlo como gold standard intocable es un error. Tiene deuda propia que ningún seguidor debería heredar: su enum es el **único sin `previous()`** (`Domain/Invoice/PipelineStatus.php:35-53`), su `InvoiceLockPolicy` **hace IO** pese a llamarse "Policy" (`InvoiceLockPolicy.php:31-43`), su `getNextStatus` mezcla State + DocumentTypePolicy (`InvoicePipelineService.php:155-160`), y casi todos los métodos de su enum están muertos. Para varios ejes, **PettyCash o Refund son mejores referentes que Invoice**.

2. **La deriva accidental real está concentrada (~13 ítems unificables).** Mucho de lo que parece divergencia es paridad consistente (todos hacen lo mismo) o diferencia esencial de dominio bien fundada.

3. **Un hallazgo trasciende lo cosmético y es de seguridad:** PettyCash y Refund **no autorizan por-paso la escritura de campos** del header (solo CRUD). Sube al primer lugar de prioridad.

---

## 1. Mapa estructural comparativo (módulo × artefacto)

Leyenda: ✅ presente/canónico · ⚠️ presente pero divergente · ❌ ausente · 🔵 ausencia legítima de dominio.

### Tabla A — Coordinador y API de transición

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| Clase coordinador | `InvoicePipelineService` ✅ | `NoveltyPipelineService` ✅ | `AdvanceLegalizationService` ⚠️ (no es coord. de pipeline) | `PettyCashPipelineService` ✅ | `RefundPipelineService` ✅ | `PaymentSchedulingPipelineService` ✅ |
| Líneas (≈) | 434 | 606 | 761 | **1057** ⚠️ fat | 616 | 534 |
| `saveAndAdvance` | ✅ `:211` | ✅ `:127` | ❌ | ✅ `:175` | ❌ (lo orquesta el controller) ⚠️ | 🔵 (no edita header) |
| `advance` (puro) | ❌ (plegado en edit) | ❌ | ❌ | ✅ `:293` | ✅ `:181` | ✅ `:132` |
| `regress` | ✅ `:318` | ❌ (tiene `reject` terminal) 🔵 | ❌ (verbos por outcome) 🔵 | ✅ `:800` | ✅ `:426` | ✅ `:208` |
| `reject` | ❌ (vía `area_approval`) | ✅ `:288` terminal 🔵 | ❌ | ❌ | ❌ | ⚠️ **en el controller** `:249` |
| `validateTransitionRequirements` firma | `(inv, fromStatus, overrides)` `:104` | `(nov, fromStatus)` `:322` | ❌ | **`(record)`** `:411` ⚠️ | **`(record)`** `:361` ⚠️ | `(sched, fromStatus)` `:106` |
| `getNextStatus` firma | `(string, ?docType)` `:145` | `(object, ?type)` `:69` 🔵 | ❌ (hardcode `_setStatus`) | `(string)` `:733` | `(string)` `:122` | `(string)` `:52` |
| Resolución de avance | State `:145` | State + skips por tipo `:46-63` | **hardcode literal** `_setStatus(…STATUS_X)` ⚠️ | State `:733` | State `:122` | **enum** `next()` `:52` ⚠️ |
| Retorna `ServiceResult` | ✅ | ⚠️ (`assignToLiquidationDoc` no) | ✅ | ✅ | ✅ | ✅ |
| Coordinador con IO/transacción inline | ⚠️ sí | ⚠️ sí | ⚠️ sí (fat) | ⚠️ sí (fat) | ⚠️ sí | ⚠️ sí |

### Tabla B — Suite de Pipeline (States / enum / Policies)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| Nº States | 7 | 9 | 7 | 6 | 6 | 5 |
| Pureza de States | ✅ (inyecta `InvoicePaymentService`) | ✅ (inyecta `NoveltyLiquidationGuard`) | ⚠️ **`ValidacionState` IO** `:33-60` | ✅ | ✅ | ⚠️ **`BorradorState` IO** `:30-33` |
| Registry resuelve enum→State | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Constants delegan al enum | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Enum tiene `previous()` | ❌ **(único sin)** | ✅ | ✅ | ✅ | ✅ | ✅ |
| Métodos enum vivos | solo `tryFrom` ⚠️ | `isTerminal` | ninguno ⚠️ | ninguno ⚠️ | `next` (en entidad) | `next`/`previous` ✅ |
| `next()/previous()` del **State** consumidos | ✅ | ✅ | ❌ vestigial | ✅ | ✅ | ❌ (usa enum) |
| `FieldAccessPolicy` | ✅ `Pipeline/Invoice/Policy/` | ✅ | 🔵 ausente (edita vía Invoices) | ✅ ⚠️ `unset($roleId)` | ✅ ⚠️ `unset($roleId)` | 🔵 ausente (no edita header) |
| `LockPolicy` | ⚠️ `src/Service/` raíz, **hace IO** | ❌ (sin regress) 🔵 | ❌ 🔵 | ⚠️ `src/Service/` raíz | ⚠️ `src/Service/` raíz | ⚠️ **stub** `→null` en coord. `:116` |
| `TransitionValidator` dedicado | ✅ raíz | ❌ (en States) | ❌ | ❌ (en States) | ✅ raíz | ❌ (en States) |
| `ActionPolicy` | ✅ 7 métodos | ✅ | ✅ 13 métodos | ✅ | ✅ | ✅ 3 métodos |
| `DocumentTypePolicy` | ✅ **exclusivo** (factory + 3 impl) | 🔵 | 🔵 | 🔵 | 🔵 | 🔵 |

### Tabla C — Servicios auxiliares (History / Document / Payment)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| `implements HistoryServiceInterface` | ✅ `:12` | ✅ `:14` | ❌ | ❌ | ❌ | ❌ |
| `use HistoryNormalizationTrait` | ✅ | ✅ | ❌ 🔵 | ✅ | ✅ | ❌ 🔵 |
| `recordChanges()` + fuente de campos | ✅ array **local** `:54-61` ⚠️ | ✅ itera `FIELD_LABELS` ⚠️ | 🔵 (audita explícito) | ✅ const `FIELDS_TO_TRACK` | ✅ const `FIELDS_TO_TRACK` | 🔵 |
| `DocumentUploadTrait` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `PaymentService` dedicado | ✅ `InvoicePaymentService` | ✅ `LiquidationDocPaymentService` | 🔵 (reusa `InvoicePaymentService`) | ❌ **inline en coord.** ⚠️ | ✅ `RefundPaymentService` | ❌ inline en coord. |
| Modelo de pago | tabla multi-fila + `idempotency_key` + `editPayment` | tabla multi-fila | invoice_payments vía eventos | columnas del record | columnas del record | materializa a hijas |
| Modelo de rechazo de pago | persiste `status`+motivo | ⚠️ **BORRA** `:166` | conserva | persiste motivo | persiste motivo | n/a (rechaza la programación) |

### Tabla D — Permisos y datos a vistas

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| CRUD vía `#[Permission]`+`$controllerModuleMap` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Enforcement por-paso (pagos) `#[PipelineAction(step)]` | ✅ 403 declarativo | ✅ | ✅ (markSigned/return) | ✅ | ✅ | ✅ |
| **Escritura de campos gateada por `canOperate`** | ✅ (base rol-aware) | ✅ | 🔵 | ❌ `unset($roleId)` | ❌ `unset($roleId)` | 🔵 |
| `ViewModel` en edit/add (`EditViewModelInterface`) | ✅ | ✅ | ⚠️ **no implementa interfaz**, `set($vm->build())` | ✅ | ✅ | ✅ |
| `$this->set()` crudo en index/view | ✅ (consistente) | ✅ | ✅ | ✅ | ✅ | ⚠️ + bug `$roleName` indefinido `:82` |
| Slug módulo ≠ slug pipeline | invoices=invoices | ⚠️ employee_novelties/novelty_liquidation_docs ≠ novelties/liquidation_docs | ⚠️ advances ≠ legalizations | petty_cash | refunds | payment_schedulings |

---

## 2. Patrón canónico propuesto

No "lo que hace Invoice", sino el mejor patrón derivado de todos. Estructura primero:

```
src/Service/
  {Modulo}PipelineService.php          ← coordinador DELGADO de aplicación
  Pipeline/
    PipelineFieldPolicy.php            ← base abstracta compartida (ya existe, PA-008)
    {Modulo}/
      {Modulo}PipelineState.php        ← interfaz del State
      {Modulo}PipelineStateRegistry.php
      State/{Estado}State.php          ← un archivo por estado, PUROS
      Policy/
        {Modulo}ActionPolicy.php       ← rol×paso + predicados de entidad
        {Modulo}FieldAccessPolicy.php  ← campos/secciones editables por paso (si edita header)
        {Modulo}LockPolicy.php         ← ★ MOVER aquí desde raíz
        {Modulo}TransitionValidator.php← ★ MOVER aquí (si existe)
      Guard/{Modulo}Guard.php          ← ★ encapsula el IO que un State necesita
  {Modulo}HistoryService.php           ← implements HistoryServiceInterface + trait + FIELDS_TO_TRACK const
  {Modulo}DocumentService.php          ← DocumentUploadTrait
  {Modulo}PaymentService.php           ← SOLO si hay agregado de pago con ciclo propio
src/Constants/
  {Modulo}Constants.php                ← STATUS_X = enum->value
  Domain/{Modulo}/PipelineStatus.php   ← FUENTE ÚNICA real (ver responsabilidades)
src/ViewModel/
  {Modulo}EditViewModel.php            ← implements EditViewModelInterface
```

**Responsabilidad de cada pieza:**

- **Enum `PipelineStatus`** — *fuente única efectiva*. Define cases, `label()`, `next()`, `previous()`, `isTerminal()`. **El State debe delegar a `enum::next()/previous()` en vez de hardcodear** (hoy hay dos representaciones paralelas). Las Constants (`STATUS_LABELS`, `PIPELINE_STATUSES`) deberían derivar del enum, no duplicarlo.
- **Coordinador `{Modulo}PipelineService`** — orquestación de aplicación: una transacción atómica que `(1)` filtra campos por paso (rol-aware), `(2)` valida, `(3)` persiste, `(4)` audita, `(5)` avanza/propaga. Verbos canónicos: `validateTransitionRequirements` / `saveAndAdvance` (si edita header) / `advance` / `regress`. Idealmente delgado, delegando persistencia; en la práctica todos hacen IO transaccional (aceptable mientras sea atómico).
- **States** — decisiones **in-memory puras**. Cuando necesitan BD, inyectan un **Guard** (`?? new`), nunca `TableRegistry` estático.
- **ActionPolicy** — compone `auth->canOperate(rol, pipeline, step)` con predicados de la entidad. Superficie específica del dominio (no se fuerza base común).
- **FieldAccessPolicy** — extiende `PipelineFieldPolicy`; **rol-aware** (no `unset($roleId)`): un rol sin `canOperate` del paso obtiene patch vacío.
- **LockPolicy / TransitionValidator** — decisiones puras de bloqueo/validación, **dentro de `Pipeline/{Modulo}/Policy/`** (hoy en raíz).
- **HistoryService** — `implements HistoryServiceInterface` + `HistoryNormalizationTrait` + `const FIELDS_TO_TRACK`.
- **PaymentService dedicado** — solo si existe un **agregado de pago con ciclo de vida propio** (múltiples filas, autorizar/rechazar/editar por pago): Invoice/Refund/Novelty-liquidación lo justifican; PettyCash/PaymentScheduling no.

---

## 3. Ejes de consistencia transversal

### Eje 1 — Validación de permisos
**Estado general: sólido y uniforme en infraestructura; un hueco real.** Las dos capas (`AuthorizationService` CRUD vs `PipelineAuthorizationService` por-paso) se componen vía `AuthorizationFacade`. Asimetría deliberada y correcta: el admin solo bypasea CRUD de `['users','roles']` (`AuthorizationService.php:34`); la capa por-paso **no** tiene bypass — el admin necesita filas en `pipeline_permissions` (riesgo de *seed*, no de código).

- **🔴 Hueco real:** PettyCash/Refund gatean la escritura de campos del header **solo con CRUD `edit`**, no con `canOperate` del paso. Sus `FieldAccessPolicy::filterEntityData` hacen `unset($roleId)` (`PettyCashFieldAccessPolicy.php:75`, `RefundFieldAccessPolicy.php:75`, cuyo docblock **admite** "el gate de rol queda como deuda separada"). Invoice/Novelty heredan la base rol-aware (`PipelineFieldPolicy.php:66-73`). Consecuencia: un rol con `can_edit` del módulo pero sin `canOperate` del paso **puede escribir** los campos de ese paso en PettyCash/Refund; en Invoice/Novelty obtendría patch vacío.
- Heterogeneidad menor: el gate por-paso de acciones `step=null` (advance/regress) vive a veces en el servicio (`denialReasonForX`) y a veces en la policy (`canX`) — funciona pero no es declarativo-uniforme.
- Redundancia inocua: `confirmPayment` de PettyCash/Refund/PaymentScheduling re-chequea `canOperateStep` inline pese a que el atributo ya lo validó.

### Eje 2 — Forma de pasar datos a las vistas
**Más uniforme de lo que parece.** Existe un canon explícito: `EditViewModelInterface` (`ViewModel/EditViewModelInterface.php:6-13`) lo implementan **6 VMs** (Invoice, Refund, PettyCash, EmployeeNovelty, NoveltyLiquidationDoc, PaymentScheduling). El patrón "ViewModel para edit/add + `$this->set()` crudo para index/view" es **idéntico en los 6** — es consenso, no deriva.
- **Único outlier: Advance.** `legalization()` entrega con `$this->set($vm->build())` (vars sueltas) en vez de `$this->set('viewModel',$vm)`, y `AdvanceLegalizationViewModel` **no implementa** `EditViewModelInterface` (`AdvancesController.php:340`). Su docblock afirma alineación que el código no completa.
- Ruido: `set('actionPolicy',…)` muerto en Advance (`:341`, ningún template lo consume); bug `$roleName` indefinido en PaymentScheduling (`:82`).

### Eje 3 — Frontera Service vs Controller
**En el camino feliz de transiciones reales, sólido:** los 6 mutan `pipeline_status` + auditan + propagan dentro de una transacción del coordinador. **Dos fugas graves** (transición de pipeline fuera del coordinador, sin transacción):
- **🔴 PaymentScheduling `reject()` en el controller** (`PaymentSchedulingsController.php:263-272`): `pipeline_status=REJECTION_TARGET` + `save` + `recordStatusChange` inline, sin transacción — asimétrico con su propio advance/regress/confirmPayment que sí delegan.
- **🔴 Novelty `assignLiquidation` rama `existing_doc_id`** (`EmployeeNoveltiesController.php:1006-1023`): transición a CONTABILIDAD con dos saves sin transacción, duplicando `NoveltyPipelineService::assignToLiquidationDoc`.
- Estructural: **Refund no tiene `saveAndAdvance`** — el guardar+avanzar vive en `RefundsController::edit:398-418` (dos transacciones separadas).
- Fugas menores **consistentes** (bajo riesgo): auditoría de creación inicial y de documentos invocada desde el controller en los 5 módulos no-Advance.

### Eje 4 — Nomenclatura
**Mayormente convergido.** 5/6 cumplen `{Modulo}PipelineService`; idioma de slugs 100% consistente (estados en español sin acentos; técnicos de pago/firma en inglés; labels DIAN/approval en español capitalizado). Divergencias:
- Verbo `reject` colisiona semánticamente: **terminal** en Novelty (`→RECHAZADA`) vs **regresión-a-tesorería** en PaymentScheduling (`REJECTION_TARGET=TESORERIA`) — esencial, pero confunde.
- Mezcla `Advance*` (controller/enum/dir/ruta) vs `AdvanceLegalization*` (clases) — parcialmente esencial (dos entidades), parcialmente irregular.
- Literal crudo `'d. liquidacion'` sin constante (`NoveltyDocumentService.php:178,215`); firma divergente de `validateTransitionRequirements` (Refund/PettyCash sin `fromStatus`).

---

## 4. Clasificación de divergencias (A / B / C)

### (A) Deriva accidental → UNIFICAR

| # | Divergencia | Evidencia | Riesgo datos/contratos |
|---|---|---|---|
| A1 🔴 | **PettyCash/Refund no gatean por-paso la escritura de campos** (`unset($roleId)`) | `PettyCashFieldAccessPolicy.php:75`, `RefundFieldAccessPolicy.php:75` vs base `PipelineFieldPolicy.php:66-73` | Ninguno en datos. Es **endurecimiento de autorización**. ⚠️ Antes de ejecutar: auditar `pipeline_permissions` para que los roles que hoy editan tengan `can_operate=true` en sus pasos, o perderían la edición (cambio de comportamiento) |
| A2 | **Refund sin `saveAndAdvance`** (orquestado en el controller) | `RefundsController.php:398-418` vs `PettyCashPipelineService.php:175` | Ninguno; cierra ventana de dos transacciones |
| A3 | **PaymentScheduling `reject()` en el controller** sin transacción | `PaymentSchedulingsController.php:263-272` | Bajo: preservar `REJECTION_TARGET='tesoreria'` exacto en el `recordStatusChange` |
| A4 | **Novelty `assignLiquidation` duplicada controller/servicio**, dos saves sin transacción | `EmployeeNoveltiesController.php:1006-1023` vs `NoveltyPipelineService.php:362` | Ninguno; mismos valores, añade atomicidad |
| A5 | **Doble fuente de la máquina lineal**: enum `next()/previous()` vs State hardcode; una queda muerta en cada módulo | `PaymentScheduling` usa enum `:54,59`; los demás usan State (enum muerto) | Ninguno (mismos slugs). Canon: que el State **delegue al enum** |
| A6 | **Métodos del enum muertos** (`label`/`pipelineCases`/`legalizationCases`/`rejectionTarget`) — duplicados por Constants | Grep 0 callers; `rejectionTarget()` eludido por `PaymentSchedulingConstants.php:43` re-hardcodeado | Borrar muertos: ninguno. Mejor: Constants delegan al enum |
| A7 | **Ubicación de LockPolicy/TransitionValidator en `src/Service/` raíz** en vez de `Pipeline/{Modulo}/Policy/` | `InvoiceLockPolicy.php`, `PettyCashLockPolicy.php`, `RefundLockPolicy.php`, `*TransitionValidator.php` | Ninguno funcional; rename de namespace + wiring en `Application.php` |
| A8 | **Pureza de States rota** en Advance/PaymentScheduling (IO directo) | `ValidacionState.php:33-60`, `BorradorState.php:30-33` vs `NoveltyLiquidationGuard` | Ninguno; extraer a Guard inyectado |
| A9 | **Advance `set($vm->build())` + VM no implementa `EditViewModelInterface`** | `AdvancesController.php:340`, `AdvanceLegalizationViewModel.php:24` | Ninguno (capa presentación) |
| A10 | **`set('actionPolicy')` muerto** + bug `$roleName` indefinido | `AdvancesController.php:341`, `PaymentSchedulingsController.php:82` | Ninguno |
| A11 | **Novelty `authorizePayment` retorna array** en vez de `ServiceResult` (sus hermanos sí) | `LiquidationDocPaymentService.php:100` vs `:148,:195` | Ninguno; ajustar único caller |
| A12 | **Literal `'d. liquidacion'` sin constante** | `NoveltyDocumentService.php:178,215` | ⚠️ Medio: extraer constante con el **valor idéntico** (`'d. liquidacion'`, con punto y espacio); NO cambiar el slug (rompe `novelty_documents.pipeline_status`) |
| A13 | **Redundancia `confirmPayment`**: `canOperateStep` inline pese al atributo | `PettyCashRecordsController.php:447`, `RefundsController.php:599`, `PaymentSchedulingsController.php:318` | Ninguno; quitar el inline |

### (B) Diferencia esencial del dominio → NO UNIFICAR

| # | Divergencia | Por qué es esencial (leído del código) | Riesgo si se unifica |
|---|---|---|---|
| B1 | **Advance reusa `InvoicePipelineService`; no es `*PipelineService`** | Un Anticipo **es** un Invoice (`document_type=Anticipo`, persiste en `invoices`); `AdvanceLegalizationService` gobierna el **sub-pipeline de legalización** sobre `advance_legalizations` (otra entidad). Son dos máquinas de estado. *Matiz:* el State `Pipeline/Advance` **no es vestigial** — se consume en `AdvanceLegalizationService` `moveToRevisionFirmas:227` (`validateAdvance`); lo vestigial es `getNextStatus/getPreviousStatus` de esos States | Alto: rompería el modelo de dos entidades |
| B2 | **Advance sin FieldAccessPolicy; verbos por outcome** (`markExact/registerShortage/registerSurplus`) | `AdvancesController::edit:349-353` es redirect puro a Invoices. El flujo **bifurca por `case_type`**: `Domain/Advance/PipelineStatus.php:36-46` devuelve `null` en CONTABILIDAD/TESORERIA porque `next()` lineal no aplica. Un `advance()` genérico no puede expresar exacto/faltante/sobrante | Alto: rompería rutas de outcome y la máquina ramificada |
| B3 | **PaymentScheduling sin FieldAccessPolicy** | No edita campos del header por paso (solo items vía import); ningún caller de `getEditableFields/filterEntityData` | Sería dead code |
| B4 | **Novelty `getNextStatus(object,$type)` + `reject` terminal + `advanceGroup`** | Skips condicionales: salta APROBACION si `!requires_boss_approval` (`:55`), GDP si `!requires_employee_signature_review` (`:58`) — el `$type` lleva semántica que `(string)` no expresa. `reject→RECHAZADA` (único enum con ese case) es fin-de-vida, distinto de `regress`. `advanceGroup` opera sobre el `NoveltyLiquidationDoc` (lote) | Defecto funcional; rompería el cálculo de avance |
| B5 | **`editPayment`/`idempotency_key` solo en Invoice** | Invoice tiene `invoice_payments` multi-fila con pagos parciales; Refund/PettyCash modelan **un pago bulk en columnas** (idempotencia = guard `!empty(banking_entity_id)` + `FOR UPDATE`, `RefundPaymentService.php:102`). No hay fila que editar | Migración mayor; convertir columnas en tablas |
| B6 | **PaymentService dedicado vs inline** (heurística) | Se extrae solo si hay agregado de pago con ciclo propio: Invoice/Refund/Novelty sí; PettyCash/PaymentScheduling no (pago = atributo del record / proyección a hijas). *Salvedad: ver C2* | Abstracción especulativa |
| B7 | **Admin sin bypass en la capa por-paso** | Decisión de gobernanza (cleanup 2026-05-02): el pipeline se gobierna por `pipeline_permissions` para todos | Reintroduce el bypass eliminado |
| B8 🔒 | **Trampa de spelling `DIAN_REJECTED='Rechazado'` vs `APPROVAL_REJECTED='Rechazada'`** | **Riesgo CONFIRMADO**: son dos columnas (`dian_validation` y `area_approval`); cada literal **es el valor persistido**. `InvoicesTable.php:209` valida `inList(DIAN_STATUSES)` → re-guardar fallaría; `isRejected` compara contra `'Rechazada'` | **Alto**: requiere `UPDATE` + deploy atómico. NO tocar sin migración |
| B9 | **History de Advance/PaymentScheduling sin `recordChanges`** | No hacen save de header por paso → no hay patch que diffear; auditan campo-a-campo explícito (montos/comprobantes) que es **más** preciso | Dead code |
| B10 | **ActionPolicy sin base abstracta común** (superficies divergentes: 7/13/3 métodos) | Cada una compone el mismo `canOperate` (ya centralizado en Facade) con predicados de entidad intrínsecamente específicos del flujo | Acoplamiento artificial |
| B11 | **DocumentService: variaciones sobre `DocumentUploadTrait`** (anti-IDOR Refund, huérfanos+firma Advance, doc liquidación Novelty) | Núcleo unificado en el trait; las extensiones son de dominio | Sin ganancia |

### (C) Zona gris → REQUIERE DECISIÓN

| # | Divergencia | El dilema | Riesgo |
|---|---|---|---|
| C1 | **Modelo de rechazo de pago de Novelty BORRA** el pago (`LiquidationDocPaymentService.php:166`) vs los demás persisten motivo | El patrón deseable es persistir (auditabilidad), pero la tabla `liquidation_doc_payments` **carece de columnas `status`/`rejection_reason`** (migración `20260414000001`): unificar es **migración de esquema + cambio de comportamiento**, no refactor | Alto: migración; los rechazos históricos ya no existen en BD |
| C2 | **PettyCash sin PaymentService dedicado** pese a ser casi idéntico a Refund (que sí lo tiene) | Es el caso más claro de migración incompleta dentro del grupo "B6" → candidato a extraer `PettyCashPaymentService` espejando `RefundPaymentService` | Ninguno; reorganización de código |
| C3 | **Mezcla naming `Advance*` vs `AdvanceLegalization*`** | El prefijo largo es correcto para clases sobre `advance_legalizations`, pero el **dir/enum** se llaman `Advance` — inconsistencia que no refleja dominio. ¿Renombrar dir/enum a `AdvanceLegalization` o documentar el split? | Medio-alto en slug `advances`/tablas (persistido); las clases PHP son seguras de renombrar |
| C4 | **`InvoiceLockPolicy` hace IO** (`isLockedByPaidScheduling`, `:31-43`); sus hermanas son puras | ¿Tolerar IO en esta Policy (única con dimensión cross-tabla) o extraer a un Guard como Novelty? | Ninguno |
| C5 | **History: 4 combinaciones** de (interface+trait+recordChanges) | Lo unificable: que Refund/PettyCash `implements HistoryServiceInterface` (trivial) y que Invoice use `const FIELDS_TO_TRACK` (hoy array local `:54-61`). **Nota:** `MEMORY.md` dice "record* devuelve bool" pero **todos devuelven `void`** (incl. la interfaz `:17`) — la memoria está desactualizada | Ninguno en datos |
| C6 | **Auditoría de creación inicial / documentos en el controller** (5 módulos) | Consistente (no outlier); encapsular en `create()`/que el `DocumentService` audite sería más puro pero es transversal de bajo retorno | Ninguno |
| C7 | **Solo Invoice cablea States en DI explícitamente**; los demás autoresuelven (`?? new`) | Afecta testabilidad uniforme, no comportamiento | Ninguno |
| C8 | **Novelty `advance()` con `#[Permission(edit)]`**: al fallar `canOperate` guarda campos y **omite el avance en silencio** (no 403, no warning) | Contrato UX de "guardar sin poder avanzar". Mínimo: emitir warning. El step NO puede ir en el atributo (skips por tipo) | Ninguno en datos |

> **Reconciliación de ejes sobre A1:** el eje de Permisos lo clasificó como **A** (deriva con consecuencia de seguridad); el eje de Pipeline-Suite como **C** (pendiente verificar que el gate upstream cubre todos los saves). El código resuelve la duda: el docblock de `RefundFieldAccessPolicy` **admite** que el gate de rol es "deuda separada", y el save de campos en `edit()` corre con filtrado role-blind antes del gate de avance. Por eso se deja en **A con caveat de verificación de seed** (no en C): es un endurecimiento legítimo, condicionado a auditar `pipeline_permissions` antes de ejecutar.

---

## 5. Priorización del refactor

**Ola 1 — Seguridad y atomicidad (prioridad máxima).** Riesgo real, esfuerzo bajo-medio.
1. **A1** — cerrar el hueco de autorización de escritura de campos en PettyCash/Refund (rol-aware), **previa auditoría de `pipeline_permissions`** para no romper edición legítima.
2. **A3 + A4** — mover las dos transiciones que viven en el controller a un método transaccional del coordinador (PaymentScheduling `reject`, Novelty `assignLiquidation`). Cierra ventanas de inconsistencia.
3. **C1** — *decisión*: ¿migrar `liquidation_doc_payments` para persistir el rechazo de pago, o documentar el borrado como limitación?

**Ola 2 — Convergencia de la máquina de estados.** Esfuerzo medio, riesgo bajo (sin datos).
4. **A5 + A6** — una sola fuente de transición lineal: que los States deleguen a `enum::next()/previous()` y las Constants deriven del enum; eliminar métodos/representaciones muertas. Resuelve también el outlier del enum de Invoice sin `previous()`.
5. **A8** — extraer el IO de `ValidacionState`/`BorradorState` a Guards inyectados.
6. **A2** — `RefundPipelineService::saveAndAdvance` (alinea con Invoice/PettyCash).

**Ola 3 — Organización y nomenclatura.** Esfuerzo bajo, riesgo bajo.
7. **A7** — mover Lock/TransitionValidator a `Pipeline/{Modulo}/Policy/` (decidir convención primero).
8. **A9 + A10 + A11 + A13** — fixes puntuales de presentación/contrato (Advance VM, sets muertos, `$roleName`, `ServiceResult`, doble `canOperateStep`).
9. **A12** — constante para `'d. liquidacion'` (valor idéntico).
10. **C2** — *decisión*: extraer `PettyCashPaymentService` espejando Refund.

**Ola 4 — Higiene de bajo retorno (opcional).** **C5** (interface/trait de History + corregir `MEMORY.md`), **C7** (DI explícita de States), **C6** (auditoría en servicios), **C3/C4** (decisiones de naming/Guard).

**NO tocar sin migración explícita:** B8 (spelling DIAN persistido), C3-slug `advances`/tablas, y todas las B (esenciales de dominio).

---

## 6. Verificaciones pendientes antes de ejecutar

Señaladas por el agente adversarial; conviene cerrarlas antes de cualquier remediación:

- Contar filas reales con `dian_validation='Rechazado'` / `area_approval='Rechazada'` antes de cualquier rename (B8).
- Confirmar que ningún rol tiene `can_edit` en `refunds`/`petty_cash` sin `can_operate` de sus pasos (dimensiona el impacto de A1).
- Revisar `tests/TestCase/Service/InvoiceLockPolicyTest.php`, `tests/TestCase/Service/Pipeline/Invoice/State/*Test.php` y `InvoiceActionPolicyTest.php` antes de tocar States/enum.
- Revisar `NoveltyLiquidationDocsController` (2º controller de Novelty) y el audit trail de edición de items de PaymentScheduling antes de tocar esos coordinadores.
- Revisar `config/Migrations/*CreateInvoices*` (tipo/longitud de columna `dian_validation`) y confirmar que `inList` es la única barrera antes de renombrar el literal DIAN.

---

## 7. Estado de ejecución — Olas 1-3 (2026-05-29)

> **Estado:** ejecutado en `main`. Esta sección es el **delta de implementación** sobre la línea base "as-audited" de las secciones 0-6 (que se conservan sin tocar como retrato del estado pre-refactor). Cada celda actualizada cita el ítem que la movió en la columna **Δ**.

### Resumen por ola

| Ola | Foco | Ítems ejecutados | Commits | Resultado |
|---|---|---|---|---|
| **1** | Seguridad y atomicidad | A1, A3, A4 | `69f4b88` (A1), `7695031` (A3), `8781d00` (A4) | ✅ Suite verde · `php -l` limpio · 0 violaciones cs nuevas · comportamiento preservado. **C1 queda como decisión pendiente** (requiere migración de esquema) |
| **2** | Convergencia de la máquina de estados | A8, A2, A5 (+A6) | `2a0fe27` (A8), `121bdd3` (A2), `14ee4f6` (A5+A6) | ✅ Suite verde · comportamiento preservado. **A6 resultó no-op de código** (ver nota) |
| **3** | Organización y fixes | A10, A11, A12, A13, A7 | `048da87` (A10/A11/A12/A13), `6789ad3` (A7) | ✅ **215/215 PHPUnit verde** · `php -l` limpio · 0 cs nuevas. **A9 diferido**; **C2 decisión pendiente** |

**Matices de ejecución a registrar:**

- **A6 fue no-op de código.** Los métodos del enum reportados como "muertos" tienen *callers de test*, y las Constants **no pueden** delegar al enum por el límite de PHP sobre expresiones constantes (`const X = Enum::CASE->value` es válido, pero derivar arrays/labels en tiempo de compilación no lo es). Se cerró documentalmente junto a A5 (`14ee4f6`); el enum sigue siendo fuente única efectiva del **grafo de transición** (los States ya delegan), no de los labels/listas de las Constants.
- **A13 sin cambio observable.** El atributo `#[PipelineAction(step)]` ya gateaba la acción **antes** de entrar al cuerpo del método, por lo que la verificación inline `canOperateStep` era estrictamente redundante; su eliminación no altera comportamiento (defensa en profundidad → defensa única declarativa).
- **A9 diferido** (no ejecutado): el VM de Advance (`AdvanceLegalizationViewModel`) sigue sin implementar `EditViewModelInterface` y `legalization()` aún entrega con `$this->set($vm->build())` (`AdvancesController.php:340`). Es un rewrite de un template de ~765 líneas: **alto riesgo, cero cambio de comportamiento** → no entró en estas olas.

### Cuadros actualizados (post-refactor)

Leyenda: ✅ presente/canónico · ⚠️ presente pero divergente · ❌ ausente · 🔵 ausencia legítima de dominio. La columna **Δ** señala el ítem de remediación que cambió la celda respecto a la versión as-audited (sección 1).

#### Tabla A — Coordinador y API de transición (post)

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

#### Tabla B — Suite de Pipeline (post)

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

#### Tabla C — Servicios auxiliares (post)

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

#### Tabla D — Permisos y datos a vistas (post)

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

### Estado de divergencias

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

> **Nota de versionado.** La auditoría inicial (secciones 0-6) se conserva intacta como línea base **"as-audited"** (estado pre-refactor, 2026-05-29). Esta sección 7 es el **delta ejecutado** de las Olas 1-3. Lo que sigue abierto para olas posteriores: **A9** (⏸), **C1/C2** (◻ decisión), más toda la Ola 4 de higiene de bajo retorno (C5/C6/C7 + C3/C4) y los ítems marcados "NO tocar sin migración" (B8, slug `advances`/tablas, y las B esenciales de dominio).
