# Audit Backlog — Templates, CSS y JS

Hallazgos pendientes de la auditoría realizada el **2026-05-11** sobre `templates/`, `webroot/css/` y `webroot/js/`.

**Estado:** los **3 Críticos**, **los 12 Mayores** (MJ-001 a MJ-012 completos), **los 16 Minores** (MN-001 a MN-016, todos cerrados), **SG-001** (preload Inter WOFF2, −60% peso de fuente), **SG-006** (FullCalendar assets centralizados), **SG-008** (consolidación de `MAX_UPLOAD_BYTES` en `UploadConstants`) y **1 refactor derivado fuera del audit** (uniformación de los 6 edit templates) ya fueron resueltos. Quedan 7 Sugerencias discrecionales (SG-002 a SG-005, SG-007, SG-009, SG-010). Ver commits `6b12baa` → reciente. Solo quedan minores menores y sugerencias.

**Convenciones:**
- 🟠 Mayor — bloquea un sprint si se acumula.
- 🟡 Minor — backlog de polish.
- 🟢 Sugerencia — mejora incremental sin urgencia.
- Cada item incluye **ubicación (`path:line`)** y **pasos concretos**, no descripciones genéricas.

---

## 🟠 Mayores pendientes (0) — ¡todos cerrados!

### ~~MJ-005~~ ✅ — Performance frontend de CDNs  (RESUELTO completo)

**Aplicado:**
- **Pasos 1-2** (`e204fa6`): SRI + `preconnect`/`dns-prefetch` + `defer` en los CDNs originales.
- **Paso 3** (`a265c93`): ApexCharts y AutoNumeric cargadas bajo demanda (solo en templates que las usan).
- **Paso 3b** (`2de2727`): Select2 + jQuery cargadas bajo demanda en los 13 templates con `.select2-enable`/`.select2`.
- **Paso 4** (`300af41`): self-host de todos los vendors (~2 MB) en `webroot/vendor/`. Eliminada dependencia runtime de jsDelivr. SRI + crossorigin removidos (no aplican a same-origin). Hashes SHA-384 validados antes de removerlos.

**Impacto acumulado:**
- Vistas como `/dashboard`, `/invoices` (listado), `/employees`: descargan SOLO Bootstrap CSS+JS + Flatpickr + el icon font + sgi-common/dialogs. Antes descargaban 9 scripts CDN serializados.
- `/invoices/edit/<id>`: descarga AutoNumeric + Select2 + jQuery además (todos same-origin con `defer`).
- Sin internet: la app funciona completa.
- CSP estricto: no requiere entries para `cdn.jsdelivr.net`.

---

### ~~MJ-010~~ ✅ — `styles.css` monolítico  (RESUELTO en `fa16845`)

**Aplicado:**

1. **!important auditados (23)** — todos son overrides legítimos de Bootstrap/Flatpickr (no specificity wars). Comentarios faltantes agregados.

2. **box-shadow ofensor** — único caso real: knob del checkbox toggle. Reemplazado por `border 1px var(--border-color)`. Los 38 box-shadow restantes son legítimos: `box-shadow: none` (eliminar Bootstrap defaults) o `inset 2px 0 0 var(--primary-color)` (lenguaje de bordes del SGI: sidebar/accordion activos). Esto cierra también **MN-001**.

3. **Split de overrides de vendors** — styles.css pasa de **3350 → 2830 LOC (−520)**. 3 archivos nuevos:
   - `webroot/css/sgi-flatpickr-overrides.css` (187 LOC) — cargado en `layout/default.php` junto a Flatpickr.
   - `webroot/css/sgi-select2-overrides.css` (234 LOC) — cargado en `element/cdn_select2.php` (solo donde se usa Select2).
   - `webroot/css/sgi-fullcalendar-overrides.css` (101 LOC) — cargado en `EmployeeNovelties/{index,active}`.

4. **Variables CSS** — ya cubierto por MN-006 (commit `be8dc72`).

**Pendiente de seguimiento (no bloqueante):** split físico de `styles.css` en `core.css` + `app.css` para que login/external/error solo carguen lo mínimo. Requiere auditoría visual prolongada para identificar el subset estricto sin romper estilos.

---

### ~~MJ-012~~ ✅ — 80 líneas de pre-render en `Invoices/edit.php`  (RESUELTO en `39b889f`)

**Aplicado:** las ~80 líneas de derivaciones en `Invoices/edit.php:13-89` ahora viven en `InvoiceEditViewModel` como propiedades readonly calculadas en el constructor body. El controller no cambió (named arguments preservados). El template solo hace aliasing local y consume `$viewModel->*` + 3 helpers (`canEditField`, `isReadOnlySection`, `isCollapsibleSection`).

**Δ:** template 1175 → 1098 LOC (−77); ViewModel 67 → 212 LOC (+145 con phpdoc).

