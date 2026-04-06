# SGI — Architecture Guide

Practical guide for building and extending the SGI (Sistema de Gestión Interna). This document covers **how** to work on the project: layer responsibilities, patterns, conventions, and step-by-step instructions for common tasks.

For quick reference and commands, see `CLAUDE.md`. For visual design rules, see `STYLES.md`.

---

## 1. Application Layers

### 1.1 Request Lifecycle

```
HTTP Request
    │
    ▼
[Middleware Stack]
    ErrorHandler → HostHeader → Asset → Routing → Authentication → BodyParser → CSRF
    │
    ▼
[AppController::beforeFilter]
    1. Get user identity
    2. Set currentUser for views
    3. Calculate sidebar counters
    4. Calculate user permissions
    5. Enforce permission for current controller/action
    │
    ▼
[Controller Action]
    1. Validate input
    2. Delegate to Service (business logic)
    3. Interact with Model (persistence)
    4. Set view variables
    │
    ▼
[View/Template]
    Layout (default.php) + Specific template
    │
    ▼
HTTP Response
```

### 1.2 Layer Responsibilities

| Layer | Does | Does NOT |
|-------|------|----------|
| **Controller** | Receive request, validate input, delegate to services, set view variables | Business logic, complex queries |
| **Service** | Business logic, orchestration, DB transactions | Access request/response directly |
| **Table (Model)** | Associations, data validation, custom finders, behaviors | Complex business logic |
| **Entity** | Field whitelist (`$_accessible`), virtual properties, domain helpers | Database queries |
| **View/Template** | HTML presentation, visual formatting | Business logic, DB queries |
| **Constants** | Reusable domain values (roles, states, types) | Logic, DB access |
| **Middleware** | Cross-cutting security concerns (auth, CSRF, host validation) | Specific business logic |

**Rule of thumb:** If you're writing an `if` with business meaning in a controller, it belongs in a service. If you're running a query in a template, it belongs in the controller. If you're hardcoding a string like `'Rechazada'` in PHP, it belongs in a constant.

---

## 2. Conceptual Directory Structure

This section explains **what goes where** — not what files exist. When adding new code, place it in the correct layer.

### `src/Controller/`

One controller per resource. Always extends `AppController`. Responsible for HTTP concerns only.

```
src/Controller/
├── AppController.php              # Base class: permissions, sidebar counters
├── {Resource}Controller.php       # One per module (Invoices, Employees, etc.)
└── Trait/
    └── ExcelCatalogTrait.php      # Shared trait for catalog export/import
```

**What goes here:**
- Request validation and input sanitization
- Service instantiation in `initialize()`
- Shared query builders as `_build*Query()` private methods
- View variable assignment via `$this->set()`

**What does NOT go here:**
- Business rules, state transitions, calculations
- Direct complex queries (delegate to Table finders or Services)

### `src/Service/`

One service per business domain. No access to request/response. Accesses tables via `TableRegistry::getTableLocator()->get()`.

```
src/Service/
├── {Domain}Service.php            # Core business logic (InvoicePipelineService)
├── {Domain}FilterService.php      # Search/filter logic (InvoiceFilterService)
├── {Domain}DocumentService.php    # File upload/management (InvoiceDocumentService)
├── {Domain}HistoryService.php     # Audit trail (InvoiceHistoryService)
└── ImportResult.php               # DTOs when needed
```

**What goes here:**
- Business rules and validations
- State machine logic (pipeline transitions)
- Cross-table orchestration and DB transactions
- Email sending, webhook calls, external integrations

**What does NOT go here:**
- `$this->request` or `$this->response` — services are request-agnostic
- HTML rendering or view logic

### `src/Model/Entity/`

Typed entities with field whitelist and domain helper methods.

```
src/Model/Entity/
└── {Resource}.php                 # Entity with $_accessible + helpers
```

**What goes here:**
- `$_accessible` array defining mass-assignable fields
- Domain helper methods that inspect entity state (e.g., `isRejected()`, `isPaid()`)
- Virtual fields via `_get{FieldName}()` methods

**What does NOT go here:**
- Database queries of any kind
- Business logic that requires other entities or tables

### `src/Model/Table/`

ORM table classes with associations, validation rules, behaviors, and custom finders.

```
src/Model/Table/
└── {Resource}Table.php            # Associations, validation, finders
```

**What goes here:**
- `initialize()`: table name, primary key, associations (`belongsTo`, `hasMany`), behaviors
- `validationDefault()`: field-level validation rules
- Custom finders (e.g., `findCodeList()`, `findAuth()`)

**What does NOT go here:**
- Business logic beyond simple data validation
- Complex multi-table orchestration (use a Service)

### `src/Constants/`

Domain values as `final` classes with `public const`. Never hardcode domain strings or IDs in PHP.

```
src/Constants/
└── {Domain}Constants.php          # e.g., InvoiceConstants, RoleConstants
```

