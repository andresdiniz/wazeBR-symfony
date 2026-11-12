'use strict';
(function () {
    const descriptions = { 1: 'Mais leve', 2: 'Leve', 3: 'Moderado', 4: 'Grave', 5: 'Mais grave' };
    const colors = { 0: '#64748b', 1: '#16a34a', 2: '#65a30d', 3: '#ca8a04', 4: '#ea580c', 5: '#dc2626' };
    const measures = {
        'hourly-activity': ['alerts', 'jams'],
        'alerts-by-type': ['total'],
        'alerts-by-city': ['total'],
        'alerts-by-street': ['total'],
        'jams-by-level': ['total'],
        'weather-trend': ['temperature', 'humidity', 'precipitation'],
        'hydro-trend': ['avg_level'],
        'pluvio-hourly': ['rain']
    };
    const displays = new WeakMap();
    const validity = new WeakMap();
    const finite = (value) => value !== null && value !== undefined && value !== '' && typeof value !== 'boolean' && Number.isFinite(Number(value));
    function rows(canvas) {
        try { const data = JSON.parse(canvas.dataset.chartData || 'null'); return Array.isArray(data) ? data : null; }
        catch { return null; }
    }
    function hasData(chart) {
        const source = rows(chart.canvas);
        const keys = measures[chart.canvas.dataset.chart];
        if (source !== null && keys) return source.some((point) => point && keys.some((key) => finite(point[key])));
        return (chart.data.datasets || []).some((dataset) => (dataset.data || []).some((point) => finite(point && typeof point === 'object' ? point.y : point)));
    }
    function toggle(element, show) {
        if (!element) return;
        if (!show && !displays.has(element)) {
            displays.set(element, { display: element.style.getPropertyValue('display'), priority: element.style.getPropertyPriority('display'), hidden: element.hidden });
            element.hidden = true;
            element.style.setProperty('display', 'none', 'important');
        } else if (show && displays.has(element)) {
            const previous = displays.get(element);
            element.hidden = previous.hidden;
            if (previous.display) element.style.setProperty('display', previous.display, previous.priority);
            else element.style.removeProperty('display');
            displays.delete(element);
        }
    }
    function applyVisibility(chart) {
        const canvas = chart.canvas;
        validity.set(canvas, hasData(chart));
        const panel = canvas.closest('.dashboard-panel');
        if (!panel) return;
        const canvases = [...panel.querySelectorAll('canvas[data-chart]')];
        const anyData = canvases.some((item) => validity.get(item) !== false);
        toggle(panel, anyData);
        const grid = panel.parentElement;
        if (grid?.matches('.dashboard-main-grid, .dashboard-content-grid, .dashboard-bottom-grid')) {
            toggle(grid, [...grid.children].some((item) => !item.hidden));
        }
    }
    function levelOf(point) {
        const value = point?.level ?? point?.label;
        if (finite(value) && Number.isInteger(Number(value))) return Number(value);
        const match = String(value ?? '').match(/(?:jam_|n[ií]vel\s*)([0-5])\b/i);
        return match ? Number(match[1]) : null;
    }
    function fixLevels(chart) {
        if (chart.canvas.dataset.chart !== 'jams-by-level') return;
        const source = rows(chart.canvas);
        if (!source || source.length !== chart.data.labels.length) return;
        const levels = source.map(levelOf);
        chart.data.labels = levels.map((level) => level === null ? 'Nível não informado' : `Nível ${level}${descriptions[level] ? ' — ' + descriptions[level] : ''}`);
        for (const dataset of chart.data.datasets || []) {
            dataset.label = 'Congestionamentos';
            dataset.backgroundColor = levels.map((level) => colors[level] || '#64748b');
            dataset.borderColor = levels.map((level) => colors[level] || '#64748b');
        }
        if (chart.options.scales?.x?.ticks) {
            chart.options.scales.x.ticks.autoSkip = false;
        }
    }
    const plugin = {
        id: 'wazebrDashboardSemantics',
        beforeUpdate(chart) {
            if (!chart.canvas.closest('[data-dashboard]')) return;
            fixLevels(chart);
            applyVisibility(chart);
        }
    };
    let installed = false;
    function install() {
        if (installed || typeof Chart === 'undefined' || typeof Chart.register !== 'function') return;
        installed = true;
        Chart.register(plugin);
        Object.values(Chart.instances || {}).forEach((chart) => {
            if (!chart.canvas?.closest('[data-dashboard]')) return;
            chart.update('none');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
    else install();
    window.addEventListener('wazebr:ready', install);
    window.addEventListener('load', install, { once: true });
})();
