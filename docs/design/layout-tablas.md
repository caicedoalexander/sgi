# Sistema de Diseño SGI · COPCSA — Layout y tablas

Cards y superficies, avatares, tablas.

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

