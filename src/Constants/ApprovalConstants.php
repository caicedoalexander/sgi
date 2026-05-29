<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Acciones de la aprobación externa (links con token SHA256): el aprobador
 * aprueba o rechaza un documento.
 *
 * Compartido por los módulos que SÍ tienen aprobación externa vía el patrón
 * `ApprovalStrategyInterface`: Invoice (`InvoiceApprovalStrategy` /
 * `InvoiceApprovalService`) y Novelty (`NoveltyApprovalStrategy`), más el
 * `ExternalApprovalsController` compartido.
 *
 * Los pipelines de agrupación (PettyCash, Refund, PaymentScheduling, Advance)
 * NO tienen acción approve/reject: avanzan/regresan por rol entre estados, así
 * que no consumen estas constantes.
 */
final class ApprovalConstants
{
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';

    /** @var list<string> Acciones válidas (para validar la entrada del request). */
    public const ACTIONS = [self::ACTION_APPROVE, self::ACTION_REJECT];
}
