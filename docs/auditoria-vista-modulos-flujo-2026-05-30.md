# Auditoría de la capa de vista — Módulos de flujo (ojos frescos sobre código vivo)

> **Fecha:** 2026-05-30 · **Alcance:** análisis, NO implementación (ningún código modificado).
> **Capas auditadas:** `src/ViewModel/`, `src/View/Presentation/` y `templates/` (index/add/edit/view + elements compartidos).
> **Módulos:** Invoice, Novelty (2 controllers), Advance, PettyCash, Refund, PaymentScheduling.
>
> **Metodología:** workflow multi-agente (13 agentes: 6 inventarios de módulo + 1 de infraestructura compartida → 5 ejes transversales → 1 adversarial), más lectura directa de `EditViewModelInterface`, los 8 elements compartidos y los VMs/Presentations. Los agentes de inventario trabajaron con ojos frescos; el doc previo solo se usó en la fase adversarial. Todo citado con `archivo:línea`.
>
> **Relación con `docs/auditoria-estructural-fresca-2026-05-29.md`:** aquella auditoría **excluyó explícitamente templates** (su línea 5) y cubrió backend/pipeline. Esta cierra ese hueco: la capa de presentación (ViewModel/Presentation) y los templates. Donde toca el backend (p. ej. `AdvanceLegalizationViewModel` no implementaba la interfaz) se reconcilia con el hallazgo previo.

---

## 0. Resumen ejecutivo y veredicto fresco

La capa de vista tiene **una columna vertebral sana que el doc previo no llegó a ver**: existen DOS familias de presentación ortogonales y bien separadas (`src/ViewModel/` per-request para `edit`; `src/View/Presentation/` const-maps estáticos para todas las vistas), un contrato común real (`EditViewModelInterface`, 4 property-hooks PHP 8.4) implementado por **6/6 módulos**, una capa `Support/` reutilizable, y un set de **8 elements compartidos** con delegación fuerte en los `edit.php`.

El veredicto en tres puntos:

1. **La dualidad ViewModel ↔ Presentation NO es deuda, es arquitectura correcta — no fusionar.** Tienen cardinalidad de consumo distinta: `Presentation` lo consumen vistas SIN instancia de VM (`index`, `view`, e incluso filas anidadas dentro de un `edit`); `ViewModel` es el agregado rico de la pantalla `edit`. Acoplamiento mutuo nulo (`SharedPresentation` ni VM se importan). Fusionar rompería el acceso desde los listados.

2. **El problema real no es la dualidad: es la cobertura asimétrica por acción.** La frontera presentación↔`.php` está **sana en `edit`** (delega bien vía VM + `Support/*` + `Presentation` consts) y **rota en `view`/`index`** en los 6 módulos por igual. La acción `view` es el *offender transversal*: sin VM, re-deriva mapas estado→pill inline, re-pinta documentos/pagos a mano teniendo elements. Las dos decisiones que cierran la asimetría — **C1 (VM para `view`)** y **C2 (`RowView` generalizado)** — son las de mayor impacto.

3. **Invoice es el outlier de layout, no la referencia.** Es el único con `RowView`+`forRow()` (canon de *Presentation*), pero su `edit.php` usa `sgi-edit-shell-body` en vez del canon `sgi-invoice-view-grid`, su `view.php` (731 líneas) es el peor del sistema (sin VM, docs inline, pills triplicados), y `add` ni siquiera pasa el VM al template. Para `edit`/`view` de layout, **Refund y PettyCash son mejores referentes**. Para el patrón estado→pill bien hecho, **Novelty/edit es el contraejemplo virtuoso** (consume `currentStatusBadge` del VM).

> **Reconciliación con el doc previo (A9 estructural).** La auditoría 2026-05-29 marcó que `AdvanceLegalizationViewModel` **no** implementaba `EditViewModelInterface` (`AdvanceLegalizationViewModel.php:24`). El estado vivo hoy lo **contradice**: sí la implementa (`:24`, verificado en infra y por el adversarial P5). O se remedió entre auditorías, o el hallazgo previo era impreciso. Lo que persiste es el naming sin sufijo `EditViewModel` y el `set($vm->build())` (ver A-entry / B-naming).

---

## 1. Mapa estructural comparativo (módulo × artefacto de vista)

Leyenda: ✅ presente/canónico · ⚠️ presente pero divergente · ❌ ausente · 🔵 ausencia legítima de dominio.

### Tabla A — Clases de presentación (ViewModel / Presentation / RowView)

