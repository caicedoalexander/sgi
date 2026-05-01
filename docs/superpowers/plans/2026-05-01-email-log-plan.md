# Email Audit Log + Reintento manual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el envío silencioso de correos por una bitácora persistente (`email_logs`) con UI de reintento manual, cerrando W8 sin introducir worker async ni cron.

**Architecture:** El envío sigue síncrono dentro de `NotificationService` pero ahora cada intento crea/actualiza una fila en `email_logs` (status=pending → sent/failed). Las excepciones SMTP ya no se tragan: se propagan al controller para que la UI las muestre. La recuperación es manual desde un panel inline en la entidad o una vista global `/email-logs` (admin-only).

**Tech Stack:** CakePHP 5.3, PHP 8.2+, MySQL/MariaDB. Sin tests automatizados (política del proyecto: validación manual). Convenciones: `BaseMigration` + `hasTable()`, `ServiceResult` para retornos de servicios, prefijo `.sgi-` para CSS, Bootstrap Icons para iconos.

**Spec:** [`docs/superpowers/specs/2026-05-01-email-log-design.md`](../specs/2026-05-01-email-log-design.md)

**Roadmap:** [`docs/audits/architecture-audit-roadmap.md`](../../audits/architecture-audit-roadmap.md) · Plan #2

---

## Estructura de archivos

**Nuevos:**
```
config/Migrations/20260501150000_AddEmailLogsTable.php
config/Migrations/20260501150100_SeedEmailLogsPermissions.php
src/Constants/EmailLogConstants.php
src/Model/Entity/EmailLog.php
src/Model/Table/EmailLogsTable.php
src/Service/EmailLogService.php
src/Controller/EmailLogsController.php
templates/EmailLogs/index.php
templates/element/email_log_panel.php
```

**Modificados:**
```
src/Service/NotificationService.php             — integra log; expone deliverRaw(); no traga excepciones
src/Service/InvoiceApprovalService.php          — propaga excepción + pasa createdBy
src/Controller/EmployeeNoveltiesController.php  — propaga excepción en líneas 641 y 788; carga logs en edit()
src/Controller/InvoicesController.php           — carga logs en edit()
src/Controller/AppController.php                — controllerModuleMap + _actionToPermission
src/Service/AuthorizationService.php            — MODULES
templates/Invoices/edit.php                     — inserta panel inline
templates/EmployeeNovelties/edit.php            — inserta panel inline
templates/layout/default.php                    — entrada en sidebar (Administración)
```

**Sin cambios pero referenciados:**
- `src/Service/CircuitBreaker.php` — sigue gobernando los envíos en `NotificationService`
- `src/Service/Adapter/CakeMailerAdapter.php` — sigue siendo la implementación de `MailerInterface`
- `config/routes.php` — no se tocan rutas (las acciones nuevas son cubiertas por `$builder->fallbacks()`)

---

## Task 1: Schema, constantes, entity, table

**Files:**
- Create: `config/Migrations/20260501150000_AddEmailLogsTable.php`
- Create: `src/Constants/EmailLogConstants.php`
- Create: `src/Model/Entity/EmailLog.php`
- Create: `src/Model/Table/EmailLogsTable.php`

- [ ] **Step 1: Generar migración con `bin/cake`**

```bash
php bin/cake migrations create AddEmailLogsTable
```

Esto crea un archivo en `config/Migrations/` con timestamp del momento. Renombrarlo a `20260501150000_AddEmailLogsTable.php` para mantener orden con el resto de migraciones del Plan 2.

```bash
# Encontrar el archivo recién creado y renombrarlo
mv config/Migrations/$(ls -t config/Migrations/ | head -1) config/Migrations/20260501150000_AddEmailLogsTable.php
```

- [ ] **Step 2: Reemplazar el contenido de la migración**

