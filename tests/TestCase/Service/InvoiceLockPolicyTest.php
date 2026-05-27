<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\InvoiceLockPolicy;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para las ramas de InvoiceLockPolicy que NO tocan la base de datos.
 * Las ramas dependientes de InvoicePayments + PaymentSchedulings (vía
 * `isLockedByPaidScheduling`) requieren fixtures y se quedan fuera de la suite
 * pura — aquí se cubren solo cuando la factura no tiene id.
 */
final class InvoiceLockPolicyTest extends TestCase
{
    private InvoiceLockPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new InvoiceLockPolicy();
    }

    public function testIsLockedByPettyCashTrueWhenPettyCashRecordIdPresent(): void
    {
        $invoice = new stdClass();
        $invoice->petty_cash_record_id = 7;
        $this->assertTrue($this->policy->isLockedByPettyCash($invoice));
    }

    public function testIsLockedByPettyCashFalseWhenAbsent(): void
    {
        $invoice = new stdClass();
        $this->assertFalse($this->policy->isLockedByPettyCash($invoice));
    }

    public function testIsLockedByPettyCashFalseWhenNull(): void
    {
        $invoice = new stdClass();
        $invoice->petty_cash_record_id = null;
        $this->assertFalse($this->policy->isLockedByPettyCash($invoice));
    }

    public function testGetEditLockMessageReturnsNullWhenNotLocked(): void
    {
        $invoice = new stdClass();
        // No id → isLockedByPaidScheduling no se consulta (la condición !empty($invoice->id) es false).
        $this->assertNull($this->policy->getEditLockMessage($invoice));
    }

    public function testGetEditLockMessagePettyCashTakesPrecedence(): void
    {
        $invoice = new stdClass();
        $invoice->petty_cash_record_id = 99;
        // No id → no se llega a la rama de scheduling
        $this->assertSame(
            'Factura bloqueada: pertenece al registro de Caja Menor.',
            $this->policy->getEditLockMessage($invoice)
        );
    }

    public function testGetRegressionLockMessageNullWhenNoBlockers(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $this->assertNull($this->policy->getRegressionLockMessage($invoice));
    }

    public function testGetRegressionLockMessageRejectedTakesPrecedence(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_REJECTED;
        $invoice->petty_cash_record_id = 1;
        $msg = $this->policy->getRegressionLockMessage($invoice);
        $this->assertStringContainsString('rechazada', $msg);
        $this->assertStringContainsString("Reiniciar flujo", $msg);
    }

    public function testGetRegressionLockMessagePettyCashWhenNotRejected(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $invoice->petty_cash_record_id = 5;
        $this->assertSame(
            'Factura bloqueada: pertenece a un registro de Caja Menor.',
            $this->policy->getRegressionLockMessage($invoice)
        );
    }
}
