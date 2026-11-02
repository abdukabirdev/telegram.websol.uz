import { http } from '../lib/http';

/**
 * Users list: search as you type (name, email, or the digits of a number), pages,
 * delete. "Connect a number" opens the connect wizard right here
 * (resources/js/lib/connect-wizard.js) with the user as the owner; the list
 * refreshes its account counts when the wizard reports a change.
 * The first page comes with the HTML (`initial`).
 */
export default function usersPage({ urls, t, initial }) {
    const url = (name, id) => urls[name].replace('__ID__', id);

    return {
        users: initial.users,
        meta: initial.meta,
        search: new URLSearchParams(window.location.search).get('search') ?? '',
        page: initial.meta.current_page,
        loading: false,
        controller: null,
        searchTimer: null,

        init() {
            this.$watch('search', () => {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => this.go(1), 300);
            });
        },

        async load() {
            clearTimeout(this.searchTimer);
            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;
            try {
                const data = await http.get(urls.data, { search: this.search.trim(), page: this.page }, { signal: this.controller.signal });
                this.users = data.users;
                this.meta = data.meta;
                this.loading = false;
                this.syncUrl();
            } catch (e) {
                if (e?.name === 'AbortError') return;
                this.loading = false;
                this.$store.toast.error(e.message);
            }
        },

        syncUrl() {
            const params = new URLSearchParams();
            if (this.search.trim()) params.set('search', this.search.trim());
            if (this.page > 1) params.set('page', this.page);
            const qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? `?${qs}` : ''));
        },

        go(page) {
            this.page = Math.max(1, page);
            this.load();
        },

        editUrl(user) {
            return url('edit', user.id);
        },

        connect(user) {
            this.$dispatch('connect-phone', { owner: user.id });
        },

        async remove(user) {
            const ok = await this.$store.confirm.ask({ title: t.delete_title, body: t.delete_body.replace(':name', user.name), action: t.delete });
            if (ok && (await this.$act(() => http.delete(url('destroy', user.id))))) {
                if (this.users.length === 1 && this.page > 1) this.page--;
                this.load();
            }
        },
    };
}
