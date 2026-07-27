/**
 * SPI Dialogs — sustitutos basados en Bootstrap Modal para confirm/prompt/alert.
 *
 * Expone:
 *   - SpiDialogs.confirm(message)                  → Promise<boolean>
 *   - SpiDialogs.prompt({title, message, minLength, placeholder, required})
 *                                                  → Promise<string|null>
 *   - SpiDialogs.toast(message, type)              → void  (alert temporal estilo bootstrap)
 *
 * Fallback: si Bootstrap no está cargado, cae a window.confirm/prompt/alert.
 */
(function (global) {
    'use strict';

    function ensureModal(id, builder) {
        var el = document.getElementById(id);
        if (el) return el;
        el = document.createElement('div');
        el.id = id;
        el.className = 'modal fade';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = builder();
        document.body.appendChild(el);
        return el;
    }

    function confirmDialog(message) {
        if (!global.bootstrap || !global.bootstrap.Modal) {
            return Promise.resolve(global.confirm(message));
        }
        var modalEl = ensureModal('spiConfirmModal', function () {
            return '' +
                '<div class="modal-dialog modal-dialog-centered modal-sm">' +
                '  <div class="modal-content">' +
                '    <div class="modal-body">' +
                '      <div class="d-flex align-items-start gap-2">' +
                '        <i class="bi bi-question-circle text-warning fs-4"></i>' +
                '        <div data-slot="body" style="font-size:.9rem;"></div>' +
                '      </div>' +
                '    </div>' +
                '    <div class="modal-footer py-2">' +
                '      <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancelar</button>' +
                '      <button type="button" class="btn btn-sm btn-warning" data-slot="ok">Aceptar</button>' +
                '    </div>' +
                '  </div>' +
                '</div>';
        });
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

    function promptDialog(opts) {
        opts = opts || {};
        var title       = opts.title       || 'Información requerida';
        var message     = opts.message     || '';
        var placeholder = opts.placeholder || '';
        var minLength   = parseInt(opts.minLength, 10) || 0;
        var required    = opts.required !== false;
        var maxLength   = parseInt(opts.maxLength, 10) || 500;

        if (!global.bootstrap || !global.bootstrap.Modal) {
            var val = global.prompt(message || title);
            if (val === null) return Promise.resolve(null);
            val = val.trim();
            if (required && !val) { global.alert('Debe indicar un valor.'); return Promise.resolve(null); }
            if (minLength && val.length < minLength) {
                global.alert('Mínimo ' + minLength + ' caracteres.');
                return Promise.resolve(null);
            }
            return Promise.resolve(val);
        }

        var modalEl = ensureModal('spiPromptModal', function () {
            return '' +
                '<div class="modal-dialog modal-dialog-centered">' +
                '  <div class="modal-content">' +
                '    <div class="modal-header">' +
                '      <h5 class="modal-title" data-slot="title"></h5>' +
                '      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>' +
                '    </div>' +
                '    <div class="modal-body">' +
                '      <p class="mb-2" data-slot="message" style="font-size:.875rem;"></p>' +
                '      <textarea class="form-control" rows="4" data-slot="input"></textarea>' +
                '      <div class="form-text" data-slot="hint"></div>' +
                '    </div>' +
                '    <div class="modal-footer py-2">' +
                '      <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancelar</button>' +
                '      <button type="button" class="btn btn-sm btn-warning" data-slot="ok" disabled>Aceptar</button>' +
                '    </div>' +
                '  </div>' +
                '</div>';
        });

        modalEl.querySelector('[data-slot="title"]').textContent = title;
        var msgEl = modalEl.querySelector('[data-slot="message"]');
        msgEl.textContent = message;
        msgEl.style.display = message ? '' : 'none';

        var input = modalEl.querySelector('[data-slot="input"]');
        input.value = '';
        input.placeholder = placeholder;
        input.setAttribute('maxlength', String(maxLength));

        var hint = modalEl.querySelector('[data-slot="hint"]');
        if (minLength) {
            hint.textContent = 'Mín. ' + minLength + ' caracteres · Máx. ' + maxLength + '.';
            hint.style.display = '';
        } else {
            hint.style.display = 'none';
        }

        var okBtn = modalEl.querySelector('[data-slot="ok"]');
        okBtn.disabled = required || minLength > 0;

        function onInput() {
            var val = input.value.trim();
            if (required && !val) { okBtn.disabled = true; return; }
            if (minLength && val.length < minLength) { okBtn.disabled = true; return; }
            okBtn.disabled = false;
        }

        var modal = global.bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var resolved = false;
            function cleanup() {
                okBtn.removeEventListener('click', onOk);
                input.removeEventListener('input', onInput);
                modalEl.removeEventListener('hidden.bs.modal', onHide);
            }
            function onOk() {
                resolved = true;
                cleanup();
                var val = input.value.trim();
                modal.hide();
                resolve(val);
            }
            function onHide() {
                if (!resolved) { cleanup(); resolve(null); }
            }
            input.addEventListener('input', onInput);
            okBtn.addEventListener('click', onOk);
            modalEl.addEventListener('hidden.bs.modal', onHide);
            modal.show();
            setTimeout(function () { input.focus(); }, 200);
        });
    }

    function toast(message, type) {
        if (global.SpiToast) {
            global.SpiToast.show(message, type || 'info');
            return;
        }
        global.alert(message);
    }

    global.SpiDialogs = {
        confirm: confirmDialog,
        prompt:  promptDialog,
        toast:   toast,
    };
})(window);
