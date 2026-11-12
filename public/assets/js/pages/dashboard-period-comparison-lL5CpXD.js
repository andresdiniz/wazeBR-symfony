'use strict';
(function () {
    function init() {
        const panel = document.querySelector('[data-period-comparison]');
        if (!panel || panel.dataset.ready) return;
        panel.dataset.ready = '1';
        const output = panel.querySelector('[data-comparison-output]');
        const select = panel.querySelector('[data-comparison-minutes]');
        const button = panel.querySelector('[data-comparison-refresh]');
        const initial = new URLSearchParams(location.search).get('comparison_minutes');
        if (['30', '60', '180'].includes(initial)) select.value = initial;
        let controller = null;
        let timer = null;
        const nf = new Intl.NumberFormat(document.documentElement.lang || 'pt-BR', { maximumFractionDigits: 1 });
        const date = (value) => value ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'Sem observação';
        const add = (tag, text, parent = output) => { const node = document.createElement(tag); node.textContent = text; parent.append(node); return node; };
        function filters() {
            const params = new URLSearchParams(location.search);
            const form = document.querySelector('[data-filter-form]');
            if (form) for (const [key, value] of new FormData(form)) params.set(key, String(value).trim());
            params.set('comparison_minutes', select.value);
            return params;
        }
        async function load() {
            if (document.hidden) return;
            controller?.abort();
            const active = new AbortController();
            controller = active;
            const timeout = setTimeout(() => active.abort(), 30000);
            button.disabled = true;
            output.setAttribute('aria-busy', 'true');
            output.replaceChildren();
            add('p', 'Carregando comparação…');
            try {
                const response = await fetch(`${panel.dataset.endpoint}?${filters()}`, { signal: active.signal, credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-cache' });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (controller !== active) return;
                output.replaceChildren();
                if (!data.available) { add('p', data.reason || 'Comparação indisponível.'); return; }
                add('p', `Anterior: ${date(data.previous_from)} até ${date(data.current_from)}. Recente: ${date(data.current_from)} até ${date(data.current_to)} (Brasília).`);
                add('p', `${data.comparable} rotas comparáveis: ${data.worse} pioraram, ${data.better} melhoraram e ${data.stable} estáveis. ${data.excluded} rotas observadas excluídas por cobertura insuficiente.`);
                add('p', `Atraso médio por rota: ${nf.format(data.previous_avg)} s → ${nf.format(data.current_avg)} s. Diferença: ${nf.format(data.delta_avg)} s.`);
                add('p', `Última observação incluída: ${date(data.last_observed_at)}. Cálculo: ${date(data.generated_at)}.`);
                add('h3', 'Até 10 rotas por maior variação de atraso');
                const list = add('ol', '');
                for (const route of data.routes) {
                    const state = { worse: 'Piorou', better: 'Melhorou', stable: 'Estável' }[route.status];
                    const percent = route.percent === null ? 'percentual indisponível: base zero' : `${nf.format(route.percent)}%`;
                    add('li', `${route.name}: ${state} · ${nf.format(route.previous)} s → ${nf.format(route.current)} s · diferença ${nf.format(route.delta)} s (${percent}) · amostras ${route.previous_samples}/${route.current_samples}.`, list);
                }
            } catch (error) {
                if (controller !== active) return;
                output.replaceChildren();
                add('p', 'Não foi possível carregar a comparação. Isso não significa estabilidade nem ausência de ocorrências. Tente atualizar novamente.');
            } finally {
                clearTimeout(timeout);
                if (controller === active) { controller = null; button.disabled = false; output.removeAttribute('aria-busy'); }
            }
        }
        const schedule = () => { clearTimeout(timer); timer = setTimeout(load, 400); };
        select.addEventListener('change', () => { const url = new URL(location.href); url.searchParams.set('comparison_minutes', select.value); history.replaceState(history.state, '', url); load(); });
        button.addEventListener('click', load);
        document.querySelector('[data-filter-form]')?.addEventListener('submit', schedule);
        document.addEventListener('click', (event) => { if (event.target.closest('[data-action="apply-filters"], [data-action="clear-filters"], [data-action="refresh-dashboard"]')) schedule(); });
        window.addEventListener('popstate', () => { const value = new URLSearchParams(location.search).get('comparison_minutes'); select.value = ['30', '60', '180'].includes(value) ? value : '30'; load(); });
        const interval = setInterval(load, 120000);
        document.addEventListener('visibilitychange', () => { if (document.hidden) controller?.abort(); else load(); });
        window.addEventListener('pagehide', () => { controller?.abort(); clearInterval(interval); clearTimeout(timer); }, { once: true });
        load();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
