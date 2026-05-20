<?php
/**
 * Shared payment section element.
 *
 * Visual pattern (design.md §09 "Card compleja" — fila de pago: sub-superficie
 * bg-subtle + .bank-chip + pill soft + monto mono; §16 empty-state cuando no
 * hay pagos; §06 pills soft para estado del pago).
 *
 * JS contract — `webroot/js/sgi-payment.js` depends on (must be preserved):
 *   container: [data-payment-section], data-add-url, data-remaining-amount,
 *              data-force-full-amount
 *   inputs:    [data-pay-bank], [data-pay-amount], [data-pay-date], [data-pay-full]
 *   buttons:   [data-btn-register-advance], .btn-post-action, .btn-reject-payment,
 *              data-url, data-confirm
 *
 * Variables:
 * @var array  $payments           List of payment entities (with banking_entity, created_by_user, authorized_by_user)
 * @var array  $bankingEntities    Banking entities [id => name]
 * @var array  $addPaymentUrl      CakePHP URL array for addPayment action
 * @var callable|null $authorizeUrlFn  fn(paymentId) => URL array for authorizePayment
 * @var callable|null $rejectUrlFn     fn(paymentId) => URL array for rejectPayment
 * @var callable|null $deleteUrlFn     fn(paymentId) => URL array for deletePayment (null if not supported)
 * @var bool   $canRegisterPayment Whether current user can register new payments
 * @var bool   $canAuthorize       Whether current user can authorize/reject payments
 * @var bool   $canDelete          Whether current user can delete payments
 * @var string|null $paymentStatus Payment status string (e.g. 'Pago total', 'Pago Parcial') or null
 * @var float|null  $totalAmount   Total amount of the parent record (for remaining calculation)
 * @var string $rejectMessage      Confirmation message for reject action
 * @var string $mode                One of 'tesoreria_register','authorize','close','view' (default 'view')
 * @var callable|null $editUrlFn    fn(paymentId) => URL array for editPayment (sub-fase tesoreria_register)
 * @var callable|null $rejectUrlReasonFn fn(paymentId) => URL array for rejectPayment with reason modal
 * @var bool   $forceFullAmount    When true, the amount input is hidden and prefilled with totalAmount
 *                                 (use for modules that only allow a single bulk payment for the total).
 * @var bool   $singlePaymentOnly  When true, hide "Agregar Pago" button if a payment already exists
 *                                 (pending, authorized, or any non-rejected).
 */

// Defaults
$payments = $payments ?? [];
$canRegisterPayment = $canRegisterPayment ?? false;
$canAuthorize = $canAuthorize ?? false;
$canDelete = $canDelete ?? false;
$deleteUrlFn = $deleteUrlFn ?? null;
$editUrlFn = $editUrlFn ?? null;
$rejectUrlReasonFn = $rejectUrlReasonFn ?? null;
$paymentStatus = $paymentStatus ?? null;
$totalAmount = (float)($totalAmount ?? 0);
$rejectMessage = $rejectMessage ?? '¿Rechazar este pago? El registro volverá a Tesorería.';
$mode = $mode ?? 'view';
$sectionTitle = $sectionTitle ?? 'Tesorería — Pagos';
$sectionIcon = $sectionIcon ?? 'bi-bank';
$forceFullAmount = $forceFullAmount ?? false;
$singlePaymentOnly = $singlePaymentOnly ?? false;

// If singlePaymentOnly, suppress "Agregar Pago" when there is any non-rejected payment.
$hasActivePayment = false;
foreach ($payments as $p) {
    $st = $p->status ?? ($p->authorized ? 'authorized' : 'pending');
    if ($st !== 'rejected') {
        $hasActivePayment = true;
        break;
    }
}
$showAddButton = $canRegisterPayment && !($singlePaymentOnly && $hasActivePayment);

// Compute totals
$paymentsTotal = 0;
foreach ($payments as $p) {
    $paymentsTotal += (float)$p->amount;
}
$remainingAmount = max(0, $totalAmount - $paymentsTotal);

