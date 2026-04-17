# Rediseño UI Pipeline de Facturas — Plan de Implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implementar el rediseño de la UI del pipeline de facturas descrito en `docs/plans/2026-04-17-invoice-ui-pipeline-design.md`, unificando el botón de submit, reemplazando secciones read-only por un ledger contextual, desacoplando acciones (enlaces de aprobación, modificación de aprobadores) y dividiendo el estado `autorizacion_pago` en dos sub-fases derivadas.

**Architecture:** Cambios en 4 capas: (1) modelo/migraciones para nuevos campos de pago y tabla de soportes, (2) servicios para reflejar las nuevas reglas (status enum, filtrado de advance errors, modifyApprovers), (3) controllers para nuevos endpoints + redirect pagada→view, (4) templates para `ledger_context`, actualización de `payment_section` con `mode`, edit.php refactorizada por estado/rol, y view.php enriquecida.

**Tech Stack:** PHP 8.2 + CakePHP 5.3, MySQL/MariaDB, Bootstrap 5, Flatpickr, AutoNumeric, vanilla JS (`sgi-payment.js`).

**Premisa DRY/YAGNI/TDD:** Los servicios se cubren con tests unitarios; los templates se verifican manualmente con el checklist del final. Commits frecuentes: uno por tarea. No se adelantan features marcadas "fuera de alcance" (batch authorization, botón regresar-estado, comprobante consolidado).

---

## Fase 0 — Preparación

### Task 0.1: Crear rama de trabajo y snapshot de BD

**Files:** ninguno

**Step 1:** `git switch -c feat/invoice-pipeline-ui-redesign`

**Step 2:** Dump de BD local para restaurar si una migración falla: `mysqldump --databases $DB > /tmp/sgi-pre-redesign.sql` (el DSN está en `.env`).

**Step 3:** Commit inicial de la rama con el diseño referenciado.

```bash
git add docs/plans/2026-04-17-invoice-ui-pipeline-design.md
git commit -m "docs: add invoice pipeline UI redesign spec"
```

---

## Fase 1 — Modelo y migraciones

### Task 1.1: Migración `invoice_payments.status` (enum)

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_AddStatusEnumToInvoicePayments.php`

**Step 1:** `php bin/cake migrations create AddStatusEnumToInvoicePayments`.

**Step 2:** Editar la migración generada. Usa `BaseMigration` (no `AbstractMigration`). Contenido:

```php
public function up(): void
{
    $this->table('invoice_payments')
        ->addColumn('status', 'enum', [
            'values' => ['pending', 'authorized', 'rejected'],
            'default' => 'pending',
            'null' => false,
            'after' => 'authorized',
        ])
        ->update();

    // Backfill
    $this->execute("UPDATE invoice_payments SET status = 'authorized' WHERE authorized = 1");
    $this->execute("UPDATE invoice_payments SET status = 'pending' WHERE authorized = 0");
}

