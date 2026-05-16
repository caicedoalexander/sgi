<?php
/**
 * El controller pasa los datos via $this->set(get_object_vars($vm)),
 * desempaquetando PettyCashEditViewModel en variables individuales.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\PettyCashRecord $record
 * @var string $currentStatus
 * @var string $roleName
 * @var \App\Model\Entity\User|null $currentUser
 *
 * Permisos del pipeline:
 * @var bool $canDeleteDocuments
 * @var bool $canRegisterPayment
 * @var bool $canAuthorizePayment
 * @var bool $canConfirmPayment
 * @var bool $canRegress
 *
 * Avance / retroceso:
 * @var array $advanceErrors
 * @var string|null $nextStatus
 * @var string|null $previousStatus
 * @var string|null $regressLockMessage
 * @var bool $canAdvance
 *
 * Visualización:
 * @var string $pageTitle
 * @var array $pipelineLabels
 * @var array $statusLabels
 * @var array $statusBadgeMap
 * @var array{0:string,1:string} $currentStatusBadge
 * @var array $readyForPaymentOptions
 * @var array $paymentStatusOptions
 * @var int  $statusIndex
 * @var bool $showAccounting
 * @var bool $showTreasury
 * @var bool $canEditAccounting
 * @var bool $canEditTreasury
 * @var bool $canSave
 * @var string $submitButtonHtml
 * @var string $submitButtonClass
 * @var int $invoiceCount
 *
 * Listados y dropdowns:
 * @var array $syntheticPayments
 * @var iterable $availableInvoices
 * @var iterable $operationCenters
 * @var array $bankingEntities
 * @var array $groupFilters
 */

use App\Constants\PettyCashConstants;

$this->assign('title', $pageTitle);

// Aliases retrocompatibles con el markup heredado.
$statusBadge = $statusBadgeMap;
$btnLabel    = $submitButtonHtml;
$btnClass    = $submitButtonClass;
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Caja Menor</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1" aria-hidden="true"></i>Ver',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
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

<!-- Layout dos columnas -->
<div class="sgi-invoice-layout">

