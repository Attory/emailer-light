// Credentials stay in this form's memory. Never persist or echo them.
const editor = document.querySelector('[data-mail-settings-editor]');
if (editor) {
    const test = editor.querySelector('[data-mail-connection-test]');
    const status = editor.querySelector('[data-mail-connection-result]');
    const password = editor.elements.namedItem('password');
    let revision = 0;
    let requestGeneration = 0;
    ['input', 'change'].forEach(type => editor.addEventListener(type, () => { revision += 1; }));
    const updatePasswordRequirement = () => {
        password.required = !(editor.dataset.savedCredential === 'true' && editor.elements.namedItem('host').value.trim().toLowerCase() === editor.dataset.savedHost && editor.elements.namedItem('username').value.trim() === editor.dataset.savedUsername);
    };
    ['host', 'username'].forEach(name => editor.elements.namedItem(name).addEventListener('input', updatePasswordRequirement));
    updatePasswordRequirement();
    const perform = async (test, body, target) => {
        if (test.disabled) return;
        if (target.origin !== location.origin) return;
        const generation = ++requestGeneration;
        const testedRevision = revision;
        const isCurrent = body.get('_configuration_source') === 'current';
        test.disabled = true;
        test.setAttribute('aria-busy', 'true');
        status.hidden = false;
        status.setAttribute('role', 'status');
        status.classList.remove('alert');
        status.textContent = body.get('_configuration_source') === 'saved' ? 'Testing saved SMTP settings securely. No email will be sent…' : 'Testing the current SMTP fields securely. No email will be sent…';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 45000);
        try {
            const response = await fetch(target, {method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (response.status === 503) throw new Error(response.headers.get('X-Emailer-Update-State') === 'in-progress' ? 'maintenance' : 'unavailable');
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('session');
            const result = await response.json();
            if (typeof result.success !== 'boolean' || typeof result.message !== 'string') throw new Error('invalid');
            if (generation !== requestGeneration) return;
            if (isCurrent && testedRevision !== revision) {
                status.textContent = result.success
                    ? 'Earlier form values tested successfully. You changed the form during the test; test the current entries before saving.'
                    : 'The earlier form values failed their test: ' + result.message + ' Your current entries are retained; test them again.';
                status.dataset.category = 'earlier_values';
                status.classList.toggle('alert', !result.success);
                status.setAttribute('role', result.success ? 'status' : 'alert');
                return;
            }
            status.textContent = result.message;
            status.dataset.category = result.category;
            status.classList.toggle('alert', !result.success);
            status.setAttribute('role', result.success ? 'status' : 'alert');
        } catch (error) {
            if (generation !== requestGeneration) return;
            status.classList.add('alert'); status.setAttribute('role', 'alert');
            status.textContent = error.message === 'maintenance'
                ? 'Emailer is applying an update. Retry after it finishes; your entries are retained. No email was sent.'
                : error.message === 'session'
                    ? 'Sign in or refresh Mail settings in another tab, then retry. Your entries are retained and have not been saved.'
                    : 'The connection-test response could not be confirmed. Your entries are retained; retry when this server is reachable. No email was sent.';
        } finally {
            clearTimeout(timer);
            test.disabled = false;
            test.removeAttribute('aria-busy');
            body.delete('password');
        }
    };
    editor.addEventListener('submit', event => {
        if (event.submitter !== test) return;
        event.preventDefault();
        const body = new URLSearchParams({_configuration_source: 'current'});
        ['_connection_token', 'host', 'port', 'encryption', 'username', 'password', 'sender_address', 'sender_name', 'reply_to', 'one_click_dkim_confirmed'].forEach(name => {
            const input = editor.elements.namedItem(name);
            if (input && (input.type !== 'checkbox' || input.checked)) body.set(name, input.value);
        });
        perform(test, body, new URL(test.formAction, location.href));
    });
    const saved = document.querySelector('[data-mail-saved-test]');
    saved?.addEventListener('submit', event => {
        event.preventDefault();
        const body = new URLSearchParams({_configuration_source: 'saved', _token: saved.elements.namedItem('_token').value});
        perform(saved.querySelector('button'), body, new URL(saved.action, location.href));
    });
}
