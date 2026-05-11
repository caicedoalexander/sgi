/**
 * SGI Common JS - Flatpickr + AutoNumeric + Row click + Modal AJAX loader
 */

window.SGI_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
window.SGI_MAX_UPLOAD_LABEL = '20 MB';

// Global guard: bloquea el submit de cualquier formulario con un archivo >20 MB
// para evitar el 413 de nginx. No interfiere con validaciones locales más estrictas
// (DIAN 10 MB, Leaves 5 MB) porque esas se ejecutan en sus propios handlers.
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.querySelectorAll) return;
    var fileInputs = form.querySelectorAll('input[type="file"]');
    if (!fileInputs.length) return;
    var max = window.SGI_MAX_UPLOAD_BYTES;
    var label = window.SGI_MAX_UPLOAD_LABEL;
    for (var i = 0; i < fileInputs.length; i++) {
        var files = fileInputs[i].files || [];
        for (var j = 0; j < files.length; j++) {
            if (files[j].size > max) {
                e.preventDefault();
                e.stopPropagation();
                alert('El archivo "' + files[j].name + '" supera el tamaño máximo de ' + label + '.');
                return;
            }
        }
    }
}, true);

/**
 * Inicializa Flatpickr/AutoNumeric/Select2 dentro de un sub-árbol del DOM.
 * Llamado en DOMContentLoaded (root=document) y tras inyectar HTML por AJAX
 * en un modal (audit SU-003).
 */
