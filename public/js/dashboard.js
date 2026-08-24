(() => {
    'use strict';

    const Dashboard = {
        config: {
            refreshInterval: 60_000,
            notificationDuration: 5_000,
            animationDuration: 300,
            endpoints: {
                metrics: '/dashboard/metrics',
                recent: '/dashboard/recent',
            },
        },

        state: {
            refreshTimer: null,
            isRefreshing: false,
            observer: null,
            visible: document.visibilityState === 'visible',
        },

        elements: {},

        init() {
            this.cacheElements();
            this.bindEvents();
            this.setupObservers();
            this.setupAutoRefresh();
            this.initializeTooltips();
            this.updateLastRefreshTime();
        },

        cacheElements() {
            this.elements = {
                root: document.documentElement,
                dashboard: document.querySelector('[data-dashboard]'),
                refreshButton: document.querySelector('[data-dashboard-refresh]'),
                refreshTime: document.querySelector('[data-last-refresh]'),
                statValues: [...document.querySelectorAll('[data-stat-value]')],
                sections: [...document.querySelectorAll('[data-animate-section]')],
                liveIndicator: document.querySelector('[data-live-indicator]'),
                notifications: document.querySelector('[data-dashboard-notifications]'),
                tables: [...document.querySelectorAll('[data-sortable-table]')],
                filters: [...document.querySelectorAll('[data-dashboard-filter]')],
            };
        },

        bindEvents() {
            this.elements.refreshButton?.addEventListener('click', () => this.refresh());

            document.addEventListener('visibilitychange', () => {
                this.state.visible = document.visibilityState === 'visible';

                if (this.state.visible) {
                    this.refresh();
                    this.setupAutoRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            });

            window.addEventListener('resize', this.debounce(() => {
                window.dispatchEvent(new CustomEvent('dashboard:resize'));
            }, 180), { passive: true });

            this.elements.tables.forEach((table) => this.enableTableSorting(table));
            this.elements.filters.forEach((filter) => {
                filter.addEventListener('change', () => this.applyFilters());
            });
        },

        setupObservers() {
            if (!('IntersectionObserver' in window)) {
                this.elements.sections.forEach((section) => section.classList.add('is-visible'));
                return;
            }

            this.state.observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    this.state.observer.unobserve(entry.target);
                });
            }, { rootMargin: '0px 0px -48px', threshold: 0.08 });

            this.elements.sections.forEach((section) => this.state.observer.observe(section));
        },

        setupAutoRefresh() {
            this.stopAutoRefresh();

            if (!this.state.visible) return;

            this.state.refreshTimer = window.setInterval(() => this.refresh(), this.config.refreshInterval);
        },

        stopAutoRefresh() {
            if (this.state.refreshTimer) {
                window.clearInterval(this.state.refreshTimer);
                this.state.refreshTimer = null;
            }
        },

        async refresh() {
            if (this.state.isRefreshing || !this.state.visible) return;

            this.state.isRefreshing = true;
            this.setLoading(true);

            try {
                const response = await fetch(this.config.endpoints.metrics, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout(15_000),
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const payload = await response.json();
                this.updateMetrics(payload);
                this.updateLastRefreshTime();
                this.flashLiveIndicator('Dados atualizados');
                document.dispatchEvent(new CustomEvent('dashboard:refreshed', { detail: payload }));
            } catch (error) {
                console.error('Dashboard refresh failed:', error);
                this.notify('Não foi possível atualizar os dados. Tentaremos novamente automaticamente.', 'warning');
            } finally {
                this.state.isRefreshing = false;
                this.setLoading(false);
            }
        },

        updateMetrics(payload) {
            const metrics = payload.metrics ?? payload;

            this.elements.statValues.forEach((element) => {
                const key = element.dataset.statValue;
                if (!(key in metrics)) return;

                const nextValue = metrics[key];
                const numericValue = Number(String(nextValue).replace(/[^0-9.-]/g, ''));

                if (Number.isFinite(numericValue) && element.dataset.animateNumber !== 'false') {
                    this.animateNumber(element, numericValue, element.dataset.format ?? 'number');
                } else {
                    element.textContent = nextValue;
                }
            });

            if (payload.updatedHtml) {
                Object.entries(payload.updatedHtml).forEach(([selector, html]) => {
                    const target = document.querySelector(selector);
                    if (target) target.innerHTML = html;
                });
            }
        },

        animateNumber(element, endValue, format) {
            const current = Number(element.dataset.currentValue ?? 0);
            const startValue = Number.isFinite(current) ? current : 0;
            const duration = this.config.animationDuration;
            const startedAt = performance.now();

            const formatter = new Intl.NumberFormat('pt-BR', {
                maximumFractionDigits: format === 'decimal' ? 1 : 0,
                minimumFractionDigits: format === 'decimal' ? 1 : 0,
            });

            const tick = (now) => {
                const progress = Math.min((now - startedAt) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                const value = startValue + ((endValue - startValue) * eased);
                element.textContent = formatter.format(value);

                if (progress < 1) {
                    window.requestAnimationFrame(tick);
                } else {
                    element.dataset.currentValue = String(endValue);
                }
            };

            window.requestAnimationFrame(tick);
        },

        enableTableSorting(table) {
            const headers = [...table.querySelectorAll('thead th[data-sort-key]')];
            const body = table.tBodies[0];
            if (!body || headers.length === 0) return;

            headers.forEach((header, columnIndex) => {
                header.tabIndex = 0;
                header.setAttribute('role', 'button');
                header.setAttribute('aria-label', `Ordenar por ${header.textContent.trim()}`);

                const sort = () => {
                    const direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                    headers.forEach((item) => {
                        item.dataset.sortDirection = '';
                        item.removeAttribute('aria-sort');
                    });

                    header.dataset.sortDirection = direction;
                    header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');

                    const rows = [...body.rows].sort((a, b) => {
                        const aValue = a.cells[columnIndex]?.dataset.sortValue ?? a.cells[columnIndex]?.textContent.trim() ?? '';
                        const bValue = b.cells[columnIndex]?.dataset.sortValue ?? b.cells[columnIndex]?.textContent.trim() ?? '';
                        return this.compareValues(aValue, bValue, direction);
                    });

                    const fragment = document.createDocumentFragment();
                    rows.forEach((row) => fragment.appendChild(row));
                    body.appendChild(fragment);
                };

                header.addEventListener('click', sort);
                header.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        sort();
                    }
                });
            });
        },

        compareValues(a, b, direction) {
            const aNumber = Number(String(a).replace(/[^0-9,.-]/g, '').replace(',', '.'));
            const bNumber = Number(String(b).replace(/[^0-9,.-]/g, '').replace(',', '.'));
            const compare = Number.isFinite(aNumber) && Number.isFinite(bNumber)
                ? aNumber - bNumber
                : String(a).localeCompare(String(b), 'pt-BR', { numeric: true, sensitivity: 'base' });

            return direction === 'asc' ? compare : -compare;
        },

        applyFilters() {
            const filters = this.elements.filters.reduce((state, input) => {
                if (input.value) state[input.dataset.dashboardFilter] = input.value;
                return state;
            }, {});

            document.dispatchEvent(new CustomEvent('dashboard:filters-changed', { detail: filters }));
        },

        setLoading(isLoading) {
            this.elements.dashboard?.classList.toggle('is-refreshing', isLoading);
            this.elements.refreshButton?.classList.toggle('is-loading', isLoading);
            if (this.elements.refreshButton) this.elements.refreshButton.disabled = isLoading;
        },

        updateLastRefreshTime() {
            if (!this.elements.refreshTime) return;
            const now = new Intl.DateTimeFormat('pt-BR', {
                hour: '2-digit', minute: '2-digit', second: '2-digit',
            }).format(new Date());
            this.elements.refreshTime.textContent = `Atualizado às ${now}`;
        },

        flashLiveIndicator(message) {
            const indicator = this.elements.liveIndicator;
            if (!indicator) return;

            const originalText = indicator.dataset.defaultText ?? indicator.textContent.trim();
            indicator.dataset.defaultText = originalText;
            indicator.textContent = message;
            indicator.classList.add('is-updated');

            window.setTimeout(() => {
                indicator.textContent = originalText;
                indicator.classList.remove('is-updated');
            }, 2_000);
        },

        initializeTooltips() {
            document.querySelectorAll('[data-tooltip]').forEach((element) => {
                element.addEventListener('mouseenter', () => this.showTooltip(element));
                element.addEventListener('focus', () => this.showTooltip(element));
                element.addEventListener('mouseleave', () => this.hideTooltip(element));
                element.addEventListener('blur', () => this.hideTooltip(element));
            });
        },

        showTooltip(element) {
            const text = element.dataset.tooltip;
            if (!text || element.querySelector('.dashboard-tooltip')) return;

            const tooltip = document.createElement('span');
            tooltip.className = 'dashboard-tooltip';
            tooltip.role = 'tooltip';
            tooltip.textContent = text;
            element.appendChild(tooltip);
        },

        hideTooltip(element) {
            element.querySelector('.dashboard-tooltip')?.remove();
        },

        notify(message, type = 'info') {
            const container = this.elements.notifications ?? this.createNotificationContainer();
            const notification = document.createElement('div');
            notification.className = `dashboard-notification dashboard-notification--${type}`;
            notification.setAttribute('role', 'status');
            notification.textContent = message;
            container.appendChild(notification);

            window.setTimeout(() => {
                notification.classList.add('is-leaving');
                notification.addEventListener('transitionend', () => notification.remove(), { once: true });
            }, this.config.notificationDuration);
        },

        createNotificationContainer() {
            const container = document.createElement('div');
            container.className = 'dashboard-notifications';
            container.dataset.dashboardNotifications = '';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
            this.elements.notifications = container;
            return container;
        },

        debounce(callback, delay) {
            let timeout;
            return (...args) => {
                window.clearTimeout(timeout);
                timeout = window.setTimeout(() => callback(...args), delay);
            };
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Dashboard.init(), { once: true });
    } else {
        Dashboard.init();
    }

    window.WazeBRDashboard = Dashboard;
})();
