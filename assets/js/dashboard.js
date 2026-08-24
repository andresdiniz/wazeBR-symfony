(() => {
    'use strict';

    const Dashboard = {
        charts: [],
        map: null,
        refreshInterval: 60000,
        refreshTimer: null,
        reduceMotion: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false,

        init() {
            this.observeSections();
            this.renderCharts();
            this.initMapWhenReady();
            this.bindRefresh();
            this.startAutoRefresh();
        },

        bindRefresh() {
            document.querySelector('[data-dashboard-refresh], #refresh-dashboard')?.addEventListener('click', () => window.location.reload());
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.startAutoRefresh();
                    this.map?.invalidateSize({ animate: false });
                } else {
                    this.stopAutoRefresh();
                }
            });
            window.addEventListener('resize', this.debounce(() => {
                this.map?.invalidateSize({ animate: false });
                this.charts.forEach((chart) => chart.resize());
            }, 180), { passive: true });
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            if (document.visibilityState === 'visible') {
                this.refreshTimer = window.setInterval(() => window.location.reload(), this.refreshInterval);
            }
        },

        stopAutoRefresh() {
            if (this.refreshTimer) window.clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        },

        observeSections() {
            const sections = document.querySelectorAll('[data-animate-section]');
            if (this.reduceMotion || !('IntersectionObserver' in window)) {
                sections.forEach((section) => section.classList.add('is-visible'));
                return;
            }
            const observer = new IntersectionObserver((entries, currentObserver) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    currentObserver.unobserve(entry.target);
                });
            }, { threshold: 0.08, rootMargin: '0px 0px -40px' });
            sections.forEach((section) => observer.observe(section));
        },

        colors() {
            const css = getComputedStyle(document.documentElement);
            return {
                text: css.getPropertyValue('--color-text').trim() || '#1e293b',
                muted: css.getPropertyValue('--color-text-muted').trim() || '#64748b',
                border: css.getPropertyValue('--color-border').trim() || '#e2e8f0',
                primary: css.getPropertyValue('--color-primary').trim() || '#3379f3',
                palette: ['#3379f3', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5a5'],
            };
        },

        chartAnimation() {
            if (this.reduceMotion) return false;
            return { duration: 850, easing: 'easeOutQuart', delay: (context) => context.type === 'data' && context.mode === 'default' ? context.dataIndex * 45 : 0 };
        },

        renderCharts() {
            if (typeof Chart === 'undefined' || !window.dashboardData) return;
            const data = window.dashboardData;
            const theme = this.colors();
            Chart.defaults.color = theme.muted;
            Chart.defaults.borderColor = theme.border;
            Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
            this.renderAlertsChart(data, theme);
            this.renderLevelsChart(data, theme);
            this.renderTopStreetsChart(data, theme);
        },

        renderAlertsChart(data, theme) {
            const canvas = document.getElementById('chartAlertsBySubtype');
            const rows = Array.isArray(data.alertsBySubtype) ? data.alertsBySubtype : [];
            if (!canvas || !rows.length) return;
            const total = rows.reduce((sum, r) => sum + Number(r.total), 0);
            this.charts.push(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.label || 'Sem subtipo'),
                    datasets: [{
                        label: 'Alertas',
                        data: rows.map((row) => Number(row.total || 0)),
                        backgroundColor: theme.primary,
                        borderRadius: 8,
                        borderSkipped: false,
                        maxBarThickness: 34,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: this.chartAnimation(),
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                label: (context) => {
                                    const value = context.parsed.y;
                                    const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return ` ${value} alertas (${percent}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: theme.muted, maxRotation: 35, minRotation: 0 }, grid: { color: theme.border } },
                        y: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.border } }
                    }
                }
            }));
        },

        renderLevelsChart(data, theme) {
            const canvas = document.getElementById('chartJamsByLevel');
            const source = Array.isArray(data.jamsByLevel) ? data.jamsByLevel : [];
            if (!canvas || !source.length) return;
            const labels = ['Livre', 'Lento', 'Moderado', 'Pesado', 'Muito pesado', 'Parado'];
            const values = [0, 1, 2, 3, 4, 5].map((level) => Number(source[level]?.total ?? source[level] ?? 0));
            if (!values.some(Boolean)) return;
            this.charts.push(new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: theme.palette,
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--color-bg-card').trim() || '#fff',
                        borderWidth: 3,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    animation: this.chartAnimation(),
                    plugins: {
                        legend: { position: 'bottom', labels: { color: theme.muted, boxWidth: 11, padding: 13, usePointStyle: true } },
                        tooltip: {
                            callbacks: {
                                label: (context) => {
                                    const total = context.dataset.data.reduce((a,b) => a+b, 0);
                                    const value = context.parsed;
                                    const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return ` ${context.label}: ${value} (${percent}%)`;
                                }
                            }
                        }
                    }
                }
            }));
        },

        renderTopStreetsChart(data, theme) {
            const canvas = document.getElementById('chartTopStreets');
            const source = Array.isArray(data.topStreets) ? data.topStreets : [];
            if (!canvas || !source.length) return;
            const rows = [...source].sort((a, b) => Number(b.occurrences || 0) - Number(a.occurrences || 0)).slice(0, 10).reverse();
            this.charts.push(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.street || 'Sem nome'),
                    datasets: [{
                        label: 'Ocorrências',
                        data: rows.map((row) => Number(row.occurrences || 0)),
                        backgroundColor: '#0ea5a5',
                        borderRadius: 8,
                        borderSkipped: false,
                        maxBarThickness: 30
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: this.chartAnimation(),
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                title: (items) => rows[items[0].dataIndex]?.street || 'Sem nome',
                                label: (context) => ` ${context.parsed.x.toLocaleString('pt-BR')} ocorrências`,
                                afterLabel: (context) => {
                                    const row = rows[context.dataIndex];
                                    const lines = [];
                                    if (row.city) lines.push(`Cidade: ${row.city}`);
                                    if (row.avgLevel !== undefined) lines.push(`Nível médio: ${row.avgLevel}`);
                                    if (row.avgDelay) lines.push(`Atraso médio: ${(Number(row.avgDelay) / 60).toFixed(1)} min`);
                                    return lines;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.border } },
                        y: { ticks: { color: theme.muted }, grid: { display: false } }
                    }
                }
            }));
        },

        // ---- MAPA SIMPLES E ROBUSTO ----
        initMapWhenReady(attempt = 0) {
            const element = document.getElementById('dashboard-map');
            if (!element) {
                if (attempt < 10) setTimeout(() => this.initMapWhenReady(attempt + 1), 200);
                return;
            }
            if (typeof L === 'undefined') {
                if (attempt < 20) setTimeout(() => this.initMapWhenReady(attempt + 1), 100);
                return;
            }
            try {
                this.initMap(element);
            } catch (err) {
                console.error('Erro ao inicializar mapa:', err);
                element.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--color-text-muted);flex-direction:column;gap:0.5rem;">
                        <i class="bi bi-map" style="font-size:2rem;"></i>
                        <span>Erro: ${err.message}</span>
                    </div>
                `;
            }
        },

        initMap(element) {
            const data = window.dashboardData || {};
            if (this.map) {
                this.map.remove();
                this.map = null;
            }

            const hasJams = Array.isArray(data.mapJams) && data.mapJams.length > 0;
            const hasAlerts = Array.isArray(data.mapAlerts) && data.mapAlerts.length > 0;

            if (!hasJams && !hasAlerts) {
                element.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--color-text-muted);flex-direction:column;gap:0.5rem;">
                        <i class="bi bi-map" style="font-size:2rem;"></i>
                        <span>Nenhum dado para exibir no período.</span>
                    </div>
                `;
                return;
            }

            // Cria o mapa com view central (Brasil)
            this.map = L.map(element, { zoomControl: true, preferCanvas: true })
                .setView([-15.7939, -47.8828], 4);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(this.map);

            const bounds = [];

            // Adiciona Jams (polylines)
            (Array.isArray(data.mapJams) ? data.mapJams : []).forEach((jam) => {
                const line = Array.isArray(jam.line) ? jam.line.map((point) => [Number(point.lat), Number(point.lng)]).filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng)) : [];
                if (line.length < 2) return;
                line.forEach((p) => bounds.push(p));
                const level = Number(jam.level || 0);
                const color = level >= 5 ? '#b91c1c' : level >= 4 ? '#ef4444' : level >= 3 ? '#f97316' : level >= 2 ? '#f59e0b' : '#10b981';
                L.polyline(line, {
                    color,
                    weight: level >= 4 ? 6 : 4,
                    opacity: 0.8,
                    lineCap: 'round',
                    lineJoin: 'round'
                }).bindPopup(`
                    <strong>${this.escape(jam.street || 'Sem nome')}</strong><br>
                    ${this.escape(jam.city || '')}<br>
                    Nível: ${level}
                    ${jam.speed ? `<br>Velocidade: ${jam.speed} km/h` : ''}
                    ${jam.delay ? `<br>Atraso: ${Math.round(Number(jam.delay) / 60)} min` : ''}
                `).addTo(this.map);
            });

            // Adiciona Alertas (marcadores simples)
            (Array.isArray(data.mapAlerts) ? data.mapAlerts : []).forEach((alert) => {
                const lat = Number(alert.lat), lng = Number(alert.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
                bounds.push([lat, lng]);
                const color = alert.type === 'JAM' ? '#f97316' :
                              alert.type === 'ACCIDENT' ? '#8b5cf6' :
                              alert.type === 'ROAD_CLOSED' ? '#64748b' : '#3379f3';
                L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'dashboard-map-marker',
                        html: `<span style="--marker-color:${color}"></span>`,
                        iconSize: [18, 18],
                        iconAnchor: [9, 9]
                    })
                }).bindPopup(`
                    <strong>${this.escape(alert.label || alert.type || 'Alerta')}</strong><br>
                    ${this.escape(alert.city || '')} — ${this.escape(alert.street || 'Via não informada')}
                `).addTo(this.map);
            });

            // Ajusta os limites
            if (bounds.length > 0) {
                const latLngBounds = L.latLngBounds(bounds);
                this.map.fitBounds(latLngBounds, { padding: [30, 30], maxZoom: 16, animate: !this.reduceMotion });
            } else {
                this.map.setView([-15.7939, -47.8828], 4);
            }

            // Força redimensionamento com múltiplos delays
            const forceResize = () => {
                if (this.map) {
                    this.map.invalidateSize({ animate: false });
                }
            };
            setTimeout(forceResize, 100);
            setTimeout(forceResize, 300);
            setTimeout(forceResize, 600);
        },

        escape(value) {
            const node = document.createElement('span');
            node.textContent = String(value);
            return node.innerHTML;
        },

        debounce(callback, delay) {
            let timeout;
            return (...args) => {
                window.clearTimeout(timeout);
                timeout = window.setTimeout(() => callback(...args), delay);
            };
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Dashboard.init(), { once: true });
    } else {
        Dashboard.init();
    }
    window.WazeBRDashboard = Dashboard;
})();
