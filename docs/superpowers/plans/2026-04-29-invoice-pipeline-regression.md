# Invoice Pipeline Regression Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir un botón "Regresar al paso anterior" a cada estado del pipeline de facturas (excepto `aprobacion`) que cambia `pipeline_status` al estado predecesor previa observación obligatoria del usuario, registrando trazabilidad en `invoice_histories` y en `invoice_observations` con `type='regression'`.

**Architecture:** Regresión "fría" (solo cambia `pipeline_status`, no toca datos colaterales). Lógica añadida a `InvoicePipelineService` simétrica a `advance()`. Permisos derivados de `ROLE_VISIBLE_STATUSES` existente. Bloqueos centralizados en `getRegressionLockMessage()` (caja menor, programación pagada, anticipo con legalización iniciada, factura rechazada). Persistencia del motivo en `invoice_observations` extendido con `type` y `metadata`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, PHPUnit, Bootstrap 5, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-04-29-invoice-pipeline-regression-design.md`.

---

## File Structure

**New files:**
- `config/Migrations/<TIMESTAMP>_AddTypeAndMetadataToInvoiceObservations.php` — migración.
- `tests/TestCase/Service/InvoicePipelineServiceTest.php` — tests del service (pueden no existir hoy).
- `tests/TestCase/Controller/InvoicesControllerTest.php` — tests del controlador.

**Modified files:**
- `src/Constants/InvoiceConstants.php` — añadir `OBSERVATION_TYPE_*`.
- `src/Service/InvoicePipelineService.php` — añadir `BACKWARD_TRANSITIONS`, `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`.
- `src/Service/AdvanceLegalizationService.php` — añadir `hasLegalization`.
- `src/Model/Entity/InvoiceObservation.php` — añadir `type` y `metadata` a `_accessible`.
- `src/Model/Table/InvoiceObservationsTable.php` — validación de `type` y `message` largo cuando regression.
- `src/Controller/AppController.php` — añadir `regressStatus` al mapping `_actionToPermission`.
- `src/Controller/InvoicesController.php` — añadir acción `regressStatus`.
- `config/routes.php` — ruta `/invoices/regress-status/{id}`.
- `templates/Invoices/edit.php` — botón + modal `regressStatusModal`.
- `templates/Invoices/view.php` — badge `[Regresión]` y línea de transición en observaciones.
- `tests/TestCase/Service/AdvanceLegalizationServiceTest.php` — test para `hasLegalization`.

---

## Task 1: Migration — extender `invoice_observations`

**Files:**
- Create: `config/Migrations/<TIMESTAMP>_AddTypeAndMetadataToInvoiceObservations.php`

- [ ] **Step 1: Generar el archivo de migración con el nombre adecuado**

```bash
php bin/cake migrations create AddTypeAndMetadataToInvoiceObservations
```

Expected: archivo creado en `config/Migrations/<TIMESTAMP>_AddTypeAndMetadataToInvoiceObservations.php` con un esqueleto vacío que extiende `BaseMigration`.

- [ ] **Step 2: Sustituir el contenido del archivo por la migración real**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToInvoiceObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_observations');

        $columns = $table->getColumns();
        $columnNames = array_map(fn($c) => $c->getName(), $columns);

        if (!in_array('type', $columnNames, true)) {
            $table->addColumn('type', 'string', [
                'limit' => 20,
                'default' => 'general',
                'null' => false,
                'after' => 'message',
            ]);
        }

        if (!in_array('metadata', $columnNames, true)) {
            $table->addColumn('metadata', 'json', [
                'null' => true,
                'after' => 'type',
            ]);
        }

        $table->update();

        $indexTable = $this->table('invoice_observations');
        if (!$indexTable->hasIndex(['invoice_id', 'type'])) {
            $indexTable->addIndex(['invoice_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('invoice_observations');
        if ($table->hasIndex(['invoice_id', 'type'])) {
            $table->removeIndex(['invoice_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
```

- [ ] **Step 3: Aplicar la migración al esquema local**

Run: `php bin/cake migrations migrate`
Expected: salida con `<TIMESTAMP> AddTypeAndMetadataToInvoiceObservations: migrated`.

- [ ] **Step 4: Verificar el esquema en MySQL**

Run: `php bin/cake migrations status` y luego:

```bash
php -r "require 'config/bootstrap.php'; \
  use Cake\Database\Connection; use Cake\Datasource\ConnectionManager; \
  /** @var Connection \$c */ \$c = ConnectionManager::get('default'); \
  print_r(\$c->getSchemaCollection()->describe('invoice_observations')->columns());"
```

Expected: la lista incluye `type` y `metadata`.

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/
git commit -m "feat(invoices): migrate invoice_observations with type and metadata columns"
```

---

## Task 2: Constantes en `InvoiceConstants` y `BACKWARD_TRANSITIONS`

**Files:**
- Modify: `src/Constants/InvoiceConstants.php`
- Modify: `src/Service/InvoicePipelineService.php`

- [ ] **Step 1: Añadir constantes de tipo de observación a `InvoiceConstants`**

Editar `src/Constants/InvoiceConstants.php` y añadir, justo antes de la línea final `}` (después de `HOLDER_TYPES`):

```php
    // Tipos de observación (invoice_observations.type)
    public const OBSERVATION_TYPE_GENERAL = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];
