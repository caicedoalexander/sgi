<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use App\Service\CodeGeneratorService;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests para CodeGeneratorService — códigos {PREFIX}-{YY}-{CCC}-{NNNN}.
 *
 * Suite pura (sin DB): TableLocator con mocks de OperationCenters (get → centro)
 * y la tabla destino (find()->...->first() → último código). El año se deriva de
 * date('y') tanto en el SUT como en el expected (determinista dentro de la corrida).
 */
final class CodeGeneratorServiceTest extends TestCase
{
    private string $centerCode = '5';
    private ?Entity $lastRecord = null;

    protected function setUp(): void
    {
        $this->centerCode = '5';
        $this->lastRecord = null;
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(string $destinationAlias): CodeGeneratorService
    {
        $centersTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $centersTable->method('get')->willReturnCallback(fn() => new Entity(['code' => $this->centerCode]));

        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'where', 'orderBy', 'first'])
            ->getMock();
        $query->method('select')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('orderBy')->willReturnSelf();
        $query->method('first')->willReturnCallback(fn() => $this->lastRecord);

        $destinationTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $destinationTable->method('find')->willReturn($query);

        $locator = new TableLocator();
        $locator->set('OperationCenters', $centersTable);
        $locator->set($destinationAlias, $destinationTable);
        TableRegistry::setTableLocator($locator);

        return new CodeGeneratorService();
    }

    public function testGeneratesFirstCodeAndPadsCenter(): void
    {
        $this->centerCode = '5';
        $this->lastRecord = null;
        $service = $this->buildService('PettyCashRecords');

        $code = $service->generatePettyCashCode(1);

        $this->assertSame(PettyCashConstants::CODE_PREFIX . '-' . date('y') . '-005-0001', $code);
    }

    public function testIncrementsFromLastCode(): void
    {
        $this->centerCode = '5';
        $base = PettyCashConstants::CODE_PREFIX . '-' . date('y') . '-005-';
        $this->lastRecord = new Entity(['code' => $base . '0007']);
        $service = $this->buildService('PettyCashRecords');

        $code = $service->generatePettyCashCode(1);

        $this->assertSame($base . '0008', $code);
    }

    public function testThrowsWhenCenterCodeNotNumeric(): void
    {
        $this->centerCode = 'ABC';
        $service = $this->buildService('PettyCashRecords');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('debe ser numérico');

        $service->generatePettyCashCode(1);
    }

    public function testRefundUsesRefundPrefixAndTable(): void
    {
        $this->centerCode = '42';
        $this->lastRecord = null;
        $service = $this->buildService('Refunds');

        $code = $service->generateRefundCode(1);

        $this->assertSame(RefundConstants::CODE_PREFIX . '-' . date('y') . '-042-0001', $code);
    }
}
