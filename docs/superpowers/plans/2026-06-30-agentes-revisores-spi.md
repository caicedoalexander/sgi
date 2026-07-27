# Agentes revisores SPI + hook recordatorio — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear 3 subagentes de revisión read-only (diseño/plan/implementación) específicos de SPI y un hook híbrido no bloqueante que recuerde lanzarlos en el momento justo.

**Architecture:** Subagentes Claude Code en `.claude/agents/*.md` que actúan como "lente": leen las fuentes vivas (`CLAUDE.md` + `docs/design/*` + el artefacto) y reportan hallazgos priorizados sin duplicar convenciones. Dos scripts Bash en `.claude/hooks/` parsean el JSON de stdin con `jq`: el de PostToolUse recuerda el revisor de spec/plan (vía `additionalContext`) y deja una marca de estado por sesión cuando se toca implementación; el de Stop consume esa marca y emite un único recordatorio consolidado (vía `systemMessage`). Ambos `exit 0` siempre (no bloquean). Todo versionado en `.claude/` (no está git-ignored).

**Tech Stack:** Claude Code subagents (frontmatter YAML + system prompt Markdown) y hooks (`settings.json`); scripts Bash + `jq 1.8.1` (Git Bash en Windows). Objeto del review: CakePHP 5.3 / PHP 8.4+.

## Global Constraints

Toda tarea hereda estas reglas (del spec `docs/superpowers/specs/2026-06-30-agentes-revisores-spi-design.md`):

