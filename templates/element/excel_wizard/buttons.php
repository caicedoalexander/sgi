<?php
/**
 * Excel wizard buttons (Exportar / Importar).
 *
 * @var \App\View\AppView $this
 * @var string $module      Camel-cased module name, e.g. 'Employees'
 * @var bool   $importable  Show Import button when true
 * @var bool   $canCreate   User has can_create on the module (for Import visibility)
 */
$importable = $importable ?? true;
$canCreate = $canCreate ?? false;
?>
<button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#exportExcelModal">
    <i class="bi bi-upload me-1" aria-hidden="true"></i>Exportar
</button>
<?php if ($importable && $canCreate): ?>
<button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
    <i class="bi bi-download me-1" aria-hidden="true"></i>Importar
</button>
<?php endif; ?>
