# SGI Architecture Audit Report

**Project:** SGI (Sistema de Gestion Interna)
**Framework:** CakePHP 5.3 / PHP 8.2+
**Date:** 2026-04-12
**Level:** Standard
**Files Analyzed:** ~130+ PHP files (38 services, 31 controllers, 50 entities, 51 tables, 9 constants)

---

## 1. Executive Summary

**Overall Architecture Health: 48%**

SGI is a well-organized **Service-Oriented Layered Architecture** monolith with consistent patterns (ServiceResult, Constants, pipeline state machines). The architecture is appropriate for an internal management system of this scale.

| Category | Compliance | Critical | Warnings |
|----------|-----------|----------|----------|
| Layered Architecture | 85% | 0 | 3 |
| Service Layer | 90% | 0 | 2 |
| SOLID Principles | 50% | 3 | 6 |
| GRASP Principles | 55% | 0 | 7 |
| DDD Tactical | 40% | 2 | 4 |
| Stability Patterns | 35% | 2 | 3 |
| Behavioral Patterns | 45% | 0 | 6 |
| Structural Patterns | 40% | 0 | 4 |
| Creational Patterns | 55% | 0 | 3 |
| Enterprise (CQRS/ES/EDA) | 15% | 0 | 3 |
| Observability | 25% | 3 | 2 |

**Critical Issues:** 10 | **Warnings:** 43 | **Informational:** 12

---

## 2. Pattern Detection Matrix

| Pattern | Detected | Compliance | Status |
|---------|----------|------------|--------|
| Service-Oriented Layers | Yes | 85% | Active |
| State Machine (implicit) | Yes | 70% | Active -- 5 pipeline services |
| ServiceResult (Result pattern) | Yes | 95% | Active -- consistent |
| Constants layer | Yes | 95% | Active -- 9 classes, no magic strings |
| RBAC Authorization | Yes | 90% | Active |
| Retry w/ Exponential Backoff | Partial | 60% | Only WebhookService |
| Facade | Partial | 50% | N8nService, SidebarCounterService |
| Caching Proxy (inline) | Partial | 40% | AuthorizationService, SystemSettingsService |
| Audit Trail | Yes | 80% | InvoiceHistory, EmployeeHistory, NoveltyHistory |
| Circuit Breaker | No | 0% | Missing |
| Rate Limiter | No | 0% | Missing |
| Bulkhead | No | 0% | Missing |
| Interfaces/Abstractions | No | 0% | Zero application interfaces |
| Strategy | No | 0% | Switch statements instead |
| Template Method | No | 0% | Duplicated services instead |
| Domain Events (EDA) | No | 0% | Synchronous coupling |
| CQRS | No | N/A | Not needed at this scale |
| Event Sourcing | No | N/A | Not needed at this scale |
| Hexagonal | No | N/A | Not needed at this scale |

---

## 3. Critical Issues

### CRIT-01: Pipeline State Changes Bypass Pipeline Service

- **Files:** `src/Controller/InvoicesController.php:649-764`
- **Impact:** Silent state transitions skip history recording and notifications.
- **Details:** The controller's `addPayment()`, `authorizePayment()`, and `rejectPayment()` directly set `pipeline_status`, bypassing `InvoicePipelineService::saveAndAdvance()` which handles history + notifications. Two pathways for the same operation = inconsistent audit trail.

### CRIT-02: Zero Interfaces in Application Code

- **Files:** All 38 services, all 31 controllers
- **Impact:** Impossible to swap implementations, mock for testing, or extend without modifying existing code. Violates DIP, OCP, and GRASP Protected Variations.

### CRIT-03: No Circuit Breaker on External Calls

- **Files:** `src/Service/WebhookService.php`, `src/Service/N8nService.php`, `src/Service/NotificationService.php`
- **Impact:** Blocking `usleep()` retries (up to 97s per call) can exhaust PHP-FPM workers during external service outages, cascading into application-wide unavailability.

### CRIT-04: No Resilience for SMTP Notifications

- **File:** `src/Service/NotificationService.php:54-83`
- **Impact:** Email failures are caught but never retried. Lost notifications cause business process delays when users don't know invoices advanced to their stage.

### CRIT-05: Service Instantiation Inside Business Logic

- **Files:** `src/Service/InvoicePipelineService.php:235,380`, `src/Service/ApprovalTokenService.php:124,192`, `src/Controller/InvoicesController.php:715`
- **Impact:** Hidden dependencies, untestable transition logic, inconsistent DI pattern.

### CRIT-06: God Controllers

- **Files:** `src/Controller/EmployeeNoveltiesController.php` (939 lines, 20+ actions, 8 services), `src/Controller/InvoicesController.php` (796 lines)
- **Impact:** Hard to test, navigate, and maintain. High merge conflict risk.

### CRIT-07: InvoicePipelineService SRP Violation (489 lines, 8 responsibilities)

- **File:** `src/Service/InvoicePipelineService.php`
- **Impact:** State machine + field visibility + section visibility + validation + data filtering + persistence + history + notifications all in one class.

### CRIT-08: No Structured Logging

