<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Integration;

use App\Constants\EmailLogConstants;
use App\Model\Entity\Invoice;
use App\Service\EmailLogService;
use App\Service\Interface\MailerInterface;
use App\Service\NotificationService;
use App\Service\SystemSettingsService;
use App\Test\Factory\UserFactory;
use Cake\Cache\Cache;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Exception;

/**
 * Tests de integración (con BD) de NotificationService::sendApprovalLinkNotification.
 *
 * Ejercita el flujo real recordPending → CircuitBreaker->call(mailer->send) →
 * markSent/markFailed, persistiendo en `email_logs` y resolviendo el destinatario
 * desde la tabla `Users`. El MailerInterface se fake-a (mock) para no enviar SMTP
 * real; SystemSettingsService se stubbea para la config SMTP. El rollback por test
 * lo aplica la estrategia global (FactoryTransactionStrategy), por eso no se
 * declara `$fixtures`.
 *
 * El CircuitBreaker interno usa Cache bajo el nombre fijo 'smtp'; se limpian sus
 * claves en setUp/tearDown para aislar el estado entre tests.
 */
final class NotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El bootstrap de tests no define la cache 'default' y otros tests la dropean
        // (ver CircuitBreakerTest). El CircuitBreaker interno de NotificationService la
        // usa; configuramos un engine Array in-memory por test → estado de breaker
        // limpio y determinista, sin filtrado entre tests ni dependencia del orden.
        Cache::drop('default');
        Cache::setConfig('default', ['className' => 'Array']);
    }

    protected function tearDown(): void
    {
        Cache::drop('default');
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $smtp Config SMTP a devolver por getGroup('smtp').
     */
    private function settings(array $smtp): SystemSettingsService
    {
        $settings = $this->createStub(SystemSettingsService::class);
        $settings->method('getGroup')->willReturn($smtp);

        return $settings;
    }

    /**
     * @param array<string,mixed> $smtp Config SMTP.
     */
    private function service(MailerInterface $mailer, array $smtp = ['smtp_host' => 'smtp.test', 'smtp_from_email' => 'from@test.local']): NotificationService
    {
        // El factory de NotificationService solo lo usa EmailLogService::retry, que
        // no se ejercita en este flujo; basta un closure inerte.
        $emailLogService = new EmailLogService(static fn() => null);

        return new NotificationService($this->settings($smtp), $mailer, $emailLogService);
    }

    private function invoice(int $approverId): Invoice
    {
        $invoice = new Invoice([
            'id' => 1,
            'invoice_number' => 'F-100',
            'approver_id' => $approverId,
            'amount' => 1000.0,
        ]);
        $invoice->set('provider', new Entity(['name' => 'Proveedor X']));

        return $invoice;
    }

    public function testSendApprovalLinkPersistsSentLogAndDeliversEmail(): void
    {
        $user = UserFactory::new(['active' => true])->save();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($user->email, $this->anything(), 'invoice_approval_request', $this->anything(), 'default');

        $this->service($mailer)->sendApprovalLinkNotification(
            $this->invoice((int)$user->id),
            'https://app.test/approve/token',
            null,
            (int)$user->id,
        );

        $logs = $this->fetchTable('EmailLogs')->find()->all()->toArray();
        $this->assertCount(1, $logs);

        $log = $logs[0];
        $this->assertSame(EmailLogConstants::STATUS_SENT, $log->status);
        $this->assertSame($user->email, $log->to_email);
        $this->assertSame(EmailLogConstants::EVENT_INVOICE_APPROVAL_REQUEST, $log->event_type);
        $this->assertSame(1, $log->attempts);
        $this->assertNotNull($log->sent_at);
    }

    public function testSendApprovalLinkMarksLogFailedAndRethrowsWhenMailerThrows(): void
    {
        $user = UserFactory::new(['active' => true])->save();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new Exception('SMTP connection refused'));

        try {
            $this->service($mailer)->sendApprovalLinkNotification(
                $this->invoice((int)$user->id),
                'https://app.test/approve/token',
            );
            $this->fail('Se esperaba que la excepción del mailer se propagara.');
        } catch (Exception $e) {
            $this->assertStringContainsString('SMTP connection refused', $e->getMessage());
        }

        $log = $this->fetchTable('EmailLogs')->find()->first();
        $this->assertNotNull($log);
        $this->assertSame(EmailLogConstants::STATUS_FAILED, $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertStringContainsString('SMTP connection refused', (string)$log->last_error);
    }

    public function testThrowsWhenSmtpNotConfiguredAndWritesNoLog(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SMTP no configurado');

        try {
            $this->service($mailer, [])->sendApprovalLinkNotification($this->invoice(1), 'https://app.test/approve');
        } finally {
            $this->assertSame(0, $this->fetchTable('EmailLogs')->find()->count());
        }
    }

    public function testThrowsWhenApproverHasNoActiveUser(): void
    {
        $inactive = UserFactory::new(['active' => false])->save();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('usuario activo');

        try {
            $this->service($mailer)->sendApprovalLinkNotification(
                $this->invoice((int)$inactive->id),
                'https://app.test/approve',
            );
        } finally {
            $this->assertSame(0, $this->fetchTable('EmailLogs')->find()->count());
        }
    }

    public function testReturnsEarlyWithoutLogWhenNoApproverAssigned(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $invoice = new Invoice(['id' => 1, 'invoice_number' => 'F-100', 'amount' => 1000.0]);

        $this->service($mailer)->sendApprovalLinkNotification($invoice, 'https://app.test/approve');

        $this->assertSame(0, $this->fetchTable('EmailLogs')->find()->count());
    }
}
