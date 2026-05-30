# Avance de ejecución — Paridad de módulos de flujo (Olas 1-4)

> **Fecha:** 2026-05-29 · **Estado:** ejecutado en `main`.
>
> Documento **compañero** de [`auditoria-estructural-fresca-2026-05-29.md`](auditoria-estructural-fresca-2026-05-29.md) (la auditoría base "as-audited", que se conserva intacta como retrato del estado **pre-refactor**). Aquí se registra el **delta de implementación** de las Olas 1-4 del roadmap de esa auditoría: qué se ejecutó, con qué commits, y los 4 cuadros comparativos actualizados al estado **post-refactor**. Con la Ola 4 queda **cerrado** el roadmap accionable: solo restan las divergencias **B** y `NO tocar` (requieren migración de datos) y **C6**, omitido por decisión.
>
> Para el mapa estructural original, el patrón canónico, la clasificación A/B/C y la priorización, ver el informe base.

---

## Cómo leer las señales (⚠️/🔵 no significan «pendiente»)

Cada señal codifica **dos cosas a la vez**: si el artefacto está *presente* y si *hay algo que hacer*. Leerlas como "todo lo que no es ✅ es trabajo pendiente" es el malentendido a evitar.

| Señal | ¿Presente? | ¿Conforme al canon? | ¿Acción? |
|---|---|---|---|
| ✅ | Sí | Sí | **No** — ya está como debe |
| ⚠️ | Sí | No (difiere del canon) | **Depende** — ver regla de oro |
| ❌ | No | — | **Casi nunca** — solo significa "no lo tiene" |
| 🔵 | No | Sí: *el dominio no lo pide* | **No, nunca** — ausencia legítima |
| n/a | El concepto **no aplica** al módulo | — | **No** — nada que evaluar |

**🔵 ≠ ❌, y n/a ≠ ❌.** `❌` = "no existe" (dato neutro); `🔵` = "no existe **y está bien**, el módulo no lo necesita por su dominio"; `n/a` = "ese concepto ni siquiera tiene sentido aquí" (p. ej. *Modelo de rechazo de pago* en PaymentScheduling, que rechaza la programación entera, no pagos individuales).

**Regla de oro — qué se migra y qué no.** Lo único que se migra es la **deriva accidental (clase A)**: el mismo problema resuelto de formas distintas sin razón de dominio. **Toda la clase A ya se ejecutó en las Olas 1-4** (por eso hoy figura como ✅). Por lo tanto:

- **Todo 🔵 restante = NO accionable.** Ausencia legítima por dominio; "migrarlo" sería crear una abstracción vacía de un solo caso (dead code). La re-verificación adversarial 2026-05-30 intentó refutar cada 🔵 como deriva disfrazada y **falló en todos**.
- **Todo ⚠️ restante = NO accionable salvo decisión puntual.** Es **diferencia esencial de dominio (clase B)** —unificarla rompería el dominio o **datos persistidos** (valores de estado en BD, slugs en `permissions`/`pipeline_permissions`, URLs bookmarkeadas)— o una **divergencia cosmética tolerada** (p. ej. la forma de una firma) sin beneficio que justifique el churn.

En resumen: **los ⚠️ y 🔵 que quedan describen el estado final aceptado, no una lista de tareas.** Solo se tocan por una decisión explícita y acotada (como la alineación de `assign*` a `ServiceResult`, registrada en la re-verificación 2026-05-30). La clasificación A/B/C completa y el porqué de cada caso están en el [informe base](auditoria-estructural-fresca-2026-05-29.md).

---

## Resumen por ola

| Ola | Foco | Ítems ejecutados | Commits | Resultado |
|---|---|---|---|---|
| **1** | Seguridad y atomicidad | A1, A3, A4 | `69f4b88` (A1), `7695031` (A3), `8781d00` (A4) | ✅ Suite verde · `php -l` limpio · 0 violaciones cs nuevas · comportamiento preservado. **C1 queda como decisión pendiente** (requiere migración de esquema) |
| **2** | Convergencia de la máquina de estados | A8, A2, A5 (+A6) | `2a0fe27` (A8), `121bdd3` (A2), `14ee4f6` (A5+A6) | ✅ Suite verde · comportamiento preservado. **A6 resultó no-op de código** (ver nota) |
| **3** | Organización y fixes | A10, A11, A12, A13, A7 | `048da87` (A10/A11/A12/A13), `6789ad3` (A7) | ✅ **215/215 PHPUnit verde** · `php -l` limpio · 0 cs nuevas. **A9 diferido**; **C2 decisión pendiente** |
| **4** | Higiene, decisiones y cierre | C1, C2, C4, C5, C7, C8, A9, C3 (C6 omitido) | `9cc17c3` (C1), `70546df` (C2+C7), `ff81a7f` (C4), `d4a259e` (C5), `c9c2f64` (A9), `89c6c11` (C3), `73211aa` (fix), `4fdb988` (C8) | ✅ **219/219 PHPUnit verde** · `php -l` limpio · 0 cs nuevas (gate `src/`+`tests/`) · **contenedor DI resuelto en runtime** (8 servicios). Revisión de calidad adversarial detectó y cerró **3 regresiones** derivadas de C1. Con C8 la auditoría queda **100 % saldada** (todo A/C ejecutado o decidido) |