### `templates/`

One folder per controller, plus shared elements and layouts.

```
templates/
├── layout/
│   ├── default.php                # Authenticated pages (sidebar + topbar)
│   ├── login.php                  # Login page (split-panel)
│   ├── external.php               # External approval via token
│   ├── ajax.php                   # AJAX responses (no layout chrome)
│   └── email/                     # Email templates (HTML + text)
├── element/
│   ├── pipeline_progress.php      # Reusable pipeline visual bar
│   ├── pagination.php             # Pagination component (use in all lists)
│   └── catalog_excel_buttons.php  # Export/import buttons for catalogs
└── {ControllerName}/
    ├── index.php                  # List view
    ├── add.php                    # Create form
    ├── edit.php                   # Edit form
    └── view.php                   # Detail view
```

### `config/Migrations/`

Timestamped migration files. Base class is `Migrations\BaseMigration`.

```
config/Migrations/
└── YYYYMMDDHHMMSS_DescriptiveName.php
```

### `webroot/`

Public assets. Custom CSS and JS live here — no build step.

```
webroot/
├── css/styles.css                 # Complete design system (see STYLES.md)
├── js/sgi-common.js               # Plugin initialization (Flatpickr, AutoNumeric, Select2)
├── fonts/Inter-Variable.ttf       # Inter font (weights 100–900)
└── uploads/{entity}/{id}/         # User-uploaded files
```

---

## 3. How to Create a New Module

Step-by-step guide with real code examples from the project.

### 3.1 Create the Migration

Use `Migrations\BaseMigration` (NOT `AbstractMigration`). Protect with `hasTable()`.

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateWidgets extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('widgets')) {
            $table = $this->table('widgets');
            $table
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('operation_center_id', 'integer', ['signed' => true, 'null' => false])
                ->addColumn('active', 'boolean', ['default' => true])
                ->addColumn('created', 'datetime', ['null' => true])
                ->addColumn('modified', 'datetime', ['null' => true])
                ->addForeignKey('operation_center_id', 'operation_centers', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('widgets')) {
            $this->table('widgets')->drop()->save();
        }
    }
}
```

**Critical:** Foreign key columns must have **identical types** (signed/unsigned) as the referenced column. Check existing tables before adding FKs.

### 3.2 Create the Constants (if needed)

If the module has domain values (states, types, options), create a constants class:

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class WidgetConstants
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];
}
```

### 3.3 Create the Entity

Define `$_accessible` and add domain helpers if the entity has meaningful state:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Widget extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'operation_center_id' => true,
        'active' => true,
    ];

    // Domain helper — only if the entity has meaningful state to check
    public function isActive(): bool
    {
        return (bool)$this->active;
    }
}
```

### 3.4 Create the Table

Add associations, `TimestampBehavior`, validation, and custom finders:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class WidgetsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('widgets');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('OperationCenters', [
            'foreignKey' => 'operation_center_id',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('name', 'Name is required')
            ->requirePresence('operation_center_id', 'create');

        return $validator;
    }

    // Custom finder pattern — use instead of overriding findList()
    public function findCodeList(\Cake\ORM\Query\SelectQuery $query, array $options): \Cake\ORM\Query\SelectQuery
    {
        return $query->formatResults(fn($results) =>
            $results->combine('id', fn($row) => $row->code . ' - ' . $row->name)
        );
    }
}
```

**Important:** Never override `findList()` in CakePHP 5 — the signature is incompatible. Use custom finders like `findCodeList()` instead.

### 3.5 Create the Controller

Extend `AppController`, set pagination, instantiate services in `initialize()`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

class WidgetsController extends AppController
{
    public $paginate = ['limit' => 15, 'maxLimit' => 15];

    public function initialize(): void
    {
        parent::initialize();
        // Instantiate services here if needed
        // $this->widgetService = new WidgetService();
    }

    public function index()
    {
        $query = $this->Widgets->find()
            ->contain(['OperationCenters']);

        $widgets = $this->paginate($query);
        $this->set(compact('widgets'));
    }

    public function add()
    {
        $widget = $this->Widgets->newEmptyEntity();

        if ($this->request->is('post')) {
            $widget = $this->Widgets->patchEntity($widget, $this->request->getData());
            if ($this->Widgets->save($widget)) {
                $this->Flash->success('Widget saved.');
                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error('Could not save widget.');
        }

        $operationCenters = $this->Widgets->OperationCenters->find('list')->toArray();
        $this->set(compact('widget', 'operationCenters'));
    }
}
```

### 3.6 Register Permissions

Three places must be updated:

**1. Add to `AppController::$controllerModuleMap`:**
```php
// In src/Controller/AppController.php
protected array $controllerModuleMap = [
    // ... existing entries
    'Widgets' => 'widgets',
];
```

**2. Add to `AuthorizationService::MODULES`:**
```php
// In src/Service/AuthorizationService.php
public const MODULES = [
    // ... existing entries
    'widgets' => 'Widgets',
];
```

**3. Insert permissions in the database** (via migration or seed):
```sql
INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete)
VALUES
    (1, 'widgets', 1, 1, 1, 1),  -- Admin: full access
    (5, 'widgets', 1, 1, 0, 0);  -- Registro/Revisión: view + create
