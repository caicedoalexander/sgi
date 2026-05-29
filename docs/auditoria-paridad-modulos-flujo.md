# Auditoría de paridad — Módulos de flujo vs Facturas (gold standard)

> Generado por auditoría multi-agente (27 agentes: canon + backend×5 + templates×5 + nomenclatura + invoice-self + dead-code + verificación adversarial + síntesis). Fecha: 2026-05-28.
>
> Contexto: la migración de los módulos de flujo al estándar de Facturas se marcó como **"7/7 completa"** (commit `51d11bd`) y los docs de progreso (`docs/superpowers/`) fueron eliminados. Esta auditoría verifica el estado **real** contra el código vivo.

## Estado de ejecución (actualizado 2026-05-28)

Remediación por olas. Cada ola/sub se verificó con `php -l`, `phpcs` (0 violaciones netas nuevas) y la suite (`composer test`, 192/192). Los cambios de template se verificaron además en vivo con Playwright (ver "Verificación visual" abajo).

> **▶ Próximo paso al retomar:** **Ola 3c COMPLETA** (2026-05-29). Resueltos los 2 pendientes: (1) `PettyCash/edit` migrado a **`sgi-invoice-view-grid`** + `element('pipeline_sidebar')` + `sgi-edit-footer` (espejo de `Refunds/edit`, pipeline idéntico) — **NO `sgi-edit-shell`**: corrección del canon, el estándar real de la familia migrada es `sgi-invoice-view-grid` (los 4 hermanos edit ya lo usan; `Invoices/edit` con `sgi-edit-shell` es el outlier, ver Veredicto). `php -l` limpio, 192/192. (2) docs especiales de `Advances/legalization` = **EXCEPCIÓN LEGÍTIMA documentada** (Opción B): son docs con firma/estado fuera del contrato upload/delete de `documents_section`; migrarlos exigiría extender el `document_row` compartido (acoplado al JS uploader, 4+ consumidores) — mal trade. Siguiente: **Ola 4** (dead code) y **Ola 5** (canon Invoice + rename `*Service`→`*PipelineService`). El sub-paso de seguridad diferido (Novelty `#[Permission]`→`#[PipelineAction]`) va aparte (ver "Pendientes con revisión de seguridad").

- **Ola 0** ✅ — Decisiones de canon documentadas en CLAUDE.md (sección "Paridad de módulos de flujo") + excepciones legítimas (FieldAccessPolicy de Advance/PaymentScheduling, prefijo AdvanceLegalization, 2 controllers de Novelty, History services Advance/PaymentScheduling, trampa DIAN). Notas stale corregidas (SECTION_BY_STEP→SECTIONS_BY_STEP, pipeline_progress).
- **Ola 1** ✅ — Constantes→enum: PaymentScheduling delega `getNextStatus/getPreviousStatus` al enum (FORWARD/BACKWARD_TRANSITIONS eliminados); trait `GroupingPipelineConstantsTrait` disuelto → Refund/PettyCash delegan `STATUS_*` a su propio enum.
- **Ola 2** ✅ (decisiones tomadas: rename completo a `*PipelineService` en Ola 5; Refund migra a `State::getNextStatus`)
  - 2a: bug transaccional de PaymentScheduling cerrado (`advance()` atómico) + `applyPayments`/`linkItems`→`ServiceResult`.
  - 2b: Refund→`State::getNextStatus`; `RefundLockPolicy`+`RefundTransitionValidator` (States puros); `advanceStatus`/`regress`→`ServiceResult`; `RefundConstants::TRANSITIONS` a 0 refs. Revisado adversarialmente.
  - 2c: Novelty `recordStatusChange` dentro del transactional de `advance()`; States puros vía `NoveltyLiquidationGuard`; decisión regress = mantener `getPreviousStatus`/`previous` por contrato.
  - 2d: PettyCash lock→`PettyCashLockPolicy`; History services Advance/PaymentScheduling = divergencia legítima (documentado).
  - **DIFERIDO**: Novelty `#[Permission]`→`#[PipelineAction]` (ver sección "Pendientes con revisión de seguridad").
