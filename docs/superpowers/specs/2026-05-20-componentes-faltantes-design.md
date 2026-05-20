# Componentes Faltantes — integración al sistema de diseño · Diseño

**Fecha:** 2026-05-20
**Topic:** Integrar los 17 componentes de `Componentes Faltantes.html` (Claude Design) al sistema de diseño SGI

---

## Problema

El usuario diseñó en Claude Design una propuesta v1.1 (`Componentes Faltantes.html`) con **17 componentes nuevos** que el sistema de diseño SGI no tiene. Hay que traerlos al repo: documentarlos en `docs/design/` y añadir su CSS real a `webroot/css/components.css`, de forma fiel al diseño original.

El archivo de origen es un prototipo HTML/CSS con CSS inline y valores hardcodeados (`#469D61` en vez de `var(--primary-color)`). **No introduce ninguna variable `:root` nueva** — todos los valores mapean a tokens SGI existentes. El único asset CSS genuinamente nuevo es `@keyframes shimmer`.

## Decisión

1. Crear un archivo nuevo `docs/design/overlays.md` (el 8.º) para la **capa flotante** — componentes que flotan sobre la página.
2. Documentar los componentes no-flotantes en los archivos existentes que les corresponden.
3. Añadir el CSS de los 17 componentes a `webroot/css/components.css`, normalizando literales a tokens.
4. Añadir la 8.ª fila (`overlays.md`) al índice `## Sistema de Diseño` de `CLAUDE.md`.
5. **Alcance:** documentación + CSS real. **No** se cablean los componentes a vistas PHP ni se escribe JS de comportamiento (fuera de alcance).

## Ubicación de los 17 componentes

Códigos `B#`/`C#`/`D#` = secciones del archivo de origen.

### `docs/design/overlays.md` — NUEVO (capa flotante, 11 componentes)

| Cód | Componente | Clase(s) CSS base |
|---|---|---|
| B1 | Toasts / notificaciones flotantes | `.toast`, `.toast-*` |
| B2 | Centro de notificaciones | `.notif`, `.notif-*` |
| B3 | Select / Dropdown abierto | `.menu`, `.menu-*`, `.trigger` |
| B4 | Multi-select con chips | `.trigger` (flex-wrap) + `.field-chip` |
| B5 | Menú de acciones (kebab) | `.menu` + `.menu-item` |
| B6 | Menú de usuario | `.menu` + `.user-head`, `.user-head-*` |
| C1 | Modal / Dialog | `.modal-stage`, `.modal`, `.modal-*` |
| C2 | Drawer lateral | `.drawer-stage`, `.drawer`, `.drawer-*` |
| C3 | Tooltip | `.tip`, `.tip-*`, `.tt-anchor` |
| C4 | Banner inline | `.banner`, `.banner-*` |
| C5 | Command palette (⌘K) | `.cmdk`, `.cmdk-*` |

`overlays.md` abre con el encabezado estándar de 6 líneas (igual que los otros archivos) y agrupa los componentes en secciones. El `.menu` se documenta una vez como base; B3/B5/B6 lo reutilizan.

### Archivos existentes (componentes no-flotantes, 6 componentes)

| Archivo | Cód | Componente | Notas |
|---|---|---|---|
| `formularios.md` | D1 | Switch / Toggle | `.switch`, `.switch-row` |
| `formularios.md` | D1 | Radio group | `.radio-row`, `.radio-dot` |
| `formularios.md` | D1 | Segmented | **Reusa** el `.segmented`/`.seg` ya existente — no se documenta clase nueva, solo se referencia |
| `layout-tablas.md` | D3 | Paginación | `.pgn`, `.pgn-*` |
| `layout-tablas.md` | D5 | Accordion / collapsible | `.acc`, `.acc-*` |
| `layout-tablas.md` | B7 | Chat de observaciones | `.chat`, `.chat-*` (componente grande) |
| `navegacion.md` | D4 | Stepper / Wizard | `.stepper`, `.step`, `.step-*` |
| `documental-vacios.md` | D2 | Skeleton loaders | `.sk` + `@keyframes shimmer` |

## Conflictos de nombres — resoluciones

| Conflicto | Resolución |
|---|---|
| `.chip` — el archivo de origen lo usa para el chip removible dentro de un campo multi-select; **ya existe** `.chip` en el sistema (chip de filtro, p. ej. "Todas · 8") | El componente nuevo se renombra a **`.field-chip`** y su botón de cierre a **`.field-chip-close`**. El `.chip` de filtro existente no se toca. |
| `.segmented`/`.segmented-opt` — el origen define un control segmentado que ya existe como `.segmented`/`.seg` | **No** se introduce `.segmented`/`.segmented-opt`. El componente D1 Segmented se documenta indicando que reusa el `.segmented`/`.seg` existente. |
| Paginación con selector de tamaño de página vs. regla SGI de 15/página fijo | Se documenta el componente `.pgn` completo. Se añade una nota: la app usa 15/página fijo; el selector de tamaño queda disponible como componente pero sin cablear. |

## Normalización de tokens

El CSS del archivo de origen usa literales hardcodeados. Regla de normalización al integrar en `components.css`:

