# Caja Menor — eliminar pagos agregados — Plan de implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eliminar la tabla `petty_cash_payments`, mover los datos del pago pendiente a columnas de `petty_cash_records`, consolidar la lógica en `PettyCashPipelineService`, y hacer que Caja Menor aparezca en el Registro de Pagos exclusivamente como `invoice_payments` individuales (badge "Caja Menor CM-XXX"), idéntico al patrón de Programación de Pagos.

**Architecture:** El record pasa a ser dueño de su transacción de pago. `registerPayment` actualiza campos del record. `authorizePayment` crea `invoice_payments` individuales y limpia/finaliza. `rejectPayment` limpia campos pendientes y guarda motivo transitorio. Se elimina `PettyCashPayments*` por completo (controller, table, entity, service, tabla DB).

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, migrations `Migrations\BaseMigration`, Bootstrap 5.

**Design reference:** `docs/plans/2026-04-24-petty-cash-remove-aggregate-payments-design.md`

**Notas sobre testing:** El proyecto no tiene suite de PHPUnit para services. La verificación es **manual** contra el dev server (`php bin/cake server` → localhost:8765) + inspección SQL.

---

## Task 1: Migración — columnas en `petty_cash_records` + drop de `petty_cash_payments`

**Files:**
- Create: `config/Migrations/20260424120000_ConvertPettyCashPaymentsToRecordFields.php`

**Step 1: Generar la migración**

Run: `php bin/cake.php migrations create ConvertPettyCashPaymentsToRecordFields`

Expected: crea el archivo en `config/Migrations/` con timestamp actual. Renombrar a `20260424120000_ConvertPettyCashPaymentsToRecordFields.php`.

**Step 2: Escribir el contenido**

Sobrescribir con:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ConvertPettyCashPaymentsToRecordFields extends BaseMigration
{
    public function up(): void
    {
        $this->table('petty_cash_records')
            ->addColumn('banking_entity_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_date',
            ])
            ->addColumn('payment_amount', 'decimal', [
                'precision' => 15,
                'scale' => 2,
                'null' => true,
                'default' => null,
                'after' => 'banking_entity_id',
            ])
            ->addColumn('payment_created_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_amount',
            ])
            ->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_created_by',
            ])
            ->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_by',
            ])
            ->addColumn('payment_rejection_reason', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_date',
            ])
            ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('payment_created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();

        if ($this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')->drop()->save();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('petty_cash_payments')) {
            $this->table('petty_cash_payments')
                ->addColumn('petty_cash_record_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('payment_date', 'date', ['null' => false])
                ->addColumn('authorized', 'boolean', ['null' => false, 'default' => false])
                ->addColumn('authorized_by', 'integer', ['null' => true])
                ->addColumn('authorized_date', 'date', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('rejection_reason', 'text', ['null' => true])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->create();
        }

        $this->table('petty_cash_records')
            ->dropForeignKey('banking_entity_id')
            ->dropForeignKey('payment_created_by')
            ->dropForeignKey('payment_authorized_by')
            ->removeColumn('banking_entity_id')
            ->removeColumn('payment_amount')
            ->removeColumn('payment_created_by')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->removeColumn('payment_rejection_reason')
            ->update();
    }
}
```

**Step 3: Ejecutar**

Run: `php bin/cake.php migrations migrate`

Expected: `migrated`. Verificar:

```sql
DESCRIBE petty_cash_records;
SHOW TABLES LIKE 'petty_cash_payments';
```

`petty_cash_records` debe tener las 6 columnas nuevas; `petty_cash_payments` no debe existir.

**Step 4: Commit**

```bash
git add config/Migrations/20260424120000_ConvertPettyCashPaymentsToRecordFields.php config/Migrations/schema-dump-default.lock
git commit -m "feat(petty-cash): migrar pagos agregados a campos del record"
```

---

## Task 2: Entity y Table — exponer nuevas columnas y asociaciones

**Files:**
- Modify: `src/Model/Entity/PettyCashRecord.php`
- Modify: `src/Model/Table/PettyCashRecordsTable.php`

**Step 1: Extender `$_accessible` del entity**

En `src/Model/Entity/PettyCashRecord.php`, agregar al array `$_accessible`:

```php
        'banking_entity_id' => true,
        'payment_amount' => true,
        'payment_created_by' => true,
        'payment_authorized_by' => true,
        'payment_authorized_date' => true,
        'payment_rejection_reason' => true,
```

**Step 2: Registrar asociaciones en el Table**

En `src/Model/Table/PettyCashRecordsTable.php::initialize()`, agregar:

```php
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentCreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_created_by',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('PaymentAuthorizedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'payment_authorized_by',
            'joinType' => 'LEFT',
        ]);
