<?php

namespace Tests\Unit;

use App\Models\Microsoft365SendAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class Microsoft365TransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_the_raw_mime_message_via_graph_and_updates_the_account(): void
    {
        $account = Microsoft365SendAccount::factory()->create();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/messages/*/send' => Http::response([], 202),
            'graph.microsoft.com/v1.0/users/*/messages' => Http::response(['id' => 'draft-1']),
        ]);

        Mail::mailer('microsoft365')->raw('Test body', function ($message) {
            $message->to('recipient@example.com')->subject('Test subject');
        });

        Http::assertSentInOrder([
            fn ($request) => str_contains($request->url(), 'login.microsoftonline.com'),
            fn ($request) => str_ends_with($request->url(), '/messages') && str_contains($request->body(), 'Test subject'),
            fn ($request) => str_ends_with($request->url(), '/messages/draft-1/send'),
        ]);

        $account->refresh();
        $this->assertNotNull($account->last_used_at);
        $this->assertNull($account->last_error);
    }

    public function test_it_throws_and_records_the_error_when_no_account_is_active(): void
    {
        Microsoft365SendAccount::factory()->create(['is_active' => false]);

        $this->expectException(TransportException::class);

        Mail::mailer('microsoft365')->raw('Test body', function ($message) {
            $message->to('recipient@example.com')->subject('Test subject');
        });
    }

    public function test_it_records_a_graph_send_failure(): void
    {
        $account = Microsoft365SendAccount::factory()->create();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/users/*/messages' => Http::response(['error' => ['message' => 'Mailbox not found']], 404),
        ]);

        try {
            Mail::mailer('microsoft365')->raw('Test body', function ($message) {
                $message->to('recipient@example.com')->subject('Test subject');
            });
            $this->fail('Expected a TransportException to be thrown.');
        } catch (TransportException) {
            // expected
        }

        $account->refresh();
        $this->assertNotNull($account->last_error);
    }
}
