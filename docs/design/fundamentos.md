# Sistema de Diseño SPI · COPCSA — Fundamentos

Tokens base del sistema: colores, tipografía, espaciado y superficies, iconografía.

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
| `--fs-display` | 30px | 800 | Moneda hero, número grande | `.spi-display` (mono · letter-spacing -1px) |
| `--fs-title-page` | 22px | 700 | Título de página (h1) | `.spi-title-page` |
| `--fs-title-card` | 14px | 700 | Título de card (h2/h3) | `.spi-title-card` |
| `--fs-body-lg` | 13px | 400–500 | Cuerpo grande | — |
| `--fs-body` | 12.5px | 400–500 | Body por defecto | — |
| `--fs-body-sm` | 12px | 500–600 | Body pequeño / faint | `.spi-body-faint` |
| `--fs-label` | 10.5px | 700 | Uppercase labels | `.spi-label` / `.label-up` |
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

Múltiplos de 4. Escala efectiva del SPI: **4, 8, 12, 14, 16, 20, 24, 28**.

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

> El SPI prefiere **ángulos rectos** para sensación de "operativo". No introducir radii arbitrarios.

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

En el proyecto SPI: Bootstrap Icons (`bi-*`) se usa como fallback. Si un icono no está en BI, agregar SVG inline siguiendo el patrón Lucide.

---

