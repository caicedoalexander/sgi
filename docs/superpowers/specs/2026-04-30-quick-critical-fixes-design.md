# Plan 1 — Quick Critical Fixes (C1 + C2 + C3)

**Plan del roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · **Plan #1**
**Auditoría origen:** [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md)
**Fecha:** 2026-04-30
**Tamaño estimado:** 2–4 días

---

## Resumen

Tres fixes independientes empaquetados en un único PR de estabilización. Cada fix se puede revertir individualmente.

| Item | Archivo principal | Naturaleza |
|---|---|---|
| C1 | `src/Service/InvoicePaymentService.php` | Bug de consistencia (transacción faltante) |
| C2 | `src/Middleware/RateLimitMiddleware.php` + nueva tabla DB | Bug de seguridad/concurrencia |
| C3 | `src/Service/NotificationService.php` + `src/Service/Adapter/CakeMailerAdapter.php` | Refactor (puerto hexagonal puenteado) |

**Regla del proyecto aplicada:** sin tests automatizados. La validación es manual (ver sección final).

---

## Orden de implementación dentro del PR

Criterio: bajo a alto riesgo.

1. **C3** — refactor `NotificationService` + fix SSL en `CakeMailerAdapter`. Toca dos archivos, sin DB.
2. **C1** — wrap `authorizePayment()` en `transactional()`. Una sola función, comportamiento equivalente en happy path.
3. **C2** — nueva tabla, migración, refactor del middleware, nueva entrada en `routes.php` para `/login`.

---

## C1 — Transacción atómica en `authorizePayment()`

### Problema
`InvoicePaymentService::authorizePayment()` (líneas 108–162) hace 4 escrituras + 2 side effects sin transacción. Si cualquier paso intermedio falla, la base de datos queda inconsistente. `registerPayment()` y `editPayment()` en el mismo archivo sí usan `Connection::transactional()`.

### Decisión
Envolver **todo** el cuerpo del método (pasos 1–6) en `Connection::transactional()`. Sin diferir side effects post-commit (eso será trabajo del Plan 2 — Outbox).

### Implementación

```php
public function authorizePayment(int $paymentId, int $authorizedBy): array
{
    $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
    $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
    $connection = $paymentsTable->getConnection();

    $result = $connection->transactional(function () use (
        $paymentsTable, $invoicesTable, $paymentId, $authorizedBy
    ) {
        $payment = $paymentsTable->get($paymentId);
        $payment->authorized = true;
        $payment->status = InvoiceConstants::PAYMENT_RECORD_AUTHORIZED;
        $payment->authorized_by = $authorizedBy;
        $payment->authorized_date = date('Y-m-d');

        if (!$paymentsTable->save($payment)) {
            return false; // → rollback
        }

        $this->recalculatePaymentStatus($payment->invoice_id);

        $invoice = $invoicesTable->get($payment->invoice_id);
        $previousStatus = $invoice->pipeline_status;

        $newPipelineStatus = $invoice->payment_status === InvoiceConstants::PAYMENT_FULL
            ? InvoiceConstants::STATUS_PAGADA
            : InvoiceConstants::STATUS_TESORERIA;

        $invoice->pipeline_status = $newPipelineStatus;
        $invoicesTable->save($invoice);

        $this->historyService->recordStatusChange(
            $invoice->id,
            $previousStatus,
            $newPipelineStatus,
            $authorizedBy,
        );

        if ((bool)($payment->is_refund ?? false)) {
            $this->advanceLegalizationService->closeOnRefundAuthorized($payment->id, $authorizedBy);
        }

        if (
            $invoice->pipeline_status === InvoiceConstants::STATUS_PAGADA
            && ($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_ANTICIPO
        ) {
            $this->advanceLegalizationService->initialize($invoice, $authorizedBy);
        }

        return [
            'success' => true,
            'paymentStatus' => $invoice->payment_status,
            'newPipelineStatus' => $newPipelineStatus,
        ];
    });

    return $result === false
        ? ['success' => false, 'paymentStatus' => null, 'newPipelineStatus' => null]
        : $result;
}
```

