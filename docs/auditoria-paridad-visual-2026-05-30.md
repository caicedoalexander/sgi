# Auditoría de paridad VISUAL · HTML/CSS — Módulos de flujo (canon = Invoice)

> **Fecha:** 2026-05-30 · **Alcance:** análisis, **NO implementación** (ningún template ni CSS modificado).
> **Eje auditado:** apariencia pura — estructura HTML, clases CSS, layout y *cómo se ve* cada vista. **Módulos:** Invoice (canon), Novelty (EmployeeNovelties + NoveltyLiquidationDocs), Advance, PettyCash, Refund, PaymentScheduling.
>
> **⚠️ Esto NO es la auditoría de arquitectura.** En `docs/auditoria-vista-modulos-flujo-2026-05-30.md` y las estructurales, Invoice era el **outlier** a alinear (capa ViewModel/Presentation/backend). **Aquí, en lo puramente visual/HTML/CSS, Invoice es la REFERENCIA a replicar.** Algunas conclusiones previas se **revierten** bajo este marco (en particular A9/A10: el `.spi-edit-shell` deja de ser "solo-Invoice" y pasa a ser el canon que los demás deben adoptar). No mezclar ambos criterios.
>
> **Metodología:** workflow multi-agente (11 agentes: 1 extracción de canon Invoice + 5 inventarios de módulo → 4 ejes transversales → 1 adversarial), sobre código vivo, contrastado contra `webroot/css/components.css` (2400+ líneas), el sistema de diseño `docs/design/` y el **design file de Claude** (prototipos JSX `lista-facturas`/`detalle-factura`/`editar-factura`/`novedades` + `Componentes Faltantes.html`, de donde derivan los templates de Invoice). Todo citado con `archivo:línea`. Las clasificaciones incorporan las correcciones del agente adversarial.

---

## 0. Resumen ejecutivo y veredicto

La paridad visual está **mucho más sana de lo que parece**, y el grueso del trabajo se concentra en **una sola decisión estructural**.

1. **El único defecto estructural (A) uniforme es el shell de edición.** Los **6 surfaces de edición** no-Invoice (EN/edit, NLD/edit, Advances/legalization, PettyCash/edit, Refund/edit, PaymentScheduling/edit) están construidos sobre `.spi-invoice-view-grid` — el grid de la vista **view** — en vez del `.spi-edit-shell` (app-shell de altura completa: header fijo + columnas con scroll independiente + footer sticky). Confirmado por grep: **`.spi-edit-shell` es exclusivo de Invoice.** Esto es ~80 % del valor de remediación y es justo lo que el marco "Invoice = canon visual" manda alinear (revirtiendo A9/A10 de la auditoría previa).

2. **`index` y `view` ya están alineados en contenedor en los 6 módulos.** Todos los `index` replican fielmente el patrón `.spi-card(padding:0)` + grid-header inline + filas `.row-fact` (ancla, no `<table>`) + `.chip` + `.input` + `.pipeline-mini` + `.empty-state` + `element('pagination')`. Todos los `view` usan `.spi-invoice-view-grid` > `left`+`right` + `element('pipeline_sidebar')`. Reutilización de elements compartidos (pipeline_sidebar, documents_section, pagination, empty-state, pills soft) **ejemplar y uniforme** — en varios casos los módulos reutilizan *más* elements que el propio Invoice.

3. **La cola de divergencias es mayormente cosmética y se colapsa en 2–3 lotes.** Tras las reclasificaciones del adversarial: `.card`→`.spi-card` (cero delta visual), idioma de tarjeta de detalle a 2 columnas, 2 botones-remove inline, el callout inline de legalization, y `active.php` (gemelo legacy). El resto son **(B) esenciales** (tablas agrupadas de dominio, soportes con firma de Advance, side-rail de calendario de Novelty, forms por-estado) o **(C) decisiones de proyecto**.

