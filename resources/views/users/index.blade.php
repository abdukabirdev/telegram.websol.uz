<x-layouts.app :title="__('users.title')">
    <div x-data="usersPage({
        urls: @js([
            'data' => route('users.data'),
            'edit' => route('users.edit', '__ID__'),
            'destroy' => route('users.destroy', '__ID__'),
        ]),
        t: @js([
            'delete_title' => __('users.delete_title'),
            'delete_body' => __('users.delete_body'),
            'delete' => __('common.delete'),
        ]),
        initial: @js($initial),
    })" @phone-changed.window="load()">
        <x-ui.page-header :title="__('users.title')" :description="__('users.description')">
            <x-slot:actions>
                @if ($owners->isNotEmpty())
                    <x-ui.button variant="secondary" icon="phone" @click="$dispatch('connect-phone')">{{ __('users.connect_phone') }}</x-ui.button>
                @endif
                <x-ui.button :href="route('users.create')" variant="primary" icon="plus">{{ __('users.create') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <section class="c-surface overflow-hidden">
            {{-- Search: the list follows the typing --}}
            <div class="flex flex-col gap-2 border-b border-[var(--line)] p-3 sm:flex-row sm:items-center">
                <label class="relative block min-w-0 flex-1 sm:max-w-md">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" x-model="search" data-page-search class="c-input pl-9 pr-10" placeholder="{{ __('users.search_placeholder') }}"
                           autocomplete="off" enterkeyhint="search" aria-label="{{ __('common.search') }}" @keydown.enter.prevent="go(1); $el.blur()">
                    <span x-show="loading" x-cloak class="c-dot is-live absolute right-3.5 top-1/2 -translate-y-1/2 text-brand-500"></span>
                    <button type="button" x-show="search && !loading" x-cloak @click="search = ''" aria-label="{{ __('common.reset') }}"
                            class="c-muted absolute right-1 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg hover:text-gray-900 dark:hover:text-white">
                        <x-ui.icon name="x" class="h-3.5 w-3.5" />
                    </button>
                </label>
                <span class="c-muted text-xs sm:ml-auto">{{ __('users.month_hint') }}</span>
            </div>

            <div x-show="!users.length" x-cloak>
                <div x-show="search"><x-ui.empty icon="search" :title="__('common.nothing_found')" /></div>
                <div x-show="!search"><x-ui.empty icon="users" :title="__('users.empty')" /></div>
            </div>

            <div x-show="users.length" class="transition-opacity" :class="loading && 'opacity-60'">
                {{-- Phone: one card per user --}}
                <ul class="divide-y divide-[var(--line)] sm:hidden">
                    <template x-for="u in users" :key="u.id">
                        <li class="p-3">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="u.initials"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium text-gray-900 dark:text-white" x-text="u.name"></span>
                                        <span x-show="u.is_me" class="c-muted shrink-0 text-xs">· {{ __('users.you') }}</span>
                                        <span class="ml-auto inline-flex shrink-0 items-center whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5" :class="$fmt.tone(u.role.tone)" x-text="u.role.label"></span>
                                    </div>
                                    <div class="c-muted truncate text-xs" x-text="u.email"></div>
                                    <div class="c-muted mt-1 text-xs tabular-nums">
                                        {{ __('users.col_phones') }}: <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(u.phones)"></span>
                                        · {{ __('users.col_sent') }}: <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(u.sent)"></span>
                                        <span x-show="u.failed" class="text-error-600 dark:text-error-400">· {{ __('users.col_failed') }}: <span x-text="$fmt.number(u.failed)"></span></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center gap-1">
                                <template x-if="u.can.connect">
                                    <x-ui.button size="sm" variant="secondary" icon="phone" class="flex-1" @click="connect(u)">{{ __('users.connect_phone') }}</x-ui.button>
                                </template>
                                <span x-show="!u.can.connect" class="flex-1"></span>
                                <template x-if="u.can.update">
                                    <x-ui.button as="a" ::href="editUrl(u)" size="icon" variant="ghost" :title="__('common.edit')"><x-ui.icon name="edit" /></x-ui.button>
                                </template>
                                <template x-if="u.can.delete">
                                    <x-ui.button size="icon" variant="danger-ghost" :title="__('common.delete')" @click="remove(u)"><x-ui.icon name="trash" /></x-ui.button>
                                </template>
                            </div>
                        </li>
                    </template>
                </ul>

                {{-- Wider screens: a table --}}
                <div class="hidden overflow-x-auto sm:block">
                    <table class="c-table min-w-[820px]">
                        <thead>
                            <tr>
                                <th>{{ __('users.col_user') }}</th>
                                <th class="w-32">{{ __('users.role') }}</th>
                                <th class="w-24 text-right">{{ __('users.col_phones') }}</th>
                                <th class="w-24 text-right">{{ __('users.col_catalogs') }}</th>
                                <th class="w-24 text-right">{{ __('users.col_groups') }}</th>
                                <th class="w-28 text-right">{{ __('users.col_sent') }}</th>
                                <th class="w-28 text-right">{{ __('users.col_failed') }}</th>
                                <th class="w-56"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="u in users" :key="u.id">
                                <tr class="c-row">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="u.initials"></span>
                                            <div class="min-w-0">
                                                <a x-show="u.can.update" :href="editUrl(u)" class="block truncate font-medium text-gray-900 hover:underline dark:text-white">
                                                    <span x-text="u.name"></span>
                                                    <span x-show="u.is_me" class="c-muted font-normal">· {{ __('users.you') }}</span>
                                                </a>
                                                <span x-show="!u.can.update" class="block truncate font-medium text-gray-900 dark:text-white" x-text="u.name"></span>
                                                <div class="c-muted truncate text-xs" x-text="u.email"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5" :class="$fmt.tone(u.role.tone)" x-text="u.role.label"></span></td>
                                    <td class="text-right tabular-nums" x-text="$fmt.number(u.phones)"></td>
                                    <td class="text-right tabular-nums" x-text="$fmt.number(u.catalogs)"></td>
                                    <td class="text-right tabular-nums" x-text="$fmt.number(u.groups)"></td>
                                    <td class="text-right font-semibold tabular-nums text-gray-900 dark:text-white" x-text="$fmt.number(u.sent)"></td>
                                    <td class="text-right tabular-nums">
                                        <template x-if="u.failed">
                                            <div>
                                                <span class="text-error-600 dark:text-error-400" x-text="$fmt.number(u.failed)"></span>
                                                <span class="c-muted block text-[11px]" x-text="$fmt.percent(u.failed, u.sent + u.failed)"></span>
                                            </div>
                                        </template>
                                        <span x-show="!u.failed" class="c-muted">0</span>
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <template x-if="u.can.connect">
                                                <x-ui.button size="sm" variant="secondary" icon="phone" @click="connect(u)">{{ __('users.connect_phone') }}</x-ui.button>
                                            </template>
                                            <template x-if="u.can.update">
                                                <x-ui.button as="a" ::href="editUrl(u)" size="icon-sm" variant="ghost" :title="__('common.edit')"><x-ui.icon name="edit" /></x-ui.button>
                                            </template>
                                            <template x-if="u.can.delete">
                                                <x-ui.button size="icon-sm" variant="danger-ghost" :title="__('common.delete')" @click="remove(u)"><x-ui.icon name="trash" /></x-ui.button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <x-ui.pager />
        </section>

        @include('phones.connect-wizard')
    </div>
</x-layouts.app>
