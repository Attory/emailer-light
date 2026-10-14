const panel = document.querySelector('[data-campaign-progress]');
if (panel) {
    let timer;
    let active = true;
    let busy = false;
    let delay = 5000;
    let confirmed = '';
    const feedback = panel.querySelector('[data-progress-feedback]');
    const countKeys = [...panel.querySelectorAll('[data-count]')].map(node => node.dataset.count);
    const initialState = panel.dataset.initialState;
    const poll = async () => {
        if (document.hidden || busy || !active) return;
        busy = true;
        const controller = new AbortController();
        const abortTimer = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(panel.dataset.progressUrl, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('unavailable');
            const data = await response.json();
            if (typeof data.state !== 'string' || !Number.isInteger(data.percent) || data.percent < 0 || data.percent > 100 || !Number.isInteger(data.total) || data.total < 0 || !Number.isInteger(data.processed) || data.processed < 0 || data.processed > data.total || !countKeys.every(key => Number.isInteger(data.counts?.[key]) && data.counts[key] >= 0)) throw new Error('invalid');
            panel.querySelector('[data-processed]').textContent = data.processed;
            panel.querySelector('[data-total]').textContent = data.total;
            panel.querySelector('[data-percent]').textContent = data.percent;
            panel.querySelector('progress').value = data.percent;
            panel.querySelector('progress').textContent = `${data.percent}%`;
            countKeys.forEach(key => panel.querySelector(`[data-count="${key}"]`).textContent = data.counts[key]);
            const state = document.querySelector('[data-campaign-state]');
            state.textContent = data.state.replaceAll('_', ' ');
            state.dataset.state = data.state;
            const test = document.querySelector('[data-test-state]');
            if (test && typeof data.test_state === 'string') test.textContent = data.test_state;
            const fingerprint = JSON.stringify([data.state, data.total, data.processed, data.test_state, data.counts]);
            const message = data.state !== initialState
                ? 'Campaign state changed. Refresh the page to see available actions.'
                : `${data.processed} of ${data.total} processed. Latest test: ${data.test_state}.`;
            if (fingerprint !== confirmed && feedback.textContent !== message) feedback.textContent = message;
            confirmed = fingerprint;
            panel.querySelector('[data-progress-updated]').textContent = `Updated ${new Date().toLocaleTimeString()}`;
            delay = ['draft', 'review'].includes(data.state) && !['pending', 'processing'].includes(data.test_state) ? 30000 : 5000;
            if (['completed', 'cancelled', 'failed'].includes(data.state)) active = false;
        } catch {
            const message = 'Live update unavailable. Counts shown are the last confirmed values. Refresh the page or sign in again.';
            if (feedback.textContent !== message) feedback.textContent = message;
        } finally {
            clearTimeout(abortTimer);
            busy = false;
            if (active && !document.hidden) timer = setTimeout(poll, delay);
        }
    };
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden && active && !busy) poll();
    });
    window.addEventListener('pagehide', () => { active = false; clearTimeout(timer); });
    poll();
}
