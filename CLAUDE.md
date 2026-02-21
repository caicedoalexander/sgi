# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**SGI — Sistema de Gestión Integrada** is a CakePHP 5.3 application for comprehensive business management including invoicing, employee management, provider management, and administrative controls.

### Key Technologies
- **Framework**: CakePHP 5.3
- **Language**: PHP 8.2+
- **Database**: MySQL/MariaDB
- **Frontend**: Bootstrap 5 + custom CSS design system (see STYLES.md)
- **Testing**: PHPUnit 11.x/12.x/13.x
- **Code Quality**: PHPCodeSniffer, PHPStan, Psalm

---

## Getting Started

### Setup
1. Copy `.env` file (already configured for development)
2. Install dependencies: `composer install`
3. Run database migrations: `bin/cake migrations migrate`
4. Start Docker container (optional): `docker-compose up -d`

### Common Development Commands

```bash
# Run all tests
composer test

# Run tests for a specific file
vendor/bin/phpunit tests/TestCase/Controller/InvoicesControllerTest.php

# Check code style
composer cs-check

# Fix code style automatically
composer cs-fix

# Run all checks (tests + code style)
composer check
```

### Development Server
- Built-in PHP server: `php -S localhost:8000 -t webroot`
- Docker: `docker-compose up` (port 80)

---

## Architecture

### Directory Structure

```
src/
├── Application.php              # App configuration
├── Constants/                   # Enum-like constants (RoleConstants, etc.)
├── Controller/                  # Request handlers
│   ├── AppController.php        # Base controller with auth & permission logic
│   ├── InvoicesController.php   # Invoice CRUD & approval workflow
│   └── [ModuleController].php   # 15+ module controllers
├── Middleware/                  # HostHeaderMiddleware (security)
├── Model/
│   ├── Entity/                  # Value objects (e.g., User, Invoice)
│   ├── Table/                   # ORM models (e.g., InvoicesTable, UsersTable)
│   └── Behavior/                # Reusable model traits
├── Service/                     # Business logic (AuthorizationService, etc.)
└── View/                        # View helpers

templates/                        # HTML/PHP templates by module
├── layout/
│   ├── default.php              # Main layout (sidebar + topbar)
│   └── login.php                # Login split-panel layout
├── element/                     # Reusable components
├── [ModuleFolder]/              # One folder per module (Invoices/, Users/, etc.)
└── error/                       # Error pages
```

### Core Components

#### Authentication & Authorization (src/Controller/AppController.php)
- Uses `Authentication.Authentication` component (CakePHP plugin)
- Role-based access control via permissions table
- `_enforcePermission()` checks user access before each action
- Permission mapping: `$controllerModuleMap` maps controller names to permission modules
- Action mapping: `_actionToPermission()` converts CakePHP actions (index, view, add, edit, delete) to permission strings

#### Key Tables
- **Invoices**: Main document with approval workflow (draft → pending → approved → paid)
- **Employees**: Staff records with documents and folders
- **Providers**: Vendor/supplier records
- **Users**: System users with roles and permissions
- **Roles**: Permission groups (e.g., admin, approver, viewer)
- **Approvers**: Assigned approval workflows per invoice type/user

#### Permission System
- Permissions stored per role in database
- Structure: `role_id`, `module`, `action` (view/add/edit/delete)
- Admin role bypasses all checks
- Non-admin users denied access if not authorized

---

## Design System (STYLES.md)

The project uses a custom **border-based design language** instead of shadows. Key rules:

### Visual Language
- **2px colored border-top** on stat cards (`.sgi-stat-card`)
- **Inset border-left** for active sidebar items
- **Green border-left** on navbar title (context indicator)
- **2px green border-left** dividing login panels

### Colors (CSS Variables in webroot/css/styles.css)
```
--primary-color: #469D61        (Green — Invoices, Employees)
--secondary-color: #CD6A15      (Orange — Providers)
--bg-dark: #212529              (Sidebar background)
--background-color: #f5f5f5     (Content area)
--border-color: #e0e0e0         (Neutral borders)
```

### Key Components
- **Forms**: `.sgi-input-group` (border on container, not inputs)
- **Buttons**: `.sgi-btn-primary` (green, no border-radius)
- **Cards**: `.sgi-stat-card`, `.sgi-quick-tile` (top border as accent)
- **Sidebar**: Dark with inset left borders for active items

### Important CSS Rules
- ❌ Avoid `box-shadow`, `border-radius` (except 2px max), `rounded-3`, `shadow-*`
- ✅ Use borders for visual hierarchy, letter-spacing for emphasis, custom variables

### Font
- **Typeface**: Inter Variable (local, in `webroot/fonts/`)
- **Common sizes**: 1.8rem (h1), 2.4rem (stat numbers), 0.875rem (nav links), 0.58rem (micro-caps labels)
- **Micro-caps**: All uppercase with `letter-spacing: .12em` for small labels

---

## Database

### Connection
- Defined in `config/app.php` using environment variables from `.env`
- Current: MariaDB on `easypanel.alexandercaicedo.dev:2705`

