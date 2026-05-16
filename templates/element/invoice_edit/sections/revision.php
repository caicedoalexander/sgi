<?php
/**
 * Sección "Revisión": widget de aprobadores con chips ricos (avatar +
 * nombre + cargo·timestamp + status pill + X), validación DIAN, botón
 * reset flow cuando la factura fue rechazada.
 *
 * Alineado al spec editar-factura.jsx (FldAprobadores, líneas 222-276).
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

// Status DB → slug del widget (DB: APPROVED/PENDING/REJECTED, widget: approved/pending/rejected).
$statusSlugMap = [
    InvoiceConstants::APPROVER_STATUS_APPROVED => 'approved',
    InvoiceConstants::APPROVER_STATUS_REJECTED => 'rejected',
];

// IDs de aprobadores ya seleccionados — el Select2 los excluye de la lista
// de opciones para evitar duplicados al "Agregar aprobador".
$existingIds = array_map(static fn($a) => $a->user_id, $viewModel->currentApprovals);
?>
<div class="sgi-edit-fields-grid">
    <div class="sgi-edit-field sgi-edit-field--span4">
        <label class="form-label">Aprobadores</label>

        <?php if ($isRejected && $rejector): ?>
        <div class="alert alert-warning mb-2">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;margin-top:1px;color:var(--secondary-color);" aria-hidden="true"></i>
                <div>
                    <strong>Rechazada por <?= h($rejector->user->full_name ?? $rejector->user->username ?? 'Aprobador') ?></strong>
                    <?php if ($rejector->observations): ?>
                        <div class="mt-1 sgi-fg-muted" style="font-size:.85rem;"><?= h($rejector->observations) ?></div>
                    <?php endif; ?>
                    <div class="mt-1 sgi-fg-faint" style="font-size:.8rem;">Corrija los datos y re-asigne aprobadores para reiniciar el flujo.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="sgi-approvers-widget" id="approvers-widget">
            <?php if (!empty($viewModel->currentApprovals)): ?>
                <?php foreach ($viewModel->currentApprovals as $a):
                    $name = $a->user->full_name ?? $a->user->username ?? ('Usuario #' . $a->user_id);
                    $role = $a->user->role->name ?? '';
                    $status = $statusSlugMap[$a->status] ?? 'pending';
                    $timestamp = $a->responded_at?->format('d/m H:i');
                    echo $this->element('invoice_edit/_approver_chip', [
                        'name' => $name,
                        'role' => $role,
                        'status' => $status,
                        'timestamp' => $timestamp,
                        'removable' => false,
                        'userId' => $a->user_id,
                    ]);
                endforeach; ?>
            <?php elseif (!$viewModel->canSendLinks): ?>
                <span class="sgi-approvers-empty">Sin aprobadores asignados</span>
            <?php endif; ?>

            <?php if ($viewModel->canSendLinks): ?>
                <?php /* Picker via Select2: el select queda en el form externo; el
                        "Agregar aprobador" abre el dropdown nativo de Select2. */ ?>
                <span class="sgi-approver-picker" style="display:inline-flex;align-items:center;">
                    <select name="approver_ids[]" id="approver-ids" class="select2-rich-approvers" multiple
                            form="sendApprovalLinksForm"
                            data-placeholder="Buscar aprobador…"
                            data-existing-ids="<?= h(json_encode($existingIds)) ?>">
                        <?php foreach ($viewModel->approvers as $appId => $appName): ?>
                            <option value="<?= $appId ?>"><?= h($appName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($viewModel->canSendLinks): ?>
        <div class="sgi-approvers-actions">
            <button type="submit" form="sendApprovalLinksForm"
                    class="btn btn-primary btn-sm"
                    data-sgi-confirm="¿Enviar enlaces de aprobación a los aprobadores seleccionados?">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Enviar links de aprobación
            </button>
            <span class="sgi-field-hint">Se envía independiente del botón Guardar</span>
        </div>
        <?php elseif ($viewModel->canModifyApprovers): ?>
        <div class="sgi-approvers-actions">
            <?php if ($viewModel->hasPendingApprovals): ?>
                <span class="sgi-status-chip --pending">
                    <span class="spinner-border" role="status" style="width:.65rem;height:.65rem;border-width:1.5px;"></span>
                    Aprobaciones en curso
                </span>
            <?php else: ?>
                <span class="sgi-status-chip --done">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobaciones registradas
                </span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-dark"
                    data-bs-toggle="modal" data-bs-target="#modifyApproversModal">
                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Modificar aprobadores
            </button>
            <span class="sgi-field-hint">Reemplaza el conjunto y reinicia la aprobación</span>
        </div>
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

    <div class="sgi-edit-field sgi-edit-field--span2">
        <label class="form-label">Aprobación Área</label>
        <?= $this->Form->control('area_approval', [
            'label' => false,
            'options' => $approvalOptions,
            'class' => 'form-select',
            'disabled' => true,
        ]) ?>
        <span class="sgi-field-hint mt-1">Se actualiza vía enlace externo</span>
    </div>
    <div class="sgi-edit-field sgi-edit-field--span2">
        <label class="form-label">Validación DIAN</label>
        <?= $this->Form->control('dian_validation', array_merge(
            ['label' => false, 'options' => $dianOptions],
            $canEdit('dian_validation')
                ? ['class' => 'form-select']
                : ['class' => 'form-select', 'disabled' => true]
        )) ?>
    </div>
</div>
