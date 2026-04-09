# Sistema de Pagos y Módulo de Programación — Plan de Implementación

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rediseñar el sistema de pagos de facturas para soportar pagos parciales con autorización individual, y crear el módulo "Programación" para pagos masivos con su propio pipeline.

**Architecture:** Se extiende `invoice_payments` con campos de autorización y referencia a programación. Se crea un nuevo módulo `PaymentSchedulings` con pipeline propio (`borrador → tesoreria → aut_pago → pagada`). El `payment_status` de facturas se recalcula automáticamente al autorizar pagos. Los pagos individuales siguen el ciclo `tesoreria ↔ autorizacion_pago`, los pagos vía programación saltan `autorizacion_pago`.

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB, PhpSpreadsheet (Excel import), Bootstrap 5.3

**Design doc:** `docs/plans/2026-04-08-payment-scheduling-design.md`

---

## Task 1: Migración — Extender `invoice_payments` con campos de autorización y programación

**Files:**
- Create: `config/Migrations/20260408000001_ExtendInvoicePayments.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ExtendInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoice_payments')
            ->addColumn('payment_scheduling_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_date',
            ])
            ->addColumn('authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'payment_scheduling_id',
            ])
            ->addColumn('authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'authorized',
            ])
            ->addColumn('authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'authorized_by',
            ])
            ->addForeignKey('authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();

        // Marcar pagos existentes como autorizados (datos previos al nuevo flujo)
        $this->execute("UPDATE invoice_payments SET authorized = 1");
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('authorized_by')
            ->removeColumn('payment_scheduling_id')
            ->removeColumn('authorized')
            ->removeColumn('authorized_by')
            ->removeColumn('authorized_date')
            ->update();
    }
}
```

**Step 2: Ejecutar migración**

Run: `php bin/cake migrations migrate`
Expected: Migration successful

**Step 3: Commit**

```bash
git add config/Migrations/20260408000001_ExtendInvoicePayments.php
git commit -m "feat: extend invoice_payments with authorization and scheduling fields"
```

---

## Task 2: Migración — Modificar tabla `invoices` (renombrar `payment_date`, eliminar campos de autorización)

**Files:**
- Create: `config/Migrations/20260408000002_ModifyInvoicesPaymentFields.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ModifyInvoicesPaymentFields extends BaseMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->renameColumn('payment_date', 'full_payment_date')
            ->update();

        $this->table('invoices')
            ->dropForeignKey('payment_authorized_by')
            ->update();

        $this->table('invoices')
            ->removeColumn('payment_authorized')
            ->removeColumn('payment_authorized_by')
            ->removeColumn('payment_authorized_date')
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->renameColumn('full_payment_date', 'payment_date')
            ->update();

        $this->table('invoices')
            ->addColumn('payment_authorized', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'full_payment_date',
            ])
            ->addColumn('payment_authorized_by', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized',
            ])
            ->addColumn('payment_authorized_date', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'payment_authorized_by',
            ])
            ->addForeignKey('payment_authorized_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }
}
```

**Step 2: Ejecutar migración**

Run: `php bin/cake migrations migrate`
Expected: Migration successful

**Step 3: Commit**

```bash
git add config/Migrations/20260408000002_ModifyInvoicesPaymentFields.php
git commit -m "feat: rename payment_date to full_payment_date, remove payment authorization from invoices"
```

---

## Task 3: Migración — Crear tablas del módulo Programación

**Files:**
- Create: `config/Migrations/20260408000003_CreatePaymentSchedulingTables.php`