**Refactor derivado (`2fbd197`):** al revisar Invoices/edit el usuario preguntó por qué tenía más derivaciones que los demás. Se uniformó el patrón en los 6 templates de edit (Invoices, Refunds, PettyCash, EmployeeNovelties, NoveltyLiquidationDocs, Advances/legalization) → ver sección "Uniformación de edit templates" abajo. Cada ViewModel ahora encapsula las derivaciones, incluyendo `statusBadgeMap`, `pageTitle`, `currentStatusBadge`, `submitButton(Label,Class)` y opciones de pago vía `App\ViewModel\Support\PaymentOptions` (helper shared).

**Pendiente de seguimiento (no bloqueante):** considerar split del template `Invoices/edit.php` que aún tiene 1098 LOC (MN-009):
- `element/invoice_edit/header.php`
- `element/invoice_edit/sections/{general,dates,classification,revision,accounting,treasury,payment_authorization}.php`
- `element/invoice_edit/modals.php`

---

## ✅ Uniformación de edit templates (refactor derivado, fuera del audit original)

**Commit:** `2fbd197`. **Disparado por:** pregunta del usuario tras MJ-012 sobre por qué `Invoices/edit` tenía más derivaciones que los otros edit templates.

**Diagnóstico:** las "más validaciones" en Invoices NO eran arbitrarias — reflejan que el dominio de facturas es más complejo (6 estados + 1 terminal, 3 tipos de documento, granularidad por campo, multi-aprobador externo, render order dinámico). Los demás módulos son más simples (4-5 estados, 1 tipo de doc, granularidad por sección).

**Aplicado:** todos los 6 edit templates ahora comparten el mismo patrón "ViewModel completo, template solo lee":

| Template | Header LOC antes → después | ViewModel extendido con |
|----------|---------------------------:|--------------------------|
| `Invoices/edit` (commit `39b889f`) | 89 → 34 | `documentTypes`, `sectionFieldMap`, `renderOrder`, `submitButton*` |
| `Refunds/edit` | 86 → 44 | `statusBadgeMap`, payment options, `showAccounting/Treasury`, `invoiceOptions`, `canEditAccounting/Treasury`, `canSave`, `submitButton*`, `invoiceCount` |
| `PettyCashRecords/edit` | 80 → 50 (phpdoc) | mismas que Refunds |
| `EmployeeNovelties/edit` | 52 → 33 | `statusBadgeMap`, labels, `badgeColors`, `sections`, `showUploadSection`, `totalDocs` |
| `NoveltyLiquidationDocs/edit` | 54 → 33 | mismas que EmployeeNovelties + `period/signer/paymentLabels`, `isFinal`, `noveltyCount` |
| `Advances/legalization` | 48 → 30 | `pageTitle`, `beneficiary*`, `ps`, `linkedCount`, `diffBadgeClass`, `caseLabels` (vía `build()` array) |

**Helper compartido nuevo:** `src/ViewModel/Support/PaymentOptions.php` con `readyForPayment()` y `paymentStatus()`. Reemplaza el copy-paste literal que existía en 3 templates.

**Decisión de diseño documentada en cada ViewModel:** los `statusBadgeMap` específicos del header de edit NO se consolidan con `*Presentation::STATUS_BADGES` porque los colores son distintos (énfasis visual distinto entre listado y edición). Comentario explícito en cada constructor.

**Δ neto del refactor derivado:** +560 / −305 LOC (más en ViewModels con phpdoc, menos en templates).

**Pendiente:** los controllers usan dos patrones de paso al template:
- `$this->set('viewModel', $vm)` → template accede `$viewModel->X` (Invoices, Refunds).
- `$this->set(get_object_vars($vm))` o `$this->set($vm->build())` → variables directas (PettyCash, EmployeeNovelties, NoveltyLiquidationDocs, Advances).

No se uniformó esta diferencia (sería intrusivo en controllers). Ambos patrones resultan en "template solo lee, no calcula", que era el objetivo. Si se quiere uniformar en el futuro, preferir `$this->set('viewModel', $vm)` (más explícito, menos magia, mejor IDE autocomplete).

---

## 🟡 Minores (0) — ¡todos cerrados!

### ~~MN-001~~ ✅ — `box-shadow` en checkbox toggle  (RESUELTO en `fa16845`, junto a MJ-010)

**Aplicado:** removido el `box-shadow: 0 1px 3px rgba(0,0,0,.15)` del knob de `.form-switch` y reemplazado por `border: 1px solid var(--border-color)`. Cumple con "no sombras" del design system.

---

### ~~MN-002 / MN-003~~ ✅ — `border-radius: 10px` en chat y counter pills  (RESUELTO)
### ~~MN-004~~ ✅ — Signature overlay con `border-radius:10px` y `box-shadow`  (RESUELTO)

**Aplicado:** se documentaron las 3 categorías de excepciones en `.claude/rules/design.md` nueva sección "## Excepciones permitidas" (después de "Reglas generales"):

