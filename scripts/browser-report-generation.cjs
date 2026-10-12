const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
    const manifest = JSON.parse(fs.readFileSync(process.env.CONTRACTS_MANIFEST || '/tmp/manifest.json', 'utf8'));
    const template = fs.readFileSync(process.env.CONTRACTS_CARD_TEMPLATE || '/tmp/report-generation.blade.php', 'utf8');
    const html = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="${base}/build/${manifest['resources/css/app.css'].file}"></head><body style="padding:24px"><main><a class="secondary-button" href="${base}/reports/export/csv" data-report-export>Exportar CSV</a>${template}</main><script type="module" src="${base}/build/${manifest['resources/js/app.js'].file}"></script></body></html>`;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let polls = 0;
    let downloads = 0;
    page.on('download', () => { downloads++; });
    await page.route('**/report-ui-fixture', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.route('**/reports/export/csv?background=1', route => route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ id: 1, status_url: base + '/reports/status/1' }) }));
    await page.route('**/reports/status/1', route => {
        polls++;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ id: 1, format: 'csv', status: polls < 3 ? 'processing' : 'ready', download_url: polls < 3 ? null : base + '/reports/download/1' }) });
    });
    await page.route('**/reports/download/1', route => route.fulfill({ contentType: 'text/csv', headers: { 'content-disposition': 'attachment; filename="relatorio.csv"' }, body: 'Contrato;Valor\n001;10,1001\n' }));
    await page.goto(base + '/report-ui-fixture');
    const autoDownloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Exportar CSV' }).click();
    await page.waitForSelector('[data-report-card][aria-busy="true"]');
    assert.equal(await page.locator('[data-report-spinner]').isVisible(), true);
    assert.equal(await page.locator('[data-report-download]').isVisible(), false);
    await page.waitForSelector('[data-report-card][data-state="ready"]', { timeout: 20000 });
    assert.equal((await autoDownloadPromise).suggestedFilename(), 'relatorio.csv');
    assert.equal(await page.locator('[data-report-spinner]').isVisible(), false);
    const count = polls;
    await page.waitForTimeout(5500);
    assert.equal(polls, count, 'Polling must stop when ready');
    assert.equal(downloads, 1, 'Automatic download must only run once');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Baixar relatório' }).click();
    assert.equal((await downloadPromise).suggestedFilename(), 'relatorio.csv');
    assert.equal(downloads, 2, 'The download button must still allow downloading again');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(errors, []);
    // Historical ready cards must not download again when opening the exports page.
    const readyCard = template.match(/<article[\s\S]*?<\/article>/)[0]
        .replace('data-report-card aria-busy="true"', `data-report-card data-state="ready" data-status-url="${base}/reports/status/1" aria-busy="false"`);
    await page.route('**/report-history-fixture', route => route.fulfill({ contentType: 'text/html', body: html.replace(template, readyCard) }));
    await page.goto(base + '/report-history-fixture');
    await page.waitForSelector('[data-report-download]:not([hidden])');
    await page.waitForTimeout(1000);
    assert.equal(downloads, 2, 'Historical ready cards must not trigger automatic downloads');
    assert.deepEqual(errors, []);
    console.log('Automatic download runs once on completion; manual download remains available; historical reports do not download again.');
    await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
