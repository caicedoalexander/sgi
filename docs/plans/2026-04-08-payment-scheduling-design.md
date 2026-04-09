# Diseño: Sistema de Pagos y Módulo de Programación

**Fecha:** 2026-04-08
**Estado:** Validado

## Resumen

Rediseño del sistema de pagos de facturas para soportar pagos parciales y masivos. Se modifica el pipeline de facturas para que `payment_status` sea calculado a partir de una tabla de pagos (`invoice_payments`), y se crea un nuevo módulo "Programación" para gestionar pagos masivos agrupados con su propio pipeline de autorización.

## Decisiones de Diseño

1. **`payment_status` desnormalizado:** Se recalcula y almacena en `invoices` cada vez que se registra o autoriza un pago (rendimiento + compatibilidad con pipeline existente).
2. **`full_payment_date` automático:** Se llena con la fecha del pago que completa el monto total.
3. **Autorización por pago individual:** Cada `invoice_payment` tiene su propia autorización (`authorized`, `authorized_by`, `authorized_date`). Se eliminan los campos de autorización de `invoices`.
4. **Dos caminos de pago:**
   - **Pago individual:** Tesorería registra un pago directo → factura avanza a `autorizacion_pago` → Contador autoriza → pagada o regresa a tesorería.
   - **Pago vía Programación:** Facturas se agrupan en un registro de Programación → pipeline propio → al autorizar se aplican los pagos y se recalcula cada factura.
5. **Pipeline cíclico en pago individual:** La factura hace el ciclo `tesoreria ↔ autorizacion_pago` tantas veces como pagos parciales haya.
6. **Sin doble autorización:** Pagos vía Programación ya fueron autorizados en el pipeline de Programación, por lo tanto la factura salta `autorizacion_pago` y va directo a `pagada` si el total está cubierto.

---

## Modelo de Datos

### Cambios a tabla `invoices`

| Campo | Cambio | Detalle |
|-------|--------|---------|
| `payment_status` | Se mantiene | Ahora es calculado. Valores: `null`, `'Pago Parcial'`, `'Pago total'` |
| `payment_date` | Renombrar a `full_payment_date` | Se llena automáticamente cuando `SUM(pagos autorizados) >= amount` |
| `payment_authorized` | Eliminar | Se mueve a `invoice_payments` |
| `payment_authorized_by` | Eliminar | Se mueve a `invoice_payments` |
| `payment_authorized_date` | Eliminar | Se mueve a `invoice_payments` |

### Tabla `invoice_payments` (existente, se extiende)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int PK | Auto-increment |
| invoice_id | int FK | Factura vinculada |
| banking_entity_id | int FK | Banco del pago |
| amount | decimal(14,2) | Monto pagado |
| payment_date | date | Fecha del pago |
| payment_scheduling_id | int FK nullable | Programación que originó el pago (null = pago individual) |
| authorized | bool default false | Si fue autorizado por Contador |
| authorized_by | int FK nullable | Contador que autorizó |
| authorized_date | date nullable | Fecha de autorización |
| created_by | int FK | Usuario que registró |
| created | datetime | Timestamp |
| modified | datetime | Timestamp |

### Nueva tabla `payment_schedulings`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int PK | Auto-increment |
| code | varchar(20) | Código único auto-generado (PRO-001, PRO-002...) |
| title | varchar(255) | Descripción (ej: "27 de abril 2026") |
| pipeline_status | varchar(50) | `borrador`, `tesoreria`, `aut_pago`, `pagada` |
| created_by | int FK | Tesorería que creó |
| created | datetime | Timestamp |
| modified | datetime | Timestamp |

### Nueva tabla `payment_scheduling_items`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int PK | Auto-increment |
| payment_scheduling_id | int FK | Registro de programación |
| invoice_id | int FK | Factura vinculada |
| banking_entity_id | int FK | Banco del pago |
| amount | decimal(14,2) | Monto a pagar |
| created | datetime | Timestamp |

> Nota: Esta tabla intermedia almacena las facturas vinculadas durante borrador/tesorería. Al autorizar la Programación, se crean los `invoice_payments` a partir de estos items.

### Nueva tabla `payment_scheduling_attachments`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int PK | Auto-increment |
| payment_scheduling_id | int FK | Registro de programación |
| file_path | varchar(500) | Ruta del archivo |
| file_name | varchar(255) | Nombre original |
| uploaded_by | int FK | Usuario que subió |
| created | datetime | Timestamp |

