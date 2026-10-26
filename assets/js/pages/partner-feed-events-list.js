/**
 * pages/partner-feed-events-list.js — Lista de eventos do parceiro.
 *
 * - Filtros por texto, tipo e fase (no ar / agendado / expirado / inativo)
 * - Fase calculada no navegador a partir de data-event-start/end e atualizada a cada minuto
 * - Filtros lembrados na sessão (sessionStorage) por parceiro
 * - Atalho "/" foca a busca; Esc limpa
 * - Confirmação de exclusão com o nome do evento e proteção contra duplo envio
 */

const PHASE_LABELS = {
    live: 'No ar',
    scheduled: 'Agendado',
    expired: 'Expirado',
    inactive: 'Inativo',
};

const SEARCH_DEBOUNCE_MS = 120;
const PHASE_REFRESH_MS = 60_000;

export function initPartnerFeedEventsList(root = document) {
    const page = root.querySelector('[data-partner-events-list]');

    if (!page || page.dataset.partnerEventsListInit === '1') {
        return;
    }

    page.dataset.partnerEventsListInit = '1';

    const rows = [...page.querySelectorAll('[data-event-row]')];
    const searchInput = page.querySelector('[data-event-search]');
    const statusFilter = page.querySelector('[data-event-status-filter]');
    const typeFilter = page.querySelector('[data-event-type-filter]');
    const clearButton = page.querySelector('[data-clear-filters]');
    const countElement = page.querySelector('[data-visible-count]');
    const countLabel = page.querySelector('[data-visible-label]');
    const noResults = page.querySelector('[data-no-results]');

    const storageKey = `pf-events-filters:${window.location.pathname}`;
    const normalize = (value) => (value || '').toString().trim().toLowerCase();

    // ── Fase do evento ────────────────────────────────────────────────────

    function computePhase(row, nowSec) {
        if (row.dataset.eventStatus !== 'active') return 'inactive';

        const start = Number(row.dataset.eventStart);
        const end = Number(row.dataset.eventEnd);

        if (row.dataset.eventEnd && Number.isFinite(end) && end < nowSec) return 'expired';
        if (row.dataset.eventStart && Number.isFinite(start) && start > nowSec) return 'scheduled';
        return 'live';
    }

    function refreshPhases() {
        const nowSec = Math.floor(Date.now() / 1000);

        rows.forEach((row) => {
            const phase = computePhase(row, nowSec);
            row.dataset.eventPhase = phase;

            const pill = row.querySelector('.partner-event-status');
            if (pill) {
                pill.textContent = PHASE_LABELS[phase];
                pill.className = `partner-event-status is-${phase}`;
                pill.title = phase === 'expired'
                    ? 'Ativo, mas já passou do fim: não é mais publicado no feed.'
                    : phase === 'scheduled'
                        ? 'Planejado: entra no feed até 7 dias antes do início.'
                        : '';
            }

            const dot = row.querySelector('.partner-event-identity__dot');
            if (dot) dot.className = `partner-event-identity__dot is-${phase}`;
        });
    }

    // ── Filtros ───────────────────────────────────────────────────────────

    function applyFilters({ persist = true } = {}) {
        const query = normalize(searchInput?.value);
        const status = normalize(statusFilter?.value || 'all');
        const type = normalize(typeFilter?.value || 'all');
        let visibleCount = 0;

        rows.forEach((row) => {
            const matchesQuery = !query || normalize(row.dataset.eventSearchText).includes(query);
            const matchesStatus = status === 'all' || row.dataset.eventPhase === status;
            const matchesType = type === 'all' || row.dataset.eventType === type;
            const visible = matchesQuery && matchesStatus && matchesType;

            row.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        if (countElement) countElement.textContent = visibleCount;
        if (countLabel) {
            countLabel.textContent = visibleCount === 1 ? 'evento encontrado' : 'eventos encontrados';
        }
        if (noResults) noResults.hidden = rows.length === 0 || visibleCount !== 0;

        if (persist) saveFilters();
    }

    function saveFilters() {
        try {
            const state = {
                q: searchInput?.value ?? '',
                status: statusFilter?.value ?? 'all',
                type: typeFilter?.value ?? 'all',
            };
            const isDefault = !state.q && state.status === 'all' && state.type === 'all';
            if (isDefault) sessionStorage.removeItem(storageKey);
            else sessionStorage.setItem(storageKey, JSON.stringify(state));
        } catch { /* storage bloqueado: ignora */ }
    }

    function restoreFilters() {
        try {
            const raw = sessionStorage.getItem(storageKey);
            if (!raw) return;
            const state = JSON.parse(raw);

            if (searchInput && typeof state.q === 'string') searchInput.value = state.q;
            // só restaura se a opção ainda existe (os tipos dependem da página atual)
            const setSelect = (select, value) => {
                if (select && [...select.options].some((o) => o.value === value)) select.value = value;
            };
            setSelect(statusFilter, state.status);
            setSelect(typeFilter, state.type);
        } catch { /* JSON/storage inválido: ignora */ }
    }

    let searchTimer = null;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, SEARCH_DEBOUNCE_MS);
    });
    statusFilter?.addEventListener('change', () => applyFilters());
    typeFilter?.addEventListener('change', () => applyFilters());

    clearButton?.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        if (statusFilter) statusFilter.value = 'all';
        if (typeFilter) typeFilter.value = 'all';
        applyFilters();
        searchInput?.focus();
    });

    // ── Atalhos: "/" foca a busca, Esc limpa o texto ──────────────────────

    document.addEventListener('keydown', (event) => {
        const el = document.activeElement;
        const typing = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA'
            || el.tagName === 'SELECT' || el.isContentEditable);

        if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            searchInput?.focus();
            searchInput?.select();
        }
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && searchInput.value) {
            event.preventDefault();
            searchInput.value = '';
            applyFilters();
        }
    });

    // ── Ações: confirmar exclusão e evitar duplo envio ────────────────────

    page.querySelectorAll('[data-event-row] form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.hasAttribute('data-delete-form')) {
                const title = form.closest('[data-event-row]')
                    ?.querySelector('.partner-event-identity__title')?.textContent?.trim();
                const message = title
                    ? `Excluir o evento "${title}"? Essa ação não pode ser desfeita.`
                    : 'Tem certeza que deseja excluir este evento? Essa ação não pode ser desfeita.';

                if (!window.confirm(message)) {
                    event.preventDefault();
                    return;
                }
            }

            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = '1';
            // desabilita depois do envio iniciar (desabilitar antes pode cancelar o submit)
            setTimeout(() => form.querySelectorAll('button').forEach((b) => { b.disabled = true; }), 0);
        });
    });

    // Voltar pelo histórico (bfcache) não deve deixar botões travados
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        page.querySelectorAll('form[data-submitting]').forEach((form) => {
            delete form.dataset.submitting;
            form.querySelectorAll('button').forEach((b) => { b.disabled = false; });
        });
    });

    // ── Inicialização ─────────────────────────────────────────────────────

    restoreFilters();
    refreshPhases();
    applyFilters({ persist: false });
    setInterval(() => {
        if (document.hidden) return;
        refreshPhases();
        applyFilters({ persist: false });
    }, PHASE_REFRESH_MS);
}

export default initPartnerFeedEventsList;