| Artefacto | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| `*AddViewModel` | ✅ `InvoiceAddViewModel` | ✅ `EmployeeNoveltyAddViewModel` (NLD 🔵 no crea por form) | ✅ `AdvanceAddViewModel` ⚠️ muta ORM `:79` | ✅ | ✅ | ✅ |
| `*EditViewModel` | ✅ `InvoiceEditViewModel:19` | ✅ `EmployeeNovelty…:14` + `NoveltyLiquidationDoc…:16` | ⚠️ `AdvanceLegalizationViewModel:24` (sin sufijo) | ✅ `:17` | ✅ `:17` | ✅ `:14` |
| `implements EditViewModelInterface` | ✅ | ✅ (los 2) | ✅ `:24` (contradice doc previo) | ✅ | ✅ | ✅ |
| Inmutabilidad | ⚠️ per-prop readonly | per-prop | ✅ `final readonly class` (único) | per-prop | per-prop | per-prop |
| `*Presentation` (const-maps) | ✅ `InvoicePresentation` | ✅ `NoveltyPresentation` (compartida x2) | ✅ `AdvancePresentation` | ✅ | ✅ | ✅ |
| `*RowView` | ✅ `InvoiceRowView` (**único**) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Sub-VMs propios | ✅ `ViewModel/Invoice/*` (3, Invoice-only 🔵) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Usa `Support/*` (`PaymentOptions`/`PipelineEditFlags`/`SubmitButton`) | ⚠️ parcial (no `PipelineEditFlags`) | ❌ | ❌ 🔵 | ✅ los 3 | ✅ los 3 | ❌ 🔵 |

### Tabla B — Mecanismo controller → template y cobertura de VM por acción

| Acción | Invoice | Novelty | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| `index` | crudo `compact()` `:103` | crudo | crudo | crudo `:91` | crudo `:197` | crudo `:170` ⚠️ bug `$roleName` (doc previo) |
| `add` | ⚠️ `set('invoice',$vm->invoice)` `:255` | `set('viewModel')` `:824` | ⚠️ props sueltas `:206,226` | ⚠️ `get_object_vars` `:248` | ✅ `set('viewModel')` `:279` | ✅ `:139` |
| `edit` / `legalization` | ✅ `set('viewModel')` `:351` | ✅ `:500`,`:233` | ⚠️ `set($vm->build())` `:340` (único con `build()`) | ⚠️ `get_object_vars` `:315` | ✅ `:371` | ✅ `:175` |
| `view` | ❌ crudo `:230` (731 ln) | ❌ crudo (x2) | ❌ crudo `:259` | ❌ crudo `:189` | ❌ crudo `:222` | ❌ crudo `:108` |
| **`view` tiene VM** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### Tabla C — Layout/grid de templates

| Acción | Invoice | Novelty (EN / NLD) | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| `index` grid | `$gridStyle` 7-col `:291` | EN **side-rail `1fr 320px`** `:207` 🔵 / NLD 7-col `:70` | 7-col `:76` | 7-col `:91` | 7-col `:197` | 7-col `:170` |
| `add` | `card card-primary` `:25` 🔵 legacy | `:38` 🔵 / (sin add 🔵) | `:37` 🔵 | `:35` 🔵 | `:34` 🔵 | `:29` 🔵 |
| `edit` | ⚠️ **`sgi-edit-shell-body`** `:224` | ✅ `sgi-invoice-view-grid` `:132` / `:92` | 🔵 (vía Invoices) → `legalization.php:88` ✅ | ✅ `:151` | ✅ `:109` | ✅ `:69` |
| `view` | ⚠️ **inline `340px 1fr`** `:158` | ✅ `:91` / `:70` | ✅ `:63` | ⚠️ **inline `340px 1fr`** `:91` | ✅ `:65` | ✅ `:54` |

### Tabla D — Elements compartidos consumidos (✅ delega · ⚠️ inline/manual · ❌ no aplica)

| Element | Invoice | Novelty (EN / NLD) | Advance | PettyCash | Refund | PaymentScheduling |
|---|---|---|---|---|---|---|
| `pipeline_sidebar` | ✅ edit+view | ✅ / ✅ | ✅ view+legaliz. | ✅ | ✅ | ✅ |
| `documents_section` | ✅ edit / ⚠️ **view inline** `:613-681` | ✅ / ✅ (NLD edit `:470`) | 🔵 bespoke (firmas) `:459-624` | ✅ edit / ⚠️ view `document_row` directo `:394` | ✅ edit+view | ✅ edit / ⚠️ view `document_row` directo `:202` |
| `payment_section` | ✅ edit ×2 / ⚠️ view inline | ❌ / ⚠️ **NLD view tabla a mano** `:248-303` (existe mode `'view'`) | ✅ legalization ×3 | ⚠️ view card inline `:256` (pagos sintéticos) | ✅ edit | 🔵 |
| `confirm_payment_card` | 🔵 (lo embebe en payment_section) | ❌ / ✅ | ✅ legalization | ✅ | ✅ | ✅ |
| `regress_status_modal` | ✅ | 🔵 (Novelty usa `reject` terminal) | 🔵 | ✅ | ✅ | ✅ |
| `email_log_panel` | ✅ edit | ✅ EN edit / ❌ NLD | ❌ | ❌ | ❌ | ❌ |
| `document_row` + `_template` (acoplados a `sgi-document-uploader.js`) | ✅ | ✅ / ✅ | 🔵 bespoke | ✅ | ✅ | ✅ |