```

### 3.7 Create Templates

Follow `STYLES.md` for visual consistency. Use standard elements:

- `templates/element/pagination.php` for paginated lists
- `.flatpickr-date` class on date inputs
- `.currency-input` class on money inputs
- `.clickable-row` with `data-href` on table rows

### 3.8 Add Routes (only if custom actions needed)

Standard CRUD works automatically via `$builder->fallbacks()`. Only add routes for non-standard actions:

```php
// In config/routes.php — BEFORE $builder->fallbacks()
$builder->connect(
    '/widgets/activate/{id}',
    ['controller' => 'Widgets', 'action' => 'activate'],
    ['id' => '\d+', 'pass' => ['id']]
);
```

---

## 4. Patterns and Conventions

### 4.1 Constants Over Hardcoding

Never use literal strings or numbers for domain values in PHP code. Always reference constants:

```php
// WRONG
if ($invoice->area_approval === 'Rechazada') { ... }
if ($user->role->name === 'Administrador') { ... }

// CORRECT
use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;

if ($invoice->area_approval === InvoiceConstants::APPROVAL_REJECTED) { ... }
if ($user->role->name === RoleConstants::ADMIN) { ... }
```

Constants are defined in `src/Constants/` as `final` classes:

```php
final class InvoiceConstants
{
    public const APPROVAL_REJECTED = 'Rechazada';
    public const APPROVAL_APPROVED = 'Aprobada';
    public const APPROVAL_STATUSES = [self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_REJECTED];

    public const PAYMENT_FULL = 'Pago total';
    public const PAYMENT_PARTIAL = 'Pago Parcial';
}
```

### 4.2 Service Dependency Injection

Services receive dependencies via constructor with optional defaults. This allows testing with mocks while keeping production instantiation simple:

```php
class InvoicePipelineService
{
    public function __construct(
        ?InvoiceHistoryService $historyService = null,
        ?NotificationService $notificationService = null,
    ) {
        $this->historyService = $historyService ?? new InvoiceHistoryService();
        $this->notificationService = $notificationService ?? new NotificationService();
    }
}
```

Controllers instantiate services in `initialize()`:

```php
public function initialize(): void
{
    parent::initialize();
    $this->pipeline = new InvoicePipelineService();
    $this->filterService = new InvoiceFilterService();
    $this->documentService = new InvoiceDocumentService();
}
```

**Rules:**
- One service per business domain
- Services access tables via `TableRegistry::getTableLocator()->get('TableName')`
- Services do NOT access `$this->request` or `$this->response`
- Dependencies between services are injected via constructor
- Don't duplicate logic — delegate to the service that already implements it

### 4.3 Reusable Queries in Controllers

When multiple actions share the same base query, extract to a private `_build*Query()` method:

```php
private function _buildInvoiceQuery(array $conditions = []): SelectQuery
{
    $query = $this->Invoices->find()
        ->contain(['Providers', 'OperationCenters', 'ExpenseTypes']);

    if (!empty($conditions)) {
        $query->where($conditions);
    }

    $this->filterService->apply($query, $this->request->getQueryParams());

    return $query;
}

// Usage
public function index()
{
    $query = $this->_buildInvoiceQuery(['pipeline_status' => 'aprobacion']);
    $invoices = $this->paginate($query);
    $this->set(compact('invoices'));
}
```

### 4.4 Custom Finders

In CakePHP 5, **do not override `findList()`** — the signature is incompatible with `Table::findList()`. Use custom finders instead:

```php
// In Table class
public function findCodeList(SelectQuery $query, array $options): SelectQuery
{
    return $query->formatResults(fn($results) =>
        $results->combine('id', fn($row) => $row->code . ' - ' . $row->name)
    );
}

// Usage
$items = $this->OperationCenters->find('codeList')->toArray();
```

### 4.5 Pagination

Fixed at **15 items per page** across the entire application:

```php
public $paginate = ['limit' => 15, 'maxLimit' => 15];
```

Always use the `pagination.php` element in list templates:

```php
<?= $this->element('pagination') ?>
```

### 4.6 Migrations

- **Base class:** `Migrations\BaseMigration` (NOT `AbstractMigration`)
- **Filename prefix:** `YYYYMMDDHHMMSS_DescriptiveName.php`
- **Foreign keys:** Column types must match exactly (signed/unsigned)
- **Protection:** Use `$this->hasTable()` to prevent failures if table already exists
- **Language:** Migration names and comments in English

### 4.7 Routes

Standard CRUD is handled automatically by `$builder->fallbacks()` in `config/routes.php`. Only add custom routes for non-standard actions:

```php
// Custom action with ID parameter
$builder->connect(
    '/invoices/advance-status/{id}',
    ['controller' => 'Invoices', 'action' => 'advanceStatus'],
    ['id' => '\d+', 'pass' => ['id']]
);

