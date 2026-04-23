# Diseño: Pagos individuales por factura con badge de módulo origen

**Fecha:** 2026-04-22
**Alcance:** Unificar el registro de pagos de Caja Menor y Legalización con el modelo de Programación de Pagos: generar un `invoice_payments` por cada factura hija al autorizar y mostrar un badge del módulo origen tanto en la ficha de la factura (edit y view) como en el Registro de Pagos global.

---

## 1. Contexto

Hoy coexisten tres flujos con comportamientos distintos:

| Módulo | Tabla de pagos | Granularidad | Badge en factura | Registro de Pagos |
|---|---|---|---|---|
| Programación de pagos | `invoice_payments` (con `payment_scheduling_id`) | 1 fila por factura | Sí, en edit | Individual por factura |
| Caja Menor | `petty_cash_payments` | 1 fila agregada | **No** | Agregado único |
| Legalización | `legalization_payments` | 1 fila agregada | **No** | Agregado único |

Las facturas hijas de Caja Menor y Legalización se marcan como pagadas vía `updateAll` (`PettyCashPaymentService.php:97-104`, `LegalizationPaymentService.php:97-104`), pero **no queda rastro en la ficha de la factura** del pago realizado.

## 2. Decisiones de diseño

| # | Decisión | Justificación |
|---|---|---|
| D1 | Crear `invoice_payments` individuales por factura hija | Uniforma la UI en la ficha de factura y en el Registro de Pagos. |
| D2 | Monto de cada pago = `invoices.total_amount` | Caja Menor/Legalización siempre pagan al 100% (ya marcan `PAYMENT_FULL`). |
| D3 | Materializar al autorizar, no al registrar | Minimiza duplicación. Mientras está pendiente solo existe el agregado. |
| D4 | Forward-only, sin backfill | Los pagos históricos se dejan como están (agregados en `petty_cash_payments` / `legalization_payments`). |
| D5 | Badge con ícono + link al registro padre | `<i class="bi bi-wallet2"></i> Caja Menor CM-001` — estilo neutro gris con borde. |

## 3. Cambios de esquema

### Migración: `AddRecordFksToInvoicePayments`

Agregar a `invoice_payments`:

```php
->addColumn('petty_cash_record_id', 'integer', ['null' => true, 'default' => null, 'after' => 'payment_scheduling_id'])
->addColumn('legalization_record_id', 'integer', ['null' => true, 'default' => null, 'after' => 'petty_cash_record_id'])
->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', ['delete' => 'SET_NULL'])
->addForeignKey('legalization_record_id', 'legalization_records', 'id', ['delete' => 'SET_NULL'])
```

**Invariante:** exactamente uno de `payment_scheduling_id`, `petty_cash_record_id`, `legalization_record_id` puede estar establecido (o ninguno, para pagos individuales). No se agrega constraint de BD para permitir evolución.

### `InvoicePayment` entity y `InvoicePaymentsTable`

- `$_accessible`: añadir `petty_cash_record_id`, `legalization_record_id`.
- Asociaciones `belongsTo`: `PettyCashRecords`, `LegalizationRecords`.

## 4. Flujo de autorización

### Caja Menor (`PettyCashPaymentService::authorizePayment()`)

Reemplazar el `updateAll` por un bloque transaccional:

```php
$connection->transactional(function () use ($record, $payment, $invoicesTable, $invoicePaymentsTable) {
    $childInvoices = $invoicesTable->find()
        ->where(['petty_cash_record_id' => $record->id])
        ->all();

    foreach ($childInvoices as $invoice) {
        $invoicePayment = $invoicePaymentsTable->newEntity([
            'invoice_id' => $invoice->id,
            'banking_entity_id' => $payment->banking_entity_id,
            'amount' => $invoice->total_amount,
            'payment_date' => $payment->payment_date,
            'petty_cash_record_id' => $record->id,
            'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
            'authorized' => true,
            'authorized_by' => $authorizedBy,
            'authorized_date' => date('Y-m-d'),
            'created_by' => $payment->created_by,
        ]);
        $invoicePaymentsTable->saveOrFail($invoicePayment);

        $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
        $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
        $invoice->full_payment_date = $payment->payment_date;
        $invoicesTable->saveOrFail($invoice);
    }
});
```

Equivalente para `LegalizationPaymentService::authorizePayment()` con `legalization_record_id`.

**Rechazo de pago:** no se tocan `invoice_payments` porque aún no se crearon (se materializan solo al autorizar). El flujo de `rejectPayment` actual sigue válido.

## 5. Renderizado del badge

### Element `templates/element/payment_section.php` (columna "Origen")

Extender el bloque actual para soportar tres orígenes:

```php
<?php if (!empty($payment->payment_scheduling_id)): ?>
    <?= $this->Html->link(
        '<i class="bi bi-calendar-check me-1"></i>Programación ' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
        ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
    ) ?>
<?php elseif (!empty($payment->petty_cash_record_id)): ?>
    <?= $this->Html->link(
        '<i class="bi bi-wallet2 me-1"></i>Caja Menor ' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
        ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
    ) ?>
<?php elseif (!empty($payment->legalization_record_id)): ?>
    <?= $this->Html->link(
        '<i class="bi bi-journal-check me-1"></i>Legalización ' . h($payment->legalization_record->code ?? '#' . $payment->legalization_record_id),
        ['controller' => 'LegalizationRecords', 'action' => 'view', $payment->legalization_record_id],
        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
    ) ?>
<?php else: ?>
    <span class="text-muted" style="font-size:.75rem;">Individual</span>
<?php endif;
```

Ícono por tipo:
- Programación → `bi-calendar-check`
- Caja Menor → `bi-wallet2`
- Legalización → `bi-journal-check`

