# Caja Menor — eliminar pagos agregados y replicar flujo de Programación

**Goal:** Eliminar la tabla `petty_cash_payments` y su capa de pagos agregados. Replicar el modelo de Programación de Pagos: los datos del pago pendiente viven en el record padre, y los pagos solo se materializan como `invoice_payments` individuales cuando el Contador autoriza. En el Registro de Pagos global, Caja Menor aparece exclusivamente como filas tipo "Factura" con badge "Caja Menor CM-XXX", nunca como fila agregada.

**Alcance:** Solo Caja Menor. Legalización y Liquidación de Novedades quedan como están.

**Datos históricos:** No se migran. Las filas existentes en `petty_cash_payments` se pierden al dropear la tabla; los records ya autorizados conservan su `status='pagado'`, `payment_date` y `payment_status` pero pierden la trazabilidad del banco y autorizador anteriores.

---

## 1. Modelo de datos

### Nuevas columnas en `petty_cash_records`

| Columna | Tipo | Nullable | Default | Propósito |
|---------|------|----------|---------|-----------|
| `banking_entity_id` | int FK → banking_entities | sí | NULL | Banco del pago pendiente/realizado |
| `payment_amount` | decimal(15,2) | sí | NULL | Monto registrado por Tesorería (informativo) |
| `payment_created_by` | int FK → users | sí | NULL | Tesorería que registró el pago |
| `payment_authorized_by` | int FK → users | sí | NULL | Contador que autorizó |
| `payment_authorized_date` | date | sí | NULL | Fecha de autorización |
| `payment_rejection_reason` | text | sí | NULL | Motivo del último rechazo (transitorio) |

### Columnas existentes que se reutilizan

- `payment_date` — fecha del pago (planeada en registro, confirmada en autorización).
- `payment_status` — `Pago total` al autorizar.
- `status` — flujo `tesoreria` → `aut_pago` → `pagado`.

### Tabla dropeada

`petty_cash_payments` se elimina por completo.

### Migración

`config/Migrations/20260424120000_ConvertPettyCashPaymentsToRecordFields.php`:
1. `addColumn` de las 6 columnas nuevas en `petty_cash_records` con FKs.
2. `drop` de la tabla `petty_cash_payments`.
3. Sin backfill.

`down()`:
1. Recrear `petty_cash_payments` con estructura original (sin datos).
2. Remover columnas del record.

---

## 2. Service refactor

`PettyCashPaymentService` se elimina. Su lógica se consolida en **`PettyCashPipelineService`** (donde ya viven las transiciones de estado).

### `registerPayment(int $recordId, array $data, int $createdBy): ServiceResult`

1. Validar `status === STATUS_TESORERIA`.
2. Validar que el record no tenga `banking_entity_id` poblado (no hay pago pendiente previo).
3. Patch al record con: `banking_entity_id`, `payment_amount`, `payment_date`, `payment_created_by = $createdBy`, `payment_rejection_reason = NULL`.
4. Cambiar `status` a `STATUS_AUT_PAGO`.
5. Guardar en transacción.

### `authorizePayment(int $recordId, int $authorizedBy): ServiceResult`

1. Validar `status === STATUS_AUT_PAGO` y `banking_entity_id IS NOT NULL`.
2. Dentro de `transactional()`:
   - Iterar facturas hijas: `invoices WHERE petty_cash_record_id = $recordId AND total_amount > 0`.
   - Crear un `invoice_payment` por cada factura con:
     - `amount = invoice.total_amount`
     - `banking_entity_id`, `payment_date` copiados del record
     - `petty_cash_record_id = $recordId`
     - `authorized = true`, `status = PAYMENT_RECORD_AUTHORIZED`
     - `authorized_by = $authorizedBy`, `authorized_date = today`
     - `created_by = $record->payment_created_by`
   - Actualizar cada factura hija: `pipeline_status = PAGADA`, `payment_status = PAYMENT_FULL`, `full_payment_date = record->payment_date`.
   - Actualizar el record: `status = STATUS_PAGADO`, `payment_status = PAYMENT_FULL`, `payment_authorized_by = $authorizedBy`, `payment_authorized_date = today`.

### `rejectPayment(int $recordId, int $rejectedBy, string $reason): ServiceResult`

1. Validar `status === STATUS_AUT_PAGO`.
2. Limpiar campos pendientes: `banking_entity_id = NULL`, `payment_amount = NULL`, `payment_date = NULL`, `payment_created_by = NULL`.
3. Guardar `payment_rejection_reason = $reason`.
4. Cambiar `status` a `STATUS_TESORERIA`.

El parámetro `$rejectedBy` se recibe por consistencia con el contrato pero no se persiste como columna (si se requiere auditoría, irá a `petty_cash_observations` en un cambio futuro).

---

## 3. Controladores y rutas

### `PettyCashRecordsController` — 3 nuevas acciones

```php
// POST /petty-cash-records/{id}/register-payment
public function registerPayment($id): \Cake\Http\Response
// POST /petty-cash-records/{id}/authorize-payment
public function authorizePayment($id): \Cake\Http\Response
// POST /petty-cash-records/{id}/reject-payment   (body: reason)
public function rejectPayment($id): \Cake\Http\Response
```

Cada acción delega a `PettyCashPipelineService`, gestiona Flash messages y redirige a `edit`.

### Rutas