4. **Corrección importante al vocabulario del brief:** **Invoice NO usa `.spi-info-grid`/`.spi-info-cell`.** Maqueta sus campos de detalle con **`.field-row`** dentro de grids inline `display:grid;grid-template-columns:1fr 1fr;gap:28px` (`Invoices/view.php:213,281`). `.spi-info-grid` existe en `components.css:2301-2335` pero es un componente **huérfano** que solo consume `EmployeeNovelties/edit.php:178`. El canon de campos de detalle es **`.field-row`**, no `.spi-info-grid`.

**Decisiones ya tomadas (esta sesión):** edit → alinear los 5 al shell; add → modal = **deuda diferida** (conservar specs del design file); overlays = **fuera del núcleo**; entrega = **roadmap ejecutable**.

---

## 1. Mapa comparativo módulo × vista

**Leyenda:** ✅ alineado-con-Invoice · ⚠️ divergente (A, alinear) · 🟦 esencial de dominio (B, no unificar) · 🔶 zona gris (C, decisión) · 🗑️ legacy/DEBT (fuera de núcleo).

### Tabla maestra — contenedor de layout por vista

| Módulo | `index` | `add` | `edit` (o equivalente) | `view` |
|---|---|---|---|---|
| **Invoice** (CANON) | ✅ `.spi-card`(p:0)+`.row-fact` `index.php:272,293` | 🗑️ `.card.card-primary` `add.php:25` | ✅ **`.spi-edit-shell`** `edit.php:147` | ✅ `.spi-invoice-view-grid` `view.php:123` |
| **EmployeeNovelties** | ✅ side-rail `1fr 320px` 🟦 `index.php:207`; lista `.spi-card`(p:0) `:218`+`.row-fact.head` `:219` | 🗑️ `.card.card-primary` `add.php:38` | ⚠️ `.spi-invoice-view-grid` `edit.php:131` → shell | ⚠️ `.spi-invoice-view-grid` `view.php:78` (info-card A `:112`) |
| **NoveltyLiquidationDocs** | ✅ `.spi-card`(p:0) `index.php:133`+`.row-fact` `:151` (réplica más limpia) | — (sin add 🟦) | ⚠️ `.spi-invoice-view-grid` `edit.php:92` → shell | ⚠️ `.spi-invoice-view-grid` `view.php:66` (info-card A `:102`) |
| **Advance** | ✅ `.spi-card` `index.php:146`+`.row-fact` `:163` (+6ª col Legalización 🟦) | 🗑️ `.card.card-primary` | ⚠️ `legalization.php:88` `.spi-invoice-view-grid` → shell **(🔶 C1: sin form único)** | ⚠️ `.spi-invoice-view-grid` `view.php:49` (info-card A `:80`) |
| **PettyCash** (Records) | ✅ `.spi-card`(p:0) `index.php:203`+`.row-fact` `:223` | 🗑️ `.card.card-primary` | ⚠️ `.spi-invoice-view-grid` `edit.php:151` → shell (**footer ya presente** `:467`, re-parentar) | ✅ **contraejemplo correcto**: `.spi-card`+`grid 1fr 1fr;gap:28px` `view.php:170,213` |
| **Refund** | ✅ `.spi-card`(p:0) `index.php:197`+`.row-fact` `:215` | 🗑️ `.card.card-primary` | ⚠️ `.spi-invoice-view-grid` `edit.php:103` → shell (**footer ya presente** `:419`) | ⚠️ `.spi-invoice-view-grid` `view.php:50` (info-card A `:81`; tabla agrupada 🟦 `:136`) |
| **PaymentScheduling** | ✅ `.spi-card`(p:0) `index.php:170`+`.row-fact` `:192` (filtros reducidos 🟦) | 🗑️ `.card.card-primary` | ⚠️ `.spi-invoice-view-grid` `edit.php:65` → shell (**footer ya presente** `:253`) | ⚠️ `.spi-invoice-view-grid` `view.php:46` (info-card A `:77`; tabla 🟦 `:129`) |

**Vistas extra (fuera del cuarteto):** `EmployeeNovelties/active.php` ⚠️ legacy (`.card.card-primary`/`-dark` `:28,51` + `.btn-outline-*` `:16`); `Advances/link_candidates.php` ✅🟦 (fragment 100 % delegado a `link_invoices_modal`, gold standard); `PaymentSchedulings/preview_import.php` 🟦 (pantalla off-canon de import; A internos de bajo valor).

