@php
    $isAdmin = auth()->user()->seesOthers();
    $editing = $catalog->exists;
    $pageTitle = $editing ? __('catalogs.edit_title') : __('catalogs.create_title');
    $presets = [
        ['value' => null, 'label' => __('catalogs.timer_none')],
        ['value' => 10, 'label' => __('catalogs.unit_min', ['n' => 10])],
        ['value' => 30, 'label' => __('catalogs.unit_min', ['n' => 30])],
        ['value' => 60, 'label' => __('catalogs.unit_hour', ['n' => 1])],
        ['value' => 1440, 'label' => __('catalogs.unit_day', ['n' => 1])],
    ];
@endphp

<x-layouts.app :title="$pageTitle">
    <form method="POST" action="{{ $editing ? route('catalogs.update', $catalog) : route('catalogs.store') }}"
          x-data="catalogFormPage({
              title: @js(old('title', $catalog->title ?? '')),
              peersText: @js(old('peers_text', implode("\n", $catalog->peerList()))),
              isGlobal: @js((bool) old('is_global', $catalog->is_global)),
              timer: @js(old('timer_minutes', $catalog->timer_minutes)),
              presets: @js($presets),
          })"
          @submit="submitting = true">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-ui.page-header :title="$pageTitle" :description="__('catalogs.form_description')">
            <x-slot:eyebrow>
                <a href="{{ route('catalogs.index') }}" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                    <x-ui.icon name="chevron-left" class="h-3 w-3" />{{ __('catalogs.title') }}
                </a>
            </x-slot:eyebrow>
        </x-ui.page-header>

        <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
            {{-- Main: title + peers --}}
            <div class="c-surface min-w-0 space-y-5 p-4 sm:p-5">
                <x-ui.field :label="__('catalogs.field_title')" for="title" error="title">
                    <input id="title" name="title" type="text" value="{{ old('title', $catalog->title) }}" x-model="title" maxlength="255" required autofocus
                           class="c-input" placeholder="{{ __('catalogs.title_placeholder') }}" @error('title') aria-invalid="true" @enderror>
                </x-ui.field>

                <x-ui.field :label="__('catalogs.field_peers')" for="peers_text" error="peers_text" :hint="__('catalogs.peers_hint')">
                    <textarea id="peers_text" name="peers_text" x-model="peersText" rows="16" spellcheck="false" autocapitalize="off" autocomplete="off"
                              class="c-input min-h-[320px] font-mono text-base leading-6 sm:text-[13px]" placeholder="{{ __('catalogs.peers_placeholder') }}"
                              @error('peers_text') aria-invalid="true" @enderror>{{ old('peers_text', implode("\n", $catalog->peerList())) }}</textarea>
                </x-ui.field>
            </div>

            {{-- Side: parsed preview + settings --}}
            <aside class="space-y-4 lg:sticky lg:top-4">
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('catalogs.preview_title') }}</h2>
                        <x-ui.button size="sm" variant="ghost" icon="check" @click="tidy()" ::disabled="!parsed.peers.length || peersText === parsed.peers.join('\n')">{{ __('catalogs.tidy') }}</x-ui.button>
                    </div>
                    <dl class="grid grid-cols-3 divide-x divide-[var(--line)] border-b border-[var(--line)] text-center">
                        <div class="px-2 py-3">
                            <dt class="c-eyebrow">{{ __('catalogs.parsed') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="$fmt.number(parsed.peers.length)"></dd>
                        </div>
                        <div class="px-2 py-3">
                            <dt class="c-eyebrow">{{ __('catalogs.duplicates') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums" :class="parsed.duplicates ? 'text-gray-950 dark:text-white' : 'c-muted'" x-text="$fmt.number(parsed.duplicates)"></dd>
                        </div>
                        <div class="px-2 py-3">
                            <dt class="c-eyebrow">{{ __('catalogs.invalid') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums" :class="parsed.invalid.length ? 'text-warning-600' : 'c-muted'" x-text="$fmt.number(parsed.invalid.length)"></dd>
                        </div>
                    </dl>
                    <div class="p-4">
                        <p x-show="!parsed.peers.length" class="c-muted py-4 text-center text-xs">{{ __('catalogs.preview_empty') }}</p>
                        <div x-show="parsed.peers.length" class="flex max-h-64 flex-wrap gap-1.5 overflow-y-auto">
                            <template x-for="peer in chips" :key="peer">
                                <span class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 font-mono text-xs"
                                      :class="isInvalid(peer) ? 'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-300' : 'border-[var(--line)] bg-[var(--surface-2)] text-gray-700 dark:text-gray-300'">
                                    <x-ui.icon name="alert" class="h-3 w-3" x-show="isInvalid(peer)" />
                                    <span x-text="peer"></span>
                                </span>
                            </template>
                            <span x-show="parsed.peers.length > chips.length" class="c-muted self-center text-xs"
                                  x-text="@js(__('catalogs.preview_more')).replace(':n', $fmt.number(parsed.peers.length - chips.length))"></span>
                        </div>
                        <div x-show="parsed.invalid.length" x-cloak class="mt-3 rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                            <p>{{ __('catalogs.invalid_hint') }}</p>
                            <p class="c-break mt-1.5 font-mono" x-text="parsed.invalid.slice(0, 10).join(', ') + (parsed.invalid.length > 10 ? ' …' : '')"></p>
                        </div>
                    </div>
                </section>

                <section class="c-surface space-y-5 p-4">
                    <x-ui.field :label="__('catalogs.field_timer')" for="timer_minutes" error="timer_minutes" :hint="__('catalogs.timer_hint')">
                        <div class="mb-2 flex flex-wrap gap-1.5">
                            <template x-for="p in presets" :key="String(p.value)">
                                <button type="button" @click="setTimer(p.value)" x-text="p.label"
                                        class="h-8 rounded-lg border px-2.5 text-xs font-medium transition-colors"
                                        :class="isPreset(p.value) ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-[var(--line-strong)] text-gray-700 hover:border-gray-400 dark:text-gray-300'"></button>
                            </template>
                        </div>
                        <div class="relative">
                            <input id="timer_minutes" name="timer_minutes" type="number" min="0" max="10080" step="1" inputmode="numeric" value="{{ old('timer_minutes', $catalog->timer_minutes) }}" x-model="timer"
                                   class="c-input pr-20 tabular-nums" @error('timer_minutes') aria-invalid="true" @enderror>
                            <span class="c-muted pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs">{{ __('catalogs.timer_custom') }}</span>
                        </div>
                    </x-ui.field>

                    @if ($isAdmin)
                        <div class="border-t border-[var(--line)] pt-4">
                            <input type="hidden" name="is_global" value="0">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" name="is_global" value="1" x-model="isGlobal" class="c-check mt-0.5">
                                <span>
                                    <span class="flex items-center gap-1.5 text-[13px] font-semibold text-gray-900 dark:text-white"><x-ui.icon name="globe" class="h-3.5 w-3.5" />{{ __('catalogs.field_global') }}</span>
                                    <span class="c-muted mt-0.5 block text-xs">{{ __('catalogs.global_hint') }}</span>
                                </span>
                            </label>
                            @error('is_global')<p class="mt-1.5 text-xs text-error-600 dark:text-error-400">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </section>
            </aside>
        </div>

        {{-- Sticky save bar --}}
        <div class="sticky bottom-0 z-20 -mx-4 mt-5 border-t border-[var(--line)] bg-[var(--surface)]/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
            <div class="flex items-center gap-3">
                <p class="c-muted hidden min-w-0 flex-1 truncate text-xs sm:block">
                    <span x-show="dirty" x-cloak class="inline-flex items-center gap-1.5 text-warning-700 dark:text-warning-300"><span class="c-dot h-1.5 w-1.5"></span>{{ __('catalogs.unsaved') }}</span>
                    <span x-show="!dirty" class="tabular-nums" x-text="@js(__('catalogs.peers_count')).replace(':n', $fmt.number(parsed.peers.length))"></span>
                </p>
                <div class="flex flex-1 justify-end gap-2 sm:flex-none">
                    <x-ui.button :href="route('catalogs.index')" variant="ghost">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" icon="check" ::disabled="submitting || !title.trim()">{{ __('common.save') }}</x-ui.button>
                </div>
            </div>
        </div>
    </form>
</x-layouts.app>