**Step 1: Crear la migración**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePaymentSchedulingTables extends BaseMigration
{
    public function up(): void
    {
        // payment_schedulings
        if (!$this->hasTable('payment_schedulings')) {
            $this->table('payment_schedulings')
                ->addColumn('code', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('pipeline_status', 'string', ['limit' => 50, 'null' => false, 'default' => 'borrador'])
                ->addColumn('created_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addIndex(['code'], ['unique' => true])
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_items
        if (!$this->hasTable('payment_scheduling_items')) {
            $this->table('payment_scheduling_items')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('invoice_id', 'integer', ['null' => false])
                ->addColumn('banking_entity_id', 'integer', ['null' => false])
                ->addColumn('amount', 'decimal', ['precision' => 15, 'scale' => 2, 'null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addIndex(['invoice_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('invoice_id', 'invoices', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('banking_entity_id', 'banking_entities', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_attachments
        if (!$this->hasTable('payment_scheduling_attachments')) {
            $this->table('payment_scheduling_attachments')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('file_path', 'string', ['limit' => 500, 'null' => false])
                ->addColumn('file_name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('uploaded_by', 'integer', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('uploaded_by', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // payment_scheduling_observations
        if (!$this->hasTable('payment_scheduling_observations')) {
            $this->table('payment_scheduling_observations')
                ->addColumn('payment_scheduling_id', 'integer', ['null' => false])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('message', 'text', ['null' => false])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addIndex(['payment_scheduling_id'])
                ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'NO_ACTION',
                ])
                ->addForeignKey('user_id', 'users', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'NO_ACTION',
                ])
                ->create();
        }

        // Add FK from invoice_payments to payment_schedulings
        $this->table('invoice_payments')
            ->addForeignKey('payment_scheduling_id', 'payment_schedulings', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoice_payments')
            ->dropForeignKey('payment_scheduling_id')
            ->update();

        if ($this->hasTable('payment_scheduling_observations')) {
            $this->table('payment_scheduling_observations')->drop()->save();
        }
        if ($this->hasTable('payment_scheduling_attachments')) {
            $this->table('payment_scheduling_attachments')->drop()->save();
        }
        if ($this->hasTable('payment_scheduling_items')) {
            $this->table('payment_scheduling_items')->drop()->save();
        }
        if ($this->hasTable('payment_schedulings')) {
            $this->table('payment_schedulings')->drop()->save();
        }
    }
}
```

**Step 2: Ejecutar migración**

Run: `php bin/cake migrations migrate`
Expected: Migration successful

**Step 3: Commit**

```bash
git add config/Migrations/20260408000003_CreatePaymentSchedulingTables.php
git commit -m "feat: create payment_schedulings tables (items, attachments, observations)"
```

---

## Task 4: Migración — Permisos del módulo Programación

**Files:**
- Create: `config/Migrations/20260408000004_AddPaymentSchedulingPermissions.php`

**Step 1: Crear la migración**

Consultar `config/Migrations/20260311000004_AddPettyCashPermissions.php` como referencia para el patrón de permisos. Los roles con acceso son: Tesorería (CRUD), Contador (view+edit para autorizar), Admin (todo).

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPaymentSchedulingPermissions extends BaseMigration
{
    public function up(): void
    {
        // Tesorería (role_id=3): full CRUD
        $this->execute("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
            VALUES (3, 'payment_schedulings', 1, 1, 1, 1)
            ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=1, can_delete=1");

        // Contador (role_id from roles table — look up dynamically)
        $this->execute("INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
            SELECT id, 'payment_schedulings', 1, 0, 1, 0
            FROM roles WHERE name = 'Contador'
            ON DUPLICATE KEY UPDATE can_view=1, can_edit=1");
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'payment_schedulings'");
    }
}
```

**Step 2: Ejecutar migración**

Run: `php bin/cake migrations migrate`
Expected: Migration successful

**Step 3: Commit**

```bash
git add config/Migrations/20260408000004_AddPaymentSchedulingPermissions.php
git commit -m "feat: add payment_schedulings permissions for Tesorería and Contador"
```

---

## Task 5: Constants — `PaymentSchedulingConstants`

**Files:**
- Create: `src/Constants/PaymentSchedulingConstants.php`

**Step 1: Crear el archivo de constantes**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class PaymentSchedulingConstants
{
    // Pipeline statuses
    public const STATUS_BORRADOR = 'borrador';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_AUT_PAGO = 'aut_pago';
    public const STATUS_PAGADA = 'pagada';

    public const PIPELINE_STATUSES = [
        self::STATUS_BORRADOR,
        self::STATUS_TESORERIA,
        self::STATUS_AUT_PAGO,
        self::STATUS_PAGADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_BORRADOR => 'Borrador',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_AUT_PAGO => 'Aut. Pago',
        self::STATUS_PAGADA => 'Pagada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_BORRADOR => 'bi-pencil',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_AUT_PAGO => 'bi-shield-check',
        self::STATUS_PAGADA => 'bi-cash-coin',
    ];

    // Code prefix
    public const CODE_PREFIX = 'PRO';
}
```

**Step 2: Commit**

```bash
git add src/Constants/PaymentSchedulingConstants.php
git commit -m "feat: add PaymentSchedulingConstants"
```

---

## Task 6: Models — Entities del módulo Programación

**Files:**
- Create: `src/Model/Entity/PaymentScheduling.php`
- Create: `src/Model/Entity/PaymentSchedulingItem.php`
- Create: `src/Model/Entity/PaymentSchedulingAttachment.php`
- Create: `src/Model/Entity/PaymentSchedulingObservation.php`

**Step 1: Crear las 4 entidades**

`src/Model/Entity/PaymentScheduling.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\Entity;

class PaymentScheduling extends Entity
{
    protected array $_accessible = [
        'code' => true,
        'title' => true,
        'pipeline_status' => true,
        'created_by' => true,
    ];

    public function isPagada(): bool
    {
        return ($this->pipeline_status ?? '') === PaymentSchedulingConstants::STATUS_PAGADA;
    }
}
```

`src/Model/Entity/PaymentSchedulingItem.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingItem extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'invoice_id' => true,
        'banking_entity_id' => true,
        'amount' => true,
    ];
}
```

`src/Model/Entity/PaymentSchedulingAttachment.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class PaymentSchedulingAttachment extends Entity
{
    protected array $_accessible = [
        'payment_scheduling_id' => true,
        'file_path' => true,
        'file_name' => true,
        'uploaded_by' => true,
    ];
}
```

`src/Model/Entity/PaymentSchedulingObservation.php`:
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
    ];
}
```

**Step 2: Commit**

```bash
git add src/Model/Entity/PaymentScheduling.php src/Model/Entity/PaymentSchedulingItem.php src/Model/Entity/PaymentSchedulingAttachment.php src/Model/Entity/PaymentSchedulingObservation.php
git commit -m "feat: add PaymentScheduling entities"
```

---

## Task 7: Models — Tables del módulo Programación

**Files:**
- Create: `src/Model/Table/PaymentSchedulingsTable.php`
- Create: `src/Model/Table/PaymentSchedulingItemsTable.php`
- Create: `src/Model/Table/PaymentSchedulingAttachmentsTable.php`
- Create: `src/Model/Table/PaymentSchedulingObservationsTable.php`

**Step 1: Crear las 4 tablas**

`src/Model/Table/PaymentSchedulingsTable.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_schedulings');
        $this->setDisplayField('code');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('CreatedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'created_by',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('PaymentSchedulingItems', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PaymentSchedulingAttachments', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PaymentSchedulingObservations', [
            'foreignKey' => 'payment_scheduling_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('InvoicePayments', [
            'foreignKey' => 'payment_scheduling_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('code')
            ->maxLength('code', 20)
            ->requirePresence('code', 'create')
            ->notEmptyString('code');

        $validator
            ->scalar('title')
            ->maxLength('title', 255)
            ->requirePresence('title', 'create')
            ->notEmptyString('title');

        $validator
            ->scalar('pipeline_status')
            ->inList('pipeline_status', PaymentSchedulingConstants::PIPELINE_STATUSES);

        $validator
            ->integer('created_by')
            ->requirePresence('created_by', 'create')
            ->notEmptyString('created_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['code']), ['errorField' => 'code']);
        $rules->add($rules->existsIn('created_by', 'CreatedByUsers'), ['errorField' => 'created_by']);

        return $rules;
    }

    /**
     * Genera el siguiente código PRO-XXX secuencial.
     */
    public function generateNextCode(): string
    {
        $last = $this->find()
            ->select(['code'])
            ->where(['code LIKE' => PaymentSchedulingConstants::CODE_PREFIX . '-%'])
            ->order(['id' => 'DESC'])
            ->first();

        $nextNumber = 1;
        if ($last) {
            $parts = explode('-', $last->code);
            $nextNumber = (int)($parts[1] ?? 0) + 1;
        }

        return PaymentSchedulingConstants::CODE_PREFIX . '-' . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
```

`src/Model/Table/PaymentSchedulingItemsTable.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingItemsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_items');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Invoices', [
            'foreignKey' => 'invoice_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('BankingEntities', [
            'foreignKey' => 'banking_entity_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->integer('invoice_id')
            ->requirePresence('invoice_id', 'create')
            ->notEmptyString('invoice_id');

        $validator
            ->integer('banking_entity_id')
            ->requirePresence('banking_entity_id', 'create')
            ->notEmptyString('banking_entity_id');

        $validator
            ->decimal('amount')
            ->requirePresence('amount', 'create')
            ->notEmptyString('amount');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('invoice_id', 'Invoices'), ['errorField' => 'invoice_id']);
        $rules->add($rules->existsIn('banking_entity_id', 'BankingEntities'), ['errorField' => 'banking_entity_id']);

        return $rules;
    }
}
```

`src/Model/Table/PaymentSchedulingAttachmentsTable.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingAttachmentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_attachments');
        $this->setDisplayField('file_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('UploadedByUsers', [
            'className' => 'Users',
            'foreignKey' => 'uploaded_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('payment_scheduling_id')
            ->requirePresence('payment_scheduling_id', 'create')
            ->notEmptyString('payment_scheduling_id');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 500)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->scalar('file_name')
            ->maxLength('file_name', 255)
            ->requirePresence('file_name', 'create')
            ->notEmptyString('file_name');

        $validator
            ->integer('uploaded_by')
            ->requirePresence('uploaded_by', 'create')
            ->notEmptyString('uploaded_by');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('uploaded_by', 'UploadedByUsers'), ['errorField' => 'uploaded_by']);

        return $rules;
    }
}
```

`src/Model/Table/PaymentSchedulingObservationsTable.php`:
```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PaymentSchedulingObservationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('payment_scheduling_observations');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('PaymentSchedulings', [
            'foreignKey' => 'payment_scheduling_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
        ]);
    }

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
            ->notEmptyString('message');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn('payment_scheduling_id', 'PaymentSchedulings'), ['errorField' => 'payment_scheduling_id']);
        $rules->add($rules->existsIn('user_id', 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
```

**Step 2: Commit**

```bash
git add src/Model/Table/PaymentSchedulingsTable.php src/Model/Table/PaymentSchedulingItemsTable.php src/Model/Table/PaymentSchedulingAttachmentsTable.php src/Model/Table/PaymentSchedulingObservationsTable.php
git commit -m "feat: add PaymentScheduling table classes"
```

---

## Task 8: Actualizar modelo `Invoice` y `InvoicePayment` — Eliminar campos de autorización, agregar `full_payment_date`

**Files:**
- Modify: `src/Model/Entity/Invoice.php`
- Modify: `src/Model/Entity/InvoicePayment.php`
- Modify: `src/Model/Table/InvoicesTable.php`
- Modify: `src/Model/Table/InvoicePaymentsTable.php`

**Step 1: Actualizar `Invoice` entity**

En `src/Model/Entity/Invoice.php`:
- Reemplazar `'payment_date' => true` por `'full_payment_date' => true`
- Eliminar `'payment_authorized' => true`, `'payment_authorized_by' => true`, `'payment_authorized_date' => false`

**Step 2: Actualizar `InvoicePayment` entity**

En `src/Model/Entity/InvoicePayment.php`, agregar campos nuevos al `$_accessible`:
```php
protected array $_accessible = [
    'invoice_id' => true,
    'banking_entity_id' => true,
    'amount' => true,
    'payment_date' => true,
    'payment_scheduling_id' => true,
    'authorized' => true,
    'authorized_by' => true,
    'authorized_date' => true,
    'created_by' => true,
];
```

**Step 3: Actualizar `InvoicesTable`**

En `src/Model/Table/InvoicesTable.php`:
- Eliminar la asociación `belongsTo('PaymentAuthorizedByUsers', ...)` (~línea 79-83)
- En `validationDefault`: eliminar validadores de `payment_authorized`, `payment_authorized_by`, `payment_authorized_date`. Renombrar `payment_date` a `full_payment_date`.
- En `buildRules`: eliminar la regla `existsIn('payment_authorized_by', 'PaymentAuthorizedByUsers')`
- Agregar asociación `hasMany('PaymentSchedulingItems', ['foreignKey' => 'invoice_id'])`

**Step 4: Actualizar `InvoicePaymentsTable`**

En `src/Model/Table/InvoicePaymentsTable.php`:
- Agregar asociaciones:
  ```php
  $this->belongsTo('PaymentSchedulings', [
      'foreignKey' => 'payment_scheduling_id',
      'joinType' => 'LEFT',
  ]);
  $this->belongsTo('AuthorizedByUsers', [
      'className' => 'Users',
      'foreignKey' => 'authorized_by',
      'joinType' => 'LEFT',
  ]);
  ```
- Agregar validaciones para los nuevos campos en `validationDefault`

**Step 5: Commit**

```bash
git add src/Model/Entity/Invoice.php src/Model/Entity/InvoicePayment.php src/Model/Table/InvoicesTable.php src/Model/Table/InvoicePaymentsTable.php
git commit -m "feat: update Invoice/InvoicePayment models for new payment flow"
```

---

## Task 9: Servicio — `InvoicePaymentService` (recálculo de `payment_status`)

**Files:**
- Create: `src/Service/InvoicePaymentService.php`

**Step 1: Crear el servicio**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use Cake\ORM\TableRegistry;

class InvoicePaymentService
{
    /**
     * Recalcula payment_status y full_payment_date de una factura
     * basándose en los pagos autorizados.
     *
     * Retorna true si la factura fue guardada correctamente.
     */
    public function recalculatePaymentStatus(int $invoiceId): bool
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);

        $authorizedPayments = $paymentsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'authorized' => true,
            ])
            ->order(['payment_date' => 'ASC'])
            ->all();

        $totalPaid = 0.0;
        $lastPaymentDate = null;
        foreach ($authorizedPayments as $payment) {
            $totalPaid += (float)$payment->amount;
            $lastPaymentDate = $payment->payment_date;
        }

        if ($totalPaid >= (float)$invoice->amount && $totalPaid > 0) {
            $invoice->payment_status = InvoiceConstants::PAYMENT_FULL;
            $invoice->full_payment_date = $lastPaymentDate;
        } elseif ($totalPaid > 0) {
            $invoice->payment_status = InvoiceConstants::PAYMENT_PARTIAL;
            $invoice->full_payment_date = null;
        } else {
            $invoice->payment_status = null;
            $invoice->full_payment_date = null;
        }

        return (bool)$invoicesTable->save($invoice);
    }

    /**
     * Obtiene el saldo pendiente de pago de una factura.
     */
    public function getPendingBalance(int $invoiceId): float
    {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);

        $totalPaid = (float)$paymentsTable->find()
            ->where([
                'invoice_id' => $invoiceId,
                'authorized' => true,
            ])
            ->sumOf('amount');

        return max(0, (float)$invoice->amount - $totalPaid);
    }

    /**
     * Verifica si hay un pago pendiente de autorización para la factura.
     */
    public function hasPendingAuthorization(int $invoiceId): bool
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        return $paymentsTable->exists([
            'invoice_id' => $invoiceId,
            'authorized' => false,
            'payment_scheduling_id IS' => null, // Solo pagos individuales
        ]);
    }

    /**
     * Autoriza un pago individual y recalcula el estado de la factura.
     * Retorna ['success' => bool, 'paymentStatus' => string|null]
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $payment = $paymentsTable->get($paymentId);

        $payment->authorized = true;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return ['success' => false, 'paymentStatus' => null];
        }

        $this->recalculatePaymentStatus($payment->invoice_id);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($payment->invoice_id);

        return [
            'success' => true,
            'paymentStatus' => $invoice->payment_status,
        ];
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/InvoicePaymentService.php
git commit -m "feat: add InvoicePaymentService for payment recalculation and authorization"
```

---

## Task 10: Actualizar `InvoicePipelineService` — Nuevo flujo de tesorería y autorización

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`

**Step 1: Actualizar constantes y lógica**

Cambios principales:
1. En `EDITABLE_FIELDS` → Tesorería en `tesoreria`: eliminar `'payment_status', 'payment_date'`
2. En `EDITABLE_FIELDS` → Contador en `autorizacion_pago`: eliminar `'payment_authorized'`
3. En `ALL_FIELDS`: reemplazar `'payment_date'` por `'full_payment_date'`, eliminar `'payment_authorized'`
4. En `TRANSITION_REQUIREMENTS[tesoreria]`: cambiar para validar que exista un pago no autorizado pendiente
5. En `TRANSITION_REQUIREMENTS[autorizacion_pago]`: cambiar para validar que el pago pendiente fue autorizado
6. Agregar método `regressToTesoreria(Invoice $invoice, int $userId)` para el regreso de `autorizacion_pago → tesoreria`
7. Modificar `saveAndAdvance()`: después de que Contador autoriza y el pago es parcial, regresar a `tesoreria`

**Step 2: Detalle de los cambios en `TRANSITION_REQUIREMENTS`**

```php
InvoiceConstants::STATUS_TESORERIA => [
    [
        'field' => '_has_pending_payment',
        'custom' => true,
        'label' => 'Debe registrar al menos un pago para avanzar a autorización',
    ],
],
InvoiceConstants::STATUS_AUTORIZACION_PAGO => [
    [
        'field' => '_payment_authorized',
        'custom' => true,
        'label' => 'El pago pendiente debe ser autorizado por el Contador',
    ],
],
```

**Step 3: Modificar `validateTransitionRequirements()`**

Agregar lógica para validaciones custom que consultan `InvoicePaymentService`:
```php
if (!empty($rule['custom'])) {
    $paymentService = new InvoicePaymentService();
    if ($rule['field'] === '_has_pending_payment') {
        if (!$paymentService->hasPendingAuthorization($invoice->id)) {
            $errors[] = $rule['label'];
        }
    } elseif ($rule['field'] === '_payment_authorized') {
        if ($paymentService->hasPendingAuthorization($invoice->id)) {
            $errors[] = $rule['label'];
        }
    }
    continue;
}
```

**Step 4: Agregar lógica de regreso en `saveAndAdvance()`**

Después de avanzar desde `autorizacion_pago`, verificar `payment_status`:
- Si `'Pago Parcial'` → cambiar `pipeline_status` a `tesoreria` (regreso)
- Si `'Pago total'` → avanzar a `pagada`

**Step 5: Commit**

```bash
git add src/Service/InvoicePipelineService.php
git commit -m "feat: update InvoicePipelineService for new payment authorization flow"
```

---

## Task 11: Servicio — `PaymentSchedulingPipelineService`

**Files:**
- Create: `src/Service/PaymentSchedulingPipelineService.php`

**Step 1: Crear el servicio**

Sigue el patrón de `InvoicePipelineService` pero simplificado para el pipeline de Programación:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;

class PaymentSchedulingPipelineService
{
    public const STATUSES = PaymentSchedulingConstants::PIPELINE_STATUSES;
    public const STATUS_LABELS = PaymentSchedulingConstants::STATUS_LABELS;

    public const TRANSITIONS = [
        PaymentSchedulingConstants::STATUS_BORRADOR => PaymentSchedulingConstants::STATUS_TESORERIA,
        PaymentSchedulingConstants::STATUS_TESORERIA => PaymentSchedulingConstants::STATUS_AUT_PAGO,
        PaymentSchedulingConstants::STATUS_AUT_PAGO => PaymentSchedulingConstants::STATUS_PAGADA,
        PaymentSchedulingConstants::STATUS_PAGADA => null,
    ];

    // Regreso cuando Contador rechaza
    public const REJECTION_TARGET = PaymentSchedulingConstants::STATUS_TESORERIA;

    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::TESORERIA => [
            PaymentSchedulingConstants::STATUS_BORRADOR,
            PaymentSchedulingConstants::STATUS_TESORERIA,
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::CONTADOR => [
            PaymentSchedulingConstants::STATUS_AUT_PAGO,
            PaymentSchedulingConstants::STATUS_PAGADA,
        ],
        RoleConstants::ADMIN => PaymentSchedulingConstants::PIPELINE_STATUSES,
    ];

    private const TRANSITION_REQUIREMENTS = [
        PaymentSchedulingConstants::STATUS_BORRADOR => [
            [
                'field' => '_has_items',
                'custom' => true,
                'label' => 'Debe vincular al menos una factura',
            ],
        ],
        PaymentSchedulingConstants::STATUS_TESORERIA => [],
        PaymentSchedulingConstants::STATUS_AUT_PAGO => [],
    ];

    public function getVisibleStatuses(string $roleName): array
    {
        return self::ROLE_VISIBLE_STATUSES[$roleName] ?? [];
    }

    public function canAdvance(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return self::TRANSITIONS[$currentStatus] !== null;
        }

        $visible = $this->getVisibleStatuses($roleName);
        if (!in_array($currentStatus, $visible)) {
            return false;
        }

        // Tesorería puede avanzar borrador y tesoreria
        if ($roleName === RoleConstants::TESORERIA) {
            return in_array($currentStatus, [
                PaymentSchedulingConstants::STATUS_BORRADOR,
                PaymentSchedulingConstants::STATUS_TESORERIA,
            ]);
        }

        // Contador puede avanzar aut_pago
        if ($roleName === RoleConstants::CONTADOR) {
            return $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
        }

        return false;
    }

    public function canReject(string $roleName, string $currentStatus): bool
    {
        if ($roleName === RoleConstants::ADMIN) {
            return $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
        }

        return $roleName === RoleConstants::CONTADOR
            && $currentStatus === PaymentSchedulingConstants::STATUS_AUT_PAGO;
    }

    public function getNextStatus(string $currentStatus): ?string
    {
        return self::TRANSITIONS[$currentStatus] ?? null;
    }

    public function validateTransitionRequirements(object $scheduling, string $fromStatus): array
    {
        $errors = [];
        foreach (self::TRANSITION_REQUIREMENTS[$fromStatus] ?? [] as $rule) {
            if (!empty($rule['custom']) && $rule['field'] === '_has_items') {
                $itemsTable = \Cake\ORM\TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
                $count = $itemsTable->find()->where(['payment_scheduling_id' => $scheduling->id])->count();
                if ($count === 0) {
                    $errors[] = $rule['label'];
                }
            }
        }

        return $errors;
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/PaymentSchedulingPipelineService.php
git commit -m "feat: add PaymentSchedulingPipelineService"
```

---

## Task 12: Servicio — `PaymentSchedulingService` (lógica de negocio, Excel import, aplicación de pagos)

**Files:**
- Create: `src/Service/PaymentSchedulingService.php`

**Step 1: Crear el servicio**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PaymentSchedulingConstants;
use Cake\ORM\TableRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PaymentSchedulingService
{
    private InvoicePaymentService $paymentService;

    public function __construct(?InvoicePaymentService $paymentService = null)
    {
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
    }

    /**
     * Parsea el Excel y valida cada fila.
     * Retorna ['valid' => [...], 'errors' => [...]]
     */
    public function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $providersTable = TableRegistry::getTableLocator()->get('Providers');
        $bankingTable = TableRegistry::getTableLocator()->get('BankingEntities');

        $valid = [];
        $errors = [];
        $headerSkipped = false;

        foreach ($rows as $rowNum => $row) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            $invoiceNumber = trim((string)($row['A'] ?? ''));
            $nit = trim((string)($row['B'] ?? ''));
            $amount = (float)($row['C'] ?? 0);
            $bankName = trim((string)($row['D'] ?? ''));

            if (empty($invoiceNumber) && empty($nit)) {
                continue; // Fila vacía
            }

            // Validar proveedor
            $provider = $providersTable->find()
                ->where(['document_number' => $nit])
                ->first();

            if (!$provider) {
                $errors[] = "Fila {$rowNum}: Proveedor con NIT '{$nit}' no encontrado.";
                continue;
            }

            // Validar factura
            $invoice = $invoicesTable->find()
                ->where([
                    'invoice_number' => $invoiceNumber,
                    'provider_id' => $provider->id,
                ])
                ->first();

            if (!$invoice) {
                $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' del proveedor '{$nit}' no encontrada.";
                continue;
            }

            if ($invoice->pipeline_status !== InvoiceConstants::STATUS_TESORERIA) {
                $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' no está en estado Tesorería (estado actual: {$invoice->pipeline_status}).";
                continue;
            }

            // Validar banco
            $bank = $bankingTable->find()
                ->where([
                    'OR' => [
                        'name' => $bankName,
                        'code' => $bankName,
                    ],
                    'active' => true,
                ])
                ->first();

            if (!$bank) {
                $errors[] = "Fila {$rowNum}: Banco '{$bankName}' no encontrado.";
                continue;
            }

            // Validar monto
            if ($amount <= 0) {
                $errors[] = "Fila {$rowNum}: El monto debe ser positivo.";
                continue;
            }

            $pendingBalance = $this->paymentService->getPendingBalance($invoice->id);
            if ($amount > $pendingBalance) {
                $errors[] = "Fila {$rowNum}: El monto (\${$amount}) excede el saldo pendiente (\${$pendingBalance}) de la factura '{$invoiceNumber}'.";
                continue;
            }

            $valid[] = [
                'row' => $rowNum,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'provider_name' => $provider->name,
                'banking_entity_id' => $bank->id,
                'bank_name' => $bank->name,
                'amount' => $amount,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Vincula items validados a una programación.
     */
    public function linkItems(int $schedulingId, array $validItems): bool
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        foreach ($validItems as $item) {
            $entity = $itemsTable->newEntity([
                'payment_scheduling_id' => $schedulingId,
                'invoice_id' => $item['invoice_id'],
                'banking_entity_id' => $item['banking_entity_id'],
                'amount' => $item['amount'],
            ]);

            if (!$itemsTable->save($entity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aplica los pagos de una programación autorizada.
     * Crea invoice_payments, recalcula payment_status, avanza facturas.
     */
    public function applyPayments(int $schedulingId, int $authorizedBy): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $scheduling = $schedulingsTable->get($schedulingId);
        $items = $itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all();

        $appliedInvoiceIds = [];
        $errors = [];

        foreach ($items as $item) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $item->invoice_id,
                'banking_entity_id' => $item->banking_entity_id,
                'amount' => $item->amount,
                'payment_date' => date('Y-m-d'),
                'payment_scheduling_id' => $schedulingId,
                'authorized' => true,
                'authorized_by' => $authorizedBy,
                'authorized_date' => date('Y-m-d'),
                'created_by' => $scheduling->created_by,
            ]);

            if (!$paymentsTable->save($payment)) {
                $errors[] = "No se pudo crear pago para factura ID {$item->invoice_id}";
                continue;
            }

            $appliedInvoiceIds[] = $item->invoice_id;
        }

        // Recalcular payment_status y avanzar facturas
        $advanced = [];
        $partial = [];
        foreach (array_unique($appliedInvoiceIds) as $invoiceId) {
            $this->paymentService->recalculatePaymentStatus($invoiceId);

            $invoice = $invoicesTable->get($invoiceId);
            if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoicesTable->save($invoice);
                $advanced[] = $invoiceId;
            } else {
                $partial[] = $invoiceId;
            }
        }

        return [
            'success' => empty($errors),
            'errors' => $errors,
            'advanced_to_pagada' => $advanced,
            'partial_payment' => $partial,
        ];
    }

    /**
     * Calcula el monto total de una programación.
     */
    public function calculateTotal(int $schedulingId): float
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        return (float)$itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->sumOf('amount');
    }
}
```

**Step 2: Commit**

```bash
git add src/Service/PaymentSchedulingService.php
git commit -m "feat: add PaymentSchedulingService (Excel import, payment application)"
```

---

## Task 13: Controller — `PaymentSchedulingsController`

**Files:**
- Create: `src/Controller/PaymentSchedulingsController.php`

**Step 1: Crear el controller**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\PaymentSchedulingConstants;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PaymentSchedulingService;
use Laminas\Diactoros\UploadedFile;

class PaymentSchedulingsController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentSchedulingPipelineService $pipeline;
    private PaymentSchedulingService $schedulingService;

    public function initialize(): void
    {
        parent::initialize();
        $this->pipeline = new PaymentSchedulingPipelineService();
        $this->schedulingService = new PaymentSchedulingService();
    }

    private function _getCurrentUser(): object
    {
        return $this->Authentication->getIdentity()->getOriginalData();
    }

    private function _getRoleName(): string
    {
        return $this->_getUserRoleName($this->_getCurrentUser());
    }

    public function index()
    {
        $roleName = $this->_getRoleName();
        $visibleStatuses = $this->pipeline->getVisibleStatuses($roleName);

        $query = $this->PaymentSchedulings->find()
            ->contain(['CreatedByUsers', 'PaymentSchedulingItems'])
            ->order(['PaymentSchedulings.created' => 'DESC']);

        if (!empty($visibleStatuses)) {
            $query->where(['PaymentSchedulings.pipeline_status IN' => $visibleStatuses]);
        }

        // Filters
        $params = $this->request->getQueryParams();
        if (!empty($params['code'])) {
            $query->where(['PaymentSchedulings.code LIKE' => '%' . $params['code'] . '%']);
        }
        if (!empty($params['status'])) {
            $query->where(['PaymentSchedulings.pipeline_status' => $params['status']]);
        }

        $records = $this->paginate($query);
        $this->set(compact('records', 'roleName'));
    }

    public function view($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingAttachments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingAttachments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $total = $this->schedulingService->calculateTotal($record->id);
        $pipelineLabels = PaymentSchedulingConstants::STATUS_LABELS;

        $this->set(compact('record', 'roleName', 'total', 'pipelineLabels'));
    }

    public function add()
    {
        $record = $this->PaymentSchedulings->newEmptyEntity();

        if ($this->request->is('post')) {
            $user = $this->_getCurrentUser();
            $data = $this->request->getData();
            $data['code'] = $this->PaymentSchedulings->generateNextCode();
            $data['pipeline_status'] = PaymentSchedulingConstants::STATUS_BORRADOR;
            $data['created_by'] = $user->id;

            $record = $this->PaymentSchedulings->patchEntity($record, $data);
            if ($this->PaymentSchedulings->save($record)) {
                $this->Flash->success('Programación creada correctamente.');

                return $this->redirect(['action' => 'edit', $record->id]);
            }
            $this->Flash->error('No se pudo crear la programación.');
        }

        $this->set(compact('record'));
    }

    public function edit($id = null)
    {
        $record = $this->PaymentSchedulings->get($id, contain: [
            'CreatedByUsers',
            'PaymentSchedulingItems' => [
                'Invoices' => ['Providers'],
                'BankingEntities',
            ],
            'PaymentSchedulingAttachments' => [
                'UploadedByUsers',
                'sort' => ['PaymentSchedulingAttachments.created' => 'DESC'],
            ],
            'PaymentSchedulingObservations' => [
                'Users',
                'sort' => ['PaymentSchedulingObservations.created' => 'ASC'],
            ],
        ]);

        $roleName = $this->_getRoleName();
        $currentStatus = $record->pipeline_status;
        $canAdvance = $this->pipeline->canAdvance($roleName, $currentStatus);
        $canReject = $this->pipeline->canReject($roleName, $currentStatus);
        $total = $this->schedulingService->calculateTotal($record->id);

        $advanceErrors = [];
        if ($canAdvance) {
            $advanceErrors = $this->pipeline->validateTransitionRequirements($record, $currentStatus);
        }

        $pipelineLabels = PaymentSchedulingConstants::STATUS_LABELS;
        $nextStatus = $this->pipeline->getNextStatus($currentStatus);
        $bankingEntities = $this->fetchTable('BankingEntities')->find('codeList')->all();

        $this->set(compact(
            'record', 'roleName', 'currentStatus', 'canAdvance', 'canReject',
            'total', 'advanceErrors', 'pipelineLabels', 'nextStatus', 'bankingEntities',
        ));
    }

    public function advance($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();

        if (!$this->pipeline->canAdvance($roleName, $record->pipeline_status)) {
            $this->Flash->error('No tiene permisos para avanzar esta programación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $errors = $this->pipeline->validateTransitionRequirements($record, $record->pipeline_status);
        if (!empty($errors)) {
            foreach ($errors as $err) {
                $this->Flash->error($err);
            }

            return $this->redirect(['action' => 'edit', $id]);
        }

        $nextStatus = $this->pipeline->getNextStatus($record->pipeline_status);

        // Si avanza a pagada (desde aut_pago), aplicar pagos
        if ($nextStatus === PaymentSchedulingConstants::STATUS_PAGADA) {
            $result = $this->schedulingService->applyPayments($record->id, (int)$user->id);
            if (!$result['success']) {
                foreach ($result['errors'] as $err) {
                    $this->Flash->error($err);
                }

                return $this->redirect(['action' => 'edit', $id]);
            }

            $advancedCount = count($result['advanced_to_pagada']);
            $partialCount = count($result['partial_payment']);
            if ($advancedCount > 0) {
                $this->Flash->success("{$advancedCount} factura(s) marcadas como Pagadas.");
            }
            if ($partialCount > 0) {
                $this->Flash->warning("{$partialCount} factura(s) con Pago Parcial, permanecen en Tesorería.");
            }
        }

        $record->pipeline_status = $nextStatus;
        if ($this->PaymentSchedulings->save($record)) {
            $label = PaymentSchedulingConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
            $this->Flash->success("Programación avanzada a: {$label}");
        } else {
            $this->Flash->error('No se pudo avanzar la programación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function reject($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);
        $roleName = $this->_getRoleName();

        if (!$this->pipeline->canReject($roleName, $record->pipeline_status)) {
            $this->Flash->error('No tiene permisos para rechazar esta programación.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $record->pipeline_status = PaymentSchedulingPipelineService::REJECTION_TARGET;
        if ($this->PaymentSchedulings->save($record)) {
            $this->Flash->warning('Programación devuelta a Tesorería para corrección.');
        } else {
            $this->Flash->error('No se pudo rechazar la programación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function importExcel($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!in_array($record->pipeline_status, [PaymentSchedulingConstants::STATUS_BORRADOR])) {
            $this->Flash->error('Solo se puede importar Excel en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        /** @var UploadedFile $file */
        $file = $this->request->getUploadedFile('excel_file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $result = $this->schedulingService->parseExcel($tmpPath);

        // Guardar resultados en sesión para confirmar
        $this->request->getSession()->write("import_preview_{$id}", $result);

        return $this->redirect(['action' => 'previewImport', $id]);
    }

    public function previewImport($id = null)
    {
        $record = $this->PaymentSchedulings->get($id);
        $result = $this->request->getSession()->read("import_preview_{$id}");

        if (!$result) {
            $this->Flash->error('No hay datos de importación pendientes.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $this->set(compact('record', 'result'));
    }

    public function confirmImport($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        $result = $this->request->getSession()->read("import_preview_{$id}");
        if (!$result || empty($result['valid'])) {
            $this->Flash->error('No hay datos válidos para importar.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        if ($this->schedulingService->linkItems($record->id, $result['valid'])) {
            $count = count($result['valid']);
            $this->Flash->success("{$count} factura(s) vinculadas correctamente.");
        } else {
            $this->Flash->error('Error al vincular las facturas.');
        }

        $this->request->getSession()->delete("import_preview_{$id}");

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addItem($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden agregar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->newEntity([
            'payment_scheduling_id' => $id,
            'invoice_id' => $this->request->getData('invoice_id'),
            'banking_entity_id' => $this->request->getData('banking_entity_id'),
            'amount' => $this->request->getData('amount'),
        ]);

        if ($itemsTable->save($item)) {
            $this->Flash->success('Factura vinculada.');
        } else {
            $this->Flash->error('No se pudo vincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function removeItem($id = null, $itemId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $record = $this->PaymentSchedulings->get($id);

        if ($record->pipeline_status !== PaymentSchedulingConstants::STATUS_BORRADOR) {
            $this->Flash->error('Solo se pueden eliminar facturas en estado Borrador.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $itemsTable = $this->fetchTable('PaymentSchedulingItems');
        $item = $itemsTable->get($itemId);

        if ($itemsTable->delete($item)) {
            $this->Flash->success('Factura desvinculada.');
        } else {
            $this->Flash->error('No se pudo desvincular la factura.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function uploadAttachment($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        $file = $this->request->getUploadedFile('file');
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se recibió un archivo válido.');

            return $this->redirect(['action' => 'edit', $id]);
        }

        $fileName = $file->getClientFilename();
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'payment_schedulings' . DS . $id;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $targetPath = $uploadDir . DS . $fileName;
        $file->moveTo($targetPath);

        $relativePath = 'uploads/payment_schedulings/' . $id . '/' . $fileName;

        $attachmentsTable = $this->fetchTable('PaymentSchedulingAttachments');
        $attachment = $attachmentsTable->newEntity([
            'payment_scheduling_id' => $id,
            'file_path' => $relativePath,
            'file_name' => $fileName,
            'uploaded_by' => $this->_getCurrentUser()->id,
        ]);

        if ($attachmentsTable->save($attachment)) {
            $this->Flash->success('Soporte subido correctamente.');
        } else {
            $this->Flash->error('No se pudo guardar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function deleteAttachment($id = null, $attachmentId = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $attachmentsTable = $this->fetchTable('PaymentSchedulingAttachments');
        $attachment = $attachmentsTable->get($attachmentId);

        $filePath = WWW_ROOT . $attachment->file_path;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        if ($attachmentsTable->delete($attachment)) {
            $this->Flash->success('Soporte eliminado.');
        } else {
            $this->Flash->error('No se pudo eliminar el soporte.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }

    public function addObservation($id = null)
    {
        $this->request->allowMethod(['post']);
        $user = $this->_getCurrentUser();

        $observationsTable = $this->fetchTable('PaymentSchedulingObservations');
        $observation = $observationsTable->newEntity([
            'payment_scheduling_id' => $id,
            'user_id' => $user->id,
            'message' => $this->request->getData('message'),
        ]);

        if ($this->request->is('ajax')) {
            if ($observationsTable->save($observation)) {
                return $this->_jsonResponse([
                    'success' => true,
                    'observation' => [
                        'message' => $observation->message,
                        'user_name' => $user->full_name,
                        'created' => $observation->created->format('d/m/Y H:i'),
                    ],
                ]);
            }

            return $this->_jsonResponse(['success' => false, 'error' => 'No se pudo agregar la observación.']);
        }

        if ($observationsTable->save($observation)) {
            $this->Flash->success('Observación agregada.');
        } else {
            $this->Flash->error('No se pudo agregar la observación.');
        }

        return $this->redirect(['action' => 'edit', $id]);
    }
}
```