### Resumen por módulo

- **EmployeeNovelties** — el más cercano en `index`/`view`; `index` incluso mejora a Invoice usando `.row-fact.head` (`index.php:219`). Divergencia dominante: ambos `edit` en el grid de view + forms por-estado inline. `active.php` es el gemelo legacy de su propio `index` modo `all`.
- **NoveltyLiquidationDocs** — réplica más limpia del `index` de Invoice. `view`/`edit` con soportes-con-firma + sub-tablas de novedades asociadas (🟦) y reemplazo de docs por `fetch()` AJAX (🟦). El consumidor de elements más fuerte (`payment_section` + `confirm_payment_card`).
- **Advance** — `index`/`view` casi perfectos. `legalization.php` es la superficie de trabajo real pero está en el grid de view; **C1**: no hay `<form>` único que envuelva el cuerpo (forms-por-estado), así que el ancla de `.spi-edit-shell-form` es ambigua. Soportes con firma/consignación bespoke = 🟦 (sancionado en CLAUDE.md).
- **PettyCash** — el más fiel. Su `view` es **el contraejemplo correcto** de tarjeta de detalle (idéntico a Invoice). `edit` ya emite el `.spi-edit-footer` correcto, solo huérfano fuera del shell → el tier más fácil.
- **Refund** — referente de layout. `edit` con footer ya presente. La tabla "Facturas Agrupadas" es **B** (tabular, no lista plana — corregido por el adversarial).
- **PaymentScheduling** — `index` casi línea-por-línea. `edit` es un híbrido (footer del shell sobre el grid de view). Tabla de invoices vinculadas = 🟦.

---

## 2. Especificación del canon visual (derivado de Invoice + sistema de diseño)

Esqueletos canónicos que **todo módulo de flujo** debe replicar. Los `.spi-*`/componentes salen de `components.css`; los estilos inline marcados son **convención canónica** (Invoice los usa así, copiarlos NO es deriva).

### 2.1 Convención de estilos inline (regla transversal)

El medio de diseño son prototipos inline-styled; **solo los átomos y los layouts recurrentes** se extrajeron a clases. Por tanto:

- ✅ **Canon-consistente (NO marcar):** tipografía/composición one-off del header de página, el `$gridStyle` N-col aplicado inline a header + `.row-fact`, los grids de campos `1fr 1fr;gap:28px`, los slots `ob_start()` del `pipeline_sidebar`, el énfasis de moneda `color:var(--primary-color)` sobre `.mono`, los separadores `<span>` de 1px del footer.
- ⚠️ **Deriva (marcar):** inline que **re-implementa un átomo** (pill/botón/card/empty-state hechos a mano), o que **reproduce una clase `.spi-*`** existente (p.ej. re-derivar el grid de view con `grid-template-columns:340px 1fr` en vez de la clase).

### 2.2 Esqueleto INDEX (ya alineado en los 6)

```
(sin shell — hijos directos del content-wrapper)
HEADER  .d-flex.justify-content-between        [inline: título 22/700 + meta + acciones]
  └─ .btn.btn-primary  (la ÚNICA primaria: "Nueva …")
SEARCH+FILTROS  Form(get)
  ├─ <label class="input">  <i.bi-search> + <input>
  ├─ .btn.btn-default (toggle "Filtros" → .collapse)
  └─ .collapse > .spi-card.compact > .row.g-2 (.form-select-sm + .flatpickr-date)
CHIPS   .d-flex[role=tablist] > .chip / .chip.is-active (+ .dot)
TABLA   .spi-card [style="padding:0"]
  ├─ HEADER ROW  <div> [inline $gridStyle + bg-subtle + uppercase]
  ├─ <a class="row-fact"> [inline $gridStyle]  ← ANCLA, no <table>
  │    .mono · .pipeline-mini.is-* · .pill.pill-*-soft.pill-sm · <i.bi-chevron-right>
  ├─ .empty-state > .es-icon.es-icon-neutral / .es-title / .es-msg
  └─ element('pagination')
```
Referencia: `Invoices/index.php:95,143,248,272,293,398,416`.

