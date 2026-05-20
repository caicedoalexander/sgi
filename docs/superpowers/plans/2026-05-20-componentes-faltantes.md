# Integración de Componentes Faltantes — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrar los 17 componentes de `Componentes Faltantes.html` (Claude Design) al sistema de diseño SGI — documentación en `docs/design/` + CSS real en `webroot/css/components.css`.

**Architecture:** Se descarga el bundle de Claude Design y se deja el archivo de origen en una ruta estable. Cada tarea lee del origen el CSS y el HTML de sus componentes, normaliza los literales a tokens SGI, y escribe (a) la sección de documentación en el `.md` destino y (b) el CSS en `components.css`. Se crea un archivo nuevo `docs/design/overlays.md`; el resto se reparte en archivos existentes.

**Tech Stack:** Markdown, CSS, bash (WebFetch, tar), git. Sin código de aplicación, sin JS.

**Spec:** `docs/superpowers/specs/2026-05-20-componentes-faltantes-design.md`

---

## Notas de ejecución

- Todos los comandos se ejecutan desde la raíz del repo: `C:\Users\sistema\Documents\sgi`.
- El proyecto **no usa tests automatizados** (ver `CLAUDE.md` → Testing Policy). Verificación por `grep` y revisión, descrita en cada tarea.
- **Alcance:** documentación + CSS. NO se cablean componentes a vistas PHP ni se escribe JS. NO se toca `webroot/css/styles.css`.

### Archivo de origen — staging

El archivo de origen es `Componentes Faltantes.html` dentro del bundle de Claude Design. La **Tarea 1** lo descarga y lo deja en `/tmp/cf-src/`. Si una tarea posterior encuentra que `/tmp/cf-src/Componentes Faltantes.html` no existe, debe re-ejecutar los pasos de la Tarea 1 antes de continuar.

### Mapa de normalización de tokens

Al copiar el CSS del origen, sustituir cada literal de la izquierda por el token de la derecha. **Solo sustituir en match exacto.** Literales sin token (`rgba(70,157,97,0.04|0.08|0.10)`, `rgba(33,37,41,0.55|0.45)` del backdrop, `#f4f4f4`) se **conservan**. `#fff` se conserva. `var(--font-mono)` ya viene en el origen.

| Literal | Token | Literal | Token |
|---|---|---|---|
| `#469D61` | `var(--primary-color)` | `#ececec` | `var(--rule)` |
| `#3a8752` | `var(--primary-color-hover)` | `#111` | `var(--text-strong)` |
| `#CD6A15` | `var(--secondary-color)` | `#222` | `var(--text-default)` |
| `#212529` | `var(--bg-dark)` | `#555` | `var(--text-muted)` |
| `#dc3545` | `var(--danger-color)` | `#888` | `var(--text-faint)` |
| `#ffc107` | `var(--warning-color)` | `#aaa` | `var(--text-disabled)` |
| `#0dcaf0` | `var(--info-color)` | `#ccc` | `var(--border-faint)` |
| `#8a6d08` | `var(--warning-text)` | `rgba(70,157,97,0.12)` | `var(--primary-soft)` |
| `#087990` | `var(--info-text)` | `rgba(70,157,97,0.18)` | `var(--primary-soft-strong)` |
| `#f5f5f5` | `var(--background-color)` | `rgba(205,106,21,0.14)` | `var(--secondary-soft)` |
| `#f8f9fa` | `var(--bg-subtle)` | `rgba(220,53,69,0.12)` | `var(--danger-soft)` |
| `#fafafa` | `var(--bg-muted)` | `rgba(255,193,7,0.20)` | `var(--warning-soft)` |
| `#e0e0e0` | `var(--border-color)` | `rgba(13,202,240,0.16)` | `var(--info-soft)` |

### Conflictos de nombres (aplicar SIEMPRE al copiar)

