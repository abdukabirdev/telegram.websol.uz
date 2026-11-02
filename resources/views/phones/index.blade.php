@php
    // the tabs: every group of App\Enums\AccountState, working accounts first
    $tabs = collect(\App\Enums\AccountState::cases())
        ->mapWithKeys(fn ($state) => [$state->value => $state->tone()])
        ->prepend('neutral', '');
    $seesOthers = auth()->user()->seesOthers();
@endphp

<x-layouts.app :title="__('phones.title')">
    <div x-data="phonesPage({
        urls: @js([
            'data' => route('phones.data'),
            'relogin' => route('phones.relogin', '__ID__'),
            'check' => route('phones.check', '__ID__'),
            'logout' => route('phones.logout', '__ID__'),
            'destroy' => route('phones.destroy', '__ID__'),
            'send' => route('send.create'),
        ]),
        t: @js(__('phones.js') + \Illuminate\Support\Arr::only(__('phones'), ['resumes', 'limit_reached', 'me'])),
        initial: @js($initial),
    })" @phone-changed.window="reload()">
        <x-ui.page-header :title="__('phones.title')" :description="__('phones.description')">
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" @click="$dispatch('connect-phone')">{{ __('phones.connect') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <section class="c-surface overflow-hidden">
            {{-- Tabs: the groups and how many accounts each has (under the search and the owner).
                 A phone sees all six at once (3 x 2, the count on top), wider screens one row. --}}
            <div class="grid grid-cols-3 border-b border-[var(--line)] px-1 sm:flex sm:items-stretch sm:gap-0.5 sm:overflow-x-auto sm:px-2" style="scrollbar-width:none" role="tablist">
                @foreach ($tabs as $value => $tone)
                    <button type="button" role="tab" @click="setState(@js($value))" :aria-selected="filters.state === @js($value)"
                            class="relative flex min-w-0 flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium text-gray-500 transition-colors hover:text-gray-900
                                   aria-selected:text-gray-950 dark:text-gray-400 dark:hover:text-white dark:aria-selected:text-white
                                   sm:h-12 sm:shrink-0 sm:flex-row sm:gap-2 sm:px-3 sm:py-0 sm:text-[13px]
                                   after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-t after:bg-brand-500 after:opacity-0 aria-selected:after:opacity-100">
                        @if ($value !== '')
                            <span class="c-dot hidden h-2 w-2 sm:inline-block" :class="$fmt.dot(@js($tone))"></span>
                        @endif
                        <span class="max-w-full truncate">{{ __('phones.states.'.($value ?: 'all')) }}</span>
                        <span class="order-first min-w-7 rounded-full px-1.5 text-center text-[13px] font-semibold leading-5 tabular-nums sm:order-none sm:min-w-6 sm:text-[11px]"
                              :class="$fmt.tone(counts[@js($value ?: 'all')] && @js($value !== '' && $value !== 'off') ? @js($tone) : 'neutral')"
                              x-text="$fmt.number(counts[@js($value ?: 'all')])"></span>
                    </button>
                @endforeach
            </div>

            {{-- Search and owner --}}
            <div class="flex flex-col gap-2 border-b border-[var(--line)] p-3 sm:flex-row sm:items-center">
                <label class="relative block min-w-0 flex-1 sm:max-w-md">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" x-model="filters.search" @input="searchChanged()" @keydown.enter.prevent="apply(); $el.blur()" data-page-search
                           class="c-input pl-9 pr-10" placeholder="{{ __('phones.search') }}" autocomplete="off" enterkeyhint="search" aria-label="{{ __('common.search') }}">
                    <span x-show="loading" x-cloak class="c-dot is-live absolute right-3.5 top-1/2 -translate-y-1/2 text-brand-500"></span>
                    <button type="button" x-show="filters.search && !loading" x-cloak @click="filters.search = ''; apply()" aria-label="{{ __('common.reset') }}"
                            class="c-muted absolute right-1 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg hover:text-gray-900 dark:hover:text-white">
                        <x-ui.icon name="x" class="h-3.5 w-3.5" />
                    </button>
                </label>
                @if ($owners->isNotEmpty())
                    <select class="c-input sm:w-64" x-model="filters.user" @change="apply()" aria-label="{{ __('phones.col_owner') }}">
                        <option value="">{{ __('phones.all_owners') }}</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner['id'] }}">{{ $owner['id'] === auth()->id() ? __('phones.js.owner_me', ['name' => $owner['name']]) : $owner['name'] }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" x-show="filtered" x-cloak @click="reset()"
                        class="c-muted inline-flex shrink-0 items-center gap-1 self-start text-xs hover:text-gray-900 sm:ml-auto sm:self-auto dark:hover:text-white">
                    <x-ui.icon name="x" class="h-3.5 w-3.5" /> {{ __('phones.reset_filters') }}
                </button>
            </div>

            {{-- Empty: no account at all, or none under the filters --}}
            <div x-show="!phones.length && !loading" x-cloak>
                <template x-if="!counts.all && !filters.search && !filters.user">
                    <x-ui.empty icon="phone" :title="__('phones.empty_title')" :text="__('phones.empty_text')">
                        <x-ui.button variant="primary" icon="plus" @click="$dispatch('connect-phone')">{{ __('phones.connect') }}</x-ui.button>
                    </x-ui.empty>
                </template>
                <template x-if="counts.all || filters.search || filters.user">
                    <x-ui.empty icon="filter" :title="__('phones.empty_filtered')" :text="__('phones.empty_filtered_text')">
                        <x-ui.button variant="secondary" icon="x" @click="reset()">{{ __('phones.reset_filters') }}</x-ui.button>
                    </x-ui.empty>
                </template>
            </div>

            {{-- Wider screens: a table; the coloured edge of a row is its state --}}
            <div x-show="phones.length" class="hidden overflow-x-auto transition-opacity md:block" :class="loading && 'opacity-60'">
                <table class="c-table {{ $seesOthers ? 'min-w-[900px]' : 'min-w-[760px]' }}">
                    <thead>
                        <tr>
                            <th class="w-52">{{ __('phones.col_status') }}</th>
                            <th>{{ __('phones.col_account') }}</th>
                            @if ($seesOthers)
                                <th class="w-44">{{ __('phones.col_owner') }}</th>
                            @endif
                            <th class="w-40">{{ __('phones.col_today') }}</th>
                            <th class="w-40">{{ __('phones.col_last_sent') }}</th>
                            <th class="w-48"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="p in phones" :key="p.id">
                            <tr class="cursor-pointer" @click="open(p)">
                                <td>
                                    <span class="absolute inset-y-0 left-0 w-[3px] bg-current" :class="$fmt.dot(p.state_tone)"></span>
                                    <div :class="p.state === 'off' && 'opacity-60'">
                                        @include('phones.state-badge', ['of' => 'p'])
                                        <div x-show="note(p)" class="mt-1 max-w-[13rem] truncate text-[11px]" :class="noteTone(p)" :title="note(p)" x-text="note(p)"></div>
                                    </div>
                                </td>
                                <td class="max-w-0" :class="p.state === 'off' && 'opacity-60'">
                                    <div class="font-mono text-[13px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="p.phone"></div>
                                    <div class="c-muted truncate text-xs" x-text="subtitle(p)"></div>
                                </td>
                                @if ($seesOthers)
                                    <td class="max-w-0" :class="p.state === 'off' && 'opacity-60'">
                                        <span class="block truncate text-[13px]" :class="p.is_mine ? 'c-muted' : 'text-gray-900 dark:text-gray-100'" x-text="ownerName(p)"></span>
                                    </td>
                                @endif
                                <td :class="p.state === 'off' && 'opacity-60'">
                                    <div x-show="working(p) || p.sent_today">
                                        <div class="text-xs tabular-nums">
                                            <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(p.sent_today)"></span>
                                            <span x-show="dailyLimit" class="c-muted" x-text="'/ ' + $fmt.number(dailyLimit)"></span>
                                        </div>
                                        <div x-show="dailyLimit" class="c-progress mt-1.5 w-28"><span :class="usageBar(p)" :style="`width:${usage(p)}%`"></span></div>
                                    </div>
                                    <span x-show="!working(p) && !p.sent_today" class="c-muted text-xs">—</span>
                                </td>
                                <td :class="p.state === 'off' && 'opacity-60'">
                                    <div class="text-xs text-gray-900 dark:text-white" x-text="$fmt.relative(p.last_sent_at)" :title="p.last_sent_at ? $fmt.dateTime(p.last_sent_at) : ''"></div>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1" @click.stop>
                                        <template x-if="p.can.send">
                                            <x-ui.button as="a" ::href="sendUrl(p)" size="sm" variant="secondary" icon="send">{{ __('phones.send') }}</x-ui.button>
                                        </template>
                                        <template x-if="p.awaits">
                                            <x-ui.button size="sm" variant="primary" icon="key" @click="continueLogin(p)">
                                                <span x-text="p.awaits === 'code' ? @js(__('phones.enter_code')) : @js(__('phones.enter_password'))"></span>
                                            </x-ui.button>
                                        </template>
                                        <template x-if="p.state === 'problem' && p.can.relogin">
                                            <x-ui.button size="sm" variant="secondary" icon="refresh" @click="relogin(p)" ::disabled="busy">{{ __('phones.relogin') }}</x-ui.button>
                                        </template>
                                        <x-ui.button size="icon-sm" variant="ghost" @click="open(p)" :title="__('common.details')" :aria-label="__('common.details')">
                                            <x-ui.icon name="chevron-right" />
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Phone: one row per account, a tap opens it --}}
            <ul x-show="phones.length" class="divide-y divide-[var(--line)] transition-opacity md:hidden" :class="loading && 'opacity-60'">
                <template x-for="p in phones" :key="p.id">
                    <li class="relative">
                        <span class="absolute inset-y-0 left-0 w-[3px] bg-current" :class="$fmt.dot(p.state_tone)"></span>
                        <div role="button" tabindex="0" class="block py-3 pl-4 pr-3 active:bg-[var(--surface-2)]" :class="p.state === 'off' && 'opacity-60'"
                             @click="open(p)" @keydown.enter="open(p)">
                            <div class="flex items-center gap-2">
                                <span class="min-w-0 truncate font-mono text-[15px] font-semibold tabular-nums text-gray-950 dark:text-white" x-text="p.phone"></span>
                                <span class="ml-auto"></span>
                                @include('phones.state-badge', ['of' => 'p'])
                            </div>
                            <div class="c-muted mt-0.5 truncate text-xs" x-text="[subtitle(p), @js($seesOthers) ? ownerName(p) : ''].filter(Boolean).join(' · ')"></div>
                            <div x-show="working(p) || p.sent_today || p.last_sent_at" class="c-muted mt-1.5 flex items-center gap-3 text-xs tabular-nums">
                                <span x-show="working(p) || p.sent_today" class="inline-flex items-center gap-1.5">
                                    {{ __('phones.col_today') }}:
                                    <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(p.sent_today)"></span><span x-show="dailyLimit" x-text="'/' + $fmt.number(dailyLimit)"></span>
                                    <span x-show="dailyLimit" class="c-progress w-12"><span :class="usageBar(p)" :style="`width:${usage(p)}%`"></span></span>
                                </span>
                                <span x-show="p.last_sent_at" class="ml-auto inline-flex items-center gap-1"><x-ui.icon name="send" class="h-3 w-3" /><span x-text="$fmt.relative(p.last_sent_at)"></span></span>
                            </div>
                            <div x-show="note(p)" class="mt-1 truncate text-xs" :class="noteTone(p)" x-text="note(p)"></div>
                        </div>
                        <div x-show="p.awaits" class="-mt-1 px-4 pb-3">
                            <x-ui.button size="sm" variant="primary" icon="key" class="w-full" @click="continueLogin(p)">
                                <span x-text="p.awaits === 'code' ? @js(__('phones.enter_code')) : @js(__('phones.enter_password'))"></span>
                            </x-ui.button>
                        </div>
                    </li>
                </template>
            </ul>

            <x-ui.pager />
        </section>

        {{-- One account: everything about it and every action --}}
        <x-ui.modal show="sheet" :title="__('phones.col_account')" width="max-w-md">
            <template x-if="current">
                <div>
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <button type="button" class="inline-flex max-w-full items-center gap-1.5 font-mono text-lg font-semibold tabular-nums text-gray-950 hover:text-brand-700 dark:text-white dark:hover:text-brand-300"
                                    @click="$copy(current.phone)">
                                <span class="truncate" x-text="current.phone"></span><x-ui.icon name="copy" class="c-muted h-3.5 w-3.5 shrink-0" />
                            </button>
                            <div class="c-muted truncate text-[13px]" x-text="subtitle(current) || '—'"></div>
                        </div>
                        @include('phones.state-badge', ['of' => 'current'])
                    </div>

                    <p x-show="current.state === 'paused'" class="mt-4 rounded-xl bg-warning-50 px-3 py-2 text-[13px] text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        {{ __('phones.flood_until') }} <span class="font-semibold" x-text="$fmt.relative(current.flood_until)"></span>
                    </p>
                    <p x-show="current.state === 'active' && limitReached(current)" class="mt-4 rounded-xl bg-error-50 px-3 py-2 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-300">
                        {{ __('phones.limit_reached') }}
                    </p>
                    <div x-show="current.last_error_text && current.state !== 'active' && current.state !== 'paused'"
                         class="mt-4 rounded-xl bg-error-50 px-3 py-2 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-300">
                        <div class="text-[11px] font-semibold uppercase tracking-wide opacity-80">{{ __('phones.last_error') }}</div>
                        <div class="c-break" x-text="current.last_error_text"></div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-[var(--line)] pt-4 text-[13px]">
                        @if ($seesOthers)
                            <div class="col-span-2">
                                <dt class="c-muted text-xs">{{ __('phones.owner_label') }}</dt>
                                <dd class="mt-0.5 font-medium text-gray-900 dark:text-white" x-text="current.owner?.name ?? '—'"></dd>
                            </div>
                        @endif
                        <div>
                            <dt class="c-muted text-xs">{{ __('phones.sent_today') }}</dt>
                            <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-white">
                                <span x-text="$fmt.number(current.sent_today ?? 0)"></span><span x-show="dailyLimit" class="c-muted font-normal" x-text="' / ' + $fmt.number(dailyLimit)"></span>
                            </dd>
                            <div x-show="dailyLimit" class="c-progress mt-1.5"><span :class="usageBar(current)" :style="`width:${usage(current)}%`"></span></div>
                        </div>
                        <div>
                            <dt class="c-muted text-xs">{{ __('phones.last_sent') }}</dt>
                            <dd class="mt-0.5 font-medium text-gray-900 dark:text-white" x-text="$fmt.relative(current.last_sent_at)" :title="current.last_sent_at ? $fmt.dateTime(current.last_sent_at) : ''"></dd>
                        </div>
                        <div>
                            <dt class="c-muted text-xs">{{ __('phones.checked') }}</dt>
                            <dd class="mt-0.5 font-medium text-gray-900 dark:text-white" x-text="$fmt.relative(current.last_checked_at)" :title="current.last_checked_at ? $fmt.dateTime(current.last_checked_at) : ''"></dd>
                        </div>
                        <div>
                            <dt class="c-muted text-xs">{{ __('phones.authorized_at') }}</dt>
                            <dd class="mt-0.5 font-medium tabular-nums text-gray-900 dark:text-white" x-text="$fmt.dateTime(current.authorized_at)"></dd>
                        </div>
                        <div x-show="current.telegram_user_id" class="col-span-2">
                            <dt class="c-muted text-xs">{{ __('phones.telegram_id') }}</dt>
                            <dd class="mt-0.5 font-mono text-xs text-gray-900 dark:text-white" x-text="current.telegram_user_id"></dd>
                        </div>
                    </dl>
                </div>
            </template>

            <x-slot:footer>
                {{-- on a phone the column is reversed: the main action on top, removal at the bottom --}}
                <div class="flex flex-col-reverse gap-2 sm:mr-auto sm:flex-row">
                    <template x-if="current?.can.remove">
                        <x-ui.button variant="danger-ghost" icon="trash" @click="remove(current)" ::disabled="busy">{{ __('phones.remove') }}</x-ui.button>
                    </template>
                    <template x-if="current?.can.logout">
                        <x-ui.button variant="danger-ghost" icon="logout" @click="logout(current)" ::disabled="busy">{{ __('phones.logout') }}</x-ui.button>
                    </template>
                </div>
                <template x-if="current?.can.check">
                    <x-ui.button variant="secondary" icon="shield" @click="check(current)" ::disabled="busy">{{ __('phones.check') }}</x-ui.button>
                </template>
                <template x-if="current?.can.relogin && !current?.awaits">
                    <x-ui.button variant="secondary" icon="refresh" @click="relogin(current)" ::disabled="busy">{{ __('phones.relogin') }}</x-ui.button>
                </template>
                <template x-if="current?.awaits">
                    <x-ui.button variant="primary" icon="key" @click="continueLogin(current)">{{ __('phones.continue_login') }}</x-ui.button>
                </template>
                <template x-if="current?.can.send">
                    <x-ui.button as="a" ::href="sendUrl(current)" variant="primary" icon="send">{{ __('phones.send') }}</x-ui.button>
                </template>
            </x-slot:footer>
        </x-ui.modal>

        @include('phones.connect-wizard')
    </div>
</x-layouts.app>
