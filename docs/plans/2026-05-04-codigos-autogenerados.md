# Códigos autogenerados con centro de operación — Plan de implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Unificar el formato de códigos en caja menor, reintegros, pago programado y anticipos al patrón `{PREFIX}-{YY}-{CCC}-{NNNN}`, con consecutivo único por (módulo, año, centro de operación) y código inmutable tras crear.

**Architecture:** Servicio centralizado `CodeGeneratorService` invocado desde `beforeSave` de cada tabla afectada. Migración consolidada que añade `operation_center_id` (nullable, para no romper legados) a `petty_cash_records`, `refunds` y `payment_schedulings`. Anticipos (facturas con `document_type=ANTICIPO`) reciben el código en `invoice_number`. Centro de operación obligatorio al crear, inmutable después.

**Tech Stack:** PHP 8.2 / CakePHP 5.3 / MySQL — migraciones con `Migrations\BaseMigration`, validación con `requirePresence(field, 'create')`, lógica en `Table::beforeSave`.

**Política de testing:** Este proyecto **no usa tests automatizados** (ver `CLAUDE.md`). Cada tarea termina con un bloque de validación manual concreto (navegador o `curl`).

**Diseño base:** `docs/plans/2026-05-04-codigos-autogenerados-design.md`

---

## Tarea 0: Preparación

**Step 1: Verificar rama limpia**

```bash
git status
```

Esperado: working tree clean. Si hay cambios, commitear o stashear antes de empezar.

**Step 2: Verificar migración base**

```bash
php bin/cake migrations status
```

Esperado: todas las migraciones existentes en estado `up`. No iniciar si hay alguna pendiente.

**Step 3: Backup de BD local**

```bash
mysqldump --opt sgi_db > /tmp/sgi_db_pre_codes.sql
```

(Reemplazar credenciales según `.env`. En Windows usar el path adecuado.)

---

## Tarea 1: Migración consolidada

**Files:**
- Create: `config/Migrations/20260504XXXXXX_AddOperationCenterToCodeGeneratedModules.php`

**Step 1: Generar esqueleto de migración**

```bash
php bin/cake migrations create AddOperationCenterToCodeGeneratedModules
```

Esto crea el archivo con timestamp actual y `extends BaseMigration`.

**Step 2: Reemplazar contenido del archivo**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddOperationCenterToCodeGeneratedModules extends BaseMigration
{
    public function up(): void
    {
        // petty_cash_records.operation_center_id
        if ($this->hasTable('petty_cash_records')) {
            $t = $this->table('petty_cash_records');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
        }

        // refunds.operation_center_id
        if ($this->hasTable('refunds')) {
            $t = $this->table('refunds');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
        }

        // payment_schedulings.operation_center_id + ampliar code a VARCHAR(30)
        if ($this->hasTable('payment_schedulings')) {
            $t = $this->table('payment_schedulings');
            if (!$t->hasColumn('operation_center_id')) {
                $t->addColumn('operation_center_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => true,
                ])
                ->addIndex(['operation_center_id'])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
            }
            $t->changeColumn('code', 'string', [
                'limit' => 30,
                'null' => false,
            ])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('payment_schedulings')) {
            $t = $this->table('payment_schedulings');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
            $t->changeColumn('code', 'string', [
                'limit' => 20,
                'null' => false,
            ])->update();
        }

        if ($this->hasTable('refunds')) {
            $t = $this->table('refunds');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
        }

        if ($this->hasTable('petty_cash_records')) {
            $t = $this->table('petty_cash_records');
            if ($t->hasColumn('operation_center_id')) {
                $t->dropForeignKey('operation_center_id')->update();
                $t->removeColumn('operation_center_id')->update();
            }
        }
    }
}
```

**Step 3: Ejecutar migración**

```bash
php bin/cake migrations migrate
```

Esperado: `== AddOperationCenterToCodeGeneratedModules: migrating` seguido de `== migrated`.

**Step 4: Verificar columnas en BD**

```bash
mysql sgi_db -e "DESCRIBE petty_cash_records;" | grep operation_center_id
mysql sgi_db -e "DESCRIBE refunds;" | grep operation_center_id
mysql sgi_db -e "DESCRIBE payment_schedulings;" | grep operation_center_id
mysql sgi_db -e "SHOW COLUMNS FROM payment_schedulings LIKE 'code';"
```

Esperado: las tres tablas muestran `operation_center_id int(11) YES MUL NULL`. La columna `code` de `payment_schedulings` debe ser `varchar(30)`.

**Step 5: Probar rollback**

```bash
php bin/cake migrations rollback
mysql sgi_db -e "DESCRIBE petty_cash_records;" | grep operation_center_id || echo "OK: columna ausente"
php bin/cake migrations migrate
```

Esperado: rollback elimina la columna; el migrate la vuelve a poner.

**Step 6: Commit**

```bash
git add config/Migrations/*AddOperationCenterToCodeGeneratedModules.php
git commit -m "feat(codes): añade operation_center_id a tablas con código autogenerado"
```

---

## Tarea 2: Constante AdvanceConstants::CODE_PREFIX

**Files:**
- Modify: `src/Constants/AdvanceConstants.php`

**Step 1: Agregar la constante**

Insertar después de la línea `public const MODULE = 'advances';`:

```php
    // Código de invoice_number autogenerado para facturas-anticipo
    public const CODE_PREFIX = 'ANT';
```

**Step 2: Validar sintaxis**

```bash
php -l src/Constants/AdvanceConstants.php
```

Esperado: `No syntax errors detected`.

**Step 3: Commit**

```bash
git add src/Constants/AdvanceConstants.php
git commit -m "feat(codes): añade CODE_PREFIX=ANT a AdvanceConstants"
```

---

## Tarea 3: Crear CodeGeneratorService

**Files:**
- Create: `src/Service/CodeGeneratorService.php`

**Step 1: Escribir el servicio**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Genera códigos del formato {PREFIX}-{YY}-{CCC}-{NNNN}.
 * Consecutivo único por (módulo, año, centro de operación).
 */
