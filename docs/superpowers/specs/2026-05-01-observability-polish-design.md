# Plan 7 — Observability + Polish (design spec)

**Fecha:** 2026-05-01
**Roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md)
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
**Items cubiertos:** W1, W4, W11, W12, W15, ADRs

---

## 1. Contexto

Plan 7 cierra el roadmap de la auditoría de arquitectura. Es **polish**: no introduce capacidades nuevas, no desbloquea otros planes. Su objetivo es consolidar lo construido en Planes 1–6 y dejar el código en una forma que un futuro auditor (o un nuevo dev) pueda navegar sin tener que reconstruir el "por qué" detrás de cada decisión.

Cinco frentes técnicos + un frente documental:

- **W1** — sustituir las 6 llamadas directas a `Cake\Log\Log::*` que quedan en servicios por `StructuredLogger`. Garantiza que el `correlationId` (inyectado por `CorrelationIdMiddleware`) se propague a todos los logs.
- **W12** — reemplazar `try { ... } catch (Exception) { return 0; }` por captura específica + log estructurado + decisión explícita (re-throw o fallback).
- **W11** — cachear `SidebarCounterService::getCounters()` por 30 segundos por rol. Evita las ~13 queries que corren en cada page load.
- **W15** — estandarizar `ServiceResult` en métodos con side effects que hoy devuelven `array` (`saveAndAdvance` y similares).
- **W4** — sustituir el redirect a `referer()` de `_enforcePermission()` por una vista 403 dedicada que rompe el riesgo de loop por construcción.
- **ADRs** — escribir 8 Architecture Decision Records que documentan las decisiones estratégicas del proyecto (Layered/no-DDD, ServiceResult, email log síncrono, sidebar cache, DI container, State pattern, Domain Events, OCC+idempotency).

---

## 2. Alcance y orden de ejecución

**Orden interno** (W1 va primero porque W12 lo necesita; ADRs van al final porque se nutren de todo lo previo):

1. **W1** — StructuredLogger en `NotificationService`, `CircuitBreaker`, `DianCrosscheckService`.
2. **W12** — catches específicos con política híbrida (UI degradable vs side-effect vs nullable finder).
3. **W11** — cache de sidebar (TTL 30s, FileEngine, clave por rol).
4. **W15** — `saveAndAdvance` y métodos hermanos a `ServiceResult`.
5. **W4** — `ForbiddenException` + template `Error/error400.php` con detección de la clase.
6. **ADRs** — 8 documentos en `docs/adr/`.

**Lo que NO entra:**

- Migrar `StructuredLogger` a PSR-3 (`LoggerInterface`). El audit no lo exige y el patrón actual ya funciona.
- Tocar el cargador de logs global (`Cake\Log\Log` en `config/app.php`); seguimos usando los canales existentes.
- Métricas Prometheus / OpenTelemetry. La "observability" del Plan 7 es estructurar logs + ADRs, no añadir un sistema de métricas.
- Tocar servicios de pura lectura/cálculo en W15 (`InvoiceFilterService`, `PaymentRegistryService`, `*Statistics`, etc.). Devolver `array`/`int` en getters/finders sigue siendo correcto.
- Refactor amplio de logging en services que hoy NO loggean nada — sólo se añade `StructuredLogger` cuando un servicio lo necesita por W1 o W12.

---

## 3. W1 — Migración a `StructuredLogger`

### Servicios afectados

| Servicio | Llamadas a migrar | Contexto del logger |
|---|---|---|
| `NotificationService` | `Log::info` ×2 (líneas 80, 129) | `Notification` |
| `CircuitBreaker` | `Log::warning` ×2 + `Log::error` ×1 (líneas 47, 64, 93) | `CircuitBreaker.{name}` |
| `DianCrosscheckService` | `Log::warning` ×1 (línea 89) | `DianCrosscheck` |

### Patrón uniforme

Sigue el patrón ya establecido en `WebhookService`, `Retryer`, `EmailLogService`:

```php
private StructuredLogger $logger;

public function __construct(/* deps */) {
    // ...
    $this->logger = new StructuredLogger('Notification');
}
```

Para `CircuitBreaker`, el contexto incluye el `name` del breaker para no perderlo al pasar el mensaje a JSON:

```php
$this->logger = new StructuredLogger('CircuitBreaker.' . $name);
```

### Forma del call site

`Log::info("texto interpolado")` se reemplaza por `$this->logger->info($event, $data)`:

- **Antes:** `Log::info("Approval link sent to {$recipient->email} for invoice #{$invoice->id}");`
- **Después:**
  ```php
  $this->logger->info('approval_link_sent', [
      'recipient' => $recipient->email,
      'invoice_id' => $invoice->id,
  ]);
  ```

El `correlationId` lo añade automáticamente `StructuredLogger::_format()` leyendo `CorrelationIdMiddleware::getId()`.

### Imports

- Eliminar `use Cake\Log\Log;` de los 3 servicios.
- Añadir `use App\Service\StructuredLogger;` donde no exista.

### Verificación

```bash
grep -rn "Log::" src/Service/ --include="*.php"
# Debe devolver ÚNICAMENTE las 3 líneas dentro de StructuredLogger.php (es el wrapper).
```

---

## 4. W12 — Catches específicos con política híbrida

### Reglas

- **UI degradable** (dashboards, sidebar, widgets): catch específico → `$logger->error(...)` → fallback explícito con comentario que justifica la degradación.
- **Operaciones con side effects**: catch específico → `$logger->error(...)` → re-throw o `ServiceResult::fail()`.
- **Finders nullable** (`?Entity` declarado): atrapar sólo lo razonable (ej. `RecordNotFoundException`) → `$logger->warning(...)` → `null` está semánticamente OK.
- `catch (Exception $e)` global se reemplaza por excepciones concretas. Si no hay una concreta razonable (queries dinámicos en stats), se mantiene `Exception` con un comentario explicando por qué.

### Sitios a refactorizar

| Archivo | Línea | Categoría | Acción |
|---|---|---|---|
| `Service/Dashboard/InvoiceStatisticsService.php` | 51, 117, 171, 190 | UI degradable | log `error` + fallback (`[]` / `0`) |
| `Service/Dashboard/EmployeeStatisticsService.php` | 36, 76, 130, 172, 191 | UI degradable | log `error` + fallback (`[]` / `0`) |
| `Service/SidebarCounterService.php` | 72 | UI degradable | log `error` + fallback `_emptyCounters()` |
| `Service/Strategy/InvoiceApprovalStrategy.php` | 95 | finder nullable | log `warning` + `null` con comentario |
| `Service/Strategy/NoveltyApprovalStrategy.php` | 63 | finder nullable | log `warning` + `null` con comentario |
| `Service/LeaveDocumentService.php` | 366 | finder nullable | log `warning` + `null` con comentario |
| `Service/ExcelImportService.php` | 92, 269 | catch (Exception/Throwable) | log `warning` + `null` con comentario |

### Constructor: añadir `StructuredLogger`

Servicios que hoy NO tienen `StructuredLogger` y lo necesitan tras W12:

- `Dashboard/InvoiceStatisticsService` → contexto `Dashboard.InvoiceStats`
- `Dashboard/EmployeeStatisticsService` → contexto `Dashboard.EmployeeStats`
- `SidebarCounterService` → contexto `Sidebar`
- `InvoiceApprovalStrategy`, `NoveltyApprovalStrategy` → contextos `Strategy.InvoiceApproval`, `Strategy.NoveltyApproval`
- `LeaveDocumentService`, `ExcelImportService` → contextos coherentes con el nombre

Mismo patrón que W1: instanciación en constructor.

### Convención de logging

```php
// UI degradable
} catch (DatabaseException $e) {
    // Stats degraded: empty list shown instead of breaking the dashboard
    $this->logger->error('invoice_stats_query_failed', [
        'method' => __METHOD__,
        'exception' => $e->getMessage(),
    ]);
    return [];
}
```

```php
// Finder nullable
} catch (RecordNotFoundException) {
    return null;
}
```

```php
// Side-effect (cuando aparezca; hoy NotificationService ya propaga)
} catch (DatabaseException $e) {
    $this->logger->error('invoice_save_failed', [
        'invoice_id' => $invoice->id,
        'exception' => $e->getMessage(),
    ]);
    return ServiceResult::fail(['No se pudo guardar la factura.']);
}
```

### No tocar

- `NotificationService` — ya propaga (Plan 1/2).
- `Pipeline/State/*::getEditableFields()` que retornan `[]` por diseño (no tienen catch).
- Services que retornan `null` sin catch (es declaración de tipo, no swallow).

---

## 5. W11 — Cache de sidebar (Opción A, TTL 30s)

### Diseño

`SidebarCounterService::getCounters(string $roleName)` envuelve toda su lógica actual en `Cache::remember()` con TTL de 30s y clave por rol.

