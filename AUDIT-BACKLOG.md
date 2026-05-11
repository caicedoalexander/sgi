# Audit Backlog — Templates, CSS y JS

Hallazgos pendientes de la auditoría realizada el **2026-05-11** sobre `templates/`, `webroot/css/` y `webroot/js/`.

**Estado:** los **3 Críticos**, **los 12 Mayores** (MJ-001 a MJ-012 completos), **8 Minores** (MN-001, MN-006, MN-007, MN-008, MN-009, MN-011, MN-012, MN-013) y **1 refactor derivado fuera del audit** (uniformación de los 6 edit templates) ya fueron resueltos. Ver commits `6b12baa` → reciente. Solo quedan minores menores y sugerencias.

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

## 🟡 Minores (8)

### ~~MN-001~~ ✅ — `box-shadow` en checkbox toggle  (RESUELTO en `fa16845`, junto a MJ-010)

**Aplicado:** removido el `box-shadow: 0 1px 3px rgba(0,0,0,.15)` del knob de `.form-switch` y reemplazado por `border: 1px solid var(--border-color)`. Cumple con "no sombras" del design system.

---

### MN-002 / MN-003 — `border-radius: 10px` en chat y otros

**Ubicación:** `webroot/css/styles.css:2680,2685,2560`

**Problema:** excede el límite "0 o 2px máx" del design system.

**Resolver:** dos opciones:
- (A) Reducir a `4px 4px 0 4px` (chat bubble más conservador).
- (B) Documentar la excepción en `.claude/rules/design.md`:
  ```md
  ### Excepciones permitidas
  - Chat bubbles (`.sgi-obs-bubble`): `border-radius: 10px 10px 2px 10px` para legibilidad de hilos largos.
  - Overlay de firma (`.sgi-signature-overlay`): redondeo de 10px aceptado.
  ```
Recomiendo (B) — los chat bubbles son un caso de UX donde `border-radius` mayor ayuda a la lectura.

---

### MN-004 — Signature overlay con `border-radius:10px` y `box-shadow`

**Ubicación:** `webroot/js/sgi-signature.js:59,61`

**Problema:** card overlay usa estilos prohibidos por el design system.

**Resolver:**
- Cambiar a `border: 1px solid var(--border-color)` + fondo `var(--bg-dark)` semi-transparente.
- O documentar excepción en `design.md` (overlay temporal sobre el documento).

---

### MN-005 — 23 `!important` declarations

**Ubicación:** `webroot/css/styles.css:97-101,2521,2623` (y más)

**Problema:** specificity wars; algunos override legítimos de Bootstrap mezclados con hacks.

**Resolver:** inventario completo (ver MJ-010 paso 1). Categorizar:
- ✅ Override de Bootstrap: dejar + comentario.
- ❌ Specificity wars: refactorizar selectores (más específicos o reorganizar cascada).

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

### MN-010 — `default.php` 644 LOC con permission logic inline

**Ubicación:** `templates/layout/default.php`

**Problema:** sidebar nav con badges concatenados inline en cada item.

**Resolver:** extraer por sección:
- `element/sidebar/financiero.php` (facturas, anticipos, reintegros, caja menor, programación pagos, registro pagos)
- `element/sidebar/rrhh.php` (empleados, novedades, calendario, doc. liquidación)
- `element/sidebar/catalogos.php` (cost centers, expense types, operation centers, banking entities, etc.)
- `element/sidebar/administracion.php` (users, roles, system settings, email logs)

El helper `$navLink` cierre actual está OK, solo el markup repetitivo se beneficia del split.

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

### MN-014 — Guard de tamaño de archivo global poco documentado

**Ubicación:** `webroot/js/sgi-common.js:11-29`, `webroot/js/sgi-document-uploader.js:279`

**Problema:** un submit listener global con `capture:true` valida tamaño de uploads, pero los devs que tocan `sgi-document-uploader.js` no se enteran que existe.

**Resolver:**
1. En `sgi-document-uploader.js:279`, agregar comentario:
   ```js
   // El guard global de tamaño vive en sgi-common.js (submit listener con capture).
   // SGI_MAX_UPLOAD_BYTES también se usa allí.
   var maxBytes = global.SGI_MAX_UPLOAD_BYTES || (20 * 1024 * 1024);
   ```

2. Exponer el valor desde PHP en `default.php`:
   ```html
   <meta name="sgi-max-upload-bytes" content="<?= (int)(\App\Config\AppConfig::MAX_UPLOAD_BYTES ?? 20971520) ?>">
   ```
   Y leerlo al inicio de `sgi-common.js`:
   ```js
   var meta = document.querySelector('meta[name="sgi-max-upload-bytes"]');
   window.SGI_MAX_UPLOAD_BYTES = meta ? parseInt(meta.content, 10) : 20*1024*1024;
   ```

