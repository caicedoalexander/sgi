<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Subir Archivo Cruce DIAN');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Subir Archivo Cruce DIAN</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
        <?= $this->Form->create(null, ['type' => 'file']) ?>

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Archivo Excel (.xls, .xlsx)</label>
                <input type="file" name="excel_file" id="excel_file" class="form-control"
                       accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                <div class="form-text">Tamaño máximo: 10 MB</div>
            </div>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary" id="btn-upload">
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Enviar para Cruce
            </button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>

<script>
document.getElementById('excel_file').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var validExts = ['.xls', '.xlsx'];
    var ext = file.name.substring(file.name.lastIndexOf('.')).toLowerCase();
    if (validExts.indexOf(ext) === -1) {
        alert('Solo se permiten archivos Excel (.xls, .xlsx)');
        this.value = '';
    }
    if (file.size > 10 * 1024 * 1024) {
        alert('El archivo no debe superar los 10 MB.');
        this.value = '';
    }
});
</script>
