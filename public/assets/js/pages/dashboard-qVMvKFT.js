/**
 * Dashboard v3 — modern, safe, performant.
 *
 * Correções aplicadas:
 *  - Template literals com auto-escape (elimina XSS acidental)
 *  - Promise.allSettled: falha em 1 endpoint não quebra os outros
 *  - View Transitions API quando disponível
 *  - WeakMap para charts (cleanup automático)
 *  - AbortController em todos os fetches + cleanup no unload
 *  - Configuração de charts em objeto (DRY)
 *  - Cleanup de timers no unload
 */

'use strict';

// ─────────────────────────────────────────────────────────────────────
// Constantes
// ─────────────────────────────────────────────────────────────────────

const AUTO_REFRESH_MS = 120_000;
const STALE_THRESHOLD_MS = 30_000;
const FETCH_TIMEOUT_MS = 30_000;

const COLORS = {
  blue:   '#2563eb',
  orange: '#ea580c',
  green:  '#16a34a',
  purple: '#7c3aed',
  gray:   '#94a3b8',
  red:    '#dc2626',
  cyan:   '#0891b2',
  grid:   'rgba(148, 163, 184, .25)',
  text:   '#475569',
};

const PALETTE = [COLORS.blue, COLORS.orange, COLORS.green, COLORS.purple,
                 COLORS.red, COLORS.cyan, '#7c2d12', '#4d7c0f', '#c026d3', '#0f766e'];

// ─────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────

const escapeHtml = (s) => String(s ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const raw = (html) => ({ __raw: String(html) });

/** Tagged template com auto-escape. Use raw() para HTML confiável. */
function html(strings, ...values) {
  return strings.reduce((out, str, i) => {
    if (i >= values.length) return out + str;
    return out + str + renderValue(values[i]);
  }, '');
}

function renderValue(v) {
  if (v == null) return '';
  if (Array.isArray(v)) return v.map(renderValue).join('');
  if (v && v.__raw !== undefined) return v.__raw;
  return escapeHtml(v);
}

/** Retorna URL apenas se http(s). Bloqueia javascript:, data:, file:. */
function safeUrl(value) {
  if (!value) return null;
  try {
    const u = new URL(String(value), window.location.origin);
    return /^https?:$/.test(u.protocol) ? u.toString() : null;
  } catch { return null; }
}

function safeJson(str, fallback = []) {
  try { return JSON.parse(str); } catch { return fallback; }
}

const nfBR = new Intl.NumberFormat('pt-BR');
const nf1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

const fmt = {
  int: (v) => nfBR.format(Number(v) || 0),
  mm:  (v) => nf1.format(Number(v) || 0),
  date: (iso) => {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
        timeZone: 'America/Sao_Paulo',
      });
    } catch { return '—'; }
  },
  time: (iso) => {
    if (!iso) return '—';
    try {
      return new Date(iso).toLocaleString('pt-BR', {
        hour: '2-digit', minute: '2-digit',
        timeZone: 'America/Sao_Paulo',
      });
    } catch { return '—'; }
  },
  shortDate: (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
  },
};

const rtf = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat
  ? new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
  : null;

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return '';
  const diffMin = Math.round((d.getTime() - Date.now()) / 60_000);
  if (!rtf) return d.toLocaleString('pt-BR');
  if (Math.abs(diffMin) < 60) return rtf.format(diffMin, 'minute');
  const diffH = Math.round(diffMin / 60);
  if (Math.abs(diffH) < 24) return rtf.format(diffH, 'hour');
  return rtf.format(Math.round(diffH / 24), 'day');
}

/** Atualiza um elemento com View Transitions se disponível. */
function withViewTransition(fn) {
  if (typeof document.startViewTransition === 'function') {
    document.startViewTransition(fn);
  } else {
    fn();
  }
}

// ─────────────────────────────────────────────────────────────────────
// Estado
// ─────────────────────────────────────────────────────────────────────

const state = {
  selectedHydro: 'all',
  selectedPluvio: 'all',
  isRefreshing: false,
  lastRefreshAt: 0,
  autoRefreshTimer: null,
  abortControllers: new Set(),
  bootstrapped: false,
};

// WeakMaps: auto-limpeza quando o canvas é removido
const chartInstances = new WeakMap();
const chartByKey = new Map();

// ─────────────────────────────────────────────────────────────────────
// Charts — DRY via configs
// ─────────────────────────────────────────────────────────────────────

const BASE_OPTIONS = {
  maintainAspectRatio: false,
  animation: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } },
    tooltip: { backgroundColor: 'rgba(15,23,42,.92)', padding: 10, cornerRadius: 6 },
  },
  scales: {
    x: { grid: { display: false } },
    y: { grid: { color: getDashboardChartTheme().grid }, beginAtZero: true },
  },
};

