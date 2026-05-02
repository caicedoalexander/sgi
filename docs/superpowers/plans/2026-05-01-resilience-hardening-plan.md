# Plan 6 — Resilience Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Endurecer SGI contra doble click en mutaciones, llamadas externas lentas, y darle al admin/uptime monitor un endpoint `/health` accionable.

**Architecture:** Cuatro piezas independientes que se conectan en `/health`. (1) Idempotencia inline en `invoice_payments` + optimistic concurrency stateless en advances. (2) `Retryer`/`RetryPolicy` reusables aplicados sólo en `WebhookService`. (3) Timeouts diferenciados (HTTP JSON 5s / file 30s / SMTP 10s). (4) `HealthCheck/*` polimórfico iterado por `HealthController` con gating por auth.

**Tech Stack:** PHP 8.2+, CakePHP 5.3, MariaDB/MySQL, FileEngine cache, League Container DI.

**Política del proyecto (recordatorio):** este proyecto NO usa tests automatizados (ver `CLAUDE.md` § Testing Policy y memoria `feedback_no_tests.md`). Cada tarea termina en validación manual descrita en el spec — los pasos del usuario sobre `php bin/cake server` quedan documentados pero no se ejecutan desde aquí. La revisión de calidad se corre **una sola vez** al final del plan, no por tarea.

**Spec:** `docs/superpowers/specs/2026-05-01-resilience-hardening-design.md`

---

## File Structure

### Nuevos
- `src/Service/Resilience/RetryPolicy.php` — value object inmutable de configuración.
- `src/Service/Resilience/Retryer.php` — ejecutor con backoff exponencial + filtro retriable.
- `src/Service/HealthCheck/HealthStatus.php` — constantes `OK`/`FAIL`/`DEGRADED`.
- `src/Service/HealthCheck/HealthCheckResult.php` — DTO inmutable.
- `src/Service/HealthCheck/HealthCheckInterface.php` — contrato `check(): HealthCheckResult`.
- `src/Service/HealthCheck/DatabaseHealthCheck.php` — `SELECT 1`.
- `src/Service/HealthCheck/CacheHealthCheck.php` — write/read/delete round-trip.
- `src/Service/HealthCheck/CircuitBreakerHealthCheck.php` — lee estado de webhook + smtp CB.
- `src/Service/HealthCheck/EmailLogHealthCheck.php` — `COUNT(*) WHERE status='failed'`.
- `config/Migrations/<TIMESTAMP>_AddIdempotencyKeyToInvoicePayments.php` — columna + UNIQUE index.

### Modificados
- `src/Service/WebhookService.php` — usa `Retryer`, timeouts per-request, sin retry hardcodeado.
- `src/Service/Adapter/CakeMailerAdapter.php` — `timeout: 10` en config SMTP.
- `src/Service/InvoicePaymentService.php` — `registerPayment` lee/genera `idempotency_key`, atrapa `PDOException` de duplicado.
- `src/Controller/AppController.php` — método protegido `_ensureExpectedStatus()`.
- `src/Controller/HealthController.php` — reescrito con iteración de checks + gating por auth.
- `src/Controller/InvoicesController.php` — `advanceStatus` usa `_ensureExpectedStatus`.
- `src/Controller/EmployeeNoveltiesController.php` — `advance` idem.
- `src/Controller/PaymentSchedulingsController.php` — `advance` idem.
- `src/Controller/PettyCashRecordsController.php` — `advanceStatus` idem.
- `src/Controller/InvoicePaymentsController.php` — `addPayment` lee `idempotency_key` del request.
- `src/Application.php` — DI: `WebhookService` recibe nada nuevo; registrar 4 health checks.
- `templates/element/payment_section.php` — parámetro opcional `with_idempotency_key`.
- `webroot/js/sgi-payment.js` — incluye `idempotency_key` en el form si está en el dataset.
- `templates/Invoices/edit.php` — pasa `'with_idempotency_key' => true` al element + hidden `expected_status` en form de `advanceStatus`.
- `templates/EmployeeNovelties/edit.php` — hidden `expected_status` en forms de `advance`.
- `templates/PaymentSchedulings/edit.php` — hidden `expected_status` en form de `advance`.
- `templates/PettyCashRecords/edit.php` — hidden `expected_status` en form de `advanceStatus`.

---

## Task 1: Migración — `idempotency_key` en `invoice_payments`

**Files:**
- Create: `config/Migrations/<TIMESTAMP>_AddIdempotencyKeyToInvoicePayments.php`

- [ ] **Step 1: Generar el archivo de migración**

```bash
php bin/cake migrations create AddIdempotencyKeyToInvoicePayments
```

Esto crea un archivo con timestamp en `config/Migrations/`. Anotar el path generado.

- [ ] **Step 2: Reemplazar el contenido del archivo recién creado por:**

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddIdempotencyKeyToInvoicePayments extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('invoice_payments');

        if (!$table->hasColumn('idempotency_key')) {
            $table->addColumn('idempotency_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
            ]);
        }

        if (!$table->hasIndex(['idempotency_key'])) {
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

        if ($table->hasIndex(['idempotency_key'])) {
            $table->removeIndexByName('uq_invoice_payments_idempotency_key');
        }

        if ($table->hasColumn('idempotency_key')) {
            $table->removeColumn('idempotency_key');
        }

        $table->update();
    }
}
```

- [ ] **Step 3: Correr la migración**

```bash
php bin/cake migrations migrate
```

Esperado: salida con `== <Number> AddIdempotencyKeyToInvoicePayments: migrating` y luego `migrated` sin errores.

- [ ] **Step 4: Verificar en la DB**

```bash
mysql -u <user> -p sgi_db -e "SHOW COLUMNS FROM invoice_payments LIKE 'idempotency_key'; SHOW INDEX FROM invoice_payments WHERE Key_name='uq_invoice_payments_idempotency_key';"
```

Esperado: una fila por columna y otra por índice (Non_unique=0).

- [ ] **Step 5: Commit**

```bash
git add config/Migrations/*_AddIdempotencyKeyToInvoicePayments.php
git commit -m "feat(plan-6): migración idempotency_key en invoice_payments (W6)"
```

---

## Task 2: `Resilience/RetryPolicy` + `Resilience/Retryer`

**Files:**
- Create: `src/Service/Resilience/RetryPolicy.php`
- Create: `src/Service/Resilience/Retryer.php`

- [ ] **Step 1: Crear `src/Service/Resilience/RetryPolicy.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use Throwable;

/**
 * Configuración inmutable para reintentos. Define cuántas veces reintentar,
 * el delay base en ms (backoff exponencial: base * 2^attempt) y qué
 * excepciones son retriables.
 */
