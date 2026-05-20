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

## Paginación

Para listados largos (más de 50 filas). Siempre visibles: primera, anterior, página actual, ±2 vecinas, última y siguiente. Elipsis para el resto. La página activa se rellena con primary.

```css
.pgn { display: inline-flex; align-items: center; gap: 4px; }
.pgn-btn { min-width: 28px; height: 28px; padding: 0 8px; background: #fff; outline: 1px solid var(--border-color); outline-offset: -1px; font-size: 11.5px; font-weight: 600; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; border-radius: 2px; }
.pgn-btn:hover { background: var(--bg-subtle); }
.pgn-btn.active { background: var(--primary-color); color: #fff; outline-color: var(--primary-color); }
.pgn-btn.disabled { opacity: 0.4; cursor: not-allowed; }
.pgn-ellipsis { min-width: 22px; text-align: center; color: var(--text-faint); font-size: 12px; }
```

```html
<div class="pgn">
  <div class="pgn-btn disabled" title="Primera"><i class="bi bi-chevron-double-left"></i></div>
  <div class="pgn-btn disabled" title="Anterior"><i class="bi bi-chevron-left"></i></div>
  <div class="pgn-btn active">1</div>
  <div class="pgn-btn">2</div>
  <div class="pgn-btn">3</div>
  <div class="pgn-btn">4</div>
  <span class="pgn-ellipsis">…</span>
  <div class="pgn-btn">21</div>
  <div class="pgn-btn" title="Siguiente"><i class="bi bi-chevron-right"></i></div>
  <div class="pgn-btn" title="Última"><i class="bi bi-chevron-double-right"></i></div>
</div>
```

> **Nota:** la app SGI usa **15 ítems por página fijo** (ver `CLAUDE.md`). El selector de tamaño de página de la propuesta original queda como componente disponible pero no cableado.

---

## Accordion / colapsable

Secciones expandibles para reducir scroll en formularios largos, agrupaciones por área o categorías de documentos. El chevron rota 90° al abrir.

```css
.acc { background: #fff; }
.acc-row { border-bottom: 1px solid var(--rule); }
.acc-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; cursor: pointer; }
.acc-head:hover { background: var(--bg-muted); }
.acc-chev { color: var(--text-faint); transition: transform 0.15s; }
.acc-row.open .acc-chev { transform: rotate(90deg); color: var(--primary-color); }
.acc-title { flex: 1; font-size: 12.5px; font-weight: 600; color: var(--text-default); }
.acc-row.open .acc-title { color: var(--text-strong); }
.acc-meta { font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.acc-body { padding: 0 18px 16px 44px; font-size: 12px; color: var(--text-muted); line-height: 1.55; }
```

```html
<div class="acc">
  <div class="acc-row open">
    <div class="acc-head">
      <span class="acc-chev"><i class="bi bi-chevron-right"></i></span>
      <span class="acc-title">Datos básicos</span>
      <span class="pill pill-primary-soft" style="font-size:9px">Completado</span>
      <span class="acc-meta">8 campos</span>
    </div>
    <div class="acc-body">
      Razón social, NIT, número de factura, fecha de emisión, fecha de vencimiento,
      valor antes de impuestos, IVA, retenciones aplicadas.
    </div>
  </div>
  <div class="acc-row">
    <div class="acc-head">
      <span class="acc-chev"><i class="bi bi-chevron-right"></i></span>
      <span class="acc-title">Contabilización</span>
      <span class="pill pill-warning-soft" style="font-size:9px">2 faltantes</span>
      <span class="acc-meta">6 campos</span>
    </div>
  </div>
</div>
```

La fila abierta lleva la clase `.open` (chevron rotado, título en `--text-strong`); las cerradas omiten el `.acc-body`.

---

## Chat de observaciones

Sustituye el bloque plano de "Observaciones" de la vista de factura. Mezcla comentarios humanos, eventos del sistema (cambios de estado, aprobaciones) y respuestas anidadas. Soporta menciones `@nombre`, adjuntos y etiquetas semánticas.

