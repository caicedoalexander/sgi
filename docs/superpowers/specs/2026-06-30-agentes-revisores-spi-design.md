# Agentes revisores SPI + hook recordatorio (diseño)

**Fecha:** 2026-06-30
**Estado:** Diseño aprobado — pendiente plan de implementación
**Autor:** Alexander + brainstorming asistido

---

## 1. Resumen

Tres agentes de **solo lectura** (subagentes de Claude Code, versionados en `.claude/agents/`)
que revisan, cada uno, una etapa del flujo de trabajo del repo (superpowers):

| Etapa | Artefacto | Revisor |
|---|---|---|
| Diseño | `docs/superpowers/specs/*-design.md` | `spi-design-reviewer` |
| Plan | `docs/superpowers/plans/*.md` | `spi-plan-reviewer` |
| Implementación | `src/**`, `templates/**`, `config/Migrations/**` | `spi-implementation-reviewer` |

Más un **hook híbrido** que recuerda lanzar el revisor correspondiente en el momento justo, sin bloquear.

### Principio rector — los agentes son un "lente", no una copia de las reglas

El conocimiento de convenciones SPI tiene **una sola fuente**: `CLAUDE.md` + `docs/design/*` + el
spec/plan asociado. Los agentes **no duplican** esas reglas en su system prompt; las **leen** y
contrastan el artefacto contra ellas. Cuando `CLAUDE.md` cambia, los revisores quedan al día solos.
Esto es coherente con la regla anti-drift / fuente única que gobierna todo el repo.

---

## 2. Alcance

### Incluido
- 3 archivos de agente en `.claude/agents/` (design / plan / implementation reviewer).
- 2 scripts de hook en `.claude/hooks/` (PostToolUse + Stop).
- Registro de los hooks en `.claude/settings.json` (versionado, compartido con el equipo).
- Documentación mínima de uso (en este spec; opcionalmente una nota en `CLAUDE.md`).

### Fuera de alcance
- Que los agentes **apliquen** correcciones (son read-only; solo reportan).
- Bloqueo duro del flujo (commits/avances): el hook **recuerda**, no impide.
- Revisión de `tests/**` y assets de `webroot/**` por el hook (no disparan recordatorio en v1).
- Integración con CI/CD (los agentes se lanzan en sesión interactiva; CI sigue con `cs-check` /
  `permissions_audit` como hoy).

---

## 3. Arquitectura general

```
  Editás un artefacto ──► Hook detecta el path ──► Recordatorio (additionalContext, no bloqueante)
                                                         │
                                                         ▼
                                   Claude (sesión) lanza el subagente revisor vía Task/Agent
                                                         │
                          Agente LEE: CLAUDE.md + docs/design + spec/plan/diff
                                                         │
                                                         ▼
                                   Reporta hallazgos priorizados (BLOQUEANTE/ALTO/MEDIO/BAJO)
```

Dirección de dependencia: **hook → recuerda → Claude → lanza agente → lee fuentes vivas**.
Ningún componente duplica las convenciones; todas se leen de su fuente única.

---

## 4. Los tres agentes

Todos: **read-only** (`Read`, `Grep`, `Glob`; `Bash` solo lectura para `git diff` / `cs-check` /
`phpunit` en el de implementación). Heredan el modelo de la sesión. Salida = texto markdown con
hallazgos clasificados `BLOQUEANTE` / `ALTO` / `MEDIO` / `BAJO`, cada uno con ubicación y la
convención violada (con referencia al doc fuente).

### 4.1 `spi-design-reviewer`

- **Cuándo:** tras escribir/actualizar un spec, antes de pasar al plan.
- **Entrada:** el archivo del spec indicado.
- **Lee (lente):** `CLAUDE.md` (Architecture, Paridad de módulos de flujo, estructura canónica
  backend/vista, New Module Checklist), `docs/design/reglas-copy.md` + `fundamentos.md`, specs
  previos como referencia de formato.
- **Verifica:**
  - **Estructura del documento:** resumen, alcance (incluido/fuera), arquitectura, modelo de datos,
    RBAC, capa de vista, criterios de aceptación.
  - **Clasificación del módulo:** ¿flujo/pipeline vs catálogo+log? ¿lo declara y diseña en
    consecuencia (coordinador delgado + States + Policy, o catálogo+servicios+log)?
  - **Convenciones a nivel diseño:** enums fuente única, `ServiceResult`, Table/Entity ORM,
    Presentation/ViewModel, RBAC doble (CRUD + pipeline) cuando aplica.
  - **Acoplamiento con lo existente:** reúso de traits/servicios/elementos compartidos
    (`DocumentUploadTrait`, `pipeline_sidebar`, `BaseFilterService`, `HistoryServiceInterface`…);
    respeto a trampas de datos persistidos (slugs ES/EN, spelling deliberado, módulo `advances`
    vs pipeline `legalizations`); no reinventa algo que ya existe.
  - **Riesgos de diseño:** FK signed/unsigned, almacenamiento de documentos público vs privado,
    estados/transiciones, terminalidad.
