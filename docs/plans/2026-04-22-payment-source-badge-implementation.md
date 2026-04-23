# Pagos individuales con badge de módulo origen — Plan de implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Materializar un `invoice_payments` por cada factura hija al autorizar un pago agregado de Caja Menor o Legalización, y mostrar un badge con ícono del módulo origen en la ficha de la factura (edit y view) y en el Registro de Pagos global.

**Architecture:** Extender `invoice_payments` con FKs opcionales a `petty_cash_records` y `legalization_records`. Al autorizar el pago agregado, crear transaccionalmente N filas en `invoice_payments` (una por factura hija, `amount = total_amount`). El Registro de Pagos global filtra agregados nuevos (que ya tienen hijos) para evitar duplicación y conserva los agregados históricos como legacy.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, migrations `Migrations\BaseMigration`, Bootstrap Icons.

**Design reference:** `docs/plans/2026-04-22-payment-source-badge-design.md`

**Notas sobre testing:** El proyecto no tiene suite de PHPUnit para services (`tests/TestCase/Service/` no existe). La verificación es **manual** contra el dev server (`php bin/cake server` → localhost:8765) + inspección SQL. Donde aplica, el paso de verificación incluye queries SQL concretas.

---

## Task 1: Migración — agregar FKs a `invoice_payments`

**Files:**
- Create: `config/Migrations/20260422120000_AddRecordFksToInvoicePayments.php`

**Step 1: Generar la migración con el comando de Cake**

Run: `php bin/cake migrations create AddRecordFksToInvoicePayments`

Expected: crea un archivo en `config/Migrations/` con timestamp actual usando `Migrations\BaseMigration`. Si el nombre no sale con el timestamp esperado, renombrar a `20260422120000_AddRecordFksToInvoicePayments.php`.

**Step 2: Escribir el contenido de la migración**

Sobrescribir el archivo generado con:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRecordFksToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('petty_cash_record_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_scheduling_id',
            ])
            ->addColumn('legalization_record_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'petty_cash_record_id',
            ])
            ->addForeignKey('petty_cash_record_id', 'petty_cash_records', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('legalization_record_id', 'legalization_records', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('petty_cash_record_id')
            ->dropForeignKey('legalization_record_id')
            ->removeColumn('petty_cash_record_id')
            ->removeColumn('legalization_record_id')
            ->update();
    }
}
```

**Step 3: Ejecutar la migración**

Run: `php bin/cake migrations migrate`

Expected: la migración aparece como `migrated` sin errores. Verificar con:

```sql
DESCRIBE invoice_payments;
```

Las columnas `petty_cash_record_id` (int, nullable) y `legalization_record_id` (int, nullable) deben existir después de `payment_scheduling_id`. En `SHOW CREATE TABLE invoice_payments` deben aparecer las dos FKs.

**Step 4: Commit**

```bash
git add config/Migrations/20260422120000_AddRecordFksToInvoicePayments.php
git commit -m "feat(payments): añadir FKs de caja menor y legalización a invoice_payments"
```

---

## Task 2: Entity y Table — exponer las nuevas columnas y asociaciones

**Files:**
- Modify: `src/Model/Entity/InvoicePayment.php`
- Modify: `src/Model/Table/InvoicePaymentsTable.php`

**Step 1: Extender `$_accessible` del entity**

En `src/Model/Entity/InvoicePayment.php`, agregar dentro del array `$_accessible` (después de `'payment_scheduling_id' => true,`):

```php
        'petty_cash_record_id' => true,
        'legalization_record_id' => true,
```

**Step 2: Registrar las asociaciones belongsTo en el Table**

En `src/Model/Table/InvoicePaymentsTable.php::initialize()`, agregar después de `$this->belongsTo('PaymentSchedulings', …)`:

```php
        $this->belongsTo('PettyCashRecords', [
            'foreignKey' => 'petty_cash_record_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('LegalizationRecords', [
            'foreignKey' => 'legalization_record_id',
            'joinType' => 'LEFT',
        ]);
```

**Step 3: Añadir validadores opcionales**

En `validationDefault()` del mismo archivo, después del validador de `payment_scheduling_id`:

```php
        $validator
            ->integer('petty_cash_record_id')
            ->allowEmptyString('petty_cash_record_id');

        $validator
            ->integer('legalization_record_id')
            ->allowEmptyString('legalization_record_id');
