// assets/js/hydro-live.js

(function() {
  'use strict';

  console.log('[HydroLive] Script iniciado.');

  // Elementos do DOM
  const tbody    = document.getElementById('hydro-tbody');
  const lastEl   = document.getElementById('last-update');
  const btnRef   = document.getElementById('btn-refresh');
  const refreshIcon = document.getElementById('refresh-icon');
  const chartCanvas = document.getElementById('hydro-chart');

  // Configuração
  const INTERVAL = 60_000;
  let chartInstance = null;
  let currentData = [];

  // Mapeamento de níveis
  const meta = {
    normal:          { icon: '✅', rowCss: '',                    barCss: 'bar-normal',  kpi: 'cnt-normal'  },
    atencao:         { icon: '⚠️', rowCss: 'row-atencao',         barCss: 'bar-atencao', kpi: 'cnt-atencao' },
    alerta:          { icon: '🚨', rowCss: 'row-alerta',          barCss: 'bar-alerta',  kpi: 'cnt-alerta'  },
    transbordamento: { icon: '🌊', rowCss: 'row-transbordamento', barCss: 'bar-transb',  kpi: 'cnt-transb'  },
  };

  // ── Utilitários ──
  function fmt(v) {
    if (v === null || v === undefined) return '—';
    return parseFloat(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' m';
  }

  function fmtDt(s) {
    if (!s) return '—';
    try {
      const d = new Date(s + 'Z');
      return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch (e) {
      return s;
    }
  }

  // ── Atualiza KPIs ──
  function updateKPIs(rows) {
    const counts = { normal: 0, atencao: 0, alerta: 0, transbordamento: 0 };
    rows.forEach(r => {
      const k = r.alert_level ?? 'normal';
      if (counts[k] !== undefined) counts[k]++;
      else counts.normal++;
    });
    document.getElementById('cnt-normal').textContent  = counts.normal;
    document.getElementById('cnt-atencao').textContent = counts.atencao;
    document.getElementById('cnt-alerta').textContent  = counts.alerta;
    document.getElementById('cnt-transb').textContent  = counts.transbordamento;
    document.getElementById('total-count').textContent = rows.length + ' estações';
  }

  // ── Renderiza tabela ──
  function renderTable(rows) {
    if (!rows.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8">
            <div class="hydro-empty">
              <div class="empty-icon">💧</div>
              <h3>Sem dados hidrológicos</h3>
              <p>Aguardando primeira leitura ou estação cadastrada.</p>
            </div>
          </td>
        </tr>
      `;
      return;
    }
    tbody.innerHTML = rows.map(r => {
      const m = meta[r.alert_level ?? 'normal'] ?? meta.normal;
      const pct = (r.cota_transbordamento && r.water_level)
        ? Math.min(Math.round((r.water_level / r.cota_transbordamento) * 100), 100)
        : 0;
      const bar = pct ? `<div class="level-bar-wrap"><div class="level-bar ${m.barCss}" style="width:${pct}%"></div></div>` : '';
      return `
        <tr class="${m.rowCss}">
          <td class="text-center"><span class="level-icon" title="${r.alert_level ?? 'normal'}">${m.icon}</span></td>
          <td>
            <div class="station-name">${r.station_name}</div>
            <div class="station-code">${r.station_code}</div>
          </td>
          <td style="white-space:nowrap">${r.municipality} / ${r.state}</td>
          <td class="text-right">
            <div class="level-value">${fmt(r.water_level)}</div>
            ${bar}
          </td>
          <td class="text-right">${fmt(r.cota_atencao)}</td>
          <td class="text-right">${fmt(r.cota_alerta)}</td>
          <td class="text-right">${fmt(r.cota_transbordamento)}</td>
          <td style="white-space:nowrap;font-size:var(--text-xs)">${fmtDt(r.measured_at)}</td>
        </tr>
      `;
    }).join('');
  }

  // ── Gráfico ──
  // Em vez de comparar metros brutos entre rios diferentes (que têm
  // escalas completamente distintas e tornam a barra sem sentido),
  // mostramos o nível como % da cota de transbordamento de cada estação
  // — assim 80% em qualquer rio significa a mesma coisa: perto de
  // transbordar. Estações sem cota de transbordamento cadastrada ficam
  // de fora do gráfico (mas continuam na tabela).
  function renderChart(rows) {
    if (!chartCanvas) return;

    const withThreshold = rows.filter(r => r.cota_transbordamento && r.water_level !== null);

    if (!withThreshold.length) {
      if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
      }
      return;
    }

    // Pior situação primeiro, para chamar atenção sem precisar rolar.
    const sorted = withThreshold
      .map(r => ({
        ...r,
        pct: Math.min((r.water_level / r.cota_transbordamento) * 100, 150),
      }))
      .sort((a, b) => b.pct - a.pct)
      .slice(0, 20); // limite pra não virar uma parede de barras ilegível

    // Altura fixa no CSS funcionava para o gráfico antigo (poucas barras
    // verticais). Barra horizontal com até 20 estações precisa de altura
    // proporcional à quantidade de linhas, senão os rótulos ficam
    // espremidos uns em cima dos outros.
    const container = chartCanvas.parentElement;
    if (container) {
      container.style.height = Math.max(250, sorted.length * 28 + 40) + 'px';
    }

    const labels = sorted.map(r => r.station_name);
    const values = sorted.map(r => +r.pct.toFixed(1));
    const colors = sorted.map(r => {
      const lvl = r.alert_level ?? 'normal';
      if (lvl === 'transbordamento') return '#dc2626';
      if (lvl === 'alerta') return '#f97316';
      if (lvl === 'atencao') return '#f59e0b';
      return '#22c55e';
    });

    if (chartInstance) {
      chartInstance.destroy();
    }

    const ctx = chartCanvas.getContext('2d');
    chartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: '% da cota de transbordamento',
          data: values,
          backgroundColor: colors,
          borderRadius: 4,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                const r = sorted[context.dataIndex];
                return [
                  'Nível: ' + fmt(r.water_level) + ' (' + context.parsed.x.toFixed(1) + '% do limite)',
                  'Transbordamento: ' + fmt(r.cota_transbordamento),
                ];
              },
            },
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            max: 150,
            title: { display: true, text: '% da cota de transbordamento' },
            ticks: { callback: value => value + '%' },
          },
          y: {
            ticks: { autoSkip: false, font: { size: 11 } },
          },
        },
      },
    });
  }

  // ── Atualiza tudo ──
  function loadData() {
    btnRef.disabled = true;
    refreshIcon.classList.add('spinning');

    const url = document.getElementById('hydro-live-data-url').value;
    fetch(url)
      .then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(data => {
        currentData = data;
        updateKPIs(data);
        renderTable(data);
        renderChart(data);
        lastEl.textContent = new Date().toLocaleTimeString('pt-BR');
        console.log('[HydroLive] Dados atualizados:', data.length, 'registros');
      })
      .catch(err => {
        console.error('[HydroLive] Erro no fetch:', err);
      })
      .finally(() => {
        btnRef.disabled = false;
        refreshIcon.classList.remove('spinning');
      });
  }

  // ── Inicialização ──
  document.addEventListener('DOMContentLoaded', function() {
    console.log('[HydroLive] DOMContentLoaded disparado.');

    // Verifica elementos essenciais
    if (!tbody || !lastEl || !btnRef || !refreshIcon) {
      console.error('[HydroLive] Elementos essenciais não encontrados.');
      return;
    }

    // Carrega dados iniciais
    loadData();

    // Evento do botão refresh
    btnRef.addEventListener('click', loadData);

    // Intervalo automático
    setInterval(loadData, INTERVAL);
    console.log('[HydroLive] Intervalo configurado para', INTERVAL, 'ms.');

    // Inicializa ícones Lucide
    if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
      lucide.createIcons();
    } else {
      console.warn('[HydroLive] Lucide não encontrado.');
    }
  });
})();
