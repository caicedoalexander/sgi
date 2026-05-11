<?php
/**
 * @var \App\View\AppView $this
 * @var \App\ViewModel\PaymentSchedulingAddViewModel $viewModel
 */
$this->assign('title', 'Nueva Programación');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Programación</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false]
    ) ?>
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
            <button type="submit" class="sgi-btn-primary btn">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Crear Programación
            </button>
        </div>
        <?= $this->Form->end() ?>
    </div>
</div>
