(() => {
  const root = document.querySelector('[data-cemaden-page]');
  if (!root) return;
  root.querySelectorAll('[data-rain-value]').forEach(element => {
    const value = Number(element.dataset.rainValue);
    if (Number.isFinite(value)) element.textContent = value.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1});
  });
})();
