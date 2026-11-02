@props(['title' => null, 'section' => null])

@php
    $user = auth()->user();
    $isAdmin = $user->seesOthers();

    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'home', 'label' => __('nav.dashboard')],
        ['route' => 'sends.index', 'match' => 'sends.*', 'icon' => 'history', 'label' => __('nav.sends')],
        ['route' => 'catalogs.index', 'match' => 'catalogs.*', 'icon' => 'list', 'label' => __('nav.catalogs')],
        ['route' => 'phones.index', 'match' => 'phones.*', 'icon' => 'phone', 'label' => __('nav.phones')],
    ];
    if ($isAdmin) {
        $nav[] = ['route' => 'monitoring.index', 'match' => 'monitoring.*', 'icon' => 'activity', 'label' => __('nav.monitoring')];
        $nav[] = ['route' => 'users.index', 'match' => 'users.*', 'icon' => 'users', 'label' => __('nav.users')];
    }

    $monitoringNav = [
        ['route' => 'monitoring.index', 'icon' => 'activity', 'label' => __('nav.overview')],
        ['route' => 'monitoring.events', 'icon' => 'alert', 'label' => __('nav.events')],
        ['route' => 'monitoring.analytics', 'icon' => 'chart', 'label' => __('nav.analytics')],
        ['route' => 'monitoring.api', 'icon' => 'code', 'label' => __('nav.api')],
        ['route' => 'monitoring.logs', 'icon' => 'file', 'label' => __('nav.logs')],
        ['route' => 'monitoring.settings', 'icon' => 'settings', 'label' => __('nav.settings')],
    ];

    $palette = collect($nav)->map(fn ($n) => ['label' => $n['label'], 'url' => route($n['route']), 'group' => __('nav.pages')])
        ->push(['label' => __('nav.send'), 'url' => route('send.create'), 'group' => __('nav.actions')])
        ->push(['label' => __('nav.new_catalog'), 'url' => route('catalogs.create'), 'group' => __('nav.actions')])
        ->push(['label' => __('nav.connect_phone'), 'url' => route('phones.index').'#connect', 'group' => __('nav.actions')])
        ->push(['label' => __('nav.profile'), 'url' => route('profile.edit'), 'group' => __('nav.pages')])
        ->when($isAdmin, fn ($c) => $c->merge(collect($monitoringNav)->map(fn ($n) => ['label' => __('nav.monitoring').' · '.$n['label'], 'url' => route($n['route']), 'group' => __('nav.monitoring')])))
        ->values();
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231fa2b3'/%3E%3Cpath d='M24 8 6 15l6 2.5L22 11l-8 8 7 5z' fill='%2304222a'/%3E%3C/svg%3E">
    <script>
        try { if ((localStorage.getItem('theme') ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}
        window.__palette = @js($palette);
        window.__t = @js(__('common.js'));
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{-- Top bar --}}
    <header class="c-bar" x-data="pulse(@js(route('dashboard.pulse')))">
        <div class="c-bar__row">
            <a href="{{ route('dashboard') }}" class="c-brand">
                <span class="c-brand__mark"><x-ui.icon name="send" class="h-3.5 w-3.5" /></span>
                <span class="hidden sm:inline">{{ config('app.name') }}</span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex">
                @foreach ($nav as $item)
                    <a href="{{ route($item['route']) }}" class="c-tab" @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                        <x-ui.icon :name="$item['icon']" />
                        {{ $item['label'] }}
                        @if ($item['route'] === 'sends.index')
                            <span x-show="active > 0" x-cloak class="ml-0.5 inline-flex h-[18px] min-w-[18px] items-center justify-center gap-1 rounded-full bg-brand-500/20 px-1.5 text-[11px] font-semibold text-brand-200">
                                <span class="c-dot is-live h-1.5 w-1.5 text-brand-300"></span><span x-text="active"></span>
                            </span>
                        @endif
                        @if ($item['route'] === 'monitoring.index')
                            <span x-show="problems > 0" x-cloak class="ml-0.5 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-error-500 px-1.5 text-[11px] font-semibold text-white" x-text="problems"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-1.5">
                <button type="button" class="c-jump hidden md:inline-flex" @click="$store.palette.show()">
                    <x-ui.icon name="search" class="h-3.5 w-3.5" />
                    <span class="flex-1 text-left">{{ __('nav.jump') }}</span>
                    <span class="c-key">Ctrl K</span>
                </button>

                <x-ui.button :href="route('send.create')" variant="brand" size="sm" icon="send" class="hidden sm:inline-flex">{{ __('nav.send') }}</x-ui.button>

                <form method="POST" action="{{ route('locale', app()->getLocale() === 'ru' ? 'uz' : 'ru') }}">
                    @csrf
                    <button class="c-iconbtn px-2 text-xs font-semibold uppercase" title="{{ __('nav.language') }}">{{ app()->getLocale() === 'ru' ? 'UZ' : 'RU' }}</button>
                </form>

                <button type="button" class="c-iconbtn" @click="$store.theme.toggle()" title="{{ __('nav.theme') }}">
                    <x-ui.icon name="moon" x-show="!$store.theme.dark" />
                    <x-ui.icon name="sun" x-show="$store.theme.dark" x-cloak />
                </button>

                {{-- User menu --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-xl p-1 hover:bg-white/5" @click="open = !open">
                        @if ($user->avatar_url)
                            <img src="{{ $user->avatar_url }}" alt="" class="h-7 w-7 rounded-full object-cover">
                        @else
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-white/10 text-[11px] font-semibold text-white">{{ $user->initials }}</span>
                        @endif
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top.right
                         class="absolute right-0 top-11 z-50 w-60 overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)] py-1 text-gray-800 shadow-xl dark:text-gray-200">
                        <div class="border-b border-[var(--line)] px-4 py-3">
                            <div class="truncate text-sm font-semibold">{{ $user->name }}</div>
                            <div class="c-muted truncate text-xs">{{ $user->email }} · {{ $user->roleName()->label() }}</div>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2 text-[13px] hover:bg-gray-50 dark:hover:bg-white/5"><x-ui.icon name="user" /> {{ __('nav.profile') }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="flex w-full items-center gap-2.5 px-4 py-2 text-left text-[13px] text-error-600 hover:bg-gray-50 dark:hover:bg-white/5"><x-ui.icon name="logout" /> {{ __('nav.logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Mobile navigation --}}
        <nav class="flex gap-1 overflow-x-auto border-t border-white/5 px-2 py-1.5 lg:hidden" style="scrollbar-width:none">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="c-tab shrink-0" @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                    <x-ui.icon :name="$item['icon']" /> {{ $item['label'] }}
                </a>
            @endforeach
            <a href="{{ route('send.create') }}" class="c-tab shrink-0 text-brand-300"><x-ui.icon name="send" /> {{ __('nav.send') }}</a>
        </nav>
    </header>

    @if ($section === 'monitoring')
        <div class="c-sub">
            <div class="c-sub__row">
                @foreach ($monitoringNav as $item)
                    <a href="{{ route($item['route']) }}" class="c-link" @if (request()->routeIs($item['route'].'*') && ($item['route'] !== 'monitoring.index' || request()->routeIs('monitoring.index'))) aria-current="page" @endif>
                        <x-ui.icon :name="$item['icon']" class="h-3.5 w-3.5" /> {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <main class="c-wrap c-page">
        @if (session('success') || session('error'))
            <div x-data x-init="$store.toast.show(@js(session('success') ?? session('error')), @js(session('error') ? 'error' : 'success'))"></div>
        @endif

        {{ $slot }}
    </main>

    {{-- Toasts --}}
    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(380px,calc(100vw-2rem))] flex-col gap-2" x-data>
        <template x-for="toast in $store.toast.items" :key="toast.id">
            <div x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-2 opacity-0"
                 class="pointer-events-auto flex items-start gap-3 rounded-2xl border border-[var(--line)] bg-[var(--surface)] px-4 py-3 text-[13px] shadow-lg">
                <span class="c-dot mt-1.5" :class="$fmt.dot(toast.tone)"></span>
                <span class="flex-1 text-gray-800 dark:text-gray-100" x-text="toast.message"></span>
                <button type="button" class="c-muted hover:text-gray-900" @click="$store.toast.dismiss(toast.id)"><x-ui.icon name="x" class="h-3.5 w-3.5" /></button>
            </div>
        </template>
    </div>

    {{-- Confirm dialog ($store.confirm.ask) --}}
    <div x-data>
        <template x-teleport="body">
            <div x-show="$store.confirm.open" x-cloak x-lock-scroll="$store.confirm.open" class="c-modal z-[70] items-center p-4" @keydown.escape.window="$store.confirm.answer(false)">
                <div class="c-modal__backdrop" @click="$store.confirm.answer(false)"></div>
                <div x-show="$store.confirm.open" x-transition class="relative w-full max-w-sm rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 shadow-2xl">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white" x-text="$store.confirm.title"></h2>
                    <p class="c-muted c-break mt-2 whitespace-pre-line text-[13px]" x-show="$store.confirm.body" x-text="$store.confirm.body"></p>
                    <div class="mt-5 flex justify-end gap-2">
                        <x-ui.button variant="ghost" @click="$store.confirm.answer(false)">{{ __('common.cancel') }}</x-ui.button>
                        <button type="button" x-ref="ok" x-effect="$store.confirm.open && $nextTick(() => $refs.ok.focus())"
                                class="inline-flex h-9 items-center rounded-xl px-3.5 text-[13px] font-medium text-white"
                                :class="$store.confirm.danger ? 'bg-error-600 hover:bg-error-700' : 'bg-gray-900 hover:bg-gray-700 dark:bg-white dark:text-gray-900'"
                                @click="$store.confirm.answer(true)" x-text="$store.confirm.action || @js(__('common.confirm'))"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Command palette (Ctrl/⌘ K) --}}
    <div x-data>
        <template x-teleport="body">
            <div x-show="$store.palette.open" x-cloak x-lock-scroll="$store.palette.open" class="c-modal z-[65] items-start px-3 pt-[12vh]" @keydown.escape.window="$store.palette.open = false">
                <div class="c-modal__backdrop" @click="$store.palette.open = false"></div>
                <div x-show="$store.palette.open" x-transition class="relative w-full max-w-xl overflow-hidden rounded-3xl border border-[var(--line-strong)] bg-[var(--surface)] shadow-2xl">
                    <div class="flex items-center gap-3 border-b border-[var(--line)] px-4">
                        <x-ui.icon name="search" class="c-muted" />
                        <input type="text" class="h-12 w-full bg-transparent text-[15px] outline-none" placeholder="{{ __('nav.jump_placeholder') }}"
                               x-model="$store.palette.query" x-effect="$store.palette.open && $nextTick(() => $el.focus())"
                               @keydown.arrow-down.prevent="$store.palette.move(1)" @keydown.arrow-up.prevent="$store.palette.move(-1)"
                               @keydown.enter.prevent="$store.palette.go()" @input="$store.palette.active = 0">
                        <span class="c-kbd">Esc</span>
                    </div>
                    <div class="max-h-[50dvh] overflow-y-auto overscroll-contain py-1.5">
                        <template x-for="(item, i) in $store.palette.results" :key="item.url + item.label">
                            <button type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm"
                                    :class="i === $store.palette.active ? 'bg-gray-100 text-gray-950 dark:bg-white/[0.07] dark:text-white' : 'text-gray-700 dark:text-gray-300'"
                                    @mouseenter="$store.palette.active = i" @click="$store.palette.go(item)">
                                <x-ui.icon name="chevron-right" class="c-muted h-3.5 w-3.5" />
                                <span class="flex-1" x-text="item.label"></span>
                                <span class="c-muted text-xs" x-text="item.group"></span>
                            </button>
                        </template>
                        <p x-show="!$store.palette.results.length" class="c-muted px-4 py-6 text-center text-sm">{{ __('common.nothing_found') }}</p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</body>
</html>
