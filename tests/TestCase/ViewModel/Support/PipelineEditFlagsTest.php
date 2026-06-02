<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel\Support;

use App\ViewModel\Support\PipelineEditFlags;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PipelineEditFlags::fromRecord() — visibilidad/edición por estado
 * en los edits agrupacion → contabilidad → tesoreria (Refund/PettyCash).
 *
 * Regla: una sección es visible cuando ya pasamos por su estado (statusIndex);
 * la edición se gobierna por el estado actual exacto.
 */
final class PipelineEditFlagsTest extends TestCase
{
    private const STATUSES = ['agrupacion', 'contabilidad', 'tesoreria', 'autorizacion_pago', 'pagada'];

    public function testFromRecordAtAgrupacion(): void
    {
        $flags = PipelineEditFlags::fromRecord('agrupacion', self::STATUSES, true, false, false);

        $this->assertSame(0, $flags->statusIndex);
        $this->assertFalse($flags->showAccounting);
        $this->assertFalse($flags->showTreasury);
        $this->assertFalse($flags->canEditAccounting);
        $this->assertTrue($flags->canSave);
    }

    public function testFromRecordAtContabilidad(): void
    {
        $flags = PipelineEditFlags::fromRecord('contabilidad', self::STATUSES, false, true, false);

        $this->assertSame(1, $flags->statusIndex);
        $this->assertTrue($flags->showAccounting);
        $this->assertFalse($flags->showTreasury);
        $this->assertTrue($flags->canEditAccounting);
        $this->assertFalse($flags->canEditTreasury);
        $this->assertTrue($flags->canSave);
    }

    public function testFromRecordAtTesoreria(): void
    {
        $flags = PipelineEditFlags::fromRecord('tesoreria', self::STATUSES, false, false, true);

        $this->assertSame(2, $flags->statusIndex);
        $this->assertTrue($flags->showAccounting);
        $this->assertTrue($flags->showTreasury);
        $this->assertTrue($flags->canEditTreasury);
        $this->assertTrue($flags->canSave);
    }

    public function testFromRecordUnknownStatusDefaultsToZeroAndNoSave(): void
    {
        $flags = PipelineEditFlags::fromRecord('estado_inexistente', self::STATUSES, false, false, false);

        $this->assertSame(0, $flags->statusIndex);
        $this->assertFalse($flags->showAccounting);
        $this->assertFalse($flags->showTreasury);
        $this->assertFalse($flags->canSave);
    }
}