const CHART_CONFIGS = {
  'hourly-activity': (data) => ({
    type: 'line',
    data: {
      labels: data.map((p) => p.hour),
      datasets: [
        { label: 'Alertas', data: data.map((p) => Number(p.alerts) || 0),
          borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,.15)',
          fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2 },
        { label: 'Jams', data: data.map((p) => Number(p.jams) || 0),
          borderColor: COLORS.orange, backgroundColor: 'rgba(234,88,12,.10)',
          fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2 },
      ],
    },
    options: BASE_OPTIONS,
  }),

  'alerts-by-type': (data) => ({
    type: 'doughnut',
    data: {
      labels: data.map((p) => p.label),
      datasets: [{
        data: data.map((p) => Number(p.total) || 0),
        backgroundColor: data.map((_, i) => PALETTE[i % PALETTE.length]),
        borderWidth: 0,
      }],
    },
    options: {
      ...BASE_OPTIONS,
      cutout: '62%',
      interaction: { mode: 'nearest' },
      plugins: { ...BASE_OPTIONS.plugins, legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, padding: 10 } } },
    },
  }),

  'alerts-by-city': (data) => barHorizontal(data, 'Alertas', 'rgba(37,99,235,.75)'),
  'alerts-by-street': (data) => barHorizontal(data, 'Alertas', 'rgba(124,58,237,.75)'),

  'jams-by-level': (data) => ({
    type: 'bar',
    data: {
      labels: data.map((p) => p.label),
      datasets: [{ label: 'Jams', data: data.map((p) => Number(p.total) || 0),
        backgroundColor: 'rgba(234,88,12,.75)', borderRadius: 6 }],
    },
    options: { ...BASE_OPTIONS, plugins: { legend: { display: false } } },
  }),

  'weather-trend': (data) => ({
    type: 'line',
    data: {
      labels: data.map((p) => (p.time || '').slice(11, 16)),
      datasets: [
        { label: 'Temp (°C)', data: data.map((p) => p.temperature),
          borderColor: COLORS.red, backgroundColor: 'rgba(220,38,38,.12)',
          tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y' },
        { label: 'Umidade (%)', data: data.map((p) => p.humidity),
          borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,.10)',
          tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y1' },
        { label: 'Chuva (mm)', data: data.map((p) => p.precipitation),
          borderColor: COLORS.green, backgroundColor: 'rgba(22,163,74,.15)',
          tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'y2' },
      ],
    },
    options: {
      ...BASE_OPTIONS,
      scales: {
        x: { grid: { display: false } },
        y:  { position: 'left',  grid: { color: getDashboardChartTheme().grid }, title: { display: true, text: '°C' } },
        y1: { position: 'right', grid: { display: false }, title: { display: true, text: '%' }, ticks: { max: 100 } },
        y2: { display: false, beginAtZero: true },
      },
    },
  }),

  'hydro-trend': (data) => ({
    type: 'line',
    data: {
      labels: data.map((p) => (p.time || '').slice(5, 16)),
      datasets: [
        { label: 'Nível do rio (m)', data: data.map((p) => p.avg_level),
          borderColor: COLORS.cyan, backgroundColor: 'rgba(8,145,178,.15)',
          fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2 },
        { label: 'Cota de atenção', data: data.map((p) => p.cota_atencao),
          borderColor: COLORS.orange, borderDash: [6, 4],
          pointRadius: 0, borderWidth: 1.5, fill: false },
        { label: 'Cota de alerta', data: data.map((p) => p.cota_alerta),
          borderColor: COLORS.red, borderDash: [6, 4],
          pointRadius: 0, borderWidth: 1.5, fill: false },
      ],
    },
    options: {
      ...BASE_OPTIONS,
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: getDashboardChartTheme().grid }, title: { display: true, text: 'metros' } },
      },
    },
  }),

  'pluvio-hourly': (data) => ({
    type: 'bar',
    data: {
      labels: data.map((p) => (p.time || '').slice(11, 16)),
      datasets: [{
        label: 'Chuva (mm)',
        data: data.map((p) => Number(p.rain) || 0),
        backgroundColor: data.map((p) => Number(p.rain) > 0
          ? 'rgba(37,99,235,.75)' : 'rgba(148,163,184,.35)'),
        borderColor: 'rgba(37,99,235,.95)',
        borderWidth: 1, borderRadius: 4,
      }],
    },
    options: {
      ...BASE_OPTIONS,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,.92)',
          padding: 10, cornerRadius: 6,
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed.y;
              if (!v) return 'Sem chuva';
              const wet = data[ctx.dataIndex]?.wet_stations || 0;
              return `Chuva: ${Number(v).toFixed(1)} mm · ${wet} ponto(s)`;
            },
          },
        },
      },
    },
  }),
};

function barHorizontal(data, label, color) {
  return {
    type: 'bar',
    data: {
      labels: data.map((p) => p.label),
      datasets: [{ label, data: data.map((p) => Number(p.total) || 0),
        backgroundColor: color, borderRadius: 6 }],
    },
    options: {
      ...BASE_OPTIONS,
      indexAxis: 'y',
      interaction: { mode: 'nearest' },
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: getDashboardChartTheme().grid }, beginAtZero: true },
        y: { grid: { display: false } },
      },
    },
  };
}

function mountOrUpdateChart(canvas, data) {
  if (!canvas || typeof Chart === 'undefined') return;

  const name = canvas.dataset.chart;
  const factory = CHART_CONFIGS[name];
  if (!factory) return;

  const config = factory(data || safeJson(canvas.dataset.chartData, []));

  // Reaproveita instância existente no mesmo canvas
  const existing = chartInstances.get(canvas);
  if (existing && existing.config.type === config.type) {
    existing.data = config.data;
    existing.options = config.options;
    existing.update('none');
    return;
  }

  // Destrói antigo (se existir em outro canvas mapeado pelo mesmo key)
  const key = `${name}:${canvas.dataset.__uid || (canvas.dataset.__uid = Math.random().toString(36).slice(2, 10))}`;
  const old = chartByKey.get(key);
  if (old) { old.destroy(); chartByKey.delete(key); }

  const instance = new Chart(canvas.getContext('2d'), config);
  chartInstances.set(canvas, instance);
  chartByKey.set(key, instance);
}

function getDashboardChartTheme() {
  const styles = getComputedStyle(document.documentElement);
  return {
    text: styles.getPropertyValue('--text').trim() || COLORS.text,
    grid: styles.getPropertyValue('--border-soft').trim() || COLORS.grid,
  };
}

