# Refactorización del Módulo de Novedades

**Fecha:** 2026-03-24

## Objetivo

Refactorizar el módulo de novedades para:
1. Unificar etapas del pipeline (todas obligatorias, sin flags por tipo)
2. Agregar etapa de aprobación del jefe inmediato vía token externo (como facturas)
3. Separar firmas del empleado en dos momentos independientes con flags por tipo
4. Eliminar firma del jefe inmediato de la etapa de firmas de liquidación
5. Renombrar "Firmas y Aprobación" → "Revisión y Firmas de documentos"

---

## 1. Pipeline y Estados

### Nuevo Pipeline (7 etapas + rechazo)

```
Aprobación → RRHH → Contabilidad → Revisión y Firmas de documentos → GDP → Tesorería → Pagada
```

- **Aprobación** es la única etapa condicional: se salta si `requires_boss_approval = false` en el tipo de novedad.
- Las demás 6 etapas son obligatorias para todos los tipos.
- Se eliminan los flags `requires_rrhh`, `requires_contabilidad`, `requires_firmas`, `requires_gdp`, `requires_tesoreria` de `novelty_types`.
- Se renombra `firmas_aprobacion` → `revision_firmas` en código. Label visible: "Revisión y Firmas de documentos".

### Flujo de Aprobación

1. RRHH crea la novedad. Si el tipo tiene `requires_boss_approval = true`, RRHH selecciona un aprobador de un select (lista de aprobadores activos de la tabla `approvers`). La novedad inicia en estado `aprobacion`.
2. Se genera token SHA256 vía `ApprovalTokenService` (TTL 48h) y se envía email al aprobador seleccionado.
3. Si el jefe **aprueba** vía token → la novedad avanza a `rrhh`, se setean `approved_by` y `approved_at`.
4. Si el jefe **rechaza** vía token → la novedad se queda en `aprobacion` marcada como rechazada (`area_approval = 'Rechazada'`). RRHH puede editar y reenviar el token (genera nuevo token, invalida anterior).
5. Si el tipo NO requiere aprobación → la novedad inicia directamente en `rrhh`.

---

## 2. Tipos de Novedad (novelty_types)

### Campos que se eliminan

- `requires_rrhh`
- `requires_contabilidad`
- `requires_firmas`
- `requires_gdp`
- `requires_tesoreria`

### Campos que se agregan

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `requires_boss_approval` | boolean, default false, not null | Si requiere aprobación del jefe inmediato (etapa Aprobación + token externo) |
| `requires_employee_signature_creation` | boolean, default false, not null | Si se pide firma del empleado al crear la novedad |
| `requires_employee_signature_review` | boolean, default false, not null | Si se pide firma del empleado en la etapa "Revisión y Firmas de documentos" (documento de liquidación) |

### UI de NoveltyTypes (add/edit)

El formulario reemplaza las 5 checkboxes de etapas por 3 checkboxes nuevas:

- "¿Requiere aprobación del jefe inmediato?"
- "¿Requiere firma del empleado al crear?"
- "¿Requiere firma del empleado en revisión de documentos?"

Los demás campos del tipo permanecen igual.

---

## 3. Firmas y Liquidación

### Firma del empleado al crear la novedad

- Se mantiene el campo `employee_signature` en `employee_novelties`.
- Se muestra el widget de firma en `add()` solo si el tipo tiene `requires_employee_signature_creation = true`.
- Se elimina el campo `coordinator_signature` de `employee_novelties`.

### Firmas en etapa "Revisión y Firmas de documentos"

Los `SIGNER_TYPES` pasan de 4 a 3 posibles:

| Tipo | Siempre requerido |
|------|-------------------|
| `contador` | Sí |
| `coordinador_admin` | Sí |
| `trabajador` | Solo si `requires_employee_signature_review = true` |

- Se elimina `jefe_inmediato` de `SIGNER_TYPES`.
- Al asignar novedad a documento de liquidación, se crean 2 o 3 slots de firma según el flag del tipo.
- La validación para avanzar desde `revision_firmas` exige que todos los slots creados tengan `signature_path` no nulo.
- Se elimina el vínculo de "reusar firma existente" (`use_existing_path`): cada firma es independiente.

---

## 4. Cambios en Base de Datos

### Migración 1: Modificar `novelty_types`

**Eliminar columnas:**
- `requires_rrhh`, `requires_contabilidad`, `requires_firmas`, `requires_gdp`, `requires_tesoreria`

**Agregar columnas:**
- `requires_boss_approval` (boolean, default false, not null)
- `requires_employee_signature_creation` (boolean, default false, not null)
- `requires_employee_signature_review` (boolean, default false, not null)

### Migración 2: Modificar `employee_novelties`

**Eliminar columna:**
- `coordinator_signature`

**Agregar columna:**
- `approver_id` (integer, nullable, FK → `users.id`)

