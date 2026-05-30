# Avance de ejecución — Paridad de la capa de vista (Olas 1-3)

> **Fecha:** 2026-05-30 · **Estado:** ejecutado en `main`, validado visualmente.
>
> Documento **compañero** de [`auditoria-vista-modulos-flujo-2026-05-30.md`](auditoria-vista-modulos-flujo-2026-05-30.md) (la auditoría base de la capa de vista — ViewModel/Presentation/templates). Aquí se registra el **delta de implementación** de las Olas 1-3 de ese roadmap: qué se ejecutó, con qué commits, qué se reclasificó con evidencia nueva, y qué queda. Es el equivalente, para la **capa de vista**, de lo que [`avance-paridad-olas-1-4.md`](avance-paridad-olas-1-4.md) es para la capa estructural/backend.
>
> ⚠️ **Numeración independiente.** Los códigos A1–A13 / C1–C9 de **este** documento son los de la auditoría **de vista** y **no** coinciden con los de la auditoría estructural (que tienen el mismo formato pero distinto significado). Aquí, p. ej., **C1 = "VM read-only para `view`"** y **C2 = "RowView generalizado"**, no lo que esos códigos significan en el tracker estructural.

---

## Cómo leer las señales

Mismas convenciones que el tracker estructural: lo único que se migra es la **deriva accidental (clase A)**; las **(B)** son diferencias esenciales de dominio (no tocar) y las **(C)** son zonas grises que requieren decisión. Un ítem ✅ está cerrado; ⚠️/🔵 describen el estado final aceptado, no tareas pendientes.

---

## Resumen por ola

| Ola | Foco | Ítems ejecutados | Commits | Resultado |
|---|---|---|---|---|
| **1** | Colapsar mapas estado→pill + dead-code | A1, A4, A5, A11, A12 | `44acb33` | ✅ 219/219 PHPUnit · `php -l` limpio · **2 drifts visuales reales corregidos** |
| **2** | VM read-only de `view` + RowView (decisiones C1, C2) | C1, C2 (6 módulos) | `0ab9da5` (piloto Refund), `fbb0d1f` (5 módulos) | ✅ 219/219 · cross-check de accesores · validado visualmente |
| **3** | Element compartido + layout + docblock | A8, A10, docblock | `88aeb00`, `f0a6911`, `656be1e` (doc) | ✅ 219/219 · validado visualmente. **A9 reclasificado a (B)**; **A6 skip** |

**Matices de ejecución a registrar:**

- **A1 — el diff celda-a-celda evitó un barrido a ciegas.** El adversarial exigió comparar cada array de pills inline contra su `*Presentation::STATUS_BADGES` antes de unificar. Resultado: solo 2 eran drifts reales (`Invoices/edit` pintaba `verificacion_pago` como `info-soft` ≠ const `warning-soft`; `EmployeeNovelties/view` tenía un mapa stale con `rrhh: info-soft` ≠ `accent-soft` + 3 claves faltantes que caían a `pill-muted`). El resto eran copias idénticas o no-ops redundantes. Alcance real: 6 ediciones triviales, no un refactor.
- **A4 — `STATUS_ICONS` muerto en 6/6, no 4/6.** La re-verificación adversarial (P1) corrigió la estimación: la const era dead-code en los 6 módulos, incluida la cadena VM→template de Novelty (`statusIcons` se asignaba pero nunca se renderizaba). Se borró la const + 2 props muertas de VM + 2 alias de template.
- **C1/C2 (Ola 2) — piloto antes de replicar.** Se implementó primero en **Refund** (`ViewViewModelInterface` + `RefundViewViewModel` + `RefundRowView` + `forRow()`), se validó, y recién entonces se replicó a los otros 5 con un workflow multi-agente. Verificación central post-workflow: `php -l`, cross-check de que cada `$viewModel->X`/`$row->X` existe como propiedad, y que ningún `view()` deja variables sueltas sin setear (Invoice/EN/NLD conservan su `compact()` y solo añaden el VM; los demás asignan todo desde el VM).
- **C2 — caveat P9 atendido.** Tener `RowView` no basta: el propio `Invoices/index` re-derivaba variantes pese a usar `forRow()`. Al generalizar se movió esa lógica (`pipelineVariant`/`stageIdx`/`pillClass`) **dentro** de `forRow()`/`InvoiceRowView` (ampliado a 13 campos).
- **A3 reclasificado a no-deriva.** La premisa del audit ("`statusBadgeMap` es alias muerto de `badgeColors`") es **falsa**: el código muestra dos roles distintos — `statusBadgeMap` = pill del estado del header; `badgeColors` = pills de estado de **documentos** (pasado a `document_row`). Coincidir en la misma const no los hace alias. No se tocó.
- **A9 reclasificado a (B) esencial — la validación visual refutó el audit.** El audit marcó `Invoices/edit` (que usa `sgi-edit-shell`) como deriva ("template viejo sin migrar"). El navegador mostró lo contrario: `sgi-edit-shell` es un **app-shell de altura completa** (header **fijo** + body con scroll interno + footer sticky) **más sofisticado** que el grid canónico para el form más pesado. Migrarlo perdería el header fijo (regresión UX). Diferencia esencial, no deriva.
- **A6 skip recomendado.** Unificar el método de entrada en `add` (`get_object_vars`/props sueltas → `set('viewModel')`) es churn sobre forms `add` **legacy** (canon B), e Invoice exigiría rediseñar `InvoiceAddViewModel` para cargar dropdowns. Bajo valor; se perdería al reescribir esos `add`.

