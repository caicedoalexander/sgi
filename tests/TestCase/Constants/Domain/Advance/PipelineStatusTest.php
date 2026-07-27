<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Advance;

use App\Constants\Domain\Advance\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame('validacion', PipelineStatus::VALIDACION->value);
        $this->assertSame('aprobacion', PipelineStatus::APROBACION->value);
        $this->assertSame('revision_firmas', PipelineStatus::REVISION_FIRMAS->value);
        $this->assertSame('contabilidad', PipelineStatus::CONTABILIDAD->value);
        $this->assertSame('tesoreria', PipelineStatus::TESORERIA->value);
        $this->assertSame('autorizacion_pago', PipelineStatus::AUTORIZACION_PAGO->value);
        $this->assertSame('verificacion_pago', PipelineStatus::VERIFICACION_PAGO->value);
        $this->assertSame('legalizada', PipelineStatus::LEGALIZADA->value);
    }

    public function testLabels(): void
    {
        $this->assertSame('Validación', PipelineStatus::VALIDACION->label());
        $this->assertSame('Aprobación', PipelineStatus::APROBACION->label());
        $this->assertSame('Revisión y Firmas', PipelineStatus::REVISION_FIRMAS->label());
        $this->assertSame('Contabilidad', PipelineStatus::CONTABILIDAD->label());
        $this->assertSame('Tesorería', PipelineStatus::TESORERIA->label());
        $this->assertSame('Autorización de pago', PipelineStatus::AUTORIZACION_PAGO->label());
        $this->assertSame('Verificación de pago', PipelineStatus::VERIFICACION_PAGO->label());
        $this->assertSame('Legalizada', PipelineStatus::LEGALIZADA->label());
    }

    public function testNextLinearPath(): void
    {
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::VALIDACION->next());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, PipelineStatus::APROBACION->next());
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::REVISION_FIRMAS->next());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, PipelineStatus::AUTORIZACION_PAGO->next());
        $this->assertSame(PipelineStatus::LEGALIZADA, PipelineStatus::VERIFICACION_PAGO->next());
    }

    public function testBifurcatingStepsHaveNullNext(): void
    {
        // CONTABILIDAD y TESORERIA bifurcan por case_type
        $this->assertNull(PipelineStatus::CONTABILIDAD->next());
        $this->assertNull(PipelineStatus::TESORERIA->next());
    }

    public function testLegalizadaIsTerminalAndHasNoNext(): void
    {
        $this->assertNull(PipelineStatus::LEGALIZADA->next());
        $this->assertTrue(PipelineStatus::LEGALIZADA->isTerminal());
    }

    public function testPreviousProvidesRegression(): void
    {
        $this->assertNull(PipelineStatus::VALIDACION->previous());
        $this->assertNull(PipelineStatus::LEGALIZADA->previous());
        $this->assertSame(PipelineStatus::APROBACION, PipelineStatus::REVISION_FIRMAS->previous());
        $this->assertSame(PipelineStatus::VALIDACION, PipelineStatus::APROBACION->previous());
        $this->assertSame(PipelineStatus::REVISION_FIRMAS, PipelineStatus::CONTABILIDAD->previous());
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::TESORERIA->previous());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::AUTORIZACION_PAGO->previous());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::VERIFICACION_PAGO->previous());
    }

    public function testIsTerminalIsOnlyTrueForLegalizada(): void
    {
        foreach (PipelineStatus::cases() as $case) {
            $expected = $case === PipelineStatus::LEGALIZADA;
            $this->assertSame($expected, $case->isTerminal(), "isTerminal for {$case->value}");
        }
    }
}