// Custom action with token parameter
$builder->connect(
    '/approve/{token}',
    ['controller' => 'ExternalApprovals', 'action' => 'review'],
    ['token' => '[a-f0-9]{64}', 'pass' => ['token']]
);
```

**Always** add custom routes **before** `$builder->fallbacks()`.

### 4.8 Change History / Audit Trail

`InvoiceHistoryService::recordChanges()` compares old vs new values with strict comparison (`!==`) and type normalization:

- `DateTimeInterface` → normalized to `Y-m-d` string
- Booleans → normalized with `(bool)` cast
- Empty strings → normalized to `null`

This prevents false positives in the audit trail from type mismatches.

### 4.9 Excel Catalog Export/Import

Catalog controllers use `ExcelCatalogTrait` for standardized Excel export and import:

```php
use App\Controller\Trait\ExcelCatalogTrait;

class ProvidersController extends AppController
{
    use ExcelCatalogTrait;
    // ...
}
```

The trait provides `exportExcel()` and `importExcel()` actions. Use the `catalog_excel_buttons.php` element in templates to render the UI buttons.

### 4.10 Error Handling

Errors are handled differently depending on the layer:

**Controllers** — Use Flash messages for user-facing errors and redirect. Never expose internal details:

```php
// Save operation with Flash feedback
if ($this->Widgets->save($widget)) {
    $this->Flash->success('Widget guardado correctamente.');
    return $this->redirect(['action' => 'index']);
}
$this->Flash->error('No se pudo guardar el widget. Verifique los datos.');
```

**Services** — Return structured results (arrays or booleans) to indicate success/failure. Throw exceptions only for truly exceptional situations (data corruption, external service unavailable). Let the controller decide what to show to the user:

```php
// Service returns structured result — controller decides how to present it
class InvoicePipelineService
{
    public function saveAndAdvance(Invoice $invoice, array $data, string $roleName): array
    {
        // Returns ['success' => bool, 'errors' => [...], 'warnings' => [...]]
    }

    public function validateTransitionRequirements(Invoice $invoice, string $fromStatus): array
    {
        // Returns array of error strings (empty = valid)
        $errors = [];
        if ($this->isRejected($invoice)) {
            $errors[] = 'La factura fue rechazada y no puede avanzar.';
        }
        return $errors;
    }
}

// Controller interprets the result
$result = $this->pipeline->saveAndAdvance($invoice, $data, $roleName);
if (!$result['success']) {
    foreach ($result['errors'] as $error) {
        $this->Flash->error($error);
    }
}
```

**Tables** — Use CakePHP validation rules in `validationDefault()`. Entity errors are automatically available via `$entity->getErrors()` after a failed `patchEntity()` or `save()`.

**Exceptions** — Use CakePHP built-in exceptions for HTTP-level errors:

```php
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\ForbiddenException;

// In controller — entity not found
$invoice = $this->Invoices->get($id);  // Throws NotFoundException automatically

// In controller — manual permission check
if (!$this->canAccessInvoice($invoice)) {
    throw new ForbiddenException('No tiene permiso para ver esta factura.');
}
```

**Rules:**
- Controllers handle user-facing feedback (Flash messages, redirects)
- Services return data, not HTTP responses — never throw `NotFoundException` from a service
- Never catch exceptions silently — if you catch, log or re-throw
- Never expose stack traces, SQL errors, or internal paths to the user

### 4.11 Database Transactions

Use `Connection::transactional()` for operations that modify multiple tables or require atomicity. This is the standard pattern in services:

```php
use Cake\Datasource\ConnectionManager;

class InvoicePipelineService
{
    public function saveAndAdvance(Invoice $invoice, array $data, string $roleName): array
    {
        $connection = ConnectionManager::get('default');

        return $connection->transactional(function () use ($invoice, $data, $roleName) {
            $invoicesTable = $this->getTable('Invoices');

            $invoice = $invoicesTable->patchEntity($invoice, $data);
            if (!$invoicesTable->save($invoice)) {
                return ['success' => false, 'errors' => ['No se pudo guardar la factura.']];
            }

            // Record history (second table write — same transaction)
            $this->historyService->recordChanges($invoice, $originalData, $userId);

            // Advance status (third table write — same transaction)
            $invoice->pipeline_status = $nextStatus;
            $invoicesTable->save($invoice);

            return ['success' => true, 'errors' => []];
        });
    }
}
```

**Rules:**
- Use `$connection->transactional()` — it auto-commits on success and auto-rollbacks on exception
- Never use manual `begin()` / `commit()` / `rollback()` unless you have a specific reason (e.g., nested savepoints)
- Wrap in a transaction when: saving to multiple tables, save + delete, or any operation where partial completion would leave inconsistent data
- Do NOT wrap single-table saves in a transaction — CakePHP handles those internally
- Get the connection via `ConnectionManager::get('default')` in services, or `$this->Table->getConnection()` when a table reference is already available

### 4.12 Logging

Use CakePHP's built-in `Log` class. Log at the appropriate level depending on the situation:

```php
use Cake\Log\Log;

