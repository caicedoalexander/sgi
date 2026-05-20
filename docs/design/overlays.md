# Sistema de Diseño SGI · COPCSA — Capa flotante (overlays)

Componentes que flotan sobre la página: toasts, banner, modal, drawer, tooltip, command palette y la familia de menús (select, kebab, usuario, notificaciones).

---

## Toasts

Notificaciones flotantes para confirmaciones de guardado, errores de red y avisos. Esquina inferior derecha, auto-cierre. Franja lateral de 3px en el color semántico.

```css
.toast { display: flex; align-items: flex-start; gap: 12px; background: #fff; padding: 12px 14px; width: 360px; position: relative; border-left: 3px solid var(--primary-color); }
.toast-icon { width: 28px; height: 28px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 3px; background: var(--primary-soft); color: var(--primary-color); }
.toast.success { border-left-color: var(--primary-color); }
.toast.success .toast-icon { background: var(--primary-soft); color: var(--primary-color); }
.toast.danger  { border-left-color: var(--danger-color); }
.toast.danger  .toast-icon { background: var(--danger-soft); color: var(--danger-color); }
.toast.warning { border-left-color: var(--warning-color); }
.toast.warning .toast-icon { background: var(--warning-soft); color: var(--warning-text); }
.toast.info    { border-left-color: var(--info-color); }
.toast.info    .toast-icon { background: var(--info-soft); color: var(--info-text); }
.toast-body { flex: 1; min-width: 0; }
.toast-title { font-size: 12.5px; font-weight: 700; color: var(--text-strong); }
.toast-msg   { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.45; }
.toast-close { background: transparent; border: none; color: var(--text-faint); padding: 4px; cursor: pointer; display: flex; flex-shrink: 0; }
.toast-actions { margin-top: 8px; display: flex; gap: 12px; align-items: center; }
.toast-link { font-size: 11.5px; font-weight: 600; color: var(--primary-color); text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.toast-progress { position: absolute; left: 0; bottom: 0; height: 2px; background: var(--primary-color); }
.toast.danger .toast-progress  { background: var(--danger-color); }
.toast.warning .toast-progress { background: var(--warning-color); }
.toast.info .toast-progress    { background: var(--info-color); }
```

```html
<div class="toast success">
  <div class="toast-icon"><i class="bi bi-check-lg"></i></div>
  <div class="toast-body">
    <div class="toast-title">Factura aprobada</div>
    <div class="toast-msg">FCTG218810 fue aprobada y enviada a contabilidad.</div>
    <div class="toast-actions">
      <a class="toast-link">Ver factura <i class="bi bi-chevron-right"></i></a>
    </div>
  </div>
  <button class="toast-close"><i class="bi bi-x-lg"></i></button>
  <div class="toast-progress" style="width:72%"></div>
</div>
```

Variantes: `success` · `danger` · `warning` · `info` (cambia `.toast-icon` y `.toast-progress`).

- Máximo 3 toasts apilados en la esquina inferior derecha, gap 8px; los excedentes esperan en cola.
- Auto-cierre a los 5s con barra de progreso; 8s si el toast tiene una acción.
- Los `danger` nunca llevan barra de progreso ni auto-cierre — el cierre es manual.

---

## Centro de notificaciones

Panel desplegable del icono de campana en la barra superior. Ancho fijo 380px. Tres pestañas (Todas, Sin leer, Menciones). Las filas no leídas llevan dot primario a la izquierda y fondo soft.

