<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\RefundEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */
use App\Constants\RefundConstants;
use App\View\Presentation\RefundPresentation;

$this->assign('title', $viewModel->pageTitle);

$record              = $viewModel->record;
$availableInvoices   = $viewModel->availableInvoices;
$operationCenters    = $viewModel->operationCenters;
$groupFilters        = $viewModel->groupFilters;
$employees           = $viewModel->employees;
$providers           = $viewModel->providers;
$bankingEntities     = $viewModel->bankingEntities;
$previousStatus      = $viewModel->previousStatus;
$regressLockMessage  = $viewModel->regressLockMessage;
$currentStatus       = $viewModel->currentStatus;
$advanceErrors       = $viewModel->advanceErrors;
$canRegisterPayment  = $viewModel->canRegisterPayment;
$canAuthorizePayment = $viewModel->canAuthorizePayment;
$canConfirmPayment   = $viewModel->canConfirmPayment;
$canRegress          = $viewModel->canRegress;
$syntheticPayments   = $viewModel->syntheticPayments;
$pipelineLabels      = $viewModel->pipelineLabels;
$roleName            = $viewModel->roleName;
$statusLabels        = $viewModel->statusLabels;
$readyForPaymentOptions = $viewModel->readyForPaymentOptions;
$showAccounting      = $viewModel->showAccounting;
$showTreasury        = $viewModel->showTreasury;
$canEditAccounting   = $viewModel->canEditAccounting;
$canEditTreasury     = $viewModel->canEditTreasury;
$canSave             = $viewModel->canSave;
$canAdvance          = $viewModel->canAdvance;
$btnLabel            = $viewModel->submitButtonHtml;
$btnClass            = $viewModel->submitButtonClass;
$invoiceCount        = $viewModel->invoiceCount;

$rfStatusPills = RefundPresentation::STATUS_BADGES;
$rfStatusPill  = $rfStatusPills[$record->status] ?? 'pill-muted';
$rfStatusLabel = $statusLabels[$record->status] ?? $record->status;