**Step 2: Commit**

```bash
git add src/Controller/PaymentSchedulingsController.php
git commit -m "feat: add PaymentSchedulingsController"
```

---

## Task 14: Registrar módulo en permisos y rutas

**Files:**
- Modify: `src/Controller/AppController.php` (~línea 24-50)
- Modify: `src/Service/AuthorizationService.php` (~línea 15-40)
- Modify: `config/routes.php`

**Step 1: Agregar a `$controllerModuleMap` en `AppController.php`**

Agregar después de la línea de `BankingEntities`:
```php
'PaymentSchedulings' => 'payment_schedulings',
```

**Step 2: Agregar a `_actionToPermission` en `AppController.php`**

Agregar acciones nuevas a las categorías existentes:
- `'importExcel', 'previewImport', 'confirmImport', 'addItem', 'uploadAttachment'` → `'add'`
- `'advance', 'reject', 'addObservation'` → `'edit'`
- `'removeItem', 'deleteAttachment'` → `'delete'`

**Step 3: Agregar a `AuthorizationService::MODULES`**

```php
'payment_schedulings' => 'Programación',
```

**Step 4: Agregar rutas en `config/routes.php`**

Antes de `$builder->fallbacks()`:
```php
$builder->connect('/payment-schedulings/advance/{id}', [
    'controller' => 'PaymentSchedulings', 'action' => 'advance',
], ['pass' => ['id']]);
$builder->connect('/payment-schedulings/reject/{id}', [
    'controller' => 'PaymentSchedulings', 'action' => 'reject',
], ['pass' => ['id']]);
```