```css
.notif { background: #fff; width: 380px; }
.notif-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--rule); }
.notif-head-title { font-size: 12.5px; font-weight: 700; color: var(--text-strong); display: inline-flex; align-items: center; gap: 8px; }
.notif-head-badge { font-size: 10px; font-weight: 700; background: var(--primary-color); color: #fff; padding: 1px 6px; border-radius: 2px; }
.notif-head-link { font-size: 11px; color: var(--primary-color); font-weight: 600; text-decoration: none; }
.notif-tabs { display: flex; gap: 0; border-bottom: 1px solid var(--rule); padding: 0 16px; }
.notif-tab { padding: 8px 0; font-size: 11.5px; font-weight: 600; color: var(--text-muted); border-bottom: 2px solid transparent; margin-right: 16px; cursor: pointer; }
.notif-tab.active { color: var(--primary-color); border-color: var(--primary-color); }
.notif-tab-count { font-size: 9.5px; font-weight: 700; margin-left: 4px; color: var(--text-faint); }
.notif-tab.active .notif-tab-count { color: var(--primary-color); }
.notif-list { max-height: 320px; overflow-y: auto; }
.notif-row { display: grid; grid-template-columns: 26px 1fr auto; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #f4f4f4; align-items: start; cursor: pointer; position: relative; }
.notif-row.unread { background: rgba(70,157,97,0.04); }
.notif-row.unread::before { content: ''; position: absolute; left: 6px; top: 18px; width: 5px; height: 5px; border-radius: 50%; background: var(--primary-color); }
.notif-row:hover { background: var(--bg-muted); }
.notif-row-icon { width: 26px; height: 26px; border-radius: 3px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.notif-row-icon.success { background: var(--primary-soft); color: var(--primary-color); }
.notif-row-icon.warning { background: var(--warning-soft); color: var(--warning-text); }
.notif-row-icon.danger  { background: var(--danger-soft); color: var(--danger-color); }
.notif-row-icon.muted   { background: var(--bg-subtle); color: var(--text-muted); }
.notif-row-body .t1 { font-size: 12px; color: var(--text-default); line-height: 1.45; }
.notif-row-body .t1 b { color: var(--text-strong); font-weight: 700; }
.notif-row-body .t2 { font-size: 10.5px; color: var(--text-faint); margin-top: 4px; display: flex; align-items: center; gap: 8px; }
.notif-row-meta { font-size: 10px; color: var(--text-faint); font-family: var(--font-mono); white-space: nowrap; }
.notif-foot { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid var(--rule); font-size: 11px; color: var(--text-muted); }
```

```html
<div class="notif">
  <div class="notif-head">
    <div class="notif-head-title">Notificaciones <span class="notif-head-badge">5</span></div>
    <a class="notif-head-link">Marcar todas como leídas</a>
  </div>
  <div class="notif-tabs">
    <div class="notif-tab active">Todas <span class="notif-tab-count">12</span></div>
    <div class="notif-tab">Sin leer <span class="notif-tab-count">5</span></div>
    <div class="notif-tab">Menciones <span class="notif-tab-count">2</span></div>
  </div>
  <div class="notif-list">
    <div class="notif-row unread">
      <div class="notif-row-icon success"><i class="bi bi-check-lg"></i></div>
      <div class="notif-row-body">
        <div class="t1"><b>Carolina Mejía</b> aprobó la factura <b>FCTG218810</b> por $ 4.250.000.</div>
        <div class="t2"><span>Facturas</span> · <span style="font-family:var(--font-mono)">14:32</span></div>
      </div>
      <div class="notif-row-meta">hace 2 m</div>
    </div>
    <div class="notif-row">
      <div class="notif-row-icon warning"><i class="bi bi-clock"></i></div>
      <div class="notif-row-body">
        <div class="t1"><b>3 facturas</b> de Proveedores Cafeteros S.A. vencen mañana.</div>
        <div class="t2"><span>Vencidas</span> · <span style="font-family:var(--font-mono)">13:50</span></div>
      </div>
      <div class="notif-row-meta">hace 44 m</div>
    </div>
  </div>
  <div class="notif-foot">
    <span>Mostrando 5 de 12</span>
    <a class="notif-head-link">Ver todas →</a>
  </div>
</div>
```

El trigger es un botón de campana en la TopBar con un badge de conteo absoluto (reusa `--danger-color`). Variantes de `.notif-row-icon`: `success` · `warning` · `danger` · `muted`. El panel cierra con click fuera, Esc o al navegar a una notificación.

---

