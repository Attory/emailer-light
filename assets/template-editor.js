// Presentation only. Upload never inserts HTML; only the explicit Insert action does.
const FIELDS = ['name', 'subject', 'preheader', 'html_body', 'text_body'];
const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/;
const ASSET = /^\/template-assets\/[a-f0-9]{48}\.(?:jpg|png|webp)$/;
const TTL = 12 * 60 * 60 * 1000;
const escapeHtml = value => value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const errors = (body, fallback) => Array.isArray(body?.errors) && typeof body.errors[0] === 'string' ? body.errors[0].slice(0, 1200) : fallback;

// Track source context rather than counting '<' and '>'. A quoted attribute,
// comment or raw-text element can contain either without ending its context.
const insertionContext = (html, position) => {
    let mode = 'text', quote = '', start = 0, raw = '';
    for (let i = 0; i < position; i++) {
        if (mode === 'comment') {
            if (html.startsWith('-->', i)) { if (i + 3 > position) return false; mode = 'text'; i += 2; }
        } else if (mode === 'raw') {
            if (html[i] === '<' && html.slice(i).toLowerCase().startsWith('</' + raw) && /[\s>\/]/.test(html[i + raw.length + 2] || '')) { mode = 'tag'; start = i; }
        } else if (mode === 'tag') {
            if (quote) { if (html[i] === quote) quote = ''; }
            else if (html[i] === '"' || html[i] === "'") quote = html[i];
            else if (html[i] === '>') {
                const tag = html.slice(start, i + 1);
                const name = /^<\s*([a-z]+)/i.exec(tag)?.[1]?.toLowerCase();
                if (name && ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'].includes(name) && !/\/\s*>$/.test(tag)) { mode = 'raw'; raw = name; }
                else { mode = 'text'; raw = ''; }
            }
        } else if (html.startsWith('<!--', i)) { mode = 'comment'; i += 3; }
        else if (html[i] === '<' && /[a-z!/?]/i.test(html[i + 1] || '')) { mode = 'tag'; start = i; }
    }
    const prefix = html.slice(0, position);
    return mode === 'text' && prefix.lastIndexOf('{{') <= prefix.lastIndexOf('}}') && prefix.lastIndexOf('{%') <= prefix.lastIndexOf('%}');
};

class TemplateEditor {
    constructor(form) {
        this.form = form;
        this.html = this.q('[name=html_body]');
        this.base = JSON.stringify(this.values());
        this.forcedDirty = form.dataset.editorUnsaved === 'true';
        this.prefix = 'emailer.templateDraft.v1.' + form.dataset.editorAccount + '.';
        this.key = this.prefix + form.dataset.editorKey;
        this.assets = new Map();
        this.selected = null;
        this.selectionRevision = 0;
        this.uploading = false;
        this.saving = false;
        this.recoveryPending = false;
        this.leaving = false;
        this.galleryGeneration = 0;
        this.uploadGeneration = 0;
        this.beforeUnload = event => {
            if (!this.leaving && (this.dirty() || this.uploading || this.saving)) {
                this.persist();
                event.preventDefault();
                event.returnValue = '';
            }
        };
        try { this.storage = window.sessionStorage; } catch { this.storage = null; }
        for (const card of form.querySelectorAll('[data-template-select]')) {
            const d = card.dataset;
            const asset = this.asset({ id: d.assetId, url: d.assetUrl, width: Number(d.assetWidth), height: Number(d.assetHeight), created_at: d.assetCreated, available: d.assetAvailable === 'true' });
            this.assets.set(asset.id, asset);
        }
        this.renderGallery();
        this.bind();
        this.recover();
        if (!this.recoveryPending && this.assets.has(form.dataset.selectedAsset)) this.select(form.dataset.selectedAsset);
        this.controls();
        form.dataset.templateEnhanced = 'true';
    }

    q(selector) { const node = this.form.querySelector(selector); if (!node) throw new Error('Editor component missing.'); return node; }
    message(selector, text) { const node = this.q(selector); if (node.textContent !== text) node.textContent = text; node.hidden = !text; }
    values() { return Object.fromEntries(FIELDS.map(name => [name, this.form.elements.namedItem(name)?.value ?? ''])); }
    dirty() { return this.forcedDirty || JSON.stringify(this.values()) !== this.base; }
    schedule() { clearTimeout(this.timer); this.controls(); this.timer = setTimeout(() => this.persist(), 250); }

    bind() {
        this.form.addEventListener('submit', event => {
            event.preventDefault();
            if (event.submitter?.hasAttribute('data-template-upload')) this.upload(); else this.save();
        });
        this.form.addEventListener('input', event => { if (FIELDS.includes(event.target.name)) this.schedule(); });
        for (const event of ['select', 'keyup', 'mouseup', 'blur']) this.html.addEventListener(event, () => this.schedule());
        this.q('[data-template-retry]').addEventListener('click', () => this.upload());
        this.q('[data-template-cancel-upload]').addEventListener('click', () => this.xhr?.abort());
        this.q('[data-template-refresh]').addEventListener('click', () => this.loadGallery(false));
        this.q('[data-template-more]').addEventListener('click', () => this.loadGallery(true));
        this.q('[data-template-gallery]').addEventListener('click', event => {
            const button = event.target.closest('[data-template-select]');
            if (button && !button.disabled && !this.recoveryPending) this.select(button.dataset.assetId);
        });
        this.q('[data-template-insert]').addEventListener('click', () => this.insert());
        this.q('[data-template-copy-html]').addEventListener('click', () => this.copy(true));
        this.q('[data-template-copy-path]').addEventListener('click', () => this.copy(false));
        for (const side of ['width', 'height']) this.q('[data-template-' + side + ']').addEventListener('input', () => {
            this.selectionRevision++;
            const image = this.assets.get(this.selected);
            const value = Number(this.q('[data-template-' + side + ']').value);
            if (image && this.q('[data-template-proportions]').checked && Number.isInteger(value) && value > 0) {
                const other = side === 'width' ? 'height' : 'width';
                this.q('[data-template-' + other + ']').value = String(Math.max(1, Math.round(value * image[other] / image[side])));
            }
            this.schedule();
        });
        this.q('[data-template-alt]').addEventListener('input', () => { this.selectionRevision++; this.schedule(); });
        this.q('[data-template-proportions]').addEventListener('change', () => { this.selectionRevision++; this.schedule(); });
        this.q('[data-template-restore]').addEventListener('click', () => this.restore());
        this.q('[data-template-discard]').addEventListener('click', () => {
            try { this.storage?.removeItem(this.key); } catch { /* Keep the visible editor usable. */ }
            this.recoveryPending = false;
            this.q('[data-template-recovery]').hidden = true;
            this.controls();
        });
        this.q('[data-template-copy-close]').addEventListener('click', () => this.q('[data-template-copy-dialog]').close());
        window.addEventListener('pagehide', () => this.persist());
        document.addEventListener('visibilitychange', () => { if (document.hidden) this.persist(); });
    }

    controls() {
        const busy = this.uploading || this.saving || this.recoveryPending;
        this.q('[data-template-save]').disabled = busy;
        this.q('[data-template-upload]').disabled = busy;
        this.q('[data-template-retry]').disabled = busy;
        this.q('[data-template-cancel-upload]').hidden = !this.uploading;
        this.q('[data-template-fields]').disabled = this.recoveryPending;
        this.q('.template-image-library').inert = this.recoveryPending;
        const guard = !this.leaving && (this.dirty() || this.uploading || this.saving);
        if (guard && !this.guarded) window.addEventListener('beforeunload', this.beforeUnload);
        if (!guard && this.guarded) window.removeEventListener('beforeunload', this.beforeUnload);
        this.guarded = guard;
    }

    persist() {
        if (this.recoveryPending || this.leaving) return false;
        try {
            if (!this.storage) throw new Error('Storage unavailable.');
            if (!this.dirty()) { this.storage.removeItem(this.key); return true; }
            const draft = { format: 1, updatedAt: Date.now(), values: this.values(), cursor: {
                start: this.html.selectionStart, end: this.html.selectionEnd, direction: this.html.selectionDirection },
                image: { id: this.selected, alt: this.q('[data-template-alt]').value,
                    width: this.q('[data-template-width]').value, height: this.q('[data-template-height]').value,
                    proportions: this.q('[data-template-proportions]').checked } };
            const encoded = JSON.stringify(draft);
            if (encoded.length > 2_000_000) throw new Error('Recovery size exceeded.');
            this.storage.setItem(this.key, encoded);
            this.message('[data-template-draft-status]', 'Unsaved changes. A recovery copy is kept in this browser tab; use Save to store the template draft.');
            return true;
        } catch {
            if (this.dirty()) this.message('[data-template-draft-status]', 'Unsaved changes. Browser recovery is unavailable; save or copy your content before leaving.');
            return false;
        }
    }

    recover() {
        if (this.forcedDirty) { this.persist(); return; }
        try {
            const raw = this.storage?.getItem(this.key);
            if (!raw) return;
            if (raw.length > 2_000_000) throw new Error('Invalid recovery copy.');
            const draft = JSON.parse(raw);
            if (draft.format !== 1 || !Number.isFinite(draft.updatedAt) || draft.updatedAt > Date.now() + 300000) throw new Error('Invalid recovery copy.');
            if (Date.now() - draft.updatedAt > TTL) { this.storage.removeItem(this.key); return; }
            if (!draft.values || !FIELDS.every(name => typeof draft.values[name] === 'string')) throw new Error('Invalid recovery fields.');
            if (JSON.stringify(draft.values) === this.base) return;
            this.recoveryDraft = draft;
            this.recoveryPending = true;
            this.q('[data-template-recovery]').hidden = false;
            this.message('[data-template-recovery-info]', 'An unsaved copy from ' + new Date(draft.updatedAt).toLocaleString() + ' is available in this tab. Restore it or discard it before editing.');
        } catch {
            this.message('[data-template-draft-status]', 'A browser recovery copy could not be read. The saved template has not been replaced. Keep a copy of any changes before leaving.');
        }
    }

    restore() {
        const draft = this.recoveryDraft;
        if (!draft || !this.recoveryPending) return;
        for (const name of FIELDS) { const field = this.form.elements.namedItem(name); if (field) field.value = draft.values[name]; }
        this.recoveryPending = false;
        this.forcedDirty = true;
        this.q('[data-template-recovery]').hidden = true;
        this.controls();
        if (draft.image && this.assets.has(draft.image.id)) {
            this.select(draft.image.id);
            for (const name of ['alt', 'width', 'height']) if (typeof draft.image[name] === 'string' && draft.image[name].length <= 500) this.q('[data-template-' + name + ']').value = draft.image[name];
            this.q('[data-template-proportions]').checked = draft.image.proportions !== false;
        }
        const cursor = draft.cursor;
        if (cursor && Number.isInteger(cursor.start) && Number.isInteger(cursor.end)) {
            const start = Math.max(0, Math.min(this.html.value.length, cursor.start));
            const end = Math.max(start, Math.min(this.html.value.length, cursor.end));
            this.html.focus({ preventScroll: true });
            this.html.setSelectionRange(start, end, ['forward', 'backward', 'none'].includes(cursor.direction) ? cursor.direction : 'none');
        }
        this.persist();
        this.message('[data-template-notice]', 'Recovery copy restored. It has not been saved or published.');
    }

    asset(value) {
        if (!value || !UUID.test(value.id) || !ASSET.test(value.url) || !Number.isInteger(value.width) || !Number.isInteger(value.height)
            || value.width < 1 || value.height < 1 || value.width > 2400 || value.height > 2400) throw new Error('Unsupported image response.');
        return { id: value.id, url: value.url, width: value.width, height: value.height,
            available: value.available !== false, created_at: typeof value.created_at === 'string' ? value.created_at.slice(0, 80) : 'Just uploaded' };
    }

    renderGallery() {
        const gallery = this.q('[data-template-gallery]');
        const focused = gallery.contains(document.activeElement) ? document.activeElement?.closest('[data-template-select]')?.dataset.assetId : null;
        const fragment = document.createDocumentFragment();
        const sorted = [...this.assets.values()].sort((a, b) => b.id.localeCompare(a.id));
        for (const [index, asset] of sorted.entries()) {
            const card = document.createElement('button');
            card.type = 'button'; card.className = 'template-image-choice secondary'; card.dataset.templateSelect = '';
            card.dataset.assetId = asset.id; card.setAttribute('aria-pressed', String(asset.id === this.selected)); card.disabled = !asset.available;
            if (asset.available) {
                const image = document.createElement('img'); image.src = asset.url; image.alt = 'Saved image ' + (index + 1);
                image.width = 160; image.height = 120; image.loading = 'lazy'; image.decoding = 'async'; image.className = 'template-image-thumbnail'; card.append(image);
            }
            const title = document.createElement('span'); title.textContent = asset.available ? 'Select image' : 'Image file unavailable'; card.append(title);
            const meta = document.createElement('small'); meta.textContent = asset.width + ' × ' + asset.height + ' pixels · ' + asset.created_at; card.append(meta);
            fragment.append(card);
        }
        if (!sorted.length) { const empty = document.createElement('p'); empty.textContent = 'No saved images yet. Your uploads will remain available here when you return.'; fragment.append(empty); }
        gallery.replaceChildren(fragment);
        if (focused && UUID.test(focused)) {
            const replacement = gallery.querySelector('[data-asset-id="' + focused + '"]');
            if (replacement && !replacement.disabled) replacement.focus({ preventScroll: true });
        }
    }

    select(id) {
        const asset = this.assets.get(id);
        if (!asset?.available || this.recoveryPending) return;
        this.selected = id; this.selectionRevision++;
        const scale = Math.min(1, 600 / asset.width, 600 / asset.height);
        this.q('[data-template-width]').value = String(Math.max(1, Math.round(asset.width * scale)));
        this.q('[data-template-height]').value = String(Math.max(1, Math.round(asset.height * scale)));
        this.q('[data-template-alt]').value = '';
        this.q('[data-template-image-path]').value = asset.url;
        this.q('[data-template-image-settings]').hidden = false;
        this.message('[data-template-image-error]', '');
        for (const card of this.form.querySelectorAll('[data-template-select]')) card.setAttribute('aria-pressed', String(card.dataset.assetId === id));
        this.schedule();
    }

    snippet() {
        const asset = this.assets.get(this.selected);
        if (!asset?.available || this.recoveryPending) throw new Error('Select an available image from the gallery first.');
        const dimensions = {};
        for (const name of ['width', 'height']) {
            const raw = this.q('[data-template-' + name + ']').value.trim();
            if (!/^[0-9]{1,4}$/.test(raw) || Number(raw) < 1 || Number(raw) > 2400) throw new Error('Choose display dimensions from 1 to 2400 pixels. Your HTML has not been changed.');
            dimensions[name] = Number(raw);
        }
        const alt = this.q('[data-template-alt]').value;
        if (alt.length > 500) throw new Error('Keep the image description within 500 characters. Your HTML has not been changed.');
        return '<img src="' + asset.url + '" alt="' + escapeHtml(alt) + '" width="' + dimensions.width + '" height="' + dimensions.height + '">';
    }

    insert() {
        let snippet;
        try { snippet = this.snippet(); } catch (error) { this.message('[data-template-image-error]', error.message); return; }
        const original = this.html.value;
        const start = this.html.selectionStart; const end = this.html.selectionEnd; const direction = this.html.selectionDirection;
        if (!insertionContext(original, start) || !insertionContext(original, end)) { this.message('[data-template-image-error]', 'Place the HTML cursor in text or between tags, outside attributes, comments and merge fields. Your HTML is unchanged.'); return; }
        try {
            this.html.setRangeText(snippet, start, end, 'end');
            this.html.focus({ preventScroll: true });
            this.html.dispatchEvent(new Event('input', { bubbles: true }));
        } catch {
            this.html.value = original;
            this.html.setSelectionRange(start, end, direction);
            this.message('[data-template-image-error]', 'The image could not be inserted. Your HTML is unchanged. Position the cursor and try again, or use Copy image HTML.');
            return;
        }
        this.message('[data-template-image-error]', '');
        this.message('[data-template-notice]', 'Image inserted at the HTML cursor. Save the template draft when ready.');
        this.persist();
    }

    async copy(html) {
        let text;
        try { text = html ? this.snippet() : this.assets.get(this.selected)?.url; if (!text) throw new Error('Select an image first.'); }
        catch (error) { this.message('[data-template-image-error]', error.message); return; }
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable.');
            await navigator.clipboard.writeText(text);
            this.message('[data-template-notice]', html ? 'Image HTML copied. Your editor content is unchanged.' : 'Image path copied. Your editor content is unchanged.');
        } catch {
            this.q('[data-template-copy-value]').value = text;
            this.q('[data-template-copy-dialog]').showModal();
            this.q('[data-template-copy-value]').select();
        }
    }

    async loadGallery(more) {
        if (this.galleryLoading) return;
        const button = this.q('[data-template-more]');
        const cursor = more ? button.dataset.cursor : '';
        if (more && !cursor) return;
        const generation = ++this.galleryGeneration;
        this.galleryLoading = true;
        this.q('[data-template-refresh]').disabled = true; button.disabled = true;
        const url = new URL(this.form.dataset.galleryUrl, location.href);
        if (cursor) url.searchParams.set('before', cursor);
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (!response.ok || !Array.isArray(body.items) || !(body.next_cursor === null || UUID.test(body.next_cursor))) throw new Error('Gallery unavailable.');
            const items = body.items.map(item => this.asset(item));
            if (generation !== this.galleryGeneration) return;
            for (const asset of items) this.assets.set(asset.id, asset);
            button.dataset.cursor = body.next_cursor ?? ''; button.hidden = !body.next_cursor;
            this.renderGallery(); this.message('[data-template-gallery-error]', '');
        } catch {
            this.message('[data-template-gallery-error]', 'Saved images could not be loaded. Check your connection and try Refresh gallery. Your entries and already loaded images are unchanged.');
        } finally {
            if (generation === this.galleryGeneration) { this.galleryLoading = false; this.q('[data-template-refresh]').disabled = false; button.disabled = false; }
        }
    }

    upload() {
        if (this.uploading || this.saving || this.recoveryPending) return;
        const file = this.q('[data-template-file]').files?.[0];
        if (!file) { this.message('[data-template-upload-error]', 'Choose a JPEG, PNG or WebP image first. Your template entries are unchanged.'); return; }
        this.persist();
        const request = ++this.uploadGeneration; const selection = this.selectionRevision;
        const xhr = new XMLHttpRequest(); this.xhr = xhr; this.uploading = true; this.controls();
        const progress = this.q('[data-template-upload-progress]'); progress.hidden = false; progress.value = 0;
        this.message('[data-template-upload-error]', ''); this.message('[data-template-upload-status]', 'Uploading image…');
        this.q('[data-template-retry]').hidden = true;
        let settled = false;
        const finish = (success, text) => {
            if (settled || request !== this.uploadGeneration) return;
            settled = true; this.uploading = false; this.controls();
            if (!success) { progress.hidden = true; this.message('[data-template-upload-status]', 'Upload not confirmed.'); this.message('[data-template-upload-error]', text); this.q('[data-template-retry]').hidden = false; }
        };
        xhr.upload.addEventListener('progress', event => {
            if (settled || request !== this.uploadGeneration) return;
            if (event.lengthComputable) { progress.value = Math.round(100 * event.loaded / event.total); this.message('[data-template-upload-status]', progress.value === 100 ? 'Upload received; validating and re-encoding…' : 'Uploading image: ' + progress.value + '%'); }
            else progress.removeAttribute('value');
        });
        xhr.addEventListener('load', () => {
            if (settled || request !== this.uploadGeneration) return;
            const body = xhr.response;
            if (xhr.status < 200 || xhr.status >= 300 || !body?.asset) { finish(false, errors(body, 'The upload was not confirmed. Refresh the gallery before retrying; your template entries are unchanged.')); return; }
            try {
                const asset = this.asset(body.asset);
                this.assets.set(asset.id, asset); this.renderGallery();
                if (selection === this.selectionRevision) this.select(asset.id);
                if (this.q('[data-template-file]').files?.[0] === file) this.q('[data-template-file]').value = '';
                progress.value = 100;
                this.message('[data-template-upload-status]', 'Image uploaded and saved in the gallery. Choose Insert image when ready.');
                finish(true, ''); this.persist();
            } catch { finish(false, 'The uploaded image response could not be read. Refresh the gallery to find the saved image. Your template entries are unchanged.'); }
        });
        for (const event of ['error', 'timeout', 'abort']) xhr.addEventListener(event, () => finish(false,
            event === 'abort' ? 'Upload canceled. Your entries are unchanged. Refresh the gallery if the image had already reached the server.' : 'The upload could not be confirmed. Check your connection and refresh the gallery before retrying. Your entries are unchanged.'));
        xhr.open('POST', this.q('[data-template-upload]').formAction);
        xhr.setRequestHeader('Accept', 'application/json'); xhr.responseType = 'json'; xhr.timeout = 300000;
        const data = new FormData(); data.append('image', file); data.append('_token', this.q('[name=_asset_token]').value);
        try { xhr.send(data); } catch { finish(false, 'The upload could not start. Your entries are unchanged. Check the connection and try again.'); }
    }

    async save() {
        if (this.saving || this.uploading || this.recoveryPending) return;
        const snapshot = JSON.stringify(this.values());
        this.persist(); this.saving = true; this.controls(); this.message('[data-template-error]', '');
        const data = new FormData(this.form); data.delete('image');
        try {
            const response = await fetch(this.form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data });
            const body = await response.json();
            if (!response.ok || body.saved !== true) { this.message('[data-template-error]', errors(body, 'The save was not confirmed. Your entries are still here. Check Templates in another tab before retrying.')); return; }
            const redirect = new URL(body.redirect, location.href); const next = body.next_editor;
            if (redirect.origin !== location.origin || !/^\/admin\/templates\/[a-f0-9-]{36}\/versions$/.test(redirect.pathname)
                || !next || !UUID.test(next.id) || !['edit', 'version'].includes(next.kind) || next.key !== next.kind + '-' + next.id
                || typeof next.token !== 'string' || !next.token || next.token.length > 1024) throw new Error('Invalid save receipt.');
            const action = new URL(next.action, location.href); const url = new URL(next.url, location.href);
            if (action.origin !== location.origin || url.origin !== location.origin || !/^\/admin\/templates\/[a-f0-9-]{36}\/(?:edit|versions(?:\/create)?)$/.test(action.pathname)
                || !/^\/admin\/templates\/[a-f0-9-]{36}\/(?:edit|versions)$/.test(url.pathname)) throw new Error('Invalid continuation.');
            const previousKey = this.key;
            this.base = snapshot; this.forcedDirty = false;
            if (JSON.stringify(this.values()) === snapshot) {
                try { this.storage?.removeItem(previousKey); } catch { /* The server has confirmed this save. */ }
                this.leaving = true; this.controls(); location.assign(redirect.href); return;
            }
            // Typing during a slow save must never be replaced by its older reply.
            this.form.action = action.href; this.form.dataset.editorKey = next.key; this.key = this.prefix + next.key;
            this.q('[name=_token]').value = next.token; this.q('[name=_editor_kind]').value = next.kind; this.q('[name=_editor_id]').value = next.id;
            this.q('[data-template-save]').textContent = next.kind === 'version' ? 'Create new version' : 'Save template';
            history.replaceState(history.state, '', url.href);
            if (this.persist() && previousKey !== this.key) { try { this.storage?.removeItem(previousKey); } catch { /* Keep recovery local to this tab. */ } }
            this.message('[data-template-notice]', 'The submitted draft was saved. You have newer unsaved edits here; save again when ready.');
        } catch {
            this.message('[data-template-error]', 'The save could not be confirmed. Your entries are still here. Check your connection and open Templates in another tab before retrying.');
        } finally { this.saving = false; this.controls(); }
    }
}

for (const form of document.querySelectorAll('[data-template-editor]')) {
    try { if (!form.dataset.templateEnhanced) new TemplateEditor(form); }
    catch { const error = form.querySelector('[data-template-error]'); if (error) { error.textContent = 'The live editor could not start. Keep a copy of your entries before leaving. The server form will retain submitted entries.'; error.hidden = false; } }
}
