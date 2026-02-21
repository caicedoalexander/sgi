<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var iterable $folders
 */
$this->assign('title', 'Empleado: ' . $employee->full_name);

// Iniciales para avatar
$initials = mb_strtoupper(
    mb_substr($employee->first_name ?? '', 0, 1) .
    mb_substr($employee->last_name  ?? '', 0, 1)
);

// Helpers para tipo de documento
$docIcon = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
    str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
    str_contains($mime ?? '', 'text/plain') => 'bi-file-earmark-text',
    default => 'bi-file-earmark',
};
$docIconColor = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => '#dc3545',
    str_contains($mime ?? '', 'image') => '#0dcaf0',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => '#0d6efd',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
    default => '#aaa',
};
$docType = fn(?string $mime): string => match(true) {
    str_contains($mime ?? '', 'pdf') => 'PDF',
    str_contains($mime ?? '', 'image/jpeg') || str_contains($mime ?? '', 'image/jpg') => 'JPG',
    str_contains($mime ?? '', 'image/png') => 'PNG',
    str_contains($mime ?? '', 'image') => 'IMG',
    str_contains($mime ?? '', 'wordprocessingml') || str_contains($mime ?? '', 'msword') => 'WORD',
    str_contains($mime ?? '', 'spreadsheet') || str_contains($mime ?? '', 'excel') => 'EXCEL',
    str_contains($mime ?? '', 'text/plain') => 'TXT',
    default => 'ARCH.',
};
$docBadgeClass = fn(string $type): string => match($type) {
    'PDF' => 'badge-outline-danger',
    'JPG', 'PNG', 'IMG' => 'badge-outline-info',
    'WORD' => 'badge-outline-dark',
    'EXCEL' => 'badge-outline-success',
    default => 'badge-outline-dark',
};

// Contar documentos totales
$totalDocs = 0;
foreach ($folders as $folder) {
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        $totalDocs += count($sf->employee_documents);
    }
}
?>

<!-- Barra de acciones -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <?= $this->Html->link('<i class="bi bi-arrow-left me-1"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
    <div class="d-flex gap-2">
        <?= $this->Html->link('<i class="bi bi-pencil me-1"></i>Editar', ['action' => 'edit', $employee->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1"></i>Eliminar', ['action' => 'delete', $employee->id], ['confirm' => '¿Está seguro de eliminar este empleado y todos sus documentos?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
    </div>
</div>

<!-- Ficha del empleado -->
<div class="card card-primary mb-4">

    <!-- Encabezado: identidad -->
    <div class="d-flex align-items-start gap-3 p-4">
        <div class="sgi-profile-avatar"><?= h($initials) ?></div>
        <div style="min-width:0">
            <div class="sgi-profile-name"><?= h($employee->full_name) ?></div>
            <div class="sgi-profile-doc"><?= h($employee->document_type) ?> · <?= h($employee->document_number) ?></div>
            <?php
            $sub = array_filter([
                $employee->has('position') ? h($employee->position->name) : null,
                $employee->has('operation_center') ? h($employee->operation_center->name) : null,
            ]);
            if ($sub): ?>
            <div class="sgi-profile-sub"><?= implode(' &middot; ', $sub) ?></div>
            <?php endif; ?>
            <?php if ($employee->has('employee_status') && $employee->employee_status): ?>
            <div class="mt-2">
                <span class="badge bg-info"><?= h($employee->employee_status->name) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cuerpo: dos columnas -->
    <div class="row g-0" style="border-top:1px solid var(--border-color)">

        <!-- Columna izquierda: información personal -->
        <div class="col-md-6" style="border-right:1px solid var(--border-color)">
            <div class="sgi-section-title">Información Personal</div>

            <?php if ($employee->birth_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Nacimiento</span>
                <span class="sgi-data-value"><?= $employee->birth_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->gender): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Género</span>
                <span class="sgi-data-value"><?= h($employee->gender) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->has('marital_status') && $employee->marital_status): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Estado Civil</span>
                <span class="sgi-data-value"><?= h($employee->marital_status->name) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->has('education_level') && $employee->education_level): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Nivel Educativo</span>
                <span class="sgi-data-value"><?= h($employee->education_level->name) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna derecha: datos laborales -->
        <div class="col-md-6">
            <div class="sgi-section-title">Datos Laborales</div>

            <?php if ($employee->has('position') && $employee->position): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Cargo</span>
                <span class="sgi-data-value"><?= h($employee->position->name) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->has('supervisor_position') && $employee->supervisor_position): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Jefe Inmediato</span>
                <span class="sgi-data-value"><?= h($employee->supervisor_position->name) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->has('cost_center') && $employee->cost_center): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Centro de Costos</span>
                <span class="sgi-data-value"><?= h($employee->cost_center->name) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->hire_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Ingreso</span>
                <span class="sgi-data-value"><?= $employee->hire_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->termination_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Retiro</span>
                <span class="sgi-data-value"><?= $employee->termination_date->format('d/m/Y') ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->salary): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Salario</span>
                <span class="sgi-data-value">$ <?= $this->Number->format($employee->salary, ['places' => 0]) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Barra de contacto -->
    <?php $hasContact = $employee->email || $employee->phone || $employee->city || $employee->address; ?>
    <?php if ($hasContact): ?>
    <div class="sgi-contact-bar">
        <?php if ($employee->email): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-envelope"></i>
            <?= h($employee->email) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->phone): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-telephone"></i>
            <?= h($employee->phone) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->city): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-geo-alt"></i>
            <?= h($employee->city) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->address): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-house"></i>
            <?= h($employee->address) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Observaciones -->
    <?php if ($employee->notes): ?>
    <div style="border-top:1px solid var(--border-color);padding:1rem 1.25rem">
        <div class="sgi-section-title" style="padding:0 0 .5rem">Observaciones</div>
        <p class="mb-0" style="font-size:.8125rem;color:#555;line-height:1.6"><?= nl2br(h($employee->notes)) ?></p>
    </div>
    <?php endif; ?>

