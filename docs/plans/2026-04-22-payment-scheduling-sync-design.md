# Sincronización de pagos desde Programación de Facturas

Fecha: 2026-04-22

## Contexto

La funcionalidad de Programación de Pagos (`PaymentScheduling`) permite agrupar múltiples facturas en un flujo único de autorización: Tesorería carga el Excel → Contador autoriza → se ejecutan los pagos. Al completarse, `PaymentSchedulingService::applyPayments()` crea un `InvoicePayment` por cada factura vinculada.

Actualmente presenta tres problemas:

1. **Desincronización de estado del pago**. Los pagos creados desde una programación aparecen como "pendientes de autorización" al entrar a la vista individual de la factura, aunque en la programación se muestren correctamente como autorizados. La factura no avanza a `pagada`.
2. **Falta de trazabilidad**. No hay indicador en la factura individual que permita saber que ese pago se originó en una programación específica.
3. **Edición permitida sobre facturas de programaciones cerradas**. Si una factura tiene pagos provenientes de una programación cuyo flujo ya terminó (`pipeline_status = pagada`), sigue siendo editable por roles no-admin.

## Diagnóstico

### Problema 1

En `PaymentSchedulingService::applyPayments()` (líneas 255-265) los pagos se crean con `authorized=true`, `authorized_by`, `authorized_date`, pero **no se setea `status='authorized'`**. El campo `status` queda en su default `'pending'`.

El resto del sistema filtra por `status`, no por `authorized`:

- `InvoicePaymentService::recalculatePaymentStatus()` filtra `status = PAYMENT_RECORD_AUTHORIZED` → no suma estos pagos.
- `InvoicePaymentService::getPendingBalance()` igual → retorna saldo incorrecto.
- Las vistas muestran el pago como pendiente.

Consecuencia: `payment_status` de la factura no se calcula, el `if` que avanzaría a `pagada` (línea 285) nunca se cumple.

### Problema 2

`invoice_payments.payment_scheduling_id` existe en BD y se guarda, pero ninguna vista de factura lo usa para trazabilidad visual.

### Problema 3

No existe regla en `InvoicePipelineService` ni en `InvoiceFieldAccessPolicy` que mire la programación vinculada. El caso crítico: pago parcial desde una programación `pagada` devuelve la factura a `tesoreria`, donde Tesorería podría volver a editarla.

## Decisiones

- **Alcance del bloqueo de edición**: estricto. Cualquier pago vinculado a programación `pagada` bloquea toda edición salvo Admin.
- **Ubicación del badge de trazabilidad**: solo en la lista de pagos de la factura, una columna con link `Programación #N`.
- **Manejo de datos históricos**: fix solo hacia adelante. Los pagos creados antes del fix se normalizarán manualmente si es necesario.

## Diseño

### Sección 1 — Fix del status desincronizado

**Archivo**: `src/Service/PaymentSchedulingService.php::applyPayments()`

Agregar la clave `status` al array del `newEntity`:

```php
$payment = $paymentsTable->newEntity([
    'invoice_id' => $item->invoice_id,
    'banking_entity_id' => $item->banking_entity_id,
    'amount' => $item->amount,
    'payment_date' => date('Y-m-d'),
    'payment_scheduling_id' => $schedulingId,
    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
    'authorized' => true,
    'authorized_by' => $authorizedBy,
    'authorized_date' => date('Y-m-d'),
    'created_by' => $scheduling->created_by,
]);
```

Efecto cascada (sin cambios adicionales):

- `recalculatePaymentStatus($invoiceId)` (ya invocado en línea 282) sumará los pagos y calculará `payment_status` correcto.
- Línea 285 (`if payment_status === PAYMENT_FULL`) avanzará la factura a `pagada` cuando corresponda.
- La vista individual de la factura mostrará el pago como autorizado.
- `getPendingBalance()` retornará el saldo correcto.

### Sección 2 — Badge de trazabilidad

**Association**. Verificar/agregar en `src/Model/Table/InvoicePaymentsTable.php`:
```php
$this->belongsTo('PaymentSchedulings');
```
Y en `PaymentSchedulingsTable`:
```php
$this->hasMany('InvoicePayments');
```