// Error — something failed that should not have (save error, external API failure)
Log::error('Failed to send notification email for invoice #{id}', ['id' => $invoice->id]);

// Warning — something unexpected but recoverable (missing optional data, deprecated usage)
Log::warning('Invoice #{id} advanced without approver assigned', ['id' => $invoice->id]);

// Info — significant business events worth tracking (state transitions, user actions)
Log::info('Invoice #{id} advanced from {from} to {to} by user #{userId}', [
    'id' => $invoice->id,
    'from' => $fromStatus,
    'to' => $toStatus,
    'userId' => $userId,
]);

// Debug — detailed information for troubleshooting (query parameters, calculation steps)
Log::debug('Filter params applied: {params}', ['params' => json_encode($filters)]);
```

**What to log:**
- Failed save/delete operations in services (with entity ID and context)
- External service calls (email sending, API calls) — both success and failure
- Pipeline state transitions (who, what, when)
- Authentication events (login, logout, failed attempts — handled by the auth plugin)

**What NOT to log:**
- Successful CRUD operations on simple entities (that's noise, not signal)
- Request/response data that contains passwords or sensitive fields
- Every query execution (use DebugKit for that during development)

**Rules:**
- Always include entity IDs and relevant context in log messages
- Use structured placeholders (`{id}`, `{status}`) instead of string concatenation
- Services log errors and warnings; controllers generally don't need to log (Flash messages serve that purpose for the user)

### 4.13 Validation: Tables vs Services

Validation happens at two levels. Each level has a distinct purpose — do not mix them:

| Level | Where | What It Validates | Examples |
|-------|-------|-------------------|----------|
| **Field validation** | `Table::validationDefault()` | Data format, presence, type, length | Required fields, email format, max length, `inList()` |
| **Business validation** | Service methods | Domain rules, state-dependent rules, cross-entity rules | "Cannot advance if rejected", "Payment date required for full payment" |

```php
// FIELD VALIDATION — in Table class
// Answers: "Is this data well-formed?"
public function validationDefault(Validator $validator): Validator
{
    $validator
        ->notEmptyString('name', 'El nombre es requerido')
        ->maxLength('name', 200)
        ->email('email', false, 'Email inválido')
        ->inList('pipeline_status', InvoiceConstants::PIPELINE_STATUSES)
        ->decimal('amount', null, 'El monto debe ser numérico');

    return $validator;
}