### Bloqueo de acciones

La condición actual `empty($payment->payment_scheduling_id)` del botón Autorizar (línea 187) debe extenderse para que **no se permita autorizar/rechazar/eliminar** un `invoice_payment` originado en Caja Menor o Legalización (ya se autoriza desde el módulo padre):

```php
$isFromModule = !empty($payment->payment_scheduling_id)
    || !empty($payment->petty_cash_record_id)
    || !empty($payment->legalization_record_id);
```

### Ficha `templates/Invoices/view.php`

Actualmente (líneas 349+) muestra estado, usuario autorizador, fecha, pero **no** muestra la columna "Origen". Agregar:

- Columna `<th>Origen</th>` en el header de la tabla.
- Celda con la misma lógica de badge descrita arriba.
- Si la factura tiene `petty_cash_record_id` o `legalization_record_id` pero **no** tiene `invoice_payments` (caso histórico pre-cambio), mostrar un aviso al final de la sección: *"Pagada vía Caja Menor CM-XXX (pago registrado antes del cambio de formato)"* con link al registro padre.

El controller `InvoicesController::view()` debe `contain` en los pagos: `'PaymentSchedulings', 'PettyCashRecords', 'LegalizationRecords'`.

## 6. Registro de Pagos global

`PaymentRegistryService.php` actualmente hace UNION de las cuatro tablas. Tras el cambio, los pagos nuevos de Caja Menor/Legalización aparecerán como `invoice_payments` (uno por factura hija), y el agregado en `petty_cash_payments`/`legalization_payments` también existirá — **duplicación visual**.

### Filtro anti-duplicación

En `_queryPettyCashPayments` y `_queryLegalizationPayments`: excluir registros que tengan `invoice_payments` hijos (los nuevos):

```php
$subquery = TableRegistry::getTableLocator()->get('InvoicePayments')
    ->find()
    ->select(['petty_cash_record_id'])
    ->where(['petty_cash_record_id IS NOT' => null]);

$query->where(function ($exp) use ($subquery) {
    return $exp->notIn('PettyCashPayments.petty_cash_record_id', $subquery);
});
```

Resultado:
- **Pagos nuevos** (post-deploy): solo aparecen las filas individuales de `invoice_payments` con badge "Caja Menor CM-XXX".
- **Pagos históricos** (pre-deploy): siguen visibles como fila agregada `petty_cash_payments` / `legalization_payments` (legacy, sin cambio).

### Adaptación del mapper de `_queryInvoicePayments`

Agregar a la estructura devuelta campos opcionales `source_type` / `source_ref` para que el template del Registro renderice el badge del módulo origen cuando aplique.

## 7. Puntos sin tocar

- **`PaymentRegistryService.php::_queryInvoicePayments`** ya incluye los pagos con `payment_scheduling_id`. Solo se extiende el contain para traer también `PettyCashRecords` y `LegalizationRecords`.
- **Registro padre (`PettyCashRecords::view`, `LegalizationRecords::view`)**: sigue mostrando la cabecera agregada + la lista de facturas hijas. Sin cambios.
- **Reglas de avance de estado (pipeline de factura)**: sin cambios. La factura sigue avanzando a `pagada` dentro de la misma transacción.

## 8. Plan de implementación (alto nivel)

1. Migración `AddRecordFksToInvoicePayments` con FKs nullable.
2. Ajustar `InvoicePayment` entity y `InvoicePaymentsTable` (asociaciones, `$_accessible`).
3. Refactor `PettyCashPaymentService::authorizePayment()` para crear N `invoice_payments` transaccionalmente.
4. Refactor `LegalizationPaymentService::authorizePayment()` idem.
5. Extender `templates/element/payment_section.php` con tres orígenes + ícono.
6. Extender `templates/Invoices/view.php` con columna Origen (el usuario lo solicitó explícitamente como Punto 1).
7. Ajustar `PaymentRegistryService` con el filtro anti-duplicación y el contain extendido.
8. Ajustar `InvoicesController::view()` y `::edit()` para contain de los tres orígenes en los pagos.
9. Pruebas manuales:
   - Autorizar un pago nuevo de Caja Menor → verificar N `invoice_payments` creados y badges visibles en factura hija (edit y view).
   - Rechazar un pago de Caja Menor → no debe crear `invoice_payments`.
   - Registro de Pagos: verificar que solo aparecen filas individuales para Caja Menor nueva, y el agregado legacy sigue visible para los históricos.

## 9. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Doble conteo de totales en Registro de Pagos si falla el filtro | Pruebas con datos mixtos (históricos + nuevos). |
| Una factura hija con `total_amount` cero o nulo | Validar antes del `saveOrFail`; si `total_amount <= 0`, saltarla y loguear. |
| Factura hija ya tiene un `invoice_payment` previo (p.ej. pago parcial antes de vincularse a Caja Menor) | Asumimos que Caja Menor solo acepta facturas sin pagos previos (invariante actual). Verificar en la carga. |
| `payment_section.php` usado por 4 módulos (Invoices, Novelty, Legalization, PettyCash) | Los nuevos campos son opcionales y retrocompatibles; los otros módulos no los verán si no existen. |

## 10. Fuera de alcance

- No se migran pagos históricos (forward-only).
- No se refactoriza la autorización de Caja Menor/Legalización para que actúe sobre cada factura hija individualmente — sigue siendo "todo o nada" a nivel del registro padre.
- No se toca el flujo de Novelty Liquidation Docs (`liquidation_doc_payments`), que conserva su modelo agregado actual (se puede replicar este diseño en una fase futura si se quiere consistencia total).
