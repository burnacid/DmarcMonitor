<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\ImapAccount;
use App\Models\Microsoft365MailAccount;
use App\Support\DmarcRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DmarcRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_reads_tags_and_normalises_policy_case(): void
    {
        $tags = DmarcRecord::parse('v=DMARC1; P=Quarantine; pct=50; rua=mailto:a@example.com ; fo=1');

        $this->assertSame(['p' => 'quarantine', 'pct' => '50', 'rua' => 'mailto:a@example.com', 'fo' => '1'], $tags);
        $this->assertSame([], DmarcRecord::parse(null));
    }

    public function test_build_orders_tags_and_leaves_out_defaults(): void
    {
        $record = DmarcRecord::build([
            'rua' => 'mailto:a@example.com',
            'pct' => '100',
            'adkim' => 'r',
            'p' => 'reject',
            'ri' => '3600',
            'sp' => '',
        ]);

        $this->assertSame('v=DMARC1; p=reject; rua=mailto:a@example.com; ri=3600', $record);
    }

    public function test_addresses_strips_mailto_and_size_limits(): void
    {
        $addresses = DmarcRecord::addresses('mailto:Reports@Example.com!10m, mailto:other@msp.test,https://ignored');

        $this->assertSame(['reports@example.com', 'other@msp.test'], $addresses);
        $this->assertSame('mailto:a@x.test,mailto:b@y.test', DmarcRecord::uriList(['a@x.test', ' ', 'b@y.test']));
    }

    public function test_external_authorization_is_only_needed_for_another_domain(): void
    {
        $this->assertSame('client.test._report._dmarc.msp.test', DmarcRecord::externalAuthorizationHost('client.test', 'dmarc@msp.test'));
        $this->assertNull(DmarcRecord::externalAuthorizationHost('client.test', 'dmarc@client.test'));
        $this->assertNull(DmarcRecord::externalAuthorizationHost('mail.client.test', 'dmarc@client.test'));
        $this->assertNull(DmarcRecord::externalAuthorizationHost('client.test', 'dmarc@reports.client.test'));
    }

    public function test_domain_reports_whether_rua_points_at_this_tool(): void
    {
        $domain = new Domain(['fqdn' => 'client.test', 'dmarc_record' => 'v=DMARC1; p=none; rua=mailto:DMARC@msp.test']);

        config(['dmarc.rua_address' => null]);
        $this->assertNull($domain->reportsToThisTool());

        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        $this->assertTrue($domain->reportsToThisTool());
        $this->assertSame('none', $domain->dmarcPolicy());

        $domain->dmarc_record = 'v=DMARC1; p=reject; rua=mailto:other@vendor.test';
        $this->assertFalse($domain->reportsToThisTool());
    }

    public function test_domain_reporting_to_an_active_ingestion_mailbox_counts_as_reporting_here(): void
    {
        config(['dmarc.rua_address' => 'dmarc@msp.test']);
        ImapAccount::factory()->create(['username' => 'Reports@MSP.test']);
        Microsoft365MailAccount::factory()->create(['mailbox' => 'dmarc@m365.test']);
        Microsoft365MailAccount::factory()->create(['mailbox' => 'old@m365.test', 'is_active' => false]);

        $this->assertTrue((new Domain(['dmarc_record' => 'v=DMARC1; p=none; rua=mailto:reports@msp.test']))->reportsToThisTool());
        $this->assertTrue((new Domain(['dmarc_record' => 'v=DMARC1; p=none; rua=mailto:dmarc@m365.test']))->reportsToThisTool());
        $this->assertFalse((new Domain(['dmarc_record' => 'v=DMARC1; p=none; rua=mailto:old@m365.test']))->reportsToThisTool());
    }
}