- El chip removible dentro de un campo (componente B4) usa `.chip`/`.chip-close` en el origen → renombrar a **`.field-chip`** / **`.field-chip-close`** en TODO el CSS y HTML copiado. (El `.chip` de filtro existente no se toca.)
- El control segmentado del origen (`.segmented`/`.segmented-opt`) **no se copia** — el sistema ya tiene `.segmented`/`.seg`.

### Formato de cada sección de documentación

Cada componente documentado en un `.md` sigue este formato (igual que los componentes existentes en `docs/design/`):

````markdown
## <Nombre del componente>

<descripción de 1-2 líneas, español formal, tono operativo>

```css
<CSS normalizado>
```

```html
<ejemplo de marcado>
```

<reglas de uso en viñetas, si el origen las menciona>
````

Los componentes nuevos usan headings `##` con nombre descriptivo (no se les fuerza un número de la numeración 01-16 heredada). El bloque ` ```css ` del doc y el CSS añadido a `components.css` son **el mismo CSS normalizado** — normalizar una vez, escribir en los dos sitios.

### Inventario de componentes (origen → destino)

| Cód | Componente | Clases CSS (en origen) | Doc destino | HTML aprox. (origen) |
|---|---|---|---|---|
| B1 | Toasts | `.toast`, `.toast-*` (líneas ~38-77) | `overlays.md` | ~860-877 |
| B2 | Centro de notificaciones | `.notif`, `.notif-*` (~80-145) | `overlays.md` | ~956-1031 |
| B3 | Select / Dropdown | `.menu`, `.menu-*`, `.trigger`, `.float-stack` (~148-219) | `overlays.md` | ~1085-1120 |
| B4 | Multi-select con chips | reusa B3 + `.field-chip`/`.field-chip-close` (renombrados de `.chip`) | `overlays.md` | ~1204-1261 |
| B5 | Menú de acciones (kebab) | reusa `.menu` (sin CSS nuevo) | `overlays.md` | ~1297-1340 |
| B6 | Menú de usuario | reusa `.menu` + `.user-head`, `.user-head-*` (~222-228) | `overlays.md` | ~1351-1408 |
| C1 | Modal / Dialog | `.modal-stage`, `.modal`, `.modal-*` (~348-372) | `overlays.md` | ~1651-1677 |
| C2 | Drawer lateral | `.drawer-stage`, `.drawer`, `.drawer-*` (~375-402) | `overlays.md` | ~1729-1758 |
| C3 | Tooltip | `.tip`, `.tip-*`, `.tt-anchor`, `.tt-stage` (~405-427) | `overlays.md` | ~1783-1816 |
| C4 | Banner inline | `.banner`, `.banner-*` (~430-447) | `overlays.md` | ~1839-1847 |
| C5 | Command palette ⌘K | `.cmdk`, `.cmdk-*` (~558-603) | `overlays.md` | ~1906-1953 |
| B7 | Chat de observaciones | `.chat`, `.chat-*`, `.tag-*` (~233-345) | `layout-tablas.md` | ~1463-1558 |
| D3 | Paginación | `.pgn`, `.pgn-*` (~517-530) | `layout-tablas.md` | ~2176-2187 |
| D5 | Accordion | `.acc`, `.acc-*` (~606-620) | `layout-tablas.md` | ~2264-2277 |
| D1 | Switch / Toggle | `.switch`, `.switch-row`, `.switch-row-*` (~449-470) | `formularios.md` | (snippet) |
| D1 | Radio group | `.radio-row`, `.radio-dot` (~488-500) | `formularios.md` | (snippet) |
| D1 | Segmented | — (reusa `.segmented`/`.seg` existente) | `formularios.md` | (solo nota) |
| D4 | Stepper / Wizard | `.stepper`, `.step`, `.step-*` (~533-555) | `navegacion.md` | ~2207-2239 |
| D2 | Skeleton loaders | `.sk` + `@keyframes shimmer` (~503-514) | `documental-vacios.md` | (snippet) |

Los números de línea son **aproximados** (del reporte de exploración) — localizar cada componente por sus nombres de clase, no por número de línea.

---

## Task 1: Descargar y dejar disponible el archivo de origen

**Files:**
- Create (temporal, fuera del repo): `/tmp/cf-src/Componentes Faltantes.html` y los dos CSS del bundle.

- [ ] **Step 1: Descargar el bundle de Claude Design**

Usar la herramienta WebFetch sobre la URL (dominio `api.anthropic.com` permitido):
`https://api.anthropic.com/v1/design/h/Il15Ecnq5XcRv8vi2WuJvw?open_file=Componentes+Faltantes.html`

