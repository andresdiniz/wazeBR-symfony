(() => {
    'use strict';

    const normalizeDay = (value) => {
        if (typeof value === 'number' && Number.isFinite(value)) {
            const ms = value > 100000000000 ? value : value * 1000;
            return new Date(ms).toISOString().slice(0, 10);
        }
        if (typeof value === 'string') return value.split('T')[0].split(' ')[0];
        return '';
    };

    const AlertPage = {
        charts: [],
        map: null,

        init() {
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => this._init());
            else this._init();
        },

        _init() {
            this.renderCharts();
            this.initMap();
        },

        colors() {
            const css = getComputedStyle(document.documentElement);
            return { muted: css.getPropertyValue('--color-text-muted').trim() || '#64748b', border: css.getPropertyValue('--color-border').trim() || '#dbe3ef', primary: css.getPropertyValue('--color-primary').trim() || '#2563eb', palette: ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5e9'] };
        },

        chartAnimation() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 500 }; },

        renderCharts() {
            if (typeof Chart === 'undefined') return;
            const data = window.alertData || {};
            const theme = this.colors();
            Chart.defaults.color = theme.muted;
            Chart.defaults.borderColor = theme.border;
            if (Array.isArray(data.bySubtype) && data.bySubtype.length) this.renderSubtypeChart(data.bySubtype, theme);
            this.renderTrendChart(data, theme);
            if (Array.isArray(data.byHour) && data.byHour.length) this.renderHourChart(data.byHour, theme);
            if (data.byWeekday) this.renderWeekdayChart(data.byWeekday, theme);
            if (data.byConfidence) this.renderConfidenceChart(data.byConfidence, theme);
        },

        renderSubtypeChart(rows, theme) {
            const el = document.getElementById('chart-subtype'); if (!el) return;
            this.charts.push(new Chart(el, { type: 'bar', data: { labels: rows.map(r => r.label || 'Sem valor'), datasets: [{ data: rows.map(r => Number(r.total || 0)), backgroundColor: theme.primary, borderRadius: 7 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: this.chartAnimation(), plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } } }));
        },

        renderTrendChart(data, theme) {
            const el = document.getElementById('chart-trend'); if (!el) return;
            const source = data.trendType === 'hour' && Array.isArray(data.byHourTrend) && data.byHourTrend.length ? data.byHourTrend : data.byDay;
            const rows = Array.isArray(source) ? source : [];
            const values = rows.map(r => Number(r.total || 0));
            const labels = rows.map(r => {
                if (data.trendType === 'hour' && r.hour_label) return String(r.hour_label).split(' ')[1]?.substring(0, 5) || String(r.hour_label);
                const day = normalizeDay(r.day); if (!day) return '—'; const p = day.split('-'); return p.length === 3 ? `${p[2]}/${p[1]}` : day;
            });
            if (!labels.length) { el.parentElement.innerHTML = '<div class="chart-empty">Sem dados para exibir</div>'; return; }
            this.charts.push(new Chart(el, { type: 'line', data: { labels, datasets: [{ data: values, borderColor: theme.primary, backgroundColor: 'rgba(37,99,235,.14)', fill: true, tension: .35, pointRadius: labels.length > 40 ? 0 : 3 }] }, options: { responsive: true, maintainAspectRatio: false, animation: this.chartAnimation(), plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 20 } } } } }));
        },

        renderHourChart(byHour, theme) {
            const el = document.getElementById('chart-hour'); if (!el) return;
            this.charts.push(new Chart(el, { type: 'bar', data: { labels: byHour.map((_, h) => `${h}h`), datasets: [{ data: byHour.map(v => Number(v || 0)), backgroundColor: '#8b5cf6', borderRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false, animation: this.chartAnimation(), plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } }));
        },

        renderWeekdayChart(byWeekday, theme) {
            const el = document.getElementById('chart-weekday'); if (!el) return;
            const values = [1, 2, 3, 4, 5, 6, 7].map(k => Number(byWeekday[k] || 0));
            this.charts.push(new Chart(el, { type: 'bar', data: { labels: ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'], datasets: [{ data: values, backgroundColor: '#0ea5e9', borderRadius: 5 }] }, options: { responsive: true, maintainAspectRatio: false, animation: this.chartAnimation(), plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } } }));
        },

        renderConfidenceChart(byConfidence, theme) {
            const el = document.getElementById('chart-confidence'); if (!el) return;
            const keys = Object.keys(byConfidence);
            this.charts.push(new Chart(el, { type: 'doughnut', data: { labels: keys, datasets: [{ data: keys.map(k => Number(byConfidence[k] || 0)), backgroundColor: ['#ef4444', '#f97316', '#22c55e', '#94a3b8'], borderColor: 'transparent' }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '60%', animation: this.chartAnimation(), plugins: { legend: { position: 'bottom' } } } }));
        },

        initMap() {
            const el = document.getElementById('alert-map'); if (!el || typeof L === 'undefined') return;
            const data = window.alertData || {}; if (!Array.isArray(data.mapAlerts) || !data.mapAlerts.length) { el.innerHTML = '<div class="chart-empty">Nenhum alerta com coordenadas para exibir.</div>'; return; }
            const map = L.map(el).setView([-20.6603, -43.7862], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
            const cluster = L.markerClusterGroup({ maxClusterRadius: 45, showCoverageOnHover: false }); const bounds = [];
            data.mapAlerts.forEach(a => { const lat = Number(a.lat), lng = Number(a.lng); if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return; bounds.push([lat, lng]); L.marker([lat, lng]).bindPopup(`<strong>${this.escape(a.type || 'Alerta')}</strong><br>${this.escape(a.street || 'Via não informada')}<br>${this.escape(a.city || 'Sem cidade')}<br><a href="/alertas/${encodeURIComponent(a.id)}">Ver detalhes</a>`).addTo(cluster); });
            map.addLayer(cluster); if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 }); setTimeout(() => map.invalidateSize(), 150); this.map = map;
        },

        escape(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; },
    };

    AlertPage.init();
    window.AlertPage = AlertPage;
})();
