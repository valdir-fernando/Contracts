const { chromium } = require('playwright');
const fs = require('fs');
const assert = require('node:assert/strict');

(async () => {
  const access = JSON.parse(fs.readFileSync(process.env.CONTRACTS_ACCESS_FILE || '/tmp/access.json', 'utf8'));
  const base = process.env.CONTRACTS_URL || 'http://127.0.0.1:8000';
  const browser = await chromium.launch();
  const page = await browser.newPage({ locale: 'en-US', viewport: { width: 1440, height: 900 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.goto(base + '/login');
    await page.locator('[name=fund_id]').selectOption(String(access.admin.fund_id || process.env.CONTRACTS_FUND_ID || await page.locator('[name=fund_id] option[value]:not([value=""])').first().getAttribute('value')));
    await page.locator('[name=username]').fill(access.admin.username);
    await page.locator('[name=password]').fill(access.admin.password);
    await Promise.all([page.waitForURL('**/dashboard'), page.locator('[data-submit-button]').click()]);
    await page.goto(base + '/calendar?month=2026-10');
    assert.equal(await page.locator('[name=month_number] option:checked').textContent(), 'Outubro');
    await page.locator('[name=month_number]').selectOption('12');
    await page.locator('[name=calendar_year]').fill('2026');
    await Promise.all([page.waitForURL('**/calendar?**'), page.getByRole('button', { name: 'Consultar mês' }).click()]);
    assert((await page.locator('.calendar-month-heading').textContent()).includes('dezembro'));
    await page.goto(base + '/reports');
    const date = page.locator('[name=reference]');
    const original = await date.evaluate(input => input.defaultValue);
    assert.equal(await date.inputValue(), original.split('-').reverse().join('/'));
    await date.fill('31/02/2026');
    assert.equal(await date.evaluate(input => input.checkValidity()), false);
    assert((await date.evaluate(input => input.validationMessage)).includes('data válida'));
    await date.fill('31/12/2026');
    assert.equal(await date.evaluate(input => input.checkValidity()), true);
    assert.equal(await date.evaluate(input => new FormData(input.form).get(input.name)), '2026-12-31');
    await date.locator('..').getByRole('button', { name: 'Abrir calendário' }).click();
    await page.keyboard.press('Escape');
    await date.locator('..').locator('input[type=date]').evaluate(input => {
      input.value = '2026-11-25';
      input.dispatchEvent(new Event('change'));
    });
    assert.equal(await date.inputValue(), '25/11/2026');
    const filterButton = page.getByRole('button', { name: 'Aplicar filtros', exact: true });
    await Promise.all([page.waitForURL('**/reports?**'), filterButton.click()]);
    assert.equal(new URL(page.url()).searchParams.get('reference'), '2026-11-25');
    for (const width of [1440, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      for (const route of ['calendar', 'reports', 'administration', 'contracts/create']) {
        assert.equal((await page.goto(base + '/' + route)).status(), 200);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      }
    }
    assert.deepEqual(errors, []);
    console.log('Brazilian localization passed in an English browser: months, dates, validation, ISO submission, calendar picker and 16 responsive views.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
