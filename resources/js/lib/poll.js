/**
 * Calls `task` now and then every `interval` ms, without overlapping calls.
 *
 * - pauses while the tab is hidden and refreshes at once when it comes back
 * - slows down after errors (x2 per failure, up to 60s) and recovers on success
 * - `interval` may be a function, so a page can poll fast while something runs
 *   and slowly when idle
 * - `immediate: false` waits one interval before the first call (the page came
 *   with its first data)
 *
 * Returns { stop(), now() }.
 */
export function poll(task, interval, { immediate = true } = {}) {
    let timer = null;
    let running = false;
    let stopped = false;
    let failures = 0;

    const delay = () => {
        const base = typeof interval === 'function' ? interval() : interval;
        return Math.min(base * 2 ** failures, 60000);
    };

    const schedule = () => {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(tick, delay());
    };

    const tick = async () => {
        if (running || stopped) return;
        running = true;
        try {
            await task();
            failures = 0;
        } catch (e) {
            if (e?.name !== 'AbortError') failures = Math.min(failures + 1, 5);
        } finally {
            running = false;
            schedule();
        }
    };

    const onVisibility = () => (document.hidden ? clearTimeout(timer) : tick());
    document.addEventListener('visibilitychange', onVisibility);

    if (immediate) tick();
    else schedule();

    return {
        now: tick,
        stop() {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        },
    };
}
