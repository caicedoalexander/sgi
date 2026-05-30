<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Service\NoveltyHistoryService;
use App\Service\NoveltyPipelineService;
use App\Service\Pipeline\Novelty\Policy\NoveltyFieldAccessPolicy;
use Cake\Database\Connection;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para NoveltyPipelineService: `assignToExistingLiquidationDoc()` (A4)
 * y `saveAndAdvance()` (C8 — surfacing del motivo cuando no se puede avanzar).
 *
 * Suite pura (sin DB): TableLocator con mocks de NoveltyLiquidationDocs (get) y
 * EmployeeNovelties (getConnection/save); transactional() ejecuta el callback en
 * memoria. El history service se mockea para verificar la transición atómica que
 * antes vivía duplicada e inline en el controller (sin transacción).
 */
final class NoveltyPipelineServiceTest extends TestCase
{
    private NoveltyHistoryService $history;
    private bool $saveSucceeds = true;
    private Entity $doc;

    protected function setUp(): void
    {
        $this->history = $this->createMock(NoveltyHistoryService::class);
        $this->saveSucceeds = true;
        $this->doc = new Entity(['id' => 55, 'liquidation_number' => 'LIQ-26-001']);
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): NoveltyPipelineService
    {
        $docsTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $docsTable->method('get')->willReturn($this->doc);

        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['transactional'])
            ->getMock();
        $connection->method('transactional')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $noveltiesTable = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'getConnection', 'patchEntity'])
            ->getMock();
        $noveltiesTable->method('getConnection')->willReturn($connection);
        $noveltiesTable->method('save')->willReturnCallback(fn($entity) => $this->saveSucceeds ? $entity : false);
        $noveltiesTable->method('patchEntity')->willReturnCallback(function ($entity, array $data) {
            foreach ($data as $field => $value) {
                $entity->set($field, $value);
            }

            return $entity;
        });

        $locator = new TableLocator();
        $locator->set('NoveltyLiquidationDocs', $docsTable);
        $locator->set('EmployeeNovelties', $noveltiesTable);
        TableRegistry::setTableLocator($locator);

        $auth = $this->createStub(AuthorizationFacade::class);

        return new NoveltyPipelineService(
            $auth,
            new NoveltyFieldAccessPolicy($auth),
            null,
            $this->history,
        );
    }

    public function testAssignToExistingMovesToContabilidadAndRecordsHistory(): void
    {
        $this->history->expects($this->once())
            ->method('recordFieldChange')
            ->with(3, 'liquidation_doc_id', null, '55', 9);
        $this->history->expects($this->once())
            ->method('recordStatusChange')
            ->with(3, NoveltyConstants::STATUS_APROBACION, NoveltyConstants::STATUS_CONTABILIDAD, 9);

        $service = $this->buildService();
        $novelty = new EmployeeNovelty([
            'id' => 3,
            'pipeline_status' => NoveltyConstants::STATUS_APROBACION,
            'liquidation_doc_id' => null,
        ]);

        $result = $service->assignToExistingLiquidationDoc($novelty, 55, 9);

        $this->assertSame($this->doc, $result);
        $this->assertSame(55, $novelty->liquidation_doc_id);
        $this->assertSame(NoveltyConstants::STATUS_CONTABILIDAD, $novelty->pipeline_status);
    }

    public function testAssignToExistingSkipsStatusChangeWhenAlreadyContabilidad(): void
    {
        $this->history->expects($this->once())->method('recordFieldChange');
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();
        $novelty = new EmployeeNovelty([
            'id' => 3,
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'liquidation_doc_id' => 40,
        ]);

        $result = $service->assignToExistingLiquidationDoc($novelty, 55, 9);

        $this->assertSame($this->doc, $result);
    }

    public function testAssignToExistingReturnsErrorAndSkipsHistoryWhenSaveFails(): void
    {
        $this->saveSucceeds = false;
        $this->history->expects($this->never())->method('recordFieldChange');
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();
        $novelty = new EmployeeNovelty([
            'id' => 3,
            'pipeline_status' => NoveltyConstants::STATUS_APROBACION,
            'liquidation_doc_id' => null,
        ]);

        $result = $service->assignToExistingLiquidationDoc($novelty, 55, 9);

        $this->assertSame(['No se pudo asignar la novedad.'], $result);
    }

    public function testSaveAndAdvanceSurfacesWarningWhenRoleCannotOperateStep(): void
    {
        // canOperate=false (stub por defecto): el avance no ocurre. Antes se
        // omitía en silencio (C8); ahora se devuelve el motivo como warning.
        $this->history->expects($this->never())->method('recordStatusChange');

        $service = $this->buildService();
        $novelty = new EmployeeNovelty([
            'id' => 7,
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'liquidation_doc_id' => null,
        ]);

        $result = $service->saveAndAdvance($novelty, [], 4, 9);

        $this->assertTrue($result->success);
        $this->assertFalse($result->data['advanced']);
        $this->assertNull($result->data['nextStatus']);
        $this->assertContains(
            'No tiene permisos para avanzar este registro.',
            $result->data['advanceErrors'],
        );
    }
}