## Select / Dropdown

Menú desplegable real (no el `<select>` nativo). El `.trigger` reusa el input del sistema (outline 1px, 2px primary al abrir). El `.menu` se ancla justo debajo. Item activo con fondo soft + texto primary + check derecho. Admite búsqueda (`.menu-search`) y agrupación (`.menu-section`).

```css
.menu { background: #fff; min-width: 240px; padding: 4px 0; }
.menu.wide { min-width: 280px; }
.menu-search { padding: 8px 10px; border-bottom: 1px solid var(--rule); }
.menu-search-input { display: flex; align-items: center; gap: 8px; padding: 6px 8px; background: var(--bg-subtle); border-radius: 3px; font-size: 12px; color: var(--text-muted); }
.menu-search-input input { flex: 1; border: none; background: transparent; outline: none; font-size: 12px; color: var(--text-default); font-family: inherit; min-width: 0; }
.menu-section { padding: 8px 14px 4px; font-size: 9.5px; font-weight: 700; color: var(--text-faint); letter-spacing: 1px; text-transform: uppercase; }
.menu-item { display: flex; align-items: center; gap: 10px; padding: 7px 14px; font-size: 12.5px; color: var(--text-default); cursor: pointer; transition: background 0.12s; }
.menu-item:hover { background: var(--bg-subtle); }
.menu-item.active { background: rgba(70,157,97,0.10); color: var(--primary-color); font-weight: 600; }
.menu-item.active .menu-check { color: var(--primary-color); }
.menu-item-icon { color: var(--text-faint); display: flex; }
.menu-item.active .menu-item-icon { color: var(--primary-color); }
.menu-item-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.menu-item-sub { font-size: 10.5px; color: var(--text-faint); }
.menu-item-meta { font-size: 10px; color: var(--text-faint); font-family: var(--font-mono); }
.menu-item-shortcut { font-family: var(--font-mono); font-size: 10px; color: var(--text-faint); padding: 1px 5px; background: var(--bg-subtle); border-radius: 2px; }
.menu-divider { height: 1px; background: var(--rule); margin: 4px 0; }
.menu-check { width: 14px; flex-shrink: 0; display: flex; color: transparent; }
.menu-item.danger { color: var(--danger-color); }
.menu-item.danger .menu-item-icon { color: var(--danger-color); }
.menu-foot { padding: 8px 14px; border-top: 1px solid var(--rule); font-size: 10.5px; color: var(--text-faint); display: flex; align-items: center; justify-content: space-between; }
.trigger { display: flex; align-items: center; gap: 10px; background: #fff; padding: 8px 12px; min-height: 36px; outline: 1px solid var(--border-color); outline-offset: -1px; font-size: 13px; color: var(--text-default); cursor: pointer; border-radius: 3px; width: 280px; }
.trigger.open { outline: 2px solid var(--primary-color); outline-offset: -2px; }
.trigger .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.float-stack { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.float-stack .trigger-anchor { position: relative; display: inline-block; }
```

```html
<div class="float-stack">
  <div class="trigger open">
    <span class="grow">Gastos Administrativos</span>
    <i class="bi bi-chevron-down" style="transform:rotate(180deg)"></i>
  </div>
  <div class="menu" style="width:280px">
    <div class="menu-item">
      <span class="menu-check"></span>
      <span class="menu-item-label">Compras y Suministros</span>
    </div>
    <div class="menu-item active">
      <span class="menu-check"><i class="bi bi-check-lg"></i></span>
      <span class="menu-item-label">Gastos Administrativos</span>
    </div>
    <div class="menu-item">
      <span class="menu-check"></span>
      <span class="menu-item-label">Mantenimiento</span>
    </div>
    <div class="menu-foot">
      <span>12 opciones</span>
      <span style="font-family:var(--font-mono)">↑↓ navegar</span>
    </div>
  </div>
</div>
```

La variante con búsqueda añade `.menu-search` arriba; aparece automáticamente con más de 8 opciones. Las `.menu-section` agrupan por área o tipo. El chevron rota 180° al abrir. Sin sombra: el menú se diferencia por el outline y por estar sobre el fondo de página.