$isTerminal = $record->status === RefundConstants::STATUS_PAGADA;
$bName  = $record->getBeneficiaryName();
$bLabel = RefundConstants::BENEFICIARY_TYPES_LABELS[$record->beneficiary_type] ?? null;
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Reintegros', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($record->code), ['action' => 'view', $record->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Editar Reintegro</span>
            <span class="sgi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $rfStatusPill ?>"><?= h($rfStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye" aria-hidden="true"></i>Ver',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<!-- Alerta de avance pendiente -->
<?php if ($canAdvance && !empty($advanceErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($advanceErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->Form->create($record, ['id' => 'refundEditForm']) ?>
<?= $this->Form->hidden('expected_status', ['value' => $record->status]) ?>

<div class="sgi-invoice-view-grid view-anim">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="sgi-invoice-view-left">
        <?php
        // Acciones del sidebar (regresión)
        $actionsHtml = null;
        if (!empty($canRegress)):
            $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
            $isLocked = !empty($regressLockMessage);
            ob_start();
            if ($isLocked): ?>
                <button type="button" class="btn" disabled title="<?= h($regressLockMessage) ?>">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
                </button>
            <?php else: ?>
                <button type="button" class="btn"
                        data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
                </button>
            <?php endif;
            $actionsHtml = ob_get_clean();
        endif;

        $registryLines = [
            ['icon' => 'bi-person', 'html' => 'Rol: <strong style="color:var(--text-default);">' . h($roleName) . '</strong>'],
        ];
        if ($record->hasValue('created_by_user')) {
            $registryLines[] = ['icon' => 'bi-person-badge', 'html' => 'Creado por ' . h($record->created_by_user->full_name)];
        }
        if ($record->created) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y') . '</span>'];
        }

        // Sub-label: beneficiario
        $subLabel = null;
        if ($bName) {
            $subLabel = $bName . ($bLabel ? ' (' . $bLabel . ')' : '');
        }

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'arrow-counterclockwise',
            'idLabel'        => $record->code,
            'typeLabel'      => 'Reintegro',
            'statusPill'     => $rfStatusPill,
            'statusLabel'    => $rfStatusLabel,
            'entityLabel'    => 'Beneficiario',
            'entityValue'    => $bName ?? '— Sin beneficiario —',
            'entitySubLabel' => $record->operation_center->name ?? null,
            'entitySubIcon'  => 'bi-geo-alt',
            'amountLabel'    => 'Total',
            'amount'         => (float)$record->total_amount,
            'pipelineSteps'  => RefundConstants::STATUSES,
            'pipelineLabels' => $statusLabels,
            'currentStatus'  => $record->status,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $record->modified,
            'registryLines'  => $registryLines,
            'actionsHtml'    => $actionsHtml,
        ]);
        ?>
    </aside>

    <!-- ═════════════════════ CONTENIDO ═════════════════════ -->
    <main class="sgi-invoice-view-right">

        <?php
        $sections = [];
        $sections[] = ['key' => 'beneficiary', 'editable' => $record->isAgrupacion()];
        $sections[] = ['key' => 'invoices',    'editable' => $record->isAgrupacion()];
        if ($showAccounting) {
            $sections[] = ['key' => 'accounting', 'editable' => $canEditAccounting];
        }
        if ($showTreasury) {
            $sections[] = ['key' => 'treasury', 'editable' => $canEditTreasury];
        }
        usort($sections, fn($a, $b) => $b['editable'] <=> $a['editable']);
        ?>

        <div class="card" style="padding:20px;">
            <div class="sgi-section-head" style="margin-bottom:16px;">
                <span class="sgi-label">Información del reintegro</span>
            </div>

            <?php foreach ($sections as $section): ?>

                <?php if ($section['key'] === 'beneficiary' && $section['editable']): ?>
                <!-- Beneficiario (editable solo en agrupación) -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sgi-label flex-shrink-0">
                            <i class="bi bi-person-badge me-1" aria-hidden="true"></i>Beneficiario
                        </span>
                        <div class="hr"></div>
                    </div>
                    <div class="mb-3">
                        <label class="input-label">Tipo</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-employee" value="employee"
                                       <?= $record->beneficiary_type === 'employee' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bt-employee">Empleado</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="beneficiary_type" id="bt-provider" value="provider"
                                       <?= $record->beneficiary_type === 'provider' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bt-provider">Proveedor</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 sgi-beneficiary-employee" <?= $record->beneficiary_type === 'employee' ? '' : 'style="display:none;"' ?>>
                        <label class="input-label">Empleado</label>
                        <select name="beneficiary_employee_id" class="form-select select2-enable">
                            <option value="">Seleccione un empleado</option>
                            <?php foreach ($employees as $eid => $ename): ?>
                            <option value="<?= (int)$eid ?>" <?= (int)($record->beneficiary_employee_id ?? 0) === (int)$eid ? 'selected' : '' ?>><?= h($ename) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 sgi-beneficiary-provider" <?= $record->beneficiary_type === 'provider' ? '' : 'style="display:none;"' ?>>
                        <label class="input-label">Proveedor</label>
                        <select name="beneficiary_provider_id" class="form-select select2-enable">
                            <option value="">Seleccione un proveedor</option>
                            <?php foreach ($providers as $pid => $pname): ?>
                            <option value="<?= (int)$pid ?>" <?= (int)($record->beneficiary_provider_id ?? 0) === (int)$pid ? 'selected' : '' ?>><?= h($pname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($section['key'] === 'invoices'): ?>
                <!-- Facturas agrupadas -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sgi-label flex-shrink-0">
                            <i class="bi bi-receipt me-1" aria-hidden="true"></i>Facturas Agrupadas
                        </span>
                        <div class="hr"></div>
                        <span class="sgi-folder-count"><?= $invoiceCount ?></span>
                    </div>

                    <?php if (!empty($record->invoices)): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th># Factura</th>
                                    <th>Proveedor</th>
                                    <th class="text-end">Monto</th>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <th class="text-center" style="width:60px;"></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($record->invoices as $inv): ?>
                                <tr>
                                    <td>
                                        <?= $this->Html->link(
                                            $inv->invoice_number ?? '#' . $inv->id,
                                            ['controller' => 'Invoices', 'action' => 'view', $inv->id],
                                            ['class' => 'mono', 'style' => 'font-weight:600;']
                                        ) ?>
                                    </td>
                                    <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                                    <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <td class="text-center">
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                            ['action' => 'removeInvoice', $record->id, $inv->id],
                                            ['confirm' => '¿Remover esta factura del registro?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.15rem .4rem;font-size:.7rem;line-height:1;', 'escape' => false, 'title' => 'Quitar', 'block' => true]
                                        ) ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" class="text-end fw-bold">Total:</td>
                                    <td class="text-end fw-bold mono" style="color:var(--primary-color);">
                                        $ <?= number_format((float)$record->total_amount, 0, ',', '.') ?>
                                    </td>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <td></td>
                                    <?php endif; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                        No hay facturas agrupadas. Agregue al menos una factura para poder avanzar.
                    </div>
                    <?php endif; ?>

                    <?php if ($record->isAgrupacion()): ?>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-primary"
                                data-bs-toggle="modal" data-bs-target="#linkRefundInvoicesModal">
                            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Vincular facturas
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($section['key'] === 'accounting'): ?>
                <!-- Contabilidad -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sgi-label flex-shrink-0">
                            <i class="bi bi-calculator me-1" aria-hidden="true"></i>Contabilidad
                        </span>
                        <div class="hr"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="input-label d-block">Causada</label>
                            <div class="form-check">
                                <?php if ($canEditAccounting): ?>
                                <input type="hidden" name="accrued" value="0">
                                <input type="checkbox" name="accrued" value="1" class="form-check-input"
                                       id="accrued" <?= !empty($record->accrued) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="accrued">Marcar como causada</label>
                                <?php else: ?>
                                <input type="checkbox" class="form-check-input" disabled <?= !empty($record->accrued) ? 'checked' : '' ?>>
                                <label class="form-check-label">Marcar como causada</label>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">Fecha de Causación<?= $canEditAccounting ? ' <span class="text-danger">*</span>' : '' ?></label>
                            <?php if ($canEditAccounting): ?>
                            <input type="text" name="accrual_date" class="form-control flatpickr-date"
                                   value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('Y-m-d')) : '' ?>">
                            <?php else: ?>
                            <input type="text" class="form-control" disabled
                                   value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('d/m/Y')) : '' ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">Lista para Pago</label>
                            <?php if ($canEditAccounting): ?>
                            <select name="ready_for_payment" class="form-select">
                                <?php foreach ($readyForPaymentOptions as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= ($record->ready_for_payment ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" class="form-control" disabled value="<?= h($record->ready_for_payment ?? '') ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($section['key'] === 'treasury'): ?>
                <?php if (!empty($record->payment_rejection_reason)
                    && $record->status === RefundConstants::STATUS_TESORERIA): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
                    <div>
                        <strong>Pago rechazado.</strong>
                        <div><?= h($record->payment_rejection_reason) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?= $this->element('payment_section', [
                    'payments'           => $syntheticPayments ?? [],
                    'bankingEntities'    => $bankingEntities,
                    'addPaymentUrl'      => ['action' => 'registerPayment', $record->id],
                    'authorizeUrlFn'     => fn($_) => ['action' => 'authorizePayment', $record->id],
                    'rejectUrlFn'        => fn($_) => ['action' => 'rejectPayment', $record->id],
                    'canRegisterPayment' => ($record->status === RefundConstants::STATUS_TESORERIA)
                        && ($canRegisterPayment ?? false),
                    'canAuthorize'       => ($record->status === RefundConstants::STATUS_AUTORIZACION_PAGO)
                        && ($canAuthorizePayment ?? false),
                    'canDelete'          => false,
                    'paymentStatus'      => null,
                    'totalAmount'        => (float)$record->total_amount,
                    'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
                    'sectionTitle'       => 'Pago',
                    'sectionIcon'        => 'bi-bank',
                    'forceFullAmount'    => true,
                    'singlePaymentOnly'  => true,
                ]) ?>
                <?php endif; ?>

            <?php endforeach; ?>
        </div>

        <!-- Confirmación de pago (Verificación) -->
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $record->status === RefundConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $canConfirmPayment,
            'confirmUrl' => ['action' => 'confirmPayment', $record->id],
        ]) ?>

        <!-- Soportes -->
        <?php
        $docs = $record->refund_documents ?? [];
        $canUploadDocs = !$record->isPagada();
        $refundDocRows = [];
        foreach ($docs as $doc) {
            $refundDocRows[] = [
                'doc'       => $doc,
                'canDelete' => $canUploadDocs,
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $doc->id]),
                'showBadge' => false,
            ];
        }
        ?>
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $refundDocRows]],
            'totalDocs'     => count($docs),
            'canUpload'     => $canUploadDocs,
            'uploadModalId' => 'uploadRefundDocModal',
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

        <!-- Sticky footer -->
        <?php if ($canSave || !empty($canRegress)): ?>
        <div class="sgi-edit-footer">
            <div class="sgi-edit-footer-meta">
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                    Rol: <strong style="color:var(--text-default);"><?= h($roleName) ?></strong>
                </span>
                <?php if ($record->modified): ?>
                <span class="sep"></span>
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-clock sgi-fg-faint" aria-hidden="true"></i>
                    Última modificación: <span class="mono"><?= $record->modified->format('d/m/Y H:i') ?></span>
                </span>
                <?php endif; ?>
            </div>
            <div class="sgi-edit-footer-actions">
                <?php if ($record->isAgrupacion() && !empty($userPermissions['refunds']['can_delete'])): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-trash" aria-hidden="true"></i>Eliminar',
                    ['action' => 'delete', $record->id],
                    ['class' => 'btn btn-ghost-card sgi-fg-danger', 'escape' => false, 'confirm' => '¿Eliminar este registro? Las facturas agrupadas quedarán libres.', 'block' => true]
                ) ?>
                <?php endif; ?>
                <?= $this->Html->link('Cancelar', ['action' => 'view', $record->id], ['class' => 'btn btn-ghost-card']) ?>
                <?php if ($canSave): ?>
                <button type="submit" class="<?= $btnClass ?>">
                    <?= $btnLabel ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<?= $this->Form->end() ?>

