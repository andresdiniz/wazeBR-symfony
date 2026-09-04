// assets/js/admin.js
import 'leaflet/dist/leaflet.min.css';
import L from 'leaflet';
import 'bootstrap-icons/font/bootstrap-icons.css';

document.addEventListener('DOMContentLoaded', () => {

    // Toggle de senha (para formulários)
    document.querySelectorAll('.toggle-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });

    // Confirmação para ações destrutivas
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm || 'Tem certeza?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-slug (name → slug)
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    if (nameInput && slugInput) {
        nameInput.addEventListener('input', () => {
            if (slugInput.dataset.edited) return;
            slugInput.value = nameInput.value
                .toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
        slugInput.addEventListener('input', () => { slugInput.dataset.edited = '1'; });
    }

    // Torna L disponível globalmente para templates que usam Leaflet
    window.L = L;

    // ===== Mapa Leaflet (show e edit) =====
    const mapContainer = document.getElementById('map');
    if (mapContainer) {
        const textarea = document.getElementById('bbox');
        const isShowPage = document.getElementById('map-show') !== null;
        const wkt = textarea ? textarea.value : (document.getElementById('bbox-wkt')?.textContent || '');

        if (typeof L === 'undefined') {
            console.warn('Leaflet não carregado.');
            return;
        }

        const map = L.map('map').setView([-20.65, -43.78], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        let points = [], polyline = null, polygon = null, drawing = false;
        const btnDraw = document.getElementById('btnDraw');
        const btnClear = document.getElementById('btnClear');

        function toWkt(pts) {
            if (pts.length < 3) return '';
            const coords = [...pts, pts[0]].map(p => p.lng.toFixed(10) + ' ' + p.lat.toFixed(10)).join(',');
            return 'POLYGON((' + coords + '))';
        }

        function renderPreview() {
            if (polyline) { map.removeLayer(polyline); polyline = null; }
            if (polygon)  { map.removeLayer(polygon);  polygon  = null; }
            if (points.length === 0) return;
            if (points.length < 3) {
                polyline = L.polyline(points, {color: '#1a56db', weight: 2, dashArray: '6 4'}).addTo(map);
                map.fitBounds(polyline.getBounds(), {padding: [24, 24]});
            } else {
                polygon = L.polygon(points, {color: '#1a56db', weight: 2, fillColor: '#1a56db', fillOpacity: 0.12}).addTo(map);
                map.fitBounds(polygon.getBounds(), {padding: [24, 24]});
            }
            if (textarea) textarea.value = toWkt(points);
        }

        function loadWkt(wkt) {
            if (!wkt) return;
            const m = wkt.match(/POLYGON\s*\(\((.+?)\)\)/i);
            if (!m) return;
            const pts = m[1].split(',').map(p => {
                const [lng, lat] = p.trim().split(/\s+/);
                return L.latLng(+lat, +lng);
            });
            if (pts.length > 1) pts.pop();
            points = pts;
            renderPreview();
        }

        // Se for página de show, apenas carrega e desabilita interação
        if (isShowPage) {
            loadWkt(wkt);
            map.dragging.disable();
            map.touchZoom.disable();
            map.doubleClickZoom.disable();
            map.scrollWheelZoom.disable();
            if (btnDraw) btnDraw.style.display = 'none';
            if (btnClear) btnClear.style.display = 'none';
            return;
        }

        // Modo edição: interativo
        if (btnDraw) {
            btnDraw.addEventListener('click', () => {
                drawing = !drawing;
                btnDraw.classList.toggle('drawing', drawing);
                btnDraw.innerHTML = drawing
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg> Parar desenho'
                    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Desenhar polígono';
                map.getContainer().style.cursor = drawing ? 'crosshair' : '';
            });
        }

        if (btnClear) {
            btnClear.addEventListener('click', () => {
                points = [];
                renderPreview();
                if (textarea) textarea.value = '';
                drawing = false;
                if (btnDraw) {
                    btnDraw.classList.remove('drawing');
                    btnDraw.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Desenhar polígono';
                }
                map.getContainer().style.cursor = '';
            });
        }

        map.on('click', e => {
            if (!drawing) return;
            points.push(e.latlng);
            renderPreview();
        });

        if (textarea) {
            textarea.addEventListener('change', function() {
                points = [];
                loadWkt(this.value.trim());
            });
        }

        loadWkt(textarea ? textarea.value.trim() : '');
    }

    // ===== Token toggle (show) =====
    const tokenToggle = document.getElementById('token-toggle');
    const tokenInput = document.getElementById('apiToken');
    if (tokenToggle && tokenInput) {
        tokenToggle.addEventListener('click', () => {
            const isPassword = tokenInput.type === 'password';
            tokenInput.type = isPassword ? 'text' : 'password';
            tokenToggle.innerHTML = isPassword 
                ? '<i class="bi bi-eye-slash"></i>' 
                : '<i class="bi bi-eye"></i>';
        });
    }

    // ===== Copiar token (show) =====
    const copyBtn = document.getElementById('copy-token');
    if (copyBtn && tokenInput) {
        copyBtn.addEventListener('click', () => {
            tokenInput.type = 'text';
            tokenInput.select();
            try {
                document.execCommand('copy');
                copyBtn.innerHTML = '<i class="bi bi-check"></i>';
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="bi bi-copy"></i>';
                    tokenInput.type = 'password';
                }, 2000);
            } catch (e) {
                alert('Não foi possível copiar o token.');
            }
        });
    }

    // ===== Abreviar BBox (show) =====
    const bboxElement = document.querySelector('.bbox-content');
    if (bboxElement) {
        const fullText = bboxElement.textContent.trim();
        if (fullText.length > 60) {
            const truncated = fullText.substring(0, 60) + '…';
            bboxElement.textContent = truncated;
            const expandBtn = document.createElement('button');
            expandBtn.className = 'btn btn-ghost btn-sm';
            expandBtn.textContent = 'Mostrar completo';
            expandBtn.style.marginLeft = '0.5rem';
            expandBtn.addEventListener('click', () => {
                if (bboxElement.textContent === fullText) {
                    bboxElement.textContent = truncated;
                    expandBtn.textContent = 'Mostrar completo';
                } else {
                    bboxElement.textContent = fullText;
                    expandBtn.textContent = 'Ocultar';
                }
            });
            bboxElement.parentNode.appendChild(expandBtn);
        }
    }
});