**Matices de ejecución a registrar:**

- **C1 fue un cambio de comportamiento real, no solo migración.** Persistir la fila rechazada (en vez de borrarla con `delete()`) reabrió caminos que el borrado ocultaba. La revisión de calidad adversarial los detectó y se cerraron en `73211aa`: (1) `authorizePayment` podía autorizar un pago **rechazado** (faltaba guard de `status` → corrupción del doc), (2) `view.php` pintaba los rechazados como "Pendiente", (3) el "Total Pagado" del element compartido sumaba montos rechazados. Los fixes (2) y (3) son **transversales** (benefician a Invoice/Refund/PettyCash, que también persisten rechazos). La migración `20260529120000` **debe ejecutarse** (`php bin/cake migrations migrate`) en cada entorno: es aditiva (`status` enum + `rejection_reason`, sin tocar `authorized`).
- **A9 estaba mal estimado.** El "rewrite de ~765 líneas" era falso: el cuerpo del template ya consume las vars de `build()`; bastó implementar la interfaz (3 props), cambiar 1 línea del controller y prepender un bloque de destructuring en la cabecera. El cuerpo quedó **intacto** (diff solo en cabecera).
- **C2+C7 en un solo commit** (`70546df`) por compartir `Application.php` y `PettyCashPipelineService` (al extraer el PaymentService se quitaron `events`/`invoiceHistory` ya sin uso del coordinador, lo que cambió su firma y su registro DI). El wiring se verificó resolviendo los 8 coordinadores/servicios desde el contenedor real.
- **C3 y C6 cerrados sin código de producción.** C3 = nota de naming en `CLAUDE.md` (decisión: no renombrar). **C6 omitido**: el patrón de auditoría desde el controller es consistente en los 5 módulos (no outlier), no hay `create()` de servicio donde encapsularlo, no hay tests que lo cubran y el retorno es puramente estético.
- **C8 (cierre, `4fdb988`).** Novelty `advance()` pasó de `#[Permission(edit)]` a `#[PipelineAction(pipeline: PIPELINE_NOVELTIES)]`, alineándose con su propio `reject()` y con el advance de Refund/PettyCash/PaymentScheduling (todos usan `#[PipelineAction(pipeline)]` **sin step**; AppController salta el gate CRUD y el servicio se auto-gatea vía `canOperate`). Se verificó que la escritura de campos ya está gateada (`PipelineFieldPolicy::getEditableFields` → patch vacío sin `canOperate`), por lo que el cambio es seguro y además corrige una sobre-restricción latente (un rol con `canOperate` del paso pero sin CRUD `edit` quedaba bloqueado con 403). El avance denegado ya no se omite en silencio: surfacea el motivo `UNAUTHORIZED` como warning. Test unitario nuevo.
- **A6 fue no-op de código** (Ola 2): los métodos del enum reportados como "muertos" tienen *callers de test*, y las Constants no pueden delegar al enum por el límite de PHP sobre expresiones constantes. Cerrado documentalmente.
- **A13 sin cambio observable** (Ola 3): el atributo `#[PipelineAction(step)]` ya gateaba antes del cuerpo, la verificación inline era redundante.

---

## Cuadros actualizados (post-refactor)

Leyenda: ✅ presente/canónico · ⚠️ presente pero divergente · ❌ ausente · 🔵 ausencia legítima de dominio. **Semántica de acción** (qué es accionable y qué no) en la sección «Cómo leer las señales» del inicio del documento. La columna **Δ** señala el ítem de remediación que cambió la celda respecto a la versión as-audited (sección 1 del informe base).