**Step 5: Commit**

```bash
git add src/Controller/AppController.php src/Service/AuthorizationService.php config/routes.php
git commit -m "feat: register PaymentSchedulings module in permissions and routes"
```

---

## Task 15: Actualizar `InvoicesController` — Adaptar pago individual al nuevo flujo

**Files:**
- Modify: `src/Controller/InvoicesController.php`

**Step 1: Actualizar `addPayment()`**

Después de guardar el pago, avanzar la factura a `autorizacion_pago`:
```php
if ($paymentsTable->save($payment)) {
    // Avanzar factura a autorizacion_pago
    $invoice->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
    $this->Invoices->save($invoice);
    $this->Flash->success('Pago registrado. La factura pasó a Autorización de Pago.');
} else {
    $this->Flash->error('No se pudo registrar el pago.');
}
```

**Step 2: Agregar acción `authorizePayment()`**

Nueva acción para que el Contador autorice un pago individual:
```php
public function authorizePayment($invoiceId = null, $paymentId = null)
{
    $this->request->allowMethod(['post']);
    $invoice = $this->Invoices->get($invoiceId);
    $user = $this->_getCurrentUser();
    $roleName = $this->_getRoleName();

    if ($roleName !== RoleConstants::CONTADOR && $roleName !== RoleConstants::ADMIN) {
        $this->Flash->error('Solo el Contador puede autorizar pagos.');
        return $this->redirect(['action' => 'edit', $invoiceId]);
    }

    $paymentService = new \App\Service\InvoicePaymentService();
    $result = $paymentService->authorizePayment((int)$paymentId, (int)$user->id);

    if ($result['success']) {
        if ($result['paymentStatus'] === InvoiceConstants::PAYMENT_FULL) {
            $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
            $this->Invoices->save($invoice);
            $this->Flash->success('Pago autorizado. Factura marcada como Pagada.');
        } else {
            $invoice->pipeline_status = InvoiceConstants::STATUS_TESORERIA;
            $this->Invoices->save($invoice);
            $this->Flash->success('Pago autorizado. Factura devuelta a Tesorería (Pago Parcial).');
        }
    } else {
        $this->Flash->error('No se pudo autorizar el pago.');
    }

    return $this->redirect(['action' => 'edit', $invoiceId]);
}
```