**Controller**. En `InvoicesController::view()`, asegurar el contain:
```php
'InvoicePayments' => ['PaymentSchedulings', 'BankingEntities', ...]
```

**Template**. En `templates/element/payment_section.php`, dentro del `<tr>` de cada pago, agregar celda:

```php
<?php if (!empty($payment->payment_scheduling_id)): ?>
    <?= $this->Html->link(
        'Programación #' . $payment->payment_scheduling_id,
        ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
        ['class' => 'badge bg-light text-dark text-decoration-none border']
    ) ?>
<?php endif; ?>
```

Pagos manuales (sin `payment_scheduling_id`) dejan la celda vacía.

### Sección 3 — Bloqueo de edición estricto

**Nuevo método** en `src/Service/InvoicePipelineService.php`:

```php
public function isLockedByPaidScheduling(int $invoiceId): bool
{
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
    return $paymentsTable->find()
        ->matching('PaymentSchedulings', fn($q) => $q->where([
            'PaymentSchedulings.pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA,
        ]))
        ->where(['InvoicePayments.invoice_id' => $invoiceId])
        ->count() > 0;
}
```

**Enforcement en `InvoicesController::edit($id)`**:

Al inicio, tras cargar `$invoice` y obtener `$roleName`:

```php
if ($roleName !== RoleConstants::ADMIN
    && $this->pipelineService->isLockedByPaidScheduling($id)) {
    $this->Flash->warning('Factura bloqueada: tiene pagos de una programación ya pagada.');
    return $this->redirect(['action' => 'view', $id]);
}
```

Se ubica **antes** de cualquier lógica de guardado → POST también queda bloqueado.

**Templates**: exponer `$isLockedByScheduling` desde el controller hacia `index.php`, `view.php`, `edit.php` y condicionar la visibilidad del botón "Editar" con esta variable en combinación con las reglas existentes.

## Casos cubiertos

| Escenario | Resultado |
|-----------|-----------|
| Programación pagada, factura full | Factura en `pagada`, bloqueada por estado existente |
| Programación pagada, factura partial (vuelve a `tesoreria`) | Bloqueada por nueva regla |
| Programación en `aut_pago`, no autorizada aún | No bloquea (flujo normal de edición por rol) |
| Pago manual individual (sin programación) | No bloquea, comportamiento actual |
| Usuario Admin | Bypass total |

## Archivos tocados

- `src/Service/PaymentSchedulingService.php` — fix `applyPayments()`
- `src/Service/InvoicePipelineService.php` — nuevo método `isLockedByPaidScheduling`
- `src/Model/Table/InvoicePaymentsTable.php` — verificar association
- `src/Model/Table/PaymentSchedulingsTable.php` — verificar association
- `src/Controller/InvoicesController.php` — enforcement en `edit` y pasar var a views
- `templates/element/payment_section.php` — badge de trazabilidad
- `templates/Invoices/index.php`, `view.php`, `edit.php` — ocultar botón Editar

## Out of scope

- Migración de datos para pagos históricos con el bug de status.
- Mostrar información ampliada (fecha/estado de la programación) en la factura: solo link al número.
- Cambios en la vista de la programación en sí.
- Reglas de bloqueo basadas en otros estados de programación distintos a `pagada`.

## Verificación manual

1. Crear programación con 1 factura cuyo monto = total de factura. Autorizar. Verificar:
   - El pago aparece autorizado en `InvoicesController::view`.
   - `payment_status` = `Pago total`, `pipeline_status` = `pagada`.
   - Badge "Programación #N" con link visible.
2. Crear programación con monto parcial. Autorizar. Verificar:
   - Pago autorizado.
   - `payment_status` = `Pago Parcial`, factura en `tesoreria`.
   - Como rol Tesorería, intentar entrar a `edit` → redirect a `view` con flash.
   - Como Admin, `edit` accesible.
3. Pago manual individual (sin programación): comportamiento actual intacto, sin badge.
