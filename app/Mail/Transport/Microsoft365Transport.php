<?php

namespace App\Mail\Transport;

use App\Models\Microsoft365SendAccount;
use App\Services\Graph\GraphTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class Microsoft365Transport extends AbstractTransport
{
    public function __construct(private readonly GraphTokenService $tokenService)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'microsoft365';
    }

    /**
     * Send the message through Microsoft Graph as the active Microsoft 365
     * sending account: submit the raw MIME as a draft, then send that draft.
     * Graph has no single-call "send this exact MIME" endpoint, so this is
     * the documented two-step way to relay an already-built MIME message
     * (headers, HTML/text alternatives, attachments) unchanged.
     */
    protected function doSend(SentMessage $message): void
    {
        $account = Microsoft365SendAccount::where('is_active', true)->first();

        if ($account === null) {
            throw new TransportException('No active Microsoft 365 sending account is configured.');
        }

        try {
            $token = $this->tokenService->getAccessToken($account->tenant_id, $account->client_id, $account->client_secret);
            $mailbox = rawurlencode($account->mailbox);

            $draft = Http::withToken($token)
                ->withBody($message->toString(), 'text/plain')
                ->post("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages");

            if ($draft->failed()) {
                throw new TransportException('Failed to create draft via Microsoft Graph: '.$this->errorMessage($draft));
            }

            $messageId = $draft->json('id');

            $sendResponse = Http::withToken($token)
                ->post("https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}/send");

            if ($sendResponse->failed()) {
                throw new TransportException('Failed to send message via Microsoft Graph: '.$this->errorMessage($sendResponse));
            }

            $account->update(['last_used_at' => now(), 'last_error' => null]);
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);

            throw $e instanceof TransportException ? $e : new TransportException($e->getMessage(), previous: $e);
        }
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('error.message') ?? $response->body();
    }
}
