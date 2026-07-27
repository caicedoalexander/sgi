<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel\Support;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\ViewModel\Support\LegalizationSummary;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Unit puro: derivación de totales, diff, badge de estado y caso del resumen de
 * legalización. Sin BD; entidades in-memory.
 */
final class LegalizationSummaryTest extends TestCase
{
    private function leg(array $data = []): AdvanceLegalization
    {
        return new AdvanceLegalization($data + [
            'id' => 1,
            'status' => AdvanceConstants::STATUS_CONTABILIDAD,
            'case_type' => null,
            'advance_legalization_signatures' => [],
        ]);
    }

    public function testTotalsAndExactDiff(): void
    {
        $summary = new LegalizationSummary(
            $this->leg(),
            1000.0,
            [new Entity(['amount' => 600]), new Entity(['amount' => 400])],
        );

        $this->assertSame(1000.0, $summary->linkedTotal);
        $this->assertSame(2, $summary->linkedCount);
        $this->assertSame(0.0, $summary->diff);
        $this->assertSame('pill-primary-soft', $summary->diffBadgeClass);
    }

    public function testShortageDiffIsWarning(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, [new Entity(['amount' => 800])]);

        $this->assertSame(200.0, $summary->diff);
        $this->assertSame('pill-warning-soft', $summary->diffBadgeClass);
    }

    public function testSurplusDiffIsDanger(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, [new Entity(['amount' => 1200])]);

        $this->assertSame(-200.0, $summary->diff);
        $this->assertSame('pill-danger-soft', $summary->diffBadgeClass);
    }

    public function testStatusBadgeAndCaseLabel(): void
    {
        $summary = new LegalizationSummary(
            $this->leg(['status' => AdvanceConstants::STATUS_TESORERIA, 'case_type' => AdvanceConstants::CASE_SOBRANTE]),
            1000.0,
            [],
        );

        $this->assertSame('Tesorería', $summary->statusBadge[0]);
        $this->assertSame('pill-info-soft', $summary->statusBadge[1]);
        $this->assertSame('Sobrante', $summary->caseLabel);
        $this->assertSame(AdvanceConstants::PIPELINE_STATUSES_SOBRANTE, $summary->casePipelineSteps);
        // TESORERIA es el índice 4 en PIPELINE_STATUSES_SOBRANTE.
        $this->assertSame(4, $summary->pipelineIdx);
        $this->assertNotSame('', $summary->pipelineVariant);
    }

    public function testNoSignaturesLeavesRelationDocumentNull(): void
    {
        $summary = new LegalizationSummary($this->leg(), 1000.0, []);

        $this->assertNull($summary->relationDocument);
        $this->assertSame([], $summary->signatureHistory);
    }
}
