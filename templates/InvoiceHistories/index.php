<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\InvoiceHistory> $invoiceHistories
 */
$this->assign('title', 'Historial de Cambios');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Historial de Cambios</h1>
</div>

<div class="card shadow-sm">
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
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted"><?= $this->Paginator->counter('Mostrando {{start}}-{{end}} de {{count}}') ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->first('«', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->prev('‹', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->numbers(['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->next('›', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
                <?= $this->Paginator->last('»', ['class' => 'page-item', 'link' => ['class' => 'page-link']]) ?>
            </ul>
        </nav>
    </div>
</div>