```php
public function getCounters(string $roleName): array
{
    return Cache::remember(
        "sidebar_counters_{$roleName}",
        function () use ($roleName) {
            try {
                return $this->_buildCounters($roleName);
            } catch (Exception $e) {
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

### Refactor interno

- Mover el bloque `try` actual a método privado `_buildCounters(string $roleName): array`.
- Mover el bloque `catch` actual a método privado `_emptyCounters(): array`.
- `getCounters()` queda reducido a la llamada `Cache::remember(...)`.

### Configuración (`config/app.php`)

Añadir bajo `Cache`:

```php
'sidebar' => [
    'className' => FileEngine::class,
    'duration' => '+30 seconds',
    'path' => CACHE,
    'prefix' => 'sgi_sidebar_',
],
```

`FileEngine` en lugar de Redis para no añadir dependencias. Si el proyecto migra a Redis algún día, sólo se cambia `className`.

### Decisiones explícitas (registradas en ADR 0004)

- **Clave por rol, no por usuario.** Los contadores ya están agrupados por rol; cachear por usuario explotaría memoria sin beneficio.
- **TTL 30s, no invalidación por evento.** No se invalida en `afterSave` de Invoices/EmployeeNovelties/etc. Añadir esos hooks acoplaría Tables a la cache de sidebar y duplicaría la lógica de "qué cambió afecta qué contador".
- **Cache del array completo, no por query.** Una entrada por rol contiene los 13 contadores; no cacheamos cada count por separado.

### Trade-off aceptado

Un usuario puede ver hasta 30s de lag en contadores nuevos. Es invisible en UX (los contadores son indicadores, no transaccionales) y queda documentado en el ADR.

---

## 6. W15 — `ServiceResult` con alcance medio

### Principio

Si el método tiene side effects (persiste, transiciona estado, dispara eventos, envía email) o puede fallar de manera que el caller deba reaccionar, devuelve `ServiceResult`. Si es un getter/finder/cálculo puro, devuelve el tipo natural.

### Métodos a migrar

| Servicio | Método | Retorno actual | Retorno nuevo |
|---|---|---|---|
| `InvoicePipelineService` | `saveAndAdvance()` | `array{saved, advanced, nextStatus, advanceErrors}` | `ServiceResult` con `data: ['advanced' => bool, 'nextStatus' => ?string, 'advanceErrors' => string[]]` |
| `NoveltyPipelineService` | `advance()` | `array` | `ServiceResult` |
| `NoveltyPipelineService` | `advanceGroup()` | `array` | `ServiceResult` |
| `NoveltyPipelineService` | `reject()` | `array` | `ServiceResult` |
| `PaymentSchedulingPipelineService` | `regress()` | `array{success, error, previousStatus}` | `ServiceResult` con `data: ['previousStatus' => ?string]` |
| `InvoiceApprovalService` | `assignApprovers()` | `array` | `ServiceResult` |
| `InvoiceApprovalService` | `processResponse()` | `array` | `ServiceResult` |
| `InvoiceApprovalService` | `sendApprovalLinks()` | `array` | `ServiceResult` |
| `InvoiceApprovalService` | `modifyApprovers()` | `array{success, errors, approvals}` | `ServiceResult` con `data: ['approvals' => array]` |

> `InvoiceApprovalService::resetFlow()` **ya retorna `ServiceResult`** (no requiere migración). Métodos de lectura (`getActiveApprovals`, `getCurrentApprovals`, `getApprovalSummary`, `areAllApproved`, `hasPendingApprovals`, `hasAnyActiveApprovals`, `validateToken`) se mantienen con su tipo natural.

### Forma de los `ServiceResult`

- `success === true` → operación completó (avanzó o no, eso va en `data`).
- `success === false` → operación falló y `errors` lista las razones (validation errors, save fallido).

### Caso `saveAndAdvance` en detalle

Hoy:

```php
[
  'saved' => bool,
  'advanced' => bool,
  'nextStatus' => ?string,
  'advanceErrors' => string[],
]
```

Después:

```php
// Save fallido
return ServiceResult::fail(['No se pudo guardar la factura.']);

// Save OK pero advance bloqueado por requirements
return ServiceResult::ok([
    'advanced' => false,
    'nextStatus' => null,
    'advanceErrors' => $advanceErrors,
]);

