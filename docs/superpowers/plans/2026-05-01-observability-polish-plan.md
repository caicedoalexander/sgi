# Plan 7 — Observability + Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cierre del roadmap de auditoría: estructurar logs (W1, W12), cachear sidebar (W11), uniformar `ServiceResult` en métodos con side effects (W15), reemplazar el redirect-a-referer de permisos por una vista 403 (W4) y dejar 8 ADRs documentando las decisiones estratégicas del proyecto.

**Architecture:** Seis frentes secuenciales. (1) `StructuredLogger` reemplaza `Log::*` directos en 3 servicios. (2) Catches específicos con política híbrida (UI degradable / side-effect / nullable finder) en 7 archivos. (3) `Cache::remember` con TTL 30s en `SidebarCounterService` + nuevo engine `sidebar` en `config/app.php`. (4) `ServiceResult` en 4 servicios (Invoice/Novelty/PaymentScheduling pipelines + InvoiceApprovalService) y sus callers. (5) `ForbiddenException` + rama nueva en `templates/Error/error400.php`. (6) `docs/adr/` con README, template y 8 ADRs.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, FileEngine cache, MariaDB/MySQL.

**Política del proyecto (recordatorio):** este proyecto NO usa tests automatizados (ver `CLAUDE.md` § Testing Policy y memoria `feedback_no_tests.md`). Cada tarea termina en validación manual descrita en el spec — los pasos del usuario sobre `php bin/cake server` quedan documentados pero no se ejecutan desde aquí. La revisión de calidad se corre **una sola vez** al final del plan, no por tarea.

**Spec:** `docs/superpowers/specs/2026-05-01-observability-polish-design.md`

---

## File Structure

### Modificados

- `src/Service/NotificationService.php` — `Log::info` ×2 → `StructuredLogger`.
- `src/Service/CircuitBreaker.php` — `Log::*` ×3 → `StructuredLogger` con contexto `CircuitBreaker.{name}`.
- `src/Service/DianCrosscheckService.php` — `Log::warning` → `StructuredLogger`.
- `src/Service/Dashboard/InvoiceStatisticsService.php` — añade `StructuredLogger`, catches específicos.
- `src/Service/Dashboard/EmployeeStatisticsService.php` — idem.
- `src/Service/SidebarCounterService.php` — añade `StructuredLogger`, extrae `_buildCounters`/`_emptyCounters`, envuelve en `Cache::remember`.
- `src/Service/Strategy/InvoiceApprovalStrategy.php` — añade `StructuredLogger`, catch específico.
- `src/Service/Strategy/NoveltyApprovalStrategy.php` — idem.
- `src/Service/LeaveDocumentService.php` — añade `StructuredLogger`, catch específico (línea 366).
- `src/Service/ExcelImportService.php` — añade `StructuredLogger`, catches específicos (líneas 92, 269).
- `config/app.php` — engine `sidebar` bajo `Cache`.
- `src/Service/InvoicePipelineService.php` — `saveAndAdvance/advance/regress` → `ServiceResult`.
- `src/Service/NoveltyPipelineService.php` — `advance/advanceGroup/reject` → `ServiceResult`.
- `src/Service/PaymentSchedulingPipelineService.php` — `regress` → `ServiceResult`.
- `src/Service/InvoiceApprovalService.php` — `assignApprovers/processResponse/sendApprovalLinks/modifyApprovers` → `ServiceResult`.
- `src/Controller/InvoicesController.php` — callers de los 3 pipeline methods + 2 approval methods.
- `src/Controller/ExternalApprovalsController.php` — caller de `processResponse` y `pipelineService->advance`.
- `src/Controller/EmployeeNoveltiesController.php` — callers de NoveltyPipeline `advance/reject`.
- `src/Controller/NoveltyLiquidationDocsController.php` — caller de `advanceGroup`.
- `src/Controller/PaymentSchedulingsController.php` — caller de `regress`.
- `src/Controller/AppController.php` — `_enforcePermission()` lanza `ForbiddenException`.
- `templates/Error/error400.php` — rama nueva para `ForbiddenException` con layout `default`.
- `webroot/css/styles.css` — clase `.sgi-forbidden-page`.
- `docs/audits/architecture-audit-roadmap.md` — actualizar tabla de estado.

### Nuevos

- `docs/adr/README.md` — índice maestro de ADRs.
- `docs/adr/template.md` — esqueleto vacío.
- `docs/adr/0001-layered-architecture-no-ddd.md`
- `docs/adr/0002-service-result-instead-of-exceptions.md`
- `docs/adr/0003-email-log-sync-instead-of-outbox.md`
- `docs/adr/0004-sidebar-counters-cache-30s.md`
- `docs/adr/0005-di-container-application-services.md`
- `docs/adr/0006-state-pattern-invoice-pipeline.md`
- `docs/adr/0007-domain-events-eventmanager-sync.md`
- `docs/adr/0008-optimistic-concurrency-and-idempotency.md`

---

## Task 1: W1 — `NotificationService` → `StructuredLogger`

**Files:**
- Modify: `src/Service/NotificationService.php`

- [ ] **Step 1: Reemplazar el import y añadir el campo logger**

Abrir `src/Service/NotificationService.php`. Localizar la línea `use Cake\Log\Log;` y reemplazarla por:

```php
use App\Service\StructuredLogger;
```

Localizar el constructor (busque `public function __construct`) y añadir el campo `private StructuredLogger $logger;` con la clase, y la asignación al final del constructor:

```php
$this->logger = new StructuredLogger('Notification');
```

Si el constructor ya tiene asignaciones, añadir esa línea **al final** (después de la última asignación).

- [ ] **Step 2: Reemplazar `Log::info(...)` línea 80**

Buscar la línea:

```php
Log::info("Approval link sent to {$recipient->email} for invoice #{$invoice->id}");
```

Reemplazar por:

```php
$this->logger->info('approval_link_sent', [
    'recipient' => $recipient->email,
    'invoice_id' => $invoice->id,
    'context' => 'invoice',
]);
```

- [ ] **Step 3: Reemplazar `Log::info(...)` línea 129**

Buscar la línea:

```php
Log::info("Novelty approval link sent to {$approver->email} for novelty #{$novelty->id}");
```

Reemplazar por:

```php
$this->logger->info('approval_link_sent', [
    'recipient' => $approver->email,
    'novelty_id' => $novelty->id,
    'context' => 'novelty',
]);
```

- [ ] **Step 4: Verificar que no queda ningún `Log::` en el archivo**

```bash
grep -n "Log::" src/Service/NotificationService.php
```

Esperado: sin output (cero matches).

- [ ] **Step 5: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "feat(plan-7): NotificationService usa StructuredLogger (W1)"
```

---

## Task 2: W1 — `CircuitBreaker` → `StructuredLogger`

**Files:**
- Modify: `src/Service/CircuitBreaker.php`

- [ ] **Step 1: Reemplazar import y añadir campo logger**

Abrir `src/Service/CircuitBreaker.php`. Localizar `use Cake\Log\Log;` y reemplazar por:

```php
use App\Service\StructuredLogger;
```

Añadir el campo en la lista de propiedades (al lado de `private string $name;` u otras propiedades existentes):

```php
private StructuredLogger $logger;
```

En el constructor, **después** de la asignación de `$this->name`, añadir:

```php
$this->logger = new StructuredLogger('CircuitBreaker.' . $name);
```

- [ ] **Step 2: Reemplazar `Log::warning` línea 47 (breaker OPEN)**

Buscar:

```php
Log::warning("CircuitBreaker [{$this->name}]: OPEN — skipping call");
```

Reemplazar por:

```php
$this->logger->warning('open_skip_call', ['name' => $this->name]);
```

- [ ] **Step 3: Reemplazar `Log::error` línea 64 (breaker failure)**

Buscar:

```php
Log::error("CircuitBreaker [{$this->name}]: failure — {$e->getMessage()}");
```

Reemplazar por:

```php
$this->logger->error('call_failure', [
    'name' => $this->name,
    'exception' => $e->getMessage(),
]);
```

- [ ] **Step 4: Reemplazar `Log::warning` línea 93 (breaker OPENED)**

Buscar:

```php
Log::warning("CircuitBreaker [{$this->name}]: OPENED after {$count} failures");
```

Reemplazar por:

```php
$this->logger->warning('opened', [
    'name' => $this->name,
    'failures' => $count,
]);
```

- [ ] **Step 5: Verificar que no queda ningún `Log::` en el archivo**

```bash
grep -n "Log::" src/Service/CircuitBreaker.php
```

Esperado: sin output.

- [ ] **Step 6: Commit**

```bash
git add src/Service/CircuitBreaker.php
git commit -m "feat(plan-7): CircuitBreaker usa StructuredLogger (W1)"
```

---

## Task 3: W1 — `DianCrosscheckService` → `StructuredLogger`

**Files:**
- Modify: `src/Service/DianCrosscheckService.php`

- [ ] **Step 1: Reemplazar import y añadir campo logger**

Abrir `src/Service/DianCrosscheckService.php`. Reemplazar `use Cake\Log\Log;` por:

```php
use App\Service\StructuredLogger;
```

Añadir el campo `private StructuredLogger $logger;` en las propiedades. En el constructor, al final, añadir:

```php
$this->logger = new StructuredLogger('DianCrosscheck');
```

- [ ] **Step 2: Reemplazar `Log::warning` línea 89**

Buscar:

```php
Log::warning("DianCrosscheck #{$entity->id}: webhook failed, queued for retry — {$result['error']}");
```

Reemplazar por:

```php
$this->logger->warning('webhook_failed_queued_for_retry', [
    'crosscheck_id' => $entity->id,
    'error' => $result['error'] ?? 'unknown',
]);
```

- [ ] **Step 3: Verificar**

```bash
grep -n "Log::" src/Service/DianCrosscheckService.php
```

Esperado: sin output.

- [ ] **Step 4: Verificación global del fin de W1**

```bash
grep -rn "Log::" src/Service/ --include="*.php"
```

Esperado: solamente las 3 líneas dentro de `src/Service/StructuredLogger.php` (28, 38, 48).

- [ ] **Step 5: Commit**

```bash
git add src/Service/DianCrosscheckService.php
git commit -m "feat(plan-7): DianCrosscheckService usa StructuredLogger (W1)"
```

---

## Task 4: W12 — `Dashboard/InvoiceStatisticsService` (catches específicos)

**Files:**
- Modify: `src/Service/Dashboard/InvoiceStatisticsService.php`

- [ ] **Step 1: Añadir imports y campo logger**

Abrir el archivo. En la sección de `use`, añadir:

```php
use App\Service\StructuredLogger;
use Cake\Database\Exception\DatabaseException;
```

Verificar que `use Exception;` ya esté presente (si no, añadirlo).

Añadir propiedad y asignación en el constructor (si no hay constructor explícito, **crear** uno):

```php
private StructuredLogger $logger;

