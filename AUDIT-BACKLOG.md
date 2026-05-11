# Audit Backlog — Templates, CSS y JS

Hallazgos pendientes de la auditoría realizada el **2026-05-11** sobre `templates/`, `webroot/css/` y `webroot/js/`.

Los **3 Críticos**, **9 de los 12 Mayores**, **1 Mayor parcial** (MJ-005 pasos 1-2 aplicados, 3-4 pendientes) y **1 Minor** (MN-012) ya fueron resueltos. Ver commits `6b12baa` → `e204fa6`. Este documento agrupa los hallazgos diferidos con instrucciones concretas para cerrarlos en próximos sprints.

**Convenciones:**
- 🟠 Mayor — bloquea un sprint si se acumula.
- 🟡 Minor — backlog de polish.
- 🟢 Sugerencia — mejora incremental sin urgencia.
- Cada item incluye **ubicación (`path:line`)** y **pasos concretos**, no descripciones genéricas.

---

## 🟠 Mayores pendientes (2)

### MJ-005 — Performance frontend de CDNs (parcial — pasos 3 y 4 pendientes)

**Ubicación:** `templates/layout/default.php:30-33,636-643`

**Estado actual:** las 16 referencias CDN tienen `integrity` + `crossorigin` (commit `6b12baa`). **Pasos 1 y 2 aplicados** en commit `e204fa6`: agregado `preconnect`/`dns-prefetch` y `defer` a los 8 scripts CDN del footer. `dashboard-charts.js` envuelto en guard `DOMContentLoaded` para soportar `defer` en ApexCharts.

**Problema restante:**
- Bootstrap, jQuery, AutoNumeric, Select2, ApexCharts se cargan en **todas** las vistas (no solo donde se usan).
- Dependencia de CDN externo en runtime (problema para staging/dev sin red, CSP estricto).

**Cómo resolver:**

1. ~~**Quick win** (10 min): agregar `defer` a los `<script>` en `default.php`.~~ ✅ **Aplicado** en `e204fa6`.

2. ~~**Preconnect** (5 min): en `<head>` de `default.php`.~~ ✅ **Aplicado** en `e204fa6`.

3. **Cargar bajo demanda** (1-2 horas) — **PENDIENTE**:
   - **ApexCharts**: solo se usa en `Dashboard/index.php` y vistas con charts. Mover a esos templates con `$this->Html->script('https://.../apexcharts.min.js', ['block' => true, 'defer' => true, 'integrity' => '...', 'crossorigin' => 'anonymous'])`.
   - **AutoNumeric**: solo donde hay `.currency-input`. Detectar en JS y cargar dinámicamente si hace falta, o mover a templates con monto.
   - **Select2**: ídem, donde hay `.select2`.

4. **Self-host** (1 día, depende del entorno) — **PENDIENTE**:
   - Descargar a `webroot/vendor/{bootstrap,bootstrap-icons,flatpickr,select2,jquery,autonumeric,apexcharts,fullcalendar,sortablejs}/<version>/`.
   - Reemplazar URLs en los 4 layouts + 3 templates puntuales (`EmployeeNovelties/index`, `EmployeeNovelties/active`, `Employees/index`).
   - Remover los hashes SRI (no aplican a same-origin) o mantener para detectar corrupción local.
   - Beneficio: elimina dependencia de internet, mejor para staging/dev sin red, CSP estricto.

**Validación manual del progreso actual:** abrir DevTools → Network en `/dashboard`. Los 8 CDN scripts ahora muestran `Initiator: Parser (defer)` y se descargan en paralelo sin bloquear el parsing. Charts y formularios siguen funcionando.

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

### MJ-012 — 80 líneas de pre-render en `Invoices/edit.php`

**Ubicación:** `templates/Invoices/edit.php:13-89`

**Problema:** el template hace pre-procesamiento de datos en su cabecera:
- Construir `$documentTypes`, `$readyForPaymentOptions`, `$pipelineBadgeMap`.
- Computar `$sectionFieldMap`, `$editableSectionKeys`, `$readOnlySectionKeys`, `$renderOrder`, `$collapsibleEditable`.

