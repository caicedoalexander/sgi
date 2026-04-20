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
     data-remaining-amount="<?= $remainingAmount ?>">

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
            <?php if ($canRegisterPayment): ?>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#add-payment-form">
                    <i class="bi bi-plus-lg me-1"></i>Agregar Pago
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($canRegisterPayment): ?>
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
                    <div class="col-md-3">
                        <label class="form-label">Monto (COP)</label>
                        <input type="text" data-pay-amount class="form-control currency-input" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fecha de Pago</label>
                        <input type="text" data-pay-date class="form-control flatpickr-date" required>
                    </div>
                    <?php if ($remainingAmount > 0): ?>
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
                        <button type="button" data-btn-register-only class="btn btn-outline-primary">
                            <i class="bi bi-save me-1"></i>Solo registrar
                        </button>
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
                        <?php if ($canAuthorize || $canDelete): ?>
                        <td class="text-end">
                            <?php if ($canAuthorize && !$payment->authorized && empty($payment->payment_scheduling_id ?? null) && $authorizeUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-success btn-post-action"
                                    data-url="<?= $this->Url->build($authorizeUrlFn($payment->id)) ?>"
                                    data-confirm="¿Autorizar este pago?">
                                <i class="bi bi-shield-check me-1"></i>Autorizar
                            </button>
                            <?php endif; ?>
                            <?php if ($canAuthorize && !$payment->authorized && $rejectUrlFn): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-reject-payment"
                                    data-url="<?= $this->Url->build($rejectUrlFn($payment->id)) ?>">
                                <i class="bi bi-x-circle me-1"></i>Rechazar
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete && !$payment->authorized && $deleteUrlFn): ?>
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
                        <th colspan="<?= ($canAuthorize || $canDelete) ? 5 : 4 ?>">$ <?= number_format($paymentsTotal, 0, ',', '.') ?></th>
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
