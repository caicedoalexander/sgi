<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\EmailLogConstants;
use App\Model\Entity\EmailLog;
use App\Model\Table\EmailLogsTable;
use App\Service\EmailLogService;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Seguridad (Ola 6): markSent purga viewVars del payload tras un envío exitoso,
 * para no dejar el secreto del token de aprobación (approvalUrl) en reposo en
 * email_logs. Los correos no enviados conservan el payload para retry().
 */
final class EmailLogServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildServiceWithLog(EmailLog $log): EmailLogService
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()->onlyMethods(['where', 'first'])->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn($log);

        $table = $this->getMockBuilder(EmailLogsTable::class)
            ->disableOriginalConstructor()->onlyMethods(['find', 'save'])->getMock();
        $table->method('find')->willReturn($query);
        $table->method('save')->willReturnCallback(fn($e) => $e);

        $locator = new TableLocator();
        $locator->set('EmailLogs', $table);
        TableRegistry::setTableLocator($locator);

        return new EmailLogService(fn() => null);
    }

    public function testMarkSentPurgesViewVarsFromPayload(): void
    {
        $log = new EmailLog([
            'id' => 1,
            'status' => EmailLogConstants::STATUS_PENDING,
            'attempts' => 0,
            'payload' => [
                'viewVars' => ['approvalUrl' => 'https://sgi.test/approve/' . str_repeat('a', 64), 'foo' => 'bar'],
                'layout' => 'default',
            ],
        ]);

        $service = $this->buildServiceWithLog($log);
        $service->markSent(1);

        $this->assertSame(EmailLogConstants::STATUS_SENT, $log->status);
        $this->assertIsArray($log->payload);
        $this->assertArrayNotHasKey('viewVars', $log->payload, 'viewVars (con el secreto) debe purgarse tras enviar');
        $this->assertSame('default', $log->payload['layout'] ?? null, 'metadatos no sensibles se conservan');
    }

    public function testMarkSentWithoutViewVarsIsNoOpOnPayload(): void
    {
        $log = new EmailLog([
            'id' => 2,
            'status' => EmailLogConstants::STATUS_FAILED,
            'attempts' => 1,
            'payload' => ['layout' => 'default'],
        ]);

        $service = $this->buildServiceWithLog($log);
        $service->markSent(2);

        $this->assertSame(EmailLogConstants::STATUS_SENT, $log->status);
        $this->assertSame(['layout' => 'default'], $log->payload);
    }
}