---

## Multi-select con chips

Selección múltiple con chips removibles dentro del `.trigger`. Reusa `.menu`, `.menu-item` y `.trigger` del Select. Cada chip se cierra individualmente con × sin abrir el menú.

```css
.field-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px 2px 8px; background: var(--primary-soft); color: var(--primary-color); font-size: 11px; font-weight: 600; border-radius: 2px; }
.field-chip-close { background: transparent; border: none; color: var(--primary-color); padding: 0; display: flex; cursor: pointer; }
```

```html
<div class="float-stack">
  <div class="trigger open" style="width:380px; flex-wrap:wrap; gap:6px; padding:6px 10px">
    <span class="field-chip">ADMIN
      <button class="field-chip-close"><i class="bi bi-x-lg"></i></button>
    </span>
    <span class="field-chip">OPERACIONES
      <button class="field-chip-close"><i class="bi bi-x-lg"></i></button>
    </span>
    <span class="field-chip">PORTUARIO
      <button class="field-chip-close"><i class="bi bi-x-lg"></i></button>
    </span>
    <input style="flex:1; min-width:80px; border:none; outline:none; background:transparent; font-size:12.5px; font-family:inherit; padding:2px 4px" placeholder="Añadir…">
    <i class="bi bi-chevron-down" style="transform:rotate(180deg); flex-shrink:0"></i>
  </div>
  <div class="menu" style="width:380px">
    <div class="menu-section">Centros de costos</div>
    <div class="menu-item active">
      <span style="width:14px; height:14px; background:var(--primary-color); outline:1px solid var(--primary-color); outline-offset:-1px; border-radius:2px; display:inline-flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0"><i class="bi bi-check-lg" style="font-size:10px"></i></span>
      <span class="menu-item-label">Administrativo</span>
      <span class="menu-item-meta">ADMIN</span>
    </div>
    <div class="menu-item">
      <span style="width:14px; height:14px; background:#fff; outline:1px solid var(--border-faint); outline-offset:-1px; border-radius:2px; flex-shrink:0"></span>
      <span class="menu-item-label">Mantenimiento</span>
      <span class="menu-item-meta">MTTO</span>
    </div>
    <div class="menu-divider"></div>
    <div class="menu-foot">
      <span><strong style="color:var(--text-default)">3</strong> seleccionados</span>
      <a class="notif-head-link">Limpiar selección</a>
    </div>
  </div>
</div>
```

> **Nota de naming:** este chip removible de campo es `.field-chip` — distinto del `.chip` de filtro documentado en `formularios.md` (sección "08 · Tabs y filtros").

Los items seleccionados usan una casilla (checkbox) en vez del check derecho. Backspace en el input vacío elimina el último chip. Con más de 6 opciones, ordenar los seleccionados primero al abrir el menú.

---

## Menú de acciones (kebab)

El menú contextual del icono ⋯ en filas de tabla o esquinas de card. No aporta CSS propio — es una composición de `.menu` + `.menu-item` + `.menu-section` + `.menu-divider`. Las acciones destructivas van al final, separadas por divider, con `.menu-item.danger`.

```html
<div class="menu">
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-eye"></i></span>
    <span class="menu-item-label">Ver detalle</span>
    <span class="menu-item-shortcut">↵</span>
  </div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-pencil"></i></span>
    <span class="menu-item-label">Editar</span>
    <span class="menu-item-shortcut">E</span>
  </div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-download"></i></span>
    <span class="menu-item-label">Descargar PDF</span>
    <span class="menu-item-shortcut">⌘D</span>
  </div>
  <div class="menu-divider"></div>
  <div class="menu-section" style="padding-top:4px">Flujo</div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-check-lg"></i></span>
    <span class="menu-item-label">Aprobar</span>
  </div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-arrow-left-right"></i></span>
    <span class="menu-item-label">Reasignar…</span>
  </div>
  <div class="menu-divider"></div>
  <div class="menu-item danger">
    <span class="menu-item-icon"><i class="bi bi-x-lg"></i></span>
    <span class="menu-item-label">Rechazar factura</span>
  </div>
</div>
```