La respuesta es un archivo comprimido (tar gzip). WebFetch guarda el cuerpo binario en un `.bin` bajo un directorio `tool-results` — anotar esa ruta.

- [ ] **Step 2: Extraer y dejar el origen en `/tmp/cf-src/`**

```bash
mkdir -p /tmp/cf-bundle /tmp/cf-src
tar -xzf "<ruta del .bin de WebFetch>" -C /tmp/cf-bundle || tar -xf "<ruta del .bin de WebFetch>" -C /tmp/cf-bundle
# Localizar la carpeta project/ extraída y copiar los 3 archivos necesarios:
find /tmp/cf-bundle -name 'Componentes Faltantes.html' -exec cp {} /tmp/cf-src/ \;
find /tmp/cf-bundle -name 'design-system.css'          -exec cp {} /tmp/cf-src/ \;
find /tmp/cf-bundle -name 'colors_and_type.css'        -exec cp {} /tmp/cf-src/ \;
ls -la /tmp/cf-src/
```

Expected: `/tmp/cf-src/` contiene `Componentes Faltantes.html`, `design-system.css`, `colors_and_type.css`.

- [ ] **Step 3: Verificar el contenido del origen**

```bash
grep -c '\.toast\|\.modal\|\.cmdk\|\.notif\|@keyframes shimmer' "/tmp/cf-src/Componentes Faltantes.html"
```

Expected: un número > 0 (el archivo contiene las clases de los componentes). No se commitea nada en esta tarea — el origen vive solo en `/tmp/`.

---

## Task 2: Crear `docs/design/overlays.md` con los componentes B1–B6 + su CSS

**Files:**
- Create: `docs/design/overlays.md`
- Modify: `webroot/css/components.css` (añadir sección nueva al final)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen**

```bash
ls "/tmp/cf-src/Componentes Faltantes.html" || echo "FALTA — re-ejecutar Task 1"
```
Si falta, re-ejecutar Task 1 Steps 1-2.

- [ ] **Step 2: Crear `docs/design/overlays.md` con el encabezado de 6 líneas**

```bash
cat > docs/design/overlays.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Capa flotante (overlays)

Componentes que flotan sobre la página: toasts, banner, modal, drawer, tooltip, command palette y la familia de menús (select, kebab, usuario, notificaciones).

---

EOF
```

- [ ] **Step 3: Documentar B1–B6 en `overlays.md`**

Leer `Componentes Faltantes.html`. Para cada componente B1–B6, localizar su CSS por nombres de clase, **normalizarlo** con el mapa de tokens del header del plan, aplicar el renombrado de conflictos, y añadir una sección al final de `overlays.md` con el formato estándar (descripción + ` ```css ` + ` ```html `). Componentes y orden: B1 Toasts, B2 Centro de notificaciones, B3 Select / Dropdown, B4 Multi-select con chips, B5 Menú de acciones (kebab), B6 Menú de usuario.

**Ejemplo completo y resuelto — B1 Toasts** (así debe quedar la sección; los demás siguen el mismo patrón):

````markdown
## Toasts

Notificaciones flotantes para confirmaciones de guardado, errores de red y avisos. Esquina inferior derecha, auto-cierre.

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

