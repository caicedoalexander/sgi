# Architecture Audit Report — SGI

**Project:** Sistema de Gestión Interna (SGI)
**Path:** `/home/alexander/Documentos/dev/sgi/src`
**Stack:** CakePHP 5.3 / PHP 8.2+ / MySQL · Layered monolith · Spanish UI
**Audit Level:** standard · **Date:** 2026-04-30

---

## 1. Executive Summary

SGI is a **well-built layered CakePHP monolith** with intentional service decomposition (~36 services), already adopting Strategy, Adapter, Circuit Breaker, Retry/Backoff, Policy, Correlation IDs, and `ServiceResult`. For its chosen architectural style, it sits around **80–85 % health**.

It does **not** adopt — and does not claim to adopt — DDD, Clean Architecture, full Hexagonal, CQRS, Event Sourcing, EDA, Outbox, or Saga. Most "missing pattern" findings are **opportunities**, not violations.

The findings that matter today are concrete bugs and inconsistencies: a missing transaction in payment authorization, the `MailerInterface` port bypassed by its main consumer, a fixed-window rate limiter with a real race condition (and not even registered in middleware), a god-service approaching 800 lines, and saga-shaped multi-aggregate flows without compensation or durable retry.

| Metric | Value |
|---|---|
| Critical issues | **6** |
| Warnings | **15** |
| Cross-pattern conflicts | **6** |
| Adopted patterns | Layered, Strategy, Adapter (partial), Circuit Breaker, Retry, Policy, ServiceResult, Correlation IDs, Structured Logging (partial) |
| Absent patterns | DDD, Clean, CQRS, ES, EDA, **Outbox**, Saga, **Specification**, **Read Model**, **Bulkhead**, **State (polymorphic)**, **Factory/DI Container** |
| Overall compliance | **62 %** |

---

## 2. Pattern Detection Matrix

| Domain | Pattern | Detected | Compliance | Status |
|---|---|---|---:|---|
| **Architectural** | Layered | ✅ | 92 % | OK |
| | 12-Factor | partial | 70 % | WARN |
| | Hexagonal | partial (sprouts) | 35 % | WARN |
| | DDD / Clean / CQRS / ES / EDA | ❌ | n/a | not adopted |
| **Stability** | Circuit Breaker | ✅ `Service/CircuitBreaker.php` | 75 % | OK |
| | Retry / Backoff | ✅ inline in `WebhookService` | 50 % | WARN |
| | Rate Limiter | ✅ but **not wired** + race condition | 25 % | 🔴 |
| | Timeout | partial (HTTP only) | 60 % | WARN |
| | Bulkhead | ❌ | 0 % | MISSING |
| | Fallback | ✅ in `CircuitBreaker` | 70 % | OK |
| **Behavioral** | Strategy | ✅ `Service/Strategy/` | 85 % | OK |
| | State | ❌ procedural arrays | 30 % | WARN |
| | Iterator | ✅ via CakePHP ResultSet | 100 % | OK |
| | Template Method, Decorator, Chain, Null Object, Visitor, Memento | ❌ | 0 % | OPPORTUNITY |
| **Structural** | Adapter | ✅ `Service/Adapter/` (bypassed in NotificationService) | 60 % | WARN |
| | Facade | ✅ `DashboardStatisticsService` | 85 % | OK |
| | Proxy / Composite / Bridge / Flyweight | ❌ | 0 % | INFO |
| **Creational** | Factory / DI Container | ❌ `?? new X()` everywhere | 20 % | WARN |
| | Builder, Object Pool, Abstract Factory | ❌ | — | OPPORTUNITY |
| **Enterprise** | Policy | ✅ `InvoiceFieldAccessPolicy`, `AuthorizationService` | 80 % | OK |
| | Specification | ❌ | 0 % | OPPORTUNITY |
| | Read Model | ❌ counters re-queried per request | 30 % | WARN |
| **Integration** | Outbox | ❌ | 0 % | 🔴 |
| | Saga | ❌ saga-shaped flows but no primitives | 20 % | 🔴 |
| | Idempotency | partial (one method) | 50 % | WARN |
| | Distributed Lock | DB-level only (`FOR UPDATE`) | 60 % | OK for now |
| **Observability** | Correlation ID | ✅ middleware | 100 % | OK |
| | Structured Logger | ✅ but underused | 40 % | WARN |
| | Health / Metrics | ❌ no `/health`, no metrics | 0 % | WARN |

---

## 3. Critical Issues

