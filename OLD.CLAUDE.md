# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SGI (Sistema de Gestión Interna) — CakePHP 5.3 invoice management system for Compañía Operadora Portuaria Cafetera S.A. Invoices flow through a role-based pipeline: **revision → area_approved → accrued → treasury → paid**. Each role controls specific pipeline states and field editability.

## Commands

```bash
# Dev server
bin/cake server -p 8765

# Migrations
bin/cake migrations migrate
bin/cake migrations create NombreMigracion   # generates Migrations\BaseMigration (NOT AbstractMigration)
bin/cake migrations rollback

# Code style (CakePHP ruleset via phpcs.xml)
composer cs-check          # phpcs --colors -p
composer cs-fix            # phpcbf --colors -p

# Tests
composer test                                                    # all tests
vendor/bin/phpunit tests/TestCase/Controller/InvoicesControllerTest.php  # single test

# Bake
bin/cake bake controller Name
bin/cake bake model Name
bin/cake bake template Name

# Docker (production)
docker-compose up --build   # PHP 8.4-FPM + Nginx on port 80
```

## Architecture

### Request Lifecycle
`Application.php` middleware stack: ErrorHandler → HostHeader → Asset → Routing → **Authentication** → BodyParser → CSRF → Controller

### Authentication (`cakephp/authentication:^3.0`)
- Session + Form authenticators configured in `Application::getAuthenticationService()`
- `UsersTable::findAuth()` custom finder: filters `active=true`, contains `Roles`
- Routes: `/login` → `Users::login`, `/logout` → `Users::logout`
- No authorization plugin — permissions are checked manually via `AppController::_checkPermission()`

### Authorization System
`AuthorizationService` checks the `permissions` table (role_id × module × CRUD). Admin role bypasses all checks. Each controller action calls `_checkPermission(module, action)` from `AppController`.

### Invoice Pipeline (core business logic)
`InvoicePipelineService` defines:
- `STATUSES` — ordered pipeline states
- `TRANSITIONS` — which state follows which (`revision→area_approved→accrued→treasury→paid`)
- `EDITABLE_FIELDS` — per-role, per-status field access control
- `filterEntityData()` — strips fields the current role cannot edit
- Advance route: `POST /invoices/advance-status/{id}`

`InvoiceHistoryService` tracks field-level changes in `invoice_histories` (field_changed, old_value, new_value).

### Roles (DB IDs)
| ID | Name | Visible Pipeline States |
|----|------|------------------------|
| 1 | Admin | All states |
| 2 | Contabilidad | area_approved, accrued |
| 3 | Tesorería | treasury |
| 5 | Registro/Revisión | revision |

### Key Patterns & Gotchas
- **Custom finders**: Never override `findList()` (signature mismatch in CakePHP 5). Use custom finders like `findCodeList()` with `formatResults` + `combine`
- **Sidebar counters**: `AppController::_setSidebarCounters()` runs on every request for logged-in users
- **Migration base class**: Always use `Migrations\BaseMigration`, not `AbstractMigration`
- **FK columns**: Must match types exactly (signed/unsigned) with referenced tables
- **Views**: Plain PHP templates in `templates/`. Layouts: `default.php`, `login.php`
- **Frontend libs** (CDN in default layout): Flatpickr (`.flatpickr-date`), AutoNumeric (`.currency-input`), Bootstrap Icons
- **JS**: `webroot/js/sgi-common.js` — clickable table rows (`.clickable-row[data-href]`), date pickers, currency formatting

### Configuration
- `.env` in project root (not `config/`), loaded in `config/bootstrap.php`
- Database via `DATABASE_URL` env var (MySQL/MariaDB)
- `config/app_local.php` for local overrides (DB, debug)
- Root route `/` maps to `Invoices::index`
