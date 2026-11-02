/**
 * Dialogs on a phone: the page behind stays still and the dialog stays in the
 * part of the screen the user actually sees.
 *
 * - `x-lock-scroll="open"` freezes the page scroll while `open` is true (several
 *   dialogs at once are counted, the last one to close unlocks).
 * - `--vv-top` / `--vv-h` on <html> follow the visual viewport: when the on-screen
 *   keyboard opens, the browser shrinks or pans it, and `.c-modal` (fixed to these
 *   variables) sits right above the keyboard instead of jumping under it.
 */
let locks = 0;

function syncViewport() {
    const vv = window.visualViewport;
    const root = document.documentElement.style;
    root.setProperty('--vv-top', `${vv ? vv.offsetTop : 0}px`);
    root.setProperty('--vv-h', `${vv ? vv.height : window.innerHeight}px`);
}

let frame = 0;
const onViewport = () => {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(syncViewport);
};

function lock() {
    if (locks++ === 0) {
        syncViewport();
        document.documentElement.classList.add('c-locked');
        window.visualViewport?.addEventListener('resize', onViewport);
        window.visualViewport?.addEventListener('scroll', onViewport);
        window.addEventListener('resize', onViewport);
    }
}

function unlock() {
    if (locks === 0 || --locks > 0) return;
    document.documentElement.classList.remove('c-locked');
    window.visualViewport?.removeEventListener('resize', onViewport);
    window.visualViewport?.removeEventListener('scroll', onViewport);
    window.removeEventListener('resize', onViewport);
}

/** Touch screens: focusing a field opens the keyboard, so the user taps it themselves. */
export const touch = () => window.matchMedia('(pointer: coarse)').matches;

export function registerViewport(Alpine) {
    syncViewport();

    Alpine.directive('lock-scroll', (el, { expression }, { evaluateLater, effect, cleanup }) => {
        const isOpen = evaluateLater(expression);
        let locked = false;

        effect(() =>
            isOpen((open) => {
                if (open && !locked) lock();
                if (!open && locked) unlock();
                locked = Boolean(open);
            }),
        );

        cleanup(() => locked && unlock());
    });
}
