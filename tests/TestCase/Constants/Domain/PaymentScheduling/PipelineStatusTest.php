<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\PaymentScheduling;

use App\Constants\Domain\PaymentScheduling\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    public function testFiveCases(): void
    {
        $this->assertCount(5, PipelineStatus::cases());
        $values = array_map(fn(PipelineStatus $s) => $s->value, PipelineStatus::cases());
        $this->assertSame([
            'borrador',
            'tesoreria',
            'autorizacion_pago',
            'verificacion_pago',
            'pagada',
        ], $values);
    }

    public function testNextLinear(): void
    {
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::BORRADOR->next());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::TESORERIA->next());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, PipelineStatus::AUTORIZACION_PAGO->next());
        $this->assertSame(PipelineStatus::PAGADA, PipelineStatus::VERIFICACION_PAGO->next());
        $this->assertNull(PipelineStatus::PAGADA->next());
    }

    public function testPrevious(): void
    {
        $this->assertNull(PipelineStatus::BORRADOR->previous());
        $this->assertNull(PipelineStatus::PAGADA->previous());
        $this->assertSame(PipelineStatus::BORRADOR, PipelineStatus::TESORERIA->previous());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::AUTORIZACION_PAGO->previous());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::VERIFICACION_PAGO->previous());
    }

    public function testIsTerminalOnlyPagada(): void
    {
        $this->assertTrue(PipelineStatus::PAGADA->isTerminal());
        foreach (
            [
            PipelineStatus::BORRADOR,
            PipelineStatus::TESORERIA,
            PipelineStatus::AUTORIZACION_PAGO,
            PipelineStatus::VERIFICACION_PAGO,
            ] as $case
        ) {
            $this->assertFalse($case->isTerminal(), $case->value);
        }
    }

    public function testRejectionTargetIsTesoreria(): void
    {
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::rejectionTarget());
    }

    public function testLabels(): void
    {
        $this->assertSame('Borrador', PipelineStatus::BORRADOR->label());
        $this->assertSame('Tesorería', PipelineStatus::TESORERIA->label());
        $this->assertSame('Pagada', PipelineStatus::PAGADA->label());
    }
}