- **Files:** All services using `Cake\Log\Log`
- **Impact:** String-interpolated logs with no correlation IDs, no request context, no structured format. Multi-step pipeline operations are untraceable.

### CRIT-09: Transaction Inconsistency in Payment Scheduling

- **File:** `src/Service/PaymentSchedulingService.php:234-292`
- **Impact:** Non-transactional loop creating payments across multiple invoices can leave system in inconsistent state on partial failure.

### CRIT-10: Grouped Invoice Advances Skip Audit Trail

- **Files:** `src/Service/PettyCashService.php:138-179`, `src/Service/LegalizationService.php:137-176`
- **Impact:** Bulk `updateAll()` for pipeline status bypasses `InvoiceHistoryService`, leaving no per-invoice audit record for grouped advances.

---

## 4. Warnings

| # | Issue | Location | Severity |
|---|-------|----------|----------|
| W-01 | Switch on entity type (OCP violation) | `src/Service/ApprovalTokenService.php:104-112` | High |
| W-02 | ~80% code duplication | `src/Service/LegalizationService.php` vs `src/Service/PettyCashService.php` | High |
| W-03 | Duplicate approval token systems | `src/Service/ApprovalTokenService.php` vs `src/Service/InvoiceApprovalService.php` | High |
| W-04 | DashboardStatisticsService low cohesion | 10 methods, 5 domains, 347 lines | High |
| W-05 | No health check endpoint | Application-wide | High |
| W-06 | No request/operation tracing | Application-wide | High |
| W-07 | No rate limiting on public approval endpoint | `src/Controller/ExternalApprovalsController.php` | High |
| W-08 | TOCTOU race condition on token consumption | `src/Service/InvoiceApprovalService.php:141-196` | High |
| W-09 | Table references Service constants | `src/Model/Table/InvoicesTable.php:186` | Medium |
| W-10 | InvoiceHistory/NoveltyHistory recording duplication | History services | Medium |
| W-11 | Direct PhpSpreadsheet usage (no adapter) | `src/Service/ExcelImportService.php` | Medium |
| W-12 | Direct CakePHP Mailer usage (no adapter) | `src/Service/NotificationService.php` | Medium |

---

## 5. Cross-Pattern Conflicts

| Conflict | Patterns | Resolution |
|----------|----------|------------|
| Controller vs Pipeline service state transitions | Layered Architecture + State Machine | Route ALL status changes through pipeline services |
| Grouping services vs Pipeline service | Service Layer + State Machine | Call `InvoiceHistoryService::recordStatusChange()` after bulk updates |
| Duplicate approval mechanisms | Service Layer coherence | Unify `ApprovalTokenService` and `InvoiceApprovalService` or clearly separate responsibilities |

---

## 6. Pattern Recommendations

### Resilience Improvements

| Problem Found | Recommended | Skill to Use |
|---------------|-------------|--------------|
| No circuit breaker on webhooks/SMTP (CRIT-03/04) | Circuit Breaker | `/acc:create-circuit-breaker` |
| Blocking retry sleeps in WebhookService | Rate Limiter / Async queue | `/acc:create-rate-limiter` |
| No rate limiting on public approval endpoint (W-07) | Rate Limiter | `/acc:create-rate-limiter` |
| No bulkhead isolation between subsystems | Bulkhead | `/acc:create-bulkhead` |
| Missing SMTP retry | Retry Pattern | `/acc:create-retry-pattern` |

### DDD / Domain Improvements

| Problem Found | Recommended | Skill to Use |
|---------------|-------------|--------------|
| Anemic entities -- no domain behavior (CRIT-07) | Rich Entity methods | `/acc:create-entity` |
| Switch on entity type (W-01) | Strategy Pattern | `/acc:create-strategy` |
| Duplicated pipeline services (W-02) | Template Method | `/acc:create-template-method` |
| Service instantiation inside methods (CRIT-05) | Factory / DI | `/acc:create-factory` |

### Integration / Observability Improvements

| Problem Found | Recommended | Skill to Use |
|---------------|-------------|--------------|
| No structured logging (CRIT-08) | Structured Logger | `/acc:create-structured-logger` |
| No health check endpoint (W-05) | Health Check | `/acc:create-health-check` |
| Synchronous side effects on state changes | Domain Events | `/acc:create-domain-event` |
| No correlation ID tracing (W-06) | Correlation Context | `/acc:create-correlation-context` |

### Structural Improvements

| Problem Found | Recommended | Skill to Use |
|---------------|-------------|--------------|
| Direct Mailer/HTTP/Excel usage (W-11/12) | Adapter | `/acc:create-adapter` |
| N8nService (already good Facade) | Formalize with interface | `/acc:create-facade` |
| God controllers (CRIT-06) | Action-Domain-Responder | `/acc:create-action` |

---

## 7. Prioritized Action Items

### Critical (Fix Now)

1. **Route ALL pipeline state changes through pipeline services** (CRIT-01, CRIT-10)
   - Move `addPayment()`, `authorizePayment()`, `rejectPayment()` logic into `InvoicePipelineService`/`InvoicePaymentService`
   - Ensure grouped advances (PettyCash/Legalization) record per-invoice history

