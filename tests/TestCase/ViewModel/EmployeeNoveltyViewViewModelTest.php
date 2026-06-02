<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\View\Presentation\NoveltyPresentation;
use App\ViewModel\EmployeeNoveltyViewViewModel;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para EmployeeNoveltyViewViewModel — derivación de la vista de detalle
 * de novedades (badge anti-drift con override rechazada, relabel del pipeline). Puro.
 */
final class EmployeeNoveltyViewViewModelTest extends TestCase
{
    private function novelty(array $data): EmployeeNovelty
    {
        return new EmployeeNovelty($data + ['id' => 1, 'custom_name' => 'Vacaciones']);
    }

    public function testBadgeForActiveStatus(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_APROBACION]));

        $this->assertFalse($vm->isRejected);
        $this->assertSame('Novedad #1', $vm->pageTitle);
        $this->assertSame(
            [
                NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_APROBACION],
                NoveltyPresentation::STATUS_BADGES[NoveltyConstants::STATUS_APROBACION],
            ],
            $vm->currentStatusBadge,
        );
    }

    public function testBadgeForRejected(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA]));

        $this->assertTrue($vm->isRejected);
        $this->assertSame(['Rechazada', 'pill-danger-soft'], $vm->currentStatusBadge);
    }

    public function testPipelineLabelsRelabelContabilidad(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_APROBACION]));

        $this->assertSame('Paso a Nómina', $vm->pipelineLabels[NoveltyConstants::STATUS_CONTABILIDAD]);
    }

    public function testNoveltyNameFromCustomName(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_APROBACION]));

        $this->assertSame('Vacaciones', $vm->noveltyName);
    }

    public function testIsTerminalWhenPaid(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty(['pipeline_status' => NoveltyConstants::STATUS_PAGADA]));

        $this->assertTrue($vm->isTerminal);
    }

    public function testRegistryLinesFromDates(): void
    {
        $vm = new EmployeeNoveltyViewViewModel($this->novelty([
            'pipeline_status' => NoveltyConstants::STATUS_APROBACION,
            'filing_date' => new DateTimeImmutable('2026-06-01'),
            'modified' => new DateTimeImmutable('2026-06-02'),
        ]));

        $this->assertCount(2, $vm->registryLines);
        $this->assertStringContainsString('Diligenciada', $vm->registryLines[0]['html']);
    }
}