### Detalles clave
- `Connection::transactional()` hace rollback si el callable devuelve `false` literal o lanza excepción. Cualquier otro valor → commit.
- CakePHP soporta `transactional()` anidado vía savepoints, así que las transacciones internas de `closeOnRefundAuthorized` e `initialize` no causan conflicto.
- Firma de retorno (`array`) sin cambios — la consolidación a `ServiceResult` se difiere al Plan 7 (W15).

### Riesgos
- Cambio de comportamiento sutil: si `save()` paso 2 ó 3 falla, hoy quedan los pasos previos persistidos; tras el cambio, todo se revierte. Es exactamente el comportamiento deseado, pero documentar en el mensaje de PR.

---

## C2 — Rate limiter atómico con tabla MySQL

### Problema
`RateLimitMiddleware` (líneas 39–50) tiene tres bugs:

1. **Race condition** en read-modify-write sobre `Cache::read()` / `Cache::write()` — dos requests simultáneas con `count = max-1` ambas pasan.
2. **TTL reset** — `Cache::write()` con la misma key reinicia el tiempo de expiración; la ventana nunca se cierra bajo tráfico continuo.
3. **No respeta `X-Forwarded-For`** — detrás de nginx (stack actual) `REMOTE_ADDR` siempre es la IP del proxy, todos los usuarios comparten límite.

Adicionalmente, **el middleware solo está aplicado a `/approve/*`** (`config/routes.php:101–106`). `/login` queda sin protección.

> **Corrección al audit:** el audit dijo "no está registrado en `Application::middleware()`", lo cual es literalmente cierto pero engañoso — sí está aplicado vía route-scope middleware.

### Decisión
- **Atomicidad:** tabla `rate_limit_buckets` con `INSERT ... ON DUPLICATE KEY UPDATE`. Sin Redis (no está en el stack).
- **Ventana fija:** cada combinación `(ip, path, windowFloor)` es una fila distinta. Sin lógica condicional de reset.
- **Trusted proxies:** configurable vía `Security.trustedProxies` (CSV de CIDRs en `.env`). Default vacío.
- **Garbage collection:** probabilístico in-line (1/100 requests llama al GC). Sin cron.
- **Cobertura:** mantener `/approve/*` actual (10/60s) + agregar `/login` (5/300s).
- **Header `Retry-After`** en todas las respuestas 429.

### Esquema DB (`rate_limit_buckets`)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `bucket_key` | VARCHAR(64), UNIQUE | `sha256(ip + '\|' + path + '\|' + windowStart)` |
| `window_start` | DATETIME | Inicio de la ventana actual |
| `count` | INT UNSIGNED | Contador acumulado |
| `created` | DATETIME | |
| `modified` | DATETIME | |

Índices: `UNIQUE(bucket_key)`, `INDEX(window_start)` para GC.

Migración: `Add{Timestamp}RateLimitBucketsTable` extends `Migrations\BaseMigration`. Usa `$this->hasTable()` para idempotencia.

### Modelos

`src/Model/Entity/RateLimitBucket.php` — entidad estándar.

`src/Model/Table/RateLimitBucketsTable.php`:

```php
public function incrementAndGet(string $bucketKey, int $windowStart): int
{
    $connection = $this->getConnection();
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $windowDt = (new DateTime("@{$windowStart}"))->format('Y-m-d H:i:s');

    $connection->execute(
        'INSERT INTO rate_limit_buckets (bucket_key, window_start, count, created, modified)
         VALUES (?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE count = count + 1, modified = ?',
        [$bucketKey, $windowDt, $now, $now, $now]
    );

    $stmt = $connection->execute(
        'SELECT count FROM rate_limit_buckets WHERE bucket_key = ?',
        [$bucketKey]
    );

    return (int)$stmt->fetchColumn(0);
}

public function garbageCollect(int $olderThanSeconds): int
{
    $cutoff = (new DateTime())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');
    return $this->deleteAll(['window_start <' => $cutoff]);
}
```

### Middleware refactorizado