> `approved_by` y `approved_at` ya existen y se reutilizan.

### Migración 3: Limpiar `novelty_liquidation_signatures`

- Eliminar registros existentes con `signer_type = 'jefe_inmediato'`.
- Actualizar validación en el modelo para aceptar solo 3 tipos.

---

## 5. Cambios en Servicios

### NoveltyPipelineService

- **`TRANSITIONS`**: Agregar `aprobacion → rrhh` al inicio.
- **`getNextStatus()`**: No consulta flags de etapas. Única excepción: si `requires_boss_approval = false`, salta `aprobacion`.
- **`getEffectiveStatuses()`**: 7 etapas si requiere aprobación, 6 si no.
- **`validateTransition()`**: Para `aprobacion`: requiere `approver_id` asignado y no rechazada.
- **`getVisibleFields()`**: Simplificar — ya no depende de flags de etapas.

### Integración con ApprovalTokenService

- Reutilizar/extender `ApprovalTokenService` para soportar novedades.
- Al crear novedad con tipo que requiere aprobación → generar token, enviar email.
- Endpoint público `/novelty-approval/{token}` con layout `external.php`.
- Aprobar → `approved_by`, `approved_at`, avanza a `rrhh`.
- Rechazar → `area_approval = 'Rechazada'`, se queda en `aprobacion`.
- RRHH puede reenviar token (nuevo token, invalida anterior).

### NoveltyConstants

- Renombrar `STATUS_FIRMAS_APROBACION` → `STATUS_REVISION_FIRMAS` (valor: `'revision_firmas'`).
- Agregar `STATUS_APROBACION = 'aprobacion'`.
- Actualizar `PIPELINE_STATUSES`, `STATUS_LABELS`, `STATUS_ICONS`, `TRANSITIONS`.
- Reducir `SIGNER_TYPES` de 4 a 3 (eliminar `jefe_inmediato`).

---

## 6. Cambios en Controladores y Vistas

### EmployeeNoveltiesController

**`add()`:**
- Select de aprobadores (lista de `approvers` activos) — visible dinámicamente si el tipo tiene `requires_boss_approval = true` (vía `getFlags`).
- Widget de firma del empleado solo si `requires_employee_signature_creation = true` (dinámico).
- Al guardar: si requiere aprobación → estado `aprobacion`, generar token, enviar email. Si no → estado `rrhh`.
- Eliminar lógica de `coordinator_signature`.

**`edit()`:**
- Si estado `aprobacion` y rechazada → RRHH puede editar + botón "Reenviar para aprobación" (nuevo token).
- Eliminar lógica de flags de etapas para campos visibles.

### NoveltyTypesController

**`add()` / `edit()`:**
- Reemplazar 5 checkboxes de etapas por 3 nuevas.
- Actualizar `getFlags()` para retornar los 3 nuevos flags.

### NoveltyLiquidationDocsController

**`edit()`:**
- Eliminar widget/opción de firma `jefe_inmediato`.
- Firma `trabajador` solo si `requires_employee_signature_review = true`.
- Eliminar opción "reusar firma existente" (`use_existing_path`).

**`addSignature()`:**
- Validar solo 3 `signer_type` válidos.
- Eliminar lógica de `use_existing_path`.

### Nuevo endpoint para aprobación externa

- Ruta pública `/novelty-approval/{token}`.
- Vista con resumen de la novedad + botones Aprobar/Rechazar.
- Layout `external.php`.

---

## 7. Resumen de eliminaciones y adiciones

### Se elimina

| Elemento | Motivo |
|----------|--------|
| 5 flags de etapas en `novelty_types` | Todas las etapas son obligatorias |
| Lógica de salto de etapas en `getNextStatus()` | Ya no aplica |
| `coordinator_signature` en `employee_novelties` | En desuso |
| `jefe_inmediato` en `SIGNER_TYPES` | Pasa a aprobación externa |
| Vínculo `use_existing_path` en firmas de liquidación | Firmas independientes |
| Widget de firma jefe inmediato en liquidación edit | Reemplazado por token |

### Se agrega

| Elemento | Motivo |
|----------|--------|
| 3 nuevos flags en `novelty_types` | Control de aprobación y firmas |
| Estado `aprobacion` en pipeline | Nueva etapa inicial condicional |
| `approver_id` en `employee_novelties` | Aprobador seleccionado por RRHH |
| Integración `ApprovalTokenService` para novedades | Token externo para jefe |
| Ruta/vista pública `/novelty-approval/{token}` | Aprobación externa |
| Renombre `firmas_aprobacion` → `revision_firmas` | Nuevo nombre de etapa |

### Sin cambios

- Documentos de liquidación, observaciones, historial, novedades masivas, templates de contrato.
- Pipeline de GDP, Tesorería, Pagada.
- Firmas de `contador` y `coordinador_admin` en liquidación.
- Campos condicionales del tipo (fechas, horarios, custom name, masivo).
