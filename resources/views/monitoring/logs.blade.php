@php
    use Illuminate\Support\Number;

    $levels = ['CRITICAL', 'ERROR', 'WARNING', 'INFO', 'DEBUG'];
    $levelTones = ['EMERGENCY' => 'error', 'ALERT' => 'error', 'CRITICAL' => 'error', 'ERROR' => 'error', 'WARNING' => 'warning', 'NOTICE' => 'info', 'INFO' => 'info', 'DEBUG' => 'neutral'];
    $borders = ['error' => 'border-l-error-500', 'warning' => 'border-l-warning-500', 'info' => 'border-l-info-500', 'neutral' => 'border-l-gray-300 dark:border-l-gray-700'];

    // "[2026-10-26 10:00:00] production.ERROR: message {context}\n#0 stack..."
    $entries = collect($lines)->map(function (string $raw) {
        $raw = rtrim($raw);
        if (preg_match('/^\[([^\]]+)\]\s+([\w-]+)\.(\w+):\s?(.*)$/s', $raw, $m) === 1) {
            return ['raw' => $raw, 'time' => $m[1], 'env' => $m[2], 'level' => strtoupper($m[3]), 'body' => $m[4]];
        }

        return ['raw' => $raw, 'time' => null, 'env' => null, 'level' => null, 'body' => $raw];
    })->map(fn (array $e) => $e + ['long' => substr_count($e['body'], "\n") > 2 || mb_strlen($e['body']) > 400]);
@endphp

<x-layouts.app :title="__('monitoring.logs.title')" section="monitoring">
    <x-ui.page-header :title="__('monitoring.logs.title')" :description="__('monitoring.logs.description')" />

    {{-- Filters (a plain GET form: the page is server-rendered) --}}
    <form method="GET" action="{{ route('monitoring.logs') }}" class="c-surface mb-4 grid gap-3 p-3 sm:p-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,0.7fr)_minmax(0,1.4fr)_auto]" x-data>
        <x-ui.field :label="__('monitoring.logs.file')">
            <select name="file" class="c-input" @change="$el.form.submit()">
                @forelse ($files as $name => $info)
                    <option value="{{ $name }}" @selected($name === $selected)>{{ $name }} · {{ Number::fileSize($info['size'], 1) }} · {{ date('d.m H:i', $info['modified']) }}</option>
                @empty
                    <option value="">{{ __('monitoring.logs.no_files') }}</option>
                @endforelse
            </select>
        </x-ui.field>
        <x-ui.field :label="__('monitoring.logs.level')">
            <select name="level" class="c-input" @change="$el.form.submit()">
                <option value="">{{ __('monitoring.logs.all_levels') }}</option>
                @foreach ($levels as $value)
                    <option value="{{ $value }}" @selected($level === $value)>{{ $value }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.field :label="__('common.search')">
            {{-- the search follows the typing; after the reload the caret is back at the end --}}
            <input type="search" name="search" value="{{ $search }}" class="c-input" data-page-search placeholder="{{ __('monitoring.logs.search') }}"
                   autocomplete="off" enterkeyhint="search" @input.debounce.600ms="$el.form.requestSubmit()"
                   @if (request()->has('search')) autofocus x-init="$el.setSelectionRange($el.value.length, $el.value.length)" @endif>
        </x-ui.field>
        <div class="flex items-end gap-2">
            <x-ui.button type="submit" variant="primary" icon="search">{{ __('monitoring.logs.show') }}</x-ui.button>
            @if ($level || $search !== '')
                <x-ui.button variant="ghost" :href="route('monitoring.logs', ['file' => $selected])">{{ __('common.reset') }}</x-ui.button>
            @endif
        </div>
    </form>

    <section class="c-surface overflow-hidden">
        <div class="c-surface__head">
            <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                <x-ui.icon name="file" class="c-muted" />
                <span class="truncate font-mono text-[13px]">{{ $selected ?: '—' }}</span>
            </h2>
            <span class="c-muted shrink-0 text-xs">{{ __('monitoring.logs.shown', ['n' => count($entries)]) }}</span>
        </div>
        <p class="c-muted border-b border-[var(--line)] px-4 py-2 text-xs">{{ __('monitoring.logs.tail_hint') }}</p>

        @if ($entries->isEmpty())
            <x-ui.empty icon="file" :title="__('monitoring.logs.empty_title')" :text="($level || $search !== '') ? __('monitoring.logs.empty_filtered') : __('monitoring.logs.empty_text')" />
        @else
            <ol class="divide-y divide-[var(--line)] font-mono text-xs">
                @foreach ($entries as $entry)
                    @php $tone = $levelTones[$entry['level']] ?? 'neutral'; @endphp
                    <li class="group border-l-2 {{ $borders[$tone] }} px-3 py-2 hover:bg-[var(--surface-2)] sm:px-4" x-data="{ open: false }">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            @if ($entry['time'])
                                <span class="c-muted tabular-nums">{{ $entry['time'] }}</span>
                            @endif
                            @if ($entry['level'])
                                <x-ui.badge :tone="$tone" class="font-mono">{{ $entry['level'] }}</x-ui.badge>
                            @endif
                            <span class="ml-auto flex items-center gap-1 opacity-100 sm:opacity-0 sm:transition-opacity sm:group-hover:opacity-100">
                                @if ($entry['long'])
                                    <button type="button" class="c-muted rounded px-1.5 py-0.5 font-sans text-[11px] hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/5 dark:hover:text-white" @click="open = !open"
                                            x-text="open ? @js(__('monitoring.logs.collapse')) : @js(__('monitoring.logs.expand'))"></button>
                                @endif
                                <button type="button" class="c-muted rounded p-1 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-white/5 dark:hover:text-white" @click="$copy($refs.raw.textContent)" title="{{ __('monitoring.copy') }}">
                                    <x-ui.icon name="copy" class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                        <pre @class(['c-break mt-1 whitespace-pre-wrap leading-relaxed text-gray-800 dark:text-gray-200', 'max-h-[4.9em] overflow-hidden' => $entry['long']])
                             @if ($entry['long']) :class="open && '!max-h-none'" @endif>{{ $entry['body'] }}</pre>
                        @if ($entry['long'])
                            <button type="button" x-show="!open" class="mt-1 font-sans text-[11px] font-medium text-brand-600 hover:underline dark:text-brand-300" @click="open = true">{{ __('monitoring.logs.expand') }} ↓</button>
                        @endif
                        <div x-ref="raw" hidden>{{ $entry['raw'] }}</div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</x-layouts.app>
