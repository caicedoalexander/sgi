<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Authorization\AuthorizationFacade;
use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Service\InvoicePipelineService;
use App\Service\NoveltyPipelineService;
use App\Service\PaymentSchedulingPipelineService;
use App\Service\PettyCashPipelineService;
use App\Service\Pipeline\Advance\Policy\AdvanceLegalizationActionPolicy;
use App\Service\RefundPipelineService;
use App\Service\SidebarCounterService;
use App\Test\Factory\AdvanceLegalizationFactory;
use App\Test\Factory\InvoiceFactory;
use App\Test\Factory\ProviderFactory;
use Cake\Cache\Cache;
use Cake\TestSuite\TestCase;

/**
 * El badge "Pendientes de Legalización" cuenta solo lo que el rol puede operar,
 * igual que sus 5 contadores hermanos.
 */
class SidebarCounterLegalizationTest extends TestCase
{
    /** Ids arbitrarios: solo sirven como clave de caché, el stub ignora el rol. */
    private const ROLE_CONTABILIDAD = 101;
    private const ROLE_TESORERIA = 102;
    private const ROLE_SIN_PASOS = 103;

    public function setUp(): void
    {
        parent::setUp();
        // `getCounters()` cachea en el config `sidebar` (SidebarCounterService:57),
        // NO en `default`. Limpiar `default` aquí sería un no-op silencioso.
        Cache::clear('sidebar');
    }

    /**
     * @param array<int, string> $operableSteps Pasos que el rol puede operar.
     */
    private function _service(array $operableSteps): SidebarCounterService
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('operableSteps')->willReturn($operableSteps);

        return new SidebarCounterService(
            $this->createStub(InvoicePipelineService::class),
            $this->createStub(NoveltyPipelineService::class),
            $this->createStub(PettyCashPipelineService::class),
            $this->createStub(RefundPipelineService::class),
            new AdvanceLegalizationActionPolicy($auth),
            $this->createStub(PaymentSchedulingPipelineService::class),
        );
    }

    private function _seedLegalization(string $status): void
    {
        $provider = ProviderFactory::new()->save();
        $anticipo = InvoiceFactory::new(['provider_id' => $provider->id])->anticipo()
            ->withStatus(InvoiceConstants::STATUS_PAGADA)->save();
        AdvanceLegalizationFactory::new()->forAdvance($anticipo)->withStatus($status)->save();
    }

    public function testCountsOnlyLegalizationsOnOperableSteps(): void
    {
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);
        $this->_seedLegalization(AdvanceConstants::STATUS_TESORERIA);

        $contabilidad = $this->_service([AdvanceConstants::STATUS_CONTABILIDAD]);
        $this->assertSame(
            2,
            $contabilidad->getCounters(self::ROLE_CONTABILIDAD)['advancesPendingLegalizationCount'],
        );

        $tesoreria = $this->_service([AdvanceConstants::STATUS_TESORERIA]);
        $this->assertSame(
            1,
            $tesoreria->getCounters(self::ROLE_TESORERIA)['advancesPendingLegalizationCount'],
        );
    }

    public function testRoleWithoutOperableStepsCountsZero(): void
    {
        $this->_seedLegalization(AdvanceConstants::STATUS_CONTABILIDAD);

        $sinPasos = $this->_service([]);
        $this->assertSame(
            0,
            $sinPasos->getCounters(self::ROLE_SIN_PASOS)['advancesPendingLegalizationCount'],
        );
    }
}
