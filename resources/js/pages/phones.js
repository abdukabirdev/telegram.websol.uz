import { http } from '../lib/http';
import { poll } from '../lib/poll';

const FILTERS = ['search', 'state', 'user'];
const NO_FILTERS = { search: '', state: '', user: '' };

/**
 * Telegram accounts page: a table (rows on a phone) of the accounts this user
 * oversees, working ones first, the coloured edge of a row telling its group
 * (App\Enums\AccountState). Tabs by group with their counts, a search, an owner
 * for an admin; the filters live in the address (/phones?state=active). A row
 * opens the account's sheet with every action.
 *
 * The first page comes with the HTML (`initial`); then it polls every 1.5s while
 * an account on the page is in flight, every 15s otherwise. Connecting goes
 * through the shared wizard (resources/js/lib/connect-wizard.js), which reports
 * changes with the 'phone-changed' event.
 */
export default function phonesPage({ urls, t, initial }) {
    const url = (name, id) => urls[name].replace('__ID__', id);

    return {
        phones: initial.phones,
        meta: initial.meta,
        counts: initial.counts,
        dailyLimit: initial.daily_limit,
        filters: { ...NO_FILTERS, ...initial.filters },
        page: initial.meta.current_page,
        loading: false,
        busy: false,
        sheet: false,
        sheetId: null,
        seen: null, // the open account as last seen, once it is off the page
        controller: null,
        searchTimer: null,

        init() {
            this.poller = poll(() => this.load(), () => (this.anyInFlight ? 1500 : 15000), { immediate: false });
        },

        destroy() {
            this.poller?.stop();
            this.controller?.abort();
            clearTimeout(this.searchTimer);
        },

        get anyInFlight() {
            return this.phones.some((p) => p.in_flight);
        },

        get filtered() {
            return FILTERS.some((key) => this.filters[key] !== '');
        },

        /** The account of the open sheet, fresh from the last load. */
        get current() {
            return this.phones.find((p) => p.id === this.sheetId) ?? this.seen;
        },

        async load() {
            this.controller?.abort();
            const controller = (this.controller = new AbortController());
            const data = await http.get(urls.data, { ...this.filters, page: this.page }, { signal: controller.signal });

            this.phones = data.phones;
            this.meta = data.meta;
            this.counts = data.counts;
            this.dailyLimit = data.daily_limit;
            this.loading = false;
            this.seen = this.current;

            // the list shrank under us (filters, removals): go to the last page that exists
            if (this.page > data.meta.last_page) {
                this.page = data.meta.last_page;
                this.refresh();
            }
        },

        /** load() now, outside the poller: it aborts a tick in flight instead of being skipped by it. */
        reload() {
            this.load().catch((e) => {
                if (e?.name === 'AbortError') return;
                this.loading = false;
                this.$store.toast.error(e.message);
            });
        },

        /** Writes the filters into the address bar and reloads the list. */
        refresh() {
            const params = new URLSearchParams();
            for (const key of FILTERS) if (this.filters[key] !== '') params.set(key, this.filters[key]);
            if (this.page > 1) params.set('page', this.page);
            const qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? `?${qs}` : ''));

            this.loading = true;
            this.reload();
        },

        apply() {
            clearTimeout(this.searchTimer);
            this.page = 1;
            this.refresh();
        },

        searchChanged() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.apply(), 300);
        },

        setState(state) {
            this.filters.state = state;
            this.apply();
        },

        reset() {
            this.filters = { ...NO_FILTERS };
            this.apply();
        },

        go(page) {
            this.page = Math.min(Math.max(1, page), this.meta.last_page);
            this.refresh();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        open(phone) {
            this.sheetId = phone.id;
            this.seen = phone;
            this.sheet = true;
        },

        // ------------------------------------------------------------ actions

        async check(phone) {
            if (await this.$act(() => http.post(url('check', phone.id)))) this.reload();
        },

        async relogin(phone) {
            const result = await this.$act(() => http.post(url('relogin', phone.id)), { quiet: true });
            if (!result) return;
            this.sheet = false;
            this.$dispatch('connect-phone', { account: result.data });
        },

        /** A login that waits for a code or a password: back into the wizard. */
        continueLogin(phone) {
            this.sheet = false;
            this.$dispatch('connect-phone', { account: phone });
        },

        async logout(phone) {
            const ok = await this.$store.confirm.ask({ title: t.logout_title, body: t.logout_body.replace(':phone', phone.phone), action: t.logout });
            if (ok && (await this.$act(() => http.post(url('logout', phone.id))))) this.reload();
        },

        async remove(phone) {
            const ok = await this.$store.confirm.ask({ title: t.remove_title, body: t.remove_body.replace(':phone', phone.phone), action: t.remove });
            if (!ok || !(await this.$act(() => http.delete(url('destroy', phone.id))))) return;
            this.sheet = false;
            this.reload();
        },

        sendUrl(phone) {
            return `${urls.send}?phone=${phone.id}`;
        },

        // ------------------------------------------------------------ display

        /** The name and @username under the number, each only when it says something new. */
        subtitle(phone) {
            const username = phone.username ? `@${phone.username}` : '';
            const name = phone.name !== phone.phone && phone.name !== username ? phone.name : '';

            return [name, username].filter(Boolean).join(' · ');
        },

        /** Authorized: sends now or after Telegram's pause. */
        working(phone) {
            return phone.state === 'active' || phone.state === 'paused';
        },

        ownerName(phone) {
            return phone.is_mine ? t.me : (phone.owner?.name ?? '');
        },

        /** Today's sends against the daily limit, 0..100. */
        usage(phone) {
            return this.dailyLimit ? Math.min(100, Math.round(((phone.sent_today ?? 0) / this.dailyLimit) * 100)) : 0;
        },

        usageBar(phone) {
            const usage = this.usage(phone);
            return usage >= 100 ? 'bg-error-500' : usage >= 85 ? 'bg-warning-500' : 'bg-brand-500';
        },

        limitReached(phone) {
            return this.dailyLimit > 0 && (phone.sent_today ?? 0) >= this.dailyLimit;
        },

        /** One line under the state: why the account is not sending, if it is not. */
        note(phone) {
            if (phone.state === 'paused') return t.resumes.replace(':time', this.$fmt.relative(phone.flood_until));
            if (phone.state === 'active') return this.limitReached(phone) ? t.limit_reached : '';
            if (phone.state === 'off') return '';

            return phone.last_error_text ?? '';
        },

        noteTone(phone) {
            return phone.state === 'paused' ? 'text-warning-700 dark:text-warning-300' : 'text-error-600 dark:text-error-400';
        },
    };
}
