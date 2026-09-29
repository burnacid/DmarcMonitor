<?php

use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Services\Analytics\DomainHealthService;
use App\Services\Dns\DomainAuthenticationChecker;
use App\Support\AuditLogger;
use App\Support\DmarcRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /**
     * Empty for all organisations, "unassigned" for domains without one,
     * or an organisation id.
     */
    #[Url(as: 'organisation')]
    public string $organisationFilter = '';

    #[Url(as: 'attention')]
    public bool $needsAttention = false;

    /**
     * Set by the organisations page's "Add domains" link (?bulk=1); consumed
     * in mount() and reset, so the modal doesn't reopen on refresh.
     */
    #[Url]
    public bool $bulk = false;

    public bool $openBulkOnLoad = false;

    public ?int $editingId = null;
    public ?int $expandedId = null;

    public string $fqdn = '';
    public ?int $organisation_id = null;
    public bool $is_active = true;
    public string $notes = '';

    public string $bulkDomains = '';
    public ?int $bulkOrganisationId = null;
    public bool $bulkIsActive = true;
    public bool $bulkCheckDns = true;

    /**
     * @var array{added: list<string>, existing: list<string>, trashed: list<string>, invalid: list<string>}|null
     */
    public ?array $bulkResult = null;

    public ?int $generatorDomainId = null;
    public string $generatorFqdn = '';
    public ?string $generatorCurrentRecord = null;
    public string $genPolicy = 'none';
    public string $genSubdomainPolicy = '';
    public string $genPct = '100';
    public string $genRua = '';
    public string $genRuf = '';
    public string $genAdkim = 'r';
    public string $genAspf = 'r';

    /**
     * Tags from the current record the generator has no field for (fo, ri,
     * rf, ...), carried over unchanged.
     *
     * @var array<string, string>
     */
    public array $genExtraTags = [];

    public function mount(): void
    {
        if ($this->bulk) {
            $this->prepareBulk();
            $this->openBulkOnLoad = true;
            $this->bulk = false;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOrganisationFilter(): void
    {
        $this->resetPage();
    }

    public function updatedNeedsAttention(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'organisationFilter', 'needsAttention']);
        $this->resetPage();
    }

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

        $validated = $this->validate([
            'fqdn' => 'required|string|max:255|unique:domains,fqdn,'.$this->editingId,
            'organisation_id' => $this->organisationRules(),
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

    /**
     * @return array<int, mixed>
     */
    private function organisationRules(): array
    {
        $user = auth()->user();

        return [
            $user->hasOrganisationScope() ? 'required' : 'nullable',
            'exists:organisations,id',
            function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (! $user->canAccessOrganisation($value)) {
                    $fail(__('You do not have access to that organisation.'));
                }
            },
        ];
    }

    /**
     * Pre-selects the organisation the list is filtered on, or the user's
     * only organisation, as create() does.
     */
    private function prepareBulk(): void
    {
        $this->reset(['bulkDomains', 'bulkResult']);
        $this->bulkIsActive = true;
        $this->bulkCheckDns = true;
        $this->resetErrorBag();

        $user = auth()->user();
        $scopedIds = $user->scopedOrganisationIds();

        $this->bulkOrganisationId = match (true) {
            ctype_digit($this->organisationFilter) && $user->canAccessOrganisation((int) $this->organisationFilter) => (int) $this->organisationFilter,
            $scopedIds !== null && count($scopedIds) === 1 => $scopedIds[0],
            default => null,
        };
    }

    public function openBulk(): void
    {
        $this->prepareBulk();
        $this->dispatch('open-modal', 'domain-bulk-form');
    }

    /**
     * Adds every domain in the pasted list that doesn't exist yet. Accepts
     * one per line or comma/space separated, and tidies up pasted URLs,
     * wildcards and trailing dots.
     */
    public function saveBulk(): void
    {
        $this->validate([
            'bulkDomains' => 'required|string',
            'bulkOrganisationId' => $this->organisationRules(),
            'bulkIsActive' => 'boolean',
            'bulkCheckDns' => 'boolean',
        ], attributes: ['bulkDomains' => __('domains'), 'bulkOrganisationId' => __('organisation')]);

        $candidates = collect(preg_split('/[\s,;]+/', strtolower($this->bulkDomains)))
            ->map(fn (string $entry) => $this->normaliseFqdn($entry))
            ->filter()
            ->unique()
            ->values();

        if ($candidates->count() > 200) {
            $this->addError('bulkDomains', __('Add at most 200 domains at a time.'));

            return;
        }

        [$valid, $invalid] = $candidates->partition(
            fn (string $fqdn) => (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $fqdn)
        );

        $existing = Domain::withTrashed()
            ->whereIn(DB::raw('LOWER(fqdn)'), $valid->all())
            ->get(['fqdn', 'deleted_at'])
            ->keyBy(fn (Domain $domain) => strtolower($domain->fqdn));

        $result = ['added' => [], 'existing' => [], 'trashed' => [], 'invalid' => $invalid->values()->all()];
        $checker = app(DomainAuthenticationChecker::class);

        foreach ($valid as $fqdn) {
            if ($existing->has($fqdn)) {
                $result[$existing[$fqdn]->trashed() ? 'trashed' : 'existing'][] = $fqdn;

                continue;
            }

            $domain = Domain::create([
                'fqdn' => $fqdn,
                'organisation_id' => $this->bulkOrganisationId,
                'is_active' => $this->bulkIsActive,
            ]);

            AuditLogger::record(
                action: 'domain.created',
                description: 'Created domain '.$domain->fqdn,
                subject: $domain,
                organisationId: $domain->organisation_id,
                context: ['bulk' => true],
            );

            if ($this->bulkCheckDns) {
                $checker->checkAndStore($domain);
            }

            $result['added'][] = $fqdn;
        }

        $this->bulkResult = $result;

        if ($result['added'] !== []) {
            $this->bulkDomains = '';
        }
    }

    private function normaliseFqdn(string $entry): string
    {
        $entry = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', trim($entry));
        $entry = explode('/', $entry)[0];
        $entry = preg_replace('/^\*\./', '', $entry);

        return rtrim($entry, '.');
    }

    /**
     * Opens the DMARC record generator, pre-filled from the domain's current
     * record plus this app's report address, at the given (or current) policy.
     */
    public function openGenerator(int $id, ?string $policy = null): void
    {
        $domain = Domain::findOrFail($id);
        abort_unless(auth()->user()->canAccessOrganisation($domain->organisation_id), 404);

        $tags = $domain->dmarcTags();
        $ruaAddresses = DmarcRecord::addresses($tags['rua'] ?? null);
        $ownAddress = strtolower((string) config('dmarc.rua_address'));

        if ($ownAddress !== '' && ! in_array($ownAddress, $ruaAddresses, true)) {
            $ruaAddresses[] = $ownAddress;
        }

        $currentPolicy = $domain->dmarcPolicy();
        $movingPolicy = in_array($policy, ['none', 'quarantine', 'reject'], true) && $policy !== $currentPolicy;

        $this->generatorDomainId = $domain->id;
        $this->generatorFqdn = $domain->fqdn;
        $this->generatorCurrentRecord = $domain->dmarc_record;
        $this->genPolicy = $movingPolicy ? $policy : ($currentPolicy ?? 'none');
        $this->genSubdomainPolicy = in_array($tags['sp'] ?? '', ['none', 'quarantine', 'reject'], true) ? $tags['sp'] : '';
        // Asking for a specific policy (a readiness "next step") means applying it to all mail.
        $this->genPct = (string) ($policy !== null ? 100 : max(0, min(100, (int) ($tags['pct'] ?? 100))));
        $this->genRua = implode(', ', $ruaAddresses);
        $this->genRuf = implode(', ', DmarcRecord::addresses($tags['ruf'] ?? null));
        $this->genAdkim = ($tags['adkim'] ?? 'r') === 's' ? 's' : 'r';
        $this->genAspf = ($tags['aspf'] ?? 'r') === 's' ? 's' : 'r';
        $this->genExtraTags = collect($tags)->except(['p', 'sp', 'pct', 'rua', 'ruf', 'adkim', 'aspf'])->all();

        $this->dispatch('open-modal', 'dmarc-generator');
    }

    /**
     * @return list<string>
     */
    private function generatorAddresses(string $list): array
    {
        return collect(preg_split('/[\s,;]+/', strtolower($list)))
            ->map(fn (string $address) => preg_replace('/^mailto:/', '', trim($address)))
            ->filter(fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    public function generatedRecord(): string
    {
        return DmarcRecord::build([
            'p' => in_array($this->genPolicy, ['none', 'quarantine', 'reject'], true) ? $this->genPolicy : 'none',
            'sp' => in_array($this->genSubdomainPolicy, ['none', 'quarantine', 'reject'], true) ? $this->genSubdomainPolicy : '',
            'pct' => trim($this->genPct) === '' ? '100' : (string) max(0, min(100, (int) $this->genPct)),
            'rua' => DmarcRecord::uriList($this->generatorAddresses($this->genRua)),
            'ruf' => DmarcRecord::uriList($this->generatorAddresses($this->genRuf)),
            'adkim' => $this->genAdkim === 's' ? 's' : 'r',
            'aspf' => $this->genAspf === 's' ? 's' : 'r',
            ...$this->genExtraTags,
        ]);
    }

    /**
     * The records each report address's domain must publish before
     * providers will send this domain's reports there (RFC 7489 §7.1).
     *
     * @return list<string>
     */
    public function generatorAuthorizationHosts(): array
    {
        return collect([...$this->generatorAddresses($this->genRua), ...$this->generatorAddresses($this->genRuf)])
            ->map(fn (string $address) => DmarcRecord::externalAuthorizationHost($this->generatorFqdn, $address))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether each authorisation record was found at the domain's last DNS
     * check, keyed by host; hosts that weren't checked are absent.
     *
     * @return array<string, bool>
     */
    public function generatorCheckedAuthorizations(): array
    {
        $domain = $this->generatorDomainId ? Domain::visibleTo(auth()->user())->find($this->generatorDomainId) : null;

        return collect($domain?->reportAuthorizations() ?? [])
            ->mapWithKeys(fn (array $authorization) => [$authorization['host'] => $authorization['authorized']])
            ->all();
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

        $healthService = app(DomainHealthService::class);

        $query = Domain::visibleTo($user)
            ->with('organisation')
            ->when(trim($this->search) !== '', fn ($query) => $query->where('fqdn', 'like', '%'.trim($this->search).'%'))
            ->when($this->organisationFilter === 'unassigned', fn ($query) => $query->whereNull('organisation_id'))
            ->when(ctype_digit($this->organisationFilter), fn ($query) => $query->where('organisation_id', (int) $this->organisationFilter));

        $attentionHealth = null;

        if ($this->needsAttention) {
            $attentionHealth = $healthService->forDomains((clone $query)->get())->filter(fn (array $row) => $row['issues'] !== []);
            $query->whereIn('id', $attentionHealth->keys()->all());
        }

        $domains = $query->orderBy('fqdn')->paginate(15);

        return [
            'domains' => $domains,
            'health' => $attentionHealth?->only($domains->getCollection()->pluck('id')->all())
                ?? $healthService->forDomains($domains->getCollection()),
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
            <div class="flex flex-wrap items-end gap-4 mb-4">
                <div class="flex-1 min-w-[12rem] max-w-sm">
                    <x-input-label for="domain_search" :value="__('Search')" />
                    <x-text-input wire:model.live.debounce.400ms="search" id="domain_search" type="search" placeholder="example.com" class="mt-1 block w-full text-sm" />
                </div>

                <div>
                    <x-input-label for="organisation_filter" :value="__('Organisation')" />
                    <select wire:model.live="organisationFilter" id="organisation_filter" class="mt-1 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">{{ __('All organisations') }}</option>
                        @unless ($organisationScoped)
                            <option value="unassigned">{{ __('— Unassigned —') }}</option>
                        @endunless
                        @foreach ($organisations as $organisation)
                            <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
                    <input wire:model.live="needsAttention" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    {{ __('Needs attention') }}
                </label>

                @if ($search !== '' || $organisationFilter !== '' || $needsAttention)
                    <button type="button" wire:click="clearFilters" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 pb-2">{{ __('Clear filters') }}</button>
                @endif

                <div class="flex items-center gap-2 ml-auto">
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.domains.trash') }}" wire:navigate title="{{ __('Trash') }}" class="inline-flex items-center justify-center h-9 w-9 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                            <span class="sr-only">{{ __('Trash') }}</span>
                        </a>
                    @endif
                    <x-dropdown align="right" width="w-72">
                        <x-slot name="trigger">
                            <button type="button" title="{{ __('Export missing report records') }}" class="inline-flex items-center justify-center h-9 w-9 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                                <span class="sr-only">{{ __('Export missing report records') }}</span>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <div class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Missing report authorisation records') }}</div>
                            <x-dropdown-link href="{{ route('admin.domains.report-authorizations') }}">{{ __('Zone file (.txt)') }}</x-dropdown-link>
                            <x-dropdown-link href="{{ route('admin.domains.report-authorizations', ['format' => 'csv']) }}">{{ __('CSV') }}</x-dropdown-link>
                        </x-slot>
                    </x-dropdown>
                    <x-secondary-button wire:click="openBulk">{{ __('Bulk add') }}</x-secondary-button>
                    <x-primary-button wire:click="create">{{ __('New Domain') }}</x-primary-button>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 max-md:block">
                    <thead class="bg-gray-50 dark:bg-gray-700 max-md:hidden">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Domain') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Organisation') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('Policy') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DMARC') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('SPF') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">{{ __('DKIM') }}</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700 max-md:block max-md:divide-y-0 max-md:space-y-3 max-md:p-3">
                        @forelse ($domains as $domain)
                            @php $domainHealth = $health->get($domain->id); @endphp
                            <tr wire:key="domain-{{ $domain->id }}" class="max-md:block max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:p-3 max-md:space-y-2">
                                <td data-label="{{ __('Domain') }}" class="px-6 py-4 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100 max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400 max-md:before:font-normal">
                                    <button type="button" wire:click="toggleExpand({{ $domain->id }})" class="inline-flex items-center gap-2 hover:text-indigo-600 dark:hover:text-indigo-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500 transition-transform {{ $expandedId === $domain->id ? 'rotate-90' : '' }}">
                                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                                        </svg>
                                        <span>{{ $domain->fqdn }}</span>
                                    </button>
                                    @if ($domainHealth && $domainHealth['issues'] !== [])
                                        <div class="mt-1 flex flex-wrap gap-1 md:pl-6 max-md:justify-end">
                                            @foreach ($domainHealth['issues'] as $issue)
                                                <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/40 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:text-red-300">{{ \App\Services\Analytics\DomainHealthService::issueLabel($issue) }}</span>
                                            @endforeach
                                        </div>
                                    @endif
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
                                <td data-label="{{ __('Policy') }}" class="px-6 py-4 whitespace-nowrap max-md:flex max-md:justify-between max-md:items-center max-md:gap-3 max-md:px-0 max-md:py-0 max-md:before:content-[attr(data-label)] max-md:before:text-xs max-md:before:font-medium max-md:before:uppercase max-md:before:tracking-wider max-md:before:text-gray-500 dark:max-md:before:text-gray-400">
                                    <span class="max-md:text-right">
                                        <x-policy-badge :policy="$domainHealth['policy'] ?? null" />
                                        @if ($domainHealth)
                                            <div @class([
                                                'text-xs mt-0.5',
                                                'text-green-600 dark:text-green-400' => $domainHealth['readiness']['ready'] && $domainHealth['readiness']['step'] !== 'enforced',
                                                'text-gray-400 dark:text-gray-500' => ! $domainHealth['readiness']['ready'] || $domainHealth['readiness']['step'] === 'enforced',
                                            ])>{{ \App\Services\Analytics\DomainHealthService::readinessHint($domainHealth['readiness']) }}</div>
                                        @endif
                                    </span>
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
                                            @php $configuredSelectorNames = collect($domain->configuredDkimSelectors())->pluck('selector'); @endphp
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono" title="{{ $configuredSelectorNames->implode(', ') }}">
                                                {{ $configuredSelectorNames->first() }}@if ($configuredSelectorNames->count() > 1) <span class="font-sans">{{ __('+:count more', ['count' => $configuredSelectorNames->count() - 1]) }}</span>@endif
                                            </div>
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
                                    <td colspan="8" class="bg-gray-50 dark:bg-gray-900/50 px-6 py-4 max-md:block max-md:px-3 max-md:py-3 max-md:rounded-lg max-md:border max-md:border-gray-200 dark:max-md:border-gray-700 max-md:mt-2">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('DMARC') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dmarc_status) }}">{{ $this->authStatusLabel($domain->dmarc_status) }}</span>
                                                <p class="mt-2 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $domain->dmarc_record ?: __('No record found.') }}</p>

                                                @php $recordAuthorizations = collect($domain->reportAuthorizations())->where('in_record', true); @endphp
                                                @if ($recordAuthorizations->isNotEmpty())
                                                    <h5 class="mt-3 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Report authorisation') }}</h5>
                                                    <ul class="mt-1 space-y-1">
                                                        @foreach ($recordAuthorizations as $authorization)
                                                            <li class="flex items-start gap-2 text-xs {{ $authorization['authorized'] ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                                                <span aria-hidden="true">{{ $authorization['authorized'] ? '✓' : '✗' }}</span>
                                                                <span>
                                                                    <span class="font-mono break-all">{{ $authorization['host'] }}</span>
                                                                    <span class="text-gray-400 dark:text-gray-500">· {{ $authorization['authorized'] ? __('Found') : __('Missing') }}</span>
                                                                </span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </div>
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('SPF') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->spf_status) }}">{{ $this->authStatusLabel($domain->spf_status) }}</span>
                                                <p class="mt-2 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $domain->spf_record ?: __('No record found.') }}</p>
                                            </div>
                                            <div>
                                                <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('DKIM') }}</h4>
                                                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $this->authStatusBadgeClass($domain->dkim_status) }}">{{ $this->authStatusLabel($domain->dkim_status) }}</span>
                                                @php $configuredSelectors = $domain->configuredDkimSelectors(); @endphp
                                                @forelse ($configuredSelectors as $configuredSelector)
                                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Configured selector: :selector', ['selector' => $configuredSelector['selector']]) }}</p>
                                                    <p class="mt-1 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ $configuredSelector['record'] ?: __('No record found.') }}</p>
                                                @empty
                                                    <p class="mt-2 text-xs font-mono break-all text-gray-600 dark:text-gray-300">{{ __('No record found.') }}</p>
                                                @endforelse

                                                @php $dkimSelectorsSeen = $this->dkimSelectorsSeen($domain); @endphp
                                                <h5 class="mt-3 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Selectors seen in reports') }}</h5>
                                                @if (empty($dkimSelectorsSeen))
                                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Never seen in aggregate reports') }}</p>
                                                @else
                                                    <ul class="mt-1 space-y-1">
                                                        @foreach ($dkimSelectorsSeen as $seenSelector)
                                                            <li class="text-xs text-gray-600 dark:text-gray-300 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                                <span class="font-mono">{{ $seenSelector['selector'] }}</span>
                                                                @if (in_array($seenSelector['selector'], array_column($configuredSelectors, 'selector'), true))
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

                                        @if ($domainHealth)
                                            @php
                                                $readiness = $domainHealth['readiness'];
                                                $generatorCall = 'openGenerator('.$domain->id.', '.($readiness['next_policy'] ? "'".$readiness['next_policy']."'" : 'null').')';
                                            @endphp
                                            <div class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                                                <div>
                                                    <h4 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Path to enforcement') }}</h4>
                                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                                        @if ($readiness['step'] === 'publish')
                                                            {{ __('No DMARC policy yet. Publish p=none with a rua address to start collecting reports.') }}
                                                        @elseif ($readiness['step'] === 'enforced')
                                                            {{ __('Fully enforced (p=reject on all mail). Keep an eye on new sending sources.') }}
                                                        @elseif ($readiness['step'] === 'raise_pct')
                                                            {{ __('p=:policy applies to only part of the mail. Next step: pct=100.', ['policy' => $readiness['next_policy']]) }}
                                                        @else
                                                            {{ __('Next step: p=:policy.', ['policy' => $readiness['next_policy']]) }}
                                                        @endif
                                                    </p>
                                                    @if ($readiness['checks'] !== [])
                                                        <ul class="mt-2 space-y-1">
                                                            @foreach ($readiness['checks'] as $check)
                                                                <li class="flex items-center gap-2 text-xs {{ $check['passed'] ? 'text-green-700 dark:text-green-400' : 'text-gray-500 dark:text-gray-400' }}">
                                                                    <span aria-hidden="true">{{ $check['passed'] ? '✓' : '✗' }}</span>
                                                                    <span>{{ $check['label'] }}</span>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </div>
                                                <x-secondary-button type="button" wire:click="{{ $generatorCall }}" class="shrink-0">
                                                    {{ $readiness['next_policy'] ? __('Generate p=:policy record', ['policy' => $readiness['next_policy']]) : __('DMARC record') }}
                                                </x-secondary-button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-400 dark:text-gray-500">{{ $search !== '' || $organisationFilter !== '' || $needsAttention ? __('No domains match your filters.') : __('No domains yet.') }}</td>
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

    <x-modal name="domain-bulk-form" :show="$openBulkOnLoad" focusable>
        <form wire:submit="saveBulk" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Bulk add domains') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('One domain per line, or separated by commas or spaces. Pasted URLs and wildcards are tidied up; domains that already exist are skipped.') }}</p>

            <div class="mt-6">
                <x-input-label for="bulk_domains" :value="__('Domains')" />
                <textarea wire:model="bulkDomains" id="bulk_domains" rows="8" placeholder="example.com&#10;example.org" class="mt-1 block w-full font-mono text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                <x-input-error :messages="$errors->get('bulkDomains')" class="mt-2" />
            </div>

            <div class="mt-6">
                <x-input-label for="bulk_organisation_id" :value="__('Organisation')" />
                <select wire:model="bulkOrganisationId" id="bulk_organisation_id" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @if ($organisationScoped)
                        <option value="" disabled>{{ __('— Select organisation —') }}</option>
                    @else
                        <option value="">{{ __('— Unassigned —') }}</option>
                    @endif
                    @foreach ($organisations as $organisation)
                        <option value="{{ $organisation->id }}">{{ $organisation->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('bulkOrganisationId')" class="mt-2" />
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input wire:model="bulkIsActive" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    {{ __('Active') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input wire:model="bulkCheckDns" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    {{ __('Check DNS records now') }}
                </label>
            </div>

            @if ($bulkResult)
                <div class="mt-6 rounded-md bg-gray-50 dark:bg-gray-900/50 p-4 text-sm space-y-2">
                    @foreach ([
                        'added' => ['Added', 'text-green-700 dark:text-green-400'],
                        'existing' => ['Already exist', 'text-gray-600 dark:text-gray-300'],
                        'trashed' => ['In the trash (restore them there)', 'text-amber-700 dark:text-amber-400'],
                        'invalid' => ['Not a valid domain', 'text-red-700 dark:text-red-400'],
                    ] as $key => [$label, $class])
                        @if ($bulkResult[$key] !== [])
                            <div>
                                <span class="font-medium {{ $class }}">{{ __($label) }} ({{ count($bulkResult[$key]) }}):</span>
                                <span class="font-mono text-xs text-gray-600 dark:text-gray-300 break-all">{{ implode(', ', $bulkResult[$key]) }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="mt-6 flex justify-end space-x-3">
                <x-secondary-button type="button" x-on:click="show = false">{{ $bulkResult ? __('Close') : __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveBulk">
                    <span wire:loading.remove wire:target="saveBulk">{{ __('Add domains') }}</span>
                    <span wire:loading wire:target="saveBulk">{{ __('Adding…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="dmarc-generator" maxWidth="2xl" focusable>
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('DMARC record for :domain', ['domain' => $generatorFqdn]) }}</h2>

            <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="gen_policy" :value="__('Policy (p)')" />
                    <select wire:model.live="genPolicy" id="gen_policy" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="none">none</option>
                        <option value="quarantine">quarantine</option>
                        <option value="reject">reject</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="gen_pct" :value="__('Percentage (pct)')" />
                    <x-text-input wire:model.live.debounce.300ms="genPct" id="gen_pct" type="number" min="0" max="100" class="mt-1 block w-full text-sm" />
                </div>
                <div>
                    <x-input-label for="gen_sp" :value="__('Subdomain policy (sp)')" />
                    <select wire:model.live="genSubdomainPolicy" id="gen_sp" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">{{ __('Same as p') }}</option>
                        <option value="none">none</option>
                        <option value="quarantine">quarantine</option>
                        <option value="reject">reject</option>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <x-input-label for="gen_rua" :value="__('Aggregate report addresses (rua)')" />
                <x-text-input wire:model.live.debounce.400ms="genRua" id="gen_rua" type="text" class="mt-1 block w-full text-sm font-mono" />
                @unless (config('dmarc.rua_address'))
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ __('Set DMARC_RUA_ADDRESS to have this app\'s report mailbox filled in automatically.') }}</p>
                @endunless
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-1">
                    <x-input-label for="gen_ruf" :value="__('Forensic addresses (ruf)')" />
                    <x-text-input wire:model.live.debounce.400ms="genRuf" id="gen_ruf" type="text" :placeholder="__('Optional')" class="mt-1 block w-full text-sm font-mono" />
                </div>
                <div>
                    <x-input-label for="gen_adkim" :value="__('DKIM alignment')" />
                    <select wire:model.live="genAdkim" id="gen_adkim" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="r">{{ __('Relaxed') }}</option>
                        <option value="s">{{ __('Strict') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="gen_aspf" :value="__('SPF alignment')" />
                    <select wire:model.live="genAspf" id="gen_aspf" class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="r">{{ __('Relaxed') }}</option>
                        <option value="s">{{ __('Strict') }}</option>
                    </select>
                </div>
            </div>

            @if ($generatorDomainId)
                @php
                    $dnsRecords = [['host' => '_dmarc.'.$generatorFqdn, 'value' => $this->generatedRecord()]];
                    $checkedAuthorizations = $this->generatorCheckedAuthorizations();
                    foreach ($this->generatorAuthorizationHosts() as $authorizationHost) {
                        $dnsRecords[] = ['host' => $authorizationHost, 'value' => 'v=DMARC1', 'external' => true, 'authorized' => $checkedAuthorizations[$authorizationHost] ?? null];
                    }
                @endphp

                <div class="mt-6 space-y-3">
                    @foreach ($dnsRecords as $dnsRecord)
                        <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3" x-data="{ copied: false }">
                            <div class="flex items-center justify-between gap-3">
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('TXT record at') }} <span class="font-mono text-gray-700 dark:text-gray-200">{{ $dnsRecord['host'] }}</span>
                                </div>
                                <button type="button" x-on:click="navigator.clipboard.writeText(@js($dnsRecord['value'])); copied = true; setTimeout(() => copied = false, 1500)" class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                                    <span x-show="! copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            </div>
                            <p class="mt-1 font-mono text-sm break-all text-gray-900 dark:text-gray-100">{{ $dnsRecord['value'] }}</p>
                            @if ($dnsRecord['external'] ?? false)
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ __('Published on the report address\'s domain, not the client\'s: without it mailbox providers won\'t send reports to another domain.') }}</p>
                                @if ($dnsRecord['authorized'] === true)
                                    <p class="mt-1 text-xs text-green-700 dark:text-green-400">✓ {{ __('Found in DNS at last check') }}</p>
                                @elseif ($dnsRecord['authorized'] === false)
                                    <p class="mt-1 text-xs text-red-700 dark:text-red-400">✗ {{ __('Not found in DNS at last check') }}</p>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Current record:') }}
                    <span class="font-mono break-all text-gray-600 dark:text-gray-300">{{ $generatorCurrentRecord ?: __('none') }}</span>
                </div>
            @endif

            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="show = false">{{ __('Close') }}</x-secondary-button>
            </div>
        </div>
    </x-modal>
</div>
