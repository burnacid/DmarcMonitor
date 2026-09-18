<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_persists_expected_fields(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create();

        $entry = AuditLogger::record(
            action: 'domain.created',
            description: 'Created domain example.com',
            subject: $domain,
            organisationId: $domain->organisation_id,
            userId: $user->id,
            context: ['fqdn' => $domain->fqdn],
        );

        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $entry->id,
            'action' => 'domain.created',
            'description' => 'Created domain example.com',
            'user_id' => $user->id,
            'subject_type' => $domain->getMorphClass(),
            'subject_id' => $domain->id,
        ]);
        $this->assertEquals(['fqdn' => $domain->fqdn], $entry->fresh()->context);
    }

    public function test_record_defaults_the_actor_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = AuditLogger::record(action: 'domain.created', description: 'x');

        $this->assertEquals($user->id, $entry->user_id);
    }

    public function test_record_leaves_user_id_null_when_explicitly_passed_null(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = AuditLogger::record(action: 'ingestion.completed', description: 'x', userId: null);

        $this->assertNull($entry->user_id);
    }

    public function test_describe_changes_strips_secrets_and_timestamps(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $user->forceFill(['name' => 'New Name', 'password' => 'irrelevant-hash'])->save();

        $changes = AuditLogger::describeChanges($user);

        $this->assertArrayHasKey('name', $changes);
        $this->assertArrayNotHasKey('password', $changes);
        $this->assertArrayNotHasKey('updated_at', $changes);
    }
}
