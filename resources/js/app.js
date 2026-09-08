import { Chart } from 'chart.js/auto';

const registerCashFlowCharts = (Alpine) => {
    Alpine.data('cashFlowCharts', (initialData) => ({
        charts: {},

        init() {
            this.render(initialData);
        },

        destroy() {
            Object.values(this.charts).forEach((chart) => chart.destroy());
            this.charts = {};
        },

        render(data) {
            if (!data?.monthly || !data?.categories || !data?.sources || !data?.evolution) {
                return;
            }

            const definitions = {
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
                        scales: ['pie', 'doughnut'].includes(definition.type) ? {} : { y: { beginAtZero: true } },
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