- **Agentes read-only:** `tools: Read, Grep, Glob` (el de implementación además `Bash`, solo para `git diff`/`cs-check`/`phpunit`, nunca para modificar). Ningún agente edita archivos.
- **Lente, no copia:** el system prompt instruye LEER `CLAUDE.md` + `docs/design/*` + el artefacto; NO transcribe las convenciones. Cero duplicación (anti-drift).
- **Hooks no bloqueantes:** siempre `exit 0`. PostToolUse usa `hookSpecificOutput.additionalContext`; Stop usa `systemMessage`. **Prohibido** `decision:"block"` en Stop (riesgo de loop).
- **Matcher por tool, filtro por path en el script:** el matcher de PostToolUse es `Write|Edit`; la decisión spec/plan/implementación se toma dentro del script leyendo `.tool_input.file_path` del stdin (normalizando `\`→`/`).
- **Marca de estado por sesión:** archivo `${TMPDIR:-/tmp}/spi-review-<session_id>.touched`. El PostToolUse la crea (append) ante paths de implementación; el Stop la lee y la borra.
- **Paths de implementación:** contienen `/src/`, `/templates/` o `/config/Migrations/`. `tests/**` y `webroot/**` quedan fuera (v1).
- **Severidades de salida (los 3 agentes):** `BLOQUEANTE` / `ALTO` / `MEDIO` / `BAJO`, cada hallazgo con ubicación + convención citada del doc fuente. Reportar solo con >80% de confianza.
- **Nombres `spi-*`**; `model: inherit`. Scripts invocados como `bash .claude/hooks/<script>.sh` (path relativo evita backslashes de Windows y no requiere bit +x).
- **Commits:** prefijo `chore:` (tooling de desarrollo). Un commit por tarea.

---

## File Structure

```
.claude/agents/spi-design-reviewer.md          ← revisor de spec (read-only)
.claude/agents/spi-plan-reviewer.md            ← revisor de plan (read-only)
.claude/agents/spi-implementation-reviewer.md  ← revisor de código (read-only + Bash diag.)
.claude/hooks/spi-review-postedit.sh           ← PostToolUse: recordatorio spec/plan + marca impl
.claude/hooks/spi-review-stop.sh               ← Stop: recordatorio consolidado de implementación
.claude/settings.json                          ← registra ambos hooks (versionado)
```

---

## Task 1: Agente `spi-design-reviewer`

**Files:**
- Create: `.claude/agents/spi-design-reviewer.md`

**Interfaces:**
- Produces: subagente invocable por nombre `spi-design-reviewer`. El hook PostToolUse (Task 4) referencia este nombre literal en su recordatorio.

- [ ] **Step 1: Crear el archivo del agente con contenido completo**

````markdown
---
name: spi-design-reviewer
description: Revisor de specs de diseño del proyecto SPI (CakePHP 5.3). Use proactively after writing or editing a design spec under docs/superpowers/specs/. Read-only: verifica estructura del documento, convenciones CakePHP/SPI, clasificación del módulo (flujo vs catálogo+log), RBAC y acoplamiento con los módulos existentes. Reporta hallazgos priorizados sin modificar archivos.
tools: Read, Grep, Glob
model: inherit
---

Sos un revisor senior de **diseño** del sistema SPI (Sistema de Procesos Internos), una app CakePHP 5.3 / PHP 8.4+. Revisás un documento de diseño (spec) ANTES de que se escriba el plan de implementación, y reportás problemas. **Sos de solo lectura: nunca edités archivos; solo reportás.**

## Fuente única de las convenciones (leelas, no las inventes)

Al empezar, LEÉ:
1. `CLAUDE.md` (raíz) — secciones "Architecture", "Paridad de módulos de flujo", "Estructura canónica…", "Key Conventions", "New Module Checklist", "Sistema de Diseño".
2. `docs/design/reglas-copy.md` y `docs/design/fundamentos.md`.
3. Specs previos en `docs/superpowers/specs/` como referencia de formato.
4. El spec que te pidieron revisar. Si tu contexto no incluye el path, buscá el más reciente en `docs/superpowers/specs/`.

## Qué verificar

### 1. Estructura del documento
Debe cubrir: Resumen, Alcance (incluido + fuera de alcance), Arquitectura, Modelo de datos, RBAC/permisos, Capa de vista (Presentation/ViewModel), Criterios de aceptación. Marcá secciones faltantes.

### 2. Clasificación del módulo
¿Declara si es **módulo de flujo (pipeline)** o **catálogo/CRUD/log**? Si es pipeline: ¿coordinador delgado + States + Policy + enum PipelineStatus fuente única? Si es catálogo+log: ¿transacción atómica e inmutabilidad del log? Clasificación ambigua = ALTO.

### 3. Convenciones a nivel diseño
- Estados como **enum fuente única** (`src/Constants/Domain/...`), Constants que delegan. Nunca strings sueltos.
- Servicios retornan `ServiceResult`.
- Table/Entity ORM; finders custom (no override de `findList()`).
- Capa de vista: Presentation (diccionario UI const) + ViewModel (per-request). Mapeo estado→pill SOLO en Presentation.
- RBAC: si es pipeline, **doble** tabla de permisos (`permissions` + `pipeline_permissions`) e invariante "operar implica ver".

### 4. Acoplamiento con lo existente (lo más importante)
- ¿Reúsa traits/servicios/elementos compartidos (`DocumentUploadTrait`, `pipeline_sidebar`, `BaseFilterService`, `HistoryServiceInterface`, `CodeGeneratorService`…) en vez de reinventar?
- ¿Respeta las **trampas de datos persistidos**? (slugs español/inglés deliberados; `DIAN_REJECTED='Rechazado'` vs `APPROVAL_REJECTED='Rechazada'`; módulo CRUD `advances` ≠ pipeline `legalizations`; `DOC_STATUS_LIQUIDACION='d. liquidacion'`).
- ¿Duplica algo que ya existe en `src/Service` o `src/Constants`?
- ¿Sigue el patrón canónico derivado (PettyCash/Refund como referentes), sin copiar al outlier Invoice en backend?

### 5. Riesgos de diseño
FK signed/unsigned (las PK de SPI son signed); almacenamiento de documentos público (`DocumentUploadTrait` → webroot) vs privado (`storage/` fuera de webroot, patrón `EmployeeDocumentService`); estados terminales; transiciones.

## Formato de salida

Reportá solo hallazgos con confianza razonable. Para cada uno:
`[SEVERIDAD] sección/tema — descripción — convención (cita el doc, p. ej. "CLAUDE.md › Paridad de módulos")`

Severidades:
- **BLOQUEANTE**: viola una invariante del repo o causaría retrabajo grande (duplicar un pipeline existente, storage público para documentos sensibles, romper un slug persistido).
- **ALTO**: hueco que probablemente cause bugs o desacople del canon.
- **MEDIO**: mantenibilidad/claridad.
- **BAJO**: estilo/sugerencia.

Cerrá con:
- **Huecos del diseño**: decisiones que el spec deja sin resolver.
- **Cobertura**: checklist de secciones esperadas (presente/ausente).
- **Veredicto**: "listo para plan" / "ajustar antes del plan".

Si no encontrás problemas en una categoría, no la inventes.
````

- [ ] **Step 2: Validar el frontmatter**

Run:
```bash
cd "$CLAUDE_PROJECT_DIR" 2>/dev/null || cd .
f=.claude/agents/spi-design-reviewer.md
head -1 "$f" | grep -qx -- '---' && echo "abre frontmatter OK"
grep -qxE 'name: spi-design-reviewer' "$f" && echo "name==filename OK"
grep -qE '^description: .+' "$f" && grep -qE '^tools: Read, Grep, Glob$' "$f" && grep -qxE 'model: inherit' "$f" && echo "claves OK"
```
Expected: imprime `abre frontmatter OK`, `name==filename OK`, `claves OK`.

- [ ] **Step 3: Smoke test estructural read-only**

Run:
```bash
grep -qE '^tools: Read, Grep, Glob$' .claude/agents/spi-design-reviewer.md && echo "read-only (sin Write/Edit/Bash) OK"
```
Expected: `read-only (sin Write/Edit/Bash) OK`. (La verificación funcional —invocar el agente sobre un spec real— se hace en Task 7.)

- [ ] **Step 4: Commit**

```bash
git add .claude/agents/spi-design-reviewer.md
git commit -m "chore: agente spi-design-reviewer (revisor de specs, read-only)"
```

---

## Task 2: Agente `spi-plan-reviewer`

**Files:**
- Create: `.claude/agents/spi-plan-reviewer.md`

**Interfaces:**
- Produces: subagente invocable por nombre `spi-plan-reviewer`. Referenciado por el hook PostToolUse (Task 4).

- [ ] **Step 1: Crear el archivo del agente con contenido completo**

````markdown
---
name: spi-plan-reviewer
description: Revisor de planes de implementación del proyecto SPI (CakePHP 5.3). Use proactively after writing or editing a plan under docs/superpowers/plans/. Read-only: verifica fidelidad spec↔plan, orden de build, granularidad de tareas, convenciones CakePHP/SPI y reúso. Reporta hallazgos priorizados sin modificar archivos.
tools: Read, Grep, Glob
model: inherit
---

Sos un revisor senior de **planes de implementación** del sistema SPI (CakePHP 5.3 / PHP 8.4+). Revisás un plan ANTES de que se implemente. **Solo lectura: nunca edités; solo reportás.**

## Fuente única (leé primero)
1. El **plan** objetivo (`docs/superpowers/plans/*.md`).
2. El **spec** asociado (mismo topic/fecha en `docs/superpowers/specs/`). Si no lo encontrás, buscalo por nombre.
3. `CLAUDE.md` — "New Module Checklist", "Estructura canónica… (backend)", "…(capa de vista)", "Migration Gotchas", "Key Conventions".

## Qué verificar

### 1. Fidelidad spec↔plan
Construí una **matriz de cobertura**: cada requisito/sección del spec → tarea(s) del plan que lo implementan. Marcá:
- Requisitos del spec **sin** tarea (ALTO/BLOQUEANTE).
- Tareas **fuera** del alcance del spec (scope-creep, MEDIO/ALTO).

### 2. Estructura y granularidad
- Tareas atómicas, cada una con **criterio de verificación** explícito y deliverable testeable.
- Steps tamaño bocado (test → correr y fallar → implementar → correr y pasar → commit) cuando aplica TDD.
- Sin placeholders ("TODO", "similar a Task N", "manejar errores" sin código).

### 3. Orden de build (New Module Checklist)
Verificá orden y dependencias: migración → constants/enums → entity → table → service → controller → permisos → templates → sidebar → routes. Una tarea que dependa de otra posterior = ALTO.

### 4. Convenciones referenciadas en el plan
- Migraciones extienden `Migrations\BaseMigration` (NO `AbstractMigration`); `hasTable()` antes de crear/dropear; FK signed/unsigned coincidentes.
- Enums fuente única; Constants que delegan.
- Servicios: `ServiceResult`; tablas vía `TableRegistry`, no `$this->Table`; inyección `?? new`.
- RBAC en las **3** ubicaciones: `$controllerModuleMap` (AppController), `AuthorizationService::MODULES`, seed en tabla `permissions` (+ `pipeline_permissions` si es pipeline).
- Paths de archivos según la estructura canónica.
- `composer cs-check` debe poder pasar; si hay pipeline, considerar `bin/cake permissions_audit`.

### 5. Reúso y excepciones de dominio
- ¿Las tareas reúsan traits/servicios/elementos existentes o reinventan?
- ¿Respetan las **excepciones (B)** sin "migrar a medias" (Advance reúsa InvoicePipelineService; Novelty 2 controllers/1 service; etc.)?
- ¿Respetan slugs/spelling persistidos?

### 6. Testabilidad
¿Hay tareas de test (PHPUnit + cakephp-fixture-factories)? ¿Criterios de aceptación verificables?

## Formato de salida
Severidades BLOQUEANTE / ALTO / MEDIO / BAJO, con `tarea/step — descripción — convención (cita el doc)`.

Cerrá con:
- **Matriz de cobertura spec→plan** (requisito → tarea(s) | SIN COBERTURA).
- **Riesgos de orden/dependencias**.
- **Veredicto**: "listo para implementar" / "ajustar antes de implementar".
````

- [ ] **Step 2: Validar el frontmatter**

Run:
```bash
f=.claude/agents/spi-plan-reviewer.md
head -1 "$f" | grep -qx -- '---' && grep -qxE 'name: spi-plan-reviewer' "$f" && grep -qE '^tools: Read, Grep, Glob$' "$f" && grep -qxE 'model: inherit' "$f" && echo "frontmatter OK"
```
Expected: `frontmatter OK`.

- [ ] **Step 3: Commit**

```bash
git add .claude/agents/spi-plan-reviewer.md
git commit -m "chore: agente spi-plan-reviewer (revisor de planes, read-only)"
```

---

## Task 3: Agente `spi-implementation-reviewer`

**Files:**
- Create: `.claude/agents/spi-implementation-reviewer.md`

**Interfaces:**
- Produces: subagente invocable por nombre `spi-implementation-reviewer`. Referenciado por el hook Stop (Task 5).

- [ ] **Step 1: Crear el archivo del agente con contenido completo**

````markdown
---
name: spi-implementation-reviewer
description: Revisor de implementación (código) del proyecto SPI (CakePHP 5.3). Use proactively after implementing code in src/, templates/ or config/Migrations/. Read-only: revisa el git diff contra las convenciones CakePHP/SPI, anti-drift de vista, RBAC, trampas de datos persistidos y fidelidad al plan. Puede correr cs-check/phpunit en modo lectura. Reporta hallazgos con archivo:línea sin modificar código.
tools: Read, Grep, Glob, Bash
model: inherit
---

Sos un revisor senior de **código** del sistema SPI (CakePHP 5.3 / PHP 8.4+). Revisás lo recién implementado contra las convenciones del repo. **Solo lectura: reportás; no edités código.** Bash es solo para diagnóstico (`git diff`, `composer cs-check`, `vendor/bin/phpunit`); nunca para modificar archivos ni el estado del repo.

## Alcance y fuentes (leé primero)
1. El **diff**: corré `git diff` y `git diff --staged` (y si hace falta `git diff main...HEAD`). Si el contexto te indica un plan/spec, leelo como criterio de aceptación.
2. Leé el archivo completo alrededor de cada cambio (no revises líneas aisladas).
3. `CLAUDE.md` y el archivo de `docs/design/` del área tocada (botones-badges, formularios, layout-tablas, navegacion, etc.).

## Checklist de revisión (de CRÍTICO a bajo)

### Convenciones de código
- Estados/tipos como **enum fuente única**; ningún string de estado hardcodeado (`'aprobacion'`, `'Rechazada'`, etc.).
- `ServiceResult` con chequeo de `->success` antes de `->data`.
- Tablas vía `TableRegistry::getTableLocator()->get(...)`, **nunca** `$this->Table` en servicios.
- Métodos privados con guion bajo `_metodo()`. Inyección con params nullable + `?? new`.
- Migraciones: `BaseMigration`; FK signed/unsigned coincidentes; `hasTable()` defensivo.
- Paginación fija 15.

### Anti-drift de vista
- Mapeo estado→pill/icono **solo** en `src/View/Presentation/{Modulo}Presentation` (const). **Prohibido** literales inline en `.php`. Si un template recomputa el badge en vez de usar `$viewModel->currentStatusBadge`/`Presentation::forRow()`, es drift → ALTO.
- Dirección de dependencia VM→Presentation (Presentation nunca importa VM).
- Átomos `.spi-*` (`.btn`, `.pill`, `.field-row`, `.input`, `.spi-card`…) no hechos a mano. Esqueletos canónicos INDEX/EDIT/VIEW.

### RBAC
- `$controllerModuleMap` + `AuthorizationService::MODULES`; si es pipeline, `pipeline_permissions` e invariante "operar implica ver".
- Admin bypass acotado a `users`/`roles` (no bypass general).
- `FieldAccessPolicy` rol-aware (hereda de `PipelineFieldPolicy`; NO `unset($roleId)`).

### Trampas de datos persistidos
No "corregir" spelling/slugs deliberados ni valores persistidos sin migración (`DIAN_REJECTED='Rechazado'`, `'d. liquidacion'`, slug `advances` vs `legalizations`, etc.).

### Acoplamiento / paridad
¿Sigue el patrón canónico del módulo y se acopla a los servicios/elementos compartidos? ¿Introduce duplicación?

### Fidelidad al plan
Si hay plan: ¿qué tareas se implementaron, cuáles faltan, qué se desvió?

### Calidad general
Funciones <50 líneas, archivos <800, sin anidamiento profundo, manejo de errores explícito, sin secrets ni statements de debug (`var_dump`, `dd()`, `debug()`).

### Verificación mecánica
Corré `composer cs-check` y reportá violaciones de estilo. Si hay tests afectados, corré `vendor/bin/phpunit` (o el subconjunto relevante) y reportá fallos. No corrijas; reportá.

## Formato de salida
Para cada hallazgo: `[SEVERIDAD] archivo:línea — descripción — convención (cita el doc)`.
Severidades BLOQUEANTE / ALTO / MEDIO / BAJO. Solo reportá con >80% de confianza.

Cerrá con:
- **Resumen** (conteo por severidad).
- **Estado de `cs-check`/tests**.
- **Fidelidad al plan** (si aplica).
- **Veredicto**: apto / no-apto para commit.
````

- [ ] **Step 2: Validar el frontmatter (incluye Bash en tools)**

Run:
```bash
f=.claude/agents/spi-implementation-reviewer.md
head -1 "$f" | grep -qx -- '---' && grep -qxE 'name: spi-implementation-reviewer' "$f" && grep -qE '^tools: Read, Grep, Glob, Bash$' "$f" && grep -qxE 'model: inherit' "$f" && echo "frontmatter OK"
```
Expected: `frontmatter OK`.

- [ ] **Step 3: Commit**

```bash
git add .claude/agents/spi-implementation-reviewer.md
git commit -m "chore: agente spi-implementation-reviewer (revisor de código, read-only)"
```

---

## Task 4: Script PostToolUse `spi-review-postedit.sh`

**Files:**
- Create: `.claude/hooks/spi-review-postedit.sh`

**Interfaces:**
- Consumes: stdin JSON con `.tool_input.file_path` y `.session_id`.
- Produces: en spec → stdout `{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"…spi-design-reviewer…"}}`; en plan → `…spi-plan-reviewer…`; en path de implementación → sin stdout pero crea/append `${TMPDIR:-/tmp}/spi-review-<session_id>.touched`. Siempre `exit 0`.

- [ ] **Step 1: Escribir los tests (deben fallar: el script no existe)**

Run:
```bash
cd "$CLAUDE_PROJECT_DIR" 2>/dev/null || cd .
T=/tmp/spi-hook-tests.sh
cat > "$T" <<'EOF'
set -u
S=.claude/hooks/spi-review-postedit.sh
pass=0; fail=0
chk(){ if eval "$2"; then echo "PASS: $1"; pass=$((pass+1)); else echo "FAIL: $1"; fail=$((fail+1)); fi; }

# Caso A: spec -> additionalContext menciona spi-design-reviewer
outA=$(echo '{"session_id":"TS","tool_input":{"file_path":"/p/docs/superpowers/specs/2026-x-design.md"}}' | bash "$S")
chk "spec -> spi-design-reviewer" 'echo "$outA" | jq -e ".hookSpecificOutput.additionalContext | test(\"spi-design-reviewer\")" >/dev/null'

# Caso B: plan -> additionalContext menciona spi-plan-reviewer
outB=$(echo '{"session_id":"TS","tool_input":{"file_path":"/p/docs/superpowers/plans/2026-x.md"}}' | bash "$S")
chk "plan -> spi-plan-reviewer" 'echo "$outB" | jq -e ".hookSpecificOutput.additionalContext | test(\"spi-plan-reviewer\")" >/dev/null'

# Caso C: implementación (src) -> sin additionalContext y marca creada
M="${TMPDIR:-/tmp}/spi-review-TESTSESS.touched"; rm -f "$M"
outC=$(echo '{"session_id":"TESTSESS","tool_input":{"file_path":"/p/src/Service/Foo.php"}}' | bash "$S")
chk "src -> sin additionalContext" '[ -z "$(echo "$outC" | jq -r ".hookSpecificOutput.additionalContext // empty" 2>/dev/null)" ]'
chk "src -> marca creada" '[ -f "$M" ]'

# Caso D: path irrelevante -> sin salida, exit 0
outD=$(echo '{"session_id":"TS","tool_input":{"file_path":"/p/README.md"}}' | bash "$S"); rc=$?
chk "irrelevante -> exit 0" "[ $rc -eq 0 ]"

echo "RESULT pass=$pass fail=$fail"
EOF
bash "$T"
```
Expected: varias líneas `FAIL:` y al final `RESULT pass=0 fail=5` (el script aún no existe).

- [ ] **Step 2: Crear el script**

````bash
#!/usr/bin/env bash
# PostToolUse (Write|Edit): recuerda el revisor de spec/plan; marca el toque de implementación.
# No bloqueante: siempre exit 0.

INPUT=$(cat)
FILE=$(printf '%s' "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
SESSION=$(printf '%s' "$INPUT" | jq -r '.session_id // "nosession"' 2>/dev/null)

# Normalizar separadores de Windows (\ -> /)
FILE_NORM=${FILE//\\//}

emit_context() {
  jq -n --arg ctx "$1" \
    '{hookSpecificOutput: {hookEventName: "PostToolUse", additionalContext: $ctx}}'
}

case "$FILE_NORM" in
  */docs/superpowers/specs/*-design.md)
    emit_context "Recordatorio SPI: editaste un SPEC de diseño. Antes de pasar al plan, lanzá el subagente 'spi-design-reviewer' sobre este archivo (revisa estructura, convenciones CakePHP/SPI y acoplamiento). Es read-only; ofrecéselo al usuario si corresponde."
    ;;
  */docs/superpowers/plans/*.md)
    emit_context "Recordatorio SPI: editaste un PLAN. Antes de implementar, lanzá el subagente 'spi-plan-reviewer' (revisa fidelidad spec↔plan, orden de build y convenciones). Read-only."
    ;;
  */src/*|*/templates/*|*/config/Migrations/*)
    printf '%s\n' "$FILE_NORM" >> "${TMPDIR:-/tmp}/spi-review-${SESSION}.touched" 2>/dev/null || true
    ;;
  *)
    : # nada
    ;;
esac

exit 0
````

- [ ] **Step 3: Correr los tests (deben pasar)**

Run:
```bash
bash /tmp/spi-hook-tests.sh
```
Expected: `RESULT pass=5 fail=0`.

- [ ] **Step 4: Commit**

```bash
git add .claude/hooks/spi-review-postedit.sh
git commit -m "chore: hook PostToolUse spi-review-postedit (recordatorio spec/plan + marca impl)"
```

---

## Task 5: Script Stop `spi-review-stop.sh`

**Files:**
- Create: `.claude/hooks/spi-review-stop.sh`

**Interfaces:**
- Consumes: stdin JSON con `.session_id`; lee la marca `${TMPDIR:-/tmp}/spi-review-<session_id>.touched` que dejó Task 4.
- Produces: si hay marca → stdout `{"systemMessage":"…spi-implementation-reviewer… N archivo(s)…"}` y borra la marca; si no hay marca → sin stdout. Siempre `exit 0`.

- [ ] **Step 1: Escribir los tests (deben fallar: el script no existe)**

Run:
```bash
cd "$CLAUDE_PROJECT_DIR" 2>/dev/null || cd .
T=/tmp/spi-stop-tests.sh
cat > "$T" <<'EOF'
set -u
S=.claude/hooks/spi-review-stop.sh
pass=0; fail=0
chk(){ if eval "$2"; then echo "PASS: $1"; pass=$((pass+1)); else echo "FAIL: $1"; fail=$((fail+1)); fi; }

# Caso A: con marca -> systemMessage menciona spi-implementation-reviewer y borra la marca
M="${TMPDIR:-/tmp}/spi-review-TESTSESS.touched"
printf '/p/src/Service/Foo.php\n/p/templates/Assets/index.php\n' > "$M"
outA=$(echo '{"session_id":"TESTSESS"}' | bash "$S")
chk "con marca -> spi-implementation-reviewer" 'echo "$outA" | jq -e ".systemMessage | test(\"spi-implementation-reviewer\")" >/dev/null'
chk "marca consumida (borrada)" '[ ! -f "$M" ]'

# Caso B: sin marca -> sin salida, exit 0
outB=$(echo '{"session_id":"NOPE"}' | bash "$S"); rc=$?
chk "sin marca -> sin salida" '[ -z "$outB" ]'
chk "sin marca -> exit 0" "[ $rc -eq 0 ]"

echo "RESULT pass=$pass fail=$fail"
EOF
bash "$T"
```
Expected: líneas `FAIL:` y `RESULT pass=0 fail=4` (script inexistente).

- [ ] **Step 2: Crear el script**

````bash
#!/usr/bin/env bash
# Stop: si en la sesión se tocó implementación, emite UN recordatorio consolidado.
# No bloqueante: nunca usa decision:block; siempre exit 0.

INPUT=$(cat)
SESSION=$(printf '%s' "$INPUT" | jq -r '.session_id // "nosession"' 2>/dev/null)
MARK="${TMPDIR:-/tmp}/spi-review-${SESSION}.touched"

if [ -f "$MARK" ]; then
  COUNT=$(sort -u "$MARK" 2>/dev/null | grep -c . 2>/dev/null || echo 0)
  rm -f "$MARK"
  MSG="Recordatorio SPI: en esta sesión se tocaron ${COUNT} archivo(s) de implementación (src/templates/migraciones). Antes de dar por cerrado el trabajo, lanzá el subagente 'spi-implementation-reviewer' sobre el diff (git diff) para revisar convenciones, anti-drift de vista, RBAC y fidelidad al plan."
  jq -n --arg m "$MSG" '{systemMessage: $m}'
fi

exit 0
````

- [ ] **Step 3: Correr los tests (deben pasar)**

Run:
```bash
bash /tmp/spi-stop-tests.sh
```
Expected: `RESULT pass=4 fail=0`.

- [ ] **Step 4: Commit**

```bash
git add .claude/hooks/spi-review-stop.sh
git commit -m "chore: hook Stop spi-review-stop (recordatorio consolidado de implementación)"
```

---

## Task 6: Registrar los hooks en `.claude/settings.json`

**Files:**
- Create: `.claude/settings.json`

**Interfaces:**
- Consumes: los scripts de Task 4 y Task 5 (paths relativos `bash .claude/hooks/<script>.sh`).

- [ ] **Step 1: Crear `.claude/settings.json` con el registro de hooks**

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "bash .claude/hooks/spi-review-postedit.sh"
          }
        ]
      }
    ],
    "Stop": [
      {
        "matcher": "*",
        "hooks": [
          {
            "type": "command",
            "command": "bash .claude/hooks/spi-review-stop.sh"
          }
        ]
      }
    ]
  }
}
```

- [ ] **Step 2: Validar que el JSON es correcto y los comandos resuelven**

Run:
```bash
cd "$CLAUDE_PROJECT_DIR" 2>/dev/null || cd .
jq -e '.hooks.PostToolUse[0].matcher=="Write|Edit" and .hooks.Stop[0].matcher=="*"' .claude/settings.json >/dev/null && echo "settings.json válido OK"
# dry-run de los comandos exactos que invocará Claude Code:
echo '{"session_id":"DRY","tool_input":{"file_path":"/p/docs/superpowers/plans/x.md"}}' | bash .claude/hooks/spi-review-postedit.sh | jq -e '.hookSpecificOutput.additionalContext' >/dev/null && echo "command PostToolUse OK"
echo '{"session_id":"DRY"}' | bash .claude/hooks/spi-review-stop.sh >/dev/null && echo "command Stop OK"
```
Expected: `settings.json válido OK`, `command PostToolUse OK`, `command Stop OK`.

- [ ] **Step 3: Confirmar que se versiona (no git-ignored)**

Run:
```bash
git check-ignore .claude/settings.json && echo "IGNORADO (ajustar .gitignore)" || echo "se versiona OK"
```
Expected: `se versiona OK`.

- [ ] **Step 4: Commit**

```bash
git add .claude/settings.json
git commit -m "chore: registrar hooks de revisión SPI (PostToolUse + Stop) en settings.json"
```

---

## Task 7: Verificación end-to-end y activación

**Files:** (ninguno nuevo; valida lo construido)

> **Nota de activación:** los hooks de `settings.json` se cargan al **iniciar** la sesión de Claude Code. Tras Task 6, hay que **reiniciar la sesión** (o usar `/hooks` para recargar/confirmar) para que los recordatorios se disparen en vivo. Los Steps 1-2 abajo no dependen de la recarga; el Step 3 sí.

- [ ] **Step 1: Smoke funcional de los 3 agentes (en sesión principal con acceso al tool Agent)**

Invocá cada subagente y confirmá que (a) NO modifica archivos, (b) devuelve hallazgos en el formato `[SEVERIDAD] … — convención` con un **Veredicto** final:
- `spi-design-reviewer` sobre `docs/superpowers/specs/2026-06-18-itam-inventario-activos-spi-design.md`.
- `spi-plan-reviewer` sobre `docs/superpowers/plans/2026-06-19-itam-tic-inventario-web-spi.md` (+ su spec).
- `spi-implementation-reviewer` sobre el `git diff` actual (o un módulo reciente).

Expected: cada uno produce un reporte estructurado con severidades y veredicto; ninguno intenta editar.

- [ ] **Step 2: Confirmar que todo está commiteado y limpio**

Run:
```bash
git status --short
ls .claude/agents/ .claude/hooks/
```
Expected: sin cambios pendientes de los archivos nuevos; los 3 agentes y los 2 scripts listados.

- [ ] **Step 3: Prueba en vivo del hook (tras reiniciar la sesión de Claude Code)**

1. Reiniciá la sesión para cargar los hooks.
2. Editá/guardá un archivo bajo `docs/superpowers/specs/*-design.md` → debe aparecer el recordatorio de `spi-design-reviewer`.
3. Editá un archivo bajo `src/**` y cerrá el turno → debe aparecer **un** recordatorio consolidado de `spi-implementation-reviewer`; verificá que NO se repite en el turno siguiente si no tocás implementación (la marca se consumió).

Expected: recordatorios visibles en los momentos correctos; sin bloqueos.

---

## Notas de implementación

- **Windows/Git Bash:** los scripts usan `jq` (1.8.1 disponible) y se invocan como `bash .claude/hooks/<script>.sh` (path relativo a la raíz del proyecto → evita backslashes de `${CLAUDE_PROJECT_DIR}` y no requiere bit de ejecución).
- **Marca de estado:** un archivo plano por sesión en `${TMPDIR:-/tmp}`. Idempotente (append + `sort -u` al consumir). Si el Stop no corre (cierre abrupto), la marca queda como archivo temporal inocuo que el SO limpia.
- **No se toca `settings.local.json`** (vacío, no versionado).
