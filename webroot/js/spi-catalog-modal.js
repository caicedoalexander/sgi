/**
 * SpiCatalogModal — CRUD de catálogos en modal con submit AJAX.
 *
 * Los triggers (botón "Nuevo …" y los lápices de cada fila) llevan
 * [data-catalog-modal] y un href a la acción add/edit. Al hacer click se carga
 * el form vía AJAX dentro de #catalogModalContent y se envía por fetch:
 *   - Respuesta JSON {success:true}  → recarga el index (el flash de sesión
 *     muestra el toast del Sistema de Diseño).
 *   - Respuesta HTML (status 422)    → reemplaza el form con los errores inline.
 *
 * El _csrfToken viaja en el FormData (lo agrega Form->create), así que el
 * CsrfProtectionMiddleware lo acepta sin header extra.
 *
 * Si el JS no carga, los triggers siguen siendo enlaces normales a la página
 * add/edit (mejora progresiva).
 */
(function (global) {
    'use strict';

    function init() {
        var modalEl = document.getElementById('catalogModal');
        if (!modalEl || !global.bootstrap) return;

        var content = document.getElementById('catalogModalContent');
        var modal = global.bootstrap.Modal.getOrCreateInstance(modalEl);

        function setLoading(btn, loading, original) {
            if (!btn) return;
            btn.disabled = loading;
            if (loading) {
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
            } else {
                btn.innerHTML = original;
            }
        }

        function bindForm() {
            var form = content.querySelector('form');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                var original = btn ? btn.innerHTML : '';
                setLoading(btn, true, original);

                fetch(form.getAttribute('action') || global.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new FormData(form),
                }).then(function (resp) {
                    var ct = resp.headers.get('content-type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return resp.json().then(function (data) {
                            if (data && data.success) {
                                global.location.reload();
                            } else {
                                setLoading(btn, false, original);
                            }
                        });
                    }
                    // HTML = form re-renderizado con errores de validación.
                    return resp.text().then(function (html) {
                        content.innerHTML = html;
                        if (global.spiInit) global.spiInit(content);
                        bindForm();
                    });
                }).catch(function () {
                    setLoading(btn, false, original);
                });
            });
        }

        function open(url) {
            content.innerHTML = '<div class="modal-body" style="padding:40px;text-align:center;">'
                + '<span class="spinner-border" role="status" aria-label="Cargando"></span></div>';
            modal.show();
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (resp) { return resp.text(); })
                .then(function (html) {
                    content.innerHTML = html;
                    if (global.spiInit) global.spiInit(content);
                    bindForm();
                })
                .catch(function () {
                    content.innerHTML = '<div class="modal-body" style="padding:24px;">'
                        + '<div class="banner danger"><div class="banner-body">'
                        + '<div class="banner-title">No se pudo cargar el formulario</div>'
                        + '<div class="banner-msg">Revisa tu conexión e intenta de nuevo.</div></div></div></div>';
                });
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-catalog-modal]');
            if (!trigger) return;
            e.preventDefault();
            open(trigger.getAttribute('href') || trigger.getAttribute('data-catalog-modal'));
        });
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }

    global.SpiCatalogModal = { init: init };
})(window);
