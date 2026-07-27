<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;

/**
 * Resuelve la etiqueta del beneficiario de una factura para listados.
 * Un Recibo de Caja puede no tener proveedor: su titular puede ser un empleado
 * (equivalent_holder_type='employee') o manual (manual_document_number).
 * Espejo de la lógica de InvoiceViewViewModel:93-100 para no duplicarla en templates.
 * La rama no-RC conserva el fallback provider→employee→'—' del element genérico
 * compartido (link_invoices_modal, usado por Refunds/PettyCash): no regresiona su display.
 */
final class InvoiceBeneficiary
{
    /**
     * Resuelve la etiqueta de beneficiario de una factura para listados.
     */
    public static function label(object $invoice): string
    {
        $isReciboDeCaja = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;

        if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === InvoiceConstants::HOLDER_TYPE_EMPLOYEE) {
            return $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
        }
        if ($isReciboDeCaja && ($invoice->equivalent_holder_type ?? '') === InvoiceConstants::HOLDER_TYPE_MANUAL) {
            return $invoice->manual_document_number ?? '—';
        }
        if ($invoice->hasValue('provider')) {
            return $invoice->provider->name;
        }

        return $invoice->hasValue('employee') ? $invoice->employee->full_name : '—';
    }
}
