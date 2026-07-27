<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\EmailLogConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Model\Entity\Refund;
use App\Service\Interface\MailerInterface;
use Cake\ORM\TableRegistry;
use Exception;

class NotificationService
{
    private CircuitBreaker $smtpCircuitBreaker;
    private StructuredLogger $logger;

    /**
     * @param \App\Service\SystemSettingsService $settings Servicio de ajustes del sistema (config SMTP).
     * @param \App\Service\Interface\MailerInterface $mailer Adaptador de envío de correo.
     * @param \App\Service\EmailLogService $emailLogService Servicio de registro de correos enviados.
     */
    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly MailerInterface $mailer,
        private readonly EmailLogService $emailLogService,
    ) {
        $this->smtpCircuitBreaker = new CircuitBreaker('smtp', failureThreshold: 3, recoveryTimeoutSeconds: 300);
        $this->logger = new StructuredLogger('Notification');
    }

    /**
     * Envía link de aprobación de factura. Registra cada intento en email_logs
     * y propaga la excepción si el envío falla (a diferencia del comportamiento
     * histórico, que la tragaba en el log de errores).
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
        $subject = "SPI-COPCSA - Solicitud de Aprobación: Factura {$invoiceNumber}";

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

            $this->logger->info('approval_link_sent', [
                'recipient' => $recipient->email,
                'invoice_id' => $invoice->id,
                'context' => 'invoice',
            ]);
        }
    }

    /**
     * Envía el link de aprobación de un grupo (Reintegro) a un aprobador.
     * Espejo de sendApprovalLinkNotification a nivel de grupo.
     */
    public function sendRefundApprovalLinkNotification(
        Refund $refund,
        string $approvalUrl,
        int $approverUserId,
        ?int $createdBy = null,
    ): void {
        $smtpConfig = $this->settings->getGroup('smtp');
        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            throw new Exception('SMTP no configurado. Configure el correo en Ajustes del Sistema.');
        }

        $recipients = $this->getApproverRecipient($approverUserId);
        if (empty($recipients)) {
            throw new Exception('El aprobador asignado no tiene un usuario activo o no tiene correo.');
        }

        $code = $refund->code ?: '#' . $refund->id;
        $subject = "SPI-COPCSA - Solicitud de Aprobación: Reintegro {$code}";

        foreach ($recipients as $recipient) {
            if (empty($recipient->email)) {
                throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
            }

            $viewVars = [
                'refundCode' => $code,
                'beneficiaryName' => $refund->getBeneficiaryName() ?: '—',
                'amount' => $refund->total_amount,
                'approvalUrl' => $approvalUrl,
                'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
            ];

            $this->deliverWithLog(
                eventType: EmailLogConstants::EVENT_REFUND_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_REFUND,
                entityId: (int)$refund->id,
                to: $recipient->email,
                subject: $subject,
                template: 'refund_approval_request',
                viewVars: $viewVars,
                layout: 'default',
                createdBy: $createdBy,
            );

            $this->logger->info('approval_link_sent', [
                'recipient' => $recipient->email,
                'refund_id' => $refund->id,
                'context' => 'refund',
            ]);
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

        $subject = "SPI-COPCSA - Solicitud de Aprobación: Novedad de {$employeeName}";

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

        $this->logger->info('approval_link_sent', [
            'recipient' => $approver->email,
            'novelty_id' => $novelty->id,
            'context' => 'novelty',
        ]);
    }

    /**
     * Envía el link de aprobación de un grupo (Legalización de Anticipo) a un aprobador.
     * Espejo de sendRefundApprovalLinkNotification a nivel de grupo.
     */
    public function sendAdvanceLegalizationApprovalLinkNotification(
        AdvanceLegalization $leg,
        string $approvalUrl,
        int $approverUserId,
        ?int $createdBy = null,
    ): void {
        $smtpConfig = $this->settings->getGroup('smtp');
        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            throw new Exception('SMTP no configurado. Configure el correo en Ajustes del Sistema.');
        }

        $recipients = $this->getApproverRecipient($approverUserId);
        if (empty($recipients)) {
            throw new Exception('El aprobador asignado no tiene un usuario activo o no tiene correo.');
        }

        $invoices = TableRegistry::getTableLocator()->get('Invoices');
        $anticipo = $invoices->get($leg->advance_invoice_id, contain: ['Providers', 'Employees']);
        $code = $anticipo->invoice_number ?: '#' . $anticipo->id;
        $beneficiary = $anticipo->provider->name ?? ($anticipo->employee->full_name ?? '—');
        $subject = "SPI-COPCSA - Solicitud de Aprobación: Legalización de Anticipo {$code}";

        foreach ($recipients as $recipient) {
            if (empty($recipient->email)) {
                throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
            }

            $viewVars = [
                'advanceCode' => $code,
                'beneficiaryName' => $beneficiary,
                'amount' => $anticipo->amount,
                'approvalUrl' => $approvalUrl,
                'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
            ];

            $this->deliverWithLog(
                eventType: EmailLogConstants::EVENT_ADVANCE_APPROVAL_REQUEST,
                entityType: EmailLogConstants::ENTITY_ADVANCE_LEGALIZATION,
                entityId: (int)$leg->id,
                to: $recipient->email,
                subject: $subject,
                template: 'advance_approval_request',
                viewVars: $viewVars,
                layout: 'default',
                createdBy: $createdBy,
            );

            $this->logger->info('approval_link_sent', [
                'recipient' => $recipient->email,
                'advance_legalization_id' => $leg->id,
                'context' => 'advance_legalization',
            ]);
        }
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

    /**
     * Resuelve el destinatario aprobador activo como arreglo de usuarios (vacío si no existe).
     *
     * @param int $approverId Id del usuario aprobador.
     * @return array
     */
    private function getApproverRecipient(int $approverId): array
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $approver = $usersTable->find()
            ->where(['Users.id' => $approverId, 'Users.active' => true])
            ->first();

        return $approver ? [$approver] : [];
    }

    /**
     * Envía un correo de prueba para validar la conexión SMTP configurada.
     *
     * @return array
     */
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
                'SPI - Prueba de conexión SMTP',
                'smtp_test',
                [],
            );

            return ['success' => true, 'message' => 'Conexión SMTP exitosa. Correo de prueba enviado.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
