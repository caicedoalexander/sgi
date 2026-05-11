# Audit Backlog — Templates, CSS y JS

Hallazgos pendientes de la auditoría realizada el **2026-05-11** sobre `templates/`, `webroot/css/` y `webroot/js/`.

**Estado:** los **3 Críticos**, **10 de los 12 Mayores** (incluyendo MJ-012), **1 Mayor parcial** (MJ-005 pasos 1-2-3 aplicados, **paso 4 pendiente**), **2 Minores** (MN-007, MN-012) y **1 refactor derivado fuera del audit** (uniformación de los 6 edit templates) ya fueron resueltos. Ver commits `6b12baa` → `a265c93`. Este documento agrupa los hallazgos diferidos con instrucciones concretas para cerrarlos en próximos sprints.

**Convenciones:**
- 🟠 Mayor — bloquea un sprint si se acumula.
- 🟡 Minor — backlog de polish.
- 🟢 Sugerencia — mejora incremental sin urgencia.
- Cada item incluye **ubicación (`path:line`)** y **pasos concretos**, no descripciones genéricas.

---

## 🟠 Mayores pendientes (1 entero + MJ-005 paso 4)

### MJ-005 — Performance frontend de CDNs (parcial — paso 4 pendiente)

**Ubicación:** `templates/layout/default.php`, `templates/element/cdn_autonumeric.php`, `templates/Dashboard/index.php`.

**Estado actual:** los 16 CDN tienen `integrity` + `crossorigin` (commit `6b12baa`). Pasos 1-2-3 aplicados:
- **Pasos 1-2** (`e204fa6`): `preconnect`/`dns-prefetch` y `defer` a los scripts CDN. `dashboard-charts.js` envuelto en guard `DOMContentLoaded`.
- **Paso 3** (`a265c93`): ApexCharts ahora se carga solo en `Dashboard/index.php`. AutoNumeric solo en los 10 templates con `.currency-input` vía nuevo element `cdn_autonumeric.php`.

**Problema restante:**
- Select2, jQuery, AutoNumeric (parcial) y Bootstrap siguen en CDN externo en runtime (problema para staging/dev sin red, CSP estricto).
- **Select2 + jQuery** todavía cargan en TODAS las vistas (no movidas en el paso 3 por estar entrelazados — Select2 depende de jQuery, y se usan en 9+ templates).

**Cómo resolver:**

1. ~~**Quick win**: `defer` a los `<script>` en `default.php`.~~ ✅ Aplicado.
2. ~~**Preconnect**.~~ ✅ Aplicado.
3. ~~**Cargar ApexCharts/AutoNumeric bajo demanda**.~~ ✅ Aplicado.

3.b **Select2 + jQuery bajo demanda** (1-2 horas) — **PENDIENTE**:
   - Crear `templates/element/cdn_select2.php` que cargue jQuery + select2 (CSS + JS + i18n/es).
   - Incluirlo desde los 9 templates con `.select2-enable`: `Advances/add`, `EmployeeNovelties/add`, `Invoices/add+edit`, `PaymentSchedulings/edit` (verificar), `PettyCashRecords/add`, `Refunds/add+edit`, y elements `payment_section`, `invoice_edit/modify_approvers_modal`.
   - **Atención al CSS:** `select2.min.css` también está en `<head>` de `default.php` y debe moverse al element o seguir en default (CSS no bloquea parsing si se sirve con `media="all"`).
   - **`sgi-common.js`** ya guarda con `typeof $ !== 'undefined' && $.fn && $.fn.select2`, así que no falla en páginas sin Select2.

4. **Self-host** (1 día, depende del entorno) — **PENDIENTE**:
   - Descargar a `webroot/vendor/{bootstrap,bootstrap-icons,flatpickr,select2,jquery,autonumeric,apexcharts,fullcalendar,sortablejs}/<version>/`.
   - Reemplazar URLs en `default.php` + `element/cdn_autonumeric.php` + `element/cdn_select2.php` (cuando exista) + `Dashboard/index.php` + 3 templates puntuales (`EmployeeNovelties/index`, `EmployeeNovelties/active`, `Employees/index`).
   - Remover los hashes SRI (no aplican a same-origin) o mantener para detectar corrupción local.
   - Beneficio: elimina dependencia de internet, mejor para staging/dev sin red, CSP estricto.

**Validación manual del progreso actual:** abrir DevTools → Network en `/dashboard` (NO debe pedir ApexCharts ya), en `/invoices` listado (NO debe pedir AutoNumeric ya), y en `/invoices/edit/<id>` (debe pedir AutoNumeric). Charts del dashboard renderizan, AutoNumeric formatea los montos.

---

### MJ-010 — `styles.css` monolítico (3,301 LOC)

**Ubicación:** `webroot/css/styles.css`

**Problema:**
- 1 stylesheet de ~120 KB servido en **todas** las vistas, incluyendo `login.php` y `external.php` que solo necesitan una fracción.
- 39 declaraciones `box-shadow` (contradicen `design.md` que prohíbe sombras).
- 23 `!important` declarations (algunos legítimos override de Bootstrap, otros revelan specificity wars).
- Greys hex repetidos (`#aaa #bbb #ccc #111 #444 #888 #999`) que deberían ser variables CSS.

