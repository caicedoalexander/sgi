# SGI — Sistema de Diseño v2

Referencia de diseño del proyecto. Documento fuente para cualquier vista nueva o cambio visual. Mantiene coherencia entre conversaciones y entre vistas.

> El sistema fue rediseñado en mayo 2026. La versión anterior usaba bordes 2px superiores como acento (`border-top: 2px solid`). v2 elimina los borders y usa **fondo + barras de acento + jerarquía tipográfica** como única señal visual.

---

## Reglas duras (NO se rompen)

1. **Sin `border`** en cards, stat-cards, quick-tiles, sidebar, navbar, modales, dropdowns. La separación con el canvas la hace el fondo (`#fff` sobre `var(--background-color)`).
2. **Sin `box-shadow`.** Prohibido. Salvo la excepción documentada del signature overlay (ver "Excepciones permitidas").
3. **Datos cuantitativos en mono.** Códigos, fechas, montos, IDs → `var(--font-mono)` (JetBrains Mono). Aplica con clase `.mono` o `.sgi-mono`.
4. **Labels en uppercase + letter-spacing.** Etiquetas de sección, columnas de tabla, micro-caps → 10–11px / 700 / 0.6–0.8 letter-spacing.
5. **Pills en variantes soft.** Nunca colores sólidos en tablas: usar `.pill-primary-soft`, `.pill-danger-soft`, etc. Los sólidos quedan para hero/destacado puntual.
6. **Sin emoji.** Iconos de Bootstrap Icons o SVG inline. Caracteres especiales (✓, →) solo si refuerzan estado.
7. **Sin admiraciones** en UI salvo errores críticos. Nunca "¡Bienvenido!".

---

## Tokens — fuente única

Definidos en `webroot/css/styles.css` `:root`. Cualquier valor "mágico" que aparezca en un template debe primero verificarse contra estos tokens.

### Color de marca

```css
--bg-dark:          #212529;   /* sidebar, footers oscuros, hero ocasional */
--primary-color:    #469D61;   /* acción primaria, éxito, marca */
--primary-color-hover: #3a8752;
--secondary-color:  #CD6A15;   /* edición, énfasis cálido, proveedores */
--secondary-color-hover: #b85d11;
--accent-color:     #83542B;   /* acento terciario, etapas avanzadas */
```

### Soft variants (badges, pills, fila seleccionada)

```css
--primary-soft:        rgba(70, 157, 97, 0.12);
--primary-soft-strong: rgba(70, 157, 97, 0.18);
--secondary-soft:      rgba(205, 106, 21, 0.14);
--accent-soft:         rgba(131, 84, 43, 0.14);
--danger-soft:         rgba(220, 53, 69, 0.12);
--warning-soft:        rgba(255, 193, 7, 0.20);
--info-soft:           rgba(13, 202, 240, 0.16);
```

### Layout

```css
--background-color: #f5f5f5;   /* canvas del content area */
--bg-subtle:        #f8f9fa;   /* headers de tabla, chips */
--bg-muted:         #fafafa;   /* hover de filas, level-2 */
--bg-focus-tinge:   #fafffe;   /* tinge sutil de inputs en focus */
--border-color:     #e0e0e0;   /* bordes legacy (forms, inputs) */
--rule:             #ececec;   /* línea fina INTERIOR a una card */
```

### Semánticos (Bootstrap 5 aligned)

Cada semántico tiene 3 variantes paralelas: `-color` (fondo/borde), `-color-hover` (hover de botón sólido), `-soft` (fondo soft con alpha) y `-text` (ink oscuro para texto sobre la soft).

```css
--primary-color:        #469D61;  --primary-color-hover: #3a8752;
--primary-soft:         rgba(70,157,97,0.12);
--primary-soft-strong:  rgba(70,157,97,0.18);
--primary-text:         #2d6e42;  /* ink sobre primary-soft */

--secondary-color:      #CD6A15;  --secondary-color-hover: #b85d11;
--secondary-soft:       rgba(205,106,21,0.14);
--secondary-text:       #9a5011;  /* ink sobre secondary-soft */

--danger-color:  #dc3545;  --danger-color-hover:  #bb2d3b;
--danger-soft:   rgba(220,53,69,0.12);
--danger-text:   #842029;  /* ink sobre danger-soft */

--warning-color: #ffc107;  --warning-color-hover: #e0a800;
--warning-soft:  rgba(255,193,7,0.20);
--warning-text:  #8a6d08;

--info-color:    #0dcaf0;
--info-soft:     rgba(13,202,240,0.16);
--info-text:     #087990;

/* Hovers para superficies neutras */
--dark-color-hover:  #1a1e21;
--light-color-hover: #e2e6ea;
```

