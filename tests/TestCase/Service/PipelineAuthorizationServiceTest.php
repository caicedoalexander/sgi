<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Service\PipelineAuthorizationService;
use Cake\ORM\Entity;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PipelineAuthorizationService — permisos por (rol, pipeline, step).
 *
 * Foco de seguridad:
 *  - canOperate(): true solo para pares (pipeline, step) concedidos; false ante
 *    step sin conceder o pipeline desconocido (deny by default).
 *  - getOperableSteps(): devuelve solo los steps concedidos, en el orden del
 *    catálogo STEPS_BY_PIPELINE.
 *  - getEmptyMatrix(): estructura completa con todo en false, sin tocar BD.
 *  - getPermissionsMatrix(): refleja concedidos y rellena el resto con false.
 *  - Cache per-request e invalidación.
 *
 * Suite pura (sin DB): TableLocator con un mock de PipelinePermissions cuyo
 * find()->where()->all() devuelve un arreglo de filas controlado por el test.
 */
final class PipelineAuthorizationServiceTest extends TestCase
{
    /**
     * @var array<\Cake\ORM\Entity>
     */
    private array $rows = [];

    protected function setUp(): void
    {
        $this->rows = [];
    }

    protected function tearDown(): void
    {
        TableRegistry::getTableLocator()->clear();
        parent::tearDown();
    }

    private function buildService(): PipelineAuthorizationService
    {
        $query = $this->getMockBuilder(SelectQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'all'])
            ->getMock();
        $query->method('where')->willReturnSelf();
        $query->method('all')->willReturnCallback(fn() => new ResultSet($this->rows));

        $table = $this->getMockBuilder(Table::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $table->method('find')->willReturn($query);

        $locator = new TableLocator();
        $locator->set('PipelinePermissions', $table);
        TableRegistry::setTableLocator($locator);

        return new PipelineAuthorizationService();
    }

    private function permissionRow(string $pipeline, string $step, bool $canOperate): Entity
    {
        return new Entity([
            'pipeline' => $pipeline,
            'step' => $step,
            'can_operate' => $canOperate,
        ]);
    }

    public function testCanOperateReturnsTrueForGrantedStep(): void
    {
        $this->rows = [
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
                true,
            ),
        ];
        $service = $this->buildService();

        $this->assertTrue($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_TESORERIA,
        ));
    }

    public function testCanOperateReturnsFalseForUngrantedStep(): void
    {
        $this->rows = [
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
                false,
            ),
        ];
        $service = $this->buildService();

        // Step con can_operate=false y step ausente: ambos deniegan.
        $this->assertFalse($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_TESORERIA,
        ));
        $this->assertFalse($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_CONTABILIDAD,
        ));
    }

    public function testCanOperateReturnsFalseForUnknownPipeline(): void
    {
        $this->rows = [];
        $service = $this->buildService();

        $this->assertFalse($service->canOperate(3, 'does_not_exist', 'whatever'));
    }

    public function testGetOperableStepsReturnsGrantedInCatalogOrder(): void
    {
        // Concedidos fuera de orden; el resultado debe seguir el orden del catálogo.
        $this->rows = [
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
                true,
            ),
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_APROBACION,
                true,
            ),
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_CONTABILIDAD,
                false,
            ),
        ];
        $service = $this->buildService();

        $steps = $service->getOperableSteps(3, PipelineStepConstants::PIPELINE_INVOICES);

        $this->assertSame(
            [InvoiceConstants::STATUS_APROBACION, InvoiceConstants::STATUS_TESORERIA],
            $steps,
        );
    }

    public function testGetEmptyMatrixHasAllStepsFalse(): void
    {
        $service = $this->buildService();

        $matrix = $service->getEmptyMatrix();

        $this->assertSame(
            array_keys(PipelineStepConstants::STEPS_BY_PIPELINE),
            array_keys($matrix),
        );
        foreach ($matrix as $steps) {
            foreach ($steps as $granted) {
                $this->assertFalse($granted);
            }
        }
    }

    public function testGetPermissionsMatrixReflectsGrantsAndDefaults(): void
    {
        $this->rows = [
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_APROBACION,
                true,
            ),
        ];
        $service = $this->buildService();

        $matrix = $service->getPermissionsMatrix(3);

        $this->assertTrue($matrix[PipelineStepConstants::PIPELINE_INVOICES][InvoiceConstants::STATUS_APROBACION]);
        $this->assertFalse($matrix[PipelineStepConstants::PIPELINE_INVOICES][InvoiceConstants::STATUS_TESORERIA]);
    }

    public function testCachesAndInvalidates(): void
    {
        $this->rows = [
            $this->permissionRow(
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_APROBACION,
                true,
            ),
        ];
        $service = $this->buildService();

        $this->assertTrue($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        ));

        // Sin invalidar, la cache mantiene el permiso aunque la fuente cambie.
        $this->rows = [];
        $this->assertTrue($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        ));

        // Tras invalidar, se recarga desde la fuente (ahora vacía).
        $service->invalidate(3);
        $this->assertFalse($service->canOperate(
            3,
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        ));
    }
}