```

- [ ] **Step 2: Añadir `BACKWARD_TRANSITIONS` a `InvoicePipelineService`**

Editar `src/Service/InvoicePipelineService.php`. Localizar la constante `TRANSITIONS` (~línea 123). Inmediatamente debajo de su cierre, añadir:

```php
    // Backward transitions (counterpart of TRANSITIONS for the regress operation).
    public const BACKWARD_TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION         => null,
        InvoiceConstants::STATUS_CONTABILIDAD       => InvoiceConstants::STATUS_APROBACION,
        InvoiceConstants::STATUS_TESORERIA          => InvoiceConstants::STATUS_CONTABILIDAD,
        InvoiceConstants::STATUS_AUTORIZACION_PAGO  => InvoiceConstants::STATUS_TESORERIA,
        InvoiceConstants::STATUS_PAGADA             => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
    ];
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Constants/InvoiceConstants.php && php -l src/Service/InvoicePipelineService.php`
Expected: ambos archivos `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Constants/InvoiceConstants.php src/Service/InvoicePipelineService.php
git commit -m "feat(invoices): add observation type constants and backward transitions map"
```

---

## Task 3: `AdvanceLegalizationService::hasLegalization` (TDD)

**Files:**
- Modify: `src/Service/AdvanceLegalizationService.php`
- Test: `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`

- [ ] **Step 1: Añadir test que falla**

Editar `tests/TestCase/Service/AdvanceLegalizationServiceTest.php`. Antes del cierre de la clase, añadir:

```php
    public function testHasLegalizationReturnsTrueWhenRowExists(): void
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'has-legalization-test',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);
        $this->assertTrue((bool)$invoices->save($advance), json_encode($advance->getErrors()));

        $this->assertFalse(
            $this->service->hasLegalization((int)$advance->id),
            'No debería existir legalización antes de inicializar.'
        );

        $this->service->initialize($advance, 1);

        $this->assertTrue(
            $this->service->hasLegalization((int)$advance->id),
            'Debe detectar la legalización ya creada.'
        );
    }
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `composer test -- --filter testHasLegalizationReturnsTrueWhenRowExists`
Expected: ERROR / FAIL con mensaje `Call to undefined method App\Service\AdvanceLegalizationService::hasLegalization()`.

- [ ] **Step 3: Implementar el método**

Editar `src/Service/AdvanceLegalizationService.php`. Añadir el siguiente método (antes del cierre de la clase, junto a `initialize`):

```php
    /**
     * Returns true if there is an advance_legalizations row linked to the given anticipo invoice id.
     */
    public function hasLegalization(int $invoiceId): bool
    {
        $table = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        return $table->find()
            ->where(['advance_invoice_id' => $invoiceId])
            ->count() > 0;
    }
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `composer test -- --filter testHasLegalizationReturnsTrueWhenRowExists`
Expected: OK (1 test, ≥2 assertions).

- [ ] **Step 5: Commit**

```bash
git add src/Service/AdvanceLegalizationService.php tests/TestCase/Service/AdvanceLegalizationServiceTest.php
git commit -m "feat(advances): add hasLegalization helper to AdvanceLegalizationService"
```

---

## Task 4: Entity y validación de `InvoiceObservation`

**Files:**
- Modify: `src/Model/Entity/InvoiceObservation.php`
- Modify: `src/Model/Table/InvoiceObservationsTable.php`

- [ ] **Step 1: Actualizar `_accessible` del entity**

Reemplazar el contenido completo de `src/Model/Entity/InvoiceObservation.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class InvoiceObservation extends Entity
{
    protected array $_accessible = [
        'invoice_id' => true,
        'user_id' => true,
        'message' => true,
        'type' => true,
        'metadata' => true,
    ];
}
```

- [ ] **Step 2: Actualizar `validationDefault` en la table**

Editar `src/Model/Table/InvoiceObservationsTable.php`. Reemplazar el método `validationDefault` por:

```php
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'La observación no puede estar vacía.')
            ->add('message', 'minLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\InvoiceConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\InvoiceConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen(trim($value)) >= 10;
                },
                'message' => 'El motivo de la regresión debe tener al menos 10 caracteres.',
            ])
            ->add('message', 'maxLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\InvoiceConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\InvoiceConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen($value) <= 500;
                },
                'message' => 'El motivo de la regresión no puede superar 500 caracteres.',
            ]);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->inList('type', \App\Constants\InvoiceConstants::OBSERVATION_TYPES, 'Tipo de observación inválido.')
            ->allowEmptyString('type');

        return $validator;
    }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Model/Entity/InvoiceObservation.php && php -l src/Model/Table/InvoiceObservationsTable.php`
Expected: ambos `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Model/Entity/InvoiceObservation.php src/Model/Table/InvoiceObservationsTable.php
git commit -m "feat(invoices): allow type/metadata on InvoiceObservation with conditional validation"
```

---

## Task 5: `getPreviousStatus` y `canRegress` en `InvoicePipelineService` (TDD)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Create: `tests/TestCase/Service/InvoicePipelineServiceTest.php`

- [ ] **Step 1: Crear el archivo de test con casos para `getPreviousStatus` y `canRegress`**

Crear `tests/TestCase/Service/InvoicePipelineServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePipelineService;
use Cake\TestSuite\TestCase;

