<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño, iconos)
 * para el pipeline de facturas. Datos puros de UI — no contiene reglas de dominio.
 */
final class InvoicePresentation
{
    public const STATUS_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
        InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-warning-soft',
        InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
        InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
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

    /** Mapping para el campo area_approval. Default no listado: 'pill-muted'. */
    public const APPROVAL_BADGES = [
        InvoiceConstants::APPROVAL_APPROVED => 'pill-primary-soft',
        InvoiceConstants::APPROVAL_REJECTED => 'pill-danger-soft',
    ];

    /** Mapping para el campo dian_validation. Default no listado: 'pill-muted'. */
    public const DIAN_BADGES = [
        InvoiceConstants::DIAN_APPROVED => 'pill-primary-soft',
        InvoiceConstants::DIAN_REJECTED => 'pill-danger-soft',
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
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            isRejected:       $isRejected,
            isApproved:       $isApproved,
            isPartialPay:     $isPartialPay,
            isPaid:           $isPaid,
            isReadyForPay:    $readyForPay,
            isOverdue:        $isOverdue,
        );
    }
}
