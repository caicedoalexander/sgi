<?php
/**
 * @var \App\View\AppView $this
 * @var string $token
 * @var \App\Model\Entity\AdvanceLegalization $leg
 * @var \App\Model\Entity\Invoice $anticipo
 * @var iterable<\App\Model\Entity\Invoice> $linkedInvoices
 * @var object $currentUser
 */

use App\View\Presentation\InvoiceBeneficiary;

$this->assign('title', 'Revisión de Aprobación de Grupo');

$linkedInvoicesList = is_array($linkedInvoices) ? $linkedInvoices : iterator_to_array($linkedInvoices);
$linkedTotal = array_sum(array_map(fn($inv) => (float)$inv->amount, $linkedInvoicesList));

// Soportes agrupados: primero el anticipo padre, luego un grupo por factura vinculada (incluso sin soportes).
$buildRows = static function ($invoice): array {
    $rows = [];
    foreach ($invoice->invoice_documents ?? [] as $doc) {
        $rows[] = ['doc' => $doc, 'showBadge' => false];
    }

    return $rows;
};
$groups = [];
$totalDocs = 0;
$anticipoRows = $buildRows($anticipo);
$totalDocs += count($anticipoRows);
$groups[] = [
    'label' => $anticipo->invoice_number ?? '#' . $anticipo->id,
    'pillKind' => 'pill-primary-soft',
    'rows' => $anticipoRows,
];
foreach ($linkedInvoicesList as $inv) {
    $rows = $buildRows($inv);
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
        <div class="spi-body-faint">Legalización de Anticipo — revise las facturas incluidas antes de decidir.</div>
    </div>

    <div>
        <div class="spi-label">Legalización de Anticipo</div>
        <div class="field-row">
            <span class="k">Código</span>
            <span class="v mono"><?= h($anticipo->invoice_number ?? '#' . $anticipo->id) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Beneficiario</span>
            <span class="v"><?= h(InvoiceBeneficiary::label($anticipo)) ?></span>
        </div>
        <div class="field-row">
            <span class="k">Monto del anticipo</span>
            <span class="v mono spi-fg-primary">
                $ <?= number_format((float)$anticipo->amount, 0, ',', '.') ?>
            </span>
        </div>
    </div>

    <div>
        <div class="spi-label">Facturas de la Legalización</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th># Factura</th>
                        <th>Beneficiario</th>
                        <th class="text-end">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($linkedInvoicesList)) : ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted">Sin facturas vinculadas</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($linkedInvoicesList as $inv) : ?>
                    <tr>
                        <td class="mono" style="font-weight:600;"><?= h($inv->invoice_number ?? '#' . $inv->id) ?></td>
                        <td><?= h(InvoiceBeneficiary::label($inv)) ?></td>
                        <td class="text-end mono">$ <?= number_format((float)$inv->amount, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($linkedInvoicesList)) : ?>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-end">Total vinculado</th>
                        <th class="text-end mono">$ <?= number_format($linkedTotal, 0, ',', '.') ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
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
