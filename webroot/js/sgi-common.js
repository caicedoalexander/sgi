/**
 * SGI Common JS - Flatpickr + AutoNumeric + Row click
 */
document.addEventListener('DOMContentLoaded', function () {

    // ── Flatpickr para inputs de fecha ──────────────────────────────────────
    if (typeof flatpickr !== 'undefined') {
        flatpickr.localize(flatpickr.l10ns.es);

        document.querySelectorAll('input.flatpickr-date').forEach(function (el) {
            flatpickr(el, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'l, d F Y',
                locale: 'es',
                animate: true,
                allowInput: true,
            });
        });
    }

    // ── AutoNumeric para campo de monto COP ─────────────────────────────────
    if (typeof AutoNumeric !== 'undefined') {
        document.querySelectorAll('input.currency-input').forEach(function (el) {
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

    // ── Click en fila de tabla para editar ──────────────────────────────────
    document.querySelectorAll('tr.clickable-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var href = this.dataset.href;
            if (href) {
                window.location.href = href;
            }
        });
    });

});
