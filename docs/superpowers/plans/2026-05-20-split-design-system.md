# Split del sistema de diseño en `docs/design/` — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover `.claude/rules/design.md` (~14k tokens cargados en cada sesión) a 7 archivos temáticos en `docs/design/` que se leen bajo demanda, con un índice en `CLAUDE.md`.

**Architecture:** El contenido se extrae verbatim por rangos de línea del `design.md` original hacia 7 archivos nuevos. `CLAUDE.md` gana una sección índice. Todas las referencias a la ruta vieja (y la rot previa a `STYLES.md`) se repuntan a `docs/design/`. Finalmente se borra el archivo original.

**Tech Stack:** Markdown, bash (`sed`, `cat`, `diff`), git. Sin código de aplicación.

**Spec:** `docs/superpowers/specs/2026-05-20-split-design-system-design.md`

**Notas de ejecución:**
- Todos los comandos se ejecutan desde la raíz del repo: `C:\Users\sistema\Documents\sgi`.
- Este proyecto **no usa tests automatizados** (ver `CLAUDE.md` → Testing Policy). La verificación es por `diff` exacto y `grep`, descrita en cada tarea.
- Mapa de secciones → archivos y rangos de línea del `design.md` original (1581 líneas):

  | Archivo destino | Secciones | Rangos `sed` |
  |---|---|---|
  | `reglas-copy.md` | Reglas duras + Tono y copy + Excepciones + Carga de CSS + Archivos clave + Convención de prefijos | `42,58p;1469,1581p` |
  | `fundamentos.md` | 01 Colores · 02 Tipografía · 03 Espaciado · 04 Iconografía | `59,242p` |
  | `botones-badges.md` | 05 Botones · 06 Badges & Pills | `243,408p` |
  | `formularios.md` | 07 Inputs · 08 Tabs y filtros · 12 Date & Time pickers | `409,594p;869,967p` |
  | `layout-tablas.md` | 09 Cards · 10 Avatares · 11 Tablas | `595,868p` |
  | `navegacion.md` | TopBar · 13 Sidebar · 14 Pipeline | `968,1310p` |
  | `documental-vacios.md` | 15 Gestión documental · 16 Empty states | `1311,1468p` |

  Las líneas 1-41 del original (título, preámbulo meta "16 secciones · 80+ tokens", e "Índice" con anclas) **no se migran**: su rol lo asumen el encabezado propio de cada archivo y el índice de `CLAUDE.md`. La suma de los rangos es exactamente 1540 líneas = líneas 42-1581 del original (cobertura total, sin solapes).

---

## Task 1: Crear los 7 archivos en `docs/design/`

**Files:**
- Create: `docs/design/reglas-copy.md`
- Create: `docs/design/fundamentos.md`
- Create: `docs/design/botones-badges.md`
- Create: `docs/design/formularios.md`
- Create: `docs/design/layout-tablas.md`
- Create: `docs/design/navegacion.md`
- Create: `docs/design/documental-vacios.md`
- Source (lectura): `.claude/rules/design.md`

Cada archivo se crea con un encabezado de **exactamente 6 líneas** (título, blanco, alcance, blanco, `---`, blanco) vía heredoc `cat`, seguido del contenido extraído verbatim con `sed`. Mantener el heredoc con comillas (`<<'EOF'`) para no interpolar `$`.

- [ ] **Step 1: Crear `reglas-copy.md`**

```bash
mkdir -p docs/design
cat > docs/design/reglas-copy.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Reglas, copy y convenciones

Fuente única de verdad para construir vistas del SGI: reglas duras (no negociables), tono y copy, excepciones permitidas, orden de carga de CSS y convención de prefijos. Lee este archivo antes de tocar cualquier vista. El índice completo del sistema de diseño está en `CLAUDE.md`.

---

EOF
sed -n '42,58p;1469,1581p' .claude/rules/design.md >> docs/design/reglas-copy.md
```

- [ ] **Step 2: Crear `fundamentos.md`**

```bash
cat > docs/design/fundamentos.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Fundamentos

Tokens base del sistema: colores, tipografía, espaciado y superficies, iconografía.

---

EOF
sed -n '59,242p' .claude/rules/design.md >> docs/design/fundamentos.md
```

- [ ] **Step 3: Crear `botones-badges.md`**

```bash
cat > docs/design/botones-badges.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Botones y badges

Componentes de acción y estado: botones (variantes, tamaños, estados) y badges/pills.

---

EOF
sed -n '243,408p' .claude/rules/design.md >> docs/design/botones-badges.md
```

- [ ] **Step 4: Crear `formularios.md`**

```bash
cat > docs/design/formularios.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Formularios

Inputs y formularios, tabs y filtros, date & time pickers.

---

EOF
sed -n '409,594p;869,967p' .claude/rules/design.md >> docs/design/formularios.md
```

