{{--
    A single-value dropdown with a search box, bound like a native select via
    wire:model (x-modelable). Option values are passed as strings, matching
    what a native <select> sends to Livewire.

    The widget is wire:ignore'd so Livewire's morph never clobbers the Alpine-
    rendered label/list; its wire:key includes a hash of the options so the
    widget is rebuilt whenever the option list itself changes.
--}}
@props([
    'options' => [],
    'placeholder' => null,
    'placeholderSelectable' => true,
    'id' => null,
])

@php
    /** @var array<int, array{value: string, label: string}> $items */
    $items = collect($options)
        ->map(fn ($label, $value): array => ['value' => (string) $value, 'label' => (string) $label])
        ->values()
        ->when($placeholder !== null && $placeholderSelectable, fn ($items) => $items->prepend(['value' => '', 'label' => (string) $placeholder]))
        ->all();

    $key = trim(($attributes->get('wire:key') ?? 'searchable-select').'-'.md5(json_encode($items)), '-');
@endphp

<div
    x-data="{
        open: false,
        search: '',
        value: '',
        highlighted: 0,
        options: @js($items),
        placeholder: @js($placeholder),
        get filtered() {
            const term = this.search.trim().toLowerCase();

            return term === '' ? this.options : this.options.filter((option) => option.label.toLowerCase().includes(term));
        },
        get selectedLabel() {
            const current = String(this.value ?? '');
            const selected = this.options.find((option) => option.value === current);

            return selected ? selected.label : (this.placeholder ?? '');
        },
        isSelected(option) {
            return option.value === String(this.value ?? '');
        },
        toggle() {
            this.open ? this.close() : this.show();
        },
        show() {
            this.open = true;
            this.search = '';
            this.highlighted = Math.max(0, this.filtered.findIndex((option) => this.isSelected(option)));
            this.$nextTick(() => {
                this.$refs.search.focus();
                this.scrollToHighlighted();
            });
        },
        close(refocus = false) {
            this.open = false;
            if (refocus) {
                this.$refs.button.focus();
            }
        },
        choose(option) {
            this.value = option.value;
            this.close(true);
        },
        chooseHighlighted() {
            const option = this.filtered[this.highlighted];
            if (option) {
                this.choose(option);
            }
        },
        move(step) {
            const count = this.filtered.length;
            if (count === 0) {
                return;
            }
            this.highlighted = (this.highlighted + step + count) % count;
            this.scrollToHighlighted();
        },
        scrollToHighlighted() {
            this.$nextTick(() => this.$refs.list.children[this.highlighted + 1]?.scrollIntoView({ block: 'nearest' }));
        },
    }"
    x-modelable="value"
    x-init="$watch('search', () => highlighted = 0)"
    x-on:click.outside="close()"
    x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true); }"
    wire:ignore
    {{ $attributes->except('wire:key')->merge(['class' => 'relative']) }}
    wire:key="{{ $key }}"
>
    <button
        type="button"
        x-ref="button"
        @if ($id) id="{{ $id }}" @endif
        x-on:click="toggle()"
        x-on:keydown.down.prevent="show()"
        aria-haspopup="listbox"
        x-bind:aria-expanded="open"
        class="flex w-full min-w-[12rem] items-center justify-between gap-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none rounded-md shadow-sm text-sm text-left pl-3 pr-2 py-2"
    >
        <span class="truncate" x-text="selectedLabel">{{ $placeholder }}</span>
        <svg class="h-4 w-4 shrink-0 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 w-max min-w-full max-w-sm rounded-md bg-white dark:bg-gray-700 shadow-lg ring-1 ring-black/5 dark:ring-white/10"
    >
        <div class="p-2 border-b border-gray-100 dark:border-gray-600">
            <input
                type="text"
                x-ref="search"
                x-model="search"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseHighlighted()"
                x-on:keydown.tab="close()"
                placeholder="{{ __('Search…') }}"
                autocomplete="off"
                class="block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm py-1.5"
            >
        </div>

        <ul x-ref="list" role="listbox" class="max-h-64 overflow-y-auto py-1">
            <template x-for="(option, index) in filtered" :key="option.value">
                <li
                    role="option"
                    x-on:click="choose(option)"
                    x-on:mouseenter="highlighted = index"
                    x-bind:aria-selected="isSelected(option)"
                    x-bind:class="{
                        'bg-indigo-600 text-white': highlighted === index,
                        'text-gray-700 dark:text-gray-200': highlighted !== index,
                        'font-semibold': isSelected(option),
                    }"
                    class="cursor-pointer select-none truncate px-3 py-1.5 text-sm"
                    x-text="option.label"
                ></li>
            </template>
            <li x-show="filtered.length === 0" class="px-3 py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No matches') }}</li>
        </ul>
    </div>
</div>
