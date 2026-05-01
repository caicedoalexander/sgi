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
                eventType: EmailLogConstants::EVENT_INVOICE_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_INVOICE,
                entityId: (int)$invoice->id,
                to: $recipient->email,
                subject: $subject,
                template: 'invoice_approval_request',
                viewVars: $viewVars,
                layout: 'default',
                createdBy: $createdBy,
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
            eventType: EmailLogConstants::EVENT_NOVELTY_APPROVAL_REQUEST,
            entityType: EmailLogConstants::ENTITY_NOVELTY,
            entityId: (int)$novelty->id,
            to: $approver->email,
            subject: $subject,
            template: 'novelty_approval_request',
            viewVars: $viewVars,
            layout: 'default',
            createdBy: $createdBy,
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
            eventType: $eventType,
            entityType: $entityType,
            entityId: $entityId,
            toEmail: $to,
            subject: $subject,
            template: $template,
            payload: ['viewVars' => $viewVars, 'layout' => $layout],
            createdBy: $createdBy,
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