window.sgiInit = function (root) {
    root = root || document;

    if (typeof flatpickr !== 'undefined') {
        if (flatpickr.l10ns && flatpickr.l10ns.es) {
            flatpickr.localize(flatpickr.l10ns.es);
        }
        root.querySelectorAll('input.flatpickr-date').forEach(function (el) {
            if (el._flatpickr) return; // evita doble init
            flatpickr(el, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd/m/Y',
                locale: 'es',
                animate: true,
                allowInput: true,
            });
        });
    }

    if (typeof AutoNumeric !== 'undefined') {
        root.querySelectorAll('input.currency-input').forEach(function (el) {
            if (AutoNumeric.getAutoNumericElement && AutoNumeric.getAutoNumericElement(el)) return;
            new AutoNumeric(el, {
                digitGroupSeparator: '.',
                decimalCharacter: ',',
                currencySymbol: '$ ',
                currencySymbolPlacement: 'p',
                decimalPlaces: 0,
                unformatOnSubmit: true,
                modifyValueOnUpDownArrow: false,
            });
        });
    }

    if (typeof $ !== 'undefined' && $.fn && $.fn.select2) {
        $(root).find('select.select2-enable').each(function () {
            if ($(this).hasClass('select2-hidden-accessible')) return;
            var $modal = $(this).closest('.modal');
            $(this).select2({
                width: '100%',
                language: 'es',
                minimumResultsForSearch: 7,
                dropdownParent: $modal.length ? $modal : $(document.body),
            });
        });
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.sgiInit(document);

    // ── Tooltips Bootstrap en botones deshabilitados con title ──
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('button[disabled][title], a.disabled[title]').forEach(function (el) {
            var wrapper = document.createElement('span');
            wrapper.setAttribute('data-bs-toggle', 'tooltip');
            wrapper.setAttribute('title', el.getAttribute('title'));
            wrapper.style.display = 'inline-block';
            el.parentNode.insertBefore(wrapper, el);
            wrapper.appendChild(el);
            new bootstrap.Tooltip(wrapper);
        });
    }

    // ── Sidebar: mantener --sidebar-width sincronizado con el ancho real ──────
    // Maneja resizes runtime (fuentes que cargan, colapso/expansión de subnav, resize
    // de ventana). El sync inicial síncrono al primer paint vive en default.php (inline
    // script justo después de </nav>) — necesario porque ResizeObserver dispara su
    // callback inicial async, dejando .content-wrapper con el fallback 260px hasta
    // que pasa el primer animation frame post-DOMContentLoaded.
    (function () {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;
        function syncWidth() {
            document.documentElement.style.setProperty('--sidebar-width', sidebar.offsetWidth + 'px');
        }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(syncWidth);
        }
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(syncWidth).observe(sidebar);
        }
    })();

    // ── Sidebar: persistir estado de todos los collapses con localStorage ────
    document.querySelectorAll('.sidebar .sidebar-chevron-btn[data-bs-target]').forEach(function (btn) {
        var targetId = btn.getAttribute('data-bs-target').replace('#', '');
        var collapseEl = document.getElementById(targetId);
        if (!collapseEl) return;

        var storageKey = 'sgi_sidebar_' + targetId;
        var stored = localStorage.getItem(storageKey);
        var serverShow = collapseEl.classList.contains('show');

        if (!serverShow && stored === 'show') {
            collapseEl.classList.add('show');
            btn.setAttribute('aria-expanded', 'true');
        } else if (serverShow && stored === 'hide') {
            collapseEl.classList.remove('show');
            btn.setAttribute('aria-expanded', 'false');
        }

        collapseEl.addEventListener('show.bs.collapse', function () {
            localStorage.setItem(storageKey, 'show');
            btn.setAttribute('aria-expanded', 'true');
        });
        collapseEl.addEventListener('hide.bs.collapse', function () {
            localStorage.setItem(storageKey, 'hide');
            btn.setAttribute('aria-expanded', 'false');
        });
    });

    // ── Auto-dismiss flash notifications ────────────────────────────────────
    document.querySelectorAll('#sgi-flash-container .alert').forEach(function (el) {
        setTimeout(function () {
            bootstrap.Alert.getOrCreateInstance(el).close();
        }, 4000);
    });

    // ── Click en fila/card para navegar ─────────────────────────────────────
    document.querySelectorAll('.clickable-row').forEach(function (el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form')) return;
            var href = this.dataset.href;
            if (href) window.location.href = href;
        });
    });

    // ── Modal AJAX loader (audit SU-003) ─────────────────────────────────────
    // Cualquier modal con data-load-url carga su .modal-content vía fetch al
    // momento de abrirse. Evita cargas de queries pesadas en el render del
    // template host. Filtros internos (forms con data-modal-filter-form) se
    // interceptan para refrescar solo el cuerpo del modal sin recargar página.
    document.addEventListener('show.bs.modal', function (ev) {
        var modal = ev.target;
        var url = modal.dataset.loadUrl;
        if (!url) return;
        // Solo cargar la primera vez. Si ya tiene contenido, dejarlo.
        if (modal.dataset.loaded === '1') return;
        var content = modal.querySelector('.modal-content');
        if (!content) return;
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                content.innerHTML = html;
                modal.dataset.loaded = '1';
                window.sgiInit(content);
            })
            .catch(function (err) {
                content.innerHTML = '<div class="modal-body text-danger text-center py-4">' +
                    '<i class="bi bi-exclamation-triangle me-1"></i>No se pudo cargar el contenido. ' +
                    'Cierra y vuelve a abrir el modal.</div>';
                console.error('Modal AJAX load failed:', err);
            });
    });

    // Reset del flag al cerrar para forzar recarga la próxima vez (datos frescos).
    document.addEventListener('hidden.bs.modal', function (ev) {
        if (ev.target.dataset.loadUrl) {
            ev.target.dataset.loaded = '';
        }
    });

    // Submit del form de filtros dentro del modal: fetch + reemplazo del cuerpo.
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.matches('[data-modal-filter-form]')) return;
        var modal = form.closest('.modal');
        if (!modal) return;
        ev.preventDefault();
        var content = modal.querySelector('.modal-content');
        if (!content) return;
        var url = form.action + '?' + new URLSearchParams(new FormData(form));
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                content.innerHTML = html;
                window.sgiInit(content);
            });
    });
});
