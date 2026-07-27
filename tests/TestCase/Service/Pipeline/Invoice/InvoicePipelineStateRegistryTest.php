<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice;

use App\Constants\Domain\Invoice\PipelineStatus;
use App\Service\InvoicePaymentService;
use App\Service\Pipeline\Invoice\InvoicePipelineStateRegistry;
use App\Service\Pipeline\Invoice\State\AprobacionState;
use App\Service\Pipeline\Invoice\State\AutorizacionPagoState;
use App\Service\Pipeline\Invoice\State\ContabilidadState;
use App\Service\Pipeline\Invoice\State\LegalizadaState;
use App\Service\Pipeline\Invoice\State\PagadaState;
use App\Service\Pipeline\Invoice\State\TesoreriaState;
use App\Service\Pipeline\Invoice\State\VerificacionPagoState;
use PHPUnit\Framework\TestCase;

final class InvoicePipelineStateRegistryTest extends TestCase
{
    private function makeRegistry(): InvoicePipelineStateRegistry
    {
        $payment = $this->createStub(InvoicePaymentService::class);

        return new InvoicePipelineStateRegistry(
            new AprobacionState(),
            new ContabilidadState(),
            new TesoreriaState($payment),
            new AutorizacionPagoState($payment),
            new VerificacionPagoState(),
            new PagadaState(),
            new LegalizadaState(),
        );
    }

    public function testRegistryContainsAllSevenStates(): void
    {
        $registry = $this->makeRegistry();
        $this->assertCount(7, $registry->all());
    }

    public function testKeysMatchEnumValues(): void
    {
        $registry = $this->makeRegistry();
        $keys = array_keys($registry->all());
        sort($keys);
        $expected = array_map(fn(PipelineStatus $s) => $s->value, PipelineStatus::cases());
        sort($expected);
        $this->assertSame($expected, $keys);
    }

    public function testEveryEnumCaseResolvesToMatchingState(): void
    {
        $registry = $this->makeRegistry();
        foreach (PipelineStatus::cases() as $case) {
            $this->assertSame($case, $registry->get($case)->getStatus(), $case->value);
        }
    }

    public function testGetReturnsCorrectStateClass(): void
    {
        $registry = $this->makeRegistry();
        $this->assertInstanceOf(AprobacionState::class, $registry->get(PipelineStatus::APROBACION));
        $this->assertInstanceOf(ContabilidadState::class, $registry->get(PipelineStatus::CONTABILIDAD));
        $this->assertInstanceOf(TesoreriaState::class, $registry->get(PipelineStatus::TESORERIA));
        $this->assertInstanceOf(AutorizacionPagoState::class, $registry->get(PipelineStatus::AUTORIZACION_PAGO));
        $this->assertInstanceOf(VerificacionPagoState::class, $registry->get(PipelineStatus::VERIFICACION_PAGO));
        $this->assertInstanceOf(PagadaState::class, $registry->get(PipelineStatus::PAGADA));
        $this->assertInstanceOf(LegalizadaState::class, $registry->get(PipelineStatus::LEGALIZADA));
    }
}
