<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$this->assign('title', $viewModel->pageTitle);

// Alias locales: convenientes para mantener el markup corto.
$isAdvance              = $viewModel->isAdvance;
$documentTypes          = $viewModel->documentTypes;
$approvalOptions        = $viewModel->approvalOptions;
$dianOptions            = $viewModel->dianOptions;
$readyForPaymentOptions = $viewModel->readyForPaymentOptions;
$paymentStatusOptions   = $viewModel->paymentStatusOptions;
$renderOrder            = $viewModel->renderOrder;
$readOnlySectionKeys    = $viewModel->readOnlySectionKeys;
$canEdit                = fn(string $field): bool => $viewModel->canEditField($field);
$isReadOnlySection      = fn(string $s): bool      => $viewModel->isReadOnlySection($s);
$isCollapsibleSection   = fn(string $s): bool      => $viewModel->isCollapsibleSection($s);

$ps       = $viewModel->currentStatusBadge;
$btnLabel = $viewModel->submitButtonHtml;
$btnClass = $viewModel->submitButtonClass;

// ── Ledger lookup arrays (ResultSet → array for bracket access) ──
$expenseTypesArr = is_array($viewModel->expenseTypes)
    ? $viewModel->expenseTypes
    : (method_exists($viewModel->expenseTypes, 'toArray') ? $viewModel->expenseTypes->toArray() : []);
?>