El trigger es el icono `bi-three-dots-vertical`. Los atajos se muestran solo en el menú, no en el trigger. Máximo 8 items; si necesitas más, dividir en submenús.

---

## Menú de usuario

Variante de `.menu` con una cabecera de identidad (`.user-head`). Reemplaza el botón de logout suelto del sidebar; el trigger es el avatar del `.sb-footer`.

```css
.user-head { display: flex; align-items: center; gap: 10px; padding: 14px 14px 12px; border-bottom: 1px solid var(--rule); }
.user-head-name { font-size: 12.5px; font-weight: 700; color: var(--text-strong); }
.user-head-sub { font-size: 10.5px; color: var(--text-faint); margin-top: 2px; font-family: var(--font-mono); }
```

```html
<div class="menu" style="width:260px">
  <div class="user-head">
    <span class="av av-md" style="background-color:var(--primary-color)">AC</span>
    <div>
      <div class="user-head-name">Alexander Caicedo</div>
      <div class="user-head-sub">alexander.caicedo@copcsa.co</div>
    </div>
  </div>
  <div class="menu-divider"></div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-person"></i></span>
    <span class="menu-item-label">Mi perfil</span>
  </div>
  <div class="menu-item">
    <span class="menu-item-icon"><i class="bi bi-bell"></i></span>
    <span class="menu-item-label">Notificaciones</span>
    <span class="menu-item-meta">5</span>
  </div>
  <div class="menu-divider"></div>
  <div class="menu-item danger">
    <span class="menu-item-icon"><i class="bi bi-box-arrow-right"></i></span>
    <span class="menu-item-label">Cerrar sesión</span>
    <span class="menu-item-shortcut">⌘Q</span>
  </div>
  <div class="menu-foot" style="font-family:var(--font-mono); justify-content:center">
    v3.4.2 · Buenaventura
  </div>
</div>
```

El avatar de la cabecera usa la clase canónica `.av av-md` (ver `layout-tablas.md` · sección "10 · Avatares").

---

## Modal / Dialog

Diálogo centrado para confirmaciones destructivas y formularios cortos. Ancho 480px (640px para formularios largos). Backdrop semitransparente sobre `--bg-dark`. Franja lateral de 3px en color semántico.

```css
.modal-stage { background: rgba(33,37,41,0.55); padding: 36px; min-height: 360px; display: flex; align-items: center; justify-content: center; }
.modal { background: #fff; width: 100%; max-width: 480px; border-left: 3px solid var(--primary-color); }
.modal.danger { border-left-color: var(--danger-color); }
.modal-head { padding: 18px 22px 0; display: flex; gap: 14px; align-items: flex-start; }
.modal-icon { width: 36px; height: 36px; flex-shrink: 0; border-radius: 3px; display: flex; align-items: center; justify-content: center; background: var(--danger-soft); color: var(--danger-color); }
.modal-body { padding: 6px 22px 18px; }
.modal-title { font-size: 16px; font-weight: 700; color: var(--text-strong); margin-bottom: 6px; }
.modal-desc { font-size: 12.5px; color: var(--text-muted); line-height: 1.55; }
.modal-foot { padding: 14px 22px; background: var(--bg-muted); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
```

