# Multi-Module Regression Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replicar el botón "Regresar al paso anterior" (ya implementado en facturas) en los flujos de **Anticipos**, **Caja Menor** y **Programación de Pagos**, manteniendo la misma UX (botón en `edit.php`, modal con motivo obligatorio 10–500 chars, badge `[Regresión]` en `view.php`).

**Architecture:**
- **Anticipos**: cero código nuevo. `AdvancesController::edit` ya redirige a `InvoicesController::edit`, donde el botón ya aparece. Se reduce a verificación + smoke test.
- **Caja Menor**: lógica en `PettyCashService` (no hay PipelineService dedicado). Regresión propaga `pipeline_status` a facturas hijas (simétrico al avance bulk).
- **Programación**: lógica en `PaymentSchedulingPipelineService`. Regresión "fría" — solo cambia `payment_schedulings.pipeline_status`.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MySQL/MariaDB, PHPUnit, Bootstrap 5, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-04-30-multi-module-regression-design.md`.

> **Nota de ejecución:** se omiten tests que requieren BD (consistente con el plan previo de facturas). Solo se conservan tests puramente lógicos de mapas y matrices. Verificación con `php -l` + `composer cs-check` + smoke test manual.

---

## File Structure

**New files:**
- `config/Migrations/<TS>_AddTypeAndMetadataToPettyCashObservations.php`
- `config/Migrations/<TS>_AddTypeAndMetadataToPaymentSchedulingObservations.php`
- `tests/TestCase/Service/PaymentSchedulingPipelineServiceTest.php`

**Modified files:**

*Caja Menor*
- `src/Constants/PettyCashConstants.php` — `OBSERVATION_TYPE_*`, `OBSERVATION_TYPES`, `BACKWARD_TRANSITIONS`, `ROLE_VISIBLE_STATUSES`.
- `src/Service/PettyCashService.php` — `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`.
- `src/Model/Entity/PettyCashObservation.php` — añadir `type` y `metadata` a `_accessible`.
- `src/Model/Table/PettyCashObservationsTable.php` — validación condicional.
- `src/Controller/PettyCashRecordsController.php` — `regressStatus` + variables a la vista.
- `templates/PettyCashRecords/edit.php` — botón + modal.
- `templates/PettyCashRecords/view.php` — badge + transición.

*Programación*
- `src/Constants/PaymentSchedulingConstants.php` — `OBSERVATION_TYPE_*`, `OBSERVATION_TYPES`, `BACKWARD_TRANSITIONS`.
- `src/Service/PaymentSchedulingPipelineService.php` — `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`.
- `src/Model/Entity/PaymentSchedulingObservation.php` — añadir `type` y `metadata`.
- `src/Model/Table/PaymentSchedulingObservationsTable.php` — validación condicional.
- `src/Controller/PaymentSchedulingsController.php` — `regressStatus` + variables a la vista.
- `templates/PaymentSchedulings/edit.php` — botón + modal.
- `templates/PaymentSchedulings/view.php` — badge + transición.

*Comunes*
- `config/routes.php` — dos rutas nuevas.

> Nota: `regressStatus` ya está mapeada al permiso `edit` en `AppController::_actionToPermission` (línea 68). El match es por nombre de acción y aplica a todos los controllers automáticamente. **No requiere modificación.**

---

## Task 1: Anticipos — verificación y smoke test

**Files:**
- (none — solo verificación)

> Hallazgo del brainstorming: `AdvancesController::edit()` redirige a `InvoicesController::edit()` (línea 220–223). El botón de regresión ya existe en `templates/Invoices/edit.php`. `templates/Advances/view.php` no carga `InvoiceObservations`, así que las observaciones de regresión solo se ven en `/invoices/view/{id}` (fuera de alcance de esta iteración).

- [ ] **Step 1: Confirmar que el botón aparece para anticipos**

1. Iniciar el servidor: `php bin/cake server`.
2. Loguearse como Tesorería en `localhost:8765`.
3. Crear o abrir un anticipo (`document_type='Anticipo'`) en estado `tesoreria` que NO tenga legalización iniciada.
4. Navegar a `/advances/edit/{id}` — debe redirigir automáticamente a `/invoices/edit/{id}`.
5. Confirmar que el botón "Regresar a: Contabilidad" aparece junto al de "Avanzar".
6. (Opcional) Ejecutar la regresión y verificar que la observación aparece en `/invoices/view/{id}` con badge `[Regresión]`.

- [ ] **Step 2: Verificar el bloqueo "anticipo con legalización"**

1. Abrir un anticipo en estado `tesoreria` que YA tenga legalización iniciada (`AdvanceLegalization` existe).
2. Navegar a `/advances/edit/{id}` (redirige a `/invoices/edit/{id}`).
3. Confirmar que el botón aparece **deshabilitado** con tooltip: "No se puede regresar: la legalización del anticipo ya fue iniciada."

- [ ] **Step 3: Documentar fuera de alcance**

Si en producción se requiere mostrar las observaciones de regresión en `templates/Advances/view.php` o en `/advances/legalization/{id}`, abrir un issue o tarea futura. **Esta iteración no toca esos templates.**

- [ ] **Step 4: No hay commit aquí**

Solo verificación. Pasar a la Task 2.

---

## Task 2: Migración — extender `petty_cash_observations`

**Files:**
- Create: `config/Migrations/<TS>_AddTypeAndMetadataToPettyCashObservations.php`

- [ ] **Step 1: Generar el archivo de migración**

```bash
php bin/cake migrations create AddTypeAndMetadataToPettyCashObservations
```

Expected: archivo creado en `config/Migrations/<TS>_AddTypeAndMetadataToPettyCashObservations.php`.

- [ ] **Step 2: Sustituir el contenido del archivo**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToPettyCashObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('petty_cash_observations');

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

        $indexTable = $this->table('petty_cash_observations');
        if (!$indexTable->hasIndex(['petty_cash_record_id', 'type'])) {
            $indexTable->addIndex(['petty_cash_record_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('petty_cash_observations');
        if ($table->hasIndex(['petty_cash_record_id', 'type'])) {
            $table->removeIndex(['petty_cash_record_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
```