**Cómo resolver:**

1. **Auditoría de `!important`** (1-2 horas):
   ```bash
   grep -nE '!important' webroot/css/styles.css | wc -l   # baseline
   grep -nE '!important' webroot/css/styles.css           # listar todos
   ```
   - Override legítimo de Bootstrap (`.btn { box-shadow: none !important }`): documentar con comentario `/* override Bootstrap */`.
   - Specificity wars (ej. `:first-child { display:none !important }` en `webroot/css/styles.css:2521+`): refactorizar con selector más específico.

2. **Variables para grises** (30 min): agregar en `:root` de `styles.css:2-12`:
   ```css
   :root {
       --text-strong:    #111;
       --text-default:   #222;
       --text-muted:     #555;
       --text-faint:     #888;
       --text-disabled:  #aaa;
       --border-faint:   #ccc;
       --danger-color:   #dc3545;
       --warning-color:  #ffc107;
       --info-color:     #0dcaf0;
   }
   ```
   Luego `grep -n '#aaa\|#bbb\|#ccc\|#111\|#888' webroot/css/styles.css` y reemplazar caso por caso.

3. **`box-shadow` ofensores** (1 hora): revisar las 39 declaraciones:
   ```bash
   grep -n "box-shadow" webroot/css/styles.css
   ```
   - Casos legítimos: `inset 2px 0 0` (sidebar activo, parte del lenguaje de bordes — mantener).
   - Casos a corregir: sombras drop reales (deberían reemplazarse por bordes 1px).
   - Excepciones documentadas: chat bubbles (`webroot/css/styles.css:2680,2685`), signature (`webroot/js/sgi-signature.js:59,61`) — pedir decisión al PO o documentar en `design.md`.

4. **Split en 2 archivos** (medio día):
   - **`core.css`**: variables `:root`, `@font-face`, tipografía, `.sgi-btn-*`, `.sgi-input-group`, `.sgi-topbar`, sidebar, login. Cargar en TODOS los layouts.
   - **`app.css`**: módulos (`.sgi-stat-card`, `.sgi-quick-tile`, `.sgi-doc-row`, `.sgi-folder-*`, chat, payment, document uploader). Cargar solo en `default.php` (autenticado).
   - `login.php`, `external.php`, `error.php` solo cargan `core.css`.

5. **Minify en producción** (opcional, 1 hora): añadir paso de build (npm script + `cssnano`) o usar CakePHP AssetCompress. Si no hay pipeline, dejar para más adelante.

**Validación manual:** comparar screenshots antes/después de cada vista representativa: `/dashboard`, `/invoices`, `/login`, `/external-approvals/<token>`, `/employees/<id>/view`, formulario edit de factura.

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

## 🟡 Minores (14)

### MN-001 — `box-shadow` en checkbox toggle

**Ubicación:** `webroot/css/styles.css:1183`

**Problema:** `box-shadow: 0 1px 3px rgba(0,0,0,.15)` en el "knob" del toggle contradice "no sombras" de `design.md`.

**Resolver:** reemplazar por `border: 1px solid var(--border-color)` o eliminar.

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

### MN-006 — Hex literals en lugar de variables

**Ubicación:** `webroot/css/styles.css:3150-3160` (y muchas otras)

**Problema:** `#212529`, `#CD6A15`, `#dee2e6`, `#495057` repetidos como literales en lugar de `var(--bg-dark)`, `var(--secondary-color)`, `var(--border-color)`.

**Resolver:** sed-like search & replace, validando contexto:
```bash
grep -n "#212529" webroot/css/styles.css   # → var(--bg-dark)
grep -n "#CD6A15" webroot/css/styles.css   # → var(--secondary-color)
grep -n "#dee2e6" webroot/css/styles.css   # → var(--border-color)
```

---

### ~~MN-007~~ ✅ — Micro-caps inline en Dashboard  (RESUELTO en `a265c93`)

**Aplicado:** se agregaron 3 clases CSS nuevas en `webroot/css/styles.css` (en lugar de reusar `.sgi-micro-caps` que tiene valores distintos):
- `.sgi-stat-label` (`.63rem`/`.08em`/`#6c757d`) — labels de stat cards.
- `.sgi-section-eyebrow` (`.63rem`/`.12em`/`#999`) — section/table headers.
- `.sgi-block-title` (`.65rem`/`.12em`/`#6c757d`) — títulos de bloque (Facturación, RRHH, Catálogos, Administración).

Reemplazadas 40 de 41 ocurrencias inline en `Dashboard/index.php`. La restante (línea 52) es el subtítulo del welcome banner con `color: var(--primary-color)` — caso único, mantenido inline.

**Pendiente de seguimiento (no bloqueante):** revisar `Invoices/edit.php` y `Advances/legalization.php` por patrones similares de micro-caps inline (no mencionados explícitamente en el audit original, pero el patrón puede repetirse).

---

### MN-008 — Constante `'OBRA O LABOR DETERMINADA'` hardcoded en JS

