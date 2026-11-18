/**
 * Ponte entre jam.js e jam-status.js.
 *
 * Observa as respostas JSON de /jams/api/data e guarda cada jam em window.__jamStatusRows
 * (id → jam), que o jam-status.js usa para desenhar os selos de status.
 *
 * Script CLÁSSICO e externo: precisa executar antes dos módulos jam.js/jam-status.js e não pode
 * ser inline (o CSP do projeto não permite 'unsafe-inline' em script-src).
 */
(function () {
    'use strict';

    if (window.__jamFetchBridgeInstalled) { return; }
    window.__jamFetchBridgeInstalled = true;

    window.__jamStatusRows = window.__jamStatusRows || new Map();

    var originalFetch = window.fetch;
    if (typeof originalFetch !== 'function') { return; }

    window.fetch = async function () {
        var response = await originalFetch.apply(this, arguments);

        try {
            var type = response.headers.get('content-type') || '';
            if (type.indexOf('application/json') !== -1) {
                var json = await response.clone().json();
                var data = json && json.data ? json.data : null;
                if (data) {
                    ['blockedActive', 'blockedStale', 'topJams'].forEach(function (key) {
                        (data[key] || []).forEach(function (jam) {
                            if (jam && jam.id != null) {
                                window.__jamStatusRows.set(String(jam.id), jam);
                            }
                        });
                    });
                }
            }
        } catch (error) {
            // A ponte nunca pode quebrar a requisição original.
        }

        return response;
    };
})();
