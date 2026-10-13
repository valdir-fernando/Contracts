const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');

(async () => {
    const policy = fs.readFileSync('/tmp/security-headers.php', 'utf8')
        .match(/'Content-Security-Policy', "([^"]+)"/)[1];
    const livewire = fs.readFileSync('/tmp/livewire.js', 'utf8');
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
        window.violations = [];
        document.addEventListener('securitypolicyviolation', event => window.violations.push(event.effectiveDirective));
    });
    await page.route('http://contracts.test/livewire.js', route => route.fulfill({ contentType: 'text/javascript', body: livewire }));
    await page.route('http://contracts.test/submitted*', route => route.fulfill({ contentType: 'text/html', body: '<h1>Enviado</h1>' }));
    await page.route('http://contracts.test/fixture', route => route.fulfill({
        contentType: 'text/html', headers: { 'Content-Security-Policy': policy },
        body: `<!doctype html><html><head><base href="https://external.invalid/"></head><body>
            <form x-data="{ step: 0, all: true }" action="/submitted">
                <button type="button" id="toggle" @click="all = !all">Etapas</button>
                <section id="first" x-show="all || step === 0">Primeira</section>
                <section id="second" x-show="all || step === 1">Segunda</section>
                <button type="button" id="next" @click="step = Math.min(1, step + 1); $nextTick(() => $dispatch('editor-section-change', { step }))">Próxima</button>
                <button id="submit">Enviar</button>
            </form>
            <form id="external" action="https://external.invalid/"><button id="external-submit">Externo</button></form>
            <object data="/untrusted.pdf"></object>
            <script src="/livewire.js" data-csrf="synthetic" data-update-uri="/livewire/update"></script>
        </body></html>`
    }));
    await page.goto('http://contracts.test/fixture');
    await page.waitForFunction(() => window.Alpine !== undefined);
    await page.locator('#toggle').click();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('#second')).display === 'none');
    await page.locator('#next').click();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('#first')).display === 'none');
    assert.equal(await page.locator('#second').isVisible(), true);
    assert.equal(await page.evaluate(() => document.baseURI), 'http://contracts.test/fixture');
    const resourceViolations = await page.evaluate(() => window.violations);
    assert(resourceViolations.includes('base-uri'));
    assert(resourceViolations.includes('object-src'));
    assert.deepEqual(errors, []);
    await page.locator('#submit').click();
    await page.waitForURL(url => url.origin === 'http://contracts.test' && url.pathname === '/submitted');
    assert.equal(await page.locator('h1').textContent(), 'Enviado');
    await page.goto('http://contracts.test/fixture');
    await page.locator('#external-submit').click({ noWaitAfter: true });
    await page.waitForFunction(() => window.violations.includes('form-action'));
    assert.equal(page.url(), 'http://contracts.test/fixture');
    assert.deepEqual(errors, []);
    console.log('CSP blocks base, objects and external forms; bundled Livewire/Alpine steps and same-origin submission work.');
    await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
