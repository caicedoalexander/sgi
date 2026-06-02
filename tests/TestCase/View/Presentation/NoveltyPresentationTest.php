<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Model\Entity\NoveltyLiquidationDoc;
use App\View\Presentation\NoveltyPresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para NoveltyPresentation — derivación de filas de novedades y
 * documentos de liquidación. Blinda el mapeo estado→pill anti-drift (incluido el
 * override a danger cuando la novedad está rechazada). Puro.
 */
final class NoveltyPresentationTest extends TestCase
{
    public function testEmployeeNoveltyRowMapsStatus(): void
    {
        $row = NoveltyPresentation::forEmployeeNoveltyRow(
            new EmployeeNovelty(['pipeline_status' => NoveltyConstants::STATUS_APROBACION]),
        );

        $this->assertFalse($row->isRejected);
        $this->assertFalse($row->isPaid);
        $this->assertSame('pill-warning-soft', $row->statusBadgeClass);
        $this->assertSame(
            NoveltyConstants::STATUS_LABELS[NoveltyConstants::STATUS_APROBACION],
            $row->statusLabel,
        );
    }

    public function testEmployeeNoveltyRowRejectedOverridesBadge(): void
    {
        $row = NoveltyPresentation::forEmployeeNoveltyRow(
            new EmployeeNovelty(['pipeline_status' => NoveltyConstants::STATUS_RECHAZADA]),
        );

        $this->assertTrue($row->isRejected);
        $this->assertSame('pill-danger-soft', $row->statusBadgeClass);
    }

    public function testEmployeeNoveltyRowPaidFlag(): void
    {
        $row = NoveltyPresentation::forEmployeeNoveltyRow(
            new EmployeeNovelty(['pipeline_status' => NoveltyConstants::STATUS_PAGADA]),
        );

        $this->assertTrue($row->isPaid);
    }

    public function testLiquidationDocRowDerivation(): void
    {
        $doc = new NoveltyLiquidationDoc([
            'pipeline_status' => NoveltyConstants::STATUS_CONTABILIDAD,
            'period' => NoveltyConstants::PERIOD_PRIMERA_QUINCENA,
            'employee_novelties' => [new stdClass(), new stdClass()],
        ]);

        $row = NoveltyPresentation::forLiquidationDocRow($doc);

        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
        $this->assertSame('Primera Quincena', $row->periodLabel);
        $this->assertSame(2, $row->noveltyCount);
    }
}