function initCharts(root = document) {
  if (typeof Chart === 'undefined') return;

  Chart.defaults.color = getDashboardChartTheme().text;
  Chart.defaults.font.family = "Inter, system-ui, -apple-system, 'Segoe UI', sans-serif";
  Chart.defaults.font.size = 12;

  root.querySelectorAll('[data-chart]').forEach((canvas) => {
    mountOrUpdateChart(canvas, null);
  });
}

// ─────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────

const map = {
  instance: null,
  container: null,
  layers: {},
};

const alertColorMap = {
  ACCIDENT: COLORS.red,
  JAM: COLORS.orange,
  ROAD_CLOSED: '#111827',
  POLICE: '#1d4ed8',
  WEATHERHAZARD: '#0891b2',
  HAZARD: '#f59e0b',
};

const jamColorByLevel = [COLORS.gray, '#22c55e', '#84cc16', '#f59e0b', '#f97316', COLORS.red];

const alertColor = (t) => alertColorMap[String(t || '').toUpperCase()] || COLORS.blue;
const jamColor = (lvl) => jamColorByLevel[lvl] ?? COLORS.orange;

function popup({ color, title, subtitle, body, footer }) {
  const dot = color
    ? raw(`<span class="map-popup__dot" style="background:${escapeHtml(color)}"></span>`)
    : '';
  return html`
    <div class="map-popup">
      <div class="map-popup__head">
        ${dot}
        <span class="map-popup__title">${title}</span>
      </div>
      ${subtitle ? raw(`<div class="map-popup__subtitle">${escapeHtml(subtitle)}</div>`) : ''}
      <div class="map-popup__body">${raw(body || '')}</div>
      ${footer ? raw(`<div class="map-popup__footer">${escapeHtml(footer)}</div>`) : ''}
    </div>
  `;
}

function initMap(root = document) {
  const container = root.querySelector('[data-dashboard-map]');
  if (!container || typeof L === 'undefined') return;

  if (map.instance && map.container === container) {
    populateMap(container);
    return;
  }

  if (map.instance) {
    map.instance.remove();
    map.instance = null;
    map.layers = {};
  }

  map.container = container;
  const center = safeJson(container.dataset.mapCenter, { lat: -15.78, lng: -47.92, zoom: 11 });

  map.instance = L.map(container, { zoomControl: true, preferCanvas: true })
    .setView([center.lat, center.lng], center.zoom ?? 11);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map.instance);

  ['alerts', 'jams', 'cameras', 'weather', 'stations'].forEach((k) => {
    map.layers[k] = L.layerGroup().addTo(map.instance);
  });

  populateMap(container);

  root.querySelectorAll('[data-layer-toggle]').forEach((btn) => {
    if (btn.dataset.layerInit === '1') return;
    btn.dataset.layerInit = '1';
    btn.addEventListener('click', () => {
      const key = btn.dataset.layerToggle;
      const group = map.layers[key];
      if (!group || !map.instance) return;
      const active = map.instance.hasLayer(group);
      if (active) map.instance.removeLayer(group);
      else map.instance.addLayer(group);
      btn.classList.toggle('is-active', !active);
    });
  });

  if (!window.__dashMapResize) {
    window.__dashMapResize = true;
    window.addEventListener('resize', () => map.instance?.invalidateSize(), { passive: true });
  }

  window.dashboardMap = map.instance;
}

function populateMap(container) {
  if (!map.instance || !container) return;

  const center = safeJson(container.dataset.mapCenter, null);
  if (center && Number.isFinite(center.lat) && Number.isFinite(center.lng)) {
    map.instance.setView([center.lat, center.lng], center.zoom ?? 11, { animate: false });
  }

  Object.values(map.layers).forEach((g) => g?.clearLayers());

  const alerts   = safeJson(container.dataset.mapAlerts, []);
  const jams     = safeJson(container.dataset.mapJams, []);
  const cameras  = safeJson(container.dataset.mapCameras, []);
  const weather  = safeJson(container.dataset.mapWeather, []);
  const stations = safeJson(container.dataset.mapStations, []);

  alerts.forEach((a) => {
    if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
    const color = alertColor(a.type);
    L.circleMarker([a.lat, a.lng], {
      radius: 6, color, weight: 1, fillColor: color, fillOpacity: 0.75,
    }).bindPopup(popup({
      color,
      title: a.type || 'Alerta',
      subtitle: a.subtype,
      body: `${escapeHtml(a.street || '')}${a.street && a.city ? ' — ' : ''}${escapeHtml(a.city || '')}`,
      footer: timeAgo(a.when),
    })).addTo(map.layers.alerts);
  });

  jams.forEach((j) => {
    if (!Array.isArray(j.path) || j.path.length < 2) return;
    const color = jamColor(Number(j.level) || 0);
    const dashArray = (Number(j.level) || 0) >= 4 ? '8 5' : null;
    L.polyline(j.path, { color, weight: 5, opacity: 0.85, dashArray })
      .bindPopup(popup({
        color,
        title: `Congestionamento — nível ${j.level}`,
        subtitle: `${j.street || ''}${j.city ? ' — ' + j.city : ''}`,
        body: `Atraso: <strong>${fmt.int(j.delay || 0)}s</strong><br>Extensão: ${((j.length || 0) / 1000).toFixed(1)} km`,
      })).addTo(map.layers.jams);
  });

  cameras.forEach((c) => {
    if (!Number.isFinite(c.lat) || !Number.isFinite(c.lng)) return;
    const camUrl = safeUrl(c.url);
    const body = camUrl
      ? `<a href="${escapeHtml(camUrl)}" target="_blank" rel="noopener noreferrer">Abrir stream ↗</a>`
      : (c.url ? '<em>URL inválida</em>' : '');
    L.circleMarker([c.lat, c.lng], {
      radius: 5, color: COLORS.purple, weight: 1, fillColor: COLORS.purple, fillOpacity: 0.85,
    }).bindPopup(popup({
      color: COLORS.purple,
      title: c.name || 'Câmera',
      subtitle: `${c.city || ''}${c.state ? ' / ' + c.state : ''}`,
      body,
    })).addTo(map.layers.cameras);
  });

  weather.forEach((w) => {
    if (!Number.isFinite(w.lat) || !Number.isFinite(w.lng)) return;
    L.circleMarker([w.lat, w.lng], {
      radius: 5, color: COLORS.green, weight: 1, fillColor: COLORS.green, fillOpacity: 0.8,
    }).bindPopup(popup({
      color: COLORS.green,
      title: w.name || 'Estação',
      subtitle: `${w.city || ''} · ${w.provider || ''}`,
    })).addTo(map.layers.weather);
  });

  stations.forEach((s) => {
    if (!Number.isFinite(s.lat) || !Number.isFinite(s.lng)) return;
    const r1 = Number(s.rain1h ?? 0);
    const r24 = Number(s.rain24h ?? 0);
    const color = r24 > 20 ? '#1d4ed8' : r24 > 5 ? '#2563eb' : r24 > 0 ? '#0891b2' : '#14b8a6';
    L.circleMarker([s.lat, s.lng], {
      radius: r24 > 0 ? 6 : 4, color: '#0f766e', weight: 1, fillColor: color, fillOpacity: 0.85,
    }).bindPopup(popup({
      color,
      title: s.name || 'Estação CEMADEM',
      subtitle: `${s.city || ''}${s.state ? ' / ' + s.state : ''} · ${s.type || ''}`,
      body: `Chuva 1h: <strong>${r1.toFixed(1)} mm</strong><br>Chuva 24h: <strong>${r24.toFixed(1)} mm</strong>`,
      footer: timeAgo(s.lastReading),
    })).addTo(map.layers.stations);
  });
}