### 2.3 Esqueleto EDIT (`.spi-edit-shell`) — **el canon a adoptar en los 5**

```
.spi-edit-shell                                   (≥992px: position:fixed, viewport completo)
├─ .spi-edit-shell-head                           (header FIJO, flex-shrink:0, borde inferior)
│    └─ breadcrumb + .spi-title-page + chip .mono id + .pill estado + acciones .btn-default
├─ Form->create(..., ['class' => 'spi-edit-shell-form'])   (flex:1, columna flex)
│   └─ .spi-edit-shell-body  (flex:1; min-height:0; SCROLL)
│       └─ .row.gx-3
│           ├─ <aside class="col-lg-3 spi-edit-col">   (340px, scroll independiente)
│           │     element('pipeline_sidebar')  (hero + pipeline-v + acciones, vía ob_start)
│           └─ <main class="col-lg-9 spi-edit-col">    (scroll independiente)
│                 .spi-card "Datos Generales"  (.spi-label + .hr + .row.g-2 form)
│                 .spi-card [position:relative] + .accent-strip "Etapa actual · editable"
│                 element('payment_section') / element('documents_section') / …
└─ .spi-edit-footer                               (dentro del shell → position:static)
     ├─ .spi-edit-footer-meta      (Rol + última modificación + dirty indicator)
     └─ .spi-edit-footer-actions   (.btn-ghost Cancelar + la ÚNICA .btn-primary submit)
```
Referencia: `Invoices/edit.php:147,150,213,216,243,326,887`. Contrato CSS: `components.css:2385-2436`. **Nota:** el split izq/der del shell usa **Bootstrap `.col-lg-3`/`.col-lg-9`** (cada uno también `.spi-edit-col`), NO el `340px 1fr` que usa el *view*.

### 2.4 Esqueleto VIEW (`.spi-invoice-view-grid`) — ya alineado en contenedor

```
HEADER  breadcrumb + .spi-title-page (h1) + acciones .btn-default/.btn-secondary
.spi-invoice-view-grid          (grid 340px 1fr; gap:14px)
├─ <aside class="spi-invoice-view-left">   element('pipeline_sidebar')
└─ <main class="spi-invoice-view-right">
     .spi-card "Datos generales"
        └─ <div [inline grid 1fr 1fr; gap:28px]>  .spi-label + .field-row (.k/.v/.v.mono)
     .spi-card pagos/observaciones/soportes/historial
        (docs: element('documents_section') read-only · pills .pill-*-soft · .av · .empty-state)
```
Referencia: `Invoices/view.php:123,126,209,213,281`. **Canon de campos = `.field-row` dentro de grid inline `1fr 1fr;gap:28px`** (NO `.spi-info-grid`).

### 2.5 Vocabulario de átomos (nunca hacer a mano)

Botones `.btn` (+`-primary/-default/-ghost/-secondary/-danger/-subtle/-sm/-icon`) · Pills `.pill` (+`-*-soft`/`-sm`/`-lg`/`.pill-dark`/`.pill-orange`) · Inputs `.input` (+`.input-label`/`.input-help`) · `.av` (`-sm/md/lg/xl`) · `.doc` · `.chip` (+`.is-active`/`.dot`) · `.tab`/`.tab-badge` · `.segmented`/`.seg` · `.field-row` (`.k`/`.v`/`.v.mono`) · `.pipeline-mini`/`.pipeline-v` · `.empty-state`/`.es-*` · `.dropzone`/`.dz-*` · `.bank-chip` · `.spi-card`(+`.compact`)/`.spi-section-head`/`.spi-label`/`.mono`/`.hr`/`.accent-strip`/`.banner`/`.spi-folder-count`. Header: breadcrumb + `.spi-title-page`/`.spi-page-header`/`.spi-edit-id-chip` (**ver C2**).

### 2.6 ADD → modal (DEUDA diferida, fuera de núcleo)

