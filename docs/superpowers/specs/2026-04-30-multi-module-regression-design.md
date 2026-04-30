# Multi-Module Regression — Design Spec

**Fecha:** 2026-04-30
**Autor:** Alexander Caicedo (con asistencia de Claude)
**Estado:** Diseño aprobado, pendiente de implementación
**Spec previo relacionado:** `docs/superpowers/specs/2026-04-29-invoice-pipeline-regression-design.md`

## 1. Resumen

Replicar el botón "Regresar al paso anterior" (ya implementado en facturas) en los flujos de **Anticipos**, **Caja Menor** y **Programación de Pagos**. La UX se mantiene idéntica: botón en `edit.php`, modal con motivo obligatorio (10–500 caracteres), badge `[Regresión]` con transición visible en `view.php`, persistencia del motivo en la tabla `*_observations` correspondiente con `type='regression'` + `metadata={from_status,to_status}`.

## 2. Decisiones tomadas durante el brainstorming

| # | Decisión |
|---|----------|
| 1 | **Anticipos:** Cero cambios de backend. Solo exponer el mismo botón/modal en `templates/Advances/edit.php` reutilizando `InvoicesController::regressStatus`. |
| 2 | **Caja Menor — alcance:** Regresables `contabilidad → agrupacion`, `tesoreria → contabilidad`, `aut_pago → tesoreria`. Excluido `pagado` por riesgo de inconsistencia con datos colaterales. |
| 3 | **Caja Menor — permisos:** Matriz simétrica al avance (Contabilidad, Tesorería, Contador respectivamente; Admin siempre). |
| 4 | **Caja Menor — propagación a hijas:** SÍ se revierte el `pipeline_status` de las facturas hijas (simétrico al avance bulk). Trazabilidad en `invoice_histories`. |
| 5 | **Caja Menor — bloqueo:** Bloqueo único: regresión de `tesoreria → contabilidad` con pago pendiente registrado (`payment_amount` no nulo). |
| 6 | **Programación — alcance:** Regresables `tesoreria → borrador` y `aut_pago → tesoreria`. Excluido `pagada` (al pasar a `pagada` se crean `invoice_payments` y avanzan facturas hijas). |
| 7 | **Programación — sin bloqueos automáticos** en esta iteración. Los items siguen siendo solo vinculaciones; no hay side effects que limpiar. |
| 8 | **Programación — coexistencia:** El botón coexiste con `canReject` del Contador. Son acciones distintas: rechazo es operación tipada con su propio botón; regresión es manual con motivo libre. |
| 9 | **Persistencia:** Replicar el patrón de `invoice_observations` extendiendo `petty_cash_observations` y `payment_scheduling_observations` con `type` + `metadata`. |
| 10 | **Trazabilidad de status:** Caja Menor reusa `invoice_histories` (porque las hijas se actualizan). Programación NO crea history dedicada. La asimetría entre módulos preexistía. |
| 11 | **Tests:** Solo tests puramente lógicos sin BD. Smoke tests manuales para todo lo demás (alineado con el plan previo). |
| 12 | **Estructura:** Plan único integral con tres áreas. |

## 3. Arquitectura

### 3.1 Anticipos

**No requiere cambios de backend.** Toda la lógica vive en `InvoicePipelineService` y `InvoicesController::regressStatus`, que ya manejan correctamente `document_type='Anticipo'`. El bloqueo "anticipo con legalización iniciada" ya está implementado vía `AdvanceLegalizationService::hasLegalization`.

**Cambios:**

- **`AdvancesController::edit($id)`**: añadir cálculo de variables `canRegress`, `previousStatus`, `regressLockMessage` invocando los métodos del service ya existente, y pasarlas a la vista.
- **`templates/Advances/edit.php`**: pegar los bloques de botón y modal copiando el patrón de `templates/Invoices/edit.php`. El form del modal debe apuntar a `Url->build(['controller' => 'Invoices', 'action' => 'regressStatus', $invoice->id])` para reutilizar la acción existente.
- **`templates/Advances/view.php`**: aplicar el render de observaciones con badge `[Regresión]` y línea de transición. Si la vista no incluye `invoice_observations` hoy, asegurar el `contain` y el bloque de render.

