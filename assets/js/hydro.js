(() => {
  const root = document.querySelector('[data-hydro-live]');
  if (!root) return;
  const endpoint = root.dataset.endpoint;
  const tbody = root.querySelector('[data-hydro-rows]');
  const updated = root.querySelector('[data-last-update]');
  const button = root.querySelector('[data-hydro-refresh]');
  const esc = value => String(value ?? '—').replace(/[&<>\"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[c]));
  const number = (value, digits = 2) => value == null ? '—' : Number(value).toLocaleString('pt-BR', {minimumFractionDigits: digits, maximumFractionDigits: digits});
  const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('pt-BR') : '—';
  const load = async () => {
    if (!endpoint || !tbody) return;
    button?.setAttribute('disabled', 'disabled');
    try {
      const response = await fetch(endpoint, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
      if (!response.ok) throw new Error('hydro request failed');
      const rows = await response.json();
      tbody.innerHTML = rows.length ? rows.map(row => `<tr><td>${esc(row.station_name)}</td><td>${esc(row.municipality)} / ${esc(row.state)}</td><td class="level-value">${number(row.water_level)} m</td><td>${esc(row.alert_level || 'normal')}</td><td>${date(row.measured_at)}</td></tr>`).join('') : '<tr><td colspan="5" class="empty-state">Sem dados hidrológicos.</td></tr>';
      if (updated) updated.textContent = new Date().toLocaleTimeString('pt-BR');
    } catch (error) {
      if (updated) updated.textContent = 'Erro ao atualizar';
    } finally { button?.removeAttribute('disabled'); }
  };
  button?.addEventListener('click', load);
  load();
  setInterval(load, 60000);
})();