- **Salida:** hallazgos priorizados + **huecos del diseño** (decisiones sin tomar) + checklist de
  cobertura de las secciones esperadas.

### 4.2 `spi-plan-reviewer`

- **Cuándo:** tras escribir/actualizar un plan, antes de implementar.
- **Entrada:** el plan + el spec asociado (mismo topic/fecha).
- **Lee:** ese spec, `CLAUDE.md` (New Module Checklist, estructura canónica), el formato de planes
  superpowers (tareas con checkbox + criterios de verificación).
- **Verifica:**
  - **Fidelidad spec↔plan:** cubre todo el alcance del spec; no mete cosas fuera de alcance
    (scope-creep).
  - **Estructura del plan:** tareas atómicas con criterio de verificación explícito; **orden de
    build** correcto (migración → constants/enums → entity → table → service → controller →
    permisos → templates → sidebar → routes); dependencias entre tareas declaradas.
  - **Convenciones referenciadas:** `BaseMigration` (no `AbstractMigration`); tipos de FK; enums
    fuente única; RBAC en las **3** ubicaciones (`$controllerModuleMap`, `AuthorizationService::MODULES`,
    seed en `permissions`); paths de archivos según la estructura canónica.
  - **Reúso vs reinvención**; respeto a las **excepciones de dominio (B)** sin "migrar a medias".
  - **Testabilidad:** existen tareas de test; criterios de aceptación claros.
  - **Riesgos de orden/CI:** algo que rompa `cs-check` o `permissions_audit`.
- **Salida:** hallazgos priorizados + **matriz de cobertura spec→plan** (cada requisito del spec →
  tarea(s) que lo cubren; qué queda sin cubrir) + riesgos de dependencias/orden.

### 4.3 `spi-implementation-reviewer`

- **Cuándo:** tras implementar (recordatorio consolidado del Stop hook) o invocación manual.
- **Entrada:** `git diff` (branch vs `main`, o cambios sin commitear) + el plan/spec asociado como
  criterio de aceptación, si existe.
- **Lee:** el diff, `CLAUDE.md`, `docs/design/*` del área tocada, el plan/spec.
- **Verifica:**
  - **Convenciones de código:** enums fuente única (no strings de estado hardcodeados);
    `ServiceResult` con chequeo de `->success`; tablas vía `TableRegistry`, nunca `$this->Table`;
    métodos privados con `_`; inyección con params nullable + `?? new`; `BaseMigration`; tipos de
    FK; paginación fija 15.
  - **Anti-drift de vista:** mapeo estado→pill/icono **solo** en `{Modulo}Presentation` (const);
    dirección VM→Presentation; sin literales inline de pills; átomos `.spi-*` no hechos a mano;
    esqueletos canónicos INDEX/EDIT/VIEW.
  - **RBAC:** `#[Permission]` / `$controllerModuleMap`; doble tabla de permisos; invariante
    "operar implica ver"; admin bypass acotado a `users`/`roles`; `FieldAccessPolicy` rol-aware
    (hereda de `PipelineFieldPolicy`, no `unset($roleId)`).
  - **Trampas de datos persistidos:** no "corregir" spelling/slugs deliberados ni valores
    persistidos sin migración.
  - **Acoplamiento / paridad:** sigue el patrón canónico del módulo; se acopla a servicios y
    elementos compartidos; no introduce duplicación.
  - **Fidelidad al plan:** qué tareas se implementaron, cuáles faltan, desvíos respecto del plan.
  - **Calidad general** (de las guías globales): funciones <50 líneas, archivos <800, sin
    anidamiento profundo, manejo de errores, sin secrets ni statements de debug.
  - Corre `composer cs-check` y `vendor/bin/phpunit` si están disponibles (lectura/diagnóstico).
- **Salida:** hallazgos con `archivo:línea` + convención violada + referencia al doc fuente +
  **veredicto** apto / no-apto para commit.

---

## 5. El hook (híbrido, no bloqueante)

### 5.1 Comportamiento

- **PostToolUse** sobre `Write|Edit`:
  - path matchea `docs/superpowers/specs/*-design.md` → recordatorio: *"Spec creado/editado. Lanzá
    `spi-design-reviewer` antes de pasar al plan."*
  - path matchea `docs/superpowers/plans/*.md` → recordatorio: *"Plan creado/editado. Lanzá
    `spi-plan-reviewer` antes de implementar."*
  - path cae en `src/**`, `templates/**` o `config/Migrations/**` → **no** emite recordatorio
    inmediato; deja una **marca de estado** (ver 5.2).
  - Mecanismo: inyecta `additionalContext` (sugerencia para Claude), `exit 0` (no bloquea).
