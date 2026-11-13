// Synthetic browser regression: all pages and API responses are mocked; no database writes.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE });
    try {
        for (const delayed of [false, true]) {
            const page = await browser.newPage();
            const errors = [];
            const posts = [];
            page.on('pageerror', error => errors.push(error.message));
            let release;
            const gate = new Promise(resolve => { release = resolve; });
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/__supplier/')) {
                    const name = path.basename(url.pathname);
                    assert(/^[a-z-]+\.js$/.test(name));
                    return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join('resources/js', name), 'utf8') });
                }
                if (url.pathname === '/suppliers/registration-lookup') {
                    if (delayed) await gate;
                    return route.fulfill({ json: { found: false } });
                }
                if (url.pathname === '/catalogs') {
                    posts.push(route.request().postData());
                    return route.fulfill({ contentType: 'text/html', body: '<p>Envio recebido</p>' });
                }
                if (url.pathname !== '/fixture') return route.abort();
                return route.fulfill({ contentType: 'text/html', body: `
                    <meta charset="utf-8">
                    <dialog open>
                    <button type="submit" form="supplier">Salvar</button>
                    <form id="supplier" action="/catalogs" method="POST"
                        data-registration-lookup-url="/suppliers/registration-lookup" data-cnpj-lookup-url="/cnpj/lookup">
                        <input name="_token" type="hidden" value="synthetic-token">
                        <div class="field"><input name="document" required></div>
                        <div class="field"><input name="name" required></div>
                        <fieldset class="supplier-registration-group">
                            <div class="field"><input name="Endereco" required></div>
                        </fieldset>
                        <p data-registration-lookup-status></p>
                    </form></dialog>
                    <script type="module">
                        import { initializeSupplierRegistration } from '/__supplier/supplier-registration.js';
                        initializeSupplierRegistration(document.querySelector('form'));
                        window.ready = true;
                    </script>` });
            });
            await page.goto('http://contracts.test/fixture');
            await page.waitForFunction(() => window.ready);
            await page.locator('[name=document]').fill('52998224725');
            await page.locator('[name=name]').fill('FORNECEDOR FICTÍCIO');
            await page.locator('[name=Endereco]').fill('Rua de teste');
            if (!delayed) await page.waitForFunction(() => document.querySelector('[data-registration-lookup-status]').textContent.includes('não cadastrado'));
            await page.getByRole('button', { name: 'Salvar', exact: true }).click();
            if (delayed) {
                assert.equal(posts.length, 0);
                release();
            }
            await page.waitForURL('**/catalogs');
            assert.equal(posts.length, 1);
            assert.equal(new URLSearchParams(posts[0]).get('document'), '529.982.247-25');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: supplier save submits once after both settled and pending document lookups.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
