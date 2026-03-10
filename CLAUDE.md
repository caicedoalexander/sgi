# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**SGI (Sistema de Gestión Interna)** — Internal management system for HR, invoicing, catalogs, and document management. Built with CakePHP 5, it features a sophisticated invoice pipeline (4-state workflow), role-based permissions, employee document management, and PDF/Excel exports.

**Tech Stack:** CakePHP 5.3 · PHP 8.2+ · MariaDB · Bootstrap 5 · Flatpickr · AutoNumeric · Select2

---

## Quick Start Commands

```bash
# Install dependencies
composer install

# Run development server
bin/cake server                    # http://localhost:8765

# Database migrations
bin/cake migrations migrate        # Apply pending migrations
bin/cake migrations rollback       # Rollback last migration
bin/cake migrations status         # List migration status

# Code quality
composer cs-check                  # Check PHP code style (CakePHP standard)
composer cs-fix                    # Fix code style issues
composer test                      # Run PHPUnit tests
composer check                     # Run both tests and cs-check

# Code generation (bake)
bin/cake bake controller Employees # Generate controller scaffold
bin/cake bake migration CreateTable HistoryType
bin/cake bake model Providers      # Entity + Table
```

---

## Architecture at a Glance

### Layered Structure

```
Request → Middleware → Controller → Service → Model/Table → Database
                          ↓
                        View
```

**Layer responsibilities:**
- **Controller** — Receive HTTP request, validate input, delegate to services, set view variables
- **Service** — Business logic, orchestration, transactions (in `src/Service/`)
- **Table (Model)** — Associations, validation, custom finders (in `src/Model/Table/`)
- **Entity** — Whitelist fields, domain helpers (`isRejected()`, `isApproved()`) (in `src/Model/Entity/`)
- **Middleware** — Cross-cutting security concerns (`Application.php`)
- **Constants** — Domain values (states, roles, types) never hardcoded in PHP (in `src/Constants/`)

### Module Organization

| Module | Controllers | Core Service | Purpose |
|--------|-------------|--------------|---------|
| **Invoices** | InvoicesController | InvoicePipelineService | 4-state workflow (aprobacion → contabilidad → tesoreria → pagada) |
| **Employees** | EmployeesController, EmployeeNovedadesController, EmployeeLeavesController | EmployeeDocumentService, LeaveDocumentService | HR, contracts, documents, leaves |
| **Catalogs** | ProvidersController, OperationCentersController, ExpenseTypesController, etc. | — | Reference tables with Excel import/export |
| **System** | UsersController, SystemSettingsController, DianCrosschecksController | AuthorizationService | Users, roles, permissions, SMTP config |

---

## Database & Migrations

**Connection:** MariaDB remote, configured via `DATABASE_URL` in `.env`

**Key patterns:**
- Migrations in `config/Migrations/` with timestamp prefix (`YYYYMMDDHHMMSS_DescriptiveName.php`)
- Base class: `Migrations\BaseMigration` (NOT `AbstractMigration`)
- Foreign keys must have **identical column types** (signed/unsigned mismatch causes errors)
- Use `$this->hasTable()` to prevent failures if table already exists

**Main tables:** `roles`, `users`, `permissions`, `invoices`, `invoice_histories`, `providers`, `operation_centers`, `expense_types`, `cost_centers`, `approvers`, `employees`, `employee_documents`, `approval_tokens`, etc. See ARCHITECTURE.md §11 for full schema.

---

## Invoice Pipeline (Core Module)

**4-state workflow:**
```
aprobacion → contabilidad → tesoreria → pagada
```

**Key entities & services:**
- `Invoice::isRejected()`, `isApproved()`, `isPaid()` — Domain helpers on entity
- `InvoicePipelineService::saveAndAdvance()` — Unified save + state transition with validation
- `InvoicePipelineService::getVisibleSections()` — Controls which form sections each role sees
- `InvoiceHistoryService::recordChanges()` — Field-by-field audit trail with type normalization
- `ApprovalTokenService` — External approval bypass for stakeholders (SHA256 tokens)

