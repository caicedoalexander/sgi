<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NoveltyType $noveltyType
 * @var array $parentTypes
 * @var array $documentTemplates
 * @var array $temporaryOrganizations
 * @var array $contractTypes
 */
$this->assign('title', 'Editar Tipo de Novedad');
?>

<div class="sgi-page-header d-flex justify-content-between align-items-center">
    <span class="sgi-page-title">Editar Tipo de Novedad</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="sgi-card">
    <?= $this->Form->create($noveltyType) ?>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Nombre</label>
            <?= $this->Form->control('name', ['label' => false, 'class' => 'form-control']) ?>
        </div>
        <div class="col-md-4">
            <label class="form-label">Tipo Padre (opcional)</label>
            <?= $this->Form->control('parent_id', [
                'label' => false,
                'options' => $parentTypes,
                'empty' => '— Ninguno (tipo principal) —',
                'class' => 'form-select',
            ]) ?>
        </div>
    </div>

    <!-- Pipeline Configuration -->
    <div class="mt-4 pt-3" style="border-top:1px solid var(--border-color);">
        <label class="sgi-section-label">Configuración del Pipeline</label>
        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <p class="text-muted small mb-2">Configuración de aprobación y firmas</p>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('requires_boss_approval', ['class' => 'form-check-input', 'id' => 'requires-boss-approval']) ?>
                    <label class="form-check-label" for="requires-boss-approval">Requiere aprobación del jefe inmediato</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('requires_employee_signature_creation', ['class' => 'form-check-input', 'id' => 'requires-sig-creation']) ?>
                    <label class="form-check-label" for="requires-sig-creation">Requiere firma del empleado al crear</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('requires_employee_signature_review', ['class' => 'form-check-input', 'id' => 'requires-sig-review']) ?>
                    <label class="form-check-label" for="requires-sig-review">Requiere firma del empleado en revisión de documentos</label>
                </div>
            </div>
            <div class="col-md-6">
                <p class="text-muted small mb-2">Campos visibles en formulario</p>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('show_start_date', ['class' => 'form-check-input', 'id' => 'show-start-date']) ?>
                    <label class="form-check-label" for="show-start-date">Mostrar Fecha Inicio</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('show_end_date', ['class' => 'form-check-input', 'id' => 'show-end-date']) ?>
                    <label class="form-check-label" for="show-end-date">Mostrar Fecha Fin</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('show_permission_date', ['class' => 'form-check-input', 'id' => 'show-permission-date']) ?>
                    <label class="form-check-label" for="show-permission-date">Mostrar Fecha de Permiso</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('show_schedule_type', ['class' => 'form-check-input', 'id' => 'show-schedule-type']) ?>
                    <label class="form-check-label" for="show-schedule-type">Mostrar Tipo de Horario</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('uses_custom_name', ['class' => 'form-check-input', 'id' => 'uses-custom-name']) ?>
                    <label class="form-check-label" for="uses-custom-name">Usa Nombre Libre (en vez de select de empleado)</label>
                </div>
                <div class="form-check mb-2">
                    <?= $this->Form->checkbox('is_massive', ['class' => 'form-check-input', 'id' => 'is-massive']) ?>
                    <label class="form-check-label" for="is-massive">Novedad Masiva (multi-selección de empleados)</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Contract Template Assignments (only for parent types) -->
    <div class="mt-4 pt-3" style="border-top:1px solid var(--border-color);" id="contract-templates-section">
        <label class="sgi-section-label">Asignación de plantillas por tipo de contrato</label>
        <p class="text-muted small mb-2" id="subtype-templates-notice" style="display:none;">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Los subtipos heredan las plantillas asignadas al tipo padre.
        </p>
        <div id="contract-templates-fields">
            <table class="table table-sm align-middle mb-2" id="contract-templates-table">
                <thead>
                    <tr>
                        <th>Tipo de Contrato</th>
                        <th>Organización Temporal</th>
                        <th>Plantilla</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="contract-templates-body">
                </tbody>
            </table>
            <button type="button" class="btn btn-outline-dark btn-sm" id="add-contract-template-row">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar asignación
            </button>
        </div>
    </div>

    <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Actualizar</button>
        <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
    </div>
    <?= $this->Form->end() ?>
