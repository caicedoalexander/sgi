<?php
/**
 * Caja Menor / edit — formulario de edición de un registro de Caja Menor.
 *
 * Reescrito (mayo 2026) para el Sistema de Diseño v2: replica el patrón de
 * `Invoices/edit.php` — panel izquierdo (hero card + pipeline vertical inline
 * + acciones + registro) y panel derecho (banner de avance, secciones de
 * formulario condicionales por estado, soportes + observaciones, footer).
 *
 * La lógica de negocio se preserva intacta respecto a la versión legacy:
 * secciones condicionales por estado/rol, forms (registrar pago, subir
 * documento, vincular facturas, observaciones), hidden inputs, CSRF, IDs
 * requeridos por el JS (#pettyCashEditForm, #upload-doc-form, #docs-list,
 * #docs-empty-state, #obs-form, #obs-count, #obs-chat-scroll, #obs-empty-state
 * — estos últimos son emitidos por el element `observations/drawer`).
 *
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
use App\View\Presentation\PettyCashPresentation;

$this->assign('title', $pageTitle);

// ── Alias locales ────────────────────────────────────────────────
$btnLabel = $submitButtonHtml;
$btnClass = $submitButtonClass;

// ── Pill kind del estado del pipeline (soft variants) ────────────
$statusBadge   = PettyCashPresentation::STATUS_BADGES;
$pcStatusPill  = $statusBadge[$record->status] ?? 'pill-muted';
$pcStatusLabel = $statusLabels[$record->status] ?? $record->status;

$nextLabel = $nextStatus !== null
    ? ($pipelineLabels[$nextStatus] ?? $nextStatus)
    : null;

// ── Mapa estado → accent-strip de la card de etapa actual ────────
$stageAccentMap = [
    PettyCashConstants::STATUS_AGRUPACION        => 'accent-info',
    PettyCashConstants::STATUS_CONTABILIDAD      => 'accent-green',
    PettyCashConstants::STATUS_TESORERIA         => 'accent-warning',
    PettyCashConstants::STATUS_AUTORIZACION_PAGO => 'accent-info',
    PettyCashConstants::STATUS_VERIFICACION_PAGO => 'accent-warning',
    PettyCashConstants::STATUS_PAGADA            => 'accent-green',
];
$stageAccent = $stageAccentMap[$record->status] ?? 'accent-green';

// ── Pipeline vertical ────────────────────────────────────────────
$pipelineSteps = PettyCashConstants::STATUSES;
$currentIdx    = array_search($record->status, $pipelineSteps, true);
if ($currentIdx === false) {
    $currentIdx = count($pipelineSteps);
}
$isTerminal = $record->status === PettyCashConstants::STATUS_PAGADA;

// ── Etapa N/total (banner) ───────────────────────────────────────
$stageNum   = is_int($currentIdx) ? $currentIdx + 1 : null;
$stageTotal = count($pipelineSteps);

// ── Resumen monetario ────────────────────────────────────────────
$totalAmount = (float)$record->total_amount;

// ── Soportes ─────────────────────────────────────────────────────
$docs      = $record->petty_cash_documents ?? [];
$totalDocs = count($docs);
?>

<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<?php /* ═══════════════════ HEADER DE PÁGINA ═══════════════════ */ ?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 view-anim"
     style="padding:4px 0 16px;">
    <div style="min-width:0;">
        <div class="d-flex align-items-center flex-wrap gap-1"
             style="font-size:var(--fs-body-sm);color:var(--text-faint);margin-bottom:6px;">
            <?= $this->Html->link('Caja Menor', ['action' => 'index'], ['class' => 'sgi-fg-faint', 'style' => 'text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <?= $this->Html->link(h($record->code), ['action' => 'view', $record->id], ['class' => 'sgi-fg-faint', 'style' => 'text-decoration:none;']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span style="color:var(--text-default);">Editar</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="sgi-title-page">Editar Caja Menor</span>
            <span class="mono" style="font-size:var(--fs-body-lg);color:var(--text-muted);padding:3px 8px;background:var(--bg-subtle);border-radius:var(--radius-sm);">
                <?= h($record->code) ?>
            </span>
            <span class="pill <?= h($pcStatusPill) ?>"><?= h($pcStatusLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-eye" aria-hidden="true"></i>Ver detalle',
            ['action' => 'view', $record->id],
            ['class' => 'btn btn-default', 'escape' => false]
        ) ?>
    </div>
</div>

<?= $this->Form->create($record, ['id' => 'pettyCashEditForm']) ?>
<?= $this->Form->hidden('expected_status', ['value' => $record->status]) ?>

<div class="row g-3 view-anim">

    <?php /* ═══════════════════ COLUMNA IZQUIERDA ═══════════════════ */ ?>
    <aside class="col-lg-4 d-flex flex-column gap-3">

        <?php /* ── Hero: resumen del registro ─────────────────── */ ?>
        <div class="sgi-card" style="position:relative;">
            <div class="d-flex align-items-start" style="gap:12px;margin-bottom:16px;">
                <div style="width:40px;height:40px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--primary-soft);color:var(--primary-color);border-radius:var(--radius-sm);">
                    <i class="bi bi-wallet2" aria-hidden="true" style="font-size:18px;"></i>
                </div>
                <div style="min-width:0;flex:1;">
                    <div class="mono" style="font-size:16px;font-weight:700;color:var(--text-strong);line-height:1.15;">
                        <?= h($record->code) ?>
                    </div>
                    <div class="d-flex flex-wrap" style="gap:4px;margin-top:6px;">
                        <span class="pill pill-secondary">Caja Menor</span>
                        <span class="pill <?= h($pcStatusPill) ?>"><?= h($pcStatusLabel) ?></span>
                    </div>
                </div>
            </div>

            <div class="sgi-label">Centro de Operación</div>
            <div style="font-size:var(--fs-body);font-weight:600;color:var(--text-default);margin-top:4px;line-height:1.3;">
                <?= h($record->operation_center->name ?? '—') ?>
            </div>
            <div class="d-flex align-items-center gap-1" style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                <i class="bi bi-receipt" aria-hidden="true" style="font-size:11px;"></i>
                <span><?= $invoiceCount ?> factura<?= $invoiceCount !== 1 ? 's' : '' ?></span>
            </div>

            <div class="hr" style="margin:16px 0 14px;"></div>

            <div class="sgi-label">Total</div>
            <div style="margin-top:4px;">
                <?php if ($totalAmount > 0): ?>
                    <span class="sgi-display">$ <?= number_format($totalAmount, 0, ',', '.') ?></span>
                <?php else: ?>
                    <span class="sgi-display" style="color:var(--text-disabled);">$ —</span>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ── Pipeline vertical (inline) ──────────────────── */ ?>
        <div class="sgi-card compact">
            <span class="sgi-label">Pipeline</span>
            <div class="pipeline-v" style="margin-top:8px;">
                <?php foreach ($pipelineSteps as $idx => $stepKey):
                    $isDone    = $idx < $currentIdx || ($isTerminal && $idx === $currentIdx);
                    $isCurrent = !$isTerminal && $idx === $currentIdx;
                    $stepLabel = $pipelineLabels[$stepKey] ?? $stepKey;

                    $stepClasses = 'pv-step';
                    if ($isDone)        { $stepClasses .= ' is-done'; }
                    elseif ($isCurrent) { $stepClasses .= ' is-current'; }
                    else                { $stepClasses .= ' is-pending'; }

                    $stepMeta = null;
                    if ($isCurrent || ($isTerminal && $idx === $currentIdx)) {
                        $stepMeta = $record->modified?->format('d/m H:i');
                    } elseif (!$isDone) {
                        $stepMeta = 'Pendiente';
                    }
                ?>
                <div class="<?= $stepClasses ?>">
                    <div class="pv-marker">
                        <?php if ($isDone): ?>
                            <i class="bi bi-check" aria-hidden="true"></i>
                        <?php elseif ($isCurrent): ?>
                            <span class="dot"></span>
                        <?php endif; ?>
                    </div>
                    <div style="min-width:0;">
                        <div class="pv-label"><?= h($stepLabel) ?></div>
                        <?php if ($stepMeta): ?>
                            <div class="pv-meta"><?= h($stepMeta) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php /* ── Acciones de etapa (regresión) ───────────────── */ ?>
        <?php if (!empty($canRegress)):
            $prevLabel     = $pipelineLabels[$previousStatus] ?? $previousStatus;
            $regressLocked = !empty($regressLockMessage);
        ?>
        <div class="sgi-card compact">
            <span class="sgi-label">Acciones</span>
            <div class="d-flex flex-column gap-1" style="margin-top:10px;">
                <?php if ($regressLocked): ?>
                    <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                            disabled title="<?= h($regressLockMessage) ?>">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar al paso anterior
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-ghost btn-sm w-100 justify-content-start"
                            data-bs-toggle="modal" data-bs-target="#regressStatusModal">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Regresar a: <?= h($prevLabel) ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── Registro / auditoría ────────────────────────── */ ?>
        <div class="sgi-card compact">
            <span class="sgi-label">Registro</span>
            <div class="d-flex align-items-center gap-2 mt-2" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                <span>Rol: <strong style="color:var(--text-default);"><?= h($roleName) ?></strong></span>
            </div>
            <?php if ($record->created): ?>
            <div class="d-flex align-items-center gap-2 mt-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-calendar3 sgi-fg-faint" aria-hidden="true"></i>
                <span>Creado · <span class="mono"><?= $record->created->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
            <?php if ($record->modified): ?>
            <div class="d-flex align-items-center gap-2 mt-1" style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <i class="bi bi-pencil sgi-fg-faint" aria-hidden="true"></i>
                <span>Modificado · <span class="mono"><?= $record->modified->format('d/m/Y') ?></span></span>
            </div>
            <?php endif; ?>
        </div>

    </aside>

    <?php /* ═══════════════════ COLUMNA DERECHA ═══════════════════ */ ?>
    <main class="col-lg-8 d-flex flex-column gap-3">

        <?php /* ── Banner: requisitos para avanzar ─────────────── */ ?>
        <?php if ($canAdvance && !empty($advanceErrors)): ?>
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
                    <?php foreach ($advanceErrors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($stageNum !== null): ?>
                <span class="pill pill-warning-soft pill-lg flex-shrink-0">Etapa <?= $stageNum ?>/<?= $stageTotal ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        // ── Section reordering: editable sections first ──
        $sections = [];
        if ($record->isAgrupacion() || $record->isContabilidad()) {
            $sections[] = ['key' => 'notes', 'editable' => true];
        }
        $sections[] = ['key' => 'invoices', 'editable' => $record->isAgrupacion()];
        if ($showAccounting) {
            $sections[] = ['key' => 'accounting', 'editable' => $canEditAccounting];
        }
        if ($showTreasury) {
            $sections[] = ['key' => 'treasury', 'editable' => $canEditTreasury];
        }
        usort($sections, fn($a, $b) => $b['editable'] <=> $a['editable']);
        ?>

        <?php /* ── Información del registro: secciones del pipeline ── */ ?>
        <?php if (!empty($sections)): ?>
        <div class="sgi-card" style="position:relative;">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-bottom:14px;">
                <div>
                    <div class="sgi-label" style="color:var(--text-faint);">Etapa actual</div>
                    <div class="sgi-title-card d-inline-flex align-items-center gap-2" style="margin-top:4px;">
                        <?= h($pcStatusLabel) ?>
                        <?php if ($nextLabel && !$isTerminal): ?>
                            <span style="font-size:11px;color:var(--text-faint);font-weight:500;">
                                <i class="bi bi-arrow-right" aria-hidden="true" style="font-size:10px;"></i>
                                próximo: <?= h($nextLabel) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="hr" style="margin-bottom:16px;"></div>

            <?php foreach ($sections as $section): ?>

                <?php /* ── Notas ───────────────────────────────── */ ?>
                <?php if ($section['key'] === 'notes'): ?>
                <div class="mb-4">
                    <label class="input-label">Notas</label>
                    <textarea name="notes" class="form-control auto-resize" rows="2"><?= h($record->notes ?? '') ?></textarea>
                </div>
                <?php endif; ?>

                <?php /* ── Facturas agrupadas ──────────────────── */ ?>
                <?php if ($section['key'] === 'invoices'): ?>
                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <span class="sgi-label d-inline-flex align-items-center gap-2">
                            <i class="bi bi-receipt" aria-hidden="true"></i>
                            Facturas Agrupadas
                            <span class="sgi-folder-count"><?= $invoiceCount ?></span>
                        </span>
                        <?php if ($record->isAgrupacion()): ?>
                        <button type="button" class="btn btn-secondary btn-sm"
                                data-bs-toggle="modal" data-bs-target="#linkPettyCashInvoicesModal">
                            <i class="bi bi-link-45deg" aria-hidden="true"></i>Vincular facturas
                        </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($record->invoices)): ?>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th># Factura</th>
                                    <th>Proveedor</th>
                                    <th style="text-align:right;">Monto</th>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <th style="text-align:center;width:60px;"></th>
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
                                    <td class="mono" style="text-align:right;">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <td style="text-align:center;">
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-x-lg" aria-hidden="true"></i>',
                                            ['action' => 'removeInvoice', $record->id, $inv->id],
                                            [
                                                'confirm' => '¿Remover esta factura del registro?',
                                                'class'   => 'btn-icon',
                                                'escape'  => false,
                                                'title'   => 'Quitar',
                                                'block'   => true,
                                            ]
                                        ) ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" style="text-align:right;font-weight:700;">Total:</td>
                                    <td class="mono" style="text-align:right;font-weight:700;color:var(--primary-color);">
                                        $ <?= number_format($totalAmount, 0, ',', '.') ?>
                                    </td>
                                    <?php if ($record->isAgrupacion()): ?>
                                    <td></td>
                                    <?php endif; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-0">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="flex-shrink:0;margin-top:1px;"></i>
                        <div>No hay facturas agrupadas. Agregue al menos una factura para poder avanzar.</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php /* ── Contabilidad ────────────────────────── */ ?>
                <?php if ($section['key'] === 'accounting'): ?>
                <div class="mb-4">
                    <div class="sgi-label d-inline-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-calculator" aria-hidden="true"></i>Contabilidad
                    </div>
                    <div class="row g-2 g-md-3">
                        <div class="col-md-4">
                            <label class="input-label">Causada</label>
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
                            <label class="input-label">Fecha de Causación<?= $canEditAccounting ? ' *' : '' ?></label>
                            <?php if ($canEditAccounting): ?>
                            <input type="text" name="accrual_date" class="form-control flatpickr-date"
                                   value="<?= $record->accrual_date ? (is_string($record->accrual_date) ? $record->accrual_date : $record->accrual_date->format('Y-m-d')) : '' ?>">
                            <?php else: ?>
                            <input type="text" class="form-control mono" disabled
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

                <?php /* ── Tesorería: registro de pagos ────────── */ ?>
                <?php if ($section['key'] === 'treasury'): ?>
                <?php if (!empty($record->payment_rejection_reason)
                    && $record->status === PettyCashConstants::STATUS_TESORERIA): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="flex-shrink:0;margin-top:1px;"></i>
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
                    'totalAmount'        => $totalAmount,
                    'rejectMessage'      => '¿Rechazar este pago? El registro volverá a Tesorería.',
                    'sectionTitle'       => 'Pago',
                    'sectionIcon'        => 'bi-bank',
                    'forceFullAmount'    => true,
                    'singlePaymentOnly'  => true,
                ]) ?>
                <?php endif; ?>

            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php /* ── Confirmación de pago (Verificación) ─────────── */ ?>
        <?= $this->element('confirm_payment_card', [
            'isVerificacionPago' => $record->status === PettyCashConstants::STATUS_VERIFICACION_PAGO,
            'canConfirm'         => $canConfirmPayment ?? false,
            'confirmUrl'         => ['action' => 'confirmPayment', $record->id],
        ]) ?>

        <?php /* ── Soportes (ancho completo) ──── */ ?>
        <?php
        $canUploadDocs = !$record->isPagada();
        $pettyCashDocRows = [];
        foreach ($docs as $doc) {
            $pettyCashDocRows[] = [
                'doc'       => $doc,
                'canDelete' => $canUploadDocs,
                'deleteUrl' => $this->Url->build(['action' => 'deleteDocument', $record->id, $doc->id]),
                'showBadge' => false,
            ];
        }
        ?>
        <?= $this->element('documents_section', [
            'groups'        => [['label' => null, 'pillKind' => null, 'rows' => $pettyCashDocRows]],
            'totalDocs'     => $totalDocs,
            'canUpload'     => $canUploadDocs,
            'uploadModalId' => 'uploadPcDocModal',
            'emptyTitle'    => 'Sin soportes adjuntos',
        ]) ?>

        <?php /* ── Footer de acciones ──────────────────────────── */ ?>
        <?php if ($canSave || !empty($canRegress)): ?>
        <div class="sgi-card d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center flex-wrap gap-3"
                 style="font-size:var(--fs-body-sm);color:var(--text-muted);">
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-person sgi-fg-faint" aria-hidden="true"></i>
                    Rol: <strong style="color:var(--text-default);"><?= h($roleName) ?></strong>
                </span>
                <?php if ($record->modified): ?>
                <span style="width:1px;height:14px;background:var(--rule);"></span>
                <span class="d-inline-flex align-items-center gap-1">
                    <i class="bi bi-clock sgi-fg-faint" aria-hidden="true"></i>
                    Última modificación: <span class="mono"><?= $record->modified->format('d/m/Y H:i') ?></span>
                </span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <?php if ($record->isAgrupacion() && !empty($userPermissions['petty_cash']['can_delete'])): ?>
                <?= $this->Form->postLink(
                    '<i class="bi bi-trash" aria-hidden="true"></i>Eliminar',
                    ['action' => 'delete', $record->id],
                    [
                        'class'   => 'btn btn-ghost',
                        'escape'  => false,
                        'style'   => 'color:var(--danger-color);',
                        'confirm' => '¿Eliminar este registro? Las facturas agrupadas quedarán libres.',
                        'block'   => true,
                    ]
                ) ?>
                <?php endif; ?>
                <?= $this->Html->link('Cancelar', ['action' => 'view', $record->id], ['class' => 'btn btn-ghost']) ?>
                <?php if ($canSave): ?>
                <button type="submit" class="<?= h($btnClass) ?>">
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
    'observations'    => $record->petty_cash_observations ?? [],
    'count'           => count($record->petty_cash_observations ?? []),
    'formUrl'         => ['action' => 'addObservation', $record->id],
    'currentUserName' => $currentUser->full_name
        ?? ($currentUser->username ?? 'Usuario'),
]) ?>

<?php /* ═══════════════════ MODALES ═══════════════════ */ ?>
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
                    <button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i>Subir</button>
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