- Máximo 3 apilados en la esquina inferior derecha, gap 8px; el resto encola.
- Auto-cierre a los 5s (8s si tiene acción). Variante `danger`: sin barra de progreso, cierre manual.
````

Para los SVG inline del origen, sustituirlos por iconos Bootstrap Icons (`<i class="bi bi-*">`) equivalentes — el sistema SGI usa Bootstrap Icons (ver `docs/design/fundamentos.md` §04). Mantener el resto del marcado y todas las clases idénticos al origen.

- [ ] **Step 4: Añadir el CSS de B1–B6 a `components.css`**

Al final de `webroot/css/components.css`, añadir la cabecera de sección y el CSS normalizado de B1–B6 (el **mismo** CSS de los bloques ` ```css ` del Step 3, sin las cercas markdown):

```css

/* =================================================================
   Componentes v1.1 — capa flotante y controles
   Integración de "Componentes Faltantes" (Claude Design).
   Doc: docs/design/overlays.md, formularios.md, layout-tablas.md,
        navegacion.md, documental-vacios.md
   ================================================================= */

/* --- Toasts --- */
<CSS normalizado de .toast (ver Step 3) >

/* --- Centro de notificaciones --- */
<CSS normalizado de .notif* >

/* --- Select / Dropdown (.menu, .trigger) --- */
<CSS normalizado de .menu*, .trigger, .float-stack >

/* --- Multi-select (.field-chip) --- */
<CSS normalizado de .field-chip, .field-chip-close — renombrados de .chip/.chip-close >

/* --- Menú de usuario --- */
<CSS normalizado de .user-head* >
```

(B5 kebab no aporta CSS — solo reusa `.menu`.)

- [ ] **Step 5: Verificar**

```bash
echo "=== clases en overlays.md ===" && grep -oE '\.(toast|notif|menu|trigger|field-chip|user-head)[a-z-]*' docs/design/overlays.md | sort -u | head -40
echo "=== clases en components.css ===" && grep -oE '\.(toast|notif|menu|trigger|field-chip|user-head)[a-z-]*' webroot/css/components.css | sort -u | head -40
echo "=== literales sin normalizar (debe estar vacío) ===" && grep -nE '#469D61|#3a8752|#CD6A15|#212529|#dc3545|#ffc107|#0dcaf0|#8a6d08|#087990|#f5f5f5|#f8f9fa|#fafafa|#e0e0e0|#ececec|#(111|222|555|888|aaa|ccc)\b' docs/design/overlays.md
echo "=== chip viejo no debe aparecer en overlays ===" && grep -nE '\.chip\b|\.chip-close' docs/design/overlays.md
```

Expected: las clases aparecen en ambos archivos; el grep de literales sin normalizar está **vacío**; el grep de `.chip`/`.chip-close` está **vacío** (se usa `.field-chip`).

- [ ] **Step 6: Commit**

```bash
git add docs/design/overlays.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): overlays.md — toasts, notificaciones y menús (B1-B6)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Añadir C1–C5 a `overlays.md` + su CSS

**Files:**
- Modify: `docs/design/overlays.md` (append)
- Modify: `webroot/css/components.css` (append)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen** — `ls "/tmp/cf-src/Componentes Faltantes.html"` (si falta, re-ejecutar Task 1).

- [ ] **Step 2: Documentar C1–C5 al final de `overlays.md`**

Mismo patrón que Task 2 Step 3 (formato estándar, CSS normalizado con el mapa de tokens, SVG → Bootstrap Icons). Componentes y orden: C1 Modal / Dialog, C2 Drawer lateral, C3 Tooltip, C4 Banner inline, C5 Command palette ⌘K.

