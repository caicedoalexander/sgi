<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\PaymentSchedulingConstants;
use App\Constants\RoleConstants;
use App\Service\PaymentSchedulingPipelineService;
use Cake\TestSuite\TestCase;

class PaymentSchedulingPipelineServiceTest extends TestCase
{
    private PaymentSchedulingPipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentSchedulingPipelineService();
    }

    public function testGetPreviousStatusReturnsExpectedMap(): void
    {
        $this->assertNull($this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_BORRADOR));
        $this->assertSame(
            PaymentSchedulingConstants::STATUS_BORRADOR,
            $this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_TESORERIA),
        );
        $this->assertSame(
            PaymentSchedulingConstants::STATUS_TESORERIA,
            $this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_AUT_PAGO),
        );
        $this->assertNull($this->service->getPreviousStatus(PaymentSchedulingConstants::STATUS_PAGADA));
    }

    public function testCanRegressTrueForVisibleStateWithPredecessor(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::CONTADOR, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }

    public function testCanRegressFalseFromBorrador(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_BORRADOR));
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_BORRADOR));
    }

    public function testCanRegressFalseFromPagada(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_PAGADA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_PAGADA));
    }

    public function testCanRegressFalseWhenStateNotVisibleForRole(): void
    {
        $this->assertFalse($this->service->canRegress(RoleConstants::CONTADOR, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertFalse($this->service->canRegress(RoleConstants::TESORERIA, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }

    public function testCanRegressTrueForAdminFromRegressableStates(): void
    {
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_TESORERIA));
        $this->assertTrue($this->service->canRegress(RoleConstants::ADMIN, PaymentSchedulingConstants::STATUS_AUT_PAGO));
    }
}
