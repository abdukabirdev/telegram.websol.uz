{{-- An account's state in an Alpine template; `$of` is the JS expression of the account (PhoneResource). --}}
<span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5" :class="$fmt.tone({{ $of }}.state_tone)">
    <span class="c-dot h-1.5 w-1.5" :class="{{ $of }}.in_flight && 'is-live'"></span>
    <span x-text="{{ $of }}.state_label"></span>
</span>