- **Ola 3 — Templates** (en curso, requiere verificación visual)
  - 3a ✅ — Clases CSS huérfanas `sgi-data-row/-label/-value` → `field-row`/`k`/`v` en 6 vistas (Advances/Refunds/PaymentSchedulings/EmployeeNovelties/NoveltyLiquidationDocs view + ExternalApprovals/review). 60 filas, diff simétrico 180/180. **REGRESIÓN VISUAL encontrada por verificación Playwright y arreglada**: en los layouts de 2 columnas (`.row.g-0 > .col-md-6`) el `field-row` (valor alineado a la derecha) colisionaba con la columna vecina porque las filas no tenían padding horizontal (las clases viejas `sgi-data-*`, sin estilo, alineaban a la izquierda). Fix: regla CSS scopeada en `components.css` — `.row.g-0 > [class*="col-"] > .field-row { padding: 0 18px }` (alinea con el `sgi-section-head` que ya lleva 18px; no afecta a field-rows en `.sgi-card`/canon Invoice). Verificado en vivo en Refunds/view (sin colisión, header alineado); las otras 4 vistas tienen markup idéntico.
  - 3b ✅ — `sgi-stage-actions`/`-head` huérfanas → `sgi-card`+`accent-strip`+`sgi-section-head` (Advances/legalization, 4 bloques); `btn-outline-secondary`→`btn-ghost-card` (preview_import). Reclasificado: `.card` crudo (no-op, lo estiliza el override) y `border-right:var(--rule)` (línea fina permitida) NO eran problemas.
  - 3c ✅ (2026-05-29) — **documents_section: COMPLETO**. Las 5 secciones de Soportes bespoke migradas a `element('documents_section')`: Refunds/view (read-only), Refunds/edit, EmployeeNovelties/edit (con agrupación+badges), PaymentSchedulings/edit, PettyCash/edit (todas con subida). **Bug crítico encontrado por revisión adversarial y arreglado en la raíz**: el element envolvía en `.sgi-card` pero `sgi-document-uploader.js` usa `list.closest('.card')` para acotar el contador → con ≥2 `.sgi-folder-count` en la página (Refunds/edit, PaymentSchedulings/edit, PettyCash/edit) el contador de docs corrompía otro contador. Fix: el element ahora envuelve en `.sgi-card card` (no-op visual, `.card{border:0}`) → `closest('.card')` acota correctamente para todos los callers. **timeline de historial: COMPLETO** — EmployeeNovelties/view + NoveltyLiquidationDocs/view migrados de tabla Bootstrap al timeline de Facturas (`.col-flex` + fila `.av av-sm` + flecha + old→new); NoveltyLiquidationDocs conserva el enlace a la novedad. **`PettyCash/edit`: COMPLETO (2026-05-29)** — migrado de grid Bootstrap (`row g-3`/`col-lg-4/8` + sidebar inline) a `sgi-invoice-view-grid` + `aside.sgi-invoice-view-left`→`element('pipeline_sidebar')` + `main.sgi-invoice-view-right` + header `sgi-page-header`/`sgi-edit-id-chip` + footer `sgi-edit-footer`, espejo de `Refunds/edit`. Eliminadas ~124 líneas de sidebar duplicado (hero/pipeline-v/acciones/registro). Preservada la lógica propia (sección notas, card "Etapa actual", banner enriquecido, payment_section, confirm_payment_card, modales, JS uploader). `php -l` limpio + 192/192. Verificación visual pendiente (requiere login; markup estructuralmente idéntico al hermano `Refunds/edit` ya verificado en vivo). **docs especiales de Advances/legalization: EXCEPCIÓN LEGÍTIMA** (no se migran) — ver "Soportes documentales" en Hallazgos.
