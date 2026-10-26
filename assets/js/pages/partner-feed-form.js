// assets/js/pages/partner-feed-form.js

'use strict';

// ── Estado global do mapa ──────────────────────────────────────────────────

let map = null;
let marker = null;
let polylinePath = null;
let polylinePoints = [];
let isRoadClosedMode = false;   // modo polyline (ROAD_CLOSED ou evento já salvo com 2+ pontos)
let allSubtypeOptions = [];    // cópia das <option> de subtipo, para refiltrar por tipo

const DESCRIPTION_MAX = 40;    // recomendação da spec CIFS do Waze

// ── Configuração injetada pelo Twig via data-attributes ────────────────────

function getConfig(root) {
    const el = root.querySelector('[data-form-config]');
    if (!el) return {};
    return {
        partnerCode:  el.dataset.partnerCode ?? '',
        defaultLat:   parseFloat(el.dataset.defaultLat  ?? '-20.6597'),
        defaultLng:   parseFloat(el.dataset.defaultLng  ?? '-43.8081'),
        defaultZoom:  parseInt(el.dataset.defaultZoom   ?? '13', 10),
        geoToken:     el.dataset.geoToken   ?? '',
        geoRegion:    el.dataset.geoRegion  ?? 'ROW',
        existingPolyline: el.dataset.existingPolyline
            ? JSON.parse(el.dataset.existingPolyline)
            : null,
    };
}

// ── Toast de validação (substitui alert()) ─────────────────────────────────

