# Spec — Módulo Reintegros (Refunds)

**Fecha:** 2026-05-02
**Estado:** Aprobado para plan de implementación
**Autor:** Alexander Caicedo (con asistencia de Claude)

## 1. Resumen

Nuevo módulo de agrupación de facturas tipo `Reintegro`, estructuralmente idéntico a Caja Menor (`PettyCash`). Reutiliza `GroupedInvoiceService` con parámetros distintos. Pipeline de 4 estados: `agrupacion → contabilidad → tesoreria → aut_pago → pagado`. La única diferencia funcional con Caja Menor es que el registro padre tiene un **beneficiario** (empleado o proveedor) que se valida antes de avanzar a `contabilidad`.

`DOCTYPE_REINTEGRO = 'Reintegro'` ya existe como tipo de documento en `InvoiceConstants` y los pagos individuales con `is_refund` ya están manejados por `InvoicePaymentService`. Este módulo no toca esa lógica; solo agrega una capa de agrupación encima de facturas con `document_type=Reintegro`.

## 2. Convenciones de naming

- Clase / tabla / FK en inglés (alineado con PettyCash, Advances, Novelties).
- Etiquetas UI en español (`Reintegro`, `Reintegros`).
- Tabla padre: `refunds`. Entity: `Refund`. Controller: `RefundsController`. Servicio: `RefundService`. Constants: `RefundConstants`. FK en `invoices` e `invoice_payments`: `refund_id`.

## 3. Arquitectura

Clon estructural de `PettyCash` reutilizando `GroupedInvoiceService`. Cero refactor del módulo Caja Menor.

**Archivos nuevos:**
- `config/Migrations/<timestamp>_CreateRefunds.php` — tabla `refunds`, observaciones, columnas `refund_id`.
- `config/Migrations/<timestamp>_SeedRefundsPermissions.php` — permisos por rol.
- `src/Constants/RefundConstants.php` — clon de `PettyCashConstants`.
- `src/Model/Entity/Refund.php`, `src/Model/Table/RefundsTable.php`.
- `src/Model/Entity/RefundObservation.php`, `src/Model/Table/RefundObservationsTable.php`.
- `src/Service/RefundService.php` — clon de `PettyCashService`, instancia `GroupedInvoiceService` con `documentType=Reintegro`, `fkField=refund_id`, `recordTableName=Refunds`, `fkLabel=Reintegro`.
- `src/Controller/RefundsController.php`.
- `templates/Refunds/{index,add,edit,view}.php`.
- `templates/element/refund_progress.php` — clon de `petty_cash_progress.php`.

**Archivos modificados:**
- `src/Model/Table/InvoicesTable.php` — `belongsTo('Refunds')`.
- `src/Model/Table/InvoicePaymentsTable.php` — `belongsTo('Refunds')`.
- `src/Service/AuthorizationService.php` — agregar `'refunds' => 'Reintegros'` a `MODULES`.
- `src/Controller/AppController.php` — agregar `'Refunds' => 'refunds'` a `$controllerModuleMap`.
- `src/Service/SidebarCounterService.php` — agregar `getRefundsPendingCount()`.
- `templates/layout/default.php` — nuevo nav-link `Reintegros` debajo de `Caja Menor`.
- `config/routes.php` — rutas custom antes de `$builder->fallbacks()`.

**Archivos NO modificados:**
- `src/Constants/InvoiceConstants.php` — `DOCTYPE_REINTEGRO` ya existe.
- `InvoicePipelineService`, `InvoicePaymentService`, `InvoiceFieldAccessPolicy` — el flujo de las facturas hijas sigue siendo el normal.
- `GroupedInvoiceService` — ya está hecho para esto.
- `PaymentRegistryService` — sigue tratando `'reintegro_doc'` como facturas con `document_type=Reintegro` sueltas (no agrupadas), separado del nuevo padre `Refund`.

## 4. Modelo de datos