### Tabla E — Infraestructura compartida

| Artefacto | Estado |
|---|---|
| `EditViewModelInterface` (`:15-28`) | ✅ 4 property-hooks `{ get; }` · implementado por **7 VMs / 6 módulos** |
| `Support/PaymentOptions` | ✅ usado por Invoice/Refund/PettyCash (3) |
| `Support/PipelineEditFlags` | ⚠️ solo Refund/PettyCash (2); Invoice usa su `FieldAccessPolicy` |
| `Support/SubmitButton` | ✅ usado por Invoice/Refund/PettyCash (3) |
| `ViewModel/Invoice/*` (3 DTOs) | 🔵 Invoice-only por diseño (bundles de ctor, flujo multi-aprobador) |
| `View/Presentation/SharedPresentation` | ⚠️ `DATE_FORMAT` vivo (`ObservationControllerTrait:66`); `READY_FOR_PAYMENT_BADGES` **0 consumidores → dead const** |
| Element `observations` | ❌ no existe (se inyecta vía `ObservationControllerTrait`) |

---

## 2. Decisión de arquitectura de la capa de presentación

**Veredicto: `ViewModel` y `Presentation` deben COEXISTIR con responsabilidades separadas. No fusionar, no reemplazar.** (Confirmado por el adversarial, P4.)

- **`src/View/Presentation/*` = diccionario UI estático por dominio.** `final class` sin estado, casi enteramente `const` (`STATUS_BADGES`, `STATUS_ICONS`, `APPROVAL_BADGES`, `DIAN_BADGES`). Única lógica viva: la factory `InvoicePresentation::forRow()` (`:54-80`) que produce un row-DTO. Fuente de verdad: `*Constants` (slugs de dominio). **Vida útil = todas las vistas del módulo, incluidos `index`/`view` que no tienen VM**, e incluso filas anidadas dentro de un `edit` (`PettyCashRecords/edit.php:76` lee `InvoicePresentation::STATUS_BADGES` para facturas embebidas).
- **`src/ViewModel/* = agregado per-request de `edit`.** Construido en `_buildEditViewModel`, deriva de UNA entidad + permisos + dropdowns, cumple `EditViewModelInterface`. Vida útil = exclusivamente `edit` (y `legalization` en Advance).

**Por qué NO fusionar (leído del código):** cardinalidad de consumo distinta. Si la tabla de badges viviera dentro del VM, los `index`/`view` (que no instancian VM) perderían acceso o tendrían que construir un VM completo solo para leer una constante. Son ejes ortogonales — *estático-por-módulo* vs *derivado-por-request* — y el acoplamiento mutuo es **nulo y debe seguir siéndolo en una sola dirección: VM → Presentation** (el VM consume consts; Presentation nunca importa VM).

**El problema real es el triple camino de derivación estado→pill** y la cobertura asimétrica:
1. El mapeo estado→pill existe en **tres lugares**: la const `Presentation::STATUS_BADGES` (fuente), el `VM.currentStatusBadge` (que la consume bien), y **literales inline re-declarados en los templates** que ignoran ambos. Es el riesgo de drift confirmado (`EmployeeNovelties/view.php:22-31` ya tiene un mapa stale que omite estados).
2. Solo `edit` tiene VM; `view`/`index`/`add`(parcial) empujan toda la derivación al `.php`.
3. No hay capa de presentación de fila de listado salvo Invoice (`InvoiceRowView`).

---

## 3. Patrón canónico de vista propuesto

No "lo que hace Invoice", sino el mejor patrón derivado de todos (Novelty/edit para el pill, Refund/PettyCash para layout `edit`/`view`, Invoice para `RowView`).

```
src/ViewModel/
  EditViewModelInterface.php           ← contrato (4 property-hooks) — YA EXISTE, 6/6 lo cumplen
  {Modulo}AddViewModel.php             ← construcción de entidad (helper de create)
  {Modulo}EditViewModel.php            ← implements EditViewModelInterface; per-request de edit
  {Modulo}ViewViewModel.php            ← ★ NUEVO (decisión C1): read-only para `view`, o reuso del Edit VM
  Support/{PaymentOptions,PipelineEditFlags,SubmitButton}.php  ← helpers cross-módulo del VM
src/View/Presentation/
  {Modulo}Presentation.php             ← const STATUS_BADGES/ICONS + factory forRow()→RowView
  {Modulo}RowView.php                  ← ★ NUEVO (decisión C2): DTO de fila de listado, en los 6
