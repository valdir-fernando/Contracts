// Browser-only fixtures; no installed database is read or modified.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
    const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
    const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../public/build/manifest.json'), 'utf8'));
    const browser = await chromium.launch({ headless: true, ...(process.env.CONTRACTS_BROWSER_EXECUTABLE ? { executablePath: process.env.CONTRACTS_BROWSER_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const html = `<!doctype html><html><head><meta charset="utf-8"></head><body><main>
            <form id="login"><select id="fund"><option>Fundo</option></select><input id="username" required><input id="password" type="password" required><button type="button" id="eye">Mostrar senha</button><button id="login-save" type="submit">Entrar</button></form>
            <form id="entry"><input id="first"><button type="button" id="helper">Ajuda</button><input id="hidden" type="hidden"><input id="disabled" disabled><input id="readonly" readonly><fieldset disabled><input id="fieldset-disabled"></fieldset><div hidden><input id="hidden-parent"></div><input id="not-tabbable" tabindex="-1"><input style="visibility:hidden"><input id="second" type="number"><textarea rows="1" id="one-line"></textarea><textarea rows="3" id="multiline"></textarea><input id="last"><button type="button" id="delete">Excluir</button><button id="save">Salvar</button></form>
            <form id="other"><input id="other-field"><button>Outro formulário</button></form>
            <div data-enter-scope><input id="contract-number"><button id="search" type="button">Buscar</button></div>
            <form id="filter"><input type="search" id="filter-query"><button id="filter-button">Filtrar</button></form>
            <form id="dates"><input id="birth-date" type="date" name="birth" value="1971-12-30"><input id="decree-date" type="date" name="decree" value="2024-02-29"><input id="optional-date" type="date" name="optional"><button>Salvar datas</button></form>
            <form id="checks"><input type="checkbox" id="check"><input type="radio" id="radio"><button>Confirmar</button></form>
            <dialog id="modal"><form><input id="modal-first"><input id="modal-last"><button id="modal-save">Salvar na janela</button></form></dialog>
            <div data-enter-scope id="product"><input id="quantity"><input readonly value="0,0000"><button type="button" id="product-save">Salvar produto</button></div>
            <a id="link" href="#destination">Abrir</a><details><summary id="summary">Detalhes</summary>Conteúdo</details><div id="editable" contenteditable="true">Texto</div>
            <form id="inspector" data-inspector-form data-inspector-lookup-url="/inspectors/lookup"><input type="hidden" name="_token" value="csrf-test"><input name="CPF_Fiscal" id="cpf" data-inspector-cpf><p data-inspector-cpf-error hidden></p><p data-inspector-lookup-status hidden></p><input name="RG_Fiscal" id="rg"><button type="submit">Salvar fiscal</button></form>
            </main><script>
                window.submissions = []; window.actions = [];
                document.addEventListener('submit', event => { if (event.target.method === 'dialog') return; event.preventDefault(); submissions.push(event.target.id || 'modal'); });
                document.querySelectorAll('button').forEach(button => button.addEventListener('click', () => actions.push(button.id)));
            </script><script type="module" src="${base}/build/${manifest['resources/js/app.js'].file}"></script></body></html>`;
        await page.route('**/keyboard-navigation-fixture', (route) => route.fulfill({ contentType: 'text/html', body: html }));
        let existingCpf = false;
        await page.route('**/inspectors/lookup', (route) => {
            assert.equal(route.request().method(), 'POST');
            assert.equal(route.request().headers()['x-csrf-token'], 'csrf-test');
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ found: existingCpf, edit_url: existingCpf ? base + '/inspector-existing-fixture' : null }) });
        });
        await page.route('**/inspector-existing-fixture', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>FISCAL EXISTENTE</h1>' }));
        await page.goto(base + '/keyboard-navigation-fixture');
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('#birth-date').inputValue(), '30/12/1971');
        assert.equal(await page.locator('#decree-date').inputValue(), '29/02/2024');
        await page.locator('#birth-date').fill('01/02/2026');
        assert.deepEqual(await page.evaluate(() => Object.fromEntries(new FormData(document.querySelector('#dates')))), { birth: '2026-02-01', decree: '2024-02-29', optional: '' });
        assert.equal(await page.locator('#birth-date').inputValue(), '01/02/2026');
        const enter = async (id, next) => {
            await page.locator('#' + id).focus();
            await page.keyboard.press('Enter');
            if (next) assert.equal(await page.evaluate(() => document.activeElement.id), next, await page.evaluate(() => JSON.stringify({ active: document.activeElement.outerHTML, cpf: document.querySelector('#cpf').value, dialogs: [...document.querySelectorAll('dialog')].map(d => ({ id: d.id, open: d.open })) })));
        };
        await enter('username', 'password');
        await enter('password', 'login-save');
        assert.deepEqual(await page.evaluate(() => submissions), []);
        await page.locator('#fund').focus(); await page.keyboard.press('Enter'); await page.keyboard.press('Escape');
        assert.deepEqual(await page.evaluate(() => submissions), []);
        await page.locator('#username').fill('Exemplo'); await page.locator('#password').fill('Senha');
        await enter('login-save');
        assert.deepEqual(await page.evaluate(() => submissions), ['login']);
        await enter('first', 'second');
        await enter('second', 'one-line');
        await enter('one-line', 'multiline');
        await page.locator('#multiline').fill('Linha 1'); await page.locator('#multiline').focus(); await page.keyboard.press('End'); await page.keyboard.press('Enter');
        assert.equal(await page.locator('#multiline').inputValue(), 'Linha 1\n');
        await enter('last', 'save');
        assert.deepEqual(await page.evaluate(() => submissions), ['login']);
        await enter('save');
        assert.deepEqual(await page.evaluate(() => submissions), ['login', 'entry']);
        await enter('contract-number', 'search');
        assert.equal(await page.evaluate(() => actions.includes('search')), false);
        await enter('search');
        assert.equal(await page.evaluate(() => actions.includes('search')), true);
        await enter('filter-query', 'filter-button');
        assert.deepEqual(await page.evaluate(() => submissions), ['login', 'entry']);
        await enter('check'); assert.equal(await page.locator('#check').isChecked(), true);
        await enter('radio'); assert.equal(await page.locator('#radio').isChecked(), true);
        assert.deepEqual(await page.evaluate(() => submissions), ['login', 'entry']);
        await enter('quantity', 'product-save');
        assert.equal(await page.evaluate(() => actions.includes('product-save')), false);
        await enter('product-save');
        assert.equal(await page.evaluate(() => actions.includes('product-save')), true);
        await page.evaluate(() => document.querySelector('#modal').showModal());
        await enter('modal-first', 'modal-last'); await enter('modal-last', 'modal-save');
        await page.evaluate(() => document.querySelector('#modal').close());
        await enter('summary'); assert.equal(await page.locator('details').getAttribute('open'), '');
        await enter('link'); assert(page.url().endsWith('#destination'));
        await page.locator('#editable').focus(); await page.keyboard.press('End'); await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'editable');
        await page.evaluate(() => { const input = document.createElement('input'); input.id = 'dynamic'; document.querySelector('#entry').insertBefore(input, document.querySelector('#save')); });
        await enter('last', 'dynamic'); await enter('dynamic', 'save');
        await page.locator('#cpf').fill('52998224724'); await enter('cpf');
        await page.locator('[data-supplier-document-warning]').waitFor({ state: 'visible' });
        await page.getByRole('button', { name: 'OK', exact: true }).click();
        await page.locator('[data-supplier-document-warning]').waitFor({ state: 'hidden' });
        await page.locator('#cpf').fill('52998224725'); await enter('cpf', 'rg');
        await page.evaluate(() => { const form = document.createElement('form'); form.id = 'single'; form.innerHTML = '<input id="only">'; document.querySelector('main').append(form); });
        await enter('only', 'only');
        assert.deepEqual(await page.evaluate(() => submissions), ['login', 'entry']);
        assert.deepEqual(errors, []);
        existingCpf = true;
        await page.locator('#cpf').fill('11144477735'); await enter('cpf');
        await page.waitForURL('**/inspector-existing-fixture');
        assert.equal(await page.locator('h1').textContent(), 'FISCAL EXISTENTE');
        console.log('PASS: keyboard navigation, controls and dialogs, CPF validation/lookup, Brazilian date display and ISO date transport; no implicit submissions.');
    } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exit(1); });
