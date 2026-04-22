/**
 * SGI Payment Section — shared JS module.
 *
 * Initialises on any container with [data-payment-section].
 * Handles: register payment (advances invoice to autorizacion_pago), full-payment checkbox.
 */
(function () {
    'use strict';

    function init() {
        document.querySelectorAll('[data-payment-section]').forEach(function (section) {
            if (section.dataset.paymentSectionInitialized === '1') {
                return;
            }
            section.dataset.paymentSectionInitialized = '1';

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
                // No permission to register — nothing to wire.
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
                    alert('Complete todos los campos del pago (entidad, monto y fecha).');
                    return false;
                }
                return true;
            }

            function findCsrfToken() {
                var input = section.closest('form')
                    ? section.closest('form').querySelector('input[name="_csrfToken"]')
                    : null;
                if (input) return input.value;
                var any = document.querySelector('input[name="_csrfToken"]');
                return any ? any.value : '';
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
                _submitDynamicForm(addUrl, fields, findCsrfToken());
            }

            btnRegisterAdvance.addEventListener('click', function (e) {
                e.preventDefault();
                if (!validate()) return;
                if (!confirm('Este pago se registrará y la factura pasará inmediatamente al estado de Autorización de Pago. ¿Continuar?')) return;
                btnRegisterAdvance.disabled = true;
                submitPayment();
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
