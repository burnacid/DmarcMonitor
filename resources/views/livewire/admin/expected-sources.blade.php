<?php

use App\Jobs\ReapplyExpectedSourcesJob;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Rules\IpOrCidr;
use App\Support\AuditLogger;
use App\Support\ExpectedSourceMatch;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;

    public ?int $organisation_id = null;
    public ?int $domain_id = null;
    public string $label = '';
    public string $match_type = 'helo_domain';
    public string $match_value = '';
    public string $note = '';
    public bool $null_sender_only = false;
    public bool $exclude_from_alerts = true;
    public bool $is_active = true;

    /** Whether the form was prefilled from the address, e.g. from a report's "Mark as expected" link. */
    public bool $openOnLoad = false;

    public function mount(): void
    {
        $matchType = ExpectedSourceMatch::tryFrom((string) request()->query('match_type'));

        if ($matchType === null) {
            return;
        }

        $this->create();
        $this->match_type = $matchType->value;
        $this->match_value = (string) request()->query('match_value', '');
        $this->label = (string) request()->query('label', '');
        $this->null_sender_only = (bool) request()->query('null_sender_only', false);

        $organisationId = (int) request()->query('organisation_id');
        if ($organisationId && Organisation::visibleTo(auth()->user())->whereKey($organisationId)->exists()) {
            $this->organisation_id = $organisationId;
        }

        $this->openOnLoad = true;
    }

    public function create(): void
    {
        $this->reset(['editingId', 'organisation_id', 'domain_id', 'label', 'match_value', 'note', 'null_sender_only']);
        $this->match_type = ExpectedSourceMatch::HeloDomain->value;
        $this->exclude_from_alerts = true;
        $this->is_active = true;
        $this->resetValidation();

        $scopedIds = auth()->user()->scopedOrganisationIds();
        if ($scopedIds !== null && count($scopedIds) === 1) {
            $this->organisation_id = $scopedIds[0];
        }

        $this->dispatch('open-modal', 'expected-source-form');
    }

    public function edit(int $id): void
    {
        $source = ExpectedSource::visibleTo(auth()->user())->findOrFail($id);

        $this->editingId = $source->id;
        $this->organisation_id = $source->organisation_id;
        $this->domain_id = $source->domain_id;
        $this->label = $source->label;
        $this->match_type = $source->match_type->value;
        $this->match_value = $source->match_value;
        $this->note = (string) $source->note;
        $this->null_sender_only = $source->null_sender_only;
        $this->exclude_from_alerts = $source->exclude_from_alerts;
        $this->is_active = $source->is_active;
        $this->resetValidation();

        $this->dispatch('open-modal', 'expected-source-form');
    }

    public function save(): void
    {
        $user = auth()->user();
        $existing = $this->editingId !== null ? ExpectedSource::visibleTo($user)->findOrFail($this->editingId) : null;

        $this->match_value = trim($this->match_value);

        $validated = $this->validate([
            'organisation_id' => [
                $user->hasOrganisationScope() ? 'required' : 'nullable',
                'exists:organisations,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if ($value !== null && ! Organisation::visibleTo($user)->whereKey($value)->exists()) {
                        $fail(__('You do not have access to that organisation.'));
                    }
                },
            ],
            'domain_id' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if ($value === null) {
                        return;
                    }

                    $domain = Domain::visibleTo($user)->find($value);

                    if ($domain === null) {
                        $fail(__('You do not have access to that domain.'));
                    } elseif ($this->organisation_id !== null && $domain->organisation_id !== $this->organisation_id) {
                        $fail(__('That domain does not belong to the chosen organisation.'));
                    }
                },
            ],
            'label' => 'required|string|max:100',
            'match_type' => ['required', Rule::enum(ExpectedSourceMatch::class)],
            'match_value' => ['required', 'string', 'max:255', ...$this->matchValueRules()],
            'note' => 'nullable|string|max:2000',
            'null_sender_only' => 'boolean',
            'exclude_from_alerts' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validated['domain_id'] !== null) {
            $validated['organisation_id'] = Domain::find($validated['domain_id'])->organisation_id;
        }

        $validated['note'] = filled($validated['note']) ? $validated['note'] : null;

        $previousScope = $existing ? $this->scopeDomainIds($existing->organisation_id, $existing->domain_id) : [];
        $source = ExpectedSource::updateOrCreate(['id' => $this->editingId], $validated);

        AuditLogger::record(
            action: $existing ? 'expected_source.updated' : 'expected_source.created',
            description: ($existing ? 'Updated expected source ' : 'Created expected source ').$source->label,
            subject: $source,
            organisationId: $source->organisation_id,
            context: $existing ? AuditLogger::describeChanges($source) : null,
        );

        $this->reapply($previousScope, $this->scopeDomainIds($source->organisation_id, $source->domain_id));

        $this->dispatch('close-modal', 'expected-source-form');
        $this->reset(['editingId', 'organisation_id', 'domain_id', 'label', 'match_value', 'note', 'null_sender_only']);
    }

    public function delete(int $id): void
    {
        $source = ExpectedSource::visibleTo(auth()->user())->findOrFail($id);

        AuditLogger::record(
            action: 'expected_source.deleted',
            description: 'Deleted expected source '.$source->label,
            organisationId: $source->organisation_id,
            context: ['label' => $source->label, 'match_type' => $source->match_type->value, 'match_value' => $source->match_value],
        );

        $scope = $this->scopeDomainIds($source->organisation_id, $source->domain_id);
        $source->delete();

        $this->reapply($scope);
    }

    /**
     * @return list<mixed>
     */
    private function matchValueRules(): array
    {
        return match (ExpectedSourceMatch::tryFrom($this->match_type)) {
            ExpectedSourceMatch::HeloDomain, ExpectedSourceMatch::PtrSuffix => ['regex:/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.?$/i'],
            ExpectedSourceMatch::IpOrCidr => [new IpOrCidr],
            ExpectedSourceMatch::Asn => ['regex:/^(AS)?\d{1,10}$/i'],
            default => [],
        };
    }

    /**
     * The domains a source applies to, or null for every domain.
     *
     * @return list<int>|null
     */
    private function scopeDomainIds(?int $organisationId, ?int $domainId): ?array
    {
        if ($domainId !== null) {
            return [$domainId];
        }

        if ($organisationId === null) {
            return null;
        }

        return Domain::withTrashed()->where('organisation_id', $organisationId)->pluck('id')->all();
    }

    /**
     * Rematches the records of every domain in any of the given scopes.
     *
     * @param  list<int>|null  ...$scopes
     */
    private function reapply(?array ...$scopes): void
    {
        $domainIds = in_array(null, $scopes, true) ? null : array_values(array_unique(array_merge(...$scopes)));

        if ($domainIds === []) {
            return;
        }

        ReapplyExpectedSourcesJob::dispatch($domainIds);
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'sources' => ExpectedSource::visibleTo($user)
                ->with(['organisation', 'domain'])
                ->withSum('records', 'count')
                ->orderBy('label')
                ->paginate(15),
            'organisations' => Organisation::visibleTo($user)->orderBy('name')->get(),
            'domains' => Domain::visibleTo($user)
                ->when($this->organisation_id !== null, fn ($query) => $query->where('organisation_id', $this->organisation_id))
                ->orderBy('fqdn')
                ->get(),
            'organisationScoped' => $user->hasOrganisationScope(),
            'matchTypes' => ExpectedSourceMatch::cases(),
        ];
    }
}; ?>

