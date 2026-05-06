# Plan A — Resolución de deuda concreta de la auditoría de Constants

**Fecha:** 2026-05-06
**Auditoría base:** [`docs/audits/constants-structure-audit-2026-05-06.md`](../audits/constants-structure-audit-2026-05-06.md)
**Alcance:** ítems pendientes M3, M4, M6, m6 — bugs y deuda técnica concreta, sin refactor arquitectónico de capas.

---

## 1. Contexto

Tras resolver C1 (unificación léxica `autorizacion_pago`/`pagada`), C2 (trait `GroupingPipelineConstantsTrait`), M5 (estilo `self::*`) y re-evaluar C3 (no procede), quedan dos categorías de pendientes:

- **Bugs/deuda concreta**: M3, M4, M6, m6 → este plan.
- **Refactor arquitectónico** (M1, M2 restante, S3) y **migración estratégica** (S1) → fuera de alcance, postergados hasta que se justifique.

El razonamiento: los ítems de este plan son riesgos hoy (drift visible al usuario, acoplamiento a PKs de seed, duplicación accidental). Los de refactor arquitectónico son estéticos hasta que duelan.

---

## 2. Decisiones de brainstorming

| Ítem | Decisión | Razón |
|---|---|---|
| **M3** label canónico | **A — `'Autorización de pago'` (forma larga)** | Más descriptivo, consistente con la denominación de `PipelineStepConstants::STEP_LABELS`. La forma corta `'Aut. Pago'` es jerga interna. Si un layout se rompe, se resuelve con `text-truncate`+`title`, no manteniendo dos vocabularios. |
| **M4** desacople de PKs | **B — Drop tabla, columna string en `employees`**, con gate de auditoría previa | Solo hay 2 valores estables (activo/retirado). Si la tabla no tiene metadatos asociados ni FKs entrantes, el fix radical es más limpio. Gate previo evita decisión a ciegas. |
| **M6** alcance | **B — Auditar los 3 pipeline services** y mover constantes donde haya duplicación | Costo bajo, deja los pipelines con topología consistente, alineado con C2. |
| **m6** observation types | **A — Promover a `ObservationConstants::TYPE_*`** | Los 4 pipelines hoy tienen exactamente los mismos dos tipos (`general`, `regression`); no hay variación de dominio real, solo duplicación accidental. |

---

## 3. Plan de ejecución

Tres PRs independientes desde `main`, mergeados en orden. Cada uno commitable y validable por separado.

### PR #1 — `refactor/constants-cleanup` (m6 + M6)

**Riesgo:** Bajo. **Esfuerzo:** 1–2 h.

**m6 — Promover `OBSERVATION_TYPE_*` a `ObservationConstants`**

1. Grep `OBSERVATION_TYPE_` en `src/Constants/` → catalogar duplicados (esperado: `general`, `regression` en Invoice, PaymentScheduling, `GroupingPipelineConstantsTrait`, posibles en Novelty/Advance).
2. Agregar a `ObservationConstants`: `TYPE_GENERAL = 'general'`, `TYPE_REGRESSION = 'regression'`, `TYPES = [...]`.
3. En cada `*Constants` con copias: redefinir como referencia a `ObservationConstants::TYPE_*` manteniendo los nombres locales (`OBSERVATION_TYPE_GENERAL = ObservationConstants::TYPE_GENERAL`) para preservar API y eliminar el string duplicado.
4. `composer cs-check`.

**M6 — Mover `ALL_STATUSES` / `TRANSITIONS` de Pipeline Services a `*Constants`**

1. Auditar 3 services: `Grep "public const ALL_STATUSES|public const TRANSITIONS"` en `src/Service/*PipelineService.php`.
2. Para cada service afectado: mover ambas constantes al `*Constants` correspondiente. Dejar alias `Service::ALL_STATUSES = XxxConstants::ALL_STATUSES` si hay muchos call-sites externos, o reemplazar todos los call-sites si son pocos.
3. `InvoicesTable.php:214` debe leer de `InvoiceConstants::ALL_STATUSES` directamente.
4. `composer cs-check`.

