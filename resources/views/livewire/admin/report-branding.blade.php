<?php

use App\Models\ReportBranding;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $accent_color = ReportBranding::DEFAULT_ACCENT;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public ?string $status = null;

    public function mount(): void
    {
        $this->accent_color = ReportBranding::current()?->accent_color ?? ReportBranding::DEFAULT_ACCENT;
    }

    public function save(): void
    {
        $this->validate([
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ], attributes: [
            'accent_color' => __('accent colour'),
        ]);

        $branding = ReportBranding::current() ?? new ReportBranding;
        $wasNew = ! $branding->exists;
        $branding->accent_color = strtolower($this->accent_color);

        if ($this->logo !== null) {
            $previousPath = $branding->logo_path;
            $branding->logo_path = $this->logo->store('branding', 'local');

            if ($previousPath !== null) {
                Storage::disk('local')->delete($previousPath);
            }
        }

        $branding->save();

        AuditLogger::record(
            action: $wasNew ? 'report_branding.created' : 'report_branding.updated',
            description: 'Updated the report branding',
            subject: $branding,
            context: $wasNew ? null : AuditLogger::describeChanges($branding),
        );

        $this->logo = null;
        $this->status = __('Saved.');
    }

    public function removeLogo(): void
    {
        $branding = ReportBranding::current();

        if ($branding?->logo_path === null) {
            return;
        }

        Storage::disk('local')->delete($branding->logo_path);
        $branding->update(['logo_path' => null]);

        AuditLogger::record(
            action: 'report_branding.logo_removed',
            description: 'Removed the report logo',
            subject: $branding,
        );

        $this->status = __('Logo removed.');
    }

    public function useDefaultColor(): void
    {
        $this->accent_color = ReportBranding::DEFAULT_ACCENT;
    }

    public function with(): array
    {
        $validAccent = preg_match('/^#[0-9a-fA-F]{6}$/', $this->accent_color) === 1 ? $this->accent_color : ReportBranding::DEFAULT_ACCENT;

        return [
            'currentLogo' => ReportBranding::current()?->logoDataUri(forPdf: false),
            'canEmbedPng' => ReportBranding::canEmbedPng(),
            'previewAccent' => $validAccent,
            'previewAccentSoft' => ReportBranding::mix($validAccent, '#ffffff', 0.9),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Report Branding') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-sm text-gray-700 dark:text-gray-300">
                <p>{{ __('The logo and accent colour are used on every PDF report — both the management and the technical organisation report.') }}</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <form wire:submit="save" class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Branding') }}</h3>

                    <div class="mt-4">
                        <x-input-label for="accent_color" :value="__('Accent colour')" />
                        <div class="mt-1 flex items-center gap-3">
                            <input wire:model.live="accent_color" id="accent_color_picker" type="color" class="h-10 w-14 cursor-pointer rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 p-1" aria-label="{{ __('Pick accent colour') }}" />
                            <x-text-input wire:model.live.debounce.300ms="accent_color" id="accent_color" type="text" maxlength="7" class="block w-32 font-mono text-sm" />
                            <button type="button" wire:click="useDefaultColor" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 underline">{{ __('Use default') }}</button>
                        </div>
                        <x-input-error :messages="$errors->get('accent_color')" class="mt-2" />
                    </div>

                    <div class="mt-6">
                        <x-input-label for="logo" :value="__('Logo')" />
                        <input wire:model="logo" id="logo" type="file" accept="image/png,image/jpeg" class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 dark:file:bg-gray-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 dark:file:text-gray-200 hover:file:bg-gray-200 dark:hover:file:bg-gray-600" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('PNG or JPG, up to 2 MB. A wide logo on a transparent background works best.') }}</p>
                        @unless ($canEmbedPng)
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('The PHP GD extension is not installed on this server, so PNG logos are left out of the PDFs. Install GD, or upload a JPG.') }}</p>
                        @endunless
                        <div wire:loading wire:target="logo" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Uploading…') }}</div>
                        <x-input-error :messages="$errors->get('logo')" class="mt-2" />
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
                        @if ($status)
                            <span class="text-sm text-green-700 dark:text-green-400">{{ $status }}</span>
                        @endif
                        @if ($currentLogo)
                            <x-danger-button type="button" wire:click="removeLogo" wire:confirm="{{ __('Remove the logo from all reports?') }}">{{ __('Remove logo') }}</x-danger-button>
                        @endif
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="logo">{{ __('Save') }}</x-primary-button>
                    </div>
                </form>

                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Preview') }}</h3>

                    {{-- Mirrors the top of the management PDF; always light, like the printed page. --}}
                    <div class="mt-4 overflow-hidden rounded-md border border-gray-200 dark:border-gray-600 bg-white text-gray-900">
                        <div class="h-1.5" style="background: {{ $previewAccent }}"></div>
                        <div class="px-6 py-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.15em]" style="color: {{ $previewAccent }}">{{ __('Email security report') }}</div>
                                    <div class="mt-1 text-xl font-bold">{{ __('Example Organisation') }}</div>
                                    <div class="text-xs text-gray-500">{{ now()->subDays(29)->format('j M Y') }} – {{ now()->format('j M Y') }}</div>
                                </div>
                                @if ($logo && method_exists($logo, 'temporaryUrl') && $logo->isPreviewable())
                                    <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('New logo') }}" class="max-h-10 max-w-[160px] object-contain" />
                                @elseif ($currentLogo)
                                    <img src="{{ $currentLogo }}" alt="{{ __('Current logo') }}" class="max-h-10 max-w-[160px] object-contain" />
                                @else
                                    <span class="text-xs text-gray-400">{{ __('No logo') }}</span>
                                @endif
                            </div>
                            <div class="mt-4 flex items-center gap-2 text-xs text-gray-700">
                                <span class="inline-flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold" style="background: {{ $previewAccentSoft }}; color: {{ $previewAccent }}">1</span>
                                {{ __('Publish a DMARC record for example.com.') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
