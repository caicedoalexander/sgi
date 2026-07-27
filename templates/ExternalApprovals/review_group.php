<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var \App\Model\Entity\Refund $refund
 * @var object $currentUser
 */
$this->assign('title', 'Revisión de Aprobación de Grupo');

// Soportes agrupados: un grupo por cada factura del reintegro (incluso sin soportes).
$groups = [];
$totalDocs = 0;
foreach ($refund->invoices as $inv) {
    $rows = [];
    foreach ($inv->invoice_documents ?? [] as $doc) {
        $rows[] = ['doc' => $doc, 'showBadge' => false];
    }
    $totalDocs += count($rows);
    $groups[] = [
        'label' => $inv->invoice_number ?? '#' . $inv->id,
        'pillKind' => 'pill-info-soft',
        'rows' => $rows,
    ];
}
?>

<div class="mb-3">
    <span class="pill pill-info-soft">
        <i class="bi bi-person-check" aria-hidden="true"></i>
        Aprobando como: <strong><?= h($currentUser->full_name) ?></strong>
    </span>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <div>
        <div class="spi-title-card"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Solicitud de Aprobación de Grupo</div>
        <div class="spi-body-faint">Reintegro agrupado — revise las facturas incluidas antes de decidir.</div>
    </div>

    <div>
        <div class="spi-label">Reintegro</div>
        <div class="field-row">
            <span class="k">Código</span>
            <span class="v mono"><?= h($refund->code ?? '#' . $refund->id) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Beneficiario</span>
            <span class="v"><?= h($refund->getBeneficiaryName() ?? '—') ?></span>
        </div>
        <div class="field-row">
            <span class="k">Total</span>
            <span class="v mono spi-fg-primary">
                $ <?= number_format((float)$refund->total_amount, 0, ',', '.') ?>
            </span>
        </div>
    </div>

    <div>
        <div class="spi-label">Facturas del Reintegro</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Proveedor</th>
                        <th class="text-end">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refund->invoices as $inv): ?>
                    <tr>
                        <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                        <td><?= $inv->hasValue('provider') ? h($inv->provider->name) : '—' ?></td>
                        <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mb-4">
    <?= $this->element('documents_section', [
        'groups' => $groups,
        'totalDocs' => $totalDocs,
        'canUpload' => false,
        'emptyTitle' => 'Sin soportes adjuntos',
    ]) ?>
</div>

<div class="spi-card mb-4 d-flex flex-column gap-3">
    <?= $this->Form->create(null, ['url' => ['action' => 'process', $token]]) ?>
    <div class="mb-3">
        <label class="input-label">Observaciones (opcional)</label>
        <textarea name="observations" class="form-control" rows="3"></textarea>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" name="action" value="approve" class="btn btn-primary">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar grupo
        </button>
        <button type="submit" name="action" value="reject" class="btn btn-danger">
            <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar grupo
        </button>
    </div>
    <?= $this->Form->end() ?>
</div>
