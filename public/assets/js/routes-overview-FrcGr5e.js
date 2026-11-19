/**
 * Painel "Situação agora" da lista de rotas.
 *
 *  - cartões só das PIORES rotas (e das favoritas), cada um com um mini-mapa Leaflet do trajeto;
 *  - mapas criados sob demanda (IntersectionObserver): cartões ocultos não carregam tiles;
 *  - atualização automática leve (?slim=1): indicadores e status dos cartões, só com a aba visível;
 *  - favoritar direto no cartão.
 *
 * Script clássico (sem import/export): o CSP do projeto não permite script inline.
 */
(() => {
    const overview = document.querySelector('[data-routes-overview]');
    if (!overview) return;

    const OSM_TILES = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const REFRESH_MS = 60000;
    const FILTER_KEY = 'routes.overview.filter';
    const LEVEL_COLORS = {
        none: '#16a34a', light: '#3b82f6', moderate: '#f59e0b', heavy: '#ea580c', severe: '#dc2626',
    };

    const clock = overview.querySelector('[data-overview-clock]');
    const overviewUrl = overview.dataset.overviewUrl;
    const grid = overview.querySelector('[data-overview-grid]');
    const chips = [...overview.querySelectorAll('[data-overview-filter]')];
    const statusEl = overview.querySelector('[data-overview-status]');
    const favEmpty = overview.querySelector('[data-overview-fav-empty]');
    const favCount = overview.querySelector('[data-favorite-count]');
    const worstCount = overview.querySelector('[data-worst-count]');

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    const parseJson = (value, fallback) => {
        try { return JSON.parse(value) ?? fallback; } catch { return fallback; }
    };

    // Textos traduzidos (mesmo JSON de routes.js); sem tradução, mantém o original em português.
    const messages = parseJson(document.querySelector('[data-routes-i18n]')?.dataset.routesI18n, {});
    const rt = (text) => (Object.hasOwn(messages, text) ? messages[text] : text);

    const cards = () => [...overview.querySelectorAll('[data-route-card]')];

    const setStatus = (message, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.classList.toggle('is-error', isError);
    };

    // ───────────────────────── Mini-mapas ─────────────────────────

    const cardMaps = new Map(); // elemento → { map, bounds }

    const showMapMessage = (el, text) => {
        el.classList.add('is-empty');
        el.textContent = text;
    };

    const initCardMap = (el) => {
        if (el.dataset.mapReady === '1') return;

        const segments = parseJson(el.dataset.routeSegments, [])
            .filter((segment) => Array.isArray(segment.path) && segment.path.length > 1);
        const incidents = parseJson(el.dataset.routeIncidents, [])
            .filter((incident) => Number.isFinite(incident.lat) && Number.isFinite(incident.lng));

        el.dataset.mapReady = '1';

        if (typeof window.L === 'undefined') {
            showMapMessage(el, rt('Mapa indisponível'));
            return;
        }
        if (segments.length === 0 && incidents.length === 0) {
            showMapMessage(el, rt('Sem traçado cadastrado'));
            return;
        }

        const map = L.map(el, {
            zoomControl: false,
            attributionControl: true,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            touchZoom: false,
            tap: false,
            preferCanvas: true,
        });
        map.attributionControl.setPrefix(false);
        L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

        const points = [];
        segments.forEach((segment) => {
            L.polyline(segment.path, {
                color: LEVEL_COLORS[segment.level] ?? LEVEL_COLORS.none,
                weight: 5,
                opacity: 0.95,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(map);
            points.push(...segment.path);
        });
        incidents.forEach((incident) => {
            L.circleMarker([incident.lat, incident.lng], {
                radius: 5, color: '#ffffff', weight: 1.5, fillColor: '#7c3aed', fillOpacity: 0.95,
            })
                .bindTooltip(escapeHtml(incident.street || incident.type || rt('Incidente')), { direction: 'top' })
                .addTo(map);
            points.push([incident.lat, incident.lng]);
        });

        const bounds = L.latLngBounds(points);
        const fit = () => {
            map.invalidateSize();
            if (points.length === 1) map.setView(points[0], 16);
            else map.fitBounds(bounds, { padding: [14, 14], maxZoom: 16 });
        };
        fit();
        cardMaps.set(el, { fit });

        // Clicar no mapa abre os detalhes da rota (o mapa do cartão não é interativo).
        const link = el.closest('[data-route-card]')?.querySelector('.route-card-action');
        if (link) {
            el.classList.add('is-link');
            el.addEventListener('click', () => { window.location.href = link.href; });
        }
    };

    // Cria o mapa só quando o cartão fica visível; ao reaparecer (filtro), reenquadra.
    const observer = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const el = entry.target;
                if (el.dataset.mapReady === '1') cardMaps.get(el)?.fit();
                else initCardMap(el);
            });
        }, { rootMargin: '160px' })
        : null;

    const watchMaps = () => {
        overview.querySelectorAll('[data-route-map]').forEach((el) => {
            if (observer) observer.observe(el);
            else initCardMap(el);
        });
    };

    // ───────────────────────── Filtro (piores / favoritas) ─────────────────────────

    let filter = 'worst';
    try { filter = localStorage.getItem(FILTER_KEY) === 'favorites' ? 'favorites' : 'worst'; } catch { /* storage indisponível */ }

    const applyFilter = () => {
        const all = cards();
        let visible = 0;
        all.forEach((card) => {
            const show = filter === 'favorites' ? card.dataset.routeFavorite === '1' : card.dataset.routeWorst === '1';
            card.hidden = !show;
            if (show) visible += 1;
        });
        chips.forEach((chip) => {
            const on = chip.dataset.overviewFilter === filter;
            chip.classList.toggle('is-active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (favEmpty) favEmpty.hidden = !(filter === 'favorites' && visible === 0);
        if (favCount) favCount.textContent = String(all.filter((card) => card.dataset.routeFavorite === '1').length);
        if (worstCount) worstCount.textContent = String(all.filter((card) => card.dataset.routeWorst === '1').length);
    };

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            filter = chip.dataset.overviewFilter === 'favorites' ? 'favorites' : 'worst';
            try { localStorage.setItem(FILTER_KEY, filter); } catch { /* storage indisponível */ }
            applyFilter();
        });
    });

    // ───────────────────────── Realce com o mapa grande ─────────────────────────

    const highlightRoute = (routeId) => {
        cards().forEach((card) => card.classList.toggle('is-active', String(card.dataset.routeId) === String(routeId)));
        document.dispatchEvent(new CustomEvent('routes:highlight', { detail: { routeId } }));
    };

    cards().forEach((card) => {
        card.addEventListener('mouseenter', () => highlightRoute(card.dataset.routeId));
        card.addEventListener('focusin', () => highlightRoute(card.dataset.routeId));
    });

    // ───────────────────────── Favoritar ─────────────────────────

    overview.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-route-fav]');
        if (!button || button.disabled) return;
        event.stopPropagation();
        button.disabled = true;

        try {
            const response = await fetch(button.dataset.favUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': button.dataset.favToken || '',
                },
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json.ok) {
                setStatus(rt('Não foi possível atualizar o favorito. Recarregue a página e tente de novo.'), true);
                return;
            }

            const card = button.closest('[data-route-card]');
            const on = Boolean(json.favorited);
            card.dataset.routeFavorite = on ? '1' : '0';
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            applyFilter();
            setStatus(on ? rt('Rota adicionada aos favoritos.') : rt('Rota removida dos favoritos.'));
        } catch (error) {
            setStatus(rt('Falha de conexão ao salvar o favorito.'), true);
        } finally {
            button.disabled = false;
        }
    });

    // ───────────────────────── Atualização automática (leve) ─────────────────────────

    const updateCard = (card, data) => {
        const status = String(data.statusClass ?? 'none');
        const badge = card.querySelector('.route-card-badge');
        const time = card.querySelector('[data-route-time-value]');
        const delay = card.querySelector('[data-route-delay-value]');
        const percent = card.querySelector('[data-route-delay-percent-value]');

        card.dataset.routeStatus = status;
        card.className = card.className.replace(/route-card--\S+/, `route-card--${status}`);

        if (badge) {
            badge.className = `route-card-badge route-card-badge--${status}`;
            badge.textContent = data.statusLabel ?? rt('Sem dados');
        }
        if (time) time.textContent = data.timeLabel ?? '—';
        if (delay) delay.textContent = data.delayLabel ?? '—';
        if (percent) percent.textContent = data.delayPercent ?? '';
    };

    // Troca só o primeiro nó de texto ("120<small>s</small>" → muda o "120").
    const setLeadingText = (el, value) => {
        const node = [...el.childNodes].find((n) => n.nodeType === Node.TEXT_NODE && n.nodeValue.trim() !== '');
        if (node) node.nodeValue = String(value);
        else el.prepend(document.createTextNode(String(value)));
    };

    const updateStats = (stats) => {
        if (!stats) return;
        const set = (name, value) => {
            const el = document.querySelector(`[data-stat="${name}"]`);
            if (el && value != null) el.textContent = String(value);
        };
        set('total', stats.total);
        set('delayed', stats.delayed);
        set('severe', stats.severe);
        set('avg-percent', Math.round(Number(stats.avgDelayPercent ?? 0) * 100));

        const avg = document.querySelector('[data-stat="avg-delay"]');
        if (avg && stats.avgDelaySeconds != null) setLeadingText(avg, stats.avgDelaySeconds);

        const heavy = Number(stats.heavy ?? 0);
        const heavyLabel = document.querySelector('[data-stat="heavy-label"]');
        if (heavyLabel) heavyLabel.textContent = `${heavy} ${rt('alto')}${heavy !== 1 ? 's' : ''}`;
    };

    const refreshUrl = (() => {
        if (!overviewUrl) return null;
        const url = new URL(overviewUrl, window.location.href);
        url.searchParams.set('slim', '1');
        return url.toString();
    })();

    let lastOk = Date.now();
    let inFlight = false;

    const refresh = async () => {
        if (!refreshUrl || inFlight) return;
        inFlight = true;
        try {
            const response = await fetch(refreshUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            const byId = new Map((payload?.overview?.routes ?? []).map((route) => [String(route.id), route]));

            cards().forEach((card) => {
                const data = byId.get(String(card.dataset.routeId));
                if (data) updateCard(card, data);
            });
            updateStats(payload?.data?.stats);

            lastOk = Date.now();
            const at = payload?.generated_at ?? new Date().toISOString();
            if (clock) clock.textContent = new Date(at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            // routes.js mostra a mesma hora no cabeçalho da página.
            document.dispatchEvent(new CustomEvent('routes:data-updated', { detail: { at } }));
            setStatus('');
        } catch (error) {
            const minutes = Math.max(1, Math.round((Date.now() - lastOk) / 60000));
            setStatus(`${rt('Não foi possível atualizar. Exibindo dados de')} ${minutes} min ${rt('atrás')}.`, true);
        } finally {
            inFlight = false;
        }
    };

    // Só consulta com a aba visível; ao voltar para a aba, atualiza na hora se os dados estiverem velhos.
    setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastOk > REFRESH_MS / 2) refresh();
    });

    applyFilter();
    watchMaps();
})();