final class RetryPolicy
{
    /**
     * @param int $maxAttempts Cantidad de reintentos tras el primer intento (0 = sin retry).
     * @param int $baseDelayMs Delay base en ms para el backoff exponencial.
     * @param list<class-string<Throwable>> $retriableExceptions Excepciones que disparan retry.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 1000,
        public readonly array $retriableExceptions = [\Exception::class],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function noRetry(): self
    {
        return new self(maxAttempts: 0);
    }
}
```

- [ ] **Step 2: Crear `src/Service/Resilience/Retryer.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use App\Service\StructuredLogger;
use RuntimeException;
use Throwable;

/**
 * Ejecuta un closure con backoff exponencial. Si la excepción no es retriable
 * (no instanceof de ninguna de las clases en la policy), re-throw inmediato.
 * Si se agotan los intentos, lanza RuntimeException con previous = última excepción.
 */
final class Retryer
{
    private readonly StructuredLogger $logger;

    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly string $context = 'retry',
    ) {
        $this->logger = new StructuredLogger($context);
    }

    /**
     * @template T
     * @param callable():T $action
     * @return T
     */
    public function run(callable $action): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->policy->maxAttempts; $attempt++) {
            try {
                return $action();
            } catch (Throwable $e) {
                if (!$this->isRetriable($e)) {
                    throw $e;
                }
                $lastException = $e;

                if ($attempt < $this->policy->maxAttempts) {
                    $delayMs = $this->policy->baseDelayMs * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    $this->logger->warning(
                        "[{$this->context}] retry #" . ($attempt + 1) . " after {$delayMs}ms",
                        ['error' => $e->getMessage()],
                    );
                }
            }
        }

        throw new RuntimeException(
            "Retries exhausted ({$this->context}): " . ($lastException?->getMessage() ?? 'unknown'),
            0,
            $lastException,
        );
    }

    private function isRetriable(Throwable $e): bool
    {
        foreach ($this->policy->retriableExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
```

**Nota sobre la decisión de no inyectar `StructuredLogger`:** la spec original lo registraba en DI, pero `StructuredLogger` requiere `string $context` por constructor que el container no puede auto-resolver con `addShared` sin args. Se construye internamente con el `$context` del Retryer — Retryer queda libre de DI (consumidor lo instancia per-context).

- [ ] **Step 3: Validación manual**

No hay UI/endpoint todavía. La validación es estructural:

```bash
php -l src/Service/Resilience/RetryPolicy.php
php -l src/Service/Resilience/Retryer.php
```

Esperado: `No syntax errors detected` en ambos.

- [ ] **Step 4: Verificar autoload**

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; new App\Service\Resilience\RetryPolicy(); echo 'ok';"
```

Esperado: imprime `ok`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Resilience/
git commit -m "feat(plan-6): RetryPolicy + Retryer en src/Service/Resilience (W13)"
```

---

## Task 3: Refactor `WebhookService` con `Retryer` y timeouts diferenciados

**Files:**
- Modify: `src/Service/WebhookService.php` (reescritura completa del archivo)

- [ ] **Step 1: Reemplazar `src/Service/WebhookService.php` por:**

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Resilience\Retryer;
use App\Service\Resilience\RetryPolicy;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Exception;

/**
 * Outbound HTTP client con CircuitBreaker, retry y timeouts diferenciados.
 * - sendJson / post genérico: timeout 5s (interactivo).
 * - sendFile (multipart): timeout 30s (uploads legítimamente lentos).
 * - 4xx: no se reintenta (filtro del Retryer no se activa porque el handler decide
 *   devolver inmediatamente sin tirar Exception).
 * - 5xx / network error: reintenta (1s, 2s, 4s).
 * - CircuitBreaker envuelve al Retryer: si el CB está abierto, el fallback retorna
 *   "Circuit breaker is open" sin tocar el remoto.
 */
class WebhookService
{
    private const TIMEOUT_JSON_SECONDS = 5;
    private const TIMEOUT_FILE_SECONDS = 30;

    private Client $client;
    private CircuitBreaker $circuitBreaker;
    private Retryer $retryer;
    private StructuredLogger $logger;

    public function __construct()
    {
        $this->client = new Client();
        $this->circuitBreaker = new CircuitBreaker(
            'webhook',
            failureThreshold: 3,
            recoveryTimeoutSeconds: 120,
        );
        $this->retryer = new Retryer(RetryPolicy::default(), context: 'webhook');
        $this->logger = new StructuredLogger('Webhook');
    }

    /**
     * POST JSON data to a URL.
     */
    public function sendJson(string $url, array $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';

        return $this->dispatch(fn () => $this->client->post(
            $url,
            (string)json_encode($data),
            ['headers' => $headers, 'timeout' => self::TIMEOUT_JSON_SECONDS],
        ));
    }

    /**
     * POST a file (multipart) to a URL.
     */
    public function sendFile(
        string $url,
        string $filePath,
        string $fieldName = 'file',
        array $extraData = [],
        array $headers = [],
    ): array {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => "File not found: {$filePath}",
            ];
        }

        return $this->dispatch(fn () => $this->client->post(
            $url,
            array_merge($extraData, [$fieldName => fopen($filePath, 'r')]),
            [
                'headers' => $headers,
                'type' => 'multipart/form-data',
                'timeout' => self::TIMEOUT_FILE_SECONDS,
            ],
        ));
    }

    /**
     * Generic POST request (treats body as JSON-shaped string; uses JSON timeout).
     */
    public function post(string $url, mixed $body, array $headers = []): array
    {
        return $this->dispatch(fn () => $this->client->post(
            $url,
            (string)$body,
            ['headers' => $headers, 'timeout' => self::TIMEOUT_JSON_SECONDS],
        ));
    }

    /**
     * Wrap el closure HTTP en CircuitBreaker → Retryer → request.
     * 4xx no es retriable: se devuelve inmediatamente como respuesta normal.
     * 5xx / network error: throw Exception → Retryer reintenta hasta agotar.
     */
    private function dispatch(callable $request): array
    {
        return $this->circuitBreaker->call(
            fn () => $this->retryer->run(function () use ($request) {
                /** @var Response $response */
                $response = $request();

                if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                    return $this->shape($response);
                }

                if (!$response->isOk()) {
                    throw new Exception("HTTP {$response->getStatusCode()}");
                }

                return $this->shape($response);
            }),
            fn () => [
                'success' => false,
                'statusCode' => 0,
                'body' => '',
                'error' => 'Circuit breaker is open — external service unavailable',
            ],
        );
    }

    private function shape(Response $response): array
    {
        return [
            'success' => $response->isOk(),
            'statusCode' => $response->getStatusCode(),
            'body' => (string)$response->getBody(),
            'error' => $response->isOk() ? null : "HTTP {$response->getStatusCode()}",
        ];
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

```bash
php -l src/Service/WebhookService.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 3: Validación manual (V3 + V4 del spec)**

(Lo ejecuta el usuario; este plan sólo deja constancia.)

1. En `system_settings` (UI), poner `n8n_dian_webhook_url` = `https://httpbin.org/status/500`.
2. Disparar un crosscheck DIAN → revisar `logs/error.log` o `logs/debug.log`.
3. Esperado: 3 entradas `[webhook] retry #1 after 1000ms`, `#2 after 2000ms`, `#3 after 4000ms`.
4. Cambiar URL a `https://httpbin.org/status/404` → disparar crosscheck.
5. Esperado: falla en el primer intento, **sin** entradas de retry.
6. Cambiar URL a `https://httpbin.org/delay/10` → disparar crosscheck.
7. Esperado: falla en ≈5s con error de timeout (no 10s ni 30s).

- [ ] **Step 4: Commit**

```bash
git add src/Service/WebhookService.php
git commit -m "refactor(plan-6): WebhookService usa Retryer + timeouts diferenciados (W13, W14)"
```

---

## Task 4: Timeout SMTP en `CakeMailerAdapter`

**Files:**
- Modify: `src/Service/Adapter/CakeMailerAdapter.php:14-17` (añadir constante) y `_ensureTransport()` líneas 67-83.

- [ ] **Step 1: Editar `src/Service/Adapter/CakeMailerAdapter.php`**

Justo después de `class CakeMailerAdapter implements MailerInterface {` agregar la constante. Reemplazar el bloque actual:

```php
class CakeMailerAdapter implements MailerInterface
{
    private bool $transportConfigured = false;
```

por:

```php
class CakeMailerAdapter implements MailerInterface
{
    private const SMTP_TIMEOUT_SECONDS = 10;

    private bool $transportConfigured = false;
```

- [ ] **Step 2: En el método `_ensureTransport`, añadir `'timeout'` al array `$config`**

Reemplazar el bloque actual (líneas ~67-73):

```php
        $config = [
            'className' => 'Smtp',
            'host' => $smtpConfig['smtp_host'] ?? '',
            'port' => (int)($smtpConfig['smtp_port'] ?? 587),
            'username' => $smtpConfig['smtp_username'] ?? '',
            'password' => $smtpConfig['smtp_password'] ?? '',
        ];
```

por:

```php
        $config = [
            'className' => 'Smtp',
            'host' => $smtpConfig['smtp_host'] ?? '',
            'port' => (int)($smtpConfig['smtp_port'] ?? 587),
            'username' => $smtpConfig['smtp_username'] ?? '',
            'password' => $smtpConfig['smtp_password'] ?? '',
            'timeout' => self::SMTP_TIMEOUT_SECONDS,
        ];
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Service/Adapter/CakeMailerAdapter.php
```

Esperado: `No syntax errors detected`.

- [ ] **Step 4: Validación manual (V5 del spec)**

(Lo ejecuta el usuario.)

1. En `system_settings` (UI o SQL), poner `smtp_host = 1.2.3.4` (IP no enrutable).
2. Acción que envía email (p.ej. `Invoices::sendApprovalLinks`).
3. Cronometrar.
4. Esperado: falla en ≈10s. Fila nueva en `email_logs` con `status='failed'` y `last_error` con mensaje de socket/connect.
5. Restaurar `smtp_host` al valor real.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Adapter/CakeMailerAdapter.php
git commit -m "feat(plan-6): timeout SMTP de 10s en CakeMailerAdapter (W14)"
```

---

## Task 5: Idempotencia inline en `registerPayment`

**Files:**
- Modify: `src/Service/InvoicePaymentService.php:187-246`
- Modify: `src/Controller/InvoicePaymentsController.php:51-90` (action `addPayment`)
- Modify: `templates/element/payment_section.php` (cabecera + sección del form)
- Modify: `webroot/js/sgi-payment.js` (función `submitPayment`)
- Modify: `templates/Invoices/edit.php:826` (parámetros del partial)

### 5.1 — Service consume `idempotency_key`

- [ ] **Step 1: Reemplazar `InvoicePaymentService::registerPayment` por la versión que lee/genera `idempotency_key` y atrapa el duplicado**

Reemplazar el método completo (líneas 187-246) por:

```php
    public function registerPayment(
        int $invoiceId,
        array $paymentData,
        int $createdBy,
    ): ServiceResult {
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');

        $invoice = $invoicesTable->get($invoiceId);
        $currentStatus = $invoice->pipeline_status;

        if (!empty($paymentData['is_refund']) && !$this->docTypePolicies->for($invoice->document_type)->allowsRefundPayments()) {
            return ServiceResult::fail('is_refund solo es válido en pagos de Anticipos.');
        }

        $idempotencyKey = isset($paymentData['idempotency_key']) && trim((string)$paymentData['idempotency_key']) !== ''
            ? trim((string)$paymentData['idempotency_key'])
            : \Cake\Utility\Text::uuid();

        // Short-circuit: si ya existe un pago con esta key, devolver éxito apuntando
        // a esa fila — operación repetida, idempotente.
        $existing = $paymentsTable->find()
            ->where(['idempotency_key' => $idempotencyKey])
            ->first();
        if ($existing !== null) {
            return ServiceResult::ok('Pago ya registrado (operación repetida).');
        }

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $invoice,
            $invoiceId,
            $paymentData,
            $createdBy,
            $currentStatus,
            $idempotencyKey,
        ) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $invoiceId,
                'banking_entity_id' => $paymentData['banking_entity_id'] ?? null,
                'amount' => $paymentData['amount'] ?? null,
                'payment_date' => $paymentData['payment_date'] ?? null,
                'status' => InvoiceConstants::PAYMENT_RECORD_PENDING,
                'authorized' => false,
                'created_by' => $createdBy,
                'idempotency_key' => $idempotencyKey,
            ]);

            try {
                if (!$paymentsTable->save($payment)) {
                    $errors = [];
                    foreach ($payment->getErrors() as $field => $fieldErrors) {
                        foreach ($fieldErrors as $msg) {
                            $errors[] = "$field: $msg";
                        }
                    }

                    return ServiceResult::fail('No se pudo registrar el pago.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
                }
            } catch (\PDOException $e) {
                // SQLSTATE 23000 = integrity constraint violation (duplicate key).
                if ($e->getCode() === '23000') {
                    return ServiceResult::ok('Pago ya registrado (operación repetida).');
                }
                throw $e;
            }

            $invoice->pipeline_status = InvoiceConstants::STATUS_AUTORIZACION_PAGO;
            $invoicesTable->save($invoice);

            $this->historyService->recordStatusChange(
                $invoiceId,
                $currentStatus,
                InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                $createdBy,
            );

            return ServiceResult::ok('Pago registrado. La factura pasó a Autorización de Pago.');
        });
    }