### 4.1 Tabla `refunds`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | INT UNSIGNED PK auto-increment | |
| `code` | VARCHAR(20) UNIQUE NOT NULL | Numeración `REI-YYYY-NNNN`, generada en `RefundsTable::beforeSave` |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'agrupacion' | `agrupacion`, `contabilidad`, `tesoreria`, `aut_pago`, `pagado` |
| `total_amount` | DECIMAL(18,2) NOT NULL DEFAULT 0 | Calculado vía `GroupedInvoiceService::calculateAndSaveTotal` |
| `beneficiary_type` | VARCHAR(20) NULL | `employee` o `provider` (alineado a `HOLDER_TYPE_*`, sin `manual`) |
| `beneficiary_employee_id` | INT UNSIGNED NULL | FK a `employees.id` |
| `beneficiary_provider_id` | INT UNSIGNED NULL | FK a `providers.id` |
| `accrued` | TINYINT(1) NOT NULL DEFAULT 0 | |
| `accrual_date` | DATE NULL | |
| `ready_for_payment` | VARCHAR(40) NULL | Usa `InvoiceConstants::READY_FOR_PAYMENT_OPTIONS` |
| `banking_entity_id` | INT UNSIGNED NULL | FK a `banking_entities.id` |
| `payment_amount` | DECIMAL(18,2) NULL | |
| `payment_date` | DATE NULL | |
| `payment_created_by` | INT UNSIGNED NULL | FK a `users.id` |
| `payment_authorized_by` | INT UNSIGNED NULL | FK a `users.id` |
| `payment_authorized_date` | DATE NULL | |
| `payment_status` | VARCHAR(40) NULL | `Pago total` o `Pago Parcial` (en este flujo siempre `Pago total` al autorizar) |
| `payment_rejection_reason` | TEXT NULL | |
| `created_by` | INT UNSIGNED NOT NULL | FK a `users.id` |
| `created` | DATETIME NOT NULL | |
| `modified` | DATETIME NOT NULL | |

**Índices/FKs:**
- Índice en `status`, `beneficiary_employee_id`, `beneficiary_provider_id`, `created_by`.
- FKs ON DELETE RESTRICT a employees, providers, banking_entities, users.

**Reglas de validación (`RefundsTable::buildRules`):**
- `beneficiary_type` debe ser `employee` o `provider` cuando esté seteado.
- Si `beneficiary_type='employee'`: `beneficiary_employee_id` requerido y `beneficiary_provider_id` debe ser NULL.
- Si `beneficiary_type='provider'`: `beneficiary_provider_id` requerido y `beneficiary_employee_id` debe ser NULL.
- `code` único.

### 4.2 Tabla `refund_observations`

Clon exacto de `petty_cash_observations`:

| Columna | Tipo |
|---|---|
| `id` | INT UNSIGNED PK |
| `refund_id` | INT UNSIGNED NOT NULL, FK a `refunds.id` ON DELETE CASCADE |
| `user_id` | INT UNSIGNED NOT NULL, FK a `users.id` |
| `type` | VARCHAR(20) NOT NULL DEFAULT 'general' (`general` o `regression`) |
| `message` | TEXT NOT NULL |
| `metadata` | JSON NULL |
| `created` | DATETIME NOT NULL |

### 4.3 Cambios en tablas existentes

- `invoices`: agregar `refund_id INT UNSIGNED NULL`, FK a `refunds.id` ON DELETE SET NULL, índice.
- `invoice_payments`: agregar `refund_id INT UNSIGNED NULL`, FK a `refunds.id` ON DELETE SET NULL, índice. Paralelo al `petty_cash_record_id` existente; permite trazar qué pagos se materializaron desde un Reintegro autorizado.

### 4.4 Asociaciones

**`RefundsTable`:**
- `belongsTo('BeneficiaryEmployees', ['className' => 'Employees', 'foreignKey' => 'beneficiary_employee_id'])`
- `belongsTo('BeneficiaryProviders', ['className' => 'Providers', 'foreignKey' => 'beneficiary_provider_id'])`
- `belongsTo('BankingEntities')`
- `belongsTo('CreatedByUser', ['className' => 'Users', 'foreignKey' => 'created_by'])`
- `belongsTo('PaymentCreatedBy', ['className' => 'Users', 'foreignKey' => 'payment_created_by'])`
- `belongsTo('PaymentAuthorizedBy', ['className' => 'Users', 'foreignKey' => 'payment_authorized_by'])`
- `hasMany('Invoices', ['foreignKey' => 'refund_id'])`
- `hasMany('RefundObservations')`

**`InvoicesTable`:** agregar `belongsTo('Refunds', ['foreignKey' => 'refund_id'])`.

**`InvoicePaymentsTable`:** agregar `belongsTo('Refunds', ['foreignKey' => 'refund_id'])`.

## 5. Constantes (`RefundConstants`)

Clon de `PettyCashConstants`. Mismos valores literales para los estados:

```php
public const STATUS_AGRUPACION   = 'agrupacion';
public const STATUS_CONTABILIDAD = 'contabilidad';
public const STATUS_TESORERIA    = 'tesoreria';
public const STATUS_AUT_PAGO     = 'aut_pago';
public const STATUS_PAGADO       = 'pagado';

public const TRANSITIONS = [
    self::STATUS_AGRUPACION   => self::STATUS_CONTABILIDAD,
    self::STATUS_CONTABILIDAD => self::STATUS_TESORERIA,
    self::STATUS_TESORERIA    => self::STATUS_AUT_PAGO,
    self::STATUS_AUT_PAGO     => self::STATUS_PAGADO,
];

public const BACKWARD_TRANSITIONS = [
    self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
    self::STATUS_TESORERIA    => self::STATUS_CONTABILIDAD,
    self::STATUS_AUT_PAGO     => self::STATUS_TESORERIA,
];

public const REGRESS_ROLE_BY_STATUS = [
    self::STATUS_CONTABILIDAD => [RoleConstants::CONTABILIDAD],
    self::STATUS_TESORERIA    => [RoleConstants::TESORERIA],
    self::STATUS_AUT_PAGO     => [RoleConstants::TESORERIA],
];

public const OBSERVATION_TYPE_GENERAL    = 'general';
public const OBSERVATION_TYPE_REGRESSION = 'regression';

// Tipos de beneficiario
public const BENEFICIARY_TYPE_EMPLOYEE = 'employee';
public const BENEFICIARY_TYPE_PROVIDER = 'provider';
public const BENEFICIARY_TYPES = [self::BENEFICIARY_TYPE_EMPLOYEE, self::BENEFICIARY_TYPE_PROVIDER];
```

## 6. Pipeline y servicio

`RefundService` espeja `PettyCashService`. Visibilidad por rol (`ROLE_VISIBLE_STATUSES`):

| Rol | Estados visibles |
|---|---|
| `Registro/Revisión` | `agrupacion` |
| `Contabilidad` | `contabilidad` |
| `Tesorería` | `tesoreria`, `aut_pago` |
| `Contador` | `aut_pago` |
| `Auxiliar de Personal`, `Asistente de Personal`, `Coordinador Admin`, `Administrador` | todos los activos |

**Métodos públicos** (mismas firmas que `PettyCashService`):

- `getVisibleStatuses(string $roleName): array`
- `validateGrouping(array $invoiceIds): array`
- `addInvoices(Refund $record, array $invoiceIds): array`
- `removeInvoice(Refund $record, int $invoiceId): bool`
- `calculateAndSaveTotal(Refund $record): void`
- `getAvailableInvoices(array $filters = []): SelectQuery`
- `advanceStatus(Refund $record, int $userId): array`
- `getTransitionErrors(Refund $record): array`
- `canDelete(Refund $record): bool` — solo en `agrupacion`.
- `registerPayment(int $recordId, array $data, int $createdBy): ServiceResult`
- `authorizePayment(int $recordId, int $authorizedBy): ServiceResult`
- `rejectPayment(int $recordId, int $rejectedBy, string $reason): ServiceResult`
- `getPreviousStatus(string $currentStatus): ?string`
- `canRegress(string $roleName, string $currentStatus): bool`
- `getRegressionLockMessage(Refund $record): ?string`
- `regress(Refund $record, string $roleName, int $userId, string $reason): array`

**Reglas de transición** (`_validateTransition`) — idénticas a PettyCash más una específica:

- `agrupacion → contabilidad`: **requiere beneficiario válido** (`beneficiary_type` seteado y FK correspondiente no NULL). Si falta, retorna error `"Debe seleccionar un beneficiario antes de avanzar."`.
- `contabilidad → tesoreria`: requiere `accrued=true`, `accrual_date` no NULL, `ready_for_payment` seteado.
- `tesoreria → aut_pago`: solo vía `registerPayment` (no por `advanceStatus` directo).
- `aut_pago → pagado`: solo vía `authorizePayment`.

**Materialización de pagos** (`authorizePayment`): por cada factura hija con `amount > 0` se crea un `invoice_payment` con `refund_id` (nuevo), `status=authorized`, `authorized=true`, `authorized_by`, `authorized_date`. La hija pasa a `pipeline_status=pagada` y `payment_status=Pago total`. El padre pasa a `pagado`.

**Rechazo de pago** (`rejectPayment`): vuelve a `tesoreria`, persiste `payment_rejection_reason` (no elimina el registro padre), limpia `banking_entity_id`/`payment_amount`/`payment_date`/`payment_created_by`.

**Regresión** (`regress`): exige motivo (10–500 caracteres). Bloqueo único: `tesoreria → contabilidad` cuando hay pago pendiente registrado. Persiste motivo en `refund_observations` con `type=regression` y `metadata={from_status, to_status}`. Propaga `pipeline_status` a hijas para `contabilidad` y `tesoreria` (mismo `childPipelineMap` de PettyCash).