### 🔴 C1. `InvoicePaymentService::authorizePayment()` mutates 4 tables without a transaction
**File:** `src/Service/InvoicePaymentService.php` (≈ lines 108–162)
The method saves `InvoicePayments`, recalculates aggregate state, saves `Invoices`, writes `InvoiceHistories`, and may mutate `AdvanceLegalizations` — all as separate `save()` calls with **no `Connection::transactional(...)`**. `registerPayment()` and `editPayment()` in the same file *do* use transactions, so the asymmetry is the bug. A failure between steps leaves the system inconsistent (payment authorized, invoice still in `autorizacion_pago`, no history row).
**Fix:** Wrap the body in `transactional()` and emit downstream side effects (`closeOnRefundAuthorized`, legalization `initialize`) **after commit**.

### 🔴 C2. `RateLimitMiddleware` is broken AND not registered
**Files:** `src/Middleware/RateLimitMiddleware.php` (lines 39–50), `src/Application.php` (lines 38–55)
Two distinct problems:
1. **Not wired into `MiddlewareQueue`** → `/login`, `/approve/{token}` and all other endpoints have **zero rate limiting** in production despite CLAUDE.md claiming the middleware is part of the stack.
2. The implementation itself is a **read-modify-write fixed-window counter without atomic ops** — two concurrent requests at `current = max-1` both pass. `Cache::write()` resets the TTL on every increment so the window never closes. `REMOTE_ADDR` ignores `X-Forwarded-For`, so behind a proxy all traffic looks like one IP.
**Fix:** Use Redis `INCR`/`EXPIRE` (atomic) or token bucket; honor `X-Forwarded-For` from a configured trusted-proxy list; register the middleware in `Application::middleware()` (scoped to `/login` and `/approve/*`); add `Retry-After`.

### 🔴 C3. Hexagonal port `MailerInterface` is bypassed by its main consumer
**Files:** `src/Service/Interface/MailerInterface.php`, `src/Service/Adapter/CakeMailerAdapter.php`, `src/Service/NotificationService.php` (lines 53–73, 99–120)
`NotificationService::sendApprovalLinkNotification()` and `sendNoveltyApprovalEmail()` instantiate `Cake\Mailer\Mailer` directly and call `TransportFactory::setConfig()` themselves — duplicating exactly what `CakeMailerAdapter` already does. The abstraction gives a false sense of decoupling: tests cannot mock email, and configuration drift is inevitable.
**Fix:** Inject `MailerInterface` into `NotificationService`; delete the duplicated `configureTransport()`.

### 🔴 C4. Saga-shaped flows have no compensation or durable retry
**Files:** `InvoicePipelineService::saveAndAdvance` (438–535), `InvoicePaymentService::authorizePayment` / `rejectPayment`, `AdvanceLegalizationService::initialize` ↔ `_setStatus` ↔ `legalizeLinkedInvoices`
The Anticipo → Pagada → Legalization handoff, partial-payment regression, and refund rejection reopening are multi-step, multi-aggregate workflows implemented as synchronous service-method chains with no saga identity, no compensating action, and no replay log beyond the field-level `invoice_histories` audit. Combined with C1, a mid-flow failure can leave orphaned state with no automated recovery.
**Fix:** Introduce an `outbox` table (event_type, payload, occurred_at, processed_at). Emit "domain events" inside the existing transactions; deliver via a CLI worker. This unlocks email reliability (W8/A4) and saga modeling later, without rewriting business logic now.

### 🔴 C5. `InvoicePipelineService` is becoming a god-service (771 lines)
**File:** `src/Service/InvoicePipelineService.php`
Cohabiting responsibilities: state-machine encoding, transition validation, transactional save+advance, regression, lock-message resolution (`isLockedByPettyCash`, `isLockedByPaidScheduling`), bulk legalization promotion, role-aware error filtering. SRP is strained; OCP is broken (adding a state forces edits in 4+ methods); document-type early-returns (`DOCTYPE_LEGALIZACION`) bury domain rules inside transition logic.
**Fix:** Extract `InvoiceLockPolicy` (the three `isLocked*` + `getEditLockMessage`/`getRegressionLockMessage`) and `InvoiceTransitionValidator` (`TRANSITION_REQUIREMENTS` + `validateTransitionRequirements` + `filterAdvanceErrorsForRole`). Target ≤ 300 LOC per service.

### 🔴 C6. Bidirectional service coupling: Pipeline ↔ Payment ↔ AdvanceLegalization
- `InvoicePipelineService::saveAndAdvance` → `advanceLegalizationService->initialize()`
- `InvoicePaymentService::authorizePayment` → `advanceLegalizationService->initialize()` and `closeOnRefundAuthorized()`
- `AdvanceLegalizationService::_setStatus` → `_getPipelineService()->legalizeLinkedInvoices()`

This is a true circular dependency, masked only by lazy-init. Domain events (`InvoicePaidEvent`, `AdvanceLegalizedEvent`) with subscribers would break the cycle cleanly. C4's outbox is a prerequisite.

