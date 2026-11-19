(() => {
    const overview = document.querySelector('[data-routes-overview]');
    if (!overview) return;

    const clock = overview.querySelector('[data-overview-clock]');
    const overviewUrl = overview.dataset.overviewUrl;
    const REFRESH_MS = 60000;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    // Textos traduzidos (mesmo JSON usado por routes.js); sem tradução, mantém o original em português.
    const messages = (() => {
        try { return JSON.parse(document.querySelector('[data-routes-i18n]')?.dataset.routesI18n || '{}'); }
        catch { return {}; }
    })();
    const rt = (text) => (Object.hasOwn(messages, text) ? messages[text] : text);

    const grid = overview.querySelector('[data-overview-grid]');
    const chips = [...overview.querySelectorAll('[data-overview-filter]')];
    const statusEl = overview.querySelector('[data-overview-status]');
    const favEmpty = overview.querySelector('[data-overview-fav-empty]');
    const favCount = overview.querySelector('[data-favorite-count]');
    const FILTER_KEY = 'routes.overview.filter';
    let filter = 'all';
    try { filter = localStorage.getItem(FILTER_KEY) === 'favorites' ? 'favorites' : 'all'; } catch { /* storage indisponível */ }

    const setStatus = (message, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.classList.toggle('is-error', isError);
    };

    const applyFilter = () => {
        const cards = [...document.querySelectorAll('[data-route-card]')];
        let visible = 0;
        cards.forEach((card) => {
            const show = filter === 'all' || card.dataset.routeFavorite === '1';
            card.hidden = !show;
            if (show) visible += 1;
        });
        chips.forEach((chip) => {
            const on = chip.dataset.overviewFilter === filter;
            chip.classList.toggle('is-active', on);
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (favEmpty) favEmpty.hidden = !(filter === 'favorites' && visible === 0);
        if (favCount) favCount.textContent = String(cards.filter((card) => card.dataset.routeFavorite === '1').length);
    };

    const drawMiniMap = (card) => {
        const svg = card.querySelector('[data-route-mini-map]');
        const rawPath = svg?.dataset.routePath;
        if (!svg || !rawPath) return;

        let points;
        try { points = JSON.parse(rawPath); } catch { return; }
        if (!Array.isArray(points) || points.length < 2) return;

        const lats = points.map((p) => Number(p[0]));
        const lngs = points.map((p) => Number(p[1]));
        const minLat = Math.min(...lats);
        const maxLat = Math.max(...lats);
        const minLng = Math.min(...lngs);
        const maxLng = Math.max(...lngs);
        const width = 240;
        const height = 110;
        const padding = 12;
        const scaleX = (maxLng === minLng) ? 1 : (width - padding * 2) / (maxLng - minLng);
        const scaleY = (maxLat === minLat) ? 1 : (height - padding * 2) / (maxLat - minLat);
        const scale = Math.min(scaleX, scaleY);

        const project = ([lat, lng]) => {
            const x = padding + (lng - minLng) * scale;
            const y = height - padding - (lat - minLat) * scale;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        };

        const first = project(points[0]).split(',');
        const last = project(points[points.length - 1]).split(',');

        svg.innerHTML = `<polyline points="${points.map(project).join(' ')}" fill="none" stroke="#38bdf8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="${first[0]}" cy="${first[1]}" r="4" fill="#22c55e"/><circle cx="${last[0]}" cy="${last[1]}" r="4" fill="#ef4444"/>`;
    };

    const closePopup = (wrapper) => {
        wrapper.querySelector('.route-card-popup')?.remove();
    };

    const openIncidentsPopup = (card, svg) => {
        const wrapper = svg.closest('.route-card-map-wrapper');
        if (!wrapper) return;

        closePopup(wrapper);

        let incidents = [];
        try { incidents = JSON.parse(svg.dataset.routeIncidents || '[]'); } catch { incidents = []; }

        const popup = document.createElement('div');
        popup.className = 'route-card-popup';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', 'Incidentes da rota');

        const items = incidents.length
            ? incidents.map((incident) => `
                <li>
                    <strong>${escapeHtml(incident.type || 'Incidente')}</strong>
                    <span>${escapeHtml(incident.street || incident.city || 'Local não informado')}</span>
                    ${incident.severity ? `<small>Severidade: ${escapeHtml(incident.severity)}</small>` : ''}
                    ${incident.description ? `<p>${escapeHtml(incident.description)}</p>` : ''}
                </li>`
            ).join('')
            : '<li class="route-card-popup-empty">Sem incidentes recentes</li>';

        popup.innerHTML = `
            <header>
                <strong>Incidentes</strong>
                <button type="button" class="route-card-popup-close" aria-label="Fechar">×</button>
            </header>
            <ul>${items}</ul>
        `;

        wrapper.appendChild(popup);
        popup.querySelector('.route-card-popup-close')?.addEventListener('click', (event) => {
            event.stopPropagation();
            closePopup(wrapper);
        });

        document.dispatchEvent(new CustomEvent('routes:highlight', {
            detail: { routeId: card.dataset.routeId },
        }));
    };

    const highlightRoute = (routeId) => {
        document.querySelectorAll('[data-route-card]').forEach((card) => {
            card.classList.toggle('is-active', String(card.dataset.routeId) === String(routeId));
        });
        document.dispatchEvent(new CustomEvent('routes:highlight', {
            detail: { routeId },
        }));
    };

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
            badge.textContent = data.statusLabel ?? 'Sem dados';
        }
        if (time) time.textContent = data.timeLabel ?? '—';
        if (delay) delay.textContent = data.delayLabel ?? '—';
        if (percent) percent.textContent = data.delayPercent ?? '';
    };

    // Texto do primeiro nó de texto do elemento (ex.: "120<small>s</small>" → troca só o "120").
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

    let lastOk = Date.now();
    let inFlight = false;

    const refresh = async () => {
        if (!overviewUrl || inFlight) return;
        inFlight = true;
        try {
            const response = await fetch(overviewUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            const routes = payload?.overview?.routes ?? [];
            const byId = new Map(routes.map((route) => [String(route.id), route]));

            document.querySelectorAll('[data-route-card]').forEach((card) => {
                const data = byId.get(String(card.dataset.routeId));
                if (data) updateCard(card, data);
            });
            updateStats(payload?.data?.stats);

            lastOk = Date.now();
            const at = payload?.generated_at ?? new Date().toISOString();
            if (clock) {
                clock.textContent = new Date(at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            }
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

    // Favoritar direto no cartão (mesmo endpoint e CSRF da página de detalhes).
    overview.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-route-fav]');
        if (!button) return;
        event.stopPropagation();
        if (button.disabled) return;
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

            if (on && grid) {
                // Favorita vai para o grupo do topo, sem depender de recarregar a página.
                const firstOther = [...grid.querySelectorAll('[data-route-card]')]
                    .find((other) => other !== card && other.dataset.routeFavorite !== '1');
                if (firstOther) grid.insertBefore(card, firstOther);
            }
            applyFilter();
            setStatus(on ? rt('Rota adicionada aos favoritos.') : rt('Rota removida dos favoritos.'));
        } catch (error) {
            setStatus(rt('Falha de conexão ao salvar o favorito.'), true);
        } finally {
            button.disabled = false;
        }
    });

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            filter = chip.dataset.overviewFilter === 'favorites' ? 'favorites' : 'all';
            try { localStorage.setItem(FILTER_KEY, filter); } catch { /* storage indisponível */ }
            applyFilter();
        });
    });
    applyFilter();

    document.querySelectorAll('[data-route-card]').forEach((card) => {
        drawMiniMap(card);

        const svg = card.querySelector('[data-route-mini-map]');
        svg?.addEventListener('click', (event) => {
            event.stopPropagation();
            openIncidentsPopup(card, svg);
        });

        card.addEventListener('mouseenter', () => highlightRoute(card.dataset.routeId));
        card.addEventListener('focusin', () => highlightRoute(card.dataset.routeId));
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('.route-card-popup')) return;
        document.querySelectorAll('.route-card-map-wrapper').forEach(closePopup);
    });

    // Só consulta com a aba visível; ao voltar para a aba, atualiza na hora se os dados estiverem velhos.
    setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastOk > REFRESH_MS / 2) refresh();
    });
})();