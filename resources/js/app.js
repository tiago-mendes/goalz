import { Chart } from 'chart.js/auto';

const accountDistributionPalette = [
    '#315D40',
    '#2563EB',
    '#7C3AED',
    '#B45309',
    '#0F766E',
    '#BE185D',
    '#4F46E5',
    '#65A30D',
];

const registerCashFlowCharts = (Alpine) => {
    Alpine.data('cashFlowCharts', (initialData, reportType = 'cash-flow') => ({
        charts: {},

        init() {
            this.render(initialData);
        },

        destroy() {
            Object.values(this.charts).forEach((chart) => chart.destroy());
            this.charts = {};
        },

        render(data) {
            const requiredSections = reportType === 'assets'
                ? ['assetTypes', 'distribution', 'allocation', 'evolution']
                : reportType === 'goals'
                    ? ['progress', 'fundingSources']
                    : ['monthly', 'categories', 'sources', 'evolution'];

            if (requiredSections.some((section) => !data?.[section])) {
                return;
            }

            const definitions = reportType === 'assets' ? {
                'assets-by-type-chart': {
                    type: 'doughnut',
                    labels: data.assetTypes.labels,
                    datasets: [{ label: 'Amount', data: data.assetTypes.amounts, backgroundColor: data.assetTypes.colors }],
                },
                'account-distribution-chart': {
                    type: 'bar',
                    indexAxis: 'y',
                    labels: data.distribution.labels,
                    datasets: [{
                        label: 'Balance',
                        data: data.distribution.amounts,
                        backgroundColor: data.distribution.labels.map((_, index) => accountDistributionPalette[index % accountDistributionPalette.length]),
                    }],
                },
                'asset-allocation-chart': {
                    type: 'bar',
                    labels: data.allocation.labels,
                    datasets: [{ label: 'Amount', data: data.allocation.amounts, backgroundColor: ['#315d40', '#2563eb', '#b45309'] }],
                },
                'account-balance-evolution-chart': {
                    type: 'line',
                    labels: data.evolution.labels,
                    datasets: [{ label: 'Balance', data: data.evolution.amounts, borderColor: '#315d40', backgroundColor: '#315d40' }],
                },
            } : reportType === 'goals' ? {
                'goal-progress-chart': {
                    type: 'bar',
                    indexAxis: 'y',
                    labels: data.progress.labels,
                    datasets: [
                        { label: 'Target', data: data.progress.targets, backgroundColor: '#2563eb' },
                        { label: 'Allocated', data: data.progress.allocated, backgroundColor: '#315d40' },
                    ],
                },
                'goal-funding-sources-chart': {
                    type: 'doughnut',
                    labels: data.fundingSources.labels,
                    datasets: [{ label: 'Allocated', data: data.fundingSources.amounts, backgroundColor: accountDistributionPalette }],
                },
            } : {
                'income-expenses-chart': {
                    type: 'line',
                    labels: data.monthly.labels,
                    datasets: [
                        { label: 'Income', data: data.monthly.income, borderColor: '#315d40', backgroundColor: '#315d40', spanGaps: false },
                        { label: 'Expenses', data: data.monthly.expenses, borderColor: '#b45309', backgroundColor: '#b45309' },
                    ],
                },
                'category-chart': {
                    type: 'pie',
                    labels: data.categories.labels,
                    datasets: [{ label: 'Amount', data: data.categories.amounts, backgroundColor: data.categories.colors }],
                },
                'expense-source-chart': {
                    type: 'bar',
                    labels: data.sources.labels,
                    datasets: [
                        { label: 'Recurring', data: data.sources.recurring, backgroundColor: '#315d40' },
                        { label: 'Manual', data: data.sources.manual, backgroundColor: '#b45309' },
                    ],
                },
                'category-evolution-chart': {
                    type: 'line',
                    labels: data.evolution.labels,
                    datasets: [{ label: 'Amount', data: data.evolution.amounts, borderColor: '#315d40', backgroundColor: '#315d40' }],
                },
            };

            Object.entries(definitions).forEach(([id, definition]) => {
                const canvas = document.getElementById(id);
                if (!canvas) {
                    return;
                }

                const existingChart = Chart.getChart(canvas);
                if (existingChart) {
                    existingChart.destroy();
                }

                this.charts[id] = new Chart(canvas, {
                    type: definition.type,
                    data: { labels: definition.labels, datasets: definition.datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        indexAxis: definition.indexAxis,
                        scales: ['pie', 'doughnut'].includes(definition.type)
                            ? {}
                            : { [definition.indexAxis === 'y' ? 'x' : 'y']: { beginAtZero: true } },
                    },
                });
            });
        },
    }));
};

if (window.Alpine) {
    registerCashFlowCharts(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => registerCashFlowCharts(window.Alpine), { once: true });
}
