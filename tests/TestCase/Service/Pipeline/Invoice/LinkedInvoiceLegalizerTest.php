<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\InvoiceHistoryService;
use App\Service\Pipeline\Invoice\LinkedInvoiceLegalizer;
use Cake\Database\Connection;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests para LinkedInvoiceLegalizer — promueve a `legalizada` las facturas
 * tipo Legalización vinculadas a un Anticipo que estén en `contabilidad`.
 *
 * Suite pura (sin DB): TableLocator con un mock de Invoices cuyo
 * find()->where()->all() devuelve un ResultSet controlado y cuyo transactional()
 * ejecuta el callback en memoria. El history service se mockea.
 */
final class LinkedInvoiceLegalizerTest extends TestCase
{
    private InvoiceHistoryService $history;
    /**
     * @var array<\Cake\ORM\Entity>
     */
    private array $rows = [];
    private bool $saveSucceeds = true;

    protected function setUp(): void
    {
        $this->history = $this->createMock(InvoiceHistoryService::class);
        $this->rows = [];
        $this->saveSucceeds = true;
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildLegalizer(): LinkedInvoiceLegalizer
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'all'])
            ->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('all')->willReturnCallback(fn() => new ResultSet($this->rows));

        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find', 'save', 'getConnection'])
            ->getMock();
        $table->method('find')->willReturn($query);
        $table->method('getConnection')->willReturn($connection);
        $table->method('save')->willReturnCallback(fn($entity) => $this->saveSucceeds ? $entity : false);

        $locator = new TableLocator();
        $locator->set('Invoices', $table);
        TableRegistry::setTableLocator($locator);

        return new LinkedInvoiceLegalizer($this->history);
    }

    public function testReturnsZeroWhenNoLinkedInvoices(): void
    {
        $this->rows = [];
        $this->history->expects($this->never())->method('recordStatusChange');

        $count = $this->buildLegalizer()->legalizeFor(5, 9);

        $this->assertSame(0, $count);
    }

    public function testLegalizesLinkedInvoicesAndRecordsHistory(): void
    {
        $a = new Invoice(['id' => 11, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]);
        $b = new Invoice(['id' => 12, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD]);
        $this->rows = [$a, $b];

        $this->history->expects($this->exactly(2))
            ->method('recordStatusChange');

        $count = $this->buildLegalizer()->legalizeFor(5, 9);

        $this->assertSame(2, $count);
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $a->pipeline_status);
        $this->assertSame(InvoiceConstants::STATUS_LEGALIZADA, $b->pipeline_status);
    }

    public function testThrowsWhenSaveFails(): void
    {
        $this->rows = [new Invoice(['id' => 11, 'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD])];
        $this->saveSucceeds = false;
        $this->history->expects($this->never())->method('recordStatusChange');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se pudo promover la factura #11');

        $this->buildLegalizer()->legalizeFor(5, 9);
    }
}