```php
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly int $maxRequests = 10,
        private readonly int $windowSeconds = 60,
        private readonly ?RateLimitBucketsTable $buckets = null,
        private readonly ?array $trustedProxies = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
        $path = $request->getUri()->getPath();
        $windowStart = (int)floor(time() / $this->windowSeconds) * $this->windowSeconds;
        $key = hash('sha256', $ip . '|' . $path . '|' . $windowStart);

        $buckets = $this->buckets
            ?? TableRegistry::getTableLocator()->get('RateLimitBuckets');

        $count = $buckets->incrementAndGet($key, $windowStart);

        if ($count > $this->maxRequests) {
            $retryAfter = max(1, $this->windowSeconds - (time() - $windowStart));
            return (new Response())
                ->withStatus(429)
                ->withType('application/json')
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withStringBody((string)json_encode(['error' => 'Too many requests']));
        }

        if (random_int(1, 100) === 1) {
            $buckets->garbageCollect($this->windowSeconds * 5);
        }

        return $handler->handle($request);
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $trustedProxies = $this->trustedProxies
            ?? $this->parseTrustedProxies(Configure::read('Security.trustedProxies', ''));

        if (!$this->ipInRanges($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff === '') {
            return $remoteAddr;
        }

        $first = trim(explode(',', $xff)[0]);
        return $first !== '' ? $first : $remoteAddr;
    }

    /** @return list<string> */
    private function parseTrustedProxies(string $csv): array
    {
        if ($csv === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /** @param list<string> $ranges CIDR notation list */
    private function ipInRanges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false; // IPv6 not supported in this helper; out of scope
        }
        $mask = -1 << (32 - (int)$bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
```

> **Nota IPv6:** `ip2long` solo soporta IPv4. Para IPv6 detrás de proxy se requiere `inet_pton` y comparación binaria — fuera de scope para Plan 1, dado que los proxies actuales (nginx en docker) usan IPv4 interno. Documentado como limitación conocida.

### Routing

`config/routes.php`:

```php
return function (RouteBuilder $routes): void {
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->registerMiddleware('rateLimit', new RateLimitMiddleware(10, 60));
        $builder->registerMiddleware('rateLimitLogin', new RateLimitMiddleware(5, 300));

        $builder->connect('/', ['controller' => 'Dashboard', 'action' => 'index']);

        // /login con su propio rate limit
        $builder->scope('/login', function (RouteBuilder $loginBuilder): void {
            $loginBuilder->applyMiddleware('rateLimitLogin');
            $loginBuilder->connect('', ['controller' => 'Users', 'action' => 'login']);
        });

        $builder->connect('/logout', ['controller' => 'Users', 'action' => 'logout']);
        // ... resto sin cambios ...

        // /approve/* mantiene scope existente con 'rateLimit' (10/60s)
        $builder->scope('/approve', function (RouteBuilder $approveBuilder): void {
            $approveBuilder->applyMiddleware('rateLimit');
            // ... routes existentes ...
        });
    });
};
```

> El sub-scope `'/login'` con `connect('', ...)` mapea exactamente a `/login` (URL idéntica al actual).

### Configuración

`config/app.php`, sección `'Security'` (crear si no existe):

```php
'Security' => [
    'trustedProxies' => env('TRUSTED_PROXIES', ''),
],
```

`.env.example` (crear si no existe) o `.env`:

```
# Comma-separated CIDRs of trusted reverse proxies (for X-Forwarded-For honoring)
# Example: TRUSTED_PROXIES=172.16.0.0/12,10.0.0.0/8
TRUSTED_PROXIES=
```

---

## C3 — `NotificationService` consume `MailerInterface`

### Problema
`MailerInterface` y `CakeMailerAdapter` existen pero `NotificationService` los ignora — instancia `Cake\Mailer\Mailer` directamente y duplica `configureTransport()`. Adicionalmente, **el adapter tiene un bug**: hardcodea `'tls' => true` y no maneja SSL (Office365 con puerto 465 fallaría).

### Decisión
- Arreglar el bug SSL en el adapter primero.
- Inyectar `MailerInterface` en `NotificationService`.
- `CircuitBreaker` permanece en `NotificationService` (es resiliencia, no transporte).
- Cache de transporte (`$transportConfigured`) se mantiene — los settings no cambian mid-request en la práctica.
- `testSmtpConnection()` también pasa por el adapter (un template `smtp_test` mínimo); así toda la lógica SMTP queda en un solo lugar.