<?php if (($viewModel->invoice->document_type ?? null) === \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($viewModel->invoice->advance_id)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
            Esta factura es una <strong>Legalización</strong> vinculada al
            <?= $this->Html->link('Anticipo #' . h($viewModel->invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $viewModel->invoice->advance_id]) ?>.
        </div>
    </div>
<?php endif; ?>

<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?= $this->element('invoice_edit/page_header', ['isAdvance' => $isAdvance]) ?>

<?= $this->element('invoice_edit/advance_alert') ?>

<?php
// Soportes — calcular antes del layout de dos columnas
$showUploadSection = !$viewModel->invoice->isInFinalState();
$documentsByStatus  = [];
if (!empty($viewModel->invoice->invoice_documents)) {
    foreach ($viewModel->invoice->invoice_documents as $doc) {
        $documentsByStatus[$doc->pipeline_status][] = $doc;
    }
}
$statusLabels = \App\Constants\InvoiceConstants::STATUS_LABELS;
$badgeColors = InvoicePresentation::STATUS_BADGES;
$totalDocs = array_sum(array_map('count', $documentsByStatus));
?>

<!-- Layout: formulario izquierda + soportes derecha -->
<div class="sgi-invoice-layout">

<!-- ── Columna izquierda: formulario ── -->
<div class="sgi-invoice-form">
<div class="card card-primary mb-4">

    <!-- Cabecera: identificador + rol + estado -->
    <div class="card-header d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:36px;height:36px;background:var(--primary-color);color:#fff;font-size:.9rem;">
                <i class="bi <?= $isAdvance ? 'bi-cash-coin' : 'bi-receipt' ?>" aria-hidden="true"></i>
            </div>
            <div>
                <div style="font-size:.95rem;font-weight:700;color:#111;font-family:monospace;letter-spacing:-.01em;">
                    <?= h($viewModel->invoice->invoice_number ?? '#' . $viewModel->invoice->id) ?>
                </div>
                <div style="font-size:.72rem;color:#aaa;margin-top:.1rem;">
                    Rol: <strong style="color:#777;"><?= h($viewModel->roleName) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge <?= $ps[1] ?>"><?= $ps[0] ?></span>
    </div>

    <!-- Pipeline progress -->
    <div style="background:var(--bg-muted);border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:1.25rem 1.5rem;">
        <?= $this->element('pipeline_progress', [
            'currentStatus'    => $viewModel->currentStatus,
            'pipelineStatuses' => $viewModel->pipelineStatuses,
            'pipelineLabels'   => $viewModel->pipelineLabels,
            'isRejected'       => $viewModel->isRejected,
            'isApproved'       => $viewModel->isApproved ?? false,
            'paymentStatus'    => $viewModel->invoice->payment_status,
        ]) ?>
    </div>

    <!-- ── Ficha resumen (ledger) ── -->
    <?php
    $costCentersArr = is_array($viewModel->costCenters) ? $viewModel->costCenters : (method_exists($viewModel->costCenters, 'toArray') ? $viewModel->costCenters->toArray() : []);
    ?>
    <div style="padding:1rem 1.5rem .75rem;">
        <div class="sgi-ledger">
            <?php if ($isAdvance): ?>
            <!-- Fila 1: Beneficiario + Documento + Valor (Anticipo) -->
            <?php
            $beneficiaryName = $viewModel->invoice->provider->name ?? ($viewModel->invoice->employee->full_name ?? '—');
            $beneficiaryDoc = $viewModel->invoice->provider->document_number
                ?? ($viewModel->invoice->employee->document_number ?? null);
            $beneficiaryDocType = $viewModel->invoice->provider_id
                ? ($viewModel->invoice->provider->document_type ?? '')
                : ($viewModel->invoice->employee_id ? ($viewModel->invoice->employee->document_type ?? '') : '');
            $beneficiaryKind = $viewModel->invoice->provider_id ? 'Proveedor' : ($viewModel->invoice->employee_id ? 'Empleado' : '—');
            ?>
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Beneficiario (<?= h($beneficiaryKind) ?>)</div>
                <div class="sgi-ledger-value" title="<?= h($beneficiaryName) ?>">
                    <?= h($beneficiaryName) ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Documento</div>
                <div class="sgi-ledger-value"><?= h(trim($beneficiaryDocType . ' ' . ($beneficiaryDoc ?? '—'))) ?></div>
            </div>
            <?php else: ?>
            <!-- Fila 1: Proveedor + NIT + Valor -->
            <div class="sgi-ledger-item" style="grid-column:span 2;">
                <div class="sgi-ledger-label">Proveedor</div>
                <div class="sgi-ledger-value" title="<?= h($viewModel->invoice->provider->name ?? '') ?>">
                    <?= h($viewModel->invoice->provider->name ?? '—') ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Documento</div>
                <div class="sgi-ledger-value"><?= h(($viewModel->invoice->provider->document_type ?? '') . ' ' . ($viewModel->invoice->provider->document_number ?? '—')) ?></div>
            </div>
            <?php endif; ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Valor</div>
                <div class="sgi-ledger-value --amount">
                    <?php if ($viewModel->invoice->amount): ?>
                        $ <?= number_format((float)$viewModel->invoice->amount, 0, ',', '.') ?>
                    <?php else: ?>
                        <span class="--muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Fila 2: Tipo Doc + Centro Op. + Tipo Gasto + Centro Costos -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Tipo Documento</div>
                <div class="sgi-ledger-value"><?= h($viewModel->invoice->document_type ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Operación</div>
                <div class="sgi-ledger-value" title="<?= h($viewModel->invoice->operation_center->name ?? '') ?>">
                    <?php if ($viewModel->invoice->operation_center): ?>
                        <?= h($viewModel->invoice->operation_center->code . ' - ' . $viewModel->invoice->operation_center->name) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Tipo de Gasto</div>
                <div class="sgi-ledger-value"><?= h($expenseTypesArr[$viewModel->invoice->expense_type_id] ?? '—') ?></div>
            </div>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Centro de Costos</div>
                <div class="sgi-ledger-value"><?= h($costCentersArr[$viewModel->invoice->cost_center_id] ?? '—') ?></div>
            </div>
            <!-- Fila 3: Fechas + Orden de Compra + Detalle -->
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Emisión</div>
                <div class="sgi-ledger-value"><?= $viewModel->invoice->issue_date ? h($viewModel->invoice->issue_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php if (!$isAdvance): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Vencimiento</div>
                <div class="sgi-ledger-value"><?= $viewModel->invoice->due_date ? h($viewModel->invoice->due_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php endif; ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Registro</div>
                <div class="sgi-ledger-value"><?= $viewModel->invoice->registration_date ? h($viewModel->invoice->registration_date->format('d/m/Y')) : '—' ?></div>
            </div>
            <?php if (!$isAdvance): ?>
            <div class="sgi-ledger-item">
                <div class="sgi-ledger-label">Orden de Compra</div>
                <div class="sgi-ledger-value"><?= h($viewModel->invoice->purchase_order ?: '—') ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-4" style="padding-top:0 !important;">
        <?php if ($viewModel->canSendLinks): ?>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'sendApprovalLinks', $viewModel->invoice->id],
            'id' => 'sendApprovalLinksForm',
            'style' => 'display:none',
        ]) ?>
        <?= $this->Form->end() ?>
        <?php endif; ?>
        <?= $this->Form->create($viewModel->invoice) ?>
        <?= $this->Form->hidden('expected_status', ['value' => $viewModel->invoice->pipeline_status]) ?>

        <div class="sgi-form-sections">

        <?php
        $collapsibleLabels = [
            'general' => ['icon' => 'bi-file-text', 'label' => 'Documento'],
            'dates' => ['icon' => 'bi-calendar3', 'label' => 'Fechas'],
            'classification' => ['icon' => 'bi-tags', 'label' => 'Clasificación y Valor'],
        ];
        foreach ($renderOrder as $sectionName):
            $sectionIsReadOnly = $isReadOnlySection($sectionName);

            // Skip read-only sections — the ledger summary already shows reference data
            if ($sectionIsReadOnly) { continue; }

            $sectionCollapsible = $isCollapsibleSection($sectionName);
        ?>

        <?php if ($sectionCollapsible): ?>
        <details class="sgi-collapsible-section mb-4">
            <summary class="d-flex align-items-center gap-3 mb-0" style="cursor:pointer;list-style:none;">
                <span class="text-uppercase fw-semibold flex-shrink-0"
                      style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                    <i class="bi <?= $collapsibleLabels[$sectionName]['icon'] ?? 'bi-pencil' ?> me-1" aria-hidden="true"></i><?= $collapsibleLabels[$sectionName]['label'] ?? ucfirst($sectionName) ?>
                </span>
                <div style="flex:1;height:1px;background:var(--border-color);"></div>
                <i class="bi bi-chevron-right sgi-collapse-chevron" style="font-size:.7rem;color:#bbb;transition:transform .2s;" aria-hidden="true"></i>
            </summary>
            <div style="padding-top:.75rem;">
        <?php endif; ?>

        <?php if ($sectionName === 'general' && in_array('general', $viewModel->visibleSections) && $isAdvance): ?>
        <?= $this->element('invoice_edit/sections/general_advance', compact('viewModel', 'canEdit')) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'general' && in_array('general', $viewModel->visibleSections) && !$isAdvance): ?>
        <?= $this->element('invoice_edit/sections/general', compact('viewModel', 'canEdit', 'isAdvance', 'documentTypes')) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'dates' && in_array('dates', $viewModel->visibleSections)): ?>
        <?= $this->element('invoice_edit/sections/dates', compact('viewModel', 'canEdit', 'isAdvance')) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'classification' && in_array('classification', $viewModel->visibleSections)): ?>
        <?= $this->element('invoice_edit/sections/classification', compact('viewModel', 'canEdit', 'isAdvance')) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'revision' && in_array('revision', $viewModel->visibleSections)): ?>
        <?= $this->element('invoice_edit/sections/revision', compact('viewModel', 'canEdit', 'approvalOptions', 'dianOptions')) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'accounting' && in_array('accounting', $viewModel->visibleSections)): ?>
        <?= $this->element('invoice_edit/sections/accounting', compact('viewModel', 'canEdit', 'readyForPaymentOptions')) ?>
        <?php endif; ?>

        <?php
            // Shared payment-section params (reused by treasury and payment_authorization)
            $sharedPaymentParams = [
                'payments'           => $viewModel->invoice->invoice_payments ?? [],
                'bankingEntities'    => $viewModel->bankingEntities,
                'addPaymentUrl'      => ['controller' => 'InvoicePayments', 'action' => 'addPayment', $viewModel->invoice->id],
                'authorizeUrlFn'     => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'authorizePayment', $viewModel->invoice->id, $pId],
                'rejectUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'rejectPayment', $viewModel->invoice->id, $pId],
                'deleteUrlFn'        => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'deletePayment', $viewModel->invoice->id, $pId],
                'paymentStatus'      => $viewModel->invoice->payment_status ?? null,
                'totalAmount'        => $viewModel->invoice->amount ?? null,
                'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
                'with_idempotency_key' => true,
            ];
        ?>

        <?php if ($sectionName === 'treasury' && in_array('treasury', $viewModel->visibleSections)
                  && !in_array($viewModel->currentStatus, [
                      \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_VERIFICACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_PAGADA,
                  ], true)): ?>
        <?php
            $canRegisterPayment = $viewModel->canRegisterPayment;
            $paymentMode = $canRegisterPayment ? 'tesoreria_register' : 'view';
        ?>
        <?= $this->element('payment_section', $sharedPaymentParams + [
            'canRegisterPayment' => $canRegisterPayment,
            'canAuthorize'       => false,
            'canDelete'          => $canRegisterPayment,
            'mode'               => $paymentMode,
        ]) ?>
        <?php endif; ?>

        <?php if ($sectionName === 'payment_authorization' && in_array('payment_authorization', $viewModel->visibleSections)
                  && in_array($viewModel->currentStatus, [
                      \App\Constants\InvoiceConstants::STATUS_AUTORIZACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_VERIFICACION_PAGO,
                      \App\Constants\InvoiceConstants::STATUS_PAGADA,
                  ], true)): ?>
        <?php
            $canAuthorizePayment = $viewModel->canAuthorizePayment;
            $paymentMode = $canAuthorizePayment ? 'authorize' : 'view';
        ?>
        <?= $this->element('payment_section', $sharedPaymentParams + [
            'canRegisterPayment' => false,
            'canAuthorize'       => $canAuthorizePayment,
            'canDelete'          => false,
            'mode'               => $paymentMode,
        ]) ?>
        <?php endif; ?>

        <?php if ($sectionCollapsible): ?>
            </div>
        </details>
        <?php endif; ?>

        <?php endforeach; ?>

        </div><!-- /sgi-form-sections -->

        <!-- Botones de acción (sticky) -->
        <?php if (!empty($viewModel->editableFields) || !empty($viewModel->canRegress)): ?>
        <div class="sgi-sticky-actions d-flex flex-wrap gap-2 align-items-center">
            <?php if (!empty($viewModel->editableFields)): ?>
                <button type="submit" class="<?= $btnClass ?>">
                    <?= $btnLabel ?>
                </button>
            <?php endif; ?>

            <?php if (!empty($viewModel->canRegress)):
                $prevLabel = $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus;
                $isLocked = !empty($viewModel->regressLockMessage);
            ?>
                <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline-secondary"
                            disabled title="<?= h($viewModel->regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <?= $this->Html->link(
                'Cancelar',
                ['action' => 'view', $viewModel->invoice->id],
                ['class' => 'btn btn-outline-secondary ms-auto']
            ) ?>
        </div>
        <?php elseif (empty(array_intersect(['treasury', 'payment_authorization'], $viewModel->visibleSections))): ?>
        <div class="alert alert-info mb-0">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            No tiene permisos de edición para esta factura en el estado actual.
        </div>
        <?php endif; ?>

        <?= $this->Form->end() ?>

        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $viewModel->currentStatus === \App\Constants\InvoiceConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm' => $viewModel->canConfirmPayment,
            'confirmUrl' => ['controller' => 'InvoicePayments', 'action' => 'confirmPayment', $viewModel->invoice->id],
        ]) ?>

        <?php if (!empty($viewModel->canRegress) && empty($viewModel->regressLockMessage)): ?>
        <?= $this->element('regress_status_modal', [
            'actionUrl'  => $this->Url->build(['action' => 'regressStatus', $viewModel->invoice->id]),
            'entityNoun' => 'factura',
            'currLabel'  => $viewModel->pipelineLabels[$viewModel->currentStatus] ?? $viewModel->currentStatus,
            'prevLabel'  => $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus,
        ]) ?>
        <?php endif; ?>

        <?php if ($viewModel->currentStatus === \App\Constants\InvoiceConstants::STATUS_APROBACION && !empty($viewModel->editableFields)): ?>
        <?= $this->element('invoice_edit/modify_approvers_modal', ['invoice' => $viewModel->invoice, 'approvers' => $viewModel->approvers]) ?>
        <?php endif; ?>
    </div>
</div>

<?= $this->element('email_log_panel', ['emailLogs' => $viewModel->emailLogs ?? []]) ?>
</div><!-- /columna izquierda -->

<!-- ── Columna derecha: soportes + observaciones ── -->
<div class="sgi-invoice-sidebar">
<?= $this->element('invoice_edit/sidebar', [
    'documentsByStatus' => $documentsByStatus,
    'totalDocs'         => $totalDocs,
    'showUploadSection' => $showUploadSection,
    'statusLabels'      => $statusLabels,
    'badgeColors'       => $badgeColors,
]) ?>
</div><!-- /columna derecha -->

</div><!-- /layout dos columnas -->

<?php if ($showUploadSection): ?>
<?= $this->element('invoice_edit/upload_doc_modal', ['invoice' => $viewModel->invoice]) ?>
<?php endif; ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>
<?= $this->element('observation_chat_init') ?>

<?= $this->element('invoice_edit/scripts', ['isAdvance' => $isAdvance]) ?>
