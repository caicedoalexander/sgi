/**
 * SGI Payment Section — shared JS module.
 *
 * Initialises on any container with [data-payment-section].
 * Handles:
 *   - register payment (advances invoice/record to autorizacion_pago)
 *   - full-payment checkbox
 *   - authorize / delete actions via .btn-post-action
 *   - reject payment with reason prompt via .btn-reject-payment
 */
(function () {
    'use strict';

    function findCsrfToken(section) {
        var form = section.closest('form');
        var input = form ? form.querySelector('input[name="_csrfToken"]') : null;
        if (input) return input.value;
        var any = document.querySelector('input[name="_csrfToken"]');
        return any ? any.value : '';
    }

    function wireAuthorizeRejectButtons(section) {
        // ── Generic POST action buttons (authorize, delete) ──
        section.querySelectorAll('.btn-post-action').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var msg = btn.getAttribute('data-confirm');
                var url = btn.getAttribute('data-url');
                if (!url) return;
                var go = msg
                    ? window.SgiDialogs.confirm(msg)
                    : Promise.resolve(true);
                go.then(function (ok) {
                    if (!ok) return;
                    btn.disabled = true;
                    _submitDynamicForm(url, {}, findCsrfToken(section));
                });
            });
        });

        // ── Reject payment: capture reason via prompt then POST ──
        section.querySelectorAll('.btn-reject-payment').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url');
                if (!url) return;
                window.SgiDialogs.prompt({
                    title: 'Rechazar pago',
                    message: 'Indique el motivo del rechazo del pago.',
                    placeholder: 'Describa por qué se rechaza este pago...',
                    minLength: 10,
                    maxLength: 500,
                    required: true,
                }).then(function (reason) {
                    if (!reason) return;
                    btn.disabled = true;
                    _submitDynamicForm(url, { 'reason': reason }, findCsrfToken(section));
                });
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-payment-section]').forEach(function (section) {
            if (section.dataset.paymentSectionInitialized === '1') {
                return;
            }
            section.dataset.paymentSectionInitialized = '1';

            // Authorize/Reject buttons are present regardless of register permission.
            wireAuthorizeRejectButtons(section);

            var addUrl = section.dataset.addUrl;
            var remainingAmount = parseFloat(section.dataset.remainingAmount) || 0;

            var btnRegisterAdvance = section.querySelector('[data-btn-register-advance]');
            var fullPayCheck       = section.querySelector('[data-pay-full]');
            var bankInput   = section.querySelector('[data-pay-bank]');
            var amountInput = section.querySelector('[data-pay-amount]');
            var dateInput   = section.querySelector('[data-pay-date]');

            if (!addUrl) {
                console.error('[sgi-payment] Falta data-add-url en la sección de pagos.');
                return;
            }
            if (!btnRegisterAdvance) {
                // No permission to register — nothing more to wire.
                return;
            }
            if (!bankInput || !amountInput || !dateInput) {
                console.error('[sgi-payment] Faltan inputs de pago (bank/amount/date).');
                return;
            }

            function getRawAmount() {
                try {
                    if (typeof AutoNumeric !== 'undefined') {
                        var an = AutoNumeric.getAutoNumericElement(amountInput);
                        if (an) {
                            return an.getNumericString();
                        }
                    }
                } catch (e) { /* fallback below */ }
                return String(amountInput.value || '')
                    .replace(/\$\s?/g, '')
                    .replace(/\./g, '')
                    .replace(',', '.');
            }

            function setAmount(val) {
                try {
                    if (typeof AutoNumeric !== 'undefined') {
                        var an = AutoNumeric.getAutoNumericElement(amountInput);
                        if (an) {
                            an.set(val);
                            return;
                        }
                    }
                } catch (e) { /* fallback below */ }
                amountInput.value = val;
            }

            function clearAmount() {
                setAmount('');
            }

            function validate() {
                var raw = getRawAmount();
                if (!bankInput.value || !raw || parseFloat(raw) <= 0 || !dateInput.value) {
                    window.SgiDialogs.toast('Complete todos los campos del pago (entidad, monto y fecha).', 'warning');
                    return false;
                }
                return true;
            }

            function submitPayment() {
                if (!validate()) return;
                var fields = {
                    'banking_entity_id': bankInput.value,
                    'amount': getRawAmount(),
                    'payment_date': dateInput.value,
                };
                if (fullPayCheck && fullPayCheck.checked) {
                    fields['full_payment'] = '1';
                }
                if (section.dataset.idempotencyKey) {
                    fields['idempotency_key'] = section.dataset.idempotencyKey;
                }
                _submitDynamicForm(addUrl, fields, findCsrfToken(section));
            }

            btnRegisterAdvance.addEventListener('click', function (e) {
                e.preventDefault();
                if (!validate()) return;
                window.SgiDialogs.confirm('Este pago se registrará y la factura pasará inmediatamente al estado de Autorización de Pago. ¿Continuar?').then(function (ok) {
                    if (!ok) return;
                    btnRegisterAdvance.disabled = true;
                    submitPayment();
                });
            });

            if (fullPayCheck && amountInput) {
                fullPayCheck.addEventListener('change', function () {
                    if (fullPayCheck.checked) {
                        setAmount(remainingAmount);
                        amountInput.setAttribute('readonly', 'readonly');
                    } else {
                        amountInput.removeAttribute('readonly');
                        clearAmount();
                        amountInput.focus();
                    }
                });
            }
        });
    }

    function _submitDynamicForm(action, fields, csrfValue) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        form.style.display = 'none';

        if (csrfValue) {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_csrfToken';
            csrfInput.value = csrfValue;
            form.appendChild(csrfInput);
        }

        for (var key in fields) {
            if (!Object.prototype.hasOwnProperty.call(fields, key)) continue;
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
