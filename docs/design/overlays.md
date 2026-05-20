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