```

**Notas:**
- El short-circuit con `find()->first()` evita disparar la transacción en el caso común de doble click — una sola query previa.
- El `try/catch (\PDOException)` es la red de seguridad por si la query del short-circuit y el save corren con timing tal que dos requests pasan ambos chequeos. El UNIQUE index los desempata.
- `Text::uuid()` se importa al usarse fully-qualified — no agregar `use` para mantener el cambio acotado al método.

### 5.2 — Form en partial compartido

- [ ] **Step 2: Editar `templates/element/payment_section.php` — bloque de defaults (líneas ~28-42)**

Justo antes de `// If singlePaymentOnly, suppress "Agregar Pago"...` (~línea 44), agregar:

```php
$withIdempotencyKey = $withIdempotencyKey ?? false;
$idempotencyKey = $withIdempotencyKey ? \Cake\Utility\Text::uuid() : null;
```

- [ ] **Step 3: Editar el contenedor de la sección (líneas ~64-68)**

Reemplazar el `<div ...>` de la sección por uno que incluya el data attribute condicional:

```php
<div class="mb-4"
     data-payment-section
     data-add-url="<?= h($addUrl) ?>"
     data-remaining-amount="<?= $remainingAmount ?>"
     data-force-full-amount="<?= $forceFullAmount ? '1' : '0' ?>"
     <?php if ($idempotencyKey): ?>data-idempotency-key="<?= h($idempotencyKey) ?>"<?php endif; ?>>
```

