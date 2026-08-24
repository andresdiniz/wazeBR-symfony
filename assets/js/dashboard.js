(() => {
    'use strict';

    const Dashboard = {
        charts: [],
        map: null,
        refreshInterval: 60_000,
        refreshTimer: null,

        init() {
            this.renderCharts();
            this.initMap();
            this.bindRefresh();
            this.startAutoRefresh();
        },

        bindRefresh() {
            document.querySelector('[data-dashboard-refresh], #refresh-dashboard')?.addEventListener('click', () => {
                window.location.reload();
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.startAutoRefresh();
                    this.map?.invalidateSize();
                } else {
                    this.stopAutoRefresh();
                }
            });
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            if (document.visibilityState !== 'visible') return;
            this.refreshTimer = window.setInterval(() => window.location.reload(), this.refreshInterval);
        },

        stopAutoRefresh() {
            if (this.refreshTimer) window.clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        },

        colors() {
            const css = getComputedStyle(document.documentElement);
            return {
                text: css.getPropertyValue('--color-text').trim() || '#1e293b',
                muted: css.getPropertyValue('--color-text-muted').trim() || '#64748b',
                border: css.getPropertyValue('--color-border').trim() || '#e2e8f0',
                palette: ['#3379f3', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5a5'],
            };
        },

        renderCharts() {
            if (typeof Chart === 'undefined' || !window.dashboardData) return;
            const data = window.dashboardData;
            const theme = this.colors();
            Chart.defaults.color = theme.muted;
            Chart.defaults.borderColor = theme.border;

            this.renderAlertsChart(data, theme);
            this.renderLevelsChart(data, theme);
            this.renderTopStreetsChart(data, theme);
        },

        renderAlertsChart(data, theme) {
            const canvas = document.getElementById('chartAlertsBySubtype');
            const rows = Array.isArray(data.alertsBySubtype) ? data.alertsBySubtype : [];
            if (!canvas || rows.length === 0) return;

            this.charts.push(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.label || 'Sem subtipo'),
                    datasets: [{
                        label: 'Alertas',
                        data: rows.map((row) => Number(row.total || 0)),
                        backgroundColor: '#3379f3',
                        borderRadius: 6,
                        maxBarThickness: 36,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: theme.muted, maxRotation: 42, minRotation: 0 }, grid: { color: theme.border } },
                        y: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.border } },
                    },
                },
            }));
        },

        renderLevelsChart(data, theme) {
            const canvas = document.getElementById('chartJamsByLevel');
            const source = Array.isArray(data.jamsByLevel) ? data.jamsByLevel : [];
            if (!canvas || source.length === 0) return;
            const labels = ['Livre', 'Lento', 'Moderado', 'Pesado', 'Muito pesado', 'Parado'];
            const values = [0, 1, 2, 3, 4, 5].map((level) => Number(source[level] || 0));
            if (!values.some(Boolean)) return;

            this.charts.push(new Chart(canvas, {
                type: 'doughnut',
                data: { labels, datasets: [{ data: values, backgroundColor: theme.palette, borderColor: 'transparent', borderWidth: 0 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: theme.muted, boxWidth: 12, padding: 12 } },
                        tooltip: { callbacks: { label: (context) => `${context.label}: ${context.parsed.toLocaleString('pt-BR')}` } },
                    },
                },
            }));
        },

        renderTopStreetsChart(data, theme) {
            const canvas = document.getElementById('chartTopStreets');
            const source = Array.isArray(data.topStreets) ? data.topStreets : [];
            if (!canvas || source.length === 0) return;

            const rows = [...source]
                .sort((a, b) => Number(b.occurrences || 0) - Number(a.occurrences || 0))
                .slice(0, 10)
                .reverse();

            this.charts.push(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map((row) => row.street || 'Sem nome'),
                    datasets: [{
                        label: 'Ocorrências',
                        data: rows.map((row) => Number(row.occurrences || 0)),
                        backgroundColor: '#0ea5a5',
                        borderRadius: 6,
                        maxBarThickness: 28,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: (items) => rows[items[0].dataIndex].street || 'Sem nome',
                                afterLabel: (item) => {
                                    const row = rows[item.dataIndex];
                                    return [
                                        row.city ? `Cidade: ${row.city}` : '',
                                        row.avgLevel !== undefined ? `Nível médio: ${row.avgLevel}` : '',
                                        row.avgDelay ? `Atraso médio: ${(Number(row.avgDelay) / 60).toFixed(1)} min` : '',
                                    ].filter(Boolean);
                                },
                            },
                        },
                    },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.border } },
                        y: { ticks: { color: theme.muted }, grid: { display: false } },
                    },
                },
            }));
        },

        initMap() {
            const element = document.getElementById('dashboard-map');
            const data = window.dashboardData || {};
            if (!element || typeof L === 'undefined') return;

            const map = L.map(element, { zoomControl: true }).setView([-20.6603, -43.7862], 13);
            this.map = map;
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);

            const jamsLayer = L.layerGroup();
            const alertsLayer = typeof L.markerClusterGroup === 'function' ? L.markerClusterGroup({ maxClusterRadius: 50 }) : L.layerGroup();
            const bounds = [];
            const jams = Array.isArray(data.mapJams) ? data.mapJams : [];
            const alerts = Array.isArray(data.mapAlerts) ? data.mapAlerts : [];

            jams.forEach((jam) => {
                const line = Array.isArray(jam.line) ? jam.line
                    .map((point) => [Number(point.y ?? point.lat), Number(point.x ?? point.lng)])
                    .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng)) : [];
                if (line.length < 2) return;
                line.forEach((point) => bounds.push(point));
                const level = Number(jam.level || 0);
                const color = level >= 5 ? '#b91c1c' : level >= 4 ? '#ef4444' : level >= 3 ? '#f97316' : level >= 2 ? '#f59e0b' : '#10b981';
                L.polyline(line, { color, weight: 5, opacity: 0.9 })
                    .bindPopup(`<strong>${this.escape(jam.street || 'Sem nome')}</strong><br>${this.escape(jam.city || '')}<br>Nível: ${level}${jam.speed ? `<br>Velocidade: ${jam.speed} km/h` : ''}${jam.delay ? `<br>Atraso: ${Math.round(Number(jam.delay) / 60)} min` : ''}`)
                    .addTo(jamsLayer);
            });

            alerts.forEach((alert) => {
                const lat = Number(alert.lat);
                const lng = Number(alert.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
                bounds.push([lat, lng]);
                const color = alert.type === 'JAM' ? '#f97316' : alert.type === 'ACCIDENT' ? '#8b5cf6' : alert.type === 'ROAD_CLOSED' ? '#64748b' : '#3379f3';
                L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: 'dashboard-map-marker',
                        html: `<span style="background:${color}"></span>`,
                        iconSize: [16, 16],
                        iconAnchor: [8, 8],
                    }),
                })
                    .bindPopup(`<strong>${this.escape(alert.label || alert.type || 'Alerta')}</strong><br>${this.escape(alert.city || '')} — ${this.escape(alert.street || 'Via não informada')}`)
                    .addTo(alertsLayer);
            });

            jamsLayer.addTo(map);
            alertsLayer.addTo(map);
            L.control.layers(null, { Congestionamentos: jamsLayer, Alertas: alertsLayer }, { collapsed: false }).addTo(map);
            if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
            window.setTimeout(() => map.invalidateSize(), 100);
        },

        escape(value) {
            const node = document.createElement('span');
            node.textContent = String(value);
            return node.innerHTML;
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Dashboard.init(), { once: true });
    } else {
        Dashboard.init();
    }

    window.WazeBRDashboard = Dashboard;
})();
