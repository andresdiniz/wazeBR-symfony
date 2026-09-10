/**
 * wazeBR — CIFS Event Manager
 *
 * Dependências:
 *   - Leaflet 1.9+ (carregado antes deste script via <script> no template)
 *   - window.CIFS_ROUTES (injetado pelo Twig com os paths das rotas)
 *
 * Responsabilidades:
 *   1. Mapa Leaflet com ferramenta de desenho de polilinha
 *   2. Formulário de criação de evento (POST JSON → /cifs/api/event)
 *   3. Desativação de evento (POST → /cifs/api/event/{id}/deactivate)
 *   4. Geocodificação reversa para sugerir nome de via
 *   5. Filtro client-side da lista de eventos
 *   6. Agendamento semanal (habilita/desabilita inputs)
 *   7. Contador de caracteres na descrição
 *   8. Flash messages descartáveis
 */

(function () {
    'use strict';

    /* ── Configuração ─────────────────────────────────────────────── */

    const ROUTES = window.CIFS_ROUTES || {};

    // Centro padrão: Brasil
    const DEFAULT_CENTER = [-15.78, -47.93];
    const DEFAULT_ZOOM   = 5;

    /* ── Estado ───────────────────────────────────────────────────── */

    let map         = null;
    let drawing     = false;
    let points      = [];          // Array de L.LatLng
    let polylineLayer = null;
    let markerLayers  = [];

    /* ================================================================
       1. INICIALIZAÇÃO
    ================================================================ */

    document.addEventListener('DOMContentLoaded', function () {
        initMap();
        initForm();
        initDeactivateButtons();
        initEventFilter();
        initScheduleCheckboxes();
        initDescriptionCounter();
        initFlashMessages();
        initAccountDropdown();
    });

    /* ================================================================
       2. MAPA LEAFLET
    ================================================================ */

    function initMap() {
        const container = document.getElementById('cifs-map');
        if (!container || typeof L === 'undefined') return;

        map = L.map('cifs-map', {
            center: DEFAULT_CENTER,
            zoom: DEFAULT_ZOOM,
            zoomControl: true,
        });

        // Tile layer OpenStreetMap
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);

        // Botão Desenhar
        const btnDraw = document.getElementById('btn-draw');
        if (btnDraw) {
            btnDraw.addEventListener('click', toggleDrawMode);
        }

        // Botão Limpar mapa
        const btnClear = document.getElementById('btn-clear-map');
        if (btnClear) {
            btnClear.addEventListener('click', clearMap);
        }

        // Clique no mapa para adicionar ponto
        map.on('click', function (e) {
            if (!drawing) return;
            addPoint(e.latlng);
        });
    }

    function toggleDrawMode() {
        drawing = !drawing;
        const btn  = document.getElementById('btn-draw');
        const hint = document.getElementById('map-hint');

        if (drawing) {
            btn.classList.add('cifs-btn--primary');
            btn.innerHTML = '<i class="fas fa-stop"></i> Parar';
            if (hint) hint.textContent = 'Clique no mapa para adicionar pontos ao segmento';
            if (map) map.getContainer().style.cursor = 'crosshair';
        } else {
            btn.classList.remove('cifs-btn--primary');
            btn.innerHTML = '<i class="fas fa-draw-polygon"></i> Desenhar';
            if (hint) hint.textContent = points.length > 0
                ? `${points.length} ponto(s) marcado(s)`
                : 'Clique em "Desenhar" e marque pontos no mapa';
            if (map) map.getContainer().style.cursor = '';
            commitPolyline();
        }
    }

    function addPoint(latlng) {
        points.push(latlng);

        // Marcador pequeno
        const marker = L.circleMarker(latlng, {
            radius: 5,
            color: '#3b82f6',
            fillColor: '#3b82f6',
            fillOpacity: 1,
            weight: 2,
        }).addTo(map);
        markerLayers.push(marker);

        // Redesenha linha
        if (polylineLayer) map.removeLayer(polylineLayer);
        if (points.length >= 2) {
            polylineLayer = L.polyline(points, {
                color: '#3b82f6',
                weight: 4,
                opacity: .8,
                dashArray: '6 4',
            }).addTo(map);
        }

        updatePolylineField();
    }

    function commitPolyline() {
        if (points.length < 2) return;

        // Redesenha sem dash
        if (polylineLayer) map.removeLayer(polylineLayer);
        polylineLayer = L.polyline(points, {
            color: '#2563eb',
            weight: 4,
            opacity: .9,
        }).addTo(map);

        map.fitBounds(polylineLayer.getBounds(), { padding: [30, 30] });
        updatePolylineField();
    }

    function clearMap() {
        points = [];
        drawing = false;

        if (polylineLayer) { map.removeLayer(polylineLayer); polylineLayer = null; }
        markerLayers.forEach(m => map.removeLayer(m));
        markerLayers = [];

        const btn  = document.getElementById('btn-draw');
        const hint = document.getElementById('map-hint');
        if (btn)  { btn.innerHTML = '<i class="fas fa-draw-polygon"></i> Desenhar'; }
        if (hint) { hint.textContent = 'Clique em "Desenhar" e marque pontos no mapa'; }
        if (map)  { map.getContainer().style.cursor = ''; }

        updatePolylineField();
    }

    /** Serializa pontos em "lat lon lat lon …" (formato CIFS) */
    function updatePolylineField() {
        const input   = document.getElementById('f-polyline');
        const preview = document.getElementById('polyline-preview');
        const text    = document.getElementById('polyline-preview-text');

        const str = points.map(p => `${p.lat.toFixed(6)} ${p.lng.toFixed(6)}`).join(' ');
        if (input) input.value = str;

        if (preview) {
            preview.hidden = points.length < 2;
        }
        if (text) {
            text.textContent = points.length >= 2
                ? `${points.length} pontos · ${computeLength(points).toFixed(2)} km`
                : '';
        }
    }

    /** Distância aproximada da polilinha em km (Haversine) */
    function computeLength(pts) {
        let total = 0;
        for (let i = 1; i < pts.length; i++) {
            total += haversine(pts[i - 1], pts[i]);
        }
        return total;
    }

    function haversine(a, b) {
        const R = 6371;
        const dLat = deg2rad(b.lat - a.lat);
        const dLon = deg2rad(b.lng - a.lng);
        const x = Math.sin(dLat / 2) ** 2
            + Math.cos(deg2rad(a.lat)) * Math.cos(deg2rad(b.lat)) * Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }

    function deg2rad(d) { return d * Math.PI / 180; }

    /* ── Botão limpar polilinha no preview ─── */
    document.addEventListener('click', function (e) {
        if (e.target.closest('#btn-clear-polyline')) clearMap();
    });

    /* ================================================================
       3. GEOCODIFICAÇÃO REVERSA
    ================================================================ */

    document.addEventListener('click', async function (e) {
        if (!e.target.closest('#btn-geocode')) return;

        if (!ROUTES.apiGeocodeReverse) return;
        if (points.length === 0) {
            showFormError('Marque pelo menos um ponto no mapa antes de sugerir a via.');
            return;
        }

        const mid = points[Math.floor(points.length / 2)];
        const url = `${ROUTES.apiGeocodeReverse}?lat=${mid.lat}&lon=${mid.lng}`;

        try {
            const resp = await fetch(url);
            const data = await resp.json();
            const candidates = data.candidates || [];
            showStreetSuggestions(candidates);
        } catch {
            showFormError('Geocodificação indisponível no momento.');
        }
    });

    function showStreetSuggestions(candidates) {
        const list  = document.getElementById('street-suggestions');
        const input = document.getElementById('f-street');
        if (!list || !input) return;

        list.innerHTML = '';

        if (candidates.length === 0) {
            list.hidden = true;
            showFormError('Nenhuma via encontrada para o ponto selecionado.');
            return;
        }

        candidates.forEach(c => {
            const li = document.createElement('li');
            li.textContent = c.name;
            if (c.distance != null) {
                li.textContent += ` (${c.distance.toFixed(0)} m)`;
            }
            li.addEventListener('click', function () {
                input.value = c.name;
                list.hidden = true;
            });
            list.appendChild(li);
        });

        list.hidden = false;
    }

    // Fecha sugestões ao clicar fora
    document.addEventListener('click', function (e) {
        const list = document.getElementById('street-suggestions');
        if (list && !list.contains(e.target) && e.target.id !== 'f-street') {
            list.hidden = true;
        }
    });

    /* ================================================================
       4. FORMULÁRIO DE CRIAÇÃO
    ================================================================ */

    function initForm() {
        const form = document.getElementById('cifs-form');
        if (!form) return;

        // Tipo → subtype hidden + mostrar/ocultar lane impact
        const typeSelect    = document.getElementById('f-type');
        const subtypeInput  = document.getElementById('f-subtype');
        const dirSelect     = document.getElementById('f-direction');
        const laneSection   = document.getElementById('lane-impact-section');

        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                const opt     = this.options[this.selectedIndex];
                const subtype = opt.dataset.subtype || '';
                if (subtypeInput) subtypeInput.value = subtype;

                // lane_impact só disponível para não-ROAD_CLOSED + ONE_DIRECTION
                updateLaneImpactVisibility(typeSelect, dirSelect, laneSection);

                // starttime obrigatório para ROAD_CLOSED
                const startInput = document.getElementById('f-start');
                if (startInput) {
                    startInput.required = (this.value === 'ROAD_CLOSED');
                }
            });
        }

        if (dirSelect) {
            dirSelect.addEventListener('change', function () {
                updateLaneImpactVisibility(typeSelect, dirSelect, laneSection);
            });
        }

        // Data/hora mínima = agora
        const now = new Date();
        now.setSeconds(0, 0);
        const isoLocal = toLocalIso(now);
        const startInput = document.getElementById('f-start');
        if (startInput) startInput.min = isoLocal;

        // Submit
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            await submitCifsForm(form);
        });
    }

    function updateLaneImpactVisibility(typeSelect, dirSelect, laneSection) {
        if (!laneSection) return;
        const isRoadClosed = typeSelect && typeSelect.value === 'ROAD_CLOSED';
        const isOneDir     = dirSelect && dirSelect.value === 'ONE_DIRECTION';
        laneSection.hidden = isRoadClosed || !isOneDir;
    }

    async function submitCifsForm(form) {
        clearFormError();
        const submitBtn = document.getElementById('btn-submit');
        if (submitBtn) {
            submitBtn.classList.add('is-loading');
            submitBtn.disabled = true;
        }

        const polyline = document.getElementById('f-polyline')?.value ?? '';
        if (!polyline) {
            showFormError('Desenhe o segmento no mapa antes de criar o evento.');
            restoreButton(submitBtn);
            return;
        }

        const payload = buildPayload(form);

        try {
            const resp = await fetch(ROUTES.apiSave, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            const data = await resp.json();

            if (!resp.ok) {
                showFormError(data.error || `Erro ${resp.status} ao criar evento.`);
                restoreButton(submitBtn);
                return;
            }

            // Sucesso
            showFlash('Evento criado com sucesso!', 'success');
            form.reset();
            clearMap();
            setTimeout(() => location.reload(), 1200);
        } catch (err) {
            showFormError('Falha de rede ao enviar o evento. Tente novamente.');
            restoreButton(submitBtn);
        }
    }

    function buildPayload(form) {
        const fd = new FormData(form);

        const payload = {
            type:        fd.get('type')        || null,
            subtype:     fd.get('subtype')     || null,
            direction:   fd.get('direction')   || null,
            street:      fd.get('street')      || null,
            description: fd.get('description') || null,
            starttime:   fd.get('starttime')   || null,
            endtime:     fd.get('endtime')     || null,
            polyline:    fd.get('polyline')    || null,
        };

        // Schedule
        const schedule = {};
        form.querySelectorAll('.schedule-day-cb:checked').forEach(cb => {
            const day    = cb.value;
            const ranges = form.querySelector(`[name="schedule[${day}]"]`)?.value?.trim();
            if (ranges) schedule[day] = ranges;
        });
        if (Object.keys(schedule).length) payload.schedule = schedule;

        // Lane impact
        const lanes    = fd.get('lane_impact[total_closed_lanes]');
        const roadside = fd.get('lane_impact[roadside]');
        if (lanes) {
            payload.lane_impact = { total_closed_lanes: parseInt(lanes) };
            if (roadside) payload.lane_impact.roadside = roadside;
        }

        return payload;
    }

    function restoreButton(btn) {
        if (!btn) return;
        btn.classList.remove('is-loading');
        btn.disabled = false;
    }

    /* ================================================================
       5. DESATIVAR EVENTO
    ================================================================ */

    function initDeactivateButtons() {
        document.addEventListener('click', async function (e) {
            const btn = e.target.closest('.btn-deactivate');
            if (!btn) return;

            const id = btn.dataset.id;
            if (!id || !ROUTES.apiDeactivate) return;

            if (!confirm('Desativar este evento? Ele será removido do feed Waze.')) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const url  = ROUTES.apiDeactivate.replace('__ID__', id);
                const resp = await fetch(url, { method: 'POST' });
                if (resp.ok) {
                    const item = btn.closest('.cifs-event-item');
                    if (item) {
                        item.classList.add('cifs-event-item--inactive');
                        item.dataset.active = '0';
                        const status = item.querySelector('.cifs-status');
                        if (status) {
                            status.className = 'cifs-status cifs-status--inactive';
                            status.textContent = 'Inativo';
                        }
                        btn.remove();
                    }
                    showFlash('Evento desativado.', 'success');
                } else {
                    const data = await resp.json();
                    showFlash(data.message || 'Erro ao desativar.', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-stop"></i>';
                }
            } catch {
                showFlash('Falha de rede ao desativar.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-stop"></i>';
            }
        });
    }

    /* ================================================================
       6. FILTRO CLIENT-SIDE
    ================================================================ */

    function initEventFilter() {
        const searchInput  = document.getElementById('filter-events');
        const activeToggle = document.getElementById('filter-active');

        function applyFilter() {
            const query     = searchInput ? searchInput.value.toLowerCase() : '';
            const onlyActive = activeToggle ? activeToggle.checked : false;

            document.querySelectorAll('.cifs-event-item').forEach(item => {
                const active  = item.dataset.active === '1';
                const street  = item.dataset.street || '';
                const type    = item.dataset.type || '';

                const matchActive = !onlyActive || active;
                const matchQuery  = !query || street.includes(query) || type.toLowerCase().includes(query);

                item.hidden = !(matchActive && matchQuery);
            });
        }

        if (searchInput)  searchInput.addEventListener('input', applyFilter);
        if (activeToggle) activeToggle.addEventListener('change', applyFilter);
    }

    /* ================================================================
       7. AGENDAMENTO SEMANAL
    ================================================================ */

    function initScheduleCheckboxes() {
        document.querySelectorAll('.schedule-day-cb').forEach(cb => {
            cb.addEventListener('change', function () {
                const row = this.closest('.cifs-schedule-row');
                const input = row?.querySelector('.schedule-hours');
                if (input) {
                    input.disabled = !this.checked;
                    if (!this.checked) input.value = '';
                }
            });
        });
    }

    /* ================================================================
       8. CONTADOR DE CARACTERES
    ================================================================ */

    function initDescriptionCounter() {
        const descInput = document.getElementById('f-description');
        const counter   = document.getElementById('desc-count');

        if (descInput && counter) {
            descInput.addEventListener('input', function () {
                counter.textContent = this.value.length;
            });
        }
    }

    /* ================================================================
       9. FLASH MESSAGES DESCARTÁVEIS
    ================================================================ */

    function initFlashMessages() {
        document.querySelectorAll('.cifs-flash__close').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.cifs-flash')?.remove();
            });
        });
    }

    function showFlash(message, type = 'success') {
        const main = document.getElementById('cifs-main');
        if (!main) return;

        const icons = { success: 'check-circle', danger: 'circle-xmark', info: 'info-circle' };
        const icon  = icons[type] || 'info-circle';

        const el = document.createElement('div');
        el.className = `cifs-flash cifs-flash--${type}`;
        el.role = 'alert';
        el.innerHTML = `
            <i class="fas fa-${icon}"></i>
            ${escapeHtml(message)}
            <button type="button" class="cifs-flash__close" aria-label="Fechar">
                <i class="fas fa-times"></i>
            </button>`;
        el.querySelector('.cifs-flash__close').addEventListener('click', () => el.remove());
        main.insertBefore(el, main.firstChild);

        setTimeout(() => el.remove(), 6000);
    }

    /* ================================================================
       10. ERROS DO FORMULÁRIO
    ================================================================ */

    function showFormError(message) {
        const el = document.getElementById('form-error');
        if (!el) return;
        el.textContent = message;
        el.hidden = false;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearFormError() {
        const el = document.getElementById('form-error');
        if (el) el.hidden = true;
    }

    /* ================================================================
       11. DROPDOWN DE CONTA
    ================================================================ */

    function initAccountDropdown() {
        const trigger  = document.getElementById('accountMenuTrigger');
        const dropdown = document.getElementById('accountDropdown');
        if (!trigger || !dropdown) return;

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = !dropdown.hidden;
            dropdown.hidden = isOpen;
            trigger.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function () {
            dropdown.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        });
    }

    /* ================================================================
       UTILITÁRIOS
    ================================================================ */

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function toLocalIso(date) {
        const pad = n => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
             + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

})();