<!-- Columna izquierda: formulario -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">
    <!-- Header -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="sgi-icon-chip">
                <i class="bi bi-wallet2" aria-hidden="true"></i>
            </div>
            <div>
                <div class="sgi-card-title mono"><?= h($record->code) ?></div>
                <div class="sgi-card-subtitle mt-1">
                    Total: <strong style="color:var(--primary-color);">$ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $statusBadge[$record->status] ?? 'bg-dark' ?>">
            <?= $statusLabels[$record->status] ?? $record->status ?>
        </span>
    </div>

    <!-- Progress -->
    <div class="sgi-pipeline-wrapper">
        <?= $this->element('petty_cash_progress', ['status' => $record->status]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <div class="sgi-ledger-wrapper">
        <div class="sgi-ledger">
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Código</div>
                <div class="sgi-ledger-value" style="font-family:monospace;"><?= h($record->code ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Operación</div>
                <div class="sgi-ledger-value"><?= h($record->operation_center->name ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Facturas</div>
                <div class="sgi-ledger-value"><?= $invoiceCount ?> factura<?= $invoiceCount !== 1 ? 's' : '' ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Total</div>
                <div class="sgi-ledger-value --amount">$ <?= number_format((float)$record->total_amount, 0, ',', '.') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Creado</div>
                <div class="sgi-ledger-value"><?= $record->created ? h($record->created->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php if ($record->notes && !$record->isAgrupacion() && !$record->isContabilidad()): ?>
            <div class="sgi-ledger-item" style="grid-column:span 4;">
                <div class="sgi-ledger-label">Notas</div>
                <div class="sgi-ledger-value" style="white-space:normal;font-weight:400;font-size:.8rem;color:#555;"><?= nl2br(h($record->notes)) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">
        <?= $this->Form->create($record, ['id' => 'pettyCashEditForm']) ?>
        <?= $this->Form->hidden('expected_status', ['value' => $record->status]) ?>

        <div class="sgi-form-sections">

        <?php
        // ── Section reordering: editable sections first ──
        $sections = [];

        // Notes section (editable in agrupacion & contabilidad)
        if ($record->isAgrupacion() || $record->isContabilidad()) {
            $sections[] = ['key' => 'notes', 'editable' => true];
        }

        // Invoices section (always visible, editable only in agrupacion)
        $sections[] = ['key' => 'invoices', 'editable' => $record->isAgrupacion()];

        // Accounting section (visible in contabilidad+)
        if ($showAccounting) {
            $sections[] = ['key' => 'accounting', 'editable' => $canEditAccounting];
        }

        // Treasury section (visible in tesoreria+)
        if ($showTreasury) {
            $sections[] = ['key' => 'treasury', 'editable' => $canEditTreasury];
        }

        // Sort: editable first
        usort($sections, fn($a, $b) => $b['editable'] <=> $a['editable']);

        foreach ($sections as $section):
        ?>

        <?php if ($section['key'] === 'notes'): ?>
        <!-- Notas -->
        <div class="mb-4">
            <label class="form-label">Notas</label>
            <textarea name="notes" class="form-control auto-resize" rows="2"><?= h($record->notes ?? '') ?></textarea>
        </div>
        <?php endif; ?>

        <?php if ($section['key'] === 'invoices'): ?>
        <!-- Facturas agrupadas -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="sgi-label flex-shrink-0">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Facturas Agrupadas
                </span>
                <div class="sgi-flex-divider"></div>
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
                                    ['style' => 'font-family:monospace;font-weight:600;']
                                ) ?>
                            </td>
                            <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                            <td class="text-end">$ <?= $this->Number->format($inv->amount, ['places' => 2]) ?></td>
                            <?php if ($record->isAgrupacion()): ?>
                            <td class="text-center">
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                    ['action' => 'removeInvoice', $record->id, $inv->id],
                                    ['confirm' => '¿Remover esta factura del registro?', 'class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:.15rem .4rem;font-size:.7rem;line-height:1;', 'escape' => false, 'title' => 'Quitar']
                                ) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-end fw-bold">Total:</td>
                            <td class="text-end fw-bold" style="color:var(--primary-color);">
                                $ <?= $this->Number->format($record->total_amount, ['places' => 2]) ?>
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

            <!-- Agregar más facturas (solo agrupación) — modal compartido -->
            <?php if ($record->isAgrupacion()): ?>
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-primary"
                        data-bs-toggle="modal" data-bs-target="#linkPettyCashInvoicesModal">
                    <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Vincular facturas
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($section['key'] === 'accounting'): ?>
        <!-- ── Sección: Contabilidad ── -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="sgi-label flex-shrink-0">
                    <i class="bi bi-calculator me-1" aria-hidden="true"></i>Contabilidad
                </span>
                <div class="sgi-flex-divider"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label d-block">Causada</label>
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
                    <label class="form-label">Fecha de Causación<?= $canEditAccounting ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($canEditAccounting): ?>
                    <input type="text" name="accrual_date" class="form-control flatpickr-date"
                           value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('Y-m-d')) : '' ?>">
                    <?php else: ?>
                    <input type="text" class="form-control" disabled
                           value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('d/m/Y')) : '' ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lista para Pago</label>
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
            && $record->status === PettyCashConstants::STATUS_TESORERIA): ?>
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
            'canRegisterPayment' => ($record->status === PettyCashConstants::STATUS_TESORERIA)
                && ($canRegisterPayment ?? false),
            'canAuthorize'       => ($record->status === PettyCashConstants::STATUS_AUTORIZACION_PAGO)
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

        </div><!-- /sgi-form-sections -->

        <!-- Botones de acción (sticky) -->
        <?php if ($canSave): ?>
        <div class="sgi-sticky-actions">
            <button type="submit" class="<?= $btnClass ?>">
                <?= $btnLabel ?>
            </button>

            <?php if (!empty($canRegress)):
                $prevLabel = $pipelineLabels[$previousStatus] ?? $previousStatus;
                $isLocked = !empty($regressLockMessage);
            ?>
                <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline-secondary"
                            disabled title="<?= h($regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($record->isAgrupacion() && !empty($userPermissions['petty_cash']['can_delete'])): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
                ['action' => 'delete', $record->id],
                ['class' => 'btn btn-outline-danger', 'escape' => false, 'confirm' => '¿Eliminar este registro? Las facturas agrupadas quedarán libres.']
            ) ?>
            <?php endif; ?>

            <?= $this->Html->link('Cancelar', ['action' => 'view', $record->id], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?php else: ?>
        <div class="sgi-sticky-actions">
            <?= $this->Html->link('Volver', ['action' => 'view', $record->id], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>

        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $record->status === PettyCashConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $canConfirmPayment ?? false,
            'confirmUrl' => ['action' => 'confirmPayment', $record->id],
        ]) ?>

        <?php if ($record->isAgrupacion()): ?>
        <?= $this->element('link_invoices_modal', [
            'modalId'    => 'linkPettyCashInvoicesModal',
            'formUrl'    => ['action' => 'linkInvoices', $record->id],
            'candidates' => $availableInvoices,
            'title'      => 'Vincular facturas — Caja Menor',
            'helpText'   => 'Filtre por fecha, centro de operación o proveedor. Por defecto, últimos 90 días.',
            'filterUrl'  => ['action' => 'edit', $record->id],
            'filters'    => $groupFilters,
            'operationCenters' => $operationCenters,
            'providers'  => $providers ?? [],
        ]) ?>
        <?php endif; ?>
    </div>
