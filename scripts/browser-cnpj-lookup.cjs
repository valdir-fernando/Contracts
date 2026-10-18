// Synthetic browser fixture: every API response is mocked; no records are saved.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
    const browser = await chromium.launch({ headless: true, executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE });
    const company = { status: 'found', source: 'BrasilAPI — dados públicos do CNPJ', cnpj: '11222333000181',
        registration_status: 'ATIVA', supplier: { name: 'EMPRESA CONSULTADA', Nome_Fantasia: 'FANTASIA',
            Endereco: 'Rua consultada', Fone_Fixo: '6233334444', Email_Fornecedor: 'empresa@example.com' },
        fund: { name: 'FUNDO CONSULTADO', End_Fundo: 'Rua consultada', Telefone: '6233334444' } };
    try {
        for (const kind of ['supplier', 'fund']) {
            for (const existing of [false, true]) {
                const page = await browser.newPage({ viewport: { width: 390, height: 850 } });
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('**/__cnpj/*.js', route => {
                    const name = new URL(route.request().url()).pathname.split('/').pop();
                    assert(/^[a-z-]+\.js$/.test(name));
                    return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join('resources/js', name), 'utf8') });
                });
                await page.route('**/cnpj/lookup', route => {
                    assert.equal(route.request().method(), 'POST');
                    assert.equal(route.request().headers()['x-csrf-token'], 'csrf-test');
                    return route.fulfill({ json: company });
                });
                await page.route('**/suppliers/registration-lookup', route => route.fulfill({ json: {
                    found: existing, values: existing ? { name: 'EMPRESA DO BANCO', Endereco: 'Rua do banco' } : null,
                } }));
                await page.route('**/funds/lookup', route => route.fulfill({ json: { found: false } }));
                const names = kind === 'supplier' ? ['Nome_Fantasia', 'Endereco', 'Fone_Fixo', 'Email_Fornecedor'] : ['End_Fundo', 'Telefone'];
                await page.route('**/cnpj-fixture', route => route.fulfill({ contentType: 'text/html', body: `
                    <meta charset="utf-8"><form><input name="_token" type="hidden" value="csrf-test">
                    <input name="document" data-fund-cnpj><input name="name">
                    <fieldset class="supplier-registration-group">${names.map(name => `<input name="${name}">`).join('')}</fieldset>
                    <p data-registration-lookup-status data-fund-lookup-status></p><button type="button">Próximo</button></form>
                    <script type="module">
                    import { initializeSupplierRegistration } from '/__cnpj/supplier-registration.js';
                    import { initializeFundLookup } from '/__cnpj/fund-registration.js';
                    const form = document.querySelector('form');
                    form.dataset.cnpjLookupUrl = '/cnpj/lookup';
                    if ('${kind}' === 'supplier') {
                        form.dataset.registrationLookupUrl = '/suppliers/registration-lookup';
                        initializeSupplierRegistration(form);
                    } else {
                        form.dataset.fundLookupUrl = '/funds/lookup';
                        form.dataset.fundOriginalCnpj = '${existing ? '11222333000181' : ''}';
                        initializeFundLookup(form);
                    }
                    window.ready = true;
                    </script>` }));
                await page.goto(base + '/cnpj-fixture');
                await page.waitForFunction(() => window.ready);
                if (existing && kind === 'fund') {
                    await page.locator('[name=name]').fill('FUNDO DO BANCO');
                    await page.locator('[name=name]').evaluate(field => { field.readOnly = true; });
                    await page.locator('[name=End_Fundo]').fill('Rua do banco');
                }
                const document = page.locator('[name=document]');
                await document.fill('11222333000181');
                await page.getByRole('button', { name: 'Próximo' }).click();
                if (existing) {
                    const dialog = page.getByRole('dialog', { name: 'Conferência do CNPJ' });
                    await dialog.waitFor();
                    assert((await dialog.textContent()).includes('Rua do banco'));
                    assert((await dialog.textContent()).includes('Rua consultada'));
                    await dialog.getByRole('button', { name: 'Manter cadastro' }).click();
                    assert.equal(await page.locator(`[name=${kind === 'fund' ? 'End_Fundo' : 'Endereco'}]`).inputValue(), 'Rua do banco');
                    await document.focus();
                    await page.getByRole('button', { name: 'Próximo' }).click();
                    await dialog.waitFor();
                    await dialog.getByRole('button', { name: 'Usar dados consultados' }).click();
                    assert.equal(await page.locator(`[name=${kind === 'fund' ? 'End_Fundo' : 'Endereco'}]`).inputValue(), 'Rua consultada');
                    assert.equal(await page.locator('[name=name]').inputValue(), kind === 'fund' ? 'FUNDO DO BANCO' : 'EMPRESA CONSULTADA');
                } else {
                    await page.waitForFunction(() => document.querySelector('[name=name]').value.includes('CONSULTAD'));
                    assert.equal(await page.locator(`[name=${kind === 'fund' ? 'Telefone' : 'Fone_Fixo'}]`).inputValue(), '(62) 3333-4444');
                    const warning = page.getByRole('alertdialog', { name: 'Gestão Sistem informa:' });
                    await warning.waitFor();
                    assert((await warning.textContent()).includes('Dados públicos preenchidos. Confira antes de salvar.'));
                    await warning.getByRole('button', { name: 'OK', exact: true }).click();
                    await document.fill('04252011000110');
                    assert.equal(await page.locator('[name=name]').inputValue(), '');
                }
                assert.deepEqual(errors, []);
                console.log(`PASS ${kind}: ${existing ? 'comparison, keep, apply and protected identity' : 'autofill, masks and document change'}`);
                await page.close();
            }
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