---

## Estado de divergencias (capa de vista)

Leyenda: ✅ ejecutado · 🔄 reclasificado · ⏸ diferido · ◻ decisión pendiente · ❎ skip.

| # | Divergencia | Estado | Commit / nota |
|---|---|---|---|
| **A1** | Mapas estado→pill inline → `*Presentation::STATUS_BADGES` (6 templates) | ✅ ejecutado | `44acb33` — 2 drifts reales corregidos de paso |
| **A2** | `PaymentScheduling/edit` recomputa el pill en vez de usar `$viewModel->currentStatusBadge` | ✅ ejecutado | Ola 4 — solo restaba `edit.php` (`view.php` ya lo leía tras C1); ahora `[$psStatusLabel,$psStatusPill]=$viewModel->currentStatusBadge` + import `PaymentSchedulingPresentation` huérfano eliminado |
| **A3** | "Doble alias `statusBadgeMap`/`badgeColors`" en Novelty | 🔄 no-deriva | dos roles distintos (header vs docs); no se toca |
| **A4** | `STATUS_ICONS` dead-code | ✅ ejecutado | `44acb33` — borrado en las 6 Presentations + props/alias muertos (6/6, P1) |
| **A5** | `READY_FOR_PAYMENT_BADGES` dead const | ✅ ejecutado | `44acb33` — `SharedPresentation` + import huérfano |
| **A6** | Unificar método de entrada en `add` | ❎ skip recomendado | churn en forms legacy; Invoice exigiría rediseñar el AddVM |
| **A7** | `view` re-pinta docs/pagos a mano teniendo elements | ✅ ejecutado (docs) | Ola 4 — `PettyCash/view` + `PaymentScheduling/view` migrados a `documents_section` read-only (VMs exponen `documentRows`). Toda `view` (salvo Invoice, outlier propio) delega ya en el element. Pagos a mano = fuera de alcance (otra sub-tabla) |
| **A8** | Extraer element `change_history` (historial duplicado en EN/NLD) | ✅ ejecutado | `88aeb00` — `templates/element/change_history.php` (param `title`/`showNoveltyLink`) |
| **A9** | `Invoices/edit` `sgi-edit-shell` → `sgi-invoice-view-grid` | 🔄 reclasificado a (B) | `656be1e` — `sgi-edit-shell` es superior (header fijo); NO migrar |
| **A10** | `Invoices/view` + `PettyCash/view` grid inline → `sgi-invoice-view-grid` | ✅ ejecutado | `f0a6911` — validado visualmente (render idéntico) |
| **A11** | Over-fetch `operationCenters` en `PaymentSchedulingAddViewModel` | ✅ ejecutado | `44acb33` — fetch + prop + paso eliminados |
| **A12** | Sets muertos `linkedInvoices`/`linkedTotal` en `Advances::view` | ✅ ejecutado | `44acb33` |
| **A13** | Filas de `index` con `onmouseenter/leave` inline | ✅ ejecutado | Ola 4 — las 6 filas `<a role="row">` adoptan el componente canónico `.row-fact` (bg/hover/transition/cursor/separador `+`-sibling); se eliminan 12 handlers JS inline + props redundantes. `padding:14px 18px` preservado inline (idéntico por construcción). El `.clickable-row` del audit no aplicaba: son `<a>`, no `<tr data-href>` |
| **C1** 🔑 | VM read-only para la acción `view` en los 6 | ✅ ejecutado | `0ab9da5`+`fbb0d1f` — `ViewViewModelInterface` + 6 `{Modulo}ViewViewModel` |
| **C2** 🔑 | `RowView` generalizado (lógica dentro de `forRow()`) | ✅ ejecutado | `0ab9da5`+`fbb0d1f` — 5 RowViews nuevos + Invoice ampliado (P9) |
| **C3** | Slot/builder compartido para el `ob_start` del sidebar (`registryLines`/`actionsHtml`) en cada `edit` | ✅ ejecutado (parcial) | Ola 4 — extraído `element('pipeline_regress_action')` (botón regresar, **idéntico** en PettyCash/Refund/PaymentScheduling). `registryLines` se deja bespoke por dominio (no es duplicación real: contenido distinto por módulo) |
| **C4** | Extender `Support/PipelineEditFlags` a Novelty | 🔄 (B) esencial — **no extender** | Investigado: `PipelineEditFlags` cablea la forma `agrupacion→contabilidad→tesoreria` (`showAccounting`=idx≥1, `showTreasury`=idx≥2). Novelty arranca `registro→aprobacion→GDP→…` con skips por tipo y ya usa un modelo más expresivo (`editableFields`/`visibleSections`/`effectiveStatuses`). Forzar el helper lo vaciaría de semántica o nombraría mal los pasos. Sin duplicación que centralizar |
| **C5** | `email_log_panel` solo en Invoice + EN/edit | 🔄 (B) esencial — **no es gap** | Investigado: solo Invoice (`sendApprovalLinkNotification`) y EmployeeNovelty (`sendNoveltyApprovalEmail`) emiten correos (únicos `entity_type` en `email_logs`: `invoice`/`employee_novelty`). Refund/PettyCash/PaymentScheduling/Advance/NLD **no** llaman a `NotificationService` ni tienen aprobación externa → el panel aparece donde hay correos. Alinear renderizaría paneles vacíos. |
| **C6** | `document_row` directo vs `documents_section` en 3 `view` | ✅ resuelto → canon = `documents_section` read-only | `documents_section` ya soporta read-only (`uploadModalId => null`); Refund/NLD/EN ya lo usaban. Alineados los 2 rezagados (PettyCash/PaymentScheduling/view). No hizo falta flag `readOnly` nuevo |
| **C7** | Estandarizar `final readonly class` | ✅ ejecutado | Ola 4 — 20 VMs (Add/Edit/View + sub-VMs `Invoice/*` + `Support/PipelineEditFlags`) pasados a `final readonly class`; `readonly` redundante quitado de props (es fatal en `readonly class`). `PaymentOptions`/`SubmitButton` se dejan `final class` (solo métodos `static`, sin props → readonly no aplica). Behavior-preserving |
| **C8** | `AddViewModel` muta el ORM (Advance/Invoice) | ✅ ejecutado | Ola 4 — `Invoice/AdvanceAddViewModel` ahora son DTO de transporte puro; el entity-building (defaults + whitelist CR-001 + guard beneficiario + `patchEntity`) se movió inline a `{Invoices,Advances}Controller::add()` (canon Refund). Behavior-preserving; VMs quedan cs-clean |
| **C9** | Grids inline de sub-tablas (facturas/pagos) sin element | ◻ decisión pendiente | exigiría crear un element nuevo, ganancia marginal |

