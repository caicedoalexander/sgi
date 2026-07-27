<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Invoice $invoice
 * @var array<string, array<string, bool>> $userPermissions
 */
use App\Constants\InvoiceConstants;

$this->assign('title', 'Nueva Factura');

$documentTypes = array_combine(InvoiceConstants::DOCUMENT_TYPES, InvoiceConstants::DOCUMENT_TYPES);
?>
<?= $this->element('cdn_autonumeric') ?>
<?= $this->element('cdn_select2') ?>

<!-- Encabezado de página -->
<div class="spi-page-header d-flex justify-content-between align-items-center">
    <span class="spi-page-title">Nueva Factura</span>
    <?= $this->Html->link(
        '<i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver',
        ['action' => 'index'],
        ['class' => 'btn btn-ghost-card', 'escape' => false],
    ) ?>
</div>

<div class="spi-card">
    <div class="d-flex align-items-center gap-3" style="margin-bottom:20px;">
        <div class="spi-icon-chip" style="font-size:.95rem;">
            <i class="bi bi-receipt" aria-hidden="true"></i>
        </div>
        <span style="font-size:var(--fs-title-card);font-weight:600;color:var(--text-default);">Información de la Factura</span>
    </div>

    <?= $this->Form->create($invoice) ?>

    <?php if (!empty($advance)) : ?>
        <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>
            <span>Comprobante para el
                <?php $advanceLabel = 'Anticipo #' . ($advance->invoice_number ?: $advance->id); ?>
                <?php if (!empty($userPermissions['advances']['can_edit'])): ?>
                <?= $this->Html->link(
                    $advanceLabel,
                    ['controller' => 'Advances', 'action' => 'legalization', $advance->id],
                ) ?>.
                <?php else: ?>
                <strong><?= h($advanceLabel) ?></strong>.
                <?php endif; ?>
            </span>
        </div>
        <?= $this->Form->control('advance_id', ['type' => 'hidden']) ?>
    <?php endif; ?>

    <!-- Sección: Documento -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Documento</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <?= $this->Form->control('invoice_number', [
                    'class'       => 'form-control',
                    'label'       => ['text' => 'No. Factura', 'class' => 'input-label'],
                    'placeholder' => 'Ej: FV-001234',
                ]) ?>
            </div>
            <div class="col-md-3">
                <?= $this->Form->control('document_type', [
                    'class'   => 'form-select',
                    'label'   => ['text' => 'Tipo de Documento', 'class' => 'input-label'],
                    'options' => !empty($advance)
                        ? array_intersect_key($documentTypes, array_flip(InvoiceConstants::ADVANCE_LINKABLE_DOCTYPES))
                        : $documentTypes,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3" id="purchase-order-wrapper">
                <?= $this->Form->control('purchase_order', [
                    'class' => 'form-control',
                    'label' => ['text' => 'Orden de Compra', 'class' => 'input-label'],
                ]) ?>
            </div>
            <div class="col-md-3" id="provider-wrapper">
                <?= $this->Form->control('provider_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Proveedor', 'class' => 'input-label'],
                    'options' => $providers,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
        </div>

        <!-- Sub-formulario disparado por document_type='Recibo de Caja' -->
        <div class="row g-3 mt-1 d-none" id="equivalent-doc-row">
            <div class="col-md-3" id="holder-type-wrapper">
                <?= $this->Form->control('equivalent_holder_type', [
                    'class'   => 'form-select',
                    'label'   => ['text' => 'Titular del Documento', 'class' => 'input-label'],
                    'options' => ['provider' => 'Proveedor', 'employee' => 'Empleado', 'manual' => 'Cédula Manual'],
                    'empty'   => '-- Seleccione --',
                    'id'      => 'equivalent-holder-type',
                ]) ?>
            </div>
            <div class="col-md-3 d-none" id="employee-wrapper">
                <?= $this->Form->control('employee_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Empleado', 'class' => 'input-label'],
                    'options' => $employees ?? [],
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3 d-none" id="manual-doc-wrapper">
                <?= $this->Form->control('manual_document_number', [
                    'class'       => 'form-control',
                    'label'       => ['text' => 'Cédula', 'class' => 'input-label'],
                    'placeholder' => 'Número de cédula',
                ]) ?>
            </div>
        </div>
    </div>

    <!-- Sección: Fechas -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Fechas</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <?= $this->Form->control('issue_date', [
                    'type'  => 'text',
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha de Emisión', 'class' => 'input-label'],
                ]) ?>
            </div>
            <div class="col-md-6" id="due-date-wrapper">
                <?= $this->Form->control('due_date', [
                    'type'  => 'text',
                    'class' => 'form-control flatpickr-date',
                    'label' => ['text' => 'Fecha de Vencimiento', 'class' => 'input-label'],
                ]) ?>
            </div>
        </div>
    </div>

    <!-- Sección: Clasificación y Valor -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Clasificación y Valor</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <?= $this->Form->control('operation_center_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Centro de Operación', 'class' => 'input-label'],
                    'options' => $operationCenters,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <?= $this->Form->control('expense_type_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Tipo de Gasto', 'class' => 'input-label'],
                    'options' => $expenseTypes,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <?= $this->Form->control('cost_center_id', [
                    'class'   => 'form-select select2-enable',
                    'label'   => ['text' => 'Centro de Costos', 'class' => 'input-label'],
                    'options' => $costCenters,
                    'empty'   => '-- Seleccione --',
                ]) ?>
            </div>
            <div class="col-md-3">
                <label class="input-label">Valor (COP)</label>
                <input type="text" name="amount" class="form-control currency-input"
                       value="<?= h($invoice->amount ?? '') ?>" required>
            </div>
        </div>
    </div>

    <!-- Sección: Detalle -->
    <div class="mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="text-uppercase fw-semibold flex-shrink-0"
                  style="font-size:var(--fs-micro);letter-spacing:.14em;color:var(--text-disabled);">Descripción</span>
            <div style="flex:1;height:1px;background:var(--rule);"></div>
        </div>
        <div>
            <?= $this->Form->control('detail', [
                'type'  => 'textarea',
                'rows'  => 3,
                'class' => 'form-control',
                'label' => ['text' => 'Detalle', 'class' => 'input-label'],
            ]) ?>
        </div>
    </div>

    <?= $this->Form->hidden('pipeline_status', ['value' => InvoiceConstants::STATUS_APROBACION]) ?>

    <div class="d-flex gap-2 pt-3" style="border-top:1px solid var(--rule);">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar Factura
        </button>
        <?= $this->Html->link(
            'Cancelar',
            ['action' => 'index'],
            ['class' => 'btn btn-default'],
        ) ?>
    </div>

    <?= $this->Form->end() ?>

<?php $this->append('script') ?>
<script>
(function () {
    var docTypeSelect = document.querySelector('select[name="document_type"]');
    if (!docTypeSelect) return;

    var purchaseOrder = document.getElementById('purchase-order-wrapper');
    var dueDate       = document.getElementById('due-date-wrapper');
    var providerWrap  = document.getElementById('provider-wrapper');
    var equivalentRow = document.getElementById('equivalent-doc-row');
    var holderSelect  = document.getElementById('equivalent-holder-type');
    var employeeWrap  = document.getElementById('employee-wrapper');
    var manualWrap    = document.getElementById('manual-doc-wrapper');

    function setVisible(wrapper, visible) {
        if (!wrapper) return;
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input,select,textarea').forEach(function (el) {
            el.disabled = !visible;
            if (!visible) {
                el.value = '';
                el.checked = false;
                if (window.jQuery && el.tagName === 'SELECT' && window.jQuery(el).hasClass('select2-hidden-accessible')) {
                    window.jQuery(el).val(null).trigger('change');
                }
            }
        });
    }

    function applyHolderRules() {
        var holder = holderSelect ? holderSelect.value : '';
        setVisible(employeeWrap, holder === 'employee');
        setVisible(manualWrap,   holder === 'manual');
        // Proveedor visible sólo cuando el titular es 'provider' o aún no se ha elegido.
        setVisible(providerWrap, holder === '' || holder === 'provider');
    }

    var RECIBO_CAJA = <?= json_encode(InvoiceConstants::DOCTYPE_RECIBO_CAJA) ?>;
    var LEGALIZACION = <?= json_encode(InvoiceConstants::DOCTYPE_LEGALIZACION) ?>;

    function applyDocTypeRules() {
        var value           = docTypeSelect.value;
        var isLegalization  = value === LEGALIZACION;
        var isReciboDeCaja  = value === RECIBO_CAJA;

        setVisible(purchaseOrder, !isLegalization);
        setVisible(dueDate,       !isLegalization && !isReciboDeCaja);
        setVisible(equivalentRow, isReciboDeCaja);

        if (!isReciboDeCaja) {
            // Reset holder + sub-campos cuando salimos de Recibo de Caja.
            if (holderSelect) holderSelect.value = '';
            setVisible(employeeWrap, false);
            setVisible(manualWrap,   false);
            setVisible(providerWrap, true);
        } else {
            applyHolderRules();
        }
    }

    docTypeSelect.addEventListener('change', applyDocTypeRules);
    if (holderSelect) holderSelect.addEventListener('change', applyHolderRules);
    applyDocTypeRules();
})();
</script>
<?php $this->end() ?>
</div>
