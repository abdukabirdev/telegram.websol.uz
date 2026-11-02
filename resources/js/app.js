import Alpine from 'alpinejs';
import { http, HttpError } from './lib/http';
import { poll } from './lib/poll';
import * as format from './lib/format';
import { registerStores } from './lib/stores';
import { registerViewport } from './lib/viewport';
import { registerPages } from './pages';

window.Alpine = Alpine;
window.http = http;

registerStores(Alpine);
registerViewport(Alpine);

/** $fmt.number(…), $fmt.relative(…) etc. in every template. */
Alpine.magic('fmt', () => format);

/**
 * Runs an async action with a busy flag, shows its message as a toast, and
 * returns the response (or null on failure, after showing the error).
 *   await $act(() => http.post(url), { busy: 'saving' })
 */
Alpine.magic('act', (el) => async (fn, { busy = 'busy', quiet = false } = {}) => {
    const data = Alpine.$data(el);
    data[busy] = true;
    try {
        const result = await fn();
        if (!quiet && result?.message) Alpine.store('toast').show(result.message);
        return result;
    } catch (e) {
        if (e instanceof HttpError) {
            const first = Object.values(e.errors ?? {})[0]?.[0];
            Alpine.store('toast').error(first ?? e.message);
        } else {
            throw e;
        }
        return null;
    } finally {
        data[busy] = false;
    }
});

/** Copy text to the clipboard with a toast. */
Alpine.magic('copy', () => async (text) => {
    try {
        await navigator.clipboard.writeText(String(text));
        Alpine.store('toast').show(window.__t?.copied ?? 'Copied', 'neutral', 2000);
    } catch {
        Alpine.store('toast').error(window.__t?.copy_failed ?? 'Copy failed');
    }
});

window.poll = poll;
registerPages(Alpine);

// Global shortcuts: Ctrl/⌘+K opens the palette, "/" focuses the page search.
document.addEventListener('keydown', (e) => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        Alpine.store('palette').show();
    } else if (e.key === '/' && !typing) {
        const search = document.querySelector('[data-page-search]');
        if (search) {
            e.preventDefault();
            search.focus();
        }
    }
});

Alpine.start();
