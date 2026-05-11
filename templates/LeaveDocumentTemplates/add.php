<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LeaveDocumentTemplate $template
 */
$this->assign('title', 'Nueva Plantilla de Documento');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Nueva Plantilla de Documento</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body">
        <?= $this->Form->create($template, ['type' => 'file']) ?>

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                <?= $this->Form->control('name', [
                    'label' => false,
                    'class' => 'form-control',
                    'placeholder' => 'Ej: Formato Solicitud de Permiso',
                    'required' => true,
                ]) ?>
            </div>

            <div class="col-md-4">
                <label class="form-label">Orientación</label>
                <select name="orientation" class="form-select">
                    <option value="P">Vertical (Retrato)</option>
                    <option value="L">Horizontal (Paisaje)</option>
                </select>
                <div class="form-text">El tamaño se detecta automáticamente del archivo.</div>
            </div>

            <div class="col-12">
                <label class="form-label">Descripción</label>
                <?= $this->Form->control('description', [
                    'label' => false,
                    'type' => 'textarea',
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Descripción opcional de esta plantilla...',
                ]) ?>
            </div>

            <div class="col-md-8">
                <label class="form-label">Archivo de Plantilla <span class="text-danger">*</span></label>
                <input type="file" name="template_file" class="form-control" accept="image/png,image/jpeg,application/pdf" required>
                <div class="form-text">Suba un archivo PNG, JPEG o PDF del formulario (máx. 5MB). Se usará como fondo del PDF generado.</div>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <?= $this->Form->checkbox('is_active', [
                        'class' => 'form-check-input',
                        'id' => 'is_active',
                        'checked' => true,
                    ]) ?>
                    <label class="form-check-label" for="is_active">Plantilla activa</label>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4 pt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar y Configurar Campos
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