// Save OK + advance OK
return ServiceResult::ok([
    'advanced' => true,
    'nextStatus' => $advanceNextStatus,
    'advanceErrors' => [],
]);
```

**No extendemos `ServiceResult` con un campo `warnings`.** Los `advanceErrors` viajan dentro de `data`. Cambiar la firma de `ServiceResult` afectaría a los 8 servicios que ya lo usan; queda fuera de alcance.

### Patrón para callers

```php
$result = $this->pipeline->saveAndAdvance($invoice, $data, $role, $userId);

if (!$result->success) {
    $this->Flash->error(implode("\n", $result->errors));
    return $this->redirect(['action' => 'edit', $invoice->id]);
}

$advanced = $result->data['advanced'];
$advanceErrors = $result->data['advanceErrors'] ?? [];

if ($advanced) {
    $this->Flash->success('Factura guardada y avanzada a ' . $result->data['nextStatus']);
} elseif (!empty($advanceErrors)) {
    $this->Flash->warning('Factura guardada. No se pudo avanzar: ' . implode(', ', $advanceErrors));
} else {
    $this->Flash->success('Factura guardada.');
}
```

### Callers a actualizar

- `InvoicesController` — métodos que llaman `saveAndAdvance` (ej. `edit`, `advance` si existe).
- `EmployeeNoveltiesController` — métodos que llaman `NoveltyPipelineService::advance/advanceGroup/reject`.
- `PaymentSchedulingsController` — métodos que llaman `PaymentSchedulingPipelineService::regress`.
- Callers de `InvoiceApprovalService::assignApprovers / processResponse / sendApprovalLinks / modifyApprovers` (probablemente `InvoicesController` y `ExternalApprovalController`).
- Verificar con grep al iniciar el plan que no haya callers fuera de los listados.

### Servicios sin cambio (decisión consciente)

- `InvoiceFilterService`, `PaymentRegistryService` (lectura)
- `InvoiceFieldAccessPolicy`, `InvoiceLockPolicy`, `InvoiceTransitionValidator` (políticas/cálculo)
- `Dashboard/*`, `SidebarCounterService` (UI/lectura)
- `AuthorizationService` (matriz de permisos)
- `*HistoryService::recordChanges` (void)
- `*DocumentService::upload` (no falla en sentido de dominio)
- Strategies con `?Entity` en su firma (semántica nullable)

---

## 7. W4 — Vista 403 dedicada con `ForbiddenException`

### Cambio en `AppController::_enforcePermission()`

Antes:

```php
if (!$this->_checkPermission($module, $permAction)) {
    $this->Flash->error('No tiene permisos para acceder a esta función.');
    if ($controllerName === 'Dashboard' && $action === 'index') {
        $this->redirect(['controller' => 'Users', 'action' => 'login']);
    } else {
        $this->redirect($this->request->referer() ?: ['controller' => 'Dashboard', 'action' => 'index']);
    }
}
```

Después:

```php
if (!$this->_checkPermission($module, $permAction)) {
    throw new ForbiddenException(
        sprintf('No tiene permisos para %s en %s.', $permAction, $module)
    );
}
```

Imports: añadir `use Cake\Http\Exception\ForbiddenException;` en `AppController`.

`ForbiddenException` produce HTTP 403 y dispara el ErrorHandler de Cake, que renderiza `templates/Error/error400.php` por defecto (Cake busca un template específico para la clase, si no existe usa `error400.php` para todas las 4xx).

### Template `templates/Error/error400.php` (modificar el existente)

Ya existe un `error400.php` con diseño dark de "página de error genérica" (layout `error`, código grande, mensaje, URL no encontrada). Lo **mantenemos intacto** para las 4xx que no son `ForbiddenException` y añadimos al inicio una rama que detecta `ForbiddenException` y renderiza la vista de "Acceso restringido" con layout `default` (con sidebar/header).

Estructura final del archivo:

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 * @var \Throwable|null $error
 */

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
    return; // No renderizar el bloque genérico de abajo
endif;

// === A partir de aquí, comportamiento existente para otras 4xx (sin cambios) ===
?>
<?php // ... contenido actual del archivo, intacto ... ?>
```

Punto crítico: la rama `Forbidden` debe ejecutar `return;` para no renderizar el bloque dark de error genérico debajo. El layout `default` también descarta el `setLayout('error')` que el archivo hace después.

### Estilos

`.sgi-forbidden-page` se añade a `webroot/css/styles.css` con el mismo lenguaje visual que el resto del sistema (borders, no shadows, paleta dark/green/orange). Estructura mínima — título, párrafo, botón de retorno.

### Excenciones

`EmailLogs::retry` ya tiene su propia validación interna (delega a `invoices.can_edit` / `employee_novelties.can_edit`); el bypass actual del `_enforcePermission()` se mantiene intacto.

---

## 8. ADRs — 8 documentos en `docs/adr/`

### Estructura común (formato MADR mínimo)

```markdown
# ADR NNNN — Título