**Ubicación:** `webroot/js/employees-form.js:8`

**Problema:** la string vive duplicada entre JS y `App\Constants\ContractTypeConstants::OBRA_LABOR`.

**Resolver:** inyectar desde el server al template que carga el JS (probablemente `Employees/add.php` y `edit.php`):
```php
<script>
window.SGI_OBRA_LABOR = <?= json_encode(\App\Constants\ContractTypeConstants::OBRA_LABOR) ?>;
</script>
<?= $this->Html->script('employees-form', ['block' => true]) ?>
```
Y en `employees-form.js:8`:
```js
const OBRA_LABOR = window.SGI_OBRA_LABOR || 'OBRA O LABOR DETERMINADA';
```

---

### MN-009 — `Invoices/edit.php` 1200+ LOC

**Ubicación:** `templates/Invoices/edit.php` (entero)

**Problema:** monolito que mezcla layout, 4 inline `<script>`, 2 modals, business logic, helpers.

**Resolver:** split por secciones (depende de MJ-012):
- `element/invoice_edit/header.php` — pipeline progress + acciones principales
- `element/invoice_edit/form/{general,dates,classification,revision,accounting,treasury,payment_authorization}.php`
- `element/invoice_edit/modals.php` — modify approvers + (ya extraído) regress modal
- `element/invoice_edit/scripts.php` — los 4 bloques `<script>` consolidados

**Validación manual:** todos los estados del pipeline + role bypass + factura rechazada.

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

### MN-011 — Avatares con iniciales sin `aria-label`

**Ubicación:**
- `templates/Employees/view.php:53`
- `templates/Employees/index.php:122-125`
- Buscar todos: `grep -rn 'border-radius:50%\|sgi-avatar' templates/`

**Problema:** divs con iniciales (`<div>AC</div>`) sin texto accesible para lectores de pantalla.

**Resolver:**
```html
<div class="sgi-avatar" role="img" aria-label="<?= h($employee->full_name) ?>">
    <?= h($initials) ?>
</div>
```

---

### ~~MN-012~~ ✅ — Iconos decorativos sin `aria-hidden`  (RESUELTO en `e204fa6`)

**Aplicado:** 688 íconos `<i class="bi bi-...">` ahora tienen `aria-hidden="true"`. 121 templates modificados con `perl -i -pe` + negative lookahead. Verificado que no quedan ocurrencias sin marcar (incluyendo opening tags multilínea).

**Pendiente de seguimiento (no bloqueante):** los botones ícono-only sin texto adyacente (ej. acciones de fila con `<button title="Eliminar"><i class="bi bi-trash" aria-hidden="true"></i></button>`) siguen dependiendo del atributo `title=` que es semi-accesible pero no ideal. Migrar `title=` a `aria-label=` en esos botones cuando se aborde un sprint de accesibilidad.

---

### MN-013 — Hardcoded colors en pipeline steppers

**Ubicación:** `templates/element/pipeline_progress.php:33,93`, `petty_cash_progress.php`, `refund_progress.php` (ya unificados en `element/progress_stepper.php`).

**Estado:** parcialmente resuelto en MJ-006. Quedan los hex `#dc3545`, `#ddd`, `#bbb`, `#aaa`, `#fff`, `#e0e0e0` en `templates/element/progress_stepper.php`.

**Resolver:** refactorizar a un patrón CSS-driven con `data-state="past|current|future|rejected"`:
```php
<div class="sgi-step" data-state="<?= h($state) ?>">
   <i class="bi <?= h($icon) ?>"></i>
</div>
```
```css
.sgi-step[data-state="past"]     { border-color: var(--primary-color); background: var(--primary-color); color: #fff; }
.sgi-step[data-state="current"]  { ... }
.sgi-step[data-state="future"]   { border-color: var(--border-color); ... }
.sgi-step[data-state="rejected"] { background: var(--danger-color); ... }
```

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
| 🟠 MJ-005 (paso 3b + paso 4) | Select2/jQuery lazy + self-host | 1 día |
| 🟠 MJ-010 | CSS limpieza + split | 1-2 días |
| 🟡 MN-001 a MN-006, MN-008 a MN-011, MN-013 a MN-016 | Design system polish + code smells + a11y | 1-1.5 días |
| 🟢 SG-001 a SG-010 | Mejoras incrementales | A discreción |

**Total estimado:** ~2.5-3.5 días de trabajo para dejar todo cerrado, repartibles entre sprints.

**Próximo paso recomendado:**
1. **MJ-005 paso 3b** (1-2 horas) — crear `element/cdn_select2.php` y mover Select2 + jQuery fuera de `default.php`. Patrón ya validado con AutoNumeric.
2. **MN-009** (medio día) — split de `Invoices/edit.php` (1098 LOC) en elements por sección. Habilitado ahora que MJ-012 dejó la lógica en el ViewModel.
3. **MN-006** (1-2 horas) — promover hex literales a variables CSS (`#212529`, `#CD6A15`, `#dee2e6`, `#495057`). Ya hay base con las nuevas clases `.sgi-stat-label` etc.

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
