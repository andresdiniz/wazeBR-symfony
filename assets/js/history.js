/**
 * history.js — Módulo premium para histórico de rota
 * Gráficos, mapa, heatmap, animações e interatividade
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    console.log('[History] Inicializando módulo premium...');

    const dataEl = document.getElementById('js-data');
    if (!dataEl) {
      console.warn('[History] Elemento #js-data não encontrado.');
      return;
    }

    const ds = dataEl.dataset;
    let raw, routeLine, jamLevel, fromName, toName, byJam, hourly, jamColors;

    try {
      raw       = JSON.parse(ds.history);
      routeLine = JSON.parse(ds.line);
      jamLevel  = parseInt(ds.jamLevel, 10) || 0;
      fromName  = JSON.parse(ds.from);
      toName    = JSON.parse(ds.to);
      byJam     = JSON.parse(ds.byJam);
      hourly    = JSON.parse(ds.hourly);
      jamColors = JSON.parse(ds.jamColors);
    } catch (e) {
      console.error('[History] Erro ao parsear JSON:', e);
      return;
    }

    const jamLabels = ['Livre', 'Lento', 'Moderado', 'Pesado', 'Muito Pesado', 'Parado'];
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const FMT = new Intl.DateTimeFormat('pt-BR', {
      timeZone: tz,
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });

    function isDark() {
      return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function getChartColors() {
      const dark = isDark();
      return {
        grid: dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
        text: dark ? '#b0b0b0' : '#5a5a5a',
        border: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)',
      };
    }

    // Gráfico de evolução temporal
    const elHistory = document.getElementById('chart-history');
    if (elHistory && raw.length > 0) {
      const ordered = raw.slice().reverse();
      const labels = ordered.map(r => FMT.format(new Date(r.t)));
      const times = ordered.map(r => r.time !== null ? +(r.time / 60).toFixed(1) : null);
      const hists = ordered.map(r => r.hist !== null ? +(r.hist / 60).toFixed(1) : null);

      const colors = getChartColors();
      new Chart(elHistory, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Tempo atual (min)',
              data: times,
              borderColor: '#a12c7b',
              backgroundColor: 'oklch(0.5 0.2 330 / 0.08)',
              tension: 0.35,
              fill: true,
              pointRadius: 3,
              pointHoverRadius: 7,
              pointBackgroundColor: '#a12c7b',
              spanGaps: true,
              borderWidth: 2.5,
            },
            {
              label: 'Histórico (min)',
              data: hists,
              borderColor: '#437a22',
              backgroundColor: 'oklch(0.4 0.15 120 / 0.05)',
              borderDash: [5, 4],
              tension: 0.35,
              fill: false,
              pointRadius: 3,
              pointHoverRadius: 7,
              pointBackgroundColor: '#437a22',
              spanGaps: true,
              borderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: {
              position: 'top',
              labels: {
                color: colors.text,
                font: { size: 12, weight: '600' },
                boxWidth: 14,
                padding: 16,
              },
            },
            tooltip: {
              backgroundColor: isDark() ? 'rgba(26,32,44,0.9)' : 'rgba(255,255,255,0.95)',
              titleColor: isDark() ? '#f1f5f9' : '#0f172a',
              bodyColor: isDark() ? '#94a3b8' : '#64748b',
              borderColor: colors.border,
              borderWidth: 1,
              cornerRadius: 8,
              boxShadow: '0 8px 24px rgba(0,0,0,0.15)',
              callbacks: {
                label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y !== null ? ctx.parsed.y.toFixed(1) + ' min' : '—'),
              },
            },
          },
          scales: {
            x: {
              ticks: { color: colors.text, font: { size: 10 }, maxTicksLimit: 14, maxRotation: 45 },
              grid: { color: colors.grid, drawBorder: false },
            },
            y: {
              beginAtZero: true,
              title: { display: true, text: 'minutos', color: colors.text, font: { size: 11 } },
              ticks: { color: colors.text, font: { size: 10 }, callback: v => v + ' min' },
              grid: { color: colors.grid, drawBorder: false },
            },
          },
        },
      });
    }

    // Gráfico de distribuição (doughnut)
    const elByJam = document.getElementById('chart-byjam');
    if (elByJam && byJam.length > 0) {
      new Chart(elByJam, {
        type: 'doughnut',
        data: {
          labels: jamLabels,
          datasets: [{ data: byJam, backgroundColor: jamColors, borderWidth: 0 }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: {
              position: 'right',
              labels: {
                boxWidth: 14,
                font: { size: 11, weight: '500' },
                color: isDark() ? '#94a3b8' : '#64748b',
                padding: 12,
              },
            },
            tooltip: {
              callbacks: {
                label: ctx => ctx.label + ': ' + ctx.parsed + ' registros',
              },
            },
          },
        },
      });
    }

    // Gráfico de perfil horário (barras)
    const elHourly = document.getElementById('chart-hourly');
    if (elHourly && hourly.length > 0) {
      const colors = getChartColors();
      new Chart(elHourly, {
        type: 'bar',
        data: {
          labels: hourly.map(h => String(h.bucket).padStart(2, '0') + 'h'),
          datasets: [
            {
              label: 'Tempo atual (min)',
              data: hourly.map(h => h.avgTimeMin),
              backgroundColor: '#a12c7b',
              borderRadius: 4,
              borderSkipped: false,
            },
            {
              label: 'Histórico (min)',
              data: hourly.map(h => h.avgHistMin),
              backgroundColor: '#437a22',
              borderRadius: 4,
              borderSkipped: false,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                font: { size: 11, weight: '600' },
                boxWidth: 14,
                color: colors.text,
                padding: 12,
              },
            },
            tooltip: {
              callbacks: {
                label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y !== null ? ctx.parsed.y.toFixed(1) + ' min' : '—'),
              },
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              title: { display: true, text: 'minutos', color: colors.text, font: { size: 10 } },
              ticks: { color: colors.text, font: { size: 9 } },
              grid: { color: colors.grid, drawBorder: false },
            },
            x: {
              ticks: { color: colors.text, font: { size: 9 }, maxRotation: 45 },
              grid: { display: false },
            },
          },
        },
      });
    }

    // Mapa Leaflet
    const mapEl = document.getElementById('history-map');
    if (mapEl && routeLine && routeLine.length >= 2) {
      const map = L.map('history-map', {
        zoomControl: true,
        scrollWheelZoom: false,
        fadeAnimation: true,
        zoomAnimation: true,
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
        crossOrigin: true,
      }).addTo(map);

      const color = jamColors[jamLevel] || '#888';
      const latlngs = routeLine.map(function (p) { return [p.y, p.x]; });

      const poly = L.polyline(latlngs, {
        color: color,
        weight: 6,
        opacity: 0.85,
        smoothFactor: 1,
        dashArray: null,
        lineJoin: 'round',
        lineCap: 'round',
      }).addTo(map);

      const delayStr = function (delay) {
        if (delay === null) return '—';
        if (delay === 0) return '0 s';
        if (delay > 0) return '+' + (delay < 60 ? delay + ' s' : (delay / 60).toFixed(1) + ' min');
        return (delay < -60 ? (delay / 60).toFixed(1) : delay) + ' min';
      };

      const jamLabel = jamLabels[jamLevel] || 'Livre';
      const delay = raw.length > 0 ? raw[0].delay : null;

      poly.bindPopup(
        '<strong>' + (ds.name || 'Rota') + '</strong><br>' +
        'Jam: ' + jamLabel + ' | Atraso: ' + delayStr(delay)
      );

      if (latlngs.length >= 2) {
        const mkPin = function (letter, bg, label) {
          const w = 30,
            h = 38;
          const svg =
            '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 30 38">' +
            '<defs><filter id="shadow-' + letter + '" x="-20%" y="-20%" width="140%" height="140%">' +
            '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/></filter></defs>' +
            '<path d="M15 0 C6.716 0 0 6.716 0 15 C0 23.5 15 38 15 38 C15 38 30 23.5 30 15 C30 6.716 23.284 0 15 0 Z"' +
            ' fill="' + bg + '" filter="url(#shadow-' + letter + ')"/>' +
            '<circle cx="15" cy="13" r="5" fill="rgba(255,255,255,0.3)"/>' +
            '<text x="15" y="20" text-anchor="middle" dominant-baseline="middle"' +
            ' font-family="system-ui,sans-serif" font-size="14" font-weight="800" fill="#fff">' + letter + '</text>' +
            '</svg>';
          return L.divIcon({
            html: svg,
            className: '',
            iconSize: [w, h],
            iconAnchor: [w / 2, h],
            popupAnchor: [0, -h + 4],
          });
        };

        L.marker(latlngs[0], { icon: mkPin('A', '#01696f', fromName) })
          .bindTooltip(fromName, { permanent: false, direction: 'top', className: 'custom-tooltip' })
          .addTo(map);

        L.marker(latlngs[latlngs.length - 1], { icon: mkPin('B', '#a12c7b', toName) })
          .bindTooltip(toName, { permanent: false, direction: 'top', className: 'custom-tooltip' })
          .addTo(map);
      }

      map.fitBounds(poly.getBounds(), { padding: [30, 30] });
      window.__historyMap = map;
      console.log('[History] Mapa inicializado com sucesso.');
    } else if (mapEl) {
      mapEl.innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--color-text-muted);font-size:var(--text-sm);background:var(--color-surface-offset);border-radius:var(--radius-lg)">' +
        '🗺️ Geometria da rota não disponível' +
        '</div>';
    }

    // Formatação das datas
    document.querySelectorAll('time[data-utc][data-fmt="datetime"]').forEach(el => {
      try {
        const d = new Date(el.dataset.utc);
        if (!isNaN(d.getTime())) {
          el.textContent = FMT.format(d);
        }
      } catch (e) { /* mantém original */ }
    });

    // Interação com heatmap
    document.querySelectorAll('.heatmap-data:not(.is-empty)').forEach(cell => {
      cell.addEventListener('click', function () {
        this.style.transform = 'scale(0.92)';
        setTimeout(() => { this.style.transform = 'scale(1)'; }, 150);
      });
      cell.addEventListener('mouseenter', function () {
        this.style.zIndex = '3';
      });
      cell.addEventListener('mouseleave', function () {
        this.style.zIndex = 'auto';
      });
    });

    // Observador de tema
    const themeObserver = new MutationObserver(() => {});
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    function initLucide() {
      if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
        lucide.createIcons();
        console.log('[History] Ícones Lucide inicializados.');
      } else {
        setTimeout(initLucide, 500);
      }
    }
    setTimeout(initLucide, 300);

    console.log('[History] Módulo premium inicializado com sucesso.');
  });
})();