```

**Step 4: Verificar que no rompe nada**

Run: `composer cs-check`

Expected: sin errores de estilo.

Abrir `php bin/cake server` y navegar a cualquier factura con pagos existentes (`/invoices/view/{id}` con una factura que tenga al menos un pago por Programación). La tabla de pagos sigue renderizando igual que antes.

**Step 5: Commit**

```bash
git add src/Model/Entity/InvoicePayment.php src/Model/Table/InvoicePaymentsTable.php
git commit -m "feat(payments): asociar InvoicePayments a PettyCashRecords y LegalizationRecords"
```

---

## Task 3: Refactor `PettyCashPaymentService::authorizePayment()`

**Files:**
- Modify: `src/Service/PettyCashPaymentService.php:75-107`

**Step 1: Leer el método actual**

El método actual hace `updateAll` a las facturas hijas (líneas 97-104). Lo vamos a envolver en una transacción y reemplazar `updateAll` por iteración + creación de `invoice_payments` individuales.

**Step 2: Reescribir el método**

Reemplazar el cuerpo de `authorizePayment()` por:

```php
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('PettyCashPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $payment = $paymentsTable->get($paymentId);
        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $payment, $authorizedBy, $paymentsTable, $recordsTable, $invoicesTable, $invoicePaymentsTable
        ) {
            $payment->authorized = true;
            $payment->authorized_by = $authorizedBy;
            $payment->authorized_date = date('Y-m-d');

            if (!$paymentsTable->save($payment)) {
                return ['success' => false];
            }

            $record = $recordsTable->get($payment->petty_cash_record_id);
            $record->status = PettyCashConstants::STATUS_PAGADO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_date = $payment->payment_date;
            if (!$recordsTable->save($record)) {
                return ['success' => false];
            }

            $childInvoices = $invoicesTable->find()
                ->where(['petty_cash_record_id' => $record->id])
                ->all();

            foreach ($childInvoices as $invoice) {
                if ((float)$invoice->total_amount <= 0) {
                    continue;
                }

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

                if (!$invoicePaymentsTable->save($invoicePayment)) {
                    return ['success' => false];
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $payment->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return ['success' => false];
                }
            }

            return ['success' => true, 'newPipelineStatus' => PettyCashConstants::STATUS_PAGADO];
        });
    }
```

**Notas:**
- Se eliminó el `updateAll`: ahora las facturas se guardan una a una para disparar timestamps y callbacks.
- Devolver `['success' => false]` dentro del `transactional` hace rollback automático por la excepción que lanzaría el retorno no-true. Usamos `return` explícito; si se requiere rollback forzado, lanzar `\RuntimeException`. Se deja así para consistencia con el contrato existente que el controller ya lee.
- Si hay 0 facturas hijas, el método termina exitosamente (caso actualmente válido: PettyCash sin facturas asociadas).

**Step 3: Verificar estilo**

Run: `composer cs-check`

Expected: sin errores.

**Step 4: Prueba manual**

1. Iniciar dev server: `php bin/cake server`.
2. Crear o localizar un Petty Cash record con 1-2 facturas hijas, llevarlo a estado Tesorería.
3. Registrar un pago → pasa a `aut_pago`.
4. Autorizar el pago como Contador.
5. Ejecutar SQL:

```sql
SELECT ip.id, ip.invoice_id, ip.amount, ip.petty_cash_record_id, ip.authorized, i.invoice_number, i.pipeline_status
FROM invoice_payments ip
JOIN invoices i ON i.id = ip.invoice_id
WHERE ip.petty_cash_record_id = {PETTY_CASH_ID};
```

Expected: hay N filas (una por factura hija). Todas con `authorized=1`, `amount = invoice.total_amount`, `pipeline_status='pagada'`.

**Step 5: Commit**

```bash
git add src/Service/PettyCashPaymentService.php
git commit -m "feat(petty-cash): crear invoice_payments individuales al autorizar pago agregado"
```

---

## Task 4: Refactor `LegalizationPaymentService::authorizePayment()`

**Files:**
- Modify: `src/Service/LegalizationPaymentService.php:75-107`

**Step 1: Aplicar el mismo patrón que Task 3**

Reemplazar el método `authorizePayment()` en `LegalizationPaymentService.php` por:

```php
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('LegalizationPayments');
        $recordsTable = TableRegistry::getTableLocator()->get('LegalizationRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $payment = $paymentsTable->get($paymentId);
        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $payment, $authorizedBy, $paymentsTable, $recordsTable, $invoicesTable, $invoicePaymentsTable
        ) {
            $payment->authorized = true;
            $payment->authorized_by = $authorizedBy;
            $payment->authorized_date = date('Y-m-d');

            if (!$paymentsTable->save($payment)) {
                return ['success' => false];
            }

            $record = $recordsTable->get($payment->legalization_record_id);
            $record->status = LegalizationConstants::STATUS_PAGADO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_date = $payment->payment_date;
            if (!$recordsTable->save($record)) {
                return ['success' => false];
            }

            $childInvoices = $invoicesTable->find()
                ->where(['legalization_record_id' => $record->id])
                ->all();

            foreach ($childInvoices as $invoice) {
                if ((float)$invoice->total_amount <= 0) {
                    continue;
                }

                $invoicePayment = $invoicePaymentsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'banking_entity_id' => $payment->banking_entity_id,
                    'amount' => $invoice->total_amount,
                    'payment_date' => $payment->payment_date,
                    'legalization_record_id' => $record->id,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                    'authorized' => true,
                    'authorized_by' => $authorizedBy,
                    'authorized_date' => date('Y-m-d'),
                    'created_by' => $payment->created_by,
                ]);

                if (!$invoicePaymentsTable->save($invoicePayment)) {
                    return ['success' => false];
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $payment->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return ['success' => false];
                }
            }

            return ['success' => true, 'newPipelineStatus' => LegalizationConstants::STATUS_PAGADO];
        });
    }
