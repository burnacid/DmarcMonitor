<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DmarcRecordExportController extends Controller
{
    /**
     * The DMARC records to publish for active domains whose reports don't
     * reach this app yet (no record, or a rua tag without this app's
     * address) — as a BIND zone snippet (default) or a CSV.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $records = $this->records($request);
        $date = now()->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($records) {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['Domain', 'Organisation', 'Host', 'Type', 'Value', 'Current Record']);

                foreach ($records as $record) {
                    fputcsv($handle, [
                        $record['domain'],
                        $record['organisation'],
                        $record['host'],
                        'TXT',
                        $record['value'],
                        $record['current'],
                    ]);
                }

                fclose($handle);
            }, "dmarc-records-{$date}.csv", ['Content-Type' => 'text/csv']);
        }

        return response()->streamDownload(function () use ($records) {
            echo $this->zone($records);
        }, "dmarc-records-{$date}.txt", ['Content-Type' => 'text/plain']);
    }

    /**
     * @return Collection<int, array{domain: string, organisation: ?string, host: string, value: string, current: string}>
     */
    private function records(Request $request): Collection
    {
        return Domain::visibleTo($request->user())
            ->with('organisation')
            ->where('is_active', true)
            ->orderBy('fqdn')
            ->get()
            ->filter(fn (Domain $domain) => $domain->reportsToThisTool() === false)
            ->map(fn (Domain $domain) => [
                'domain' => $domain->fqdn,
                'organisation' => $domain->organisation?->name,
                'host' => "_dmarc.{$domain->fqdn}",
                'value' => $domain->recommendedDmarcRecord(),
                'current' => (string) $domain->dmarc_record,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{domain: string, organisation: ?string, host: string, value: string, current: string}>  $records
     */
    private function zone(Collection $records): string
    {
        $lines = ['; DMARC records to publish so aggregate reports reach this app ('.now()->toDateTimeString().')'];

        if ($records->isEmpty()) {
            $lines[] = '; Nothing to publish.';
        }

        foreach ($records as $record) {
            $lines[] = '';
            $lines[] = '; Current: '.($record['current'] !== '' ? $record['current'] : 'none');
            $lines[] = "\$ORIGIN {$record['domain']}.";
            $lines[] = "_dmarc\tIN\tTXT\t\"{$record['value']}\"";
        }

        return implode("\n", $lines)."\n";
    }
}
