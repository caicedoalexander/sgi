<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\PaymentSchedulingConstants;
use App\Model\Entity\PaymentScheduling;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de estado del agregado PaymentScheduling. Puro, sin BD.
 */
final class PaymentSchedulingStatePredicatesTest extends TestCase
{
    private function scheduling(array $props): PaymentScheduling
    {
        return new PaymentScheduling($props);
    }

    public function testIsPagadaTrueOnlyForPagadaStatus(): void
    {
        $this->assertTrue($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_PAGADA])->isPagada());
        $this->assertFalse($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO])->isPagada());
        $this->assertFalse($this->scheduling([])->isPagada());
    }

    public function testCanRejectRequiresAutorizacionPago(): void
    {
        $this->assertTrue($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO])->canReject());
        $this->assertFalse($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO])->canReject());
    }

    public function testCanConfirmPaymentRequiresVerificacionPago(): void
    {
        $this->assertTrue($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO])->canConfirmPayment());
        $this->assertFalse($this->scheduling(['pipeline_status' => PaymentSchedulingConstants::STATUS_AUTORIZACION_PAGO])->canConfirmPayment());
    }
}