- **Ola 4** 🔶 EN CURSO (2026-05-29) — **Backend dead code ELIMINADO** (re-verificado 0-refs por mí con grep + `php -l` + 192/192): `InvoiceConstants::TRANSITIONS`, `NoveltyConstants::TRANSITIONS`, `RefundConstants::TRANSITIONS`, `RefundConstants::BACKWARD_TRANSITIONS`, `PettyCashConstants::BACKWARD_TRANSITIONS` (+ comentario stale reescrito en `Refund/State/AutorizacionPagoState`), `InvoicePipelineService::getStatusIndex()`, `InvoiceApprovalService::getActiveApprovals()` y `getApprovalSummary()` (sin orfanatos: `_summaryFromApprovals`/`getCurrentApprovals`/`getApprovalSummariesBatch` siguen vivos). **OJO — corrección de un falso positivo del workflow:** `PettyCashConstants::TRANSITIONS` está **VIVO** (`PettyCashRecordsController:324`) → **NO se borró**. Además **elements huérfanos ELIMINADOS**: `element/copcsa.php`, `element/pipeline_progress.php` y `element/progress_stepper.php` (0 invocaciones — `progress_stepper` solo lo invocaba `pipeline_progress`, así que se borró la familia completa) + citas corregidas en `CLAUDE.md` y `arquitecture.md`. **PENDIENTE Ola 4** (sub-pasos aparte): (a) acción/ruta `advanceStatus` legacy (Invoice + PettyCash controller + `InvoicePipelineService::advance()`) — requiere **confirmar 0 integraciones externas POST** antes de borrar (NO tocar `PettyCashService::advanceStatus`, vivo vía `saveAndAdvance`, ni nada de Refund); (b) `FIELDS_BY_STEP`/`SECTIONS_BY_STEP`/`fieldsByStep`/`sectionsByStep`/`pipelineKey` en PettyCash/RefundFieldAccessPolicy — requiere refactor de la base `PipelineFieldPolicy` (no es borrado trivial).
- **Ola 5** 🔶 EN CURSO — Fixes del canon Invoice + rename `*Service`→`*PipelineService` (decidido: rename completo). **Fixes APLICADOS (2026-05-29, php -l + 192/192):** `getNextStatus($currentStatus, $invoice->document_type)` en `InvoicesController:369` (respeta DocumentTypePolicy); `final class InvoiceFieldAccessPolicy`; comentario inline en `DIAN_REJECTED` (género divergente deliberado); `'+48 hours'` → `InvoiceConstants::APPROVAL_TOKEN_HOURS` en `InvoiceApprovalService:80` (2.ª instancia, fuera del item original). **Diferidos (no triviales):** (a) magic `'approve'`/`'reject'` → `APPROVAL_ACTION_*`: son 10 usos en 4 archivos (InvoiceApprovalService×3, ExternalApprovalsController×1, InvoiceApprovalStrategy×2, **NoveltyApprovalStrategy×4**) → requiere decidir si la constante vive en `InvoiceConstants` o en un `ApprovalConstants` compartido (Novelty también la usa); (b) `FIELD_LABELS` `payment.*`: hay una clave **dinámica** (`'payment.' . $field`, `InvoicePaymentService:488`) → mejor un fallback "Pago - X" en el sitio de lookup que claves fijas; (c) `add.php:187` literal `'aprobacion'`. **Blast radius del rename mapeado (2026-05-29):** renombrables = `RefundService` (8 archivos src), `PettyCashService` (10), `NoveltyService` (10, 2 controllers), `PaymentSchedulingService` (7, el menos disruptivo; ya alinea `validateTransitionRequirements`/`advance`/`regress` con Invoice). **`AdvanceLegalizationService` EXCLUIR del rename**: NO es coordinador de pipeline (Advance reusa `InvoicePipelineService`); es servicio de dominio (firmas/outcomes/refunds), 19 src + 2 tests — requiere decisión de canon Advance/AdvanceLegalization aparte. `InvoicePipelineService` ya cumple. Corrección de drift: el verbo `regressStatus` citado en el doc **no existe** (el set real de regreso es `regress`/`reject`). Rename de clase ≠ convergencia de verbos (dos tareas distintas).

### Verificación visual (Playwright, 2026-05-28, server :8000)

Verificado en vivo en el navegador (login admin):
- ✅ **Fix del contador de documentos** (bug crítico de 3c): en `/refunds/edit` con 2 `.sgi-folder-count`, `#docs-list.closest('.card')` resuelve a `#docs-folder-count` (el contador de documentos), no al de Facturas Agrupadas. Confirmado por DOM.
- ✅ **3a field-rows**: tras arreglar la regresión de colisión (regla CSS), Refunds/view renderiza key/value limpio en 2 columnas (sin colisión, header alineado).
- ✅ **3c documents_section**: Refunds/view (empty-state read-only) y Refunds/edit (dropzone canónica + wiring de subida) renderizan correctamente.
- ✅ **3b accent cards**: `Advances/legalization` "Acción del paso actual" renderiza como `sgi-card` con barra de acento verde.
- ✅ 0 errores de consola en las páginas probadas.
- ⬜ NO verificado visualmente (faltan registros con datos): timeline de historial de Novelty, vistas/edits de los demás módulos. Cubiertos a nivel de código (php -l, revisión adversarial, 192/192 tests) + la regla CSS de colisión aplica por igual (markup idéntico).
- Nota: se creó un reintegro de prueba `REI-26-001-0001` (id 2, estado agrupacion, sin facturas/docs) para la verificación — borrable.

## Resumen ejecutivo

