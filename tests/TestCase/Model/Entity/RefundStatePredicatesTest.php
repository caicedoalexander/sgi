<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\RefundConstants;
use App\Model\Entity\Refund;
use Cake\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de máquina de estado del agregado Refund (fuente única de
 * verdad de las reglas estado-solo que componen RefundActionPolicy). Puro, sin BD.
 * Espejo de InvoiceStatePredicatesTest — cierra el gap de paridad de entidades.
 */
final class RefundStatePredicatesTest extends TestCase
{
    private function refund(array $props): Refund
    {
        return new Refund($props);
    }

    public function testStatusMirrorPredicatesAreTrueOnlyForTheirStatus(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AGRUPACION])->isAgrupacion());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD])->isContabilidad());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_TESORERIA])->isTesoreria());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO])->isAutorizacionPago());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_VERIFICACION_PAGO])->isVerificacionPago());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_PAGADA])->isPagada());
    }

    public function testStatusMirrorPredicatesAreFalseForAnotherStatus(): void
    {
        $contabilidad = $this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD]);
        $this->assertFalse($contabilidad->isAgrupacion());
        $this->assertFalse($contabilidad->isTesoreria());
        $this->assertFalse($contabilidad->isPagada());
    }

    public function testIsInPaymentPhaseCoversTesoreriaOnwards(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_TESORERIA])->isInPaymentPhase());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO])->isInPaymentPhase());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_VERIFICACION_PAGO])->isInPaymentPhase());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_PAGADA])->isInPaymentPhase());

        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_AGRUPACION])->isInPaymentPhase());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD])->isInPaymentPhase());
    }

    public function testCanBeDeletedOnlyInAgrupacion(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AGRUPACION])->canBeDeleted());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD])->canBeDeleted());
    }

    public function testCanAdvancePipelineTrueForForwardStates(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AGRUPACION])->canAdvancePipeline());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD])->canAdvancePipeline());
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_TESORERIA])->canAdvancePipeline());
    }

    public function testCanAdvancePipelineFalseForPaymentControlledAndTerminalStates(): void
    {
        // autorizacion_pago / verificacion_pago avanzan vía la sección de pagos,
        // no por el botón de avance; pagada es terminal.
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO])->canAdvancePipeline());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_VERIFICACION_PAGO])->canAdvancePipeline());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_PAGADA])->canAdvancePipeline());
    }

    public function testCanRegisterPaymentRequiresTesoreria(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_TESORERIA])->canRegisterPayment());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_CONTABILIDAD])->canRegisterPayment());
    }

    public function testCanAuthorizePaymentRequiresAutorizacionPago(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO])->canAuthorizePayment());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_TESORERIA])->canAuthorizePayment());
    }

    public function testCanConfirmPaymentRequiresVerificacionPago(): void
    {
        $this->assertTrue($this->refund(['status' => RefundConstants::STATUS_VERIFICACION_PAGO])->canConfirmPayment());
        $this->assertFalse($this->refund(['status' => RefundConstants::STATUS_AUTORIZACION_PAGO])->canConfirmPayment());
    }

    public function testGetBeneficiaryNameForEmployeeJoinsNames(): void
    {
        $refund = $this->refund([
            'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE,
            'beneficiary_employee' => new Entity([
                'first_name' => 'Ana',
                'last_name1' => 'Pérez',
                'last_name2' => 'Gómez',
            ]),
        ]);

        $this->assertSame('Ana Pérez Gómez', $refund->getBeneficiaryName());
    }

    public function testGetBeneficiaryNameForProviderUsesProviderName(): void
    {
        $refund = $this->refund([
            'beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_PROVIDER,
            'beneficiary_provider' => new Entity(['name' => 'ACME S.A.S.']),
        ]);

        $this->assertSame('ACME S.A.S.', $refund->getBeneficiaryName());
    }

    public function testGetBeneficiaryNameNullWhenAssociationMissingOrTypeUnset(): void
    {
        $employeeWithoutEntity = $this->refund(['beneficiary_type' => RefundConstants::BENEFICIARY_TYPE_EMPLOYEE]);
        $this->assertNull($employeeWithoutEntity->getBeneficiaryName());

        $this->assertNull($this->refund([])->getBeneficiaryName());
    }
}
