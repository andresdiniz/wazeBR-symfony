// assets/js/pages/traffic-light-dashboard.js

'use strict';

const STATUS_META = {
    OK:      { cls: 'ok',   color: '#16a34a', label: 'OK' },
    ERROR:   { cls: 'error', color: '#e11919', label: 'ERRO' },
    PENDING: { cls: 'idle',  color: '#94a3b8', label: '—' },
};

export function init(root = document) {
    const mapEl = document.getElementById('tl-dashboard-map');
    if (!mapEl || typeof L === 'undefined') return;

    const statusUrl = mapEl.dataset.statusUrl;
    if (!statusUrl) return;

    const tableBody = document.getElementById('tl-dashboard-tbody');
    const chipOk    = document.getElementById('tl-chip-ok');
    const chipErr   = document.getElementById('tl-chip-err');
    const chipPend  = document.getElementById('tl-chip-pending');
    const chipTotal = document.getElementById('tl-chip-total');

    const map = L.map(mapEl, { zoomControl: true, attributionControl: true })
        .setView([-20.66, -43.81], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 19,
    }).addTo(map);

    const layer = L.layerGroup().addTo(map);
    let boundsApplied = false;

    function markerIcon(status) {
        const meta = STATUS_META[status] || STATUS_META.PENDING;
        const icon = status === 'ERROR' ? '⚠' : (status === 'OK' ? '●' : '○');
        return L.divIcon({
            className: '',
            html: `
                <div class="tl-dash-marker tl-dash-marker--${meta.cls}">
                    <span class="tl-dash-marker__pulse"></span>
                    <span class="tl-dash-marker__icon" style="color:${meta.color}">${icon}</span>
                </div>`,
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -18],
        });
    }

    async function refresh() {
        try {
            const res = await fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const lights = await res.json();

            layer.clearLayers();
            if (tableBody) tableBody.innerHTML = '';

            let ok = 0, err = 0, pend = 0;
            const points = [];

            for (const l of lights) {
                const status = String(l.status || 'PENDING').toUpperCase();
                if (status === 'OK') ok++;
                else if (status === 'ERROR') err++;
                else pend++;

                if (l.latitude && l.longitude) {
                    const m = L.marker([l.latitude, l.longitude], { icon: markerIcon(status) })
                        .bindPopup(`
                            <div style="min-width:200px">
                                <strong>${escapeHtml(l.name)}</strong><br>
                                <code>${escapeHtml(l.code)}</code><br>
                                <span style="color:${(STATUS_META[status] || STATUS_META.PENDING).color}">
                                    ● ${escapeHtml(status)}
                                </span>
                                ${l.error ? `<br><small style="color:#991b1b">${escapeHtml(l.error)}</small>` : ''}
                                <br><a href="/traffic-lights/${l.id}" style="font-size:.85rem">Ver detalhes →</a>
                            </div>
                        `);
                    m.addTo(layer);
                    points.push([l.latitude, l.longitude]);
                }

                if (tableBody) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><code>${escapeHtml(l.code)}</code></td>
                        <td>${escapeHtml(l.name)}</td>
                        <td>${escapeHtml(l.protocol || '—')}</td>
                        <td>
                            <span class="tl-dash__row-status">
                                <span class="tl-status-dot tl-status-dot--${(STATUS_META[status] || STATUS_META.PENDING).cls}"></span>
                                ${escapeHtml((STATUS_META[status] || STATUS_META.PENDING).label)}
                            </span>
                        </td>
                        <td>${l.lastReadAt ? new Date(l.lastReadAt).toLocaleString() : '—'}</td>
                        <td>
                            <a class="pf-btn pf-btn--secondary" href="/traffic-lights/${l.id}">Detalhes</a>
                            <a class="pf-btn pf-btn--primary" href="/traffic-lights/${l.id}/control">Controlar</a>
                        </td>
                    `;
                    tableBody.appendChild(tr);
                }
            }

            if (chipOk)      chipOk.textContent      = ok;
            if (chipErr)     chipErr.textContent     = err;
            if (chipPend)    chipPend.textContent    = pend;
            if (chipTotal)   chipTotal.textContent   = lights.length;

            if (!boundsApplied && points.length) {
                map.fitBounds(points, { padding: [40, 40], maxZoom: 14 });
                boundsApplied = true;
            }
        } catch (err) {
            console.error('[tl-dashboard] refresh falhou:', err);
        }
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    refresh();
    setInterval(refresh, 10_000);
}

export default init;