**No se modifican:** routes, `AppController::_actionToPermission`, `_redirectForInvoice` (ya distingue `/advances` por `document_type`), `InvoicePipelineService`, migraciones.

**Restricción potencial:** En Anticipos el estado `autorizacion_pago` se usa para "caso sobrante / refund". Si la regresión `autorizacion_pago → tesoreria` rompe el flujo de refund, restringir el botón en la vista de Anticipos a estados ≤ `tesoreria` (o añadir un bloqueo dedicado en `getRegressionLockMessage` para anticipos). Validar al implementar.

### 3.2 Caja Menor

**Componentes nuevos / modificados:**

- **`PettyCashConstants`**:
  - `OBSERVATION_TYPE_GENERAL = 'general'`
  - `OBSERVATION_TYPE_REGRESSION = 'regression'`
  - `OBSERVATION_TYPES = [...]`
  - `BACKWARD_TRANSITIONS` (mapa de regresión)

- **`PettyCashService`** (no hay PipelineService dedicado):
  - Constante o propiedad `ROLE_VISIBLE_STATUSES` (nueva, derivada del avance actual).
  - `getPreviousStatus(string $currentStatus): ?string`
  - `canRegress(string $roleName, string $currentStatus): bool`
  - `getRegressionLockMessage(PettyCashRecord $record): ?string`
  - `regress(PettyCashRecord $record, string $roleName, int $userId, string $reason): array`

- **Migración** `petty_cash_observations`: añadir `type` (varchar 20, default `general`, NOT NULL) y `metadata` (json nullable). Índice `(petty_cash_record_id, type)`.

- **Entity `PettyCashObservation`** y **Table `PettyCashObservationsTable`**: añadir `type` y `metadata` a `_accessible`; validación condicional de `message` (≥10 chars cuando `type='regression'`).

- **`PettyCashRecordsController::regressStatus($id)`** — endpoint POST.

- **`AppController::_actionToPermission`**: mapear `regressStatus` al grupo `edit` para `PettyCashRecords`.

- **`config/routes.php`**: ruta `/petty-cash-records/regress-status/{id}` antes de `fallbacks()`.

- **Templates**:
  - `templates/PettyCashRecords/edit.php`: botón + modal `regressStatusModal`.
  - `templates/PettyCashRecords/view.php`: badge `[Regresión]` y render del salto de estado en la lista de observaciones.

### 3.3 Programación

**Componentes nuevos / modificados:**

- **`PaymentSchedulingConstants`**:
  - `OBSERVATION_TYPE_GENERAL`, `OBSERVATION_TYPE_REGRESSION`, `OBSERVATION_TYPES`.
  - `BACKWARD_TRANSITIONS`.

- **`PaymentSchedulingPipelineService`**:
  - `getPreviousStatus(string $currentStatus): ?string`
  - `canRegress(string $roleName, string $currentStatus): bool`
  - `getRegressionLockMessage(object $scheduling): ?string` (retorna `null` siempre en esta iteración, presente por simetría)
  - `regress(PaymentScheduling $scheduling, string $roleName, int $userId, string $reason): array`

- **Migración** `payment_scheduling_observations`: añadir `type` y `metadata`. Índice `(payment_scheduling_id, type)`.

- **Entity `PaymentSchedulingObservation`** y **Table** correspondiente: añadir `type` y `metadata`; validación condicional.

- **`PaymentSchedulingsController::regressStatus($id)`** — endpoint POST.

- **`AppController::_actionToPermission`**: mapear `regressStatus` para `PaymentSchedulings`.

- **`config/routes.php`**: ruta `/payment-schedulings/regress-status/{id}`.

- **Templates**:
  - `templates/PaymentSchedulings/edit.php`: botón + modal.
  - `templates/PaymentSchedulings/view.php`: badge + transición.

## 4. Detalle de transiciones inversas

### 4.1 Caja Menor

| Estado actual | Regresa a | Roles autorizados |
|---|---|---|
| `agrupacion` | (ninguno) | — |
| `contabilidad` | `agrupacion` | Contabilidad, Admin |
| `tesoreria` | `contabilidad` | Tesorería, Admin |
| `aut_pago` | `tesoreria` | Contador, Admin |
| `pagado` | (excluido) | — |

