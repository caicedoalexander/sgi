# Sistema de Diseño SGI · COPCSA — Formularios

Inputs y formularios, tabs y filtros, date & time pickers.

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

