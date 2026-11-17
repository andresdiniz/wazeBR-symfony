/* Preferências de notificação: sincroniza "todos" ↔ subtipos, contadores e seções desligadas. */
(function () {
    'use strict';

    var page = document.querySelector('[data-notif-page]');
    if (!page) { return; }

    var master = page.querySelector('[data-notif-master]');
    var sections = page.querySelector('[data-notif-sections]');

    function subtypesOf(type) {
        return Array.prototype.slice.call(type.querySelectorAll('[data-notif-subtype]'));
    }

    function refreshType(type) {
        var all = type.querySelector('[data-notif-type-all]');
        var subs = subtypesOf(type);
        var counter = type.querySelector('[data-notif-count]');

        if (subs.length === 0) { return; }

        var checked = subs.filter(function (el) { return el.checked; }).length;
        all.checked = checked === subs.length;
        all.indeterminate = checked > 0 && checked < subs.length;

        if (counter) {
            counter.textContent = checked === subs.length
                ? 'todos'
                : (checked === 0 ? 'nenhum' : checked + ' de ' + subs.length);
        }
    }

    function setExpanded(button, panel, open) {
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;
    }

    function refreshSwitches() {
        if (sections && master) { sections.classList.toggle('is-off', !master.checked); }
        Array.prototype.forEach.call(page.querySelectorAll('[data-notif-section]'), function (section) {
            var toggle = section.querySelector('[data-notif-section-toggle]');
            section.classList.toggle('is-off', !toggle.checked);
        });
    }

    Array.prototype.forEach.call(page.querySelectorAll('[data-notif-type]'), function (type) {
        var all = type.querySelector('[data-notif-type-all]');

        all.addEventListener('change', function () {
            subtypesOf(type).forEach(function (el) { el.checked = all.checked; });
            refreshType(type);
        });
        subtypesOf(type).forEach(function (el) {
            el.addEventListener('change', function () { refreshType(type); });
        });

        var expand = type.querySelector('[data-notif-expand]');
        var panel = type.querySelector('[data-notif-subtypes]');
        if (expand && panel) {
            // Começa recolhido (sem JS fica tudo visível); abre sozinho quando há escolha parcial.
            var partial = all.indeterminate || (!all.checked && subtypesOf(type).some(function (el) { return el.checked; }));
            setExpanded(expand, panel, partial);
            expand.addEventListener('click', function () {
                setExpanded(expand, panel, expand.getAttribute('aria-expanded') !== 'true');
            });
        }

        refreshType(type);
    });

    Array.prototype.forEach.call(page.querySelectorAll('[data-notif-bulk]'), function (button) {
        button.addEventListener('click', function () {
            var section = button.closest('[data-notif-section]');
            var value = button.getAttribute('data-notif-bulk') === 'all';
            Array.prototype.forEach.call(section.querySelectorAll('input[type="checkbox"][name^="rules"]'), function (el) {
                el.checked = value;
                el.indeterminate = false;
            });
            Array.prototype.forEach.call(section.querySelectorAll('[data-notif-type]'), refreshType);
        });
    });

    if (master) { master.addEventListener('change', refreshSwitches); }
    Array.prototype.forEach.call(page.querySelectorAll('[data-notif-section-toggle]'), function (el) {
        el.addEventListener('change', refreshSwitches);
    });
    refreshSwitches();
})();