Sustituir todo el archivo `config/Migrations/20260501150000_AddEmailLogsTable.php` por:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddEmailLogsTable extends BaseMigration
{
    public function up(): void
    {
        if ($this->hasTable('email_logs')) {
            return;
        }

        $table = $this->table('email_logs', ['signed' => false]);
        $table
            ->addColumn('event_type', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('entity_type', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('entity_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('to_email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('subject', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('template', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('payload', 'json', [
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'pending',
            ])
            ->addColumn('attempts', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('last_error', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('last_attempt_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('sent_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created_by', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'idx_entity'])
            ->addIndex(['status', 'created'], ['name' => 'idx_status_created'])
            ->addIndex(['event_type'], ['name' => 'idx_event_type'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('email_logs')) {
            $this->table('email_logs')->drop()->save();
        }
    }
}
```

- [ ] **Step 3: Aplicar migración y verificar**

```bash
php bin/cake migrations migrate
```

Esperado: salida termina con `migrated` y sin errores. Verificar:

```bash
php bin/cake console -q <<< "echo \Cake\Datasource\ConnectionManager::get('default')->getSchemaCollection()->describe('email_logs')->columns();"
```

Alternativa más simple: `php bin/cake bake migrations status` lista la migración como `up`.

Probar también el rollback y volver a aplicar:

```bash
php bin/cake migrations rollback
php bin/cake migrations migrate
```

Esperado: rollback elimina la tabla, migrate la recrea, sin errores.

- [ ] **Step 4: Crear `src/Constants/EmailLogConstants.php`**

```php
<?php
declare(strict_types=1);

namespace App\Constants;

final class EmailLogConstants
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_SENT    => 'Enviado',
        self::STATUS_FAILED  => 'Fallido',
    ];

    public const EVENT_INVOICE_APPROVAL_REQUEST = 'invoice_approval_request';
    public const EVENT_NOVELTY_APPROVAL_REQUEST = 'novelty_approval_request';

    public const EVENT_LABELS = [
        self::EVENT_INVOICE_APPROVAL_REQUEST => 'Solicitud de aprobación de factura',
        self::EVENT_NOVELTY_APPROVAL_REQUEST => 'Solicitud de aprobación de novedad',
    ];

    public const ENTITY_INVOICE = 'invoice';
    public const ENTITY_NOVELTY = 'employee_novelty';

    /** Tras este tiempo, una fila 'pending' se considera huérfana (proceso interrumpido). */
    public const ORPHAN_THRESHOLD_SECONDS = 300;

    /** Truncado del mensaje de error guardado en `last_error`. */
    public const ERROR_MESSAGE_MAX_LENGTH = 5000;

    /** Tope de filas procesadas por una invocación de retryAllFailed. */
    public const RETRY_BATCH_LIMIT = 100;
}
```

- [ ] **Step 5: Crear `src/Model/Entity/EmailLog.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $event_type
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $to_email
 * @property string $subject
 * @property string $template
 * @property array $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Cake\I18n\DateTime|null $last_attempt_at
 * @property \Cake\I18n\DateTime|null $sent_at
 * @property int|null $created_by
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class EmailLog extends Entity
{
    protected array $_accessible = [
        'event_type' => true,
        'entity_type' => true,
        'entity_id' => true,
        'to_email' => true,
        'subject' => true,
        'template' => true,
        'payload' => true,
        'status' => true,
        'attempts' => true,
        'last_error' => true,
        'last_attempt_at' => true,
        'sent_at' => true,
        'created_by' => true,
    ];
}
```

- [ ] **Step 6: Crear `src/Model/Table/EmailLogsTable.php`**

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\EmailLogConstants;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class EmailLogsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('email_logs');
        $this->setDisplayField('subject');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('event_type')->maxLength('event_type', 50)->notEmptyString('event_type')
            ->scalar('to_email')->maxLength('to_email', 255)->notEmptyString('to_email')
            ->scalar('subject')->maxLength('subject', 255)->notEmptyString('subject')
            ->scalar('template')->maxLength('template', 100)->notEmptyString('template')
            ->array('payload')->notEmptyArray('payload')
            ->inList('status', EmailLogConstants::STATUSES)
            ->integer('attempts')->greaterThanOrEqual('attempts', 0)
            ->allowEmptyString('entity_type')
            ->integer('entity_id')->allowEmptyString('entity_id')
            ->maxLength('last_error', EmailLogConstants::ERROR_MESSAGE_MAX_LENGTH)
            ->allowEmptyString('last_error')
            ->allowEmptyDateTime('last_attempt_at')
            ->allowEmptyDateTime('sent_at')
            ->allowEmptyString('created_by');

        return $validator;
    }

    /** Filas pertenecientes a una entidad concreta (factura o novedad). */
    public function findForEntity(SelectQuery $query, string $entityType, int $entityId): SelectQuery
    {
        return $query
            ->where([
                'EmailLogs.entity_type' => $entityType,
                'EmailLogs.entity_id' => $entityId,
            ])
            ->orderBy(['EmailLogs.created' => 'DESC']);
    }

    /** Solo fallidos (para retry masivo). */
    public function findFailed(SelectQuery $query): SelectQuery
    {
        return $query->where(['EmailLogs.status' => EmailLogConstants::STATUS_FAILED]);
    }

    /** Pendientes huérfanos (creados antes del threshold y sin intento reciente). */
    public function findOrphanPending(SelectQuery $query): SelectQuery
    {
        $cutoff = (new DateTime())->modify('-' . EmailLogConstants::ORPHAN_THRESHOLD_SECONDS . ' seconds');

        return $query->where([
            'EmailLogs.status' => EmailLogConstants::STATUS_PENDING,
            'EmailLogs.created <' => $cutoff,
            'OR' => [
                'EmailLogs.last_attempt_at IS' => null,
                'EmailLogs.last_attempt_at <' => $cutoff,
            ],
        ]);
    }
}
```

- [ ] **Step 7: Verificar code style**

```bash
composer cs-check src/Constants/EmailLogConstants.php src/Model/Entity/EmailLog.php src/Model/Table/EmailLogsTable.php config/Migrations/20260501150000_AddEmailLogsTable.php
```

Esperado: `0 ERRORS, 0 WARNINGS`. Si hay errores: `composer cs-fix <archivos>`.

- [ ] **Step 8: Commit**

```bash
git add config/Migrations/20260501150000_AddEmailLogsTable.php \
        src/Constants/EmailLogConstants.php \
        src/Model/Entity/EmailLog.php \
        src/Model/Table/EmailLogsTable.php
git commit -m "feat(email-log): add email_logs table, entity, table class and constants

Plan 2 — capa de persistencia para la bitácora de envíos. Tabla con
índices para panel inline (entity_type, entity_id), filtros globales
(status, created; event_type) y sweep de huérfanos."
```

---

## Task 2: `EmailLogService` — operaciones base

**Files:**
- Create: `src/Service/EmailLogService.php`

- [ ] **Step 1: Crear el servicio con las operaciones de logging y sweep**

Crear `src/Service/EmailLogService.php`. **Nota**: el método `retry()` y `retryAllFailed()` se completan en Tasks 8 y 9 — aquí dejamos solo las operaciones que `NotificationService` usará en Task 3.

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
use App\Model\Table\EmailLogsTable;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

class EmailLogService
{
    private EmailLogsTable $emailLogsTable;
    private StructuredLogger $logger;

    public function __construct()
    {
        /** @var EmailLogsTable $table */
        $table = TableRegistry::getTableLocator()->get('EmailLogs');
        $this->emailLogsTable = $table;
        $this->logger = new StructuredLogger('EmailLog');
    }

    /**
     * Registra una nueva intención de envío con status='pending'. Devuelve el id.
     * El `payload` debe contener al menos las claves 'viewVars' y 'layout'.
     */
    public function recordPending(
        string $eventType,
        ?string $entityType,
        ?int $entityId,
        string $toEmail,
        string $subject,
        string $template,
        array $payload,
        ?int $createdBy,
    ): int {
        $log = $this->emailLogsTable->newEntity([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'to_email' => $toEmail,
            'subject' => $subject,
            'template' => $template,
            'payload' => $payload,
            'status' => EmailLogConstants::STATUS_PENDING,
            'attempts' => 0,
            'created_by' => $createdBy,
        ]);

        if (!$this->emailLogsTable->save($log)) {
            $this->logger->error('Failed to persist email log row', [
                'errors' => $log->getErrors(),
                'event_type' => $eventType,
                'to' => $toEmail,
            ]);
            // Devolver 0 indica al caller que no se pudo persistir; el caller
            // debe seguir adelante con el envío (preferimos enviar sin log que
            // bloquear el flujo).
            return 0;
        }

        return (int)$log->id;
    }

    /**
     * Marca una fila como enviada. attempts++, sent_at=now, last_attempt_at=now.
     * No-op si $id=0 (caso de fallback descrito en recordPending).
     */
    public function markSent(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $log = $this->emailLogsTable->find()->where(['id' => $id])->first();
        if (!$log instanceof EmailLog) {
            return;
        }

        $now = new DateTime();
        $log->status = EmailLogConstants::STATUS_SENT;
        $log->attempts = $log->attempts + 1;
        $log->last_attempt_at = $now;
        $log->sent_at = $now;
        $log->last_error = null;

        $this->emailLogsTable->save($log);
    }

    /**
     * Marca una fila como fallida. attempts++, last_error truncado.
     * No-op si $id=0.
     */
    public function markFailed(int $id, string $error): void
    {
        if ($id <= 0) {
            return;
        }

        $log = $this->emailLogsTable->find()->where(['id' => $id])->first();
        if (!$log instanceof EmailLog) {
            return;
        }

        $log->status = EmailLogConstants::STATUS_FAILED;
        $log->attempts = $log->attempts + 1;
        $log->last_attempt_at = new DateTime();
        $log->last_error = mb_substr($error, 0, EmailLogConstants::ERROR_MESSAGE_MAX_LENGTH);

        $this->emailLogsTable->save($log);
    }

    /**
     * Marca como failed cualquier fila pending huérfana (creada hace más de
     * ORPHAN_THRESHOLD_SECONDS y sin último intento reciente). Devuelve cuántas
     * filas afectó. Llamado lazy desde EmailLogsController::index y ::retry.
     */
    public function sweepOrphanPendings(): int
    {
        $cutoff = (new DateTime())->modify('-' . EmailLogConstants::ORPHAN_THRESHOLD_SECONDS . ' seconds');

        return $this->emailLogsTable->updateAll(
            [
                'status' => EmailLogConstants::STATUS_FAILED,
                'last_error' => 'Envío inconcluso (proceso interrumpido)',
                'last_attempt_at' => new DateTime(),
                'modified' => new DateTime(),
            ],
            [
                'status' => EmailLogConstants::STATUS_PENDING,
                'created <' => $cutoff,
                'OR' => [
                    'last_attempt_at IS' => null,
                    'last_attempt_at <' => $cutoff,
                ],
            ],
        );
    }

    /** Logs ordenados desc por fecha para una entidad concreta (panel inline). */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->emailLogsTable->find('forEntity', entityType: $entityType, entityId: $entityId)
            ->all()
            ->toArray();
    }

    /** Acceso a la table para callers (controller usa paginate sobre la query). */
    public function getTable(): EmailLogsTable
    {
        return $this->emailLogsTable;
    }
}
```

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check src/Service/EmailLogService.php
```

Esperado: 0 errores. Si hay, `composer cs-fix src/Service/EmailLogService.php`.

- [ ] **Step 3: Commit**

```bash
git add src/Service/EmailLogService.php
git commit -m "feat(email-log): add EmailLogService base operations

Plan 2 — recordPending/markSent/markFailed/sweepOrphanPendings/forEntity.
retry y retryAllFailed se añaden en tareas posteriores junto con la UI."
```

---

## Task 3: Integrar bitácora en `NotificationService`

**Files:**
- Modify: `src/Service/NotificationService.php`

- [ ] **Step 1: Reemplazar `src/Service/NotificationService.php` completo**

Sustituir todo el contenido del archivo por:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmailLogConstants;
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
    private EmailLogService $emailLogService;

    public function __construct(
        ?SystemSettingsService $settings = null,
        ?MailerInterface $mailer = null,
        ?EmailLogService $emailLogService = null,
    ) {
        $this->settings = $settings ?? new SystemSettingsService();
        $this->mailer = $mailer ?? new CakeMailerAdapter($this->settings);
        $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
        $this->emailLogService = $emailLogService ?? new EmailLogService();
    }

    /**
     * Envía link de aprobación de factura. Registra cada intento en email_logs
     * y propaga la excepción si el envío falla (a diferencia del comportamiento
     * histórico, que la tragaba con Log::error).
     */
    public function sendApprovalLinkNotification(
        Invoice $invoice,
        string $approvalUrl,
        ?int $approverUserId = null,
        ?int $createdBy = null,
    ): void {
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

            $this->deliverWithLog(
                eventType:  EmailLogConstants::EVENT_INVOICE_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_INVOICE,
                entityId:   (int)$invoice->id,
                to:         $recipient->email,
                subject:    $subject,
                template:   'invoice_approval_request',
                viewVars:   $viewVars,
                layout:     'default',
                createdBy:  $createdBy,
            );

            Log::info("Approval link sent to {$recipient->email} for invoice #{$invoice->id}");
        }
    }

    /**
     * Envía link de aprobación de novedad. Registra cada intento y propaga
     * excepciones (cambio: antes se tragaban).
     */
    public function sendNoveltyApprovalEmail(
        object $approver,
        object $novelty,
        string $approvalUrl,
        ?int $createdBy = null,
    ): void {
        $smtpConfig = $this->settings->getGroup('smtp');

        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            throw new Exception('SMTP no configurado. Configure el correo en Ajustes del Sistema.');
        }

        if (empty($approver->email)) {
            throw new Exception('El aprobador asignado no tiene correo electrónico configurado.');
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

        $this->deliverWithLog(
            eventType:  EmailLogConstants::EVENT_NOVELTY_APPROVAL_REQUEST,
            entityType: EmailLogConstants::ENTITY_NOVELTY,
            entityId:   (int)$novelty->id,
            to:         $approver->email,
            subject:    $subject,
            template:   'novelty_approval_request',
            viewVars:   $viewVars,
            layout:     'default',
            createdBy:  $createdBy,
        );

        Log::info("Novelty approval link sent to {$approver->email} for novelty #{$novelty->id}");
    }

    /**
     * Punto de entrada "raw" usado por EmailLogService::retry — no resuelve
     * recipient ni viewVars; recibe el envío ya armado y solo lo entrega vía
     * CircuitBreaker + MailerInterface, actualizando la fila $logId.
     */
    public function deliverRaw(
        int $logId,
        string $to,
        string $subject,
        string $template,
        array $viewVars,
        string $layout = 'default',
    ): void {
        try {
            $this->smtpCircuitBreaker->call(function () use ($to, $subject, $template, $viewVars, $layout): void {
                $this->mailer->send($to, $subject, $template, $viewVars, $layout);
            });
            $this->emailLogService->markSent($logId);
        } catch (Exception $e) {
            $this->emailLogService->markFailed($logId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Núcleo común: recordPending → CB.call(send) → markSent o markFailed → throw.
     */
    private function deliverWithLog(
        string $eventType,
        ?string $entityType,
        ?int $entityId,
        string $to,
        string $subject,
        string $template,
        array $viewVars,
        string $layout,
        ?int $createdBy,
    ): void {
        $logId = $this->emailLogService->recordPending(
            eventType:  $eventType,
            entityType: $entityType,
            entityId:   $entityId,
            toEmail:    $to,
            subject:    $subject,
            template:   $template,
            payload:    ['viewVars' => $viewVars, 'layout' => $layout],
            createdBy:  $createdBy,
        );

        try {
            $this->smtpCircuitBreaker->call(function () use ($to, $subject, $template, $viewVars, $layout): void {
                $this->mailer->send($to, $subject, $template, $viewVars, $layout);
            });
            $this->emailLogService->markSent($logId);
        } catch (Exception $e) {
            $this->emailLogService->markFailed($logId, $e->getMessage());
            throw $e;
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

- [ ] **Step 2: Verificar code style**

```bash
composer cs-check src/Service/NotificationService.php
```

Esperado: 0 errores.

- [ ] **Step 3: Commit**

```bash
git add src/Service/NotificationService.php
git commit -m "feat(email-log): integrate email log into NotificationService

Cada envío crea fila pending → marca sent o failed → propaga excepción.
Nuevo método deliverRaw() para que EmailLogService::retry pueda reusar
el CircuitBreaker. testSmtpConnection sigue sin loguear (diagnóstico)."
```

---

## Task 4: Que los callers dejen de tragar excepciones

**Files:**
- Modify: `src/Service/InvoiceApprovalService.php`
- Modify: `src/Controller/EmployeeNoveltiesController.php`

- [ ] **Step 1: Modificar `InvoiceApprovalService::assignApprovers`**

En `src/Service/InvoiceApprovalService.php`, sustituir el bloque de las líneas 73-80 (incluido el `Log::error` que se traga la excepción):

**Antes:**
```php
            // Send notification email
            $approvalUrl = $baseUrl . '/approve/' . $token;
            try {
                $this->notificationService->sendApprovalLinkNotification($invoice, $approvalUrl, (int)$userId);
            } catch (Exception $e) {
                Log::error("Approval email failed for user {$userId}: " . $e->getMessage());
            }
```

**Después:**
```php
            // Send notification email
            $approvalUrl = $baseUrl . '/approve/' . $token;
            try {
                $this->notificationService->sendApprovalLinkNotification(
                    $invoice,
                    $approvalUrl,
                    (int)$userId,
                    $createdByUserId,
                );
            } catch (Exception $e) {
                $errors[] = sprintf(
                    'Aprobador asignado, pero el correo a usuario ID %d falló: %s. Puede reintentar desde el panel de notificaciones de la factura.',
                    (int)$userId,
                    $e->getMessage(),
                );
            }
```

Notar:
- Se pasa `$createdByUserId` como cuarto argumento (ya disponible en la firma del método).
- Se agrega al array `$errors` que el método ya retorna como parte de `compact('success', 'errors', 'approvals')`.
- `$success = empty($errors)` ya existe — un fallo de correo dejará `success=false`, lo cual es intencionado: la asignación se persistió, pero el flash mostrará el error.
- El `Log::error` desaparece — la fila en `email_logs` ya guarda el mensaje de error.

- [ ] **Step 2: Modificar `EmployeeNoveltiesController` líneas 638-642 (acción `add`)**

En `src/Controller/EmployeeNoveltiesController.php`, localizar el bloque alrededor de la línea 641:

**Antes:**
```php
                    $approver = $approversTable->get($novelty->approver_id);
                    if ($approver && !empty($approver->email)) {
                        $this->notificationService->sendNoveltyApprovalEmail($approver, $noveltyForEmail, $approvalUrl);
                    }
```

**Después:**
```php
                    $approver = $approversTable->get($novelty->approver_id);
                    if ($approver && !empty($approver->email)) {
                        try {
                            $this->notificationService->sendNoveltyApprovalEmail(
                                $approver,
                                $noveltyForEmail,
                                $approvalUrl,
                                (int)$user->id,
                            );
                        } catch (\Exception $e) {
                            $this->Flash->warning(__(
                                'Novedad creada, pero el correo de aprobación falló: {0}. Puede reintentar desde la página de la novedad.',
                                $e->getMessage(),
                            ));
                        }
                    }
```

- [ ] **Step 3: Modificar `EmployeeNoveltiesController` línea 788 (acción `resendApproval`)**

Mismo archivo, localizar el bloque cerca de la línea 788:

**Antes:**
```php
        $approver = $approversTable->get($novelty->approver_id);
        if ($approver && !empty($approver->email)) {
            $this->notificationService->sendNoveltyApprovalEmail($approver, $novelty, $approvalUrl);
        }

        $this->Flash->success('Enlace de aprobación reenviado al aprobador (válido por 48h).');
```

**Después:**
```php
        $approver = $approversTable->get($novelty->approver_id);
        if ($approver && !empty($approver->email)) {
            try {
                $this->notificationService->sendNoveltyApprovalEmail(
                    $approver,
                    $novelty,
                    $approvalUrl,
                    (int)$user->id,
                );
                $this->Flash->success('Enlace de aprobación reenviado al aprobador (válido por 48h).');
            } catch (\Exception $e) {
                $this->Flash->error(__(
                    'No se pudo reenviar el correo de aprobación: {0}. Puede reintentar desde la página de la novedad.',
                    $e->getMessage(),
                ));
            }
        } else {
            $this->Flash->warning('El aprobador asignado no tiene correo electrónico configurado.');
        }
```

Notar: el flash de éxito se mueve dentro del `try` (solo se muestra si el correo realmente salió). Se elimina el flash de éxito al final del bloque que tenían antes.

- [ ] **Step 4: Verificar code style**

```bash
composer cs-check src/Service/InvoiceApprovalService.php src/Controller/EmployeeNoveltiesController.php
```

- [ ] **Step 5: Validación manual rápida**

Levantar el servidor:

```bash
php bin/cake server
```

En el navegador:

1. Loguear como Administrador. Ir a una factura existente y asignar aprobadores con SMTP correctamente configurado. Esperado: flash verde "Aprobadores asignados", correo recibido. Verificar en MySQL: `SELECT id, status, attempts FROM email_logs ORDER BY id DESC LIMIT 5;` → fila con `status='sent'`, `attempts=1`.

2. En "Configuración del Sistema" cambiar `smtp_host` a `smtp.invalid.local` y guardar. Asignar un aprobador a otra factura. Esperado: flash que **incluye** el mensaje de error y dice "Puede reintentar desde el panel de notificaciones". Verificar en DB: nueva fila con `status='failed'`, `last_error` con el mensaje SMTP. Restaurar SMTP correcto.

- [ ] **Step 6: Commit**

```bash
git add src/Service/InvoiceApprovalService.php src/Controller/EmployeeNoveltiesController.php
git commit -m "fix(email-log): stop swallowing SMTP exceptions in callers

W8 — InvoiceApprovalService::assignApprovers ahora agrega el error al
array errors del ServiceResult; EmployeeNoveltiesController muestra
flash::warning/error en vez del Log::error silencioso.

createdBy se propaga al NotificationService para trazabilidad."
```

---

## Task 5: Registrar el módulo y crear migración seed de permisos

**Files:**
- Modify: `src/Service/AuthorizationService.php`
- Modify: `src/Controller/AppController.php`
- Create: `config/Migrations/20260501150100_SeedEmailLogsPermissions.php`

- [ ] **Step 1: Agregar el módulo a `AuthorizationService::MODULES`**

En `src/Service/AuthorizationService.php`, dentro del array `MODULES` (línea 15-42), agregar al final (antes del cierre `]`):

```php
        'email_logs' => 'Logs de correo',
```

El array completo queda con `'email_logs' => 'Logs de correo'` como última entrada.

- [ ] **Step 2: Agregar el controller al `controllerModuleMap` de `AppController`**

En `src/Controller/AppController.php`, dentro del array `$controllerModuleMap` (línea 27-58), agregar al final (antes del cierre `]`):

```php
        'EmailLogs' => 'email_logs',
```

- [ ] **Step 3: Mapear las acciones nuevas a permisos en `_actionToPermission`**

En el mismo archivo, modificar `_actionToPermission` (línea 63-72). Localizar la rama de `'edit'`:

**Antes:**
```php
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion' => 'edit',
```

**Después** (agregar `'retry', 'retryAllFailed'` antes del `=> 'edit'`):
```php
            'edit', 'advanceStatus', 'regressStatus', 'addObservation', 'testSmtp', 'approve', 'reject', 'deactivate', 'saveFields', 'removeInvoice', 'advance', 'advanceGroup', 'addSignature', 'assignLiquidation', 'getFlags', 'authorizePayment', 'rejectPayment', 'editPayment', 'sendApprovalLinks', 'modifyApprovers', 'resetFlow', 'upload', 'linkInvoices', 'unlinkInvoice', 'uploadRelationDocument', 'markSigned', 'markExact', 'registerShortage', 'registerSurplus', 'confirmShortage', 'registerRefund', 'moveToRevision', 'returnToValidacion', 'retry', 'retryAllFailed' => 'edit',
```

- [ ] **Step 4: Generar migración seed de permisos**

```bash
php bin/cake migrations create SeedEmailLogsPermissions
mv config/Migrations/$(ls -t config/Migrations/ | head -1) config/Migrations/20260501150100_SeedEmailLogsPermissions.php
```

- [ ] **Step 5: Reemplazar contenido de la migración seed**

Sustituir todo `config/Migrations/20260501150100_SeedEmailLogsPermissions.php` por:

```php
<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class SeedEmailLogsPermissions extends BaseMigration
{
    /**
     * Solo Administrador. El permiso inline desde invoices/edit y
     * employee_novelties/edit reusa los permisos de esas entidades —
     * la validación se hace dentro de EmailLogsController::retry.
     */
    private const MATRIX = [
        'Administrador' => ['view' => 1, 'create' => 0, 'edit' => 1, 'delete' => 0],
    ];

    public function up(): void
    {
        foreach (self::MATRIX as $roleName => $perms) {
            $row = $this->fetchRow("SELECT id FROM roles WHERE name = '" . addslashes($roleName) . "'");
            if (!$row) {
                continue;
            }
            $roleId = $row['id'] ?? $row[0];

            $existing = $this->fetchRow(
                "SELECT id FROM permissions WHERE role_id = $roleId AND module = 'email_logs'"
            );
            if ($existing) {
                continue;
            }

            $this->execute(
                "INSERT INTO permissions (role_id, module, can_view, can_create, can_edit, can_delete, created, modified)
                 VALUES ($roleId, 'email_logs', {$perms['view']}, {$perms['create']}, {$perms['edit']}, {$perms['delete']}, NOW(), NOW())"
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM permissions WHERE module = 'email_logs'");
    }
}
```

- [ ] **Step 6: Aplicar y verificar**

```bash
php bin/cake migrations migrate
```

Verificar:

```sql
-- En MySQL/MariaDB:
SELECT p.module, p.can_view, p.can_edit, r.name AS role
FROM permissions p
JOIN roles r ON r.id = p.role_id
WHERE p.module = 'email_logs';
```

Esperado: 1 fila con `Administrador` / `can_view=1` / `can_edit=1`.

- [ ] **Step 7: Code style**

```bash
composer cs-check src/Service/AuthorizationService.php src/Controller/AppController.php config/Migrations/20260501150100_SeedEmailLogsPermissions.php
```

- [ ] **Step 8: Commit**

```bash
git add src/Service/AuthorizationService.php \
        src/Controller/AppController.php \
        config/Migrations/20260501150100_SeedEmailLogsPermissions.php
git commit -m "feat(email-log): register module + seed Administrador permission

email_logs en AuthorizationService::MODULES, EmailLogs en
AppController::controllerModuleMap, retry/retryAllFailed mapeadas
a edit, migración seed para Administrador."
```

---

## Task 6: Panel inline en facturas y novedades

**Files:**
- Create: `templates/element/email_log_panel.php`
- Modify: `src/Controller/InvoicesController.php` (acción `edit`)
- Modify: `src/Controller/EmployeeNoveltiesController.php` (acción `edit`)
- Modify: `templates/Invoices/edit.php`
- Modify: `templates/EmployeeNovelties/edit.php`

- [ ] **Step 1: Crear el element reusable**

Crear `templates/element/email_log_panel.php`:

```php
<?php
/**
 * Panel inline de logs de correo para una entidad.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmailLog[] $emailLogs
 */

use App\Constants\EmailLogConstants;

if (empty($emailLogs)) {
    return;
}

$now = new \Cake\I18n\DateTime();
$orphanThreshold = EmailLogConstants::ORPHAN_THRESHOLD_SECONDS;
?>
<div class="card mt-3 sgi-email-log-panel">
    <div class="card-header d-flex align-items-center">
        <i class="bi bi-envelope-paper me-2"></i>
        <strong>Notificaciones de correo</strong>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Destinatario</th>
                    <th>Estado</th>
                    <th>Último intento</th>
                    <th>Intentos</th>
                    <th class="text-end">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($emailLogs as $log) : ?>
                    <?php
                    $statusBadge = match ($log->status) {
                        EmailLogConstants::STATUS_SENT    => 'bg-success',
                        EmailLogConstants::STATUS_FAILED  => 'bg-danger',
                        EmailLogConstants::STATUS_PENDING => 'bg-warning text-dark',
                        default                           => 'bg-secondary',
                    };
                    $statusIcon = match ($log->status) {
                        EmailLogConstants::STATUS_SENT    => 'bi-check-circle',
                        EmailLogConstants::STATUS_FAILED  => 'bi-x-circle',
                        EmailLogConstants::STATUS_PENDING => 'bi-hourglass-split',
                        default                           => 'bi-question-circle',
                    };

                    $isOrphanPending = $log->status === EmailLogConstants::STATUS_PENDING
                        && $log->created !== null
                        && $log->created->diffInSeconds($now) > $orphanThreshold;

                    $showRetry = $log->status === EmailLogConstants::STATUS_FAILED || $isOrphanPending;
                    $lastAttempt = $log->last_attempt_at ?? $log->created;
                    ?>
                    <tr>
                        <td><?= h($log->to_email) ?></td>
                        <td>
                            <span class="badge <?= $statusBadge ?>">
                                <i class="bi <?= $statusIcon ?>"></i>
                                <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                            </span>
                            <?php if ($log->status === EmailLogConstants::STATUS_FAILED && !empty($log->last_error)) : ?>
                                <div class="text-danger small mt-1">
                                    <i class="bi bi-exclamation-triangle me-1"></i><?= h($log->last_error) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= $lastAttempt ? h($lastAttempt->i18nFormat('yyyy-MM-dd HH:mm')) : '—' ?></td>
                        <td><?= (int)$log->attempts ?></td>
                        <td class="text-end">
                            <?php if ($showRetry) : ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-arrow-clockwise me-1"></i>Reintentar',
                                    ['controller' => 'EmailLogs', 'action' => 'retry', $log->id],
                                    [
                                        'class' => 'btn btn-sm btn-outline-primary',
                                        'escape' => false,
                                        'confirm' => '¿Reenviar este correo?',
                                    ],
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 2: Cargar logs en `InvoicesController::edit`**

Localizar la acción `edit` en `src/Controller/InvoicesController.php` (buscar `public function edit`). Al final del método, justo antes del último `$this->set(...)` que pasa variables a la vista, agregar:

```php
        // Email logs para el panel inline (Plan 2 — W8)
        $emailLogService = new \App\Service\EmailLogService();
        $this->set('emailLogs', $emailLogService->forEntity('invoice', (int)$invoice->id));
```

Si la acción `edit` ya tiene varios `$this->set` agrupados, agregar la variable al final con la misma forma. La línea exacta depende del código actual; agregar antes del `return` o cierre del método y después de que `$invoice` esté cargado.

- [ ] **Step 3: Cargar logs en `EmployeeNoveltiesController::edit`**

Mismo patrón en `src/Controller/EmployeeNoveltiesController.php`, dentro de la acción `edit`. Agregar antes del cierre del método, una vez que `$novelty` está cargado:

```php
        // Email logs para el panel inline (Plan 2 — W8)
        $emailLogService = new \App\Service\EmailLogService();
        $this->set('emailLogs', $emailLogService->forEntity('employee_novelty', (int)$novelty->id));
```

- [ ] **Step 4: Insertar el element en `templates/Invoices/edit.php`**

Abrir `templates/Invoices/edit.php`. Localizar la sección de aprobadores (buscar literal `aprobadores` o `approvers` en el archivo). Inmediatamente después del cierre de esa sección (después del `</div>` que cierra la card/section de aprobadores), agregar:

```php
<?= $this->element('email_log_panel', ['emailLogs' => $emailLogs ?? []]) ?>
```

Si la sección de aprobadores no se identifica fácilmente, alternativa: agregar al final del archivo, antes del cierre del último `</div>` del contenedor principal del formulario.

- [ ] **Step 5: Insertar el element en `templates/EmployeeNovelties/edit.php`**

Abrir `templates/EmployeeNovelties/edit.php`. Localizar el bloque del aprobador asignado (buscar `approver` o `aprobador`). Inmediatamente después del cierre del bloque, agregar:

```php
<?= $this->element('email_log_panel', ['emailLogs' => $emailLogs ?? []]) ?>
```

Alternativa si no se identifica: al final del archivo antes del último `</div>` del contenedor principal.

- [ ] **Step 6: Validación manual**

Levantar el servidor (`php bin/cake server`).

1. Como cualquier rol con `invoices.can_edit`, abrir una factura que ya tenga aprobadores asignados (las que se probaron en Task 4 sirven). Esperado: aparece el panel "Notificaciones de correo" con las filas previas (Sent o Failed según corresponda); el botón Reintentar **NO** funciona aún (la acción `retry` se implementa en Task 8) — el postLink lleva a 404 o a un error de "Action not found". Eso está bien por ahora.

2. Para una factura sin envíos previos, el panel **no se renderiza** (return temprano cuando `$emailLogs` está vacío).

3. Repetir con una novedad que tenga su correo enviado.

- [ ] **Step 7: Code style**

```bash
composer cs-check templates/element/email_log_panel.php \
                  src/Controller/InvoicesController.php \
                  src/Controller/EmployeeNoveltiesController.php \
                  templates/Invoices/edit.php \
                  templates/EmployeeNovelties/edit.php
```

- [ ] **Step 8: Commit**

```bash
git add templates/element/email_log_panel.php \
        src/Controller/InvoicesController.php \
        src/Controller/EmployeeNoveltiesController.php \
        templates/Invoices/edit.php \
        templates/EmployeeNovelties/edit.php
git commit -m "feat(email-log): inline panel on invoice and novelty edit pages

Element reusable email_log_panel muestra destinatario, estado, último
intento e intentos. Botón Reintentar visible en failed y pending
huérfano (la acción retry se cablea en una tarea posterior)."
```

---

## Task 7: Vista global `/email-logs` (admin)

**Files:**
- Create: `src/Controller/EmailLogsController.php`
- Create: `templates/EmailLogs/index.php`
- Modify: `templates/layout/default.php` (sidebar)

- [ ] **Step 1: Crear `EmailLogsController` con la acción `index`**

Crear `src/Controller/EmailLogsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\EmailLogConstants;
use App\Service\EmailLogService;

/**
 * Bitácora de envíos de correo (Plan 2 — W8). Solo Administrador.
 *
 * Las acciones retry y retryAllFailed se agregan en tareas posteriores.
 */
class EmailLogsController extends AppController
{
    private EmailLogService $emailLogService;

    public function initialize(): void
    {
        parent::initialize();
        $this->emailLogService = new EmailLogService();
    }

    public function index(): void
    {
        // Sweep lazy de huérfanos antes de listar.
        $this->emailLogService->sweepOrphanPendings();

        $table = $this->emailLogService->getTable();

        $query = $table->find();

        // Filtros
        $status = (string)$this->request->getQuery('status', '');
        if ($status !== '' && in_array($status, EmailLogConstants::STATUSES, true)) {
            $query = $query->where(['EmailLogs.status' => $status]);
        }

        $eventType = (string)$this->request->getQuery('event_type', '');
        if ($eventType !== '' && array_key_exists($eventType, EmailLogConstants::EVENT_LABELS)) {
            $query = $query->where(['EmailLogs.event_type' => $eventType]);
        }

        $from = (string)$this->request->getQuery('from', '');
        if ($from !== '') {
            $query = $query->where(['EmailLogs.created >=' => $from . ' 00:00:00']);
        }

        $to = (string)$this->request->getQuery('to', '');
        if ($to !== '') {
            $query = $query->where(['EmailLogs.created <=' => $to . ' 23:59:59']);
        }

        $email = (string)$this->request->getQuery('email', '');
        if ($email !== '') {
            $query = $query->where(['EmailLogs.to_email LIKE' => '%' . $email . '%']);
        }

        $query = $query->orderBy(['EmailLogs.created' => 'DESC']);

        $this->paginate = ['limit' => 15, 'maxLimit' => 15];
        $emailLogs = $this->paginate($query);

        $this->set(compact('emailLogs', 'status', 'eventType', 'from', 'to', 'email'));
        $this->set('statusOptions', EmailLogConstants::STATUS_LABELS);
        $this->set('eventOptions', EmailLogConstants::EVENT_LABELS);
    }
}
```

- [ ] **Step 2: Crear `templates/EmailLogs/index.php`**

```php
<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\ResultSetInterface<\App\Model\Entity\EmailLog> $emailLogs
 * @var string $status
 * @var string $eventType
 * @var string $from
 * @var string $to
 * @var string $email
 * @var array<string,string> $statusOptions
 * @var array<string,string> $eventOptions
 */

use App\Constants\EmailLogConstants;

$this->assign('title', 'Logs de correo');
?>
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-3">
        <h2 class="me-auto mb-0">
            <i class="bi bi-envelope-exclamation me-2"></i>Logs de correo
        </h2>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'class' => 'row g-2 align-items-end']) ?>
                <div class="col-md-2">
                    <?= $this->Form->control('status', [
                        'type' => 'select',
                        'label' => 'Estado',
                        'empty' => 'Todos',
                        'options' => $statusOptions,
                        'value' => $status,
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('event_type', [
                        'type' => 'select',
                        'label' => 'Tipo',
                        'empty' => 'Todos',
                        'options' => $eventOptions,
                        'value' => $eventType,
                        'class' => 'form-select',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('from', [
                        'type' => 'text',
                        'label' => 'Desde',
                        'value' => $from,
                        'class' => 'form-control flatpickr-date',
                        'placeholder' => 'YYYY-MM-DD',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('to', [
                        'type' => 'text',
                        'label' => 'Hasta',
                        'value' => $to,
                        'class' => 'form-control flatpickr-date',
                        'placeholder' => 'YYYY-MM-DD',
                    ]) ?>
                </div>
                <div class="col-md-2">
                    <?= $this->Form->control('email', [
                        'type' => 'text',
                        'label' => 'Destinatario',
                        'value' => $email,
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i></button>
                </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Destinatario</th>
                        <th>Asunto</th>
                        <th>Estado</th>
                        <th>Intentos</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($emailLogs->count() === 0) : ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Sin resultados.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($emailLogs as $log) : ?>
                        <?php
                        $statusBadge = match ($log->status) {
                            EmailLogConstants::STATUS_SENT    => 'bg-success',
                            EmailLogConstants::STATUS_FAILED  => 'bg-danger',
                            EmailLogConstants::STATUS_PENDING => 'bg-warning text-dark',
                            default                           => 'bg-secondary',
                        };
                        ?>
                        <tr>
                            <td><?= (int)$log->id ?></td>
                            <td><?= h($log->created->i18nFormat('yyyy-MM-dd HH:mm')) ?></td>
                            <td><?= h(EmailLogConstants::EVENT_LABELS[$log->event_type] ?? $log->event_type) ?></td>
                            <td><?= h($log->to_email) ?></td>
                            <td><?= h($log->subject) ?></td>
                            <td>
                                <span class="badge <?= $statusBadge ?>">
                                    <?= h(EmailLogConstants::STATUS_LABELS[$log->status] ?? $log->status) ?>
                                </span>
                                <?php if ($log->status === EmailLogConstants::STATUS_FAILED && !empty($log->last_error)) : ?>
                                    <div class="text-danger small mt-1"><?= h(mb_substr($log->last_error, 0, 200)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$log->attempts ?></td>
                            <td class="text-end">
                                <?php if ($log->status === EmailLogConstants::STATUS_FAILED) : ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-arrow-clockwise"></i>',
                                        ['action' => 'retry', $log->id],
                                        [
                                            'class' => 'btn btn-sm btn-outline-primary',
                                            'escape' => false,
                                            'confirm' => '¿Reenviar este correo?',
                                            'title' => 'Reintentar',
                                        ],
                                    ) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small">
            <?= $this->Paginator->counter('Página {{page}} de {{pages}} — {{count}} registros') ?>
        </div>
        <ul class="pagination mb-0">
            <?= $this->Paginator->prev('« Anterior') ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next('Siguiente »') ?>
        </ul>
    </div>
</div>
```

- [ ] **Step 3: Agregar entrada al sidebar**

En `templates/layout/default.php`, localizar el bloque de "Administración" (líneas 507-542 aproximadamente). Justo después del bloque de "Configuración" (líneas 533-541), antes del `<?php endif; ?>` que cierra `$adminItems`, agregar:

**Buscar este patrón existente:**
```php
                <?php
                $adminItems = array_filter([
                    $canView('users') ? 'users' : null,
                    $canView('roles') ? 'roles' : null,
                    $canView('system_settings') ? 'system_settings' : null,
                ]);
                if (!empty($adminItems)) : ?>
```

**Reemplazar por:**
```php
                <?php
                $adminItems = array_filter([
                    $canView('users') ? 'users' : null,
                    $canView('roles') ? 'roles' : null,
                    $canView('system_settings') ? 'system_settings' : null,
                    $canView('email_logs') ? 'email_logs' : null,
                ]);
                if (!empty($adminItems)) : ?>
```

Y luego, **después** del bloque que renderiza "Configuración" (después de su `<?php endif; ?>`) y **antes** del `<?php endif; ?>` que cierra `if (!empty($adminItems))`, insertar:

```php
                    <?php if ($canView('email_logs')) : ?>
                <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="bi bi-envelope-exclamation me-2"></i>Logs de correo',
                            ['controller' => 'EmailLogs', 'action' => 'index'],
                            ['class' => $navLink('EmailLogs'), 'escape' => false],
                        ) ?>
                </li>
                    <?php endif; ?>
```

- [ ] **Step 4: Validación manual**

Levantar el servidor (`php bin/cake server`).

1. Como Administrador: el sidebar muestra "Logs de correo" bajo "Administración". Click → `/email-logs`. Esperado: lista paginada con los registros previos. Filtros funcionales: probar filtrar por `status=failed` (debe mostrar solo los rojos), por rango de fechas, y por destinatario.

2. Como rol no-Administrador (ej. Tesorería): el sidebar **no** muestra la entrada. Visitar `/email-logs` directamente: redirige al dashboard (regla de `_enforcePermission()`).

3. El botón "Reintentar" en filas failed lleva a 404 — la acción se cablea en Task 8.

- [ ] **Step 5: Code style**

```bash
composer cs-check src/Controller/EmailLogsController.php \
                  templates/EmailLogs/index.php \
                  templates/layout/default.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Controller/EmailLogsController.php \
        templates/EmailLogs/index.php \
        templates/layout/default.php
git commit -m "feat(email-log): admin index page /email-logs with filters

Filtros: status, event_type, rango de fechas, destinatario.
Sweep de huérfanos lazy al cargar. Sidebar entry bajo Administración
visible solo con email_logs.can_view (admin)."
```

---

## Task 8: Reintento individual

**Files:**
- Modify: `src/Service/EmailLogService.php` (agregar `retry`)
- Modify: `src/Controller/EmailLogsController.php` (agregar acción `retry`)

- [ ] **Step 1: Agregar `retry()` a `EmailLogService`**

En `src/Service/EmailLogService.php`, dentro de la clase, después del método `sweepOrphanPendings()`, agregar:

```php
    /**
     * Reintenta una fila concreta. Carga, marca status=pending mientras intenta,
     * delega el envío en NotificationService::deliverRaw que actualiza el log
     * (sent o failed) según resultado. Devuelve ServiceResult.
     *
     * Antes de operar sobre la fila, si la fila es pending huérfana, se sweepea.
     */
    public function retry(int $id, ?NotificationService $notificationService = null): ServiceResult
    {
        $this->sweepOrphanPendings();

        $log = $this->emailLogsTable->find()->where(['id' => $id])->first();
        if (!$log instanceof EmailLog) {
            return ServiceResult::fail('No se encontró el registro de correo.');
        }

        if ($log->status === EmailLogConstants::STATUS_SENT) {
            return ServiceResult::fail('Este correo ya fue enviado.');
        }

        $payload = $log->payload ?? [];
        $viewVars = $payload['viewVars'] ?? [];
        $layout = $payload['layout'] ?? 'default';

        $notificationService = $notificationService ?? new NotificationService();

        try {
            $notificationService->deliverRaw(
                logId:    (int)$log->id,
                to:       $log->to_email,
                subject:  $log->subject,
                template: $log->template,
                viewVars: is_array($viewVars) ? $viewVars : [],
                layout:   is_string($layout) ? $layout : 'default',
            );
        } catch (\Exception $e) {
            $this->logger->warning('Email retry failed', [
                'log_id' => (int)$log->id,
                'error' => $e->getMessage(),
            ]);

            return ServiceResult::fail('Reintento falló: ' . $e->getMessage());
        }

        return ServiceResult::ok('Correo reenviado exitosamente.');
    }
```

**Importante:** asegurar que el `use` al inicio del archivo incluya:

```php
use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
use App\Model\Table\EmailLogsTable;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
```

(Si ya están desde Task 2, no duplicar.)

- [ ] **Step 2: Agregar acción `retry` al `EmailLogsController`**

En `src/Controller/EmailLogsController.php`, agregar dentro de la clase (después del método `index`):

```php
    /**
     * Reenvía un correo concreto. Permisos:
     *   - Si el log no tiene entity_id (smtp_test futuro u otros): solo Administrador.
     *   - Si pertenece a una factura: requiere invoices.can_edit.
     *   - Si pertenece a una novedad: requiere employee_novelties.can_edit.
     *   - Administrador siempre puede (bypass del AuthorizationService).
     */
    public function retry(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        if ($id === null) {
            $this->Flash->error('ID inválido.');

            return $this->redirect(['action' => 'index']);
        }

        $logRow = $this->emailLogService->getTable()
            ->find()
            ->where(['id' => (int)$id])
            ->first();

        if (!$logRow) {
            $this->Flash->error('No se encontró el registro de correo.');

            return $this->redirect(['action' => 'index']);
        }

        if (!$this->_canRetry($logRow)) {
            $this->Flash->error('No tiene permiso para reintentar este correo.');

            return $this->redirect($this->request->referer() ?: ['action' => 'index']);
        }

        $result = $this->emailLogService->retry((int)$id);

        if ($result->success) {
            $this->Flash->success($result->data ?? 'Correo reenviado.');
        } else {
            $this->Flash->error($result->errors[0] ?? 'Reintento falló.');
        }

        return $this->redirect($this->request->referer() ?: ['action' => 'index']);
    }

    /**
     * Verifica si el usuario actual puede reintentar la fila $logRow.
     * Reusa los permisos de la entidad (invoices.can_edit / employee_novelties.can_edit)
     * para respetar el contexto del panel inline.
     */
    private function _canRetry(\App\Model\Entity\EmailLog $logRow): bool
    {
        $user = $this->Authentication->getIdentity()?->getOriginalData();
        if (!$user) {
            return false;
        }

        // Admin pasa siempre.
        $roleName = $this->_getUserRoleName($user);
        if ($roleName === \App\Service\AuthorizationService::ROLE_ADMIN) {
            return true;
        }

        // Resto: depende de la entidad.
        $authorizationService = new \App\Service\AuthorizationService();
        $roleId = (int)($user->role_id ?? 0);

        if ($logRow->entity_type === \App\Constants\EmailLogConstants::ENTITY_INVOICE) {
            return $authorizationService->isAllowed($roleId, $roleName, 'invoices', 'edit');
        }

        if ($logRow->entity_type === \App\Constants\EmailLogConstants::ENTITY_NOVELTY) {
            return $authorizationService->isAllowed($roleId, $roleName, 'employee_novelties', 'edit');
        }

        // Sin entity_type → solo admin (que ya retornó arriba).
        return false;
    }
```

- [ ] **Step 3: Validación manual**

Levantar el servidor.

1. **Caso happy:** romper SMTP, asignar aprobador a una factura → falla; restaurar SMTP. Como admin ir a `/email-logs`, click en Reintentar de la fila failed. Esperado: flash verde "Correo reenviado exitosamente", la fila pasa a `sent`, `attempts=2`, el correo llega al inbox.

2. **Reintento desde panel inline:** abrir la página de edición de la factura del paso anterior. El panel muestra la fila como `sent`. Romper SMTP, click en Reintentar de otra fila failed (si existe) → debe respetar el permiso del usuario actual.

3. **Permiso denegado:** loguear como rol no-Administrador con `invoices.can_edit`. Desde la página de edición de una factura, click en Reintentar → debe funcionar (mismo permiso). Pero ir a `/email-logs` directamente → redirige (no tiene `email_logs.can_view`).

4. **CB abierto:** romper SMTP, asignar 4 aprobadores rápidamente. Las primeras 3 generan filas `failed` con error real; la 4° genera fila `failed` con error de circuit breaker. Esperar 5 min o resetear cache. Click Reintentar en la fila CB → debería volver a probar SMTP. (Esperado, no estricto.)

- [ ] **Step 4: Code style**

```bash
composer cs-check src/Service/EmailLogService.php src/Controller/EmailLogsController.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Service/EmailLogService.php src/Controller/EmailLogsController.php
git commit -m "feat(email-log): individual retry from inline panel and admin view

EmailLogService::retry usa NotificationService::deliverRaw para pasar
por el CircuitBreaker. EmailLogsController::retry valida permiso según
entity_type (invoices.can_edit / employee_novelties.can_edit) o admin."
```

---

## Task 9: Reintento masivo de fallidos

**Files:**
- Modify: `src/Service/EmailLogService.php` (agregar `retryAllFailed`)
- Modify: `src/Controller/EmailLogsController.php` (agregar acción `retryAllFailed`)
- Modify: `templates/EmailLogs/index.php` (botón con modal)

- [ ] **Step 1: Agregar `retryAllFailed()` al servicio**

En `src/Service/EmailLogService.php`, después del método `retry()`, agregar:

```php
    /**
     * Reintenta hasta RETRY_BATCH_LIMIT filas con status=failed. Devuelve
     * el conteo {success, failed, skipped} para el flash del controller.
     *
     * Si se desea procesar más filas, el admin puede pulsar el botón otra
     * vez; no hay paginación de procesamiento ni progress bar.
     */
    public function retryAllFailed(?NotificationService $notificationService = null): array
    {
        $this->sweepOrphanPendings();

        $notificationService = $notificationService ?? new NotificationService();

        $failedLogs = $this->emailLogsTable->find('failed')
            ->orderBy(['EmailLogs.created' => 'ASC'])
            ->limit(EmailLogConstants::RETRY_BATCH_LIMIT)
            ->all();

        $stats = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($failedLogs as $log) {
            $result = $this->retry((int)$log->id, $notificationService);
            if ($result->success) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }
```

- [ ] **Step 2: Agregar acción `retryAllFailed` al controller**

En `src/Controller/EmailLogsController.php`, agregar después de `_canRetry`:

```php
    /**
     * Reintento masivo. Solo Administrador (gated por _enforcePermission con
     * email_logs.can_edit, que solo está asignado al admin).
     */
    public function retryAllFailed()
    {
        $this->request->allowMethod(['post']);

        $stats = $this->emailLogService->retryAllFailed();

        if ($stats['success'] === 0 && $stats['failed'] === 0) {
            $this->Flash->info('No había correos fallidos para reintentar.');
        } else {
            $msg = sprintf(
                'Reintentos: %d exitosos, %d fallidos.',
                $stats['success'],
                $stats['failed'],
            );
            if ($stats['failed'] > 0) {
                $this->Flash->warning($msg);
            } else {
                $this->Flash->success($msg);
            }
        }

        return $this->redirect(['action' => 'index']);
    }
```

- [ ] **Step 3: Agregar el botón al template global**

En `templates/EmailLogs/index.php`, modificar el header. Localizar:

```php
    <div class="d-flex align-items-center mb-3">
        <h2 class="me-auto mb-0">
            <i class="bi bi-envelope-exclamation me-2"></i>Logs de correo
        </h2>
    </div>
```

Reemplazar por:

```php
    <div class="d-flex align-items-center mb-3">
        <h2 class="me-auto mb-0">
            <i class="bi bi-envelope-exclamation me-2"></i>Logs de correo
        </h2>
        <?= $this->Form->postLink(
            '<i class="bi bi-arrow-clockwise me-1"></i>Reintentar todos los fallidos',
            ['action' => 'retryAllFailed'],
            [
                'class' => 'btn btn-warning',
                'escape' => false,
                'confirm' => '¿Reintentar todos los correos fallidos? Se procesarán hasta 100 por click.',
            ],
        ) ?>
    </div>
```

- [ ] **Step 4: Validación manual end-to-end**

Levantar el servidor.

1. **Validación manual completa del Plan 2 (smoke test final):**

   a. Romper SMTP (host inválido en Configuración).
   b. Crear 3 facturas distintas y asignar aprobadores → 3 filas `failed`.
   c. Restaurar SMTP correcto.
   d. Como Administrador, ir a `/email-logs`, filtrar `status=failed` → ver las 3 filas.
   e. Click "Reintentar todos los fallidos" → confirmar.
   f. Esperado: flash verde "Reintentos: 3 exitosos, 0 fallidos". Filas en `sent`, `attempts=2`, correos recibidos.

2. **Cero fallidos:** sin filas failed, click "Reintentar todos los fallidos" → flash info "No había correos fallidos para reintentar."

3. **Pending huérfano:** insertar manualmente vía consola DB:

   ```sql
   INSERT INTO email_logs
     (event_type, to_email, subject, template, payload, status, attempts, created, modified)
   VALUES
     ('invoice_approval_request', 'orphan@test.local', 'Test orphan',
      'invoice_approval_request', '{"viewVars":{},"layout":"default"}',
      'pending', 0, '2024-01-01 00:00:00', '2024-01-01 00:00:00');
   ```

   Recargar `/email-logs`. Esperado: la fila ahora aparece como `failed` con `last_error='Envío inconcluso (proceso interrumpido)'` (el sweep la transformó).

4. **Test SMTP no se loguea:** click en "Probar conexión SMTP" en Configuración. Verificar `SELECT count(*) FROM email_logs WHERE event_type='smtp_test';` → 0 (ese flujo no genera log).

- [ ] **Step 5: Code style**

```bash
composer cs-check src/Service/EmailLogService.php \
                  src/Controller/EmailLogsController.php \
                  templates/EmailLogs/index.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/EmailLogService.php \
        src/Controller/EmailLogsController.php \
        templates/EmailLogs/index.php
git commit -m "feat(email-log): mass retry of failed entries (admin only)

EmailLogService::retryAllFailed itera hasta RETRY_BATCH_LIMIT filas
y devuelve stats. Botón en /email-logs con modal de confirmación,
gated por email_logs.can_edit (admin)."
```

---

## Task 10: Cierre — actualizar roadmap y validación final

**Files:**
- Modify: `docs/audits/architecture-audit-roadmap.md`

- [ ] **Step 1: Actualizar la tabla de estado del roadmap**

En `docs/audits/architecture-audit-roadmap.md`, localizar la tabla "Tabla de estado":

**Antes:**
```markdown
| 2 | Email Audit Log + Reintento manual (W8) | 🟡 En progreso | [spec](../superpowers/specs/2026-05-01-email-log-design.md) | — | — | — |
```

**Después** (los placeholders del PR # y la fecha se completan al cerrar el PR; agregar el plan):

```markdown
| 2 | Email Audit Log + Reintento manual (W8) | 🟢 Completado | [spec](../superpowers/specs/2026-05-01-email-log-design.md) | [plan](../superpowers/plans/2026-05-01-email-log-plan.md) | <PR#> | <YYYY-MM-DD> |
```

Reemplazar `<PR#>` y `<YYYY-MM-DD>` con los valores reales al hacer merge del PR.

- [ ] **Step 2: Actualizar el resumen ejecutivo si todavía dice 🟡**

En la misma tabla del resumen ejecutivo (al inicio del archivo), cambiar el estado del Plan 2 de 🟡 a 🟢.

**Antes:**
```markdown
| 2 | Email Audit Log + Reintento manual *(pivot, ver "Cambios al roadmap" 2026-05-01)* | W8 | S (4–6 días) | — | 🟡 En progreso |
```

**Después:**
```markdown
| 2 | Email Audit Log + Reintento manual *(pivot, ver "Cambios al roadmap" 2026-05-01)* | W8 | S (4–6 días) | — | 🟢 Completado |
```

- [ ] **Step 3: Commit del cierre del plan**

```bash
git add docs/audits/architecture-audit-roadmap.md
git commit -m "docs(roadmap): mark plan 2 (email audit log) as completed"
```

- [ ] **Step 4: Validación final consolidada**

Recorrer la sección "Validación manual" del spec ([`docs/superpowers/specs/2026-05-01-email-log-design.md`](../specs/2026-05-01-email-log-design.md), 10 escenarios) y confirmar que todos pasan en el navegador. Si alguno falla, abrir issue interno y rebajar a 🟡 + nota en "Cambios al roadmap" describiendo lo no cubierto.

---

## Notas de implementación

**Orden de ejecución:** los tasks están ordenados por dependencia. Cada task termina con un commit y deja el sistema en un estado coherente, aunque algunos no produzcan valor visible al usuario hasta el siguiente task (ej. Task 1 deja la DB lista pero nadie la usa hasta Task 3).

**Ramas:** el usuario indicó trabajar en `main` directamente. Los commits van a `main` con conventional commits (`feat(email-log):`, `fix(email-log):`, `docs:`).

**Sin tests automatizados:** política del proyecto. Cada task tiene una sección de "Validación manual" cuando aplica. `composer cs-check` se corre antes de cada commit.

**Manejo de errores:** todos los `try/catch` capturan `\Exception` (no `\Throwable`) — coherente con el patrón ya existente en `WebhookService` y `NotificationService`. Errores críticos del propio `EmailLogService` (fallo al persistir el log) se loguean vía `StructuredLogger` y devuelven 0 — el caller continúa con el envío en lugar de bloquear.

**Locales/i18n:** todos los strings visibles al usuario van en español (convención del proyecto). `__()` se usa donde ya está en uso (templates y flashes).

**Permisos del retry inline:** `EmailLogsController::retry` resuelve el permiso según `entity_type` de la fila — esto significa que un usuario con `invoices.can_edit` puede reintentar correos de **cualquier** factura (no solo "las suyas"). Coherente con cómo funciona `assignApprovers` hoy: si tienes can_edit sobre el módulo, puedes operar sobre cualquier factura del módulo. Si más adelante se requiere autorización por dueño, se aborda en una iteración posterior.
