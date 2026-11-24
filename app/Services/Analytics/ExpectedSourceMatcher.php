<?php

namespace App\Services\Analytics;

use App\Models\AggregateReport;
use App\Models\Domain;
use App\Models\ExpectedSource;
use Illuminate\Support\Collection;

/**
 * Links aggregate report records to the expected source that covers them,
 * stored on the record so dashboards and alerts can filter on it in SQL.
 */
class ExpectedSourceMatcher
{
    /**
     * Matches every record of the report against the active sources that
     * apply to its domain. Returns whether any record changed.
     */
    public function applyToReport(AggregateReport $report): bool
    {
        $domain = Domain::withTrashed()->find($report->domain_id);

        if ($domain === null) {
            return false;
        }

        return $this->apply($report, $this->sourcesFor($domain));
    }

    /**
     * Rematches the records of every report of the given domains, or of all
     * domains when $domainIds is null. Used after a source is added,
     * changed or removed.
     *
     * @param  list<int>|null  $domainIds
     */
    public function reapply(?array $domainIds = null): void
    {
        $changed = false;

        Domain::withTrashed()
            ->when($domainIds !== null, fn ($query) => $query->whereIn('id', $domainIds))
            ->each(function (Domain $domain) use (&$changed) {
                $sources = $this->sourcesFor($domain);

                $domain->aggregateReports()->chunkById(100, function (Collection $reports) use ($sources, &$changed) {
                    foreach ($reports as $report) {
                        $changed = $this->apply($report, $sources) || $changed;
                    }
                });
            });

        if ($changed) {
            DmarcMetricsService::flushCache();
        }
    }

    /**
     * @return Collection<int, ExpectedSource>
     */
    private function sourcesFor(Domain $domain): Collection
    {
        return ExpectedSource::applicableTo($domain)
            ->get()
            ->sortBy([fn (ExpectedSource $a, ExpectedSource $b) => $b->specificity() <=> $a->specificity(), ['id', 'asc']])
            ->values();
    }

    /**
     * @param  Collection<int, ExpectedSource>  $sources  most specific first
     */
    private function apply(AggregateReport $report, Collection $sources): bool
    {
        $records = $report->records()->get();

        $changes = $records
            ->map(fn ($record) => [
                'id' => $record->id,
                'current' => $record->expected_source_id,
                'matched' => $sources->first(fn (ExpectedSource $source) => $source->matches($record))?->id,
            ])
            ->reject(fn (array $change) => $change['current'] === $change['matched']);

        foreach ($changes->groupBy(fn (array $change) => (string) $change['matched']) as $matchedId => $group) {
            $report->records()->whereIn('id', $group->pluck('id'))->update([
                'expected_source_id' => $matchedId === '' ? null : (int) $matchedId,
            ]);
        }

        return $changes->isNotEmpty();
    }
}