La migración entregó la **columna vertebral del State pattern** (todos los módulos tienen `Pipeline/{Modulo}/` con interface + Registry + `State/` + ActionPolicy, enum `Domain/{Modulo}/PipelineStatus`, y History/Document services) pero quedó **incompleta en paridad de capa**. Score de paridad promedio ponderado **~67/100**.

| Dimensión | Score |
|---|---|
| Backend — PettyCash | 78 (mejor tras Invoice) |
| Backend — Refund | 68 |
| Backend — Novelty | 68 |
| Backend — Advance | 62 |
| Backend — PaymentScheduling | **58 (peor backend)** |
| Templates — PaymentScheduling | 78 (mejor templates) |
| Templates — Advance | 72 |
| Templates — Refund | 72 |
| Templates — PettyCash | 68 |
| Templates — Novelty | **62 (peor templates)** |
| Nomenclatura transversal | **52 (peor dimensión global)** |
| Invoice (invoice-self) | 82 (deuda propia de templates) |

Cuatro ejes de divergencia sistemática: (1) suite de Policy con asimetrías, (2) nomenclatura fragmentada del coordinador y sus métodos, (3) restos de Bootstrap/clases CSS huérfanas en templates, (4) dead code de migración a medias. Ninguna es bloqueante funcional, pero erosionan el valor del canon como plantilla replicable.

## Veredicto del gold standard

- **CONFIRMADO**: Invoice es el canon correcto para la **capa de servicios/pipeline** (State pattern limpio, DocumentTypePolicy vía factory, enum como fuente única, ServiceResult en todas partes, separación `FieldAccessPolicy` vs `LockPolicy`).
- **AJUSTE CRÍTICO**: para **templates**, el gold standard real es la **familia migrada** (`sgi-invoice-view-grid` + `sgi-page-header` + `element('pipeline_sidebar')`, definidas en `components.css:2269`, gap 14px). **Invoice es el outlier a alinear** — `Invoices/view.php` usa grid inline (340px+1fr, gap 16px) y `edit.php` usa `sgi-edit-shell`. `add.php` es legacy en TODOS (deuda compartida).
- **Mejor por capa**: backend → **PettyCash** (78); templates → **PaymentScheduling** (78, reuso ejemplar de elements compartidos).
- **Caso especial Advance**: único módulo SIN coordinador propio (reusa `InvoicePipelineService` sobre la tabla `Invoices`, porque un Anticipo ES un Invoice). Su State pattern es vestigial (`getNextStatus`/`getPreviousStatus` nunca consumidos). Nomenclatura mixta `Advance` (dir/enum/controller) vs `AdvanceLegalization` (clases).

## Hallazgos por tema (deduplicados)

### [HIGH] Suite de Policy incompleta: falta `FieldAccessPolicy` en Advance y PaymentScheduling
Advance solo tiene `AdvanceLegalizationActionPolicy`; PaymentScheduling solo `PaymentSchedulingActionPolicy`. **MATIZ (verificado adversarialmente): la ausencia es LEGÍTIMA**, no migración a medias — `AdvancesController::edit` es un redirect puro a `Invoices::edit` y `PaymentSchedulingsController::edit` no patchea campos del header por paso (solo items vía import). La maquinaria `filterEntityData/getEditableFields` no aplica. **NO crear policies vacías** — documentar la excepción.

### [HIGH] Fragmentación de nomenclatura del coordinador y sus métodos
Solo `InvoicePipelineService` lleva el sufijo `*PipelineService`. Los otros 5 usan `*Service` plano y Advance ni tiene coordinador propio. Verbos divergentes: `saveAndAdvance`/`advance`/`advanceStatus`/`advanceGroup`; `regress`/`regressStatus`/`reject`. Validación divergente: `validateTransitionRequirements`/`getTransitionErrors`/`validateTransition`/`validateGroupTransition`. Advance usa prefijo `AdvanceLegalization*` que contradice Controller/ruta/template `Advances`.

### [HIGH] Modelado de constantes: enum como código muerto y literales hardcodeados
- **PaymentScheduling**: los métodos del enum (`next`/`previous`/`isTerminal`/`label`/`rejectionTarget`) son **CÓDIGO MUERTO** — el service lee arrays `FORWARD_TRANSITIONS`/`BACKWARD_TRANSITIONS` y `STATUS_LABELS` de las constantes, rompiendo la dirección de delegación canónica (Constants→enum). `REJECTION_TARGET` hardcodeado ignora `enum::rejectionTarget()`.
- **Refund/PettyCash**: `GroupingPipelineConstantsTrait` define `STATUS_*` como **literales** en vez de delegar a `Domain/{Modulo}/PipelineStatus->value` — doble fuente de verdad no sincronizada (riesgo de drift).

