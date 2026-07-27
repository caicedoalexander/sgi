<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\InvoiceConstants;
use App\Model\Entity\Invoice;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Configuración de presentación (clases pill del Sistema de Diseño)
 * para el pipeline de facturas. Datos puros de UI — no contiene reglas de dominio.
 */
final class InvoicePresentation
{
    // Pills unificados vía PipelineColorMap (un color por tipo de estado en
    // todos los módulos). Test guard: PipelineColorConsistencyTest.
    public const STATUS_BADGES = [
        InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
        InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-orange-soft',
        InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
        InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
        InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-accent-soft',
        InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
        InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
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

    /** Badge del estado de soporte en la tabla de hijas. */
    public const SUPPORT_BADGES = [
        'ok'      => 'pill-primary-soft',
        'missing' => 'pill-warning-soft',
        'na'      => 'pill-muted',
    ];

    /**
     * Mapa de estado de aprobador → pill + icono + label, para el chip de
     * aprobador (element invoice_edit/_approver_chip). Fallback: 'pending'.
     */
    public const APPROVER_STATUS_MAP = [
        'approved' => ['pill' => 'pill-primary-soft', 'icon' => 'bi-check2', 'label' => 'Aprobado'],
        'pending'  => ['pill' => 'pill-warning-soft', 'icon' => 'bi-clock',  'label' => 'Pendiente'],
        'rejected' => ['pill' => 'pill-danger-soft',  'icon' => 'bi-x',      'label' => 'Rechazado'],
    ];

    /**
     * Mapa de clave foránea de contención → presentación del badge. Espejo de
     * InvoiceConstants::PARENT_FOREIGN_KEYS. El prefijo del código ya identifica
     * el tipo (CM-, RE-, ANT-), así que el badge muestra el código pelado.
     */
    public const PARENT_BADGES = [
        'petty_cash_record_id' => [
            'label' => 'Caja menor',
            'association' => 'petty_cash_record',
            'code_field' => 'code',
            'controller' => 'PettyCashRecords',
            'icon' => 'bi-link-45deg',
        ],
        'refund_id' => [
            'label' => 'Reintegro',
            'association' => 'refund',
            'code_field' => 'code',
            'controller' => 'Refunds',
            'icon' => 'bi-link-45deg',
        ],
        'advance_id' => [
            'label' => 'Anticipo',
            'association' => 'advance',
            'code_field' => 'invoice_number',
            'controller' => 'Advances',
            'icon' => 'bi-link-45deg',
        ],
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

        $isLegalization = $invoice->usesLegalizationView();
        $steps          = $isLegalization
            ? InvoiceConstants::PIPELINE_STATUSES_LEGALIZACION
            : InvoiceConstants::PIPELINE_STATUSES;
        $stageIdx       = array_search($status, $steps, true);
        $stageIdx       = $stageIdx === false ? -1 : $stageIdx;

        $linkBadge = self::parentBadge($invoice) ?? self::schedulingBadge($invoice);

        $pipelineVariant = $isRejected ? 'is-danger' : self::pipelineVariant($status);
        $pillClass       = $isRejected
            ? 'pill-danger-soft'
            : (self::STATUS_BADGES[$status] ?? 'pill-muted');

        // El padre gobierna el pipeline: la factura ni lo dibuja ni lo colorea.
        if ($linkBadge?->isContainment) {
            $stageIdx  = -1;
            $pillClass = 'pill-muted';
        }

        return new InvoiceRowView(
            statusLabel: InvoiceConstants::STATUS_LABELS[$status] ?? 'Desconocido',
            statusBadgeClass: self::STATUS_BADGES[$status] ?? 'pill-muted',
            isRejected: $isRejected,
            isApproved: $isApproved,
            isPartialPay: $isPartialPay,
            isPaid: $isPaid,
            isReadyForPay: $readyForPay,
            isOverdue: $isOverdue,
            isLegalization: $isLegalization,
            pipelineSteps: $steps,
            stageIdx: $stageIdx,
            pipelineVariant: $pipelineVariant,
            pillClass: $pillClass,
            linkBadge: $linkBadge,
        );
    }

    /**
     * DTO de fila para la tabla de facturas hijas dentro de la vista del padre.
     * Requiere que el caller haya contenido Providers, Employees e InvoiceDocuments.
     */
    public static function forGroupedRow(Invoice $invoice, bool $canResolveDian): GroupedInvoiceRowView
    {
        $status = $invoice->pipeline_status ?? '';
        $documentType = $invoice->document_type ?? null;
        $requiresDian = DocumentTypePolicyFactory::requiresDianFor($documentType);
        $dianValue = $invoice->dian_validation ?? InvoiceConstants::DIAN_PENDING;

        $dianMode = 'pill';
        if (!$requiresDian) {
            $dianMode = 'na';
        } elseif ($canResolveDian && $status === InvoiceConstants::STATUS_APROBACION) {
            $dianMode = 'select';
        }

        $docsCount = count($invoice->invoice_documents ?? []);
        $supportRequired = DocumentTypePolicyFactory::requiresSupportFor($documentType);

        return new GroupedInvoiceRowView(
            id: (int)$invoice->id,
            number: (string)($invoice->invoice_number ?: '#' . $invoice->id),
            beneficiaryName: InvoiceBeneficiary::label($invoice),
            documentType: (string)($invoice->document_type ?? ''),
            amount: (float)$invoice->amount,
            issueDate: $invoice->issue_date?->format('d/m/Y'),
            statusLabel: InvoiceConstants::STATUS_LABELS[$status] ?? $status,
            statusPill: self::STATUS_BADGES[$status] ?? 'pill-muted',
            dianMode: $dianMode,
            dianValue: $dianValue,
            dianPill: self::DIAN_BADGES[$dianValue] ?? 'pill-muted',
            supportRequired: $supportRequired,
            docsCount: $docsCount,
            supportOk: !$supportRequired || $docsCount > 0,
            childStatus: $status,
        );
    }

    /** Variante de color para .pipeline-mini según estado actual (no-rechazado). */
    private static function pipelineVariant(string $status): string
    {
        return PipelineColorMap::variant($status);
    }

    /**
     * Badge del registro padre que gobierna el pipeline de la factura, si lo hay.
     *
     * Devuelve null cuando la asociación no viene contenida en el query: la
     * bandeja no la contiene porque allí la FK siempre es NULL. Un badge que
     * falta en una vista que sí debería mostrarlo lo caza el test de all().
     */
    public static function parentBadge(Invoice $invoice): ?InvoiceLinkBadge
    {
        foreach (InvoiceConstants::PARENT_FOREIGN_KEYS as $fk) {
            if (empty($invoice->{$fk})) {
                continue;
            }

            $cfg = self::PARENT_BADGES[$fk];
            if (!$invoice->hasValue($cfg['association'])) {
                return null;
            }

            return new InvoiceLinkBadge(
                code: (string)$invoice->{$cfg['association']}->{$cfg['code_field']},
                label: $cfg['label'],
                icon: $cfg['icon'],
                isContainment: true,
                controller: $cfg['controller'],
                parentId: (int)$invoice->{$fk},
                pillClass: 'pill-muted',
            );
        }

        return null;
    }

    /**
     * Badge de la programación de pagos más reciente que agenda esta factura.
     * Referencia, no contención: el estado de la factura no lo toca nadie.
     *
     * Nada en la base impide que una factura esté en dos programaciones
     * (payment_scheduling_items.invoice_id no es único). Se muestra la más
     * reciente; el contain la ordena por id DESC.
     */
    private static function schedulingBadge(Invoice $invoice): ?InvoiceLinkBadge
    {
        if (!$invoice->hasValue('payment_scheduling_items')) {
            return null;
        }

        $item = $invoice->payment_scheduling_items[0] ?? null;
        if ($item === null || !$item->hasValue('payment_scheduling')) {
            return null;
        }

        return new InvoiceLinkBadge(
            code: (string)$item->payment_scheduling->code,
            label: 'Programación',
            icon: 'bi-calendar-event',
            isContainment: false,
            controller: 'PaymentSchedulings',
            parentId: (int)$item->payment_scheduling->id,
            pillClass: 'pill-ref',
        );
    }
}
