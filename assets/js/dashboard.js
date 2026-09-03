/**
 * wazeBR - Dashboard Specific JavaScript
 * Charts, Maps, and Dashboard-only features
 */

// Initialize Dashboard
document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.dashboardData === 'undefined') {
        console.warn('Dashboard data not found');
        return;
    }

    initializeCharts();
    initializeMap();
    initializeDashboardAnimations();
});

// Charts
function initializeCharts() {
    if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded');
        return;
    }

    // Alerts Chart (Doughnut)
    const alertsCanvas = document.getElementById('alertsChart');
    if (alertsCanvas && window.dashboardData.alertsBySubtype?.length > 0) {
        new Chart(alertsCanvas, {
            type: 'doughnut',
            data: {
                labels: window.dashboardData.alertsBySubtype.map(item => item.label),
                datasets: [{
                    data: window.dashboardData.alertsBySubtype.map(item => item.count),
                    backgroundColor: [
                        '#2563eb',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                        '#8b5cf6',
                        '#06b6d4',
                        '#ec4899',
                        '#84cc16',
                    ],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return `${label}: ${value}`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Jams Chart (Bar)
    const jamsCanvas = document.getElementById('jamsChart');
    if (jamsCanvas && window.dashboardData.jamsByLevel?.length > 0) {
        new Chart(jamsCanvas, {
            type: 'bar',
            data: {
                labels: window.dashboardData.jamsByLevel.map(item => `Nível ${item.level}`),
                datasets: [{
                    label: 'Jams',
                    data: window.dashboardData.jamsByLevel.map(item => item.count),
                    backgroundColor: [
                        '#10b981',
                        '#84cc16',
                        '#f59e0b',
                        '#ef4444',
                        '#dc2626',
                    ],
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.parsed} jams`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: {
                                family: "'Inter', sans-serif"
                            }
                        },
                        grid: {
                            color: '#e2e8f0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
}

// Map
function initializeMap() {
    if (typeof L === 'undefined') {
        console.warn('Leaflet not loaded');
        return;
    }

    const mapContainer = document.getElementById('dashboard-map');
    if (!mapContainer) return;

    const { mapCenter, mapZoom, mapJams, mapAlerts } = window.dashboardData;

    // Create map
    const map = L.map(mapContainer).setView(mapCenter, mapZoom);

    // Add tile layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Create marker groups
    const jamsGroup = L.markerClusterGroup({
        iconCreateFunction: (cluster) => {
            const count = cluster.getChildCount();
            return L.divIcon({
                html: `<div class="marker-cluster marker-cluster-jam">${count}</div>`,
                className: 'marker-cluster-custom',
                iconSize: L.point(40, 40)
            });
        }
    });

    const alertsGroup = L.markerClusterGroup({
        iconCreateFunction: (cluster) => {
            const count = cluster.getChildCount();
            return L.divIcon({
                html: `<div class="marker-cluster marker-cluster-alert">${count}</div>`,
                className: 'marker-cluster-custom',
                iconSize: L.point(40, 40)
            });
        }
    });

    // Add jams markers
    if (mapJams?.length > 0) {
        mapJams.forEach((jam) => {
            if (jam.lat && jam.lng) {
                const marker = L.marker([jam.lat, jam.lng]);
                marker.bindPopup(`
                    <div class="map-popup">
                        <h4>🚦 Jam</h4>
                        <p><strong>Via:</strong> ${jam.street || 'N/A'}</p>
                        <p><strong>Cidade:</strong> ${jam.city || 'N/A'}</p>
                        <p><strong>Nível:</strong> ${jam.level || 0}</p>
                    </div>
                `);
                jamsGroup.addLayer(marker);
            }
        });
    }

    // Add alerts markers
    if (mapAlerts?.length > 0) {
        mapAlerts.forEach((alert) => {
            if (alert.lat && alert.lng) {
                const marker = L.marker([alert.lat, alert.lng]);
                marker.bindPopup(`
                    <div class="map-popup">
                        <h4>🔔 Alerta</h4>
                        <p><strong>Tipo:</strong> ${alert.type || 'Alerta'}</p>
                        <p><strong>Local:</strong> ${alert.street || 'N/A'}</p>
                    </div>
                `);
                alertsGroup.addLayer(marker);
            }
        });
    }

    // Add groups to map
    jamsGroup.addTo(map);
    alertsGroup.addTo(map);

    // Fit bounds if we have markers
    const allMarkers = [...(mapJams || []), ...(mapAlerts || [])].filter(m => m.lat && m.lng);
    if (allMarkers.length > 0) {
        const bounds = allMarkers.map(m => [m.lat, m.lng]);
        map.fitBounds(bounds, { padding: [50, 50] });
    }
}

// Dashboard-specific animations
function initializeDashboardAnimations() {
    // Animate stats on scroll
    const statValues = document.querySelectorAll('.stat-value');

    const animateValue = (element) => {
        const text = element.textContent;
        const number = parseInt(text.replace(/\D/g, ''));

        if (isNaN(number)) return;

        let current = 0;
        const increment = number / 50;
        const timer = setInterval(() => {
            current += increment;
            if (current >= number) {
                element.textContent = text;
                clearInterval(timer);
            } else {
                element.textContent = Math.floor(current).toLocaleString('pt-BR');
            }
        }, 20);
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                animateValue(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    statValues.forEach((stat) => observer.observe(stat));
}

// Marker cluster custom styles (injected dynamically)
const style = document.createElement('style');
style.textContent = `
    .marker-cluster-custom {
        background: transparent !important;
    }

    .marker-cluster-jam {
        background: rgba(239, 68, 68, 0.9);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    .marker-cluster-alert {
        background: rgba(37, 99, 235, 0.9);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }

    .map-popup h4 {
        margin: 0 0 8px 0;
        font-size: 16px;
        font-weight: 600;
    }

    .map-popup p {
        margin: 4px 0;
        font-size: 13px;
        color: #475569;
    }
`;
document.head.appendChild(style);