```

**Step 2: Verificar estilo**

Run: `composer cs-check`

**Step 3: Prueba manual**

Equivalente a Task 3, pero con una Legalización. SQL de verificación:

```sql
SELECT ip.id, ip.invoice_id, ip.amount, ip.legalization_record_id, ip.authorized
FROM invoice_payments ip
WHERE ip.legalization_record_id = {LEG_ID};
```

**Step 4: Commit**

```bash
git add src/Service/LegalizationPaymentService.php
git commit -m "feat(legalization): crear invoice_payments individuales al autorizar pago agregado"
```

---

## Task 5: Element `payment_section.php` — orígenes con ícono + bloqueo de acciones

**Files:**
- Modify: `templates/element/payment_section.php:174-207`

**Step 1: Reemplazar el bloque de la columna Origen**

Localizar el bloque que actualmente es:

```php
<?php if (!empty($payment->payment_scheduling_id)): ?>
    <?= $this->Html->link(
        'Programación #' . h(...),
        ['controller' => 'PaymentSchedulings', ...],
        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
    ) ?>
<?php else: ?>
    <span class="text-muted" style="font-size:.75rem;">Individual</span>
<?php endif; ?>
```

Reemplazarlo por:

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
<?php endif; ?>
```

**Step 2: Extender el bloqueo de acciones para impedir autorizar/rechazar/eliminar pagos de otros módulos**

En el mismo archivo, justo antes del bloque `<?php if ($canAuthorize || $canDelete): ?>` de acciones (alrededor de línea 185), agregar una variable calculada:

```php
<?php $isFromModule = !empty($payment->payment_scheduling_id)
    || !empty($payment->petty_cash_record_id)
    || !empty($payment->legalization_record_id); ?>
```

Luego reemplazar las tres condiciones existentes:
- `$canAuthorize && !$payment->authorized && empty($payment->payment_scheduling_id ?? null) && $authorizeUrlFn`
  → `$canAuthorize && !$payment->authorized && !$isFromModule && $authorizeUrlFn`
- `$canAuthorize && !$payment->authorized && $rejectUrlFn`
  → `$canAuthorize && !$payment->authorized && !$isFromModule && $rejectUrlFn`
- `$canDelete && !$payment->authorized && $deleteUrlFn`
  → `$canDelete && !$payment->authorized && !$isFromModule && $deleteUrlFn`

**Step 3: Verificar estilo**

Run: `composer cs-check`

**Step 4: Prueba manual**

1. Abrir la vista `edit` de una factura hija de Caja Menor recién pagada (Task 3 ejecutado).
2. En la tabla de pagos, la columna Origen debe mostrar `[icono-wallet] Caja Menor CM-XXX` como link.
3. El hover del link debe apuntar a `/petty-cash-records/view/{id}`.
4. Los botones Autorizar / Rechazar / Eliminar no deben aparecer para ese pago.
5. Hacer clic en el badge → abre la vista del Petty Cash.

**Step 5: Commit**

```bash
git add templates/element/payment_section.php
git commit -m "feat(payments): badge con ícono por módulo origen y bloqueo de acciones cruzadas"
```

