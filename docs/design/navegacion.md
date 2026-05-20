# Sistema de Diseño SGI · COPCSA — Navegación

Patrones de navegación: TopBar, sidebar y pipeline.

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

Color del badge según el tipo de alerta: `.sb-badge.is-danger` rojo (vencidas), `.sb-badge.is-warning` amarillo (atención), `.sb-badge.is-primary` verde (positivo). El badge tiene `margin-left: auto`, por lo que se ancla solo a la derecha — va como hermano del `.grow`, sin clases extra.

```css
.sb-badge {
  font-size: 9.5px; font-weight: 700;
  padding: 1px 6px; min-width: 18px; text-align: center;
  border-radius: 2px; line-height: 1.4;
  margin-left: auto;
}
.sb-badge.is-danger  { background: var(--danger-color);  color: #fff; }
.sb-badge.is-warning { background: var(--warning-color); color: #5c4a08; }
.sb-badge.is-primary { background: var(--primary-color); color: #fff; }
```

### Módulos colapsables

Un módulo con submenú se compone de `.sb-collapsible-header` (fila que envuelve el `.sb-item` y el botón chevron) más un `<div class="collapse">` que contiene el `<ul class="sb-submenu">`. El colapso lo maneja Bootstrap collapse vía `data-bs-toggle`/`data-bs-target` y los `id` deben coincidir con `aria-controls`.

```css
.sb-collapsible-header { display: flex; align-items: stretch; }
.sb-collapsible-header > .sb-item { flex: 1 1 0; min-width: 0; }

.sb-chevron {
  flex-shrink: 0; width: 32px; padding: 0;
  background: transparent; border: 0; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,0.35);
}
.sb-chevron:hover { color: rgba(255,255,255,0.75); }
.sb-chevron .bi { font-size: 11px; transition: transform 0.2s ease; }
.sb-chevron[aria-expanded="true"] .bi { transform: rotate(180deg); }

.sb-submenu {
  display: flex; flex-direction: column;
  padding: 0; margin: 0; list-style: none;
}
.sb-submenu .sb-item {
  padding-left: 38px;
  font-size: 12px;
  color: rgba(255,255,255,0.55);
}
.sb-submenu .sb-item .ic { color: rgba(255,255,255,0.4); }
.sb-submenu .sb-item.hover  { color: rgba(255,255,255,0.9); }
.sb-submenu .sb-item.active { color: #fff; }
.sb-submenu .sb-item.active .ic { color: var(--primary-color); }
```

El chevron rota 180° cuando `aria-expanded="true"`. Los `.sb-item` dentro de `.sb-submenu` reciben indentación (38px) y fuente/color más tenues automáticamente — no llevan clases extra.

```html
<li>
  <div class="sb-collapsible-header">
    <a href="/invoices/all" class="sb-item active">
      <span class="ic"><i class="bi bi-receipt-cutoff"></i></span>
      <span class="grow">Todas las Facturas</span>
    </a>
    <button class="sb-chevron" data-bs-toggle="collapse" data-bs-target="#facturacion-submenu"
            aria-expanded="true" aria-controls="facturacion-submenu">
      <i class="bi bi-chevron-down"></i>
    </button>
  </div>
  <div class="collapse show" id="facturacion-submenu">
    <ul class="sb-submenu">
      <li>
        <a href="/invoices" class="sb-item">
          <span class="ic"><i class="bi bi-receipt"></i></span>
          <span class="grow">Mis Facturas</span>
          <span class="sb-badge is-primary">5</span>
        </a>
      </li>
    </ul>
  </div>
</li>
```

### Footer de usuario

Ancla al fondo del sidebar (`margin-top: auto`). Avatar de iniciales con la clase canónica `.av av-sm` (24×24), identidad recortada con ellipsis y botón de logout.

```css
.sb-footer {
  margin-top: auto;
  padding: 12px 16px;
  background: rgba(255,255,255,0.04);
  display: flex; align-items: center; gap: 10px;
}
.sb-footer-identity { flex: 1; min-width: 0; }
.sb-footer-name {
  font-size: 12px; font-weight: 600; color: #fff;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sb-footer-role { font-size: 10px; color: rgba(255,255,255,0.5); }

.sb-logout {
  flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  padding: 4px; line-height: 1;
  color: rgba(255,255,255,0.5);
  background: transparent; border: 0;
}
.sb-logout:hover { color: #fff; background: rgba(255,255,255,0.08); }
```

```html
<div class="sb-footer">
  <div class="av av-sm" style="background-color:var(--primary-color)">AC</div>
  <div class="sb-footer-identity">
    <div class="sb-footer-name">Alexander Caicedo</div>
    <div class="sb-footer-role">Administrador</div>
  </div>
  <a href="/users/logout" class="sb-logout" aria-label="Cerrar sesión">
    <i class="bi bi-box-arrow-right"></i>
  </a>
</div>
```

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

