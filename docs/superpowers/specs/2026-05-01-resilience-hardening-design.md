# Plan 6 — Resilience Hardening (W6, W13, W14, W7)

**Origen:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) — Plan 6
**Fecha:** 2026-05-01
**Estado:** Spec aprobado, pendiente plan de implementación

---

## Contexto

La auditoría de 2026-04-30 identificó 4 warnings agrupables bajo "resiliencia": idempotencia ausente en mutaciones del usuario (W6), retry hardcodeado y no reusable (W13), sin bulkhead en integraciones outbound (W14), y sin endpoint `/health` (W7).

Plan 6 originalmente dependía de Plan 2 (outbox + worker async) para el bulkhead de W14. Tras el pivot del 2026-05-01, el outbox fue descartado; el bulkhead se reemplaza por **timeouts agresivos** en HTTP/SMTP. Plan 6 ya no depende de ningún otro plan.

## Objetivo

Endurecer el sistema contra cuatro modos de falla observables hoy:
1. Doble click en "Registrar pago" creando dos pagos idénticos.
2. Doble click en "Avanzar" saltando estados del pipeline.
3. Webhook a n8n / DIAN bloqueando un worker PHP-FPM 30s en cada intento.
4. SMTP lento bloqueando workers durante envío de approval links.
5. Sin manera de saber desde fuera si la app, su DB, su cache o sus integraciones están sanas.

---

## Decisiones de diseño

### D1 — W6 idempotencia: inline en `invoice_payments`, no tabla genérica

Se descarta la tabla `idempotency_keys` separada (request_hash, response cache, TTL, sweeping). En su lugar:

- Columna `idempotency_key VARCHAR(64) NULL` en `invoice_payments` con `UNIQUE INDEX`. MySQL InnoDB acepta múltiples `NULL` en uniques, así que filas históricas no se rompen.
- El form de "Registrar pago" en `templates/Invoices/edit.php` emite un `Text::uuid()` por render. Doble submit envía la misma key dos veces; la segunda inserción cae por `SQLSTATE 23000` y `InvoicePaymentService` la atrapa, busca la fila existente por la key y devuelve `ServiceResult::ok` con mensaje "Pago ya registrado (operación repetida)".

**Razón:** evita 1 tabla nueva, 1 servicio nuevo, 1 cron de sweeping. Mismo resultado funcional; el motor enforce la unicidad sin race window.

**Alcance acotado** (per audit, que nombra explícitamente `InvoicePaymentService`): la idempotencia se aplica **sólo** a `InvoicePaymentService::registerPayment` → tabla `invoice_payments`. Los otros tres flujos de "registrar pago" (`LiquidationDocPaymentService::registerPayment`, `PettyCashService::registerPayment`, `AdvanceLegalizationService::registerRefundPayment`) quedan fuera de alcance — sus tablas no se modifican y sus servicios no consumen `idempotency_key` aunque el form lo enviase. Si en el futuro se quiere extender, se replica el patrón (1 columna + 1 índice + try/catch).

**Implementación del hidden field:** el form vive en el partial compartido `templates/element/payment_section.php` (lo usan Invoices, NoveltyLiquidationDocs, PettyCashRecords, Advances). Para no acoplar idempotencia a flujos fuera de alcance, el partial gana un parámetro opcional `with_idempotency_key` (default `false`). Sólo `templates/Invoices/edit.php` lo pasa como `true` al renderizar el partial; los demás callers no lo activan y siguen igual.

### D2 — W6 advances: optimistic concurrency stateless, no submit-once tokens

Se descarta `SubmitOnceTokenService` con sesión y TTL. En su lugar:

- Cada form de los 4 endpoints `advance*` emite un hidden field `expected_status` con `pipeline_status` actual.
- Helper `_ensureExpectedStatus(string $current): bool` en `AppController`: si `$_POST['expected_status'] !== $current`, flash error "La factura cambió de estado. Recargue la página antes de avanzar." y redirect.
- Cada controller ejecuta el guard antes de delegar al pipeline service.

**Razón:** stateless, soporta múltiples pestañas, distingue doble-click (mismo expected) de stale-tab (expected viejo). Un helper en lugar de un servicio nuevo.

### D3 — W13 retry: extraer primitivas, aplicar solo en `WebhookService`

Crear `RetryPolicy` (config inmutable) y `Retryer` (ejecutor con backoff exponencial). Refactorizar `WebhookService::executeWithRetry` para delegarles. Mantener el orden actual: `CircuitBreaker → Retryer → request`. **No** aplicar retry en `NotificationService` — el envío SMTP queda con un único intento; el reintento manual ya está cubierto por `email_logs` (Plan 2).