**Permission model:**
- Each role sees only its assigned states (`ROLE_VISIBLE_STATUSES`)
- Each role edits only certain fields in its state (`EDITABLE_FIELDS` keyed by role+status)
- Admin sees/edits everything
- Registro/Revisión → `aprobacion` state, Contabilidad → `contabilidad` state, Tesorería → `tesoreria` state

**Transition rules:** Validated in `InvoicePipelineService::validateTransitionRequirements()`. For treasury→paid: requires `payment_status='Pago total'` and non-empty `payment_date`.

---

## Role-Based Access Control (RBAC)

**Roles** (defined in `src/Constants/RoleConstants.php`):
- `Administrador` — Full access
- `Registro/Revisión` — Creates/reviews invoices in aprobacion state
- `Contabilidad` — Reviews accounting fields
- `Tesorería` — Reviews payment fields

**Permission flow:**
1. User logs in → `UsersTable::findAuth()` fetches user with role
2. Before each action → `AppController::beforeFilter()` calls `_enforcePermission()`
3. Checks `permissions` table (`role_id`, `module`, `can_view`/`can_create`/`can_edit`/`can_delete`)
4. Admin bypasses all permission checks

**Module names** are in `AuthorizationService::MODULES` constant. Controllers map to modules via `AppController::$controllerModuleMap`.

---

## Common Patterns

### Custom Finders
Never override `findList()` in CakePHP 5 (incompatible signature). Use custom finders instead:

```php
// In Table class
public function findCodeList(SelectQuery $query, array $options): SelectQuery
{
    return $query->formatResults(fn($results) =>
        $results->combine('id', fn($row) => $row->code . ' - ' . $row->name)
    );
}

// Usage in Controller
$items = $table->find('codeList')->toArray();
```

### Reusable Queries
Extract shared query logic to private `_build*Query()` methods in controllers:

```php
private function _buildInvoiceQuery(array $conditions = []): SelectQuery
{
    $query = $this->Invoices->find()
        ->contain(['Providers', 'OperationCenters', 'Users'])
        ->where($conditions);

    $this->filterService->apply($query, $this->request->getQueryParams());
    return $query;
}
```

### Service Injection
Services use constructor dependency injection with optional defaults:

```php
public function __construct(
    ?InvoiceHistoryService $historyService = null,
    ?NotificationService $notificationService = null,
) {
    $this->historyService = $historyService ?? new InvoiceHistoryService();
    $this->notificationService = $notificationService ?? new NotificationService();
}
```

### Pagination
Hardcoded to **15 items per page**:
```php
public $paginate = ['limit' => 15, 'maxLimit' => 15];
```

### Date Formatting
Use `AppView::formatDateEs()` for Spanish format:
```php
$this->AppView->formatDateEs($invoice->created);  // "Lunes, 17 Febrero 2026"
```

### Constants over Hardcoding
Never use literal strings for domain values in PHP. Use `src/Constants/`:
- `InvoiceConstants::APPROVAL_REJECTED` instead of `'Rechazada'`
- `RoleConstants::ADMIN` instead of `'Administrador'`
- `EmployeeStatusConstants::RETIRADO` instead of `2`

---

## Frontend & Design System

**Design system documented in STYLES.md** — Read before modifying any views.

**Key principles:**
- Borders instead of shadows (no `box-shadow` except sidebar active state)
- 2px top border on stat cards identifies them
- `inset 2px 0` on sidebar active item
- 2px left border on navbar title
- Micro-caps for section labels
- Local Inter Variable font (weights 100–900)
- Bootstrap 5 as base, then custom CSS in `webroot/css/styles.css`

**CSS Load Order** (in layout, MANDATORY):
1. Bootstrap CSS
2. Bootstrap Icons CSS
3. Flatpickr CSS
4. `styles.css` (custom, overrides Bootstrap)

**JavaScript** (end of body):
1. Bootstrap JS
2. Flatpickr JS + locale `es`
3. AutoNumeric JS
4. Select2 JS
5. `sgi-common.js` (plugin initialization)

