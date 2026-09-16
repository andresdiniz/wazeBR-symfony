/**
 * pages/tv.js — Wallboard /tv
 *
 * - Relógio (1s)
 * - Polling do endpoint a cada 30s
 * - Render de KPIs, feed, ticker, hydro, rain, weather
 * - Mapa Leaflet (inicializa 1x, atualiza dados)
 * - Overlay de reconexão após 3 falhas
 * - Fullscreen + alerta sonoro opcional
 * - Nunca quebra o app: try/catch em cada bloco
 */

const POLL_INTERVAL = 30_000;   // 30s
const ERROR_THRESHOLD = 3;      // 3 falhas antes do overlay
const SOUND_COOLDOWN = 120_000; // não repete beep antes de 2 min

const state = {
    endpoint: null,
    failures: 0,
    lastGeneratedAt: null,
    map: null,
    mapLayers: { alerts: null, jams: null },
    markers: [],
    polylines: [],
    soundOn: false,
    lastBeepAt: 0,
    criticalPreviously: false,
    fullscreen: false,
};

// ─────────────────────────────────────────────────────────────────────────
// Utils
// ─────────────────────────────────────────────────────────────────────────

function $(sel, root = document) { return root.querySelector(sel); }
function $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function fmtNum(n, digits = 0) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(n);
}

function fmtTime(iso) {
    if (!iso) return '—';
    try {
        const d = new Date(iso);
        return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
    } catch {
        return '—';
    }
}

function fmtRelative(iso) {
    if (!iso) return '—';
    try {
        const diff = Date.now() - new Date(iso).getTime();
        const s = Math.max(0, Math.floor(diff / 1000));
        if (s < 60) return `há ${s}s`;
        const m = Math.floor(s / 60);
        if (m < 60) return `há ${m} min`;
        const h = Math.floor(m / 60);
        return `há ${h}h`;
    } catch {
        return '—';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Relógio
// ─────────────────────────────────────────────────────────────────────────

function initClock() {
    const clock = $('[data-tv-clock]');
    const dateEl = $('[data-tv-date]');
    if (!clock || !dateEl) return;

    const timeFmt = new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        timeZone: 'America/Sao_Paulo',
    });
    const dateFmt = new Intl.DateTimeFormat('pt-BR', {
        weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric',
        timeZone: 'America/Sao_Paulo',
    });

    const tick = () => {
        const now = new Date();
        clock.textContent = timeFmt.format(now);
        dateEl.textContent = dateFmt.format(now);
    };
    tick();
    setInterval(tick, 1000);
}

// ─────────────────────────────────────────────────────────────────────────
// Mapa
// ─────────────────────────────────────────────────────────────────────────

function initMap() {
    const container = $('[data-tv-map]');
    if (!container) return;
    if (typeof L === 'undefined') {
        // Tenta novamente por 3s
        let tries = 0;
        const retry = setInterval(() => {
            if (typeof L !== 'undefined' || ++tries > 30) {
                clearInterval(retry);
                if (typeof L !== 'undefined') doInitMap(container);
            }
        }, 100);
        return;
    }
    doInitMap(container);
}