**Razón:** retry sincrónico en SMTP suma 7s al pageload por intento fallido y dispara el threshold del CB con un solo intento del usuario. Manual via UI es la opción consistente con el pivot del Plan 2 (zero infra nueva, recovery humano).

### D4 — W14 bulkhead: timeouts agresivos diferenciados, no worker async

Tres timeouts concretos:

| Operación | Timeout | Aplica en |
|-----------|---------|-----------|
| `WebhookService::sendJson` (POST JSON; n8n crosscheck) | **5s** | `Cake\Http\Client` per-request option |
| `WebhookService::sendFile` / `post()` (multipart) | **30s** | per-request option |
| SMTP socket (connect + read/write) | **10s** | `Cake\Mailer\TransportFactory::setConfig` |

**Razón:** un timeout único de 30s permite que 5 facturas atascadas consuman 5 workers FPM por 2.5 min. Diferenciar por operación reconoce que `sendFile` legítimamente tarda (uploads de PDF) mientras `sendJson` no debería pasar de unos pocos segundos. Los valores no se exponen en `system_settings` — son invariantes operativos, no preferencias.

### D5 — W7 health: 503 solo si infra core falla; auth-gated detalle

`/health` queda público (`allowUnauthenticated`) pero diferencia respuesta:

- Anónimo: `{"status": "healthy" | "degraded" | "unhealthy"}`. Suficiente para uptime monitors externos.
- Autenticado: payload completo con `checks` y `details` por componente.

Status code 503 únicamente si **DB** o **cache** fallan (críticos para que la app procese requests). CBs abiertos y `email_logs.failed > 0` se reportan como `degraded` con status 200.

**Razón:** SGI no tiene LB ni autoscaling — un 503 selectivo solo le sirve al admin que mira la página. SMTP transitorio abriendo el CB no debe sacar el nodo del pool. Exponer detalles de CB y conteos a internet anónimo es leak menor pero innecesario.

---

## Arquitectura

### Layout de archivos

```
src/Service/
├── Resilience/                          ← carpeta nueva
│   ├── RetryPolicy.php
│   └── Retryer.php
├── HealthCheck/                         ← carpeta nueva
│   ├── HealthCheckInterface.php
│   ├── HealthCheckResult.php
│   ├── HealthStatus.php
│   ├── DatabaseHealthCheck.php
│   ├── CacheHealthCheck.php
│   ├── CircuitBreakerHealthCheck.php
│   └── EmailLogHealthCheck.php
├── WebhookService.php                   ← refactor: usa Retryer, timeouts diferenciados, DI logger
├── InvoicePaymentService.php            ← registerPayment lee idempotency_key
└── Adapter/CakeMailerAdapter.php        ← timeout: 10 en config SMTP

src/Controller/
├── AppController.php                    ← + protected _ensureExpectedStatus()
├── HealthController.php                 ← reescrito: itera HealthCheckInterface, gating por auth
├── InvoicesController.php               ← advanceStatus llama _ensureExpectedStatus
├── EmployeeNoveltiesController.php      ← advance llama _ensureExpectedStatus
├── PaymentSchedulingsController.php     ← advance llama _ensureExpectedStatus
├── PettyCashRecordsController.php       ← advanceStatus llama _ensureExpectedStatus
└── InvoicePaymentsController.php        ← addPayment pasa idempotency_key al service

config/Migrations/
└── YYYYMMDDHHMMSS_AddIdempotencyKeyToInvoicePayments.php   ← prefijo lo genera `bin/cake migrations create`

templates/
├── Invoices/edit.php                              ← hidden input expected_status en form de advance
├── Invoices/(modal de pago)                       ← hidden input idempotency_key en form de payment
├── EmployeeNovelties/edit.php                     ← expected_status
├── PaymentSchedulings/edit.php                    ← expected_status
└── PettyCashRecords/edit.php                      ← expected_status
```

### Componentes

#### `Resilience/RetryPolicy`

```php
final class RetryPolicy
{
    /** @param list<class-string<\Throwable>> $retriableExceptions */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 1000,
        public readonly array $retriableExceptions = [\Exception::class],
    ) {}

    public static function default(): self;
    public static function noRetry(): self;
}
```

Inmutable, sin lógica. Backoff total con `default()`: 1s + 2s + 4s = 7s en peor caso.

#### `Resilience/Retryer`

```php
final class Retryer
{
    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly StructuredLogger $logger,
        private readonly string $context = 'retry',
    ) {}

    /** @template T @param callable():T $action @return T */
    public function run(callable $action): mixed;

    private function isRetriable(\Throwable $e): bool;
}
```

