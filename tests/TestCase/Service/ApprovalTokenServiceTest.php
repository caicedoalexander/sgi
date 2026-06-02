<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\ApprovalTokenService;
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
 * Unit tests para ApprovalTokenService — aprobación externa por token (seguridad).
 *
 * Cubre las invariantes de seguridad del token:
 *  - generateToken(): SHA256 hex de 64 chars, TTL por defecto de 48h (APPROVAL_TOKEN_HOURS)
 *    y TTL personalizado, persistiendo el registro.
 *  - validateToken(): rechaza token inexistente, ya usado y expirado; acepta el vigente.
 *  - consumeToken(): rechaza inexistente/usado/expirado; consume el vigente bajo TX
 *    (marca used_at + action_taken) delegando en la Strategy; falla en silencio si el
 *    entity_type no tiene Strategy registrada.
 *  - getEntity(): delega en la Strategy o devuelve null si el tipo es desconocido.
 *
 * Suite pura (sin DB): TableLocator con un mock de ApprovalTokens cuyo find() devuelve
 * un SelectQuery mockeado (where/epilog/first encadenados) y cuyo transactional()
 * ejecuta el callback en memoria. Las Strategies se mockean para verificar la delegación.
 */
final class ApprovalTokenServiceTest extends TestCase
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

    private function buildService(): ApprovalTokenService
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

        return new ApprovalTokenService($this->invoiceStrategy, $this->noveltyStrategy);
    }

    // --- generateToken ---

    public function testGenerateTokenReturnsHexTokenAndPersistsWithDefaultTtl(): void
    {
        $service = $this->buildService();

        $token = $service->generateToken('invoices', 5, 7);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertNotNull($this->savedEntity);
        $this->assertSame($token, $this->savedEntity->token);
        $this->assertSame('invoices', $this->savedEntity->entity_type);
        $this->assertSame(5, $this->savedEntity->entity_id);
        $this->assertSame(7, $this->savedEntity->created_by);

        $expected = (new DateTime('+' . InvoiceConstants::APPROVAL_TOKEN_HOURS . ' hours'))->getTimestamp();
        $this->assertEqualsWithDelta($expected, $this->savedEntity->expires_at->getTimestamp(), 60);
    }

    public function testGenerateTokenHonorsCustomValidityHours(): void
    {
        $service = $this->buildService();

        $service->generateToken('invoices', 5, 7, 2);

        $expected = (new DateTime('+2 hours'))->getTimestamp();
        $this->assertEqualsWithDelta($expected, $this->savedEntity->expires_at->getTimestamp(), 60);
    }

    // --- validateToken ---

    public function testValidateTokenReturnsNullWhenNotFound(): void
    {
        $this->record = null;
        $service = $this->buildService();

        $this->assertNull($service->validateToken('missing'));
    }

    public function testValidateTokenReturnsNullWhenAlreadyUsed(): void
    {
        $this->record = new Entity([
            'used_at' => new DateTime('-1 hour'),
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $service = $this->buildService();

        $this->assertNull($service->validateToken('used'));
    }

    public function testValidateTokenReturnsNullWhenExpired(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('-1 hour'),
        ]);
        $service = $this->buildService();

        $this->assertNull($service->validateToken('expired'));
    }

    public function testValidateTokenReturnsRecordWhenValid(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $service = $this->buildService();

        $this->assertSame($this->record, $service->validateToken('valid'));
    }

    // --- consumeToken ---

    public function testConsumeTokenReturnsFalseWhenAlreadyUsed(): void
    {
        $this->record = new Entity([
            'used_at' => new DateTime('-1 hour'),
            'expires_at' => new DateTime('+10 hours'),
        ]);
        $this->invoiceStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $this->assertFalse($service->consumeToken('used', 'approve', null, null, null));
    }

    public function testConsumeTokenReturnsFalseWhenExpired(): void
    {
        $this->record = new Entity([
            'used_at' => null,
            'expires_at' => new DateTime('-1 hour'),
        ]);
        $this->invoiceStrategy->expects($this->never())->method('apply');

        $service = $this->buildService();

        $this->assertFalse($service->consumeToken('expired', 'approve', null, null, null));
    }

    public function testConsumeTokenMarksRecordAndDelegatesToStrategy(): void
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

        $result = $service->consumeToken('valid', 'approve', 'ok', '1.2.3.4', 'agent', '2026-06-02', 7);

        $this->assertTrue($result);
        $this->assertInstanceOf(DateTime::class, $this->record->used_at);
        $this->assertSame('approve', $this->record->action_taken);
        $this->assertSame('1.2.3.4', $this->record->ip_address);
    }

    public function testConsumeTokenReturnsFalseWhenNoStrategyForEntityType(): void
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

        $this->assertFalse($service->consumeToken('valid', 'approve', null, null, null));
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
