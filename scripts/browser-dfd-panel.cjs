// Uses HTML rendered by DfdPanelTest with synthetic data and an in-memory database.
// All writes and DFD lookups are intercepted; no installed records are read or changed.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const base = 'http://127.0.0.1:8000';
    const root = path.join(__dirname, '..');
    const directory = path.join(root, 'build/dfd-panel');
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const login = await (await fetch(base + '/login')).text();
    const livewirePrefix = login.match(/\/livewire-[a-f0-9]+\/livewire\.js/)[0].replace('/livewire.js', '');
    const livewireScript = fs.readFileSync(path.join(directory, 'new.html'), 'utf8').match(/<script src="[^"]*livewire[^>]*><\/script>/)[0];
    const html = (name) => fs.readFileSync(path.join(directory, `${name}.html`), 'utf8')
        .replace(/<script src="[^"]*livewire[^>]*><\/script>/g, '')
        .replace('</body>', livewireScript + '</body>')
        .replaceAll(/\/livewire-[a-f0-9]+/g, livewirePrefix)
        .replace('</head>', `<link rel="stylesheet" href="${base}/build/${manifest['resources/css/app.css'].file}"><script type="module" src="${base}/build/${manifest['resources/js/app.js'].file}"></script></head>`);
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(15000);
        const errors = [];
        let submitted;
        const queries = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (request.method() !== 'GET') {
                submitted = new URLSearchParams(request.postData());
                return route.fulfill({ contentType: 'text/html', body: '<p>Envio interceptado para validação.</p>' });
            }
            if (url.pathname.startsWith('/dfd-preview/')) return route.fulfill({ contentType: 'text/html', body: html(url.pathname.split('/').at(-1)) });
            if (url.pathname === '/dfd-compra/classes') return route.fulfill({ json: { classes: ['Material'] } });
            if (url.pathname === '/dfd-compra/search') {
                queries.push(url.searchParams.get('q'));
                return route.fulfill({ json: { records: url.searchParams.get('q') === '999/26' ? [] : [{ number: '001/26', date: '2026-10-29', category: '<script>Material</script>', process: '100/2026', url: base + '/dfd-compra/1' }] } });
            }
            if (url.pathname.startsWith('/dfd-compra')) return route.fulfill({ contentType: 'text/html', body: html('saved') });
            if (url.origin !== base || (!url.pathname.startsWith('/build/') && !url.pathname.includes('/livewire'))) return route.abort();
            return route.continue();
        });
        const open = async (name, width) => {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(`${base}/dfd-preview/${name}`);
            try {
                await page.waitForFunction(() => document.querySelector('[data-dfd-editor]')._x_dataStack, null, { timeout: 15000 });
            } catch (error) {
                console.error(await page.evaluate(() => ({ alpine: !!window.Alpine, scripts: [...document.scripts].map((s) => s.src) })), errors);
                throw error;
            }
            await page.waitForFunction(() => getComputedStyle(document.querySelector('.dfd-section-grid') || document.querySelector('.dfd-identification')).display === 'grid');
        };
        for (const width of [1440, 500]) {
            await open('new', width);
            assert.equal(await page.locator('#Necessidade').isEnabled(), true);
            assert.equal(await page.getByRole('button', { name: 'Produtos', exact: true }).isDisabled(), true);
            assert.equal(await page.locator('[data-dfd-pdf]').isDisabled(), true);
            const pdfAlignment = await page.locator('.dfd-process-control').evaluate((control) => {
                const input = control.querySelector('input').getBoundingClientRect();
                const icon = control.querySelector('[data-dfd-pdf]').getBoundingClientRect();
                return { right: Math.abs(icon.right - control.getBoundingClientRect().right), center: Math.abs((icon.top + icon.bottom - input.top - input.bottom) / 2) };
            });
            assert(pdfAlignment.right < 1 && pdfAlignment.center < 1);
            const gap = await page.evaluate(() => document.querySelector('.dfd-page-heading').getBoundingClientRect().top - document.querySelector('.workspace-top').getBoundingClientRect().bottom);
            assert.equal(gap, 8);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            const navigationTop = await page.locator('.dfd-tabs').evaluate((el) => el.getBoundingClientRect().top);
            const content = page.locator('.dfd-editor > .editor-panels');
            assert(await content.evaluate((el) => el.scrollHeight > el.clientHeight));
            const bounds = await content.boundingBox();
            await page.mouse.move(bounds.x + bounds.width - 20, bounds.y + 40);
            await page.mouse.wheel(0, 400);
            await page.waitForFunction(() => document.querySelector('.dfd-editor > .editor-panels').scrollTop > 0);
            assert.equal(await page.locator('.dfd-tabs').evaluate((el) => el.getBoundingClientRect().top), navigationTop);
            assert.equal(await page.evaluate(() => window.scrollY), 0);
            assert(await page.evaluate(() => document.documentElement.scrollHeight <= innerHeight));
            await content.evaluate((el) => { el.scrollTop = 0; });
            await page.screenshot({ path: path.join(directory, `new-${width}.png`), fullPage: true });
            assert.equal(await page.locator('#dfd-number').isEditable(), true);
            const previousQueries = queries.length;
            await page.locator('[data-dfd-search-open]').click();
            await page.locator('[data-dfd-search-results] a').waitFor();
            assert.deepEqual(queries.slice(previousQueries), ['']);
            assert.equal(await page.locator('[data-dfd-search-results] script').count(), 0);
            assert.equal(await page.locator('[data-dfd-search-results]').textContent(), '001/2629/10/2026100/2026<script>Material</script>');
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => document.querySelector('[data-dfd-search-open]') === document.activeElement);
            assert.equal(await page.locator('[data-dfd-search-open]').evaluate((el) => el === document.activeElement), true);
            await page.locator('#dfd-number').fill('999/26');
            await page.locator('#dfd-number').press('Tab');
            await page.waitForFunction(() => document.querySelector('[data-dfd-search-status]').textContent === 'Nenhuma DFD encontrada.');
            assert.equal(queries.at(-1), '999/26');
            await page.keyboard.press('Escape');
            await page.locator('#dfd-number').fill('');
            const beforeEmptyBlur = queries.length;
            await page.locator('#dfd-number').press('Tab');
            assert.equal(await page.locator('[data-dfd-search-dialog]').isVisible(), false);
            assert.equal(queries.length, beforeEmptyBlur);
            for (const trigger of ['click', 'blur']) {
                await open('new', width);
                await page.locator('[data-dfd-class-panel]').evaluate((input) => { input.value = 'Outra classe'; });
                await page.locator('#dfd-number').fill('001/26');
                const beforeLookup = queries.length;
                if (trigger === 'click') await page.locator('[data-dfd-search-open]').click();
                else await page.locator('#dfd-number').press('Tab');
                await page.waitForURL(base + '/dfd-compra/1');
                await page.waitForFunction(() => document.querySelector('[data-dfd-editor]')._x_dataStack);
                assert.deepEqual(queries.slice(beforeLookup), ['001/26']);
                assert.equal(await page.locator('[data-dfd-class-panel]').inputValue(), 'Material');
                for (const [field, value] of Object.entries({ Processo_DFD: '100/2026', Necessidade: 'Reposição de material', Desricao: 'Aquisição de papel', Prioridade: 'Alta', Vigencia: '12 meses', Local: 'Almoxarifado', End_Local: 'Rua exemplo, 10', Vinculacao: 'Sem dependência', Observacoes: 'Entrega em horário comercial', Responsavel: 'Pessoa responsável', Decreto: '123/2026', Cargo: 'Diretor', email: 'exemplo@example.test' })) {
                    assert.equal(await page.locator(`[name="${field}"]`).inputValue(), value);
                }
                assert.equal(await page.locator('[name="Data_DFD"]').inputValue(), '29/10/2026');
                assert.equal(await page.locator('[name="Data_Conclusao"]').inputValue(), '10/12/2026');
            }
            await open('saved', width);
            assert.equal(await page.locator('[data-dfd-pdf]').getAttribute('href'), base + '/dfd-compra/1/pdf');
            assert.equal(await page.locator('[data-dfd-pdf]').getAttribute('target'), '_blank');
            await page.screenshot({ path: path.join(directory, `saved-${width}.png`), fullPage: true });
            assert.equal(await page.locator('#Necessidade').isDisabled(), true);
            await page.getByRole('button', { name: 'Editar', exact: true }).click();
            assert.equal(await page.locator('#Necessidade').isEnabled(), true);
            await page.getByRole('button', { name: 'Excluir', exact: true }).click();
            assert.equal(await page.locator('[aria-labelledby="dfd-delete-title"]').isVisible(), true);
            await page.keyboard.press('Escape');
            await open('budget', width);
            assert.equal(await page.locator('[data-dfd-budget-field="Orgao"]').inputValue(), '02');
            assert.equal(await page.locator('[data-dfd-budget-field="Dotacao"]').inputValue(), '01.02.03');
            await page.getByRole('button', { name: 'Editar', exact: true }).click();
            await page.locator('#dfd-budget').selectOption('');
            assert.equal(await page.locator('[data-dfd-budget-field="Orgao"]').inputValue(), '');
            await page.getByRole('button', { name: 'Salvar', exact: true }).click();
            await page.waitForURL((url) => url.pathname.startsWith('/dfd-compra/'));
            assert.equal(submitted.get('budget_id'), '');
            assert.equal(submitted.get('_method'), 'PUT');
            assert.equal(submitted.get('Data_DFD'), '2026-10-29');
            await open('products', width);
            assert.equal(await page.locator('[name="quantity"]').count(), 1);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.screenshot({ path: path.join(directory, `products-${width}.png`), fullPage: true });
        }
        assert.deepEqual(errors, []);
        console.log('DFD panel: desktop/mobile layout, 8px heading gap, search, edit/delete, budget and intercepted submit passed.');
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
