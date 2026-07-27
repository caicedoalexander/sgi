/**
 * SPI Calendar — shared FullCalendar initializer.
 *
 * Usage:
 *   SPICalendar.init({
 *       el:        '#calendar',
 *       eventsUrl: '/employee-novelties/active-events',
 *       filters: {
 *           novelty_type_id: '#filter-novelty-type',
 *           employee_id:     '#filter-employee',
 *           pipeline_status: '#cal-filter-status'   // optional
 *       },
 *       clearBtn: '#btn-clear-filters'
 *   });
 *
 * Returns the FullCalendar instance.
 */
var SPICalendar = (function () {
    'use strict';

    function init(opts) {
        var calEl = document.querySelector(opts.el);
        if (!calEl) return null;

        var filterEls = {};
        var filterKeys = [];
        if (opts.filters) {
            Object.keys(opts.filters).forEach(function (key) {
                var el = document.querySelector(opts.filters[key]);
                if (el) {
                    filterEls[key] = el;
                    filterKeys.push(key);
                }
            });
        }

        var clearBtn = opts.clearBtn ? document.querySelector(opts.clearBtn) : null;

        function updateClearBtn() {
            if (!clearBtn) return;
            var hasValue = filterKeys.some(function (k) { return filterEls[k].value; });
            clearBtn.style.display = hasValue ? '' : 'none';
        }

        var calendar = new FullCalendar.Calendar(calEl, {
            initialView: 'dayGridMonth',
            locale: 'es',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: ''
            },
            buttonText: { today: 'Hoy' },
            height: 'auto',
            dayMaxEvents: 3,
            events: function (info, successCallback, failureCallback) {
                var params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr
                });
                filterKeys.forEach(function (k) {
                    if (filterEls[k].value) params.append(k, filterEls[k].value);
                });
                fetch(opts.eventsUrl + '?' + params.toString())
                    .then(function (r) { return r.json(); })
                    .then(function (data) { successCallback(data); })
                    .catch(function (err) { failureCallback(err); });
            },
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                if (info.event.url) window.location.href = info.event.url;
            },
            dateClick: opts.dateClick || undefined,
            eventsSet: opts.onEventsSet || undefined,
            eventDisplay: 'block',
            displayEventTime: false
        });

        calendar.render();

        // Re-append spi-calendar CSS so it comes AFTER FullCalendar's injected styles
        var calCss = document.querySelector('link[href*="spi-calendar"]');
        if (calCss) document.head.appendChild(calCss);

        // Bind filter changes (a menos que el caller maneje los cambios él mismo,
        // p.ej. cuando los filtros son select2 y/o se comparten con una vista lista).
        if (opts.bindFilterChange !== false) {
            filterKeys.forEach(function (k) {
                filterEls[k].addEventListener('change', function () {
                    calendar.refetchEvents();
                    updateClearBtn();
                });
            });
        }

        // Clear button
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                filterKeys.forEach(function (k) {
                    var el = filterEls[k];
                    if (typeof $ !== 'undefined' && $(el).hasClass('select2')) {
                        $(el).val('').trigger('change');
                    } else {
                        el.value = '';
                    }
                });
                calendar.refetchEvents();
                updateClearBtn();
            });
        }

        return calendar;
    }

    return { init: init };
})();