class InvoicePipelineServiceTest extends TestCase
{
    private InvoicePipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoicePipelineService();
    }

    public function testGetPreviousStatusReturnsExpectedMap(): void
    {
        $this->assertNull($this->service->getPreviousStatus(InvoiceConstants::STATUS_APROBACION));
        $this->assertSame(
            InvoiceConstants::STATUS_APROBACION,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_CONTABILIDAD)
        );
        $this->assertSame(
            InvoiceConstants::STATUS_CONTABILIDAD,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_TESORERIA)
        );
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO)
        );
        $this->assertSame(
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_PAGADA)
        );
    }

    public function testCanRegressTrueForVisibleStateWithPredecessor(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTADOR, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
    }

    public function testCanRegressFalseWhenStateNotVisibleForRole(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTADOR, InvoiceConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::REGISTRO_REVISION, InvoiceConstants::STATUS_APROBACION));
    }

    public function testCanRegressFalseFromAprobacion(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_APROBACION));
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_APROBACION));
    }

    public function testCanRegressTrueForAdminFromAnyNonAprobacionState(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_PAGADA));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: errores `Call to undefined method ...::getPreviousStatus()` y `::canRegress()`.

- [ ] **Step 3: Implementar `getPreviousStatus` y `canRegress` en el service**

Editar `src/Service/InvoicePipelineService.php`. Añadir los métodos antes del cierre de la clase (justo antes de la `}` final):

```php
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return self::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canRegress(string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        return in_array($currentStatus, $this->getVisibleStatuses($roleName), true);
    }
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: OK (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php tests/TestCase/Service/InvoicePipelineServiceTest.php
git commit -m "feat(invoices): add getPreviousStatus and canRegress to InvoicePipelineService"
```

---

## Task 6: `getRegressionLockMessage` (TDD)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `tests/TestCase/Service/InvoicePipelineServiceTest.php`

- [ ] **Step 1: Añadir tests para los 4 bloqueos**

Añadir al final de `tests/TestCase/Service/InvoicePipelineServiceTest.php` (antes del cierre de la clase):

```php
    public function testGetRegressionLockMessageReturnsNullWhenNoLock(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'no-lock',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $this->assertTrue((bool)$invoices->save($invoice), json_encode($invoice->getErrors()));

        $this->assertNull($this->service->getRegressionLockMessage($invoice));
    }

    public function testGetRegressionLockMessageBlocksPettyCash(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'pc',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'petty_cash_record_id' => 999,
        ]);
        $invoice->petty_cash_record_id = 999;

        $msg = $this->service->getRegressionLockMessage($invoice);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Caja Menor', $msg);
    }

    public function testGetRegressionLockMessageBlocksRejected(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'detail' => 'rej',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ]);

        $msg = $this->service->getRegressionLockMessage($invoice);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Rechazada', $msg);
    }

    public function testGetRegressionLockMessageBlocksAnticipoWithLegalization(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $advance = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_ANTICIPO,
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'detail' => 'leg-block',
            'amount' => 1000,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);
        $this->assertTrue((bool)$invoices->save($advance), json_encode($advance->getErrors()));

        // Crear la fila de legalización para activar el bloqueo
        (new \App\Service\AdvanceLegalizationService())->initialize($advance, 1);

        $msg = $this->service->getRegressionLockMessage($advance);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('legalización', $msg);
    }
```

- [ ] **Step 2: Correr y confirmar fallo**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: ERROR `Call to undefined method ...::getRegressionLockMessage()`.

- [ ] **Step 3: Implementar el método**

Editar `src/Service/InvoicePipelineService.php`. Después del método `canRegress` añadir:

```php
    /**
     * Returns a human-readable reason if the invoice cannot be regressed,
     * or null if regression is allowed (independent of role).
     */
    public function getRegressionLockMessage(object $invoice): ?string
    {
        if (($invoice->area_approval ?? null) === InvoiceConstants::APPROVAL_REJECTED) {
            return "Factura rechazada. Use 'Reiniciar flujo' para reactivarla.";
        }
        if ($this->isLockedByPettyCash($invoice)) {
            return 'Factura bloqueada: pertenece a un registro de Caja Menor.';
        }
        if (!empty($invoice->id) && $this->isLockedByPaidScheduling((int)$invoice->id)) {
            return 'Factura bloqueada: tiene pagos en una programación ya pagada.';
        }
        if (
            ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
            && !empty($invoice->id)
            && $this->advanceLegalizationService->hasLegalization((int)$invoice->id)
        ) {
            return 'No se puede regresar: la legalización del anticipo ya fue iniciada.';
        }

        return null;
    }
```

