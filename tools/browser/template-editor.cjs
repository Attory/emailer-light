// Source fixtures and exact final packages use the same real-browser assertions.
// Configuration is a private JSON file; never put credentials on the command line.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.EMAILER_PLAYWRIGHT_MODULE || 'playwright-core');
const config = JSON.parse(fs.readFileSync(process.env.EMAILER_BROWSER_CONFIG, 'utf8'));
const credentials = JSON.parse(fs.readFileSync(config.credentials, 'utf8'));
const output = config.output;
fs.mkdirSync(output, { recursive: true, mode: 0o700 });
const fieldNames = ['name', 'subject', 'preheader', 'html_body', 'text_body'];
const result = { edition: config.edition, cases: [], status: 'RUNNING' };
let browser, context, page, phase = 'launch';
const record = name => result.cases.push({ name, status: 'PASS' });
const field = name => page.locator('[data-template-editor] [name=' + name + ']');
const values = async () => Object.fromEntries(await Promise.all(fieldNames.map(async name => [name, await field(name).inputValue()])));
const expectValues = async expected => assert.deepEqual(await values(), expected);
const enhanced = async target => {
    await target.locator('[data-template-editor][data-template-enhanced=true]').waitFor();
    const more = target.locator('[data-template-more]');
    for (let number = 0; await more.isVisible(); number++) {
        assert(number < 100, 'The disposable gallery pagination must terminate.');
        await more.click(); await target.locator('[data-template-more]:not([disabled])').waitFor({ state: 'attached' });
    }
};
const setCursor = async position => field('html_body').evaluate((node, pos) => {
    node.focus(); node.setSelectionRange(pos, pos); node.dispatchEvent(new Event('select'));
}, position);
const upload = async file => {
    await page.locator('[data-template-file]').setInputFiles(file);
    await page.locator('[data-template-upload]').click();
    await page.locator('[data-template-upload-status]').filter({ hasText: 'Image uploaded and saved' }).waitFor();
};
const snapshot = async name => {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: path.join(output, name + '.png'), fullPage: true });
};
const recover = async () => {
    await enhanced(page); await page.locator('[data-template-recovery]:not([hidden])').waitFor();
    await page.locator('[data-template-restore]').click();
};
const heldRequests = [];
const holdNext = async (pattern, allowCanceled = false) => {
    let release, signal;
    const started = new Promise(resolve => { signal = resolve; });
    await page.route(pattern, async route => {
        const gate = new Promise(resolve => { release = resolve; }); signal(); await gate;
        if (allowCanceled) await route.continue().catch(() => {}); else await route.continue();
    }, { times: 1 });
    const held = { started, release: () => release?.() }; heldRequests.push(held);
    return held;
};
(async () => {
    try {
        const args = ['--no-proxy-server'];
        if (config.port) args.push('--host-resolver-rules=MAP ' + new URL(config.url).hostname + ' 127.0.0.1:' + config.port);
        if (config.spki) args.push('--ignore-certificate-errors-spki-list=' + config.spki);
        browser = await chromium.launch({ executablePath: process.env.EMAILER_CHROMIUM_EXECUTABLE, headless: true, args });
        result.browserVersion = browser.version();
        result.playwrightVersion = require((process.env.EMAILER_PLAYWRIGHT_MODULE || 'playwright-core') + '/package.json').version;
        context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
        // Exercise duplicate XHR load callbacks. Only the UI callback is repeated;
        // no additional upload request or database mutation is made.
        await context.addInitScript(() => {
            const original = XMLHttpRequest.prototype.addEventListener;
            XMLHttpRequest.prototype.addEventListener = function (name, listener, options) {
                if (name !== 'load' || typeof listener !== 'function') return original.call(this, name, listener, options);
                return original.call(this, name, function (event) { listener.call(this, event); listener.call(this, event); }, options);
            };
        });
        page = await context.newPage();
        // No HAR, request bodies, console logs or Playwright traces: these can
        // contain session cookies or typed credentials.
        const pageErrors = []; page.on('pageerror', error => {
            const type = ['Error', 'TypeError', 'ReferenceError', 'SyntaxError', 'RangeError'].includes(error.name) ? error.name : 'OtherError';
            const frame = /\/assets\/[A-Za-z0-9._/-]+\.js:\d+:\d+/.exec(error.stack || '')?.[0] || 'No asset frame';
            pageErrors.push({ type, frame });
        });
        page.on('dialog', dialog => dialog.accept());
        phase = 'real login';
        await page.goto(config.url + '/login');
        await page.locator('[name=login_identifier]').fill(credentials.email);
        await page.locator('[name=password]').fill(credentials.password);
        await page.locator('form button[type=submit]').click(); await page.waitForURL('**/admin');
        phase = 'editor initialization';
        await page.goto(config.url + '/admin/templates'); await enhanced(page);
        const oldCount = await page.locator('[data-template-select]').count();
        if (config.requireExistingAssets) assert(oldCount > 0);
        record('Existing uploaded assets available on return');
        const original = { name: 'Example image newsletter ' + Date.now(), subject: 'A retained subject',
            preheader: 'A retained preheader', html_body: '<p>Before the image.</p>\n<p>After the image.</p>', text_body: 'Retained plain text.\nSecond line.' };
        for (const [name, value] of Object.entries(original)) await field(name).fill(value);
        const cursor = original.html_body.indexOf('\n') + 1; await setCursor(cursor);
        phase = 'original inline upload sequence';
        const firstUpload = await holdNext('**/admin/templates/assets/upload');
        await page.locator('[data-template-file]').setInputFiles(config.image);
        await page.locator('[data-template-upload]').click();
        await firstUpload.started;
        await page.locator('[data-template-upload-progress]:not([hidden])').waitFor();
        assert.equal(await page.locator('[data-template-save]').isDisabled(), true);
        await expectValues(original); firstUpload.release();
        await page.locator('[data-template-upload-status]').filter({ hasText: 'Image uploaded and saved' }).waitFor();
        assert.equal(new URL(page.url()).pathname, '/admin/templates'); await expectValues(original);
        assert.equal(await field('html_body').evaluate(node => node.selectionStart), cursor);
        assert.equal(await page.locator('[data-template-select]').count(), oldCount + 1);
        const imagePath = await page.locator('[data-template-image-path]').inputValue();
        assert.match(imagePath, /^\/template-assets\/[a-f0-9]{48}\.png$/);
        assert(!original.html_body.includes(imagePath));
        await page.locator('[data-template-fields]').screenshot({ path: path.join(output, 'retained-template-fields.png') });
        await page.locator('.template-image-library').evaluate(node => window.scrollTo({ top: window.scrollY + node.getBoundingClientRect().top, behavior: 'instant' }));
        const libraryBox = await page.locator('.template-image-library').boundingBox();
        const settingsBox = await page.locator('[data-template-image-settings]').boundingBox();
        // Capture the real upload/gallery region, with the separate insertion
        // controls shown in their own screenshot at a readable document scale.
        await page.screenshot({ path: path.join(output, 'image-library-desktop.png'), clip: {
            x: libraryBox.x, y: Math.max(0, libraryBox.y), width: libraryBox.width, height: settingsBox.y - libraryBox.y,
        } });
        record('Original five fields and cursor survive inline upload; progress and duplicate-response handling');
        phase = 'actual validation failure and retry';
        await page.locator('[data-template-file]').setInputFiles({ name: 'unsupported.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"></svg>') });
        await page.locator('[data-template-upload]').click();
        await page.locator('[data-template-upload-error]:not([hidden])').waitFor();
        assert.match(await page.locator('[data-template-upload-error]').innerText(), /JPEG|PNG|WebP/);
        await expectValues(original); assert.equal(await field('html_body').evaluate(node => node.selectionStart), cursor);
        await snapshot('upload-validation-error-desktop');
        await page.locator('[data-template-file]').setInputFiles(config.image);
        await page.locator('[data-template-retry]').click();
        await page.locator('[data-template-upload-status]').filter({ hasText: 'Image uploaded and saved' }).waitFor();
        await expectValues(original); assert.equal(await page.locator('[data-template-select]').count(), oldCount + 2);
        record('Rejected file and explicit retry retain all entries and cursor; multiple persistent images');
        phase = 'network upload failure';
        await page.route('**/admin/templates/assets/upload', route => route.abort('failed'), { times: 1 });
        await page.locator('[data-template-file]').setInputFiles(config.image); await page.locator('[data-template-upload]').click();
        await page.locator('[data-template-upload-error]:not([hidden])').waitFor(); await expectValues(original);
        await page.locator('[data-template-retry]').click();
        await page.locator('[data-template-upload-status]').filter({ hasText: 'Image uploaded and saved' }).waitFor();
        await expectValues(original); assert.equal(await page.locator('[data-template-select]').count(), oldCount + 3);
        record('Network failure and retry retain fields without duplicate insertion');
        phase = 'late upload preserves current selection and keyboard focus';
        const lateUpload = await holdNext('**/admin/templates/assets/upload');
        await page.locator('[data-template-file]').setInputFiles(config.image); await page.locator('[data-template-upload]').click();
        await lateUpload.started;
        await page.locator('[data-template-upload-progress]:not([hidden])').waitFor();
        const existingId = await page.locator('[data-template-select]').first().getAttribute('data-asset-id');
        const existingButton = page.locator('[data-asset-id="' + existingId + '"]');
        await existingButton.focus(); await existingButton.press('Enter');
        await page.locator('[data-template-alt]').fill('Keep the current selection');
        await existingButton.focus(); lateUpload.release();
        await page.locator('[data-template-upload-status]').filter({ hasText: 'Image uploaded and saved' }).waitFor();
        assert.equal(await existingButton.getAttribute('aria-pressed'), 'true');
        assert.equal(await existingButton.evaluate(node => node === document.activeElement), true);
        assert.equal(await page.locator('[data-template-alt]').inputValue(), 'Keep the current selection');
        await expectValues(original); assert.equal(await page.locator('[data-template-select]').count(), oldCount + 4);
        record('Late upload reply preserves newer image settings, selection and keyboard focus');
        phase = 'cancel upload';
        const canceledUpload = await holdNext('**/admin/templates/assets/upload', true);
        await page.locator('[data-template-file]').setInputFiles(config.image); await page.locator('[data-template-upload]').click();
        await canceledUpload.started;
        await page.locator('[data-template-cancel-upload]:not([hidden])').click(); canceledUpload.release();
        await page.locator('[data-template-upload-error]').filter({ hasText: 'canceled' }).waitFor();
        await expectValues(original); assert.equal(await page.locator('[data-template-select]').count(), oldCount + 4);
        record('Canceled upload retains entries and explains how to check the gallery before retrying');
        phase = 'explicit insertion and copy';
        await page.locator('[data-template-select]').first().click();
        await page.locator('[data-template-alt]').fill('A warm banner & welcome');
        await page.locator('[data-template-width]').fill('480');
        assert.equal(await page.locator('[data-template-height]').inputValue(), '270');
        const selectedPath = await page.locator('[data-template-image-path]').inputValue();
        // Force the documented clipboard fallback so the exact copied value is
        // observable without granting machine-wide clipboard access.
        await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('Test fallback'); } } }));
        await page.locator('[data-template-copy-html]').click();
        const snippet = await page.locator('[data-template-copy-value]').inputValue();
        assert.equal(snippet, '<img src="' + selectedPath + '" alt="A warm banner &amp; welcome" width="480" height="270">');
        await page.locator('[data-template-copy-close]').click(); await expectValues(original);
        await page.locator('[data-template-copy-path]').click();
        assert.equal(await page.locator('[data-template-copy-value]').inputValue(), selectedPath); await page.locator('[data-template-copy-close]').click();
        await setCursor(cursor); await page.locator('[data-template-insert]').click();
        const inserted = { ...original, html_body: original.html_body.slice(0, cursor) + snippet + original.html_body.slice(cursor) };
        await expectValues(inserted);
        assert.equal((inserted.html_body.match(/<img /g) || []).length, 1);
        const after = await field('html_body').evaluate(node => node.selectionStart); assert.equal(after, cursor + snippet.length);
        await page.locator('[data-template-image-settings]').screenshot({ path: path.join(output, 'insert-image-settings.png') });
        await setCursor(2); await page.locator('[data-template-insert]').click();
        await page.locator('[data-template-image-error]:not([hidden])').waitFor(); await expectValues(inserted);
        await page.locator('[data-template-width]').fill('0'); await setCursor(after); await page.locator('[data-template-insert]').click(); await expectValues(inserted);
        await page.locator('[data-template-width]').fill('480');
        for (const [html, position] of [
            ['<p title="a > b">Banner</p>', 14],
            ['<!-- a > b -->', 10],
            ['<style>p > span { color: red; }</style>', 15],
            ['<p>{{ first_name }}</p>', 10],
        ]) {
            await field('html_body').fill(html); await setCursor(position);
            await page.locator('[data-template-insert]').click();
            assert.equal(await field('html_body').inputValue(), html);
            await page.locator('[data-template-image-error]:not([hidden])').waitFor();
        }
        await field('html_body').fill(inserted.html_body); await setCursor(after);
        await field('html_body').evaluate(node => { node.setRangeText = () => { throw new Error('Simulated insertion failure'); }; });
        await page.locator('[data-template-insert]').click(); await expectValues(inserted);
        await field('html_body').evaluate(node => { delete node.setRangeText; });
        record('Copy HTML/path, escaped alt text, dimensions and explicit cursor insertion; failed insertion preserves content');
        phase = 'reload and independent tabs';
        await page.reload(); await recover(); await expectValues(inserted);
        phase = 'reload cursor recovery';
        assert.equal(await field('html_body').evaluate(node => node.selectionStart), after);
        const second = await context.newPage(); second.on('dialog', dialog => dialog.accept());
        await second.goto(config.url + '/admin/templates'); await enhanced(second);
        assert.equal(await second.locator('[data-template-recovery]:not([hidden])').count(), 0);
        phase = 'independent tab asset reuse';
        // Cancel is not a server rollback: a request that already arrived may
        // still leave a reusable image. The visible message explains this.
        assert(await second.locator('[data-template-select]').count() >= oldCount + 4);
        await second.locator('[data-template-editor] [name=name]').fill('Independent tab draft');
        await second.locator('[data-template-editor] [name=html_body]').fill('<p>Other tab only.</p>');
        await second.reload(); await second.locator('[data-template-restore]').click();
        assert.equal(await second.locator('[data-template-editor] [name=name]').inputValue(), 'Independent tab draft');
        await expectValues(inserted); await second.close();
        phase = 'navigation return recovery';
        await page.goto(config.url + '/admin'); await page.goto(config.url + '/admin/templates'); await recover(); await expectValues(inserted);
        record('Reload, navigation return and independent tab recovery; saved assets reused without re-upload');
        phase = 'create validation retains all fields';
        const invalid = { ...inserted, html_body: '<p>{{ unknown_field }}</p>\n' + inserted.html_body };
        await field('html_body').fill(invalid.html_body); await setCursor(5); await page.locator('[data-template-save]').click();
        await page.locator('[data-template-error]:not([hidden])').waitFor(); await expectValues(invalid);
        assert.equal(await field('html_body').evaluate(node => node.selectionStart), 5);
        await snapshot('template-validation-error-desktop');
        record('Failed template validation retains all five fields and cursor with an actionable error');
        phase = 'desktop and mobile thumbnail bounds';
        await field('html_body').fill(inserted.html_body);
        await snapshot('editor-images-desktop');
        for (const size of [{ width: 1440, height: 1100 }, { width: 390, height: 844 }]) {
            await page.setViewportSize(size);
            assert(await page.locator('.template-image-thumbnail').count() >= 3);
            for (const image of await page.locator('.template-image-thumbnail').all()) {
                const box = await image.boundingBox(); assert(box.width <= 160 && box.height <= 120);
            }
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        }
        await snapshot('editor-images-mobile'); await page.setViewportSize({ width: 1440, height: 1100 });
        record('Bounded desktop/mobile thumbnails and no horizontal overflow');
        phase = 'save an unpublished draft and edit';
        const createSave = await holdNext('**/admin/templates/create');
        await page.locator('[data-template-save]').click();
        await createSave.started;
        await page.locator('[data-template-save]:disabled').waitFor();
        await field('subject').fill('Newer typing during the save'); createSave.release();
        await page.locator('[data-template-notice]').filter({ hasText: 'newer unsaved edits' }).waitFor();
        assert.equal(await field('subject').inputValue(), 'Newer typing during the save');
        assert.match(new URL(page.url()).pathname, /^\/admin\/templates\/[a-f0-9-]{36}\/edit$/);
        await page.reload(); await recover();
        assert.equal(await field('subject').inputValue(), 'Newer typing during the save');
        await field('subject').fill(inserted.subject);
        const editPath = new URL(page.url()).pathname;
        const editSave = await holdNext('**' + editPath);
        await page.locator('[data-template-save]').click(); await page.locator('[data-template-save]:disabled').waitFor();
        await editSave.started;
        await field('preheader').fill('Newer typing during an edit save'); editSave.release();
        await page.locator('[data-template-notice]').filter({ hasText: 'newer unsaved edits' }).waitFor();
        assert.equal(new URL(page.url()).pathname, editPath);
        await page.reload(); await recover();
        assert.equal(await field('preheader').inputValue(), 'Newer typing during an edit save');
        await field('preheader').fill(inserted.preheader);
        await page.locator('[data-template-save]').click(); await page.waitForURL('**/admin/templates/*/versions'); await enhanced(page);
        record('Typing during slow create and edit saves survives reload and resumes without duplication');
        const id = new URL(page.url()).pathname.split('/')[3];
        result.templateId = id; result.assetPath = selectedPath; result.expectedHtml = inserted.html_body; result.templateName = original.name;
        await page.goto(config.url + '/admin/templates/' + id + '/edit'); await enhanced(page);
        assert.equal(await field('name').inputValue(), inserted.name); const normalized = await field('html_body').inputValue();
        const semantic = await field('html_body').evaluate((node) => { const doc = new DOMParser().parseFromString(node.value, 'text/html'); const image = doc.querySelector('img'); return { paragraphs: [...doc.querySelectorAll('p')].map(p => p.textContent), src: image?.getAttribute('src'), alt: image?.getAttribute('alt'), width: image?.getAttribute('width'), height: image?.getAttribute('height') }; });
        assert.deepEqual(semantic, { paragraphs: ['Before the image.', 'After the image.'], src: selectedPath, alt: 'A warm banner & welcome', width: '480', height: '270' });
        result.expectedHtml = normalized;
        const editValues = { ...(await values()), subject: 'An edited retained subject' };
        await field('subject').fill(editValues.subject); await setCursor(0); await upload(config.image); await expectValues(editValues);
        await field('html_body').fill('{% unsupported %}' + editValues.html_body); const invalidEdit = await values();
        await page.locator('[data-template-save]').click(); await page.locator('[data-template-error]:not([hidden])').waitFor(); await expectValues(invalidEdit);
        await field('html_body').fill(editValues.html_body); await snapshot('edit-template-desktop');
        await page.locator('[data-template-save]').click(); await page.waitForURL('**/admin/templates/*/versions');
        record('Create and edit upload/validation preserve fields; save remains a separate unpublished draft action');
        phase = 'fallback upload then enhancement';
        const fallback = await context.newPage(); fallback.on('dialog', dialog => dialog.accept());
        await fallback.route('**/assets/template-editor-*.js', route => route.abort('failed'), { times: 1 });
        await fallback.goto(config.url + '/admin/templates');
        assert.equal(await fallback.locator('[data-template-enhanced=true]').count(), 0);
        for (const [name, value] of Object.entries(original)) await fallback.locator('[data-template-editor] [name=' + name + ']').fill(value);
        await fallback.locator('[data-template-file]').setInputFiles(config.image);
        phase = 'fallback native upload response';
        await fallback.locator('[data-template-upload]').click(); await enhanced(fallback);
        phase = 'fallback unsaved metadata';
        assert.equal(await fallback.locator('[data-template-editor]').getAttribute('data-editor-unsaved'), 'true');
        for (const [name, value] of Object.entries(original)) assert.equal(await fallback.locator('[data-template-editor] [name=' + name + ']').inputValue(), value);
        phase = 'fallback navigation recovery';
        await fallback.goto(config.url + '/admin/templates'); await fallback.locator('[data-template-restore]').click();
        for (const [name, value] of Object.entries(original)) assert.equal(await fallback.locator('[data-template-editor] [name=' + name + ']').inputValue(), value);
        await fallback.close(); record('Non-JavaScript upload retains entries and subsequent enhancement protects the unsaved draft');
        phase = 'browser storage unavailable safeguard';
        const unavailable = await context.newPage(); let warned = 0;
        unavailable.on('dialog', async dialog => { if (dialog.type() === 'beforeunload') warned++; await dialog.accept(); });
        await unavailable.addInitScript(() => Object.defineProperty(window, 'sessionStorage', { configurable: true, get() { throw new Error('Simulated disabled browser storage'); } }));
        await unavailable.goto(config.url + '/admin/templates'); await enhanced(unavailable);
        await unavailable.locator('[data-template-editor] [name=name]').click();
        await unavailable.locator('[data-template-editor] [name=name]').fill('Unsaved copy with storage disabled');
        await unavailable.locator('[data-template-editor] [name=html_body]').fill('<p>Keep this until leaving is confirmed.</p>');
        await unavailable.locator('[data-template-draft-status]').filter({ hasText: 'Browser recovery is unavailable' }).waitFor();
        assert.equal(await unavailable.locator('[data-template-editor] [name=name]').inputValue(), 'Unsaved copy with storage disabled');
        await unavailable.goto(config.url + '/admin'); assert(warned > 0); await unavailable.close();
        record('Unavailable browser storage reports the limitation and warns before leaving unsaved content');
        phase = 'public image retrieval';
        const publicPage = await browser.newContext(); const recipient = await publicPage.newPage();
        const response = await recipient.goto(config.url + selectedPath);
        phase = 'public image HTTP status'; result.publicImageStatus = response.status();
        assert.equal(response.status(), 200);
        phase = 'public image MIME type';
        const imageType = response.headers()['content-type'];
        result.publicImageContentType = typeof imageType === 'string' && /^[A-Za-z0-9/+.;= -]{1,100}$/.test(imageType) ? imageType : 'Other';
        assert.match(imageType, /^image\/png/);
        phase = 'public image byte count';
        const bytes = await response.body(); assert(bytes.length > 0); result.publicImageBytes = bytes.length; result.publicImageSha256 = crypto.createHash('sha256').update(bytes).digest('hex'); await publicPage.close();
        phase = 'browser runtime errors'; result.runtimeErrors = pageErrors;
        assert.deepEqual(pageErrors, []); record('Saved image retrievable by an unauthenticated recipient');
        result.status = 'PASS'; console.log(config.edition + ' editor browser regressions PASS (' + result.cases.length + ' cases).');
    } catch {
        result.status = 'FAIL'; result.failedPhase = phase;
        console.error('Editor browser regression failed at ' + phase + '. Credential/request details omitted.');
        if (page && !phase.includes('login')) await snapshot('failure').catch(() => {});
        process.exitCode = 1;
    } finally {
        for (const held of heldRequests) held.release();
        if (page && context) {
            await page.goto(config.url + '/admin/sessions').catch(() => {});
            const logout = page.locator('form[action="/logout"]');
            if (await logout.count()) await logout.locator('button').click().catch(() => {});
        }
        await context?.close(); await browser?.close();
        fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify(result, null, 2), { mode: 0o600 });
    }
})();
