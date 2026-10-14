<?php

namespace Tests\Feature;

use App\Models\ImapAccount;
use App\Models\Microsoft365MailAccount;
use App\Models\User;
use App\Notifications\MailboxFailing;
use App\Notifications\MailboxRecovered;
use App\Services\Graph\GraphIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class MailboxFailureNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_are_emailed_once_after_three_failed_polls_in_a_row(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $editor = User::factory()->editor()->create();
        $pendingAdmin = User::factory()->unverified()->create();
        $account = ImapAccount::factory()->create();

        $account->recordPollResult('Authentication failed');
        $account->recordPollResult('Authentication failed');
        Notification::assertNothingSent();

        $account->recordPollResult('Authentication failed');
        $account->recordPollResult('Authentication failed');

        Notification::assertSentToTimes($admin, MailboxFailing::class, 1);
        Notification::assertNotSentTo([$editor, $pendingAdmin], MailboxFailing::class);
        $this->assertTrue($account->fresh()->isFailing());
        $this->assertSame(4, $account->fresh()->consecutive_failures);
    }

    public function test_a_successful_poll_resets_the_count_and_sends_one_recovery_email(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $account = ImapAccount::factory()->create();

        foreach (range(1, 3) as $attempt) {
            $account->recordPollResult('Authentication failed');
        }

        $account->recordPollResult(null);
        $account->recordPollResult(null);

        Notification::assertSentToTimes($admin, MailboxRecovered::class, 1);
        $account->refresh();
        $this->assertFalse($account->isFailing());
        $this->assertSame(0, $account->consecutive_failures);
        $this->assertNull($account->failure_notified_at);
        $this->assertNull($account->last_error);
    }

    public function test_the_emails_name_the_mailbox_error_and_settings_page(): void
    {
        $admin = User::factory()->create();
        $account = ImapAccount::factory()->create(['label' => 'Main mailbox', 'consecutive_failures' => 3, 'last_error' => 'Authentication failed']);

        $failing = (string) (new MailboxFailing($account))->toMail($admin)->render();
        $recovered = (string) (new MailboxRecovered($account))->toMail($admin)->render();

        $this->assertStringContainsString('Main mailbox', $failing);
        $this->assertStringContainsString('Authentication failed', $failing);
        $this->assertStringContainsString(route('admin.imap-accounts'), $failing);
        $this->assertStringContainsString('working again', (new MailboxRecovered($account))->toMail($admin)->subject);
        $this->assertStringContainsString('Main mailbox', $recovered);
    }

    public function test_a_short_hiccup_sends_no_email_at_all(): void
    {
        Notification::fake();
        User::factory()->create();
        $account = ImapAccount::factory()->create();

        $account->recordPollResult('Timed out');
        $account->recordPollResult(null);

        Notification::assertNothingSent();
    }

    public function test_a_failing_microsoft365_poll_counts_towards_the_warning(): void
    {
        Notification::fake();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401)]);
        $account = Microsoft365MailAccount::factory()->create();

        (new GraphIngestionService)->pollAccount($account);
        (new GraphIngestionService)->pollAccount($account);

        $this->assertSame(2, $account->fresh()->consecutive_failures);
    }

    public function test_the_mailbox_page_shows_a_banner_for_a_failing_account(): void
    {
        $admin = User::factory()->create();
        ImapAccount::factory()->create(['label' => 'Main mailbox', 'consecutive_failures' => 3, 'last_error' => 'Authentication failed', 'failure_notified_at' => now()]);
        ImapAccount::factory()->create(['label' => 'Healthy mailbox']);

        Volt::actingAs($admin)->test('admin.imap-accounts')
            ->assertSee('This mailbox keeps failing')
            ->assertSee('Authentication failed')
            ->assertSee('Failing');
    }

    public function test_only_admins_see_the_failing_mailbox_badge_in_the_navigation(): void
    {
        Microsoft365MailAccount::factory()->create(['consecutive_failures' => 5]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertSee('Failing mailboxes');
        $this->actingAs(User::factory()->editor()->create())->get(route('dashboard'))->assertDontSee('Failing mailboxes');
    }

    public function test_an_inactive_failing_mailbox_is_not_reported(): void
    {
        ImapAccount::factory()->create(['consecutive_failures' => 5, 'is_active' => false]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee('Failing mailboxes');
    }
}