> **Sustituir un literal por un token SOLO cuando el valor del token sea exactamente igual.** Donde el archivo use un valor sin token equivalente exacto (p. ej. `rgba(70,157,97,0.04/0.08/0.10)`, `rgba(33,37,41,0.55)` del backdrop, `#f4f4f4`), se conserva el literal. Esto preserva la fidelidad pixel-a-pixel ("idéntico a Claude Design") y usa tokens solo donde el match es real.

Mapa de sustituciones exactas (tokens definidos en `webroot/css/styles.css` / `docs/design/fundamentos.md`):

| Literal | Token |
|---|---|
| `#469D61` | `var(--primary-color)` |
| `#3a8752` | `var(--primary-color-hover)` |
| `#CD6A15` | `var(--secondary-color)` |
| `#212529` | `var(--bg-dark)` |
| `#dc3545` | `var(--danger-color)` |
| `#ffc107` | `var(--warning-color)` |
| `#0dcaf0` | `var(--info-color)` |
| `#8a6d08` | `var(--warning-text)` |
| `#087990` | `var(--info-text)` |
| `#f5f5f5` | `var(--background-color)` |
| `#f8f9fa` | `var(--bg-subtle)` |
| `#fafafa` | `var(--bg-muted)` |
| `#e0e0e0` | `var(--border-color)` |
| `#ececec` | `var(--rule)` |
| `#111` | `var(--text-strong)` |
| `#222` | `var(--text-default)` |
| `#555` | `var(--text-muted)` |
| `#888` | `var(--text-faint)` |
| `#aaa` | `var(--text-disabled)` |
| `#ccc` | `var(--border-faint)` |
| `rgba(70,157,97,0.12)` | `var(--primary-soft)` |
| `rgba(70,157,97,0.18)` | `var(--primary-soft-strong)` |
| `rgba(205,106,21,0.14)` | `var(--secondary-soft)` |
| `rgba(220,53,69,0.12)` | `var(--danger-soft)` |
| `rgba(255,193,7,0.20)` | `var(--warning-soft)` |
| `rgba(13,202,240,0.16)` | `var(--info-soft)` |

`#fff` se mantiene literal (el sistema de diseño ya lo usa así para cards). `var(--font-mono)` ya viene usado en el archivo de origen.

## CSS — destino y organización

- Todo el CSS de los 17 componentes va a `webroot/css/components.css`, en una sección nueva al final del archivo, comentada (p. ej. `/* === Componentes v1.1 — capa flotante y controles === */`), agrupada por componente.
- `@keyframes shimmer` se añade en `components.css` junto al componente `.sk` (es su único consumidor).
- `webroot/css/styles.css` **no se toca**.
- El CSS sigue las reglas del sistema: sin `box-shadow`, esquinas rectas salvo radios documentados, separadores `1px var(--rule)`. Los `border-left: 3px solid {semantic}` (acento de toast/modal/drawer/banner) son consistentes con el patrón de barra de acento 3px ya permitido.

## Documentación — formato

Cada componente documentado en `docs/design/` sigue el formato de los componentes existentes: subtítulo de sección, descripción breve, bloque ` ```css ` con el spec, bloque ` ```html ` con el ejemplo de marcado. Fidelidad: specs y ejemplos idénticos al archivo de origen, con los literales ya normalizados a tokens.

`docs/design/overlays.md` se crea con el encabezado de 6 líneas estándar. `CLAUDE.md` recibe una 8.ª fila en la tabla `## Sistema de Diseño`:

```
| `docs/design/overlays.md` | Toasts, banner, modal, drawer, tooltip, command palette, menús (select, kebab, usuario, notificaciones) |
```

## Criterios de validación manual

Este proyecto no usa tests automatizados. Tras la implementación:

1. **Cobertura:** los 17 componentes están documentados — 11 en `docs/design/overlays.md`, 3 en `formularios.md`, 3 en `layout-tablas.md`, 1 en `navegacion.md`, 1 en `documental-vacios.md`. (Segmented se cuenta como referencia al componente existente, no como clase nueva.)
2. **Índice:** `CLAUDE.md` lista `docs/design/overlays.md` como 8.ª fila; el archivo existe.
3. **CSS:** `components.css` contiene las clases nuevas; ninguna usa un literal que tenga token exacto en el mapa; el chip de campo es `.field-chip` (no `.chip`); no existe `.segmented-opt`.
4. **Sin regresión:** las clases existentes `.chip` (filtro), `.segmented`, `.seg` siguen intactas; `styles.css` sin cambios.
5. **Visual:** abrir en navegador una página de muestra que cargue `components.css` (orden de carga estándar) con el marcado de ejemplo de 4-5 componentes representativos (toast, modal, menú, switch, skeleton) y confirmar que renderizan según el diseño.

## Fuera de alcance

- Cablear los componentes a vistas PHP o escribir JS de comportamiento (auto-dismiss de toasts, apertura de modales, foco del command palette, etc.).
- Leer los 4 transcripts de chat del bundle de Claude Design — la instrucción directa del usuario (docs + CSS, los 17 componentes) ya define la intención.
- Tocar `webroot/css/styles.css` o los tokens `:root`.
- Resolver la propuesta de tamaño de página configurable contra la regla de 15/página (solo se documenta el componente).
