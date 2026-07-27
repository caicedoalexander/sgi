<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Novelty;

use App\Constants\Domain\Novelty\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    public function testElevenCasesExist(): void
    {
        $this->assertCount(11, PipelineStatus::cases());
    }

    public function testNextLinearProgression(): void
    {
        $pairs = [
            [PipelineStatus::REGISTRO, PipelineStatus::APROBACION],
            [PipelineStatus::APROBACION, PipelineStatus::RRHH],
            [PipelineStatus::RRHH, PipelineStatus::CONTABILIDAD],
            [PipelineStatus::CONTABILIDAD, PipelineStatus::REVISION_FIRMAS],
            [PipelineStatus::REVISION_FIRMAS, PipelineStatus::GDP],
            [PipelineStatus::GDP, PipelineStatus::TESORERIA],
            [PipelineStatus::TESORERIA, PipelineStatus::AUTORIZACION_PAGO],
            [PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::VERIFICACION_PAGO],
            [PipelineStatus::VERIFICACION_PAGO, PipelineStatus::PAGADA],
        ];

        foreach ($pairs as [$from, $to]) {
            $this->assertSame($to, $from->next(), "{$from->value} should advance to {$to->value}");
        }
    }

    public function testTerminalStatesHaveNoNext(): void
    {
        $this->assertNull(PipelineStatus::PAGADA->next());
        $this->assertNull(PipelineStatus::RECHAZADA->next());
    }

    public function testPreviousMirrorsForward(): void
    {
        $this->assertNull(PipelineStatus::REGISTRO->previous());
        $this->assertNull(PipelineStatus::PAGADA->previous());
        $this->assertNull(PipelineStatus::RECHAZADA->previous());

        $this->assertSame(PipelineStatus::REGISTRO, PipelineStatus::APROBACION->previous());
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::RRHH->previous());
        $this->assertSame(PipelineStatus::RRHH, PipelineStatus::CONTABILIDAD->previous());
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::REVISION_FIRMAS->previous());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, PipelineStatus::GDP->previous());
        $this->assertSame(PipelineStatus::GDP, PipelineStatus::TESORERIA->previous());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::AUTORIZACION_PAGO->previous());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::VERIFICACION_PAGO->previous());
    }

    public function testIsTerminalForPagadaAndRechazada(): void
    {
        $this->assertTrue(PipelineStatus::PAGADA->isTerminal());
        $this->assertTrue(PipelineStatus::RECHAZADA->isTerminal());

        foreach (
            [
            PipelineStatus::REGISTRO,
            PipelineStatus::APROBACION,
            PipelineStatus::RRHH,
            PipelineStatus::CONTABILIDAD,
            PipelineStatus::REVISION_FIRMAS,
            PipelineStatus::GDP,
            PipelineStatus::TESORERIA,
            PipelineStatus::AUTORIZACION_PAGO,
            PipelineStatus::VERIFICACION_PAGO,
            ] as $case
        ) {
            $this->assertFalse($case->isTerminal(), "{$case->value} should NOT be terminal");
        }
    }

    public function testLabelsAreSpanishCapitalized(): void
    {
        $this->assertSame('Registro', PipelineStatus::REGISTRO->label());
        $this->assertSame('Revisión y Firmas de documentos', PipelineStatus::REVISION_FIRMAS->label());
        $this->assertSame('Rechazada', PipelineStatus::RECHAZADA->label());
    }
}
