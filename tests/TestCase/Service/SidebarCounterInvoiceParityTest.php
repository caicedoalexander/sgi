<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\Service\NoveltyPipelineService;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\RefundPipelineService;
use App\Service\SidebarCounterService;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\RefundFactory;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

/**
 * Los contadores del sidebar cuentan exactamente lo que su listado muestra.
 * Comparten findWithoutParent(), así que no pueden divergir.
 */
final class SidebarCounterInvoiceParityTest extends TestCase
{
    private const ROLE_ID = 201;

    public function setUp(): void
    {
        parent::setUp();
        // getCounters() cachea en el config `sidebar`, no en `default`.
        Cache::clear('sidebar');
    }

    /** @param list<string> $visibleStatuses */
    private function _service(array $visibleStatuses): SidebarCounterService
    {
        $invoicePipeline = $this->createStub(InvoicePipelineService::class);
        $invoicePipeline->method('getVisibleStatuses')->willReturn($visibleStatuses);

        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn([]);

        return new SidebarCounterService(
            $invoicePipeline,
            $this->createStub(NoveltyPipelineService::class),
            $this->createStub(PettyCashPipelineService::class),
            $this->createStub(RefundPipelineService::class),
            new AdvanceLegalizationActionPolicy($auth),
            $this->createStub(PaymentSchedulingPipelineService::class),
        );
    }

    public function testStatusCountersIgnoreInvoicesWithParent(): void
    {
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new(['refund_id' => $refund->id])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new()->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $counters = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD])
            ->getCounters(self::ROLE_ID)['sidebarCounters'];

        $this->assertSame(1, $counters[InvoiceConstants::STATUS_CONTABILIDAD]);
    }

    public function testRejectedCounterIsScopedToRoleAndIgnoresParents(): void
    {
        // Rechazada operable por el rol: cuenta.
        InvoiceFactory::new(['area_approval' => InvoiceConstants::APPROVAL_REJECTED])
            ->withStatus(InvoiceConstants::STATUS_APROBACION)->save();
        // Rechazada agrupada en un reintegro: no cuenta.
        $refund = RefundFactory::new()->save();
        InvoiceFactory::new([
            'refund_id' => $refund->id,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ])->withStatus(InvoiceConstants::STATUS_APROBACION)->save();

        $conPaso = $this->_service([InvoiceConstants::STATUS_APROBACION]);
        $this->assertSame(1, $conPaso->getCounters(self::ROLE_ID)['rejectedInvoicesCount']);

        Cache::clear('sidebar');

        // Un rol que no opera `aprobacion` no ve ninguna rechazada.
        $sinPaso = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD]);
        $this->assertSame(0, $sinPaso->getCounters(self::ROLE_ID)['rejectedInvoicesCount']);
    }

    public function testOverdueCounterExcludesDocTypesWithoutRealDueDate(): void
    {
        $ayer = date('Y-m-d', strtotime('-1 day'));

        InvoiceFactory::new(['due_date' => $ayer])
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();
        InvoiceFactory::new(['due_date' => $ayer])->legalizacion()
            ->withStatus(InvoiceConstants::STATUS_CONTABILIDAD)->save();

        $service = $this->_service([InvoiceConstants::STATUS_CONTABILIDAD]);

        $this->assertSame(1, $service->getCounters(self::ROLE_ID)['overdueInvoicesCount']);
    }
}
