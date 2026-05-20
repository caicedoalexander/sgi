# Sistema de Diseño SGI · COPCSA — Gestión documental y empty states

Gestión documental (códigos de documento, filas, indicadores de completitud) y empty states.

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