---

## 4. Warnings

| ID | Title | Where | Severity |
|---|---|---|---|
| W1 | Logging double-tracked: `Cake\Log\Log` used in services, `StructuredLogger` only in `WebhookService` → correlation IDs lost across services | `NotificationService`, `InvoiceApprovalService`, `InvoicePaymentService` | 🟠 |
| W2 | Anemic domain + duplicated truth: `Invoice::canAdvanceTo()` (entity) disagrees with `InvoicePipelineService::canAdvance()` (service uses `TRANSITIONS`, entity uses `PIPELINE_STATUSES` index) | `Model/Entity/Invoice.php`, `InvoicePipelineService` | 🟠 |
| W3 | `?? new ServiceClass()` DI fallback across **~all** services and controllers — service-locator anti-pattern in disguise. Same `InvoiceHistoryService` instantiated multiple times per request | `src/Service/*`, `InvoicesController::initialize` | 🟠 |
| W4 | Permission-failure redirect uses `referer()` → potential redirect loop if user lands on a forbidden URL directly | `AppController::_enforcePermission()` lines 134–142 | 🟠 |
| W5 | `AuthorizationService` per-instance cache is dead because `AppController` instantiates a fresh service on each call | `AuthorizationService`, `AppController` | 🟡 |
| W6 | No idempotency on `registerPayment` and `advance` — double-clicks can create duplicate payments and double-advance state | `InvoicePaymentService` | 🟠 |
| W7 | No `/health`, `/ready`, or metrics endpoint. Circuit-breaker state observable only via cache inspection | (missing) | 🟠 |
| W8 | `NotificationService` swallows SMTP exceptions to logs while approval token is already persisted → user sees success, email never arrives | `NotificationService`, `InvoiceApprovalService::assignApprovers` | 🟠 |
| W9 | Pipeline state encoded as `const TRANSITIONS` arrays + `match`/`switch` chains, not polymorphic State objects. Same procedural pattern repeated in `NoveltyPipelineService`, `PaymentSchedulingPipelineService` | `InvoicePipelineService` and siblings | 🟠 |
| W10 | Document-type conditionals (`DOCTYPE_LEGALIZACION`, `DOCTYPE_ANTICIPO`) repeated in 4 services — OCP violation | pipeline + policy + payment + access policy | 🟠 |
| W11 | `SidebarCounterService::getCounters()` runs ~13 separate count queries on every page load | `SidebarCounterService` | 🟡 |
| W12 | Silent exception swallowing returning `0` / `null` with no log trail | `DashboardStatisticsService::_safeCount`, `SidebarCounterService::getCounters`, `InvoiceApprovalStrategy::getEntity` | 🟡 |
| W13 | Retry logic locked inside `WebhookService::executeWithRetry()` — `NotificationService::deliver()` has zero retry on transient SMTP errors | `WebhookService`, `NotificationService` | 🟠 |
| W14 | No bulkhead on outbound integrations: webhook + SMTP run on user-facing PHP-FPM workers; a slow n8n endpoint can starve interactive requests | `WebhookService`, `NotificationService` | 🟠 |
| W15 | `ServiceResult` adoption inconsistent: `saveAndAdvance` returns `array`, `registerPayment` returns `ServiceResult` — callers must know which | mixed across services | 🟡 |

---

## 5. Cross-Pattern Conflicts

1. **Strategy vs State.** `InvoiceApprovalStrategy::apply()` instantiates a fresh `InvoicePipelineService` to perform the actual transition because there is no State pattern. The strategy is forced to know about pipeline internals.
2. **Adapter vs duplicated SMTP.** `MailerInterface` exists, `CakeMailerAdapter` exists — yet `NotificationService` re-implements the port. The presence of the interface lies about what's abstracted.
3. **CircuitBreaker + no-Outbox.** When SMTP/n8n is down longer than `recoveryTimeoutSeconds`, the breaker returns the fallback and callers log-and-continue. Approval tokens are persisted, emails are silently lost.
4. **Correlation ID + raw `Log::*`.** `CorrelationIdMiddleware` injects an ID, `StructuredLogger` reads it, but most services log via `Cake\Log\Log` directly → the trace context dies at every service boundary.
5. **Inline retry + breaker.** `WebhookService` wraps its 3-attempt retry inside one `CircuitBreaker::call()`, so 3 retries count as one breaker failure. With a CB threshold of 3 that means ~9 actual attempts before the breaker opens. Document or invert — currently surprising.
6. **Circular service graph + `?? new` fallbacks.** Pipeline ↔ Payment ↔ AdvanceLegalization (C6) is reachable only because each constructor lazily builds its own collaborator graph, multiplying instantiations.

