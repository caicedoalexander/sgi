<?php
/**
 * Panel de aprobación de área en lote — Legalización de Anticipos en estado
 * `aprobacion`. Espejo de `templates/element/refund_edit/_approval_panel.php`:
 * asignación de aprobadores, tabla de estado por aprobador y consolidación.
 *
 * Nota M1 (eje de id): todos los forms de este panel postean a
 * `$invoice->id` (= `$leg->advance_invoice_id`), NO `$leg->id` —
 * `AdvancesController::_loadLegalization` resuelve por `advance_invoice_id`.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\Invoice $invoice
 * @var array<int, \App\Model\Entity\AdvanceLegalizationApproval> $approvals
 * @var array{total:int,approved:int,rejected:int,pending:int} $approvalSummary
 * @var bool $canManageApprovers
 * @var array<int,string> $approvers
 */

use App\Constants\InvoiceConstants;

$statusSlugMap = [
    InvoiceConstants::APPROVER_STATUS_APPROVED => 'approved',
    InvoiceConstants::APPROVER_STATUS_REJECTED => 'rejected',
];
$hasApprovals = !empty($approvals);
$canAssignApprovers = $canManageApprovers && $approvalSummary['total'] === 0;
$canAdvanceApproval = $canManageApprovers
    && $approvalSummary['total'] > 0
    && $approvalSummary['pending'] === 0;
?>
<div class="spi-card" style="position:relative">
    <div class="accent-strip accent-green"></div>
    <div class="spi-section-head">
        <span class="spi-label d-inline-flex align-items-center gap-2">
            <i class="bi bi-check2-square" aria-hidden="true"></i>Aprobación de área
        </span>
    </div>

    <?php if ($hasApprovals || !$canAssignApprovers): ?>
    <div class="spi-approvers-widget d-flex flex-wrap gap-2">
        <?php if ($hasApprovals): ?>
            <?php foreach ($approvals as $a):
                echo $this->element('invoice_edit/_approver_chip', [
                    'name' => $a->user->full_name ?? $a->user->username ?? 'Usuario #' . $a->user_id,
                    'role' => $a->user->role->name ?? '',
                    'status' => $statusSlugMap[$a->status] ?? 'pending',
                    'timestamp' => $a->responded_at?->format('d/m H:i'),
                    'removable' => false,
                    'userId' => $a->user_id,
                ]);
            endforeach; ?>
        <?php else: ?>
            <span style="font-size:var(--fs-body-sm);color:var(--text-faint);">Sin aprobadores asignados</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canAssignApprovers): ?>
    <?= $this->Form->create(null, ['url' => ['action' => 'sendApprovalLinks', $invoice->id], 'class' => 'mt-3']) ?>
    <label class="input-label">Aprobadores</label>
    <select name="approver_ids[]" class="form-select select2-enable" multiple required data-placeholder="Seleccione...">
        <?php foreach ($approvers as $appId => $appName): ?>
        <option value="<?= (int)$appId ?>"><?= h($appName) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-send" aria-hidden="true"></i>Enviar enlaces de aprobación
        </button>
        <span class="input-help">Seleccione una o más personas que deben aprobar esta legalización.</span>
    </div>
    <?= $this->Form->end() ?>
    <?php endif; ?>

    <?php if ($canManageApprovers && $hasApprovals): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <button type="button" class="btn btn-default" data-bs-toggle="modal" data-bs-target="#advModifyApproversModal">
            <i class="bi bi-pencil" aria-hidden="true"></i>Modificar aprobadores
        </button>
        <?= $this->Form->create(null, ['url' => ['action' => 'moveToRevision', $invoice->id]]) ?>
        <button type="submit" class="btn btn-primary" <?= $canAdvanceApproval ? '' : 'disabled' ?>
                data-spi-confirm="¿Consolidar la aprobación y avanzar a Revisión y Firmas?">
            <i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Avanzar
        </button>
        <?= $this->Form->end() ?>
        <?php if (!$canAdvanceApproval): ?>
        <small class="text-muted">Requiere que todos los aprobadores respondan (sin pendientes).</small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canManageApprovers): ?>
    <div class="mt-2">
        <?= $this->Form->postLink(
            '<i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Regresar a Validación',
            ['action' => 'returnFromAprobacion', $invoice->id],
            ['class' => 'btn btn-ghost-card spi-fg-warning', 'escape' => false, 'confirm' => '¿Regresar a Validación para editar el grupo? Las aprobaciones activas quedarán invalidadas.']
        ) ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManageApprovers && $hasApprovals): ?>
<div class="modal fade" id="advModifyApproversModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <?= $this->Form->create(null, ['url' => ['action' => 'modifyApprovers', $invoice->id]]) ?>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Modificar aprobadores
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2" style="font-size:.8rem;">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    Los enlaces de aprobación previos quedarán invalidados.
                </div>
                <div class="mb-3">
                    <label class="input-label">Nuevos aprobadores</label>
                    <select name="approver_ids[]" class="form-select select2-enable" multiple required data-placeholder="Seleccione...">
                        <?php foreach ($approvers as $appId => $appName): ?>
                        <option value="<?= (int)$appId ?>"><?= h($appName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="input-label">Motivo *</label>
                    <?= $this->Form->control('reason', ['type' => 'textarea', 'rows' => 3, 'class' => 'form-control', 'required' => true, 'label' => false]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-send me-1" aria-hidden="true"></i>Guardar y reenviar enlaces
                </button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
<?php endif; ?>
