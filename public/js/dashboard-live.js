/**
 * dashboard-live.js
 * Polling do endpoint /dashboard/api/live a cada 60s.
 * Atualiza os KPIs ao vivo sem recarregar a página.
 *
 * Uso: <script src="/js/dashboard-live.js" defer></script>
 * Requer elementos com data-live="*" no HTML.
 *
 * Elementos suportados (data-live=""):
 *   alerts1h, liveJams, liveAvgSpeed, liveAvgDelay, rainLastHour,
 *   wazeJams, wazeAlerts, cifsActive, collectedAt
 *
 * Banner de anomalia: elemento com id="anomaly-banner" e id="anomaly-banner-hidden"
 */
(function () {
    'use strict';

    const POLL_INTERVAL = 60_000; // 60 segundos
    const API_URL = '/dashboard/api/live';

    function updateEl(attr, value) {
        document.querySelectorAll(`[data-live="${attr}"]`).forEach(el => {
            el.textContent = value ?? '—';
        });
    }

    function applyAnomaly(data) {
        const banner = document.getElementById('anomaly-banner');
        const hiddenBanner = document.getElementById('anomaly-banner-hidden');
        const target = banner || hiddenBanner;
        if (!target) return;

        if (data.anomaly?.detected) {
            target.classList.remove('d-none');
            const msg = target.querySelector('.anomaly-msg');
            if (msg) {
                msg.textContent = `${data.alerts1h} alertas na última hora — ${data.anomaly.ratio}× a média (${data.anomaly.avg6h}/h)`;
            }
        } else {
            target.classList.add('d-none');
        }
    }

    async function fetchLive() {
        try {
            const res = await fetch(API_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            const data = await res.json();

            updateEl('alerts1h',     data.alerts1h);
            updateEl('liveJams',     data.liveJams);
            updateEl('liveAvgSpeed', data.liveAvgSpeed !== null ? `${data.liveAvgSpeed} km/h` : '—');
            updateEl('liveAvgDelay', data.liveAvgDelay !== null ? `${data.liveAvgDelay}s` : '—');
            updateEl('rainLastHour', data.rainLastHour !== null ? `${data.rainLastHour} mm` : '—');
            updateEl('wazeJams',     data.wazeJams);
            updateEl('wazeAlerts',   data.wazeAlerts);
            updateEl('cifsActive',   data.cifsActive);
            updateEl('collectedAt',  data.collectedAt);

            applyAnomaly(data);

            // Pisca os elementos atualizados para feedback visual
            document.querySelectorAll('[data-live]').forEach(el => {
                el.classList.add('text-primary');
                setTimeout(() => el.classList.remove('text-primary'), 800);
            });
        } catch (e) {
            // Falha silenciosa — dados estáticos do servidor continuam visíveis
        }
    }

    // Executa imediatamente e depois a cada POLL_INTERVAL
    document.addEventListener('DOMContentLoaded', () => {
        fetchLive();
        setInterval(fetchLive, POLL_INTERVAL);
    });
})();