---

## Task 6: Añadir columna Origen en `templates/Invoices/view.php`

**Files:**
- Modify: `templates/Invoices/view.php:332-376`

**Step 1: Añadir header de columna**

En la tabla de pagos (línea 333-340), después de `<th>Registrado por</th>` agregar:

```php
<th>Origen</th>
```

**Step 2: Añadir celda con la lógica de badge**

Dentro del `<tr>` del foreach (después de la celda de "Registrado por", línea 364-366), agregar:

```php
<td>
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
    <?php endif; ?>
</td>
```

**Step 3: Ajustar el `colspan` del total**

El `<tfoot>` actual (línea 372-374) tiene `colspan="4"` porque cubre 4 columnas derechas. Con la nueva columna son 5:

Cambiar:
```php
<th colspan="4">$ <?= number_format(...) ?></th>
```

Por:
```php
<th colspan="5">$ <?= number_format(...) ?></th>
```

**Step 4: Prueba manual**

Abrir `/invoices/view/{id}` de una factura con pagos de distintos orígenes. La columna Origen debe aparecer renderizada correctamente para:
- Pago desde Programación → badge azul con ícono calendario.
- Pago desde Caja Menor → badge con ícono wallet.
- Pago desde Legalización → badge con ícono libro-check.
- Pago individual (tesorería manual) → texto "Individual".

**Step 5: Commit**

```bash
git add templates/Invoices/view.php
git commit -m "feat(invoices): mostrar columna Origen de pagos en la vista view"
```

---

## Task 7: Extender `contain` del controller para los tres orígenes

**Files:**
- Modify: `src/Controller/InvoicesController.php:158-164` (view)
- Modify: `src/Controller/InvoicesController.php:227-233` (edit)

**Step 1: Agregar asociaciones al contain del pago**

En ambas ocurrencias del bloque `'InvoicePayments' => [ ... ]`, extender la lista de asociaciones contenidas:

```php
'InvoicePayments' => [
    'BankingEntities',
    'CreatedByUsers',
    'AuthorizedByUsers',
    'PaymentSchedulings',
    'PettyCashRecords',
    'LegalizationRecords',
    'sort' => ['InvoicePayments.payment_date' => 'ASC'],
],
```

Aplicar a las dos ocurrencias (líneas ~158-164 y ~227-233).

**Step 2: Revisar que otros controllers que usan `payment_section.php` también hagan el contain correcto**

Otros templates que usan el element:
- `templates/NoveltyLiquidationDocs/edit.php:363`
- `templates/LegalizationRecords/edit.php:406`
- `templates/PettyCashRecords/edit.php:406`

Para estos tres, los pagos renderizados pertenecen al registro padre (tabla propia: `liquidation_doc_payments`, `legalization_payments`, `petty_cash_payments`), **no** a `invoice_payments`. Por tanto no necesitan ver los campos `petty_cash_record_id` / `legalization_record_id` / `payment_scheduling_id` (esos campos solo existen en `invoice_payments`). El bloque nuevo en `payment_section.php` usa `!empty(...)` sobre propiedades que serían `null` en esas tablas, lo cual entra al `else` "Individual" — comportamiento aceptable.

**No se requieren cambios en esos controllers** para este feature.

**Step 3: Prueba manual**

Navegar a la vista edit y view de una factura que tenga un pago por Caja Menor. El link debe aparecer ya poblado con el `code` del registro padre, no con el fallback `#{id}`.

**Step 4: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "feat(invoices): contain PettyCashRecords y LegalizationRecords en pagos"
```

---

## Task 8: `PaymentRegistryService` — filtro anti-duplicación + badge data

**Files:**
- Modify: `src/Service/PaymentRegistryService.php`

**Step 1: En `_queryPettyCashPayments`, excluir los registros que ya tienen `invoice_payments` hijos**

Antes del `return array_map(...)`, agregar:

```php
$invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
$childRecordIds = $invoicePaymentsTable->find()
    ->select(['petty_cash_record_id'])
    ->where(['petty_cash_record_id IS NOT' => null])
    ->distinct(['petty_cash_record_id'])
    ->all()
    ->extract('petty_cash_record_id')
    ->toArray();

