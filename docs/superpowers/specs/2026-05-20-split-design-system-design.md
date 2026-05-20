# Split del sistema de diseño — Diseño

**Fecha:** 2026-05-20
**Topic:** Dividir `.claude/rules/design.md` en archivos temáticos bajo demanda

---

## Problema

`.claude/rules/design.md` (1581 líneas, ~55 KB, ~14k tokens) es el único archivo en
`.claude/rules/`. Esa carpeta se carga **completa en cada sesión** como instrucciones del
proyecto, lo que dispara una alerta de tamaño y consume ~14k tokens de contexto aunque la
tarea no toque la UI.

Dividir el archivo **dentro de** `.claude/rules/` no ahorraría tokens: todos los archivos de
esa carpeta se siguen cargando. El ahorro solo aparece si el contenido deja de cargarse
siempre y pasa a leerse **bajo demanda**.

## Decisión

Mover el contenido fuera de `.claude/rules/` hacia `docs/design/`, dividido en **7 archivos
temáticos**, y dejar un **índice corto en `CLAUDE.md`**. El contenido ya no se carga
automáticamente; Claude lee el/los archivo(s) relevante(s) cuando trabaja en una vista.

- **Impacto en tokens:** de ~14k siempre cargados → ~400 (solo el índice en `CLAUDE.md`).
  Al trabajar en una vista se leen 2-3 archivos relevantes (~3-5k) en vez de los 14k.
- **Navegación:** la división temática + el índice hacen más fácil ubicar qué token o
  componente usar.

## Estructura destino — `docs/design/`

| Archivo | Secciones que recibe (del `design.md` actual) |
|---|---|
| `reglas-copy.md` | Reglas duras · Tono y copy · Excepciones permitidas · Carga de CSS · Archivos clave del proyecto · Convención de prefijos |
| `fundamentos.md` | 01 Colores · 02 Tipografía · 03 Espaciado & superficies · 04 Iconografía |
| `botones-badges.md` | 05 Botones · 06 Badges & Pills |
| `formularios.md` | 07 Inputs & formularios · 08 Tabs y filtros · 12 Date & Time pickers |
| `layout-tablas.md` | 09 Cards y superficies · 10 Avatares · 11 Tablas |
| `navegacion.md` | TopBar · 13 Sidebar · 14 Pipeline |
| `documental-vacios.md` | 15 Gestión documental · 16 Empty states |

## Reglas de migración

1. **Pérdida cero.** El contenido se mueve **literal**; solo se redistribuye. No se reescribe,
   no se "mejora" copy, no se reordena dentro de cada sección.
2. **Se conservan los números de sección en los headings** (`## 09 · Cards y superficies`,
   `## 15 · Gestión documental`, etc.). Esto mantiene válidas:
   - las referencias cruzadas internas del propio texto (`ver sección 13`);
   - los comentarios en código que citan `§NN`.
3. Cada archivo abre con un encabezado mínimo: título + una línea de alcance. Se descarta el
   "Índice" con anclas del `design.md` original (su rol de navegación lo asume el índice de
   `CLAUDE.md`).
4. El orden de las secciones dentro de cada archivo respeta el orden del documento original.

## Índice en `CLAUDE.md`

Se añade una sección `## Sistema de Diseño` con esta tabla (~13 líneas). La mención actual
en la línea ~47 (`Sistema de diseño: ver \`.claude/rules/design.md\`.`) se reduce a un
puntero a esa sección.

```markdown
## Sistema de Diseño

Tokens, componentes y patrones en `docs/design/`. **Lee solo los archivos relevantes a la
tarea** — no cargues todo.

| Archivo | Contenido |
|---|---|
| `docs/design/reglas-copy.md` | Reglas duras, tono/copy, excepciones, orden de carga CSS, prefijos |
| `docs/design/fundamentos.md` | Colores, tipografía, espaciado, iconografía (tokens base) |
| `docs/design/botones-badges.md` | Botones, badges, pills |
| `docs/design/formularios.md` | Inputs, tabs, filtros, date/time pickers |
| `docs/design/layout-tablas.md` | Cards y superficies, avatares, tablas |
| `docs/design/navegacion.md` | TopBar, sidebar, pipeline |
| `docs/design/documental-vacios.md` | Gestión documental, empty states |

Antes de crear o editar cualquier vista, lee siempre `reglas-copy.md` + `fundamentos.md`,
luego el archivo del componente concreto.
```

