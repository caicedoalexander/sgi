/**
 * SgiDocumentUploader
 * Helper unificado para subida y eliminación AJAX de documentos en módulos
 * de flujo (Invoices, PettyCashRecords, EmployeeNovelties, NoveltyLiquidationDocs).
 *
 * Uso:
 *   SgiDocumentUploader.init({
 *     formSelector:        '#upload-doc-form',
 *     listSelector:        '#docs-list',
 *     emptySelector:       '#docs-empty-state',
 *     counterSelector:     '.card-header .sgi-folder-count',
 *     rowTemplateSelector: '#doc-row-template',
 *     modalSelector:       '#uploadDocModal',
 *     csrfToken:           '...'
 *   });
 *
 * Contrato JSON esperado:
 *   Upload OK:    { success: true, document: { id, file_name, document_type,
 *                   mime_type, file_path, file_size, created,
 *                   pipeline_status?, can_delete } }
 *   Upload fail:  { success: false, error: '...' }
 *   Delete OK:    { success: true }
 *   Delete fail:  { success: false, error: '...' }
 */
(function (global) {
    'use strict';

    function docIconClass(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return 'bi-file-earmark-pdf';
        if (mime.indexOf('image') !== -1) return 'bi-file-earmark-image';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return 'bi-file-earmark-word';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'bi-file-earmark-excel';
        return 'bi-file-earmark';
    }

    function docIconColor(mime) {
        mime = mime || '';
        if (mime.indexOf('pdf') !== -1) return '#dc3545';
        if (mime.indexOf('image') !== -1) return '#0dcaf0';
        if (mime.indexOf('wordprocessingml') !== -1 || mime.indexOf('msword') !== -1) return '#0d6efd';
        if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1) return 'var(--primary-color)';
        return '#aaa';
    }

    function formatFileSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function setSlot(root, slot, value, attr) {
        var el = root.querySelector('[data-slot="' + slot + '"]');
        if (!el) return null;
        if (attr) {
            el.setAttribute(attr, value);
        } else {
            el.textContent = value;
        }
        return el;
    }

    function buildDocRow(template, doc) {
        var clone = template.content.firstElementChild.cloneNode(true);
        clone.setAttribute('data-doc-id', doc.id);

        var iconEl = clone.querySelector('.doc-icon i');
        if (iconEl) {
            iconEl.classList.add(docIconClass(doc.mime_type));
            iconEl.style.color = docIconColor(doc.mime_type);
        }

        var label = doc.document_type || doc.file_name;
        var labelEl = setSlot(clone, 'label', label);
        if (labelEl) labelEl.title = label;

        var filenameEl = clone.querySelector('[data-slot="filename"]');
        if (filenameEl) {
            if (doc.document_type) {
                filenameEl.textContent = doc.file_name;
                filenameEl.title = doc.file_name;
                filenameEl.style.display = '';
            } else {
                filenameEl.style.display = 'none';
            }
        }

        var badgeEl = clone.querySelector('[data-slot="badge"]');
        if (badgeEl) {
            if (doc.pipeline_status && doc.badge_class && doc.badge_label) {
                badgeEl.classList.add(doc.badge_class);
                badgeEl.textContent = doc.badge_label;
                badgeEl.style.display = '';
            } else {
                badgeEl.style.display = 'none';
            }
        }

        setSlot(clone, 'created', doc.created || '');

        var sizeEl = clone.querySelector('[data-slot="size"]');
        if (sizeEl) {
            if (doc.file_size) {
                sizeEl.textContent = formatFileSize(doc.file_size);
                sizeEl.style.display = '';
            } else {
                sizeEl.style.display = 'none';
            }
        }

        var openEl = clone.querySelector('[data-slot="open-link"]');
        if (openEl) openEl.href = '/' + doc.file_path;

        var deleteEl = clone.querySelector('[data-slot="delete-btn"]');
        if (deleteEl) {
            if (doc.can_delete && doc.delete_url) {
                deleteEl.setAttribute('data-url', doc.delete_url);
                deleteEl.style.display = '';
            } else {
                deleteEl.style.display = 'none';
            }
        }

        return clone;
    }

    function updateCounter(el, delta) {
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var n = m ? parseInt(m[1], 10) + delta : delta;
        if (n < 0) n = 0;
        el.textContent = n + ' doc' + (n !== 1 ? 's' : '');
    }

    function init(opts) {
        var form         = document.querySelector(opts.formSelector);
        var list         = document.querySelector(opts.listSelector);
        var emptyState   = document.querySelector(opts.emptySelector);
        // Resolve counter relative to the card containing the list to avoid
        // matching counters in unrelated cards (e.g. observations, payments).
        var listCard     = list ? list.closest('.card') : null;
        var counter      = listCard
            ? listCard.querySelector('.sgi-folder-count')
            : (opts.counterSelector ? document.querySelector(opts.counterSelector) : null);
        var template     = document.querySelector(opts.rowTemplateSelector);
        var modalEl      = opts.modalSelector ? document.querySelector(opts.modalSelector) : null;
        var csrfToken    = opts.csrfToken || '';
        var submitBtn    = form ? form.querySelector('button[type="submit"]') : null;

        if (!list || !template) return;

        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fileInput = form.querySelector('input[type="file"]');
                if (!fileInput || !fileInput.files.length) return;

                var file = fileInput.files[0];
                var maxBytes = global.SGI_MAX_UPLOAD_BYTES || (20 * 1024 * 1024);
                var maxLabel = global.SGI_MAX_UPLOAD_LABEL || '20 MB';
                if (file.size > maxBytes) {
                    alert('El archivo supera el tamaño máximo de ' + maxLabel + '.');
                    return;
                }

                var originalHtml = submitBtn ? submitBtn.innerHTML : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Subiendo...';
                }

                fetch(form.dataset.url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: new FormData(form),
                    redirect: 'follow'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (emptyState) emptyState.style.display = 'none';
                        list.appendChild(buildDocRow(template, data.document));
                        updateCounter(counter, 1);
                        form.reset();
                        if (modalEl && global.bootstrap) {
                            var modal = global.bootstrap.Modal.getInstance(modalEl);
                            if (modal) modal.hide();
                        }
                    } else {
                        alert(data.error || 'Error al subir el archivo.');
                    }
                })
                .catch(function () { alert('Error de conexión. Intente nuevamente.'); })
                .finally(function () {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
                });
            });
        }

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.doc-delete-btn');
            if (!btn || !list.contains(btn)) return;
            if (!confirm('¿Eliminar este soporte?')) return;

            btn.disabled = true;
            fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var row = btn.closest('.doc-row');
                    if (row) row.remove();
                    updateCounter(counter, -1);
                    if (!list.querySelector('.doc-row') && emptyState) emptyState.style.display = '';
                } else {
                    alert(data.error || 'Error al eliminar.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Error de conexión. Intente nuevamente.');
                btn.disabled = false;
            });
        });
    }

    global.SgiDocumentUploader = { init: init };
})(window);