// BUSINESS VALIDATION — in Service class
// Answers: "Is this operation allowed given the current state?"
public function validateTransitionRequirements(Invoice $invoice, string $fromStatus): array
{
    $errors = [];

    if ($this->isRejected($invoice)) {
        $errors[] = 'La factura fue rechazada y no puede avanzar en el pipeline.';
    }

    if ($fromStatus === InvoiceConstants::STATUS_TESORERIA) {
        if ($invoice->payment_status !== InvoiceConstants::PAYMENT_FULL) {
            $errors[] = 'El estado de pago debe ser "Pago total" para marcar como pagada.';
        }
        if (empty($invoice->payment_date)) {
            $errors[] = 'La fecha de pago es requerida para marcar como pagada.';
        }
    }

    return $errors;
}
```

**Rules:**
- Table validation runs automatically on `patchEntity()` and `save()` — it's the first line of defense
- Service validation is called explicitly by the controller before performing a business operation
- Never put business rules in `validationDefault()` (e.g., "can only edit if status is X")
- Never put format checks in services (e.g., "email must be valid") — that's the table's job
- Use constants in `inList()` validators: `->inList('status', InvoiceConstants::STATUSES)`

### 4.14 Naming Conventions

CakePHP enforces most naming via convention-over-configuration. This table documents the full set used in SGI:

| Element | Convention | Example |
|---------|-----------|---------|
| Database table | `snake_case`, plural | `invoice_histories` |
| Database column | `snake_case` | `operation_center_id` |
| Foreign key column | `singular_table_id` | `provider_id`, `role_id` |
| Entity class | `PascalCase`, singular | `InvoiceHistory` |
| Table class | `PascalCase`, plural + `Table` | `InvoiceHistoriesTable` |
| Controller class | `PascalCase`, plural + `Controller` | `InvoiceHistoriesController` |
| Service class | `PascalCase` + `Service` | `InvoicePipelineService` |
| Constants class | `PascalCase` + `Constants` | `InvoiceConstants` |
| Constants | `UPPER_SNAKE_CASE` | `APPROVAL_REJECTED` |
| Template folder | `PascalCase`, matches controller | `templates/InvoiceHistories/` |
| Template file | `snake_case.php` | `index.php`, `add.php` |
| Element file | `snake_case.php` | `pipeline_progress.php` |
| Migration file | `YYYYMMDDHHMMSS_PascalCase.php` | `20260215120000_CreateWidgets.php` |
| Route URL | `kebab-case` | `/invoices/advance-status/{id}` |
| Controller action | `camelCase` | `advanceStatus()`, `exportExcel()` |
| Private controller method | `_camelCase` (underscore prefix) | `_buildInvoiceQuery()` |
| Entity virtual field | `_getCamelCase()` | `_getFullName()` |
| Custom finder | `findCamelCase()` | `findCodeList()`, `findAuth()` |
| CSS custom class | `.sgi-kebab-case` | `.sgi-stat-card`, `.sgi-btn-primary` |

**Critical conventions:**
- CakePHP auto-inflects names: `InvoiceHistories` controller → `invoice_histories` table → `InvoiceHistory` entity
- Foreign keys **must** follow the pattern `singular_table_id` for CakePHP associations to work automatically
- Template folders **must** match the controller name exactly (PascalCase) for automatic template resolution

### 4.15 Service Families

Business domains that involve pipeline workflows, documents, and audit trails follow a consistent service family pattern. Each domain gets up to 5 specialized services:

| Service Type | Purpose | Examples |
|--------------|---------|----------|
| `{Domain}PipelineService` | State machine: transitions, role-based field visibility, validation | `InvoicePipelineService`, `NoveltyPipelineService` |
| `{Domain}FilterService` | Search/filter logic applied to queries | `InvoiceFilterService`, `EmployeeFilterService` |
| `{Domain}DocumentService` | File upload, validation, deletion | `InvoiceDocumentService`, `NoveltyDocumentService`, `EmployeeDocumentService` |
| `{Domain}HistoryService` | Field-by-field audit trail recording | `InvoiceHistoryService`, `NoveltyHistoryService`, `EmployeeHistoryService` |
| `{Domain}ObservationService` | User comments/observations on records | `NoveltyObservationService` |

Not every domain needs all five — create only what the module requires.

### 4.16 Pipeline Service Structure

Pipeline services manage state machine workflows. They define their configuration through a set of `const` arrays:

```php
class InvoicePipelineService
{
    // 1. Status labels for display
    public const STATUS_LABELS = [
        InvoiceConstants::STATUS_APROBACION => 'Aprobacion',
        InvoiceConstants::STATUS_CONTABILIDAD => 'Contabilidad',
        // ...
    ];

    // 2. Which statuses each role can see
    private const ROLE_VISIBLE_STATUSES = [
        RoleConstants::REGISTRO_REVISION => [InvoiceConstants::STATUS_APROBACION],
        RoleConstants::CONTABILIDAD => [InvoiceConstants::STATUS_CONTABILIDAD],
        // ...
    ];

    // 3. Which fields each role can edit per status
    private const EDITABLE_FIELDS = [
        RoleConstants::REGISTRO_REVISION => [
            InvoiceConstants::STATUS_APROBACION => ['invoice_number', 'issue_date', ...],
        ],
        // ...
    ];

    // 4. Which form sections each role sees
    private const VISIBLE_SECTIONS_BY_ROLE = [
        RoleConstants::REGISTRO_REVISION => ['general', 'dates', 'classification', 'revision'],
        RoleConstants::CONTABILIDAD => ['general', 'dates', 'classification', 'accounting'],
        // ...
    ];

    // 5. State transitions (linear)
    public const TRANSITIONS = [
        InvoiceConstants::STATUS_APROBACION => InvoiceConstants::STATUS_CONTABILIDAD,
        // ...
    ];

