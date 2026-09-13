export function initDashboard(root = document) {
    const dashboard = root.querySelector('[data-dashboard]');

    if (!dashboard || dashboard.dataset.initialized === 'true') {
        return;
    }

    dashboard.dataset.initialized = 'true';

    const reducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    initReveal(dashboard, reducedMotion);
    initCounters(dashboard, reducedMotion);
    initClock(dashboard);
    initFilters(dashboard);
    initRefreshButton(dashboard);
    initDashboardMap(dashboard);
    initDashboardCharts(dashboard);
}

function initReveal(dashboard, reducedMotion) {
    const elements = dashboard.querySelectorAll(
        '.dashboard-reveal',
    );

    if (
        reducedMotion
        || !('IntersectionObserver' in window)
    ) {
        elements.forEach((element) => {
            element.classList.add('is-visible');
        });

        return;
    }

    const observer = new IntersectionObserver(
        (entries, currentObserver) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-visible');
                currentObserver.unobserve(entry.target);
            });
        },
        {
            threshold: .08,
        },
    );

    elements.forEach((element, index) => {
        element.style.transitionDelay = `${Math.min(
            index * 45,
            280,
        )}ms`;

        observer.observe(element);
    });
}

function initCounters(dashboard, reducedMotion) {
    const elements = dashboard.querySelectorAll(
        '[data-count-value]',
    );

    elements.forEach((element) => {
        const target = Number(
            element.dataset.countValue || 0,
        );

        if (
            reducedMotion
            || !Number.isFinite(target)
            || target === 0
        ) {
            element.textContent = String(target);
            return;
        }

        const start = performance.now();
        const duration = 850;

        const tick = (now) => {
            const progress = Math.min(
                (now - start) / duration,
                1,
            );

            const eased = 1 - Math.pow(1 - progress, 3);

            element.textContent = String(
                Math.round(target * eased),
            );

            if (progress < 1) {
                window.requestAnimationFrame(tick);
            }
        };

        window.requestAnimationFrame(tick);
    });
}

function initClock(dashboard) {
    const clock = dashboard.querySelector(
        '[data-dashboard-clock]',
    );

    if (!clock) {
        return;
    }

    const update = () => {
        clock.textContent = new Intl.DateTimeFormat(
            'pt-BR',
            {
                hour: '2-digit',
                minute: '2-digit',
            },
        ).format(new Date());
    };

    update();
    window.setInterval(update, 30000);
}

function initFilters(dashboard) {
    const form = dashboard.querySelector(
        '[data-dashboard-filter-form]',
    );

    if (!form) {
        return;
    }

    const clearButton = form.querySelector(
        '[data-dashboard-clear-filters]',
    );

    clearButton?.addEventListener('click', () => {
        form.querySelectorAll('input, select').forEach(
            (field) => {
                field.value = '';
            },
        );

        form.submit();
    });
}

function initRefreshButton(dashboard) {
    const button = dashboard.querySelector(
        '[data-action="refresh-dashboard"]',
    );

    if (!button) {
        return;
    }

    button.addEventListener('click', () => {
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');

        window.location.reload();
    });
}

function initDashboardMap(dashboard) {
    const element = dashboard.querySelector(
        '[data-dashboard-map]',
    );

    if (!element) {
        return;
    }

    if (!window.L) {
        element.innerHTML = `
            <div class="dashboard-empty">
                <strong>Mapa indisponível</strong>
                <span>Leaflet não foi carregado.</span>
            </div>
        `;

        return;
    }

    if (element.dataset.initialized === 'true') {
        return;
    }

    element.dataset.initialized = 'true';

    const latitude = Number(
        element.dataset.latitude || -20.66,
    );

    const longitude = Number(
        element.dataset.longitude || -43.79,
    );

    const zoom = Number(
        element.dataset.zoom || 13,
    );

    const map = window.L.map(element).setView(
        [latitude, longitude],
        zoom,
    );

    window.L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        },
    ).addTo(map);

    const markers = readJsonData(
        element.dataset.markers,
        [],
    );

    const lines = readJsonData(
        element.dataset.lines,
        [],
    );

    const bounds = [];

    markers.forEach((item) => {
        const lat = Number(item.latitude);
        const lon = Number(item.longitude);

        if (
            !Number.isFinite(lat)
            || !Number.isFinite(lon)
        ) {
            return;
        }

        const marker = window.L.marker([lat, lon])
            .addTo(map);

        const title = escapeHtml(
            item.title || item.type || 'Ocorrência',
        );

        const detail = escapeHtml(
            item.detail || item.street || '',
        );

        marker.bindPopup(`
            <div class="dashboard-map-popup">
                <strong>${title}</strong>
                <span>${detail}</span>
            </div>
        `);

        bounds.push([lat, lon]);
    });

    lines.forEach((item) => {
        if (!Array.isArray(item.coordinates)) {
            return;
        }

        const coordinates = item.coordinates
            .filter((point) => (
                Array.isArray(point)
                && point.length >= 2
            ))
            .map((point) => [
                Number(point[1]),
                Number(point[0]),
            ]);

        if (coordinates.length < 2) {
            return;
        }

        window.L.polyline(
            coordinates,
            {
                color: item.color || '#2563eb',
                weight: 4,
                opacity: .78,
            },
        ).addTo(map);

        coordinates.forEach((point) => {
            bounds.push(point);
        });
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, {
            padding: [24, 24],
        });
    }

    window.setTimeout(() => {
        map.invalidateSize();
    }, 100);
}

function initDashboardCharts(dashboard) {
    const canvasElements = dashboard.querySelectorAll(
        '[data-dashboard-chart]',
    );

    canvasElements.forEach((canvas) => {
        if (!window.Chart) {
            return;
        }

        if (canvas.dataset.initialized === 'true') {
            return;
        }

        const labels = readJsonData(
            canvas.dataset.labels,
            [],
        );

        const values = readJsonData(
            canvas.dataset.values,
            [],
        );

        canvas.dataset.initialized = 'true';

        new window.Chart(canvas, {
            type: canvas.dataset.chartType || 'line',
            data: {
                labels,
                datasets: [{
                    label: canvas.dataset.label || '',
                    data: values,
                    borderColor: canvas.dataset.color || '#2563eb',
                    backgroundColor: canvas.dataset.background
                        || 'rgba(37, 99, 235, .12)',
                    borderWidth: 2,
                    fill: true,
                    tension: .35,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    },
                },
                scales: {
                    x: {
                        grid: {
                            display: false,
                        },
                    },
                    y: {
                        beginAtZero: true,
                    },
                },
            },
        });
    });
}

function readJsonData(value, fallback) {
    if (!value) {
        return fallback;
    }

    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
