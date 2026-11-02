import { http, HttpError } from './http';
import { poll } from './poll';
import { touch } from './viewport';

/**
 * The connect wizard (resources/views/phones/connect-wizard.blade.php), the same on
 * the accounts page and the users page.
 *
 * It follows the account's status (see App\Enums\AccountStatus):
 *   phone step -> requesting_code -> code step (code_sent / code_invalid)
 *   -> verifying -> password step (need_password / password_invalid) -> authorized
 * polling the account every 1.5s while a step is in flight, every 5s otherwise.
 *
 * Opened by a window event: $dispatch('connect-phone', { owner, phone, account })
 * (`account` continues a login that waits for a code or a password), or by
 * `#connect` in the address (`?owner=ID` picks whose account it is).
 * Announces every change with a 'phone-changed' window event, so the page
 * refreshes its own list.
 *
 * An admin picks the owner on the phone step (`owners`): an employee's phone is
 * connected for them, the code comes from the employee.
 */
export function connectWizard({ urls, t, me, owners }) {
    const url = (name, id) => urls[name].replace('__ID__', id);

    return {
        wizard: false,
        step: 'phone', // phone | code | password | done
        me,
        owners,
        ownerId: me,
        pickingOwner: false,
        ownerSearch: '',
        phoneInput: '',
        code: '',
        password: '',
        account: null,
        error: null,
        busy: false,
        resendAt: 0,
        now: Date.now(),

        init() {
            this.$watch('wizard', (open) => open || this.stop());

            if (window.location.hash === '#connect') {
                const address = new URL(window.location.href);
                this.open({ owner: Number(address.searchParams.get('owner')) || null });
                address.hash = '';
                address.searchParams.delete('owner');
                history.replaceState(null, '', address);
            }
        },

        destroy() {
            this.stop();
        },

        open({ owner = null, phone = null, account = null } = {}) {
            if (account) this.resume(account);
            else this.start(phone, owner);
        },

        get owner() {
            return owners.find((o) => o.id === this.ownerId) ?? null;
        },

        get ownerName() {
            return this.owner?.name ?? this.account?.owner?.name ?? '';
        },

        get filteredOwners() {
            const q = this.ownerSearch.trim().toLowerCase();

            return q ? owners.filter((o) => `${o.name} ${o.email}`.toLowerCase().includes(q)) : owners;
        },

        ownerLabel(owner) {
            if (!owner) return '';
            return owner.id === me ? t.owner_me.replace(':name', owner.name) : owner.name;
        },

        ownerPhones(owner) {
            return t.owner_phones.replace(':n', owner.phones);
        },

        get sendUrl() {
            return `${urls.send}?phone=${this.account?.id}`;
        },

        get resendIn() {
            return Math.max(0, Math.ceil((this.resendAt - this.now) / 1000));
        },

        // ------------------------------------------------------------ flow

        /** An admin starts by choosing the owner, unless one is given (the users page). */
        start(phone = null, ownerId = null) {
            const preset = owners.some((o) => o.id === ownerId);
            Object.assign(this, { wizard: true, step: 'phone', code: '', password: '', error: null, account: null, phoneInput: phone ?? '' });
            Object.assign(this, { ownerId: preset ? ownerId : me, pickingOwner: false });
            this.tick();
            if (owners.length > 1 && !preset) this.pickOwner();
            else this.focus('phoneInput');
        },

        resume(account) {
            Object.assign(this, { wizard: true, step: 'phone', code: '', password: '', error: null, pickingOwner: false });
            this.ownerId = account.owner?.id ?? me;
            this.phoneInput = account.phone;
            this.account = null;
            this.follow(account);
            this.tick();
            this.watch();
        },

        pickOwner() {
            if (this.account) return;
            this.pickingOwner = true;
            this.ownerSearch = '';
            this.focus('ownerSearch');
        },

        chooseOwner(owner) {
            this.ownerId = owner.id;
            this.pickingOwner = false;
            this.focus('phoneInput');
        },

        /** Moves the wizard to the step the account's status asks for. */
        follow(account) {
            const before = this.account?.status;
            this.account = account;

            if (account.status === 'authorized') {
                this.step = 'done';
                if (before && before !== 'authorized') this.$store.toast.show(t.connected);
            } else if (account.awaits === 'code') {
                if (this.step !== 'code') {
                    this.step = 'code';
                    this.resendAt = Date.now() + 60000;
                    this.focus('codeInput');
                }
                this.error = account.status === 'code_invalid' ? account.last_error_text : null;
            } else if (account.awaits === 'password') {
                if (this.step !== 'password') {
                    this.step = 'password';
                    this.focus('passwordInput');
                }
                this.error = account.status === 'password_invalid' || account.last_error ? account.last_error_text : null;
            } else if (account.status === 'failed') {
                this.step = 'phone';
                this.error = account.last_error_text ?? t.failed;
            }

            if (before !== account.status) this.changed();
        },

        async submitPhone() {
            this.error = null;
            const account = await this.send(() => http.post(urls.store, { phone: this.phoneInput, user_id: this.ownerId }));
            if (account) this.accept(account.data);
        },

        async submitCode() {
            this.error = null;
            const account = await this.send(() => http.post(url('code', this.account.id), { code: this.code }));
            if (account) {
                this.code = '';
                this.accept(account.data);
            }
        },

        async submitPassword() {
            this.error = null;
            const account = await this.send(() => http.post(url('password', this.account.id), { password: this.password }));
            this.password = '';
            if (account) this.accept(account.data);
        },

        async resend() {
            if (this.resendIn > 0 || !this.account) return;
            this.step = 'phone';
            const account = await this.send(() => http.post(url('relogin', this.account.id)));
            if (account) this.accept(account.data);
        },

        async cancel() {
            if (this.account && this.account.status !== 'authorized' && !this.account.in_flight) {
                await this.send(() => http.post(url('cancel', this.account.id)));
            }
            this.wizard = false;
            this.changed();
        },

        // --------------------------------------------------------- plumbing

        /** A step was accepted by the server: the job runs, the account is polled. */
        accept(account) {
            this.account = account;
            this.changed();
            this.watch();
        },

        watch() {
            this.poller?.stop();
            this.poller = poll(async () => {
                if (!this.wizard || !this.account || this.step === 'done') return this.poller?.stop();
                this.follow((await http.get(url('show', this.account.id))).data);
            }, () => (this.account?.in_flight ? 1500 : 5000));
        },

        /** The resend countdown runs only while the wizard is open. */
        tick() {
            clearInterval(this.clock);
            this.now = Date.now();
            this.clock = setInterval(() => (this.now = Date.now()), 1000);
        },

        stop() {
            this.poller?.stop();
            this.poller = null;
            clearInterval(this.clock);
        },

        changed() {
            this.$dispatch('phone-changed', { account: this.account, owner: this.ownerId });
        },

        /** On a phone the keyboard opens only when they tap the field: no jumping sheet. */
        focus(ref) {
            if (!touch()) this.$nextTick(() => this.$refs[ref]?.focus());
        },

        /** Runs a request, puts a failure into the wizard's error line. */
        async send(fn) {
            this.busy = true;
            try {
                return await fn();
            } catch (e) {
                this.error = e instanceof HttpError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t.failed;
                return null;
            } finally {
                this.busy = false;
            }
        },
    };
}