### Escala de grises (5 niveles)

```css
--text-strong:   #111;   /* Títulos, énfasis */
--text-default:  #222;   /* Cuerpo */
--text-muted:    #555;   /* Texto secundario */
--text-faint:    #888;   /* Labels, metadatos, placeholders */
--text-disabled: #aaa;   /* Deshabilitado */
--border-faint:  #ccc;
```

### Tipografía

```css
--font-sans: 'Inter', system-ui, sans-serif;
--font-mono: 'JetBrains Mono', ui-monospace, monospace;

--fs-display:    30px;     /* moneda hero */
--fs-title-page: 22px;     /* h1 de página */
--fs-title-card: 14px;     /* h2/h3 dentro de cards */
--fs-body-lg:    13px;
--fs-body:       12.5px;   /* body por defecto */
--fs-body-sm:    12px;
--fs-label:      10.5px;   /* uppercase labels */
--fs-meta:       10px;
--fs-micro:      9.5px;    /* pills sm */
```

### Espaciado, radii y transiciones

```css
/* Espaciado (múltiplos de 4): 4, 8, 12, 16, 20, 24, 28, 32 */
--space-1..8;

--radius-none: 0;     /* cards, celdas de tabla */
--radius-sm:   3px;   /* pills, badges, doc codes */
--radius-md:   4px;   /* botones, inputs, chips */

--t-fast: 0.12s;   /* hover de filas, botones, tabs */
--t-view: 0.18s;   /* entrada de vistas (fadeIn) */
```

---

## Fuentes

| Fuente | Archivo | Uso |
|--------|---------|-----|
| **Inter Variable** | `webroot/fonts/Inter-Variable.woff2` (+ TTF fallback) | UI general, títulos, labels, cuerpo |
| **JetBrains Mono Variable** | `webroot/fonts/JetBrainsMono-Variable.woff2` (+ TTF fallback) | Datos cuantitativos: códigos, fechas, montos, IDs |

Ambas están preloadadas en `templates/layout/default.php` con `<link rel="preload">` para evitar FOIT en la primera carga.

---

## Lenguaje de identidad visual

Sin border ni shadow, los elementos se identifican con:

- **Barra de acento izquierda 3px** (`border-left: 3px solid var(--primary-color)`) → stat-cards, action-cards, cards variantes (`.card-primary`, `.card-danger`…), ledger summary.
- **`box-shadow: inset 2px 0 0`** → ítem activo del sidebar (única regla de box-shadow permitida fuera de la excepción modal).
- **Borde izquierdo 2px** en el título de la navbar → marca el contexto actual.
- **Borde izquierdo 2px** separando paneles (login) → divisor estructural de marca.
- **Border-top 3px** del modal → única afordancia visual ya que el modal flota sobre backdrop oscuro y se distingue por contraste.
- **Líneas finas interiores `1px solid var(--rule)`** → separan rows de tabla, header/body/footer de card. Es un "rule" interior, NO un box border.

---

## Componentes

### Stat Card (`.sgi-stat-card`)

Contador del Dashboard. Fondo blanco, barra de acento izquierda 3px verde por defecto. Hover → fondo `var(--bg-muted)`.

```css
.sgi-stat-card {
    background: #fff;
    border-left: 3px solid var(--primary-color);
}
.sgi-stat-card:hover { background: var(--bg-muted); }
.sgi-stat-card.accent-secondary { border-left-color: var(--secondary-color); }
.sgi-stat-card.accent-dark      { border-left-color: var(--bg-dark); }
```

### Action Card (`.sgi-action-card`)

Card horizontal con label + mensaje + CTA. Mismo lenguaje: barra izquierda 3px.

### Quick Tile (`.sgi-quick-tile`)

Acceso rápido del Dashboard. Sin border. El hover revela una línea inferior verde de 2px como afordancia, sin afectar layout.

### Cards de Bootstrap (`.card`)

`.card` base = sin border, sin shadow. Variantes con `border-left: 3px` según color:
`.card-primary`, `.card-secondary`, `.card-success`, `.card-danger`, `.card-warning`, `.card-info`, `.card-dark`.

`.card-hover` → `cursor: pointer` + hover bg-muted.

### Botones

Bootstrap `.btn` con `border-radius: 0`, sin shadow. Variantes sólidas (`btn-primary`, `btn-secondary`…) y outline (`btn-outline-*`). Sin cambios desde v1.

### Pills / Badges

Sistema nuevo. Para listados y tablas SIEMPRE usar variante soft:

