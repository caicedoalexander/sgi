# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SGI (Sistema de Gestión Interna) — internal management system built with CakePHP 5.3 on PHP 8.4+ (`composer.json` requires `>=8.4`). Manages invoices (6-state pipeline + estado terminal `legalizada`), employees, petty cash, legalizations, novelties, payment schedulings, refunds, advances, and catalog modules. MySQL/MariaDB backend. Spanish-language UI.

## Commands

```bash
# Dev server
php bin/cake server                  # localhost:8765

# Dependencies
composer install

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

Sistema de diseño: ver la sección [Sistema de Diseño](#sistema-de-diseño) más abajo.

### Layer Summary

- **Controller** → HTTP concerns, input validation, delegates to services. One per resource, extends `AppController`.
- **Service** (`src/Service/`) → Business logic, state transitions, DB transactions. Retornan `ServiceResult`.
- **Table/Entity** (`src/Model/`) → ORM associations, validation rules, custom finders.
- **Constants** (`src/Constants/`) → Domain values (states, roles, types). Never hardcode strings like `'Rechazada'` — use constants.
- **Templates** (`templates/`) → PHP views. Layouts: `default.php` (authenticated), `login.php` (split-panel), `external.php` (approval tokens), `ajax.php` (respuestas AJAX sin chrome), `error.php` (páginas de error).
- **Constants/Domain/** (`src/Constants/Domain/{Modulo}/PipelineStatus.php`) → Enums fuente única de los estados de pipeline por módulo (Invoice, Novelty, Advance, PettyCash, Refund, PaymentScheduling). Las constantes string en `*Constants` delegan a estos enums por retrocompatibilidad.
- **Events** (`src/Event/`) → `InvoicePaidEvent`, `AdvanceLegalizedEvent`, `InvoiceRefundAuthorizedEvent`, `InvoiceRefundRejectedEvent`. Disparados desde States del pipeline; suscriptores en `Service/Subscriber/`.

### Key Services

| Service | Purpose |
|---------|---------|
| `InvoicePipelineService` | Coordinador delgado del pipeline de facturas (6 estados). Delega a `InvoicePipelineStateRegistry`, `DocumentTypePolicyFactory`, `InvoiceLockPolicy`, `InvoiceTransitionValidator` y `PipelineAuthorizationService`. API pública preservada. |
| `InvoiceFieldAccessPolicy` | Editable fields and visible sections per role/state (extracted from pipeline service) |
| `InvoiceLockPolicy` | Determina qué campos quedan bloqueados según estado/documento |
| `InvoiceTransitionValidator` | Valida requisitos de avance entre estados del pipeline de facturas |
| `InvoicePaymentService` | Payment registration, authorization, partial payment recalculation. `registerPayment()` siempre avanza la factura a `autorizacion_pago`. `editPayment()` requiere motivo. `rejectPayment()` persiste `rejection_reason` (no elimina) |
| `InvoiceApprovalService` | Invoice approval operations. `sendApprovalLinks()`, `modifyApprovers()` (con motivo obligatorio), `resetFlow()` cuando `area_approval='Rechazada'` |
| `InvoiceFilterService` / `EmployeeFilterService` | Filtros de listados (extends `Filter/BaseFilterService`) |
| `GroupedInvoiceService` | Grouped invoice batch operations |
| `NoveltyPipelineService` | Workflow del pipeline de novedades (delega a `NoveltyPipelineStateRegistry`) |
| `PaymentSchedulingPipelineService` | Payment scheduling pipeline (5 estados: borrador → tesoreria → autorizacion_pago → verificacion_pago → pagada) y management de registros |
| `AdvanceLegalizationService` | Pipeline de legalizaciones de anticipos (validacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → revision_firmas → legalizada) |
| `RefundPipelineService` | Pipeline de reintegros (agrupacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada) y outcomes |
| `RefundPaymentService` | Registro/edición/rechazo de pagos individuales del módulo de reintegros |
| `PettyCashPipelineService` | Pipeline de caja menor (agrupacion → contabilidad → tesoreria → autorizacion_pago → verificacion_pago → pagada) |
| `LiquidationDocPaymentService` | Pagos de documentos de liquidación de novedades |
| `PaymentRegistryService` | Vista consolidada del registro de pagos cross-módulo |
| `AuthorizationService` | RBAC via `permissions` table. Admin **solo** bypassa los módulos en `ADMIN_BYPASS_MODULES = ['users', 'roles']`; en los demás módulos el rol Administrador pasa por el lookup normal en la tabla `permissions`. |
| `PipelineAuthorizationService` | Autoriza si un rol puede operar (avanzar/regresar/editar) un paso de un pipeline (`pipeline_permissions`). Espejo del `AuthorizationService` para steps de pipeline. |
| `InvoiceHistoryService` / `EmployeeHistoryService` / `PettyCashHistoryService` / `RefundHistoryService` / `AdvanceLegalizationHistoryService` / `NoveltyHistoryService` | Audit trail field-by-field por dominio |
| `*DocumentService` (`InvoiceDocumentService`, `EmployeeDocumentService`, `PettyCashDocumentService`, `RefundDocumentService`, `AdvanceLegalizationDocumentService`, `PaymentSchedulingDocumentService`, `NoveltyDocumentService`, `LeaveDocumentService`) | Gestión de uploads/eliminación de documentos por dominio (usan `Trait/DocumentUploadTrait`) |
| `LeaveSignatureService` / `NoveltySignatureService` | Firmas de documentos de licencias y novedades |
| `NoveltyObservationService` | Observaciones del pipeline de novedades |
| `ApprovalTokenService` | External approval via SHA256 tokens (48h TTL) |
| `NotificationService` | Email para links de aprobación, notificaciones del pipeline y prueba SMTP. Usa `CircuitBreaker`. |
| `PendingNotificationsService` | Cálculo de notificaciones pendientes para el sidebar/badges |
| `EmailLogService` | Persistencia y consulta del log de correos enviados (`email_logs`) |
| `DianCrosscheckService` | DIAN crosscheck validation |
| `CodeGeneratorService` | Generación de códigos secuenciales por dominio |
| `ExcelService` / `ExcelImportService` / `ExcelMappingService` / `PaymentSchedulingImportService` | Lectura/escritura/import de hojas Excel (usan `Adapter/PhpSpreadsheetAdapter`) |
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
- `Service/Strategy/` — `ApprovalStrategyInterface`, `InvoiceApprovalStrategy`, `NoveltyApprovalStrategy` (strategy pattern para lógica de aprobación externa)
- `Service/Trait/` — `DocumentUploadTrait`, `HistoryNormalizationTrait`
- `Service/Filter/` — `BaseFilterService` (clase base para filtros de listados)
- `Service/Dto/` — `BulkPaymentView` (DTOs ligeros)
- `Service/Dashboard/` — `EmployeeStatisticsService`, `InvoiceStatisticsService`
- `Service/Pipeline/` — State pattern por módulo. Cada submódulo expone `{Modulo}PipelineState` (interfaz/abstracta), `{Modulo}PipelineStateRegistry` (resuelve enum → State) y `State/` con un archivo por estado:
  - `Pipeline/Invoice/` — `InvoicePipelineState`, `InvoicePipelineStateRegistry`, `State/` (Aprobacion, Contabilidad, Tesoreria, AutorizacionPago, VerificacionPago, Pagada, Legalizada), `DocumentTypePolicy` + `DocumentTypePolicyFactory`, `Policy/` (`StandardDocumentTypePolicy`, `AnticipoDocumentTypePolicy`, `LegalizacionDocumentTypePolicy`), `LinkedInvoiceLegalizer`
  - `Pipeline/Novelty/`, `Pipeline/Advance/`, `Pipeline/PettyCash/`, `Pipeline/Refund/`, `Pipeline/PaymentScheduling/` — misma estructura (Registry + States), con `Policy/` propio cuando aplica (p. ej. `Advance/Policy/AdvanceLegalizationActionPolicy`)
- `Service/HealthCheck/` — `HealthCheckInterface`, `HealthCheckResult`, `HealthStatus` + implementaciones (`Database`, `Cache`, `EmailLog`, `CircuitBreaker`)
- `Service/Resilience/` — `Retryer`, `RetryPolicy` (retry con backoff)
- `Service/Subscriber/` — Event subscribers (`LegalizationInitializerSubscriber`, `LinkedInvoicesPromoterSubscriber`, `RefundOutcomeSubscriber`)

### Middlewares

Located in `src/Middleware/`:
- `CorrelationIdMiddleware` — Injects/propagates `X-Correlation-ID` header; used by `StructuredLogger`
- `RateLimitMiddleware` — Rate limiting per IP/route
- `HostHeaderMiddleware` — Host header validation

### Auth & Permissions

- Plugin: `cakephp/authentication ^4.1.0`. Custom finder `UsersTable::findAuth()` (active=true, contain Roles).
- RBAC enforced in `AppController::beforeFilter()` via `_enforcePermission()`.
- `$controllerModuleMap` maps controller → module. Actions map to can_view/can_create/can_edit/can_delete.
- Roles (ver `RoleConstants.php`): Administrador, Contabilidad, Tesorería, Registro/Revisión, Contador, Auxiliar de Personal, Asistente de Personal, Coordinador Administrativo y Financiero.
- **Admin bypass acotado**: `AuthorizationService::ADMIN_BYPASS_MODULES = ['users', 'roles']`. Para cualquier otro módulo el rol Administrador pasa por el lookup normal en la tabla `permissions` (cleanup post pipeline-permissions, 2026-05-02).
- **Pipeline permissions**: `PipelineAuthorizationService` resuelve permisos por (rol, pipeline, step) contra la tabla `pipeline_permissions`. Espejo del flujo CRUD pero por paso de pipeline.
- **Contador** ve y autoriza pagos en estado `autorizacion_pago` del pipeline de facturas; en `verificacion_pago` se valida la ejecución del pago antes de pasar a `pagada`.

### Invoice Pipeline

States (fuente única: `App\Constants\Domain\Invoice\PipelineStatus` enum, espejado en `InvoiceConstants::PIPELINE_STATUSES`):
`aprobacion` → `contabilidad` → `tesoreria` → `autorizacion_pago` → `verificacion_pago` → `pagada` (6 estados).

- Existe además un estado terminal `legalizada`, exclusivo de `document_type = 'Legalización'`. **No** participa en `PIPELINE_STATUSES`; vive en `ALL_STATUSES`. Pipeline visual reducido (`PIPELINE_STATUSES_LEGALIZACION`): `aprobacion` → `contabilidad` → `legalizada` (3 pasos).
- Tesorería registra pagos → avanza a `autorizacion_pago` (requiere ≥1 pago pendiente vía `InvoicePaymentService`)
- Contador autoriza en `autorizacion_pago` → avanza a `verificacion_pago`
- En `verificacion_pago` se valida la ejecución/conciliación del pago → avanza a `pagada`
- Pago parcial tras autorización → **regresa automáticamente** a `tesoreria`
- Facturas rechazadas (`area_approval='Rechazada'`) bloquean todo avance; Registro puede `resetFlow` para reiniciar
- En `autorizacion_pago` el Contador autoriza/rechaza cada pago; al quedar todos autorizados, la factura puede avanzar. Los soportes de pago se cargan como documentos normales del pipeline en `tesoreria` (`InvoiceDocuments`)
- Facturas en `pagada` redireccionan a `view` para no-admins
- Secciones del formulario controladas por `InvoiceFieldAccessPolicy` (const privada `SECTIONS_BY_STEP`, expuesta vía `getVisibleSections()`): `ledger` (siempre visible), `revision` (aprobacion), `accounting` (contabilidad), `treasury` (tesoreria), `payment_authorization` (autorizacion_pago **y** verificacion_pago — esta última reusa la misma sección read-only). Las plantillas además exponen secciones estructurales `general`, `dates`, `classification`.
- Estados de un pago individual (`invoice_payments.status`, slugs en inglés por convención): `pending`, `authorized`, `rejected`

### Paridad de módulos de flujo (alineación al canon de Facturas — en curso)

Auditoría completa y roadmap de remediación en `docs/auditoria-paridad-modulos-flujo.md`. Decisiones de canon vigentes:

- **Canon de backend/pipeline = Invoice**: State pattern limpio (un archivo por estado, States sin IO directo salvo servicios inyectados), enum `Domain/{Modulo}/PipelineStatus` como **fuente única** y las `*Constants` delegan a él (`STATUS_X = PipelineStatus::X->value`). El avance/regresión se resuelve vía `enum::next()/previous()` o `State::getNextStatus()` — **no** vía mapas `TRANSITIONS` legacy (en migración).
- **Canon de templates = la familia migrada**, no Invoice: layout `sgi-invoice-view-grid` + `element('pipeline_sidebar')` (Invoice aún arrastra grid inline y es el outlier a alinear). `add.php` es legacy en todos.
- **Nomenclatura del coordinador**: el objetivo es `{Modulo}PipelineService` (rename completado el 2026-05-29 — los 5 coordinadores cumplen; `AdvanceLegalizationService` excluido (no es coordinador de pipeline)).
- **Excepciones legítimas (NO son migración a medias):**
  - `Advance` y `PaymentScheduling` **no** tienen `FieldAccessPolicy`: Advance edita vía `Invoices::edit` (redirect) y PaymentScheduling no edita campos del header por paso. No crear policies vacías.
  - **Split de naming `Advance*` / `AdvanceLegalization*` (DECISIÓN: NO renombrar — conviven 3 ejes, todos legítimos):**
    - **Dir/enum/namespace corto `Advance`** (`App\Service\Pipeline\Advance`, `App\Constants\Domain\Advance\PipelineStatus`): convención de submódulo de Pipeline. Los 6 submódulos usan el sustantivo corto del módulo (`Invoice`, `Novelty`, `PettyCash`, `Refund`, `PaymentScheduling`); renombrar a `AdvanceLegalization` rompería la simetría con los otros 5.
    - **Clases/tablas largas `AdvanceLegalization*`** (`AdvanceLegalizationService`, `AdvanceLegalizationViewModel`, `AdvanceLegalizationHistoryService`, tablas `advance_legalizations` / `advance_legalization_signatures` / `advance_legalization_histories`): reflejan la entidad de dominio (la **legalización de anticipos**; Anticipo = Invoice, reusa `InvoicePipelineService`).
    - **Slugs persistidos = 2 ejes DISTINTOS e INMUTABLES** (no son el mismo, no tocar sin migración de datos): el módulo CRUD/permisos es `'advances'` (en `AppController::$controllerModuleMap`, `AuthorizationService::MODULES`, columna `permissions.module`, ruta `/advances/*`, controller `AdvancesController` plural corto), mientras que el pipeline en `pipeline_permissions` es `'legalizations'` (`PipelineStepConstants::PIPELINE_LEGALIZATIONS`). Cambiar cualquiera rompe URLs bookmarkeadas y filas ya persistidas en `permissions` / `pipeline_permissions`.
  - `Novelty` tiene 2 controllers (`EmployeeNovelties` individual + `NoveltyLiquidationDocs` grupal) servidos por un `NoveltyPipelineService`.
  - `AdvanceLegalizationHistoryService` y `PaymentSchedulingHistoryService` **no** usan `HistoryNormalizationTrait` ni `recordChanges()` (a diferencia de Invoice/Refund/PettyCash/Novelty): Advance audita campo-a-campo explícito por transición vía `_setStatus(extraChanges)` (patrón deliberado y transaccional, no frágil) y PaymentScheduling no edita campos del header por paso. Añadir `recordChanges` sería dead code — están bien dimensionados para su flujo.
  - `Advances/legalization` **Soportes NO usa `element('documents_section')`**: sus 3 bloques (relación de facturas, comprobante de consignación, historial de firmas) son **docs con firma/estado** (pills firmado/pendiente, reemplazo AJAX inline vía `fetch()` no `SgiDocumentUploader`, metadata de consignación, filas de firma rechazada con motivo) — fuera del contrato upload/delete del element, cuyo `document_row` está acoplado al `document_row_template`↔`sgi-document-uploader.js`. Markup bespoke deliberado; migrarlo exigiría extender `document_row` (transversal a 4+ consumidores) sin ganancia. El resto de módulos (Refund/PettyCash/EmployeeNovelties/PaymentScheduling) **sí** delegan en el element.
  - **Verbos del coordinador**: el canon convergido (2026-05-29) es `validateTransitionRequirements` / `saveAndAdvance` (guardar+avanzar) / `advance` (avance puro) / `regress`. **Divergencias de Novelty legítimas (NO unificar):** `getNextStatus(object, $type)` (los skips de pasos dependen del tipo — salta `aprobacion` si `!requires_boss_approval`, salta `GDP` si `!requires_employee_signature_review`; la firma `(string)` de los demás no lo expresa); `reject()` es **rechazo terminal** (→ `RECHAZADA`), distinto de la regresión fría `regress()` (Novelty no tiene `regress`); `advanceGroup()`/`validateGroupTransition()` son ops de lote del doc de liquidación sin equivalente en el canon.
  - Trampa de spelling deliberada: `InvoiceConstants::DIAN_REJECTED = 'Rechazado'` (masculino) vs `APPROVAL_REJECTED = 'Rechazada'` — **no unificar** (rompe datos persistidos).

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
- **Slug language convention:** Slugs visibles al usuario (estados de pipeline: `aprobacion`, `tesoreria`, `pagada`, `agrupacion`, `legalizada`, etc.) en **español** sin acentos. Slugs técnicos internos no visibles directamente al usuario (estados de logs de email, registros de pago, firmas de legalización: `pending`, `sent`, `failed`, `authorized`, `rejected`, `signed`) en **inglés**. Estados con label visible (approval/DIAN: `'Pendiente'`, `'Aprobada'`, `'Rechazada'`) en **español capitalizado** porque coinciden con el label de UI. La convivencia es deliberada — no homogeneizar sin migración explícita.

## Migration Gotchas

- Base class is `Migrations\BaseMigration` (NOT `AbstractMigration`).
- Use `$this->hasTable()` before create/drop to handle partial failures.
- FK column types must match exactly (signed/unsigned) with referenced tables.

## Sistema de Diseño

Tokens, componentes y patrones en `docs/design/`. **Lee solo los archivos relevantes a la tarea** — no cargues todo.

| Archivo | Contenido |
|---------|-----------|
| `docs/design/reglas-copy.md` | Reglas duras, tono/copy, excepciones, orden de carga CSS, archivos clave, prefijos |
| `docs/design/fundamentos.md` | Colores, tipografía, espaciado, iconografía (tokens base) |
| `docs/design/botones-badges.md` | Botones, badges, pills |
| `docs/design/formularios.md` | Inputs, tabs, filtros, date/time pickers |
| `docs/design/layout-tablas.md` | Cards y superficies, avatares, tablas |
| `docs/design/navegacion.md` | TopBar, sidebar, pipeline |
| `docs/design/documental-vacios.md` | Gestión documental, empty states, skeleton loaders |
| `docs/design/overlays.md` | Capa flotante: toasts, banner, modal, drawer, tooltip, command palette, menús (select, kebab, usuario, notificaciones) |

Antes de crear o editar cualquier vista, lee siempre `reglas-copy.md` + `fundamentos.md`, luego el archivo del componente concreto.

## Frontend

- Font: Inter Variable (local, `webroot/fonts/Inter-Variable.ttf`).
- Design: Borders instead of shadows. 2px top border on stat cards. No box-shadow.
- Colors: dark (#212529), green (#469D61), orange (#CD6A15).
- JS common: `webroot/js/sgi-common.js` auto-initializes Flatpickr, AutoNumeric, Select2.
- PDF: TCPDF + FPDI. Excel: PhpSpreadsheet.
- Pipeline elements: **todos** los módulos de flujo (incluido NoveltyLiquidationDocs) usan el sidebar vertical via `element/pipeline_sidebar.php` (hero + pipeline vertical + registro + acciones), dentro del layout `sgi-invoice-view-grid > sgi-invoice-view-left + sgi-invoice-view-right`. (`pipeline_progress.php` y `progress_stepper.php` —antiguos steppers huérfanos tras unificar el pipeline en el sidebar— se eliminaron el 2026-05-29.)

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
