<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Invoice;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;
use Exception;

class NotificationService
{
    private SystemSettingsService $settings;
    private CircuitBreaker $smtpCircuitBreaker;

    public function __construct(?SystemSettingsService $settings = null)
    {
        $this->settings = $settings ?? new SystemSettingsService();
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

        $this->configureTransport($smtpConfig);
        $invoiceNumber = $invoice->invoice_number ?: '#' . $invoice->id;

        foreach ($recipients as $recipient) {
            if (empty($recipient->email)) {
                throw new Exception("El aprobador '{$recipient->full_name}' no tiene correo electrónico configurado.");
            }

            $mailer = new Mailer();
            $mailer->setTransport('sgi_dynamic');
            $mailer->setFrom(
                $smtpConfig['smtp_from_email'],
                $smtpConfig['smtp_from_name'] ?? 'SGI',
            );
            $mailer->setTo($recipient->email);
            $mailer->setSubject("SGI-COPCSA - Solicitud de Aprobación: Factura {$invoiceNumber}");
            $mailer->setEmailFormat('html');
            $mailer->setViewVars([
                'invoiceNumber' => $invoiceNumber,
                'providerName' => $invoice->provider->name ?? '—',
                'amount' => $invoice->amount,
                'approvalUrl' => $approvalUrl,
                'recipientName' => $recipient->full_name ?? $recipient->username ?? '',
            ]);
            $mailer->viewBuilder()
                ->setTemplate('invoice_approval_request')
                ->setLayout('default');
            $this->smtpCircuitBreaker->call(function () use ($mailer): void {
                $mailer->deliver();
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

        $this->configureTransport($smtpConfig);

        $employeeName = $novelty->custom_name ?? ($novelty->employee->full_name ?? '—');
        $noveltyTypeName = $novelty->novelty_type->name ?? '—';

        try {
            $mailer = new Mailer();
            $mailer->setTransport('sgi_dynamic');
            $mailer->setFrom(
                $smtpConfig['smtp_from_email'],
                $smtpConfig['smtp_from_name'] ?? 'SGI',
            );
            $mailer->setTo($approver->email);
            $mailer->setSubject("SGI-COPCSA - Solicitud de Aprobación: Novedad de {$employeeName}");
            $mailer->setEmailFormat('html');
            $mailer->setViewVars([
                'employeeName' => $employeeName,
                'noveltyTypeName' => $noveltyTypeName,
                'reason' => $novelty->reason ?? '',
                'approvalUrl' => $approvalUrl,
                'recipientName' => $approver->full_name ?? $approver->username ?? '',
            ]);
            $mailer->viewBuilder()
                ->setTemplate('novelty_approval_request')
                ->setLayout('default');
            $this->smtpCircuitBreaker->call(function () use ($mailer): void {
                $mailer->deliver();
            });

            Log::info("Novelty approval link sent to {$approver->email} for novelty #{$novelty->id}");
        } catch (Exception $e) {
            Log::error("Novelty approval email failed for {$approver->email}: " . $e->getMessage());
        }
    }

    private function configureTransport(array $smtpConfig): void
    {
        $config = [
            'host' => $smtpConfig['smtp_host'],
            'port' => (int)($smtpConfig['smtp_port'] ?? 587),
            'username' => $smtpConfig['smtp_username'] ?? '',
            'password' => $smtpConfig['smtp_password'] ?? '',
            'className' => 'Smtp',
        ];

        if (!empty($smtpConfig['smtp_encryption'])) {
            $config['tls'] = $smtpConfig['smtp_encryption'] === 'tls';
            if ($smtpConfig['smtp_encryption'] === 'ssl') {
                $config['port'] = (int)($smtpConfig['smtp_port'] ?? 465);
                $config['tls'] = false;
                $config['host'] = 'ssl://' . $smtpConfig['smtp_host'];
            }
        }

        // Drop and recreate to allow config changes
        if (TransportFactory::getConfig('sgi_dynamic')) {
            TransportFactory::drop('sgi_dynamic');
        }
        TransportFactory::setConfig('sgi_dynamic', $config);
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

        try {
            $this->configureTransport($smtpConfig);

            $mailer = new Mailer();
            $mailer->setTransport('sgi_dynamic');
            $mailer->setFrom(
                $smtpConfig['smtp_from_email'] ?? 'test@test.com',
                $smtpConfig['smtp_from_name'] ?? 'SGI',
            );
            $mailer->setTo($smtpConfig['smtp_from_email'] ?? 'test@test.com');
            $mailer->setSubject('SGI - Prueba de conexión SMTP');
            $mailer->deliver('Este es un correo de prueba del SGI.');

            return ['success' => true, 'message' => 'Conexión SMTP exitosa. Correo de prueba enviado.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