Notas por componente:
- **C1 Modal:** clases `.modal-stage` (backdrop), `.modal`, `.modal-head/-icon/-body/-title/-desc/-foot`. El backdrop `rgba(33,37,41,0.55)` **se conserva** (no hay token). Variante `.modal.danger`.
- **C2 Drawer:** `.drawer-stage` (backdrop `rgba(33,37,41,0.45)` se conserva), `.drawer`, `.drawer-head*`, `.drawer-row`.
- **C3 Tooltip:** `.tip` (+ `.top/.bottom/.left/.right` con `::after` flecha), `.tip-rich*`, `.tt-anchor`, `.tt-stage`. El `#212529` del fondo → `var(--bg-dark)`.
- **C4 Banner:** `.banner` (+ `.warning/.danger/.info`), `.banner-icon`, `.banner-body/-title/-msg`.
- **C5 Command palette:** `.cmdk-stage` (backdrop se conserva), `.cmdk`, `.cmdk-input/-list/-section/-item/-foot`, `.cmdk-foot kbd`.

- [ ] **Step 3: Añadir el CSS de C1–C5 a `components.css`**

Al final de `webroot/css/components.css`, bajo la sección "Componentes v1.1" ya creada en Task 2, con sub-comentarios `/* --- Modal --- */` etc., el CSS normalizado de C1–C5.

- [ ] **Step 4: Verificar**

```bash
echo "=== clases ===" && grep -oE '\.(modal|drawer|tip|banner|cmdk)[a-z-]*' docs/design/overlays.md | sort -u | head -50
grep -oE '\.(modal|drawer|tip|banner|cmdk)[a-z-]*' webroot/css/components.css | sort -u | head -50
echo "=== literales sin normalizar (debe estar vacío) ===" && grep -nE '#469D61|#3a8752|#CD6A15|#212529|#dc3545|#ffc107|#0dcaf0|#8a6d08|#087990|#f5f5f5|#f8f9fa|#fafafa|#e0e0e0|#ececec|#(111|222|555|888|aaa|ccc)\b' docs/design/overlays.md
echo "=== backdrop conservado (debe aparecer) ===" && grep -c 'rgba(33,37,41' docs/design/overlays.md
```

Expected: clases en ambos archivos; literales sin normalizar **vacío**; `rgba(33,37,41,...)` presente (backdrop conservado a propósito).

- [ ] **Step 5: Commit**

```bash
git add docs/design/overlays.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): overlays.md — modal, drawer, tooltip, banner, command palette (C1-C5)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Añadir D1 (switch, radio) a `formularios.md` + su CSS

**Files:**
- Modify: `docs/design/formularios.md` (append)
- Modify: `webroot/css/components.css` (append)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen** — `ls "/tmp/cf-src/Componentes Faltantes.html"`.

- [ ] **Step 2: Documentar Switch y Radio group al final de `formularios.md`**

Mismo formato estándar. Dos componentes:
- **Switch / Toggle:** clases `.switch` (+ `.switch.on`, `.switch.disabled`, `::after`), `.switch-row`, `.switch-row-body/-title/-sub`. CSS normalizado con el mapa.
- **Radio group:** clases `.radio-row`, `.radio-dot` (+ `.radio-dot.on`, `::after`).

Tras esos dos, añadir una sección breve **sin CSS nuevo**:

````markdown
## Segmented

Para alternar entre 2–4 opciones cortas exclusivas, usar el componente `.segmented` / `.seg` ya documentado en este archivo (sección "08 · Tabs y filtros"). El "Segmented" de la propuesta v1.1 es funcionalmente idéntico — no se introduce una clase nueva.
````

- [ ] **Step 3: Añadir el CSS de Switch y Radio a `components.css`**

Al final de la sección "Componentes v1.1", con sub-comentarios `/* --- Switch / Toggle --- */` y `/* --- Radio group --- */`, el CSS normalizado.

- [ ] **Step 4: Verificar**

```bash
grep -oE '\.(switch|radio)[a-z-]*' docs/design/formularios.md | sort -u
grep -oE '\.(switch|radio)[a-z-]*' webroot/css/components.css | sort -u
echo "=== literales sin normalizar (debe estar vacío) ===" && grep -nE '#469D61|#dc3545|#ccc|#e0e0e0|#ececec|#(111|222|555|888|aaa)\b' docs/design/formularios.md
echo "=== no debe introducir .segmented-opt ===" && grep -nE '\.segmented-opt' docs/design/formularios.md webroot/css/components.css
```

Expected: clases `switch`/`radio` en ambos archivos; sin literales sin normalizar **en las secciones nuevas** (revisar que los hits del grep sean solo de secciones nuevas — `formularios.md` ya tenía contenido; los literales preexistentes en secciones viejas no cuentan, pero las secciones nuevas Switch/Radio deben estar limpias); `.segmented-opt` **vacío**.

- [ ] **Step 5: Commit**

```bash
git add docs/design/formularios.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): formularios.md — switch, radio group (D1)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Añadir Paginación, Accordion y Chat a `layout-tablas.md` + su CSS

