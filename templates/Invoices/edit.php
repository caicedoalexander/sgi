<?php
/**
 * Invoices/edit — formulario de edición de factura.
 *
 * Reescrito (mayo 2026) para el Sistema de Diseño v2: sin bordes ni
 * box-shadow, identidad por fondo + accent-strip + jerarquía tipográfica.
 * Replica el rediseño `editar-factura.jsx` de Claude Design usando
 * exclusivamente clases canónicas de components.css / styles.css.
 *
 * La lógica de negocio se preserva intacta respecto a la versión legacy:
 * permisos por rol/estado (`canEdit`), secciones condicionales
 * (`visibleSections`), hidden inputs, CSRF, IDs requeridos por el JS
 * (`scripts.php` espera #provider-wrapper, #employee-wrapper,
 * #equivalent-doc-row, #equivalent-holder-type, #manual-doc-wrapper,
 * #beneficiary-type, #invoiceEditForm, [data-dirty-indicator]).
 *
 * @var \App\View\AppView $this
 * @var \App\ViewModel\InvoiceEditViewModel $viewModel
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$this->assign('title', $viewModel->pageTitle);

// ── Alias locales ────────────────────────────────────────────────
$invoice                = $viewModel->invoice;
$isAdvance              = $viewModel->isAdvance;
$documentTypes          = $viewModel->documentTypes;
$approvalOptions        = $viewModel->approvalOptions;
$dianOptions            = $viewModel->dianOptions;
$readyForPaymentOptions = $viewModel->readyForPaymentOptions;
$canEdit                = fn(string $field): bool => $viewModel->canEditField($field);

$currentStatus = $viewModel->currentStatus;
$currentLabel  = $viewModel->pipelineLabels[$currentStatus] ?? $currentStatus;
$nextLabel     = $viewModel->nextStatus !== null
    ? ($viewModel->pipelineLabels[$viewModel->nextStatus] ?? $viewModel->nextStatus)
    : null;

// ── Mapa estado → accent-strip de la card de etapa actual ────────
$stageAccentMap = [
    InvoiceConstants::STATUS_APROBACION        => 'accent-warning',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'accent-orange',
    InvoiceConstants::STATUS_TESORERIA         => 'accent-info',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'accent-warning',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'accent-info',
    InvoiceConstants::STATUS_PAGADA            => 'accent-green',
    InvoiceConstants::STATUS_LEGALIZADA        => 'accent-green',
];
$stageAccent = $viewModel->isRejected ? 'accent-danger' : ($stageAccentMap[$currentStatus] ?? 'accent-green');

// ── Pill kind del estado del pipeline (soft variants) ────────────
$statusPills = [
    InvoiceConstants::STATUS_APROBACION        => 'pill-warning-soft',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'pill-secondary-soft',
    InvoiceConstants::STATUS_TESORERIA         => 'pill-info-soft',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'pill-warning-soft',
    InvoiceConstants::STATUS_VERIFICACION_PAGO => 'pill-info-soft',
    InvoiceConstants::STATUS_PAGADA            => 'pill-primary-soft',
    InvoiceConstants::STATUS_LEGALIZADA        => 'pill-primary-soft',
];
$statusPill = $statusPills[$currentStatus] ?? 'pill-muted';

// ── URLs (Factura vs Anticipo) ───────────────────────────────────
$indexUrl = $isAdvance
    ? ['controller' => 'Advances', 'action' => 'index']
    : ['action' => 'index'];
$viewUrl = $isAdvance
    ? ['controller' => 'Advances', 'action' => 'view', $invoice->id]
    : ['action' => 'view', $invoice->id];
$listLabel  = $isAdvance ? 'Todos los Anticipos' : 'Todas las Facturas';
$titleLabel = $isAdvance ? 'Editar Anticipo' : 'Editar Factura';
$idLabel    = $invoice->invoice_number ?? ('#' . $invoice->id);

// ── Resumen monetario ────────────────────────────────────────────
$invoiceAmount = (float)($invoice->amount ?? 0);
$totalPagado   = (float)$viewModel->paymentsTotal;
$saldo         = $invoiceAmount - $totalPagado;

// ── Soportes ─────────────────────────────────────────────────────
$showUploadSection = !$invoice->isInFinalState();
$documentsByStatus = [];
if (!empty($invoice->invoice_documents)) {
    foreach ($invoice->invoice_documents as $doc) {
        $documentsByStatus[$doc->pipeline_status][] = $doc;
    }
}
$statusLabels = InvoiceConstants::STATUS_LABELS;
$badgeColors  = InvoicePresentation::STATUS_BADGES;
$totalDocs    = array_sum(array_map('count', $documentsByStatus));
$multipleDocStatuses = count($documentsByStatus) > 1;

// ── Pipeline vertical ────────────────────────────────────────────
$pipelineSteps = $viewModel->pipelineStatuses;
$currentIdx    = array_search($currentStatus, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps);
}
$isTerminal = in_array($currentStatus, [
    InvoiceConstants::STATUS_PAGADA,
    InvoiceConstants::STATUS_LEGALIZADA,
], true);

// ── Etapa N/total (banner) ───────────────────────────────────────
$stageNum   = is_int($currentIdx) ? $currentIdx + 1 : null;
$stageTotal = count($pipelineSteps);

// ── Beneficiario (Anticipo vs Factura) ───────────────────────────
$beneficiaryName = $invoice->provider->name
    ?? ($invoice->employee->full_name ?? '—');

// ── Secciones a renderizar ───────────────────────────────────────
$stageSectionKeys     = ['revision', 'accounting', 'treasury', 'payment_authorization'];
$visibleStageSections = array_values(array_intersect($stageSectionKeys, $viewModel->visibleSections));

$isPaymentStage = in_array($currentStatus, [
    InvoiceConstants::STATUS_AUTORIZACION_PAGO,
    InvoiceConstants::STATUS_VERIFICACION_PAGO,
    InvoiceConstants::STATUS_PAGADA,
], true);

$sharedPaymentParams = [
    'payments'             => $invoice->invoice_payments ?? [],
    'bankingEntities'      => $viewModel->bankingEntities,
    'addPaymentUrl'        => ['controller' => 'InvoicePayments', 'action' => 'addPayment', $invoice->id],
    'authorizeUrlFn'       => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'authorizePayment', $invoice->id, $pId],
    'rejectUrlFn'          => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'rejectPayment', $invoice->id, $pId],
    'deleteUrlFn'          => fn($pId) => ['controller' => 'InvoicePayments', 'action' => 'deletePayment', $invoice->id, $pId],
    'paymentStatus'        => $invoice->payment_status ?? null,
    'totalAmount'          => $invoice->amount ?? null,
    'rejectMessage'        => '¿Rechazar este pago? El registro volverá a Tesorería.',
    'with_idempotency_key' => true,
];

// ── Datos de Recibo de Caja (titular del documento) ──────────────
$isReciboDeCaja    = ($invoice->document_type ?? '') === InvoiceConstants::DOCTYPE_RECIBO_CAJA;
$currentHolderType = $invoice->equivalent_holder_type ?? '';
$currentBeneficiary = $invoice->provider_id
    ? 'provider'
    : ($invoice->employee_id ? 'employee' : '');

// ── Banner: requisitos para avanzar ──────────────────────────────
$showAdvanceBanner = $viewModel->canAdvance
    && !$viewModel->isRejected
    && !empty($viewModel->advanceErrors);

$canRegress = !empty($viewModel->canRegress);
?>

<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<div class="sgi-edit-shell">

<?php /* ═══════════════════ HEADER DE PÁGINA (barra fija) ═══════════════════ */ ?>
<div class="sgi-edit-shell-head">
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 view-anim">
    <div style="min-width:0;">
        <div class="d-flex align-items-center flex-wrap gap-1"
             style="font-size:var(--fs-body-sm);color:var(--text-faint);margin-bottom:6px;">
            <?= $this->Html->link(h($listLabel), $indexUrl, ['class' => 'sgi-fg-faint', 'style' => 'text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($idLabel), $viewUrl, ['class' => 'sgi-fg-faint', 'style' => 'text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span style="color:var(--text-default);">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-title-page"><?= h($titleLabel) ?></span>
            <span class="mono" style="font-size:var(--fs-body-lg);color:var(--text-muted);padding:3px 8px;background:var(--bg-subtle);border-radius:var(--radius-sm);">
                <?= h($idLabel) ?>
            </span>
            <?php if ($viewModel->isRejected): ?>
                <span class="pill pill-danger-soft">Rechazada</span>
            <?php elseif ($viewModel->isApproved): ?>
                <span class="pill pill-primary-soft">
                    <i class="bi bi-check2" aria-hidden="true" style="font-size:9px;"></i>Aprobada
                </span>
            <?php else: ?>
                <span class="pill <?= $statusPill ?>"><?= h($currentLabel) ?></span>
            <?php endif; ?>
            <?php if ($currentStatus === InvoiceConstants::STATUS_TESORERIA
                      && $invoice->payment_status === InvoiceConstants::PAYMENT_PARTIAL): ?>
                <span class="pill pill-warning-soft">Pago Parcial</span>
            <?php endif; ?>
            <span class="d-inline-flex align-items-center gap-1 sgi-fg-secondary"
                  data-dirty-indicator hidden
                  style="font-size:var(--fs-label);font-weight:600;">
                <span style="width:6px;height:6px;background:var(--secondary-color);border-radius:50%;"></span>
                Cambios sin guardar
            </span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            $indexUrl,
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye" aria-hidden="true"></i>Ver detalle',
            $viewUrl,
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
    </div>
</div>

</div><?php /* fin .sgi-edit-shell-head */ ?>

<?php /* ── Formulario oculto para "Enviar links de aprobación" ── */ ?>
<?php if ($viewModel->canSendLinks): ?>
<?= $this->Form->create(null, [
    'url' => ['action' => 'sendApprovalLinks', $invoice->id],
    'id' => 'sendApprovalLinksForm',
    'style' => 'display:none',
]) ?>
<?= $this->Form->end() ?>
<?php endif; ?>

<?= $this->Form->create($invoice, ['id' => 'invoiceEditForm', 'class' => 'sgi-edit-shell-form']) ?>
<?= $this->Form->hidden('expected_status', ['value' => $invoice->pipeline_status]) ?>

<div class="sgi-edit-shell-body view-anim">

<?php /* ── Banner: factura rechazada en aprobación de área ────── */ ?>
<?php if ($viewModel->isRejected): ?>
<div class="banner danger" style="margin-bottom:14px">
    <div class="banner-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
    <div class="banner-body">
        <div class="banner-title">Esta factura fue rechazada en la aprobación de área</div>
        <div class="banner-msg">Revisa las observaciones de los aprobadores. El flujo puede reiniciarse para reenviar los enlaces de aprobación.</div>
    </div>
</div>
<?php endif; ?>

<?php /* ── Alerta: legalización vinculada a un anticipo ───────── */ ?>
<?php if (($invoice->document_type ?? null) === InvoiceConstants::DOCTYPE_LEGALIZACION && !empty($invoice->advance_id)): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <div>
        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>
        Esta factura es una <strong>Legalización</strong> vinculada al
        <?= $this->Html->link('Anticipo #' . h($invoice->advance_id), ['controller' => 'Advances', 'action' => 'view', $invoice->advance_id]) ?>.
    </div>
</div>
<?php endif; ?>

<div class="row gx-3">

    <?php /* ═══════════════════ COLUMNA IZQUIERDA ═══════════════════ */ ?>
    <aside class="col-lg-3 sgi-edit-col d-flex flex-column gap-3">

    <?php
    // Bloque Pagado/Saldo bajo el monto del hero (cuando aplica).
    $heroExtraHtml = '';
    if ($totalPagado > 0 || $invoiceAmount > 0) {
        ob_start(); ?>
        <div class="d-flex" style="gap:18px;margin-top:12px;">
            <div>
                <div class="sgi-label" style="font-size:var(--fs-micro);">Pagado</div>
                <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;color:var(--primary-color);margin-top:2px;">
                    $ <?= number_format($totalPagado, 0, ',', '.') ?>
                </div>
            </div>
            <div>
                <div class="sgi-label" style="font-size:var(--fs-micro);">Saldo</div>
                <div class="mono" style="font-size:var(--fs-body-lg);font-weight:700;margin-top:2px;color:<?= $saldo > 0 ? 'var(--secondary-color)' : 'var(--primary-color)' ?>;">
                    $ <?= number_format(max(0, $saldo), 0, ',', '.') ?>
                </div>
            </div>
        </div>
        <?php
        $heroExtraHtml = ob_get_clean();
    }

    // Acciones de etapa (regresión) — solo si $canRegress.
    $stageActionsHtml = null;
    if ($canRegress) {
        $prevLabel     = $viewModel->pipelineLabels[$viewModel->previousStatus]
            ?? $viewModel->previousStatus;
        $regressLocked = !empty($viewModel->regressLockMessage);
        ob_start(); ?>
        <?php if ($regressLocked): ?>
            <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                    disabled title="<?= h($viewModel->regressLockMessage) ?>">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
            </button>
        <?php else: ?>
            <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                    data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
            </button>
        <?php endif;
        $stageActionsHtml = ob_get_clean();
    }

    // Pill de estado: preserva las 3 variantes del hero actual
    // (rechazada → la maneja el element vía isRejected; aprobada; estado del pipeline).
    // El hero de Invoices/edit no muestra pill "Pago Parcial" (esa info va en el
    // bloque Pagado/Saldo de $heroExtraHtml).
    $heroStatusPill  = $statusPill;
    $heroStatusLabel = $currentLabel;
    if (!$viewModel->isRejected && $viewModel->isApproved) {
        $heroStatusPill  = 'pill-primary-soft';
        $heroStatusLabel = 'Aprobada';
    }
    ?>

    <?= $this->element('pipeline_sidebar', [
        'icon'          => $isAdvance ? 'cash-coin' : 'file-earmark-text',
        'idLabel'       => $idLabel,
        'typeLabel'     => $invoice->document_type,
        'statusPill'    => $heroStatusPill,
        'statusLabel'   => $heroStatusLabel,
        'isRejected'    => $viewModel->isRejected,
        'entityLabel'   => $isAdvance ? 'Beneficiario' : 'Proveedor',
        'entityValue'   => $beneficiaryName,
        'entitySubLabel' => $invoice->hasValue('operation_center')
            ? $invoice->operation_center->name : null,
        'amountLabel'   => $isAdvance ? 'Valor Anticipo' : 'Valor Factura',
        'amount'        => $invoiceAmount,
        'heroExtraHtml' => $heroExtraHtml,
        'pipelineSteps'  => $pipelineSteps,
        'pipelineLabels' => $viewModel->pipelineLabels,
        'currentStatus'  => $currentStatus,
        'isTerminal'     => $isTerminal,
        'modifiedAt'     => $invoice->modified,
        'actionsHtml'    => $stageActionsHtml,
    ]) ?>

    </aside>

    <?php /* ═══════════════════ COLUMNA DERECHA ═══════════════════ */ ?>
    <main class="col-lg-9 sgi-edit-col d-flex flex-column gap-3">

        <?php /* ── Banner: requisitos para avanzar ─────────────── */ ?>
        <?php if ($showAdvanceBanner): ?>
        <div class="alert alert-warning d-flex align-items-start gap-3 mb-0">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"
               style="font-size:16px;flex-shrink:0;margin-top:1px;color:var(--warning-color);"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;color:var(--text-strong);margin-bottom:6px;">
                    <?php if ($nextLabel): ?>
                        Para avanzar a <span style="color:var(--warning-text);"><?= h($nextLabel) ?></span> debe completar:
                    <?php else: ?>
                        Para avanzar al siguiente estado debe completar:
                    <?php endif; ?>
                </div>
                <ul style="margin:0;padding-left:18px;line-height:1.7;">
                    <?php foreach ($viewModel->advanceErrors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($stageNum !== null): ?>
                <span class="pill pill-warning-soft pill-lg flex-shrink-0">Etapa <?= $stageNum ?>/<?= $stageTotal ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php /* ── Datos Generales (siempre visible) ───────────── */ ?>
        <div class="sgi-card">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-bottom:14px;">
                <span class="sgi-label">Datos Generales</span>
                <span style="font-size:11px;color:var(--text-faint);font-style:italic;">
                    Estos campos no cambian con la etapa
                </span>
            </div>
            <div class="hr" style="margin-bottom:16px;"></div>

            <div class="row g-2 g-md-3">

                <?php /* ── Fila 1 ─────────────────────────────── */ ?>
                <?php if ($isAdvance): ?>
                    <div class="col-md-3">
                        <label class="input-label" for="beneficiary-type">Tipo de Beneficiario</label>
                        <select id="beneficiary-type" class="form-select"
                                <?= ($canEdit('provider_id') || $canEdit('employee_id')) ? '' : 'disabled' ?>>
                            <option value="">-- Seleccione --</option>
                            <option value="provider" <?= $currentBeneficiary === 'provider' ? 'selected' : '' ?>>Proveedor</option>
                            <option value="employee" <?= $currentBeneficiary === 'employee' ? 'selected' : '' ?>>Empleado</option>
                        </select>
                    </div>
                    <div class="col-md-6 <?= $currentBeneficiary === 'provider' ? '' : 'd-none' ?>" id="provider-wrapper">
                        <label class="input-label">Proveedor</label>
                        <?= $this->Form->control('provider_id', [
                            'label' => false,
                            'options' => $viewModel->providers,
                            'empty' => '-- Seleccione --',
                            'class' => 'form-select select2-enable',
                            'disabled' => !$canEdit('provider_id'),
                        ]) ?>
                    </div>
                    <div class="col-md-6 <?= $currentBeneficiary === 'employee' ? '' : 'd-none' ?>" id="employee-wrapper">
                        <label class="input-label">Empleado</label>
                        <?= $this->Form->control('employee_id', [
                            'label' => false,
                            'options' => $viewModel->employees ?? [],
                            'empty' => '-- Seleccione --',
                            'class' => 'form-select select2-enable',
                            'disabled' => !$canEdit('employee_id'),
                        ]) ?>
                    </div>
                    <?= $this->Form->hidden('document_type', ['value' => InvoiceConstants::DOCTYPE_ANTICIPO]) ?>
                <?php else: ?>
                    <div class="col-md-6" id="provider-wrapper">
                        <label class="input-label">Proveedor</label>
                        <?= $this->Form->control('provider_id', [
                            'label' => false,
                            'options' => $viewModel->providers,
                            'empty' => '-- Seleccione --',
                            'class' => 'form-select select2-enable',
                            'disabled' => !$canEdit('provider_id'),
                        ]) ?>
                    </div>
                    <div class="col-md-3">
                        <label class="input-label">No. Factura</label>
                        <?= $this->Form->control('invoice_number', [
                            'label' => false,
                            'placeholder' => 'Ej: FV-001234',
                            'class' => 'form-control mono',
                            'disabled' => !$canEdit('invoice_number'),
                        ]) ?>
                    </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="input-label">Valor (COP)</label>
                    <?php if ($canEdit('amount')): ?>
                        <input type="text" name="amount" class="form-control currency-input"
                               value="<?= h($invoice->amount ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control mono" disabled
                               value="$ <?= number_format($invoiceAmount, 0, ',', '.') ?>">
                    <?php endif; ?>
                </div>

                <?php /* ── Fila 2 ─────────────────────────────── */ ?>
                <?php if (!$isAdvance): ?>
                <div class="col-md-3">
                    <label class="input-label">Tipo Documento</label>
                    <?= $this->Form->control('document_type', [
                        'label' => false,
                        'options' => $documentTypes,
                        'class' => 'form-select',
                        'disabled' => !$canEdit('document_type'),
                    ]) ?>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="input-label">Centro de Operación</label>
                    <?= $this->Form->control('operation_center_id', [
                        'label' => false,
                        'options' => $viewModel->operationCenters,
                        'empty' => '-- Seleccione --',
                        'class' => 'form-select',
                        'disabled' => !($canEdit('operation_center_id') && !$isAdvance),
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <label class="input-label">Tipo de Gasto</label>
                    <?= $this->Form->control('expense_type_id', [
                        'label' => false,
                        'options' => $viewModel->expenseTypes,
                        'empty' => '-- Seleccione --',
                        'class' => 'form-select',
                        'disabled' => !$canEdit('expense_type_id'),
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <label class="input-label">Centro de Costos</label>
                    <?= $this->Form->control('cost_center_id', [
                        'label' => false,
                        'options' => $viewModel->costCenters,
                        'empty' => '-- Seleccione --',
                        'class' => 'form-select',
                        'disabled' => !$canEdit('cost_center_id'),
                    ]) ?>
                </div>

                <?php /* ── Fila 3 ─────────────────────────────── */ ?>
                <div class="col-md-3">
                    <label class="input-label">Fecha de Emisión</label>
                    <?php if ($canEdit('issue_date')): ?>
                        <input type="text" name="issue_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->issue_date?->format('Y-m-d') ?? '') ?>">
                    <?php else: ?>
                        <input type="text" class="form-control mono" disabled
                               value="<?= h($invoice->issue_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="issue_date"
                               value="<?= h($invoice->issue_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>

                <?php if (!$isAdvance): ?>
                <div class="col-md-3" id="due-date-wrapper">
                    <label class="input-label">Fecha de Vencimiento</label>
                    <?php if ($canEdit('due_date')): ?>
                        <input type="text" name="due_date" class="form-control flatpickr-date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>"
                               <?= $isReciboDeCaja ? 'disabled' : '' ?>>
                    <?php else: ?>
                        <input type="text" class="form-control mono" disabled
                               value="<?= h($invoice->due_date?->format('d/m/Y') ?? '') ?>">
                        <input type="hidden" name="due_date"
                               value="<?= h($invoice->due_date?->format('Y-m-d') ?? '') ?>">
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <?= $this->Form->hidden('due_date', ['value' => $invoice->due_date?->format('Y-m-d') ?? '']) ?>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="input-label">Fecha de Registro</label>
                    <input type="text" class="form-control mono" disabled
                           value="<?= h($invoice->registration_date?->format('d/m/Y') ?? '') ?>">
                    <input type="hidden" name="registration_date"
                           value="<?= h($invoice->registration_date?->format('Y-m-d') ?? '') ?>">
                </div>

                <?php if (!$isAdvance): ?>
                <div class="col-md-3" id="purchase-order-wrapper">
                    <label class="input-label">Orden de Compra</label>
                    <?= $this->Form->control('purchase_order', [
                        'label' => false,
                        'placeholder' => '—',
                        'class' => 'form-control mono',
                        'disabled' => !$canEdit('purchase_order'),
                    ]) ?>
                </div>
                <?php endif; ?>

                <?php /* ── Detalle (full width) ───────────────── */ ?>
                <div class="col-12">
                    <label class="input-label">Detalle</label>
                    <?= $this->Form->control('detail', [
                        'label' => false,
                        'type' => 'textarea',
                        'rows' => 2,
                        'class' => 'form-control auto-resize',
                        'disabled' => !$canEdit('detail'),
                    ]) ?>
                </div>

                <?php /* ── Sub-form Recibo de Caja ────────────── */ ?>
                <?php if (!$isAdvance): ?>
                <div class="col-12 <?= $isReciboDeCaja ? '' : 'd-none' ?>" id="equivalent-doc-row">
                    <div class="row g-2 g-md-3">
                        <div class="col-md-4" id="holder-type-wrapper">
                            <label class="input-label">Titular del Documento</label>
                            <?= $this->Form->control('equivalent_holder_type', [
                                'label' => false,
                                'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'],
                                'empty' => '-- Seleccione --',
                                'id' => 'equivalent-holder-type',
                                'class' => 'form-select',
                                'disabled' => !$canEdit('document_type'),
                            ]) ?>
                        </div>
                        <div class="col-md-4 <?= $currentHolderType !== 'employee' ? 'd-none' : '' ?>" id="employee-wrapper">
                            <label class="input-label">Empleado</label>
                            <?= $this->Form->control('employee_id', [
                                'label' => false,
                                'options' => $viewModel->employees ?? [],
                                'empty' => '-- Seleccione --',
                                'class' => 'form-select select2-enable',
                                'disabled' => !$canEdit('document_type'),
                            ]) ?>
                        </div>
                        <div class="col-md-4 <?= $currentHolderType !== 'manual' ? 'd-none' : '' ?>" id="manual-doc-wrapper">
                            <label class="input-label">Cédula</label>
                            <?= $this->Form->control('manual_document_number', [
                                'label' => false,
                                'placeholder' => 'Número de cédula',
                                'class' => 'form-control mono',
                                'disabled' => !$canEdit('document_type'),
                            ]) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php /* ── Etapa actual: campos editables por estado ───── */ ?>
        <?php if (!empty($visibleStageSections)): ?>
        <div class="sgi-card" style="position:relative;">
            <span class="accent-strip <?= $stageAccent ?>"></span>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-bottom:14px;">
                <div>
                    <div class="sgi-label" style="color:var(--text-faint);">Etapa actual · editable</div>
                    <div class="sgi-title-card d-inline-flex align-items-center gap-2" style="margin-top:4px;">
                        <?= h($currentLabel) ?>
                        <?php if ($nextLabel && !$viewModel->isRejected): ?>
                            <span style="font-size:11px;color:var(--text-faint);font-weight:500;">
                                <i class="bi bi-arrow-right" aria-hidden="true" style="font-size:10px;"></i>
                                próximo: <?= h($nextLabel) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-inline-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);">
                    <span style="width:7px;height:7px;background:var(--danger-color);border-radius:50%;"></span>
                    Campos con * son obligatorios para avanzar
                </div>
            </div>
            <div class="hr" style="margin-bottom:16px;"></div>

            <?php foreach ($visibleStageSections as $sectionName): ?>

                <?php /* ── Revisión / Aprobación ──────────────── */ ?>
                <?php /* Cada sección de etapa se renderiza solo en su estado
                          del pipeline. `revision` (aprobadores + DIAN) pertenece
                          a `aprobacion`; no debe aparecer en estados posteriores
                          aunque el rol Admin tenga `revision` en sus secciones
                          visibles (unión de todos los pasos operables). */ ?>
                <?php if ($sectionName === 'revision' && $currentStatus === InvoiceConstants::STATUS_APROBACION):
                    $isRejected = ($invoice->area_approval ?? '') === InvoiceConstants::APPROVAL_REJECTED;
                    $rejector = null;
                    if ($isRejected) {
                        foreach ($viewModel->currentApprovals as $a) {
                            if ($a->status === InvoiceConstants::APPROVER_STATUS_REJECTED) {
                                $rejector = $a;
                                break;
                            }
                        }
                    }
                    $statusSlugMap = [
                        InvoiceConstants::APPROVER_STATUS_APPROVED => 'approved',
                        InvoiceConstants::APPROVER_STATUS_REJECTED => 'rejected',
                    ];
                    $existingIds = array_map(static fn($a) => $a->user_id, $viewModel->currentApprovals);
                ?>
                <div class="row g-2 g-md-3">
                    <div class="col-12">
                        <label class="input-label">Aprobadores</label>

                        <?php if ($isRejected && $rejector): ?>
                        <div class="alert alert-warning mb-2">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"
                                   style="font-size:1.1rem;margin-top:1px;color:var(--secondary-color);"></i>
                                <div>
                                    <strong>Rechazada por <?= h($rejector->user->full_name ?? $rejector->user->username ?? 'Aprobador') ?></strong>
                                    <?php if ($rejector->observations): ?>
                                        <div class="mt-1 sgi-fg-muted" style="font-size:.85rem;"><?= h($rejector->observations) ?></div>
                                    <?php endif; ?>
                                    <div class="mt-1 sgi-fg-faint" style="font-size:.8rem;">
                                        Corrija los datos y re-asigne aprobadores para reiniciar el flujo.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="sgi-approvers-widget d-flex flex-wrap gap-2" id="approvers-widget">
                            <?php if (!empty($viewModel->currentApprovals)): ?>
                                <?php foreach ($viewModel->currentApprovals as $a):
                                    echo $this->element('invoice_edit/_approver_chip', [
                                        'name' => $a->user->full_name ?? $a->user->username ?? ('Usuario #' . $a->user_id),
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
                                    <select name="approver_ids[]" id="approver-ids" class="select2-rich-approvers" multiple
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
                                    data-sgi-confirm="¿Enviar enlaces de aprobación a los aprobadores seleccionados?">
                                <i class="bi bi-send" aria-hidden="true"></i>Enviar links de aprobación
                            </button>
                            <span class="input-help">Se envía independiente del botón Guardar</span>
                        </div>
                        <div class="input-help mt-1">Seleccione una o más personas que deben aprobar este documento.</div>
                        <?php elseif ($viewModel->canModifyApprovers): ?>
                        <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                            <?php if ($viewModel->hasPendingApprovals): ?>
                                <span class="pill pill-warning-soft">
                                    <span class="spinner-border" role="status" style="width:.65rem;height:.65rem;border-width:1.5px;"></span>
                                    Aprobaciones en curso
                                </span>
                            <?php else: ?>
                                <span class="pill pill-primary-soft">
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobaciones registradas
                                </span>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-dark"
                                    data-bs-toggle="modal" data-bs-target="#modifyApproversModal">
                                <i class="bi bi-pencil" aria-hidden="true"></i>Modificar aprobadores
                            </button>
                            <span class="input-help">Reemplaza el conjunto y reinicia la aprobación</span>
                        </div>
                        <?php endif; ?>

                        <?php if ($isRejected
                            && !empty($viewModel->editableFields)
                            && $currentStatus === InvoiceConstants::STATUS_APROBACION): ?>
                        <form method="post" class="mt-2"
                              action="<?= $this->Url->build(['action' => 'resetFlow', $invoice->id]) ?>">
                            <?= $this->Form->hidden('_csrfToken', ['value' => $this->request->getAttribute('csrfToken')]) ?>
                            <button type="submit" class="btn btn-sm btn-outline-dark"
                                    data-sgi-confirm="¿Reiniciar flujo? Se limpiarán aprobaciones y se permitirá reenviar enlaces.">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reiniciar flujo
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="input-label">Aprobación Área</label>
                        <?= $this->Form->control('area_approval', [
                            'label' => false,
                            'options' => $approvalOptions,
                            'class' => 'form-select',
                            'disabled' => true,
                        ]) ?>
                        <span class="input-help">Se actualiza vía enlace externo</span>
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">Validación DIAN</label>
                        <?= $this->Form->control('dian_validation', [
                            'label' => false,
                            'options' => $dianOptions,
                            'class' => 'form-select',
                            'disabled' => !$canEdit('dian_validation'),
                        ]) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── Contabilidad ───────────────────────── */ ?>
                <?php /* `accounting` pertenece al estado `contabilidad` — misma
                          regla que `revision`: no se muestra fuera de su etapa. */ ?>
                <?php if ($sectionName === 'accounting' && $currentStatus === InvoiceConstants::STATUS_CONTABILIDAD): ?>
                <div class="row g-2 g-md-3">
                    <div class="col-md-4">
                        <label class="input-label">Causada</label>
                        <div class="form-check">
                            <?= $this->Form->checkbox('accrued', array_merge(
                                ['class' => 'form-check-input'],
                                $canEdit('accrued') ? [] : ['disabled' => true]
                            )) ?>
                            <?= $this->Form->label('accrued', 'Marcar como causada', ['class' => 'form-check-label']) ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">Fecha de Causación</label>
                        <?php if ($canEdit('accrual_date')): ?>
                            <input type="text" name="accrual_date" class="form-control flatpickr-date"
                                   value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                        <?php else: ?>
                            <input type="text" class="form-control mono" disabled
                                   value="<?= h($invoice->accrual_date?->format('d/m/Y') ?? '') ?>">
                            <input type="hidden" name="accrual_date"
                                   value="<?= h($invoice->accrual_date?->format('Y-m-d') ?? '') ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">Lista para Pago</label>
                        <?= $this->Form->control('ready_for_payment', [
                            'label' => false,
                            'options' => $readyForPaymentOptions,
                            'class' => 'form-select',
                            'disabled' => !$canEdit('ready_for_payment'),
                        ]) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* ── Tesorería: registro de pagos ───────── */ ?>
                <?php /* El área de pagos solo aplica desde Tesorería en adelante:
                          en `tesoreria` se registran los pagos; en
                          `autorizacion_pago`/`verificacion_pago` se autorizan
                          (sección `payment_authorization`). En `aprobacion` y
                          `contabilidad` no debe mostrarse, aunque el rol Admin
                          tenga `treasury` en sus secciones visibles. */ ?>
                <?php if ($sectionName === 'treasury' && $currentStatus === InvoiceConstants::STATUS_TESORERIA):
                    $canRegisterPayment = $viewModel->canRegisterPayment;
                ?>
                <?= $this->element('payment_section', $sharedPaymentParams + [
                    'canRegisterPayment' => $canRegisterPayment,
                    'canAuthorize'       => false,
                    'canDelete'          => $canRegisterPayment,
                    'mode'               => $canRegisterPayment ? 'tesoreria_register' : 'view',
                ]) ?>
                <?php endif; ?>

                <?php /* ── Autorización / verificación de pago ── */ ?>
                <?php if ($sectionName === 'payment_authorization' && $isPaymentStage):
                    $canAuthorizePayment = $viewModel->canAuthorizePayment;
                ?>
                <?= $this->element('payment_section', $sharedPaymentParams + [
                    'canRegisterPayment' => false,
                    'canAuthorize'       => $canAuthorizePayment,
                    'canDelete'          => false,
                    'mode'               => $canAuthorizePayment ? 'authorize' : 'view',
                ]) ?>
                <?php endif; ?>

            <?php endforeach; ?>
        </div>
        <?php elseif (empty($viewModel->editableFields)): ?>
        <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
            <i class="bi bi-info-circle" aria-hidden="true" style="font-size:16px;flex-shrink:0;margin-top:1px;"></i>
            <div>No tiene permisos de edición para esta factura en el estado actual.</div>
        </div>
        <?php endif; ?>

        <?php /* ── Cierre de flujo (Verificación de pago) ──────── */ ?>
        <?php if ($viewModel->canConfirmPayment
                  && $currentStatus === InvoiceConstants::STATUS_VERIFICACION_PAGO): ?>
        <div class="sgi-card" style="position:relative;">
            <span class="sgi-label">Cierre de flujo</span>
            <p style="font-size:var(--fs-body);color:var(--text-muted);margin:8px 0 12px;">
                El pago fue autorizado por el Contador. Verifique que todos los soportes estén
                cargados y cierre el flujo.
            </p>
            <?= $this->Form->postLink(
                '<i class="bi bi-check2-circle" aria-hidden="true"></i>Cerrar flujo',
                ['controller' => 'InvoicePayments', 'action' => 'confirmPayment', $invoice->id],
                [
                    'class' => 'btn btn-primary',
                    'escape' => false,
                    'confirm' => '¿Confirmar que los soportes están completos y desea cerrar el flujo?',
                    'block' => true,
                ]
            ) ?>
        </div>
        <?php endif; ?>

        <?php /* ── Soportes (ancho completo; Observaciones vive en el drawer) ── */ ?>
<?php
$docGroups = [];
foreach ($documentsByStatus as $status => $docs) {
    $rows = [];
    foreach ($docs as $doc) {
        $rows[] = [
            'doc'          => $doc,
            'canDelete'    => $viewModel->canDeleteDocuments
                && $doc->pipeline_status === $currentStatus,
            'deleteUrl'    => $this->Url->build(
                ['action' => 'deleteDocument', $invoice->id, $doc->id]
            ),
            'showBadge'    => !$multipleDocStatuses,
            'badgeColors'  => $badgeColors,
            'statusLabels' => $statusLabels,
        ];
    }
    $docGroups[] = [
        'label'    => $multipleDocStatuses ? ($statusLabels[$status] ?? $status) : null,
        'pillKind' => $multipleDocStatuses ? ($statusPills[$status] ?? 'pill-muted') : null,
        'rows'     => $rows,
    ];
}
?>
<?= $this->element('documents_section', [
    'groups'        => $docGroups,
    'totalDocs'     => $totalDocs,
    'canUpload'     => $showUploadSection,
    'uploadModalId' => 'uploadInvoiceDocModal',
    'emptyTitle'    => 'Sin soportes adjuntos',
]) ?>

        <?php /* ── Log de correos (cuando aplica) ──────────────── */ ?>
        <?= $this->element('email_log_panel', ['emailLogs' => $viewModel->emailLogs ?? []]) ?>


    </main>
</div><?php /* fin .row */ ?>
</div><?php /* fin .sgi-edit-shell-body */ ?>

<?php /* ── Footer de acciones ──────────────────────────── */ ?>
<?php if (!empty($viewModel->editableFields) || $canRegress): ?>
<div class="sgi-edit-footer">
    <div class="sgi-edit-footer-meta">
        <span class="d-inline-flex align-items-center gap-1">
            <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
            Rol: <strong style="color:var(--text-default);"><?= h($viewModel->roleName) ?></strong>
        </span>
        <?php if ($invoice->modified): ?>
        <span style="width:1px;height:14px;background:var(--rule);"></span>
        <span class="d-inline-flex align-items-center gap-1">
            <i class="bi bi-clock sgi-fg-faint" aria-hidden="true"></i>
            Última modificación: <span class="mono"><?= $invoice->modified->format('d/m/Y H:i') ?></span>
        </span>
        <?php endif; ?>
        <span style="width:1px;height:14px;background:var(--rule);" data-dirty-indicator hidden></span>
        <span class="d-inline-flex align-items-center gap-1 sgi-fg-secondary"
              data-dirty-indicator hidden style="font-weight:600;">
            <span style="width:6px;height:6px;background:var(--secondary-color);border-radius:50%;"></span>
            Hay cambios sin guardar
        </span>
    </div>
    <div class="sgi-edit-footer-actions">
        <?= $this->Html->link('Cancelar', $viewUrl, ['class' => 'btn btn-ghost']) ?>
        <?php if (!empty($viewModel->editableFields)): ?>
            <button type="submit" class="<?= h($viewModel->submitButtonClass) ?>">
                <?= $viewModel->submitButtonHtml ?>
            </button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?= $this->Form->end() ?>

</div><?php /* fin .sgi-edit-shell */ ?>

<?php /* ═══════════════════ MODALES ═══════════════════ */ ?>
<?php if ($canRegress && empty($viewModel->regressLockMessage)): ?>
<?= $this->element('regress_status_modal', [
    'actionUrl'  => $this->Url->build(['action' => 'regressStatus', $invoice->id]),
    'entityNoun' => 'factura',
    'currLabel'  => $currentLabel,
    'prevLabel'  => $viewModel->pipelineLabels[$viewModel->previousStatus] ?? $viewModel->previousStatus,
]) ?>
<?php endif; ?>

<?php if ($currentStatus === InvoiceConstants::STATUS_APROBACION && !empty($viewModel->editableFields)): ?>
<?= $this->element('invoice_edit/modify_approvers_modal', [
    'invoice' => $invoice,
    'approvers' => $viewModel->approvers,
]) ?>
<?php endif; ?>

<?php if ($showUploadSection): ?>
<?= $this->element('invoice_edit/upload_doc_modal', ['invoice' => $invoice]) ?>
<?php endif; ?>

<?= $this->element('observations/drawer', [
    'observations'    => $invoice->invoice_observations ?? [],
    'count'           => is_array($invoice->invoice_observations ?? null)
        ? count($invoice->invoice_observations) : 0,
    'formUrl'         => ['action' => 'addObservation', $invoice->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?= $this->element('document_row_template', ['showBadge' => true]) ?>
<?= $this->Html->script('sgi-document-uploader', ['block' => true]) ?>

<?php /* El chat de observaciones es autocontenido en observations/drawer.php
         (emite su <template> e inicializa SgiObservationChat). */ ?>
<?= $this->element('invoice_edit/scripts', ['isAdvance' => $isAdvance]) ?>
