<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites a newly created user to choose their own password, using a token
 * from the long-lived "invites" password broker.
 */
class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $expiresInDays = (int) ceil(config('auth.passwords.invites.expire') / 1440);

        return (new MailMessage)
            ->subject(__('You\'ve been invited to :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An account has been created for you on :app. Choose a password to get started.', ['app' => config('app.name')]))
            ->action(__('Set your password'), route('invite.accept', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line(trans_choice('This invitation expires in :count day.|This invitation expires in :count days.', $expiresInDays));
    }
}