- [ ] **Step 4: Correr y confirmar éxito**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: OK (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php tests/TestCase/Service/InvoicePipelineServiceTest.php
git commit -m "feat(invoices): add getRegressionLockMessage covering 4 lock conditions"
```

---

## Task 7: Método `regress()` en `InvoicePipelineService` (TDD)

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `tests/TestCase/Service/InvoicePipelineServiceTest.php`

- [ ] **Step 1: Añadir test del happy path**

Añadir al final de `tests/TestCase/Service/InvoicePipelineServiceTest.php` (antes del cierre de la clase):

```php
    public function testRegressHappyPathFromTesoreriaToContabilidad(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $observations = \Cake\ORM\TableRegistry::getTableLocator()->get('InvoiceObservations');
        $histories = \Cake\ORM\TableRegistry::getTableLocator()->get('InvoiceHistories');

        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'regress-happy',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $this->assertTrue((bool)$invoices->save($invoice), json_encode($invoice->getErrors()));

        $result = $this->service->regress(
            $invoice,
            RoleConstants::TESORERIA,
            1,
            'Falta verificar la causación contable'
        );

        $this->assertTrue($result['success'], $result['error'] ?? 'no error message');
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $result['previousStatus']);

        $refreshed = $invoices->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $refreshed->pipeline_status);

        $obs = $observations->find()->where(['invoice_id' => $invoice->id])->first();
        $this->assertNotNull($obs);
        $this->assertSame(InvoiceConstants::OBSERVATION_TYPE_REGRESSION, $obs->type);
        $this->assertSame('Falta verificar la causación contable', $obs->message);
        $this->assertSame(InvoiceConstants::STATUS_TESORERIA, $obs->metadata['from_status'] ?? null);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $obs->metadata['to_status'] ?? null);

        $hist = $histories->find()->where([
            'invoice_id' => $invoice->id,
            'field_changed' => 'pipeline_status',
        ])->first();
        $this->assertNotNull($hist);
    }

    public function testRegressFailsWhenReasonTooShort(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'short-reason',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $invoices->save($invoice);

        $result = $this->service->regress(
            $invoice,
            RoleConstants::TESORERIA,
            1,
            'corto'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('motivo', mb_strtolower((string)$result['error']));
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $invoices->get($invoice->id)->pipeline_status
        );
    }

    public function testRegressFailsWhenRoleNotAuthorized(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'wrong-role',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $invoices->save($invoice);

        $result = $this->service->regress(
            $invoice,
            RoleConstants::CONTABILIDAD,
            1,
            'Motivo razonable de regresión'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('permisos', mb_strtolower((string)$result['error']));
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $invoices->get($invoice->id)->pipeline_status
        );
    }

    public function testRegressFailsWhenNoPredecessor(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'detail' => 'no-pred',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
        ]);
        $invoices->save($invoice);

        $result = $this->service->regress(
            $invoice,
            RoleConstants::ADMIN,
            1,
            'Motivo razonable de regresión'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('primer paso', mb_strtolower((string)$result['error']));
    }

    public function testRegressFailsWhenLockActive(): void
    {
        $invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoices->newEntity([
            'document_type' => InvoiceConstants::DOCTYPE_CAJA_MENOR,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'pc-block',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'petty_cash_record_id' => 9999,
        ]);
        $invoices->save($invoice);

        $result = $this->service->regress(
            $invoice,
            RoleConstants::TESORERIA,
            1,
            'Motivo razonable de regresión'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Caja Menor', (string)$result['error']);
    }
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: errores `Call to undefined method ...::regress()`.

- [ ] **Step 3: Implementar `regress()`**

Editar `src/Service/InvoicePipelineService.php`. Después de `getRegressionLockMessage` añadir:

```php
    /**
     * Regress the invoice to its previous pipeline status (cold regression).
     * Records a status change in invoice_histories and stores the reason in
     * invoice_observations as a regression-typed observation.
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        Invoice $invoice,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $invoice->pipeline_status;

        if (!$this->canRegress($roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta factura ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta factura.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($invoice);
        if ($lock !== null) {
            return ['success' => false, 'error' => $lock, 'previousStatus' => null];
        }

        if (mb_strlen($reason) < 10) {
            return [
                'success' => false,
                'error' => 'El motivo es obligatorio (mínimo 10 caracteres).',
                'previousStatus' => null,
            ];
        }
        if (mb_strlen($reason) > 500) {
            return [
                'success' => false,
                'error' => 'El motivo no puede superar 500 caracteres.',
                'previousStatus' => null,
            ];
        }

        $previousStatus = $this->getPreviousStatus($currentStatus);
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('InvoiceObservations');

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $invoicesTable,
                $observationsTable,
                $invoice,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason
            ): bool {
                $invoice->pipeline_status = $previousStatus;
                if (!$invoicesTable->save($invoice)) {
                    return false;
                }

                $this->historyService->recordStatusChange(
                    $invoice->id,
                    $currentStatus,
                    $previousStatus,
                    $userId,
                );

                $observation = $observationsTable->newEntity([
                    'invoice_id' => $invoice->id,
                    'user_id' => $userId,
                    'type' => InvoiceConstants::OBSERVATION_TYPE_REGRESSION,
                    'message' => $reason,
                    'metadata' => [
                        'from_status' => $currentStatus,
                        'to_status' => $previousStatus,
                    ],
                ]);

                return (bool)$observationsTable->save($observation);
            },
        );

        if (!$ok) {
            return [
                'success' => false,
                'error' => 'No se pudo regresar la factura. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }
```

- [ ] **Step 4: Correr los tests y confirmar éxito**

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: OK (14 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php tests/TestCase/Service/InvoicePipelineServiceTest.php
git commit -m "feat(invoices): add regress() to InvoicePipelineService with history and observation"
```

---

## Task 8: Permiso, ruta y mapeo de acción

**Files:**
- Modify: `src/Controller/AppController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Añadir `regressStatus` al match de permisos**

Editar `src/Controller/AppController.php`. Localizar el `match ($action)` dentro de `_actionToPermission` (línea 65-71). Añadir `'regressStatus'` al grupo `'edit'`:

Reemplazar la línea que comienza con `'edit',` (línea 68) por la versión con `'regressStatus'` añadido. La nueva línea queda:

```php
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion' => 'edit',
```

- [ ] **Step 2: Añadir la ruta antes de `fallbacks`**

Editar `config/routes.php`. Localizar la línea `$builder->fallbacks();` y añadir antes:

```php
    $builder->connect(
        '/invoices/regress-status/{id}',
        ['controller' => 'Invoices', 'action' => 'regressStatus'],
        ['id' => '\d+', 'pass' => ['id']],
    );
```

Si ya existe la ruta `/invoices/advance-status/{id}` colocar la nueva inmediatamente debajo para mantener simetría.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Controller/AppController.php && php -l config/routes.php`
Expected: ambos `No syntax errors detected`.

- [ ] **Step 4: Verificar que la ruta aparece**

Run: `php bin/cake routes | grep regress-status`
Expected: una línea con `regress-status` apuntando a `Invoices::regressStatus`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/AppController.php config/routes.php
git commit -m "feat(invoices): map regressStatus to edit permission and register route"
```

---

## Task 9: `InvoicesController::regressStatus`

**Files:**
- Modify: `src/Controller/InvoicesController.php`
- Create: `tests/TestCase/Controller/InvoicesControllerTest.php`

- [ ] **Step 1: Implementar la acción**

Editar `src/Controller/InvoicesController.php`. Localizar `public function advanceStatus($id = null)` (línea 353). Inmediatamente después del método (después de la `}` de cierre, línea ~381) añadir:

```php
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);
        $user = $this->_getCurrentUser();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pipeline->regress(
            $invoice,
            $this->_getRoleName(),
            (int)$user->id,
            $reason,
        );

        if ($result['success']) {
            $prevLabel = InvoicePipelineService::STATUS_LABELS[$result['previousStatus']]
                ?? $result['previousStatus'];
            $this->Flash->success(sprintf('Factura regresada a: %s', $prevLabel));

            return $this->_redirectForInvoice($invoice, 'index');
        }

        $this->Flash->error($result['error']);

        return $this->_redirectForInvoice($invoice, 'edit', $id);
    }
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l src/Controller/InvoicesController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Crear el test del controlador (happy path)**

Crear `tests/TestCase/Controller/InvoicesControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class InvoicesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private function loginAsRole(string $roleName): array
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $rolesTable = TableRegistry::getTableLocator()->get('Roles');

        $role = $rolesTable->find()->where(['name' => $roleName])->first();
        $this->assertNotNull($role, "Rol $roleName no existe en la BD de tests.");

        $user = $usersTable->newEntity([
            'username' => 'test_' . strtolower(str_replace([' ', '/'], '_', $roleName)) . '_' . uniqid(),
            'email' => uniqid('test_') . '@example.com',
            'full_name' => 'Test ' . $roleName,
            'password' => 'pass',
            'role_id' => $role->id,
            'active' => true,
        ]);
        $this->assertTrue((bool)$usersTable->save($user), json_encode($user->getErrors()));

        $this->session([
            'Auth' => $user->toArray(),
        ]);

        return ['user' => $user, 'role' => $role];
    }

    private function createInvoice(array $overrides = []): \Cake\Datasource\EntityInterface
    {
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $defaults = [
            'document_type' => InvoiceConstants::DOCTYPE_FACTURA,
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'detail' => 'controller-test',
            'amount' => 100,
            'issue_date' => date('Y-m-d'),
            'operation_center_id' => 1,
            'expense_type_id' => 1,
            'registered_by' => 1,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ];
        $invoice = $invoices->newEntity(array_merge($defaults, $overrides));
        $invoices->save($invoice);

        return $invoice;
    }

    public function testRegressStatusRequiresPost(): void
    {
        $this->loginAsRole(RoleConstants::ADMIN);
        $invoice = $this->createInvoice();

        $this->get('/invoices/regress-status/' . $invoice->id);

        $this->assertResponseCode(405);
    }

    public function testRegressStatusHappyPath(): void
    {
        $this->loginAsRole(RoleConstants::TESORERIA);
        $invoice = $this->createInvoice();

        $this->enableCsrfToken();
        $this->post('/invoices/regress-status/' . $invoice->id, [
            'reason' => 'Falta verificar la causación contable',
        ]);

        $this->assertRedirectContains('/invoices');
        $this->assertFlashMessage('Factura regresada a: Contabilidad');

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $refreshed = $invoices->get($invoice->id);
        $this->assertSame(InvoiceConstants::STATUS_CONTABILIDAD, $refreshed->pipeline_status);
    }

    public function testRegressStatusFailsWithoutReason(): void
    {
        $this->loginAsRole(RoleConstants::TESORERIA);
        $invoice = $this->createInvoice();

        $this->enableCsrfToken();
        $this->post('/invoices/regress-status/' . $invoice->id, [
            'reason' => '',
        ]);

        $this->assertRedirect();
        $this->assertSession('El motivo es obligatorio (mínimo 10 caracteres).', 'Flash.flash.0.message');

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $invoices->get($invoice->id)->pipeline_status,
        );
    }

    public function testRegressStatusFailsForUnauthorizedRole(): void
    {
        $this->loginAsRole(RoleConstants::CONTABILIDAD);
        $invoice = $this->createInvoice();

        $this->enableCsrfToken();
        $this->post('/invoices/regress-status/' . $invoice->id, [
            'reason' => 'Motivo razonable de regresión',
        ]);

        $this->assertRedirect();
        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $invoices->get($invoice->id)->pipeline_status,
        );
    }
}
```

- [ ] **Step 4: Correr los tests del controlador**

Run: `composer test -- --filter InvoicesControllerTest`
Expected: OK (4 tests). Si la prueba falla por permisos faltantes en BD de test (por ejemplo el rol `Tesorería` sin filas en `permissions`), revisar `config/Migrations/` por seeds y replicarlos en `setUp` o complementar con `TableRegistry::get('Permissions')->newEntity([...])->save()` antes del POST.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InvoicesController.php tests/TestCase/Controller/InvoicesControllerTest.php
git commit -m "feat(invoices): add regressStatus action with controller tests"
```

