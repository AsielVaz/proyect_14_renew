(() => {
    'use strict';

    const money = new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        maximumFractionDigits: 2,
    });
    const number = new Intl.NumberFormat('es-MX', {maximumFractionDigits: 2});
    const colors = ['#ef6c22', '#5c63d8', '#18a46f', '#d84f78', '#2f86c7', '#a87418'];

    const formatValue = (value, format) => {
        const numericValue = Number(value || 0);
        if (format === 'money') {
            const absolute = Math.abs(numericValue);
            if (absolute >= 1000000) return '$' + number.format(numericValue / 1000000) + ' M';
            if (absolute >= 1000) return '$' + number.format(numericValue / 1000) + ' mil';
            return money.format(numericValue);
        }
        return number.format(numericValue);
    };

    document.querySelectorAll('.statistics-chart-plot[data-statistics-chart]').forEach((container) => {
        let data = {};
        try {
            data = JSON.parse(container.dataset.statisticsChart || '{}');
        } catch (error) {
            container.innerHTML = '<div class="statistics-chart-error">Los datos de la gráfica no son válidos.</div>';
            return;
        }

        if (typeof window.ApexCharts !== 'function') {
            container.innerHTML = '<div class="statistics-chart-error">No fue posible cargar ApexCharts.</div>';
            return;
        }

        const labels = Array.isArray(data.labels)
            ? data.labels.map((label) => /^\d{4}-\d{2}-\d{2}(?:[T\s]|$)/.test(String(label)) ? window.s14DisplayDate(label) : label)
            : [];
        const series = Array.isArray(data.series) ? data.series : [];
        const horizontal = series.length === 1 && labels.length > 8;
        const valueFormatter = (value) => formatValue(value, data.format);

        const chart = new window.ApexCharts(container, {
            chart: {
                type: 'bar',
                height: horizontal ? Math.max(390, labels.length * 34 + 80) : 400,
                fontFamily: 'inherit',
                foreColor: '#77737a',
                toolbar: {
                    show: true,
                    tools: {
                        download: true,
                        selection: false,
                        zoom: false,
                        zoomin: false,
                        zoomout: false,
                        pan: false,
                        reset: false,
                    },
                },
                animations: {enabled: true, easing: 'easeinout', speed: 450},
            },
            series,
            colors,
            plotOptions: {
                bar: {
                    horizontal,
                    borderRadius: 5,
                    columnWidth: series.length > 2 ? '72%' : '56%',
                    barHeight: '68%',
                },
            },
            dataLabels: {enabled: false},
            stroke: {show: true, width: 1, colors: ['transparent']},
            xaxis: {
                categories: labels,
                labels: {
                    rotate: labels.length > 7 && !horizontal ? -45 : 0,
                    trim: true,
                    formatter: horizontal ? valueFormatter : undefined,
                },
            },
            yaxis: {
                labels: {
                    maxWidth: horizontal ? 220 : 120,
                    formatter: horizontal ? undefined : valueFormatter,
                },
            },
            grid: {borderColor: 'rgba(125, 125, 140, .18)', strokeDashArray: 3},
            legend: {position: 'top', horizontalAlign: 'left'},
            tooltip: {shared: !horizontal, intersect: false, y: {formatter: valueFormatter}},
            noData: {
                text: 'No hay información en el periodo seleccionado.',
                align: 'center',
                verticalAlign: 'middle',
            },
            responsive: [{
                breakpoint: 640,
                options: {
                    chart: {toolbar: {show: false}},
                    legend: {position: 'bottom'},
                },
            }],
        });

        chart.render();
    });
})();
