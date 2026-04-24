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

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="text-uppercase fw-semibold flex-shrink-0"
              style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
            <i class="bi <?= h($sectionIcon) ?> me-1"></i><?= h($sectionTitle) ?>
        </span>
        <div style="flex:1;height:1px;background:var(--border-color);"></div>
    </div>

    <?php if ($paymentStatus !== null): ?>
    <!-- Payment status badge -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">Estado de Pago</label>
            <div class="py-1">
                <?php if ($paymentStatus === 'Pago total'): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Pago total</span>
                <?php elseif ($paymentStatus === 'Pago Parcial'): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pago Parcial</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Sin pagos</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sub-section: Registered Payments -->
    <div class="mt-2">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-uppercase fw-semibold" style="font-size:.58rem;letter-spacing:.14em;color:#bbb;">
                <i class="bi bi-credit-card me-1"></i>Pagos Registrados
            </span>
            <?php if ($showAddButton): ?>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                    <i class="bi bi-plus-lg me-1"></i>Agregar Pago
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showAddButton): ?>
        <div class="collapse mb-3" id="add-payment-form">
            <div class="card card-body" style="border-top:2px solid var(--primary-color);">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Entidad Bancaria</label>
                        <select data-pay-bank class="form-select select2-enable" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($bankingEntities as $beId => $beName): ?>
                                <option value="<?= $beId ?>"><?= h($beName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($forceFullAmount): ?>
                    <div class="col-md-3">
                        <label class="form-label">Monto (COP)</label>
                        <input type="text" data-pay-amount class="form-control currency-input"
                               value="<?= $totalAmount ?>" readonly>
                        <div class="form-text">Pago total del registro.</div>
                    </div>
                    <?php else: ?>
                    <div class="col-md-3">
                        <label class="form-label">Monto (COP)</label>
                        <input type="text" data-pay-amount class="form-control currency-input" required>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Fecha de Pago</label>
                        <input type="text" data-pay-date class="form-control flatpickr-date" required>
                    </div>
                    <?php if (!$forceFullAmount && $remainingAmount > 0): ?>
                    <div class="col-md-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="pay-full-check" data-pay-full>
                            <label class="form-check-label" for="pay-full-check">
                                Pago total (usa monto restante: $<?= number_format($remainingAmount, 0, ',', '.') ?>)
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-12 d-flex gap-2 justify-content-end">
                        <button type="button" data-btn-register-advance class="btn btn-success">
                            <i class="bi bi-send-check me-1"></i>Registrar y enviar a autorización
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($payments)): ?>
        <div style="border:1px solid var(--border-color);border-top:2px solid var(--primary-color);">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Entidad Bancaria</th>
                        <th>Monto</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Registrado por</th>
                        <th>Origen</th>
                        <?php if ($canAuthorize || $canDelete): ?>
                        <th class="text-end">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td><?= h($payment->banking_entity->name ?? '—') ?></td>
                        <td>$ <?= number_format((float)$payment->amount, 0, ',', '.') ?></td>
                        <td><?= $payment->payment_date?->format('d/m/Y') ?? '—' ?></td>
                        <td>
                            <?php $pStatus = $payment->status ?? ($payment->authorized ? 'authorized' : 'pending'); ?>
                            <?php if ($pStatus === 'authorized'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Autorizado</span>
                                <?php if ($payment->authorized_by_user): ?>
                                <br><small class="text-muted"><?= h($payment->authorized_by_user->full_name ?? $payment->authorized_by_user->username ?? '') ?> - <?= $payment->authorized_date?->format('d/m/Y') ?? '' ?></small>
                                <?php endif; ?>
                            <?php elseif ($pStatus === 'rejected'): ?>
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rechazado</span>
                                <?php if (!empty($payment->rejection_reason)): ?>
                                <br><small class="text-muted"><?= h($payment->rejection_reason) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pendiente</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($payment->created_by_user->full_name ?? $payment->created_by_user->username ?? '—') ?></td>
                        <td>
                            <?php if (!empty($payment->payment_scheduling_id)): ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-calendar-check me-1"></i>Programación ' . h($payment->payment_scheduling->code ?? '#' . $payment->payment_scheduling_id),
                                    ['controller' => 'PaymentSchedulings', 'action' => 'view', $payment->payment_scheduling_id],
                                    ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                                ) ?>
                            <?php elseif (!empty($payment->petty_cash_record_id)): ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-wallet2 me-1"></i>Caja Menor ' . h($payment->petty_cash_record->code ?? '#' . $payment->petty_cash_record_id),
                                    ['controller' => 'PettyCashRecords', 'action' => 'view', $payment->petty_cash_record_id],
                                    ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                                ) ?>
                            <?php elseif (!empty($payment->legalization_record_id)): ?>
                                <?= $this->Html->link(
                                    '<i class="bi bi-journal-check me-1"></i>Legalización ' . h($payment->legalization_record->code ?? '#' . $payment->legalization_record_id),
                                    ['controller' => 'LegalizationRecords', 'action' => 'view', $payment->legalization_record_id],
                                    ['class' => 'badge bg-light text-dark text-decoration-none border', 'escape' => false]
                                ) ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.75rem;">Individual</span>
                            <?php endif; ?>
                        </td>
                        <?php $isInvoicePayment = isset($payment->invoice_id);
                        $isFromModule = $isInvoicePayment && (
                            !empty($payment->payment_scheduling_id)
                            || !empty($payment->petty_cash_record_id)
                            || !empty($payment->legalization_record_id)
                        ); ?>
                        <?php if ($canAuthorize || $canDelete): ?>
                        <td class="text-end">
                            <?php if ($canAuthorize && !$payment->authorized && !$isFromModule && $authorizeUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-post-action"
                                    data-url="<?= $this->Url->build($authorizeUrlFn($payment->id)) ?>"
                                    data-confirm="¿Autorizar este pago?">
                                <i class="bi bi-shield-check me-1"></i>Autorizar
                            </button>
                            <?php endif; ?>
                            <?php if ($canAuthorize && !$payment->authorized && !$isFromModule && $rejectUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-reject-payment"
                                    data-url="<?= $this->Url->build($rejectUrlFn($payment->id)) ?>">
                                <i class="bi bi-x-circle me-1"></i>Rechazar
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete && !$payment->authorized && !$isFromModule && $deleteUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-post-action"
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
                <tfoot class="table-light">
                    <tr>
                        <th>Total Pagado</th>
                        <th colspan="<?= ($canAuthorize || $canDelete) ? 6 : 5 ?>">$ <?= number_format($paymentsTotal, 0, ',', '.') ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-3" style="font-size:.85rem;border:1px dashed var(--border-color);">
            <i class="bi bi-credit-card me-1"></i>No hay pagos registrados
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $this->Html->script('sgi-payment', ['block' => true]); ?>