### 4.2 Programación

| Estado actual | Regresa a | Roles autorizados |
|---|---|---|
| `borrador` | (ninguno) | — |
| `tesoreria` | `borrador` | Tesorería, Admin |
| `aut_pago` | `tesoreria` | Contador, Admin |
| `pagada` | (excluido) | — |

## 5. Bloqueos automáticos

### 5.1 Caja Menor

| Bloqueo | Mensaje | Aplica a |
|---|---|---|
| `payment_amount` no nulo en estado `tesoreria` | "No se puede regresar a Contabilidad: existe un pago pendiente registrado. Anule o reasigne el pago primero." | `tesoreria → contabilidad` |

(En `aut_pago → tesoreria` el pago pendiente se mantiene tal cual, listo para reintento; **no se bloquea**.)

### 5.2 Programación

Sin bloqueos automáticos en esta iteración. `getRegressionLockMessage` retorna `null` siempre — presente para simetría con el patrón.

## 6. Modelo de datos

### 6.1 Migración `petty_cash_observations`

```php
public function up(): void
{
    $table = $this->table('petty_cash_observations');

    $columns = $table->getColumns();
    $columnNames = array_map(fn($c) => $c->getName(), $columns);

    if (!in_array('type', $columnNames, true)) {
        $table->addColumn('type', 'string', [
            'limit' => 20,
            'default' => 'general',
            'null' => false,
            'after' => 'message',
        ]);
    }

    if (!in_array('metadata', $columnNames, true)) {
        $table->addColumn('metadata', 'json', [
            'null' => true,
            'after' => 'type',
        ]);
    }

    $table->update();

    $indexTable = $this->table('petty_cash_observations');
    if (!$indexTable->hasIndex(['petty_cash_record_id', 'type'])) {
        $indexTable->addIndex(['petty_cash_record_id', 'type'])->update();
    }
}
```

### 6.2 Migración `payment_scheduling_observations`

Idéntica a la anterior, sustituyendo el nombre de tabla y la FK por `payment_scheduling_id`.

### 6.3 Estructura del registro de regresión

```php
[
    'petty_cash_record_id'  => $record->id,    // o payment_scheduling_id
    'user_id'               => $userId,
    'type'                  => OBSERVATION_TYPE_REGRESSION,
    'message'               => $reason,
    'metadata'              => ['from_status' => $from, 'to_status' => $to],
]
```

### 6.4 Validación condicional

En `PettyCashObservationsTable` y `PaymentSchedulingObservationsTable`:

- `type` ∈ `[OBSERVATION_TYPE_GENERAL, OBSERVATION_TYPE_REGRESSION]`.
- `message`: requerido siempre. `mb_strlen >= 10` y `<= 500` cuando `type='regression'`. Para `type='general'` se mantiene la regla actual.

Patrón ya implementado en `InvoiceObservationsTable` — replicar literalmente cambiando la constante de namespace.

## 7. Contratos del servicio

### 7.1 `PettyCashService`

```php
public function getPreviousStatus(string $currentStatus): ?string;

public function canRegress(string $roleName, string $currentStatus): bool;

public function getRegressionLockMessage(PettyCashRecord $record): ?string;

/**
 * @return array{success: bool, error: ?string, previousStatus: ?string}
 */
public function regress(
    PettyCashRecord $record,
    string $roleName,
    int $userId,
    string $reason,
): array;
```

Comportamiento de `regress()` (transaccional):

1. Validar `canRegress` (rol + predecesor) → error si falla.
2. Validar `getRegressionLockMessage` → error si retorna mensaje.
3. Validar longitud del motivo (10–500) → error si falla.
4. Cambiar `petty_cash_records.status` al predecesor.
5. Actualizar bulk `Invoices.pipeline_status = $previous WHERE petty_cash_record_id = $record->id`.
6. Llamar al método existente de bulk history (verificar firma de `recordBulkHistory` en `GroupedInvoiceService`; si no existe, crear el equivalente). Una entrada en `invoice_histories` por cada factura hija.
7. Insertar fila en `petty_cash_observations` con `type='regression'` + `metadata={from,to}`.