Hoy los 6 `add.php` son legacy `.card.card-primary` (`Invoices/add.php:25`, etc.). El sistema de diseño reserva el **modal `.modal-stage`** (C1 de *Componentes Faltantes*: `.modal-stage`/`.modal`/`.modal-head`/`.modal-icon`/`.modal-desc`/`.modal-body`/`.modal-foot`, máx 480/640px, franja izq. 3px semántica, footer `#fafafa`) para **formularios cortos/confirmaciones**, y propone **stepper/wizard `.stepper`/`.step*`** (D4) para altas largas (Invoice/add es multi-sección). **Decisión modal-vs-wizard por módulo = pendiente; conservar estas specs como referencia.** No auditado en profundidad aquí.

---

## 3. Catálogo de divergencias (A / B / C)

> Incorpora las correcciones del agente adversarial (FP = falso positivo, RC = reclasificación).

### (A) Deriva accidental → ALINEAR a Invoice

| # | Divergencia | Módulos / evidencia | Fix canónico | Severidad |
|---|---|---|---|---|
| **A1** 🔑 | **EDIT sobre `.spi-invoice-view-grid` en vez de `.spi-edit-shell`** | TODOS los 5: `EmployeeNovelties/edit.php:131`, `NoveltyLiquidationDocs/edit.php:92`, `Advances/legalization.php:88`, `PettyCashRecords/edit.php:151`, `Refunds/edit.php:103`, `PaymentSchedulings/edit.php:65` | Envolver en `.spi-edit-shell` (head/form/body/`.col-lg-3+9 .spi-edit-col`/footer) | **Alta — único A estructural** |
| A2 | Tarjeta de detalle a 2 col con `.card`+`.row.g-0`+`.col-md-6`+`border-right` vs Invoice `.spi-card`+grid inline `1fr 1fr;gap:28px`+`.field-row` | `EmployeeNovelties/view.php:112`, `NoveltyLiquidationDocs/view.php:102`, `Advances/view.php:80`, `Refunds/view.php:81`, `PaymentSchedulings/view.php:77` (PettyCash exento — lo hace bien `view.php:170`) | Adoptar `.spi-card`+grid inline `1fr 1fr;gap:28px`. Sancionado por `components.css:1116` → baja prioridad | Baja (cosmética) |
| A3 | `.card`+`style="padding:18px 20px"` re-implementa `.spi-card` | `EmployeeNovelties/edit.php:174,271`, `NoveltyLiquidationDocs/edit.php:150,195,317`, `PaymentSchedulings/edit.php:116` | `.spi-card` (quitar padding inline); `.spi-card.compact` si denso | Baja (cero delta visual) |
| A4 | `active.php` legacy: `.card.card-primary`/`.card-dark` + `.btn-outline-*` scope nav | `EmployeeNovelties/active.php:16-24,28,51` | `.spi-card.compact`/`.spi-card`(p:0) + `.chip` tabs (como su propio `index.php:149`), o **retirar** a favor del `index` modo `all` | Media |
| A5 | Botón-remove icon-only inline (`.btn-outline-danger`+`style="padding:.15rem .4rem"`) | `Refunds/edit.php:265`, `PaymentSchedulings/edit.php:197` | `.btn.btn-icon`+`.spi-fg-danger` ó `.btn.btn-danger.btn-sm` | Baja (2 swaps) |
| A6 | Callout "Monto pendiente" inline `border-left:2px` re-implementa `.banner` | `Advances/legalization.php:354` (el módulo ya usa `.banner info` en `view.php:131` → resuelve la duda a A) | `.banner.info`/`.banner.warning` | Baja (1 instancia) |
| A7 | Modal upload-doc inline duplicado en vez de element compartido | `Refunds/edit.php:479`, `PettyCashRecords/edit.php:534`, `EmployeeNovelties/edit.php:499`, `NoveltyLiquidationDocs/edit.php:490`, `PaymentSchedulings/edit.php:330` (Invoice lo factoriza: `invoice_edit/upload_doc_modal`) | Extraer `element('upload_doc_modal')` parametrizado | Baja (DRY, cero delta) |
| A8 | `.spi-edit-footer` huérfano fuera del shell → variante page-fixed | `PettyCashRecords/edit.php:467`, `Refunds/edit.php:419`, `PaymentSchedulings/edit.php:253` | **Se resuelve solo con A1** (re-parentar dentro del shell → `position:static`) | — (consecuencia de A1) |
| A9 | Tokens Bootstrap en celdas de tabla (`text-end`/`fw-bold`/`table-light`) | `Refunds/view.php:156`, `PaymentSchedulings/view.php:156`, `preview_import.php:61` | `.mono`+`color:var(--primary-color)` para totales | Baja (token, dentro de B) |
| A10 | `.btn-ghost-card` (módulos) vs `.btn-default` (Invoice/view) en acciones de header | EN/NLD/Advance/Refund/PaySched view+edit | **Acoplado a C2** — resolver junto al idioma de header, no aislado | Baja |