Si `$e` no está en `retriableExceptions`, re-throw inmediato (no reintenta). Si se agotan los intentos, lanza `RuntimeException` con `previous = $lastException`.

#### `HealthCheck/HealthCheckInterface`

```php
interface HealthCheckInterface
{
    public function check(): HealthCheckResult;
}

final class HealthCheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,        // HealthStatus::*
        public readonly bool $critical,        // true => 503 si falla
        public readonly array $details = [],
    ) {}
}

final class HealthStatus
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const DEGRADED = 'degraded';
}
```

#### Implementaciones de check

| Clase | `critical` | OK cuando | DEGRADED cuando | FAIL cuando |
|-------|-----------|-----------|-----------------|-------------|
| `DatabaseHealthCheck` | `true` | `SELECT 1` ok | — | excepción |
| `CacheHealthCheck` | `true` | write/read/delete round-trip ok | — | excepción o read mismatch |
| `CircuitBreakerHealthCheck` | `false` | webhook+smtp closed/half-open | algún CB open | (no aplica) |
| `EmailLogHealthCheck` | `false` | `failed_count == 0` | `failed_count > 0` | excepción al consultar |

#### `HealthController` (reescrito)

```php
public function index(): Response
{
    $results = array_map(
        fn(string $cls) => $this->getContainer()->get($cls)->check(),
        [DatabaseHealthCheck::class, CacheHealthCheck::class,
         CircuitBreakerHealthCheck::class, EmailLogHealthCheck::class],
    );

    $criticalFailed = (bool)array_filter($results,
        fn(HealthCheckResult $r) => $r->critical && $r->status === HealthStatus::FAIL);

    $globalStatus = $criticalFailed
        ? 'unhealthy'
        : (array_filter($results, fn($r) => $r->status === HealthStatus::DEGRADED)
            ? 'degraded' : 'healthy');

    $isAuthed = $this->Authentication->getIdentity() !== null;
    $body = $isAuthed
        ? ['status' => $globalStatus, 'checks' => $this->_serialize($results)]
        : ['status' => $globalStatus];

    return $this->response->withStatus($criticalFailed ? 503 : 200)
        ->withType('application/json')
        ->withStringBody((string)json_encode($body));
}
```

### Migraciones

#### `YYYYMMDDHHMMSS_AddIdempotencyKeyToInvoicePayments`

```php
public function up(): void
{
    $table = $this->table('invoice_payments');
    if (!$this->_columnExists('invoice_payments', 'idempotency_key')) {
        $table->addColumn('idempotency_key', 'string', ['limit' => 64, 'null' => true]);
    }
    if (!$this->_indexExists('invoice_payments', 'uq_invoice_payments_idempotency_key')) {
        $table->addIndex(['idempotency_key'], [
            'unique' => true,
            'name' => 'uq_invoice_payments_idempotency_key',
        ]);
    }
    $table->update();
}

public function down(): void
{
    $table = $this->table('invoice_payments');
    if ($this->_indexExists('invoice_payments', 'uq_invoice_payments_idempotency_key')) {
        $table->removeIndexByName('uq_invoice_payments_idempotency_key');
    }
    if ($this->_columnExists('invoice_payments', 'idempotency_key')) {
        $table->removeColumn('idempotency_key');
    }
    $table->update();
}
```

(`_columnExists`/`_indexExists` son helpers privados con `SHOW COLUMNS / SHOW INDEX` o equivalente — siguen el patrón de migraciones recientes que usan `hasTable()` para idempotencia.)

### Registro DI (`Application::services()`)

```php
// === Resilience primitives ===
$container->addShared(RetryPolicy::class, fn() => RetryPolicy::default());
$container->addShared(Retryer::class)
    ->addArguments([RetryPolicy::class, StructuredLogger::class]);

// === Webhook (refactor) ===
$container->addShared(WebhookService::class)
    ->addArgument(StructuredLogger::class);

// === Health checks ===
$container->addShared(DatabaseHealthCheck::class);
$container->addShared(CacheHealthCheck::class);
$container->addShared(CircuitBreakerHealthCheck::class);
$container->addShared(EmailLogHealthCheck::class);
```

`NotificationService`, `CakeMailerAdapter`, `InvoicePaymentService` no cambian su firma de constructor — sólo su comportamiento interno.

---

## Cambios por archivo (resumen)