### 5.3 — JS forwardea la key

- [ ] **Step 4: Editar `webroot/js/sgi-payment.js` — función `submitPayment` (~líneas 123-134)**

Reemplazar:

```javascript
            function submitPayment() {
                if (!validate()) return;
                var fields = {
                    'banking_entity_id': bankInput.value,
                    'amount': getRawAmount(),
                    'payment_date': dateInput.value,
                };
                if (fullPayCheck && fullPayCheck.checked) {
                    fields['full_payment'] = '1';
                }
                _submitDynamicForm(addUrl, fields, findCsrfToken(section));
            }
```

por:

```javascript
            function submitPayment() {
                if (!validate()) return;
                var fields = {
                    'banking_entity_id': bankInput.value,
                    'amount': getRawAmount(),
                    'payment_date': dateInput.value,
                };
                if (fullPayCheck && fullPayCheck.checked) {
                    fields['full_payment'] = '1';
                }
                if (section.dataset.idempotencyKey) {
                    fields['idempotency_key'] = section.dataset.idempotencyKey;
                }
                _submitDynamicForm(addUrl, fields, findCsrfToken(section));
            }
```

### 5.4 — Activar el flag desde Invoices/edit

- [ ] **Step 5: Editar `templates/Invoices/edit.php` (~líneas 820-845)**

Localizar el bloque que arma `$sharedPaymentParams` (cerca de línea 820) y agregar `'with_idempotency_key' => true` al array. Sin el contexto exacto del archivo, la regla es: **al `$sharedPaymentParams` que apunta a `'controller' => 'InvoicePayments', 'action' => 'addPayment'` agregarle la clave**.

Si `$sharedPaymentParams` se construye así:

```php
$sharedPaymentParams = [
    'addPaymentUrl'      => ['controller' => 'InvoicePayments', 'action' => 'addPayment', $invoice->id],
    // ... otras claves ...
];
```

agregar (al final del array, antes del `]`):

```php
    'with_idempotency_key' => true,
```

**Importante:** sólo este consumidor (`Invoices/edit.php` cuando renderiza `payment_section` para `InvoicePayments`) recibe la flag en true. El resto (`NoveltyLiquidationDocs`, `PettyCashRecords`, `Advances`) no se toca y queda con default `false` — fuera de alcance W6.

### 5.5 — Controller pasa la key al service

- [ ] **Step 6: Editar `src/Controller/InvoicePaymentsController.php:51-90`**

El método `addPayment` ya hace `$data = $this->request->getData();` y pasa `$data` al service. La idempotency_key llega adentro de `$data` automáticamente — **no requiere cambio en el controller**.

Verificar que la línea actual (~77):

```php
        $result = $this->paymentService->registerPayment(
            (int)$invoiceId,
            $data,
            (int)$this->_getCurrentUser()->id,
        );
```

queda **igual**. No editar.

- [ ] **Step 7: Verificar sintaxis**