<?= $this->element('observations/drawer', [
    'observations'    => $record->refund_observations ?? [],
    'count'           => count($record->refund_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php if ($record->isAgrupacion()): ?>
<?= $this->element('link_invoices_modal', [
    'modalId'    => 'linkRefundInvoicesModal',
    'formUrl'    => ['action' => 'linkInvoices', $record->id],
    'candidates' => $availableInvoices,
    'title'      => 'Vincular facturas — Reintegro',
    'helpText'   => 'Filtre por fecha o centro de operación para acotar la lista.',
    'filterUrl'  => ['action' => 'edit', $record->id],
    'filters'    => $groupFilters,
    'operationCenters' => $operationCenters,
]) ?>
<?php endif; ?>

<?php if (!$record->isPagada()): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadRefundDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="upload-doc-form"
                  data-url="<?= $this->Url->build(['action' => 'uploadDocument', $record->id]) ?>"
                  enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Soporte</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="input-label">Tipo de Documento (opcional)</label>
                        <input type="text" name="document_type" class="form-control" placeholder="Ej. Soporte causación, Comprobante...">
                    </div>
                    <div class="mb-3">
                        <label class="input-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadRefundDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<script>
(function(){
    // Beneficiary radio toggle (only present when in agrupacion)
    var bRadios = document.querySelectorAll('input[name="beneficiary_type"]');
    if (bRadios.length > 0) {
        var empBlock = document.querySelector('.sgi-beneficiary-employee');
        var provBlock = document.querySelector('.sgi-beneficiary-provider');
        function syncBeneficiary() {
            var checked = document.querySelector('input[name="beneficiary_type"]:checked');
            var val = checked ? checked.value : null;
            if (empBlock) empBlock.style.display = val === 'employee' ? '' : 'none';
            if (provBlock) provBlock.style.display = val === 'provider' ? '' : 'none';
        }
        bRadios.forEach(function (r) { r.addEventListener('change', syncBeneficiary); });
    }
})();
</script>

<?php if (!empty($canRegress) && empty($regressLockMessage)): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'     => $this->Url->build(['action' => 'regressStatus', $record->id]),
    'entityNoun'    => 'reintegro',
    'entityArticle' => 'Este',
    'currLabel'     => $pipelineLabels[$currentStatus] ?? $currentStatus,
    'prevLabel'     => $pipelineLabels[$previousStatus] ?? $previousStatus,
    'extraNote'     => 'Las facturas vinculadas también se regresarán al estado correspondiente.',
]) ?>
<?php endif; ?>
<?php $this->end() ?>