**Decisión de arquitectura mayor (cerrada):** ViewModel y Presentation **coexisten** con responsabilidades disjuntas (per-request vs const-maps estáticos); **no fusionar** (B1). Acoplamiento mutuo nulo, dirección única VM→Presentation.

---

## Validación visual (Playwright, 2026-05-30)

Índices y vistas de los 6 módulos renderizados en el navegador, **0 errores de console** en todas las páginas con datos:

| Módulo | index | view | Nota |
|---|---|---|---|
| Invoice | ✅ | ✅ | el más pesado; `InvoiceViewViewModel` + RowView (P9) + A10 |
| Refund | ✅ | ✅ | piloto |
| EmployeeNovelties | ✅ | ✅ | element `change_history` |
| NoveltyLiquidationDocs | ✅ | ✅ | `change_history` con link |
| Advance | ✅ | ✅ | `AdvanceRowView` (pipeline + legalización) |
| PettyCash | ✅ (empty) | n/a | sin registros; index empty-state OK, view no testeable |
| PaymentScheduling | ✅ (empty) | n/a | ídem |

---

## Anexo — Eliminación de captura de firma (fuera del audit de vista)

Hallazgo durante la validación visual: 404 `/marked_as_signed` en `NoveltyLiquidationDocs/view`. Al investigarlo se decidió (con el usuario) **eliminar la captura de firma desde el sistema** (canvas de dibujo + dispositivo ePad + imagen) y dejar solo el toggle **"marcar como firmado"**. El flujo pasa a: el usuario descarga el documento, lo firma fuera, lo re-sube como documento normal y marca "Firmado".

