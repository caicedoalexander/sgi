<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Provider $provider
 */

use App\Constants\InvoiceConstants;
use App\View\Presentation\InvoicePresentation;

$this->assign('title', 'Proveedor: ' . $provider->name);
?>
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Detalle del Proveedor</span>
    <div class="d-flex gap-2">
        <?php if (!empty($userPermissions['providers']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar',
            ['action' => 'edit', $provider->id],
            ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['providers']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar',
            ['action' => 'delete', $provider->id],
            ['confirm' => '¿Está seguro?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]) ?>
    </div>
</div>

<div class="sgi-card mb-4">
    <div class="field-row">
        <span class="k">ID</span>
        <span class="v mono"><?= $this->Number->format($provider->id) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Tipo Documento</span>
        <span class="v"><span class="pill pill-secondary-soft"><?= h($provider->document_type) ?></span></span>
    </div>
    <div class="field-row">
        <span class="k">Número Documento</span>
        <span class="v mono"><?= h($provider->document_number) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Nombre</span>
        <span class="v"><?= h($provider->name) ?></span>
    </div>
    <div class="field-row">
        <span class="k">Estado</span>
        <span class="v"><?= $provider->active ? '<span class="pill pill-primary-soft">Activo</span>' : '<span class="pill pill-secondary-soft">Inactivo</span>' ?></span>
    </div>
    <div class="field-row">
        <span class="k">Creado</span>
        <span class="v mono"><?= $provider->created?->format('d/m/Y H:i') ?></span>
    </div>
    <div class="field-row is-last">
        <span class="k">Modificado</span>
        <span class="v mono"><?= $provider->modified?->format('d/m/Y H:i') ?></span>
    </div>
</div>

<?php if (!empty($provider->invoices)): ?>
<div class="sgi-card" style="padding:0;">
    <div style="padding:16px 20px 12px;font-weight:600;color:var(--text-strong);border-bottom:1px solid var(--rule);">
        Facturas del Proveedor
    </div>
    <div class="row-fact head" style="grid-template-columns:80px 1fr 130px 150px 150px;" role="row">
        <span>#</span>
        <span>Tipo</span>
        <span>Fecha Emisión</span>
        <span style="text-align:right;">Valor</span>
        <span>Estado</span>
    </div>
    <?php foreach ($provider->invoices as $invoice): ?>
    <div class="row-fact" style="grid-template-columns:80px 1fr 130px 150px 150px;" role="row">
        <span class="mono"><?= $this->Html->link($invoice->id, ['controller' => 'Invoices', 'action' => 'view', $invoice->id]) ?></span>
        <span><?= h($invoice->document_type) ?></span>
        <span class="mono"><?= $invoice->issue_date?->format('d/m/Y') ?></span>
        <span class="mono" style="text-align:right;">$ <?= $this->Number->format($invoice->amount, ['places' => 2]) ?></span>
        <span><span class="pill <?= InvoicePresentation::STATUS_BADGES[$invoice->pipeline_status] ?? 'pill-muted' ?>"><?= h(InvoiceConstants::STATUS_LABELS[$invoice->pipeline_status] ?? $invoice->pipeline_status) ?></span></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
