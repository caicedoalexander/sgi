# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SGI (Sistema de Gestión Interna) — internal management system built with CakePHP 5.3 on PHP 8.4+ (`composer.json` requires `>=8.4`). Manages invoices (6-state pipeline + estado terminal `legalizada`), employees, petty cash, legalizations, novelties, payment schedulings, refunds, advances, and catalog modules. MySQL/MariaDB backend. Spanish-language UI.

## Commands

```bash
# Dev server
php bin/cake server

# Dependencies
composer install

# Code style (CakePHP standard)
composer cs-check                    # Check
composer cs-fix                      # Auto-fix

# Database migrations
php bin/cake migrations migrate      # Run pending
php bin/cake migrations rollback     # Rollback last
php bin/cake migrations create Name  # New migration (uses BaseMigration, NOT AbstractMigration)
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
| `InvoicePipelineService` | Coordinador delgado del pipeline de facturas (6 estados). Delega a `InvoicePipelineStateRegistry`, `DocumentTypePolicyFactory`, `PipelineAuthorizationService` y las policies en `Pipeline/Invoice/Policy/` (`InvoiceFieldAccessPolicy`, `InvoiceLockPolicy`, `InvoiceTransitionValidator`, `InvoiceActionPolicy`). API pública preservada. |
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
  - `Pipeline/Invoice/` — `InvoicePipelineState`, `InvoicePipelineStateRegistry`, `State/` (Aprobacion, Contabilidad, Tesoreria, AutorizacionPago, VerificacionPago, Pagada, Legalizada), `DocumentTypePolicy` + `DocumentTypePolicyFactory`, `LinkedInvoiceLegalizer`, y `Policy/` con las 3 DocumentTypePolicy (`StandardDocumentTypePolicy`, `AnticipoDocumentTypePolicy`, `LegalizacionDocumentTypePolicy`) **y** las policies de campos/locks/transición/acciones (`InvoiceFieldAccessPolicy`, `InvoiceLockPolicy`, `InvoiceTransitionValidator`, `InvoiceActionPolicy`)
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
- **Admin bypass acotado**: `AuthorizationService::ADMIN_BYPASS_MODULES = ['users', 'roles']`. Para cualquier otro módulo el rol Administrador pasa por el lookup normal en la tabla `permissions`.
- **Pipeline permissions**: `PipelineAuthorizationService` resuelve permisos por (rol, pipeline, step) contra la tabla `pipeline_permissions`. Espejo del flujo CRUD pero por paso de pipeline.
- **Contador** ve y autoriza pagos en estado `autorizacion_pago` del pipeline de facturas; en `verificacion_pago` se valida la ejecución del pago antes de pasar a `pagada`.
- **`FieldAccessPolicy` debe ser rol-aware**: heredar de `PipelineFieldPolicy` (un rol sin `canOperate` del paso obtiene patch vacío). **No** usar `unset($roleId)` (filtrado role-blind). Los 6 módulos cumplen — `PettyCash`/`Refund` gatean la escritura con `if (getEditableFields($roleId,$step) === [])`.

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

### Paridad de módulos de flujo

**Eje doble de canon — NO mezclar criterios:**
- **Arquitectura (backend/pipeline + capa de vista/ViewModel):** Invoice es el **OUTLIER**. El canon es el *mejor patrón derivado de todos* (PettyCash/Refund como referentes de coordinador/layout).
- **Visual puro (HTML/CSS/átomos):** Invoice **SÍ es el canon** de apariencia a replicar (ver [Canon visual de templates de flujo](#sistema-de-diseño)).

Las estructuras canónicas (backend, capa de vista, visual) y sus excepciones de dominio (B) están detalladas en las subsecciones siguientes. `add.php` es legacy en todos los módulos.

### Estructura canónica de un módulo de flujo (backend)

DEFAULT obligatorio para todo módulo de flujo nuevo o tocado. Es el patrón derivado de todos los módulos, NO "lo que hace Invoice" (Invoice es outlier en backend; PettyCash/Refund son los mejores referentes). Desviarse solo es legítimo para las excepciones (B) listadas abajo.

```
src/Service/
  {Modulo}PipelineService.php          ← coordinador DELGADO de aplicación
  Pipeline/
    PipelineFieldPolicy.php            ← base abstracta compartida (ya existe)
    {Modulo}/
      {Modulo}PipelineState.php        ← interfaz/abstracta del State
      {Modulo}PipelineStateRegistry.php← resuelve enum → State
      State/{Estado}State.php          ← un archivo por estado, PUROS (in-memory)
      Policy/
        {Modulo}ActionPolicy.php       ← rol×paso (auth->canOperate) + predicados de entidad
        {Modulo}FieldAccessPolicy.php  ← campos/secciones editables por paso (si edita header)
        {Modulo}LockPolicy.php         ← bloqueo de campos por estado/documento
        {Modulo}TransitionValidator.php← requisitos de avance (si aplica)
      Guard/{Modulo}Guard.php          ← (opcional) encapsula el IO que un State necesite
  {Modulo}HistoryService.php           ← implements HistoryServiceInterface + HistoryNormalizationTrait + const FIELDS_TO_TRACK
  {Modulo}DocumentService.php          ← usa DocumentUploadTrait
  {Modulo}PaymentService.php           ← SOLO si hay agregado de pago con ciclo propio