**Step 3: Actualizar `edit()`**

- Eliminar referencia a `PaymentAuthorizedByUsers` en el contain
- Actualizar la vista para que muestre el estado de autorización de cada pago individual
- Agregar `addPayment`, `deletePayment`, `authorizePayment` a las acciones editables en `_actionToPermission`

**Step 4: Actualizar `view()`**

- Eliminar `PaymentAuthorizedByUsers` del contain
- Agregar `AuthorizedByUsers` al contain de `InvoicePayments`

**Step 5: Actualizar `export()`**

- Cambiar `'Fecha Pago'` a `'Fecha Pago Total'` y `payment_date` a `full_payment_date`
- Cambiar `'Autorizada Pago'` para leerlo de los pagos individuales

**Step 6: Commit**

```bash
git add src/Controller/InvoicesController.php
git commit -m "feat: update InvoicesController for new payment authorization flow"
```

---

## Task 16: Actualizar template `Invoices/edit.php` — Sección tesorería y autorización

**Files:**
- Modify: `templates/Invoices/edit.php`

**Step 1: Sección Tesorería (~línea 705-827)**

- Eliminar los campos de `payment_status` (select) y `payment_date` (input) — ahora son calculados y solo se muestran como read-only
- Mostrar `payment_status` como badge (calculado)
- Mostrar `full_payment_date` como texto readonly
- En la tabla de pagos: agregar columna "Estado" con badge (Autorizado/Pendiente)
- En la tabla de pagos: si está en `autorizacion_pago` y rol es Contador, mostrar botón "Autorizar"