```bash
php -l src/Service/InvoicePaymentService.php
php -l templates/element/payment_section.php
php -l templates/Invoices/edit.php
node --check webroot/js/sgi-payment.js 2>/dev/null || echo "(skip si node no instalado — JS no tiene validación de sintaxis estática nativa)"
```

Esperado: `No syntax errors detected` en los `.php`.

- [ ] **Step 8: Validación manual (V1 del spec)**

(Lo ejecuta el usuario.)

1. Login Tesorería → factura en estado `tesoreria`.
2. Modal "Registrar pago" → llenar monto y fecha.
3. Click rápido **dos veces** en submit (o duplicar pestaña tras render del modal y enviar desde ambas).
4. Esperado: una sola fila nueva en `invoice_payments`. Segundo click muestra flash "Pago ya registrado (operación repetida)". Factura avanza a `autorizacion_pago` una sola vez.
5. SQL de verificación:

```sql
SELECT idempotency_key, COUNT(*) FROM invoice_payments
WHERE idempotency_key IS NOT NULL
GROUP BY idempotency_key HAVING COUNT(*) > 1;
```

Esperado: 0 filas.

- [ ] **Step 9: Commit**

```bash
git add src/Service/InvoicePaymentService.php \
        src/Controller/InvoicePaymentsController.php \
        templates/element/payment_section.php \
        templates/Invoices/edit.php \
        webroot/js/sgi-payment.js
git commit -m "feat(plan-6): idempotencia inline en registerPayment (W6)"
```

---

## Task 6: Optimistic concurrency en advance endpoints

**Files:**
- Modify: `src/Controller/AppController.php` (agregar helper protegido)
- Modify: `src/Controller/InvoicesController.php:391-419` (advanceStatus)
- Modify: `src/Controller/EmployeeNoveltiesController.php:716-747` (advance)
- Modify: `src/Controller/PaymentSchedulingsController.php:183-` (advance)
- Modify: `src/Controller/PettyCashRecordsController.php:358-376` (advanceStatus)
- Modify: `templates/Invoices/edit.php` (form de avance)
- Modify: `templates/EmployeeNovelties/edit.php:376` y `:445` (dos forms a `advance`)
- Modify: `templates/PaymentSchedulings/edit.php:282` (form a `advance`)
- Modify: `templates/PettyCashRecords/edit.php` (form a `advanceStatus`)

### 6.1 — Helper en `AppController`

- [ ] **Step 1: Editar `src/Controller/AppController.php`**

Agregar el método protegido al final de la clase, antes del cierre `}`. Incluir docblock:

```php
    /**
     * Optimistic concurrency guard para acciones que avanzan estado del pipeline.
     *
     * Compara el `expected_status` enviado por el form con el estado actual de la
     * entidad. Si difieren (otra pestaña ya avanzó, o el usuario hizo back+resubmit),
     * flash error y devuelve false; el caller hace `return $this->redirect(...)`.
     *
     * Funciona para cualquier campo de status: por defecto pipeline_status, pero
     * acepta cualquier nombre para reutilizar en otros workflows.
     *
     * @param string $current Estado actual de la entidad.
     * @param string $errorMessage Mensaje de flash si no coincide.
     * @return bool true si el guard pasa, false si falló (caller debe redirect).
     */
    protected function _ensureExpectedStatus(
        string $current,
        string $errorMessage = 'El registro cambió de estado. Recargue la página antes de avanzar.',
    ): bool {
        $expected = (string)$this->request->getData('expected_status', '');
        if ($expected !== '' && $expected !== $current) {
            $this->Flash->error($errorMessage);

            return false;
        }

        return true;
    }
```

### 6.2 — Aplicar en `InvoicesController::advanceStatus`

- [ ] **Step 2: Editar `src/Controller/InvoicesController.php:391-419`**

Reemplazar el método `advanceStatus` por:

```php
    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $invoice = $this->Invoices->get($id);

        if (!$this->_ensureExpectedStatus($invoice->pipeline_status)) {
            return $this->_redirectForInvoice($invoice, 'edit', $id);
        }

        if ($this->_getRoleName() !== RoleConstants::ADMIN) {
            $lockMessage = $this->pipeline->getEditLockMessage($invoice);
            if ($lockMessage !== null) {
                $this->Flash->error($lockMessage);

                return $this->_redirectForInvoice($invoice, 'view', $id);
            }
        }

        $user = $this->_getCurrentUser();

        $result = $this->pipeline->advance($invoice, $this->_getRoleName(), $user->id);

        if ($result['success']) {
            $nextLabel = InvoicePipelineService::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Factura avanzada a: %s', $nextLabel));

            return $this->_redirectForInvoice($invoice, 'index');
        }

        $this->Flash->error($result['error']);

        return $this->_redirectForInvoice($invoice, 'edit', $id);
    }
```

### 6.3 — Aplicar en `EmployeeNoveltiesController::advance`

- [ ] **Step 3: Editar `src/Controller/EmployeeNoveltiesController.php:716-747`**

Insertar el guard al inicio del método, después del `allowMethod` y el `get`:

```php
    public function advance(?string $id = null)
    {
        $this->request->allowMethod(['post']);
        $novelty = $this->EmployeeNovelties->get($id, contain: ['NoveltyTypes']);

        if (!$this->_ensureExpectedStatus($novelty->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->Authentication->getIdentity()->getOriginalData();
        $originalStatus = $novelty->pipeline_status;

        // ... (resto del método igual: save editable fields, advance, flash, redirect)
```

(El resto del método queda exactamente como está. Sólo se inserta el bloque del guard entre el `get` y la línea `$user = $this->Authentication->...`.)

### 6.4 — Aplicar en `PaymentSchedulingsController::advance`

- [ ] **Step 4: Editar `src/Controller/PaymentSchedulingsController.php:183-`**

Insertar el guard al inicio:

```php
    public function advance($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PaymentSchedulings->get($id);

        if (!$this->_ensureExpectedStatus($record->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $roleName = $this->_getRoleName();
        $user = $this->_getCurrentUser();

        // ... (resto del método igual)
```

### 6.5 — Aplicar en `PettyCashRecordsController::advanceStatus`

- [ ] **Step 5: Editar `src/Controller/PettyCashRecordsController.php:358-376`**

Reemplazar el método por:

