/**
 * Excel Mapper — Export & Import modal logic with SortableJS.
 * Initialize by including this script and adding data-module="Employees" to #exportExcelModal.
 */
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrfToken"]')?.content || '';

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // CamelCase → kebab-case para coincidir con DashedRoute de CakePHP.
    // Ej: CostCenters → cost-centers, OperationCenters → operation-centers.
    function toKebab(s) {
        return s.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();
    }

    // ─── EXPORT MODAL ───
    const exportModal = document.getElementById('exportExcelModal');
    if (exportModal) {
        const module = exportModal.dataset.module || 'Employees';
        const exportFieldList = document.getElementById('exportFieldList');
        const exportBtn = document.getElementById('exportBtn');
        const exportSelectAll = document.getElementById('exportSelectAll');
        const exportLoading = document.getElementById('exportLoading');

        // Load fields when modal opens
        exportModal.addEventListener('show.bs.modal', function () {
            if (exportFieldList.children.length > 0) return; // Already loaded
            exportLoading.style.display = 'block';

            fetch(`/${toKebab(module)}/export-config`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                exportLoading.style.display = 'none';
                renderExportFields(data.fields);
                initSortable();
            })
            .catch(() => {
                exportLoading.style.display = 'none';
                exportFieldList.innerHTML = '<div class="text-danger">Error al cargar campos.</div>';
            });
        });

        function renderExportFields(fields) {
            exportFieldList.innerHTML = '';
            fields.forEach(f => {
                const item = document.createElement('div');
                item.className = 'export-field-item d-flex align-items-center gap-2 px-3 py-2';
                item.dataset.field = f.field;

                const grip = document.createElement('i');
                grip.className = 'bi bi-grip-vertical text-muted export-drag-handle';
                grip.style.cursor = 'grab';

                const inputId = 'exp_' + f.field;
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'form-check-input export-field-check';
                cb.value = f.field;
                cb.id = inputId;
                if (f.checked) cb.checked = true;

                const lbl = document.createElement('label');
                lbl.className = 'form-check-label flex-grow-1';
                lbl.htmlFor = inputId;
                lbl.style.fontSize = '.875rem';
                lbl.textContent = f.label;

                item.appendChild(grip);
                item.appendChild(cb);
                item.appendChild(lbl);
                exportFieldList.appendChild(item);
            });
        }

        function initSortable() {
            if (typeof Sortable !== 'undefined') {
                Sortable.create(exportFieldList, {
                    handle: '.export-drag-handle',
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                });
            }
        }

        // Select all
        if (exportSelectAll) {
            exportSelectAll.addEventListener('change', function () {
                exportFieldList.querySelectorAll('.export-field-check').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }

        // Export button
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                const fields = [];
                exportFieldList.querySelectorAll('.export-field-item').forEach(item => {
                    const cb = item.querySelector('.export-field-check');
                    if (cb && cb.checked) {
                        fields.push(cb.value);
                    }
                });

                if (fields.length === 0) {
                    const errorDiv = document.getElementById('exportError');
                    if (errorDiv) {
                        errorDiv.textContent = 'Seleccione al menos un campo para exportar.';
                        errorDiv.style.display = 'block';
                    }
                    return;
                }

                exportBtn.disabled = true;
                exportBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Exportando...';

                fetch(`/${toKebab(module)}/export`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({ fields }),
                })
                .then(response => {
                    if (!response.ok) throw new Error('Error en la exportación');
                    return response.blob();
                })
                .then(blob => {
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    const slug = exportModal.dataset.downloadSlug || module.toLowerCase();
                    a.download = `${slug}_${new Date().toISOString().slice(0,10)}.xlsx`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    bootstrap.Modal.getInstance(exportModal).hide();
                })
                .catch(() => {
                    const errorDiv = document.getElementById('exportError');
                    if (errorDiv) {
                        errorDiv.textContent = 'Error al exportar. Intente de nuevo.';
                        errorDiv.style.display = 'block';
                    }
                })
                .finally(() => {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = '<i class="bi bi-download me-1"></i>Exportar';
                });
            });
        }
    }

    // ─── IMPORT MODAL ───
    const importModal = document.getElementById('importExcelModal');
    if (importModal) {
        const module = importModal.dataset.module || 'Employees';
        const importStep1 = document.getElementById('importStep1');
        const importStep2 = document.getElementById('importStep2');
        const importStep3 = document.getElementById('importStep3');
        const importUploadBtn = document.getElementById('importUploadBtn');
        const importProcessBtn = document.getElementById('importProcessBtn');
        const importBackBtn = document.getElementById('importBackBtn');
        const importCloseBtn = document.getElementById('importCloseBtn');
        const importFileInput = document.getElementById('importFileInput');
        const importMappingBody = document.getElementById('importMappingBody');
        const importMappingError = document.getElementById('importMappingError');
        const importStep1Error = document.getElementById('importStep1Error');

        let currentTempName = null;
        let currentHeaders = [];
        let systemFields = [];

        // Reset on modal close
        importModal.addEventListener('hidden.bs.modal', function () {
            showStep(1);
            if (importFileInput) importFileInput.value = '';
            currentTempName = null;
            currentHeaders = [];
            importMappingBody.innerHTML = '';
            if (importStep1Error) {
                importStep1Error.textContent = '';
                importStep1Error.style.display = 'none';
            }
            if (importMappingError) {
                importMappingError.textContent = '';
                importMappingError.style.display = 'none';
            }
        });

        function showStep(step) {
            importStep1.style.display = step === 1 ? 'block' : 'none';
            importStep2.style.display = step === 2 ? 'block' : 'none';
            importStep3.style.display = step === 3 ? 'block' : 'none';

            if (importUploadBtn) importUploadBtn.style.display = step === 1 ? 'inline-block' : 'none';
            if (importProcessBtn) importProcessBtn.style.display = step === 2 ? 'inline-block' : 'none';
            if (importBackBtn) importBackBtn.style.display = step === 2 ? 'inline-block' : 'none';
            if (importCloseBtn) importCloseBtn.style.display = step === 3 ? 'inline-block' : 'none';
        }

        // Step 1 → Upload file
        if (importUploadBtn) {
            importUploadBtn.addEventListener('click', function () {
                const file = importFileInput?.files[0];
                if (!file) {
                    if (importStep1Error) {
                        importStep1Error.textContent = 'Seleccione un archivo .xlsx antes de continuar.';
                        importStep1Error.style.display = 'block';
                    }
                    return;
                }

                if (importStep1Error) {
                    importStep1Error.textContent = '';
                    importStep1Error.style.display = 'none';
                }

                const formData = new FormData();
                formData.append('excel_file', file);

                importUploadBtn.disabled = true;
                importUploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Analizando...';

                fetch(`/${toKebab(module)}/import-upload`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                    },
                    body: formData,
                })
                .then(async r => {
                    // Si la respuesta no es JSON (404, 500, redirect HTML), construir un
                    // error informativo en lugar de fallar silenciosamente.
                    const contentType = r.headers.get('content-type') || '';
                    if (!contentType.includes('application/json')) {
                        throw new Error(`Error del servidor (HTTP ${r.status}). La ruta /${toKebab(module)}/import-upload no respondió JSON.`);
                    }
                    const data = await r.json();
                    if (!r.ok || data.error) {
                        throw new Error(data.error || `Error HTTP ${r.status}`);
                    }
                    return data;
                })
                .then(data => {
                    currentTempName = data.tempName;
                    currentHeaders = data.headers;
                    systemFields = data.systemFields;
                    renderMappingTable(data.headers, data.autoMapping, data.systemFields);
                    showStep(2);
                })
                .catch(err => {
                    if (importStep1Error) {
                        importStep1Error.textContent = err.message || 'Error al procesar el archivo.';
                        importStep1Error.style.display = 'block';
                    }
                })
                .finally(() => {
                    importUploadBtn.disabled = false;
                    importUploadBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Subir';
                });
            });
        }

        // Back button
        if (importBackBtn) {
            importBackBtn.addEventListener('click', function () {
                showStep(1);
                importMappingError.style.display = 'none';
            });
        }

        function setStatusIcon(cell, mapped) {
            cell.innerHTML = '';
            const icon = document.createElement('i');
            icon.className = mapped
                ? 'bi bi-check-circle-fill text-success'
                : 'bi bi-dash-circle text-muted';
            cell.appendChild(icon);
        }

        function renderMappingTable(headers, autoMapping, fields) {
            importMappingBody.innerHTML = '';

            headers.forEach(header => {
                const mappedField = autoMapping[header] || '';

                const tr = document.createElement('tr');

                // Col 1: checkbox
                const tdCheck = document.createElement('td');
                tdCheck.className = 'align-middle';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input import-col-check';
                checkbox.dataset.header = header;
                if (mappedField) checkbox.checked = true;
                tdCheck.appendChild(checkbox);

                // Col 2: header label
                const tdHeader = document.createElement('td');
                tdHeader.className = 'align-middle';
                tdHeader.style.fontSize = '.875rem';
                const code = document.createElement('code');
                code.textContent = header;
                tdHeader.appendChild(code);

                // Col 3: select
                const tdSelect = document.createElement('td');
                const select = document.createElement('select');
                select.className = 'form-select form-select-sm import-field-select';
                select.dataset.header = header;
                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = '\u2014 Sin asignar \u2014';
                select.appendChild(emptyOpt);
                fields.forEach(f => {
                    const opt = document.createElement('option');
                    opt.value = f.field;
                    opt.textContent = f.label + (f.required ? ' *' : '');
                    if (mappedField === f.field) opt.selected = true;
                    select.appendChild(opt);
                });
                tdSelect.appendChild(select);

                // Col 4: status icon
                const statusCell = document.createElement('td');
                statusCell.className = 'align-middle text-center import-status-cell';
                setStatusIcon(statusCell, !!mappedField);

                tr.appendChild(tdCheck);
                tr.appendChild(tdHeader);
                tr.appendChild(tdSelect);
                tr.appendChild(statusCell);
                importMappingBody.appendChild(tr);

                select.addEventListener('change', function () {
                    if (this.value) {
                        checkbox.checked = true;
                    }
                    setStatusIcon(statusCell, !!this.value);
                    validateRequiredMapped();
                });

                checkbox.addEventListener('change', function () {
                    validateRequiredMapped();
                });
            });

            validateRequiredMapped();
        }

        function validateRequiredMapped() {
            const requiredFields = systemFields.filter(f => f.required).map(f => f.field);
            const mappedFields = [];

            importMappingBody.querySelectorAll('tr').forEach(tr => {
                const cb = tr.querySelector('.import-col-check');
                const sel = tr.querySelector('.import-field-select');
                if (cb && cb.checked && sel && sel.value) {
                    mappedFields.push(sel.value);
                }
            });

            const missing = requiredFields.filter(f => !mappedFields.includes(f));
            const indicator = document.getElementById('importRequiredIndicator');

            if (missing.length > 0) {
                const missingLabels = missing.map(f => {
                    const sf = systemFields.find(s => s.field === f);
                    return sf ? sf.label : f;
                });
                if (indicator) {
                    indicator.innerHTML = '';
                    const icon = document.createElement('i');
                    icon.className = 'bi bi-exclamation-triangle-fill text-warning me-1';
                    indicator.appendChild(icon);
                    indicator.appendChild(document.createTextNode('Faltan campos obligatorios: ' + missingLabels.join(', ')));
                    indicator.className = 'alert alert-warning py-2 px-3 mb-0 mt-2';
                    indicator.style.display = 'block';
                    indicator.style.fontSize = '.825rem';
                }
                if (importProcessBtn) importProcessBtn.disabled = true;
            } else {
                if (indicator) {
                    indicator.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i>Todos los campos obligatorios mapeados';
                    indicator.className = 'alert alert-success py-2 px-3 mb-0 mt-2';
                    indicator.style.display = 'block';
                    indicator.style.fontSize = '.825rem';
                }
                if (importProcessBtn) importProcessBtn.disabled = false;
            }
        }

        // Step 2 → Process import
        if (importProcessBtn) {
            importProcessBtn.addEventListener('click', function () {
                const mapping = {};
                const enabled = [];

                importMappingBody.querySelectorAll('tr').forEach(tr => {
                    const cb = tr.querySelector('.import-col-check');
                    const sel = tr.querySelector('.import-field-select');
                    const header = cb?.dataset.header;

                    if (cb && cb.checked && header) {
                        enabled.push(header);
                        if (sel && sel.value) {
                            mapping[header] = sel.value;
                        }
                    }
                });

                importProcessBtn.disabled = true;
                importProcessBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importando...';
                importMappingError.style.display = 'none';

                fetch(`/${toKebab(module)}/import-process`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({
                        temp_file: currentTempName,
                        mapping: mapping,
                        enabled: enabled,
                    }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    renderImportResults(data);
                    showStep(3);
                })
                .catch(err => {
                    importMappingError.textContent = err.message || 'Error al importar.';
                    importMappingError.style.display = 'block';
                })
                .finally(() => {
                    importProcessBtn.disabled = false;
                    importProcessBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Importar';
                });
            });
        }

        // Close button (step 3) → reload page
        if (importCloseBtn) {
            importCloseBtn.addEventListener('click', function () {
                window.location.reload();
            });
        }

        function renderImportResults(data) {
            const container = document.getElementById('importResults');
            if (!container) return;

            const n = v => Number.isFinite(Number(v)) ? Number(v) : 0;
            let html = `
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:1.5rem"></i>
                    <strong>Importación completada</strong>
                </div>
                <div class="d-flex justify-content-center gap-3 mb-3 flex-wrap">
                    <div class="text-center" style="min-width:80px">
                        <div style="font-size:1.5rem;font-weight:600;color:var(--primary-color)">${n(data.created)}</div>
                        <div style="font-size:.75rem;color:#666">Creados</div>
                    </div>
                    <div class="text-center" style="min-width:80px">
                        <div style="font-size:1.5rem;font-weight:600;color:#0d6efd">${n(data.updated)}</div>
                        <div style="font-size:.75rem;color:#666">Actualizados</div>
                    </div>
                    <div class="text-center" style="min-width:80px">
                        <div style="font-size:1.5rem;font-weight:600;color:#6c757d">${n(data.unchanged)}</div>
                        <div style="font-size:.75rem;color:#666">Sin cambios</div>
                    </div>
                    <div class="text-center" style="min-width:80px">
                        <div style="font-size:1.5rem;font-weight:600;color:#6c757d">${n(data.skipped)}</div>
                        <div style="font-size:.75rem;color:#666">Omitidos</div>
                    </div>
                    <div class="text-center" style="min-width:80px">
                        <div style="font-size:1.5rem;font-weight:600;color:#dc3545">${data.errors ? data.errors.length : 0}</div>
                        <div style="font-size:.75rem;color:#666">Errores</div>
                    </div>
                </div>
            `;

            if (data.errors && data.errors.length > 0) {
                html += `
                    <div style="font-size:.825rem;font-weight:600;margin-bottom:.5rem">Detalle de errores:</div>
                    <div style="max-height:200px;overflow-y:auto;font-size:.8rem;border:1px solid var(--border-color);border-radius:4px;padding:.5rem">
                        ${data.errors.map(e => `<div class="mb-1"><i class="bi bi-exclamation-circle text-danger me-1"></i>${escapeHtml(e)}</div>`).join('')}
                    </div>
                `;
            }

            container.innerHTML = html;
        }
    }
});