Es lógica de presentación que debería vivir en el ViewModel.

**Cómo resolver:**

1. **Localizar el ViewModel** (5 min):
   ```bash
   grep -rn "InvoiceEditViewModel\|InvoiceViewModel" src/
   ```

2. **Extender ViewModel** (1 hora): agregar al ViewModel campos pre-computados:
   ```php
   public readonly array $documentTypes;
   public readonly array $readyForPaymentOptions;
   public readonly array $sectionFieldMap;
   public readonly array $editableSectionKeys;
   public readonly array $readOnlySectionKeys;
   public readonly array $renderOrder;
   public readonly array $collapsibleEditable;
   ```
   Mover la lógica del template al constructor o a `forEdit()` factory.

3. **Actualizar el controller** (30 min): `InvoicesController::edit()` que crea el ViewModel debe pasar los inputs necesarios (current user role, current status, etc.) para que el ViewModel los compute.

4. **Limpiar template** (15 min): eliminar el bloque `templates/Invoices/edit.php:13-89` y consumir `$viewModel->documentTypes`, `$viewModel->renderOrder`, etc.

5. **Considerar split del template** (opcional, medio día): si el archivo sigue siendo >800 LOC, extraer secciones:
   - `element/invoice_edit/header.php`
   - `element/invoice_edit/sections/{general,dates,classification,revision,accounting,treasury,payment_authorization}.php`
   - `element/invoice_edit/modals.php`

**Validación manual:** abrir factura en cada estado del pipeline (aprobacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada) y verificar que las secciones visibles/editables coincidan con el comportamiento anterior. Crear factura nueva. Editar con diferentes roles.

---

## 🟡 Minores (15)

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

### MN-007 — Micro-caps inline en Dashboard

**Ubicación:** `templates/Dashboard/index.php` (~30 ocurrencias del bloque `style="font-size:.63rem;letter-spacing:.12em;text-transform:uppercase;color:#999;..."`)

**Problema:** la clase `.sgi-micro-caps` ya existe en `webroot/css/styles.css:3244` pero el template repite la regla inline.

**Resolver:**
```bash
# Buscar todos los inline styles
grep -n "font-size:.6" templates/Dashboard/index.php
```
Reemplazar cada `<div style="font-size:.63rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.75rem;">Texto</div>` por:
```php
<div class="sgi-micro-caps mb-2">Texto</div>
```

Verificar que `.sgi-micro-caps` tenga las mismas propiedades; si difiere `margin-bottom` o `color`, agregar variante:
```css
.sgi-micro-caps        { color: #999; }
.sgi-micro-caps--muted { color: #aaa; }
```

**Aplicar también en:** `Invoices/edit.php`, `Advances/legalization.php` headers que tengan el mismo patrón.

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
| 🟠 MJ-005 (pasos 3-4) | Carga bajo demanda + self-host | 1 día |
| 🟠 MJ-010 | CSS limpieza + split | 1-2 días |
| 🟠 MJ-012 | Invoices/edit pre-render | 1 día |
| 🟡 MN-001 a MN-007 | Design system polish | 4-6 horas total |
| 🟡 MN-008 a MN-011, MN-013 a MN-016 | Code smells + a11y | 1 día |
| 🟢 SG-001 a SG-010 | Mejoras incrementales | A discreción |

**Total estimado:** ~4-6 días de trabajo para dejar todo cerrado, repartibles entre sprints.

**Próximo paso recomendado** (tras los quick wins ya aplicados):
1. **MJ-012** (1 día) — mover el bloque pre-render de `Invoices/edit.php` al ViewModel. Es el refactor de mayor ROI en términos de testability.
2. **MN-007** (30 min) — reemplazar las ~30 ocurrencias del bloque `style="font-size:.63rem;..."` por `class="sgi-micro-caps"` en `Dashboard/index.php`. Trivial pero mejora consistencia visual del design system.
3. **MJ-005 paso 3** (1-2 horas) — cargar ApexCharts solo en templates que renderizan charts (no en todas las vistas).

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