public function __construct()
{
    $this->logger = new StructuredLogger('Dashboard.InvoiceStats');
}
```

Si ya existe un constructor con dependencias, añadir sólo la línea `$this->logger = new StructuredLogger('Dashboard.InvoiceStats');` al final.

- [ ] **Step 2: Reemplazar el catch en `getRecent()` (línea ~51)**

Buscar:

```php
} catch (Exception $e) {
    return [];
}
```

Dentro del método `getRecent()`, reemplazar por:

```php
} catch (DatabaseException $e) {
    // UI degradable: dashboard no debe romper si la query de "recientes" falla.
    $this->logger->error('recent_invoices_query_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return [];
}
```

- [ ] **Step 3: Reemplazar el catch en `getFinancialStats()` (línea ~117)**

Mismo patrón. Dentro del método `getFinancialStats()`, encontrar el bloque `} catch (Exception $e) { return []; }` y reemplazar por:

```php
} catch (DatabaseException $e) {
    // UI degradable: dashboard muestra stats vacíos en lugar de fallar.
    $this->logger->error('financial_stats_query_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return [];
}
```

- [ ] **Step 4: Reemplazar el catch en el método de la línea ~171**

Identificar qué método contiene la línea 171 (es probablemente `getStatusBreakdown` o similar). Reemplazar el catch por el mismo patrón con un `event` específico:

```php
} catch (DatabaseException $e) {
    // UI degradable
    $this->logger->error('status_breakdown_query_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return [];
}
```

- [ ] **Step 5: Reemplazar el catch en el método de la línea ~190**

El catch retorna `0` (no `[]`). Reemplazar por:

```php
} catch (DatabaseException $e) {
    // UI degradable: contador queda en 0 si la query falla.
    $this->logger->error('count_query_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return 0;
}
```

- [ ] **Step 6: Verificar que no quedan `catch (Exception` genéricos**

```bash
grep -n "catch (Exception" src/Service/Dashboard/InvoiceStatisticsService.php
```

Esperado: sin output (todos migraron a `DatabaseException`).

- [ ] **Step 7: Commit**

```bash
git add src/Service/Dashboard/InvoiceStatisticsService.php
git commit -m "feat(plan-7): catches específicos + log estructurado en InvoiceStatisticsService (W12)"
```

---

## Task 5: W12 — `Dashboard/EmployeeStatisticsService` (catches específicos)

**Files:**
- Modify: `src/Service/Dashboard/EmployeeStatisticsService.php`

- [ ] **Step 1: Añadir imports y campo logger**

En la sección de `use` añadir:

```php
use App\Service\StructuredLogger;
use Cake\Database\Exception\DatabaseException;
```

Añadir el campo y la inicialización en el constructor (creándolo si no existe):

```php
private StructuredLogger $logger;

public function __construct()
{
    $this->logger = new StructuredLogger('Dashboard.EmployeeStats');
}
```

Si ya existe un constructor, añadir sólo `$this->logger = new StructuredLogger('Dashboard.EmployeeStats');` al final.

- [ ] **Step 2: Migrar los 5 catches (líneas ~36, ~76, ~130, ~172, ~191)**

Para cada uno de los 5 bloques `} catch (Exception $e) { return []; }` o `... return 0; }`, identificar el método contenedor y aplicar el patrón:

Para los que retornan `[]`:

```php
} catch (DatabaseException $e) {
    // UI degradable
    $this->logger->error('<event_name>_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return [];
}
```

Para el que retorna `0` (línea ~191):

```php
} catch (DatabaseException $e) {
    // UI degradable: contador queda en 0 si la query falla.
    $this->logger->error('<event_name>_count_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return 0;
}
```

`<event_name>` debe describir el método (`recent_employees`, `employee_status_breakdown`, etc.).

- [ ] **Step 3: Verificar**

```bash
grep -n "catch (Exception" src/Service/Dashboard/EmployeeStatisticsService.php
```

Esperado: sin output.

- [ ] **Step 4: Commit**

```bash
git add src/Service/Dashboard/EmployeeStatisticsService.php
git commit -m "feat(plan-7): catches específicos + log estructurado en EmployeeStatisticsService (W12)"
```

---

## Task 6: W12 — `SidebarCounterService` (logger + helpers `_buildCounters`/`_emptyCounters`)

> Esta tarea **no aplica el cache aún** — solo prepara la estructura interna para que el cache (Task 9) pueda envolver una sola llamada y el catch tenga logger disponible. El comportamiento de `getCounters()` no cambia.

**Files:**
- Modify: `src/Service/SidebarCounterService.php`

- [ ] **Step 1: Añadir imports y campo logger**

En la sección de `use` añadir:

```php
use App\Service\StructuredLogger;
use Cake\Database\Exception\DatabaseException;
```

En el constructor, después de la última asignación, añadir:

```php
$this->logger = new StructuredLogger('Sidebar');
```

Y declarar la propiedad junto a las otras readonly:

```php
private StructuredLogger $logger;
```

(Nota: `$logger` no es `readonly` porque se asigna después de las readonly del constructor — usar regular `private`).

- [ ] **Step 2: Extraer `_buildCounters()` y `_emptyCounters()`**

Reemplazar el cuerpo completo del método `getCounters()` por:

```php
public function getCounters(string $roleName): array
{
    try {
        return $this->_buildCounters($roleName);
    } catch (DatabaseException $e) {
        // UI degradable: si una query falla, el sidebar muestra ceros en lugar de romper la página.
        $this->logger->error('sidebar_counters_failed', [
            'role' => $roleName,
            'exception' => $e->getMessage(),
        ]);

        return $this->_emptyCounters();
    }
}

private function _buildCounters(string $roleName): array
{
    return [
        'sidebarCounters' => $this->getInvoiceStatusCounters($roleName),
        'totalInvoicesCount' => $this->getCount(
            'Invoices',
            ['document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO],
        ),
        'rejectedInvoicesCount' => $this->getCount(
            'Invoices',
            [
                'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
                'document_type !=' => InvoiceConstants::DOCTYPE_ANTICIPO,
            ],
        ),
        'overdueInvoicesCount' => $this->getOverdueInvoicesCount(),
        'pettyCashCount' => $this->getCount(
            'PettyCashRecords',
            ['status !=' => PettyCashConstants::STATUS_PAGADO],
        ),
        'pettyCashMineCount' => $this->getPettyCashMineCount($roleName),
        'advancesMineCount' => $this->getAdvancesMineCount($roleName),
        'noveltiesCount' => $this->getNoveltiesCount($roleName),
        'rejectedNoveltiesCount' => $this->getCount(
            'EmployeeNovelties',
            ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
        ),
        'activeNoveltiesCount' => $this->getActiveNoveltiesCount(),
        'liquidationMineCount' => $this->getLiquidationMineCount($roleName),
        'liquidationRejectedCount' => $this->getCount(
            'NoveltyLiquidationDocs',
            ['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA],
        ),
        'advancesPendingLegalizationCount' => $this->getCount(
            'AdvanceLegalizations',
            ['status !=' => AdvanceConstants::STATUS_LEGALIZADA],
        ),
    ];
}

private function _emptyCounters(): array
{
    return [
        'sidebarCounters' => [],
        'totalInvoicesCount' => 0,
        'rejectedInvoicesCount' => 0,
        'overdueInvoicesCount' => 0,
        'pettyCashCount' => 0,
        'pettyCashMineCount' => 0,
        'advancesMineCount' => 0,
        'noveltiesCount' => 0,
        'rejectedNoveltiesCount' => 0,
        'activeNoveltiesCount' => 0,
        'liquidationMineCount' => 0,
        'liquidationRejectedCount' => 0,
        'advancesPendingLegalizationCount' => 0,
    ];
}
```

- [ ] **Step 3: Eliminar el `use Exception;` si ya no se usa**

```bash
grep -n "Exception" src/Service/SidebarCounterService.php
```

Si sólo aparece en el `use`, eliminarlo. Si aparece en otros lugares, mantenerlo.

- [ ] **Step 4: Commit**

```bash
git add src/Service/SidebarCounterService.php
git commit -m "feat(plan-7): SidebarCounterService — logger + helpers _build/_empty (W12)"
```

---

## Task 7: W12 — Strategies (`InvoiceApprovalStrategy`, `NoveltyApprovalStrategy`)

**Files:**
- Modify: `src/Service/Strategy/InvoiceApprovalStrategy.php`
- Modify: `src/Service/Strategy/NoveltyApprovalStrategy.php`

- [ ] **Step 1: `InvoiceApprovalStrategy` — añadir logger e imports**

Abrir `src/Service/Strategy/InvoiceApprovalStrategy.php`. Añadir:

```php
use App\Service\StructuredLogger;
use Cake\Datasource\Exception\RecordNotFoundException;
```

Añadir propiedad y asignación en constructor:

```php
private StructuredLogger $logger;

// dentro del constructor, al final:
$this->logger = new StructuredLogger('Strategy.InvoiceApproval');
```

- [ ] **Step 2: `InvoiceApprovalStrategy` — catch específico en `getEntity()` (línea ~95)**

Buscar el bloque actual:

```php
} catch (Exception $e) {
    return null;
}
```

Reemplazar por:

```php
} catch (RecordNotFoundException $e) {
    // Finder nullable: la factura puede no existir si fue eliminada después de emitir el token.
    $this->logger->warning('entity_not_found', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);

    return null;
}
```

Si después del cambio queda `use Exception;` huérfano, eliminarlo.

- [ ] **Step 3: `NoveltyApprovalStrategy` — añadir logger e imports**

Abrir `src/Service/Strategy/NoveltyApprovalStrategy.php`. Mismas adiciones:

```php
use App\Service\StructuredLogger;
use Cake\Datasource\Exception\RecordNotFoundException;
```

```php
private StructuredLogger $logger;

// constructor, al final:
$this->logger = new StructuredLogger('Strategy.NoveltyApproval');
```

- [ ] **Step 4: `NoveltyApprovalStrategy` — catch específico en `getEntity()` (línea ~63)**

Mismo patrón:

```php
} catch (RecordNotFoundException $e) {
    // Finder nullable: la novedad puede no existir si fue eliminada después de emitir el token.
    $this->logger->warning('entity_not_found', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);

    return null;
}
```

- [ ] **Step 5: Verificar**

```bash
grep -n "catch (Exception" src/Service/Strategy/
```

Esperado: sin output.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Strategy/InvoiceApprovalStrategy.php src/Service/Strategy/NoveltyApprovalStrategy.php
git commit -m "feat(plan-7): catches específicos + log estructurado en strategies (W12)"
```

---

## Task 8: W12 — `LeaveDocumentService` y `ExcelImportService`

**Files:**
- Modify: `src/Service/LeaveDocumentService.php`
- Modify: `src/Service/ExcelImportService.php`

- [ ] **Step 1: `LeaveDocumentService` — añadir logger e imports**

Abrir `src/Service/LeaveDocumentService.php`. Añadir en la sección de `use`:

```php
use App\Service\StructuredLogger;
use Throwable;
```

Añadir propiedad y asignación al final del constructor:

```php
private StructuredLogger $logger;

// constructor, al final:
$this->logger = new StructuredLogger('LeaveDocument');
```

- [ ] **Step 2: `LeaveDocumentService` — catch específico (línea ~366)**

Localizar el bloque alrededor de la línea 366 (es un `} catch (Exception $e) {` seguido eventualmente de `return null;`). Reemplazar:

```php
} catch (Exception $e) {
    // ...
    return null;
}
```

por:

```php
} catch (Throwable $e) {
    $this->logger->warning('document_operation_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);

    return null;
}
```

> Usamos `Throwable` aquí porque la operación involucra I/O sobre archivos (PhpSpreadsheet, filesystem) y puede lanzar tanto `Exception` como `Error` (ej. `TypeError` si el archivo está corrupto). Comentario inline explica el por qué.

- [ ] **Step 3: `ExcelImportService` — añadir logger e imports**

Abrir `src/Service/ExcelImportService.php`. Añadir:

```php
use App\Service\StructuredLogger;
use Throwable;
```

Constructor, al final:

```php
private StructuredLogger $logger;

// asignación al final del constructor:
$this->logger = new StructuredLogger('ExcelImport');
```

- [ ] **Step 4: `ExcelImportService` — catch específico línea ~92**

Reemplazar el catch actual de la línea ~92 por:

```php
} catch (Throwable $e) {
    // Lectura de Excel puede lanzar Exception o Error (PhpSpreadsheet, archivos corruptos).
    $this->logger->warning('excel_read_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);

    return null;
}
```

- [ ] **Step 5: `ExcelImportService` — catch específico línea ~269**

El bloque actual es `} catch (Exception) { return null; }` (sin variable). Reemplazar por:

```php
} catch (Throwable $e) {
    $this->logger->warning('excel_cell_parse_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);

    return null;
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/LeaveDocumentService.php src/Service/ExcelImportService.php
git commit -m "feat(plan-7): catches específicos + log estructurado en LeaveDocument y ExcelImport (W12)"
```

---

## Task 9: W11 — Configurar engine `sidebar` en `config/app.php`

**Files:**
- Modify: `config/app.php`

- [ ] **Step 1: Localizar el bloque `Cache`**

Abrir `config/app.php`. Buscar la clave `'Cache' => [` (es un array de configs de caches con `'default'`, `'_cake_translations_'`, etc.).

- [ ] **Step 2: Añadir la entrada `sidebar`**

Dentro del array `'Cache'`, añadir como **última entrada** antes del `]` que cierra el bloque:

```php
'sidebar' => [
    'className' => FileEngine::class,
    'duration' => '+30 seconds',
    'path' => CACHE,
    'prefix' => 'sgi_sidebar_',
],
```

Verificar que `FileEngine` ya esté importado al inicio del archivo. Si no:

```php
use Cake\Cache\Engine\FileEngine;
```

(Probablemente ya está, dado que el cache `default` lo usa.)

- [ ] **Step 3: Verificar la sintaxis**

```bash
php -l config/app.php
```

Esperado: `No syntax errors detected in config/app.php`.

- [ ] **Step 4: Commit**

```bash
git add config/app.php
git commit -m "feat(plan-7): engine 'sidebar' (FileEngine, TTL 30s) en config (W11)"
```

---

## Task 10: W11 — `SidebarCounterService::getCounters()` con `Cache::remember`

**Files:**
- Modify: `src/Service/SidebarCounterService.php`

- [ ] **Step 1: Añadir import de `Cache`**

Abrir `src/Service/SidebarCounterService.php`. En la sección de `use` añadir:

```php
use Cake\Cache\Cache;
```

- [ ] **Step 2: Envolver `getCounters()` en `Cache::remember`**

Reemplazar el método `getCounters()` actual (que ya tiene `_buildCounters`/`_emptyCounters` desde la Task 6) por:

```php
public function getCounters(string $roleName): array
{
    return Cache::remember(
        "sidebar_counters_{$roleName}",
        function () use ($roleName) {
            try {
                return $this->_buildCounters($roleName);
            } catch (DatabaseException $e) {
                // UI degradable: si una query falla, el sidebar muestra ceros en lugar de romper la página.
                $this->logger->error('sidebar_counters_failed', [
                    'role' => $roleName,
                    'exception' => $e->getMessage(),
                ]);

                return $this->_emptyCounters();
            }
        },
        'sidebar'
    );
}
```

> El fallback **se cachea junto con el éxito** (porque va dentro del closure). Eso significa que si la DB cae 30s, el sidebar mostrará ceros 30s después del recovery. Trade-off aceptado en el ADR 0004.

- [ ] **Step 3: Verificar**

```bash
php -l src/Service/SidebarCounterService.php
```

Esperado: sin errores de sintaxis.

- [ ] **Step 4: Commit**

```bash
git add src/Service/SidebarCounterService.php
git commit -m "feat(plan-7): sidebar counters cacheados con TTL 30s (W11)"
```

---

## Task 11: W15a — `InvoicePipelineService` (saveAndAdvance / advance / regress) → `ServiceResult`

**Files:**
- Modify: `src/Service/InvoicePipelineService.php`
- Modify: `src/Controller/InvoicesController.php`
- Modify: `src/Controller/ExternalApprovalsController.php`

- [ ] **Step 1: Asegurar import de `ServiceResult`**

Abrir `src/Service/InvoicePipelineService.php`. Verificar que esté:

```php
use App\Service\ServiceResult;
```

Si no, añadirlo.

- [ ] **Step 2: Cambiar la firma y el `return` de `saveAndAdvance()` (línea 241)**

Cambiar la firma:

```php
public function saveAndAdvance(
    Invoice $invoice,
    array $data,
    string $roleName,
    int $userId,
    ?string $baseUrl = null,
): ServiceResult {
```

El cuerpo del método se conserva igual hasta el `return` final. Reemplazar el `return [...]` final (líneas ~336-341) por:

```php
if (!(bool)$saved) {
    return ServiceResult::fail(['No se pudo guardar la factura.']);
}

return ServiceResult::ok([
    'advanced' => (bool)$advanceNextStatus,
    'nextStatus' => $advanceNextStatus,
    'advanceErrors' => $postAdvanceErrors,
]);
```

Actualizar el PHPDoc del método para reflejar el nuevo retorno:

```php
/**
 * Save invoice fields, optionally advance the pipeline, and record history.
 *
 * @return ServiceResult on success: data = ['advanced' => bool, 'nextStatus' => ?string, 'advanceErrors' => string[]]
 */
```

- [ ] **Step 3: Cambiar firma y returns de `advance()` (línea 349)**

Firma:

```php
public function advance(Invoice $invoice, string $roleName, int $userId): ServiceResult
```

Reemplazar cada `return ['success' => false, 'error' => '...', 'nextStatus' => null];` por `return ServiceResult::fail(['<error>']);`. Reemplazar el return final exitoso por `return ServiceResult::ok(['nextStatus' => $nextStatus]);`.

Ejemplo del cuerpo completo:

```php
public function advance(Invoice $invoice, string $roleName, int $userId): ServiceResult
{
    $currentStatus = $invoice->pipeline_status;

    if (!$this->canAdvance($roleName, $currentStatus, $invoice->document_type ?? null)) {
        return ServiceResult::fail(['No tiene permisos para avanzar esta factura.']);
    }

    if ($this->isRejected($invoice)) {
        return ServiceResult::fail(['La factura fue rechazada. El flujo ha terminado.']);
    }

    $errors = $this->validateTransitionRequirements($invoice, $currentStatus);
    if (!empty($errors)) {
        return ServiceResult::fail($errors);
    }

    $nextStatus = $this->getNextStatus($currentStatus, $invoice->document_type);
    if (!$nextStatus) {
        return ServiceResult::fail(['Esta factura ya está en el estado final.']);
    }

    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $invoice->pipeline_status = $nextStatus;

    if (!$invoicesTable->save($invoice)) {
        return ServiceResult::fail(['No se pudo avanzar el estado.']);
    }

    $this->historyService->recordStatusChange($invoice->id, $currentStatus, $nextStatus, $userId);

    return ServiceResult::ok(['nextStatus' => $nextStatus]);
}
```

- [ ] **Step 4: Cambiar firma y returns de `regress()` (línea 392)**

Firma:

```php
public function regress(
    Invoice $invoice,
    string $roleName,
    int $userId,
    string $reason,
): ServiceResult
```

Cuerpo: cada `return ['success' => false, 'error' => $error, 'previousStatus' => null];` se vuelve `return ServiceResult::fail([$error]);`. El return exitoso final (que probablemente devuelve `['success' => true, 'error' => null, 'previousStatus' => $previousStatus]`) se vuelve `return ServiceResult::ok(['previousStatus' => $previousStatus]);`.

Leer el método completo (líneas 392 hasta el cierre de la función) y aplicar el patrón a cada `return`. El `transactional` interno conserva su semántica; solo se ajustan los retornos al exterior.

- [ ] **Step 5: Actualizar `InvoicesController::edit()` (línea ~310)**

Abrir `src/Controller/InvoicesController.php`. En la acción `edit`, localizar el bloque tras `$result = $this->pipeline->saveAndAdvance(...)` (línea ~310-344).

Reemplazar el bloque:

```php
if ($result['saved']) {
    if ($result['advanced']) {
        $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
        $this->Flash->success(sprintf('Factura guardada y avanzada a: %s', $nextLabel));
    } else {
        $this->Flash->success('La factura ha sido actualizada.');
        $rules = $this->pipeline->getTransitionRules($currentStatus);
        $filteredErrors = $this->pipeline->filterAdvanceErrorsForRole(
            $result['advanceErrors'],
            $rules,
            $roleName,
            $currentStatus,
        );
        foreach ($filteredErrors as $err) {
            $this->Flash->warning($err);
        }
    }

    $redirectAction = $result['advanced'] ? 'index' : 'edit';

    return $redirectAction === 'edit'
        ? $this->_redirectForInvoice($invoice, 'edit', $id)
        : $this->_redirectForInvoice($invoice, 'index');
}

$this->Flash->error('No se pudo guardar la factura. Verifique los datos e intente de nuevo.');
```

por:

```php
if ($result->success) {
    $advanced = (bool)($result->data['advanced'] ?? false);
    $nextStatus = $result->data['nextStatus'] ?? null;
    $advanceErrors = $result->data['advanceErrors'] ?? [];

    if ($advanced) {
        $nextLabel = InvoicePipelineService::STATUS_LABELS[$nextStatus] ?? $nextStatus;
        $this->Flash->success(sprintf('Factura guardada y avanzada a: %s', $nextLabel));
    } else {
        $this->Flash->success('La factura ha sido actualizada.');
        $rules = $this->pipeline->getTransitionRules($currentStatus);
        $filteredErrors = $this->pipeline->filterAdvanceErrorsForRole(
            $advanceErrors,
            $rules,
            $roleName,
            $currentStatus,
        );
        foreach ($filteredErrors as $err) {
            $this->Flash->warning($err);
        }
    }

    return $advanced
        ? $this->_redirectForInvoice($invoice, 'index')
        : $this->_redirectForInvoice($invoice, 'edit', $id);
}

$this->Flash->error(implode("\n", $result->errors) ?: 'No se pudo guardar la factura. Verifique los datos e intente de nuevo.');
```

- [ ] **Step 6: Actualizar `InvoicesController::advanceStatus()` (línea ~415)**

Localizar la acción `advanceStatus` (línea ~415). Reemplazar:

```php
$result = $this->pipeline->advance($invoice, $this->_getRoleName(), $user->id);

if ($result['success']) {
    $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
    $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));

    return $this->_redirectForInvoice($invoice, 'index');
}

$this->Flash->error($result['error']);

return $this->_redirectForInvoice($invoice, 'edit', $id);
```

por:

```php
$result = $this->pipeline->advance($invoice, $this->_getRoleName(), $user->id);

if ($result->success) {
    $nextStatus = $result->data['nextStatus'] ?? null;
    $nextLabel = InvoicePipelineService::STATUS_LABELS[$nextStatus] ?? $nextStatus;
    $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));

    return $this->_redirectForInvoice($invoice, 'index');
}

$this->Flash->error($result->firstError() ?? 'No se pudo avanzar la factura.');

return $this->_redirectForInvoice($invoice, 'edit', $id);
```

- [ ] **Step 7: Actualizar `InvoicesController::regressStatus()` (línea ~436)**

Reemplazar el bloque tras `$result = $this->pipeline->regress(...)` por:

```php
if ($result->success) {
    $previousStatus = $result->data['previousStatus'] ?? null;
    $prevLabel = InvoicePipelineService::STATUS_LABELS[$previousStatus] ?? $previousStatus;
    $this->Flash->success(sprintf('Factura regresada a: %s', $prevLabel));

    return $this->_redirectForInvoice($invoice, 'index');
}

$this->Flash->error($result->firstError() ?? 'No se pudo regresar la factura.');

return $this->_redirectForInvoice($invoice, 'edit', $id);
```

- [ ] **Step 8: Actualizar `ExternalApprovalsController` (línea ~180)**

Abrir `src/Controller/ExternalApprovalsController.php`. Localizar la línea:

```php
$this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
```

(La acción no inspecciona el resultado actualmente — sólo se llama por side effect.)

El cambio es **menor**: ahora `advance()` devuelve `ServiceResult` en vez de array. Como no se inspecciona el retorno, no rompe; pero conviene **registrar** errores silenciosos. Reemplazar por:

```php
$advanceResult = $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
if (!$advanceResult->success) {
    Log::warning('External approval: auto-advance falló', [
        'invoice_id' => $invoice->id,
        'errors' => $advanceResult->errors,
    ]);
}
```

> Nota: aquí estamos en un controller, no en un service. Los controllers usan `Cake\Log\Log` directamente (W1 sólo aplica a services). Verificar que `use Cake\Log\Log;` esté presente al inicio; si no, añadirlo.

- [ ] **Step 9: Verificación**

```bash
grep -n "saveAndAdvance\|->pipeline->advance\|->pipelineService->advance\|->pipeline->regress" src/Controller/ src/Service/InvoicePipelineService.php | head
```

Verificar manualmente que ningún caller queda usando `$result['saved']`, `$result['success']`, `$result['error']` o `$result['nextStatus']` para los métodos migrados.

```bash
grep -rn "result\['saved'\]\|result\['advanced'\]\|result\['nextStatus'\]\|result\['advanceErrors'\]" src/Controller/ --include="*.php"
```

Esperado: sin output (todos migraron a `$result->data['...']` o `$result->success`).

- [ ] **Step 10: Commit**

```bash
git add src/Service/InvoicePipelineService.php src/Controller/InvoicesController.php src/Controller/ExternalApprovalsController.php
git commit -m "feat(plan-7): InvoicePipelineService y callers usan ServiceResult (W15)"
```

---

## Task 12: W15b — `NoveltyPipelineService` (advance / advanceGroup / reject) → `ServiceResult`