function doInitMap(container) {
    try {
        state.map = L.map(container, {
            zoomControl: false,
            attributionControl: true,
            preferCanvas: true,
            center: [-20.66, -43.78],
            zoom: 12,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(state.map);

        state.mapLayers.alerts = L.layerGroup().addTo(state.map);
        state.mapLayers.jams   = L.layerGroup().addTo(state.map);

        setTimeout(() => state.map && state.map.invalidateSize(), 100);
        setTimeout(() => state.map && state.map.invalidateSize(), 500);

        window.addEventListener('resize', () => state.map && state.map.invalidateSize());
    } catch (err) {
        console.error('[TV] mapa falhou', err);
    }
}

function renderMap(mapData) {
    if (!state.map || !mapData) return;

    // Limpa camadas
    state.mapLayers.alerts.clearLayers();
    state.mapLayers.jams.clearLayers();

    // Jams (linhas vermelhas) — por baixo
    (mapData.jams || []).forEach((j) => {
        if (!Array.isArray(j.path) || j.path.length < 2) return;
        const color = j.level >= 5 ? '#dc2626' : j.level >= 4 ? '#ef4444' : '#f97316';
        L.polyline(j.path, {
            color, weight: 6, opacity: 0.85,
            lineCap: 'round', lineJoin: 'round',
        }).addTo(state.mapLayers.jams);
    });

    // Alertas
    (mapData.alerts || []).forEach((a) => {
        if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;
        const color = alertColor(a.type);
        L.circleMarker([a.lat, a.lng], {
            radius: 7,
            color: '#ffffff',
            weight: 2,
            fillColor: color,
            fillOpacity: 0.95,
        }).addTo(state.mapLayers.alerts);
    });

    // Hot zone: se há muitos alertas, foca
    const badge = $('[data-tv-map-badge]');
    if (mapData.center && mapData.center.hasData) {
        try {
            state.map.setView([mapData.center.lat, mapData.center.lng], mapData.center.zoom, { animate: false });
        } catch {}
        if (badge) badge.hidden = false;
    } else {
        if (badge) badge.hidden = true;
    }
}

function alertColor(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':              return '#ef4444';
        case 'JAM':                   return '#f97316';
        case 'ROAD_CLOSED':           return '#7c3aed';
        case 'POLICE':                return '#3b82f6';
        case 'WEATHERHAZARD':         return '#0891b2';
        case 'HAZARD':                return '#f59e0b';
        case 'HAZARD_WEATHER_FLOOD':  return '#06b6d4';
        case 'CONSTRUCTION':          return '#a855f7';
        default:                      return '#94a3b8';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────

function applyStatus(status) {
    const wrap = $('[data-tv-status]');
    const label = $('[data-tv-status-label]');
    const reason = $('[data-tv-status-reason]');
    const wb = document.querySelector('.tv-wallboard');

    if (!wrap || !label) return;

    wrap.classList.remove('is-normal', 'is-attention', 'is-critical');
    wb.classList.remove('is-normal', 'is-attention', 'is-critical');

    const level = status.level || 'normal';
    wrap.classList.add(`is-${level}`);
    wb.classList.add(`is-${level}`);

    label.textContent = status.label || '—';
    if (reason) {
        reason.textContent = (status.reasons && status.reasons.length) ? status.reasons.join(' · ') : '';
    }

    // Beep se virou crítico
    if (level === 'critical' && !state.criticalPreviously) {
        beep();
    }
    state.criticalPreviously = level === 'critical';
}

function renderAlerts(alerts) {
    setText('[data-tv-total="alerts"]', fmtNum(alerts.total));
    setText('[data-tv-counter="alerts"]', fmtNum(alerts.total));

    const hint = $('[data-tv-alerts-hint]');
    if (hint) {
        const crit2h = alerts.last2h.critical;
        hint.textContent = crit2h > 0
            ? `${crit2h} crítico${crit2h > 1 ? 's' : ''} nas últimas 2h`
            : 'Nenhum crítico nas últimas 2h';
        hint.style.color = crit2h > 0 ? '#f87171' : '';
    }

    // Por tipo
    setText('[data-tv-type="ACCIDENT"]', fmtNum(alerts.byType.ACCIDENT ?? 0));
    setText('[data-tv-type="ROAD_CLOSED"]', fmtNum(alerts.byType.ROAD_CLOSED ?? 0));
    setText('[data-tv-type="HAZARD_WEATHER_FLOOD"]', fmtNum(alerts.byType.HAZARD_WEATHER_FLOOD ?? 0));
    setText('[data-tv-type="WEATHERHAZARD"]', fmtNum(alerts.byType.WEATHERHAZARD ?? 0));

    // Top 5 críticos
    const list = $('[data-tv-top-alerts]');
    if (!list) return;

    if (!alerts.recent || alerts.recent.length === 0) {
        list.innerHTML = '<li class="tv-empty-row">Sem ocorrências recentes</li>';
        return;
    }

    list.innerHTML = alerts.recent.map((a) => {
        const local = a.street || a.city || 'Local não informado';
        return `
            <li>
                <time>${fmtTime(a.when)}</time>
                <div>
                    <strong>${escapeHtml(a.typeLabel || a.type || 'Alerta')}</strong>
                    <small>${escapeHtml(local)}</small>
                </div>
            </li>
        `;
    }).join('');
}

function renderJams(jams) {
    setText('[data-tv-total="jams"]', fmtNum(jams.total));
    setText('[data-tv-counter="jams"]', fmtNum(jams.total));
    setText('[data-tv-jams="level3"]', fmtNum(jams.level3plus));
    setText('[data-tv-jams="level4"]', fmtNum(jams.level4plus));

    const avg = $('[data-tv-jams="avgDelay"]');
    if (avg) avg.textContent = jams.avgDelay > 0 ? `${jams.avgDelay}s` : '—';

    const hint = $('[data-tv-jams-hint]');
    if (hint) {
        hint.textContent = jams.level4plus > 0
            ? `${jams.level4plus} em nível alto ou superior`
            : jams.avgDelay > 120
                ? 'Atraso médio elevado'
                : 'Operação estável';
        hint.style.color = jams.level4plus > 0 ? '#fb923c' : '';
    }

    const sub = $('[data-tv-jam-sub]');
    if (sub) sub.textContent = 'ativos';
}

function renderFeed(feed) {
    const list = $('[data-tv-feed]');
    const updated = $('[data-tv-feed-updated]');
    if (!list) return;

    if (updated) updated.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    if (!feed || feed.length === 0) {
        list.innerHTML = '<li class="tv-empty-row">Sem eventos recentes</li>';
        return;
    }

    list.innerHTML = feed.slice(0, 6).map((e) => {
        const isAlert = e.kind === 'alert';
        const icon = isAlert ? iconForAlert(e.type) : '≋';
        const label = isAlert ? (e.typeLabel || e.type) : 'Congestionamento';
        const local = [e.street, e.city].filter(Boolean).join(' · ') || 'Local não informado';
        const extra = !isAlert && e.level
            ? `Nível ${e.level} · +${e.delay ?? 0}s`
            : '';
        return `
            <li class="tv-feed__row tv-feed__row--${isAlert ? 'alert' : 'jam'}">
                <time>${fmtTime(e.when)}</time>
                <span class="tv-feed__icon">${icon}</span>
                <div class="tv-feed__body">
                    <strong>${escapeHtml(label)}</strong>
                    <small>${escapeHtml(local)}${extra ? ' · ' + escapeHtml(extra) : ''}</small>
                </div>
            </li>
        `;
    }).join('');
}

function iconForAlert(type) {
    switch (String(type || '').toUpperCase()) {
        case 'ACCIDENT':              return '✕';
        case 'ROAD_CLOSED':           return '⊘';
        case 'HAZARD_WEATHER_FLOOD':  return '🌊';
        case 'WEATHERHAZARD':         return '☂';
        case 'HAZARD':                return '▲';
        case 'POLICE':                return '◉';
        case 'JAM':                   return '≋';
        default:                      return '⚠';
    }
}

function renderHydro(hydro) {
    const wrap = $('[data-tv-hydro]');
    const badge = $('[data-tv-hydro-badge]');
    setText('[data-tv-counter="hydro"]', fmtNum(hydro.stations.length));

    if (badge) {
        const atRisk = hydro.atRisk || 0;
        badge.textContent = String(atRisk);
        badge.classList.remove('is-warning', 'is-critical');
        if (hydro.risk.overflow > 0 || hydro.risk.alert > 0) badge.classList.add('is-critical');
        else if (atRisk > 0) badge.classList.add('is-warning');
    }

    if (!wrap) return;

    if (hydro.stations.length === 0) {
        wrap.innerHTML = '<div class="tv-empty-row">Sem estações cadastradas</div>';
        return;
    }

    // Mostra até 4 (o resto cabe em scroll interno)
    wrap.innerHTML = hydro.stations.slice(0, 4).map((s) => {
        const risk = s.risk;
        const riskLabel = {
            overflow: 'Transbordo',
            alert: 'Alerta',
            attention: 'Atenção',
            normal: 'Normal',
            unknown: 'Sem dados',
        }[risk] || '—';

        const progress = s.progress !== null && s.progress !== undefined
            ? Math.min(100, s.progress)
            : 0;

        return `
            <div class="tv-hydro__item tv-hydro__item--${risk}">
                <div class="tv-hydro__head">
                    <span class="tv-hydro__name">${escapeHtml(s.name)}</span>
                    <span class="tv-hydro__risk tv-hydro__risk--${risk}">${riskLabel}</span>
                </div>
                <div class="tv-hydro__value">
                    <strong>${s.level !== null ? fmtNum(s.level, 2) : '—'}</strong>
                    <span>m</span>
                </div>
                ${s.transbordo !== null ? `
                    <div class="tv-hydro__bar">
                        <span style="width:${progress}%"></span>
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');
}

function renderRain(rain) {
    setText('[data-tv-rain="lastHour"]', fmtNum(rain.lastHour ?? 0, 1));
    setText('[data-tv-rain="last24h"]', fmtNum(rain.last24h ?? 0, 1));
    setText('[data-tv-rain="peak24h"]', fmtNum(rain.peak24h ?? 0, 1));

    const top = $('[data-tv-rain-top]');
    if (top) {
        if (rain.topStation) {
            const city = rain.topStation.city
                ? ` · ${rain.topStation.city}${rain.topStation.state ? '/' + rain.topStation.state : ''}`
                : '';
            top.textContent = `Maior: ${rain.topStation.name}${city} — ${fmtNum(rain.topStation.rain24h, 1)} mm`;
        } else {
            top.textContent = 'Sem leituras recentes';
        }
    }
}

function renderWeather(w) {
    if (!w) {
        setText('[data-tv-weather="temperature"]', '—');
        setText('[data-tv-weather="humidity"]', '—');
        setText('[data-tv-weather="wind"]', '—');
        return;
    }

    setText('[data-tv-weather="temperature"]', w.temperature !== null ? fmtNum(w.temperature, 1) : '—');
    setText('[data-tv-weather="humidity"]', w.humidity !== null ? `${w.humidity}%` : '—');
    setText('[data-tv-weather="wind"]', w.windSpeed !== null ? `${fmtNum(w.windSpeed, 0)} km/h` : '—');

    const station = $('[data-tv-weather-station]');
    if (station) {
        const parts = [];
        if (w.stationName) parts.push(w.stationName);
        if (w.city) parts.push(`${w.city}${w.state ? '/' + w.state : ''}`);
        if (w.observedAt) parts.push(fmtRelative(w.observedAt));
        station.textContent = parts.join(' · ') || '—';
    }
}

function renderToday(today) {
    setText('[data-tv-today="alerts"]', fmtNum(today.alerts));
    setText('[data-tv-today="accidents"]', fmtNum(today.accidents));
    setText('[data-tv-today="roadClosed"]', fmtNum(today.roadClosed));
    setText('[data-tv-today="floods"]', fmtNum(today.floods));
    setText('[data-tv-today="jams"]', fmtNum(today.jams));

    const trend = $('[data-tv-today="trend"]');
    if (trend) {
        trend.classList.remove('is-up', 'is-down', 'is-flat');
        if (today.trend === null || today.trend === undefined) {
            trend.textContent = '—';
            trend.classList.add('is-flat');
        } else if (today.trend > 0) {
            trend.textContent = `▲ ${fmtNum(today.trend, 1)}%`;
            trend.classList.add('is-up');
        } else if (today.trend < 0) {
            trend.textContent = `▼ ${fmtNum(Math.abs(today.trend), 1)}%`;
            trend.classList.add('is-down');
        } else {
            trend.textContent = '0%';
            trend.classList.add('is-flat');
        }
    }
}

function renderFresh(fetch) {
    const now = Date.now();
    const map = {
        alerts:  fetch.alerts,
        jams:    fetch.jams,
        weather: fetch.weather,
        hydro:   fetch.hydro,
        pluvio:  fetch.pluvio,
    };

    Object.entries(map).forEach(([key, iso]) => {
        const el = $(`[data-tv-fresh="${key}"]`)?.parentElement;
        if (!el) return;
        el.classList.remove('is-fresh', 'is-stale', 'is-old');
        if (!iso) {
            el.classList.add('is-old');
            return;
        }
        const diff = now - new Date(iso).getTime();
        const min = diff / 60000;
        if (min < 10)      el.classList.add('is-fresh');
        else if (min < 60) el.classList.add('is-stale');
        else               el.classList.add('is-old');
    });
}

function renderUpdated(generatedAt) {
    const el = $('[data-tv-updated]');
    if (el) el.textContent = fmtRelative(generatedAt);
}

function renderTicker(feed) {
    const track = $('[data-tv-ticker-track]');
    if (!track) return;

    if (!feed || feed.length === 0) {
        track.innerHTML = '<span>Sem eventos recentes</span>';
        return;
    }

    // Duplica o conteúdo para loop contínuo (translateX -50%)
    const items = feed.slice(0, 8).map((e) => {
        const isAlert = e.kind === 'alert';
        const label = isAlert ? (e.typeLabel || e.type) : `Congestionamento nível ${e.level}`;
        const local = [e.street, e.city].filter(Boolean).join(' · ') || 'Local não informado';
        return `<span><strong>${fmtTime(e.when)}</strong>${escapeHtml(label)} · ${escapeHtml(local)}</span>`;
    }).join('');

    track.innerHTML = items + items;
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

function setText(selector, value) {
    const el = $(selector);
    if (el) el.textContent = value;
}

function showOverlay(msg) {
    const overlay = $('[data-tv-overlay]');
    const msgEl = $('[data-tv-overlay-msg]');
    if (overlay) overlay.hidden = false;
    if (msgEl && msg) msgEl.textContent = msg;
}

function hideOverlay() {
    const overlay = $('[data-tv-overlay]');
    if (overlay) overlay.hidden = true;
}

// ─────────────────────────────────────────────────────────────────────────
// Som (Web Audio API — sem assets)
// ─────────────────────────────────────────────────────────────────────────

let audioCtx = null;

function beep() {
    if (!state.soundOn) return;
    const now = Date.now();
    if (now - state.lastBeepAt < SOUND_COOLDOWN) return;
    state.lastBeepAt = now;

    try {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        gain.gain.value = 0.08;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.35);
    } catch { /* silencioso */ }
}

// ─────────────────────────────────────────────────────────────────────────
// Polling
// ─────────────────────────────────────────────────────────────────────────

async function fetchData() {
    if (!state.endpoint) return;

    try {
        const res = await fetch(state.endpoint, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!json || !json.data) throw new Error('Payload inválido');

        state.failures = 0;
        state.lastGeneratedAt = json.data.generatedAt;
        hideOverlay();

        applyStatus(json.data.status);
        renderAlerts(json.data.alerts);
        renderJams(json.data.jams);
        renderFeed(json.data.feed);
        renderMap(json.data.map);
        renderHydro(json.data.hydro);
        renderRain(json.data.rain);
        renderWeather(json.data.weather);
        renderToday(json.data.today);
        renderFresh(json.data.fetch);
        renderUpdated(json.data.generatedAt);
        renderTicker(json.data.feed);
    } catch (err) {
        state.failures++;
        console.warn('[TV] fetch falhou', state.failures, err);
        if (state.failures >= ERROR_THRESHOLD) {
            showOverlay(`Tentando novamente… (${state.failures} falhas)`);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Fullscreen + som
// ─────────────────────────────────────────────────────────────────────────

function initFullscreen() {
    const btn = $('[data-tv-fullscreen]');
    if (!btn) return;

    btn.addEventListener('click', () => {
        try {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen?.();
            } else {
                document.exitFullscreen?.();
            }
        } catch (err) {
            console.warn('[TV] fullscreen falhou', err);
        }
    });
}

function initSoundToggle() {
    const btn = $('[data-tv-sound]');
    const icon = $('[data-tv-sound-icon]');
    if (!btn) return;

    // Persiste entre refreshes
    try {
        state.soundOn = localStorage.getItem('wazebr:tv:sound') === '1';
    } catch {}

    updateSoundUI();

    btn.addEventListener('click', () => {
        state.soundOn = !state.soundOn;
        try {
            localStorage.setItem('wazebr:tv:sound', state.soundOn ? '1' : '0');
        } catch {}
        updateSoundUI();
        if (state.soundOn) beep(); // feedback
    });

    function updateSoundUI() {
        btn.classList.toggle('is-on', state.soundOn);
        btn.setAttribute('aria-label', state.soundOn ? 'Desativar alerta sonoro' : 'Ativar alerta sonoro');
        if (icon) icon.textContent = state.soundOn ? '🔔' : '🔇';
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────

let bootstrapped = false;

export function initTvWallboard(root = document) {
    const page = root.querySelector?.('[data-tv-wallboard]') ?? root;
    if (!page || !page.matches?.('[data-tv-wallboard]') || bootstrapped) return;
    bootstrapped = true;

    state.endpoint = page.dataset.apiEndpoint;
    if (!state.endpoint) {
        console.warn('[TV] sem data-api-endpoint');
        return;
    }

    initClock();
    initMap();
    initFullscreen();
    initSoundToggle();

    // Primeira carga imediata + polling
    fetchData();
    setInterval(fetchData, POLL_INTERVAL);

    // Atualiza "atualizado há" entre polls
    setInterval(() => {
        if (state.lastGeneratedAt) renderUpdated(state.lastGeneratedAt);
    }, 5000);

    // Re-fetch quando a aba volta ao foco (TV desligada e religada)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData();
    });
}

export default initTvWallboard;
