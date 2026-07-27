<?php
/**
 * Panel de aprobación de grupo — Reintegros en estado `aprobacion`.
 * Adaptado del panel de aprobadores de Invoices/edit.php (sección `revision`):
 * chips de aprobadores por estado (`$viewModel->currentApprovals`), asignación
 * (select múltiple ligado al form oculto `sendApprovalLinksForm`) y modificación
 * (modal, postea a `modifyApprovers`) cuando ya hay aprobaciones activas.
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\RefundEditViewModel $viewModel
 * @var \App\Model\Entity\Refund $record
 */

use App\Constants\InvoiceConstants;

$statusSlugMap = [
    InvoiceConstants::APPROVER_STATUS_APPROVED => 'approved',
    InvoiceConstants::APPROVER_STATUS_REJECTED => 'rejected',
];
$existingIds = array_map(static fn($a) => $a->user_id, $viewModel->currentApprovals);
$hasActiveApprovals = !empty($viewModel->currentApprovals);
?>
<div class="mb-4">
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="spi-label flex-shrink-0">
            <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Aprobación
        </span>
        <div class="hr"></div>
    </div>

    <div class="spi-approvers-widget d-flex flex-wrap gap-2" id="refund-approvers-widget">
        <?php if ($hasActiveApprovals): ?>
            <?php foreach ($viewModel->currentApprovals as $a):
                echo $this->element('invoice_edit/_approver_chip', [
                    'name' => $a->user->full_name ?? $a->user->username ?? 'Usuario #' . $a->user_id,
                    'role' => $a->user->role->name ?? '',
                    'status' => $statusSlugMap[$a->status] ?? 'pending',
                    'timestamp' => $a->responded_at?->format('d/m H:i'),
                    'removable' => false,
                    'userId' => $a->user_id,
                ]);
            endforeach; ?>
        <?php elseif (!$viewModel->canSendLinks): ?>
            <span style="font-size:var(--fs-body-sm);color:var(--text-faint);">Sin aprobadores asignados</span>
        <?php endif; ?>

        <?php if ($viewModel->canSendLinks): ?>
            <span style="width:100%;">
                <select name="approver_ids[]" id="refund-approver-ids" class="form-select select2-enable" multiple
                        form="sendApprovalLinksForm"
                        data-placeholder="+ Agregar aprobador"
                        data-existing-ids="<?= h(json_encode($existingIds)) ?>">
                    <?php foreach ($viewModel->approvers as $appId => $appName): ?>
                        <option value="<?= $appId ?>"><?= h($appName) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($viewModel->canSendLinks): ?>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
        <button type="submit" form="sendApprovalLinksForm" class="btn btn-primary btn-sm"
                data-spi-confirm="¿Enviar enlaces de aprobación a los aprobadores seleccionados?">
            <i class="bi bi-send" aria-hidden="true"></i>Enviar links de aprobación
        </button>
        <span class="input-help">Se envía independiente del botón Guardar</span>
    </div>
    <div class="input-help mt-1">Seleccione una o más personas que deben aprobar este reintegro.</div>
    <?php elseif ($hasActiveApprovals): ?>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
        <?php if ($viewModel->hasPendingApprovals): ?>
            <span class="pill pill-warning-soft">
                <span class="spinner-border" role="status"
                      style="width:.65rem;height:.65rem;border-width:1.5px;"></span>
                Aprobaciones en curso
            </span>
        <?php else: ?>
            <span class="pill pill-primary-soft">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobaciones registradas
            </span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-dark"
                data-bs-toggle="modal" data-bs-target="#modifyRefundApproversModal">
            <i class="bi bi-pencil" aria-hidden="true"></i>Modificar aprobadores
        </button>
        <span class="input-help">Reemplaza el conjunto y reinicia la aprobación</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($hasActiveApprovals): ?>
<div class="modal fade" id="modifyRefundApproversModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= $this->Url->build(['action' => 'modifyApprovers', $record->id]) ?>">
                <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
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
                        <label class="form-label">Nuevos aprobadores</label>
                        <select name="approver_ids[]" class="form-select select2-enable" multiple required
                                data-placeholder="Seleccione...">
                            <?php foreach ($viewModel->approvers as $id => $name): ?>
                                <option value="<?= $id ?>"><?= h($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Motivo *</label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Indique por qué se modifican los aprobadores"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Guardar y reenviar enlaces
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
