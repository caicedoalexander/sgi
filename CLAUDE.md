# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SGI (Sistema de Gestión Interna) — internal management system built with CakePHP 5.3 on PHP 8.2+. Manages invoices (5-state pipeline), employees, petty cash, legalizations, novelties, payment schedulings, and catalog modules. MySQL/MariaDB backend. Spanish-language UI.

## Commands

```bash
# Dev server
php bin/cake server                  # localhost:8765

# Dependencies
composer install

# Tests — NO se usan en este proyecto (ver "Testing Policy")
# composer test                      # disponible pero no se ejecuta
# composer check                     # disponible pero no se ejecuta

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

Sistema de diseño: ver `.claude/rules/design.md`.

### Layer Summary

- **Controller** → HTTP concerns, input validation, delegates to services. One per resource, extends `AppController`.
- **Service** (`src/Service/`) → Business logic, state transitions, DB transactions. Retornan `ServiceResult`.
- **Table/Entity** (`src/Model/`) → ORM associations, validation rules, custom finders.
- **Constants** (`src/Constants/`) → Domain values (states, roles, types). Never hardcode strings like `'Rechazada'` — use constants.
- **Templates** (`templates/`) → PHP views. Layouts: `default.php` (authenticated), `login.php` (split-panel), `external.php` (approval tokens).

### Key Services

| Service | Purpose |
|---------|---------|
| `InvoicePipelineService` | 5-state workflow: aprobacion → contabilidad → tesoreria → autorizacion_pago → pagada |
| `InvoiceFieldAccessPolicy` | Editable fields and visible sections per role/state (extracted from pipeline service) |
| `InvoicePaymentService` | Payment registration, authorization, partial payment recalculation. `registerPayment()` siempre avanza la factura a `autorizacion_pago`. `editPayment()` requiere motivo. `rejectPayment()` persiste `rejection_reason` (no elimina) |
| `InvoiceApprovalService` | Invoice approval operations. `sendApprovalLinks()`, `modifyApprovers()` (con motivo obligatorio), `resetFlow()` cuando `area_approval='Rechazada'` |
| `GroupedInvoiceService` | Grouped invoice batch operations |
| `NoveltyPipelineService` | Novelty state workflow (similar pattern to invoices) |
| `PaymentSchedulingPipelineService` | Payment scheduling pipeline: borrador → tesoreria → aut_pago → pagada |
| `PaymentSchedulingService` | Payment scheduling records management |
| `AuthorizationService` | RBAC via `permissions` table. Admin bypasses all. |
| `InvoiceHistoryService` | Field-by-field audit trail in `invoice_histories` |
| `ApprovalTokenService` | External approval via SHA256 tokens (48h TTL) |
| `NotificationService` | Email para links de aprobación (facturas y novedades) y prueba SMTP |
| `AdvanceLegalizationService` | Advance legalization workflow |
| `RefundService` | Refund records and outcomes |
| `PettyCashService` | Petty cash records management |
| `DianCrosscheckService` | DIAN crosscheck validation |
| `N8nService` | n8n workflow integration |
| `WebhookService` | Outbound webhook dispatch (circuit breaker enabled) |
| `SystemSettingsService` | Application-wide configuration |
| `SidebarCounterService` | Badge counters for pending items in sidebar nav |
| `StructuredLogger` | Structured logging with correlation IDs (via `CorrelationIdMiddleware`) |
| `CircuitBreaker` | Circuit breaker pattern — used by WebhookService and NotificationService |
| `DashboardStatisticsService` | Coordinates dashboard stats; delegates to `Dashboard/` sub-services |

### Service Subdirectories

- `Service/Interface/` — `HistoryServiceInterface`, `MailerInterface`, `SpreadsheetReaderInterface`
- `Service/Adapter/` — `CakeMailerAdapter`, `PhpSpreadsheetAdapter` (adapter pattern for external libs)
- `Service/Strategy/` — `InvoiceApprovalStrategy`, `NoveltyApprovalStrategy` (strategy pattern for approval logic)
- `Service/Trait/` — `DocumentUploadTrait`, `HistoryNormalizationTrait`
- `Service/Dashboard/` — `EmployeeStatisticsService`, `InvoiceStatisticsService`
- `Service/Pipeline/` — State pattern del pipeline de facturas (`InvoicePipelineState`, `InvoicePipelineStateRegistry`, `Policy/`, `State/`, `LinkedInvoiceLegalizer`)
- `Service/HealthCheck/` — `HealthCheckInterface` + implementaciones (`Database`, `Cache`, `EmailLog`, `CircuitBreaker`)
- `Service/Resilience/` — `Retryer`, `RetryPolicy` (retry con backoff)
- `Service/Subscriber/` — Event subscribers (`LegalizationInitializerSubscriber`, `LinkedInvoicesPromoterSubscriber`, `RefundOutcomeSubscriber`)

### Middlewares

Located in `src/Middleware/`:
- `CorrelationIdMiddleware` — Injects/propagates `X-Correlation-ID` header; used by `StructuredLogger`
- `RateLimitMiddleware` — Rate limiting per IP/route
- `HostHeaderMiddleware` — Host header validation

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
- Facturas rechazadas (`area_approval='Rechazada'`) bloquean todo avance; Registro puede `resetFlow` para reiniciar
- En `autorizacion_pago` el Contador autoriza/rechaza cada pago; al quedar todos autorizados, la factura puede avanzar a `pagada`. Los soportes de pago se cargan como documentos normales del pipeline en `tesoreria` (`InvoiceDocuments`)
- Facturas en `pagada` redireccionan a `view` para no-admins
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

## Testing Policy

**Este proyecto NO usa tests automatizados.** No agregar archivos en `tests/`, no proponer fixtures de PHPUnit, no incluir secciones de "testing strategy" en specs/plans, no recomendar TDD.

La validación se hace de forma manual: levantar `php bin/cake server` y ejercitar los endpoints en el navegador o con `curl`. Los specs/plans deben sustituir la sección de tests por **criterios de validación manual** (pasos concretos a ejecutar tras el merge).

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
