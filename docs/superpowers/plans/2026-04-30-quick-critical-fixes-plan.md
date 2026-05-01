# Plan 1 — Quick Critical Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply 3 critical fixes from the architecture audit (C1, C2, C3) — make `authorizePayment()` atomic, route `NotificationService` through `MailerInterface` (with adapter SSL bug fix), and replace `RateLimitMiddleware` with an atomic DB-backed counter applied to `/login` and `/approve/*`.

**Architecture:** Three independent changes bundled in one branch. Order: C3 (low risk) → C1 (medium) → C2 (highest, new DB table). Each task ends with a commit.

**Tech Stack:** CakePHP 5.3 / PHP 8.2+ / MySQL. Migrations via `Migrations\BaseMigration`. Email through `MailerInterface` adapter pattern. Rate limit storage in MySQL via `INSERT ... ON DUPLICATE KEY UPDATE` for atomicity.

**Project policy:** This project does **not** use automated tests (see `CLAUDE.md` → "Testing Policy"). Validation is manual. Plans replace TDD steps with manual validation steps before each commit, plus a comprehensive validation run at the end.

**References:**
- Spec: [`docs/superpowers/specs/2026-04-30-quick-critical-fixes-design.md`](../specs/2026-04-30-quick-critical-fixes-design.md)
- Roadmap: [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) (Plan 1)
- Audit origin: [`docs/audits/architecture-audit-2026-04-30.md`](../../audits/architecture-audit-2026-04-30.md) (C1, C2, C3)

---

## Task 0: Create feature branch

**Files:** none.

- [ ] **Step 1: Verify clean working tree**

```bash
git status
```