**Custom Classes:**
- `.sgi-stat-card` — Dashboard counter card
- `.sgi-quick-tile` — Quick access tile
- `.sgi-btn-primary` — Green primary button
- `.sgi-input-group` — Input wrapper with green focus border
- `.sgi-sidebar-logout` — Logout button
- `.flatpickr-date` — Date input (auto-initialized)
- `.currency-input` — COP currency input (AutoNumeric auto-init)
- `.clickable-row` — Clickable table row (needs `data-href` attribute)

---

## File Structure (Key Paths)

```
src/
├── Controller/
│   ├── AppController.php              # Base: perms, sidebar counters
│   ├── InvoicesController.php         # Pipeline + CRUD
│   ├── EmployeesController.php        # HR + document management
│   └── Trait/ExcelCatalogTrait.php    # Reusable Excel export/import
├── Model/
│   ├── Entity/
│   │   ├── Invoice.php                # Domain helpers (isRejected, etc.)
│   │   ├── User.php                   # Auto password hashing
│   │   └── Employee.php               # Contract logic
│   └── Table/
│       ├── InvoicesTable.php          # Associations, validation, finders
│       ├── UsersTable.php             # findAuth() for login
│       └── *Table.php                 # Standard models
├── Service/
│   ├── InvoicePipelineService.php     # 4-state workflow logic
│   ├── InvoiceHistoryService.php      # Audit trail
│   ├── AuthorizationService.php       # RBAC
│   ├── ApprovalTokenService.php       # External approval tokens
│   ├── NotificationService.php        # Email sending
│   ├── EmployeeDocumentService.php    # File uploads
│   ├── InvoiceDocumentService.php     # File uploads
│   ├── ExcelService.php               # XLSX export/import
│   └── [Other domain services]
├── Constants/
│   ├── RoleConstants.php              # Role names
│   ├── InvoiceConstants.php           # States, types, etc.
│   └── EmployeeStatusConstants.php    # Employee statuses
├── Middleware/
│   └── HostHeaderMiddleware.php       # Host header validation (prod)
└── View/
    └── AppView.php                    # formatDateEs() helper

config/
├── routes.php                         # Custom routes
├── bootstrap.php                      # .env loader, plugin setup
├── Migrations/                        # Database migrations
└── Seeds/                             # Seed data

templates/
├── layout/
│   ├── default.php                    # Sidebar + topbar + content
│   ├── login.php                      # Split-panel login
│   ├── external.php                   # Token approval
│   ├── ajax.php                       # AJAX responses
│   └── email/                         # Email templates
├── element/
│   ├── pipeline_progress.php          # Pipeline visual
│   ├── pagination.php                 # Pagination component
│   └── catalog_excel_buttons.php      # Export/import buttons
└── [Per-controller folders]

webroot/
├── css/
│   └── styles.css                     # Complete design system
├── js/
│   ├── sgi-common.js                  # Plugin initialization
│   └── leave-template-editor.js       # Leave template editor
├── fonts/
│   └── Inter-Variable.ttf             # Inter font (100–900)
└── uploads/                           # User-uploaded files
```

---

## Authentication & Session Management

- Plugin: `cakephp/authentication ^3.0`
- Authenticators: Session + Form
- Identifier: Password with bcrypt
- Custom finder: `UsersTable::findAuth()` filters `active=true`, loads user with roles
- Middleware order: ErrorHandler → HostHeader → Asset → Routing → **Authentication** → BodyParser → CSRF
- Login route: `/login` (UsersController::login)
- Logout route: `/logout` (UsersController::logout)
- Redirect target if unauthenticated: `/login`

---

## Important Conventions

### When Adding a New Module

1. **Controller**
   - Extend `AppController`
   - Add entry to `$controllerModuleMap` in AppController
   - Set `public $paginate = ['limit' => 15, 'maxLimit' => 15]`
   - Initialize services in `initialize()`
   - Extract shared queries to `_build*Query()` methods

2. **Model**
   - Create Entity with `$_accessible` and domain helpers if needed
   - Create Table with associations, validation, behaviors
   - Use constant references in validators (`inList(InvoiceConstants::STATUSES)`)
   - Add `TimestampBehavior`
   - Implement custom finders if needed (use `findCodeList()` pattern, not `findList()`)

