<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Services\Dmarc\AggregateReportParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

/**
 * Realistic aggregate reports for local development: a few providers report
 * daily on every active domain, with a mix of legitimate senders, a mailing
 * list forwarder and spoofers. Each report is written as XML and imported
 * through the real parser, so its raw XML is stored like an ingested one.
 * Re-running replaces the reports for the same days instead of adding more.
 */
class SampleReportsSeeder extends Seeder
{
    public const int DAYS = 30;

    /** @var array<string, array{email: string, envelope_to: ?string}> */
    private const array REPORTERS = [
        'google.com' => ['email' => 'noreply-dmarc-support@google.com', 'envelope_to' => null],
        'Enterprise Outlook' => ['email' => 'dmarcreport@microsoft.com', 'envelope_to' => 'outlook.com'],
        'Yahoo' => ['email' => 'dmarchelp@yahooinc.com', 'envelope_to' => 'yahoo.com'],
    ];

    /**
     * How each sender authenticates: spf_domain null means the domain itself
     * (aligned), dkim_selector null means it doesn't sign as the domain.
     *
     * @var array<string, array{ips: list<string>, spf_domain: ?string, spf_pass: bool, dkim_selector: ?string, dkim_pass: bool, volume: array{int, int}}>
     */
    private const array SENDERS = [
        'microsoft365' => ['ips' => ['40.107.22.51', '40.107.8.102', '52.100.174.207'], 'spf_domain' => null, 'spf_pass' => true, 'dkim_selector' => 'selector1', 'dkim_pass' => true, 'volume' => [20, 400]],
        'google' => ['ips' => ['209.85.220.41', '209.85.128.170'], 'spf_domain' => null, 'spf_pass' => true, 'dkim_selector' => 'google', 'dkim_pass' => true, 'volume' => [5, 120]],
        'sendgrid' => ['ips' => ['167.89.115.54', '149.72.123.24'], 'spf_domain' => 'sendgrid.net', 'spf_pass' => true, 'dkim_selector' => 's1', 'dkim_pass' => true, 'volume' => [10, 250]],
        'mailchimp' => ['ips' => ['198.2.180.15'], 'spf_domain' => 'mcsv.net', 'spf_pass' => true, 'dkim_selector' => 'k1', 'dkim_pass' => true, 'volume' => [1, 80]],
        'forwarder' => ['ips' => ['66.163.188.147'], 'spf_domain' => null, 'spf_pass' => false, 'dkim_selector' => 'selector1', 'dkim_pass' => false, 'volume' => [1, 6]],
        'spoofer' => ['ips' => ['185.220.101.4', '45.95.147.10', '103.152.79.21'], 'spf_domain' => null, 'spf_pass' => false, 'dkim_selector' => null, 'dkim_pass' => false, 'volume' => [1, 30]],
    ];

    public function run(AggregateReportParser $parser): void
    {
        $domains = Domain::where('is_active', true)->get();

        if ($domains->isEmpty()) {
            $domains = collect([Domain::firstOrCreate(['fqdn' => 'example.com'], ['is_active' => true])]);
        }

        $imported = 0;

        foreach ($domains as $domain) {
            for ($daysAgo = self::DAYS; $daysAgo >= 1; $daysAgo--) {
                $begin = now('UTC')->subDays($daysAgo)->startOfDay();

                foreach (self::REPORTERS as $orgName => $reporter) {
                    // Not every provider sees mail for the domain every day.
                    if (random_int(1, 100) > 80) {
                        continue;
                    }

                    $reportId = sprintf('sample-%s-%s-%s', str($orgName)->slug(), str($domain->fqdn)->slug(), $begin->format('Ymd'));
                    $storedPath = "dmarc-attachments/sample/{$reportId}.xml";

                    Storage::disk('local')->put($storedPath, $this->reportXml($domain, $orgName, $reporter, $reportId, $begin));
                    $parser->parseFile(Storage::disk('local')->path($storedPath), null, $storedPath);
                    $imported++;
                }
            }
        }

        $this->command?->info("Imported {$imported} sample aggregate reports for {$domains->count()} domain(s).");
    }