```php
    public function advanceStatus($id = null)
    {
        $this->request->allowMethod(['post']);
        $record = $this->PettyCashRecords->get($id);

        if (!$this->_ensureExpectedStatus($record->pipeline_status)) {
            return $this->redirect(['action' => 'edit', $id]);
        }

        $user = $this->_getCurrentUser();

        $result = $this->pettyCashService->advanceStatus($record, $user->id);

        if ($result['success']) {
            $nextLabel = PettyCashConstants::STATUS_LABELS[$result['nextStatus']] ?? $result['nextStatus'];
            $this->Flash->success(sprintf('Registro avanzado a: %s', $nextLabel));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->error($result['error']);

        return $this->redirect(['action' => 'edit', $id]);
    }
```

### 6.6 — Hidden field `expected_status` en los 4 templates

- [ ] **Step 6: Editar `templates/Invoices/edit.php`**

Localizar el `Form->create` que apunta a `advanceStatus` (`['action' => 'advanceStatus', $invoice->id]`). Inmediatamente después de la apertura del form, agregar:

```php
<?= $this->Form->hidden('expected_status', ['value' => $invoice->pipeline_status]) ?>
```

(Si hay más de un form de `advanceStatus` — por ejemplo uno por sección — agregar el hidden en cada uno.)

- [ ] **Step 7: Editar `templates/EmployeeNovelties/edit.php` líneas ~376 y ~445**

Hay dos `Form->create` apuntando a `'action' => 'advance'`. Tras cada apertura, agregar:

```php
<?= $this->Form->hidden('expected_status', ['value' => $novelty->pipeline_status]) ?>
```

- [ ] **Step 8: Editar `templates/PaymentSchedulings/edit.php` línea ~282**

Localizar el `Form->create` con `['action' => 'advance', $record->id]`. Tras la apertura, agregar:

```php
<?= $this->Form->hidden('expected_status', ['value' => $record->pipeline_status]) ?>
```

- [ ] **Step 9: Editar `templates/PettyCashRecords/edit.php`**

Localizar el form que postea a `advanceStatus`. Tras la apertura, agregar:

```php
<?= $this->Form->hidden('expected_status', ['value' => $record->pipeline_status]) ?>
```

(Si el botón "Avanzar" no está dentro de un `Form->create` formal sino en un postLink u otro mecanismo, verificar que la mecánica de envío incluya `expected_status`. `postLink` acepta `['data' => ['expected_status' => $record->pipeline_status]]`.)

- [ ] **Step 10: Verificar sintaxis**

```bash
php -l src/Controller/AppController.php \
       src/Controller/InvoicesController.php \
       src/Controller/EmployeeNoveltiesController.php \
       src/Controller/PaymentSchedulingsController.php \
       src/Controller/PettyCashRecordsController.php \
       templates/Invoices/edit.php \
       templates/EmployeeNovelties/edit.php \
       templates/PaymentSchedulings/edit.php \
       templates/PettyCashRecords/edit.php
```

Esperado: `No syntax errors detected` en todos.

- [ ] **Step 11: Validación manual (V2 del spec)**

(Lo ejecuta el usuario, repetir para cada uno de Invoices, EmployeeNovelties, PaymentSchedulings, PettyCashRecords.)

1. Cargar `edit` del registro.
2. En otra pestaña, avanzar el mismo registro al siguiente estado.
3. Volver a la primera pestaña → click "Avanzar".
4. Esperado: flash "El registro cambió de estado. Recargue la página antes de avanzar." Sin avance.

- [ ] **Step 12: Commit**

```bash
git add src/Controller/AppController.php \
        src/Controller/InvoicesController.php \
        src/Controller/EmployeeNoveltiesController.php \
        src/Controller/PaymentSchedulingsController.php \
        src/Controller/PettyCashRecordsController.php \
        templates/Invoices/edit.php \
        templates/EmployeeNovelties/edit.php \
        templates/PaymentSchedulings/edit.php \
        templates/PettyCashRecords/edit.php
git commit -m "feat(plan-6): optimistic concurrency en advance endpoints (W6)"
```

---

## Task 7: Infraestructura `HealthCheck/`

**Files:**
- Create: `src/Service/HealthCheck/HealthStatus.php`
- Create: `src/Service/HealthCheck/HealthCheckResult.php`
- Create: `src/Service/HealthCheck/HealthCheckInterface.php`
- Create: `src/Service/HealthCheck/DatabaseHealthCheck.php`
- Create: `src/Service/HealthCheck/CacheHealthCheck.php`
- Create: `src/Service/HealthCheck/CircuitBreakerHealthCheck.php`
- Create: `src/Service/HealthCheck/EmailLogHealthCheck.php`

- [ ] **Step 1: Crear `src/Service/HealthCheck/HealthStatus.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

final class HealthStatus
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const DEGRADED = 'degraded';
}
```

- [ ] **Step 2: Crear `src/Service/HealthCheck/HealthCheckResult.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

final class HealthCheckResult
{
    /**
     * @param string $name Nombre del check (database, cache, circuit_breakers, email_logs).
     * @param string $status Una de las constantes de HealthStatus.
     * @param bool $critical Si true, FAIL hace al endpoint devolver 503.
     * @param array $details Payload adicional visible solo para usuarios autenticados.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly bool $critical,
        public readonly array $details = [],
    ) {
    }
}
```

- [ ] **Step 3: Crear `src/Service/HealthCheck/HealthCheckInterface.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

interface HealthCheckInterface
{
    public function check(): HealthCheckResult;
}
```

- [ ] **Step 4: Crear `src/Service/HealthCheck/DatabaseHealthCheck.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Datasource\ConnectionManager;
use Throwable;

final class DatabaseHealthCheck implements HealthCheckInterface
{
    public function check(): HealthCheckResult
    {
        try {
            ConnectionManager::get('default')->execute('SELECT 1');

            return new HealthCheckResult('database', HealthStatus::OK, critical: true);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'database',
                HealthStatus::FAIL,
                critical: true,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
```

