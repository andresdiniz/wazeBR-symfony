import './styles/app.css';
import './js/public-pages.js';

const boot = () => {
  document.dispatchEvent(new CustomEvent('wazebr:ready'));
};

document.readyState === 'loading'
  ? document.addEventListener('DOMContentLoaded', boot, { once: true })
  : boot();