<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Configuración de presentación (badges Bootstrap, iconos) para el pipeline
 * de facturas. Datos puros de UI — no contiene reglas de dominio.
 */
final class InvoicePresentation
{
    public const STATUS_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'bg-warning text-dark',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-secondary',
        InvoiceConstants::STATUS_TESORERIA         => 'bg-info',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-warning text-dark',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bg-warning',
        InvoiceConstants::STATUS_PAGADA            => 'bg-success',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bg-success',
    ];

    /**
     * Badges del header del formulario de edición. Distintos a STATUS_BADGES
     * (énfasis visual más fuerte en el contexto del edit, donde el badge es
     * el ancla del workflow). NO consolidar con STATUS_BADGES sin alinear con
     * producto — audit CR-203.
     */
    public const EDIT_HEADER_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'bg-info text-dark',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-primary',
        InvoiceConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bg-warning text-dark',
        InvoiceConstants::STATUS_PAGADA            => 'bg-success',
    ];

    public const STATUS_ICONS = [
        InvoiceConstants::STATUS_APROBACION        => 'bi-check-circle',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'bi-calculator',
        InvoiceConstants::STATUS_TESORERIA         => 'bi-bank',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bi-shield-check',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'bi-hourglass-split',
        InvoiceConstants::STATUS_PAGADA            => 'bi-cash-coin',
        InvoiceConstants::STATUS_LEGALIZADA        => 'bi-cash-coin',
    ];

    /**
     * Construye el DTO de fila para Invoices/index.
     * Encapsula todas las derivaciones de estado que antes vivían inline en el
     * template (MJ-003 del audit 2026-05-11).
     */
    public static function forRow(Invoice $invoice, ?DateTimeInterface $today = null): InvoiceRowView
    {
        $today        = $today ?? new DateTimeImmutable('today');
        $status       = $invoice->pipeline_status ?? '';
        $isRejected   = ($invoice->area_approval === InvoiceConstants::APPROVAL_REJECTED);
        $isApproved   = ($status === InvoiceConstants::STATUS_APROBACION
                         && $invoice->area_approval === InvoiceConstants::APPROVAL_APPROVED);
        $isPartialPay = ($status === InvoiceConstants::STATUS_TESORERIA
                         && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL);
        $isPaid       = ($status === InvoiceConstants::STATUS_PAGADA);
        $readyForPay  = (!empty($invoice->ready_for_payment) && $invoice->ready_for_payment !== 'No');
        $isOverdue    = $invoice->due_date !== null
                        && !$isPaid
                        && !$isRejected
                        && $invoice->due_date < $today;

        return new InvoiceRowView(
            statusLabel:      InvoiceConstants::STATUS_LABELS[$status] ?? 'Desconocido',
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'bg-dark',
            isRejected:       $isRejected,
            isApproved:       $isApproved,
            isPartialPay:     $isPartialPay,
            isPaid:           $isPaid,
            isReadyForPay:    $readyForPay,
            isOverdue:        $isOverdue,
        );
    }
}