```

**Step 3: Remover la asociación `hasMany PettyCashPayments`** si existe.

Buscar en el mismo archivo la línea con `$this->hasMany('PettyCashPayments'...)`. Si existe, eliminarla por completo.

**Step 4: Añadir validadores**

En `validationDefault()`, agregar:

```php
        $validator->integer('banking_entity_id')->allowEmptyString('banking_entity_id');
        $validator->decimal('payment_amount')->allowEmptyString('payment_amount');
        $validator->integer('payment_created_by')->allowEmptyString('payment_created_by');
        $validator->integer('payment_authorized_by')->allowEmptyString('payment_authorized_by');
        $validator->date('payment_authorized_date')->allowEmptyDate('payment_authorized_date');
        $validator->scalar('payment_rejection_reason')->allowEmptyString('payment_rejection_reason');
```

**Step 5: Commit**

```bash
git add src/Model/Entity/PettyCashRecord.php src/Model/Table/PettyCashRecordsTable.php
git commit -m "feat(petty-cash): exponer campos de pago en el record y asociar banking_entities"
```

---

## Task 3: Consolidar lógica de pagos en `PettyCashPipelineService`

**Files:**
- Modify: `src/Service/PettyCashPipelineService.php`

**Step 1: Leer el archivo actual**

Leer `src/Service/PettyCashPipelineService.php` para conocer el estilo, imports, y transiciones existentes. Identificar dónde encajar los 3 nuevos métodos (`registerPayment`, `authorizePayment`, `rejectPayment`).

**Step 2: Agregar `use` si faltan**

Asegurar imports al tope del archivo:

```php
use App\Constants\InvoiceConstants;
use App\Constants\PettyCashConstants;
use Cake\ORM\TableRegistry;
```

**Step 3: Agregar `registerPayment()`**

```php
    public function registerPayment(int $recordId, array $data, int $createdBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_TESORERIA) {
            return ServiceResult::fail('Solo se pueden registrar pagos en estado Tesorería.');
        }

        if (!empty($record->banking_entity_id)) {
            return ServiceResult::fail('Ya existe un pago pendiente de autorización.');
        }

        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => $data['banking_entity_id'] ?? null,
            'payment_amount' => $data['payment_amount'] ?? null,
            'payment_date' => $data['payment_date'] ?? null,
            'payment_created_by' => $createdBy,
            'payment_rejection_reason' => null,
            'status' => PettyCashConstants::STATUS_AUT_PAGO,
        ]);

        if (!$recordsTable->save($record)) {
            $errors = [];
            foreach ($record->getErrors() as $field => $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $errors[] = "$field: $msg";
                }
            }

            $msg = 'No se pudo registrar el pago.';
            if (!empty($errors)) {
                $msg .= ' ' . implode(', ', $errors);
            }

            return ServiceResult::fail($msg);
        }

        return ServiceResult::ok('Pago registrado. Registro avanzado a Autorización de Pago.');
    }
