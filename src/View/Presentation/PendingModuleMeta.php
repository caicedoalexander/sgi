<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;
use App\Constants\NoveltyConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;

/**
 * Registry único módulo → metadatos de presentación de la bandeja Mis Pendientes.
 * Fuente anti-drift: reusa los step-sets ordenados y los mapas estado→pill de
 * cada módulo, no los redeclara.
 *
 * ⚠️ Anticipos usa el step-set/pill de FACTURAS (InvoiceConstants/InvoicePresentation),
 * NO AdvancePresentation (que está keyed por estados de legalización).
 */
final class PendingModuleMeta
{
    /**
     * @var array<string, array{label:string, moduleBadge:string, controller:string, action:string, mini:bool, steps:array<int,string>, statusLabels:array<string,string>, pills:array<string,string>}>
     */
    public const MODULES = [
        'invoices' => [
            'label' => 'Factura', 'moduleBadge' => 'pill-info-soft',
            'controller' => 'Invoices', 'action' => 'edit', 'mini' => true,
            'steps' => InvoiceConstants::PIPELINE_STATUSES,
            'statusLabels' => InvoiceConstants::STATUS_LABELS,
            'pills' => InvoicePresentation::STATUS_BADGES,
        ],
        'advances' => [
            'label' => 'Anticipo', 'moduleBadge' => 'pill-accent-soft',
            'controller' => 'Advances', 'action' => 'edit', 'mini' => true,
            'steps' => InvoiceConstants::PIPELINE_STATUSES,
            'statusLabels' => InvoiceConstants::STATUS_LABELS,
            'pills' => InvoicePresentation::STATUS_BADGES,
        ],
        'legalizations' => [
            'label' => 'Legalización', 'moduleBadge' => 'pill-primary-soft',
            'controller' => 'Advances', 'action' => 'legalization', 'mini' => true,
            'steps' => AdvanceConstants::PIPELINE_STATUSES,
            'statusLabels' => AdvanceConstants::STATUS_LABELS,
            'pills' => AdvancePresentation::STATUS_BADGES,
        ],
        'petty_cash' => [
            'label' => 'Caja Menor', 'moduleBadge' => 'pill-orange-soft',
            'controller' => 'PettyCashRecords', 'action' => 'edit', 'mini' => true,
            'steps' => PettyCashConstants::STATUSES,
            'statusLabels' => PettyCashConstants::STATUS_LABELS,
            'pills' => PettyCashPresentation::STATUS_BADGES,
        ],
        'refunds' => [
            'label' => 'Reintegro', 'moduleBadge' => 'pill-warning-soft',
            'controller' => 'Refunds', 'action' => 'edit', 'mini' => true,
            'steps' => RefundConstants::STATUSES,
            'statusLabels' => RefundConstants::STATUS_LABELS,
            'pills' => RefundPresentation::STATUS_BADGES,
        ],
        'novelties' => [
            'label' => 'Novedad', 'moduleBadge' => 'pill-muted',
            'controller' => 'EmployeeNovelties', 'action' => 'edit', 'mini' => false,
            'steps' => [],
            'statusLabels' => NoveltyConstants::STATUS_LABELS,
            'pills' => NoveltyPresentation::STATUS_BADGES,
        ],
        'liquidations' => [
            'label' => 'Liquidación', 'moduleBadge' => 'pill-muted',
            'controller' => 'NoveltyLiquidationDocs', 'action' => 'edit', 'mini' => false,
            'steps' => [],
            'statusLabels' => NoveltyConstants::STATUS_LABELS,
            'pills' => NoveltyPresentation::STATUS_BADGES,
        ],
        'payment_schedulings' => [
            'label' => 'Prog. Pago', 'moduleBadge' => 'pill-dark',
            'controller' => 'PaymentSchedulings', 'action' => 'edit', 'mini' => true,
            'steps' => PaymentSchedulingConstants::PIPELINE_STATUSES,
            'statusLabels' => PaymentSchedulingConstants::STATUS_LABELS,
            'pills' => PaymentSchedulingPresentation::STATUS_BADGES,
        ],
    ];
}
