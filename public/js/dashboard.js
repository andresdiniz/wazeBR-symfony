(function() {
    'use strict';

    // Gráfico de barras horizontal
    function createBarChart(canvasId, labels, data, color) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Ocorrências',
                    data: data,
                    backgroundColor: color,
                    borderRadius: 6,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } }
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

    // Mapa Leaflet
    function initMap(jams, alerts) {
        const mapEl = document.getElementById('dashboard-map');
        if (!mapEl) return;

        const map = L.map('dashboard-map').setView([-23.5505, -46.6333], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        const allBounds = [];

        // Congestionamentos (polylines)
        const jamsLayer = L.layerGroup();
        jams.forEach(j => {
            if (j.line && j.line.length > 1) {
                const pts = j.line.map(p => [p.y, p.x]);
                pts.forEach(p => allBounds.push(p));

                const color = j.level >= 5 ? '#dc2626' :
                              j.level >= 3 ? '#ea580c' :
                              j.level >= 1 ? '#ca8a04' : '#16a34a';

                L.polyline(pts, { color, weight: 4, opacity: 0.85 })
                    .bindPopup(`
                        <strong>${j.street || 'Sem nome'}</strong><br>
                        ${j.city || ''}<br>
                        Nível: ${j.level}<br>
                        ${j.speed ? j.speed + ' km/h' : ''}
                        ${j.delay ? ' | Atraso: ' + j.delay + 's' : ''}
                    `)
                    .addTo(jamsLayer);
            }
        });
        jamsLayer.addTo(map);

        // Alertas (markers agrupados)
        const alertsLayer = L.markerClusterGroup({ maxClusterRadius: 50 });
        alerts.forEach(a => {
            if (a.lat && a.lng) {
                allBounds.push([a.lat, a.lng]);

                const color = a.type === 'JAM' ? '#ea580c' :
                              a.type === 'ACCIDENT' ? '#7c3aed' :
                              a.type === 'ROAD_CLOSED' ? '#1e293b' :
                              a.type === 'WEATHERHAZARD' ? '#0891b2' :
                              a.type === 'CONSTRUCTION' ? '#92400e' : '#2563eb';

                L.marker([a.lat, a.lng], {
                    icon: L.divIcon({
                        className: '',
                        html: `<div style="width:12px;height:12px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,0.25)"></div>`,
                        iconSize: [12, 12],
                        iconAnchor: [6, 6],
                    })
                })
                .bindPopup(`
                    <strong>${a.label || a.type}</strong><br>
                    ${a.city || ''} — ${a.street || 'Via não informada'}
                `)
                .addTo(alertsLayer);
            }
        });
        alertsLayer.addTo(map);

        L.control.layers(null, {
            'Congestionamentos': jamsLayer,
            'Alertas': alertsLayer,
        }, { collapsed: false }).addTo(map);

        if (allBounds.length) {
            map.fitBounds(allBounds, { padding: [30, 30] });
        }
    }

    // Inicializa tudo
    function init() {
        const data = window.dashboardData || {};

        // Gráfico: Alertas por subtipo
        if (data.alertsBySubtype && data.alertsBySubtype.length) {
            const labels = data.alertsBySubtype.map(d => d.label);
            const values = data.alertsBySubtype.map(d => d.total);
            createBarChart('chartAlertsBySubtype', labels, values, '#2563eb');
        }

        // Gráfico: Jams por nível
        if (data.jamsByLevel) {
            const labels = ['0-Livre', '1-Livre', '2-Leve', '3-Moderado', '4-Pesado', '5-Parado'];
            const colors = ['#16a34a', '#16a34a', '#ca8a04', '#ea580c', '#ea580c', '#dc2626'];
            const values = [0, 1, 2, 3, 4, 5].map(l => data.jamsByLevel[l] || 0);
            createDoughnutChart('chartJamsByLevel', labels, values, colors);
        }

        // Mapa
        if (data.mapJams && data.mapAlerts) {
            initMap(data.mapJams, data.mapAlerts);
        }

        // Refresh
        document.getElementById('refresh-dashboard')?.addEventListener('click', () => {
            location.reload();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
