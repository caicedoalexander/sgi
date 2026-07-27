<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\PettyCashConstants;
use App\Model\Entity\PettyCashRecord;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de máquina de estado del agregado PettyCashRecord (fuente
 * única de las reglas estado-solo que componen PettyCashActionPolicy). Puro, sin BD.
 */
final class PettyCashRecordStatePredicatesTest extends TestCase
{
    private function record(array $props): PettyCashRecord
    {
        return new PettyCashRecord($props);
    }

    public function testStatusMirrorPredicatesAreTrueOnlyForTheirStatus(): void
    {
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_AGRUPACION])->isAgrupacion());
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->isContabilidad());
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_TESORERIA])->isTesoreria());
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO])->isAutorizacionPago());
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_VERIFICACION_PAGO])->isVerificacionPago());
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_PAGADA])->isPagada());
    }

    public function testStatusMirrorPredicatesAreFalseForAnotherStatus(): void
    {
        $tesoreria = $this->record(['status' => PettyCashConstants::STATUS_TESORERIA]);
        $this->assertFalse($tesoreria->isAgrupacion());
        $this->assertFalse($tesoreria->isAutorizacionPago());
        $this->assertFalse($tesoreria->isPagada());
    }

    public function testStatusPredicatesAreFalseWhenStatusUnset(): void
    {
        $blank = $this->record([]);
        $this->assertFalse($blank->isAgrupacion());
        $this->assertFalse($blank->isPagada());
    }

    public function testCanRegisterPaymentRequiresTesoreria(): void
    {
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_TESORERIA])->canRegisterPayment());
        $this->assertFalse($this->record(['status' => PettyCashConstants::STATUS_CONTABILIDAD])->canRegisterPayment());
    }

    public function testCanAuthorizePaymentRequiresAutorizacionPago(): void
    {
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO])->canAuthorizePayment());
        $this->assertFalse($this->record(['status' => PettyCashConstants::STATUS_TESORERIA])->canAuthorizePayment());
    }

    public function testCanConfirmPaymentRequiresVerificacionPago(): void
    {
        $this->assertTrue($this->record(['status' => PettyCashConstants::STATUS_VERIFICACION_PAGO])->canConfirmPayment());
        $this->assertFalse($this->record(['status' => PettyCashConstants::STATUS_AUTORIZACION_PAGO])->canConfirmPayment());
    }
}