### (B) Diferencia esencial de dominio → NO UNIFICAR

| # | Divergencia | Por qué es esencial (leído del código) |
|---|---|---|
| B1 | **Tablas de invoices agrupadas/vinculadas `<table class="table">`** (Refund `view.php:136`+`edit.php:236`, PettyCash `view.php:295`+`edit.php:289`, PaymentScheduling `view.php:129`+`edit.php:169`) | Datos tabulares multi-columna con `<tfoot>` total y columna de acción; Invoice no tiene line-items hijos en core views → no hay patrón `.row-fact` que copiar. Forzar `.row-fact` perdería alineación de columnas + total. **Adversarial RC-2: Refund también es B** (5-col con pill de pipeline, no lista plana) |
| B2 | **Soportes con firma/consignación bespoke** (`Advances/legalization.php:459-624`; firmas en NLD `edit.php:199`, `view.php:189`) | Docs con pills firmado/pendiente + reemplazo AJAX `fetch()` fuera del contrato `document_row`↔`spi-document-uploader.js` — sancionado en CLAUDE.md. Reutilizan correctamente `.doc-row`/`.doc-icon` |
| B3 | **Side-rail de calendario `1fr 320px` + swatch de color de tipo** (`EmployeeNovelties/index.php:207,271`; `.spi-calendar`) | Dimensión temporal/calendario que los otros listados no tienen; el swatch es la clave-de-color del evento (NO un status pill — sería violación de "pills soft") |
| B4 | **Forms por-estado** (EN/edit `:372-449`, NLD/edit `:248-325`, legalization `:234-454` switch por `case_type`) | Cada estado renderiza campos/acciones estructuralmente distintos; lógica de flujo legítima. Al migrar al shell se preservan en el body |
| B5 | **Sub-tablas inline de novedades asociadas** (`NoveltyLiquidationDocs/view.php:158`) | Sub-entidad de dominio; grid inline canon-consistente (estilo Invoice), sin análogo en Invoice |
| B6 | **`add.php` legacy `.card.card-primary`** en los 6 | Deuda diferida (→ modal/stepper); la entidad aún no existe → sin pipeline ni shell |
| B7 | **`preview_import.php`** (PaymentScheduling) pantalla off-canon de import Excel | Interstitial sin análogo en Invoice; A internos de bajo valor |
| B8 | **Filtros reducidos / conteos de columna por dominio** (Advance sin panel Filtros; PaymentScheduling solo Estado; 6ª col Legalización de Advance) | Reflejan ejes filtrables/atributos propios del dominio |
| B9 | **Reutilización que SUPERA a Invoice** (no tocar): `confirm_payment_card` (los 5 lo usan, Invoice inlina `edit.php:825`); `change_history` (Novelty lo usa, Invoice inlina `view.php:633`); `documents_section` en view (módulos lo usan, Invoice/view inlina `:561`) | Los módulos son **más** limpios que Invoice aquí; alinear "hacia Invoice" empeoraría. Invoice es el outlier de element-reuse — fuera de dirección |
| B10 | **Header helper-set `.spi-page-header`/`.spi-breadcrumb`/`.spi-edit-id-chip`** (11 templates) | Son clases canónicas reales (systematización de `styles.css`), NO deriva ad-hoc — pero ver C2 |
| B11 | `link_invoices_modal` + `link_candidates` (Advance/Refund/PettyCash/PaySched); `regress_status_modal` ausente en Novelty (reject terminal) | Reutilización compartida ejemplar / modelo de regresión distinto, documentado |

