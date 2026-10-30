const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.EMAILER_PLAYWRIGHT_MODULE || 'playwright-core');
const config = JSON.parse(fs.readFileSync(process.env.EMAILER_BROWSER_CONFIG, 'utf8'));
const credentials = JSON.parse(fs.readFileSync(config.credentials, 'utf8'));
const result = { status: 'RUNNING', cases: [], production_accessed: false };
let browser, phase = 'launch';
(async () => {
    try {
        const args = ['--no-proxy-server'];
        if (config.port) args.push('--host-resolver-rules=MAP ' + new URL(config.url).hostname + ' 127.0.0.1:' + config.port);
        if (config.spki) args.push('--ignore-certificate-errors-spki-list=' + config.spki);
        browser = await chromium.launch({ executablePath: process.env.EMAILER_CHROMIUM_EXECUTABLE, headless: true, args });
        const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
        phase = 'login and create draft';
        await page.goto(config.url + '/login');
        await page.locator('[name=login_identifier]').fill(credentials.email);
        await page.locator('[name=password]').fill(credentials.password);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await page.waitForURL('**/admin');
        await page.goto(config.url + '/admin/templates');
        await page.locator('[data-template-enhanced=true]').waitFor();
        for (const [name, value] of Object.entries({ name: 'Synthetic action layout', subject: 'Synthetic subject', html_body: '<p>Synthetic content.</p>', text_body: 'Synthetic content.' })) {
            await page.locator('[data-template-editor] [name=' + name + ']').fill(value);
        }
        await page.locator('[data-template-save]').click();
        await page.waitForURL('**/admin/templates/*/versions');
        const actions = page.locator('.template-version-actions').first();
        for (const [label, role] of [['Synthetic preview', 'link'], ['Run fake-only test', 'button'], ['Publish', 'button']]) {
            const control = actions.getByRole(role, { name: label, exact: true });
            assert(await control.isVisible());
            await control.focus();
            assert(await control.evaluate(node => node === document.activeElement));
            if (role === 'button') {
                assert(await control.evaluate(node => node.form.method === 'post' && !!node.form.querySelector('input[name=_token]')?.value));
            }
        }
        for (const [width, kind] of [[1366, 'desktop'], [390, 'mobile']]) {
            phase = kind + ' compact accessible actions';
            await page.setViewportSize({ width, height: 900 });
            const controls = actions.locator('.template-text-action');
            result.measurements = await controls.evaluateAll(nodes => nodes.map(node => { const style = getComputedStyle(node); return { background: style.backgroundColor, border: style.borderTopWidth, height: node.getBoundingClientRect().height, action: node.textContent.trim() }; }));
            assert(await controls.evaluateAll(nodes => nodes.every(node => { const style = getComputedStyle(node); return style.backgroundColor === 'rgba(0, 0, 0, 0)' && parseFloat(style.borderTopWidth) === 0 && node.getBoundingClientRect().height <= 46; })));
            assert(await actions.evaluate(node => node.scrollWidth <= node.clientWidth + 1));
            await actions.screenshot({ path: path.join(config.output, 'template-actions-' + kind + '.png') });
            result.cases.push({ name: phase, status: 'PASS' });
        }
        phase = 'native POST and CSRF semantics without publication or testing';
        let captured;
        await page.route('**/admin/templates/**', route => {
            if (route.request().method() === 'POST') { captured = { method: route.request().method(), csrf: !!new URLSearchParams(route.request().postData()).get('_token') }; return route.fulfill({ status: 303, headers: { location: new URL(page.url()).pathname }, body: '' }); }
            return route.continue();
        });
        for (const label of ['Run fake-only test', 'Publish']) {
            captured = undefined;
            await actions.getByRole('button', { name: label, exact: true }).click();
            for (let i = 0; !captured && i < 100; i++) await page.waitForTimeout(10);
            assert(captured?.method === 'POST' && captured.csrf);
            await page.waitForLoadState('networkidle');
        }
        result.cases.push({ name: phase, status: 'PASS' });
        result.status = 'PASS';
    } catch {
        result.status = 'FAIL'; result.failedPhase = phase; process.exitCode = 1;
    } finally {
        if (browser) await browser.close();
        fs.writeFileSync(path.join(config.output, 'template-actions-result.json'), JSON.stringify(result, null, 2) + '\n');
        console.log('Template actions: ' + result.status + ' at ' + phase);
    }
})();
