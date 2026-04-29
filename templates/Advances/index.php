<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 */
$this->assign('title', 'Anticipos');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h3 mb-0">Anticipos</h2>
        <?= $this->Html->link(
            '<i class="bi bi-plus-circle me-1"></i> Nuevo Anticipo',
            ['action' => 'add'],
            ['class' => 'btn sgi-btn-primary', 'escape' => false],
        ) ?>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Beneficiario</th>
                        <th>Centro Op.</th>
                        <th>Detalle</th>
                        <th class="text-end">Monto</th>
                        <th>Estado pago</th>
                        <th>Estado legalización</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($advances as $a): ?>
                    <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>">
                        <td><?= h($a->id) ?></td>
                        <td><?= h($a->provider->name ?? ($a->employee->full_name ?? '—')) ?></td>
                        <td><?= h($a->operation_center->name ?? '—') ?></td>
                        <td><?= h($a->detail) ?></td>
                        <td class="text-end">$<?= number_format((float)$a->amount, 0, ',', '.') ?></td>
                        <td><span class="badge bg-secondary"><?= h($a->pipeline_status) ?></span></td>
                        <td>
                            <?php if ($a->advance_legalization): ?>
                                <span class="badge bg-info text-dark">
                                    <?= h(\App\Constants\AdvanceConstants::STATUS_LABELS[$a->advance_legalization->status] ?? $a->advance_legalization->status) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $this->Html->link('<i class="bi bi-eye"></i>', ['action' => 'view', $a->id], ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= $this->element('pagination') ?>
</div>