```css
.chat { background: #fff; width: 100%; max-width: 560px; display: flex; flex-direction: column; }
.chat-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--rule); }
.chat-head-title { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--text-strong); }
.chat-head-count { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 2px; background: var(--bg-subtle); color: var(--text-muted); }
.chat-filters { display: flex; gap: 0; padding: 6px 14px 0; border-bottom: 1px solid var(--rule); }
.chat-filter { font-size: 11px; font-weight: 600; color: var(--text-muted); padding: 7px 0; margin-right: 16px; cursor: pointer; border-bottom: 2px solid transparent; }
.chat-filter.active { color: var(--primary-color); border-color: var(--primary-color); }
.chat-list { padding: 16px 18px; display: flex; flex-direction: column; gap: 16px; }
.chat-item { display: flex; gap: 10px; }
.chat-item.reply { margin-left: 38px; }
.chat-av { width: 28px; height: 28px; border-radius: 3px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; color: #fff; }
.chat-body { flex: 1; min-width: 0; }
.chat-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; color: var(--text-faint); }
.chat-meta-author { color: var(--text-strong); font-weight: 700; font-size: 12px; }
.chat-meta-time { font-family: var(--font-mono); font-size: 10.5px; }
.chat-meta-tag { font-size: 9px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; padding: 1px 6px; border-radius: 2px; }
.tag-primary { background: var(--primary-soft); color: var(--primary-color); }
.tag-warning { background: var(--warning-soft); color: var(--warning-text); }
.tag-danger  { background: var(--danger-soft); color: var(--danger-color); }
.tag-muted   { background: var(--bg-subtle); color: var(--text-muted); }
.chat-text { font-size: 12.5px; color: var(--text-default); line-height: 1.55; margin-top: 5px; }
.chat-text .mention { color: var(--primary-color); background: rgba(70,157,97,0.10); padding: 1px 4px; border-radius: 2px; font-weight: 600; }
.chat-attach { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
.chat-attach-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 8px; background: var(--bg-subtle); font-size: 11px; color: var(--text-muted); border-radius: 3px; }
.chat-attach-chip code { font-family: var(--font-mono); color: var(--text-default); }
.chat-actions { margin-top: 6px; display: flex; gap: 14px; font-size: 10.5px; color: var(--text-faint); }
.chat-actions a { color: var(--text-muted); cursor: pointer; font-weight: 600; }
.chat-actions a:hover { color: var(--primary-color); }
.chat-sys { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 11px; color: var(--text-faint); }
.chat-sys-line { flex: 1; height: 1px; background: var(--rule); }
.chat-sys-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 3px; font-size: 10.5px; font-weight: 600; color: var(--text-muted); background: var(--bg-subtle); }
.chat-composer { border-top: 1px solid var(--rule); padding: 12px 14px; background: var(--bg-muted); }
.chat-composer-box { background: #fff; padding: 8px 10px; outline: 1px solid var(--border-color); outline-offset: -1px; border-radius: 3px; display: flex; flex-direction: column; gap: 8px; }
.chat-composer-box.focus { outline: 2px solid var(--primary-color); outline-offset: -2px; }
.chat-composer-input { border: none; outline: none; resize: none; background: transparent; font-family: inherit; font-size: 12.5px; color: var(--text-default); min-height: 36px; line-height: 1.55; }
.chat-composer-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.chat-composer-tools { display: flex; gap: 2px; }
.chat-composer-tool { background: transparent; border: none; padding: 5px; color: var(--text-faint); cursor: pointer; display: flex; border-radius: 2px; }
.chat-composer-tool:hover { background: var(--bg-subtle); color: var(--text-default); }
.chat-composer-tag { font-size: 9.5px; font-weight: 700; padding: 3px 6px; background: var(--primary-soft); color: var(--primary-color); border-radius: 2px; letter-spacing: 0.5px; text-transform: uppercase; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
```

