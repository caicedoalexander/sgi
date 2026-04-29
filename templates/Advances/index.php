<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Invoice> $advances
 */

use App\Constants\AdvanceConstants;
use App\Constants\InvoiceConstants;

$this->assign('title', 'Anticipos');

$pipelineBadge = [
    InvoiceConstants::STATUS_APROBACION        => 'bg-info text-dark',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'bg-primary',
    InvoiceConstants::STATUS_TESORERIA         => 'bg-warning text-dark',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'bg-info',
    InvoiceConstants::STATUS_PAGADA            => 'bg-success',
];
$pipelineLabels = [
    InvoiceConstants::STATUS_APROBACION        => 'Aprobación',
    InvoiceConstants::STATUS_CONTABILIDAD      => 'Contabilidad',
    InvoiceConstants::STATUS_TESORERIA         => 'Tesorería',
    InvoiceConstants::STATUS_AUTORIZACION_PAGO => 'Aut. Pago',
    InvoiceConstants::STATUS_PAGADA            => 'Pagada',
];

$legalizationBadge = [
    AdvanceConstants::STATUS_VALIDACION       => 'bg-info text-dark',
    AdvanceConstants::STATUS_REVISION_FIRMAS  => 'bg-primary',
    AdvanceConstants::STATUS_CONTABILIDAD     => 'bg-warning text-dark',
    AdvanceConstants::STATUS_TESORERIA        => 'bg-warning text-dark',
    AdvanceConstants::STATUS_LEGALIZADA       => 'bg-success',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Anticipos</span>
    <?php if (!empty($userPermissions['advances']['can_create'])): ?>
    <div>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1"></i>Nuevo Anticipo',
            ['action' => 'add'],
            ['class' => 'sgi-btn-primary btn btn-sm', 'escape' => false]
        ) ?>
    </div>
    <?php endif; ?>
</div>

<div class="card card-primary">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Beneficiario</th>
                    <th>Centro Op.</th>
                    <th>Detalle</th>
                    <th class="text-end">Monto</th>
                    <th>Estado pago</th>
                    <th>Estado legalización</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($advances) || $advances->count() === 0): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No hay anticipos registrados.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($advances as $a): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['action' => 'view', $a->id]) ?>">
                    <td>
                        <span style="font-family:monospace;font-weight:600;font-size:.85rem;">
                            #<?= h($a->id) ?>
                        </span>
                    </td>
                    <td><?= h($a->provider->name ?? ($a->employee->full_name ?? '—')) ?></td>
                    <td><?= h($a->operation_center->name ?? '—') ?></td>
                    <td><?= h($a->detail) ?></td>
                    <td class="text-end" style="font-weight:600;">
                        $ <?= $this->Number->format((float)$a->amount, ['places' => 2]) ?>
                    </td>
                    <td>
                        <span class="badge <?= $pipelineBadge[$a->pipeline_status] ?? 'bg-dark' ?>">
                            <?= $pipelineLabels[$a->pipeline_status] ?? h($a->pipeline_status) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($a->advance_legalization): ?>
                            <span class="badge <?= $legalizationBadge[$a->advance_legalization->status] ?? 'bg-dark' ?>">
                                <?= h(AdvanceConstants::STATUS_LABELS[$a->advance_legalization->status] ?? $a->advance_legalization->status) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $this->element('pagination') ?>
</div>
