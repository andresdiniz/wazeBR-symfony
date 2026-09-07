export function initPublicPages() {
  document.querySelectorAll('.auth-password-toggle').forEach((button) => {
    button.addEventListener('click', () => {
      const input = button.closest('.auth-input-wrapper')?.querySelector('input');
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      const icon = button.querySelector('i');
      if (icon) icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
    });
  });

  document.querySelectorAll('.auth-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      let valid = true;
      form.querySelectorAll('[data-required]').forEach((input) => {
        input.classList.toggle('error', !input.value.trim());
        valid = valid && Boolean(input.value.trim());
      });
      if (!valid) event.preventDefault();
    });
  });
}

export function initAlerts() {
  document.querySelectorAll('[data-timeout]').forEach((element) => {
    const timeout = Number(element.dataset.timeout);
    if (!Number.isNaN(timeout) && timeout > 0) {
      window.setTimeout(() => element.remove(), timeout);
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initPublicPages();
  initAlerts();
});