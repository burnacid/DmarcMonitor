<?php

namespace App\Notifications;

use App\Models\ImapAccount;
use App\Models\Microsoft365MailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins that a report mailbox has failed several polls in a row, so
 * reports from it aren't coming in.
 */
class MailboxFailing extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ImapAccount|Microsoft365MailAccount $account) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(__('Mailbox ":label" is failing', ['label' => $this->account->label]))
            ->line(__('The :type ":label" has failed its last :count polls, so no DMARC reports are being collected from it.', [
                'type' => $this->account->mailboxTypeLabel(),
                'label' => $this->account->label,
                'count' => $this->account->consecutive_failures,
            ]))
            ->line(__('Last error: :error', ['error' => $this->account->last_error]))
            ->line(__('This often means the password expired or access was revoked. You\'ll get another email once it works again.'))
            ->action(__('Open mailbox settings'), $this->account->settingsUrl());
    }
}
