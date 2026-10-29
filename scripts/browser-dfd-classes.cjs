// Browser fixtures use synthetic classes and do not read or mutate the database.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
    const root = path.join(__dirname, '..');
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const modal = fs.readFileSync(path.join(root, 'resources/views/components/dfd-class-dialog.blade.php'), 'utf8')
        .replaceAll("{{ route('dfd-compra.classes') }}", base + '/dfd-compra/classes')
        .replaceAll("{{ route('dfd-compra.index') }}", base + '/dfd-compra');
    const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="${base}/build/${manifest['resources/css/app.css'].file}"></head><body>
        <a href="/dfd-compra" data-dfd-class-open>DFD Compra</a><label>Classe / Categoria<input readonly data-dfd-class-panel value=""></label>${modal}
        <script type="module" src="${base}/build/${manifest['resources/js/app.js'].file}"></script></body></html>`;
    const browser = await chromium.launch({ headless: true, ...(process.env.CONTRACTS_BROWSER_EXECUTABLE ? { executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const classes = ['ALIMENTOS PARA ANIMAIS', 'APARELHOS E EQUIPAMENTOS DE COMUNICAÇÃO', '<script>Texto literal</script>', ...Array.from({ length: 60 }, (_, i) => `CLASSE ${i + 1}`)];
        let fail = false;
        let delay = 0;
        await page.route('**/dfd-modal-fixture', (route) => route.fulfill({ contentType: 'text/html', body: html }));
        await page.route('**/dfd-compra?*', (route) => {
            const value = new URL(route.request().url()).searchParams.get('classe') || '';
            const escaped = value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
            return route.fulfill({ contentType: 'text/html', body: html.replace('data-dfd-class-panel value=""', `data-dfd-class-panel value="${escaped}"`) });
        });
        await page.route('**/dfd-compra/classes', async (route) => {
            if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
            await route.fulfill({ status: fail ? 500 : 200, contentType: 'application/json', body: JSON.stringify({ classes }) }).catch(() => {});
        });
        const dialog = page.locator('[data-dfd-class-dialog]');
        const open = async () => {
            await page.locator('[data-dfd-class-open]').click();
            await dialog.waitFor({ state: 'visible' });
        };
        await page.goto(base + '/dfd-modal-fixture');
        await page.waitForFunction(() => performance.getEntriesByType('resource').some((entry) => entry.name.includes('/build/assets/app-') && entry.name.endsWith('.js')));
        for (const width of [1280, 500]) {
            await page.goto(base + '/dfd-modal-fixture');
            await page.setViewportSize({ width, height: 900 });
            await open();
            await page.waitForFunction(() => document.querySelectorAll('.dfd-class-row').length === 63);
            assert(page.url().endsWith('/dfd-modal-fixture'));
            assert.equal(await page.locator('[data-dfd-class-confirm]').count(), 0);
            assert.equal(await page.locator('#dfd-class-title').textContent(), 'DFD - Documento de Formalização de Demanda');
            assert.equal(await page.locator('[data-dfd-class-close].modal-close svg').count(), 1);
            assert.equal(await page.locator('[data-dfd-class-list] script').count(), 0);
            assert.equal(await page.locator('[data-dfd-class-close]').evaluate((button) => getComputedStyle(button).backgroundColor), 'rgb(255, 215, 0)');
            const bounds = await dialog.boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= width);
            assert(await page.locator('[data-dfd-class-list]').evaluate((list) => list.scrollHeight > list.clientHeight));
            await page.locator('[data-dfd-class-filter]').fill('comunicacao');
            assert.equal(await page.locator('.dfd-class-row').count(), 1);
            await page.locator('.dfd-class-row').click();
            assert(page.url().endsWith('/dfd-modal-fixture'));
            assert(await dialog.isVisible());
            assert.equal(await page.locator('[data-dfd-class-selected]').textContent(), classes[1]);
            assert.equal(await page.locator('.dfd-class-row').getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('.dfd-class-row').evaluate((row) => getComputedStyle(row).backgroundColor), 'rgb(8, 122, 193)');
            fs.mkdirSync(path.join(root, 'build/dfd-modal'), { recursive: true });
            await page.screenshot({ path: path.join(root, `build/dfd-modal/${width}.png`) });
            await page.locator('[data-dfd-class-close]').click();
            await page.waitForURL('**/dfd-compra?*');
            assert.equal(new URL(page.url()).searchParams.get('classe'), classes[1]);
            assert.equal(await page.locator('[data-dfd-class-panel]').inputValue(), classes[1]);
            assert.equal(await page.locator('[data-dfd-class-panel]').getAttribute('readonly'), '');
            // Closing a reopened chooser without a new selection preserves the panel.
            const destination = page.url();
            await open();
            await page.waitForFunction(() => document.querySelectorAll('.dfd-class-row').length === 63);
            await page.locator('[data-dfd-class-close]').click();
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(page.url(), destination);
            assert.equal(await page.locator('[data-dfd-class-panel]').inputValue(), classes[1]);
            assert(await page.locator('[data-dfd-class-open]').evaluate((link) => document.activeElement === link));
        }
        await page.goto(base + '/dfd-modal-fixture');
        await open();
        await page.waitForFunction(() => document.querySelectorAll('.dfd-class-row').length === 63);
        await page.locator('[data-dfd-class-filter]').fill('inexistente');
        assert.equal(await page.locator('.dfd-class-row').count(), 0);
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
        fail = true;
        await open();
        await page.locator('[data-dfd-class-retry]').waitFor({ state: 'visible' });
        fail = false;
        await page.locator('[data-dfd-class-retry]').click();
        await page.waitForFunction(() => document.querySelectorAll('.dfd-class-row').length === 63);
        await page.keyboard.press('Escape');
        delay = 250;
        await open();
        await page.keyboard.press('Escape');
        await open();
        await page.waitForFunction(() => document.querySelectorAll('.dfd-class-row').length === 63);
        await page.locator('[data-dfd-class-filter]').fill('comunicacao');
        await page.locator('[data-dfd-class-filter]').press('ArrowDown');
        assert(await page.locator('.dfd-class-row').evaluate((row) => document.activeElement === row));
        await page.locator('.dfd-class-row').press('Enter');
        assert.equal(await page.locator('[data-dfd-class-selected]').textContent(), classes[1]);
        assert(page.url().endsWith('/dfd-modal-fixture'));
        await page.keyboard.press('Escape');
        await page.waitForURL('**/dfd-compra?*');
        assert.equal(await page.locator('[data-dfd-class-panel]').inputValue(), classes[1]);
        assert.deepEqual(errors, []);
        console.log('DFD modal: desktop/mobile, title, standard X, selection displayed in panel on close, filter, keyboard, retry, reopening and safe text passed.');
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exit(1); });
