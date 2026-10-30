<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Dashboard') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex flex-col items-center justify-center gap-3 py-24 text-gray-400 dark:text-gray-500" role="status">
                <x-spinner class="h-8 w-8 text-indigo-600 dark:text-indigo-400" />
                <span class="text-sm">{{ __('Loading dashboard…') }}</span>
            </div>
        </div>
    </div>
</div>
