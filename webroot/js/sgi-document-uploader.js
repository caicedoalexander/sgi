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

    // Reglas MIME → ícono/color cargadas desde PHP (audit CR-202).
    // Fuente única: src/View/Helper/DocumentIconHelper::MIME_RULES, inyectado
    // por templates/layout/default.php como JSON inline. Fallback defensivo
    // por si el script no existe (layouts ajax/external que no extienden default).
    var DOC_ICON_RULES = (function () {
        var defaultRules = [
            { matches: ['pdf'],                            icon: 'bi-file-earmark-pdf',   color: '#dc3545' },
            { matches: ['image'],                          icon: 'bi-file-earmark-image', color: '#0dcaf0' },
            { matches: ['wordprocessingml', 'msword'],     icon: 'bi-file-earmark-word',  color: '#0d6efd' },
            { matches: ['spreadsheet', 'excel'],           icon: 'bi-file-earmark-excel', color: 'var(--primary-color)' },
            { matches: [],                                 icon: 'bi-file-earmark',       color: '#aaa' }
        ];
        try {
            var el = document.getElementById('sgi-doc-icon-rules');
            if (el) {
                var parsed = JSON.parse(el.textContent || '');
                if (parsed && Array.isArray(parsed.mime)) return parsed.mime;
            }
        } catch (e) { /* fall through to defaults */ }
        return defaultRules;
    })();

    function resolveByMime(mime) {
        mime = mime || '';
        for (var i = 0; i < DOC_ICON_RULES.length; i++) {
            var rule = DOC_ICON_RULES[i];
            if (!rule.matches || rule.matches.length === 0) return rule;
            for (var j = 0; j < rule.matches.length; j++) {
                if (mime.indexOf(rule.matches[j]) !== -1) return rule;
            }
        }
        return DOC_ICON_RULES[DOC_ICON_RULES.length - 1];
    }

    function docIconClass(mime) { return resolveByMime(mime).icon; }
    function docIconColor(mime) { return resolveByMime(mime).color; }

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

    // ─── Flash notification (alineado con el sistema Flash de SGI) ──────────
    // Reutiliza #sgi-flash-container del layout para que los mensajes AJAX
    // tengan el mismo estilo, posición y animación que los Flash de servidor
    // (templates/element/flash/*.php + #sgi-flash-container en styles.css).
    var FLASH_ICONS = {
        success: 'bi-check-circle',
        danger:  'bi-exclamation-triangle',
        warning: 'bi-exclamation-circle',
        info:    'bi-info-circle',
    };

    function ensureFlashContainer() {
        var c = document.getElementById('sgi-flash-container');
        if (c) return c;
        // Fallback: el layout no incluyó el container (no debería pasar en SGI).
        c = document.createElement('div');
        c.id = 'sgi-flash-container';
        document.body.appendChild(c);
        return c;
    }

    function showToast(message, variant) {
        variant = variant || 'danger';
        var icon = FLASH_ICONS[variant] || FLASH_ICONS.info;
        var container = ensureFlashContainer();
        var alertEl = document.createElement('div');
        alertEl.className = 'alert alert-' + variant + ' alert-dismissible fade show';
        alertEl.setAttribute('role', 'alert');
        alertEl.innerHTML =
            '<i class="bi ' + icon + ' me-2"></i>' +
            '<span data-slot="msg"></span>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>';
        alertEl.querySelector('[data-slot="msg"]').textContent = message;
        container.appendChild(alertEl);
        // Coincide con la animación sgi-flash-timer (4s) en styles.css.
        setTimeout(function () {
            if (!alertEl.parentNode) return;
            if (global.bootstrap && global.bootstrap.Alert) {
                global.bootstrap.Alert.getOrCreateInstance(alertEl).close();
            } else {
                alertEl.remove();
            }
        }, 4000);
    }

    // Delegado en SgiDialogs (sgi-dialogs.js). Mantenemos un fallback local
    // por si este script se carga sin el módulo de diálogos.
    function confirmDialog(message) {
        if (global.SgiDialogs && global.SgiDialogs.confirm) {
            return global.SgiDialogs.confirm(message);
        }
        return Promise.resolve(global.confirm(message));
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
                // badge_class viene como clase pill del Sistema de Diseño (e.g. "pill-warning-soft");
                // split por si llegan varias clases separadas por espacios.
                doc.badge_class.split(/\s+/).filter(Boolean).forEach(function (c) {
                    badgeEl.classList.add(c);
                });
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
                // SGI_MAX_UPLOAD_BYTES/LABEL los expone sgi-common.js leyendo
                // <meta name="sgi-max-upload-{bytes,label}"> inyectado por default.php.
                // Adicionalmente, sgi-common.js tiene un submit-listener global con
                // capture:true que valida cualquier <form> con file inputs — esta
                // validación local es para mostrar el toast inline antes del submit.
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