</div>

<!-- Gestión Documental -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-folder2-open"></i>
            Gestión Documental
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#newFolderModal">
                <i class="bi bi-folder-plus me-1"></i>Nueva Carpeta
            </button>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                <i class="bi bi-upload me-1"></i>Subir Documento
            </button>
        </div>
    </div>

    <?php if ($folders->isEmpty()): ?>
        <div class="sgi-doc-empty">
            <i class="bi bi-folder-x sgi-doc-empty-icon"></i>
            <div style="font-size:.875rem;font-weight:500;color:#999">Sin carpetas ni documentos</div>
            <div style="font-size:.8rem;margin-top:.3rem">Crea una carpeta o sube un documento para comenzar</div>
        </div>
    <?php else: ?>
        <div class="accordion accordion-flush" id="foldersAccordion">
            <?php foreach ($folders as $i => $folder): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#folder-<?= $folder->id ?>">
                        <i class="bi bi-folder-fill me-2" style="color:#f6a623;font-size:1rem"></i>
                        <span><?= h($folder->name) ?></span>
                        <span class="sgi-folder-count ms-2"><?= count($folder->employee_documents) ?></span>
                    </button>
                </h2>
                <div id="folder-<?= $folder->id ?>"
                     class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                     data-bs-parent="#foldersAccordion">
                    <div class="accordion-body">

                        <?php if (!empty($folder->employee_documents)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Documento</th>
                                        <th>Tipo</th>
                                        <th>Tamaño</th>
                                        <th>Subido por</th>
                                        <th>Fecha</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($folder->employee_documents as $doc):
                                        $type = $docType($doc->mime_type);
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="bi <?= $docIcon($doc->mime_type) ?> me-1"
                                               style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1rem;vertical-align:middle"></i>
                                            <?= $this->Html->link(h($doc->name), '/' . $doc->file_path, ['target' => '_blank', 'class' => 'text-decoration-none']) ?>
                                        </td>
                                        <td><span class="badge <?= $docBadgeClass($type) ?>"><?= $type ?></span></td>
                                        <td style="color:#888;font-size:.8rem"><?= $doc->file_size ? $this->Number->toReadableSize($doc->file_size) : '—' ?></td>
                                        <td style="color:#888;font-size:.8rem"><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></td>
                                        <td style="color:#888;font-size:.8rem">
                                            <?= $doc->created?->format('d/m/Y') ?>
                                            <span style="color:#bbb;font-size:.7rem;display:block"><?= $doc->created?->format('H:i') ?></span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <?= $this->Html->link('<i class="bi bi-box-arrow-up-right"></i>', '/' . $doc->file_path, ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']) ?>
                                                <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'deleteDocument', $employee->id, $doc->id], ['confirm' => '¿Eliminar este documento?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1"></i>Carpeta vacía</div>
                        <?php endif; ?>

                        <?php if (!empty($folder->child_folders)): ?>
                        <div class="sgi-subfolder-section">
                            <?php foreach ($folder->child_folders as $subfolder): ?>
                            <div class="sgi-subfolder-item">
                                <div class="sgi-subfolder-header">
                                    <i class="bi bi-folder-fill" style="color:#f6a623"></i>
                                    <?= h($subfolder->name) ?>
                                    <span class="sgi-folder-count ms-1"><?= count($subfolder->employee_documents) ?></span>
                                </div>
                                <?php if (!empty($subfolder->employee_documents)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <tbody>
                                            <?php foreach ($subfolder->employee_documents as $doc):
                                                $type = $docType($doc->mime_type);
                                            ?>
                                            <tr>
                                                <td>
                                                    <i class="bi <?= $docIcon($doc->mime_type) ?> me-1"
                                                       style="color:<?= $docIconColor($doc->mime_type) ?>;font-size:1rem;vertical-align:middle"></i>
                                                    <?= $this->Html->link(h($doc->name), '/' . $doc->file_path, ['target' => '_blank', 'class' => 'text-decoration-none']) ?>
                                                </td>
                                                <td><span class="badge <?= $docBadgeClass($type) ?>"><?= $type ?></span></td>
                                                <td style="color:#888;font-size:.8rem"><?= $doc->file_size ? $this->Number->toReadableSize($doc->file_size) : '—' ?></td>
                                                <td style="color:#888;font-size:.8rem"><?= $doc->has('uploaded_by_user') ? h($doc->uploaded_by_user->full_name) : '—' ?></td>
                                                <td style="color:#888;font-size:.8rem"><?= $doc->created?->format('d/m/Y') ?></td>
                                                <td class="text-end">
                                                    <div class="d-flex gap-1 justify-content-end">
                                                        <?= $this->Html->link('<i class="bi bi-box-arrow-up-right"></i>', '/' . $doc->file_path, ['class' => 'btn btn-sm btn-outline-primary', 'escape' => false, 'target' => '_blank', 'title' => 'Abrir']) ?>
                                                        <?= $this->Form->postLink('<i class="bi bi-trash"></i>', ['action' => 'deleteDocument', $employee->id, $doc->id], ['confirm' => '¿Eliminar este documento?', 'class' => 'btn btn-sm btn-outline-danger', 'escape' => false, 'title' => 'Eliminar']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1"></i>Subcarpeta vacía</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Nueva Carpeta -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'addFolder', $employee->id]]) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Nueva Carpeta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?= $this->Form->control('name', ['class' => 'form-control', 'label' => ['text' => 'Nombre de la Carpeta', 'class' => 'form-label'], 'required' => true, 'placeholder' => 'Ej. Contratos, Certificados...']) ?>
                </div>
                <div class="mb-3">
                    <?php
                    $folderOptions = [];
                    foreach ($folders as $f) { $folderOptions[$f->id] = $f->name; }
                    ?>
                    <?= $this->Form->control('parent_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta Padre (opcional)', 'class' => 'form-label'], 'options' => $folderOptions, 'empty' => '— Raíz —']) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-folder-plus me-1"></i>Crear Carpeta</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<!-- Modal: Subir Documento -->
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'uploadDocument', $employee->id], 'type' => 'file']) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Subir Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <?php
                    $allFolderOptions = [];
                    foreach ($folders as $f) {
                        $allFolderOptions[$f->id] = $f->name;
                        foreach ($f->child_folders as $cf) {
                            $allFolderOptions[$cf->id] = '— ' . $cf->name;
                        }
                    }
                    ?>
                    <?= $this->Form->control('employee_folder_id', ['class' => 'form-select', 'label' => ['text' => 'Carpeta de Destino', 'class' => 'form-label'], 'options' => $allFolderOptions, 'required' => true, 'id' => 'upload-folder-select']) ?>
                </div>
                <div class="mb-3">
                    <?= $this->Form->control('file', ['type' => 'file', 'class' => 'form-control', 'label' => ['text' => 'Archivo', 'class' => 'form-label'], 'required' => true, 'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt']) ?>
                    <div class="form-text">Máximo 10 MB — PDF, imágenes, Word, Excel o texto.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
