# SGI — Architecture Audit Report

> Date: 2026-04-06 | Level: Deep | 161 PHP files, 30 services, 45 tables, 8 constants

---

## 1. Executive Summary

**Overall Score: 6.7/10** — Solid foundation with good service layer patterns, but critical gaps in external integration resilience and SOLID compliance.

### Scorecard

| Category | Score | Notes |
|----------|:-----:|-------|
| Layer Compliance | 7/10 | Good separation; sidebar counters and dashboard violate layers |
| State Machine Design | 9/10 | Excellent pipeline implementation with role-based visibility |
| Transaction Safety | 7/10 | Proper in pipelines; gaps in history services |
| Resilience (External APIs) | 3/10 | **Critical gap**: No retries, circuit breakers, or timeouts |
| Error Handling | 6/10 | Inconsistent return types; generic exceptions in webhooks |
| Audit Trail | 8/10 | Comprehensive field-level tracking; duplicated across services |
| Authorization (RBAC) | 9/10 | Well-designed, cached, role-based matrix |
| Constants & Domain Values | 9/10 | Consistent use; minimal hardcoded strings |
| Entity Enrichment | 7/10 | Domain helpers present; no behavior or invariant enforcement |
| SOLID Compliance | 4/10 | Zero interfaces/abstractions; SRP violations in large files |
| Code Duplication | 5/10 | History (x3), Document (x3), Pipeline (x2) services share patterns |

---

## 2. Critical Issues

### 2.1 AppController._setSidebarCounters() — Layer Violation

**File:** `src/Controller/AppController.php:151-256`

The `_setSidebarCounters()` method in `beforeFilter()` directly queries 5+ tables (Invoices, PettyCashRecords, LegalizationRecords, EmployeeNovelties, NoveltyLiquidationDocs) on **every authenticated request**. This is 100+ lines of query logic in a controller.

**Impact:** Performance on every page load; violates controller/service separation.

**Fix:** Extract to a `SidebarCounterService` with caching (counters rarely change within a single session).

### 2.2 External Integrations Have No Resilience

**Files:** `WebhookService.php`, `N8nService.php`, `NotificationService.php`, `DianCrosscheckService.php`

| Service | Issue |
|---------|-------|
| `WebhookService` | 30s hardcoded timeout, no retry, no circuit breaker, generic Exception catch |
| `N8nService` | Silent failure on missing config, no health check |
| `NotificationService` | All-or-nothing: if ANY email fails, throws Exception (blocks the operation) |
| `DianCrosscheckService` | Fire-and-forget: failed webhook leaves DB record in "error" state with no retry |

**Impact:** A single external API outage can cascade through the entire invoice pipeline.

**Fix:** Implement retry with exponential backoff, circuit breaker pattern, and structured error returns (not exceptions) for external calls.

### 2.3 History Services — N+1 Queries and No Transactions

**Files:** `InvoiceHistoryService.php`, `EmployeeHistoryService.php`, `NoveltyHistoryService.php`

All three services save one record per changed field in a loop (`$historiesTable->save($history)` inside `foreach`). A single invoice edit changing 10 fields generates 10 individual INSERT queries, none wrapped in a transaction.

**Impact:** Performance degradation; audit trail gaps if a save fails mid-loop.

**Fix:** Batch collect entities, then use `$historiesTable->saveMany($entities)` inside a single transaction.

---

## 3. Warnings

### 3.1 Fat Controllers

| Controller | Lines | Concern |
|------------|------:|---------|
| `EmployeeNoveltiesController` | 938 | Document uploads, signatures, observations, history, notifications, pipeline |
| `InvoicesController` | 574 | CRUD + filtering + documents + approvals + export |
| `EmployeesController` | 525 | CRUD + documents + history + filtering + import |
| `DashboardController` | 435 | Direct table queries + complex calculations for 6+ modules |

**Recommendation:** Extract sub-concerns (document management, filtering, export) into dedicated services or controller traits.

