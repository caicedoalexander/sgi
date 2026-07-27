<?php
declare(strict_types=1);

namespace App\Test\TestCase\Constants;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Constants\RefundConstants;
use PHPUnit\Framework\TestCase;

final class PipelineStepConstantsTest extends TestCase
{
    public function testPipelineSlugsAreEnglishSlugs(): void
    {
        $this->assertSame('invoices', PipelineStepConstants::PIPELINE_INVOICES);
        $this->assertSame('novelties', PipelineStepConstants::PIPELINE_NOVELTIES);
        $this->assertSame('payment_schedulings', PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS);
        $this->assertSame('refunds', PipelineStepConstants::PIPELINE_REFUNDS);
        $this->assertSame('petty_cash', PipelineStepConstants::PIPELINE_PETTY_CASH);
        $this->assertSame('legalizations', PipelineStepConstants::PIPELINE_LEGALIZATIONS);
        $this->assertSame('liquidation_docs', PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS);
    }

    public function testEveryPipelineHasLabelAndStepList(): void
    {
        $pipelines = [
            PipelineStepConstants::PIPELINE_INVOICES,
            PipelineStepConstants::PIPELINE_NOVELTIES,
            PipelineStepConstants::PIPELINE_PAYMENT_SCHEDULINGS,
            PipelineStepConstants::PIPELINE_REFUNDS,
            PipelineStepConstants::PIPELINE_PETTY_CASH,
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            PipelineStepConstants::PIPELINE_LIQUIDATION_DOCS,
        ];
        foreach ($pipelines as $p) {
            $this->assertArrayHasKey($p, PipelineStepConstants::PIPELINE_LABELS, "label for {$p}");
            $this->assertArrayHasKey($p, PipelineStepConstants::STEPS_BY_PIPELINE, "steps for {$p}");
            $this->assertNotEmpty(PipelineStepConstants::STEPS_BY_PIPELINE[$p]);
        }
    }

    public function testIsValidRecognizesKnownStep(): void
    {
        $this->assertTrue(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_APROBACION,
        ));
        $this->assertTrue(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_VERIFICACION_PAGO,
        ));
    }

    public function testIsValidRejectsTerminalStateNotInSteps(): void
    {
        // PAGADA es estado terminal y NO está en STEPS_BY_PIPELINE para invoices.
        $this->assertFalse(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_INVOICES,
            InvoiceConstants::STATUS_PAGADA,
        ));
    }

    public function testIsValidRejectsUnknownPipeline(): void
    {
        $this->assertFalse(PipelineStepConstants::isValid('unknown', InvoiceConstants::STATUS_APROBACION));
    }

    public function testIsValidRejectsStepFromAnotherPipeline(): void
    {
        // borrador pertenece a payment_schedulings, no a invoices
        $this->assertFalse(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_INVOICES,
            'borrador',
        ));
    }

    public function testAprobacionIsAValidRefundStep(): void
    {
        $this->assertTrue(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_REFUNDS,
            RefundConstants::STATUS_APROBACION,
        ));
        $this->assertArrayHasKey(
            RefundConstants::STATUS_APROBACION,
            PipelineStepConstants::STEP_LABELS[PipelineStepConstants::PIPELINE_REFUNDS],
        );
    }

    public function testAprobacionIsAValidLegalizationStep(): void
    {
        // Legalizations pasó de 6 a 7 steps al declarar 'aprobacion' (espejo de 'validacion').
        $this->assertCount(7, PipelineStepConstants::STEPS_BY_PIPELINE[PipelineStepConstants::PIPELINE_LEGALIZATIONS]);
        $this->assertTrue(PipelineStepConstants::isValid(
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            AdvanceConstants::STATUS_APROBACION,
        ));
        $this->assertArrayHasKey(
            AdvanceConstants::STATUS_APROBACION,
            PipelineStepConstants::STEP_LABELS[PipelineStepConstants::PIPELINE_LEGALIZATIONS],
        );
    }
}
