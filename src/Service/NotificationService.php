<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Invoice;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;

class NotificationService
{
    private SystemSettingsService $settings;

    public function __construct()
    {
        $this->settings = new SystemSettingsService();
    }

    public function sendStatusChangeNotification(Invoice $invoice, string $fromStatus, string $toStatus): void
    {
        $smtpConfig = $this->settings->getGroup('smtp');

        // Skip if SMTP not configured
        if (empty($smtpConfig['smtp_host']) || empty($smtpConfig['smtp_from_email'])) {
            return;
        }

        // When advancing to 'aprobacion', notify the assigned approver
        if ($toStatus === 'aprobacion' && !empty($invoice->approver_id)) {
            $recipients = $this->getApproverRecipient($invoice->approver_id);
        } else {
            $recipients = $this->getRecipientsForStatus($toStatus);
        }

        if (empty($recipients)) {
            return;
        }

        $this->configureTransport($smtpConfig);

        $statusLabels = InvoicePipelineService::STATUS_LABELS;
        $fromLabel = $statusLabels[$fromStatus] ?? $fromStatus;
        $toLabel = $statusLabels[$toStatus] ?? $toStatus;
        $invoiceNumber = $invoice->invoice_number ?: '#' . $invoice->id;

        foreach ($recipients as $recipient) {
            try {
                $mailer = new Mailer();
                $mailer->setTransport('sgi_dynamic');
                $mailer->setFrom(
                    $smtpConfig['smtp_from_email'],
                    $smtpConfig['smtp_from_name'] ?? 'SGI'
                );
                $mailer->setTo($recipient->email);
                $mailer->setSubject("SGI - Factura {$invoiceNumber} avanzó a {$toLabel}");
                $mailer->setEmailFormat('html');
                $mailer->setViewVars([
                    'invoiceNumber' => $invoiceNumber,
                    'fromLabel' => $fromLabel,
                    'toLabel' => $toLabel,
                    'invoiceId' => $invoice->id,
                ]);
                $mailer->viewBuilder()
                    ->setTemplate('invoice_status_changed')
                    ->setLayout('default');
                $mailer->deliver();
            } catch (\Exception $e) {
                // Log but don't block
                \Cake\Log\Log::warning("Email notification failed for {$recipient->email}: " . $e->getMessage());
            }
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

    private function getRecipientsForStatus(string $toStatus): array
    {
        $roleMapping = $this->getStatusRoleMapping();
        $roleName = $roleMapping[$toStatus] ?? null;

        if (!$roleName) {
            return [];
        }

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        return $usersTable->find()
            ->contain(['Roles'])
            ->matching('Roles', function ($q) use ($roleName) {
                return $q->where(['Roles.name' => $roleName]);
            })
            ->where(['Users.active' => true])
            ->all()
            ->toArray();
    }

    private function getStatusRoleMapping(): array
    {
        return [
            'registro' => 'Registro/Revisión',
            'aprobacion' => 'Contabilidad',
            'contabilidad' => 'Contabilidad',
            'tesoreria' => 'Tesorería',
            'pagada' => null,
        ];
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
                $smtpConfig['smtp_from_name'] ?? 'SGI'
            );
            $mailer->setTo($smtpConfig['smtp_from_email'] ?? 'test@test.com');
            $mailer->setSubject('SGI - Prueba de conexión SMTP');
            $mailer->deliver('Este es un correo de prueba del SGI.');

            return ['success' => true, 'message' => 'Conexión SMTP exitosa. Correo de prueba enviado.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