</div>
</div><!-- /columna izquierda -->

<!-- Columna derecha: soportes + observaciones -->
<div class="sgi-invoice-sidebar">

<?php $docs = $record->petty_cash_documents ?? []; ?>
<div class="card card-primary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-paperclip" style="font-size:.85rem;" aria-hidden="true"></i>
            <span style="font-size:.85rem;font-weight:600;">Soportes</span>
            <span class="sgi-folder-count"><?= count($docs) ?> doc<?= count($docs) !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!$record->isPagada()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadPcDocModal">
            <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
        </button>
        <?php endif; ?>
    </div>

    <div id="docs-empty-state" style="padding:2rem 1rem;text-align:center;color:#c8c8c8;<?= !empty($docs) ? 'display:none;' : '' ?>">
        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:1.5rem;" aria-hidden="true"></i>
        <span style="font-size:.8rem;">Sin soportes adjuntos</span>
    </div>
    <div id="docs-list" style="max-height:420px;overflow-y:auto;">
        <?php foreach ($docs as $doc): ?>
            <?= $this->element('document_row', [
                'doc'       => $doc,
                'canDelete' => !$record->isPagada(),
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $doc->id]),
                'showBadge' => false,
            ]) ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Observaciones: chat -->
<?php $obsCount = count($record->petty_cash_observations ?? []); ?>
<div class="card card-primary sgi-obs-card" style="display:flex;flex-direction:column;">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-chat-left-text" style="font-size:.85rem;color:var(--primary-color);" aria-hidden="true"></i>
        <span style="font-size:.85rem;font-weight:600;">Observaciones</span>
        <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
    </div>

    <div id="obs-chat-scroll" class="sgi-obs-list">
        <?php foreach ($record->petty_cash_observations ?? [] as $obs): ?>
            <?= $this->element('observation_bubble', [
                'observation' => $obs,
                'isMine' => $currentUser && $obs->user_id === $currentUser->id,
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
        <i class="bi bi-chat-square-dots" style="font-size:1.75rem;" aria-hidden="true"></i>
        <span style="font-size:.78rem;">Sin observaciones aún</span>
    </div>

    <div class="sgi-obs-input-bar">
        <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $record->id], 'id' => 'obs-form']) ?>
        <div class="sgi-obs-compose">
            <textarea name="message" class="auto-resize" rows="1"
                      placeholder="Escriba una observación..."></textarea>
            <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                <i class="bi bi-send" aria-hidden="true"></i>
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>

</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if (!$record->isPagada()): ?>
<!-- Modal: Subir Soporte -->
<div class="modal fade" id="uploadPcDocModal" tabindex="-1">
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
                        <label class="form-label">Tipo de Documento (opcional)</label>
                        <input type="text" name="document_type" class="form-control" placeholder="Ej. Soporte causación, Comprobante...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Archivo</label>
                        <input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word o Excel.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => false]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observation_chat_init') ?>

<?php $this->append('script') ?>
<script>
(function(){
    SgiDocumentUploader.init({
        formSelector:        '#upload-doc-form',
        listSelector:        '#docs-list',
        emptySelector:       '#docs-empty-state',
        counterSelector:     '.card.card-primary .card-header .sgi-folder-count',
        rowTemplateSelector: '#doc-row-template',
        modalSelector:       '#uploadPcDocModal',
        csrfToken:           <?= json_encode($this->request->getAttribute('csrfToken') ?? '') ?>
    });
})();
</script>

<?php if (!empty($canRegress) && empty($regressLockMessage)): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'     => $this->Url->build(['action' => 'regressStatus', $record->id]),
    'entityNoun'    => 'registro',
    'entityArticle' => 'Este',
    'currLabel'     => $pipelineLabels[$currentStatus] ?? $currentStatus,
    'prevLabel'     => $pipelineLabels[$previousStatus] ?? $previousStatus,
    'extraNote'     => 'Las facturas vinculadas también se regresarán al estado correspondiente.',
]) ?>
<?php endif; ?>
<?php $this->end() ?>