```html
<div class="modal-stage">
  <div class="modal danger">
    <div class="modal-head">
      <div class="modal-icon"><i class="bi bi-exclamation-triangle"></i></div>
      <div style="flex:1">
        <div class="modal-title">¿Rechazar esta factura?</div>
        <div class="modal-desc">El proveedor recibirá una notificación automática con el motivo y la factura quedará archivada. Esta acción no se puede deshacer.</div>
      </div>
    </div>
    <div class="modal-body">
      <span class="input-label">Motivo del rechazo</span>
      <div class="input" style="height:auto; padding:10px 12px; align-items:flex-start">
        <textarea placeholder="Explica brevemente al proveedor…" style="border:none;outline:none;width:100%;font-family:inherit;font-size:12.5px;color:var(--text-default);resize:vertical;min-height:64px;background:transparent"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <span class="mono" style="font-size:11px; color:var(--text-faint)">FCTG218810</span>
      <div style="display:flex; gap:8px">
        <button class="btn btn-ghost btn-sm">Cancelar</button>
        <button class="btn btn-danger btn-sm">Sí, rechazar factura</button>
      </div>
    </div>
  </div>
</div>
```

Variante `danger` para decisiones destructivas (franja e icono rojos); sin clase, la franja es primary y el `.modal-icon` se sobrescribe al color que aplique. El `.modal-body` es opcional (confirmaciones simples solo llevan head + foot). Cierra con Esc, click fuera o ✕.

---

## Side drawer

Panel lateral para vista previa rápida sin perder el contexto de la lista. Ancho 440px desde la derecha, backdrop más sutil que el modal (45%).

```css
.drawer-stage { background: rgba(33,37,41,0.45); padding: 0; min-height: 460px; display: flex; justify-content: flex-end; position: relative; overflow: hidden; }
.drawer { width: 440px; background: #fff; display: flex; flex-direction: column; border-left: 3px solid var(--primary-color); }
.drawer-head { padding: 16px 20px; display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 1px solid var(--rule); }
.drawer-head-eyebrow { font-size: 9.5px; font-weight: 700; color: var(--primary-color); letter-spacing: 1.2px; text-transform: uppercase; margin-bottom: 4px; }
.drawer-head-title { font-size: 16px; font-weight: 700; color: var(--text-strong); }
.drawer-head-sub { font-size: 11px; color: var(--text-faint); font-family: var(--font-mono); margin-top: 3px; }
.drawer-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--rule); font-size: 12px; }
.drawer-row span:first-child { color: var(--text-muted); }
.drawer-row span:last-child { color: var(--text-default); font-weight: 600; text-align: right; }
```

```html
<div class="drawer">
  <div class="drawer-head">
    <div>
      <div class="drawer-head-eyebrow">Vista previa · Factura</div>
      <div class="drawer-head-title">Comercializadora Andina S.A.S</div>
      <div class="drawer-head-sub">FCTG218810 · 13/05/2026</div>
    </div>
    <button class="btn-icon"><i class="bi bi-x-lg"></i></button>
  </div>
  <div style="padding:14px 20px; display:flex; gap:6px; flex-wrap:wrap">
    <span class="pill pill-primary-soft">Aprobada</span>
    <span class="pill pill-muted">Operaciones</span>
  </div>
  <div style="padding:6px 20px 12px">
    <div class="drawer-row"><span>Valor total</span><span class="mono">$ 4.250.000</span></div>
    <div class="drawer-row"><span>Vence</span><span style="color:var(--danger-color)">20/05/2026 · en 7 días</span></div>
    <div class="drawer-row"><span>Responsable</span><span>Carolina Mejía</span></div>
  </div>
  <div style="padding:14px 20px; background:var(--bg-muted); margin-top:auto; display:flex; gap:8px; border-top:1px solid var(--rule)">
    <button class="btn btn-ghost btn-sm" style="flex:1">Abrir detalle completo</button>
    <button class="btn btn-primary btn-sm">Editar</button>
  </div>
</div>
```

Slide-in 220ms desde la derecha; la lista detrás permanece visible y atenuada. `.drawer-row` es el patrón `FieldRow` aplicado al drawer. Cierra con Esc o click fuera.

---

## Tooltip

Texto explicativo en hover, fondo oscuro con flecha de 5px. Aparece a 400ms de hover, máximo 240px. La variante `rich` admite título + cuerpo y franja lateral primary para ayuda contextual.

