# Invoice Pipeline Regression — Design Spec

**Fecha:** 2026-04-29
**Autor:** Alexander Caicedo (con asistencia de Claude)
**Estado:** Diseño aprobado, pendiente de implementación

## 1. Resumen

Añadir a cada paso del flujo de facturas un botón "Regresar al paso anterior" que, al pulsarlo, abra un modal donde el usuario debe escribir un motivo obligatorio. La operación cambia únicamente `pipeline_status` al estado predecesor (regresión "fría", sin tocar datos colaterales) y deja trazabilidad en `invoice_histories` y en `invoice_observations` con un nuevo `type='regression'`.

## 2. Decisiones tomadas durante el brainstorming

| # | Decisión |
|---|----------|
| 1 | Permisos: cada rol responsable del estado actual puede regresar al paso anterior; Admin puede regresar desde cualquier estado. |
| 2 | Estados regresables: todos excepto `aprobacion` (incluye `pagada → autorizacion_pago` con bloqueos automáticos por efectos irreversibles). |
| 3 | Las facturas rechazadas (`area_approval='Rechazada'`) siguen manejándose con `InvoiceApprovalService::resetFlow`. El nuevo botón no aparece en ese caso. |
| 4 | Regresión "fría": solo cambia `pipeline_status`; no se modifican pagos, causación, autorizaciones, etc. (Implicación aceptada: regresar desde `pagada` puede resultar en re-avance inmediato si los gates siguen cumpliéndose.) |
| 5 | Bloqueos automáticos: caja menor, programación pagada vinculada, anticipo con legalización iniciada, factura rechazada. |
| 6 | Persistencia del motivo: reutilizar `invoice_observations` con un campo `type` y un campo `metadata` (JSON con `from_status`/`to_status`). |
| 7 | UX: botón en `edit.php` junto a "Avanzar"; visible para roles con permiso, deshabilitado con tooltip cuando hay bloqueo. Modal con textarea (10–500 caracteres). |

## 3. Arquitectura

### 3.1 Componentes nuevos / modificados

- **`InvoicePipelineService`**
  - Constante `BACKWARD_TRANSITIONS`.
  - Método `getPreviousStatus(string $currentStatus): ?string`.
  - Método `canRegress(string $roleName, string $currentStatus): bool`.
  - Método `getRegressionLockMessage(Invoice $invoice): ?string`.
  - Método `regress(Invoice $invoice, string $roleName, int $userId, string $reason): array`.
- **`InvoicesController::regressStatus($id)`** — endpoint POST.
- **`InvoiceConstants`**
  - `OBSERVATION_TYPE_GENERAL = 'general'`.
  - `OBSERVATION_TYPE_REGRESSION = 'regression'`.
- **`AdvanceLegalizationService::hasLegalization(int $invoiceId): bool`** — usado para detectar bloqueo de regresión desde `pagada`.
- **Migración** sobre `invoice_observations`: columnas `type` (varchar 20, default `general`, NOT NULL) y `metadata` (JSON nullable). Índice compuesto `(invoice_id, type)`.
- **Entity `InvoiceObservation`** y **Table `InvoiceObservationsTable`**: añadir los nuevos campos, validación condicional (`message` con mín. 10 caracteres si `type='regression'`).
- **Templates**:
  - `edit.php`: botón + modal `regressStatusModal`.
  - `view.php` (sección de observaciones): badge `[Regresión]` y render del salto de estado para observaciones con `type='regression'`.
- **`config/routes.php`**: ruta `/invoices/regress-status/{id}` antes de `fallbacks()`.

### 3.2 Diagrama de flujo (alto nivel)

```
Usuario pulsa "Regresar"  →  Modal se abre con destino visible
       │
       ├─► Textarea reason (≥10, ≤500 chars)
       │
       └─► POST /invoices/regress-status/{id}
                │
                ▼
       InvoicesController::regressStatus
                │
                ▼
       InvoicePipelineService::regress
                │
                ├─► canRegress?               (rol + estado)
                ├─► getRegressionLockMessage? (4 bloqueos)
                ├─► reason válido?
                │
                ▼
       Transacción:
         - invoice.pipeline_status ← previo
         - history.recordStatusChange()
         - InvoiceObservation insertada (type=regression, metadata={from,to})
                │
                ▼
       Flash success → redirect index
```

## 4. Detalle de transiciones inversas

| Estado actual | Regresa a | Roles autorizados |
|---|---|---|
| `aprobacion` | (ninguno) | — |
| `contabilidad` | `aprobacion` | Contabilidad, Admin |
| `tesoreria` | `contabilidad` | Tesorería, Admin |
| `autorizacion_pago` | `tesoreria` | Tesorería, Contador, Admin |
| `pagada` | `autorizacion_pago` | Admin |

