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
