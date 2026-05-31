<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\PaymentSchedulingEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */
use App\Constants\PaymentSchedulingConstants;

$this->assign('title', h($viewModel->pageTitle));

$record = $viewModel->record;
[$psStatusLabel, $psStatusPill] = $viewModel->currentStatusBadge;

$isTerminal = $record->pipeline_status === PaymentSchedulingConstants::STATUS_PAGADA;
?>
<?= $this->element('cdn_autonumeric') ?>

<div class="sgi-edit-shell">

<?php /* ═══════════════════ HEADER DE PÁGINA (barra fija) ═══════════════════ */ ?>
<div class="sgi-edit-shell-head">
<!-- Page header -->
<div class="sgi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="sgi-breadcrumb">
            <?= $this->Html->link('Programación de Pagos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($record->code), ['action' => 'view', $record->id]) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-page-title">Editar Programación</span>
            <span class="sgi-edit-id-chip"><?= h($record->code) ?></span>
            <span class="pill <?= $psStatusPill ?>"><?= h($psStatusLabel) ?></span>
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

</div><?php /* fin .sgi-edit-shell-head */ ?>

<div class="sgi-edit-shell-body view-anim">

<!-- Alerta de avance pendiente -->
<?php if ($viewModel->canAdvance && !empty($viewModel->advanceErrors)): ?>
<div class="alert alert-warning mb-4">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
            <strong>Para avanzar al siguiente estado complete:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($viewModel->advanceErrors as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row gx-3">

    <!-- ═════════════════════ SIDEBAR ═════════════════════ -->
    <aside class="col-lg-3 sgi-edit-col">
        <?php
        // Acciones del sidebar (regresión) — element compartido
        $actionsHtml = !empty($viewModel->canRegress)
            ? $this->element('pipeline_regress_action', [
                'previousStatus'     => $viewModel->previousStatus,
                'pipelineLabels'     => $viewModel->pipelineLabels,
                'regressLockMessage' => $viewModel->regressLockMessage,
            ])
            : null;

        $registryLines = [
            ['icon' => 'bi-person', 'html' => 'Rol: <strong style="color:var(--text-default);">' . h($viewModel->roleName) . '</strong>'],
        ];
        if ($record->hasValue('created_by_user')) {
            $registryLines[] = ['icon' => 'bi-person-badge', 'html' => 'Creado por ' . h($record->created_by_user->full_name)];
        }
        if ($record->created) {
            $registryLines[] = ['icon' => 'bi-calendar3', 'html' => 'Creado · <span class="mono">' . $record->created->format('d/m/Y') . '</span>'];
        }

        echo $this->element('pipeline_sidebar', [
            'icon'           => 'calendar2-check',
            'idLabel'        => $record->code,
            'typeLabel'      => 'Programación',
            'statusPill'     => $psStatusPill,
            'statusLabel'    => $psStatusLabel,
            'entityLabel'    => 'Título',
            'entityValue'    => $record->title ?: '—',
            'entitySubLabel' => $viewModel->itemCount . ' factura' . ($viewModel->itemCount !== 1 ? 's' : ''),
            'entitySubIcon'  => 'bi-receipt',
            'amountLabel'    => 'Monto Total',
            'amount'         => (float)$viewModel->total,
            'pipelineSteps'  => PaymentSchedulingConstants::PIPELINE_STATUSES,
            'pipelineLabels' => $viewModel->pipelineLabels,
            'currentStatus'  => $record->pipeline_status,
            'isTerminal'     => $isTerminal,
            'modifiedAt'     => $record->modified,
            'registryLines'  => $registryLines,
            'actionsHtml'    => $actionsHtml,
        ]);
        ?>
    </aside>

    <!-- ═════════════════════ CONTENIDO ═════════════════════ -->
    <main class="col-lg-9 sgi-edit-col">

        <!-- Facturas Vinculadas -->
        <div class="card" style="padding:18px 20px;">
            <div class="sgi-section-head" style="margin-bottom:12px;">
                <span class="sgi-label d-inline-flex align-items-center gap-2">
                    <i class="bi bi-receipt" aria-hidden="true"></i>
                    Facturas Vinculadas
                    <span class="sgi-folder-count"><?= $viewModel->itemCount ?></span>
                </span>
                <?php if ($viewModel->isBorrador): ?>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-ghost-card btn-sm" data-bs-toggle="collapse" data-bs-target="#add-item-form">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>Manual
                    </button>
                    <button type="button" class="btn btn-ghost-card btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
                        <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Excel
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($viewModel->isBorrador): ?>
            <!-- Form agregar item manual -->
            <div class="collapse mb-3" id="add-item-form">
                <div class="card" style="padding:12px;background:var(--bg-subtle);">
                    <?= $this->Form->create(null, ['url' => ['action' => 'addItem', $record->id]]) ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Factura (ID)</label>
                            <input type="number" name="invoice_id" class="form-control form-control-sm" required placeholder="ID de factura">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Banco</label>
                            <select name="banking_entity_id" class="form-select form-select-sm" required>
                                <option value="">-- Seleccione --</option>
                                <?php foreach ($viewModel->bankingEntities as $beId => $beName): ?>
                                <option value="<?= $beId ?>"><?= h($beName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" style="font-size:var(--fs-body-sm);">Monto (COP)</label>
                            <input type="text" name="amount" class="form-control form-control-sm currency-input" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar</button>
                        </div>
                    </div>
                    <?= $this->Form->end() ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($record->payment_scheduling_items)): ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>N. Factura</th>
                            <th>Proveedor</th>
                            <th>Banco</th>
                            <th class="text-end">Monto</th>
                            <?php if ($viewModel->isBorrador): ?><th class="text-end" style="width:60px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($record->payment_scheduling_items as $item): ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(
                                    h($item->invoice->invoice_number ?? 'ID:' . $item->invoice_id),
                                    ['controller' => 'Invoices', 'action' => 'view', $item->invoice_id],
                                    ['class' => 'mono', 'style' => 'font-weight:600;']
                                ) ?>
                            </td>
                            <td><?= h($item->invoice->provider->name ?? '—') ?></td>
                            <td><?= h($item->banking_entity->name ?? '—') ?></td>
                            <td class="text-end mono">$ <?= number_format((float)$item->amount, 0, ',', '.') ?></td>
                            <?php if ($viewModel->isBorrador): ?>
                            <td class="text-end">
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                    ['action' => 'removeItem', $record->id, $item->id],
                                    ['confirm' => '¿Desvincular esta factura?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.15rem .4rem;font-size:.7rem;line-height:1;', 'escape' => false]
                                ) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">Total</td>
                            <td class="text-end fw-bold mono" style="color:var(--primary-color);">
                                $ <?= number_format((float)$viewModel->total, 0, ',', '.') ?>
                            </td>
                            <?php if ($viewModel->isBorrador): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center sgi-fg-faint py-3" style="font-size:var(--fs-body);">
                <i class="bi bi-receipt me-1" aria-hidden="true"></i>No hay facturas vinculadas
            </div>
            <?php endif; ?>
        </div>

        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $record->pipeline_status === PaymentSchedulingConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $viewModel->canConfirmPayment,
            'confirmUrl' => ['action' => 'confirmPayment', $record->id],
            'message' => 'Los pagos fueron autorizados por el Contador. Confirme cuando el dinero haya salido del banco.',
        ]) ?>

        <!-- Soportes -->
        <?php
        $documents = $record->payment_scheduling_documents ?? [];
        $canUploadDocs = !$viewModel->isPagada;
        $schedulingDocRows = [];
        foreach ($documents as $att) {
            $schedulingDocRows[] = [
                'doc'       => $att,
                'canDelete' => $canUploadDocs,
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $att->id]),
                'showBadge' => false,
            ];
        }
        ?>
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $schedulingDocRows]],
            'totalDocs'     => count($documents),
            'canUpload'     => $canUploadDocs,
            'uploadModalId' => 'uploadDocumentModal',
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

    </main>