### Nuevos
- `src/Service/Resilience/RetryPolicy.php`
- `src/Service/Resilience/Retryer.php`
- `src/Service/HealthCheck/HealthCheckInterface.php`
- `src/Service/HealthCheck/HealthCheckResult.php`
- `src/Service/HealthCheck/HealthStatus.php`
- `src/Service/HealthCheck/DatabaseHealthCheck.php`
- `src/Service/HealthCheck/CacheHealthCheck.php`
- `src/Service/HealthCheck/CircuitBreakerHealthCheck.php`
- `src/Service/HealthCheck/EmailLogHealthCheck.php`
- `config/Migrations/YYYYMMDDHHMMSS_AddIdempotencyKeyToInvoicePayments.php`

### Modificados
- `src/Service/WebhookService.php` — usa `Retryer`, timeouts per-request, DI de `StructuredLogger`.
- `src/Service/Adapter/CakeMailerAdapter.php` — `timeout: 10` en config SMTP.
- `src/Service/InvoicePaymentService.php` — `registerPayment` lee/genera `idempotency_key`, atrapa duplicado.
- `src/Controller/AppController.php` — método protegido `_ensureExpectedStatus()`.
- `src/Controller/HealthController.php` — reescrito (itera checks, gating auth).
- `src/Controller/InvoicesController.php` — `advanceStatus` consume `expected_status`.
- `src/Controller/EmployeeNoveltiesController.php` — `advance` idem.
- `src/Controller/PaymentSchedulingsController.php` — `advance` idem.
- `src/Controller/PettyCashRecordsController.php` — `advanceStatus` idem.
- `src/Controller/InvoicePaymentsController.php` — `addPayment` pasa `idempotency_key` al service.
- `src/Application.php` — registros DI nuevos.
- 5 templates de form: parámetro `with_idempotency_key` consumido por `templates/Invoices/edit.php` cuando renderiza `templates/element/payment_section.php`; hidden `expected_status` en los edit forms de Invoices, EmployeeNovelties, PaymentSchedulings, PettyCashRecords.
- 1 element compartido (`templates/element/payment_section.php`) gana parámetro opcional `with_idempotency_key` que renderiza el hidden cuando es true.

---

## Orden de implementación

| # | Cambio | Bloquea a |
|---|--------|-----------|
| 1 | Migración `idempotency_key` en `invoice_payments` | 5 |
| 2 | `Resilience/RetryPolicy.php` + `Resilience/Retryer.php` | 3 |
| 3 | Refactor `WebhookService` (Retryer, timeouts diferenciados, DI logger) | — |
| 4 | `CakeMailerAdapter` timeout 10s | — |
| 5 | `InvoicePaymentService::registerPayment` + form modificado | — |
| 6 | Helper `_ensureExpectedStatus` + 4 controllers + 4 forms | — |
| 7 | `HealthCheck/*` + `HealthController` reescrito + DI | — |

Pasos 3–7 son independientes; 5 sólo depende de 1.

---

## Criterios de validación manual

(Conforme `feedback_no_tests.md`: el proyecto **no** usa tests automatizados. Validación con `php bin/cake server` + browser/curl/MySQL.)

### V1 — `registerPayment` idempotency
1. Login Tesorería → factura en estado `tesoreria`.
2. Abrir modal "Registrar pago" → llenar monto y fecha.
3. Click rápido **dos veces** en el botón submit.
4. **Esperado:** una sola fila nueva en `invoice_payments`. Segundo click muestra flash "Pago ya registrado (operación repetida)". Factura avanza a `autorizacion_pago` una sola vez.
5. **Verificar SQL:** `SELECT idempotency_key, COUNT(*) FROM invoice_payments WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1` → 0 filas.

### V2 — advances (optimistic concurrency)
Repetir para cada uno de Invoices, Novelties, PaymentSchedulings, PettyCashRecords:
1. Cargar `edit` del registro (deja form con `expected_status` actual renderizado).
2. En otra pestaña/usuario, avanzar el mismo registro al siguiente estado.
3. Volver a la primera pestaña → click "Avanzar".
4. **Esperado:** flash "El estado cambió, recargue la página antes de avanzar." Sin avance.

### V3 — Retry extraído + filtro 4xx
1. En `system_settings`, apuntar `n8n_dian_webhook_url` a `https://httpbin.org/status/500`.
2. Disparar un crosscheck.
3. **Esperado en logs:** `[webhook] retry #1 after 1000ms`, `#2 after 2000ms`, `#3 after 4000ms`. Después error final.
4. Cambiar URL a `https://httpbin.org/status/404`. Disparar crosscheck.
5. **Esperado:** falla en el primer intento, **sin** reintentos (4xx no es retriable).