### Cambios en `CakeMailerAdapter`

`_ensureTransport()` reemplaza la lógica hardcodeada por la equivalente de `NotificationService::configureTransport()` actual:

```php
private function _ensureTransport(array $smtpConfig): void
{
    if ($this->transportConfigured) {
        return;
    }

    $config = [
        'className' => 'Smtp',
        'host' => $smtpConfig['smtp_host'] ?? '',
        'port' => (int)($smtpConfig['smtp_port'] ?? 587),
        'username' => $smtpConfig['smtp_username'] ?? '',
        'password' => $smtpConfig['smtp_password'] ?? '',
    ];

    $encryption = $smtpConfig['smtp_encryption'] ?? '';
    if ($encryption === 'tls') {
        $config['tls'] = true;
    } elseif ($encryption === 'ssl') {
        $config['host'] = 'ssl://' . ($smtpConfig['smtp_host'] ?? '');
        $config['port'] = (int)($smtpConfig['smtp_port'] ?? 465);
        $config['tls'] = false;
    }

    if (TransportFactory::getConfig('sgi_dynamic')) {
        TransportFactory::drop('sgi_dynamic');
    }
    TransportFactory::setConfig('sgi_dynamic', $config);

    $this->transportConfigured = true;
}
```

### Cambios en `NotificationService`

Constructor:

```php
public function __construct(
    ?SystemSettingsService $settings = null,
    ?MailerInterface $mailer = null,
) {
    $this->settings = $settings ?? new SystemSettingsService();
    $this->mailer = $mailer ?? new CakeMailerAdapter($this->settings);
    $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
}
```

`sendApprovalLinkNotification()`:

```php
foreach ($recipients as $recipient) {
    if (empty($recipient->email)) {
        throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
    }

    $viewVars = [
        'invoiceNumber' => $invoiceNumber,
        'providerName' => $invoice->provider->name ?? '—',
        'amount' => $invoice->amount,
        'approvalUrl' => $approvalUrl,
        'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
    ];

    $subject = "SGI-COPCSA - Solicitud de Aprobación: Factura {$invoiceNumber}";

    $this->smtpCircuitBreaker->call(function () use ($recipient, $subject, $viewVars): void {
        $this->mailer->send($recipient->email, $subject, 'invoice_approval_request', $viewVars);
    });

    Log::info("Approval link sent to {$recipient->email} for invoice #{$invoice->id}");
}
```

`sendNoveltyApprovalEmail()` análogo: construye `$viewVars`, pasa por `$this->smtpCircuitBreaker->call(fn => $this->mailer->send(...))`.

`testSmtpConnection()`:

```php
public function testSmtpConnection(): array
{
    $smtpConfig = $this->settings->getGroup('smtp');

    if (empty($smtpConfig['smtp_host'])) {
        return ['success' => false, 'message' => 'Host SMTP no configurado.'];
    }

    $fromEmail = $smtpConfig['smtp_from_email'] ?? 'test@test.com';

    try {
        $this->mailer->send(
            $fromEmail,
            'SGI - Prueba de conexión SMTP',
            'smtp_test',
            [],
        );

        return ['success' => true, 'message' => 'Conexión SMTP exitosa. Correo de prueba enviado.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
```

Eliminar el método `configureTransport()` privado de `NotificationService`.

### Nuevo template

`templates/email/html/smtp_test.php` — una línea:

```php
<p>Este es un correo de prueba del SGI.</p>
```

---

## Archivos afectados

**Modificados:**
- `src/Service/NotificationService.php`
- `src/Service/Adapter/CakeMailerAdapter.php`
- `src/Service/InvoicePaymentService.php`
- `src/Middleware/RateLimitMiddleware.php`
- `config/routes.php`
- `config/app.php` (nueva sección `Security.trustedProxies`)
- `.env.example` (si existe; si no, omitir o crear una nota)