<div x-data x-init="@if ($openOnLoad) $nextTick(() => $dispatch('open-modal', 'expected-source-form')) @endif">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Expected Sources') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                <p class="max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('Sources you know about and expect to fail DMARC, such as a mail security gateway that sends notifications with your From domain. Their messages are labelled on the dashboard and on reports, and can be left out of alerts. They still count in the pass rates, because receivers still treat them as failing.') }}</p>
                <x-primary-button wire:click="create">{{ __('New Expected Source') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Matches') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Applies to') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Messages matched') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Alerts') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($sources as $source)
                            <tr wire:key="source-{{ $source->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Label') }}" class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span>
                                        <span class="font-medium">{{ $source->label }}</span>
                                        @if ($source->note)
                                            <span class="block text-xs text-gray-400 dark:text-gray-500">{{ \Illuminate\Support\Str::limit($source->note, 120) }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td data-label="{{ __('Matches') }}" class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span>
                                        {{ __($source->match_type->label()) }}: <span class="font-mono">{{ $source->match_value }}</span>
                                        @if ($source->null_sender_only)
                                            <span class="block text-xs text-gray-400 dark:text-gray-500">{{ __('Null sender messages only') }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td data-label="{{ __('Applies to') }}" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $source->domain?->fqdn ?? $source->organisation?->name ?? __('All organisations') }}</td>
                                <td data-label="{{ __('Messages matched') }}" class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400 tabular-nums max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:text-left max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ number_format((int) $source->records_sum_count) }}</td>
                                <td data-label="{{ __('Alerts') }}" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">{{ $source->exclude_from_alerts ? __('Left out') : __('Included') }}</td>
                                <td data-label="{{ __('Status') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($source->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button wire:click="edit({{ $source->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $source->id }})" wire:confirm="{{ __('Delete this expected source?') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No expected sources yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $sources->links() }}
            </div>
        </div>
    </div>

    <x-modal name="expected-source-form" max-width="2xl" overflow-visible focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Expected Source') : __('New Expected Source') }}
            </h2>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="label" :value="__('Label')" />
                    <x-text-input wire:model="label" id="label" type="text" placeholder="INKY" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('label')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="match_type" :value="__('Match on')" />
                    <select wire:model.live="match_type" id="match_type" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                        @foreach ($matchTypes as $matchType)
                            <option value="{{ $matchType->value }}">{{ __($matchType->label()) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('match_type')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="match_value" :value="__('Value')" />
                    <x-text-input wire:model="match_value" id="match_value" type="text" :placeholder="\App\Support\ExpectedSourceMatch::tryFrom($match_type)?->placeholder()" class="mt-1 block w-full font-mono" />
                    @if (in_array($match_type, ['helo_domain', 'ptr_suffix'], true))
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Also matches every subdomain, so inkyphishfence.com covers ipw.inkyphishfence.com.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('match_value')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="organisation_id" :value="__('Organisation')" />
                    <x-searchable-select
                        wire:model.live="organisation_id"
                        id="organisation_id"
                        :options="$organisations->pluck('name', 'id')"
                        :placeholder="$organisationScoped ? __('— Select organisation —') : __('— All organisations —')"
                        :placeholder-selectable="! $organisationScoped"
                        class="mt-1"
                    />
                    <x-input-error :messages="$errors->get('organisation_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="domain_id" :value="__('Domain')" />
                    <x-searchable-select
                        wire:model="domain_id"
                        wire:key="expected-source-domain-{{ $organisation_id ?? 'all' }}"
                        id="domain_id"
                        :options="$domains->pluck('fqdn', 'id')"
                        :placeholder="__('— Every domain —')"
                        class="mt-1"
                    />
                    <x-input-error :messages="$errors->get('domain_id')" class="mt-2" />
                </div>

                <div class="sm:col-span-2">
                    <x-input-label for="note" :value="__('Note')" />
                    <textarea wire:model="note" id="note" rows="2" placeholder="{{ __('Why this source is expected, and what would fix it.') }}" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                    <x-input-error :messages="$errors->get('note')" class="mt-2" />
                </div>

                <div class="sm:col-span-2 space-y-2">
                    <div class="flex items-center">
                        <input wire:model="null_sender_only" id="null_sender_only" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        <label for="null_sender_only" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Only null sender messages (bounces and notifications with an empty envelope sender)') }}</label>
                    </div>
                    <div class="flex items-center">
                        <input wire:model="exclude_from_alerts" id="exclude_from_alerts" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        <label for="exclude_from_alerts" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Leave out of alerts (pass rate drop, SPF/DKIM fail spike, new sending source)') }}</label>
                    </div>
                    <div class="flex items-center">
                        <input wire:model="is_active" id="is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                        <label for="is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Active') }}</label>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
