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
 *     rowTemplateSelector: '#doc-row-template',
 *     modalSelector:       '#uploadDocModal',
 *     csrfToken:           '...'
 *   });
 *
 * El contador se resuelve automáticamente desde el `.card` que contiene
 * `listSelector` (debe contener un `.sgi-folder-count`).
 *
 * Contrato JSON esperado:
 *   Upload OK:    { success: true, document: { id, file_name, document_type,
 *                   mime_type, file_path, file_size, created,
 *                   pipeline_status?, can_delete } }
 *   Upload fail:  { success: false, error: '...' }
 *   Delete OK:    { success: true }
 *   Delete fail:  { success: false, error: '...' }
 *
 * El template (`#doc-row-template`) y el partial server-side
 * (`templates/element/document_row.php`) deben mantener los mismos
 * `data-slot`: label, filename, badge, created, size, open-link, delete-btn.
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

    // CR-002: defensa contra valores absolutos / con esquema en file_path o delete_url.
    // Acepta sólo paths relativos al sitio actual.
    function safeRelativePath(value) {
        if (typeof value !== 'string' || !value) return '';
        var trimmed = value.replace(/^\/+/, '');
        if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) return '';
        if (trimmed.indexOf('//') === 0) return '';
        return '/' + trimmed;
    }

    // ─── Bootstrap UI helpers (CR-011) ───────────────────────────────────────
    function ensureToastContainer() {
        var c = document.getElementById('sgi-toast-container');
        if (c) return c;
        c = document.createElement('div');
        c.id = 'sgi-toast-container';
        c.className = 'toast-container position-fixed top-0 end-0 p-3';
        c.style.zIndex = '1090';
        document.body.appendChild(c);
        return c;
    }

    function showToast(message, variant) {
        if (!global.bootstrap || !global.bootstrap.Toast) {
            // Fallback solo si Bootstrap no cargó (no debería ocurrir en SGI).
            global.alert(message);
            return;
        }
        variant = variant || 'danger';
        var container = ensureToastContainer();
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + variant + ' border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML =
            '<div class="d-flex">' +
              '<div class="toast-body"></div>' +
              '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>' +
            '</div>';
        toastEl.querySelector('.toast-body').textContent = message;
        container.appendChild(toastEl);
        var t = new global.bootstrap.Toast(toastEl, { delay: 4500 });
        toastEl.addEventListener('hidden.bs.toast', function () { toastEl.remove(); });
        t.show();
    }

    function ensureConfirmModal() {
        var existing = document.getElementById('sgi-confirm-modal');
        if (existing) return existing;
        var html =
            '<div class="modal fade" id="sgi-confirm-modal" tabindex="-1" aria-hidden="true">' +
              '<div class="modal-dialog modal-dialog-centered">' +
                '<div class="modal-content">' +
                  '<div class="modal-header">' +
                    '<h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i><span data-slot="title">Confirmar</span></h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                  '</div>' +
                  '<div class="modal-body" data-slot="body"></div>' +
                  '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Cancelar</button>' +
                    '<button type="button" class="btn btn-danger" data-slot="ok">Eliminar</button>' +
                  '</div>' +
                '</div>' +
              '</div>' +
            '</div>';
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var node = wrap.firstElementChild;
        document.body.appendChild(node);
        return node;
    }

    function confirmDialog(message) {
        if (!global.bootstrap || !global.bootstrap.Modal) {
            return Promise.resolve(global.confirm(message));
        }
        var modalEl = ensureConfirmModal();
        modalEl.querySelector('[data-slot="body"]').textContent = message;
        var okBtn = modalEl.querySelector('[data-slot="ok"]');
        var modal = global.bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var resolved = false;
            function cleanup() {
                okBtn.removeEventListener('click', onOk);
                modalEl.removeEventListener('hidden.bs.modal', onHide);
            }
            function onOk() {
                resolved = true;
                cleanup();
                modal.hide();
                resolve(true);
            }
            function onHide() {
                if (!resolved) { cleanup(); resolve(false); }
            }
            okBtn.addEventListener('click', onOk);
            modalEl.addEventListener('hidden.bs.modal', onHide);
            modal.show();
        });
    }

    // ─── Row builder ─────────────────────────────────────────────────────────
    function setSlot(root, slot, value) {
        var el = root.querySelector('[data-slot="' + slot + '"]');
        if (!el) return null;
        el.textContent = value;
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
        if (openEl) openEl.href = safeRelativePath(doc.file_path);

        var deleteEl = clone.querySelector('[data-slot="delete-btn"]');
        if (deleteEl) {
            var safeDeleteUrl = safeRelativePath(doc.delete_url);
            if (doc.can_delete && safeDeleteUrl) {
                deleteEl.setAttribute('data-url', safeDeleteUrl);
                deleteEl.style.display = '';
            } else {
                deleteEl.style.display = 'none';
            }
        }

        return clone;
    }

    // CR-006: guard NaN cuando textContent no contiene dígitos.
    function updateCounter(el, delta) {
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var base = m ? parseInt(m[1], 10) : 0;
        var n = base + delta;
        if (n < 0 || isNaN(n)) n = 0;
        el.textContent = n + ' doc' + (n !== 1 ? 's' : '');
    }

    function init(opts) {
        var form         = document.querySelector(opts.formSelector);
        var list         = document.querySelector(opts.listSelector);
        var emptyState   = document.querySelector(opts.emptySelector);
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
                    showToast('El archivo supera el tamaño máximo de ' + maxLabel + '.', 'warning');
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
                        showToast(data.error || 'Error al subir el archivo.', 'danger');
                    }
                })
                .catch(function () { showToast('Error de conexión. Intente nuevamente.', 'danger'); })
                .finally(function () {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
                });
            });
        }

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.doc-delete-btn');
            if (!btn || !list.contains(btn)) return;
            e.preventDefault();

            confirmDialog('¿Eliminar este soporte? Esta acción no se puede deshacer.').then(function (ok) {
                if (!ok) return;

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
                        showToast(data.error || 'Error al eliminar.', 'danger');
                        btn.disabled = false;
                    }
                })
                .catch(function () {
                    showToast('Error de conexión. Intente nuevamente.', 'danger');
                    btn.disabled = false;
                });
            });
        });
    }

    global.SgiDocumentUploader = { init: init };
})(window);
