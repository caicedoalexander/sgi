<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\LeaveDocumentTemplate $template
 * @var array $availableFields
 */
$this->assign('title', 'Editor de Plantilla: ' . h($template->name));
$this->Html->script('leave-template-editor', ['block' => 'script']);

// Group fields by category
$grouped = [];
foreach ($availableFields as $key => $info) {
    $group = $info['group'] ?? 'Otros';
    $grouped[$group][$key] = $info;
}

$groupIcons = [
    'Empleado' => 'bi-person-badge',
    'Permiso' => 'bi-calendar-check',
    'Fechas' => 'bi-calendar-event',
    'Clasificación' => 'bi-tags',
    'Gestión' => 'bi-clipboard-check',
    'Firma' => 'bi-pen',
];
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editor: <?= h($template->name) ?></span>
    <div class="d-flex gap-2">
        <button type="button" id="btn-save-fields" class="btn btn-primary btn-sm">
            <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Campos
        </button>
        <?= $this->Html->link(
            '<i class="bi bi-eye me-1" aria-hidden="true"></i>Previsualizar',
            ['action' => 'preview', $template->id],
            ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false, 'target' => '_blank', 'id' => 'btn-preview']
        ) ?>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
            ['action' => 'index'],
            ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
        ) ?>
    </div>
</div>

<div class="template-editor-layout">
    <!-- Left Panel -->
    <div class="template-sidebar">
        <!-- Available Fields grouped -->
        <div class="template-sidebar-section">
            <div class="template-sidebar-heading">Campos Disponibles</div>
            <div id="available-fields-list">
                <?php foreach ($grouped as $groupName => $fields): ?>
                <div class="template-field-group">
                    <div class="template-field-group-title">
                        <i class="bi <?= $groupIcons[$groupName] ?? 'bi-tag' ?> me-1" aria-hidden="true"></i><?= h($groupName) ?>
                    </div>
                    <?php foreach ($fields as $key => $info): ?>
                    <div class="template-field-item" data-key="<?= h($key) ?>" data-label="<?= h($info['label']) ?>" data-type="<?= h($info['type']) ?>">
                        <button type="button" class="btn btn-sm btn-outline-dark w-100 text-start add-field-btn" title="Agregar <?= h($info['label']) ?>">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
                            <span class="flex-grow-1"><?= h($info['label']) ?></span>
                            <span class="pill pill-muted ms-1" style="font-size:.6rem;"><?= h($info['type']) ?></span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Properties Panel -->
        <div class="template-sidebar-section" id="field-properties-panel" style="display:none;">
            <div class="template-sidebar-heading">Propiedades del Campo</div>
            <div class="px-2 pb-2">
                <div class="mb-2">
                    <label class="form-label mb-1" style="font-size:.75rem;">Campo</label>
                    <input type="text" id="prop-field-key" class="form-control form-control-sm" readonly>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1" style="font-size:.75rem;">Etiqueta visible</label>
                    <input type="text" id="prop-label" class="form-control form-control-sm">
                </div>

                <div class="template-props-divider"></div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">X (mm)</label>
                        <input type="number" id="prop-x" class="form-control form-control-sm" step="0.5">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">Y (mm)</label>
                        <input type="number" id="prop-y" class="form-control form-control-sm" step="0.5">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">Ancho (mm)</label>
                        <input type="number" id="prop-width" class="form-control form-control-sm" step="0.5" placeholder="Auto">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">Alto (mm)</label>
                        <input type="number" id="prop-height" class="form-control form-control-sm" step="0.5" placeholder="Auto">
                    </div>
                </div>

                <div class="template-props-divider"></div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">Fuente (pt)</label>
                        <input type="number" id="prop-font-size" class="form-control form-control-sm" min="4" max="72" value="10">
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1" style="font-size:.75rem;">Estilo</label>
                        <select id="prop-font-style" class="form-select form-select-sm">
                            <option value="">Normal</option>
                            <option value="B">Negrita</option>
                            <option value="I">Itálica</option>
                            <option value="BI">Negrita + Itálica</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label mb-1" style="font-size:.75rem;">Alineación</label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                        <input type="radio" class="btn-check" name="prop-alignment" id="align-L" value="L" checked>
                        <label class="btn btn-outline-dark" for="align-L"><i class="bi bi-text-left" aria-hidden="true"></i></label>
                        <input type="radio" class="btn-check" name="prop-alignment" id="align-C" value="C">
                        <label class="btn btn-outline-dark" for="align-C"><i class="bi bi-text-center" aria-hidden="true"></i></label>
                        <input type="radio" class="btn-check" name="prop-alignment" id="align-R" value="R">
                        <label class="btn btn-outline-dark" for="align-R"><i class="bi bi-text-right" aria-hidden="true"></i></label>
                    </div>
                </div>

                <div class="template-props-divider"></div>

                <div class="mb-2">
                    <label class="form-label mb-1" style="font-size:.75rem;">Formato</label>
                    <input type="text" id="prop-format" class="form-control form-control-sm" placeholder="d/m/Y, X, etc.">
                    <div class="form-text" style="font-size:.65rem;">Fecha: d/m/Y | Check: X</div>
                </div>

                <button type="button" id="btn-remove-field" class="btn btn-sm btn-outline-danger w-100 mt-2">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Eliminar Campo
                </button>
            </div>
        </div>

        <!-- Info at bottom -->
        <div class="template-sidebar-section" style="padding:.5rem .75rem;font-size:.7rem;color:#999;">
            Página: <?= number_format((float)$template->page_width, 1) ?> x <?= number_format((float)$template->page_height, 1) ?> mm
            (<?= ($template->orientation ?? 'P') === 'L' ? 'Horizontal' : 'Vertical' ?>)
        </div>
    </div>

    <!-- Right Panel: Template Canvas -->
    <div class="template-canvas-wrapper">
        <div id="template-canvas"
             class="template-editor-container"
             data-page-width="<?= (float)$template->page_width ?>"
             data-page-height="<?= (float)$template->page_height ?>"
             data-orientation="<?= h($template->orientation ?? 'P') ?>"
             data-mime-type="<?= h($template->mime_type) ?>"
             data-save-url="<?= $this->Url->build(['action' => 'saveFields', $template->id]) ?>"
             data-fields="<?= h(json_encode($template->leave_template_fields)) ?>">
            <?php if ($template->mime_type === 'application/pdf'): ?>
            <object data="<?= $this->Url->build('/' . $template->file_path) ?>#toolbar=0&navpanes=0&scrollbar=0"
                    type="application/pdf"
                    id="template-bg-pdf"
                    style="width:100%;height:100%;pointer-events:none;position:absolute;top:0;left:0;">
            </object>
            <?php else: ?>
            <img src="<?= $this->Url->build('/' . $template->file_path) ?>"
                 alt="Plantilla"
                 id="template-bg-image"
                 draggable="false">
            <?php endif; ?>
        </div>
    </div>
</div>
