// assets/js/pages/partner-feed-form.js
'use strict';

let map = null;
let marker = null;
let polylinePath = null;
let polylinePoints = [];
let isRoadClosedMode = false;
let controller = null;
let debounceTimer = null;
let requestVersion = 0;
const initialized = new WeakSet();

function field(root, id) {
    return root.querySelector(`#${id}`);
}

function getConfig(root) {
    const el = root.matches?.('[data-form-config]')
        ? root : root.querySelector('[data-form-config]');
    const data = el?.dataset ?? {};
    let points = [];
    try {
        const parsed = JSON.parse(data.existingPolyline || '[]');
        if (Array.isArray(parsed)) {
            points = parsed.filter(p => Array.isArray(p) && p.length >= 2)
                .map(p => [Number(p[0]), Number(p[1])])
                .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng)
                    && Math.abs(lat) <= 90 && Math.abs(lng) <= 180);
        }
    } catch (error) {
        console.warn('[pf-form] Geometria inválida:', error);
    }
    return {
        partnerCode: data.partnerCode || '',
        geoToken: data.geoToken || '',
        defaultLat: Number.parseFloat(data.defaultLat || '-20.6597'),
        defaultLng: Number.parseFloat(data.defaultLng || '-43.8081'),
        defaultZoom: Number.parseInt(data.defaultZoom || '13', 10),
        points,
    };
}