**Files:**
- Modify: `docs/design/layout-tablas.md` (append)
- Modify: `webroot/css/components.css` (append)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen** — `ls "/tmp/cf-src/Componentes Faltantes.html"`.

- [ ] **Step 2: Documentar Paginación, Accordion y Chat de observaciones al final de `layout-tablas.md`**

Formato estándar, CSS normalizado, SVG → Bootstrap Icons. Tres componentes:
- **Paginación:** clases `.pgn`, `.pgn-btn` (+ `.active`, `.disabled`), `.pgn-ellipsis`. Añadir nota: *"La app SGI usa 15 ítems por página fijo (ver `CLAUDE.md`); el selector de tamaño de página queda como componente disponible pero no cableado."*
- **Accordion / collapsible:** clases `.acc`, `.acc-row` (+ `.open`), `.acc-head`, `.acc-chev`, `.acc-title`, `.acc-meta`, `.acc-body`.
- **Chat de observaciones:** componente grande — clases `.chat`, `.chat-head*`, `.chat-filters/-filter`, `.chat-list`, `.chat-item` (+ `.reply`), `.chat-av`, `.chat-body`, `.chat-meta*`, `.chat-text` (+ `.mention`), `.chat-attach*`, `.chat-actions`, `.chat-sys*`, `.chat-composer*`, y las etiquetas `.tag-primary/-warning/-danger/-muted`.

- [ ] **Step 3: Añadir el CSS de los tres a `components.css`**

Al final de la sección "Componentes v1.1", sub-comentarios `/* --- Paginación --- */`, `/* --- Accordion --- */`, `/* --- Chat de observaciones --- */`, CSS normalizado.

- [ ] **Step 4: Verificar**

```bash
grep -oE '\.(pgn|acc|chat|tag)[a-z-]*' docs/design/layout-tablas.md | sort -u | head -50
grep -oE '\.(pgn|acc|chat|tag)[a-z-]*' webroot/css/components.css | sort -u | head -50
echo "=== literales sin normalizar en secciones nuevas ===" && grep -nE '#469D61|#212529|#dc3545|#ffc107|#ececec|#e0e0e0|#(111|222|555|888|aaa)\b' docs/design/layout-tablas.md
```

Expected: clases en ambos archivos. Para el grep de literales: revisar que cualquier hit sea de secciones preexistentes de `layout-tablas.md`, NO de las secciones nuevas (Paginación/Accordion/Chat).

- [ ] **Step 5: Commit**

