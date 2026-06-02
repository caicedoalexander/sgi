<?php
declare(strict_types=1);

namespace App\Test\TestCase\ViewModel\Support;

use App\Constants\InvoiceConstants;
use App\ViewModel\Support\PaymentOptions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para PaymentOptions — dropdowns de pago compartidos entre VMs.
 */
final class PaymentOptionsTest extends TestCase
{
    public function testReadyForPaymentHasPlaceholderAndLabels(): void
    {
        $options = PaymentOptions::readyForPayment();

        $this->assertSame('-- Seleccione --', $options['']);
        $this->assertSame('Sí', $options[InvoiceConstants::READY_FOR_PAYMENT_SI]);
        $this->assertSame('Pago Prioritario', $options[InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO]);
    }

    public function testReadyForPaymentPlaceholderIsFirst(): void
    {
        $this->assertSame('', array_key_first(PaymentOptions::readyForPayment()));
    }

    public function testPaymentStatusOptions(): void
    {
        $options = PaymentOptions::paymentStatus();

        $this->assertSame('-- Seleccione --', $options['']);
        $this->assertSame('Pago total', $options[InvoiceConstants::PAYMENT_FULL]);
        $this->assertSame('Pago Parcial', $options[InvoiceConstants::PAYMENT_PARTIAL]);
    }
}
