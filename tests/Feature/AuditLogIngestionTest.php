<?php

namespace Tests\Feature;

use App\Models\ImapAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_poll_is_audited(): void
    {
        $account = ImapAccount::create([
            'label' => 'Unreachable mailbox',
            'host' => '127.0.0.1',
            'port' => 1, // nothing listens here
            'encryption' => 'none',
            'username' => 'nobody',
            'password' => 'nope',
            'is_active' => true,
        ]);

        $this->artisan('imap:poll', ['account' => $account->id])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ingestion.failed',
            'subject_type' => $account->getMorphClass(),
            'subject_id' => $account->id,
            'user_id' => null,
        ]);
    }
}
