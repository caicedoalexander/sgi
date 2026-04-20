/**
 * SGI Payment Section — shared JS module.
 *
 * Initialises on any container with [data-payment-section].
 * Handles: register payment with advance_after flag, full-payment checkbox.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-payment-section]').forEach(function (section) {
        var addUrl = section.dataset.addUrl;
        var remainingAmount = parseFloat(section.dataset.remainingAmount) || 0;

        var btnRegisterAdvance = section.querySelector('[data-btn-register-advance]');
        var btnRegisterOnly    = section.querySelector('[data-btn-register-only]');
        var fullPayCheck       = section.querySelector('[data-pay-full]');
        var bankInput   = section.querySelector('[data-pay-bank]');
        var amountInput = section.querySelector('[data-pay-amount]');
        var dateInput   = section.querySelector('[data-pay-date]');

        function getRawAmount() {
            try {
                var an = AutoNumeric.getAutoNumericElement(amountInput);
                return an.getNumericString();
            } catch (e) {
                return amountInput.value.replace(/\$\s?/g, '').replace(/\./g, '').replace(',', '.');
            }
        }

        function setAmount(val) {
            try {
                var an = AutoNumeric.getAutoNumericElement(amountInput);
                an.set(val);
            } catch (e) {
                amountInput.value = val;
            }
        }

        function validate() {
            if (!bankInput.value || !amountInput.value || !dateInput.value) {
                alert('Complete todos los campos del pago.');
                return false;
            }
            return true;
        }

        function submitPayment(advanceAfter) {
            if (!validate()) return;
            var fields = {
                'banking_entity_id': bankInput.value,
                'amount': getRawAmount(),
                'payment_date': dateInput.value,
            };
            if (advanceAfter) {
                fields['advance_after'] = '1';
            }
            if (fullPayCheck && fullPayCheck.checked) {
                fields['full_payment'] = '1';
            }
            _submitDynamicForm(addUrl, fields);
        }

        if (btnRegisterAdvance && addUrl) {
            btnRegisterAdvance.addEventListener('click', function () {
                if (!validate()) return;
                if (!confirm('Este pago se registrará y la factura pasará inmediatamente al estado de Autorización de Pago. ¿Continuar?')) return;
                btnRegisterAdvance.disabled = true;
                submitPayment(true);
            });
        }

        if (btnRegisterOnly && addUrl) {
            btnRegisterOnly.addEventListener('click', function () {
                btnRegisterOnly.disabled = true;
                submitPayment(false);
            });
        }

        if (fullPayCheck && amountInput) {
            fullPayCheck.addEventListener('change', function () {
                if (fullPayCheck.checked) {
                    setAmount(remainingAmount);
                    amountInput.setAttribute('readonly', 'readonly');
                } else {
                    amountInput.removeAttribute('readonly');
                }
            });
        }
    });

    function _submitDynamicForm(action, fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        form.style.display = 'none';

        var csrf = document.querySelector('input[name="_csrfToken"]');
        if (csrf) {
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_csrfToken';
            csrfInput.value = csrf.value;
            form.appendChild(csrfInput);
        }

        for (var key in fields) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    }
})();