### 3.2 Zero Interfaces / Abstract Classes

The codebase has **no interfaces and no abstract classes** anywhere in `src/`. All service dependencies are concrete classes. While the constructor DI pattern (`?ServiceClass $s = null` with `?? new ServiceClass()` fallback) allows testing, it doesn't enforce contracts.

### 3.3 Code Duplication Across Service Families

| Pattern | Duplicated In | Shared Logic |
|---------|---------------|--------------|
| History recording | `InvoiceHistoryService`, `NoveltyHistoryService`, `EmployeeHistoryService` | Value normalization (DateTime to string, bool cast, null/empty), loop-and-save |
| Document upload | `InvoiceDocumentService`, `NoveltyDocumentService`, `EmployeeDocumentService` | MIME validation, size check, file move, delete logic, `MAX_DOC_SIZE` and `ALLOWED_DOC_MIMES` constants |
| Pipeline config | `InvoicePipelineService`, `NoveltyPipelineService` | Role-status visibility, editable fields matrix, section visibility, transition validation |

### 3.4 Pipeline Extensibility (Open/Closed Principle)

Adding a new pipeline status requires modifying **6+ constants** in a single service file (`STATUS_LABELS`, `ROLE_VISIBLE_STATUSES`, `EDITABLE_FIELDS`, `VISIBLE_SECTIONS_BY_ROLE`, `TRANSITION_REQUIREMENTS`, `TRANSITIONS`), plus the domain Constants class.

### 3.5 Entities Lack Domain Behavior

Entities have useful query helpers (`isRejected()`, `isPaid()`, `isApproved()`) but no behavior enforcement. An `Invoice` can be set to any `pipeline_status` without domain validation — all invariants live in services.

**Missing value objects:** Money amounts, date ranges, NIT/document numbers, and approval statuses are all primitive types with no encapsulated validation.

### 3.6 DashboardController Contains Business Logic

**File:** `src/Controller/DashboardController.php:46-289`

The dashboard index action performs direct table queries and complex calculations for 6+ modules inline, including raw SQL queries. Should be extracted to a `DashboardStatisticsService`.

### 3.7 NoveltyPipelineService Missing Transaction in advance()

**File:** `src/Service/NoveltyPipelineService.php:175-206`

Single novelty `advance()` method saves entity without wrapping in a transaction, unlike `advanceGroup()` which properly uses `transactional()`.

---

## 4. Strengths

What the codebase does well:

| Area | Details |
|------|---------|
| **Service DI pattern** | All services use nullable constructor params with `?? new Service()` fallback — consistent across 30 services |
| **Service isolation** | Zero instances of services accessing `$this->request` or `$this->response` |
| **Structured returns** | `InvoicePipelineService::saveAndAdvance()` returns `['saved', 'advanced', 'advanceErrors', 'notificationErrors']` |
| **Constants usage** | All domain values (`'Rechazada'`, `'Pago total'`, pipeline statuses) referenced via constants classes |
| **Pagination** | Fixed 15 items per page across all 27+ controllers |
| **Entity enrichment** | Entities have domain query helpers (`isRejected()`, `isPaid()`, `isInPettyCash()`, `isGrouped()`) |
| **Authorization caching** | `AuthorizationService` caches permissions per role ID |
| **Pipeline state machine** | Comprehensive role-based visibility, editable fields per role/status, transition requirements, transactional save+advance |
| **Audit trail** | Field-by-field change tracking with user attribution across invoices, employees, and novelties |

---

## 5. SOLID Analysis

### Single Responsibility

| File | Lines | Issues |
|------|------:|--------|
| `EmployeeNoveltiesController.php` | 938 | Form handling + documents + history + observations + notifications + signatures + pagination |
| `NoveltyPipelineService.php` | 590 | Pipeline orchestration + field visibility + transition logic + section management |
| `ExcelImportService.php` | 491 | Excel reading + mapping validation + import orchestration + field transformation |
| `InvoicePipelineService.php` | 437 | 7 const collections + visibility logic + field editing + transition validation + advancement |
| `DashboardController.php` | 435 | Aggregates counters from 6+ tables + query building |

