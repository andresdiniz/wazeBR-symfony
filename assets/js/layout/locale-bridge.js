/** Connect the existing header selector to the server-side locale. */
(() => {
  'use strict';
  const root = document.documentElement;
  const locales = { pt_BR: '🇧🇷', en: '🇺🇸', es: '🇪🇸' };
  const locale = root.dataset.locale;
  if (!Object.hasOwn(locales, locale)) return;

  try { localStorage.setItem('wazebr-locale', locale); } catch {}

  function syncSelector() {
    const flag = document.querySelector('[data-header-language-flag]');
    if (flag) flag.textContent = locales[locale];
    document.querySelectorAll('[data-language-option]').forEach(option => {
      const active = option.dataset.languageOption === locale;
      option.classList.toggle('is-active', active);
      if (active) option.setAttribute('aria-current', 'true');
      else option.removeAttribute('aria-current');
      const url = new URL(window.location.href);
      url.searchParams.set('_locale', option.dataset.languageOption);
      option.href = url.href;
    });
  }

  document.addEventListener('click', event => {
    const option = event.target instanceof Element
      ? event.target.closest('[data-language-option]') : null;
    if (!option) return;
    const selected = option.dataset.languageOption;
    if (!Object.hasOwn(locales, selected)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const url = new URL(window.location.href);
    url.searchParams.set('_locale', selected);
    window.location.assign(url.href);
  }, true);

  syncSelector();
  window.addEventListener('wazebr:ready', syncSelector);
})();
