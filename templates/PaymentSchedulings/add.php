<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\PaymentSchedulingAddViewModel $viewModel
 */
$this->assign('title', 'Nueva Programación');
?>

<div class="spi-page-header d-flex justify-content-between align-items-start">
    <div style="min-width:0;">
        <div class="spi-breadcrumb">
            <?= $this->Html->link('Programación de Pagos', ['action' => 'index']) ?>
            <i class="bi bi-chevron-right" aria-hidden="true" style="font-size:var(--fs-meta);"></i>
            <span class="current">Nueva</span>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
            <span class="spi-page-title">Nueva Programación</span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-ghost-card', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="card card-primary">
    <div class="card-body">
        <?= $this->Form->create($viewModel->record) ?>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Título <span class="text-danger">*</span></label>
                <?= $this->Form->control('title', [
                    'label' => false,
                    'class' => 'form-control',
                    'placeholder' => 'Ej: Programación pagos Abril 2026',
                    'required' => true,
                ]) ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Código</label>
                <input type="text" class="form-control" disabled value="Se genera automáticamente">
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn-primary btn">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear Programación
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