---

## Task 10: Botón y modal en `templates/Invoices/edit.php`

**Files:**
- Modify: `src/Controller/InvoicesController.php` (pasar variables a la vista)
- Modify: `templates/Invoices/edit.php`

- [ ] **Step 1: Pasar `canRegress` y `regressLockMessage` a la vista de edición**

Editar `src/Controller/InvoicesController.php`. Localizar el método `edit($id = null)` y, antes del bloque `compact(...)` que pasa variables a la vista (alrededor de la línea 320-345), añadir el cálculo de regresión. Buscar la línea con `$advanceErrors = [];` (línea 262) y, debajo del cálculo de `advanceErrors`, añadir:

```php
        $canRegress = $this->pipeline->canRegress($roleName, $currentStatus);
        $previousStatus = $this->pipeline->getPreviousStatus($currentStatus);
        $regressLockMessage = $this->pipeline->getRegressionLockMessage($invoice);
```

Después, en el `compact(...)` de la vista (líneas ~340), añadir las nuevas variables:

```php
            'canRegress',
            'previousStatus',
            'regressLockMessage',
```

(insertarlas después de `'nextStatus'` para mantener simetría).

- [ ] **Step 2: Renderizar el botón en `edit.php`**

Editar `templates/Invoices/edit.php`. Localizar el bloque "Botones de acción (sticky)" (línea 876-887). Reemplazar el bloque por:

```php
        <!-- Botones de acción (sticky) -->
        <?php if (!empty($editableFields) || ($canRegress ?? false)): ?>
        <div class="sgi-sticky-actions d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($editableFields)): ?>
                <button type="submit" class="<?= $btnClass ?>">
                    <?= $btnLabel ?>
                </button>
            <?php endif; ?>

            <?php if (!empty($canRegress)):
                $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
                $isLocked = !empty($regressLockMessage);
            ?>
                <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline-secondary"
                            disabled title="<?= h($regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal"
                            data-prev-label="<?= h($prevLabel) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'view', $invoice->id],
                ['class' => 'btn btn-outline-secondary ms-auto']
            ) ?>
        </div>
        <?php elseif (empty(array_intersect($functionalSections, $visibleSections))): ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1"></i>
            No tiene permisos de edición para esta factura en el estado actual.
        </div>
        <?php endif; ?>
```

- [ ] **Step 3: Añadir el modal al final de la vista**

Editar `templates/Invoices/edit.php`. Antes del último `<?php $this->end(); ?>` o al final del archivo (justo antes del último cierre de bloque PHP de scripts), añadir:

```php
<?php if (!empty($canRegress) && empty($regressLockMessage)):
    $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
    $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
?>
<!-- Modal: Regresar al paso anterior -->
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= $this->Url->build(['action' => 'regressStatus', $invoice->id]) ?>"
              id="regressStatusForm">
            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Regresar al paso anterior
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Esta factura volverá del paso
                        <strong><?= h($currLabel) ?></strong>
                        al paso
                        <strong><?= h($prevLabel) ?></strong>.
                    </p>
                    <div class="mb-2">
                        <label for="regressReason" class="form-label">
                            Motivo de la regresión <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="regressReason"
                                  class="form-control" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Describa por qué está regresando esta factura..."></textarea>
                        <div class="form-text">Mín. 10 caracteres · Máx. 500.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="regressConfirmBtn" class="btn btn-warning" disabled>
                        Confirmar regreso
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var ta = document.getElementById('regressReason');
    var btn = document.getElementById('regressConfirmBtn');
    if (!ta || !btn) return;
    ta.addEventListener('input', function () {
        btn.disabled = ta.value.trim().length < 10;
    });
})();
</script>
<?php endif; ?>
```