```html
<span class="pill pill-primary-soft">VIGENTE</span>
<span class="pill pill-danger-soft">FALTANTE</span>
<span class="pill pill-warning-soft">POR VENCER</span>
<span class="pill pill-info-soft">ACTIVO</span>
<span class="pill pill-secondary-soft">EDICIÓN</span>
<span class="pill pill-muted">SIN ESTADO</span>
```

Sólidos (`.pill-primary`, `.pill-secondary`, `.pill-dark`) reservados para hero o destacado puntual.

### Doc codes (`.sgi-doc-code`)

Catálogo de documentos de empleado: CC, HV, ARL, AFP… Cuadritos monoespaciados de color según estado.

```html
<span class="sgi-doc-code is-ok">CC</span>
<span class="sgi-doc-code is-warn">HV</span>
<span class="sgi-doc-code is-miss">ARL</span>
<span class="sgi-doc-code is-dim">AFP</span>
```

### Pipeline mini-bar (`.sgi-pipeline-mini`)

Barra horizontal compacta usada en listados de facturas para indicar el estado sin pipeline completo.

```html
<div class="sgi-pipeline-mini">
    <div class="on"></div>
    <div class="on"></div>
    <div></div>
    <div></div>
</div>
```

### Inputs / Forms

Mantienen `border: 1px solid var(--border-color)` por consistencia con el patrón de Bootstrap (los inputs sin border son confusos). Focus → `border-color: var(--primary-color)` + `box-shadow: inset 2px 0 0 var(--primary-color)`.

---

## Sidebar

Sin cambios estructurales desde v1. Lenguaje:

- **Fondo:** `bg-dark` Bootstrap (`#212529`).
- **Logo:** cuadrado 36×36px, fondo `--primary-color`, ícono `bi-building`.
- **Nav-link activo:** `box-shadow: inset 2px 0 0 var(--primary-color)` — sin fondo (excepción box-shadow permitida).
- **Nav-link hover:** `box-shadow: inset 2px 0 0 rgba(255,255,255,.18)` + fondo `rgba(255,255,255,.04)`.
- **Nav headings:** `.58rem`, `letter-spacing: .14em`, `rgba(255,255,255,.25)`.
- **Avatar:** cuadrado 32×32px, fondo `--primary-color`.
- **Botón logout (`.sgi-sidebar-logout`):** sin border. Hover → fondo translúcido.

## Navbar superior (`.sgi-topbar`)

Sin border-bottom. El contraste con el canvas viene del color de fondo (`#fff` vs `var(--background-color)`).

```css
.sgi-topbar { background: #fff; min-height: 56px; }
.sgi-topbar-title { border-left: 2px solid var(--primary-color); padding-left: .6rem; }
.sgi-topbar-date  { font-family: var(--font-mono); }
```

## Tablas

Sin borders externos. Separación entre filas con `border-bottom: 1px solid var(--rule)` (regla interior). Header con `background: var(--bg-subtle)`. Hover de fila → `var(--bg-muted)`.

Datos cuantitativos en celdas (montos, fechas, códigos) deben llevar `.mono` o `.sgi-mono`.

```html
<table class="table table-hover">
  <thead>
    <tr><th>Código</th><th>Proveedor</th><th>Valor</th></tr>
  </thead>
  <tbody>
    <tr>
      <td class="mono">FACT-2026-0142</td>
      <td>Acme S.A.</td>
      <td class="mono">$ 1.250.000</td>
    </tr>
  </tbody>
</table>
```

## Login

Layout de dos paneles full-height:
- **Panel izquierdo** (45%, `d-none d-lg-flex`): fondo `--bg-dark`, branding centrado.
- **Panel derecho** (flex-grow-1): fondo `#fff`, `border-left: 2px solid var(--primary-color)`, formulario centrado.

El borde verde que divide los dos paneles es el "divisor estructural de marca".

---

## Utilidades semánticas

Drop-in classes para usar en cualquier vista nueva. Definidas al final de `styles.css`.

| Clase | Uso |
|-------|-----|
| `.sgi-display` | Moneda hero (`$ 120.000`) — 30px, 800, mono, letter-spacing -1px |
| `.sgi-title-page` | h1 de página — 22px, 700 |
| `.sgi-title-card` | h2/h3 de card — 14px, 700 |
| `.sgi-label` | Label uppercase — 10.5px, 700, letter-spacing 0.8px |
| `.sgi-body-muted` / `.sgi-body-faint` | Texto secundario / terciario |
| `.mono` / `.sgi-mono` | JetBrains Mono — OBLIGATORIO para datos cuantitativos |
| `.sgi-fg-primary` / `.sgi-fg-secondary` / `.sgi-fg-accent` / `.sgi-fg-danger` / `.sgi-fg-warning` / `.sgi-fg-info` | Color de texto semántico |
| `.sgi-fg-strong` / `.sgi-fg-default` / `.sgi-fg-muted` / `.sgi-fg-faint` / `.sgi-fg-disabled` | Color de texto por jerarquía |
| `.sgi-accent-strip` + `.sgi-accent-*` | Barra de 3px (para anclar identidad en cards/filas custom) |
| `.sgi-rule` | Línea fina horizontal interior (no border, no shadow) |