function showToast(message, duration = 3500) {
    let toast = document.getElementById('pf-validation-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'pf-validation-toast';
        toast.className = 'pf-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('is-visible'), duration);
}

function syncPolylineInput(root) {
    const input = field(root, 'pf-polyline-input');
    if (input) input.value = JSON.stringify(polylinePoints);
}

function renderGeometry(root, fit = false) {
    if (marker) map.removeLayer(marker);
    if (polylinePath) map.removeLayer(polylinePath);
    marker = null;
    polylinePath = null;
    if (polylinePoints.length) {
        if (isRoadClosedMode) {
            polylinePath = L.polyline(polylinePoints, {
                color: '#ef4444', weight: 5, opacity: 0.9,
            }).addTo(map);
            if (fit && polylinePoints.length > 1) {
                map.fitBounds(polylinePath.getBounds(), { padding: [50, 50] });
            }
        } else {
            marker = L.marker(polylinePoints[0]).addTo(map);
            if (fit) map.setView(polylinePoints[0], map.getZoom());
        }
    }
    syncPolylineInput(root);
    updateCoordsDisplay(root);
}

function updateCoordsDisplay(root) {
    const display = field(root, 'pf-coords-display');
    if (!display) return;
    if (!polylinePoints.length) {
        display.textContent = 'Lat: —  Lng: —';
    } else if (isRoadClosedMode) {
        const first = polylinePoints[0];
        const last = polylinePoints[polylinePoints.length - 1];
        display.textContent = `Pontos: ${polylinePoints.length} · Início: ${first[0].toFixed(6)}, ${first[1].toFixed(6)} · Fim: ${last[0].toFixed(6)}, ${last[1].toFixed(6)}`;
    } else {
        const [lat, lng] = polylinePoints[0];
        display.textContent = `Lat: ${lat.toFixed(6)} · Lng: ${lng.toFixed(6)}`;
    }
}

function cancelReverseGeocode(root) {
    requestVersion += 1;
    clearTimeout(debounceTimer);
    debounceTimer = null;
    controller?.abort();
    controller = null;
    const street = field(root, 'street');
    if (street) {
        street.disabled = false;
        street.removeAttribute('aria-busy');
    }
}

function reverseGeocode(lat, lng, root, config) {
    cancelReverseGeocode(root);
    const version = requestVersion;
    debounceTimer = setTimeout(() => {
        debounceTimer = null;
        void lookupAddress(lat, lng, root, config, version);
    }, 400);
}

async function lookupAddress(lat, lng, root, config, version) {
    const streetInput = field(root, 'street');
    const referenceInput = field(root, 'reference');
    if (!streetInput || version !== requestVersion) return;
    const active = new AbortController();
    controller = active;
    const timeout = setTimeout(() => active.abort(), 10000);
    const oldStreet = streetInput.value;
    const oldReference = referenceInput?.value ?? '';
    let streetEdited = false;
    let referenceEdited = false;
    const onStreetInput = () => { streetEdited = true; };
    const onReferenceInput = () => { referenceEdited = true; };
    streetInput.addEventListener('input', onStreetInput);
    referenceInput?.addEventListener('input', onReferenceInput);
    streetInput.disabled = false;
    streetInput.setAttribute('aria-busy', 'true');
    const current = () => version === requestVersion && !active.signal.aborted;
    const fetchJson = async url => {
        const response = await fetch(url, {
            signal: active.signal, headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    };
    try {
        let street = '';
        let reference = '';
        if (config.geoToken && config.partnerCode) {
            const url = new URL(`/partner-feed/${encodeURIComponent(config.partnerCode)}/reverse-geocode`, window.location.origin);
            url.searchParams.set('lat', String(lat));
            url.searchParams.set('lon', String(lng));
            try {
                const data = await fetchJson(url);
                const top = Array.isArray(data?.result) ? data.result[0] : null;
                street = typeof top?.names?.[0] === 'string' ? top.names[0] : '';
            } catch (error) {
                if (active.signal.aborted) throw error;
                console.warn('[pf-form] Proxy de geocodificação falhou:', error);
            }
        }
        if (!current()) return;
        if (!street || (referenceInput && !oldReference.trim())) {
            const url = new URL('https://nominatim.openstreetmap.org/reverse');
            url.searchParams.set('format', 'json');
            url.searchParams.set('lat', String(lat));
            url.searchParams.set('lon', String(lng));
            url.searchParams.set('addressdetails', '1');
            try {
                const data = await fetchJson(url);
                const address = data?.address;
                if (address) {
                    street ||= address.road || address.residential || address.pedestrian || address.neighbourhood || '';
                    reference = address.city || address.town || address.village || address.county || address.state || '';
                }
            } catch (error) {
                if (active.signal.aborted) throw error;
                console.warn('[pf-form] Nominatim falhou:', error);
            }
        }
        if (!current()) return;
        if (street && !streetEdited && streetInput.value === oldStreet) streetInput.value = street;
        if (referenceInput && reference && !referenceEdited && !oldReference.trim()
            && referenceInput.value === oldReference) referenceInput.value = reference;
        if (!street) showToast('Não foi possível localizar a rua. Preencha o endereço manualmente.');
    } catch (error) {
        if (version !== requestVersion) return;
        if (error.name === 'AbortError') {
            showToast('A busca de endereço excedeu o tempo limite. Preencha a rua manualmente.');
        } else {
            console.error('[pf-form] Erro na geocodificação:', error);
            showToast('Erro ao buscar endereço. Preencha a rua manualmente.');
        }
    } finally {
        clearTimeout(timeout);
        streetInput.removeEventListener('input', onStreetInput);
        referenceInput?.removeEventListener('input', onReferenceInput);
        if (version === requestVersion) {
            streetInput.disabled = false;
            streetInput.removeAttribute('aria-busy');
        }
        if (controller === active) controller = null;
    }
}

function updateMapInstructions(root) {
    const badge = field(root, 'pf-map-mode-badge');
    const instructions = field(root, 'pf-map-instructions');
    if (badge) {
        badge.classList.add('pf-map__mode-badge');
        badge.classList.toggle('pf-map__mode-badge--road', isRoadClosedMode);
        badge.textContent = isRoadClosedMode ? '🚧 Via interditada (polyline)' : '📍 Ponto único';
    }
    if (instructions) instructions.textContent = isRoadClosedMode
        ? 'Clique no mapa para adicionar pontos. Mínimo 2 pontos.'
        : 'Clique no mapa para marcar o local exato do evento.';
}

function updateSubtypes(root, preserve = false) {
    const typeSelect = field(root, 'cifsType');
    const subtypeSelect = field(root, 'cifsSubtype');
    if (!typeSelect || !subtypeSelect) return;
    const previous = subtypeSelect.value;
    subtypeSelect.querySelectorAll('option').forEach(option => {
        option.hidden = option.value !== '' && option.dataset.type !== typeSelect.value;
    });
    if (preserve) subtypeSelect.value = previous;
    else subtypeSelect.value = '';
}

function onTypeChange(root) {
    cancelReverseGeocode(root);
    isRoadClosedMode = field(root, 'cifsType')?.value === 'ROAD_CLOSED';
    if (!isRoadClosedMode && polylinePoints.length > 1) polylinePoints = [polylinePoints[0]];
    updateMapInstructions(root);
    updateSubtypes(root);
    if (map) renderGeometry(root);
}

function clearMap(root, config) {
    cancelReverseGeocode(root);
    polylinePoints = [];
    renderGeometry(root);
    const street = field(root, 'street');
    if (street) street.value = '';
    map.setView([config.defaultLat, config.defaultLng], config.defaultZoom);
}

function initMap(root, config) {
    const mapEl = field(root, 'pf-map');
    if (!mapEl || typeof L === 'undefined' || map) return;
    map = L.map(mapEl).setView([config.defaultLat, config.defaultLng], config.defaultZoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);
    polylinePoints = config.points;
    isRoadClosedMode = field(root, 'cifsType')?.value === 'ROAD_CLOSED';
    // Restore geometry without querying or overwriting the saved address.
    renderGeometry(root, true);
    updateMapInstructions(root);
    updateSubtypes(root, true);
    map.on('click', event => {
        const lat = Number(event.latlng.lat);
        const lng = Number(event.latlng.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return;
        if (isRoadClosedMode) {
            cancelReverseGeocode(root);
            polylinePoints.push([lat, lng]);
        } else {
            polylinePoints = [[lat, lng]];
            reverseGeocode(lat, lng, root, config);
        }
        renderGeometry(root);
    });
    field(root, 'pf-clear-map-btn')?.addEventListener('click', () => clearMap(root, config));
}

function initFormValidation(root) {
    const form = field(root, 'event-form');
    if (!form) return;
    form.addEventListener('submit', event => {
        syncPolylineInput(root);
        cancelReverseGeocode(root);
        if (!map) {
            event.preventDefault();
            showToast('O mapa não foi carregado. Recarregue a página antes de salvar.');
            return;
        }
        if (isRoadClosedMode && polylinePoints.length < 2) {
            event.preventDefault();
            showToast('Via interditada precisa de pelo menos 2 pontos no mapa.');
        } else if (!isRoadClosedMode && polylinePoints.length !== 1) {
            event.preventDefault();
            showToast('Selecione um local no mapa antes de salvar.');
        } else if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });
}

export function init(root = document) {
    const form = field(root, 'event-form');
    if (!form || initialized.has(form)) return;
    initialized.add(form);
    const config = getConfig(root);
    if (typeof L !== 'undefined') initMap(root, config);
    else if (document.readyState !== 'complete') {
        window.addEventListener('load', () => initMap(root, config), { once: true });
    } else showToast('Não foi possível carregar o mapa. Recarregue a página.');
    field(root, 'cifsType')?.addEventListener('change', () => onTypeChange(root));
    window.addEventListener('pagehide', () => cancelReverseGeocode(root), { once: true });
    initFormValidation(root);
}

export default init;