public function down(): void
{
    $this->table('invoice_payments')->removeColumn('status')->update();
}
```

**Step 3:** `php bin/cake migrations migrate`. Esperado: `== AddStatusEnumToInvoicePayments: migrated`.

**Step 4:** Commit:

```bash
git add config/Migrations/*AddStatusEnumToInvoicePayments.php
git commit -m "feat(migrations): add status enum to invoice_payments with backfill"
```

### Task 1.2: Migración `invoice_payments.rejection_reason`

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_AddRejectionReasonToInvoicePayments.php`

**Step 1:** `php bin/cake migrations create AddRejectionReasonToInvoicePayments`.

**Step 2:** Contenido:

```php
public function up(): void
{
    $this->table('invoice_payments')
        ->addColumn('rejection_reason', 'text', ['null' => true, 'after' => 'status'])
        ->update();
}

public function down(): void
{
    $this->table('invoice_payments')->removeColumn('rejection_reason')->update();
}
```

**Step 3:** `php bin/cake migrations migrate`.

**Step 4:** Commit: `git commit -am "feat(migrations): add rejection_reason to invoice_payments"`.

### Task 1.3: Migración tabla `invoice_payment_attachments`

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_CreateInvoicePaymentAttachments.php`

**Step 1:** `php bin/cake migrations create CreateInvoicePaymentAttachments`.

**Step 2:** Contenido:

```php
public function up(): void
{
    if ($this->hasTable('invoice_payment_attachments')) {
        $this->table('invoice_payment_attachments')->drop()->update();
    }

    $this->table('invoice_payment_attachments', ['signed' => false])
        ->addColumn('invoice_payment_id', 'integer', ['signed' => false, 'null' => false])
        ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
        ->addColumn('file_path', 'string', ['limit' => 500, 'null' => false])
        ->addColumn('mime_type', 'string', ['limit' => 120, 'null' => true])
        ->addColumn('file_size', 'integer', ['null' => true])
        ->addColumn('uploaded_by', 'integer', ['signed' => false, 'null' => true])
        ->addColumn('created', 'datetime', ['null' => false])
        ->addColumn('modified', 'datetime', ['null' => false])
        ->addIndex(['invoice_payment_id'])
        ->addForeignKey('invoice_payment_id', 'invoice_payments', 'id', [
            'delete' => 'CASCADE', 'update' => 'NO_ACTION',
        ])
        ->addForeignKey('uploaded_by', 'users', 'id', [
            'delete' => 'SET_NULL', 'update' => 'NO_ACTION',
        ])
        ->create();
}

public function down(): void
{
    if ($this->hasTable('invoice_payment_attachments')) {
        $this->table('invoice_payment_attachments')->drop()->update();
    }
}
```

**Step 3:** Verificar tipos. `invoice_payments.id` y `users.id` deben ser `UNSIGNED` (compararlo con migraciones anteriores). Ajustar `signed` si corresponde.

**Step 4:** `php bin/cake migrations migrate`.

**Step 5:** Commit: `git commit -am "feat(migrations): create invoice_payment_attachments"`.

### Task 1.4: Migración tabla `petty_cash_payment_attachments` (para replicación futura)

**Files:**
- Create: `config/Migrations/YYYYMMDDHHMMSS_CreatePettyCashPaymentAttachments.php`

**Step 1:** Verificar primero que `petty_cash_payments` existe (migración `20260414000002_CreatePettyCashPayments.php` ya existe).

**Step 2:** `php bin/cake migrations create CreatePettyCashPaymentAttachments`.

**Step 3:** Misma estructura que Task 1.3 pero con FK a `petty_cash_payments`.

**Step 4:** `php bin/cake migrations migrate`, commit.

### Task 1.5: Entity + Table para `invoice_payment_attachments`

**Files:**
- Create: `src/Model/Entity/InvoicePaymentAttachment.php`
- Create: `src/Model/Table/InvoicePaymentAttachmentsTable.php`
- Modify: `src/Model/Table/InvoicePaymentsTable.php` (agregar `hasMany('Attachments', …)`)

**Step 1:** Crear `InvoicePaymentAttachment` entity con `protected array $_accessible` estándar (todos true excepto `id`).

**Step 2:** Crear `InvoicePaymentAttachmentsTable`:

```php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->setTable('invoice_payment_attachments');
    $this->addBehavior('Timestamp');
    $this->belongsTo('InvoicePayments', ['foreignKey' => 'invoice_payment_id']);
    $this->belongsTo('UploadedByUsers', ['className' => 'Users', 'foreignKey' => 'uploaded_by']);
}
```

**Step 3:** En `InvoicePaymentsTable::initialize()` agregar:

```php
$this->hasMany('InvoicePaymentAttachments', ['foreignKey' => 'invoice_payment_id']);
```

**Step 4:** Commit: `git commit -am "feat(model): InvoicePaymentAttachment entity/table + association"`.

### Task 1.6: Entity property para `status` y `rejection_reason` en `InvoicePayment`

**Files:**
- Modify: `src/Model/Entity/InvoicePayment.php`

**Step 1:** Agregar `status` y `rejection_reason` a `$_accessible` (true). Si no es un accessible allowlist, basta verificar estén reflejadas por el patchEntity. Añadir constantes dentro de `InvoiceConstants` (ver Task 1.7).

**Step 2:** Commit: `git commit -am "feat(entity): expose status/rejection_reason on InvoicePayment"`.

### Task 1.7: Constantes de estado de pago en `InvoiceConstants`

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`

**Step 1:** Agregar:

```php
public const PAYMENT_RECORD_PENDING    = 'pending';
public const PAYMENT_RECORD_AUTHORIZED = 'authorized';
public const PAYMENT_RECORD_REJECTED   = 'rejected';

public const PAYMENT_RECORD_STATUSES = [
    self::PAYMENT_RECORD_PENDING,
    self::PAYMENT_RECORD_AUTHORIZED,
    self::PAYMENT_RECORD_REJECTED,
];
```

**Step 2:** Commit: `git commit -am "feat(constants): add payment record status constants"`.

---

## Fase 2 — Servicios

### Task 2.1: `InvoicePaymentService::recalculatePaymentStatus` ignora pagos rechazados

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`
- Test: `tests/TestCase/Service/InvoicePaymentServiceTest.php`

**Step 1 — test primero:** En `InvoicePaymentServiceTest` agregar caso:

```php
public function testRecalculateIgnoresRejectedPayments(): void
{
    // fixture: 1 invoice amount 1000, 1 authorized 400, 1 rejected 600
    // expected: payment_status = Pago Parcial (no Pago total)
}
```

**Step 2:** `vendor/bin/phpunit --filter testRecalculateIgnoresRejectedPayments` → debe FALLAR antes del cambio (la query actual filtra por `authorized => true` lo que ya excluye rejected; de hecho pasará inicialmente; reescribir test para que falle migrando a `status`):

Nueva assertion: el query interno ahora filtra `status = 'authorized'` en vez de `authorized = 1`. Para probarlo inserta un pago con `authorized = 1` y `status = 'rejected'` simultáneamente (estado inconsistente) — el service debe ignorarlo (basarse en `status`).

**Step 3 — implementar:** Cambiar dentro de `recalculatePaymentStatus()`:

```php
$authorizedPayments = $paymentsTable->find()
    ->where([
        'invoice_id' => $invoiceId,
        'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
    ])
    ->order(['payment_date' => 'ASC'])
    ->all();
```

Y en `getPendingBalance()`:

```php
->where(['invoice_id' => $invoiceId, 'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED])
```

**Step 4:** `vendor/bin/phpunit --filter InvoicePaymentService` → todos los tests pasan.

**Step 5:** Commit: `git commit -am "refactor(InvoicePaymentService): filter by status enum instead of authorized flag"`.

### Task 2.2: `hasPendingAuthorization` filtra por `status = pending`

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`
- Test: `tests/TestCase/Service/InvoicePaymentServiceTest.php`

**Step 1 — test:** "rechazo no cuenta como pago pendiente":

```php
public function testHasPendingAuthorizationIgnoresRejected(): void
{
    // crear invoice + 1 pago rejected
    $this->assertFalse($service->hasPendingAuthorization($invoiceId));
}
```

**Step 2:** Cambiar `hasPendingAuthorization` a `status = 'pending'`:

```php
return $paymentsTable->exists([
    'invoice_id' => $invoiceId,
    'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
    'payment_scheduling_id IS' => null,
]);
```

**Step 3:** Run tests. Commit.

### Task 2.3: `rejectPayment` marca status en vez de eliminar

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`
- Test: `tests/TestCase/Service/InvoicePaymentServiceTest.php`

**Step 1 — test:**

```php
public function testRejectPaymentMarksStatusAndPersistsReason(): void
{
    // ejecutar rejectPayment con motivo
    // asserts: payment existe, status == 'rejected', rejection_reason == motivo, authorized == false
    // asserts: invoice vuelve a tesoreria
}
```

**Step 2 — implementar:** Cambiar firma y cuerpo:

```php
public function rejectPayment(int $paymentId, int $rejectedBy, string $reason): ServiceResult
{
    // ...
    $payment->status = InvoiceConstants::PAYMENT_RECORD_REJECTED;
    $payment->rejection_reason = $reason;
    // NO eliminar
    $paymentsTable->save($payment);

    $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
    $invoicesTable->save($invoice);
    $this->historyService->recordStatusChange($invoiceId, $previousStatus, InvoiceConstants::STATUS_TESORERIA, $rejectedBy);
    // notificar a Tesorería (via NotificationService.sendPaymentRejectedNotification — crear método stub si no existe)

    return ServiceResult::ok('Pago rechazado...');
}
```

**Step 3:** Actualizar `authorizePayment` para actualizar también `status = authorized` además del flag `authorized` legacy.

**Step 4:** Run tests. Commit: `git commit -am "feat(InvoicePaymentService): rejectPayment persists reason instead of deleting"`.

### Task 2.4: `registerPayment` acepta `advance_after` flag

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`
- Test: `tests/TestCase/Service/InvoicePaymentServiceTest.php`

**Step 1 — test:**

```php
public function testRegisterPaymentWithoutAdvanceKeepsInTreasury(): void { /* advance_after=false → status sigue tesoreria */ }
public function testRegisterPaymentWithAdvanceMovesToAuthorization(): void { /* advance_after=true */ }
```

**Step 2 — implementar:** Introducir parámetro:

```php
public function registerPayment(int $invoiceId, array $paymentData, int $createdBy, bool $advanceAfter = true): ServiceResult
{
    // transactional
    // crear payment (status default = pending)
    // si $advanceAfter: invoice.pipeline_status = AUTORIZACION_PAGO + history + notify
    // si no: solo crear payment
}
```

Usar `$table->getConnection()->transactional(...)`.

**Step 3:** Run tests. Commit: `git commit -am "feat(InvoicePaymentService): add advance_after flag to registerPayment"`.

### Task 2.5: `InvoicePaymentService::editPayment` con motivo obligatorio

**Files:**
- Modify: `src/Service/InvoicePaymentService.php`
- Test: `tests/TestCase/Service/InvoicePaymentServiceTest.php`

**Step 1 — test:**

```php
public function testEditPaymentRecordsHistoryPerField(): void
{
    // cambiar monto 400 → 500 con motivo
    // assert: invoice_histories contiene entrada por cada campo cambiado
    // assert: reason persistida en invoice_histories (via recordChange observation)
}