- [ ] **Step 5: Crear `src/Service/HealthCheck/CacheHealthCheck.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Cache\Cache;
use Throwable;

final class CacheHealthCheck implements HealthCheckInterface
{
    public function check(): HealthCheckResult
    {
        $key = 'health_probe_' . bin2hex(random_bytes(4));

        try {
            Cache::write($key, '1', 'default');
            $value = Cache::read($key, 'default');
            Cache::delete($key, 'default');

            if ($value !== '1') {
                return new HealthCheckResult(
                    'cache',
                    HealthStatus::FAIL,
                    critical: true,
                    details: ['error' => 'cache read returned unexpected value'],
                );
            }

            return new HealthCheckResult('cache', HealthStatus::OK, critical: true);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'cache',
                HealthStatus::FAIL,
                critical: true,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
```

- [ ] **Step 6: Crear `src/Service/HealthCheck/CircuitBreakerHealthCheck.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\Cache\Cache;

/**
 * Lee el estado de los CircuitBreaker desde el cache (sin instanciar el CB).
 * El layout de las keys está definido en CircuitBreaker::_cacheKey:
 *   "circuit_breaker_{$name}_state".
 * Si el cache no devuelve nada, asumimos closed (estado por defecto del CB).
 */
final class CircuitBreakerHealthCheck implements HealthCheckInterface
{
    private const CB_NAMES = ['webhook', 'smtp'];

    public function check(): HealthCheckResult
    {
        $states = [];
        $anyOpen = false;

        foreach (self::CB_NAMES as $name) {
            $state = Cache::read("circuit_breaker_{$name}_state", 'default') ?: 'closed';
            $states[$name] = (string)$state;

            if ($state === 'open') {
                $anyOpen = true;
            }
        }

        return new HealthCheckResult(
            'circuit_breakers',
            $anyOpen ? HealthStatus::DEGRADED : HealthStatus::OK,
            critical: false,
            details: $states,
        );
    }
}
```

- [ ] **Step 7: Crear `src/Service/HealthCheck/EmailLogHealthCheck.php`**

```php
<?php
declare(strict_types=1);

namespace App\Service\HealthCheck;

use Cake\ORM\TableRegistry;
use Throwable;

final class EmailLogHealthCheck implements HealthCheckInterface
{
    public function check(): HealthCheckResult
    {
        try {
            $count = TableRegistry::getTableLocator()
                ->get('EmailLogs')
                ->find()
                ->where(['status' => 'failed'])
                ->count();

            return new HealthCheckResult(
                'email_logs_failed',
                $count > 0 ? HealthStatus::DEGRADED : HealthStatus::OK,
                critical: false,
                details: ['failed_count' => $count],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                'email_logs_failed',
                HealthStatus::FAIL,
                critical: false,
                details: ['error' => $e->getMessage()],
            );
        }
    }
}
```

- [ ] **Step 8: Verificar sintaxis y autoload**

```bash
php -l src/Service/HealthCheck/*.php
composer dump-autoload
php -r "require 'vendor/autoload.php'; new App\Service\HealthCheck\DatabaseHealthCheck(); echo 'ok';"
```

Esperado: `No syntax errors detected` en todos los archivos y `ok` impreso por el `php -r`.

- [ ] **Step 9: Commit**

```bash
git add src/Service/HealthCheck/
git commit -m "feat(plan-6): infraestructura HealthCheck (DB, cache, CBs, email_logs) (W7)"
```

---

## Task 8: `HealthController` reescrito + DI

**Files:**
- Modify: `src/Controller/HealthController.php` (reescritura completa)
- Modify: `src/Application.php:154-322` (agregar registros DI al final de `services()`)

### 8.1 — Registrar checks en DI

- [ ] **Step 1: Editar `src/Application.php`**

Agregar el bloque al final del método `services()`, justo antes del cierre `}` de `services()`:

```php
        // === Plan 6: Health checks ===
        $container->addShared(\App\Service\HealthCheck\DatabaseHealthCheck::class);
        $container->addShared(\App\Service\HealthCheck\CacheHealthCheck::class);
        $container->addShared(\App\Service\HealthCheck\CircuitBreakerHealthCheck::class);
        $container->addShared(\App\Service\HealthCheck\EmailLogHealthCheck::class);
```

### 8.2 — Reescribir `HealthController`

- [ ] **Step 2: Reemplazar `src/Controller/HealthController.php` por:**

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthCheck\CacheHealthCheck;
use App\Service\HealthCheck\CircuitBreakerHealthCheck;
use App\Service\HealthCheck\DatabaseHealthCheck;
use App\Service\HealthCheck\EmailLogHealthCheck;
use App\Service\HealthCheck\HealthCheckInterface;
use App\Service\HealthCheck\HealthCheckResult;
use App\Service\HealthCheck\HealthStatus;
use Cake\Http\Response;

class HealthController extends AppController
{
    private const CHECKS = [
        DatabaseHealthCheck::class,
        CacheHealthCheck::class,
        CircuitBreakerHealthCheck::class,
        EmailLogHealthCheck::class,
    ];

    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * Endpoint público con respuesta mínima para uptime monitoring;
     * con sesión autenticada devuelve el detalle por componente.
     *
     * 503 sólo si DatabaseHealthCheck o CacheHealthCheck devuelven FAIL
     * (críticos para que la app procese requests). CB abierto y
     * email_logs_failed > 0 se reportan como degraded con status 200.
     */
    public function index(): Response
    {
        $container = $this->getContainer();
        $results = array_map(
            fn (string $cls): HealthCheckResult => $container->get($cls)->check(),
            self::CHECKS,
        );

        $criticalFailed = !empty(array_filter(
            $results,
            fn (HealthCheckResult $r) => $r->critical && $r->status === HealthStatus::FAIL,
        ));

        $hasDegraded = !empty(array_filter(
            $results,
            fn (HealthCheckResult $r) => $r->status === HealthStatus::DEGRADED
                || (!$r->critical && $r->status === HealthStatus::FAIL),
        ));

        $globalStatus = match (true) {
            $criticalFailed => 'unhealthy',
            $hasDegraded => 'degraded',
            default => 'healthy',
        };

        $isAuthed = $this->Authentication->getIdentity() !== null;
        $body = $isAuthed
            ? ['status' => $globalStatus, 'checks' => $this->_serializeChecks($results)]
            : ['status' => $globalStatus];

        return $this->response
            ->withType('application/json')
            ->withStatus($criticalFailed ? 503 : 200)
            ->withStringBody((string)json_encode($body));
    }

