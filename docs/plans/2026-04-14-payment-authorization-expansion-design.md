# Expansión de Autorización de Pago — Diseño

**Fecha:** 2026-04-14
**Módulos afectados:** Documentos de Liquidación, Caja Menor, Legalizaciones, Registro de Pagos (nuevo)

## Contexto

Facturas y Programación de Pagos ya tienen el estado `aut_pago`/`autorizacion_pago` donde el Contador autoriza el pago antes de marcarlo como pagado. Los módulos de Documentos de Liquidación, Caja Menor y Legalizaciones saltan directo de `tesoreria` a `pagado/pagada` sin esta autorización.

Adicionalmente, se necesita una vista unificada de todos los pagos del sistema como nuevo item en el sidebar.

## Decisiones de diseño

- **Sin pago parcial** en los tres módulos — se registra un solo pago por el total.
- **Rechazo regresa a `tesoreria`** — Contador puede rechazar y el registro vuelve a Tesorería.
- **Tablas de pagos separadas por módulo** (opción A) — mantiene FKs reales en BD, sin polimorfismo. Un servicio unificado hace UNION para la vista global.
- **Sin migración de datos existentes** — registros ya en `pagado/pagada` quedan como están. Registros en `tesoreria` siguen el nuevo flujo.

## Sección 1: Cambios en Constants

### NoveltyConstants — Documentos de Liquidación

Pipeline actual: `aprobacion → rrhh → contabilidad → revision_firmas → gdp → tesoreria → pagada`
Pipeline nuevo: `aprobacion → rrhh → contabilidad → revision_firmas → gdp → tesoreria → aut_pago → pagada`

- Nueva constante `STATUS_AUT_PAGO = 'aut_pago'`
- Se agrega a `PIPELINE_STATUSES` entre `tesoreria` y `pagada`
- `STATUS_LABELS['aut_pago'] = 'Aut. Pago'`
- `STATUS_ICONS['aut_pago'] = 'bi-shield-check'`
- `TRANSITIONS`: `tesoreria → aut_pago`, `aut_pago → pagada` (antes `tesoreria → pagada`)

### PettyCashConstants — Caja Menor

Pipeline actual: `agrupacion → contabilidad → tesoreria → pagado`
Pipeline nuevo: `agrupacion → contabilidad → tesoreria → aut_pago → pagado`

- Nueva constante `STATUS_AUT_PAGO = 'aut_pago'`
- Se agrega a `STATUSES` y `TRANSITIONS`
- `STATUS_LABELS['aut_pago'] = 'Aut. Pago'`, icono `bi-shield-check`
- `TRANSITIONS`: `tesoreria → aut_pago`, `aut_pago → pagado`

### LegalizationConstants — Legalizaciones

Idéntico a Caja Menor (misma estructura de pipeline).

## Sección 2: Nuevas tablas de pagos

### `liquidation_doc_payments`

| Columna | Tipo | Notas |
|---------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| liquidation_doc_id | INT NOT NULL | FK → novelty_liquidation_docs |
| banking_entity_id | INT NOT NULL | FK → banking_entities |
| amount | DECIMAL(15,2) NOT NULL | |
| payment_date | DATE NOT NULL | |
| authorized | BOOLEAN DEFAULT FALSE | |
| authorized_by | INT NULL | FK → users |
| authorized_date | DATE NULL | |
| created_by | INT NOT NULL | FK → users |
| created | DATETIME | |
| modified | DATETIME | |

### `petty_cash_payments`

| Columna | Tipo | Notas |
|---------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| petty_cash_record_id | INT NOT NULL | FK → petty_cash_records |
| banking_entity_id | INT NOT NULL | FK → banking_entities |
| amount | DECIMAL(15,2) NOT NULL | |
| payment_date | DATE NOT NULL | |
| authorized | BOOLEAN DEFAULT FALSE | |
| authorized_by | INT NULL | FK → users |
| authorized_date | DATE NULL | |
| created_by | INT NOT NULL | FK → users |
| created | DATETIME | |
| modified | DATETIME | |

### `legalization_payments`