### 7.2 `PaymentSchedulingPipelineService`

```php
public function getPreviousStatus(string $currentStatus): ?string;

public function canRegress(string $roleName, string $currentStatus): bool;

public function getRegressionLockMessage(object $scheduling): ?string;

/**
 * @return array{success: bool, error: ?string, previousStatus: ?string}
 */
public function regress(
    PaymentScheduling $scheduling,
    string $roleName,
    int $userId,
    string $reason,
): array;
```

Comportamiento de `regress()` (transaccional, "fría"):

1. Validar `canRegress`, `getRegressionLockMessage`, longitud del motivo.
2. Cambiar `payment_schedulings.status` al predecesor.
3. **No tocar** `payment_scheduling_items` ni `invoice_payments`.
4. Insertar fila en `payment_scheduling_observations` con `type='regression'` + `metadata={from,to}`.

### 7.3 Errores devueltos por `regress()` (común a ambos servicios)

| Caso | Mensaje |
|---|---|
| Rol sin permiso | "No tiene permisos para regresar este registro." |
| Estado sin predecesor | "Este registro ya está en el primer paso del flujo." |
| Bloqueo activo | (mensaje del lock) |
| `reason` vacío o < 10 chars | "El motivo es obligatorio (mínimo 10 caracteres)." |
| `reason` > 500 chars | "El motivo no puede superar 500 caracteres." |
| Falla de save | "No se pudo regresar el registro. Intente de nuevo." |

## 8. UX

Idéntica al patrón de facturas:

- **Botón en `edit.php`**: a la izquierda de "Avanzar al siguiente paso", clase `.btn-outline-secondary`, icono `bi-arrow-counterclockwise`, texto "Regresar a: [Destino]". Visible solo si `canRegress` es `true`. Si hay bloqueo, `<button disabled title="...">` con tooltip Bootstrap.
- **Modal `regressStatusModal`**: textarea `name="reason"`, `required`, `minlength="10"`, `maxlength="500"`, `rows="4"`. Botón "Confirmar regreso" (`.btn-warning`) deshabilitado hasta que el textarea tenga ≥10 chars (vía JS `oninput`). Form POST a la ruta correspondiente con CSRF token.
- **Visualización en `view.php`**: filas con `type='regression'` muestran badge `[Regresión]` (`.badge.bg-warning.text-dark`), línea con `STATUS_LABELS[from] → STATUS_LABELS[to]`, avatar con color naranja (`#CD6A15`) en lugar del primario.

## 9. Rutas

En `config/routes.php`, antes de `$builder->fallbacks()`:

```php
$builder->connect(
    '/petty-cash-records/regress-status/{id}',
    ['controller' => 'PettyCashRecords', 'action' => 'regressStatus'],
    ['id' => '\d+', 'pass' => ['id']],
);

$builder->connect(
    '/payment-schedulings/regress-status/{id}',
    ['controller' => 'PaymentSchedulings', 'action' => 'regressStatus'],
    ['id' => '\d+', 'pass' => ['id']],
);
```

## 10. Permisos

Mapear `regressStatus` al grupo `'edit'` en `AppController::_actionToPermission` para los controllers `PettyCashRecords` y `PaymentSchedulings`. La regresión se considera una acción de edición (mismo nivel que `advanceStatus`).

## 11. Tests

### 11.1 Tests puros (sin BD)

- **`PaymentSchedulingPipelineServiceTest`** (nuevo):
  - `getPreviousStatus` retorna mapa correcto (incluye `null` para `borrador` y `pagada`).
  - `canRegress` true para roles visibles + estado con predecesor.
  - `canRegress` false desde `borrador` (sin predecesor).
  - `canRegress` false desde `pagada` (excluido vía `BACKWARD_TRANSITIONS`).
  - `canRegress` true para Admin desde estados regresables.

- **`PettyCashServiceTest`** (si los métodos `getPreviousStatus`/`canRegress` se implementan como funciones puras): idénticos casos.

### 11.2 Tests omitidos

