const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
    for (const name of ['E2E_BASE_URL', 'E2E_USERNAME', 'E2E_PASSWORD']) {
        assert.ok(process.env[name], `Missing ${name}`);
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
        await page.waitForFunction(() => {
            const tile = document.querySelector('[data-alerts-map] .leaflet-tile');
            return tile && getComputedStyle(tile).position === 'absolute';
        });
        const size = await page.locator('[data-alerts-map]').boundingBox();
        assert.ok(size && size.width > 0 && size.height > 0, 'Map must have dimensions');

        await page.route('**/alerts/api/list*', route => {
            assert.equal(new URL(route.request().url()).searchParams.get('query'), 'E2E_FILTER');
            return route.fulfill({ json: { ok: true, rows: [{
                id: 987654, uuid: 'E2E_FILTER', type: 'HAZARD', subtype: null,
                city: 'E2E_FILTER', street: 'E2E_FILTER', confidence: 5,
                isActive: true, when: '2026-01-01T12:00:00Z',
            }] } });
        });
        await page.route('**/alerts/api/live*', route => route.fulfill({ json: {
            ok: true, stats: { total: 1, active: 1 }, map: { live: [] },
            charts: { by_type: [], by_hour: [], by_subtype: [] },
        } }));
        await page.route('**/alerts/api/history*', route => route.fulfill({ json: {
            ok: true, clusters: [], charts: { by_day: [], by_city: [] },
        } }));
        await page.locator('[data-filter-form] [name="query"]').fill('E2E_FILTER');
        await page.locator('[data-filter-form] [type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('[data-table-body]')?.textContent.includes('987654'));
        assert.equal(await page.locator('[data-table-body] tr[data-search-text]').count(), 1);
        const links = await page.locator('[data-export-link]').evaluateAll(nodes => nodes.map(n => n.href));
        assert.equal(links.length, 2);
        for (const link of links) assert.equal(new URL(link).searchParams.get('query'), 'E2E_FILTER');
        console.log('PASS: map positioning, filtered table replacement and CSV links');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
