/**
 * SGI Dashboard Charts
 * ApexCharts initialization from data-* attributes on div elements.
 * Styled to match the SGI design system: no border-radius, border-based hierarchy,
 * Inter font, micro-caps labels, muted grid, corporate color palette.
 */
(function () {
    'use strict';

    if (typeof ApexCharts === 'undefined') return;

    /* ── Color palettes ──────────────────────────────────────── */
    /* Hex equivalentes a las clases Bootstrap usadas en
       App\View\Presentation\InvoicePresentation::STATUS_BADGES:
       bg-warning #ffc107, bg-primary #0d6efd, bg-info #0dcaf0,
       bg-success #198754, bg-danger #dc3545. */
    var STATUS_COLORS = {
        aprobacion:        '#ffc107',
        contabilidad:      '#0d6efd',
        tesoreria:         '#0dcaf0',
        autorizacion_pago: '#0dcaf0',
        verificacion_pago: '#ffc107',
        pagada:            '#198754',
        legalizada:        '#198754',
        rechazada:         '#dc3545'
    };

    var STATUS_LABELS = {
        aprobacion:        'Aprobación',
        contabilidad:      'Contabilidad',
        tesoreria:         'Tesorería',
        autorizacion_pago: 'Autorización de pago',
        verificacion_pago: 'Verificación de pago',
        pagada:            'Pagada',
        legalizada:        'Legalizada',
        rechazada:         'Rechazada'
    };

    var CONTRACT_COLORS = ['#469D61', '#CD6A15', '#83542B', '#3B82F6', '#8B5CF6'];
    var NOVELTY_COLOR = '#469D61';

    /* ── Helpers ──────────────────────────────────────────────── */
    function parseJSON(el, attr, fallback) {
        try {
            return JSON.parse(el.getAttribute(attr) || (Array.isArray(fallback) ? '[]' : '{}'));
        } catch (e) {
            return fallback;
        }
    }

    function formatCurrency(value) {
        return '$' + new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(value);
    }

    function formatMonth(ym) {
        var parts = ym.split('-');
        var months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
                      'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        return months[parseInt(parts[1], 10) - 1] + ' ' + parts[0].slice(2);
    }

    function abbreviate(v) {
        if (v >= 1000000) return '$' + (v / 1000000).toFixed(1) + 'M';
        if (v >= 1000) return '$' + (v / 1000).toFixed(0) + 'K';
        return '$' + v;
    }

    /* ── Shared SGI base configs ─────────────────────────────── */
    var fontFamily = "'Inter', system-ui, sans-serif";

    // SGI micro-caps style for axis labels
    var axisLabelStyle = {
        fontSize: '10px',
        fontWeight: 600,
        colors: '#999',
        fontFamily: fontFamily
    };

    var axisTitleStyle = {
        fontSize: '9px',
        fontWeight: 600,
        color: '#bbb',
        fontFamily: fontFamily
    };

    var donutBase = {
        chart: {
            type: 'donut',
            height: 280,
            fontFamily: fontFamily,
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 700,
                animateGradually: { enabled: true, delay: 100 }
            }
        },
        stroke: { width: 2, colors: ['#fff'] },
        plotOptions: {
            pie: {
                donut: {
                    size: '70%',
                    labels: {
                        show: true,
                        name: {
                            fontSize: '10px',
                            fontWeight: 600,
                            color: '#999',
                            offsetY: -4
                        },
                        value: {
                            fontSize: '18px',
                            fontWeight: 700,
                            color: '#212529',
                            offsetY: 4
                        },
                        total: {
                            show: true,
                            fontSize: '9px',
                            fontWeight: 600,
                            color: '#999',
                            label: 'TOTAL',
                            formatter: function (w) {
                                return w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                            }
                        }
                    }
                },
                expandOnClick: false
            }
        },
        legend: {
            position: 'bottom',
            fontSize: '11px',
            fontWeight: 500,
            fontFamily: fontFamily,
            labels: { colors: '#555' },
            markers: { size: 4, offsetX: -3, shape: 'square' },
            itemMargin: { horizontal: 10, vertical: 3 }
        },
        dataLabels: {
            enabled: false
        },
        tooltip: {
            style: { fontSize: '11px', fontFamily: fontFamily },
            fillSeriesColor: false,
            marker: { show: true }
        }
    };

    var barBase = {
        chart: {
            type: 'bar',
            height: 280,
            fontFamily: fontFamily,
            toolbar: { show: false },
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 700,
                animateGradually: { enabled: true, delay: 80 }
            }
        },
        plotOptions: {
            bar: {
                borderRadius: 0,
                columnWidth: '50%'
            }
        },
        dataLabels: { enabled: false },
        grid: {
            borderColor: '#eee',
            strokeDashArray: 0,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
            padding: { top: -8, bottom: 0 }
        },
        tooltip: {
            style: { fontSize: '11px', fontFamily: fontFamily },
            marker: { show: true }
        }
    };

    /* ── 1. Invoice Donut (amount by pipeline status) ────────── */
    var donutEl = document.getElementById('invoiceDonutChart');
    if (donutEl) {
        var donutData = parseJSON(donutEl, 'data-chart-donut', {});
        var labels = [];
        var values = [];
        var colors = [];

        Object.keys(donutData).forEach(function (key) {
            labels.push(STATUS_LABELS[key] || key);
            values.push(donutData[key]);
            colors.push(STATUS_COLORS[key] || '#adb5bd');
        });

        new ApexCharts(donutEl, Object.assign({}, donutBase, {
            series: values,
            labels: labels,
            colors: colors,
            plotOptions: {
                pie: {
                    donut: {
                        size: '70%',
                        labels: {
                            show: true,
                            name: {
                                fontSize: '10px',
                                fontWeight: 600,
                                color: '#999',
                                offsetY: -4
                            },
                            value: {
                                fontSize: '16px',
                                fontWeight: 700,
                                color: '#212529',
                                offsetY: 4,
                                formatter: function (val) { return formatCurrency(val); }
                            },
                            total: {
                                show: true,
                                fontSize: '9px',
                                fontWeight: 600,
                                color: '#999',
                                label: 'TOTAL',
                                formatter: function (w) {
                                    var total = w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                                    return formatCurrency(total);
                                }
                            }
                        }
                    },
                    expandOnClick: false
                }
            },
            tooltip: {
                style: { fontSize: '11px', fontFamily: fontFamily },
                fillSeriesColor: false,
                y: {
                    formatter: function (val) { return formatCurrency(val); }
                }
            }
        })).render();
    }

    /* ── 2. Invoice Bar (monthly count + amount) ─────────────── */
    var barEl = document.getElementById('invoiceBarChart');
    if (barEl) {
        var monthlyData = parseJSON(barEl, 'data-chart-monthly', []);

        var barLabels = monthlyData.map(function (r) { return formatMonth(r.month); });
        var barCounts = monthlyData.map(function (r) { return parseInt(r.count, 10); });
        var barTotals = monthlyData.map(function (r) { return parseFloat(r.total); });

        new ApexCharts(barEl, Object.assign({}, barBase, {
            series: [
                { name: 'Cantidad', data: barCounts, type: 'bar' },
                { name: 'Monto', data: barTotals, type: 'line' }
            ],
            chart: Object.assign({}, barBase.chart, {
                type: 'line',
                height: 280
            }),
            stroke: {
                width: [0, 2],
                curve: 'straight',
                dashArray: [0, 0]
            },
            colors: ['#469D61', '#212529'],
            fill: {
                type: ['solid', 'solid'],
                opacity: [0.85, 1]
            },
            plotOptions: {
                bar: {
                    borderRadius: 0,
                    columnWidth: '45%'
                }
            },
            markers: {
                size: [0, 3],
                colors: ['#469D61', '#212529'],
                strokeColors: '#fff',
                strokeWidth: 2,
                shape: 'square'
            },
            xaxis: {
                categories: barLabels,
                labels: { style: axisLabelStyle },
                axisBorder: { show: true, color: '#e0e0e0' },
                axisTicks: { show: true, color: '#e0e0e0' }
            },
            yaxis: [
                {
                    title: { text: 'CANTIDAD', style: axisTitleStyle },
                    labels: {
                        style: axisLabelStyle,
                        formatter: function (v) { return Math.round(v); }
                    },
                    forceNiceScale: true
                },
                {
                    opposite: true,
                    title: { text: 'MONTO', style: axisTitleStyle },
                    labels: {
                        style: axisLabelStyle,
                        formatter: function (v) { return abbreviate(v); }
                    }
                }
            ],
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '10px',
                fontWeight: 600,
                fontFamily: fontFamily,
                labels: { colors: '#777' },
                markers: { size: 4, offsetX: -3, shape: 'square' },
                itemMargin: { horizontal: 10 }
            },
            tooltip: {
                shared: true,
                intersect: false,
                style: { fontSize: '11px', fontFamily: fontFamily },
                y: {
                    formatter: function (val, opts) {
                        if (opts.seriesIndex === 1) return formatCurrency(val);
                        return val + ' facturas';
                    }
                }
            }
        })).render();
    }

    /* ── 3. Employee Donut (contract type) ────────────────────── */
    var contractEl = document.getElementById('employeeDonutChart');
    if (contractEl) {
        var contractData = parseJSON(contractEl, 'data-chart-contract', {});
        var cLabels = [];
        var cValues = [];
        var cColors = [];
        var ci = 0;

        Object.keys(contractData).forEach(function (key) {
            cLabels.push(key);
            cValues.push(contractData[key]);
            cColors.push(CONTRACT_COLORS[ci % CONTRACT_COLORS.length]);
            ci++;
        });

        new ApexCharts(contractEl, Object.assign({}, donutBase, {
            series: cValues,
            labels: cLabels,
            colors: cColors
        })).render();
    }

    /* ── 4. Novelty Bar (monthly count) ──────────────────────── */
    var noveltyEl = document.getElementById('noveltyBarChart');
    if (noveltyEl) {
        var noveltyData = parseJSON(noveltyEl, 'data-chart-novelties', []);

        var nLabels = noveltyData.map(function (r) { return formatMonth(r.month); });
        var nValues = noveltyData.map(function (r) { return parseInt(r.count, 10); });

        new ApexCharts(noveltyEl, Object.assign({}, barBase, {
            series: [{ name: 'Novedades', data: nValues }],
            colors: [NOVELTY_COLOR],
            xaxis: {
                categories: nLabels,
                labels: { style: axisLabelStyle },
                axisBorder: { show: true, color: '#e0e0e0' },
                axisTicks: { show: true, color: '#e0e0e0' }
            },
            yaxis: {
                labels: {
                    style: axisLabelStyle,
                    formatter: function (v) { return Math.round(v); }
                },
                forceNiceScale: true
            },
            legend: { show: false },
            tooltip: {
                style: { fontSize: '11px', fontFamily: fontFamily },
                y: {
                    formatter: function (val) { return val + ' novedades'; }
                }
            }
        })).render();
    }

})();