## Actualización de referencias

### Referencias activas a la ruta vieja — se repuntan a `docs/design/`

| Archivo | Cambio |
|---|---|
| `CLAUDE.md:~47` | Reemplazada por la nueva sección `## Sistema de Diseño` |
| `README.md:15` | `Ver \`.claude/rules/design.md\`.` → `Ver \`docs/design/\`.` |
| `README.md:109` | Línea del árbol de archivos: `.claude/rules/design.md` → `docs/design/` |
| `README.md:155` | Enlace `[\`.claude/rules/design.md\`](...)` → `[\`docs/design/\`](docs/design/)` |
| `webroot/css/components.css:5` | Comentario: `(ver .claude/rules/design.md)` → `(ver docs/design/)` |
| `webroot/css/sgi-flatpickr-overrides.css:8` | Comentario: `.claude/rules/design.md` → `docs/design/` |
| `webroot/js/sgi-signature.js:57` | Comentario → `docs/design/reglas-copy.md` sección "Excepciones permitidas" |
| `templates/element/payment_section.php:5` | `design.md §09` → `docs/design/layout-tablas.md §09` |
| `templates/element/document_row_template.php:5` | `design.md §15` → `docs/design/documental-vacios.md §15` |
| `templates/element/document_row.php:8` | `design.md §15` → `docs/design/documental-vacios.md §15` |

### Referencias a `STYLES.md` (rot previa) — se repuntan a `docs/design/`

`STYLES.md` no existe en el repo; estas referencias ya estaban rotas antes de este cambio.
Se corrigen como parte de la limpieza, repuntándolas a `docs/design/`.

> **Interpretación a confirmar:** "eliminar esas referencias" se entiende como **corregir el
> puntero roto** (repuntar a `docs/design/`), **no** borrar la frase que lo contiene. Borrar
> las frases dejaría a `arquitecture.md` sin señalar dónde están las reglas visuales.

| Archivo | Líneas |
|---|---|
| `arquitecture.md` | 5, 191, 423, 1123, 1153 — `STYLES.md` → `docs/design/` |
| `docs/audits/architecture-audit-roadmap.md` | 359 — `Estilo: \`STYLES.md\` (raíz)` → `docs/design/` |

### No se tocan

- Specs/plans históricos en `docs/superpowers/` con fecha (p. ej.
  `2026-05-13-invoice-revision-section-refactor-design.md`): son registros de un punto en el
  tiempo, no se reescriben aunque mencionen la ruta vieja.
- Las coincidencias `*-design.md` en roadmap, plans y `SeedAdvancesPermissions.php` son
  nombres de archivos de spec — no tienen relación con el sistema de diseño (ruido del grep).

## Limpieza

- Borrar `.claude/rules/design.md`. La carpeta `.claude/rules/` queda vacía y desaparece de
  git automáticamente (git no versiona carpetas vacías).

## Criterios de validación manual

Este proyecto no usa tests automatizados. Tras aplicar los cambios:

1. **Pérdida cero de contenido:** concatenar los 7 archivos nuevos y verificar contra el
   `design.md` original (vía `git show`) que no falta ni sobra ninguna sección. Cada una de
   las 16 secciones numeradas + TopBar + los 4 apéndices debe aparecer exactamente una vez.
2. **Sin referencias rotas:** `grep -rn ".claude/rules/design.md\|STYLES.md" .` no devuelve
   coincidencias fuera de specs/plans históricos de `docs/superpowers/`.
3. **Índice correcto:** abrir `CLAUDE.md`, confirmar que la sección `## Sistema de Diseño`
   lista los 7 archivos con rutas que existen en `docs/design/`.
4. **Comentarios en código:** abrir `payment_section.php`, `document_row.php`,
   `document_row_template.php`, `sgi-signature.js` y confirmar que los punteros nuevos
   resuelven a un archivo y sección reales.
5. **Alerta de tamaño:** iniciar una sesión nueva de Claude Code y confirmar que ya no salta
   la alerta de archivo grande en contexto.

## Fuera de alcance

- No se crea `docs/design/README.md` (el índice vive en `CLAUDE.md`, decisión del usuario).
- No se reorganiza ni reescribe `arquitecture.md` más allá de corregir los punteros.
- La entrada de memoria de Claude que menciona `STYLES.md` se actualiza aparte (no es parte
  del repo).