Expected: working tree clean (or only the unrelated test-scaffolding deletions you've been carrying).

- [ ] **Step 2: Create branch from main**

```bash
git checkout -b feat/audit-plan-1-quick-fixes
```

Expected: switched to a new branch.

---

## Task 1: Fix SSL handling in `CakeMailerAdapter` (C3 prep)

**Files:**
- Modify: `src/Service/Adapter/CakeMailerAdapter.php` (lines 62–82, method `_ensureTransport`)

The current adapter hardcodes `'tls' => true`, which breaks SMTP accounts that use SSL on port 465 (Office365, Gmail SSL). This task replicates the (correct) logic that `NotificationService::configureTransport()` already has — handles `tls`, `ssl`, and "no encryption".

- [ ] **Step 1: Replace `_ensureTransport()` body**

Open `src/Service/Adapter/CakeMailerAdapter.php`. Replace the entire `_ensureTransport()` method (currently lines 62–82) with:

```php
    /**
     * Configure the dynamic SMTP transport if not already done.
     *
     * @param array $smtpConfig SMTP configuration.
     * @return void
     */
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

- [ ] **Step 2: Syntax check**

```bash
php -l src/Service/Adapter/CakeMailerAdapter.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/Adapter/CakeMailerAdapter.php
git commit -m "fix(mailer): handle ssl/tls/none encryption in CakeMailerAdapter

Adapter previously hardcoded tls=true, breaking SMTP accounts using SSL
on port 465. Mirrors NotificationService::configureTransport() logic so
the adapter becomes a drop-in replacement.

Refs audit C3."
```

---

## Task 2: Create `smtp_test` email template (C3 prep)

**Files:**
- Create: `templates/email/html/smtp_test.php`

Required because `MailerInterface::send()` takes a template name; the SMTP test diagnostic needs its own minimal template instead of inline HTML.

- [ ] **Step 1: Create the template file**

Create `templates/email/html/smtp_test.php` with exactly this content:

```php
<p>Este es un correo de prueba del SGI.</p>
```

- [ ] **Step 2: Commit**

```bash
git add templates/email/html/smtp_test.php
git commit -m "feat(mailer): add smtp_test template for SMTP diagnostic

Minimal HTML template used by NotificationService::testSmtpConnection
once it is migrated to use MailerInterface.

Refs audit C3."
```

---

## Task 3: Refactor `NotificationService` to consume `MailerInterface` (C3)

**Files:**
- Modify: `src/Service/NotificationService.php` (full rewrite of class body)

This task is one big rewrite: constructor, three methods, drop one private method. Do all sub-steps before committing — leaving the class half-migrated breaks email flows.

- [ ] **Step 1: Rewrite the entire file**

Replace the full content of `src/Service/NotificationService.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Invoice;
use App\Service\Adapter\CakeMailerAdapter;
use App\Service\Interface\MailerInterface;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Exception;

class NotificationService
{
    private SystemSettingsService $settings;
    private MailerInterface $mailer;
    private CircuitBreaker $smtpCircuitBreaker;

    public function __construct(
        ?SystemSettingsService $settings = null,
        ?MailerInterface $mailer = null,
    ) {
        $this->settings = $settings ?? new SystemSettingsService();
        $this->mailer = $mailer ?? new CakeMailerAdapter($this->settings);
        $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
    }

    /**
     * Send approval link email to the assigned approver. Throws on failure.
     */
    public function sendApprovalLinkNotification(Invoice $invoice, string $approvalUrl, ?int $approverUserId = null): void
    {
        $smtpConfig = $this->settings->getGroup('smtp');

        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            throw new Exception('SMTP no configurado. Configure el correo en Ajustes del Sistema.');
        }

        $approverId = $approverUserId ?? $invoice->approver_id;
        if (!$approverId) {
            return;
        }

        $recipients = $this->getApproverRecipient($approverId);
        if (empty($recipients)) {
            throw new Exception('El aprobador asignado no tiene un usuario activo o no tiene correo.');
        }

        $invoiceNumber = $invoice->invoice_number ?: '#' . $invoice->id;
        $subject = "SGI-COPCSA - Solicitud de Aprobación: Factura {$invoiceNumber}";

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

            $this->smtpCircuitBreaker->call(function () use ($recipient, $subject, $viewVars): void {
                $this->mailer->send($recipient->email, $subject, 'invoice_approval_request', $viewVars);
            });

            Log::info("Approval link sent to {$recipient->email} for invoice #{$invoice->id}");
        }
    }

    /**
     * Send approval link email for a novelty to the assigned approver.
     */
    public function sendNoveltyApprovalEmail(object $approver, object $novelty, string $approvalUrl): void
    {
        $smtpConfig = $this->settings->getGroup('smtp');

        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            Log::warning('SMTP no configurado — no se envió email de aprobación de novedad.');

            return;
        }

        $employeeName = $novelty->custom_name ?? ($novelty->employee->full_name ?? '—');
        $noveltyTypeName = $novelty->novelty_type->name ?? '—';

        $subject = "SGI-COPCSA - Solicitud de Aprobación: Novedad de {$employeeName}";

        $viewVars = [
            'employeeName' => $employeeName,
            'noveltyTypeName' => $noveltyTypeName,
            'reason' => $novelty->reason ?? '',
            'approvalUrl' => $approvalUrl,
            'recipientName' => $approver->full_name ?? $approver->username ?? '',
        ];

        try {
            $this->smtpCircuitBreaker->call(function () use ($approver, $subject, $viewVars): void {
                $this->mailer->send($approver->email, $subject, 'novelty_approval_request', $viewVars);
            });

            Log::info("Novelty approval link sent to {$approver->email} for novelty #{$novelty->id}");
        } catch (Exception $e) {
            Log::error("Novelty approval email failed for {$approver->email}: " . $e->getMessage());
        }
    }

    private function getApproverRecipient(int $approverId): array
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $approver = $usersTable->find()
            ->where(['Users.id' => $approverId, 'Users.active' => true])
            ->first();

        return $approver ? [$approver] : [];
    }

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
}
```

Note what changed vs. the original:
- New imports: `App\Service\Adapter\CakeMailerAdapter`, `App\Service\Interface\MailerInterface`.
- Removed imports: `Cake\Mailer\Mailer`, `Cake\Mailer\TransportFactory`.
- New property: `private MailerInterface $mailer;`.
- New constructor parameter: `?MailerInterface $mailer`.
- All three send methods (`sendApprovalLinkNotification`, `sendNoveltyApprovalEmail`, `testSmtpConnection`) call `$this->mailer->send(...)` instead of `new Mailer()` + manual setup.
- Private method `configureTransport()` is gone.

- [ ] **Step 2: Syntax check**

```bash
php -l src/Service/NotificationService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Quick smoke check via dev server**

