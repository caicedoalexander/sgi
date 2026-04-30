<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Constants\RoleConstants;
use App\Service\InvoicePipelineService;
use Cake\TestSuite\TestCase;

class InvoicePipelineServiceTest extends TestCase
{
    private InvoicePipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoicePipelineService();
    }

    public function testGetPreviousStatusReturnsExpectedMap(): void
    {
        $this->assertNull($this->service->getPreviousStatus(InvoiceConstants::STATUS_APROBACION));
        $this->assertSame(
            InvoiceConstants::STATUS_APROBACION,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_CONTABILIDAD),
        );
        $this->assertSame(
            InvoiceConstants::STATUS_CONTABILIDAD,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_TESORERIA),
        );
        $this->assertSame(
            InvoiceConstants::STATUS_TESORERIA,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_AUTORIZACION_PAGO),
        );
        $this->assertSame(
            InvoiceConstants::STATUS_AUTORIZACION_PAGO,
            $this->service->getPreviousStatus(InvoiceConstants::STATUS_PAGADA),
        );
    }

    public function testCanRegressTrueForVisibleStateWithPredecessor(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTADOR, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
    }

    public function testCanRegressFalseWhenStateNotVisibleForRole(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTADOR, InvoiceConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::REGISTRO_REVISION, InvoiceConstants::STATUS_APROBACION));
    }

    public function testCanRegressFalseFromAprobacion(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_APROBACION));
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTABILIDAD, InvoiceConstants::STATUS_APROBACION));
    }

    public function testCanRegressTrueForAdminFromAnyNonAprobacionState(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_CONTABILIDAD));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_AUTORIZACION_PAGO));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, InvoiceConstants::STATUS_PAGADA));
    }
}