```bash
git add docs/design/layout-tablas.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): layout-tablas.md — paginación, accordion, chat de observaciones (D3, D5, B7)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Añadir Stepper a `navegacion.md` + su CSS

**Files:**
- Modify: `docs/design/navegacion.md` (append)
- Modify: `webroot/css/components.css` (append)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen** — `ls "/tmp/cf-src/Componentes Faltantes.html"`.

- [ ] **Step 2: Documentar Stepper / Wizard al final de `navegacion.md`**

Formato estándar. Clases: `.stepper`, `.step` (+ `.done`, `.active`, `.future`), `.step-num`, `.step-label`, `.step-label-sub`, `.step-line` (+ `.done`). CSS normalizado. Añadir nota: *"El stepper es un asistente paso-a-paso activo, distinto del pipeline de solo lectura (ver sección 14 · Pipeline). El círculo activo lleva un halo `outline: 3px solid var(--primary-soft-strong)`."*

- [ ] **Step 3: Añadir el CSS del Stepper a `components.css`** — al final de la sección "Componentes v1.1", sub-comentario `/* --- Stepper / Wizard --- */`.

- [ ] **Step 4: Verificar**

```bash
grep -oE '\.(stepper|step)[a-z-]*' docs/design/navegacion.md | sort -u
grep -oE '\.(stepper|step)[a-z-]*' webroot/css/components.css | sort -u
echo "=== literales sin normalizar en sección nueva ===" && grep -nE '#469D61|#e0e0e0|#f8f9fa|#(111|222|888|aaa)\b' docs/design/navegacion.md
```

Expected: clases `stepper`/`step` en ambos; literales — los hits deben ser solo de secciones preexistentes de `navegacion.md`, no de la sección Stepper nueva.

- [ ] **Step 5: Commit**

```bash
git add docs/design/navegacion.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): navegacion.md — stepper / wizard (D4)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Añadir Skeleton loaders a `documental-vacios.md` + CSS + keyframes

**Files:**
- Modify: `docs/design/documental-vacios.md` (append)
- Modify: `webroot/css/components.css` (append)
- Read: `/tmp/cf-src/Componentes Faltantes.html`

- [ ] **Step 1: Verificar el origen** — `ls "/tmp/cf-src/Componentes Faltantes.html"`.

- [ ] **Step 2: Documentar Skeleton loaders al final de `documental-vacios.md`**

Formato estándar. Incluye el `@keyframes shimmer` y la clase `.sk`. CSS exacto (el origen ya usa `#ececec`/`#f6f6f6` — `#ececec` → `var(--rule)`; `#f6f6f6` no tiene token, se conserva):

````markdown
## Skeleton loaders

Barras grises animadas que ocupan el lugar del contenido mientras carga una tabla, card o lista. Las barras `.sk` imitan la forma del contenido final.

```css
@keyframes shimmer {
  0% { background-position: -200px 0; }
  100% { background-position: calc(200px + 100%) 0; }
}
.sk {
  background: linear-gradient(90deg, var(--rule) 0%, #f6f6f6 50%, var(--rule) 100%);
  background-size: 200px 100%;
  background-repeat: no-repeat;
  border-radius: 2px;
  display: block;
  animation: shimmer 1.4s infinite linear;
}
```

```html
<span class="sk" style="height:11px; width:90%"></span>
<span class="sk" style="width:28px; height:28px; flex-shrink:0; border-radius:3px"></span>
```

- Componer varias barras `.sk` con grid/flex para imitar el patrón final (tabla, card, lista con avatar).
````

- [ ] **Step 3: Añadir `@keyframes shimmer` + `.sk` a `components.css`**

Al final de la sección "Componentes v1.1", sub-comentario `/* --- Skeleton loaders --- */`, con el mismo CSS del Step 2 (keyframes incluido — es su único consumidor).

- [ ] **Step 4: Verificar**

```bash
grep -n 'shimmer\|\.sk ' docs/design/documental-vacios.md webroot/css/components.css
echo "=== keyframes presente en components.css ===" && grep -c '@keyframes shimmer' webroot/css/components.css
```

Expected: `.sk` y `@keyframes shimmer` presentes en ambos archivos; el `@keyframes shimmer` aparece exactamente 1 vez en `components.css`.

- [ ] **Step 5: Commit**

```bash
git add docs/design/documental-vacios.md webroot/css/components.css
git commit -m "$(cat <<'EOF'
docs(design): documental-vacios.md — skeleton loaders (D2)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Añadir `overlays.md` al índice de `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (tabla `## Sistema de Diseño`)