### Nueva tabla `payment_scheduling_observations`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| id | int PK | Auto-increment |
| payment_scheduling_id | int FK | Registro de programación |
| user_id | int FK | Autor |
| message | text | Contenido |
| created | datetime | Timestamp |

---

## Pipeline de Facturas (modificado)

### Estados

`aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada`

(Sin cambios en los estados, cambia la lógica de transición en tesorería)

### Flujo: Pago Individual (inmediato)

```
tesoreria ──[registra pago]──> autorizacion_pago
                                    │
                          Contador autoriza
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
              Pago total                      Pago Parcial
                    │                               │
                    v                               v
                 pagada                         tesoreria
                                                (ciclo)
```

1. Factura en `tesoreria` → Tesorería registra un pago (banco, monto, fecha)
2. Se crea `invoice_payment` con `authorized = false`
3. Factura avanza a `autorizacion_pago`
4. Contador autoriza → `authorized = true`, se recalcula `payment_status`
5. Si "Pago total" → `full_payment_date` = fecha del pago, avanza a `pagada`
6. Si "Pago Parcial" → regresa a `tesoreria`

### Flujo: Pago vía Programación

```
Factura en tesoreria ──[se vincula a Programación]──> se queda en tesoreria
                                                           │
                                              Programación se autoriza
                                                           │
                                              Se crean invoice_payments
                                              (authorized = true)
                                                           │
                                              Recalcula payment_status
                                                           │
                                       ┌───────────────────┴──────────────┐
                                       │                                  │
                                 Pago total                         Pago Parcial
                                       │                                  │
                                       v                                  v
                                    pagada                     se queda en tesoreria
                              (salta aut_pago)
```

### Recálculo de `payment_status`

Se ejecuta cada vez que se crea o autoriza un pago:

```php
$sumPagos = SUM(invoice_payments.amount WHERE invoice_id = X AND authorized = true)

if ($sumPagos >= $invoice->amount) {
    $invoice->payment_status = 'Pago total';
    $invoice->full_payment_date = $ultimoPagoAutorizado->payment_date;
} elseif ($sumPagos > 0) {
    $invoice->payment_status = 'Pago Parcial';
    $invoice->full_payment_date = null;
} else {
    $invoice->payment_status = null;
    $invoice->full_payment_date = null;
}
```

### Cambios en `TRANSITION_REQUIREMENTS`

**Estado `tesoreria`:**
- Ya no valida `payment_status` ni `payment_date`
- Valida que exista al menos un `invoice_payment` no autorizado (pago individual pendiente de autorización)

**Estado `autorizacion_pago`:**
- Valida que el `invoice_payment` pendiente esté autorizado
- Determina destino: `pagada` si pago total, `tesoreria` si parcial

### Cambios en `EDITABLE_FIELDS`

Tesorería en `tesoreria`: se eliminan `payment_status` y `payment_date`. En su lugar, interactúa con formulario de registro de pagos.

---

## Pipeline de Programación

### Estados

`borrador → tesoreria → aut_pago → pagada`

### Flujo detallado

#### 1. Borrador
- Tesorería crea el registro con título
- Código auto-generado: PRO-001, PRO-002...
- Vincula facturas: carga Excel o agrega manualmente
- Solo facturas en estado `tesoreria` son válidas

#### 2. Tesorería
- Tesorería revisa el listado de facturas y montos
- Confirma que todo esté correcto
- Sube soportes de pago (comprobantes bancarios, etc.)
- Agrega observaciones
- Al avanzar, envía a autorización

#### 3. Aut. Pago
- Contador revisa la programación completa
- Ve: facturas, montos, bancos, soportes, observaciones
- **Autoriza:** se aplican los pagos (ver "Aplicación de pagos")
- **Rechaza:** regresa a `tesoreria` para corrección

#### 4. Pagada
- Estado final
- Todos los pagos fueron aplicados y autorizados

### Aplicación de pagos (al autorizar)

Cuando el Contador autoriza una Programación:

1. Para cada `payment_scheduling_item`:
   - Crear `invoice_payment` con:
     - `invoice_id`, `banking_entity_id`, `amount`, `payment_date` = fecha actual (o del registro)
     - `payment_scheduling_id` = ID de la programación
     - `authorized = true`
     - `authorized_by` = ID del Contador
     - `authorized_date` = fecha actual
     - `created_by` = ID del Tesorero que creó la programación