Start the dev server in one terminal:

```bash
php bin/cake server
```

In a browser, log in as admin and navigate to `Ajustes del Sistema → SMTP → Probar conexión`. With valid SMTP credentials, the test must succeed and a "Correo de prueba" should arrive at the configured `smtp_from_email`. With invalid host, the response shows the error message.

If the test SMTP page is not easily reachable in your environment, skip this step — full validation runs in Task 10.

- [ ] **Step 4: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "refactor(notifications): consume MailerInterface, drop duplicated SMTP setup

Resolves audit finding C3: NotificationService now depends on the
MailerInterface port and delegates transport configuration to
CakeMailerAdapter. Removes the duplicated configureTransport() and
inline Mailer instantiation.

testSmtpConnection() now also routes through the adapter using the
new smtp_test template, so all SMTP transport setup lives in one
place.

Refs audit C3."
```

---

## Task 4: Wrap `authorizePayment()` in `transactional()` (C1)

**Files:**
- Modify: `src/Service/InvoicePaymentService.php` (lines 108–162, method `authorizePayment`)

- [ ] **Step 1: Replace `authorizePayment()` method body**

Open `src/Service/InvoicePaymentService.php`. Replace the entire `authorizePayment()` method (currently lines 108–162) with:

```php
    /**
     * Autoriza un pago individual, recalcula estado, y maneja transiciones de pipeline.
     * Registra historial para los cambios de estado. Todo el flujo (pago, recálculo,
     * actualización de pipeline, historial y side effects de legalización) ocurre
     * dentro de una sola transacción para evitar inconsistencias parciales.
     */
    public function authorizePayment(int $paymentId, int $authorizedBy): array
    {
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $connection = $paymentsTable->getConnection();

        $result = $connection->transactional(function () use (
            $paymentsTable,
            $invoicesTable,
            $paymentId,
            $authorizedBy,
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

Notes:
- `Connection::transactional()` rolls back if the callable returns `false` literal or throws; otherwise commits.
- Nested `transactional()` calls inside `closeOnRefundAuthorized` and `initialize` use savepoints — safe.
- Return shape (`array`) preserved — `ServiceResult` standardization is W15 / Plan 7 work.

- [ ] **Step 2: Syntax check**

```bash
php -l src/Service/InvoicePaymentService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/InvoicePaymentService.php
git commit -m "fix(invoices): authorizePayment becomes atomic via transactional()

Resolves audit finding C1. Previously authorizePayment() did 4 saves +
2 service side effects without a transaction; failure mid-flow left the
DB inconsistent. Now the entire body runs inside Connection::transactional(),
including closeOnRefundAuthorized and AdvanceLegalizationService::initialize.

Side effects stay inside the transaction (not deferred post-commit) until
plan 2 (Outbox) lands. See roadmap deviation note dated 2026-04-30.

Refs audit C1."
```

---

## Task 5: Create `rate_limit_buckets` migration (C2 prep)

**Files:**
- Create: `config/Migrations/{timestamp}_AddRateLimitBucketsTable.php` (timestamp generated by CakePHP)

- [ ] **Step 1: Generate migration skeleton**

```bash
php bin/cake migrations create AddRateLimitBucketsTable
```

Expected: a new file at `config/Migrations/<14-digit-timestamp>_AddRateLimitBucketsTable.php`.

Note the exact filename — you'll edit it in the next step.

- [ ] **Step 2: Edit the generated migration**

Open the new migration file and replace its content with:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRateLimitBucketsTable extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('rate_limit_buckets')) {
            return;
        }

        $table = $this->table('rate_limit_buckets', ['signed' => false]);
        $table
            ->addColumn('bucket_key', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('window_start', 'datetime', [
                'null' => false,
            ])
            ->addColumn('count', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['bucket_key'], ['unique' => true])
            ->addIndex(['window_start'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('rate_limit_buckets')) {
            $this->table('rate_limit_buckets')->drop()->save();
        }
    }
}
```

- [ ] **Step 3: Run the migration**

```bash
php bin/cake migrations migrate
```

Expected: migration applies, no errors.

- [ ] **Step 4: Verify the table exists**

```bash
php bin/cake migrations status
```

Expected: the new migration shows as `up`.

Optional sanity check via MySQL:

```bash
mysql -u <user> -p <db> -e "DESCRIBE rate_limit_buckets;"
```

Expected: 6 columns (id, bucket_key, window_start, count, created, modified) plus the indexes.

- [ ] **Step 5: Commit**

Replace `<filename>` with the actual migration filename from Step 1.

```bash
git add config/Migrations/<filename>
git commit -m "feat(rate-limit): add rate_limit_buckets migration

Backing store for atomic per-window request counters. Used by
RateLimitMiddleware to fix audit finding C2 (race condition + TTL reset
in cache-based counter).

Refs audit C2."
```

---

## Task 6: Create `RateLimitBucket` entity and `RateLimitBucketsTable` (C2 prep)

**Files:**
- Create: `src/Model/Entity/RateLimitBucket.php`
- Create: `src/Model/Table/RateLimitBucketsTable.php`

- [ ] **Step 1: Create the entity**

Create `src/Model/Entity/RateLimitBucket.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class RateLimitBucket extends Entity
{
    protected array $_accessible = [
        'bucket_key' => true,
        'window_start' => true,
        'count' => true,
        'created' => true,
        'modified' => true,
    ];
}
```

- [ ] **Step 2: Create the table class**

Create `src/Model/Table/RateLimitBucketsTable.php`:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use DateTime;

class RateLimitBucketsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rate_limit_buckets');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * Atomically increment the counter for the given bucket key in the
     * given window, returning the resulting count.
     */
    public function incrementAndGet(string $bucketKey, int $windowStart): int
    {
        $connection = $this->getConnection();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $windowDt = (new DateTime("@{$windowStart}"))->format('Y-m-d H:i:s');

        $connection->execute(
            'INSERT INTO rate_limit_buckets (bucket_key, window_start, count, created, modified)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE count = count + 1, modified = ?',
            [$bucketKey, $windowDt, $now, $now, $now],
        );

        $stmt = $connection->execute(
            'SELECT count FROM rate_limit_buckets WHERE bucket_key = ?',
            [$bucketKey],
        );

        return (int)$stmt->fetchColumn(0);
    }

    /**
     * Delete bucket rows whose window started more than $olderThanSeconds ago.
     */
    public function garbageCollect(int $olderThanSeconds): int
    {
        $cutoff = (new DateTime())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');

        return $this->deleteAll(['window_start <' => $cutoff]);
    }
}
```

- [ ] **Step 3: Syntax check**

```bash
php -l src/Model/Entity/RateLimitBucket.php
php -l src/Model/Table/RateLimitBucketsTable.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add src/Model/Entity/RateLimitBucket.php src/Model/Table/RateLimitBucketsTable.php
git commit -m "feat(rate-limit): RateLimitBucket entity + atomic increment table

incrementAndGet() uses INSERT ... ON DUPLICATE KEY UPDATE so concurrent
requests cannot bypass the limit (fixes the read-modify-write race in
the cache-backed implementation).

garbageCollect() drops bucket rows whose window is older than a given
threshold; called probabilistically from the middleware so no cron job
is needed.

Refs audit C2."
```

---

## Task 7: Add `Security.trustedProxies` config (C2 prep)

**Files:**
- Modify: `config/app.php` (line 80–82, `Security` section)
- Modify: `.env` (project root — append a new variable)

- [ ] **Step 1: Add `trustedProxies` to the `Security` array**

Open `config/app.php`. Replace the existing `Security` block at line 80–82:

```php
    'Security' => [
        'salt' => env('SECURITY_SALT'),
    ],
```

with:

```php
    'Security' => [
        'salt' => env('SECURITY_SALT'),
        /*
         * Comma-separated list of CIDR ranges for trusted reverse proxies.
         * RateLimitMiddleware will only honor X-Forwarded-For when REMOTE_ADDR
         * matches one of these. Empty by default (no proxy trusted).
         * Example: '172.16.0.0/12,10.0.0.0/8'
         */
        'trustedProxies' => env('TRUSTED_PROXIES', ''),
    ],
```

- [ ] **Step 2: Add `TRUSTED_PROXIES` to `.env`**

Open `.env` (project root). Append at the end (above any closing notes):

```
# Trusted reverse proxies for X-Forwarded-For (rate limiter).
# Comma-separated CIDRs. Set to your nginx/proxy network range
# (typical Docker bridge: 172.16.0.0/12).
TRUSTED_PROXIES=
```

> Leave the value empty in dev unless you actually run behind a proxy. With empty value, the middleware uses `REMOTE_ADDR` directly.

- [ ] **Step 3: Syntax check**

```bash
php -l config/app.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

`.env` is gitignored — only `config/app.php` will be committed. Document the env var in the commit message.

```bash
git add config/app.php
git commit -m "config(security): add Security.trustedProxies for rate limiter

CSV of CIDRs read from TRUSTED_PROXIES env var. Empty default means
'do not trust X-Forwarded-For from anyone' (use REMOTE_ADDR directly).

Operators behind a reverse proxy (e.g. nginx in Docker) must set
TRUSTED_PROXIES in .env. Documented inline.

Refs audit C2."
```

---

## Task 8: Refactor `RateLimitMiddleware` (C2)

**Files:**
- Modify: `src/Middleware/RateLimitMiddleware.php` (full rewrite)

- [ ] **Step 1: Rewrite the entire file**

Replace the full content of `src/Middleware/RateLimitMiddleware.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Model\Table\RateLimitBucketsTable;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param int $maxRequests Maximum requests allowed per window.
     * @param int $windowSeconds Window duration in seconds.
     * @param \App\Model\Table\RateLimitBucketsTable|null $buckets Bucket table (DI for tests/dev).
     * @param array<string>|null $trustedProxies CIDR list of trusted proxies; null = read from config.
     */
    public function __construct(
        private readonly int $maxRequests = 10,
        private readonly int $windowSeconds = 60,
        private readonly ?RateLimitBucketsTable $buckets = null,
        private readonly ?array $trustedProxies = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
        $path = $request->getUri()->getPath();
        $windowStart = (int)floor(time() / $this->windowSeconds) * $this->windowSeconds;
        $key = hash('sha256', $ip . '|' . $path . '|' . $windowStart);

        /** @var \App\Model\Table\RateLimitBucketsTable $buckets */
        $buckets = $this->buckets
            ?? TableRegistry::getTableLocator()->get('RateLimitBuckets');

        $count = $buckets->incrementAndGet($key, $windowStart);

        if ($count > $this->maxRequests) {
            $retryAfter = max(1, $this->windowSeconds - (time() - $windowStart));

            $response = new Response();

            return $response
                ->withStatus(429)
                ->withType('application/json')
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withStringBody((string)json_encode(['error' => 'Too many requests']));
        }

        // Probabilistic in-line garbage collection (1 in 100 requests).
        if (random_int(1, 100) === 1) {
            $buckets->garbageCollect($this->windowSeconds * 5);
        }

        return $handler->handle($request);
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        $trustedProxies = $this->trustedProxies
            ?? $this->parseTrustedProxies((string)Configure::read('Security.trustedProxies', ''));

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

    /**
     * @return array<string>
     */
    private function parseTrustedProxies(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    /**
     * @param array<string> $ranges CIDR ranges.
     */
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
            // IPv6 not supported in this helper. Documented limitation.
            return false;
        }

        $mask = -1 << (32 - (int)$bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
```

- [ ] **Step 2: Syntax check**

```bash
php -l src/Middleware/RateLimitMiddleware.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/Middleware/RateLimitMiddleware.php
git commit -m "fix(rate-limit): atomic counter, X-Forwarded-For, Retry-After

Resolves audit finding C2:
- Atomicity: delegates to RateLimitBucketsTable::incrementAndGet
  (INSERT ... ON DUPLICATE KEY UPDATE in MySQL).
- Fixed-window strategy: each (ip, path, windowFloor) is a distinct
  bucket row, so the TTL-reset bug from the cache impl is gone.
- X-Forwarded-For honored only when REMOTE_ADDR matches a CIDR in
  Security.trustedProxies. Default empty (use REMOTE_ADDR).
- 429 responses now include Retry-After header with seconds remaining
  in the window.
- Probabilistic in-line GC (1/100) drops bucket rows older than 5
  windows; no cron required.

IPv6 in CIDR matching is out of scope; documented as a known limitation.

Refs audit C2."
```

---

## Task 9: Apply rate limiter to `/login` in `routes.php` (C2)

**Files:**
- Modify: `config/routes.php` (the `$routes->scope('/', ...)` block)

> **Why three edits, in order:** `applyMiddleware('name')` looks up `name` in the parent scope's middleware registry. So both `registerMiddleware` calls must come **before** any `applyMiddleware` call that references them. The cleanest layout is: register both middlewares at the top of the outer `'/'` scope, then add the `/login` sub-scope that applies `rateLimitLogin`, then remove the now-redundant `registerMiddleware('rateLimit', ...)` lower down.

- [ ] **Step 1: Register both rate-limit middlewares at the top of the `'/'` scope**

Open `config/routes.php`. Inside `$routes->scope('/', function (RouteBuilder $builder): void {` (line 53), insert these two lines as the **first** statements in the closure (immediately above `$builder->connect('/', ['controller' => 'Dashboard', ...])`):

```php
        $builder->registerMiddleware(
            'rateLimit',
            new RateLimitMiddleware(10, 60),
        );
        $builder->registerMiddleware(
            'rateLimitLogin',
            new RateLimitMiddleware(5, 300),
        );

```

- [ ] **Step 2: Replace the `/login` connect with a rate-limited sub-scope**

Find the existing line (around line 55):

```php
        $builder->connect('/login', ['controller' => 'Users', 'action' => 'login']);
```

Replace that single line with:

```php
        $builder->scope('/login', function (RouteBuilder $loginBuilder): void {
            $loginBuilder->applyMiddleware('rateLimitLogin');
            $loginBuilder->connect('', ['controller' => 'Users', 'action' => 'login']);
        });
```

> The empty-path `connect('', ...)` inside `scope('/login', ...)` matches the URL `/login` exactly — same as the original.

- [ ] **Step 3: Remove the now-redundant `registerMiddleware('rateLimit', ...)` below**

Find this block (around line 100, just before the `/approve` scope):

```php
        // External approval tokens (rate-limited)
        $builder->registerMiddleware(
            'rateLimit',
            new RateLimitMiddleware(10, 60),
        );
        $builder->scope('/approve', function (RouteBuilder $approveBuilder): void {
```

Delete only the inner `$builder->registerMiddleware(...)` block (3 lines), leaving the comment and the `$builder->scope('/approve', ...)` line intact:

```php
        // External approval tokens (rate-limited)
        $builder->scope('/approve', function (RouteBuilder $approveBuilder): void {
```

The `applyMiddleware('rateLimit')` call inside that scope keeps working because the middleware is now registered at the outer scope (Step 1).

- [ ] **Step 4: Syntax check**

```bash
php -l config/routes.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Smoke check — routes still resolve**

```bash
php bin/cake routes
```

Expected output includes a row mapping `/login` → `Users::login` (path may show as `/login` or with trailing slash; both are acceptable).

- [ ] **Step 6: Commit**

```bash
git add config/routes.php
git commit -m "feat(rate-limit): apply rateLimitLogin (5/300s) to /login

Resolves second half of audit finding C2. /approve/* keeps the existing
rateLimit (10/60s); /login gets a tighter rateLimitLogin (5 attempts
per 5 minutes) via a sub-scope to match the URL exactly.

Both middlewares now use the new atomic DB-backed RateLimitMiddleware
implementation from the previous commit.

Refs audit C2."
```

---

## Task 10: Final validation and roadmap update

**Files:**
- Modify: `docs/audits/architecture-audit-roadmap.md` (status table row for Plan 1)

This task runs the full manual validation suite from the spec, then updates the roadmap to reflect 🟢 Completado.

### 10.1 — C3 validation (NotificationService + MailerInterface)

- [ ] **Step 1: Approval flow regression**

Start the dev server:

```bash
php bin/cake server
```

In the browser, log in as a user with the *Registro/Revisión* role. Open an existing factura in `aprobacion`, assign an aprobador. The aprobador should receive a real email with the approval link.

Expected: email arrives at `<approver>.email`. No errors in `logs/error.log`.

- [ ] **Step 2: SMTP variants**

In *Ajustes del Sistema → SMTP*, configure each variant in turn and click "Probar SMTP":

- TLS, port 587 → expect success.
- SSL, port 465 → expect success (this is the SSL bug fix from Task 1).
- No encryption (e.g. local mailcatcher on port 25) → expect success.

Expected: each variant produces a "Conexión SMTP exitosa" response and a test email arrives.

- [ ] **Step 3: Circuit breaker**

In SMTP settings, set the host to an invalid value (e.g. `nope.invalid`). Trigger 4 approval emails in quick succession (assign / unassign approver, or use a CLI script).

Expected: first 3 attempts fail and log `CircuitBreaker open` warning; the 4th attempt fails immediately with the breaker-open path. Restore valid host after the test.

### 10.2 — C1 validation (`authorizePayment` transactional)

- [ ] **Step 4: Happy path**

As Contador, authorize a payment in `autorizacion_pago`. Confirm in MySQL:

```bash
mysql -u <user> -p <db> -e "
  SELECT id, status, authorized FROM invoice_payments WHERE id = <paymentId>;
  SELECT id, pipeline_status FROM invoices WHERE id = <invoiceId>;
  SELECT * FROM invoice_histories WHERE invoice_id = <invoiceId> ORDER BY id DESC LIMIT 3;
"
```

Expected: payment shows `status='authorized'`, invoice advanced to `pagada` or back to `tesoreria` per the rules, and `invoice_histories` has a fresh status-change row.

- [ ] **Step 5: Rollback verification**

In `src/Service/AdvanceLegalizationService.php`, temporarily edit `initialize(...)` to throw an exception unconditionally:

```php
public function initialize(Invoice $invoice, int $userId): void
{
    throw new \RuntimeException('plan-1 rollback test');
    // ... original body unchanged below ...
}
```

Authorize the final payment of an Anticipo (the case that triggers `initialize`). The request should fail with a 500 / exception.

Then verify in MySQL that **nothing changed**:

```bash
mysql -u <user> -p <db> -e "
  SELECT id, status FROM invoice_payments WHERE id = <paymentId>;
  SELECT id, pipeline_status FROM invoices WHERE id = <invoiceId>;
"
```

Expected: payment still `status='pending'`, invoice still in its prior state. If both rolled back, C1 is verified.

**Restore the `initialize()` method to its original body before continuing.**

### 10.3 — C2 validation (rate limiter)

- [ ] **Step 6: `/login` 6th attempt blocked**

In a private/incognito window, attempt 6 logins to `/login` with intentionally wrong credentials:

```
admin / wrong-1
admin / wrong-2
admin / wrong-3
admin / wrong-4
admin / wrong-5
admin / wrong-6   ← this one returns 429
```

Expected: 6th response is HTTP 429 with header `Retry-After: <number>` and body `{"error":"Too many requests"}`. After waiting 5 minutes (or truncating the bucket row in MySQL), login becomes possible again.

- [ ] **Step 7: `/approve/{token}` 11th attempt blocked**

From a terminal, hammer an existing approval URL:

```bash
TOKEN="<paste-a-real-64-hex-token>"
for i in $(seq 1 11); do
  curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8765/approve/${TOKEN}"
done
```

Expected: first 10 responses are 200/302, 11th is `429`.

- [ ] **Step 8: Trusted proxies**

Configure in `.env`:

```
TRUSTED_PROXIES=127.0.0.1/32
```

Restart the dev server. Then:

```bash
curl -H "X-Forwarded-For: 1.2.3.4" -o /dev/null -s "http://localhost:8765/login"
mysql -u <user> -p <db> -e "
  SELECT bucket_key, count, window_start FROM rate_limit_buckets ORDER BY id DESC LIMIT 5;
"
```

Expected: a fresh bucket row whose `bucket_key` is the sha256 of `'1.2.3.4|/login|<windowStart>'`. To verify: in PHP `hash('sha256', '1.2.3.4|/login|' . floor(time()/300)*300)` should match the row's `bucket_key`.

Then unset / clear `TRUSTED_PROXIES`, restart server, repeat the curl. The new row's `bucket_key` should now be hashed from REMOTE_ADDR (`127.0.0.1`), not `1.2.3.4`.

- [ ] **Step 9: Garbage collection**

The middleware calls GC probabilistically (1/100). To force it, hit any rate-limited route in a loop:

```bash
for i in $(seq 1 200); do
  curl -s -o /dev/null "http://localhost:8765/login"
done
```

Then verify old bucket rows were removed:

```bash
mysql -u <user> -p <db> -e "
  SELECT COUNT(*) AS total, MIN(window_start) AS oldest
  FROM rate_limit_buckets;
"
```

Expected: oldest `window_start` is within the last `windowSeconds * 5` (= 25 minutes for the login limiter).

### 10.4 — Update roadmap

- [ ] **Step 10: Mark Plan 1 as completed in the roadmap**

Edit `docs/audits/architecture-audit-roadmap.md`. In the status table, replace the Plan 1 row:

```
| 1 | Quick Critical Fixes | 🟡 En progreso | [spec](../superpowers/specs/2026-04-30-quick-critical-fixes-design.md) | — | — | — |
```

with:

```
| 1 | Quick Critical Fixes | 🟢 Completado | [spec](../superpowers/specs/2026-04-30-quick-critical-fixes-design.md) | [plan](../superpowers/plans/2026-04-30-quick-critical-fixes-plan.md) | <PR-URL-or-#> | YYYY-MM-DD |
```

Replace `<PR-URL-or-#>` with the merged PR number/URL and `YYYY-MM-DD` with the merge date.

- [ ] **Step 11: Final commit**

```bash
git add docs/audits/architecture-audit-roadmap.md
git commit -m "docs(roadmap): mark plan 1 (quick critical fixes) as completed"
```

- [ ] **Step 12: Push and open PR**

```bash
git push -u origin feat/audit-plan-1-quick-fixes
```

Then open a PR titled `Plan 1 — Quick Critical Fixes (C1+C2+C3)` with description linking to the spec, the plan, and the audit. Once merged, return to this file and confirm Step 10 was already filled with the real PR URL and merge date.

---

## Done criteria

This plan is complete when:

1. All 10 tasks are merged.
2. All 9 manual validation steps in Task 10 pass.
3. The `rate_limit_buckets` migration is applied in production.
4. `docs/audits/architecture-audit-roadmap.md` shows Plan 1 as 🟢 Completado with a real PR link and merge date.

## Out of scope (explicit)

- Automated tests (project policy — see `CLAUDE.md`).
- Migrating `?? new X()` constructor fallback pattern → Plan 3 (DI Container).
- Standardizing `ServiceResult` returns in `authorizePayment()` → Plan 7.
- Outbox-based deferral of `authorizePayment` side effects → Plan 2 / Plan 5.
- IPv6 support in CIDR matching for the rate limiter → known limitation.
- Refactor of approval emails to outbox-driven delivery → Plan 2.