| Columna | Tipo | Notas |
|---------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| legalization_record_id | INT NOT NULL | FK → legalization_records |
| banking_entity_id | INT NOT NULL | FK → banking_entities |
| amount | DECIMAL(15,2) NOT NULL | |
| payment_date | DATE NOT NULL | |
| authorized | BOOLEAN DEFAULT FALSE | |
| authorized_by | INT NULL | FK → users |
| authorized_date | DATE NULL | |
| created_by | INT NOT NULL | FK → users |
| created | DATETIME | |
| modified | DATETIME | |

## Sección 3: Lógica de pipeline y servicios

### NoveltyPipelineService — Cambios

- `ROLE_VISIBLE_STATUSES`: Contador ve `aut_pago` y `pagada`.
- `VISIBLE_SECTIONS_BY_ROLE`: Contador agrega visibilidad de `aut_pago`.
- `SECTIONS_BY_STATUS`: Agregar entrada para `aut_pago`.
- Transición `tesoreria → aut_pago`: se dispara al registrar pago, no con botón genérico.
- Transición `aut_pago → pagada`: Contador autoriza el pago.
- Rechazo en `aut_pago`: regresa a `tesoreria`, se elimina el pago pendiente.

### Nuevos servicios de pago

**`LiquidationDocPaymentService`**
- `registerPayment(docId, paymentData, createdBy)` — Crea pago, avanza doc a `aut_pago`. Valida monto = total del documento.
- `authorizePayment(paymentId, authorizedBy)` — Autoriza, avanza a `pagada`.
- `rejectPayment(paymentId, rejectedBy)` — Elimina pago, regresa a `tesoreria`.

**`PettyCashPaymentService`**
- Misma estructura que `LiquidationDocPaymentService` pero opera sobre `petty_cash_payments` y `petty_cash_records`.

**`LegalizationPaymentService`**
- Misma estructura, opera sobre `legalization_payments` y `legalization_records`.

### Cambios en PettyCashService y LegalizationService

En `advanceStatus()`: cuando el estado siguiente es `aut_pago`, el avance se bloquea por botón genérico — se avanza al registrar el pago. Cuando el estado actual es `aut_pago`, se avanza cuando el Contador autoriza.

## Sección 4: Vista unificada — Registro de Pagos

### PaymentRegistryService

Consulta las 4 tablas de pagos y unifica en formato común:

```
type, type_label, reference, banking_entity, amount, payment_date,
authorized, authorized_by, authorized_date, created_by, created
```

4 queries separadas → normalización → array combinado ordenado por `created DESC`.

### Filtros

- Tipo de documento (invoice, petty_cash, legalization, liquidation_doc)
- Estado (Autorizado / Pendiente)
- Rango de fechas de pago
- Entidad bancaria

### Sidebar

- Nuevo item bajo Facturación
- Icono: `bi-cash-stack`
- Label: "Registro de Pagos"
- Ruta: `/payment-registry`
- Visible para: Admin, Tesorería, Contador

### Controller y permisos

- `PaymentRegistryController` con action `index` (solo lectura)
- Template `templates/PaymentRegistry/index.php`
- Módulo `payment-registry` en `$controllerModuleMap`, `AuthorizationService::MODULES`, tabla `permissions`

## Sección 5: Edge cases y migración

### Registros existentes

- **En `tesoreria`:** No se migran. Siguen el nuevo flujo (Tesorería registra pago para avanzar).
- **En `pagado/pagada`:** Quedan como están. Sin registro retroactivo en tablas de pagos.

### Tabla de validaciones de avance

| Módulo | De → A | Requisito |
|--------|--------|-----------|
| Doc. Liquidación | tesoreria → aut_pago | Registrar pago (monto = total doc) |
| Doc. Liquidación | aut_pago → pagada | Contador autoriza pago |
| Doc. Liquidación | aut_pago → tesoreria | Contador rechaza pago |
| Caja Menor | tesoreria → aut_pago | Registrar pago (monto = total record) |
| Caja Menor | aut_pago → pagado | Contador autoriza pago |
| Caja Menor | aut_pago → tesoreria | Contador rechaza pago |
| Legalizaciones | tesoreria → aut_pago | Registrar pago (monto = total record) |
| Legalizaciones | aut_pago → pagado | Contador autoriza pago |
| Legalizaciones | aut_pago → tesoreria | Contador rechaza pago |

### Pipeline visual

Los elements de progreso de cada módulo se actualizan para mostrar el nuevo paso `aut_pago` con icono `bi-shield-check`.
