# Employee History & Observations Design

**Date:** 2026-03-18
**Purpose:** Add audit trail (change history) and threaded observations to the Employees module, replicating existing patterns from Invoices.

---

## Motivation

- **Auditoría**: Se necesita trazabilidad de todos los cambios realizados a los datos de empleados (quién cambió qué, cuándo, valor anterior y nuevo).
- **Observaciones**: El campo `notes` actual es texto plano sin atribución. Se necesita un sistema de observaciones con registro de usuario y fecha, igual que en facturas.

---

## Database

### Table: `employee_histories`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT, PK, AUTO_INCREMENT | — |
| `employee_id` | INT, FK → employees (CASCADE DELETE) | Empleado modificado |
| `user_id` | INT, FK → users (RESTRICT DELETE) | Quién hizo el cambio |
| `field_changed` | VARCHAR(100) | Nombre del campo |
| `old_value` | TEXT, nullable | Valor anterior |
| `new_value` | TEXT, nullable | Valor nuevo |
| `created` | DATETIME | Cuándo ocurrió |

### Table: `employee_observations`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT, PK, AUTO_INCREMENT | — |
| `employee_id` | INT, FK → employees (CASCADE DELETE) | Empleado |
| `user_id` | INT, FK → users (RESTRICT DELETE) | Quién escribió |
| `message` | TEXT | Contenido |
| `created` | DATETIME | Cuándo se creó |

### Migration: Remove `notes` column from `employees`

- Migrate non-empty `notes` values to `employee_observations` (user_id=1/Admin, created=employee created date)
- Drop `notes` column after migration

---

## Backend

### New Models

- **EmployeeHistory** (Entity): `$_accessible` = employee_id, user_id, field_changed, old_value, new_value
- **EmployeeHistoriesTable**: belongsTo(Employees, Users), TimestampBehavior (created only)
- **EmployeeObservation** (Entity): `$_accessible` = employee_id, user_id, message
- **EmployeeObservationsTable**: belongsTo(Employees, Users), TimestampBehavior (created only)

### EmployeesTable Changes

- Add `hasMany('EmployeeHistories')` with dependent + cascadeCallbacks
- Add `hasMany('EmployeeObservations')` with dependent + cascadeCallbacks

### New Service: `EmployeeHistoryService`

Replicates `InvoiceHistoryService` pattern:

- Constant `FIELD_LABELS`: ~25 tracked fields with Spanish labels
- `recordChanges(Employee $original, Employee $modified, int $userId)`: field-by-field comparison with normalization (DateTime→Y-m-d, booleans, nulls)
- FK fields (position_id, operation_center_id, etc.) store the ID as value

**Tracked fields (all editable):**
- Personal: document_type, document_number, first_name, last_name1, last_name2, birth_date, gender, marital_status_id, education_level_id
- Contact: email, phone, address, city
- Employment: employee_status_id, position_id, supervisor_position_id, operation_center_id, cost_center_id, hire_date, termination_date, salary, contract_type, temporary_organization_id, vest_number
- Social security: eps, pension_fund, arl, severance_fund

### Controller Changes

**EmployeesController::edit():**
- Before `patchEntity()`: clone original entity
- After successful save: call `historyService->recordChanges($original, $modified, $userId)`

**New action: EmployeesController::addObservation($id):**
- POST-only
- Creates EmployeeObservation with employee_id, user_id, message
- Redirect back to view

**Contain changes in view():**
- Add `EmployeeHistories.Users` and `EmployeeObservations.Users`

---

## Frontend (templates/Employees/view.php)

### Observations Section (replaces current "Observaciones")

- Chat-style thread (same as invoice observations)
- Avatar with user initials
- User full name + date (d/m/Y H:i)
- Message with `nl2br(h())`
- Scrollable container (max-height: 400px)
- Inline form at bottom: textarea + "Agregar" button (POST to addObservation)

### Change History Section (bottom of view)

- Table: Fecha | Usuario | Campo | Valor Anterior | Valor Nuevo
- Field names translated via FIELD_LABELS
- Ordered by created DESC
- Scrollable container

---

## Implementation Steps

1. Migration: create `employee_histories` + `employee_observations` tables
2. Models: Entity + Table for both new models
3. EmployeesTable: add hasMany relationships
4. EmployeeHistoryService: field tracking + comparison logic
5. EmployeesController: integrate history in edit(), add addObservation()
6. Route: add addObservation route if needed
7. templates/Employees/view.php: observations chat + history table
8. Migration: migrate notes data + drop notes column
