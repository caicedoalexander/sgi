<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Advance\State;

use App\Constants\Domain\Advance\PipelineStatus;
use App\Constants\InvoiceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Model\Entity\Invoice;
use App\Service\AdvanceLegalizationGuard;
use App\Service\Pipeline\Advance\State\ValidacionState;
use PHPUnit\Framework\TestCase;

/**
 * A8 — ValidacionState es puro: delega el IO al AdvanceLegalizationGuard
 * inyectado. Verifica las transiciones y los 2 mensajes de validateAdvance
 * (sin BD; el Guard se mockea).
 */
final class ValidacionStateTest extends TestCase
{
    public function testStatusTransitions(): void
    {
        $state = new ValidacionState($this->createStub(AdvanceLegalizationGuard::class));

        $this->assertSame(PipelineStatus::VALIDACION, $state->getStatus());
        $this->assertSame(PipelineStatus::APROBACION, $state->getNextStatus());
        $this->assertNull($state->getPreviousStatus());
    }

    public function testErrorsWhenNoInvoicesAndNoDocument(): void
    {
        $guard = $this->createMock(AdvanceLegalizationGuard::class);
        $guard->method('linkedLegalizationInvoices')->willReturn([]);
        $guard->method('hasPendingRelationDocument')->willReturn(false);

        $state = new ValidacionState($guard);
        $errors = $state->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));

        $this->assertContains('Vincule al menos una factura antes de avanzar.', $errors);
        $this->assertContains('Debe adjuntar la relación de facturas (PDF).', $errors);
        $this->assertCount(2, $errors);
    }

    public function testPassesWhenInvoicesLinkedAndDocumentPresent(): void
    {
        $guard = $this->createMock(AdvanceLegalizationGuard::class);
        $guard->expects($this->once())
            ->method('linkedLegalizationInvoices')
            ->with(5)
            ->willReturn([
                new Invoice(['id' => 11, 'invoice_number' => 'F-1', 'pipeline_status' => InvoiceConstants::STATUS_APROBACION]),
            ]);
        $guard->expects($this->once())
            ->method('hasPendingRelationDocument')
            ->with(1)
            ->willReturn(true);

        $state = new ValidacionState($guard);
        $errors = $state->validateAdvance(new AdvanceLegalization(['id' => 1, 'advance_invoice_id' => 5]));

        $this->assertSame([], $errors);
    }
}