### (C) Zona gris → DECISIÓN del usuario

| # | Decisión | El dilema | Recomendación |
|---|---|---|---|
| **C1** | **Ancla de `.spi-edit-shell-form` en `Advances/legalization`** | No hay `<form>` único que envuelva el cuerpo (forms-por-estado, `legalization.php:234-454`). La clase es solo un *hook flex*, así que un `<div class="spi-edit-shell-form">` no-form es técnicamente limpio | Aplicar la clase a un `<div>` envolvente (las forms por-estado quedan anidadas), o variante de shell bespoke. **El resto del shell (head/body/col/footer) aplica sin cambios** |
| **C2** | **Idioma de header (+ homógrafo + `.btn-ghost-card`)** | Dos idiomas canónicos: helper-set `.spi-page-header`/`.spi-breadcrumb`/`.spi-page-title`/`.spi-edit-id-chip` (11 templates) vs inline `.spi-title-page` (Invoice). **Invoice es internamente inconsistente** (view≠edit) → "alinear a Invoice" es ambiguo. Además `.spi-title-page` vs `.spi-page-title` son near-homógrafos del mismo h1 | Elegir UN idioma + UN token de título y converger los 12 templates; **matar uno de los dos homógrafos**. (Por adopción/abstracción, el helper-set es el target más fuerte; pero por el marco "Invoice=canon" requiere tu visto bueno.) `.btn-ghost-card` vs `.btn-default` (A10) se pliega aquí |
| **C3** | **Idioma de campos de detalle** | CUATRO idiomas conviven: (1) Invoice inline-grid+`.field-row` (canon), (2) `.row.g-0`+`border-right`+`.field-row` (5 views, A2, sancionado), (3) `.spi-info-grid`/`.spi-info-cell` huérfano (solo EN/edit `:178`), (4) copia correcta de PettyCash/view | Estandarizar en (1) `.field-row`+grid inline (canon real). **Decidir si se deprecia `.spi-info-grid`/`.spi-info-cell`** (`components.css:2301-2335`) o se promueve a canon (migrando Invoice a él, lo que invierte la dirección) |

### Falsos positivos descartados por el adversarial (no son trabajo)

- **`.spi-info-grid` ≠ canon** — el brief lo listaba como canon de view; verificado: Invoice usa `.field-row`. `.spi-info-grid` de EN/edit es un *tercer* idioma visualmente distinto (4-col con bordes), **no** "canon correcto".
- **`.card` vs `.spi-card`** — ambos `border:0;radius:0;box-shadow:none` → **cero delta visual**. Solo higiene de token (A3, "renombrar al pasar por el archivo").
- **Refund "Facturas Agrupadas"** — es tabla 5-col con pill de pipeline (B1), no lista plana → **no** convertir a `.row-flex`.
- **Swatch de tipo / `.doc-row`/`.doc-icon` / hooks `.spi-beneficiary-*`** — color-key de calendario / reuso correcto de clase canon / hooks JS. No son re-implementaciones de átomos.

---

## 4. Priorización del refactor (roadmap ejecutable)

> Sin ejecutar nada. Ordenado por impacto/esfuerzo. Validación visual entre olas (Playwright + smoke manual del usuario).

### Ola 1 — Migración al `.spi-edit-shell` (≈80 % del valor; A1 + A8)

El único defecto estructural. **3 tiers por dificultad:**

