// Blade fixture plus simulated HTTP: never writes to the installed database.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const browser = await chromium.launch({ headless: true, ...(process.env.CONTRACTS_BROWSER_EXECUTABLE ? { executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const errors = [];
        let acceptBudget = true;
        let budgetConfirmations = 0;
        page.on('dialog', async (dialog) => {
            if (dialog.message().startsWith('Ficha ')) {
                budgetConfirmations++;
                assert.match(dialog.message(), /Fundo ou Secretaria diferente/);
                if (!acceptBudget) return dialog.dismiss();
            }
            return dialog.accept();
        });
        page.on('pageerror', (error) => errors.push(error.message));
        const fixture = fs.readFileSync(process.env.CONTRACTS_PRODUCTS_FIXTURE, 'utf8');
        let revision = 1;
        let items = [];
        let total = 'R$ 0,00';
        let remaining = '100.0000';
        const mutations = [];
        const product = { id: 1, item: '1', code: 'CAD-01', description: '<script>unsafe()</script> Cadeira', brand: 'Marca', unit: 'UN', unit_price: '10.0000', available: true };
        const commonProduct = { ...product, id: 2, item: '2', code: 'MERENDA-02' };
        await page.route('http://contracts.test/**', async (route) => {
            const req = route.request();
            if (req.url().endsWith('/fixture')) return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture });
            if (req.url().includes('/item-lookup?')) {
                const params = new URL(req.url()).searchParams;
                const item = params.get('item');
                const mode = params.get('process_catalog') === '1';
                if (item === '2' && !mode) return route.fulfill({ json: { status: 'supplier_mismatch' } });
                return route.fulfill({ json: ['1', '2'].includes(item) ? { status: 'found', product: { ...(item === '2' ? commonProduct : product), remaining } } : { status: 'not_found' } });
            }
            if (req.url().includes('/budget-lookup?')) {
                const params = new URL(req.url()).searchParams;
                const ficha = params.get('ficha');
                assert.ok(['18', '19'].includes(params.get('department_id')));
                if (ficha === 'DELAY') await new Promise((resolve) => setTimeout(resolve, 150));
                return route.fulfill({ json: ficha === '123' ? { status: 'found', description: 'Material de consumo', allocation: 'Dotação A',
                    fund: 'Município de Niquelândia', department: 'SEC MUNICIPAL DE URBANISMO', scope_matches: params.get('department_id') === '18',
                    ficha_values: [{ label: '123', total: '1234.5678' }] }
                    : { status: 'not_found', description: '', allocation: '' } }).catch(() => {});
            }
            if (req.method() === 'GET') {
                const params = new URL(req.url()).searchParams;
                const mode = params.get('process_catalog') === '1';
                const pageNumber = Number(params.get('page') || 1);
                return route.fulfill({ json: { contract_version: revision, total_label: total,
                    products: [{ ...(mode && pageNumber === 2 ? commonProduct : product), remaining }], items, page: pageNumber, last_page: mode ? 2 : 1,
                    budgets: [{ id: 1, number: '123', description: 'Material', allocation: 'Dotação A' }] } });
            }
            const data = req.postDataJSON();
            assert.equal(String(data.contract_version), String(revision));
            mutations.push({ method: req.method(), data });
            revision++;
            if (req.method() === 'DELETE') {
                items = [];
                total = 'R$ 0,00';
                remaining = '100.0000';
            } else {
                const quantity = Number(data.quantity);
                const sourceId = data.manual ? null : data.product_id ?? items[0]?.product_id;
                const source = data.manual ? { item: data.item, description: data.description, code: data.code, brand: data.brand, unit: data.unit, unit_price: '10.0000' } : sourceId === 2 ? commonProduct : product;
                items = [{ ...source, id: 1, product_id: sourceId, quantity: quantity.toFixed(4), total: (quantity * 10).toFixed(4), budget_id: null, Ficha: data.ficha || null, process_catalog: data.process_catalog }];
                total = `R$ ${quantity * 10},00`;
                remaining = (100 - quantity).toFixed(4);
            }
            return route.fulfill({ json: { contract_version: revision, total_label: total } });
        });
        await page.goto('http://contracts.test/fixture');
        await page.evaluate(() => {
            const select = document.createElement('select');
            select.id = 'department_id';
            select.add(new Option('Urbanismo', '18'));
            select.add(new Option('Governo', '19'));
            document.querySelector('form').prepend(select);
        });
        const manifest = JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'));
        const cssEntry = Object.values(manifest).find((entry) => entry.file?.endsWith('.css'));
        if (cssEntry) await page.addStyleTag({ path: `public/build/${cssEntry.file}` });
        const script = fs.readFileSync('resources/js/contract-products.js', 'utf8').replaceAll('export function ', 'function ');
        await page.addScriptTag({ content: script });
        assert.equal(await page.locator('[data-contract-products-dialog]').evaluate((dialog) => dialog.open), false);
        await page.locator('[data-contract-products-open]').click();
        assert.equal(await page.locator('[data-contract-products-dialog]').evaluate((dialog) => dialog.open), true);
        assert.equal(await page.locator('[data-product-budget]').evaluate((field) => document.activeElement === field), true);
        await page.locator('[data-contract-products-close]').click();
        assert.equal(await page.locator('[data-contract-products-dialog]').evaluate((dialog) => dialog.open), false);
        await page.locator('[data-contract-products-open]').click();
        await page.waitForFunction(() => document.querySelector('[data-contract-products]').getAttribute('aria-busy') === 'false');
        for (const width of [1280, 1600, 1024]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const columns = await page.evaluate(() => {
                const headers = document.querySelector('.product-contract-list table').tHead.rows[0].cells;
                return [...headers].map((header) => header.getBoundingClientRect().width);
            });
            [50, 54, null, 55, 94, 120, 100, 100, 80, 80].forEach((expected, index) => {
                if (expected === null) return;
                assert.ok(Math.abs(columns[index] - expected) < 1, JSON.stringify(columns));
            });
            assert.ok(columns[2] >= 265, JSON.stringify(columns));
            const alignment = await page.evaluate(() => {
                const table = document.querySelector('.product-contract-list table').getBoundingClientRect();
                const container = document.querySelector('.product-contract-list').getBoundingClientRect();
                const summary = document.querySelector('.product-budget-summary').getBoundingClientRect();
                return { table: table.right, summary: summary.right, fits: container.width >= 1000 };
            });
            if (alignment.fits) assert.ok(Math.abs(alignment.table - alignment.summary) < 2, JSON.stringify(alignment));
        }
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.locator('[data-product-item-search]').click();
        await page.waitForFunction(() => document.querySelector('[data-product-picker]').open && document.querySelector('[data-product-picker]').getAttribute('aria-busy') === 'false');
        assert.equal(await page.locator('[data-product-picker-next]').isDisabled(), true);
        assert.equal(await page.locator('[data-product-picker-rows] script').count(), 0);
        await page.locator('[data-product-picker-rows] button').click();
        assert.equal(await page.locator('[data-product-picker]').evaluate((dialog) => dialog.open), false);
        assert.equal(await page.locator('[data-product-field="code"]').inputValue(), 'CAD-01');
        await page.locator('[data-product-item-search]').click();
        await page.locator('[data-product-picker-cancel]').click();
        assert.equal(await page.locator('[data-product-field="code"]').inputValue(), 'CAD-01');
        await page.locator('[data-products-new]').click();
        await page.locator('[data-product-field="item"]').fill('999');
        await page.locator('[data-product-field="item"]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-item-warning]').open);
        assert.equal(await page.locator('[data-product-item-warning-message]').textContent(), 'Item não encontrado neste processo.');
        await page.locator('[data-product-item-warning-ok]').click();
        assert.equal(await page.locator('[data-product-item-warning]').evaluate((dialog) => dialog.open), false);
        assert.equal(await page.locator('[data-product-field="item"]').inputValue(), '');
        await page.locator('[data-product-field="item"]').fill('1');
        await page.locator('[data-product-field="item"]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-field="code"]').value === 'CAD-01');
        await page.locator('[data-product-field="item"]').fill('2');
        await page.locator('[data-product-field="item"]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-item-warning]').open);
        assert.equal(await page.locator('[data-product-item-warning-message]').textContent(), 'Este Item não corresponde a este Fornecedor');
        await page.locator('[data-product-item-warning-ok]').click();
        assert.equal(await page.locator('[data-product-field="code"]').inputValue(), '');
        assert.equal(await page.locator('[data-products-save]').isDisabled(), true);
        assert.equal(await page.locator('[data-product-field="item"]').inputValue(), '');
        await page.locator('[data-products-show]').check();
        await page.waitForFunction(() => document.querySelector('[data-contract-products]').getAttribute('aria-busy') === 'false');
        await page.locator('[data-product-item-search]').click();
        await page.locator('[data-product-picker-next]').click();
        await page.waitForFunction(() => document.querySelector('[data-product-picker-rows]').textContent.includes('MERENDA-02'));
        await page.locator('[data-product-picker-rows] tr').click();
        assert.equal(await page.locator('[data-product-picker]').evaluate((dialog) => dialog.open), false);
        await page.waitForFunction(() => document.querySelector('[data-product-field="code"]').value === 'MERENDA-02');
        assert.equal(await page.locator('[data-product-field="unit_price"]').evaluate((field) => field.readOnly), true);
        assert.match(await page.locator('[data-product-field="disp"]').locator('..').innerText(), /Disponível/);
        const layout = await page.evaluate(() => {
            const description = document.querySelector('[data-product-description]');
            const total = document.querySelector('[data-product-line-total]');
            const available = document.querySelector('[data-product-field="disp"]');
            return { right: description.getBoundingClientRect().right, totalRight: total.getBoundingClientRect().right,
                background: getComputedStyle(available).backgroundColor };
        });
        assert.ok(Math.abs(layout.right - layout.totalRight) < 2);
        assert.equal(layout.background, 'rgb(245, 163, 163)');
        await page.locator('[data-product-budget]').fill('123');
        assert.equal(await page.locator('[data-product-budget-description]').inputValue(), '');
        await page.locator('[data-product-quantity]').fill('40');
        await page.waitForFunction(() => document.querySelector('[data-product-budget-description]').value === 'Material de consumo');
        assert.match(await page.locator('[data-product-budget-totals]').innerText(), /123.*1234,5678/s);
        assert.equal(budgetConfirmations, 0);
        await page.locator('#department_id').selectOption('19');
        await page.waitForFunction(() => document.querySelector('[data-product-budget-description]').value === 'Material de consumo');
        assert.equal(budgetConfirmations, 1);
        assert.equal(await page.locator('[data-product-budget-description]').inputValue(), 'Material de consumo');
        await page.locator('#department_id').selectOption('18');
        await page.waitForFunction(() => document.querySelector('[data-product-budget-description]').value === 'Material de consumo');
        assert.equal(await page.locator('[data-product-budget-allocation]').inputValue(), 'Dotação A');
        assert.equal(await page.locator('[data-product-line-total]').inputValue(), '400,0000');
        await page.getByRole('button', { name: 'Salvar produto', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#contract-items-total').value === 'R$ 400,00' && document.querySelector('[data-product-feedback]').textContent.includes('Produto salvo'));
        assert.equal(await page.locator('[name=version]').inputValue(), '2');
        assert.equal(await page.locator('[data-contract-product-list] tr').count(), 1);
        assert.equal(await page.locator('[data-contract-product-list] script').count(), 0);
        assert.equal(await page.locator('[data-product-budget-totals] tr').count(), 0);
        await page.getByRole('button', { name: 'Editar', exact: true }).click();
        await page.locator('[data-product-quantity]').fill('30');
        await page.getByRole('button', { name: 'Salvar produto', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#valor_total').textContent === 'R$ 300,00' && document.querySelector('[data-product-feedback]').textContent.includes('Produto salvo'));
        await page.getByRole('button', { name: 'Editar', exact: true }).click();
        await page.getByRole('button', { name: 'Excluir / estornar', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('#contract-items-total').value === 'R$ 0,00' && document.querySelector('[name=version]').value === '4');
        assert.deepEqual(mutations.map((r) => r.method), ['POST', 'PUT', 'DELETE']);
        assert.equal(mutations[0].data.ficha, '123');
        assert.equal(mutations[0].data.process_catalog, true);
        assert.equal(mutations[0].data.product_id, 2);
        assert.equal(await page.locator('[data-product-document], [data-product-imports]').count(), 0);
        assert.equal(await page.locator('[data-contract-products] [name]').count(), 0);
        await page.locator('[data-product-budget]').fill('DELAY');
        await page.locator('[data-product-budget]').blur();
        await page.locator('[data-product-budget]').fill('123');
        await page.locator('[data-product-budget]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-budget-description]').value === 'Material de consumo');
        await page.locator('[data-product-budget]').fill('UNKNOWN');
        assert.equal(await page.locator('[data-product-budget-description]').inputValue(), '');
        assert.equal(await page.locator('[data-product-budget-allocation]').inputValue(), '');
        await page.locator('[data-product-budget]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-budget-status]').textContent.includes('Ficha não encontrada'));
        acceptBudget = false;
        await page.locator('#department_id').selectOption('19');
        await page.locator('[data-product-budget]').fill('123');
        await page.locator('[data-product-budget]').blur();
        await page.waitForFunction(() => document.querySelector('[data-product-budget-status]').textContent.includes('Consulta cancelada'));
        assert.equal(await page.locator('[data-product-budget-description]').inputValue(), '');
        assert.equal(await page.locator('[data-product-budget-allocation]').inputValue(), '');
        assert.ok(budgetConfirmations >= 2);
        await page.locator('[data-products-manual]').check();
        for (const field of ['code', 'brand', 'unit']) assert.equal(await page.locator(`[data-product-field="${field}"]`).evaluate((input) => input.readOnly), false);
        assert.equal(await page.locator('[data-product-description]').evaluate((input) => input.readOnly), false);
        await page.locator('[data-product-description]').fill('Locação de imóvel');
        assert.equal(await page.locator('[data-product-field="unit_price"]').evaluate((field) => field.readOnly), false);
        const description = page.locator('[data-product-description]');
        await description.fill(Array.from({ length: 10 }, (_, i) => `Linha ${i + 1}`).join('\n'));
        const dimensions = await description.evaluate((field) => ({ height: field.getBoundingClientRect().height,
            lineHeight: parseFloat(getComputedStyle(field).lineHeight), scroll: field.scrollHeight, client: field.clientHeight }));
        assert.ok(dimensions.height <= dimensions.lineHeight * 5 + 15);
        assert.ok(dimensions.height >= dimensions.lineHeight * 5);
        assert.ok(dimensions.scroll > dimensions.client);
        await description.fill('Locação de imóvel');
        await page.locator('[data-product-field="code"]').fill('IMOVEL');
        await page.locator('[data-product-field="unit"]').fill('MÊS');
        await page.locator('[data-product-field="unit_price"]').fill('10,0000');
        await page.locator('[data-products-save]').click();
        await page.waitForFunction(() => document.querySelector('[data-product-feedback]').textContent.includes('Produto salvo') && !document.querySelector('[data-products-manual]').checked);
        assert.equal(mutations.at(-1).data.manual, true);
        assert.equal(mutations.at(-1).data.product_id, undefined);
        assert.equal(await page.locator('[data-product-description]').evaluate((input) => input.readOnly), true);
        await page.getByRole('button', { name: 'Editar', exact: true }).click();
        assert.equal(await page.locator('[data-products-manual]').isChecked(), true);
        assert.equal(await page.locator('[data-product-description]').evaluate((input) => input.readOnly), false);
        await page.locator('[data-products-new]').click();
        assert.equal(await page.locator('[data-products-manual]').isChecked(), false);
        for (const field of ['code', 'brand', 'unit']) assert.equal(await page.locator(`[data-product-field="${field}"]`).evaluate((input) => input.readOnly), true);
        assert.equal(await page.locator('[data-product-description]').evaluate((input) => input.readOnly), true);
        assert.deepEqual(errors, []);
        if (process.env.CONTRACTS_PRODUCTS_SCREENSHOT) await page.screenshot({ path: process.env.CONTRACTS_PRODUCTS_SCREENSHOT, fullPage: true });
        console.log('Browser panel passed: catalog selection, exact preview, ficha, add/edit/reversal, totals, revisions and safe text; no AI controls.');
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
