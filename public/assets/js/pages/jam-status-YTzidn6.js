const RESOLVED_AFTER_MS = 10 * 60 * 1000;
const STALE_AFTER_MS = 24 * 60 * 60 * 1000;

const rows = window.__jamStatusRows || new Map();

function statusFor(jam) {
    if (!jam) {
        return { label: '—', color: '#94a3b8', icon: '•' };
    }

    const seen = jam.lastSeen || jam.when;

    if (!seen) {
        return { label: 'Sem informação', color: '#94a3b8', icon: '•' };
    }

    const seenTime = new Date(seen).getTime();

    if (Number.isNaN(seenTime)) {
        return { label: 'Sem informação', color: '#94a3b8', icon: '•' };
    }

    const age = Date.now() - seenTime;

    if (age >= STALE_AFTER_MS) {
        return { label: 'Antigo', color: '#b45309', icon: '🕒' };
    }

    if (age >= RESOLVED_AFTER_MS) {
        return { label: 'Possivelmente resolvido', color: '#2563eb', icon: '✔' };
    }

    return { label: 'Ativo', color: '#16a34a', icon: '🟢' };
}

function badgeFor(jam) {
    const status = statusFor(jam);

    return `<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:${status.color}">${status.icon} ${status.label}</span>`;
}

const tbodySelectors = [
    '[data-jam-table-blocked-active]',
    '[data-jam-table-blocked-stale]',
    '[data-jam-table]',
];

function renderStatuses() {
    tbodySelectors.forEach((selector) => {
        document.querySelectorAll(`${selector} tr[data-jam-id]`).forEach((row) => {
            const jamId = row.dataset.jamId;
            const jam = rows.get(String(jamId));
            let cell = row.querySelector('.jam-status-cell');

            if (!cell) {
                cell = document.createElement('td');
                cell.className = 'jam-status-cell';
                row.appendChild(cell);
            }

            cell.innerHTML = badgeFor(jam);
        });
    });
}

const observer = new MutationObserver((mutations) => {
    const hasRowMutation = mutations.some((mutation) =>
        [...mutation.addedNodes].some((node) => node.nodeName === 'TR'),
    );

    if (hasRowMutation) {
        renderStatuses();
    }
});

tbodySelectors.forEach((selector) => {
    const tbody = document.querySelector(selector);

    if (tbody) {
        observer.observe(tbody, { childList: true });
    }
});

renderStatuses();
setInterval(renderStatuses, 30000);
