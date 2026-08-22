(function() {
    'use strict';

    // Contador animado
    function animateCount(el, target, duration = 1000) {
        const start = 0;
        const startTime = performance.now();

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const value = Math.floor(start + (target - start) * progress);
            el.textContent = value.toLocaleString('pt-BR');

            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                el.textContent = target.toLocaleString('pt-BR');
            }
        }

        requestAnimationFrame(update);
    }

    // Inicializa contadores
    function initCounters() {
        document.querySelectorAll('.count-up').forEach(el => {
            const target = parseInt(el.dataset.target, 10) || 0;
            if (target > 0) {
                animateCount(el, target);
            } else {
                el.textContent = '0';
            }
        });
    }

    // Gráfico de barras
    function createBarChart(canvasId, labels, data, color, options = {}) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: options.label || 'Valor',
                    data: data,
                    backgroundColor: color,
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: options.showLegend !== false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // Gráfico de pizza
    function createDoughnutChart(canvasId, labels, data, colors) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    }

    // Gráfico de linha
    function createLineChart(canvasId, labels, datasets, options = {}) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        new Chart(ctx, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: options.showLegend !== false }
                },
                scales: {
                    y: { beginAtZero: options.beginAtZero !== false }
                }
            }
        });
    }

    // Inicializa gráficos
    function initCharts() {
        // Radares por UF
        if (window.radarUf && window.radarUf.length) {
            const labels = window.radarUf.map(d => d.uf || d.label);
            const data = window.radarUf.map(d => d.total || d.value);
            createBarChart('chartRadarUf', labels, data, '#2563eb', { label: 'Radares' });
        }

        // Resultado Geral
        if (window.radarResultado && window.radarResultado.length) {
            const labels = window.radarResultado.map(d => d.label);
            const data = window.radarResultado.map(d => d.value);
            const colors = ['#16a34a', '#ca8a04', '#dc2626'];
            createDoughnutChart('chartRadarResultado', labels, data, colors);
        }

        // Verificações Mensais
        if (window.radarMensais && window.radarMensais.length) {
            const labels = window.radarMensais.map(d => d.label || d.mes);
            const data = window.radarMensais.map(d => d.total || d.value);
            createBarChart('chartVerifMensais', labels, data, '#0891b2', { label: 'Verificações' });
        }

        // Cobertura Waze por UF
        if (window.radarCobertura && window.radarCobertura.length) {
            const labels = window.radarCobertura.map(d => d.uf || d.label);
            const data = window.radarCobertura.map(d => d.pct || d.value);
            createBarChart('chartCoberturaUf', labels, data, '#dc2626', { label: 'Cobertura (%)' });
        }

        // Posto Atividade
        if (window.postoAtividade && window.postoAtividade.length) {
            const labels = window.postoAtividade.map(d => d.label || d.data);
            const data = window.postoAtividade.map(d => d.total || d.value);
            createLineChart('chartPostoAtividade', labels, [{
                label: 'Postos',
                data: data,
                borderColor: '#ca8a04',
                backgroundColor: 'rgba(202, 138, 4, 0.1)',
                tension: 0.3,
                fill: true,
            }], { beginAtZero: true });
        }

        // Solicitações Diárias
        if (window.solicDiarias && window.solicDiarias.length) {
            const labels = window.solicDiarias.map(d => d.label || d.data);
            const data = window.solicDiarias.map(d => d.total || d.value);
            createLineChart('chartSolicDiarias', labels, [{
                label: 'Solicitações',
                data: data,
                borderColor: '#0891b2',
                backgroundColor: 'rgba(8, 145, 178, 0.1)',
                tension: 0.3,
                fill: true,
            }], { beginAtZero: true });
        }
    }

    // Refresh do dashboard
    document.getElementById('refresh-dashboard')?.addEventListener('click', () => {
        location.reload();
    });

    // Inicializa tudo quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initCounters();
            initCharts();
        });
    } else {
        initCounters();
        initCharts();
    }
})();
