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

    const refresh = async () => {
        if (!overviewUrl) return;
        try {
            const response = await fetch(overviewUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const payload = await response.json();
            const routes = payload?.overview?.routes ?? [];
            const byId = new Map(routes.map((route) => [String(route.id), route]));

            document.querySelectorAll('[data-route-card]').forEach((card) => {
                const data = byId.get(String(card.dataset.routeId));
                if (data) updateCard(card, data);
            });

            if (clock) {
                clock.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            }
        } catch { /* silencioso */ }
    };

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

    setInterval(refresh, REFRESH_MS);
})();