</div>

<?php
$existingRows = [];
if (!empty($noveltyType->novelty_type_contract_templates)) {
    foreach ($noveltyType->novelty_type_contract_templates as $ct) {
        $existingRows[] = [
            'id' => $ct->id,
            'contract_type' => $ct->contract_type,
            'temporary_organization_id' => $ct->temporary_organization_id,
            'leave_document_template_id' => $ct->leave_document_template_id,
        ];
    }
}
?>

<?php $this->Html->scriptStart(['block' => true]); ?>
(function() {
    var contractTypes = <?= json_encode($contractTypes) ?>;
    var documentTemplates = <?= json_encode($documentTemplates) ?>;
    var temporaryOrgs = <?= json_encode($temporaryOrganizations) ?>;
    var existingRows = <?= json_encode($existingRows) ?>;
    var obraLaborValue = <?= json_encode(\App\Constants\ContractTypeConstants::OBRA_LABOR) ?>;
    var rowIndex = 0;
    var tbody = document.getElementById('contract-templates-body');

    function buildOptions(obj, emptyLabel) {
        var html = '<option value="">' + emptyLabel + '</option>';
        for (var k in obj) {
            if (obj.hasOwnProperty(k)) {
                html += '<option value="' + k + '">' + obj[k] + '</option>';
            }
        }
        return html;
    }

    function addRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        var prefix = 'novelty_type_contract_templates[' + rowIndex + ']';
        var hiddenHtml = '';

        if (data.id) {
            hiddenHtml = '<input type="hidden" name="' + prefix + '[id]" value="' + data.id + '">';
        }

        tr.innerHTML = hiddenHtml
            + '<td><select name="' + prefix + '[contract_type]" class="form-select form-select-sm ct-contract-type">'
            + buildOptions(contractTypes, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[temporary_organization_id]" class="form-select form-select-sm ct-org-select" disabled>'
            + buildOptions(temporaryOrgs, '-- Seleccione --') + '</select></td>'
            + '<td><select name="' + prefix + '[leave_document_template_id]" class="form-select form-select-sm">'
            + buildOptions(documentTemplates, '-- Seleccione --') + '</select></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row" aria-label="Eliminar fila" title="Eliminar fila"><i class="bi bi-trash" aria-hidden="true"></i></button></td>';

        tbody.appendChild(tr);

        var ctSelect = tr.querySelector('.ct-contract-type');
        var orgSelect = tr.querySelector('.ct-org-select');

        function toggleOrg() {
            var isObraLabor = ctSelect.value === obraLaborValue;
            orgSelect.disabled = !isObraLabor;
            if (!isObraLabor) orgSelect.value = '';
        }

        ctSelect.addEventListener('change', toggleOrg);

        if (data.contract_type) ctSelect.value = data.contract_type;
        if (data.leave_document_template_id) {
            tr.querySelector('[name$="[leave_document_template_id]"]').value = data.leave_document_template_id;
        }

        toggleOrg();

        if (data.temporary_organization_id) {
            orgSelect.disabled = false;
            orgSelect.value = data.temporary_organization_id;
        }

        tr.querySelector('.ct-remove-row').addEventListener('click', function() { tr.remove(); });
        rowIndex++;
    }

    existingRows.forEach(function(row) { addRow(row); });

    document.getElementById('add-contract-template-row').addEventListener('click', function() { addRow(); });

    // Hide template section for subtypes
    var parentSelect = document.getElementById('parent-id');
    var templateFields = document.getElementById('contract-templates-fields');
    var subtypeNotice = document.getElementById('subtype-templates-notice');

    function toggleTemplateSection() {
        var isSubtype = parentSelect && parentSelect.value !== '';
        templateFields.style.display = isSubtype ? 'none' : '';
        subtypeNotice.style.display = isSubtype ? '' : 'none';
    }

    if (parentSelect) {
        parentSelect.addEventListener('change', toggleTemplateSection);
        toggleTemplateSection();
    }
})();
<?php $this->Html->scriptEnd(); ?>