### Open/Closed

- Pipeline services require code modification to add new states or roles (6+ constants per change)
- `AuthorizationService::isAllowed()` match statement must be modified to add new permission types (`can_export`, `can_approve`)

### Dependency Inversion

- Zero interfaces in the entire `src/` directory
- All services depend on concrete implementations
- `TableRegistry::getTableLocator()->get('TableName')` used as service locator (string coupling)
- Controllers instantiate services directly with `new` in `initialize()`

---

## 6. Pattern Detection Matrix

| Pattern | Detected | Compliance | Notes |
|---------|:--------:|:----------:|-------|
| Layered Architecture | Yes | 7/10 | Clean separation with exceptions in AppController and Dashboard |
| Service Layer | Yes | 9/10 | 30 well-scoped services, proper DI pattern |
| RBAC Authorization | Yes | 9/10 | Cached, matrix-based, admin bypass |
| State Machine (Pipeline) | Yes | 9/10 | Role-based visibility, transition validation, transactional |
| Audit Trail | Yes | 8/10 | Field-level tracking; needs batch saves and deduplication |
| Strategy (implicit) | Yes | 8/10 | Role-based field filtering and section visibility |
| Template Method (implicit) | Yes | 7/10 | `saveAndAdvance()` defines workflow skeleton |
| Repository (implicit) | Partial | 6/10 | ORM Tables act as repositories; no formal interfaces |
| Observer/Event | No | -- | Notifications tightly coupled; could use CakePHP Events |
| Circuit Breaker | No | -- | External calls have no fault tolerance |
| Retry | No | -- | No exponential backoff on transient failures |
| Value Objects | No | -- | All domain values are primitives |
| DTO | Partial | 5/10 | `ImportResult` exists; most services use arrays |

---

## 7. Recommended Improvements

### Priority 1 — Resilience (High Impact)

1. **Retry + Circuit Breaker** for `WebhookService`, `N8nService` — wrap HTTP client with exponential backoff (3 attempts: 1s/2s/4s)
2. **Non-blocking notifications** — `NotificationService` should return partial success `['sent' => n, 'failed' => [...]]` instead of throwing on any failure
3. **Retry mechanism for DIAN** — Add `attempt_count` column to `dian_crosschecks`, retry failed webhooks

### Priority 2 — Eliminate Duplication (Medium Impact)

4. **Extract `BaseHistoryService`** — Consolidate normalization logic and use `saveMany()` for batch inserts
5. **Extract `DocumentUploadService`** — Consolidate `MAX_DOC_SIZE`, `ALLOWED_DOC_MIMES`, validate/move/delete methods
6. **Shared pipeline configuration** — Consider data-driven config for role-status-field matrices

### Priority 3 — Layer Compliance (Medium Impact)

7. **Extract `SidebarCounterService`** — Move `_setSidebarCounters()` from `AppController` with optional caching
8. **Extract `DashboardStatisticsService`** — Move inline queries and calculations from `DashboardController`

### Priority 4 — Standardize Returns (Low-Medium Impact)

9. **`ServiceResult` DTO** — Standardize all service return values: `['success' => bool, 'data' => mixed, 'errors' => string[]]`
10. **Fix mixed returns** — `DianCrosscheckService::processUpload()` currently returns `string|Entity`

### Priority 5 — Future Considerations (Long-term)

| Improvement | When to Consider |
|-------------|-----------------|
| Introduce interfaces for core services | When adding test suite |
| Use CakePHP Event system for notifications | When adding more notification channels |
| Value Objects (Money, DateRange) | When adding multi-currency or complex date logic |
| Data-driven pipeline configuration | When adding 5th+ pipeline state or 5th+ role |
| Split `EmployeeNoveltiesController` (938 lines) | When adding more novelty features |
