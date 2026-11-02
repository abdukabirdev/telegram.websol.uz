/**
 * Registers every page component found in this folder: the default export of
 * `catalogs.js` becomes x-data="catalogsPage(...)", `send-show.js` becomes
 * "sendShowPage", and so on. Adding a page = adding a file.
 */
import { connectWizard } from '../lib/connect-wizard';
import { http } from '../lib/http';
import { poll } from '../lib/poll';

const modules = import.meta.glob(['./*.js', '!./index.js'], { eager: true });

const camel = (file) =>
    file
        .replace(/^\.\//, '')
        .replace(/\.js$/, '')
        .replace(/-(\w)/g, (_, c) => c.toUpperCase());

/** Top bar counters: active sends, open problems. */
function pulse(url) {
    return {
        active: 0,
        problems: 0,
        init() {
            this.poller = poll(async () => Object.assign(this, await http.get(url)), () => (this.active > 0 ? 8000 : 20000));
        },
        destroy() {
            this.poller?.stop();
        },
    };
}

export function registerPages(Alpine) {
    Alpine.data('pulse', pulse);
    Alpine.data('connectWizard', connectWizard);
    for (const [file, module] of Object.entries(modules)) {
        Alpine.data(`${camel(file)}Page`, module.default);
    }
}