| Selector | Archivo | Excepción | Justificación |
|---|---|---|---|
| `.sgi-obs-bubble-body` (is-mine / is-other) | `styles.css:2335,2340` | `border-radius: 10px 10px {2px↔10px} 10px` | Chat bubbles asimétricos — convención universal (WhatsApp/iMessage/Slack). 2px hacia el hablante mantiene ancla al lenguaje del sistema |
| `.sgi-folder-count` | `styles.css:2210` | `border-radius: 10px` | Counter pill — efecto pill estándar en badges numéricos pequeños. Reducir a 2px convertiría en chip rectangular |
| Signature overlay card | `sgi-signature.js:59-63` | `border-radius:10px` + `box-shadow` | **Única excepción de `box-shadow`** — modal temporal sobre `backdrop-filter:blur(3px)`. Los bordes serían insuficientes contra el blur |

**Comentarios CSS/JS agregados** en cada sitio de violación apuntando a la sección de excepciones en `design.md` para que cualquier dev que toque ese código sepa que es intencional.

---

### ~~MN-005~~ ✅ — `!important` declarations auditadas y comentadas  (RESUELTO)

**Inventario final** (post-split de MJ-010):

| Archivo | `!important` | Estado |
|---|---:|---|
| `webroot/css/styles.css` | 17 | Todos legítimos, todos comentados |
| `webroot/css/sgi-flatpickr-overrides.css` | 5 | Vendor overrides — header del archivo explica |
| `webroot/css/sgi-fullcalendar-overrides.css` | 3 | Vendor overrides — header del archivo explica |
| **Total** | **25** | **0 specificity wars internas** |

**Detalle del audit en styles.css (17 declaraciones):**

| Líneas | Sitio | Razón |
|---|---|---|
| 166, 170 | `.sgi-stat-card` shadows | "Nunca sombras" — override defaults de Bootstrap (ya comentadas) |
| 438-494 | `.bg-primary/secondary/success/danger/warning/info/dark/purple/light` | Bootstrap usa `.bg-X` con cascade media-query — !important documentado en header del bloque |
| 2155 | `.accordion-button` | Bootstrap usa `.accordion-item:first-of-type .accordion-button` (specificity 0,2,1) |
| 2171 | `.accordion-button:not(.collapsed)` | Bootstrap define `box-shadow: inset 0 -1px 0` en mismo selector |
| 2249 | `.sgi-obs-empty[hidden]` | El atributo HTML `[hidden]` es UA-stylesheet, cualquier regla CSS gana sin !important |
| 2277 | `.sgi-obs-compose textarea` | Bootstrap `.form-control:focus` específica más que `textarea` descender |
| 2682 | `.sgi-collapse-chevron` color | Chevron suele estar dentro de `.btn` de Bootstrap con color de estado |
| 2689 | Inner section header hide | `.d-flex` de Bootstrap usa `display:flex !important` — único camino para override |

**Aplicado:** comentarios inline agregados a las 6 declaraciones que no los tenían (2155, 2171, 2249, 2277, 2682, 2689). Header de `sgi-flatpickr-overrides.css` expandido para documentar el patrón de vendor overrides.

**Conclusión:** las 25 declaraciones son overrides legítimos. **Cero specificity wars internas.** El `!important` es la herramienta correcta para sobrescribir CSS de terceros con specificity alta o que ellos mismos usan `!important`. Ningún refactor de selectores aplica.

---

### ~~MN-006~~ ✅ — Hex literals en lugar de variables  (RESUELTO)

**Aplicado:** 6 variables semánticas nuevas en `:root` y 142 reemplazos:

| Variable nueva | Hex | Usos en CSS | Usos en templates |
|---------------|-----|------------:|------------------:|
| `--primary-color-hover` | `#3a8752` | 10 | varios |
| `--bg-subtle` | `#f8f9fa` | 13 | varios |
| `--bg-muted` | `#fafafa` | 12 | varios |
| `--danger-color` | `#dc3545` | 22 | varios |
| `--warning-color` | `#ffc107` | 14 | varios |
| `--info-color` | `#0dcaf0` | 14 | varios |

Además se agregaron variables para la escala de grises (`--text-strong/default/muted/faint/disabled`, `--border-faint`) **definidas pero NO reemplazadas automáticamente** — los grises requieren revisión semántica caso por caso (un `#aaa` puntual puede ser una sutileza intencional vs un patrón de "text-disabled" sistemático).

**Pendiente de seguimiento (no bloqueante):** auditar manualmente los ~80 usos de `#aaa #bbb #888 #555 #444 #333 #111` en `styles.css` y reemplazar los que claramente correspondan a `--text-*` semánticos. Trabajo de polish, no bloqueante.

---

### ~~MN-007~~ ✅ — Micro-caps inline en Dashboard  (RESUELTO en `a265c93`)

**Aplicado:** se agregaron 3 clases CSS nuevas en `webroot/css/styles.css` (en lugar de reusar `.sgi-micro-caps` que tiene valores distintos):
- `.sgi-stat-label` (`.63rem`/`.08em`/`#6c757d`) — labels de stat cards.
- `.sgi-section-eyebrow` (`.63rem`/`.12em`/`#999`) — section/table headers.
- `.sgi-block-title` (`.65rem`/`.12em`/`#6c757d`) — títulos de bloque (Facturación, RRHH, Catálogos, Administración).

