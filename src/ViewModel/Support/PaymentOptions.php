<?php
declare(strict_types=1);

namespace App\ViewModel\Support;

use App\Constants\InvoiceConstants;

/**
 * Options de pagos comunes a múltiples ViewModels (Invoices, Refunds,
 * PettyCash, etc.). Fuente única para los dropdowns que antes vivían
 * inline en cada template.
 */
final class PaymentOptions
{
    /**
     * Dropdown "Listo para pago": '' + Sí + Pago Prioritario.
     *
     * @return array<string,string>
     */
    public static function readyForPayment(): array
    {
        $labels = [
            InvoiceConstants::READY_FOR_PAYMENT_SI          => 'Sí',
            InvoiceConstants::READY_FOR_PAYMENT_PRIORITARIO => 'Pago Prioritario',
        ];

        return ['' => '-- Seleccione --'] + array_combine(
            InvoiceConstants::READY_FOR_PAYMENT_OPTIONS,
            array_map(
                fn($v) => $labels[$v] ?? $v,
                InvoiceConstants::READY_FOR_PAYMENT_OPTIONS,
            ),
        );
    }

    /**
     * Dropdown "Estado de pago": '' + Pago total + Pago Parcial.
     *
     * @return array<string,string>
     */
    public static function paymentStatus(): array
    {
        return [
            ''                                => '-- Seleccione --',
            InvoiceConstants::PAYMENT_FULL    => 'Pago total',
            InvoiceConstants::PAYMENT_PARTIAL => 'Pago Parcial',
        ];
    }
}
