# SGI · COPCSA — Sistema de Diseño

**16 secciones · 80+ tokens**

Tokens, componentes y patrones para construir cualquier vista del Sistema de Gestión Interna de COPCSA. Esta hoja es la fuente única de verdad; cualquier vista nueva debe usar estos mismos tokens, componentes y reglas sin reinventarlos.

---

## Índice

**Fundamentos**
- [01 · Colores](#01--colores)
- [02 · Tipografía](#02--tipografía)
- [03 · Espaciado & superficies](#03--espaciado--superficies)
- [04 · Iconografía](#04--iconografía)

**Componentes**
- [05 · Botones](#05--botones)
- [06 · Badges & Pills](#06--badges--pills)
- [07 · Inputs & formularios](#07--inputs--formularios)
- [08 · Tabs y filtros](#08--tabs-y-filtros)
- [09 · Cards y superficies](#09--cards-y-superficies)
- [10 · Avatares](#10--avatares)
- [11 · Tablas](#11--tablas)
- [12 · Date & Time pickers](#12--date--time-pickers)

**Patrones**
- [TopBar — barra superior](#topbar--barra-superior-de-la-página)
- [13 · Sidebar](#13--sidebar) (incluye branding)
- [14 · Pipeline](#14--pipeline)
- [15 · Gestión documental](#15--gestión-documental)
- [16 · Empty states](#16--empty-states)

**Apéndices**
- [Tono y copy](#tono-y-copy)
- [Reglas duras](#reglas-duras)
- [Excepciones permitidas](#excepciones-permitidas)
- [Carga de CSS](#carga-de-css)

---

## Reglas duras

Las reglas que NO se rompen. Toda excepción debe documentarse explícitamente en este archivo bajo "Excepciones permitidas".

1. **Sin bordes** — la separación entre elementos se logra con `background-color` (cards blancas sobre fondo gris), barras de acento de 3px o líneas finas `var(--rule)`. Nunca `border: 1px solid` en cards, sidebar, navbar, modales, dropdowns.
2. **Sin sombras** — `box-shadow` está prohibido. La elevación se sugiere con jerarquía de fondo (`#fff` → `--bg-subtle` → `--bg-muted` → `--background-color`).
3. **Datos cuantitativos en mono** — `JetBrains Mono` es OBLIGATORIO para códigos de factura, fechas, montos, IDs, cédulas, nombres de archivo. Aplica con `.sgi-mono` o `.mono`.
4. **Labels en uppercase** — etiquetas de sección y columnas de tabla: 10–11px / 700 / `letter-spacing 0.6–0.8`. Usa `.sgi-label` o `.label-up`.
5. **Pills en variantes soft** — listados y tablas SIEMPRE con `.pill-*-soft`. Sólidos (`.pill-primary`, `.pill-orange`, `.pill-dark`) reservados a hero del sidebar y destacados puntuales.
6. **Sin emoji** — iconos de Bootstrap Icons o SVG inline estilo Lucide. Caracteres especiales (✓, →) solo si refuerzan estado.
7. **Sin admiraciones** salvo errores críticos. Nunca "¡Bienvenido!" ni "¡Ups!".
8. **Esquinas rectas** — cards y celdas de tabla `radius: 0`. Pills `3px`. Botones / inputs `4px`. Sin `border-radius` arbitrarios.
9. **Una `primary` por sección** — una sola acción `.btn-primary` visible por pantalla. Si necesitas más, conviértelas en `secondary` y eleva una.
10. **Inter + JetBrains Mono únicamente** — no introducir nuevas fuentes.

---

## 01 · Colores

Tres colores de marca + escala de grises + semánticos. Cada color tiene una variante `-soft` para fondos suaves y una variante de texto (`-text`) para usar sobre esa superficie.

### Marca

```css
--bg-dark:               #212529;   /* Sidebar, headers oscuros, hero */
--primary-color:         #469D61;   /* Acción primaria, éxito, marca */
--primary-color-hover:   #3a8752;
--secondary-color:       #CD6A15;   /* Edición, favoritos, énfasis cálido */
--secondary-color-hover: #b85d11;
--accent-color:          #83542B;   /* Acento terciario, etapas avanzadas */
```

### Soft variants (fondos suaves, badges, pills)

```css
--primary-soft:         rgba(70, 157, 97, 0.12);
--primary-soft-strong:  rgba(70, 157, 97, 0.18);  /* hover/selected fuerte */
--secondary-soft:       rgba(205, 106, 21, 0.14);
--accent-soft:          rgba(131, 84, 43, 0.14);
```

### Semánticos (alineados con Bootstrap 5)

```css
--danger-color:    #dc3545;   --danger-soft:   rgba(220, 53, 69, 0.12);
--warning-color:   #ffc107;   --warning-soft:  rgba(255, 193, 7, 0.20);
--warning-text:    #8a6d08;   /* ink sobre warning-soft */
--info-color:      #0dcaf0;   --info-soft:     rgba(13, 202, 240, 0.16);
--info-text:       #087990;   /* ink sobre info-soft */
```

### Layout (superficies)

```css
--background-color: #f5f5f5;   /* canvas de la app */
--bg-subtle:        #f8f9fa;   /* header de tabla, chips, hover suave */
--bg-muted:         #fafafa;   /* fila hover, demo shelf */
--border-color:     #e0e0e0;   /* outline de inputs / botón default */
--rule:             #ececec;   /* línea fina interior, separador entre filas */
```

### Escala de grises (texto)

```css
--text-strong:   #111;   /* Títulos, énfasis */
--text-default:  #222;   /* Cuerpo */
--text-muted:    #555;   /* Texto secundario */
--text-faint:    #888;   /* Labels, metadatos, placeholders */
--text-disabled: #aaa;   /* Deshabilitado, iconos sutiles */
--border-faint:  #ccc;
```

### Jerarquía de superficies

```
#f5f5f5 (canvas) → #fff (card) → #f8f9fa (header / chip) → #fafafa (hover)
```

> **Regla de uso:** `--primary` es para acción primaria y éxito. Nunca lo uses como fondo de página o decoración. Para pills/badges usa SIEMPRE la variante `-soft`. Los colores fuertes están reservados para botones primarios, barras de acento 3px y elementos del hero del sidebar.

---

## 02 · Tipografía

**Dos familias, sin excepciones.**

- **Inter** (400 · 500 · 600 · 700 · 800) — UI general, títulos, etiquetas, cuerpo.
- **JetBrains Mono** (400 · 500 · 600) — códigos, fechas, montos, IDs, cédulas, archivos.

```css
--font-sans: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
--font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
```

### Escala (6 niveles)

| Token | Tamaño | Weight | Uso | Clase utility |
|-------|--------|--------|-----|---------------|
| `--fs-display` | 30px | 800 | Moneda hero, número grande | `.sgi-display` (mono · letter-spacing -1px) |
| `--fs-title-page` | 22px | 700 | Título de página (h1) | `.sgi-title-page` |
| `--fs-title-card` | 14px | 700 | Título de card (h2/h3) | `.sgi-title-card` |
| `--fs-body-lg` | 13px | 400–500 | Cuerpo grande | — |
| `--fs-body` | 12.5px | 400–500 | Body por defecto | — |
| `--fs-body-sm` | 12px | 500–600 | Body pequeño / faint | `.sgi-body-faint` |
| `--fs-label` | 10.5px | 700 | Uppercase labels | `.sgi-label` / `.label-up` |
| `--fs-meta` | 10px | 600 | Meta mono | — |
| `--fs-micro` | 9.5px | 700 | Pills sm | — |

```css
--tracking-label: 0.8px;   /* labels uppercase */
--tracking-pill:  0.4px;   /* pills uppercase */
```

### Reglas tipográficas

- **Mono OBLIGATORIO** para: `E008113522` (facturas), `13/05/2026` (fechas), `CC 1006193265` (cédulas), `$ 120.000` (montos), `cc.pdf` (archivos).
- **Nunca `text-decoration: underline`** para links dentro de la app. El color (`--primary-color` o `--secondary-color`) basta.
- **Casing:**
  - Botones / acciones → Tipo oración ("Nueva Factura", "Agregar pago").
  - Labels / encabezados de sección → MAYÚSCULAS + letter-spacing.
  - Estados / pills → MAYÚSCULAS ("PAGADA", "VIGENTE", "FALTANTE").
  - Códigos de documento → MAYÚSCULAS monoespaciado ("CC", "HV", "ARL").

---

## 03 · Espaciado & superficies

Múltiplos de 4. Escala efectiva del SGI: **4, 8, 12, 14, 16, 20, 24, 28**.

```css
--space-1:4px;   --space-2:8px;   --space-3:12px;  --space-4:16px;
--space-5:20px;  --space-6:24px;  --space-7:28px;  --space-8:32px;
```

### Reglas

| Contexto | Valor |
|----------|-------|
| Icono ↔ texto | 4px |
| Entre botones / chips | 8px |
| Filas en columna | 12px |
| Entre cards | 14–16px |
| Padding interior card compacta | 16–18px |
| Padding interior card estándar | 20px |
| Padding horizontal de página | 24px |

### Radii

```css
--radius-none: 0;     /* cards, celdas de tabla */
--radius-sm:   3px;   /* pills, badges, doc codes, avatares cuadrados */
--radius-md:   4px;   /* botones, inputs, chips */
```

> El SGI prefiere **ángulos rectos** para sensación de "operativo". No introducir radii arbitrarios.

### Bordes y separadores — qué se permite

- **NO** `border: 1px solid` en cards, inputs, botones primarios, sidebar, navbar, modales.
- **SÍ** línea 1px `var(--rule)` para separar filas dentro de una card (border-bottom de la fila, no border de la card).
- **SÍ** barra de acento 3px en el lado izquierdo para foco/selección (sidebar activo, fila seleccionada, KPI card).
- **SÍ** `outline: 1px solid; outline-offset: -1px` en botones default e inputs (es outline, no border — no consume layout).
- **SÍ** `.hr` — `height: 1px; background: var(--rule)` como separador interior de cards.

### Sombras

**Prohibidas.** Si necesitas separar dos cards, usa otro `background-color` o un `gap` visible.

### Transiciones

```css
--t-fast: 0.12s;   /* hover de filas, botones, tabs */
--t-view: 0.18s;   /* entrada de vistas (fadeIn) */
```

Easing: `ease` del navegador. Sin bounces. Entrada de vista: `opacity 0→1 + translateY(4px→0)` en 180ms (clase `.view-anim`).

---

## 04 · Iconografía

Set propio estilo **Lucide simplificado**. SVG `viewBox 0 0 24 24`, `stroke="currentColor"`, `stroke-width="2"` por defecto, `stroke-linecap="round"`, `stroke-linejoin="round"`.

- **Heredan color** con `currentColor` — basta con setear `color` en el contenedor.
- **Tamaños usados:**
  - **10** — dentro de chips
  - **11** — pills, breadcrumb
  - **12–13** — botones
  - **14–16** — sidebar, KPIs
  - **18–22** — headers de cards, avatares grandes
- **Stroke 2** estándar. Stroke 3 solo para `IconCheck` dentro de pills pequeñas para legibilidad.
- **Sin emoji.** Sin iconos con `fill: color` salvo logo, estrella rellena y dots.

### Catálogo mínimo (28 iconos)

`Home`, `File`, `Wallet`, `Users`, `User`, `Doc`, `Clock`, `Calendar`, `Search`, `Filter`, `Plus`, `Check`, `X`, `ChevronRight`, `ChevronDown`, `ArrowLeft`, `Download`, `Upload`, `Edit`, `Eye`, `Star`, `Clip`, `Mail`, `Pin`, `Alert`, `Building`, `Refresh`, `Bank`.

En el proyecto SGI: Bootstrap Icons (`bi-*`) se usa como fallback. Si un icono no está en BI, agregar SVG inline siguiendo el patrón Lucide.

---

## 05 · Botones

Variantes que cubren el 100% de los casos. **Una sola `.btn-primary` por sección de pantalla.**

### Variantes

```html
<button class="btn btn-primary">Nueva Factura</button>          <!-- acción primaria -->
<button class="btn btn-secondary">Marcar pendiente</button>     <!-- outline verde -->
<button class="btn btn-default">Más opciones</button>           <!-- card blanca + outline gris -->
<button class="btn btn-ghost">Cancelar</button>                 <!-- transparente -->
<button class="btn btn-subtle">Detalles</button>                <!-- bg-subtle -->
<button class="btn btn-dashed">Asignarme</button>               <!-- outline punteado verde -->
<button class="btn btn-danger">Eliminar</button>                <!-- destructivo -->
```

### Especificación

```css
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 7px 14px; font-size: 12.5px; font-weight: 600;
  border: none; border-radius: 4px; font-family: inherit;
  cursor: pointer; white-space: nowrap;
  transition: background var(--t-fast), color var(--t-fast);
}
.btn-primary   { background: var(--primary-color); color: #fff; }
.btn-primary:hover { background: var(--primary-color-hover); }

.btn-secondary { background: transparent; color: var(--primary-color);
                 outline: 1px solid var(--primary-color); outline-offset: -1px; }

.btn-default   { background: #fff; color: var(--text-default);
                 outline: 1px solid var(--border-color); outline-offset: -1px; }
.btn-default:hover { background: var(--bg-subtle); }

.btn-ghost     { background: transparent; color: var(--text-muted); }
.btn-ghost:hover { background: var(--bg-subtle); color: var(--text-default); }

.btn-subtle    { background: var(--bg-subtle); color: var(--text-default); }
.btn-subtle:hover { background: var(--bg-muted); }

.btn-dashed    { background: transparent; color: var(--primary-color);
                 outline: 1.5px dashed rgba(70,157,97,0.6); outline-offset: -1.5px; }

.btn-danger    { background: var(--danger-color); color: #fff; }
```

### Cuándo usar cada variante

| Variante | Uso |
|----------|-----|
| `btn-primary` | Acción principal de la pantalla (una sola por sección). |
| `btn-secondary` | Acción afirmativa secundaria, alta importancia ("Marcar pendiente"). |
| `btn-default` | Acción secundaria sobre canvas gris — card blanca con outline fino. |
| `btn-ghost` | Acción terciaria sin peso visual ("Cancelar", "Más opciones"). |
| `btn-subtle` | Acción contextual dentro de cards — fondo gris sutil. |
| `btn-dashed` | Acción opcional / call-to-action discreto ("Asignarme"). |
| `btn-danger` | Solo acciones destructivas irreversibles. |

### Tamaños

```css
.btn-sm { padding: 5px 10px; font-size: 11.5px; gap: 5px; }   /* alto 28 */
.btn    { padding: 7px 14px; font-size: 12.5px; gap: 6px; }   /* alto 36 — default */
.btn-lg { padding: 9px 18px; font-size: 13px;   gap: 7px; }   /* alto 44 */
```

- **md** por defecto.
- **sm** dentro de filas densas (tablas, headers de card).
- **lg** en CTAs aislados o formularios principales.
- **full-width** (`width: 100%; padding: 11px; font-size: 13.5px`) en formularios.

### Estados

```css
.btn-disabled, .btn:disabled { opacity: 0.5; cursor: not-allowed; }
```

Mantén el color de la variante; no la conviertas en gris.

### Iconos

- Icono **a la izquierda** por defecto.
- Icono **a la derecha** solo si indica continuidad (chevron, flecha → "Más opciones ▾").

### Toolbar pattern

```html
<button class="btn btn-ghost">Cancelar</button>
<button class="btn btn-secondary">Guardar borrador</button>
<button class="btn btn-primary"><i class="bi bi-check"></i>Enviar a aprobación</button>
```

> **Regla de oro:** una sola `.btn-primary` visible por sección. Si necesitas más, conviértelas en `.btn-secondary` y eleva una al primario según el contexto.

---

## 06 · Badges & Pills

Tres familias: **estado** (con dot), **prioridad** (con glifo), **tags neutros** (categorías sin dot).

### Especificación base

```css
.pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 8px; font-size: 10.5px;
  font-weight: 700; letter-spacing: 0.4px;
  text-transform: uppercase; border-radius: 3px; line-height: 1.2;
}
.pill .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
```

### Variantes (uso obligatorio de soft en listados)

```css
.pill-primary-soft  { background: var(--primary-soft);   color: var(--primary-color); }
.pill-orange-soft   { background: var(--secondary-soft); color: var(--secondary-color); }
.pill-warning-soft  { background: var(--warning-soft);   color: var(--warning-text); }
.pill-danger-soft   { background: var(--danger-soft);    color: var(--danger-color); }
.pill-info-soft     { background: var(--info-soft);      color: var(--info-text); }
.pill-muted         { background: var(--background-color); color: var(--text-muted); }

/* Sólidas — uso restringido a sidebar/hero/headers oscuros */
.pill-primary       { background: var(--primary-color);   color: #fff; }
.pill-orange        { background: var(--secondary-color); color: #fff; }
.pill-dark          { background: var(--bg-dark);         color: #fff; }
```

### Patrones de uso

**Estados de pipeline (con dot):**
```html
<span class="pill pill-warning-soft"><span class="dot" style="background:#ffc107"></span>Aprobación</span>
<span class="pill pill-orange-soft"><span class="dot" style="background:#CD6A15"></span>Contabilidad</span>
<span class="pill pill-info-soft"><span class="dot" style="background:#0dcaf0"></span>Tesorería</span>
<span class="pill pill-primary-soft"><i class="bi bi-check"></i>Pagada</span>
<span class="pill pill-danger-soft"><span class="dot" style="background:#dc3545"></span>Rechazada</span>
```

**Estados de documento (con glifo contextual):**
```html
<span class="pill pill-primary-soft"><i class="bi bi-check"></i>Vigente</span>
<span class="pill pill-warning-soft"><i class="bi bi-clock"></i>Vence en 16d</span>
<span class="pill pill-danger-soft"><i class="bi bi-exclamation-triangle"></i>Faltante</span>
```

**Tags neutros (sin dot):**
```html
<span class="pill pill-orange-soft">REINTEGRO</span>
<span class="pill pill-muted">FACTURA</span>
```

### Tamaños

| Tamaño | Font | Padding | Uso |
|--------|------|---------|-----|
| `sm` | 9.5px | 2px 6px | Dentro de tablas densas |
| `md` (default) | 10.5px | 3px 8px | Por defecto |
| `lg` | 11px | 4px 10px | Cards hero |

> En tablas y listas usa **siempre** variantes `-soft`. El color sólido solo en superficies oscuras (sidebar, hero) y como acción primaria. **Nunca pills sólidos dentro de tablas** — saturan la grilla.

---

## 07 · Inputs & formularios

Altura estándar **36px**. Outline (no border) 1px `--border-color` por defecto, 2px `--primary-color` al focus, 1.5px `--danger-color` en error.

### Especificación

```css
.input {
  display: flex; align-items: center; gap: 10px;
  background: #fff;
  outline: 1px solid var(--border-color);
  outline-offset: -1px;
  padding: 0 14px;
  border-radius: 4px;
  height: 36px;
  font-size: 13px;
  color: var(--text-default);
}
.input.focus    { outline: 2px solid var(--primary-color); outline-offset: -2px; }
.input.error    { outline: 1.5px solid var(--danger-color); outline-offset: -1.5px; }
.input.disabled { background: var(--bg-subtle); color: var(--text-disabled); }

.input input {
  flex: 1; border: none; outline: none; background: transparent;
  padding: 0; font-size: 13px; color: inherit; font-family: inherit; min-width: 0;
}

.input-label {
  font-size: 11px; font-weight: 600; color: var(--text-muted);
  display: block; margin-bottom: 6px;
}
.input-help { font-size: 11px; color: var(--text-faint); margin-top: 5px; }
.input-help.error { color: var(--danger-color); }
```

### Estados

```html
<!-- Default -->
<span class="input-label">Proveedor</span>
<div class="input"><input placeholder="Selecciona un proveedor…"></div>
<div class="input-help">Búscalo por nombre o NIT.</div>

<!-- Focus -->
<div class="input focus"><input class="mono" value="$ 120.000"></div>

<!-- Error -->
<div class="input error"><input class="mono" value="100619"></div>
<div class="input-help error">La cédula debe tener al menos 7 dígitos.</div>

<!-- Disabled -->
<div class="input disabled"><input value="PERSONAL" disabled></div>
```

### Con icono / Select / Textarea

```html
<!-- Con icono de búsqueda -->
<div class="input">
  <i class="bi bi-search" style="color: var(--text-faint)"></i>
  <input placeholder="Número, proveedor, OC…">
</div>

<!-- Select (mismo input + chevron derecho) -->
<div class="input">
  <span class="grow">Gastos Administrativos</span>
  <i class="bi bi-chevron-down" style="color: var(--text-faint)"></i>
</div>

<!-- Textarea -->
<div class="input" style="height:auto; padding:10px 14px; align-items:flex-start">
  <textarea placeholder="Agrega una observación visible…"
            style="border:none; outline:none; width:100%; font-family:inherit;
                   font-size:13px; resize:vertical; min-height:64px; background:transparent">
  </textarea>
</div>
```

### Reglas

- Valor monetario → siempre clase `.mono`.
- Cédula / código → siempre clase `.mono`.
- Placeholder en `var(--text-disabled)`.
- Mensajes de ayuda (`.input-help`) en `var(--text-faint)`, error en `var(--danger-color)`.

---

## 08 · Tabs y filtros

Tres patrones según contexto:

### Tabs (navegación dentro del módulo)

```html
<div class="tabs">
  <button class="tab is-active">
    <i class="bi bi-file-earmark"></i> Documentos
    <span class="tab-badge">2</span>
  </button>
  <button class="tab">Perfil</button>
  <button class="tab">Contrato</button>
  <button class="tab">Historial</button>
</div>
```

```css
.tab {
  padding: 8px 14px; font-size: 12.5px; font-weight: 600;
  background: transparent; color: var(--text-muted);
  border: none; border-bottom: 2px solid transparent;
  display: inline-flex; align-items: center; gap: 6px;
}
.tab.is-active {
  color: var(--primary-color);
  border-bottom-color: var(--primary-color);
}
.tab-badge {
  padding: 1px 5px; font-size: 9px; font-weight: 700;
  background: var(--danger-soft); color: var(--danger-color);
  border-radius: 2px;
}
.tab.is-active .tab-badge {
  background: var(--primary-soft); color: var(--primary-color);
}
```

### Chips (filtros por estado)

```html
<button class="chip is-active">
  <span class="dot" style="background: var(--primary-color)"></span>Todas · 8
</button>
<button class="chip">En aprobación · 3</button>
<button class="chip">Contabilidad · 1</button>
```

```css
.chip {
  padding: 6px 14px; font-size: 11.5px; font-weight: 600;
  background: transparent; color: var(--text-muted);
  border: none; border-radius: 4px;
  display: inline-flex; align-items: center; gap: 6px;
}
.chip.is-active { background: #fff; color: var(--primary-color); }
.chip.is-active .dot { width: 6px; height: 6px; border-radius: 50%; }
```

### Segmented (alternar vista / densidad)

```html
<div class="segmented">
  <button class="seg is-active"><i class="bi bi-grid"></i> Cards</button>
  <button class="seg"><i class="bi bi-list"></i> Tabla</button>
</div>
```

```css
.segmented { background: #fff; padding: 3px; display: inline-flex; border-radius: 4px; }
.seg {
  padding: 5px 12px; font-size: 11.5px; font-weight: 600;
  background: transparent; color: var(--text-faint);
  border: none; display: inline-flex; align-items: center; gap: 6px;
}
.seg.is-active { background: var(--primary-color); color: #fff; }
```

### Filtros + búsqueda combinados

```html
<div class="row-flex gap-8">
  <div class="input grow">
    <i class="bi bi-search" style="color: var(--text-faint)"></i>
    <input placeholder="Buscar…">
  </div>
  <button class="btn btn-ghost"
          style="outline: 1px solid var(--border-color); outline-offset: -1px;">
    <i class="bi bi-funnel"></i>
    Filtros · <span style="color: var(--primary-color); font-weight: 700">3</span>
  </button>
</div>
```

El contador de filtros activos siempre en color `--primary-color`.

---

## 09 · Cards y superficies

Toda card SGI es:

```css
.sgi-card, .card {
  background: #fff;
  padding: 20px;     /* o 16–18 si es compacta */
  border-radius: 0;
  /* SIN border, SIN shadow */
}
```

### Anatomía típica

```html
<div class="sgi-card">
  <header class="card-head">
    <div class="sgi-title-card">Pagos Registrados</div>
    <button class="btn btn-ghost btn-sm">+ Agregar pago</button>
  </header>

  <!-- meta opcional -->
  <div class="sgi-body-faint">1 movimiento · Total $ 120.000</div>

  <!-- body -->
  <div class="card-body">…</div>

  <!-- footer opcional -->
  <footer class="card-footer">…</footer>
</div>
```

### Variantes

**Card básica** — label + valor + meta:
```html
<div class="sgi-card">
  <div class="sgi-label">PROVEEDOR</div>
  <div style="font-size:14px;font-weight:600;margin-top:6px">AGROPECUARIA ORGANICA TATAMA</div>
  <div class="sgi-body-faint" style="margin-top:4px">BUENAVENTURA SPRBUN</div>
</div>
```

**Card con valor hero** (KPI):
```html
<div class="sgi-card">
  <div class="sgi-label">VALOR FACTURA</div>
  <div class="sgi-display sgi-fg-primary" style="margin-top:6px">$ 120.000</div>
  <div class="row-flex gap-6" style="margin-top:6px; color: var(--text-muted)">
    <i class="bi bi-check-circle sgi-fg-primary"></i> Pagado · 13/05/2026
  </div>
</div>
```

**Card con acento** (barra 3px izquierda):
```html
<div class="sgi-card" style="position:relative">
  <div class="accent-strip accent-orange"></div>
  <div style="padding-left:8px">
    <div class="sgi-label">EN APROBACIÓN</div>
    <div class="sgi-display sgi-fg-secondary" style="margin-top:6px">3</div>
    <div class="sgi-body-faint" style="margin-top:6px">facturas pendientes</div>
  </div>
</div>
```

```css
.accent-strip { position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
.accent-green   { background: var(--primary-color); }
.accent-orange  { background: var(--secondary-color); }
.accent-warning { background: var(--warning-color); }
.accent-danger  { background: var(--danger-color); }
```

### Card compleja (head + sub-superficie + footer)

```html
<div class="sgi-card">
  <header class="card-head">
    <div class="sgi-title-card">Pagos Registrados</div>
    <button class="btn btn-ghost btn-sm">+ Agregar pago</button>
  </header>

  <!-- Sub-superficie con bg-subtle -->
  <div style="background: var(--bg-subtle); padding: 12px 14px; display:flex; gap:12px;">
    <div class="bank-chip">
      <i class="bi bi-bank"></i>
    </div>
    <div class="grow">
      <div style="font-weight:600">Davivienda</div>
      <div class="mono sgi-body-faint">13/05/2026 · por Alexander Caicedo</div>
    </div>
    <span class="pill pill-primary-soft">AUTORIZADO</span>
    <div class="mono sgi-fg-primary" style="font-size:14px;font-weight:700">$ 120.000</div>
  </div>
</div>
```

### Separador interior

```css
.hr { height: 1px; background: var(--rule); margin: 14px 0; }
```

Es la línea interior permitida — NO un border de la card.

### Primitivas del sistema

Patrones canónicos con clase CSS asociada. Toda vista nueva debe componerse a partir de ellos.

| Primitiva | Función | Clase / patrón CSS |
|-----------|---------|--------------------|
| `KV` | Label uppercase 10.5px + valor 12.5px 600 en columna | `.sgi-label` + valor; gap 3px |
| `SectionHead` | Label uppercase + acción a la derecha, margin-bottom 14px | `.card-head` (ver "Anatomía típica") |
| `FieldRow` | Fila key/value con `border-bottom: 1px solid --rule`, último sin border | `.field-row` (ver abajo) |
| `TopBar` | Barra superior 52px (ver sección 13) | `.sgi-topbar` |
| `fmtMoney(120000)` | Formatea como `$ 120.000` (es-CO, sin decimales) | helper PHP/JS |

### FieldRow — patrón canónico

```html
<div class="field-row">
  <span class="k">Proveedor</span>
  <span class="v">AGROPECUARIA ORGANICA TATAMA</span>
</div>
<div class="field-row">
  <span class="k">NIT</span>
  <span class="v mono">900.123.456-7</span>
</div>
<div class="field-row is-last">
  <span class="k">Valor</span>
  <span class="v mono sgi-fg-primary">$ 120.000</span>
</div>
```

```css
.field-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 9px 0; gap: 12px;
  border-bottom: 1px solid var(--rule);
}
.field-row.is-last { border-bottom: none; }
.field-row > .k { font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
.field-row > .v {
  font-size: 12.5px; font-weight: 600; color: var(--text-default);
  text-align: right; min-width: 0;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
```

---

## 10 · Avatares

Iniciales sobre fondo color, cuadrado por defecto (`radius: 3px`). Color asignado por **hash del nombre completo** entre 7 tonos cálidos. Determinístico: el mismo empleado siempre tiene el mismo color.

### Especificación

```css
.av {
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700; color: #fff; flex-shrink: 0;
  border-radius: 3px;
  background: var(--primary-color);   /* fallback */
  text-transform: uppercase;
}
.av-sm { width: 24px; height: 24px; font-size: 10px; }   /* row densa */
.av-md { width: 34px; height: 34px; font-size: 13px; }   /* lista */
.av-lg { width: 48px; height: 48px; font-size: 16px; }   /* card */
.av-xl { width: 64px; height: 64px; font-size: 22px; }   /* header */
/* Tamaños custom: 20 (meta), 60 (header alterno) */
```

### Paleta de hash (7 tonos cálidos)

```
#469D61  #CD6A15  #83542B  #212529  #5a4a2a  #4a6f5c  #7a4c1e
```

Algoritmo: hash del nombre completo módulo 7 → índice en la paleta. Garantiza que el mismo empleado tenga siempre el mismo color.

### Variante circular (opcional)

El componente `Avatar` soporta `shape: 'circle'` además del cuadrado por defecto. Úsalo solo en headers de perfil o user-card del sidebar — en listas mantén cuadrado para legibilidad.

```css
.av.is-circle { border-radius: 50%; }
```

### En contexto

```html
<div class="row-flex gap-14" style="padding: 12px; background: var(--bg-muted)">
  <div class="av av-lg" style="background:#469D61">JA</div>
  <div class="grow">
    <div style="font-size:14px;font-weight:700">JHON FREDDY ACOSTA MONTAÑO</div>
    <div class="mono sgi-body-faint">
      CC 1006193265 · jhondeian04@gmail.com · Ingreso 01/03/2024
    </div>
  </div>
  <span class="pill pill-info-soft"><span class="dot" style="background:#0dcaf0"></span>Activo</span>
</div>
```

> Nunca usar fotos en listas largas — solo iniciales.

---

## 11 · Tablas

Header con `--bg-subtle`, fila con bg `#fff`, hover con `--bg-muted`, separador 1px `--rule` entre filas (border-bottom de la fila, NO border de la card).

### Listado tipo factura (grid-based, no `<table>`)

```css
.row-fact {
  display: grid;
  grid-template-columns: 1.3fr 2.4fr 1fr 1.1fr 1.6fr 28px;
  gap: 14px; align-items: center;
  padding: 14px 16px;
  background: #fff;
}
.row-fact + .row-fact { border-top: 1px solid var(--rule); }
.row-fact.head {
  background: var(--bg-subtle);
  padding: 10px 16px;
  font-size: 10px; font-weight: 700; color: var(--text-faint);
  letter-spacing: 0.7px; text-transform: uppercase;
}
.row-fact:hover { background: var(--bg-muted); }
```

### Fila completa

```html
<div class="row-fact head">
  <span>Factura</span><span>Proveedor</span><span>Vence</span>
  <span style="text-align:right">Valor</span><span>Estado · Pipeline</span><span></span>
</div>

<div class="row-fact">
  <div>
    <div class="mono" style="font-weight:700">E008113522</div>
    <div class="sgi-label" style="margin-top:2px">REINTEGRO</div>
  </div>
  <div>
    <div style="font-weight:600">AGROPECUARIA ORGANICA TATAMA</div>
    <div class="sgi-body-faint" style="margin-top:2px">BUENAVENTURA SPRBUN</div>
  </div>
  <div class="mono">19/05/2026</div>
  <div class="mono sgi-fg-primary" style="font-size:13.5px;font-weight:700;text-align:right">$ 120.000</div>
  <div>
    <div class="pipeline-mini" style="margin-bottom:5px">
      <div class="on"></div><div class="on"></div><div class="on"></div>
      <div class="on"></div><div class="on"></div><div class="on"></div>
    </div>
    <span class="pill pill-primary-soft"><i class="bi bi-check"></i>PAGADA</span>
  </div>
  <div style="color: var(--text-faint); text-align:center">
    <i class="bi bi-chevron-right"></i>
  </div>
</div>
```

### Reglas

- Vencimientos en `var(--danger-color)` cuando la factura NO está pagada y la fecha ya pasó.
- Códigos de factura, fechas, montos → `.mono`.
- Pills SIEMPRE en variante soft.
- Chevron derecho a 14px color `var(--text-faint)`.

---

## 12 · Date & Time pickers

Trigger discreto tipo input (mismo alto 36px). Calendario popover de 270px, grid 7×6. Soporta fecha única y rango.

### Trigger (input)

```html
<div class="input" style="width:200px">
  <i class="bi bi-calendar3 sgi-fg-primary"></i>
  <span class="grow">18 mayo 2026</span>
  <i class="bi bi-chevron-down" style="color: var(--text-faint)"></i>
</div>
```

Range trigger:
```html
<div class="input" style="width:240px">
  <i class="bi bi-calendar3 sgi-fg-primary"></i>
  <span class="grow">14 may → 18 may</span>
  <i class="bi bi-chevron-down"></i>
</div>
```

### Calendar popover

```css
.cal { width: 270px; background: #fff; padding: 12px 14px 14px; }
.cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cal-head .month { font-size: 13px; font-weight: 700; color: var(--text-strong); }
.cal-head button {
  background: transparent; border: none; cursor: pointer;
  color: var(--text-muted); width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
}
.cal-head button:hover { background: var(--bg-subtle); }

.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; text-align: center; }
.cal-grid .dow {
  font-size: 10px; color: var(--text-faint); font-weight: 600;
  padding: 4px 0; text-transform: uppercase;
}
.cal-grid .day { padding: 6px 0; font-size: 12px; cursor: pointer; border-radius: 3px; }
.cal-grid .day:hover  { background: var(--bg-subtle); }
.cal-grid .day.today  { outline: 1.5px solid var(--primary-color); outline-offset: -1.5px;
                        color: var(--primary-color); font-weight: 700; }
.cal-grid .day.sel    { background: var(--primary-color); color: #fff; font-weight: 700; }
.cal-grid .day.in-range { background: var(--primary-soft); color: #2a6b40; border-radius: 0; }
.cal-grid .day.muted  { color: var(--border-faint); }
```

### Shortcuts (lista vertical al lado del calendario)

```html
<div class="shortcuts">
  <div class="shortcut is-active">Hoy</div>
  <div class="shortcut">Últimos 7 días</div>
  <div class="shortcut">Este mes</div>
  <div class="shortcut">Mes anterior</div>
  <div class="shortcut">Personalizado…</div>
</div>
```

```css
.shortcut { padding: 7px 12px; font-size: 12.5px; color: var(--text-default); }
.shortcut.is-active { color: var(--primary-color); background: rgba(70,157,97,0.10); font-weight: 600; }
```

### Time picker

Lista vertical scrollable de horas en pasos de 30 min, valor activo con fondo verde.

```html
<div class="time-list">
  <div class="time-opt mono">08:00</div>
  <div class="time-opt mono">08:30</div>
  <div class="time-opt mono is-active">09:00</div>
  <div class="time-opt mono">09:30</div>
</div>
```

### Resumen de rango

```html
<div style="background: var(--primary-soft); padding: 14px 16px">
  <div class="sgi-label sgi-fg-primary">RANGO SELECCIONADO</div>
  <div style="font-size:16px;font-weight:700;margin-top:6px">14 may — 18 may 2026</div>
  <div class="sgi-body-faint" style="margin-top:3px">5 días</div>
</div>
```

### Formatos visibles

- **Tabla:** `DD/MM/AAAA` (`13/05/2026`).
- **Trigger compacto:** `14 may` o `14 may → 18 may`.
- **Timestamp largo:** `13/05/2026 09:35`.
- Internamente siempre ISO. Locale `es-CO`, primer día de semana **lunes**.

---

## TopBar — barra superior de la página

Estructura común a todas las vistas: 52px de alto, fondo `#fff`, breadcrumb a la izquierda (con accent strip verde 3×18px), fecha mono a la derecha.

```html
<div class="sgi-topbar">
  <div class="sgi-topbar-crumb">
    <span class="sgi-topbar-accent"></span>
    <span>Todas las Facturas</span>
  </div>
  <div class="sgi-topbar-right">
    <button class="btn btn-ghost btn-sm"><i class="bi bi-bell"></i></button>
    <div class="sgi-topbar-date">
      <i class="bi bi-calendar3"></i>
      <span>Viernes, 15 de Mayo del 2026</span>
    </div>
  </div>
</div>
```

```css
.sgi-topbar {
  height: 52px;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 24px;
  background: #fff;
  flex-shrink: 0;
}
.sgi-topbar-crumb {
  display: flex; align-items: center; gap: 12px;
  font-size: 13px; font-weight: 600; color: var(--text-default);
}
.sgi-topbar-accent {
  width: 3px; height: 18px;
  background: var(--primary-color);
}
.sgi-topbar-right {
  display: flex; align-items: center; gap: 14px;
}
.sgi-topbar-date {
  font-size: 11.5px; color: var(--text-faint);
  display: flex; align-items: center; gap: 6px;
}
```

> Sin border-bottom. El contraste con el canvas viene del color de fondo (`#fff` vs `var(--background-color)`).

---

## 13 · Sidebar

Tres bloques fijos:

1. **Logo + Search** arriba.
2. **Módulos agrupados** en el medio (Favoritos · Reciente · Módulos).
3. **User card** abajo.

Fondo `--bg-dark`. Logo `IconLogo` cuadrado 32×32 fondo `--primary-color`. Buscador con atajo `⌘K`.

### Item · estados

```css
.sb-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 14px;
  font-size: 12.5px; font-weight: 500;
  border-left: 2px solid transparent;
  color: rgba(255,255,255,0.72);
}
.sb-item .ic { width: 16px; color: rgba(255,255,255,0.5); display: inline-flex; }
.sb-item.hover  { background: rgba(255,255,255,0.04); color: #fff; }
.sb-item.active {
  background: var(--primary-soft-strong);
  color: #fff;
  border-left-color: var(--primary-color);
}
.sb-item.active .ic { color: var(--primary-color); }

.sb-section-head {
  padding: 12px 14px 5px;
  font-size: 9.5px; font-weight: 700;
  color: rgba(255,255,255,0.35);
  letter-spacing: 1.5px;
  text-transform: uppercase;
}
```

### Buscador

```html
<div class="sb-search">
  <i class="bi bi-search"></i>
  <span class="grow">Buscar…</span>
  <span class="kbd mono">⌘K</span>
</div>
```

```css
.sb-search {
  background: rgba(255,255,255,0.06);
  padding: 7px 10px; display: flex; align-items: center; gap: 8px;
  font-size: 11.5px; color: rgba(255,255,255,0.55);
  border-radius: 4px;
}
.sb-search .kbd {
  font-size: 9px; padding: 1px 5px; border-radius: 2px;
  background: rgba(255,255,255,0.10); color: rgba(255,255,255,0.65);
}
```

### Badges en items

```html
<div class="sb-item">
  <span class="ic"><i class="bi bi-exclamation-circle"></i></span>
  <span class="grow">Vencidas</span>
  <span style="background:var(--danger-color); color:#fff; font-size:9.5px; font-weight:700;
               padding:1px 6px; border-radius:2px">3</span>
</div>
```

Color del badge según el tipo de alerta: rojo (vencidas), amarillo (atención), verde (positivo).

### Branding — logo en el sidebar

Glifo 32×32 fondo `--primary-color`, icono triangular (cumbre/pirámide) en `#fff`. Variante para fondos oscuros (sidebar) y fondos claros (login, headers).

**Variante dark** (sidebar / hero oscuro):
```html
<div style="background:#212529; padding:16px 20px; display:flex; align-items:center; gap:10px">
  <div style="width:32px; height:32px; background:var(--primary-color); display:flex; align-items:center; justify-content:center">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M12 3 2 20h20z"/></svg>
  </div>
  <div>
    <div style="font-weight:700; font-size:13px; color:#fff; letter-spacing:0.3px">SGI · COPCSA</div>
    <div style="font-size:8.5px; color:rgba(255,255,255,0.55); letter-spacing:1px;
                text-transform:uppercase; margin-top:2px">Sistema de Gestión Interna</div>
  </div>
</div>
```

**Variante light** (login / headers claros): mismo glifo, fondo `#fff` con `outline: 1px solid var(--rule); outline-offset: -1px`, texto en `--text-strong` y tagline en `--text-faint`.

Glifo 32×32 · texto Inter 700 13px · tagline uppercase letter-spacing 1px. **Pendiente:** reemplazar el triángulo placeholder con la marca oficial de COPCSA.

---

## 14 · Pipeline

6 etapas fijas de aprobación de factura. Dos representaciones:

### Mini horizontal (lista de facturas)

```html
<div class="pipeline-mini">
  <div class="on"></div>
  <div class="on"></div>
  <div class="on"></div>
  <div></div>
  <div></div>
  <div></div>
</div>
```

```css
.pipeline-mini { display: flex; gap: 2px; max-width: 180px; }
.pipeline-mini > div { flex: 1; height: 4px; border-radius: 1px; background: var(--rule); }
.pipeline-mini > div.on { background: var(--primary-color); }
```

Color de los segmentos `.on` cambia según el estado actual:
- En aprobación → `var(--warning-color)` (#ffc107)
- Contabilidad / Tesorería / Pago → `var(--secondary-color)` (#CD6A15)
- Pagada → `var(--primary-color)` (#469D61)

### Vertical detallado (detalle de factura)

Cada paso = marker + label + timestamp en mono.

```html
<div class="pipeline-v">
  <div class="pv-step is-done">
    <div class="pv-marker"><i class="bi bi-check"></i></div>
    <div>
      <div class="pv-label">Aprobación</div>
      <div class="mono sgi-body-faint">13/05 09:35</div>
    </div>
  </div>

  <div class="pv-step is-current">
    <div class="pv-marker"><span class="dot"></span></div>
    <div>
      <div class="pv-label">Tesorería</div>
      <div class="mono sgi-body-faint">en curso</div>
    </div>
  </div>

  <div class="pv-step is-pending">
    <div class="pv-marker"></div>
    <div>
      <div class="pv-label">Autorización de pago</div>
      <div class="mono sgi-body-faint">Pendiente</div>
    </div>
  </div>
</div>
```

```css
.pipeline-v { position: relative; padding: 12px 16px; }
.pipeline-v::before {
  content: ''; position: absolute; left: 25px; top: 24px; bottom: 24px;
  width: 2px; background: var(--rule);
}
.pv-step { display: flex; gap: 12px; align-items: flex-start; margin-top: 8px; position: relative; }
.pv-marker {
  width: 20px; height: 20px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: #fff; color: #fff; z-index: 1;
}
.pv-step.is-done    .pv-marker { background: var(--primary-color); }
.pv-step.is-current .pv-marker { background: var(--primary-color); }
.pv-step.is-pending .pv-marker { background: #fff; outline: 1.5px dashed var(--border-faint); outline-offset: -1.5px; }
.pv-step.is-current .pv-marker .dot { width: 6px; height: 6px; background: #fff; border-radius: 50%; }
.pv-label { font-size: 12.5px; font-weight: 600; color: var(--text-strong); }
.pv-step.is-pending .pv-label { color: var(--text-faint); }
```

---

## 15 · Gestión documental

Cada tipo de documento del empleado tiene un código mono uppercase de 2–4 letras. Color = estado. Permite escanear la completitud de una carpeta de un vistazo.

### Códigos · catálogo

```css
.doc {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 7px 9px; font-size: 11px; font-weight: 700;
  font-family: var(--font-mono); letter-spacing: 0.5px;
  color: #fff; min-width: 42px;
  border-radius: 3px;
}
.doc.ok   { background: var(--primary-color);  color: #fff; }
.doc.warn { background: var(--warning-color);  color: #5c4a08; }
.doc.miss { background: var(--danger-color);   color: #fff; }
.doc.dim  { background: var(--rule);           color: var(--text-faint); }
```

### Catálogo de 14 códigos

```
CC · HV · EPS · AFP · ARL · CCF · CTR · OTRO · CB · BG · EX1 · EX2 · CAP1 · CAP2
```

Categorías: Identificación · Seguridad social · Contractual · Pagos · Médicos · Capacitación.

### Indicador de completitud (barra apilada)

```html
<div class="row-flex" style="justify-content:space-between; margin-bottom:12px">
  <div>
    <div class="sgi-label">12<span style="color:var(--text-faint);font-weight:500">/14 · 86%</span></div>
    <div class="row-flex gap-4 mono" style="font-size:11px;color:var(--text-muted);margin-top:6px">
      <span class="row-flex gap-4"><span style="width:10px;height:10px;background:var(--primary-color)"></span>11 vigentes</span>
      <span class="row-flex gap-4"><span style="width:10px;height:10px;background:var(--warning-color)"></span>1 por vencer</span>
      <span class="row-flex gap-4"><span style="width:10px;height:10px;background:var(--danger-color)"></span>2 faltantes</span>
    </div>
  </div>
</div>
<div class="row-flex" style="height:8px; gap:2px">
  <div style="flex:11; background:var(--primary-color)"></div>
  <div style="flex:1;  background:var(--warning-color)"></div>
  <div style="flex:2;  background:var(--danger-color)"></div>
</div>
```

### Fila de documento

```html
<div class="row-flex gap-12" style="padding: 12px 16px; background: var(--bg-muted)">
  <div class="doc warn">ARL</div>
  <div class="grow">
    <div style="font-weight:600">ARL · SURA</div>
    <div class="mono sgi-body-faint">arl_2025.pdf · 623 KB</div>
  </div>
  <div class="col-flex gap-2">
    <div class="sgi-label">CARGADO</div>
    <div class="mono">03/02/2025</div>
  </div>
  <div class="col-flex gap-2">
    <div class="sgi-label">VENCE</div>
    <div class="mono sgi-fg-warning" style="font-weight:600">01/06/2026</div>
  </div>
  <span class="pill pill-warning-soft"><i class="bi bi-clock"></i>Vence en 16d</span>
  <div class="row-flex gap-4">
    <button class="btn-icon"><i class="bi bi-eye"></i></button>
    <button class="btn-icon"><i class="bi bi-download"></i></button>
  </div>
</div>
```

```css
.btn-icon {
  width: 28px; height: 28px; background: var(--bg-subtle); border: none;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-muted);
}
.btn-icon:hover { background: var(--primary-soft); color: var(--primary-color); }
```

---

## 16 · Empty states

Copy corto en español formal. Sin emoji, sin admiraciones. **Siempre con una acción siguiente clara.**

### Dropzone

```html
<div class="dropzone">
  <i class="bi bi-paperclip"></i>
  <div>Arrastra archivos aquí o <a class="dz-link">examina</a></div>
  <div class="dz-hint">PDF, JPG, PNG · máx 10 MB</div>
</div>
```

```css
.dropzone {
  padding: 28px 20px; text-align: center;
  background: var(--bg-muted);     /* NO border dashed */
}
.dropzone .bi { font-size: 22px; color: var(--text-disabled); margin-bottom: 8px; display: block; }
.dropzone .dz-link { color: var(--secondary-color); font-weight: 600; cursor: pointer; }
.dropzone .dz-hint { font-size: 10.5px; color: var(--text-disabled); margin-top: 4px; }
```

### Sin resultados

```html
<div class="empty-state">
  <div class="es-icon es-icon-neutral"><i class="bi bi-search"></i></div>
  <div class="es-title">Sin facturas en este filtro</div>
  <div class="es-msg">Cambia el filtro o crea una nueva factura.</div>
  <button class="btn btn-primary btn-sm">+ Nueva Factura</button>
</div>
```

### Carpeta incompleta (con warning)

```html
<div class="empty-state">
  <div class="es-icon es-icon-warning"><i class="bi bi-exclamation-triangle"></i></div>
  <div class="es-title">Faltan 2 documentos</div>
  <div class="es-msg">Subir Examen Periódico y Capacitación de Espacios Confinados.</div>
  <button class="btn btn-secondary btn-sm">Ir a documentos</button>
</div>
```

```css
.empty-state { padding: 24px 16px; text-align: center; }
.es-icon {
  width: 48px; height: 48px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 10px;
}
.es-icon-neutral { background: var(--bg-subtle); color: var(--text-disabled); }
.es-icon-warning { background: var(--warning-soft); color: var(--warning-text); }
.es-icon i { font-size: 22px; }
.es-title { font-size: 13px; font-weight: 600; color: var(--text-strong); }
.es-msg   { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; line-height: 1.5; }
.empty-state .btn { margin-top: 12px; }
```

### Copy obligatorio

Aceptados:
- "Sin facturas en este filtro"
- "Arrastra archivos aquí o examina"
- "Faltan 2 documentos"
- "Sin soportes adjuntos"

**Nunca:**
- "¡Ups!", "Vaya, no encontramos…", emoji, animaciones de mascota.

---

## Tono y copy

El SGI es una herramienta operativa para administrativos y supervisores. Tono **directo, profesional, español formal pero no rígido**.

- **Voz: tercera persona / impersonal.** "Aprobado por", "Registrado por", "Pendiente de aprobación".
- **Sin "tú" ni "usted"** directos en UI. Sí en mensajes de ayuda contextual ("Arrastra archivos aquí").
- **Casing por contexto:**
  | Contexto | Casing | Ejemplo |
  |----------|--------|---------|
  | Botones / acciones | Tipo oración | "Nueva Factura", "Agregar pago" |
  | Labels / encabezados | MAYÚSCULAS + tracking | "PIPELINE", "PROVEEDOR" |
  | Estados / pills | MAYÚSCULAS | "PAGADA", "VIGENTE" |
  | Códigos documento | MAYÚSCULAS mono | "CC", "HV", "ARL" |
- **Sin emoji** en producción. Sin admiraciones salvo errores críticos.
- **Valores monetarios:** `$ 120.000` (espacio tras `$`, separador miles con punto, sin decimales) usando `Intl.NumberFormat('es-CO')`.
- **Fechas:** `DD/MM/AAAA` en mono. Timestamps: `13/05/2026 09:35`.
- **Cuentas:** "8 documentos", "248 activos", "3 pendientes" — plurales correctos.

### Helper de formato de moneda

```js
// JS — Intl.NumberFormat es-CO
const fmtMoney = (n, { decimals = 0 } = {}) => {
  const s = n.toLocaleString('es-CO', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  return '$ ' + s;
};
```

```php
// PHP — helper equivalente
function fmtMoney(float $n, int $decimals = 0): string {
    return '$ ' . number_format($n, $decimals, ',', '.');
}
```

Resultado: `$ 120.000` para enteros, `$ 1.250,50` con decimales. Siempre con clase `.mono` en el DOM.

---

## Excepciones permitidas

Estos casos se apartan deliberadamente de las reglas duras. **Antes de añadir una nueva excepción, documentarla aquí** con selector y justificación.

### Chat bubbles (`.sgi-obs-bubble-body`)

```css
.sgi-obs-bubble.is-mine  .sgi-obs-bubble-body { border-radius: 10px 10px 2px 10px; }
.sgi-obs-bubble.is-other .sgi-obs-bubble-body { border-radius: 10px 10px 10px 2px; }
```
Radio asimétrico 10/2 es convención universal (WhatsApp/Slack/iMessage). Mantiene 2px como ancla al sistema.

### Counter pills (`.sgi-folder-count`)

```css
.sgi-folder-count { border-radius: 10px; padding: .15em .5em; min-width: 1.5em; }
```
Badges numéricos pequeños como contadores; convención visual estándar.

### Signature overlay (`webroot/js/sgi-signature.js`)

Card modal temporal flotante sobre documento con `backdrop-filter: blur(3px)`. **Única excepción que permite `box-shadow`** porque flota sobre fondo borroso y el border sería insuficiente.

### Template editor

Editor visual de plantillas con borders funcionales (zona arrastrable, selección). Los borders son afordancia interactiva, no decoración.

---

## Carga de CSS

Orden de carga obligatorio:

```html
<!-- 1. Bootstrap base -->
<link href="bootstrap.min.css" rel="stylesheet">
<!-- 2. Bootstrap Icons -->
<link href="bootstrap-icons.min.css" rel="stylesheet">
<!-- 3. Flatpickr -->
<link href="flatpickr.min.css" rel="stylesheet">
<!-- 4. Tokens + utilidades (foundation) -->
<?= $this->Html->css('styles') ?>
<!-- 5. Componentes SGI (debe ir después de Bootstrap para overrides) -->
<?= $this->Html->css('components') ?>
```

---

## Archivos clave del proyecto

| Archivo | Contenido |
|---------|-----------|
| `webroot/css/styles.css` | Tokens (`:root`), `@font-face`, base, tipografía, utilidades |
| `webroot/css/components.css` | Catálogo completo de componentes + Bootstrap overrides |
| `webroot/fonts/Inter-Variable.woff2` | Inter Variable 100–900 |
| `webroot/fonts/JetBrainsMono-Variable.woff2` | JetBrains Mono Variable 100–800 |
| `webroot/js/sgi-common.js` | Auto-init: Flatpickr, AutoNumeric, Select2, clickable rows |
| `templates/layout/default.php` | Layout principal (sidebar + topbar) |
| `templates/layout/login.php` | Layout split-panel del login |

---

## Convención de prefijos

El sistema usa dos prefijos según el rol del CSS:

| Sin prefijo | `sgi-` prefijado |
|-------------|------------------|
| Componentes UI: `.btn`, `.pill`, `.input`, `.av`, `.doc`, `.cal`, `.row-fact`, `.pipeline-mini`, `.sb-item`, `.tab`, `.chip` | Utilidades / tipografía: `.sgi-card`, `.sgi-display`, `.sgi-title-page`, `.sgi-title-card`, `.sgi-label`, `.sgi-mono`, `.sgi-body-muted`, `.sgi-body-faint`, `.sgi-fg-*` |

La regla: **componentes** = sin prefijo (más legible en markup); **utilidades de marca/sistema** = `sgi-` para no chocar con Bootstrap o librerías de terceros.