templates/{Controller}/
  index.php   ← {Modulo}RowView vía Presentation::forRow(); CERO derivación de fila en .php
  add.php     ← legacy card (aceptado); set('viewModel', $vm) uniforme
  edit.php    ← sgi-invoice-view-grid + pipeline_sidebar; lee $viewModel->*; NUNCA re-deriva pills
  view.php    ← sgi-invoice-view-grid; $viewModel (read-only); documents_section/payment_section
```

**Contrato de datos VM/Presentation → template:**

1. **El mapeo estado→pill/icono vive SOLO en `{Modulo}Presentation` (const).** Cero arrays literales en `.php`.
2. **`currentStatusBadge` del VM se deriva de `Presentation` y los templates lo usan.** Si un template lo recomputa, es **bug de drift** (Novelty/edit es el modelo correcto: `edit.php:37,62,152`).
3. **`index` → `{Modulo}RowView` vía `Presentation::forRow()`.** Toda derivación de fila (`stageIdx`, pill, pipeline-mini, `periodLabel` ES, `pageTotal`) entra **dentro de `forRow()`**, no en el `.php` (caveat P9: tener `RowView` no basta — Invoice lo prueba; hay que mover la lógica adentro).
4. **`view` → VM read-only** (o reuso del Edit VM en modo read-only); el controller pasa `$viewModel`, no `compact()` crudo.
5. **`add` → `set('viewModel', $vm)` uniforme** (hoy hay 3 mecanismos distintos).
6. **Dirección de dependencia única:** VM puede importar `Presentation`; `Presentation` jamás importa VM.
7. **`Support/*`** es la capa de derivación cross-módulo del VM; alinear los VMs que apliquen a usarla.

---

## 4. Ejes de consistencia transversal y estado por módulo

### Eje 1 — Frontera lógica de presentación vs lógica embebida en el `.php`
**Sana en `edit`, enferma en `view`/`index` en los 6.** No hay un módulo outlier: es un eje de **acción**.
- **Mejor delegador: PettyCash/edit** (VM + 8 elements; `edit.php:151,185,422,448,467,519`). Refund empata.
- **Peor agregado: Novelty/view** — dos `view.php` con lógica duplicada **entre sí** (closure `initialsOf`, historial a mano: `EN/view.php:294-372` ≈ `NLD/view.php:351-441`) y mapa stale local.
- **Invoice/view (731 ln)** es el peor `view` individual: sin VM, docs inline (`:613-681`), pills duplicados (`:26-41`), slots con `ob_start`.
- Síntoma recurrente: `edit.php` re-deriva lo que **su propio VM ya expone** (`Invoice edit.php:43-63` vs `InvoiceEditViewModel:147-188`).

### Eje 2 — Reutilización de elements vs markup bespoke
**8 elements sanos, delegación fuerte en `edit`, débil en `view`.**
- **Bespoke legítimo (B):** `Advances/legalization` Soportes (`:459-624`) y `NLD/edit` doc-de-liquidación renderizan estado de **firma** (`isSigned()`, `signed_by_user`, pill "Firmado · X") — fuera del slot-contract de `document_row.php:8-27`, que no tiene noción de firma y está acoplado a `sgi-document-uploader.js`. Documentado en CLAUDE.md; confirmado por el adversarial (P8).
- **Deriva accidental (A):** `NLD/view.php:248-303` re-implementa la tabla de pagos a mano teniendo `payment_section` mode `'view'` (que su propio `edit` usa, `:339`). `Invoice/view` docs/pagos inline. Historial de Novelty duplicado cross-template → candidato a **nuevo element compartido**.

### Eje 3 — Layout/grid y estructura de index/add/edit/view
**Mayormente convergido al canon `sgi-invoice-view-grid` + `pipeline_sidebar`.** Outlier confirmado: **Invoice** (su `edit` usa `sgi-edit-shell-body:224`, su `view` grid inline `340px 1fr:158`); **PettyCash/view** también inline `:91`. `index` es div-grid (no `<table>`) con la misma mecánica en los 5 no-Novelty; EN/index usa side-rail calendario (esencial, B). `add` legacy en los 6 (canon aceptado, B).

### Eje 4 — Nomenclatura
**Sólida.** Sufijos `*AddViewModel`/`*EditViewModel`/`*Presentation`/`*RowView` con 0 divergencias accidentales de nombre de clase ni de template. Única excepción de sufijo: `AdvanceLegalizationViewModel` (esencial, B — entidad `AdvanceLegalization`, no "edit de Advance"). **Única deriva real: el método de entrada al template** — 3 mecanismos para lo mismo (`set('viewModel')` mayoritario vs `get_object_vars` en PettyCash vs `set('invoice',$vm->invoice)` en Invoice/Advance add vs `build()` solo en Advance legalization).

---

## 5. Clasificación de divergencias (A / B / C)

> Incorpora las 9 reclasificaciones del agente adversarial. Numeración nueva, unificada sobre los 5 ejes.

### (A) Deriva accidental → UNIFICAR

| # | Divergencia | Evidencia | Riesgo / caveat |
|---|---|---|---|
| A1 | **Mapeo estado→pill re-declarado inline** en templates, ignorando const + VM | `Refund/edit.php:41-48`+`view.php:16-23`; `Invoice/view.php:26-41`+`edit.php:55-63`; `Advances/view.php:19-26` | ⚠️ **Solo `EmployeeNovelties/view.php:22-31` está probado divergente (stale)**; los demás pueden ser copias idénticas. **Antes de unificar: diff celda-a-celda de cada array inline vs `*Presentation::STATUS_BADGES`** (adversarial P7) |
| A2 | **`currentStatusBadge` del VM calculado pero ignorado** (template recomputa) | `PaymentScheduling/edit.php:13-16`+`view.php:15-17` vs VM `:46-49` | Trabajo muerto + drift. **Real en Invoice/PettyCash/Refund/PaymentScheduling; NO en Novelty** (consume bien, es la referencia — P3) |
| A3 | **Doble alias `statusBadgeMap`==`badgeColors`** en EditVMs de Novelty | `EmployeeNoveltyEditViewModel.php:68,74`; `NoveltyLiquidationDocEditViewModel.php:70,77` | Dead alias; `statusBadgeMap` nunca consumido directo |
| A4 | **`STATUS_ICONS` dead-code en 6/6 módulos** (no 4/6) | Refund/PettyCash/PaymentScheduling/Advance Presentation `:22-30`; **+ Invoice (0 refs) + Novelty (VM lo expone `:21,:66` y 2 templates lo aliasan pero nunca lo renderizan)** | Borrar const + arrastra props muertas en VM Novelty (adversarial **P1, reclasificado de 4/6 a 6/6**) |
| A5 | **`READY_FOR_PAYMENT_BADGES` dead const** (0 consumidores) | `SharedPresentation.php:16-20` | Reclasificada **C→A** por el adversarial (P6): cautela "verificar" ya saldada, cero refs |
| A6 | **Método de entrada del VM triple** (`get_object_vars` / `set('invoice',$vm->invoice)` / `build()`) vs `set('viewModel',$vm)` mayoritario | `PettyCashRecordsController:248,315`; `InvoicesController:255,262`; `AdvancesController:206,226`; `legalization.php:15` | Ninguno; unificar a `set('viewModel',$vm)` + props directas |
| A7 ✅ docs hecho (2026-05-30) | **`view` re-pinta docs/pagos a mano** teniendo elements | `NLD/view.php:248-303` vs `payment_section` mode `'view'`; `Invoice/view.php:613-681` vs `documents_section` | **Docs: cerrado.** `PettyCash/view` + `PaymentScheduling/view` migrados a `documents_section` read-only (VMs exponen `documentRows`); Refund/NLD/EN ya delegaban. Toda `view` delega salvo Invoice (outlier). Pagos a mano = otra sub-tabla, fuera de alcance |
| A8 | **Historial de cambios duplicado a mano** entre los 2 `view.php` de Novelty | `EN/view.php:349-372` ≈ `NLD/view.php:351-441` | Ninguno; candidato a **extraer element `change_history`** |
| ~~A9~~ → **B** (reclasificado 2026-05-30) | **`Invoices/edit.php` usa `sgi-edit-shell-body`** en vez de `sgi-invoice-view-grid` | `edit.php:224` | **NO migrar.** La validación visual refutó la premisa "template viejo": `sgi-edit-shell` es un app-shell de altura completa (header **fijo** + body con scroll interno + footer sticky) **más sofisticado** que el grid canónico para el form más pesado. Migrarlo perdería el header fijo (regresión UX). Diferencia esencial, no deriva. Alinear "al revés" (los otros edits al shell) sería decisión de diseño mayor, fuera de alcance. |
| A10 ✅ hecho (`f0a6911`) | **`Invoices/view.php` + `PettyCashRecords/view.php` grid inline `340px 1fr`** | `Invoice/view.php:158`, `PettyCash/view.php:91` | Ninguno; mismo `pipeline_sidebar`, solo difiere el contenedor. CSS de la clase equivalente. |
| A11 | **`operationCenters` over-fetch en AddVM** no consumido | `PaymentScheduling _buildAddViewModel:144`, `add.php` no lo usa | Ninguno; query DB + props muertas |
| A12 | **`view()` setea `linkedInvoices`/`linkedTotal` muertos** | `AdvancesController:260-261` | Ninguno. **Muerte por no-consumo, NO por inalcanzabilidad** (adversarial P2: `view.php` SÍ se alcanza para anticipos sin legalización) |
| A13 ✅ hecho (2026-05-30) | **Filas de `index` con `onmouseenter/leave` inline** | `Invoices/index.php:326-327`; `NoveltyLiquidationDocs/index.php:158-159` | **Cerrado.** Las 6 filas `<a role="row">` adoptan el componente canónico `.row-fact` (components.css:1221); 12 handlers JS + props inline redundantes eliminados; `padding:14px 18px` preservado (idéntico). Nota: no era `.clickable-row` (eso es para `<tr data-href>`; estas son `<a>`) |

### (B) Diferencia esencial del dominio → NO UNIFICAR

| # | Divergencia | Por qué es esencial (leído del código) | Riesgo si se unifica |
|---|---|---|---|
| B1 | **Dualidad ViewModel vs Presentation** | Cardinalidad de consumo distinta: `Presentation` lo usan vistas sin VM e incluso filas anidadas dentro de `edit` (`PettyCashRecords/edit.php:76`). Acoplamiento mutuo nulo | Alto: rompería acceso desde `index`/`view` |
| B2 | **`ViewModel/Invoice/*` (3 DTOs) Invoice-only** | Bundles del ctor de `InvoiceEditViewModel` para el flujo multi-aprobador inexistente en los otros (`:9-11`). Subdir `Invoice/` lo señala | Acoplamiento artificial |
| B3 | **`AdvanceLegalizationViewModel` sin sufijo + único `readonly class`** | Modela la entidad `AdvanceLegalization` (legalización de anticipos), no "edit de Advance"; alineado al split documentado. `readonly class` es inmutabilidad *más fuerte*. Sí cumple la interfaz (`:24`) | Colisiona con la convención de entidad larga ya establecida |
| B4 | **`add.php` legacy (`card card-primary`) en los 6** | Canon explícito ("add.php es legacy en todos"); la entidad aún no existe → sin pipeline ni sidebar | — |
| B5 | **`EmployeeNovelties/index` side-rail `1fr 320px`** | Panel "Próximas/Distribución" + FullCalendar; el módulo tiene dimensión temporal/calendario que los otros listados no tienen (`index.php:207`) | Rompería una función de dominio |
| B6 | **`Advances` sin `edit.php`** (edita vía redirect a `Invoices::edit`) | Anticipo = Invoice (documentado). El "edit" real es `legalization.php` que sí usa el canon (`:88`) | Rompería el modelo de dos entidades |
| B7 | **Soportes bespoke en `Advances/legalization` + `NLD/edit`** | Docs con firma/estado/AJAX inline fuera del contrato `document_row`↔`sgi-document-uploader.js` (`legalization.php:485-492` vs `document_row.php:8-27`) | Rompería el gemelo `document_row`↔`_template`↔JS |
| B8 | **`confirm_payment_card` no usado por Invoice** | Invoice cierra el flujo dentro de `payment_section`; no re-pinta el card. Asimetría de cierre, no duplicado | Cambio funcional |
| B9 | **`regress_status_modal` ausente en Novelty** | Novelty usa `reject()` terminal, no `regress()` (documentado); no hay markup de regresión que duplicar | — |
| B10 | **Forms-por-estado en template** (`NLD/edit.php:251-313` switch de 4 forms; `legalization.php:235-454` if/elseif 7 ramas) | Cada estado (GDP/CONTABILIDAD/REVISION_FIRMAS/RRHH; estado×case_type) renderiza un form estructuralmente distinto; el VM entrega datos, la rama de UI por caso es lógica de flujo legítima | Defecto funcional |
| B11 | **`submitButtonHtml` impreso raw desde el VM** | HTML pre-renderizado por `SubmitButton::decide()` deliberado; el VM es la fuente, el `.php` solo emite (`PettyCash/edit.php:507`) | Sin ganancia |
| B12 | **Plurales de `compact()` por entidad** (`invoices`/`records`/`advances`/`liquidationDocs`) | El nombre refleja la entidad; uniformizar rompería legibilidad | — |

### (C) Zona gris → REQUIERE DECISIÓN

| # | Divergencia | El dilema | Riesgo |
|---|---|---|---|
| C1 🔑 | **`view` sin ViewModel en los 6 módulos** | ¿Crear `{Modulo}ViewViewModel` read-only, reusar el EditVM en modo read-only, o aceptar crudo? El `view.php` re-deriva exactamente lo que el EditVM ya encapsula → favorece reuso/VM read-only. **Es la decisión arquitectónica mayor de la capa de vista** | Medio: define si `EditViewModelInterface` cubre `view` |
| C2 🔑 | **`{Modulo}RowView` solo en Invoice** | ¿Generalizar `forRow()→RowView` a los 6 para absorber `stageIdx`/pill/pipeline-mini/`periodLabel` ES/`pageTotal` inline de cada `index.php`? **Caveat (adversarial P9): tener `RowView` NO basta** — Invoice lo prueba, su `index.php:311-322` re-deriva variantes pese a usar `forRow`. Cualquier canon de RowView debe mover esa lógica DENTRO de `forRow()` | Medio: trabajo grande × 5 módulos |
| C3 | **`ob_start` de sidebar (`registryLines`/`actionsHtml`) en cada `edit.php`** | Armado de HTML del sidebar repetido inline en todos los edit (`PettyCash:157-204`, `Refund:115-141`, `PS:78-100`, `legalization:93-128`). ¿Mover a un helper/builder de presentación o a un slot compartido? | Ninguno |
| C4 | **`Support/PipelineEditFlags` solo en Refund/PettyCash** | ¿Extender a Novelty (que sí edita header por paso), o aceptar el gap? PS/Advance no editan header (legítimo) | Ninguno |
| C5 → **(B)** (resuelto 2026-05-30) | **`email_log_panel` solo en Invoice + EN/edit** | **Resuelto = (B) esencial, no es gap.** Solo Invoice (`sendApprovalLinkNotification`) y EmployeeNovelty (`sendNoveltyApprovalEmail`) emiten correos — únicos `entity_type` en `email_logs` (`invoice`/`employee_novelty`). Refund/PettyCash/PaymentScheduling/Advance/NLD **no** llaman a `NotificationService` ni tienen aprobación externa (`ApprovalToken`/`ExternalApprovals` solo Invoice+EN). El panel aparece donde hay correos; NLD≠EN porque NLD es otra entidad (liquidación grupal) sin esos correos. Alinear renderizaría paneles vacíos | Ninguno |
| C6 → **resuelto** (2026-05-30) | **`document_row` server-render directo (sin `documents_section`) en 3 `view`** | **Resuelto: canon = `documents_section` read-only.** La premisa era imprecisa — el element YA soporta read-only vía `uploadModalId => null` (`$showUpload = $canUpload && $uploadModalId !== null`), y Refund/NLD/EN/view ya lo usaban así (no document_row directo). Solo quedaban 2 rezagados reales (`PettyCash/view`, `PaymentScheduling/view`), ya alineados. No hizo falta flag `readOnly` nuevo | Ninguno |
| C7 | **`final readonly class` vs `public readonly` por-propiedad** | `InvoiceEditViewModel:19`/`Refund:17`/`PettyCash:17` per-prop vs `*AddViewModel` y `AdvanceLegalizationViewModel:24` clase. Mismo efecto de inmutabilidad. ¿Estandarizar a `final readonly class`? Cosmético | Ninguno |
| C8 | **`AdvanceAddViewModel.fromRequest` muta el ORM** (`patchEntity`/`newEntity`) dentro del VM | ¿El AddVM debe construir entidad (lógica de controller/service) o solo transportar? Diverge del rol "deriva presentación" (`:79`). Aplica también a `InvoiceAddViewModel` | Bajo; reorganización |
| C9 | **Grids internos inline para sub-tablas** (facturas vinculadas, pagos) dentro de templates canónicos | `legalization.php:158`, `NLD/view.php:184,249`, `Invoice/view.php:269,337`, `PettyCash/view.php:180,223`. No hay element "tabla interna de detalle". ¿Tolerar grid inline o crear element? Transversal | Ninguno |

---

## 6. Priorización del refactor

**Ola 1 — Colapsar el triple camino de derivación de badges (máxima prioridad: drift real en producción).** Esfuerzo bajo, riesgo bajo (con caveat de diff).
1. **A1 + A2 + A3** — una sola fuente del mapeo estado→pill: const `Presentation` como fuente, `VM.currentStatusBadge` que la consume, templates que leen el VM (modelo: Novelty/edit). **Previo: diff celda-a-celda** de cada array inline vs la const para distinguir copia-idéntica de drift (P7). Corregir el mapa stale de `EmployeeNovelties/view.php:22-31`.
2. **A4 + A5 + A11 + A12** — barrer dead-code de presentación (`STATUS_ICONS` 6/6 + props muertas del VM Novelty; `READY_FOR_PAYMENT_BADGES`; `operationCenters`; `linkedInvoices/Total`).

**Ola 2 — Las dos decisiones que cierran la asimetría de cobertura.** Esfuerzo medio-alto, riesgo bajo (sin datos).
3. **C1 (decisión) — VM para `view`** en los 6: define si se reusa el EditVM read-only o se crea `{Modulo}ViewViewModel`. Desbloquea A7 (mover docs/pagos de `view` a los elements).
4. **C2 (decisión) — `RowView` generalizado** a los 6, moviendo la lógica de fila DENTRO de `forRow()` (no solo crear el DTO — caveat P9). Cubre A13 y los `periodLabel`/`pageTotal` inline.

**Ola 3 — Convergencia de layout y mecanismo.** Esfuerzo bajo, riesgo bajo.
5. **A10** (hecho 2026-05-30, `f0a6911`) — migrar `Invoices/view` + `PettyCash/view` a `sgi-invoice-view-grid`. **A9 descartado**: la validación visual mostró que `sgi-edit-shell` es esencial (header fijo), no deriva — reclasificado a (B).
6. **A6** — unificar el método de entrada a `set('viewModel', $vm)` (PettyCash, Invoice/add, Advance/add, Advance/legalization).
7. **A8** — extraer un element `change_history` compartido (Novelty `view` × 2; reutilizable por los demás).

**Ola 4 — Decisiones de bajo retorno (opcional).** **C3** (slot/builder para el `ob_start` del sidebar), **C4** (`PipelineEditFlags` a Novelty), **C5** (`email_log_panel` — verificar emisión por módulo), **C6** (canon de docs en `view`), **C7** (`final readonly class`), **C8** (rol del AddVM), **C9** (element de sub-tablas).

**NO tocar:** todas las B (esenciales de dominio: dualidad VM/Presentation, Soportes bespoke con firmas, `add` legacy, side-rail de Novelty, Advance vía Invoices, forms-por-estado, naming `AdvanceLegalization*`).

---

## 7. Verificaciones pendientes antes de ejecutar

Señaladas por el agente adversarial; conviene cerrarlas antes de cualquier remediación:

- **A1 (crítico):** diff literal de cada array de pills inline (`$statusPills`/`$rfStatusPills`/`$advStatusPills`/`$statusBadgeMap`) vs su `*Presentation::STATUS_BADGES`, etiquetando "idéntico" (borrar y leer const) vs "divergente" (hay un mapeo incorrecto en producción). Solo `EmployeeNovelties/view.php` está probado divergente.
- **A4 / A5:** confirmar 0 consumidores de `STATUS_ICONS` (incl. la cadena VM→template de Novelty, que es dead-pass-through) y de `READY_FOR_PAYMENT_BADGES` antes de borrar.
- **C1:** decidir si `EditViewModelInterface` cubre la semántica de `view` o se necesita un contrato/VM read-only aparte.
- ~~**C5:**~~ ✅ auditado (2026-05-30) → **(B), no es gap.** Solo Invoice y EmployeeNovelty emiten correos (`sendApprovalLinkNotification`/`sendNoveltyApprovalEmail`; `entity_type` `invoice`/`employee_novelty`). Los otros 4 no usan `NotificationService`; `email_log_panel` solo donde hay correos. Sin trabajo.
- **Reconciliación con backend:** confirmar que `AdvanceLegalizationViewModel` hoy implementa `EditViewModelInterface` (`:24`) y actualizar el hallazgo A9 de `docs/auditoria-estructural-fresca-2026-05-29.md` si procede.

---

## 8. Estado de ejecución (2026-05-30)

Remediación implementada en `main`, validada visualmente con Playwright (índices y vistas de los 6 módulos renderizados sin errores; PettyCash/PaymentScheduling `view` no testeables por falta de datos pero con el mismo patrón validado en los otros 5).

| Ola | Ítems | Commit | Estado |
|---|---|---|---|
| 1 | A1 (pills→const, 2 drifts corregidos), A4 (`STATUS_ICONS` dead 6/6), A5 (`READY_FOR_PAYMENT_BADGES`), A11 (`operationCenters`), A12 (`linkedInvoices/Total`) | `44acb33` | ✅ |
| 2 | C1 (`ViewViewModelInterface` + 6 `{Modulo}ViewViewModel`), C2 (5 `RowView` + `forRow()`; Invoice `RowView` ampliado, P9) | `0ab9da5` (piloto Refund) + `fbb0d1f` (5 módulos) | ✅ |
| 3 | A8 (element `change_history`), docblock `InvoicePresentation`, A10 (`Invoices/view`+`PettyCash/view`→grid) | `88aeb00` + `f0a6911` | ✅ |

**Reclasificaciones por evidencia visual/de coste (divergen del análisis original):**
- **A9 → (B) esencial** — `sgi-edit-shell` (Invoice/edit) es un app-shell superior (header fijo), no deriva. NO migrar.
- **A6 → skip recomendado** — uniformar el método de entrada en `add` es churn de bajo valor sobre forms legacy (Invoice exigiría rediseñar `InvoiceAddViewModel`).
- **A3 → no-deriva** — `statusBadgeMap`/`badgeColors` cumplen dos roles distintos (pill de header vs pills de documentos), no son alias muertos.

**Pendiente (Ola 4, bajo retorno):** C3–C9. **Bug previo ajeno detectado:** 404 `/marked_as_signed` en la sección de firmas bespoke de `NoveltyLiquidationDocs/view` (no tocada por esta refactorización).

</invoke>
