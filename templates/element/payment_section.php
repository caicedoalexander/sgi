<?php
/**
 * Shared payment section element.
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
$withIdempotencyKey = $withIdempotencyKey ?? false;
$idempotencyKey = $withIdempotencyKey ? \Cake\Utility\Text::uuid() : null;

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
     data-force-full-amount="<?= $forceFullAmount ? '1' : '0' ?>"
     <?php if ($idempotencyKey): ?>data-idempotency-key="<?= h($idempotencyKey) ?>"<?php endif; ?>>

    <!-- Section header -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi <?= h($sectionIcon) ?> me-1"></i><?= h($sectionTitle) ?>
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
        <?php if ($paymentStatus !== null): ?>
            <?php if ($paymentStatus === 'Pago total'): ?>
                <span class="badge flex-shrink-0"
                      style="background:var(--primary-color);border-radius:0;font-size:.58rem;letter-spacing:.08em;">
                    <i class="bi bi-check-circle me-1"></i>PAGO TOTAL
                </span>
            <?php elseif ($paymentStatus === 'Pago Parcial'): ?>
                <span class="badge bg-warning text-dark flex-shrink-0"
                      style="border-radius:0;font-size:.58rem;letter-spacing:.08em;">
                    <i class="bi bi-clock me-1"></i>PAGO PARCIAL
                </span>
            <?php else: ?>
                <span class="badge bg-secondary flex-shrink-0"
                      style="border-radius:0;font-size:.58rem;letter-spacing:.08em;">SIN PAGOS</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Mini stat callouts: total pagado + saldo restante -->
    <?php if ($totalAmount > 0): ?>
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);padding:.45rem .8rem;background:#fff;min-width:140px;">
            <div style="font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:#aaa;margin-bottom:.1rem;">Total pagado</div>
            <div style="font-size:1.05rem;font-weight:700;letter-spacing:-.03em;color:#111;">
                $ <?= number_format($paymentsTotal, 0, ',', '.') ?>
            </div>
        </div>
        <?php if ($remainingAmount > 0): ?>
        <div style="border:1px solid var(--border-color);border-top:2px solid var(--secondary-color);padding:.45rem .8rem;background:#fff;min-width:140px;">
            <div style="font-size:.55rem;letter-spacing:.12em;text-transform:uppercase;color:#aaa;margin-bottom:.1rem;">Saldo restante</div>
            <div style="font-size:1.05rem;font-weight:700;letter-spacing:-.03em;color:var(--secondary-color);">
                $ <?= number_format($remainingAmount, 0, ',', '.') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Payments list sub-section -->
    <div class="mt-2">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-uppercase fw-semibold" style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                <i class="bi bi-credit-card me-1"></i>Pagos Registrados
            </span>
            <?php if ($showAddButton): ?>
            <button type="button" class="btn btn-sm btn-outline-primary"
                    style="border-radius:0;font-size:.75rem;"
                    data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                <i class="bi bi-plus-lg me-1"></i>Agregar Pago
            </button>
            <?php endif; ?>
        </div>

        <!-- Add payment form (collapsible) -->
        <?php if ($showAddButton): ?>
        <div class="collapse mb-3" id="add-payment-form">
            <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);background:#fff;padding:1rem 1rem .75rem;">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"
                               style="font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#888;">
                            Entidad Bancaria
                        </label>
                        <select data-pay-bank class="form-select select2-enable" required>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($bankingEntities as $beId => $beName): ?>
                                <option value="<?= $beId ?>"><?= h($beName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($forceFullAmount): ?>
                    <div class="col-md-3">
                        <label class="form-label"
                               style="font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#888;">
                            Monto (COP)
                        </label>
                        <input type="text" data-pay-amount class="form-control currency-input"
                               value="<?= $totalAmount ?>" readonly>
                        <div class="form-text" style="font-size:.7rem;">Pago total del registro.</div>
                    </div>
                    <?php else: ?>
                    <div class="col-md-3">
                        <label class="form-label"
                               style="font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#888;">
                            Monto (COP)
                        </label>
                        <input type="text" data-pay-amount class="form-control currency-input" required>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label"
                               style="font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:#888;">
                            Fecha de Pago
                        </label>
                        <input type="text" data-pay-date class="form-control flatpickr-date" required>
                    </div>
                    <?php if (!$forceFullAmount && $remainingAmount > 0): ?>
                    <div class="col-md-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="pay-full-check" data-pay-full>
                            <label class="form-check-label" for="pay-full-check" style="font-size:.82rem;">
                                Pago total — usar saldo restante
                                <strong>$ <?= number_format($remainingAmount, 0, ',', '.') ?></strong>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 d-flex gap-2 justify-content-end"
                         style="border-top:1px solid var(--border-color);padding-top:.75rem;margin-top:.1rem;">
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                style="border-radius:0;font-size:.78rem;"
                                data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                            Cancelar
                        </button>
                        <button type="button" data-btn-register-advance
                                class="btn btn-sm sgi-btn-primary">
                            <i class="bi bi-send-check me-1"></i>Registrar y enviar a autorización
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payments table -->
        <?php if (!empty($payments)): ?>
        <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
            <table class="table table-sm mb-0">
                <thead>
                    <tr style="background:#fafafa;">
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Entidad Bancaria</th>
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Monto</th>
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Fecha</th>
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Estado</th>
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Registrado por</th>
                        <th style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">Origen</th>
                        <?php if ($canAuthorize || $canDelete): ?>
                        <th class="text-end"
                            style="font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:#999;font-weight:600;border-bottom:1px solid var(--border-color);">
                            Acciones
                        </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <?php
                        $pStatus = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending');
                        $rowAccent = match($pStatus) {
                            'authorized' => 'var(--primary-color)',
                            'rejected'   => '#dc3545',
                            default      => 'var(--secondary-color)',
                        };
                    ?>
                    <tr style="border-left:3px solid <?= $rowAccent ?>;">
                        <td style="font-size:.82rem;"><?= h($payment->banking_entity->name ?? '—') ?></td>
                        <td style="font-size:.82rem;font-weight:600;letter-spacing:-.02em;">
                            $ <?= number_format((float)$payment->amount, 0, ',', '.') ?>
                        </td>
                        <td style="font-size:.82rem;"><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></td>
                        <td>
                            <?php if ($pStatus === 'authorized'): ?>
                                <span class="badge"
                                      style="background:var(--primary-color);border-radius:0;font-size:.6rem;letter-spacing:.06em;">
                                    <i class="bi bi-check-circle me-1"></i>AUTORIZADO
                                </span>
                                <?php if ($payment->authorized_by_user): ?>
                                <br><small class="text-muted" style="font-size:.7rem;">
                                    <?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?>
                                    · <?= $payment->authorized_date?->format('d/m/Y') ?? '' ?>
                                </small>
                                <?php endif; ?>
                            <?php elseif ($pStatus === 'rejected'): ?>
                                <span class="badge bg-danger"
                                      style="border-radius:0;font-size:.6rem;letter-spacing:.06em;">
                                    <i class="bi bi-x-circle me-1"></i>RECHAZADO
                                </span>
                                <?php if (!empty($payment->rejection_reason)): ?>
                                <br><small class="text-muted" style="font-size:.7rem;"><?= h($payment->rejection_reason) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"
                                      style="border-radius:0;font-size:.6rem;letter-spacing:.06em;">
                                    <i class="bi bi-clock me-1"></i>PENDIENTE
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?>
                        </td>
                        <td>
                            <?php if (!empty($payment->payment_scheduling_id)): ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-calendar-check me-1"></i>' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                                    ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                                    ['class' => 'badge bg-light text-dark text-decoration-none border', 'style' => 'border-radius:0;font-size:.65rem;', 'escape' => false]
                                ) ?>
                            <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-wallet2 me-1"></i>' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                                    ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                                    ['class' => 'badge bg-light text-dark text-decoration-none border', 'style' => 'border-radius:0;font-size:.65rem;', 'escape' => false]
                                ) ?>
                            <?php else: ?>
                                <span style="font-size:.7rem;color:#bbb;text-transform:uppercase;letter-spacing:.08em;">Individual</span>
                            <?php endif; ?>
                        </td>
                        <?php $isInvoicePayment = isset($payment->invoice_id);
                        $isFromModule = $isInvoicePayment && (
                            !empty($payment->payment_scheduling_id)
                            || !empty($payment->petty_cash_record_id)
                        ); ?>
                        <?php if ($canAuthorize || $canDelete): ?>
                        <td class="text-end">
                            <?php if ($canAuthorize && !$payment->authorized && !$isFromModule && $authorizeUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-post-action"
                                    style="border-radius:0;font-size:.75rem;"
                                    data-url="<?= $this->Url->build($authorizeUrlFn($payment->id)) ?>"
                                    data-confirm="¿Autorizar este pago?">
                                <i class="bi bi-shield-check me-1"></i>Autorizar
                            </button>
                            <?php endif; ?>
                            <?php if ($canAuthorize && !$payment->authorized && !$isFromModule && $rejectUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-reject-payment"
                                    style="border-radius:0;font-size:.75rem;"
                                    data-url="<?= $this->Url->build($rejectUrlFn($payment->id)) ?>">
                                <i class="bi bi-x-circle me-1"></i>Rechazar
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete && !$payment->authorized && !$isFromModule && $deleteUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-post-action"
                                    style="border-radius:0;font-size:.75rem;"
                                    data-url="<?= $this->Url->build($deleteUrlFn($payment->id)) ?>"
                                    data-confirm="¿Eliminar este pago?">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#fafafa;border-top:2px solid var(--border-color);">
                        <th style="font-size:.65rem;text-transform:uppercase;letter-spacing:.1em;color:#666;">Total Pagado</th>
                        <th colspan="<?= ($canAuthorize || $canDelete) ? 6 : 5 ?>"
                            style="font-size:.9rem;font-weight:700;letter-spacing:-.02em;">
                            $ <?= number_format($paymentsTotal, 0, ',', '.') ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4" style="border:1px dashed var(--border-color);">
            <i class="bi bi-credit-card d-block mb-1" style="font-size:1.4rem;color:#ccc;"></i>
            <span style="font-size:.7rem;text-transform:uppercase;letter-spacing:.12em;color:#bbb;">
                No hay pagos registrados
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $this->Html->script('sgi-payment', ['block' => true]); ?>