### Tabla A — Coordinador y API de transición (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| Clase coordinador | `InvoicePipelineService` ✅ | `NoveltyPipelineService` ✅ | `AdvanceLegalizationService` ⚠️ (no es coord. de pipeline) | `PettyCashPipelineService` ✅ | `RefundPipelineService` ✅ | `PaymentSchedulingPipelineService` ✅ | — |
| `saveAndAdvance` | ✅ `:211` | ✅ `:127` | ❌ | ✅ `:175` | ✅ **`:144`** | 🔵 (no edita header) | **A2** |
| `advance` (puro) | ❌ (plegado en edit) | ❌ | ❌ | ✅ `:293` | ✅ `:181` | ✅ `:132` | — |
| `regress` | ✅ `:318` | ❌ (tiene `reject` terminal) 🔵 | ❌ (verbos por outcome) 🔵 | ✅ `:800` | ✅ `:426` | ✅ `:208` | — |
| `reject` | ❌ (vía `area_approval`) | ✅ `:288` terminal 🔵 | ❌ | ❌ | ❌ | ✅ **en el coordinador** `:330` (transaccional) | **A3** |
| `validateTransitionRequirements` firma | `(inv, fromStatus, overrides)` `:106` | `(nov, fromStatus)` `:328` | ❌ | `(record)` `:402` ⚠️ | `(record)` `:498` ⚠️ | `(sched, fromStatus)` `:106` | **re-verif. 2026-05-30** |
| `getNextStatus` firma | `(string, ?docType)` `:145` | `(object, ?type)` 🔵 | ❌ (hardcode `_setStatus`) | `(string)` | `(string)` | `(string)` | — |
| Resolución de avance | State `:145` | State + skips por tipo 🔵 | hardcode literal `_setStatus(…)` ⚠️ | State (→ enum) | State (→ enum) | enum `next()` | **A5** (States ahora delegan al enum; ya no hay doble fuente) |
| Retorna `ServiceResult` | ✅ | ✅ **(ambos `assign*` → `ServiceResult`)** | ✅ | ✅ | ✅ | ✅ | **A4** + **re-verif. 2026-05-30** |
| Coordinador posee la transacción (IO inline, States puros) | ✅ canónico | ✅ canónico | ⚠️ *fat* | ⚠️ *fat* | ✅ canónico | ✅ canónico | **re-verif. 2026-05-30** |

> **Re-verificación 2026-05-30 (Tabla A).** Auditoría fresca de los puntos ⚠️/🔵 contra código vivo (workflow multi-agente: verificación + reto adversarial). **(1) Fila "Coordinador posee la transacción":** que el coordinador abra la transacción inline con States puros **es el patrón canónico** —el propio Invoice lo hace (`InvoicePipelineService:247,354`)—, no una divergencia per-módulo; la versión as-audited la marcaba ⚠️ en los 6, confundiendo "uniforme/aceptado" con "a remediar". El ⚠️ subsiste solo para **Advance** (761 líneas, 6 `transactional()`) y **PettyCash** (~743 líneas tras C2, antes ~1057) por **concentración de responsabilidades** (*fat*), no por la transacción en sí. **(2) Retorno `ServiceResult` (Novelty):** la verificación detectó que `assignToExistingLiquidationDoc` **no** retornaba `ServiceResult` (ambos `assign*` devolvían `object|array`); se **alineó el contrato** de los dos métodos a `ServiceResult` (servicio + controller + 3 tests; suite **219/219**). **(3) Refs `validateTransitionRequirements`** actualizadas a líneas vivas (Invoice `:106`, Novelty `:328`, PettyCash `:402`, Refund `:498`). El resto de ⚠️/🔵 se confirmó intacto.

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
| `LockPolicy` | ✅ **`Pipeline/Invoice/Policy/`** (pura; IO en `InvoiceLockGuard`) | ❌ (sin regress) 🔵 | ❌ 🔵 | ✅ **`Pipeline/PettyCash/Policy/`** | ✅ **`Pipeline/Refund/Policy/`** | ⚠️ stub `→null` en coord. | **A7, C4** |
| `TransitionValidator` dedicado | ✅ **`Pipeline/Invoice/Policy/`** | ❌ (en States) | ❌ | ❌ (en States) | ✅ **`Pipeline/Refund/Policy/`** | ❌ (en States) | **A7** |
| `ActionPolicy` | ✅ 7 métodos | ✅ | ✅ 13 métodos | ✅ | ✅ | ✅ 3 métodos | — |
| `DocumentTypePolicy` | ✅ exclusivo (factory + 3 impl) | 🔵 | 🔵 | 🔵 | 🔵 | 🔵 | — |