- Status: Accepted
- Date: YYYY-MM-DD
- Deciders: Equipo SGI

## Contexto
Qué situación/problema motiva la decisión.

## Decisión
Qué se decidió, en una frase clara.

## Consecuencias
Pros y contras. Qué se gana, qué se pierde, qué obliga a futuro.

## Alternativas consideradas
Qué se evaluó y descartó, y por qué.
```

### Documentos a crear

| # | Archivo | Decisión | Plan origen |
|---|---|---|---|
| 0001 | `0001-layered-architecture-no-ddd.md` | Capas (Controller → Service → Table/Entity); por qué no DDD táctico/estratégico | proyecto base |
| 0002 | `0002-service-result-instead-of-exceptions.md` | `ServiceResult::ok/fail` para errores de dominio; excepciones sólo para errores de infraestructura | proyecto base |
| 0003 | `0003-email-log-sync-instead-of-outbox.md` | Email log síncrono + reintento manual; por qué se descartó outbox+worker | Plan 2 (pivot 2026-05-01) |
| 0004 | `0004-sidebar-counters-cache-30s.md` | Cache TTL 30s vs tabla materializada; trade-off frescura/complejidad | Plan 7 |
| 0005 | `0005-di-container-application-services.md` | Registro centralizado en `Application::services()`; eliminación del patrón `?? new` | Plan 3 |
| 0006 | `0006-state-pattern-invoice-pipeline.md` | State pattern para pipeline de facturas; polimorfismo vs `match`/arrays | Plan 4 |
| 0007 | `0007-domain-events-eventmanager-sync.md` | `EventManager` síncrono in-process; por qué no bus async; relación con el outbox descartado | Plan 5 |
| 0008 | `0008-optimistic-concurrency-and-idempotency.md` | OCC + idempotency keys para mutaciones críticas; trade-offs vs locks pesimistas | Plan 6 |

### Archivos auxiliares en `docs/adr/`

- **`README.md`** — índice maestro: lista los 8 ADRs con número, título y status. Incluye nota: "Para crear un nuevo ADR, copia `template.md` y numera consecutivamente. Si una decisión queda obsoleta, cambia su status a `Superseded by ADR NNNN`; no borrar."
- **`template.md`** — esqueleto vacío con las 4 secciones, para futuras decisiones.

### Estilo

Prosa en español (consistente con specs/plans). Cada ADR ~40–80 líneas. Foco: el **por qué**, no la implementación. La implementación vive en specs/plans/código.

---

## 9. Cambios externos (controllers, templates, config)

### Controllers a tocar

- `AppController` — `_enforcePermission()` (W4).
- `InvoicesController` — callers de `saveAndAdvance` (W15).
- `EmployeeNoveltiesController` — callers de NoveltyPipeline (W15).
- `PaymentSchedulingsController` — callers de `regress()` (W15).
- `InvoicesController` y `ExternalApprovalController` (o equivalente) — callers de `InvoiceApprovalService::assignApprovers / processResponse / sendApprovalLinks / modifyApprovers` (W15).

### Templates a tocar

- `templates/Error/error400.php` — **modificar** (existente; añadir rama `ForbiddenException` arriba) (W4).
- `webroot/css/styles.css` — clase `.sgi-forbidden-page` (W4).

### Config a tocar

- `config/app.php` — añadir engine `sidebar` bajo `Cache` (W11).

### Archivos nuevos

- `docs/adr/README.md`
- `docs/adr/template.md`
- `docs/adr/0001-layered-architecture-no-ddd.md`
- `docs/adr/0002-service-result-instead-of-exceptions.md`
- `docs/adr/0003-email-log-sync-instead-of-outbox.md`
- `docs/adr/0004-sidebar-counters-cache-30s.md`
- `docs/adr/0005-di-container-application-services.md`
- `docs/adr/0006-state-pattern-invoice-pipeline.md`
- `docs/adr/0007-domain-events-eventmanager-sync.md`
- `docs/adr/0008-optimistic-concurrency-and-idempotency.md`

---

## 10. Criterios de validación manual

> No hay tests automatizados. Validación post-merge con `php bin/cake server`.

### W1 (StructuredLogger)

- `grep -rn "Log::" src/Service/ --include="*.php"` devuelve sólo las 3 llamadas dentro de `StructuredLogger.php`.
- Enviar approval link de una factura nueva → entrada JSON en `logs/error.log` (canal info por defecto) con `correlationId`, `context: "Notification"`, `data.recipient`, `data.invoice_id`.
- Forzar fallo de webhook (n8n apagado o URL inválida) → entradas JSON con `context: "CircuitBreaker.webhook"` que reflejan el ciclo open/closed.

### W12 (catches específicos)

- `grep -rn "catch (Exception" src/Service/Dashboard/ src/Service/SidebarCounterService.php` muestra sólo catches con tipo específico (o `Exception` con comentario justificando).
- Provocar fallo en una de las queries de stats (renombrar temporalmente una columna en una copia de DB, o forzar un error vía debug) → cargar dashboard → la página NO rompe; widgets afectados muestran datos vacíos; log estructurado con `*_query_failed` y stack trace.
- Restaurar.

### W11 (cache de sidebar)

- Cargar dashboard como Tesorería → archivo `tmp/cache/sidebar/sgi_sidebar_sidebar_counters_Tesorería` existe.
- Crear factura nueva como Registro → cargar dashboard como Tesorería en <30s → contador NO refleja el cambio (esperado por TTL).
- Esperar 30s + recargar → contador refleja el cambio.
- Cambiar de rol (Admin) → archivo de cache distinto (clave por rol).

### W15 (`ServiceResult`)

- `invoices/edit` con datos válidos que cumplen requirements → factura avanza, flash de éxito.
- `invoices/edit` con datos válidos que NO cumplen requirements (por ejemplo, sin documentos requeridos para la transición) → factura se guarda pero no avanza, flash de tipo `warning` con los `advanceErrors`.
- `invoices/edit` con datos inválidos (validación fallida) → no se guarda, flash de error con `errors`.
- Mismo ciclo en `employee_novelties/edit` (NoveltyPipeline).

### W4 (vista 403)

- Login como rol no-admin sin permiso `invoices.can_view`.
- Visitar `/invoices` directo (sin referer) → ve `error400.php` con layout `default`, mensaje "Acceso restringido", botón "Volver al inicio". HTTP 403.
- Visitar otra URL prohibida desde la pantalla 403 → misma vista; sin redirect loop.
- Visitar URL permitida → carga normal.
- Login no autenticado a una URL prohibida → comportamiento del Authentication middleware (redirect a login), NO la vista 403.

### ADRs

- `ls docs/adr/` muestra 10 archivos: `README.md`, `template.md` y los 8 ADRs (`0001-…` hasta `0008-…`).
- Cada ADR tiene `Status: Accepted`, fecha, las 4 secciones (Contexto, Decisión, Consecuencias, Alternativas).
- `docs/adr/README.md` lista los 8 ADRs con número, título y status.

### Style

- `composer cs-check` pasa.

---

## 11. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `saveAndAdvance` migrado rompe callers no detectados | Grep exhaustivo previo a la migración; revisar cada caller antes de cerrar el paso. |
| Cache de sidebar ofusca un bug nuevo de contadores | El log `sidebar_counters_failed` se mantiene; en dev, vaciar `tmp/cache/sidebar/` resetea instantáneamente. |
| `ForbiddenException` cambia el comportamiento esperado por usuarios actuales (estaban acostumbrados al redirect silencioso) | El template tiene mensaje claro y botón "Volver al inicio"; HTTP 403 es el comportamiento correcto. |
| ADRs quedan obsoletos rápido | Mantenerlos cortos y enfocados al "por qué"; el `template.md` y `README.md` describen cómo supersede. |
| `CircuitBreaker.{name}` produce muchos contextos distintos en logs | Ese es exactamente el objetivo — distinguir breakers en el JSON estructurado. |

---

## 12. Cierre

Plan 7 cierra el roadmap. Tras el merge:

- `docs/audits/architecture-audit-roadmap.md` se actualiza con fila `🟢 Completado` para Plan 7 + fecha + ruta de spec/plan.
- Se añade en "Cambios al roadmap" si hay desviaciones intencionales del alcance original (ej: el ADR del Outbox quedó como ADR del email log, set de ADRs ampliado a 8).
- Los 6 críticos y 15 warnings de la auditoría quedan resueltos (✅ todos).