- [ ] **Step 5: Crear `layout-tablas.md`**

```bash
cat > docs/design/layout-tablas.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Layout y tablas

Cards y superficies, avatares, tablas.

---

EOF
sed -n '595,868p' .claude/rules/design.md >> docs/design/layout-tablas.md
```

- [ ] **Step 6: Crear `navegacion.md`**

```bash
cat > docs/design/navegacion.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Navegación

Patrones de navegación: TopBar, sidebar y pipeline.

---

EOF
sed -n '968,1310p' .claude/rules/design.md >> docs/design/navegacion.md
```

- [ ] **Step 7: Crear `documental-vacios.md`**

```bash
cat > docs/design/documental-vacios.md <<'EOF'
# Sistema de Diseño SGI · COPCSA — Gestión documental y empty states

Gestión documental (códigos de documento, filas, indicadores de completitud) y empty states.

---

EOF
sed -n '1311,1468p' .claude/rules/design.md >> docs/design/documental-vacios.md
```

- [ ] **Step 8: Verificar pérdida cero (diff exacto por archivo)**

Compara el contenido de cada archivo nuevo (saltando el encabezado de 6 líneas) contra los rangos exactos del original. `design.md` todavía existe en este punto.

```bash
for pair in \
  "reglas-copy.md:42,58p;1469,1581p" \
  "fundamentos.md:59,242p" \
  "botones-badges.md:243,408p" \
  "formularios.md:409,594p;869,967p" \
  "layout-tablas.md:595,868p" \
  "navegacion.md:968,1310p" \
  "documental-vacios.md:1311,1468p"; do
  f="${pair%%:*}"; r="${pair#*:}"
  if diff <(tail -n +7 "docs/design/$f") <(sed -n "$r" .claude/rules/design.md) >/dev/null; then
    echo "OK   $f"
  else
    echo "FAIL $f"
    diff <(tail -n +7 "docs/design/$f") <(sed -n "$r" .claude/rules/design.md) | head -20
  fi
done
```

Expected: 7 líneas, todas `OK`. Si alguna dice `FAIL`, revisar el `diff` mostrado y corregir el rango/encabezado del archivo afectado antes de continuar.

- [ ] **Step 9: Commit**

```bash
git add docs/design/
git commit -m "$(cat <<'EOF'
docs(design): crear docs/design/ con el sistema de diseño en 7 archivos

Contenido extraído verbatim de .claude/rules/design.md y dividido
en archivos temáticos para carga bajo demanda.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Añadir el índice del sistema de diseño en `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (línea ~47 y antes de `## Frontend`)

- [ ] **Step 1: Reemplazar la mención de la ruta vieja por un puntero**

Edit en `CLAUDE.md`:

old_string:
```
Sistema de diseño: ver `.claude/rules/design.md`.
```

new_string:
```
Sistema de diseño: ver la sección [Sistema de Diseño](#sistema-de-diseño) más abajo.
```

- [ ] **Step 2: Insertar la sección `## Sistema de Diseño` antes de `## Frontend`**

Edit en `CLAUDE.md`:

old_string:
```
## Frontend

- Font: Inter Variable (local, `webroot/fonts/Inter-Variable.ttf`).
```

new_string:
```
## Sistema de Diseño

Tokens, componentes y patrones en `docs/design/`. **Lee solo los archivos relevantes a la tarea** — no cargues todo.

| Archivo | Contenido |
|---|---|
| `docs/design/reglas-copy.md` | Reglas duras, tono/copy, excepciones, orden de carga CSS, prefijos |
| `docs/design/fundamentos.md` | Colores, tipografía, espaciado, iconografía (tokens base) |
| `docs/design/botones-badges.md` | Botones, badges, pills |
| `docs/design/formularios.md` | Inputs, tabs, filtros, date/time pickers |
| `docs/design/layout-tablas.md` | Cards y superficies, avatares, tablas |
| `docs/design/navegacion.md` | TopBar, sidebar, pipeline |
| `docs/design/documental-vacios.md` | Gestión documental, empty states |

Antes de crear o editar cualquier vista, lee siempre `reglas-copy.md` + `fundamentos.md`, luego el archivo del componente concreto.

## Frontend

- Font: Inter Variable (local, `webroot/fonts/Inter-Variable.ttf`).
```

- [ ] **Step 3: Verificar**

```bash
grep -n "Sistema de Diseño" CLAUDE.md
grep -n "docs/design/" CLAUDE.md | wc -l
```

Expected: aparece el puntero (`#sistema-de-diseño`), el encabezado `## Sistema de Diseño`, y 7 rutas `docs/design/...` en la tabla (el `wc -l` da 7).

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs(design): añadir índice del sistema de diseño en CLAUDE.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Actualizar las referencias activas a la ruta vieja

