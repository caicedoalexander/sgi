/**
 * SGI Payment Section — shared JS module.
 *
 * Initialises on any container with [data-payment-section].
 * Handles: register payment (dynamic form POST), full-payment shortcut.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-payment-section]').forEach(function (section) {
        var addUrl = section.dataset.addUrl;
        var remainingAmount = parseFloat(section.dataset.remainingAmount) || 0;

        // ── Elements ──
        var btnRegister = section.querySelector('[data-btn-register]');
        var btnFullPay  = section.querySelector('[data-btn-full-pay]');
        var bankInput   = section.querySelector('[data-pay-bank]');
        var amountInput = section.querySelector('[data-pay-amount]');
        var dateInput   = section.querySelector('[data-pay-date]');
        var collapseEl  = section.querySelector('.collapse');

        // ── Register Payment ──
        if (btnRegister && addUrl) {
            btnRegister.addEventListener('click', function () {
                if (!bankInput.value || !amountInput.value || !dateInput.value) {
                    alert('Complete todos los campos del pago.');
                    return;
                }

                var rawAmount;
                try {
                    var an = AutoNumeric.getAutoNumericElement(amountInput);
                    rawAmount = an.getNumericString();
                } catch (e) {
                    rawAmount = amountInput.value.replace(/\$\s?/g, '').replace(/\./g, '').replace(',', '.');
                }

                _submitDynamicForm(addUrl, {
                    'banking_entity_id': bankInput.value,
                    'amount': rawAmount,
                    'payment_date': dateInput.value,
                });

                btnRegister.disabled = true;
            });
        }

        // ── Full Payment shortcut ──
        if (btnFullPay && remainingAmount > 0) {
            btnFullPay.addEventListener('click', function () {
                // Open the collapse if closed
                if (collapseEl) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
                    bsCollapse.show();
                }

                // Fill amount
                if (amountInput) {
                    try {
                        var an = AutoNumeric.getAutoNumericElement(amountInput);
                        an.set(remainingAmount);
                    } catch (e) {
                        amountInput.value = remainingAmount;
                    }
                }

                // Fill date with today
                if (dateInput) {
                    var today = new Date();
                    var y = today.getFullYear();
                    var m = String(today.getMonth() + 1).padStart(2, '0');
                    var d = String(today.getDate()).padStart(2, '0');
                    var todayStr = y + '-' + m + '-' + d;

                    if (dateInput._flatpickr) {
                        dateInput._flatpickr.setDate(todayStr, true);
                    } else {
                        dateInput.value = todayStr;
                    }
                }

                // Focus on bank selector
                if (bankInput) {
                    bankInput.focus();
                }
            });
        }
    });

    /**
     * Create a hidden form and submit it (avoids nested-form issues).
     */
    function _submitDynamicForm(action, fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        form.style.display = 'none';

        // CSRF token
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