---

## 6. Pattern Recommendations

### Resilience & Integration
| Problem | Recommended | Skill |
|---|---|---|
| Saga-shaped flows + lost emails on CB-open (C4, W8) | Outbox pattern | `/acc:create-outbox-pattern` |
| Hardcoded retry locked in `WebhookService` (W13) | Reusable Retry policy | `/acc:create-retry-pattern` |
| Race-prone fixed-window limiter (C2) | Token bucket / atomic counter | `/acc:create-rate-limiter` |
| Outbound integrations on FPM workers (W14) | Bulkhead / queue isolation | `/acc:create-bulkhead` |
| No idempotency on user mutations (W6) | Idempotency handler | `/acc:create-idempotency-handler` |
| No `/health` (W7) | Health Check | `/acc:create-health-check` |
| Multi-aggregate workflows (C4, C6) | Domain event + subscribers | `/acc:create-domain-event` |

### Behavioral & Structural
| Problem | Recommended | Skill |
|---|---|---|
| Procedural state arrays (W9) | State pattern | `/acc:create-state` |
| Document-type branches (W10) | Strategy / Policy per type | `/acc:create-policy` or `/acc:create-strategy` |
| Repeated query predicates in services (sidebar counters, locks) | Specification | `/acc:create-specification` |
| Sidebar counters re-queried per request (W11) | Read Model + cache | `/acc:create-read-model` |
| Missing approver/provider null fallbacks | Null Object | `/acc:create-null-object` |
| God service (C5) | Extract `InvoiceLockPolicy` + `InvoiceTransitionValidator` | `/acc:create-policy` |

### Construction & Wiring
| Problem | Recommended | Skill |
|---|---|---|
| `?? new X()` everywhere (W3) | CakePHP `Container` registration in `Application::services()` | refactor (no skill) |
| Telescoping pipeline ctor with 4 deps | Builder for service config | `/acc:create-builder` |

---

## 7. Prioritized Action Items

### 🔴 Critical — fix immediately
1. **Add transaction to `InvoicePaymentService::authorizePayment()`** (C1). 1-line wrap + move post-commit side effects out.
2. **Fix `RateLimitMiddleware` and register it** (C2). Atomic counter + scoped wiring on `/login` and `/approve/*` + trusted-proxy `X-Forwarded-For` handling.
3. **Make `NotificationService` consume `MailerInterface`** (C3). Delete duplicated SMTP code.

### 🟠 High — current sprint
4. **Introduce Outbox table + worker** (C4, W8). Highest leverage change in the codebase: unlocks reliable emails, future EDA/Saga, and fixes silent failure modes.
5. **Extract `InvoiceLockPolicy` + `InvoiceTransitionValidator`** from `InvoicePipelineService` (C5).
6. **Refactor pipeline to State pattern** (W9). Replaces array-based machine; removes OCP violations across pipeline/payment/policy.
7. **Replace `?? new` with CakePHP `Container`** (W3). Register at minimum `InvoiceHistoryService`, `CircuitBreaker`, all pipeline + payment + legalization services.
8. **Extract Retry policy** (W13). Apply to `NotificationService::deliver()` and any future external integration.
9. **Add idempotency** for `registerPayment` / `advance` (W6).

### 🟡 Medium — next sprint
10. Standardize all services on `ServiceResult` returns (W15).
11. Replace direct `Cake\Log\Log` calls in services with injected `StructuredLogger` (W1).
12. Resolve `Invoice::canAdvanceTo()` vs `InvoicePipelineService::canAdvance()` duplication (W2).
13. Add `/health` endpoint with DB + cache + circuit-breaker introspection (W7).
14. Cache sidebar counters per role for ~30 s, or build a `sidebar_counters` read model (W11).
15. Write a one-page ADR set under `docs/adr/` capturing deliberate decisions: "we use Layered, not DDD"; "we use ServiceResult"; "Outbox deferred to phase 2". This documents trade-offs and pre-empts future audits flagging them as bugs.

### 🟢 Low — backlog
16. Replace permission-failure redirects with 403 view (W4).
17. Lift `AuthorizationService` cache to static / request-scoped (W5).
18. Add `User::displayName()` to remove `?? '—'` ternaries.
19. `DocumentTypePolicy` polymorphic family for `DOCTYPE_*` branching (W10).

---

### Bottom line

For its chosen layered style, SGI is well above average. The single highest-leverage move is **introducing an Outbox table** — it directly addresses C4, W8, the CircuitBreaker/email data-loss conflict, and lays the foundation for breaking the Pipeline ↔ Payment ↔ Legalization cycle (C6) via domain events. After that, the State pattern refactor and the `Container` migration eliminate the structural friction that's making `InvoicePipelineService` grow.