2. Recalcular `payment_status` de cada factura afectada
3. Facturas con "Pago total" → avanzan directo a `pagada`
4. Facturas con "Pago Parcial" → se quedan en `tesoreria`

### Carga de Excel

#### Columnas requeridas

| Columna | Descripción |
|---------|-------------|
| `numero_factura` | Número de factura del proveedor |
| `nit_proveedor` | NIT del proveedor (para desambiguar) |
| `monto_a_pagar` | Monto a pagar en este ciclo |
| `banco` | Nombre o código del banco |

#### Flujo de validación

1. Tesorería sube el archivo Excel
2. Sistema procesa cada fila y valida:
   - ¿Existe factura con `invoice_number` + NIT del proveedor?
   - ¿Está en estado `tesoreria`?
   - ¿El banco existe en `banking_entities`?
   - ¿El monto es positivo y no excede el saldo pendiente de la factura?
3. Se muestra resumen previo:
   - Facturas válidas (listas para vincular)
   - Facturas rechazadas con motivo del error
4. Tesorería confirma → se crean los `payment_scheduling_items`

---

## Permisos

### Módulo Programación

| Rol | Permisos |
|-----|----------|
| Tesorería | Crear, editar (borrador y tesorería), ver todas |
| Contador | Ver y autorizar en `aut_pago` |
| Admin | Todo |

Se agrega a `$controllerModuleMap` y `AuthorizationService::MODULES`.

---

## Servicios Nuevos

| Servicio | Responsabilidad |
|----------|-----------------|
| `InvoicePaymentService` | Registrar pagos individuales, recalcular `payment_status`, lógica de avance/regreso |
| `PaymentSchedulingPipelineService` | Estados, transiciones, validaciones del pipeline de Programación |
| `PaymentSchedulingService` | Lógica de negocio: importar Excel, aplicar pagos al autorizar, vincular facturas |

---

## Impacto en el Sistema Existente

### Migración de datos
- Renombrar `invoices.payment_date` → `invoices.full_payment_date`
- Eliminar `invoices.payment_authorized`, `payment_authorized_by`, `payment_authorized_date`
- Facturas en `pagada` con `payment_date`: copiar a `full_payment_date`
- `invoice_payments` existentes: agregar campos nuevos con `authorized = true` (pagos previos al nuevo flujo)

### `InvoicePipelineService`
- Modificar `TRANSITION_REQUIREMENTS` para tesorería y autorizacion_pago
- Eliminar `payment_status`, `payment_date` de `EDITABLE_FIELDS` de Tesorería
- Agregar lógica de regreso `autorizacion_pago → tesoreria`

### Lo que NO cambia
- Pipeline aprobación → contabilidad (intacto)
- Sistema de adjuntos y observaciones de facturas (independiente)
- Aprobación externa por tokens (intacto)
- Notificaciones por email (se extienden para Programación)

---

## Archivos a Crear

### Constants
- `src/Constants/PaymentSchedulingConstants.php`

### Model
- `src/Model/Entity/PaymentScheduling.php`
- `src/Model/Entity/PaymentSchedulingItem.php`
- `src/Model/Entity/PaymentSchedulingAttachment.php`
- `src/Model/Entity/PaymentSchedulingObservation.php`
- `src/Model/Table/PaymentSchedulingsTable.php`
- `src/Model/Table/PaymentSchedulingItemsTable.php`
- `src/Model/Table/PaymentSchedulingAttachmentsTable.php`
- `src/Model/Table/PaymentSchedulingObservationsTable.php`

### Services
- `src/Service/InvoicePaymentService.php`
- `src/Service/PaymentSchedulingService.php`
- `src/Service/PaymentSchedulingPipelineService.php`

### Controller
- `src/Controller/PaymentSchedulingsController.php`

### Templates
- `templates/PaymentSchedulings/index.php`
- `templates/PaymentSchedulings/add.php`
- `templates/PaymentSchedulings/edit.php`
- `templates/PaymentSchedulings/view.php`

### Migraciones
- Modificar tabla `invoices` (renombrar/eliminar campos)
- Extender tabla `invoice_payments` (nuevos campos)
- Crear tabla `payment_schedulings`
- Crear tabla `payment_scheduling_items`
- Crear tabla `payment_scheduling_attachments`
- Crear tabla `payment_scheduling_observations`