- [ ] **Step 4: Verificar sintaxis y arrancar el server local**

Run: `php -l templates/Invoices/edit.php && php -l src/Controller/InvoicesController.php`
Expected: ambos `No syntax errors detected`.

- [ ] **Step 5: Smoke test manual en navegador**

1. Iniciar el servidor: `php bin/cake server` (se queda corriendo).
2. Loguearse como Tesorería en `localhost:8765`.
3. Abrir una factura en estado `tesoreria` editable.
4. Verificar que aparece el botón "Regresar a: Contabilidad".
5. Pulsar y comprobar que el modal abre con la frase correcta.
6. El botón "Confirmar regreso" debe estar deshabilitado al inicio.
7. Escribir 5 caracteres → sigue deshabilitado.
8. Escribir 10+ caracteres → se habilita.
9. Confirmar → flash success, factura ahora en `contabilidad`.
10. Detener el servidor con Ctrl+C.

(Si el server no inicia por puerto ocupado, usar `php bin/cake server -p 8766`.)

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InvoicesController.php templates/Invoices/edit.php
git commit -m "feat(invoices): add regression button and modal to edit form"
```

---

## Task 11: Visualización de observaciones de regresión en `view.php`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php` (exponer labels para template — opcional, ya hay constante pública)
- Modify: `templates/Invoices/view.php`

- [ ] **Step 1: Modificar el render de observaciones**

Editar `templates/Invoices/view.php`. Localizar el bloque de observaciones (líneas 222-252). Reemplazarlo por:

```php
    <!-- Sección: Observaciones (chat) -->
    <?php if (!empty($invoice->invoice_observations)): ?>
    <?php
    $statusLabels = \App\Service\InvoicePipelineService::STATUS_LABELS;
    ?>
    <div style="border-bottom:1px solid var(--border-color);">
        <div class="sgi-section-title">Observaciones</div>
        <div style="padding:.5rem 1.25rem .875rem;max-height:400px;overflow-y:auto;">
            <?php foreach ($invoice->invoice_observations as $obs): ?>
            <?php
                $isRegression = ($obs->type ?? null) === \App\Constants\InvoiceConstants::OBSERVATION_TYPE_REGRESSION;
                $meta = $obs->metadata ?? [];
                $fromLbl = $statusLabels[$meta['from_status'] ?? ''] ?? null;
                $toLbl = $statusLabels[$meta['to_status'] ?? ''] ?? null;
            ?>
            <div class="d-flex align-items-start gap-2 mb-3">
                <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:32px;height:32px;background:<?= $isRegression ? '#CD6A15' : 'var(--primary-color)' ?>;color:#fff;font-size:.7rem;font-weight:700;">
                    <?php
                    $names = explode(' ', $obs->user->full_name ?? '');
                    echo strtoupper(substr($names[0] ?? '', 0, 1) . substr($names[1] ?? '', 0, 1));
                    ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span style="font-size:.8rem;font-weight:600;color:#222;">
                            <?= h($obs->user->full_name ?? '') ?>
                        </span>
                        <?php if ($isRegression): ?>
                            <span class="badge bg-warning text-dark" style="font-size:.65rem;">Regresión</span>
                        <?php endif; ?>
                        <span style="font-size:.7rem;color:#aaa;">
                            <?= $obs->created ? $obs->created->format('d/m/Y H:i') : '' ?>
                        </span>
                    </div>
                    <?php if ($isRegression && $fromLbl && $toLbl): ?>
                        <div style="font-size:.74rem;color:#666;margin-top:.1rem;">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            <?= h($fromLbl) ?> &rarr; <?= h($toLbl) ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size:.84rem;color:#444;line-height:1.5;margin-top:.15rem;">
                        <?= nl2br(h($obs->message)) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 2: Asegurar que `invoice_observations` traiga `type` y `metadata` en el contain**

Buscar en `src/Controller/InvoicesController.php` el `contain` que carga las observaciones para `view`. Si lista columnas explícitas (por ejemplo `'fields'`), añadir `type` y `metadata`. Si solo hace `contain(['InvoiceObservations.Users'])` no se requiere cambio — todas las columnas vienen por defecto.

Run: `grep -n "InvoiceObservations" src/Controller/InvoicesController.php`
Esperado: identificar el `contain`. Si tiene `fields`, editarlo para incluir `type, metadata`.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l templates/Invoices/view.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Smoke test manual**

1. Iniciar `php bin/cake server`.
2. Como Admin, abrir la factura regresada en Task 10 (paso 5).
3. Ir a la vista (`/invoices/view/<id>`) y verificar que:
   - Aparece la observación con badge `[Regresión]`.
   - La línea `Tesorería → Contabilidad` está visible encima del mensaje.
   - El avatar es color naranja (`#CD6A15`).
