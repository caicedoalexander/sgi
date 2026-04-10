# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SGI (Sistema de Gestión Interna) — internal management system built with CakePHP 5.3 on PHP 8.2+. Manages invoices (4-state pipeline), employees, petty cash, legalizations, novelties, and catalog modules. MySQL/MariaDB backend. Spanish-language UI.

## Commands

```bash
# Dev server
php bin/cake server                  # localhost:8765

# Dependencies
composer install

# Tests
composer test                        # PHPUnit
composer check                       # test + cs-check

# Code style (CakePHP standard)
composer cs-check                    # Check
composer cs-fix                      # Auto-fix

# Database migrations
php bin/cake migrations migrate      # Run pending
php bin/cake migrations rollback     # Rollback last
php bin/cake migrations create Name  # New migration (uses BaseMigration, NOT AbstractMigration)

# Admin seed
php bin/seed-admin.php               # username: admin, pass: Admin2024*
```

## Environment Setup

```bash
# .env file lives at project root (not config/)
# Required variables:
DATABASE_URL=mysql://user:pass@host:3306/sgi_db
```

The dotenv loader is enabled in `config/bootstrap.php` (~line 69) pointing to `ROOT . DS . '.env'`.

## Architecture

See `ARCHITECTURE.md` for full details. See `STYLES.md` for design system rules.

### Layer Summary

- **Controller** → HTTP concerns, input validation, delegates to services. One per resource, extends `AppController`.
- **Service** (`src/Service/`) → Business logic, state transitions, DB transactions. ~36 services. Retornan `ServiceResult`.
- **Table/Entity** (`src/Model/`) → ORM associations, validation rules, custom finders.
- **Constants** (`src/Constants/`) → Domain values (states, roles, types). Never hardcode strings like `'Rechazada'` — use constants.
- **Templates** (`templates/`) → PHP views. Layouts: `default.php` (authenticated), `login.php` (split-panel), `external.php` (approval tokens).

### Key Services

| Service | Purpose |
|---------|---------|
| `InvoicePipelineService` | 5-state workflow: aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada |
| `InvoicePaymentService` | Payment registration, authorization, partial payment recalculation |
| `NoveltyPipelineService` | Novelty state workflow (similar pattern to invoices) |
| `AuthorizationService` | RBAC via `permissions` table. Admin bypasses all. |
| `InvoiceHistoryService` | Field-by-field audit trail in `invoice_histories` |
| `ApprovalTokenService` | External approval via SHA256 tokens (48h TTL) |
| `NotificationService` | Email on state transitions |
| `LegalizationService` | Legalization records business logic |
| `PettyCashService` | Petty cash records management |
| `DianCrosscheckService` | DIAN crosscheck validation |
| `N8nService` | n8n workflow integration |
| `WebhookService` | Outbound webhook dispatch |
| `SystemSettingsService` | Application-wide configuration |

### Auth & Permissions

- Plugin: `cakephp/authentication ^3.0`. Custom finder `UsersTable::findAuth()` (active=true, contain Roles).
- RBAC enforced in `AppController::beforeFilter()` via `_enforcePermission()`.
- `$controllerModuleMap` maps controller → module. Actions map to can_view/can_create/can_edit/can_delete.
- Roles (ver `RoleConstants.php`): Administrador, Contabilidad, Tesorería, Registro/Revisión, Contador, Auxiliar de Personal, Asistente de Personal, Coordinador Administrativo y Financiero.
- **Contador** ve y autoriza pagos en estado `autorizacion_pago` del pipeline de facturas.

### Invoice Pipeline

States: `aprobacion` → `contabilidad` → `tesoreria` → `autorizacion_pago` → `pagada` (5 estados).
- Tesorería registra pagos → avanza a `autorizacion_pago` (requiere ≥1 pago pendiente vía `InvoicePaymentService`)
- Contador autoriza en `autorizacion_pago` → avanza a `pagada`
- Pago parcial tras autorización → **regresa automáticamente** a `tesoreria`
- Facturas rechazadas (`area_approval='Rechazada'`) bloquean todo avance
- Secciones del formulario: `general`, `dates`, `classification`, `revision`, `accounting`, `treasury`, `payment_authorization`

## Key Conventions

- **`ServiceResult`:** Servicios retornan `ServiceResult::ok($data)` / `ServiceResult::fail($errors)`. Verificar `->success` antes de usar `->data`.
- **Pagination:** Fixed 15 items per page across all controllers.
- **Custom finders:** Don't override `findList()` in CakePHP 5 (incompatible signature). Use custom finders like `findCodeList()`.
- **Private methods:** Prefixed with underscore: `_buildInvoiceQuery()`.
- **Services get tables via:** `TableRegistry::getTableLocator()->get('TableName')`, never `$this->TableName`.
- **Dependency injection:** Constructor with nullable params and `?? new ServiceClass()` fallback.
- **CSS class prefix:** `.sgi-` for custom classes (`.sgi-stat-card`, `.sgi-btn-primary`, etc.).
- **CSS load order:** Bootstrap → Bootstrap Icons → Flatpickr → `styles.css` (always this order).
- **JS auto-init classes:** `.flatpickr-date` (datepicker), `.currency-input` (AutoNumeric COP), `.select2` (searchable dropdown), `.clickable-row` (row click via `data-href`).
- **Routes:** Custom routes go before `$builder->fallbacks()` in `config/routes.php`.

## Migration Gotchas

- Base class is `Migrations\BaseMigration` (NOT `AbstractMigration`).
- Use `$this->hasTable()` before create/drop to handle partial failures.
- FK column types must match exactly (signed/unsigned) with referenced tables.

## Frontend

- Font: Inter Variable (local, `webroot/fonts/Inter-Variable.ttf`).
- Design: Borders instead of shadows. 2px top border on stat cards. No box-shadow.
- Colors: dark (#212529), green (#469D61), orange (#CD6A15).
- JS common: `webroot/js/sgi-common.js` auto-initializes Flatpickr, AutoNumeric, Select2.
- PDF: TCPDF + FPDI. Excel: PhpSpreadsheet.
- Pipeline elements: `pipeline_progress.php` (facturas), `legalization_progress.php`, `petty_cash_progress.php`. Todos aceptan `isRejected` (bool).

## New Module Checklist

1. Migration → `bin/cake migrations create`
2. Constants → `src/Constants/{Domain}Constants.php`
3. Entity → `src/Model/Entity/`
4. Table → `src/Model/Table/` (associations, validation, finders)
5. Service → `src/Service/` (business logic)
6. Controller → `src/Controller/` (extends AppController)
7. Permissions → Add to `$controllerModuleMap` (AppController), `AuthorizationService::MODULES` (key=slug, value=nombre display), `permissions` table
8. Templates → `templates/{Controller}/` (index, add, edit, view)
9. Sidebar → Add nav-link in `templates/layout/default.php` under the correct section
10. Routes → Custom routes before `$builder->fallbacks()` if needed