Reemplazadas 40 de 41 ocurrencias inline en `Dashboard/index.php`. La restante (línea 52) es el subtítulo del welcome banner con `color: var(--primary-color)` — caso único, mantenido inline.

**Pendiente de seguimiento (no bloqueante):** revisar `Invoices/edit.php` y `Advances/legalization.php` por patrones similares de micro-caps inline (no mencionados explícitamente en el audit original, pero el patrón puede repetirse).

---

### ~~MN-008~~ ✅ — Constante `'OBRA O LABOR DETERMINADA'` hardcoded en JS  (RESUELTO)

**Aplicado:** la string ahora se inyecta desde PHP vía `scriptBlock` que escribe `window.SGI_OBRA_LABOR` al block `'script'`. El JS lee `window.SGI_OBRA_LABOR || 'OBRA O LABOR DETERMINADA'` (fallback defensivo). Fuente única de verdad: `ContractTypeConstants::OBRA_LABOR`.

**Bug latente arreglado en el mismo cambio:** `templates/element/Employees/form.php:156` cargaba el JS con `'block' => 'scriptBottom'`, pero el layout solo hace `fetch('script')` (no existe `fetch('scriptBottom')` en ningún template). Resultado: `employees-form.js` **nunca se cargaba en runtime** — el toggle dinámico de "Organización Temporal" al cambiar el tipo de contrato estaba muerto (solo funcionaba el `display:none` inicial calculado en PHP via `$employee->requiresTemporaryOrg()`). Cambiado a `'block' => 'script'` y ahora el JS sí ejecuta.

---

### ~~MN-009~~ ✅ — `Invoices/edit.php` 1200+ LOC  (RESUELTO)

**Aplicado:** template principal pasa de **1101 → 415 LOC** (−62%). Habilitado por MJ-012 que dejó la lógica de presentación en `InvoiceEditViewModel`. 11 elements nuevos creados en `templates/element/invoice_edit/`:

| Element | LOC | Contenido |
|---------|----:|-----------|
| `page_header.php` | 25 | Título + Volver/Ver (Anticipo vs Factura) |
| `advance_alert.php` | 26 | Alerta amarilla con errores para avanzar |
| `sidebar.php` | 90 | Columna derecha: documentos + chat de observaciones |
| `upload_doc_modal.php` | 37 | Modal "Subir Soporte" |
| `scripts.php` | 137 | 3 bloques: SgiDocumentUploader init, beneficiary toggle, doc-type/holder toggle |
| `sections/general.php` | 94 | Factura no-anticipo + sub-form Recibo de Caja |
| `sections/general_advance.php` | 55 | Beneficiario provider/employee (anticipo) |
| `sections/dates.php` | 59 | Registro/emisión/vencimiento |
| `sections/classification.php` | 68 | Centros + monto + detalle |
| `sections/revision.php` | 186 | Aprobadores + DIAN + status individuals |
| `sections/accounting.php` | 52 | Accrued + lista para pago |

Treasury y payment_authorization no se extrajeron (ya son wrappers de 7-12 LOC sobre `element('payment_section')`).

Cada section element recibe `$viewModel` + closure `$canEdit` + las opciones específicas. El template principal mantiene el foreach `$renderOrder` + lógica de collapsible details + invocaciones.

---

### ~~MN-010~~ ✅ — `default.php` split por secciones del sidebar  (RESUELTO)

**Aplicado:** las 4 secciones del sidebar nav se extrajeron a elements en `templates/element/sidebar/`. `default.php` pasa de **662 → 183 LOC** (−479, −72%).

| Element | LOC | Contiene |
|---|---:|---|
| `sidebar/financiero.php` | 233 | Facturas (con submenús: Mis, Rechazadas, Vencidas, Programación), Caja Menor (Mis, Pendientes), Reintegros (Mis, Pendientes), Anticipos (Mis, Pendientes), Registro de Pagos, D. de Liquidación (Mis, Rechazadas) |
| `sidebar/rrhh.php` | 81 | Empleados, Todas las Novedades (con submenús: Mis, Rechazadas, Vigentes) |
| `sidebar/catalogos.php` | 145 | Aprobadores, Proveedores, Entidades Bancarias, Centros de Operación/Costos, Tipos de Gasto, Cargos, Estados Civiles, Niveles Educativos, Carpetas, Tipos de Novedad, Org. Temporales, Plantillas Documento |
| `sidebar/administracion.php` | 56 | Usuarios, Roles, Configuración, Logs de correo |

**Patrón:** cada element recibe las closures `$canView` y `$navLink` vía element data; los counters (`$sidebarCounters`, `$rejectedInvoicesCount`, etc.) están disponibles automáticamente como view vars (settean desde `AppController::_setSidebarCounters`). El item "Inicio" (Dashboard) sigue en `default.php` porque es siempre visible y no pertenece a una sección.

**Decisión de diseño:** cada element hace `return` temprano si su `*Items` filtrado está vacío — evita renderizar el `<li class="nav-heading">` huérfano cuando el usuario no tiene permisos para ningún ítem de la sección.