**Files:**
- Modify: `src/Service/NoveltyPipelineService.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php`
- Modify: `src/Controller/NoveltyLiquidationDocsController.php`

- [ ] **Step 1: Asegurar import de `ServiceResult` en NoveltyPipelineService**

Abrir `src/Service/NoveltyPipelineService.php`. Verificar:

```php
use App\Service\ServiceResult;
```

- [ ] **Step 2: Migrar `advance()` (línea 211)**

Cambiar la firma a `: ServiceResult`. Reemplazar cada `return ['success' => false, 'error' => '<msg>'];` (o variantes con `errors`) por `return ServiceResult::fail(['<msg>']);`. El return exitoso (que devuelve `['success' => true, 'nextStatus' => ...]`) se vuelve `return ServiceResult::ok(['nextStatus' => $nextStatus]);`.

Leer el método completo y aplicar el patrón. Si hay múltiples errores acumulados, pasar el array directo: `return ServiceResult::fail($errors);`.

- [ ] **Step 3: Migrar `advanceGroup()` (línea 252)**

Cambiar firma a `: ServiceResult`. Aplicar mismo patrón:
- `['success' => false, 'errors' => $errors]` → `ServiceResult::fail($errors)`.
- `['success' => true, 'nextStatus' => $nextStatus]` → `ServiceResult::ok(['nextStatus' => $nextStatus])`.

- [ ] **Step 4: Migrar `reject()` (línea 305)**

Cambiar firma a `: ServiceResult`. Patrón:
- `['success' => false, 'error' => '<msg>']` → `ServiceResult::fail(['<msg>'])`.
- `['success' => true]` (si lo hubiera) → `ServiceResult::ok()`.

- [ ] **Step 5: Actualizar `EmployeeNoveltiesController::advance()` (línea ~737)**

Abrir `src/Controller/EmployeeNoveltiesController.php`. Reemplazar el bloque:

```php
$result = $this->pipelineService->advance($novelty, $user->id);

if ($result['success']) {
    $this->historyService->recordStatusChange(
        (int)$novelty->id,
        $originalStatus,
        $result['nextStatus'],
        $user->id,
    );
    $this->Flash->success('Novedad avanzada a: ' . NoveltyConstants::STATUS_LABELS[$result['nextStatus']]);
} else {
    $this->Flash->error($result['error']);
}
```

por:

```php
$result = $this->pipelineService->advance($novelty, $user->id);

if ($result->success) {
    $nextStatus = $result->data['nextStatus'] ?? null;
    $this->historyService->recordStatusChange(
        (int)$novelty->id,
        $originalStatus,
        $nextStatus,
        $user->id,
    );
    $this->Flash->success('Novedad avanzada a: ' . NoveltyConstants::STATUS_LABELS[$nextStatus]);
} else {
    $this->Flash->error($result->firstError() ?? 'No se pudo avanzar la novedad.');
}
```

- [ ] **Step 6: Actualizar `EmployeeNoveltiesController::reject()` (línea ~766)**

Reemplazar:

```php
$result = $this->pipelineService->reject($novelty, $user->id, $observations);

if ($result['success']) {
    $this->historyService->recordStatusChange(...);
    $this->Flash->success('Novedad rechazada.');
} else {
    $this->Flash->error($result['error']);
}
```

por:

```php
$result = $this->pipelineService->reject($novelty, $user->id, $observations);

if ($result->success) {
    $this->historyService->recordStatusChange(
        (int)$novelty->id,
        $originalStatus,
        NoveltyConstants::STATUS_RECHAZADA,
        $user->id,
    );
    $this->Flash->success('Novedad rechazada.');
} else {
    $this->Flash->error($result->firstError() ?? 'No se pudo rechazar la novedad.');
}
```

- [ ] **Step 7: Actualizar `NoveltyLiquidationDocsController::advanceGroup()` (línea ~256)**

Abrir `src/Controller/NoveltyLiquidationDocsController.php`. Reemplazar:

```php
$result = $this->pipelineService->advanceGroup($doc, $user->id);

if ($result['success']) {
    $label = NoveltyConstants::STATUS_LABELS[$result['nextStatus']];
    $this->Flash->success('Documento de liquidación avanzado a: ' . $label);

    return $this->redirect(['action' => 'index']);
}

foreach ($result['errors'] as $error) {
    $this->Flash->error($error);
}

return $this->redirect(['action' => 'edit', $id]);
```

por:

```php
$result = $this->pipelineService->advanceGroup($doc, $user->id);

if ($result->success) {
    $nextStatus = $result->data['nextStatus'] ?? null;
    $label = NoveltyConstants::STATUS_LABELS[$nextStatus] ?? $nextStatus;
    $this->Flash->success('Documento de liquidación avanzado a: ' . $label);

    return $this->redirect(['action' => 'index']);
}

foreach ($result->errors as $error) {
    $this->Flash->error($error);
}

return $this->redirect(['action' => 'edit', $id]);
```

- [ ] **Step 8: Verificación cruzada**

```bash
grep -rn "pipelineService->\(advance\|advanceGroup\|reject\)\|pipelineService->advance\|pipelineService->advanceGroup\|pipelineService->reject" src/Controller/ --include="*.php"
```

Inspeccionar cada hit y verificar que use `$result->success` y `$result->data[...]`, no `$result['...']`.

- [ ] **Step 9: Commit**

```bash
git add src/Service/NoveltyPipelineService.php src/Controller/EmployeeNoveltiesController.php src/Controller/NoveltyLiquidationDocsController.php
git commit -m "feat(plan-7): NoveltyPipelineService y callers usan ServiceResult (W15)"
```

---

## Task 13: W15c — `PaymentSchedulingPipelineService::regress()` → `ServiceResult`

**Files:**
- Modify: `src/Service/PaymentSchedulingPipelineService.php`
- Modify: `src/Controller/PaymentSchedulingsController.php`

- [ ] **Step 1: Asegurar import de `ServiceResult`**

```php
use App\Service\ServiceResult;
```

- [ ] **Step 2: Migrar `regress()` (línea 167)**

Cambiar firma:

```php
public function regress(
    PaymentScheduling $scheduling,
    string $roleName,
    int $userId,
    string $reason,
): ServiceResult
```

Reemplazar cada `return ['success' => false, 'error' => $error, 'previousStatus' => null];` por:

```php
return ServiceResult::fail([$error]);
```

El `return` exitoso al final (probablemente `['success' => true, 'error' => null, 'previousStatus' => $previousStatus]`) se vuelve:

```php
return ServiceResult::ok(['previousStatus' => $previousStatus]);
```

Leer el método completo y aplicar el patrón a cada return.

- [ ] **Step 3: Actualizar `PaymentSchedulingsController` (línea ~276)**

Reemplazar:

```php
$result = $this->pipeline->regress(
    $record,
    $roleName,
    (int)$user->id,
    $reason,
);

if ($result['success']) {
    $prevLabel = PaymentSchedulingConstants::STATUS_LABELS[$result['previousStatus']]
        ?? $result['previousStatus'];
    $this->Flash->success(sprintf('Programación regresada a: %s', $prevLabel));

    return $this->redirect(['action' => 'index']);
}

$this->Flash->error($result['error']);

return $this->redirect(['action' => 'edit', $id]);
```

por:

```php
$result = $this->pipeline->regress(
    $record,
    $roleName,
    (int)$user->id,
    $reason,
);

if ($result->success) {
    $previousStatus = $result->data['previousStatus'] ?? null;
    $prevLabel = PaymentSchedulingConstants::STATUS_LABELS[$previousStatus] ?? $previousStatus;
    $this->Flash->success(sprintf('Programación regresada a: %s', $prevLabel));

    return $this->redirect(['action' => 'index']);
}

$this->Flash->error($result->firstError() ?? 'No se pudo regresar la programación.');

return $this->redirect(['action' => 'edit', $id]);
```

- [ ] **Step 4: Commit**

```bash
git add src/Service/PaymentSchedulingPipelineService.php src/Controller/PaymentSchedulingsController.php
git commit -m "feat(plan-7): PaymentSchedulingPipelineService::regress y caller usan ServiceResult (W15)"
```

---