**Nuevos:**
- `config/Migrations/{Timestamp}AddRateLimitBucketsTable.php`
- `src/Model/Entity/RateLimitBucket.php`
- `src/Model/Table/RateLimitBucketsTable.php`
- `templates/email/html/smtp_test.php`

---

## Validación manual (en lugar de tests automatizados)

Pasos a ejecutar tras el merge para confirmar que el plan funcionó.

### C1 — Transacción en `authorizePayment`
1. Como Contador, autorizar un pago real desde la UI.
2. En MySQL, verificar:
   - `invoice_payments.status = 'authorized'`
   - `invoices.pipeline_status` avanzó (a `pagada` o `tesoreria` según monto)
   - `invoice_histories` tiene una fila nueva
3. Para verificar el rollback: comentar temporalmente la implementación de `AdvanceLegalizationService::initialize` y forzar `throw new Exception('test')`. Autorizar un pago de un Anticipo que quede totalmente pagado (debe disparar `initialize`). Verificar que ningún cambio quedó persistido en la BD. Restaurar el código.

### C2 — Rate limiter
1. **`/login`:** abrir el navegador y hacer 6 intentos de login fallido seguidos. La 6ª request debe responder HTTP 429 con header `Retry-After`.
2. **`/approve/{token}`:** desde terminal:
   ```
   for i in $(seq 1 11); do
     curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8765/approve/<token-real>"
   done
   ```
   La 11ª debe ser `429`.
3. Esperar la ventana correspondiente (5 min para login, 1 min para approve) → siguiente intento permitido.
4. Inspeccionar `rate_limit_buckets`: filas creándose, `count` incrementando dentro de la ventana, GC limpia tras ~5 ventanas (puede tardar; hacer ~100 requests para forzar el 1/100).
5. **Trusted proxies:** configurar `TRUSTED_PROXIES=172.16.0.0/12` (rango Docker) en `.env`, reiniciar php-fpm. Probar:
   ```
   curl -H "X-Forwarded-For: 1.2.3.4" http://localhost:8765/login
   ```
   Verificar en `rate_limit_buckets` que la `bucket_key` se calculó usando la IP `1.2.3.4`.
6. Sin `TRUSTED_PROXIES` configurado, repetir lo anterior — la `bucket_key` debe usar `REMOTE_ADDR` (la IP del proxy/cliente directo).

### C3 — `NotificationService` + `MailerInterface`
1. Asignar aprobador a una factura (regresión funcional). El correo de aprobación debe llegar al destinatario.
2. En Ajustes del Sistema, probar las 3 variantes de SMTP:
   - **TLS (puerto 587):** debe enviar.
   - **SSL (puerto 465):** debe enviar (cubre el bug fix del adapter — antes fallaba).
   - **Sin encriptación:** debe enviar (servidor de pruebas local).
3. Click en "Probar SMTP" en Ajustes → debe llegar el correo de prueba con el cuerpo del template `smtp_test`.
4. Apagar el SMTP a propósito (host inválido en settings). Forzar 3+ intentos de envío. Verificar en logs que el `CircuitBreaker` abrió tras los 3 fallos.

---

## Definición de "hecho"

Este plan se considera completado cuando:

- Los 3 fixes están mergeados a `main`.
- Los 6 pasos de validación manual de cada C ejecutados con éxito en un entorno de prueba.
- La migración `AddRateLimitBucketsTable` aplicada en producción sin errores.
- Un breve comentario en el roadmap (`docs/audits/architecture-audit-roadmap.md`) actualizando el estado del Plan 1 a 🟢 Completado con fecha y link al PR.

---

## Fuera de scope (explícito)

- Tests automatizados (regla del proyecto).
- Migrar el patrón `?? new X()` (W3) — Plan 3.
- Estandarizar `ServiceResult` en `authorizePayment` — Plan 7 (W15).
- Endpoint `/health` (W7) — Plan 6 (existe ya como ruta vacía).
- Outbox para diferir side effects de `authorizePayment` (W8) — Plan 2.
- IPv6 en CIDR matching del rate limiter — limitación conocida documentada.
- Refactor de `sendApprovalLinks` o `sendNoveltyApprovalEmail` para usar Outbox — Plan 2.