public function testEditPaymentRequiresReason(): void
{
    // reason vacío → ServiceResult::fail
}

public function testCannotEditAuthorizedPayment(): void { /* fail */ }
```

**Step 2 — implementar:**

```php
public function editPayment(int $paymentId, array $data, string $reason, int $userId): ServiceResult
{
    if (trim($reason) === '') return ServiceResult::fail('La observación es obligatoria.');
    $payment = $paymentsTable->get($paymentId);
    if ($payment->status === InvoiceConstants::PAYMENT_RECORD_AUTHORIZED) {
        return ServiceResult::fail('No se puede editar un pago autorizado.');
    }
    // patch + save + record history
    // usar historyService->recordChanges() o similar anotando reason
}
```

**Step 3:** Run tests. Commit.

### Task 2.6: `InvoicePipelineService` — nueva regla `_has_payment_supports`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/InvoicePaymentService.php` (nuevo método `hasPaymentSupports`)
- Test: `tests/TestCase/Service/InvoicePipelineServiceTest.php`

**Step 1 — nuevo método en `InvoicePaymentService`:**

```php
public function hasPaymentSupports(int $invoiceId): bool
{
    $attachmentsTable = TableRegistry::getTableLocator()->get('InvoicePaymentAttachments');
    return $attachmentsTable->find()
        ->innerJoinWith('InvoicePayments', fn($q) => $q->where([
            'InvoicePayments.invoice_id' => $invoiceId,
            'InvoicePayments.status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
        ]))->count() > 0;
}
```

**Step 2:** Agregar al `TRANSITION_REQUIREMENTS[STATUS_AUTORIZACION_PAGO]`:

```php
InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
    ['field' => '_payment_authorized', 'custom' => true, 'label' => '...'],
    ['field' => '_has_payment_supports', 'custom' => true, 'label' => 'Debe subir al menos un soporte de pago para cerrar la factura'],
],
```

**Step 3:** En `validateTransitionRequirements`, manejar el nuevo caso:

```php
} elseif ($rule['field'] === '_has_payment_supports') {
    if (!$this->paymentService->hasPaymentSupports($invoice->id)) {
        $errors[] = $rule['label'];
    }
}
```

**Step 4:** Test unitario que verifique validación exige soportes.

**Step 5:** Commit.

### Task 2.7: Filtrar `advanceErrors` por editabilidad del rol

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

**Step 1:** Mapa de requisitos → campos que los resuelven (dentro de `InvoicePipelineService` o nuevo `REQUIREMENT_FIELDS`):

```php
private const REQUIREMENT_FIELDS = [
    'area_approval'    => ['approver_ids'], // se resuelve al enviar enlaces, no campo propio
    'dian_validation'  => ['dian_validation'],
    'accrued'          => ['accrued', 'accrual_date'],
    'accrual_date'     => ['accrual_date'],
    'ready_for_payment' => ['ready_for_payment'],
    '_has_pending_payment' => [], // acciones (no campos)
    '_payment_authorized'  => [],
    '_has_payment_supports' => [],
];
```

**Step 2:** Nuevo método:

```php
public function filterAdvanceErrorsForRole(array $errors, array $rules, string $roleName, string $status): array
{
    $editable = $this->getEditableFields($roleName, $status);
    $filtered = [];
    foreach ($rules as $i => $rule) {
        if (!isset($errors[$i])) continue;
        $field = $rule['field'];
        $responsible = self::REQUIREMENT_FIELDS[$field] ?? [$field];
        if ($responsible === [] || !empty(array_intersect($responsible, $editable))) {
            $filtered[] = $errors[$i];
        }
    }
    return $filtered;
}
```

**Step 3:** Modificar `validateTransitionRequirements` para devolver `['label' => ..., 'field' => ...]` en vez de string, o crear método alterno `validateTransitionRequirementsDetailed()`. Ajustar llamadores.

