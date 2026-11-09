const RESOLVED_AFTER_MS = 10 * 60 * 1000;
const STALE_AFTER_MS = 24 * 60 * 60 * 1000;

const rows = window.__jamStatusRows || new Map();

function statusFor(jam) {
    if (!jam) {
        return { key: 'unknown', label: '—', color: '#94a3b8', icon: '•' };
    }

    if (jam.isActive === false) {
        return { key: 'closed', label: 'Encerrado', color: '#64748b', icon: '⊘' };
    }

    const seen = jam.lastSeen || jam.when;

    if (!seen) {
        return { key: 'unknown', label: 'Sem informação', color: '#94a3b8', icon: '•' };
    }

    const seenTime = new Date(seen).getTime();

    if (Number.isNaN(seenTime)) {
        return { key: 'unknown', label: 'Sem informação', color: '#94a3b8', icon: '•' };
    }

    const age = Date.now() - seenTime;

    if (age >= STALE_AFTER_MS) {
        return { key: 'stale', label: 'Antigo', color: '#b45309', icon: '🕒' };
    }

    if (age >= RESOLVED_AFTER_MS) {
        return { key: 'resolved', label: 'Possivelmente resolvido', color: '#2563eb', icon: '✔' };
    }

    return { key: 'active', label: 'Ativo', color: '#16a34a', icon: '🟢' };
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

function statusFilter() {
    const select = document.querySelector('[data-jam-filter="status"]');

    return select ? select.value : '';
}

function shouldShowRow(jam) {
    const filter = statusFilter();

    if (!filter) {
        return true;
    }

    return statusFor(jam).key === filter;
}

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
            row.style.display = shouldShowRow(jam) ? '' : 'none';
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

document.querySelector('[data-jam-filter="status"]')?.addEventListener('change', renderStatuses);

function currentShareUrl() {
    return window.location.href;
}

function whatsappUrl() {
    const text = `Veja os congestionamentos monitorados no WazeBR: ${currentShareUrl()}`;

    return `https://wa.me/?text=${encodeURIComponent(text)}`;
}

document.querySelector('[data-action="copy-link"]')?.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(currentShareUrl());
    } catch (error) {
        // Permite a cópia manual caso o navegador bloqueie a Clipboard API.
    }
});

document.querySelector('[data-action="whatsapp-share"]')?.addEventListener('click', (event) => {
    event.preventDefault();
    window.open(whatsappUrl(), '_blank', 'noopener');
});

renderStatuses();
setInterval(renderStatuses, 30000);