final class CodeGeneratorService
{
    public function generatePettyCashCode(int $operationCenterId): string
    {
        return $this->generate(
            PettyCashConstants::CODE_PREFIX,
            'PettyCashRecords',
            'code',
            $operationCenterId,
        );
    }

    public function generateRefundCode(int $operationCenterId): string
    {
        return $this->generate(
            RefundConstants::CODE_PREFIX,
            'Refunds',
            'code',
            $operationCenterId,
        );
    }

    public function generatePaymentSchedulingCode(int $operationCenterId): string
    {
        return $this->generate(
            PaymentSchedulingConstants::CODE_PREFIX,
            'PaymentSchedulings',
            'code',
            $operationCenterId,
        );
    }

    public function generateAdvanceInvoiceNumber(int $operationCenterId): string
    {
        return $this->generate(
            AdvanceConstants::CODE_PREFIX,
            'Invoices',
            'invoice_number',
            $operationCenterId,
        );
    }

    /**
     * @param string $prefix      Prefijo del módulo (CM, REI, PRO, ANT).
     * @param string $tableAlias  Alias del Table de CakePHP (ej. 'PettyCashRecords').
     * @param string $codeField   Nombre del campo de código en la tabla.
     * @param int    $operationCenterId
     */
    private function generate(string $prefix, string $tableAlias, string $codeField, int $operationCenterId): string
    {
        $center = TableRegistry::getTableLocator()
            ->get('OperationCenters')
            ->get($operationCenterId);

        $centerCode = $this->normalizeCenterCode((string)$center->code);
        $year = date('y'); // 2 dígitos
        $base = sprintf('%s-%s-%s-', $prefix, $year, $centerCode);

        $table = TableRegistry::getTableLocator()->get($tableAlias);
        $last = $table->find()
            ->select([$codeField])
            ->where([$codeField . ' LIKE' => $base . '%'])
            ->order([$codeField => 'DESC'])
            ->first();

        $next = 1;
        if ($last !== null && preg_match('/-(\d{4})$/', (string)$last->{$codeField}, $m)) {
            $next = (int)$m[1] + 1;
        }

        return $base . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Normaliza el código del centro a 3 dígitos numéricos con padding de ceros.
     * Si el código no es numérico, lanza excepción (todos los centros deben tenerlo).
     */
    private function normalizeCenterCode(string $code): string
    {
        if (!ctype_digit($code)) {
            throw new RuntimeException(
                'El código del centro de operación debe ser numérico, recibido: ' . $code,
            );
        }

        return str_pad($code, 3, '0', STR_PAD_LEFT);
    }
}
```

**Step 2: Validar sintaxis**

```bash
php -l src/Service/CodeGeneratorService.php
```

Esperado: `No syntax errors detected`.

**Step 3: Commit**

```bash
git add src/Service/CodeGeneratorService.php
git commit -m "feat(codes): servicio CodeGeneratorService para códigos autogenerados"
```

---

## Tarea 4: Integrar en PettyCashRecordsTable

**Files:**
- Modify: `src/Model/Table/PettyCashRecordsTable.php`
- Modify: `src/Controller/PettyCashRecordsController.php` (líneas 160 y 217)

**Step 1: Agregar `beforeSave` en el Table**

Insertar después del método `validationDefault()` (revisar archivo para línea exacta):

```php
public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity, \ArrayObject $options): void
{
    if (!$entity->isNew() || !empty($entity->code)) {
        return;
    }
    if (empty($entity->operation_center_id)) {
        return; // la validación lo bloqueará
    }

    $generator = new \App\Service\CodeGeneratorService();
    $entity->code = $generator->generatePettyCashCode((int)$entity->operation_center_id);
}
```

**Step 2: Endurecer validación**

En `validationDefault()`, reemplazar `->allowEmptyString('code')` (línea ~62) por:

```php
->scalar('code')
->maxLength('code', 30)
->allowEmptyString('code'); // se autogenera en beforeSave

