/**
 * Alpine chart for the home dashboard. Chart.js is loaded only on pages that draw a chart.
 * The config comes from the server; `links` (one URL per bar or slice) opens the report behind it.
 */
export function dashboardChart(config, loadChart = () => import('chart.js')) {
    let chart = null;

    return {
        async init() {
            const { Chart, registerables } = await loadChart();
            Chart.register(...registerables);
            Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

            const { links = [], ...chartConfig } = config;
            chartConfig.options = {
                responsive: true,
                maintainAspectRatio: false,
                ...chartConfig.options,
                plugins: {
                    ...chartConfig.options?.plugins,
                    legend: { rtl: true, ...chartConfig.options?.plugins?.legend },
                    tooltip: { rtl: true, ...chartConfig.options?.plugins?.tooltip },
                },
                onClick: (_event, elements) => {
                    const url = links[elements[0]?.index];
                    if (!url) return;
                    window.Livewire?.navigate ? window.Livewire.navigate(url) : (window.location.href = url);
                },
                onHover: (event, elements) => {
                    event.native.target.style.cursor = elements.length && links[elements[0].index] ? 'pointer' : 'default';
                },
            };
            chart = new Chart(this.$refs.canvas, chartConfig);
        },
        destroy() {
            chart?.destroy();
            chart = null;
        },
    };
}

if (typeof window !== 'undefined') window.dashboardChart = dashboardChart;