```

**Step 4: Agregar `authorizePayment()`**

```php
    public function authorizePayment(int $recordId, int $authorizedBy): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicePaymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
            return ServiceResult::fail('El registro no está en estado Autorización de Pago.');
        }

        if (empty($record->banking_entity_id)) {
            return ServiceResult::fail('No hay un pago pendiente para autorizar.');
        }

        $connection = $recordsTable->getConnection();

        $ok = $connection->transactional(function () use (
            $record,
            $authorizedBy,
            $recordsTable,
            $invoicesTable,
            $invoicePaymentsTable
        ) {
            $childInvoices = $invoicesTable->find()
                ->where(['petty_cash_record_id' => $record->id])
                ->all();

            foreach ($childInvoices as $invoice) {
                if ((float)$invoice->total_amount <= 0) {
                    continue;
                }

                $invoicePayment = $invoicePaymentsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'banking_entity_id' => $record->banking_entity_id,
                    'amount' => $invoice->total_amount,
                    'payment_date' => $record->payment_date,
                    'petty_cash_record_id' => $record->id,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
                    'authorized' => true,
                    'authorized_by' => $authorizedBy,
                    'authorized_date' => date('Y-m-d'),
                    'created_by' => $record->payment_created_by,
                ]);

                if (!$invoicePaymentsTable->save($invoicePayment)) {
                    return false;
                }

                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
                $invoice->full_payment_date = $record->payment_date;

                if (!$invoicesTable->save($invoice)) {
                    return false;
                }
            }

            $record->status = PettyCashConstants::STATUS_PAGADO;
            $record->payment_status = InvoiceConstants::PAYMENT_FULL;
            $record->payment_authorized_by = $authorizedBy;
            $record->payment_authorized_date = date('Y-m-d');

            return (bool)$recordsTable->save($record);
        });

        if ($ok === false) {
            return ServiceResult::fail('No se pudo autorizar el pago.');
        }

        return ServiceResult::ok('Pago autorizado. Registro marcado como Pagado.');
    }
```

**Step 5: Agregar `rejectPayment()`**

```php
    public function rejectPayment(int $recordId, int $rejectedBy, string $reason): ServiceResult
    {
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $record = $recordsTable->get($recordId);

        if ($record->status !== PettyCashConstants::STATUS_AUT_PAGO) {
            return ServiceResult::fail('Solo se pueden rechazar pagos en estado Autorización de Pago.');
        }

        $record = $recordsTable->patchEntity($record, [
            'banking_entity_id' => null,
            'payment_amount' => null,
            'payment_date' => null,
            'payment_created_by' => null,
            'payment_rejection_reason' => $reason,
            'status' => PettyCashConstants::STATUS_TESORERIA,
        ]);

        if (!$recordsTable->save($record)) {
            return ServiceResult::fail('No se pudo rechazar el pago.');
        }

        return ServiceResult::ok('Pago rechazado. Registro devuelto a Tesorería.');
    }
```

**Step 6: Verificar estilo**

Run: `composer cs-check 2>&1 | grep PettyCashPipelineService`

Expected: sin errores nuevos. Los warnings preexistentes del proyecto son aceptables.

**Step 7: Commit**

```bash
git add src/Service/PettyCashPipelineService.php
git commit -m "feat(petty-cash): mover logica de pagos a PettyCashPipelineService"
```

---

## Task 4: Controller — 3 acciones nuevas en `PettyCashRecordsController`

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`

**Step 1: Asegurar DI del service**

Verificar que el controller tiene inyectado `PettyCashPipelineService` (probablemente como `$this->pipelineService`). Si no, agregarlo al constructor con fallback `?? new PettyCashPipelineService()`.

**Step 2: Agregar `registerPayment()` action**