**Sin cambios funcionales** — el HTML renderizado es idéntico al anterior.

---

### ~~MN-011~~ ✅ — Avatares con iniciales sin texto accesible  (RESUELTO)

**Aplicado:** los 2 únicos avatares de iniciales (`templates/Employees/view.php:53` y `templates/Employees/index.php:130`) ahora llevan `aria-hidden="true"`.

**Decisión de diseño:** se prefirió `aria-hidden="true"` sobre la sugerencia original del backlog (`role="img" aria-label="..."`) porque en ambos casos el nombre completo del empleado se renderiza como texto visible justo al lado del avatar (`.sgi-profile-name` / `.sgi-emp-name`). Usar `aria-label` causaría que el lector de pantalla anuncie el nombre dos veces. `aria-hidden="true"` marca el avatar como decorativo, el SR salta directo al nombre real adyacente — más correcto semánticamente.

**Búsqueda exhaustiva confirmada:** `grep -rn 'sgi-avatar|sgi-profile-avatar|sgi-emp-avatar|h(\$initials)' templates/` solo arroja estas 2 ubicaciones; no hay otros patrones de avatar con iniciales en el codebase.

---

### ~~MN-012~~ ✅ — Iconos decorativos sin `aria-hidden`  (RESUELTO en `e204fa6`)

**Aplicado:** 688 íconos `<i class="bi bi-...">` ahora tienen `aria-hidden="true"`. 121 templates modificados con `perl -i -pe` + negative lookahead. Verificado que no quedan ocurrencias sin marcar (incluyendo opening tags multilínea).

**Pendiente de seguimiento (no bloqueante):** los botones ícono-only sin texto adyacente (ej. acciones de fila con `<button title="Eliminar"><i class="bi bi-trash" aria-hidden="true"></i></button>`) siguen dependiendo del atributo `title=` que es semi-accesible pero no ideal. Migrar `title=` a `aria-label=` en esos botones cuando se aborde un sprint de accesibilidad.

---

### ~~MN-013~~ ✅ — Hardcoded colors en pipeline steppers  (RESUELTO)

**Aplicado:** refactor CSS-driven. `templates/element/progress_stepper.php` pasa de **107 → 70 LOC**, cero `style="..."` inline. El template solo computa `data-state="past|current|future"` por step + `data-rejected="true"` en el contenedor `.sgi-stepper`. Toda la lógica de color vive ahora en `webroot/css/styles.css` sección "Componentes — Pipeline Stepper" (~50 LOC).

**Variables usadas:** `--primary-color`, `--danger-color`, `--border-color`, `--border-faint`, `--text-strong`, `--text-faint`, `--text-disabled`. Único hex literal remanente: `rgba(220,53,69,.25)` para el border translúcido de steps futuros en estado rejected (variante específica que no aplica a otros componentes).

**Sin cambios en los 3 invocadores** (`pipeline_progress.php`, `petty_cash_progress.php`, `refund_progress.php`) — API del element preservada. `advance_legalization_progress.php` usa un patrón distinto (badges), no afectado.

---

### ~~MN-014~~ ✅ — Guard de tamaño de archivo global documentado  (RESUELTO)

**Aplicado:**

1. **`templates/layout/default.php`** — 2 meta tags nuevos en `<head>` con comentario PHP arriba que documenta el contrato (espejo del backend `DocumentUploadTrait::MAX_DOC_SIZE` / `EmployeeDocumentService::MAX_DOC_SIZE` + límite de nginx):
   ```html
   <meta name="sgi-max-upload-bytes" content="20971520">
   <meta name="sgi-max-upload-label" content="20 MB">
   ```

2. **`webroot/js/sgi-common.js`** — ya no hardcodea los valores. Lee de los meta tags en una IIFE al tope del archivo, con fallback defensivo a `20*1024*1024` para layouts que no extiendan `default.php` (ajax/external). El comentario del submit listener global se expandió para explicar `capture:true`.

3. **`webroot/js/sgi-document-uploader.js:233`** — comentario inline explicando que el guard global vive en `sgi-common.js` y que esta validación local es solo para el toast inline. Cierra el problema de discoverability que motivaba MN-014.

**SG-008 sigue abierto:** el valor `20 * 1024 * 1024` aún vive duplicado en 2 servicios PHP (`DocumentUploadTrait`, `EmployeeDocumentService`) más 7 templates con "Máximo 20 MB" como texto. Consolidarlo en una constante PHP única (`UploadConstants::MAX_BYTES`) y referenciarla desde el meta tag cierra SG-008 — pendiente.

---

### ~~MN-015~~ ✅ — `ucfirst()` antes de `h()` (4 templates)  (RESUELTO)

**Aplicado:** la convención "`h()` siempre al final" ahora se cumple en los 4 lugares donde se invertía:

| Archivo | Patrón anterior | Patrón nuevo |
|---|---|---|
| `EmployeeNovelties/index.php:125` | `$labels[X] ?? ucfirst(h(X))` | `h($labels[X] ?? ucfirst(X))` |
| `NoveltyLiquidationDocs/index.php:80` | `$labels[X] ?? ucfirst(h(X))` | `h($labels[X] ?? ucfirst(X))` |
| `DianCrosschecks/index.php:71` | `ucfirst(h(X))` | `h(ucfirst(X))` |
| `Employees/view.php:316` | `ucfirst(h(X))` | `h(ucfirst(X))` |

