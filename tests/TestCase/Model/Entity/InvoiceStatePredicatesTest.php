<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * Cubre los predicados de máquina de estado del agregado Invoice
 * (única fuente de verdad para las reglas estado-solo del pipeline de pagos).
 */
final class InvoiceStatePredicatesTest extends TestCase
{
    private function make(array $props): Invoice
    {
        return new Invoice($props);
    }

    public function testIsRejectedTrueWhenAreaApprovalRejected(): void
    {
        $invoice = $this->make(['area_approval' => InvoiceConstants::APPROVAL_REJECTED]);
        $this->assertTrue($invoice->isRejected());
    }

    public function testIsRejectedFalseWhenPending(): void
    {
        $invoice = $this->make(['area_approval' => InvoiceConstants::APPROVAL_PENDING]);
        $this->assertFalse($invoice->isRejected());
    }

    public function testIsRejectedFalseWhenUnset(): void
    {
        $this->assertFalse($this->make([])->isRejected());
    }

    public function testIsApprovedTrueOnlyForApproval(): void
    {
        $this->assertTrue($this->make(['area_approval' => InvoiceConstants::APPROVAL_APPROVED])->isApproved());
        $this->assertFalse($this->make(['area_approval' => InvoiceConstants::APPROVAL_PENDING])->isApproved());
        $this->assertFalse($this->make(['area_approval' => InvoiceConstants::APPROVAL_REJECTED])->isApproved());
    }

    public function testIsPaidTrueOnlyInPagada(): void
    {
        $this->assertTrue($this->make(['pipeline_status' => InvoiceConstants::STATUS_PAGADA])->isPaid());
        $this->assertFalse($this->make(['pipeline_status' => InvoiceConstants::STATUS_LEGALIZADA])->isPaid());
        $this->assertFalse($this->make([])->isPaid());
    }

    public function testIsInFinalStateCoversPagadaAndLegalizada(): void
    {
        $this->assertTrue($this->make(['pipeline_status' => InvoiceConstants::STATUS_PAGADA])->isInFinalState());
        $this->assertTrue($this->make(['pipeline_status' => InvoiceConstants::STATUS_LEGALIZADA])->isInFinalState());
        $this->assertFalse($this->make(['pipeline_status' => InvoiceConstants::STATUS_TESORERIA])->isInFinalState());
    }

    public function testIsEditableIsInverseOfFinalState(): void
    {
        $this->assertFalse($this->make(['pipeline_status' => InvoiceConstants::STATUS_PAGADA])->isEditable());
        $this->assertTrue($this->make(['pipeline_status' => InvoiceConstants::STATUS_APROBACION])->isEditable());
    }

    public function testRequiresApprovalTrueOnlyWhenAprobacionAndNotApproved(): void
    {
        $this->assertTrue($this->make([
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'area_approval' => InvoiceConstants::APPROVAL_PENDING,
        ])->requiresApproval());

        $this->assertFalse($this->make([
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ])->requiresApproval());

        $this->assertFalse($this->make([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
        ])->requiresApproval());
    }

    public function testCanRegisterPaymentRequiresTesoreriaAndNotRejectedNotPaid(): void
    {
        $ok = $this->make([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $this->assertTrue($ok->canRegisterPayment());

        $rejected = $this->make([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ]);
        $this->assertFalse($rejected->canRegisterPayment());

        $wrongStep = $this->make([
            'pipeline_status' => InvoiceConstants::STATUS_CONTABILIDAD,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $this->assertFalse($wrongStep->canRegisterPayment());

        $paid = $this->make([
            'pipeline_status' => InvoiceConstants::STATUS_PAGADA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);
        $this->assertFalse($paid->canRegisterPayment());
    }

    public function testCanAuthorizePaymentRequiresAutorizacionPagoStep(): void
    {
        $ok = $this->make(['pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO]);
        $this->assertTrue($ok->canAuthorizePayment());

        $wrong = $this->make(['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]);
        $this->assertFalse($wrong->canAuthorizePayment());
    }

    public function testCanConfirmPaymentRequiresVerificacionPagoStep(): void
    {
        $ok = $this->make(['pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO]);
        $this->assertTrue($ok->canConfirmPayment());

        $wrong = $this->make(['pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO]);
        $this->assertFalse($wrong->canConfirmPayment());
    }

    public function testAliasesPointToTheRightPredicate(): void
    {
        $tesoreria = $this->make(['pipeline_status' => InvoiceConstants::STATUS_TESORERIA]);
        $this->assertTrue($tesoreria->canAddPayment());
        $this->assertTrue($tesoreria->canEditPayment());
        $this->assertTrue($tesoreria->canDeletePayment());

        $autorizacion = $this->make(['pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO]);
        $this->assertTrue($autorizacion->canRejectPayment());
    }

    public function testIsInPettyCashUsesPettyCashRecordId(): void
    {
        $this->assertTrue($this->make(['petty_cash_record_id' => 7])->isInPettyCash());
        $this->assertFalse($this->make([])->isInPettyCash());
        $this->assertFalse($this->make(['petty_cash_record_id' => null])->isInPettyCash());
    }
}