**Step 2: Sección Autorización de Pago (~línea 829-874)**

- Eliminar el checkbox `payment_authorized` y los campos `payment_authorized_by`/`payment_authorized_date`
- Reemplazar con vista de pagos pendientes de autorización con botones de autorizar por pago individual

**Step 3: Commit**

```bash
git add templates/Invoices/edit.php
git commit -m "feat: update invoice edit template for new payment flow"
```

---

## Task 17: Actualizar template `Invoices/view.php` — Reflejar nuevo modelo de pagos

**Files:**
- Modify: `templates/Invoices/view.php`

**Step 1:** Actualizar la sección de tesorería para mostrar `payment_status` como badge calculado, `full_payment_date`, y la tabla de pagos con columna de autorización.

**Step 2:** Eliminar sección de autorización de pago antigua (checkbox).

**Step 3: Commit**

```bash
git add templates/Invoices/view.php
git commit -m "feat: update invoice view template for new payment model"
```

---

## Task 18: Templates del módulo Programación — `index.php`

**Files:**
- Create: `templates/PaymentSchedulings/index.php`

**Step 1: Crear el template index**

Seguir el patrón de `templates/PettyCashRecords/index.php`:
- Tabla con columnas: Código, Título, Estado (badge), N. Facturas, Monto Total, Creado por, Fecha
- Filtros: código, estado
- Botón "Nueva Programación"
- Filas clickeables con `data-href`