```html
<div class="chat">
  <div class="chat-head">
    <div class="chat-head-title">
      <i class="bi bi-chat-left-text"></i> Observaciones <span class="chat-head-count">5</span>
    </div>
  </div>
  <div class="chat-filters">
    <div class="chat-filter active">Todas</div>
    <div class="chat-filter">Solo comentarios</div>
    <div class="chat-filter">Solo eventos</div>
  </div>
  <div class="chat-list">
    <!-- Comentario humano con adjunto -->
    <div class="chat-item">
      <div class="chat-av" style="background-color:var(--primary-color)">AC</div>
      <div class="chat-body">
        <div class="chat-meta">
          <span class="chat-meta-author">Alexander Caicedo</span>
          <span class="chat-meta-tag tag-primary">Aprobación externa</span>
          <span class="chat-meta-time">13/05/2026 09:35</span>
        </div>
        <div class="chat-text">Aprobación recibida del proveedor por correo. Documentación conciliada con el OC #4421.</div>
        <div class="chat-attach">
          <div class="chat-attach-chip"><i class="bi bi-paperclip"></i> <code>aprobacion_proveedor.pdf</code> · 248 KB</div>
        </div>
        <div class="chat-actions"><a>Responder</a><a>Reaccionar</a></div>
      </div>
    </div>
    <!-- Evento del sistema -->
    <div class="chat-sys">
      <div class="chat-sys-line"></div>
      <span class="chat-sys-pill">
        <i class="bi bi-check-lg" style="color:var(--primary-color)"></i>
        <b style="color:var(--text-default)">Carolina Mejía</b> marcó como <b style="color:var(--primary-color)">aprobada</b>
        <span class="mono" style="color:var(--text-faint); margin-left:4px">13/05 10:12</span>
      </span>
      <div class="chat-sys-line"></div>
    </div>
    <!-- Respuesta anidada -->
    <div class="chat-item reply">
      <div class="chat-av" style="background-color:var(--primary-color); width:24px; height:24px; font-size:9.5px">AC</div>
      <div class="chat-body">
        <div class="chat-meta">
          <span class="chat-meta-author" style="font-size:11.5px">Alexander Caicedo</span>
          <span class="chat-meta-time">13/05/2026 11:14</span>
        </div>
        <div class="chat-text" style="font-size:12px">Confirmado, va a OPERACIONES. Ya corregí el centro de costos.</div>
      </div>
    </div>
  </div>
  <div class="chat-composer">
    <div class="chat-composer-box focus">
      <textarea class="chat-composer-input" placeholder="Escribe una observación… usa @ para mencionar"></textarea>
      <div class="chat-composer-toolbar">
        <div style="display:flex; align-items:center; gap:8px">
          <span class="chat-composer-tag"><i class="bi bi-plus-lg"></i> Etiqueta</span>
          <div class="chat-composer-tools">
            <button class="chat-composer-tool" title="Adjuntar"><i class="bi bi-paperclip"></i></button>
            <button class="chat-composer-tool" title="Mencionar"><i class="bi bi-at"></i></button>
            <button class="chat-composer-tool" title="Marcar como evento"><i class="bi bi-clock"></i></button>
          </div>
        </div>
        <button class="btn btn-primary btn-sm">Publicar</button>
      </div>
    </div>
  </div>
</div>
```

- Los eventos del sistema (`.chat-sys`) se renderizan como pill centrado con línea horizontal a ambos lados, sin avatar.
- Las respuestas (`.chat-item.reply`) indentan 38px y reducen el avatar a 24px.
- Etiquetas semánticas: `.tag-primary` · `.tag-warning` · `.tag-danger` · `.tag-muted`. Las menciones disparan notificación al usuario citado.
- El `.chat-composer-box` toma la clase `.focus` cuando el textarea recibe foco.

