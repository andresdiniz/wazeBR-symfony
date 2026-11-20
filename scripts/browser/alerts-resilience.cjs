const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    for (const key of ['E2E_BASE_URL', 'E2E_USERNAME', 'E2E_PASSWORD']) {
        assert.ok(process.env[key], `Missing ${key}`);
    }
    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL });
        const page = await context.newPage();
        await page.goto('/login');
        await page.locator('[name="_username"]').fill(process.env.E2E_USERNAME);
        await page.locator('[name="_password"]').fill(process.env.E2E_PASSWORD);
        await Promise.all([
            page.waitForURL(url => !url.pathname.startsWith('/login')),
            page.locator('#loginSubmit').click(),
        ]);
        await page.goto('/alerts');
        await page.locator('[data-alerts-page]').waitFor();
        let navigations = 0;
        page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });
        let historyFailures = 0;
        await page.route('**/alerts/api/history*', route => {
            historyFailures++;
            return route.fulfill({ status: 500, json: { ok: false } });
        });
        await page.route('**/alerts/api/live*', route => route.fulfill({ json: {
            ok: true, stats: { total: 0, active: 0 }, map: { live: [] },
            charts: { by_type: [], by_hour: [], by_subtype: [] },
        } }));
        await page.route('**/alerts/api/list*', route => {
            const query = new URL(route.request().url()).searchParams.get('query');
            const rows = query === 'E2E_RECOVERY' ? [{
                id: 987653, uuid: 'E2E_RECOVERY', type: 'HAZARD', subtype: null,
                city: 'E2E_RECOVERY', street: 'E2E_RECOVERY', confidence: 5,
                isActive: true, when: '2026-01-01T12:00:00Z',
            }] : [];
            return route.fulfill({ json: { ok: true, rows } });
        });
        const apply = async query => {
            await page.locator('[data-filter-form] [name="query"]').fill(query);
            const responses = Promise.all(['list', 'live', 'history'].map(endpoint =>
                page.waitForResponse(response => {
                    const url = new URL(response.url());
                    return url.pathname === `/alerts/api/${endpoint}` && url.searchParams.get('query') === query;
                })
            ));
            await page.locator('[data-filter-form] [type="submit"]').click();
            await responses;
        };
        await apply('E2E_EMPTY');
        await page.locator('[data-table-body] .alerts-empty').waitFor();
        assert.equal(await page.locator('[data-table-body] tr[data-search-text]').count(), 0);
        await apply('E2E_RECOVERY');
        await page.waitForFunction(() => document.querySelector('[data-table-body]')?.textContent.includes('987653'));
        assert.equal(await page.locator('[data-table-body] .alerts-empty').count(), 0);
        assert.equal(await page.locator('[data-table-body] tr[data-search-text]').count(), 1);
        assert.ok(historyFailures >= 2, 'History failure must be exercised');
        assert.equal(navigations, 0, 'Filter failures must not navigate the main frame');
        for (const href of await page.locator('[data-export-link]').evaluateAll(nodes => nodes.map(node => node.href))) {
            assert.equal(new URL(href).searchParams.get('query'), 'E2E_RECOVERY');
        }
        console.log('PASS: empty results, recovery and table refresh despite history failure');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