1. **Fácil — PettyCash, Refund, PaymentScheduling.** Ya emiten el `.spi-edit-footer` correcto (`PettyCashRecords/edit.php:467`, `Refunds/edit.php:419`, `PaymentSchedulings/edit.php:253`); solo hay que envolver en `.spi-edit-shell`, mover el header a `.spi-edit-shell-head`, poner `.spi-edit-shell-form` en el `Form->create`, renombrar el body a `.spi-edit-shell-body` con `.col-lg-3/9 .spi-edit-col`, y re-parentar el footer dentro del shell (A8 se resuelve gratis → `position:static`).
2. **Medio — EmployeeNovelties/edit, NoveltyLiquidationDocs/edit.** Sin footer; advance es **por-estado** (B4). Consolidar los submit/avanzar en `.spi-edit-footer-actions` **preservando los cuerpos de form por-estado** en el body.
3. **Difícil — Advances/legalization.** **Bloqueado por C1** (sin form único). Decidir el ancla antes de migrar; el resto del shell aplica igual. Soportes con firma (B2) y forms-por-estado (B4) quedan como body content, no se colapsan.

### Ola 2 — Lote cosmético (bajo riesgo, alto orden visual)

- **A2** — tarjeta de detalle a `.spi-card`+grid inline `1fr 1fr;gap:28px` en los 5 views (← **decidir C3 primero**).
- **A3** — `.card`+padding → `.spi-card` (al tocar cada edit en Ola 1).
- **A5** — 2 botones-remove → `.btn-icon`/`.btn-danger`.
- **A6** — callout legalization → `.banner.info`.
- **A4** — `active.php` → `.chip` + `.spi-card` (o retirarlo).

### Ola 3 — DRY + decisiones de proyecto

- **A7** — extraer `element('upload_doc_modal')` compartido (5 módulos + Invoice).
- **C2** — resolver idioma de header project-wide (+ matar homógrafo, + A10) y aplicarlo al migrar los headers en Ola 1.
- **C3** — resolver idioma de campos de detalle (deprecar o promover `.spi-info-grid`).

### NO tocar (todas las B)

Tablas agrupadas de dominio, soportes con firma de Advance, side-rail+swatch de calendario, forms por-estado, sub-tablas de novedades, `preview_import`, filtros reducidos, reuso que supera a Invoice (`confirm_payment_card`/`change_history`/`documents_section`), `link_invoices_modal`, reject terminal de Novelty.

### Pistas fuera de núcleo (decisión aparte)

- **add → modal/stepper** (deuda; specs del design file en §2.6).
- **Adopción de overlays** (toast/notif/cmdk/drawer/banner ya en `components.css`).

---

## 5. Decisiones que necesito de ti (las 3 (C))

1. **C1** — ¿`.spi-edit-shell-form` sobre un `<div>` envolvente en legalization (las forms por-estado anidadas), o variante de shell bespoke?
2. **C2** — ¿idioma de header único? Helper-set `.spi-page-header` (11 templates, más DRY) **o** inline `.spi-title-page` (Invoice). Y ¿cuál de los homógrafos `.spi-title-page`/`.spi-page-title` se elimina?
3. **C3** — ¿campos de detalle en `.field-row`+grid inline (canon real de Invoice) y **deprecar** `.spi-info-grid`/`.spi-info-cell`? ¿O promover `.spi-info-grid` a canon (migrando Invoice)?

---

## Anexo — Artefactos y reconciliación

- **Design file de Claude** (prototipos JSX + `Componentes Faltantes.html` + 5 chats) extraído en `~/.claude/projects/-home-alexander-Documentos-dev-sgi/design-ref/sistema-de-gesti-n-interna/`. Es la fuente de la que derivan los templates de Invoice (`lista-facturas.jsx`/`detalle-factura.jsx`/`editar-factura.jsx`/`novedades.jsx`).
- **Salida cruda del workflow** (canon + 5 módulos + 4 ejes + adversarial) en `~/.claude/projects/-home-alexander-Documentos-dev-sgi/design-ref/_audit_out/`.
- **Reconciliación con auditorías previas:** `docs/auditoria-vista-modulos-flujo-2026-05-30.md` (A9/A10) concluyó que `.spi-edit-shell` era "solo-Invoice superior" y migró Invoice/view AL grid. Bajo este marco visual, **A9 se revierte**: el shell es el canon de edit y los 5 módulos lo adoptan (A1). La migración de Invoice/view al grid (A10) sigue vigente — el grid ES el canon de *view*. No hay conflicto: shell=edit, grid=view.
