<?php
declare(strict_types=1);

namespace App\Service\Adapter;

use App\Service\Interface\MailerInterface;
use App\Service\SystemSettingsService;
use Cake\Mailer\Mailer;
use Cake\Mailer\TransportFactory;

/**
 * Adapter that wraps CakePHP Mailer with dynamic SMTP configuration.
 */
class CakeMailerAdapter implements MailerInterface
{
    private const SMTP_TIMEOUT_SECONDS = 10;

    private bool $transportConfigured = false;

    /**
     * @param \App\Service\SystemSettingsService $settings System settings.
     */
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function send(
        string $to,
        string $subject,
        string $template,
        array $viewVars,
        string $layout = 'default',
    ): void {
        $smtpConfig = $this->settings->getGroup('smtp');
        $this->_ensureTransport($smtpConfig);

        $mailer = new Mailer();
        $mailer->setTransport('sgi_dynamic');
        $mailer->setFrom(
            $smtpConfig['smtp_from_email'],
            $smtpConfig['smtp_from_name'] ?? 'SGI',
        );
        $mailer->setTo($to);
        $mailer->setSubject($subject);
        $mailer->setEmailFormat('html');
        $mailer->setViewVars($viewVars);
        $mailer->viewBuilder()
            ->setTemplate($template)
            ->setLayout($layout);
        $mailer->deliver();
    }

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
            'timeout' => self::SMTP_TIMEOUT_SECONDS,
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
}
