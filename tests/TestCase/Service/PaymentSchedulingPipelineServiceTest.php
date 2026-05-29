<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use App\Service\InvoicePaymentService;
use App\Service\PaymentSchedulingHistoryService;
use App\Service\PaymentSchedulingPipelineService;
use Cake\Database\Connection;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PaymentSchedulingPipelineService::reject() (A3).
 *
 * Suite pura (sin DB): se inyecta un TableLocator con un mock de Table cuyo
 * getConnection()->transactional() ejecuta el callback en memoria y cuyo save()
 * se intercepta. El history service se mockea para verificar la transición
 * atómica (estado + auditoría) que antes vivía inline en el controller.
 */
final class PaymentSchedulingPipelineServiceTest extends TestCase
{
    private PaymentSchedulingHistoryService $history;
    private bool $saveSucceeds = true;

    protected function setUp(): void
    {
        $this->history = $this->createMock(PaymentSchedulingHistoryService::class);
        $this->saveSucceeds = true;
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): PaymentSchedulingPipelineService
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getConnection'])
            ->getMock();
        $table->method('getConnection')->willReturn($connection);
        $table->method('save')->willReturnCallback(fn($entity) => $this->saveSucceeds ? $entity : false);

        $locator = new TableLocator();
        $locator->set('PaymentSchedulings', $table);
        TableRegistry::setTableLocator($locator);

        return new PaymentSchedulingPipelineService(
            $this->createStub(InvoicePaymentService::class),
            $this->createStub(AuthorizationFacade::class),
            null,
            null,
            null,
            $this->history,
        );
    }

    public function testRejectMovesToTesoreriaAndRecordsHistory(): void
    {
        $this->history->expects($this->once())
            ->method('recordStatusChange')
            ->with(
                10,
                PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
                PaymentSchedulingConstants::REJECTION_TARGET,
                7,
            );

        $service = $this->buildService();
        $scheduling = new PaymentScheduling([
            'id' => 10,
            'pipeline_status' => PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
        ]);

        $result = $service->reject($scheduling, 7);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentSchedulingConstants::REJECTION_TARGET, $scheduling->pipeline_status);
        $this->assertSame(PaymentSchedulingConstants::STATUS_TESORERIA, $result->data['toStatus']);
    }

    public function testRejectFailsAndSkipsHistoryWhenSaveFails(): void
    {
        $this->saveSucceeds = false;
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();
        $scheduling = new PaymentScheduling([
            'id' => 10,
            'pipeline_status' => PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO,
        ]);

        $result = $service->reject($scheduling, 7);

        $this->assertFalse($result->success);
        $this->assertSame('No se pudo rechazar la programación.', $result->firstError());
    }
}