Agregar en `config/routes.php` antes de `$builder->fallbacks()`:

```php
$builder->connect(
    '/petty-cash-records/{id}/register-payment',
    ['controller' => 'PettyCashRecords', 'action' => 'registerPayment']
)->setPass(['id'])->setMethods(['POST']);

// idem para authorize-payment y reject-payment
```

### Permisos

- Entrada `PettyCashPayments` se elimina de `AppController::$controllerModuleMap`.
- Las 3 nuevas acciones quedan bajo el módulo `PettyCashRecords` (permisos existentes cubren Admin, Contador, Tesorería).
- Filas huérfanas en `permissions` con `module = 'PettyCashPayments'` quedan inofensivas; limpieza opcional con `DELETE FROM permissions WHERE module = 'PettyCashPayments'`.

### Archivos a eliminar

- `src/Controller/PettyCashPaymentsController.php`
- `src/Model/Table/PettyCashPaymentsTable.php`
- `src/Model/Entity/PettyCashPayment.php`
- `src/Service/PettyCashPaymentService.php`

---

## 4. Templates

### `templates/PettyCashRecords/edit.php`

**Remover** el uso del element `payment_section` que renderiza la tabla de `petty_cash_payments`.

**Agregar** una tarjeta de pago simple que se muestra cuando `status IN (aut_pago, pagado)`:

```
┌─ Pago ───────────────────────────────────────────────┐
│ Banco: Bancolombia                                   │
│ Monto: $ 1.111.111                                   │
│ Fecha: 24/04/2026                                    │
│ Registrado por: Alexander Caicedo · 24/04/2026       │
│                                                       │
│ Estado: [Pendiente de autorización | Autorizado]     │
│ Autorizado por: Fredy Giraldo · 24/04/2026           │
│                                                       │
│ [Autorizar] [Rechazar]   ← si status=aut_pago        │
└──────────────────────────────────────────────────────┘
```

**Alert de rechazo** encima del formulario cuando `status = tesoreria` AND `payment_rejection_reason IS NOT NULL`:

```
⚠ Pago rechazado: {payment_rejection_reason}
```

### Formulario de registro

Visible cuando `status = tesoreria` y rol tiene permiso. Campos: `banking_entity_id`, `payment_amount`, `payment_date`. POST a `/petty-cash-records/{id}/register-payment`.

### Botones de acción

Renderizados directamente en `edit.php` (no por el element compartido). El botón Rechazar abre modal de motivo reusando el JS existente `btn-reject-payment` con `data-url` apuntando al nuevo endpoint.

### Otros templates

- `templates/element/payment_section.php`: sin cambios. Sigue siendo usado por Legalización y Liquidación.
- `templates/Invoices/view.php` y `edit.php`: sin cambios — el badge "Caja Menor CM-XXX" sobre `invoice_payments` ya quedó implementado en el plan anterior.

---

## 5. Registro de Pagos global

### `PaymentRegistryService`

- **Eliminar** el método `_queryPettyCashPayments()` y su llamada en `getAll()`.
- El filtro anti-duplicación `PettyCashPayments.petty_cash_record_id NOT IN (...)` se va con el método (ya no hay fuente agregada).
- `_queryInvoicePayments()` sin cambios: sigue retornando los `invoice_payments` con `petty_cash_record_id` poblado y `source_label = "Caja Menor CM-XXX"`.

### `templates/PaymentRegistry/index.php`

- Eliminar la opción `<option value="petty_cash">` del select de filtro Tipo (cosmético — sin backend a filtrar).

### Resultado visible

Caja Menor aparece como N filas tipo **Factura** con badge "Caja Menor CM-XXX", idéntico al ejemplo con Programación:

```
Factura  FVE80933  Banco de Occidente  $1.852.701  10/04/2026  Autorizado  Fredy Giraldo  10/04/2026  Keila  Caja Menor CM-001  10/04/2026 16:53
Factura  10302391  Banco de Occidente  $1.852.701  10/04/2026  Autorizado  Fredy Giraldo  10/04/2026  Keila  Caja Menor CM-001  10/04/2026 16:53
```

---

## 6. Verificación

1. SQL: `SHOW TABLES LIKE 'petty_cash_payments'` → vacío.
2. Flujo Tesorería → Contador:
   - Crear record con 2 facturas, avanzar a Tesorería.
   - Tesorería registra pago (banco+monto+fecha).
   - Contador autoriza.
   - SQL verifica `SELECT COUNT(*) FROM invoice_payments WHERE petty_cash_record_id = X` → 2.
   - Registro de Pagos muestra 2 filas tipo Factura con badge.
3. Flujo rechazo:
   - Registrar pago, rechazar con motivo.
   - Record vuelve a Tesorería, alert muestra motivo.
   - Re-registrar pago → `payment_rejection_reason` se limpia.
4. Admin y Contador ven los botones Autorizar/Rechazar en la tarjeta de pago.

---

## Principios aplicados

- **Replicar Programación de Pagos:** el record es dueño de su transacción; `invoice_payments` solo existe al autorizar.
- **YAGNI:** no se crea `items` per-factura (Caja Menor no lo requiere); no se audita `rejected_by` (se deja para un cambio futuro si aparece la necesidad).
- **Forward-only:** sin backfill; los records autorizados conservan estado pero pierden datos del banco/autorizador antiguos.
- **Commits atómicos:** uno por task del plan de implementación.
