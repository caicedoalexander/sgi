<?php
declare(strict_types=1);

namespace App\Service\Interface;

interface MailerInterface
{
    /**
     * Send an email.
     *
     * @param string $to Recipient email address.
     * @param string $subject Email subject.
     * @param string $template Template name.
     * @param array $viewVars Template variables.
     * @param string $layout Layout name.
     * @return void
     */
    public function send(
        string $to,
        string $subject,
        string $template,
        array $viewVars,
        string $layout = 'default',
    ): void;
}