**Files:**
- Modify: `README.md` (líneas 15, 109, 155)
- Modify: `webroot/css/components.css` (línea 5)
- Modify: `webroot/css/sgi-flatpickr-overrides.css` (línea 8)
- Modify: `webroot/js/sgi-signature.js` (línea 57)
- Modify: `templates/element/payment_section.php` (línea 5)
- Modify: `templates/element/document_row_template.php` (línea 5)
- Modify: `templates/element/document_row.php` (línea 8)

- [ ] **Step 1: `README.md` — referencia en Características**

Edit:

old_string:
```
- **UI consistente** — Sistema de diseño basado en bordes (sin sombras), Inter Variable, paleta verde corporativo. Ver `.claude/rules/design.md`.
```

new_string:
```
- **UI consistente** — Sistema de diseño basado en bordes (sin sombras), Inter Variable, paleta verde corporativo. Ver `docs/design/` (índice en `CLAUDE.md`).
```

- [ ] **Step 2: `README.md` — árbol de estructura del proyecto**

Edit (la nueva ruta se alinea para mantener el `#` en la misma columna):

old_string:
```
.claude/rules/design.md # Sistema de diseño visual
```

new_string:
```
docs/design/             # Sistema de diseño visual
```

- [ ] **Step 3: `README.md` — enlace en Convenciones clave**

Edit:

old_string:
```
Ver [`CLAUDE.md`](CLAUDE.md) y [`.claude/rules/design.md`](.claude/rules/design.md) para la guía completa.
```

new_string:
```
Ver [`CLAUDE.md`](CLAUDE.md) y [`docs/design/`](docs/design/) para la guía completa.
```

- [ ] **Step 4: `webroot/css/components.css` — comentario de cabecera**

Edit:

old_string:
```
   (ver .claude/rules/design.md). `styles.css` aporta tokens + base;
```

new_string:
```
   (ver docs/design/). `styles.css` aporta tokens + base;
```

- [ ] **Step 5: `webroot/css/sgi-flatpickr-overrides.css` — comentario de cabecera**

Edit:

old_string:
```
   internas. Ver .claude/rules/design.md y MN-005 en AUDIT-BACKLOG.md.
```

new_string:
```
   internas. Ver docs/design/ y MN-005 en AUDIT-BACKLOG.md.
```

- [ ] **Step 6: `webroot/js/sgi-signature.js` — comentario de la excepción**

Edit:

old_string:
```
        // (ver .claude/rules/design.md sección "Excepciones permitidas").
```

new_string:
```
        // (ver docs/design/reglas-copy.md sección "Excepciones permitidas").
```

- [ ] **Step 7: `templates/element/payment_section.php` — comentario de patrón visual**

Edit:

old_string:
```
 * Visual pattern (design.md §09 "Card compleja" — fila de pago: sub-superficie
```

new_string:
```
 * Visual pattern (docs/design/ §09 "Card compleja" — fila de pago: sub-superficie
```

- [ ] **Step 8: `templates/element/document_row_template.php` — comentario de patrón visual**

Edit:

old_string:
```
 * Visual pattern (design.md §15 · Gestión documental — "Fila de documento").
```

new_string:
```
 * Visual pattern (docs/design/ §15 · Gestión documental — "Fila de documento").
```

- [ ] **Step 9: `templates/element/document_row.php` — comentario de patrón visual**

Edit:

old_string:
```
 * Visual pattern (design.md §15 · Gestión documental — "Fila de documento"):
```

new_string:
```
 * Visual pattern (docs/design/ §15 · Gestión documental — "Fila de documento"):
```

- [ ] **Step 10: Verificar**

```bash
grep -rn "\.claude/rules/design\.md" README.md webroot/ templates/
grep -rn "design\.md §" templates/
```

Expected: ambos `grep` sin salida (todas las referencias activas repuntadas).

- [ ] **Step 11: Commit**

