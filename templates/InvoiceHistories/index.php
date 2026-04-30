<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\InvoiceHistory> $invoiceHistories
 */
$this->assign('title', 'Historial de Cambios');
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Historial de Cambios</span>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= $this->Paginator->sort('id', '#') ?></th>
                    <th><?= $this->Paginator->sort('invoice_id', 'Factura') ?></th>
                    <th><?= $this->Paginator->sort('Users.full_name', 'Usuario') ?></th>
                    <th><?= $this->Paginator->sort('field_changed', 'Campo') ?></th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                    <th><?= $this->Paginator->sort('created', 'Fecha') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoiceHistories as $history): ?>
                <tr>
                    <td><?= $this->Number->format($history->id) ?></td>
                    <td><?= $this->Html->link('#' . $history->invoice_id, ['controller' => 'Invoices', 'action' => 'view', $history->invoice_id]) ?></td>
                    <td><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td><code><?= h($history->field_changed) ?></code></td>
                    <td class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                    <td><?= $history->created?->format('d/m/Y H:i') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>