3. **Service** (if complex business logic)
   - Create in `src/Service/`
   - Inject dependencies via constructor with optional defaults
   - No direct request/response access
   - Delegate to existing services to avoid duplication

4. **Constants** (if new domain values)
   - Create in `src/Constants/`
   - Use `final class` with `public const`
   - Reference from services, tables, controllers

5. **Templates**
   - Follow STYLES.md for visual consistency
   - Use element `pagination.php` for paginated lists
   - Use `.flatpickr-date`, `.currency-input`, `.clickable-row` classes
   - Use `AppView::formatDateEs()` for dates

6. **Permissions**
   - Add module to `AuthorizationService::MODULES`
   - Add mapping in `AppController::$controllerModuleMap`
   - Configure permissions in `permissions` table by role

7. **Migrations**
   - Use `Migrations\BaseMigration` as base class
   - Prefix: `YYYYMMDDHHMMSS_DescriptiveName.php`
   - Foreign keys: ensure identical column types
   - Protection: `if ($this->hasTable('invoices')) { ... }`
   - Default Language: English 

8. **Routes** (if custom routes needed)
   - Add before `$builder->fallbacks()` in `config/routes.php`
   - Pattern: `/{controller-dashed}/{action-dashed}/{id}`
   - Constraints: `['id' => '\d+', 'pass' => ['id']]`

---

## Debugging & Testing

```bash
# PHPUnit
composer test                          # Run all tests
composer test -- tests/TestCase.php    # Single test file
composer test -- --filter testMethod   # Single test method

# PHP Code Sniffer
composer cs-check                      # Check style
composer cs-check src/                 # Check only src/
composer cs-fix                        # Auto-fix issues

# Debug server mode (with toolbar)
bin/cake server
# Visit http://localhost:8765 → Debug Kit toolbar in bottom-right
```

---

## Key Dependencies

| Package | Version | Use |
|---------|---------|-----|
| cakephp/cakephp | 5.3.* | Framework |
| cakephp/authentication | ^3.0 | Session + form login |
| cakephp/migrations | ^5.0 | Database versioning |
| phpoffice/phpspreadsheet | ^2.0 \| ^3.0 | Excel export/import |
| tecnickcom/tcpdf | ^6.10 | PDF generation |
| setasign/fpdi | ^2.6 | PDF manipulation |
| mobiledetect/mobiledetectlib | ^4.8 | Device detection |

---

## Environment Setup

`.env` file in project root (loaded in `config/bootstrap.php`):

```env
DATABASE_URL=mysql://user:pass@host:3306/sgi_db
DEBUG=true
APP_NAME=SGI
```

The `.env` is loaded early in bootstrap and credentials are read from there, NOT hardcoded in app config.

---

## See Also

- **ARCHITECTURE.md** — Detailed architecture, all tables, service responsibilities, patterns
- **STYLES.md** — Design system, CSS variables, components, typography, color palette
- **config/routes.php** — Custom route definitions
- **src/Constants/** — Domain constants (never hardcode values)

---

## Notes for Future Developers

1. **Always read ARCHITECTURE.md and STYLES.md first** for detailed guidance on your specific task
2. **Use constants** — If you're typing a string like `'aprobacion'` or `'Administrador'`, it should be a constant in `src/Constants/`
3. **Extract to services** — Complex business logic belongs in `src/Service/`, not controllers
4. **Custom finders** — Use the `findCodeList()` pattern, NOT `findList()` override
5. **Pagination is fixed at 15** — Don't change it without explicit product decision
6. **Migrations are timestamped** — Always use `bin/cake migrations create` for new migrations
7. **Permission checks are automatic** — AppController enforces them; trust the framework
8. **Invoice history is normalized** — `InvoiceHistoryService` handles type conversion automatically
9. **Frontend has a design system** — Use classes like `.sgi-stat-card`, `.sgi-btn-primary` instead of Bootstrap utilities
10. **Flatpickr, AutoNumeric, Select2 auto-initialize** — Just add the right CSS class (`.flatpickr-date`, `.currency-input`, `.select2`)