```bash
git add README.md webroot/css/components.css webroot/css/sgi-flatpickr-overrides.css webroot/js/sgi-signature.js templates/element/payment_section.php templates/element/document_row_template.php templates/element/document_row.php
git commit -m "$(cat <<'EOF'
docs(design): repuntar referencias del sistema de diseño a docs/design/

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Repuntar las referencias rotas a `STYLES.md`

`STYLES.md` no existe en el repo — estas referencias ya estaban rotas antes de este cambio. Se corrige el puntero (no se borran las frases).

**Files:**
- Modify: `arquitecture.md` (líneas 5, 191, 423, 1123, 1153)
- Modify: `docs/audits/architecture-audit-roadmap.md` (línea 359)

- [ ] **Step 1: `arquitecture.md` — reemplazo global de `STYLES.md`**

Las 5 ocurrencias de `STYLES.md` en `arquitecture.md` son punteros "ver las reglas de diseño"; todas pasan a `docs/design/`.

```bash
sed -i 's#STYLES\.md#docs/design/#g' arquitecture.md
```

- [ ] **Step 2: `docs/audits/architecture-audit-roadmap.md` — referencia en Referencias**

Edit:

old_string:
```
- Estilo: `STYLES.md` (raíz)
```

new_string:
```
- Estilo: `docs/design/`
```

- [ ] **Step 3: Verificar**

```bash
grep -rn "STYLES\.md" arquitecture.md docs/audits/architecture-audit-roadmap.md
grep -c "docs/design/" arquitecture.md
```

Expected: el primer `grep` sin salida; el segundo da `5` (las 5 ocurrencias repuntadas).

- [ ] **Step 4: Commit**

```bash
git add arquitecture.md docs/audits/architecture-audit-roadmap.md
git commit -m "$(cat <<'EOF'
docs(design): repuntar referencias rotas a STYLES.md hacia docs/design/

Rot previa: STYLES.md ya no existía en el repo.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Borrar `.claude/rules/design.md` y validación final

**Files:**
- Delete: `.claude/rules/design.md`

- [ ] **Step 1: Validación final de referencias**

Confirmar que no quedan referencias rotas fuera de los specs/plans históricos de `docs/superpowers/` (que son registros con fecha y no se reescriben).

```bash
grep -rn "\.claude/rules/design\.md\|STYLES\.md" \
  --include='*.md' --include='*.css' --include='*.js' --include='*.php' . \
  | grep -v 'docs/superpowers/'
```

Expected: sin salida. (Las coincidencias en `docs/superpowers/` — specs/plans históricos y el spec/plan de este cambio — se ignoran deliberadamente.)

- [ ] **Step 2: Borrar el archivo original**

```bash
git rm .claude/rules/design.md
```

La carpeta `.claude/rules/` queda vacía y desaparece de git automáticamente.

- [ ] **Step 3: Verificar contenido contra la versión en git**

`design.md` ya no está en el working tree, pero el `git rm` del paso anterior aún no se ha commiteado, así que `HEAD` todavía lo conserva. Se valida contra `HEAD` para confirmar de nuevo la pérdida cero.

```bash
SRC="HEAD:.claude/rules/design.md"
for pair in \
  "reglas-copy.md:42,58p;1469,1581p" \
  "fundamentos.md:59,242p" \
  "botones-badges.md:243,408p" \
  "formularios.md:409,594p;869,967p" \
  "layout-tablas.md:595,868p" \
  "navegacion.md:968,1310p" \
  "documental-vacios.md:1311,1468p"; do
  f="${pair%%:*}"; r="${pair#*:}"
  if diff <(tail -n +7 "docs/design/$f") <(git show "$SRC" | sed -n "$r") >/dev/null; then
    echo "OK   $f"
  else
    echo "FAIL $f"
  fi
done
```

Expected: 7 líneas, todas `OK`.

- [ ] **Step 4: Commit**

```bash
git commit -m "$(cat <<'EOF'
docs(design): eliminar .claude/rules/design.md (migrado a docs/design/)

El contenido vive ahora en docs/design/ (7 archivos temáticos) y deja
de cargarse en cada sesión. Ahorro: ~14k tokens de contexto.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 5: Validación manual final (criterios del spec)**

1. **Índice correcto:** abrir `CLAUDE.md`, confirmar que la sección `## Sistema de Diseño` lista 7 rutas que existen en `docs/design/`.
2. **Comentarios en código:** abrir `payment_section.php`, `document_row.php`, `document_row_template.php`, `sgi-signature.js`; confirmar que los punteros nuevos resuelven a archivo + sección reales.
3. **Alerta de tamaño:** iniciar una sesión nueva de Claude Code en el proyecto y confirmar que ya **no** salta la alerta de archivo grande en contexto.

---

## Self-Review (autor del plan)

- **Cobertura del spec:** los 7 archivos (Task 1), el índice en `CLAUDE.md` (Task 2), las 10 referencias activas (Task 3), las 6 referencias a `STYLES.md` (Task 4), el borrado del original y la validación manual (Task 5) cubren cada sección del spec. La interpretación de "eliminar referencias a STYLES.md" = repuntar (no borrar la frase) está aplicada en Task 4 conforme al blockquote del spec.
- **Sin placeholders:** cada step tiene comando o `old_string`/`new_string` exacto.
- **Consistencia:** los rangos `sed` de Task 1 Step 8 y Task 5 Step 3 son idénticos; el encabezado de 6 líneas es uniforme en los 7 archivos, por lo que `tail -n +7` aplica a todos.