```css
.tt-stage { background: var(--bg-muted); padding: 32px; display: flex; gap: 40px; justify-content: center; align-items: center; flex-wrap: wrap; }
.tt-anchor { position: relative; display: inline-flex; }
.tip { background: var(--bg-dark); color: #fff; padding: 6px 10px; font-size: 11.5px; font-weight: 500; position: relative; max-width: 240px; line-height: 1.4; }
.tip::after { content: ''; position: absolute; width: 0; height: 0; border: 5px solid transparent; }
.tip.top::after    { border-top-color: var(--bg-dark);    top: 100%; left: 50%; transform: translateX(-50%); }
.tip.bottom::after { border-bottom-color: var(--bg-dark); bottom: 100%; left: 50%; transform: translateX(-50%); }
.tip.left::after   { border-left-color: var(--bg-dark);   left: 100%; top: 50%;  transform: translateY(-50%); }
.tip.right::after  { border-right-color: var(--bg-dark);  right: 100%; top: 50%; transform: translateY(-50%); }
.tip-kw { font-family: var(--font-mono); color: var(--text-disabled); margin-left: 6px; }
.tip-rich { background: var(--bg-dark); color: #fff; padding: 10px 12px; max-width: 280px; border-left: 2px solid var(--primary-color); }
.tip-rich-title { font-size: 11.5px; font-weight: 700; margin-bottom: 4px; }
.tip-rich-body  { font-size: 10.5px; color: rgba(255,255,255,0.7); line-height: 1.5; }
```

```html
<!-- Simple, con 4 posiciones (top / bottom / left / right) -->
<div class="tt-anchor">
  <button class="btn btn-ghost btn-sm">Aprobar</button>
  <div class="tip top" style="position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%)">
    Aprobar factura<span class="tip-kw">⌘↵</span>
  </div>
</div>

<!-- Variante rich · ayuda contextual -->
<div class="tt-anchor">
  <span style="display:inline-flex; align-items:center; gap:4px; font-size:12.5px">
    Vía Enlace Externo <i class="bi bi-info-circle" style="color:var(--text-faint)"></i>
  </span>
  <div class="tip-rich" style="position:absolute; top:calc(100% + 8px); left:0">
    <div class="tip-rich-title">¿Qué hace esta opción?</div>
    <div class="tip-rich-body">Permite que aprobadores externos respondan al correo sin iniciar sesión en el sistema.</div>
  </div>
</div>
```

La flecha (`::after`) se posiciona según la clase `top` / `bottom` / `left` / `right`. Sustituye los `title` nativos del navegador.

---

## Banner inline

Aviso a nivel de página o sección. A diferencia del toast **no es flotante**: ocupa todo el ancho del contenedor y permanece hasta que el usuario actúe. Mismo lenguaje semántico (franja lateral + icono soft).

```css
.banner { display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; background: #fff; border-left: 3px solid var(--primary-color); }
.banner.warning { background: rgba(255,193,7,0.10); border-left-color: var(--warning-color); }
.banner.danger  { background: rgba(220,53,69,0.06); border-left-color: var(--danger-color); }
.banner.info    { background: rgba(13,202,240,0.08); border-left-color: var(--info-color); }
.banner-icon { width: 24px; height: 24px; flex-shrink: 0; border-radius: 3px; display: flex; align-items: center; justify-content: center; }
.banner.warning .banner-icon { background: var(--warning-soft); color: var(--warning-text); }
.banner.danger .banner-icon  { background: var(--danger-soft); color: var(--danger-color); }
.banner.info .banner-icon    { background: var(--info-soft); color: var(--info-text); }
.banner-body { flex: 1; min-width: 0; }
.banner-title { font-size: 12.5px; font-weight: 700; color: var(--text-strong); }
.banner-msg { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
```

```html
<div class="banner danger">
  <div class="banner-icon"><i class="bi bi-exclamation-triangle"></i></div>
  <div class="banner-body">
    <div class="banner-title">3 facturas están vencidas hace más de 5 días</div>
    <div class="banner-msg">Requieren respuesta inmediata. <a style="color:var(--danger-color); font-weight:600; text-decoration:none">Ver lista →</a></div>
  </div>
</div>
```

