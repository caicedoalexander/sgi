# Sistema de Diseño SPI · COPCSA — Botones y badges

Componentes de acción y estado: botones (variantes, tamaños, estados) y badges/pills.

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