if (!empty($childRecordIds)) {
    $query->where(['PettyCashPayments.petty_cash_record_id NOT IN' => $childRecordIds]);
}
```

**Step 2: Lo mismo en `_queryLegalizationPayments`**

```php
$invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
$childRecordIds = $invoicePaymentsTable->find()
    ->select(['legalization_record_id'])
    ->where(['legalization_record_id IS NOT' => null])
    ->distinct(['legalization_record_id'])
    ->all()
    ->extract('legalization_record_id')
    ->toArray();

if (!empty($childRecordIds)) {
    $query->where(['LegalizationPayments.legalization_record_id NOT IN' => $childRecordIds]);
}
```

**Step 3: Extender `_queryInvoicePayments` para exponer el origen y enriquecer el contain**

Modificar el `contain`:

```php
->contain(['Invoices', 'BankingEntities', 'CreatedByUsers', 'AuthorizedByUsers', 'PaymentSchedulings', 'PettyCashRecords', 'LegalizationRecords'])
```

Extender el mapper agregando dos campos nuevos al array devuelto, entre `created_by` y `created`:

```php
'source_type' => match (true) {
    !empty($p->payment_scheduling_id) => 'scheduling',
    !empty($p->petty_cash_record_id) => 'petty_cash',
    !empty($p->legalization_record_id) => 'legalization',
    default => 'individual',
},
'source_label' => match (true) {
    !empty($p->payment_scheduling_id) => 'Programación ' . ($p->payment_scheduling->code ?? '#' . $p->payment_scheduling_id),
    !empty($p->petty_cash_record_id) => 'Caja Menor ' . ($p->petty_cash_record->code ?? '#' . $p->petty_cash_record_id),
    !empty($p->legalization_record_id) => 'Legalización ' . ($p->legalization_record->code ?? '#' . $p->legalization_record_id),
    default => 'Individual',
},
'source_url' => match (true) {
    !empty($p->payment_scheduling_id) => ['controller' => 'PaymentSchedulings', 'action' => 'view', $p->payment_scheduling_id],
    !empty($p->petty_cash_record_id) => ['controller' => 'PettyCashRecords', 'action' => 'view', $p->petty_cash_record_id],
    !empty($p->legalization_record_id) => ['controller' => 'LegalizationRecords', 'action' => 'view', $p->legalization_record_id],
    default => null,
},
```

Para los mappers de `_queryPettyCashPayments`, `_queryLegalizationPayments`, `_queryLiquidationDocPayments`: añadir `'source_type' => 'legacy'`, `'source_label' => null`, `'source_url' => null` (son los históricos agregados, no llevan badge).

**Step 4: Actualizar el template del Registro de Pagos**

Localizar el template que consume `getAll()`:

Run: `grep -rn "PaymentRegistry\|paymentRegistry" templates/ src/Controller/`

En el template correspondiente (probablemente `templates/PaymentRegistry/index.php` o similar), donde se itera cada resultado, añadir una columna que renderice el badge:

```php
<?php if (!empty($row['source_url'])): ?>
    <?= $this->Html->link(
        '<i class="bi bi-' . h(match ($row['source_type']) {
            'scheduling' => 'calendar-check',
            'petty_cash' => 'wallet2',
            'legalization' => 'journal-check',
            default => 'dash',
        }) . ' me-1"></i>' . h($row['source_label']),
        $row['source_url'],
        ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
    ) ?>
<?php else: ?>
    —