---

### MN-015 — `ucfirst()` antes de `h()` en EmployeeNovelties

**Ubicación:** `templates/EmployeeNovelties/index.php:124`

**Código actual:**
```php
ucfirst($statusLabels[$novelty->pipeline_status] ?? ucfirst(h($novelty->pipeline_status)))
```

**Problema:** `ucfirst(h($x))` aplica `ucfirst` después de escapar — el orden invertido es harmless aquí (entidades HTML no afectan), pero rompe la convención "siempre `h()` al final".

**Resolver:**
```php
h(ucfirst($statusLabels[$novelty->pipeline_status] ?? $novelty->pipeline_status))
```

---

### MN-016 — Inline `<script>` duplicado para `--sidebar-width`

**Ubicación:** `templates/layout/default.php:606`

**Problema:** el inline script para sincronizar `--sidebar-width` se ejecuta antes del DOM, y `sgi-common.js` lo hace de nuevo con `ResizeObserver`.

**Resolver:** verificar que `sgi-common.js` ya maneja el caso inicial:
```bash
grep -n "sidebar-width\|--sidebar" webroot/js/sgi-common.js
```
Si lo hace, eliminar el bloque inline. Si solo reacciona a resize, mover el setup inicial al primer event del ResizeObserver (que dispara en la creación).

---

## 🟢 Sugerencias (10)

### SG-001 — Preload de Inter font + WOFF2

**Ubicación:** `webroot/css/styles.css:18-24`, `templates/layout/default.php` (head)

**Resolver:**
1. Convertir `webroot/fonts/Inter-Variable.ttf` a WOFF2 (`fonttools` o `woff2_compress`). WOFF2 ahorra ~30%.
2. En `default.php` head:
   ```html
   <link rel="preload" href="/fonts/Inter-Variable.woff2" as="font" type="font/woff2" crossorigin>
   ```
3. Actualizar `@font-face`:
   ```css
   @font-face {
       font-family: 'Inter';
       src: url('../fonts/Inter-Variable.woff2') format('woff2'),
            url('../fonts/Inter-Variable.ttf')   format('truetype');
       font-display: optional;  /* o swap si se prefiere */
       font-weight: 100 900;
   }
   ```

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

### SG-006 — FullCalendar duplicado

**Ubicación:** `templates/EmployeeNovelties/index.php:176-177`, `templates/EmployeeNovelties/active.php:57-58`

**Problema:** FullCalendar v6 cargado en 2 templates.

**Resolver:** extraer a un element:
```php
// templates/element/fullcalendar_assets.php
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js" integrity="..." crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.11/locales/es.global.min.js" integrity="..." crossorigin="anonymous"></script>
<?= $this->Html->css('sgi-calendar') ?>
<script src="<?= $this->Url->build('/js/sgi-calendar.js') ?>"></script>
```
Y consumirlo:
```php
<?= $this->element('fullcalendar_assets') ?>
```

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

### SG-008 — `SGI_MAX_UPLOAD_BYTES` duplicado

**Ubicación:** `webroot/js/sgi-common.js:5-6`, `webroot/js/sgi-document-uploader.js:279`

**Problema:** el valor `20 * 1024 * 1024` aparece dos veces.

**Resolver:** ver MN-014 — exponer desde PHP en `<meta>` y leerlo una sola vez en `sgi-common.js`. `sgi-document-uploader.js` consume `window.SGI_MAX_UPLOAD_BYTES`.

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
| 🟡 MN-002 a MN-005, MN-010, MN-014 a MN-016 | Design system polish + code smells + a11y | 1 día |
| 🟢 SG-001 a SG-010 | Mejoras incrementales | A discreción |

**Total estimado:** ~1 día de trabajo para cerrar todos los Minores restantes; las sugerencias son a discreción.

**Próximo paso recomendado:**
1. **MN-015** (10 min) — invertir orden `h(ucfirst(...))` en `EmployeeNovelties/index.php:124`.
2. **MN-016** (15 min) — verificar y eliminar el inline `<script>` duplicado para `--sidebar-width` en `default.php`.
3. **MN-014** (15 min) — comentar/exponer `SGI_MAX_UPLOAD_BYTES` y documentar el guard global.

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
| _pendiente_ | MN-011 (aria-hidden="true" en avatares con iniciales; nombre adyacente ya anuncia) |