### Migrations
Located in `config/Migrations/`. To create a new migration:
```bash
bin/cake bake migration CreateInvoiceApprovals
```

### Schema
Run migrations to create tables. Key entities include:
- invoices, invoice_histories
- employees, employee_documents, employee_folders, employee_statuses
- providers, users, roles, approvers, permissions
- cost_centers, operation_centers, expense_types
- education_levels, positions, marital_statuses, default_folders

---

## Common Workflows

### Adding a New Module (CRUD)
1. Create database table via migration
2. Generate model: `bin/cake bake model [TableName]`
3. Generate controller: `bin/cake bake controller [ModuleName]`
4. Create templates in `templates/[Module]/` (index, add, edit, view)
5. Add permission mapping to `AppController::$controllerModuleMap`
6. Assign permissions to roles in database

### Modifying Styles
1. Edit `webroot/css/styles.css` (CSS variables, components)
2. Follow border-based design rules (see STYLES.md)
3. Never use Bootstrap utility classes that contradict design system (e.g., `shadow-lg`, `rounded-3`)

### Adding Form Fields
1. Create field in template using `.sgi-input-group` wrapper
2. Set form data via controller: `$this->set('record', $entity)`
3. Validation handled in Table model via `validationDefault()`

### Approval Workflow
Invoices follow status progression: draft → pending → approved → paid
- `ApproversTable` stores approval rules per role/user
- `InvoiceHistoriesTable` logs state changes
- See `InvoicesController::advanceStatus()` for state machine logic

---

## Code Style

### PHP
- PSR-12 (enforced via PHPCodeSniffer)
- Run `composer cs-fix` to auto-correct
- CakePHP conventions: camelCase methods, snake_case database columns

### SQL
- Table names: plural, snake_case (e.g., `invoices`, `employee_statuses`)
- Foreign keys: `{table}_id` (e.g., `user_id`, `invoice_id`)
- Timestamps: `created`, `modified` (CakePHP standard)

### Template Variables
- Pass data via `$this->set('varName', $value)` in controller
- Access via `$varName` in templates
- Sidebar data: `$currentUser`, `$userPermissions` (set in AppController)

---

## Testing

### Structure
- Test files in `tests/TestCase/` mirror `src/` structure
- Fixtures (test data) in `tests/Fixture/`

### Running Tests
```bash
composer test                    # All tests
composer test -- --filter=Invoice   # Specific class
composer test -- tests/TestCase/Model/Table/InvoicesTableTest.php  # Single file
```

### PHPUnit Configuration
- Defined in `phpunit.xml.dist`
- Bootstrap: `tests/bootstrap.php`
- Uses CakePHP fixture extension for database isolation

---

## Important Files & Patterns

| File | Purpose |
|------|---------|
| `src/Controller/AppController.php` | Authentication, authorization, sidebar counters, permission checks |
| `src/Service/AuthorizationService.php` | Central authorization logic (role checking) |
| `config/routes.php` | URL routing configuration |
| `templates/layout/default.php` | Main layout with sidebar + topbar |
| `templates/layout/login.php` | Login page layout (split-panel design) |
| `webroot/css/styles.css` | CSS variables, custom components, design system |
| `webroot/js/sgi-common.js` | Shared JS: Flatpickr (date picker), AutoNumeric, clickable rows |
| `STYLES.md` | Complete design system reference (read before styling) |

---

## Git Workflow

Recent commits show active development on:
- UI/style refactoring (button styles, form labels, Bootstrap component customization)
- Feature additions (Select2 support, flash notifications, active approver filtering)
- Layout and component improvements

When committing:
- Use imperative form: "Refactor X" or "Fix Y" not "Fixed Y" or "Refactoring X"
- Reference module/feature: "feat: Add Select2 to approver dropdown" not "Update file"
- Spanish or English are both acceptable (project uses both)

---

## Troubleshooting

### Database Connection Issues
- Check `.env` file for correct host, port, username, password
- Ensure database exists: `CREATE DATABASE bd_sgi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
- Run migrations: `bin/cake migrations migrate`

### Permission Denied on Actions
- Check `AppController::_enforcePermission()` flow
- Verify user role and permissions in database
- Admin role always has access (check `AuthorizationService::ROLE_ADMIN`)

### Template Not Rendering
- Confirm template exists at `templates/[Controller]/[action].php`
- Check controller passes required variables via `$this->set()`
- Verify layout is correct (default.php vs login.php)

### CSS Not Applying
- Clear browser cache (Ctrl+Shift+Del)
- Ensure custom CSS in `styles.css` comes AFTER Bootstrap import (check `templates/layout/default.php`)
- Use `--primary-color` variables instead of hardcoded colors

---

## Development Notes

- Project is actively being styled according to STYLES.md design system
- Focus on consistency: borders > shadows, micro-caps for labels, green accents
- Database-driven permissions: all role/module/action combos stored in DB
- CakePHP ORM is fully utilized: use eager loading (`contain()`) to avoid N+1 queries
- Select2 and Flatpickr are integrated for enhanced form UX