<?php endif; ?>
```

**Step 5: Prueba manual (la crítica)**

Preparar datos de prueba:
1. Un Caja Menor pagado **antes** del cambio (datos históricos existentes sin `invoice_payments`).
2. Un Caja Menor pagado **después** del cambio (Task 3 ejecutado).

Abrir el Registro de Pagos global. Verificar:
- El Caja Menor histórico aparece como **1 fila** con type "Caja Menor" (legacy), sin link.
- El Caja Menor nuevo aparece como **N filas** (una por factura hija) con badge "Caja Menor CM-XXX".
- **No hay duplicación**: ninguna de las N filas debería coexistir con una fila agregada.

SQL de verificación:

```sql
SELECT pcp.id, pcp.petty_cash_record_id, pcp.amount, 'AGGREGATE' AS type
FROM petty_cash_payments pcp
WHERE pcp.authorized = 1
UNION ALL
SELECT ip.id, ip.petty_cash_record_id, ip.amount, 'INDIVIDUAL' AS type
FROM invoice_payments ip
WHERE ip.petty_cash_record_id IS NOT NULL
ORDER BY petty_cash_record_id;
```

Los `petty_cash_record_id` no deben tener simultáneamente fila `AGGREGATE` e `INDIVIDUAL` para registros post-cambio (excepto si un registro histórico fue migrado manualmente).

**Step 6: Commit**

```bash
git add src/Service/PaymentRegistryService.php templates/PaymentRegistry/index.php
git commit -m "feat(payment-registry): filtrar agregados duplicados y añadir badge de origen"
```

---

## Task 9: Verificación end-to-end

**Step 1: Flujo completo Caja Menor**

1. Crear un Petty Cash record con 2 facturas hijas (`total_amount` > 0 ambas).
2. Avanzar el registro hasta Tesorería.
3. Registrar pago (banco + monto + fecha) → estado cambia a `aut_pago`.
4. Login como Contador → autorizar el pago.
5. Estado del Petty Cash pasa a `pagado`; las dos facturas pasan a `pagada`.
6. En `/invoices/view/{child_id}` → sección "Pagos Registrados" muestra 1 fila con badge "Caja Menor CM-XXX".
7. En `/invoices/edit/{child_id}` (si el estado lo permite) → idem.
8. En `/petty-cash-records/view/{id}` → sigue mostrando su tabla agregada (sin cambios).
9. En el Registro de Pagos global → aparecen 2 filas individuales con badge, no una agregada.

**Step 2: Flujo completo Legalización**

Repetir los pasos 1-9 sustituyendo Petty Cash por Legalización.

**Step 3: Flujo rechazo**

1. Crear pago nuevo para un Petty Cash, autorizar.
2. Intentar rechazar el pago desde el botón → no debería ser posible (ya autorizado).
3. En cambio, probar: registrar un nuevo pago (otro registro) y rechazarlo antes de autorizar → verificar que **no se crearon** `invoice_payments` (rechazo solo elimina el agregado):

```sql
SELECT COUNT(*) FROM invoice_payments WHERE petty_cash_record_id = {REJECTED_ID};
```

Expected: `0`.

**Step 4: Flujo Programación (regresión)**

Ejecutar el flujo estándar de Programación de Pagos (ya existente). Verificar que:
- Los badges en ficha de factura siguen funcionando como antes.
- El Registro de Pagos muestra las filas individuales con badge "Programación XXX" con el nuevo ícono.

**Step 5: Smoke test de cs-check y dev server**

```bash
composer cs-check
php bin/cake server
```

Expected: cero errores de CS, dev server arranca, navegación básica funciona.

**Step 6: Commit de verificación (si se ajustó algo)**

Si la verificación descubrió problemas menores, corregirlos en commits atómicos. Si todo pasa sin cambios, no hay commit adicional.

---

## Orden de ejecución resumido

| # | Task | Archivos |
|---|------|----------|
| 1 | Migración FKs | `config/Migrations/20260422120000_AddRecordFksToInvoicePayments.php` |
| 2 | Entity + Table | `src/Model/Entity/InvoicePayment.php`, `src/Model/Table/InvoicePaymentsTable.php` |
| 3 | PettyCashPaymentService | `src/Service/PettyCashPaymentService.php` |
| 4 | LegalizationPaymentService | `src/Service/LegalizationPaymentService.php` |
| 5 | payment_section element | `templates/element/payment_section.php` |
| 6 | Invoices/view columna Origen | `templates/Invoices/view.php` |
| 7 | InvoicesController contain | `src/Controller/InvoicesController.php` |
| 8 | PaymentRegistryService | `src/Service/PaymentRegistryService.php` + template |
| 9 | Verificación E2E | — |

**Dependencias:**
- Task 2 requiere Task 1.
- Task 3 y 4 son independientes entre sí, pero ambas requieren Task 2.
- Task 5 no tiene dependencia técnica de Task 3/4, pero la verificación manual de Task 5 necesita datos producidos por Task 3 o Task 4.
- Task 6 requiere Task 7 para que el badge muestre el `code` (no solo el `#id`).
- Task 8 requiere Tasks 1-4 para producir datos mixtos (legacy + nuevos) y probarlos.

## Principios aplicados

- **DRY:** el renderizado del badge se centraliza en `payment_section.php` y se replica una sola vez en `Invoices/view.php` (el element solo se usa en edit).
- **YAGNI:** no se crea backfill (explícitamente fuera de alcance), no se refactoriza `liquidation_doc_payments` aunque sigue el mismo patrón agregado.
- **Commits frecuentes:** un commit por Task, mensajes descriptivos en español.
- **Forward-only:** los datos históricos quedan intactos; el filtro anti-duplicación los conserva visibles en su formato legacy.