- [ ] **Step 3: Aplicar la migración**

Run: `php bin/cake migrations migrate`
Expected: `<TS> AddTypeAndMetadataToPettyCashObservations: migrated`.

- [ ] **Step 4: Commit**

```bash
git add config/Migrations/
git commit -m "feat(petty-cash): migrate petty_cash_observations with type and metadata columns"
```

---

## Task 3: Constantes en `PettyCashConstants` y `BACKWARD_TRANSITIONS`

**Files:**
- Modify: `src/Constants/PettyCashConstants.php`

- [ ] **Step 1: Añadir constantes**

Editar `src/Constants/PettyCashConstants.php`. Antes del `}` final añadir:

```php

    // Backward transitions for the regress operation.
    // Excluido `pagado` por riesgo de inconsistencia con datos colaterales.
    public const BACKWARD_TRANSITIONS = [
        self::STATUS_AGRUPACION   => null,
        self::STATUS_CONTABILIDAD => self::STATUS_AGRUPACION,
        self::STATUS_TESORERIA    => self::STATUS_CONTABILIDAD,
        self::STATUS_AUT_PAGO     => self::STATUS_TESORERIA,
        self::STATUS_PAGADO       => null,
    ];

    // Roles autorizados para regresar desde cada estado (matriz simétrica al avance).
    public const REGRESS_ROLE_BY_STATUS = [
        self::STATUS_CONTABILIDAD => ['Contabilidad', 'Administrador'],
        self::STATUS_TESORERIA    => ['Tesorería', 'Administrador'],
        self::STATUS_AUT_PAGO     => ['Contador', 'Administrador'],
    ];

    // Tipos de observación (petty_cash_observations.type)
    public const OBSERVATION_TYPE_GENERAL = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];
```

