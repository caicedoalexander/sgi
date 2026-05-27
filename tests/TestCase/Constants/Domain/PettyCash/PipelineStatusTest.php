<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\PettyCash;

use App\Constants\Domain\PettyCash\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    public function testSixCasesAndValues(): void
    {
        $expected = [
            'agrupacion',
            'contabilidad',
            'tesoreria',
            'autorizacion_pago',
            'verificacion_pago',
            'pagada',
        ];
        $actual = array_map(fn (PipelineStatus $s) => $s->value, PipelineStatus::cases());
        $this->assertSame($expected, $actual);
    }

    public function testLinearNext(): void
    {
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::AGRUPACION->next());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::CONTABILIDAD->next());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::TESORERIA->next());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, PipelineStatus::AUTORIZACION_PAGO->next());
        $this->assertSame(PipelineStatus::PAGADA, PipelineStatus::VERIFICACION_PAGO->next());
        $this->assertNull(PipelineStatus::PAGADA->next());
    }

    public function testPrevious(): void
    {
        $this->assertNull(PipelineStatus::AGRUPACION->previous());
        $this->assertNull(PipelineStatus::PAGADA->previous());
        $this->assertSame(PipelineStatus::AGRUPACION, PipelineStatus::CONTABILIDAD->previous());
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::TESORERIA->previous());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::AUTORIZACION_PAGO->previous());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::VERIFICACION_PAGO->previous());
    }

    public function testIsTerminal(): void
    {
        $this->assertTrue(PipelineStatus::PAGADA->isTerminal());
        foreach (PipelineStatus::cases() as $case) {
            if ($case !== PipelineStatus::PAGADA) {
                $this->assertFalse($case->isTerminal(), "{$case->value}");
            }
        }
    }

    public function testLabels(): void
    {
        $this->assertSame('Agrupación', PipelineStatus::AGRUPACION->label());
        $this->assertSame('Pagada', PipelineStatus::PAGADA->label());
        $this->assertSame('Tesorería', PipelineStatus::TESORERIA->label());
    }
}
