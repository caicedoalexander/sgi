<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Presentation;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use App\View\Presentation\PettyCashPresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para PettyCashPresentation::forRow() y pipelineVariant() — derivación
 * de fila del listado de caja menor. Blinda el mapeo estado→pill anti-drift. Puro.
 */
final class PettyCashPresentationTest extends TestCase
{
    public function testForRowMapsStatusBadgeAndLabel(): void
    {
        $row = PettyCashPresentation::forRow(new PettyCashRecord(['status' => PettyCashConstants::STATUS_CONTABILIDAD]));

        $this->assertSame('pill-primary-soft', $row->statusBadgeClass);
        $this->assertSame(
            PettyCashConstants::STATUS_LABELS[PettyCashConstants::STATUS_CONTABILIDAD],
            $row->statusLabel,
        );
    }

    public function testForRowPaidFlag(): void
    {
        $row = PettyCashPresentation::forRow(new PettyCashRecord(['status' => PettyCashConstants::STATUS_PAGADA]));

        $this->assertTrue($row->isPaid);
    }

    public function testForRowUnknownStatusFallsBack(): void
    {
        $row = PettyCashPresentation::forRow(new PettyCashRecord(['status' => 'estado_inexistente']));

        $this->assertSame('pill-muted', $row->statusBadgeClass);
        $this->assertSame(-1, $row->stageIdx);
    }

    public function testForRowCountsInvoices(): void
    {
        $record = new PettyCashRecord([
            'status' => PettyCashConstants::STATUS_AGRUPACION,
            'invoices' => [new stdClass(), new stdClass(), new stdClass()],
        ]);

        $this->assertSame(3, PettyCashPresentation::forRow($record)->invoiceCount);
    }

    public function testPipelineVariantMapping(): void
    {
        $this->assertSame('is-warning', PettyCashPresentation::pipelineVariant(PettyCashConstants::STATUS_TESORERIA));
        $this->assertSame('is-orange', PettyCashPresentation::pipelineVariant(PettyCashConstants::STATUS_AGRUPACION));
        $this->assertSame('', PettyCashPresentation::pipelineVariant(PettyCashConstants::STATUS_CONTABILIDAD));
    }
}