**Step 2: Commit**

```bash
git add templates/PaymentSchedulings/index.php
git commit -m "feat: add PaymentSchedulings index template"
```

---

## Task 19: Templates del módulo Programación — `add.php`

**Files:**
- Create: `templates/PaymentSchedulings/add.php`

**Step 1: Crear el template add**

Formulario simple:
- Campo `title` (texto)
- El `code` se genera automáticamente
- Botón "Crear Programación"

**Step 2: Commit**

```bash
git add templates/PaymentSchedulings/add.php
git commit -m "feat: add PaymentSchedulings add template"
```

---

## Task 20: Templates del módulo Programación — `edit.php`

**Files:**
- Create: `templates/PaymentSchedulings/edit.php`

**Step 1: Crear el template edit**

Este es el template principal del módulo. Secciones:

1. **Header:** Código, título, pipeline progress (reutilizar patrón de `pipeline_progress.php`)
2. **Facturas vinculadas:** Tabla con: N. Factura, Proveedor, Banco, Monto, Acciones (eliminar si borrador)
3. **Importar Excel:** Botón + formulario upload (solo en borrador)
4. **Agregar factura manual:** Formulario inline (solo en borrador). Campos: invoice_id (select2), banco, monto
5. **Soportes:** Lista de archivos con upload (solo en tesorería)
6. **Observaciones:** Chat con formulario (patrón AJAX igual que facturas)
7. **Totales:** Monto total calculado, cantidad de facturas
8. **Acciones:** Botón avanzar / rechazar según rol y estado