Tests del controlador, tests de `regress()` con persistencia, tests de bloqueos basados en BD: omitidos por decisión consistente con el plan previo. Cobertura por smoke tests manuales.

### 11.3 Smoke tests manuales (al cierre)

Por módulo, cuatro escenarios:

1. **Happy path**: regresión exitosa desde el estado más común; verificar flash success, cambio de status y observación con badge en `view`.
2. **Bloqueo**: aplicar la condición de bloqueo (solo Caja Menor) y verificar botón deshabilitado con tooltip.
3. **Sin permiso**: rol no autorizado → botón no aparece.
4. **Sin predecesor**: estado inicial del flujo → botón no aparece.

## 12. Orden de implementación

1. **Anticipos** (rápido, separado para iterar):
   1. `AdvancesController::edit` pasa `canRegress`, `previousStatus`, `regressLockMessage`.
   2. `templates/Advances/edit.php`: botón + modal.
   3. `templates/Advances/view.php`: render con badge si la vista muestra observaciones (verificar contain).
   4. Smoke test + commit.

2. **Caja Menor:**
   5. Migración `petty_cash_observations` (`type`, `metadata`, índice).
   6. Constantes en `PettyCashConstants` (`OBSERVATION_TYPE_*`, `BACKWARD_TRANSITIONS`).
   7. Entity y Table de `PettyCashObservation` (validación condicional).
   8. `PettyCashService::getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress` (con propagación bulk).
   9. Controller (`regressStatus`), ruta, mapeo de permiso.
   10. Templates `edit.php` y `view.php` (botón, modal, badge).

3. **Programación:**
   11. Migración `payment_scheduling_observations`.
   12. Constantes en `PaymentSchedulingConstants`.
   13. Entity y Table de `PaymentSchedulingObservation`.
   14. `PaymentSchedulingPipelineService::getPreviousStatus`, `canRegress`, `getRegressionLockMessage` (null siempre), `regress`.
   15. Controller, ruta, permiso.
   16. Templates `edit.php` y `view.php`.

4. **Cierre:**
   17. Tests puros (`PaymentSchedulingPipelineServiceTest`).
   18. `composer cs-check` (+ `cs-fix` si necesario).
   19. Smoke tests integrales (4 escenarios por módulo).
   20. Commit de cierre.

## 13. Riesgos

| Riesgo | Mitigación |
|---|---|
| Anticipos en `autorizacion_pago` (refund flow) podrían entrar a un estado inválido si se regresan a `tesoreria` durante un caso sobrante en curso. | Verificar al implementar; si rompe, restringir el botón en `Advances/edit.php` cuando `pipeline_status === 'autorizacion_pago'` o añadir un bloqueo dedicado en `getRegressionLockMessage` para anticipos. |
| Caja Menor: `recordBulkHistory` debe existir en `GroupedInvoiceService` con la firma esperada. | Verificar la firma actual antes de Task 8; si difiere, ajustar la llamada o crear un wrapper. |
| Programación: coexistencia de `canReject` (Contador) y `regress` desde `aut_pago` puede confundir UX. | Mantener "Rechazar" como acción primaria del Contador con su propio botón; "Regresar al paso anterior" queda como `btn-outline-secondary` (acción secundaria). |
| Versión de MariaDB y soporte JSON nativo. | Riesgo ya validado en producción al implementar facturas. Plan B: `text` + `json_encode/decode` manual en el entity. |
| Concurrencia: dos usuarios actuando simultáneamente (avance + regresión). | La transacción protege la consistencia, último gana. Sin lock optimista, alineado con el patrón actual. |

## 14. Fuera de alcance

- Crear `petty_cash_histories` ni `payment_scheduling_histories` (la asimetría entre módulos preexistía; no la introducimos en esta iteración).
- Replicar la funcionalidad a Novedades (`NoveltyPipelineService`).
- Notificaciones por email al regresar.
- Botón en módulos no mencionados (Legalizaciones, Petty Cash documents, etc.).
- Permisos granulares por estado en una tabla nueva (la matriz se deriva de roles/estados visibles).
- Migración del flujo `canReject` de Programación al nuevo botón unificado (siguen como acciones separadas).