**Validación manual:**
- `php bin/cake server`
- Cargar `index` + `edit` de cada estado del pipeline → sin errores de constantes indefinidas.
- Crear observación tipo "general" en Invoice y PettyCash → persiste igual.

**Commit:** `refactor(constants): centralize observation types and pipeline status arrays`

---

### PR #2 — `refactor/label-canonicalization` (M3)

**Riesgo:** Medio (visible en UI). **Esfuerzo:** 1 h.

1. **Inventario**: Grep de `'Aut. Pago'` y `'Autorización de pago'` en `src/Constants/`, `src/Service/`, `templates/`.
2. **Cambios**: reemplazar `'Aut. Pago'` → `'Autorización de pago'` en cada `*Constants::STATUS_LABELS` (incluyendo `GroupingPipelineConstantsTrait`). Templates con string hardcodeado: usar la constante si es trivial, si no, cambiar el literal.
3. **Verificación visual obligatoria** antes de commit:
   - `pipeline_progress.php` (facturas + variantes legalization/petty_cash).
   - Index de los 6 pipelines: la columna estado debe leerse en pantalla normal y móvil.
   - Vista `view` en cada pipeline.
   - Si un layout se rompe: agregar `text-truncate` + `title="..."`, **no** retroceder a forma corta.
4. **Riesgo a verificar**: queries que filtren/agrupen por el string del label (no por slug). Confianza alta de que no existen — toda la lógica usa el slug `'autorizacion_pago'`. Confirmar con Grep antes de tocar.
5. `composer cs-check`.

**Validación manual:**
- Recorrer cada pipeline y confirmar que `'Aut. Pago'` no aparece en ninguna vista.
- `*Constants::STATUS_LABELS` y `PipelineStepConstants::STEP_LABELS` ahora coinciden.

**Commit:** `refactor(constants): canonicalize 'Autorización de pago' label across pipelines`

---

### PR #3 — `refactor/employee-status-enum` (M4)

**Riesgo:** Alto (migración destructiva). **Esfuerzo:** ½ día.

#### Fase 1 — Auditoría previa (read-only)

1. `Grep EmployeeStatusConstants::` → catalogar call-sites.
2. `Grep employee_status_id` → listar Tables/Entities/queries afectadas.
3. Inspeccionar schema: `DESCRIBE employee_statuses` + `SHOW CREATE TABLE` para campos extra y FKs entrantes.
4. `SELECT employee_status_id, COUNT(*) FROM employees GROUP BY employee_status_id` → confirmar solo {1, 2} en uso.

#### Fase 2 — Gate de decisión

| Hallazgo | Acción |
|---|---|
| Tabla solo `id`, `name`, timestamps; solo 2 valores en uso | ✅ Proceder |
| Tabla tiene metadatos (color, descripción, orden) | ⚠️ Pausa — reabrir M4 (probablemente fix A: agregar `code` slug) |
| FKs entrantes desde otras tablas | ⚠️ Pausa — alcance se amplía |
| Valores en `employees.employee_status_id` fuera de {1, 2} | ⚠️ Pausa — investigar |

Si pausa: reportar hallazgo y volver a brainstorming antes de seguir.

#### Fase 3 — Migración

`AddStatusStringToEmployees`:
1. `ALTER TABLE employees ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'activo' AFTER employee_status_id`.
2. `UPDATE employees SET status = CASE employee_status_id WHEN 1 THEN 'activo' WHEN 2 THEN 'retirado' END`.
3. Verificar que ningún `status` quedó vacío.
4. `ALTER TABLE employees DROP FOREIGN KEY ...; DROP COLUMN employee_status_id`.
5. `DROP TABLE employee_statuses`.

`down()` reconstruye tabla, FK, columna y backfill inverso.