4 niveles: sin clase (success, franja primary) · `warning` · `danger` · `info`. Posición: arriba del contenido principal, debajo de la TopBar. Si hay varios, apilar con gap 8px en orden de severidad. El cierre (×) solo se muestra para warnings e infos no críticos.

---

## Command palette (⌘K)

Panel central de búsqueda + acciones, activado con ⌘K. Busca simultáneamente facturas, empleados, novedades y acciones; resultados agrupados por tipo. Ancho 560px, anclado al tercio superior de la pantalla (no centrado verticalmente).

```css
.cmdk-stage { background: rgba(33,37,41,0.55); padding: 32px; min-height: 420px; display: flex; justify-content: center; align-items: flex-start; }
.cmdk { background: #fff; width: 100%; max-width: 560px; display: flex; flex-direction: column; }
.cmdk-input { padding: 14px 16px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--rule); }
.cmdk-input input { flex: 1; border: none; outline: none; font-size: 15px; color: var(--text-strong); font-family: inherit; background: transparent; }
.cmdk-input input::placeholder { color: var(--text-disabled); }
.cmdk-list { padding: 4px 0; max-height: 320px; overflow-y: auto; }
.cmdk-section { font-size: 9.5px; font-weight: 700; color: var(--text-faint); letter-spacing: 1px; text-transform: uppercase; padding: 10px 16px 4px; }
.cmdk-item { display: flex; align-items: center; gap: 10px; padding: 8px 16px; font-size: 12.5px; color: var(--text-default); cursor: pointer; }
.cmdk-item.active { background: rgba(70,157,97,0.08); }
.cmdk-item.active .cmdk-item-label { color: var(--primary-color); font-weight: 600; }
.cmdk-item-icon { color: var(--text-faint); display: flex; flex-shrink: 0; }
.cmdk-item.active .cmdk-item-icon { color: var(--primary-color); }
.cmdk-item-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmdk-item-meta { font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.cmdk-foot { padding: 8px 16px; border-top: 1px solid var(--rule); display: flex; gap: 14px; font-size: 10.5px; color: var(--text-faint); font-family: var(--font-mono); }
.cmdk-foot kbd { display: inline-block; padding: 1px 5px; background: var(--bg-subtle); color: var(--text-muted); font-family: var(--font-mono); font-size: 10px; border-radius: 2px; margin-right: 4px; }
```

```html
<div class="cmdk">
  <div class="cmdk-input">
    <i class="bi bi-search" style="color:var(--text-faint)"></i>
    <input value="carolina" placeholder="Buscar facturas, empleados, acciones…">
    <span class="mono" style="font-size:10.5px; color:var(--text-faint); padding:3px 6px; background:var(--bg-subtle); border-radius:2px">esc</span>
  </div>
  <div class="cmdk-list">
    <div class="cmdk-section">Empleados · 1</div>
    <div class="cmdk-item active">
      <span class="cmdk-item-icon"><span class="av av-sm" style="background-color:var(--primary-color)">CM</span></span>
      <span class="cmdk-item-label">Carolina Mejía</span>
      <span class="cmdk-item-meta">Jefe Contable</span>
    </div>
    <div class="cmdk-section">Facturas · 1</div>
    <div class="cmdk-item">
      <span class="cmdk-item-icon"><i class="bi bi-file-earmark-text"></i></span>
      <span class="cmdk-item-label">FCTG218810 · aprobada por <b>Carolina</b></span>
      <span class="cmdk-item-meta">$ 4.250.000</span>
    </div>
  </div>
  <div class="cmdk-foot">
    <span><kbd>↑↓</kbd>navegar</span>
    <span><kbd>↵</kbd>abrir</span>
    <span style="margin-left:auto"><kbd>esc</kbd>cerrar</span>
  </div>
</div>
```

Búsqueda fuzzy en nombres, números de factura, NIT y cédula. Sin resultados muestra un empty state sugerido por tipo.