4. Detener el servidor.

- [ ] **Step 5: Commit**

```bash
git add templates/Invoices/view.php src/Controller/InvoicesController.php
git commit -m "feat(invoices): render regression observations with badge and transition"
```

---

## Task 12: Verificación final y commit de cierre

**Files:**
- (none — solo ejecución de checks)

- [ ] **Step 1: Correr la suite completa**

Run: `composer test`
Expected: todos los tests pasan, incluyendo los nuevos `InvoicePipelineServiceTest`, `InvoicesControllerTest`, `AdvanceLegalizationServiceTest`.

- [ ] **Step 2: Correr el code style**

Run: `composer cs-check`
Expected: sin violaciones. Si hay, ejecutar `composer cs-fix` y volver a correr `cs-check`.

- [ ] **Step 3: Correr el agregado**

Run: `composer check`
Expected: equivalente a test + cs-check, todo verde.

- [ ] **Step 4: Smoke test integral en navegador (4 escenarios)**

Iniciar `php bin/cake server` y verificar:

1. **Happy path Tesorería:** factura en `tesoreria` no rechazada, no caja menor, no scheduling pagada → botón visible, modal funciona, regresión exitosa, observación con badge en la vista.
2. **Bloqueo Caja Menor:** factura con `petty_cash_record_id` no nulo → botón aparece deshabilitado con tooltip "Factura bloqueada: pertenece a un registro de Caja Menor."
3. **Sin permiso:** loguearse como Contabilidad sobre factura en `tesoreria` → botón no aparece (Contabilidad no tiene `tesoreria` en visible statuses).
4. **Sin predecesor:** loguearse como Registro/Revisión sobre factura en `aprobacion` → botón no aparece (no hay paso previo).

- [ ] **Step 5: Commit final si quedó algún ajuste de cs-fix**

```bash
git status
# Si hay cambios pendientes:
git add -A
git commit -m "style: apply cs-fix after pipeline regression feature"
```

- [ ] **Step 6: Resumen final al usuario**

Comentar al usuario:

- Migración aplicada: `invoice_observations` ahora tiene `type` y `metadata`.
- Nuevos métodos en `InvoicePipelineService`: `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`.
- Nueva acción `InvoicesController::regressStatus`.
- UI: botón en `edit.php` + modal con observación obligatoria.
- Visualización con badge en `view.php`.
- Tests: `InvoicePipelineServiceTest` (14), `InvoicesControllerTest` (4), `AdvanceLegalizationServiceTest` (+1).
- Total commits feature: 11.

---

## Riesgos y consideraciones para el implementador

- **Permisos en BD de test:** los tests del controlador asumen que existen filas en `permissions` para los roles usados. Si fallan por 403/redirect inesperado, revisar la seed de permisos en `config/Migrations/20260219000005*` o `20260429151623_SeedAdvancesPermissions.php` y replicar lo necesario en el `setUp` del test.
- **MariaDB JSON:** si la columna `metadata` falla por versión de MariaDB < 10.2.7, cambiar tipo a `text` en la migración y serializar/deserializar manualmente en el entity (`_setMetadata`/`_getMetadata` con `json_encode`/`json_decode`).
- **CSRF en modal:** la vista `edit.php` ya está dentro de un layout que aplica CSRF. El modal usa `$this->request->getAttribute('csrfToken')` — si el token resulta vacío, comprobar que la request tiene el middleware CSRF habilitado (debería estarlo por defecto en CakePHP 5).
- **Anticipos vs. Facturas:** `_redirectForInvoice` ya distingue entre `/invoices` y `/advances` según `document_type`. La acción `regressStatus` está en `InvoicesController` y se aplica a ambos (Anticipo es solo un `document_type`, no tiene controller propio para edit). Verificar que un Anticipo en `tesoreria` también muestra el botón al editarlo desde `/advances/edit/<id>`. Si hay redirección a `/advances`, podría requerir replicar la ruta o hacer que `AdvancesController` delegue.

---

## Self-Review (ejecutado al escribir este plan)

- **Cobertura del spec:** las secciones 3-11 del spec quedan cubiertas:
  - 3 (Arquitectura) → Tasks 5-7, 10.
  - 4 (Transiciones) → Tasks 2, 5.
  - 5 (Bloqueos) → Tasks 3, 6.
  - 6 (Modelo de datos) → Tasks 1, 4.
  - 7 (Contratos) → Tasks 5-7.
  - 8 (UX) → Tasks 10-11.
  - 9 (Ruta) → Task 8.
  - 10 (Permisos) → Task 8.
  - 11 (Tests) → Tasks 3, 5, 6, 7, 9, 12.
- **Placeholder scan:** todas las llamadas a métodos definidas se introducen antes de usarse. Cada step con código contiene el código completo. No hay "TBD" / "TODO".
- **Consistencia de tipos:** `regress()` retorna `array{success, error, previousStatus}` — usado idénticamente en Task 7 (test) y Task 9 (controller).
- **Ámbito:** spec acotada a un sub-sistema (regresión de pipeline de facturas); apto para un único plan.
