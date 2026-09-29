<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportAuthorizationExportController extends Controller
{
    /**
     * The "v=DMARC1" authorisation records that were missing at the last DNS
     * check, grouped by the report address's domain they must be published
     * in — as a BIND zone snippet (default) or a CSV.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $records = $this->missingRecords($request);
        $date = now()->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($records) {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, ['Receiving Domain', 'Host', 'Type', 'Value', 'Client Domain', 'In DMARC Record']);

                foreach ($records as $record) {
                    fputcsv($handle, [
                        $record['report_domain'],
                        $record['host'],
                        'TXT',
                        'v=DMARC1',
                        $record['client_domain'],
                        $record['in_record'] ? 'yes' : 'no',
                    ]);
                }

                fclose($handle);
            }, "dmarc-report-authorizations-{$date}.csv", ['Content-Type' => 'text/csv']);
        }

        return response()->streamDownload(function () use ($records) {
            echo $this->zone($records);
        }, "dmarc-report-authorizations-{$date}.txt", ['Content-Type' => 'text/plain']);
    }

    /**
     * @return Collection<int, array{report_domain: string, host: string, client_domain: string, in_record: bool}>
     */
    private function missingRecords(Request $request): Collection
    {
        return Domain::visibleTo($request->user())
            ->where('is_active', true)
            ->get()
            ->flatMap(fn (Domain $domain) => collect($domain->missingReportAuthorizations())
                ->map(fn (array $authorization) => [
                    'report_domain' => $authorization['report_domain'],
                    'host' => $authorization['host'],
                    'client_domain' => $domain->fqdn,
                    'in_record' => $authorization['in_record'],
                ]))
            ->unique('host')
            ->sortBy(fn (array $record) => $record['report_domain'].' '.$record['host'])
            ->values();
    }

    /**
     * @param  Collection<int, array{report_domain: string, host: string, client_domain: string, in_record: bool}>  $records
     */
    private function zone(Collection $records): string
    {
        $lines = ['; DMARC report authorisation records missing at the last DNS check ('.now()->toDateTimeString().')'];

        if ($records->isEmpty()) {
            $lines[] = '; Nothing to publish.';
        }

        foreach ($records->groupBy('report_domain') as $reportDomain => $group) {
            $lines[] = '';
            $lines[] = "; Records to publish in {$reportDomain}";
            $lines[] = "\$ORIGIN {$reportDomain}.";

            foreach ($group as $record) {
                $name = substr($record['host'], 0, -strlen(".{$reportDomain}"));
                $lines[] = "{$name}\tIN\tTXT\t\"v=DMARC1\"";
            }
        }

        return implode("\n", $lines)."\n";
    }
}