**Mejora defensiva incluida:** las 2 variantes con `??` (EmployeeNovelties, NoveltyLiquidationDocs) antes NO escapaban el valor de `$statusLabels[X]` (asumía safe). Ahora SÍ lo escapan al envolver toda la expresión con `h()`. Para las otras 2 el cambio es semánticamente equivalente (`ucfirst` no toca entidades HTML), pero alinea con la convención del proyecto.

**Nota:** el backlog original solo mencionaba `EmployeeNovelties/index.php`. Al revisar el patrón aparecieron 3 instancias adicionales con el mismo problema — se incluyeron todas en el fix para cerrar la convención completa.

---

### ~~MN-016~~ ✅ — Inline `<script>` para `--sidebar-width`  (VERIFICADO, no es duplicado)

**Diagnóstico:** la premisa del backlog (que el inline script y el `ResizeObserver` en `sgi-common.js` son redundantes) resultó **incorrecta** tras inspección. Tienen distintos timings:

- **Inline script en `default.php:617`** — corre síncrono al parsear el body, **antes** de que `.content-wrapper` se renderice. Setea `--sidebar-width` al ancho real del sidebar.
- **`ResizeObserver` en `sgi-common.js:101-114`** — corre dentro de `DOMContentLoaded`. Su callback inicial es async (siguiente animation frame).

**Por qué no se puede eliminar el inline:** `.sidebar { width: max-content }` significa que el ancho depende del nav-item más largo (no es 260px fijo). El fallback `--sidebar-width: 260px` del `<head>` rara vez coincide con el ancho real. Sin el inline, hay flash de `.content-wrapper` mal posicionada entre el parse del body y el primer animation frame post-DOMContentLoaded.

**Aplicado:** se documentó el contrato en ambos sitios con comentarios explícitos:
- `templates/layout/default.php` — comentario PHP arriba del inline explicando por qué es load-bearing.
- `webroot/js/sgi-common.js` — comentario JS arriba del bloque ResizeObserver apuntando al inline.

**Cierra MN-016 como falso positivo del audit.** El comportamiento es intencional y necesario.

---

## 🟢 Sugerencias (10)

### ~~SG-001~~ ✅ — Preload de Inter font + WOFF2  (RESUELTO)

**Aplicado:**

1. **WOFF2 generado** vía `python -m fontTools` (fonttools + brotli): `webroot/fonts/Inter-Variable.woff2` (349 KB vs 875 KB del TTF, **−60%**, mejor que la estimación del audit de 30%).

2. **Preload en `templates/layout/default.php`** (head, antes del CSS):
   ```html
   <link rel="preload" href="/fonts/Inter-Variable.woff2" as="font" type="font/woff2" crossorigin>
   ```
   El `crossorigin` es requerido por la spec aunque sea same-origin (los preloads de fuente exigen credentialed mode coincidente con el `@font-face`, que por default es anonymous).

3. **`@font-face` en `webroot/css/styles.css`** actualizado para preferir WOFF2 con TTF como fallback:
   ```css
   src: url('../fonts/Inter-Variable.woff2') format('woff2'),
        url('../fonts/Inter-Variable.ttf') format('truetype');
   ```
   `font-display: swap` se mantiene (decisión previa del proyecto; muestra system-ui durante el load y reemplaza al cargar — `optional` que sugería el audit eliminaría el swap pero también cancelaría la carga si tarda >100ms, perdiendo Inter en redes lentas).

**Impacto:** los browsers modernos ahora descargan 349 KB en lugar de 875 KB (ahorro de 526 KB en el primer paint). El TTF se mantiene en disco solo como fallback para browsers pre-2014 — no se descarga en uso normal.

---

### SG-002 — Modal AJAX loader: usar `<template>` parsing

**Ubicación:** `webroot/js/sgi-common.js:166`

**Problema:** `innerHTML` con HTML fetchado, aunque del mismo origen.

**Resolver:** fetched HTML envuelto en `<template>`:
```js
fetch(url).then(r => r.text()).then(html => {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    container.replaceChildren(...tpl.content.childNodes);
});
```
No es vulnerability — es defensa en profundidad y un contrato más estricto.

---

### SG-003 — `Text::uuid()` desde un element template

**Ubicación:** `templates/element/payment_section.php:44`

**Problema:** generar UUID desde el template viola separation of concerns.

**Resolver:** generar el UUID en el controller o ViewModel y pasarlo al element:
```php
// Controller
$viewModel->idempotencyKey = \Cake\Utility\Text::uuid();

// Template
<?= $this->element('payment_section', [..., 'idempotencyKey' => $viewModel->idempotencyKey]) ?>
```

---

### SG-004 — Unificar SVG/font icons después de centralizar MIME→icono