> **Nota A7:** los 5 artefactos movidos (`InvoiceLockPolicy`, `InvoiceTransitionValidator`, `RefundLockPolicy`, `RefundTransitionValidator`, `PettyCashLockPolicy`) ahora viven bajo `src/Service/Pipeline/{Invoice,Refund,PettyCash}/Policy/` con su namespace renombrado; **`src/Service/` raíz ya no contiene ningún `*LockPolicy`** (verificado por Glob). Solo cambió ubicación/namespace + wiring; sin cambio funcional. **C4 (`ff81a7f`):** el IO de `InvoiceLockPolicy` (`isLockedByPaidScheduling`, lock cross-tabla factura→programación pagada) se extrajo a `InvoiceLockGuard` inyectado (`?? new`, espejo del patrón Guard de A8); la Policy quedó pura como sus hermanas y se cubrió esa rama con un test unitario (Guard fake).

### Tabla C — Servicios auxiliares (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| `implements HistoryServiceInterface` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **C5** |
| `use HistoryNormalizationTrait` | ✅ | ✅ | ❌ 🔵 | ✅ | ✅ | ❌ 🔵 | — |
| `recordChanges()` + fuente de campos | ✅ const `FIELDS_TO_TRACK` | ✅ itera `FIELD_LABELS` ⚠️ | 🔵 (audita explícito) | ✅ const `FIELDS_TO_TRACK` | ✅ const `FIELDS_TO_TRACK` | 🔵 | **C5** (Invoice ya no usa array local) |
| `DocumentUploadTrait` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `PaymentService` dedicado | ✅ `InvoicePaymentService` | ✅ `LiquidationDocPaymentService` | 🔵 (reusa `InvoicePaymentService`) | ✅ `PettyCashPaymentService` | ✅ `RefundPaymentService` | ❌ inline en coord. 🔵 (sin agregado de pago propio) | **C2** |
| `authorizePayment` retorna `ServiceResult` | ⚠️ array (`InvoicePayment`, propio) | ✅ **`:100`** | n/a | ✅ | ✅ | ✅ | **A11** (Novelty/`LiquidationDocPaymentService`) |
| Modelo de pago | tabla multi-fila + `idempotency_key` + `editPayment` | tabla multi-fila | invoice_payments vía eventos | columnas del record | columnas del record | materializa a hijas | — |
| Modelo de rechazo de pago | persiste `status`+motivo | ✅ **persiste `status`+motivo** (ya no borra) | conserva | persiste motivo | persiste motivo | n/a (rechaza la programación) | **C1** (`9cc17c3` + fix `73211aa`) |

### Tabla D — Permisos y datos a vistas (post)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling | Δ |
|---|---|---|---|---|---|---|---|
| CRUD vía `#[Permission]`+`$controllerModuleMap` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Enforcement por-paso (pagos) `#[PipelineAction(step)]` | ✅ 403 declarativo | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| **Escritura de campos gateada por `canOperate`** | ✅ (base rol-aware) | ✅ | 🔵 | ✅ **(gate por-paso)** | ✅ **(gate por-paso)** | 🔵 | **A1** |
| Redundancia `canOperateStep` inline en `confirmPayment` | n/a | n/a | n/a | ✅ **removida** | ✅ **removida** | ✅ **removida** | **A13** |
| `ViewModel` en edit/add (`EditViewModelInterface`) | ✅ | ✅ | ✅ **(implementa interfaz; `set('viewModel')`)** | ✅ | ✅ | ✅ | **A9** |
| `set('actionPolicy')` muerto | n/a | n/a | ✅ **removido** | n/a | n/a | n/a | **A10** |
| `$this->set()` crudo en index/view | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **(bug `$roleName` resuelto: `index()` usa solo `$roleId`)** | **A10** |
| Literal del estado de doc de liquidación | n/a | ✅ **`NoveltyConstants::DOC_STATUS_LIQUIDACION`** (`= 'd. liquidacion'`, valor idéntico) | n/a | n/a | n/a | n/a | **A12** |
| Slug módulo ≠ slug pipeline | invoices=invoices | ⚠️ employee_novelties/… ≠ novelties/… | ⚠️ advances ≠ legalizations | petty_cash | refunds | payment_schedulings | — (C3 no tocado: persistido) |

---

## Estado de divergencias