2. **Add Circuit Breaker to external calls** (CRIT-03, CRIT-04)
   - Wrap `WebhookService` and `NotificationService` SMTP calls
   - Use `/acc:create-circuit-breaker`

3. **Wrap `PaymentSchedulingService::applyPayments()` in a transaction** (CRIT-09)
   - Single `$connection->transactional()` around the entire payment loop

4. **Move inline service instantiation to constructors** (CRIT-05)
   - `InvoicePipelineService::validateTransitionRequirements()` -> inject `InvoicePaymentService`
   - `ApprovalTokenService` -> inject dependencies via constructor

### High (Next Sprint)

5. **Extract interfaces for key services** (CRIT-02)
   - Start with: `NotificationServiceInterface`, `HistoryServiceInterface`, `WebhookServiceInterface`, `PipelineServiceInterface`

6. **Decompose God controllers** (CRIT-06)
   - Split `EmployeeNoveltiesController` into: NoveltyController, NoveltyDocumentsController, NoveltyLiquidationController
   - Extract payment actions from `InvoicesController` into `InvoicePaymentsController`

7. **Add structured logging with correlation IDs** (CRIT-08)
   - Use `/acc:create-structured-logger` + `/acc:create-correlation-context`

8. **Extract `InvoicePipelineService` responsibilities** (CRIT-07)
   - `InvoiceStateMachine` (transitions, validation)
   - `InvoiceFieldAccessPolicy` (editable fields, visible sections)
   - `InvoicePipelineService` as orchestrator

### Medium (Backlog)

9. **Eliminate LegalizationService/PettyCashService duplication** (W-02) -- `/acc:create-template-method`
10. **Replace switch statements with Strategy pattern** (W-01) -- `/acc:create-strategy`
11. **Add health check endpoint** (W-05) -- `/acc:create-health-check`
12. **Implement domain events for pipeline side effects** -- `/acc:create-domain-event`
13. **Add rate limiting to public approval endpoint** (W-07) -- `/acc:create-rate-limiter`
14. **Fix token consumption race condition** (W-08) -- Add `SELECT ... FOR UPDATE`

### Low (Nice to Have)

15. Enrich entity domain methods (`Invoice::canAdvanceTo()`, `Invoice::isOverdue()`)
16. Consolidate the two approval token systems (W-03)
17. Split `DashboardStatisticsService` by domain (W-04)
18. Add adapter interfaces for Mailer and PhpSpreadsheet (W-11/12)

---

## 8. SOLID/GRASP Analysis

### SOLID Scores

| Principle | Score | Key Issues |
|-----------|-------|------------|
| SRP | 40% | 4 God controllers (>500 lines), `DashboardStatisticsService` spans 5 domains |
| OCP | 55% | 8 `switch` statements on status in pipeline services |
| LSP | 95% | No violations -- flat entity hierarchy |
| ISP | 20% | Zero interfaces exist |
| DIP | 15% | Zero abstractions; 60+ `new ConcreteService()` instantiations |

### GRASP Scores

| Principle | Score | Key Issues |
|-----------|-------|------------|
| Information Expert | 70% | Pipeline logic outside entities; entities are data bags |
| Creator | 60% | Service instantiation scattered across controllers and services |
| Controller | 45% | `EmployeeNoveltiesController` (939 lines), `InvoicesController` (796 lines) |
| Low Coupling | 35% | `EmployeeNoveltiesController` depends on 8 concrete services |
| High Cohesion | 55% | `DashboardStatisticsService` mixes 5 domains in 10 methods |

---

## 9. Coupling & Cohesion

### High Efferent Coupling (Ce)

| Class | Dependencies | Layer |
|-------|-------------|-------|
| `EmployeeNoveltiesController` | 8 services | Controller |
| `EmployeesController` | 6 services | Controller |
| `InvoicesController` | 5 services + TableRegistry | Controller |
| `ApprovalTokenService` | 5 services (some inline) | Service |
| `InvoicePipelineService` | 3 declared + 1 hidden | Service |

### Code Duplication

| Service A | Service B | Duplication |
|-----------|-----------|-------------|
| `LegalizationService` (257 lines) | `PettyCashService` (265 lines) | ~80% structural |
| `InvoiceHistoryService` | `NoveltyHistoryService` | Recording patterns duplicated |
| `InvoicePipelineService` | `NoveltyPipelineService` | Field visibility/filtering structure |

---

## 10. Architecture Strengths (Preserve These)

- **ServiceResult pattern** -- Clean `ok()`/`fail()` with `readonly` properties
- **Constants layer** -- 9 classes, zero magic strings in business logic
- **WebhookService retry** -- Exponential backoff with 4xx/5xx distinction
- **DianCrosscheckService async retry** -- Application-level retry with attempt counting
- **N8nService as Facade** -- Clean abstraction over webhook + settings
- **DocumentUploadTrait / HistoryNormalizationTrait** -- Good code reuse
- **Constructor injection with nullable fallbacks** -- Testable defaults
- **Audit trail system** -- Field-level change tracking across 3 entity types
- **RBAC implementation** -- Comprehensive role-based access with module mapping
