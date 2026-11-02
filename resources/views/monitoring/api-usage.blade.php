@php
    use Illuminate\Support\Carbon;

    $methodTones = ['GET' => 'info', 'POST' => 'success', 'PUT' => 'warning', 'PATCH' => 'warning', 'DELETE' => 'error'];
    $totalCalls = (int) $used->sum('calls');
    $totalErrors = (int) $used->sum('errors');
@endphp

<x-layouts.app :title="__('monitoring.api.title')" section="monitoring">
    <div x-data="{ q: '' }">
        <x-ui.page-header :title="__('monitoring.api.title')" :description="__('monitoring.api.description')">
            <x-slot:actions>
                <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] bg-[var(--surface)] p-0.5">
                    @foreach ([7, 30, 90, 180] as $window)
                        <a href="{{ route('monitoring.api', ['days' => $window]) }}"
                           @class([
                               'inline-flex h-8 items-center rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-colors',
                               'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $days === $window,
                               'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white' => $days !== $window,
                           ])
                           @if ($days === $window) aria-current="page" @endif>{{ __('monitoring.api.days', ['n' => $window]) }}</a>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Why this page exists --}}
        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-info-200 bg-info-50 px-4 py-3 text-[13px] text-info-800 dark:border-info-500/30 dark:bg-info-500/10 dark:text-info-200">
            <x-ui.icon name="info" class="mt-0.5" />
            <div class="space-y-1">
                <p>{{ __('monitoring.api.purpose') }}</p>
                <p class="text-xs opacity-80">
                    @if ($tracking)
                        {{ __('monitoring.api.tracking_since', ['date' => Carbon::parse($tracking)->format('d.m.Y'), 'days' => (int) Carbon::parse($tracking)->startOfDay()->diffInDays(now()->startOfDay()) + 1]) }}
                    @else
                        {{ __('monitoring.api.tracking_none') }}
                    @endif
                </p>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat :label="__('monitoring.api.used_endpoints')" icon="code">{{ number_format($used->count()) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.never_called')" icon="alert" :tone="$never->isNotEmpty() ? 'warning' : null">{{ number_format($never->count()) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.calls')" icon="activity">{{ number_format($totalCalls) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.errors')" icon="x-circle" :tone="$totalErrors ? 'error' : null">
                {{ number_format($totalErrors) }}
                @if ($totalCalls)
                    <x-slot:hint>{{ __('monitoring.api.error_share', ['pct' => round($totalErrors / $totalCalls * 100, 1)]) }}</x-slot:hint>
                @endif
            </x-ui.stat>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            {{-- Used endpoints --}}
            <section class="c-surface min-w-0 overflow-hidden">
                <div class="c-surface__head">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.api.used_title') }}</h2>
                    <div class="relative w-48 sm:w-64">
                        <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2" />
                        <input type="search" x-model="q" data-page-search class="c-input h-8 pl-8 text-base sm:text-[13px]" placeholder="{{ __('monitoring.api.filter') }}">
                    </div>
                </div>
                @if ($used->isEmpty())
                    <x-ui.empty icon="code" :title="__('monitoring.api.no_calls')" :text="__('monitoring.api.no_calls_text')" />
                @else
                    <div class="c-scroll-x">
                        <table class="c-table">
                            <thead>
                                <tr>
                                    <th>{{ __('monitoring.api.endpoint') }}</th>
                                    <th class="text-right">{{ __('monitoring.api.calls') }}</th>
                                    <th class="text-right">{{ __('monitoring.api.errors') }}</th>
                                    <th>{{ __('monitoring.api.last_call') }}</th>
                                    <th>{{ __('monitoring.api.callers') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($used as $row)
                                    @php
                                        $errorPct = $row->calls ? round($row->errors / $row->calls * 100, 1) : 0;
                                        $haystack = mb_strtolower($row->method.' '.$row->endpoint.' '.$row->callers);
                                    @endphp
                                    <tr class="c-row" x-show="!q || @js($haystack).includes(q.toLowerCase())">
                                        <td>
                                            <span class="inline-flex items-center gap-2">
                                                <x-ui.badge :tone="$methodTones[$row->method] ?? 'neutral'" class="w-14 justify-center font-mono">{{ $row->method }}</x-ui.badge>
                                                <span class="c-break font-mono text-xs text-gray-900 dark:text-white">{{ $row->endpoint }}</span>
                                            </span>
                                        </td>
                                        <td class="text-right font-semibold tabular-nums">{{ number_format((int) $row->calls) }}</td>
                                        <td class="text-right tabular-nums">
                                            @if ($row->errors)
                                                <span @class(['text-error-600' => $errorPct >= 5, 'text-warning-600' => $errorPct < 5])>{{ number_format((int) $row->errors) }}</span>
                                                <span class="c-muted text-xs">{{ $errorPct }}%</span>
                                            @else
                                                <span class="c-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">
                                            @if ($row->last_called_at)
                                                @php $iso = Carbon::parse($row->last_called_at)->toIso8601String(); @endphp
                                                <span :title="$fmt.dateTime(@js($iso))" x-text="$fmt.relative(@js($iso))"></span>
                                            @else
                                                <span class="c-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="c-muted max-w-[260px] text-xs"><span class="c-break line-clamp-2" title="{{ $row->callers }}">{{ $row->callers }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Never called --}}
            <section class="c-surface min-w-0 overflow-hidden">
                <div class="c-surface__head">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.api.never_title') }}</h2>
                    <span class="c-muted text-xs tabular-nums">{{ $never->count() }}</span>
                </div>
                <p class="c-muted border-b border-[var(--line)] px-4 py-2.5 text-xs">{{ __('monitoring.api.never_hint', ['n' => $days]) }}</p>
                @if ($never->isEmpty())
                    <x-ui.empty icon="check-circle" :title="__('monitoring.api.all_used')" class="!py-8" />
                @else
                    <ul class="max-h-[640px] divide-y divide-[var(--line)] overflow-y-auto">
                        @foreach ($never as $route)
                            @php [$method, $uri] = explode(' ', $route, 2); @endphp
                            <li class="flex items-center gap-2 px-4 py-2" x-show="!q || @js(mb_strtolower($route)).includes(q.toLowerCase())">
                                <x-ui.badge :tone="$methodTones[$method] ?? 'neutral'" class="w-14 justify-center font-mono">{{ $method }}</x-ui.badge>
                                <span class="c-break min-w-0 flex-1 font-mono text-xs text-gray-800 dark:text-gray-200">{{ $uri }}</span>
                                <button type="button" class="c-muted shrink-0 hover:text-gray-900 dark:hover:text-white" @click="$copy(@js($route))" title="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    {{-- Request log: body and response of the logged methods (POST) --}}
    <section class="c-surface mt-5 min-w-0 overflow-hidden"
             x-data="apiLogsPage({ urls: @js(['data' => route('monitoring.api.logs'), 'show' => route('monitoring.api.logs.show', '__ID__')]), methodTones: @js($methodTones) })">
        <div class="c-surface__head">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('monitoring.api.log.title') }} <span class="c-muted font-normal tabular-nums" x-text="$fmt.number(meta.total)"></span>
            </h2>
            <span class="c-muted inline-flex items-center gap-2 text-xs">
                <span class="c-dot is-live text-success-500"></span> {{ __('monitoring.events.auto_refresh') }}
            </span>
        </div>
        <p class="c-muted border-b border-[var(--line)] px-4 py-2.5 text-xs">{{ __('monitoring.api.log.hint', ['methods' => $logMethods, 'days' => $logDays]) }}</p>

        {{-- Filters --}}
        <div class="flex flex-col gap-2 border-b border-[var(--line)] p-3 lg:flex-row lg:items-center">
            <div class="relative min-w-0 flex-1">
                <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                <input type="search" x-model="filters.search" class="c-input h-9 pl-9" placeholder="{{ __('monitoring.api.log.search') }}" autocomplete="off">
            </div>
            <select x-model="filters.endpoint" class="c-input h-9 lg:w-64" aria-label="{{ __('monitoring.api.endpoint') }}">
                <option value="">{{ __('monitoring.api.log.all_endpoints') }}</option>
                <template x-for="e in endpoints" :key="e">
                    <option :value="e" :selected="e === filters.endpoint" x-text="e"></option>
                </template>
            </select>
            <select x-model="filters.user_id" class="c-input h-9 lg:w-52" aria-label="{{ __('monitoring.api.log.user') }}">
                <option value="">{{ __('monitoring.api.log.all_users') }}</option>
                <template x-for="u in users" :key="u.id">
                    <option :value="u.id" :selected="String(u.id) === String(filters.user_id)" x-text="u.name"></option>
                </template>
            </select>
            <div class="c-scroll-x shrink-0">
                <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] p-0.5">
                    @foreach (['' => __('monitoring.api.log.status_all'), 'ok' => __('monitoring.api.log.status_ok'), 'errors' => __('monitoring.api.log.status_errors')] as $value => $label)
                        <button type="button" class="h-8 rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-colors"
                                :class="filters.status === @js($value) ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white'"
                                @click="filters.status = @js($value)">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <button type="button" x-show="filtered" x-cloak class="c-muted shrink-0 text-xs hover:text-gray-900 dark:hover:text-white" @click="reset()">{{ __('common.reset') }}</button>
        </div>

        <div x-show="loading" class="space-y-3 p-4">
            <template x-for="i in 4"><div class="c-skeleton h-10"></div></template>
        </div>

        <div x-show="!loading && !logs.length" x-cloak>
            <x-ui.empty icon="code" :title="__('monitoring.api.log.empty_title')" :text="__('monitoring.api.log.empty_text', ['methods' => $logMethods])" x-show="!filtered" />
            <x-ui.empty icon="search" :title="__('monitoring.api.log.empty_filtered')" x-show="filtered" />
        </div>

        <div x-show="!loading && logs.length" x-cloak class="c-scroll-x" :class="refreshing && 'opacity-70 transition-opacity'">
            <table class="c-table">
                <thead>
                    <tr>
                        <th>{{ __('monitoring.api.log.time') }}</th>
                        <th>{{ __('monitoring.api.endpoint') }}</th>
                        <th>{{ __('monitoring.api.log.status') }}</th>
                        <th>{{ __('monitoring.api.log.user') }}</th>
                        <th class="text-right">{{ __('monitoring.api.log.duration') }}</th>
                        <th>{{ __('monitoring.api.log.request') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in logs" :key="l.id">
                        <tr class="c-row cursor-pointer" @click="show(l)" @keydown.enter="show(l)" tabindex="0">
                            <td class="whitespace-nowrap"><span :title="$fmt.dateTime(l.created_at)" x-text="$fmt.relative(l.created_at)"></span></td>
                            <td>
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-flex w-14 shrink-0 justify-center rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold leading-5" :class="$fmt.tone(methodTones[l.method])" x-text="l.method"></span>
                                    <span class="min-w-0">
                                        <span class="c-break block font-mono text-xs text-gray-900 dark:text-white" x-text="l.endpoint"></span>
                                        <span x-show="l.path !== l.endpoint" class="c-muted c-break block font-mono text-[11px]" x-text="l.path"></span>
                                    </span>
                                </span>
                            </td>
                            <td><span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold tabular-nums leading-5" :class="$fmt.tone(l.tone)" x-text="l.status"></span></td>
                            <td class="text-xs"><span x-text="l.user ?? @js(__('monitoring.api.log.no_token'))" :class="!l.user && 'c-muted'"></span></td>
                            <td class="c-muted text-right text-xs tabular-nums whitespace-nowrap" x-text="@js(__('monitoring.api.log.ms')).replace(':n', $fmt.number(l.duration_ms))"></td>
                            <td class="max-w-[320px]"><span class="c-muted line-clamp-1 font-mono text-[11px] break-all" x-text="l.preview ?? '—'"></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <x-ui.pager />

        {{-- One call --}}
        <x-ui.modal show="open" :title="__('monitoring.api.log.detail')" width="max-w-4xl">
            <template x-if="detail">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold leading-5" :class="$fmt.tone(methodTones[detail.method])" x-text="detail.method"></span>
                        <span class="c-break font-mono text-[13px] text-gray-900 dark:text-white" x-text="detail.path"></span>
                        <span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold tabular-nums leading-5" :class="$fmt.tone(detail.tone)" x-text="detail.status"></span>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-[13px] sm:grid-cols-4">
                        <div><dt class="c-eyebrow">{{ __('monitoring.api.log.time') }}</dt><dd class="tabular-nums" x-text="$fmt.dateTime(detail.created_at)"></dd></div>
                        <div><dt class="c-eyebrow">{{ __('monitoring.api.log.user') }}</dt><dd x-text="detail.user ?? @js(__('monitoring.api.log.no_token'))"></dd></div>
                        <div><dt class="c-eyebrow">{{ __('monitoring.api.log.duration') }}</dt><dd class="tabular-nums" x-text="@js(__('monitoring.api.log.ms')).replace(':n', $fmt.number(detail.duration_ms))"></dd></div>
                        <div><dt class="c-eyebrow">{{ __('monitoring.api.log.ip') }}</dt><dd class="font-mono text-xs" x-text="detail.ip ?? '—'"></dd></div>
                        <div class="col-span-2 sm:col-span-4"><dt class="c-eyebrow">{{ __('monitoring.api.log.user_agent') }}</dt><dd class="c-break c-muted text-xs" x-text="detail.user_agent || '—'"></dd></div>
                    </dl>

                    @foreach (['request_body' => __('monitoring.api.log.request'), 'response_body' => __('monitoring.api.log.response')] as $field => $label)
                        <div class="overflow-hidden rounded-xl border border-[var(--line)]">
                            <div class="flex items-center justify-between gap-3 border-b border-[var(--line)] px-3 py-2">
                                <span class="c-eyebrow">{{ $label }}</span>
                                <button type="button" x-show="detail.{{ $field }}" class="c-muted hover:text-gray-900 dark:hover:text-white" @click="$copy(pretty(detail.{{ $field }}))" title="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                            </div>
                            <div x-show="detailLoading" class="p-3"><div class="c-skeleton h-16"></div></div>
                            <pre x-show="!detailLoading && detail.{{ $field }}" class="c-break max-h-[40vh] overflow-auto whitespace-pre-wrap bg-[var(--surface-2)] p-3 font-mono text-xs leading-relaxed text-gray-800 dark:text-gray-200" x-text="pretty(detail.{{ $field }})"></pre>
                            <p x-show="!detailLoading && !detail.{{ $field }}" class="c-muted px-3 py-4 text-center text-xs">{{ __('monitoring.api.log.empty_body') }}</p>
                        </div>
                    @endforeach
                </div>
            </template>
        </x-ui.modal>
    </section>
</x-layouts.app>
