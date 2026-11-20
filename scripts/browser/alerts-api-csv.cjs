const { chromium } = require('playwright');
const assert = require('node:assert/strict');

function parseCsv(input) {
    const rows = [];
    let row = [], field = '', quoted = false;
    const text = input.replace(/^\uFEFF/, '');
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (ch === '"') {
            if (quoted && text[i + 1] === '"') { field += '"'; i++; }
            else quoted = !quoted;
        } else if (!quoted && ch === ';') {
            row.push(field); field = '';
        } else if (!quoted && (ch === '\n' || ch === '\r')) {
            if (ch === '\r' && text[i + 1] === '\n') i++;
            row.push(field); rows.push(row); row = []; field = '';
        } else field += ch;
    }
    assert.equal(quoted, false, 'Unterminated CSV field');
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows;
}

(async () => {
    assert.deepEqual(parseCsv('\uFEFFa;b\r\n"x;y";"a""b"\r\n'), [['a', 'b'], ['x;y', 'a"b']]);
    assert.deepEqual(parseCsv('a;b\n"x\ny";z'), [['a', 'b'], ['x\ny', 'z']]);
    for (const key of ['E2E_BASE_URL', 'E2E_USERNAME', 'E2E_PASSWORD']) assert.ok(process.env[key], `Missing ${key}`);
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
        const getJson = async path => {
            const response = await context.request.get(path);
            assert.equal(response.status(), 200, 'API status');
            assert.match(response.headers()['content-type'] || '', /application\/json/);
            const body = await response.json();
            assert.equal(body.ok, true);
            return body;
        };
        const initial = await getJson('/alerts/api/list?period=all&limit=1');
        assert.ok(initial.rows.length > 0, 'Staging requires at least one stable alert');
        const sample = initial.rows[0];
        const minConfidence = Number(sample.confidence);
        assert.ok(Number.isInteger(minConfidence) && minConfidence >= 0 && minConfidence <= 5);
        const params = new URLSearchParams({
            period: 'all', type: sample.type, min_confidence: String(minConfidence),
        });
        const list = await getJson(`/alerts/api/list?${params}&limit=30&offset=0`);
        assert.ok(list.rows.length > 0, 'Filtered staging data must remain stable during test');
        for (const row of list.rows) {
            assert.equal(row.type, sample.type);
            assert.ok(Number(row.confidence) >= minConfidence);
        }
        const response = await context.request.get(`/alerts/export.csv?${params}`);
        assert.equal(response.status(), 200, 'CSV status');
        assert.match(response.headers()['content-type'] || '', /text\/csv/);
        const records = parseCsv(await response.text());
        assert.equal(records[0].length, 14, 'CSV header shape');
        assert.ok(records.length > 1, 'CSV must contain staging records');
        for (const row of records.slice(1)) {
            assert.equal(row.length, 14, 'CSV row shape');
            assert.equal(row[2], sample.type, 'CSV type filter');
            assert.ok(Number(row[6]) >= minConfidence, 'CSV confidence filter');
        }
        console.log('PASS: real list API and CSV respect type and confidence filters');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
