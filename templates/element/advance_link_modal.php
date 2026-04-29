<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\AdvanceLegalization $leg
 */
$invoices = \Cake\ORM\TableRegistry::getTableLocator()->get('Invoices');
$candidates = $invoices->find()
    ->where([
        'Invoices.document_type' => \App\Constants\InvoiceConstants::DOCTYPE_LEGALIZACION,
        'Invoices.advance_id IS' => null,
    ])
    ->contain(['Providers', 'Employees'])
    ->order(['Invoices.issue_date' => 'DESC'])
    ->all();
?>
<div class="modal fade" id="advanceLinkModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <?= $this->Form->create(null, ['url' => ['controller' => 'Advances', 'action' => 'linkInvoices', $leg->advance_invoice_id]]) ?>
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vincular facturas-Legalización</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Solo se muestran facturas con tipo "Legalización" sin anticipo asignado.</p>
                <div class="table-responsive" style="max-height: 50vh;">
                    <table class="table table-sm table-hover">
                        <thead><tr><th></th><th>#</th><th>Beneficiario</th><th>Fecha</th><th class="text-end">Monto</th></tr></thead>
                        <tbody>
                        <?php foreach ($candidates as $c): ?>
                            <tr>
                                <td><?= $this->Form->checkbox('invoice_ids[]', ['value' => $c->id, 'hiddenField' => false]) ?></td>
                                <td><?= h($c->invoice_number ?: $c->id) ?></td>
                                <td><?= h($c->provider->name ?? ($c->employee->full_name ?? '—')) ?></td>
                                <td><?= $c->issue_date ? $c->issue_date->format('d/m/Y') : '—' ?></td>
                                <td class="text-end">$<?= number_format((float)$c->amount, 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn sgi-btn-primary">Vincular seleccionadas</button>
            </div>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
