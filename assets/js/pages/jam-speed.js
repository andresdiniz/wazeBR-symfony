const NS = 'http://www.w3.org/2000/svg';
const root = document.querySelector('[data-speed-page]');
if (root) initSpeedPage(root);

function initSpeedPage(page) {
    const host = page.querySelector('[data-speed-chart]');
    if (!host) return;
    let data;
    try { data = JSON.parse(host.dataset.history); } catch { return; }
    if (!Array.isArray(data.samples) || !data.statistics) return;
    const samples = data.samples.filter(p => Number.isFinite(p.at) && p.at > 0).map(p => ({at: p.at, speed: typeof p.speed === 'number' && Number.isFinite(p.speed) && p.speed >= 0 ? p.speed : null})).sort((a, b) => a.at - b.at);
    const stats = data.statistics;
    const format = new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 1});
    let dates;
    try { dates = new Intl.DateTimeFormat('pt-BR', {timeZone: page.dataset.timezone, dateStyle: 'short', timeStyle: 'medium'}); }
    catch { dates = new Intl.DateTimeFormat('pt-BR', {timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'medium'}); }
    const date = at => dates.format(new Date(at * 1000));
    const range = page.querySelector('[data-speed-range]');
    const mean = page.querySelector('[data-speed-mean]');
    const tip = page.querySelector('[data-speed-tooltip]');
    const svgNode = (tag, attrs = {}, text = '') => {
        const node = document.createElementNS(NS, tag);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, String(value));
        if (text) node.textContent = text;
        return node;
    };
    function draw() {
        const minutes = Number(range.value);
        const last = samples.at(-1)?.at ?? 0;
        const visible = minutes > 0 ? samples.filter(p => p.at >= last - minutes * 60) : samples;
        host.replaceChildren();
        if (!visible.length || !visible.some(p => p.speed !== null)) {
            const message = document.createElement('p');
            message.className = 'speed-chart-help';
            message.textContent = 'Não há leituras válidas neste período. Consulte a tabela ou amplie a janela.';
            host.append(message);
            tip.textContent = 'Sem pontos válidos para consultar.';
            return;
        }
        const first = visible[0].at;
        const end = visible.at(-1).at;
        let peak = 1;
        for (const p of visible) if (p.speed !== null) peak = Math.max(peak, p.speed);
        const hasMean = typeof stats.mean_speed_kmh === 'number' && Number.isFinite(stats.mean_speed_kmh);
        if (mean.checked && hasMean) peak = Math.max(peak, stats.mean_speed_kmh);
        const ceiling = Math.max(5, Math.ceil(peak / 5) * 5);
        const x = at => first === end ? 470 : 60 + 820 * (at - first) / (end - first);
        const y = speed => 270 - 230 * speed / ceiling;
        const svg = svgNode('svg', {viewBox: '0 0 940 325', role: 'img', tabindex: '0', 'aria-label': 'Gráfico de velocidade em km/h. Use as setas esquerda e direita para consultar leituras.'});
        svg.append(svgNode('title', {}, 'Velocidade observada no trecho'));
        for (let i = 0; i <= 4; i++) {
            const value = ceiling * i / 4;
            svg.append(svgNode('line', {x1: 60, x2: 880, y1: y(value), y2: y(value), class: 'speed-grid'}));
            svg.append(svgNode('text', {x: 48, y: y(value) + 4, 'text-anchor': 'end'}, format.format(value)));
        }
        svg.append(svgNode('text', {x: 60, y: 20}, 'km/h'));
        svg.append(svgNode('text', {x: 60, y: 300}, date(first)));
        if (first !== end) svg.append(svgNode('text', {x: 880, y: 300, 'text-anchor': 'end'}, date(end)));
        for (let i = 1; i < visible.length; i++) {
            const a = visible[i - 1], b = visible[i];
            if (b.at - a.at > stats.max_gap_seconds || a.speed === null || b.speed === null) {
                svg.append(svgNode('rect', {x: x(a.at), y: 40, width: Math.max(0, x(b.at) - x(a.at)), height: 230, class: 'speed-gap'}));
            } else {
                svg.append(svgNode('path', {d: `M${x(a.at)} ${y(a.speed)} H${x(b.at)} V${y(b.speed)}`, class: 'speed-line'}));
            }
        }
        if (mean.checked && hasMean) {
            const line = svgNode('line', {x1: 60, x2: 880, y1: y(stats.mean_speed_kmh), y2: y(stats.mean_speed_kmh), class: 'speed-mean'});
            line.append(svgNode('title', {}, `Média do episódio: ${format.format(stats.mean_speed_kmh)} km/h`));
            svg.append(line);
        }
        for (const p of visible) {
            if (p.speed === null) continue;
            const circle = svgNode('circle', {cx: x(p.at), cy: y(p.speed), r: visible.length > 200 ? 2 : 3, class: 'speed-point'});
            circle.append(svgNode('title', {}, `${date(p.at)} · ${format.format(p.speed)} km/h`));
            svg.append(circle);
        }
        const cursor = svgNode('circle', {r: 6, class: 'speed-cursor', visibility: 'hidden'});
        svg.append(cursor);
        let selected = visible.length - 1;
        function select(index) {
            selected = Math.max(0, Math.min(visible.length - 1, index));
            const point = visible[selected];
            tip.textContent = `${date(point.at)} · ${point.speed === null ? 'Sem medição válida' : format.format(point.speed) + ' km/h'} · leitura ${selected + 1} de ${visible.length} na janela`;
            cursor.setAttribute('visibility', point.speed === null ? 'hidden' : 'visible');
            if (point.speed !== null) {
                cursor.setAttribute('cx', x(point.at));
                cursor.setAttribute('cy', y(point.speed));
            }
        }
        function nearest(event) {
            const matrix = svg.getScreenCTM();
            if (!matrix) return;
            const point = svg.createSVGPoint();
            point.x = event.clientX;
            point.y = event.clientY;
            const local = point.matrixTransform(matrix.inverse());
            const target = first + Math.max(0, Math.min(1, (local.x - 60) / 820)) * (end - first);
            let low = 0, high = visible.length - 1;
            while (low < high) { const mid = Math.floor((low + high) / 2); if (visible[mid].at < target) low = mid + 1; else high = mid; }
            select(low > 0 && target - visible[low - 1].at <= visible[low].at - target ? low - 1 : low);
        }
        svg.addEventListener('pointerdown', nearest);
        svg.addEventListener('pointermove', event => { if (event.pointerType === 'mouse') nearest(event); });
        svg.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            select(event.key === 'Home' ? 0 : event.key === 'End' ? visible.length - 1 : selected + (event.key === 'ArrowLeft' ? -1 : 1));
        });
        host.append(svg);
        select(visible.length - 1);
    }
    range.addEventListener('change', draw);
    mean.addEventListener('change', draw);
    draw();
    const rows = [...page.querySelectorAll('[data-speed-rows] tr')];
    const prev = page.querySelector('[data-speed-prev]');
    const next = page.querySelector('[data-speed-next]');
    const label = page.querySelector('[data-speed-page-label]');
    let current = 0;
    const pages = Math.max(1, Math.ceil(rows.length / 25));
    function paginate() {
        rows.forEach((row, index) => { row.hidden = index < current * 25 || index >= (current + 1) * 25; });
        prev.disabled = current === 0;
        next.disabled = current === pages - 1;
        label.textContent = `Página ${current + 1} de ${pages}`;
    }
    prev.addEventListener('click', () => { if (current > 0) { current--; paginate(); } });
    next.addEventListener('click', () => { if (current < pages - 1) { current++; paginate(); } });
    paginate();
    page.querySelector('[data-speed-export]').addEventListener('click', () => {
        const quote = value => '"' + String(value).replaceAll('"', '""') + '"';
        const lines = [['Horario UTC ISO8601', 'Horario local (' + page.dataset.timezone + ')', 'Velocidade km/h', 'Validade'], ...samples.map(p => [new Date(p.at * 1000).toISOString(), date(p.at), p.speed === null ? '' : String(p.speed).replace('.', ','), p.speed === null ? 'Sem medicao valida' : 'Valida'])];
        const csv = '\uFEFF' + lines.map(line => line.map(quote).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'historico-velocidade.csv';
        document.body.append(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 10000);
    });
    page.querySelectorAll('[data-enhancement]').forEach(element => { element.hidden = false; });
}
