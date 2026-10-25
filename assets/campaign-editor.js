// Progressive enhancement only. Authorization, incomplete state and delivery gates stay on the server.
for (const form of document.querySelectorAll('[data-campaign-editor]')) {
    const fieldset = form.querySelector('[data-campaign-fields]');
    const link = form.querySelector('[data-campaign-templates]');
    const dialog = form.querySelector('[data-campaign-leave]');
    const error = form.querySelector('[data-campaign-error]');
    const status = form.querySelector('[data-campaign-status]');
    const refreshButton = form.querySelector('[data-campaign-refresh-security]');
    let saving = false, leaving = false, refreshing = false;
    const modes = () => {
        const selected = form.elements.namedItem('audience_mode')?.value;
        if (!selected) return;
        for (const group of form.querySelectorAll('[data-campaign-audience]')) {
            const active = group.dataset.campaignAudience === selected;
            group.hidden = !active;
            for (const input of group.querySelectorAll('input,select')) input.disabled = !active;
        }
    };
    modes();
    const fingerprint = () => JSON.stringify([...new FormData(form)].filter(([key]) => key !== '_token' && key !== '_destination').map(([key, value]) =>
        [key, value instanceof File ? [value.name, value.size, value.name ? value.lastModified : 0] : value]));
    let base = fingerprint();
    const dirty = () => form.dataset.campaignUnsaved === 'true' || fingerprint() !== base;
    const fail = message => {
        refreshButton.hidden = false;
        error.textContent = message; error.hidden = false; error.tabIndex = -1; error.focus();
        status.textContent = 'Draft not confirmed. Your campaign fields and selected CSV remain here.';
    };
    const go = (href, replace = false) => { leaving = true; if (replace) location.replace(href); else location.assign(href); };
    const save = async destination => {
        if (saving) return;
        dialog.close();
        const data = new FormData(form);
        data.set('_destination', destination);
        saving = true; fieldset.disabled = true; form.setAttribute('aria-busy', 'true');
        error.hidden = true; status.textContent = 'Saving campaign draft… Nothing will be sent.';
        try {
            const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: data });
            const body = await response.json();
            if (!response.ok || body.saved !== true || typeof body.redirect !== 'string') {
                fail(Array.isArray(body.errors) && typeof body.errors[0] === 'string' ? body.errors[0].slice(0, 1400) : 'The draft was not saved. Check your session and try again. Your entries and CSV are retained.'); return;
            }
            const target = new URL(body.redirect, location.href);
            if (target.origin !== location.origin || !/\/admin\/(campaigns|templates)(\/|\?|$)/.test(target.pathname + target.search)) throw new Error('Invalid save receipt.');
            status.textContent = 'Campaign draft saved. Nothing was published, approved or sent.';
            const campaign = new URL(body.campaign_url, location.href);
            if (campaign.origin !== location.origin || !/\/admin\/campaigns\/[a-f0-9-]{36}\/edit$/.test(campaign.pathname)) throw new Error('Invalid campaign continuation.');
            if (destination === 'templates') {
                // Back must reopen the confirmed draft, never resubmit an old Create form.
                form.dataset.confirmedCampaignUrl = campaign.href;
                history.replaceState(history.state, '', campaign.href);
                go(target.href);
            } else go(target.href, true);
        } catch {
            fail('The save could not be confirmed. Your entries and selected CSV are still here. Check Campaigns in another tab before retrying, in case the request was saved. Nothing was sent.');
        } finally {
            saving = false; fieldset.disabled = false; form.removeAttribute('aria-busy'); modes();
        }
    };
    refreshButton.addEventListener('click', async () => {
        if (saving || refreshing) return;
        refreshing = true; refreshButton.disabled = true;
        try {
            const response = await fetch(form.dataset.campaignRecoveryUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store' });
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const expected = new URL(form.action, location.href);
            const fresh = [...page.querySelectorAll('[data-campaign-editor]')].find(candidate => new URL(candidate.action, location.href).pathname === expected.pathname);
            const token = fresh?.querySelector('[name=_token]')?.value;
            if (!response.ok || !token) {
                fail('Sign in in another tab first, then choose Refresh security check here. Your fields and selected CSV are kept.'); return;
            }
            form.elements.namedItem('_token').value = token;
            error.hidden = true; refreshButton.hidden = true;
            status.textContent = 'Security check refreshed. Your fields and selected CSV are kept. Choose Save draft to retry.';
        } catch { fail('The security check could not be refreshed. Check your connection and try again. Your entries and selected CSV are kept.'); }
        finally { refreshing = false; refreshButton.disabled = false; }
    });
    form.noValidate = true; // Incomplete drafts are deliberate; server validates every supplied value.
    form.addEventListener('change', modes);
    form.addEventListener('submit', event => { event.preventDefault(); save('campaign'); });
    link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        if (saving) return;
        if (!dirty()) go(link.href); else dialog.showModal();
    });
    form.querySelector('[data-campaign-save-open]').addEventListener('click', () => save('templates'));
    form.querySelector('[data-campaign-discard]').addEventListener('click', () => { dialog.close(); go(link.href); });
    form.querySelector('[data-campaign-stay]').addEventListener('click', () => { dialog.close(); link.focus(); });
    window.addEventListener('beforeunload', event => {
        if (!leaving && (saving || dirty())) { event.preventDefault(); event.returnValue = ''; }
    });
    let refreshingChoices = false;
    const refreshChoices = async () => {
        if (refreshingChoices || saving || leaving || dialog.open) return;
        const button = form.querySelector('[data-campaign-refresh-choices]');
        const note = form.querySelector('[data-campaign-choice-status]');
        refreshingChoices = true; button.disabled = true;
        try {
            const response = await fetch(form.dataset.campaignRecoveryUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Choices unavailable.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = page.querySelector('[data-campaign-editor] [name=template_version]');
            const select = form.elements.namedItem('template_version');
            if (!fresh || !select) throw new Error('Choices unavailable.');
            if (saving || leaving) return;
            // Read the selection after the response; preserve edits made while fetching.
            const selected = select.value;
            const old = select.selectedOptions[0]?.cloneNode(true);
            select.replaceChildren(...[...fresh.options].map(option => option.cloneNode(true)));
            if (selected && ![...select.options].some(option => option.value === selected) && old) select.append(old);
            select.value = selected; note.textContent = 'Template choices refreshed. Your campaign fields and selected CSV are kept.';
        } catch { note.textContent = 'Template choices could not be refreshed. Sign in again if needed, then try Refresh template choices. Your fields and selected CSV are kept.'; }
        finally { refreshingChoices = false; button.disabled = false; }
    };
    form.querySelector('[data-campaign-refresh-choices]').addEventListener('click', refreshChoices);
    window.addEventListener('focus', refreshChoices);
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        if (form.dataset.confirmedCampaignUrl) { go(form.dataset.confirmedCampaignUrl, true); return; }
        leaving = false; refreshChoices();
    });
    form.dataset.campaignEnhanced = 'true';
}