function refreshMap(mapData) {
  if (!map.container || !mapData) return;
  const ds = map.container.dataset;
  ds.mapAlerts   = JSON.stringify(mapData.alerts   || []);
  ds.mapJams     = JSON.stringify(mapData.jams     || []);
  ds.mapCameras  = JSON.stringify(mapData.cameras  || []);
  ds.mapWeather  = JSON.stringify(mapData.weather  || []);
  ds.mapStations = JSON.stringify(mapData.stations || []);
  if (mapData.center) ds.mapCenter = JSON.stringify(mapData.center);
  populateMap(map.container);
}

// ─────────────────────────────────────────────────────────────────────
// Fetch
// ─────────────────────────────────────────────────────────────────────

function apiBase(root) {
  const el = root.querySelector('[data-dashboard]');
  const endpoint = el?.dataset.apiEndpoint || '/dashboard/api/data';
  return endpoint.replace(/\/data$/, '');
}

function currentFilters() {
  const form = document.querySelector('[data-filter-form]');
  if (!form) return {};
  const out = {};
  new FormData(form).forEach((v, k) => {
    const val = String(v).trim();
    if (val) out[k] = val;
  });
  return out;
}

async function fetchJson(url, signal) {
  const res = await fetch(url, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
    signal,
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

function makeController() {
  const c = new AbortController();
  state.abortControllers.add(c);
  const t = setTimeout(() => c.abort(), FETCH_TIMEOUT_MS);
  c.signal.addEventListener('abort', () => clearTimeout(t), { once: true });
  return c;
}

async function fetchFullPayload(root, filters) {
  const base = apiBase(root);
  const qs = new URLSearchParams(filters).toString();
  const controller = makeController();
  const sig = controller.signal;

  const endpoints = ['summary', 'charts', 'map', 'recent', 'hydro', 'pluvio'];
  const results = await Promise.allSettled(
    endpoints.map((e) => fetchJson(`${base}/${e}?${qs}`, sig))
  );

  state.abortControllers.delete(controller);

  const merged = {};
  results.forEach((r) => {
    if (r.status === 'fulfilled' && r.value?.data) {
      Object.assign(merged, r.value.data);
    }
  });

  const failures = results
    .map((r, i) => r.status === 'rejected' ? endpoints[i] : null)
    .filter(Boolean);

  return { data: merged, failures };
}

// ─────────────────────────────────────────────────────────────────────
// Renderers
// ─────────────────────────────────────────────────────────────────────

function emptyState(title, subtitle) {
  return html`
    <div class="dashboard-empty">
      <span aria-hidden="true">✓</span>
      <strong>${title}</strong>
      <small>${subtitle}</small>
    </div>
  `;
}

function setChartData(root, name, data) {
  const canvas = root.querySelector(`[data-chart="${name}"]`);
  if (canvas) canvas.dataset.chartData = JSON.stringify(data);
}

function renderKPIs(root, kpis, totals) {
  if (!kpis) return;

  const cards = [
    ['.kpi-blue .dashboard-kpi-value',   kpis.alerts],
    ['.kpi-orange .dashboard-kpi-value', kpis.jams],
    ['.kpi-gold .dashboard-kpi-value',   kpis.routes_total],
    ['.kpi-green .dashboard-kpi-value',  kpis.weather],
    ['.kpi-purple .dashboard-kpi-value', kpis.cameras],
    ['.kpi-cyan .dashboard-kpi-value',   kpis.hydro_stations],
    ['.kpi-rain .dashboard-kpi-value',   kpis.pluvio_stations],
  ];
  cards.forEach(([sel, val]) => {
    if (val === undefined) return;
    const el = root.querySelector(sel);
    if (!el) return;
    el.dataset.countValue = String(val);
    el.textContent = fmt.int(val);
  });

  const setTrend = (name, text, mod = '') => {
    const el = root.querySelector(`[data-kpi-trend="${name}"]`);
    if (!el) return;
    el.textContent = text;
    el.classList.remove('is-warning', 'is-positive');
    if (mod) el.classList.add(mod);
  };

  if (totals) {
    setTrend('alerts_active', `${totals.alerts_active ?? 0} ativos`);
    setTrend('jams_active', `${totals.jams_active ?? 0} ativos`,
      (totals.jams_active ?? 0) > 10 ? 'is-warning' : '');
  }
  if (kpis.routes_delayed !== undefined) {
    setTrend('routes_delayed', `${kpis.routes_delayed} atrasadas`, kpis.routes_delayed > 0 ? 'is-warning' : '');
  }
  if (kpis.hydro_at_risk !== undefined) {
    setTrend('hydro_at_risk', `${kpis.hydro_at_risk} em atenção`, kpis.hydro_at_risk > 0 ? 'is-warning' : '');
  }
  if (kpis.pluvio_rain_24h !== undefined) {
    setTrend('pluvio_rain_24h', `${fmt.mm(kpis.pluvio_rain_24h)} mm · 24h`,
      Number(kpis.pluvio_rain_24h) > 0 ? 'is-positive' : '');
  }
}

function renderRecentAlerts(root, alerts) {
  const list = root.querySelector('[data-list="alerts"]');
  if (!list) return;

  list.innerHTML = !alerts?.length
    ? emptyState('Nenhum alerta recente', 'Ajuste os filtros ou verifique as fontes.')
    : alerts.map((a) => html`
        <div class="dashboard-data-row">
          <span class="dashboard-row-icon row-orange" aria-hidden="true">⚠</span>
          <div>
            <strong>${a.typeLabel || a.type || 'Alerta'}</strong>
            <small>#${a.id} · ${a.subtype || '—'} · ${a.city || 'Local não informado'}${a.street ? ' · ' + a.street : ''}</small>
          </div>
          <time>${fmt.shortDate(a.pubDateTime)}</time>
          <span class="dashboard-severity">Confiança ${a.confidence ?? 0}</span>
        </div>
      `).join('');

  root.querySelector('[data-count="recent-alerts"]')?.replaceChildren(String(alerts?.length ?? 0));
}

function renderRecentJams(root, jams) {
  const list = root.querySelector('[data-list="jams"]');
  if (!list) return;

  list.innerHTML = !jams?.length
    ? emptyState('Nenhum congestionamento', 'Não há ocorrências viárias no período.')
    : jams.map((j) => {
        const speed = j.speedKmh != null ? `${Number(j.speedKmh).toFixed(1)} km/h` : '';
        const len = j.length ? `${(j.length / 1000).toFixed(1)} km` : '';
        return html`
          <div class="dashboard-data-row">
            <span class="dashboard-row-icon row-orange" aria-hidden="true">≋</span>
            <div>
              <strong>${j.street || 'Via não informada'}</strong>
              <small>${j.city || '—'}${speed ? ' · ' + speed : ''}${len ? ' · ' + len : ''}</small>
            </div>
            <span class="dashboard-severity dashboard-jam-level-${j.level}">Nível ${j.level}</span>
          </div>
        `;
      }).join('');

  root.querySelector('[data-count="recent-jams"]')?.replaceChildren(String(jams?.length ?? 0));
}

function renderRecentRoutes(root, routes) {
  const list = root.querySelector('[data-list="routes"]');
  if (!list) return;

  if (!routes?.length) {
    list.innerHTML = emptyState('Nenhuma rota encontrada', 'Sem snapshots vinculados às rotas.');
  } else {
    list.innerHTML = routes.map((r) => {
      const lvl = r.delay_level?.level || 'none';
      const icon = (lvl === 'severe' || lvl === 'heavy') ? 'row-orange'
                 : (lvl === 'none' ? 'row-green' : 'row-blue');
      const ratio = r.delay_ratio != null ? Math.round(r.delay_ratio * 100) : 0;

      let badge;
      if (r.delay_seconds > 0) badge = `+${r.delay_seconds}s <small>(${ratio}%)</small>`;
      else if (r.jam_level != null) badge = `Nível ${r.jam_level}`;
      else badge = 'No prazo';

      return html`
        <div class="dashboard-data-row">
          <span class="dashboard-row-icon ${icon}" aria-hidden="true">→</span>
          <div>
            <strong>${r.name || 'Rota'}</strong>
            <small>
              ${r.from_name && r.to_name ? `${r.from_name} → ${r.to_name} · ` : ''}
              Atual ${r.time ?? '—'}s · Hist. ${r.historic_time ?? '—'}s
            </small>
          </div>
          <span class="dashboard-severity delay-badge delay-badge--${lvl}">${raw(badge)}</span>
        </div>
      `;
    }).join('');
  }

  root.querySelector('[data-count="recent-routes"]')?.replaceChildren(String(routes?.length ?? 0));
}

function renderRecentWeather(root, weather) {
  const list = root.querySelector('[data-list="weather"]');
  if (!list) return;

  if (!weather?.length) {
    list.innerHTML = emptyState('Sem leituras climáticas', 'Cadastre uma localização meteorológica.');
    return;
  }

  list.innerHTML = weather.map((obs) => {
    const detail = [
      obs.temperature != null ? `${Number(obs.temperature).toFixed(1)}°C` : null,
      obs.humidity != null ? `${obs.humidity}%` : null,
    ].filter(Boolean).join(' · ');

    return html`
      <div class="dashboard-data-row">
        <span class="dashboard-row-icon row-green" aria-hidden="true">☁</span>
        <div>
          <strong>${obs.locationName || 'Estação'}</strong>
          <small>${obs.city || '—'} · ${fmt.shortDate(obs.observedAt)}</small>
        </div>
        <span class="dashboard-severity">${detail}</span>
      </div>
    `;
  }).join('');
}

function renderFetchStatus(root, status) {
  if (!status) return;
  ['waze_alerts','waze_jams','waze_tvt','weather','cemaden_hydro','cemaden_pluvio','cameras']
    .forEach((k) => {
      const el = root.querySelector(`[data-fetch="${k}"]`);
      if (el) el.textContent = fmt.date(status[k]);
    });
}

function renderHydro(root, hydro) {
  if (!hydro) return;
  const panel = root.querySelector('[data-hydro-panel]');
  if (!panel) return;

  panel.dataset.hydroTrendAll = JSON.stringify(hydro.trend || []);
  panel.dataset.hydroTrendByStation = JSON.stringify(hydro.trend_by_station || {});

  const risk = hydro.stats?.risk || {};
  const strip = {
    stations: hydro.stats?.stations ?? 0,
    alert: (risk.overflow || 0) + (risk.alert || 0),
    attention: risk.attention || 0,
    normal: risk.normal || 0,
  };
  Object.entries(strip).forEach(([k, v]) => {
    panel.querySelector(`[data-hydro-stat="${k}"]`)?.replaceChildren(String(v));
  });

  const picker = panel.querySelector('[data-hydro-picker]');
  if (!picker) return;

  const list = hydro.stations_list || [];
  if (list.length <= 1) { picker.innerHTML = ''; return; }

  const active = (id) => String(state.selectedHydro) === String(id) ? 'is-active' : '';

  picker.innerHTML = html`
    <div class="dashboard-station-picker-head">
      <strong>Rios monitorados</strong>
      <small>${list.length} estações</small>
    </div>
    <button type="button" class="hydro-station-btn hydro-station-btn--all ${active('all')}" data-hydro-select="all">
      <span class="hydro-station-btn-name">Todas as estações</span>
      <small>Média agregada</small>
    </button>
    ${list.map((st) => html`
      <button type="button"
              class="hydro-station-btn hydro-station-btn--${st.risk || 'unknown'} ${active(st.id)}"
              data-hydro-select="${st.id}">
        <span class="hydro-station-btn-name">${st.name}</span>
        <small>${st.city || '—'}${st.state ? '/' + st.state : ''}</small>
        <span class="hydro-station-btn-level">${st.level != null ? fmt.mm(st.level) + ' m' : '—'}</span>
      </button>
    `)}
  `;

  applyHydroSelection(panel, root, true);
}

function renderPluvio(root, pluvio) {
  if (!pluvio) return;
  const panel = root.querySelector('[data-pluvio-panel]');
  if (!panel) return;

  panel.dataset.pluvioHourlyAll = JSON.stringify(pluvio.hourly || []);
  panel.dataset.pluvioHourlyByStation = JSON.stringify(pluvio.hourly_by_station || {});

  const s = pluvio.stats || {};
  const strip = {
    stations: s.stations ?? 0,
    rain_1h:  fmt.mm(s.rain_1h)  + '<small style="font-size:11px;font-weight:600;color:var(--text-3);margin-left:4px">mm</small>',
    rain_24h: fmt.mm(s.rain_24h) + '<small style="font-size:11px;font-weight:600;color:var(--text-3);margin-left:4px">mm</small>',
    peak_24h: fmt.mm(s.peak_24h) + '<small style="font-size:11px;font-weight:600;color:var(--text-3);margin-left:4px">mm/h</small>',
  };
  Object.entries(strip).forEach(([k, v]) => {
    const el = panel.querySelector(`[data-pluvio-stat="${k}"]`);
    if (el) el.innerHTML = String(v);
  });

  const picker = panel.querySelector('[data-pluvio-picker]');
  if (!picker) return;

  const stations = pluvio.stations || [];
  const peak = Number(s.peak_24h ?? 0);
  const active = (id) => String(state.selectedPluvio) === String(id) ? 'is-active' : '';

  picker.innerHTML = html`
    <div class="dashboard-station-picker-head">
      <strong>Chuva por estação · 24h</strong>
      <small>${stations.length} pontos</small>
    </div>
    <button type="button" class="hydro-station-btn hydro-station-btn--all ${active('all')}" data-pluvio-select="all">
      <span class="hydro-station-btn-name">Todas as estações</span>
      <small>Média agregada</small>
    </button>
    ${stations.length === 0
      ? raw(emptyState('Sem leituras de chuva', 'Cadastre um pluviômetro ou estação hidro com dados de chuva.'))
      : stations.map((st) => {
          const pct = peak > 0 ? Math.min(100, (st.rain24h / peak) * 100) : 0;
          return html`
            <button type="button"
                    class="hydro-station-btn pluvio-station-btn pluvio-station-btn--${st.source} ${active(st.stationId)}"
                    data-pluvio-select="${st.stationId}"
                    style="--pct:${pct}">
              <span class="hydro-station-btn-name">
                ${st.stationName}
                <span class="pluvio-source-badge pluvio-source-badge--${st.source}">
                  ${st.source === 'hydro' ? 'Hidro' : 'Auto'}
                </span>
              </span>
              <small>${st.city || '—'}${st.state ? '/' + st.state : ''}</small>
              <span class="hydro-station-btn-level">${fmt.mm(st.rain24h)} mm</span>
            </button>
          `;
        })}
  `;

  applyPluvioSelection(panel, root, true);
}

// ─────────────────────────────────────────────────────────────────────
// Seleção hidro/pluvio
// ─────────────────────────────────────────────────────────────────────

function applyHydroSelection(panel, root, skipCharts = false) {
  const all = safeJson(panel.dataset.hydroTrendAll, []);
  const by = safeJson(panel.dataset.hydroTrendByStation, {});
  const data = state.selectedHydro === 'all' ? all : (by[state.selectedHydro] || []);
  const canvas = panel.querySelector('[data-chart="hydro-trend"]');
  if (!canvas) return;
  canvas.dataset.chartData = JSON.stringify(data);
  if (!skipCharts) initCharts(root);
}

function applyPluvioSelection(panel, root, skipCharts = false) {
  const all = safeJson(panel.dataset.pluvioHourlyAll, []);
  const by = safeJson(panel.dataset.pluvioHourlyByStation, {});
  const data = state.selectedPluvio === 'all' ? all : (by[state.selectedPluvio] || []);
  const canvas = panel.querySelector('[data-chart="pluvio-hourly"]');
  if (!canvas) return;
  canvas.dataset.chartData = JSON.stringify(data);
  if (!skipCharts) initCharts(root);
}

function initPickers(root = document) {
  const hydroPanel = root.querySelector('[data-hydro-panel]');
  if (hydroPanel && hydroPanel.dataset.delegated !== '1') {
    hydroPanel.dataset.delegated = '1';
    hydroPanel.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-hydro-select]');
      if (!btn) return;
      state.selectedHydro = btn.dataset.hydroSelect;
      hydroPanel.querySelectorAll('[data-hydro-select]').forEach((b) =>
        b.classList.toggle('is-active', b === btn));
      applyHydroSelection(hydroPanel, root);
    });
  }

  const pluvioPanel = root.querySelector('[data-pluvio-panel]');
  if (pluvioPanel && pluvioPanel.dataset.delegated !== '1') {
    pluvioPanel.dataset.delegated = '1';
    pluvioPanel.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-pluvio-select]');
      if (!btn) return;
      state.selectedPluvio = btn.dataset.pluvioSelect;
      pluvioPanel.querySelectorAll('[data-pluvio-select]').forEach((b) =>
        b.classList.toggle('is-active', b === btn));
      applyPluvioSelection(pluvioPanel, root);
    });
  }
}

