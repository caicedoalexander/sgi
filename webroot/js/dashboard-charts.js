/**
 * SGI Dashboard Charts
 * ApexCharts initialization from data-* attributes on div elements.
 */
(function () {
    'use strict';

    if (typeof ApexCharts === 'undefined') return;

    /* ── Color palettes ──────────────────────────────────────── */
    var STATUS_COLORS = {
        aprobacion:   '#ffc107',
        contabilidad: '#0dcaf0',
        tesoreria:    '#0d6efd',
        pagada:       '#469D61',
        rechazada:    '#dc3545'
    };

    var STATUS_LABELS = {
        aprobacion:   'Aprobación',
        contabilidad: 'Contabilidad',
        tesoreria:    'Tesorería',
        pagada:       'Pagada',
        rechazada:    'Rechazada'
    };

    var CONTRACT_COLORS = ['#469D61', '#CD6A15', '#83542B'];
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

    /* ── Shared base configs ─────────────────────────────────── */
    var fontFamily = "'Inter', system-ui, sans-serif";

    var donutBase = {
        chart: {
            type: 'donut',
            height: 300,
            fontFamily: fontFamily,
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 600,
                animateGradually: { enabled: true, delay: 80 }
            }
        },
        stroke: { width: 2, colors: ['#fff'] },
        plotOptions: {
            pie: {
                donut: {
                    size: '65%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            fontSize: '11px',
                            fontWeight: 600,
                            color: '#6c757d',
                            label: 'Total'
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
            markers: { size: 5, offsetX: -2 },
            itemMargin: { horizontal: 8, vertical: 4 }
        },
        dataLabels: {
            enabled: false
        },
        tooltip: {
            style: { fontSize: '12px' }
        }
    };

    var barBase = {
        chart: {
            type: 'bar',
            height: 300,
            fontFamily: fontFamily,
            toolbar: { show: false },
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 600,
                animateGradually: { enabled: true, delay: 60 }
            }
        },
        plotOptions: {
            bar: {
                borderRadius: 3,
                columnWidth: '55%'
            }
        },
        dataLabels: { enabled: false },
        grid: {
            borderColor: '#f0f0f0',
            strokeDashArray: 3,
            xaxis: { lines: { show: false } }
        },
        tooltip: {
            style: { fontSize: '12px' }
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
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                fontSize: '11px',
                                fontWeight: 600,
                                color: '#6c757d',
                                label: 'Total',
                                formatter: function (w) {
                                    var total = w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0);
                                    return formatCurrency(total);
                                }
                            },
                            value: {
                                fontSize: '16px',
                                fontWeight: 700,
                                color: '#212529',
                                formatter: function (val) { return formatCurrency(val); }
                            }
                        }
                    },
                    expandOnClick: false
                }
            },
            tooltip: {
                style: { fontSize: '12px' },
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
                height: 300
            }),
            stroke: {
                width: [0, 3],
                curve: 'smooth'
            },
            colors: ['#469D61', '#0d6efd'],
            fill: {
                type: ['solid', 'solid'],
                opacity: [0.85, 1]
            },
            plotOptions: {
                bar: {
                    borderRadius: 3,
                    columnWidth: '50%'
                }
            },
            xaxis: {
                categories: barLabels,
                labels: { style: { fontSize: '11px', colors: '#6c757d' } },
                axisBorder: { color: '#dee2e6' },
                axisTicks: { color: '#dee2e6' }
            },
            yaxis: [
                {
                    title: { text: 'Cantidad', style: { fontSize: '10px', fontWeight: 600, color: '#adb5bd' } },
                    labels: {
                        style: { fontSize: '11px', colors: '#6c757d' },
                        formatter: function (v) { return Math.round(v); }
                    },
                    forceNiceScale: true
                },
                {
                    opposite: true,
                    title: { text: 'Monto', style: { fontSize: '10px', fontWeight: 600, color: '#adb5bd' } },
                    labels: {
                        style: { fontSize: '11px', colors: '#6c757d' },
                        formatter: function (v) { return abbreviate(v); }
                    }
                }
            ],
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '11px',
                fontWeight: 500,
                markers: { size: 5, offsetX: -2 },
                itemMargin: { horizontal: 8 }
            },
            tooltip: {
                shared: true,
                intersect: false,
                style: { fontSize: '12px' },
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
            colors: cColors,
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                fontSize: '11px',
                                fontWeight: 600,
                                color: '#6c757d',
                                label: 'Total'
                            },
                            value: {
                                fontSize: '18px',
                                fontWeight: 700,
                                color: '#212529'
                            }
                        }
                    },
                    expandOnClick: false
                }
            }
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
                labels: { style: { fontSize: '11px', colors: '#6c757d' } },
                axisBorder: { color: '#dee2e6' },
                axisTicks: { color: '#dee2e6' }
            },
            yaxis: {
                labels: {
                    style: { fontSize: '11px', colors: '#6c757d' },
                    formatter: function (v) { return Math.round(v); }
                },
                forceNiceScale: true
            },
            legend: { show: false },
            tooltip: {
                style: { fontSize: '12px' },
                y: {
                    formatter: function (val) { return val + ' novedades'; }
                }
            }
        })).render();
    }

})();
