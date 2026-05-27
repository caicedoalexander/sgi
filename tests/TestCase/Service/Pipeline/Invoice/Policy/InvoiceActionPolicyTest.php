<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\InvoiceConstants;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\Invoice;
use App\Service\Pipeline\Invoice\Policy\InvoiceActionPolicy;
use App\ValueObject\UserContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifica la composición rol×paso (vía AuthorizationFacade) + estado del
 * agregado (vía Invoice::canX) en InvoiceActionPolicy.
 */
final class InvoiceActionPolicyTest extends TestCase
{
    private const ROLE_TESORERIA = 3;
    private const ROLE_CONTADOR = 4;

    public function testCanRegisterPaymentTrueWhenInvoiceAllowsAndRoleCanOperateOnTesoreria(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with(
                $this->callback(fn (UserContext $u) => $u->roleId === self::ROLE_TESORERIA),
                PipelineStepConstants::PIPELINE_INVOICES,
                InvoiceConstants::STATUS_TESORERIA,
            )
            ->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canRegisterPayment($invoice, self::ROLE_TESORERIA));
    }

    public function testCanRegisterPaymentFalseWhenInvoiceRejected(): void
    {
        // canOperate puede o no ser llamado, no importa porque canRegisterPayment del invoice es false
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_REJECTED,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($invoice, self::ROLE_TESORERIA));
    }

    public function testCanRegisterPaymentFalseWhenRoleCannotOperate(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(false);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertFalse($policy->canRegisterPayment($invoice, 999));
    }

    public function testCanAuthorizePaymentChecksAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), InvoiceConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canAuthorizePayment($invoice, self::ROLE_CONTADOR));
    }

    public function testCanConfirmPaymentChecksVerificacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), InvoiceConstants::STATUS_VERIFICACION_PAGO)
            ->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_VERIFICACION_PAGO,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canConfirmPayment($invoice, self::ROLE_TESORERIA));
    }

    public function testEditPaymentMapsToTesoreriaStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), InvoiceConstants::STATUS_TESORERIA)
            ->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canEditPayment($invoice, self::ROLE_TESORERIA));
    }

    public function testRejectPaymentMapsToAutorizacionPagoStep(): void
    {
        $auth = $this->createMock(AuthorizationFacade::class);
        $auth->expects($this->once())
            ->method('canOperate')
            ->with($this->anything(), $this->anything(), InvoiceConstants::STATUS_AUTORIZACION_PAGO)
            ->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_AUTORIZACION_PAGO,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canRejectPayment($invoice, self::ROLE_CONTADOR));
    }

    public function testAddPaymentAndDeletePaymentMapToTesoreriaStep(): void
    {
        $auth = $this->createStub(AuthorizationFacade::class);
        $auth->method('canOperate')->willReturn(true);

        $invoice = new Invoice([
            'pipeline_status' => InvoiceConstants::STATUS_TESORERIA,
            'area_approval' => InvoiceConstants::APPROVAL_APPROVED,
        ]);

        $policy = new InvoiceActionPolicy($auth);
        $this->assertTrue($policy->canAddPayment($invoice, self::ROLE_TESORERIA));
        $this->assertTrue($policy->canDeletePayment($invoice, self::ROLE_TESORERIA));
    }
}
