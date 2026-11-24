@props(['nullSender' => false, 'heloDomains' => [], 'expectedLabels' => []])

@php($heloDomains = array_values(array_filter((array) $heloDomains)))

@if ($nullSender || $expectedLabels)
    <span {{ $attributes->class('inline-flex flex-wrap items-center gap-1') }}>
        @foreach ($expectedLabels as $expectedLabel)
            <span class="inline-flex items-center rounded-full bg-sky-100 dark:bg-sky-900 px-2 py-0.5 text-xs font-medium text-sky-800 dark:text-sky-200" title="{{ __('Marked as an expected source under Expected Sources.') }}">{{ __('Expected: :label', ['label' => $expectedLabel]) }}</span>
        @endforeach
        @if ($nullSender)
            <span class="inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200" title="{{ $heloDomains
                ? __('Bounce or notification with an empty envelope sender (<>). SPF was checked on the HELO name :helo, which can\'t align with the From domain, so only DKIM can make these messages pass DMARC.', ['helo' => implode(', ', $heloDomains)])
                : __('Bounce or notification with an empty envelope sender (<>). SPF can\'t align for these messages, so only DKIM can make them pass DMARC.') }}">{{ __('Null sender') }}</span>
        @endif
    </span>
@endif