- [ ] **Step 1: Añadir la 8.ª fila a la tabla**

Edit en `CLAUDE.md` — old_string:
```
| `docs/design/documental-vacios.md` | Gestión documental, empty states |

Antes de crear o editar cualquier vista, lee siempre `reglas-copy.md` + `fundamentos.md`, luego el archivo del componente concreto.
```

new_string:
```
| `docs/design/documental-vacios.md` | Gestión documental, empty states, skeleton loaders |
| `docs/design/overlays.md` | Capa flotante: toasts, banner, modal, drawer, tooltip, command palette, menús (select, kebab, usuario, notificaciones) |

Antes de crear o editar cualquier vista, lee siempre `reglas-copy.md` + `fundamentos.md`, luego el archivo del componente concreto.
```

(La fila de `documental-vacios.md` se actualiza para mencionar los skeleton loaders añadidos en Task 7.)

- [ ] **Step 2: Verificar**

```bash
grep -n 'overlays.md\|documental-vacios.md' CLAUDE.md
```

Expected: `overlays.md` aparece como fila de la tabla; la fila de `documental-vacios.md` menciona "skeleton loaders".

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs(design): añadir overlays.md al índice del sistema de diseño

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Validación manual final (criterios del spec)

Tras completar las 8 tareas:

1. **Cobertura:** `docs/design/overlays.md` existe con 11 componentes (B1–B6, C1–C5); `formularios.md` tiene Switch + Radio + nota de Segmented; `layout-tablas.md` tiene Paginación + Accordion + Chat; `navegacion.md` tiene Stepper; `documental-vacios.md` tiene Skeleton loaders.
2. **Índice:** `CLAUDE.md` lista las 8 filas de `docs/design/`.
3. **CSS:** `webroot/css/components.css` tiene la sección "Componentes v1.1" con el CSS de los 17 componentes; ningún literal con token exacto sobrevive en el CSS nuevo; el chip de campo es `.field-chip`; no existe `.segmented-opt`.
4. **Sin regresión:** `grep -n '\.chip\b' webroot/css/components.css` muestra solo el `.chip` de filtro preexistente; `styles.css` sin cambios (`git diff --stat` no lo lista).
5. **Visual:** crear una página HTML de prueba que cargue el CSS en el orden estándar (Bootstrap → Bootstrap Icons → Flatpickr → `styles.css` → `components.css`) con el marcado de ejemplo de toast, modal, menú select, switch y skeleton; abrir en navegador y confirmar que renderizan según el diseño de Claude Design. Esta es una verificación manual del usuario.

## Self-Review (autor del plan)

- **Cobertura del spec:** los 17 componentes están cubiertos — overlays.md (Tasks 2-3: B1-B6, C1-C5), formularios.md (Task 4: D1 switch/radio + nota segmented), layout-tablas.md (Task 5: D3/D5/B7), navegacion.md (Task 6: D4), documental-vacios.md (Task 7: D2). CSS en `components.css` en cada tarea. Índice de `CLAUDE.md` en Task 8. Normalización de tokens y resolución de conflictos (`.field-chip`, sin `.segmented-opt`) están en el header y repetidas en cada verificación.
- **Sin placeholders:** el patrón de cada sección está fijado por el ejemplo completo y resuelto de B1 Toasts (Task 2 Step 3); el mapa de normalización es determinista; el origen real se lee de `/tmp/cf-src/`. Las marcas `<CSS normalizado de ...>` en Task 2 Step 4 refieren al CSS ya producido en el Step 3 de la misma tarea — no son placeholders sino "el mismo CSS, sin las cercas markdown".
- **Consistencia:** los nombres de clase del inventario coinciden con los usados en las verificaciones; `@keyframes shimmer` se añade una sola vez (Task 7); la sección "Componentes v1.1" de `components.css` la crea Task 2 y la extienden las demás.