**Step 4:** En el consumidor (`InvoicesController::edit`), aplicar filtro antes de pasar a la vista.

**Step 5:** Test:

```php
public function testAdvanceErrorsFilteredByEditability(): void
{
    // role Registro en aprobacion: no debe aparecer "Todos los aprobadores deben haber aprobado"
    // (porque area_approval NO está en los editables directos, se resuelve con acción)
}
```

**Step 6:** Commit: `git commit -am "feat(pipeline): filter advanceErrors by role editability"`.

### Task 2.8: Eliminar `approver_id` de `ALL_FIELDS`

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`
- Grep: `grep -rn "approver_id" src/ templates/ config/` para identificar usos

**Step 1:** Buscar y listar todas las referencias.

**Step 2:** Quitar de `ALL_FIELDS` el valor `'approver_id'`. Quitar de cualquier `EDITABLE_FIELDS`.

**Step 3:** En templates (`edit.php`, `view.php`) donde se lee `$invoice->approver_id` / `$invoice->approver_user`, reemplazar por la relación multi-aprobador (`$currentApprovals`) o quitar si redundante.

**Step 4:** Entidad `Invoice`: mantener la columna físicamente (no borrarla en esta tarea — migración aparte si se confirma). Sólo dejar de exponerla en la policy.

**Step 5:** `composer cs-check && composer test`. Commit.

### Task 2.9: Actualizar `VISIBLE_SECTIONS_BY_ROLE` según el diseño

**Files:**
- Modify: `src/Service/InvoiceFieldAccessPolicy.php`

**Step 1:** Ajustar el mapa tal que cada rol vea solo su sección editable + la sección `ledger` (virtual):

```php
private const VISIBLE_SECTIONS_BY_ROLE = [
    RoleConstants::REGISTRO_REVISION => ['ledger', 'revision'],
    RoleConstants::CONTABILIDAD      => ['ledger', 'accounting'],
    RoleConstants::TESORERIA         => ['ledger', 'treasury', 'payment_supports'],
    RoleConstants::CONTADOR          => ['ledger', 'payment_authorization'],
];
```

(Admin sigue con lógica por statusIndex.)

**Step 2:** Remover `COLLAPSIBLE_SECTIONS_BY_ROLE` o vaciarlo — el ledger reemplaza secciones colapsables.

**Step 3:** Commit: `git commit -am "refactor(FieldAccessPolicy): narrow visible sections to role's editable + ledger"`.

### Task 2.10: `InvoiceApprovalService::modifyApprovers`

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php`
- Test: `tests/TestCase/Service/InvoiceApprovalServiceTest.php`

**Step 1 — test:**

```php
public function testModifyApproversInvalidatesPreviousAndCreatesNew(): void
{
    // fixture: invoice con 2 approvals pending (tokens activos)
    // ejecutar modifyApprovers(invoice, [nuevo1, nuevo2, nuevo3], 'motivo', $baseUrl, $userId)
    // asserts:
    //   - approvals viejos: token IS NULL, token_expires_at IS NULL
    //   - 3 approvals nuevos con token, status=pending
    //   - invoice_histories registra cambio con motivo
}

public function testModifyApproversRequiresReason(): void { /* '' → excepción o error */ }
```

**Step 2 — implementar:**

```php
public function modifyApprovers(Invoice $invoice, array $newApproverIds, string $reason, string $baseUrl, int $userId): array
{
    if (trim($reason) === '') return ['success' => false, 'errors' => ['Motivo obligatorio'], 'approvals' => []];
    $conn = $this->invoiceApprovalsTable->getConnection();
    return $conn->transactional(function () use (...) {
        $this->_invalidatePendingTokens($invoice->id);
        $history = new InvoiceHistoryService();
        $history->recordCustom($invoice->id, 'approvers_modified', null, json_encode($newApproverIds), $userId, $reason);
        return $this->assignApprovers($invoice, $newApproverIds, $baseUrl, $userId);
    });
}
```

Si `InvoiceHistoryService::recordCustom` no existe, crearlo (una línea en `invoice_histories` con field_changed custom).

**Step 3:** Commit.

### Task 2.11: `InvoiceApprovalService::sendApprovalLinks` desacoplado

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php`

**Step 1:** Agregar wrapper:

```php
public function sendApprovalLinks(Invoice $invoice, array $approverIds, string $baseUrl, int $userId): array
{
    if ($this->hasPendingApprovals($invoice->id)) {
        return ['success' => false, 'errors' => ['Ya hay aprobaciones activas; use Modificar aprobadores.'], 'approvals' => []];
    }
    return $this->assignApprovers($invoice, $approverIds, $baseUrl, $userId);
}
```

**Step 2:** Test `testSendApprovalLinksBlocksWhenPendingExists`.

**Step 3:** Commit.

### Task 2.12: Soporte "Reiniciar flujo" cuando `area_approval=Rechazada`

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php`
- Test: `tests/TestCase/Service/InvoiceApprovalServiceTest.php`

**Step 1:** Nuevo método:

```php
public function resetFlow(Invoice $invoice, int $userId): ServiceResult
{
    if ($invoice->area_approval !== InvoiceConstants::APPROVAL_REJECTED) {
        return ServiceResult::fail('La factura no está rechazada.');
    }
    $conn = $this->invoiceApprovalsTable->getConnection();
    $conn->transactional(function () use ($invoice, $userId) {
        $this->invoiceApprovalsTable->deleteAll(['invoice_id' => $invoice->id]);
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice->area_approval = InvoiceConstants::APPROVAL_PENDING;
        $invoice->area_approval_date = null;
        $invoicesTable->save($invoice);
        (new InvoiceHistoryService())->recordStatusChange($invoice->id, 'rechazada', 'pendiente', $userId);
    });
    return ServiceResult::ok('Flujo reiniciado.');
}
```

**Step 2:** Test.

**Step 3:** Commit.

### Task 2.13: Pipeline `saveAndAdvance` — preservar input al fallar avance

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

**Step 1:** Ya hoy guarda y retorna `advanceErrors`. Verificar que el controller no muestre warnings si `advanceErrors` viene vacío tras filtrar (Task 2.7). Nada nuevo aquí si Task 2.7 quedó bien; solo eliminar los `Flash->warning` duplicados en el controller.