```php
    public function registerPayment($id)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->pipelineService->registerPayment(
            (int)$id,
            $this->request->getData(),
            $user->id
        );

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago registrado.');
        } else {
            $this->Flash->error($result->errors[0] ?? 'No se pudo registrar el pago.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

**Step 3: Agregar `authorizePayment()` action**

```php
    public function authorizePayment($id)
    {
        $this->request->allowMethod(['post']);

        $user = $this->_getCurrentUser();
        $result = $this->pipelineService->authorizePayment((int)$id, $user->id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago autorizado.');
        } else {
            $this->Flash->error($result->errors[0] ?? 'No se pudo autorizar.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

**Step 4: Agregar `rejectPayment()` action**

```php
    public function rejectPayment($id)
    {
        $this->request->allowMethod(['post']);

        $reason = trim((string)$this->request->getData('reason'));
        if ($reason === '') {
            $this->Flash->error('Debe indicar un motivo de rechazo.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();
        $result = $this->pipelineService->rejectPayment((int)$id, $user->id, $reason);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Pago rechazado.');
        } else {
            $this->Flash->error($result->errors[0] ?? 'No se pudo rechazar el pago.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
```

**Step 5: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php
git commit -m "feat(petty-cash): acciones registerPayment/authorizePayment/rejectPayment"
```

---

## Task 5: Rutas

**Files:**
- Modify: `config/routes.php`

**Step 1: Localizar la zona antes de `$builder->fallbacks()`**

Abrir `config/routes.php` y ubicar donde terminan las rutas custom. Buscar si hay rutas ya definidas para `/petty-cash-records/*` — probablemente no, solo fallbacks resuelven `/petty-cash-records/edit/{id}`.

**Step 2: Agregar las 3 rutas**

Antes de `$builder->fallbacks();` (dentro del mismo scope) agregar:

```php
    $builder->connect(
        '/petty-cash-records/{id}/register-payment',
        ['controller' => 'PettyCashRecords', 'action' => 'registerPayment']
    )->setPass(['id'])->setMethods(['POST']);

    $builder->connect(
        '/petty-cash-records/{id}/authorize-payment',
        ['controller' => 'PettyCashRecords', 'action' => 'authorizePayment']
    )->setPass(['id'])->setMethods(['POST']);

    $builder->connect(
        '/petty-cash-records/{id}/reject-payment',
        ['controller' => 'PettyCashRecords', 'action' => 'rejectPayment']
    )->setPass(['id'])->setMethods(['POST']);
```

**Step 3: Verificar**

Run: `php bin/cake.php routes | grep -i petty-cash`

Expected: aparecen las 3 rutas nuevas apuntando a `PettyCashRecords::registerPayment/authorizePayment/rejectPayment`.

**Step 4: Commit**

```bash
git add config/routes.php
git commit -m "feat(petty-cash): rutas para registrar, autorizar y rechazar pagos"
```

---

## Task 6: Template `PettyCashRecords/edit.php`

**Files:**
- Modify: `templates/PettyCashRecords/edit.php`

**Step 1: Leer el template actual**

Identificar dónde se renderiza el element `payment_section` con `petty_cash_payments`. Ubicar el formulario de registro de pago (banco + monto + fecha). Ubicar cómo se renderizan botones Autorizar/Rechazar actualmente.

**Step 2: Remover el uso del `payment_section` element**

Eliminar la línea `<?= $this->element('payment_section', [...]) ?>` y cualquier preparación de variables asociadas (array de pagos, URL functions como `$authorizeUrlFn`, `$rejectUrlFn`).

**Step 3: Agregar alert de rechazo (si hay motivo)**

Antes del formulario de registro de pago, agregar:

```php
<?php if ($petty_cash_record->status === \App\Constants\PettyCashConstants::STATUS_TESORERIA
    && !empty($petty_cash_record->payment_rejection_reason)): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>Pago rechazado.</strong>
            <div><?= h($petty_cash_record->payment_rejection_reason) ?></div>
        </div>
    </div>
<?php endif; ?>
```

**Step 4: Formulario de registro de pago**

Asegurar que el formulario existente (o agregar si no está) hace POST al nuevo endpoint:

```php
<?php if ($petty_cash_record->status === \App\Constants\PettyCashConstants::STATUS_TESORERIA && $canRegisterPayment ?? true): ?>
<?= $this->Form->create(null, [
    'url' => ['action' => 'registerPayment', $petty_cash_record->id],
    'type' => 'post',
]) ?>
<div class="row g-2 align-items-end">
    <div class="col-md-4">
        <label class="form-label">Entidad Bancaria</label>
        <?= $this->Form->control('banking_entity_id', [
            'type' => 'select',
            'options' => $bankingEntities,
            'empty' => 'Seleccione...',
            'class' => 'form-select select2',
            'label' => false,
            'required' => true,
        ]) ?>
    </div>
    <div class="col-md-3">
        <label class="form-label">Monto</label>
        <?= $this->Form->control('payment_amount', [
            'type' => 'text',
            'class' => 'form-control currency-input',
            'label' => false,
            'required' => true,
        ]) ?>
    </div>
    <div class="col-md-3">
        <label class="form-label">Fecha</label>
        <?= $this->Form->control('payment_date', [
            'type' => 'text',
            'class' => 'form-control flatpickr-date',
            'label' => false,
            'required' => true,
        ]) ?>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn sgi-btn-primary w-100">
            <i class="bi bi-plus-circle me-1"></i>Registrar
        </button>
    </div>
</div>
<?= $this->Form->end() ?>
<?php endif; ?>
```

(Si la variable `$bankingEntities` no está set, asegurarla desde el controller `edit()` acción.)

**Step 5: Tarjeta de pago pendiente/autorizado**

Cuando el record está en `aut_pago` o `pagado`:

```php
<?php if (in_array($petty_cash_record->status, [
    \App\Constants\PettyCashConstants::STATUS_AUT_PAGO,
    \App\Constants\PettyCashConstants::STATUS_PAGADO,
], true)): ?>
<div class="card mt-3" style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
    <div class="card-header bg-light"><strong>Pago</strong></div>
    <div class="card-body">
        <dl class="row mb-0" style="font-size:.9rem;">
            <dt class="col-sm-3">Banco</dt>
            <dd class="col-sm-9"><?= h($petty_cash_record->banking_entity->name ?? '—') ?></dd>

            <dt class="col-sm-3">Monto</dt>
            <dd class="col-sm-9">$ <?= number_format((float)$petty_cash_record->payment_amount, 0, ',', '.') ?></dd>

            <dt class="col-sm-3">Fecha</dt>
            <dd class="col-sm-9"><?= $petty_cash_record->payment_date?->format('d/m/Y') ?? '—' ?></dd>

            <dt class="col-sm-3">Registrado por</dt>
            <dd class="col-sm-9">
                <?= h($petty_cash_record->payment_created_by_user->full_name
                    ?? $petty_cash_record->payment_created_by_user->username ?? '—') ?>
            </dd>

            <dt class="col-sm-3">Estado</dt>
            <dd class="col-sm-9">
                <?php if ($petty_cash_record->status === \App\Constants\PettyCashConstants::STATUS_PAGADO): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizado</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pendiente de autorización</span>
                <?php endif; ?>
            </dd>

            <?php if ($petty_cash_record->status === \App\Constants\PettyCashConstants::STATUS_PAGADO): ?>
            <dt class="col-sm-3">Autorizado por</dt>
            <dd class="col-sm-9">
                <?= h($petty_cash_record->payment_authorized_by_user->full_name
                    ?? $petty_cash_record->payment_authorized_by_user->username ?? '—') ?>
                · <?= $petty_cash_record->payment_authorized_date?->format('d/m/Y') ?? '' ?>
            </dd>
            <?php endif; ?>
        </dl>

        <?php if ($petty_cash_record->status === \App\Constants\PettyCashConstants::STATUS_AUT_PAGO
            && ($canAuthorizePayment ?? false)): ?>
        <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-success btn-post-action"
                    data-url="<?= $this->Url->build(['action' => 'authorizePayment', $petty_cash_record->id]) ?>"
                    data-confirm="¿Autorizar este pago?">
                <i class="bi bi-shield-check me-1"></i>Autorizar
            </button>
            <button type="button" class="btn btn-outline-danger btn-reject-payment"
                    data-url="<?= $this->Url->build(['action' => 'rejectPayment', $petty_cash_record->id]) ?>">
                <i class="bi bi-x-circle me-1"></i>Rechazar
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
```

**Step 6: Controller `edit()` — asegurar contain + flags**

En `PettyCashRecordsController::edit()`, extender el `contain` del `get()`:

```php
contain: [
    // ... existentes
    'BankingEntities',
    'PaymentCreatedByUsers',
    'PaymentAuthorizedByUsers',
]
```

Setear flags de permisos:

```php
$canRegisterPayment = in_array($roleName, [
    RoleConstants::TESORERIA, RoleConstants::ADMIN,
], true);
$canAuthorizePayment = in_array($roleName, [
    RoleConstants::CONTADOR, RoleConstants::ADMIN,
], true);
$bankingEntities = $this->fetchTable('BankingEntities')
    ->find('list', keyField: 'id', valueField: 'name')->toArray();
$this->set(compact('canRegisterPayment', 'canAuthorizePayment', 'bankingEntities'));
```

**Step 7: Prueba manual**

1. `php bin/cake.php server`.
2. Login como Admin. Ir a un Caja Menor en Tesorería.
3. Verificar que el formulario de pago se ve, registrar un pago → record pasa a aut_pago, tarjeta se renderiza.
4. Login como Contador → botones Autorizar/Rechazar visibles.
5. Rechazar con motivo → vuelve a Tesorería, alert visible.
6. Registrar de nuevo y autorizar → tarjeta muestra "Autorizado", SQL verifica `invoice_payments`:

```sql
SELECT ip.id, ip.invoice_id, ip.amount, ip.petty_cash_record_id, ip.authorized
FROM invoice_payments ip
WHERE ip.petty_cash_record_id = {ID};
```

**Step 8: Commit**

```bash
git add templates/PettyCashRecords/edit.php src/Controller/PettyCashRecordsController.php
git commit -m "feat(petty-cash): tarjeta de pago y alert de rechazo en edit"
```

---

## Task 7: `PaymentRegistryService` — eliminar query de Caja Menor

**Files:**
- Modify: `src/Service/PaymentRegistryService.php`

**Step 1: Eliminar `_queryPettyCashPayments()` completo**

Remover el método `private function _queryPettyCashPayments(array $filters): array` completo.

**Step 2: Remover su llamada**

En `getAll()`, eliminar:

```php
$results = array_merge($results, $this->_queryPettyCashPayments($filters));
```

**Step 3: Verificar sintaxis**

Run: `php -l src/Service/PaymentRegistryService.php`

Expected: `No syntax errors detected`.

**Step 4: Commit**

```bash
git add src/Service/PaymentRegistryService.php
git commit -m "feat(payment-registry): eliminar query de pagos agregados de caja menor"
```

---

## Task 8: Template `PaymentRegistry/index.php` — limpiar opción

**Files:**
- Modify: `templates/PaymentRegistry/index.php`

**Step 1: Remover opción del select Tipo**

Eliminar la línea:

```php
<option value="petty_cash" <?= ($filters['type'] ?? '') === 'petty_cash' ? 'selected' : '' ?>>Caja Menor</option>
```

**Step 2: Verificar sintaxis**

Run: `php -l templates/PaymentRegistry/index.php`

**Step 3: Commit**

```bash
git add templates/PaymentRegistry/index.php
git commit -m "feat(payment-registry): remover opcion caja menor del filtro tipo"
```

---

## Task 9: Limpieza — eliminar archivos obsoletos

**Files:**
- Delete: `src/Controller/PettyCashPaymentsController.php`
- Delete: `src/Model/Table/PettyCashPaymentsTable.php`
- Delete: `src/Model/Entity/PettyCashPayment.php`
- Delete: `src/Service/PettyCashPaymentService.php`
- Modify: `src/Controller/AppController.php` (quitar entry en `$controllerModuleMap`)

**Step 1: Borrar archivos**

```bash
rm src/Controller/PettyCashPaymentsController.php
rm src/Model/Table/PettyCashPaymentsTable.php
rm src/Model/Entity/PettyCashPayment.php
rm src/Service/PettyCashPaymentService.php
```

**Step 2: Editar `AppController::$controllerModuleMap`**

Buscar la entrada `'PettyCashPayments' => '...'` y eliminarla.

**Step 3: Verificar que no queden referencias**

Run (usando la herramienta Grep, no bash):

Buscar `PettyCashPayments` en `src/`, `templates/`, `config/` excluyendo el directorio `docs/plans/`.

Expected: cero matches (o solo en migraciones históricas `20260414000002_CreatePettyCashPayments.php` — esas NO se tocan).

**Step 4: Arrancar dev server como smoke test**

Run: `php bin/cake.php server &` y abrir localhost:8765. Navegar a `/petty-cash-records/edit/{id}` — no debe lanzar error por clase faltante.

**Step 5: Commit**

```bash
git add -u src/ templates/
git commit -m "chore(petty-cash): eliminar controller/table/entity/service de PettyCashPayments"
```

---

## Task 10: Verificación end-to-end

**Step 1: Flujo completo**

1. `php bin/cake.php server`.
2. Crear o localizar un Petty Cash con 2 facturas hijas (`total_amount > 0`).
3. Avanzar a Tesorería.
4. Como Tesorería: registrar pago (banco + monto + fecha) → record pasa a `aut_pago`, tarjeta visible con datos del pago.
5. Como Contador: Autorizar → record pasa a `pagado`, tarjeta muestra "Autorizado".
6. Factura hija `/invoices/view/{id}` → sección "Pagos Registrados" muestra 1 fila con badge "Caja Menor CM-XXX" y enlace al record.
7. Registro de Pagos global → aparecen 2 filas tipo "Factura" con badge Caja Menor, ninguna agregada.

**Step 2: Flujo rechazo**

1. Nuevo Caja Menor en Tesorería, registrar pago.
2. Como Contador: Rechazar con motivo "Error en banco".
3. Record vuelve a Tesorería, alert naranja visible con el motivo.
4. Re-registrar pago → alert desaparece.

**Step 3: SQL de consistencia**

```sql
-- Verificar que no hay tabla vieja
SHOW TABLES LIKE 'petty_cash_payments';

-- Verificar invoice_payments creados
SELECT ip.id, ip.invoice_id, ip.amount, ip.petty_cash_record_id, ip.authorized
FROM invoice_payments ip
WHERE ip.petty_cash_record_id IS NOT NULL;

-- Verificar campos del record
SELECT id, code, status, banking_entity_id, payment_amount, payment_date,
       payment_created_by, payment_authorized_by, payment_authorized_date, payment_rejection_reason
FROM petty_cash_records
WHERE status IN ('aut_pago', 'pagado');
```

**Step 4: Smoke test**

```bash
composer cs-check 2>&1 | grep -E "PettyCash|error" | head -20
php bin/cake.php server
```

Navegar por las vistas principales (index, edit, view) de Caja Menor, Facturas, Registro de Pagos. Ningún error PHP/Cake.

**Step 5: Commit (solo si fue necesario ajustar algo)**

Si la verificación descubrió problemas menores, corregir en commits atómicos. Si todo pasa, no hay commit adicional.

---

## Orden de ejecución resumido

| # | Task | Archivos principales |
|---|------|----------------------|
| 1 | Migración | `config/Migrations/20260424120000_ConvertPettyCashPaymentsToRecordFields.php` |
| 2 | Entity + Table | `src/Model/Entity/PettyCashRecord.php`, `src/Model/Table/PettyCashRecordsTable.php` |
| 3 | PipelineService | `src/Service/PettyCashPipelineService.php` |
| 4 | Controller actions | `src/Controller/PettyCashRecordsController.php` |
| 5 | Rutas | `config/routes.php` |
| 6 | Template edit | `templates/PettyCashRecords/edit.php` + controller set |
| 7 | PaymentRegistryService | `src/Service/PaymentRegistryService.php` |
| 8 | PaymentRegistry template | `templates/PaymentRegistry/index.php` |
| 9 | Limpieza archivos | delete old + `AppController.php` |
| 10 | Verificación E2E | — |

**Dependencias:**
- Task 2 requiere Task 1.
- Task 3 requiere Task 2.
- Tasks 4, 5 requieren Task 3.
- Task 6 requiere Tasks 2, 4, 5.
- Tasks 7 y 8 son independientes (pueden ir antes o después).
- Task 9 requiere Tasks 3, 4 (las clases nuevas deben estar en su lugar).

## Principios aplicados

- **Replicar Programación:** el record es dueño de la transacción; `invoice_payments` solo se materializa al autorizar.
- **YAGNI:** sin backfill, sin auditoría de `rejected_by` (va a observations si surge necesidad), sin tocar Legalización/Liquidación.
- **Forward-only:** datos viejos se pierden al dropear; el estado del record (`status`, `payment_date`, `payment_status`) queda intacto.
- **Commits atómicos:** uno por Task, mensajes en español sin acentos (por compatibilidad con git hooks).
