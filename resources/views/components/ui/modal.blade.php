@props(['show', 'title' => null, 'width' => 'max-w-lg'])

{{--
    A dialog bound to an Alpine boolean: <x-ui.modal show="open" title="...">.
    Esc and a click on the backdrop close it. The page behind does not scroll while it
    is open, and on a phone it is a bottom sheet that stays above the keyboard (.c-modal).
--}}
<template x-teleport="body">
    <div x-show="{{ $show }}" x-cloak x-lock-scroll="{{ $show }}" class="c-modal"
         @keydown.escape.window="{{ $show }} = false" role="dialog" aria-modal="true">
        <div x-show="{{ $show }}" x-transition.opacity.duration.150ms class="c-modal__backdrop" @click="{{ $show }} = false"></div>

        <div x-show="{{ $show }}"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-0 sm:scale-[0.98]"
             x-transition:leave="transition duration-100 ease-in" x-transition:leave-end="opacity-0"
             {{ $attributes->merge(['class' => "c-modal__panel {$width}"]) }}>
            @if ($title)
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[var(--line)] px-5 py-3.5">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $title }}</h2>
                    <button type="button" class="c-muted -mr-1.5 grid h-9 w-9 place-items-center rounded-lg hover:bg-gray-100 dark:hover:bg-white/5" @click="{{ $show }} = false" aria-label="{{ __('common.close') }}">
                        <x-ui.icon name="x" />
                    </button>
                </div>
            @endif
            <div class="c-modal__body p-5">{{ $slot }}</div>
            @isset($footer)
                <div class="flex shrink-0 flex-col-reverse gap-2 border-t border-[var(--line)] px-5 py-3 sm:flex-row sm:justify-end">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</template>