**Step 2: Commit**

```bash
git add templates/PaymentSchedulings/edit.php
git commit -m "feat: add PaymentSchedulings edit template"
```

---

## Task 21: Templates del módulo Programación — `view.php` y `preview_import.php`

**Files:**
- Create: `templates/PaymentSchedulings/view.php`
- Create: `templates/PaymentSchedulings/preview_import.php`

**Step 1: Crear `view.php`**

Vista de solo lectura del registro. Misma estructura que edit pero sin formularios.

**Step 2: Crear `preview_import.php`**

Vista de previsualización del Excel importado:
- Tabla de facturas válidas (verde): N. Factura, Proveedor, Banco, Monto
- Lista de errores (rojo): mensaje de error por fila
- Botón "Confirmar importación" (POST a confirmImport)
- Botón "Cancelar" (volver a edit)

**Step 3: Commit**

```bash
git add templates/PaymentSchedulings/view.php templates/PaymentSchedulings/preview_import.php
git commit -m "feat: add PaymentSchedulings view and preview_import templates"
```

---

## Task 22: Agregar enlace en sidebar

**Files:**
- Modify: `templates/layout/default.php`

**Step 1:** Agregar nav-link de "Programación" en la sección de Facturación del sidebar, después de "Legalizaciones":

```php
<?php if ($canView('payment_schedulings')): ?>
<li class="nav-item">
    <?= $this->Html->link(
        '<i class="bi bi-calendar-check me-2"></i>Programación',
        ['controller' => 'PaymentSchedulings', 'action' => 'index'],
        ['class' => $navLink('PaymentSchedulings') . ' d-flex align-items-center', 'escape' => false]
    ) ?>
</li>
<?php endif; ?>
```

**Step 2: Commit**

```bash
git add templates/layout/default.php
git commit -m "feat: add Programación link to sidebar"
```

---

## Task 23: Actualizar `InvoiceConstants` y `InvoiceHistoryService`

**Files:**
- Modify: `src/Constants/InvoiceConstants.php` (si es necesario agregar nuevos valores)
- Modify: `src/Service/InvoiceHistoryService.php` — actualizar `FIELD_LABELS` para `full_payment_date`

**Step 1:** Renombrar label de `payment_date` a `full_payment_date` en `InvoiceHistoryService::FIELD_LABELS`

**Step 2:** Eliminar labels de `payment_authorized`, `payment_authorized_by`, `payment_authorized_date`

**Step 3: Commit**

```bash
git add src/Constants/InvoiceConstants.php src/Service/InvoiceHistoryService.php
git commit -m "feat: update InvoiceConstants and InvoiceHistoryService for new payment fields"
```

---

## Task 24: Actualizar `pipeline_progress.php` element y `index.php` de facturas

**Files:**
- Modify: `templates/element/pipeline_progress.php`
- Modify: `templates/Invoices/index.php`

**Step 1:** En `pipeline_progress.php`: actualizar cualquier referencia a `payment_date` por `full_payment_date`

**Step 2:** En `Invoices/index.php`: actualizar badges y columnas que referencien `payment_date`, `payment_authorized`

**Step 3: Commit**

```bash
git add templates/element/pipeline_progress.php templates/Invoices/index.php
git commit -m "feat: update pipeline progress element and invoices index for new payment model"
```

---

## Task 25: Pruebas manuales y verificación final

**Step 1:** Ejecutar el servidor de desarrollo

Run: `php bin/cake server`

**Step 2:** Verificar flujo completo:

1. Crear una factura y avanzarla hasta `tesoreria`
2. **Pago individual:** Registrar un pago parcial → verificar que avanza a `autorizacion_pago` → Contador autoriza → verificar que regresa a `tesoreria` con `payment_status = 'Pago Parcial'`
3. Registrar otro pago que complete el total → Contador autoriza → verificar que avanza a `pagada` con `full_payment_date` llena
4. **Programación:** Crear registro de programación → cargar Excel → previsualizar → confirmar → avanzar a tesorería → subir soportes → avanzar a aut_pago → Contador autoriza → verificar que se aplican los pagos y las facturas avanzan correctamente
5. **Rechazo:** Crear programación → llevar a aut_pago → Contador rechaza → verificar que regresa a tesorería

**Step 3:** Verificar permisos:
- Tesorería puede crear/editar programaciones
- Contador solo ve en aut_pago y puede autorizar
- Admin puede hacer todo

**Step 4: Commit final**

```bash
git add -A
git commit -m "feat: complete payment scheduling module implementation"
```