---

## Reglas generales

| ✅ Usar | ❌ Evitar |
|---------|----------|
| Fondo + barras de acento como jerarquía | `border: 1px solid` decorativo en cards/sidebar/navbar |
| `var(--rule)` para líneas interiores | `border-color` para "dividir" cosas externamente |
| `var(--font-mono)` para datos cuantitativos | `'Courier New', monospace` o fuentes mono ad-hoc |
| Pills soft (`.pill-*-soft`) | Pills sólidos en tablas/listados |
| Variables del `:root` | Valores hex inline en templates |
| `letter-spacing` negativo en títulos grandes | Tracking positivo en títulos |
| `border-radius: 0` o `3px–4px` máximo | `rounded-3`, `rounded-circle` en contenedores |
| `--primary-color` para acentos | `btn-success`, `border-success` Bootstrap directos |

---

## Excepciones permitidas

Las reglas duras aplican al 99% del sistema. Las excepciones documentadas a continuación reflejan casos UX donde apartarse del lenguaje mejora la legibilidad o sigue convenciones bien establecidas. **Antes de introducir una nueva excepción, añadirla a esta lista** con el selector exacto y la justificación.

### Chat bubbles (`.sgi-obs-bubble-body`)

```css
.sgi-obs-bubble.is-mine  .sgi-obs-bubble-body { border-radius: 10px 10px 2px 10px; }
.sgi-obs-bubble.is-other .sgi-obs-bubble-body { border-radius: 10px 10px 10px 2px; }
```

**Justificación:** los hilos de conversación con bubbles cuadrados son visualmente densos y rompen el patrón mental de "mensaje propio vs ajeno". El radio asimétrico (10px en 3 esquinas + 2px en la esquina que apunta al hablante) es convención universal en chats (WhatsApp, Slack, iMessage). Mantiene los 2px como "ancla" hacia el lenguaje del sistema.

### Counter pills (`.sgi-folder-count`)

```css
.sgi-folder-count { border-radius: 10px; padding: .15em .5em; min-width: 1.5em; }
```

**Justificación:** badges numéricos pequeños usados como contadores (carpetas, notificaciones). El 10px en un elemento de ~16-20px de alto produce efecto pill — convención visual estándar para contadores.

### Signature overlay (`webroot/js/sgi-signature.js` — card modal)

```js
'background:#fff', 'border-radius:10px', 'padding:1.5rem',
'box-shadow:0 16px 48px rgba(0,0,0,.25)',
```

**Justificación:** card modal temporal que aparece sobre el documento al firmar. **Única excepción que permite `box-shadow` en el sistema** — porque el card flota sobre un fondo desenfocado (`backdrop-filter:blur(3px)`) y necesita separación visual mediante sombra (los bordes serían insuficientes contra el blur). Es un componente efímero, no parte del flujo principal.

---

## Carga de CSS (orden importante)

```html
<!-- 1. Bootstrap (base) -->
<link href="bootstrap.min.css" rel="stylesheet">
<!-- 2. Bootstrap Icons -->
<link href="bootstrap-icons.min.css" rel="stylesheet">
<!-- 3. Flatpickr -->
<link href="flatpickr.min.css" rel="stylesheet">
<!-- 4. Nuestros estilos — deben ir DESPUÉS de Bootstrap para sobreescribir -->
<?= $this->Html->css('styles') ?>
<!-- 5. Inline <style> — solo CSS estructural (posicionamiento, layout) -->
```

---

## Archivos clave

| Archivo | Contenido |
|---------|-----------|
| `webroot/css/styles.css` | Variables, tipografía, todos los componentes SGI |
| `webroot/fonts/Inter-Variable.{woff2,ttf}` | Inter (100–900) |
| `webroot/fonts/JetBrainsMono-Variable.{woff2,ttf}` | JetBrains Mono (100–800) |
| `webroot/js/sgi-common.js` | Clickable rows, Flatpickr, AutoNumeric |
| `templates/layout/default.php` | Layout principal con sidebar + topbar |
| `templates/layout/login.php` | Layout split-panel del login |
