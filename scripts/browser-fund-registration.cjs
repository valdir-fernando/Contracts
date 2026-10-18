// Rendered synthetic fixture; form submissions are intercepted and never persisted.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
    const html = fs.readFileSync(process.env.CONTRACTS_FUND_FIXTURE, 'utf8');
    const browser = await chromium.launch({ headless: true, ...(process.env.CONTRACTS_BROWSER_EXECUTABLE ? { executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('**/fund-registration-fixture', (route) => route.fulfill({ contentType: 'text/html', body: html }));
        await page.route('**/funds/**', (route) => { assert.equal(route.request().method(), 'GET', 'Browser fixture must never send a mutation'); return route.abort(); });
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto(base + '/fund-registration-fixture');
            await page.waitForLoadState('networkidle');
            assert.equal(await page.locator('h1').textContent(), 'Cadastro de Fundo');
            assert.equal(await page.locator('#fund-document').inputValue(), '11.222.333/0001-81');
            assert.equal(await page.locator('#fund-Telefone').inputValue(), '(62) 3333-4444');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            const height = await page.locator('#fund-document').evaluate((input) => input.getBoundingClientRect().height);
            assert(Math.abs(height - (width > 600 ? 34.8 : 39.6)) < 1);
            const address = page.locator('#fund-address');
            const initial = (await address.boundingBox()).height;
            await address.fill('Rua extensa para verificar expansão automática do endereço do Fundo. '.repeat(12));
            assert((await address.boundingBox()).height > initial + 10);
            await address.fill('Rua curta');
            assert(Math.abs((await address.boundingBox()).height - height) < 1);
            await page.locator('#fund-name').fill('fundo de teste');
            assert.equal(await page.locator('#fund-name').inputValue(), 'FUNDO DE TESTE');
            await page.locator('#fund-document').fill('11222333000181');
            await page.locator('#fund-document').press('Enter');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'fund-name');
            await page.locator('#fund-document').fill('11111111111111');
            await page.locator('#fund-document').press('Enter');
            const warning = page.locator('[data-supplier-document-warning]');
            await warning.waitFor({ state: 'visible' });
            assert((await warning.textContent()).includes('CNPJ do Fundo inválido'));
            await warning.getByRole('button', { name: 'OK', exact: true }).click();
            await warning.waitFor({ state: 'hidden' });
            await page.locator('#fund-document').fill('11222333000181');
            await page.locator('#fund-document').press('Enter');
            await page.getByRole('button', { name: 'Excluir', exact: true }).click();
            const deletion = page.getByRole('dialog', { name: 'Excluir Fundo', exact: true });
            assert.deepEqual(errors, []);
            await deletion.waitFor({ state: 'visible' });
            await deletion.getByRole('button', { name: 'Cancelar', exact: true }).click();
            await deletion.waitFor({ state: 'hidden' });
            const online = page.getByRole('link', { name: 'Dados Online', exact: true });
            assert.equal(await online.getAttribute('target'), '_blank');
            assert((await online.getAttribute('href')).startsWith('https://www.gov.br/'));
            if (process.env.CONTRACTS_SCREENSHOT_DIR) await page.screenshot({ path: `${process.env.CONTRACTS_SCREENSHOT_DIR}/fund-${width}.png`, fullPage: true });
            console.log(`PASS ${width}px: layout, masks, CNPJ validation, auto height, Enter and delete/cancel dialog`);
        }
        if (process.env.CONTRACTS_FUND_NEW_FIXTURE) {
            const empty = fs.readFileSync(process.env.CONTRACTS_FUND_NEW_FIXTURE, 'utf8');
            await page.route('**/fund-new-fixture', (route) => route.fulfill({ contentType: 'text/html', body: empty }));
            await page.route('**/fund-existing-fixture', (route) => route.fulfill({ contentType: 'text/html', body: html }));
            await page.route('**/funds/lookup', (route) => {
                assert.equal(route.request().method(), 'POST');
                assert.deepEqual(route.request().postDataJSON(), { cnpj: '11222333000181' });
                assert(route.request().headers()['x-csrf-token']);
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ found: true, edit_url: base + '/fund-existing-fixture' }) });
            });
            await page.goto(base + '/fund-new-fixture'); await page.waitForLoadState('networkidle');
            assert.equal(await page.locator('#fund-name').inputValue(), '');
            await page.locator('#fund-document').fill('11222333000181');
            await page.locator('#fund-document').press('Enter');
            await page.waitForURL('**/fund-existing-fixture'); await page.waitForLoadState('networkidle');
            assert.equal(await page.locator('#fund-name').inputValue(), 'FUNDO MUNICIPAL DE EXEMPLO');
            assert.equal(await page.locator('#fund-address').inputValue(), 'Rua Exemplo, 100');
            assert.equal(await page.locator('#fund-Abreviatuda').inputValue(), 'FEX');
            assert.equal(await page.locator('#fund-Codigo').inputValue(), '002');
            assert.equal(await page.locator('#fund-Telefone').inputValue(), '(62) 3333-4444');
            assert.equal(await page.locator('#fund-Nome').inputValue(), 'Contato de exemplo');
            assert.equal(await page.locator('[data-fund-form] [name="_method"]').inputValue(), 'PUT');
            console.log('PASS: entering an existing CNPJ opens and fills the complete existing editor without creating a copy');
        }
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exit(1); });