### [HIGH] Clases CSS huérfanas usadas en templates pero NO definidas en CSS
`sgi-data-row`/`sgi-data-label`/`sgi-data-value` se usan en `Advances/view`, `Refunds/view`, `PaymentSchedulings/view`, `EmployeeNovelties/view` pero **NO están definidas en ningún CSS** — renderizan como texto plano sin layout key/value. También `Advances/legalization.php` usa `sgi-stage-actions`/`-head` no definidas. El canon usa `field-row` + `.k`/`.v` (`components.css:1084-1110`, usadas 21×). **Renderizan roto en producción hoy.**

### [MEDIUM] Coordinadores 'fat' y orquestación filtrada al controller
- `AdvanceLegalizationService` (761 líneas) hardcodea destinos `STATUS_*` en vez de delegar a `State->getNextStatus`.
- `RefundService` concentra transacción/lock/propagación; la regla de bloqueo de regresión vive DENTRO de `TesoreriaState` (viola pureza de State).
- `PettyCash` hardcodea la regla de regresión inline (divergente de su hermano Refund que la delega al State).
- **`PaymentScheduling` orquesta `applyPayments` + save de `pipeline_status` + `recordStatusChange` en el controller** (`advance()`), dejando el save de estado FUERA del `transactional` → **ventana de inconsistencia (bug latente)**.
- `NoveltyService::advance` no registra `recordStatusChange` (lo hace el controller, asimétrico con `advanceGroup`).
- Novelty States consultan la BD directamente vía `TableRegistry` (`ContabilidadState`, `RevisionFirmasState`, `GdpState`) violando el contrato de States puras.

### [MEDIUM] Inconsistencia de `ServiceResult`: arrays crudos y copy de UI en `->data`
`RefundService::advanceStatus/regress` y `PaymentScheduling::applyPayments/linkItems` retornan **arrays crudos** en vez de `ServiceResult`. Varios servicios usan `ServiceResult::ok('mensaje UI')` con copy humano en el slot `data` en vez de payload estructurado (`RefundPaymentService`, `LiquidationDocPaymentService`).

### [MEDIUM] History services con adopción inconsistente de interface/trait
4 combinaciones distintas de (interface, trait) entre 6 servicios del mismo rol. Invoice/Novelty implementan `HistoryServiceInterface` + trait. Refund/PettyCash solo el trait. **Advance/PaymentScheduling no usan ninguno** y carecen de `recordChanges()`/`FIELDS_TO_TRACK` — auditan ad-hoc (frágil).

### [MEDIUM] Restos de Bootstrap crudo en templates
`.card` crudo (con padding inline) en vez de `.sgi-card` en Refunds/view+edit, PettyCash edit (grid `row g-3`/`col-lg-*` en vez de `sgi-edit-shell`), Novelty view/edit, Advances/view+legalization. Tablas `table-sm table-light`. Historiales de Novelty en tabla Bootstrap en vez del timeline de Invoice. `Advances/view` tiene `border-right:1px` (**viola regla dura 'sin bordes'**). `preview_import` con `table-light`/`btn-outline-secondary`/alert legacy.

### [MEDIUM] Soportes documentales: markup bespoke en vez de `element('documents_section')` — ✅ RESUELTO (3c) salvo excepción legítima
`Refunds/view+edit`, `EmployeeNovelties/edit`, `PaymentSchedulings/edit` y `PettyCash/edit` **ya delegan** en el element compartido (Ola 3c). **Excepción legítima documentada — `Advances/legalization` (Soportes, ~165 líneas):** NO se migra a `documents_section`. Sus 3 bloques (relación de facturas, comprobante de consignación, historial de firmas) son **docs con firma/estado**, no docs CRUD: pills firmado/pendiente, form AJAX de reemplazo inline (`data-rel-doc-trigger`, vía `fetch()` no `SgiDocumentUploader`), metadata de consignación y filas de firma rechazada con motivo. `documents_section` delega cada fila a `document_row`, que está acoplado al contrato `document_row_template.php`↔`sgi-document-uploader.js` (slots `label/filename/badge/created/size/open-link/delete-btn`) y es consumido por Invoices/PettyCash/EmployeeNovelties/NoveltyLiquidationDocs. Forzar el caso exigiría extender `document_row` con slots bespoke (cambio transversal de alto blast radius para todos los consumidores) y aún así perdería semántica — **mal trade**. Es un caso fuera del contrato upload/delete del element (Opción B, 2026-05-29).