**Ubicación:** `src/View/Helper/DocumentIconHelper.php` (ya centralizado en MJ-004)

**Sugerencia:** ahora que el mapping es único, considerar reemplazar las clases `bi-file-earmark-*` por SVGs inline (mejor accesibilidad, color-tinting con `currentColor`).

**Resolver:** opcional. Solo si se evalúa migrar de Bootstrap Icons (font) a Lucide / Phosphor (SVG).

---

### SG-005 — Documentar fallback de `getRawAmount()` en `sgi-payment.js`

**Ubicación:** `webroot/js/sgi-payment.js:82-95`

**Problema:** la función tiene fallback string-parsing si AutoNumeric no está cargado, pero no está claro cuándo se da ese caso.

**Resolver:** dos opciones:
- (A) Si AutoNumeric siempre está cargado (lo está en `default.php`), eliminar el fallback.
- (B) Si hay vistas que no lo cargan (revisar `external.php`, `ajax.php`), documentar:
  ```js
  function getRawAmount() {
      // Fallback intencional: external.php no carga AutoNumeric.
      // Mantener el parsing manual en sincronía con el formato AutoNumeric COP ($ 1.234.567,89).
      ...
  }
  ```

---

### ~~SG-006~~ ✅ — FullCalendar assets centralizados  (RESUELTO)

**Aplicado:** las 4 líneas de assets de FullCalendar (JS bundle + locale español + CSS overrides + sgi-calendar.js wrapper) se extrajeron a `templates/element/fullcalendar_assets.php`. Los 2 templates que las consumían (`EmployeeNovelties/index.php` y `EmployeeNovelties/active.php`) ahora hacen `<?= $this->element('fullcalendar_assets') ?>` en una sola línea.

**Diferencia con el snippet original del audit:** el snippet asumía CDN URLs de jsdelivr. La realidad post-MJ-005 paso 4 es que los assets están self-hosted en `/vendor/fullcalendar/` — el element los referencia desde allí. El audit `sgi-fullcalendar-overrides.css` ya no aplica (fue consolidado en `styles.css` por commit `3014cb3`).

**Si en el futuro se agregan más vistas con calendario,** consumir el element ya existente en lugar de copiar las 4 líneas.

---

### SG-007 — Focus visible en `.sgi-input-group`

**Ubicación:** `templates/Users/login.php:42-46`, `webroot/css/styles.css` (estilos de `.sgi-input-group:focus-within`)

**Problema:** el border verde aparece en focus-within pero el outline nativo del input se elimina (Bootstrap default `border-0 shadow-none`).

**Resolver:** verificar manualmente con Tab navigation que el focus es visible. Si no, agregar:
```css
.sgi-input-group input:focus-visible {
    outline: 2px solid var(--primary-color);
    outline-offset: 2px;
}
```

---

### ~~SG-008~~ ✅ — `MAX_UPLOAD_BYTES` consolidado en `UploadConstants`  (RESUELTO)

**Aplicado:** una sola fuente de verdad para tamaño máximo de upload en todo el SGI.

**Nueva constante:** `src/Constants/UploadConstants.php`
```php
final class UploadConstants {
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_BYTES_LABEL = '20 MB';
}
```

**Refactorizaciones aplicadas (11 sitios):**

| Sitio | Cambio |
|---|---|
| `src/Service/Trait/DocumentUploadTrait.php` | const privada `MAX_DOC_SIZE` eliminada → `UploadConstants::MAX_BYTES`. Error message también parameterizado. |
| `src/Service/EmployeeDocumentService.php` | idem |
| `templates/layout/default.php` | `<meta sgi-max-upload-bytes content="<?= UploadConstants::MAX_BYTES ?>">` y label idem |
| `templates/PettyCashRecords/edit.php` | "Máximo 20 MB" → `<?= h(UploadConstants::MAX_BYTES_LABEL) ?>` |
| `templates/Employees/view.php` | idem |
| `templates/element/invoice_edit/upload_doc_modal.php` | idem |
| `templates/EmployeeNovelties/edit.php` | idem |
| `templates/Refunds/edit.php` | idem |
| `templates/NoveltyLiquidationDocs/edit.php` | idem |
| `templates/PaymentSchedulings/edit.php` | idem |

**Verificación:** `grep -rn "20 \\* 1024 \\* 1024\\|MAX_DOC_SIZE\\|Máximo 20 MB\\|20971520" src/ templates/` excluyendo `UploadConstants` arroja **0 resultados**. La duplicación queda eliminada.

**Mantenimiento:** cambiar el límite ahora requiere editar SOLO `UploadConstants.php`. Si nginx o php.ini tienen un límite menor, ese gana — la constante representa el límite aplicacional. Mantener sincronizado con `client_max_body_size` (nginx) y `upload_max_filesize`/`post_max_size` (php.ini) — documentado en el doc-comment de la clase.

---

### SG-009 — Variables CSS para colores semánticos

**Ubicación:** `webroot/css/styles.css:2-12`

**Resolver:** ya cubierto parcialmente por MN-006. Variables semánticas adicionales:
```css
:root {
    --danger-color:   #dc3545;
    --warning-color:  #ffc107;
    --success-color:  var(--primary-color);
    --info-color:     #0dcaf0;
}
```

