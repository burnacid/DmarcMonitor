<?php

namespace Tests\Feature;

use App\Jobs\ReapplyExpectedSourcesJob;
use App\Models\AggregateReport;
use App\Models\AggregateReportRecord;
use App\Models\Domain;
use App\Models\ExpectedSource;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ExpectedSourceMatch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ExpectedSourcesAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewers_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('admin.expected-sources'))
            ->assertForbidden();
    }

    public function test_an_editor_creates_an_expected_source_that_is_applied_to_existing_records(): void
    {
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        $report = AggregateReport::factory()->create(['domain_id' => $domain->id]);
        $record = AggregateReportRecord::factory()->create([
            'aggregate_report_id' => $report->id,
            'spf_domain' => 'ipw.inkyphishfence.com',
            'is_null_sender' => true,
        ]);

        Volt::actingAs(User::factory()->editor()->create())->test('admin.expected-sources')
            ->call('create')
            ->set('label', 'INKY')
            ->set('match_type', 'helo_domain')
            ->set('match_value', 'inkyphishfence.com')
            ->set('organisation_id', $organisation->id)
            ->set('null_sender_only', true)
            ->call('save')
            ->assertHasNoErrors();

        $source = ExpectedSource::sole();
        $this->assertSame(ExpectedSourceMatch::HeloDomain, $source->match_type);
        $this->assertTrue($source->exclude_from_alerts);
        $this->assertSame($source->id, $record->fresh()->expected_source_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expected_source.created']);
    }

    public function test_deleting_an_expected_source_unlinks_its_records(): void
    {
        $domain = Domain::factory()->create(['organisation_id' => Organisation::factory()]);
        $source = ExpectedSource::factory()->create(['organisation_id' => $domain->organisation_id]);
        $record = AggregateReportRecord::factory()->create([
            'aggregate_report_id' => AggregateReport::factory()->create(['domain_id' => $domain->id]),
            'expected_source_id' => $source->id,
        ]);

        Volt::actingAs(User::factory()->create())->test('admin.expected-sources')
            ->call('delete', $source->id);

        $this->assertModelMissing($source);
        $this->assertNull($record->fresh()->expected_source_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expected_source.deleted']);
    }

    public function test_only_matching_records_of_the_changed_scope_are_rematched(): void
    {
        Queue::fake();
        $organisation = Organisation::factory()->create();
        $domain = Domain::factory()->create(['organisation_id' => $organisation->id]);
        Domain::factory()->create(['organisation_id' => Organisation::factory()]);

        Volt::actingAs(User::factory()->create())->test('admin.expected-sources')
            ->call('create')
            ->set('label', 'INKY')
            ->set('match_value', 'inkyphishfence.com')
            ->set('organisation_id', $organisation->id)
            ->call('save');

        Queue::assertPushed(ReapplyExpectedSourcesJob::class, fn ($job) => $job->domainIds === [$domain->id]);
    }

    public function test_the_match_value_must_fit_the_match_type(): void
    {
        Volt::actingAs(User::factory()->create())->test('admin.expected-sources')
            ->call('create')
            ->set('label', 'Gateway')
            ->set('match_type', 'ip_or_cidr')
            ->set('match_value', 'inkyphishfence.com')
            ->call('save')
            ->assertHasErrors(['match_value']);

        $this->assertDatabaseCount('expected_sources', 0);
    }

    public function test_a_scoped_editor_cannot_see_or_create_sources_for_other_organisations(): void
    {
        $own = Organisation::factory()->create();
        $other = Organisation::factory()->create();
        $hidden = ExpectedSource::factory()->create(['organisation_id' => $other->id, 'label' => 'Hidden gateway']);
        $editor = User::factory()->editor()->scopedTo($own)->create();

        Volt::actingAs($editor)->test('admin.expected-sources')
            ->assertDontSee('Hidden gateway')
            ->call('create')
            ->set('label', 'INKY')
            ->set('match_value', 'inkyphishfence.com')
            ->set('organisation_id', $other->id)
            ->call('save')
            ->assertHasErrors(['organisation_id']);

        $this->assertDatabaseCount('expected_sources', 1);
        $this->expectException(ModelNotFoundException::class);
        Volt::actingAs($editor)->test('admin.expected-sources')->call('edit', $hidden->id);
    }

    public function test_the_form_is_prefilled_from_the_address(): void
    {
        $organisation = Organisation::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.expected-sources', ['match_type' => 'helo_domain', 'match_value' => 'ipw.inkyphishfence.com', 'organisation_id' => $organisation->id, 'null_sender_only' => 1]))
            ->assertOk()
            ->assertSee("open-modal', 'expected-source-form'", false)
            ->assertSee('&quot;match_value&quot;:&quot;ipw.inkyphishfence.com&quot;', false)
            ->assertSee('&quot;organisation_id&quot;:'.$organisation->id.',', false)
            ->assertSee('&quot;null_sender_only&quot;:true', false);
    }
}
