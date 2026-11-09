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

function formatAge(milliseconds) {
    if (!Number.isFinite(milliseconds) || milliseconds < 0) {
        return 'sem informação';
    }

    const minutes = Math.floor(milliseconds / 60000);

    if (minutes < 1) {
        return 'agora';
    }

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} h`;
    }

    return `${Math.floor(hours / 24)} d`;
}

function renderStatusPanel() {
    const panel = document.querySelector('[data-jam-status-panel]');

    if (!panel) {
        return;
    }

    const counts = { active: 0, resolved: 0, stale: 0, closed: 0 };
    let latest = null;

    rows.forEach((jam) => {
        const status = statusFor(jam);

        if (counts[status.key] !== undefined) {
            counts[status.key] += 1;
        }

        const seen = jam.lastSeen || jam.when;
        const time = seen ? new Date(seen).getTime() : NaN;

        if (!Number.isNaN(time) && (latest === null || time > latest)) {
            latest = time;
        }
    });

    panel.querySelectorAll('[data-status-count]').forEach((element) => {
        const key = element.dataset.statusCount;
        element.textContent = counts[key] !== undefined ? String(counts[key]) : '0';
    });

    const updated = panel.querySelector('[data-status-updated]');

    if (updated) {
        updated.textContent = latest === null ? 'Sem atualização disponível' : `Última atualização há ${formatAge(Date.now() - latest)}`;
    }
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

    renderStatusPanel();
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

renderStatuses();
setInterval(renderStatuses, 30000);