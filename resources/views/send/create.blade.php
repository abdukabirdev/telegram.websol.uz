@php
    $isAdmin = auth()->user()->seesOthers();
    $max = \App\Sending\MassSend::MAX_TEXT_LENGTH;
    $caption = \App\Sending\MassSend::MAX_CAPTION_LENGTH;
    $limits = [
        'text' => $max,
        'caption' => $caption,
        'repeatMinutes' => \App\Sending\MassSend::MAX_REPEAT_MINUTES,
        'rounds' => \App\Sending\MassSend::MAX_ROUNDS,
        'fileBytes' => (int) config('telegram.attachments.max_kb') * 1024,
        // what goes to Telegram as a photo; anything else goes as a document
        'photoBytes' => \App\Sending\Attachments::PHOTO_MAX_BYTES,
        'photoTypes' => \App\Sending\Attachments::PHOTO_MIME_TYPES,
    ];
    $maxFile = round($limits['fileBytes'] / 1024 / 1024).' MB';
@endphp

<x-layouts.app :title="__('send.title')">
    <x-ui.page-header :title="__('send.title')" :description="__('send.description')">
        <x-slot:actions>
            <x-ui.button :href="route('sends.index')" variant="ghost" icon="history">{{ __('send.history') }}</x-ui.button>
            <x-ui.button :href="route('catalogs.create')" icon="plus">{{ __('send.new_catalog') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (empty($phones))
        <div class="c-surface">
            <x-ui.empty icon="phone" :title="__('send.no_phones_title')" :text="__('send.no_phones_text')">
                <x-ui.button :href="route('phones.index').'#connect'" variant="primary" icon="plus">{{ __('send.connect_phone') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <div x-data="sendPage({
                phones: @js($phones),
                catalogs: @js($catalogs),
                selectedPhone: @js($selectedPhone),
                admin: @js($isAdmin),
                urls: @js(['preview' => route('send.preview'), 'store' => route('send.store')]),
                t: @js(__('send')),
                units: @js(['min' => __('catalogs.unit_min'), 'hour' => __('catalogs.unit_hour'), 'day' => __('catalogs.unit_day')]),
                limits: @js($limits),
                maxFile: @js($maxFile),
             })"
             class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">

            <div class="min-w-0 space-y-5">
                {{-- 1. Account: the chosen one in a single row; the picker (search, owner groups) only while choosing --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2.5 text-sm font-semibold text-gray-900 dark:text-white">
                            <span class="grid h-5 w-5 place-items-center rounded-full text-[11px] tabular-nums"
                                  :class="phone ? 'bg-brand-500 text-white' : 'border border-gray-300 text-gray-500 dark:border-gray-600'">
                                <x-ui.icon name="check" class="h-3 w-3" x-show="phone" /><span x-show="!phone">1</span>
                            </span>
                            {{ __('send.step_account') }}
                        </h2>
                        <div class="flex items-center gap-3">
                            <span class="c-muted hidden text-xs tabular-nums sm:inline"
                                  x-text="t.accounts_ready.replace(':ready', $fmt.number(readyCount)).replace(':total', $fmt.number(phones.length))"></span>
                            <a href="{{ route('phones.index') }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('nav.phones') }} →</a>
                        </div>
                    </div>

                    {{-- Chosen --}}
                    <div x-show="phone && !picking" class="flex items-center gap-3 px-4 py-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"
                              x-text="initial(phone)"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                <span class="truncate text-[13px] font-semibold text-gray-950 dark:text-white" x-text="phone?.name"></span>
                                <span class="c-muted font-mono text-xs" x-text="phone?.phone"></span>
                            </div>
                            <div class="c-muted mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                @if ($isAdmin)
                                    <span x-show="phone?.owner && !phone?.is_mine" class="flex min-w-0 items-center gap-1">
                                        <x-ui.icon name="user" class="h-3 w-3" /><span class="truncate" x-text="phone?.owner?.name"></span>
                                    </span>
                                @endif
                                <span x-show="preview.sent_today !== null" class="tabular-nums">
                                    {{ __('send.sent_today') }}: <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(preview.sent_today)"></span><span x-show="preview.daily_limit" x-text="' / ' + $fmt.number(preview.daily_limit)"></span>
                                </span>
                                <span x-show="phone?.flood_until" class="flex items-center gap-1 text-warning-700 dark:text-warning-300">
                                    <x-ui.icon name="pause" class="h-3 w-3" />{{ __('send.paused_until') }} <span class="font-semibold" x-text="phone?.flood_until ? $fmt.relative(phone.flood_until) : ''"></span>
                                </span>
                            </div>
                        </div>
                        <x-ui.button size="sm" variant="secondary" @click="openPicker()">{{ __('send.change_account') }}</x-ui.button>
                    </div>

                    {{-- Picker --}}
                    <div x-show="!phone || picking" @keydown.escape="closePicker()">
                        <div class="flex flex-col gap-2 border-b border-[var(--line)] p-3 sm:flex-row sm:items-center">
                            <div class="relative min-w-0 flex-1">
                                <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                                <input type="search" x-ref="phoneSearch" x-model="phoneSearch" @keydown.enter.prevent="pickFirst()" class="c-input pl-9"
                                       placeholder="{{ __('send.search_accounts') }}" aria-label="{{ __('send.search_accounts') }}" autocomplete="off">
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($isAdmin)
                                    <div class="inline-flex shrink-0 rounded-xl border border-[var(--line-strong)] p-0.5" role="group">
                                        @foreach (['all' => __('send.scope_all'), 'mine' => __('send.scope_mine')] as $scope => $label)
                                            <button type="button" @click="phoneScope = @js($scope)" :aria-pressed="phoneScope === @js($scope)"
                                                    class="inline-flex h-8 items-center gap-1.5 rounded-[10px] px-3 text-xs font-medium transition-colors"
                                                    :class="phoneScope === @js($scope) ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'">
                                                {{ $label }} <span class="tabular-nums opacity-60" x-text="$fmt.number(scopeCount(@js($scope)))"></span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                <x-ui.button x-show="phone" size="sm" variant="ghost" @click="closePicker()">{{ __('common.cancel') }}</x-ui.button>
                            </div>
                        </div>

                        <div class="max-h-[360px] overflow-y-auto" role="radiogroup" aria-label="{{ __('send.step_account') }}">
                            <template x-for="g in phoneGroups" :key="g.key">
                                <div>
                                    <div x-show="g.label" class="sticky top-0 z-[1] flex items-center justify-between gap-3 border-b border-[var(--line)] bg-[var(--surface-2)] px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <span class="truncate" x-text="g.label"></span>
                                        <span class="tabular-nums" x-text="g.phones.length"></span>
                                    </div>
                                    <template x-for="p in g.phones" :key="p.id">
                                        <label class="flex items-center gap-3 border-b border-[var(--line)] px-4 py-2 transition-colors" :class="phoneRowClass(p)">
                                            <input type="radio" name="phone_id" class="peer sr-only" :value="p.id" :checked="phoneId === p.id" :disabled="!p.can.send" @change="choose(p)">
                                            <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full border peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/60"
                                                  :class="phoneId === p.id ? 'border-brand-500' : 'border-gray-300 dark:border-gray-600'">
                                                <span x-show="phoneId === p.id" class="h-2 w-2 rounded-full bg-brand-500"></span>
                                            </span>
                                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"
                                                  x-text="initial(p)"></span>
                                            <span class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-2">
                                                <span class="block truncate text-[13px] font-medium text-gray-950 dark:text-white" x-text="p.name"></span>
                                                <span class="c-muted block shrink-0 font-mono text-xs" x-text="p.phone"></span>
                                            </span>
                                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(p.tone)"
                                                  :title="p.flood_until ? @js(__('send.paused_until')) + ' ' + $fmt.relative(p.flood_until) : ''">
                                                <span class="c-dot h-1.5 w-1.5"></span>
                                                <span x-text="p.flood_until ? @js(__('phones.paused')) : p.status_label"></span>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                            </template>
                            <p x-show="!matchedPhones.length" x-cloak class="c-muted px-4 py-8 text-center text-[13px]"
                               x-text="readyCount ? t.no_accounts_found : t.no_ready_accounts"></p>
                        </div>

                        <div x-show="notReadyCount" x-cloak class="flex items-center justify-between gap-3 border-t border-[var(--line)] px-4 py-2 text-xs">
                            <label class="inline-flex cursor-pointer items-center gap-2 text-gray-700 dark:text-gray-300">
                                <input type="checkbox" class="c-check" x-model="showNotReady">
                                <span x-text="t.show_not_ready.replace(':n', $fmt.number(notReadyCount))"></span>
                            </label>
                            <span class="c-muted hidden sm:inline">{{ __('send.enter_to_pick') }}</span>
                        </div>
                    </div>
                    <p x-show="fieldError('phone_id')" x-cloak class="border-t border-[var(--line)] px-4 py-3 text-xs text-error-600 dark:text-error-400" x-text="fieldError('phone_id')"></p>
                </section>

                {{-- 2. Catalogs --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2.5 text-sm font-semibold text-gray-900 dark:text-white">
                            <span class="grid h-5 w-5 place-items-center rounded-full text-[11px] tabular-nums"
                                  :class="selected.length ? 'bg-brand-500 text-white' : 'border border-gray-300 text-gray-500 dark:border-gray-600'">
                                <x-ui.icon name="check" class="h-3 w-3" x-show="selected.length" /><span x-show="!selected.length">2</span>
                            </span>
                            {{ __('send.step_catalogs') }}
                        </h2>
                        <span x-show="selected.length" x-cloak class="c-muted text-xs tabular-nums"
                              x-text="@js(__('send.selected')).replace(':n', selected.length) + ' · ' + $fmt.number(selectedPeers) + ' ' + t.peers"></span>
                    </div>

                    @if (empty($catalogs))
                        <x-ui.empty icon="list" :title="__('send.no_catalogs_title')" :text="__('send.no_catalogs_text')">
                            <x-ui.button :href="route('catalogs.create')" variant="primary" icon="plus">{{ __('send.new_catalog') }}</x-ui.button>
                        </x-ui.empty>
                    @else
                        <div class="flex flex-col gap-2 border-b border-[var(--line)] p-3 sm:flex-row sm:items-center">
                            <div class="relative flex-1">
                                <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                                <input type="search" x-model="search" data-page-search class="c-input pl-9" placeholder="{{ __('send.search_catalogs') }}" autocomplete="off">
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($isAdmin)
                                    <div class="mr-1 inline-flex shrink-0 rounded-xl border border-[var(--line-strong)] p-0.5" role="group">
                                        @foreach (['all' => __('send.scope_all'), 'mine' => __('send.scope_mine')] as $scope => $label)
                                            <button type="button" @click="catalogScope = @js($scope)" :aria-pressed="catalogScope === @js($scope)"
                                                    class="inline-flex h-7 items-center gap-1.5 rounded-[10px] px-2.5 text-xs font-medium transition-colors"
                                                    :class="catalogScope === @js($scope) ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'">
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                <x-ui.button size="sm" variant="ghost" icon="check" @click="selectAll()">{{ __('send.select_all') }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" icon="x" @click="selectNone()" ::disabled="!selected.length">{{ __('send.select_none') }}</x-ui.button>
                            </div>
                        </div>

                        <ul class="max-h-[420px] divide-y divide-[var(--line)] overflow-y-auto">
                            <template x-for="c in filtered" :key="c.id">
                                <li>
                                    <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--surface-2)]"
                                           :class="isSelected(c) && 'bg-brand-50/50 dark:bg-brand-500/[0.07]'">
                                        <input type="checkbox" class="c-check shrink-0" :checked="isSelected(c)" @change="toggle(c)">
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <span class="truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="c.title"></span>
                                                <span x-show="c.is_global" class="inline-flex items-center gap-1 rounded-md px-1.5 text-[11px] font-semibold leading-5" :class="$fmt.tone('brand')">
                                                    <x-ui.icon name="globe" class="h-3 w-3" />{{ __('send.global') }}
                                                </span>
                                                <span x-show="c.timer_minutes" class="inline-flex items-center gap-1 rounded-md px-1.5 text-[11px] font-semibold leading-5" :class="$fmt.tone('neutral')">
                                                    <x-ui.icon name="clock" class="h-3 w-3" /><span x-text="@js(__('send.timer')).replace(':time', duration(c.timer_minutes))"></span>
                                                </span>
                                                <span x-show="cooldown(c)" class="inline-flex items-center gap-1 rounded-md px-1.5 text-[11px] font-semibold leading-5" :class="$fmt.tone('warning')">
                                                    <x-ui.icon name="alert" class="h-3 w-3" /><span x-text="@js(__('send.cooldown')).replace(':time', duration(cooldown(c)))"></span>
                                                </span>
                                            </span>
                                            <span x-show="!c.is_mine && c.owner" class="c-muted block truncate text-xs" x-text="c.owner?.name"></span>
                                        </span>
                                        <span class="shrink-0 text-right text-[13px] font-semibold tabular-nums text-gray-900 dark:text-white">
                                            <span x-text="$fmt.number(c.peers_count)"></span>
                                            <span class="c-muted text-xs font-normal" x-text="t.peers"></span>
                                        </span>
                                    </label>
                                </li>
                            </template>
                        </ul>
                        <p x-show="!filtered.length" x-cloak class="c-muted px-4 py-8 text-center text-[13px]">{{ __('send.no_catalogs_found') }}</p>
                    @endif
                    <p x-show="fieldError('catalog_ids')" x-cloak class="border-t border-[var(--line)] px-4 py-3 text-xs text-error-600 dark:text-error-400" x-text="fieldError('catalog_ids')"></p>
                </section>

                {{-- 3. Message and file (a file can be dropped anywhere on this card) --}}
                <section class="c-surface" @dragover="dragOver($event)" @dragleave="$el.contains($event.relatedTarget) || (dragging = false)" @drop="drop($event)">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2.5 text-sm font-semibold text-gray-900 dark:text-white">
                            <span class="grid h-5 w-5 place-items-center rounded-full text-[11px] tabular-nums"
                                  :class="hasContent ? 'bg-brand-500 text-white' : 'border border-gray-300 text-gray-500 dark:border-gray-600'">
                                <x-ui.icon name="check" class="h-3 w-3" x-show="hasContent" /><span x-show="!hasContent">3</span>
                            </span>
                            {{ __('send.step_message') }}
                        </h2>
                        <div class="flex items-center gap-0.5">
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-lg font-serif text-[13px] font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5" @click="insert('<b>', '</b>')" title="&lt;b&gt;">B</button>
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-lg font-serif text-[13px] italic text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5" @click="insert('<i>', '</i>')" title="&lt;i&gt;">I</button>
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-lg text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5" @click="insert('<code>', '</code>')" title="&lt;code&gt;"><x-ui.icon name="code" class="h-3.5 w-3.5" /></button>
                            <button type="button" class="grid h-7 w-7 place-items-center rounded-lg text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5" @click="insert('<a href=&quot;https://&quot;>', '</a>')" title="&lt;a href&gt;"><x-ui.icon name="external" class="h-3.5 w-3.5" /></button>
                        </div>
                    </div>
                    <div class="p-4">
                        <label for="message" class="sr-only">{{ __('send.message_label') }}</label>
                        <textarea id="message" x-ref="message" x-model="message" rows="9" class="c-input min-h-[200px] text-base sm:text-[14px]"
                                  :aria-invalid="tooLong || !!fieldError('message')" placeholder="{{ __('send.message_placeholder') }}"></textarea>
                        <div class="mt-2 flex flex-wrap items-start justify-between gap-x-4 gap-y-1 text-xs">
                            <p class="c-muted">
                                {{ __('send.html_hint') }}
                                <span class="c-code">&lt;b&gt;</span> <span class="c-code">&lt;i&gt;</span> <span class="c-code">&lt;a href&gt;</span> <span class="c-code">&lt;code&gt;</span>
                            </p>
                            <span class="tabular-nums" :class="tooLong ? 'font-semibold text-error-600' : (length > maxLength * 0.9 ? 'text-warning-600' : 'c-muted')"
                                  x-text="$fmt.number(length) + ' / ' + $fmt.number(maxLength)"></span>
                        </div>
                        <p x-show="fieldError('message')" x-cloak class="mt-1.5 text-xs text-error-600 dark:text-error-400" x-text="fieldError('message')"></p>
                    </div>

                    {{-- File --}}
                    <div class="border-t border-[var(--line)] p-4">
                        <input type="file" id="send-file" x-ref="file" class="sr-only" @change="pickFile($event.target.files)">

                        <label for="send-file" x-show="!file"
                               class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-dashed px-4 py-5 text-center transition-colors"
                               :class="dragging ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' : 'border-[var(--line-strong)] hover:border-gray-400 hover:bg-[var(--surface-2)] dark:hover:border-gray-500'">
                            <x-ui.icon name="paperclip" class="c-muted h-5 w-5" />
                            <span class="mt-1 text-[13px] font-medium text-gray-900 dark:text-white">{{ __('send.file_drop') }}</span>
                            <span class="c-muted max-w-md text-xs">{{ __('send.file_limits', ['max' => $maxFile]) }}</span>
                        </label>

                        <div x-show="file" x-cloak class="flex items-center gap-3 rounded-xl border p-2.5 transition-colors"
                             :class="dragging ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-500/10' : 'border-[var(--line)]'">
                            <template x-if="fileUrl">
                                <img :src="fileUrl" alt="" class="h-11 w-11 shrink-0 rounded-lg border border-[var(--line)] object-cover">
                            </template>
                            <span x-show="!fileUrl" class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                                <x-ui.icon name="file" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="file?.name" :title="file?.name"></span>
                                <span class="c-muted block text-xs tabular-nums">
                                    <span x-text="$fmt.bytes(file?.size)"></span> · <span x-text="fileKind === 'photo' ? t.file_as_photo : t.file_as_document"></span>
                                </span>
                            </span>
                            <x-ui.button size="icon-sm" variant="ghost" @click="removeFile()" :title="__('send.file_remove')" :aria-label="__('send.file_remove')">
                                <x-ui.icon name="x" />
                            </x-ui.button>
                        </div>

                        <p x-show="fieldError('file')" x-cloak class="mt-1.5 text-xs text-error-600 dark:text-error-400" x-text="fieldError('file')"></p>
                        <p x-show="file && !captionSplit" x-cloak class="c-muted mt-2 flex gap-1.5 text-xs">
                            <x-ui.icon name="info" class="mt-px h-3.5 w-3.5" /><span>{{ __('send.caption_hint', ['max' => $caption]) }} {{ __('send.message_optional') }}</span>
                        </p>
                        <p x-show="captionSplit" x-cloak class="mt-2 flex gap-1.5 rounded-xl bg-info-50 px-3 py-2 text-xs text-info-700 dark:bg-info-500/10 dark:text-info-300">
                            <x-ui.icon name="info" class="mt-px h-3.5 w-3.5" /><span>{{ __('send.caption_split', ['max' => $caption]) }}</span>
                        </p>
                    </div>
                </section>

                {{-- Repeat (optional) --}}
                <section class="c-surface">
                    <label class="flex cursor-pointer items-start gap-3 p-4">
                        <input type="checkbox" class="c-check mt-0.5" x-model="repeat">
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5 text-[13px] font-semibold text-gray-900 dark:text-white">
                                <x-ui.icon name="repeat" class="h-3.5 w-3.5" /> {{ __('send.repeat_toggle') }}
                            </span>
                            <span class="c-muted mt-0.5 block text-xs">{{ __('send.repeat_hint') }}</span>
                        </span>
                    </label>
                    <div x-show="repeat" x-cloak class="grid gap-4 border-t border-[var(--line)] p-4 sm:grid-cols-2">
                        <div class="min-w-0">
                            <label for="repeat_every" class="c-label">{{ __('send.repeat_every') }}</label>
                            <div class="flex gap-2">
                                <input id="repeat_every" type="number" min="1" :max="unitMax" step="1" inputmode="numeric" x-model.number="every"
                                       class="c-input w-24 tabular-nums" :aria-invalid="!!repeatError || !!fieldError('repeat_every_minutes')">
                                <select x-model="unit" class="c-input min-w-0 flex-1" aria-label="{{ __('send.repeat_unit') }}">
                                    @foreach (__('send.repeat_units') as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p x-show="repeatError || fieldError('repeat_every_minutes')" x-cloak class="mt-1.5 text-xs text-error-600 dark:text-error-400"
                               x-text="fieldError('repeat_every_minutes') || repeatError"></p>
                            <p x-show="!repeatError && !fieldError('repeat_every_minutes')" class="c-muted mt-1.5 text-xs tabular-nums" x-text="repeatText"></p>
                        </div>
                        <div class="min-w-0">
                            <label for="max_rounds" class="c-label">{{ __('send.repeat_rounds') }}</label>
                            <input id="max_rounds" type="number" min="1" :max="limits.rounds" step="1" inputmode="numeric" x-model="maxRounds"
                                   class="c-input tabular-nums" placeholder="{{ __('send.repeat_rounds_placeholder') }}" :aria-invalid="!!roundsError || !!fieldError('max_rounds')">
                            <p x-show="roundsError || fieldError('max_rounds')" x-cloak class="mt-1.5 text-xs text-error-600 dark:text-error-400"
                               x-text="fieldError('max_rounds') || roundsError"></p>
                            <p x-show="!roundsError && !fieldError('max_rounds')" class="c-muted mt-1.5 text-xs">{{ __('send.repeat_rounds_hint') }}</p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Summary --}}
            <aside class="space-y-4 lg:sticky lg:top-4">
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('send.summary') }}</h2>
                        <span x-show="previewLoading" x-cloak class="c-dot is-live text-brand-500"></span>
                    </div>
                    <dl class="divide-y divide-[var(--line)] text-[13px]">
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="c-muted">{{ __('send.account') }}</dt>
                            <dd class="min-w-0 truncate text-right font-medium text-gray-900 dark:text-white">
                                <span x-show="phone" x-text="phone?.name"></span>
                                <span x-show="phone" class="c-muted font-mono text-xs" x-text="phone?.phone"></span>
                                <span x-show="!phone" class="c-muted font-normal">{{ __('send.not_chosen') }}</span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="c-muted">{{ __('send.catalogs_count') }}</dt>
                            <dd class="font-medium tabular-nums text-gray-900 dark:text-white" x-text="selected.length"></dd>
                        </div>
                        <div x-show="file" x-cloak class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="c-muted flex shrink-0 items-center gap-1.5"><x-ui.icon name="paperclip" class="h-3.5 w-3.5" />{{ __('send.file') }}</dt>
                            <dd class="min-w-0 truncate text-right font-medium text-gray-900 dark:text-white" :title="file?.name">
                                <span x-text="file?.name"></span> <span class="c-muted text-xs font-normal tabular-nums" x-text="$fmt.bytes(file?.size)"></span>
                            </dd>
                        </div>
                        <div x-show="repeat" x-cloak class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="c-muted flex shrink-0 items-center gap-1.5"><x-ui.icon name="repeat" class="h-3.5 w-3.5" />{{ __('send.repeat') }}</dt>
                            <dd class="min-w-0 truncate text-right font-medium tabular-nums text-gray-900 dark:text-white" x-text="repeatError ? '—' : repeatText"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <dt class="c-muted">{{ __('send.unique_peers') }}</dt>
                            <dd class="text-[22px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 transition-opacity dark:text-white"
                                :class="previewLoading && 'opacity-50'" x-text="$fmt.number(selected.length ? preview.unique_peers : 0)"></dd>
                        </div>
                        <div x-show="selected.length && duplicates" x-cloak class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="c-muted">{{ __('send.duplicates') }}</dt>
                            <dd class="tabular-nums text-gray-700 dark:text-gray-300" x-text="'−' + $fmt.number(duplicates)"></dd>
                        </div>
                        <div x-show="phone && preview.sent_today !== null" x-cloak class="px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="c-muted">{{ __('send.today_usage') }}</dt>
                                <dd class="tabular-nums text-gray-900 dark:text-white">
                                    <span class="font-semibold" x-text="$fmt.number(preview.sent_today)"></span>
                                    <span class="c-muted" x-text="preview.daily_limit ? ' / ' + $fmt.number(preview.daily_limit) : ' · ' + @js(__('send.no_limit'))"></span>
                                </dd>
                            </div>
                            <div x-show="preview.daily_limit" class="c-progress mt-2">
                                <span class="bg-brand-500" :style="`width:${usagePercent}%`"></span>
                                <span :class="overLimit ? 'bg-error-400' : 'bg-brand-200 dark:bg-brand-700'" :style="`width:${plannedPercent}%`"></span>
                            </div>
                        </div>
                    </dl>

                    <div x-show="remaining === 0 && phone" x-cloak class="mx-4 mb-3 flex gap-2 rounded-xl bg-error-50 px-3 py-2 text-xs text-error-700 dark:bg-error-500/10 dark:text-error-300">
                        <x-ui.icon name="alert" class="mt-0.5 h-3.5 w-3.5" /><span>{{ __('send.limit_reached') }}</span>
                    </div>
                    <div x-show="overLimit && remaining > 0" x-cloak class="mx-4 mb-3 flex gap-2 rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        <x-ui.icon name="alert" class="mt-0.5 h-3.5 w-3.5" /><span x-text="t.over_limit.replace(':n', $fmt.number(remaining))"></span>
                    </div>
                    <div x-show="blockedSelected.length" x-cloak class="mx-4 mb-3 flex gap-2 rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                        <x-ui.icon name="clock" class="mt-0.5 h-3.5 w-3.5" /><span>{{ __('send.cooldown_blocked') }}</span>
                    </div>

                    <div class="border-t border-[var(--line)] p-4">
                        <x-ui.button variant="primary" size="lg" class="w-full" @click="submit()" ::disabled="!!blocker || busy">
                            <x-ui.icon name="repeat" x-show="repeat" /><x-ui.icon name="send" x-show="!repeat" />
                            <span class="tabular-nums" x-text="buttonLabel"></span>
                            <span x-show="!busy && !blocker" class="tabular-nums opacity-70" x-text="'· ' + $fmt.number(preview.unique_peers)"></span>
                        </x-ui.button>
                        <div x-show="busy && progress !== null" x-cloak class="c-progress mt-2 h-1">
                            <span class="bg-brand-500" :style="`width:${progress}%`"></span>
                        </div>
                        <p x-show="blocker" x-cloak class="c-muted mt-2 text-center text-xs" x-text="blocker"></p>
                    </div>
                </section>

                {{-- Message preview --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><x-ui.icon name="eye" class="c-muted" /> {{ __('send.preview') }}</h2>
                    </div>
                    <div class="space-y-2 bg-[var(--canvas)] p-4">
                        {{-- the file, with the text as its caption when it fits --}}
                        <div x-show="file" x-cloak class="ml-auto max-w-[92%] overflow-hidden rounded-2xl rounded-br-md border border-[var(--line)] bg-[var(--surface)] text-[13.5px] leading-relaxed text-gray-900 shadow-sm dark:text-gray-100">
                            <template x-if="fileUrl && fileKind === 'photo'">
                                <img :src="fileUrl" alt="" class="block max-h-64 w-full object-cover">
                            </template>
                            <div x-show="!(fileUrl && fileKind === 'photo')" class="flex items-center gap-3 px-3.5 py-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand-500 text-white"><x-ui.icon name="file" class="h-5 w-5" /></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-[13px] font-medium" x-text="file?.name"></span>
                                    <span class="c-muted block text-xs tabular-nums" x-text="$fmt.bytes(file?.size)"></span>
                                </span>
                            </div>
                            <p x-show="message.trim() && !captionSplit" class="c-break whitespace-pre-wrap px-3.5 pb-2.5" :class="fileKind === 'photo' && fileUrl && 'pt-2.5'"><template x-for="s in segments" :key="s.key"><span :class="segmentClass(s)" x-text="s.text"></span></template></p>
                        </div>
                        {{-- the text alone, or after the file when it is too long for a caption --}}
                        <div x-show="message.trim() && (!file || captionSplit)" class="ml-auto max-w-[92%] rounded-2xl rounded-br-md border border-[var(--line)] bg-[var(--surface)] px-3.5 py-2.5 text-[13.5px] leading-relaxed text-gray-900 shadow-sm dark:text-gray-100">
                            <p class="c-break whitespace-pre-wrap"><template x-for="s in segments" :key="s.key"><span :class="segmentClass(s)" x-text="s.text"></span></template></p>
                        </div>
                        <p x-show="!message.trim() && !file" class="c-muted py-6 text-center text-xs">{{ __('send.preview_empty') }}</p>
                    </div>
                </section>
            </aside>
        </div>
    @endif
</x-layouts.app>