$validator
    ->integer('operation_center_id')
    ->requirePresence('operation_center_id', 'create')
    ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.', 'create');
```

Y agregar la asociación si falta (ver `initialize()`):

```php
$this->belongsTo('OperationCenters', [
    'foreignKey' => 'operation_center_id',
]);
```

**Step 3: Quitar asignación manual de `code` en el controller**

En `PettyCashRecordsController::add()` (línea ~160), eliminar `'code' => !empty($data['code']) ? $data['code'] : null,` y agregar `'operation_center_id' => $data['operation_center_id'] ?? null,`.

En `edit()` (línea ~217), eliminar el bloque `if (...) { $patchData['code'] = ...; }` y asegurarse de que `operation_center_id` NO esté en `$patchData` (inmutable post-creación).

**Step 4: Validar sintaxis**

```bash
php -l src/Model/Table/PettyCashRecordsTable.php
php -l src/Controller/PettyCashRecordsController.php
```

**Step 5: Validación manual mínima**

```bash
php bin/cake server
```

En el navegador: ir a `/petty-cash-records/add`. (UI todavía no muestra centro — eso es Tarea 8.) Verificar con DevTools que enviando un POST con `operation_center_id=1` se guarda con `code=CM-26-001-0001`. Si aún no hay UI lista, omitir y dejar para Tarea 8.

**Step 6: Commit**

```bash
git add src/Model/Table/PettyCashRecordsTable.php src/Controller/PettyCashRecordsController.php
git commit -m "feat(codes): autogeneración de code en caja menor vía beforeSave"
```

---

## Tarea 5: Integrar en RefundsTable

**Files:**
- Modify: `src/Model/Table/RefundsTable.php` (líneas 138-162)
- Modify: `src/Controller/RefundsController.php` (líneas 166 y 245)

**Step 1: Reemplazar `beforeSave` existente**

Reemplazar las líneas 138-162 con:

```php
public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
{
    if (!$entity->isNew() || !empty($entity->code)) {
        return;
    }
    if (empty($entity->operation_center_id)) {
        return;
    }

    $generator = new \App\Service\CodeGeneratorService();
    $entity->code = $generator->generateRefundCode((int)$entity->operation_center_id);
}
```

**Step 2: Validación y asociación**

En `validationDefault()`, mantener `code` como `allowEmptyString` y añadir:

```php
$validator
    ->integer('operation_center_id')
    ->requirePresence('operation_center_id', 'create')
    ->notEmptyString('operation_center_id', 'Selecciona un centro de operación.', 'create');