### [MEDIUM] Pagos: ausencia de PaymentService dedicado y modelo de rechazo divergente
Invoice y Refund extraen `{Modulo}PaymentService`; PettyCash concentra register/authorize/reject/confirm inline (~580 líneas; atenuante: sin tabla de pagos propia). **`LiquidationDocPaymentService` (Novelty) `rejectPayment` BORRA el pago sin motivo** (vs canon que persiste `status=rejected` + `rejection_reason`), sin `editPayment`/`idempotency_key`/eventos.

### [LOW] Paridad funcional menor en listados/paneles
Sin panel Filtros (Advance), sin columnas ordenables (Refund), sin card Historial en view (Refund/PettyCash pese a tener HistoryService), empty-state sin CTA (PaymentScheduling), botón Exportar muerto (`#btn-export-novelties`), sin select2 en catálogo (PaymentScheduling add). Tratar caso por caso (varios son omisiones legítimas por dominio).

### [LOW] Deuda propia del gold standard Invoice (NO propagar)
Templates desactualizados; `add.php` legacy con `pipeline_status='aprobacion'` hardcodeado; TTL token `'+48 hours'` en vez de `InvoiceConstants::APPROVAL_TOKEN_HOURS`; `getNextStatus` llamado sin `document_type` en `_buildEditViewModel`; magic strings `'approve'`/`'reject'`; `FIELD_LABELS` sin entradas `payment.*`; `InvoiceFieldAccessPolicy` no es `final`; CLAUDE.md cita `SECTION_BY_STEP` (real: `SECTIONS_BY_STEP` privada); trampa de spelling `DIAN_REJECTED='Rechazado'` vs `APPROVAL_REJECTED='Rechazada'` (documentar, NO unificar — rompe datos persistidos).

**Verificado (2026-05-29) contra código vivo:** TTL `'+48 hours'` **YA RESUELTO** (`ApprovalTokenService.php:52,55` usa `InvoiceConstants::APPROVAL_TOKEN_HOURS`); cita `SECTION_BY_STEP` en CLAUDE.md **YA CORREGIDA** a `SECTIONS_BY_STEP` (drift del doc). **Pendientes reales de Ola 5:** `add.php:187` literal `'aprobacion'`; `getNextStatus` sin `document_type` en `InvoicesController.php:369`; magic `'approve'`/`'reject'` (no existen `APPROVAL_ACTION_*`); `FIELD_LABELS` sin `payment.*` (`InvoiceHistoryService.php`); `final` en `InvoiceFieldAccessPolicy.php:19`; añadir comentario inline a `DIAN_REJECTED` advirtiendo que el género divergente es deliberado.

## Dead code CONFIRMADO (verificado: 0 referencias — seguro de eliminar)

> **✅ Ejecutado 2026-05-29** (backend): `InvoiceConstants/NoveltyConstants/RefundConstants::TRANSITIONS`, `RefundConstants/PettyCashConstants::BACKWARD_TRANSITIONS`, `getStatusIndex`, `getActiveApprovals`, `getApprovalSummary`; **elements** `copcsa.php` + `pipeline_progress.php` + `progress_stepper.php`. **Falso positivo corregido:** `PettyCashConstants::TRANSITIONS` está vivo (`PettyCashRecordsController:324`) — NO se borró. Pendientes: `advanceStatus` legacy (caveat integraciones externas) y `FIELDS_BY_STEP`/`SECTIONS_BY_STEP` (refactor de base). Ver "Estado de ejecución → Ola 4".