Regla unificada: el rol puede regresar si `pipeline_status` está en `getVisibleStatuses($role)` o si es Admin, **y** existe un estado predecesor.

## 5. Bloqueos automáticos

Implementados en `InvoicePipelineService::getRegressionLockMessage()`:

| Bloqueo | Mensaje |
|---|---|
| `petty_cash_record_id` no nulo | "Factura bloqueada: pertenece a un registro de Caja Menor." |
| `isLockedByPaidScheduling($id)` | "Factura bloqueada: tiene pagos en una programación ya pagada." |
| `area_approval === 'Rechazada'` | "Factura rechazada. Use 'Reiniciar flujo' para reactivarla." |
| Anticipo con legalización iniciada | "No se puede regresar: la legalización del anticipo ya fue iniciada." |

Cuando hay bloqueo, el botón aparece deshabilitado y el tooltip muestra el mensaje.

## 6. Modelo de datos

### 6.1 Migración `invoice_observations`

```php
public function change(): void
{
    $this->table('invoice_observations')
        ->addColumn('type', 'string', ['limit' => 20, 'default' => 'general', 'null' => false])
        ->addColumn('metadata', 'json', ['null' => true])
        ->addIndex(['invoice_id', 'type'])
        ->update();
}
```

Backfill innecesario: el default cubre filas existentes.

**Riesgo MariaDB:** validar al implementar que la versión soporta `json` nativo (MariaDB ≥ 10.2.7 / MySQL ≥ 5.7). Si no, usar `text` y serializar manualmente con `json_encode`/`json_decode` en el entity.

### 6.2 Entity `InvoiceObservation`

```php
protected array $_accessible = [
    'invoice_id' => true,
    'user_id'    => true,
    'message'    => true,
    'type'       => true,
    'metadata'   => true,
];
```

### 6.3 Estructura del registro de regresión

```php
[
    'invoice_id' => $invoice->id,
    'user_id'    => $userId,
    'type'       => InvoiceConstants::OBSERVATION_TYPE_REGRESSION,
    'message'    => $reason,
    'metadata'   => ['from_status' => $from, 'to_status' => $to],
]
```

### 6.4 Validación condicional en `InvoiceObservationsTable`

- `type` ∈ `[OBSERVATION_TYPE_GENERAL, OBSERVATION_TYPE_REGRESSION]`.
- `message`: requerido siempre. `mb_strlen >= 10` cuando `type='regression'` (regla `add('message', 'minLengthRegression', ['rule' => ...])` que solo dispara si el contexto incluye `type==='regression'`).

## 7. Contratos del servicio

```php
public function getPreviousStatus(string $currentStatus): ?string;

public function canRegress(string $roleName, string $currentStatus): bool;

public function getRegressionLockMessage(Invoice $invoice): ?string;

/**
 * @return array{success: bool, error: ?string, previousStatus: ?string}
 */
public function regress(
    Invoice $invoice,
    string $roleName,
    int $userId,
    string $reason
): array;
```

Errores devueltos por `regress()`:

| Caso | Mensaje |
|---|---|
| Rol sin permiso | "No tiene permisos para regresar esta factura." |
| Estado sin predecesor | "Esta factura ya está en el primer paso del flujo." |
| Bloqueo activo | (mensaje del lock) |
| `reason` vacío o < 10 chars | "El motivo es obligatorio (mínimo 10 caracteres)." |
| Falla de save | "No se pudo regresar la factura. Intente de nuevo." |

## 8. UX

### 8.1 Botón en `edit.php`

- Posición: a la izquierda de "Avanzar al siguiente paso".
- Clase: `.btn-outline-secondary` (Bootstrap; el proyecto solo tiene `.sgi-btn-primary` definida, no hay variantes SGI para secondary/warning).
- Icono: `bi-arrow-counterclockwise`.
- Texto: "Regresar al paso anterior".
- Visible solo si `canRegress` es `true`.
- Si hay bloqueo (`getRegressionLockMessage` no nulo): renderizar como `<button disabled title="...">` con el mensaje del lock. `sgi-common.js` ya envuelve estos botones e inicializa el tooltip de Bootstrap automáticamente — no se requiere JS adicional.
- En `view.php` no aparece.

### 8.2 Modal `regressStatusModal`