- **Fase A — fix + vestigial:** `NLD/view` ya no renderiza el sentinel `signature_path='marked_as_signed'` como `<img>` (404 eliminado, badge "Firmado"); quitados los `script()` vestigiales de `NLD/edit`.
- **Fase B — quitar captura:** eliminado el campo de firma (imagen + canvas) en `EmployeeNovelties/add` y su manejo en `EmployeeNoveltiesController::add()`. La columna `employee_signature` se **conserva** (sin migración): datos históricos siguen renderizando; solo se deja de poblar.
- **Fase C — código muerto:** borrados `sgi-signature.js`, `sgi-epadlink.js`, `NoveltySignatureService`, `LeaveSignatureService`; quitadas inyecciones DI en 2 controllers + `Application.php`; docs actualizados.
- Commit: `2f2c00a` · 219/219 tests · 404 confirmado eliminado en navegador.
- **Cabo suelto (✅ resuelto, Ola 4):** el checkbox `requires_employee_signature_creation` quedó **inerte** en el admin de `NoveltyTypes` (su única UI consumidora se eliminó). Retirado: 2 casillas UI (`add.php`/`edit.php`) + clave muerta del JSON de `getFlags()` + `$_accessible`/`validator->boolean`. La **columna de BD se conserva** (sin migración, igual que `employee_signature`); solo se retira la superficie editable/expuesta.

---

## Siguientes pasos

**Lote rentable (✅ ejecutado — Ola 4, validado: 219/219 PHPUnit · `php -l` limpio · `cs-check` sin nuevos errores):**
1. ~~**A2**~~ ✅ — `PaymentScheduling/edit.php` lee `$viewModel->currentStatusBadge` (`view.php` ya lo hacía tras C1); import huérfano eliminado.
2. ~~**C3**~~ ✅ (parcial) — extraído `element('pipeline_regress_action')` (botón regresar idéntico en PettyCash/Refund/PaymentScheduling). `registryLines` se mantiene bespoke por dominio (no era duplicación real).
3. ~~Retirar checkbox inerte~~ ✅ — `requires_employee_signature_creation` retirado de UI (`add`/`edit`) + `getFlags()` + `$_accessible`/`validator`; columna BD conservada.

**Decisiones tuyas — todas resueltas:**
4. ~~**C6 / A7**~~ ✅ resuelto → canon = `documents_section` read-only (`uploadModalId => null`; ya lo usaban Refund/NLD/EN). Alineados los 2 rezagados (`PettyCash/view` + `PaymentScheduling/view`); VMs exponen `documentRows`. No hizo falta flag nuevo. Toda `view` delega en el element salvo Invoice (outlier reconocido).
5. ~~**C5**~~ ✅ resuelto → **(B) esencial, no es gap.** Solo Invoice y EmployeeNovelty emiten correos (`sendApprovalLinkNotification`/`sendNoveltyApprovalEmail`; `entity_type` `invoice`/`employee_novelty`). Los otros 4 módulos no usan `NotificationService` → `email_log_panel` aparece donde hay correos; alinear daría paneles vacíos. Sin trabajo.

**Bajo retorno / opcional:** ~~A13~~ ✅, ~~C8~~ ✅, ~~C4~~ ✅ (B, no extender), ~~C7~~ ✅. Resta solo **C9** (element de sub-tablas, ganancia marginal + riesgo visual) — único pendiente de toda la auditoría de vista.

**NO tocar (B, esenciales de dominio):** dualidad VM/Presentation, soportes bespoke con firmas, `add` legacy, side-rail de Novelty, Advance vía Invoices, forms-por-estado, naming `AdvanceLegalization*`, y `sgi-edit-shell` de Invoice/edit (A9 reclasificado).
