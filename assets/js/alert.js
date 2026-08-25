(() => {
  const normalizeDay = (value) => {
    if (typeof value === 'number' && Number.isFinite(value)) return new Date((value > 100000000000 ? value : value * 1000)).toISOString().slice(0, 10);
    if (typeof value === 'string') return value.split('T')[0].split(' ')[0];
    return '';
  };

  const rows = (value) => Array.isArray(value) ? value : [];
  const getData = () => window.alertData || {};
  const chart = (id, config) => { const canvas = document.getElementById(id); if (!canvas || typeof window.Chart !== 'function') return; new window.Chart(canvas.getContext('2d'), config); };

  const renderTrendChart = () => {
    const data = rows(getData().byHourTrend).map(item => ({ day: normalizeDay(item.day), total: Number(item.total || 0) })).filter(item => item.day);
    chart('trendChart', { type: 'line', data: { labels: data.map(item => item.day), datasets: [{ label: 'Alertas', data: data.map(item => item.total), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.15)', fill: true, tension: .25 }] }, options: { responsive: true, maintainAspectRatio: false } });
  };

  const renderCharts = () => { renderTrendChart(); };
  const init = () => { if (window.alertData) renderCharts(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