#### Fase 4 — Código

- `EmployeeStatusConstants`: `ACTIVO = 'activo'`, `RETIRADO = 'retirado'`, `STATUSES`, `STATUS_LABELS`.
- `Employee` entity: métodos `isActivo()`/etc. siguen funcionando con la nueva columna string.
- `EmployeesTable`: quitar `belongsTo('EmployeeStatuses')`, ajustar finders y validación (`->inList('status', EmployeeStatusConstants::STATUSES)`).
- Templates: `$employee->employee_status->name` → `EmployeeStatusConstants::STATUS_LABELS[$employee->status]`.
- Borrar `EmployeeStatusesController`, `EmployeeStatusesTable`, `EmployeeStatus` entity, templates, y entrada en `permissions` si existía.

#### Fase 5 — Validación manual

- Listado, crear, editar empleados.
- Filtros de `EmployeesController::index()` por estado.
- Ciclo `migrations rollback` + `migrations migrate` sin errores.

**Commit:** `refactor(employees): replace employee_statuses table with status enum column`

---

## 4. Out of scope (postergado)

| Ítem | Razón |
|---|---|
| **M1** mezcla dominio/presentación | Refactor arquitectónico; sin dolor productivo concreto hoy. |
| **M2** restante (god-array `PIPELINE_STATUS_BADGES`) | Aliases ya eliminados al resolver C1. Resto requiere S3. |
| **m1** mezcla idiomas inglés/español | Convivencia funcional; decidir convención requiere otro brainstorming. |
| **m2** orden de constantes en `InvoiceConstants` | Estético. |
| **m3** `DIAN_STATUSES` con literal `'Pendiente'` | Local a Invoice. |
| **m4** `READY_FOR_PAYMENT_OPTIONS` magic strings | Local; bajo impacto. |
| **m5** semántica `NoveltyConstants::ACTIVE_STATUSES` | PHPDoc, no refactor. |
| **m7** `PipelineStepConstants::isValid()` estático | Estilístico. |
| **S1** migración a enums PHP 8.1+ | Esfuerzo alto, beneficio difuso. Re-evaluar caso por caso si se justifica. |
| **S3** subdirectorios `Domain/`+`Presentation/` | Atado a M1. |

Reabrir cuando: aparezca un séptimo pipeline, se haga un cambio de tema visual que rompa la mezcla M1, o se sume un módulo que requiera variación de tipos de observación que justifique S1.

---

## 5. Criterios de validación global

Tras los 3 merges en `main`:

1. **Grep negativo (= 0 resultados):**
   - `'aut_pago'` (string literal)
   - `'pagado'` (string literal en contexto pipeline)
   - `'Aut. Pago'`
   - `EmployeeStatusConstants::ACTIVO = 1` (PK literal)
   - `OBSERVATION_TYPE_GENERAL = 'general'` en archivos distintos a `ObservationConstants`
2. **Grep positivo:**
   - `ObservationConstants::TYPE_GENERAL` aparece donde antes estaban las copias.
   - `*Constants::ALL_STATUSES` aparece donde antes estaba `*PipelineService::ALL_STATUSES`.
3. **Smoke test funcional:** los 6 pipelines (Invoice, Novelty, PaymentScheduling, PettyCash, Refund, Advance) abren `index` + `view` + `edit` + crean observación sin errores.
4. **Smoke test empleados:** listado, crear, editar, filtrar por estado.
5. `composer cs-check` limpio.

---

## 6. Estrategia de ramas

```
main
 ├─ refactor/constants-cleanup       ← PR #1 (m6 + M6)
 ├─ refactor/label-canonicalization  ← PR #2 (M3)        — desde main tras merge de #1
 └─ refactor/employee-status-enum    ← PR #3 (M4)        — desde main tras merge de #2
```

Cada PR independiente; si uno se bloquea, los demás avanzan. M4 explícitamente al final por riesgo de schema.