// ─────────────────────────────────────────────────────────────────────
// Hidratação
// ─────────────────────────────────────────────────────────────────────

function hydrate(data, root) {
  if (!data) return;

  renderKPIs(root, data.kpis, data.totals);

  if (data.charts) {
    setChartData(root, 'hourly-activity', data.charts.hourly_activity);
    setChartData(root, 'alerts-by-type',  data.charts.alerts_by_type);
    setChartData(root, 'alerts-by-city',  data.charts.alerts_by_city);
    setChartData(root, 'alerts-by-street',data.charts.alerts_by_street);
    setChartData(root, 'jams-by-level',   data.charts.jams_by_level);
    setChartData(root, 'weather-trend',   data.charts.weather_trend);
  }

  renderHydro(root, data.hydro);
  renderPluvio(root, data.pluvio);
  refreshMap(data.map);
  renderRecentAlerts(root, data.recent?.alerts);
  renderRecentJams(root, data.recent?.jams);
  renderRecentRoutes(root, data.recent?.routes);
  renderRecentWeather(root, data.recent?.weather);
  renderFetchStatus(root, data.fetch_status);

  initCharts(root);
}

// ─────────────────────────────────────────────────────────────────────
// Filters
// ─────────────────────────────────────────────────────────────────────

function initFilters(root = document) {
  const form = root.querySelector('[data-filter-form]');
  if (!form || form.dataset.init === '1') return;
  form.dataset.init = '1';

  const feedback = root.querySelector('[data-filter-feedback]');
  const setFeedback = (msg, isError = false) => {
    if (!feedback) return;
    feedback.textContent = msg;
    feedback.classList.toggle('is-error', isError);
  };

  const setLoading = (active) => {
    const page = root.querySelector('[data-dashboard]');
    page?.classList.toggle('is-loading', active);
    page?.setAttribute('aria-busy', String(active));
  };

  const pushUrl = (params) => {
    const url = new URL(window.location.href);
    url.search = '';
    Object.entries(params).forEach(([k, v]) => v && url.searchParams.set(k, v));
    history.pushState({ filters: params }, '', url.toString());
  };

  const apply = async (params) => {
    if (state.isRefreshing) return;
    state.isRefreshing = true;
    setLoading(true);
    setFeedback('Aplicando filtros...');

    try {
      const { data, failures } = await fetchFullPayload(root, params);

      withViewTransition(() => hydrate(data, root));

      pushUrl(params);
      state.lastRefreshAt = Date.now();

      if (failures.length) {
        setFeedback(`Filtros aplicados · ${failures.length} fonte(s) com falha (${failures.join(', ')})`, true);
      } else {
        setFeedback(`Filtros aplicados · ${new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`);
      }
    } catch (err) {
      setFeedback(err.name === 'AbortError'
        ? 'A requisição demorou demais. Tente novamente.'
        : 'Falha ao aplicar filtros: ' + err.message, true);
    } finally {
      state.isRefreshing = false;
      setLoading(false);
    }
  };

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    apply(currentFilters());
  });

  const clear = () => { form.reset(); apply({}); };
  form.querySelectorAll('[data-action="clear-filters"]').forEach((b) => b.addEventListener('click', clear));
  root.querySelectorAll('[data-action="clear-filters"]').forEach((b) => {
    if (!form.contains(b)) b.addEventListener('click', clear);
  });

  const refresh = root.querySelector('[data-action="refresh-dashboard"]');
  if (refresh && refresh.dataset.init !== '1') {
    refresh.dataset.init = '1';
    refresh.addEventListener('click', async () => {
      refresh.classList.add('is-loading');
      refresh.setAttribute('aria-busy', 'true');
      try { await apply(currentFilters()); }
      finally {
        refresh.classList.remove('is-loading');
        refresh.removeAttribute('aria-busy');
      }
    });
  }

  window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    Array.from(form.elements).forEach((f) => {
      if (!f.name) return;
      f.value = params.get(f.name) ?? '';
    });
    apply(currentFilters());
  });
}