```

En `initialize()`:

```php
$this->belongsTo('OperationCenters', [
    'foreignKey' => 'operation_center_id',
]);
```

**Step 3: Limpiar controller**

`RefundsController::add()` (línea ~166): eliminar asignación de `code`, agregar `operation_center_id`.

`edit()` (línea ~245): eliminar bloque `if (...) { $patchData['code'] = ...; }` y excluir `operation_center_id` del patch.

**Step 4: Validar sintaxis**

```bash
php -l src/Model/Table/RefundsTable.php
php -l src/Controller/RefundsController.php
```

**Step 5: Commit**

```bash
git add src/Model/Table/RefundsTable.php src/Controller/RefundsController.php
git commit -m "feat(codes): autogeneración de code en reintegros con nuevo formato"
```

---

## Tarea 6: Integrar en PaymentSchedulingsTable

**Files:**
- Modify: `src/Model/Table/PaymentSchedulingsTable.php`
- Modify: `src/Controller/PaymentSchedulingsController.php` (línea 104)

**Step 1: Reemplazar `generateNextCode` por `beforeSave`**

Eliminar el método `generateNextCode()` (líneas 82-100).

Agregar `beforeSave`:

```php
public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity, \ArrayObject $options): void
{
    if (!$entity->isNew() || !empty($entity->code)) {
        return;
    }
    if (empty($entity->operation_center_id)) {
        return;
    }

    $generator = new \App\Service\CodeGeneratorService();
    $entity->code = $generator->generatePaymentSchedulingCode((int)$entity->operation_center_id);
}
```

**Step 2: Validación y asociación**

Mismo patrón que las dos tareas anteriores: `requirePresence('operation_center_id', 'create')` + `belongsTo('OperationCenters')`.

**Step 3: Limpiar controller**

`PaymentSchedulingsController::add()` línea 104: eliminar `$data['code'] = $this->PaymentSchedulings->generateNextCode();` y agregar `$data['operation_center_id'] = $data['operation_center_id'] ?? null;`.

**Step 4: Validar sintaxis**

```bash
php -l src/Model/Table/PaymentSchedulingsTable.php
php -l src/Controller/PaymentSchedulingsController.php
```

**Step 5: Commit**

```bash
git add src/Model/Table/PaymentSchedulingsTable.php src/Controller/PaymentSchedulingsController.php
git commit -m "feat(codes): autogeneración de code en pago programado con nuevo formato"
```

---

## Tarea 7: Integrar en InvoicesTable (anticipos)

**Files:**
- Modify: `src/Model/Table/InvoicesTable.php`

**Step 1: Verificar si existe `beforeSave` actual**

```bash
grep -n "function beforeSave" src/Model/Table/InvoicesTable.php
```

Si existe, **extender** el método sin romper la lógica actual. Si no existe, agregar.

**Step 2: Agregar/extender `beforeSave`**

```php
public function beforeSave(\Cake\Event\EventInterface $event, \Cake\Datasource\EntityInterface $entity, \ArrayObject $options): void
{
    // ... lógica existente si la hay ...

    // Autogenerar invoice_number solo para facturas-anticipo
    if (
        $entity->isNew()
        && empty($entity->invoice_number)
        && ($entity->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO
        && !empty($entity->operation_center_id)
    ) {
        $generator = new \App\Service\CodeGeneratorService();
        $entity->invoice_number = $generator->generateAdvanceInvoiceNumber((int)$entity->operation_center_id);
    }
}
```

**Step 3: Validar sintaxis**

```bash
php -l src/Model/Table/InvoicesTable.php
```

**Step 4: Commit**

```bash
git add src/Model/Table/InvoicesTable.php
git commit -m "feat(codes): autogeneración de invoice_number para facturas-anticipo"
```

---

## Tarea 8: UI Caja menor

**Files:**
- Modify: `templates/PettyCashRecords/add.php`
- Modify: `templates/PettyCashRecords/edit.php`
- Modify: `templates/PettyCashRecords/index.php`
- Modify: `src/Controller/PettyCashRecordsController.php` (acción `add`, `edit`, `index`)

**Step 1: `add.php` — quitar input de `code`, agregar select de centro**

Eliminar el campo `code` del formulario. Agregar:

```php
<div class="mb-3">
    <label for="operation-center-id" class="form-label">Centro de operación <span class="text-danger">*</span></label>
    <?= $this->Form->select('operation_center_id', $operationCenters, [
        'class' => 'form-select select2',
        'empty' => 'Selecciona...',
        'required' => true,
    ]) ?>
</div>
```

**Step 2: Pasar `operationCenters` al template desde `add()`**

En `PettyCashRecordsController::add()` antes de `$this->set(...)`:

```php
$operationCenters = $this->fetchTable('OperationCenters')->find('codeList')->all();
$this->set(compact('operationCenters'));
```

**Step 3: `edit.php` — `code` y centro deshabilitados**

Cambiar el input de `code` a:

```php
<input type="text" class="form-control" value="<?= h($pettyCashRecord->code) ?>" readonly>
```

Cambiar el select de centro (si lo hay) a:

```php
<input type="text" class="form-control" value="<?= h($pettyCashRecord->operation_center?->name ?? '—') ?>" readonly>
```

**Step 4: Lista blanca en `edit()`**

En `PettyCashRecordsController::edit()`, asegurar que el `patchEntity` recibe **solo** los campos editables. `code` y `operation_center_id` no deben estar en `$patchData`. Verificar la línea 215-220 y limpiar.

**Step 5: `index.php` — columna y filtro**

Agregar columna "Centro" tras "Código":

```php
<td><?= h($r->operation_center?->code ?? '—') ?></td>
```

Agregar `contain(['OperationCenters'])` en el query del controller.

Filtro opcional (formulario de búsqueda): agregar `<select name="operation_center_id">` con la lista de centros.

En `_buildQuery` o equivalente, agregar:

```php
if (!empty($params['operation_center_id'])) {
    $query->where(['PettyCashRecords.operation_center_id' => $params['operation_center_id']]);
}
```

**Step 6: Validación manual**

```bash
php bin/cake server
```

1. `/petty-cash-records/add` — debe mostrar select de centro, no debe mostrar input de código.
2. Crear con centro 001 → redirige a view con `code=CM-26-001-0001`.
3. Crear segundo registro centro 001 → `CM-26-001-0002`.
4. Crear con centro 002 → `CM-26-002-0001`.
5. `/petty-cash-records/edit/{id}` — `code` y centro aparecen read-only.
6. Manipular form con DevTools cambiando `operation_center_id` y enviar → el servidor ignora.
7. Index muestra columna Centro y permite filtrar por centro.

**Step 7: Commit**

```bash
git add templates/PettyCashRecords/ src/Controller/PettyCashRecordsController.php
git commit -m "feat(codes): UI de caja menor con centro de operación e código read-only"
```

---

## Tarea 9: UI Reintegros

**Files:**
- Modify: `templates/Refunds/add.php`
- Modify: `templates/Refunds/edit.php`
- Modify: `templates/Refunds/index.php`
- Modify: `src/Controller/RefundsController.php`

**Step 1-7:** mismo patrón que Tarea 8 aplicado al módulo Reintegros. Reemplazar referencias `PettyCashRecords` por `Refunds`, `petty_cash_records` por `refunds`, y prefijo del código `CM` por `REI`.

**Validación manual:**

1. Crear reintegro centro 002 → `code=REI-26-002-0001`.
2. Crear segundo reintegro centro 002 → `REI-26-002-0002`.
3. Edit muestra ambos campos read-only.
4. Legados con `operation_center_id=NULL` muestran "—" en el centro y conservan su `code` viejo (ej. `REI-2026-0042`).

**Commit:**

```bash
git add templates/Refunds/ src/Controller/RefundsController.php
git commit -m "feat(codes): UI de reintegros con centro de operación e código read-only"
```

---

## Tarea 10: UI Pago programado

**Files:**
- Modify: `templates/PaymentSchedulings/add.php`, `edit.php`, `index.php`
- Modify: `src/Controller/PaymentSchedulingsController.php`

**Step 1-7:** mismo patrón. Verificar también la línea del controller donde se llamaba `generateNextCode` — confirmar que ya no aparece.

**Validación manual:**

1. Crear pago programado centro 003 → `code=PRO-26-003-0001`.
2. Edit deshabilita ambos campos.
3. Legados con código `PRO-001` (formato viejo) se siguen viendo intactos en el index.

**Commit:**

```bash
git add templates/PaymentSchedulings/ src/Controller/PaymentSchedulingsController.php
git commit -m "feat(codes): UI de pago programado con centro de operación e código read-only"
```

---

## Tarea 11: UI Anticipos

**Files:**
- Modify: `src/Controller/AdvancesController.php` (acción `add`)
- Modify: `templates/Advances/add.php` (o el template que use)
- Modify: `templates/Advances/edit.php` (si edita facturas-anticipo)

**Step 1: Quitar input de `invoice_number` del formulario de creación**

En `templates/Advances/add.php`, eliminar el campo `invoice_number` (o ocultarlo).

**Step 2: Asegurar que `operation_center_id` es requerido**

El campo ya existe en el formulario de facturas; solo verificar `required` en el HTML y validación.

**Step 3: `edit.php` — bloquear `operation_center_id` cuando es anticipo**

```php
<?php if ($invoice->document_type === \App\Constants\InvoiceConstants::DOCTYPE_ANTICIPO): ?>
    <input type="text" class="form-control" value="<?= h($invoice->operation_center?->name ?? '—') ?>" readonly>
<?php else: ?>
    <?= $this->Form->select('operation_center_id', $operationCenters, [...]) ?>
<?php endif; ?>
```

`invoice_number` para anticipos siempre read-only.

**Step 4: Lista blanca en controller**

Donde se haga `patchEntity` para edición de anticipos, excluir `invoice_number` y `operation_center_id`.

**Step 5: Validación manual**

1. `/advances/add` — sin input de `invoice_number`.
2. Crear anticipo centro 005 → la factura se guarda con `invoice_number=ANT-26-005-0001` y `document_type=ANTICIPO`.
3. Crear segundo anticipo centro 005 → `ANT-26-005-0002`.
4. Editar el anticipo → `invoice_number` y `operation_center_id` read-only.
5. Crear factura **normal** (no-anticipo) → input de `invoice_number` sigue editable como hoy. No se autogenera.

**Step 6: Commit**

```bash
git add src/Controller/AdvancesController.php templates/Advances/
git commit -m "feat(codes): autogeneración de invoice_number en anticipos"
```

---

## Tarea 12: Validación end-to-end

**Step 1: Reset de BD para prueba limpia (opcional, solo en local)**

```bash
mysql sgi_db < /tmp/sgi_db_pre_codes.sql
php bin/cake migrations migrate
```

**Step 2: Sembrar centros 001, 002, 003, 005**

Verificar en `/operation-centers/index` que existen. Si no, crearlos manualmente.

**Step 3: Ejecutar matriz golden path**

| # | Acción | `code`/`invoice_number` esperado |
|---|--------|----------------------------------|
| 1 | Caja menor + centro 001 | `CM-26-001-0001` |
| 2 | Caja menor + centro 001 | `CM-26-001-0002` |
| 3 | Caja menor + centro 002 | `CM-26-002-0001` |
| 4 | Reintegro + centro 002 | `REI-26-002-0001` |
| 5 | Pago programado + centro 003 | `PRO-26-003-0001` |
| 6 | Factura-anticipo + centro 005 | `ANT-26-005-0001` |
| 7 | Factura normal + centro 005 | input libre del usuario |

**Step 4: Casos de borde**

1. Editar registro nuevo: confirmar que `code` y `operation_center_id` son read-only en UI y que el servidor ignora cualquier cambio.
2. Editar legado (registro creado antes del despliegue, con `operation_center_id=NULL`): el `code` viejo se preserva, el centro muestra "—".
3. Crear sin centro de operación: validación falla con mensaje "Selecciona un centro de operación."
4. Borrar un centro de operación con registros: MySQL bloquea por FK RESTRICT.
5. Filtros por centro en index: listas filtradas correctamente.

**Step 5: Code style check**

```bash
composer cs-check
```

Esperado: sin errores. Si hay, correr `composer cs-fix` y commitear con `style:` aparte.

**Step 6: Commit final + push**

```bash
git log --oneline | head -15  # revisar cadena de commits
git push origin main
```

---

## Resumen de commits esperados

```
feat(codes): añade operation_center_id a tablas con código autogenerado
feat(codes): añade CODE_PREFIX=ANT a AdvanceConstants
feat(codes): servicio CodeGeneratorService para códigos autogenerados
feat(codes): autogeneración de code en caja menor vía beforeSave
feat(codes): autogeneración de code en reintegros con nuevo formato
feat(codes): autogeneración de code en pago programado con nuevo formato
feat(codes): autogeneración de invoice_number para facturas-anticipo
feat(codes): UI de caja menor con centro de operación e código read-only
feat(codes): UI de reintegros con centro de operación e código read-only
feat(codes): UI de pago programado con centro de operación e código read-only
feat(codes): autogeneración de invoice_number en anticipos
```

12 tareas, ~11 commits funcionales.

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| Centros con `code` no numérico | `CodeGeneratorService::normalizeCenterCode()` lanza excepción explícita; revisar BD antes de desplegar (`SELECT id, code FROM operation_centers WHERE code NOT REGEXP '^[0-9]+$';`) |
| Colisión de consecutivo en concurrencia | `code` es `UNIQUE` en BD; en colisión MySQL devuelve error y el usuario reintenta. Si se vuelve frecuente, envolver `beforeSave` en `INSERT ... SELECT MAX + 1` con lock |
| `operation_center_id` queda NULL en formularios olvidados | `requirePresence('operation_center_id', 'create')` + `required` en el HTML |
| Templates con cache compilado tras deploy | `php bin/cake cache clear_all` |
| Rollback de migración con datos nuevos en BD | El `code` queda intacto (sigue siendo string); solo se pierde `operation_center_id`. Hacer rollback aplicativo primero |