**Step 2:** Commit si algo cambia; si no, skip.

---

## Fase 3 — Controllers / Endpoints

### Task 3.1: Endpoint `sendApprovalLinks`

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Modify: `config/routes.php`

**Step 1:** En routes, antes de `fallbacks`:

```php
$builder->connect(
    '/invoices/send-approval-links/{id}',
    ['controller' => 'Invoices', 'action' => 'sendApprovalLinks'],
    ['id' => '\d+', 'pass' => ['id']],
);
```

**Step 2:** En controller:

```php
public function sendApprovalLinks($id = null)
{
    $this->request->allowMethod(['post']);
    $invoice = $this->Invoices->get($id);
    $user = $this->_getCurrentUser();
    $ids = (array)$this->request->getData('approver_ids');
    $result = $this->approvalService->sendApprovalLinks($invoice, $ids, $this->_getBaseUrl(), $user->id);
    if ($result['success']) {
        $this->Flash->success('Enlaces de aprobación enviados.');
    } else {
        foreach ($result['errors'] as $e) { $this->Flash->error($e); }
    }
    return $this->redirect(['action' => 'edit', $id]);
}
```

**Step 3:** Commit.

### Task 3.2: Endpoint `modifyApprovers`

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Modify: `config/routes.php`

**Step 1:** Ruta `/invoices/modify-approvers/{id}`.

**Step 2:** Controller:

```php
public function modifyApprovers($id = null)
{
    $this->request->allowMethod(['post']);
    $invoice = $this->Invoices->get($id);
    $user = $this->_getCurrentUser();
    $reason = trim((string)$this->request->getData('reason'));
    $ids = (array)$this->request->getData('approver_ids');
    $result = $this->approvalService->modifyApprovers($invoice, $ids, $reason, $this->_getBaseUrl(), $user->id);
    // flash según success/errors
    return $this->redirect(['action' => 'edit', $id]);
}
```

**Step 3:** Commit.

### Task 3.3: Endpoint `resetFlow` (reiniciar)

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Modify: `config/routes.php`

**Step 1:** Ruta `/invoices/reset-flow/{id}`.

**Step 2:** Acción controller invoca `approvalService->resetFlow`. Flash + redirect edit.

**Step 3:** Commit.

### Task 3.4: Redirect `pagada && !Admin` → `view`

**Files:**
- Modify: `src/Controller/InvoicesController.php`

**Step 1:** Al inicio de `edit()`, después de fetch del invoice:

```php
if ($invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
    && $this->_getRoleName() !== RoleConstants::ADMIN) {
    return $this->redirect(['action' => 'view', $id]);
}
```

**Step 2:** Commit: `git commit -am "feat(InvoicesController): redirect paid invoices to view for non-admins"`.

### Task 3.5: `InvoicePaymentsController::addPayment` — soporte `advance_after` + checkbox "Pago total"

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php`

**Step 1:** Extraer `$advanceAfter = (bool)$this->request->getData('advance_after');`.

**Step 2:** Si `full_payment` flag: amount = remaining.

```php
$data = $this->request->getData();
if (!empty($data['full_payment'])) {
    $data['amount'] = $this->paymentService->getPendingBalance((int)$invoiceId);
}
$result = $this->paymentService->registerPayment((int)$invoiceId, $data, $user->id, $advanceAfter);
```

**Step 3:** Mensajes de flash diferenciados según avance.

**Step 4:** Commit.

### Task 3.6: Endpoint `editPayment`

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php`
- Modify: `config/routes.php`

**Step 1:** Ruta `/invoice-payments/edit-payment/{invoiceId}/{paymentId}`.

**Step 2:** Controller:

```php
public function editPayment($invoiceId = null, $paymentId = null)
{
    $this->request->allowMethod(['post']);
    // validar rol tesoreria / admin
    $data = array_intersect_key($this->request->getData(), array_flip(['banking_entity_id','amount','payment_date']));
    $reason = (string)$this->request->getData('reason');
    $result = $this->paymentService->editPayment((int)$paymentId, $data, $reason, (int)$this->_getCurrentUser()->id);
    // flash
    return $this->redirect(['controller' => 'Invoices', 'action' => 'edit', $invoiceId]);
}
```

**Step 3:** Commit.

### Task 3.7: Endpoint `rejectPayment` acepta motivo

**Files:**
- Modify: `src/Controller/InvoicePaymentsController.php`

**Step 1:** Cambiar firma para leer `reason`:

```php
$reason = (string)$this->request->getData('reason');
$result = $this->paymentService->rejectPayment((int)$paymentId, $userId, $reason);
```

**Step 2:** Validar que reason no esté vacío antes (o dejar que el service falle con flash apropiado).

**Step 3:** Commit.

### Task 3.8: `InvoicePaymentAttachmentsController` — upload/delete soportes

**Files:**
- Create: `src/Controller/InvoicePaymentAttachmentsController.php`
- Create: `src/Service/InvoicePaymentAttachmentService.php` (reutiliza `DocumentUploadTrait` si existe)
- Modify: `config/routes.php`

**Step 1:** Rutas:

```php
$builder->connect('/invoices/upload-payment-support/{invoiceId}',
    ['controller' => 'InvoicePaymentAttachments', 'action' => 'upload'], ['pass' => ['invoiceId']]);
$builder->connect('/invoices/delete-payment-support/{invoiceId}/{attachmentId}',
    ['controller' => 'InvoicePaymentAttachments', 'action' => 'delete'], ['pass' => ['invoiceId','attachmentId']]);
```

**Step 2:** Controller con métodos `upload($invoiceId)` y `delete($invoiceId, $attachmentId)`. Validar rol Tesoreria/Admin y que invoice esté en `autorizacion_pago` sub-fase B.

**Step 3:** Service/Trait que guarde archivo en `webroot/files/invoice-payment-supports/` y cree registro en tabla con `invoice_payment_id` (el soporte cuelga del PRIMER pago autorizado por simplicidad, o de `null` → reconsiderar: el diseño dice "cuelga del evento de pago"; si son múltiples pagos, el usuario indica a cuál asociar vía campo `invoice_payment_id` en el form).

