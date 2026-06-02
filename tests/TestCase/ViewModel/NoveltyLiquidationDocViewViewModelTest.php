<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\View\Presentation\NoveltyPresentation;
use App\ViewModel\NoveltyLiquidationDocViewViewModel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para NoveltyLiquidationDocViewViewModel — derivación de la vista de
 * detalle del documento de liquidación (badge anti-drift, pills extra, registro). Puro.
 */
final class NoveltyLiquidationDocViewViewModelTest extends TestCase
{
    private function doc(array $data): NoveltyLiquidationDoc
    {
        return new NoveltyLiquidationDoc($data + ['id' => 1, 'liquidation_number' => 'LIQ-1']);
    }

    public function testBadgeAndPageTitle(): void
    {
        $vm = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
        ]));

        $this->assertSame('Liquidación: LIQ-1', $vm->pageTitle);
        $this->assertFalse($vm->isRejected);
        $this->assertSame(
            [
                NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_CONTABILIDAD],
                NoveltyPresentation::STATUS_BADGES[NoveltyConstants::STATUS_CONTABILIDAD],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testBadgeForRejected(): void
    {
        $vm = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_RECHAZADA,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
        ]));

        $this->assertTrue($vm->isRejected);
        $this->assertSame(['Rechazada', 'pill-danger-soft'], $vm->currentStatusBadge);
    }

    public function testPeriodLabelAndNoveltyCount(): void
    {
        $vm = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'employee_novelties' => [new stdClass(), new stdClass()],
        ]));

        $this->assertSame('Primera Quincena', $vm->periodLabel);
        $this->assertSame(2, $vm->noveltyCount);
    }

    public function testExtraPillForPassesForPayment(): void
    {
        $passes = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'passes_for_payment' => true,
        ]));
        $notPasses = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'passes_for_payment' => false,
        ]));

        $this->assertStringContainsString('Pasa a pago', $passes->extraPillHtml);
        $this->assertStringContainsString('No pasa a pago', $notPasses->extraPillHtml);
    }

    public function testRegistryLinesFromTimestamps(): void
    {
        $vm = new NoveltyLiquidationDocViewViewModel($this->doc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'created' => new DateTimeImmutable('2026-06-01'),
            'modified' => new DateTimeImmutable('2026-06-02'),
        ]));

        $this->assertCount(2, $vm->registryLines);
        $this->assertStringContainsString('Creado', $vm->registryLines[0]['html']);
    }
}