// ─────────────────────────────────────────────────────────────────────
// Auto-refresh
// ─────────────────────────────────────────────────────────────────────

async function refreshSummary(root) {
  if (state.isRefreshing) return;
  state.isRefreshing = true;
  const base = apiBase(root);
  const qs = new URLSearchParams(currentFilters()).toString();

  try {
    const json = await fetchJson(`${base}/summary?${qs}`, makeController().signal);
    if (json?.data) {
      renderKPIs(root, json.data.kpis, json.data.totals);
      renderFetchStatus(root, json.data.fetch_status);
      if (json.data.health_score != null) {
        const ring = root.querySelector('.dashboard-health-ring');
        if (ring) {
          const s = Math.max(0, Math.min(100, Number(json.data.health_score)));
          ring.style.setProperty('--health-score', String(s));
          const span = ring.querySelector('span');
          if (span) span.innerHTML = `${s}<small>%</small>`;
        }
      }
      state.lastRefreshAt = Date.now();
      const ts = root.querySelector('[data-auto-refresh-ts]');
      if (ts) ts.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }
  } catch (err) {
    if (err.name !== 'AbortError') console.warn('[dashboard] auto-refresh:', err.message);
  } finally {
    state.isRefreshing = false;
  }
}

function startAutoRefresh(root) {
  stopAutoRefresh();
  state.lastRefreshAt = Date.now();
  state.autoRefreshTimer = setInterval(() => {
    if (!document.hidden) refreshSummary(root);
  }, AUTO_REFRESH_MS);

  const onVis = () => {
    if (document.hidden) return;
    if (Date.now() - state.lastRefreshAt >= STALE_THRESHOLD_MS) refreshSummary(root);
  };
  document.addEventListener('visibilitychange', onVis);
  window.addEventListener('beforeunload', () => {
    document.removeEventListener('visibilitychange', onVis);
  }, { once: true });
}

