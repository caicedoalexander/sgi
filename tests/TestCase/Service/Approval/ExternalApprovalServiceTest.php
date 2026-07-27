<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Approval;

use App\Service\Approval\ConsumeContext;
use App\Service\Approval\ExternalApprovalService;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para ExternalApprovalService — consumo de aprobación externa por token.
 *
 * Cubre las invariantes del consumo del token genérico single-entity:
 *  - resolve(): rechaza token inexistente, ya usado y expirado; acepta el vigente.
 *  - consume(): rechaza inexistente/usado/expirado; consume el vigente bajo TX
 *    (marca used_at + action_taken) delegando en la Strategy; falla en silencio si el
 *    entity_type no tiene Strategy registrada.
 *  - getEntity(): delega en la Strategy o devuelve null si el tipo es desconocido.
 *
 * Suite pura (sin DB): TableLocator con un mock de ApprovalTokens cuyo find() devuelve
 * un SelectQuery mockeado (where/epilog/first encadenados) y cuyo transactional()
 * ejecuta el callback en memoria. Las Strategies se mockean para verificar la delegación.
 */
final class ExternalApprovalServiceTest extends TestCase
{
    private InvoiceApprovalStrategy $invoiceStrategy;
    private NoveltyApprovalStrategy $noveltyStrategy;
    private ?Entity $record = null;
    private ?Entity $savedEntity = null;
    private bool $saveSucceeds = true;

    protected function setUp(): void
    {
        $this->invoiceStrategy = $this->createMock(InvoiceApprovalStrategy::class);
        $this->noveltyStrategy = $this->createMock(NoveltyApprovalStrategy::class);
        $this->record = null;
        $this->savedEntity = null;
        $this->saveSucceeds = true;
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): ExternalApprovalService
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'epilog', 'first'])
            ->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('epilog')->willReturnSelf();
        $query->method('first')->willReturnCallback(fn() => $this->record);

        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'newEntity', 'save', 'getConnection'])
            ->getMock();
        $table->method('find')->willReturn($query);
        $table->method('newEntity')->willReturnCallback(fn(array $data) => new Entity($data));
        $table->method('getConnection')->willReturn($connection);
        $table->method('save')->willReturnCallback(function ($entity) {
            $this->savedEntity = $entity;

            return $this->saveSucceeds ? $entity : false;
        });

        $locator = new TableLocator();
        $locator->set('ApprovalTokens', $table);
        TableRegistry::setTableLocator($locator);

        return new ExternalApprovalService($this->invoiceStrategy, $this->noveltyStrategy);
    }

    // --- resolve ---

    public function testResolveReturnsNullWhenNotFound(): void
    {
        $this->record = null;
        $service = $this->buildService();

        $this->assertNull($service->resolve('missing'));
    }

    public function testResolveReturnsNullWhenAlreadyUsed(): void
    {
        $this->record = new Entity([
            'used_at' => new DateTime('-1 hour'),
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $service = $this->buildService();

        $this->assertNull($service->resolve('used'));
    }

    public function testResolveReturnsNullWhenExpired(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('-1 hour'),
        ]);
        $service = $this->buildService();

        $this->assertNull($service->resolve('expired'));
    }

    public function testResolveReturnsRecordWhenValid(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $service = $this->buildService();

        $this->assertSame($this->record, $service->resolve('valid'));
    }

    // --- consume ---

    public function testConsumeReturnsFalseWhenAlreadyUsed(): void
    {
        $this->record = new Entity([
            'used_at' => new DateTime('-1 hour'),
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $this->invoiceStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $this->assertFalse($service->consume('used', 'approve', new ConsumeContext()));
    }

    public function testConsumeReturnsFalseWhenExpired(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('-1 hour'),
        ]);
        $this->invoiceStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $this->assertFalse($service->consume('expired', 'approve', new ConsumeContext()));
    }

    public function testConsumeMarksRecordAndDelegatesToStrategy(): void
    {
        $this->record = new Entity([
            'entity_type' => 'invoices',
            'entity_id' => 5,
            'used_at' => null,
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $this->invoiceStrategy->expects($this->once())
            ->method('apply')
            ->with(5, 'approve', 'ok', 7, '2026-06-02')
            ->willReturn(true);
        $this->noveltyStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $context = new ConsumeContext('ok', '1.2.3.4', 'agent', '2026-06-02', 7);
        $result = $service->consume('valid', 'approve', $context);

        $this->assertTrue($result);
        $this->assertInstanceOf(DateTime::class, $this->record->used_at);
        $this->assertSame('approve', $this->record->action_taken);
        $this->assertSame('1.2.3.4', $this->record->ip_address);
    }

    public function testConsumeReturnsFalseWhenNoStrategyForEntityType(): void
    {
        $this->record = new Entity([
            'entity_type' => 'unknown',
            'entity_id' => 5,
            'used_at' => null,
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $this->invoiceStrategy->expects($this->never())->method('apply');
        $this->noveltyStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $this->assertFalse($service->consume('valid', 'approve', new ConsumeContext()));
    }

    public function testConsumeReturnsFalseWhenStrategyFails(): void
    {
        $this->record = new Entity([
            'entity_type' => 'invoices',
            'entity_id' => 5,
            'used_at' => null,
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $this->invoiceStrategy->expects($this->once())
            ->method('apply')
            ->willReturn(false);

        $service = $this->buildService();

        $this->assertFalse($service->consume('valid', 'approve', new ConsumeContext()));
    }

    // --- getEntity ---

    public function testGetEntityDelegatesToStrategy(): void
    {
        $entity = new Entity(['id' => 5]);
        $this->invoiceStrategy->expects($this->once())
            ->method('getEntity')
            ->with(5)
            ->willReturn($entity);

        $service = $this->buildService();

        $this->assertSame($entity, $service->getEntity('invoices', 5));
    }

    public function testGetEntityReturnsNullForUnknownType(): void
    {
        $service = $this->buildService();

        $this->assertNull($service->getEntity('unknown', 5));
    }
}