Decisión: el form incluye un `<select>` de pagos autorizados existentes para asociarlo. Si hay solo 1, preseleccionado.

**Step 4:** Commit: `git commit -am "feat(controller): upload/delete invoice payment attachments"`.

### Task 3.9: Permisos módulo

**Files:**
- Modify: `src/Controller/AppController.php` (o donde esté `$controllerModuleMap`)
- Modify: `src/Service/AuthorizationService.php` si corresponde

**Step 1:** Agregar `InvoicePaymentAttachments => 'invoices'` al `$controllerModuleMap` (heredan permisos del módulo invoices).

**Step 2:** Commit.

---

## Fase 4 — Element `ledger_context.php`

### Task 4.1: Crear element reutilizable `ledger_context`

**Files:**
- Create: `templates/element/ledger_context.php`

**Step 1:** Escribir el element siguiendo el sistema de diseño:

```php
<?php
/**
 * @var \App\Model\Entity\Invoice $invoice
 * @var string[] $pipelineStatuses
 * @var string[] $pipelineLabels
 * @var string $currentStatus
 */
?>
<div class="sgi-ledger mb-4">
    <div class="row g-3">
        <div class="col-md-3"><small class="text-uppercase text-muted">Nº Factura</small>
            <div class="fw-semibold"><?= h($invoice->invoice_number ?? '—') ?></div></div>
        <div class="col-md-3"><small class="text-uppercase text-muted">Proveedor</small>
            <div><?= h($invoice->provider->name ?? '—') ?></div></div>
        <div class="col-md-2"><small class="text-uppercase text-muted">Monto</small>
            <div>$ <?= number_format((float)$invoice->amount, 0, ',', '.') ?></div></div>
        <div class="col-md-2"><small class="text-uppercase text-muted">Fecha emisión</small>
            <div><?= $invoice->issue_date?->format('d/m/Y') ?></div></div>
        <div class="col-md-2"><small class="text-uppercase text-muted">Vence</small>
            <div><?= $invoice->due_date?->format('d/m/Y') ?></div></div>
        <!-- Fila 2: centro operación, tipo gasto, centro costo, detalle -->
    </div>
</div>
```

Añadir clase `.sgi-ledger` en `webroot/css/styles.css`: fondo `#f8f9fa`, borde izquierdo verde 2px, padding 1rem.

**Step 2:** Commit: `git commit -am "feat(view): ledger_context element + CSS"`.

### Task 4.2: Enriquecer `pipeline_progress` con `$timeline`

**Files:**
- Modify: `templates/element/pipeline_progress.php`

**Step 1:** Aceptar variable opcional `$timeline` con formato:

```php
// $timeline = ['aprobacion' => ['user_name' => 'Alex', 'date' => '2026-04-10'], ...]
$timeline = $timeline ?? [];
```

**Step 2:** Dentro del loop de nodos, si existe `$timeline[$status]` agregar tooltip/subline:

```php
<?php if (!empty($timeline[$status])): ?>
<br><small style="font-size:.55rem;color:#777;"><?= h($timeline[$status]['user_name']) ?><br><?= h($timeline[$status]['date']) ?></small>
<?php endif; ?>
```

**Step 3:** En el controller (`view` y `edit`) construir `$timeline` desde `invoice_histories` (filtrar entries con `field_changed = 'pipeline_status'`).

Crear método utilitario en `InvoiceHistoryService::buildPipelineTimeline(int $invoiceId): array`.

**Step 4:** Commit.

---

## Fase 5 — Rediseño `edit.php` por estado

El archivo `templates/Invoices/edit.php` de 1238 líneas se dividirá en parciales. Cada estado tendrá su propio element.

### Task 5.1: Extraer sub-template estado `aprobacion` (rol Registro/Revisión)

**Files:**
- Create: `templates/element/invoice_edit/aprobacion_form.php`

**Step 1:** Crear element con solo la sección editable del estado Aprobación:

- Datos generales (invoice_number, issue_date, due_date, document_type, purchase_order, provider_id)
- Clasificación (operation_center_id, expense_type_id, cost_center_id, amount, detail)
- Revisión (approver_ids[] multi-select, dian_validation)

**Step 2:** Inputs deshabilitados cuando `$hasPendingApprovals` sea true sobre los campos "generales". `dian_validation` deshabilitado hasta `area_approval=Aprobada`.

**Step 3:** Tres botones auxiliares al lado del formulario:

```php
<?php if (!$hasPendingApprovals && empty($currentApprovals)): ?>
<button type="submit" formaction="<?= $this->Url->build(['action' => 'sendApprovalLinks', $invoice->id]) ?>"
        class="btn btn-outline-primary">Enviar link de aprobación</button>
<?php elseif (!empty($currentApprovals)): ?>
<button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modifyApproversModal">
    Modificar aprobadores</button>
<?php endif; ?>

<?php if ($invoice->area_approval === 'Rechazada'): ?>
<form method="post" action="<?= $this->Url->build(['action' => 'resetFlow', $invoice->id]) ?>" onsubmit="return confirm('¿Reiniciar flujo?')">
    <button class="btn btn-outline-warning">Reiniciar flujo</button>
</form>
<?php endif; ?>
```

**Step 4:** Commit.

### Task 5.2: Modal "Modificar aprobadores"

**Files:**
- Create: `templates/element/invoice_edit/modify_approvers_modal.php`

**Step 1:** Modal Bootstrap con form que posta a `modifyApprovers/{id}`, campos:
- `approver_ids[]` (select multiple con aprobadores activos)
- `reason` (textarea, required)
- Warning explicando que se invalidan tokens previos.

**Step 2:** Commit.

### Task 5.3: Sub-template estado `contabilidad` (rol Contabilidad)

**Files:**
- Create: `templates/element/invoice_edit/contabilidad_form.php`

**Step 1:** Formulario con únicamente:

```php
<label>Fecha de Causación *</label>
<input type="text" name="accrual_date" class="form-control flatpickr-date" required>
<small class="text-muted">Al diligenciar, la factura se marca como causada automáticamente.</small>

<label>Lista para Pago *</label>
<select name="ready_for_payment" class="form-select">...</select>
```

**Step 2:** No incluir checkbox `accrued`. En el controller/service, al guardar `accrual_date`, setear `accrued = (accrual_date !== null)`. Agregar hook en `InvoicesTable::beforeSave` o en el patchEntity filter:

```php
if (array_key_exists('accrual_date', $data)) {
    $data['accrued'] = !empty($data['accrual_date']);
}
```

Agregar esto en `InvoicePipelineService::saveAndAdvance` tras `filterEntityData`.

**Step 3:** Commit.

### Task 5.4: Sub-template estado `tesoreria` (rol Tesorería, sub-fase A)

**Files:**
- Create: `templates/element/invoice_edit/tesoreria_form.php`

**Step 1:** Alert si partial:

```php
<?php if ($invoice->payment_status === 'Pago Parcial'): ?>
<div class="alert alert-warning">⚠ Pago parcial — falta $<?= number_format($remaining,0,',','.') ?>...</div>
<?php endif; ?>
```

**Step 2:** Inlinear `payment_section` element con props adaptadas (ver Task 5.5). Botón principal `Avanzar a: Autorización de Pago` deshabilitado si no hay pagos pendientes con tooltip.

**Step 3:** Commit.

### Task 5.5: Actualizar `payment_section` con `mode` + checkbox pago total + dos botones

**Files:**
- Modify: `templates/element/payment_section.php`
- Modify: `webroot/js/sgi-payment.js`

**Step 1:** Aceptar variable `$mode ∈ ['tesoreria_register','authorize','close','view']` y renderizar condicionalmente.

**Step 2:** Reemplazar el botón "Pago Total" que existe por un checkbox dentro del form de alta:

```php
<div class="col-md-12">
    <label><input type="checkbox" data-pay-full> Pago total (usa monto restante)</label>
</div>
```

**Step 3:** Sustituir `Registrar` por dos botones:

```php
<button type="button" data-btn-register-advance class="btn btn-success">Registrar y enviar a autorización</button>
<button type="button" data-btn-register-only class="btn btn-outline-primary">Solo registrar</button>
```

`data-btn-register-advance` agrega `advance_after=1` al payload y muestra confirm modal.

**Step 4:** En `sgi-payment.js`:

- Listener del checkbox → rellena/deshabilita amount con `remainingAmount`.
- Listener de `data-btn-register-advance` → confirm("Este pago se registrará y la factura pasará inmediatamente al estado de Autorización de Pago. ¿Continuar?") + POST con advance_after=1.
- Listener de `data-btn-register-only` → POST sin flag.

**Step 5:** Commit.

### Task 5.6: Sub-template estado `autorizacion_pago` sub-fase A (rol Contador)

**Files:**
- Create: `templates/element/invoice_edit/autorizacion_contador_form.php`

**Step 1:** Tabla de pagos pendientes (`status=pending`) con columnas Banco/Monto/Fecha/Acciones `Autorizar` / `Rechazar`.

**Step 2:** Botón Rechazar abre modal con `reason` obligatorio (crear `reject_payment_modal.php`).

**Step 3:** Si todos autorizados sin rechazos: banner verde "Todos los pagos autorizados. Esperando cierre por Tesorería." (el Contador ya no tiene acciones).

**Step 4:** Sin submit global.

**Step 5:** Commit.

### Task 5.7: Sub-template estado `autorizacion_pago` sub-fase B (rol Tesorería)

**Files:**
- Create: `templates/element/invoice_edit/autorizacion_tesoreria_form.php`

**Step 1:** Detección de sub-fase (calcular en controller, pasar flag `$subPhaseB`):

```php
$allAuthorized = !$this->paymentService->hasPendingAuthorization($invoice->id)
    && !empty(filter array_authorized);
```

**Step 2:** Sección upload de soportes con form POST a `uploadPaymentSupport`:

```php
<form action=".../upload-payment-support/{id}" method="post" enctype="multipart/form-data">
    <select name="invoice_payment_id">autorizados</select>
    <input type="file" name="file" required>
    <button>Subir</button>
</form>
```

**Step 3:** Tabla read-only de pagos autorizados con link al soporte adjunto.

**Step 4:** Botón principal `Cerrar Factura` (POST a `advanceStatus`) deshabilitado si no hay soportes; tooltip: "Sube al menos un soporte para cerrar.".

**Step 5:** Caso especial: si `petty_cash_record_id` o factura en programación → banner informativo en vez de form. Lógica del diseño §3.4 Soportes.

**Step 6:** Commit.

### Task 5.8: Botón de submit único en `edit.php`

**Files:**
- Modify: `templates/Invoices/edit.php`

**Step 1:** Reducir drásticamente `edit.php` a un dispatcher:

```php
<?= $this->element('ledger_context', compact('invoice')) ?>
<?= $this->element('pipeline_progress', [...]) ?>

<?= $this->Form->create($invoice) ?>
<?php
$map = [
    'aprobacion' => 'invoice_edit/aprobacion_form',
    'contabilidad' => 'invoice_edit/contabilidad_form',
    'tesoreria' => 'invoice_edit/tesoreria_form',
    'autorizacion_pago' => $subPhaseB
        ? 'invoice_edit/autorizacion_tesoreria_form'
        : 'invoice_edit/autorizacion_contador_form',
];
echo $this->element($map[$currentStatus], compact('invoice','editableFields','currentApprovals', ...));
?>

<?php if ($showSingleSubmit): ?>
<button type="submit" class="btn btn-success" <?= $submitDisabled ? 'disabled title="..."' : '' ?>>
    <?= $submitLabel /* Guardar y Avanzar a: X | Avanzar a: X | Cerrar Factura */ ?>
</button>
<?php endif; ?>
<?= $this->Form->end() ?>
```

**Step 2:** Lógica del label en el controller:

```php
// en edit():
$submitLabel = 'Guardar y Avanzar a: ' . $pipelineLabels[$nextStatus];
if ($currentStatus === 'tesoreria') $submitLabel = 'Avanzar a: Autorización de Pago';
if ($currentStatus === 'autorizacion_pago' && $subPhaseB) $submitLabel = 'Cerrar Factura';
if (empty($editableFields) && $currentStatus === 'autorizacion_pago' && !$subPhaseB) $showSingleSubmit = false; // Contador
```

**Step 3:** Eliminar todo el código legado de secciones read-only con campos deshabilitados (arrays `$sectionFieldMap`, `$renderOrder`, etc.). El ledger + el form del estado los reemplazan.

**Step 4:** Eliminar flash warnings fantasma. En `InvoicesController::edit()` tras `saveAndAdvance`, filtrar `advanceErrors` por editabilidad (usando Task 2.7) y pasar a la vista como errores inline (junto a los campos), no como `Flash->warning`.

**Step 5:** Prueba manual: abrir factura en cada estado con el rol correspondiente, confirmar que:
  - Se ve el ledger arriba + pipeline progress
  - Solo la sección editable del rol aparece
  - Un solo botón al fondo, con label contextual

**Step 6:** Commit: `git commit -am "feat(view): unify edit.php per-state dispatch + single submit"`.

---

## Fase 6 — Vista `view.php` enriquecida (estado `pagada`)

### Task 6.1: Estructurar `view.php` con ledger + timeline + pagos + documentos

**Files:**
- Modify: `templates/Invoices/view.php`

**Step 1:** Layout:

1. `ledger_context` element
2. `pipeline_progress` con `$timeline` poblado
3. Tabla pagos autorizados (incluye link descarga soporte asociado)
4. Documentos adjuntos (abiertos; badge "Post-cierre" cuando `doc.created > invoice.full_payment_date`)
5. Observaciones abiertas (mismo badge)
6. Historial de cambios (expandible)

**Step 2:** Forms de "adjuntar documento" y "añadir observación" siempre disponibles para usuarios con `invoices.can_view`.

**Step 3:** Sin botones de avance.

**Step 4:** Commit.

---

## Fase 7 — JS / UX remate

### Task 7.1: Validación inline de campos requeridos

**Files:**
- Modify: `webroot/js/sgi-common.js` (o nuevo `sgi-invoice-form.js`)

**Step 1:** Al submit, validar campos marcados `data-required-for-advance`. Si faltan, `preventDefault()`, agregar clase `.is-invalid` y mensaje inline.

**Step 2:** Commit.

### Task 7.2: Tooltips en botones deshabilitados

**Files:**
- Modify: `webroot/js/sgi-common.js`

**Step 1:** Inicializar `bootstrap.Tooltip` para `[disabled][title]`.

**Step 2:** Commit.

---

## Fase 8 — Limpieza / cerrar

### Task 8.1: Remover referencias `approver_id` legado (si data lo permite)

**Files:**
- Migration opcional: `RemoveApproverIdFromInvoices.php`

**Step 1:** Verificar `SELECT COUNT(*) FROM invoices WHERE approver_id IS NOT NULL`. Si 0 o irrelevante, crear migración drop de columna.

**Step 2:** Si hay datos, diferir y documentar en el diseño.

**Step 3:** Commit (solo si se ejecuta drop).

### Task 8.2: Actualizar CLAUDE.md con nuevas convenciones

**Files:**
- Modify: `CLAUDE.md`

**Step 1:** Agregar al sección "Key Services":
- `InvoicePaymentService` acepta `advance_after` y `editPayment` con motivo.
- `InvoiceApprovalService::modifyApprovers`, `sendApprovalLinks`, `resetFlow`.
- Nota sobre sub-fase A/B del estado `autorizacion_pago` (derivada en runtime).

**Step 2:** Commit: `git commit -am "docs: update CLAUDE.md with invoice pipeline changes"`.

### Task 8.3: Verificación E2E manual

**Checklist (todo debe pasar):**

- [ ] Rol Registro en estado Aprobación: ve solo ledger + revision; envía enlaces; modifica aprobadores con modal; reinicia flujo tras rechazo; botón "Guardar y Avanzar a: Contabilidad" funciona.
- [ ] Rol Contabilidad en Contabilidad: ve ledger + dos campos; al diligenciar fecha causación avanza a Tesorería.
- [ ] Rol Tesorería en Tesorería: alert parcial aparece cuando corresponde; checkbox "Pago total" llena monto; "Registrar y enviar" confirma y avanza; "Solo registrar" mantiene en Tesorería.
- [ ] Rol Contador en Aut. Pago sub-A: autoriza/rechaza por fila; banner verde cuando todo autorizado; sin submit global.
- [ ] Rol Tesorería en Aut. Pago sub-B: sube soportes; botón "Cerrar Factura" disabled sin soportes; al cerrar factura parcial regresa a Tesorería.
- [ ] Facturas en `petty_cash` o `programacion`: banner informativo sin botón "Cerrar Factura" en sub-B.
- [ ] Rol cualquiera no-admin abriendo factura Pagada: redirige a `view` con ledger + timeline + pagos + documentos abiertos.
- [ ] `composer check` pasa (cs-check + tests).

### Task 8.4: Commit final + PR

**Step 1:** `composer cs-fix && composer check`.

**Step 2:** Push rama, crear PR con título "feat: rediseño UI pipeline de facturas".

---

## Dependencias explícitas fuera de este plan

- Diseño e implementación del botón transversal "Regresar estado" (tarea separada).
- Replicación sub-fase B en Caja Menor y Programación de Pagos (módulos propios).
- Revisión catálogo `ready_for_payment`, comprobante consolidado PDF, notificación a proveedor en Pagada, batch authorization: TODAS fuera de alcance.

## Notas de ejecución

- Mantener commits atómicos (uno por Task o Step cuando aplique).
- Ejecutar `composer check` después de cada Fase.
- Ante duda en la sub-fase B (qué ocurre si hay pagos rechazados + autorizados sin pendientes) → mantener factura en sub-A mostrando tabla mixta; el Contador decide si ampliar aceptación o esperar nuevo pago desde Tesorería.