**Edición del beneficiario:** solo en estado `agrupacion`. En estados posteriores los campos `beneficiary_type`, `beneficiary_employee_id`, `beneficiary_provider_id` son read-only en formularios y bloqueados en el controller (validación a la hora de patch).

**Borrado:** solo en estado `agrupacion`. Antes de eliminar, libera la FK `refund_id` en facturas hijas (mismo patrón que PettyCash en `delete()`).

## 7. Controller, rutas, permisos

**`RefundsController`** — clon de `PettyCashRecordsController`. Acciones: `index`, `add`, `edit`, `view`, `delete`, `addInvoices`, `removeInvoice`, `advance`, `regress`, `registerPayment`, `authorizePayment`, `rejectPayment`. DI vía `$container->get(RefundService::class)`.

**Rutas** (`config/routes.php`, antes de `$builder->fallbacks()`):

```php
$builder->scope('/refunds', function (RouteBuilder $routes): void {
    $routes->connect('/{id}/add-invoices', ['controller' => 'Refunds', 'action' => 'addInvoices'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/remove-invoice/{invoiceId}', ['controller' => 'Refunds', 'action' => 'removeInvoice'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/advance', ['controller' => 'Refunds', 'action' => 'advance'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/regress', ['controller' => 'Refunds', 'action' => 'regress'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/register-payment', ['controller' => 'Refunds', 'action' => 'registerPayment'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/authorize-payment', ['controller' => 'Refunds', 'action' => 'authorizePayment'])
        ->setMethods(['POST']);
    $routes->connect('/{id}/reject-payment', ['controller' => 'Refunds', 'action' => 'rejectPayment'])
        ->setMethods(['POST']);
});
```

**Permisos:**

- `AuthorizationService::MODULES['refunds'] = 'Reintegros'`.
- `AppController::$controllerModuleMap['Refunds'] = 'refunds'`.
- Migración `SeedRefundsPermissions`: por cada rol que hoy tiene permisos en `petty_cash`, insertar fila equivalente en `permissions` para `module='refunds'` con los mismos flags `can_view`/`can_create`/`can_edit`/`can_delete`. Admin queda con bypass natural.

**Sidebar (`templates/layout/default.php`):**
- Nuevo nav-link `Reintegros` (icono Bootstrap `bi-arrow-counterclockwise` o equivalente) debajo del nav-link de `Caja Menor`, dentro de la misma sección de pipeline contable.
- Badge de pendientes vía `SidebarCounterService::getRefundsPendingCount($user)` (clon de la lógica de PettyCash, agrupado por rol-visible).

## 8. Templates

**`templates/Refunds/index.php`:**
- Listado paginado (15) filtrado por estados visibles del rol.
- Filtros: estado, fecha desde/hasta, beneficiario (selector empleado/proveedor).
- Tabla con `code`, `created`, beneficiario (nombre+tipo), `total_amount`, `status` con badge de color (reusa `StatusColorConstants`).
- Filas `clickable-row` con `data-href` a `view`.

**`templates/Refunds/add.php`:**
- Solo se crea el padre (sin facturas todavía). Campos: beneficiario.
- Beneficiario: dos `select2` (uno para `BeneficiaryEmployees`, otro para `BeneficiaryProviders`) con visibilidad condicional según radio `beneficiary_type`. Solo uno se envía/pobla a la vez.
- Tras crear, redirige a `edit` para empezar a agrupar facturas.

**`templates/Refunds/edit.php`:**
- Header con `refund_progress.php` (estado actual + `isRejected=false` ya que en este pipeline no aplica rechazo de tipo aprobación externa).
- Sección de beneficiario: editable solo en `agrupacion`; en estados posteriores se muestra read-only.
- Tabla de facturas agrupadas con botón "Quitar" solo si `status=agrupacion` y la factura no está bloqueada.
- Buscador de facturas disponibles (`getAvailableInvoices` con filtros) para agregar al registro.
- Sección de campos de contabilidad (`accrued`, `accrual_date`, `ready_for_payment`) editables en `contabilidad`.
- Sección de pago (`banking_entity_id`, `payment_amount`, `payment_date`) editable en `tesoreria` (si no hay pago pendiente).
- Botón "Avanzar" / "Regresar" / "Registrar pago" / "Autorizar pago" / "Rechazar pago" según estado y permisos.

**`templates/Refunds/view.php`:**
- Read-only. Mismo layout que `edit` pero sin formularios. Muestra observaciones (general + regression con metadata).

**`templates/element/refund_progress.php`:**
- Clon visual de `petty_cash_progress.php`. Mismos 4 pasos visibles. Acepta `isRejected` (bool) por simetría aunque en este pipeline no se use.