| Símbolo | Archivos | Nota |
|---|---|---|
| `InvoiceConstants::TRANSITIONS` | `InvoiceConstants.php` | Mapa legacy reemplazado por `State::getNextStatus`. 0 refs. |
| `NoveltyConstants::TRANSITIONS` | `NoveltyConstants.php` | Ídem. 0 refs. |
| ~~`GroupingPipelineConstantsTrait::BACKWARD_TRANSITIONS`~~ **YA HECHO** (trait disuelto en Ola 1, `135d773`). Residuo vivo verificado (2026-05-29): `RefundConstants::BACKWARD_TRANSITIONS` + `PettyCashConstants::BACKWARD_TRANSITIONS` (0 refs c/u) + comentarios stale | `RefundConstants.php:48`, `PettyCashConstants.php:48`, `Refund/State/AutorizacionPagoState.php:23,26` | Borrar esas 2 constantes huérfanas + comentarios. **NO confundir** con `PaymentSchedulingConstants::BACKWARD_TRANSITIONS` (vive). |
| ~~`RefundPipelineState::getNextStatus()` + 6 States~~ **CORRECCIÓN (drift, 2026-05-29)**: Ola 2b (`6afc0af`) invirtió la decisión — Refund avanza vía `State::getNextStatus()` (VIVO, `RefundService.php:129`). El muerto es `RefundConstants::TRANSITIONS` | `RefundConstants.php:37` (0 refs) | Borrar **solo** `RefundConstants::TRANSITIONS`; NO el `getNextStatus` ni los States (consistente con líneas 17/25). |
| `FIELDS_BY_STEP`/`SECTIONS_BY_STEP`/`fieldsByStep()`/`sectionsByStep()`/`pipelineKey()` | `PettyCash/RefundFieldAccessPolicy` | Inalcanzables (overridean `filterEntityData` completo). La visibilidad viva está en `PipelineEditFlags::fromRecord()`. Son `abstract` en la base → eliminar requiere dejar de extender `PipelineFieldPolicy`. |
| `element/copcsa.php` | `templates/element/copcsa.php` | Huérfano tras rediseño del sidebar (`cb0ec97`). 0 invocaciones. |
| `element/pipeline_progress.php` | `templates/element/pipeline_progress.php` | Huérfano tras unificación de steppers (`0e63f06`). CLAUDE.md/arquitecture.md lo citan stale. |
| `InvoicePipelineService::getStatusIndex()` | `InvoicePipelineService.php` | 0 call-sites. |
| `InvoiceApprovalService::getActiveApprovals()` / `getApprovalSummary()` | `InvoiceApprovalService.php` | 0 call-sites. |
| Acción `advanceStatus` + `InvoicePipelineService::advance()` (Invoice y PettyCash) | `InvoicesController`, `PettyCashRecordsController`, `InvoicePipelineService`, `config/routes.php` | Ruta legacy POST sin disparador de UI (el flujo real es `edit→saveAndAdvance`). **Confidence medium** (endpoint POST real; confirmar 0 integraciones externas antes de borrar). **Conservar** `RefundService::advanceStatus` (vivo). |

## Dead code DESCARTADO (NO tocar — tiene referencias) — registrado para no re-flaggear

- **`getRegressionLockMessage` (PettyCash inline)** — VIVO: `PettyCashRecordsController:347` + `PettyCashService:807` (regress). Es divergencia de estilo, no dead code.
- **Ausencia de `FieldAccessPolicy` en Advance/PaymentScheduling** — los `ActionPolicy` están vivos y referenciados (DI `Application.php:253/259`, controllers, ViewModels). La ausencia del `FieldAccessPolicy` es legítima (no editan header por paso).
- **`PaymentSchedulingService::getRegressionLockMessage()`** (stub que retorna null) — contrato simétrico (Null Object) consumido por `PaymentSchedulingsController:203` → ViewModel → template (`edit.php:77/80/391`). NO borrar.

## Roadmap de remediación (olas)

### Ola 0 — Decisiones de canon y documentación (prerequisito, sin código)
Resolver ambigüedades del canon ANTES de tocar código (evita churn por decisiones no tomadas).
- Documentar en CLAUDE.md el canon REAL de templates (familia `sgi-invoice-view-grid`; Invoice es el outlier; registrar drift gap 16→14).
- Decidir el contrato de nomenclatura del coordinador (`*PipelineService` objetivo vs aceptar `*Service`). **[DECISIÓN PENDIENTE]**
- Documentar excepciones legítimas: Advance/PaymentScheduling sin `FieldAccessPolicy`; Advance usa prefijo `AdvanceLegalization*` por la entidad; Novelty con 2 controllers servidos por un `NoveltyService`.
- Corregir notas stale: `SECTION_BY_STEP`→`SECTIONS_BY_STEP`; `pipeline_progress.php` ya no se usa en NoveltyLiquidationDocs; trampa spelling DIAN.

### Ola 1 — Constantes y delegación al enum (fundamento)
- PaymentScheduling: `getNextStatus/getPreviousStatus`/labels delegan al enum; eliminar `FORWARD/BACKWARD_TRANSITIONS`; `REJECTION_TARGET = enum::rejectionTarget()->value`.
- Refund + PettyCash: cada `{Modulo}Constants` delega a su propio enum; eliminar literales del trait.