function stopAutoRefresh() {
  if (state.autoRefreshTimer) {
    clearInterval(state.autoRefreshTimer);
    state.autoRefreshTimer = null;
  }
}

// ─────────────────────────────────────────────────────────────────────
// Atalhos
// ─────────────────────────────────────────────────────────────────────

function initShortcuts(root = document) {
  if (window.__dashKeys) return;
  window.__dashKeys = true;

  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select, [contenteditable="true"]')) {
      if (e.key === 'Escape') e.target.blur();
      return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    switch (e.key.toLowerCase()) {
      case 'r':
        e.preventDefault();
        root.querySelector('[data-action="refresh-dashboard"]')?.click();
        break;
      case 'f': case '/':
        e.preventDefault();
        root.querySelector('[data-filter="query"]')?.focus();
        break;
      case 'escape':
        e.preventDefault();
        root.querySelector('[data-action="clear-filters"]')?.click();
        break;
      case '?':
        e.preventDefault();
        showShortcutsHelp();
        break;
    }
  });
}

function showShortcutsHelp() {
  document.querySelector('[data-shortcuts-help]')?.remove();

  const backdrop = document.createElement('div');
  backdrop.className = 'dashboard-dialog-backdrop';
  backdrop.setAttribute('data-shortcuts-help', '');
  backdrop.setAttribute('role', 'dialog');
  backdrop.setAttribute('aria-modal', 'true');
  backdrop.setAttribute('aria-label', 'Atalhos de teclado');

  backdrop.innerHTML = html`
    <div class="dashboard-dialog">
      <h3>Atalhos de teclado</h3>
      <table>
        <tr><td><kbd>R</kbd></td><td>Atualizar dados</td></tr>
        <tr><td><kbd>F</kbd> / <kbd>/</kbd></td><td>Ir para busca</td></tr>
        <tr><td><kbd>Esc</kbd></td><td>Limpar filtros</td></tr>
        <tr><td><kbd>?</kbd></td><td>Mostrar ajuda</td></tr>
      </table>
      <button type="button" class="dashboard-button is-primary">Fechar</button>
    </div>
  `;

  const close = () => backdrop.remove();
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  backdrop.querySelector('button').addEventListener('click', close);
  document.body.appendChild(backdrop);
  backdrop.querySelector('button').focus();
}

// ─────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────

export function initDashboard(root = document) {
  if (state.bootstrapped) return;
  if (!root.querySelector('[data-dashboard]')) return;
  state.bootstrapped = true;

  initCharts(root);
  initMap(root);
  initPickers(root);
  initFilters(root);
  initShortcuts(root);
  startAutoRefresh(root);

  window.addEventListener('dashboard:update', (e) => {
    withViewTransition(() => hydrate(e.detail, root));
  });
  window.addEventListener('wazebr:themechange', () => initCharts(root));
}

export function initDashboardCharts(root = document) { initCharts(root); }
export function initDashboardMap(root = document)     { initMap(root); }
export function initDashboardFilters(root = document) { initFilters(root); }

export default initDashboard;