**Convenciones aplicadas:**
- Pagination = 15.
- CSS classes `.sgi-*`.
- Auto-init JS: `.flatpickr-date`, `.currency-input`, `.select2`, `.clickable-row`.
- Servicios accedidos vía `TableRegistry::getTableLocator()->get(...)`.
- DI con constructor + `?? new` fallback.
- `ServiceResult::ok/fail` y `->success` antes de `->data`.
- Métodos privados con prefijo `_`.

## 9. Validación manual

Sin tests automatizados (ver `CLAUDE.md` → "Testing Policy"). Validación end-to-end manual:

1. **Migración**
   - `composer install` (si aplica).
   - `php bin/cake migrations migrate` corre limpio.
   - Verificar tabla `refunds`, `refund_observations`, columnas `refund_id` en `invoices` e `invoice_payments`, FKs e índices.
   - Verificar filas seed en `permissions` para módulo `refunds`.

2. **Crear factura individual con `document_type=Reintegro`** y llevarla a `pipeline_status=contabilidad` por el flujo normal de Invoices.

3. **Como Registro/Revisión:** `/refunds/add` con beneficiario empleado → crea `Refund` en `agrupacion`. En `edit`, agregar la factura del paso 2 → aparece en la tabla, `total_amount` se recalcula. Intentar borrar el reintegro → permitido. Recrearlo. Intentar avanzar **sin beneficiario** → debe fallar con mensaje claro. Con beneficiario → avanza a `contabilidad`; verificar que las hijas pasan a `pipeline_status=contabilidad`.

4. **Como Contabilidad:** abrir el reintegro, marcar `accrued`, setear `accrual_date` y `ready_for_payment`, avanzar → pasa a `tesoreria`; hijas pasan a `pipeline_status=tesoreria`.

5. **Como Tesorería:** registrar pago (`banking_entity_id`, `payment_amount`, `payment_date`) → padre pasa a `aut_pago`. Hijas no cambian. Intentar regresar a `contabilidad` → debe bloquearse con mensaje "existe un pago pendiente".

6. **Como Contador:** ver `aut_pago`. Probar **rechazo** primero: rechaza con motivo → vuelve a `tesoreria` con `payment_rejection_reason` poblado. Re-registrar pago. **Autorizar** → padre `pagado`, hijas `pagada` con `payment_status=Pago total`, `invoice_payments` con `refund_id`, `status=authorized`, `authorized=true`.

7. **Regresión con motivo** desde `contabilidad → agrupacion` y `tesoreria → contabilidad` (sin pago pendiente). Verificar que el motivo queda en `refund_observations` con `type=regression` y `metadata` correcto. Verificar bloqueo de `tesoreria → contabilidad` con pago pendiente.

8. **Repetir el flujo principal con beneficiario `provider`** (selector cambia, FK distinta).

9. **Permisos:** loguear como Contador → no ve registros en `agrupacion`/`contabilidad`. Loguear como Contabilidad → solo ve `contabilidad`. Loguear como Registro/Revisión → solo `agrupacion`. Admin ve todo.

10. **Sidebar:** badge de pendientes coincide con conteo de registros en estados visibles del rol.

11. **Bloqueos de borrado:** intentar borrar un reintegro fuera de `agrupacion` → debe fallar.

12. **Cs check:** `composer cs-check` queda limpio.

## 10. Riesgos y notas

- **Conflicto semántico con `is_refund`:** `InvoicePayments.is_refund` se usa hoy para pagos de reintegro de Anticipos (ver `AdvanceLegalizationService`). Es un concepto distinto al módulo Refunds: aquí `refund_id` apunta al registro padre de agrupación. Ambos conviven sin colisión. Documentar esta distinción en comentario PHPDoc del campo `refund_id`.
- **`PaymentRegistryService`:** sigue tratando `'reintegro_doc'` como facturas con `document_type=Reintegro` independientes (no agrupadas). Cuando una factura entra a un Refund, `refund_id` se setea pero `document_type` no cambia. Eso significa que las facturas agrupadas siguen apareciendo en el Payment Registry filtrable por tipo `reintegro_doc`. Comportamiento aceptado, idéntico al de Caja Menor.
- **Numeración `code`:** generación en `RefundsTable::beforeSave` usando año actual + secuencia (`SELECT MAX(code)` con prefijo del año). Mismo patrón que PettyCash.
- **Migración idempotente:** usar `$this->hasTable('refunds')` y `$this->hasColumn(...)` antes de crear/agregar para tolerar reruns parciales.
