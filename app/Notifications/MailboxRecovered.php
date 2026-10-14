<?php

namespace App\Notifications;

use App\Models\ImapAccount;
use App\Models\Microsoft365MailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Follows up on MailboxFailing once a poll of the mailbox succeeds again.
 */
class MailboxRecovered extends Notification implements ShouldQueue
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
            ->success()
            ->subject(__('Mailbox ":label" is working again', ['label' => $this->account->label]))
            ->line(__('The :type ":label" was polled successfully, so DMARC reports are being collected from it again.', [
                'type' => $this->account->mailboxTypeLabel(),
                'label' => $this->account->label,
            ]))
            ->action(__('Open mailbox settings'), $this->account->settingsUrl());
    }
}