</div><?php /* fin .row */ ?>
</div><?php /* fin .sgi-edit-shell-body */ ?>

<!-- Sticky footer con acciones del pipeline -->
<?php if ($viewModel->canAdvance || $viewModel->canReject || !empty($viewModel->canRegress)): ?>
        <div class="sgi-edit-footer">
            <div class="sgi-edit-footer-meta">
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                    Rol: <strong style="color:var(--text-default);"><?= h($viewModel->roleName) ?></strong>
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
                <?php if ($viewModel->canReject): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Devolver a Tesorería',
                    ['action' => 'reject', $record->id],
                    ['confirm' => '¿Devolver la programación a Tesorería?', 'class' => 'btn btn-ghost-card sgi-fg-warning', 'escape' => false]
                ) ?>
                <?php endif; ?>
                <?php if ($viewModel->canAdvance && empty($viewModel->advanceErrors) && $viewModel->nextStatus): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Avanzar a ' . h($viewModel->pipelineLabels[$viewModel->nextStatus] ?? ''),
                    ['action' => 'advance', $record->id],
                    [
                        'confirm' => '¿Avanzar la programación?',
                        'class' => 'btn btn-primary',
                        'escape' => false,
                        'data' => ['expected_status' => $record->pipeline_status],
                    ]
                ) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

</div><?php /* fin .sgi-edit-shell */ ?>

<?php /* ═══════════════════ MODALES ═══════════════════ */ ?>
<?php if ($viewModel->isBorrador): ?>
<!-- Modal: Importar Excel -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'importExcel', $record->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Importar Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem;">
                    Formato: Preprogramación de pagos (columnas: Banco, Proveedor, Razón Social, Saldo, Programado).
                </p>
                <div class="mb-3">
                    <?= $this->Form->control('excel_file', [
                        'type' => 'file',
                        'class' => 'form-control',
                        'label' => ['text' => 'Archivo Excel', 'class' => 'form-label'],
                        'required' => true,
                        'accept' => '.xls,.xlsx',
                    ]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost-card" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Importar</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$viewModel->isPagada): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
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
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
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
<?= $this->element('observations/drawer', [
    'observations'    => $record->payment_scheduling_observations ?? [],
    'count'           => count($record->payment_scheduling_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php if (!empty($viewModel->canRegress) && empty($viewModel->regressLockMessage)): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'  => $this->Url->build(['action' => 'regressStatus', $record->id]),
    'entityNoun' => 'programación',
    'currLabel'  => $viewModel->pipelineLabels[$viewModel->currentStatus] ?? $viewModel->currentStatus,
    'prevLabel'  => $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus,
]) ?>
<?php endif; ?>

<?php $this->append('script') ?>
<script>
(function () {
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadDocumentModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>
<?php $this->end() ?>