> **Nota sobre nombres de roles:** verificar con `RoleConstants::*` los valores exactos. Si los nombres difieren (ej: 'CONTABILIDAD' vs 'Contabilidad'), ajustar a los valores reales.

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l src/Constants/PettyCashConstants.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Constants/PettyCashConstants.php
git commit -m "feat(petty-cash): add observation types and backward transitions constants"
```

---

## Task 4: Entity y Table de `PettyCashObservation`

**Files:**
- Modify: `src/Model/Entity/PettyCashObservation.php`
- Modify: `src/Model/Table/PettyCashObservationsTable.php`

- [ ] **Step 1: Actualizar `_accessible` del entity**

Reemplazar el contenido completo de `src/Model/Entity/PettyCashObservation.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PettyCashObservation extends Entity
{
    protected array $_accessible = [
        'petty_cash_record_id' => true,
        'user_id' => true,
        'message' => true,
        'type' => true,
        'metadata' => true,
    ];
}
```

- [ ] **Step 2: Actualizar `validationDefault`**

Editar `src/Model/Table/PettyCashObservationsTable.php`. Reemplazar el método `validationDefault` por:

```php
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('petty_cash_record_id')
            ->requirePresence('petty_cash_record_id', 'create')
            ->notEmptyString('petty_cash_record_id');

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
                    $type = $context['data']['type'] ?? \App\Constants\PettyCashConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PettyCashConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen(trim($value)) >= 10;
                },
                'message' => 'El motivo de la regresión debe tener al menos 10 caracteres.',
            ])
            ->add('message', 'maxLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\PettyCashConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PettyCashConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen($value) <= 500;
                },
                'message' => 'El motivo de la regresión no puede superar 500 caracteres.',
            ]);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->inList('type', \App\Constants\PettyCashConstants::OBSERVATION_TYPES, 'Tipo de observación inválido.')
            ->allowEmptyString('type');

        return $validator;
    }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Model/Entity/PettyCashObservation.php && php -l src/Model/Table/PettyCashObservationsTable.php`
Expected: ambos `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Model/Entity/PettyCashObservation.php src/Model/Table/PettyCashObservationsTable.php
git commit -m "feat(petty-cash): allow type/metadata on PettyCashObservation with conditional validation"
```

---

## Task 5: `PettyCashService` — métodos de regresión

**Files:**
- Modify: `src/Service/PettyCashService.php`

> Tests omitidos (todos los métodos consultan BD: `getRegressionLockMessage` lee campos del record, `regress` persiste cambios). Smoke test manual en Task 8.

- [ ] **Step 1: Añadir uses al inicio del archivo**

Editar `src/Service/PettyCashService.php`. Localizar el bloque `use ...` (líneas 6–10). Añadir:

```php
use App\Constants\RoleConstants;
```

(Si ya está, omitir.)

- [ ] **Step 2: Implementar los métodos**

Añadir antes del cierre de la clase:

```php
    /**
     * Returns the previous pipeline status for the given current status,
     * or null if no predecessor exists or the state is excluded from regression.
     */
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PettyCashConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    /**
     * Returns true if the role can regress the record from the current status.
     */
    public function canRegress(string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        $allowed = PettyCashConstants::REGRESS_ROLE_BY_STATUS[$currentStatus] ?? [];

        return in_array($roleName, $allowed, true);
    }

    /**
     * Returns a human-readable lock message preventing regression, or null if allowed.
     */
    public function getRegressionLockMessage(PettyCashRecord $record): ?string
    {
        // Solo bloqueo: tesoreria → contabilidad con pago pendiente registrado.
        if ($record->status === PettyCashConstants::STATUS_TESORERIA
            && !empty($record->payment_amount)
        ) {
            return 'No se puede regresar a Contabilidad: existe un pago pendiente registrado. Anule o reasigne el pago primero.';
        }

        return null;
    }

    /**
     * Regress the record to its previous pipeline status.
     * Propagates the change to child invoices (bulk update of pipeline_status)
     * and stores the reason as a typed observation.
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        PettyCashRecord $record,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $record->status;

        if (!$this->canRegress($roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Este registro ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar este registro.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($record);
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
        $recordsTable = TableRegistry::getTableLocator()->get('PettyCashRecords');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $observationsTable = TableRegistry::getTableLocator()->get('PettyCashObservations');
        $fkField = $this->grouped->getFkField();

        // Map child invoice status to align with parent regression target.
        // agrupacion: hijas no tienen pipeline alineado todavía → no se tocan.
        // contabilidad/tesoreria: alinear hijas al mismo estado.
        // aut_pago no aplica (no es regresable a un estado donde se propaguen hijas).
        $childPipelineMap = [
            PettyCashConstants::STATUS_CONTABILIDAD => InvoiceConstants::STATUS_CONTABILIDAD,
            PettyCashConstants::STATUS_TESORERIA    => InvoiceConstants::STATUS_TESORERIA,
        ];

        $ok = $invoicesTable->getConnection()->transactional(
            function () use (
                $recordsTable,
                $invoicesTable,
                $observationsTable,
                $record,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason,
                $fkField,
                $childPipelineMap
            ): bool {
                $record->status = $previousStatus;
                if (!$recordsTable->save($record)) {
                    return false;
                }

                // Propagar a facturas hijas si aplica.
                if (isset($childPipelineMap[$previousStatus])) {
                    $newPipelineStatus = $childPipelineMap[$previousStatus];
                    $invoicesBefore = $invoicesTable->find()
                        ->select(['id', 'pipeline_status'])
                        ->where([$fkField => $record->id])
                        ->all()
                        ->toArray();

                    $invoicesTable->updateAll(
                        ['pipeline_status' => $newPipelineStatus],
                        [$fkField => $record->id],
                    );

                    $this->grouped->recordBulkHistory(
                        $record->id,
                        $invoicesBefore,
                        $newPipelineStatus,
                        $userId,
                    );
                }

                $observation = $observationsTable->newEntity([
                    'petty_cash_record_id' => $record->id,
                    'user_id' => $userId,
                    'type' => PettyCashConstants::OBSERVATION_TYPE_REGRESSION,
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
                'error' => 'No se pudo regresar el registro. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }
```

> **Nota:** `aut_pago → tesoreria` NO toca facturas hijas porque al avanzar de tesoreria → aut_pago tampoco se cambió `pipeline_status` (las hijas ya están en `tesoreria`). Solo se cambia el `status` del record. Si la verificación al implementar muestra otra cosa, ajustar el `$childPipelineMap`.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l src/Service/PettyCashService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Service/PettyCashService.php
git commit -m "feat(petty-cash): add regression methods to PettyCashService with bulk propagation"
```

---

## Task 6: Controller, ruta y variables a la vista

**Files:**
- Modify: `src/Controller/PettyCashRecordsController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Añadir `regressStatus` al controller**

Editar `src/Controller/PettyCashRecordsController.php`. Localizar `public function advanceStatus($id = null)` (línea 270). Inmediatamente DESPUÉS de su cierre (después de la `}`, antes de `registerPayment`), añadir:

```php
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);
        $user = $this->_getCurrentUser();
        $roleName = $this->_getUserRoleName($user);
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pettyCashService->regress(
            $record,
            $roleName,
            (int)$user->id,
            $reason,
        );

        if ($result['success']) {
            $prevLabel = PettyCashConstants::STATUS_LABELS[$result['previousStatus']]
                ?? $result['previousStatus'];
            $this->Flash->success(sprintf('Registro regresado a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 2: Pasar variables a la vista de edición**

Editar el método `edit()` del mismo controller. Localizar el bloque `compact(...)` final (líneas 254–267). Antes del `compact(...)`, añadir el cálculo de regresión:

```php
        $canRegress = $this->pettyCashService->canRegress($roleName, $record->status);
        $previousStatus = $this->pettyCashService->getPreviousStatus($record->status);
        $regressLockMessage = $this->pettyCashService->getRegressionLockMessage($record);
        $pipelineLabels = PettyCashConstants::STATUS_LABELS;
        $currentStatus = $record->status;
```

Y añadir al `compact(...)` (después de `'syntheticPayments'`):

```php
            'canRegress',
            'previousStatus',
            'regressLockMessage',
            'pipelineLabels',
            'currentStatus',
```

- [ ] **Step 3: Añadir la ruta**

Editar `config/routes.php`. Localizar la ruta `/petty-cash-records/advance-status/{id}` (línea 286). Inmediatamente DESPUÉS de ella, añadir:

```php
        $builder->connect(
            '/petty-cash-records/regress-status/{id}',
            ['controller' => 'PettyCashRecords', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 4: Verificar sintaxis y ruta**

Run: `php -l src/Controller/PettyCashRecordsController.php && php -l config/routes.php`
Expected: ambos `No syntax errors detected`.

Run: `php bin/cake routes | grep "petty-cash-records/regress"`
Expected: una línea con `regress-status` apuntando a `PettyCashRecords::regressStatus`.

> **Nota sobre permisos:** `regressStatus` ya está en el match del grupo `'edit'` en `AppController::_actionToPermission` (línea 68). El match es por nombre de acción, no por controller — aplica automáticamente.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/PettyCashRecordsController.php config/routes.php
git commit -m "feat(petty-cash): add regressStatus action and route"
```

---

## Task 7: Templates `edit.php` y `view.php` de Caja Menor

**Files:**
- Modify: `templates/PettyCashRecords/edit.php`
- Modify: `templates/PettyCashRecords/view.php`

- [ ] **Step 1: Identificar la barra de acciones del `edit.php`**

Leer `templates/PettyCashRecords/edit.php` y localizar el bloque de botones de acción (sticky), similar al de Invoices. Identificar la línea del botón "Avanzar".

- [ ] **Step 2: Añadir el botón "Regresar" junto al de "Avanzar"**

En el bloque de botones (al lado del botón "Avanzar"), añadir:

```php
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
```

- [ ] **Step 3: Añadir el modal al final del template**

Al final de `templates/PettyCashRecords/edit.php`, añadir:

```php
<?php if (!empty($canRegress) && empty($regressLockMessage)):
    $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
    $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
?>
<!-- Modal: Regresar al paso anterior -->
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= $this->Url->build(['action' => 'regressStatus', $record->id]) ?>"
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
                        Este registro volverá del paso
                        <strong><?= h($currLabel) ?></strong>
                        al paso
                        <strong><?= h($prevLabel) ?></strong>.
                        Las facturas vinculadas también se regresarán al estado correspondiente.
                    </p>
                    <div class="mb-2">
                        <label for="regressReason" class="form-label">
                            Motivo de la regresión <span class="text-danger">*</span>
                        </label>
                        <textarea name="reason" id="regressReason"
                                  class="form-control" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Describa por qué está regresando este registro..."></textarea>
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

- [ ] **Step 4: Render de observaciones de regresión en `view.php`**

Editar `templates/PettyCashRecords/view.php`. Localizar el bloque que itera sobre `$record->petty_cash_observations`. Reemplazar el bloque de cada item por una versión que detecte `type='regression'` (siguiendo el patrón usado en `templates/Invoices/view.php`):

```php
            <?php
                $statusLabels = \App\Constants\PettyCashConstants::STATUS_LABELS;
            ?>
            <?php foreach ($record->petty_cash_observations as $obs): ?>
            <?php
                $isRegression = ($obs->type ?? null) === \App\Constants\PettyCashConstants::OBSERVATION_TYPE_REGRESSION;
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
```

> Verificar que el `view()` action ya carga `petty_cash_observations` en su contain. Si no, añadirlo.

- [ ] **Step 5: Verificar sintaxis y smoke test**

Run: `php -l templates/PettyCashRecords/edit.php && php -l templates/PettyCashRecords/view.php`
Expected: ambos `No syntax errors detected`.

Smoke test:
1. `php bin/cake server`.
2. Como Tesorería, abrir un record en `tesoreria` SIN pago pendiente (`payment_amount IS NULL`).
3. Ir a `edit` → ver botón "Regresar a: Contabilidad".
4. Confirmar el regreso. Verificar:
   - Flash success.
   - Record vuelve a `contabilidad`.
   - Facturas hijas también pasan a `contabilidad`.
   - En `view`, observación con badge `[Regresión]` y línea `Tesorería → Contabilidad`.
5. Repetir con un record con `payment_amount` lleno → botón aparece deshabilitado con tooltip.

- [ ] **Step 6: Commit**

```bash
git add templates/PettyCashRecords/edit.php templates/PettyCashRecords/view.php
git commit -m "feat(petty-cash): add regression button, modal and observation badge to templates"
```

---

## Task 8: Migración — extender `payment_scheduling_observations`

**Files:**
- Create: `config/Migrations/<TS>_AddTypeAndMetadataToPaymentSchedulingObservations.php`

- [ ] **Step 1: Generar el archivo**

```bash
php bin/cake migrations create AddTypeAndMetadataToPaymentSchedulingObservations
```

- [ ] **Step 2: Sustituir el contenido**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTypeAndMetadataToPaymentSchedulingObservations extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('payment_scheduling_observations');

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

        $indexTable = $this->table('payment_scheduling_observations');
        if (!$indexTable->hasIndex(['payment_scheduling_id', 'type'])) {
            $indexTable->addIndex(['payment_scheduling_id', 'type'])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('payment_scheduling_observations');
        if ($table->hasIndex(['payment_scheduling_id', 'type'])) {
            $table->removeIndex(['payment_scheduling_id', 'type'])->update();
        }
        $table->removeColumn('metadata')->removeColumn('type')->update();
    }
}
```

- [ ] **Step 3: Aplicar migración + commit**

```bash
php bin/cake migrations migrate
git add config/Migrations/
git commit -m "feat(payment-scheduling): migrate payment_scheduling_observations with type and metadata"
```

---

## Task 9: Constantes en `PaymentSchedulingConstants`

**Files:**
- Modify: `src/Constants/PaymentSchedulingConstants.php`

- [ ] **Step 1: Añadir constantes**

Editar el archivo. Antes del `}` final añadir:

```php

    // Backward transitions for the regress operation.
    // Excluida `pagada` por irreversibilidad de invoice_payments creados.
    public const BACKWARD_TRANSITIONS = [
        self::STATUS_BORRADOR  => null,
        self::STATUS_TESORERIA => self::STATUS_BORRADOR,
        self::STATUS_AUT_PAGO  => self::STATUS_TESORERIA,
        self::STATUS_PAGADA    => null,
    ];

    // Tipos de observación
    public const OBSERVATION_TYPE_GENERAL = 'general';
    public const OBSERVATION_TYPE_REGRESSION = 'regression';

    public const OBSERVATION_TYPES = [
        self::OBSERVATION_TYPE_GENERAL,
        self::OBSERVATION_TYPE_REGRESSION,
    ];
```

- [ ] **Step 2: Verificar sintaxis y commit**

Run: `php -l src/Constants/PaymentSchedulingConstants.php`

```bash
git add src/Constants/PaymentSchedulingConstants.php
git commit -m "feat(payment-scheduling): add observation types and backward transitions constants"
```

---

## Task 10: Entity y Table de `PaymentSchedulingObservation`

**Files:**
- Modify: `src/Model/Entity/PaymentSchedulingObservation.php`
- Modify: `src/Model/Table/PaymentSchedulingObservationsTable.php`

- [ ] **Step 1: Actualizar `_accessible`**

Reemplazar el contenido de `src/Model/Entity/PaymentSchedulingObservation.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingObservation extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'user_id' => true,
        'message' => true,
        'type' => true,
        'metadata' => true,
    ];
}
```

- [ ] **Step 2: Actualizar `validationDefault`**

Reemplazar el método en `src/Model/Table/PaymentSchedulingObservationsTable.php`:

```php
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message')
            ->add('message', 'minLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen(trim($value)) >= 10;
                },
                'message' => 'El motivo de la regresión debe tener al menos 10 caracteres.',
            ])
            ->add('message', 'maxLengthRegression', [
                'rule' => function ($value, $context) {
                    $type = $context['data']['type'] ?? \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_GENERAL;
                    if ($type !== \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION) {
                        return true;
                    }

                    return is_string($value) && mb_strlen($value) <= 500;
                },
                'message' => 'El motivo de la regresión no puede superar 500 caracteres.',
            ]);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->inList('type', \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPES, 'Tipo de observación inválido.')
            ->allowEmptyString('type');

        return $validator;
    }
```

- [ ] **Step 3: Verificar sintaxis y commit**

Run: `php -l src/Model/Entity/PaymentSchedulingObservation.php && php -l src/Model/Table/PaymentSchedulingObservationsTable.php`

```bash
git add src/Model/Entity/PaymentSchedulingObservation.php src/Model/Table/PaymentSchedulingObservationsTable.php
git commit -m "feat(payment-scheduling): allow type/metadata on PaymentSchedulingObservation"
```

---

## Task 11: `PaymentSchedulingPipelineService` — métodos de regresión (TDD)

**Files:**
- Modify: `src/Service/PaymentSchedulingPipelineService.php`
- Create: `tests/TestCase/Service/PaymentSchedulingPipelineServiceTest.php`

- [ ] **Step 1: Crear el archivo de test**

Crear `tests/TestCase/Service/PaymentSchedulingPipelineServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;
use App\Service\PaymentSchedulingPipelineService;
use Cake\TestSuite\TestCase;

class PaymentSchedulingPipelineServiceTest extends TestCase
{
    private PaymentSchedulingPipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentSchedulingPipelineService();
    }

    public function testGetPreviousStatusReturnsExpectedMap(): void
    {
        $this->assertNull($this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_BORRADOR));
        $this->assertSame(
            PaymentSchedulingConstants::STATUS_BORRADOR,
            $this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_TESORERIA)
        );
        $this->assertSame(
            PaymentSchedulingConstants::STATUS_TESORERIA,
            $this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_AUT_PAGO)
        );
        $this->assertNull($this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_PAGADA));
    }

    public function testCanRegressTrueForVisibleStateWithPredecessor(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTADOR, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }

    public function testCanRegressFalseFromBorrador(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_BORRADOR));
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_BORRADOR));
    }

    public function testCanRegressFalseFromPagada(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_PAGADA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_PAGADA));
    }

    public function testCanRegressFalseWhenStateNotVisibleForRole(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTADOR, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }

    public function testCanRegressTrueForAdminFromRegressableStates(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `composer test -- --filter PaymentSchedulingPipelineServiceTest`
Expected: errores `Call to undefined method getPreviousStatus()` y `canRegress()`.

- [ ] **Step 3: Implementar `getPreviousStatus`, `canRegress`, `getRegressionLockMessage`, `regress`**

Editar `src/Service/PaymentSchedulingPipelineService.php`. Añadir uses al inicio:

```php
use App\Model\Entity\PaymentScheduling;
```

Luego añadir antes del cierre de la clase:

```php
    public function getPreviousStatus(string $currentStatus): ?string
    {
        return PaymentSchedulingConstants::BACKWARD_TRANSITIONS[$currentStatus] ?? null;
    }

    public function canRegress(string $roleName, string $currentStatus): bool
    {
        if ($this->getPreviousStatus($currentStatus) === null) {
            return false;
        }

        if ($roleName === RoleConstants::ADMIN) {
            return true;
        }

        // Tesorería regresa desde tesoreria; Contador regresa desde aut_pago.
        if ($roleName === RoleConstants::TESORERIA
            && $currentStatus === PaymentSchedulingConstants::STATUS_TESORERIA
        ) {
            return true;
        }

        if ($roleName === RoleConstants::CONTADOR
            && $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO
        ) {
            return true;
        }

        return false;
    }

    /**
     * Sin bloqueos automáticos en esta iteración. Presente por simetría.
     */
    public function getRegressionLockMessage(object $scheduling): ?string
    {
        return null;
    }

    /**
     * Cold regression — only changes pipeline_status, doesn't touch items or payments.
     *
     * @return array{success: bool, error: ?string, previousStatus: ?string}
     */
    public function regress(
        PaymentScheduling $scheduling,
        string $roleName,
        int $userId,
        string $reason,
    ): array {
        $reason = trim($reason);
        $currentStatus = $scheduling->pipeline_status;

        if (!$this->canRegress($roleName, $currentStatus)) {
            $previous = $this->getPreviousStatus($currentStatus);
            $error = $previous === null
                ? 'Esta programación ya está en el primer paso del flujo.'
                : 'No tiene permisos para regresar esta programación.';

            return ['success' => false, 'error' => $error, 'previousStatus' => null];
        }

        $lock = $this->getRegressionLockMessage($scheduling);
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
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');
        $observationsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingObservations');

        $ok = $schedulingsTable->getConnection()->transactional(
            function () use (
                $schedulingsTable,
                $observationsTable,
                $scheduling,
                $previousStatus,
                $currentStatus,
                $userId,
                $reason
            ): bool {
                $scheduling->pipeline_status = $previousStatus;
                if (!$schedulingsTable->save($scheduling)) {
                    return false;
                }

                $observation = $observationsTable->newEntity([
                    'payment_scheduling_id' => $scheduling->id,
                    'user_id' => $userId,
                    'type' => PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION,
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
                'error' => 'No se pudo regresar la programación. Intente de nuevo.',
                'previousStatus' => null,
            ];
        }

        return ['success' => true, 'error' => null, 'previousStatus' => $previousStatus];
    }
```

- [ ] **Step 4: Correr el test (debe pasar)**

Run: `composer test -- --filter PaymentSchedulingPipelineServiceTest`
Expected: 6 tests OK.

- [ ] **Step 5: Commit**

```bash
git add src/Service/PaymentSchedulingPipelineService.php tests/TestCase/Service/PaymentSchedulingPipelineServiceTest.php
git commit -m "feat(payment-scheduling): add regression methods to pipeline service with tests"
```

---

## Task 12: Controller, ruta y variables a la vista de Programación

**Files:**
- Modify: `src/Controller/PaymentSchedulingsController.php`
- Modify: `config/routes.php`

- [ ] **Step 1: Añadir `regressStatus` al controller**

Editar `src/Controller/PaymentSchedulingsController.php`. Localizar el método `reject($id = null)` (línea 213). Inmediatamente DESPUÉS de su cierre, añadir:

```php
    public function regressStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $user = $this->_getCurrentUser();
        $roleName = $this->_getRoleName();
        $reason = trim((string)$this->request->getData('reason', ''));

        $result = $this->pipeline->regress(
            $record,
            $roleName,
            (int)$user->id,
            $reason,
        );

        if ($result['success']) {
            $prevLabel = PaymentSchedulingConstants::STATUS_LABELS[$result['previousStatus']]
                ?? $result['previousStatus'];
            $this->Flash->success(sprintf('Programación regresada a: %s', $prevLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }
```

- [ ] **Step 2: Pasar variables a la vista**

Editar el método `edit()` (línea 108). Localizar el bloque del cálculo (líneas 126–139). DESPUÉS de la línea con `$bankingEntities = ...`, añadir:

```php
        $canRegress = $this->pipeline->canRegress($roleName, $currentStatus);
        $previousStatus = $this->pipeline->getPreviousStatus($currentStatus);
        $regressLockMessage = $this->pipeline->getRegressionLockMessage($record);
```

Añadir al `compact(...)`:

```php
            'canRegress',
            'previousStatus',
            'regressLockMessage',
```

- [ ] **Step 3: Añadir la ruta**

Editar `config/routes.php`. Localizar la sección de rutas de `payment-schedulings` (buscar `payment-schedulings`). Antes del bloque `fallbacks()` o junto a otras rutas custom de payment-schedulings, añadir:

```php
        $builder->connect(
            '/payment-schedulings/regress-status/{id}',
            ['controller' => 'PaymentSchedulings', 'action' => 'regressStatus'],
            ['id' => '\d+', 'pass' => ['id']],
        );
```

- [ ] **Step 4: Verificar sintaxis y ruta**

Run: `php -l src/Controller/PaymentSchedulingsController.php && php -l config/routes.php`
Run: `php bin/cake routes | grep "payment-schedulings/regress"`

- [ ] **Step 5: Commit**

```bash
git add src/Controller/PaymentSchedulingsController.php config/routes.php
git commit -m "feat(payment-scheduling): add regressStatus action and route"
```

---

## Task 13: Templates `edit.php` y `view.php` de Programación

**Files:**
- Modify: `templates/PaymentSchedulings/edit.php`
- Modify: `templates/PaymentSchedulings/view.php`

- [ ] **Step 1: Añadir botón "Regresar" en `edit.php`**

Editar `templates/PaymentSchedulings/edit.php`. Localizar el bloque de botones de acción (junto a "Avanzar" y al posible botón "Rechazar"). Añadir el botón:

```php
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
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
```

- [ ] **Step 2: Añadir el modal al final del template**

```php
<?php if (!empty($canRegress) && empty($regressLockMessage)):
    $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
    $currLabel = $pipelineLabels[$currentStatus] ?? $currentStatus;
?>
<!-- Modal: Regresar al paso anterior -->
<div class="modal fade" id="regressStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post"
              action="<?= $this->Url->build(['action' => 'regressStatus', $record->id]) ?>"
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
                        Esta programación volverá del paso
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
                                  placeholder="Describa por qué está regresando esta programación..."></textarea>
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

> Si la variable `$currentStatus` no se está pasando hoy a `edit.php`, añadirla en el controller (Task 12 Step 2 ya pasa `$currentStatus` indirectamente porque ya estaba en el compact original).

- [ ] **Step 3: Render de observaciones en `view.php`**

Editar `templates/PaymentSchedulings/view.php`. Localizar el bloque de iteración sobre `$record->payment_scheduling_observations`. Reemplazar el render por:

```php
            <?php
                $statusLabels = \App\Constants\PaymentSchedulingConstants::STATUS_LABELS;
            ?>
            <?php foreach ($record->payment_scheduling_observations as $obs): ?>
            <?php
                $isRegression = ($obs->type ?? null) === \App\Constants\PaymentSchedulingConstants::OBSERVATION_TYPE_REGRESSION;
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
```

> Verificar que `view()` action ya carga `payment_scheduling_observations` en su contain. Si no, añadirlo.

- [ ] **Step 4: Verificar sintaxis y smoke test**

Run: `php -l templates/PaymentSchedulings/edit.php && php -l templates/PaymentSchedulings/view.php`

Smoke test:
1. `php bin/cake server`.
2. Como Tesorería, abrir una programación en `tesoreria`.
3. `edit` → ver botón "Regresar a: Borrador". Confirmar regresión exitosa.
4. Verificar en `view`: badge `[Regresión]` + `Tesorería → Borrador`.
5. Como Contador, abrir una programación en `aut_pago`. El botón "Rechazar" del Contador debe seguir visible (acción primaria). El nuevo botón "Regresar a: Tesorería" aparece como secundario.
6. Como Tesorería, abrir una programación en `aut_pago` → botón NO aparece (rol no autorizado).
7. Como Tesorería, abrir una programación en `borrador` → botón NO aparece (sin predecesor).

- [ ] **Step 5: Commit**

```bash
git add templates/PaymentSchedulings/edit.php templates/PaymentSchedulings/view.php
git commit -m "feat(payment-scheduling): add regression button, modal and observation badge to templates"
```

---

## Task 14: Verificación final

**Files:**
- (none — solo ejecución de checks)

- [ ] **Step 1: Correr los tests puros**

Run: `composer test -- --filter PaymentSchedulingPipelineServiceTest`
Expected: 6 tests OK.

Run: `composer test -- --filter InvoicePipelineServiceTest`
Expected: 5 tests OK (no debe haber regresiones del trabajo anterior).

- [ ] **Step 2: Code style**

Run: `composer cs-check`
Expected: sin violaciones. Si hay, ejecutar `composer cs-fix` y volver a correr `cs-check`.

- [ ] **Step 3: Smoke tests integrales**

Iniciar `php bin/cake server` y verificar 4 escenarios por módulo:

**Anticipos:**
1. Anticipo en `tesoreria` sin legalización → botón visible, regresión funciona, observación aparece en `/invoices/view/{id}` (no en `/advances/view`).
2. Anticipo con legalización iniciada → botón deshabilitado con tooltip.

**Caja Menor:**
1. Record en `tesoreria` sin pago pendiente → botón visible, regresión + propagación a hijas + observación con badge.
2. Record en `tesoreria` con `payment_amount` lleno → botón deshabilitado.
3. Record en `agrupacion` → botón no aparece.
4. Loguearse como Contabilidad sobre record en `tesoreria` → botón no aparece (rol no visible).

**Programación:**
1. Programación en `tesoreria` (Tesorería) → botón visible, regresa a `borrador` correctamente.
2. Programación en `aut_pago` (Contador) → botón visible junto a "Rechazar", regresa a `tesoreria`.
3. Programación en `borrador` → botón no aparece.
4. Programación en `pagada` → botón no aparece.

- [ ] **Step 4: Commit final si quedó algo de cs-fix**

```bash
git status
# Si hay cambios:
git add -A
git commit -m "style: apply cs-fix after multi-module regression feature"
```

- [ ] **Step 5: Resumen al usuario**

- Anticipos: cero código nuevo, botón ya existía vía redirect a Invoices.
- Caja Menor: regresión con propagación bulk a facturas hijas, bloqueo por pago pendiente, badge en `view`.
- Programación: regresión "fría", coexiste con `canReject` del Contador, badge en `view`.
- Tests: `PaymentSchedulingPipelineServiceTest` (6 puros, sin BD).
- Total commits feature: ~13.

---

## Riesgos y consideraciones para el implementador

- **Anticipos en `autorizacion_pago` (refund flow):** verificar al implementar que un anticipo en estado `autorizacion_pago` (caso sobrante) no rompe al regresarse a `tesoreria`. Si rompe, restringir el botón vía template (no mostrar si `pipeline_status === 'autorizacion_pago'` y `document_type === 'Anticipo'`). Documentar en la verificación de Task 1.
- **Nombres de roles en `PettyCashConstants::REGRESS_ROLE_BY_STATUS`:** se usaron strings literales ('Contabilidad', 'Tesorería', etc.). Si los valores reales en `RoleConstants` difieren (ej: vienen de la BD con tildes/sin tildes), ajustar a las constantes correspondientes.
- **`recordBulkHistory` firma:** confirmé en `GroupedInvoiceService` línea 222–236. La firma esperada es `(int $recordId, array $invoicesBefore, string $newPipelineStatus, int $userId): void` y eso es lo que el código de Task 5 invoca.
- **`PaymentSchedulings::view()` contain:** verificar que carga `PaymentSchedulingObservations` con `type` y `metadata`. Si no, añadir al contain.
- **`PettyCashRecords::view()` contain:** mismo punto. El edit ya lo carga (línea 127–130).
- **MariaDB JSON support:** ya validado en producción al implementar facturas.
- **CSRF en modales:** los templates usan `$this->request->getAttribute('csrfToken')` — patrón ya validado en facturas.

---

## Self-Review

- **Cobertura del spec:** secciones 3-11 cubiertas:
  - 3.1 Anticipos → Task 1.
  - 3.2 Caja Menor → Tasks 2-7.
  - 3.3 Programación → Tasks 8-13.
  - 4 Transiciones → Tasks 3, 9.
  - 5 Bloqueos → Task 5 (Caja Menor only).
  - 6 Modelo de datos → Tasks 2, 4, 8, 10.
  - 7 Contratos → Tasks 5, 11.
  - 8 UX → Tasks 7, 13.
  - 9 Rutas → Tasks 6, 12.
  - 10 Permisos → ya cubierto por mapping existente en AppController.
  - 11 Tests → Task 11 (puros) + Task 14 (smoke).
- **Placeholder scan:** todas las llamadas a métodos definidas se introducen antes de usarse. Cada step con código contiene el código completo.
- **Consistencia de tipos:** `regress()` retorna `array{success, error, previousStatus}` — usado idénticamente en service y controllers de ambos módulos.
- **Ámbito:** plan único integral con tres áreas, alineado con la decisión X del brainstorming.
- **No hay tests del controlador:** consistente con la decisión de omitir tests con BD del plan previo.