src/Constants/
  {Modulo}Constants.php                ← STATUS_X = PipelineStatus::X->value (delega, no duplica)
  Domain/{Modulo}/PipelineStatus.php   ← FUENTE ÚNICA: cases + label()/next()/previous()/isTerminal()
src/ViewModel/
  {Modulo}EditViewModel.php            ← implements EditViewModelInterface
```

Responsabilidades:
- **Enum `PipelineStatus`** = fuente única real. El avance/regresión se resuelve vía `enum::next()/previous()` o `State::getNextStatus()`, NUNCA mapas `TRANSITIONS` legacy.
- **Coordinador** = una transacción atómica: filtra campos por paso (rol-aware) → valida → persiste → audita → avanza/propaga. Verbos canónicos: `validateTransitionRequirements` / `saveAndAdvance` / `advance` / `regress`.
- **States** = decisiones in-memory puras; cuando necesitan BD inyectan dependencias (`?? new`), nunca `TableRegistry` estático (encapsular ese IO en un `Guard/{Modulo}Guard` inyectado).
- **FieldAccessPolicy** extiende `PipelineFieldPolicy` y es **rol-aware** (un rol sin `canOperate` del paso obtiene patch vacío; no `unset($roleId)`).
- **HistoryService** = interface + trait + `const FIELDS_TO_TRACK`.
- **PaymentService dedicado** solo si hay agregado de pago con ciclo propio (Invoice/Refund/Novelty-liquidación); PettyCash/PaymentScheduling no.

Excepciones (B) legítimas — NO forzar el patrón:
- **Advance** reusa `InvoicePipelineService` (Anticipo = Invoice); sin `FieldAccessPolicy` (edita vía `Invoices::edit`); verbos por outcome.
- **PaymentScheduling** sin `FieldAccessPolicy` (no edita campos del header por paso).
- **Novelty**: `getNextStatus(object, $type)` (skips de paso dependen del tipo), `reject()` terminal (≠ `regress`), `advanceGroup()`/`validateGroupTransition()` (lote del doc de liquidación). `AdvanceLegalizationHistoryService`/`PaymentSchedulingHistoryService` no usan el trait (auditan por transición / no editan header).

### Estructura canónica de la capa de vista (ViewModel ↔ Presentation)

DEFAULT obligatorio. Dos capas que COEXISTEN con dirección de dependencia única **VM → Presentation** y NO se fusionan:

- **`src/View/Presentation/{Modulo}Presentation.php`** = diccionario UI estático por dominio. `final class` casi enteramente `const` (`STATUS_BADGES`, `STATUS_ICONS`, `APPROVAL_BADGES`, `DIAN_BADGES`) + factory `forRow()`. Fuente = `*Constants`. Vive en **todas** las vistas (index/view no instancian VM). Jamás importa un VM.
- **`src/ViewModel/{Modulo}{Add,Edit,View}ViewModel.php`** = agregado per-request, `final readonly class`. `Edit`/`View` implementan `EditViewModelInterface`/`ViewViewModelInterface`. Derivan de UNA entidad + permisos + dropdowns. Pueden importar `Presentation`.
- **`src/View/Presentation/{Modulo}RowView.php`** = DTO de fila de listado para `index`, producido por `Presentation::forRow()`.
- **`src/ViewModel/Support/`** (`PaymentOptions`, `PipelineEditFlags`, `SubmitButton`) = derivación cross-módulo del VM.

Regla de oro (cero drift): el mapeo **estado→pill/icono vive SOLO en `{Modulo}Presentation` (const)**. CERO arrays literales inline en los `.php`. El VM expone `currentStatusBadge` derivado de Presentation; si un template lo recomputa es **bug de drift**. Toda derivación de fila (`stageIdx`, pill, pipeline-mini, labels ES, totales) va **dentro de `forRow()`**, no en `index.php`. El controller pasa `$viewModel` (uniforme `set('viewModel', $vm)`), no `compact()` crudo.

Excepción (B): `AdvanceLegalizationViewModel` conserva el sufijo de entidad (no "edit de Advance").

### Excepciones de dominio transversales (NO migración a medias)

- **Nomenclatura del coordinador** = `{Modulo}PipelineService` (los 5 coordinadores cumplen; `AdvanceLegalizationService` excluido — gobierna el sub-pipeline de legalización sobre `advance_legalizations`, no es coordinador de pipeline).
- **Split de naming `Advance*` / `AdvanceLegalization*` (NO renombrar — 3 ejes legítimos):**
  - Dir/enum/namespace corto `Advance` (`App\Service\Pipeline\Advance`, `App\Constants\Domain\Advance\PipelineStatus`): convención de submódulo (los 6 usan el sustantivo corto: `Invoice`/`Novelty`/`PettyCash`/`Refund`/`PaymentScheduling`). Renombrar rompería la simetría.
  - Clases/tablas largas `AdvanceLegalization*` (`AdvanceLegalizationService`, `…ViewModel`, `…HistoryService`, tablas `advance_legalizations`/`…_signatures`/`…_histories`): reflejan la entidad de dominio (legalización de anticipos; Anticipo = Invoice, reusa `InvoicePipelineService`).
  - **Slugs persistidos INMUTABLES** (no tocar sin migración): CRUD/permisos `'advances'` (`$controllerModuleMap`, `AuthorizationService::MODULES`, `permissions.module`, ruta `/advances/*`) ≠ pipeline `'legalizations'` (`pipeline_permissions`, `PipelineStepConstants::PIPELINE_LEGALIZATIONS`). Cambiar cualquiera rompe URLs y filas persistidas.
- **Novelty** tiene 2 controllers (`EmployeeNovelties` individual + `NoveltyLiquidationDocs` grupal) servidos por un solo `NoveltyPipelineService`.

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
- **Mapeo estado→pill/icono — anti-drift:** vive SOLO en `src/View/Presentation/{Modulo}Presentation` (const `STATUS_BADGES`/`STATUS_ICONS`). Los templates lo consumen vía `$viewModel->currentStatusBadge` o `Presentation::forRow()`. **Prohibido** redeclarar mapas estado→pill como literales en el `.php`. Dirección de dependencia única: **VM → Presentation** (Presentation nunca importa VM).
- **Trampas de datos persistidos — NO tocar sin migración:**
  - `InvoiceConstants::DIAN_REJECTED = 'Rechazado'` (masculino) vs `APPROVAL_REJECTED = 'Rechazada'` (femenino) — spelling deliberado, no unificar.
  - Módulo CRUD/permisos `'advances'` ≠ pipeline `'legalizations'` (2 ejes distintos; ver Paridad de módulos de flujo).
  - `NoveltyConstants::DOC_STATUS_LIQUIDACION = 'd. liquidacion'` (con punto y espacio) — valor persistido en `novelty_documents.pipeline_status`; no "corregir el typo" ni cambiar el valor sin migración.
  - Slugs español/inglés mixtos (ver convención de slugs abajo).
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

### Canon visual de templates de flujo

En HTML/CSS puro, **Invoice = canon de APARIENCIA a replicar** (eje opuesto al backend/vista, donde es outlier — no mezclar criterios). Estilos inline one-off (header de página, `$gridStyle` N-col, grids de campos, slots `ob_start` del sidebar, `color:var(--primary-color)` sobre `.mono`) son convención canónica, NO deriva.

Tres esqueletos obligatorios:
- **INDEX** (sin shell): HEADER `.d-flex` (título + única `.btn-primary` "Nueva …") + Form(get) con `.input`+`.btn-default` toggle filtros + `.chip[role=tablist]` + TABLA `.sgi-card[style="padding:0"]` con `<a class="row-fact">` como ANCLA (NO `<table>`), `.pipeline-mini`/`.pill-*-soft`, `.empty-state`, `element('pagination')`.
- **EDIT** (`.sgi-edit-shell`): `.sgi-edit-shell-head` (fijo) → `Form` `.sgi-edit-shell-form` → `.sgi-edit-shell-body` (scroll) → `.row.gx-3` con `aside.col-lg-3.sgi-edit-col` (`element('pipeline_sidebar')`) + `main.col-lg-9.sgi-edit-col` (ambos `d-flex flex-column gap-3`) → `.sgi-edit-footer` sticky (meta + única `.btn-primary` submit). Split izq/der = Bootstrap `.col-lg-3`/`.col-lg-9`, NO `340px 1fr`.
- **VIEW** (`.sgi-invoice-view-grid`, grid `340px 1fr`): `.sgi-invoice-view-left` (`pipeline_sidebar`) + `.sgi-invoice-view-right` (`.sgi-card` con campos en `.field-row` `.k`/`.v`/`.v.mono` dentro de grid inline `1fr 1fr;gap:28px`).

Reglas duras:
- **Vocabulario de átomos — nunca a mano:** `.btn`(+`-primary/-default/-ghost/-secondary/-danger/-subtle/-sm/-icon`), `.pill`(+`-*-soft`/`-sm`/`-lg`), `.input`, `.av`, `.doc`, `.chip`, `.field-row`, `.pipeline-mini`/`.pipeline-v`, `.empty-state`/`.es-*`, `.sgi-card`(+`.compact`), `.mono`, `.accent-strip`.
- **Campos = `.field-row` en grid inline `1fr 1fr;gap:28px`**, NO `.sgi-info-grid` (eliminado).
- **Header:** breadcrumb + `.sgi-page-title` (NO `.sgi-title-page`, eliminado) + `.sgi-edit-id-chip`; acciones de header en `.btn-default`.
- **Modales:** los modales reales son Bootstrap `.modal.fade` — usar `element('upload_doc_modal')` compartido. El componente *modal* del sistema de diseño vive scopeado bajo `.modal-stage` (`.modal-stage .modal { … }`) porque la clase `.modal` pelada colisionaba con Bootstrap. **Nunca** estilar `.modal` sin el scope `.modal-stage`.

Excepciones (B) — markup bespoke legítimo, NO alinear: tablas agrupadas de dominio, soportes con firma (`Advances/legalization`, NLD — fuera del slot-contract de `document_row`), side-rail de calendario en `EmployeeNovelties/index`, forms por-estado. `add.php` sigue legacy `.card.card-primary` (DEUDA → modal `.modal-stage` / stepper, decisión por módulo pendiente).

## Frontend

- Font: Inter Variable (local, `webroot/fonts/Inter-Variable.woff2`).
- Design: Borders instead of shadows. 2px top border on stat cards. No box-shadow.
- Colors: dark (#212529), green (#469D61), orange (#CD6A15).
- JS common: `webroot/js/sgi-common.js` auto-initializes Flatpickr, AutoNumeric, Select2.
- PDF: TCPDF + FPDI. Excel: PhpSpreadsheet.
- Pipeline elements: **todos** los módulos de flujo (incluido NoveltyLiquidationDocs) usan el sidebar vertical via `element/pipeline_sidebar.php` (hero + pipeline vertical + registro + acciones), dentro del layout `sgi-invoice-view-grid > sgi-invoice-view-left + sgi-invoice-view-right`.

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