    /**
     * @param list<HealthCheckResult> $results
     * @return array<string, array{status: string, ...}>
     */
    private function _serializeChecks(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r->name] = ['status' => $r->status] + $r->details;
        }

        return $out;
    }
}
```

**Nota sobre el `getContainer()`:** CakePHP 5 lo expone en controllers via `$this->getContainer()` cuando hay un container registrado en la app (lo hay desde Plan 3). Si por alguna razón este método no estuviera disponible en runtime, el fallback es importar el contenedor vía `\League\Container\Container` desde `\Cake\Core\Configure::read('App.container')` — pero eso no es necesario si se sigue el patrón de Application::services() del proyecto.

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Controller/HealthController.php src/Application.php
```

Esperado: `No syntax errors detected` en ambos.

- [ ] **Step 4: Validación manual (V6, V7, V8, V9, V10 del spec)**

(Lo ejecuta el usuario.)

**V6 — Mínimo público:**

```bash
curl -i http://localhost:8765/health
```

Esperado: `HTTP/1.1 200 OK` + cuerpo `{"status":"healthy"}` (o `degraded` si algún CB está abierto).

**V7 — Detallado autenticado:**

1. Login admin en browser.
2. Abrir `http://localhost:8765/health`.
3. Esperado: JSON con `status` + `checks.database.status=ok`, `checks.cache.status=ok`, `checks.circuit_breakers.details = {"webhook":"closed","smtp":"closed"}`, `checks.email_logs_failed.details.failed_count`.

**V8 — DB caída:**

```bash
sudo systemctl stop mariadb
curl -i http://localhost:8765/health
sudo systemctl start mariadb
```

Esperado entre los dos comandos: `HTTP/1.1 503 Service Unavailable` + `{"status":"unhealthy"}` (anónimo).

**V9 — Cache rota:**

```bash
chmod 000 tmp/cache/
curl -i http://localhost:8765/health
chmod 755 tmp/cache/
```

Esperado: 503 con `cache: fail` (autenticado) o `{"status":"unhealthy"}` (anónimo). Restaurar permisos después.

**V10 — CB degraded:**

1. Forzar 3 webhooks fallidos consecutivos (V3 cumple si se completó).
2. Login admin → `/health`.
3. Esperado: `status=degraded`, código 200, `circuit_breakers.details.webhook="open"`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/HealthController.php src/Application.php
git commit -m "feat(plan-6): /health expandido con DB+cache+CBs+email_logs (W7)"
```

---

## Task 9: Cierre — actualizar roadmap

**Files:**
- Modify: `docs/audits/architecture-audit-roadmap.md` (tabla de estado)

- [ ] **Step 1: Editar `docs/audits/architecture-audit-roadmap.md`**

En la tabla "Tabla de estado (actualizar al cerrar cada plan)" cambiar la fila del Plan 6:

```
| 6 | Resilience Hardening | ⬜ Pendiente | — | — | — | — |
```

por (con la fecha real al momento del merge y los paths de spec/plan):

```
| 6 | Resilience Hardening | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-resilience-hardening-design.md) | [plan](../superpowers/plans/2026-05-01-resilience-hardening-plan.md) | — | YYYY-MM-DD |
```

También cambiar en el "Resumen ejecutivo" (cerca de la línea 48) la fila:

```
| 6 | Resilience Hardening | W6, W13, W14, W7 | M (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | ⬜ Pendiente |
```

por:

```
| 6 | Resilience Hardening | W6, W13, W14, W7 | M (~1 sem) | — *(originalmente Plan 2; ver "Cambios al roadmap")* | 🟢 Completado |
```

- [ ] **Step 2: Commit**

```bash
git add docs/audits/architecture-audit-roadmap.md
git commit -m "chore(plan-6): cierre del Plan 6 (Resilience Hardening)"
```

---

## Revisión de calidad final (single pass al final del plan)

Per memoria `feedback_review_at_end.md`: la revisión de calidad de código se corre **una sola vez** al final del plan, no por tarea. Cuando todos los tasks anteriores estén commited y validados manualmente:

- [ ] **Step 1: Lanzar el revisor de calidad sobre los cambios del plan**

```bash
composer cs-check
```

Si hay violaciones, autofix:

```bash
composer cs-fix
git diff
```

Revisar el diff de `cs-fix` antes de aceptar (puede tocar archivos no relacionados al plan; en ese caso, restringir el commit a los archivos modificados por Plan 6).

- [ ] **Step 2: Buscar code smells residuales**

```bash
grep -n "new StructuredLogger\|new CircuitBreaker\|usleep" src/Service/Resilience/ src/Service/HealthCheck/ src/Service/WebhookService.php
```

Esperado: ocurrencias coherentes con la arquitectura (StructuredLogger en Retryer/WebhookService, CircuitBreaker en WebhookService, usleep en Retryer únicamente).

- [ ] **Step 3: Sweep de imports**

```bash
grep -rn "^use App\\\\Service" src/Controller/HealthController.php src/Service/WebhookService.php
```

Verificar que no hay `use` huérfanos (sin uso) ni faltantes.

- [ ] **Step 4: Commit del cleanup si hubo cambios de cs-fix**

```bash
git add -p
git commit -m "style(plan-6): cs-fix"
```

---

## Resumen de criterios de cierre

Antes de cerrar Plan 6 verificar **todos**:

- ✅ Migración aplicada — columna `idempotency_key` y unique index existen en `invoice_payments`.
- ✅ Doble click en "Registrar pago" no crea pagos duplicados (V1).
- ✅ Doble avance con tab stale es rechazado en los 4 controllers (V2).
- ✅ Webhook 5xx reintenta con backoff visible en logs; 4xx no reintenta (V3).
- ✅ `/health` anónimo devuelve `{"status":...}` mínimo; autenticado devuelve detalle (V6, V7).
- ✅ `/health` 503 con DB caída (V8) y cache rota (V9).
- ✅ `/health` degraded con CB abierto (V10).
- ✅ `composer cs-check` pasa.
- ✅ Roadmap actualizado.
