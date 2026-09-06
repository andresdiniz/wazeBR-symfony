/**
 * wazeBR - Dashboard Page Initialization
 */

import { config } from '../core/config.js';
import { helpers } from '../utils/helpers.js';

let map = null;
let chart = null;

export function initDashboard(options = {}) {
  console.log('[wazeBR] Initializing dashboard...');
  
  // Initialize map
  if (options.mapElement) {
    initMap(options.mapElement);
  }
  
  // Initialize chart
  if (options.chartElement) {
    initChart(options.chartElement);
  }
  
  // Setup event listeners
  setupEventListeners(options);
  
  // Load initial data
  loadDashboardData(options);
}

function initMap(elementId) {
  const mapElement = document.getElementById(elementId);
  if (!mapElement) return;
  
  map = L.map(elementId, {
    center: [config.map.defaultLat, config.map.defaultLng],
    zoom: config.map.defaultZoom,
    minZoom: config.map.minZoom,
    maxZoom: config.map.maxZoom,
  });
  
  L.tileLayer(config.map.tileProvider, {
    attribution: config.map.attribution,
  }).addTo(map);
  
  console.log('[wazeBR] Dashboard map initialized');
}

function initChart(elementId) {
  const ctx = document.getElementById(elementId);
  if (!ctx) return;
  
  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
        label: 'Veí££ulos/hora',
        data: [],
        borderColor: config.chart.colors.primary,
        backgroundColor: 'rgba(37, 99, 235, 0.1)',
        tension: 0.4,
        fill: true,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top',
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0, 0, 0, 0.05)',
          },
        },
        x: {
          grid: {
            display: false,
          },
        },
      },
    },
  });
  
  console.log('[wazeBR] Dashboard chart initialized');
}

function setupEventListeners(options) {
  // Refresh button
  const refreshBtn = document.getElementById('refreshDashboard');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => loadDashboardData(options));
  }
  
  // Time range buttons
  document.querySelectorAll('.time-range-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      document.querySelectorAll('.time-range-btn').forEach(b => b.classList.remove('active'));
      e.target.classList.add('active');
      loadChartData(e.target.dataset.range);
    });
  });
  
  // Map fullscreen
  const fullscreenBtn = document.getElementById('mapFullscreen');
  if (fullscreenBtn) {
    fullscreenBtn.addEventListener('click', () => {
      const mapWidget = document.getElementById(options.mapElement)?.closest('.widget');
      if (mapWidget) {
        mapWidget.classList.toggle('widget-fullscreen');
        setTimeout(() => map?.invalidateSize(), 300);
      }
    });
  }
}

async function loadDashboardData(options) {
  try {
    // Load alerts
    if (options.endpoints?.alerts) {
      const alerts = await fetch(options.endpoints.alerts).then(r => r.json());
      updateAlertsList(alerts);
    }
    
    // Load traffic data
    if (options.endpoints?.traffic) {
      const traffic = await fetch(options.endpoints.traffic).then(r => r.json());
      updateTrafficData(traffic);
    }
    
    console.log('[wazeBR] Dashboard data loaded');
  } catch (error) {
    console.error('[wazeBR] Error loading dashboard data:', error);
    helpers.showToast('Erro ao carregar dados do dashboard', 'danger');
  }
}

function updateAlertsList(alerts) {
  const container = document.querySelector('.alerts-widget');
  if (!container || !alerts?.length) return;
  
  container.innerHTML = alerts.slice(0, 5).map(alert => `
    <div class="alert-item">
      <div class="alert-item-icon ${alert.type || 'warning'}">
        <i class="bi bi-${alert.icon || 'exclamation-triangle'}"></i>
      </div>
      <div class="alert-item-content">
        <div class="alert-item-title">${helpers.escapeHtml(alert.title)}</div>
        <div class="alert-item-location">
          <i class="bi bi-geo-alt"></i>
          ${helpers.escapeHtml(alert.location || 'N/A')}
        </div>
        <div class="alert-item-time">
          <i class="bi bi-clock"></i>
          ${helpers.formatTime(alert.createdAt)}
        </div>
      </div>
    </div>
  `).join('');
}

function updateTrafficData(traffic) {
  if (!chart || !traffic?.data) return;
  
  chart.data.labels = traffic.data.labels || [];
  chart.data.datasets[0].data = traffic.data.values || [];
  chart.update();
}

async function loadChartData(range = '24h') {
  // Implement chart data loading based on range
  console.log('[wazeBR] Loading chart data for range:', range);
}

export default { initDashboard };