function showToast(message, duration = 4000) {
    let toast = document.getElementById('pf-validation-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'pf-validation-toast';
        toast.className = 'pf-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        document.body.appendChild(toast);
    }

    toast.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span></span>
    `;
    toast.querySelector('span').textContent = message; // texto puro, sem HTML

    toast.classList.add('is-visible');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('is-visible'), duration);
}

// ── Mapa ───────────────────────────────────────────────────────────────────

function initMap(root, config) {
    const mapEl = root.getElementById('pf-map');
    if (!mapEl || typeof L === 'undefined') return;

    const {
        defaultLat, defaultLng, defaultZoom,
        geoToken, geoRegion, partnerCode,
        existingPolyline,
    } = config;

    map = L.map('pf-map').setView([defaultLat, defaultLng], defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    map.on('click', (e) => onMapClick(e, root, geoToken, geoRegion, partnerCode));
    // Botão direito (ou toque longo) remove o último ponto da polyline
    map.on('contextmenu', () => undoLastPoint(root));

    root.getElementById('pf-clear-map-btn')
        ?.addEventListener('click', () => clearMap(root, defaultLat, defaultLng, defaultZoom));

    // Restaura geometria existente
    if (existingPolyline && existingPolyline.length > 0) {
        polylinePoints = existingPolyline;

        if (polylinePoints.length > 1) {
            isRoadClosedMode = true;
            drawPolyline(root, true);
            updateCoordsDisplay(root);
            updateMapInstructions(root);
        } else {
            const [lat, lng] = polylinePoints[0];
            marker = L.marker([lat, lng]).addTo(map);
            updateCoordsDisplay(root, lat, lng);
            reverseGeocode(lat, lng, root, geoToken, geoRegion, partnerCode);
        }
    }

    onTypeChange(root);
}

function onMapClick(e, root, geoToken, geoRegion, partnerCode) {
    const { lat, lng } = e.latlng;

    if (isRoadClosedMode) {
        polylinePoints.push([lat, lng]);
        drawPolyline(root);
        updateCoordsDisplay(root);
    } else {
        polylinePoints = [[lat, lng]];
        if (marker) map.removeLayer(marker);
        marker = L.marker([lat, lng]).addTo(map);
        updateCoordsDisplay(root, lat, lng);
        reverseGeocode(lat, lng, root, geoToken, geoRegion, partnerCode);
    }
}

function drawPolyline(root, fit = false) {
    if (polylinePath) map.removeLayer(polylinePath);

    if (polylinePoints.length > 0) {
        polylinePath = L.polyline(polylinePoints, {
            color: '#ef4444',
            weight: 5,
            opacity: 0.9,
        }).addTo(map);

        if (fit && polylinePoints.length > 1) {
            map.fitBounds(polylinePath.getBounds(), { padding: [50, 50] });
        }
    }

    const input = root.getElementById('pf-polyline-input');
    if (input) input.value = JSON.stringify(polylinePoints);
}

function undoLastPoint(root) {
    if (!isRoadClosedMode || polylinePoints.length === 0) return;

    polylinePoints.pop();
    if (polylinePath) { map.removeLayer(polylinePath); polylinePath = null; }
    drawPolyline(root);
    updateCoordsDisplay(root);
}

function updateCoordsDisplay(root, lat = null, lng = null) {
    const display = root.getElementById('pf-coords-display');
    if (!display) return;

    if (isRoadClosedMode && polylinePoints.length > 1) {
        const first = polylinePoints[0];
        const last  = polylinePoints[polylinePoints.length - 1];
        display.innerHTML = `
            <strong>Pontos:</strong> ${polylinePoints.length}<br>
            <strong>Início:</strong> ${first[0].toFixed(6)}, ${first[1].toFixed(6)}<br>
            <strong>Fim:</strong> ${last[0].toFixed(6)}, ${last[1].toFixed(6)}
        `;
    } else if (lat !== null && lng !== null) {
        display.innerHTML = `
            <strong>Lat:</strong> ${lat.toFixed(6)}<br>
            <strong>Lng:</strong> ${lng.toFixed(6)}
        `;
    } else {
        display.innerHTML = 'Lat: —&nbsp; Lng: —';
    }
}

// ── Reverse Geocoding ──────────────────────────────────────────────────────
// ✅ CORRIGIDO — chamada via proxy interno (evita CORS) e formato real da API.
// Documentação: https://support.google.com/waze/partners/answer/11486981
// Formato real: { result: [{ names: ["Rua X"], distance: 4.23 }, ...] }
async function reverseGeocode(lat, lng, root, geoToken, geoRegion, partnerCode) {
    const streetInput    = root.getElementById('street');
    const referenceInput = root.getElementById('reference');
    if (!streetInput) return;

    streetInput.value    = 'Buscando endereço…';
    streetInput.disabled = true;

    // ── Tentativa 1: proxy interno → Waze ─────────────────────────────────
    if (geoToken && partnerCode) {
        try {
            const url = new URL(
                `/partner-feed/${encodeURIComponent(partnerCode)}/reverse-geocode`,
                window.location.origin,
            );
            url.searchParams.set('lat', String(lat));
            url.searchParams.set('lon', String(lng));

            const res = await fetch(url.toString());

            if (res.ok) {
                const data = await res.json();

                const top = Array.isArray(data?.result) ? data.result[0] : null;
                const streetName = top?.names?.[0] ?? null;

                if (streetName) {
                    streetInput.value = streetName;
                    streetInput.disabled = false;

                    // A API do Waze não retorna cidade; Nominatim enriquece 'reference'
                    if (referenceInput && !referenceInput.value) {
                        try {
                            const nomRes = await fetch(
                                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`
                            );
                            const nomData = await nomRes.json();
                            const addr = nomData?.address;
                            if (addr) {
                                const ref = addr.city || addr.town || addr.village
                                    || addr.county || addr.state || '';
                                if (ref) referenceInput.value = ref;
                            }
                        } catch (_) { /* silencioso */ }
                    }
                    return;
                }
            } else {
                console.warn('[pf-form] Waze proxy HTTP', res.status);
            }
        } catch (err) {
            console.warn('[pf-form] Waze proxy falhou:', err);
        }
    }

    // ── Tentativa 2: Nominatim (fallback completo) ────────────────────────
    try {
        const res  = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`
        );
        const data = await res.json();

        if (data?.address) {
            const { address } = data;
            const street = address.road || address.residential || address.pedestrian
                || address.neighbourhood || address.city || address.town || address.village || '';
            const ref = address.city || address.town || address.village
                || address.county || address.state || '';

            streetInput.value = street || 'Endereço não encontrado';
            if (referenceInput && ref) referenceInput.value = ref;
        } else {
            streetInput.value = 'Endereço não encontrado';
        }
    } catch (err) {
        console.error('[pf-form] Nominatim falhou:', err);
        streetInput.value = 'Erro ao buscar endereço';
    } finally {
        streetInput.disabled = false;
    }
}

function clearMap(root, defaultLat, defaultLng, defaultZoom) {
    if (marker)       { map.removeLayer(marker);       marker       = null; }
    if (polylinePath) { map.removeLayer(polylinePath); polylinePath = null; }
    polylinePoints = [];

    const polylineInput = root.getElementById('pf-polyline-input');
    const streetInput   = root.getElementById('street');
    const coordDisplay  = root.getElementById('pf-coords-display');

    if (polylineInput) polylineInput.value = '';
    if (streetInput)   streetInput.value   = '';
    if (coordDisplay)  coordDisplay.innerHTML = 'Lat: —&nbsp; Lng: —';

    map.setView([defaultLat, defaultLng], defaultZoom);
}

// ── Tipo / subtipo ─────────────────────────────────────────────────────────

function onTypeChange(root) {
    const typeSelect = root.getElementById('cifsType');
    if (!typeSelect) return;

    // ROAD_CLOSED sempre usa polyline. Outros tipos também aceitam polyline na spec,
    // então um evento salvo com 2+ pontos mantém a geometria (antes era truncada
    // para o 1º ponto ao abrir a edição).
    isRoadClosedMode = typeSelect.value === 'ROAD_CLOSED' || polylinePoints.length > 1;

    updateMapInstructions(root);
    updateSubtypes(root);
}

function updateMapInstructions(root) {
    const badge        = root.getElementById('pf-map-mode-badge');
    const instructions = root.getElementById('pf-map-instructions');
    if (!badge || !instructions) return;

    if (isRoadClosedMode) {
        badge.textContent = '🚧 Trecho (polyline)';
        badge.classList.add('pf-map__mode-badge--road');
        badge.classList.remove('pf-map__mode-badge');
        instructions.textContent = 'Clique para adicionar pontos na ordem do tráfego (mínimo 2). Botão direito remove o último.';
    } else {
        badge.textContent = '📍 Ponto único';
        badge.classList.add('pf-map__mode-badge');
        badge.classList.remove('pf-map__mode-badge--road');
        instructions.textContent = 'Clique no mapa para marcar o local exato do evento.';
    }
}

function updateSubtypes(root) {
    const typeSelect    = root.getElementById('cifsType');
    const subtypeSelect = root.getElementById('cifsSubtype');
    if (!typeSelect || !subtypeSelect) return;

    if (allSubtypeOptions.length === 0) {
        allSubtypeOptions = Array.from(subtypeSelect.options).map((o) => ({
            value: o.value, label: o.textContent.trim(),
        }));
    }

    const selectedType = typeSelect.value;
    const current = subtypeSelect.value;

    // Casa por prefixo do valor (ROAD_CLOSED_EVENT -> ROAD_CLOSED). O data-type do
    // Twig usa só o 1º segmento ("ROAD"), o que escondia todos os subtipos de ROAD_CLOSED.
    const allowed = allSubtypeOptions.filter(
        (o) => o.value === '' || (selectedType !== '' && o.value.startsWith(selectedType + '_')),
    );

    subtypeSelect.replaceChildren(...allowed.map((o) => {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.label;
        return opt;
    }));

    // Mantém o subtipo salvo se ele ainda vale para o tipo (antes era sempre zerado).
    subtypeSelect.value = allowed.some((o) => o.value === current) ? current : '';
    subtypeSelect.disabled = selectedType === 'CHIT_CHAT'; // a spec não define subtipos p/ CHIT_CHAT
}

// ── Validação do formulário ────────────────────────────────────────────────

function setFieldError(root, id, message) {
    const input = root.getElementById(id);
    const field = input?.closest('.pf-field');
    if (!field) return;

    field.classList.toggle('pf-field--error', Boolean(message));
    let msg = field.querySelector('.pf-field__error');
    if (!message) { msg?.remove(); return; }
    if (!msg) {
        msg = document.createElement('div');
        msg.className = 'pf-field__error';
        msg.setAttribute('role', 'alert');
        field.appendChild(msg);
    }
    msg.textContent = message;
}

function initFieldHelpers(root) {
    // Contador da descrição (recomendação CIFS: até 40 caracteres, lida em voz alta no app)
    const desc = root.getElementById('description');
    if (desc) {
        const counter = document.createElement('div');
        counter.className = 'pf-counter';
        counter.setAttribute('aria-live', 'polite');
        desc.insertAdjacentElement('afterend', counter);
        const update = () => {
            const n = desc.value.trim().length;
            counter.textContent = `${n}/${DESCRIPTION_MAX} caracteres (recomendado pelo Waze)`;
            counter.classList.toggle('is-over', n > DESCRIPTION_MAX);
        };
        desc.addEventListener('input', update);
        update();
    }

    // Fim não pode ser anterior ao início
    const start = root.getElementById('startTime');
    const end   = root.getElementById('endTime');
    if (start && end) {
        const sync = () => {
            end.min = start.value || '';
            setFieldError(root, 'endTime',
                start.value && end.value && end.value <= start.value
                    ? 'O fim deve ser depois do início.' : '');
        };
        start.addEventListener('change', sync);
        end.addEventListener('change', sync);
        sync();
    }
}

function initFormValidation(root) {
    const form = root.getElementById('event-form');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        const get = (id) => root.getElementById(id)?.value?.trim() ?? '';
        const problems = [];

        if (!get('cifsType')) problems.push(['cifsType', 'Selecione o tipo do evento.']);
        else setFieldError(root, 'cifsType', '');

        const streetBad = get('street') === '' || /^(Buscando|Endereço não|Erro ao)/.test(get('street'));
        if (streetBad) problems.push(['street', 'Informe a rua (obrigatória no CIFS).']);
        else setFieldError(root, 'street', '');

        const start = get('startTime');
        const end   = get('endTime');
        if (start && end && end <= start) problems.push(['endTime', 'O fim deve ser depois do início.']);
        if (get('cifsType') === 'ROAD_CLOSED' && !start) problems.push(['startTime', 'Interdição precisa de início.']);

        let mapMessage = '';
        if (isRoadClosedMode && polylinePoints.length < 2) {
            mapMessage = 'Marque pelo menos 2 pontos no mapa.';
        } else if (!isRoadClosedMode && polylinePoints.length < 1) {
            mapMessage = 'Selecione um local no mapa antes de salvar.';
        } else if (get('direction') === 'ONE_DIRECTION' && polylinePoints.length < 2) {
            mapMessage = 'Para "Um sentido" marque 2+ pontos na ordem do tráfego.';
        }

        problems.forEach(([id, msg]) => setFieldError(root, id, msg));

        const first = mapMessage || problems[0]?.[1];
        if (first) {
            e.preventDefault();
            showToast(first);
            if (!mapMessage) root.getElementById(problems[0][0])?.focus();
        }
    });
}

// ── Ponto de entrada ───────────────────────────────────────────────────────

export function init(root = document) {
    const config = getConfig(root);

    // Leaflet é carregado via <script> no bloco javascripts — aguarda estar pronto
    if (typeof L !== 'undefined') {
        initMap(root, config);
    } else {
        window.addEventListener('load', () => initMap(root, config), { once: true });
    }

    // Listener do select de tipo via JS (sem onchange inline)
    root.getElementById('cifsType')
        ?.addEventListener('change', () => onTypeChange(root));

    initFieldHelpers(root);
    initFormValidation(root);
}

export default init;