### V4 — Timeout HTTP JSON
1. Apuntar webhook a `https://httpbin.org/delay/10`.
2. Disparar crosscheck. Cronometrar.
3. **Esperado:** falla en ≈5s, no 10s. Mensaje de timeout.

### V5 — Timeout SMTP
1. En `system_settings`, poner `smtp_host = 1.2.3.4` (IP no enrutable).
2. Acción que dispara approval link (p.ej. `Invoices::sendApprovalLinks`).
3. Cronometrar.
4. **Esperado:** falla en ≈10s. Fila nueva en `email_logs` con `status = failed` y `last_error` con mensaje de socket/connect.

### V6 — `/health` mínimo público
```
curl -i http://localhost:8765/health
```
**Esperado:** `200 OK` + `{"status":"healthy"}` (o `degraded` si algún CB está abierto).

### V7 — `/health` detallado autenticado
1. Login admin en browser.
2. Abrir `/health`.
3. **Esperado:** JSON con `status`, `checks.database.status=ok`, `checks.cache.status=ok`, `checks.circuit_breakers.details={webhook, smtp}`, `checks.email_logs_failed.details.failed_count`.

### V8 — `/health` 503 con DB caída
1. `sudo systemctl stop mariadb` (o equivalente).
2. `curl -i http://localhost:8765/health`.
3. **Esperado:** `503` + `{"status":"unhealthy"}` (anónimo) o JSON con `checks.database.status=fail` (autenticado, si la sesión sobrevive sin DB — más realista: probar con `curl` sin cookie).
4. Restaurar DB.

### V9 — `/health` 503 con cache caída
1. `chmod 000 tmp/cache/`.
2. `curl -i http://localhost:8765/health` → **Esperado:** `503` con `cache: fail` (autenticado).
3. `chmod 755 tmp/cache/`.

### V10 — `/health` degraded con CB abierto
1. Forzar CB `webhook` abierto: 3 webhooks fallidos consecutivos (V3 cumple).
2. Login admin → `/health`.
3. **Esperado:** status `degraded`, `200`, `circuit_breakers.details.webhook = "open"`.

---

## Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|-----------|
| Timeout SMTP de 10s rompe envíos legítimos a SMTPs lentos | Si pasa, subir constante en `CakeMailerAdapter::SMTP_TIMEOUT_SECONDS` y redeployar. Detectable vía `email_logs.failed` con mensajes de timeout. |
| Filas históricas de `invoice_payments` sin `idempotency_key` | NULL permitido; índice unique acepta múltiples NULL. No hay backfill. |
| Hidden field `expected_status` puede ser manipulado por usuario malicioso | Es un guard contra UI bugs (doble click, stale tab), no contra ataques. La autorización de la transición sigue siendo del pipeline service. Riesgo nulo. |
| Helper `_ensureExpectedStatus` en `AppController` aumenta superficie de la base class | Una sola función protegida sin estado. Aceptable. |
| Cambio de orden de retry / no-retry en SMTP altera comportamiento observable | El comportamiento previo de `NotificationService` no tenía retry. Se mantiene. Único cambio: timeout 10s vs 30s. |
| `HealthController` resolver checks vía `getContainer()` (service locator) | Aceptado para 1 controller con 4 deps muy estables. Alternativa (HealthCheckRegistry inyectado) deja para refactor futuro si crece la lista. |

---

## Fuera de alcance (explícitamente)

- Tabla genérica `idempotency_keys` (descartada — D1).
- `SubmitOnceTokenService` con sesión y TTL (descartada — D2).
- Retry sincrónico en envío SMTP (descartada — D3).
- Worker async para bulkhead (descartada por pivot Plan 2 — D4).
- Configuración de timeouts en `system_settings` (descartada — D4).
- Métricas Prometheus / OpenTelemetry — corresponde a Plan 7 (W1, W12 etc).
- ADRs documentando estas decisiones — corresponde a Plan 7.
- Refactor de `NotificationService` para inyectar `Retryer` aunque hoy no lo use — `Retryer` queda registrado en DI para que cualquier integración futura lo use, pero `NotificationService` no recibe la dependencia.

---

## Referencias

- Roadmap maestro: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md)
- Auditoría origen: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
- Plan 2 (email_logs base): [`docs/superpowers/specs/2026-05-01-email-log-design.md`](./2026-05-01-email-log-design.md)
- Plan 3 (DI container): [`docs/superpowers/specs/2026-05-01-di-container-design.md`](./2026-05-01-di-container-design.md)
- CakePHP HTTP Client timeout: https://book.cakephp.org/5/en/core-libraries/httpclient.html
- CakePHP SMTP transport timeout: https://book.cakephp.org/5/en/core-libraries/email.html#smtp