### Ola 2 — Paridad de capa de servicios
- **PaymentScheduling**: mover `advance()` del controller a `PaymentSchedulingService::advance():ServiceResult` con `applyPayments`+save+`recordStatusChange` en UNA transacción (**cierra la ventana de inconsistencia**). Migrar `applyPayments`/`linkItems` a `ServiceResult`.
- **Novelty**: mover `recordStatusChange` a `NoveltyService::advance()` dentro del transactional; extraer queries de States a servicios inyectados; decidir `regress()` o eliminar `getPreviousStatus` muerto.
- **Refund**: extraer regla de regresión a `RefundLockPolicy`; validación a `RefundTransitionValidator`; migrar a `ServiceResult`. **Decidir fuente única de avance** (ver Ola 4). **[DECISIÓN PENDIENTE]**
- **PettyCash**: añadir `getRegressionLockMessage` a la interface + `TesoreriaState` (delegar como Refund). Opcional: extraer `PettyCashPaymentService`.
- **Advance**: consumir `State->getNextStatus` o documentar flujo bifurcante + eliminar métodos muertos. Añadir `HistoryNormalizationTrait`+`recordChanges()`+`FIELDS_TO_TRACK`.
- PaymentScheduling+Advance: añadir trait/recordChanges a sus HistoryService. Novelty: persistir rechazo con motivo o documentar.
- Migrar transiciones de Novelty controllers de `#[Permission(edit)]` a `#[PipelineAction]`.

### Ola 3 — Paridad de templates
- Reemplazar clases huérfanas `sgi-data-*` por `field-row`+`.k`/`.v` (Advances/Refunds/PaymentSchedulings/EmployeeNovelties view). `sgi-stage-actions` → `sgi-card`+`.accent-strip` (Advances/legalization).
- `.card` crudo → `.sgi-card`; eliminar `border-right` de Advances/view.
- PettyCash edit → `sgi-edit-shell` + reusar `element('pipeline_sidebar')` (elimina ~120 líneas duplicadas).
- Migrar soportes a `element('documents_section')`: Refunds/view+edit, EmployeeNovelties/edit; evaluar Advances/legalization.
- Historiales de Novelty → timeline de Invoice. `preview_import` → `sgi-card`.
- **Alinear el outlier Invoice**: `Invoices/view+edit` → `sgi-invoice-view-grid` (cierra drift). PettyCashRecords/view ídem.
- Eliminar/enrutar `active.php` de EmployeeNovelties. Cablear o eliminar `#btn-export-novelties`.
- Paridad menor: card Historial en Refund/PettyCash; CTA en PaymentScheduling; `Paginator->sort` en Refund; select2.

### Ola 4 — Limpieza de dead code confirmado
Eliminar (con verificación previa) en orden: `TRANSITIONS` de Invoice/Novelty; `BACKWARD_TRANSITIONS` del trait (+ comentarios); elements `copcsa.php`/`pipeline_progress.php`; `getStatusIndex`/`getActiveApprovals`/`getApprovalSummary`. Resolver residuo de Ola 2 (Refund `getNextStatus` vs `TRANSITIONS`; `FIELDS_BY_STEP`/`SECTIONS_BY_STEP`). `advanceStatus` legacy SOLO tras confirmar 0 integraciones externas POST.

### Ola 5 — Fixes localizados del canon Invoice + rename de nomenclatura
Fixes bajo riesgo de Invoice (`'+48 hours'`→constante, `document_type` en viewmodel, `APPROVAL_ACTION_*`, `FIELD_LABELS` payment.*, `final` en `InvoiceFieldAccessPolicy`, refactor cosmético `InvoiceApprovalService`). Rename incremental de nomenclatura (según decisión Ola 0).

## Pendientes con revisión de seguridad (diferidos)

- **Novelty: migrar transiciones `#[Permission(action:'edit')]` → `#[PipelineAction]`** (acciones `advance`/`reject` en `EmployeeNoveltiesController`, `advanceGroup` en `NoveltyLiquidationDocsController`). **DIFERIDO** (decisión 2026-05-28). Riesgo: `#[PipelineAction]` sin `step` salta el chequeo CRUD y delega la autorización al servicio, pero `NoveltyService::advance()` **no tiene RBAC propio** (depende solo del permiso `edit`). Voltear el atributo a secas dejaría las acciones **sin autorización**. Hacerlo bien requiere: (1) añadir `denialReasonForAdvance`/`canOperate` a nivel servicio en `advance`/`reject`, (2) verificar que `pipeline_permissions` tenga las filas correctas para los pasos de novedades, (3) prueba manual del flujo antes de desplegar. Tratar como sub-paso dedicado con revisión de seguridad.