## Task 14: W15d — `InvoiceApprovalService` (4 métodos) → `ServiceResult`

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php`
- Modify: `src/Controller/InvoicesController.php`
- Modify: `src/Controller/ExternalApprovalsController.php`

- [ ] **Step 1: Verificar import de `ServiceResult`**

```php
use App\Service\ServiceResult;
```

(Probablemente ya está, dado que `resetFlow` ya devuelve `ServiceResult`.)

- [ ] **Step 2: Migrar `assignApprovers()` (línea 42)**

Cambiar firma:

```php
public function assignApprovers(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
```

Reemplazar el primer return:

```php
if (empty($approverUserIds)) {
    return ['success' => false, 'errors' => ['Debe seleccionar al menos un aprobador'], 'approvals' => []];
}
```

por:

```php
if (empty($approverUserIds)) {
    return ServiceResult::fail(['Debe seleccionar al menos un aprobador']);
}
```

Reemplazar el return final:

```php
$success = empty($errors);

return compact('success', 'errors', 'approvals');
```

por:

```php
if (!empty($errors)) {
    return ServiceResult::fail($errors);
}

return ServiceResult::ok(['approvals' => $approvals]);
```

- [ ] **Step 3: Migrar `processResponse()` (línea 146)**

Cambiar firma a `: ServiceResult`. El método completo está envuelto en `transactional(function () { ... })` que actualmente devuelve un array. Hay que cambiar **lo que retorna el closure** y la firma exterior.

Reemplazar el closure interno por:

```php
return $connection->transactional(function () use ($token, $action, $observations, $ipAddress, $userAgent) {
    $approval = $this->invoiceApprovalsTable->find()
        ->where([
            'token' => $token,
            'status' => InvoiceConstants::APPROVER_STATUS_PENDING,
            'token_expires_at >' => new DateTime(),
        ])
        ->contain(['Invoices' => ['Providers', 'InvoiceDocuments'], 'Users'])
        ->epilog('FOR UPDATE')
        ->first();

    if (!$approval) {
        return ServiceResult::fail(['Token inválido o expirado']);
    }

    $newStatus = $action === 'approve'
        ? InvoiceConstants::APPROVER_STATUS_APPROVED
        : InvoiceConstants::APPROVER_STATUS_REJECTED;

    $approval->status = $newStatus;
    $approval->responded_at = new DateTime();
    $approval->observations = $observations;
    $approval->ip_address = $ipAddress;
    $approval->user_agent = $userAgent;
    $approval->token = null;

    if (!$this->invoiceApprovalsTable->save($approval)) {
        return ServiceResult::fail(['Error al guardar respuesta']);
    }

    $invoiceId = $approval->invoice_id;

    if ($action === 'reject') {
        $this->_invalidatePendingTokens($invoiceId, $approval->id);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($invoiceId);
        $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
        $invoice->area_approval_date = new DateTime();
        $invoicesTable->save($invoice);

        return ServiceResult::ok([
            'allApproved' => false,
            'rejected' => true,
            'invoice_id' => $invoiceId,
        ]);
    }

    $allApproved = $this->areAllApproved($invoiceId);

    if ($allApproved) {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoice = $invoicesTable->get($invoiceId);
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $invoice->area_approval_date = new DateTime();
        $invoicesTable->save($invoice);
    }

    return ServiceResult::ok([
        'allApproved' => $allApproved,
        'rejected' => false,
        'invoice_id' => $invoiceId,
    ]);
});
```

- [ ] **Step 4: Migrar `sendApprovalLinks()` (línea 312)**

Cambiar firma:

```php
public function sendApprovalLinks(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
```

Cuerpo: los dos returns iniciales se vuelven `ServiceResult::fail([...])`. El return final ya delega a `assignApprovers` (que ahora devuelve `ServiceResult` por la Task Step 2).

```php
public function sendApprovalLinks(Invoice $invoice, array $approverUserIds, string $baseUrl, int $createdByUserId): ServiceResult
{
    if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
        return ServiceResult::fail(['Solo se pueden enviar enlaces mientras la factura esté en Aprobación.']);
    }
    if ($this->hasAnyActiveApprovals($invoice->id)) {
        return ServiceResult::fail(['Ya existen aprobaciones para esta factura; use Modificar aprobadores.']);
    }

    return $this->assignApprovers($invoice, $approverUserIds, $baseUrl, $createdByUserId);
}
```

- [ ] **Step 5: Migrar `modifyApprovers()` (línea 338)**

Cambiar firma:

```php
public function modifyApprovers(
    Invoice $invoice,
    array $newApproverIds,
    string $reason,
    string $baseUrl,
    int $userId,
): ServiceResult
```

Reemplazar los 3 returns iniciales por `ServiceResult::fail([...])`. Conservar el `transactional(function () { ... })` pero el closure interno ahora delega a `assignApprovers` (que ya devuelve `ServiceResult`):

```php
public function modifyApprovers(
    Invoice $invoice,
    array $newApproverIds,
    string $reason,
    string $baseUrl,
    int $userId,
): ServiceResult {
    if ($invoice->pipeline_status !== InvoiceConstants::STATUS_APROBACION) {
        return ServiceResult::fail(['No se pueden modificar aprobadores fuera del estado Aprobación.']);
    }
    if (trim($reason) === '') {
        return ServiceResult::fail(['El motivo es obligatorio.']);
    }
    if (empty($newApproverIds)) {
        return ServiceResult::fail(['Debe seleccionar al menos un aprobador.']);
    }

    $connection = $this->invoiceApprovalsTable->getConnection();

    return $connection->transactional(function () use ($invoice, $newApproverIds, $reason, $baseUrl, $userId) {
        $previous = $this->getCurrentApprovals($invoice->id);
        $previousNames = array_map(
            fn($a) => $a->user->full_name ?? $a->user->username ?? 'Usuario #' . $a->user_id,
            $previous,
        );

        $this->invoiceApprovalsTable->updateAll(
            [
                'status' => InvoiceConstants::APPROVER_STATUS_SUPERSEDED,
                'token' => null,
                'token_expires_at' => null,
            ],
            [
                'invoice_id' => $invoice->id,
                'status IN' => InvoiceConstants::APPROVER_STATUSES_ACTIVE,
            ],
        );

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $invoicesTable->updateAll(
            ['area_approval' => InvoiceConstants::APPROVAL_PENDING, 'area_approval_date' => null],
            ['id' => $invoice->id],
        );

        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $newUsers = $usersTable->find()
            ->where(['id IN' => array_map('intval', $newApproverIds)])
            ->all()
            ->toArray();
        $newNames = array_map(
            fn($u) => $u->full_name ?? $u->username ?? 'Usuario #' . $u->id,
            $newUsers,
        );

        $historiesTable = TableRegistry::getTableLocator()->get('InvoiceHistories');
        $historiesTable->save($historiesTable->newEntity([
            'invoice_id' => $invoice->id,
            'user_id' => $userId,
            'field_changed' => 'approvers_modified',
            'old_value' => implode(', ', $previousNames) ?: '—',
            'new_value' => (implode(', ', $newNames) ?: '—') . ' (Motivo: ' . $reason . ')',
        ]));

        return $this->assignApprovers($invoice, $newApproverIds, $baseUrl, $userId);
    });
}
```

- [ ] **Step 6: Actualizar `InvoicesController::sendApprovalLinks()` (línea ~688)**

Reemplazar:

```php
$result = $this->approvalService->sendApprovalLinks(...);

if (!empty($result['success'])) {
    $this->Flash->success('Enlaces de aprobación enviados.');
} else {
    foreach ($result['errors'] ?? [] as $error) {
        $this->Flash->error($error);
    }
}
```

por:

```php
$result = $this->approvalService->sendApprovalLinks(
    $invoice,
    $approverIds,
    $this->_getBaseUrl(),
    (int)$user->id,
);

if ($result->success) {
    $this->Flash->success('Enlaces de aprobación enviados.');
} else {
    foreach ($result->errors as $error) {
        $this->Flash->error($error);
    }
}
```

- [ ] **Step 7: Actualizar `InvoicesController::modifyApprovers()` (línea ~717)**

Reemplazar:

```php
$result = $this->approvalService->modifyApprovers(...);

if (!empty($result['success'])) {
    $this->Flash->success('Aprobadores actualizados. Se enviaron los nuevos enlaces.');
} else {
    foreach ($result['errors'] ?? [] as $error) {
        $this->Flash->error($error);
    }
}
```

por:

```php
$result = $this->approvalService->modifyApprovers(
    $invoice,
    $approverIds,
    $reason,
    $this->_getBaseUrl(),
    (int)$user->id,
);

if ($result->success) {
    $this->Flash->success('Aprobadores actualizados. Se enviaron los nuevos enlaces.');
} else {
    foreach ($result->errors as $error) {
        $this->Flash->error($error);
    }
}
```

- [ ] **Step 8: Actualizar `ExternalApprovalsController::review()` (línea ~156)**

Reemplazar:

```php
$result = $this->approvalService->processResponse($token, $action, $observations, $ipAddress, $userAgent);

if (!$result['success']) {
    $this->Flash->error($result['errors'][0] ?? 'Error al procesar respuesta');

    return $this->redirect(['action' => 'review', $token]);
}

// Save observation to invoice_observations chat if not empty
if (!empty($observations)) {
    $actionLabel = $action === 'approve' ? 'Aprobado' : 'Rechazado';
    $this->_saveExternalObservation(
        $approval->invoice_id,
        $currentUser->id,
        "[Aprobación externa - {$actionLabel}] {$observations}",
    );
}

if ($result['allApproved']) {
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $invoice = $invoicesTable->get($result['invoice_id']);

    if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
        $identity = $this->Authentication->getIdentity();
        $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
    }
}
```

por:

```php
$result = $this->approvalService->processResponse($token, $action, $observations, $ipAddress, $userAgent);

if (!$result->success) {
    $this->Flash->error($result->firstError() ?? 'Error al procesar respuesta');

    return $this->redirect(['action' => 'review', $token]);
}

if (!empty($observations)) {
    $actionLabel = $action === 'approve' ? 'Aprobado' : 'Rechazado';
    $this->_saveExternalObservation(
        $approval->invoice_id,
        $currentUser->id,
        "[Aprobación externa - {$actionLabel}] {$observations}",
    );
}

$allApproved = (bool)($result->data['allApproved'] ?? false);
$invoiceId = $result->data['invoice_id'] ?? null;

if ($allApproved && $invoiceId !== null) {
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $invoice = $invoicesTable->get($invoiceId);

    if ($invoice->pipeline_status === InvoiceConstants::STATUS_APROBACION) {
        $identity = $this->Authentication->getIdentity();
        $advanceResult = $this->pipelineService->advance($invoice, 'Admin', (int)$identity->getIdentifier());
        if (!$advanceResult->success) {
            Log::warning('External approval: auto-advance falló', [
                'invoice_id' => $invoice->id,
                'errors' => $advanceResult->errors,
            ]);
        }
    }
}
```

> Verificar que `use Cake\Log\Log;` esté presente al inicio. Si no, añadirlo.

> Si la Task 11 Step 8 ya hizo este cambio del `pipelineService->advance` (con `Log::warning`), no duplicar — solo aplicar el cambio de `processResponse`.

- [ ] **Step 9: Verificación cruzada**

```bash
grep -rn "approvalService->\(assignApprovers\|processResponse\|sendApprovalLinks\|modifyApprovers\)" src/Controller/ --include="*.php"
```

Inspeccionar cada hit y confirmar uso de `$result->success` / `$result->data[...]` / `$result->errors`, no `$result['...']`.

- [ ] **Step 10: Commit**

```bash
git add src/Service/InvoiceApprovalService.php src/Controller/InvoicesController.php src/Controller/ExternalApprovalsController.php
git commit -m "feat(plan-7): InvoiceApprovalService (4 métodos) y callers usan ServiceResult (W15)"
```

---

## Task 15: W4 — `_enforcePermission()` lanza `ForbiddenException`

**Files:**
- Modify: `src/Controller/AppController.php`

- [ ] **Step 1: Añadir import**

Abrir `src/Controller/AppController.php`. En la sección de `use` añadir:

```php
use Cake\Http\Exception\ForbiddenException;
```

- [ ] **Step 2: Reemplazar el cuerpo de `_enforcePermission()`**

Buscar el método `_enforcePermission()` (línea ~140). Reemplazar el bloque final del `if (!$this->_checkPermission(...))`:

```php
if (!$this->_checkPermission($module, $permAction)) {
    $this->Flash->error('No tiene permisos para acceder a esta función.');
    // Avoid redirect loop: if already on dashboard, redirect to login
    if ($controllerName === 'Dashboard' && $action === 'index') {
        $this->redirect(['controller' => 'Users', 'action' => 'login']);
    } else {
        $this->redirect($this->request->referer() ?: ['controller' => 'Dashboard', 'action' => 'index']);
    }
}
```

por:

```php
if (!$this->_checkPermission($module, $permAction)) {
    throw new ForbiddenException(
        sprintf('No tiene permisos para %s en %s.', $permAction, $module)
    );
}
```

- [ ] **Step 3: Verificar que el método queda coherente**

Leer el método completo después del cambio (debe ser corto: detección de skip → check → throw).

- [ ] **Step 4: Commit**

```bash
git add src/Controller/AppController.php
git commit -m "feat(plan-7): _enforcePermission lanza ForbiddenException (W4)"
```

---

## Task 16: W4 — Template `templates/Error/error400.php` con rama `ForbiddenException`

**Files:**
- Modify: `templates/Error/error400.php`

- [ ] **Step 1: Leer el archivo actual**

Leer `templates/Error/error400.php` y memorizar su contenido. Tiene un diseño dark con código grande (centrado en 4xx genéricas).

- [ ] **Step 2: Añadir la rama Forbidden al inicio**

Modificar el archivo para que empiece con la nueva rama:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 * @var \Throwable|null $error
 */

use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;

if (isset($error) && $error instanceof ForbiddenException):
    $this->setLayout('default');
?>
    <div class="sgi-forbidden-page">
        <h1>Acceso restringido</h1>
        <p><?= h($error->getMessage()) ?></p>
        <p>Si crees que es un error, contacta al administrador.</p>
        <a href="<?= $this->Url->build(['controller' => 'Dashboard', 'action' => 'index']) ?>"
           class="sgi-btn-primary">Volver al inicio</a>
    </div>
<?php
    return;
endif;

$this->setLayout('error');

if (Configure::read('debug')) :
    $this->setLayout('dev_error');

    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    echo $this->element('auto_table_warning');
    $this->end();
endif;

$code = isset($error) && method_exists($error, 'getCode') ? (int)$error->getCode() : 404;
?>

<div style="
    font-size: 6.5rem;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -.04em;
    color: var(--primary-color);
    font-variant-numeric: tabular-nums;
    margin-bottom: .25rem;
"><?= $code ?></div>

<div style="
    font-size: .55rem;
    font-weight: 600;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: rgba(255,255,255,.25);
    margin-bottom: 1.5rem;
">Error del cliente</div>

<div class="sgi-error-divider"></div>

<p style="
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: .5rem;
    letter-spacing: -.01em;
"><?= h($message) ?></p>

<p style="
    font-size: .8rem;
    color: rgba(255,255,255,.35);
    line-height: 1.6;
    margin: 0;
">
    La dirección <code style="
        font-size: .75rem;
        color: var(--primary-color);
        background: rgba(70,157,97,.1);
        padding: .1rem .4rem;
        border: 1px solid rgba(70,157,97,.2);
    "><?= h($url) ?></code>
    no pudo ser encontrada en este servidor.
</p>
```

> Punto crítico: la rama `Forbidden` ejecuta `return;` para no caer en el bloque dark genérico de abajo, y llama `setLayout('default')` antes de renderizar. Lo demás es el contenido original intacto.

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l templates/Error/error400.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add templates/Error/error400.php
git commit -m "feat(plan-7): error400 detecta ForbiddenException con vista dedicada (W4)"
```

---

## Task 17: W4 — CSS `.sgi-forbidden-page`

**Files:**
- Modify: `webroot/css/styles.css`

- [ ] **Step 1: Localizar el final del archivo**

Abrir `webroot/css/styles.css` y posicionarse al final.

- [ ] **Step 2: Añadir el bloque de estilos**

Añadir al final del archivo:

```css
/* W4 (Plan 7) — Vista 403 */
.sgi-forbidden-page {
    max-width: 560px;
    margin: 4rem auto;
    padding: 2rem;
    border: 1px solid #dee2e6;
    border-top: 2px solid #CD6A15;
    background: #fff;
    text-align: left;
}