---

### SG-010 — i18n preparation

**Ubicación:** todos los templates

**Problema:** todas las strings están en español hardcoded. CakePHP soporta `__()` pero no se usa.

**Resolver:** si hay plan de multi-idioma, migrar incrementalmente:
```php
<h1><?= __('Facturas') ?></h1>
```
Y crear archivos PO en `resources/locales/es_CO/default.po`.

Si NO hay plan de multi-idioma, documentar en `CLAUDE.md`:
> Este proyecto es **es_CO único** por decisión de producto. No usar `__()` ni preparar i18n.

Quita el flag del backlog hasta que el PO lo pida.

---

## Resumen ejecutivo

| Prioridad | Categoría | Estimación |
|-----------|-----------|-----------|
| 🟢 SG-002 a SG-005, SG-007, SG-009, SG-010 | Mejoras incrementales (SG-001, SG-006, SG-008 cerradas) | A discreción |

**Estado:** todos los Críticos (3), Mayores (12), Minores (16) y la primera Sugerencia (SG-008) están cerrados. Quedan 9 Sugerencias discrecionales.

**Próximo paso recomendado:** las 7 Sugerencias restantes son polish sin urgencia. Las más prácticas si se quiere continuar:
- **SG-007** — Verificar focus-visible en `.sgi-input-group` para a11y de teclado (10 min).
- **SG-005** — Documentar fallback de `getRawAmount()` en `sgi-payment.js` o eliminarlo (15 min).
- **SG-010** — Decisión de producto sobre i18n (`es_CO` único vs preparar `__()`).

## Historial de aplicación

| Commit | Hallazgos cerrados |
|--------|---------------------|
| `6b12baa` | CR-001 (SRI CDNs), CR-002 (XSS leave-template-editor), CR-003 (Dashboard JSON) |
| `b21d22f` | MJ-001 (XSS excel-mapper), MJ-004 (mapping MIME→icono) |
| `0e63f06` | MJ-006 (progress_stepper), MJ-007 (regress_status_modal) |
| `523b339` | MJ-008 (employee_documents_table), MJ-009 (SgiDialogs) |
| `38a0d96` | MJ-002 (ResultSet count), MJ-011 (label for=) |
| `49f7d88` | MJ-003 (InvoicePresentation::forRow + InvoiceRowView) |
| `e204fa6` | MJ-005 pasos 1-2 (defer + preconnect), MN-012 (aria-hidden bulk) |
| `39b889f` | MJ-012 (Invoices/edit pre-render → InvoiceEditViewModel) |
| `2fbd197` | Refactor derivado: uniformación de los 5 edit templates restantes + helper `PaymentOptions` |
| `a265c93` | MN-007 (micro-caps → classes), MJ-005 paso 3 (ApexCharts/AutoNumeric lazy) |
| `2de2727` | MJ-005 paso 3b (Select2 + jQuery lazy vía cdn_select2.php) |
| `e85e89d` | MN-009 (split Invoices/edit en 11 elements; −686 LOC del template principal) |
| `be8dc72` | MN-006 (6 variables CSS semánticas + 142 reemplazos de hex en css/templates) |
| `300af41` | MJ-005 paso 4 (self-host de todos los vendors a webroot/vendor/) |
| `fa16845` | MJ-010 (split de vendor-overrides; styles.css −520 LOC) + MN-001 (box-shadow toggle) |
| `0423353` | MN-013 (progress_stepper CSS-driven con data-state; template −37 LOC, cero inline styles) |
| `bf4c296` | MN-008 (window.SGI_OBRA_LABOR vía scriptBlock + fix bug latente del block 'scriptBottom') |
| `e355f63` | MN-011 (aria-hidden="true" en avatares con iniciales; nombre adyacente ya anuncia) |
| `d316440` | MN-015 (h() al final en 4 templates; +escape defensivo de $statusLabels) |
| `39752d0` | MN-016 (documentar contrato inline+ResizeObserver; falso positivo del audit) |
| `9acafb1` | MN-014 (meta tags sgi-max-upload-* + comments cross-file; SG-008 sigue abierto) |
| `6ea0dd2` | MN-002/003/004 (sección "Excepciones permitidas" en design.md + comments cross-file) |
| `40e5b1d` | MN-005 (inventario completo de 25 !important; comentarios inline a las 6 sin doc; header de flatpickr-overrides expandido) |
| `8e35bf0` | MN-010 (split de default.php 662→183 LOC en 4 elements sidebar/{financiero,rrhh,catalogos,administracion}.php) |
| `00a5f62` | SG-008 (UploadConstants nuevo + consolidación en 2 services + meta + 7 templates; 0 duplicados residuales) |
| `4d74008` | SG-006 (element/fullcalendar_assets.php; bloque de 4 líneas centralizado entre EmployeeNovelties/{index,active}) |
| `27f3ded` | SG-001 (Inter-Variable.woff2 generado −60%; preload tag + @font-face dual format) |
