<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\InvoiceConstants;
use App\Service\InvoiceLockGuard;
use App\Service\Pipeline\Invoice\Policy\InvoiceLockPolicy;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests para InvoiceLockPolicy. Las ramas puras (petty cash, rechazo) se
 * cubren sin tocar BD. La rama dependiente de InvoicePayments +
 * PaymentSchedulings (vía `isLockedByPaidScheduling`) ya no requiere fixtures:
 * el acceso a BD vive ahora en InvoiceLockGuard, y aquí se inyecta un Guard
 * fake que fuerza el lock por programación pagada.
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
            $this->policy->getEditLockMessage($invoice),
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
        $this->assertStringContainsString('Reiniciar flujo', $msg);
    }

    public function testGetRegressionLockMessagePettyCashWhenNotRejected(): void
    {
        $invoice = new stdClass();
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $invoice->petty_cash_record_id = 5;
        $this->assertSame(
            'Factura bloqueada: pertenece a un registro de Caja Menor.',
            $this->policy->getRegressionLockMessage($invoice),
        );
    }

    public function testGetEditLockMessageScheduledLockViaGuard(): void
    {
        $policy = new InvoiceLockPolicy($this->fakeGuard(true));
        $invoice = new stdClass();
        $invoice->id = 42;
        $this->assertSame(
            'Factura bloqueada: tiene pagos de una programación ya pagada.',
            $policy->getEditLockMessage($invoice),
        );
    }

    public function testGetRegressionLockMessageScheduledLockViaGuard(): void
    {
        $policy = new InvoiceLockPolicy($this->fakeGuard(true));
        $invoice = new stdClass();
        $invoice->id = 42;
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $this->assertSame(
            'Factura bloqueada: tiene pagos en una programación ya pagada.',
            $policy->getRegressionLockMessage($invoice),
        );
    }

    public function testScheduledLockNotTriggeredWhenGuardReturnsFalse(): void
    {
        $policy = new InvoiceLockPolicy($this->fakeGuard(false));
        $invoice = new stdClass();
        $invoice->id = 42;
        $invoice->area_approval = InvoiceConstants::APPROVAL_APPROVED;
        $this->assertNull($policy->getEditLockMessage($invoice));
        $this->assertNull($policy->getRegressionLockMessage($invoice));
    }

    /**
     * Guard fake que fuerza el resultado de la consulta a BD.
     */
    private function fakeGuard(bool $linked): InvoiceLockGuard
    {
        return new class ($linked) extends InvoiceLockGuard {
            public function __construct(private bool $linked)
            {
            }

            public function hasPaidSchedulingLink(int $invoiceId): bool
            {
                return $this->linked;
            }
        };
    }
}