.sgi-forbidden-page h1 {
    margin: 0 0 1rem 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #212529;
}

.sgi-forbidden-page p {
    margin: 0 0 0.75rem 0;
    color: #495057;
    line-height: 1.55;
}

.sgi-forbidden-page .sgi-btn-primary {
    display: inline-block;
    margin-top: 1rem;
}
```

> Sigue las reglas de diseño de `STYLES.md`: borders en lugar de shadows, 2px top border (en naranja, color de "atención"), paleta `#212529`/`#469D61`/`#CD6A15`.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/styles.css
git commit -m "style(plan-7): estilos .sgi-forbidden-page para vista 403 (W4)"
```

---

## Task 18: ADRs — Setup (`docs/adr/README.md` + `template.md`)

**Files:**
- Create: `docs/adr/README.md`
- Create: `docs/adr/template.md`

- [ ] **Step 1: Crear el directorio**

```bash
mkdir -p docs/adr
```

- [ ] **Step 2: Crear `docs/adr/template.md`**

```markdown
# ADR NNNN — [Título de la decisión]

- **Status:** Proposed | Accepted | Deprecated | Superseded by ADR NNNN
- **Date:** YYYY-MM-DD
- **Deciders:** Equipo SGI

## Contexto

Qué situación o problema motiva la decisión. Qué pieza del sistema afecta. Qué fuerzas
externas hay (tiempo, infraestructura, dependencias, regulación). Suficiente contexto
para que un nuevo dev entienda el problema sin reconstruirlo desde cero.

## Decisión

Qué se decidió, en una frase clara. Si hay configuración asociada, mencionarla.

## Consecuencias

Qué se gana, qué se pierde, qué obliga a futuro. Incluir tanto consecuencias positivas
como negativas. Si la decisión hace difícil revertirse, anotarlo.

## Alternativas consideradas

Qué se evaluó y descartó, y por qué. Una alternativa por sub-sección breve.
```

- [ ] **Step 3: Crear `docs/adr/README.md`**

```markdown
# Architecture Decision Records

Este directorio contiene las decisiones arquitectónicas del proyecto SGI. Cada ADR
documenta el **por qué** de una decisión, no su implementación (eso vive en specs,
plans y código).

## Cómo añadir un ADR

1. Copia `template.md` a `NNNN-titulo-en-kebab-case.md` con el siguiente número
   consecutivo. Ej: si el último es `0008-…`, el nuevo es `0009-…`.
2. Completa las 4 secciones (Contexto, Decisión, Consecuencias, Alternativas).
3. Marca `Status: Accepted` cuando se incorpore al proyecto.

## Cómo actualizar un ADR existente

Los ADRs son **inmutables** una vez aceptados. Si una decisión cambia:

1. Crea un ADR nuevo con la decisión nueva.
2. En el ADR antiguo, cambia el status a `Superseded by ADR NNNN`.
3. Añade una nota al inicio del antiguo apuntando al nuevo.

No se borran ADRs (para preservar la historia de decisiones).

## Índice

| # | Título | Status |
|---|---|---|
| [0001](0001-layered-architecture-no-ddd.md) | Layered Architecture, no DDD | Accepted |
| [0002](0002-service-result-instead-of-exceptions.md) | ServiceResult en lugar de excepciones para errores de dominio | Accepted |
| [0003](0003-email-log-sync-instead-of-outbox.md) | Email log síncrono con reintento manual; descartar outbox | Accepted |
| [0004](0004-sidebar-counters-cache-30s.md) | Sidebar counters con cache TTL 30s | Accepted |
| [0005](0005-di-container-application-services.md) | DI Container centralizado en `Application::services()` | Accepted |
| [0006](0006-state-pattern-invoice-pipeline.md) | State pattern para el pipeline de facturas | Accepted |
| [0007](0007-domain-events-eventmanager-sync.md) | Domain events vía `EventManager` síncrono in-process | Accepted |
| [0008](0008-optimistic-concurrency-and-idempotency.md) | Optimistic concurrency + idempotency keys para mutaciones | Accepted |
```

- [ ] **Step 4: Commit**

```bash
git add docs/adr/README.md docs/adr/template.md
git commit -m "docs(plan-7): setup de docs/adr/ (README + template)"
```

---

## Task 19: ADRs — 0001 (Layered) + 0002 (ServiceResult)

**Files:**
- Create: `docs/adr/0001-layered-architecture-no-ddd.md`
- Create: `docs/adr/0002-service-result-instead-of-exceptions.md`

- [ ] **Step 1: Crear `0001-layered-architecture-no-ddd.md`**

```markdown
# ADR 0001 — Layered Architecture, no DDD

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

SGI es un sistema de gestión interna construido con CakePHP 5.3. La auditoría
arquitectónica (2026-04-30) verificó que el proyecto sigue una arquitectura en
capas clásica (Controller → Service → Table/Entity) en lugar de DDD táctico
(aggregates, repositories de dominio, bounded contexts explícitos, etc.).

La pregunta natural del audit y de devs nuevos al proyecto es: ¿por qué no DDD?

## Decisión

**Mantener el layered classic.** Las capas son:

- **Controller** (`src/Controller/`) — HTTP, validación de input, delega a services.
- **Service** (`src/Service/`) — lógica de negocio, transacciones, transiciones de
  estado. Retornan `ServiceResult` (ver ADR 0002).
- **Table/Entity** (`src/Model/`) — ORM CakePHP, asociaciones, validación, finders.
- **Constants** (`src/Constants/`) — valores de dominio (estados, roles, tipos).

No hay aggregates ni repositories de dominio. `Table` cumple el rol de repository.
Las entidades son anémicas en el sentido DDD — la lógica vive en services.

## Consecuencias

**Positivas:**
- Patrón idiomático CakePHP. Devs con experiencia previa en CakePHP son productivos
  desde el día 1.
- Pocas abstracciones; el camino de un request al DB es lineal.
- ORM provee asociaciones, validación y finders sin tener que reimplementar.

**Negativas:**
- Servicios pueden crecer (god services como `InvoicePipelineService` antes de Plan 4).
  Mitigado extrayendo policies/validators/state machines.
- No hay barrera explícita entre módulos; `Table` y `Service` pueden cruzarse libremente.
- Si SGI evoluciona a un sistema multi-bounded-context (ej. expansión a otra unidad de
  negocio), tocará migrar capa por capa.

## Alternativas consideradas

### DDD táctico (Aggregates + Repository pattern)
Descartado porque el dominio actual cabe en un módulo (gestión interna de una empresa)
y el costo de reimplementar repositories sobre Table no se compensa. Si surge un
segundo bounded context, **se reconsiderará** para esa parte.

### Hexagonal / Ports & Adapters
Descartado por el mismo motivo: la complejidad de implementar puertos para todo el
sistema supera el beneficio de testabilidad cuando ya tenemos services puros (sin
side effects ocultos) que se prestan a tests directos. Además, este proyecto no usa
tests automatizados (decisión operativa).

### CQRS / Event Sourcing
Descartado: las consultas no son lo bastante distintas de las mutaciones para
justificar dividir el modelo. ES introduciría complejidad operativa (event store,
replays) sin un caso de uso claro hoy.
```

- [ ] **Step 2: Crear `0002-service-result-instead-of-exceptions.md`**

```markdown
# ADR 0002 — ServiceResult en lugar de excepciones para errores de dominio

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Los servicios de SGI pueden fallar por dos clases de razón:

1. **Errores de dominio**: el caller envió datos que no satisfacen una regla de negocio
   (factura ya está en estado final, falta documento requerido, motivo demasiado
   corto, etc.). Son **esperables**, parte del flujo normal del usuario, y la UI
   debe mostrarlos como mensajes accionables.

2. **Errores de infraestructura**: la DB cae, el SMTP no responde, el filesystem
   está lleno. Son **excepcionales**, raros, y requieren intervención técnica.

Mezclar ambos en excepciones tiene problemas:
- Forzar el caller a `try/catch` cada llamada produce ruido.
- Excepciones de dominio se pierden con `catch (Exception $e) { return null; }` (fue
  el caso del audit W12).
- Los stack traces de excepciones de dominio inflan logs sin valor.

## Decisión

**Métodos con side effects que pueden fallar por reglas de negocio devuelven
`ServiceResult`. Las excepciones se reservan para errores de infraestructura.**

`ServiceResult` (ver `src/Service/ServiceResult.php`) es:

```php
new ServiceResult(
    bool $success,
    mixed $data = null,
    array $errors = []
);
```

Con factory methods `::ok($data)` y `::fail($errors)`. El caller verifica
`$result->success` antes de usar `$result->data`.

**Métodos que NO devuelven `ServiceResult`:**
- Getters / finders / cálculos puros (devuelven el tipo natural).
- Métodos `?Entity` que pueden retornar `null` por diseño (no es un fallo).
- Métodos `void` que no pueden fallar de manera que el caller deba reaccionar.

## Consecuencias

**Positivas:**
- El caller ve explícitamente que el método puede fallar y debe verificar.
- Errores de dominio quedan como datos manipulables (lista de strings), no como
  excepciones que se atrapan o se dejan burbujear.
- Logs limpios: errores de infra dejan stack trace; errores de dominio no.
- Componer múltiples llamadas con verificación de éxito es trivial.

**Negativas:**
- Hay que recordar verificar `$result->success`. Olvidarlo no es fail-fast.
- Existe ambigüedad en el límite: ¿"no se pudo guardar" es dominio o infra? Por
  convención: si la causa es validación → dominio; si es PDOException → infra.
- Migrar gradualmente del patrón anterior (array con `success`/`error`) llevó
  el Plan 7 (W15).

## Alternativas consideradas

### Excepciones de dominio (clase `DomainException` propia)
Descartado por la convención del lenguaje: excepciones tienen costo de stack trace y
producen flujo de control no-local. Para errores esperables, prefiere valor de retorno.

### Result type funcional (Either / Result<T, E>)
Descartado por sobre-ingeniería para PHP. `ServiceResult` con `success/data/errors`
cubre los casos sin requerir librería de funcional ni tipos genéricos.