    // 6. Requirements to advance from each status
    private const TRANSITION_REQUIREMENTS = [
        InvoiceConstants::STATUS_APROBACION => [
            ['field' => 'area_approval', 'value' => InvoiceConstants::APPROVAL_APPROVED, 'label' => '...'],
        ],
        // ...
    ];
}
```

**Key methods** every pipeline service provides:

| Method | Purpose |
|--------|---------|
| `getVisibleStatuses(roleName)` | Returns which pipeline states a role can see |
| `getEditableFields(roleName, status)` | Returns field whitelist for a role at a given status |
| `getVisibleSections(roleName, status)` | Returns which form sections to display |
| `validateTransitionRequirements(entity, fromStatus)` | Returns array of error strings (empty = valid) |
| `saveAndAdvance(entity, data, roleName)` | Unified save + state transition in one transaction |
| `filterEntityData(data, roleName, status)` | Strips fields the role cannot edit |

### 4.17 Document Service Conventions

All document services share common constraints and patterns:

**Upload limits:**
- Maximum file size: `10 MB` (`MAX_DOC_SIZE = 10 * 1024 * 1024`)
- Allowed MIME types: PDF, JPEG, PNG, GIF, Word (`.doc`, `.docx`), Excel (`.xls`, `.xlsx`)

**Storage:**
- Files stored in `webroot/uploads/{entity}/{id}/`
- Filenames use a unique prefix: `{entity_prefix}_` + `uniqid()` + original extension
- Entity prefixes: `inv_` (invoices), `nov_` (novelties), `emp_` (employees)

**Return convention:**
- On validation error: return a `string` with the error message
- On success: return the saved document `Entity`

**Standard methods:**

| Method | Purpose |
|--------|---------|
| `uploadDocument(entityId, ..., file)` | Validate, move file, create DB record |
| `deleteDocument(documentId)` | Remove file from disk + delete DB record |
| `canDeleteDocument(document, pipelineStatus)` | Check if deletion is allowed given current state |

### 4.18 History Service Conventions

History services record field-by-field changes for audit trails. Each service defines:

1. **`FIELD_LABELS`** — `public const` array mapping field names to human-readable Spanish labels
2. **`recordChanges(original, modified, userId)`** — Compares old vs new values for tracked fields

**Value normalization** (applied before comparison with `!==`):
- `DateTimeInterface` → `Y-m-d` string
- Booleans → `(bool)` cast
- Empty strings → `null`

**History record structure** (same across all history tables):

| Column | Type | Description |
|--------|------|-------------|
| `{entity}_id` | integer | FK to the parent entity |
| `user_id` | integer | FK to the user who made the change |
| `field_changed` | string | Field name that was modified |
| `old_value` | string (nullable) | Previous value |
| `new_value` | string (nullable) | New value |
| `created` | datetime | Timestamp of the change |

### 4.19 External Integration Services

External integrations follow a layered pattern:

```
WebhookService          ← Low-level HTTP client (Cake\Http\Client wrapper)
    ↑
N8nService              ← n8n-specific: resolves webhook URLs from SystemSettings
    ↑
DianCrosscheckService   ← Domain-specific: DIAN file upload + status tracking
NotificationService     ← Email sending via CakePHP Mailer + SMTP from SystemSettings
```

**`WebhookService`** — Generic HTTP client wrapper. Provides `sendJson(url, data)` and `sendFile(url, filePath, fieldName, extraData)`. Returns structured array:

```php
['success' => bool, 'statusCode' => int, 'body' => string, 'error' => string]
```

**`N8nService`** — Resolves n8n webhook URLs from `system_settings` table via `SystemSettingsService`. Methods: `sendData(webhookKey, data)`, `sendFile(webhookKey, filePath, ...)`, `isConfigured(webhookKey)`.

**`NotificationService`** — Sends emails on pipeline state transitions. Reads SMTP config from `system_settings` table. Determines recipients based on role and target pipeline status.

**`SystemSettingsService`** — Key-value configuration store. Provides `get(key)`, `getGroup(prefix)`, `set(key, value)`. Used to store SMTP credentials, webhook URLs, and application-wide settings.

---

## 5. Permission System (RBAC)

### 5.1 How It Works

Every controller action is automatically checked against the `permissions` table. The flow:

```
AppController::beforeFilter()
    │
    ▼
_enforcePermission(user)
    │
    ▼
$controllerModuleMap[ControllerName] → module name
_actionToPermission(action) → permission action (view/add/edit/delete)
    │
    ▼
AuthorizationService::isAllowed(roleId, roleName, module, permAction)
    ├── Admin? → true (bypass all checks)
    └── Other role? → query `permissions` table
