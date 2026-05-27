<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants\Domain\Invoice;

use App\Constants\Domain\Invoice\PipelineStatus;
use PHPUnit\Framework\TestCase;

final class PipelineStatusTest extends TestCase
{
    public function testValuesAreSpanishSlugs(): void
    {
        $this->assertSame('aprobacion', PipelineStatus::APROBACION->value);
        $this->assertSame('contabilidad', PipelineStatus::CONTABILIDAD->value);
        $this->assertSame('tesoreria', PipelineStatus::TESORERIA->value);
        $this->assertSame('autorizacion_pago', PipelineStatus::AUTORIZACION_PAGO->value);
        $this->assertSame('verificacion_pago', PipelineStatus::VERIFICACION_PAGO->value);
        $this->assertSame('pagada', PipelineStatus::PAGADA->value);
        $this->assertSame('legalizada', PipelineStatus::LEGALIZADA->value);
    }

    public function testLabelsAreCapitalizedSpanish(): void
    {
        $this->assertSame('Aprobación', PipelineStatus::APROBACION->label());
        $this->assertSame('Contabilidad', PipelineStatus::CONTABILIDAD->label());
        $this->assertSame('Tesorería', PipelineStatus::TESORERIA->label());
        $this->assertSame('Autorización de pago', PipelineStatus::AUTORIZACION_PAGO->label());
        $this->assertSame('Verificación de pago', PipelineStatus::VERIFICACION_PAGO->label());
        $this->assertSame('Pagada', PipelineStatus::PAGADA->label());
        $this->assertSame('Legalizada', PipelineStatus::LEGALIZADA->label());
    }

    public function testNextFollowsLinearProgression(): void
    {
        $this->assertSame(PipelineStatus::CONTABILIDAD, PipelineStatus::APROBACION->next());
        $this->assertSame(PipelineStatus::TESORERIA, PipelineStatus::CONTABILIDAD->next());
        $this->assertSame(PipelineStatus::AUTORIZACION_PAGO, PipelineStatus::TESORERIA->next());
        $this->assertSame(PipelineStatus::VERIFICACION_PAGO, PipelineStatus::AUTORIZACION_PAGO->next());
        $this->assertSame(PipelineStatus::PAGADA, PipelineStatus::VERIFICACION_PAGO->next());
    }

    public function testTerminalStatesHaveNoNext(): void
    {
        $this->assertNull(PipelineStatus::PAGADA->next());
        $this->assertNull(PipelineStatus::LEGALIZADA->next());
    }

    public function testIsTerminalIdentifiesPagadaAndLegalizada(): void
    {
        $this->assertTrue(PipelineStatus::PAGADA->isTerminal());
        $this->assertTrue(PipelineStatus::LEGALIZADA->isTerminal());

        $this->assertFalse(PipelineStatus::APROBACION->isTerminal());
        $this->assertFalse(PipelineStatus::CONTABILIDAD->isTerminal());
        $this->assertFalse(PipelineStatus::TESORERIA->isTerminal());
        $this->assertFalse(PipelineStatus::AUTORIZACION_PAGO->isTerminal());
        $this->assertFalse(PipelineStatus::VERIFICACION_PAGO->isTerminal());
    }

    public function testPipelineCasesExcludesLegalizada(): void
    {
        $cases = PipelineStatus::pipelineCases();

        $this->assertCount(6, $cases);
        $this->assertSame([
            PipelineStatus::APROBACION,
            PipelineStatus::CONTABILIDAD,
            PipelineStatus::TESORERIA,
            PipelineStatus::AUTORIZACION_PAGO,
            PipelineStatus::VERIFICACION_PAGO,
            PipelineStatus::PAGADA,
        ], $cases);
        $this->assertNotContains(PipelineStatus::LEGALIZADA, $cases);
    }

    public function testLegalizationCasesIsThreeStepVisualPipeline(): void
    {
        $this->assertSame([
            PipelineStatus::APROBACION,
            PipelineStatus::CONTABILIDAD,
            PipelineStatus::LEGALIZADA,
        ], PipelineStatus::legalizationCases());
    }
}