$addUrl = $this->Url->build($addPaymentUrl);
?>
<div class="mb-4"
     data-payment-section
     data-add-url="<?= h($addUrl) ?>"
     data-remaining-amount="<?= $remainingAmount ?>"
     data-force-full-amount="<?= $forceFullAmount ? '1' : '0' ?>">

    <!-- Section header -->
    <div class="row-flex gap-12 mb-3">
        <span class="sgi-label row-flex gap-4 grow">
            <i class="bi <?= h($sectionIcon) ?>" aria-hidden="true"></i><?= h($sectionTitle) ?>
        </span>
        <?php if ($paymentStatus !== null): ?>
            <?php if ($paymentStatus === 'Pago total'): ?>
                <span class="pill pill-primary-soft">
                    <i class="bi bi-check" aria-hidden="true"></i>PAGO TOTAL
                </span>
            <?php elseif ($paymentStatus === 'Pago Parcial'): ?>
                <span class="pill pill-warning-soft">
                    <i class="bi bi-clock" aria-hidden="true"></i>PAGO PARCIAL
                </span>
            <?php else: ?>
                <span class="pill pill-muted">SIN PAGOS</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Resumen: total pagado + saldo restante -->
    <?php if ($totalAmount > 0): ?>
    <div class="row-flex gap-12 mb-3" style="flex-wrap:wrap;">
        <div style="background:var(--bg-subtle);padding:10px 14px;min-width:150px;">
            <div class="sgi-label">Total pagado</div>
            <div class="mono sgi-fg-strong" style="font-size:15px;font-weight:700;margin-top:3px;">
                $ <?= number_format($paymentsTotal, 0, ',', '.') ?>
            </div>
        </div>
        <?php if ($remainingAmount > 0): ?>
        <div style="background:var(--bg-subtle);padding:10px 14px;min-width:150px;position:relative;">
            <span class="accent-strip accent-orange"></span>
            <div style="padding-left:8px;">
                <div class="sgi-label">Saldo restante</div>
                <div class="mono sgi-fg-secondary" style="font-size:15px;font-weight:700;margin-top:3px;">
                    $ <?= number_format($remainingAmount, 0, ',', '.') ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Payments list sub-section -->
    <div class="mt-2">
        <div class="row-flex gap-12 mb-2">
            <span class="sgi-label row-flex gap-4 grow">
                <i class="bi bi-credit-card" aria-hidden="true"></i>Pagos Registrados
            </span>
            <?php if ($showAddButton): ?>
            <button type="button" class="btn btn-secondary btn-sm"
                    data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>Agregar Pago
            </button>
            <?php endif; ?>
        </div>

        <!-- Add payment form (collapsible) -->
        <?php if ($showAddButton): ?>
        <div class="collapse mb-3" id="add-payment-form">
            <div style="background:var(--bg-subtle);padding:16px;">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="sgi-label d-block mb-1">Entidad Bancaria</label>
                        <select data-pay-bank class="form-select select2-enable" required>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($bankingEntities as $beId => $beName): ?>
                                <option value="<?= $beId ?>"><?= h($beName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($forceFullAmount): ?>
                    <div class="col-md-3">
                        <label class="sgi-label d-block mb-1">Monto (COP)</label>
                        <input type="text" data-pay-amount class="form-control currency-input mono"
                               value="<?= $totalAmount ?>" readonly>
                        <div class="sgi-body-faint" style="margin-top:4px;">Pago total del registro.</div>
                    </div>
                    <?php else: ?>
                    <div class="col-md-3">
                        <label class="sgi-label d-block mb-1">Monto (COP)</label>
                        <input type="text" data-pay-amount class="form-control currency-input mono" required>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="sgi-label d-block mb-1">Fecha de Pago</label>
                        <input type="text" data-pay-date class="form-control flatpickr-date mono" required>
                    </div>
                    <?php if (!$forceFullAmount && $remainingAmount > 0): ?>
                    <div class="col-md-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="pay-full-check" data-pay-full>
                            <label class="form-check-label" for="pay-full-check" style="font-size:var(--fs-body-lg);">
                                Pago total — usar saldo restante
                                <strong class="mono">$ <?= number_format($remainingAmount, 0, ',', '.') ?></strong>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 row-flex gap-8" style="justify-content:flex-end;">
                        <button type="button" class="btn btn-ghost btn-sm"
                                data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                            Cancelar
                        </button>
                        <button type="button" data-btn-register-advance class="btn btn-primary btn-sm">
                            <i class="bi bi-check2" aria-hidden="true"></i>Registrar y enviar a autorización
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payments list -->
        <?php if (!empty($payments)): ?>
        <div class="col-flex gap-2">
            <?php foreach ($payments as $payment): ?>
            <?php
                $pStatus = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending');
                $isInvoicePayment = isset($payment->invoice_id);
                $isFromModule = $isInvoicePayment && (
                    !empty($payment->payment_scheduling_id)
                    || !empty($payment->petty_cash_record_id)
                );
            ?>
            <div class="row-flex gap-12" style="background:var(--bg-subtle);padding:12px 14px;flex-wrap:wrap;">
                <div class="bank-chip">
                    <i class="bi bi-bank" aria-hidden="true"></i>
                </div>
                <div class="grow">
                    <div style="font-size:var(--fs-body-lg);font-weight:600;color:var(--text-strong);">
                        <?= h($payment->banking_entity->name ?? '—') ?>
                    </div>
                    <div class="mono sgi-body-faint" style="margin-top:2px;">
                        <?= $payment->payment_date?->format('d/m/Y') ?? '—' ?>
                        · por <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                    </div>
                    <?php if (!empty($payment->payment_scheduling_id)): ?>
                    <div style="margin-top:6px;">
                        <?= $this->Html->link(
                            '<i class="bi bi-calendar3" aria-hidden="true"></i> ' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                            ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                            ['class' => 'pill pill-muted text-decoration-none', 'escape' => false]
                        ) ?>
                    </div>
                    <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                    <div style="margin-top:6px;">
                        <?= $this->Html->link(
                            '<i class="bi bi-credit-card" aria-hidden="true"></i> ' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                            ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                            ['class' => 'pill pill-muted text-decoration-none', 'escape' => false]
                        ) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($pStatus === 'authorized' && $payment->authorized_by_user): ?>
                    <div class="mono sgi-body-faint" style="margin-top:4px;">
                        Autorizado por <?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?>
                        · <?= $payment->authorized_date?->format('d/m/Y') ?? '' ?>
                    </div>
                    <?php elseif ($pStatus === 'rejected' && !empty($payment->rejection_reason)): ?>
                    <div class="sgi-body-faint" style="margin-top:4px;">
                        <i class="bi bi-exclamation-triangle sgi-fg-danger" aria-hidden="true"></i>
                        <?= h($payment->rejection_reason) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-flex gap-4" style="align-items:flex-end;flex-shrink:0;">
                    <?php if ($pStatus === 'authorized'): ?>
                        <span class="pill pill-primary-soft">
                            <i class="bi bi-check" aria-hidden="true"></i>AUTORIZADO
                        </span>
                    <?php elseif ($pStatus === 'rejected'): ?>
                        <span class="pill pill-danger-soft">
                            <i class="bi bi-x" aria-hidden="true"></i>RECHAZADO
                        </span>
                    <?php else: ?>
                        <span class="pill pill-warning-soft">
                            <i class="bi bi-clock" aria-hidden="true"></i>PENDIENTE
                        </span>
                    <?php endif; ?>
                    <div class="mono sgi-fg-strong" style="font-size:14px;font-weight:700;">
                        $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                    </div>
                </div>
                <?php if (($canAuthorize || $canDelete) && !$payment->authorized && !$isFromModule): ?>
                <div class="row-flex gap-4" style="width:100%;justify-content:flex-end;border-top:1px solid var(--rule);padding-top:8px;">
                    <?php if ($canAuthorize && $authorizeUrlFn): ?>
                    <button type="button" class="btn btn-secondary btn-sm btn-post-action"
                            data-url="<?= $this->Url->build($authorizeUrlFn($payment->id)) ?>"
                            data-confirm="¿Autorizar este pago?">
                        <i class="bi bi-check" aria-hidden="true"></i>Autorizar
                    </button>
                    <?php endif; ?>
                    <?php if ($canAuthorize && $rejectUrlFn): ?>
                    <button type="button" class="btn btn-danger btn-sm btn-reject-payment"
                            data-url="<?= $this->Url->build($rejectUrlFn($payment->id)) ?>">
                        <i class="bi bi-x" aria-hidden="true"></i>Rechazar
                    </button>
                    <?php endif; ?>
                    <?php if ($canDelete && $deleteUrlFn): ?>
                    <button type="button" class="btn btn-default btn-sm btn-post-action"
                            data-url="<?= $this->Url->build($deleteUrlFn($payment->id)) ?>"
                            data-confirm="¿Eliminar este pago?">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <!-- Total -->
            <div class="row-flex gap-12" style="background:var(--bg-muted);padding:10px 14px;">
                <span class="sgi-label grow">Total Pagado</span>
                <span class="mono sgi-fg-strong" style="font-size:14px;font-weight:700;">
                    $ <?= number_format($paymentsTotal, 0, ',', '.') ?>
                </span>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="es-icon es-icon-neutral"><i class="bi bi-credit-card" aria-hidden="true"></i></div>
            <div class="es-title">Sin pagos registrados</div>
            <?php if ($showAddButton): ?>
            <div class="es-msg">Registra un pago para avanzar el registro.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $this->Html->script('sgi-payment', ['block' => true]); ?>