### Mixed return (a veces array, a veces null, a veces bool)
Descartado: es lo que ya teníamos antes del Plan 7 y produjo el W15 ("callers must
know which return shape this method has"). `ServiceResult` uniforma.
```

- [ ] **Step 3: Commit**

```bash
git add docs/adr/0001-layered-architecture-no-ddd.md docs/adr/0002-service-result-instead-of-exceptions.md
git commit -m "docs(plan-7): ADR 0001 (layered) + 0002 (ServiceResult)"
```

---

## Task 20: ADRs — 0003 (Email log) + 0004 (Sidebar cache)

**Files:**
- Create: `docs/adr/0003-email-log-sync-instead-of-outbox.md`
- Create: `docs/adr/0004-sidebar-counters-cache-30s.md`

- [ ] **Step 1: Crear `0003-email-log-sync-instead-of-outbox.md`**

```markdown
# ADR 0003 — Email log síncrono con reintento manual; descartar outbox

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

La auditoría 2026-04-30 marcó dos issues relacionados:

- **C4** — flujos saga-shaped (`authorizePayment` con varios pasos en services
  distintos) sin compensación.
- **W8** — correos perdidos silenciosamente cuando SMTP falla y `NotificationService`
  trababa la excepción.

El roadmap original proponía un **outbox** + worker CLI corrido por cron como
columna vertebral de integraciones (resolvía C4, W8, y dejaba la base para domain
events).

## Decisión

**Descartar el outbox y el worker.** Sustituir por:

1. **Email log síncrono.** Tabla `email_logs` registra cada intento de envío con
   `status` (pending/sent/failed), `attempts`, `last_error`, `payload` snapshot.
   `NotificationService` envía síncronamente y propaga excepciones SMTP al
   controller (la UI muestra el error al usuario que disparó el flujo).
2. **Recuperación manual** desde la UI. Panel inline en `invoices/edit` y
   `employee_novelties/edit` + vista global `/email-logs` con reintento individual y
   masivo.
3. **Sweep lazy de huérfanos** (filas `pending` interrumpidas por crash de PHP) al
   cargar la vista global o ejecutar un reintento. Sin cron.

Para C4 — la transacción atómica del Plan 1 (`authorizePayment` con todos los pasos
dentro de `Connection::transactional`) cubre ese flujo sin necesitar outbox.

## Consecuencias

**Positivas:**
- Cero infraestructura nueva en el servidor. No hay cron que cuidar, monitorear,
  reiniciar.
- El usuario ve los fallos de email en tiempo real (no descubre 30 min después que
  el correo nunca salió).
- Toda recuperación queda en la UI; auditable, con permisos, con motivo registrado.

**Negativas:**
- Recovery es **manual**, no automático. Si nadie revisa `/email-logs`, los failed
  quedan ahí.
- Mitigación: `/health` reporta `email_logs_failed_count` (Plan 6 — W7); cualquier
  monitor externo lo levanta como alerta.
- El usuario espera un poco más en flujos que disparan email (envío síncrono) — pero
  la espera es rara (el SMTP responde rápido cuando funciona) y el `CircuitBreaker`
  de `NotificationService` ya da fallback rápido cuando el SMTP está caído.

**Lo que se pierde respecto al outbox:**
- No hay despacho diferido genérico (no se reusa para webhooks o eventos futuros).
- Domain events del Plan 5 tuvieron que apoyarse en `EventManager` síncrono en lugar
  de en el outbox. Ver ADR 0007.

## Alternativas consideradas

### Outbox con worker CLI por cron (propuesta original)
Descartado por decisión operativa: un nuevo cron es un componente más al que cuidar
(monitoreo, restart, ownership de logs). Para una app interna con un equipo pequeño,
el costo operativo no se compensa.

### Outbox con worker daemon supervisor
Descartado por el mismo motivo (más complejo aún que el cron).

### Tragar las excepciones SMTP a `Log::error` (status quo previo)
Descartado: era el bug original W8. Usuarios veían "éxito" en pantalla y los correos
nunca llegaban.
```

- [ ] **Step 2: Crear `0004-sidebar-counters-cache-30s.md`**

```markdown
# ADR 0004 — Sidebar counters con cache TTL 30s

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

`SidebarCounterService::getCounters()` ejecuta ~13 queries de count por cada page
load (W11 de la auditoría). Multiplicado por el tráfico interno típico, es la fuente
más alta de queries en cualquier request del sistema.

## Decisión

**Cachear el array completo de contadores con TTL de 30 segundos, clave por rol.**

Configuración:

- `Cache::remember()` con engine `sidebar` (FileEngine, prefix `sgi_sidebar_`,
  duration `+30 seconds`).
- Clave: `sidebar_counters_{roleName}`.
- El fallback degradado (`_emptyCounters()`) se cachea junto con el éxito — si la DB
  cae, el sidebar muestra ceros 30s incluso después del recovery. Aceptable.

## Consecuencias

**Positivas:**
- En cache hit, el sidebar consume 0 queries.
- En cache miss (cada 30s por rol), 13 queries.
- Trafficked: 5 roles activos × 1 cache miss / 30s = ~10 cache fills/min global.
  Comparado con cada user-request golpeando 13 queries, es una reducción enorme.
- Cero infraestructura nueva: `FileEngine` ya estaba configurado.

**Negativas:**
- Lag de hasta 30s en ver un nuevo pendiente en el sidebar después de crearlo. En
  UX es invisible (los contadores son indicadores secundarios).
- Si la DB cae, el sidebar muestra ceros durante ese minuto y los siguientes 30s
  post-recovery.
- File-based: si SGI escalara a múltiples instancias, cada una tendría su cache.
  No es problema hoy (single instance) y migrar a Redis es cambiar `className`.

## Alternativas consideradas

### Tabla materializada `sidebar_counters` actualizada en write-side
Descartado por el costo de mantenimiento. Cada Table que afecta contadores
(Invoices, EmployeeNovelties, PettyCashRecords, NoveltyLiquidationDocs,
AdvanceLegalizations…) tendría que agregar hooks `afterSave`/`afterDelete` que
actualicen la tabla. Esa lógica duplica la de `getCounters()` y se desfasa fácilmente.
Una mejora futura, pero no se justifica hoy.

### Caché por usuario en lugar de por rol
Descartado: los contadores ya están agrupados por rol; cachear por usuario explotaría
la memoria (decenas de entradas por rol, una por usuario activo) sin beneficio.

### Caché por query individual (cada count su entrada)
Descartado: complejidad sin beneficio. El array completo de 13 contadores cabe en
una sola entrada de cache de pocos KB.

### Invalidar en `afterSave` de las tablas relevantes (write-through)
Descartado por acoplamiento. Mantenemos TTL puro: simple y predecible. Si en el
futuro 30s no es suficiente, se baja el TTL antes de añadir invalidación selectiva.
```

- [ ] **Step 3: Commit**

```bash
git add docs/adr/0003-email-log-sync-instead-of-outbox.md docs/adr/0004-sidebar-counters-cache-30s.md
git commit -m "docs(plan-7): ADR 0003 (email log) + 0004 (sidebar cache)"
```

---

## Task 21: ADRs — 0005 (DI Container) + 0006 (State pattern)

**Files:**
- Create: `docs/adr/0005-di-container-application-services.md`
- Create: `docs/adr/0006-state-pattern-invoice-pipeline.md`

- [ ] **Step 1: Crear `0005-di-container-application-services.md`**

```markdown
# ADR 0005 — DI Container centralizado en `Application::services()`

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Antes del Plan 3 (DI Container), el wiring de servicios usaba el patrón
`?? new ServiceClass()` en cada constructor. Síntomas (W3 del audit):

- `InvoiceHistoryService` se instanciaba múltiples veces por request (cada service
  que lo necesitaba creaba uno nuevo).
- El grafo de dependencias era invisible — había que leer cada constructor para
  reconstruirlo.
- La cache interna de `AuthorizationService` (W5) era inútil porque cada request
  creaba un `AuthorizationService` nuevo.
- Test wiring era imposible sin `?? new MockClass()` en producción.

## Decisión

**Registrar todos los services en `Application::services(ContainerInterface)`** —
método que CakePHP 5 expone para configurar su contenedor (League Container debajo).

Los servicios se construyen con sus dependencias declaradas. Los constructores ya no
tienen `?? new`. El container resuelve transitivamente.

`AuthorizationService` queda registrado como single-instance por request (su
caché interna empieza a funcionar — resuelve W5 colateralmente).

## Consecuencias

**Positivas:**
- Grafo de dependencias visible en un solo archivo.
- `InvoiceHistoryService` y similares se construyen una vez por request.
- Inyectar mocks/stubs en futuras tareas (cuando aparezcan tests) es trivial: cambiar
  el binding en una sub-clase de `Application`.
- `?? new` desaparece del código de servicios.

**Negativas:**
- `Application::services()` crece con cada servicio nuevo. Mitigable extrayendo
  módulos (no necesario hoy).
- Los servicios dependen del container — si el container falla en construir uno, la
  app no arranca. Es un fail-fast deseable, pero requiere disciplina al añadir deps.

## Alternativas consideradas

### Service Locator (`$this->getContainer()->get(...)` en cada caller)
Descartado: oculta dependencias dentro de los métodos en lugar de declararlas en el
constructor. Es el anti-patrón que `?? new` ya sufría, sólo movido un nivel.

### Una factory por servicio
Descartado: 36+ factories sería ruido. El container hace lo mismo con menos código.

### Auto-wiring por reflexión sin registro explícito
Descartado: es lo que el container hace cuando no hay binding explícito, pero la
explicitud de un binding facilita debugging y deja un contrato claro de "qué inyectar
para este servicio".
```

- [ ] **Step 2: Crear `0006-state-pattern-invoice-pipeline.md`**

```markdown
# ADR 0006 — State pattern para el pipeline de facturas

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

Antes del Plan 4, el pipeline de 5 estados de facturas (`aprobacion → contabilidad →
tesoreria → autorizacion_pago → pagada`) vivía como `const TRANSITIONS = [...]`
arrays + `match` / `switch` chains en `InvoicePipelineService`. Síntomas:

- W9 — el mismo patrón procedural se repetía en `NoveltyPipelineService`,
  `PaymentSchedulingPipelineService`.
- Añadir un estado nuevo requería tocar 5+ lugares dentro del mismo archivo.
- OCP violado: cualquier cambio de comportamiento por estado tocaba `InvoicePipelineService`.

## Decisión

**Convertir cada estado en una clase polimórfica que implementa
`InvoicePipelineState`.**

Interfaz mínima (ver `src/Service/Pipeline/State/`):

- `getName(): string`
- `getNext(?string $documentType): ?string`
- `getPrevious(): ?string`
- `getEditableFields(string $roleName): array`
- `validateAdvance(Invoice, ...): array`
- `getRoleVisibility(): array`

Implementaciones: `AprobacionState`, `ContabilidadState`, `TesoreriaState`,
`AutorizacionPagoState`, `PagadaState`, `LegalizadaState`.

`InvoicePipelineService` se convierte en coordinador delgado que delega al state
actual. La transición rejected (`area_approval='Rechazada'`) se maneja como guard
externo, no como estado (no hay transiciones desde rejected sin reset explícito).

## Consecuencias

**Positivas:**
- Añadir un estado nuevo = un archivo nuevo + bind en factory de states. No tocar
  el coordinador.
- Reglas por estado quedan localizadas (cada State tiene su propio `validateAdvance`).
- Tests por State (cuando el proyecto los tenga) son aislados.

**Negativas:**
- Más archivos en `src/Service/Pipeline/State/`. Es el cost de OCP.
- Boilerplate menor: cada State implementa los 6 métodos aunque algunos sean stubs.
- Cambios cross-state (ej. añadir un campo a la interfaz) tocan todos los States. Es
  raro, pero pasa.

## Alternativas consideradas

### Mantener el array `TRANSITIONS` + chain de `match`
Descartado: era el problema W9 original. No escala.

### State machine library (Symfony Workflow, Finite, etc.)
Descartado: introducir dependencia para un dominio de 5 estados es overkill. Las
clases polimórficas dan la flexibilidad necesaria sin librería.

### Estados como enum + métodos en `Invoice` entity
Descartado por anemic-vs-rich tradeoff. Mantener la lógica de transición en services
(layered classic, ADR 0001) y la entidad como datos. Si SGI migrara a DDD, esta
sería la opción natural.
```

- [ ] **Step 3: Commit**

```bash
git add docs/adr/0005-di-container-application-services.md docs/adr/0006-state-pattern-invoice-pipeline.md
git commit -m "docs(plan-7): ADR 0005 (DI container) + 0006 (state pattern)"
```

---

## Task 22: ADRs — 0007 (Domain Events) + 0008 (OCC + Idempotency)

**Files:**
- Create: `docs/adr/0007-domain-events-eventmanager-sync.md`
- Create: `docs/adr/0008-optimistic-concurrency-and-idempotency.md`

- [ ] **Step 1: Crear `0007-domain-events-eventmanager-sync.md`**

```markdown
# ADR 0007 — Domain events vía `EventManager` síncrono in-process

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

El audit C6 marcaba un ciclo entre `InvoicePipelineService` ↔ `InvoicePaymentService`
↔ `AdvanceLegalizationService`. El ciclo se sostenía con `?? new` lazy-init, lo cual
oscurecía el grafo y hacía las dependencias circulares invisibles.

El Plan 5 introdujo eventos de dominio para romper el ciclo. La pregunta de
implementación fue: ¿qué mecanismo de despacho?

El roadmap original asumía que el outbox del Plan 2 sería la base del despacho. Pero
el outbox fue descartado (ver ADR 0003). Así que Plan 5 tuvo que decidir mecanismo
desde cero.

## Decisión

**Despachar eventos de dominio síncronamente, in-process, vía
`Cake\Event\EventManager`.**

Eventos definidos:

- `InvoicePaidEvent` — disparado cuando una factura llega a `pagada`.
- `InvoiceRefundAuthorizedEvent`, `InvoiceRefundRejectedEvent` — flujos de refund.
- `AdvanceLegalizedEvent` — cuando una legalización completa.

Suscriptores:

- `LegalizationInitializerSubscriber` reacciona a `Invoice.paid` y dispara la
  inicialización si la factura es de tipo anticipo.
- (otros según el dominio)

`InvoicePipelineService` ya no llama directo a `AdvanceLegalizationService`. Emite
el evento al `EventManager` global y deja que el subscriber decida.

## Consecuencias

**Positivas:**
- El ciclo de dependencias C6 se rompe. Cada service ya no conoce a los otros.
- Añadir un nuevo subscriber a un evento existente no toca al publisher.
- Despacho **síncrono** garantiza que el subscriber corre dentro de la misma
  transacción que el publisher (cuando hay una). Plan 5 lo aprovecha para que la
  inicialización de legalización rollback junto con el `authorizePayment`.

**Negativas:**
- Acoplamiento temporal: si un subscriber es lento, el publisher espera.
  Mitigación: subscribers deben ser rápidos; trabajos largos se delegan a otra
  pieza (que hoy no existe — si surge la necesidad, se introducirá un mecanismo
  diferido específico).
- Errores en subscriber pueden derribar al publisher. Mitigación: subscribers
  críticos atrapan sus propias excepciones; los no-críticos pueden burbujear.
- No hay despacho diferido / out-of-process. Si SGI necesita publicar a otros
  sistemas, se introducirá outbox específico para integración (no para eventos
  internos).

## Alternativas consideradas

### Outbox + worker para todos los eventos
Descartado por la misma razón del ADR 0003: cero infra nueva. El outbox era el
camino natural cuando estaba sobre la mesa, pero al descartarse, EventManager era la
opción restante razonable.

### Reusar `WebhookService` como bus de eventos internos
Descartado: el webhook service hace HTTP a sistemas externos (n8n). Reutilizarlo para
eventos internos mezclaría dos canales con semánticas distintas (in-process vs HTTP).

### Bus async dedicado (RabbitMQ, Redis pub/sub)
Descartado por sobre-ingeniería. El dominio actual no requiere despacho asíncrono;
introducir un broker añade infraestructura, monitoreo y latencia.
```

- [ ] **Step 2: Crear `0008-optimistic-concurrency-and-idempotency.md`**

```markdown
# ADR 0008 — Optimistic concurrency + idempotency keys para mutaciones

- **Status:** Accepted
- **Date:** 2026-05-01
- **Deciders:** Equipo SGI

## Contexto

El audit W6 marcó dos clases de fallo en mutaciones críticas:

- **Doble-click en "Registrar pago"** crea dos pagos para la misma factura.
- **Doble-click en "Avanzar"** intenta dos transiciones, ambas pasan validation y
  una crea inconsistencia (estado avanzado dos veces, pagos recalculados raros).

## Decisión

**Estrategia híbrida según el tipo de mutación.**

### Pagos (`registerPayment`)

**Idempotency key.** El form genera un UUID de un solo uso (`idempotency_key`)
embebido como hidden input. La columna `invoice_payments.idempotency_key` tiene
índice único; PDO lanza error de constraint si la misma key se reusa, y el caller lo
atrapa devolviendo `ServiceResult` "ya procesado, no es error".

### Avances de pipeline (`advanceStatus`, `advance`)

**Optimistic concurrency stateless.** El form incluye un hidden
`expected_status` con el estado actual al renderizar la página. El controller
verifica que el estado en DB siga siendo el esperado antes de avanzar; si difiere,
muestra "alguien más cambió esto, recargue" y aborta.

No usamos versión de fila (column `version` con `WHERE version = ?`) porque el
estado del pipeline ya cumple el rol de "qué fila lógica estás editando" — si el
estado cambió, la fila lógica cambió también.

## Consecuencias

**Positivas:**
- Doble-click en pagos no produce duplicados (DB lo bloquea).
- Doble-click en avances no produce double-advance (controller lo bloquea).
- Stateless: no hay estructura nueva en cache ni en DB para tracking de operaciones.
- Errores de concurrencia muestran feedback claro al usuario en lugar de inconsistencia
  silenciosa.

**Negativas:**
- Dos mecanismos distintos (idempotency key vs expected_status). Trade-off: cada uno
  ajusta a su tipo de mutación; un solo mecanismo no encaja igual de bien en ambos.
- Idempotency keys requieren ser únicos por usuario/sesión. Si el form se cachea
  (back button + reenvío), la segunda petición falla con feedback útil ("ya
  procesado") en lugar de duplicar.
- El usuario que pierde una carrera ve una pantalla de "alguien más cambió esto",
  lo cual a veces sorprende. El mensaje debe ser claro.

## Alternativas consideradas

### Locks pesimistas (`SELECT ... FOR UPDATE`)
Descartado por costo: bloquear filas durante la edición de un usuario congela el
flujo para otros que intentan ver/editar. La concurrencia real en SGI es baja y no
amerita ese costo.

### Token-based deduplication en cache (Redis)
Descartado: introduciría Redis donde no lo necesitamos hoy. La columna única en DB
da el mismo efecto sin infra adicional.

### Disable button JS-only
Descartado: protege la mayoría de los doble-clicks pero no los submits con
JavaScript desactivado, ni recargas posteriores que reenvían el form. Útil como
**capa adicional** (UX), no como única defensa.
```

- [ ] **Step 3: Commit**

```bash
git add docs/adr/0007-domain-events-eventmanager-sync.md docs/adr/0008-optimistic-concurrency-and-idempotency.md
git commit -m "docs(plan-7): ADR 0007 (domain events) + 0008 (OCC + idempotency)"
```

---

## Task 23: Cierre — actualizar roadmap, cs-fix, commit final

**Files:**
- Modify: `docs/audits/architecture-audit-roadmap.md`

- [ ] **Step 1: Actualizar la tabla de estado del roadmap**

Abrir `docs/audits/architecture-audit-roadmap.md`. Localizar la tabla de estado al
final ("Tabla de estado") y actualizar la fila del Plan 7 a:

```markdown
| 7 | Observability + Polish | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-observability-polish-design.md) | [plan](../superpowers/plans/2026-05-01-observability-polish-plan.md) | — | 2026-05-01 |
```

Localizar también el "Resumen ejecutivo" (al inicio) y actualizar la fila del Plan 7
a `🟢 Completado`.

- [ ] **Step 2: Añadir entrada en "Cambios al roadmap"**

Al final de la sección "Cambios al roadmap", añadir:

```markdown
### 2026-05-01 — Plan 7: ADR del outbox sustituido + set ampliado a 8 ADRs

Spec resultante: [`docs/superpowers/specs/2026-05-01-observability-polish-design.md`](../superpowers/specs/2026-05-01-observability-polish-design.md)

El roadmap original incluía un ADR de "Outbox como columna vertebral de integraciones;
Saga implícita". El outbox fue descartado en el pivot del Plan 2 (2026-05-01), así
que ese ADR no tenía sujeto. Sustituido por **ADR 0003 — Email log síncrono con
reintento manual; descartar outbox**, que documenta justamente el pivot.

Adicionalmente, el set de ADRs creció de 4 (los del roadmap original) a 8 incluyendo
las decisiones tácticas/arquitectónicas de los Planes 3–6:

- 0001 — Layered Architecture, no DDD
- 0002 — ServiceResult en lugar de excepciones
- 0003 — Email log síncrono (sustituye al "Outbox como columna vertebral")
- 0004 — Sidebar counters con cache 30s
- 0005 — DI Container centralizado (Plan 3)
- 0006 — State pattern para invoice pipeline (Plan 4)
- 0007 — Domain events vía EventManager síncrono (Plan 5)
- 0008 — Optimistic concurrency + idempotency keys (Plan 6)

Razón: dejar las decisiones estratégicas en `docs/adr/` evita que un futuro auditor
o dev nuevo tenga que reconstruir el "por qué" de cada elección desde specs/commits.

**Cierre del roadmap.** Con el merge del Plan 7, los 6 críticos (C1–C6) y los
15 warnings (W1–W15) de la auditoría 2026-04-30 quedan resueltos.
```

- [ ] **Step 3: Correr `cs-fix` en todo lo modificado**

```bash
composer cs-fix
```

Verificar la salida — si hay archivos auto-corregidos, son normalmente espacios y
ordering.

- [ ] **Step 4: Verificar `cs-check`**

```bash
composer cs-check
```

Esperado: sin errores.

- [ ] **Step 5: Commit del cierre**

Si `cs-fix` cambió archivos, agregarlos junto con el roadmap:

```bash
git add docs/audits/architecture-audit-roadmap.md
git add -u  # captura cualquier cambio de cs-fix en archivos ya tracked
git commit -m "chore(plan-7): cierre del Plan 7 (Observability + Polish)"
```

- [ ] **Step 6: Validación manual final (usuario)**

> Los pasos abajo los ejecuta el usuario manualmente. No se ejecutan desde aquí.

Levantar el servidor:

```bash
php bin/cake server
```

Validaciones del spec § 10 a recorrer:

**W1 — StructuredLogger:**
- Verificar `grep -rn "Log::" src/Service/ --include="*.php"` ⇒ sólo 3 hits dentro
  de `StructuredLogger.php`.
- Enviar approval link de una factura. `tail -f logs/error.log` ⇒ entrada JSON con
  `correlationId`, `context: "Notification"`, `data.recipient`, `data.invoice_id`.
- Forzar fallo de webhook (n8n apagado o URL inválida) ⇒ entradas JSON con `context:
  "CircuitBreaker.webhook"`.

**W12 — catches específicos:**
- `grep -n "catch (Exception" src/Service/Dashboard/ src/Service/SidebarCounterService.php`
  ⇒ vacío (todos migraron a tipo específico).
- Provocar un error en stats (renombrar columna en DB de prueba o forzar excepción).
  Cargar dashboard ⇒ no rompe; widgets afectados muestran ceros/vacío; log con
  `*_query_failed`.

**W11 — sidebar cache:**
- Cargar dashboard como Tesorería ⇒ existe archivo `tmp/cache/sgi_sidebar_*` para
  ese rol.
- Crear factura nueva como Registro. Recargar dashboard como Tesorería en <30s ⇒ el
  contador NO refleja el cambio.
- Esperar 30s y recargar ⇒ contador refleja el cambio.
- Cambiar de rol ⇒ archivo de cache distinto.

**W15 — ServiceResult:**
- En `invoices/edit` con datos válidos que cumplen requirements ⇒ avance + flash de
  éxito.
- Con datos válidos que NO cumplen requirements ⇒ guarda pero no avanza, flash
  warning con `advanceErrors`.
- Con datos inválidos ⇒ flash error.
- Mismo ciclo en `employee_novelties/edit`, `payment_schedulings/edit`,
  modificación de aprobadores en facturas, aprobación externa.

**W4 — vista 403:**
- Login como rol no-admin sin permiso `invoices.can_view`.
- Visitar `/invoices` directo ⇒ template `error400.php` con layout `default`,
  mensaje "Acceso restringido" + botón "Volver al inicio". HTTP 403.
- Visitar otra URL prohibida desde la 403 ⇒ misma vista, sin loop.
- URL permitida ⇒ carga normal.
- Sin login ⇒ redirect a login (Authentication middleware), no la 403.

**ADRs:**
- `ls docs/adr/` ⇒ 10 archivos (`README.md`, `template.md`, 0001-0008).
- Cada ADR: `Status: Accepted`, fecha, 4 secciones (Contexto, Decisión,
  Consecuencias, Alternativas).
- `docs/adr/README.md` ⇒ índice con los 8 ADRs.

**Style:**
- `composer cs-check` ⇒ pasa.

---

## Self-Review (post-plan)

Verificación interna del plan vs spec:

- ✅ **W1 (3 servicios):** Tasks 1–3.
- ✅ **W12 (7 archivos):** Tasks 4–8 cubren Dashboard ×2, SidebarCounterService,
  Strategies ×2, LeaveDocumentService, ExcelImportService.
- ✅ **W11 (cache + helpers):** Tasks 6 (helpers), 9 (config engine), 10 (cache wrap).
- ✅ **W15 (4 servicios + callers):** Tasks 11–14 con callers atómicos por servicio.
- ✅ **W4 (3 cambios):** Tasks 15 (controller), 16 (template), 17 (CSS).
- ✅ **ADRs (8 docs + setup):** Tasks 18–22.
- ✅ **Cierre y validación manual:** Task 23.

**Sin placeholders TBD/TODO.** Cada step muestra el código antes y después.
**Tipos consistentes.** `ServiceResult` se usa con la misma firma (`ok($data)`,
`fail($errors)`, `->success`, `->data`, `->errors`, `->firstError()`) en todos los
callers.
