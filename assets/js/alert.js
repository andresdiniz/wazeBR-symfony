(() => {
    'use strict';

    const AlertPage = {
        charts: [],
        map: null,

        init() {
            // Aguarda o DOM e os dados
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this._init());
            } else {
                this._init();
            }
        },

        _init() {
            this.renderCharts();
            this.initMap();
        },

        renderCharts() {
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js não carregado.');
                return;
            }

            const data = window.alertData;
            if (!data) {
                console.warn('Dados não encontrados (window.alertData)');
                return;
            }

            const theme = this.colors();

            Chart.defaults.color = theme.muted;
            Chart.defaults.borderColor = theme.border;
            Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

            // Gráfico 1: Subtipo
            if (data.bySubtype && data.bySubtype.length) {
                this.renderSubtypeChart(data.bySubtype, theme);
            }

            // Gráfico 2: Tendência (dia ou hora)
            this.renderTrendChart(data, theme);

            // Gráfico 3: Hora do dia
            if (data.byHour && data.byHour.length) {
                this.renderHourChart(data.byHour, theme);
            }

            // Gráfico 4: Dia da semana
            if (data.byWeekday) {
                this.renderWeekdayChart(data.byWeekday, theme);
            }

            // Gráfico 5: Confiança
            if (data.byConfidence) {
                this.renderConfidenceChart(data.byConfidence, theme);
            }

            console.log('Gráficos renderizados:', this.charts.length);
        },

        colors() {
            const css = getComputedStyle(document.documentElement);
            return {
                text: css.getPropertyValue('--color-text').trim() || '#172033',
                muted: css.getPropertyValue('--color-text-muted').trim() || '#64748b',
                border: css.getPropertyValue('--color-border').trim() || '#dbe3ef',
                primary: css.getPropertyValue('--color-primary').trim() || '#2563eb',
                palette: ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5a5'],
            };
        },

        chartAnimation() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
            return { duration: 700, easing: 'easeOutQuart' };
        },

        renderSubtypeChart(rows, theme) {
            const el = document.getElementById('chart-subtype');
            if (!el) return;
            const total = rows.reduce((sum, r) => sum + Number(r.total), 0);

            this.charts.push(new Chart(el, {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [{
                        data: rows.map(r => Number(r.total)),
                        backgroundColor: theme.primary,
                        borderRadius: 7,
                        borderSkipped: false,
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
                                label: (context) => {
                                    const value = context.parsed.x;
                                    const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return ` ${value} alertas (${percent}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: theme.border }, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    }
                }
            }));
        },

        renderTrendChart(data, theme) {
            const el = document.getElementById('chart-trend');
            if (!el) return;

            let labels, values;
            const trendType = data.trendType || 'day';

            if (trendType === 'hour' && data.byHourTrend && data.byHourTrend.length) {
                // Dados por hora (período < 1 dia)
                const rows = data.byHourTrend;
                labels = rows.map(r => {
                    const parts = r.hour_label.split(' ');
                    return parts[1] ? parts[1].substring(0, 5) : r.hour_label;
                });
                values = rows.map(r => parseInt(r.total, 10));
            } else if (data.byDay && data.byDay.length) {
                // Dados por dia
                const rows = data.byDay;
                labels = rows.map(r => {
                    const p = r.day.split('-');
                    return p[2] + '/' + p[1];
                });
                values = rows.map(r => parseInt(r.total, 10));
            } else {
                // Sem dados
                el.parentElement.innerHTML = '<div class="chart-empty">Sem dados para exibir</div>';
                return;
            }

            this.charts.push(new Chart(el, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        borderColor: theme.primary,
                        backgroundColor: 'rgba(37,99,235,.14)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: labels.length > 40 ? 0 : 3,
                        pointBackgroundColor: theme.primary,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: this.chartAnimation(),
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => ` ${context.parsed.y} alertas`
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: theme.border }, ticks: { precision: 0 } },
                        x: { grid: { display: false }, ticks: { maxRotation: 35, autoSkip: true, maxTicksLimit: 20 } }
                    }
                }
            }));
        },

        renderHourChart(byHour, theme) {
            const el = document.getElementById('chart-hour');
            if (!el) return;
            const labels = byHour.map((_, h) => h + 'h');

            this.charts.push(new Chart(el, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: byHour,
                        backgroundColor: '#8b5cf6',
                        borderRadius: 5,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: this.chartAnimation(),
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 12 } },
                        y: { beginAtZero: true, grid: { color: theme.border }, ticks: { precision: 0 } }
                    }
                }
            }));
        },

        renderWeekdayChart(byWeekday, theme) {
            const el = document.getElementById('chart-weekday');
            if (!el) return;
            const labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            const values = [1, 2, 3, 4, 5, 6, 7].map(k => byWeekday[k] || 0);

            this.charts.push(new Chart(el, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: '#0ea5e9',
                        borderRadius: 5,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: this.chartAnimation(),
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, grid: { color: theme.border }, ticks: { precision: 0 } }
                    }
                }
            }));
        },

        renderConfidenceChart(byConfidence, theme) {
            const el = document.getElementById('chart-confidence');
            if (!el) return;
            const keys = Object.keys(byConfidence);
            const colors = ['#ef4444', '#f97316', '#22c55e', '#94a3b8'];

            this.charts.push(new Chart(el, {
                type: 'doughnut',
                data: {
                    labels: keys,
                    datasets: [{
                        data: keys.map(k => byConfidence[k]),
                        backgroundColor: colors.slice(0, keys.length),
                        borderColor: 'transparent',
                        borderWidth: 2,
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    animation: this.chartAnimation(),
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: theme.muted,
                                boxWidth: 11,
                                usePointStyle: true,
                                padding: 12,
                            }
                        },
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

        // ----- Mapa -----
        initMap() {
            const el = document.getElementById('alert-map');
            if (!el || typeof L === 'undefined') {
                console.warn('Mapa não disponível');
                return;
            }

            const data = window.alertData;
            if (!data || !data.mapAlerts || !data.mapAlerts.length) {
                el.innerHTML = `
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--color-text-muted);flex-direction:column;gap:0.5rem;">
                        <i class="bi bi-map" style="font-size:2rem;"></i>
                        <span>Nenhum alerta com coordenadas para exibir.</span>
                    </div>
                `;
                return;
            }

            if (this.map) {
                this.map.remove();
                this.map = null;
            }

            const map = L.map(el, { zoomControl: true }).setView([-20.6603, -43.7862], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            const cluster = L.markerClusterGroup({
                maxClusterRadius: 45,
                showCoverageOnHover: false,
            });

            const bounds = [];
            const typeColor = {
                ACCIDENT: '#a12c7b',
                HAZARD: '#d19900',
                WEATHERHAZARD: '#006494',
                JAM: '#da7101',
                ROAD_CLOSED: '#7a7974',
                CONSTRUCTION: '#437a22',
                POLICE: '#01696f',
                MISC: '#7a39bb'
            };

            const typesMap = data.typesMap || {};
            const subtypesMap = data.subtypesMap || {};

            data.mapAlerts.forEach(a => {
                const lat = parseFloat(a.lat);
                const lng = parseFloat(a.lng);
                if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return;
                bounds.push([lat, lng]);

                const color = typeColor[a.type] || '#7a7974';
                const icon = L.divIcon({
                    className: '',
                    iconSize: [16, 16],
                    iconAnchor: [8, 8],
                    html: `<div style="width:16px;height:16px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.35)"></div>`
                });

                const typeLabel = typesMap[a.type] || a.type;
                const subLabel = a.subtype ? (subtypesMap[a.type + '|' + a.subtype] || a.subtype) : '';
                const popup = `
                    <div class="popup-inner">
                        <div class="popup-type">${this.escape(typeLabel)}</div>
                        ${subLabel ? `<div class="popup-sub">${this.escape(subLabel)}</div>` : ''}
                        ${a.street ? `<div class="popup-street">${this.escape(a.street)}</div>` : ''}
                        ${a.city ? `<div class="popup-city">${this.escape(a.city)}</div>` : ''}
                        <div class="popup-meta">
                            ${a.conf !== null && a.conf !== undefined ? `<span>Conf: ${this.escape(a.conf)}</span>` : ''}
                            ${a.thumbs !== null && a.thumbs !== undefined ? `<span>👍 ${this.escape(a.thumbs)}</span>` : ''}
                        </div>
                        <a href="/alertas/${encodeURIComponent(a.id)}" class="alert-detail-link">Ver detalhes →</a>
                    </div>
                `;

                L.marker([lat, lng], { icon })
                    .bindPopup(popup)
                    .addTo(cluster);
            });

            map.addLayer(cluster);
            if (bounds.length) {
                map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16, animate: !window.matchMedia('(prefers-reduced-motion: reduce)').matches });
            } else {
                map.setView([-15.7801, -47.9292], 4);
            }

            setTimeout(() => map.invalidateSize({ animate: false }), 150);
            this.map = map;
        },

        escape(value) {
            const node = document.createElement('span');
            node.textContent = String(value ?? '');
            return node.innerHTML;
        },
    };

    AlertPage.init();
    window.AlertPage = AlertPage;
})();