```

The `isAllowed()` method maps actions to permission columns:

```php
return match ($action) {
    'view', 'index' => (bool)$perm['can_view'],
    'add' => (bool)$perm['can_create'],
    'edit' => (bool)$perm['can_edit'],
    'delete' => (bool)$perm['can_delete'],
    default => false,
};
```

### 5.2 Roles

Defined in `src/Constants/RoleConstants.php`:

| Constant | Value | Access |
|----------|-------|--------|
| `ADMIN` | Administrador | Full access (bypasses all checks) |
| `REGISTRO_REVISION` | Registro/Revisión | Invoice `aprobacion` state |
| `CONTABILIDAD` | Contabilidad | Invoice `contabilidad` state |
| `TESORERIA` | Tesorería | Invoice `tesoreria` state |

### 5.3 How to Add a New Module to Permissions

See [Section 3.6](#36-register-permissions) — three places must be updated: `$controllerModuleMap`, `AuthorizationService::MODULES`, and the `permissions` table.

---

## 6. Invoice Pipeline (Core Business Module)

### 6.1 The 4-State Workflow

```
aprobacion → contabilidad → tesoreria → pagada
```

Each role can only see and edit invoices in their assigned states:

| Role | Visible States | Editable Fields |
|------|---------------|-----------------|
| Registro/Revisión | `aprobacion` | Document fields, approver, DIAN |
| Contabilidad | `aprobacion`, `contabilidad` | Accounting fields (accrued, accrual_date) |
| Tesorería | `tesoreria` | Payment fields (payment_status, payment_date) |
| Admin | All | All |

### 6.2 Key Components

- **`InvoicePipelineService`** — Central orchestrator. Manages transitions, field permissions, validation.
  - `saveAndAdvance()` — Unified save + state transition in one transaction
  - `getVisibleSections()` — Controls which form sections each role sees
  - `validateTransitionRequirements()` — Checks if all required fields are filled before advancing
  - `getVisibleStatuses()` — Returns which pipeline states a role can see
  - `isRejected()` — Checks if invoice was rejected (blocks all advancement)

- **`InvoiceHistoryService`** — Records field-by-field changes in `invoice_histories` table

- **`ApprovalTokenService`** — SHA256 tokens for external stakeholder approval (bypasses login)

- **`NotificationService`** — Sends emails on state changes

### 6.3 Transition Rules

Validated in `validateTransitionRequirements()`:
- **Any state:** If `area_approval = 'Rechazada'`, the pipeline is blocked
- **tesoreria → pagada:** Requires `payment_status = 'Pago total'` AND `payment_date` not empty

### 6.4 Form Sections by Role

`getVisibleSections()` returns which sections to show in the edit form:

| Section | Registro/Revisión | Contabilidad | Tesorería |
|---------|:-:|:-:|:-:|
| general | ✓ | ✓ | ✓ |
| dates | ✓ | ✓ | — |
| classification | ✓ | ✓ | — |
| revision | ✓ | — | — |
| accounting | — | ✓ | — |
| treasury | — | — | ✓ |

---

## 7. Frontend and Design System

For complete visual rules, CSS variables, color palette, typography, and component specifications, see **`STYLES.md`**. This section covers only the practical essentials needed when writing templates.

### 7.1 Asset Load Order (Mandatory)

**CSS** (in `<head>`, this exact order):
1. Bootstrap CSS → 2. Bootstrap Icons CSS → 3. Flatpickr CSS → 4. `styles.css` (custom — overrides Bootstrap)

**JavaScript** (end of `<body>`):
1. Bootstrap JS → 2. Flatpickr JS + Spanish locale → 3. AutoNumeric JS → 4. Select2 JS → 5. `sgi-common.js` (auto-initializes plugins)

### 7.2 Auto-Initialized Components

These initialize automatically via `sgi-common.js` — just add the CSS class:

| Class | Effect |
|-------|--------|
| `.flatpickr-date` | Date picker |
| `.currency-input` | COP currency formatting |
| `.select2` | Searchable dropdown |
| `.clickable-row` | Row click navigation (requires `data-href`) |

### 7.3 Layouts

| Layout | When to Use |
|--------|------------|
| `default.php` | All authenticated pages (sidebar + topbar) |
| `login.php` | Login page (split-panel: dark left / white right) |
| `external.php` | External approval via token (no sidebar) |
| `ajax.php` | AJAX responses (no layout chrome) |

For the full list of custom CSS classes (`.sgi-stat-card`, `.sgi-btn-primary`, `.sgi-input-group`, etc.) and design principles (borders over shadows, micro-caps, Inter Variable font), refer to `STYLES.md`.

---

## 8. Security

### 8.1 Authentication

- Plugin: `cakephp/authentication ^3.0`
- Authenticators: `Session` + `Form`
- Identifier: `Password` with bcrypt hashing
- Custom finder: `UsersTable::findAuth()` filters `active = true` with `contain(['Roles'])`
- Unauthenticated requests redirect to `/login`

**How it works:** `Application.php` implements `AuthenticationServiceProviderInterface` and registers the `AuthenticationMiddleware` in the middleware stack (before `BodyParserMiddleware`).

### 8.2 Authorization

Automatic RBAC via `AuthorizationService` + `permissions` table. Enforced in `AppController::beforeFilter()` on every request. Admin role bypasses all permission checks. See [Section 5](#5-permission-system-rbac) for details.

### 8.3 CSRF Protection

`CsrfProtectionMiddleware` with `httponly: true`. Token is available in a meta tag for AJAX requests.

### 8.4 Host Header Validation

`HostHeaderMiddleware` validates the `Host` header in production to prevent Host Header Injection attacks.

### 8.5 File Uploads

- Files stored in `webroot/uploads/{entity}/{id}/`
- Filenames use a unique prefix: `inv_` + `uniqid()` + original extension
- Upload logic is encapsulated in document services (`InvoiceDocumentService`, `EmployeeDocumentService`, etc.)