Leyenda: ✅ ejecutado · ⏸ diferido · ◻ decisión pendiente · ❎ omitido por decisión.

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
| **A9** | Advance VM `EditViewModelInterface` + `set('viewModel')` | ✅ ejecutado | `c9c2f64` — el "rewrite ~765 líneas" era falso: cuerpo del template intacto, solo cabecera (destructura `build()`) + 3 props en el VM + 1 línea del controller |
| **A10** | `set('actionPolicy')` muerto (Advance) + bug `$roleName` indefinido (PaymentScheduling index) | ✅ ejecutado | `048da87` |
| **A11** | `LiquidationDocPaymentService::authorizePayment` → `ServiceResult`; caller ajustado | ✅ ejecutado | `048da87` — servicio `:100`, caller `LiquidationDocPaymentsController.php:106` |
| **A12** | Constante `NoveltyConstants::DOC_STATUS_LIQUIDACION = 'd. liquidacion'` (valor idéntico); `NoveltyDocumentService` la usa | ✅ ejecutado | `048da87` — `NoveltyConstants.php:140`, `NoveltyDocumentService.php:178,215` |
| **A13** | Redundancia `canOperateStep` inline en `confirmPayment` (PettyCash/Refund/PaymentScheduling) | ✅ ejecutado (**sin cambio observable**) | `048da87` — el atributo `#[PipelineAction(step)]` ya gateaba antes del cuerpo |
| **C1** | Rechazo de pago de Novelty BORRA → persiste `status`+motivo | ✅ ejecutado | `9cc17c3` (migración `20260529120000` + servicio/entity/table/controller/constantes) + `73211aa` (3 regresiones cerradas por la revisión) + `97d8168` (schema dump). Migración **aplicada en dev**; ⚠️ correr `migrations migrate` en los demás entornos |
| **C2** | Extraer `PettyCashPaymentService` espejando Refund | ✅ ejecutado | `70546df` — 4 métodos de pago movidos; `buildSyntheticPayments` queda en el coordinador; deps `events`/`invoiceHistory` removidas del coordinador |
| **C3** | Naming `Advance*` vs `AdvanceLegalization*` | ✅ documentado (no renombrar) | `89c6c11` — nota de los 3 ejes en `CLAUDE.md`; dir/enum corto = convención Pipeline; slugs `advances`/`legalizations` inmutables |
| **C4** | `InvoiceLockPolicy` hace IO | ✅ ejecutado | `ff81a7f` — extraído a `InvoiceLockGuard` (`?? new`, espejo A8); Policy pura + test unitario de la rama scheduling |
| **C5** | History: `implements` interfaz + `FIELDS_TO_TRACK` | ✅ ejecutado | `d4a259e` — Refund/PettyCash/Advance/PaymentScheduling implementan `HistoryServiceInterface`; Invoice usa `const FIELDS_TO_TRACK` |
| **C6** | Auditoría de creación/documentos en el controller | ❎ omitido por decisión | patrón consistente en los 5 módulos (no outlier), sin `create()` de servicio donde encapsular, sin tests que lo cubran, retorno solo estético — mover el audit no acerca al canon |
| **C7** | DI de States/Registries (3 niveles) | ✅ ejecutado (opción C) | `70546df` — los 4 Registries restantes pasan a `shared` e inyectados en sus coordinadores; los States conservan `?? new`; Invoice queda como única divergencia legítima (sus States dependen de un servicio). Contenedor verificado resolviendo 8 servicios |
| **C8** | Novelty `advance()` con `#[Permission(edit)]` omite el avance en silencio | ✅ ejecutado | `4fdb988` — `advance()` → `#[PipelineAction(pipeline: PIPELINE_NOVELTIES)]` (alineado con `reject()` y con el advance de los demás módulos); el servicio se auto-gatea por `canOperate` (escritura + avance); el motivo `UNAUTHORIZED` ahora se surfacea como warning. Corrige además la sobre-restricción del 403 por CRUD `edit`. Test unitario nuevo |

---

## Pendiente tras la Ola 4

La auditoría queda **100 % saldada**: A1–A13 + A9 ejecutados; C1, C2, C4, C5, C7, C8 ejecutados; C3 documentado; C6 omitido por decisión. No queda ningún ítem accionable abierto. Solo restan, deliberadamente, estas categorías:

- **Despliegue de C1 en otros entornos (acción operativa, no de código):** la migración `20260529120000_AddStatusRejectionToLiquidationDocPayments` ya está **aplicada en dev** (columnas + backfill verificados); falta correr `php bin/cake migrations migrate` en staging/prod. Smoke manual del flujo de rechazo/registro de pago en `NoveltyLiquidationDocs` (edit + view).
- **C6** (❎ omitido) — encapsular la auditoría de creación/documentos en los servicios: bajo retorno, transversal a 5 controllers, sin red de tests; no acerca al canon.
- **NO tocar sin migración explícita de datos:** B8 (spelling `DIAN_REJECTED='Rechazado'` persistido), slug `advances`/`legalizations` y nombres de tabla, y todas las divergencias **B** (esenciales de dominio).
