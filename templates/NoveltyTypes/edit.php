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
        '<i class="bi bi-arrow-left me-1"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-outline-dark btn-sm', 'escape' => false]
    ) ?>
</div>

<div class="card card-primary">
    <div class="card-body p-4">
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
                    <p class="text-muted small mb-2">Etapas requeridas</p>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_rrhh', ['class' => 'form-check-input', 'id' => 'requires-rrhh']) ?>
                        <label class="form-check-label" for="requires-rrhh">Requiere etapa RRHH</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_firmas', ['class' => 'form-check-input', 'id' => 'requires-firmas']) ?>
                        <label class="form-check-label" for="requires-firmas">Requiere Firmas y Aprobación</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_gdp', ['class' => 'form-check-input', 'id' => 'requires-gdp']) ?>
                        <label class="form-check-label" for="requires-gdp">Requiere etapa GDP</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('requires_tesoreria', ['class' => 'form-check-input', 'id' => 'requires-tesoreria']) ?>
                        <label class="form-check-label" for="requires-tesoreria">Requiere etapa Tesorería</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <p class="text-muted small mb-2">Campos visibles en formulario</p>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('show_start_date', ['class' => 'form-check-input', 'id' => 'show-start-date']) ?>
                        <label class="form-check-label" for="show-start-date">Mostrar Fecha Inicio</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('show_end_date', ['class' => 'form-check-input', 'id' => 'show-end-date']) ?>
                        <label class="form-check-label" for="show-end-date">Mostrar Fecha Fin</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('show_permission_date', ['class' => 'form-check-input', 'id' => 'show-permission-date']) ?>
                        <label class="form-check-label" for="show-permission-date">Mostrar Fecha de Permiso</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('show_schedule_type', ['class' => 'form-check-input', 'id' => 'show-schedule-type']) ?>
                        <label class="form-check-label" for="show-schedule-type">Mostrar Tipo de Horario</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('uses_custom_name', ['class' => 'form-check-input', 'id' => 'uses-custom-name']) ?>
                        <label class="form-check-label" for="uses-custom-name">Usa Nombre Libre (en vez de select de empleado)</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?= $this->Form->checkbox('is_massive', ['class' => 'form-check-input', 'id' => 'is-massive']) ?>
                        <label class="form-check-label" for="is-massive">Novedad Masiva (multi-selección de empleados)</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contract Template Assignments -->
        <div class="mt-4 pt-3" style="border-top:1px solid var(--border-color);">
            <label class="sgi-section-label">Asignación de plantillas por tipo de contrato</label>
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
                <i class="bi bi-plus-lg me-1"></i>Agregar asignación
            </button>
        </div>

        <div class="d-flex gap-2 pt-3 mt-3" style="border-top:1px solid var(--border-color);">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
            <?= $this->Html->link('Cancelar', ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
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
            + '<td><button type="button" class="btn btn-sm btn-outline-danger ct-remove-row"><i class="bi bi-trash"></i></button></td>';

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
})();
<?php $this->Html->scriptEnd(); ?>