    /**
     * @param  array{email: string, envelope_to: ?string}  $reporter
     */
    private function reportXml(Domain $domain, string $orgName, array $reporter, string $reportId, Carbon $begin): string
    {
        $policy = $domain->dmarcPolicy() ?? 'none';

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feedback');
        $xml->writeElement('version', '1.0');

        $xml->startElement('report_metadata');
        $xml->writeElement('org_name', $orgName);
        $xml->writeElement('email', $reporter['email']);
        $xml->writeElement('report_id', $reportId);
        $xml->startElement('date_range');
        $xml->writeElement('begin', (string) $begin->timestamp);
        $xml->writeElement('end', (string) $begin->copy()->endOfDay()->timestamp);
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('policy_published');
        $xml->writeElement('domain', $domain->fqdn);
        $xml->writeElement('adkim', 'r');
        $xml->writeElement('aspf', 'r');
        $xml->writeElement('p', $policy);
        $xml->writeElement('sp', $policy);
        $xml->writeElement('pct', '100');
        $xml->endElement();

        foreach ($this->sendersFor() as $senderName) {
            $sender = self::SENDERS[$senderName];

            foreach (Arr::random($sender['ips'], random_int(1, count($sender['ips']))) as $ip) {
                $this->writeRecord($xml, $domain->fqdn, $policy, $reporter, $sender, $ip);
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * The domain's main platforms report almost every day, the others now and then.
     *
     * @return list<string>
     */
    private function sendersFor(): array
    {
        $chances = ['microsoft365' => 95, 'google' => 40, 'sendgrid' => 70, 'mailchimp' => 35, 'forwarder' => 30, 'spoofer' => 45];

        return array_keys(array_filter($chances, fn (int $chance) => random_int(1, 100) <= $chance));
    }

    /**
     * @param  array{email: string, envelope_to: ?string}  $reporter
     * @param  array{ips: list<string>, spf_domain: ?string, spf_pass: bool, dkim_selector: ?string, dkim_pass: bool, volume: array{int, int}}  $sender
     */
    private function writeRecord(XMLWriter $xml, string $fqdn, string $policy, array $reporter, array $sender, string $ip): void
    {
        $spfDomain = $sender['spf_domain'] ?? $fqdn;
        $spfAligned = $sender['spf_pass'] && $sender['spf_domain'] === null;
        $dkimAligned = $sender['dkim_pass'] && $sender['dkim_selector'] !== null;
        $passes = $spfAligned || $dkimAligned;

        $xml->startElement('record');

        $xml->startElement('row');
        $xml->writeElement('source_ip', $ip);
        $xml->writeElement('count', (string) random_int(...$sender['volume']));
        $xml->startElement('policy_evaluated');
        $xml->writeElement('disposition', $passes ? 'none' : $policy);
        $xml->writeElement('dkim', $dkimAligned ? 'pass' : 'fail');
        $xml->writeElement('spf', $spfAligned ? 'pass' : 'fail');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('identifiers');
        if ($reporter['envelope_to'] !== null) {
            $xml->writeElement('envelope_to', $reporter['envelope_to']);
        }
        $xml->writeElement('envelope_from', $spfDomain);
        $xml->writeElement('header_from', $fqdn);
        $xml->endElement();

        $xml->startElement('auth_results');
        if ($sender['dkim_selector'] !== null) {
            $xml->startElement('dkim');
            $xml->writeElement('domain', $fqdn);
            $xml->writeElement('selector', $sender['dkim_selector']);
            $xml->writeElement('result', $sender['dkim_pass'] ? 'pass' : 'fail');
            $xml->endElement();
        }
        $xml->startElement('spf');
        $xml->writeElement('domain', $spfDomain);
        $xml->writeElement('scope', 'mfrom');
        $xml->writeElement('result', $sender['spf_pass'] ? 'pass' : 'fail');
        $xml->endElement();
        $xml->endElement();

        $xml->endElement();
    }
}
