<?php

use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Services\Dns\DomainAuthenticationChecker;
use App\Support\AuditLogger;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?int $editingId = null;
    public ?int $expandedId = null;

    public string $fqdn = '';
    public ?int $organisation_id = null;
    public bool $is_active = true;
    public string $notes = '';

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function create(): void
    {
        $this->reset(['editingId', 'fqdn', 'organisation_id', 'notes']);
        $this->is_active = true;

        $scopedIds = auth()->user()->scopedOrganisationIds();
        if ($scopedIds !== null && count($scopedIds) === 1) {
            $this->organisation_id = $scopedIds[0];
        }

        $this->dispatch('open-modal', 'domain-form');
    }

    public function edit(int $id): void
    {
        $domain = Domain::findOrFail($id);
        abort_unless(auth()->user()->canAccessOrganisation($domain->organisation_id), 404);
        $this->editingId = $domain->id;
        $this->fqdn = $domain->fqdn;
        $this->organisation_id = $domain->organisation_id;
        $this->is_active = $domain->is_active;
        $this->notes = (string) $domain->notes;
        $this->dispatch('open-modal', 'domain-form');
    }

    public function save(): void
    {
        if ($this->editingId !== null) {
            $existing = Domain::findOrFail($this->editingId);
            abort_unless(auth()->user()->canAccessOrganisation($existing->organisation_id), 404);
        }

        $user = auth()->user();

        $validated = $this->validate([
            'fqdn' => 'required|string|max:255|unique:domains,fqdn,'.$this->editingId,
            'organisation_id' => [
                $user->hasOrganisationScope() ? 'required' : 'nullable',
                'exists:organisations,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (! $user->canAccessOrganisation($value)) {
                        $fail(__('You do not have access to that organisation.'));
                    }
                },
            ],
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $wasNew = $this->editingId === null;
        $domain = Domain::updateOrCreate(['id' => $this->editingId], $validated);

        AuditLogger::record(
            action: $wasNew ? 'domain.created' : 'domain.updated',
            description: ($wasNew ? 'Created domain ' : 'Updated domain ').$domain->fqdn,
            subject: $domain,
            organisationId: $domain->organisation_id,
            context: $wasNew ? null : AuditLogger::describeChanges($domain),
        );

        $this->dispatch('close-modal', 'domain-form');
        $this->reset(['editingId', 'fqdn', 'organisation_id', 'notes']);
    }

    public function delete(int $id): void
    {
        $domain = Domain::findOrFail($id);
        abort_unless(auth()->user()->canAccessOrganisation($domain->organisation_id), 404);

        AuditLogger::record(
            action: 'domain.deleted',
            description: 'Deleted domain '.$domain->fqdn,
            organisationId: $domain->organisation_id,
            context: ['fqdn' => $domain->fqdn],
        );

        $domain->delete();
    }

    public function checkDns(int $id): void
    {
        $domain = Domain::findOrFail($id);
        abort_unless(auth()->user()->canAccessOrganisation($domain->organisation_id), 404);

        app(DomainAuthenticationChecker::class)->checkAndStore($domain);
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'domains' => Domain::visibleTo($user)->with('organisation')->orderBy('fqdn')->paginate(15),
            'organisations' => Organisation::visibleTo($user)->orderBy('name')->get(),
            'organisationScoped' => $user->hasOrganisationScope(),
        ];
    }

    public function authStatusBadgeClass(?string $status): string
    {
        return match ($status) {
            'valid' => 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
            'weak', 'unknown' => 'bg-amber-100 dark:bg-amber-900 text-amber-800 dark:text-amber-200',
            'missing' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',
            default => 'bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500',
        };
    }

    public function authStatusLabel(?string $status): string
    {
        return match ($status) {
            'valid' => __('Valid'),
            'weak' => __('Weak'),
            'missing' => __('Missing'),
            'unknown' => __('Unknown'),
            default => __('Not checked'),
        };
    }

    /**
     * @return array<int, array{selector: string, pass_count: int, fail_count: int, last_seen: ?Carbon}>
     */
    public function dkimSelectorsSeen(Domain $domain): array
    {
        $rows = AggregateReportRecord::query()
            ->join('aggregate_reports', 'aggregate_reports.id', '=', 'aggregate_report_records.aggregate_report_id')
            ->where('aggregate_reports.domain_id', $domain->id)
            ->where('aggregate_report_records.dkim_domain', $domain->fqdn)
            ->whereNotNull('aggregate_report_records.dkim_selector')
            ->selectRaw('aggregate_report_records.dkim_selector as selector')
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_auth_result = 'pass' THEN aggregate_report_records.count ELSE 0 END) as pass_count")
            ->selectRaw("SUM(CASE WHEN aggregate_report_records.dkim_auth_result != 'pass' THEN aggregate_report_records.count ELSE 0 END) as fail_count")
            ->selectRaw('MAX(aggregate_reports.date_range_end) as last_seen')
            ->groupBy('aggregate_report_records.dkim_selector')
            ->orderByDesc('last_seen')
            ->get();

        return $rows->map(fn ($row) => [
            'selector' => $row->selector,
            'pass_count' => (int) $row->pass_count,
            'fail_count' => (int) $row->fail_count,
            'last_seen' => $row->last_seen ? Carbon::parse($row->last_seen) : null,
        ])->all();
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">{{ __('Domains') }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8">
            <div class="flex justify-end items-center gap-4 mb-4">
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.domains.trash') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        {{ __('Trash') }}
                    </a>
                @endif
                <x-primary-button wire:click="create">{{ __('New Domain') }}</x-primary-button>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Organisation') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DMARC') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('SPF') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DKIM') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($domains as $domain)
                            <tr wire:key="domain-{{ $domain->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Domain') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">
                                    <button type="button" wire:click="toggleExpand({{ $domain->id }})" class="inline-flex items-center gap-2 hover:text-indigo-600 dark:hover:text-indigo-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500 transition-transform {{ $expandedId === $domain->id ? 'rotate-90' : '' }}">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                        </svg>
                                        <span>{{ $domain->fqdn }}</span>
                                    </button>
                                </td>
                                <td data-label="{{ __('Organisation') }}" class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    {{ $domain->organisation?->name ?? '—' }}
                                    @unless ($domain->organisation_id)
                                        <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 dark:bg-amber-900 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-200">{{ __('Unassigned') }}</span>
                                    @endunless
                                </td>
                                <td data-label="{{ __('Status') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    @if ($domain->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-800 dark:text-green-200">{{ __('Active') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Inactive') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('DMARC') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span class="max-md:text-right">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dmarc_status) }}">{{ $this->authStatusLabel($domain->dmarc_status) }}</span>
                                        @if ($domain->dmarc_status === 'weak')
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ __('p=none') }}</div>
                                        @endif
                                    </span>
                                </td>
                                <td data-label="{{ __('SPF') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->spf_status) }}">{{ $this->authStatusLabel($domain->spf_status) }}</span>
                                </td>
                                <td data-label="{{ __('DKIM') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span class="max-md:text-right">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dkim_status) }}">{{ $this->authStatusLabel($domain->dkim_status) }}</span>
                                        @if ($domain->dkim_status === 'valid')
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono">{{ $domain->dkim_selector }}</div>
                                        @elseif ($domain->dkim_status === 'unknown')
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ __('No selector seen yet') }}</div>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-3 max-md:px-0 max-md:py-0 max-md:pt-1 max-md:space-x-0 max-md:flex max-md:flex-wrap max-md:gap-3">
                                    <button
                                        wire:click="checkDns({{ $domain->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="checkDns({{ $domain->id }})"
                                        title="{{ $domain->dns_checked_at ? __('Last checked :time', ['time' => $domain->dns_checked_at->diffForHumans()]) : __('Never checked') }}"
                                        class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-900 dark:hover:text-emerald-300"
                                    >
                                        <span wire:loading.remove wire:target="checkDns({{ $domain->id }})">{{ __('Recheck') }}</span>
                                        <span wire:loading wire:target="checkDns({{ $domain->id }})">{{ __('Checking…') }}</span>
                                    </button>
                                    <button wire:click="edit({{ $domain->id }})" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $domain->id }})" wire:confirm="{{ __('Move this domain to the trash? Its reports will be hidden until it is restored.') }}" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                            @if ($expandedId === $domain->id)
                                <tr wire:key="domain-{{ $domain->id }}-details" class="max-md:block">
                                    <td colspan="7" class="bg-gray-50 dark:bg-gray-900/50 px-6 py-4 max-md:block max-md:px-3 max-md:py-3 max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:mt-2">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('DMARC') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dmarc_status) }}">{{ $this->authStatusLabel($domain->dmarc_status) }}</span>
                                                <p class="mt-2 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $domain->dmarc_record ?: __('No record found.') }}</p>
                                            </div>
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('SPF') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->spf_status) }}">{{ $this->authStatusLabel($domain->spf_status) }}</span>
                                                <p class="mt-2 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $domain->spf_record ?: __('No record found.') }}</p>
                                            </div>
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('DKIM') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dkim_status) }}">{{ $this->authStatusLabel($domain->dkim_status) }}</span>
                                                @if ($domain->dkim_selector)
                                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Configured selector: :selector', ['selector' => $domain->dkim_selector]) }}</p>
                                                @endif
                                                <p class="mt-1 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $domain->dkim_record ?: __('No record found.') }}</p>

                                                @php $dkimSelectorsSeen = $this->dkimSelectorsSeen($domain); @endphp
                                                <h5 class="mt-3 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Selectors seen in reports') }}</h5>
                                                @if (empty($dkimSelectorsSeen))
                                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Never seen in aggregate reports') }}</p>
                                                @else
                                                    <ul class="mt-1 space-y-1">
                                                        @foreach ($dkimSelectorsSeen as $seenSelector)
                                                            <li class="text-xs text-gray-600 dark:text-gray-300 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                                <span class="font-mono">{{ $seenSelector['selector'] }}</span>
                                                                @if ($domain->dkim_selector && $seenSelector['selector'] === $domain->dkim_selector)
                                                                    <span class="inline-flex items-center rounded-full bg-indigo-100 dark:bg-indigo-900 px-1.5 py-0.5 text-[10px] font-medium text-indigo-800 dark:text-indigo-200">{{ __('Configured') }}</span>
                                                                @endif
                                                                <span class="text-gray-400 dark:text-gray-500">{{ __(':pass pass / :fail fail', ['pass' => $seenSelector['pass_count'], 'fail' => $seenSelector['fail_count']]) }}</span>
                                                                <span class="text-gray-400 dark:text-gray-500">·</span>
                                                                <span class="text-gray-400 dark:text-gray-500">{{ $seenSelector['last_seen']?->diffForHumans() }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ __('No domains yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $domains->links() }}
            </div>
        </div>
    </div>

    <x-modal name="domain-form" focusable>
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('Edit Domain') : __('New Domain') }}
            </h2>

            <div class="mt-6">
                <x-input-label for="fqdn" :value="__('Domain (FQDN)')" />
                <x-text-input wire:model="fqdn" id="fqdn" type="text" placeholder="example.com" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('fqdn')" class="mt-2" />
            </div>

            <div class="mt-6">
                <x-input-label for="organisation_id" :value="__('Organisation')" />
                <select wire:model="organisation_id" id="organisation_id" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @if ($organisationScoped)
                        <option value="" disabled>{{ __('— Select organisation —') }}</option>
                    @else
                        <option value="">{{ __('— Unassigned —') }}</option>
                    @endif
                    @foreach ($organisations as $organisation)
                        <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('organisation_id')" class="mt-2" />
            </div>

            <div class="mt-6 flex items-center">
                <input wire:model="is_active" id="is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <label for="is_active" class="ml-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Active') }}</label>
            </div>

            <div class="mt-6">
                <x-input-label for="notes" :value="__('Notes')" />
                <textarea wire:model="notes" id="notes" rows="3" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
