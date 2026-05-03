/**
 * SgiObservationChat
 * Helper unificado para chats de observaciones en módulos SGI
 * (Invoices, PaymentSchedulings, EmployeeNovelties, Employees,
 * PettyCashRecords, Refunds).
 *
 * Uso:
 *   SgiObservationChat.init({
 *     formSelector:           '#obs-form',
 *     listSelector:           '#obs-chat-scroll',
 *     emptySelector:          '#obs-empty-state',
 *     bubbleTemplateSelector: '#observation-bubble-template',
 *     csrfToken:              '<?= $this->request->getAttribute('csrfToken') ?>'
 *   });
 *
 * El contador se resuelve automáticamente desde el `.card` que contiene
 * `listSelector` (debe contener un `.sgi-folder-count`). Si no existe,
 * se crea uno al insertar la primera observación.
 *
 * Contrato JSON esperado:
 *   OK:    { success: true, observation: { id, message, user_name, created } }
 *   Fail:  { success: false, error: '...' }
 */
(function (global) {
    'use strict';

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

    function setSlot(root, slot, value) {
        var el = root.querySelector('[data-slot="' + slot + '"]');
        if (!el) return null;
        el.textContent = value;
        return el;
    }

    function setMessageWithBreaks(el, text) {
        if (!el) return;
        el.textContent = '';
        var parts = String(text == null ? '' : text).split(/\n/);
        for (var i = 0; i < parts.length; i++) {
            if (i > 0) el.appendChild(document.createElement('br'));
            el.appendChild(document.createTextNode(parts[i]));
        }
    }

    function buildBubble(template, obs) {
        var clone = template.content.firstElementChild.cloneNode(true);
        clone.classList.remove('is-other');
        clone.classList.add('is-mine');
        if (obs.id != null) clone.setAttribute('data-obs-id', obs.id);
        setSlot(clone, 'user_name', 'Tú');
        setMessageWithBreaks(clone.querySelector('[data-slot="message"]'), obs.message);
        setSlot(clone, 'created', obs.created || '');
        return clone;
    }

    function ensureCounter(listCard) {
        if (!listCard) return null;
        var existing = listCard.querySelector('.sgi-folder-count');
        if (existing) return existing;
        var header = listCard.querySelector('.card-header');
        if (!header) return null;
        var badge = document.createElement('span');
        badge.className = 'sgi-folder-count ms-auto';
        badge.textContent = '0';
        header.appendChild(badge);
        return badge;
    }

    function updateCounter(el, delta) {
        if (!el) return;
        var m = el.textContent.match(/(\d+)/);
        var base = m ? parseInt(m[1], 10) : 0;
        var n = base + delta;
        if (n < 0 || isNaN(n)) n = 0;
        el.textContent = String(n);
        if (n > 0) el.style.display = '';
    }

    function init(opts) {
        var form       = document.querySelector(opts.formSelector);
        var list       = document.querySelector(opts.listSelector);
        var emptyState = opts.emptySelector ? document.querySelector(opts.emptySelector) : null;
        var template   = document.querySelector(opts.bubbleTemplateSelector);
        var csrfToken  = opts.csrfToken || '';

        if (!form || !list || !template) return;

        var listCard = list.closest('.card');
        var counter  = ensureCounter(listCard);
        var textarea = form.querySelector('textarea[name="message"]');
        var submitBtn = form.querySelector('button[type="submit"]');

        list.scrollTop = list.scrollHeight;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var message = (textarea && textarea.value || '').trim();
            if (!message) return;

            if (submitBtn) submitBtn.disabled = true;

            var url = form.dataset.url || form.action;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                    'Accept': 'application/json'
                },
                body: new FormData(form)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (emptyState) emptyState.style.display = 'none';
                    list.appendChild(buildBubble(template, data.observation));
                    list.scrollTop = list.scrollHeight;
                    updateCounter(counter, 1);
                    if (textarea) {
                        textarea.value = '';
                        textarea.style.height = '';
                        textarea.focus();
                    }
                } else {
                    showToast(data.error || 'Error al agregar observación.', 'danger');
                }
            })
            .catch(function () {
                showToast('Error de conexión. Intente nuevamente.', 'danger');
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    global.SgiObservationChat = { init: init };
})(window);
