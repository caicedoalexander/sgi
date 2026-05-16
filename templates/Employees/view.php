<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Employee $employee
 * @var iterable $folders
 * @var \App\Model\Entity\User|null $currentUser
 */
$this->assign('title', 'Empleado: ' . $employee->full_name);

// Iniciales para avatar
$initials = mb_strtoupper(
    mb_substr($employee->first_name ?? '', 0, 1) .
    mb_substr($employee->last_name1  ?? '', 0, 1)
);

// Iconografía de documentos: ver $this->DocumentIcon (CR-019)

// Contar documentos totales
$totalDocs = 0;
foreach ($folders as $folder) {
    $totalDocs += count($folder->employee_documents);
    foreach ($folder->child_folders as $sf) {
        $totalDocs += count($sf->employee_documents);
    }
}
?>

<!-- Encabezado de página -->
<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Empleado</span>
    <div class="d-flex gap-2">
        <?= $this->Html->link('<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver', ['action' => 'index'], ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]) ?>
        <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
        <?= $this->Html->link('<i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar', ['action' => 'edit', $employee->id], ['class' => 'btn btn-warning btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
        <?php if (!empty($userPermissions['employees']['can_delete'])): ?>
        <?= $this->Form->postLink('<i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar', ['action' => 'delete', $employee->id], ['confirm' => '¿Está seguro de eliminar este empleado y todos sus documentos?', 'class' => 'btn btn-danger btn-sm', 'escape' => false]) ?>
        <?php endif; ?>
    </div>
</div>

<!-- Ficha del empleado -->
<div class="card card-primary mb-4">

    <!-- Encabezado: identidad -->
    <div class="d-flex align-items-start gap-3 p-4">
        <?php if ($employee->profile_image): ?>
            <img src="<?= $this->Url->build('/' . $employee->profile_image) ?>"
                 alt="<?= h($employee->full_name) ?>"
                 class="sgi-profile-avatar"
                 style="object-fit:cover;">
        <?php else: ?>
            <div class="sgi-profile-avatar" aria-hidden="true"><?= h($initials) ?></div>
        <?php endif; ?>
        <div style="min-width:0">
            <div class="sgi-profile-name"><?= h($employee->full_name) ?></div>
            <div class="sgi-profile-doc"><?= h($employee->identification?->formatted() ?? '') ?></div>
            <?php
            $sub = array_filter([
                $employee->has('position') ? h($employee->position->name) : null,
                $employee->has('operation_center') ? h($employee->operation_center->name) : null,
            ]);
            if ($sub): ?>
            <div class="sgi-profile-sub"><?= implode(' &middot; ', $sub) ?></div>
            <?php endif; ?>
            <div class="mt-2 d-flex gap-1 flex-wrap">
                <?php if (!empty($employee->status)): ?>
                    <span class="badge <?= $employee->isRetired() ? 'bg-danger' : 'bg-info' ?>">
                        <?= h(\App\Constants\EmployeeStatusConstants::STATUS_LABELS[$employee->status] ?? $employee->status) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($currentNovelty)): ?>
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-journal-text me-1" aria-hidden="true"></i><?= h($currentNovelty->novelty_type->name ?? '') ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cuerpo: dos columnas -->
    <div class="row g-0" style="border-top:1px solid var(--border-color)">

        <!-- Columna izquierda: información personal -->
        <div class="col-md-6" style="border-right:1px solid var(--border-color)">
            <div class="sgi-label">Información Personal</div>

            <?php if ($employee->birth_date): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Fecha Nacimiento</span>
                <span class="sgi-data-value">
                    <?= $employee->birth_date->format('d/m/Y') ?>
                    <?php if ($employee->age !== null): ?>
                        <span class="text-muted ms-1">(<?= $employee->age ?> años)</span>
                    <?php endif; ?>
                </span>
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
            <div class="sgi-label">Datos Laborales</div>

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

            <?php if ($employee->contract_type): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Tipo de Contrato</span>
                <span class="sgi-data-value"><?= h($employee->contract_type) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->has('temporary_organization') && $employee->temporary_organization): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">Organización Temporal</span>
                <span class="sgi-data-value"><?= h($employee->temporary_organization->name) ?><?= $employee->temporary_organization->nit ? ' <span class="text-muted">(' . h($employee->temporary_organization->nit) . ')</span>' : '' ?></span>
            </div>
            <?php endif; ?>

            <?php if ($employee->vest_number): ?>
            <div class="sgi-data-row">
                <span class="sgi-data-label">N. Chaleco</span>
                <span class="sgi-data-value"><?= h($employee->vest_number) ?></span>
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
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <?= h($employee->email) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->phone): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-telephone" aria-hidden="true"></i>
            <?= h($employee->phone) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->city): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <?= h($employee->city) ?>
        </div>
        <?php endif; ?>
        <?php if ($employee->address): ?>
        <div class="sgi-contact-item">
            <i class="bi bi-house" aria-hidden="true"></i>
            <?= h($employee->address) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Observaciones (chat) -->
    <?php
    $obsCount = count($employee->employee_observations ?? []);
    $currentUser = $this->getRequest()->getAttribute('identity');
    ?>
    <div class="sgi-obs-card" style="border-top:1px solid var(--border-color);display:flex;flex-direction:column;">
        <div class="sgi-label d-flex align-items-center gap-2">
            <span>Observaciones</span>
            <span id="obs-count" class="sgi-folder-count ms-auto" <?= $obsCount === 0 ? 'style="display:none;"' : '' ?>><?= $obsCount ?></span>
        </div>

        <div id="obs-chat-scroll" class="sgi-obs-list">
            <?php foreach ($employee->employee_observations ?? [] as $obs): ?>
                <?= $this->element('observation_bubble', [
                    'observation' => $obs,
                    'isMine' => $currentUser && $obs->user_id === $currentUser->id,
                ]) ?>
            <?php endforeach; ?>
        </div>

        <div id="obs-empty-state" class="sgi-obs-empty" <?= $obsCount > 0 ? 'hidden' : '' ?>>
            <i class="bi bi-chat-square-dots" style="font-size:1.75rem;" aria-hidden="true"></i>
            <span style="font-size:.78rem;">Sin observaciones aún</span>
        </div>

        <?php if (!empty($userPermissions['employees']['can_edit'])): ?>
        <div class="sgi-obs-input-bar">
            <?= $this->Form->create(null, ['url' => ['action' => 'addObservation', $employee->id], 'id' => 'obs-form']) ?>
            <div class="sgi-obs-compose">
                <textarea name="message" class="auto-resize" rows="1"
                          placeholder="Escriba una observación..."></textarea>
                <button type="submit" class="sgi-obs-compose-send" title="Enviar">
                    <i class="bi bi-send" aria-hidden="true"></i>
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Novedades -->
<?php
use App\Constants\NoveltyConstants;
$statusBadges = [
    'pendiente' => 'bg-warning text-dark',
    'aprobado' => 'bg-success',
    'rechazado' => 'bg-danger',
];
$scheduleLabels = NoveltyConstants::SCHEDULE_LABELS;
$novedades = $employee->employee_novelties ?? [];
?>
<div class="card card-primary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-journal-text" aria-hidden="true"></i>
            Novedades
        </span>
        <?php if (!empty($userPermissions['employee_novelties']['can_create'])): ?>
        <?= $this->Html->link(
            '<i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva Novedad',
            ['controller' => 'EmployeeNovelties', 'action' => 'add', '?' => ['employee_id' => $employee->id]],
            ['class' => 'btn btn-sm btn-primary', 'escape' => false]
        ) ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($novedades)): ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Tipo</th>
                    <th>Fecha Permiso</th>
                    <th>Horario</th>
                    <th>Remunerado</th>
                    <th>Estado</th>
                    <th>Registrado por</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($novedades as $nov): ?>
                <tr class="clickable-row" data-href="<?= $this->Url->build(['controller' => 'EmployeeNovelties', 'action' => 'edit', $nov->id]) ?>">
                    <td><?= h($nov->novelty_type->name ?? '—') ?></td>
                    <td><?= $nov->permission_date?->format('d/m/Y') ?: '—' ?></td>
                    <td><?= $scheduleLabels[$nov->schedule_type] ?? h($nov->schedule_type) ?></td>
                    <td>
                        <?php if ($nov->is_paid): ?>
                            <span class="badge bg-success">Sí</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $statusBadges[$nov->status] ?? 'bg-secondary' ?>"><?= h(ucfirst($nov->status)) ?></span></td>
                    <td style="font-size:.8rem"><?= h($nov->registered_by_user->full_name ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="p-3 text-center text-muted" style="font-size:.875rem">
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Sin novedades registradas
    </div>
    <?php endif; ?>
</div>

<!-- Gestión Documental -->
<div class="card card-primary mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-folder2-open" aria-hidden="true"></i>
            Gestión Documental
            <span class="sgi-folder-count"><?= $totalDocs ?> doc<?= $totalDocs !== 1 ? 's' : '' ?></span>
        </span>
        <?php if (!empty($userPermissions['employees']['can_create'])): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#newFolderModal">
                <i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Nueva Carpeta
            </button>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir Documento
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($folders->isEmpty()): ?>
        <div class="sgi-doc-empty">
            <i class="bi bi-folder-x sgi-doc-empty-icon" aria-hidden="true"></i>
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
                        <i class="bi bi-folder-fill me-2" style="color:#f6a623;font-size:1rem" aria-hidden="true"></i>
                        <span><?= h($folder->name) ?></span>
                        <span class="sgi-folder-count ms-2"><?= count($folder->employee_documents) ?></span>
                    </button>
                </h2>
                <div id="folder-<?= $folder->id ?>"
                     class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                     data-bs-parent="#foldersAccordion">
                    <div class="accordion-body">

                        <?php if (!empty($folder->employee_documents)): ?>
                        <?= $this->element('employee_documents_table', [
                            'docs'       => $folder->employee_documents,
                            'employeeId' => $employee->id,
                            'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                            'showHeader' => true,
                        ]) ?>
                        <?php else: ?>
                        <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Carpeta vacía</div>
                        <?php endif; ?>

                        <?php if (!empty($folder->child_folders)): ?>
                        <div class="sgi-subfolder-section">
                            <?php foreach ($folder->child_folders as $subfolder): ?>
                            <div class="sgi-subfolder-item">
                                <div class="sgi-subfolder-header">
                                    <i class="bi bi-folder-fill" style="color:#f6a623" aria-hidden="true"></i>
                                    <?= h($subfolder->name) ?>
                                    <span class="sgi-folder-count ms-1"><?= count($subfolder->employee_documents) ?></span>
                                </div>
                                <?php if (!empty($subfolder->employee_documents)): ?>
                                <?= $this->element('employee_documents_table', [
                                    'docs'       => $subfolder->employee_documents,
                                    'employeeId' => $employee->id,
                                    'canDelete'  => !empty($userPermissions['employees']['can_delete']),
                                    'showHeader' => false,
                                ]) ?>
                                <?php else: ?>
                                <div class="sgi-doc-empty-sm"><i class="bi bi-file-earmark me-1" aria-hidden="true"></i>Subcarpeta vacía</div>
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

<!-- Historial de Cambios -->
<?php if (!empty($employee->employee_histories)): ?>
<div class="card card-primary mb-4">
    <div class="card-header">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            Historial de Cambios
        </span>
    </div>
    <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Campo</th>
                    <th>Valor Anterior</th>
                    <th>Valor Nuevo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employee->employee_histories as $history): ?>
                <tr>
                    <td style="font-size:.8rem;white-space:nowrap;"><?= $history->created ? $history->created->format('d/m/Y H:i') : '' ?></td>
                    <td style="font-size:.8rem;"><?= $history->hasValue('user') ? h($history->user->full_name) : '' ?></td>
                    <td style="font-size:.8rem;"><?= h($fieldLabels[$history->field_changed] ?? $history->field_changed) ?></td>
                    <td style="font-size:.8rem;" class="text-muted"><?= h($history->old_value) ?: '—' ?></td>
                    <td style="font-size:.8rem;" class="fw-semibold"><?= h($history->new_value) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Nueva Carpeta -->
<div class="modal fade" id="newFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= $this->Form->create(null, ['url' => ['action' => 'addFolder', $employee->id]]) ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder-plus me-2" aria-hidden="true"></i>Nueva Carpeta</h5>
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
                <button type="submit" class="btn btn-primary"><i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Crear Carpeta</button>
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
                <h5 class="modal-title"><i class="bi bi-upload me-2" aria-hidden="true"></i>Subir Documento</h5>
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
                    <div class="form-text">Máximo <?= h(\App\Constants\UploadConstants::MAX_BYTES_LABEL) ?> — PDF, imágenes, Word, Excel o texto.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Subir</button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?= $this->element('observation_chat_init') ?>
