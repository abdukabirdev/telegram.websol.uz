{{--
    The connect wizard (phone -> code -> 2FA password), on the accounts page and the users page.
    Open it with $dispatch('connect-phone', { owner: userId }) or { account } to continue a login;
    it announces changes with the 'phone-changed' window event. Logic: resources/js/lib/connect-wizard.js.
    Needs $owners (App\Queries\PhoneOwners): whom this user may connect a phone for.
--}}
<div x-data="connectWizard({
        urls: @js([
            'store' => route('phones.store'),
            'show' => route('phones.show', '__ID__'),
            'code' => route('phones.code', '__ID__'),
            'password' => route('phones.password', '__ID__'),
            'relogin' => route('phones.relogin', '__ID__'),
            'cancel' => route('phones.cancel', '__ID__'),
            'send' => route('send.create'),
        ]),
        t: @js(__('phones.js')),
        me: @js(auth()->id()),
        owners: @js($owners),
    })" @connect-phone.window="open($event.detail)">
    <x-ui.modal show="wizard" :title="__('phones.connect')" width="max-w-md">
        {{-- Steps --}}
        <ol class="mb-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium">
            @foreach (['phone' => __('phones.step_phone'), 'code' => __('phones.step_code'), 'password' => __('phones.step_password')] as $key => $label)
                <li class="flex items-center gap-2" :class="['phone','code','password','done'].indexOf(step) >= {{ $loop->index }} ? 'text-gray-950 dark:text-white' : 'c-muted'">
                    <span class="grid h-5 w-5 place-items-center rounded-full border text-[10px]"
                          :class="['phone','code','password','done'].indexOf(step) > {{ $loop->index }} ? 'border-brand-500 bg-brand-500 text-white' : 'border-current'">{{ $loop->iteration }}</span>
                    {{ $label }}
                    @unless ($loop->last)<span class="c-muted">→</span>@endunless
                </li>
            @endforeach
        </ol>

        {{-- Connecting for someone else (an admin for an employee) --}}
        <p x-show="step !== 'phone' && ownerId !== me" x-cloak class="c-muted mb-4 text-[13px]">
            {{ __('phones.owner_label') }}: <span class="font-semibold text-gray-900 dark:text-white" x-text="ownerName"></span>
        </p>

        {{-- Working... --}}
        <div x-show="account?.in_flight" class="mb-4 flex items-center gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-[13px] text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
            <span class="c-dot is-live text-brand-500"></span>
            <span x-text="account?.status === 'requesting_code' ? @js(__('phones.requesting_code')) : @js(__('phones.verifying'))"></span>
        </div>

        <p x-show="error" x-cloak class="mb-4 rounded-2xl bg-error-50 px-4 py-3 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-300" x-text="error"></p>

        {{-- 1. phone --}}
        <form x-show="step === 'phone' && !account?.in_flight" @submit.prevent="submitPhone()" class="space-y-4">
            @if ($owners->count() > 1)
                {{-- Whose account: the chosen owner, or the searchable list while picking --}}
                <div>
                    <span class="c-label">{{ __('phones.owner_label') }}</span>
                    <button type="button" x-show="!pickingOwner" @click="pickOwner()" :disabled="!!account"
                            class="flex w-full items-center gap-3 rounded-xl border border-[var(--line)] px-3 py-2.5 text-left hover:bg-[var(--surface-2)] disabled:cursor-default">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="owner?.initials"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="ownerLabel(owner)"></span>
                            <span class="c-muted block truncate text-xs" x-text="owner?.email"></span>
                        </span>
                        <span x-show="!account" class="shrink-0 text-xs font-medium text-brand-600 dark:text-brand-300">{{ __('phones.owner_change') }}</span>
                    </button>

                    <div x-show="pickingOwner" x-cloak>
                        <div class="relative mb-2">
                            <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                            <input type="search" x-ref="ownerSearch" x-model="ownerSearch" class="c-input pl-9" placeholder="{{ __('phones.owner_search') }}"
                                   autocomplete="off" enterkeyhint="search" @keydown.enter.prevent="filteredOwners.length === 1 && chooseOwner(filteredOwners[0])">
                        </div>
                        <ul class="max-h-[min(18rem,45dvh)] divide-y divide-[var(--line)] overflow-y-auto overscroll-contain rounded-xl border border-[var(--line)]">
                            <template x-for="o in filteredOwners" :key="o.id">
                                <li>
                                    <button type="button" @click="chooseOwner(o)" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-[var(--surface-2)]"
                                            :class="o.id === ownerId && 'bg-brand-50/60 dark:bg-brand-500/10'">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="o.initials"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="ownerLabel(o)"></span>
                                            <span class="c-muted block truncate text-xs" x-text="o.email"></span>
                                        </span>
                                        <span x-show="o.phones" class="c-muted shrink-0 text-[11px] tabular-nums" x-text="ownerPhones(o)"></span>
                                        <x-ui.icon name="check" x-show="o.id === ownerId" class="text-brand-600 dark:text-brand-300" />
                                    </button>
                                </li>
                            </template>
                            <li x-show="!filteredOwners.length" class="c-muted px-3 py-6 text-center text-xs">{{ __('common.nothing_found') }}</li>
                        </ul>
                    </div>
                    <p x-show="ownerId !== me && !pickingOwner" class="c-muted mt-1.5 text-xs">{{ __('phones.owner_hint') }}</p>
                </div>
            @endif
            <div x-show="!pickingOwner" class="space-y-4">
                <x-ui.field :label="__('phones.phone_label')" :hint="__('phones.phone_hint')">
                    <input x-ref="phoneInput" x-model="phoneInput" type="tel" inputmode="tel" autocomplete="off" enterkeyhint="send" class="c-input font-mono" placeholder="+998 90 123 45 67" required>
                </x-ui.field>
                <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || pickingOwner || phoneInput.length < 9">{{ __('phones.get_code') }}</x-ui.button>
            </div>
        </form>

        {{-- 2. code --}}
        <form x-show="step === 'code' && !account?.in_flight" x-cloak @submit.prevent="submitCode()" class="space-y-4">
            <p class="c-muted text-[13px]">{!! __('phones.code_sent_to', ['phone' => '<span class="font-mono font-semibold text-gray-900 dark:text-white" x-text="account?.phone"></span>']) !!}</p>
            <p x-show="ownerId !== me" class="rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">{{ __('phones.code_from_owner') }}</p>
            <x-ui.field :label="__('phones.code_label')">
                <input x-ref="codeInput" x-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" enterkeyhint="send" maxlength="12"
                       class="c-input h-12 text-center font-mono text-2xl tracking-[0.5em]" placeholder="•••••" required>
            </x-ui.field>
            <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || code.length < 3">{{ __('phones.confirm') }}</x-ui.button>
            <button type="button" class="c-muted w-full py-1 text-center text-xs hover:text-gray-900 disabled:opacity-60 dark:hover:text-white" :disabled="resendIn > 0" @click="resend()">
                <span x-show="resendIn > 0" x-text="@js(__('phones.resend_in')).replace(':time', $fmt.clock(resendIn))"></span>
                <span x-show="resendIn === 0">{{ __('phones.resend') }}</span>
            </button>
        </form>

        {{-- 3. password --}}
        <form x-show="step === 'password' && !account?.in_flight" x-cloak @submit.prevent="submitPassword()" class="space-y-4">
            <p class="c-muted text-[13px]">{{ __('phones.password_text') }}</p>
            <x-ui.field :label="__('phones.password_label')">
                <input x-ref="passwordInput" x-model="password" type="password" autocomplete="off" enterkeyhint="send" class="c-input" required>
            </x-ui.field>
            <p x-show="account?.password_hint" class="c-muted text-xs">{{ __('phones.hint') }}: <span class="font-semibold" x-text="account?.password_hint"></span></p>
            <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || !password">{{ __('phones.confirm') }}</x-ui.button>
        </form>

        {{-- done --}}
        <div x-show="step === 'done'" x-cloak class="py-4 text-center">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15">
                <x-ui.icon name="check" class="h-6 w-6" />
            </div>
            <p class="text-base font-semibold text-gray-950 dark:text-white">{{ __('phones.connected') }}</p>
            <p class="c-muted mt-1 text-[13px]" x-text="account?.name + ' · ' + account?.phone"></p>
            <div class="mt-5 flex flex-col-reverse justify-center gap-2 sm:flex-row">
                <x-ui.button variant="secondary" @click="wizard = false">{{ __('common.close') }}</x-ui.button>
                <template x-if="ownerId === me">
                    <x-ui.button as="a" variant="primary" icon="send" ::href="sendUrl">{{ __('phones.send') }}</x-ui.button>
                </template>
                {{-- an admin connecting employees goes on to the next one --}}
                <template x-if="ownerId !== me">
                    <x-ui.button variant="primary" icon="plus" @click="start()">{{ __('phones.connect_another') }}</x-ui.button>
                </template>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="ghost" x-show="step !== 'done'" @click="cancel()">{{ __('phones.cancel_login') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