- Header: "Regresar al paso anterior".
- Cuerpo:
  - Frase: "Esta factura volverá del paso `[Origen]` al paso `[Destino]`."
  - Textarea `name="reason"`, `required`, `minlength="10"`, `maxlength="500"`, `rows="4"`.
  - Texto auxiliar: "Mín. 10 caracteres · Máx. 500".
- Footer:
  - Botón "Cancelar" (`.btn-light`, cierra modal).
  - Botón "Confirmar regreso" (`.btn-warning`, deshabilitado hasta que el textarea tenga ≥10 chars; habilitación vía JS `oninput`).
- Form: POST a `/invoices/regress-status/{id}` con CSRF token de CakePHP.

### 8.3 Visualización de observaciones de regresión

En `view.php`, dentro de la lista de observaciones existentes:
- Las filas con `type='regression'` muestran un badge `[Regresión]` antes del mensaje.
- Encima del mensaje del usuario, una línea: `Tesorería → Contabilidad` (renderizada usando `InvoicePipelineService::STATUS_LABELS` aplicada a `metadata.from_status` y `metadata.to_status`).

## 9. Ruta

En `config/routes.php`, antes de `$builder->fallbacks()`:

```php
$builder->connect(
    '/invoices/regress-status/{id}',
    ['controller' => 'Invoices', 'action' => 'regressStatus'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

## 10. Permisos

`regressStatus` se mapea al módulo `invoices` y se considera una acción de edición — debe quedar cubierta por `can_edit`. Verificar al implementar si el proyecto usa un mapa explícito de acciones a permisos (`AppController`) o si la convención por defecto basta.

## 11. Tests

### 11.1 `InvoicePipelineServiceTest`

- `regress()` exitoso desde cada estado válido (4 casos: contabilidad, tesoreria, autorizacion_pago, pagada).
- Falla cuando `pipeline_status === 'aprobacion'`.
- Falla por cada uno de los 4 bloqueos.
- Falla cuando rol no autorizado (matriz negativa).
- Falla cuando `reason` vacío o < 10 chars.
- Admin regresa desde cualquier estado válido.
- `recordStatusChange` invocado con `from`/`to` correctos.
- Se persiste `InvoiceObservation` con `type='regression'` y `metadata` correctos.
- Transaccionalidad: si la inserción de la observación falla, `pipeline_status` no cambia.

### 11.2 `InvoicesControllerTest`

- POST exitoso → flash success + redirect index.
- POST sin `reason` → flash error.
- POST con factura bloqueada → flash error.
- POST con rol no autorizado → flash error.
- GET no permitido (espera `MethodNotAllowedException` o equivalente).
- CSRF token requerido.

### 11.3 `AdvanceLegalizationServiceTest`

- Test para `hasLegalization` (positivo y negativo).

## 12. Orden de implementación

1. Migración `invoice_observations` (`type`, `metadata`, índice).
2. Constantes en `InvoiceConstants` y `BACKWARD_TRANSITIONS` en `InvoicePipelineService`.
3. `AdvanceLegalizationService::hasLegalization`.
4. Entity y Table de `InvoiceObservation` (validación condicional).
5. Métodos del service: `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`.
6. Tests unitarios del service.
7. Controlador (`regressStatus`) y ruta.
8. Tests del controlador.
9. UI: botón + modal + JS de validación de longitud.
10. Visualización de observaciones de regresión en `view.php`.
11. Validar que `composer check` pasa (cs + tests).

## 13. Riesgos

- **MariaDB/MySQL JSON:** confirmar versión del entorno antes de usar columna `json` nativa. Plan B: `text` + serialización manual.
- **Permisos:** validar que `can_edit` cubre `regressStatus` o ajustar el mapa.
- **Concurrencia:** dos usuarios actuando simultáneamente (avance + regresión) — la transacción protege la consistencia, último gana. No se añade lock optimista (alineado con `advance` actual).
- **Regresión desde `pagada`:** dado que es regresión "fría", al guardar de nuevo los gates pueden cumplirse y la factura re-avanza automáticamente. Se acepta como comportamiento esperado en esta iteración. Mejora futura posible: flag `needs_review_after_regression` que bloquee el siguiente avance hasta acción explícita.

## 14. Fuera de alcance

- Aplicar la misma funcionalidad al pipeline de novedades (`NoveltyPipelineService`) o al de programaciones de pago (`PaymentSchedulingPipelineService`). Si se decide replicar, será otro spec.
- Migrar `resetFlow` al nuevo botón unificado (descartado en pregunta 3, opción A).
- Notificaciones por email al regresar (no solicitado).
- Permisos granulares por estado en una tabla nueva (la matriz se deriva de `ROLE_VISIBLE_STATUSES`).
