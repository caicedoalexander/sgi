<?php
/**
 * Sección "Revisión": aprobadores externos (multi), alerta de rechazo,
 * estado de aprobaciones individuales, validación DIAN, botón reset
 * flow cuando la factura fue rechazada.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var callable $canEdit
 * @var array<string,string> $approvalOptions
 * @var array<string,string> $dianOptions
 */

use App\Constants\InvoiceConstants;

$isRejected = ($viewModel->invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
$rejector = null;
if ($isRejected) {
    foreach ($viewModel->currentApprovals as $a) {
        if ($a->status === InvoiceConstants::APPROVER_STATUS_REJECTED) { $rejector = $a; break; }
    }
}
?>
<div class="mb-4 ">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi bi-search me-1" aria-hidden="true"></i>Revisión
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>
    <?php if ($isRejected): ?>
        <div class="alert alert-warning mb-3" style="border:1px solid var(--warning-color);border-left:3px solid #CD6A15;border-radius:0;">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill" style="color:#CD6A15;font-size:1.1rem;margin-top:1px;" aria-hidden="true"></i>
                <div>
                    <strong>Rechazada por <?= h($rejector->user->full_name ?? $rejector->user->username ?? 'Aprobador') ?></strong>
                    <?php if ($rejector && $rejector->observations): ?>
                        <div class="mt-1" style="font-size:.85rem;color:#555;"><?= h($rejector->observations) ?></div>
                    <?php endif; ?>
                    <div class="mt-1" style="font-size:.8rem;color:#888;">Corrija los datos y re-asigne aprobadores para reiniciar el flujo.</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Aprobadores</label>
            <?php if ($viewModel->canSendLinks): ?>
                <select name="approver_ids[]" id="approver-ids" class="form-select select2-enable" multiple
                        form="sendApprovalLinksForm"
                        data-placeholder="Seleccione los aprobadores...">
                    <?php foreach ($viewModel->approvers as $appId => $appName): ?>
                        <option value="<?= $appId ?>"><?= h($appName) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <button type="submit" form="sendApprovalLinksForm"
                            class="btn btn-primary btn-sm"
                            onclick="return confirm('¿Enviar enlaces de aprobación a los aprobadores seleccionados?');">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Enviar links
                    </button>
                    <span class="sgi-field-hint">Independiente del botón Guardar</span>
                </div>
            <?php elseif ($viewModel->canModifyApprovers): ?>
                <?php if ($viewModel->hasPendingApprovals): ?>
                <div class="sgi-status-chip --pending">
                    <span class="spinner-border" role="status" style="width:.65rem;height:.65rem;border-width:1.5px;"></span>
                    Aprobaciones en curso
                </div>
                <?php else: ?>
                <div class="sgi-status-chip --done">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobaciones registradas
                </div>
                <?php endif; ?>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modifyApproversModal">
                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Modificar aprobadores
                    </button>
                    <span class="sgi-field-hint">Reemplaza el conjunto y reinicia la aprobación</span>
                </div>
            <?php else: ?>
                <div class="sgi-status-chip --muted">No editable en este estado</div>
            <?php endif; ?>

            <?php if ($isRejected
                && !empty($viewModel->editableFields)
                && $viewModel->currentStatus === InvoiceConstants::STATUS_APROBACION): ?>
            <form method="post" action="<?= $this->Url->build(['action' => 'resetFlow', $viewModel->invoice->id]) ?>"
                  class="mt-2" onsubmit="return confirm('¿Reiniciar flujo? Se limpiarán aprobaciones y se permitirá reenviar enlaces.');">
                <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                <button type="submit" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reiniciar flujo
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <label class="form-label d-flex align-items-center gap-1">
                Aprobación Área <span class="sgi-field-hint">· vía enlace externo</span>
            </label>
            <?= $this->Form->control('area_approval', [
                'label' => false,
                'options' => $approvalOptions,
                'class' => 'form-select',
                'disabled' => true,
            ]) ?>
        </div>
        <?php if ($viewModel->invoice->area_approval_date): ?>
        <div class="col-md-3">
            <label class="form-label">Fecha Aprobación</label>
            <input type="text" class="form-control" disabled
                   value="<?= h($viewModel->invoice->area_approval_date?->format('d/m/Y') ?? '') ?>">
        </div>
        <?php endif; ?>

        <?php if (!empty($viewModel->currentApprovals)): ?>
        <div class="col-12 mt-2">
            <?php
            $totalApprovals = count($viewModel->currentApprovals);
            $approvedCount = 0;
            $rejectedCount = 0;
            $pendingCount = 0;
            foreach ($viewModel->currentApprovals as $a) {
                match ($a->status) {
                    InvoiceConstants::APPROVER_STATUS_APPROVED => $approvedCount++,
                    InvoiceConstants::APPROVER_STATUS_REJECTED => $rejectedCount++,
                    default => $pendingCount++,
                };
            }
            ?>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <label class="form-label mb-0">Estado de Aprobaciones</label>
                <div class="d-flex gap-2" style="font-size:.75rem;">
                    <?php if ($approvedCount > 0): ?>
                        <span class="badge bg-success"><?= $approvedCount ?> aprobada<?= $approvedCount > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-secondary"><?= $pendingCount ?> pendiente<?= $pendingCount > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                    <?php if ($rejectedCount > 0): ?>
                        <span class="badge bg-danger"><?= $rejectedCount ?> rechazada<?= $rejectedCount > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
                <?php foreach ($viewModel->currentApprovals as $i => $approval): ?>
                    <?php
                    $statusIcon = match ($approval->status) {
                        InvoiceConstants::APPROVER_STATUS_APPROVED => '<i class="bi bi-check-circle-fill" style="color:#469D61;" aria-hidden="true"></i>',
                        InvoiceConstants::APPROVER_STATUS_REJECTED => '<i class="bi bi-x-circle-fill" style="color:var(--danger-color);" aria-hidden="true"></i>',
                        default => '<i class="bi bi-clock" style="color:#888;" aria-hidden="true"></i>',
                    };
                    $borderBottom = $i < $totalApprovals - 1 ? 'border-bottom:1px solid var(--border-color);' : '';
                    ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2" style="<?= $borderBottom ?>font-size:.875rem;">
                        <div style="flex:0 0 20px;"><?= $statusIcon ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="fw-medium"><?= h($approval->user->full_name ?? $approval->user->username) ?></div>
                            <?php if ($approval->observations): ?>
                                <div style="font-size:.78rem;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= h($approval->observations) ?>">
                                    <?= h($approval->observations) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end" style="flex:0 0 auto;font-size:.78rem;color:#888;">
                            <?= $approval->responded_at ? $approval->responded_at->format('d/m/Y H:i') : 'Pendiente' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-4">
            <label class="form-label">Validación DIAN</label>
            <?= $this->Form->control('dian_validation', array_merge(
                ['label' => false, 'options' => $dianOptions],
                $canEdit('dian_validation')
                    ? ['class' => 'form-select']
                    : ['class' => 'form-select', 'disabled' => true]
            )) ?>
        </div>
    </div>
</div>