- **Stop:**
  - Si existe la marca de estado de implementación para la sesión → emite **un** recordatorio
    consolidado: *"Se tocó código de implementación en esta sesión. Lanzá
    `spi-implementation-reviewer` sobre el diff antes de dar por cerrado."* y **borra la marca**.
  - Si no hay marca → no dice nada.

### 5.2 Marca de estado (cómo el Stop sabe qué se tocó)

En lugar de parsear el transcript, el PostToolUse escribe una marca por sesión cuando el path es de
implementación; el Stop la lee y la borra. Diseño:

- Archivo de marca: en el directorio temporal del sistema, nombrado por `session_id`
  (p. ej. `<tmp>/spi-review/<session_id>.touched`). El path exacto lo fija el plan.
- Ambos hooks reciben `session_id` por stdin → comparten la misma marca.
- Idempotente: si ya existe, no se duplica; el Stop la consume (borra) tras emitir el aviso.

### 5.3 Configuración

- Registrado en `.claude/settings.json` (versionado): bloques `hooks.PostToolUse` (matcher
  `Write|Edit`) y `hooks.Stop`.
- Scripts en `.claude/hooks/`, en **Bash** (Git Bash disponible; portable). Parsean el JSON de
  stdin (path y `session_id`) sin dependencias externas si es viable.

---

## 6. Archivos a crear

```
.claude/agents/spi-design-reviewer.md
.claude/agents/spi-plan-reviewer.md
.claude/agents/spi-implementation-reviewer.md
.claude/hooks/spi-review-postedit.sh    ← PostToolUse: recordatorio spec/plan + marca impl
.claude/hooks/spi-review-stop.sh         ← Stop: recordatorio consolidado de implementación
.claude/settings.json                    ← registra ambos hooks (versionado)
```

`.claude/settings.local.json` (hoy vacío, no versionado) se deja como está.

---

## 7. Decisiones de diseño

1. **Read-only:** los agentes solo reportan; mantiene separación revisor/implementador y es seguro.
2. **Lente sobre docs vivos:** cero duplicación de convenciones → cero drift.
3. **Hook híbrido:** PostToolUse puntual para spec/plan (evento único por archivo); Stop
   consolidado para implementación (evita spam por cada `Edit` en `src/**`).
4. **No bloqueante:** el hook recuerda; vos decidís. No frena commits ni avances de pipeline.
5. **Versionado en el repo:** agentes y hooks viven en `.claude/` versionado → el equipo los hereda.
6. **Nombres `spi-*`:** alineados con el prefijo de dominio (`.spi-`). (Ajustable a `sgi-`.)
7. **Paths de implementación que disparan el Stop:** `src/**`, `templates/**`, `config/Migrations/**`.
   `tests/**` y `webroot/**` quedan fuera en v1.
8. **Modelo:** heredado de la sesión; no se fuerza por agente.

---

## 8. Criterios de aceptación

- [ ] Existen los 3 agentes en `.claude/agents/`, cada uno read-only, con instrucción explícita de
      leer las fuentes vivas (CLAUDE.md + docs/design + artefacto) y reportar con las 4 severidades.
- [ ] Al guardar un spec en `docs/superpowers/specs/*-design.md`, aparece el recordatorio de
      `spi-design-reviewer`.
- [ ] Al guardar un plan en `docs/superpowers/plans/*.md`, aparece el recordatorio de
      `spi-plan-reviewer`.
- [ ] Tras editar uno o más archivos en `src/**` / `templates/**` / `config/Migrations/**`, al
      cerrar el turno aparece **un solo** recordatorio consolidado de `spi-implementation-reviewer`,
      y la marca se borra (no se repite si el turno siguiente no toca implementación).
- [ ] Lanzado manualmente sobre un diff con una violación conocida (p. ej. estado hardcodeado o pill
      inline), `spi-implementation-reviewer` la reporta como `BLOQUEANTE`/`ALTO` con `archivo:línea`
      y la convención citada.
- [ ] Los hooks no bloquean ninguna operación (siempre `exit 0`).

---

## 9. Cuestiones abiertas

Ninguna crítica. Ajustes menores posibles tras revisión: prefijo de nombres (`spi-`/`sgi-`),
inclusión de `tests/**` en el disparador de implementación, y lenguaje de los scripts del hook
(Bash vs PowerShell) si el equipo es 100% Windows nativo.
