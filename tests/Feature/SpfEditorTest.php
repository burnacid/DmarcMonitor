<?php

namespace Tests\Feature;

use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Dns\SpfAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SpfEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $txt = [
            'used.test' => ['v=spf1 ip4:192.0.2.0/24 -all'],
            'unused.test' => ['v=spf1 ip4:198.51.100.0/24 -all'],
            'spf.protection.outlook.com' => ['v=spf1 ip4:40.92.0.0/15 -all'],
        ];

        $this->app->instance(SpfAnalyzer::class, new SpfAnalyzer(fn (string $hostname, int $type): array => $type === DNS_TXT ? ($txt[$hostname] ?? []) : []));
    }

    public function test_opening_the_editor_starts_from_the_current_record(): void
    {
        $user = User::factory()->create();
        $withRecord = Domain::factory()->create(['spf_record' => 'v=spf1 include:used.test -all']);
        $withoutRecord = Domain::factory()->create(['spf_record' => null]);

        Volt::actingAs($user)->test('admin.domains')
            ->call('openSpfEditor', $withRecord->id)
            ->assertSet('spfDraft', 'v=spf1 include:used.test -all')
            ->assertSee('1/10 lookups')
            ->call('openSpfEditor', $withoutRecord->id)
            ->assertSet('spfDraft', 'v=spf1 ~all');
    }

    public function test_the_editor_is_not_available_for_another_organisations_domain(): void
    {
        $orgA = Organisation::factory()->create();
        $orgB = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $orgB->id]);
        $editor = User::factory()->editor()->create(['all_organisations' => false]);
        $editor->organisations()->attach($orgA->id);

        Volt::actingAs($editor)->test('admin.domains')
            ->call('openSpfEditor', $domain->id)
            ->assertStatus(404);
    }

    public function test_terms_can_be_added_and_removed(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['spf_record' => 'v=spf1 -all']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('openSpfEditor', $domain->id)
            ->set('spfAddMechanism', 'ip4')
            ->set('spfAddValue', '192.0.2.0/24')
            ->call('addSpfTerm')
            ->assertHasNoErrors()
            ->call('addSpfPreset', 'microsoft365')
            ->assertSet('spfDraft', 'v=spf1 ip4:192.0.2.0/24 include:spf.protection.outlook.com -all')
            ->call('setSpfAll', '~')
            ->call('removeSpfTerm', 0)
            ->assertSet('spfDraft', 'v=spf1 include:spf.protection.outlook.com ~all');
    }

    public function test_an_invalid_term_is_rejected(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['spf_record' => 'v=spf1 -all']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('openSpfEditor', $domain->id)
            ->set('spfAddMechanism', 'ip4')
            ->set('spfAddValue', '2001:db8::1')
            ->call('addSpfTerm')
            ->assertHasErrors('spfAddValue')
            ->assertSet('spfDraft', 'v=spf1 -all');
    }

    public function test_an_include_no_reported_source_uses_can_be_removed_with_a_suggestion(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['spf_record' => 'v=spf1 include:used.test include:unused.test -all']);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id, 'date_range_end' => now()->subDays(3)]);
        AggregateReportRecord::factory()->create(['aggregate_report_id' => $report->id, 'source_ip' => '192.0.2.44']);

        Volt::actingAs($user)->test('admin.domains')
            ->call('openSpfEditor', $domain->id)
            ->assertSee('Remove include:unused.test')
            ->assertDontSee('Remove include:used.test')
            ->call('applySpfSuggestion', 'unused:unused.test')
            ->assertSet('spfDraft', 'v=spf1 include:used.test -all');
    }
}
