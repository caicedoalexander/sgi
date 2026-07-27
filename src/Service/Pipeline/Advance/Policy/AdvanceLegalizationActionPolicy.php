<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Advance\Policy;

use App\Authorization\AuthorizationFacade;
use App\Constants\PipelineStepConstants;
use App\Model\Entity\AdvanceLegalization;
use App\ValueObject\UserContext;

/**
 * Decide whether a given role is allowed to execute a mutating action on an
 * advance legalization in its current pipeline state.
 *
 * Audit MA-010 — la regla de **estado** vive en los predicates `canXxx()` de
 * `AdvanceLegalization`. Este policy compone solo la dimensión de **rol×paso**,
 * delegando esa decisión a `AuthorizationFacade` (matriz configurable
 * desde /roles/edit). El chequeo de estado sigue delegado a la entidad.
 */
final class AdvanceLegalizationActionPolicy
{
    /**
     * @param \App\Authorization\AuthorizationFacade $auth Authorization facade.
     */
    public function __construct(
        private AuthorizationFacade $auth,
    ) {
    }

    /**
     * ¿El rol puede vincular facturas a la legalización en su estado actual?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canLinkInvoices(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canLinkInvoices();
    }

    /**
     * ¿El rol puede desvincular una factura de la legalización?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canUnlinkInvoice(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUnlinkInvoice();
    }

    /**
     * ¿El rol puede adjuntar el documento de relación de facturas (PDF)?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canUploadRelationDocument(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canUploadRelationDocument();
    }

    /**
     * ¿El rol puede mover la legalización a `aprobacion`?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canMoveToAprobacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMoveToAprobacion();
    }

    /**
     * ¿El rol puede consolidar la aprobación de área en lote?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConsolidateApproval(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConsolidateApproval();
    }

    /**
     * ¿El rol puede devolver la legalización desde `aprobacion` al paso anterior?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canReturnFromAprobacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canReturnFromAprobacion();
    }

    /**
     * ¿El rol puede marcar la legalización como firmada (avanza desde revisión y firmas)?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canMarkSigned(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkSigned();
    }

    /**
     * ¿El rol puede devolver la legalización a `aprobacion`?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canReturnToAprobacion(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canReturnToAprobacion();
    }

    /**
     * ¿El rol puede marcar el caso exacto (sin faltante ni sobrante) en contabilidad?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canMarkExact(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canMarkExact();
    }

    /**
     * ¿El rol puede registrar un faltante en contabilidad?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterShortage();
    }

    /**
     * ¿El rol puede registrar un sobrante (reintegro) en contabilidad?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterSurplus(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterSurplus();
    }

    /**
     * ¿El rol puede confirmar el faltante registrado?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmShortage(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmShortage();
    }

    /**
     * ¿El rol puede gestionar (subir/eliminar) documentos de la legalización?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canManageDocuments(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canManageDocuments();
    }

    /**
     * ¿El rol puede registrar el pago del reintegro (caso sobrante)?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canRegisterRefund(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canRegisterRefund();
    }

    /**
     * ¿El rol puede autorizar el pago del reintegro?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canAuthorizeRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canAuthorizeRefundPayment();
    }

    /**
     * ¿El rol puede confirmar la ejecución del pago del reintegro?
     *
     * @param \App\Model\Entity\AdvanceLegalization $leg Legalización de anticipo.
     * @param int $roleId Role ID.
     * @return bool
     */
    public function canConfirmRefundPayment(AdvanceLegalization $leg, int $roleId): bool
    {
        return $this->_canOperate($roleId, $leg->status) && $leg->canConfirmRefundPayment();
    }

    /**
     * True cuando el rol puede operar el paso actual de la legalización.
     * Alimenta el banner de solo lectura de `templates/Advances/legalization.php`.
     *
     * La guarda `isLegalized()` es redundante — `legalizada` no figura en
     * `STEPS_BY_PIPELINE`, así que `canOperate` ya devolvería false — pero se
     * conserva explícita por paridad con `RefundActionPolicy::canOperateCurrentStep`.
     */
    public function canOperateCurrentStep(AdvanceLegalization $leg, int $roleId): bool
    {
        if ($leg->isLegalized()) {
            return false;
        }

        return $this->_canOperate($roleId, (string)$leg->status);
    }

    /**
     * Pasos del pipeline `legalizations` que el rol puede operar. Filtra la
     * bandeja `pendingLegalization` y el badge del sidebar.
     *
     * Nombre alineado con los 5 `{Modulo}PipelineService::getVisibleStatuses()`
     * hermanos. Vive en el policy y no en `AdvanceLegalizationService` porque ese
     * service no es un coordinador de pipeline (ver CLAUDE.md) y no inyecta
     * `AuthorizationFacade`.
     *
     * @return array<string>
     */
    public function getVisibleStatuses(int $roleId): array
    {
        return $this->auth->operableSteps(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
        );
    }

    /**
     * ¿El rol puede operar el paso indicado del pipeline `legalizations`?
     *
     * @param int $roleId Role ID.
     * @param string $step Pipeline step.
     * @return bool
     */
    private function _canOperate(int $roleId, string $step): bool
    {
        return $this->auth->canOperate(
            new UserContext($roleId),
            PipelineStepConstants::PIPELINE_LEGALIZATIONS,
            $step,
        );
    }
}
