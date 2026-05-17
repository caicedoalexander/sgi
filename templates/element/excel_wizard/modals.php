<?php
/**
 * Excel wizard modals — export field selector + import 3-step wizard.
 *
 * @var \App\View\AppView $this
 * @var string $module       Camel-cased, e.g. 'Employees'
 * @var string $entityName   Plural Spanish label, e.g. 'Empleados'
 * @var string $downloadSlug Lower-snake slug for filename, e.g. 'empleados'
 * @var bool   $importable
 */
$importable = $importable ?? true;
?>
<!-- Export Modal -->
<div class="modal fade" id="exportExcelModal" tabindex="-1"
     data-module="<?= h($module) ?>" data-download-slug="<?= h($downloadSlug) ?>">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exportar <?= h($entityName) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center mb-2">
                    <input type="checkbox" class="form-check-input me-2" id="exportSelectAll" checked>
                    <label class="form-check-label" for="exportSelectAll" style="font-size:.875rem;font-weight:600">Seleccionar todos</label>
                </div>
                <div id="exportLoading" style="display:none" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm me-1"></span>Cargando campos...
                </div>
                <div id="exportFieldList" style="max-height:400px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px"></div>
                <div id="exportError" class="text-danger mt-2" style="display:none;font-size:.825rem"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="exportBtn">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>Exportar
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($importable): ?>
<!-- Import Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" data-module="<?= h($module) ?>">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Importar <?= h($entityName) ?> desde Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="importStep1">
                    <p style="font-size:.85rem;color:var(--text-muted);">
                        Seleccione un archivo <code>.xlsx</code> para importar. El sistema detectará las columnas automáticamente y le permitirá configurar el mapeo.
                    </p>
                    <p style="font-size:.8rem;color:var(--text-faint);">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Tip: Exporte primero para obtener la plantilla con las columnas correctas.
                    </p>
                    <input type="file" id="importFileInput" class="form-control" accept=".xlsx">
                    <div id="importStep1Error" class="alert alert-danger py-2 px-3 mt-2" style="display:none;font-size:.825rem"></div>
                </div>

                <div id="importStep2" style="display:none">
                    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.75rem">
                        Configure el mapeo de columnas. Las columnas reconocidas se asignan automáticamente.
                    </p>
                    <div style="max-height:400px;overflow-y:auto">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th style="width:40px"></th>
                                    <th style="font-size:.8rem">Columna del archivo</th>
                                    <th style="font-size:.8rem">Campo del sistema</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="importMappingBody"></tbody>
                        </table>
                    </div>
                    <div id="importRequiredIndicator" style="display:none"></div>
                    <div id="importMappingError" class="alert alert-danger py-2 px-3 mt-2" style="display:none;font-size:.825rem"></div>
                </div>

                <div id="importStep3" style="display:none">
                    <div id="importResults"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-outline-secondary" id="importBackBtn" style="display:none">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver
                </button>
                <button type="button" class="btn btn-primary" id="importUploadBtn">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Subir
                </button>
                <button type="button" class="btn btn-primary" id="importProcessBtn" style="display:none">
                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Importar
                </button>
                <button type="button" class="btn btn-primary" id="importCloseBtn" style="display:none">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->Html->script('excel-mapper', ['block' => true]) ?>
